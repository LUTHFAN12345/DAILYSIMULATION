<?php
/* php job_dump.php <root> <SC> <out.json> [json-overrides] : satu job economic_review (jalur UI), simpan output lengkap */
ini_set('memory_limit', '3G'); define('PP_LIB_ONLY', true);
$dir = $argv[1]; chdir($dir); require $dir . '/run.php'; 
$in = json_decode(file_get_contents($argv[2]), true); foreach ($in as $kk => $vv) if ($kk[0] === "_") unset($in[$kk]); foreach ((array)json_decode($argv[4] ?? '{}', true) as $k => $v) { if ($v === null) unset($in['data3']['modeling'][$k]); else $in['data3']['modeling'][$k] = $v; }
$in['_context'] = 'plan'; $in['_request_id'] = 'plan-' . substr(md5($argv[2] . ($argv[4] ?? '') . microtime()), 0, 6);
$m = &$in['data3']['modeling']; $m['time_budget_seconds'] = 10.0; $m['time_budget_step_seconds'] = 5.0; $m['time_budget_max_seconds'] = max(15.0, 60.0 - PP_SYNC_CLOSING_RESERVE_S); $m['__core_run_budget'] = 100; unset($m);
$in = pp_normalize_copy($in);
$st = pp_job_start($in, 'economic_review', $in['_request_id'], false); $id = $st['job']['job_id'];
$t = microtime(true); if (!$st['reused'] || ($st['job']['status'] ?? '') !== 'DONE') pp_job_worker_main($id); $w = round(microtime(true) - $t, 2);
$r = json_decode((string)@file_get_contents(pp_job_dir($id) . '/result.json'), true);
$o = $r['output'] ?? []; $i = $o['info'] ?? [];
file_put_contents($argv[3], json_encode(['wall' => $w, 'mode' => $r['mode'] ?? null, 'output' => $o, 'input' => $in]));
echo json_encode(['wall' => $w, 'mode' => $r['mode'] ?? null, 'status' => $o['status'] ?? null, 'final' => pp_final_is_final($o), 'cp' => $i['Cost Production (USD/MWh)'] ?? null]), "\n";
