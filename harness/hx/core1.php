<?php
/* core1.php <src> <input.json> : waktu satu pp_run_simulation_core (seperti baseline pipeline) + ringkasan */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '2G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true);
$in = pp_normalize_copy($in);
pp_tl_clean_globals(); pp_budget_start(1800.0, true, true);
$t = microtime(true); $o = pp_run_simulation_core($in); $dt = microtime(true) - $t;
$i = $o['info'] ?? [];
echo json_encode(['core_s' => round($dt, 3), 'rows' => count($o['data'] ?? []), 'gas_used' => $i['Total Gas Used (BBTUD)'] ?? null, 'gas_quota' => $i['Total Gas Quota (BBTUD)'] ?? null,
  'dist' => $i['Distillate Gas Offset (BBTUD)'] ?? null, 'shortage' => $i['Gas Shortage (BBTUD)'] ?? null, 'counters' => array_filter($GLOBALS, function ($k) { return is_string($k) && preg_match('~cnt|count|iter|calls~i', $k); }, ARRAY_FILTER_USE_KEY)]) . "\n";
