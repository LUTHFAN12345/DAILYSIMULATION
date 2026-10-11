<?php
/* pipeval.php <src> <payload> : pp_v13f_pipeline lalu validasi (tanpa V8/fix) */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $c = pp_v13f_clean($in);
$t = microtime(true); $o = pp_v13f_pipeline($c); $V = pp_validate_hard_constraints($c, $o);
$on = []; foreach (['G3','G4','G5','G6'] as $U) { $rr = []; foreach ($o['data'] as $i => $r) if ((float)($r[$U] ?? 0) > 0.01) $rr[] = $i + 1; if ($rr) $on[$U] = implode(',', array_map(fn($a) => $a, [$rr[0], $rr[count($rr)-1], count($rr)])); }
echo json_encode(['wall' => round(microtime(true) - $t, 2), 'hard' => $V['status'], 'viol' => array_slice($V['violations'], 0, 4), 'on' => $on, 'warn' => array_values(array_filter((array)($o['info']['Warnings'] ?? []), fn($w) => stripos($w, 'G4') !== false || stripos($w, 'downtime') !== false))]) . "\n";
