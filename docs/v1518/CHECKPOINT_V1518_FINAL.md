# CHECKPOINT V15.18 FINAL — SC/CC sequence, IE parity and performance

- Branch: `claude/gifted-ritchie-q0hfdp`. Started from `5a60d8a`; intermediate commits `40fc88c`, `210b8a5`, `96b0ba1`.
  The final commit is the commit that adds this file (see `git log`).
- Package: `php_simulation_SC_CC_SEQUENCE_FAST_FINAL.zip`. It contains the 5 PHP files at the root, `docs/` (this
  checkpoint and all reports) and `SHA256SUMS.txt`.
- Runtime: PHP 7.4 (XAMPP or Linux Apache + PHP-FPM). The async job CLI worker uses 3 helpers.

## Status
| Area | Status |
|---|---|
| Functional / validation / quality | PASS (TARGETED_TEST_STATUS_FINAL.md) |
| UI/CLI T6 parity (P0) | PASS: root cause B fixed, one canonical normalisation (UI_CLI_T6_PARITY_ROOT_CAUSE.md) |
| IE classes A/B/C (P1) | PASS: class from the solution structure; A ≤ 2.6 s engine / 3.3 s UI, B ≤ 27.2 s UI, C 5.3 s |
| Golden Class A without the fuel analysis (P2) | PASS: typed litres, `_prelim_skipped`, result identical to the two-step flow |
| T6 IEW1 / T3 outlier (P3) | PASS: T6 IEW1 10.9–11.5 s (Class B); T3 cold 22.7–24.7 s across 12 runs |
| MIN SC / MIN CC / MAX (P4) | PASS: M0–M5 0 FAIL |
| Regression, 38 cases | PASS: 0 regressions against the PHP 7.4 baseline (REGRESSION_STATUS_FINAL.md) |
| Determinism | PASS: count limits, STG memo boundaries, isolated C1 fix |
| XAMPP / Linux parity | PASS: 8/8 + IE commitment repair identical |

## Rollback switches (environment variables, default = new behaviour)
`PP_V1518_IE_ENTRY_PRE=0` (no entry prefetch), `PP_V1518_IE_ENTRY_CHAIN=0` (entry core only),
`PP_TL_POLISH_N=0` (node polish back to the 3 s wall-clock window), `PP_V1518_FIX_ISOLATED=0`,
`PP_IE_WARM_POLISH_N=<n>`, `PP_V8_C24_UNITS=<n>`.

## How to reproduce
- CLI: `php7.4 harness/v1518/dumpjob.php <src> <fixture.json> <out.json>` (or `harness/v1518/runv.sh`), then
  `python3 harness/v1518/fp.py`, `chk.py`, `mchk.py`.
- UI: `harness/v1518/cold3.sh`, `warm3.sh`, `iesuite.sh` (Playwright); headless: `hrun.js <base> <payload>`.
- Regression: `harness/v1518/fxall_final3.sh`, compare with `cmp.py old74.log final3.log`.
