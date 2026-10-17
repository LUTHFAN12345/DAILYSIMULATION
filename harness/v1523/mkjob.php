<?php
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$in=json_decode(file_get_contents($argv[2]),true); $in['_request_id']='p'.substr(md5(microtime()),0,8);
$st=pp_job_start($in,'economic_review',$in['_request_id'],true); echo $st['job']['job_id'],' ',$st['job']['exec_token'],"\n";
