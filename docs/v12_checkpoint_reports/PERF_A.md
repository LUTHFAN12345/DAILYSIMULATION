# Performa V12 — median / minimum / maksimum tiga run (V11 vs V12, VM sama, bergantian)

Mesin: PHP 7.4, 4 inti CPU, 3 pekerja pembantu, server multi-backend meniru Apache; tiap run cold dari snapshot yang sama (basis Actual: FINAL jangkar PGN 30 + BASE_ACT10; "dari nol": direktori job kosong). Waktu = detik dari request Run sampai FINAL (HTTP). Rantai bahan bakar: satu server warm, urutan popup Gas Shortage PGN 25 -> LNG rekomendasi -> LNG lebih (+2 BBTUD) -> Distillate rekomendasi -> LNG kurang.

| rute | kategori (target) | V11 median | V11 min | V11 maks | V12 median | V12 min | V12 maks | V12 lulus | CP V11 | CP V12 | dispatch identik | core run V12 | idle pembantu V12 (s) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| FUEL_SHORTAGE_PGN25 | Gas Shortage tervalidasi (20,0 s) | 9,19 | 8,80 | 9,21 | 5,87 | 5,16 | 6,57 | YA | 61.6371 | 61.6371 | YA | 11 | 5,46 |
| BASE_ACT10_DARI_NOL | exact berat (40,0 s) | 31,56 | 31,27 | 31,88 | 30,78 | 30,04 | 31,05 | YA | 64.6214 | 64.6214 | YA | 138 | 41,64 |
| BASE_PGN30_DARI_NOL | exact berat (40,0 s) | 17,53 | 17,50 | 17,56 | 16,99 | 16,07 | 17,39 | YA | 64.6644 | 64.6644 | YA | 135 | 18,96 |
| Q_pep_kp72_1 | exact berat (40,0 s) | 27,41 | 27,14 | 27,42 | 19,91 | 19,65 | 20,42 | YA | 64.631 | 64.631 | YA | 155 | 23,16 |
| QA_lng_1 | kuota basis Actual (commitment berubah) (15,0 s) | 16,68 | 16,66 | 17,41 | 14,90 | 14,87 | 15,15 | YA | 64.1733 | 64.1733 | YA | 44 | 12,24 |
| QA_pgn_pipe_1 | kuota basis Actual (commitment berubah) (15,0 s) | 16,65 | 16,40 | 16,67 | 14,92 | 14,65 | 16,05 | YA | 63.8609 | 63.8609 | YA | 44 | 12,78 |
| QA_pgn_pipe_4 | kuota basis Actual (commitment berubah) (15,0 s) | 19,87 | 19,85 | 19,93 | 20,17 | 19,96 | 20,40 | TIDAK | 60.277 | 60.277 | YA | 89 | 37,92 |
| Q_akasia_1 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 13,48 | 13,45 | 13,99 | 11,75 | 11,51 | 12,05 | YA | 64.6661 | 64.6661 | YA | 59 | 6,96 |
| Q_pep_1 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 13,48 | 13,32 | 13,59 | 11,66 | 11,56 | 11,74 | YA | 64.4253 | 64.4253 | YA | 57 | 5,88 |
| Q_pgn_pipe_4 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 9,12 | 8,87 | 9,13 | 9,88 | 9,63 | 10,18 | YA | 64.1256 | 64.1256 | YA | 60 | 11,94 |
| Q_lng_1 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 12,50 | 12,19 | 12,64 | 10,65 | 10,49 | 10,77 | TIDAK | 64.8581 | 64.8581 | YA | 47 | 3,78 |
| Q_pgn_pipe_1 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 12,47 | 11,97 | 12,71 | 10,75 | 10,74 | 10,80 | TIDAK | 64.5424 | 64.5424 | YA | 55 | 3,60 |
| Q_pgn_pipe_2 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 9,56 | 9,52 | 9,87 | 12,69 | 9,52 | 13,79 | TIDAK | 64.4029 | 64.4029 | YA | 47 | 2,40 |
| WB09 | reproducer (HTTP) (15,5 s) | 11,38 | 10,88 | 11,40 | 9,26 | 8,44 | 10,07 | YA | 64.3644 | 64.3644 | YA | 73 | 5,58 |
| WB09_3 | reproducer (HTTP) (15,5 s) | 9,89 | 9,36 | 10,20 | 9,90 | 9,87 | 10,20 | YA | 63.4469 | 63.4469 | YA | 48 | 8,58 |
| FUEL_DISTILLATE | rerun bahan bakar (10,0 s) | 6,08 | 6,07 | 6,08 | 2,55 | 2,54 | 2,81 | YA | 77.3295 | 77.3295 | YA | 1 | 2,70 |
| FUEL_LNG_LEBIH | rerun bahan bakar (10,0 s) | 19,85 | 19,40 | 20,37 | 2,29 | 2,29 | 2,80 | YA | 66.5411 | 66.5411 | YA | 1 | 5,94 |
| FUEL_LNG_REKOMENDASI | rerun bahan bakar (10,0 s) | 5,57 | 5,34 | 5,84 | 3,81 | 3,58 | 4,07 | YA | 66.2242 | 66.2242 | YA | 1 | 1,80 |
| ACT_FFJ_UP | update slot Actual/Fixed Flow (5,0 s) | 5,35 | 5,32 | 5,62 | 5,33 | 5,31 | 5,40 | TIDAK | 64.6309 | 64.6309 | YA | 38 | 8,76 |
| ACT_FFM_UP | update slot Actual/Fixed Flow (5,0 s) | 5,03 | 4,80 | 5,05 | 4,84 | 4,84 | 5,03 | YA | 64.6216 | 64.6216 | YA | 34 | 7,92 |
| ACT_PGN_2SLOT_C | update slot Actual/Fixed Flow (5,0 s) | 3,86 | 3,78 | 4,08 | 3,79 | 3,79 | 3,79 | YA | 64.6987 | 64.6987 | YA | 31 | 5,40 |
| ACT_PGN_DOWN | update slot Actual/Fixed Flow (5,0 s) | 4,28 | 4,26 | 4,29 | 4,30 | 4,29 | 4,36 | YA | 64.5676 | 64.5676 | YA | 25 | 5,88 |
| ACT_PGN_UP | update slot Actual/Fixed Flow (5,0 s) | 4,61 | 4,55 | 4,81 | 4,84 | 4,83 | 4,85 | YA | 64.6801 | 64.6801 | YA | 27 | 7,14 |
| KP72_UP | update slot Actual/Fixed Flow (5,0 s) | 4,78 | 4,55 | 5,12 | 4,81 | 4,79 | 5,09 | YA | 64.6214 | 64.6214 | YA | 31 | 7,68 |
| FUEL_LNG_KURANG | - | 8,45 | 8,45 | 8,97 | 7,19 | 6,68 | 7,45 | - | 63.9333 | 63.9333 | YA | 11 | 10,26 |

## Ringkasan terhadap target V12 (median per rute)

| kategori | rute | lulus | median rute terburuk | target |
|---|---|---|---|---|
| Gas Shortage tervalidasi | 1 | 1/1 | FUEL_SHORTAGE_PGN25 5,87 s | 20,0 s |
| exact berat | 3 | 3/3 | BASE_ACT10_DARI_NOL 30,78 s | 40,0 s |
| kuota basis Actual (commitment berubah) | 3 | 2/3 | QA_pgn_pipe_4 20,17 s | 15,0 s |
| kuota basis PGN 30 (commitment berubah) | 3 | 3/3 | Q_akasia_1 11,75 s | 15,0 s |
| kuota basis PGN 30 (commitment tetap) | 3 | 0/3 | Q_pgn_pipe_2 12,69 s | 10,0 s |
| reproducer (HTTP) | 2 | 2/2 | WB09_3 9,90 s | 15,5 s |
| rerun bahan bakar | 3 | 3/3 | FUEL_LNG_REKOMENDASI 3,81 s | 10,0 s |
| update slot Actual/Fixed Flow | 6 | 5/6 | ACT_FFJ_UP 5,33 s | 5,0 s |
