| Payload | Jalur | Build | n | min (s) | median (s) | max (s) | CP | HR | status | core sim | tasks/workers | sig |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| cs_bench_fast | cli | ZIP | 3 | 35.33 | 35.72 | 35.93 | 73.1462 | 8324.73 | CONVERGED | 67 | - | 4671fc31f81c |
| cs_bench_fast | cli | FIX | 3 | 36.28 | 36.59 | 37.23 | 73.1462 | 8324.73 | CONVERGED | 67 | - | 4671fc31f81c |
| cs_bench_fast | srv-cached | ZIP | 1 | 0.29 | 0.29 | 0.29 | 73.1462 | 8324.73 | CONVERGED | 67 | 6/4 | 649e4112c29e |
| cs_bench_fast | srv-cached | FIX | 1 | 0.30 | 0.30 | 0.30 | 73.1462 | 8324.73 | CONVERGED | 67 | 13/4 | 649e4112c29e |
| cs_bench_fast | srv-cold | ZIP | 3 | 20.24 | 20.74 | 22.03 | 73.1462 | 8324.73 | CONVERGED | 67 | 6/4 | 649e4112c29e |
| cs_bench_fast | srv-cold | FIX | 3 | 16.98 | 18.04 | 18.34 | 73.1462 | 8324.73 | CONVERGED | 67 | 13/4 | 649e4112c29e |
| cs_bench_fast | srv-warm | ZIP | 3 | 20.11 | 20.65 | 20.88 | 73.1462 | 8324.73 | CONVERGED | 67 | 6/4 | 649e4112c29e |
| cs_bench_fast | srv-warm | FIX | 3 | 17.67 | 17.72 | 18.19 | 73.1462 | 8324.73 | CONVERGED | 67 | 13/4 | 649e4112c29e |
| cs_lng_fast | cli | ZIP | 3 | 12.27 | 12.38 | 12.49 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | - | 1ed9539587d9 |
| cs_lng_fast | cli | FIX | 3 | 11.15 | 11.50 | 12.05 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | - | 1ed9539587d9 |
| cs_lng_fast | srv-cached | ZIP | 1 | 0.31 | 0.31 | 0.31 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |
| cs_lng_fast | srv-cached | FIX | 1 | 0.31 | 0.31 | 0.31 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |
| cs_lng_fast | srv-cold | ZIP | 3 | 9.42 | 10.22 | 10.28 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |
| cs_lng_fast | srv-cold | FIX | 3 | 9.37 | 9.62 | 9.92 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |
| cs_lng_fast | srv-warm | ZIP | 3 | 9.49 | 9.52 | 9.75 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |
| cs_lng_fast | srv-warm | FIX | 3 | 8.71 | 8.98 | 9.48 | 71.8684 | 8261.34 | FASTEST_FIRST_VALID | 11 | 5/4 | 9e5ece3cb3eb |

| Payload | Jalur | median ZIP | median FIX | perubahan |
|---|---|---|---|---|
| cs_bench_fast | cli | 35.72 | 36.59 | +2.4% |
| cs_bench_fast | srv-cached | 0.29 | 0.30 | +1.7% |
| cs_bench_fast | srv-cold | 20.74 | 18.04 | -13.0% |
| cs_bench_fast | srv-warm | 20.65 | 17.72 | -14.2% |
| cs_lng_fast | cli | 12.38 | 11.50 | -7.1% |
| cs_lng_fast | srv-cached | 0.31 | 0.31 | -1.3% |
| cs_lng_fast | srv-cold | 10.22 | 9.62 | -5.9% |
| cs_lng_fast | srv-warm | 9.52 | 8.98 | -5.6% |
