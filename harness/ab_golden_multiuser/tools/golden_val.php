<?php
/* golden_val.php <src> <input> <tables.json> <out> : dispatch GTG Excel dibekukan (fixed load) pada state use_distillate (plafon dari Excel),
 * engine + validator source terbaru menghitung ulang seluruh akun; laporkan hard, gas, CP, HR, distillate. */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$raw = json_decode(file_get_contents($argv[2]), true); foreach (['__fastest_local_only','__no_exact_family'] as $k) unset($raw['data3']['modeling'][$k]);
$T = json_decode(file_get_contents($argv[3]), true); $h = $T[1][0]; $rows = array_slice($T[1], 1);
$m = &$raw['data3']['modeling']; $m['gas_shortage_action'] = 'use_distillate'; $m['additional_lng'] = 0; $m['distillate_user_limit_litres'] = (float)(getenv('DLIM') ?: 149954.6); unset($m);
$bd = []; foreach ($rows as $r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) $x[$u] = (float)$r[array_search($u . ' (MW)', $h)]; $bd[] = $x; }
$orig = pp_normalize_copy($raw); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0) unset($orig['data3']['modeling'][$mk]);
pp_tl_clean_globals(); pp_budget_start(600.0, true, true);
$fz = pp_v3_frozen_eval($orig, $bd, microtime(true) + 60.0); $o = $fz['output']; $i = $o['info'];
$V = pp_validate_hard_constraints($orig, $o); $vt = []; foreach ((array)($V['violations'] ?? []) as $v) $vt[] = is_array($v) ? $v[0] . ':' . substr((string)($v[1] ?? ''), 0, 140) : '?';
$dev = 0.0; foreach ($o['data'] as $k => $r) foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) $dev = max($dev, abs((float)$r[$u] - $bd[$k][$u]));
$xb = []; foreach ($rows as $k => $r) { $xb[] = (float)$r[array_search('BBLN2 (MW)', $h)]; }
file_put_contents($argv[4], json_encode($o));
echo json_encode(['valid' => $fz['valid'] ?? null, 'assess_viol' => $fz['violations'] ?? null, 'checks_failed' => array_keys(array_filter((array)($fz['checks'] ?? []), function ($x) { return !$x; })),
  'hard' => $V['status'] ?? null, 'viol' => array_slice($vt, 0, 8), 'gtg_dev_mw' => round($dev, 4), 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null,
  'gas' => $i['Total Gas Used (BBTUD)'] ?? null, 'q' => $i['Total Gas Quota (BBTUD)'] ?? null, 'resid' => $i['Residual Gas Shortage (BBTUD)'] ?? null, 'dist_l' => $i['Distillate Used (l)'] ?? null, 'dist_units' => $i['Distillate per unit (l)'] ?? null,
  'pln' => $i['PLN Export Compliance'] ?? null]) . "\n";
