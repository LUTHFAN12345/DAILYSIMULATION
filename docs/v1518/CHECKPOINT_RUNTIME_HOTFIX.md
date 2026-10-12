# CHECKPOINT — runtime hotfix (plan-1 snapshot)
Branch `claude/gifted-ritchie-q0hfdp`, based on `6037e26`.

Root cause:
1. `worker02.php` `pp_tl_clean_globals` also deleted `__pp_async_worker` and `__pp_async_worker_ceiling`. After the
   Fastest pipeline cleaned its globals, `pp_budget_ceiling()` fell back to 60 s. On a slow machine the latest-start
   candidate was cut at 60 s (`not_truncated`), and `run.php` `pp_v13f_pipeline` then ran a second full pipeline
   (fallback). Result: 145 s, 38 candidates, CP 71.4265 (winner decided by CPU speed).
2. The PGN supplier search ran up to the 32-attempt cap while the physical response alternated between two states
   (22.8681 ↔ 22.9067); one decommit candidate (G4) took 15 s.

Fix:
- Keep the job ceiling in `pp_tl_clean_globals`.
- Latest-start candidates run with a 300 s watchdog; a candidate that fails only `not_truncated` triggers no fallback.
- Supplier search stops on a two-state physical cycle (A,B,A,B,A,B) and returns `bestOut`, the same output as the full
  search.

Opt-out: `PP_V1518_SUP_CYCLE=0`, `PP_V1518_LATEST_WATCHDOG_S`.
