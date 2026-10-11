# REGRESSION_STATUS_FINAL — 38 cases, single run on the final source

## Setup
- Baseline: commit `78b72d5` (start of V15.18), **rerun with PHP 7.4** (`fxall_old74.sh` → `old74.log`).
  The older baseline logs (`old.log`, `new_final.log`) were produced with the container default `php` (8.4). That
  difference alone explained the whole "A WARM CP +0.19 / A5_multi PASS→FAIL" signal seen in the intermediate
  run: with PHP 7.4 on both sides, every A WARM case is identical.
- Final: the source in this commit (`fxall_final3.sh` → `final3.log`, after the determinism fixes; identical to the intermediate `final2.log`), PHP 7.4 CLI, Fastest, 1 run each.
- Sections: 19 cold fixtures (job cache cleared per fixture), IE warm chain IE0→IE8 (10 jobs, cache kept), Actual Gas
  warm chain A0→A6 (9 jobs, cache kept).
- Full side-by-side table: `REGRESSION_AB_FINAL_php74.txt`.

## Result
| Section | Cases | Gate change | CP worse (> 0.001) | CP better | Identical |
|---|---|---|---|---|---|
| Cold | 19 | D3_g6_unavail **FAIL → PASS** | 0 | D3 −6.7545, D5_g6_stop1000_1400 −0.1093 (still terminal), R1 −0.0124, SR5 −0.1766 | 15 |
| IE warm | 10 | none | 0 | IE4 −0.0008, IE5 −0.0007, IE6 −0.0005 | 7 |
| A warm | 9 | none | 0 | – | 9 |
| **Total** | **38** | 1 improvement | **0** | 7 | 31 |

Expected-infeasible fixtures are unchanged in status: D5_g6_stop1000_1400, D5_two_blocks_fixload,
R2_minflow45_infeas and the A0–A7 chain are terminal in both baseline and final (identical CP for all except D5, which
is lower).

## IE warm polish (finding of this run)
The intermediate final run (`new_v1518f.log`, polish limited to 1 evaluation) had IE6 +0.0513 compared with the PHP 7.4
baseline, whose polish was a 0.5 s wall-clock window. The polish is now limited to **8 evaluations**
(`PP_IE_WARM_POLISH_N`, default 8). It is still deterministic: the evaluation count does not depend on CPU speed.

| Chain | IE3 | IE4 | IE5 | IE6 |
|---|---|---|---|---|
| Baseline 78b72d5 (wall-clock polish) | 80.6848 | 78.8066 | 80.1875 | 79.9449 |
| Polish 1 evaluation | 80.6809 | 78.8056 | 80.1813 | 79.9962 ✘ |
| **Polish 8 evaluations (final)** | 80.6848 | 78.8058 | 80.1868 | 79.9444 |

Cost: +0.3–1.1 s on IE warm jobs (golden Class A still ≤ 5 s engine; see PERFORMANCE_FINAL_COLD_WARM.md).
Cold paths and T0–T9 / M0–M5 do not use this polish (16/16 fingerprints unchanged).

## Verdict
**PASS**: 0 regressions in gate, CP or HR across the 38 cases. C1–C4 and hard-validation status are identical or
better.
