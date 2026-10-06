<?php
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$x = json_decode(file_get_contents($argv[2]), true); $in = $x['input']; $W = $x['reviewed'];
$orig = pp_normalize_copy($in); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
$ma0 = pp_v12_merit_audit($orig, $W); echo "before fail ", $ma0['c4_cross_group_priority']['fail'], "\n";
$t = microtime(true); $r = pp_v12_c4_redistribute($orig, $W, microtime(true) + 300); echo "redistribute ", round(microtime(true) - $t, 2), " s applied=", json_encode($r['applied'] ?? null), "\n";
/* ulang rowwise untuk mendapat output & audit FAIL rinci */
$rep = $r['report']; $mv = null; foreach ($rep['rounds'] as $R) if (!empty($R['moves'])) { $mv = $R['moves']; break; }
$T = pp_tl_supplier_target($W);
$fb = pp_v12_c4_rowwise($orig, (array)$W['data'], $mv, $T, microtime(true) + 300, null, $ma0['c4_cross_group_priority']['detail'] ?? []);
$new = $fb['a']['output']; foreach ((array)($W['info'] ?? []) as $k => $v) if (!array_key_exists($k, (array)$new['info'])) $new['info'][$k] = $v;
$new['info']['V12 C4 Counterfactual Proof'] = $fb['proof']; $maF = pp_v12_merit_audit($orig, $new); $cc = $maF['c4_cross_group_priority'];
echo json_encode(['fail' => $cc['fail'], 'cf' => $cc['counterfactual_infeasible'], 'detail' => $cc['detail'], 'proof_keys' => array_keys($fb['proof']['proofs'])]), "\n";
/* baris sebelum vs sesudah untuk row FAIL */
foreach ($cc['detail'] as $d) { $r0 = $d['row'] - 1; echo "row ", $d['row'], " before ", json_encode(array_intersect_key($W['data'][$r0], array_flip(['G2','G3','G5','G8','G9','Export_PLN']))), " after ", json_encode(array_intersect_key($new['data'][$r0], array_flip(['G2','G3','G5','G8','G9','Export_PLN']))), "\n"; }
foreach ($fb['report']['rows'] as $x) if (($x['source'] ?? '') === 'TEMUAN_SISA_DISPATCH_AKHIR') echo $x['row'], ' ', $x['outcome'], ' ', $x['reason'] ?? '', ' export ', json_encode($x['export_impact']), "\n";
echo 'search_s ', $fb['report']['search_s'], ' proof_s ', $fb['report']['proof_s'], ' evals ', $fb['report']['evaluations'], ' phase ', $fb['report']['phase'], "\n";
