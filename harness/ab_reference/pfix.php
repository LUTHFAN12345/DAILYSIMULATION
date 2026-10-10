<?php
/* pfix.php <src> <job_dir> : jalankan ulang pp_v15_fast_priority_fix + merit proof pada output job */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2] . '/input.json'), true); $in = $in['input'] ?? $in;
$d = json_decode(file_get_contents($argv[2] . '/result.json'), true); $o = $d['output'];
pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
$t = microtime(true); $o2 = pp_v15_fast_priority_fix($in, $o, microtime(true) + 30.0); $w = microtime(true) - $t;
$fx = $o2['info']['Fastest Unit Priority Fix'] ?? [];
$mp = pp_v15_merit_proof($in, $o2);
$att = []; foreach ((array)($fx['passes'] ?? []) as $p) foreach ((array)($p['attempts'] ?? []) as $a) $att[] = ($a['single'] ?? ('joint' . $a['moves'])) . ':' . ($a['valid'] ? 'V' : implode('/', (array)$a['violations'])) . ':' . $a['cp'];
echo json_encode(['wall' => round($w, 2), 'cp_before' => $o['info']['Cost Production (USD/MWh)'] ?? null, 'cp_after' => $o2['info']['Cost Production (USD/MWh)'] ?? null,
  'attempts' => $att, 'merit' => $mp['status'] ?? null, 'C1' => [$mp['C1']['findings'] ?? null, $mp['C1']['fail'] ?? null],
  'C1fail' => array_values(array_filter((array)($mp['C1']['detail'] ?? []), function ($x) { return ($x['result'] ?? '') === 'FAIL'; })),
  'C2' => $mp['C2']['status'] ?? null, 'C3' => $mp['C3']['status'] ?? null, 'C4' => $mp['C4']['status'] ?? null, 'STG' => $mp['stg']['status'] ?? null], JSON_PRETTY_PRINT) . "\n";
