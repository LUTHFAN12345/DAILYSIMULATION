<?php
declare(strict_types=1);
if(!function_exists('pp_store_commit')){
function pp_store_commit(string $event,array $input,?array $output=null,array $meta=[],?string &$err=null):array{
 $err=null;$dir=__DIR__.DIRECTORY_SEPARATOR.'saved_data_history';if(!is_dir($dir))@mkdir($dir,0777,true);
 $clean=$input;foreach(array_keys($clean)as$k)if(is_string($k)&&$k!==''&&$k[0]==='_')unset($clean[$k]);
 $ih=hash('sha256',json_encode($clean));$id=gmdate('Ymd_His').'_'.substr($ih,0,10).'_'.strtolower($event);
 $snap=['schema'=>'pp-central-snapshot-v1','id'=>$id,'saved_at'=>gmdate('c'),'event'=>$event,'meta'=>$meta,'input'=>$clean,'output'=>$output];
 $write=function($f,$v)use(&$err){$j=json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($j)){$err='encode';return false;}$t=$f.'.tmp.'.getmypid();if(@file_put_contents($t,$j,LOCK_EX)!==strlen($j)){$err='write';return false;}if(!@rename($t,$f)){$err='rename';return false;}return true;};
 if(!$write($dir.DIRECTORY_SEPARATOR.$id.'.json',$snap))return['ok'=>false,'error'=>$err];
 $f=__DIR__.DIRECTORY_SEPARATOR.'saved_data_store.json';$old=is_file($f)?json_decode((string)@file_get_contents($f),true):[];$ev=(array)($old['events']??[]);array_unshift($ev,['id'=>$id,'event'=>$event,'saved_at'=>$snap['saved_at']]);$store=['schema'=>'pp-central-store-v1','revision'=>(int)($old['revision']??0)+1,'latest'=>$snap,'events'=>array_slice($ev,0,100)];if(!$write($f,$store))return['ok'=>false,'error'=>$err];return['ok'=>true,'revision'=>$store['revision'],'id'=>$id];
}}
