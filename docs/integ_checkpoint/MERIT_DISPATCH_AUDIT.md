# MERIT DISPATCH — bukti generik per state (12 state nyata, engine V12 checkpoint = source integrasi)

Sumber angka: FINAL engine (job HTTP cold), counterfactual state yang sama dengan redistribusi Unit Priority dimatikan (PP_V8_PRIORITY=0, PP_V6_POLISH=0) sebagai "sebelum redistribusi", run tanpa pembantu, dan rantai warm. Legal headroom = min(Effective Max - MW, allowance ramp) dari V12 Dispatch Merit Audit; STG blok dihitung ulang oleh core run (G3/G4/G6->S1, G1/G2/G5->S2, G8/G9->S3).
**Ringkasan: 12/12 state MERIT PASS** — M01_HEADROOM_BASE PASS, M02_NEAR_MIN_WB09_3 PASS, M03_CONSOLIDATION_WB09 PASS, M04_MIN_RUNTIME_STOP PASS, M05_RESERVE PASS, M06_BUSFLOW PASS, M07_EXPORT_RANGE PASS, M08_DEV_DISPATCH PASS, M09_REQUIRED_G5 PASS, M10_MULTI_START PASS, M11_GAS_WINDOW PASS, M12_ACTUAL_SLOT PASS

