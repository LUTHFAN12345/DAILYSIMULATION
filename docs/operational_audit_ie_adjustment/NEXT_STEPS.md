# NEXT STEPS (setelah V15.17)

Item di bawah **bukan release blocker** menurut gate Addendum §12. Semua item terukur dan dicatat apa adanya.

1. **Actual Gas A2/A4/A6 (input infeasible).** Fastest dan Maximum Review sama-sama tidak menemukan rencana valid.
   - Envelope identitas akunting menunjukkan window pipe dan total tidak beririsan (A2/A4), dengan selisih 0,015–0,024 BBTUD. Selisih ini di bawah margin sertifikat 0,05, jadi sertifikat terminal tidak diterbitkan.
   - Usulan: sertifikat kedua berbasis observasi dispatch nyata (K terukur) dengan margin numerik kecil. Ini perlu persetujuan karena menyangkut kekuatan bukti.
   - Maximum Review pada state ini menerbitkan rencana jangkar kanonik tanpa actual (gate FAIL). Sebaiknya ditampilkan sebagai blocker dengan bukti envelope.
2. **Browser IE warm.** Langkah rekomendasi (±2,5–3 s) dijalankan ulang setiap perubahan IE untuk menghitung ulang liter Distillate, sehingga klik→FINAL 6,9–9,2 s walaupun job warm 4,0–4,6 s.
   - Opsi: estimasi rekomendasi warm dari basis yang sama (delta energi IE × kurva bahan bakar), tetap divalidasi rerun.
3. **Change Over dengan gas shortage di UI (CO0/CO3).** Popup opsi tervalidasi baru aktif setelah 13–26 s, karena validasi rerun penuh LNG dan Distillate dijalankan paralel.
   - Opsi: validasi LNG lebih dulu dan tampilkan tombol LNG segera setelah lolos.
4. **IE9 (Follow PV + IE Adjustment), dua langkah ±24 s CLI.** Masih dalam batas kasus sulit (≤ 30 s), tetapi di atas target cold 15 s. Pola waktunya sama dengan baseline Follow PV (P10).
5. **D4/SR6 (G6 Stop Request 08:00) ±22 s.** Kasus sulit: G3 start ditambah Distillate dua unit. Masih ≤ 30 s.
6. **Uji Linux/XAMPP berkala.** Matriks R12 di `FINAL_RELEASE_MATRIX.md`. Ulangi pada mesin produksi karena waktu absolut bergantung pada CPU.
