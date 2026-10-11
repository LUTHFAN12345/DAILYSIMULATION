<?php
/* normcmp.php <src> <a.json> <b.json> : normalisasi kanonik engine (pp_normalize_copy + pp_v1518_semantics) atas dua payload,
 * lalu bandingkan field keputusan (abaikan metadata request `_*`, budget waktu, dan provenance auto_semantics). */
define('PP_LIB_ONLY', 1); require $argv[1] . '/run.php';
$n = function ($f) { $x = json_decode(file_get_contents($f), true); $c = pp_normalize_copy($x); pp_v1518_semantics($c);
    foreach (array_keys($c) as $k) if (is_string($k) && $k[0] === '_') unset($c[$k]);
    foreach (['time_budget_max_seconds', 'time_budget_step_seconds', 'time_budget_seconds', '__core_run_budget', 'change_over_search_budget_seconds', 'change_over_interactive_request', 'auto_semantics'] as $k) unset($c['data3']['modeling'][$k]);
    foreach (['unit_fix_load', 'unit_skip_load'] as $k) if (empty($c['data3']['modeling'][$k])) $c['data3']['modeling'][$k] = [];
    $m = &$c['data3']['modeling']; if (isset($m['required_units'])) { $m['required_units'] = array_values(array_unique(array_map('strtolower', $m['required_units']))); sort($m['required_units']); }
    return $c; };
$A = $n($argv[2]); $B = $n($argv[3]); $d = 0;
$walk = function ($x, $y, $p) use (&$walk, &$d) { if (is_array($x) && is_array($y)) { foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $k) $walk($x[$k] ?? '<absent>', $y[$k] ?? '<absent>', "$p.$k"); return; }
    if ($x !== $y && !(is_numeric($x) && is_numeric($y) && (float)$x == (float)$y)) { $d++; if ($d <= 25) echo "DIFF $p: " . substr(json_encode($x), 0, 120) . ' -> ' . substr(json_encode($y), 0, 120) . "\n"; } };
$walk($A, $B, ''); echo "diffs=$d\n";
foreach (['commitment_semantics', 'stop_policy', 'required_units', 'unit_cannot_stop', 'sc_cc'] as $k) echo $k, ' A=', md5(json_encode($A['data3']['modeling'][$k] ?? null)), ' B=', md5(json_encode($B['data3']['modeling'][$k] ?? null)), "\n";
echo 'auto_semantics A: ', json_encode(array_map(fn($a) => ($a['unit'] ?? '') . ':' . ($a['reason'] ?? ''), (array)(json_decode(file_get_contents($argv[2]), true)['data3']['modeling']['auto_semantics'] ?? []))), "\n";
