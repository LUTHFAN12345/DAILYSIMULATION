# PGN25 / LNG18 / PEP36 / Akasia4 — Performance (Addendum §3, R1)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | PASS |
| Validation | PASS — hard PASS, gate PASS, C1–C4 FAIL = 0 |
| Quality | PASS — CP 71,78, HR ±8175 |
| Performance | **PASS** — browser 13.076 s (target ideal ≤ 15 s, batas ≤ 30 s); CLI 13,8 s |
| Release blocker | Tidak ada |

## Hasil
| Kasus | Wall (s) | Hard | Gate | CP | HR | C1–C4 FAIL | STG | Kontinuitas Dist. | Cache warm |
|---|---|---|---|---|---|---|---|---|---|
| R1 CLI cold | 13.63 | PASS | PASS | 71.7979 | 8176.34 | 0 | PASS | PASS | MISS |

Browser (UI nyata, XAMPP-like proxy 6 worker PHP 7.4): klik Run → FINAL **13.076 s**, gate PASS, CP 71.7784, HR 8173.52, mode FASTEST_PIPELINE.

Critical path sebelum V15.17: 22,9–23,3 s, didominasi polish Unit Priority tanpa batas di pipeline Fastest. Perbaikan: `PP_FAST_POLISH_S` = 3 s (satu repair terbatas; review Unit Priority V8 + perbaikan C1–C4 tetap di job).
