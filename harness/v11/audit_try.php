<?php
ini_set('memory_limit','3G'); define('PP_LIB_ONLY', true);
$dir=$argv[1]; chdir($dir); require $dir.'/run.php'; require '/home/claude/t5/v3/scen.php';
$in = v3_input($argv[2]); $d = json_decode(file_get_contents($argv[3]), true); $o = $d['output'];
$t=microtime(true); $A = pp_v11_frag_audit($in, $o); $C = pp_v11_cp_audit($in, $o);
echo json_encode(['frag'=>array_diff_key($A,['findings_detail'=>1]), 'first'=>array_slice($A['findings_detail'],0,4), 'cp'=>$C, 't'=>microtime(true)-$t], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), "\n";
