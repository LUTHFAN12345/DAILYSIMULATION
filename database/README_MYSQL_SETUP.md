# README_MYSQL_SETUP — Report store MySQL (schema `pp-report-mysql-1`)

Berlaku untuk MySQL 5.7/8.0 dan MariaDB 10.3+ (diuji: MariaDB 10.4.27, PHP 7.4.33). Ganti semua `<...>`.
Jangan menaruh password produksi di repository atau di folder web.

## 1. Database dan user (privilege minimum)

```sql
CREATE DATABASE <db_name> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '<app_user>'@'<app_host>' IDENTIFIED BY '<strong_password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.* TO '<app_user>'@'<app_host>';
-- user terpisah untuk import schema / migrasi DDL:
CREATE USER '<admin_user>'@'localhost' IDENTIFIED BY '<admin_password>';
GRANT ALL PRIVILEGES ON <db_name>.* TO '<admin_user>'@'localhost';
FLUSH PRIVILEGES;
```

User aplikasi tidak butuh CREATE/ALTER/DROP. `DELETE` hanya dipakai untuk mengganti 48 `monitoring_rows`
versi aktif di dalam transaksi Save.

## 2. Import schema

```bash
mysql -u <admin_user> -p <db_name> < database/schema.sql
mysql -u <admin_user> -p <db_name> -e "SELECT * FROM schema_migrations;"
```

