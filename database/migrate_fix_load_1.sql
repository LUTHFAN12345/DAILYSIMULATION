-- =====================================================================================================
-- Migration pp-fix-load-1 : User Fixed Load (Ctrl+Click Simulation Data / menu Fix Load) — idempotent.
--
-- Satu baris = satu Fixed Load user AKTIF untuk (versi input tersimpan, unit, row 30 menit).
-- Sumber kebenaran isi tetap snapshot input (input_snapshots.payload_json -> data3.modeling.unit_fix_load);
-- tabel ini adalah indeks relasional yang ditulis DI DALAM transaksi Save yang sama (rs_report_save),
-- sehingga Fix Load dan snapshot/hasil tidak pernah berbeda versi.
--
-- Isolasi multi-user: user_id + project_id (milik user) + input_snapshot_id; tidak ada Fix lintas user.
-- Unique: satu Fix aktif per input_snapshot (versi) + unit + row  (active_marker 0 = aktif, = id saat soft-delete).
--
-- Jalankan:  mysql -u <user> -p <db> < database/migrate_fix_load_1.sql   (aman diulang)
-- ROLLBACK (bila perlu dibatalkan; data Fix tetap ada di snapshot input):
--   DROP TABLE IF EXISTS user_fixed_loads;
--   DELETE FROM schema_migrations WHERE version = 'pp-fix-load-1';
-- Tidak ada kredensial di file ini.
-- =====================================================================================================
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
