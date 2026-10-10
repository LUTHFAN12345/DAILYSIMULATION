# FIRST_DIVERGENCE_GOLDEN_VS_LATEST

Input sama (`input_data.json`, 07/10-May-26 Baru). Sumber pembanding:
- Golden `Daily_Plan_10_May_26_Baru(5).xls` — engine reference V13.4 (Maximum Review).
- Latest `Daily_Plan_10_May_26_Baru(7).xls` — build V15.15 (commit 92263fb), Fastest + Distillate.

## 1. Bukti regresi (diakui)
| | Golden (5) | Latest (7) | Δ |
|---|---|---|---|
| CP (USD/MWh) | 80.2321 | 82.0968 | +1.8647 |
| Heat Rate (BTU/kWh) | 8249.58 | 8261.08 | +11.50 |
| Daily PLN Export (MWh) | 517.06 | 581.14 | +64.08 |
| Gas required (BBTUD) | 82.6572 | 83.3017 | +0.6445 |
| Distillate (l) | 96,057.7 | 114,378.9 | +18,321.2 |
| Startup | G1, G4 | G3, G5 | — |

Gejala latest: G5 start 01:00 (5 MW), G9 turun ke 65 MW (04:30–06:30), dan Distillate 709,5 l pada G5 5 MW di 01:00.

## 2. Metode
Probe snapshot dipasang pada setiap pass dispatch di `pp_run_simulation_once_raw` (worker02.php), lalu di dalam `pp_shape_final_dispatch` (worker_functions.php) setiap ±40–60 baris, lalu pada array lever shaper (`$g5row`, `$g3row`) dan himpunan defisit `$need`.

Core run identik di tiga engine (reference V13.4, Copilot V15.14, V15.15): `pp_run_simulation_core` pada state distillate menghasilkan G3 2–37 / G5 2–48, CP 86.9633. Golden bebas G5 hanya karena Maximum Review (keluarga commitment / GCR) membuangnya. Fastest tidak menjalankan tahap itu, sehingga cacat core terbawa ke hasil.

## 3. Routine pertama yang menyimpang (bukti row-level)
| # | Routine (file : bagian) | Row pertama | State sebelum | State sesudah | Kandidat (sha256 commitment) |
|---|---|---|---|---|---|
| D1 | `pp_shape_final_dispatch` → lever export-floor Block-2 (`$need` → `$b2Levers` g2/g5), worker_functions.php | 2 (01:00) | G5 15–35 (rencana awal core) | G5 2–48, G3 2–37 | `30cde0b5029e` (G2,G3:2-37,G5:2-48,G6,G8,G9) |
| D2 | `pp_shape_final_dispatch` → gas-cap final `$tryRemove` (kompensasi G3 hardcode) | 17 | G4 15–34 (setelah perbaikan D1) | G4 dilepas, G3 17–48 + G1 17–31 | — |
| D3 | `pp_shape_final_dispatch` → lever Block-1 (eskalasi G3 hardcode lebih dulu daripada g4/g6) | 15 | — | G3 dipilih walau Unit Priority user g6 > g4 > g3 | — |
| D4 | Distillate greedy `rsort($eligible)` + langkah penutup diskret (worker02.php) | 15 (07:30) | G6 (prioritas 1 Distillate) masih punya slot kosong rows 1–28 | sisa ditaruh di G4 5 MW (lead-in startup) 709,5–1182,5 l | — |

### Detail D1 (akar G5 01:00 dan G9 65 MW)
Probe defisit export-floor memanggil `$mkFull(..., g4=0, g6=0, g2=0, ...)` secara literal. Padahal G2 dan G6 adalah unit Cannot-Stop/Running yang DIJAMIN berbeban ≥ min-CC 20 MW dan menyuplai steam S2/S1.

Akibatnya export terhitung −33 s/d −150 MW di SEMUA row 1–48. Contoh row 2: −35,2 MW, padahal kenyataannya +15,5 MW.

