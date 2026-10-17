<?php
// usage: php runjob2.php <appdir> <input.json> <out.json> [extra-env k=v,...]
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
$in=json_decode(file_get_contents($argv[2]),true);
$in['_request_id']='h'.substr(md5(microtime()),0,8);
$st=pp_job_start($in,'economic_review',$in['_request_id'],true);
$id=$st['job']['job_id']; $tf=tempnam('/tmp','tm');
$cmd='/usr/bin/time -f "%e %U %S %M" -o '.escapeshellarg($tf).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($app.'/run.php').' --job='.escapeshellarg($id)
    .' & CP=$!; sleep 0.3; for p in $(pgrep -P $CP) $CP; do grep -h Cpus_allowed_list /proc/$p/status 2>/dev/null; done | sort -u > '.escapeshellarg($tf.'.aff').'; wait $CP';
$t=microtime(true); passthru('bash -c '.escapeshellarg($cmd),$rc); $w=microtime(true)-$t;
$tm=preg_split('/\s+/',trim((string)@file_get_contents($tf)));
$r=json_decode(@file_get_contents(pp_job_dir($id).'/result.json'),true) ?: [];
$r['__wall']=$w; $r['__bench']=['php'=>PHP_VERSION,'opcache'=>function_exists('opcache_get_status')&&@opcache_get_status(false)['opcache_enabled'],'affinity'=>trim((string)@file_get_contents($tf.'.aff')),
  'wall_s'=>(float)($tm[0]??0),'user_s'=>(float)($tm[1]??0),'sys_s'=>(float)($tm[2]??0),'cpu_s'=>(float)($tm[1]??0)+(float)($tm[2]??0),'max_rss_kb'=>(int)($tm[3]??0),'job_id'=>$id,'payload_hash'=>pp_payload_hash($in),'job_input_hash'=>pp_job_input_hash($in)];
fwrite(STDERR,sprintf("rc=%d wall=%.2f cpu=%.2f rss=%dMB aff=%s php=%s\n",$rc,$w,$r['__bench']['cpu_s'],$r['__bench']['max_rss_kb']/1024,$r['__bench']['affinity'],PHP_VERSION));
file_put_contents($argv[3],json_encode($r));
