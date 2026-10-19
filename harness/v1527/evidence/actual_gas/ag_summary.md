# Actual Gas dua arah — V15.27

| kasus | arah | status warm | publish | CP basis -> warm | dingin == warm | actual immutable | warm ulang identik | warm s | dingin s |
|---|---|---|---|---|---|---|---|---|---|
| A0_distillate__PGN_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 93.0321 -> 93.0306 | ya | ya | ya | 0.69 | 41.96 |
| A0_distillate__FFJ_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 93.0321 -> 93.0321 | ya | ya | ya | 0.71 | 44.26 |
| A0_distillate__PGN_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.1421 | ya | ya | ya | 0.79 | 43.2 |
| A0_distillate__PGN_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 93.0321 -> 93.0207 | ya | ya | ya | 0.84 | 41.46 |
| A0_distillate__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.3264 | ya | ya | ya | 0.85 | 43.27 |
| A0_distillate__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.8364 | ya | ya | ya | 0.7 | 44.12 |
| A0_distillate__FFJ_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.6299 | ya | ya | ya | 0.69 | 44.1 |
| A0_distillate__FFJ_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.5974 | ya | ya | ya | 0.68 | 42.45 |
| A0_distillate__FFJ_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 95.2239 | ya | ya | ya | 0.74 | 42.3 |
| A0_distillate__FFJ_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 91.0543 | ya | ya | ya | 0.69 | 41.87 |
| A0_distillate__ALL3_hi10_h1-4 | HIGHER | USER_ACTION_REQUIRED | False | 93.0321 -> 98.2165 | ya | ya | ya | 1.94 | 43.94 |
| A0_distillate__ALL3_lo10_h1-4 | LOWER | None | False | 93.0321 -> 96.3254 | ya | ya | ya | 86.05 | 133.78 |
| A0_distillate__PGN_hi80_h1-8 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 108.4125 | ya | ya | ya | 1.08 | 41.69 |
| A0_distillate__PGN_lo80_h1-8 | LOWER | FASTEST_FINAL | True | 93.0321 -> 77.8002 | ya | ya | ya | 1.04 | 42.64 |
| A2_lng__PGN_eq0_h1-4 | EQUAL | FASTEST_FINAL | True | 73.9965 -> 73.9964 | ya | ya | ya | 0.6 | 61.69 |
| A2_lng__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 73.9965 -> 73.9925 | ya | ya | ya | 2.99 | 63.61 |
| A2_lng__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 73.9965 -> 73.7954 | ya | ya | ya | 4.83 | 65.65 |
| A2_lng__FFJ_hi10_h1-4 | HIGHER | USER_ACTION_REQUIRED | False | 73.9965 -> 74.2088 | ya | ya | ya | 5.21 | 65.77 |
| A2_lng__FFJ_lo10_h1-4 | LOWER | FASTEST_FINAL | False | 73.9965 -> 73.8543 | ya | ya | ya | 62.86 | 132.96 |
| A0_fixload__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 92.8227 -> 92.9622 | ya | ya | ya | 0.58 | 46.16 |
| A0_fixload__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 92.8227 -> 92.6497 | ya | ya | ya | 0.61 | 46.24 |
| A0_supplier_window__PGN_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 93.0321 -> 93.4329 | ya | ya | ya | 0.72 | 42.19 |
| A0_supplier_window__PGN_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 93.0321 -> 92.7441 | ya | ya | ya | 0.7 | 42.17 |
| MM2100_active__MM_hi10_h1-1 | HIGHER | FASTEST_FINAL | True | 92.3376 -> 92.3671 | ya | ya | ya | 0.78 | 55.78 |
| MM2100_active__MM_lo10_h1-1 | LOWER | FASTEST_FINAL | True | 92.3376 -> 92.3593 | ya | ya | ya | 0.83 | 57.25 |
| MM2100_active__MM_hi10_h1-4 | HIGHER | FASTEST_FINAL | True | 92.3376 -> 92.3839 | ya | ya | ya | 5.15 | 61.39 |
| MM2100_active__MM_lo10_h1-4 | LOWER | FASTEST_FINAL | True | 92.3376 -> 92.3117 | ya | ya | ya | 5.25 | 61.51 |
| MM2100_active__ALL3_hi10_h1-4 | HIGHER | FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW | False | 92.3376 -> 95.672 | ya | ya | ya | 55.28 | 121.27 |
| MM2100_active__ALL3_lo10_h1-4 | LOWER | None | False | 92.3376 -> 95.6192 | ya | ya | ya | 69.99 | 136.28 |

Ringkasan: 29 kasus, 23 publish, dingin == warm 29/29, actual immutable 29/29, warm ulang identik 29/29; warm median 0.83 s, maks 86.05 s; <= 5 s: 22/29.

Pasangan input tunggal (lebih tinggi vs lebih rendah, keduanya publish): PGN_hi10_h1-1 CP 93.1421 > 93.0207 ya, PGN_hi10_h1-4 CP 93.3264 > 92.8364 ya, FFJ_hi10_h1-1 CP 93.6299 > 92.5974 ya, FFJ_hi10_h1-4 CP 95.2239 > 91.0543 ya, PGN_hi80_h1-8 CP 108.4125 > 77.8002 ya, PGN_hi10_h1-4 CP 73.9925 > 73.7954 ya, PGN_hi10_h1-4 CP 92.9622 > 92.6497 ya, PGN_hi10_h1-4 CP 93.4329 > 92.7441 ya, MM_hi10_h1-1 CP 92.3671 > 92.3593 ya, MM_hi10_h1-4 CP 92.3839 > 92.3117 ya
