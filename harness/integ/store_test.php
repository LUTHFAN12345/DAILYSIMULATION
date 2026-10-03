<?php
/* Uji saved_data_store.php (8 butir instruksi + hapus berkonfirmasi, metadata, migrasi skema, pemulihan berkas kerja).
 * php store_test.php <src> <port> <out.md> */
[$_, $SRC, $PORT, $OUT] = $argv; $PHP = '/usr/local/bin/php74';
$R = '/tmp/claude-0/storetest_' . $PORT; exec('rm -rf ' . escapeshellarg($R)); mkdir($R . '/jobs', 0777, true);
foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'] as $f) copy("$SRC/$f", "$R/$f");
$base = json_decode(file_get_contents('/home/claude/t5/integ/sw/base_WB09_3.json'), true);
copy('/home/claude/t5/fixtures/input_actual.json', "$R/input_data.json");
$srv = proc_open("$PHP -d max_execution_time=0 -S 127.0.0.1:$PORT -t " . escapeshellarg($R), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp, $R); usleep(1500000);
$B = "http://127.0.0.1:$PORT";
function http($u, $p = null, $to = 120) { $c = curl_init($u); $o = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $to];
    if ($p !== null) { $o[CURLOPT_POST] = 1; $o[CURLOPT_POSTFIELDS] = json_encode($p); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($c, $o); $b = curl_exec($c); curl_close($c); return json_decode((string)$b, true); }
function cli($R, $code, $env = '') { global $PHP; $f = tempnam('/tmp', 'sds'); file_put_contents($f, "<?php define('PP_LIB_ONLY',1); chdir('$R'); require '$R/saved_data_store.php'; $code");
    $o = shell_exec("cd $R && $env $PHP $f 2>&1"); @unlink($f); return $o; }
$T = []; $pass = function ($id, $name, $ok, $ev) use (&$T) { $T[] = [$id, $name, (bool)$ok, $ev]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  $name  -- " . substr(is_string($ev) ? $ev : json_encode($ev), 0, 240) . "\n"; };

/* 1. Save Actual + Planning lewat tombol Save (mode=save) */
$planIn = $base; unset($planIn['data3']['modeling']['report_planning']);
$in = $base;
$in['data3']['modeling']['report_planning'] = ['records' => [
    ['plan_type' => 'plan', 'plan_date' => '2026-07-09', 'name_plan' => 'Daily Plan 09 Jul (uji store)', 'saved_at' => '2026-07-08T20:00:00', 'snapshot' => ['input' => $planIn, 'output' => ['info' => ['Cost Production (USD/MWh)' => 63.4469]]]],
    ['plan_type' => 'monitoring', 'plan_date' => '2026-07-08', 'name_plan' => 'Monitoring Daily Plan 08 Jul', 'saved_at' => '2026-07-08T21:00:00', 'snapshot' => ['input' => $planIn]]]];
$in['data3']['modeling']['report_actual'] = ['2026' => ['7' => ['2026-07-08' => ['PGN' => [1.25, 1.24, 1.26], 'Export' => [40, 42, 41]]]]];
$rs = http("$B/run.php?mode=save", $in);
$L = http("$B/run.php?mode=store_list"); $types = array_count_values(array_map(function ($r) { return $r['plan_type']; }, (array)($L['records'] ?? [])));
$pass('S1', 'Save data Actual dan Planning (tombol Save) -> record tersimpan di data/saved/records', ($rs['result'] ?? '') === 'ok' && ($types['Actual'] ?? 0) >= 1 && ($types['Planning'] ?? 0) >= 1 && ($types['Monitoring'] ?? 0) >= 1,
    ['save' => $rs['_store'] ?? null, 'types' => $types]);
$recs = (array)$L['records']; $planRec = null; foreach ($recs as $r) if ($r['plan_type'] === 'Planning') $planRec = $r;
$fPlan = "$R/data/saved/records/{$planRec['record_id']}.json"; $shaBefore = hash_file('sha256', $fPlan);

/* 2. Ubah source engine (revisi) */
$fp0 = trim((string)cli($R, "require '$R/run.php'; echo pp_engine_fingerprint();"));
file_put_contents("$R/worker02.php", "\n/* revisi uji store " . date('c') . " */\n", FILE_APPEND); file_put_contents("$R/run.php", "\n/* revisi uji store */\n", FILE_APPEND);
touch("$R/worker02.php"); clearstatcache();
$fp1 = trim((string)cli($R, "require '$R/run.php'; echo pp_engine_fingerprint();"));
$pass('S2', 'source engine diubah (fingerprint engine berubah)', $fp0 !== '' && $fp0 !== $fp1, "$fp0 -> $fp1");

/* 3. Daftar Report tetap ada */
$L2 = http("$B/run.php?mode=store_list"); $ids1 = array_column($recs, 'record_id'); $ids2 = array_column((array)$L2['records'], 'record_id'); sort($ids1); sort($ids2);
$allOk = !in_array(false, array_column((array)$L2['records'], 'checksum_ok'), true);
$pass('S3', 'daftar Report tetap ada setelah revisi source (record & checksum sama)', $ids1 === $ids2 && $allOk, ['n' => count($ids2), 'checksum_ok' => $allOk]);

/* 4. Buka record lama */
$ld = http("$B/run.php?mode=store_load&id=" . urlencode($planRec['record_id'])); $rec = $ld['record'] ?? [];
$same = json_encode($rec['input_payload'] ?? null) === json_encode(json_decode(json_encode($planIn), true));
$pass('S4', 'record lama dibuka: input_payload identik dengan yang disimpan, checksum valid', $same && !empty($rec['integrity']['checksum_ok']), ['checksum_ok' => $rec['integrity']['checksum_ok'] ?? null, 'engine_at_save' => $rec['engine_version_at_save'] ?? null]);

/* 5. Jalankan ulang dengan engine terbaru */
$run = $rec['input_payload']; $run['_context'] = 'plan'; $run['_request_id'] = 'plan-store-' . substr(md5(microtime()), 0, 6); $run['_state_revision'] = 1;
$r = http("$B/run.php?mode=run", $run, 120); $job = $r['async_job'] ?? null; $final = null;
if (is_array($job) && !empty($job['job_id'])) { $mh = curl_init("$B/run.php?mode=job_exec&job=" . urlencode($job['job_id']) . '&token=' . urlencode($job['exec_token'] ?? '')); curl_setopt_array($mh, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 1]); @curl_exec($mh);
    $t0 = microtime(true); while (microtime(true) - $t0 < 400) { $p = http("$B/run.php?mode=job_poll&job=" . urlencode($job['job_id']), null, 10); if (($p['job']['status'] ?? '') === 'DONE' && !empty($p['result'])) { $final = $p['result']['output']; break; } if (in_array($p['job']['status'] ?? '', ['FAILED', 'CANCELLED'], true)) break; usleep(300000); } }
