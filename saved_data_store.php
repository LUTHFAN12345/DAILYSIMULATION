<?php
/* =============================================================================================================
 *  saved_data_store.php — SATU-SATUNYA MODUL PENYIMPANAN DATA PENGGUNA (tab Report: Actual, Planning, Monitoring,
 *  Weekly; seluruh data yang disimpan lewat tombol Save / auto-save sebelum Run; output rilis).
 *
 *  Tujuan: revisi run.php / worker02.php / worker_functions.php / index.php tidak menghapus atau mengubah diam-diam
 *  data tersimpan. Data pengguna berada di direktori data terpisah dari source:
 *      <data>/saved/state/input_data.json   cermin keadaan kerja terakhir yang disimpan
 *      <data>/saved/records/<record_id>.json satu berkas per record Report (Actual / Planning / Monitoring / Weekly)
 *      <data>/backups/...                     versi sebelumnya (sebelum ditimpa / dihapus)
 *  <data> = env PP_DATA_DIR, selain itu <folder aplikasi>/data. Paket deploy hanya berisi berkas PHP; folder data
 *  tidak pernah ikut ditimpa. input_data.json di folder aplikasi tetap berkas kerja yang dibaca index.php/run.php
 *  (kompatibilitas), tetapi HANYA modul ini yang menulisnya.
 *
 *  Setiap record: record_id, schema_version, created_at, updated_at, plan_type, name, plan_date, input_payload,
 *  saved_result (bila ada), engine_version_at_save (informasi saja — record lama SELALU dijalankan dengan engine
 *  terbaru), checksum (sha256 isi kanonik).
 *
 *  Tulis atomik: lock -> tulis berkas sementara -> fflush + fsync -> tutup -> validasi baca-ulang -> backup versi
 *  lama -> rename (diulang, tahan Windows). Bila satu langkah gagal berkas lama utuh.
 * ============================================================================================================= */
if (defined('SDS_LOADED')) return;
define('SDS_LOADED', true);
const SDS_SCHEMA = 2;
const SDS_BACKUP_KEEP = 30;

function sds_root(): string {
    $d = getenv('PP_DATA_DIR'); $d = ($d !== false && $d !== '') ? rtrim($d, '/\\') : __DIR__ . DIRECTORY_SEPARATOR . 'data';
    return $d;
}
function sds_dir(string $sub): string {
    $d = sds_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sub);
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}
function sds_is_windows(): bool { return stripos(PHP_OS_FAMILY ?? PHP_OS, 'WIN') === 0; }
function sds_engine_version(): string {
    $p = []; foreach (['run.php', 'worker02.php', 'worker_functions.php'] as $f) { $x = __DIR__ . DIRECTORY_SEPARATOR . $f; $p[] = $f . ':' . (is_file($x) ? substr(hash_file('sha256', $x), 0, 12) : '-'); }
    return implode(' ', $p);
}

/* ---- tulis atomik ---------------------------------------------------------------------------------------- */
function sds_replace(string $tmp, string $path): bool {
    for ($i = 0; $i < 60; $i++) { if (@rename($tmp, $path)) return true; if (!is_file($tmp)) return false; usleep(25000); }
    if (sds_is_windows() && is_file($path) && @unlink($path) && @rename($tmp, $path)) return true;
    return false;
}
/* PP_SDS_FAIL_AT = write|validate|rename (UJI saja): meniru kegagalan pada langkah itu untuk membuktikan berkas lama utuh. */
function sds_atomic_write(string $path, string $bytes, ?string &$why = null, ?string $backupTag = null): bool {
    $why = null; $dir = dirname($path); if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) { $why = 'tidak bisa membuka lock ' . basename($path) . '.lock (permission?)'; return false; }
    if (!flock($lock, LOCK_EX)) { fclose($lock); $why = 'tidak bisa memperoleh exclusive lock'; return false; }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4)); $ok = false; $fail = (string)getenv('PP_SDS_FAIL_AT');
    try {
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) { $why = 'tidak bisa membuat berkas sementara di ' . $dir; return false; }
        $w = fwrite($fh, $fail === 'write' ? substr($bytes, 0, intdiv(strlen($bytes), 2)) : $bytes);
        fflush($fh); if (function_exists('fsync')) @fsync($fh); fclose($fh);
        if ($w === false || $w !== strlen($bytes)) { $why = 'tulis terpotong (' . var_export($w, true) . ' dari ' . strlen($bytes) . ' byte)'; return false; }
        $chk = (string)@file_get_contents($tmp);
        if ($chk !== $bytes || $fail === 'validate') { $why = 'validasi baca-ulang berkas sementara gagal'; return false; }
        if ($backupTag !== null && is_file($path)) sds_backup($path, $backupTag);
        if ($fail === 'rename' || !sds_replace($tmp, $path)) { $why = 'rename atomik gagal (berkas tujuan dikunci / permission)'; return false; }
        $ok = true; return true;
    } finally {
        if (!$ok && is_file($tmp)) @unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}
