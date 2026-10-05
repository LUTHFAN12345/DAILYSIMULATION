# Cara pasang — XAMPP (Windows)

Paket: `php_simulation_FIX_PGN_RECOMMENDATION_SR_FOLLOW_PV.zip`. Lima berkas PHP di akar ZIP adalah **satu paket** — jangan menyalin sebagian.

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

## Setelah pemasangan versi ini

- **Input lama tetap jalan:** input tanpa token baru otomatis memakai mode `Fix Spinning Reserve` dengan nilai `spinning_reserve_min` lama (perilaku lama). Tidak ada migrasi data yang diperlukan, dan data tersimpan tidak dihapus.
- **Bus Flow & Spinning Reserve:** tab *Frequently Input → Bus Flow & Spinning Reserve* kini berisi dua section, BUS FLOW di atas dan SPINNING RESERVE di bawah.
- **Import:** tombol *Import CSV / Excel…* di *IE Prediction & Dispatch* membaca row 1 sebagai header, lalu data row 2–49 kolom A–D (D = PV).
- **Data baru tersimpan biasa:** `sr_mode`, `sr_fixed_mw`, `pv_rows`, `sr_effective_rows`, dan `pgn_fixed_flow_recommendation_applied` disimpan lewat `saved_data_store.php` dan `input_data.json`. Untuk input yang sama, path dan hasilnya identik dengan Linux.
