<?php
declare(strict_types=1);
/* =============================================================================================
 *  saved_data_store.php — DATASTORE KANONIK (V15.16, multi-user / multi-tab)
 *
 *  PHP adalah controller, BUKAN datastore. Seluruh data tersimpan di folder `saved/` di samping
 *  aplikasi (XAMPP htdocs\...\saved atau Linux /var/www/.../saved):
 *    - SQLite WAL (`saved/store.sqlite`) bila pdo_sqlite tersedia — transaksi BEGIN IMMEDIATE,
 *      busy_timeout, journal WAL: banyak user membaca/menulis bersamaan tanpa saling menimpa;
 *    - fallback JSON per user/proyek (`saved/json/<uid>/<project>/v<versi>_<record>.json`) dengan
 *      file lock (flock) + tulis-sementara-lalu-rename (atomic) bila SQLite tidak ada.
 *  Kunci record: user/session ID (pp_uid) · tab ID (sessionStorage) · project ID (plan type + tanggal +
 *  nama plan) · run ID (request id) · record/version ID.
 *  Setiap Run menulis snapshot input IMMUTABLE (AUTOSAVE_RUN); Save menulis versi baru (SAVE); hasil
 *  final job tercatat sebagai SIMULATION_FINAL (input + hasil). Input terakhir per user juga disimpan
 *  sebagai file atomik `saved/users/<uid>/input_latest.json` (dibaca index.php saat halaman dimuat dan
 *  oleh Reload) — user lain tidak pernah menimpanya. Retensi: 200 versi terakhir per user+proyek dan
 *  umur maksimum 180 hari, dibersihkan berkala saat commit.
 *  Kompatibilitas: pp_store_commit() (run.php) dan endpoint store_list / store_load / store_meta /
 *  store_delete / store_integrity tetap ada; tambahan store_latest.
 * ============================================================================================= */

if (!defined('PP_SDS_RETAIN_N')) define('PP_SDS_RETAIN_N', 200);
if (!defined('PP_SDS_RETAIN_DAYS')) define('PP_SDS_RETAIN_DAYS', 180);

function sds_root(): string { $d = __DIR__ . DIRECTORY_SEPARATOR . 'saved'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function sds_dir(string $sub): string { $d = sds_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub); if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function sds_clean_id($v, int $max = 64): string { $s = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)$v); return substr((string)$s, 0, $max); }

/* ---------------- identitas request ---------------- */
function sds_ctx(array $input = []): array {
    $uid = sds_clean_id($_GET['uid'] ?? ($input['_uid'] ?? ($_COOKIE['pp_uid'] ?? '')));
    $tab = sds_clean_id($_GET['tab'] ?? ($input['_tab'] ?? ''));
    $rid = sds_clean_id($input['_request_id'] ?? ($_GET['rid'] ?? ''));
    $m = (array)($input['data3']['modeling'] ?? []);
    $proj = sds_clean_id($input['_project'] ?? '');
    if ($proj === '') {
        $pt = strtolower((string)($m['plan_type'] ?? 'planning')); $dt = (string)($m['plan_date'] ?? ''); $nm = (string)($m['name_plan'] ?? ($m['plan_name'] ?? ''));
        $proj = ($dt === '' && $nm === '') ? 'default' : 'p_' . substr(hash('sha256', $pt . '|' . $dt . '|' . strtolower(trim($nm))), 0, 16);
    }
    return ['uid' => $uid, 'tab' => $tab, 'project' => $proj, 'run' => $rid];
}
function sds_clean_input(array $input): array { foreach (array_keys($input) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($input[$k]); return $input; }

/* ---------------- tulis atomik + lock ---------------- */
function sds_atomic_write(string $path, string $bytes, ?string &$why = null): bool {
    $why = null; $dir = dirname($path); if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $why = 'mkdir'; return false; }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) { @unlink($tmp); $why = 'write'; return false; }
    if (!@rename($tmp, $path)) { if (stripos(PHP_OS, 'WIN') === 0) { @unlink($path); if (@rename($tmp, $path)) return true; } @unlink($tmp); $why = 'rename'; return false; }
    return true;
}
function sds_with_lock(string $key, callable $fn) {
    $lf = sds_dir('locks') . DIRECTORY_SEPARATOR . sds_clean_id($key, 120) . '.lock'; $h = @fopen($lf, 'c');
    if ($h) @flock($h, LOCK_EX);
    try { return $fn(); } finally { if ($h) { @flock($h, LOCK_UN); @fclose($h); } }
}

/* ---------------- backend ---------------- */
function sds_pdo(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $pdo = null;
    if ((string)getenv('PP_SDS_BACKEND') === 'json' || !class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) return null;
    try {
        $p = new PDO('sqlite:' . sds_root() . DIRECTORY_SEPARATOR . 'store.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $p->exec('PRAGMA journal_mode=WAL'); $p->exec('PRAGMA busy_timeout=10000'); $p->exec('PRAGMA synchronous=NORMAL');
        $p->exec('CREATE TABLE IF NOT EXISTS records (record_id TEXT PRIMARY KEY, user_or_session_id TEXT NOT NULL, tab_id TEXT, project_id TEXT NOT NULL,
            run_id TEXT, kind TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, input_payload_json TEXT, result_payload_json TEXT,
            status TEXT, version INTEGER NOT NULL, payload_hash TEXT, meta_json TEXT)');
        $p->exec('CREATE INDEX IF NOT EXISTS ix_rec_scope ON records(user_or_session_id, project_id, version)');
        $p->exec('CREATE INDEX IF NOT EXISTS ix_rec_time ON records(created_at)');
        $pdo = $p;
    } catch (Throwable $e) { $pdo = null; }
    return $pdo;
}
function sds_backend(): string { return sds_pdo() ? 'sqlite-wal' : 'json-lock-atomic'; }
function sds_user_latest_file(string $uid): string { return sds_dir('users/' . sds_clean_id($uid)) . DIRECTORY_SEPARATOR . 'input_latest.json'; }

/** Commit satu record (versi baru). Mengembalikan ringkasan record atau null (+$why). */
function sds_commit(string $kind, array $input, ?array $output, array $ctx, array $meta = [], ?string &$why = null): ?array {
    static $mig = false; if (!$mig) { $mig = true; try { if (!rs_enabled() && !is_file(sds_root() . '/meta/legacy_migrated.json')) sds_migrate_legacy(); } catch (Throwable $e) {} }
    $why = null; $uid = $ctx['uid'] !== '' ? $ctx['uid'] : 'shared'; $proj = $ctx['project'] ?: 'default';
    $clean = sds_clean_input($input); $inJ = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($inJ)) { $why = 'encode_input'; return null; }
    $outJ = $output === null ? null : json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $hash = hash('sha256', $inJ); $now = gmdate('c');
    $rec = ['record_id' => 'rec_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(5)), 'user_or_session_id' => $uid, 'tab_id' => $ctx['tab'], 'project_id' => $proj,
            'run_id' => $ctx['run'], 'kind' => $kind, 'created_at' => $now, 'updated_at' => $now, 'status' => (string)($meta['status'] ?? 'OK'),
            'version' => 0, 'payload_hash' => $hash];
    $pdo = sds_pdo();
    if ($pdo) {
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $st = $pdo->prepare('SELECT COALESCE(MAX(version),0) FROM records WHERE user_or_session_id=? AND project_id=?'); $st->execute([$uid, $proj]);
            $rec['version'] = (int)$st->fetchColumn() + 1;
            $ins = $pdo->prepare('INSERT INTO records VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ins->execute([$rec['record_id'], $uid, $rec['tab_id'], $proj, $rec['run_id'], $kind, $now, $now, $inJ, $outJ, $rec['status'], $rec['version'], $hash,
                json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {} $why = 'sqlite:' . $e->getMessage(); return null; }
    } else {
        $ok = sds_with_lock('u_' . $uid . '_' . $proj, function () use (&$rec, $uid, $proj, $inJ, $outJ, $meta, &$why) {
            $dir = sds_dir('json/' . $uid . '/' . $proj); $ver = 0;
            foreach ((array)@scandir($dir) as $f) if (preg_match('~^v(\d+)_~', (string)$f, $mm)) $ver = max($ver, (int)$mm[1]);
            $rec['version'] = $ver + 1;
            $body = json_encode($rec + ['input' => json_decode($inJ, true), 'result' => $outJ === null ? null : json_decode($outJ, true), 'meta' => $meta],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return sds_atomic_write($dir . DIRECTORY_SEPARATOR . sprintf('v%08d_%s.json', $rec['version'], $rec['record_id']), (string)$body, $why);
        });
        if (!$ok) return null;
    }
    if (in_array($kind, ['SAVE', 'AUTOSAVE_RUN', 'ACTUAL_GAS_DRAFT'], true) && $uid !== 'shared') {   // V15.22: draft Actual Gas dimuat kembali saat reload
        $w = null; sds_with_lock('latest_' . $uid, function () use ($uid, $inJ, $rec, &$w) {
            $doc = '{"schema":"pp-user-latest-v1","record_id":' . json_encode($rec['record_id']) . ',"kind":' . json_encode($rec['kind']) . ',"project_id":' . json_encode($rec['project_id'])
                 . ',"version":' . (int)$rec['version'] . ',"saved_at":' . json_encode($rec['created_at']) . ',"input":' . $inJ . '}';
            return sds_atomic_write(sds_user_latest_file($uid), $doc, $w); });
    }
    /* Retensi menghapus record lama; selama MySQL menjadi datastore Report kanonik, store lama read-only (tidak dibersihkan). */
    if (!rs_enabled() && random_int(1, 10) === 1) { try { sds_retention(); } catch (Throwable $e) {} }
    return $rec;
}
/** Input terakhir milik user (SAVE / AUTOSAVE_RUN). null bila belum ada. */
function sds_latest_input(string $uid): ?array {
    $uid = sds_clean_id($uid); if ($uid === '') return null; $f = sds_root() . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . 'input_latest.json';
    if (!is_file($f)) return null; $d = json_decode((string)@file_get_contents($f), true);
    return (is_array($d) && is_array($d['input'] ?? null) && isset($d['input']['data3']['modeling'])) ? $d : null;
}
function sds_list(string $uid, array $filter = []): array {
    $uid = $uid !== '' ? $uid : 'shared'; $lim = max(1, min(500, (int)($filter['limit'] ?? 100))); $rows = [];
    $pdo = sds_pdo();
    if ($pdo) {
        $sql = 'SELECT record_id,user_or_session_id,tab_id,project_id,run_id,kind,created_at,updated_at,status,version,payload_hash,meta_json FROM records WHERE user_or_session_id=?';
        $args = [$uid];
        if (!empty($filter['project'])) { $sql .= ' AND project_id=?'; $args[] = (string)$filter['project']; }
        if (!empty($filter['kind'])) { $sql .= ' AND kind=?'; $args[] = (string)$filter['kind']; }
        $sql .= ' ORDER BY created_at DESC, version DESC LIMIT ' . $lim; $st = $pdo->prepare($sql); $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $r['meta'] = json_decode((string)$r['meta_json'], true); unset($r['meta_json']); $rows[] = $r; }
        return $rows;
    }
    $base = sds_root() . DIRECTORY_SEPARATOR . 'json' . DIRECTORY_SEPARATOR . sds_clean_id($uid);
    foreach ((array)@scandir($base) as $p) { if ($p === '.' || $p === '..' || (!empty($filter['project']) && $p !== $filter['project'])) continue;
        foreach ((array)@scandir($base . DIRECTORY_SEPARATOR . $p) as $f) { if (!preg_match('~^v\d+_.*\.json$~', (string)$f)) continue;
            $d = json_decode((string)@file_get_contents($base . DIRECTORY_SEPARATOR . $p . DIRECTORY_SEPARATOR . $f), true); if (!is_array($d)) continue;
            if (!empty($filter['kind']) && ($d['kind'] ?? '') !== $filter['kind']) continue;
            unset($d['input'], $d['result']); $rows[] = $d; } }
    usort($rows, function ($a, $b) { return [(string)$b['created_at'], (int)$b['version']] <=> [(string)$a['created_at'], (int)$a['version']]; });
    return array_slice($rows, 0, $lim);
}
/** Record lengkap — hanya milik user pemanggil (isolasi). */
function sds_load(string $uid, string $id): ?array {
    $uid = $uid !== '' ? $uid : 'shared'; $id = sds_clean_id($id, 80); $pdo = sds_pdo();
    if ($pdo) { $st = $pdo->prepare('SELECT * FROM records WHERE record_id=? AND user_or_session_id=?'); $st->execute([$id, $uid]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null; $r['input'] = json_decode((string)$r['input_payload_json'], true); $r['result'] = $r['result_payload_json'] === null ? null : json_decode((string)$r['result_payload_json'], true);
        $r['meta'] = json_decode((string)$r['meta_json'], true); unset($r['input_payload_json'], $r['result_payload_json'], $r['meta_json']); return $r; }
    foreach (glob(sds_root() . '/json/' . sds_clean_id($uid) . '/*/v*_' . $id . '.json') ?: [] as $f) { $d = json_decode((string)@file_get_contents($f), true); if (is_array($d)) return $d; }
    return null;
}
function sds_update_meta(string $uid, string $id, array $meta, ?string &$why = null): bool {
    $why = null; $r = sds_load($uid, $id); if (!$r) { $why = 'NOT_FOUND'; return false; }
    $m = array_merge((array)($r['meta'] ?? []), array_intersect_key($meta, array_flip(['label', 'note', 'status']))); $pdo = sds_pdo();
    if ($pdo) { $st = $pdo->prepare('UPDATE records SET meta_json=?, updated_at=?, status=COALESCE(?,status) WHERE record_id=? AND user_or_session_id=?');
        $st->execute([json_encode($m), gmdate('c'), $meta['status'] ?? null, $r['record_id'], $r['user_or_session_id']]); return true; }
    foreach (glob(sds_root() . '/json/' . sds_clean_id($r['user_or_session_id']) . '/*/v*_' . $r['record_id'] . '.json') ?: [] as $f) {
        $r['meta'] = $m; $r['updated_at'] = gmdate('c'); return sds_atomic_write($f, (string)json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $why); }
    return false;
}
function sds_delete(string $uid, string $id, string $confirm, ?string &$why = null): bool {
    $why = null; if ($confirm !== 'DELETE') { $why = 'CONFIRM_REQUIRED'; return false; } $r = sds_load($uid, $id); if (!$r) { $why = 'NOT_FOUND'; return false; }
    $pdo = sds_pdo(); if ($pdo) { $st = $pdo->prepare('DELETE FROM records WHERE record_id=? AND user_or_session_id=?'); $st->execute([$r['record_id'], $r['user_or_session_id']]); return true; }
    foreach (glob(sds_root() . '/json/' . sds_clean_id($r['user_or_session_id']) . '/*/v*_' . $r['record_id'] . '.json') ?: [] as $f) return @unlink($f);
    return false;
}
/** Integritas: hash payload cocok dengan isi; record JSON rusak dikarantina (*.corrupt) — corruption recovery. */
function sds_integrity(string $uid = ''): array {
    $bad = []; $n = 0; $pdo = sds_pdo();
    if ($pdo) { $ic = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        $q = $pdo->prepare('SELECT record_id,input_payload_json,payload_hash FROM records' . ($uid !== '' ? ' WHERE user_or_session_id=?' : '')); $q->execute($uid !== '' ? [$uid] : []);
        while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $n++; if (hash('sha256', (string)$r['input_payload_json']) !== $r['payload_hash']) $bad[] = $r['record_id']; }
        return ['backend' => 'sqlite-wal', 'sqlite_integrity' => $ic, 'records' => $n, 'hash_mismatch' => $bad, 'ok' => $ic === 'ok' && !$bad]; }
    foreach (glob(sds_root() . '/json/*/*/v*.json') ?: [] as $f) { $n++; $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || hash('sha256', (string)json_encode($d['input'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) !== ($d['payload_hash'] ?? '')) {
            $bad[] = basename($f); @rename($f, $f . '.corrupt'); } }
    return ['backend' => 'json-lock-atomic', 'records' => $n, 'quarantined' => $bad, 'ok' => !$bad];
}
/** Retensi: 200 versi terakhir per user+proyek, umur <= 180 hari. */
function sds_retention(): array {
    $cut = gmdate('c', time() - PP_SDS_RETAIN_DAYS * 86400); $del = 0; $pdo = sds_pdo();
    if ($pdo) { $del += (int)$pdo->exec('DELETE FROM records WHERE created_at < ' . $pdo->quote($cut));
        $q = $pdo->query('SELECT user_or_session_id, project_id, MAX(version) mv FROM records GROUP BY user_or_session_id, project_id HAVING COUNT(*) > ' . (int)PP_SDS_RETAIN_N);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $g) { $st = $pdo->prepare('DELETE FROM records WHERE user_or_session_id=? AND project_id=? AND version <= ?');
            $st->execute([$g['user_or_session_id'], $g['project_id'], (int)$g['mv'] - PP_SDS_RETAIN_N]); $del += $st->rowCount(); }
        return ['deleted' => $del]; }
    foreach (glob(sds_root() . '/json/*/*', GLOB_ONLYDIR) ?: [] as $d) { $fs = glob($d . '/v*.json') ?: []; rsort($fs);
        foreach ($fs as $i => $f) if ($i >= PP_SDS_RETAIN_N || filemtime($f) < time() - PP_SDS_RETAIN_DAYS * 86400) { @unlink($f); $del++; } }
    return ['deleted' => $del];
}