function sds_atomic_write_json(string $path, $data, ?string &$why = null, ?string $backupTag = null): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { $why = 'json_encode gagal: ' . json_last_error_msg(); return false; }
    if (!is_array(json_decode($json, true))) { $why = 'JSON tidak valid'; return false; }
    return sds_atomic_write($path, $json, $why, $backupTag);
}
/* salinan versi lama sebelum ditimpa; disimpan maks. SDS_BACKUP_KEEP per tag */
function sds_backup(string $path, string $tag): ?string {
    $d = sds_dir('backups/' . preg_replace('~[^A-Za-z0-9_.\-]~', '_', $tag));
    $dst = $d . DIRECTORY_SEPARATOR . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_' . basename($path);
    if (!@copy($path, $dst)) return null;
    $fs = glob($d . DIRECTORY_SEPARATOR . '*') ?: []; sort($fs);
    while (count($fs) > SDS_BACKUP_KEEP) @unlink(array_shift($fs));
    return $dst;
}

/* ---- berkas kerja (input_data.json / output_data.json) ------------------------------------------------- */
function sds_state_mirror(): string { return sds_dir('saved/state') . DIRECTORY_SEPARATOR . 'input_data.json'; }
/* Save / auto-save: backup versi lama, tulis atomik berkas kerja, perbarui cermin, sinkronkan record Report. */
function sds_write_state_input(string $path, array $input, ?string &$why = null, bool $syncRecords = true): bool {
    if (!sds_atomic_write_json($path, $input, $why, 'input_data')) return false;
    $w2 = null; sds_atomic_write_json(sds_state_mirror(), $input, $w2);
    /* record Report disinkronkan pada Save eksplisit; auto-save sebelum Run hanya berkas kerja + cermin (Run tetap cepat) */
    if ($syncRecords) { try { $GLOBALS['__sds_sync'] = sds_sync_records($input); } catch (Throwable $e) { $GLOBALS['__sds_sync'] = ['error' => $e->getMessage()]; } }
    return true;
}
function sds_write_state_output(string $path, array $output, ?string &$why = null): bool { return sds_atomic_write_json($path, $output, $why, 'output_data'); }
/* index.php: berkas kerja hilang / rusak (mis. folder aplikasi diganti) -> dipulihkan dari cermin data tersimpan. */
function sds_load_state_input(string $path): ?array {
    $in = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (is_array($in) && isset($in['data3']['modeling'])) return $in;
    $m = sds_state_mirror(); $mi = is_file($m) ? json_decode((string)@file_get_contents($m), true) : null;
    if (!is_array($mi) || !isset($mi['data3']['modeling'])) return is_array($in) ? $in : null;
    $why = null; sds_atomic_write_json($path, $mi, $why, 'input_data_restored');
    $mi['data3']['modeling']['__restored_from_store'] = ['from' => 'saved/state/input_data.json', 'at' => date('c')];
    return $mi;
}

