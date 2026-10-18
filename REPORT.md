# REPORT V15.25 — Remediasi UI + Logic + Full Regression (basis V15.24)

Engine build: `V15.25-REMEDIATION-UI-LOGIC-FULL-REGRESSION-20261018`. Baseline: V15.24 (`d207780`, ZIP FULL SHA-256 `cc8135fc…8aef`, direproduksi — lihat `harness/v1525/BASELINE_V1524_CONFIRMED.md`).
Platform acceptance: **PHP 7.4.33** (opcache CLI aktif), setiap run engine dipin ke **1 logical CPU** (`taskset`, dibuktikan `Cpus_allowed_list`). MariaDB lokal untuk store report.

## 0. Ringkasan gate

| gate | hasil |
|---|---|
| Matrix engine 229 test (207 lama + 22 baru) | **193 PASS, 36 EXPECTED_REJECTION (bukti terminal numerik), 0 FAIL** |
| 14 FAIL V15.24 | 0 FAIL: 8 PASS (published FASTEST_FINAL, hard PASS), 6 EXPECTED_REJECTION dengan sertifikat numerik |
| Grup I + H15/H16 (MySQL, gi.php) | 14/14 PASS |
| UI semantic smoke V15.24 (Playwright) | 26/26 PASS |
| UI popup Ctrl+Click / Fix Load (3 layar x zoom 100/125/150 %, normal & Full Screen) | 203/203 PASS (V15.24: 134 dari 143 FAIL) |
| UI Distillate Priority 1–6 | 5/5 PASS |
| UI Check Yesterday Data / Start Up CC / target backward | 12/12 PASS; backend 9/9 PASS |
| Maximum Review vs Fastest | kandidat Fastest selalu dibandingkan; pemenang = comparator final (T227, T228) |
| Runtime 1 CPU (target normal 30 s / sulit 40 s / exact rerun 15 s / Actual Gas inkremental 5 s / Fix warm 10 s) | **TIDAK LULUS**: exact rerun (0,5–0,7 s) dan Actual Gas inkremental (0,75–0,92 s) lulus; A0 cold 41–43 s, gas/LNG/terminal/Actual cold 73–110 s, Fix Load warm 27 s di atas target |

**Keputusan paket:** gate runtime adalah gate material yang belum lulus, sehingga paket **tidak** diberi nama FINAL/VALIDATED. Paket yang dibangun: `XAMPP_V1525_RC_RUNTIME_GATE_OPEN_{PATCH,FULL_DEPLOYMENT,DOCUMENTATION}.zip` (release candidate, clean-install smoke lulus, lihat bagian 8).

## 1. Skenario aktual A0 (snapshot user) — sebelum / sesudah

| versi | CP (USD/MWh) | HR (BTU/kWh) | Distillate (l) | Gas (BBTUD) | event |
|---|---|---|---|---|---|
| V15.24 | 93.4567 | 8481.93 | 208545 | 65.4567 | G3+05:30 G3-17:00 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+09:00 |
| V15.25 | 93.4567 | 8481.93 | 208545 | 65.4567 | identik (Event Proof TERBUKTI, 25 evaluasi) |

A0 tidak berubah karena tidak memuat komposisi 3+1, Actual Gas, Start Up CC, target Export harian, maupun kelanjutan startup. Prescreen kapasitas tightening (`PP_V1525_CAPBOUND=0` -> output identik, T229) hanya mengurangi simulasi.

## 2. Matrix regresi (Fastest; PHP 7.4.33; worker dipin 1 CPU per run; 3 run paralel di CPU berbeda)

| grup | jumlah | PASS | EXPECTED_REJECTION | FAIL |
|---|---|---|---|---|
| A | 32 | 31 | 1 | 0 |
| B | 30 | 29 | 1 | 0 |
| C | 16 | 11 | 5 | 0 |
| D | 30 | 24 | 6 | 0 |
| E | 24 | 14 | 10 | 0 |
| F | 18 | 17 | 1 | 0 |
| G | 14 | 8 | 6 | 0 |
| H | 14 | 10 | 4 | 0 |
| J | 7 | 7 | 0 | 0 |
| X | 23 | 22 | 1 | 0 |
| K | 6 | 5 | 1 | 0 |
| L | 6 | 6 | 0 | 0 |
| N | 7 | 7 | 0 | 0 |
| M | 2 | 2 | 0 | 0 |
| **total engine** | **229** | **193** | **36** | **0** |

