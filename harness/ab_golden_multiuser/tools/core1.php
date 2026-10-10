<?php
/* core1.php <src> <input> <action|none> [amount] : pp_run_simulation_core sekali; ringkas commitment + CP */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$raw = json_decode(file_get_contents($argv[2]), true); foreach (['__fastest_local_only','__no_exact_family'] as $k) unset($raw['data3']['modeling'][$k]);
$m = &$raw['data3']['modeling'];
if ($argv[3] !== 'none') { $m['gas_shortage_action'] = $argv[3]; $m['additional_lng'] = 0; unset($m['distillate_user_limit_litres']);
  if ($argv[3] !== 'use_distillate') $m['additional_lng'] = (float)$argv[4]; else $m['distillate_user_limit_litres'] = (float)$argv[4]; }
foreach (array_filter(explode(",", (string)getenv("STOPU"))) as $u) $m["unit_stop_time"][] = ["unit" => $u, "start" => 1, "stop" => 48];
unset($m);
$in = pp_normalize_copy($raw); pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
$t = microtime(true); $o = pp_run_simulation_core($in); $s = microtime(true) - $t;
function summ($o) { $D = $o['data']; $st = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) { $on = []; foreach ($D as $k => $r) if ((float)($r[$u] ?? 0) > 0.01) $on[] = $k + 1; if ($on) $st[$u] = $on[0] . '-' . end($on) . '(' . count($on) . ')'; }
  $i = $o['info']; return ['cp' => $i['Cost Production (USD/MWh)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null, 'dist' => $i['Distillate Used (l)'] ?? null, 'exp' => $i['Daily PLN Exp (MWh)'] ?? null, 'starts' => $st]; }
echo json_encode(['s' => round($s, 2)] + summ($o)) . "\n";
$r = $o['data']; for ($k = 0; $k < 16; $k++) { echo $k + 1, ' '; foreach (['G2','G5','G6','G8','G9','S2','S3','Export_PLN'] as $u) echo $u, '=', round((float)($r[$k][$u] ?? 0), 2), ' '; echo "\n"; }
file_put_contents('/tmp/claude-0/abw/core1_' . $argv[3] . '.json', json_encode($o));
