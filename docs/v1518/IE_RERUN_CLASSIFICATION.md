# IE_RERUN_CLASSIFICATION — IE Adjustment rerun Classes A / B / C

## 1. Rule (from the structure of the solution, not from time spent)
The engine writes `info['IE Rerun Classification']` (`pp_v1518_ie_classification`, run.php) for every Run where the IE
basis is available (`IE Incremental Redispatch.cache = HIT`).

| Class | Structural condition (proof) | Target |
|---|---|---|
| **A** — local redispatch | stage A (affected window ±2 rows, basis commitment locked outside the window) **valid** against the original input **and** the operator fuel decision (action + authorised amount) is identical to the basis | engine ≤ 5 s, UI ≤ 8 s |
| **B** — commitment / fuel / gas-window change | stage A invalid → 48-row reconstruction with the basis commitment (stage B: gas-window landing) **or** the basis commitment is infeasible → *Commitment repair required* (startup/shutdown/family/STG sequence); **or** the fuel decision changed (*fuel closing*) | UI ≤ 30 s (difficult feasible), not a warm fast-path |
| **C** — terminal | no valid plan after the proof (terminal certificate) | terminal proof ≤ 15 s |

Recorded fields: `class`, `reason`, `stage` (*Local redispatch* / *Gas-window reconstruction (48 row)* /
*Commitment repair required* / *Terminal proof*), `affected_rows`, `commitment_before`/`commitment_after`,
`startup_shutdown_required`, `fuel_decision.before/after/unchanged/fuel_prelim`, `gas_window_after`, `basis_cp`,
`cp_after`, `release_gate`, `stages` (validity and failing checks of stages A/B) and `engine_s`.
Class B commitment repair also sends the job progress stage `COMMITMENT_REPAIR_REQUIRED`, which the UI shows as
"commitment repair required".

## 2. Golden — fuel decision
- The golden UI flow is fuel analysis followed by the final Distillate run. When the operator **does not** type a litre
  amount, the final litres are the engine **recommendation**, recomputed from the new shortage. A different IE value
  changes the shortage hash, so this is **fuel closing → Class B**. The fuel analysis must be rerun, and the litres
  follow the new shortage.
- When the operator **types** a litre amount (the fuel decision is fixed) and the fuel context is identical except for
  IE Adjustment, the browser does **not** rerun the fuel analysis. The final payload is sent directly
  (`_prelim_skipped`). The server only accepts it when the IE warm redispatch applies (its result does not depend on the
  preliminary analysis) and Distillate is still needed. Otherwise it returns `PRELIM_REQUIRED` and the browser runs the
  normal two-step flow. The autosave history is identical (preliminary payload, then final payload).

## 3. Results (final source; real UI, one tab: base Run → change IE → Run; time = click → 48-row FINAL)
IEW1 = +5 MW 10:00–12:00, IEW2 = −5 MW 14:00–15:00, IEW3 = +8 MW 18:00–19:30. IE warm polish = 8 evaluations
(REGRESSION_STATUS_FINAL.md). Raw logs: `harness/v1518/perf_logs/XIg.log`, `XIr.log`, `XI.log`, `XW.log`, `XH.log`.

