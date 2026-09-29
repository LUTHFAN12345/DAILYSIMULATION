# Performa V11 — runtime V9 / V10 / V11

Mesin uji V11: PHP 7.4, 4 inti CPU (3 pekerja pembantu); angka V9/V10 berasal dari baseline clean-extract masing-masing (2 inti, 1 pembantu) sehingga hanya indikatif, server multi-backend yang meniru Apache. Setiap versi diukur dari clean-extract ZIP finalnya pada battery yang sama. Waktu = detik sejak klik Run sampai hasil valid pertama / FINAL (HTTP, tanpa CMD/runner). CP = Cost Production USD/MWh FINAL.

## 0. Ringkasan terhadap target runtime V11

| kategori | n | median | maks | target (maks) | lulus |
|---|---|---|---|---|---|
| update Actual / FF / KP72 satu slot | 14 | 7,65 | 8,66 | 5,0 (5,0) | 1/14 — lewat: ACT_PGN_UP 7,63, ACT_PGN_DOWN 7,37, ACT_FFJ_UP 8,66, ACT_FFM_UP 8,38, ACT_FFM_DOWN 8,62, KP72_UP 7,92, KP72_DOWN 8,61, ACT_PGN_2SLOT_C 6,11, ACT_PGN_UP 7,32, ACT_PGN_DOWN 6,95, ACT_PGN_2SLOT_C 5,66, KP72_DOWN 8,20, ACT_FFJ_UP 7,68 |
| update Actual / FF / KP72 satu slot (valid pertama) | 14 | 3,02 | 4,06 | 2,0 (2,0) | 1/14 — lewat: ACT_PGN_UP 3,03, ACT_PGN_DOWN 3,28, ACT_FFJ_UP 4,06, ACT_FFM_UP 2,99, ACT_FFM_DOWN 3,53, KP72_UP 3,06, KP72_DOWN 3,24, ACT_PGN_2SLOT_C 3,02, ACT_PGN_UP 2,98, ACT_PGN_DOWN 2,84, ACT_PGN_2SLOT_C 2,59, KP72_DOWN 2,59, ACT_FFJ_UP 3,60 |
| kuota ±1..4 (basis Actual, termasuk FINAL jangkar baru) | 7 | 28,33 | 34,16 | 10,0 (10,0) | 1/7 — lewat: QA_pgn_pipe_1 29,10, QA_pgn_pipe_2 26,85, QA_pgn_pipe_4 34,16, QA_lng_1 29,07, QA_lng_1 28,33, QA_pgn_pipe_1 28,12 |
| kuota ±1..4 (basis PGN 30) | 9 | 20,70 | 22,53 | 10,0 (10,0) | 0/9 — lewat: Q_pgn_pipe_1 20,86, Q_pgn_pipe_2 16,07, Q_pgn_pipe_4 14,58, Q_pep_1 22,23, Q_pep_2 20,10, Q_lng_1 20,49, Q_akasia_1 22,53, Q_pep_1 21,99, Q_pgn_pipe_1 20,70 |
| exact berat | 6 | 26,52 | 52,42 | 40,0 (40,0) | 4/6 — lewat: Q_pep_kp72_1 50,45, BASE_ACT10 52,42 |
| UI update slot | 9 | 8,35 | 11,19 | 5,0 (5,0) | 0/9 — lewat: PGN_UP 8,77, PGN_DOWN 7,78, FFJ_UP 11,19, FFM_UP 7,98, FFM_DOWN 8,95, KP72_UP 8,84, KP72_DOWN 8,08, PGN_2SLOT 6,22, FFJ_SMALL 8,35 |
| commitment berubah (UI) | 8 | 14,57 | 36,41 | 15,0 (15,0) | 4/8 — lewat: F0_BASE 30,47, F1_STOP_G1_1000 32,32, F2_STOP_G1_SLOTS 36,41, F5_COMBO 21,07 |
| reproducer UI | 1 | 17,30 | 17,30 | 15,5 (15,5) | 0/1 — lewat: WB09 17,30 |
| reproducer UI WB09_3 | 1 | 18,63 | 18,63 | 15,5 (15,5) | 0/1 — lewat: WB09_3 18,63 |
| reproducer CP WB09 (<= V10 x 1,002) | 1 | 64,3644 | 64,3644 | 64,3644 (64,4931) | 1/1 |
| Gas Shortage opsi → FINAL | 1 | 10,61 | 10,61 | 20,0 (20,0) | 1/1 |
| rerun bahan bakar (Gas Shortage opsi) | 8 | 10,83 | 38,11 | 10,0 (10,0) | 2/8 — lewat: PGN 25 T2 10,61, PGN 25 T3 11,04, PGN 25 T5 38,11, PGN 20 T2 10,03, PGN 20 T3 12,52, PGN 20 T5 35,93 |

