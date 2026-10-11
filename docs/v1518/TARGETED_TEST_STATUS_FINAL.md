# TARGETED_TEST_STATUS_FINAL — final source (V15.18)

Run on PHP 7.4. CLI = `dumpjob.php` (serial, no helpers). UI = headless Chromium against the XAMPP-like server.
Checkers: `harness/v1518/chk.py` (T), `mchk.py` (M), `u14.js`.

## 1. T0–T9 (CLI, final source)
| Test | Topic | CP | HR | Fingerprint | Checks | Result |
|---|---|---|---|---|---|---|
| T0 | STG S1 lead G6, hold to end | 71.9896 | 8459.56 | `153909b165d98c33` | hard, gate, C1–C4 = 0, STG, block sequence, proof binding, G6 hold-to-end, G6 semantics | **PASS** |
| T1 | G6 Continuous (full horizon) | 72.1938 | 8490.96 | `1972b118d72f7f2c` | + G6 48/48 rows, CONTINUOUS_FULL_HORIZON | **PASS** |
| T2 | Stop-or-Continuous family evidence | 71.289 | 8352.36 | `dc037044c28d1373` | + family evidence G6/G9 | **PASS** |
| T3 | Dynamic priority, G6 stop 16:00 | 71.5546 | 8392.72 | `ad8f9b2efbf4342c` | + G6 zero from 16:00, notification, effective priority row 31/32 | **PASS** |
| T4 | G6 SC (user MIN SC) | 72.7408 | 8575.34 | `bc1b3ce71ce194c5` | + marker 05:00 zero, free 05:30 ≥ user MIN SC, S1 not fed by G6, G6 SC last | **PASS** |
| T5 (official = T5c) | G9 SC | 77.7052 | 9333.86 | `828f51f6090c299a` | + G9 marker, ≥ min SC load, ramp ≤ 55, S3 fed by G8 only, G9 SC | **PASS** |
| T5 (marker conflict variant) | Class C terminal | 77.713 | 9802.96 | `644857aeacdfa38a` | VALID-INFEASIBLE in 5.2–5.5 s (terminal ≤ 15 s), identical to the reference | **PASS (terminal)** |
| T6 | Block Required + S1 requested (UI parity) | 71.9896 | 8459.56 | `153909b165d98c33` | + G6 auto lead, G6 hold policy, G4/G3 not auto | **PASS** |
| T7 | G6 SC makes G4 the lead of S1 | 72.0533 | 8469.94 | `1f7435c47749fc6f` | + G4 lead S1, SC notification | **PASS** |
| T8 | Block sequence monitoring reproducer | 71.289 | 8352.36 | `dc037044c28d1373` | + lead first, S1 cold hold, additional legal window, connect ≥ S1 first + 1, BB1 zero | **PASS** |
| T9 | Golden | 79.9049 | 8239.87 | `ee03d6e8a883975a` | + CP ≤ 80.2521, wall ≤ 15 s | **PASS** |

## 2. M0–M5 (MIN SC / MIN CC / MAX from user input)
| Test | CP | HR | Fingerprint | Checks | Result |
|---|---|---|---|---|---|
| M0 | 72.7408 | 8575.34 | `bc1b3ce71ce194c5` | 7/7 PASS | **PASS** |
| M1 | 72.8253 | 8588.41 | `c091c0727fd11b68` | 7/7 PASS | **PASS** |
| M2 | 72.7408 | 8575.34 | `bc1b3ce71ce194c5` | 7/7 PASS | **PASS** |
| M3 | 77.7365 | 9338.81 | `08f8ac2df0fe6bb6` | 7/7 PASS | **PASS** |
| M4 | 71.3373 | 8359.62 | `ff8501fee2703e2a` | 7/7 PASS | **PASS** |
| M5 | 72.7439 | 8575.82 | `123fc5ed6446c7fa` | 8/8 PASS | **PASS** |

The checks include: SC minimum = user MIN SC, CC minimum = user MIN CC, maximum = user MAX, no 20 MW hardcode, and
`unit_limits_hash` in the proof binding. All 17 fingerprints are identical to before this round of changes
(`hcheck.txt`).

## 3. Smoke S0–S11
| Smoke | Topic | Evidence | Result |
|---|---|---|---|
| S0 | Commitment Continuous vs Stop Continuous | T1 (full horizon) + T2 (stop-or-continuous family) | PASS |
| S1 | Dynamic Priority stop 16:00 | T3 | PASS |
| S2 | G6 SC | T4, M0/M2 | PASS |
| S3 | G9 SC | T5c | PASS |
| S4 | STG auto-follow | T0, T6 (UI = CLI, UI_CLI_T6_PARITY_ROOT_CAUSE.md) | PASS |
| S5 | G6 SC makes G4 the lead | T7 | PASS |
| S6 | Block-sequence monitoring reproducer | T8 | PASS |
| S7 | BBLN1 Stop All Day | T8 `BB1_zero` + block sequence PASS | PASS |
| S8 | Distillate 75 % continuity | golden: G6 only, rows 26–48 contiguous, levels 30/50/75/100 (CLI and UI, every IE run) | PASS |
| S9 | Actual Gas + IE Adjustment | CLI chain S9_base → S9_ie: 64.7168 / 64.6231, fp `60b53489…` / `34726f36…`, identical to the reference | PASS |
| S10 | Concurrency, two users | `conc.js`: distinct uid, B finishes while A runs, save while running, simultaneous save, A cannot load B's record, reload restores each user's IE, cancel of B does not affect A; 0 errors | PASS |
| S11 | Golden | T9 (CLI 7.4 s; UI cold 6.6–7.7 s, CP 79.9049) | PASS |
| U14 | Unit characteristic → payload (MIN SC / MIN CC / MAX) | `u14.js` | PASS |

## 4. IE Adjustment, parity, performance
- IE classes A/B/C with targets: see IE_RERUN_CLASSIFICATION.md. Every run passed.
- UI/CLI T6 parity: see UI_CLI_T6_PARITY_ROOT_CAUSE.md (normalized payload, fingerprint, CP/HR and proof hashes are
  identical).
- XAMPP-like vs Linux: 8/8 identical, plus T3 → IEW3 (PERFORMANCE_FINAL_COLD_WARM.md §5).
- Cold, warm and IE performance: see PERFORMANCE_FINAL_COLD_WARM.md (all within target).
- Regression (38 cases): see REGRESSION_STATUS_FINAL.md (0 regressions).

## 5. Gate summary
| Gate | Status |
|---|---|
| C1–C4 FAIL = 0 (every feasible test) | PASS |
| STG / block-sequence proof | PASS |
| Hard validation | PASS (T5 marker-conflict variant = expected terminal) |
| SC/CC, Dynamic Priority, Continuous semantics, Distillate, Actual Gas, IE Adjustment, BBLN1, Save/Reload, concurrency | PASS |
| Result independent of wall-clock/CPU speed | PASS (count limits; T3 IEW2 4/4; CLI serial = UI with helpers) |