/* ---- record Report --------------------------------------------------------------------------------------- */
function sds_rec_dir(): string { return sds_dir('saved/records'); }
function sds_canon($v) { if (is_array($v)) { $isList = array_keys($v) === range(0, count($v) - 1); if (!$isList) ksort($v); foreach ($v as $k => $x) $v[$k] = sds_canon($x); } return $v; }
function sds_checksum(array $rec): string {
    $c = ['record_id' => $rec['record_id'] ?? null, 'plan_type' => $rec['plan_type'] ?? null, 'plan_date' => $rec['plan_date'] ?? null, 'name' => $rec['name'] ?? null,
          'input_payload' => $rec['input_payload'] ?? null, 'saved_result' => $rec['saved_result'] ?? null];
    return hash('sha256', json_encode(sds_canon($c), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}
function sds_record_id(string $planType, string $date, string $name): string { return 'rec_' . substr(hash('sha256', strtolower($planType) . '|' . $date . '|' . $name), 0, 24); }
function sds_type_label(string $pt): string {
    $p = strtolower($pt); if ($p === 'actual') return 'Actual'; if ($p === 'monitoring') return 'Monitoring'; if (strpos($p, 'weekly') === 0) return 'Weekly'; return 'Planning';
}
/* migrasi skema: v1 (record tanpa skema / bentuk report_planning lama) -> v2. Tidak pernah membuang field. */
function sds_migrate(array $rec): array {
    $v = (int)($rec['schema_version'] ?? 1);
    if ($v < 2) {
        $rec['schema_version'] = 2; $rec['migrated_from'] = $v;
        if (!isset($rec['input_payload']) && isset($rec['snapshot']['input'])) $rec['input_payload'] = $rec['snapshot']['input'];
        if (!isset($rec['saved_result']) && isset($rec['snapshot']['output'])) $rec['saved_result'] = $rec['snapshot']['output'];
        $rec['created_at'] = $rec['created_at'] ?? ($rec['saved_at'] ?? date('c')); $rec['updated_at'] = $rec['updated_at'] ?? $rec['created_at'];
        $rec['checksum'] = sds_checksum($rec);
    }
    return $rec;
}
/* record dari input (semua bentuk report_planning yang dikenali run.php + report_actual per tanggal) */
function sds_records_from_input(array $input): array {
    $m = (array)($input['data3']['modeling'] ?? []); $out = [];
    $flat = []; $rp = $m['report_planning'] ?? null;
    $walk = function ($x) use (&$walk, &$flat) { if (!is_array($x)) return; if (isset($x['plan_type']) || isset($x['name_plan']) || isset($x['snapshot'])) { $flat[] = $x; return; } foreach ($x as $y) $walk($y); };
    if (is_array($rp)) $walk(array_key_exists('records', $rp) ? $rp['records'] : $rp);
    foreach ($flat as $r) {
        $pt = (string)($r['plan_type'] ?? 'plan');
        $date = (string)($pt === 'weekly_plan_day' ? ($r['day_date'] ?? $r['plan_date'] ?? '') : ($r['plan_date'] ?? ''));
        $name = (string)($pt === 'weekly_plan_day' ? ('weekly:' . ($r['weekly_plan_id'] ?? '')) : ($r['name_plan'] ?? ''));
        $out[] = ['record_id' => sds_record_id($pt, $date, $name), 'plan_type' => sds_type_label($pt), 'plan_type_raw' => $pt, 'plan_date' => $date, 'name' => $name,
                  'input_payload' => $r['snapshot']['input'] ?? $r, 'saved_result' => $r['snapshot']['output'] ?? null, 'source_record' => array_diff_key($r, ['snapshot' => 1])];
    }
    $ra = $m['report_actual'] ?? null;
    if (is_array($ra)) foreach ($ra as $y => $months) if (is_array($months)) foreach ($months as $mo => $days) if (is_array($days)) foreach ($days as $day => $vals) {
        if (!is_array($vals)) continue;
        $out[] = ['record_id' => sds_record_id('actual', (string)$day, 'actual'), 'plan_type' => 'Actual', 'plan_type_raw' => 'actual', 'plan_date' => (string)$day, 'name' => 'Actual ' . $day,
                  'input_payload' => $vals, 'saved_result' => null, 'source_record' => ['year' => $y, 'month' => $mo]];
    }
    return $out;
}
/* simpan / perbarui satu record (backup versi lama bila isinya berubah) */
function sds_put(array $r, ?string &$why = null): ?array {
    $f = sds_rec_dir() . DIRECTORY_SEPARATOR . $r['record_id'] . '.json';
    $old = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $rec = ['record_id' => $r['record_id'], 'schema_version' => SDS_SCHEMA, 'created_at' => $old['created_at'] ?? date('c'), 'updated_at' => date('c'),
            'plan_type' => $r['plan_type'], 'plan_type_raw' => $r['plan_type_raw'] ?? null, 'plan_date' => $r['plan_date'] ?? '', 'name' => $r['name'] ?? '',
            'input_payload' => $r['input_payload'] ?? null, 'saved_result' => $r['saved_result'] ?? null, 'source_record' => $r['source_record'] ?? null,
            'meta' => (array)($old['meta'] ?? []) + (array)($r['meta'] ?? []), 'engine_version_at_save' => sds_engine_version()];
    $rec['checksum'] = sds_checksum($rec);
    $GLOBALS['__sds_changed'] = false;
    if (is_array($old) && ($old['checksum'] ?? '') === $rec['checksum'] && (int)($old['schema_version'] ?? 0) === SDS_SCHEMA) return $old;   // tidak berubah
    $GLOBALS['__sds_changed'] = true;
    return sds_atomic_write_json($f, $rec, $why, 'records/' . $r['record_id']) ? $rec : null;
}
function sds_sync_records(array $input): array {
    $n = ['written' => 0, 'unchanged' => 0, 'failed' => 0];
    foreach (sds_records_from_input($input) as $r) { $x = sds_put($r); if ($x === null) $n['failed']++; elseif (!empty($GLOBALS['__sds_changed'])) $n['written']++; else $n['unchanged']++; }
    return $n;
}
function sds_save(array $payload, string $planType, string $date, string $name, ?array $result = null, array $meta = [], ?string &$why = null): ?array {
    return sds_put(['record_id' => sds_record_id(strtolower($planType), $date, $name), 'plan_type' => sds_type_label($planType), 'plan_type_raw' => strtolower($planType), 'plan_date' => $date, 'name' => $name,
                    'input_payload' => $payload, 'saved_result' => $result, 'meta' => $meta], $why);
}
/* load: verifikasi checksum + migrasi skema. Record TIDAK membawa engine; menjalankannya selalu memakai run.php/worker terbaru. */
function sds_load(string $id): ?array {
    $id = preg_replace('~[^A-Za-z0-9_]~', '', $id); $f = sds_rec_dir() . DIRECTORY_SEPARATOR . $id . '.json';
    $r = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; if (!is_array($r)) return null;
    $r = sds_migrate($r);
    $r['integrity'] = ['checksum_ok' => hash_equals((string)($r['checksum'] ?? ''), sds_checksum($r)), 'file' => basename($f)];
    return $r;
}
function sds_list(array $filter = []): array {
    $L = [];
    foreach (glob(sds_rec_dir() . DIRECTORY_SEPARATOR . 'rec_*.json') ?: [] as $f) { $r = json_decode((string)@file_get_contents($f), true); if (!is_array($r)) { $L[] = ['record_id' => basename($f, '.json'), 'error' => 'JSON_RUSAK']; continue; }
        if (!empty($filter['plan_type']) && strcasecmp((string)$r['plan_type'], (string)$filter['plan_type']) !== 0) continue;
        $L[] = ['record_id' => $r['record_id'], 'plan_type' => $r['plan_type'], 'plan_date' => $r['plan_date'] ?? '', 'name' => $r['name'] ?? '', 'updated_at' => $r['updated_at'] ?? null,
                'schema_version' => $r['schema_version'] ?? 1, 'engine_version_at_save' => $r['engine_version_at_save'] ?? null,
                /* record v1 lama tidak pernah menyimpan checksum: tidak dapat diverifikasi (bukan rusak) */
                'checksum_ok' => isset($r['checksum']) ? hash_equals((string)$r['checksum'], sds_checksum(sds_migrate($r))) : null, 'legacy_unverified' => !isset($r['checksum'])]; }
    usort($L, function ($a, $b) { return [$a['plan_type'] ?? '', $a['plan_date'] ?? '', $a['name'] ?? ''] <=> [$b['plan_type'] ?? '', $b['plan_date'] ?? '', $b['name'] ?? '']; });
    return $L;
}
function sds_update_meta(string $id, array $meta, ?string &$why = null): ?array {
    $r = sds_load($id); if (!$r) { $why = 'record tidak ditemukan'; return null; } unset($r['integrity']);
    $r['meta'] = array_merge((array)($r['meta'] ?? []), $meta); $r['updated_at'] = date('c');          // metadata tidak mengubah checksum isi
    return sds_atomic_write_json(sds_rec_dir() . DIRECTORY_SEPARATOR . $r['record_id'] . '.json', $r, $why, 'records/' . $r['record_id']) ? $r : null;
}
/* hapus dengan konfirmasi: $confirm wajib sama dengan record_id; berkas dipindah ke backups/deleted (dapat dipulihkan) */
function sds_delete(string $id, string $confirm, ?string &$why = null): bool {
    $id = preg_replace('~[^A-Za-z0-9_]~', '', $id);
    if ($confirm !== $id) { $why = 'KONFIRMASI_DIPERLUKAN: kirim confirm=' . $id; return false; }
    $f = sds_rec_dir() . DIRECTORY_SEPARATOR . $id . '.json'; if (!is_file($f)) { $why = 'record tidak ditemukan'; return false; }
    if (sds_backup($f, 'deleted') === null) { $why = 'backup sebelum hapus gagal; record TIDAK dihapus'; return false; }
    return @unlink($f) || ($why = 'hapus gagal') === null;
}
function sds_integrity(): array {
    $bad = []; $legacy = []; $n = 0; foreach (sds_list() as $r) { $n++; if (!empty($r['legacy_unverified'])) { $legacy[] = $r['record_id']; continue; } if (empty($r['checksum_ok'])) $bad[] = $r['record_id']; }
    $st = sds_state_mirror(); $sOk = !is_file($st) || is_array(json_decode((string)@file_get_contents($st), true));
    return ['records' => $n, 'checksum_fail' => $bad, 'legacy_v1_unverified' => $legacy, 'state_mirror_ok' => $sOk, 'root' => sds_root(), 'status' => (!$bad && $sOk) ? 'PASS' : 'FAIL'];
}

/* ---- HTTP (dipanggil run.php): mode=store_list | store_load | store_meta | store_delete | store_integrity ---- */
function sds_http(string $mode): void {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8'); $why = null;
    $body = json_decode((string)file_get_contents('php://input'), true); $body = is_array($body) ? $body : [];
    $id = (string)($_GET['id'] ?? $body['id'] ?? '');
    if ($mode === 'store_list') $r = ['ok' => true, 'records' => sds_list(['plan_type' => $_GET['plan_type'] ?? null])];
    elseif ($mode === 'store_load') { $x = sds_load($id); $r = $x ? ['ok' => true, 'record' => $x, 'note' => 'Jalankan input_payload dengan engine terbaru (run.php?mode=run); record tidak membawa engine.'] : ['ok' => false, 'error' => 'TIDAK_DITEMUKAN']; }
    elseif ($mode === 'store_meta') { $x = sds_update_meta($id, (array)($body['meta'] ?? []), $why); $r = $x ? ['ok' => true, 'record_id' => $x['record_id'], 'meta' => $x['meta']] : ['ok' => false, 'error' => $why]; }
    elseif ($mode === 'store_delete') { $ok = sds_delete($id, (string)($_GET['confirm'] ?? $body['confirm'] ?? ''), $why); $r = ['ok' => $ok, 'error' => $ok ? null : $why]; }
    else $r = ['ok' => true] + sds_integrity();
    echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}
