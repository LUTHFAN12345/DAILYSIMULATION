<?php
/* dumpjob.php <src> <payload.json> <out.json> : job Fastest di CLI, simpan output lengkap untuk audit dokumen */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $t = microtime(true);
$o = pp_job_run_economic_review('economic_review-dump' . getmypid(), $in); $o = $o['output'] ?? $o;
$V = pp_validate_hard_constraints(pp_normalize_copy($in), $o);
file_put_contents($argv[3], json_encode(['wall_s' => round(microtime(true) - $t, 2), 'hard' => $V['status'] ?? null, 'viol' => array_slice((array)($V['violations'] ?? []), 0, 12), 'output' => $o], JSON_PRETTY_PRINT));
echo "ok\n";
