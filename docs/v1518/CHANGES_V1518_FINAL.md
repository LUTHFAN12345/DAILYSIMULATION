# CHANGES V15.18 FINAL (since 5a60d8a)

## index.php
- `syncBlockRequiredToCommitment`: the primary GTG of a block whose STG is requested is handed to §6 (STG lead follows,
  Stop Status follows the STG). This is the root cause of the T6 parity issue.
- Golden Class A: when the fuel context is unchanged and the litres were typed, the fuel analysis is skipped
  (`FUEL_CTX`, `gsfBuildFuelPayload`). If the server returns `PRELIM_REQUIRED`, the normal two-step flow runs.
- The run timer declares FINAL as soon as the workflow is proven complete (250 ms tick).

## run.php
- `normalized_payload_hash` in Proof Binding; IE classification A/B/C (`IE Rerun Classification`) and the
  `COMMITMENT_REPAIR_REQUIRED` progress stage; `PRELIM_REQUIRED` guard; double autosave parity.
- IE warm stage A polish limited to 8 evaluations (count).
- C1 priority fix runs isolated (state-independent); STG memo reset at the start of polish and of the V8 review;
  helper tasks start and end with an empty STG memo.
- Entry prefetch for IE commitment repair; new helper task type `entry1518`.

## worker02.php
- Isolated core: dist-schedule parity, HIT counted, memo boundaries; speculative gas window correction; stop after
  rejected in-window landings (non-gas).
- Entry chain (`pp_v1518_entry_chain`, `pp_v1518_entry_prefetch`). `pp_v1518_mk_r1` was extracted from the pipeline
  closure without changing its logic.
- `pp_tl_eval`: per-node polish limited to 12 evaluations; STG memo empty at the start and end of each evaluation.

## worker_functions.php
- `pp_v1518_canonical_order` (canonical normalisation of unit sets and maps); `$minOf` uses MIN CC/SC from user input.
- `pp_fuel_sum_g19` unroll and the non-GE `pp_is_unit_stopped` memo (bit-identical).
- STG memo `reset` command (`pp_v1518_stg_memo_reset`).