## M01_HEADROOM_BASE — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | BASE_PGN30 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G2 > S2 > GE1 |
| 3 | unit running | G1, G2, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 20.0/31.0 MW, headroom 11.00; G2 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 103.3/108.0 MW, headroom 4.67; G9 row 28: 103.3/108.0 MW, headroom 4.67 |
| 5 | energi sebelum redistribusi (MWh; CP 64.6658) | G1 504.4, G2 0.0, G5 110.0, G8 2070.6, G9 2070.6, S2 380.1, S3 2516.7, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.6644) | G1 505.1, G2 110.0, G5 0.0, G8 2072.9, G9 2069.0, S2 380.5, S3 2517.0, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': 0.75, '3': 0.69} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': 0.33, '3': 0.24} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': 1.08, '3': 0.93} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 30 (STG ikut berubah 18; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start |  / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 33.43, 'G9': 33.43, 'G1': 11} / {} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:26-37:@27 valid CP 64.7173; DELAY:G5:26-37:@28 tidak valid: EXPORT |
| 12 | G5 row start | None |
| 13 | G5 row minimum runtime selesai | None (12 row) |
| 14 | G5 row legal stop pertama | None |
| 15 | G5 row benar-benar stop | 38 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 47: status paksa 1, akun MM2100 terpakai penuh 46 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 0, "RESOLVED_BY_STOP": 10, "PASS_WITH_REASON": 34, "FAIL": 0} |
| 18 | CP minimum absolut | 64.6644 (V11_SUSUN:SWAP:G5>G2:26-37) |
| 19 | CP pemenang | 64.6644 |
| 20 | Heat Rate pemenang | 8386.61 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M02_NEAR_MIN_WB09_3 — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | WB09_3 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 29: 31.0/31.0 MW, headroom 0.00; G5 row 29: 20.0/31.0 MW, headroom 11.00; G8 row 29: 108.0/108.0 MW, headroom 0.00; G9 row 29: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 63.7197) | G1 544.5, G5 220.0, G8 2333.6, G9 2333.6, S2 495.9, S3 2712.3, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 63.4469) | G1 541.2, G5 110.0, G8 2435.6, G9 2435.6, S2 384.0, S3 2788.2, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -113.27, '3': 203.9} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -111.86, '3': 75.87} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -225.13, '3': 279.77} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 53 (STG ikut berubah 51; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start | DECOMMIT:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW); DELAY:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW); SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G3 (S1) mati sepanjang interval, G3 prioritas lebih rendah) / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 12.72, 'G9': 12.72, 'G1': 11} / {'G1': 5.5, 'G8': 0, 'G9': 0, 'S3': 0, 'B1': 0, 'B2': 0} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:27-38:@29 tidak valid: EXPORT; DELAY:G5:27-38:@28 tidak valid: EXPORT |
| 12 | G5 row start | 27 |
| 13 | G5 row minimum runtime selesai | 38 (12 row) |
| 14 | G5 row legal stop pertama | 39 |
| 15 | G5 row benar-benar stop | 39 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 46: status paksa 1, akun MM2100 terpakai penuh 45 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 12, "RESOLVED_BY_STOP": 10, "PASS_WITH_REASON": 10, "FAIL": 0} |
| 18 | CP minimum absolut | 63.4469 (POLISH_UNIT_PRIORITY_LANJUTAN) |
| 19 | CP pemenang | 63.4469 |
| 20 | Heat Rate pemenang | 8109.13 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M03_CONSOLIDATION_WB09 — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | WB09 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 29: 29.0/31.0 MW, headroom 2.00; G5 row 29: 20.0/31.0 MW, headroom 11.00; G8 row 29: 108.0/108.0 MW, headroom 0.00; G9 row 29: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 64.5788) | G1 505.5, G2 220.0, G5 0.0, G8 2110.1, G9 2095.0, S2 493.0, S3 2540.5, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.3644) | G1 524.8, G2 0.0, G5 110.0, G8 2189.3, G9 2189.3, S2 382.0, S3 2605.0, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -90.74, '3': 173.46} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -110.97, '3': 64.49} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -201.71, '3': 237.95} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 70 (STG ikut berubah 62; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start | DECOMMIT:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW); DELAY:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW); SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G3 (S1) mati sepanjang interval, G3 prioritas lebih rendah) / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 27.14, 'G9': 27.14, 'G1': 11} / {'G1': 5.5, 'G8': 0, 'G9': 0, 'S3': 0, 'B1': 0, 'B2': 0} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:27-38:@29 tidak valid: EXPORT; DELAY:G5:27-38:@28 tidak valid: EXPORT |
| 12 | G5 row start | 27 |
| 13 | G5 row minimum runtime selesai | 38 (12 row) |
| 14 | G5 row legal stop pertama | 39 |
| 15 | G5 row benar-benar stop | 39 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 0: status paksa 0, akun MM2100 terpakai penuh 0 (kuota 0.000, terpakai 0.000 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 24, "RESOLVED_BY_STOP": 21, "PASS_WITH_REASON": 18, "FAIL": 0} |
| 18 | CP minimum absolut | 64.3644 (POLISH_UNIT_PRIORITY_LANJUTAN) |
| 19 | CP pemenang | 64.3644 |
| 20 | Heat Rate pemenang | 8319.17 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M04_MIN_RUNTIME_STOP — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | Q_pep_kp72_1 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G2 > S2 > GE1 > GE2 |
| 3 | unit running | G1, G2, G8, G9, S2, S3, GE1, GE2, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 20.0/31.0 MW, headroom 11.00; G2 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 103.3/108.0 MW, headroom 4.67; G9 row 28: 103.3/108.0 MW, headroom 4.67 |
| 5 | energi sebelum redistribusi (MWh; CP 64.6324) | G1 504.4, G2 0.0, G5 110.0, G8 2070.6, G9 2070.6, S2 380.1, S3 2516.7, GE1 300.0, GE2 114.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.631) | G1 505.1, G2 110.0, G5 0.0, G8 2072.9, G9 2069.0, S2 380.5, S3 2517.0, GE1 300.0, GE2 114.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': 0.75, '3': 0.69} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': 0.33, '3': 0.24} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': 1.08, '3': 0.93} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 30 (STG ikut berubah 18; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start |  / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 33.43, 'G9': 33.43, 'G1': 11} / {} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:26-37:@27 valid CP 64.6732; DELAY:G5:26-37:@28 tidak valid: EXPORT |
| 12 | G5 row start | None |
| 13 | G5 row minimum runtime selesai | None (12 row) |
| 14 | G5 row legal stop pertama | None |
| 15 | G5 row benar-benar stop | 38 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 93: status paksa 1, akun MM2100 terpakai penuh 92 (kuota 3.200, terpakai 3.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 0, "RESOLVED_BY_STOP": 10, "PASS_WITH_REASON": 34, "FAIL": 0} |
| 18 | CP minimum absolut | 64.631 (V11_SUSUN:SWAP:G5>G2:26-37) |
| 19 | CP pemenang | 64.631 |
| 20 | Heat Rate pemenang | 8382.58 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M05_RESERVE — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | OPS:pgn_pipe=33;mj=spinning_reserve_min:5 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 > GE2 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, GE2, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 31.0/31.0 MW, headroom 0.00; G5 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 108.0/108.0 MW, headroom 0.00; G9 row 28: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 64.5216) | G1 518.0, G5 220.0, G8 2145.0, G9 2145.2, S2 491.6, S3 2572.1, GE1 291.8, GE2 0.0, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.4728) | G1 522.8, G5 170.0, G8 2215.5, G9 2163.0, S2 438.3, S3 2605.0, GE1 231.0, GE2 46.5, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -45.18, '3': 88.46} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -53.25, '3': 32.9} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -98.43, '3': 121.36} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 68 (STG ikut berubah 67; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start | DECOMMIT:EXPORT(kapasitas maks row 22 = 25.0 < Range Min 25.0 MW); SWAP:EXPORT(Export maks row 28 = 14.8 MW < Range Min 25.0 MW (seluruh unit tersedia pada Effective Max)); MIN_RUNTIME_COMBINED_CYCLE_SEJAK_START_ROW_16 / row kebutuhan Export [22, 31, 5] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 36.99, 'G9': 36.99, 'G1': 11} / {'G1': 5.5, 'G8': 0, 'G9': 0, 'S3': 0, 'B1': 0, 'B2': 0} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:16-37:@17 tidak valid: GAS_WINDOW,RESERVE; DELAY:G5:16-37:@20 valid CP 64.4728 |
| 12 | G5 row start | 20 |
| 13 | G5 row minimum runtime selesai | 31 (12 row) |
| 14 | G5 row legal stop pertama | 28 |
| 15 | G5 row benar-benar stop | 38 |
| 16 | G5 alasan numerik tidak stop | STOP:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW) / DECOMMIT:EXPORT(kapasitas maks row 22 = 25.0 < Range Min 25.0 MW) / SWAP:EXPORT(Export maks row 28 = 14.8 MW < Range Min 25.0 MW (seluruh unit tersedia pada Effective Max)) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 40: status paksa 5, akun MM2100 terpakai penuh 35 (kuota 2.200, terpakai 2.183 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 9, "RESOLVED_BY_STOP": 2, "PASS_WITH_REASON": 25, "FAIL": 0} |
| 18 | CP minimum absolut | 64.4728 (DELAY:G5:16-37:@20) |
| 19 | CP pemenang | 64.4728 |
| 20 | Heat Rate pemenang | 8296.78 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M06_BUSFLOW — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | OPS:pgn_pipe=30;mj=busflow_min:22 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G2 > S2 > GE1 |
| 3 | unit running | G1, G2, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 20.0/31.0 MW, headroom 11.00; G2 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 103.3/108.0 MW, headroom 4.67; G9 row 28: 103.3/108.0 MW, headroom 4.67 |
| 5 | energi sebelum redistribusi (MWh; CP 64.6658) | G1 504.4, G2 0.0, G5 110.0, G8 2070.6, G9 2070.6, S2 380.1, S3 2516.7, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.6644) | G1 505.1, G2 110.0, G5 0.0, G8 2072.9, G9 2069.0, S2 380.5, S3 2517.0, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': 0.75, '3': 0.69} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': 0.33, '3': 0.24} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': 1.08, '3': 0.93} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 30 (STG ikut berubah 18; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start |  / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 33.43, 'G9': 33.43, 'G1': 11} / {} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:26-37:@27 valid CP 64.7173; DELAY:G5:26-37:@28 tidak valid: EXPORT |
| 12 | G5 row start | None |
| 13 | G5 row minimum runtime selesai | None (12 row) |
| 14 | G5 row legal stop pertama | None |
| 15 | G5 row benar-benar stop | 38 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 47: status paksa 1, akun MM2100 terpakai penuh 46 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 0, "RESOLVED_BY_STOP": 10, "PASS_WITH_REASON": 34, "FAIL": 0} |
| 18 | CP minimum absolut | 64.6644 (V11_SUSUN:SWAP:G5>G2:26-37) |
| 19 | CP pemenang | 64.6644 |
| 20 | Heat Rate pemenang | 8386.61 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M07_EXPORT_RANGE — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":26,"max":155,"required":true},"daily_target":{"value":1800,"required":false},"dispatch_dev_rules":[],"ran |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 31.0/31.0 MW, headroom 0.00; G5 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 108.0/108.0 MW, headroom 0.00; G9 row 28: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 64.2998) | G1 525.9, G5 120.0, G8 2232.2, G9 2232.2, S2 387.6, S3 2636.8, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.273) | G1 529.4, G5 110.0, G8 2240.9, G9 2240.6, S2 379.1, S3 2643.3, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -6.55, '3': 17.13} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -8.51, '3': 6.41} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -15.06, '3': 23.54} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 41 (STG ikut berubah 40; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start | DECOMMIT:EXPORT(kapasitas maks row 22 = 25.0 < Range Min 27.0 MW); DELAY:EXPORT(kapasitas maks row 22 = 25.0 < Range Min 27.0 MW); SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G3 (S1) mati sepanjang interval, G3 prioritas lebih rendah) / row kebutuhan Export [22, 31, 6] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 33.77, 'G9': 33.77, 'G1': 11} / {'G1': 3, 'G8': 0, 'G9': 0, 'S3': 0, 'B1': 0, 'B2': 0} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:21-32:@23 tidak valid: EXPORT; DELAY:G5:21-32:@22 valid CP 64.2826 |
| 12 | G5 row start | 21 |
| 13 | G5 row minimum runtime selesai | 32 (12 row) |
| 14 | G5 row legal stop pertama | 33 |
| 15 | G5 row benar-benar stop | 33 |
| 16 | G5 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 46: status paksa 1, akun MM2100 terpakai penuh 45 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 4, "RESOLVED_BY_STOP": 0, "PASS_WITH_REASON": 9, "FAIL": 0} |
| 18 | CP minimum absolut | 64.273 (POLISH_UNIT_PRIORITY_LANJUTAN) |
| 19 | CP pemenang | 64.273 |
| 20 | Heat Rate pemenang | 8253.06 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M08_DEV_DISPATCH — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":20,"max":155,"required":true},"daily_target":{"value":1800,"required":false},"dispatch_dev_rules":[{"min" |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 30.0/31.0 MW, headroom 1.00; G5 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 108.0/108.0 MW, headroom 0.00; G9 row 28: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 65.309) | G1 518.0, G3 270.4, G5 0.0, G8 2188.5, G9 2010.9, S1 117.7, S2 284.1, S3 2538.3, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.4309) | G1 520.5, G3 0.0, G5 180.0, G8 2181.0, G9 2179.1, S1 0.0, S2 450.3, S3 2598.1, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': -270.45, '2': 182.47, '3': 160.73} |
| 8 | delta STG per blok (MWh) | {'1': -117.72, '2': 166.23, '3': 59.78} |
| 9 | delta total blok GTG+STG (MWh) | {'1': -388.17, '2': 348.7, '3': 220.51} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 139/144, tahan 5, langgar 0; row GTG berubah vs sebelum 87 (STG ikut berubah 77; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G3 alasan start |  / row kebutuhan Export [22, 31, 6] |
| 10b | G3 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 33.5, 'G9': 33.5, 'G1': 11} / {} |
| 11 | G3 start dapat ditunda? | tidak — DELAY:G3:21-39:@22 tidak valid: MIN_RUNTIME_DOWNTIME; DELAY:G3:21-39:@23 tidak valid: EXPORT |
| 12 | G3 row start | None |
| 13 | G3 row minimum runtime selesai | None (12 row) |
| 14 | G3 row legal stop pertama | None |
| 15 | G3 row benar-benar stop | 40 |
| 16 | G3 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 47: status paksa 1, akun MM2100 terpakai penuh 46 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 24, "RESOLVED_BY_STOP": 22, "PASS_WITH_REASON": 24, "FAIL": 0} |
| 18 | CP minimum absolut | 64.4309 (V11_SUSUN:SWAP:G3>G5:21-39) |
| 19 | CP pemenang | 64.4309 |
| 20 | Heat Rate pemenang | 8287.94 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M09_REQUIRED_G5 — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | OPS:pgn_pipe=30;mj=required_mode:{"g1":{"mode":"continuous"},"g8":{"mode":"continuous"},"g9":{"mode":"continuous"},"s2":{"mode":"continuous"},"s3":{"mode":"cont |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 20.0/31.0 MW, headroom 11.00; G5 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 103.3/108.0 MW, headroom 4.67; G9 row 28: 103.3/108.0 MW, headroom 4.67 |
| 5 | energi sebelum redistribusi (MWh; CP 64.7788) | G1 492.9, G5 160.0, G8 2032.6, G9 2032.6, S2 426.1, S3 2488.4, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.7788) | G1 492.9, G5 160.0, G8 2032.6, G9 2032.6, S2 426.1, S3 2488.4, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 0 (STG ikut berubah 0; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10-16 | unit prioritas rendah | tidak ada unit prioritas rendah yang start (seluruh beban dipenuhi unit prioritas tinggi) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 48: status paksa 1, akun MM2100 terpakai penuh 47 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 0, "RESOLVED_BY_STOP": 0, "PASS_WITH_REASON": 46, "FAIL": 0} |
| 18 | CP minimum absolut | 64.7788 (INCUMBENT) |
| 19 | CP pemenang | 64.7788 |
| 20 | Heat Rate pemenang | 8412.58 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M10_MULTI_START — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | Q_pgn_pipe_4 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 29: 31.0/31.0 MW, headroom 0.00; G5 row 29: 20.0/31.0 MW, headroom 11.00; G8 row 29: 108.0/108.0 MW, headroom 0.00; G9 row 29: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 64.3954) | G1 531.2, G5 220.0, G8 2197.3, G9 2197.3, S2 494.6, S3 2611.0, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.1256) | G1 537.7, G5 110.0, G8 2296.2, G9 2296.2, S2 385.5, S3 2684.5, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -103.51, '3': 197.72} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -109.15, '3': 73.57} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -212.66, '3': 271.29} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 55 (STG ikut berubah 53; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G2 alasan start |  / row kebutuhan Export [28, 31, 4] |
| 10b | G2 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 20.98, 'G9': 20.98, 'G1': 11} / {} |
| 11 | G2 start dapat ditunda? | tidak — DELAY:G2:27-38:@28 tidak valid: EXPORT; DELAY:G2:27-38:@29 tidak valid: EXPORT |
| 12 | G2 row start | None |
| 13 | G2 row minimum runtime selesai | None (12 row) |
| 14 | G2 row legal stop pertama | None |
| 15 | G2 row benar-benar stop | 39 |
| 16 | G2 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 46: status paksa 1, akun MM2100 terpakai penuh 45 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 13, "RESOLVED_BY_STOP": 17, "PASS_WITH_REASON": 10, "FAIL": 0} |
| 18 | CP minimum absolut | 64.1256 (V11_SUSUN:SWAP:G2>G5:27-38) |
| 19 | CP pemenang | 64.1256 |
| 20 | Heat Rate pemenang | 8207.16 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M11_GAS_WINDOW — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | Q_pep_1 |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 30: 24.0/31.0 MW, headroom 7.00; G5 row 30: 20.0/31.0 MW, headroom 11.00; G8 row 30: 108.0/108.0 MW, headroom 0.00; G9 row 30: 108.0/108.0 MW, headroom 0.00 |
| 5 | energi sebelum redistribusi (MWh; CP 64.6765) | G1 504.7, G5 220.0, G8 2081.5, G9 1991.5, S2 492.6, S3 2491.3, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.4253) | G1 518.8, G5 110.0, G8 2128.1, G9 2128.0, S2 384.9, S3 2559.4, GE1 291.8, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': -95.94, '3': 183.13} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': -107.75, '3': 68.08} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': -203.69, '3': 251.21} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 61 (STG ikut berubah 60; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G2 alasan start |  / row kebutuhan Export [28, 31, 4] |
| 10b | G2 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 32.56, 'G9': 32.56, 'G1': 11} / {} |
| 11 | G2 start dapat ditunda? | tidak — DELAY:G2:27-38:@28 tidak valid: EXPORT; DELAY:G2:27-38:@29 tidak valid: EXPORT |
| 12 | G2 row start | None |
| 13 | G2 row minimum runtime selesai | None (12 row) |
| 14 | G2 row legal stop pertama | None |
| 15 | G2 row benar-benar stop | 39 |
| 16 | G2 alasan numerik tidak stop | — (stop pada/ sebelum row legal pertama) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 46: status paksa 1, akun MM2100 terpakai penuh 45 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 29, "RESOLVED_BY_STOP": 10, "PASS_WITH_REASON": 23, "FAIL": 0} |
| 18 | CP minimum absolut | 64.4253 (V11_SUSUN:SWAP:G2>G5:27-38) |
| 19 | CP pemenang | 64.4253 |
| 20 | Heat Rate pemenang | 8336.79 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

