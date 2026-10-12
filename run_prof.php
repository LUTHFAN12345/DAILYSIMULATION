<?php
/* PROFILER RUNTIME SEMENTARA (debug Windows/XAMPP). Bukan bagian engine; tidak mengubah perhitungan.
 *
 * Saat profil aktif (index.php: PP_RTP_ENABLED), browser mengirim SEMUA request run.php dari satu klik Run
 * (mode=run, job_exec owner, job_help pembantu, job_poll, ...) ke file ini. File ini menjalankan SALINAN run.php,
 * worker02.php dan worker_functions.php (di .rtprof/<versi>/) yang identik kecuali:
 *   - declare(ticks) -> sampler per fungsi (self, cumulative, call-site) setiap ~5 ms wall;
 *   - satu penghitung panggilan di awal setiap fungsi bernama (call count);
 *   - hash argumen untuk fungsi kunci (duplikat konteks: core, proof, supplier search, STG);
 *   - pembungkus I/O & serialisasi (json/file/lock/stat/sleep): jumlah, byte, waktu, pemanggil.
 * Setiap request menulis satu fragmen jobs/<job-id>/rtprof/*.json saat selesai. Browser mengirim timing klik ->
 * request -> polling -> renderResult -> 48 row, lalu fragmen digabung menjadi jobs/<job-id>/runtime_profile.json.
 * File asli tidak disentuh; hapus file ini + flag PP_RTP_ENABLED untuk menonaktifkan. */

const PP_RTP_VER = 'rtp-1';
const PP_RTP_IV = 0.005;
const PP_RTP_IO = ['json_encode', 'json_decode', 'serialize', 'unserialize', 'file_get_contents', 'file_put_contents', 'fopen', 'fclose',
    'flock', 'fwrite', 'fread', 'rename', 'unlink', 'copy', 'glob', 'scandir', 'is_file', 'file_exists', 'is_dir', 'filemtime', 'filesize',
    'clearstatcache', 'touch', 'mkdir', 'md5_file', 'usleep', 'time_nanosleep', 'sleep'];
const PP_RTP_ARGS = ['pp_run_simulation' => 1, 'pp_run_simulation_pipeline' => 1, 'pp_actual_gas_compensation_raw' => 1, 'pp_v8_priority_review' => 1,
    'pp_v8_eval_all' => 1, 'pp_v15_merit_proof' => 1, 'pp_v1518_startup_proof' => 1, 'pp_v1517_proof_binding' => 1, 'pp_decommit_pass' => 1,
    'pp_v15_fast_priority_fix' => 1, 'pp_v4_task_exec' => 1, 'pp_probe_run_candidate' => 1, 'pp_v1517_candidate_hash' => 31,
    'pp_tl_supplier_target' => 1, 'pp_shape_final_dispatch' => 7, 'pp_recompute_stgs' => 97, 'pp_rows_signature' => 31, 'pp_fuel_sum_g19' => 31];

function pp_rtp_t(): float { return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true); }

