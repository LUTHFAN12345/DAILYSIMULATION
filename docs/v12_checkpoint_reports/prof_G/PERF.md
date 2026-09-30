# Performa V12 — median / minimum / maksimum tiga run (V11 vs V12, VM sama, bergantian)

Mesin: PHP 7.4, 4 inti CPU, 3 pekerja pembantu, server multi-backend meniru Apache; tiap run cold dari snapshot yang sama (basis Actual: FINAL jangkar PGN 30 + BASE_ACT10; "dari nol": direktori job kosong). Waktu = detik dari request Run sampai FINAL (HTTP). Rantai bahan bakar: satu server warm, urutan popup Gas Shortage PGN 25 -> LNG rekomendasi -> LNG lebih (+2 BBTUD) -> Distillate rekomendasi -> LNG kurang.

| rute | kategori (target) | V11 median | V11 min | V11 maks | V12 median | V12 min | V12 maks | V12 lulus | CP V11 | CP V12 | dispatch identik | core run V12 | idle pembantu V12 (s) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| FUEL_SHORTAGE_PGN25 | Gas Shortage tervalidasi (20,0 s) | 9,87 | 9,66 | 10,45 | 6,02 | 5,85 | 6,05 | YA | 61.6371 | 61.6371 | YA | 13 | 4,98 |
| OPS_F1_STOP_G1_1000 | commitment berubah (perintah stop G1) (15,0 s) | 20,82 | 20,73 | 22,34 | 21,02 | 20,50 | 21,36 | TIDAK | 64.1378 | 64.1378 | YA | 80 | 12,60 |
| OPS_F2_STOP_G1_SLOTS | commitment berubah (perintah stop G1) (15,0 s) | 20,89 | 20,87 | 21,85 | 21,78 | 20,63 | 23,79 | TIDAK | 64.1378 | 64.1378 | YA | 66 | 12,24 |
| BASE_ACT10_DARI_NOL | exact berat (40,0 s) | 35,10 | 34,97 | 35,96 | 27,56 | 26,38 | 27,80 | YA | 64.6214 | 64.6214 | YA | 105 | 13,14 |
| BASE_PGN30_DARI_NOL | exact berat (40,0 s) | 19,04 | 18,83 | 20,43 | 14,79 | 13,24 | 15,02 | YA | 64.6644 | 64.6644 | YA | 78 | 6,84 |
| Q_pep_kp72_1 | exact berat (40,0 s) | 31,53 | 28,32 | 32,19 | 17,50 | 16,88 | 19,44 | YA | 64.631 | 64.631 | YA | 108 | 8,46 |
| QA_lng_1 | kuota basis Actual (commitment berubah) (15,0 s) | 17,69 | 17,29 | 19,69 | 14,99 | 14,30 | 16,37 | YA | 64.1733 | 64.1733 | YA | 31 | 6,48 |
| QA_pgn_pipe_1 | kuota basis Actual (commitment berubah) (15,0 s) | 18,39 | 18,20 | 18,88 | 15,01 | 14,31 | 15,42 | TIDAK | 63.8609 | 63.8609 | YA | 30 | 6,78 |
| QA_pgn_pipe_4 | kuota basis Actual (commitment berubah) (15,0 s) | 22,75 | 21,19 | 23,35 | 18,55 | 18,52 | 19,43 | TIDAK | 60.277 | 60.277 | YA | 51 | 22,68 |
| Q_akasia_1 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 14,97 | 14,28 | 15,47 | 13,30 | 13,20 | 14,24 | YA | 64.6661 | 64.6661 | YA | 77 | 7,56 |
| Q_pep_1 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 14,48 | 14,23 | 15,48 | 12,29 | 12,21 | 12,94 | YA | 64.4253 | 64.4253 | YA | 76 | 7,20 |
| Q_pgn_pipe_4 | kuota basis PGN 30 (commitment berubah) (15,0 s) | 10,41 | 9,55 | 11,00 | 10,41 | 10,33 | 11,22 | YA | 64.1256 | 64.1256 | YA | 57 | 11,22 |
| Q_lng_1 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 13,21 | 12,81 | 14,14 | 12,29 | 11,24 | 12,67 | TIDAK | 64.8581 | 64.8581 | YA | 57 | 4,02 |
| Q_pgn_pipe_1 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 13,11 | 12,93 | 13,63 | 11,14 | 10,82 | 12,53 | TIDAK | 64.5424 | 64.5424 | YA | 41 | 4,08 |
| Q_pgn_pipe_2 | kuota basis PGN 30 (commitment tetap) (10,0 s) | 13,96 | 10,63 | 14,04 | 14,98 | 12,00 | 15,84 | TIDAK | 64.4029 | 64.4029 | YA | 51 | 2,40 |
| WB09 | reproducer (HTTP) (15,5 s) | 12,84 | 9,74 | 13,83 | 12,18 | 11,44 | 12,47 | YA | 64.3644 | 64.3644 | YA | 80 | 4,68 |
| WB09_3 | reproducer (HTTP) (15,5 s) | 11,21 | 10,70 | 11,21 | 11,44 | 11,03 | 12,23 | YA | 63.4469 | 63.4469 | YA | 50 | 8,28 |
| FUEL_DISTILLATE | rerun bahan bakar (10,0 s) | 6,56 | 5,94 | 7,09 | 2,74 | 2,59 | 2,89 | YA | 77.3295 | 77.3295 | YA | 1 | 2,76 |
| FUEL_LNG_LEBIH | rerun bahan bakar (10,0 s) | 22,66 | 22,48 | 23,57 | 2,44 | 2,43 | 2,60 | YA | 66.5411 | 66.5411 | YA | 1 | 5,76 |
| FUEL_LNG_REKOMENDASI | rerun bahan bakar (10,0 s) | 6,43 | 6,12 | 8,08 | 4,00 | 3,69 | 4,15 | YA | 66.2242 | 66.2242 | YA | 1 | 1,38 |
| ACT_FFJ_UP | update slot Actual/Fixed Flow (5,0 s) | 5,60 | 5,59 | 5,78 | 5,28 | 5,21 | 5,33 | TIDAK | 64.6309 | 64.6309 | YA | 35 | 6,90 |
| ACT_FFM_UP | update slot Actual/Fixed Flow (5,0 s) | 5,42 | 5,22 | 5,43 | 5,31 | 4,86 | 5,59 | TIDAK | 64.6216 | 64.6216 | YA | 30 | 6,96 |
| ACT_PGN_2SLOT_C | update slot Actual/Fixed Flow (5,0 s) | 3,86 | 3,85 | 3,90 | 4,11 | 3,94 | 4,27 | YA | 64.6987 | 64.6987 | YA | 28 | 4,92 |
| ACT_PGN_DOWN | update slot Actual/Fixed Flow (5,0 s) | 4,55 | 4,34 | 4,70 | 4,79 | 4,37 | 5,42 | YA | 64.5676 | 64.5676 | YA | 25 | 5,04 |
| ACT_PGN_UP | update slot Actual/Fixed Flow (5,0 s) | 4,82 | 4,81 | 5,27 | 4,72 | 4,63 | 4,82 | YA | 64.6801 | 64.6801 | YA | 23 | 5,40 |
| KP72_UP | update slot Actual/Fixed Flow (5,0 s) | 4,93 | 4,80 | 5,13 | 4,82 | 4,69 | 4,89 | YA | 64.6214 | 64.6214 | YA | 33 | 6,12 |
| FUEL_LNG_KURANG | - | 9,83 | 8,89 | 9,85 | 7,36 | 6,71 | 7,69 | - | 63.9333 | 63.9333 | YA | 12 | 9,60 |

