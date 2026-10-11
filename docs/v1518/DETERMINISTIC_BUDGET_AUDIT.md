# DETERMINISTIC_BUDGET_AUDIT — wall-clock vs. deterministic budgets (V15.18)

Principle (§8): wall-clock is only an **emergency watchdog**. Which candidates are examined, how many core runs are made
and which winner is chosen are determined by **counts**: unique candidates, core evaluations, proof evaluations and
supplier attempts. These must be the same on fast and slow CPUs, with or without helpers.

## 1. Inventory of time checks in the Fastest path

| Location | Old mechanism | Status in V15.18 |
|---|---|---|
| Fastest job deadline `pp_v13f_*` / V10 (`run.php` `$dl = $t0 + 120`) | `$dl - x` checks in many stages | **watchdog**: the normal path finishes ≤ 40 s (CLI serial ≤ 55 s), far below 120 s. Every `$dl - 2.0`, `$dl - 4.0` and `$dl - 8.0` gate only applies when the job is stuck |
| V8 review Fastest (`__pp_v8_fast_wall`) | wall = remaining time from the 11.5 s target (V15.16) | since V15.17b, `detV8`: **cap of 4 candidates, one round**, fixed batch of size = cap; wall 45 s = watchdog |
| V8 polish (`__pp_polish_max_evals`) | time-limited | **8 evaluations** (Fastest) |
| Decommit iteration (`pp_stage_admits`) | remaining wall-clock | Fastest: `__pp_core_runs ≤ 12` (**count**) |
| Decommit stop-window candidate (`pp_budget_exceeded`, `pp_stage_admits`) | wall-clock | Fastest (`$detDecW`): skipped; bounded by 3 units × windows and the core-run budget |
| Isolated core cache HIT | not counted toward `__pp_core_runs` | **fixed**: a HIT counts as one evaluation, so with or without helpers the decommit admission is identical |
| Iso-core `__pp_dist_schedule` | global from the last serial core | **fixed**: the iso core stores the realised schedule with its result and applies it on return (serial parity for the distillate-mode gas corrector) |
| Sequence lock R1 (3 stagger variants) | — | variants are evaluated **in a fixed order**, the first one that passes is chosen; helpers only prefetch |
| Gas window correction att 0 | serial core | isolated core keyed by input hash; **speculative** prefetch during decommit screening (used only if no decommit candidate is accepted) |
| PGN supplier window repair | budget ladder 60 s → ceiling (watchdog), plafon 32 attempts | new: stop after **3** consecutive in-window landings rejected by the non-gas guard (count) |
| IE warm stage A polish | `dl = now + 2.5 s`, gate `dl − 2.0` (a 0.5 s window, so the number of evaluations depended on CPU speed) | **8 evaluations** (`PP_IE_WARM_POLISH_N`), dl 30 s = watchdog. With 8, the IE0–IE8 chain is never worse than the PHP 7.4 wall-clock baseline (REGRESSION_STATUS_FINAL.md) |
| IE warm stage B (`pp_tl_eval` + landing) | `dl = now + 6 s` (truncated core on a slow CPU) | dl 60 s = watchdog; landing bounded by **N = 10 evaluations** |
| IE warm landing (`PP_IE_WARM_LAND_S`) | 20 s | 60 s watchdog; N = 10 evaluations |
| Per-node polish in `pp_tl_eval` (IE stage B, landing, V8 land candidates, family nodes) | `dl = now + 3 s`, gate `dl − 2.0` (a ~1 s window) | **12 evaluations** (`PP_TL_POLISH_N`), dl 30 s = watchdog. Measured T3 → IEW1 → IEW2 in the UI: 13 vs 16 evaluations, so the polish evidence and the C1 priority fix differed (CP 71.5381 vs 71.5365 in 8 of 20 runs) |
| STG memo (`pp_recompute_stgs`) | key = feeder loads quantised to 0.01 MW and kept for the life of the process, so a hit can return the value of a neighbouring load: measured on T3, 116 193 of 1 092 770 hits (10.6 %) differ from the exact value. The content therefore depended on which tasks the owner had computed itself rather than receiving from a helper | **empty at the start and end of every candidate evaluation** (`pp_tl_eval`, isolated core, helper task, both on HIT and on compute) and at the start of polish, the V8 review and the C1 priority fix. The priority fix also runs isolated from `__pp_*` globals left by the last core run. The memo key and the calculation are unchanged |
| `pp_budget_exceeded` in the core (async job) | adaptive ladder up to the job ceiling (900 s) | watchdog; on a hit, `stages_truncated` is recorded and publish is blocked |

## 2. Behaviour when the watchdog is reached
- A candidate whose proof is incomplete is **not** published (Simulation Acceptance Review / release gate).
- The status is reported honestly: `stages_truncated`, `DEADLINE_REACHED`.
- The fixed evaluation order means a truncated run never picks a different winner than the full run. It only stops
  earlier.

## 3. Determinism verification
- **A/B on identical source, two ways of execution.** T0–T9 and M0–M5 in CLI (serial, no helpers) produce winner
  fingerprints identical to the UI (helpers 3). They are also identical to the reference commit a38cede. 16/16 have
  the same fingerprint, CP and HR (see `PERFORMANCE_BEFORE_AFTER_V1518.md`).
- **Slow CPU.** CLI serial is 1.5–2× slower than the UI with helpers. The fingerprint is the same.
- **Iso-core HIT/MISS.** The evaluation counter is the same, so admission is the same.
- **Owner vs. helper history (final).** UI, T0 then T3 with IEW1–IEW3 in one tab. Before the fix, T3 IEW2 was 71.5365 in
  8 of 20 runs and 71.5381 in the others. After the fix (count-limited node polish + STG memo boundaries + isolated
  priority fix) it was 71.5381 in 4 of 4 runs. CLI T0–T9 and M0–M5 are 17/17 identical to before (fingerprint, CP, HR).
