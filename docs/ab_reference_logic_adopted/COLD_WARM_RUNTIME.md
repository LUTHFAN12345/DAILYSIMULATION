# COLD_WARM_RUNTIME

## Cara ukur
- UI asli (Chromium/Playwright) → proksi semantik XAMPP (6 backend PHP 7.4, `max_execution_time=30`). Tombol yang diklik sama dengan yang dipakai pengguna.
- **Cold**: folder `jobs/`, `data/`, `saved_data_*`, dan `output_data.json` dihapus sebelum run. Tidak ada memo, basis, atau hasil tersimpan.
- **Warm**: run ulang state identik dengan `jobs/` dipertahankan. Hasil diambil dari memo/basis state yang sama, dan nilainya identik dengan cold.
- Waktu = detik sejak klik tombol jalan:
  - `popup` = popup keputusan bahan bakar tampil (atau hasil pertama)
  - `after-click` = dari klik LNG/Distillate sampai 48 row final dirilis
- Hasil mentah: `runs/final2/summary.txt` (matriks final pada source beku).

## P0 / P6 — rekomendasi keputusan bahan bakar
| Run | c1 | c2 | c3 | median | max | w1 | w2 | w3 | median | max |
|---|---|---|---|---|---|---|---|---|---|---|
| P0 popup (s) | 10,19 | 9,69 | 9,24 | **9,69** | 10,19 | 0,84 | 0,88 | 0,81 | 0,84 | 0,88 |

P6 = waktu popup P0 (jalur sama). Seluruh 9 popup cold pada alur P0 (P0/P7/P8): median 9,98 detik, max 11,52 detik.

## P7 / P9 — LNG (Follow PV OFF)
| | c1 | c2 | c3 | median | max | w1 | w2 | w3 | median | max |
|---|---|---|---|---|---|---|---|---|---|---|
| total (s) | 26,20 | 25,02 | 27,30 | 26,20 | 27,30 | 1,65 | 1,72 | 2,00 | 1,72 | 2,00 |
| setelah klik (s) | 15,97 | 15,57 | 17,58 | **15,97** | 17,58 | 0,80 | 0,76 | 0,82 | 0,80 | 0,82 |

CP 72,3059 / HR 8229,68, identik di keenam run. P9 = run P7 (payload sama, Follow PV OFF).

## P8 — Distillate
| | c1 | c2 | c3 | median | max | w1 | w2 | w3 | median | max |
|---|---|---|---|---|---|---|---|---|---|---|
| total (s) | 18,90 | 16,83 | 17,23 | 17,23 | 18,90 | 1,56 | 1,64 | 1,57 | 1,57 | 1,64 |
| setelah klik (s) | 7,38 | 6,85 | 6,94 | **6,94** | 7,38 | 0,69 | 0,80 | 0,69 | 0,69 | 0,80 |

CP 82,0968 / HR 8261,08 / 114.378,9 l, identik.

## P10 — Follow PV ON + LNG
| | c1 | c2 | c3 | median | max | w1 | w2 | w3 | median | max |
|---|---|---|---|---|---|---|---|---|---|---|
| popup (s) | 14,50 | 14,56 | 14,62 | 14,56 | 14,62 | 0,81 | 0,83 | 0,88 | 0,83 | 0,88 |
| total (s) | 50,38 | 48,02 | 47,79 | **48,02** | 50,38 | 1,46 | 1,56 | 1,58 | 1,56 | 1,58 |

CP 76,19 / HR 8638,75 / LNG 8,7709, identik.

## Run tunggal (cold)
| Kasus | total (s) | Catatan |
|---|---|---|
| P10d PV ON + Distillate | 29,50 | popup 15,27 |
| P2 / P3 / P4 / P5 | 25,91 / 23,66 / 24,52 / 22,75 | popup 9,2–9,5 |
| P11 | 22,80 | popup 8,93 |
| P12 terminal | **4,62** | |
| P13 Required Start | 42,95 | popup 9,43 |
| P14 Change Over | 16,89 | tanpa popup |
| GOLDEN Fastest | 15,53 | |
| P1 Max + LNG | 111,95 | popup 16,3 |
| GOLDEN Max | 205,08 | |

## Target vs terukur
| Target | Terukur | Status | Critical path dan minimum terukur |
|---|---|---|---|
| Rekomendasi ≤10 s | median 9,69 (P0); max 11,52 dari 9 run alur P0; P10 (PV ON) 14,6 | **sebagian** | Pipeline basis ±8,5 s (tanpa GCR/family) + start job/polling ±1 s. P10: commit-for-reserve per row Follow PV ±5 s tambahan |
| LNG diterima ≤15 s | median 15,97, max 17,58 | **tidak (selisih ±1–2,6 s)** | V8 priority review ±13 s (sumber kualitas C1–C4). Tidak dipangkas agar kualitas tidak turun |
| Distillate diterima ≤15 s | median 6,94 | **ya** | — |
| Fastest normal ≤15 s | P14 16,89; GOLDEN 15,53 | **hampir (selisih ±0,5–1,9 s)** | pipeline + V8 review |
| Sulit tapi feasible ≤30 s | P10d 29,5 ✓; P10 48,0 ✗; P13 43,0 ✗ | **sebagian** | P10 LNG: pipeline state bahan bakar dengan SR per row; P13: required start memperbesar ruang V8 |
| Terminal ≤15 s setelah bukti | 4,62 | **ya** | — |
| Max Review golden ≤80 s | 205 | **tidak** | V7 + family paralel 98 s → V8 review 91 s → perbaikan C1–C4 21 s. Hasilnya lebih baik dari golden (CP 80,1504 vs 80,2321) |

Tidak ada PASS yang dipaksakan. Target yang tidak tercapai dilaporkan bersama critical path dan minimum terukurnya.