## Profil per rute V12 (run dengan waktu median)

Kolom: kandidat diperiksa/valid (counter satu sumber), Tier 1 dipangkas (review + rute cepat), node keluarga (dihitung/dipakai ulang), cache hit (core run terisolasi / evaluasi beku / tersusun / kolam kandidat), row kotor, percobaan supplier (pencarian), idle pembantu, jalur kritis (penanda tahap job, detik).

| rute | median (s) | diperiksa/valid | Tier 1 dipangkas | node keluarga | cache hit iso/beku/tersusun/kolam | row kotor | supplier (percobaan/pencarian) | idle pembantu (s) | jalur kritis |
|---|---|---|---|---|---|---|---|---|---|
| FUEL_SHORTAGE_PGN25 | 6,02 | 9/0 | 0 | 9 (0/9) | 15/0/0/16 | 0 | 8/8 | 4,98 | baseline core run 1,0; decommit screening 3,8; export min phase b d 5,5; pipeline end 5,5; v12 keluarga selesai 5,5; v12 review selesai 5,5; simulasi selesai mem 5,5 |
| OPS_F1_STOP_G1_1000 | 21,02 | 70/6 | 10 | 25 (5/20) | 10/0/1/20 | 0 | 55/12 | 12,18 | baseline core run 0,6; decommit screening 3,1; pipeline end 3,1; v12 keluarga selesai 9,6; v12 review selesai 20,4; simulasi selesai mem 20,5 |
| OPS_F2_STOP_G1_SLOTS | 21,78 | 69/6 | 10 | 25 (4/21) | 11/0/0/21 | 0 | 61/13 | 12,24 | baseline core run 0,9; decommit screening 4,1; pipeline end 4,1; v12 keluarga selesai 11,1; v12 review selesai 21,3; simulasi selesai mem 21,3 |
| BASE_ACT10_DARI_NOL | 27,56 | 50/16 | 5 | 13 (-/-) | 6/0/10/30 | 22 | 178/11 | 11,82 | v9 jangkar kanonik e 0,0; baseline core run 1,1; decommit screening 6,6; pipeline end 6,6; v12 keluarga selesai 10,5; v12 review selesai 15,4; v9 jangkar exact sel 15,4; v9 jangkar review se 15,4; v10 pustaka jangkar 15,4; v10 tier2b core run 22,7; v10 hasil valid pert 25,8; simulasi selesai mem 27,1 |
| BASE_PGN30_DARI_NOL | 14,79 | 24/5 | 4 | 9 (0/9) | 6/0/1/14 | 0 | 68/6 | 8,10 | baseline core run 1,1; decommit screening 6,0; pipeline end 6,0; v12 keluarga selesai 9,2; v12 review selesai 14,4; simulasi selesai mem 14,4 |
| Q_pep_kp72_1 | 17,50 | 25/5 | 4 | 9 (0/9) | 14/0/1/22 | 0 | 91/9 | 9,96 | baseline core run 1,1; decommit screening 6,4; pipeline end 12,0; v12 keluarga selesai 12,0; v12 review selesai 17,0; simulasi selesai mem 17,0 |
| QA_lng_1 | 14,99 | 51/23 | 0 | 23 (-/-) | 7/1/0/25 | 22 | 48/9 | 5,94 | v9 jangkar kanonik e 0,0; baseline core run 1,1; decommit screening 6,1; pipeline end 6,1; v12 keluarga selesai 8,2; v12 review selesai 11,7; v9 jangkar exact sel 11,7; v9 jangkar review se 11,7; v10 pustaka jangkar 11,7; v10 tier2b core run 13,0; v10 hasil valid pert 14,4; simulasi selesai mem 14,6 |
| QA_pgn_pipe_1 | 15,01 | 51/23 | 0 | 23 (-/-) | 7/1/0/25 | 22 | 46/8 | 6,78 | v9 jangkar kanonik e 0,0; baseline core run 1,3; decommit screening 6,0; pipeline end 6,0; v12 keluarga selesai 8,2; v12 review selesai 11,4; v9 jangkar exact sel 11,4; v9 jangkar review se 11,4; v10 pustaka jangkar 11,5; v10 tier2b core run 12,9; v10 hasil valid pert 14,3; simulasi selesai mem 14,6 |
| QA_pgn_pipe_4 | 18,55 | 73/24 | 5 | 23 (-/-) | 0/3/0/24 | 22 | 80/12 | 22,26 | v9 jangkar kanonik e 0,0; baseline core run 1,9; decommit screening 4,2; pipeline end 4,2; v12 keluarga selesai 5,4; v12 review selesai 10,7; v9 jangkar exact sel 10,7; v9 jangkar review se 10,7; v10 pustaka jangkar 10,7; v10 tier2b core run 12,7; v10 hasil valid pert 13,9; simulasi selesai mem 18,1 |
| Q_akasia_1 | 13,30 | 30/9 | 8 | 9 (0/9) | 7/0/0/17 | 0 | 62/9 | 7,26 | baseline core run 1,4; decommit screening 6,7; pipeline end 6,7; v12 keluarga selesai 7,5; v12 review selesai 12,8; simulasi selesai mem 12,8 |
| Q_pep_1 | 12,29 | 30/9 | 8 | 9 (0/9) | 7/0/0/17 | 0 | 62/9 | 7,20 | baseline core run 1,1; decommit screening 6,5; pipeline end 6,5; v12 keluarga selesai 7,4; v12 review selesai 11,7; simulasi selesai mem 11,8 |
| Q_pgn_pipe_4 | 10,41 | 28/13 | 8 | 7 (0/7) | 1/3/0/9 | 0 | 41/8 | 11,40 | baseline core run 1,8; decommit screening 4,5; pipeline end 4,5; v12 keluarga selesai 5,2; v12 review selesai 10,0; simulasi selesai mem 10,0 |
| Q_lng_1 | 12,29 | 25/7 | 4 | 9 (0/9) | 7/1/0/12 | 0 | 46/6 | 4,02 | baseline core run 1,3; decommit screening 6,9; pipeline end 6,9; v12 keluarga selesai 8,7; v12 review selesai 11,8; simulasi selesai mem 11,8 |
| Q_pgn_pipe_1 | 11,14 | 25/7 | 4 | 9 (1/8) | 7/1/0/12 | 0 | 32/6 | 2,88 | baseline core run 1,1; decommit screening 5,4; pipeline end 5,4; v12 keluarga selesai 7,5; v12 review selesai 10,6; simulasi selesai mem 10,6 |
| Q_pgn_pipe_2 | 14,98 | 22/12 | 4 | 7 (0/7) | 0/0/0/5 | 0 | 36/5 | 2,40 | baseline core run 0,5; decommit screening 4,7; pipeline end 4,7; v12 keluarga selesai 12,0; v12 review selesai 14,5; simulasi selesai mem 14,5 |
| WB09 | 12,18 | 25/11 | 6 | 7 (2/5) | 0/1/0/6 | 0 | 61/7 | 4,68 | baseline core run 1,9; decommit screening 5,0; pipeline end 5,0; v12 keluarga selesai 8,9; v12 review selesai 11,7; simulasi selesai mem 11,8 |
| WB09_3 | 11,44 | 28/13 | 6 | 7 (0/7) | 0/6/0/6 | 0 | 31/5 | 8,28 | baseline core run 2,4; decommit screening 4,5; pipeline end 4,6; v12 keluarga selesai 6,1; v12 review selesai 10,9; simulasi selesai mem 10,9 |
| FUEL_DISTILLATE | 2,74 | 31/3 | 4 | 30 (10/20) | 0/0/1/3 | 0 | 10/10 | 2,76 | v7 rerun bahan bakar 0,0; simulasi selesai mem 2,6 |
| FUEL_LNG_LEBIH | 2,44 | 12/3 | 6 | - (-/-) | 0/0/0/4 | 0 | 21/3 | 5,76 | v7 rerun bahan bakar 0,0; simulasi selesai mem 2,3 |
| FUEL_LNG_REKOMENDASI | 4,00 | 17/4 | 6 | 9 (2/7) | 0/0/1/8 | 0 | 5/5 | 1,32 | v7 rerun bahan bakar 0,0; simulasi selesai mem 3,8 |
| ACT_FFJ_UP | 5,28 | 64/15 | 5 | 13 (-/-) | 0/3/9/2 | 22 | 22/1 | 7,14 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,3; v10 hasil valid pert 3,2; simulasi selesai mem 4,8 |
| ACT_FFM_UP | 5,31 | 50/16 | 5 | 13 (-/-) | 0/0/9/2 | 22 | 22/1 | 6,96 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,3; v10 hasil valid pert 3,4; simulasi selesai mem 4,8 |
| ACT_PGN_2SLOT_C | 4,11 | 66/13 | 5 | 13 (-/-) | 0/0/11/2 | 22 | 19/1 | 5,28 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,3; v10 hasil valid pert 2,9; simulasi selesai mem 3,7 |
| ACT_PGN_DOWN | 4,79 | 45/16 | 5 | 13 (-/-) | 0/0/10/2 | 22 | 14/1 | 5,04 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,2; v10 hasil valid pert 3,2; simulasi selesai mem 4,3 |
| ACT_PGN_UP | 4,72 | 54/13 | 3 | 13 (-/-) | 0/0/12/2 | 22 | 14/1 | 5,40 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,3; v10 hasil valid pert 3,3; simulasi selesai mem 4,3 |
| KP72_UP | 4,82 | 48/16 | 5 | 13 (-/-) | 0/0/9/2 | 23 | 22/1 | 6,12 | v10 pustaka jangkar 0,0; v10 tier2b core run 0,3; v10 hasil valid pert 3,3; simulasi selesai mem 4,3 |
| FUEL_LNG_KURANG | 7,36 | 10/0 | 0 | 9 (0/9) | 17/0/0/19 | 0 | 11/11 | 9,78 | v7 rerun bahan bakar 0,0; baseline core run 3,3; decommit screening 5,5; export min phase b d 7,1; pipeline end 7,1; v12 keluarga selesai 7,1; v12 review selesai 7,1; simulasi selesai mem 7,1 |

