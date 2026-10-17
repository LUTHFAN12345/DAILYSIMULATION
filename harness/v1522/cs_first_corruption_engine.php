<?php
/* cs_first_corruption_engine.php <src> <payload.json> <unit> [run] : tahap worker [7] input worker, [8] normalisasi pp_v1518_semantics,
 * [9..13] candidate/UPF/commitment/stop/final dispatch (dari job penuh bila argumen 'run'): nilai aktual per tahap. */
define('PP_LIB_ONLY', 1); $GLOBALS['__pp_async_worker'] = true; ini_set('memory_limit', '3G');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $u = strtolower($argv[3]); $U = strtoupper($u);
$pick = function (array $m) use ($u, $U) { return ['uld' => $m['unit_last_data_status'][$U] ?? null, 'required_mode' => $m['required_mode'][$u] ?? null, 'stop_mode' => ((array)($m['stop_mode'] ?? []))[$u] ?? null,
    'in_unit_cannot_stop' => in_array($u, array_map('strtolower', (array)($m['unit_cannot_stop'] ?? [])), true), 'in_required_units' => in_array($u, array_map('strtolower', (array)($m['required_units'] ?? [])), true),
    'commitment_semantics' => $m['commitment_semantics'][$u] ?? null, 'stop_policy' => $m['stop_policy'][$u] ?? null,
    'hold_only' => function_exists('pp_v1522_hold_only') ? pp_v1522_hold_only($m, $u) : null]; };
$st = ['s7_worker_input' => $pick((array)$in['data3']['modeling'])];
$c = pp_normalize_copy($in); $st['s8a_after_pp_normalize_copy'] = $pick((array)$c['data3']['modeling']);
pp_v1518_semantics($c); $st['s8b_after_pp_v1518_semantics'] = $pick((array)$c['data3']['modeling']);
if (($argv[4] ?? '') === 'run') {
    $t = microtime(true); $o = pp_job_run_economic_review('economic_review-fc' . getmypid(), $in); $o = $o['output'] ?? $o; $i = (array)($o['info'] ?? []);
    $col = in_array($u, ['b1', 'b2'], true) ? 'BB' . substr($u, 1) : $U; $on = []; foreach ((array)($o['data'] ?? []) as $k => $r) if ((float)($r[$col] ?? 0) > 0.01) $on[] = $k + 1;
    $tr = (array)(($i['Commitment Semantics Trace']['units'] ?? [])[$U] ?? []);
    $st['s9_startup_candidates'] = array_map(fn($x) => ['start_rows' => $x['start_rows'] ?? null, 'valid' => $x['valid'] ?? null, 'cp' => $x['cp'] ?? null, 'failed' => $x['failed_checks'] ?? null], (array)(($i['Startup Timing Proof'] ?? [])['candidates_tried'] ?? []));
    $st['s9b_startup_proof_unit'] = (($i['Startup Timing Proof'] ?? [])['units'] ?? [])[$U] ?? null;
    $st['s10_unit_priority_review'] = ['status' => ($i['V8 Priority Review'] ?? [])['status'] ?? null, 'applied' => ($i['V8 Priority Review'] ?? [])['applied'] ?? null];
    $st['s11_s12_commitment_stop_enforcement'] = ['state_path' => $tr['state_path'] ?? null, 'selected_start' => $tr['selected_start'] ?? null, 'selected_start_reason' => $tr['selected_start_reason'] ?? null,
        'selected_stop' => $tr['selected_stop'] ?? null, 'selected_stop_reason' => $tr['selected_stop_reason'] ?? null, 'flags' => $tr['flags'] ?? null];
    $st['s13_final_dispatch'] = ['first_loaded_row' => $on[0] ?? null, 'last_loaded_row' => $on ? end($on) : null, 'rows_loaded' => count($on), 'intervals' => $tr['operating_intervals'] ?? null,
        'run_status' => ($i['Run Status'] ?? [])['status'] ?? null, 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'trace_status' => ($i['Commitment Semantics Trace'] ?? [])['status'] ?? null,
        'terminal_certificate' => isset($i['Fastest Terminal Certificate']) ? ($i['Fastest Terminal Certificate']['code'] ?? null) : null, 'wall_s' => round(microtime(true) - $t, 2)];
}
echo json_encode($st, JSON_UNESCAPED_SLASHES), "\n";
