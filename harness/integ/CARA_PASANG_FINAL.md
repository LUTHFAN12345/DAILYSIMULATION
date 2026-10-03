# Cara pasang — php_simulation_FASTEST_DISTILLATE_DATASTORE_FINAL

1. Salin **lima** berkas di akar ZIP ke folder aplikasi (mis. `C:\xampp\htdocs\php_simulation\`), menimpa versi lama:
   `run.php`, `worker02.php`, `worker_functions.php`, `index.php`, `saved_data_store.php`.
   `saved_data_store.php` WAJIB ikut — `run.php` dan `index.php` memuatnya (`require_once`).
2. Data pengguna disimpan di `<folder aplikasi>/data/` (`data/saved/records`, `data/saved/state`, `data/backups`), atau di folder lain
   bila variabel lingkungan `PP_DATA_DIR` diisi. Folder `data/` tidak ada di ZIP, sehingga deploy berikutnya tidak menimpanya.
   Disarankan menyertakan `data/` dalam backup rutin.
3. Pemasangan pertama: `input_data.json` yang sudah ada dibaca seperti biasa; Save berikutnya membuat record Report dan cermin
   `data/saved/state/input_data.json`. Record lama (report_planning bentuk lama) dibuka lewat migrasi skema v1 -> v2.
4. Rollback: `rollback/V12_CHECKPOINT_7ac6ab8/` (empat berkas V12 checkpoint) dan `rollback/INTEGRASI_V4_88165df/`. Versi rollback tidak
   memakai `saved_data_store.php`; folder `data/` tetap utuh dan dapat dipakai lagi saat kembali ke versi ini.
5. Verifikasi: `SHA256SUMS.txt` (sha256sum -c) mencakup seluruh isi ZIP.

PHP 7.4 (lint bersih). Tidak ada proses OS / CMD yang dijalankan aplikasi.
