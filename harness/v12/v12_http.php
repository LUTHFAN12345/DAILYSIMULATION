<?php
/* Uji jalur produksi V3 lewat HTTP, meniru UI Maximum Review:
 *   POST mode=run (_autosave=true) -> picu job_exec -> kolam provisional per job + job_poll.
 * php v3_http.php <root> <port> <out.jsonl> <mode:cold|warm> <SC1,SC2,...> [snapshot_dir]
 *   cold: server baru + jobs dipulihkan dari snapshot SEBELUM setiap skenario
 *   warm: satu server, skenario berurutan (rantai), jobs dipulihkan sekali di awal */
require '/home/claude/t5/v3/scen.php';
$root = $argv[1]; $port = (int)$argv[2]; $outF = $argv[3]; $mode = $argv[4]; $scs = is_file($argv[5]) ? array_values(array_filter(array_map('trim', file($argv[5])))) : explode(',', $argv[5]); $snap = $argv[6] ?? '/home/claude/t5/v3/snap_base';
$B = "http://127.0.0.1:$port";
function restore($root, $snap) {
    exec('rm -rf ' . escapeshellarg($root . '/jobs') . ' && cp -a ' . escapeshellarg($snap) . ' ' . escapeshellarg($root . '/jobs'));
    $fp = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && /usr/local/bin/php74 -r \'define("PP_LIB_ONLY",1); require "run.php"; echo "FP=".pp_engine_fingerprint();\' 2>/dev/null | grep -o "FP=[0-9a-f]*" | cut -c4-'));
    foreach (glob($root . '/jobs/_final/*.json') as $f) { $d = json_decode(file_get_contents($f), true); if (isset($d['v3'])) { $d['v3']['engine'] = $fp; file_put_contents($f, json_encode($d)); } elseif (substr($f, -12) === '.v10lib.json' && is_array($d)) { $d['engine'] = $fp; file_put_contents($f, json_encode($d)); } }
    copy('/home/claude/t5/fixtures/input_actual.json', $root . '/input_data.json');
}
function srv_start($root, $port) {
    $env = ['PHP_CLI_SERVER_WORKERS' => '8'] + array_filter(getenv(), function ($k) { return strpos($k, 'PP_') === 0; }, ARRAY_FILTER_USE_KEY);
    /* Proksi multi-backend (lihat proxy.js): meniru server multi-thread seperti Apache. */
    $p = proc_open('exec node /home/claude/t5/v3/proxy.js ' . $port . ' ' . escapeshellarg($root) . ' 6',
        [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/tmp/claude-0/v3srv_' . $port . '.log', 'a']], $pp, $root, $env);
    usleep(2000000); return $p;
}
function srv_stop($p) { $st = proc_get_status($p); if ($st['running']) { exec('kill ' . (int)$st['pid'] . ' 2>/dev/null'); usleep(800000); } proc_close($p); }
function http($u, $p = null, $to = 60) { $c = curl_init($u); $o = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $to];
    if ($p !== null) { $o[CURLOPT_POST] = 1; $o[CURLOPT_POSTFIELDS] = json_encode($p); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($c, $o); $b = curl_exec($c); curl_close($c); return json_decode((string)$b, true); }
$srv = null;
if ($mode === 'warm') { restore($root, $snap); $srv = srv_start($root, $port); }
$seq = 0;
foreach ($scs as $sc) {
    if ($mode === 'cold') { if ($srv) srv_stop($srv); restore($root, $snap); $srv = srv_start($root, $port); }
    $seq++; $in = strpos($sc, '|') !== false ? [] : (strpos($sc, 'FILE:') === 0 ? (function ($f) { $d = json_decode(file_get_contents($f), true); $d = $d['input'] ?? $d;
        foreach (array_keys($d) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($d[$k]); return $d; })(substr($sc, 5)) : v3_input($sc)); $in['_context'] = 'plan'; $in['_request_id'] = 'plan-' . $seq . '-' . substr(md5(microtime()), 0, 6);
    $in['_state_revision'] = $seq; $in['_autosave'] = true;
    $label = $sc; if (strpos($sc, '|') !== false) { [$label, $sc] = array_map('trim', explode('|', $sc, 2)); $in = v3_input($sc); $in['_context'] = 'plan'; $in['_request_id'] = 'plan-' . $seq . '-' . substr(md5(microtime()), 0, 6); $in['_state_revision'] = $seq; $in['_autosave'] = true; }
    $res = ['sc' => $label, 'mode' => $mode];
    $t0 = microtime(true);
    $r = http("$B/run.php?mode=run", $in, 120);
    $res['t_response_s'] = round(microtime(true) - $t0, 3);
    $res['autosave'] = $r['autosave'] ?? null; $res['orchestration'] = $r['orchestration'] ?? null;
    $saved = json_decode((string)@file_get_contents($root . '/input_data.json'), true);
    $cmp = $in; foreach (array_keys($cmp) as $k) if ($k[0] === '_') unset($cmp[$k]);
    $res['input_saved_equals_payload'] = is_array($saved) && json_encode($saved['data3']['modeling']['actual_pgn_total'] ?? null) === json_encode($cmp['data3']['modeling']['actual_pgn_total'] ?? null)
        && json_encode($saved['data3']['modeling']['gas_quota'] ?? null) === json_encode($cmp['data3']['modeling']['gas_quota'] ?? null)
        && json_encode($saved['data3']['modeling']['manual_fixed_flows'] ?? null) === json_encode($cmp['data3']['modeling']['manual_fixed_flows'] ?? null);
    $job = $r['async_job'] ?? null; $final = null; $tValid = null; $validCp = null; $tDone = null;
    if (is_array($job) && !empty($job['job_id'])) {
        $mh = curl_multi_init(); $cJ = curl_init("$B/run.php?mode=job_exec&job=" . urlencode($job['job_id']) . '&token=' . urlencode($job['exec_token'] ?? ''));
        curl_setopt_array($cJ, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 3600]); curl_multi_add_handle($mh, $cJ); $run = 1;
        $hH = []; for ($k = 1; $k <= (int)($job['helpers'] ?? 0); $k++) { $cH = curl_init("$B/run.php?mode=job_help&job=" . urlencode($job['job_id']) . '&token=' . urlencode($job['exec_token'] ?? '') . '&slot=' . $k);
            curl_setopt_array($cH, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 3600]); curl_multi_add_handle($mh, $cH); $hH[] = $cH; }
        $res['helpers'] = count($hH);
        for ($q = 0; $q < 20; $q++) { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.01); }   // kirim job_exec segera (seperti fetch browser)
        while (true) {
            curl_multi_exec($mh, $run);
            if ($tValid === null) { $p = http("$B/run.php?mode=tl_pool&as=provisional&job=" . urlencode($job['job_id']), null, 5);
                if (!empty($p['ok']) && count((array)($p['output']['data'] ?? [])) === 48) { $tValid = microtime(true) - $t0; $validCp = $p['output']['info']['Cost Production (USD/MWh)'] ?? null; } }
            $p = http("$B/run.php?mode=job_poll&job=" . urlencode($job['job_id']), null, 10);
            $st = $p['job']['status'] ?? '';
            if ($st === 'DONE' && !empty($p['result'])) { $final = $p['result']['output']; $tDone = microtime(true) - $t0; $res['job_mode'] = $p['result']['mode'] ?? null; break; }
            if (in_array($st, ['FAILED', 'CANCELLED'], true)) { $final = ['failed' => $p['job']]; $tDone = microtime(true) - $t0; break; }
            if (microtime(true) - $t0 > 1800) { $res['timeout'] = true; break; }
            usleep(150000);   // V12: interval polling = UI (150 ms)
        }
        $tw = microtime(true); while ($run && microtime(true) - $tw < 30) { curl_multi_exec($mh, $run); usleep(100000); }
        $res['helper_stats'] = []; foreach ($hH as $cH) { $hr = json_decode((string)curl_multi_getcontent($cH), true); $res['helper_stats'][] = is_array($hr) ? ['tasks' => $hr['tasks_done'] ?? null, 'stats' => $hr['stats'] ?? null, 'wall' => $hr['wall_s'] ?? null, 'idle' => $hr['idle_s'] ?? null, 'idle_fam' => $hr['idle_family_s'] ?? null, 'side' => $hr['side_done'] ?? null] : null; }
        curl_multi_close($mh);
    } else { $final = $r; $tDone = microtime(true) - $t0; }
    $jobT = []; if (is_array($job) && !empty($job['job_id'])) { $jj = json_decode((string)@file_get_contents($root . '/jobs/' . $job['job_id'] . '/job.json'), true);
        if (is_array($jj)) { $jobT['job_started_s'] = isset($jj['started_at_ts']) ? round((float)$jj['started_at_ts'] - $t0, 2) : null;
            foreach ((array)($jj['progress'] ?? []) as $pg) if (($pg['step'] ?? '') === 'INKREMENTAL_HASIL_VALID_PERTAMA') $jobT['job_first_valid_s'] = $pg['first_valid_s'] ?? null;
            $jobT['steps'] = array_map(function ($pg) { return [substr((string)($pg['step'] ?? ''), 0, 28), $pg['elapsed_s'] ?? null]; }, (array)($jj['progress'] ?? [])); } }
    $res['job_timing'] = $jobT;
    $f = (array)$final; $i = (array)($f['info'] ?? []);
    if (($sv = getenv('V12_SAVE_OUT')) !== false && $sv !== '') { @mkdir($sv, 0777, true); @file_put_contents($sv . '/' . substr(preg_replace('~[^A-Za-z0-9_.=-]~', '_', $sc), 0, 80) . '_' . substr(md5($sc), 0, 6) . '.json', json_encode(['sc' => $sc, 'output' => $f])); }
    $rs = (array)($i['Run Status'] ?? []); $ir = (array)($i['Incremental Recompute'] ?? []);
    $sar = (array)($f['simulation_acceptance_review'] ?? []);
    $final_ok = ($f['release_gate']['release_allowed'] ?? null) === true;
    if ($final_ok && $tValid === null) { $tValid = $tDone; $validCp = $i['Cost Production (USD/MWh)'] ?? null; }
    $res += ['t_first_valid_s' => $tValid !== null ? round($tValid, 2) : null, 'first_valid_cp' => $validCp,
        't_final_s' => $tDone !== null ? round($tDone, 2) : null, 'rows' => count((array)($f['data'] ?? [])),
        'release' => $f['release_gate']['release_allowed'] ?? null, 'status' => $f['status'] ?? null,
        'computation' => ($res['job_mode'] ?? null) ?: (($ir['applied'] ?? false) === true ? 'INCREMENTAL' : (($rs['mode'] ?? null) ?: 'EXACT')),
        'action_required' => $f['action_required'] ?? null, 'gwc' => $i['Gas Window Conflict']['jenis'] ?? null, 'gwc_method' => $i['Gas Window Conflict']['metode'] ?? null,
        'v7_route' => $i['V7 Delta Route'] ?? null, 'polish' => $i['Unit Priority Polish']['shifts_applied'] ?? null,
        'headroom_priority' => $i['Headroom Priority Audit']['status'] ?? null,
        'headroom_unresolved' => $i['Headroom Priority Audit']['flags_unresolved'] ?? null,
        'v8' => isset($i['V8 Priority Review']) ? ['status' => $i['V8 Priority Review']['status'] ?? null, 'reason' => $i['V8 Priority Review']['reason'] ?? null, 'wall_s' => $i['V8 Priority Review']['wall_s'] ?? null,
            'sim' => $i['V8 Priority Review']['candidates_simulated'] ?? null, 'pre' => $i['V8 Priority Review']['candidates_prescreened'] ?? null,
            'applied' => array_map(function ($a) { return $a['candidate']; }, (array)($i['V8 Priority Review']['applied'] ?? [])), 'cp_before' => $i['V8 Priority Review']['cost_production_before'] ?? null] : null,
        'mm2100' => isset($i['MM2100 Gas Provenance']) ? ($i['MM2100 Gas Provenance']['gas_source'] ?? null) . '/' . ($i['MM2100 Gas Provenance']['status'] ?? null) : null,
        'sup_mode' => $i['PGN Supplier Repair Review']['search_mode'] ?? null,
        'fallback_reason' => ($ir['applied'] ?? null) === false ? ($ir['reason'] ?? null) : null,
        'core_simulations' => $rs['core_simulations'] ?? null, 'core_runs' => $rs['core_runs'] ?? null, 'core_state_reuse' => $rs['core_state_reuse'] ?? null,
        'gcr_candidates' => $i['Global Commitment Review']['candidates_evaluated'] ?? null,
        'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'cost' => $i['Total Cost (USD)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null,
        'sig' => substr(md5(json_encode(array_map(function ($r) { $x = []; foreach ((array)$r as $k => $v) if (preg_match('~^(G\d+|S\d+|GE\d+|GEG\d*|BBLN\d*)$~', (string)$k)) $x[$k] = round((float)$v, 4); ksort($x); return $x; }, (array)($f['data'] ?? [])))), 0, 12),
        'csig' => substr(md5(json_encode(array_map(function ($r) { $x = []; foreach ((array)$r as $k => $v) if (preg_match('~^(G\d+|S\d+|GE\d+|BB\d*)$~', (string)$k)) $x[$k] = ((float)$v > 0.01) ? 1 : 0; ksort($x); return $x; }, (array)($f['data'] ?? [])))), 0, 10),
        'prof' => ['screen' => $i['V10 Screening Summary'] ?? null, 'family' => isset($i['Exact Candidate Space']) ? array_intersect_key((array)$i['Exact Candidate Space'], array_flip(['family_nodes', 'family_nodes_computed', 'family_nodes_reused', 'family_core_evaluations', 'family_valid', 'family_wall_s', 'winner_source'])) : null,
            'counters' => isset($i['V11 Candidate Counters']) ? array_intersect_key((array)$i['V11 Candidate Counters'], array_flip(['candidates_checked', 'candidates_full_run', 'candidates_screened_out', 'candidates_valid'])) : null,
            'dirty_rows' => count((array)($i['Incremental Recompute']['diff']['dirty_rows'] ?? [])), 'stats' => $i['V12 Reuse Certificate']['runtime_stats'] ?? null,
            'review' => isset($i['V8 Priority Review']) ? array_intersect_key((array)$i['V8 Priority Review'], array_flip(['status', 'wall_s', 'rounds', 'candidates_simulated', 'candidates_prescreened'])) : null,
            'merit' => $i['V12 Dispatch Merit Audit']['status'] ?? null, 'llf' => $i['V12 Low Load Fragmentation Outcome']['counts'] ?? null,
            'cp_report' => isset($i['V12 CP Report']) ? array_intersect_key((array)$i['V12 CP Report'], array_flip(['absolute_cp_min', 'winner_cp', 'delta_winner_vs_min_pct', 'winner_heat_rate', 'min_heat_rate_in_band', 'tie_break_reason'])) : null],
        'cnt' => isset($i['V11 Candidate Counters']) ? [$i['V11 Candidate Counters']['candidates_checked'] ?? null, $i['V11 Candidate Counters']['candidates_valid'] ?? null] : null,
        'hard' => $sar['hard_validation']['status'] ?? null, 'econ' => $sar['economic_review']['status'] ?? null,
        'export_48' => $i['PLN Export Compliance'] ?? null, 'residual' => $i['Residual Gas Shortage (BBTUD)'] ?? null,
        'gas_used' => $i['Effective Total Gas (BBTUD)'] ?? ($i['Total Gas Used (BBTUD)'] ?? null), 'gas_quota' => $i['Total Gas Quota (BBTUD)'] ?? null,
        'pgn_used' => $i['PGN Pipe Used (BBTUD)'] ?? null, 'pgn_quota' => $i['PGN Pipe Quota (BBTUD)'] ?? null,
        'headroom' => $sar['hard_validation']['universal_headroom_review']['status'] ?? null,
        'full_horizon' => $sar['hard_validation']['full_horizon_review']['status'] ?? null,
        'violations' => $sar['hard_validation']['violation_count'] ?? null,
        'converged' => $rs['converged'] ?? null, 'blocking' => $f['release_gate']['blocking_reasons'] ?? null,
        'inc' => array_intersect_key($ir, array_flip(['first_valid_s', 'wall_s', 'core_evaluations', 'rows_reused_identical', 'rows_recomputed', 'first_changed_row', 'commitment_unchanged', 'winner', 'candidates_evaluated', 'valid_evaluations', 'far_candidates', 'v4_pool_owner', 'dirty_row_range', 'v4_parallel', 'near_candidates'])),
        'space' => array_intersect_key((array)($i['Exact Candidate Space'] ?? []), array_flip(['family_nodes', 'family_nodes_computed', 'family_nodes_reused', 'family_core_evaluations', 'family_valid', 'registry_valid_candidates', 'far_candidates_excluded', 'winner_node', 'family_complete'])),
        'prepass' => $i['Exact Family Prepass'] ?? null,
        'dirty_rows' => $ir['diff']['dirty_rows'] ?? null, 'diff_kind' => $ir['diff']['kind'] ?? null,
        'winner_source' => $i['Exact Candidate Space']['winner_source'] ?? null,
        'fuel_decision' => !empty($f['shortage_decision'])];
    file_put_contents($outF, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    echo json_encode(['sc' => $label, 'mode' => $mode, 'save_ms' => $res['autosave']['ms'] ?? null, 'resp' => $res['t_response_s'], 'valid' => $res['t_first_valid_s'], 'final' => $res['t_final_s'],
        'job' => $jobT, 'comp' => $res['computation'], 'cp' => $res['cp'], 'release' => $res['release'], 'sims' => $res['core_simulations'], 'fb' => $res['fallback_reason'], 'h' => $res['helpers'] ?? 0]), "\n";
}
if ($srv) srv_stop($srv);
