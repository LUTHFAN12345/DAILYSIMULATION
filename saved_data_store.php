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
    static $mig = false; if (!$mig) { $mig = true; try { if (!is_file(sds_root() . '/meta/legacy_migrated.json')) sds_migrate_legacy(); } catch (Throwable $e) {} }
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
    if (in_array($kind, ['SAVE', 'AUTOSAVE_RUN'], true) && $uid !== 'shared') {
        $w = null; sds_with_lock('latest_' . $uid, function () use ($uid, $inJ, $rec, &$w) {
            $doc = '{"schema":"pp-user-latest-v1","record_id":' . json_encode($rec['record_id']) . ',"kind":' . json_encode($rec['kind']) . ',"project_id":' . json_encode($rec['project_id'])
                 . ',"version":' . (int)$rec['version'] . ',"saved_at":' . json_encode($rec['created_at']) . ',"input":' . $inJ . '}';
            return sds_atomic_write(sds_user_latest_file($uid), $doc, $w); });
    }
    if (random_int(1, 10) === 1) { try { sds_retention(); } catch (Throwable $e) {} }
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
    elseif ($mode === 'store_integrity') { $out['integrity'] = sds_integrity($uid); $out['writable'] = sds_writable(); $out['legacy'] = sds_migrate_legacy(); }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}