## 1. Update Simulation Data / kuota dari basis FINAL Actual 11 jam (HTTP, cold)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| ACT_PGN_UP | 18,22 s | 64,6727 | 4,72 s | 64,6587 | 3,03 s | 7,63 s | 64,6801 | 0,0214 | INCREMENTAL |
| ACT_PGN_DOWN | 22,67 s | 64,5606 | 4,12 s | 64,5425 | 3,28 s | 7,37 s | 64,5676 | 0,0251 | INCREMENTAL |
| ACT_FFJ_UP | 11,86 s | 64,6498 | 6,47 s | 64,6303 | 4,06 s | 8,66 s | 64,6309 | 0,0006 | INCREMENTAL |
| ACT_FFJ_DOWN | 25,15 s | 64,7607 | 23,46 s | 64,7607 | - s | 23,93 s | 64,7607 | 0,0000 | EXACT |
| ACT_FFM_UP | 21,15 s | 64,6421 | 4,54 s | 64,6210 | 2,99 s | 8,38 s | 64,6216 | 0,0006 | INCREMENTAL |
| ACT_FFM_DOWN | 21,18 s | 64,6513 | 4,75 s | 64,6209 | 3,53 s | 8,62 s | 64,6214 | 0,0005 | INCREMENTAL |
| KP72_UP | 21,60 s | 64,6419 | 4,49 s | 64,6209 | 3,06 s | 7,92 s | 64,6214 | 0,0005 | INCREMENTAL |
| KP72_DOWN | 20,84 s | 64,6418 | 4,35 s | 64,6208 | 3,24 s | 8,61 s | 64,6214 | 0,0006 | INCREMENTAL |
| ACT_PGN_2SLOT_C | 14,52 s | 64,7007 | 4,64 s | 64,6980 | 3,02 s | 6,11 s | 64,6987 | 0,0007 | INCREMENTAL |
| ACT_PGN_BIG | 19,98 s | 66,0122 | 18,13 s | 66,0122 | - s | 22,93 s | 66,0122 | 0,0000 | EXACT |
| QA_pgn_pipe_1 | 68,15 s | 63,8279 | 24,71 s | 63,8487 | 24,77 s | 29,10 s | 63,8609 | 0,0122 | INCREMENTAL |
| QA_pgn_pipe_-1 | 31,00 s | 64,7333 | 20,08 s | 64,7333 | - s | 30,34 s | 64,7333 | 0,0000 | EXACT |
| QA_pgn_pipe_2 | 56,74 s | 62,8401 | 30,15 s | 62,8401 | 21,25 s | 26,85 s | 62,9444 | 0,1043 | INCREMENTAL |
| QA_pgn_pipe_-2 | 31,25 s | 64,7333 | 21,15 s | 64,7333 | - s | 28,27 s | 64,7333 | 0,0000 | EXACT |
| QA_pgn_pipe_4 | 34,19 s | 60,2738 | 24,12 s | 60,2768 | 20,17 s | 34,16 s | 60,2770 | 0,0002 | INCREMENTAL |
| QA_pgn_pipe_-4 | 31,21 s | 64,7333 | 20,71 s | 64,7333 | - s | 28,80 s | 64,7333 | 0,0000 | EXACT |
| QA_pep_1 | 0,81 s | 66,0665 | 1,02 s | 66,0665 | - s | 1,31 s | 66,0665 | 0,0000 | DELTA_CERTIFICATE |
| QA_pep_-1 | 1,29 s | 65,6235 | 1,37 s | 65,6235 | - s | 2,36 s | 65,6235 | 0,0000 | DELTA_CERTIFICATE |
| QA_pep_2 | 0,48 s | 65,1795 | 0,49 s | 65,1795 | - s | 1,01 s | 65,1795 | 0,0000 | DELTA_CERTIFICATE |
| QA_pep_-2 | 1,25 s | 65,3991 | 1,28 s | 65,3991 | - s | 2,13 s | 65,3991 | 0,0000 | DELTA_CERTIFICATE |
| QA_lng_1 | 67,84 s | 64,1402 | 25,67 s | 64,1611 | 24,46 s | 29,07 s | 64,1733 | 0,0122 | INCREMENTAL |
| QA_lng_-1 | 31,47 s | 64,4127 | 20,63 s | 64,4127 | - s | 28,87 s | 64,4127 | 0,0000 | EXACT |
| QA_pep_kp72_1 | 1,28 s | 65,8037 | 1,64 s | 65,8037 | - s | 2,34 s | 65,8037 | 0,0000 | DELTA_CERTIFICATE |
| QA_pep_kp72_-1 | 1,28 s | 65,9826 | 1,56 s | 65,9826 | - s | 2,11 s | 65,9826 | 0,0000 | DELTA_CERTIFICATE |
| QA_akasia_1 | 0,78 s | 66,3111 | 0,79 s | 66,3111 | - s | 1,29 s | 66,3111 | 0,0000 | DELTA_CERTIFICATE |
| QA_akasia_-1 | 1,27 s | 65,3788 | 1,36 s | 65,3788 | - s | 1,85 s | 65,3788 | 0,0000 | DELTA_CERTIFICATE |
| PGN29_PEP32_ACT | 0,77 s | 66,2908 | 0,73 s | 66,2908 | - s | 1,25 s | 66,2908 | 0,0000 | DELTA_CERTIFICATE |

