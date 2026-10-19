<?php
// sim1.php <app> <input.json> <n> : n core simulations of the cleaned engine input (profiling target)
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $c = pp_v13f_clean(pp_v1517_engine_input($in));
$n = (int)($argv[3] ?? 1); $t = microtime(true);
for ($i = 0; $i < $n; $i++) { pp_tl_clean_globals(); $GLOBALS['__ppx_once_depth'] = 1; $o = pp_run_simulation_once($c); unset($GLOBALS['__ppx_once_depth']); }
printf("n=%d s/sim=%.3f CP=%s\n", $n, (microtime(true) - $t) / $n, $o['info']['Cost Production (USD/MWh)'] ?? '?');
