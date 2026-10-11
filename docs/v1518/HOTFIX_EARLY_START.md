# HOTFIX EARLY START: G6/G9 started too early (01:00)

**Root cause.** In `worker_functions.php` `pp_v1518_semantics` (Stop Status `hold_to_end`, lines 2172–2173), the unit was
put into `unit_cannot_stop`. The Cannot-Stop passes then forced it online on every row. For a unit whose Last Data is
Stop, that means a startup at the first legal row (row 2, 01:00): G6 5/15/20, G9 40/50…, and through S1, G6 as well.
The `REQUIRED_MODE_BASED_ON_SIM` / `EXEMPT_REQUIRED` audits let it pass.

**New behaviour** (`pp_v13f_pipeline` + `pp_v1518_latest_legal_starts`), for Required + Start Based On Simulation +
Hold to End + Last Data Stop:
1. Put the running fleet at its effective max, in Effective Priority order.
2. Find the first row where Export stays below Range Min (first failed row).
3. Subtract the startup sequence length to get the latest legal start.
4. Hold the unit off until that row with an engine-derived hold window, then run one full validation against the
   original input.
5. If the result is invalid, try 05:00, 04:00, 01:30 in turn, and finally the old behaviour.

Hold to End applies after startup. The proof is in `info['Startup Timing Proof']`.

Decommit screening: when the baseline violates only `gas_quota` on the UNDER side, a single full-day window is used,
because stopping a unit cannot raise gas.

| Snapshot monitoring-1 (Fastest) | G9 start | G6 start | CP | HR | Gate / C1–C4 / STG / BS |
|---|---|---|---|---|---|
| Old | 01:00 | 01:00 | 71.8171 | 8433.70 | PASS |
| H0 (latest legal) | **03:30** (first failed row if delayed: 06:30) | **05:00** (first failed row if delayed: 08:30) | **71.1216** | **8322.87** | PASS / 0 / PASS / PASS |
| H1 (+1 row: 04:00 / 05:30) | 04:00 | 05:30 | 71.3216 | 8352.59 | PASS (more expensive) |
| G9 + G6 05:00 | 05:00 | 05:00 | 71.8503 | 8438.17 | PASS (G5 starts first: priority inversion) |
| H2 golden | – | – | 79.9049 | 8239.87 | PASS, fingerprint `ee03d6e8…` unchanged |

Runtime with UI helpers: job 24.2–25.7 s, end-to-end 28.7–30.7 s. Opt-out: `PP_V1518_LATEST_START=0`,
`PP_V1518_DEC_UNDER_FULLDAY=0`.