Schema idempoten (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`).

## 3. Konfigurasi aplikasi (tanpa kredensial di source, tanpa mengubah setting Apache/PHP-FPM)

**Cara utama — satu file di folder aplikasi:**

```bash
cd /var/www/html/<app>            # folder aplikasi (yang berisi index.php)
cp config/db.example.php config/db.php
nano config/db.php                # isi name / user / password (dan host/port bila bukan 127.0.0.1:3306)
chown root:www-data config/db.php && chmod 0640 config/db.php      # Linux; www-data = user web server
```

Windows/XAMPP: `copy config\db.example.php config\db.php`, lalu edit dengan Notepad.

`saved_data_store.php` otomatis membaca `<folder aplikasi>/config/db.php`. Keamanan file ini:
- baris penjaga di template membuat `config/db.php` menjawab **HTTP 404 tanpa isi** bila dibuka lewat URL;
- `config/.htaccess` menolak seluruh folder `config/` bila server mengizinkan `.htaccess`;
- paket deployment hanya memuat `config/db.example.php`, jadi mengekstrak paket berikutnya **tidak menimpa** `config/db.php`;
- jangan commit `config/db.php` ke SVN: `svn propset svn:ignore db.php config`.

**Alternatif (opsional, bila server memang ingin diatur di level Apache/FPM):** env `DB_HOST`, `DB_PORT`, `DB_NAME`,
`DB_USER`, `DB_PASSWORD` (opsional `DB_SOCKET`, `PP_PROJECT_KEY`), atau `PP_DB_CONFIG=<path file config lain>`.
Urutan prioritas: env `DB_*` (bila `DB_NAME`+`DB_USER` ada) -> file `PP_DB_CONFIG` -> `config/db.php`.

Cek: `run.php?mode=report_status` -> `{"store":{"enabled":true,"ok":true,"schema":"pp-report-mysql-1"}}`.
Halaman Report -> Planning menampilkan `Store: MySQL (default, pp-report-mysql-1)`.
## 4. Migrasi data lama (WAJIB sebelum mengaktifkan MySQL di produksi)

```bash
# 1) backup folder lama (read-only sesudahnya)
tar czf /root/pp_legacy_$(date +%F).tgz -C /var/www/html/<app> saved input_data.json saved_data_history 2>/dev/null
# 2) dry-run (tidak menulis DB)
# kredensial dibaca dari <app>/config/db.php (user aplikasi cukup: SELECT/INSERT/UPDATE/DELETE)
php database/migrate_legacy_reports.php --app=/var/www/html/<app> --out=/root/pp_migration
# 3) baca /root/pp_migration/<run>/migration_report.md (migrated / skipped / conflict / orphan) dan manifest.json
# 4) execute
php database/migrate_legacy_reports.php --app=/var/www/html/<app> --out=/root/pp_migration --execute
# 5) ulangi execute -> harus 0 migrated, semua skipped_already_migrated (idempoten)
```

Sumber dibaca read-only (SQLite disalin ke temp). Snapshot identik dari banyak autosave menjadi satu versi;
isi berbeda bernama sama menjadi versi berurutan (`saved_at`). Bila entry sudah punya versi dari aplikasi baru,
versi legacy masuk sebagai `LEGACY_IMPORT_HISTORY_ONLY` tanpa memindah versi aktif.

## 5. Backup / restore

```bash
mysqldump --single-transaction --routines --triggers -u <admin_user> -p <db_name> | gzip > pp_report_$(date +%F_%H%M).sql.gz
gunzip -c pp_report_<stamp>.sql.gz | mysql -u <admin_user> -p <db_name>
```

`--single-transaction` memberi snapshot konsisten tanpa mengunci Save yang sedang berjalan (InnoDB).

## 6. Rollback aplikasi ke store lama (read-only fallback)

Tambahkan `'report_store' => 'legacy'` di `config/db.php` (atau ganti nama/hapus `config/db.php`; tanpa restart). Alternatif env: `PP_REPORT_STORE=legacy`. Aplikasi kembali membaca Report dari
store lama (`saved/`), yang tidak diubah oleh migrasi. Data yang disimpan ke MySQL setelah go-live tetap di MySQL
(tidak hilang); aktifkan kembali config untuk melihatnya. Selama MySQL kanonik, retensi store lama dinonaktifkan.

## 7. Query verifikasi

```sql
SELECT version, applied_at FROM schema_migrations;
SELECT report_type, COUNT(*) FROM report_entries WHERE active_marker = 0 GROUP BY report_type;
-- link membuka record yang direferensikan (harus 0 baris):
SELECT e.report_key FROM report_entries e JOIN simulation_results r ON r.id = e.result_id WHERE r.input_snapshot_id <> e.input_snapshot_id;
-- versi aktif ada di riwayat (harus 0 baris):
SELECT e.report_key FROM report_entries e LEFT JOIN report_versions v ON v.report_entry_id = e.id AND v.version_no = e.version_no WHERE v.id IS NULL;
-- Monitoring 48 row (harus 0 baris):
SELECT e.report_key, COUNT(m.id) n FROM report_entries e LEFT JOIN monitoring_rows m ON m.report_entry_id = e.id
 WHERE e.report_type = 'MONITORING' AND e.active_marker = 0 GROUP BY e.id HAVING n <> 48;
-- hash utuh (harus 0 baris):
SELECT snapshot_key FROM input_snapshots WHERE SHA2(payload_json, 256) <> payload_hash;
SELECT result_key FROM simulation_results WHERE SHA2(result_json, 256) <> result_sha256;
-- riwayat satu report:
SELECT v.version_no, v.saved_at, u.external_uid, v.change_reason FROM report_versions v JOIN report_entries e ON e.id = v.report_entry_id
 JOIN users u ON u.id = v.saved_by WHERE e.report_key = '<report_key>' ORDER BY v.version_no;
-- audit Save gagal/rollback:
SELECT created_at, request_id, status, message FROM save_events WHERE status <> 'COMMITTED' ORDER BY id DESC LIMIT 50;
```

Atau HTTP: `run.php?mode=report_integrity`.

## 8. Keamanan

- Kredensial hanya di `config/db.php` (404 bila dibuka via URL, folder ditolak `config/.htaccess`) atau env/pool/vhost; jangan commit `config/db.php` ke SVN/Git.
- User aplikasi DML-only; DDL dan migrasi memakai user admin terpisah.
- Semua query memakai prepared statement (`PDO::ATTR_EMULATE_PREPARES=false`), charset utf8mb4.
- `report_key`/`result_key`/`snapshot_key` acak 96-bit; nama/tanggal tidak pernah dipakai sebagai kunci link.
- Tidak ada autentikasi user di aplikasi ini: identitas user = pp_uid browser. Batasi akses jaringan ke aplikasi
  (VPN/IP allowlist) sesuai kebijakan; siapa pun yang dapat membuka aplikasi dapat membuka Report project-nya.
- Hapus `run_prof.php` dari server produksi (profiler hanya aktif bila file itu ada; overhead ~10 s per Run).