/* ---------------- migrasi data lama (sekali, idempoten) + diagnostik tulis ----------------
 * Riwayat datastore sebelumnya (saved_data_history/*.json dari pp_store_commit lama, saved/records dari datastore 21 KB)
 * diimpor sebagai record milik scope 'legacy' sehingga tidak hilang dan tidak bercampur dengan data user. */
function sds_migrate_legacy(): array {
    $mark = sds_dir('meta') . DIRECTORY_SEPARATOR . 'legacy_migrated.json'; if (is_file($mark)) return ['already' => true];
    $n = 0; $ctx = ['uid' => 'legacy', 'tab' => '', 'project' => 'legacy', 'run' => ''];
    foreach (array_merge(glob(__DIR__ . '/saved_data_history/*.json') ?: [], glob(sds_root() . '/records/*.json') ?: []) as $f) {
        $d = json_decode((string)@file_get_contents($f), true); if (!is_array($d)) continue;
        $in = $d['input'] ?? ($d['payload'] ?? null); if (!is_array($in)) continue;
        $w = null; if (sds_commit('LEGACY_' . strtoupper((string)($d['event'] ?? 'RECORD')), $in, is_array($d['output'] ?? null) ? $d['output'] : null, $ctx, ['source_file' => basename($f)], $w)) $n++; }
    $w = null; sds_atomic_write($mark, (string)json_encode(['at' => gmdate('c'), 'imported' => $n]), $w);
    return ['imported' => $n];
}
function sds_writable(): array { $root = sds_root(); $t = $root . DIRECTORY_SEPARATOR . '.w' . getmypid(); $ok = @file_put_contents($t, '1') === 1; @unlink($t);
    return ['ok' => $ok, 'path' => $root, 'hint' => $ok ? null : 'Folder saved/ tidak dapat ditulis oleh user web server (XAMPP: izin folder htdocs; Linux: chown www-data / chmod 2775).']; }

/* ---------------- kompatibilitas: pp_store_commit (run.php) ---------------- */
if (!function_exists('pp_store_commit')) {
function pp_store_commit(string $event, array $input, ?array $output = null, array $meta = [], ?string &$err = null): array {
    $err = null; $ctx = sds_ctx($input); $r = sds_commit($event, $input, $output, $ctx, $meta, $err);
    if ($r === null) return ['ok' => false, 'error' => $err];
    return ['ok' => true, 'revision' => (int)$r['version'], 'id' => $r['record_id'], 'backend' => sds_backend(),
            'scope' => ['uid' => $r['user_or_session_id'], 'project' => $r['project_id'], 'tab' => $r['tab_id'], 'run' => $r['run_id']]];
}}

/* ---------------- HTTP ---------------- */
function sds_http(string $mode): void {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); $ctx = sds_ctx([]); $uid = $ctx['uid']; $out = ['ok' => true, 'mode' => $mode, 'backend' => sds_backend()];
    $why = null;
    if ($mode === 'store_list') $out['records'] = sds_list($uid, ['project' => sds_clean_id($_GET['project'] ?? ''), 'kind' => sds_clean_id($_GET['kind'] ?? ''), 'limit' => (int)($_GET['limit'] ?? 100)]);
    elseif ($mode === 'store_load') { $r = sds_load($uid, (string)($_GET['id'] ?? '')); if (!$r) { http_response_code(404); $out = ['ok' => false, 'error' => 'NOT_FOUND_OR_NOT_OWNED']; } else $out['record'] = $r; }
    elseif ($mode === 'store_latest') $out['latest'] = sds_latest_input($uid);
    elseif ($mode === 'store_meta') { $b = json_decode((string)file_get_contents('php://input'), true) ?: []; $out['ok'] = sds_update_meta($uid, (string)($_GET['id'] ?? ''), (array)$b, $why); $out['error'] = $why; }
    elseif ($mode === 'store_delete') { $out['ok'] = sds_delete($uid, (string)($_GET['id'] ?? ''), (string)($_GET['confirm'] ?? ''), $why); $out['error'] = $why; }
    elseif ($mode === 'store_integrity') { $out['integrity'] = sds_integrity($uid); $out['writable'] = sds_writable(); $out['legacy'] = rs_enabled() ? ['skipped' => 'MYSQL_CANONICAL_LEGACY_READ_ONLY'] : sds_migrate_legacy(); }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}

/* =============================================================================================
 *  REPORT STORE MySQL (schema pp-report-mysql-1, database/schema.sql) — DATASTORE KANONIK REPORT
 *
 *  Planning Report, Actual Report, Monitoring Daily Plan, input snapshot, hasil simulasi, versi
 *  report, 48 monitoring row, save/audit event dan konteks user/tab/project/run tersimpan di MySQL
 *  dan langsung persisten saat transaksi Save COMMIT (tanpa commit repository).
 *
 *  Link Report memakai report_key (opaque, immutable): index.php?report=<report_key>. Nama plan dan
 *  tanggal hanya untuk tampilan/filter. Loader membuka report_entries -> simulation_results ->
 *  input_snapshots lewat foreign key dan memverifikasi sha256 byte yang tersimpan.
 *
 *  Kredensial TIDAK ada di source. Urutan sumber konfigurasi:
 *    1. env DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD (bila DB_NAME + DB_USER diset);
 *    2. file PHP yang me-return array host/port/name/user/password[/report_store]:
 *       env PP_DB_CONFIG = path file, atau (tanpa setting server apa pun) <folder aplikasi>/config/db.php.
 *       File config dibuka dengan konstanta PP_DB_CONFIG_LOADING; template config/db.example.php menolak request
 *       URL langsung (HTTP 404, tanpa isi) dan config/.htaccess menolaknya bila AllowOverride aktif.
 *  Tanpa konfigurasi, aplikasi tetap memakai store lama (fallback) dan UI menampilkan statusnya.
 *  PP_REPORT_STORE=legacy (env) atau 'report_store' => 'legacy' (file config) memaksa store lama (rollback aplikasi).
 * ============================================================================================= */
