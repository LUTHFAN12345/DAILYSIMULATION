# BASELINE

| Item | Nilai |
|---|---|
| Checkpoint awal | commit `92263fb` = `php_simulation_REFERENCE_LOGIC_ADOPTED_FASTEST_FINAL.zip` (V15.15) |
| Golden reference | `Daily_Plan_10_May_26_Baru(5).xls` — CP 80.2521 USD/MWh (header Excel; 80.2321 bila dievaluasi ulang validator V15.15), HR 8251.58 BTU/kWh, startup G1 + G4, Distillate 96.057,7 l |
| Latest sebelum perbaikan | `Daily_Plan_10_May_26_Baru(7).xls` — CP 82.0968, HR 8261.08, G5 start 01:00, G9 turun 65 MW, Distillate 709,5 l pada G5 5 MW |
| Input | `input_data.json` user (07-Oct-26, Baru); payload uji `harness/ab_golden_multiuser/payloads/` |
| Target (ADDENDUM) | Golden Fastest CP ≤ 80.2521, HR ≤ 8251.58, cold ≤ 15 s; Max ≤ 80 s; rekomendasi ≤ 10 s; LNG/Distillate accepted ≤ 15 s; difficult feasible ≤ 30 s |
| Kinerja 92263fb (laporan ADDENDUM) | Golden Max 205 s; LNG accepted 15,97 s; P10 Follow PV 48 s; P13 43 s; P10 rekomendasi 14,6 s; P14 16,9 s; golden Fastest 15,5 s |

## Lingkungan uji
- **XAMPP-semantik**: proksi `v3/proxy.js` + N backend `php -S` PHP 7.4 (dev 8792, NB=6; uji multi-user NB=12).
- **Linux**: Apache 2.4.58 (mpm_event) + PHP-FPM 7.4 (24 worker), `/php74/opr-simulation` port 8074 (`setup_linux.sh`).
- Browser: Chromium (Playwright) — seluruh angka waktu diukur dari klik Run sampai 48 row FINAL tampil di UI (`t_end`), bukan waktu CLI.
- Mesin uji: 4 vCPU. "Cold" = cache job/memo dibersihkan; "warm" = state identik diulang.
