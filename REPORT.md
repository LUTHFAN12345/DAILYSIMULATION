# REPORT V15.24 — Order-Independent Commitment Tightening, Final-Dispatch Event Proof, 192+ Regression

Engine build: `V15.24-ORDER-INDEPENDENT-TIGHTENING-FINAL-PROOF-20261017`. Baseline paket: `XAMPP_START_PRIORITY_RC_V1523_COMMITMENT_TIGHTENING.zip` (V15.23) di atas V15.22.
Platform acceptance: **PHP 7.4.33** (opcache CLI aktif), engine dikunci ke **1 logical CPU** (`taskset -c 0`, dibuktikan `Cpus_allowed_list` proses worker). Hasil multi-core dipisahkan (bagian helper-failure).

## 1. Skenario aktual (A0, snapshot user) — sebelum / sesudah

| versi | CP (USD/MWh) | HR (BTU/kWh) | Distillate (l) | Gas (BBTUD) | event start/stop |
|---|---|---|---|---|---|
| V15.22 (PHP 7.4, 1 CPU) | 105.8901 | 8575.04 | 318214 | 65.4547 | G1+10:00 G1-17:30 G5+07:30 G5-19:30 G6+01:00 G9+01:00 S1+04:30 |
| V15.24 (PHP 7.4, 1 CPU) | 93.4567 | 8481.93 | 208545 | 65.4567 | G3+05:30 G3-17:00 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+09:00 |

V15.23 pada PHP 8.3 multi-core: CP 93,4422 (commitment identik dengan V15.24; selisih CP 0,0145 = perbedaan floating point PHP 7.4 vs 8.3 pada commitment yang sama).

### Bukti event dispatch FINAL A0 (status **TERBUKTI**, 25 evaluasi redispatch, 4.921 s)

| unit | event | jam | alasan | first-needed | defisit MW tanpa unit (sesudah legal headroom unit lain) | uji tunda/stop 1 row | swap same-block | swap cross-block |
|---|---|---|---|---|---|---|---|---|
| G3 | START | 05:30 | DIPERLUKAN_START_PALING_LAMBAT_LEGAL:export_range | 08:30 | 3.48 | TIDAK_VALID export_range: row 18: Export 2.83 < RangeMin 30 | G4:VALID_TIDAK_LEBIH_BAIK | G1:TIDAK_VALID |
| G3 | STOP | 17:00 | STOP_PALING_AWAL_LEGAL | - | - | TIDAK_VALID export_range: row 32: Export 11.32 < RangeMin 30 | - | - |
| G5 | START | 07:30 | DIPERLUKAN_START_PALING_LAMBAT_LEGAL:export_range | 08:30 | 22.39 | TIDAK_VALID export_range: row 17: Export 3.25 < RangeMin 30; export_range: row 27: Export 28.95 < RangeMin 30 | G1:TIDAK_VALID | - |
| G5 | STOP | 18:30 | STOP_PALING_AWAL_LEGAL | - | - | TIDAK_VALID export_range: row 36: Export 10.18 < RangeMin 15 | - | - |
| G6 | START | 10:00 | REQUIRED_START_PALING_LAMBAT_LEGAL:export_range | 10:30 | 11.77 | TIDAK_VALID export_range: row 21: Export 27.51 < RangeMin 30; export_range: row 22: Export 25.51 < RangeMin 30 | G4:TIDAK_VALID | G1:TIDAK_VALID |
| G9 | START | 05:00 | REQUIRED_START_PALING_LAMBAT_LEGAL:export_range | 06:30 | 12.37 | TIDAK_VALID export_range: row 15: Export -13.2 < RangeMin 15; export_range: row 16: Export 0.62 < RangeMin 15 | - | - |

Interval beban minimum: G3 05:30–08:30 STARTUP_SEQUENCE (defisit maks bila dilepas 3.48 MW); G3 12:30–16:30 DIPERLUKAN_KAPASITAS_EXPORT_MIN (defisit maks bila dilepas 29.61 MW); G5 07:30–08:00 STARTUP_SEQUENCE (defisit maks bila dilepas 0 MW); G5 12:00–13:30 DIPERLUKAN_KAPASITAS_EXPORT_MIN (defisit maks bila dilepas 2.5 MW); G5 17:00–18:00 DIPERLUKAN_KAPASITAS_EXPORT_MIN (defisit maks bila dilepas 27.17 MW); G6 10:00–13:00 STARTUP_SEQUENCE (defisit maks bila dilepas 14.89 MW); G6 00:00–00:00 DIPERLUKAN_KAPASITAS_EXPORT_MIN (defisit maks bila dilepas 7.69 MW); G9 05:00–07:00 STARTUP_SEQUENCE (defisit maks bila dilepas 50.7 MW)

