<?php
/* php libeval.php <root> : evaluasi tugas pustaka jangkar (K x grid) langsung, cetak valid/cp per tugas */
ini_set('memory_limit','3G'); define('PP_LIB_ONLY', true); $dir=$argv[1]; chdir($dir); require $dir.'/run.php'; require '/home/claude/t5/v3/scen.php';
$in = pp_normalize_copy(v3_input('ACT_PGN_UP'));
$S0 = $in; foreach (array_keys((array)$S0['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($S0['data3']['modeling'][$mk]);
$B = pp_v3_find_base($S0); $base = $B['base']; if (!$base) { echo "no base ".json_encode($B['reason'])."\n"; exit; }
$ancOrig = pp_normalize_copy((array)$base['input']); foreach (array_keys((array)$ancOrig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($ancOrig['data3']['modeling'][$mk]);
$lib = json_decode(file_get_contents(glob($dir.'/jobs/_final/*.v10lib.json')[0]), true);
$ancN = $ancOrig; $ancN['data3']['modeling']['__v9_nopolish'] = true; $ancN['data3']['modeling']['__v10_sup_secant'] = true;
$only = $argv[2] ?? '';
foreach ($lib['K'] as $k) foreach ([-0.16, 0.0, 0.16] as $p) { if ($only !== '' && strpos($k['id'], $only) === false) continue;
  $t=microtime(true); $a = pp_tl_eval($ancN, [], $k['adj'] + $p, microtime(true)+300, $k['stops'], $k['hint']); pp_tl_clean_globals();
  printf("%-40s %+.2f valid=%d cp=%s shape=%s %.1fs viol=%s\n", $k['id'], $p, !empty($a['valid']), $a['key']['cp'] ?? '-', is_array($a['output']['data'] ?? null) ? substr(md5(json_encode(pp_v10_shape((array)$a['output']['data']))),0,8) : '-', microtime(true)-$t, json_encode(array_slice((array)($a['violations'] ?? []),0,2))); }
