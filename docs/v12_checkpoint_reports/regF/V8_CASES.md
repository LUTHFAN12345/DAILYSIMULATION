# V8 targeted — reproducer, gas GE, unit priority

| id | uji | hasil | bukti |
|---|---|---|---|
| 1 | T1 reproducer Daily_Plan_09_Jul_26_Baru: FINAL 48 row, hard PASS, Export 48/48, residual 0 | PASS | wall 23.66 s, CP 64.3644 |
| 2 | T2 kuota MM2100 0 + fixed/actual MM2100 0: GE1-GE4 = 0 MW, provenance PASS (sumber NONE), MM2100 Daily Used 0 | PASS | GE rows 0, sumber NONE, used 0 |
| 3 | T2b dispatch V7 (GE1 3 MW row 1, kuota KP72 0) ditolak validator V8: mm2100_gas_source | PASS | status FAIL pelanggaran fuel_account,mm2100_gas_source |
| 4 | T4 unit prioritas rendah start lalu kebutuhan turun: stop pada row legal pertama (G5 = minimum runtime 12 row, G2 tidak running) | PASS | G2 0 row; G5 row 27-38 (V7: G2 row 26-48 = 23 row) |
| 5 | T5 headroom G1/G8/G9 dipakai sebelum unit prioritas rendah: tanpa G5, G1/G8/G9/BBLN di Effective Max pun Export di bawah Range Min (bukti kapasitas) | PASS | DECOMMIT:G5:27-38: row 28 Export maks 14.00 < Range Min 25.0 MW |
| 6 | T5b CP lebih rendah dari incumbent V7 dan kandidat pembanding tercatat (incumbent, G2 off/trunc, G5 swap, delay) | PASS | CP V7 64.5741 -> V8 64.3644; diterapkan SWAP:G2>G5:26-37, DELAY:G5:26-37:@27, POLISH_UNIT_PRIORITY_LANJUTAN |
| 7 | T7 alasan terhalang Export (reserve/Export/Bus Flow) tercatat per row; Headroom/Unit Priority PASS_WITH_REASON | PASS | PASS_WITH_REASON, tanpa alasan 0, alasan EXPORT=YA |
| 8 | T3 kuota MM2100 0 + fixed flow manual MM2100 sah (row 1-2): GE hanya pada row bersumber, provenance jelas, gas tidak melebihi sumber, biaya gas dihitung | PASS | GE row [1], sumber MANUAL_FIXED_FLOW_MM2100, row gagal 0, biaya gas MM2100 100.2 USD, Heat Rate MM2100 17813.33 |
| 9 | T3b kuota KP72 2,2 sah: GE berbeban, heat rate dan cost MM2100 dihitung, provenance KP72 PASS | PASS | GE1 48 row, HR MM2100 7537.38, CP MM2100 56.5303, used 2.199/2.2 |
| 10 | T6 headroom terhalang ramp (Fixed Load G8/G9 76 MW row 24-27): alasan RAMP per row, FINAL, PASS_WITH_REASON | PASS | PASS_WITH_REASON, tanpa alasan 0, contoh RAMP_LIMIT_TETANGGA / RAMP_LIMIT_TETANGGA, CP 64.9301 |
| 11 | T8 kopling blok STG (G1 stop 10:00, S2 continuous): alasan STG/blok per row, FINAL, PASS_WITH_REASON | PASS | PASS_WITH_REASON, tanpa alasan 0, contoh SWAP:FORCED_STATUS(LAST_DATA_RUNNING_G1_WAJIB_BERBEBAN_ROW_1) / SWAP:FORCED_STATUS(PEER_G1_TIDAK_TERSEDIA_ROW_26), CP 64.5005 |
| 12 | T9 deterministik: reproducer dijalankan ulang di akar baru -> 48 row identik | PASS | cfe07f3de3494b5caf1e6f805f8e4fc0 / cfe07f3de3494b5caf1e6f805f8e4fc0 |

**12/12 PASS**