## Ringkasan terhadap target V12 (median per rute)

| kategori | rute | lulus | median rute terburuk | target |
|---|---|---|---|---|
| Gas Shortage tervalidasi | 1 | 1/1 | FUEL_SHORTAGE_PGN25 6,02 s | 20,0 s |
| commitment berubah (perintah stop G1) | 2 | 0/2 | OPS_F2_STOP_G1_SLOTS 21,78 s | 15,0 s |
| exact berat | 3 | 3/3 | BASE_ACT10_DARI_NOL 27,56 s | 40,0 s |
| kuota basis Actual (commitment berubah) | 3 | 1/3 | QA_pgn_pipe_4 18,55 s | 15,0 s |
| kuota basis PGN 30 (commitment berubah) | 3 | 3/3 | Q_akasia_1 13,30 s | 15,0 s |
| kuota basis PGN 30 (commitment tetap) | 3 | 0/3 | Q_pgn_pipe_2 14,98 s | 10,0 s |
| reproducer (HTTP) | 2 | 2/2 | WB09 12,18 s | 15,5 s |
| rerun bahan bakar | 3 | 3/3 | FUEL_LNG_REKOMENDASI 4,00 s | 10,0 s |
| update slot Actual/Fixed Flow | 6 | 4/6 | ACT_FFM_UP 5,31 s | 5,0 s |
