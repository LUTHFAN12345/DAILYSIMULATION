<?php
// usage: php runjob.php <appdir> <input.json> <out.json>
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app);
require $app.'/run.php';
$in=json_decode(file_get_contents($argv[2]),true);
$in['_request_id']='h'.substr(md5(microtime()),0,8);
$st=pp_job_start($in,'economic_review',$in['_request_id'],true);
$id=$st['job']['job_id'];
fwrite(STDERR,"job $id\n");
$t=microtime(true);
$cmd=escapeshellarg(PHP_BINARY).' -d memory_limit=2048M '.escapeshellarg($app.'/run.php').' --job='.escapeshellarg($id);
passthru($cmd,$rc);
$w=microtime(true)-$t;
$r=json_decode(@file_get_contents(pp_job_dir($id).'/result.json'),true);
fwrite(STDERR,sprintf("rc=%d wall=%.2f\n",$rc,$w));
$r['__wall']=$w; file_put_contents($argv[3],json_encode($r));
