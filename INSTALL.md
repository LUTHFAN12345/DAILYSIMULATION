# INSTALL — SIMULASI_12-OCT-26 (deployment minimal)

Target: `https://10.4.40.84:74/opr-simulation/` (folder aplikasi = isi ZIP ini). PHP 7.4 (Apache mod_php / PHP-FPM),
ekstensi `json`, `pdo_mysql` (Report store MySQL), `pdo_sqlite` (opsional, store lama), `zip` tidak diperlukan.

## Isi paket

| Path | Fungsi |
|---|---|
| `index.php` | halaman utama (Daily Plan, Report, Monitoring, Weekly UI) |
| `run.php` | seluruh endpoint (`mode=run`, `save`, `job_*`, `prelim_progress`, `tl_*`, `report_*`, `input_snapshot`, `run_weekly_day`, ...) |
| `worker02.php`, `worker_functions.php` | engine |
| `saved_data_store.php` | service layer datastore: MySQL Report store (`rs_*`) + store lama (`saved/`) |
| `weekly.php`, `weekly_ui.php` | Weekly (di-require `run.php` / di-include `index.php`) |
| `database/schema.sql`, `database/migrate_legacy_reports.php`, `database/README_MYSQL_SETUP.md` | instalasi MySQL & migrasi satu kali |
| `install/input_data.template.json` | input awal untuk instalasi BARU (tanpa record Report user) |
| `config/db.example.php` | template koneksi MySQL tanpa password -> salin menjadi `config/db.php` |
| `config/.htaccess` | menolak akses URL langsung ke folder `config/` |

Tidak berisi: data user, `jobs/`, `saved/`, `data/`, `.co_cache/`, `.svn/`, profiler, test, laporan, kredensial.

## A. Update server yang sudah berjalan

1. Backup folder aplikasi (terutama `input_data.json`, `output_data.json`, `saved/`, `saved_data_history/`, `data/`,
   `jobs/`, `.co_cache/`) dan database MySQL (`mysqldump --single-transaction ...`).
2. Ekstrak ZIP ke folder aplikasi, timpa file yang sama. Paket TIDAK memuat `input_data.json`/`output_data.json`/data,
   sehingga data server tidak tersentuh.
3. **Hapus `run_prof.php` dari server bila ada** (profiler sementara; selama berkas itu ada, setiap Run diprofil dan
   lebih lambat ±10 s). Paket ini sengaja tidak memuatnya.
4. Bila MySQL belum dipasang: ikuti `database/README_MYSQL_SETUP.md` (buat DB/user, import `schema.sql`,
   `cp config/db.example.php config/db.php` lalu isi kredensial — **tanpa mengubah setting Apache/PHP-FPM** —,
   jalankan `migrate_legacy_reports.php` dry-run lalu `--execute`). Paket ini tidak menimpa `config/db.php` yang sudah ada.
5. Verifikasi: `sha256sum -c SHA256SUMS.txt`, buka halaman utama, `run.php?mode=report_status` -> `"ok":true`.

## B. Instalasi baru

1. Ekstrak ZIP ke folder web; `cp install/input_data.template.json input_data.json`.
2. Folder aplikasi harus dapat ditulis user web server (dibuat otomatis: `jobs/`, `.co_cache/`, `saved/`, `*.lock`):
   Linux `chown -R www-data:www-data <app> && chmod 2775 <app>`.
3. MySQL: `database/README_MYSQL_SETUP.md` §1-3: import schema, `cp config/db.example.php config/db.php`, isi kredensial
   (tanpa migrasi bila tidak ada data lama).

## Rollback

Kembalikan file PHP dari backup langkah A.1 (atau `svn update -r <rev>` bila folder adalah working copy SVN).
`'report_store' => 'legacy'` di `config/db.php` (atau env `PP_REPORT_STORE=legacy`) mengembalikan Report ke store lama tanpa mengubah data MySQL.
