<?php
/* fastjob.php <src> <payload.json> : pp_job_run_economic_review di CLI (job palsu) — ringkas hasil */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $t = microtime(true);
$o = pp_job_run_economic_review('economic_review-cli' . getmypid(), $in); $o = $o['output'] ?? $o; $i = $o['info'] ?? [];
$V = pp_validate_hard_constraints(pp_normalize_copy($in), $o); $vt = []; foreach ((array)($V['violations'] ?? []) as $v) $vt[] = is_array($v) ? $v[0] . ':' . substr((string)($v[1] ?? ''), 0, 160) : '?';
echo json_encode(['s' => round(microtime(true) - $t, 2), 'status' => $o['status'] ?? null, 'hard' => $V['status'] ?? null, 'viol' => array_slice($vt, 0, 6), 'cp' => $i['Cost Production (USD/MWh)'] ?? null,
  'rs' => $i['Run Status']['mode'] ?? null, 'conv' => $i['Run Status']['converged'] ?? null, 'trace' => $i['Fastest Trace']['steps'] ?? null, 'v7' => $GLOBALS['ppV7FuelRerun'] ?? null,
  'gate' => $o['release_gate']['status'] ?? null, 'blk' => $o['release_gate']['blocking_reasons'] ?? null], JSON_PRETTY_PRINT) . "\n";
