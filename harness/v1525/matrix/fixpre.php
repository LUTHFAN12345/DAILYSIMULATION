<?php // fixpre.php <app> <input.json> <unit> <row> <value> : pp_sp_fix_precheck (jalur Ctrl+Click / Fix Load UI)
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); echo json_encode(pp_sp_fix_precheck($in, $argv[3], (int)$argv[4], (float)$argv[5]));
