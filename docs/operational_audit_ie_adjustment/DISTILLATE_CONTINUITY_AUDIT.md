# Distillate Continuity Audit (Addendum §1, R0)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — level legal hanya 30/50/75/100 %; 75 % muncul di UI, payload, worker, validator, kalkulasi bahan bakar, Simulation Data, Summary, Save/Reload, Excel |
| Validation | **PASS** — audit `distillate_continuity` + hard validation PASS pada seluruh kasus feasible; D→G→D hanya dengan bukti row tidak eligible |
| Quality | **PASS** — golden CP 79,9049 (V15.16: 79,9299), HR 8239,87; satu blok kontinu G6 row 26–48 (30→50→75→100) |
| Performance | **PASS** — golden Fastest ±10 s CLI / ±12 s browser; golden Max 26,4 s browser |
| Release blocker | Tidak ada |

## Aturan yang ditegakkan (generik)
- Level legal per unit: 30, 50, 75, 100 % (pasangan daftar — bug float-key PHP diperbaiki).
- Tidak ada Distillate pada unit ≤ 5 MW (startup / tidak operasional).
- Satu blok kontinu per unit; ramp ±1 level per row; masuk/keluar blok pada 30 % bila bersebelahan dengan row gas.
- Celah antara dua blok hanya sah bila ada row tidak eligible (bukti dicetak per celah, mis. `row 30 tidak eligible (G6 0.00 MW)`).
- Full-first antarunit: unit prioritas Distillate pertama dipenuhi lebih dulu; unit kedua hanya bila unit pertama habis ruang.
- Rekomendasi memakai allocator yang sama + margin 5 % → liter rekomendasi ≥ liter eksekusi (perbaikan browser golden VALID-INFEASIBLE).

## Hasil (CLI, source final, cold)
| Kasus | Wall (s) | Hard | Gate | CP | HR | C1–C4 FAIL | STG | Kontinuitas Dist. | Cache warm |
|---|---|---|---|---|---|---|---|---|---|
| D0 golden | 9.83 | PASS | PASS | 79.9049 | 8239.87 | 0 | PASS | PASS | MISS |
| D2 G6 stop 21:00 | 10.68 | PASS | PASS | 80.537 | 8265.81 | 0 | PASS | PASS | MISS |
| D3b prioritas pertama unavailable | 8.88 | PASS | PASS | 79.9049 | 8239.87 | 0 | PASS | PASS | MISS |
| D4 G6 stop 08:00 | 20.5 | PASS | PASS | 80.453 | 8264.03 | 0 | PASS | PASS | MISS |
| D4b dua unit | 9.69 | PASS | PASS | 88.1996 | 8239.87 | 0 | PASS | PASS | MISS |
| D4c dua unit (G6 + G4) | 9.84 | PASS | PASS | 99.2948 | 8239.87 | 0 | PASS | PASS | MISS |
| D5b dua blok G6 (celah stop window) | 10.58 | PASS | PASS | 98.7983 | 8462.1 | 0 | PASS | PASS | MISS |
| D3 G6 unavailable (terminal) | 0.63 | FAIL | FAIL | 86.827 | 8536.17 | 81 | PASS | PASS | – |
| D5 fixture lama (terminal) | 0.7 | FAIL | FAIL | 91.1641 | 8552.46 | 81 | PASS | PASS | – |
| D5 fixture lama Fix Load 5 MW (ilegal) | 16.38 | FAIL | FAIL | 94.6531 | 8361.6 | 0 | PASS | PASS | MISS |

Catatan: D3/D5 (fixture lama) berakhir dengan sertifikat `TERMINAL_INFEASIBLE_EXPORT_ROW_1` (unit Last Data Running dibuat tidak tersedia → row 1 tidak dapat memenuhi Range Min). `D5_two_blocks_fixload` memakai Fix Load 5 MW yang ilegal di luar startup sequence; validator menolaknya dengan benar dan fixture diganti D5b.

## UI 75 %
- Excel (`resultToHTMLTable`): kolom Distillate G6 (%), nilai 75 terdeteksi: True.
- Audit kontinuitas pada hasil UI: PASS.
