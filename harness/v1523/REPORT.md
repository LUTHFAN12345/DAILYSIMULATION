# V15.23 — Laporan dispatch skenario aktual (snapshot plan-2-mvc1ltkp, 05-Oct-26)

> Catatan: konteks numerik bukti (beban unit running, headroom) diambil pada tahap Commitment Tightening (sebelum Unit Priority Fix memindahkan MW antar unit dalam row); commitment (start/stop) identik dengan dispatch final. Benchmark: mesin uji 4 core, job CLI 1 proses tanpa helper.

## Ringkasan before/after

| Metrik | V15.22 | V15.23 |
|---|---|---|
| CP (USD/MWh) | 105.8901 | 93.4422 |
| HR JBBK MM (BTU/kWh) | 8575.04 | 8481.79 |
| Gas (BBTUD) | 65.4547 | 65.4592 |
| Distillate (l) | 318361.9 | 208532.7 |
| Export harian (MWh) | 837.89 | 581.71 |
| Hard validation | PASS | PASS |
| Publish | True | True |
| Jumlah startup GTG | 4 | 4 |
| Unit-row di beban minimum (≤ min+0,5) | 91 | 58 |
| Unit-row inversi prioritas statis* | 54 | 22 |
| Event start/stop | G1+10:00 G1-17:30 G5+07:30 G5-19:30 G6+01:00 G9+01:00 S1+04:30 | G3+05:30 G3-17:00 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+09:00 |
| Jam operasi | {'G1': 7.5, 'G2': 24.0, 'G5': 12.0, 'G6': 23.5, 'G8': 24.0, 'G9': 23.5} | {'G2': 24.0, 'G3': 11.5, 'G5': 11.0, 'G6': 14.5, 'G8': 24.0, 'G9': 19.5} |

*inversi statis = unit level lebih rendah online sementara headroom (max − beban, tanpa ramp/STG) unit online level lebih tinggi ≥ bebannya; indikator kasar, bukti legal ada di bagian bukti.

## Dispatch 48 row (MW)

