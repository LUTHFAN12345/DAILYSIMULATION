<?php
/* PROFILER SEMENTARA (debug runtime Windows/XAMPP) — tidak dipakai engine/UI biasa.
 * Jalankan SEKALI di folder aplikasi:
 *     C:\xampp\php\php.exe windows_profile.php [snapshot_atau_payload.json]
 * Tanpa argumen: memakai input_data.json. Hasil: jobs/windows_profile.json (juga bisa diunduh lewat tombol
 * "Download Runtime Profile" = run.php?mode=runtime_profile).
 * Isi: (1) run BIASA tanpa profiler (waktu nyata, fase, CP/fingerprint) di proses PHP terpisah;
 *      (2) run TERPROFIL: sampling per fungsi (self/cumulative wall & jumlah sampel, call-site) lewat tick, file engine
 *          disalin ke jobs/_winprof dengan declare(ticks) sehingga file asli tidak berubah. */
@ini_set('memory_limit', '4G'); @ini_set('max_execution_time', '0');
$app = __DIR__; $args = array_slice($argv ?? [], 1); $plain = in_array('--plain', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--plain'));
$inF = $args[0] ?? ($app . DIRECTORY_SEPARATOR . 'input_data.json');
$p = json_decode((string)file_get_contents($inF), true); if (isset($p['payload']) && is_array($p['payload'])) $p = $p['payload'];
if (!is_array($p)) { fwrite(STDERR, "payload tidak terbaca: $inF\n"); exit(1); }
$p['_fast_default'] = true; $p['_maximum_review'] = false;
$src = $app;
if (!$plain) {
    $src = $app . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . '_winprof'; @mkdir($src, 0777, true);
    foreach (['run.php', 'worker02.php', 'worker_functions.php'] as $f) {
        $s = (string)file_get_contents($app . DIRECTORY_SEPARATOR . $f);
        file_put_contents($src . DIRECTORY_SEPARATOR . $f, preg_replace('/^<\?php\s*/', "<?php declare(ticks=25);\n", $s, 1)); }
    copy($app . DIRECTORY_SEPARATOR . 'saved_data_store.php', $src . DIRECTORY_SEPARATOR . 'saved_data_store.php');
    $GLOBALS['__wp'] = ['next' => 0.0, 'last' => microtime(true), 'iv' => 0.005, 'self' => [], 'cum' => [], 'site' => [], 'n' => 0, 'w' => 0.0];
    register_tick_function(function () {
        $t = microtime(true); $P = &$GLOBALS['__wp']; if ($t < $P['next']) return;
        $dt = $t - $P['last']; $P['last'] = $t; $P['next'] = $t + $P['iv']; $P['n']++; $P['w'] += $dt;
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48); $ln = $bt[0]['line'] ?? 0; $fl = basename((string)($bt[0]['file'] ?? ''));
        $fn = $bt[1]['function'] ?? '{main}';
        $P['self'][$fn] = ($P['self'][$fn] ?? 0) + $dt; $site = $fn . ' @' . $fl . ':' . $ln; $P['site'][$site] = ($P['site'][$site] ?? 0) + $dt;
        $seen = []; for ($i = 1; $i < count($bt); $i++) { $f = $bt[$i]['function'] ?? '?'; if (isset($seen[$f])) continue; $seen[$f] = 1; $P['cum'][$f] = ($P['cum'][$f] ?? 0) + $dt; }
    });
}
define('PP_LIB_ONLY', 1);
$GLOBALS['__pp_async_worker'] = true; $GLOBALS['__pp_async_worker_ceiling'] = 900.0;
require $src . DIRECTORY_SEPARATOR . 'run.php';
$ru0 = function_exists('getrusage') ? getrusage() : []; $t0 = microtime(true);
$o = pp_job_run_economic_review('economic_review-winprof' . getmypid(), $p); $o = $o['output'] ?? $o;
$wall = microtime(true) - $t0; $ru1 = function_exists('getrusage') ? getrusage() : [];
$cpu = isset($ru1['ru_utime.tv_sec']) ? ($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec']) / 1e6 : null;
$i = (array)($o['info'] ?? []); $U = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'];
$fp = substr(md5(json_encode(array_map(fn($r) => array_map(fn($u) => $r[$u] ?? null, $U), (array)($o['data'] ?? [])))), 0, 16);
$res = ['wall_s' => round($wall, 2), 'cpu_user_s' => $cpu !== null ? round($cpu, 2) : null, 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null,
    'fingerprint' => $fp, 'rows' => count((array)($o['data'] ?? [])), 'phase_timeline' => $i['Run Status']['phase_timeline'] ?? null, 'core_simulations' => $i['Run Status']['core_simulations'] ?? null,
    'candidates' => $i['V11 Candidate Counters'] ?? null, 'startup' => $i['Startup Timing Proof']['units'] ?? null, 'v8_wall_s' => $i['V8 Priority Review']['wall_s'] ?? null,
    'merit' => $i['Merit Proof C1-C4 STG']['status'] ?? null, 'gate' => $o['simulation_acceptance_review']['status'] ?? null];
if ($plain) { echo json_encode($res); exit(0); }
$P = $GLOBALS['__wp']; arsort($P['self']); arsort($P['cum']); arsort($P['site']);
$top = function (array $a, int $n) use ($P) { $r = []; foreach (array_slice($a, 0, $n, true) as $k => $v) $r[] = ['name' => $k, 's' => round($v, 3), 'pct' => round(100 * $v / max(1e-9, $P['w']), 1)]; return $r; };
/* run biasa (tanpa tick) di proses PHP terpisah: waktu nyata tanpa overhead profiler */
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --plain ' . escapeshellarg($inF);
$plainOut = @shell_exec($cmd); $plainRes = json_decode((string)$plainOut, true);
$env = ['os' => PHP_OS_FAMILY, 'php' => PHP_VERSION, 'zts' => (bool)PHP_ZTS, 'sapi' => PHP_SAPI, 'php_binary' => PHP_BINARY,
    'opcache_cli' => function_exists('opcache_get_status') ? (bool)(@opcache_get_status(false)['opcache_enabled'] ?? false) : false,
    'cores' => function_exists('pp_v4_cores') ? pp_v4_cores() : null, 'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'payload_bytes' => strlen((string)file_get_contents($inF))];
$out = ['schema' => 'windows-runtime-profile-1', 'generated_at' => date('c'), 'env' => $env, 'plain_run' => $plainRes ?: ['error' => 'run tanpa profiler gagal', 'raw' => substr((string)$plainOut, 0, 400)],
    'profiled_run' => $res + ['samples' => $P['n'], 'sampled_s' => round($P['w'], 2), 'top_self' => $top($P['self'], 40), 'top_cumulative' => $top($P['cum'], 60), 'top_call_sites' => $top($P['site'], 60)]];
@mkdir($app . DIRECTORY_SEPARATOR . 'jobs', 0777, true);
file_put_contents($app . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'windows_profile.json', json_encode($out, JSON_PRETTY_PRINT));
echo "OK -> jobs/windows_profile.json (plain " . ($plainRes['wall_s'] ?? '?') . " s, profiled " . round($wall, 1) . " s)\n";