## 2. Transisi berantai (HTTP, warm)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| ACT_PGN_UP | 18,06 s | 64,6727 | 4,64 s | 64,6587 | 2,98 s | 7,32 s | 64,6801 | 0,0214 | INCREMENTAL |
| ACT_PGN_DOWN | 22,10 s | 64,5606 | 3,31 s | 64,5425 | 2,84 s | 6,95 s | 64,5676 | 0,0251 | INCREMENTAL |
| ACT_PGN_UP | 0,03 s | 64,6727 | 0,03 s | 64,6587 | 0,03 s | 0,05 s | 64,6801 | 0,0214 | INCREMENTAL |
| ACT_PGN_2SLOT_C | 13,74 s | 64,7007 | 3,83 s | 64,6980 | 2,59 s | 5,66 s | 64,6987 | 0,0007 | INCREMENTAL |
| KP72_DOWN | 21,03 s | 64,6418 | 3,81 s | 64,6208 | 2,59 s | 8,20 s | 64,6214 | 0,0006 | INCREMENTAL |
| ACT_FFJ_UP | 10,98 s | 64,6498 | 4,83 s | 64,6303 | 3,60 s | 7,68 s | 64,6309 | 0,0006 | INCREMENTAL |
| QA_lng_1 | 67,54 s | 64,1402 | 23,64 s | 64,1611 | 24,24 s | 28,33 s | 64,1733 | 0,0122 | INCREMENTAL |
| QA_pep_1 | 0,50 s | 66,0665 | 0,77 s | 66,0665 | - s | 0,78 s | 66,0665 | 0,0000 | DELTA_CERTIFICATE |
| QA_pgn_pipe_1 | 67,80 s | 63,8279 | 26,30 s | 63,8487 | 24,29 s | 28,12 s | 63,8609 | 0,0122 | INCREMENTAL |
| QA_pep_-1 | 1,02 s | 65,6235 | 1,02 s | 65,6235 | - s | 1,55 s | 65,6235 | 0,0000 | DELTA_CERTIFICATE |
| QA_pgn_pipe_1 | 0,03 s | 63,8279 | 0,03 s | 63,8487 | 0,03 s | 0,06 s | 63,8609 | 0,0122 | INCREMENTAL |

