# PRECHECK DEPLOYMENT — Linux (Apache/PHP-FPM) dan XAMPP

Source commit `aa63677`. Lima berkas PHP satu paket; hash di `SHA256SUMS.txt` = `run.php?mode=preflight` `checks[].sha256`.

## Akar masalah HTTP 500 / "mencari kandidat ... 129,4 s"

1. Paket terbaru menambah dependensi wajib `saved_data_store.php`; server produksi hanya menerima empat berkas lama.
2. `run.php` memanggil `require_once saved_data_store.php` tanpa pemeriksaan. Dengan `display_errors=On` respons berisi warning HTML `require_once(...): failed to open stream` + fatal (bukan JSON). Direproduksi di Linux Apache/PHP-FPM dengan source sebelumnya (`3b43955`): HTTP 200 berisi `<b>Warning</b>: require_once(/var/www/html/php74/opr-simulation/saved_data_store.php): failed to open stream`.
3. UI menjalankan timer Fastest SEBELUM backend mengakui job, dan timer hanya berhenti bila pesan status mengandung kata tertentu ("FINAL", "gagal", ...). Pesan "Server returned non-JSON" / "Error: ..." tidak termasuk, sehingga teks "mencari kandidat fully valid pertama · N s" terus bertambah walaupun tidak ada job (direproduksi: masih berjalan 4,8 s sesudah error, tanpa batas).
4. Uji sebelumnya selalu memakai lima berkas lengkap (XAMPP/proksi uji), dan paket tidak menegakkan kelengkapan atau izin folder data.

## Perbaikan

- `run.php`: `display_errors` dimatikan untuk endpoint JSON; kelima berkas diperiksa SEBELUM `require_once __DIR__ . DIRECTORY_SEPARATOR . ...`; berkas hilang -> HTTP 500 JSON `{"ok":false,"error":"MISSING_DEPENDENCY","file":...}` untuk SETIAP endpoint, detail ke error log server.
- `run.php?mode=preflight`: kelima berkas + SHA-256, versi PHP/SAPI/php.ini, fungsi wajib, folder aplikasi + `jobs/` + `data/{saved,backups}` dapat dibuat & ditulisi, user proses, OPcache, jumlah pembantu.
- Kontrak penyimpanan: simulasi TIDAK bergantung pada folder data (Run tidak membuat backup/cermin/record). Folder data tak dapat ditulisi -> Save dan API penyimpanan yang menulis menjawab `DATASTORE_NOT_WRITABLE` sebelum menulis apa pun; simulasi tetap berjalan. Lima berkas tetap satu paket (berkas hilang = instalasi ditolak, UI menonaktifkan Run).
- `index.php`: berkas paket hilang -> pesan instalasi + Run nonaktif; preflight dipanggil saat halaman dibuka.
- UI fail-fast: timer Fastest mulai hanya setelah backend mengembalikan `job_id`; HTTP 500, non-JSON, error JSON, kegagalan jaringan, atau timeout bootstrap 45 s -> timer & progres berhenti, Run aktif kembali, pesan singkat, tanpa polling, tanpa cancel ke job yang tidak pernah dibuat.

## Enam uji pendek

| # | uji | hasil | bukti |
|---|---|---|---|
| 1 | Linux preflight, lima PHP lengkap | PASS | HTTP 200, ok=True, SAPI fpm-fcgi, user www-data, OPcache True, pembantu 3 |
| 2 | Linux tanpa `saved_data_store.php` | PASS | run/job_poll/fast_ready/preflight/store_list: HTTP 500 JSON `MISSING_DEPENDENCY` (display_errors Off dan On); UI: Run nonaktif + pesan instalasi; klik paksa -> UI berhenti 0.23 s, request sesudah berhenti (5 s) 0, cancel False, warning HTML False. Source lama: warning HTML, timer tetap berjalan (`Fastest - Default — mencari kandidat fully valid pertama · 4,8 s`) |
| 3 | Linux folder data read-only (root:root 0555) | PASS | preflight HTTP 500 `DATASTORE_NOT_WRITABLE`; Save HTTP 500 `DATASTORE_NOT_WRITABLE` (input_data.json tidak berubah); store_delete `DATASTORE_NOT_WRITABLE`; store_list tetap 200; UI: peringatan, Run aktif; Fastest selesai normal (lihat T3_run.jsonl) |
| 4/5 | XAMPP dan Linux PGN 31 + PEP 30 Fastest | PASS | dispatch identik (f3acd04c7971acfb), CP 64.7205, HR 8375.02; XAMPP median 11.51 s (11.45-11.70), Linux median 8.61 s (8.29-9.30), 3 run cold masing-masing |
| 6 | XAMPP dan Linux PGN 30 + PEP 30 Fastest | PASS | dispatch identik (17b40201749fe55a), CP 64.8461, HR 8420.61; XAMPP median 7.55 s (7.43-7.72), Linux median 6.85 s (6.20-7.00), 3 run cold masing-masing |

Batas jujur: XAMPP/Windows tidak tersedia di lingkungan uji ini. "XAMPP" = server multi-worker dengan semantik Apache Windows yang relevan — seluruh request berbagi satu pid (mod_php mpm_winnt; kait uji `PP_EMU_PID`), OPcache mati (bawaan XAMPP), source hash sama. Linux = Apache 2.4.58 + PHP-FPM 7.4.3 nyata (paket Ubuntu resmi), user `www-data`, app di `/var/www/html/php74/opr-simulation`.

