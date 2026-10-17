<?php
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$GLOBALS['__pp_async_worker']=true; $GLOBALS['__pp_async_worker_ceiling']=1800.0;
$D=json_decode(file_get_contents($argv[2]),true); putenv('PP_V1523_DUMP=');
$t=microtime(true); $o=pp_v1523_tighten($D['input'],$D['out']); fwrite(STDERR,"t=".round(microtime(true)-$t,2)."\n");
file_put_contents($argv[3],json_encode($o));
