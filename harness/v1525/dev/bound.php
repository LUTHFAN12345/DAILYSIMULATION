<?php
// bound.php <app> <input.json> : batas atas energi Export harian (diagnostik Fase 1 grup E)
$app = $argv[1]; define('PP_LIB_ONLY', 1); chdir($app); require $app . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $d3 = $in['data3']; $m = $d3['modeling']; $d3['modeling'] = $m;
$ie = pp_ie_series($in)['eff']; $pt = pp_compute_pln_targets($m, $in['data2']); $hi = $pt['hi'];
$blocks = ['b1' => ['g3', 'g4', 'g6'], 'b2' => ['g1', 'g2', 'g5'], 'b3' => ['g8', 'g9']];
$best = []; $t0 = microtime(true);
foreach ($blocks as $b => $gs) {
    $grid = [];
    foreach ($gs as $g) { $mn = (float)$d3[$g]['min_scload']; $mx = (float)$d3[$g]['max_load']; $v = [0.0]; for ($x = $mn; $x <= $mx + 1e-9; $x += 1) $v[] = $x; if (end($v) < $mx) $v[] = $mx; $grid[$g] = $v; }
    $bestR = 0; $bestC = null; $maxOut = 0;
    $rec = function ($i, $cur) use (&$rec, $gs, $grid, $d3, $m, &$bestR, &$bestC, &$maxOut) {
        if ($i === count($gs)) { $gen = array_fill_keys(['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'], 0.0); foreach ($cur as $k => $v) $gen[$k] = $v;
            $f = 0; foreach ($cur as $k => $v) $f += calc_fuel($d3, $k, $v); if ($f <= 0) return; pp_recompute_stgs($gen, $d3, $m, 20);
            $out = array_sum($cur) + $gen['s1'] + $gen['s2'] + $gen['s3']; if ($out / $f > $bestR) { $bestR = $out / $f; $bestC = [$cur, $gen['s1'] + $gen['s2'] + $gen['s3']]; } $maxOut = max($maxOut, $out); return; }
        foreach ($grid[$gs[$i]] as $v) { $c = $cur; $c[$gs[$i]] = $v; $rec($i + 1, $c); } };
    $rec(0, []); $best[$b] = ['mw_per_fuel_rate' => $bestR, 'cfg' => $bestC, 'max_out' => $maxOut];
}
$rho = max(array_column($best, 'mw_per_fuel_rate'));
[$used, $quota] = [null, (float)array_sum(array_map('floatval', array_intersect_key($m['gas_quota'], array_flip(['pgn_pipe', 'lng', 'pep', 'akasia', 'bbg', 'baskara'])))) + (float)($m['additional_lng'] ?? 0)];
$distL = (float)($m['distillate_user_limit_litres'] ?? 0); $l1 = pp_distillate_litres_from_bbtu(1.0, $m); $distB = $l1 > 0 ? $distL / $l1 : 0;
$bab = 2 * 120.0; $hlMin = 3.75;
$ieSum = array_sum($ie) / 2; $hiSum = array_sum($hi) / 2;
$fuelB = $quota + $distB;               // BBTU/day
$genFuelMWh = $fuelB * $rho;            // rate convention: sum(calc_fuel)/2 = BBTUD; MW/rate * BBTU = MWh
$expFuel = $genFuelMWh + $bab * 24 - $ieSum - $hlMin * 24;
$cap = 0; foreach ($best as $x) $cap += $x['max_out']; $capExp = 0; foreach ($ie as $r => $v) $capExp += min($hi[$r], $cap + $bab - $v - $hlMin) / 2;
echo json_encode(['rho' => $rho, 'best' => $best, 'quota' => $quota, 'dist_bbtu' => $distB, 'ie_mwh' => $ieSum, 'rangemax_mwh' => $hiSum, 'cap_bound_mwh' => $capExp, 'fuel_bound_mwh' => $expFuel, 's' => microtime(true) - $t0], JSON_PRETTY_PRINT), "\n";
