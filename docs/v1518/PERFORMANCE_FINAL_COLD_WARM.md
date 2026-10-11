# PERFORMANCE_FINAL_COLD_WARM — final source (V15.18)

Measurement setup:
- Container with 4 cores. PHP 7.4 built-in server with 6 backends behind a proxy (XAMPP-like); jobs use 3 helpers.
- **UI** = headless Chromium (Playwright) on the same 4-core host. Time is click Run → app state FINAL with 48 rows
  rendered, polled every 100 ms.
- **Headless** = Node client that sends the same requests as the browser (`mode=run`, `job_exec`, 3 × `job_help`, poll
  every 150 ms). No browser is running.
- Cold = server cache (`jobs/`) cleared before every run. Raw logs are in `harness/v1518/perf_logs/` (`XC.log`,
  `XW.log`, `XI*.log`, `XH.log`, `par.txt`).

## 1. Cold (3 runs each, UI)
| Case | Run 1 | Run 2 | Run 3 | Max | Target | CP | HR | Gate / Merit |
|---|---|---|---|---|---|---|---|---|
| Golden (T9) | 7.71 | 7.25 | 6.57 | 7.71 | ≤ 15 ✔ | 79.9049 | 8239.87 | PASS / PASS |
| T0 | 21.29 | 19.63 | 20.26 | 21.29 | ≤ 30 ✔ | 71.9896 | 8459.56 | PASS / PASS |
| T1 | 17.64 | 17.13 | 17.12 | 17.64 | ≤ 30 ✔ | 72.1938 | 8490.96 | PASS / PASS |
| T3 | 23.17 | 24.24 | 23.85 | 24.24 | ≤ 30 ✔ | 71.5546 | 8392.72 | PASS / PASS |
| T6 | 20.15 | 21.34 | 22.69 | 22.69 | ≤ 30 ✔ | 71.9896 | 8459.56 | PASS / PASS |
| Cold IE golden (+10 MW 10–12) | 7.16 | 7.84 | 7.33 | 7.84 | ≤ 15 ✔ | 80.3792 | 8237.25 | PASS / PASS |
| Cold IE T0 (+10 MW 10–12) | 18.64 | 18.95 | 19.23 | 19.23 | ≤ 30 ✔ | 72.1424 | 8483.69 | PASS / PASS |
| Terminal T5 (G9 SC marker conflict, Class C) | CLI serial 5.2–5.5 s; headless step 1 (fuel decision prompt) 4.4 s | | | | ≤ 15 ✔ | 77.713 | 9802.96 | terminal (VALID-INFEASIBLE) |

### P3 — T3 outlier
| Condition | Runs (s, end-to-end) | Job wall (s) | Fingerprint |
|---|---|---|---|
| No other browser (headless client) | 24.61 / 24.34 / 22.90 | 16.6 / 17.1 / 15.1 | `454c6190…` (JS) = `ad8f9b2e…` (CLI) every run |
| Normal browser (UI) | 23.17 / 24.24 / 23.85 (+ warm-batch primes 24.69, 24.65) | – | same CP/HR every run |

- Helpers: 3 in every run (`helpers` field = 3).
- Unique candidates and core evaluations (T3, deterministic): 26 checked, 20 full runs, 6 screened out, 2 valid, 41 core
  simulations.
- CPU contention: on this 4-core host the owner plus 3 helpers use all cores. A browser on the same host adds about 1 s
  of engine time from CPU contention, plus 0.5–0.7 s of UI overhead (poll, result transfer, 48-row render). Headless T3
  end-to-end is 22.9–24.6 s.
- The earlier 30.3 s outlier did not recur. All 12 T3 cold runs in this round (UI, headless, XAMPP-like parity and Linux)
  were 22.7–24.7 s. The difference came
  from removing the 2 s idle rule in the run timer (FINAL is declared as soon as it is proven) and from the prefetches.

## 2. Warm (server cache retained), UI
| Case | Prime (cold) | Same input ×3 | Target ≤ 10 s |
|---|---|---|---|
| Golden | 8.39 | 1.13 / 1.02 / 1.01 | ✔ |
| T0 | 20.19 | 0.51 / 1.02 / 0.56 | ✔ |
| T3 | 24.69 | 0.50 / 0.51 / 0.53 | ✔ |
| T6 | 20.34 | 0.51 / 0.51 / 0.51 | ✔ |

