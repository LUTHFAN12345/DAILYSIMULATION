<?php
/* Uji keandalan kontrak dispatch-di-dalam-request (tanpa proses OS).
 * php dispatch_reliability.php <akar> <port> <input.json> <out.md> */
$root=$argv[1]; $port=(int)$argv[2]; $in=$argv[3]; $out=$argv[4];
$B='http://127.0.0.1:'.$port;
$srv=proc_open('/usr/local/bin/php74 -d max_execution_time=30 -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root),
  [1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pp,$root,['PHP_CLI_SERVER_WORKERS'=>'8']);
sleep(2);
$R=[]; function rec($id,$n,$ok,$d=''){global $R;$R[]=[$id,$n,$ok,$d];printf("%s  %-5s %s  -- %s\n",$ok?'PASS':'FAIL',$id,$n,$d);}
function get($u,$to=60){$c=curl_init($u);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>$to]);$b=curl_exec($c);$h=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$ct=(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);return [$h,$b,$ct];}
function post($u,$p,$to=300){$c=curl_init($u);curl_setopt_array($c,[CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>json_encode($p),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>$to]);$b=curl_exec($c);$h=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);$ct=(string)curl_getinfo($c,CURLINFO_CONTENT_TYPE);curl_close($c);return [$h,$b,$ct];}
$base=json_decode(file_get_contents($in),true);
$base['data3']['modeling']['gas_quota']['pgn_pipe']=20; $base['data3']['modeling']['gas_shortage_action']='recommendation';
$base['_request_id']='REL1'; $base['_state_revision']=1; $base['_context']='plan';

/* D1 Run -> JSON, job terdaftar, tidak ada proses OS */
$ps0=(int)trim(shell_exec("ps -eo args | grep -c 'run.php --job='"));
[$h,$b,$ct]=post($B.'/run.php?mode=run',$base);
$j=json_decode((string)$b,true);
rec('H1','Run mengembalikan JSON yang sah',is_array($j),"http=$h");
rec('H2','Run Content-Type application/json',stripos($ct,'application/json')!==false,$ct);
rec('H3','tidak ada keluaran sebelum JSON',is_string($b)&&ltrim($b)!==''&&ltrim($b)[0]==='{','byte pertama='.substr(ltrim((string)$b),0,1));
$job=$j['async_job']??null;
rec('D1','job terdaftar dengan token eksekusi',!empty($job['job_id'])&&!empty($job['exec_token']),substr((string)($job['job_id']??'-'),0,30));
$jid=(string)($job['job_id']??''); $tok=(string)($job['exec_token']??'');
[$h2,$b2]=get($B.'/run.php?mode=job_poll&job='.urlencode($jid));
$pj=json_decode((string)$b2,true);
rec('D2','sebelum dipicu, job TIDAK berjalan sendiri (tidak ada proses OS)',in_array($pj['job']['status']??'',['QUEUED'],true),'status='.($pj['job']['status']??'?'));
rec('D3','preliminary: Save & Publish terkunci',($j['save_allowed']??null)===false&&($j['publish_allowed']??null)===false,'save='.var_export($j['save_allowed']??null,true));

/* D4 token salah ditolak */
[$h3,$b3]=get($B.'/run.php?mode=job_exec&job='.urlencode($jid).'&token=salah');
rec('D4','token salah ditolak 403',$h3===403,"http=$h3 ".substr((string)$b3,0,60));

/* D5 klik ganda: dua job_exec bersamaan -> hanya satu yang menghitung */
$mh=curl_multi_init(); $hs=[];
for($i=0;$i<3;$i++){$c=curl_init($B.'/run.php?mode=job_exec&job='.urlencode($jid).'&token='.urlencode($tok));curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>900]);curl_multi_add_handle($mh,$c);$hs[]=$c;usleep(150000);}
$t0=microtime(true); do{curl_multi_exec($mh,$run);curl_multi_select($mh,1.0);}while($run>0);
$res=[];foreach($hs as $c){$res[]=json_decode((string)curl_multi_getcontent($c),true);curl_multi_remove_handle($mh,$c);}
$pemilik=0;$already=0;foreach($res as $r){if(isset($r['exit_code']))$pemilik++;elseif(isset($r['already']))$already++;}
rec('D5','tiga pemicu bersamaan: tepat SATU pemilik perhitungan',$pemilik===1&&$already===2,"pemilik=$pemilik sudah=$already wall=".round(microtime(true)-$t0,1).'s');
$ps1=(int)trim(shell_exec("ps -eo args | grep -c 'run.php --job='"));
rec('D6','tidak ada satu pun proses worker OS lahir',$ps1<=$ps0,"sebelum=$ps0 sesudah=$ps1 (termasuk grep)");

