<?php
/* landprobe.php <src> <base_dump.json> <payload.json> : pp_tl_land_commitment dengan commitment basis -> waktu, valid, unresolved */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); require $argv[1] . '/run.php';
$B = json_decode(file_get_contents($argv[2]), true)['output']['data']; $in = json_decode(file_get_contents($argv[3]), true);
$c = pp_v13f_clean($in); $c['data3']['modeling']['__v9_nopolish'] = (getenv('NOPOL') === '1');
$t = microtime(true); $L = pp_tl_land_commitment($c, [], pp_v3_stops($B), microtime(true) + 20.0, 3); $a = $L['a'] ?? null; $w = microtime(true) - $t;
$orig = pp_v13f_clean($in); $o = $a['output'] ?? null; $un = null;
if (is_array($o)) { $H = pp_v5_headroom_priority_audit($orig, $o, true); $un = 0; foreach ((array)$H['flags'] as $f) if (($f['type'] ?? '') === 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' && empty($f['resolved'])) $un++; }
echo json_encode(['wall' => round($w, 2), 'valid' => $a['valid'] ?? null, 'cp' => $a['key']['cp'] ?? null, 'unresolved' => $un, 'evals' => $L['evals'] ?? null]) . "\n";
