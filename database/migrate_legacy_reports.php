<?php
/* =============================================================================================
 *  database/migrate_legacy_reports.php — migrasi SATU KALI Report lama -> MySQL (pp-report-mysql-1)
 *
 *  php database/migrate_legacy_reports.php --app=/var/www/html/app [--execute] [--out=/root/pp_migration] [--project=default]
 *
 *  Default = DRY-RUN (tidak menulis apa pun ke MySQL). --execute menulis.
 *  Koneksi DB: sama dengan aplikasi -> <--app>/config/db.php (atau env DB_* / PP_DB_CONFIG bila diset).
 *
 *  Sumber (semua dibaca READ-ONLY; store lama tidak diubah/dihapus):
 *    saved/store.sqlite (+ -wal, dibaca dari salinan sementara), saved/json/<uid>/<project>/v*.json,
 *    saved/users/<uid>/input_latest.json, saved/records/*.json, saved_data_history/*.json, input_data.json.
 *  Yang dimigrasikan: setiap record report_planning (Plan + Monitoring Daily Plan) beserta snapshot input+hasil, dan
 *  report_actual per bulan (snapshot terbaru per bulan).
 *
 *  Sifat:
 *    - idempoten: setiap versi record diberi source_hash = sha256(type|date|nama|snapshot bytes) dan dicatat di
 *      legacy_migration_map; run ulang melewatinya (skipped_already_migrated);
 *    - dedup: snapshot identik yang muncul di banyak autosave hanya menjadi SATU versi;
 *    - riwayat: beberapa isi berbeda untuk nama sama -> beberapa versi berurutan saved_at (versi terakhir aktif);
 *      bila entry tersebut SUDAH punya versi baru dari aplikasi (setelah go-live), versi legacy masuk sebagai HISTORY_ONLY
 *      dan pointer aktif tidak dipindah (conflict_newer_in_mysql) — tidak ada overwrite tanpa riwayat;
 *    - orphan: record tanpa snapshot.input/hasil, atau Monitoring tanpa 48 row -> tidak dimigrasikan, dilaporkan;
 *    - backup manifest (path, ukuran, mtime, sha256 setiap sumber) ditulis SEBELUM migrasi;
 *    - arsip report lama TIDAK pernah masuk payload engine (snapshot dibersihkan dari report_planning bersarang).
 *  Output: <out>/<run>/manifest.json, migration_report.json, migration_report.md
 * ============================================================================================= */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
ini_set('memory_limit', '2048M');
$opt = ['app' => null, 'execute' => false, 'out' => null, 'project' => null];
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--execute') $opt['execute'] = true;
    elseif (preg_match('~^--(app|out|project)=(.*)$~', $a, $m)) $opt[$m[1]] = $m[2];
    elseif ($a === '--help' || $a === '-h') { echo "php migrate_legacy_reports.php --app=<app dir> [--execute] [--out=<dir>] [--project=<key>]\n"; exit(0); }
}
$app = rtrim((string)($opt['app'] ?? dirname(__DIR__)), '/\\');
if (!is_file($app . '/saved_data_store.php')) { fwrite(STDERR, "saved_data_store.php tidak ditemukan di --app=$app\n"); exit(2); }
require_once $app . '/saved_data_store.php';
$runId = 'mig_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3));
$outDir = rtrim((string)($opt['out'] ?? (sys_get_temp_dir() . '/pp_migration')), '/\\') . DIRECTORY_SEPARATOR . $runId;
if (!is_dir($outDir) && !mkdir($outDir, 0770, true)) { fwrite(STDERR, "tidak bisa membuat $outDir\n"); exit(2); }
$project = $opt['project'] ?: rs_project_key();
$mode = $opt['execute'] ? 'EXECUTE' : 'DRY_RUN';
$log = function (string $s) { fwrite(STDERR, '[' . gmdate('H:i:s') . "] $s\n"); };
$log("$mode run=$runId app=$app project=$project out=$outDir");