## 3. Kuota dari basis PGN 30 tanpa Actual (HTTP)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| Q_pgn_pipe_1 | 38,41 s | 64,5450 | 17,57 s | 64,5450 | 7,86 s | 20,86 s | 64,5424 | -0,0026 | EXACT |
| Q_pgn_pipe_-1 | 14,75 s | 64,2315 | 11,24 s | 64,2315 | - s | 14,06 s | 64,2315 | 0,0000 | EXACT |
| Q_pgn_pipe_2 | 15,19 s | 64,4029 | 17,54 s | 64,4029 | 1,51 s | 16,07 s | 64,4029 | 0,0000 | EXACT |
| Q_pgn_pipe_-2 | 14,90 s | 63,5829 | 11,11 s | 63,5829 | - s | 13,69 s | 63,5829 | 0,0000 | EXACT |
| Q_pgn_pipe_4 | 12,01 s | 64,1256 | 12,83 s | 64,1256 | 2,59 s | 14,58 s | 64,1256 | 0,0000 | EXACT |
| Q_pgn_pipe_-4 | 14,66 s | 62,2857 | 10,89 s | 62,2857 | - s | 13,87 s | 62,2857 | 0,0000 | EXACT |
| Q_pep_1 | 25,88 s | 64,4258 | 18,46 s | 64,4258 | 6,19 s | 22,23 s | 64,4253 | -0,0005 | EXACT |
| Q_pep_-1 | 14,33 s | 64,2762 | 11,13 s | 64,2762 | - s | 13,44 s | 64,2762 | 0,0000 | EXACT |
| Q_pep_2 | 15,82 s | 64,1989 | 15,32 s | 64,1989 | 2,03 s | 20,10 s | 64,1989 | 0,0000 | EXACT |
| Q_pep_-2 | 14,13 s | 63,6723 | 10,53 s | 63,6723 | - s | 14,61 s | 63,6723 | 0,0000 | EXACT |
| Q_lng_1 | 37,66 s | 64,8608 | 16,69 s | 64,8608 | 7,50 s | 20,49 s | 64,8581 | -0,0027 | EXACT |
| Q_lng_-1 | 14,63 s | 63,9109 | 11,00 s | 63,9109 | - s | 14,21 s | 63,9109 | 0,0000 | EXACT |
| Q_pep_kp72_1 | 36,09 s | 64,6324 | 34,95 s | 64,6324 | 4,12 s | 50,45 s | 64,6310 | -0,0014 | EXACT |
| Q_pep_kp72_-1 | 24,96 s | 64,7878 | 23,18 s | 64,7878 | 4,41 s | 29,97 s | 64,7860 | -0,0018 | EXACT |
| Q_akasia_1 | 25,05 s | 64,6666 | 17,28 s | 64,6666 | 5,73 s | 22,53 s | 64,6661 | -0,0005 | EXACT |
| Q_akasia_-1 | 14,28 s | 64,0315 | 10,98 s | 64,0315 | - s | 14,14 s | 64,0315 | 0,0000 | EXACT |
| PGN29_PEP32 | 40,00 s | 64,3188 | 24,66 s | 64,3188 | 7,50 s | 22,06 s | 64,3188 | 0,0000 | EXACT |

