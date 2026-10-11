# Emergency Min Flow PGN via Fixed Flow JBBK Redistribution (Addendum §5, R2)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — redistribusi hanya dijalankan setelah lever legal lain habis; pengurangan minimal, water-filling prorata, Σ Fixed Flow tetap |
| Validation | **PASS** (Min Flow 26) / sertifikat terminal (Min Flow 45: envelope tidak cukup) |
| Quality | PASS — CP Min Flow 26 = golden (79,9047 vs 79,9049) |
| Performance | PASS — ≤ 3 iterasi; 9,9 s (26) / 15,1 s (45) |
| Release blocker | Tidak ada |

## Hasil
| Kasus | Wall (s) | Hard | Gate | CP | HR | C1–C4 FAIL | STG | Kontinuitas Dist. | Cache warm |
|---|---|---|---|---|---|---|---|---|---|
| R2 Min Flow 26 MMSCFD | 9.87 | PASS | PASS | 79.9047 | 8239.86 | 0 | PASS | PASS | MISS |
| R2 Min Flow 45 MMSCFD | 13.76 | VALID-INFEASIBLE | FAIL | 80.8225 | 8278.33 | 0 | PASS | PASS | MISS |

### Min Flow 26 — audit redistribusi
```json
{
 "schema": "v1517-ff-jbbk-redistribution-v1",
 "status": "APPLIED",
 "min_pgn_flow_mmscfd": 26,
 "epsilon_mmscfd": 0.01,
 "source_rows": [
  {
   "row": 9,
   "flow_pgn_sebelum": 25.5309,
   "ff_original": 40,
   "reduction": 0.46133,
   "ff_adjusted": 39.53867
  },
  {
   "row": 10,
   "flow_pgn_sebelum": 22.8007,
   "ff_original": 40,
   "reduction": 3.09044,
   "ff_adjusted": 36.90956
  },
  {
   "row": 11,
   "flow_pgn_sebelum": 24.03,
   "ff_original": 40,
   "reduction": 1.90667,
   "ff_adjusted": 38.09333
  },
  {
   "row": 12,
   "flow_pgn_sebelum": 24.4385,
   "ff_original": 40,
   "reduction": 1.51333,
   "ff_adjusted": 38.48667
  },
  {
   "row": 13,
   "flow_pgn_sebelum": 25.6696,
   "ff_original": 40,
   "reduction": 0.32778,
   "ff_adjusted": 39.67222
  }
 ],
 "recipient_rows": [
  {
   "row": 1,
   "delta": 0.16976,
   "cap": 3.86933
  },
  {
   "row": 2,
   "delta": 0.16976,
   "cap": 4.06956
  },
  {
   "row": 3,
   "delta": 0.16976,
   "cap": 4.60022
  },
  {
   "row": 4,
   "delta": 0.16976,
   "cap": 4.60022
  },
  {
   "row": 5,
   "delta": 0.16976,
   "cap": 3.47244
  },
  {
   "row": 6,
   "delta": 0.16976,
   "cap": 2.46733
  },
  {
   "row": 7,
   "delta": 0.16976,
   "cap": 1.76889
  },
  {
   "row": 8,
   "delta": 0.16976,
   "cap": 1.11756
  },
  {
   "row": 14,
   "delta": 0.16976,
   "cap": 4.99822
  },
  {
   "row": 15,
   "delta": 0.16976,
   "cap": 12.10467
  },
  {
   "row": 16,
   "delta": 0.16976,
   "cap": 16.87244
  },
  {
   "row": 17,
   "delta": 0.16976,
   "cap": 20.66867
  },
  {
   "row": 18,
   "delta": 0.16976,
   "cap": 23.63289
  },
  {
   "row": 19,
   "delta": 0.16976,
   "cap": 24.42867
  },
  {
   "row": 20,
   "delta": 0.16976,
   "cap": 23.322
  },
  {
   "row": 21,
   "delta": 0.16976,
   "cap": 23.90933
  },
  {
   "row": 22,
   "delta": 0.16976,
   "cap": 24.82467
  },
  {
   "row": 23,
   "delta": 0.16976,
   "cap": 25.06778
  },
  {
   "row": 24,
   "delta": 0.16976,
   "cap": 18.30556
  },
  {
   "row": 25,
   "delta": 0.16976,
   "cap": 11.45956
  },
  {
   "row": 26,
   "delta": 0.16976,
   "cap": 17.59044
  },
  {
   "row": 27,
   "delta": 0.16976,
   "cap": 25.47756
  },
  {
   "row": 28,
   "delta": 0.16976,
   "cap": 26.83333
  },
  {
   "row": 29,
   "delta": 0.16976,
   "cap": 25.83511
  },
  {
   "row": 30,
   "delta": 0.16976,
   "cap": 25.83511
  },
  {
   "row": 31,
   "delta": 0.16976,
   "cap": 24.42867
  },
  {
   "row": 32,
   "delta": 0.16976,
   "cap": 20.34467
  },
  {
   "row
```