/* Salinan berinstrumen; dibangun sekali per versi sumber (atomik, aman untuk request bersamaan). */
function pp_rtp_build(string $app): string {
    $src = ['run.php', 'worker02.php', 'worker_functions.php']; $k = PP_RTP_VER . filesize(__FILE__);
    foreach ($src as $f) $k .= '|' . filemtime("$app/$f") . ':' . filesize("$app/$f");
    $dir = $app . DIRECTORY_SEPARATOR . '.rtprof' . DIRECTORY_SEPARATOR . substr(md5($k), 0, 12);
    if (is_file($dir . DIRECTORY_SEPARATOR . 'ok')) return $dir;
    @mkdir($dir, 0777, true);
    foreach ($src as $f) {
        $code = pp_rtp_instrument((string)file_get_contents("$app/$f"), $app, $dir, $f);
        $tmp = $dir . DIRECTORY_SEPARATOR . $f . '.' . getmypid() . mt_rand() . '.tmp';
        file_put_contents($tmp, $code); @rename($tmp, $dir . DIRECTORY_SEPARATOR . $f);
    }
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'ok', $k);
    return $dir;
}
/* Tanpa ekstensi tokenizer: string/komentar dimasker (panjang sama) lalu pola dicari hanya pada kode. */
function pp_rtp_mask(string $c): string {
    $n = strlen($c); $m = $c; $i = 0;
    $blank = function (int $a, int $b) use (&$m, $c) { for ($k = $a; $k < $b; $k++) if ($c[$k] !== "\n") $m[$k] = ' '; };
    while ($i < $n) {
        $ch = $c[$i]; $nx = $i + 1 < $n ? $c[$i + 1] : '';
        if ($ch === "'" || $ch === '"') { $j = $i + 1; while ($j < $n && $c[$j] !== $ch) { if ($c[$j] === '\\') $j++; $j++; } $blank($i + 1, min($j, $n)); $i = $j + 1; continue; }
        if (($ch === '/' && $nx === '/') || ($ch === '#' && $nx !== '[')) { $j = strpos($c, "\n", $i); if ($j === false) $j = $n; $blank($i, $j); $i = $j; continue; }
        if ($ch === '/' && $nx === '*') { $j = strpos($c, '*/', $i + 2); $j = $j === false ? $n : $j + 2; $blank($i, $j); $i = $j; continue; }
        $i++;
    }
    return $m;
}
function pp_rtp_instrument(string $code, string $app, string $dir, string $file): string {
    $m = pp_rtp_mask($code); $ed = [];   // [pos, len, text]
    $p = strpos($m, '<?php'); if ($p !== false) $ed[] = [$p + 5, 0, "\ndeclare(ticks=25);"];
    if (preg_match_all('/\bfunction\s+&?\s*([A-Za-z_]\w*)\s*\(/', $m, $mm, PREG_OFFSET_CAPTURE)) foreach ($mm[0] as $k => $h) {
        $pos = $h[1]; $pre = rtrim(substr($m, max(0, $pos - 3), 3)); if (substr($pre, -2) === '->' || substr($pre, -2) === '::') continue;
        $name = $mm[1][$k][0]; $q = $pos + strlen($h[0]); $d = 1;
        for (; $q < strlen($m) && $d > 0; $q++) { if ($m[$q] === '(') $d++; elseif ($m[$q] === ')') $d--; }
        $rest = substr($m, $q, 200); if (!preg_match('/^\s*(?::\s*\??[\w\\\\|]+\s*)?([{;])/', $rest, $r, PREG_OFFSET_CAPTURE) || $r[1][0] !== '{') continue;
        $s = "isset(\$GLOBALS['__rtpc']['$name'])?++\$GLOBALS['__rtpc']['$name']:(\$GLOBALS['__rtpc']['$name']=1);";
        if (isset(PP_RTP_ARGS[$name])) $s .= "\\pp_rtp_arg('$name',func_get_args());";
        $ed[] = [$q + $r[1][1] + 1, 0, $s];
    }
    $io = implode('|', PP_RTP_IO);
    if (preg_match_all('/(?<![\w$\\\\>:])(\\\\?)(' . $io . ')\s*\(/i', $m, $mm, PREG_OFFSET_CAPTURE)) foreach ($mm[2] as $k => $h) {
        $pos = $mm[0][$k][1]; $pre = substr($m, max(0, $pos - 12), min(12, $pos));
        if (preg_match('/(->|::|\bfunction|\bnew)\s*$/', $pre)) continue;
        $ed[] = [$h[1], strlen($h[0]), 'pp_rtp_' . strtolower($h[0])];
    }
    if (preg_match_all('/\b__(DIR|FILE)__\b/', $m, $mm, PREG_OFFSET_CAPTURE)) foreach ($mm[0] as $h)
        $ed[] = [$h[1], strlen($h[0]), $h[0] === '__DIR__' ? var_export($app, true) : var_export($app . DIRECTORY_SEPARATOR . $file, true)];
    usort($ed, fn($a, $b) => $b[0] <=> $a[0]);
    foreach ($ed as $e) $code = substr_replace($code, $e[2], $e[0], $e[1]);
    /* include worker02 / worker_functions -> salinan berinstrumen (file lain: asli) */
    foreach (['worker02.php', 'worker_functions.php'] as $f)
        $code = preg_replace('/((?:require|include)_once\s+)' . preg_quote(var_export($app, true), '/') . "\s*\.\s*'\/" . preg_quote($f, '/') . "'/", '${1}' . str_replace('\\', '\\\\', var_export($dir . DIRECTORY_SEPARATOR . $f, true)), $code);
    return $code;
}

