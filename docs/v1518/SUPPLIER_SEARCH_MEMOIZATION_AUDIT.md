# SUPPLIER_SEARCH_MEMOIZATION_AUDIT — PGN supplier window search (V15.18)

Principle: the landing point stays **bit-identical**. Secant/hint search is **not** the default
(`PP_V1518_SUP_SECANT=1` is opt-in only). In earlier measurements it changed the landing point: T3 C4 FAIL 10, M1 CP
+0.13 or infeasible. Every optimisation below only removes work whose result is already known.

| # | Instruction | Mechanism in source | Proof of identical result |
|---|---|---|---|
| 1 | Memoize target evaluations by full context hash | **Isolated core** `pp_v1518_isolated_core`: md5 key of the full input (including target/offset); cache file per job; helper and owner produce byte-identical output. **Candidate-state pool** `pp_cs_key/pp_cs_get/pp_cs_claim` (land/eval V8, IE warm): key = all numeric input values | T0–T9, M0–M5 fingerprints identical with and without helpers (CLI serial vs UI 3 helpers) |
| 2 | Dedup identical targets before a core run | The same input → the same iso key / cs key: the second request is a HIT. Prefetch tasks are deduplicated by key (`pp_v4_task_key`) | HIT counted as an evaluation (`__pp_core_runs`), so admission stays identical |
| 3 | Cache physical response per candidate/target | `$supObs[internal target] = physical pipe` (regula falsi V10) and `$supCache` (flat look-ahead) inside one search; `pp_fuel_sum_g19` per-cell fuel memo (`$fc`) and `pp_recompute_stgs` STG memo across shaper iterations | Memos are compared with `===` on row content; summation order unchanged |
| 4 | Reuse a rejected landing with an identical finding set | **New V15.18**: in-window landings (`W`) rejected by the non-gas guard 3 times in a row → search stops (`V1518_WINDOW_LANDING_REJECTED_NON_GAS`); `$out` = the last accepted candidate | Measured T0: 11 consecutive rejections, the last 4 at an identical internal target (25.895 → 22.979, `unit_ramp`). 16/16 T/M fingerprints identical |
| 5 | Cancel a branch after a stagnation proof | Existing: `V1517_RESPON_TIDAK_KONTINU_BERHENTI` (discontinuous response), `V7_ENVELOPE_CONFLICT`, `V4_FUTILE_OVER_AT_ZERO`, `BRACKET_RESOLUTION_EXHAUSTED`; gas-window corrector: repeated physical-state cycle and "lever inert" | Unchanged from the reference |
| 6 | Parallelise only unique targets | `pp_v1518_iso_prefetch`: tasks = unique keys (lock variants, decommit candidates, **speculative gas correction**); V8 land tasks deduplicated by key | Parallel/serial produce the same results (keyed by input) |
| 7 | Do not repeat full V8/C4 when the dispatch hash is unchanged | `pp_v8_priority_review`: `rows_sig` identical and status ≠ SKIPPED → return; proof binding `candidate_hash` + `bound_to_rendered` (proof recomputed only when the rendered candidate changes) | – |

## 2. Speculative gas correction (pure function of the basis)
`pp_v1518_gwc_first_input($in, $out)` = the att 0 step of `$fixGasWindow` (trim/up). It is published alongside the
decommit candidates. If no candidate is accepted, the corrector uses an iso core with the same key, so the result is a
HIT (T3: −7.7 s on the critical path, fingerprint `ad8f9b2e…` identical with `PP_V1518_GWC_ISO=0`). Distillate mode is
excluded because the distillate schedule pin is read from the global state.

## 3. Proof reuse
Proof (C1–C4, STG, block sequence, SC/CC, effective priority, unit limits, normalized payload) is bound to
`candidate_hash` (48 rows × units). It is reused only when that hash and the context hash are identical. If the
rendered data changes, the proof is invalidated, recomputed and bound again (`rebound`).

## 4. Hot-path micro-optimisations (bit-identical)
- `pp_fuel_sum_g19`: summing an unchanged row = 9 sequential additions written out (same order as `foreach`). About
  7% less CPU on golden (7.25 → 6.76 s user+sys, 3 alternating pairs).
- `pp_is_unit_stopped`: non-GE units bypass the `pp_effective_unit_available → pp_unit_present → pp_req_gen` chain;
  presence is memoized with the same invalidation rule (request generation); GE units still take the full path.
