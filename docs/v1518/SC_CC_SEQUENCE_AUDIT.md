# SC/CC SEQUENCE AUDIT

Ramp G8/G9 SC **tidak ditebak**: diturunkan dari routine G7/G10 yang sudah ada di worker, lalu dibuktikan dengan probe.

## 1. Routine yang menentukan sequence

| Routine | File | Peran | G7/G10 (SC teknis) | G1–G6 CC | G8/G9 CC |
|---|---|---|---|---|---|
| `pp_start_sequence($d3,$m,$u,$rows,$r)` | worker_functions | sumber tunggal sequence (engine + validator) | `[]` (tidak ada di feed STG) | STG fresh: caps STG mode; Additional: `[5,15]` | STG fresh: caps S3 mode; Additional `[40,40,50,60,60,70]` |
| `pp_startup_caps($stg,$mode)` | worker_functions | caps STG Cold/Warm/Hot | fallback `[5]` (toleransi saja) | S1/S2 cold `[5,15,20×5]`, warm `[5,15]`, hot `[5]` | S3 cold `[40,40,40,50,50,60×5,70,80]`, warm `[50,50,60,60,70]`, hot `[60]` |
| `pp_additional_hrsg_caps($u)` | worker_functions | caps Additional HRSG | `[5]` | `[5,15]` (lead-in 3 row = 1,5 jam) | `[40,40,50,60,60,70]` (lead-in 7 row) |
| `pp_stg_release_rows($stg,$mode)` | worker_functions | hold STG sampai first positive | — | S1/S2 cold 7 / warm 2 / hot 1 | S3 cold 11 / warm 5 / hot 1 |
| Start Based on Request (`required_mode start_at`) | worker02 normalisasi | hold `unit_stop_time [1..marker]`, marker = row Start At (0 MW, oranye), beban mulai row berikutnya | marker 0 MW → `min_scload` 30 | marker → sequence | marker → sequence |
| `pp_apply_startup_constraints` / shaper `applyStgHold` (`$seqOfFinal`) | worker_functions | menegakkan langkah sequence persis | caps `[5]` hanya toleransi, lalu release-to-min | langkah eksak | langkah eksak |
| `pp_unit_ramp_limit($u)` (baru, menggantikan 14 literal) | worker_functions | ramp per unit | 55 MW/30 mnt | — | 30 MW/30 mnt (SC: 55) |
| Validator `startup_sequence` / `min_load` / `unit_ramp` / `block_sequence` (8f, baru) | worker_functions | penolakan | — | 5/15 MW hanya di dalam startup | — |
| Fuel `calc_fuel` | worker_functions | kurva bahan bakar | formula g7/g8/g9 identik | per unit | identik dengan g7 |
| UI `cell-startup` / `sc-mode` | index.php | warna | marker oranye, GTG tanpa STG oranye | oranye selama STG 0 | sama |

Probe empiris G7 (`A_g7_start0500.json`, Start At 05:00): row 10 (05:00) = 0 MW marker oranye, row 11 (05:30) = 30 MW
(= `min_scload`), selanjutnya bebas; tanpa kontribusi STG; runtime class `gtg_large`.

## 2. Sequence SC V15.18

| | G1–G6 SC | G8/G9 SC (family G7/G10) |
|---|---|---|
| Startup row | row Start At = marker 0 MW oranye | sama |
| Load per startup row | tidak ada caps (caps SC = `[]`) | tidak ada caps |
| Transisi | row berikutnya dispatch bebas legal | row berikutnya bebas |
| Lantai | **MIN SC user** (`min_scload`; lihat SC_MIN_LOAD_AUDIT.md) | **MIN SC user** (`min_scload`; MIN CC hanya untuk mode CC) |
| Maks | MAX user (`max_load`) | MAX user (`max_load`) |
| Ramp | — (sama dengan CC) | 55 MW/30 mnt |
| STG | tidak ada kontribusi (unit keluar dari `d3[sX].hrsg/gtg`; salinan `hrsg_configured`/`gtg_configured`) | sama |
| Warna | oranye sepanjang SC (`info['SC CC Mode'].sc_units`) | sama |
| CP/HR | dihitung dari dispatch nyata tanpa STG (HR SC) | sama |
| Effective priority | terakhir di grup CC (configured tidak berubah) | sama |
| Hold sampai 00:00 | hanya bila Stop Status = Unit Continuous Running | sama |

Revisi MIN SC: lantai 20 MW untuk G1–G6 SC dicabut. Semua unit SC memakai MIN SC user dan semua unit CC memakai MIN CC user
(lihat SC_MIN_LOAD_AUDIT.md, uji M0–M5).

## 3. Bukti uji
- T4 (G6 SC, Start Request 05:00, Stop Based On Simulation): G6 rows 1–10 = 0, row 11 (05:30) = 20 MW, S1 feed = [G3, G4], S1 = 0 setiap row G3=G4=0, G6 terakhir (G4 → G3 → G6). PASS.
- T5 (G9 SC, PGN 28, Start Request 05:00, Stop Based On Simulation): G9 rows 1–10 = 0, row 11 = 40,5 MW ≥ 30, ramp maks 25,1 ≤ 55, S3 feed = [G8]. PASS.