## Perbandingan platform

| aspek | XAMPP (semantik) | Linux Apache/PHP-FPM |
|---|---|---|
| SHA-256 lima PHP | sama (lihat SHA256SUMS.txt) | sama (preflight `checks[].sha256`) |
| PHP binary / php.ini | /opt/php74/usr/bin/php7.4 (`php -S`), /opt/php74/etc/php.ini | /opt/php74/usr/sbin/php-fpm7.4, /etc/php74-fpm/php.ini |
| SAPI | cli-server (6 worker, satu pid untuk semua request) | fpm-fcgi (pm static 24 child) |
| DocumentRoot / __DIR__ | akar uji sementara / sama | /var/www/html / /var/www/html/php74/opr-simulation |
| user proses / izin data | root (lingkungan uji) | www-data; folder app root:www-data 2775, PHP 0644, jobs/ data/ www-data 2775 |
| pembantu / inti | 3 / 4 | 3 / 4 |
| jobs / data | <app>/jobs, <app>/data | /var/www/html/php74/opr-simulation/jobs, /var/www/html/php74/opr-simulation/data |
| OPcache | off | True |
| payload / response | input_data.json pengguna (PGN 31 / PGN 30, PEP 30) — sama | sama; response JSON identik (dispatch 48 row, CP, HR, merit, C1-C4, STG, constraints) |

## Timeline nyata (run pertama tiap platform, detik sejak klik Run; browser Chromium)

| platform | kasus | run_click | job_created | first_candidate_complete | first_valid_claimed | first_fully_valid | snapshot_persisted | browser_received_snapshot | render_start | render_done | simulation_data_opened | exact_cancel_sent | helpers_stopped | CP | merit | C4 | STG | baris tabel |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| XAMPP (semantik) | U31 | 0.00 | 0.22 | 1.22 | 5.89 | 11.12 | 11.13 | 11.24 | 11.24 | 11.35 | 11.51 | 11.52 | 11.86 | 64.7205 | PASS | 0 | 144/144 langgar 0 | 48 |
| XAMPP (semantik) | U30 | 0.00 | 0.17 | 0.56 | 2.62 | 7.38 | 7.39 | 7.51 | 7.51 | 7.62 | 7.72 | 7.72 | 8.05 | 64.8461 | PASS | 0 | 144/144 langgar 0 | 48 |
| Linux Apache/PHP-FPM | U31 | 0.00 | 0.15 | 0.90 | 5.14 | 9.06 | 9.06 | 9.12 | 9.12 | 9.22 | 9.30 | 9.31 | 9.58 | 64.7205 | PASS | 0 | 144/144 langgar 0 | 48 |
| Linux Apache/PHP-FPM | U30 | 0.00 | 0.15 | 0.45 | 2.27 | 5.90 | 5.90 | 6.02 | 6.02 | 6.11 | 6.20 | 6.20 | 6.47 | 64.8461 | PASS | 0 | 144/144 langgar 0 | 48 |

Selisih Linux vs XAMPP: kandidat pertama diklaim lebih awal di Linux (OPcache aktif, proses PHP-FPM terpisah); review merit dan rilis sama (dispatch, CP, HR identik). Di XAMPP Windows nyata OPcache bawaan mati — mengaktifkannya disarankan di CARA_PASANG_XAMPP.md.

## Regression

| suite | PASS | FAIL |
|---|---|---|
| Full regression (source beku) | 409 (402 baris log + 7 baris reliability yang terpotong `tail -26` oleh jejak diagnostik; laporan DISPATCH_RELIABILITY.md: 25/25 LULUS) | 0 |
| Clean-extract ZIP + uji browser Linux dari isi ZIP | SHA256SUMS OK, identik source beku, lint OK, preflight 200, U31 + U30 PASS | 0 |


Catatan D14 (dispatch reliability, "Run ulang saat job berjalan memakai job yang sama"): gagal intermiten pada dua full regression sebelumnya dan pada 2 dari 18 percobaan terisolasi (job PGN 21 sudah DONE ketika uji memeriksa kondisi "job berjalan"); lulus pada full regression final. Ekspektasi uji tidak diubah; uji kini mencatat jejak langkah dan potret proses/kunci bila poll lambat, di `/tmp/claude-0/drel_trace_<port>.txt`, sehingga bila muncul lagi sebabnya terekam. Server uji reliability dinaikkan ke 16 worker.

## Validasi paket (clean-extract)

ZIP diekstrak ke folder kosong, `sha256sum -c SHA256SUMS.txt` OK, kelima PHP identik dengan source beku, `php -l` OK, lalu dipasang ke Apache/PHP-FPM Linux (`/var/www/html/php74/opr-simulation`, user www-data).

| uji | hasil |
|---|---|
| preflight (`run.php?mode=preflight`) | HTTP 200, ok=true |
| U31 (PGN 31 + PEP 30) Fastest, klik Run → 48 row | 9.55 s, CP 64.7205, HR 8375.02, sidik dispatch f3acd04c7971acfb, merit PASS, C4 0, STG 144/144 langgar 0 |
| U30 (PGN 30 + PEP 30) Fastest, klik Run → 48 row | 6.25 s, CP 64.8461, HR 8420.61, sidik dispatch 17b40201749fe55a, merit PASS, C4 0, STG 144/144 langgar 0 |

Sidik dispatch sama dengan uji XAMPP (semantik) dan Linux sebelumnya.