/* ---- pengumpul ---- */
function pp_rtp_init(): void {
    $GLOBALS['__rtp'] = ['t0' => microtime(true), 'p0' => pp_rtp_t(), 'last' => pp_rtp_t(), 'next' => 0.0, 'n' => 0, 'w' => 0.0, 'ovh' => 0.0,
        'self' => [], 'cum' => [], 'site' => [], 'io' => [], 'io_by' => [], 'arg' => [], 'argn' => [], 'ru0' => function_exists('getrusage') ? @getrusage() : []];
    $GLOBALS['__rtpc'] = [];
    register_tick_function('pp_rtp_tick');
}
function pp_rtp_tick(): void {
    $t = pp_rtp_t(); $P = &$GLOBALS['__rtp']; if ($t < $P['next']) return;
    $dt = $t - $P['last']; $P['n']++; $P['w'] += $dt;
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 64);
    $fn = $bt[1]['function'] ?? '{main}'; if (isset($bt[1]['class'])) $fn = $bt[1]['class'] . '::' . $fn;
    if ($fn === '{closure}' && isset($bt[2]['function'])) $fn = '{closure}@' . $bt[2]['function'];
    $P['self'][$fn] = ($P['self'][$fn] ?? 0) + $dt;
    $site = $fn . ' @' . basename((string)($bt[0]['file'] ?? '')) . ':' . ($bt[0]['line'] ?? 0); $P['site'][$site] = ($P['site'][$site] ?? 0) + $dt;
    $seen = []; for ($i = 1, $c = count($bt); $i < $c; $i++) { $f = $bt[$i]['function'] ?? '?'; if (isset($seen[$f])) continue; $seen[$f] = 1; $P['cum'][$f] = ($P['cum'][$f] ?? 0) + $dt; }
    $e = pp_rtp_t(); $P['ovh'] += $e - $t; $P['last'] = $e; $P['next'] = $e + PP_RTP_IV;
}
/* Hash konteks argumen: berapa kali fungsi kunci dipanggil dengan argumen IDENTIK (kandidat cache/memo). */
function pp_rtp_h($a): string { try { return substr(md5(serialize($a)), 0, 12); } catch (Throwable $e) { return 'x' . substr(md5((string)json_encode($a, JSON_PARTIAL_OUTPUT_ON_ERROR)), 0, 11); } }
function pp_rtp_arg(string $name, array $a): void {
    $t = pp_rtp_t(); $P = &$GLOBALS['__rtp']; $k = PP_RTP_ARGS[$name] ?? 1;
    $P['argn'][$name] = ($P['argn'][$name] ?? 0) + 1;
    if ($k > 1 && $P['argn'][$name] % $k !== 1) { $P['ovh'] += pp_rtp_t() - $t; return; }
    if ($name === 'pp_run_simulation_pipeline' || $name === 'pp_run_simulation') {
        $m = (array)($a[0]['data3']['modeling'] ?? []); unset($m['time_budget_seconds'], $m['time_budget_step_seconds'], $m['time_budget_max_seconds']);
        $h = pp_rtp_h($a); $cm = pp_rtp_h($m);
        $P['arg'][$name]['commitment'][$cm] = ($P['arg'][$name]['commitment'][$cm] ?? 0) + 1;
    } else $h = pp_rtp_h($a);
    $P['arg'][$name]['h'][$h] = ($P['arg'][$name]['h'][$h] ?? 0) + 1;
    $P['ovh'] += pp_rtp_t() - $t;
}
function pp_rtp_iorec(string $fn, float $t, int $bytes): void {
    $P = &$GLOBALS['__rtp']; $e = pp_rtp_t(); $dt = $e - $t;
    $r = &$P['io'][$fn]; $r['n'] = ($r['n'] ?? 0) + 1; $r['s'] = ($r['s'] ?? 0) + $dt; $r['bytes'] = ($r['bytes'] ?? 0) + $bytes; $r['max_s'] = max($r['max_s'] ?? 0, $dt);
    $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3); $by = $fn . ' <- ' . ($bt[2]['function'] ?? '{main}');
    $q = &$P['io_by'][$by]; $q['n'] = ($q['n'] ?? 0) + 1; $q['s'] = ($q['s'] ?? 0) + $dt; $q['bytes'] = ($q['bytes'] ?? 0) + $bytes;
    $P['ovh'] += pp_rtp_t() - $e;
}
function pp_rtp_flock($h, int $op, &$wb = null) { $t = pp_rtp_t(); $r = \flock($h, $op, $wb); pp_rtp_iorec(($op & LOCK_NB) ? 'flock_nb' : 'flock', $t, 0); return $r; }
foreach (PP_RTP_IO as $__f) {
    if ($__f === 'flock') continue;
    $__b = in_array($__f, ['json_encode', 'serialize', 'file_get_contents', 'fread'], true) ? 'is_string($r)?strlen($r):0'
        : (in_array($__f, ['json_decode', 'unserialize'], true) ? '(isset($a[0])&&is_string($a[0]))?strlen($a[0]):0'
        : ($__f === 'file_put_contents' || $__f === 'fwrite' ? '(isset($a[1])&&is_string($a[1]))?strlen($a[1]):0' : '0'));
    eval("function pp_rtp_$__f(...\$a){\$t=pp_rtp_t();\$r=\\$__f(...\$a);pp_rtp_iorec('$__f',\$t,$__b);return \$r;}");
}
unset($__f, $__b);