| Row | Jam | IE | BB2 lama/baru | G8 lama/baru | G9 lama/baru | S3 lama/baru | G2 lama/baru | G5 lama/baru | G1 lama/baru | S2 lama/baru | G6 lama/baru | G4 lama/baru | G3 lama/baru | S1 lama/baru | Export lama/baru |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 00:30 | 270.8 | 120.0/120.0 | 105.0/90.5 | 0.0/0.0 | 57.7/51.5 | 31.0/20.0 | 0.0/0.0 | 0.0/0.0 | 16.0/11.1 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 51.6/15.0 |
| 2 | 01:00 | 271.8 | 115.0/120.0 | 85.0/91.5 | 40.0/0.0 | 49.1/51.9 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 5.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 42.7/15.5 |
| 3 | 01:30 | 273.4 | 105.0/120.0 | 72.0/92.5 | 40.0/0.0 | 43.6/52.3 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 15.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 22.6/15.3 |
| 4 | 02:00 | 273.2 | 95.0/120.0 | 65.0/92.5 | 50.0/0.0 | 40.6/52.3 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 17.8/15.5 |
| 5 | 02:30 | 272.0 | 85.1/120.0 | 65.0/94.5 | 60.0/0.0 | 40.6/53.2 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 19.1/19.6 |
| 6 | 03:00 | 269.4 | 75.5/120.0 | 86.5/100.0 | 60.0/0.0 | 49.8/55.5 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 42.8/30.1 |
| 7 | 03:30 | 269.1 | 80.5/120.0 | 65.0/105.0 | 70.0/0.0 | 90.9/57.7 | 20.0/21.5 | 0.0/0.0 | 0.0/0.0 | 11.1/11.8 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 77.7/39.6 |
| 8 | 04:00 | 271.2 | 90.5/120.0 | 65.0/105.0 | 65.0/0.0 | 89.0/57.7 | 20.0/29.5 | 0.0/0.0 | 0.0/0.0 | 11.1/15.4 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 0.0/0.0 | 78.8/49.1 |
| 9 | 04:30 | 269.5 | 100.5/120.0 | 65.0/93.2 | 65.0/0.0 | 89.0/52.6 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 11.2/0.0 | 101.6/20.2 |
| 10 | 05:00 | 271.7 | 110.5/120.0 | 65.0/65.0 | 65.0/40.0 | 89.0/40.6 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/0.0 | 11.2/0.0 | 109.4/15.0 |
| 11 | 05:30 | 283.4 | 120.0/120.0 | 65.0/72.0 | 65.0/40.0 | 89.0/43.6 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/5.0 | 11.2/0.0 | 107.2/17.5 |
| 12 | 06:00 | 295.4 | 120.0/120.0 | 65.0/73.9 | 65.0/50.0 | 89.0/44.4 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/15.0 | 11.2/0.0 | 95.2/28.2 |
| 13 | 06:30 | 324.9 | 120.0/120.0 | 65.0/83.0 | 65.0/60.0 | 89.0/48.3 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/20.0 | 11.2/0.0 | 65.8/26.8 |
| 14 | 07:00 | 364.9 | 120.0/120.0 | 65.2/105.0 | 71.2/60.0 | 91.5/57.7 | 20.0/28.5 | 0.0/0.0 | 0.0/0.0 | 11.1/14.9 | 20.0/0.0 | 0.0/0.0 | 0.0/20.0 | 11.2/0.0 | 34.6/30.5 |
| 15 | 07:30 | 416.4 | 120.0/120.0 | 78.7/99.2 | 78.7/70.0 | 99.2/103.6 | 20.0/20.0 | 5.0/5.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/20.0 | 11.2/0.0 | 16.0/21.0 |
| 16 | 08:00 | 466.6 | 120.0/120.0 | 94.2/97.5 | 92.2/98.9 | 110.0/113.8 | 20.0/20.0 | 15.0/15.0 | 0.0/0.0 | 11.1/11.1 | 20.0/0.0 | 0.0/0.0 | 0.0/20.0 | 11.2/0.0 | 15.8/18.3 |
| 17 | 08:30 | 510.6 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 23.0/31.0 | 20.0/21.0 | 0.0/0.0 | 33.9/31.7 | 20.0/0.0 | 0.0/0.0 | 0.0/20.0 | 11.2/0.0 | 34.8/30.4 |
| 18 | 09:00 | 538.7 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 21.5/21.5 | 0.0/0.0 | 31.9/31.9 | 31.0/0.0 | 0.0/0.0 | 0.0/31.0 | 16.7/16.7 | 30.7/30.7 |
| 19 | 09:30 | 549.4 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 29.0/29.0 | 0.0/0.0 | 34.6/34.6 | 31.0/0.0 | 0.0/0.0 | 0.0/31.0 | 16.7/16.7 | 30.2/30.2 |
| 20 | 10:00 | 549.7 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 31.0/26.1 | 5.0/0.0 | 35.3/33.5 | 29.5/5.0 | 0.0/0.0 | 0.0/31.0 | 15.9/16.7 | 34.6/30.1 |
| 21 | 10:30 | 559.0 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 31.0/25.9 | 15.0/0.0 | 35.3/33.5 | 31.0/15.0 | 0.0/0.0 | 0.0/31.0 | 16.7/16.7 | 37.5/30.6 |
| 22 | 11:00 | 571.0 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 31.0/23.2 | 20.0/0.0 | 51.0/32.5 | 31.0/20.0 | 0.0/0.0 | 0.0/27.0 | 16.7/31.0 | 46.3/30.3 |
| 23 | 11:30 | 571.5 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 30.6/31.0 | 20.0/25.0 | 20.0/0.0 | 47.5/33.1 | 31.0/20.0 | 0.0/0.0 | 0.0/26.0 | 16.7/30.3 | 30.8/30.4 |
| 24 | 12:00 | 537.4 | 120.0/120.0 | 98.0/105.0 | 87.0/95.5 | 109.5/115.3 | 31.0/20.0 | 20.0/20.0 | 20.0/0.0 | 47.8/31.6 | 31.0/20.0 | 0.0/0.0 | 0.0/27.0 | 16.7/31.0 | 31.4/35.7 |
| 25 | 12:30 | 479.9 | 120.0/120.0 | 80.8/82.0 | 73.8/82.0 | 98.2/101.7 | 31.0/20.0 | 20.0/20.0 | 20.0/0.0 | 47.8/31.6 | 20.0/20.0 | 0.0/0.0 | 0.0/20.0 | 11.2/25.8 | 30.4/30.9 |
| 26 | 13:00 | 519.1 | 120.0/120.0 | 89.3/105.0 | 81.8/89.0 | 104.4/112.8 | 31.0/20.0 | 20.0/20.0 | 20.0/0.0 | 47.8/31.6 | 31.0/20.0 | 0.0/0.0 | 0.0/20.0 | 16.7/25.8 | 30.7/32.8 |
| 27 | 13:30 | 561.7 | 120.0/120.0 | 105.0/105.0 | 97.6/105.0 | 116.0/118.8 | 31.0/31.0 | 20.0/20.0 | 20.0/0.0 | 47.8/31.4 | 31.0/24.0 | 0.0/0.0 | 0.0/20.0 | 16.7/28.8 | 31.2/30.0 |
| 28 | 14:00 | 579.0 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 25.4/31.0 | 20.0/0.0 | 49.4/35.3 | 31.0/26.0 | 0.0/0.0 | 0.0/20.0 | 16.7/30.3 | 31.0/31.1 |
| 29 | 14:30 | 581.8 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 27.4/31.0 | 20.0/0.0 | 50.0/35.3 | 31.0/28.0 | 0.0/0.0 | 0.0/20.0 | 16.7/31.4 | 30.8/31.4 |
| 30 | 15:00 | 584.3 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 29.0/31.0 | 20.0/0.0 | 50.5/35.3 | 31.0/30.0 | 0.0/0.0 | 0.0/20.0 | 16.7/32.0 | 30.4/31.5 |
| 31 | 15:30 | 581.4 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 27.0/31.0 | 20.0/0.0 | 49.9/35.3 | 31.0/28.0 | 0.0/0.0 | 0.0/20.0 | 16.7/31.4 | 30.7/31.8 |
| 32 | 16:00 | 571.0 | 120.0/120.0 | 105.0/105.0 | 105.0/105.0 | 118.8/118.8 | 31.0/31.0 | 20.0/21.0 | 20.0/0.0 | 47.8/31.7 | 30.4/30.0 | 0.0/0.0 | 0.0/20.0 | 16.4/32.0 | 31.2/31.3 |
| 33 | 16:30 | 556.5 | 120.0/120.0 | 93.5/104.5 | 93.4/104.6 | 110.2/118.5 | 28.0/31.0 | 28.0/24.0 | 20.0/0.0 | 49.3/32.8 | 27.0/31.0 | 0.0/0.0 | 0.0/20.0 | 14.7/32.3 | 15.3/49.9 |
| 34 | 17:00 | 547.7 | 120.0/120.0 | 97.5/105.0 | 90.5/105.0 | 110.6/118.8 | 28.0/28.0 | 28.0/20.0 | 20.0/0.0 | 49.3/30.3 | 20.0/31.0 | 0.0/0.0 | 0.0/0.0 | 11.2/16.7 | 15.1/15.6 |
| 35 | 17:30 | 535.7 | 120.0/120.0 | 105.0/105.0 | 105.0/104.0 | 118.8/118.4 | 20.0/20.0 | 20.0/20.0 | 0.0/0.0 | 31.6/31.6 | 30.5/31.0 | 0.0/0.0 | 0.0/0.0 | 16.4/16.7 | 20.1/19.5 |
| 36 | 18:00 | 522.6 | 120.0/120.0 | 105.0/105.0 | 95.0/94.7 | 115.1/115.0 | 20.0/20.0 | 20.0/20.0 | 0.0/0.0 | 31.6/31.6 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 20.2/19.8 |
| 37 | 18:30 | 486.6 | 120.0/120.0 | 90.5/95.0 | 90.5/94.6 | 108.0/111.2 | 21.0/31.0 | 20.0/0.0 | 0.0/0.0 | 32.4/16.0 | 20.0/29.0 | 0.0/0.0 | 0.0/0.0 | 11.2/15.7 | 15.4/15.1 |
| 38 | 19:00 | 490.3 | 120.0/120.0 | 90.0/95.0 | 90.0/95.1 | 107.7/111.4 | 24.0/31.0 | 20.0/0.0 | 0.0/0.0 | 34.7/16.0 | 20.0/31.0 | 0.0/0.0 | 0.0/0.0 | 11.2/16.7 | 15.8/15.1 |
| 39 | 19:30 | 492.8 | 120.0/120.0 | 105.0/96.0 | 98.5/96.0 | 116.4/112.1 | 20.0/31.0 | 0.0/0.0 | 0.0/0.0 | 11.1/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.2/15.3 |
| 40 | 20:00 | 488.9 | 120.0/120.0 | 95.0/95.0 | 95.0/94.6 | 111.3/111.2 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.0/16.0 | 30.5/30.5 | 0.0/0.0 | 0.0/0.0 | 16.4/16.4 | 15.7/15.2 |
| 41 | 20:30 | 492.4 | 120.0/120.0 | 105.0/95.8 | 96.0/95.8 | 115.5/112.0 | 22.0/31.0 | 0.0/0.0 | 0.0/0.0 | 12.0/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.0/15.1 |
| 42 | 21:00 | 502.5 | 120.0/120.0 | 105.0/99.5 | 105.0/99.5 | 118.8/114.7 | 20.5/31.0 | 0.0/0.0 | 0.0/0.0 | 11.4/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.1/15.2 |
| 43 | 21:30 | 501.5 | 120.0/120.0 | 105.0/105.0 | 99.2/99.2 | 116.7/116.7 | 25.2/25.2 | 0.0/0.0 | 0.0/0.0 | 13.5/13.5 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.1/15.1 |
| 44 | 22:00 | 503.9 | 120.0/120.0 | 105.0/100.0 | 105.0/100.0 | 118.8/115.1 | 21.5/31.0 | 0.0/0.0 | 0.0/0.0 | 11.8/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.1/15.1 |
| 45 | 22:30 | 500.2 | 120.0/120.0 | 105.0/105.0 | 98.8/98.8 | 116.5/116.5 | 24.8/24.8 | 0.0/0.0 | 0.0/0.0 | 13.2/13.2 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.0/15.0 |
| 46 | 23:00 | 499.1 | 120.0/120.0 | 105.0/98.2 | 103.0/98.2 | 118.0/113.8 | 20.0/31.0 | 0.0/0.0 | 0.0/0.0 | 11.1/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.0/15.2 |
| 47 | 23:30 | 493.9 | 120.0/120.0 | 96.5/96.5 | 96.5/96.5 | 112.5/112.5 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.0/16.0 | 31.0/31.0 | 0.0/0.0 | 0.0/0.0 | 16.7/16.7 | 15.6/15.6 |
| 48 | 00:00 | 479.8 | 120.0/120.0 | 103.2/103.0 | 103.2/103.0 | 117.5/117.3 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.1/11.1 | 20.0/20.0 | 0.0/0.0 | 0.0/0.0 | 11.2/11.2 | 15.8/15.1 |