| Case | Change | Class | Reason (proof) | UI s | Engine s | CP | HR | Prelim |
|---|---|---|---|---|---|---|---|---|
| Golden (typed litres) | IEW1 | **A** | local window valid, fuel unchanged | 3.23 | 2.58 | 80.1646 | 8237.16 | skipped |
| Golden (typed litres) | IEW2 | **A** | same | 2.04 | 1.51 | 79.8254 | 8238.46 | skipped |
| Golden (typed litres) | IEW3 | B | local window invalid → 48-row gas-window, basis commitment kept | 3.15 | 2.54 | 79.7324 | 8233.54 | skipped (IE warm stage B applies) |
| Golden (recommended litres) | IEW1 | B | FUEL_CLOSING_CHANGED (litres follow the new shortage) | 5.52 | 2.37 (+ fuel analysis) | 80.1646 | 8237.16 | rerun |
| Golden (recommended litres) | IEW2 | B | FUEL_CLOSING_CHANGED | 4.87 | 1.54 | 79.8254 | 8238.46 | rerun |
| Golden (recommended litres) | IEW3 | B | 48-row gas-window | 5.25 | 2.39 | 79.7324 | 8233.54 | rerun |
| T0 | IEW1 | B | local window invalid (gas/supplier window) → 48-row gas-window | 10.97 | 10.37 | 72.0821 | 8474.42 | – |
| T0 | IEW2 | **A** | local window valid | 2.01 | 1.51 | 72.0498 | 8469.45 | – |
| T0 | IEW3 | B | 48-row gas-window | 4.26 | 3.65 | 71.9567 | 8454.42 | – |
| T3 | IEW1 | B | 48-row gas-window | 5.01 | 4.48 | 71.5704 | 8395.21 | – |
| T3 | IEW2 | B | 48-row gas-window | 6.10 | 5.46 | 71.5381 | 8390.32 | – |
| T3 | IEW3 | B | **Commitment repair required** (basis commitment infeasible: stages A and B invalid) | 26.57 | 25.98 | 71.4436 | 8375.69 | – |
| T6 | IEW1 | B | 48-row gas-window | 11.50 | 10.99 | 72.0821 | 8474.42 | – |
| T6 | IEW2 | **A** | local window valid | 2.40 | 1.74 | 72.0498 | 8469.45 | – |
| T6 | IEW3 | B | 48-row gas-window | 4.82 | 4.12 | 71.9567 | 8454.42 | – |

Same IE changes as separate page loads (`warm3`, the basis is the previous run): T3 IEW3 27.2 s, T0 IEW1 10.6 s, T6 IEW1
11.1 s, golden IEW1–IEW3 5.0–6.0 s. T3 IEW3 without a browser (headless client, 3 runs): 24.7 / 25.3 / 27.1 s (engine
19.1–21.6 s job wall). In earlier rounds of this session it reached 30.4–30.9 s in the UI. Two deterministic changes
brought it under 30 s: prefetching the Fastest entry core and the sequence-lock R1 variants during stages A/B
(SUPPLIER_SEARCH_MEMOIZATION_AUDIT.md §2b). The fingerprint did not change (`6fca3a84…`, also on Linux).

Targets: every Class A run is ≤ 2.6 s engine / ≤ 3.3 s UI (target 5 / 8). Every Class B run is ≤ 27.2 s UI (target 30).
Class C (terminal): T5 (G9 SC marker conflict) proves VALID-INFEASIBLE in 5.3 s CLI serial (target 15 s), with no
economic review after the terminal certificate.
Merit C1–C4 = 0 and release gate PASS in every feasible run.

Golden IEW1/IEW2 cross-check, CLI, same chain on the PHP 7.4 baseline `78b72d5` (wall-clock polish): IEW1 is
**bit-identical** (fp `d0362ac18e9ed252`, CP 80.1646); IEW2 final 79.8254 ≤ baseline 79.8257.

Golden equivalence (typed litres with the fuel analysis skipped vs. the recommended-litres two-step flow, same tab
sequence, final source): CP, HR, all 48 rows, Distillate litres (93 439.7 / 96 352.8 / 92 731.2 / 92 168.2 l for
base / IEW1 / IEW2 / IEW3), Distillate unit (G6 only), continuous block (rows 26–48 / 26–48 / 27–48 / 26–48) and
30/50/75/100 mix are **identical**. Merit C1–C4 and gate PASS in every run.

After the parity fix, T6 equals T0. Its old IEW1 result (30.4–38.3 s) came from the UI payload that broke parity; it is
now 48-row Class B at 11.1–11.5 s.
