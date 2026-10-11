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

## 3. Results (real UI, one tab: base Run → change IE → Run; time = click → 48-row FINAL, 100 ms poll)
IEW1 = +5 MW 10:00–12:00, IEW2 = −5 MW 14:00–15:00, IEW3 = +8 MW 18:00–19:30.

| Case | Change | Class | Reason (proof) | UI s | Engine s | CP | Prelim |
|---|---|---|---|---|---|---|---|
| Golden (typed litres) | IEW1 | **A** | local window valid, fuel unchanged | 3.68 | 2.28 | 80.1564 | skipped |
| Golden (typed litres) | IEW2 | **A** | same | 4.64 | 2.66 | 79.8346 | skipped |
| Golden (typed litres) | IEW3 | B | local window invalid (export_range/runtime) → 48-row gas-window | 4.16 | 2.52 | 79.7324 | skipped (IE warm stage B applies) |
| Golden (recommended litres) | IEW1 | B | FUEL_CLOSING_CHANGED (litres 101100.4 → 103476.5) | 6.95 | 2.02 (+ fuel analysis) | 80.1564 | rerun |
| Golden (recommended litres) | IEW2 | B | FUEL_CLOSING_CHANGED | 6.98 | 2.46 | 79.8346 | rerun |
| Golden (recommended litres) | IEW3 | B | 48-row gas-window | 6.97 | 2.44 | 79.7324 | rerun |
| T0 | IEW1 | B | local window invalid (gas/supplier window) → 48-row gas-window | 13.79 | 12.02 | 72.0821 | – |
| T0 | IEW2 | **A** | local window valid | 2.90 | 1.15 | 72.0500 | – |
| T0 | IEW3 | B | 48-row gas-window | 5.96 | 4.37 | 71.9567 | – |
| T3 | IEW1 | B | 48-row gas-window | 6.92 | 5.00 | 71.5704 | – |
| T3 | IEW2 | B | 48-row gas-window | 7.91 | 5.62 | 71.5381 | – |
| T3 | IEW3 | B | **Commitment repair required** (basis commitment infeasible: stages A and B invalid) | 27.7–34.9 | 26.2–33.4 | 71.4436 | – |
| T6 | IEW1 | B | 48-row gas-window | 14.92 | 13.07 | 72.0821 | – |
| T6 | IEW2 | **A** | local window valid | 2.94 | 1.25 | 72.0500 | – |
| T6 | IEW3 | B | 48-row gas-window | 5.88 | 4.02 | 71.9567 | – |

Golden equivalence (typed litres, without fuel analysis, vs. the two-step flow): fingerprint, CP, HR, Distillate
litres (96 318.4 / 92 822.1 / 92 168.2 l), Distillate unit (G6 only), continuous block (rows 26–48 / 27–48 / 26–48)
and 30/50/75/100 mix are **identical**. Merit C1–C4 and gate PASS in every run.

After the parity fix, T6 equals T0. Its old IEW1 result (30.4–38.3 s) came from the UI payload that broke parity; it is
now 48-row Class B at 14.9 s.