## Bukti numerik event start (invariant B: start satu row lebih lambat)

### G3 START 05:30 (row 11)
- Jenis: AUTO_START; level Unit Priority: 4
- Row sebelum start: 05:00 — IE 271.674837 MW, Export 15.03 MW (min 15, max 155), gas row 1.9692 BBTUD, distillate row 0 l
- Unit running (MW / min / max / ramp / legal headroom / level):
  - G2: 20 / 20 / 31 / None / 11 / 3
  - G8: 65 / 65 / 105 / 30 / 30 / 2
  - G9: 40 / 65 / 105 / 30 / 30 / 2
  - B2: 120 / 30 / 120 / None / 0 / 1
- Legal headroom unit running berprioritas lebih tinggi: 71 MW
- STG/HRSG: {'unit': 'S1', 'mw_row_before': 0, 'startup_mode': 'cold'}; startup lead-in: [5, 15, 20, 20, 20, 20, 20]
- Uji: START_SATU_ROW_LEBIH_LAMBAT (row 12) → **TIDAK_VALID**
  - export_range: row 18: Export 2.83 < RangeMin 30
  - Defisit Export pada uji tunda: row 18 (09:00): 2.83 < 30 (−27.17)

### G3 PRIORITY_REPLACEMENT 05:30 (row 11)
- Uji: SWAP G3->G1 row 11-33 → **TIDAK_VALID**
  - export_range: row 22: Export 15.09 < RangeMin 30
  - export_range: row 23: Export -16.94 < RangeMin 30
  - export_range: row 24: Export 15.16 < RangeMin 30
  - export_range: row 26: Export 19.72 < RangeMin 30