$fpRun = $final['info']['V12 Reuse Certificate']['engine_fingerprint'] ?? null;
$pass('S5', 'record lama dijalankan dengan engine TERBARU (fingerprint hasil = fingerprint source revisi), 48 row', is_array($final) && count((array)($final['data'] ?? [])) === 48 && $fpRun === $fp1,
    ['engine_fingerprint_result' => $fpRun, 'engine_now' => $fp1, 'engine_at_save' => $rec['engine_version_at_save'] ?? null, 'cp' => $final['info']['Cost Production (USD/MWh)'] ?? null]);

/* 6. Data asli tidak berubah tanpa Save eksplisit */
clearstatcache(); $shaAfter = hash_file('sha256', $fPlan);
$pass('S6', 'record asli tidak berubah setelah dibuka + dijalankan (tanpa Save eksplisit)', $shaBefore === $shaAfter, "$shaBefore = $shaAfter");

/* 7. Tulis gagal -> berkas lama utuh */
$o7 = [];
foreach (['write', 'validate', 'rename'] as $stage) {
    $sha0 = hash_file('sha256', $fPlan);
    $res = cli($R, "\$w=null; \$x = sds_save(['diubah'=>'$stage'], 'plan', '2026-07-09', 'Daily Plan 09 Jul (uji store)', null, [], \$w); echo json_encode(['saved'=>\$x!==null,'why'=>\$w]);", "PP_SDS_FAIL_AT=$stage");
    clearstatcache(); $sha1 = hash_file('sha256', $fPlan); $valid = is_array(json_decode(file_get_contents($fPlan), true)); $tmpLeft = glob("$R/data/saved/records/*.tmp.*") ?: [];
    $o7[$stage] = ['result' => json_decode(trim((string)$res), true), 'intact' => $sha0 === $sha1, 'json_valid' => $valid, 'tmp_left' => count($tmpLeft)];
}
$pass('S7', 'tulis gagal (write/validate/rename) -> Save ditolak, berkas lama utuh & JSON valid, tanpa berkas sementara tersisa',
    !array_filter($o7, function ($x) { return !$x['intact'] || !$x['json_valid'] || $x['tmp_left'] || !empty($x['result']['saved']); }), $o7);

