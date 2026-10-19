<?php
// shdiff.php <app> <a.json> <b.json>: diff of structural-hash canonical forms
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$ld = function ($f) { $d = json_decode(file_get_contents($f), true); return $d['input'] ?? $d; };
$a = $ld($argv[2]); $b = $ld($argv[3]);
$norm = function ($pc) { $mPC = &$pc['data3']['modeling'];
    $actPC = strtolower(trim((string)($mPC['gas_shortage_action'] ?? 'recommendation')));
    if (in_array($actPC, ['add_lng', 'use_distillate'], true) || !in_array($actPC, ['none', 'recommendation', 'force_same_as_quota'], true)) $mPC['gas_shortage_action'] = 'recommendation';
    foreach (['validated_options', 'validated_shortage_options', '_validated_option'] as $k) unset($mPC[$k]);
    $mPC['time_budget_seconds'] = min(60.0, max(10.0, (float)($mPC['time_budget_seconds'] ?? 10.0))); $mPC['time_budget_step_seconds'] = 5.0;
    $mPC['time_budget_max_seconds'] = max(15.0, 60.0 - PP_SYNC_CLOSING_RESERVE_S); $mPC['__core_run_budget'] = 100; $mPC['change_over_search_budget_seconds'] = 20.0;
    $mPC['change_over_interactive_request'] = !empty($mPC['change_over']['enabled']); unset($mPC);
    pp_actual_gas_apply_snapshots($pc); $c = pp_v13f_clean(pp_v1517_engine_input($pc)); pp_v1531_mdp_strip($c);
    unset($c['data3']['modeling']['additional_lng'], $c['data3']['modeling']['distillate_user_limit_litres'], $c['data3']['modeling']['__fuel_decision_mode']);
    unset($c['data3']['modeling']['actual_pgn_total'], $c['data3']['modeling']['actual_energy_jababeka'], $c['data3']['modeling']['actual_energy_mm2100']);
    pp_v3_strip_meta($c['data3']['modeling']); foreach (array_keys($c) as $k) if (is_string($k) && strpos($k, '_') === 0) unset($c[$k]); return pp_flat_for_diff($c); };
$A = $norm($a); $B = $norm($b); $n = 0;
foreach (array_unique(array_merge(array_keys($A), array_keys($B))) as $k) if (($A[$k] ?? null) !== ($B[$k] ?? null)) { if ($n++ < 40) echo $k, ' : ', json_encode($A[$k] ?? null), ' | ', json_encode($B[$k] ?? null), "\n"; }
echo "diffs=$n\n";
