# PERFORMANCE_CRITICAL_PATH

Data: `runs/final_x/` (R0, R1, P1 pada source final) dan `runs/racc3/` (kasus Fastest lainnya; source identik dengan final kecuali supersede ber-scope yang hanya aktif pada multi-user, diverifikasi ulang di Linux pada source final). Semua waktu diukur di browser nyata dari klik Run sampai 48 row FINAL. Cold = cache job/memo dibersihkan; warm = state identik. Lingkungan: proksi XAMPP-semantik 6 backend PHP 7.4 (4 vCPU). Parity Linux Apache + FPM 7.4 ada di TEST_STATUS.md (R10).

| Kasus | Target | Cold (s) | Warm (s) | Popup rekomendasi (s) | CP | Status |
|---|---|---|---|---|---|---|
| R0 Golden Fastest | ≤ 15 | **11,57 / 11,60 / 11,79** (source final; matrix sebelumnya 11,08 / 12,22 / 13,23) | 1,24 / 1,28 / 1,32 | — | 79.9299 | PASS |
| R1 Golden Maximum Review | ≤ 80 | **25,17** (source final; sebelumnya 26,73) | 1,29 | — | 79.9299 | PASS |
| R2 LNG accepted (P0) | ≤ 15 | 13,17 / 13,37 / 13,70 | 1,41 / 1,51 / 1,55 | 2,87–3,19 | 71.9046 | PASS |
| R3 Distillate accepted (P0) | ≤ 15 | 11,57 / 11,79 / 11,81 | 1,33 / 1,38 / 1,51 | 2,84–2,87 | 79.9299 | PASS |
| R4 Follow PV ON + LNG (P10, difficult) | ≤ 30 | 14,77 | — | 7,16 | 74.9099 | PASS |
| R4 Follow PV ON + Distillate (P10, difficult) | ≤ 30 | 20,69 | — | 6,92 | 90.9873 | PASS |
| R4 Follow PV OFF + LNG | ≤ 15 | 13,79 | — | 3,13 | 71.9046 | PASS |
| R5 Actual Gas | ≤ 15 | 12,99 / 13,46 / 13,46 | 1,02 | — | 64.6663 | PASS |
| R6 PGN 22 / 24, PEP 35 / 37 | ≤ 15 | 13,48 / 12,93 / 12,87 / 13,77 | — | 2,57–3,26 | 72.2387 / 71.5706 / 72.3661 / 71.4432 | PASS |
| R9 Change Over B2→B1 | ≤ 15 | 12,09 | — | — | 64.6658 | PASS |
| P13 required start G1 08:00 (difficult) | ≤ 30 | 11,83 | — | 3,74 | 72.4625 | PASS |
| P11 G8 stop/available | ≤ 15 | 13,21 | — | 2,87 | 71.9046 | PASS |
| P12 terminal infeasible | — | 4,63 (keputusan terminal) | — | — | — | PASS (terminal ber-evidence) |
| P1 Maximum Review + LNG | ≤ 80 | **65,89** (source final; sebelumnya 67,34) | — | 8,83 (sebelumnya 8,97–9,98) | 71.834 | PASS (margin rekomendasi kecil) |

## Akar penyebab yang diperbaiki
1. **Maximum Review 205 s.** V8 pada mode Max tidak punya batas sisa waktu. Kini `PP_V8_MAX_TOTAL` 58 s dikurangi elapsed.
2. **Fastest V8 tanpa batas jumlah** (92263fb: hingga 60 s untuk P7). Kini bounded race: 4 kandidat, 1 ronde.
3. **Anggaran waktu V8 Fastest konstan 10 s** selalu habis. Akibatnya kasus pipeline panjang (Actual Gas 21–22 s) dan rerun bahan bakar (15,3–15,9 s) melewati 15 s. Kini anggaran = sisa target total job (11,5 s; rerun bahan bakar 8,5 s; minimum 2 s).
4. **Decommit menjalankan core run untuk stop-window yang mustahil.** Kini prasaring kapasitas export, berupa bukti numerik yang sama dengan V8.
5. **Fix pass C1–C4 mengulang langkah lokal yang tidak valid** (P10 48 s). Kini ada bukti tepi atas window gas + memo; P10 ≤ 21 s.

Validator tidak dilonggarkan: setiap hasil di atas berstatus gate PASS, C1–C4 FAIL = 0, dan STG PASS 144/0.