/* 8. Dua Save bersamaan tidak saling menimpa */
$code = "for (\$i=0;\$i<25;\$i++) { sds_save(['writer'=>getenv('W'),'i'=>\$i,'pad'=>str_repeat(getenv('W'),20000)], 'plan', '2026-07-20', 'Bersamaan sama', null); sds_save(['writer'=>getenv('W'),'i'=>\$i], 'plan', '2026-07-2'.getenv('W'), 'Bersamaan beda '.getenv('W'), null); } echo 'ok';";
$f = tempnam('/tmp', 'sdsc'); file_put_contents($f, "<?php chdir('$R'); require '$R/saved_data_store.php'; $code");
$p1 = proc_open("cd $R && W=1 $PHP $f", [1 => ['pipe', 'w']], $a1); $p2 = proc_open("cd $R && W=2 $PHP $f", [1 => ['pipe', 'w']], $a2);
$r1 = stream_get_contents($a1[1]); $r2 = stream_get_contents($a2[1]); proc_close($p1); proc_close($p2); @unlink($f);
$c8 = json_decode(cli($R, "\$L=sds_list(); \$o=[]; foreach(\$L as \$r) if (strpos(\$r['name'],'Bersamaan')===0) { \$x=sds_load(\$r['record_id']); \$o[]=['name'=>\$r['name'],'ok'=>\$x['integrity']['checksum_ok'],'writer'=>\$x['input_payload']['writer']??null,'i'=>\$x['input_payload']['i']??null,'pad_ok'=>!isset(\$x['input_payload']['pad'])||strlen(\$x['input_payload']['pad'])===20000&&trim(\$x['input_payload']['pad'],(string)\$x['input_payload']['writer'])===''];} echo json_encode(\$o);"), true);
$names = array_column((array)$c8, 'name');
$pass('S8', 'dua Save bersamaan (2 proses x 25): record berbeda keduanya ada; record sama utuh (satu penulis, tidak tercampur), checksum valid',
    trim($r1) === 'ok' && trim($r2) === 'ok' && in_array('Bersamaan beda 1', $names, true) && in_array('Bersamaan beda 2', $names, true) && in_array('Bersamaan sama', $names, true)
    && !array_filter((array)$c8, function ($x) { return !$x['ok'] || !$x['pad_ok']; }), $c8);

/* tambahan: hapus berkonfirmasi, metadata, migrasi skema v1, pemulihan berkas kerja */
$d0 = http("$B/run.php?mode=store_delete&id=" . urlencode($planRec['record_id'])); $still = is_file($fPlan);
$m = http("$B/run.php?mode=store_meta", ['id' => $planRec['record_id'], 'meta' => ['catatan' => 'diperiksa']]); clearstatcache(); $l2 = http("$B/run.php?mode=store_load&id=" . urlencode($planRec['record_id']));
$pass('S9', 'hapus tanpa konfirmasi ditolak; metadata diperbarui tanpa mengubah checksum isi', ($d0['ok'] ?? true) === false && $still && ($l2['record']['meta']['catatan'] ?? '') === 'diperiksa' && !empty($l2['record']['integrity']['checksum_ok']), ['delete' => $d0, 'meta' => $m]);
$d1 = http("$B/run.php?mode=store_delete&id=" . urlencode($planRec['record_id']) . '&confirm=' . urlencode($planRec['record_id'])); clearstatcache();
$bk = glob("$R/data/backups/deleted/*") ?: [];
$pass('S10', 'hapus dengan konfirmasi: record dipindah ke backups/deleted (dapat dipulihkan)', !empty($d1['ok']) && !is_file($fPlan) && count($bk) >= 1, ['delete' => $d1, 'backup' => array_map('basename', $bk)]);
file_put_contents("$R/data/saved/records/rec_legacyv1.json", json_encode(['record_id' => 'rec_legacyv1', 'plan_type' => 'Planning', 'plan_date' => '2025-01-01', 'name' => 'lama', 'saved_at' => '2025-01-01T00:00:00', 'snapshot' => ['input' => ['data3' => ['modeling' => ['x' => 1]]]]]));
$lv = json_decode(cli($R, "echo json_encode(sds_load('rec_legacyv1'));"), true);
$pass('S11', 'migrasi skema: record v1 lama dibuka sebagai v2 (input_payload dari snapshot), checksum dihitung', ($lv['schema_version'] ?? 0) === 2 && ($lv['migrated_from'] ?? 0) === 1 && ($lv['input_payload']['data3']['modeling']['x'] ?? 0) === 1, ['schema' => $lv['schema_version'] ?? null, 'migrated_from' => $lv['migrated_from'] ?? null]);
@unlink("$R/input_data.json"); $html = (string)@file_get_contents("$B/index.php"); clearstatcache(); $rest = is_file("$R/input_data.json") && is_array(json_decode(file_get_contents("$R/input_data.json"), true));
$pass('S12', 'input_data.json hilang (mis. folder aplikasi diganti) -> dipulihkan dari cermin data/saved/state saat halaman dibuka', $rest && strpos($html, 'input_data.json not found') === false, ['restored' => $rest]);
$I = http("$B/run.php?mode=store_integrity");
$pass('S13', 'pemeriksaan integritas penyimpanan', ($I['status'] ?? '') === 'PASS', $I);
$st = proc_get_status($srv); exec('kill ' . (int)$st['pid']); proc_close($srv);
$n = count(array_filter($T, function ($x) { return $x[2]; }));
$md = "# saved_data_store.php — uji penyimpanan\n\nAkar uji: salinan source di direktori sementara, server PHP 7.4 nyata (HTTP), data di <akar>/data.\n\n| id | uji | hasil | bukti |\n|---|---|---|---|\n";
foreach ($T as [$id, $nm, $ok, $ev]) $md .= "| $id | $nm | " . ($ok ? 'LULUS' : 'GAGAL') . ' | ' . str_replace('|', '/', substr(is_string($ev) ? $ev : json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 600)) . " |\n";
$md .= "\n$n/" . count($T) . " LULUS\n"; file_put_contents($OUT, $md); echo "$n/" . count($T) . " PASS\n";
