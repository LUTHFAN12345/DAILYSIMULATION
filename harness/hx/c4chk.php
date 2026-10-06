<?php
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$x = json_decode(file_get_contents($argv[2]), true); $in = $x['input']; $W = $x['reviewed'];
$orig = pp_normalize_copy($in); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
$t = microtime(true); $r = pp_v12_c4_redistribute($orig, $W, microtime(true) + 300); $rep = $r['report'];
$rw = null; foreach ($rep['rounds'] as $R) if (!empty($R['rowwise'])) $rw = $R['rowwise'];
$oc = []; foreach ((array)($rw['rows'] ?? []) as $x2) $oc[$x2['outcome']] = ($oc[$x2['outcome']] ?? 0) + 1;
echo json_encode(['s' => round(microtime(true) - $t, 1), 'applied' => $r['applied'], 'fail_before' => $rep['c4_fail_before'], 'fail_after' => $rep['c4_fail_after'] ?? null, 'merit' => $rep['merit_status_after'] ?? null,
  'cp' => [$rep['cost_production_before'] ?? null, $rep['cost_production_after'] ?? null], 'outcomes' => $oc, 'residual_legal' => $rw['residual_legal_transfers'] ?? null, 'residual_proofs' => $rw['residual_finding_proofs'] ?? null]) . "\n";
