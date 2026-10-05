# Cara pasang — XAMPP (Windows)

Paket: `php_simulation_CROSS_PLATFORM_FASTEST_DATASTORE_FIX.zip`. Lima berkas PHP di akar ZIP adalah **satu paket** — jangan menyalin sebagian.

1. Tutup tab aplikasi di browser. Salin **kelima** berkas ke folder aplikasi (contoh `C:\xampp\htdocs\opr-simulation\`), menimpa versi lama:
   `run.php`, `worker02.php`, `worker_functions.php`, `index.php`, `saved_data_store.php`.
2. Jangan menyalin `input_data.json`, `output_data.json`, `jobs\`, atau `data\` dari paket lain — folder itu milik instalasi Anda (data Save/Report ada di `data\`).
3. Buka `http://localhost/opr-simulation/run.php?mode=preflight`. Harus tampil JSON dengan `"ok": true`. Bila tidak:
   - `MISSING_DEPENDENCY` + `file` → berkas itu belum tersalin; ulangi langkah 1.
   - `APP_DIR_NOT_WRITABLE` / `JOBS_NOT_WRITABLE` → folder aplikasi berada di lokasi yang tidak dapat ditulisi akun Apache (mis. `C:\Program Files`); pindahkan ke `C:\xampp\htdocs\...`.
   - `DATASTORE_NOT_WRITABLE` → simulasi tetap jalan, Save/Report nonaktif; beri akun yang menjalankan Apache hak Modify pada folder `data\`.
4. Buka `http://localhost/opr-simulation/`. Bila ada berkas yang hilang, halaman menampilkan "Instalasi tidak lengkap" dan tombol Run nonaktif (tidak ada timer yang berjalan tanpa backend).
5. Disarankan (opsional, mempercepat 20-30 %): aktifkan OPcache di `C:\xampp\php\php.ini` (`zend_extension=opcache`, `opcache.enable=1`), lalu restart Apache.

`SHA256SUMS.txt` memuat hash kelima berkas; nilai yang sama ditampilkan oleh `run.php?mode=preflight` (`checks[].sha256`) sehingga isi folder dapat dicocokkan langsung.
