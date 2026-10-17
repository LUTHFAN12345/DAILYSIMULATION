<?php // semantik engine untuk payload UI: semchk.php <unit> <payload>
define('PP_LIB_ONLY',1); chdir('/tmp/w/app'); require '/tmp/w/app/run.php';
$u=$argv[1]; $in=json_decode(file_get_contents($argv[2]),true); pp_v1518_semantics($in); $m=$in['data3']['modeling'];
$keys=[]; foreach($m as $k=>$v) if (is_array($v) && isset($v[$u]) && is_string($v[$u])) $keys[$k]=$v[$u];
echo json_encode(['unit'=>$u,'cannot_stop'=>in_array($u,array_map('strtolower',(array)($m['unit_cannot_stop']??[])),true),'last'=>$m['unit_last_data_status'][strtoupper($u)]??null]+$keys),"\n";