### Min Flow 45 — bukti terminal
```json
{
 "schema": "v1517-ff-jbbk-redistribution-v1",
 "status": "TERMINAL_ENVELOPE_TIDAK_CUKUP",
 "min_pgn_flow_mmscfd": 45,
 "epsilon_mmscfd": 0.01,
 "source_rows": [
  {
   "row": 1,
   "flow_pgn_sebelum": 30.0189,
   "ff_original": 40,
   "reduction": 14.43585,
   "ff_adjusted": 25.56415
  },
  {
   "row": 2,
   "flow_pgn_sebelum": 30.2342,
   "ff_original": 40,
   "reduction": 14.22852,
   "ff_adjusted": 25.77148
  },
  {
   "row": 3,
   "flow_pgn_sebelum": 30.7872,
   "ff_original": 40,
   "reduction": 13.69607,
   "ff_adjusted": 26.30393
  },
  {
   "row": 4,
   "flow_pgn_sebelum": 30.7872,
   "ff_original": 40,
   "reduction": 13.69607,
   "ff_adjusted": 26.30393
  },
  {
   "row": 5,
   "flow_pgn_sebelum": 29.6123,
   "ff_original": 40,
   "reduction": 14.82741,
   "ff_adjusted": 25.17259
  },
  {
   "row": 6,
   "flow_pgn_sebelum": 28.6659,
   "ff_original": 40,
   "reduction": 15.73874,
   "ff_adjusted": 24.26126
  },
  {
   "row": 7,
   "flow_pgn_sebelum": 27.8001,
   "ff_original": 40,
   "reduction": 16.57252,
   "ff_adjusted": 23.42748
  },
  {
   "row": 8,
   "flow_pgn_sebelum": 27.1182,
   "ff_original": 40,
   "reduction": 17.22919,
   "ff_adjusted": 22.77081
  },
  {
   "row": 9,
   "flow_pgn_sebelum": 25.4841,
   "ff_original": 40,
   "reduction": 18.80274,
   "ff_adjusted": 21.19726
  },
  {
   "row": 10,
   "flow_pgn_sebelum": 22.7483,
   "ff_original": 40,
   "reduction": 21.43719,
   "ff_adjusted": 18.56281
  },
  {
   "row": 11,
   "flow_pgn_sebelum": 23.9776,
   "ff_original": 40,
   "reduction": 20.25341,
   "ff_adjusted": 19.74659
  },
  {
   "row": 12,
   "flow_pgn_sebelum": 24.3898,
   "ff_original": 40,
   "reduction": 19.85652,
   "ff_adjusted": 20.14348
  },
  {
   "row": 13,
   "flow_pgn_sebelum": 25.6154,
   "ff_original": 40,
   "reduction": 18.6763,
   "ff_adjusted": 21.3237
  },
  {
   "row": 14,
   "flow_pgn_sebelum": 31.1518,
   "ff_original": 40,
   "reduction": 13.34496,
   "ff_adjusted": 26.65504
  },
  {
   "row": 15,
   "flow_p
```
Min Flow 45 MMSCFD tidak dapat dipenuhi pada 34 row (Flow PGN RT terendah 30,019 MMSCFD) bahkan setelah redistribusi maksimum yang legal — keputusan kuota/operator diperlukan; Σ Fixed Flow tidak diubah.