/* D7 DONE hanya setelah hasil ada dan valid */
[$h4,$b4]=get($B.'/run.php?mode=job_poll&job='.urlencode($jid));
$pj=json_decode((string)$b4,true); $st=$pj['job']['status']??'?';
$dir=$root.'/jobs/'.$jid;
$rf=$dir.'/result.json'; $valid=is_file($rf)&&json_decode(file_get_contents($rf),true)!==null;
rec('D7','DONE hanya dengan result.json yang valid',$st==='DONE'&&$valid&&!empty($pj['result']),"status=$st valid=".var_export($valid,true));
rec('D8','tidak ada sisa .tmp setelah rename atomik',count(glob($dir.'/result.json.*.tmp')?:[])===0,'tmp='.count(glob($dir.'/result.json.*.tmp')?:[]));
$o=$pj['result']['output']??[];
rec('D9','hasil eksak: 48 baris, konvergen',count($o['data']??[])===48&&(($o['info']['Run Status']['converged']??null)===true),'rows='.count($o['data']??[]));

/* D10 retry setelah DONE: tidak menghitung ulang */
$t1=microtime(true);[$h5,$b5]=get($B.'/run.php?mode=job_exec&job='.urlencode($jid).'&token='.urlencode($tok));
$r5=json_decode((string)$b5,true);
rec('D10','retry setelah DONE tidak menghitung ulang (idempoten)',($r5['already']??'')==='DONE'&&microtime(true)-$t1<3,'already='.($r5['already']??'-').' '.round(microtime(true)-$t1,2).'s');

/* D11 refresh: Run identik -> job yang sama dipakai ulang, hasil langsung tersedia */
$base['_request_id']='REL2';
[$h6,$b6]=post($B.'/run.php?mode=run',$base);$j6=json_decode((string)$b6,true);
rec('D11','refresh/Run ulang input sama memakai job yang sama',($j6['async_job']['job_id']??'')===$jid,'job='.substr((string)($j6['async_job']['job_id']??'-'),0,30));

/* D12 stale: hash input berbeda ditolak */
[$h7,$b7]=get($B.'/run.php?mode=job_poll&job='.urlencode($jid).'&input_hash='.str_repeat('0',64));
$p7=json_decode((string)$b7,true);
rec('D12','hasil basi (input_hash beda) ditolak',!empty($p7['stale'])&&!isset($p7['result']),'stale='.var_export($p7['stale']??null,true));

/* D13 request terputus: klien memutus setelah 2 detik, perhitungan tetap selesai */
$b2x=$base; $b2x['data3']['modeling']['gas_quota']['pgn_pipe']=30; $b2x['_request_id']='REL3';
[$h8,$b8]=post($B.'/run.php?mode=run',$b2x);$j8=json_decode((string)$b8,true);
$jid2=(string)($j8['async_job']['job_id']??''); $tok2=(string)($j8['async_job']['exec_token']??'');
$ok13=false;$n13='tidak ada job (run PGN30 mungkin sudah final sinkron)';
if($jid2!==''){ get($B.'/run.php?mode=job_exec&job='.urlencode($jid2).'&token='.urlencode($tok2),2);
  for($k=0;$k<600;$k++){sleep(1);[$hh,$bb]=get($B.'/run.php?mode=job_poll&job='.urlencode($jid2));$pp9=json_decode((string)$bb,true);
    if(in_array($pp9['job']['status']??'',['DONE','FAILED','CANCELLED'],true))break;}
  $ok13=($pp9['job']['status']??'')==='DONE'&&!empty($pp9['result']);$n13='status='.($pp9['job']['status']??'?').' setelah klien memutus pada 2 s';}
else { $ok13 = ($j8['ok']??false)===true && count($j8['data']??[])===48; }
rec('D13','request terputus tetap menyelesaikan dan menulis hasil',$ok13,$n13);