## 4. Kuota berantai (HTTP, warm)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| PGN29_PEP32 | 39,95 s | 64,3188 | 25,90 s | 64,3188 | 8,00 s | 23,07 s | 64,3188 | 0,0000 | EXACT |
| BASE_PGN30 | 15,71 s | 64,6658 | 11,39 s | 64,6658 | 0,25 s | 15,75 s | 64,6644 | -0,0014 | EXACT |
| PGN29_PEP32_LAGI | 0,03 s | 64,3188 | 0,02 s | 64,3188 | 0,02 s | 0,04 s | 64,3188 | 0,0000 | EXACT |
| Q_pep_1 | 24,41 s | 64,4258 | 18,16 s | 64,4258 | 5,68 s | 21,99 s | 64,4253 | -0,0005 | EXACT |
| Q_pgn_pipe_1 | 37,36 s | 64,5450 | 16,09 s | 64,5450 | 6,97 s | 20,70 s | 64,5424 | -0,0026 | EXACT |

## 5. Exact penuh state Actual dari nol (HTTP)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| BASE_ACT10 | 57,35 s | 64,6418 | 42,80 s | 64,6208 | 43,78 s | 52,42 s | 64,6214 | 0,0006 | INCREMENTAL |

## 6. Event berat: status unit, Mandatory Stop, Skip/Fix Load, Change Over (HTTP, warm)

| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |
|---|---|---|---|---|---|---|---|---|---|
| BASE PGN30 | 25,51 s | 64,6658 | 25,02 s | 64,6658 | 3,54 s | 28,73 s | 64,6644 | -0,0014 | EXACT |
| STOP_RUNNING_G1_FROM_10:00 | 30,04 s | 64,8331 | 21,19 s | 64,8331 | 0,51 s | 20,75 s | 64,8331 | 0,0000 | EXACT |
| FORCE_RUN_G5_AT_10:00 | 2,81 s | 64,7788 | 3,62 s | 64,7788 | 3,41 s | 5,71 s | 64,7788 | 0,0000 | EXACT |
| MANDATORY_STOP_G1 | 30,25 s | 64,4990 | 18,48 s | 64,4990 | 19,03 s | 23,11 s | 64,4990 | 0,0000 | EXACT |
| SKIP_LOAD_G9 | 14,75 s | 64,8307 | 13,23 s | 64,8307 | 2,61 s | 19,93 s | 64,8307 | 0,0000 | EXACT |
| FIX_LOAD_G9 | 14,82 s | 64,5169 | 8,23 s | 64,5169 | - s | 11,38 s | 64,5169 | 0,0000 | EXACT |
| CO_B2_G1_TO_B1_G3_SIM | 0,77 s | 64,9050 | 0,78 s | 64,9050 | - s | 1,29 s | 64,9050 | 0,0000 | EXACT |
| CO_B2_G1_TO_B1_G4_MANUAL | 2,03 s | 64,3415 | 2,05 s | 64,3415 | - s | 2,82 s | 64,3415 | 0,0000 | EXACT |
| CO_B2_G1_TO_B1_G6_SIM | 0,76 s | 64,9193 | 0,77 s | 64,9193 | - s | 1,04 s | 64,9193 | 0,0000 | EXACT |