function pp_rtp_top(array $a, int $n, float $tot): array {
    arsort($a); $r = []; foreach (array_slice($a, 0, $n, true) as $k => $v) $r[] = ['name' => $k, 's' => round($v, 3), 'pct' => round(100 * $v / max(1e-9, $tot), 1)]; return $r;
}
/* Fragmen satu request -> jobs/<job>/rtprof/ (atau jobs/_rtprof_req/ bila job belum diketahui). */
function pp_rtp_flush(string $app): void {
    $P = $GLOBALS['__rtp'] ?? null; if (!is_array($P)) return;
    $t1 = microtime(true); $mode = (string)($_GET['mode'] ?? 'run');
    $job = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
    if ($job === '') foreach (['__pp_async_early_admission', 'ppSafetyAdm'] as $g) if (!empty($GLOBALS[$g]['job_id'])) { $job = (string)$GLOBALS[$g]['job_id']; break; }
    $rid = (string)($_GET['rid'] ?? '');
    if ($rid === '' && $mode === 'run') { $raw = (string)@file_get_contents('php://input'); if (preg_match('/"_request_id":"([^"]+)"/', $raw, $m)) $rid = $m[1]; }
    $role = $mode === 'job_exec' ? 'owner' : ($mode === 'job_help' ? 'helper' : $mode);
    $ru1 = function_exists('getrusage') ? @getrusage() : []; $ru0 = $P['ru0'];
    $cpu = isset($ru1['ru_utime.tv_sec'], $ru0['ru_utime.tv_sec']) ? round(($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e6
        + ($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec']) / 1e6, 3) : null;
    $calls = $GLOBALS['__rtpc'] ?? []; arsort($calls);
    $io = $P['io']; uasort($io, fn($a, $b) => $b['s'] <=> $a['s']); foreach ($io as &$x) { $x['s'] = round($x['s'], 4); $x['max_s'] = round($x['max_s'], 4); } unset($x);
    $ioBy = $P['io_by']; uasort($ioBy, fn($a, $b) => $b['s'] <=> $a['s']); $ioBy = array_slice($ioBy, 0, 40, true); foreach ($ioBy as &$x) $x['s'] = round($x['s'], 4); unset($x);
    $dup = []; foreach ($P['arg'] as $fn => $d) { $h = $d['h'] ?? []; $cnt = array_sum($h);
        $dup[$fn] = ['calls' => $P['argn'][$fn] ?? $cnt, 'hashed' => $cnt, 'sample_every' => PP_RTP_ARGS[$fn] ?? 1, 'unique_ctx' => count($h), 'duplicate_ctx' => $cnt - count($h),
            'max_same_ctx' => $h ? max($h) : 0] + (isset($d['commitment']) ? ['unique_commitment' => count($d['commitment']), 'commitment_hashes' => array_slice($d['commitment'], 0, 60, true), 'ctx_hashes' => array_slice($h, 0, 60, true)] : []); }
    $wall = $t1 - $P['t0'];
    $frag = ['schema' => 'rtp-fragment-1', 'wid' => function_exists('pp_v1518_worker_id') ? pp_v1518_worker_id() : null, 'pid' => getmypid(),
        'thread_note' => PHP_ZTS ? 'ZTS: semua request thread satu PID; wid membedakan request' : null,
        'mode' => $mode, 'role' => $role, 'slot' => $_GET['slot'] ?? null, 'job' => $job, 'rid' => $rid, 't_start' => $P['t0'], 't_end' => $t1, 'wall_s' => round($wall, 3),
        'cpu_s' => $cpu, 'cpu_note' => PHP_OS_FAMILY === 'Windows' ? 'getrusage di Windows = seluruh proses (semua thread Apache)' : 'proses',
        'http_status' => function_exists('http_response_code') ? http_response_code() : null, 'mem_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'samples' => $P['n'], 'sampled_s' => round($P['w'], 3), 'profiler_overhead_s' => round($P['ovh'], 3),
        'top_self' => pp_rtp_top($P['self'], 50, $P['w']), 'top_cumulative' => pp_rtp_top($P['cum'], 70, $P['w']), 'top_call_sites' => pp_rtp_top($P['site'], 60, $P['w']),
        'call_counts' => array_slice($calls, 0, 120, true), 'call_count_total' => array_sum($calls), 'io' => $io, 'io_by_caller' => $ioBy, 'arg_context' => $dup];
    $d = $app . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . ($job !== '' ? $job . DIRECTORY_SEPARATOR . 'rtprof' : '_rtprof_req');
    @mkdir($d, 0777, true);
    @file_put_contents($d . DIRECTORY_SEPARATOR . sprintf('%.4f', $P['t0']) . '_' . $role . '_' . $frag['wid'] . '.json', json_encode($frag, JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/* Gabungan semua fragmen + timing browser + bukti job -> jobs/<job>/runtime_profile.json. Dipanggil dari run.php. */
function pp_rtp_merge(string $app, string $job): ?array {
    $jd = $app . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $job; if (!is_dir($jd . DIRECTORY_SEPARATOR . 'rtprof')) return null;
    $fr = []; foreach (glob($jd . DIRECTORY_SEPARATOR . 'rtprof' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) { $x = json_decode((string)file_get_contents($f), true); if (is_array($x)) $fr[] = $x; }
    $cl = json_decode((string)@file_get_contents($jd . DIRECTORY_SEPARATOR . 'rtprof_client.json'), true);
    $rids = []; if (is_array($cl) && !empty($cl['rid'])) $rids[$cl['rid']] = 1; foreach ($fr as $x) if (!empty($x['rid'])) $rids[$x['rid']] = 1;
    foreach (glob($app . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . '_rtprof_req' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        $x = json_decode((string)file_get_contents($f), true); if (is_array($x) && !empty($x['rid']) && isset($rids[$x['rid']])) $fr[] = $x; }
    /* hanya request milik Run (klik) terakhir: sejak klik browser */
    if (is_array($cl) && !empty($cl['epoch_click'])) { $tc = (float)$cl['epoch_click'] / 1000 - 1.0; $fr = array_values(array_filter($fr, fn($x) => (float)$x['t_start'] >= $tc)); }
    usort($fr, fn($a, $b) => $a['t_start'] <=> $b['t_start']);
    /* agregat per fungsi atas semua request */
    $agg = ['self' => [], 'cum' => [], 'calls' => [], 'io' => []]; $tot = 0.0; $ovh = 0.0; $reqs = []; $iv = [];
    foreach ($fr as $x) {
        $tot += $x['sampled_s']; $ovh += $x['profiler_overhead_s'];
        foreach ($x['top_self'] as $r) $agg['self'][$r['name']] = ($agg['self'][$r['name']] ?? 0) + $r['s'];
        foreach ($x['top_cumulative'] as $r) $agg['cum'][$r['name']] = ($agg['cum'][$r['name']] ?? 0) + $r['s'];
        foreach ($x['call_counts'] as $k => $v) $agg['calls'][$k] = ($agg['calls'][$k] ?? 0) + $v;
        foreach ($x['io'] as $k => $v) { $agg['io'][$k]['n'] = ($agg['io'][$k]['n'] ?? 0) + $v['n']; $agg['io'][$k]['s'] = round(($agg['io'][$k]['s'] ?? 0) + $v['s'], 4); $agg['io'][$k]['bytes'] = ($agg['io'][$k]['bytes'] ?? 0) + $v['bytes']; }
        $reqs[] = ['wid' => $x['wid'], 'pid' => $x['pid'], 'role' => $x['role'], 'mode' => $x['mode'], 'slot' => $x['slot'], 't_start' => round($x['t_start'], 3), 't_end' => round($x['t_end'], 3),
            'wall_s' => $x['wall_s'], 'cpu_s' => $x['cpu_s'], 'samples' => $x['samples'], 'overhead_s' => $x['profiler_overhead_s']];
        if (in_array($x['role'], ['owner', 'helper'], true)) $iv[] = [$x['t_start'], $x['t_end'], $x['wid'], $x['role']];
    }
    /* overlap nyata antar-request (owner/helper) dan antar-tugas (v4t_*.done) */
    $ov = []; for ($i = 0; $i < count($iv); $i++) for ($j = $i + 1; $j < count($iv); $j++) { $o = min($iv[$i][1], $iv[$j][1]) - max($iv[$i][0], $iv[$j][0]);
        if ($o > 0 && $iv[$i][2] !== $iv[$j][2]) $ov[] = ['a' => $iv[$i][3] . ':' . $iv[$i][2], 'b' => $iv[$j][3] . ':' . $iv[$j][2], 'overlap_s' => round($o, 3)]; }
    $tasks = []; foreach (glob($jd . DIRECTORY_SEPARATOR . 'v4t_*.done') ?: [] as $f) { $d = json_decode((string)@file_get_contents($f), true); if (!is_array($d)) continue;
        $tasks[] = ['key' => substr(basename($f, '.done'), 4), 'wid' => $d['wid'] ?? null, 'role' => $d['role'] ?? null, 'type' => $d['type'] ?? null, 'claim' => $d['tc'] ?? ($d['t0'] ?? null),
            'start' => $d['t0'] ?? null, 'finish' => $d['t1'] ?? null, 'dur_s' => isset($d['t0'], $d['t1']) ? round($d['t1'] - $d['t0'], 3) : null, 'off' => $d['off'] ?? null, 'adj' => $d['adj'] ?? null]; }
    usort($tasks, fn($a, $b) => ($a['start'] ?? 0) <=> ($b['start'] ?? 0));
    $tov = 0; for ($i = 0; $i < count($tasks); $i++) for ($j = $i + 1; $j < count($tasks); $j++) if ($tasks[$i]['wid'] !== $tasks[$j]['wid'] && min($tasks[$i]['finish'], $tasks[$j]['finish']) > max($tasks[$i]['start'], $tasks[$j]['start'])) $tov++;
    $res = json_decode((string)@file_get_contents($jd . DIRECTORY_SEPARATOR . 'result.json'), true); $o = $res['output'] ?? $res; $info = (array)($o['info'] ?? []);
    $U = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'];
    $fp = isset($o['data']) ? substr(md5(json_encode(array_map(fn($r) => array_map(fn($u) => $r[$u] ?? null, $U), (array)$o['data']))), 0, 16) : null;
    $job = json_decode((string)@file_get_contents($jd . DIRECTORY_SEPARATOR . 'job.json'), true);
    $owners = array_values(array_filter($fr, fn($x) => $x['role'] === 'owner' && ($x['samples'] > 0 || $x['wall_s'] > 1.0))); $nOwnerEngine = count($owners);
    $pick = function (string $k) use ($info) { return $info[$k] ?? null; };
    $out = ['schema' => 'runtime-profile-1', 'generated_at' => date('c'), 'job_id' => basename($jd), 'run_id' => array_keys($rids),
        'env' => ['os' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'zts' => (bool)PHP_ZTS, 'sapi' => PHP_SAPI, 'server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'opcache' => function_exists('opcache_get_status') ? (bool)(@opcache_get_status(false)['opcache_enabled'] ?? false) : false, 'cores_env' => getenv('NUMBER_OF_PROCESSORS') ?: null],
        'summary' => ['engine_wall_s' => $owners ? $owners[0]['wall_s'] : null, 'owner_cpu_s' => $owners ? $owners[0]['cpu_s'] : null,
            'browser' => is_array($cl) ? ($cl['summary'] ?? null) : null, 'requests' => count($fr), 'owner_requests' => $nOwnerEngine,
            'second_pipeline' => $nOwnerEngine > 1 ? 'YES (' . $nOwnerEngine . ' owner request menjalankan engine)' : 'NO', 'cache_hit' => $nOwnerEngine === 0,
            'distinct_worker_ids' => count(array_unique(array_column($iv, 2))), 'request_overlaps' => count($ov), 'task_overlaps_between_workers' => $tov,
            'profiler_overhead_s_total' => round($ovh, 2), 'sampled_s_total' => round($tot, 2),
            'cp' => $pick('Cost Production (USD/MWh)'), 'hr' => $pick('JBBK MM Heat Rate (BTU/kWh)'), 'fingerprint' => $fp, 'rows' => count((array)($o['data'] ?? []))],
        'requests' => $reqs, 'request_overlap' => $ov, 'tasks' => $tasks,
        'engine' => ['phase_timeline' => $info['Run Status']['phase_timeline'] ?? null, 'core_simulations' => $info['Run Status']['core_simulations'] ?? null,
            'candidates' => $pick('V11 Candidate Counters'), 'v8' => isset($info['V8 Priority Review']) ? array_intersect_key((array)$info['V8 Priority Review'], array_flip(['wall_s', 'status', 'evaluated', 'candidates', 'screened'])) : null,
            'merit_c1_c4_stg' => $info['Merit Proof C1-C4 STG']['status'] ?? null, 'proof_binding' => $pick('Proof Binding'), 'startup' => $info['Startup Timing Proof']['units'] ?? null,
            'runtime_platform' => $pick('Runtime Platform Diagnostics'), 'gate' => $o['simulation_acceptance_review']['status'] ?? null],
        'functions' => ['top_self' => pp_rtp_top($agg['self'], 50, $tot), 'top_cumulative' => pp_rtp_top($agg['cum'], 70, $tot), 'call_counts' => array_slice((function ($c) { arsort($c); return $c; })($agg['calls']), 0, 120, true)],
        'io_total' => (function ($io) { uasort($io, fn($a, $b) => $b['s'] <=> $a['s']); return $io; })($agg['io']),
        'per_request' => $fr, 'browser' => $cl];
    @file_put_contents($jd . DIRECTORY_SEPARATOR . 'runtime_profile.json', json_encode($out, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES));
    return $out;
}

if (defined('PP_RTP_LIB_ONLY')) return;
/* ---- entri request browser ---- */
$__app = __DIR__;
$__dir = pp_rtp_build($__app);
pp_rtp_init();
register_shutdown_function(function () use ($__app) { pp_rtp_flush($__app); });
require $__dir . DIRECTORY_SEPARATOR . 'run.php';
