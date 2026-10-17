<?php
$app='/tmp/w/app'; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$GLOBALS['__pp_async_worker']=true; $GLOBALS['__pp_async_worker_ceiling']=1800.0;
$D=json_decode(file_get_contents($argv[1]),true); putenv('PP_V1523_DUMP=');
$orig=pp_v1523_orig($D['input']); $out=$D['out']; $K=pp_v1523_classes($orig);
$saved=[]; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
$restore=function() use($saved){ pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; if (function_exists('pp_v1518_stg_memo_reset')) pp_v1518_stg_memo_reset(); };
$XW=pp_v8_masks((array)$out['data']); $t=microtime(true); $n=0;
foreach (['g3','g5'] as $u) { $iv=pp_v8_intervals($XW[$u]); [$a1,$b1]=$iv[0];
  foreach ([[$b1,$b1],[$a1,$a1],[$a1,$b1]] as [$x,$y]) { $X=$XW; for($r=$x;$r<=$y;$r++) $X[$u][$r]=false; $chg=[]; for($r=$x;$r<=$y;$r++) $chg[$r]=true;
   $R=pp_v1523_local_eval($orig,$out,$XW,$X,$K,$restore,$chg,null,false); $n+=count($R['attempts']); } }
echo "evals $n t=",round(microtime(true)-$t,2),"\n";
