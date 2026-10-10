# NEXT_STEPS

1. **Uji di XAMPP Windows nyata** (Apache mpm_winnt + mod_php). Parity sudah dibuktikan di Linux Apache + FPM dan proksi semantik XAMPP. Jalur file memakai `DIRECTORY_SEPARATOR`, dan atomic rename punya fallback Windows, tetapi belum dijalankan di Windows sungguhan.
2. **V8 Fastest dibatasi waktu.** Hasil kasus dengan kandidat review yang banyak dapat sedikit berbeda antar mesin (selisih CP ≤ 0,05 USD/MWh, contohnya R4 PV-on LNG 74.8839 vs 74.933). Hasil tetap valid dan lolos C1–C4. Bila hasil yang identik bit-per-bit antar mesin diperlukan, ganti batas waktu dengan batas jumlah evaluasi yang dikalibrasi.
3. **Paralelisasi kandidat V8 ke helper job** (`job_help` slot 1–3). Saat ini kandidat dievaluasi berurutan; versi paralel dapat memangkas review Fastest dari sekitar 8–9 s menjadi sekitar 3–4 s.
4. **Endpoint `job_list`** masih menampilkan job semua user (hanya metadata, tanpa input/hasil). Bila perlu, filter dengan `owner_uid`.
5. **Retensi datastore** (200 versi / 180 hari) dapat diatur lewat konstanta di `saved_data_store.php` sesuai kebijakan data.