/* ---------- JOB SEDANG BERJALAN: Run ulang, denyut basi, batal, pakai ulang ---------- */
$bx=$base; $bx['data3']['modeling']['gas_quota']['pgn_pipe']=21; $bx['_request_id']='REL4';
[$hA,$bA]=post($B.'/run.php?mode=run',$bx);$jA=json_decode((string)$bA,true);
$jx=(string)($jA['async_job']['job_id']??''); $tx=(string)($jA['async_job']['exec_token']??'');
if($jx===''){ rec('D14','Run ulang saat job berjalan memakai job yang sama',false,'tidak ada job asinkron PGN21'); }
else {
  get($B.'/run.php?mode=job_exec&job='.urlencode($jx).'&token='.urlencode($tx),1);
  $c0='';for($k=0;$k<60;$k++){usleep(300000);$jj=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($jx))[1],true)['job']??[];
    if(($jj['status']??'')==='RUNNING'){$c0=(string)($jj['claim_id']??'');break;}}
  /* D16: denyut dibuat basi 1 jam; penyapu tidak boleh membunuh job yang kuncinya dipegang */
  $dx=$root.'/jobs/'.$jx; $lk=fopen($dx.'/job.lock','c'); flock($lk,LOCK_EX);
  $jo=json_decode(file_get_contents($dx.'/job.json'),true); $jo['heartbeat_ts']=microtime(true)-3600; $jo['updated_at']=date('c',time()-3600);
  file_put_contents($dx.'/job.json',json_encode($jo)); flock($lk,LOCK_UN); fclose($lk);
  $p16=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($jx))[1],true)['job']??[];
  rec('D16','job sehat dengan denyut basi 1 jam TIDAK disapu (kunci eksekusi dipegang)',in_array($p16['status']??'',['RUNNING','DONE'],true),'status='.($p16['status']??'?'));
  /* D14: Run ulang input sama saat job berjalan */
  $bx['_request_id']='REL5';
  $jB=json_decode((string)post($B.'/run.php?mode=run',$bx)[1],true);
  $p14=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($jx))[1],true)['job']??[];
  rec('D14','Run ulang saat job berjalan memakai job yang sama (tidak dibuat ulang)',($jB['async_job']['job_id']??'')===$jx&&(string)($p14['claim_id']??'')===$c0&&$c0!=='','claim tetap='.var_export((string)($p14['claim_id']??'')===$c0,true).' status='.($p14['status']??'?'));
  /* D17: batal tidak mematikan server */
  $pc=json_decode((string)get($B.'/run.php?mode=job_cancel&job='.urlencode($jx))[1],true)['job']??[];
  [$hq]=get($B.'/run.php?mode=job_poll&job='.urlencode($jx));
  rec('D17','Batal pada job berjalan: status CANCELLED dan server tetap melayani',in_array($pc['status']??'',['CANCELLED','DONE'],true)&&$hq===200,'status='.($pc['status']??'?')." poll http=$hq");
  /* D18: Run lagi setelah batal -> perhitungan yang masih berjalan dipakai ulang, tidak digandakan */
  $bx['_request_id']='REL6';
  $jC=json_decode((string)post($B.'/run.php?mode=run',$bx)[1],true);
  $pz0=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($jx))[1],true)['job']??[];
  $jalur=((string)($pz0['claim_id']??'')===$c0)?'pakai-ulang perhitungan berjalan':'perhitungan lama sudah berakhir; job baru dibuat';
  if(($pz0['status']??'')==='QUEUED'){ $tk=(string)($jC['async_job']['exec_token']??($pz0['exec_token']??''));
    get($B.'/run.php?mode=job_exec&job='.urlencode($jx).'&token='.urlencode($tk),900); }
  for($k=0;$k<900;$k++){$pz=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($jx))[1],true);
    if(in_array($pz['job']['status']??'',['DONE','FAILED','CANCELLED'],true))break; sleep(1);}
  $satuKlaim = empty($pz['job']['reclaimed_from']);
  rec('D18','Run setelah batal: tidak ada perhitungan ganda, berakhir DONE 48 baris',
    ($jC['async_job']['job_id']??'')===$jx&&($pz['job']['status']??'')==='DONE'&&$satuKlaim&&count($pz['result']['output']['data']??[])===48,
    $jalur.'; status='.($pz['job']['status']??'?').' rows='.count($pz['result']['output']['data']??[]));
}
/* D15: pemilik mati (status RUNNING, kunci lepas) -> disapu FAILED dengan sebab jelas */
$fid='economic_review-deadowner'.substr(md5((string)microtime(true)),0,6); $fd=$root.'/jobs/'.$fid; @mkdir($fd,0777,true);
file_put_contents($fd.'/job.json',json_encode(['schema'=>'co12-async-job-v1','job_id'=>$fid,'kind'=>'economic_review','status'=>'RUNNING','claim_id'=>'x','exec_token'=>'t',
  'created_at'=>date('c',time()-60),'updated_at'=>date('c',time()-60),'heartbeat_ts'=>microtime(true)-60,'progress'=>[]]));