## 7. UI browser: perubahan Simulation Data per slot

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| BASE | 24,29 s | 64,6418 | 5,52 s | 64,6208 | 4,09 s | 10,38 s | 64,6214 | FINAL |
| PGN_UP | 19,80 s | 64,6727 | 4,79 s | 64,6587 | 3,44 s | 8,77 s | 64,6801 | FINAL |
| PGN_DOWN | 22,94 s | 64,5606 | 3,96 s | 64,5425 | 3,04 s | 7,78 s | 64,5676 | FINAL |
| FFJ_UP | 13,88 s | 64,6498 | 5,92 s | 64,6303 | 4,37 s | 11,19 s | 64,6309 | FINAL |
| FFJ_DOWN | 25,58 s | 64,6498 | 23,62 s | 64,6303 | - s | 27,70 s | 64,6309 | NOT_FINAL |
| FFM_UP | 21,48 s | 64,6421 | 4,61 s | 64,6210 | 3,32 s | 7,98 s | 64,6216 | FINAL |
| FFM_DOWN | 22,26 s | 64,6513 | 4,63 s | 64,6209 | 3,33 s | 8,95 s | 64,6214 | FINAL |
| KP72_UP | 23,33 s | 64,6419 | 4,75 s | 64,6209 | 3,49 s | 8,84 s | 64,6214 | FINAL |
| KP72_DOWN | 22,19 s | 64,6418 | 4,65 s | 64,6208 | 2,78 s | 8,08 s | 64,6214 | FINAL |
| PGN_2SLOT | 16,05 s | 64,7007 | 4,59 s | 64,6980 | 3,04 s | 6,22 s | 64,6987 | FINAL |
| FFJ_SMALL | 22,36 s | 64,6439 | 4,83 s | 64,6210 | 3,24 s | 8,35 s | 64,6214 | FINAL |
| PGN_BIG | 21,60 s | 64,6439 | 18,06 s | 64,6210 | - s | 28,38 s | 64,6214 | GAS_SHORTAGE_POPUP |

## 8. UI browser: perubahan kuota — basis Actual 11 jam

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| BASIS | 24,21 s | 64,6418 | 5,78 s | 64,6208 | 3,79 s | 9,46 s | 64,6214 | FINAL |
| pgn_pipe:1 | 69,39 s | 63,8279 | 26,31 s | 63,8487 | 24,36 s | 28,70 s | 63,8609 | FINAL |
| pgn_pipe:-1 | 31,80 s | 64,7333 | 21,00 s | 64,7333 | - s | 26,97 s | 64,7333 | GAS_SHORTAGE_POPUP |
| pgn_pipe:2 | 61,37 s | 62,8401 | 32,62 s | 62,8401 | 21,05 s | 26,81 s | 62,9444 | FINAL |
| pgn_pipe:-2 | 31,81 s | 64,7333 | 22,87 s | 64,7333 | - s | 27,28 s | 64,7333 | GAS_SHORTAGE_POPUP |
| pgn_pipe:4 | 35,94 s | 60,2738 | 24,93 s | 60,2768 | 20,79 s | 35,74 s | 60,2770 | FINAL |
| pgn_pipe:-4 | 31,79 s | 64,7333 | 22,54 s | 64,7333 | - s | 27,56 s | 64,7333 | GAS_SHORTAGE_POPUP |
| pep:1 | 0,65 s | 66,0665 | 0,64 s | 66,0665 | - s | 0,98 s | 66,0665 | KEPUTUSAN_KUOTA_OPERATOR |
| pep:-1 | 1,16 s | 65,6235 | 1,48 s | 65,6235 | - s | 1,58 s | 65,6235 | KEPUTUSAN_KUOTA_OPERATOR |
| pep:2 | 0,44 s | 65,1795 | 0,33 s | 65,1795 | - s | 0,44 s | 65,1795 | KEPUTUSAN_KUOTA_OPERATOR |
| pep:-2 | 1,15 s | 65,3991 | 1,19 s | 65,3991 | - s | 1,70 s | 65,3991 | KEPUTUSAN_KUOTA_OPERATOR |
| lng:1 | 69,57 s | 64,1402 | 32,04 s | 64,1611 | 23,70 s | 27,45 s | 64,1733 | FINAL |
| lng:-1 | 31,81 s | 64,4127 | 23,91 s | 64,4127 | - s | 27,81 s | 64,4127 | GAS_SHORTAGE_POPUP |
| pep_kp72:1 | 1,17 s | 65,8037 | 1,49 s | 65,8037 | - s | 1,38 s | 65,8037 | KEPUTUSAN_KUOTA_OPERATOR |
| pep_kp72:-1 | 1,16 s | 65,9826 | 1,50 s | 65,9826 | - s | 1,59 s | 65,9826 | KEPUTUSAN_KUOTA_OPERATOR |
| akasia:1 | 0,65 s | 66,3111 | 0,65 s | 66,3111 | - s | 0,77 s | 66,3111 | KEPUTUSAN_KUOTA_OPERATOR |
| akasia:-1 | 1,15 s | 65,3788 | 1,47 s | 65,3788 | - s | 1,69 s | 65,3788 | KEPUTUSAN_KUOTA_OPERATOR |
| pgn_pipe:-1+pep:2 | 0,65 s | 66,2908 | 0,96 s | 66,2908 | - s | 0,75 s | 66,2908 | KEPUTUSAN_KUOTA_OPERATOR |

