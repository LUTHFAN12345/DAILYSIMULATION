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
- Daily Export target REQUIRED (T124/T127/T128/T129, juga T130 dispatch-dev): lift target harian (`pp_compute_pln_targets`) dibagi sebanding headroom
  RangeMax menjadi lantai per row (`pln_lo`) termasuk row 1 yang secara fisik hanya dapat dilayani unit Last Data Running (row 1 Export 15,4 < 59–155).
  Identik pada V15.22 (diverifikasi: T127 di app V15.22 -> row 1 Export 15,4 < RangeMin 59,07). Percobaan redistribusi lift berbasis export tercapai
  sesudah dispatch pertama DIBATALKAN: pada tahap itu commitment belum diputuskan sehingga lantai turun ke 550 MWh (< target 1500) — target harian
  justru tidak ditegakkan. Perbaikan yang benar memerlukan target harian ikut menggerakkan commitment (startup) — dicatat sebagai keterbatasan.
- T111 (IE Adjustment nol): basis warm IE satu per konteks ditimpa run terakhir -> hasil bergantung riwayat (CP 95,0865 vs 93,4567).
  Diperbaiki: basis eksak per (konteks, profil IE efektif, Actual Gas, Fix Load) + bukti event/penutupan juga pada jalur warm.
- T105/T117 (distillate plafon user < kebutuhan): kini TERMINAL_INFEASIBLE_FUEL_CAP dengan bukti numerik (kebutuhan, plafon, sisa > batas atas penurunan
  gas yang masih dapat dipakai) + opsi (plafon liter yang dibutuhkan / LNG tambahan); sebelumnya pratinjau non-terminal.
- T034/T045/T047: Unit Priority Fix sesudah Tightening membuat tunda-start menjadi valid & lebih baik -> penutupan bukti event pada dispatch FINAL.
- T055/T076/T079/T081/T091/T107: oracle diperbaiki (baris pembanding salah / hash job dari __bench / window gas = Total Gas Used vs [kuota-0,04, kuota]).
