<?php
// w1.php <app> <input.json> : satu panggilan worker mentah (diagnostik)
$app = $argv[1]; define('PP_LIB_ONLY', 1); chdir($app); require $app . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $t = microtime(true);
$o = pp_run_simulation_once_raw($in); $i = $o['info'] ?? [];
echo json_encode(['s' => round(microtime(true) - $t, 2), 'daily' => $i['Daily PLN Exp (MWh)'] ?? null, 'gas' => $i['Total Gas Used (BBTUD)'] ?? null, 'dist' => $i['Required Distillate (l/day)'] ?? null, 'cp' => $i['Cost Production (USD/MWh)'] ?? null,
  'topup' => ($i['Daily Export Plan']['final_topup'] ?? null), 'exp' => array_map(fn($w) => round((float)$w['Export_PLN'], 1), $o['data'] ?? [])]), "\n";
