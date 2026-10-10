# P0_P15_RESULTS

Source beku: build `V15.15-REFERENCE-LOGIC-ADOPTED-FASTEST-20261010`.
Pengujian lewat UI asli. Proof = 'Merit Proof C1-C4 STG' (n/m = temuan dicek / gagal).
Semua run final: 48 row, release gate PASS, hard PASS, SR unmet 0, error JS 0.

| Test | Skenario | Hasil | Waktu cold (s) | CP | HR | Bahan bakar | C1 | C2 | C3 | C4 | STG |
|---|---|---|---|---|---|---|---|---|---|---|---|
| P0 | input_data.json terbaru, Fastest - Default | **PASS** — popup keputusan + rekomendasi, tanpa "BELUM konvergen" | 9,69 median (3c) / 0,84 (3w) | — | — | shortage 5,3957 BBTUD | — | — | — | — | — |
| P1 | input sama, Maximum Review (+LNG dari popup) | **PASS** FINAL OPTIMAL | 111,95 | 72,0530 | 8191,94 | LNG 5,4357 | 7/0 | 1/0 | 30/0 | 71/0 | 144/0 |
| P2 | PGN −1 | **PASS** | 25,91 | 72,6327 | 8229,68 | LNG 6,4157 | 66/0 | 1/0 | 70/0 | 101/0 | 144/0 |
| P3 | PGN +1 | **PASS** | 23,66 | 71,9791 | 8229,68 | LNG 4,4157 | 66/0 | 1/0 | 70/0 | 101/0 | 144/0 |
| P4 | PEP −1 | **PASS** | 24,52 | 72,7574 | 8229,69 | LNG 6,4957 | 66/0 | 1/0 | 70/0 | 101/0 | 144/0 |
| P5 | PEP +1 | **PASS** | 22,75 | 71,8314 | 8226,31 | LNG 4,3357 | 53/0 | 1/0 | 65/0 | 101/0 | 144/0 |
| P6 | Rekomendasi Gas Shortage | **PASS** | 9,69 median | — | — | rekomendasi LNG = shortage + 0,02; Dist = ceil(rec × 1,01 / 10) × 10 | — | — | — | — | — |
| P7 | rekomendasi → LNG diterima | **PASS** | 26,20 total / 15,97 setelah klik (median 3c) | 72,3059 | 8229,68 | LNG 5,4157 | 66/0 | 1/0 | 70/0 | 101/0 | 144/0 |
| P8 | rekomendasi → Distillate diterima | **PASS** | 17,23 total / 6,94 setelah klik | 82,0968 | 8261,08 | Dist 114.378,9 l | 4/0 | 2/0 | 82/0 | 116/0 | 144/0 |
| P9 | Follow PV OFF (= P7) | **PASS** | sama dengan P7 | 72,3059 | 8229,68 | LNG | sama dengan P7 | | | | |
| P10 | Follow PV ON (+LNG) | **PASS** | 48,02 median | 76,1900 | 8638,75 | LNG 8,7709 | 34/0 | 3/0 | 96/0 | 163/0 | 144/0 |
| P10d | Follow PV ON (+Distillate) | **PASS** | 29,50 | 94,0988 | 8590,06 | Dist 213.304,7 l | 12/0 | 3/0 | 96/0 | 177/0 | 144/0 |
| P11 | unit prioritas tinggi (G8) stop tetapi available | **PASS** | 22,80 | 72,3059 | 8229,68 | LNG 5,4157 | 66/0 | 1/0 | 70/0 | 101/0 | 144/0 |
| P12 | unit prioritas tinggi (G8) unavailable | **PASS** — TERMINAL_INFEASIBLE bersertifikat | 4,62 | — | — | — | — | — | — | — | — |
| P13 | Required Start (G1 mulai 08:00) | **PASS** | 42,95 | 73,1622 | 8282,70 | LNG 6,9644 | 68/0 | 0/0 | 96/0 | 132/0 | 144/0 |
| P14 | Change Over Block 1-2 (B2→B1) | **PASS** | 16,89 | 64,6644 | 8386,61 | — | 0/0 | 1/0 | 34/0 | 96/0 | 144/0 |
| P15 | Save / Reload / Report / saved_data_store | **PASS** | final 16,7 | 82,0968 | — | Dist | | | | | |

## Detail P12
Pesan: "KEPUTUSAN TERMINAL — TIDAK FEASIBLE".
- Bukti: pada row 1, hanya unit Last Data Running yang tersedia yang dapat berbeban (G2, G6, G9, B2, …).
- Ekspor maksimum, dengan STG dihitung `pp_recompute_stgs` serta dikurangi MM, HL dan IE, masih di bawah batas bawah range ekspor.
- Tidak ada popup bahan bakar, karena bahan bakar tidak dapat memperbaiki batas ekspor row 1.

## Detail P15
- Excel `Daily_Plan_10_May_26_Baru.xls` 40.643 byte.
- Image Full 3.563.251 byte.
- Save Actual 200, Save input 200.
- `central_store` true, revision 1 (`SIMULATION_FINAL`, `route=job`).
- Reload: 48 row, `gas_action=use_distillate`, flag internal `__no_exact_family` / `__fastest_local_only` tidak tersimpan, PV 48 row.

## Golden non-regression (setiap patch)
| Uji | Hasil |
|---|---|
| CLI: dispatch Excel Baru(5) dibekukan, dievaluasi engine + validator final | VALID / hard PASS; CP **80,2321**; HR 8249,58; Dist 96.057,7 l. Identik dengan Excel, deviasi 0 MW |
| Fastest pada input golden (use_distillate) | PASS; CP 82,0968; 15,53 s |
| Maximum Review pada input golden | **FINAL PASS; CP 80,1504; HR 8237,67; Dist 96.008,7 l** — lebih baik dari golden (−0,0817 USD/MWh) |

Selisih Fastest terhadap golden (82,10 vs 80,23) adalah konsekuensi definisi Fastest = kandidat fully valid pertama. V7 memakai ulang commitment basis peminimum gas. Commitment golden (G3+G5 off) valid pada 80,37, tetapi butuh ±48 s per evaluasi, sehingga hanya dikerjakan Maximum Review.

## Paritas Linux (Apache 2.4 + PHP-FPM 7.4, `/php74/opr-simulation`)
| Kasus | XAMPP-semantics | Linux |
|---|---|---|
| P0 popup | 9,69 s | 10,77 s |
| P7 LNG | CP 72,3059 / HR 8229,68 | CP 72,3059 / HR 8229,68 (25,09 s) |
| P8 Dist | CP 82,0968 / 114.378,9 l | CP 82,0968 / 114.378,9 l (16,93 s) |
| GOLDEN Fastest | CP 82,0968 | CP 82,0968 (17,04 s) |
| P12 | terminal 4,62 s | terminal 4,63 s |
| P1 Max + LNG | CP 72,0530 / HR 8191,94 (111,95 s) | CP 72,0530 / HR 8191,94 (125,5 s) |
