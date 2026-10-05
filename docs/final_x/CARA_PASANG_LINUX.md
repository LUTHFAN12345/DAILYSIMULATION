# Cara pasang — Linux (Apache + PHP-FPM / mod_php), contoh `/var/www/html/php74/opr-simulation`

Diuji pada: Apache 2.4.58 (mpm_event) + `proxy_fcgi` + PHP-FPM 7.4.3 (pool berjalan sebagai `www-data`), DocumentRoot `/var/www/html`.
Ganti `www-data` dengan user/grup proses PHP di server Anda (RHEL/CentOS: `apache`; lihat `user =` / `group =` di pool PHP-FPM, atau `User`/`Group` Apache untuk mod_php). Seluruh path dibangun dari `__DIR__`, jadi folder aplikasi boleh di mana saja.

## 1. Salin kelima berkas (satu paket)

```bash
APP=/var/www/html/php74/opr-simulation
sudo install -d -o root -g www-data -m 2775 "$APP"
sudo install -o root -g www-data -m 0644 run.php worker02.php worker_functions.php index.php saved_data_store.php "$APP"/
```

Berkas PHP milik `root`, dapat dibaca grup `www-data` (0644) — web server tidak dapat mengubah kode. Jangan menyalin hanya empat berkas: tanpa `saved_data_store.php` aplikasi menolak berjalan dengan JSON `MISSING_DEPENDENCY` (bukan warning HTML).

## 2. Izin folder yang ditulisi aplikasi (tanpa `chmod 777`)

Aplikasi menulis: `input_data.json` / `output_data.json` dan berkas sementara (rename atomik) di folder aplikasi, `jobs/` (job & cache), `data/` (Save/Report: `data/saved/`, `data/backups/`).

```bash
sudo chown root:www-data "$APP"            # folder aplikasi: grup web server boleh menulis
sudo chmod 2775 "$APP"                      # setgid: berkas/folder baru ikut grup www-data
sudo install -d -o www-data -g www-data -m 2775 "$APP/jobs" "$APP/data" "$APP/data/saved" "$APP/data/backups"
# input_data.json yang sudah ada (bila dipindahkan dari server lama):
sudo chown www-data:www-data "$APP/input_data.json" && sudo chmod 0664 "$APP/input_data.json"
```

Bila folder data harus berada di luar folder aplikasi (disarankan agar deploy tidak menyentuhnya), set `PP_DATA_DIR`, mis. di pool PHP-FPM: `env[PP_DATA_DIR] = /var/lib/opr-simulation/data`, lalu buat folder itu dengan perintah `install -d` yang sama.

SELinux (RHEL/CentOS): `sudo semanage fcontext -a -t httpd_sys_rw_content_t "$APP(/.*)?" && sudo restorecon -R "$APP"` — lalu batasi kembali berkas PHP ke `httpd_sys_content_t` bila kebijakan menuntut.

## 3. PHP

- PHP 7.4 (diuji 7.4.3). Ekstensi: json, mbstring, posix (opsional, untuk nama user di preflight).
- `display_errors = Off` di produksi (endpoint JSON juga mematikannya sendiri), `log_errors = On`. Pesan instalasi dicatat ke error log PHP dengan awalan `[opr-simulation]`.
- `max_execution_time` boleh 30 (job menaikkan batasnya sendiri pada request job); `memory_limit` >= 512M (disarankan 1024M).
- PHP-FPM: `pm.max_children` >= 16 (satu Run Fastest memakai 1 request pemilik + 3 pembantu + poll browser). mod_php prefork: `MaxRequestWorkers` >= 16.
- `request_terminate_timeout = 0` (atau >= 600) dan Apache `ProxyTimeout 900` untuk `proxy_fcgi`.
- OPcache disarankan aktif.

## 4. Pemeriksaan

```bash
curl -s https://<host>/php74/opr-simulation/run.php?mode=preflight
```

Harus `"ok": true`. Kode lain: `MISSING_DEPENDENCY` (berkas hilang, `file` menyebut namanya), `APP_DIR_NOT_WRITABLE`, `JOBS_NOT_WRITABLE`, `DATASTORE_NOT_WRITABLE` (simulasi tetap jalan; Save/Report nonaktif), `PHP_VERSION`, `MISSING_EXTENSION`. `checks[].sha256` harus sama dengan `SHA256SUMS.txt`; `environment.process_user` menunjukkan user proses PHP yang harus punya hak tulis di atas.
