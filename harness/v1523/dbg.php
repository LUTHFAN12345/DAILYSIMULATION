<?php
// php dbg.php app pretight.json "g3:10-10" wd [off|on]
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$GLOBALS['__pp_async_worker']=true; $GLOBALS['__pp_async_worker_ceiling']=1800.0;
$D=json_decode(file_get_contents($argv[2]),true); $orig=pp_v1523_orig($D['input']); $K=pp_v1523_classes($orig);
$W=$D['out']; $XW=pp_v8_masks((array)$W['data']); $X=$XW; $chg=[];
foreach(explode(",",$argv[3]) as $s){$pp=explode(":",$s);$u=$pp[0];$rr=$pp[1];[$a,$b]=explode("-",$rr);$on=($pp[2]??"off")==="on";for($r=(int)$a;$r<=(int)$b;$r++){$X[$u][$r]=$on;$chg[$r]=true;}}
$restore=function(){pp_tl_clean_globals();};
$R=pp_v1523_local_eval($orig,$W,$XW,$X,$K,$restore,$chg,[(int)$argv[4]]);
echo json_encode($R['attempts']),"\n";
foreach(array_slice($R['o']['data'],(int)($argv[6]??13),8) as $k=>$r) printf("%2d G3 %5.1f G6 %5.1f S1 %5.1f G5 %5.1f G1 %5.1f S2 %5.1f G8 %5.1f G9 %5.1f S3 %5.1f exp %6.1f\n",$k+1+(int)($argv[6]??13),$r['G3'],$r['G6'],$r['S1'],$r['G5'],$r['G1'],$r['S2'],$r['G8'],$r['G9'],$r['S3'],$r['Export_PLN']);
