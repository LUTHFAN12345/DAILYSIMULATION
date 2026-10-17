<?php // mk.php <app> <input> : buat job, cetak "id token"
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$in=json_decode(file_get_contents($argv[2]),true); $in['_request_id']='hf'.substr(md5(microtime().mt_rand()),0,8);
$st=pp_job_start($in,'economic_review',$in['_request_id'],true); $id=$st['job']['job_id']; $j=pp_job_read($id);
echo $id,' ',$j['exec_token'] ?? '',' ',$j['helpers'] ?? '',"\n";
