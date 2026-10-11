# V8_CRITICAL_PATH_PROFILE — real UI timestamps (V15.18)

Method: a profiling copy of the source (`harness/v1518/mkprof.py`) writes `microtime` timestamps for every core run
(start/end, caller, gas, CP). It also records isolated-core HIT/MISS, supplier window search (attempts, rejections,
mode, bracket observations), `pp_phase_mark`, and the start/end of the review/proof functions. Runs go through the
real UI (Playwright → proxy → PHP 7.4 built-in server, 3 job helpers). Time zero is the browser's `POST mode=run`.
The production source contains no tracer.

## 1. T3 (PGN −1) — before vs. after
### Before (commit a38cede), 33.2 s click → FINAL
| Phase | Interval (s) | Duration | Notes |
|---|---|---|---|
| request → job | 0.0–0.4 | 0.4 | |
| baseline core | 0.4–1.3 | 0.9 | 1 supplier attempt |
| sequence lock R1 (3 parallel variants) | 1.3–8.5 | 7.2 | variant 2 (0.6 s) fails, variant 1 (7.2 s, 6 supplier attempts) chosen |
| decommit screening (G1/G5 12:00–24:00) | 8.6–16.5 | 8.0 | both **rejected** (export_range), owner computes the 8.0 s candidate |
| gas window correction att 0 (serial) | 16.6–24.3 | 7.7 | its basis = the decommit basis, **known before** decommit started |
| polish + V8 review (3 parallel candidates) | 24.3–30.2 | 5.9 | longest candidate 4.3–5.6 s (11 supplier attempts) |
| proof + publish + fetch/render | 30.2–33.2 | 3.0 | server ≤ 0.2 s; fetching 360 KB + browser render ≈ 1.4 s |

### After (V15.18), 27.7 s (server-profile run; batch B 24.2 / 26.2 / 26.2 s)
The gas window correction att 0 (`pp_v1518_gwc_first_input`, a pure function of the basis) is published as a
**speculative** task alongside the decommit candidates. If no decommit candidate is accepted, the corrector uses the
isolated core with the same input hash, so the result is a HIT. Decommit (8.1 s) and correction (7.6 s) now run in
parallel: −7.7 s on the critical path. The result is bit-identical (fingerprint `ad8f9b2e…`, CP 71.5546), verified with
`PP_V1518_GWC_ISO=0` vs. default.

## 2. T0 (BBLN1 Stop All Day + Block 1)
| Phase | Duration | Notes |
|---|---|---|
| baseline core | 4.8 s | 7 supplier attempts |
| decommit (1 candidate) + paired decommit | 3.6 + 5.6 s | serial; paired = 1 core |
| V8 review (3 candidates, 4 workers) | 12.5–15.1 s | one candidate: **16 supplier attempts, 11 rejected** (in-window landings rejected by the `unit_ramp` guard; the last 4 have an identical internal target) |

Patch: deterministic stop after **3 consecutive in-window landings rejected by the non-gas guard**
(`V1518_WINDOW_LANDING_REJECTED_NON_GAS`). `$out` stays the last accepted candidate, exactly as when the search runs
until it is exhausted. 16/16 T/M cases have identical fingerprints.

## 3. Candidates/cores per run (Fastest, UI)
| Case | Candidates checked / valid | Core sims in pipeline | V8 cap | Unresolved C2/C4 units |
|---|---|---|---|---|
| T0 | 35 / 10 | 27 | 3 | from the audit (per run) |
| T3 | 19 / – | 41 | 3–4 | G5, G1, G3 (+G4 on T3+IE 18:00) |
| T6 | 33 / 8–9 | 34 | 3 | G5, G4, G3 |
| golden | 33 / 8 | – | – | – |

## 4. Isolated-core cache (job-level, input-hash key)
- Lock: 3 variants, prefetch → owner HIT 2/2 for the evaluated variants.
- Decommit + speculative gwc: 3 tasks → owner HIT 3/3 (T3).
- Counter `__pp_core_runs`: a HIT now counts as one evaluation, so admission is the same with or without helpers.

## 5. Main remaining cost
The PGN supplier window search: each attempt is about 0.5 s (one `pp_run_simulation_once`) and the physical response
is discrete (jumps over the 0.04 BBTUD window). A core needs 1–16 attempts. This dominates the lock (7 s), decommit
(8 s), V8 (4–9 s) and IE warm stage B (3.6 + 5.2 s). A faster method (secant / supplier hint) was tested in the
previous session but **changed the landing point**: T3 C4 FAIL 10, M1 CP +0.13 or infeasible. It therefore stays
opt-in (`PP_V1518_SUP_SECANT=1`) and is not the default.
