# TEST STATUS — V15.17

| Status | Hasil | Catatan |
|---|---|---|
| Functional | **PASS** | Seluruh fitur inventory (FEATURE_INVENTORY.md) bekerja end-to-end lewat UI nyata. |
| Validation | **PASS** | Matriks 38 skenario: 35 gate PASS. Tiga sisanya (S1, S6, S23) berakhir dengan sertifikat `TERMINAL_INFEASIBLE_EXPORT_ROW_1` yang dibuktikan secara fisik. Suite fx: semua kasus feasible PASS. C1–C4 FAIL = 0, STG PASS, kontinuitas Distillate PASS. |
| Quality | **PASS** | Golden CP 79,9049 (V15.16: 79,9299), HR 8239,87. CP warm IE sama atau lebih baik dari cold, dalam band 0,2 %. |
| Performance | **PASS** | Lihat tabel di bawah. Tidak ada blocker waktu; pengukuran di atas target ideal dicatat dengan bukti critical path. |
| Release blocker | **Tidak ada** | Gate §12 dan batas Max 80 s dari Addendum 2 terpenuhi (FINAL_RELEASE_MATRIX.md). |

## Waktu browser (klik Run → FINAL), source beku

Server: XAMPP-like (6 worker PHP 7.4) / Linux (Apache 2.4 + PHP-FPM 7.4).

| Kasus | XAMPP-like (s) | Linux (s) | Target |
|---|---|---|---|
| Golden Fastest (rekomendasi → Distillate) | 12,8 | 12,1 | ≤ 15 |
| Golden Maximum Review (dua langkah) | 27,1 | 28,7 | ≤ 80 |
| Golden Maximum Review (GOLDEN_dist) | 26,4 | 26,1 | ≤ 80 |
| PGN25/LNG18/PEP36/Akasia4 | 13,1 | 14,3 | ≤ 15 ideal / ≤ 30 |
| Follow PV (P10) | 15,3 | 16,3 | ≤ 30 (sulit) |
| Actual Gas basis (R5) | 13,1 | 14,1 | ≤ 15 |
| Change Over B2 → B1 | 3,0 | 3,0 | ≤ 15 |
| Change Over B1 → B2 + LNG | 24,7 | 24,8 | ≤ 30 (sulit) |
| IE warm jendela kecil (job / klik→FINAL) | 3,8–4,5 / 8,1–9,2 | 3,9–4,4 / 8,2–9,2 | job < 5 |
| Terminal row 1 (P12) | 2,0 | – | segera |

## Perbaikan yang ditemukan lewat uji final (semua sudah masuk source beku)

1. Golden Maximum Review 86 s → 26 s.
   - Penyebab: polish per node keluarga tidak berbatas; dengan allocator kontinu jumlah node valid naik dari 0 menjadi 4.
   - Perbaikan: `PP_TL_POLISH_S` 3 s.
2. Basis warm IE tidak pernah kena di browser.
   - Penyebab: metadata request `_*` ikut masuk key basis.
3. Popup Gas Shortage Change Over mengunci tombol LNG/Distillate.
4. Cancel dari user lain dengan input identik membatalkan job bersama pada detik-detik awal.
   - Perbaikan: subscriber dicatat saat job dibuat.
5. Pill "Gas SHORTAGE" tampil pada rencana Distillate yang valid; offset Distillate global bisa basi.
6. Footer build lama; notice PHP "Undefined array key" pada audit headroom.
