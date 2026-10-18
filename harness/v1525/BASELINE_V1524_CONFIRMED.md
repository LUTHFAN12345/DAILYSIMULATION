# BASELINE V15.24 CONFIRMED (Fase 0)

- Paket: `XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip` SHA-256 `cc8135fc4ab197b381d534ae2078547a2e3fd83ea27c89c1e9e851fbf66f8aef` (cocok `SHA256SUMS_ZIP.txt`); `SHA256SUMS.txt` di dalam ZIP diverifikasi `sha256sum -c` OK; seluruh file runtime identik byte dengan source commit `d207780` (branch `claude/keen-galileo-sjjhsk`).
- Diekstrak ke direktori kerja baru `/tmp/w/b1524` (jobs kosong = cache dingin); PHP 7.4.33 CLI (opcache CLI aktif); setiap run engine dipin `taskset -c <n>` -> `Cpus_allowed_list` 1 CPU per run (3 run paralel pada CPU 1/2/3 berbeda).
- Database: MariaDB lokal (socket `/run/mysqld/mysqld.sock`), schema `pp-report-mysql-1`; clean-install MySQL dari ZIP 14/14 PASS (V15.24 bagian 10).
- Run ID = `__bench.job_id` dalam setiap evidence (`evidence/baseline_v1524/T*.json` -> `evidence` run file).

| Test | status reproduksi | status V15.24 REPORT | CP reproduksi | CP REPORT | payload hash | wall s / CPU s (1 CPU) |
|---|---|---|---|---|---|---|
| T124 | FAIL | FAIL | 105.9801 | 105.9801 | `b3174c86039e36ffc899c064` (sama) | 50.1 / 49.37 |
| T127 | FAIL | FAIL | 105.9801 | 105.9801 | `4cfc03e882a93c4059778cc3` (sama) | 49.89 / 49.4 |
| T128 | FAIL | FAIL | 105.9801 | 105.9801 | `94712aad443a0028c8147eb2` (sama) | 50.33 / 49.78 |
| T129 | FAIL | FAIL | 105.9801 | 105.9801 | `5c824aae3345f4c290db58e1` (sama) | 62.79 / 49.45 |
| T130 | FAIL | FAIL | 105.9801 | 105.9801 | `c04ae46e58796404be36451a` (sama) | 36.86 / 35.96 |
| T134 | FAIL | FAIL | 112.121 | 112.121 | `ea34d70ffc5852739b1d30ee` (sama) | 71.15 / 70 |
| T135 | FAIL | FAIL | 105.9801 | 105.9801 | `7c6c2fa50c6232bc260e2495` (sama) | 57.5 / 44.3 |
| T136 | FAIL | FAIL | 105.9801 | 105.9801 | `25b440f68b839a063a53ff52` (sama) | 44.71 / 44.03 |
| T137 | FAIL | FAIL | 105.9801 | 105.9801 | `f93da44c676b3703c861d69f` (sama) | 57.22 / 56.31 |
| T139 | FAIL | FAIL | 112.2096 | 112.2096 | `5dfcebf0c23798d935c570c7` (sama) | 80.54 / 58.83 |
| T154 | FAIL | FAIL | 93.9603 | 93.9603 | `8673343bc9e89d84ae4e4106` (sama) | 118.61 / 92.33 |
| T161 | FAIL | FAIL | 77.3441 | 77.3441 | `df052b81d9b545bc0555c1e7` (sama) | 4.28 / 4.23 |
| T164 | FAIL | FAIL | 93.9603 | 93.9603 | `33d8d2f1a8b821a4be611a69` (sama) | 117.08 / 93.15 |
| T178 | FAIL | FAIL | 112.3743 | 112.3743 | `5d4ce34a20aca0f589d46565` (sama) | 80.23 / 51.21 |
| T184 | PASS | PASS | 93.4567 | 93.4567 | `49c02edc54b266284c0ca345` (sama) | 42.5 / 39.75 |
| T207 | PASS | PASS | 94.4589 | 94.4592 | `49c02edc54b266284c0ca345_mr` (sama) | 71.78 / 70.83 |

Kesimpulan: 14 FAIL tereproduksi identik (status, CP, payload hash); A0 (T184) CP 93,4567, event G9+05:00, G6+10:00, G3 05:30–17:00, G5 07:30–18:30, G1 tidak start — identik. Maximum Review T207 CP 94,4589 (REPORT 94,4592: selisih 0,0003 pada run ulang Maximum Review — dicatat sebagai non-determinisme Maximum Review, ditangani di Fase 1.2).
Runtime 1 CPU A0 cold pada reproduksi matrix: 42,5 s wall / 39,75 s CPU (3 run paralel di CPU berbeda; benchmark terisolasi V15.24: 36,02 s).
UI: uji popup Ctrl+Click (Fase 2) pada index.php V15.24: 134 dari 143 kasus FAIL (popup keluar viewport / OK-Cancel tertutup) — bug terkonfirmasi (`evidence/ui_popup_baseline_v1524/`).