## 9. UI browser: perubahan kuota — basis PGN 30 tanpa Actual

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| BASIS | 28,06 s | 64,6658 | 26,09 s | 64,6658 | 4,00 s | 29,53 s | 64,6644 | FINAL |
| pgn_pipe:1 | 37,96 s | 64,5450 | 18,41 s | 64,5450 | 8,30 s | 20,27 s | 64,5424 | FINAL |
| pgn_pipe:-1 | 14,63 s | 64,2315 | 11,58 s | 64,2315 | - s | 13,54 s | 64,2315 | GAS_SHORTAGE_POPUP |
| pgn_pipe:2 | 17,12 s | 64,4029 | 18,89 s | 64,4029 | 0,97 s | 17,15 s | 64,4029 | FINAL |
| pgn_pipe:-2 | 14,54 s | 63,5829 | 13,24 s | 63,5829 | - s | 13,17 s | 63,5829 | GAS_SHORTAGE_POPUP |
| pgn_pipe:4 | 12,26 s | 64,1256 | 16,07 s | 64,1256 | 1,86 s | 14,15 s | 64,1256 | FINAL |
| pgn_pipe:-4 | 15,57 s | 62,2857 | 13,25 s | 62,2857 | - s | 13,79 s | 62,2857 | GAS_SHORTAGE_POPUP |
| pep:1 | 26,13 s | 64,4258 | 18,80 s | 64,4258 | 5,78 s | 22,50 s | 64,4253 | FINAL |
| pep:-1 | 14,62 s | 64,2762 | 11,62 s | 64,2762 | - s | 13,11 s | 64,2762 | GAS_SHORTAGE_POPUP |
| pep:2 | 16,93 s | 64,1989 | 16,45 s | 64,1989 | 1,60 s | 18,56 s | 64,1989 | FINAL |
| pep:-2 | 14,61 s | 63,6723 | 10,89 s | 63,6723 | - s | 13,29 s | 63,6723 | GAS_SHORTAGE_POPUP |
| lng:1 | 37,88 s | 64,8608 | 19,15 s | 64,8608 | 7,98 s | 20,17 s | 64,8581 | FINAL |
| lng:-1 | 14,51 s | 63,9109 | 12,21 s | 63,9109 | - s | 14,05 s | 63,9109 | GAS_SHORTAGE_POPUP |
| pep_kp72:1 | 37,05 s | 64,6324 | 37,25 s | 64,6324 | 3,78 s | 46,48 s | 64,6310 | FINAL |
| pep_kp72:-1 | 25,97 s | 64,7878 | 24,20 s | 64,7878 | 3,99 s | 28,23 s | 64,7860 | FINAL |
| akasia:1 | 25,90 s | 64,6666 | 19,60 s | 64,6666 | 6,09 s | 21,93 s | 64,6661 | FINAL |
| akasia:-1 | 14,51 s | 64,0315 | 11,65 s | 64,0315 | - s | 14,18 s | 64,0315 | GAS_SHORTAGE_POPUP |
| pgn_pipe:-1+pep:2 | 41,07 s | 64,3188 | 27,81 s | 64,3188 | 8,66 s | 23,73 s | 64,3188 | FINAL |

