# TEST STATUS — BBLN1 Stop All Day (V15.17b)

Sumber: `ROOT_CAUSE_BBLN1_BLOCK1_G8G9_MERIT_PROOF.md` §7–§8.

## T0 lewat UI nyata (3 cold + 3 warm per platform)
| Platform | Sesi | Run | Klik→48 row FINAL (s) | Status | Gate | Merit | CP | HR | BB1 maks | Save/Export | candidate_hash | payload_hash | Proof terikat |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| XAMPP-like (proxy + 6 worker PHP 7.4) | X1 | cold | 24,763 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| XAMPP-like (proxy + 6 worker PHP 7.4) | X1 | warm | 0,364 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| XAMPP-like (proxy + 6 worker PHP 7.4) | X2 | cold | 23,658 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| XAMPP-like (proxy + 6 worker PHP 7.4) | X2 | warm | 0,401 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| XAMPP-like (proxy + 6 worker PHP 7.4) | X3 | cold | 24,136 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| XAMPP-like (proxy + 6 worker PHP 7.4) | X3 | warm | 0,409 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y1 | cold | 24,272 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y1 | warm | 0,396 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y2 | cold | 24,883 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y2 | warm | 0,362 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y3 | cold | 23,928 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |
| Linux (Apache 2.4 + PHP-FPM 7.4) | Y3 | warm | 0,393 | FINAL | PASS | PASS | 72.3124 | 8509.21 | 0 | aktif | 0b63b7e26b3bc2e628e5253c | 5777f3b4cfe8cbeb8f47b8c1 | ya |

## T0–T5 (CLI PHP 7.4, source freeze, berurutan tanpa beban paralel)
| Uji | CLI (s) | Hard | Gate | Merit | C1/C2/C3/C4 FAIL | STG | CP | HR | Commitment (row) | Catatan |
|---|---|---|---|---|---|---|---|---|---|---|
| T0 BBLN1 Stop All Day + Block 1 + G8/G9 (payload UI) | 28,3 | PASS | PASS | PASS | 0/0/0/0 | PASS | 72.3124 | 8509.21 | G1 16–29, G4 16–35, G5 15–34, G6 15–48, G8 1–48, G9 2–48, S1 22–48 · BB1 0 MW | acuan |
| T1 F0: Block 1 + G8 saja (G9 Required dilepas) | 37,34 | PASS | PASS | PASS | 0/0/0/0 | PASS | 72.6602 | 8562.87 | G3 15–34, G4 16–35, G5 16–29, G6 15–48, G8 1–48, G9 2–48, S1 22–48 · BB1 0 MW | engine tetap start G9 row 2 → G9 diperlukan |
| T1 F1: G9 dipaksa start 07:00 | 60,4 | VALID-INFEASIBLE | FAIL HARD_VALIDATION_FAILED,NOT_CONVERGED | FAIL | 48/0/16/0 | PASS | 70.5539 | 8540 | G1 16–48, G3 16–38, G4 13–30, G5 11–48, G6 12–37, G8 1–48, G9 15–48, S1 19–38 · BB1 0 MW | pembanding |
| T1 F1: G9 dipaksa start 14:00 | 24,39 | VALID-INFEASIBLE | FAIL HARD_VALIDATION_FAILED,NOT_CONVERGED | PASS | 0/0/0/0 | PASS | 72.2474 | 8504.66 | G1 17–36, G3 9–33, G4 13–36, G5 11–48, G6 12–37, G8 1–48, G9 29–48, S1 16–37 · BB1 0 MW | pembanding |
| T2 IE Adjustment +10 MW 10:00–12:00 (warm sesudah T0) | 4,37 | PASS | PASS | PASS | 0/0/0/0 | PASS | 72.2505 | 8499.95 | G1 18–29, G4 16–35, G5 15–34, G6 16–48, G8 1–48, G9 2–48, S1 23–48 · BB1 0 MW | jalur IE warm |
| T3 PGN Pipe −1 (22 BBTUD) | 64,65 | PASS | PASS | PASS | 0/0/0/0 | PASS | 73.7555 | 8737.03 | G1 18–29, G3 18–48, G4 20–31, G5 15–48, G6 16–37, G8 1–48, G9 2–48, S1 23–48 · BB1 0 MW | cold |
| T4 G5 (unit startup terpilih) unavailable | 22,55 | PASS | PASS | PASS | 0/0/0/0 | PASS | 72.6595 | 8562.78 | G1 16–29, G3 15–34, G4 16–35, G6 15–48, G8 1–48, G9 2–48, S1 22–48 · BB1 0 MW | fallback legal |
| T5 golden smoke (D0_golden, Fastest) | 6,42 | PASS | PASS | PASS | 0/0/0/0 | PASS | 79.9049 | 8239.87 | G4 15–34, G5 14–35, G6 1–48, G8 1–48, G9 1–48, S1 1–48 · BB1 0 MW | acuan CP 79,9049 |
