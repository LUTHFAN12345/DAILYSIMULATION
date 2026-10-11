<?php
/* ieunit.php <src> : pemetaan row IE Adjustment (label inklusif; 00:00 = row 48) */
define('PP_LIB_ONLY', 1); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents('/home/claude/ab/payloads/P0.json'), true);
$cases = ['IE3' => [['operator' => '+', 'value' => 15, 'start_period' => '10:00', 'stop_period' => '12:00']],
          'IE4' => [['operator' => '-', 'value' => 10, 'start_period' => '12:30', 'stop_period' => '17:30']],
          'IE5' => [['operator' => '+', 'value' => 5, 'start_period' => '18:00', 'stop_period' => '00:00']],
          'IE1' => [['operator' => '+', 'value' => 15, 'start_period' => '14:00', 'stop_period' => '14:00']]];
$cases['IE6'] = array_merge($cases['IE3'], $cases['IE4'], $cases['IE5']);
$res = [];
foreach ($cases as $k => $rules) { $x = $in; $x['data3']['modeling']['ie_adjustments'] = $rules; $s = pp_ie_series($x); $rows = [];
    foreach ($s['adj'] as $i => $a) if (abs($a) > 1e-9) $rows[] = ($i + 1) . '(' . $in['data1'][$i]['time'] . ')' . ($a > 0 ? '+' : '') . $a;
    $res[$k] = ['n' => count($rows), 'first' => $rows[0] ?? null, 'last' => $rows[count($rows) - 1] ?? null, 'pred_eff_row_first' => [$s['pred'][array_key_first(array_filter($s['adj']))], $s['eff'][array_key_first(array_filter($s['adj']))]]]; }
echo json_encode($res, JSON_PRETTY_PRINT) . "\n";
