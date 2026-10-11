<?php
/* seedprobe.php <src> <base_dump.json> <payload.json> : pipeline penuh dengan commitment basis di-seed (tanpa auto-start) */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); require $argv[1] . '/run.php';
$B = json_decode(file_get_contents($argv[2]), true)['output']['data']; $in = json_decode(file_get_contents($argv[3]), true);
$c = pp_v13f_clean($in); if (getenv("NOSEED") !== "1") { $c["data3"]["modeling"]["unit_stop_time"] = array_merge((array)($c["data3"]["modeling"]["unit_stop_time"] ?? []), pp_v3_stops($B)); $c["data3"]["modeling"]["__tl_no_auto_start"] = true; }
$t = microtime(true); $o = pp_v13f_pipeline($c); $w = microtime(true) - $t;
$orig = pp_v13f_clean($in); $a = pp_tl_assess($orig, $o); $H = pp_v5_headroom_priority_audit($orig, $o, true);
$un = 0; foreach ((array)$H['flags'] as $f) if (($f['type'] ?? '') === 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' && empty($f['resolved'])) $un++;
echo json_encode(['wall' => round($w, 2), 'valid' => $a['valid'], 'fail' => array_keys(array_filter($a['checks'], fn($x) => $x === false)), 'cp' => $o['info']['Cost Production (USD/MWh)'] ?? null, 'loaded_unresolved' => $un, 'phase' => array_map(fn($p) => $p['phase'] . '@' . $p['at_s'], (array)($o['info']['Run Status']['phase_timeline'] ?? []))]) . "\n";
