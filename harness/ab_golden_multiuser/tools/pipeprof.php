<?php
/* pipeprof.php <src> <input> <action> [amount] : pp_v13f_pipeline + phase timeline */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$raw = json_decode(file_get_contents($argv[2]), true); foreach (['__fastest_local_only','__no_exact_family'] as $k) unset($raw['data3']['modeling'][$k]);
$m = &$raw['data3']['modeling']; $m['gas_shortage_action'] = $argv[3]; $m['additional_lng'] = 0; unset($m['distillate_user_limit_litres']);
if ($argv[3] === 'add_lng') $m['additional_lng'] = (float)$argv[4]; if ($argv[3] === 'use_distillate') $m['distillate_user_limit_litres'] = (float)$argv[4]; unset($m);
$raw['_fast_default'] = true; $t = microtime(true); $o = pp_v13f_pipeline($raw); $w = microtime(true) - $t;
$rs = $o['info']['Run Status'] ?? []; $prev = 0.0;
foreach ((array)($rs['phase_timeline'] ?? []) as $p) { $at = (float)($p['at_s'] ?? 0); printf("%7.2f +%6.2f %s\n", $at, $at - $prev, $p['phase'] ?? ($p['name'] ?? json_encode($p))); $prev = $at; }
printf("wall %.2f cp %s hr %s core_runs %s\n", $w, $o['info']['Cost Production (USD/MWh)'] ?? '-', $o['info']['JBBK MM Heat Rate (BTU/kWh)'] ?? '-', $rs['core_runs'] ?? '-');