Grup I + H15/H16 (MySQL): 14/14 PASS. Total engine + DB: **243 test, 207 PASS, 36 EXPECTED_REJECTION, 0 FAIL** (+ UI 26 + 203 + 5 + 12, backend yesterday 9).
Oracle publikasi diperketat: hasil hanya dihitung published bila job-level `publish_allowed`, hard validation PASS, 48 row, **dan** output tidak `preliminary` / `publish_allowed=false` (lihat bagian 5, temuan 2+2).

### 14 FAIL V15.24 -> V15.25

| test | judul | V15.24 | V15.25 | CP V15.25 | wall s (matrix, 3 run paralel) | bukti |
|---|---|---|---|---|---|---|
| T124 | PLN Export Priority susunan kedua (Daily target required 1800) | FAIL | EXPECTED_REJECTION | 115.1217 | 61.67 | TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.1657 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sis |
| T127 | Daily Export tepat target (target 1500 required) | FAIL | EXPECTED_REJECTION | 102.2193 | 59.04 | TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.2880 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sis |
| T128 | Daily Export di bawah target perlu koreksi (target 2600 required) | FAIL | EXPECTED_REJECTION | 114.0188 | 133.79 | TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.9729 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sis |
| T129 | Daily Export infeasible dengan terminal proof (target 6000 required) | FAIL | EXPECTED_REJECTION | 114.0188 | 0.9 | TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_DAILY_EXPORT_TARGET: Target Export harian REQUIRED 6000.00 MWh > energi Export maksimum 3617.42 MWh (batas ata |
| T130 | Dev Dispatch positif dan negatif (rule -10..+10 row 10-30) | FAIL | EXPECTED_REJECTION | 119.6635 | 0.83 | TERMINAL_INFEASIBLE:INPUT_CONTRADICTION_EXPORT_BAND_STEP: Row 27 -> row 28: Export harus <= 100.00 lalu >= 140.00 MW, perubahan minimum 40.00 MW > bat |
| T134 | Actual PGN lebih rendah (row 1-8) | FAIL | PASS | 93.0236 | 68.04 | published FASTEST_FINAL, hard PASS |
| T135 | Actual Fixed Flow Jababeka lebih tinggi (row 1-8) | FAIL | PASS | 108.7221 | 116.98 | published FASTEST_FINAL, hard PASS |
| T136 | Actual Fixed Flow Jababeka lebih rendah | FAIL | PASS | 100.8793 | 0.73 | published FASTEST_FINAL, hard PASS |
| T137 | Actual MM2100 lebih tinggi | FAIL | PASS | 94.248 | 1.81 | published FASTEST_FINAL, hard PASS |
| T139 | Kombinasi ketiga Actual Gas | FAIL | EXPECTED_REJECTION | 92.677 | 220.14 | GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION |
| T154 | Trip G9 | FAIL | PASS | 108.3314 | 32.76 | published FASTEST_FINAL, hard PASS |
| T161 | Change Over legal (Block 1 start 08:00, Block 2 stop 12:00) | FAIL | EXPECTED_REJECTION | 109.1954 | 0.84 | TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_CAPACITY: 6 row tidak dapat memenuhi lantai Export walau SELURUH unit yang tersedia berbeban maksimum ( |
| T164 | Gas/Export/Bus Flow setelah trip (G9 trip + busflow_min 20) | FAIL | PASS | 108.3314 | 35.1 | published FASTEST_FINAL, hard PASS |
| T178 | Fix + Actual Gas lebih rendah | FAIL | PASS | 94.3407 | 1.6 | published FASTEST_FINAL, hard PASS |


### EXPECTED_REJECTION (bukti terminal)

- T028 Konfigurasi Initial Stop + Simulation + Hold dipindahkan ke G8 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada beban maksimum -> Export maksimum -108.32
- T043 Seluruh feeder Block 1 Stop -> input ditolak / Block 1 tidak dapat dipenuhi — INPUT_CONTRADICTION:BLOCK_REQUIRED_CONTRADICTORY_INPUT
- T067 Distillate hanya pada unit prioritas pertama bila cukup (shortage kecil) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 60.000 l (2.1617 BBTUD); sisa 9.3066 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.197
- T069 Distillate level 30% dari batas user — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 99.240 l (3.5755 BBTUD); sisa 7.8928 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.197
- T070 Distillate level 50% dari batas user — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 165.400 l (5.9592 BBTUD); sisa 5.5091 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.19
- T077 LNG lebih ekonomis dibanding Distillate (action recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T078 Kombinasi LNG + Distillate diperlukan (IE +20, action recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T086 LNG quota turun (-4) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.4683 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 3.5500 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.1
- T102 Gas Shortage dengan LNG + Distillate (IE +20, recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T103 Kapasitas LNG tidak cukup (add_lng 1 BBTUD, shortage aktual) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: sesudah LNG yang diotorisasi 1.0000 BBTUD masih tersisa kebutuhan 10.1919 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.1777 BBTUD. Opsi: tambahan LNG total
- T104 Kapasitas Distillate tidak cukup (limit 20000 l) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 20.000 l (0.7206 BBTUD); sisa 10.7477 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.19
- T105 Terminal fuel infeasible (IE +20, distillate limit aktual) [A7] — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.8010 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 5.8827 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.4
- T106 Stale fuel-decision cache tidak dipakai (action none setelah distillate) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T116 Follow PV naik tajam — RESERVE_INFEASIBILITY_CERTIFICATE
- T117 Follow PV turun tajam — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 13.6230 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 1.7047 BBTUD > batas atas penurunan gas yang masih dapat dipakai 1.1
- T122 SR Effective Minimum tinggi (40 MW) — RESERVE_INFEASIBILITY_CERTIFICATE
- T124 PLN Export Priority susunan kedua (Daily target required 1800) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.1657 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 5.2473 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.0
- T125 Export Range ketat (min 40 sepanjang hari) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 16.0082 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 4.0899 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.4
- T127 Daily Export tepat target (target 1500 required) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.2880 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 3.3697 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.1
- T128 Daily Export di bawah target perlu koreksi (target 2600 required) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.9729 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 6.0546 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.0
- T129 Daily Export infeasible dengan terminal proof (target 6000 required) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_DAILY_EXPORT_TARGET: Target Export harian REQUIRED 6000.00 MWh > energi Export maksimum 3617.42 MWh (batas atas per row = min(RangeMax, kapasitas fisik seluruh unit tersedia pada b
- T130 Dev Dispatch positif dan negatif (rule -10..+10 row 10-30) — TERMINAL_INFEASIBLE:INPUT_CONTRADICTION_EXPORT_BAND_STEP: Row 27 -> row 28: Export harus <= 100.00 lalu >= 140.00 MW, perubahan minimum 40.00 MW > batas langkah Export 35 MW per 30 menit (hard constraint export_step). Pi
- T132 Adjustment beberapa unit + Bus Flow boundary (G2 26, G8 90; busflow_min 20) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.9900 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 4.0717 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.5
- T139 Kombinasi ketiga Actual Gas — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T152 Trip Babelan unit 2 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, G8 105 MW, S2 16.03 MW, S3 57.68 MW) pada beban maksimum -> Export mak
- T153 Trip G8 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada beban maksimum -> Export maksimum -108.32
- T155 Trip unit prioritas tertinggi GT (G8, plus B2 tetap) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada beban maksimum -> Export maksimum -108.32
- T161 Change Over legal (Block 1 start 08:00, Block 2 stop 12:00) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_CAPACITY: 6 row tidak dapat memenuhi lantai Export walau SELURUH unit yang tersedia berbeban maksimum (stop/trip/unavailable dan stop window Stop At/Change Over dihormati; S
- T162 Change Over tidak legal (Block 2 stop 01:00 sebelum Block 1 siap) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_CAPACITY: 8 row tidak dapat memenuhi lantai Export walau SELURUH unit yang tersedia berbeban maksimum (stop/trip/unavailable dan stop window Stop At/Change Over dihormati; S
- T163 Ramp dan reserve setelah trip (G8 trip 12:00, SR 10) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.0908 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 5.1725 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.1
- T171 Fix di atas MAX ditolak (G2 40 MW) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G2 row 20 = 40 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (1 pelanggaran tersisa). Fixed Load tidak diubah/dihapus 
- T172 Fix di bawah MIN ditolak (G2 5 MW) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G2 row 20 = 5 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (2 pelanggaran tersisa). Fixed Load tidak diubah/dihapus e
- T173 Fix pada unit Trip/Unavailable ditolak (G5 trip + fix) — 
- T175 Fix pada row setelah Stop Request (G5 stop 12:00, fix row 30) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G5 row 30 = 25 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (4 pelanggaran tersisa). Fixed Load tidak diubah/dihapus 
- T194 Stale preliminary/recommendation basis ditolak (aksi recommendation sesudah distillate, app hangat) = run dingin — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T213 Plafon distillate kecil (40000 l): hanya prioritas eligible pertama — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 40.000 l (1.4412 BBTUD); sisa 10.0271 BBTUD > batas atas penurunan gas yang masih dapat dipakai 0.19


### Grup baru K/L/M/N + X229

- T208 [K] Distillate Priority 1-6 global: payload lama 2 grup Block 1/Block 2 = payload gabungan (migrasi identik) — PASS, CP 93.4567; identik dengan A0_actual: events=True cp 93.4567 vs 93.4567 proof=True 48row=True; dipakai ['G3', 'G6']; eligible ['G6', 'G3', 'G2', 'G5', 'G8', 'G9']; tidak eligible tapi dipakai []; prioritas eligible pertama G6 dipakai=True
- T209 [K] Distillate Priority 1-6: Block 2 di atas Block 1 (G1,G2,G5,G6,G4,G3) — PASS, CP 93.4762; dipakai ['G2']; eligible ['G2', 'G5', 'G6', 'G3', 'G8', 'G9']; tidak eligible tapi dipakai []; prioritas eligible pertama G2 dipakai=True; pola Distillate->Gas->Distillate: []
- T210 [K] Distillate Priority 1-6 campuran lintas blok (G2,G6,G5,G4,G1,G3) — PASS, CP 93.4762; dipakai ['G2']; eligible ['G2', 'G6', 'G5', 'G3', 'G8', 'G9']; tidak eligible tapi dipakai []; prioritas eligible pertama G2 dipakai=True; pola Distillate->Gas->Distillate: []
- T211 [K] Unit prioritas 1 Stop seharian (G6 trip): dilewati dengan alasan, tidak distart demi distillate — PASS, CP 93.0509; g6 online 0/48; dipakai ['G3', 'G4']; eligible ['G4', 'G3', 'G2', 'G5', 'G8', 'G9']; tidak eligible tapi dipakai []; prioritas eligible pertama G4 dipakai=True; G6: {'unit': 'G6', 'eligible': False, 'reason': 'TRIP_ATAU_STOP_SEHARIAN'}
- T212 [K] Unit Stop (G4 tidak berbeban) prioritas 1: tidak eligible, unit running berikutnya dipakai — PASS, CP 93.4567; dipakai ['G3', 'G6']; eligible ['G6', 'G3', 'G2', 'G5', 'G8', 'G9']; tidak eligible tapi dipakai []; prioritas eligible pertama G6 dipakai=True
- T213 [K] Plafon distillate kecil (40000 l): hanya prioritas eligible pertama — EXPECTED_REJECTION, CP 102.4698; tidak dipublikasikan; terminal proof: TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 40.000 l (1.4412 BBTUD); sisa 10.0271 B
- T214 [L] S1 & S2 paralel, startup tambahan otomatis: 2+2 diuji sebelum 3+1 (IE +30 row 17-40) — PASS, CP 107.0065; Block Balance 2x2 EVALUATED reason ['AUTO_BALANCE_2X2_TIDAK_LEGAL_3X1_DIPERTAHANKAN', 'AUTO_BALANCE_2X2_TIDAK_LEGAL_3X1_DIPERTAHANKAN', 'AUTO_BALANCE_2X2_TIDAK_LEGAL_3X1_DIPERTAHANKAN', 'AUTO_BALAN; event proof TERBUKTI not_proven=[]
- T215 [L] S1 & S2 paralel tanpa kebutuhan unit tambahan (IE +0): tidak ada 3+1 otomatis — PASS, CP 97.8234; 
- T216 [L] S1 & S2 paralel IE +15: keputusan 2+2/3+1 dicatat dengan alasan — PASS, CP 101.3333; 
- T217 [L] Intent user eksplisit (G1 Start Based On Request 08:00) tidak ditimpa 2+2 — PASS, CP 109.1974; g1 first_on=17 expected 17 (08:30)
- T218 [L] Blok lawan tidak legal (G3/G4 trip): 3+1 dipertahankan dengan alasan — PASS, CP 116.5369; g3 online 0/48; g4 online 0/48
- T219 [L] Prioritas unit ditukar (Block 2 di atas Block 1) + IE +30 — PASS, CP 107.0065; 
- T220 [N] Block 3 cold startup: 40 MW 3,5 jam, 50 MW 1 jam, 60 MW 2,5 jam; S3 berbeban tepat 7,5 jam sesudah startup — PASS, CP 115.5544; lead G8 start row 2 (00:30): profil [40.0, 40.0, 40.0, 40.0, 40.0, 40.0, 40.0, 50.0, 50.0, 60.0, 60.0, 60.0, 60.0, 60.0] (harus 40x7,50x2,60x5); S3 first load row 16 (harus 16 = start + 7,5 jam)
- T221 [N] Start Up CC kemarin: G6 sudah 3 row sequence cold S1 -> sisa profil 20 MW, S1 berbeban row 5 — PASS, CP 94.2265; S1 first load row 5 (harus 5); info {"stg": "S1", "mode": "cold", "lag_rows": 7, "profile_mw": [5, 15, 20, 20, 20, 20, 20], "elapsed_rows": 3, "units": ["G6"], "source": "MATRIX", "remaining_profil; g6 fix 20 MW rows 1-4; bad=[]
- T222 [N] Start Up CC manual (G6, elapsed 0): sequence cold penuh dari row 1, S1 berbeban row 8 — PASS, CP 94.8797; S1 first load row 8 (harus 8); info {"stg": "S1", "mode": "cold", "lag_rows": 7, "profile_mw": [5, 15, 20, 20, 20, 20, 20], "elapsed_rows": 0, "units": ["G6"], "source": "MANUAL_START_UP_CC", "rema
- T223 [N] Kelanjutan sequence + commitment STG eksplisit (S1 continuous): commitment user menang, alasan dicatat — PASS, CP 94.2366; info {"s1": {"stg": "S1", "mode": "cold", "lag_rows": 7, "profile_mw": [5, 15, 20, 20, 20, 20, 20], "elapsed_rows": 3, "units": ["G6"], "source": "MATRIX", "status": "DIABAIKAN_KOMITMEN_STG_EKSPLIS
- T224 [N] Backward scheduling S1 target first load 06:00: GTG start mundur 7 row (same day) — PASS, CP 93.9239; S1 first 12 info {"mode": "BACKWARD_START_SAME_DAY", "target_first_load_row": 12, "lag_rows": 7, "gtg": "G6", "gtg_first_load_row": 5, "stg_first_load_row": 12}
- T225 [N] Backward scheduling S1 target 02:00 (sebelum lag): kelanjutan dari kemarin, S1 berbeban row 4 — PASS, CP 94.1836; S1 first load row 4 (harus 4); info {"stg": "S1", "mode": "cold", "lag_rows": 7, "profile_mw": [5, 15, 20, 20, 20, 20, 20], "elapsed_rows": 4, "units": ["G6"], "source": "BACKWARD_SCHEDULE_TARGET_0
- T226 [N] Kelanjutan cross-day selesai kemarin (elapsed >= lag): unit & STG Running sejak row 1 — PASS, CP 94.2366; S1 row1 11.19 G6 row1 20.0
- T227 [M] Maximum Review tidak lebih buruk dari Fastest (A0): kandidat Fastest selalu dibandingkan, pemenang = comparator final — PASS, CP 93.4567; MR CP 94.4508 (inv 10) vs Fastest CP 93.4567 (inv 15; job Fastest mandiri 93.4567); pemenang comparator final FASTEST_CANDIDATE, dipublikasikan FASTEST_CANDIDATE CP 93.4567
- T228 [M] Maximum Review tidak lebih buruk dari Fastest (A3 G6->G4): pemenang = comparator final — PASS, CP 95.4159; MR CP 95.4159 (inv 17) vs Fastest CP 93.5553 (inv 21; job Fastest mandiri 93.5553); pemenang comparator final MAXIMUM_REVIEW, dipublikasikan MAXIMUM_REVIEW CP 95.4159
- T229 [X] Prescreen batas atas kapasitas Export OFF (PP_V1525_CAPBOUND=0) -> output identik — PASS, CP 93.4567; identik dengan A0_actual: events=True cp 93.4567 vs 93.4567 proof=True 48row=True


## 3. Runtime PHP 7.4.33 — 1 logical CPU

| kasus | target | V15.24 wall s (status, CP) | V15.25 wall s (status, CP) | target V15.25 |
|---|---|---|---|---|
| A0 cold (3x) | <= 30 s | 49.80, 48.60, 50.47 (FASTEST_FINAL, 93.4567) | 41.64, 41.14, 42.84 (FASTEST_FINAL, 93.4567) | TIDAK LULUS |
| Gas cukup A1 cold | <= 40 s | 99.83 (FASTEST_FINAL, 68.4413) | 103.46 (FASTEST_FINAL, 68.4413) | TIDAK LULUS |
| LNG A2 cold | <= 40 s | 65.06 (FASTEST_FINAL, 73.9965) | 72.84 (FASTEST_FINAL, 73.9965) | TIDAK LULUS |
| Terminal A7 cold | <= 40 s | 103.74 (TERMINAL_INFEASIBLE, 122.0047) | 109.83 (TERMINAL_INFEASIBLE, 122.0047) | TIDAK LULUS |
| Actual PGN rendah cold (tanpa basis) | <= 40 s | 79.11 (None, 112.121) | 73.71 (FASTEST_FINAL, 93.0236) | TIDAK LULUS |
| Exact rerun (3x warm) | <= 15 s | 0.54, 0.58, 0.64 (FASTEST_FINAL, 93.4567) | 0.57, 0.53, 0.69 (FASTEST_FINAL, 93.4567) | LULUS |
| Actual PGN rendah inkremental | <= 5 s | 71.67, 74.31, 76.98 (None, 112.121) | 0.86, 0.77, 0.75 (FASTEST_FINAL, 93.183) | LULUS |
| Actual Fixed Flow Jababeka tinggi inkremental | <= 5 s | 44.35, 45.66, 45.39 (None, 105.9801) | 0.80, 0.80, 0.91 (FASTEST_FINAL, 107.3177) | LULUS |
| Actual MM2100 tinggi inkremental | <= 5 s | 60.49, 58.20, 57.41 (None, 105.9801) | 0.89, 0.92, 0.76 (FASTEST_FINAL, 94.248) | LULUS |
| Fix Load warm G2 row 20 (tanpa cache hasil) | <= 10 s | 26.60, 27.39, 27.34 (FASTEST_FINAL, 93.3625) | 27.07, 27.22, 27.64 (FASTEST_FINAL, 92.8227) | TIDAK LULUS |

Metode: `harness/v1525/bench/bench.py` — setiap run `taskset -c 1` (PHP 7.4.33, opcache CLI aktif; `Cpus_allowed_list: 1` tercatat per run), V15.25 lalu V15.24 dijalankan berurutan pada container yang sama tanpa beban lain. Cold = salinan app dengan `jobs/` kosong. Warm = basis A0 dijalankan dulu pada app yang sama, lalu exact rerun / Actual Gas / Fix Load (Fix Load tidak memakai cache hasil: payload baru). Data mentah: `harness/v1525/bench/raw/`.
Catatan: V15.24 pada Actual Gas menghasilkan status kosong (tidak dipublikasikan) — FAIL fungsional, bukan hanya waktu. Fix Load warm pada kedua versi jatuh ke commitment repair penuh karena commitment basis tidak feasible dengan Fix G2 25 MW row 20 (~27 s).
Profil satu job A0: ~184 simulasi inti, ~38,8 s dari 42,9 s di simulasi (rata-rata 0,21 s; 78 % di shaper final). Prescreen kapasitas mengurangi evaluasi Commitment Tightening 99 -> 83 (23,9 s -> 18,9 s) dengan hasil identik.

## 4. Perubahan per fase (generik; tanpa hard-code unit/tanggal/fixture)

- **F1 grup E (Export harian / dispatch-dev):** target Export harian REQUIRED sebagai batas kumulatif (rencana + top-up akhir water-filling di dalam pita per row); validator `export_daily`; sertifikat `TERMINAL_INFEASIBLE_DAILY_EXPORT_TARGET` (batas atas energi = Σ min(RangeMax, kapasitas fisik)), `INPUT_CONTRADICTION_EXPORT_BAND_STEP` (pita dispatch-dev vs langkah Export 35 MW), dan konteks target harian pada `TERMINAL_INFEASIBLE_FUEL_CAP`.
- **F1 grup F/H (Actual Gas):** rekonsiliasi Effective Total Gas pada jalur distillate (shortage digeser deviasi actual, iteratif secant, plafon liter user), baris jam actual immutable (validator Min PGN Flow memperlakukan jam actual sebagai historis), biaya MM2100 dari actual. V15.25 runtime: rerun rekonsiliasi hanya menjalankan blok akuntansi bahan bakar (state dispatch dipulihkan dari simulasi polos, `PP_V1525_ACT_SNAP`).
- **F1 grup G (trip / Change Over):** GTG simple-cycle standby sebagai last resort hanya pada row kekurangan kapasitas nyata; sertifikat `TERMINAL_INFEASIBLE_EXPORT_CAPACITY` (row ≥ 2, seluruh unit tersedia maksimum); sertifikat terminal juga di jalur non-Fastest (Change Over / Maximum Review).
- **F1 Maximum Review:** kandidat Fastest (dingin, tanpa basis warm IE) selalu dihitung lebih dulu lalu dibandingkan dengan comparator final yang sama; sidik jari engine memuat flag env `PP_*` sehingga cache hasil tidak tercampur antar-flag.
- **F2 popup Ctrl+Click / Fix Load:** portal `position:fixed`, clamp/flip terhadap visualViewport ∩ panel Full Screen, footer OK/Cancel sticky, Esc/Enter/klik luar, focus trap, reposisi pada resize/scroll.
- **F3 Distillate Priority 1–6:** Block 1 dan Block 2 digabung, migrasi payload lama deterministik (urutan grup lalu urutan unit), hanya unit Running & legal eligible, alasan unit dilewati (`Distillate Unit Selection`).
- **F4 2+2 sebelum 3+1:** tahap Auto Balance sesudah tightening (pengganti = unit legal prioritas berikut di blok lawan, lead 0/1/2/4 row, redispatch + validasi penuh); comparator mengutamakan komposisi lebih seimbang hanya bila CP ekonomi tidak lebih buruk di luar band 0,02 %; intent user (Start Based On Request / Required) tidak ditimpa; reason code `AUTO_BALANCE_2X2_BEFORE_3X1` / `…_VALID_TETAPI_CP_EKONOMI_LEBIH_BURUK_3X1_DIPERTAHANKAN` / `…_TIDAK_LEGAL_3X1_DIPERTAHANKAN`.
- **F5 Actual Gas inkremental:** tahap warm 0 (dispatch terkunci, rekonsiliasi akuntansi) dan 0b (trim gas row planning), bukti event basis dipakai ulang bila 48 row identik.
- **F6:** tombol **Check Yesterday Data** (Monitoring Daily Plan > Daily Plan > No Data, versi aktif terbaru, data rusak dilewati dengan alasan, project terisolasi, pratinjau + Apply/Cancel + provenance); status **Start Up CC**; kelanjutan startup lintas tanggal (sisa profil sebagai fix load, feeder lain ditahan, mode `<mode>@n`), backward scheduling dari target first STG load (same-day start_at atau kelanjutan dari kemarin), commitment STG eksplisit yang menetapkan waktu (Continuous / Start Based On Request) menang dengan alasan dicatat; profil cold Block 3 40 MW 3,5 jam, 50 MW 1 jam, 60 MW 2,5 jam (S3 berbeban tepat 7,5 jam sesudah startup); aturan Additional HRSG pada shaper diselaraskan dengan `block_sequence`/validator untuk sequence Block 3 (6 langkah).
- **Runtime:** prescreen batas atas kapasitas Export (sound) di Commitment Tightening; rekonsiliasi Actual Gas tanpa ulang dispatch.

## 5. Temuan yang diperbaiki selama full regression (tidak dipalsukan PASS)

1. 2+2 yang diterapkan membawa info output redispatch saja -> `Run Status` hilang -> hasil dikunci `RUN_NOT_CONVERGED` sementara job-level `publish_allowed=true` (17 test terhitung PASS pada run sebelumnya). Diperbaiki: metadata pipeline incumbent dibawa; job-level `publish_allowed` konsisten dengan kontrak hasil; oracle matrix diperketat. Matrix diulang penuh.
2. Comparator 2+2 global tanpa batas CP membuat T032 gagal gerbang merit C4 -> preferensi 2+2 dibatasi band CP ekonomi; bukti event final (uji stop 1 row lebih awal, defisit interval beban minimum) dipakai sebagai bukti C4.
3. Cache hasil tercampur antar-flag env (T188 Tightening OFF mengisi cache konteks A0) -> sidik jari engine memuat flag `PP_*`.
4. Kelanjutan startup: Required + Start Based On Simulation pada STG tidak lagi dianggap konflik; validator mengenali sisa profil sequence dari kemarin.

## 6. Keterbatasan (dilaporkan apa adanya)

- **Runtime cold 1 CPU belum memenuhi target** (bagian 3). Pada mesin uji ini V15.24 sendiri membutuhkan ~42 s untuk A0 (laporan V15.24: 36 s pada mesin lain). Profil: ~85–90 % waktu adalah simulasi inti (rata-rata ~0,2 s per simulasi, ~180–240 simulasi per job; 78 % di shaper final). Pengurangan lebih lanjut membutuhkan perubahan algoritmik pada shaper/tightening yang mengubah hasil; tidak dilakukan tanpa bukti kesetaraan.
- Fix Load warm (payload baru, tanpa cache hasil) ~27 s pada V15.24 maupun V15.25: Fix yang membuat commitment basis tidak feasible memicu commitment repair penuh; target 10 s tidak tercapai.
- Gas cukup / LNG / terminal cold 4–12 % lebih lambat daripada V15.24 pada mesin yang sama (tahap baru: Auto Balance 2+2, bukti event final, sertifikat); A0 cold 15 % lebih cepat.
- Actual Gas cold (tanpa basis warm) tetap > 40 s (T134 ~61–68 s, T135 ~111–117 s, T139 ~212–220 s); jalur yang dipakai operator (rerun inkremental dari basis) < 2 s (T136/T137/T178).
- T139 (kombinasi tiga Actual Gas) berakhir pada keputusan operator `GAS_WINDOW_CONFLICT` dengan bukti (bukan rencana final).
- T032 (S2 Initial Stop + Simulation + Hold): CP 98,96 vs V15.24 98,37 (+0,6 %) — jalur pencarian berbeda karena preferensi keseimbangan blok; hasil valid & terbukti.
- T228 (Maximum Review A3): MR dipublikasikan CP 95,42 vs Fastest 93,56 karena comparator final (aturan V15.24) mengutamakan inversi prioritas (17 vs 21) sebelum CP ekonomi.
- Uji Block 3 cold memakai fixture A0 dengan Block 1 & 2 Running sejak 00:00; pada fixture A0 asli (hanya Block 3 yang menopang row 1) row 1 terbukti infeasible (sertifikat EXPORT_ROW_1).
- Benchmark dan matrix dijalankan pada container 4 vCPU (worker dipin 1 CPU); angka absolut bergantung mesin.

## 7. File diubah terhadap V15.24

`index.php`, `run.php`, `saved_data_store.php`, `worker02.php`, `worker_functions.php`, `CHECKPOINT.md`, `DEPLOYMENT_MANIFEST.md`, `SHA256SUMS.txt`. Harness/evidence: `harness/v1525/` (matrix, UI, yesterday, bench, pack, evidence).

## 8. Paket & clean-install smoke

Paket (release candidate, BUKAN FINAL/VALIDATED karena gate runtime): `XAMPP_V1525_RC_RUNTIME_GATE_OPEN_PATCH.zip`, `XAMPP_V1525_RC_RUNTIME_GATE_OPEN_FULL_DEPLOYMENT.zip`, `XAMPP_V1525_RC_RUNTIME_GATE_OPEN_DOCUMENTATION.zip`; SHA-256 di `SHA256SUMS_ZIP.txt`.
Verifikasi `harness/v1525/pack/pack.sh`: lint PHP 7.4 seluruh PHP runtime, secret scan, FULL tanpa `config/db.php` / `jobs/` / `saved/` / `input_data.json` / harness / output job.
Clean install `harness/v1525/pack/clean_smoke.sh` (bukti `harness/v1525/evidence/clean_install/`): unzip ke direktori baru -> `sha256sum -c SHA256SUMS.txt` OK -> lint ZIP OK -> database MySQL baru dari `database/schema.sql` -> `report_status` schema `pp-report-mysql-1` OK -> `index.php` HTTP 200 -> `report_yesterday` NO_DATA (DB kosong) -> run A0 dari ZIP (PHP 7.4.33, 1 CPU): FASTEST_FINAL, CP 93,4567, 38,25 s.
