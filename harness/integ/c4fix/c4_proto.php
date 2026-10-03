<?php
ini_set('memory_limit','3G'); define('PP_LIB_ONLY', true);
$dir = __DIR__.'/root'; chdir($dir); require $dir.'/run.php';
$D = json_decode(file_get_contents($argv[1]), true); $in = $D['input']; $out = $D['output'];
$orig = pp_normalize_copy($in); unset($orig['_context'], $orig['_request_id']);
$ma = pp_v12_merit_audit($orig, $out); $c4 = $ma['c4_cross_group_priority'];
echo "BEFORE status {$ma['status']} c4 fail {$c4['fail']} CP ", $out['info']['Cost Production (USD/MWh)'], " HR ", $out['info']['JBBK MM Heat Rate (BTU/kWh)'] ?? '-', "\n";
$sh = pp_v10_shape($out['data']);
$moved = 0;
foreach ($ma['rows'] as $rw) { $r = $rw['row'];
  $UU = array_values(array_filter($rw['units'], function ($e) { return $e['mw'] > 0.01 && in_array($e['class'], ['GTG'], true); }));
  usort($UU, function($a,$b){ return [$a['priority_group']??99,$a['priority_rank']??99] <=> [$b['priority_group']??99,$b['priority_rank']??99]; });
  // low units from lowest priority upward
  $lows = array_reverse($UU);
  $room = []; foreach ($UU as $e) $room[$e['unit']] = $e['legal_headroom_mw'];
  foreach ($lows as $lo) { if ($lo['status'] || $lo['mw'] <= $lo['min'] + 0.01) continue; $ex = (float)$sh[$r-1][$lo['unit']] - $lo['min'];
    foreach ($UU as $hi) { if ($ex <= 0.01) break; if (($hi['priority_group'] ?? 99) >= ($lo['priority_group'] ?? 99)) continue; $h = $room[$hi['unit']]; if ($h <= 0.5) continue;
      $d = min($h, $ex); $sh[$r-1][$hi['unit']] = round($sh[$r-1][$hi['unit']] + $d, 4); $sh[$r-1][$lo['unit']] = round($sh[$r-1][$lo['unit']] - $d, 4); $room[$hi['unit']] -= $d; $ex -= $d; $moved += $d;
      echo "row $r move ", round($d,3), " {$lo['unit']} -> {$hi['unit']}\n"; } } }
echo "moved $moved\n";
$T = pp_tl_supplier_target($out); $dl = microtime(true) + 120;
$a = pp_v10_fz($orig, $sh, $T, $dl);
if (!is_array($a)) { echo "fz null\n"; exit; }
echo "fz valid ", json_encode($a['valid'] ?? null), " CP ", $a['key']['cp'] ?? '-', " HR ", $a['key']['hr'] ?? '-', " viol ", json_encode(array_slice((array)($a['violations'] ?? []),0,6)), "\n";
if (empty($a['valid'])) { $lg = null; $L = pp_v10_land($orig, $sh, $T, $dl, 6, $lg); if (is_array($L)) { $a = $L; echo "land valid ", json_encode($a['valid'] ?? null), " CP ", $a['key']['cp'] ?? '-', " viol ", json_encode(array_slice((array)($a['violations'] ?? []),0,6)), "\n"; } }
$o2 = $a['output']; $mb = pp_v12_merit_audit($orig, $o2);
echo "AFTER merit {$mb['status']} c2f {$mb['c2_start_with_headroom']['fail']} c3f {$mb['c3_first_legal_stop']['fail']} c4 ", json_encode(['fail'=>$mb['c4_cross_group_priority']['fail'],'detail'=>array_slice($mb['c4_cross_group_priority']['detail'],0,4)]), "\n";
$cols = ['G1','G2','G5','S2','G8','G9','S3','Export_PLN','Spin_Res'];
foreach ([22,23,24,27,28,29,30,31,32,33] as $r) { echo $r; foreach ($cols as $c) echo ' ', $c, '=', round((float)($o2['data'][$r-1][$c] ?? 0),2); echo "\n"; }
file_put_contents($argv[2], json_encode(['a'=>array_diff_key($a,['output'=>1]),'output'=>$o2]));