## 2. Matrix regresi (Fastest; PHP 7.4.33, worker dipin 1 CPU per run)

| grup | jumlah | PASS | EXPECTED_REJECTION | FAIL | Test ID FAIL |
|---|---|---|---|---|---|
| A | 32 | 31 | 1 | 0 | - |
| B | 30 | 29 | 1 | 0 | - |
| C | 16 | 11 | 5 | 0 | - |
| D | 30 | 24 | 6 | 0 | - |
| E | 24 | 14 | 5 | 5 | T124, T127, T128, T129, T130 |
| F | 18 | 9 | 4 | 5 | T134, T135, T136, T137, T139 |
| G | 14 | 6 | 5 | 3 | T154, T161, T164 |
| H | 14 | 8 | 5 | 1 | T178 |
| J | 7 | 7 | 0 | 0 | - |
| X | 22 | 21 | 1 | 0 | - |
| I (+H15/H16, MySQL/UI) | 14 | 14 | 0 | 0 | - |
| UI semantic smoke (Playwright) | 26 | 26 | 0 | 0 | - |

**Total engine+DB: 221 test — PASS 174, EXPECTED_REJECTION 33, FAIL 14** (+26 UI smoke).

EXPECTED_REJECTION (bukti terminal per test):

- T028 Konfigurasi Initial Stop + Simulation + Hold dipindahkan ke G8 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada b
- T043 Seluruh feeder Block 1 Stop -> input ditolak / Block 1 tidak dapat dipenuhi — INPUT_CONTRADICTION:BLOCK_REQUIRED_CONTRADICTORY_INPUT
- T067 Distillate hanya pada unit prioritas pertama bila cukup (shortage kecil) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 60.000 l (2.1617 BBTUD); sisa 9.3066 BBTUD > batas atas penu
- T069 Distillate level 30% dari batas user — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 99.240 l (3.5755 BBTUD); sisa 7.8928 BBTUD > batas atas penu
- T070 Distillate level 50% dari batas user — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 165.400 l (5.9592 BBTUD); sisa 5.5091 BBTUD > batas atas pen
- T077 LNG lebih ekonomis dibanding Distillate (action recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T078 Kombinasi LNG + Distillate diperlukan (IE +20, action recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T086 LNG quota turun (-4) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.4683 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 3.5500 BBTUD > batas atas pe
- T102 Gas Shortage dengan LNG + Distillate (IE +20, recommendation) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T103 Kapasitas LNG tidak cukup (add_lng 1 BBTUD, shortage aktual) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: sesudah LNG yang diotorisasi 1.0000 BBTUD masih tersisa kebutuhan 10.1919 BBTUD > batas atas penurunan gas yang masih dapat dipaka
- T104 Kapasitas Distillate tidak cukup (limit 20000 l) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 11.4683 BBTUD > plafon distillate user 20.000 l (0.7206 BBTUD); sisa 10.7477 BBTUD > batas atas pen
- T105 Terminal fuel infeasible (IE +20, distillate limit aktual) [A7] — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 17.8010 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 5.8827 BBTUD > batas atas pe
- T106 Stale fuel-decision cache tidak dipakai (action none setelah distillate) — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS
- T116 Follow PV naik tajam — RESERVE_INFEASIBILITY_CERTIFICATE
- T117 Follow PV turun tajam — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 13.6230 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 1.7047 BBTUD > batas atas pe
- T122 SR Effective Minimum tinggi (40 MW) — RESERVE_INFEASIBILITY_CERTIFICATE
- T125 Export Range ketat (min 40 sepanjang hari) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 16.0082 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 4.0899 BBTUD > batas atas pe
- T132 Adjustment beberapa unit + Bus Flow boundary (G2 26, G8 90; busflow_min 20) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_FUEL_CAP: kebutuhan bahan bakar terbukti 15.9900 BBTUD > plafon distillate user 330.800 l (11.9183 BBTUD); sisa 4.0717 BBTUD > batas atas pe
- T133 Actual PGN lebih tinggi dari simulasi (row 1-8) — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T140 Historical actual immutable (actual row 1-8 tercermin di output) — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T141 Current/future planning rows adjustable (row 9+ tanpa actual) — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T150 Save Actual Gas draft/final dan Monitoring reload exact [engine payload] — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T152 Trip Babelan unit 2 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, G8 105 MW, S2 16.03 MW, S3 57.
- T153 Trip G8 — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada b
- T155 Trip unit prioritas tertinggi GT (G8, plus B2 tetap) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_EXPORT_ROW_1: Row 1 (05-Oct-26 00:30): unit yang dapat berbeban hanya Last Data Running & tersedia (G2 31 MW, B2 120 MW, S2 16.03 MW) pada b
- T162 Change Over tidak legal (Block 2 stop 01:00 sebelum Block 1 siap) — INPUT_CONTRADICTION:CHANGE_OVER_TIDAK_LEGAL:WAKTU_MANUAL_TERLALU_RAPAT
- T163 Ramp dan reserve setelah trip (G8 trip 12:00, SR 10) — RESERVE_INFEASIBILITY_CERTIFICATE
- T171 Fix di atas MAX ditolak (G2 40 MW) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G2 row 20 = 40 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (1 pelanggaran te
- T172 Fix di bawah MIN ditolak (G2 5 MW) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G2 row 20 = 5 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (2 pelanggaran ter
- T173 Fix pada unit Trip/Unavailable ditolak (G5 trip + fix) — 
- T175 Fix pada row setelah Stop Request (G5 stop 12:00, fix row 30) — TERMINAL_INFEASIBLE:TERMINAL_INFEASIBLE_USER_FIXED_LOAD: Dengan 1 Fixed Load user (G5 row 30 = 25 MW), rencana 48 row tidak dapat memenuhi seluruh hard constraint (4 pelanggaran te
- T177 Fix + Actual Gas lebih tinggi — GAS_WINDOW_CONFLICT_PROOF_OPERATOR_DECISION
- T194 Stale preliminary/recommendation basis ditolak (aksi recommendation sesudah distillate, app hangat) = run dingin — FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS

FAIL (akar masalah di bagian 6):

- T124 [E] PLN Export Priority susunan kedua (Daily target required 1800) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 1: Export 15.4 < RangeMin 72.04 Recommended action: Keep the source block running longer or increase legal o
- T127 [E] Daily Export tepat target (target 1500 required) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 1: Export 15.4 < RangeMin 59.07 Recommended action: Keep the source block running longer or increase legal o
- T128 [E] Daily Export di bawah target perlu koreksi (target 2600 required) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 1: Export 15.4 < RangeMin 106.6 Recommended action: Keep the source block running longer or increase legal o
- T129 [E] Daily Export infeasible dengan terminal proof (target 6000 required) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 1: Export 15.4 < RangeMin 155 Recommended action: Keep the source block running longer or increase legal out
- T130 [E] Dev Dispatch positif dan negatif (rule -10..+10 row 10-30) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 10: Export 118.94 > RangeMax 100 Recommended action: Keep the source block running longer or increase legal 
- T134 [F] Actual PGN lebih rendah (row 1-8) — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=62.2954 di luar window [65.4200,65.4600] (deviasi 3.124
- T135 [F] Actual Fixed Flow Jababeka lebih tinggi (row 1-8) — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=69.0145 di luar window [65.4200,65.4600] (deviasi 3.554
- T136 [F] Actual Fixed Flow Jababeka lebih rendah — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=67.3665 di luar window [65.4200,65.4600] (deviasi 1.906
- T137 [F] Actual MM2100 lebih tinggi — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=65.6038 di luar window [65.4200,65.4600] (deviasi 0.143
- T139 [F] Kombinasi ketiga Actual Gas — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=65.6421 di luar window [65.4200,65.4600] (deviasi 0.182
- T154 [G] Trip G9 — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 17: Export 19.32 < RangeMin 30 Recommended action: Keep the source block running longer or increase legal ou
- T161 [G] Change Over legal (Block 1 start 08:00, Block 2 stop 12:00) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 21: Export 19.89 < RangeMin 30 Recommended action: Keep the source block running longer or increase legal ou
- T164 [G] Gas/Export/Bus Flow setelah trip (G9 trip + busflow_min 20) — NG tidak dipublikasikan; terminal proof: None; status None; msg PLN Export is outside the configured range. row 17: Export 19.32 < RangeMin 30 Recommended action: Keep the source block running longer or increase legal ou
- T178 [H] Fix + Actual Gas lebih rendah — NG tidak dipublikasikan; terminal proof: None; status None; msg Gas consumption remains outside the target window after bounded automatic correction. Total Gas Used=62.2778 di luar window [65.4200,65.4600] (deviasi 3.142

Payload engine unik: 154 dari 207 test engine (test berpayload sama memakai oracle berbeda; lihat MATRIX_CHANGELOG.md).

## 3. Benchmark P0.4 (PHP 7.4.33, 1 logical CPU, 3 cold + 3 warm)

Topologi: CPU(s):                                  4 / On-line CPU(s) list:                     0-3 / Model name:                              Intel(R) Xeon(R) Processor @ 2.10GHz / Thread(s) per core:                      1 / Core(s) per socket:                      4 / Socket(s):                               1
Kuota cgroup: /sys/fs/cgroup/cpu/cpu.cfs_quota_us=-1, /sys/fs/cgroup/cpu/cpu.cfs_period_us=100000; affinity worker: `taskset -c 0` -> `Cpus_allowed_list: 0`; PHP 7.4.33 (cli) (built: Oct 17 2026 13:10:59) ( NTS ); {"opcache.enable_cli":"1","zts":0,"pcntl":true,"memory_limit":"1024M"}; helper_slots = 0 pada 1 CPU (cores_detected memakai affinity).

Definisi: cold = cache job/basis/bundle dikosongkan + proses PHP baru; warm = cache dipertahankan, job baru untuk payload sama (dilayani registry hasil); exact rerun = payload identik; Fix Load warm = satu sel Fix Load sesudah run A0 (cache basis).

| skenario | mode | target s | V15.24 wall median (min–max) | V15.24 CPU s | V15.24 RSS MB | V15.22 wall median (min–max) | V15.22 CPU s | V15.22 RSS MB | CP V15.24 / V15.22 | deterministik |
|---|---|---|---|---|---|---|---|---|---|---|
| normal Fastest | cold | 30 | 36.02 (35.8–37.44) | 35.59 | 454.0 | 71.48 (70.47–71.73) | 57.27 | 395.4 | [93.4567] / [105.8901] | True |
| normal Fastest | warm | 30 | 0.55 (0.51–0.63) | 0.52 | 38.9 | 5.32 (5.28–5.65) | 5.24 | 57.7 | [93.4567] / [105.8901] | True |
| gas-cukup (mandatory window) | cold | 40 | 77.25 (76.31–81.21) | 76.33 | 555.0 | 87.2 (86.63–95.16) | 68.23 | 468.1 | [68.4413] / [68.4413] | True |
| gas-cukup (mandatory window) | warm | 40 | 2.64 (2.49–2.74) | 2.6 | 67.3 | 4.11 (4.09–4.18) | 2.55 | 66.1 | [68.4413] / [68.4413] | True |
| shortage/LNG | cold | 40 | 51.46 (49.46–53.51) | 50.61 | 260.9 | 115.26 (114.88–117.85) | 96.01 | 518.4 | [73.9965] / [73.9965] | True |
| shortage/LNG | warm | 40 | 0.57 (0.53–0.62) | 0.52 | 38.8 | 2.09 (2.04–2.11) | 0.53 | 38.7 | [73.9965] / [73.9965] | True |
| terminal/infeasible (IE +20) | cold | 40 | 89.86 (85.08–93.03) | 88.74 | 466.7 | 134.25 (133.89–137.52) | 96.66 | 578.9 | [122.0047] / [122.0047] | True |
| terminal/infeasible (IE +20) | warm | 40 | 22.49 (21.87–23.95) | 22.14 | 115.4 | 23.32 (22.88–24.4) | 20.05 | 82.8 | [122.0047] / [122.0047] | True |
| difficult/cross-block | cold | 40 | 41.66 (41.34–43.51) | 41.14 | 435.4 | 100.28 (98.5–101.29) | 75.06 | 317.4 | [101.7689] / [106.0179] | True |
| difficult/cross-block | warm | 40 | 0.52 (0.49–0.54) | 0.5 | 38.8 | 3.73 (3.66–3.8) | 0.65 | 38.7 | [101.7689] / [106.0179] | True |
| fuel decision required (input_data) | cold | 40 | 1.86 (1.79–2.39) | 1.81 | 55.7 | 1.88 (1.69–2.21) | 1.84 | 55.5 | [68.4541] / [68.4541] | True |
| fuel decision required (input_data) | warm | 40 | 2.08 (1.97–2.16) | 2.03 | 55.7 | 1.81 (1.77–1.86) | 1.78 | 55.4 | [68.4541] / [68.4541] | True |
| one-cell Fix Load (feasible, G8 row 8 = 95 MW) | cold | 10 | 35.03 (34.97–35.24) | 34.54 | 442.1 | 95.02 (95.02–95.02) | 69.48 | 441.5 | [93.3691] / [95.5793] | True |
| one-cell Fix Load (feasible, G8 row 8 = 95 MW) | warm | 10 | 0.66 (0.52–36.96) | 0.63 | 38.9 | 7.88 (2.12–91.47) | 7.78 | 65.6 | [93.3691] / [107.4461] | True |
| one-cell Fix Load memaksa commitment repair (G2 row 20 = 25 MW) | warm | - | 23.73 (23.73–23.73) | 23.37 | 284.7 | 77.4 (77.4–77.4) | 61.38 | 409.7 | [93.3625] / [106.0083] | True |
| exact rerun | exact | 15 | 0.5 (0.48–0.55) | 0.47 | 38.9 | 5.49 (5.39–5.77) | 5.42 | 57.6 | [93.4567] / [105.8901] | True |

## 4. Root cause dan perbaikan (generik, tanpa nama unit / fixture)

| # | Root cause (terukur) | Perbaikan | Fungsi |
|---|---|---|---|
| 1 | Commitment Tightening V15.23 menerapkan perbaikan pertama yang ditemukan (first-improvement), memotong evaluasi dengan anggaran per kandidat — hasil bergantung urutan kandidat dan anggaran. | Pencarian **best-improvement per ronde**: seluruh kandidat neighborhood dibentuk dari baseline kanonik yang sama, seluruhnya dievaluasi (redispatch, dedup sidik jari commitment) tanpa mengubah baseline, diranking comparator deterministik + sidik jari, satu pemenang diterapkan, perbaikan lain yang sel commitment-nya tidak beririsan digabung berurutan rank (masing-masing dievaluasi ulang). Anggaran hanya diperiksa di awal ronde (ronde tidak pernah terpotong). Log kandidat kanonik (terurut). Uji 3 urutan acak (`PP_V1523_SHUFFLE`) identik byte: 48 row, CP, HR, event, bukti, gate. | `pp_v1523_tighten` |
| 2 | Comparator V15.23 menaruh CP ekonomi di atas prioritas unit; urutan Bagian VII menghendaki running-unit-first / inversi prioritas / startup tak-perlu / blok baru sebelum CP. | **Comparator V15.24**: valid -> inversi running-unit-first (unit-row level L berbeban sementara legal headroom unit online berprioritas lebih tinggi >= bebannya) -> startup tak-wajib -> blok baru -> CP ekonomi (band 0,02%) -> HR -> startup -> online unit-row -> start lebih lambat -> stop lebih awal -> sidik jari. `PP_V1524_CMP=0` = comparator V15.23. | `pp_v1523_metrics`, `pp_v1523_cmp` |
| 3 | Biner penuh untuk latest legal start / earliest legal stop memboroskan evaluasi (log2 rentang per kandidat per ronde). | Pencarian **galloping** (1,2,4,… row lalu biner pada rentang terakhir) — hasil sama di bawah asumsi monoton, evaluasi O(log jarak). Penundaan unit Required yang +1 row-nya gagal HANYA pada batas bawah Export tidak diuji +2/+4/+6 (subset sel online -> pasti gagal, monoton; `PP_V1524_MONO=0` = selalu diuji, output identik — test X). BACKFILL bertingkat (+4/+6 hanya bila +2 valid & lebih baik). | `pp_v1523_tighten` |
| 4 | Bukti start/stop V15.23 dihitung pada dispatch ANTARA (sebelum Unit Priority Fix) — Unit Priority Fix dapat membuat tunda-start / stop-awal yang tadinya tidak valid menjadi valid & lebih baik (terukur T045: G9 01:00, tunda valid & CP 102,09 -> 101,16). | **Bukti event pada dispatch FINAL** (`pp_v1524_event_proof`): per start & interval beban minimum — first-needed row & defisit MW (Export tanpa unit + legal headroom GT/Babelan online lain + kopling STG proporsional), unit running (beban, min/max legal, ramp, headroom), prioritas efektif, kandidat same-block / cross-block + uji swap, status bahan bakar & window gas, margin Export/SR/BusFlow/STG, lead time, CP/CP ekonomi/HR, uji tunda 1 row (galloping bila lebih baik), uji stop 1 row lebih awal, uji DECOMMIT & GESER interval bila penundaan gagal hanya karena runtime/sequence, kode alasan. **Penutupan** (`pp_v1524_event_closure`): langkah VALID & LEBIH BAIK terbaik diterapkan, melewati Unit Priority Fix + pelengkap bukti C4 yang sama, stage guard, bukti dihitung ulang (maks 3). Dipakai jalur bundle Fastest DAN jalur warm/Maximum Review. | `pp_v1524_event_proof`, `pp_v1524_event_closure`, `pp_sp_final_bundle`, `pp_job_run_economic_review` |
| 5 | Merit C2 menolak start yang pembuktiannya hanya counterfactual penuh (start lebih awal dari lead sequence karena runtime minimum) — T034 tidak terpublikasi. | Bukti event final (tunda tidak valid + interval GESER tidak lebih baik + swap tidak lebih baik) diterima sebagai bukti numerik C2. | `pp_v15_merit_proof` |
| 6 | Basis warm IE satu per konteks, ditimpa run terakhir -> rerun profil IE yang sama memberi hasil berbeda tergantung riwayat (T111: CP 95,0865 vs 93,4567). | Basis **eksak per (konteks, profil IE efektif, Actual Gas, Fix Load)**; profil yang pernah dihitung penuh memakai basisnya sendiri (identik dengan run dingin). | `pp_v1517_ie_store`, `pp_v1517_ie_warm`, `pp_v1524_ie_exact_file` |
| 7 | Plafon bahan bakar user (distillate liter / LNG) lebih kecil dari kebutuhan terbukti -> hasil "aksi tidak diterapkan" (pratinjau non-terminal, distillate terjadwal melampaui plafon). | **TERMINAL_INFEASIBLE_FUEL_CAP** bila sisa kebutuhan > batas atas penurunan gas yang masih dapat dipakai (audit gas): bukti numerik (kebutuhan, plafon, sisa, batas audit) + opsi (plafon liter yang dibutuhkan / LNG tambahan / campuran). | `pp_v1524_fuel_cap_terminal`, `pp_attach_or_reject_acceptance`, `pp_job_run_economic_review` |
| 8 | `Effective Total Gas` (dipakai validator bila ada jam Actual) dihitung dari gas KOTOR; `Total Gas Used` NETO (dikurangi offset distillate) -> setiap Actual Gas pada skenario distillate dinilai melampaui window sebesar offset (11,48 BBTUD). | Effective Total Gas memakai basis neto yang sama. | `worker02.php` (akuntansi gas) |
| 9 | Jumlah core terdeteksi dari `/proc/cpuinfo` (4) walau proses dibatasi 1 CPU (affinity/cgroup) -> 3 helper time-slicing di 1 CPU (wall > CPU, terukur V15.22 A0 75 s wall vs 60 s CPU). | `cores = min(processor, affinity, kuota cgroup)` -> helper_slots 0 pada 1 CPU; topologi/affinity/kuota dilaporkan di `Runtime Platform Diagnostics`. | `pp_v4_cores`, `pp_v1524_cpu_limits`, `pp_v1518_runtime_diag` |
| 10 | Hasil pembantu diterima bila `count(data) > 0` (parsial dapat menjadi FINAL); pembantu hang menahan job tanpa batas. | Hasil pembantu diterima hanya bila SHA-1 sidecar cocok, tepat 48 row, info ada, tidak terpotong; selain itu ditolak (reason code) dan dihitung ulang pemilik. Tugas terkunci > max(45 s, 3x durasi tugas terlama) diambil alih pemilik (fungsi murni -> identik). Hook injeksi kegagalan khusus pembantu (`PP_V1524_FAULT`, non-aktif tanpa env). | `pp_sp_par_pipeline`, `pp_sp_cand_task`, `pp_v4_cooperate`, `pp_v1524_fault` |

File diubah terhadap V15.23: `run.php`, `worker02.php` (satu titik: basis neto Effective Total Gas). `index.php`, `weekly_ui.php`, `worker_functions.php`, `saved_data_store.php` tidak diubah. Engine build: `V15.24-ORDER-INDEPENDENT-TIGHTENING-FINAL-PROOF-20261017`.

## 5. P0 Gate

| syarat | status | bukti |
|---|---|---|
| candidate-order independence | PASS — 3 seed acak (A,B,C) pada app tanpa cache identik dengan urutan kanonik (48 row unit, CP, event, status & kode bukti); uji P0.1 terpisah: hash data/bukti/tightening/gate identik | T185–T187, `harness/v1524/matrix/evidence/T18[5-7].json` |
| comparator deterministik | PASS — cold x3, warm x3 hash dispatch identik (benchmark), shuffle identik | bagian 3, T185–T187 |
| G5/G3 bukti numerik | PASS — tabel bagian 1 (first-needed 08:30, defisit, swap G4/G1, tunda tidak valid) | `Commitment Event Proof` pada run T184 |
| gas-cukup runtime | root cause terdokumentasi (bagian 6.2); keputusan aman: dipertahankan | bagian 3 & 6.2 |
| PHP 7.4 1 CPU benar-benar diuji | PASS — seluruh matrix & benchmark PHP 7.4.33, affinity terbukti per run | `__bench.affinity` setiap evidence |
| helper failure aman | PASS — 7 skenario identik referensi serial | bagian 7 |
| semantic smoke | PASS 26/26 + semantik engine G1/G3/G4/G5 identik | bagian 8 |

## 6. Kegagalan tersisa dan keterbatasan (jujur, tidak dipalsukan)

1. **Daily Export target REQUIRED (T124, T127, T128, T129; T130 dispatch-dev)** — lift target harian dibagi sebanding headroom RangeMax menjadi lantai per row termasuk row 1 (hanya unit Last Data Running) -> row 1 Export 15,4 < lantai 59–155 MW. Identik pada V15.22 (diverifikasi). Redistribusi pasca-dispatch dicoba dan dibatalkan (pada tahap itu commitment belum diputuskan -> target turun ke 550 MWh). Perbaikan benar: target harian ikut menggerakkan commitment.
2. **Actual Gas menyimpang + aksi distillate aktif (T134–T137, T139)** — kompensasi actual tidak menyelesaikan ulang jumlah distillate dan sertifikat supplier-window tidak diterbitkan pada jalur distillate (pada skenario tanpa distillate penyimpangan yang sama menghasilkan `TERMINAL_INFEASIBLE_GAS_SUPPLIER_WINDOW` dengan bukti). V15.22 juga gagal (bahkan dengan deviasi palsu 10,8 BBTUD karena basis kotor — root cause #8 sudah diperbaiki).
3. **Trip unit besar G9 (T154, T164)** — tidak ditemukan commitment valid (row 17 Export 19,32 < 30). Identik V15.22 (hash 4f6536ff19d8). Repair generik dicoba (majukan start / start unit tersedia) — seluruh kandidat tidak valid, +30 s — dibatalkan.
4. **Runtime 1 CPU di atas target** untuk skenario normal (A0) dan beberapa skenario sulit — lihat bagian 3 (nilai aktual & bottleneck: tightening + bukti event pada redispatch core ~0,15–0,2 s/evaluasi; konsolidasi blok; review V8). Gas-cukup: CPU naik ±11 s vs V15.22 karena penilaian kandidat pada state bahan bakar (kandidat latest-legal gagal window gas wajib -> pendaratan gas + kandidat berikutnya); prescreen terbukti diuji dan tidak berlaku (deviasi 2,84 < batas audit 3,20 BBTUD) sehingga tidak dipasang.
5. **Maximum Review smoke (T207)** CP 94,4592 vs Fastest 93,4567 — jalur Maximum Review menjalankan Tightening + bukti event (TERBUKTI) tetapi tanpa konsolidasi blok dan comparator final multi-kandidat milik Fastest, sehingga berakhir pada commitment lain (G1/G6 07:30); dilaporkan transparan.
6. Pembantu lambat (uji `slow`) aman (identik) tetapi wall naik (110 s) — ambil alih memakai ambang 45 s / 3x tugas terlama.
7. Grup H (Ctrl+Click / full screen) diuji pada tingkat payload & precheck server; interaksi klik UI hanya pada smoke semantik.

### 6.1 Fix Load warm (target 10 s) — catatan benchmark
Baris "one-cell Fix Load (feasible) warm" berisi 3 run: run pertama sesudah A0 (**36,96 s**, CPU 36,54 s) adalah perhitungan sebenarnya; dua run berikutnya (0,52 / 0,66 s) dilayani registry hasil payload yang sama. Median 0,66 s karena itu **tidak** dipakai sebagai klaim: nilai acuan Fix Load warm adalah **36,96 s (target 10 s tidak tercapai)**. Root cause: jalur warm lokal (Stage A, jendela redispatch sekitar sel Fix) tidak dapat mereproduksi dispatch FINAL yang sudah melalui tightening + Unit Priority Fix + closure bukti event, sehingga Stage A ditolak comparator dan pipeline jatuh ke perbaikan penuh (Stage C). V15.22 warm 7,9 s menghasilkan CP 107,45 dan tidak deterministik (rentang 2,1–91,5 s); V15.24 menghasilkan CP 93,37 deterministik. Eksperimen perluasan jendela warm dan forced-start warm diuji dan dibatalkan (hasil berbeda dari run dingin).

### 6.2 Gas-cukup (P0.3) — root cause runtime
Cold 77,25 s wall / 76,33 s CPU (V15.22: 87,2 s wall / 68,23 s CPU). Wall turun karena helper time-slicing dihapus; CPU naik ±8 s karena penilaian kandidat pada state bahan bakar: kandidat latest-legal yang gagal window gas wajib memicu pendaratan gas (~5 s) + evaluasi kandidat berikutnya. Prescreen "kandidat pasti gagal window" diuji tetapi tidak dapat dibuktikan aman (deviasi 2,84 < batas atas audit 3,20 BBTUD), sehingga tidak dipasang — hasil (CP 68,4413) identik V15.22.

## 7. Helper failure (multi-core, DIPISAHKAN dari acceptance 1 CPU)
PHP 7.4.33 tanpa pin (4 CPU, helper aktif), payload A0, hook `PP_V1524_FAULT` hanya pada proses pembantu. Seluruh skenario memberi 48 row + CP 93,4567 + hash data/bukti/gate identik referensi serial.

| skenario | hasil | ditolak (reason) | diambil alih | mode paralel |
|---|---|---|---|---|
| referensi serial | PASS | - | - | NO_TASKS |
| normal | PASS identik | - | - | PARALLEL |
| crash | PASS identik | - | - | PARALLEL (tugas dihitung ulang pemilik) |
| hang | PASS identik | - | spcand (20 s) | PARALLEL |
| malformed | PASS identik | SIDIK_TIDAK_COCOK x2 | - | PARALLEL |
| partial (<48 row) | PASS identik | ROW_TIDAK_48 x2 | - | PARALLEL |
| satu lambat | PASS identik (wall 110 s) | - | - | PARALLEL |
| semua gagal | PASS identik | - | - | SERIAL_OR_UNPROVEN |

Bukti: `harness/v1524/evidence/helper_fault_final/summary_final.txt`, `all_final.log`.

## 8. UI semantic smoke (Playwright, Chromium) — 26/26 PASS
UI-S1 (G1/G3/G4/G5: Initial Status Stop + Commitment Mode Simulation -> tidak dapat stop, HOLD_TO_END_AFTER_START), UI-SWAP-1/2, UI-S2, UI-S3 Block Required B0–B6 ON/OFF, UI-S4 Save / Reload / tanggal lain, UI-S5 stale-cache. Semantik engine (G1/G3/G4/G5) identik UI. Bukti: `harness/v1524/evidence/ui_smoke/smoke_final.json`, `engine_semantics_final.txt`.

## 9. Lokasi evidence (paket DOCUMENTATION)
- Matrix: `harness/v1524/matrix/` — definisi `mxdefs.py`, runner `mx.py`, `MATRIX_CHANGELOG.md`, evidence per test `evidence/T*.json` (status, cek, payload hash, `__bench` affinity), grup I/H15/H16 `evidence/group_I_H15_H16.json`, `report/<grup>/report.json|csv`.
- Benchmark: `harness/v1524/bench/bench_final.md|json` (+ skrip `bench.sh`, `post.sh`, `benchrep.py`); raw JSON per run tidak dimasukkan (59 MB; dapat dibangkitkan ulang dengan `bench.sh`).
- Helper failure: `harness/v1524/evidence/helper_fault_final/`; UI: `harness/v1524/evidence/ui_smoke/`.
- Harness: `harness/v1524/runjob2.php` (runner job + pengukuran wall/CPU/RSS/affinity), `hf/`, `ui/`, `pack/pack.sh`.