### G3 STOP 17:00 (row 34)
- Uji: STOP_SATU_ROW_LEBIH_AWAL (off row 33) → **TIDAK_VALID**
  - export_range: row 32: Export 11.32 < RangeMin 30

### G5 START 07:30 (row 15)
- Jenis: AUTO_START; level Unit Priority: 3
- Row sebelum start: 07:00 — IE 364.8809175 MW, Export 30.47 MW (min 15, max 155), gas row 2.84575 BBTUD, distillate row 0 l
- Unit running (MW / min / max / ramp / legal headroom / level):
  - G2: 28.5 / 20 / 31 / None / 2.5 / 3
  - G3: 20 / 20 / 31 / None / 11 / 4
  - G8: 105 / 65 / 105 / 30 / 0 / 2
  - G9: 60 / 65 / 105 / 30 / 30 / 2
  - B2: 120 / 30 / 120 / None / 0 / 1
- Legal headroom unit running berprioritas lebih tinggi: 30 MW
- STG/HRSG: {'unit': 'S2', 'mw_row_before': 14.91, 'startup_mode': 'cold'}; startup lead-in: [5, 15]
- Uji: START_SATU_ROW_LEBIH_LAMBAT (row 16) → **TIDAK_VALID**
  - export_range: row 17: Export 3.13 < RangeMin 30
  - export_range: row 27: Export 28.26 < RangeMin 30
  - export_range: row 28: Export -9.24 < RangeMin 30
  - export_range: row 29: Export -2.03 < RangeMin 30
  - Defisit Export pada uji tunda: row 17 (08:30): 3.13 < 30 (−26.87); row 27 (13:30): 28.26 < 30 (−1.74); row 28 (14:00): -9.24 < 30 (−39.24); row 29 (14:30): -2.03 < 30 (−32.03); row 30 (15:00): -6.99 < 30 (−36.99)

