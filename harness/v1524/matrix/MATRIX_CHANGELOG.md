# MATRIX_CHANGELOG — V15.24 (192+ matrix)

Format: setiap perubahan/penggantian/penambahan skenario terhadap katalog minimum Bagian IV, plus temuan investigasi selama eksekusi.

## Struktur
- Katalog engine (A–H sebagian, J1–J6, X) didefinisikan di `mxdefs.py`; Test ID T001… diberikan berurutan sesuai definisi.
- UI/DB (A24–A26 tampilan, B6–B7 tampilan, H15–H16, I1–I12) dijalankan oleh `gi.php` (MySQL MariaDB uji) dan `../ui/smoke.js` (Playwright/Chromium);
  hasil dimasukkan ke laporan dengan Test ID lanjutan.
- Grup X = skenario tambahan wajib V15.23 (di atas 192).

## Perubahan terhadap katalog
- A24/A25/A26: varian engine menjalankan payload hasil reload; persistensi UI-nya diuji terpisah di UI-S4/UI-S5 (Playwright) — dicatat sebagai bukti tambahan.
- B6/B7: toggle Required ON->OFF->ON dan Save/Reload diuji di UI-S3/UI-S4 (Playwright); varian engine menjalankan payload akhir.
- D6 (KP72 -1): kuota KP72 fixture = 0; -1 tidak sah sehingga dijepit 0 (payload = A0, oracle hash). Tetap dihitung, dicatat sebagai duplikat payload.
- D9/D10: Akasia/MM2100 hanya diuji arah naik (fixture tidak memakai keduanya; arah turun dari 0 tidak sah).
- J7: urutan kandidat — diwujudkan sebagai X (3 seed shuffle, app tanpa cache). J8/J9: determinisme cold/warm 3x dari benchmark P0.4. J10: Fastest vs Maximum Review smoke.
- Skenario dengan payload identik (catatan `payload = ...` di definisi) memakai satu run engine (cache payload hash) dengan oracle berbeda; tidak dihitung
  sebagai tambahan terhadap minimum 192 — lihat bagian "Hitungan unik" di laporan akhir.

## Temuan investigasi
- T001 (G5 Initial Stop + Simulation + Hold): G4 (level 3) berjalan 04:00–12:00 sebelum G5 (level 2, Required) start 08:00. Diuji kandidat generik baru
  ADVANCE_REPLACE+SELF_DELAY (majukan unit Required menggantikan bagian awal interval unit prioritas lebih rendah). Hasil final dengan kandidat itu:
  CP 101,077 / inversi 55 row vs tanpa kandidat CP 98,8143 / inversi 43 row (comparator final: tanpa kandidat LEBIH BAIK pada semua kunci).
  Penyebab: G5 Hold to End — start lebih awal memaksa G5 ON sampai 00:00; pencarian greedy dengan kandidat baru berakhir di optimum lokal lebih buruk.
  Keputusan: kandidat tidak dipasang (perubahan dibatalkan); perilaku T001 terbukti oleh comparator dan Event Proof TERBUKTI.
