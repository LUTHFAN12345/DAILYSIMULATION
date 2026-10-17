<?php
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$D=json_decode(file_get_contents($argv[2]),true); $in=$D['input']; $o=json_decode(file_get_contents($argv[3]),true); if(isset($o['output']))$o=$o['output'];
$orig=pp_v1523_orig($in); pp_tl_clean_globals();
$V=pp_validate_hard_constraints($orig,$o); echo "hard(orig): ",$V['status']," ",json_encode(array_slice($V['violations'],0,3)),"\n";
pp_tl_clean_globals(); $a=pp_tl_assess($orig,$o); echo "assess valid=",json_encode($a['valid'])," ",json_encode(array_keys(array_filter($a['checks'],fn($x)=>!$x))),"\n";
pp_tl_clean_globals(); $V2=pp_v1522_validate_final($in,$o); echo "final: ",json_encode($V2['status']??null)," ",json_encode(array_slice((array)($V2['violations']??[]),0,3)),"\n";