### G5 STOP 18:30 (row 37)
- Uji: STOP_SATU_ROW_LEBIH_AWAL (off row 36) → **TIDAK_VALID**
  - export_range: row 36: Export 10.18 < RangeMin 15

### G6 START 10:00 (row 20)
- Jenis: REQUIRED_START_BASED_ON_SIMULATION; level Unit Priority: 4
- Row sebelum start: 09:30 — IE 549.39654 MW, Export 30.14 MW (min 30, max 155), gas row 3.74107 BBTUD, distillate row 2856.1 l
- Unit running (MW / min / max / ramp / legal headroom / level):
  - G2: 31 / 20 / 31 / None / 0 / 3
  - G3: 31 / 20 / 31 / None / 0 / 4
  - G5: 31 / 20 / 31 / None / 0 / 3
  - G8: 104 / 65 / 105 / 30 / 1 / 2
  - G9: 104 / 65 / 105 / 30 / 1 / 2
  - B2: 120 / 30 / 120 / None / 0 / 1
- Legal headroom unit running berprioritas lebih tinggi: 2 MW
- STG/HRSG: {'unit': 'S1', 'mw_row_before': 16.69, 'startup_mode': 'cold'}; startup lead-in: [5, 15]
- Uji: START_SATU_ROW_LEBIH_LAMBAT (row 21) → **TIDAK_VALID**
  - export_range: row 21: Export 27.51 < RangeMin 30
  - export_range: row 22: Export 25.51 < RangeMin 30
  - min_load: row 24: G3=5 below valid minimum 20 outside startup sequence
  - startup_sequence: Invalid 5 MW startup load: G3 has 5 MW at row 24, but unit is not in Additional HRSG or STG 1/2 startup sequence.
  - Defisit Export pada uji tunda: row 21 (10:30): 27.51 < 30 (−2.49); row 22 (11:00): 25.51 < 30 (−4.49)

### G9 START 05:00 (row 10)
- Jenis: REQUIRED_START_BASED_ON_SIMULATION; level Unit Priority: 2
- Row sebelum start: 04:30 — IE 269.51176 MW, Export 20.39 MW (min 15, max 155), gas row 1.48771 BBTUD, distillate row 0 l
- Unit running (MW / min / max / ramp / legal headroom / level):
  - G2: 31 / 20 / 31 / None / 0 / 3
  - G8: 82.2 / 65 / 105 / 30 / 22.8 / 2
  - B2: 120 / 30 / 120 / None / 0 / 1