/* ---------------- 1. sumber + backup manifest ---------------- */
$files = [];
$add = function (string $f, string $kind) use (&$files) { if (is_file($f)) $files[] = ['path' => $f, 'kind' => $kind]; };
$add($app . '/input_data.json', 'input_data');
foreach (glob($app . '/saved_data_history/*.json') ?: [] as $f) $add($f, 'history');
foreach (glob($app . '/saved/records/*.json') ?: [] as $f) $add($f, 'records');
foreach (glob($app . '/saved/users/*/input_latest.json') ?: [] as $f) $add($f, 'user_latest');
foreach (glob($app . '/saved/json/*/*/v*.json') ?: [] as $f) $add($f, 'json_store');
$add($app . '/saved/store.sqlite', 'sqlite');
foreach ([$app . '/saved/store.sqlite-wal', $app . '/saved/store.sqlite-shm'] as $f) $add($f, 'sqlite_aux');
foreach ($files as &$fi) { $fi['bytes'] = filesize($fi['path']); $fi['mtime_utc'] = gmdate('c', filemtime($fi['path'])); $fi['sha256'] = hash_file('sha256', $fi['path']); } unset($fi);
file_put_contents($outDir . '/manifest.json', json_encode(['schema' => 'pp-legacy-backup-manifest-1', 'run' => $runId, 'mode' => $mode, 'app' => $app, 'created_utc' => gmdate('c'),
    'note' => 'Backup manifest SEBELUM migrasi. Store lama tidak diubah oleh skrip ini; simpan salinan folder saved/ + input_data.json sesuai manifest ini.',
    'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$log(count($files) . ' file sumber, manifest ditulis');

/* ---------------- 2. ekstraksi record ---------------- */
$recs = [];          // source_hash => record
$orphans = []; $skipped = []; $actual = []; $payloads = 0;
$walkRP = function ($rp, string $src, string $srcSha, string $uid) use (&$recs, &$orphans, &$skipped) {
    if (!is_array($rp)) return;
    $list = [];
    if (isset($rp['records']) && is_array($rp['records'])) foreach ($rp['records'] as $r) $list[] = $r;
    $isList = array_keys($rp) === range(0, count($rp) - 1);
    if ($isList) foreach ($rp as $r) $list[] = $r;
    else foreach ($rp as $y => $months) { if ($y === 'records' || !is_array($months)) continue;
        foreach ($months as $mo => $dates) { if (!is_array($dates)) continue;
            foreach ($dates as $dt => $plans) { if (!is_array($plans)) continue; foreach ($plans as $p) $list[] = $p; } } }
    foreach ($list as $r) {
        if (!is_array($r)) continue;
        $pt = (string)($r['plan_type'] ?? ((preg_match('~^Monitoring Daily Plan~i', (string)($r['name_plan'] ?? ''))) ? 'monitoring' : 'plan'));
        $name = trim((string)($r['name_plan'] ?? '')); $date = (string)($r['plan_date'] ?? ($r['day_date'] ?? ''));
        if ($pt === 'weekly_plan_day') { $skipped[] = ['reason' => 'WEEKLY_OUT_OF_SCOPE', 'name' => $name, 'date' => $date, 'source' => $src]; continue; }
        $type = $pt === 'monitoring' ? 'MONITORING' : 'PLANNING';
        $in = $r['snapshot']['input'] ?? null; $out = $r['snapshot']['output'] ?? null;
        if ($name === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) { $orphans[] = ['reason' => 'NO_NAME_OR_DATE', 'name' => $name, 'date' => $date, 'source' => $src]; continue; }
        if (!is_array($in) || !isset($in['data3']['modeling'])) { $orphans[] = ['reason' => 'NO_SNAPSHOT_INPUT', 'type' => $type, 'name' => $name, 'date' => $date, 'source' => $src]; continue; }
        if (!is_array($out) || !is_array($out['data'] ?? null) || !count($out['data'])) { $orphans[] = ['reason' => 'NO_SNAPSHOT_RESULT', 'type' => $type, 'name' => $name, 'date' => $date, 'source' => $src]; continue; }
        if ($type === 'MONITORING' && count($out['data']) !== 48) { $orphans[] = ['reason' => 'MONITORING_NOT_48_ROWS', 'rows' => count($out['data']), 'name' => $name, 'date' => $date, 'source' => $src]; continue; }
        unset($in['data3']['modeling']['report_planning']);           // arsip tidak pernah masuk payload
        $inJ = json_encode($in, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $outJ = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $sh = hash('sha256', $type . '|' . $date . '|' . rs_norm_name($name) . '|' . $inJ . '|' . $outJ);
        if (isset($recs[$sh])) { $recs[$sh]['seen']++; continue; }
        $recs[$sh] = ['source_hash' => $sh, 'type' => $type, 'date' => $date, 'name' => $name, 'remark' => (string)($r['plan_remark'] ?? ''),
            'saved_at' => preg_match('~^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}~', (string)($r['saved_at'] ?? '')) ? str_replace('T', ' ', substr((string)$r['saved_at'], 0, 19)) : null,
            'legacy_snapshot_id' => isset($r['snapshot_id']) ? substr((string)$r['snapshot_id'], 0, 128) : null, 'summary' => is_array($r['summary'] ?? null) ? $r['summary'] : null,
            'inJ' => $inJ, 'outJ' => $outJ, 'in' => $in, 'out' => $out, 'source' => $src, 'source_sha' => $srcSha, 'uid' => $uid, 'seen' => 1];
    }
};
$takeActual = function ($ra, string $src, int $mtime) use (&$actual) {
    if (!is_array($ra)) return;
    foreach ($ra as $y => $ms) { if (!is_array($ms) || !preg_match('~^\d{4}$~', (string)$y)) continue;
        foreach ($ms as $mn => $data) { if (!is_array($data) || !$data) continue;
            $k = $y . '|' . $mn; if (!isset($actual[$k]) || $actual[$k]['mtime'] < $mtime) $actual[$k] = ['year' => (int)$y, 'month' => (string)$mn, 'data' => $data, 'mtime' => $mtime, 'source' => $src]; } }
};
$fromPayload = function ($pl, string $src, string $srcSha, string $uid, int $mtime) use ($walkRP, $takeActual, &$payloads) {
    if (!is_array($pl) || !isset($pl['data3']['modeling'])) return; $payloads++;
    $walkRP($pl['data3']['modeling']['report_planning'] ?? null, $src, $srcSha, $uid);
    $takeActual($pl['data3']['modeling']['report_actual'] ?? null, $src, $mtime);
};
foreach ($files as $fi) {
    $f = $fi['path']; $mt = filemtime($f); $rel = substr($f, strlen($app) + 1);
    if ($fi['kind'] === 'sqlite_aux') continue;
    if ($fi['kind'] === 'sqlite') {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { $skipped[] = ['reason' => 'PDO_SQLITE_MISSING', 'source' => $rel]; continue; }
        $tmp = sys_get_temp_dir() . '/pp_mig_' . bin2hex(random_bytes(4)); mkdir($tmp);
        copy($f, $tmp . '/store.sqlite'); foreach (['-wal', '-shm'] as $sx) if (is_file($f . $sx)) copy($f . $sx, $tmp . '/store.sqlite' . $sx);
        $db = new PDO('sqlite:' . $tmp . '/store.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $db->query('SELECT record_id, user_or_session_id, created_at, input_payload_json FROM records ORDER BY created_at');
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) $fromPayload(json_decode((string)$row['input_payload_json'], true), $rel . '#' . $row['record_id'], $fi['sha256'], (string)$row['user_or_session_id'], (int)strtotime((string)$row['created_at']));
        $db = null; foreach (glob($tmp . '/*') ?: [] as $x) @unlink($x); @rmdir($tmp);
        continue;
    }
    $d = json_decode((string)file_get_contents($f), true);
    if (!is_array($d)) { $skipped[] = ['reason' => 'UNREADABLE_JSON', 'source' => $rel]; continue; }
    $uid = preg_match('~saved[/\\\\](?:users|json)[/\\\\]([^/\\\\]+)~', $f, $mm) ? $mm[1] : 'legacy';
    $pl = $fi['kind'] === 'input_data' ? $d : ($d['input'] ?? ($d['payload'] ?? null));
    $fromPayload($pl, $rel, $fi['sha256'], $uid, $mt);
}
$log(count($recs) . " versi record unik dari $payloads payload; orphan " . count($orphans) . ', bulan actual ' . count($actual));

/* ---------------- 3. kelompokkan per identitas, urutkan riwayat ---------------- */
$groups = [];
foreach ($recs as $r) $groups[$r['type'] . '|' . $r['date'] . '|' . rs_norm_name($r['name'])][] = $r;
$conflicts = [];
foreach ($groups as $k => &$g) {
    usort($g, function ($a, $b) { return strcmp((string)$a['saved_at'], (string)$b['saved_at']); });
    $bySaved = []; foreach ($g as $r) $bySaved[(string)$r['saved_at']][] = $r['source_hash'];
    foreach ($bySaved as $sa => $hs) if (count($hs) > 1) $conflicts[] = ['identity' => $k, 'saved_at' => $sa, 'versions' => count($hs), 'resolution' => 'semua isi berbeda dipertahankan sebagai versi berurutan'];
} unset($g);

/* ---------------- 4. tulis (EXECUTE) / simulasikan (DRY_RUN) ---------------- */
$p = rs_enabled() ? rs_pdo() : null;
if ($opt['execute'] && !$p) { fwrite(STDERR, 'DB tidak tersedia: ' . (rs_last_error() ?: 'config/db.php tidak ditemukan / belum diisi') . "\n"); exit(3); }
$migrated = []; $already = []; $histOnly = [];
$isMapped = function (string $sh) use ($p) { if (!$p) return false; $q = $p->prepare('SELECT report_entry_id FROM legacy_migration_map WHERE source_hash = ?'); $q->execute([$sh]); return $q->fetchColumn() !== false; };
$ctxUser = function (PDO $p, string $uid) { return rs_user_id($p, $uid !== '' ? 'legacy:' . $uid : 'legacy-migration'); };
foreach ($groups as $k => $g) {
    $todo = []; foreach ($g as $r) { if ($isMapped($r['source_hash'])) $already[] = ['identity' => $k, 'source_hash' => $r['source_hash']]; else $todo[] = $r; }
    if (!$todo) continue;
    if (!$opt['execute']) { foreach ($todo as $r) $migrated[] = ['identity' => $k, 'type' => $r['type'], 'date' => $r['date'], 'name' => $r['name'], 'saved_at' => $r['saved_at'], 'source' => $r['source'], 'would_migrate' => true]; continue; }
    $attempt = 0;
    while (true) {
        $attempt++;
        try {
            $p->beginTransaction();
            $prj = rs_project_id($p, $project, null);
            /* entry sudah punya versi dari aplikasi (bukan impor)? -> versi legacy hanya riwayat */
            $q = $p->prepare('SELECT e.id FROM report_entries e WHERE e.project_id = ? AND e.report_type = ? AND e.plan_date = ? AND e.plan_name_normalized = ? AND e.active_marker = 0 FOR UPDATE');
            $q->execute([$prj, $g[0]['type'], $g[0]['date'], rs_norm_name($g[0]['name'])]); $eid = $q->fetchColumn();
            $hasAppVersions = false;
            if ($eid !== false) { $q2 = $p->prepare("SELECT COUNT(*) FROM report_versions WHERE report_entry_id = ? AND (change_reason IS NULL OR change_reason NOT LIKE 'LEGACY%')"); $q2->execute([(int)$eid]); $hasAppVersions = (int)$q2->fetchColumn() > 0; }
            $done = [];
            foreach ($todo as $r) {
                $uidRow = $ctxUser($p, (string)$r['uid']);
                $runRow = rs_run_id($p, ['run_key' => 'legacy:' . substr($r['source_hash'], 0, 32), 'client_run_id' => 'legacy', 'project_id' => $prj, 'user_id' => $uidRow, 'tab_id' => null,
                    'mode' => 'FASTEST', 'status' => 'FINAL', 'payload_hash' => hash('sha256', $r['inJ']), 'started_at' => $r['saved_at'], 'finished_at' => $r['saved_at']]);
                $snap = rs_snapshot_id($p, $runRow, $r['inJ'], 'pp-input-legacy-v1');
                $res = rs_result_id($p, $runRow, $snap['id'], $r['outJ'], rs_result_facts($r['out']), $r['summary'] !== null ? json_encode($r['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
                $ent = rs_report_put($p, ['project_id' => $prj, 'report_type' => $r['type'], 'plan_date' => $r['date'], 'plan_name' => $r['name'], 'remark' => $r['remark'],
                    'result_id' => $res['id'], 'snapshot_id' => $snap['id'], 'user_id' => $uidRow, 'request_id' => $runId . '-' . substr($r['source_hash'], 0, 12),
                    'saved_at' => $r['saved_at'] ? $r['saved_at'] . '.000000' : null, 'history_only' => $hasAppVersions,
                    'change_reason' => $hasAppVersions ? 'LEGACY_IMPORT_HISTORY_ONLY' : 'LEGACY_IMPORT']);
                if ($r['type'] === 'MONITORING' && $ent['active']) rs_monitoring_rows($p, $ent['id'], $ent['version_no'], $r['date'], $r['out'], $r['in']);
                $p->prepare('INSERT INTO legacy_migration_map (source_hash, source_path, source_file_sha256, legacy_snapshot_id, report_entry_id, version_no, migration_run, created_at) VALUES (?,?,?,?,?,?,?,?)')
                  ->execute([$r['source_hash'], substr($r['source'], 0, 512), $r['source_sha'], $r['legacy_snapshot_id'], $ent['id'], $ent['version_no'], $runId, rs_now()]);
                $done[] = ['identity' => $k, 'type' => $r['type'], 'date' => $r['date'], 'name' => $r['name'], 'saved_at' => $r['saved_at'], 'report_key' => $ent['report_key'], 'version_no' => $ent['version_no'], 'active' => $ent['active'], 'source' => $r['source']];
                if (!$ent['active']) $histOnly[] = ['identity' => $k, 'report_key' => $ent['report_key'], 'version_no' => $ent['version_no']];
            }
            rs_event($p, 'audit', ['event_key' => rs_key('aud'), 'user_id' => null, 'project_id' => $prj, 'event_type' => 'LEGACY_MIGRATION', 'request_id' => $runId, 'status' => 'COMMITTED',
                'message' => $k . ' +' . count($done) . ' version(s)']);
            $p->commit();
            foreach ($done as $d) $migrated[] = $d;
            break;
        } catch (Throwable $ex) {
            if ($p->inTransaction()) $p->rollBack();
            if (rs_is_retryable($ex) && $attempt < 3) continue;
            $orphans[] = ['reason' => 'WRITE_FAILED_ROLLED_BACK', 'identity' => $k, 'message' => $ex->getMessage()];
            break;
        }
    }
}
/* Report Actual: satu entry ACTUAL per bulan (isi terbaru antar sumber), versi baru hanya bila berbeda */
$actualDone = [];
foreach ($actual as $a) {
    $mi = array_search($a['month'], ['January','February','March','April','May','June','July','August','September','October','November','December'], true);
    if ($mi === false) { $skipped[] = ['reason' => 'ACTUAL_BAD_MONTH', 'month' => $a['month'], 'year' => $a['year']]; continue; }
    if (!$opt['execute']) { $actualDone[] = ['year' => $a['year'], 'month' => $a['month'], 'source' => $a['source'], 'would_migrate' => true]; continue; }
    $r = rs_actual_save(['request_id' => $runId . '-actual-' . $a['year'] . '-' . ($mi + 1), 'project_key' => $project,
        'months' => [['year' => $a['year'], 'month' => $a['month'], 'month_index' => $mi, 'data_json' => json_encode($a['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]]],
        ['uid' => 'legacy-migration', 'tab' => '', 'project' => $project, 'run' => '']);
    $actualDone[] = ['year' => $a['year'], 'month' => $a['month'], 'ok' => !empty($r['ok']), 'result' => $r['months'][0] ?? ($r['error'] ?? null)];
}
$integrity = ($opt['execute'] && $p) ? rs_integrity() : null;
$rep = ['schema' => 'pp-legacy-migration-report-1', 'run' => $runId, 'mode' => $mode, 'project_key' => $project, 'finished_utc' => gmdate('c'),
    'counts' => ['source_files' => count($files), 'payloads_scanned' => $payloads, 'unique_record_versions' => count($recs), 'identities' => count($groups),
        ($opt['execute'] ? 'migrated' : 'would_migrate') => count($migrated), 'skipped_already_migrated' => count($already), 'skipped_other' => count($skipped),
        'conflict_same_saved_at' => count($conflicts), 'history_only_newer_in_mysql' => count($histOnly), 'orphan' => count($orphans), 'actual_months' => count($actualDone)],
    'migrated' => $migrated, 'skipped_already_migrated' => $already, 'skipped' => $skipped, 'conflicts' => $conflicts, 'history_only' => $histOnly, 'orphans' => $orphans,
    'actual' => $actualDone, 'integrity_after' => $integrity,
    'legacy_store' => 'TIDAK diubah. Biarkan read-only sebagai fallback sampai verifikasi MySQL PASS (README_MYSQL_SETUP.md §6).'];
file_put_contents($outDir . '/migration_report.json', json_encode($rep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$md = "# Legacy migration $runId ($mode)\n\nProject: `$project`\n\n| Count | Value |\n|---|---|\n";
foreach ($rep['counts'] as $k => $v) $md .= "| $k | $v |\n";
$md .= "\nOrphans:\n\n" . ($orphans ? implode("\n", array_map(function ($o) { return '- ' . ($o['reason'] ?? '?') . ' — ' . ($o['type'] ?? '') . ' ' . ($o['date'] ?? '') . ' ' . ($o['name'] ?? ($o['identity'] ?? '')) . ' (' . ($o['source'] ?? '') . ')'; }, $orphans)) : '- none') . "\n";
$md .= "\nConflicts (same identity, same saved_at, different content):\n\n" . ($conflicts ? implode("\n", array_map(function ($c) { return '- ' . $c['identity'] . ' @ ' . $c['saved_at'] . ' x' . $c['versions']; }, $conflicts)) : '- none') . "\n";
if ($integrity !== null) $md .= "\nIntegrity after migration: " . ($integrity['ok'] ? 'PASS' : 'FAIL (' . $integrity['problem_count'] . ')') . "\n";
file_put_contents($outDir . '/migration_report.md', $md);
echo json_encode(['run' => $runId, 'mode' => $mode, 'out' => $outDir, 'counts' => $rep['counts'], 'integrity_ok' => $integrity['ok'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit(count(array_filter($orphans, function ($o) { return ($o['reason'] ?? '') === 'WRITE_FAILED_ROLLED_BACK'; })) ? 1 : 0);