## 10. UI browser: forced stop/start dan Change Over (commitment berubah)

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| F0_BASE | 27,98 s | 64,6658 | 28,01 s | 64,6658 | - s | 30,47 s | 64,6644 | FINAL |
| F1_STOP_G1_1000 | 30,06 s | 64,1487 | 33,18 s | 64,1378 | - s | 32,32 s | 64,1378 | FINAL |
| F2_STOP_G1_SLOTS | 29,94 s | 64,1487 | 33,58 s | 64,1378 | - s | 36,41 s | 64,1378 | FINAL |
| F3_START_G5_1000 | 3,53 s | 64,7788 | 3,97 s | 64,7788 | - s | 4,89 s | 64,7788 | FINAL |
| F4_RUN_G5_SLOTS | 2,56 s | 64,6520 | 3,31 s | 64,6520 | - s | 4,13 s | 64,6520 | FINAL |
| F4X_RUN_G5_TOO_SHORT | 12,86 s | - | 8,92 s | - | - s | 10,80 s | - | NOT_FINAL |
| F5_COMBO | 12,83 s | 64,7864 | 16,29 s | 64,7864 | - s | 21,07 s | 64,7387 | FINAL |
| C1_LEGAL_SIM | 1,63 s | 65,0245 | 1,81 s | 65,0245 | - s | 1,85 s | 65,0245 | FINAL |
| C2_LEGAL_OVERLAP | 5,32 s | 64,8889 | 7,04 s | 64,8889 | - s | 8,07 s | 64,8889 | FINAL |
| C3_ILLEGAL_EXPORT | 3,69 s | - | 3,70 s | - | - s | 5,14 s | - | NOT_FINAL |
| C4_NEED_COMMIT | 0,96 s | - | 1,27 s | - | - s | 1,16 s | - | GAS_SHORTAGE_POPUP |
| C5_ILLEGAL_REQUIRED | 2,79 s | - | 2,79 s | - | - s | 2,79 s | - | NOT_FINAL |

## 11. UI browser: reproducer Daily_Plan_09_Jul_26_Baru

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| WB09 | 17,76 s | 64,3644 | 17,20 s | 64,3644 | - s | 17,30 s | 64,3644 | FINAL |
| WB09_KP72 | 16,82 s | 64,1989 | 15,91 s | 64,1989 | - s | 16,67 s | 64,1989 | FINAL |
| WB09_ULANG | 0,82 s | 64,3644 | 0,82 s | 64,3644 | - s | 0,83 s | 64,3644 | FINAL |

## 11b. UI browser: reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3)

| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |
|---|---|---|---|---|---|---|---|---|
| WB09_3 | - s | - | - s | - | - s | 18,63 s | 63,4469 | FINAL |
| METRIK_W2 | - s | - | - s | - | - s | - s | - | None |
| WB09_3_KP72_0 | - s | - | - s | - | - s | 15,16 s | 63,5889 | FINAL |
| WB09_3_ULANG | - s | - | - s | - | - s | 0,66 s | 63,4469 | FINAL |

## 12. Gas Shortage (UI)

| PGN | versi | popup | angka tervalidasi | opsi → FINAL |
|---|---|---|---|---|
| 25 | V9 | 14,73 s | 15,74 s | 6,43 s |
| 25 | V10 | 13,52 s | 14,29 s | 8,24 s |
| 25 | V11 | 14,05 s | 15,12 s | 10,61 s |
| 20 | V9 | - s | - s | - s |
| 20 | V10 | - s | - s | - s |
| 20 | V11 | - s | - s | - s |