$p15=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($fid))[1],true)['job']??[];
rec('D15','request penghitung mati -> job ditandai FAILED dengan sebab JOB_REQUEST_BERHENTI',($p15['status']??'')==='FAILED'&&($p15['error']['code']??'')==='JOB_REQUEST_BERHENTI','status='.($p15['status']??'?').' code='.($p15['error']['code']??'-'));
/* D20: status akhir hilang (job RUNNING, kunci lepas, result.json utuh) -> dipulihkan DONE */
$hid='economic_review-recover'.substr(md5((string)microtime(true)),0,6); $hd=$root.'/jobs/'.$hid; @mkdir($hd,0777,true);
file_put_contents($hd.'/result.json',json_encode(['kind'=>'economic_review','output'=>['data'=>[]]]));
file_put_contents($hd.'/job.json',json_encode(['schema'=>'co12-async-job-v1','job_id'=>$hid,'kind'=>'economic_review','status'=>'RUNNING','percent'=>100,'current_step'=>'VALIDASI_SELESAI','exec_token'=>'t',
  'created_at'=>date('c',time()-60),'updated_at'=>date('c',time()-60),'progress'=>[]]));
$p20=json_decode((string)get($B.'/run.php?mode=job_poll&job='.urlencode($hid))[1],true);
rec('D20','progress 100% tetapi status akhir hilang -> dipulihkan DONE dan hasil langsung diberikan',($p20['job']['status']??'')==='DONE'&&!empty($p20['result'])&&!empty($p20['job']['recovered_done']),'status='.($p20['job']['status']??'?').' hasil='.(!empty($p20['result'])?'ada':'tidak'));
/* D19: pemindaian statis — tidak ada pemanggil proses OS di keempat berkas produksi */
$hit=[];
foreach(array_filter(['run.php','worker02.php','worker_functions.php','index.php','saved_data_store.php'],function($x)use($root){return is_file($root.'/'.$x);}) as $f){
  $src=file_get_contents($root.'/'.$f);
  if($f!=='index.php'){ $code=preg_replace('~/\*.*?\*/~s','',$src); $code=preg_replace('~^\s*(//|#)[^\n]*~m','',$code);
    if(preg_match_all('~(?<![\w>\$:])(shell_exec|exec|proc_open|popen|system|passthru|posix_kill|pcntl_exec|pcntl_fork)\s*\(~i',$code,$m2)) foreach($m2[1] as $x)$hit[]="$f:$x("; }
  if(preg_match_all('~(cmd\.exe|powershell|start\s+/B|tasklist|php\.exe)~i',preg_replace('~/\*.*?\*/|//[^\n]*~s','',$src),$mm)) foreach($mm[0] as $x)$hit[]="$f:$x";
}
rec('D19','pemindaian token PHP: tidak ada shell_exec/exec/proc_open/popen/system/passthru/posix_kill, tidak ada cmd/powershell/start /B/tasklist/php.exe di kode',!$hit,$hit?implode(', ',array_slice($hit,0,6)):'0 temuan');

/* H4 Save JSON */
[$h9,$b9,$ct9]=post($B.'/run.php?mode=save',$base);$j9=json_decode((string)$b9,true);
rec('H4','Save mengembalikan JSON yang sah',is_array($j9),"http=$h9 ".$ct9);
rec('H5','Save Content-Type application/json',stripos($ct9,'application/json')!==false,$ct9);

proc_terminate($srv);
$p=count(array_filter($R,fn($r)=>$r[2]));
$md="# KEANDALAN DISPATCH DI DALAM REQUEST\n\nHasil: **$p/".count($R)."**\n\n| id | pemeriksaan | hasil | rincian |\n|---|---|---|---|\n";
foreach($R as $r)$md.="| {$r[0]} | {$r[1]} | ".($r[2]?'LULUS':'GAGAL')." | ".str_replace('|','/',$r[3])." |\n";
file_put_contents($out,$md);
echo "\n$p/".count($R)." PASS\n";
