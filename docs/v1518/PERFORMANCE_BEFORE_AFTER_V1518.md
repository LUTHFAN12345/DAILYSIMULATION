# PERFORMANCE_BEFORE_AFTER_V1518

> History of the first V15.18 round. The final numbers are in **PERFORMANCE_FINAL_COLD_WARM.md**. In this table T6 still
> shows the pre-parity UI payload (CP 71.7732); after the parity fix T6 = T0 = 71.9896.

Measurement is UI end-to-end: Playwright clicks Run, and the clock stops when the app reaches state FINAL with 48 rows
rendered (500 ms poll; includes ±1.4 s of harness overhead compared with the app's own timer). Cold = server cache
(`jobs/`) cleared before every run. Machine: 4 cores, PHP 7.4 built-in server behind a multi-backend proxy (XAMPP-like),
3 job helpers.

## 1. Cold Fastest (3 runs)
| Case | Instruction §3 (before) | Start of this session (A) | V15.18 final (B) | Target | CP (all runs) | HR |
|---|---|---|---|---|---|---|
| T0 | 37.2 | 26.2 / 26.2 / 25.2 | 25.2 / 26.2 / 25.0 | ≤ 30 ✔ | 71.9896 | 8459.56 |
| T1 | 32.7 | 19.1 / 20.1 / 20.1 | 22.0 / 19.2 / 19.4 | ≤ 30 ✔ | 72.1938 | 8490.96 |
| T3 | 39.6 | 35.4 / 38.3 / 36.1 | 24.2 / 26.2 / 26.2 | ≤ 30 ✔ | 71.5546 | 8392.72 |
| T6 | 37.2 | 18.2 / 18.1 / 16.1 | 19.1 / 20.2 / 21.3 | ≤ 30 ✔ | 71.7732 | 8426.87 |
| Golden (T9) | 9.2 | – | 8.1 / 8.3 / 8.1 | ≤ 15 ✔ | 79.9049 | 8239.87 |
| Cold IE golden (+10 MW 10–12) | timeout | – | 8.1 / 9.1 / 8.1 | ≤ 15 ✔ | 80.3792 | 8237.25 |
| Cold IE T0 (+10 MW 10–12) | – | – | 21.3 / 21.2 / 20.1 | difficult ≤ 30 ✔ | 72.1424 | 8483.69 |

Note: one T3 prime run in the warm batch measured 30.3 s (CPU contention with Chromium on a 4-core container). The 6
other T3 runs were 24.2–27.7 s.

## 2. Warm (server cache retained)
| Case | Warm rerun same input (3×) | Target ≤ 10 s |
|---|---|---|
| Golden | 2.0 / 2.1 / 2.1 | ✔ |
| T0 | 2.0 / 2.1 / 2.0 | ✔ |
| T3 | 2.0 / 2.1 / 2.0 | ✔ |
| T6 | 2.1 / 2.0 / 2.0 | ✔ |

### Warm IE Adjustment (small change, basis = previous run)
| Case | IEW1 (+5 MW 10–12) | IEW2 (−5 MW 14–15) | IEW3 (+8 MW 18–19.30) | Target ≤ 5 s | Notes |
|---|---|---|---|---|---|
| Golden (two steps: fuel analysis + final distillate) | UI 7.1 (app 5.5) | UI 7.4 (app 5.9) | UI 7.1 (app 5.6) | engine-level: step 1 ≈ 1.9 s + step 2 ≈ 1.9–2.4 s | the C1 priority fix (22 frozen-evals, 1.5 s) is mandatory |
| T0 | UI 15.9 (stage B 11 s) | UI 3.0 | UI 6.0 | ✘ for IEW1/IEW3 | stage B = 48 rows, commitment fixed + supplier landing (6 + 9 attempts) |
| T3 | UI 8.2 | UI 9.1 | blocked by C4 in the reference (fixed, see §3) | ✘ | stage B |
| T6 | UI 30.4–38.3 | UI 6.1 | UI 3.2 | IEW1 = commitment repair (stage A/B invalid: export_range) | full cold path |

## 3. Quality fix found during measurement
T3 + IE +8 MW 18:00–19:30. The reference a38cede (and the earlier V15.18) produced C4 FAIL 10, so the release gate
blocked it and the UI never reached FINAL. Cause: the Fastest V8 review truncated the list of units with unresolved
C2/C4 findings to **3**, so the 4th unit (G4) had no counterfactual. The limit is now 4 (= owner + 3 helpers, one
parallel batch). Result: C1–C4 = 0, gate PASS, dispatch identical (fingerprint `7251074f…`, CP 71.4436).

## 4. Summary of patches and effect
| Patch | Effect | Bit-identical? |
|---|---|---|
| Speculative gas window correction during decommit screening | T3 −7.7 s on the critical path | ✔ (16/16 fingerprints identical) |
| Stop after 3 in-window landings rejected by the non-gas guard | −3–5 s on long V8 candidates (T0) | ✔ on 16/16 (the final regression rechecks) |
| Iso-core HIT counted + `__pp_dist_schedule` parity | determinism with/without helpers | ✔ |
| IE warm polish 1 evaluation, stage B/landing watchdog | wall-clock no longer decides the result | ✔ on the current machine |
| V8 C2/C4 units 3 → 4 | quality (T3+IE gate PASS) | changes only cases with ≥ 4 unresolved units |