## M12_ACTUAL_SLOT — PASS

| # | butir | nilai |
|---|---|---|
| 1 | state | ACT_PGN_UP |
| 2 | Unit Priority (urut grup/rank input) | B1 > B2 > G8 > G9 > S3 > G1 > G5 > S2 > GE1 |
| 3 | unit running | G1, G5, G8, G9, S2, S3, GE1, BB1, BB2 |
| 4 | legal headroom GTG berjalan pada row beban puncak | G1 row 28: 20.0/31.0 MW, headroom 11.00; G5 row 28: 20.0/31.0 MW, headroom 11.00; G8 row 28: 103.3/108.0 MW, headroom 4.67; G9 row 28: 103.3/108.0 MW, headroom 4.67 |
| 5 | energi sebelum redistribusi (MWh; CP 64.6801) | G1 504.7, G5 110.0, G8 2079.7, G9 2060.1, S2 380.3, S3 2516.2, GE1 289.5, BB1 2880.0, BB2 2880.0 |
| 6 | energi sesudah redistribusi (MWh; CP 64.6801) | G1 504.7, G5 110.0, G8 2079.7, G9 2060.1, S2 380.3, S3 2516.2, GE1 289.5, BB1 2880.0, BB2 2880.0 |
| 7 | delta GTG per blok (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 8 | delta STG per blok (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 9 | delta total blok GTG+STG (MWh) | {'1': 0.0, '2': 0.0, '3': 0.0} |
| 9b | STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row) | FINAL 144/144 row x STG = calc, tahan start-up 0, langgar 0; sebelum redistribusi 144/144, tahan 0, langgar 0; row GTG berubah vs sebelum 0 (STG ikut berubah 0; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas) |
| 10 | G5 alasan start | BAND_V11_TIE_BREAK: kandidat valid SWAP:G5>G2:27-38 tanpa G5 pada row ini (CP 64.6587, -0.0214 di dalam band 0,2 %, Heat Rate 8388.31 vs 8387.69) / row kebutuhan Export [28, 31, 4] |
| 10b | G5 headroom prioritas tinggi saat start (sebelum review / FINAL) | {'G8': 43, 'G9': 43, 'G1': 11} / {'G1': 11, 'G8': 17.1, 'G9': 17.1, 'S3': 0, 'B1': 0, 'B2': 0} |
| 11 | G5 start dapat ditunda? | tidak — DELAY:G5:27-38:@29 tidak valid: EXPORT; DELAY:G5:27-38:@28 tidak valid: GAS_WINDOW,EXPORT,RAMP |
| 12 | G5 row start | 26 |
| 13 | G5 row minimum runtime selesai | 37 (12 row) |
| 14 | G5 row legal stop pertama | 38 |
| 15 | G5 row benar-benar stop | 39 |
| 16 | G5 alasan numerik tidak stop | DECOMMIT:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW) / MIN_RUNTIME_COMBINED_CYCLE_SEJAK_START_ROW_27 / CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID SWAP:G5>G2:27-38 (CP 64.6587, +-0.0214) |
| 4b | uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom) | temuan 46: status paksa 1, akun MM2100 terpakai penuh 45 (kuota 2.200, terpakai 2.199 BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan 0 [] |
| 17 | LOW_LOAD_FRAGMENTATION | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION": 0, "RESOLVED_BY_STOP": 0, "PASS_WITH_REASON": 35, "FAIL": 0} |
| 18 | CP minimum absolut | 64.6587 (SWAP:G5>G2:27-38) |
| 19 | CP pemenang | 64.6801 |
| 20 | Heat Rate pemenang | 8387.69 |
| 21 | constraints | {'hard': 'PASS', 'release': True, 'export_in_band_rows': 48} |
| 22 | provenance | {'fuel': 'PASS', 'mm2100': 'PASS'} |
| K | kriteria FAIL | low_start_with_sufficient_high_headroom=OK; high_units_raised_as_far_as_legal_and_needed=OK; stg_recalculated_on_gtg_change=OK; low_unit_stopped_at_first_legal_row_or_numeric_proof=OK; low_load_fragmentation_resolved=OK; cheaper_valid_candidates_in_comparator=OK; heat_rate_only_inside_cp_band=OK; identical_cold_warm_helper=OK; hard_constraints_and_provenance=OK; independent_cross_group_merit=OK |

