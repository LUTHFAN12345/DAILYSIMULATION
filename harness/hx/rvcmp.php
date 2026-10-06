<?php
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$x = json_decode(file_get_contents($argv[2]), true);
pp_tl_clean_globals(); pp_budget_start(900.0, true, true); $GLOBALS['ppV12FastReview'] = true;
$t = microtime(true); $o = pp_v8_priority_review($x['input'], $x['candidate'], microtime(true) + 600.0);
$i = $o['info']; $c4 = $i['V12 C4 Redistribution'] ?? [];
echo json_encode(['src' => $argv[1], 's' => round(microtime(true) - $t, 1), 'sig' => pp_v6_gtg_sig((array)$o['data']), 'cp' => $i['Cost Production (USD/MWh)'] ?? null,
  'c4_before' => $c4['c4_fail_before'] ?? null, 'c4_after' => $c4['c4_fail_after'] ?? null, 'applied' => $c4['applied'] ?? null, 'rv_total' => $i['V8 Priority Review']['candidates_total'] ?? null]) . "\n";