- Legal headroom unit running berprioritas lebih tinggi: 0 MW
- STG/HRSG: {'unit': 'S3', 'mw_row_before': 47.93, 'startup_mode': 'cold'}; startup lead-in: [40, 40, 50, 60, 60, 70]
- Uji: START_SATU_ROW_LEBIH_LAMBAT (row 11) → **TIDAK_VALID**
  - export_range: row 15: Export -13.2 < RangeMin 15
  - export_range: row 16: Export 0.62 < RangeMin 15
  - Defisit Export pada uji tunda: row 15 (07:30): -13.2 < 15 (−28.2); row 16 (08:00): 0.62 < 15 (−14.38)

## Langkah Commitment Tightening

Status APPLIED; sebelum {'startups_nm': 2, 'startups': 4, 'prio': 366, 'cp': 95.5928, 'econ_cp': 96.0276, 'hr': 8533.81, 'dist_l': 224962.3}; sesudah {'startups_nm': 2, 'startups': 4, 'prio': 352, 'cp': 94.6218, 'econ_cp': 95.0575, 'hr': 8521.98, 'dist_l': 216487.5}; evaluasi 47, cache hit 10, 9.387 s

- DECOMMIT:G3:10-34: TIDAK_VALID: export_range: row 14: Export 14.83 < RangeMin 15; export_range: row 17: Export 24.75 < RangeMin 30; export_range: row 18: Export -3.39 < RangeMin 30
- EARLY_STOP:G3:10-34:@22: TIDAK_VALID: export_range: row 21: Export 14.51 < RangeMin 30; export_range: row 22: Export 11.26 < RangeMin 30; export_range: row 23: Export 10.75 < RangeMin 30
- EARLY_STOP:G3:10-34:@34: DITERAPKAN
- DELAY_START:G3:10-33:@22: TIDAK_VALID: export_range: row 14: Export 14.83 < RangeMin 15; export_range: row 17: Export 24.75 < RangeMin 30; export_range: row 18: Export -3.39 < RangeMin 30
- DELAY_START:G3:10-33:@11: DITERAPKAN
- DECOMMIT:G5:15-38: TIDAK_VALID: export_range: row 17: Export -5.64 < RangeMin 30; export_range: row 18: Export -5.96 < RangeMin 30; export_range: row 19: Export -16.63 < RangeMin 30
- EARLY_STOP:G5:15-38:@27: TIDAK_VALID: export_range: row 27: Export 20.25 < RangeMin 30; export_range: row 28: Export 2.89 < RangeMin 30; export_range: row 29: Export 0.09 < RangeMin 30
- EARLY_STOP:G5:15-38:@37: DITERAPKAN
- SWAP:G3>G1:11-33: TIDAK_DIEVALUASI_BATAS
- SWAP:G3>G4:11-33: TIDAK_DIEVALUASI_BATAS
- ADVANCE_REPLACE:G6@11>G3:11-33: TIDAK_DIEVALUASI_BATAS
- ADVANCE_REPLACE:G6@15>G5:15-36: TIDAK_DIEVALUASI_BATAS
- DECOMMIT:G3:11-33: TIDAK_DIEVALUASI_BATAS
- DECOMMIT:G5:15-36: TIDAK_VALID: export_range: row 17: Export -5.64 < RangeMin 30; export_range: row 18: Export -5.96 < RangeMin 30; export_range: row 19: Export -16.63 < RangeMin 30
- SWAP:G3>G1:11-33: TIDAK_DIEVALUASI_BATAS
- SWAP:G3>G4:11-33: TIDAK_DIEVALUASI_BATAS
- ADVANCE_REPLACE:G6@11>G3:11-33: TIDAK_DIEVALUASI_BATAS
- ADVANCE_REPLACE:G6@15>G5:15-36: TIDAK_DIEVALUASI_BATAS

## Comparator Invariant Guard (V8 / family / Unit Priority Fix)

- V8_PRIORITY_REVIEW: DITOLAK_MEMBURUK_KEMBALI_KE_SEBELUM — econ CP 96.0276 → 96.8696, startup tak-wajib 2 → 2
- UNIT_PRIORITY_FIX: DITERIMA — econ CP 95.0575 → 93.8776, startup tak-wajib 2 → 2

## Periode G8/G9 ≤ 70 MW sementara unit prioritas lebih rendah running (dispatch final)

