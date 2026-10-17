<?php
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$GLOBALS['__pp_async_worker']=true; $GLOBALS['__pp_async_worker_ceiling']=1800.0;
$D=json_decode(file_get_contents($argv[2]),true); $orig=pp_v1523_orig($D['input']); $K=pp_v1523_classes($orig);
$W=$D['out']; $XW=pp_v8_masks((array)$W['data']); $X=$XW; $chg=[];
foreach(explode(',',$argv[3]) as $s){$pp=explode(":",$s);$u=$pp[0];[$a,$b]=explode("-",$pp[1]);$on=($pp[2]??"off")==="on";for($r=(int)$a;$r<=(int)$b;$r++){$X[$u][$r]=$on;$chg[$r]=true;}}
$R=pp_v1523_local_eval($orig,$W,$XW,$X,$K,function(){pp_tl_clean_globals();},$chg,null,false);
echo json_encode($R['attempts']),"\n";
$cols=explode(',',$argv[5]); [$ra,$rb]=explode('-',$argv[4]);
for($r=(int)$ra;$r<=(int)$rb;$r++){ $x=$W['data'][$r-1]; $y=$R['o']['data'][$r-1]; echo $r; foreach($cols as $c) printf(" %s %.2f/%.2f",$c,$x[$c],$y[$c]); echo "\n"; }