if (!defined('PP_RS_SCHEMA')) define('PP_RS_SCHEMA', 'pp-report-mysql-1');

function rs_env(string $k): ?string {
    $v = getenv($k);
    if (($v === false || $v === '') && isset($_SERVER[$k]) && is_string($_SERVER[$k])) $v = $_SERVER[$k];
    if (($v === false || $v === '') && isset($_SERVER['REDIRECT_' . $k]) && is_string($_SERVER['REDIRECT_' . $k])) $v = $_SERVER['REDIRECT_' . $k];
    return ($v === false || $v === '') ? null : (string)$v;
}
function rs_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $cfg = null;
    /* File config: env PP_DB_CONFIG atau default <folder aplikasi>/config/db.php (tanpa perubahan setting Apache/FPM). */
    $f = rs_env('PP_DB_CONFIG') ?? (__DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php');
    $a = null;
    if (is_file($f)) { if (!defined('PP_DB_CONFIG_LOADING')) define('PP_DB_CONFIG_LOADING', true); $x = include $f; if (is_array($x)) $a = $x; }
    $GLOBALS['__rs_cfg_file'] = is_array($a) ? array_diff_key($a, ['password' => 1]) : [];   // kunci non-rahasia (mis. report_admins)
    if (strtolower((string)(rs_env('PP_REPORT_STORE') ?? ($a['report_store'] ?? ''))) === 'legacy') return null;
    $c = ['host' => rs_env('DB_HOST'), 'port' => rs_env('DB_PORT'), 'name' => rs_env('DB_NAME'), 'user' => rs_env('DB_USER'),
          'password' => rs_env('DB_PASSWORD'), 'socket' => rs_env('DB_SOCKET')];
    if (($c['name'] === null || $c['user'] === null) && is_array($a)) {
        foreach (['host', 'port', 'name', 'user', 'password', 'socket'] as $k) if (isset($a[$k]) && $a[$k] !== '') $c[$k] = (string)$a[$k];
    }
    if ($c['name'] === null || $c['user'] === null) return null;
    if ($c['host'] === null) $c['host'] = '127.0.0.1';
    if ($c['port'] === null) $c['port'] = '3306';
    if ($c['password'] === null) $c['password'] = '';
    $cfg = $c;
    return $cfg;
}
function rs_enabled(): bool { return rs_config() !== null; }
function &rs_last_error(): ?string { static $e = null; return $e; }
/** Satu koneksi per request. Gagal -> null (+ rs_last_error), TIDAK pernah melempar ke pemanggil Run. */
function rs_pdo(bool $fresh = false): ?PDO {
    static $pdo = null;
    if ($pdo !== null && !$fresh) return $pdo;
    $c = rs_config(); if ($c === null) return null;
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) { $e =& rs_last_error(); $e = 'PDO_MYSQL_DRIVER_MISSING'; return null; }
    $dsn = $c['socket'] !== null ? ('mysql:unix_socket=' . $c['socket'] . ';dbname=' . $c['name'] . ';charset=utf8mb4')
                                 : ('mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';dbname=' . $c['name'] . ';charset=utf8mb4');
    try {
        $p = new PDO($dsn, $c['user'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5, PDO::ATTR_STRINGIFY_FETCHES => false]);
        $p->exec("SET time_zone = '+00:00'");
        $p->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $p->exec('SET SESSION innodb_lock_wait_timeout = 15');
        $pdo = $p;
    } catch (Throwable $ex) { $e =& rs_last_error(); $e = 'DB_CONNECT_FAILED: ' . preg_replace('~password[^ ]*~i', 'password=***', $ex->getMessage()); $pdo = null; }
    return $pdo;
}
function rs_status(): array {
    if (!rs_enabled()) return ['enabled' => false, 'backend' => 'legacy-' . sds_backend(), 'reason' => strtolower((string)rs_env('PP_REPORT_STORE')) === 'legacy' ? 'PP_REPORT_STORE=legacy' : 'config/db.php tidak ada/belum diisi, atau report_store=legacy'];
    $p = rs_pdo();
    if (!$p) return ['enabled' => true, 'ok' => false, 'backend' => 'mysql', 'error' => rs_last_error()];
    $v = null; try { $v = $p->query("SELECT version FROM schema_migrations WHERE version = '" . PP_RS_SCHEMA . "'")->fetchColumn(); } catch (Throwable $e) { $v = null; }
    return ['enabled' => true, 'ok' => $v === PP_RS_SCHEMA, 'backend' => 'mysql', 'schema' => $v ?: null, 'expected_schema' => PP_RS_SCHEMA,
            'error' => $v === PP_RS_SCHEMA ? null : 'SCHEMA_NOT_IMPORTED (jalankan database/schema.sql)'];
}
function rs_now(): string { $t = microtime(true); $s = (int)floor($t); return gmdate('Y-m-d H:i:s', $s) . sprintf('.%06d', (int)round(($t - $s) * 1e6) % 1000000); }
function rs_key(string $prefix): string { return $prefix . '_' . bin2hex(random_bytes(12)); }
function rs_norm_name(string $s): string {
    $s = trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function rs_is_dup(Throwable $e): bool { return $e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062; }
function rs_is_retryable(Throwable $e): bool { $c = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0; return $c === 1213 || $c === 1205; }
function rs_project_key(array $req = []): string {
    $k = sds_clean_id($req['project_key'] ?? ($_GET['project'] ?? ''), 128);
    if ($k === '') $k = sds_clean_id((string)rs_env('PP_PROJECT_KEY'), 128);
    return $k !== '' ? $k : 'default';
}
/* ---- upsert identitas: INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) (atomik, tanpa race) ---- */
function rs_user_id(PDO $p, string $uid, ?string $name = null): int {
    $uid = $uid !== '' ? $uid : 'anonymous'; $now = rs_now();
    $p->prepare('INSERT INTO users (external_uid, display_name, created_at, updated_at) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = VALUES(updated_at), display_name = COALESCE(VALUES(display_name), display_name)')
      ->execute([$uid, $name, $now, $now]);
    return (int)$p->lastInsertId();
}
function rs_tab_id(PDO $p, int $userId, string $tab): ?int {
    if ($tab === '') return null; $now = rs_now();
    $p->prepare('INSERT INTO browser_tabs (user_id, tab_key, created_at, last_seen_at) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), last_seen_at = VALUES(last_seen_at)')->execute([$userId, $tab, $now, $now]);
    return (int)$p->lastInsertId();
}
function rs_project_id(PDO $p, string $key, ?int $userId): int {
    $now = rs_now();
    $p->prepare('INSERT INTO projects (project_key, owner_user_id, project_name, created_at, updated_at) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([$key, $userId, $key === 'default' ? 'Daily Plan Simulation' : $key, $now, $now]);
    return (int)$p->lastInsertId();
}
function rs_run_key(string $uid, string $tab, string $clientRid): string { return substr(($uid !== '' ? $uid : 'anonymous') . ':' . ($tab !== '' ? $tab : '-') . ':' . $clientRid, 0, 128); }
function rs_run_id(PDO $p, array $r): int {
    $now = rs_now();
    $p->prepare('INSERT INTO simulation_runs (run_key, client_run_id, project_id, user_id, tab_id, mode, status, state_revision, payload_hash, job_id,
                     candidate_fingerprint, started_at, finished_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id),
                     status = IF(VALUES(status) IN (\'FINAL\',\'TERMINAL\',\'ERROR\',\'CANCELLED\'), VALUES(status), status),
                     job_id = COALESCE(VALUES(job_id), job_id),
                     candidate_fingerprint = COALESCE(VALUES(candidate_fingerprint), candidate_fingerprint),
                     finished_at = COALESCE(VALUES(finished_at), finished_at)')
      ->execute([$r['run_key'], substr((string)$r['client_run_id'], 0, 64), $r['project_id'], $r['user_id'], $r['tab_id'], $r['mode'], $r['status'],
                 (int)($r['state_revision'] ?? 0), $r['payload_hash'], $r['job_id'] ?? null, $r['fingerprint'] ?? null,
                 $r['started_at'] ?? $now, $r['finished_at'] ?? null, $now]);
    return (int)$p->lastInsertId();
}
function rs_snapshot_id(PDO $p, int $runId, string $json, string $schemaVersion = 'pp-input-v1'): array {
    $h = hash('sha256', $json); $now = rs_now();
    $p->prepare('INSERT INTO input_snapshots (snapshot_key, run_id, payload_hash, schema_version, payload_bytes, payload_json, created_at) VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')->execute([rs_key('snp'), $runId, $h, $schemaVersion, strlen($json), $json, $now]);
    $id = (int)$p->lastInsertId();
    $k = $p->prepare('SELECT snapshot_key FROM input_snapshots WHERE id = ?'); $k->execute([$id]);
    return ['id' => $id, 'key' => (string)$k->fetchColumn(), 'hash' => $h];
}
/** Ringkasan terstruktur dari output engine (kolom pencarian; JSON lengkap tetap disimpan apa adanya). */
function rs_result_facts(array $out): array {
    $info = (array)($out['info'] ?? []); $rs = (array)($info['Run Status'] ?? []);
    $num = function ($v) { return is_numeric($v) ? (float)$v : null; };
    $proof = $out['proof_binding'] ?? ($info['Proof Binding'] ?? ($out['merit_proof'] ?? null));
    $ph = null; if (is_array($proof)) { $ph = $proof['proof_hash'] ?? ($proof['candidate_hash'] ?? null); if (is_array($ph)) $ph = hash('sha256', json_encode($ph)); }
    $st = strtoupper((string)($out['status'] ?? ''));
    return ['cp' => $num($info['Cost Production (USD/MWh)'] ?? null), 'hr' => $num($info['Heatrate'] ?? null),
            'gas' => $num($info['Gas Fuel Total (BBTUD)'] ?? null), 'rows' => is_array($out['data'] ?? null) ? count($out['data']) : 0,
            'fingerprint' => isset($rs['candidate_fingerprint']) ? substr((string)$rs['candidate_fingerprint'], 0, 128) : (isset($out['candidate_fingerprint']) ? substr((string)$out['candidate_fingerprint'], 0, 128) : null),
            'proof_hash' => $ph !== null ? substr((string)$ph, 0, 128) : null,
            'status' => strpos($st, 'TERMINAL') === 0 ? 'TERMINAL' : (($out['result'] ?? 'ok') === 'error' ? 'ERROR' : 'FINAL')];
}
function rs_result_id(PDO $p, int $runId, int $snapId, string $json, array $facts, ?string $summaryJson): array {
    $h = hash('sha256', $json); $now = rs_now();
    $p->prepare('INSERT INTO simulation_results (result_key, run_id, input_snapshot_id, status, cp_usd_per_mwh, heat_rate_btu_per_kwh, gas_used_bbtud, rows_count,
                     candidate_fingerprint, proof_hash, result_sha256, result_bytes, result_json, summary_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
      ->execute([rs_key('res'), $runId, $snapId, $facts['status'], $facts['cp'], $facts['hr'], $facts['gas'], $facts['rows'], $facts['fingerprint'],
                 $facts['proof_hash'], $h, strlen($json), $json, $summaryJson, $now]);
    $id = (int)$p->lastInsertId();
    $k = $p->prepare('SELECT result_key FROM simulation_results WHERE id = ?'); $k->execute([$id]);
    return ['id' => $id, 'key' => (string)$k->fetchColumn(), 'hash' => $h];
}
/** Nama sama (project + type + tanggal + nama normalisasi) -> lock baris (FOR UPDATE), versi +1, pointer aktif dipindah,
 *  report_versions bertambah. Nama berbeda -> entry baru versi 1. Race insert ditangani dengan duplicate-key -> lock ulang. */
function rs_report_put(PDO $p, array $a): array {
    $norm = rs_norm_name($a['plan_name']); $now = rs_now();
    $sel = $p->prepare('SELECT id, report_key, version_no, created_by FROM report_entries
                        WHERE project_id = ? AND report_type = ? AND plan_date = ? AND plan_name_normalized = ? AND active_marker = 0 FOR UPDATE');
    $args = [$a['project_id'], $a['report_type'], $a['plan_date'], $norm];
    $sel->execute($args); $row = $sel->fetch();
    $created = false;
    if (!$row) {
        $key = rs_key('rpt');
        try {
            $p->prepare('INSERT INTO report_entries (report_key, project_id, report_type, plan_date, plan_name, plan_name_normalized, remark, result_id, input_snapshot_id,
                             monitoring_source_report_id, created_by, updated_by, created_at, updated_at, version_no, is_deleted, active_marker)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,0,0)')
              ->execute([$key, $a['project_id'], $a['report_type'], $a['plan_date'], $a['plan_name'], $norm, $a['remark'], $a['result_id'], $a['snapshot_id'],
                         $a['monitoring_source_id'] ?? null, $a['user_id'], $a['user_id'], $now, $now]);
            $row = ['id' => (int)$p->lastInsertId(), 'report_key' => $key, 'version_no' => 1]; $created = true;
        } catch (Throwable $e) {
            if (!rs_is_dup($e)) throw $e;
            /* save bersamaan dengan nama sama baru saja meng-commit entry ini: lock dan jadikan versi berikutnya */
            $sel->execute($args); $row = $sel->fetch();
            if (!$row) throw $e;
        }
    }
    $ver = (int)$row['version_no']; $active = true;
    if (!$created) {
        /* nomor versi berikutnya = MAX riwayat + 1 (di bawah lock baris entry) — riwayat impor legacy tidak pernah bertabrakan */
        $mx = $p->prepare('SELECT COALESCE(MAX(version_no), 0) FROM report_versions WHERE report_entry_id = ?'); $mx->execute([(int)$row['id']]);
        $ver = (int)$mx->fetchColumn() + 1;
        $active = empty($a['history_only']);
        if ($active)
            $p->prepare('UPDATE report_entries SET result_id = ?, input_snapshot_id = ?, plan_name = ?, remark = ?, updated_by = ?, updated_at = ?, version_no = ?,
                             monitoring_source_report_id = COALESCE(?, monitoring_source_report_id) WHERE id = ?')
              ->execute([$a['result_id'], $a['snapshot_id'], $a['plan_name'], $a['remark'], $a['user_id'], $a['saved_at'] ?? $now, $ver, $a['monitoring_source_id'] ?? null, (int)$row['id']]);
    } elseif (!empty($a['saved_at'])) {
        $p->prepare('UPDATE report_entries SET created_at = ?, updated_at = ? WHERE id = ?')->execute([$a['saved_at'], $a['saved_at'], (int)$row['id']]);
    }
    $p->prepare('INSERT INTO report_versions (report_entry_id, version_no, result_id, input_snapshot_id, saved_by, saved_at, save_request_id, change_reason) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([(int)$row['id'], $ver, $a['result_id'], $a['snapshot_id'], $a['user_id'], $a['saved_at'] ?? $now, $a['request_id'],
                 $a['change_reason'] ?? ($created ? 'CREATE' : ($active ? 'REPLACE_SAME_NAME' : 'HISTORY_ONLY'))]);
    return ['id' => (int)$row['id'], 'report_key' => (string)$row['report_key'], 'version_no' => $ver, 'created' => $created, 'replaced' => !$created && $active, 'active' => $active];
}
/** 48 baris Monitoring untuk versi aktif. row_status MONITORING_SELECTED = warna oranye Monitoring; LOCKED = TIME PASSED 'Y'. */
function rs_monitoring_rows(PDO $p, int $entryId, int $ver, string $planDate, array $out, array $in): int {
    $rows = array_values((array)($out['data'] ?? []));
    if (count($rows) !== 48) throw new RuntimeException('MONITORING_ROWS_NOT_48: hasil memuat ' . count($rows) . ' row');
    $locked = (array)($in['data3']['modeling']['monitoring_daily_plan']['locked_rows'] ?? []);
    $cut = (int)($in['data3']['modeling']['monitoring_daily_plan']['time_passed_cutoff_row'] ?? 0);
    $p->prepare('DELETE FROM monitoring_rows WHERE report_entry_id = ?')->execute([$entryId]);
    $ins = $p->prepare('INSERT INTO monitoring_rows (report_entry_id, version_no, row_no, time_slot, row_status, row_color, row_json) VALUES (?,?,?,?,?,?,?)');
    $base = strtotime($planDate . ' 00:00:00 UTC');
    foreach ($rows as $i => $r) {
        $no = $i + 1;
        $isLocked = isset($locked[$i]) || isset($locked[(string)$i]) || isset($locked[$no]) || ($cut > 0 && $no <= $cut);
        $ins->execute([$entryId, $ver, $no, gmdate('Y-m-d H:i:s', $base + $no * 1800), $isLocked ? 'LOCKED' : 'MONITORING_SELECTED', '#fb923c',
                       json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
    }
    return count($rows);
}
function rs_event(PDO $p, string $table, array $e): void {
    $p->prepare('INSERT INTO ' . ($table === 'audit' ? 'audit_events' : 'save_events') . ' (event_key, user_id, project_id, run_id, report_entry_id, '
        . ($table === 'audit' ? '' : 'tab_id, ') . 'event_type, request_id, payload_hash, status, message, ' . ($table === 'audit' ? '' : 'response_json, ') . 'created_at) VALUES ('
        . ($table === 'audit' ? '?,?,?,?,?,?,?,?,?,?,?' : '?,?,?,?,?,?,?,?,?,?,?,?,?') . ')')
      ->execute($table === 'audit'
        ? [$e['event_key'], $e['user_id'] ?? null, $e['project_id'] ?? null, $e['run_id'] ?? null, $e['report_entry_id'] ?? null, $e['event_type'], $e['request_id'], $e['payload_hash'] ?? null, $e['status'], $e['message'] ?? null, rs_now()]
        : [$e['event_key'], $e['user_id'], $e['project_id'] ?? null, $e['run_id'] ?? null, $e['report_entry_id'] ?? null, $e['tab_id'] ?? null, $e['event_type'], $e['request_id'], $e['payload_hash'] ?? null, $e['status'], $e['message'] ?? null, $e['response_json'] ?? null, rs_now()]);
}
function rs_request_id(array $req): string { $r = sds_clean_id($req['request_id'] ?? '', 128); return $r !== '' ? $r : rs_key('req'); }
function rs_save_event_key(string $projectKey, string $requestId, string $kind = 'save'): string { return $kind . ':' . sha1($projectKey . '|' . $requestId); }

/**
 * V15.21 GERBANG FINAL DI SERVER (bukan hanya UI). Report hanya boleh menyimpan hasil yang:
 *   - FINAL menurut kontrak backend (release_allowed, publish/save diizinkan, bukan pratinjau), 48 row;
 *   - proof terikat ke baris yang disimpan (Proof Binding candidate_hash = hash baris) -> selain itu PROOF_STALE;
 *   - DIHITUNG dengan Actual Gas yang ada di snapshot input: echo engine Act_PGN / Act_FF_J / Act_FF_M per jam harus sama dengan
 *     actual_pgn_total / actual_energy_jababeka / actual_energy_mm2100 (menutup jalur lama: hasil lama + actual baru ditempel klien).
 * Mengembalikan null bila lolos, atau ['code','message']. PP_REPORT_FINAL_GUARD=0 menonaktifkan (rollback darurat).
 */
function rs_final_guard(array $in, array $out): ?array {
    if ((string)rs_env('PP_REPORT_FINAL_GUARD') === '0') return null;
    $rows = array_values((array)($out['data'] ?? []));
    if (count($rows) !== 48) return ['code' => 'RESULT_NOT_FINAL', 'message' => 'hasil memuat ' . count($rows) . ' row, bukan 48'];
    $rg = (array)($out['release_gate'] ?? []);
    if (($rg['release_allowed'] ?? null) !== true || ($out['publish_allowed'] ?? true) === false || ($out['preliminary'] ?? false) === true
        || ($out['final_result_visible'] ?? true) === false || ($out['save_allowed'] ?? true) === false || ($out['ok'] ?? true) === false)
        return ['code' => 'RESULT_NOT_FINAL', 'message' => 'gerbang rilis tidak mengizinkan hasil ini (' . implode(', ', (array)($rg['blocking_reasons'] ?? [])) . ')'];
    $pb = (array)($out['info']['Proof Binding'] ?? []);
    if (($pb['bound_to_rendered'] ?? true) === false) return ['code' => 'PROOF_STALE', 'message' => 'proof tidak terikat ke baris hasil'];
    if (isset($pb['candidate_hash']) && function_exists('pp_v1517_candidate_hash') && !hash_equals((string)$pb['candidate_hash'], pp_v1517_candidate_hash($rows)))
        return ['code' => 'PROOF_STALE', 'message' => 'candidate_hash proof berbeda dengan baris yang disimpan'];
    $m = (array)($in['data3']['modeling'] ?? []);
    $cell = function ($arr, int $i): ?float { $v = $arr[$i] ?? ''; return ($v === '' || $v === null || !is_numeric($v)) ? null : (float)$v; };
    foreach (['actual_pgn_total' => 'Act_PGN', 'actual_energy_jababeka' => 'Act_FF_J', 'actual_energy_mm2100' => 'Act_FF_M'] as $k => $col) {
        $arr = array_values((array)($m[$k] ?? []));
        for ($i = 0; $i < 48; $i += 2) {
            $a = $cell($arr, $i); $b = $cell($arr, $i + 1); $exp = ($a !== null && $b !== null) ? $a + $b : ($a ?? $b);
            $got = $rows[$i][$col] ?? null; $got = ($got === '' || $got === null) ? null : (float)$got;
            if (($exp === null) !== ($got === null) || ($exp !== null && abs($exp - $got) > 1e-6))
                return ['code' => 'ACTUAL_GAS_RESULT_MISMATCH', 'message' => sprintf('%s jam %d: snapshot %s, hasil dihitung dengan %s — hasil bukan rerun untuk Actual Gas ini',
                    $col, $i / 2 + 1, $exp === null ? 'kosong' : (string)$exp, $got === null ? 'kosong' : (string)$got)];
        }
    }
    return null;
}
/**
 * SAVE Report (Planning / Monitoring), SATU transaksi:
 *   $req = ['request_id','project_key','run_key'(client Run ID),'state_revision','mode','entries'=>[[ 'role'=>'plan'|'monitoring',
 *           'report_type'=>'PLANNING'|'MONITORING','plan_date','plan_name','remark','input_json','output_json','summary'=>{..} ], ...]]
 *   input_json/output_json = string JSON persis dari browser (byte-exact; tidak di-decode/encode ulang).
 *   Entry 'monitoring' otomatis menunjuk entry 'plan' di request yang sama (monitoring_source_report_id) dan menulis 48 row.
 *   Gagal di tahap mana pun -> ROLLBACK seluruhnya + save_events ROLLED_BACK. Request ID yang sama -> jawaban commit pertama.
 */
/* USER FIXED LOAD (pp-fix-load-1): indeks relasional Fix Load user per (snapshot versi, unit, row), ditulis di transaksi Save yang SAMA
 * dengan snapshot/hasil/report. Isi kanonik tetap snapshot input (modeling.unit_fix_load), jadi Reload/Report membuka nilai yang identik.
 * Tabel belum dimigrasi (1146) -> dilewati tanpa membatalkan Save (Fix tetap tersimpan di snapshot). Mengembalikan jumlah baris ditulis, atau null. */
function rs_fix_loads(PDO $p, array $k, array $in): ?int {
    $m = (array)($in['data3']['modeling'] ?? []); $rows = [];
    foreach ((array)($m['unit_fix_load'] ?? []) as $u => $rules) foreach ((array)$rules as $ru) { $s = max(1, (int)($ru['start'] ?? 1)); $e = min(48, (int)($ru['stop'] ?? 48));
        for ($r = $s; $r <= $e; $r++) $rows[strtolower((string)$u) . '@' . $r] = ['u' => strtoupper(substr(preg_replace('~[^A-Za-z0-9]~', '', (string)$u), 0, 8)), 'r' => $r, 'v' => (float)($ru['value'] ?? 0),
            'op' => isset($ru['op_id']) ? substr(preg_replace('~[^A-Za-z0-9_\-]~', '', (string)$ru['op_id']), 0, 64) : null,
            'src' => in_array(($ru['source'] ?? ''), ['CTRL_CLICK', 'FIX_LOAD_MENU', 'IMPORT'], true) ? $ru['source'] : 'UNKNOWN',
            'c' => isset($ru['created_at']) ? (string)$ru['created_at'] : null, 'up' => isset($ru['updated_at']) ? (string)$ru['updated_at'] : null]; }
    if (!$rows) return 0;
    $ts = function ($v) { $t = is_numeric($v) ? (float)$v / 1000.0 : (is_string($v) && $v !== '' ? strtotime($v) : false); return $t ? gmdate('Y-m-d H:i:s', (int)$t) . '.000000' : rs_now(); };
    try {
        $ins = $p->prepare('INSERT INTO user_fixed_loads (fix_load_key, input_snapshot_id, project_id, user_id, tab_id, report_type, plan_date, unit_id, row_index, period, fixed_mw, operation_id, source, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $x) { if ($x['v'] < 0 || !is_finite($x['v'])) continue;
            $min = ($x['r'] * 30) % 1440; $per = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
            $ins->execute([rs_key('fix'), $k['snapshot_id'], $k['project_id'], $k['user_id'], $k['tab_id'], $k['report_type'], $k['plan_date'], $x['u'], $x['r'], $per, round($x['v'], 4), $x['op'], $x['src'], $ts($x['c']), $ts($x['up'] ?? $x['c'])]); }
        return count($rows);
    } catch (PDOException $ex) { if ((int)($ex->errorInfo[1] ?? 0) === 1146) return null; throw $ex; }
}
function rs_report_save(array $req, array $ctx, array $opts = []): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $rid = rs_request_id($req); $pkey = rs_project_key($req); $evKey = rs_save_event_key($pkey, $rid);
    $prev = $p->prepare("SELECT response_json FROM save_events WHERE event_key = ? AND status = 'COMMITTED'"); $prev->execute([$evKey]);
    if (($pr = $prev->fetchColumn()) !== false) { $d = json_decode((string)$pr, true); if (is_array($d)) { $d['idempotent_replay'] = true; return $d; } }
    $entries = array_values((array)($req['entries'] ?? []));
    if (!$entries) return ['ok' => false, 'error' => 'NO_ENTRIES'];
    $prep = [];
    foreach ($entries as $i => $e) {
        $type = strtoupper((string)($e['report_type'] ?? 'PLANNING'));
        if (!in_array($type, ['PLANNING', 'MONITORING'], true)) return ['ok' => false, 'error' => 'BAD_REPORT_TYPE'];
        $date = (string)($e['plan_date'] ?? '');
        if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) return ['ok' => false, 'error' => 'BAD_PLAN_DATE'];
        $name = trim((string)($e['plan_name'] ?? ''));
        if ($name === '' || strlen($name) > 255) return ['ok' => false, 'error' => 'BAD_PLAN_NAME'];
        $inJ = (string)($e['input_json'] ?? ''); $outJ = (string)($e['output_json'] ?? '');
        $in = json_decode($inJ, true); $out = json_decode($outJ, true);
        if (!is_array($in) || !isset($in['data3']['modeling'])) return ['ok' => false, 'error' => 'BAD_INPUT_SNAPSHOT'];
        if (!is_array($out) || !is_array($out['data'] ?? null) || !count($out['data'])) return ['ok' => false, 'error' => 'BAD_RESULT (tidak ada row hasil)'];
        if (isset($in['data3']['modeling']['report_planning'])) return ['ok' => false, 'error' => 'SNAPSHOT_CONTAINS_REPORT_ARCHIVE'];
        if (($g = rs_final_guard($in, $out)) !== null) return ['ok' => false, 'error' => $g['code'], 'message' => $g['message'], 'entry' => $i];
        $sum = isset($e['summary']) && is_array($e['summary']) ? json_encode($e['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $prep[] = ['role' => (string)($e['role'] ?? ($type === 'MONITORING' ? 'monitoring' : 'plan')), 'type' => $type, 'date' => $date, 'name' => $name,
                   'remark' => (string)($e['remark'] ?? ''), 'inJ' => $inJ, 'outJ' => $outJ, 'in' => $in, 'out' => $out, 'sum' => $sum];
    }
    $clientRid = sds_clean_id($req['run_key'] ?? '', 64);
    $runKey = rs_run_key($ctx['uid'], $ctx['tab'], $clientRid !== '' ? $clientRid : ('save-' . $rid));
    $mode = strtoupper((string)($req['mode'] ?? '')) === 'MAXIMUM_REVIEW' ? 'MAXIMUM_REVIEW' : 'FASTEST';
    $attempt = 0;
    while (true) {
        $attempt++; $stage = 'begin'; $ids = ['user' => null, 'project' => null, 'run' => null, 'tab' => null];
        try {
            $p->beginTransaction();
            $stage = 'context';
            $ids['user'] = rs_user_id($p, $ctx['uid'], isset($req['display_name']) ? substr((string)$req['display_name'], 0, 255) : null);
            $ids['tab'] = rs_tab_id($p, $ids['user'], $ctx['tab']);
            $ids['project'] = rs_project_id($p, $pkey, $ids['user']);
            $f0 = rs_result_facts($prep[0]['out']);
            $ids['run'] = rs_run_id($p, ['run_key' => $runKey, 'client_run_id' => $clientRid, 'project_id' => $ids['project'], 'user_id' => $ids['user'], 'tab_id' => $ids['tab'],
                'mode' => $mode, 'status' => $f0['status'], 'state_revision' => (int)($req['state_revision'] ?? 0), 'payload_hash' => hash('sha256', $prep[0]['inJ']),
                'fingerprint' => $f0['fingerprint'], 'finished_at' => rs_now()]);
            $resp = ['ok' => true, 'request_id' => $rid, 'project_key' => $pkey, 'run_key' => $runKey, 'entries' => [], 'backend' => 'mysql', 'schema' => PP_RS_SCHEMA];
            $planEntryId = null;
            foreach ($prep as $e) {
                $stage = 'snapshot:' . $e['role'];
                $snap = rs_snapshot_id($p, $ids['run'], $e['inJ']);
                $stage = 'result:' . $e['role'];
                $facts = rs_result_facts($e['out']);
                $res = rs_result_id($p, $ids['run'], $snap['id'], $e['outJ'], $facts, $e['sum']);
                $stage = 'report:' . $e['role'];
                $ent = rs_report_put($p, ['project_id' => $ids['project'], 'report_type' => $e['type'], 'plan_date' => $e['date'], 'plan_name' => $e['name'],
                    'remark' => $e['remark'], 'result_id' => $res['id'], 'snapshot_id' => $snap['id'], 'user_id' => $ids['user'], 'request_id' => $rid,
                    'monitoring_source_id' => $e['type'] === 'MONITORING' ? $planEntryId : null]);
                if ($e['type'] === 'PLANNING') $planEntryId = $ent['id'];
                $nRows = 0;
                if ($e['type'] === 'MONITORING') { $stage = 'monitoring_rows'; if (($opts['fail_at'] ?? '') === 'monitoring_rows') throw new RuntimeException('TEST_INJECTED_FAILURE@monitoring_rows');
                    $nRows = rs_monitoring_rows($p, $ent['id'], $ent['version_no'], $e['date'], $e['out'], $e['in']); }
                $stage = 'user_fixed_loads:' . $e['role'];
                $nFix = rs_fix_loads($p, ['snapshot_id' => $snap['id'], 'project_id' => $ids['project'], 'user_id' => $ids['user'], 'tab_id' => $ids['tab'], 'report_type' => $e['type'], 'plan_date' => $e['date']], $e['in']);
                $resp['entries'][] = ['role' => $e['role'], 'report_type' => $e['type'], 'report_key' => $ent['report_key'], 'report_entry_id' => $ent['id'],
                    'version_no' => $ent['version_no'], 'created' => $ent['created'], 'replaced' => $ent['replaced'], 'plan_date' => $e['date'], 'plan_name' => $e['name'],
                    'result_key' => $res['key'], 'result_id' => $res['id'], 'snapshot_key' => $snap['key'], 'input_snapshot_id' => $snap['id'],
                    'result_sha256' => $res['hash'], 'payload_hash' => $snap['hash'], 'monitoring_rows' => $nRows, 'user_fixed_loads' => $nFix, 'cp' => $facts['cp'], 'hr' => $facts['hr']];
            }
            if (($opts['fail_at'] ?? '') === 'before_commit') throw new RuntimeException('TEST_INJECTED_FAILURE@before_commit');
            $stage = 'save_event';
            $resp['saved_at_utc'] = rs_now();
            rs_event($p, 'save', ['event_key' => $evKey, 'user_id' => $ids['user'], 'project_id' => $ids['project'], 'run_id' => $ids['run'],
                'report_entry_id' => $resp['entries'][0]['report_entry_id'], 'tab_id' => $ids['tab'], 'event_type' => 'REPORT_SAVE', 'request_id' => $rid,
                'payload_hash' => $resp['entries'][0]['payload_hash'], 'status' => 'COMMITTED',
                'message' => implode('; ', array_map(function ($x) { return $x['report_type'] . ' ' . $x['plan_name'] . ' v' . $x['version_no']; }, $resp['entries'])),
                'response_json' => json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            $p->commit();
            return $resp;
        } catch (Throwable $ex) {
            try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {}
            if (rs_is_dup($ex) && $stage === 'save_event') {   /* request ID yang sama sudah di-commit request paralel (klik ganda) */
                $prev->execute([$evKey]); if (($pr = $prev->fetchColumn()) !== false) { $d = json_decode((string)$pr, true); if (is_array($d)) { $d['idempotent_replay'] = true; return $d; } }
            }
            if (rs_is_retryable($ex) && $attempt < 3) { usleep(50000 * $attempt); continue; }
            $msg = 'ROLLED_BACK at ' . $stage . ': ' . $ex->getMessage();
            /* id dari transaksi yang di-rollback tidak boleh dipakai (baris user baru ikut di-rollback): upsert ulang di autocommit */
            try { $uidF = rs_user_id($p, $ctx['uid']);
                  rs_event($p, 'save', ['event_key' => rs_key('evf'), 'user_id' => $uidF, 'project_id' => null, 'run_id' => null, 'report_entry_id' => null,
                      'tab_id' => null, 'event_type' => 'REPORT_SAVE', 'request_id' => $rid, 'status' => 'ROLLED_BACK', 'message' => substr($msg, 0, 4000)]); } catch (Throwable $e3) {}
            return ['ok' => false, 'error' => 'SAVE_ROLLED_BACK', 'stage' => $stage, 'message' => $ex->getMessage(), 'request_id' => $rid];
        }
    }
}
/** Buka EXACT record: report_entries -> simulation_results -> input_snapshots (FK), verifikasi sha256. $version null = versi aktif. */
function rs_report_open(string $key, ?int $version = null): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE', 'report_key' => $key];
    $key = sds_clean_id($key, 128);
    $q = $p->prepare('SELECT e.id, e.report_key, e.report_type, e.plan_date, e.plan_name, e.remark, e.version_no, e.is_deleted, e.result_id, e.input_snapshot_id,
                             e.monitoring_source_report_id, e.updated_at, e.created_at, u.external_uid AS updated_by_uid, u.display_name AS updated_by_name, pr.project_key
                      FROM report_entries e JOIN users u ON u.id = e.updated_by JOIN projects pr ON pr.id = e.project_id WHERE e.report_key = ?');
    $q->execute([$key]); $e = $q->fetch();
    if (!$e) return ['ok' => false, 'error' => 'REPORT_NOT_FOUND', 'report_key' => $key];
    if ((int)$e['is_deleted'] === 1) return ['ok' => false, 'error' => 'REPORT_DELETED', 'report_key' => $key];
    $resId = $e['result_id']; $snapId = $e['input_snapshot_id']; $ver = (int)$e['version_no']; $savedAt = $e['updated_at'];
    if ($version !== null && $version !== $ver) {
        $v = $p->prepare('SELECT v.version_no, v.result_id, v.input_snapshot_id, v.saved_at FROM report_versions v WHERE v.report_entry_id = ? AND v.version_no = ?');
        $v->execute([(int)$e['id'], $version]); $vr = $v->fetch();
        if (!$vr) return ['ok' => false, 'error' => 'VERSION_NOT_FOUND', 'report_key' => $key, 'version_no' => $version];
        $resId = $vr['result_id']; $snapId = $vr['input_snapshot_id']; $ver = (int)$vr['version_no']; $savedAt = $vr['saved_at'];
    }
    $meta = ['report_key' => $e['report_key'], 'report_entry_id' => (int)$e['id'], 'report_type' => $e['report_type'], 'plan_date' => $e['plan_date'],
             'plan_name' => $e['plan_name'], 'remark' => $e['remark'], 'version_no' => $ver, 'active_version_no' => (int)$e['version_no'], 'saved_at_utc' => $savedAt,
             'saved_by' => $e['updated_by_name'] ?: $e['updated_by_uid'], 'project_key' => $e['project_key'], 'result_id' => $resId !== null ? (int)$resId : null,
             'input_snapshot_id' => $snapId !== null ? (int)$snapId : null, 'monitoring_source_report_id' => $e['monitoring_source_report_id'] !== null ? (int)$e['monitoring_source_report_id'] : null];
    if ($snapId === null) return ['ok' => false, 'error' => 'SNAPSHOT_MISSING'] + $meta;
    $s = $p->prepare('SELECT snapshot_key, payload_hash, payload_json FROM input_snapshots WHERE id = ?'); $s->execute([(int)$snapId]); $sr = $s->fetch();
    if (!$sr) return ['ok' => false, 'error' => 'SNAPSHOT_MISSING'] + $meta;
    $meta['snapshot_key'] = $sr['snapshot_key'];
    if (!hash_equals((string)$sr['payload_hash'], hash('sha256', (string)$sr['payload_json']))) return ['ok' => false, 'error' => 'SNAPSHOT_HASH_MISMATCH'] + $meta;
    $out = null;
    if ($resId !== null) {
        $r = $p->prepare('SELECT result_key, input_snapshot_id, result_sha256, result_json, cp_usd_per_mwh, heat_rate_btu_per_kwh, candidate_fingerprint FROM simulation_results WHERE id = ?');
        $r->execute([(int)$resId]); $rr = $r->fetch();
        if (!$rr) return ['ok' => false, 'error' => 'RESULT_MISSING'] + $meta;
        if ((int)$rr['input_snapshot_id'] !== (int)$snapId) return ['ok' => false, 'error' => 'RESULT_SNAPSHOT_PAIR_MISMATCH'] + $meta;
        if (!hash_equals((string)$rr['result_sha256'], hash('sha256', (string)$rr['result_json']))) return ['ok' => false, 'error' => 'RESULT_HASH_MISMATCH'] + $meta;
        $meta['result_key'] = $rr['result_key']; $meta['result_sha256'] = $rr['result_sha256']; $meta['payload_hash'] = $sr['payload_hash'];
        $meta['cp'] = $rr['cp_usd_per_mwh'] !== null ? (float)$rr['cp_usd_per_mwh'] : null; $meta['hr'] = $rr['heat_rate_btu_per_kwh'] !== null ? (float)$rr['heat_rate_btu_per_kwh'] : null;
        $out = (string)$rr['result_json'];
    }
    return ['ok' => true, 'meta' => $meta, 'input_json' => (string)$sr['payload_json'], 'output_json' => $out];
}
/** Index Report (tanpa snapshot/result body): untuk tabel Report → Planning, Planning vs Actual dan Trend. */
function rs_report_list(string $projectKey, array $f = []): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $sql = 'SELECT e.report_key, e.report_type, e.plan_date, e.plan_name, e.remark, e.version_no, e.updated_at, e.created_at, e.monitoring_source_report_id,
                   e.created_by, pr.owner_user_id, u.external_uid AS saved_by_uid, u.display_name AS saved_by_name, r.summary_json, r.cp_usd_per_mwh, r.heat_rate_btu_per_kwh, r.result_key
            FROM report_entries e JOIN projects pr ON pr.id = e.project_id JOIN users u ON u.id = e.updated_by LEFT JOIN simulation_results r ON r.id = e.result_id
            WHERE pr.project_key = ? AND e.active_marker = 0 AND e.report_type IN (\'PLANNING\',\'MONITORING\')';
    $args = [$projectKey];
    if (!empty($f['from'])) { $sql .= ' AND e.plan_date >= ?'; $args[] = (string)$f['from']; }
    if (!empty($f['to'])) { $sql .= ' AND e.plan_date <= ?'; $args[] = (string)$f['to']; }
    $sql .= ' ORDER BY e.plan_date ASC, e.created_at ASC, e.id ASC LIMIT ' . max(1, min(20000, (int)($f['limit'] ?? 5000)));
    $q = $p->prepare($sql); $q->execute($args); $rows = [];
    /* V15.21: can_delete per baris untuk pemanggil (tampilan saja; keputusan tetap di rs_report_delete). */
    $cu = (string)($f['caller_uid'] ?? ''); $cuId = null;
    if ($cu !== '') { $s = $p->prepare('SELECT id FROM users WHERE external_uid = ?'); $s->execute([$cu]); $x = $s->fetchColumn(); $cuId = $x !== false ? (int)$x : null; }
    foreach ($q->fetchAll() as $r) {
        $rows[] = ['report_key' => $r['report_key'], 'report_type' => $r['report_type'], 'plan_type' => $r['report_type'] === 'MONITORING' ? 'monitoring' : 'plan',
            'plan_date' => $r['plan_date'], 'name_plan' => $r['plan_name'], 'plan_remark' => (string)$r['remark'], 'version_no' => (int)$r['version_no'],
            'saved_at' => $r['updated_at'], 'created_at' => $r['created_at'], 'saved_by' => $r['saved_by_name'] ?: $r['saved_by_uid'], 'result_key' => $r['result_key'],
            'summary' => $r['summary_json'] !== null ? (json_decode((string)$r['summary_json'], true) ?: new stdClass()) : new stdClass(),
            'cp' => $r['cp_usd_per_mwh'] !== null ? (float)$r['cp_usd_per_mwh'] : null, 'hr' => $r['heat_rate_btu_per_kwh'] !== null ? (float)$r['heat_rate_btu_per_kwh'] : null,
            'can_delete' => rs_can_delete($cu, $cuId, (int)$r['created_by'], $r['owner_user_id'] !== null ? (int)$r['owner_user_id'] : null)];
    }
    return ['ok' => true, 'project_key' => $projectKey, 'reports' => $rows];
}
/* ---------------- V15.21 DELETE Report Planning (soft delete, permission, audit, idempoten) ----------------
 * Permission (server): pembuat record (created_by), pemilik project (projects.owner_user_id), atau uid pada daftar admin
 * ('report_admins' => ['uid', ...] di config/db.php, atau env PP_REPORT_ADMINS dipisah koma). */
function rs_admin_uids(): array {
    rs_config();
    $l = (array)($GLOBALS['__rs_cfg_file']['report_admins'] ?? []);
    foreach (explode(',', (string)rs_env('PP_REPORT_ADMINS')) as $u) if (trim($u) !== '') $l[] = trim($u);
    return array_values(array_unique(array_map('strval', $l)));
}
function rs_can_delete(string $callerUid, ?int $callerUserId, int $createdBy, ?int $projectOwner): bool {
    if ($callerUid === '' || $callerUserId === null) return false;
    if ($callerUserId === $createdBy) return true;
    if ($projectOwner !== null && $callerUserId === $projectOwner) return true;
    return in_array($callerUid, rs_admin_uids(), true);
}
/**
 * Soft delete SATU record Planning/Monitoring berdasarkan report_key (immutable, bukan nama/Base64), dalam satu transaksi:
 *   SELECT ... FOR UPDATE (row lock; serialisasi dengan replace/delete user lain) -> cek project + permission ->
 *   is_deleted = 1, active_marker = id (nama yang sama bebas dipakai record baru) -> audit_events REPORT_DELETE -> COMMIT.
 * Snapshot, hasil, report_versions dan monitoring_rows TIDAK dihapus (riwayat & audit tetap utuh; FK RESTRICT tetap benar).
 * Idempoten: request_id sama -> jawaban commit pertama; record sudah terhapus -> ok + already_deleted.
 */
function rs_report_delete(array $req, array $ctx): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $key = sds_clean_id((string)($req['report_key'] ?? ''), 128);
    if ($key === '' || strpos($key, 'rpt_') !== 0) return ['ok' => false, 'error' => 'BAD_REPORT_KEY'];
    $rid = rs_request_id($req); $pkey = rs_project_key($req);
    $reason = trim(substr((string)($req['reason'] ?? ''), 0, 500));
    $evKey = 'delete:' . sha1($pkey . '|' . $key . '|' . $rid);
    $prev = $p->prepare("SELECT message FROM audit_events WHERE event_key = ? AND status = 'COMMITTED'"); $prev->execute([$evKey]);
    if (($pr = $prev->fetchColumn()) !== false) { $d = json_decode((string)$pr, true); if (is_array($d)) return ['ok' => true, 'idempotent_replay' => true] + $d; }
    for ($attempt = 1; ; $attempt++) {
        try {
            $p->beginTransaction();
            $uid = rs_user_id($p, $ctx['uid']);
            $q = $p->prepare('SELECT e.id, e.report_key, e.report_type, e.plan_date, e.plan_name, e.version_no, e.is_deleted, e.created_by, e.project_id,
                                     pr.project_key, pr.owner_user_id FROM report_entries e JOIN projects pr ON pr.id = e.project_id WHERE e.report_key = ? FOR UPDATE');
            $q->execute([$key]); $e = $q->fetch();
            if (!$e || (string)$e['project_key'] !== $pkey) { $p->rollBack(); return ['ok' => false, 'error' => 'REPORT_NOT_FOUND', 'report_key' => $key]; }
            if (!in_array($e['report_type'], ['PLANNING', 'MONITORING'], true)) { $p->rollBack(); return ['ok' => false, 'error' => 'BAD_REPORT_TYPE', 'report_key' => $key]; }
            if (!rs_can_delete($ctx['uid'], $uid, (int)$e['created_by'], $e['owner_user_id'] !== null ? (int)$e['owner_user_id'] : null)) {
                $p->rollBack();
                try { rs_event($p, 'audit', ['event_key' => rs_key('evd'), 'user_id' => rs_user_id($p, $ctx['uid']), 'project_id' => (int)$e['project_id'], 'report_entry_id' => (int)$e['id'],
                        'event_type' => 'REPORT_DELETE', 'request_id' => $rid, 'status' => 'FAILED', 'message' => 'NO_PERMISSION actor=' . $ctx['uid'] . ' report=' . $key]); } catch (Throwable $e4) {}
                return ['ok' => false, 'error' => 'NO_PERMISSION', 'report_key' => $key];
            }
            if ((int)$e['is_deleted'] === 1) { $p->rollBack(); return ['ok' => true, 'already_deleted' => true, 'report_key' => $key, 'request_id' => $rid]; }
            $now = rs_now();
            $u = $p->prepare('UPDATE report_entries SET is_deleted = 1, active_marker = id WHERE id = ? AND is_deleted = 0');
            $u->execute([(int)$e['id']]);
            if ($u->rowCount() !== 1) throw new RuntimeException('DELETE_RACE');
            $resp = ['deleted' => true, 'soft_delete' => true, 'report_key' => $key, 'report_entry_id' => (int)$e['id'], 'report_type' => $e['report_type'],
                     'plan_date' => $e['plan_date'], 'plan_name' => $e['plan_name'], 'project_key' => $pkey, 'old_active_version' => (int)$e['version_no'],
                     'actor' => $ctx['uid'], 'deleted_at_utc' => $now, 'request_id' => $rid, 'reason' => $reason !== '' ? $reason : null];
            rs_event($p, 'audit', ['event_key' => $evKey, 'user_id' => $uid, 'project_id' => (int)$e['project_id'], 'report_entry_id' => (int)$e['id'],
                'event_type' => 'REPORT_DELETE', 'request_id' => $rid, 'status' => 'COMMITTED', 'message' => json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            $p->commit();
            return ['ok' => true] + $resp;
        } catch (Throwable $ex) {
            try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {}
            if (rs_is_dup($ex)) { $prev->execute([$evKey]); if (($pr = $prev->fetchColumn()) !== false) { $d = json_decode((string)$pr, true); if (is_array($d)) return ['ok' => true, 'idempotent_replay' => true] + $d; } }
            if (rs_is_retryable($ex) && $attempt < 3) { usleep(50000 * $attempt); continue; }
            return ['ok' => false, 'error' => 'DELETE_ROLLED_BACK', 'message' => $ex->getMessage(), 'report_key' => $key];
        }
    }
}
function rs_report_versions(string $key): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $q = $p->prepare('SELECT v.version_no, v.saved_at, v.change_reason, v.save_request_id, u.external_uid AS saved_by, r.result_key, s.snapshot_key, r.cp_usd_per_mwh AS cp,
                             r.heat_rate_btu_per_kwh AS hr, r.result_sha256, s.payload_hash
                      FROM report_versions v JOIN report_entries e ON e.id = v.report_entry_id JOIN users u ON u.id = v.saved_by
                      LEFT JOIN simulation_results r ON r.id = v.result_id LEFT JOIN input_snapshots s ON s.id = v.input_snapshot_id
                      WHERE e.report_key = ? ORDER BY v.version_no ASC');
    $q->execute([sds_clean_id($key, 128)]);
    return ['ok' => true, 'report_key' => $key, 'versions' => $q->fetchAll()];
}
/** ACTUAL report: satu entry per bulan (plan_date = tanggal 1), versi baru hanya bila isi berubah. */
function rs_actual_save(array $req, array $ctx): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $rid = rs_request_id($req); $pkey = rs_project_key($req); $evKey = rs_save_event_key($pkey, $rid, 'actual');
    $months = (array)($req['months'] ?? []);
    try {
        $p->beginTransaction();
        $uid = rs_user_id($p, $ctx['uid']); $tab = rs_tab_id($p, $uid, $ctx['tab']); $prj = rs_project_id($p, $pkey, $uid);
        $runId = rs_run_id($p, ['run_key' => rs_run_key($ctx['uid'], $ctx['tab'], 'actual-' . $rid), 'client_run_id' => 'actual-' . substr($rid, 0, 40), 'project_id' => $prj,
            'user_id' => $uid, 'tab_id' => $tab, 'mode' => 'FASTEST', 'status' => 'FINAL', 'payload_hash' => hash('sha256', json_encode($months)), 'finished_at' => rs_now()]);
        $done = [];
        foreach ($months as $m) {
            $y = (int)($m['year'] ?? 0); $mo = (int)($m['month_index'] ?? -1) + 1; $label = (string)($m['month'] ?? '');
            $json = (string)($m['data_json'] ?? '');
            if ($y < 2000 || $mo < 1 || $mo > 12 || !is_array(json_decode($json, true))) continue;
            $date = sprintf('%04d-%02d-01', $y, $mo); $name = 'Report Actual ' . $label . ' ' . $y;
            $cur = $p->prepare("SELECT s.payload_hash FROM report_entries e JOIN input_snapshots s ON s.id = e.input_snapshot_id JOIN projects pr ON pr.id = e.project_id
                                WHERE pr.id = ? AND e.report_type = 'ACTUAL' AND e.plan_date = ? AND e.active_marker = 0");
            $cur->execute([$prj, $date]);
            if ((string)$cur->fetchColumn() === hash('sha256', '{"report_actual_month":' . $json . '}')) { $done[] = ['month' => $date, 'unchanged' => true]; continue; }
            $snap = rs_snapshot_id($p, $runId, '{"report_actual_month":' . $json . '}', 'pp-report-actual-v1');
            $ent = rs_report_put($p, ['project_id' => $prj, 'report_type' => 'ACTUAL', 'plan_date' => $date, 'plan_name' => $name, 'remark' => '', 'result_id' => null,
                'snapshot_id' => $snap['id'], 'user_id' => $uid, 'request_id' => $rid]);
            $done[] = ['month' => $date, 'report_key' => $ent['report_key'], 'version_no' => $ent['version_no']];
        }
        rs_event($p, 'save', ['event_key' => $evKey, 'user_id' => $uid, 'project_id' => $prj, 'run_id' => $runId, 'tab_id' => $tab, 'event_type' => 'ACTUAL_SAVE',
            'request_id' => $rid, 'status' => 'COMMITTED', 'message' => count($done) . ' month(s)', 'response_json' => json_encode(['months' => $done])]);
        $p->commit();
        return ['ok' => true, 'request_id' => $rid, 'months' => $done];
    } catch (Throwable $ex) {
        try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {}
        if (rs_is_dup($ex)) return ['ok' => true, 'request_id' => $rid, 'idempotent_replay' => true];
        return ['ok' => false, 'error' => 'SAVE_ROLLED_BACK', 'message' => $ex->getMessage()];
    }
}
/** Data ACTUAL aktif per bulan -> struktur REPORT_ACTUAL[year][MonthName] = {...}. */
function rs_actual_load(string $projectKey): array {
    $p = rs_pdo(); if (!$p) return [];
    $q = $p->prepare("SELECT e.plan_date, s.payload_json FROM report_entries e JOIN projects pr ON pr.id = e.project_id JOIN input_snapshots s ON s.id = e.input_snapshot_id
                      WHERE pr.project_key = ? AND e.report_type = 'ACTUAL' AND e.active_marker = 0 ORDER BY e.plan_date");
    $q->execute([$projectKey]); $names = ['January','February','March','April','May','June','July','August','September','October','November','December']; $o = [];
    foreach ($q->fetchAll() as $r) { $d = json_decode((string)$r['payload_json'], true); $m = $d['report_actual_month'] ?? null; if (!is_array($m)) continue;
        $o[(string)(int)substr($r['plan_date'], 0, 4)][$names[(int)substr($r['plan_date'], 5, 2) - 1]] = $m; }
    return $o;
}
/** Run didaftarkan saat mode=run (transaksi singkat sendiri, best-effort: kegagalan DB tidak pernah menghentikan Run). */
function rs_register_run(array $input, array $ctx, string $clientRid, string $mode, ?string $jobId = null): ?array {
    if (!rs_enabled() || $clientRid === '') return null;
    $p = rs_pdo(); if (!$p) return null;
    try {
        $clean = sds_clean_input($input); unset($clean['data3']['modeling']['report_planning']);
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $p->beginTransaction();
        $uid = rs_user_id($p, $ctx['uid']); $tab = rs_tab_id($p, $uid, $ctx['tab']); $prj = rs_project_id($p, rs_project_key(), $uid);
        $runId = rs_run_id($p, ['run_key' => rs_run_key($ctx['uid'], $ctx['tab'], $clientRid), 'client_run_id' => $clientRid, 'project_id' => $prj, 'user_id' => $uid, 'tab_id' => $tab,
            'mode' => $mode, 'status' => 'RUNNING', 'state_revision' => (int)($input['_state_revision'] ?? 0), 'payload_hash' => hash('sha256', (string)$json), 'job_id' => $jobId]);
        $snap = rs_snapshot_id($p, $runId, (string)$json);
        $p->commit();
        return ['run_id' => $runId, 'input_snapshot_id' => $snap['id'], 'snapshot_key' => $snap['key']];
    } catch (Throwable $ex) { try { if ($p->inTransaction()) $p->rollBack(); } catch (Throwable $e2) {} $e =& rs_last_error(); $e = $ex->getMessage(); return null; }
}
/** Status akhir run (dipanggil sekali saat job_poll DONE untuk Run ID ini). */
function rs_finish_run(array $ctx, string $clientRid, string $status, ?string $fingerprint, ?string $jobId): void {
    if (!rs_enabled() || $clientRid === '') return;
    $p = rs_pdo(); if (!$p) return;
    try { $p->prepare("UPDATE simulation_runs SET status = ?, candidate_fingerprint = COALESCE(?, candidate_fingerprint), job_id = COALESCE(?, job_id), finished_at = ?
                       WHERE run_key = ? AND status IN ('QUEUED','RUNNING')")
            ->execute([$status, $fingerprint, $jobId, rs_now(), rs_run_key($ctx['uid'], $ctx['tab'], $clientRid)]); } catch (Throwable $e) {}
}
/** Integritas + repair report: orphan/pasangan FK tidak konsisten/hash rusak/monitoring row != 48. Read-only. */
function rs_integrity(): array {
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'error' => rs_last_error() ?: 'DB_UNAVAILABLE'];
    $one = function (string $sql) use ($p) { return array_map(function ($r) { return array_values($r)[0]; }, $p->query($sql)->fetchAll()); };
    $chk = [
        'entries_missing_result_or_snapshot' => $one("SELECT report_key FROM report_entries WHERE report_type IN ('PLANNING','MONITORING') AND (result_id IS NULL OR input_snapshot_id IS NULL)"),
        'entries_result_snapshot_mismatch'   => $one('SELECT e.report_key FROM report_entries e JOIN simulation_results r ON r.id = e.result_id WHERE r.input_snapshot_id <> e.input_snapshot_id'),
        'versions_result_snapshot_mismatch'  => $one("SELECT CONCAT(v.report_entry_id, ':v', v.version_no) FROM report_versions v JOIN simulation_results r ON r.id = v.result_id WHERE r.input_snapshot_id <> v.input_snapshot_id"),
        'entries_active_version_missing'     => $one('SELECT e.report_key FROM report_entries e LEFT JOIN report_versions v ON v.report_entry_id = e.id AND v.version_no = e.version_no WHERE v.id IS NULL'),
        'monitoring_rows_not_48'             => $one("SELECT e.report_key FROM report_entries e LEFT JOIN monitoring_rows m ON m.report_entry_id = e.id WHERE e.report_type = 'MONITORING' AND e.active_marker = 0 GROUP BY e.id, e.report_key HAVING COUNT(m.id) <> 48"),
        'snapshot_hash_mismatch'             => $one('SELECT snapshot_key FROM input_snapshots WHERE SHA2(payload_json, 256) <> payload_hash'),
        'result_hash_mismatch'               => $one('SELECT result_key FROM simulation_results WHERE SHA2(result_json, 256) <> result_sha256'),
    ];
    $bad = 0; foreach ($chk as $v) $bad += count($v);
    $cnt = []; foreach (['users', 'browser_tabs', 'projects', 'simulation_runs', 'input_snapshots', 'simulation_results', 'report_entries', 'report_versions', 'monitoring_rows', 'save_events', 'audit_events', 'legacy_migration_map'] as $t)
        $cnt[$t] = (int)$p->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    $cnt['unreferenced_results'] = (int)$p->query('SELECT COUNT(*) FROM simulation_results r WHERE NOT EXISTS (SELECT 1 FROM report_versions v WHERE v.result_id = r.id)')->fetchColumn();
    return ['ok' => $bad === 0, 'schema' => PP_RS_SCHEMA, 'problems' => $chk, 'problem_count' => $bad, 'counts' => $cnt,
            'note' => 'unreferenced_results bukan kerusakan: hasil run yang tidak (belum) disimpan ke Report.'];
}
/* ---------------- HTTP (dipanggil run.php SEBELUM engine dimuat) ---------------- */
/* ===== V15.25 CHECK YESTERDAY DATA =============================================================================================
 * Sumber (urutan prioritas, hanya project pemanggil, record aktif tidak terhapus, tanggal = Plan Date - 1 hari):
 *   1) Report MONITORING (Monitoring Daily Plan) tanggal kemarin — versi aktif terbaru (updated_at terbaru);
 *   2) bila tidak ada: Report PLANNING (Daily Plan) tanggal kemarin — versi aktif terbaru;
 *   3) tidak ada keduanya: NO_DATA (input tidak diubah).
 * Record yang snapshot/result-nya rusak (hash tidak cocok / 48 row tidak lengkap) dilewati dengan alasan (PARTIAL_OR_CORRUPT); bila
 * seluruh kandidat rusak -> status CORRUPT. Status per unit dibaca dari row terakhir (00:00) DAN state sequence blok:
 *   STG blok > 0 -> GTG berbeban = Running; STG blok = 0 dan GTG feeder CC berbeban -> Start Up CC (elapsed = row berturut-turut sampai
 *   00:00, ditambah elapsed kelanjutan kemarin bila segmen dimulai row 1); unit 0 MW -> Stop; GTG mode SC / tanpa HRSG berbeban -> Running.
 * Tidak mengubah apa pun di server; UI menampilkan pratinjau dan hanya menerapkan setelah Apply. */
function rs_yesterday_status(array $in, array $out): array {
    $d3 = (array)($in['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $rows = array_values((array)($out['data'] ?? []));
    if (count($rows) !== 48) return ['ok' => false, 'error' => 'ROWS_NOT_48', 'rows' => count($rows)];
    $last = $rows[47]; $units = []; $cont = [];
    $sc = array_change_key_case((array)($m['sc_cc'] ?? []), CASE_LOWER); $applied = (array)($m['startup_continuation_applied'] ?? ($m['__v1525_continuation'] ?? []));
    $lv = function (string $U) use ($last): float { $k = ['B1' => 'BB1', 'B2' => 'BB2'][$U] ?? $U; return (float)($last[$k] ?? 0); };
    $feed = []; foreach (['s1', 's2', 's3'] as $s) foreach ((array)($d3[$s]['gtg_configured'] ?? ($d3[$s]['gtg'] ?? array_values((array)($d3[$s]['hrsg'] ?? [])))) as $g) $feed[strtolower((string)$g)] = $s;
    foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','B1','B2'] as $U) { $u = strtolower($U); $v = $lv($U);
        $units[$U] = ['status' => $v > 0.01 ? 'Running' : 'Stop', 'load_mw_0000' => round($v, 2), 'reason' => $v > 0.01 ? 'BERBEBAN_ROW_00:00' : 'NOL_MW_ROW_00:00']; }
    foreach ($feed as $g => $s) { $G = strtoupper($g); $S = strtoupper($s); if (!isset($units[$G]) || $units[$G]['status'] !== 'Running') continue;
        if (strtoupper((string)($sc[$g] ?? 'CC')) === 'SC') { $units[$G]['reason'] = 'GTG_MODE_SC_BERBEBAN'; continue; }
        if ($units[$S]['load_mw_0000'] > 0.01) continue;
        $k = 0; for ($r = 47; $r >= 0 && (float)($rows[$r][$G] ?? 0) > 0.01; $r--) $k++;
        if ($r < 0 && isset($applied[$s]['elapsed_rows'])) $k += (int)$applied[$s]['elapsed_rows'];
        $units[$G]['status'] = 'Start Up CC'; $units[$G]['reason'] = 'STG_BLOK_BELUM_BERBEBAN_SEQUENCE_BERJALAN'; $units[$G]['sequence_rows_done'] = $k;
        $mode = strtolower(preg_replace('/@\d+$/', '', (string)($m['stg_startup_mode'][$s . '_startup'] ?? ($m['stg_startup_mode'][$s] ?? 'warm'))));
        if (!isset($cont[$s])) $cont[$s] = ['elapsed_rows' => $k, 'units' => [], 'mode' => $mode, 'source' => 'YESTERDAY_DATA'];
        $cont[$s]['elapsed_rows'] = max($cont[$s]['elapsed_rows'], $k); $cont[$s]['units'][] = $g; }
    return ['ok' => true, 'units' => $units, 'startup_continuation' => $cont, 'row_time' => (string)($last['Time'] ?? '')];
}
function rs_yesterday_data(string $projectKey, string $planDate): array {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $planDate)) return ['ok' => false, 'status' => 'ERROR', 'error' => 'PLAN_DATE_INVALID'];
    $y = (new DateTime($planDate . ' 00:00:00', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    $p = rs_pdo(); if (!$p) return ['ok' => false, 'status' => 'ERROR', 'error' => rs_last_error() ?: 'DB_UNAVAILABLE', 'yesterday' => $y];
    $skipped = [];
    foreach (['MONITORING' => 'Monitoring Daily Plan', 'PLANNING' => 'Daily Plan'] as $type => $label) {
        $q = $p->prepare('SELECT e.report_key, e.version_no, e.updated_at, e.plan_name, u.external_uid AS uid, u.display_name AS uname FROM report_entries e JOIN projects pr ON pr.id = e.project_id
                          JOIN users u ON u.id = e.updated_by WHERE pr.project_key = ? AND e.plan_date = ? AND e.report_type = ? AND e.is_deleted = 0 AND e.active_marker = 0
                          ORDER BY e.updated_at DESC, e.id DESC LIMIT 20');
        $q->execute([$projectKey, $y, $type]);
        foreach ($q->fetchAll() as $e) {
            $o = rs_report_open((string)$e['report_key']);
            if (empty($o['ok']) || $o['output_json'] === null) { $skipped[] = ['report_key' => $e['report_key'], 'type' => $type, 'reason' => 'PARTIAL_OR_CORRUPT:' . ($o['error'] ?? 'NO_RESULT')]; continue; }
            $in = json_decode((string)$o['input_json'], true); $out = json_decode((string)$o['output_json'], true);
            if (!is_array($in) || !is_array($out)) { $skipped[] = ['report_key' => $e['report_key'], 'type' => $type, 'reason' => 'PARTIAL_OR_CORRUPT:JSON']; continue; }
            $st = rs_yesterday_status($in, $out);
            if (empty($st['ok'])) { $skipped[] = ['report_key' => $e['report_key'], 'type' => $type, 'reason' => 'PARTIAL_OR_CORRUPT:' . ($st['error'] ?? '?')]; continue; }
            return ['ok' => true, 'status' => $skipped ? 'FOUND_WITH_SKIPPED' : 'FOUND', 'plan_date' => $planDate, 'yesterday' => $y,
                'source' => ['report_key' => $o['meta']['report_key'], 'report_entry_id' => $o['meta']['report_entry_id'], 'source_type' => $type, 'source_label' => $label,
                             'plan_name' => $o['meta']['plan_name'], 'plan_date' => $o['meta']['plan_date'], 'version_no' => $o['meta']['version_no'], 'active_version_no' => $o['meta']['active_version_no'],
                             'saved_by' => $o['meta']['saved_by'], 'saved_at_utc' => $o['meta']['saved_at_utc'], 'result_sha256' => $o['meta']['result_sha256'] ?? null],
                'row_time' => $st['row_time'], 'units' => $st['units'], 'startup_continuation' => $st['startup_continuation'], 'skipped' => $skipped];
        }
    }
    return ['ok' => true, 'status' => $skipped ? 'CORRUPT' : 'NO_DATA', 'plan_date' => $planDate, 'yesterday' => $y, 'skipped' => $skipped,
            'message' => $skipped ? 'Report kemarin ditemukan tetapi rusak/tidak lengkap — input tidak diubah.' : 'No Yesterday Data — tidak ada Monitoring Daily Plan maupun Daily Plan aktif tanggal ' . $y . '; input tidak diubah.'];
}
function rs_http(string $mode): void {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $ctx = sds_ctx([]);
    $j = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; };
    if ($mode === 'report_status') $j(['ok' => true, 'store' => rs_status()]);
    if (!rs_enabled()) $j(['ok' => false, 'error' => 'MYSQL_REPORT_STORE_NOT_CONFIGURED', 'store' => rs_status()], 503);
    if (in_array($mode, ['report_save', 'report_save_actual'], true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $j(['ok' => false, 'error' => 'USE_POST'], 405);
        $req = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($req)) $j(['ok' => false, 'error' => 'BAD_JSON'], 400);
        if ($ctx['uid'] === '') $j(['ok' => false, 'error' => 'UID_REQUIRED'], 400);
        $r = $mode === 'report_save' ? rs_report_save($req, $ctx) : rs_actual_save($req, $ctx);
        $j($r, !empty($r['ok']) ? 200 : (($r['error'] ?? '') === 'SAVE_ROLLED_BACK' ? 409 : 422));
    }
    if ($mode === 'report_open') { $v = isset($_GET['version']) && ctype_digit((string)$_GET['version']) ? (int)$_GET['version'] : null;
        $r = rs_report_open((string)($_GET['key'] ?? ''), $v); if (!empty($r['ok'])) { $r['input'] = json_decode($r['input_json'], true); $r['output'] = $r['output_json'] !== null ? json_decode($r['output_json'], true) : null; unset($r['input_json'], $r['output_json']); }
        $j($r, !empty($r['ok']) ? 200 : 404); }
    if ($mode === 'report_list') $j(rs_report_list(rs_project_key(), ['from' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['from'] ?? '')) ? $_GET['from'] : null,
                                                                      'to' => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($_GET['to'] ?? '')) ? $_GET['to'] : null,
                                                                      'caller_uid' => $ctx['uid']]));
    if ($mode === 'report_delete') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $j(['ok' => false, 'error' => 'USE_POST'], 405);
        $req = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($req)) $j(['ok' => false, 'error' => 'BAD_JSON'], 400);
        if ($ctx['uid'] === '') $j(['ok' => false, 'error' => 'UID_REQUIRED'], 400);
        $r = rs_report_delete($req, $ctx); $er = (string)($r['error'] ?? '');
        $j($r, !empty($r['ok']) ? 200 : ($er === 'NO_PERMISSION' ? 403 : ($er === 'REPORT_NOT_FOUND' ? 404 : ($er === 'DELETE_ROLLED_BACK' ? 409 : 422))));
    }
    if ($mode === 'report_yesterday') $j(rs_yesterday_data(rs_project_key(), (string)($_GET['plan_date'] ?? '')));
    if ($mode === 'report_versions') $j(rs_report_versions((string)($_GET['key'] ?? '')));
    if ($mode === 'report_integrity') $j(rs_integrity());
    $j(['ok' => false, 'error' => 'UNKNOWN_MODE'], 400);
}