- row 11 (05:30): G8 72.0, G9 40.0, S3 43.56, Export 17.53 (min 15); unit lebih rendah: G3 5
- row 12 (06:00): G8 73.91, G9 50.0, S3 44.38, Export 28.23 (min 15); unit lebih rendah: G3 15
- row 13 (06:30): G8 83.0, G9 60.0, S3 48.27, Export 26.79 (min 15); unit lebih rendah: G3 20
- row 14 (07:00): G8 105.0, G9 60.0, S3 57.68, Export 30.47 (min 15); unit lebih rendah: G3 20
- row 15 (07:30): G8 99.19, G9 70.0, S3 103.61, Export 21.03 (min 15); unit lebih rendah: G5 5, G3 20

## Regresi

| Kasus | CP lama | CP baru | Hard lama/baru | Publish lama/baru | Event baru | wall baru (s) |
|---|---|---|---|---|---|---|
| A0_actual | 105.8901 | 93.4422 | PASS/PASS | True/True | G3+05:30 G3-17:00 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+09:00 | 31.5 |
| A1_gas_cukup | 68.4317 | 68.4317 | PASS/PASS | True/True | G1+08:00 G1-17:30 G3+07:30 G3-13:30 G4+12:00 G5+07:30 G5-19:00 G6+11:30 G9+04:30 S1+11:00 | 108.5 |
| A2_lng | 73.9965 | 73.9965 | PASS/PASS | True/True | G1+04:30 G1-17:30 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+13:30 | 61.6 |
| A3_cfg_G6_ke_G4 | 93.6116 | 93.1676 | PASS/PASS | True/True | G1+06:00 G1-16:30 G4+07:30 G5+08:00 G5-18:30 G9+05:00 S1+11:00 | 20.3 |
| A4_priority_blok1_di_atas_blok2 | 105.6953 | 95.3471 | PASS/PASS | True/True | G3+10:00 G3-16:30 G4+08:00 G4-18:30 G6+05:00 G9+05:00 S1+08:30 | 33.7 |
| A5_block2_required | 105.8901 | 93.4422 | PASS/PASS | True/True | G3+05:30 G3-17:00 G5+07:30 G5-18:30 G6+10:00 G9+05:00 S1+09:00 | 31.8 |
| A6_IE_minus40 | 73.5813 | 72.8132 | PASS/PASS | True/True | G5+07:30 G5-16:30 G6+06:30 G9+05:30 S1+10:00 | 20.0 |
| A7_IE_plus20 | 122.0047 | 122.0047 | VALID-INFEASIBLE/VALID-INFEASIBLE | False/False | G1+01:00 G4+08:00 G4-18:30 G5+07:30 G6+01:00 G9+01:00 S1+04:30 | 130.6 |
| A8_cfg_G9_ke_G5 | 106.0179 | 102.2652 | PASS/PASS | True/True | G4+09:30 G4-16:30 G5+07:30 G6+05:30 G9+01:00 S1+09:00 | 35.0 |
| B0_input_data | 68.4541 | 68.4541 | VALID-INFEASIBLE/VALID-INFEASIBLE | False/False | G4+07:30 G4-17:30 G5+07:00 G5-19:00 | 1.9 |

## Benchmark (CLI job, 1 proses, tanpa helper)

```
new cold 1 rc=0 wall=28.75
new cold 2 rc=0 wall=31.34
new cold 3 rc=0 wall=29.83
new warm rc=0 wall=0.42
new cache-off rc=0 wall=30.54
new prelim rc=0 wall=5.71
new rerun-after-prelim rc=0 wall=29.51
old prelim rc=0 wall=78.07
old rerun-after-prelim rc=0 wall=7.94
ALLDONE

```

## Profil tahap V15.23 (run cold)

- plan_latest_legal_starts: 0.002 s
- cand_run: 3.596 s
- cand_assess: 0.007 s
- cand_fuel_assess: 0.137 s
- cand_rank: 0.079 s
- pipeline_core_total: 3.821 s
- consol_run: 3.003 s
- consol_run: 1.512 s
- consol_run: 1.215 s
- consol_run: 3.376 s
- bundle_v8_priority_review: 5.612 s
- bundle_family_evidence: 0 s
- commitment_tightening: 9.388 s
- bundle_priority_fix: 1.242 s
- bundle_proof_completion: 0.051 s
- gate_acceptance_review: 0.004 s
- gate_hpa: 0.056 s
- gate_merit: 0.017 s
- final_bundle_comparator: 27.452 s
- pre_gate_misc: 0.01 s
- gate_acceptance_review: 0.004 s
- gate_hpa: 0.033 s
- gate_merit: 0.014 s
- gate: 0.07 s
- validate_final: 0.002 s
