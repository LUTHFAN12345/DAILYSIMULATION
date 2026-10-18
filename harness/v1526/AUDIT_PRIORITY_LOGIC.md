# AUDIT LOGIC PRIORITY PER ROW (V15.25 source, commit debf01f) — sebelum patch V15.26

STATUS LOGIC EXISTING: **ADA PARSIAL**

## SOURCE (file + fungsi + caller)
| tahap | implementasi | caller | cakupan |
|---|---|---|---|
| alokasi awal per row | `worker02.php` closure `$run_blocks` (±L5199) -> `worker_functions.php::pp_solve_block` (L1891) | bisection target Export per row (worker02 L5260-5272) | 48 row, setiap core run. Antarblok: urutan Block Priority (committedAbove/reservedBelow). Dalam blok: unit Running(kemarin)/Required dulu lalu Unit Priority; beban dibagi SAMA rata (common load), bukan "prioritas tertinggi dimaksimalkan dulu". "Running" = Last Data Status kemarin, bukan state row sebelumnya. |
| shaper final (repair ramp/gas/export/STG hold) | `worker_functions.php::pp_shape_final_dispatch` (L9620) | setiap core run | 48 row; lintasan naik/turun beban memakai URUTAN UNIT HARD-CODE (mis. L12397 `['g5','g1','g2','g3']`, L13351/L13471 `['g9','g8','g5','g1','g2','g4','g3','g6']`, L13197 `['g9','g8','g5','g2','g1']`, L14278, L14325), bukan Effective Unit Priority input. |
| audit headroom/prioritas | `run.php::pp_v5_headroom_priority_audit` (L6958) | gate (L10019), Unit Priority Fix (L4763), V8 (L9071/9228/9423) | 48 row: flag LOADED / START / RUNNING while higher headroom |
| perbaikan prioritas (pindah MW rendah -> tinggi) | `run.php::pp_v15_fast_priority_fix` (L4704, lintasan L4760, maks 8) | bundle Fastest (L3182), jalur job/warm/Maximum Review (L5056), closure bukti event (L8630) | 48 row, pasca-dispatch, divalidasi `pp_v3_frozen_eval` 48 row |
| latest legal start | Required+Simulation: `worker_functions.php::pp_v1518_latest_legal_starts` (L14666, caller run.php L3400, hanya bundle Fastest); start otomatis: `pp_v1523_tighten` DELAY_START galloping (run.php L8807) + `pp_v1524_event_proof` uji start+1 row (L8648) + closure | bundle & job path | per event, redispatch jendela lokal 48 row tervalidasi |
| earliest legal stop | `pp_v1523_tighten` EARLY_STOP galloping + event proof uji stop-1 row + C4 counterfactual | bundle & job path | per event |
| comparator | `run.php::pp_v1523_cmp` (bal3x1 [V15.25] -> inv -> startups_nm -> new_blocks -> CP ekonomi band 0,02 % -> HR ...) ; metrik `pp_v1523_metrics` (L8412) | tightening, stage guard, 2+2, Maximum Review | — |

## JALUR YANG MELEWATI / MELEMAHKAN LOGIC
1. **Audit menganggap `EKONOMIS` sebagai alasan sah** (run.php L7080: `EKONOMIS: memindah 1 MW w->v menambah biaya` -> `resolved = true`). Unit Priority Fix hanya memproses flag `resolved=false` (L4765) -> MW pada unit prioritas rendah TIDAK pernah dipindah ke unit Running prioritas tinggi yang masih punya headroom legal bila pemindahan menaikkan biaya marginal. Bukti (matrix V15.25 final): A0 40, A3 44, T154 46, T034 33, T221 22, A6 21, T214 7 temuan "lower loaded while higher headroom" hanya dibenarkan `EKONOMIS` — tanpa constraint, nilai aktual, limit, margin.
2. Metrik `inv` comparator menghitung unit-row tak-kontinu yang seluruh bebannya dapat diserap headroom unit level lebih tinggi TANPA memperhitungkan runtime minimum, sequence startup, Hold-To-End/Required, fixed load -> false positive; dipakai SEBELUM CP ekonomi (sumber T228: MR CP 95,42 menang atas Fastest 93,56 karena inv 17 vs 21).
3. Jalur warm IE (`__pp_ie_warm_done`): Commitment Tightening & 2+2 dilewati (run.php L5053); V8 dibatasi kandidat. Unit Priority Fix + bukti event final tetap jalan.
4. Change Over: Tightening SKIPPED (L8811). Sertifikat terminal / DEADLINE / fallback basis v7: tidak ada review (dispatch basis dipertahankan).
5. Seluruh tahap review hanya bila `Run Status.economic_review_completed === true` (L5026).
6. Shaper hard-code unit order (lihat tabel) menghasilkan inversi antara yang harus ditambal pasca-dispatch.

## CAKUPAN ROW
Alokasi awal + shaper + audit + Unit Priority Fix: seluruh 48 row pada setiap core run / dispatch final. Start/stop: per event (bukan per row).

## ROOT CAUSE OUTPUT TIDAK KONSISTEN
(a) pengecualian `EKONOMIS` menghentikan penegakan "Running prioritas tinggi dimaksimalkan dulu"; (b) metrik inversi comparator false-positive mendahului CP; (c) urutan hard-code shaper; (d) jalur warm/Change Over tanpa tightening.

## TINDAKAN: **perbaiki existing** (tanpa logic kedua)
- `pp_v5_headroom_priority_audit`: `EKONOMIS` tetap dicatat sebagai informasi tetapi TIDAK lagi me-resolve temuan; pengecualian hanya constraint numerik (ramp tetangga, bus flow, fixed/skip, kopling STG/Export, gas window, counterfactual 48 row) -> Unit Priority Fix (shared, sudah dipanggil bundle/job/warm/MR/closure) memindahkan beban.
- `pp_v1523_metrics.inv`: hanya inversi tanpa pengecualian legal numerik (runtime minimum sejak start, langkah sequence startup, Required/Continuous/Hold-To-End, fixed load) -> comparator tidak lagi menilai false positive.
- Invariant gate RUNNING_HIGH_PRIORITY_FIRST / NO_IDLE_MINLOAD / NO_PRIORITY_INVERSION_WITHOUT_PROOF dari audit yang sama (temuan tanpa bukti constraint = blokir publish); DELAY_LOWER_PRIORITY_START / EARLY_STOP_LOWER_PRIORITY dari bukti event final.
- Audit 48 row ringkas per skenario (harness) sebagai evidence.
