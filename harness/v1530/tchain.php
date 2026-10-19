<?php
// tchain.php <app> <dumpN.json> : tighten (dump mode) -> 2x2 -> priority fix, print econ metrics per step
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$d = json_decode(file_get_contents($argv[2]), true);
pp_tl_clean_globals(); if (!empty($d['inv'])) $GLOBALS['ppV1527InvSearch'] = true;
$in = $d['input']; $o = pp_v1523_tighten($in, $d['out']); unset($GLOBALS['ppV1527InvSearch']); pp_tl_clean_globals();
$orD = pp_v1523_orig($in); $K = pp_v1523_classes($orD); $m = fn($x) => (function ($q) { return ['econ' => $q['econ_cp'], 'cp' => $q['cp'], 'inv' => $q['inv'], 'bal' => $q['bal3x1'], 'hr' => $q['hr']]; })(pp_v1523_metrics($orD, $x, $K));
echo 'tight ', json_encode($m($o)), "\n";
$b = pp_v1525_balance_2x2($in, $o); pp_tl_clean_globals();
echo 'bal   ', json_encode($m($b)), ' active=', json_encode(!empty($GLOBALS['ppV1525BalActive'])), ' ', json_encode(array_map(fn($x) => ($x['reason_code'] ?? '') . ':' . ($x['decision'] ?? ''), (array)($b['info']['Block Balance 2x2']['decisions'] ?? []))), "\n";
$f = pp_v15_fast_priority_fix($in, $b, microtime(true) + 600.0); pp_tl_clean_globals();
$a = pp_sp_assess($orD, $f);
echo 'fix   ', json_encode($m($f)), ' valid=', json_encode(!empty($a['valid'])), "\n";
file_put_contents($argv[2] . '.' . basename(dirname($argv[1] . '/x')) . '.chain', json_encode(['t' => $o, 'b' => $b, 'f' => $f]));
