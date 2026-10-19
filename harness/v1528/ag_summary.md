# Actual Gas dua arah — V15.27

| kasus | arah | status warm | publish | CP basis -> warm | dingin == warm | actual immutable | warm ulang identik | warm s | dingin s |
|---|---|---|---|---|---|---|---|---|---|
| A0_distillate__PGN_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 93.0321 -> 93.0306 | ya | ya | ya | 1.38 | 58.51 |
| A0_distillate__FFJ_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 93.0321 -> 93.0321 | ya | ya | ya | 1.48 | 57.63 |
| A0_distillate__PGN_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.1421 | ya | ya | ya | 1.39 | 54.77 |
| A0_distillate__PGN_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 93.0321 -> 93.0207 | ya | ya | ya | 1.43 | 55.47 |
| A0_distillate__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.3264 | ya | ya | ya | 1.3 | 56.98 |
| A0_distillate__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.8364 | ya | ya | ya | 1.25 | 59.74 |
| A0_distillate__FFJ_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.6299 | ya | ya | ya | 1.28 | 55.28 |
| A0_distillate__FFJ_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.5974 | ya | ya | ya | 1.17 | 54.73 |
| A0_distillate__FFJ_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 95.2239 | ya | ya | ya | 1.18 | 52.99 |
| A0_distillate__FFJ_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 91.0543 | ya | ya | ya | 1.31 | 55.46 |
| A0_distillate__ALL3_hi10_h1-4 | HIGHER | USER_ACTION_REQUIRED | False | 93.0321 -> 98.2165 | ya | ya | ya | 2.42 | 56.06 |
| A0_distillate__ALL3_lo10_h1-4 | LOWER | None | False | 93.0321 -> 96.3254 | ya | ya | ya | 100.81 | 169.73 |
| A0_distillate__PGN_hi80_h1-8 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 108.4125 | ya | ya | ya | 2.95 | 58.09 |
| A0_distillate__PGN_lo80_h1-8 | LOWER | FASTEST_FINAL | True | 93.0321 -> 77.8002 | ya | ya | ya | 1.71 | 57.42 |
| A2_lng__PGN_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 73.9965 -> 73.9964 | ya | ya | ya | 1.44 | 83.93 |
| A2_lng__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 73.9965 -> 73.9925 | ya | ya | ya | 4.7 | 84.28 |
| A2_lng__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 73.9965 -> 73.7954 | ya | ya | ya | 8.54 | 87.93 |
| A2_lng__FFJ_hi10_h1-4 | HIGHER | USER_ACTION_REQUIRED | False | 73.9965 -> 74.2088 | ya | ya | ya | 6.69 | 88.46 |
| A2_lng__FFJ_lo10_h1-4 | LOWER | FASTEST_FINAL | False | 73.9965 -> 73.8543 | ya | ya | ya | 80.78 | 178.73 |
| A0_fixload__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 92.8227 -> 92.9622 | ya | ya | ya | 1.62 | 69.78 |
| A0_fixload__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 92.8227 -> 92.6497 | ya | ya | ya | 1.3 | 67.22 |
| A0_supplier_window__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.4329 | ya | ya | ya | 1.19 | 51.46 |
| A0_supplier_window__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.7441 | ya | ya | ya | 1.26 | 51.83 |
| MM2100_active__MM_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 92.3376 -> 92.3671 | ya | ya | ya | 1.25 | 70.34 |
| MM2100_active__MM_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 92.3376 -> 92.3593 | ya | ya | ya | 1.33 | 72.01 |
| MM2100_active__MM_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 92.3376 -> 92.3839 | ya | ya | ya | 5.94 | 76.83 |
| MM2100_active__MM_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 92.3376 -> 92.3117 | ya | ya | ya | 6.59 | 77.32 |
| MM2100_active__ALL3_hi10_h1-4 | HIGHER | FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW | False | 92.3376 -> 95.672 | ya | ya | ya | 71.77 | 155.4 |
| MM2100_active__ALL3_lo10_h1-4 | LOWER | None | False | 92.3376 -> 95.6192 | ya | ya | ya | 81.58 | 166.52 |

Ringkasan: 29 kasus, 23 publish, dingin == warm 29/29, actual immutable 29/29, warm ulang identik 29/29; warm median 1.44 s, maks 100.81 s; <= 5 s: 21/29.

Pasangan input tunggal (lebih tinggi vs lebih rendah, keduanya publish): PGN_hi10_h1-1 CP 93.1421 > 93.0207 ya, PGN_hi10_h1-4 CP 93.3264 > 92.8364 ya, FFJ_hi10_h1-1 CP 93.6299 > 92.5974 ya, FFJ_hi10_h1-4 CP 95.2239 > 91.0543 ya, PGN_hi80_h1-8 CP 108.4125 > 77.8002 ya, PGN_hi10_h1-4 CP 73.9925 > 73.7954 ya, PGN_hi10_h1-4 CP 92.9622 > 92.6497 ya, PGN_hi10_h1-4 CP 93.4329 > 92.7441 ya, MM_hi10_h1-1 CP 92.3671 > 92.3593 ya, MM_hi10_h1-4 CP 92.3839 > 92.3117 ya
