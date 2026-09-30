# V9 targeted — unit priority generik + provenance bahan bakar

| id | uji | hasil | bukti |
|---|---|---|---|
| 1 | P01 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 5.26 s, CP 63.9057, kandidat valid termurah -, audit PASS_WITH_REASON/0, provenance PASS |
| 2 | P01 headroom penuh (Export Range Min 10 MW sepanjang hari; row puncak Export maks tanpa start 14 MW): headroom G1/G8/G9 cukup -> tidak ada start GTG prioritas rendah | PASS | start GTG 0; running: G1:48 G2:0 G3:0 G4:0 G5:0 G6:0 G7:0 G8:48 G9:48 G10:0 |
| 3 | P02 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 36.74 s, CP 64.9301, kandidat valid termurah 64.9301, audit PASS_WITH_REASON/0, provenance PASS |
| 4 | P02 headroom parsial karena ramp: alasan RAMP per row | PASS | RAMP_LIMIT_TETANGGA |
| 5 | P03 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 122.5 s, CP 64.7133, kandidat valid termurah -, audit PASS_WITH_REASON/0, provenance PASS |
| 6 | P03 reserve mengikat (Spinning Reserve Min 20 MW; V8 gagal hard reserve row 17-23): REPAIR START unit prioritas tertinggi yang tersedia, alasan RESERVE per row | PASS | repair EXTEND:G2:14-27; DELAY:RESERVE / STOP:RESERVE |
| 7 | P04 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 24.87 s, CP 64.3644, kandidat valid termurah 64.3703, audit PASS_WITH_REASON/0, provenance PASS |
| 8 | P04 headroom terhalang Export (Range Min 25 MW row 17-32): bukti kapasitas + alasan EXPORT | PASS | DELAY:G5:27-38:@28: simulasi 48 row |
| 9 | P08 minimum runtime menahan unit prioritas rendah: G5 running row 27-38 dengan alasan MIN_RUNTIME | PASS | MIN_RUNTIME_COMBINED_CYCLE_SEJAK_START_ROW_27 |
| 10 | P09 unit berhenti pada row legal pertama sesudah minimum runtime (G5 12 row = 6 jam), G2 tidak running | PASS | G5 12 row, G2 0 row |
| 11 | P10 beberapa unit eligible: kandidat SWAP/STOP/DELAY/DECOMMIT dibandingkan, pemenang = CP terendah kandidat valid | PASS | kandidat valid 5, jenis SWAP,STOP,DELAY,DECOMMIT,EARLY_STOP, CP 64.3644, termurah 64.3703 |
| 12 | P05 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 70.55 s, CP 64.3725, kandidat valid termurah 64.3784, audit PASS_WITH_REASON/0, provenance PASS |
| 13 | P05 headroom terhalang Bus Flow (Bus Flow Min 180 MW; BBLN/G1 di Bus B): alasan BUS_FLOW / bukti Bus Flow tanpa unit | PASS | BUS_FLOW(Bus Flow 180.0 MW, min 180.0 MW: BB1 di Bus B tidak dapat dinaikkan) / BUS_FLOW(Bus Flow 180.0 MW, min 180.0 MW: BB2 di Bus B tidak dapat dinaikkan) |
| 14 | P06 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 37.25 s, CP 64.6644, kandidat valid termurah 64.7173, audit PASS_WITH_REASON/0, provenance PASS |
| 15 | P06 headroom/kandidat terhalang window gas (PGN 30): kandidat yang hanya gagal window gas tercatat dan tidak dipilih | PASS | SWAP:G5>G2:26-37 CP 64.6719 -> GAS_WINDOW |
| 16 | P06b FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 19.47 s, CP 65.2141, kandidat valid termurah 65.2149, audit PASS_WITH_REASON/0, provenance PASS |
| 17 | P06b Gas Shortage (PGN Pipe 26) + Add LNG 1,6: aksi bahan bakar ikut review kandidat Unit Priority (tidak dibekukan), akun LNG berbiaya | PASS | review NO_BETTER_VALID_CANDIDATE, disimulasikan 3, LNG 1.6 BBTUD, CP 65.2141 |
| 18 | P07 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 69.09 s, CP 64.5005, kandidat valid termurah 64.5078, audit PASS_WITH_REASON/0, provenance PASS |
| 19 | P07 headroom terhalang kopling STG/blok (G1 stop 10:00): alasan STG/blok per row | PASS | SWAP:FORCED_STATUS(LAST_DATA_RUNNING_G1_WAJIB_BERBEBAN_ROW_1) / SWAP:BLOCK_STG(S2_CONTINUOUS_TANPA_PEMASOK_GTG_ROW_21) |
| 20 | F1 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 25.51 s, CP 64.1989, kandidat valid termurah 64.2047, audit PASS_WITH_REASON/0, provenance PASS |
| 21 | F1 kuota KP72 2,2 (GE berbeban): setiap unit berbahan bakar punya sumber sah, akun biaya, heat rate, biaya per row | PASS | status PASS, unit-row GE 48, unit-row ber-heat-rate & biaya 204, sumber MM2100 KP72_QUOTA, identitas 0 |
| 22 | F2 FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah x 1,002 (band V11) | PASS | wall 39.47 s, CP 64.3653, kandidat valid termurah 64.3655, audit PASS_WITH_REASON/0, provenance PASS |
| 23 | F2 fixed flow manual MM2100 (kuota 0): setiap unit berbahan bakar punya sumber sah, akun biaya, heat rate, biaya per row | PASS | status PASS, unit-row GE 1, unit-row ber-heat-rate & biaya 157, sumber MM2100 MANUAL_FIXED_FLOW_MM2100, identitas -0.0133 |

**23/23 PASS**
