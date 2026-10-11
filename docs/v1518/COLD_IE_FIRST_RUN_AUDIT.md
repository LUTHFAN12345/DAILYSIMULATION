# COLD_IE_FIRST_RUN_AUDIT — IE Adjustment before the first Run (V15.18)

## 1. Scenario
Load a saved/imported input, change the IE Adjustment, then click Run for the first time. There is no earlier Run
result, so no warm incumbent exists.

## 2. Path (run.php `pp_v13f_fast_job`)
1. `pp_v1517_ie_warm()` looks up the IE basis under `pp_v1517_ie_base_key()`. The key is a hash of the **whole
   structural context**: commitment, Stop Status, SC/CC, MIN SC/MIN CC/MAX, priority, block sequence, fuel action and
   Change Over. It excludes only the IE values, the fuel amounts (LNG/litres), Actual Gas (handled separately as
   `actual_gas_hash`) and request metadata `_*`.
2. Cold → `cache: MISS` (`TIDAK_ADA_BASIS_FULLY_VALID_DENGAN_KONTEKS_SAMA`) → **one** deterministic cold
   construction, the full Fastest pipeline: Last Data Status, Commitment Mode, Stop Status, SC/CC, Effective Priority,
   IE Effective and gas/fuel constraints all come from the current input.
3. No supplier hint from another run is used. A pipeline hint comes only from an evaluation in the same job (same
   input).
4. Gas landing (if the pipeline commitment is valid but the gas window has not landed) is bounded by **N evaluations**
   (deterministic), followed by one full validation (hard validator, C1–C4/STG, block sequence, SC/CC) before
   publishing.
5. There is no "try a stale hint, fail, then rerun the full pipeline" loop. A basis from a different context never
   matches the key. The warm basis is only written after a FINAL fully valid result.

## 3. Changes in V15.18
- IE warm stage A polish: the 2.5 s window (0.5 s effective) was replaced by **1 evaluation**. Stage B and landing
  `dl` became a 60 s watchdog; landing is still N = 10 evaluations (see DETERMINISTIC_BUDGET_AUDIT.md). The warm result
  no longer depends on CPU speed.

## 4. Measurements (real UI, cold = server cache cleared, 3 runs each)
| Case | Run 1 | Run 2 | Run 3 | CP | HR | Target |
|---|---|---|---|---|---|---|
| Golden + IE +10 MW 10:00–12:00 (CIE_golden) | 8.06 s | 9.14 s | 8.14 s | 80.3792 | 8237.25 | ≤ 15 s ✔ |
| T0 + IE +10 MW 10:00–12:00 (CIE_T0, difficult case) | 21.29 s | 21.18 s | 20.15 s | 72.1424 | 8483.69 | ≤ 30 s (difficult feasible) ✔ |

The results are identical across runs (CP/HR identical, release gate PASS, merit PASS). CIE_T0 is a cold difficult case
(G8/G9 + BBLN1 Stop All Day + Block 1). Its first Run costs the same as a cold T0 without IE (≈ 25 s) and stays below
the 30 s limit for difficult feasible cases.
