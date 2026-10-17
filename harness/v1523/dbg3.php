<?php
// reproduce proof-time W: apply tighten with PP_V1523 then evaluate G9 delay against W
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$GLOBALS['__pp_async_worker']=true; $GLOBALS['__pp_async_worker_ceiling']=1800.0;
$D=json_decode(file_get_contents($argv[2]),true); putenv('PP_V1523_DUMP=');
$W=pp_v1523_tighten($D['input'],$D['out']); $orig=pp_v1523_orig($D['input']); $K=pp_v1523_classes($orig);
$XW=pp_v8_masks((array)$W['data']); $X=$XW; $X['g9'][2]=false; $chg=[2=>true];
$R=pp_v1523_local_eval($orig,$W,$XW,$X,$K,function(){pp_tl_clean_globals();},$chg,null,false);
echo json_encode($R['attempts'][0]),"\n";
foreach([32,33,34,35] as $r){ $x=$W['data'][$r-1]; $y=$R['o']['data'][$r-1]; echo $r; foreach(['G8','G9','S3','G2','G5','G4','G6','S1','S2','BB2','G10','G7','HL','IE','Export_PLN'] as $c) printf(" %s %.3f/%.3f",$c,$x[$c],$y[$c]); echo "\n"; }