## 3. Warm IE Adjustment (class from the solution structure; IE_RERUN_CLASSIFICATION.md)
| Case | Change | Class | UI runs (s) | Engine (s) | Target |
|---|---|---|---|---|---|
| Golden typed litres | IEW1 | A | 3.23 / 2.95 / 3.07 | 2.58 / 2.45 / 2.63 | engine ≤ 5, UI ≤ 8 ✔ |
| Golden typed litres | IEW2 | A | 2.04 / 1.93 / 1.98 | 1.51 / 1.44 / 1.54 | ✔ |
| Golden typed litres | IEW3 | B (48-row gas window) | 3.15 / 2.83 / 3.09 | 2.54 / 2.24 / 2.31 | ≤ 30 ✔ |
| Golden recommended litres | IEW1/2/3 | B (fuel closing / gas window) | 5.52 / 4.87 / 5.25 | 2.37 / 1.54 / 2.39 (+ fuel analysis) | ≤ 30 ✔ |
| T0 | IEW1 / IEW2 / IEW3 | B / A / B | 10.97 / 2.01 / 4.26 (warm3: 10.62 / 2.02 / 4.07) | 10.37 / 1.51 / 3.65 | ✔ |
| T3 | IEW1 / IEW2 / IEW3 | B / B / B (commitment repair) | 5.01 / 6.10 / 26.57 (warm3: 5.27 / 6.25 / 27.21) | 4.48 / 5.46 / 25.98 | ✔ |
| T3 IEW3 headless | IEW3 | B | 27.10 / 24.73 / 25.31; Linux 25.82 | 19.1–21.6 job wall | ✔ |
| T6 | IEW1 (3 runs) | B | 11.50 / 10.93 / 11.43 (warm3 11.09) | 10.99 / 10.42 / 10.90 | ≤ 30 ✔ |
| T6 | IEW2 / IEW3 | A / B | 2.40 / 4.82 | 1.74 / 4.12 | ✔ |

The golden Class A path does not rerun the fuel analysis (`_prelim_skipped`). Litres, Distillate unit (G6), continuous
block (26–48 / 27–48) and the 30/50/75/100 mix are identical to the two-step flow.

## 4. Changes in this final round (all bit-identical; 17/17 CLI fingerprints and the 38-case regression unchanged)
| Change | Effect |
|---|---|
| IE warm stage A polish = 8 evaluations (count) | IE6 chain CP 79.9444 ≤ wall-clock baseline 79.9449; +0.3–1.1 s IE warm |
| Entry core + R1 sequence-lock variants prefetched by a helper during IE stages A/B | T3 IEW3 (commitment repair): UI 27.3–30.9 → 26.6–27.2 s; headless 26.6–29.2 → 24.7–27.1 s |
| Per-node polish = 12 evaluations (instead of a ~1 s wall-clock window), STG memo boundaries, isolated C1 priority fix | Result no longer depends on owner/helper timing (T3 IEW2: 4/4 identical) |

## 5. Determinism and parity
- UI vs CLI: CP and HR are identical for every fixture. The UI fingerprint (JS hash) is the same in every run.
- XAMPP-like (built-in PHP 7.4 + proxy) vs Linux (Apache 2.4 + PHP-FPM 7.4, `/php74/opr-simulation`), same payload:
  T0, T1, T3, T6, golden (step 1), T5, M1 and M3 all have identical fingerprint, `normalized_payload_hash`, CP, HR and
  status (8/8, `par.txt`). T3 → IEW3 on Linux gives fingerprint `6fca3a84…`, the same as XAMPP-like.

## 6. Candidate / core evaluation counts (CLI, deterministic)
| Case | Checked | Full run | Screened out | Valid | Core simulations |
|---|---|---|---|---|---|
| T0 / T6 | 39 | 28 | 11 | 10 | 20 |
| T1 | 19 | 15 | 4 | 3 | 19 |
| T3 | 26 | 20 | 6 | 2 | 41 |
| Golden | 30 | 18 | 12 | 12 | 1 |
| M1 | 38 | 27 | 11 | 2 | 57 |
| M3 | 48 | 33 | 15 | 5 | 34 |
