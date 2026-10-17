-- =============================================================================================
--  database/schema.sql — Report / Monitoring / Snapshot store (MySQL 5.7+/8.0, MariaDB 10.3+)
--  Schema version: pp-report-mysql-1
--
--  Canonical datastore for: Planning Report, Actual Report, Monitoring Daily Plan, input
--  snapshots, simulation results, report versions, monitoring rows, save/audit events and the
--  user / tab / project / run context. Data lives on the MySQL server and is persistent the
--  moment a Save transaction COMMITs — no file in the repository changes, nothing needs a Git
--  commit.
--
--  Conventions
--    * InnoDB, utf8mb4 / utf8mb4_unicode_ci, ROW_FORMAT=DYNAMIC (long composite keys).
--    * Every timestamp is UTC, written by the application with UTC_TIMESTAMP(6); the UI
--      converts to local time for display only.
--    * Immutable rows: input_snapshots, simulation_results, report_versions, save_events and
--      audit_events are INSERT-only. report_entries only moves its "active version" pointer.
--    * Foreign keys are ON DELETE RESTRICT: no cleanup can remove a snapshot or result that a
--      report or a version still references.
--
--  Documented deviations from the minimum table list (INSTRUKSI §3)
--    1. Large JSON documents (payload_json, result_json, row_json, ...) are LONGTEXT with
--       CHECK (JSON_VALID(...)) instead of the native JSON type. MySQL's native JSON type
--       re-orders object keys and re-formats numbers on read, which would break the byte-exact
--       sha256 proof binding (payload_sha256 / result_sha256) and change key order seen by the
--       UI. LONGTEXT keeps the exact bytes and JSON_EXTRACT() still works on it. On MariaDB the
--       JSON type is already an alias of LONGTEXT + JSON_VALID, so behaviour is identical.
--    2. report_entries uniqueness uses active_marker (0 = active, = id once soft-deleted)
--       instead of is_deleted inside the UNIQUE key: with is_deleted a second soft delete of
--       the same name would violate the key. is_deleted is still kept for readability.
--    3. simulation_results is UNIQUE(input_snapshot_id, result_sha256) instead of UNIQUE(run_id):
--       one Run can legitimately be saved with operator edits (Actual Gas) and as a Monitoring
--       twin (different snapshot, same result), and every saved byte-set must stay addressable.
--    4. report_entries.result_id / input_snapshot_id are NULL-able only for report_type='ACTUAL'
--       (monthly actual data has no simulation result); the application and the integrity check
--       enforce NOT NULL for PLANNING and MONITORING.
-- =============================================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS schema_migrations (
  version        VARCHAR(64)  NOT NULL,
  description    VARCHAR(255) NOT NULL,
  applied_at     DATETIME(6)  NOT NULL,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------------------- 3.1 users
-- external_uid = browser identity pp_uid (localStorage + cookie) or a future SSO subject.
CREATE TABLE IF NOT EXISTS users (
  id             BIGINT       NOT NULL AUTO_INCREMENT,
  external_uid   VARCHAR(128) NOT NULL,
  display_name   VARCHAR(255) NULL,
  created_at     DATETIME(6)  NOT NULL,
  updated_at     DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_external_uid (external_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------------------------- 3.2 browser_tabs
CREATE TABLE IF NOT EXISTS browser_tabs (
  id             BIGINT       NOT NULL AUTO_INCREMENT,
  user_id        BIGINT       NOT NULL,
  tab_key        VARCHAR(128) NOT NULL,
  created_at     DATETIME(6)  NOT NULL,
  last_seen_at   DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tabs_user_tab (user_id, tab_key),
  CONSTRAINT fk_tabs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------------- 3.3 projects
-- A project is the shared workspace whose reports all users see (default project_key 'default',
-- override per deployment with env PP_PROJECT_KEY).
CREATE TABLE IF NOT EXISTS projects (
  id             BIGINT       NOT NULL AUTO_INCREMENT,
  project_key    VARCHAR(128) NOT NULL,
  owner_user_id  BIGINT       NULL,
  project_name   VARCHAR(255) NOT NULL,
  created_at     DATETIME(6)  NOT NULL,
  updated_at     DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_key (project_key),
  CONSTRAINT fk_projects_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------ 3.4 simulation_runs
-- run_key = '<user uid>:<tab key>:<client Run ID>' — globally unique, never the plan name.
CREATE TABLE IF NOT EXISTS simulation_runs (
  id                    BIGINT       NOT NULL AUTO_INCREMENT,
  run_key               VARCHAR(128) NOT NULL,
  client_run_id         VARCHAR(64)  NOT NULL,
  project_id            BIGINT       NOT NULL,
  user_id               BIGINT       NOT NULL,
  tab_id                BIGINT       NULL,
  mode                  ENUM('FASTEST','MAXIMUM_REVIEW') NOT NULL,
  status                ENUM('QUEUED','RUNNING','FINAL','TERMINAL','ERROR','CANCELLED') NOT NULL,
  state_revision        INT          NOT NULL DEFAULT 0,
  payload_hash          CHAR(64)     NOT NULL,
  job_id                VARCHAR(128) NULL,
  candidate_fingerprint VARCHAR(128) NULL,
  started_at            DATETIME(6)  NULL,
  finished_at           DATETIME(6)  NULL,
  created_at            DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_runs_key (run_key),
  KEY ix_runs_project_created (project_id, created_at),
  KEY ix_runs_payload_status (payload_hash, status),
  KEY ix_runs_user_created (user_id, created_at),
  CONSTRAINT fk_runs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
  CONSTRAINT fk_runs_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_runs_tab     FOREIGN KEY (tab_id)     REFERENCES browser_tabs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------ 3.5 input_snapshots
-- Immutable. payload_hash = sha256 of payload_json bytes (report archive never included).
CREATE TABLE IF NOT EXISTS input_snapshots (
  id              BIGINT       NOT NULL AUTO_INCREMENT,
  snapshot_key    VARCHAR(128) NOT NULL,
  run_id          BIGINT       NOT NULL,
  payload_hash    CHAR(64)     NOT NULL,
  schema_version  VARCHAR(64)  NOT NULL,
  payload_bytes   INT UNSIGNED NOT NULL,
  payload_json    LONGTEXT     NOT NULL,
  created_at      DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_snap_key (snapshot_key),
  UNIQUE KEY uq_snap_run_hash (run_id, payload_hash),
  CONSTRAINT ck_snap_json CHECK (JSON_VALID(payload_json)),
  CONSTRAINT fk_snap_run FOREIGN KEY (run_id) REFERENCES simulation_runs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------------------- 3.6 simulation_results
CREATE TABLE IF NOT EXISTS simulation_results (
  id                     BIGINT        NOT NULL AUTO_INCREMENT,
  result_key             VARCHAR(128)  NOT NULL,
  run_id                 BIGINT        NOT NULL,
  input_snapshot_id      BIGINT        NOT NULL,
  status                 ENUM('FINAL','TERMINAL','ERROR') NOT NULL,
  cp_usd_per_mwh         DECIMAL(18,6) NULL,
  heat_rate_btu_per_kwh  DECIMAL(18,6) NULL,
  gas_used_bbtud         DECIMAL(18,6) NULL,
  rows_count             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  candidate_fingerprint  VARCHAR(128)  NULL,
  proof_hash             VARCHAR(128)  NULL,
  result_sha256          CHAR(64)      NOT NULL,
  result_bytes           INT UNSIGNED  NOT NULL,
  result_json            LONGTEXT      NOT NULL,
  summary_json           TEXT          NULL,
  created_at             DATETIME(6)   NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_res_key (result_key),
  UNIQUE KEY uq_res_snap_hash (input_snapshot_id, result_sha256),
  KEY ix_res_run (run_id),
  CONSTRAINT ck_res_json CHECK (JSON_VALID(result_json)),
  CONSTRAINT fk_res_run  FOREIGN KEY (run_id)            REFERENCES simulation_runs (id) ON DELETE RESTRICT,
  CONSTRAINT fk_res_snap FOREIGN KEY (input_snapshot_id) REFERENCES input_snapshots (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------- 3.7 report_entries
-- report_key is the ONLY identifier used by links (index.php?report=<report_key>).
-- Name and date are display / filter columns. result_id + input_snapshot_id point at the
-- ACTIVE version; history lives in report_versions.
CREATE TABLE IF NOT EXISTS report_entries (
  id                           BIGINT       NOT NULL AUTO_INCREMENT,
  report_key                   VARCHAR(128) NOT NULL,
  project_id                   BIGINT       NOT NULL,
  report_type                  ENUM('PLANNING','ACTUAL','MONITORING') NOT NULL,
  plan_date                    DATE         NOT NULL,
  plan_name                    VARCHAR(255) NOT NULL,
  plan_name_normalized         VARCHAR(255) NOT NULL,
  remark                       TEXT         NULL,
  result_id                    BIGINT       NULL,
  input_snapshot_id            BIGINT       NULL,
  monitoring_source_report_id  BIGINT       NULL,
  created_by                   BIGINT       NOT NULL,
  updated_by                   BIGINT       NOT NULL,
  created_at                   DATETIME(6)  NOT NULL,
  updated_at                   DATETIME(6)  NOT NULL,
  version_no                   INT          NOT NULL DEFAULT 1,
  is_deleted                   TINYINT(1)   NOT NULL DEFAULT 0,
  active_marker                BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rep_key (report_key),
  UNIQUE KEY uq_rep_identity (project_id, report_type, plan_date, plan_name_normalized, active_marker),
  KEY ix_rep_type_date (report_type, plan_date),
  KEY ix_rep_project_date (project_id, plan_date),
  CONSTRAINT fk_rep_project FOREIGN KEY (project_id)                  REFERENCES projects (id)           ON DELETE RESTRICT,
  CONSTRAINT fk_rep_result  FOREIGN KEY (result_id)                   REFERENCES simulation_results (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rep_snap    FOREIGN KEY (input_snapshot_id)           REFERENCES input_snapshots (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_rep_monsrc  FOREIGN KEY (monitoring_source_report_id) REFERENCES report_entries (id)     ON DELETE RESTRICT,
  CONSTRAINT fk_rep_cby     FOREIGN KEY (created_by)                  REFERENCES users (id)              ON DELETE RESTRICT,
  CONSTRAINT fk_rep_uby     FOREIGN KEY (updated_by)                  REFERENCES users (id)              ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------ 3.8 report_versions
CREATE TABLE IF NOT EXISTS report_versions (
  id                 BIGINT       NOT NULL AUTO_INCREMENT,
  report_entry_id    BIGINT       NOT NULL,
  version_no         INT          NOT NULL,
  result_id          BIGINT       NULL,
  input_snapshot_id  BIGINT       NULL,
  saved_by           BIGINT       NOT NULL,
  saved_at           DATETIME(6)  NOT NULL,
  save_request_id    VARCHAR(128) NULL,
  change_reason      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ver_entry_no (report_entry_id, version_no),
  CONSTRAINT fk_ver_entry  FOREIGN KEY (report_entry_id)   REFERENCES report_entries (id)     ON DELETE RESTRICT,
  CONSTRAINT fk_ver_result FOREIGN KEY (result_id)         REFERENCES simulation_results (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ver_snap   FOREIGN KEY (input_snapshot_id) REFERENCES input_snapshots (id)    ON DELETE RESTRICT,
  CONSTRAINT fk_ver_user   FOREIGN KEY (saved_by)          REFERENCES users (id)              ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------------ 3.9 monitoring_rows
-- 48 rows of the ACTIVE version of a MONITORING entry. row_status MONITORING_SELECTED keeps the
-- orange Monitoring colour (row_color '#fb923c'); LOCKED = TIME PASSED 'Y' rows.
CREATE TABLE IF NOT EXISTS monitoring_rows (
  id               BIGINT           NOT NULL AUTO_INCREMENT,
  report_entry_id  BIGINT           NOT NULL,
  version_no       INT              NOT NULL,
  row_no           TINYINT UNSIGNED NOT NULL,
  time_slot        DATETIME         NOT NULL,
  row_status       ENUM('NORMAL','MONITORING_SELECTED','LOCKED','STOPPED') NOT NULL,
  row_color        VARCHAR(32)      NULL,
  row_json         LONGTEXT         NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mon_entry_row (report_entry_id, row_no),
  CONSTRAINT ck_mon_json CHECK (JSON_VALID(row_json)),
  CONSTRAINT ck_mon_row  CHECK (row_no BETWEEN 1 AND 48),
  CONSTRAINT fk_mon_entry FOREIGN KEY (report_entry_id) REFERENCES report_entries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------- 3.10 save_events, audit_events
-- save_events.event_key for a committed save is deterministic ('save:' + sha1(project|request_id)),
-- so a double-click / retry with the same request ID is answered from the first commit
-- (idempotency) instead of creating a second version.
CREATE TABLE IF NOT EXISTS save_events (
  id               BIGINT       NOT NULL AUTO_INCREMENT,
  event_key        VARCHAR(128) NOT NULL,
  user_id          BIGINT       NOT NULL,
  project_id       BIGINT       NULL,
  run_id           BIGINT       NULL,
  report_entry_id  BIGINT       NULL,
  tab_id           BIGINT       NULL,
  event_type       VARCHAR(64)  NOT NULL,
  request_id       VARCHAR(128) NOT NULL,
  payload_hash     CHAR(64)     NULL,
  status           ENUM('STARTED','COMMITTED','ROLLED_BACK','FAILED') NOT NULL,
  message          TEXT         NULL,
  response_json    TEXT         NULL,
  created_at       DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_save_event_key (event_key),
  KEY ix_save_request (request_id),
  KEY ix_save_entry_created (report_entry_id, created_at),
  CONSTRAINT fk_save_user    FOREIGN KEY (user_id)         REFERENCES users (id)           ON DELETE RESTRICT,
  CONSTRAINT fk_save_project FOREIGN KEY (project_id)      REFERENCES projects (id)        ON DELETE RESTRICT,
  CONSTRAINT fk_save_run     FOREIGN KEY (run_id)          REFERENCES simulation_runs (id) ON DELETE RESTRICT,
  CONSTRAINT fk_save_entry   FOREIGN KEY (report_entry_id) REFERENCES report_entries (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_save_tab     FOREIGN KEY (tab_id)          REFERENCES browser_tabs (id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS audit_events (
  id               BIGINT       NOT NULL AUTO_INCREMENT,
  event_key        VARCHAR(128) NOT NULL,
  user_id          BIGINT       NULL,
  project_id       BIGINT       NULL,
  run_id           BIGINT       NULL,
  report_entry_id  BIGINT       NULL,
  event_type       VARCHAR(64)  NOT NULL,
  request_id       VARCHAR(128) NOT NULL,
  payload_hash     CHAR(64)     NULL,
  status           ENUM('STARTED','COMMITTED','ROLLED_BACK','FAILED') NOT NULL,
  message          TEXT         NULL,
  created_at       DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_event_key (event_key),
  KEY ix_audit_request (request_id),
  KEY ix_audit_entry_created (report_entry_id, created_at),
  CONSTRAINT fk_audit_user    FOREIGN KEY (user_id)         REFERENCES users (id)           ON DELETE RESTRICT,
  CONSTRAINT fk_audit_project FOREIGN KEY (project_id)      REFERENCES projects (id)        ON DELETE RESTRICT,
  CONSTRAINT fk_audit_run     FOREIGN KEY (run_id)          REFERENCES simulation_runs (id) ON DELETE RESTRICT,
  CONSTRAINT fk_audit_entry   FOREIGN KEY (report_entry_id) REFERENCES report_entries (id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- ------------------------------------------------------------------ legacy migration ledger
-- One row per legacy record version imported by database/migrate_legacy_reports.php.
-- source_hash = sha256(type|date|name|snapshot bytes) makes re-runs idempotent.
CREATE TABLE IF NOT EXISTS legacy_migration_map (
  id               BIGINT       NOT NULL AUTO_INCREMENT,
  source_hash      CHAR(64)     NOT NULL,
  source_path      VARCHAR(512) NOT NULL,
  source_file_sha256 CHAR(64)   NOT NULL,
  legacy_snapshot_id VARCHAR(128) NULL,
  report_entry_id  BIGINT       NOT NULL,
  version_no       INT          NOT NULL,
  migration_run    VARCHAR(64)  NOT NULL,
  created_at       DATETIME(6)  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_source (source_hash),
  CONSTRAINT fk_legacy_entry FOREIGN KEY (report_entry_id) REFERENCES report_entries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT IGNORE INTO schema_migrations (version, description, applied_at)
VALUES ('pp-report-mysql-1', 'Report/Monitoring/Snapshot store: users, tabs, projects, runs, snapshots, results, entries, versions, monitoring rows, save/audit events, legacy ledger', UTC_TIMESTAMP(6));

-- ------------------------------------------------------------- 3.12 user_fixed_loads (pp-fix-load-1)
-- Lihat database/migrate_fix_load_1.sql (idempoten, termasuk panduan rollback).
CREATE TABLE IF NOT EXISTS user_fixed_loads (
  id                 BIGINT           NOT NULL AUTO_INCREMENT,
  fix_load_key       VARCHAR(64)      NOT NULL,
  input_snapshot_id  BIGINT           NOT NULL,
  project_id         BIGINT           NOT NULL,
  user_id            BIGINT           NOT NULL,
  tab_id             BIGINT           NULL,
  report_type        ENUM('PLANNING','MONITORING') NOT NULL,
  plan_date          DATE             NOT NULL,
  unit_id            VARCHAR(8)       NOT NULL,
  row_index          TINYINT UNSIGNED NOT NULL,
  period             CHAR(5)          NOT NULL,
  fixed_mw           DECIMAL(10,4)    NOT NULL,
  operation_id       VARCHAR(64)      NULL,
  source             ENUM('CTRL_CLICK','FIX_LOAD_MENU','IMPORT','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  created_at         DATETIME(6)      NOT NULL,
  updated_at         DATETIME(6)      NOT NULL,
  deleted_at         DATETIME(6)      NULL,
  active_marker      BIGINT           NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fix_key (fix_load_key),
  UNIQUE KEY uq_fix_active (input_snapshot_id, unit_id, row_index, active_marker),
  KEY ix_fix_project_user (project_id, user_id),
  CONSTRAINT ck_fix_row CHECK (row_index BETWEEN 1 AND 48),
  CONSTRAINT ck_fix_mw  CHECK (fixed_mw >= 0),
  CONSTRAINT fk_fix_snap FOREIGN KEY (input_snapshot_id) REFERENCES input_snapshots (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fix_proj FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fix_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT IGNORE INTO schema_migrations (version, description, applied_at)
VALUES ('pp-fix-load-1', 'User Fixed Load per input snapshot x unit x row (Ctrl+Click / Fix Load menu), written in the Save transaction', UTC_TIMESTAMP(6));

