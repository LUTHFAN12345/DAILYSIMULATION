<?php
/* audit_plan.php <src_dir> <payload.json> <output.json> [redist]: validator source terbaru (hard, merit C1-C4) diterapkan pada rencana mana pun
 * (mis. rencana OLD_GOOD_FAST) dengan payload yang sama; redist=1 juga menjalankan redistribusi C4 (bukti counterfactual) pada rencana itu. */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true);
$o = json_decode(file_get_contents($argv[3]), true); if (isset($o['output']) && is_array($o['output'])) $o = $o['output'];
$orig = pp_normalize_copy($in); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
$V = pp_validate_hard_constraints($orig, $o); $vt = []; foreach ((array)($V['violations'] ?? []) as $v) { $k = is_array($v) ? (string)($v[0] ?? '?') : '?'; $vt[$k] = ($vt[$k] ?? 0) + 1; }
$ma = pp_v12_merit_audit($orig, $o); $i = (array)($o['info'] ?? []); $D = (array)($o['data'] ?? []);
$dist = []; foreach ($D as $r) foreach ($r as $k => $v) if (stripos((string)$k, 'distillate') !== false && is_numeric($v) && abs((float)$v) > 1e-9) $dist[$k] = round(($dist[$k] ?? 0) + (float)$v, 3);
$res = ['rows' => count($D), 'hard' => $V['status'] ?? null, 'hard_violations' => $vt, 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null,
  'gas_used' => $i['Total Gas Used (BBTUD)'] ?? null, 'gas_quota' => $i['Total Gas Quota (BBTUD)'] ?? null, 'pgn_min' => $D ? min(array_map(function ($r) { return (float)($r['Flow_PGN_RT'] ?? 0); }, $D)) : null,
  'merit' => $ma['status'] ?? null, 'c1_findings' => $ma['c1_merit_headroom']['findings'] ?? null, 'c2_fail' => $ma['c2_start_with_headroom']['fail'] ?? null, 'c3_fail' => $ma['c3_first_legal_stop']['fail'] ?? null,
  'c4' => array_diff_key((array)($ma['c4_cross_group_priority'] ?? []), ['detail' => 1, 'proof_detail' => 1]), 'c4_detail_head' => array_slice((array)($ma['c4_cross_group_priority']['detail'] ?? []), 0, 4),
  'sig' => count($D) === 48 ? substr(pp_v6_gtg_sig($D), 0, 12) : null, 'distillate_columns' => $dist];
if (!empty($argv[4]) && count($D) === 48) { pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
  $t = microtime(true); $r = pp_v12_c4_redistribute($orig, $o, microtime(true) + 600); $rep = (array)($r['report'] ?? []);
  $res['c4_redistribution'] = ['s' => round(microtime(true) - $t, 1), 'applied' => $r['applied'] ?? null, 'fail_before' => $rep['c4_fail_before'] ?? null, 'fail_after' => $rep['c4_fail_after'] ?? null,
    'merit_after' => $rep['merit_status_after'] ?? null, 'cp_before' => $rep['cost_production_before'] ?? null, 'cp_after' => $rep['cost_production_after'] ?? null, 'hr_after' => $rep['heat_rate_after'] ?? null,
    'moved_mw' => array_sum(array_map(function ($R) { return (float)($R['moved_mw'] ?? 0); }, (array)($rep['rounds'] ?? [])))]; }
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
