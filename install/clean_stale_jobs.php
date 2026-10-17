<?php
/* V15.20 — PEMBERSIHAN AMAN folder jobs/ (CLI saja; via URL menjawab 404 tanpa isi).
 *
 *   php install/clean_stale_jobs.php --app=/var/www/html/php74/opr-simulation                     (dry-run: laporan saja)
 *   php install/clean_stale_jobs.php --app=... --execute --backup=/root/pp_jobs_backup_20261013   (pindahkan, bukan hapus)
 *
 * Klasifikasi tiap jobs/<id>:
 *   FOREIGN  job.json menunjuk working_directory di LUAR folder jobs aplikasi ini (mis. disalin dari server lain / Laragon)
 *   STALE    status DONE/FAILED/CANCELLED dan updated_at lebih tua dari --max-age-hours (default 6)
 *   ORPHAN   direktori tanpa job.json yang terbaca, lebih tua dari --max-age-hours
 *   ACTIVE   QUEUED/CLAIMED/RUNNING — TIDAK PERNAH dipindahkan
 *   KEEP     job baru milik server ini
 * Direktori cache bersama (_final, _tl, _memo, _prelim, _fastbasis, _trunc, ...) hanya dipindahkan dengan --include-cache
 * (aman: dibangun ulang otomatis; disarankan sekali setelah jobs/ tersalin dari server lain).
 * Berkas pelindung jobs/.htaccess dan jobs/index.html selalu dipastikan ada. Tidak ada yang dihapus permanen. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opt = ['app' => null, 'execute' => false, 'backup' => null, 'max-age-hours' => 6.0, 'include-cache' => false];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--execute') $opt['execute'] = true;
    elseif ($a === '--include-cache') $opt['include-cache'] = true;
    elseif (preg_match('~^--(app|backup|max-age-hours)=(.+)$~', $a, $m)) $opt[$m[1]] = $m[2];
    else { fwrite(STDERR, "Argumen tidak dikenal: $a\n"); exit(2); }
}
$app = $opt['app'] ? rtrim((string)realpath($opt['app']), '/\\') : '';
if ($app === '' || !is_file($app . '/run.php')) { fwrite(STDERR, "--app=<folder aplikasi> wajib (berisi run.php)\n"); exit(2); }
$root = $app . DIRECTORY_SEPARATOR . 'jobs';
if (!is_dir($root)) { echo json_encode(['jobs_dir' => $root, 'note' => 'jobs/ belum ada; tidak ada yang dibersihkan']), "\n"; exit(0); }
if ($opt['execute']) {
    if (!$opt['backup']) { fwrite(STDERR, "--execute wajib disertai --backup=<folder di LUAR web root>\n"); exit(2); }
    if (!is_dir($opt['backup']) && !@mkdir($opt['backup'], 0700, true)) { fwrite(STDERR, "Tidak dapat membuat folder backup {$opt['backup']}\n"); exit(3); }
    $bk = rtrim((string)realpath($opt['backup']), '/\\');
    if (strpos($bk . DIRECTORY_SEPARATOR, $app . DIRECTORY_SEPARATOR) === 0) { fwrite(STDERR, "Folder backup tidak boleh di dalam folder aplikasi (dapat diakses lewat web)\n"); exit(2); }
}
$maxAge = (float)$opt['max-age-hours'] * 3600.0; $now = time();
$norm = function (string $p): string { return strtolower(rtrim(str_replace('\\', '/', $p), '/')); };
$rootN = $norm($root);
$rows = []; $count = ['FOREIGN' => 0, 'STALE' => 0, 'ORPHAN' => 0, 'ACTIVE' => 0, 'KEEP' => 0, 'CACHE' => 0, 'CACHE_MOVE' => 0]; $moved = 0; $errors = [];
foreach ((array)@scandir($root) as $e) {
    if ($e === '.' || $e === '..' || !is_dir($root . DIRECTORY_SEPARATOR . $e)) continue;
    $d = $root . DIRECTORY_SEPARATOR . $e;
    if ($e[0] === '_' || $e[0] === '.') { $cls = $opt['include-cache'] ? 'CACHE_MOVE' : 'CACHE'; }
    else {
        $j = json_decode((string)@file_get_contents($d . DIRECTORY_SEPARATOR . 'job.json'), true);
        $age = $now - (int)@filemtime($d);
        if (!is_array($j)) $cls = $age > $maxAge ? 'ORPHAN' : 'KEEP';
        else {
            $st = (string)($j['status'] ?? ''); $wd = (string)($j['working_directory'] ?? '');
            $upd = strtotime((string)($j['updated_at'] ?? '')) ?: (int)@filemtime($d); $ageJ = $now - $upd;
            if (in_array($st, ['QUEUED', 'CLAIMED', 'RUNNING'], true) && $wd !== '' && strpos($norm($wd), $rootN . '/') === 0 && $ageJ < 7200) $cls = 'ACTIVE';
            elseif ($wd !== '' && strpos($norm($wd), $rootN . '/') !== 0) $cls = 'FOREIGN';
            elseif (in_array($st, ['DONE', 'FAILED', 'CANCELLED'], true) && $ageJ > $maxAge) $cls = 'STALE';
            elseif (in_array($st, ['QUEUED', 'CLAIMED', 'RUNNING'], true)) $cls = 'ACTIVE';
            else $cls = 'KEEP';
        }
    }
    $count[$cls]++;
    $row = ['entry' => $e, 'class' => $cls];
    if (isset($j) && is_array($j) && $e[0] !== '_') $row += ['status' => $j['status'] ?? null, 'updated_at' => $j['updated_at'] ?? null, 'working_directory' => $j['working_directory'] ?? null];
    if ($opt['execute'] && in_array($cls, ['FOREIGN', 'STALE', 'ORPHAN', 'CACHE_MOVE'], true)) {
        $dst = $bk . DIRECTORY_SEPARATOR . $e;
        if (file_exists($dst)) $dst .= '_' . date('Ymd_His');
        if (@rename($d, $dst)) { $moved++; $row['moved_to'] = $dst; } else { $errors[] = $e; $row['error'] = 'rename gagal (beda filesystem/izin) — pindahkan manual'; }
    }
    $rows[] = $row; unset($j);
}
/* pelindung akses langsung (sama dengan run.php pp_job_root_guard) */
$h = $root . DIRECTORY_SEPARATOR . '.htaccess';
if (!is_file($h)) @file_put_contents($h, "# V15.20: data job hanya lewat run.php (cek kepemilikan). Akses langsung ditolak.\nOptions -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
if (!is_file($root . DIRECTORY_SEPARATOR . 'index.html')) @file_put_contents($root . DIRECTORY_SEPARATOR . 'index.html', '');
echo json_encode(['mode' => $opt['execute'] ? 'EXECUTE' : 'DRY_RUN', 'jobs_dir' => $root, 'max_age_hours' => (float)$opt['max-age-hours'],
    'counts' => $count, 'moved' => $moved, 'errors' => $errors, 'backup' => $opt['execute'] ? $bk : null,
    'guard' => ['htaccess' => is_file($h), 'index_html' => is_file($root . DIRECTORY_SEPARATOR . 'index.html')], 'entries' => $rows],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($errors ? 4 : 0);