Lever Block-2 lalu menaikkan G5 pada satu window KONTIGU dari row defisit pertama sampai terakhir, yaitu row 2–48 dengan lead-in 5/15 MW di row 2. G3 dieskalasi untuk row 2–37. Kapasitas tambahan G5+S2 kemudian memaksa G8/G9 turun ke lantai `min_ccload` 65 MW agar Export tidak melebihi kebutuhan (G9 65 MW row 9–13). Export harian naik +64 MW, gas naik, dan Distillate naik.

Ini kelas cacat yang sama dengan PATCH B01a lama, yang hanya memperbaiki G3.

### Detail D2
Gas-cap final (gas > kuota adalah keadaan normal pada state shortage) melepas G4 (prioritas terendah g4/g6), lalu "mengompensasi" dengan menaikkan G3 yang sedang OFF (komentar kode: "already-running CC unit G3"). Hasilnya penukaran unit, bukan pengurangan gas.

### Detail D4
Pass greedy Distillate melewati slot G6 yang lebih besar dari sisa kebutuhan, lalu turun ke unit prioritas berikutnya (G4 di slot lead-in 5 MW). Langkah penutup memilih overshoot terkecil lintas unit tanpa urutan prioritas.

## 4. Perbaikan (generik, tanpa hardcode unit/tanggal/dispatch)
| Patch | Isi | Saklar rollback |
|---|---|---|
| V1516_BASE | Lantai commitment (Cannot-Stop / Continuous / Last-Running sampai stop pertama) untuk g2/g4/g6 masuk probe defisit dan state lever | `PP_V1516_BASE=0` |
| V1516_RUNFIRST | Sebelum lever menyalakan unit baru, headroom unit yang SUDAH berbeban dinaikkan dulu (urutan Unit Priority user) dengan batas headroom Spinning Reserve per row (Fix/Follow PV) | `PP_V1516_RUNFIRST=0` |
| V1516_B1ORDER | Block-1: g4/g6 sebelum G3 bila Unit Priority user menempatkannya lebih tinggi (G3 fallback). Lead-in hanya untuk unit yang benar-benar START. Window unit yang di-start kontigu | `PP_V1516_B1ORDER=0` |
| V1516_GASCAP | Kompensasi gas-cap hanya dengan unit yang sudah berbeban; pelepasan hanya sah bila gas benar-benar turun | `PP_V1516_GASCAP=0` |
| V1516_DIST_TIER | Distillate full-first: greedy berhenti bila unit prioritas sebelumnya belum penuh; langkah penutup bertingkat per Unit Priority Distillate dengan bukti per tingkat (`priority_tiers`) | `PP_V1516_DIST_TIER=0` |

## 5. Dampak ekonomi (input golden, state Distillate)
| Tahap | CP | HR | Distillate | Startup |
|---|---|---|---|---|
| Core lama (D1 aktif) | 86.9633 | 8464.63 | 149,939 | G3 2-37, G5 2-48 |
| Core + V1516_BASE | 82.6659 | 8336.98 | 114,233 | G3 10-34, G5 14-46 |
| Core + seluruh patch shaper | 80.7138 | 8280.25 | 98,576 | G4 15-34, G5 14-37 |
| **Fastest V15.16 (job lengkap)** | **79.9299** | **8239.87** | **93,641.1 (hanya G6)** | **G4 15-34, G5 14-35** (`538f5ae350b4`) |
| Golden (5) | 80.2321 | 8249.58 | 96,057.7 (G6+G4) | G1 16-36, G4 15-32 (`c88717085e59`) |
| Latest (7) | 82.0968 | 8261.08 | 114,378.9 | G3 15-35, G5 2-32 (`aaf0217166e3`) |

G5 (pengganti G1 pada blok S2 yang sama) dipilih karena Unit Priority user menempatkan g5 di atas g1. Unitnya identik, sehingga ekonominya setara.
