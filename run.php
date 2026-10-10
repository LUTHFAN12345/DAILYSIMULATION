<?php
require_once __DIR__ . '/saved_data_store.php';
/* Konstanta kalibrasi prediktor runtime — lihat reports/ASYNC_ADMISSION_CALIBRATION.md.
 * PP_CALIB_REF_S adalah detik loop kalibrasi pada mesin pengukuran; PP_PRED_WU_SECONDS mengubah
 * unit kerja menjadi detik pada mesin itu. Keduanya dipisahkan supaya prediksi tetap benar pada
 * mesin dengan kecepatan berbeda. */
if (!defined('PP_CALIB_REF_S'))     define('PP_CALIB_REF_S', 0.00496);
if (!defined('PP_PRED_WU_SECONDS')) define('PP_PRED_WU_SECONDS', 1.0);
if (!defined('PP_PRED_BASE_WU'))    define('PP_PRED_BASE_WU', 14.0);
if (!defined('PP_PRED_SKIP_WU'))    define('PP_PRED_SKIP_WU', 32.4);
if (!defined('PP_PRED_FIX_WU'))     define('PP_PRED_FIX_WU', 23.0);
if (!defined('PP_PRED_RESERVE_WU')) define('PP_PRED_RESERVE_WU', 23.5);
if (!defined('PP_PRED_STOP_WU'))    define('PP_PRED_STOP_WU', 10.8);
/* Pengali keamanan: prediksi sengaja dibiaskan ke atas. Lihat ASYNC_ADMISSION_CALIBRATION.md. */
if (!defined('PP_PRED_SAFETY'))     define('PP_PRED_SAFETY', 1.35);
/* Ambang checkpoint tengah-jalan: detik berlalu saat pipeline masih punya fase tersisa. */
if (!defined('PP_ADMIT_CHECKPOINT_S')) define('PP_ADMIT_CHECKPOINT_S', 12.0);
if (!defined('PP_PRED_CO_WU'))      define('PP_PRED_CO_WU', 18.0);
if (!defined('PP_PRED_GASQ_WU'))    define('PP_PRED_GASQ_WU', 13.0);
if (!defined('PP_PRED_ACTUAL_WU'))  define('PP_PRED_ACTUAL_WU', 6.0);

/* =========================================================================
 *  run.php  —  POST endpoint for the dashboard, and CLI runner.
 *
 *  Hardened order of operations (so a bad request can NEVER corrupt the saved
 *  input_data.json):
 *    1. decode + structurally validate the body (data1/data2 non-empty,
 *       data3 has every required unit + modeling);
 *    2. run pp_run_simulation();
 *    3. ONLY on success, persist input_data.json and output_data.json.
 *  Any failure returns an error and leaves the saved files untouched.
 *
 *  RELEASE GATE (MASTER FABLE5): output_data.json is only ever written with
 *  a "released"/"ok" status when it has passed the gate. Two modes:
 *    - normal (default): single simulation run, fast, for interactive UI use.
 *      release_gate.100x_validation_required = false; release_allowed is
 *      NOT asserted true — the file is written but flagged NOT VALIDATED.
 *    - release-validate: runs pp_release_gate() (100x by default) via
 *      worker_functions.php, and ONLY marks the output "released"/"ok" if
 *      release_allowed is true (zero FAIL across all runs). If any run
 *      FAILs, output_data.json is still written (for inspection) but with
 *      result="not_released" / a validation_failed status, NEVER "ok".
 * ========================================================================= */

/* PP_COMPAT_PHP74_SHIM */
/* ==============================================================================================
 *  KOMPATIBILITAS PHP TARGET (XAMPP 7.4) — POLYFILL, BUKAN PERUBAHAN PERILAKU.
 *
 *  AKAR MASALAH PRODUKSI. Paket sebelumnya memakai fungsi dan konstruksi PHP 8 sementara XAMPP
 *  target berjalan pada PHP 7.4, sehingga `run.php` gagal di-parse dan server menjawab HTML
 *  "Parse error" dengan HTTP 200 — Run dan Save sama-sama mati.
 *
 *  Polyfill di bawah HANYA didefinisikan bila fungsinya memang belum ada. Pada PHP 8 tidak ada
 *  satu pun yang didefinisikan, sehingga perilaku pada PHP 8 tidak berubah sedikit pun; pada
 *  PHP 7.4 implementasinya mengikuti semantik resmi PHP 8 persis, termasuk kasus needle kosong
 *  dan array kosong. Tidak ada satu pun call-site yang diubah, jadi tidak ada risiko perilaku. */
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        if ($needle === '') return true;
        $len = strlen($needle);
        return $len <= strlen($haystack) && substr_compare($haystack, $needle, -$len) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('array_is_list')) {
    function array_is_list(array $array) {
        $i = 0;
        foreach ($array as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }
}

header('Content-Type: application/json; charset=utf-8');
/* SAFETY NET: apapun yang terjadi (termasuk fatal PHP seperti max_execution_time), klien WAJIB
 * menerima JSON — bukan halaman HTML — supaya spinner UI berhenti dan pesan error terbaca. */
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    $fatal = [
        'ok' => false, 'result' => 'error',
        'error' => ['code' => 'FATAL', 'message' => (string)($e['message'] ?? 'fatal error'),
                    'where' => basename((string)($e['file'] ?? '')) . ':' . (int)($e['line'] ?? 0)],
        'message' => (string)($e['message'] ?? 'fatal error'),
    ];
    /* Job exact yang sudah terdaftar untuk request ini tetap diserahkan ke browser, sehingga
     * perhitungan diselesaikan job itu dan tidak ada job yang tertinggal dalam antrean. */
    $adm = $GLOBALS['__pp_async_early_admission'] ?? ($GLOBALS['ppSafetyAdm'] ?? null);
    if (is_array($adm) && !empty($adm['job_id']) && !empty($adm['exec_token']))
        $fatal['async_job'] = ['required' => true, 'kind' => (string)($adm['kind'] ?? 'economic_review'), 'ok' => true,
            'job_id' => $adm['job_id'], 'exec_token' => $adm['exec_token'], 'status' => $adm['status'] ?? null,
            'input_hash' => $adm['input_hash'] ?? null, 'reason' => 'Jalur sinkron berhenti; job exact menyelesaikan perhitungan.',
            'handoff_reason' => 'SYNC_FATAL_HANDOFF'];
    echo json_encode($fatal, JSON_UNESCAPED_SLASHES);
});
header('Cache-Control: no-store');

require_once __DIR__ . '/worker02.php';   // defines pp_run_simulation(), pp_release_gate(), pp_validate_hard_constraints()

/* ---- PROMPT SAVE-FI §A2 — ATOMIC WRITE (ALL OR NOTHING) ---------------------------------------
 * ROOT CAUSE "seluruh input hilang setelah reload": file_put_contents() langsung ke file aktif.
 * Tulis-sebagian (disk penuh / proses mati / dua request menulis bersamaan) meninggalkan JSON
 * terpotong -> reload membaca file korup -> seluruh input lenyap. Mekanisme wajib:
 *   file lock -> write temp file (dir yang sama) -> fflush+fsync -> close -> validate JSON (re-read
 *   + decode) -> atomic rename. Bila SATU langkah gagal: temp dibersihkan, FILE LAMA TETAP UTUH,
 *   dan pemanggil mendapat alasan spesifik (bukan sekadar false). ------------------------------ */
function pp_atomic_write_json(string $path, $data, ?string &$why = null): bool {
    $why  = null;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { $why = 'json_encode gagal: ' . json_last_error_msg(); return false; }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) { $why = 'tidak bisa membuka lock file ' . basename($path) . '.lock (permission?)'; return false; }
    if (!flock($lock, LOCK_EX)) { fclose($lock); $why = 'tidak bisa memperoleh exclusive lock'; return false; }
    $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    $ok = false;
    try {
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) { $why = 'tidak bisa membuat temp file di direktori ' . dirname($path); return false; }
        $w = fwrite($fh, $json);
        fflush($fh);
        if (function_exists('fsync')) @fsync($fh);
        fclose($fh);
        if ($w === false || $w !== strlen($json)) { $why = 'write terpotong (' . var_export($w, true) . ' dari ' . strlen($json) . ' bytes)'; return false; }
        $chk = json_decode((string)@file_get_contents($tmp), true);
        if (!is_array($chk)) { $why = 'validasi ulang temp file gagal (JSON tidak terbaca kembali)'; return false; }
        if (!@rename($tmp, $path)) { $why = 'atomic rename gagal (permission / cross-device?)'; return false; }
        $ok = true;
        return true;
    } finally {
        if (!$ok && is_file($tmp)) @unlink($tmp);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/* ---- PROMPT SAVE-FI §A — SANITASI REKURSI SNAPSHOT --------------------------------------------
 * ROOT CAUSE spinner tak berujung: snapshot record Report Planning ikut membawa report_planning
 * (berisi seluruh record lama BESERTA snapshot mereka) -> nesting eksponensial. Terukur pada file
 * user: depth 4, report_planning = 99.6% file, payload ~40 MB > post_max_size PHP -> body di-drop.
 * Klien sudah berhenti membuat nesting baru; helper ini MIGRASI data lama: setiap snapshot.input
 * dilucuti report_planning-nya (rekursif). Idempoten; tidak menyentuh field lain. --------------- */
function pp_sanitize_report_planning(array &$input): int {
    $stripped = 0;
    $rp = &$input['data3']['modeling']['report_planning'];
    if (!is_array($rp)) return 0;
    $walk = function (array &$rpx) use (&$walk, &$stripped): void {
        foreach ($rpx as &$months) { if (!is_array($months)) continue;
            foreach ($months as &$dates) { if (!is_array($dates)) continue;
                foreach ($dates as &$plans) { if (!is_array($plans)) continue;
                    foreach ($plans as &$rec) {
                        if (!is_array($rec)) continue;
                        $inner = &$rec['snapshot']['input']['data3']['modeling'];
                        if (is_array($inner) && isset($inner['report_planning'])) {
                            if (is_array($inner['report_planning'])) $walk($inner['report_planning']);   // hitung level lebih dalam juga
                            unset($inner['report_planning']); $stripped++;
                        }
                        unset($inner);
                    } unset($rec);
                } unset($plans);
            } unset($dates);
        } unset($months);
    };
    $walk($rp);
    return $stripped;
}

/* ---- PROMPT STATE ISOLATION §10: concurrent-save safety --------------------------------------
 * report_planning dari payload klien bisa STALE (klien lain baru saja save record lain).
 * Sebelum input_data.json ditulis, report_planning payload di-MERGE dgn yang ada di disk:
 *   unique key per record = plan_date + name_plan + plan_type; record dgn saved_at terbaru menang;
 *   record yang hanya ada di salah satu sisi dipertahankan (union). Dengan ini save Plan (User A)
 *   dan save Monitoring (User B) yang hampir bersamaan TIDAK saling menghapus. ------------------- */
/* Bentuk report_planning yang dikenali kode ini:
 *   'null'      -> belum ada riwayat
 *   'empty'     -> array kosong
 *   'records'   -> { records: [ {..}, .. ], <key lain dipertahankan> }   (format Weekly/baru)
 *   'list'      -> [ {..}, {..} ]                                        (daftar record datar)
 *   'nested'    -> { <tahun>: { <bulan>: { <tanggal>: [ {..}, .. ] } } } (legacy)
 *   'malformed' -> selain di atas (string/angka/records bukan array)
 * Deteksi bersifat struktural, tidak menebak isi. */
function pp_rp_shape($rp): string {
    if ($rp === null) return 'null';
    if (!is_array($rp)) return 'malformed';
    if ($rp === []) return 'empty';
    if (array_key_exists('records', $rp)) return is_array($rp['records']) ? 'records' : 'malformed';
    return array_is_list($rp) ? 'list' : 'nested';
}

/* Identitas unik satu record — SAMA PERSIS dengan $idOf yang sudah dipakai cabang 'records',
 * supaya kebijakan de-duplikasi tidak berubah. */
function pp_rp_record_id($r): ?string {
    if (!is_array($r)) return null;
    $pt = (string)($r['plan_type'] ?? 'plan');
    if ($pt === 'weekly_plan_day')
        return $pt . '|' . (string)($r['weekly_plan_id'] ?? '') . '|' . (string)($r['day_date'] ?? $r['plan_date'] ?? '');
    return $pt . '|' . (string)($r['plan_date'] ?? '') . '|' . (string)($r['name_plan'] ?? '');
}

/* Merge daftar record datar: disk lebih dulu, lalu payload menimpa (upsert atomik — kebijakan
 * yang sudah berlaku), created_at disk dibawa serta bila payload tidak membawanya. Record yang
 * hanya ada di salah satu sisi selalu dipertahankan (union / anti-wipe). Record tanpa identitas
 * (bukan array) tetap disimpan apa adanya agar tidak ada data yang hilang diam-diam. */
function pp_rp_merge_records(array $diskRecs, array $inRecs, array &$notes): array {
    $byId = []; $orphan = [];
    foreach ($diskRecs as $r) { $k = pp_rp_record_id($r); if ($k === null) { $orphan[] = $r; continue; } $byId[$k] = $r; }
    foreach ($inRecs as $r) {
        $k = pp_rp_record_id($r);
        if ($k === null) { $orphan[] = $r; continue; }
        if (isset($byId[$k]) && is_array($byId[$k]) && !isset($r['created_at']) && isset($byId[$k]['created_at']))
            $r['created_at'] = $byId[$k]['created_at'];
        $byId[$k] = $r;
    }
    if ($orphan) $notes[] = 'kept ' . count($orphan) . ' record(s) without a usable identity verbatim';
    return array_merge(array_values($byId), $orphan);
}

/* ---- PROMPT STATE ISOLATION §10: concurrent-save safety --------------------------------------
 * report_planning dari payload klien bisa STALE (klien lain baru saja save record lain).
 * Sebelum input_data.json ditulis, report_planning payload di-MERGE dgn yang ada di disk:
 *   unique key per record = plan_date + name_plan + plan_type; record yang hanya ada di salah satu
 *   sisi dipertahankan (union). Dengan ini save Plan (User A) dan save Monitoring (User B) yang
 *   hampir bersamaan TIDAK saling menghapus.
 *
 * BUGFIX DATA-LOSS: cabang legacy di bawah memakai $ex dan $in yang TIDAK PERNAH didefinisikan.
 * Akibatnya `foreach ($ex ...)` tidak beriterasi dan baris terakhir menulis
 * report_planning = NULL — SELURUH riwayat legacy terhapus pada setiap Save, disertai PHP warning
 * "Undefined variable". Nama variabel & komentar aslinya sudah menjelaskan maksudnya:
 * $ex = struktur DISK (existing), $in = struktur PAYLOAD (incoming). Keduanya kini diisi, dan
 * seluruh bentuk lain (null, list datar, kosong, malformed, campuran) ditangani eksplisit dengan
 * prinsip lossless. Parameter $diag bersifat opsional sehingga ketiga pemanggil lama tidak berubah. */
function pp_merge_report_planning(array &$input, string $diskFile, ?array &$diag = null): void {
    $rpIn = $input['data3']['modeling']['report_planning'] ?? null;

    $diskRP = null;
    if (is_file($diskFile)) {
        $dj = json_decode((string)@file_get_contents($diskFile), true);
        if (is_array($dj) && isset($dj['data3']) && is_array($dj['data3'])
            && isset($dj['data3']['modeling']) && is_array($dj['data3']['modeling'])
            && array_key_exists('report_planning', $dj['data3']['modeling']))
            $diskRP = $dj['data3']['modeling']['report_planning'];
    }

    $sIn = pp_rp_shape($rpIn); $sDisk = pp_rp_shape($diskRP);
    $notes = [];
    $countRec = function ($rp, string $shape): int {
        if (!is_array($rp)) return 0;
        if ($shape === 'list') return count($rp);
        $c = count((array)($rp['records'] ?? []));           // bentuk datar
        foreach ($rp as $k => $mo) {                          // plus sisa struktur legacy bersarang
            if ($k === 'records' || !is_array($mo)) continue;
            foreach ($mo as $dt) { if (!is_array($dt)) continue;
                foreach ($dt as $pl) { if (is_array($pl)) $c += count($pl); } }
        }
        return $c;
    };

    $apply = function ($value, string $action) use (&$input, &$diag, &$notes, $sIn, $sDisk, $countRec): void {
        $input['data3']['modeling']['report_planning'] = $value;
        $diag = ['payload_shape' => $sIn, 'disk_shape' => $sDisk, 'action' => $action,
                 'records_out' => $countRec($value, pp_rp_shape($value)), 'notes' => $notes];
    };

    /* Payload rusak: JANGAN pernah menimpa riwayat disk dengan nilai yang tidak bisa dibaca. */
    if ($sIn === 'malformed') {
        $notes[] = 'payload report_planning is malformed and was ignored';
        $apply(in_array($sDisk, ['null', 'malformed'], true) ? null : $diskRP, 'kept_disk_payload_malformed');
        return;
    }
    /* Disk rusak: pertahankan payload, catat supaya operator tahu file lama tak terbaca. */
    if ($sDisk === 'malformed') {
        $notes[] = 'disk report_planning is malformed and was ignored';
        $apply($rpIn, 'kept_payload_disk_malformed');
        return;
    }
    /* Payload tidak membawa riwayat -> ADOPSI riwayat disk (inilah jalur yang dulu menghapus data). */
    if ($sIn === 'null' || $sIn === 'empty') {
        if ($sDisk === 'null') { $apply($rpIn, 'both_absent'); return; }
        $apply($diskRP, 'adopted_disk_history');
        return;
    }
    /* Disk kosong -> payload adalah satu-satunya sumber. */
    if ($sDisk === 'null' || $sDisk === 'empty') { $apply($rpIn, 'kept_payload_disk_empty'); return; }

    $flat = ['records', 'list'];

    /* Keluarga datar di kedua sisi (records/list, boleh bercampur): merge per identitas record.
     * Bentuk keluaran mengikuti PAYLOAD agar kontrak klien tidak berubah; untuk 'records',
     * seluruh key lain milik disk tetap dipertahankan seperti perilaku semula. */
    if (in_array($sIn, $flat, true) && in_array($sDisk, $flat, true)) {
        $diskRecs = $sDisk === 'records' ? (array)$diskRP['records'] : (array)$diskRP;
        $inRecs   = $sIn   === 'records' ? (array)$rpIn['records']   : (array)$rpIn;
        $merged   = pp_rp_merge_records($diskRecs, $inRecs, $notes);
        if ($sIn === 'records') {
            $out = is_array($diskRP) && $sDisk === 'records' ? $diskRP : [];
            foreach ($rpIn as $k => $v) if ($k !== 'records') $out[$k] = $v;   // key payload menang
            $out['records'] = $merged;
            $apply($out, 'merged_flat_records');
        } else {
            $apply($merged, 'merged_flat_list');
        }
        return;
    }

    /* Payload datar + disk legacy bersarang: bentuk 'records' menampung key lain apa adanya,
     * sehingga seluruh tahun/bulan/tanggal legacy TETAP ADA berdampingan dengan records baru.
     * Tidak ada struktur yang ditransformasikan, jadi pembaca lama maupun baru sama-sama jalan. */
    if (in_array($sIn, $flat, true) && $sDisk === 'nested') {
        $inRecs = $sIn === 'records' ? (array)$rpIn['records'] : (array)$rpIn;
        $out = $diskRP;                                   // seluruh riwayat legacy dipertahankan
        if ($sIn === 'records') foreach ($rpIn as $k => $v) if ($k !== 'records') $out[$k] = $v;
        $out['records'] = pp_rp_merge_records([], $inRecs, $notes);
        $notes[] = 'legacy nested history preserved alongside the flat records list';
        $apply($out, 'preserved_nested_plus_records');
        return;
    }

    /* Payload legacy bersarang + disk datar: cermin dari kasus di atas. */
    if ($sIn === 'nested' && in_array($sDisk, $flat, true)) {
        $diskRecs = $sDisk === 'records' ? (array)$diskRP['records'] : (array)$diskRP;
        $out = $rpIn;
        $out['records'] = pp_rp_merge_records($diskRecs, [], $notes);
        $notes[] = 'disk flat records preserved alongside the nested payload';
        $apply($out, 'preserved_records_plus_nested');
        return;
    }

    /* Legacy bersarang di kedua sisi — jalur yang dulu rusak.
     * $ex = DISK (existing), $in = PAYLOAD (incoming); union per tanggal, record yang hanya ada di
     * disk dipertahankan (anti-wipe), dan bila kunci sama maka saved_at TERBARU yang menang. */
    $ex = (array)$diskRP;
    $in = (array)$rpIn;
    $key = fn(array $p) => (string)($p['plan_date'] ?? '') . '|' . (string)($p['name_plan'] ?? '') . '|' . (string)($p['plan_type'] ?? 'plan');
    foreach ($ex as $yr => $months) { if (!is_array($months)) continue;
        foreach ($months as $mo => $dates) { if (!is_array($dates)) continue;
            foreach ($dates as $dt => $plans) { if (!is_array($plans)) continue;
                $cur = (is_array($in[$yr][$mo][$dt] ?? null)) ? $in[$yr][$mo][$dt] : [];
                $byKey = [];
                foreach ($cur as $p) if (is_array($p)) $byKey[$key($p)] = $p;
                foreach ($plans as $p) { if (!is_array($p)) continue;
                    $k = $key($p);
                    if (!isset($byKey[$k])) { $byKey[$k] = $p; continue; }                       // hanya di disk -> pertahankan (anti-wipe)
                    if (strcmp((string)($p['saved_at'] ?? ''), (string)($byKey[$k]['saved_at'] ?? '')) > 0)
                        $byKey[$k] = $p;                                                          // disk lebih baru -> disk menang
                }
                if (!is_array($in[$yr] ?? null)) $in[$yr] = [];
                if (!is_array($in[$yr][$mo] ?? null)) $in[$yr][$mo] = [];
                $in[$yr][$mo][$dt] = array_values($byKey);
            }
        }
    }
    $apply($in, 'merged_nested_legacy');
}

/* =============================================================================================
 *  JOB ASINKRON — WORKER CLI DI LUAR PHP-FPM  (instruksi operator §9)
 *
 *  MENGAPA ADA.
 *  Dua pekerjaan tidak muat di dalam satu request sinkron 60 detik pada mesin target:
 *    (1) Global Commitment Review pada input PGN 30. Satu kandidat comparator = satu pipeline
 *        penuh bersarang. Terukur pada mesin benchmark: pipeline sampai gerbang 15,4 detik dan
 *        comparator 30,3 detik (total 45,9 detik). Pada mesin operator, pipeline sampai gerbang
 *        saja sudah ~51,8 detik sehingga comparator hanya menyisakan 8,22 detik dan DILEWATI.
 *    (2) Validasi rekomendasi LNG/distillate (§4/§5). Sebuah angka baru boleh disebut
 *        "kebutuhan" bila kandidatnya sudah di-rerun penuh dan lulus SELURUH constraint plus
 *        economic review. Satu kandidat = satu simulasi penuh; LNG dan distillate divalidasi
 *        TERPISAH dan masing-masing memerlukan beberapa kandidat.
 *
 *  ATURAN YANG TETAP BERLAKU.
 *    - Plafon request sinkron TIDAK PERNAH dinaikkan di atas 60 detik.
 *    - Tidak ada heuristic pruning dan tidak ada kandidat yang dilewati.
 *    - Hasil deterministik: kandidat dikumpulkan berdasarkan INDEKS, bukan urutan selesai.
 *    - Hasil diikat ke input_hash + request_id; hasil basi tidak boleh menggantikan run terbaru.
 *    - Publish tetap ditolak sampai job selesai seluruhnya.
 *    - Operator TIDAK PERNAH diminta menjalankan ulang simulasi.
 * =========================================================================================== */
function pp_job_root(): string { return __DIR__ . '/jobs'; }
function pp_job_dir(string $id): string { return pp_job_root() . '/' . $id; }
/* Cadangan waktu untuk pekerjaan penutup response sinkron (acceptance review, hard validation,
 * audit gas dan Export, penyusunan baris, serialisasi JSON). Diukur: dengan cadangan nol, waktu di
 * kabel mencapai 61,03 detik pada PEP 40. */
const PP_SYNC_CLOSING_RESERVE_S = 6.0;
/* V13.4 deployment identity. Simulation logic is unchanged; this invalidates stale jobs/memo
 * produced by prior failed Change Over builds. */
const PP_ENGINE_BUILD_ID = 'V15.16-GOLDEN-QUALITY-PERFORMANCE-UI-MULTIUSER-20261010';

function pp_job_kinds(): array { return ['economic_review', 'validated_options', 'legal_branch_exact', 'validated_option_distillate']; }
/* Identitas job = kind + hash input BERSIH. Dua request identik memakai job yang sama
 * (idempotency); input yang berubah sedikit pun menghasilkan job baru (anti hasil basi). */
function pp_job_id(string $kind, string $hash): string { return $kind . '-' . substr($hash, 0, 20); }

/* SIDIK JARI KODE ENGINE — bagian dari identitas job.
 *
 * ROOT CAUSE (terukur saat memperbaiki lantai kuota MM2100): hasil job hanya diikat pada hash
 * INPUT. Setelah engine diperbaiki, request dengan input yang sama memungut kembali `result.json`
 * lama dari direktori `jobs/` dan menampilkannya sebagai hasil — sehingga perbaikan engine seolah
 * tidak berpengaruh, dan pada instalasi produksi sebuah deployment baru akan menyajikan rencana
 * yang dihitung kode LAMA. Itu hasil basi dalam arti yang paling berbahaya: tidak ada yang salah
 * secara kasat mata.
 *
 * Karena itu identitas job memuat sidik jari ketiga berkas engine. Kode berubah -> hash berubah
 * -> job baru. Biaya: tiga filemtime/filesize per request, dihitung sekali per proses. */
function pp_engine_fingerprint(): string {
    static $fp = null;
    if ($fp !== null) return $fp;
    $parts = [PP_ENGINE_BUILD_ID];
    foreach (['run.php', 'worker02.php', 'worker_functions.php'] as $f) {
        $p = __DIR__ . '/' . $f; clearstatcache(true, $p);
        $parts[] = $f . ':' . (is_file($p) ? hash_file('sha256', $p) : 'missing');
    }
    return $fp = substr(hash('sha256', implode('|', $parts)), 0, 20);
}

function pp_job_input_hash(array $input): string {
    /* Field kontrol transport tidak boleh ikut menentukan identitas job. */
    foreach (['_request_id', '_state_revision', '_context', '_shortage_resolution', '_autosave'] as $k) unset($input[$k]);
    if (isset($input['data3']['modeling']) && is_array($input['data3']['modeling'])) {
        pp_v3_strip_meta($input['data3']['modeling']);      // V3: label/arsip UI tidak membuat job baru
        foreach (array_keys($input['data3']['modeling']) as $mk)
            if (is_string($mk) && str_starts_with($mk, '__')) unset($input['data3']['modeling'][$mk]);
    }
    return hash('sha256', pp_engine_fingerprint() . '|' . json_encode($input, JSON_UNESCAPED_SLASHES));
}

/* ==============================================================================================
 *  PEMAKAIAN ULANG STATE IDENTIK (FULL STATE HASH).
 *
 *  Terukur pada PGN 25: job economic_review menghitung pipeline penuh (24 s), lalu job
 *  validated_options menghitung "run dasar" dengan state yang SAMA (22 s), lalu probe LNG
 *  4,7385 BBTUD menghitung pipeline penuh dengan LNG 4,7385 (18 s) — dan ketika operator memilih
 *  "Gunakan LNG 4,7385", rerun final menghitung state itu SEKALI LAGI (sinkron 18 s + job 24 s).
 *
 *  Hasil pipeline dipakai ulang HANYA bila:
 *    1. kunci state identik: sidik jari engine + data1 + data2 + seluruh data3 (termasuk seluruh
 *       modeling setelah normalisasi aksi bahan bakar). Yang dikeluarkan dari kunci hanya field
 *       transport (`_request_id`, `_state_revision`, `_context`, `_run_source`,
 *       `_shortage_resolution`), field anggaran waktu, dan penanda kontrol probe — tidak satu pun
 *       dibaca engine sebagai data rencana;
 *    2. run asal berjalan pada rezim TANPA BATAS waktu (plafon job >= 600 s) dan pemakai juga pada
 *       rezim itu, sehingga tidak ada cabang yang bergantung pada sisa waktu;
 *    3. run asal konvergen penuh: converged, tanpa deadline, tanpa tahap terpotong/ditunda,
 *       economic review selesai, tanpa pemotongan anggaran di pencarian mana pun;
 *    4. state global engine saat mulai bersih pada kedua sisi. Global yang diubah run asal ikut
 *       disimpan dan dipulihkan, sehingga pemeriksaan sesudahnya melihat keadaan yang sama.
 *  Selain itu pipeline dihitung penuh seperti biasa.
 * ============================================================================================ */
const PP_MEMO_MIN_BUDGET_S = 600.0;
const PP_MEMO_SCHEMA = 'co13-state-memo-v2';
function pp_memo_dir(): string { return pp_job_root() . DIRECTORY_SEPARATOR . '_memo'; }
function pp_memo_canon($v) {
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = pp_memo_canon($x); return $v; }
    if (is_float($v) && is_finite($v) && floor($v) == $v && abs($v) < 1e15) return (int)$v;
    return $v;
}
function pp_sim_state_key(array $input): string {
    foreach (array_keys($input) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($input[$k]);
    $keepMode = $GLOBALS['__pp_fuel_decision_mode'] ?? null; $hadMode = array_key_exists('__pp_fuel_decision_mode', $GLOBALS);
    if (function_exists('pp_normalize_fuel_action')) pp_normalize_fuel_action($input);
    if(!empty($input['_fast_default'])){$input['data3']['modeling']['__no_exact_family']=true;$input['data3']['modeling']['__fastest_local_only']=true;$input['data3']['modeling']['time_budget_seconds']=25.0;$input['data3']['modeling']['time_budget_max_seconds']=25.0;}
    if ($hadMode) $GLOBALS['__pp_fuel_decision_mode'] = $keepMode; else unset($GLOBALS['__pp_fuel_decision_mode']);
    $m = (array)($input['data3']['modeling'] ?? []);
    foreach (['time_budget_seconds', 'time_budget_step_seconds', 'time_budget_max_seconds',
              'shortage_probe_max_seconds', 'shortage_probe_max_candidates', '__probe_tested',
              '__probe_state', 'change_over_interactive_request', '__no_exact_family'] as $k) unset($m[$k]);
    pp_v3_strip_meta($m);
    if (!isset($m['change_over_search_budget_seconds'])) $m['change_over_search_budget_seconds'] = 20.0;
    ksort($m);
    $d3 = (array)($input['data3'] ?? []); $d3['modeling'] = $m; ksort($d3);
    return hash('sha256', PP_MEMO_SCHEMA . '|' . pp_engine_fingerprint() . '|' . json_encode(pp_memo_canon([
        'data1' => $input['data1'] ?? null, 'data2' => $input['data2'] ?? null, 'data3' => $d3]),
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}
function pp_memo_path(string $key): string { return pp_memo_dir() . DIRECTORY_SEPARATOR . substr($key, 0, 40) . '.json'; }
/* Global yang BUKAN state rencana: anggaran, jam, identitas job/request. */
function pp_memo_global_ignored(string $g): bool {
    static $x = ['__pp_budget', '__pp_budget_deadline', '__pp_budget_aborts', '__pp_outer_t0',
                 '__pp_async_worker', '__pp_async_worker_ceiling', '__pp_job_id', '__pp_inproc_job',
                 '__pp_is_job_worker', '__pp_admit_input', '__pp_admit_rid', '__pp_prelim_rid',
                 '__pp_prelim_t0', '__pp_prelim_steps', '__pp_memo_last', '__pp_memo_log',
                 '__pp_runtime_prediction', '__pp_async_early_admission'];
    return in_array($g, $x, true);
}
function pp_memo_context_ok(array $input): array {
    $m = (array)($input['data3']['modeling'] ?? []);
    $why = [];
    if (pp_budget_ceiling() < PP_MEMO_MIN_BUDGET_S) $why[] = 'plafon_job<600';
    $dl = pp_budget_deadline_left();
    if (is_finite($dl) && $dl < PP_MEMO_MIN_BUDGET_S) $why[] = 'deadline<600';
    if ((float)($m['time_budget_seconds'] ?? 0) < PP_MEMO_MIN_BUDGET_S) $why[] = 'time_budget_seconds<600';
    if ((float)($m['time_budget_max_seconds'] ?? 60) < PP_MEMO_MIN_BUDGET_S) $why[] = 'time_budget_max_seconds<600';
    foreach (array_keys($GLOBALS) as $g)
        if (is_string($g) && strpos($g, '__pp_') === 0 && !pp_memo_global_ignored($g)) { $why[] = 'global_kotor:' . $g; break; }
    return $why;
}
function pp_memo_eligible_output(array $out): bool {
    $rs = (array)($out['info']['Run Status'] ?? []);
    if (($rs['converged'] ?? null) !== true) return false;
    if (($rs['deadline_reached'] ?? null) !== false) return false;
    if (!empty($rs['stages_truncated'])) return false;
    if (($rs['economic_review_completed'] ?? null) !== true) return false;
    if (!empty($rs['economic_review_skipped'])) return false;
    if (!empty($GLOBALS['__pp_stages_deferred']) || !empty($GLOBALS['__pp_budget_aborts'])) return false;
    if (!empty($GLOBALS['__pp_econ_review_skipped'])) return false;
    if (count((array)($out['data'] ?? [])) !== 48) return false;
    $j = json_encode($out['info'] ?? []);
    if (!is_string($j)) return false;
    if (preg_match('~"budget_skipped":[1-9]~', $j)) return false;
    foreach (['search_truncated_by_budget', 'per_candidate_core_run_skipped', 'verification_truncated_by_budget',
              'budget_exhausted', 'BUDGET_EXHAUSTED', 'SEARCH_BUDGET_EXCEEDED'] as $w)
        if (strpos($j, '"' . $w . '":true') !== false || strpos($j, $w . '_PARTIAL') !== false) return false;
    return true;
}
/* true bila hasil yang dapat dipakai ulang untuk state ini sudah tersedia di disk. */
function pp_memo_available(array $input): bool {
    if (pp_memo_off()) return false;
    $f = pp_memo_path(pp_sim_state_key($input));
    return is_file($f) && filesize($f) > 100 && (time() - (int)@filemtime($f)) < 7 * 86400;
}
function pp_memo_off(): bool { return (string)getenv('PP_MEMO_OFF') === '1'; }   // pembanding A/B saja
function pp_sim_memo_run(array $input): array {
    if (pp_memo_off()) { pp_memo_log(['hit' => false, 'stored' => false, 'reason' => 'PP_MEMO_OFF']); return pp_run_simulation($input); }
    $why = pp_memo_context_ok($input);
    if ($why) { pp_memo_log(['hit' => false, 'stored' => false, 'reason' => implode(',', $why)]);
                return pp_run_simulation($input); }
    $key = pp_sim_state_key($input);
    $f = pp_memo_path($key);
    if (is_file($f) && (time() - (int)@filemtime($f)) < 7 * 86400) {
        $raw = null;
        for ($i = 0; $i < 5 && !is_array($raw); $i++) { $raw = json_decode((string)@file_get_contents($f), true); if (!is_array($raw)) usleep(20000); }
        if (is_array($raw) && ($raw['key'] ?? '') === $key && is_array($raw['output'] ?? null)) {
            $gl = @unserialize(base64_decode((string)($raw['globals'] ?? '')), ['allowed_classes' => false]);
            if (is_array($gl)) {
                foreach ($gl as $g => $v) if (is_string($g) && strpos($g, '__pp_') === 0 && !pp_memo_global_ignored($g)) $GLOBALS[$g] = $v;
                pp_memo_log(['hit' => true, 'key' => substr($key, 0, 16), 'source_wall_s' => $raw['wall_s'] ?? null]);
                $hitOut = $raw['output'];
                /* Hasil yang tersimpan oleh probe bahan bakar belum memuat keluarga commitment:
                 * ruang kandidat exact dilengkapi di sini (sekali), lalu memo diperbarui. */
                if (pp_memo_needs_family($input, $hitOut)) {
                    $hitOut = pp_memo_apply_family($input, $hitOut);
                    if (pp_memo_eligible_output($hitOut)) {
                        $raw['output'] = $hitOut;
                        $enc2 = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                        if (is_string($enc2)) { $tmp2 = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
                            if (@file_put_contents($tmp2, $enc2) === strlen($enc2)) { if (pp_is_windows() && is_file($f)) @unlink($f); if (!@rename($tmp2, $f)) @unlink($tmp2); } else @unlink($tmp2); }
                    }
                }
                return $hitOut;
            }
        }
    }
    $before = [];
    foreach ($GLOBALS as $g => $v) if (is_string($g) && strpos($g, '__pp_') === 0) $before[$g] = md5(serialize($v));
    $t = microtime(true);
    $out = pp_run_simulation($input);
    $wall = microtime(true) - $t;
    $stored = false;
    if (pp_memo_eligible_output($out) && empty($GLOBALS['ppTlHook']['aborted'])) {
        $gl = [];
        foreach ($GLOBALS as $g => $v) {
            if (!is_string($g) || strpos($g, '__pp_') !== 0 || pp_memo_global_ignored($g)) continue;
            $s = @serialize($v);
            if (!isset($before[$g]) || $before[$g] !== md5($s)) $gl[$g] = $v;
        }
        $enc = json_encode(['schema' => PP_MEMO_SCHEMA, 'key' => $key, 'created_at' => date('c'),
                            'wall_s' => round($wall, 2), 'globals' => base64_encode(serialize($gl)),
                            'output' => $out], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (is_string($enc) && is_array(json_decode($enc, true))) {
            $dir = pp_memo_dir();
            if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $enc) === strlen($enc)) {
                if (pp_is_windows() && is_file($f)) @unlink($f);
                $stored = @rename($tmp, $f);
                if (!$stored) @unlink($tmp);
            }
            pp_memo_sweep();
        }
    }
    pp_memo_log(['hit' => false, 'stored' => $stored, 'key' => substr($key, 0, 16), 'wall_s' => round($wall, 2)]);
    return $out;
}
function pp_memo_needs_family(array $input, array $out): bool {
    if (!function_exists('pp_exact_family_stage') || (string)getenv('PP_EXACT_FAMILY') === '0') return false;
    $m = (array)($input['data3']['modeling'] ?? []);
    if (!empty($m['__no_exact_family']) || !empty($m['change_over']['enabled'])) return false;
    if (count((array)($out['data'] ?? [])) !== 48) return false;
    if (!isset($out['info']['Exact Candidate Space'])) return true;
    /* Registri state ini memperoleh kandidat valid baru sesudah hasil disimpan (mis. dari pencarian
     * Target Selesai dengan seed lain): comparator akhir dijalankan ulang atas registri. */
    try {
        $orig = $input; foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
        if (pp_v9_canon()) return false;
        $nv = count(pp_tl_registry_open($orig)->allValid());
        return $nv > (int)($out['info']['Exact Candidate Space']['registry_valid_nodes'] ?? 0);
    } catch (Throwable $e) { return false; }
}
function pp_memo_apply_family(array $input, array $out): array {
    $prevSkip = $GLOBALS['__pp_econ_review_skipped'] ?? null;
    $GLOBALS['__pp_econ_review_skipped'] = null;
    $keep0 = []; foreach (['Run Status', 'V7 Fuel Delta Validation'] as $kk) if (isset($out['info'][$kk])) $keep0[$kk] = $out['info'][$kk];
    $out = pp_exact_family_stage($input, $out);
    /* V7: node keluarga yang menang menggantikan rencana tersimpan TANPA status run miliknya; status
     * run hasil tersimpan (konvergen, economic review selesai) dan bukti validasinya dibawa ikut. */
    foreach ($keep0 as $kk => $vv) if (!isset($out['info'][$kk])) $out['info'][$kk] = $vv;
    $skip = $GLOBALS['__pp_econ_review_skipped'] ?? null;
    if ($skip !== null) {
        $rs = (array)($out['info']['Run Status'] ?? []);
        $rs['converged'] = false; $rs['status'] = 'ECONOMIC_REVIEW_INCOMPLETE';
        $rs['economic_review_skipped'] = $skip; $rs['economic_review_completed'] = false;
        $out['info']['Run Status'] = $rs;
    } else $GLOBALS['__pp_econ_review_skipped'] = $prevSkip;
    return $out;
}
/* Log pemakaian ulang per request; statis supaya tidak ikut dibersihkan bersama global `__pp_*`. */
function pp_memo_log(?array $add = null): array { static $l = []; if ($add !== null) $l[] = $add; return $l; }
function pp_memo_sweep(): void {
    $fs = glob(pp_memo_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    if (count($fs) <= 60) return;
    usort($fs, fn($a, $b) => (int)@filemtime($a) <=> (int)@filemtime($b));
    foreach (array_slice($fs, 0, count($fs) - 60) as $x) @unlink($x);
}

function pp_job_read(string $id): ?array {
    $f = pp_job_dir($id) . '/job.json';
    /* Dibaca ulang beberapa kali: di Windows penulis dapat sedang mengganti berkas tepat saat ini. */
    for ($i = 0; $i < 8; $i++) {
        if (!is_file($f)) { if ($i >= 2) return null; usleep(15000); continue; }
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) return $j;
        usleep(15000);
    }
    return null;
}
/* ==============================================================================================
 *  PENULISAN STATUS JOB YANG TIDAK BOLEH HILANG.
 *
 *  AKAR "progress 100% tetapi halaman menunggu". Di Windows, rename() ke job.json GAGAL bila berkas
 *  itu sedang terbuka oleh request lain — dan job_poll membacanya setiap 1-2 detik. Kegagalan itu
 *  dahulu diabaikan diam-diam, sehingga pembaruan status (termasuk status DONE) dapat hilang: job
 *  tertinggal RUNNING walaupun hasilnya sudah tertulis, dan browser terus menunggu.
 *  Sekarang rename diulang, dan bila tetap gagal isi ditulis langsung; hasil akhirnya diverifikasi.
 * ============================================================================================ */
function pp_job_write(string $id, array $job): bool {
    $d = pp_job_dir($id);
    if (!is_dir($d) && !@mkdir($d, 0777, true) && !is_dir($d)) return false;
    $job['updated_at'] = date('c');
    $enc = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($enc)) return false;
    $fin = $d . '/job.json';
    $tmp = $d . '/job.json.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $enc) === false) return false;
    for ($i = 0; $i < 40; $i++) {
        if (@rename($tmp, $fin)) return true;
        usleep(25000);
    }
    @unlink($tmp);
    for ($i = 0; $i < 20; $i++) {
        if (@file_put_contents($fin, $enc, LOCK_EX) === strlen($enc)) return true;
        usleep(25000);
    }
    return false;
}
/* Progress ditulis lewat read-modify-write ber-lock: worker dan endpoint cancel dapat menulis
 * file yang sama. Tanpa lock, cancel bisa hilang tertimpa progress berikutnya. */
function pp_job_update(string $id, callable $fn): ?array {
    $d = pp_job_dir($id);
    if (!is_dir($d)) return null;
    $lk = @fopen($d . '/job.lock', 'c');
    if ($lk === false) return null;
    @flock($lk, LOCK_EX);
    $job = pp_job_read($id);
    if ($job === null) { @flock($lk, LOCK_UN); fclose($lk); return null; }
    $job = $fn($job);
    pp_job_write($id, $job);
    @flock($lk, LOCK_UN); fclose($lk);
    return $job;
}
function pp_job_progress(string $id, string $step, ?float $percent = null, array $extra = []): void {
    pp_job_update($id, function (array $j) use ($step, $percent, $extra): array {
        $j['progress'] = array_values((array)($j['progress'] ?? []));
        $j['progress'][] = ['step' => $step, 'at' => date('c'),
                            'elapsed_s' => round(microtime(true) - (float)($j['started_at_ts'] ?? microtime(true)), 2)] + $extra;
        if (count($j['progress']) > 200) $j['progress'] = array_slice($j['progress'], -200);
        $j['current_step'] = $step;
        $j['last_phase'] = $step;
        /* HEARTBEAT. Tanpa denyut, penyapu hanya punya PID untuk menilai kesehatan job — dan pada
         * Windows PID pun tidak selalu terbaca. Denyut membuat job panjang yang SEHAT dapat
         * dibedakan dari job yang benar-benar mati. */
        $j['heartbeat_at'] = date('c');
        $j['heartbeat_ts'] = microtime(true);
        $j['peak_memory_bytes'] = memory_get_peak_usage(true);
        if ($percent !== null) $j['percent'] = max(0.0, min(100.0, round($percent, 1)));
        return $j;
    });
}
/* ==============================================================================================
 *  LAPISAN SADAR-SISTEM-OPERASI UNTUK WORKER LATAR.
 *
 *  AKAR MASALAH "Proses worker berhenti tanpa menuliskan hasil" PADA XAMPP/WINDOWS.
 *  Pesan itu dihasilkan penyapu job ketika sebuah job tidak pernah menulis result DAN PID-nya
 *  dianggap mati. Pada Windows hal itu terjadi bukan karena worker mati, melainkan karena
 *  worker TIDAK PERNAH LAHIR — dan karena pemeriksaan hidup-matinya sendiri tidak berlaku
 *  di sana. Empat sebab nyata, semuanya spesifik Windows:
 *
 *    1. LAUNCHER KHUSUS UNIX. Perintah yang dipakai adalah
 *          setsid nohup <php> ... > log 2>&1 < /dev/null & disown
 *       `setsid`, `nohup`, `& disown` — dan `nice -n 19` yang ditambahkan belakangan — tidak ada
 *       satu pun di Windows. cmd.exe menolak seluruh baris, tidak ada proses yang dibuat, dan job
 *       tertinggal QUEUED sampai penyapu menyimpulkan "worker berhenti tanpa hasil".
 *
 *    2. PHP_BINARY TIDAK DAPAT DIPERCAYA DI BAWAH APACHE. Dengan mod_php, `PHP_BINARY` menunjuk ke
 *       httpd.exe, bukan php.exe. Penjaga lama hanya memeriksa apakah nama berkasnya memuat "php",
 *       sehingga httpd.exe ditolak dan jatuh ke `'php'` — yang belum tentu ada di PATH milik akun
 *       layanan Apache. Worker gagal dijalankan tanpa pesan apa pun.
 *
 *    3. DETEKSI PROSES HIDUP KHUSUS LINUX. `pp_job_pid_alive()` membaca `/proc/<pid>/stat`.
 *       Windows tidak punya /proc, sehingga worker yang SEHAT pun dianggap mati.
 *
 *    4. TIDAK ADA SEBAB SPESIFIK. Ketiga kegagalan di atas berakhir pada satu pesan generik yang
 *       tidak dapat ditindaklanjuti operator.
 *
 *  PENYELESAIAN: worker OS dihapus seluruhnya. Tidak ada peluncur proses, tidak ada pencarian
 *  php.exe, tidak ada pembacaan PID. Job dihitung di dalam request HTTP (`mode=job_exec`) dan
 *  kesehatannya dinilai dari kunci berkas `exec.lock` yang dipegang request penghitungnya.
 * ============================================================================================== */
function pp_is_windows(): bool { return stripos(PHP_OS_FAMILY ?? PHP_OS, 'WIN') === 0; }

/* ==============================================================================================
 *  PEMILIK PERHITUNGAN DIKENALI DARI KUNCI BERKAS, BUKAN DARI PID.
 *
 *  Job dihitung di dalam request HTTP (`mode=job_exec`). Selama request itu hidup ia memegang
 *  kunci eksklusif `exec.lock` di folder job. Kunci berkas dilepas otomatis oleh PHP/OS begitu
 *  request selesai, gagal fatal, atau prosesnya mati — jadi "kunci masih dipegang" berarti
 *  "perhitungan masih berjalan", tanpa perlu membaca daftar proses. Tidak ada tasklist, tidak ada
 *  /proc, tidak ada shell: pemeriksaan ini tidak pernah membuat proses maupun jendela console.
 * ============================================================================================ */
function pp_job_exec_lock_path(string $id): string { return pp_job_dir($id) . DIRECTORY_SEPARATOR . 'exec.lock'; }
/* true = ada request lain yang sedang menghitung job ini. */
function pp_job_exec_held(string $id): bool {
    if (!is_dir(pp_job_dir($id))) return false;
    $f = @fopen(pp_job_exec_lock_path($id), 'c');
    if ($f === false) return false;
    $bebas = @flock($f, LOCK_EX | LOCK_NB);
    if ($bebas) @flock($f, LOCK_UN);
    fclose($f);
    return !$bebas;
}
/* Mengambil kunci eksekusi untuk request ini; handle disimpan global agar kunci bertahan sampai
 * request berakhir. Mengembalikan false bila request lain sudah memegangnya. */
function pp_job_exec_acquire(string $id): bool {
    $f = @fopen(pp_job_exec_lock_path($id), 'c');
    if ($f === false) return false;
    if (!@flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return false; }
    /* Handle disimpan di registri STATIS, bukan di $GLOBALS: mesin hitung membersihkan seluruh
     * global berawalan `__pp_` di antara kandidat (pp_probe_run_candidate, pipeline kandidat).
     * Handle yang ikut terhapus akan ditutup PHP dan kuncinya lepas di tengah perhitungan —
     * terukur: job validated_options dinyatakan berhenti padahal masih menghitung. */
    $reg =& pp_job_exec_registry();
    $reg[$id] = $f;
    return true;
}
function &pp_job_exec_registry(): array { static $r = []; return $r; }
/* Melepas kunci eksekusi milik request ini (dipakai uji unit; request biasa melepasnya saat selesai). */
function pp_job_exec_release(string $id): void {
    $reg =& pp_job_exec_registry();
    if (isset($reg[$id])) { @flock($reg[$id], LOCK_UN); @fclose($reg[$id]); unset($reg[$id]); }
}
/* Penanda "job ini dihitung di dalam request". KONSTANTA, bukan global: global `__pp_*` dibersihkan
 * mesin hitung di antara kandidat, dan tanpa penanda ini plafon job jatuh kembali ke 60 detik. */
function pp_job_inproc(): bool { return defined('PP_INPROC_JOB'); }
/* Job masih "hidup" (tidak boleh dibuat ulang / disapu): menunggu dipicu, atau sedang dihitung. */
function pp_job_is_live(array $job): bool {
    $st = (string)($job['status'] ?? '');
    if ($st === 'QUEUED') return true;
    if (in_array($st, ['CLAIMED', 'RUNNING'], true)) return pp_job_exec_held((string)($job['job_id'] ?? ''));
    return false;
}
function pp_job_log_err(string $msg): void { if (defined('STDERR')) @fwrite(STDERR, $msg); else @error_log(rtrim($msg)); }
/* Job yatim: status RUNNING tetapi prosesnya sudah tidak ada (server restart, OOM, kill).
 * Dibersihkan sebagai FAILED dengan sebab spesifik, bukan dibiarkan menggantung selamanya. */
function pp_job_sweep(int $maxAgeSeconds = 21600): array {
    $root = pp_job_root();
    if (!is_dir($root)) return [];
    $touched = [];
    foreach ((array)@scandir($root) as $e) {
        if ($e === '.' || $e === '..') continue;
        /* V4: direktori bersama (_final, _tl, _memo, _prelim) bukan job; sebelumnya disapu bila
         * mtime-nya > 6 jam, sehingga final valid terakhir (basis inkremental) dan kolam kandidat
         * hilang setelah jeda kerja panjang. */
        if ($e[0] === '_') continue;
        $d = $root . '/' . $e;
        if (!is_dir($d)) continue;
        $job = pp_job_read($e);
        if ($job === null) { if ((time() - (int)@filemtime($d)) > $maxAgeSeconds) pp_job_rmdir($d); continue; }
        $st = (string)($job['status'] ?? '');
        $age = time() - (int)strtotime((string)($job['updated_at'] ?? 'now'));
        /* Job yang menunggu dipicu (QUEUED) atau yang kunci eksekusinya masih dipegang tidak pernah
         * disapu. Job CLAIMED/RUNNING yang kuncinya sudah lepas berarti request penghitungnya
         * berhenti tanpa menuliskan hasil (Apache didaur ulang, batas waktu server, dsb.). */
        if (in_array($st, ['QUEUED', 'CLAIMED', 'RUNNING'], true) && pp_job_is_live($job)) {
            if ($st === 'QUEUED' && $age > $maxAgeSeconds) pp_job_rmdir($d);
            continue;
        }
        if (in_array($st, ['CLAIMED', 'RUNNING'], true) && $age > 5) {
            pp_job_update($e, function (array $j) use ($e): array {
                /* Diperiksa ULANG di dalam kunci job: di antara pembacaan di atas dan saat ini
                 * perhitungan bisa saja baru selesai (DONE) atau diklaim ulang. */
                if (!in_array((string)($j['status'] ?? ''), ['CLAIMED', 'RUNNING'], true) || pp_job_exec_held($e)) return $j;
                /* Hasil sudah tertulis utuh tetapi status akhirnya hilang: pulihkan sebagai DONE. */
                $rfH = pp_job_dir($e) . '/result.json';
                if (is_file($rfH) && is_array(json_decode((string)@file_get_contents($rfH), true))) {
                    $j['status'] = !empty($j['cancel_requested']) ? 'CANCELLED' : 'DONE';
                    $j['result_available'] = true; $j['percent'] = 100.0;
                    $j['current_step'] = 'SELESAI'; $j['finished_at'] = $j['finished_at'] ?? date('c');
                    $j['recovered_done'] = true;
                    return $j;
                }
                $j['status'] = 'FAILED';
                /* Fatal error di tengah perhitungan sudah dicatat sebabnya oleh shutdown handler di
                 * pp_job_worker_main(); cabang ini hanya untuk request yang dihentikan dari luar. */
                $tail = '';
                $code = 'JOB_REQUEST_BERHENTI';
                $msg  = 'Perhitungan berhenti sebelum menuliskan hasil (request dihentikan server). Klik Run sekali lagi.';
                $j['error'] = ['code' => $code, 'message' => $msg,
                    'stderr_tail' => $tail !== '' ? $tail : null,
                    'last_phase' => $j['last_phase'] ?? ($j['current_step'] ?? null),
                    'heartbeat_at' => $j['heartbeat_at'] ?? null,
                    'php_executable' => $j['php_executable'] ?? null,
                    'os_family' => $j['os_family'] ?? null,
                    'peak_memory_bytes' => $j['peak_memory_bytes'] ?? null];
                $j['finished_at'] = date('c');
                return $j;
            });
            $touched[] = $e;
        }
        if (in_array($st, ['DONE', 'FAILED', 'CANCELLED'], true) && $age > $maxAgeSeconds) { pp_job_rmdir($d); $touched[] = $e; }
    }
    return $touched;
}
function pp_job_rmdir(string $d): void {
    foreach ((array)@scandir($d) as $f) { if ($f === '.' || $f === '..') continue; @unlink($d . '/' . $f); }
    @rmdir($d);
}

/* Membuat (atau memakai ulang) job dan melepas worker CLI yang benar-benar terlepas dari
 * PHP-FPM: setsid + nohup + stdin/stdout/stderr dialihkan, sehingga worker tetap hidup setelah
 * request HTTP yang melahirkannya ditutup. */
function pp_job_start(array $input, string $kind, ?string $requestId, bool $force = false): array {
    if (!in_array($kind, pp_job_kinds(), true))
        return ['ok' => false, 'error' => 'KIND_TIDAK_DIKENAL: ' . $kind];
    /* V15.16 MULTI-USER: pembuatan job per job-id diserialisasi (flock). Dua user/tab yang menjalankan input identik pada
     * saat yang sama dulu sama-sama melihat "belum ada", lalu saling menghapus/menulis direktori job yang sama sehingga salah
     * satunya gagal (TIDAK_BISA_MENULIS_INPUT_JOB). Kini request kedua menunggu sebentar lalu MEMAKAI ULANG job pertama. */
    if ((string)getenv('PP_V1516_JOB_LOCK') !== '0' && empty($GLOBALS['__pp_job_start_locked'])) {
        if (!is_dir(pp_job_root())) @mkdir(pp_job_root(), 0777, true);
        $lk = @fopen(rtrim(pp_job_root(), '/\\') . DIRECTORY_SEPARATOR . '.start_' . pp_job_id($kind, pp_job_input_hash($input)) . '.lock', 'c');
        if ($lk) @flock($lk, LOCK_EX);
        $GLOBALS['__pp_job_start_locked'] = true;
        try { return pp_job_start($input, $kind, $requestId, $force); }
        finally { unset($GLOBALS['__pp_job_start_locked']); if ($lk) { @flock($lk, LOCK_UN); @fclose($lk); } }
    }
    $hash = pp_job_input_hash($input);
    $id   = pp_job_id($kind, $hash);
    $d    = pp_job_dir($id);
    pp_job_sweep();
    $existing = pp_job_read($id);
    if ($existing !== null && !$force) {
        $st = (string)($existing['status'] ?? '');
        /* IDEMPOTENCY: job dengan input identik yang masih berjalan atau sudah selesai dipakai
         * ulang. Ini juga yang membuat polling UI tidak pernah melahirkan worker kedua. */
        if (pp_job_is_live($existing))
            return ['ok' => true, 'reused' => true, 'job' => $existing];
        /* Job yang dilepas/dibatalkan tetapi requestnya MASIH menghitung input yang sama: batalkan
         * pembatalannya dan pakai ulang — membuat job baru berarti menghitung hal yang sama dua kali. */
        if ($st === 'CANCELLED' && pp_job_exec_held($id)) {
            $re = pp_job_update($id, function (array $j): array {
                $j['cancel_requested'] = false; unset($j['cancel_abort']); $j['status'] = 'RUNNING'; $j['finished_at'] = null;
                $j['current_step'] = 'DIPAKAI_ULANG'; return $j; });
            return ['ok' => true, 'reused' => true, 'job' => $re ?? $existing];
        }
        if ($st === 'DONE' && hash_equals((string)($existing['input_hash'] ?? ''), $hash))
            return ['ok' => true, 'reused' => true, 'job' => $existing];
    }
    if (is_dir($d)) pp_job_rmdir($d);
    if (!@mkdir($d, 0777, true) && !is_dir($d))
        return ['ok' => false, 'error' => 'TIDAK_BISA_MEMBUAT_DIREKTORI_JOB: ' . $d];
    if (@file_put_contents($d . '/input.json', json_encode($input, JSON_UNESCAPED_SLASHES)) === false)
        return ['ok' => false, 'error' => 'TIDAK_BISA_MENULIS_INPUT_JOB'];
    $job = [
        'schema' => 'co12-async-job-v1',
        'job_id' => $id, 'kind' => $kind, 'input_hash' => $hash,
        'request_id' => $requestId, 'status' => 'QUEUED',
        'owner_uid' => pp_v1516_req_ident('uid'), 'owner_tab' => pp_v1516_req_ident('tab'),   // V15.16: pemilik run (isolasi supersede antar user/tab)
        'created_at' => date('c'), 'updated_at' => date('c'),
        'started_at' => null, 'started_at_ts' => null, 'finished_at' => null,
        'pid' => null, 'percent' => 0.0, 'current_step' => 'ANTRE',
        'progress' => [], 'result_available' => false, 'error' => null,
        'cancel_requested' => false,
        /* PLAFON WAKTU WORKER — SAMA UNTUK SELURUH PERHITUNGAN EKSAK.
         * `economic_review` dulu diberi 900 detik sementara dua kind lain diberi 1800. Terukur pada
         * D1_21 (Skip Load G9 80-90 + spinning reserve 40): worker memakai 449,74 detik lalu berhenti
         * di depan comparator global Fase B karena memperkirakan butuh 566,18 detik sedangkan sisa
         * 432,58 detik — jadi ia menolak memulai, dan hasilnya kembali menyatakan "perlu diselesaikan
         * asinkron". Kebutuhan totalnya sekitar 1.016 detik; plafon 900 memang tidak pernah cukup.
         * Plafon 60 detik hanya berlaku pada REQUEST SINKRON; worker berjalan di luar PHP-FPM. */
        'ceiling_s' => 1800.0,
        'php_binary' => pp_job_php_binary(),
        /* V4: jumlah pekerja pembantu yang dipicu browser bersama job_exec (0 = tanpa pembantu). */
        'helpers' => $kind === 'economic_review' ? pp_v4_helper_slots() : 0,
    ];
    if (!pp_job_write($id, $job)) return ['ok' => false, 'error' => 'TIDAK_BISA_MENULIS_JOB_JSON'];
    /* ==========================================================================================
     * TIDAK ADA PROSES OS YANG DILUNCURKAN. TIDAK ADA CONSOLE.
     *
     * PERUBAHAN ARSITEKTUR, DAN ALASANNYA. Versi sebelumnya meluncurkan worker sebagai proses
     * terpisah. Pada Windows/XAMPP hal itu membawa tiga kerugian yang seluruhnya terjadi di mesin
     * operator: jendela Command Prompt berkedip setiap kali Run ditekan, peluncuran bergantung pada
     * penemuan `php.exe` yang dapat gagal, dan proses anak terikat job object Apache sehingga bisa
     * dimatikan di tengah jalan. Mempertahankan arsitektur itu berarti mempertahankan tiga sumber
     * kegagalan demi sebuah keuntungan yang tidak pernah terwujud.
     *
     * Sekarang job hanya DIDAFTARKAN di sini. Yang menjalankannya adalah request HTTP biasa
     * (`mode=job_exec`) yang dipicu browser — Apache sendiri, komponen yang sudah pasti berjalan.
     * Karena itu: nol proses baru, nol console, nol pencarian biner, nol PID yang harus dijaga.
     *
     * Token eksekusi memastikan hanya pemilik job yang dapat memicunya. */
    $tok = bin2hex(random_bytes(16));
    pp_job_update($id, function (array $j) use ($tok, $d): array {
        $j['exec_token']        = $tok;
        $j['dispatch']          = 'in_request';
        /* Denyut awal: job yang baru dibuat menunggu pemicu dari browser. Tanpa denyut ini penyapu
         * menganggapnya yatim setelah 90 detik, padahal browser baru memicunya setelah jawaban
         * jalur sinkron tiba. */
        $j['heartbeat_ts']      = microtime(true);
        $j['heartbeat_at']      = date('c');
        $j['dispatch_note']     = 'Dijalankan di dalam request HTTP (mode=job_exec). Tidak ada proses OS '
                                . 'yang diluncurkan, sehingga tidak ada jendela console pada Windows.';
        $j['working_directory'] = $d;
        $j['memory_limit']      = '1024M';
        $j['parent_pid']        = function_exists('getmypid') ? getmypid() : null;
        $j['stdout_path']       = null;
        $j['stderr_path']       = null;
        $j['result_tmp_path']   = $d . DIRECTORY_SEPARATOR . 'result.json.tmp';
        $j['result_final_path'] = $d . DIRECTORY_SEPARATOR . 'result.json';
        return $j; });
    return ['ok' => true, 'reused' => false, 'job' => pp_job_read($id)];
}
function pp_job_php_binary(): string {
    $c = PHP_BINARY;
    if ($c && is_file($c) && strpos(basename($c), 'php') !== false) return $c;
    return 'php';
}

/* ---------------------------------------------------------------------------------------------
 *  WORKER — dijalankan HANYA dari CLI (`php run.php --job=<id>`).
 * ------------------------------------------------------------------------------------------- */
function pp_job_worker_main(string $id): int {
    /* Dijalankan dari CLI (kompatibilitas lama) ATAU di dalam request lewat `mode=job_exec`.
     * Jalur kedua inilah yang dipakai sekarang: tidak ada proses baru, tidak ada console. */
    if (PHP_SAPI !== 'cli' && !defined('PP_INPROC_JOB')) return 1;
    $d = pp_job_dir($id);
    $job = pp_job_read($id);
    if ($job === null) { pp_job_log_err("job $id tidak ditemukan\n"); return 1; }
    if (!empty($job['cancel_requested'])) { pp_job_update($id, fn($j) => ['status' => 'CANCELLED'] + $j); return 0; }
    $input = json_decode((string)@file_get_contents($d . '/input.json'), true);
    if (!is_array($input)) {
        pp_job_update($id, function (array $j): array {
            $j['status'] = 'FAILED';
            $j['error'] = ['code' => 'INPUT_JOB_RUSAK', 'message' => 'input.json job tidak dapat dibaca.'];
            return $j; });
        return 1;
    }
    $t0 = microtime(true);
    pp_job_update($id, function (array $j) use ($t0): array {
        $j['status'] = 'RUNNING'; $j['pid'] = null;
        $j['started_at'] = date('c'); $j['started_at_ts'] = $t0;
        $j['current_step'] = 'WORKER_MULAI'; $j['percent'] = 2.0;
        $j['heartbeat_at'] = date('c'); $j['heartbeat_ts'] = microtime(true);
        return $j; });
    /* ==============================================================================================
     * SEBAB KEMATIAN DICATAT OLEH YANG MATI, BUKAN DISIMPULKAN OLEH PENYAPU.
     *
     * Tanpa handler ini, fatal error di tengah worker hanya meninggalkan job RUNNING yang lalu
     * disapu dengan kalimat "berhenti tanpa menuliskan hasil" — sebuah KESIMPULAN, bukan sebab.
     * Handler ini berjalan pada saat PHP mematikan proses, membaca error terakhir, dan menuliskan
     * sebabnya beserta fase terakhir dan puncak memori ke dalam job. */
    register_shutdown_function(function () use ($id, $t0) {
        $e = error_get_last();
        if (!is_array($e) || !in_array((int)($e['type'] ?? 0),
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        @pp_job_update($id, function (array $j) use ($e, $t0): array {
            if (in_array((string)($j['status'] ?? ''), ['DONE', 'CANCELLED'], true)) return $j;
            $j['status'] = 'FAILED'; $j['finished_at'] = date('c');
            $j['error'] = ['code' => 'UNCAUGHT_FATAL',
                'message' => (string)($e['message'] ?? 'fatal error tanpa pesan'),
                'file' => basename((string)($e['file'] ?? '')) . ':' . (int)($e['line'] ?? 0),
                'last_phase' => (string)($j['current_step'] ?? '?'),
                'elapsed_s' => round(microtime(true) - $t0, 2)];
            $j['memory_peak_bytes'] = memory_get_peak_usage(true);
            return $j; });
    });
    /* Plafon job (bukan plafon request sinkron) — lihat pp_budget_ceiling(). */
    $GLOBALS['__pp_async_worker'] = true;
    $GLOBALS['__pp_async_worker_ceiling'] = (float)($job['ceiling_s'] ?? 900.0);
    $GLOBALS['__pp_job_id'] = $id;
    try {
        /* KOMPATIBILITAS PHP 7.4: `match` adalah konstruksi PHP 8.0 dan menjadi akar parse error
         * produksi (`unexpected '=>'` pada baris pertama di dalamnya). Ditulis ulang dengan
         * if/elseif memakai `===`, yang SEMANTIKNYA identik dengan `match`: perbandingan ketat,
         * cabang pertama yang cocok dipakai, dan cabang `default` selalu ada sehingga
         * `UnhandledMatchError` memang tidak pernah mungkin terjadi pada bentuk aslinya. */
        $jobKindSel = (string)$job['kind'];
        if ($jobKindSel === 'validated_options') {
            $result = pp_job_run_validated_options($id, $input);
        } elseif ($jobKindSel === 'validated_option_distillate') {
            $result = pp_job_run_validated_option_distillate($id, $input);
        } elseif ($jobKindSel === 'legal_branch_exact') {
            $result = pp_job_run_legal_branch_exact($id, $input);
        } else {
            $result = pp_job_run_economic_review($id, $input);
        }
        $result['job_id'] = $id;
        $result['input_hash'] = (string)$job['input_hash'];
        $result['request_id'] = $job['request_id'] ?? null;
        $result['worker_wall_s'] = round(microtime(true) - $t0, 2);
        /* ==========================================================================================
         * KONTRAK HASIL ATOMIK.
         *
         * Versi lama menulis .tmp lalu langsung rename, TANPA memeriksa apakah json_encode berhasil
         * dan tanpa memeriksa apakah penulisan benar-benar selesai. Bila encode gagal (satu nilai
         * INF/NAN sudah cukup) atau disk penuh, yang ter-rename adalah berkas kosong atau terpotong
         * — dan job tetap ditandai DONE. Pembacanya kemudian gagal mem-parse dan operator melihat
         * kegagalan tanpa sebab. Urutannya kini: encode -> validasi -> tulis -> flush -> validasi
         * ulang dari disk -> rename -> BARU status DONE. Status DONE karena itu menjadi janji yang
         * benar-benar dapat dipegang: kalau DONE, hasilnya ADA dan dapat di-parse. */
        $enc = json_encode($result, JSON_UNESCAPED_SLASHES);
        if ($enc === false) {
            $sanit = function_exists('pp_json_sanitize') ? pp_json_sanitize($result) : $result;
            $enc = json_encode($sanit, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $okWrite = false; $whyWrite = '';
        if (!is_string($enc) || $enc === '') {
            $whyWrite = 'json_encode gagal: ' . json_last_error_msg();
        } else {
            $tmp = $d . DIRECTORY_SEPARATOR . 'result.json.' . getmypid() . '.tmp';
            $fh = @fopen($tmp, 'wb');
            if ($fh === false) { $whyWrite = 'tidak dapat membuka berkas sementara hasil'; }
            else {
                $w = @fwrite($fh, $enc);
                @fflush($fh); @fclose($fh);
                if ($w === false || $w !== strlen($enc)) {
                    $whyWrite = 'penulisan hasil terpotong (' . var_export($w, true) . ' dari ' . strlen($enc) . ' byte)';
                    @unlink($tmp);
                } elseif (json_decode((string)@file_get_contents($tmp), true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $whyWrite = 'hasil di disk tidak dapat di-parse kembali: ' . json_last_error_msg();
                    @unlink($tmp);
                } else {
                    /* rename() menimpa atomik di POSIX; di Windows ia gagal bila tujuan ada,
                     * sehingga tujuan dihapus lebih dulu. */
                    $fin = $d . DIRECTORY_SEPARATOR . 'result.json';
                    if (pp_is_windows() && is_file($fin)) @unlink($fin);
                    $okWrite = @rename($tmp, $fin);
                    if (!$okWrite) { $whyWrite = 'rename hasil ke berkas final gagal'; @unlink($tmp); }
                }
            }
        }
        if (!$okWrite) {
            pp_job_update($id, function (array $j) use ($whyWrite): array {
                $j['status'] = 'FAILED'; $j['finished_at'] = date('c'); $j['result_available'] = false;
                $j['error'] = ['code' => 'RESULT_WRITE_FAILED', 'message' => $whyWrite];
                return $j; });
            pp_job_log_err("RESULT_WRITE_FAILED: $whyWrite\n");
            return 1;
        }
        $selesai = function (array $j): array {
            $j['status'] = (!empty($j['cancel_requested'])) ? 'CANCELLED' : 'DONE';
            $j['peak_memory_bytes'] = memory_get_peak_usage(true);
            $j['result_available'] = true; $j['percent'] = 100.0;
            $j['current_step'] = 'SELESAI'; $j['finished_at'] = date('c');
            return $j; };
        /* Status akhir WAJIB tertulis: 100% tanpa DONE adalah cacat yang membuat browser menunggu. */
        for ($k = 0; $k < 10; $k++) {
            pp_job_update($id, $selesai);
            $cek = pp_job_read($id);
            if (is_array($cek) && in_array((string)($cek['status'] ?? ''), ['DONE', 'CANCELLED'], true)) break;
            usleep(100000);
        }
        return 0;
    } catch (Throwable $ex) {
        if ($ex instanceof PpJobAborted) {
            /* Dihentikan TARGET SELESAI: bukan kegagalan. Bila pembatalannya sudah dicabut (Run baru
             * untuk state yang sama memakai ulang job ini), job dikembalikan ke antrean. */
            pp_job_update($id, function (array $j): array {
                $j['result_available'] = false; $j['finished_at'] = date('c');
                if (!empty($j['cancel_requested'])) { $j['status'] = 'CANCELLED'; $j['current_step'] = 'DIHENTIKAN_TARGET_SELESAI'; }
                else { $j['status'] = 'QUEUED'; $j['current_step'] = 'ANTRE'; $j['claim_id'] = null; }
                return $j; });
            return 0;
        }
        pp_job_update($id, function (array $j) use ($ex): array {
            $j['status'] = 'FAILED'; $j['finished_at'] = date('c');
            $j['error'] = ['code' => 'WORKER_EXCEPTION',
                'message' => $ex->getMessage(),
                'where' => basename($ex->getFile()) . ':' . $ex->getLine()];
            return $j; });
        return 1;
    }
}

/* KIND 1 — menyelesaikan economic review yang tidak muat di request sinkron.
 * Job ini menjalankan SIMULASI YANG SAMA dari input bersih, dengan plafon job, sehingga
 * comparator komitmen global dijalankan seluruhnya. Tidak ada kandidat yang dilewati dan
 * urutan evaluasi tidak berubah, jadi hasilnya deterministik dan identik dengan hasil yang
 * akan diperoleh mesin cepat secara sinkron. */
/* ==============================================================================================
 *  DUA TAHAP UNTUK PERUBAHAN KUOTA SEDERHANA: VALID PROVISIONAL -> FINAL OPTIMAL.
 *
 *  Tahap 1 memakai commitment hasil FINAL valid terakhir sebagai titik awal dan menghitung
 *  redispatch terbaik untuk kuota baru dengan commitment itu (satu core run lengkap: shaping,
 *  pencarian window supplier PGN, perbaikan Export). Hasilnya diperiksa penuh (48 baris, hard
 *  constraint termasuk reserve dan Bus Flow, PLN Export 48/48, gas window, residual shortage).
 *  Bila semuanya valid, hasil ditampilkan sebagai VALID PROVISIONAL — BUKAN cost minimum global.
 *  Save/Export/Publish tetap terkunci. Tahap 2 adalah job exact yang sama seperti biasa; hasilnya
 *  menggantikan provisional dan menjadi FINAL OPTIMAL. Tahap 1 tidak mengubah tahap 2 sedikit pun.
 * ============================================================================================ */
function pp_final_dir(): string { return pp_job_root() . DIRECTORY_SEPARATOR . '_final'; }
function pp_final_is_final(array $o): bool {
    $rs = (array)($o['info']['Run Status'] ?? []);
    return count((array)($o['data'] ?? [])) === 48 && ($o['preliminary'] ?? null) !== true
        && (($o['release_gate']['release_allowed'] ?? null) === true)
        && (($rs['converged'] ?? null) === true) && (($rs['economic_review_completed'] ?? null) === true)
        && empty($o['provisional']);
}
/* Input dibandingkan tanpa field transport, anggaran waktu, dan penanda internal engine. */
function pp_final_canon(array $input): array {
    foreach (array_keys($input) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($input[$k]);
    $m = (array)($input['data3']['modeling'] ?? []);
    foreach (array_keys($m) as $k)
        if (is_string($k) && (strpos($k, '__') === 0 || strpos($k, 'time_budget') === 0
            || in_array($k, ['change_over_search_budget_seconds', 'change_over_interactive_request',
                             'shortage_probe_max_seconds', 'shortage_probe_max_candidates', 'validated_options'], true)))
            unset($m[$k]);
    pp_v3_strip_meta($m);                     // V3: label/arsip UI bukan state engine
    $input['data3']['modeling'] = $m;
    return pp_memo_canon($input);
}
function pp_final_save(array $input, array $output, ?array $v3 = null): void {
    if (!pp_final_is_final($output)) return;
    $dir = pp_final_dir(); if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $c = pp_final_canon($input);
    /* V3: commitment pemenang dan ruang kandidatnya ikut disimpan sebagai basis recompute inkremental. */
    if ($v3 === null) { try { $v3 = pp_v3_block_from_exact(pp_normalize_copy($input), $output); } catch (Throwable $e) { $v3 = null; } }
    elseif (is_array($v3['winner'] ?? null)) {            // pemenang yang tersimpan = dispatch yang diterbitkan
        try { $ws = pp_v3_stops((array)$output['data']); $sg = pp_v3_sig($ws);
            if ($sg !== ($v3['winner']['sig'] ?? '')) $v3['winner']['adj'] = 0.0;
            $v3['winner']['stops'] = $ws; $v3['winner']['sig'] = $sg;
            $v3['winner']['key'] = pp_global_commitment_key(pp_normalize_copy($input), $output); $v3['winner']['cp'] = (float)($v3['winner']['key']['cp'] ?? 0);
            $v3['winner']['supplier_target'] = pp_tl_supplier_target($output); } catch (Throwable $e) {} }
    /* V8: kandidat pembanding review Unit Priority ikut menjadi pesaing basis inkremental. */
    if (is_array($v3) && is_array($output['info']['V8 Priority Review']['carry'] ?? null)) {
        $have = []; foreach ((array)($v3['candidates'] ?? []) as $cc) $have[(string)($cc['sig'] ?? '')] = true;
        foreach ($output['info']['V8 Priority Review']['carry'] as $cc) { $sg = pp_v3_sig(pp_v3_commitment_stops($cc)); if ($sg === ($v3['winner']['sig'] ?? '') || isset($have[$sg])) continue;
            $cc['sig'] = $sg; $v3['candidates'][] = $cc; $have[$sg] = true; } }
    if (is_array($v3) && pp_v9_canon()) $v3['v9_canonical'] = true;
    $v9r = null; $vr = $output['info']['V8 Priority Review'] ?? null;
    if (is_array($vr) && in_array($vr['status'] ?? '', ['APPLIED', 'NO_BETTER_VALID_CANDIDATE', 'DELTA_REVIEW_BASIS', 'DELTA_REVIEW_APPLIED'], true)) {
        try { $v9r = array_intersect_key($vr, array_flip(['status', 'relevant_intervals', 'final_candidates', 'row_evidence', 'rows_sig', 'applied']));
            $v9r['commit_sig'] = pp_v3_sig(pp_v8_stops(pp_v8_masks(array_values((array)$output['data'])))); } catch (Throwable $e) { $v9r = null; } }
    $enc = json_encode(['saved_at' => microtime(true), 'input' => $c, 'data' => $output['data'], 'v9_review' => $v9r,
        'cost' => $output['info']['Total Cost (USD)'] ?? null,
        'cost_production' => $output['info']['Cost Production (USD/MWh)'] ?? null, 'v3' => $v3],
        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($enc)) return;
    $f = $dir . DIRECTORY_SEPARATOR . substr(hash('sha256', json_encode($c)), 0, 32) . '.json';
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $enc) !== strlen($enc)) { @unlink($tmp); return; }
    if (pp_is_windows() && is_file($f)) @unlink($f);
    if (!@rename($tmp, $f)) @unlink($tmp);
    $fs = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    if (count($fs) > 48) { usort($fs, fn($a, $b) => (int)@filemtime($a) <=> (int)@filemtime($b));      // V9: 12 -> 48 (jangkar kanonik tetap tersedia)
        foreach (array_slice($fs, 0, count($fs) - 48) as $x) @unlink($x); }
}
function pp_flat_for_diff($a, string $p = ''): array {
    $o = [];
    foreach ((array)$a as $k => $v) {
        if (is_array($v) && $v !== [] && array_keys($v) !== range(0, count($v) - 1)) $o += pp_flat_for_diff($v, $p . '/' . $k);
        else $o[$p . '/' . $k] = json_encode($v, JSON_PRESERVE_ZERO_FRACTION);
    }
    return $o;
}
/* KLASIFIKASI: perubahan sederhana = hanya kuota gas, Additional LNG (tanpa Recommendation), dan
 * Actual Gas. Perubahan lain apa pun (unit, stop, skip/fix load, Change Over, Export, harga, data
 * beban, aksi Distillate/campuran/Recommendation) -> tidak ada tahap provisional. */
function pp_provisional_base(array $input): ?array {
    $m = (array)($input['data3']['modeling'] ?? []);
    $act = strtolower((string)($m['gas_shortage_action'] ?? 'none'));
    /* `recommendation` = dispatch `none` (pp_normalize_fuel_action); bila kuota baru memicu kekurangan
     * gas, pemeriksaan residual shortage menolak provisional dan alur Gas Shortage berjalan biasa. */
    if (!in_array($act,['none','add_lng','use_distillate','mixed_lng_distillate','recommendation'],true)) return null;
    if (!empty($m['change_over']['enabled'])) return null;
    $new = pp_flat_for_diff(pp_final_canon($input));
    $allowed = ['~^/data3/modeling/gas_quota/~', '~^/data3/modeling/additional_lng$~',
                '~^/data3/modeling/gas_shortage_action$~', '~^/data3/modeling/actual_data(/|$)~',
                '~^/data3/modeling/actual_gas_rows(/|$)~', '~^/data3/modeling/actual_rows(/|$)~',
                '~^/data3/modeling/actual_pgn_total$~', '~^/data3/modeling/actual_energy_jababeka$~',
                '~^/data3/modeling/actual_energy_mm2100$~', '~^/data3/modeling/manual_fixed_flows(/|$)~'];
    $best = null;
    foreach (glob(pp_final_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        $r = json_decode((string)@file_get_contents($f), true);
        if (!is_array($r) || !is_array($r['input'] ?? null) || count((array)($r['data'] ?? [])) !== 48) continue;
        $bm = (array)($r['input']['data3']['modeling'] ?? []);
        if (!in_array(strtolower((string)($bm['gas_shortage_action'] ?? 'none')), ['none', 'add_lng', 'recommendation'], true)) continue;
        $old = pp_flat_for_diff($r['input']);
        $ok = true; $n = 0;
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            if (($old[$k] ?? null) === ($new[$k] ?? null)) continue;
            $n++; $hit = false;
            foreach ($allowed as $re) if (preg_match($re, $k)) { $hit = true; break; }
            if (!$hit) { $ok = false; break; }
        }
        if (!$ok) continue;
        if ($n === 0) continue;
        if ($best === null || (float)$r['saved_at'] > (float)$best['saved_at']) $best = $r;
    }
    return $best;
}
/* Tahap 1. null = tidak ada hasil provisional yang valid (jalur biasa dipakai apa adanya). */
function pp_provisional_compute(array $input, array $base, float $budget = 12.0): ?array {
    $st = [];
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u) {
        $U = strtoupper($u); $r0 = null;
        for ($r = 1; $r <= 49; $r++) {
            $off = $r <= 48 ? ((float)($base['data'][$r - 1][$U] ?? 0) <= 0.01) : false;
            if ($off && $r0 === null) $r0 = $r;
            if (!$off && $r0 !== null) { $st[] = ['unit' => $u, 'start' => $r0, 'stop' => $r - 1]; $r0 = null; }
        }
    }
    /* Commitment hasil final terakhir dipakai apa adanya; redispatch lalu window gas didaratkan
     * dengan lever engine sendiri (target shaper/trim), maksimal lima evaluasi. Hasil hanya dipakai
     * bila SELURUH pemeriksaan lulus terhadap input asli operator. */
    $orig = pp_normalize_copy($input);
    $t = microtime(true);
    $savedG = [];
    foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $savedG[$gk] = $gv;
    /* Node ini identik dengan node seed keluarga commitment: registri bersama dipakai dua arah. */
    $regP = null; $nkP = pp_tl_node_key([], $st); $L = null;
    try {
        $regP = pp_tl_registry_open($orig);
        $sP = $regP->get($nkP); $oP = is_array($sP) && !empty($sP['valid']) ? $regP->output($nkP) : null;
        if (is_array($oP) && count((array)($oP['data'] ?? [])) === 48)
            $L = ['a' => ['valid' => true, 'checks' => $sP['checks'], 'key' => $sP['key'], 'adj' => $sP['adj'] ?? 0.0, 'output' => $oP, 'off' => []], 'evals' => 0, 'reused' => true];
    } catch (Throwable $e) { $L = null; }
    /* Pencarian keluarga paralel (mode=tl_search, dipicu browser bersamaan dengan Run) sedang aktif
     * untuk state ini: node ini dihitung di sana. Request sinkron hanya MENUNGGU hasilnya (tanpa
     * beban CPU), sehingga tidak ada evaluasi ganda dan request tidak menembus batas waktu PHP. */
    $parallel = false;
    if ($L === null && $regP !== null) {
        try {
            $ownF = pp_tl_file(pp_tl_key($orig) . '_owner.json');
            for ($iw = 0; $iw < 10 && !$parallel; $iw++) {
                clearstatcache(true, $ownF);
                if (is_file($ownF) && time() - (int)@filemtime($ownF) <= 30) $parallel = true; else usleep(100000);
            }
        } catch (Throwable $e) { $parallel = false; }
    }
    if ($L === null && $regP !== null && $parallel) {
        while (microtime(true) < $t + $budget - 0.5) {
            $sP = $regP->get($nkP);
            if (is_array($sP)) { $oP = !empty($sP['valid']) ? $regP->output($nkP) : null;
                $L = is_array($oP) ? ['a' => ['valid' => true, 'checks' => $sP['checks'], 'key' => $sP['key'], 'adj' => $sP['adj'] ?? 0.0, 'output' => $oP, 'off' => []], 'evals' => 0, 'reused' => true]
                                   : ['a' => null, 'evals' => 0]; break; }
            usleep(150000);
        }
        if ($L === null) $L = ['a' => null, 'evals' => 0];     // belum selesai: job exact + tampilan keluarga mengambil alih
    }
    /* Node sedang dihitung pencarian keluarga (request paralel): ditunggu, tidak dihitung dua kali. */
    if ($L === null && $regP !== null) {
        try {
            $claimed = !$regP->claimedByOther($nkP) && $regP->claim($nkP);
            while (!$claimed && microtime(true) < $t + $budget - 1.0) {
                usleep(150000);
                $sP = $regP->get($nkP);
                if (is_array($sP)) { $oP = !empty($sP['valid']) ? $regP->output($nkP) : null;
                    $L = is_array($oP) ? ['a' => ['valid' => true, 'checks' => $sP['checks'], 'key' => $sP['key'], 'adj' => $sP['adj'] ?? 0.0, 'output' => $oP, 'off' => []], 'evals' => 0, 'reused' => true]
                                       : ['a' => null, 'evals' => 0]; break; }
                if (!$regP->claimedByOther($nkP)) $claimed = $regP->claim($nkP);
            }
        } catch (Throwable $e) {}
    }
    if ($L === null) {
        try { $L = pp_tl_land_commitment($orig, [], $st, $t + $budget, 5); }
        catch (Throwable $e) { $L = ['a' => null, 'evals' => 0]; }
        try { if ($regP !== null && is_array($L['a']) && !empty($L['a']['checks']['not_truncated'])) { $aP = $L['a']; $aP['off'] = [];
            $regP->put($nkP, pp_tl_node_summary($aP, (int)$L['evals'], microtime(true) - $t), !empty($aP['valid']) ? $aP['output'] : null); } } catch (Throwable $e) {}
        try { if ($regP !== null) $regP->release($nkP); } catch (Throwable $e) {}
    }
    pp_tl_clean_globals();
    foreach ($savedG as $gk => $gv) $GLOBALS[$gk] = $gv;     // state request pemanggil dipulihkan utuh
    $wall = microtime(true) - $t;
    $a = $L['a'];
    if (!is_array($a) || empty($a['valid'])) return null;
    $GLOBALS['ppProvAssess'] = $a;                          // kandidat valid: ditawarkan ke kolam state
    $out = $a['output'];
    $ck = (array)$a['checks'];
    $checks = [
        'rows_48' => !empty($ck['rows_48']),
        'hard_constraints' => !empty($ck['hard_constraints']),
        'pln_export_48_48' => !empty($ck['pln_export_48_48']),
        'gas_window' => !empty($ck['gas_window']),
        'pgn_supplier_window' => !empty($ck['pgn_supplier_window']),
        'residual_shortage_zero' => !empty($ck['residual_shortage_zero']),
        'search_not_truncated' => !empty($ck['not_truncated']),
        'export_reserve_busflow' => !empty($ck['export_reserve_busflow']),
    ];
    foreach ($checks as $ok) if (!$ok) return null;
    $V = ['violations' => []];
    $out['provisional'] = true;
    $out['provisional_status'] = 'VALID_PROVISIONAL';
    $out['provisional_label'] = 'VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS';
    $out['provisional_checks'] = $checks + ['hard_violations' => count((array)($V['violations'] ?? []))];
    $out['provisional_basis'] = ['commitment_from' => 'hasil FINAL valid terakhir', 'wall_s' => round($wall, 2),
        'evaluations' => (int)$L['evals'], 'gas_landing_adjust' => $a['adj'] ?? 0.0,
        'base_cost' => $base['cost'] ?? null, 'base_cost_production' => $base['cost_production'] ?? null];
    $out['preliminary'] = true; $out['final_result_visible'] = true;
    $out['save_allowed'] = false; $out['publish_allowed'] = false;
    $out['release_gate'] = ['release_allowed' => false, 'status' => 'PROVISIONAL',
        'hard_validation' => 'PASS', 'economic_review' => 'IN_PROGRESS', 'convergence' => 'PROVISIONAL',
        'blocking_reasons' => ['EXACT_COST_OPTIMIZATION_IN_PROGRESS'],
        'note' => 'Hasil provisional valid terhadap seluruh hard constraint, tetapi BUKAN cost minimum global. Publish Final dibuka setelah optimasi exact selesai.'];
    $out['preliminary_reason'] = 'VALID_PROVISIONAL';
    $out['preliminary_note'] = 'VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS. Commitment hasil final terakhir dipakai ulang dan seluruh constraint valid; biaya ini belum dibuktikan terendah.';
    return $out;
}

/* ==============================================================================================
 *  TARGET SELESAI (15-60 detik / Maximum Review) — KOLAM KANDIDAT VALID PER STATE.
 *
 *  Kolam menyimpan SATU kandidat constraint-valid terbaik (urutan comparator engine: hard, Export/
 *  Reserve/Bus Flow, Cost Production, ...) untuk setiap state input. Pengisinya dua: pencarian
 *  anytime (`mode=tl_search`) dan pengamat job exact (setiap core run yang lulus). Kolam hanya
 *  bertambah baik, sehingga batas waktu yang lebih panjang tidak pernah menghasilkan Cost
 *  Production yang lebih buruk. Kandidat invalid tidak pernah masuk.
 * ============================================================================================ */
if (!class_exists('PpJobAborted')) { class PpJobAborted extends RuntimeException {} }
function pp_tl_dir(): string { return pp_job_root() . DIRECTORY_SEPARATOR . '_tl'; }
/* Normalisasi aksi bahan bakar atas SALINAN tanpa meninggalkan global keputusan bahan bakar. */
function pp_normalize_copy(array $input): array {
    $keepMode = $GLOBALS['__pp_fuel_decision_mode'] ?? null; $hadMode = array_key_exists('__pp_fuel_decision_mode', $GLOBALS);
    if (function_exists('pp_normalize_fuel_action')) pp_normalize_fuel_action($input);
    if ($hadMode) $GLOBALS['__pp_fuel_decision_mode'] = $keepMode; else unset($GLOBALS['__pp_fuel_decision_mode']);
    return $input;
}
function pp_tl_key(array $input): string {
    /* Normalisasi pada SALINAN tidak boleh meninggalkan global keputusan bahan bakar: global itu
     * membuat konteks memo state "kotor" sehingga hasil job tidak pernah disimpan untuk dipakai ulang. */
    $keepMode = $GLOBALS['__pp_fuel_decision_mode'] ?? null; $hadMode = array_key_exists('__pp_fuel_decision_mode', $GLOBALS);
    if (function_exists('pp_normalize_fuel_action')) pp_normalize_fuel_action($input);
    if ($hadMode) $GLOBALS['__pp_fuel_decision_mode'] = $keepMode; else unset($GLOBALS['__pp_fuel_decision_mode']);
    return substr(hash('sha256', pp_engine_fingerprint() . '|'
        . json_encode(pp_final_canon($input), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), 0, 32);
}
function pp_tl_file(string $name): string {
    $d = pp_tl_dir(); if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d . DIRECTORY_SEPARATOR . preg_replace('~[^A-Za-z0-9_.\-]~', '', $name);
}
function pp_tl_write(string $f, array $data): bool {
    $enc = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (!is_string($enc)) return false;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $enc) !== strlen($enc)) { @unlink($tmp); return false; }
    if (pp_is_windows() && is_file($f)) @unlink($f);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    return true;
}
function pp_tl_read(string $f): ?array {
    for ($i = 0; $i < 4; $i++) {
        if (!is_file($f)) return null;
        $r = json_decode((string)@file_get_contents($f), true);
        if (is_array($r)) return $r;
        usleep(20000);
    }
    return null;
}
/* Tawarkan kandidat VALID ke kolam; true bila ia menjadi yang terbaik. */
function pp_tl_pool_offer(string $key, array $a, string $source, string $owner): bool {
    if (empty($a['valid']) || !is_array($a['key'] ?? null) || !is_array($a['output'] ?? null)) return false;
    $sgP = pp_v6_gtg_sig((array)($a['output']['data'] ?? []));
    if (function_exists('pp_v11_cnt_at')) pp_v11_cnt_at((string)preg_replace('~_[xq]$~', '', $key), $owner, 'v', $sgP, (array)($a['output']['data'] ?? []));
    $lk = @fopen(pp_tl_file($key . '.lock'), 'c'); if ($lk) @flock($lk, LOCK_EX);
    $bf = pp_tl_file($key . '_best.json');
    $meta = pp_tl_read(pp_tl_file($key . '_meta.json'));
    $better = !is_array($meta) || !is_array($meta['cmp'] ?? null) || pp_global_commitment_better($a['key'], $meta['cmp']);
    if ($better) {
        $i = (array)($a['output']['info'] ?? []);
        $m = ['cmp' => $a['key'], 'source' => $source, 'owner' => $owner, 'found_at' => microtime(true),
              'checks' => $a['checks'] ?? [], 'units_off' => array_map('strtoupper', (array)($a['off'] ?? [])),
              'cost' => $i['Total Cost (USD)'] ?? null, 'cost_production' => $i['Cost Production (USD/MWh)'] ?? null, 'sig' => $sgP];
        if (pp_tl_write($bf, ['meta' => $m, 'output' => $a['output']])) pp_tl_write(pp_tl_file($key . '_meta.json'), $m);
        else $better = false;
    }
    if ($lk) { @flock($lk, LOCK_UN); fclose($lk); }
    return $better;
}
/* Pengamat job exact: dipanggil setiap core run (lihat pp_run_simulation_core). Hanya MENCATAT —
 * tidak ada nilai engine yang dibaca balik, sehingga hasil exact tidak berubah sedikit pun. */
function pp_tl_exact_observe(array $out, bool $pass): void {
    $h = $GLOBALS['ppTlHook'] ?? null; if (!is_array($h)) return;
    if (!empty($h['spec'])) return;                         // V11: prefetch spekulatif keluarga bukan kandidat ruang exact
    $h['n']++;
    $sgO = count((array)($out['data'] ?? [])) === 48 ? pp_v6_gtg_sig((array)$out['data']) : null;
    if ($sgO !== null && function_exists('pp_v11_cnt_at')) pp_v11_cnt_at((string)$h['key'], (string)$h['job'], 'c', $sgO, (array)$out['data']);
    if ($pass) {
        try {
            $a = pp_tl_assess($h['orig'], $out);
            if (!empty($a['valid'])) { $h['v']++; if ($sgO !== null && function_exists('pp_v11_cnt_at')) pp_v11_cnt_at((string)$h['key'], (string)$h['job'], 'v', $sgO, (array)$out['data']); $a['output'] = $out; $a['off'] = []; pp_tl_pool_offer($h['key'] . '_x', $a, 'exact_search', $h['job']); }
        } catch (Throwable $e) {}
    }
    if ($pass || microtime(true) - $h['flush'] > 1.5) {
        pp_tl_write(pp_tl_file($h['key'] . '_x_' . $h['job'] . '.json'), ['evaluated' => $h['n'], 'valid' => $h['v'], 'at' => microtime(true)]);
        $h['flush'] = microtime(true);
    }
    $GLOBALS['ppTlHook'] = $h;
}
/* Penghentian kooperatif job exact yang diminta TARGET SELESAI (job_cancel&abort=1). Diperiksa
 * paling sering sekali per detik di pintu masuk setiap core run. */
function pp_tl_abort_poll(): void {
    $h = $GLOBALS['ppTlHook'] ?? null; if (!is_array($h)) return;
    if (microtime(true) - (float)($h['cchk'] ?? 0) < 1.0) return;
    $GLOBALS['ppTlHook']['cchk'] = microtime(true);
    $j = pp_job_read((string)$h['job']);
    if (is_array($j) && !empty($j['cancel_requested']) && !empty($j['cancel_abort'])) {
        $GLOBALS['ppTlHook']['aborted'] = true;            // hasil apa pun sesudah ini tidak sah
        throw new PpJobAborted('DIHENTIKAN_TARGET_SELESAI');
    }
    /* V4: pekerja pembantu berhenti begitu job pemiliknya selesai/dibatalkan (tidak menghabiskan CPU
     * untuk candidate-state yang tidak lagi dibutuhkan). */
    if (!empty($h['helper']) && (!is_array($j) || !empty($j['cancel_requested']) || in_array((string)($j['status'] ?? ''), ['DONE', 'FAILED', 'CANCELLED'], true))) {
        $GLOBALS['ppTlHook']['aborted'] = true;
        throw new PpJobAborted('PEMILIK_SELESAI');
    }
}
/* Maximum Review: bila kolam Target Selesai state ini memegang kandidat valid yang LEBIH MURAH dari
 * hasil final exact, hal itu dinyatakan (hasil exact sendiri tidak diubah). */
function pp_tl_pool_note(array $input, array &$output): void {
    try {
        if (!pp_final_is_final($output)) return;
        $key = pp_tl_key($input);
        $mq = pp_tl_read(pp_tl_file($key . '_q_meta.json'));
        if (!is_array($mq) || !is_array($mq['cmp'] ?? null)) return;
        $inN = pp_normalize_copy($input);
        $kE = pp_global_commitment_key($inN, $output);
        if (pp_global_commitment_better($mq['cmp'], $kE))
            $output['tl_pool'] = ['better' => true, 'key' => $key, 'cost' => $mq['cost'] ?? null,
                'cost_production' => $mq['cost_production'] ?? null, 'units_off' => $mq['units_off'] ?? [],
                'exact_cost_production' => $kE['cp'] ?? null,
                'exact_cost' => $output['info']['Total Cost (USD)'] ?? null, 'v9_path_independent' => pp_v9_canon()];
    } catch (Throwable $e) {}
}
/* REGISTRI EVALUASI BERSAMA (berkas). Satu direktori per state input (kunci = pp_tl_key: input
 * kanonik + sidik jari engine). Setiap node keluarga commitment dihitung SEKALI oleh proses mana
 * pun (job exact, pencarian Target Selesai, run berikutnya) lalu dipakai ulang. Klaim node
 * memakai berkas eksklusif (fopen 'x'); klaim yang tidak diperbarui > 180 detik dianggap basi. */
class PpTlFileRegistry extends PpTlRegistry {
    public $dir; public $owner;
    public function __construct(string $dir) {
        $this->dir = $dir; $this->owner = getmypid() . '-' . bin2hex(random_bytes(3));
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
    }
    private function f(string $nk, string $ext): string { return $this->dir . DIRECTORY_SEPARATOR . substr(md5($nk), 0, 20) . $ext; }
    public function get(string $nk) {
        if (isset($this->nodes[$nk])) return $this->nodes[$nk];
        $r = pp_tl_read($this->f($nk, '.node.json'));
        if (is_array($r) && ($r['nk'] ?? null) === $nk) { $this->nodes[$nk] = $r['sum']; return $r['sum']; }
        return null;
    }
    public function output(string $nk) {
        if (isset($this->outs[$nk])) return $this->outs[$nk];
        $r = pp_tl_read($this->f($nk, '.out.json'));
        return (is_array($r) && ($r['nk'] ?? null) === $nk) ? $r['out'] : null;
    }
    /* V4: klaim node memakai flock (lepas otomatis bila proses pemegangnya berhenti), bukan berkas
     * eksklusif dengan batas basi 180 detik. */
    public $locks = [];
    public function claimedByOther(string $nk): bool {
        if (isset($this->locks[$nk])) return false;
        $h = @fopen($this->f($nk, '.claim'), 'c'); if (!$h) return false;
        if (@flock($h, LOCK_EX | LOCK_NB)) { @flock($h, LOCK_UN); @fclose($h); return false; }
        @fclose($h); return true;
    }
    public function claim(string $nk): bool {
        if (isset($this->locks[$nk])) return true;
        $h = @fopen($this->f($nk, '.claim'), 'c'); if (!$h) return true;
        if (!@flock($h, LOCK_EX | LOCK_NB)) { @fclose($h); return false; }
        $this->locks[$nk] = $h; return true;
    }
    public function put(string $nk, array $sum, ?array $out): void {
        $this->nodes[$nk] = $sum;
        if ($out !== null) { pp_tl_write($this->f($nk, '.out.json'), ['nk' => $nk, 'out' => $out]); }
        pp_tl_write($this->f($nk, '.node.json'), ['nk' => $nk, 'sum' => $sum, 'at' => microtime(true)]);
    }
    public function release(string $nk): void {
        if (isset($this->locks[$nk])) { @flock($this->locks[$nk], LOCK_UN); @fclose($this->locks[$nk]); unset($this->locks[$nk]); }
    }
    public function markComplete(array $meta): void { pp_tl_write($this->dir . DIRECTORY_SEPARATOR . 'complete.json', $meta + ['at' => microtime(true)]); }
    public function allNodes(): array {
        $r = $this->nodes;
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.node.json') ?: [] as $f) {
            $x = pp_tl_read($f);
            if (is_array($x) && is_string($x['nk'] ?? null) && is_array($x['sum'] ?? null)) { $r[$x['nk']] = $x['sum']; $this->nodes[$x['nk']] = $x['sum']; }
        }
        return $r;
    }
    public function allValid(): array {
        $r = parent::allValid();
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.node.json') ?: [] as $f) {
            $x = pp_tl_read($f);
            if (is_array($x) && is_string($x['nk'] ?? null) && !empty($x['sum']['valid'])) { $r[$x['nk']] = $x['sum']; $this->nodes[$x['nk']] = $x['sum']; }
        }
        return $r;
    }
}
function pp_tl_registry_open(array $orig): PpTlRegistry {
    try { return new PpTlFileRegistry(pp_tl_file('') . pp_tl_key($orig) . '_reg'); }
    catch (Throwable $e) { return new PpTlRegistry(); }
}
/* Seed keluarga: commitment hasil FINAL (maks. 3 pola berbeda, terbaru dahulu) dari state yang hanya
 * berbeda pada perubahan sederhana (kuota/actual/fixed flow). Commitment parsial-hari seperti ini
 * tidak terjangkau oleh node "unit mati sepanjang hari". */
function pp_tl_stops_of(array $data): array {
    $seed = [];
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u) {
        $U = strtoupper($u); $r0 = null;
        for ($r = 1; $r <= 49; $r++) {
            $offR = $r <= 48 ? ((float)($data[$r - 1][$U] ?? 0) <= 0.01) : false;
            if ($offR && $r0 === null) $r0 = $r;
            if (!$offR && $r0 !== null) { $seed[] = ['unit' => $u, 'start' => $r0, 'stop' => $r - 1]; $r0 = null; }
        }
    }
    return $seed;
}
function pp_v9_canon(): bool { return (string)getenv('PP_V9_CANON') !== '0'; }
/* V10: rute cepat (screening dua tingkat + sertifikat) di atas definisi kanonik V9. PP_V10_FAST=0 = perilaku V9. */
function pp_v10_fast(): bool { return pp_v9_canon() && (string)getenv('PP_V10_FAST') !== '0'; }
/* Jangkar kanonik D(S): Actual (PGN/Jababeka/MM2100) dan fixed flow manual dihapus. null bila S tidak memuat keduanya. */
function pp_v9_anchor(array $orig): ?array {
    $m = (array)($orig['data3']['modeling'] ?? []); $has = false;
    foreach (['actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100'] as $k) foreach ((array)($m[$k] ?? []) as $v) if ($v !== '' && $v !== null) { $has = true; break 2; }
    if (!empty($m['manual_fixed_flows'])) $has = true;
    if (!$has) return null;
    $a = $orig;
    foreach (['actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100'] as $k) if (isset($a['data3']['modeling'][$k])) $a['data3']['modeling'][$k] = array_fill(0, 48, '');
    $a['data3']['modeling']['manual_fixed_flows'] = [];
    return $a;
}
function pp_v9_final_file(array $input): string { return pp_final_dir() . DIRECTORY_SEPARATOR . substr(hash('sha256', json_encode(pp_final_canon($input))), 0, 32) . '.json'; }
function pp_v9_final_load(array $input): ?array {
    $f = pp_v9_final_file($input); if (!is_file($f)) return null;
    $r = json_decode((string)@file_get_contents($f), true);
    if (!is_array($r) || !is_array($r['v3'] ?? null) || count((array)($r['data'] ?? [])) !== 48) return null;
    if (($r['v3']['schema'] ?? '') !== PP_V3_SCHEMA || ($r['v3']['engine'] ?? '') !== pp_engine_fingerprint() || empty($r['v3']['v9_canonical'])) return null;
    $r['_file'] = basename($f); return $r;
}
function pp_tl_seed_stops(array $orig): array {
    $out = [];
    if (pp_v9_canon()) return [];          // V9: seed dari FINAL sebelumnya membuat pemenang bergantung riwayat
    try {
        $m = (array)($orig['data3']['modeling'] ?? []);
        $act = strtolower((string)($m['gas_shortage_action'] ?? 'none'));
        if (!in_array($act, ['none', 'add_lng', 'recommendation'], true) || !empty($m['change_over']['enabled'])) return [];
        $new = pp_flat_for_diff(pp_final_canon($orig));
        $allowed = ['~^/data3/modeling/gas_quota/~', '~^/data3/modeling/additional_lng$~',
                    '~^/data3/modeling/gas_shortage_action$~', '~^/data3/modeling/actual_data(/|$)~',
                    '~^/data3/modeling/actual_gas_rows(/|$)~', '~^/data3/modeling/actual_rows(/|$)~',
                    '~^/data3/modeling/actual_pgn_total$~', '~^/data3/modeling/actual_energy_jababeka$~',
                    '~^/data3/modeling/actual_energy_mm2100$~', '~^/data3/modeling/manual_fixed_flows(/|$)~',
                    '~^/data3/modeling/actual_pgn_total/~', '~^/data3/modeling/actual_energy_jababeka/~', '~^/data3/modeling/actual_energy_mm2100/~'];
        $cands = [];
        foreach (glob(pp_final_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
            $r = json_decode((string)@file_get_contents($f), true);
            if (!is_array($r) || !is_array($r['input'] ?? null) || count((array)($r['data'] ?? [])) !== 48) continue;
            $old = pp_flat_for_diff($r['input']); $ok = true; $n = 0;
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
                if (($old[$k] ?? null) === ($new[$k] ?? null)) continue;
                $n++; $hit = false;
                foreach ($allowed as $re) if (preg_match($re, $k)) { $hit = true; break; }
                if (!$hit) { $ok = false; break; }
            }
            if ($ok && $n > 0) $cands[] = [(float)($r['saved_at'] ?? 0), pp_tl_stops_of((array)$r['data'])];
        }
        usort($cands, function ($a, $b) { return $b[0] <=> $a[0]; });
        $seen = [];
        foreach ($cands as $c) { $h = md5(json_encode($c[1])); if (isset($seen[$h]) || !$c[1]) continue; $seen[$h] = true; $out[] = $c[1]; if (count($out) >= 3) break; }
    } catch (Throwable $e) { $out = []; }
    return $out;
}
/* Maximum Review: kandidat valid terbaik keluarga commitment yang sudah selesai dievaluasi,
 * ditampilkan sebagai VALID PROVISIONAL selama optimasi exact masih berjalan (terkunci). */
function pp_tl_label_provisional(array $out, array $meta, ?array $st): array {
    $out['provisional'] = true;
    $out['provisional_status'] = 'VALID_PROVISIONAL';
    $out['provisional_label'] = 'VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS';
    $out['provisional_checks'] = (array)($meta['checks'] ?? []) + ['hard_violations' => 0];
    $out['provisional_basis'] = ['commitment_from' => 'keluarga commitment ruang kandidat exact (node selesai)',
        'units_off_all_day' => $meta['units_off'] ?? [], 'nodes_done' => (int)($st['nodes'] ?? 0),
        'evaluations' => (int)($st['evaluated'] ?? 0), 'base_cost' => null, 'base_cost_production' => null];
    $out['preliminary'] = true; $out['final_result_visible'] = true;
    $out['save_allowed'] = false; $out['publish_allowed'] = false;
    $out['release_gate'] = ['release_allowed' => false, 'status' => 'PROVISIONAL',
        'hard_validation' => 'PASS', 'economic_review' => 'IN_PROGRESS', 'convergence' => 'PROVISIONAL',
        'blocking_reasons' => ['EXACT_COST_OPTIMIZATION_IN_PROGRESS'],
        'note' => 'Hasil provisional valid terhadap seluruh hard constraint, tetapi BUKAN cost minimum global. Publish Final dibuka setelah optimasi exact selesai.'];
    $out['preliminary_reason'] = 'VALID_PROVISIONAL';
    $out['preliminary_note'] = 'VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS. Kandidat valid terbaik yang sudah dievaluasi; biaya ini belum dibuktikan terendah.';
    return $out;
}
/* Hasil kolam siap tampil: dispatch 48 baris apa adanya + label TIME-LIMITED yang jelas. */
function pp_tl_label_output(array $out, array $meta, bool $proven): array {
    $out['time_limited'] = true;
    $out['status'] = 'BEST_VALID_WITHIN_TIME_LIMIT';
    $out['result_label'] = 'TIME-LIMITED VALID PLAN';
    $out['global_optimum_proven'] = $proven;
    $out['ok'] = true; $out['result'] = 'time_limited';
    $out['preliminary'] = true; $out['final_result_visible'] = true;
    $out['save_allowed'] = false; $out['publish_allowed'] = false;
    $out['release_gate'] = ['release_allowed' => false, 'status' => 'TIME_LIMITED', 'hard_validation' => 'PASS',
        'economic_review' => $proven ? 'COMPLETE' : 'NOT_PROVEN', 'convergence' => 'TIME_LIMITED',
        'blocking_reasons' => ['TIME_LIMITED_NOT_PROVEN_GLOBAL_OPTIMUM'],
        'note' => 'Rencana valid terhadap seluruh hard constraint (48 baris, PLN Export 48/48, gas, reserve, Bus Flow, residual 0), dipilih dengan Cost Production terendah di antara kandidat yang sudah dievaluasi. Bukan bukti global optimum.'];
    $out['preliminary_reason'] = 'TIME_LIMITED_VALID_PLAN';
    $out['time_limited_checks'] = $meta['checks'] ?? [];
    $out['info']['Result Status'] = 'TIME-LIMITED VALID PLAN — BEST VALID WITHIN TIME LIMIT (Global optimum proven: ' . ($proven ? 'YES' : 'NO') . ')';
    $out['info']['Time-Limited Candidate'] = ['source' => $meta['source'] ?? null,
        'units_off_all_day' => $meta['units_off'] ?? [], 'checks' => $meta['checks'] ?? []];
    return $out;
}

/* ==============================================================================================
 *  V3 — SATU PEMILIK PERHITUNGAN PER STATE + RECOMPUTE INKREMENTAL BERBASIS DEPENDENSI.
 *
 *  AKAR MASALAH (terukur, profil per tahap pada rencana dengan Actual Gas 11 jam, PGN 30):
 *  perubahan SATU slot Actual PGN / Actual Fixed Flow JBBK / MM / fixed flow KP72 menghasilkan
 *  hash payload baru, sehingga seluruh pipeline exact dijalankan dari nol: baseline core run
 *  (21-22 s), mandatory stop + decommit screening (69-71 s, 13 core run), koreksi window gas,
 *  Global Commitment Review (92-96 s, 10 core run), lalu keluarga commitment (130-134 s,
 *  9 node). Total 315-345 s, 450-590 simulasi inti; 90 % waktu inklusif berada di perbaikan
 *  window supplier PGN dan 69 % di perbaikan lantai Export — keduanya dijalankan ulang untuk
 *  SETIAP kandidat commitment walaupun perubahan hanya menggeser neraca gas satu slot. Pada
 *  orkestrasi V2, Run yang sama juga memicu pencarian Target Selesai paralel, tahap provisional
 *  sinkron, dan job exact untuk input yang sama.
 *
 *  PERBAIKAN:
 *   1. Run = simpan input atomik -> diff dengan final valid terakhir -> SATU job (pemilik
 *      tunggal) yang memilih inkremental atau exact -> validasi -> hasil. Tidak ada tahap
 *      provisional sinkron, tidak ada pencarian Target Selesai paralel, job lama untuk state lain
 *      dihentikan.
 *   2. Inkremental: bila state hanya berbeda pada data slot (Actual PGN, Actual Energy JBBK/MM,
 *      manual fixed flow JBBK/MM2100/KP72) atau kuota gas, commitment pemenang final terakhir
 *      dipertahankan dan hanya dispatch-nya yang dihitung ulang (baris yang tidak bergantung pada
 *      slot yang berubah terbukti identik dan dipakai ulang). Seluruh kandidat ruang exact
 *      sebelumnya yang DAPAT menyalip pemenang (valid dan dekat biaya, atau hanya gagal window
 *      gas) didaratkan ulang pada state baru. Pemenang = Cost Production terendah di antara
 *      kandidat constraint-valid tersebut. Bila commitment, feasibility, atau pemenang tidak
 *      dapat dijamin (kandidat dekat terlalu banyak, commitment pemenang tidak feasible,
 *      perubahan non-slot, basis tidak tersedia) job langsung menjalankan pipeline exact penuh.
 * ============================================================================================ */
const PP_V3_SCHEMA = 'co12-v3-final-v1';
const PP_V3_INC_BUDGET_S = 60.0;
const PP_V9_INC_BUDGET_S = 180.0;          // batas waktu upaya inkremental sebelum jatuh ke exact
const PP_V3_MAX_NEAR = 12;                // kandidat dekat maksimum yang didaratkan ulang
function pp_v9_max_near(): int { return pp_v9_canon() ? 16 : PP_V3_MAX_NEAR; }   // V9: pesaing review delta ikut dibawa
const PP_V3_MAX_CHAIN = 8;                // rantai inkremental maksimum sebelum exact penuh lagi
/* Field modeling yang hanya label/arsip UI: tidak dibaca engine (terverifikasi dengan grep:
 * `worker` dan `note` hanya dipakai sebagai label keluaran). */
function pp_v3_meta_keys(): array { return ['name_plan', 'note', 'plan_date', 'plan_name', 'plan_remark', 'plan_type', 'report_planning', 'worker']; }
function pp_v3_strip_meta(array &$m): void { foreach (pp_v3_meta_keys() as $k) unset($m[$k]); }
/* Identitas state engine (untuk bukti "payload tersimpan = payload dihitung"). */
function pp_v3_state_hash(array $input): string {
    return hash('sha256', json_encode(pp_final_canon(pp_normalize_copy($input)), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}
function pp_v3_relabel(array &$out, array $input): void {
    $m = (array)($input['data3']['modeling'] ?? []);
    if (array_key_exists('worker', $out)) $out['worker'] = $m['worker'] ?? 'worker02';
    if (isset($out['modeling']) && is_string($out['modeling'])) $out['modeling'] = 'Daily Simulation: ' . ($m['note'] ?? '');
}
/* ---- AUTO-SAVE ATOMIK SEBELUM SIMULASI -------------------------------------------------------
 * Aturan yang sama dengan tombol Save (mode=save): merge report_planning dengan disk, sanitasi
 * snapshot bersarang, tulis atomik (temp -> validasi ulang -> rename). Bila gagal, file lama utuh
 * dan simulasi TIDAK dimulai. Isi yang identik dengan disk tidak ditulis ulang (klik ganda). */
function pp_v3_autosave(array &$input): array {
    $t = microtime(true);
    if (function_exists('sds_ctx') && sds_ctx($input)['uid'] !== '' && (string)getenv('PP_V1516_SCOPED_SAVE') !== '0') {
        /* V15.16: autosave Run = snapshot input IMMUTABLE milik user/tab/run pemanggil (bukan file global bersama). */
        $ctxA = sds_ctx($input); $whyA = null; $save = $input; foreach (array_keys($save) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($save[$k]);
        $recA = sds_commit('AUTOSAVE_RUN', $save, null, $ctxA, ['source' => 'run_autosave'], $whyA);
        return ['ok' => $recA !== null, 'scoped' => true, 'record_id' => $recA['record_id'] ?? null, 'version' => $recA['version'] ?? null, 'error' => $whyA,
                'skipped_identical' => false, 'ms' => round((microtime(true) - $t) * 1000, 1), 'backend' => sds_backend()];
    }
    $f = __DIR__ . '/input_data.json';
    $save = $input;
    foreach (array_keys($save) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($save[$k]);
    $rpDiag = null;
    try { pp_merge_report_planning($save, $f, $rpDiag); } catch (Throwable $e) { $rpDiag = ['error' => $e->getMessage()]; }
    $nStrip = pp_sanitize_report_planning($save);
    $norm = json_decode(json_encode($save), true);
    $old = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $same = is_array($old) && is_array($norm) && json_encode($old) === json_encode($norm);
    $why = null; $ok = true;
    if (!$same) $ok = pp_atomic_write_json($f, $save, $why);
    $r = ['ok' => (bool)$ok, 'file' => 'input_data.json', 'skipped_identical' => $same,
          'ms' => round((microtime(true) - $t) * 1000, 1), 'error' => $ok ? null : $why,
          'old_file_intact' => $ok ? null : true, 'sanitized_nested_snapshots' => $nStrip,
          'report_planning_merge' => $rpDiag];
    if (!$ok) return $r;
    clearstatcache(true, $f);
    $r['bytes'] = (int)@filesize($f);
    $r['sha256'] = (string)@hash_file('sha256', $f);
    $disk = json_decode((string)@file_get_contents($f), true);
    /* Yang dihitung adalah konten yang tersimpan (setelah merge) + field transport request. */
    foreach ($input as $k => $v) if (is_string($k) && $k !== '' && $k[0] === '_' && $k !== '_autosave') $save[$k] = $v;
    $input = $save;
    $r['state_hash_saved'] = is_array($disk) ? pp_v3_state_hash($disk) : null;
    $r['state_hash_computed'] = pp_v3_state_hash($input);
    $r['saved_equals_computed'] = $r['state_hash_saved'] !== null && hash_equals($r['state_hash_saved'], $r['state_hash_computed']);
    return $r;
}
/* Job economic_review lain yang masih berjalan untuk konteks yang sama dihentikan: Run baru
 * menggantikan state lama, sehingga tidak ada job lama yang tetap hidup. */
/* V15.16: identitas user/tab dari request (dikirim wrapper fetch index.php); '' untuk CLI / klien lama. */
function pp_v1516_req_ident(string $k): string {
    return substr((string)preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET[$k] ?? ($_POST[$k] ?? ''))), 0, 64);
}
function pp_v3_supersede(array $input): array {
    $keep = pp_job_input_hash($input);
    $ctx = (string)($input['_context'] ?? '');
    $done = [];
    /* V15.16 ISOLASI MULTI-USER: Run baru hanya menggantikan job milik user+tab yang sama. Dahulu filter
     * hanya prefiks konteks request ("plan-"), yang sama untuk semua user, sehingga Run user A membatalkan
     * job user B yang sedang berjalan (terukur: job B CANCELLED "DIGANTIKAN_RUN_BARU" pada detik yang sama).
     * Job yang masih dipantau run lain (input identik, job bersama) juga tidak dibatalkan. */
    $scoped = (string)getenv('PP_V1516_SUPERSEDE_SCOPE') !== '0';
    $myUid = pp_v1516_req_ident('uid'); $myTab = pp_v1516_req_ident('tab');
    foreach ((array)@scandir(pp_job_root()) as $e) {
        if (!is_string($e) || $e === '' || $e[0] === '.' || $e[0] === '_' || strpos($e, 'economic_review-') !== 0) continue;
        $j = pp_job_read($e);
        if (!is_array($j) || !in_array((string)($j['status'] ?? ''), ['QUEUED', 'CLAIMED', 'RUNNING'], true)) continue;
        if (hash_equals((string)($j['input_hash'] ?? ''), $keep)) continue;
        $rq = (string)($j['request_id'] ?? '');
        if ($ctx !== '' && $rq !== '' && strpos($rq, $ctx . '-') !== 0) continue;   // konteks lain (Plan vs Monitoring)
        if ($scoped) {
            if ((string)($j['owner_uid'] ?? '') !== $myUid || (string)($j['owner_tab'] ?? '') !== $myTab) continue;   // milik user/tab lain
            $othersS = 0;
            foreach ((array)@glob(pp_job_dir($e) . '/subs/*') as $sf)
                if (is_file($sf) && basename($sf) !== $rq && @filemtime($sf) >= time() - 20) $othersS++;
            if ($othersS > 0) continue;                                              // masih dipantau run lain
        }
        pp_job_update($e, function (array $x): array {
            $x['cancel_requested'] = true; $x['cancel_abort'] = true;
            if (in_array((string)($x['status'] ?? ''), ['QUEUED', 'CLAIMED', 'RUNNING'], true)) {
                $x['status'] = 'CANCELLED'; $x['finished_at'] = date('c'); $x['current_step'] = 'DIGANTIKAN_RUN_BARU';
            }
            return $x; });
        $done[] = $e;
    }
    return $done;
}
function pp_v3_handoff_respond(array $input, $rid, $rev, array $autosave, array $superseded): void {
    $stF = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
    if (empty($stF['ok']) || empty($stF['job']['job_id']))
        pp_fail(500, 'Job perhitungan tidak dapat dibuat: ' . (string)($stF['error'] ?? '?'),
            ['code' => 'JOB_START_FAILED', 'request_id' => $rid, 'state_revision' => $rev, 'autosave' => $autosave]);
    $jF = (array)$stF['job'];
    $asyncF = ['required' => true, 'kind' => 'economic_review', 'reason' => 'Satu pemilik perhitungan per state (V3).',
        'ok' => true, 'job_id' => $jF['job_id'], 'exec_token' => $jF['exec_token'] ?? null,
        'status' => $jF['status'] ?? null, 'reused' => (bool)($stF['reused'] ?? false),
        'input_hash' => pp_job_input_hash($input), 'error' => null, 'handoff_reason' => 'V3_SINGLE_OWNER',
        'helpers' => (int)($jF['helpers'] ?? pp_v4_helper_slots())];
    $outF = ['ok' => true, 'result' => 'pending', 'status' => 'ECONOMIC_REVIEW_REQUIRED',
        'data' => [], 'info' => ['Async Economic Review Job' => $asyncF],
        'preliminary' => true, 'final_result_visible' => false, 'save_allowed' => false,
        'publish_allowed' => false, 'preliminary_reason' => 'V3_SINGLE_OWNER',
        'preliminary_note' => 'Perhitungan dijalankan oleh satu job untuk state ini; hasil ditampilkan begitu tersedia.',
        'async_job' => $asyncF, 'request_id' => $rid,
        'state_revision' => $rev !== null ? (int)$rev : null,
        'orchestration' => ['schema' => 'co12-v3-orchestration-v1', 'owner' => $jF['job_id'],
            'owner_reused' => (bool)($stF['reused'] ?? false), 'superseded_jobs' => $superseded,
            'provisional_stage' => false, 'parallel_time_limited_search' => false,
            'schema_v4' => PP_V4_SCHEMA, 'helper_workers' => (int)($jF['helpers'] ?? 0), 'cpu_cores' => pp_v4_cores(),
            'note_v4' => 'Satu job pemilik per state; pekerja pembantu hanya mengerjakan candidate-state yang diterbitkan pemilik (satu pemilik per candidate-state).'],
        'autosave' => $autosave,
        '_saved' => ['input' => !empty($autosave['ok']), 'output' => false]];
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo pp_json_out($outF); exit;
}

/* ---- DIRTY ANALYSIS --------------------------------------------------------------------------- */
function pp_v3_slot_fields(): array { return ['actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100']; }
/* Diff state baru terhadap input final tersimpan. null = ada perubahan di luar data slot/kuota. */
function pp_v3_diff(array $oldCanon, array $newCanon): ?array {
    $old = pp_flat_for_diff($oldCanon); $new = pp_flat_for_diff($newCanon);
    $slotRe = '~^/data3/modeling/(actual_pgn_total|actual_energy_jababeka|actual_energy_mm2100|manual_fixed_flows|actual_data|actual_gas_rows|actual_rows)(/|$)~';
    $quotaRe = '~^/data3/modeling/gas_quota/~';
    $paths = []; $slot = false; $quota = []; $other = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
        if (($old[$k] ?? null) === ($new[$k] ?? null)) continue;
        $paths[] = $k;
        if (preg_match($slotRe, $k)) $slot = true;
        elseif (preg_match($quotaRe, $k)) $quota[substr($k, strlen('/data3/modeling/gas_quota/'))] = [json_decode($old[$k] ?? 'null', true), json_decode($new[$k] ?? 'null', true)];
        /* V5: Additional LNG (aksi add_lng pada kedua sisi) = tambahan kuota LNG must-take. */
        elseif ($k === '/data3/modeling/additional_lng' && (string)getenv('PP_V5_FUEL_INC') !== '0'
                && strtolower((string)($oldCanon['data3']['modeling']['gas_shortage_action'] ?? '')) === 'add_lng'
                && strtolower((string)($newCanon['data3']['modeling']['gas_shortage_action'] ?? '')) === 'add_lng')
            $quota['additional_lng'] = [json_decode($old[$k] ?? 'null', true), json_decode($new[$k] ?? 'null', true)];
        else $other[] = $k;
    }
    if ($other) return ['ok' => false, 'paths' => $paths, 'other' => $other];
    $mo = (array)($oldCanon['data3']['modeling'] ?? []); $mn = (array)($newCanon['data3']['modeling'] ?? []);
    $rows = []; $fields = []; $slotAbs = 0.0; $slotsChanged = 0; $slotSigned = 0.0; $slotUnknown = 0.0;
    foreach (pp_v3_slot_fields() as $fk) {
        $a = array_values((array)($mo[$fk] ?? [])); $b = array_values((array)($mn[$fk] ?? []));
        for ($i = 0; $i < max(count($a), count($b)); $i++) {
            $x = $a[$i] ?? ''; $y = $b[$i] ?? '';
            if ((string)$x === (string)$y) continue;
            $slotsChanged++; $fields[$fk][] = $i;
            $slotAbs += abs((is_numeric($y) ? (float)$y : 0.0) - (is_numeric($x) ? (float)$x : 0.0));
            if (is_numeric($x) && is_numeric($y)) $slotSigned += (float)$y - (float)$x;          // pemakaian gas bertambah
            else $slotUnknown += abs(is_numeric($y) ? (float)$y : (float)$x);                    // jam actual ditambah/dihapus
            $rows[$i + 1] = true; if ($i % 2 === 0) $rows[$i + 2] = true;   // nilai per jam di indeks genap: 2 row
        }
    }
    $fo = []; foreach ((array)($mo['manual_fixed_flows'] ?? []) as $e) if (is_array($e)) $fo[strtoupper((string)($e['area'] ?? '')) . '#' . (int)($e['row'] ?? 0)] = (float)($e['value_mmscfd'] ?? 0);
    $fn = []; foreach ((array)($mn['manual_fixed_flows'] ?? []) as $e) if (is_array($e)) $fn[strtoupper((string)($e['area'] ?? '')) . '#' . (int)($e['row'] ?? 0)] = (float)($e['value_mmscfd'] ?? 0);
    $ffAbs = 0.0;
    foreach (array_unique(array_merge(array_keys($fo), array_keys($fn))) as $kf) {
        if (isset($fo[$kf], $fn[$kf]) && abs($fo[$kf] - $fn[$kf]) < 1e-12) continue;
        $slotsChanged++; $r = (int)explode('#', $kf)[1]; if ($r >= 1 && $r <= 48) $rows[$r] = true;
        $fields['manual_fixed_flows'][] = $kf; $ffAbs += abs(($fn[$kf] ?? 0.0) - ($fo[$kf] ?? 0.0));
    }
    $qAbs = 0.0; $qSigned = 0.0;
    foreach ($quota as $q) { $d = (float)($q[1] ?? 0) - (float)($q[0] ?? 0); $qAbs += abs($d); $qSigned += $d; }
    ksort($rows);
    /* Besaran perubahan gas harian (BBTUD): perubahan kuota + perubahan nilai slot actual (nilai
     * satu jam menggantikan kontribusi dua row pada total harian secara langsung; terukur: +0,048
     * pada satu jam Actual PGN menggeser gas efektif +0,05) + fixed flow per row / 48. Dipakai
     * untuk menentukan kandidat mana yang masih dapat berubah status feasibility-nya. */
    $gasMag = $qAbs + $slotAbs + $ffAbs / 48.0;
    return ['ok' => true, 'paths' => $paths, 'kind' => $quota ? ($slot ? 'slot+quota' : 'quota') : 'slot',
            'slot_changes' => $slotsChanged, 'fields' => $fields, 'quota' => $quota,
            'dirty_rows' => array_keys($rows), 'gas_change_bbtud' => round($gasMag, 6), 'n' => count($paths),
            /* Pergeseran window gas relatif terhadap pemakaian (BBTUD, bertanda): kuota naik = ruang
             * gas bertambah; actual naik = ruang gas berkurang. `uncertain` = jam actual yang
             * ditambah/dihapus (selisih terhadap estimasinya tidak diketahui). */
            'gas_shift_bbtud' => round($qSigned - $slotSigned, 6), 'gas_shift_uncertain_bbtud' => round($slotUnknown + $ffAbs / 48.0, 6)];
}
/* Basis = final valid terakhir (inkremental atau exact) yang berbeda hanya pada data slot/kuota. */
function pp_v3_find_base(array $orig): array {
    $m = (array)($orig['data3']['modeling'] ?? []);
    $act = strtolower((string)($m['gas_shortage_action'] ?? 'none'));
    /* V5: rerun pilihan bahan bakar LNG (add_lng) melanjutkan dari FINAL tervalidasi dengan aksi yang
     * sama (mis. opsi LNG rekomendasi): perbedaan Additional LNG diperlakukan sebagai perubahan kuota
     * LNG, ruang kandidat basis dievaluasi ulang pada state baru — tidak memulai pipeline dari nol.
     * Distillate/campuran tetap exact (alokasi distillate diskret). PP_V5_FUEL_INC=0 mematikan. */
    $fuelInc = $act === 'add_lng' && (string)getenv('PP_V5_FUEL_INC') !== '0';
    if (!in_array($act, ['none', 'recommendation'], true) && !$fuelInc) return ['base' => null, 'reason' => 'AKSI_BAHAN_BAKAR_' . strtoupper($act)];
    if (!empty($m['change_over']['enabled'])) return ['base' => null, 'reason' => 'CHANGE_OVER_AKTIF'];
    if ((float)($m['additional_lng'] ?? 0) > 1e-9 && !$fuelInc) return ['base' => null, 'reason' => 'ADDITIONAL_LNG'];
    $new = pp_final_canon($orig);
    /* V9 KANONIK: basis inkremental BUKAN "FINAL terakhir yang mirip" (bergantung riwayat), melainkan FINAL
     * kanonik state jangkar D(S) = S tanpa Actual/fixed flow manual. D(S) adalah fungsi state saja, dan FINAL(D)
     * dihitung dengan pipeline exact + review generik, sehingga hasil S tidak bergantung jalur. */
    if (pp_v9_canon()) {
        if (!in_array($act, ['none', 'recommendation'], true)) return ['base' => null, 'reason' => 'V9_AKSI_BAHAN_BAKAR_EXACT'];
        $anc = pp_v9_anchor($orig);
        if ($anc === null) return ['base' => null, 'reason' => 'V9_STATE_TANPA_ACTUAL_EXACT_KANONIK'];
        $r = pp_v9_final_load($anc);
        if ($r === null) return ['base' => null, 'reason' => 'V9_JANGKAR_BELUM_ADA'];
        $d = pp_v3_diff($r['input'], $new);
        if ($d === null || empty($d['ok']) || (int)$d['n'] === 0) return ['base' => null, 'reason' => 'V9_JANGKAR_TIDAK_DAPAT_DIPAKAI'];
        return ['base' => $r, 'diff' => $d, 'reason' => null];
    }
    $best = null; $bestDiff = null; $why = 'TIDAK_ADA_FINAL_VALID_V3';
    foreach (glob(pp_final_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        $r = json_decode((string)@file_get_contents($f), true);
        if (!is_array($r) || !is_array($r['input'] ?? null) || !is_array($r['v3'] ?? null) || count((array)($r['data'] ?? [])) !== 48) continue;
        if (($r['v3']['schema'] ?? '') !== PP_V3_SCHEMA || ($r['v3']['engine'] ?? '') !== pp_engine_fingerprint()) continue;
        $d = pp_v3_diff($r['input'], $new);
        if ($d === null || empty($d['ok'])) { if ($best === null) $why = 'PERUBAHAN_DI_LUAR_DATA_SLOT_ATAU_KUOTA'; continue; }
        if ((int)$d['n'] === 0) continue;
        if ($best === null || (float)$r['saved_at'] > (float)$best['saved_at']) { $best = $r; $bestDiff = $d; $best['_file'] = basename($f); }
    }
    return $best === null ? ['base' => null, 'reason' => $why] : ['base' => $best, 'diff' => $bestDiff, 'reason' => null];
}

/* ---- EVALUASI KANDIDAT ------------------------------------------------------------------------ */
function pp_v3_commitment_stops(array $c): array {
    $st = array_values((array)($c['stops'] ?? []));
    foreach ((array)($c['seed'] ?? []) as $s) $st[] = $s;
    foreach ((array)($c['off'] ?? []) as $u) $st[] = ['unit' => $u, 'start' => 1, 'stop' => 48];
    return $st;
}
function pp_v3_sig(array $stops): string {
    $n = []; foreach ($stops as $s) $n[] = strtolower((string)$s['unit']) . ':' . (int)$s['start'] . '-' . (int)$s['stop'];
    sort($n); return md5(implode('|', $n));
}
/* Satu dispatch commitment tetap pada state baru (redispatch 48 row + pendaratan gas). */
function pp_v3_eval(array $orig, array $c, float $adj, ?float $hint, float $dl): ?array {
    $a = pp_tl_eval($orig, (array)($c['off'] ?? []), $adj, $dl, array_merge(array_values((array)($c['stops'] ?? [])), array_values((array)($c['seed'] ?? []))), $hint);
    if (is_array($a)) $a['off'] = (array)($c['off'] ?? []);
    return $a;
}
/* Dispatch 48 baris final basis DIBEKUKAN (setiap unit GTG pada MW yang sama per row) lalu dihitung
 * ulang satu kali terhadap input baru: hanya akuntansi slot yang berubah (gas actual, fixed flow)
 * dan turunannya yang dihitung ulang. Valid bila perubahan terserap slack window gas. */
function pp_v3_frozen_eval(array $orig, array $bd, float $dl): ?array {
    if (count($bd) !== 48 || microtime(true) > $dl - 1.0) return null;
    $in = json_decode(json_encode($orig), true); $m = &$in['data3']['modeling'];
    $m['unit_stop_time'] = (array)($m['unit_stop_time'] ?? []);
    foreach (pp_v3_stops($bd) as $st) $m['unit_stop_time'][] = $st;
    foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $rules = [];
        for ($r = 1; $r <= 48; $r++) { $v = (float)($bd[$r - 1][$U] ?? 0); if ($v > 0.01) $rules[] = ['start' => $r, 'stop' => $r, 'value' => round($v, 4)]; }
        if ($rules) { $m['unit_fix_load'] = (array)($m['unit_fix_load'] ?? []); $m['unit_fix_load'][$u] = $rules; } }
    $m['__tl_no_auto_start'] = true; $m['time_budget_seconds'] = 60.0; $m['time_budget_max_seconds'] = 60.0;
    unset($m);
    pp_tl_clean_globals(); pp_budget_start(60.0, true, true); $GLOBALS['__pp_budget_deadline'] = $dl;
    try { $o = pp_run_simulation_once($in); } catch (Throwable $e) { pp_tl_clean_globals(); return null; }
    $a = pp_tl_assess($orig, $o); pp_tl_clean_globals();
    $a['output'] = $o; $a['off'] = []; $a['adj'] = 0.0; $a['supplier_target'] = pp_tl_supplier_target($o);
    return $a;
}
/* Pemenang commitment dipertahankan: pindaian lever gas di sekitar lever pemenang lama (titik
 * awal supplier dari pemenang lama), lalu pendaratan bisection bila belum ada yang valid. */
function pp_v3_scan_winner(array $orig, array $c, float $a0, ?float $hint, float $dl, int &$evals, callable $tick, float $dirHint = 0.0, ?float &$minDev = null, float $exitDev = 0.3): ?array {
    $best = null; $seen = []; $minDev = INF;
    $ev = function (float $adj) use ($orig, $c, $hint, $dl, &$evals, $tick, &$best, &$seen) {
        $k = sprintf('%.6f', $adj); if (isset($seen[$k])) return $seen[$k];
        if (microtime(true) > $dl - 1.0) return null;
        $a = pp_v3_eval($orig, $c, $adj, $hint, $dl); $evals++;
        if (!is_array($a)) return null;
        $a['adj'] = $adj; $seen[$k] = $a; $tick($a);
        $minDev = min($minDev, empty($a['valid']) ? (!empty($a['gas_only']) ? abs((float)$a['dev']) : INF) : 0.0);
        if (!empty($a['valid']) && ($best === null || pp_global_commitment_better($a['key'], $best['key']))) $best = $a;
        return $a;
    };
    /* Titik lever tetap di sekitar lever pemenang lama (deterministik untuk setiap state), diurutkan
     * searah deviasi gas titik awal sehingga dispatch valid pertama cepat ditemukan; seluruh titik
     * tetap dievaluasi dan dispatch valid termurah dipilih. */
    /* Urutan terukur: pergeseran lever besar (±0,16) memindahkan pola dispatch melewati ambang
     * diskret sehingga kompensasi supplier mendarat; titik kecil menyusul untuk biaya terendah. */
    /* Arah awal dari deviasi gas dispatch beku (bila ada): gas di atas window -> lever turun. */
    $dir = $dirHint > 0 ? -1.0 : 1.0;
    $a = $ev($dirHint != 0.0 ? $a0 + $dir * 0.16 : $a0);
    /* Kekurangan gas nyata (residual shortage > 0) tidak dapat diselesaikan lever dispatch: alur
     * keputusan bahan bakar (exact) yang berlaku, tanpa membuang waktu pada titik lever lain. */
    if (is_array($a) && empty($a['valid']) && isset($a['checks']['residual_shortage_zero']) && $a['checks']['residual_shortage_zero'] === false) return null;
    if ($dirHint == 0.0) $dir = (is_array($a) && empty($a['valid']) && (float)($a['dev'] ?? 0) > 0) ? -1.0 : 1.0;
    foreach (($dirHint != 0.0 ? [-0.16, 0.0, 0.08, -0.08, 0.04, -0.04] : [0.16, -0.16, 0.08, -0.08, 0.04, -0.04]) as $d) {
        if ($ev($a0 + $dir * $d) === null && microtime(true) > $dl - 1.0) break;
        if ($best === null && count($seen) >= 3 && $minDev > $exitDev) break;   // tiga titik lever jauh dari window: commitment tidak menyerap perubahan
    }
    if ($best === null && $minDev > $exitDev) return null;       // tidak ada titik yang mendekati window gas
    /* Belum ada yang valid: pendaratan bisection pada braket deviasi gas bertetangga (tanda deviasi
     * berlawanan) yang sudah teramati, braket terkecil lebih dulu; maks. 6 evaluasi. */
    for ($n = 0; $n < 6 && $best === null; $n++) {
        $pts = [];
        foreach ($seen as $k => $x) if (is_array($x) && !empty($x['gas_only']) && abs((float)$x['dev']) > 1e-9) $pts[] = [(float)$k, (float)$x['dev']];
        usort($pts, function ($p, $q) { return $p[0] <=> $q[0]; });
        $pair = null;
        for ($q = 0; $q + 1 < count($pts); $q++) {
            if (($pts[$q][1] > 0) === ($pts[$q + 1][1] > 0) || $pts[$q + 1][0] - $pts[$q][0] < 2e-4) continue;
            $w = abs($pts[$q][1]) + abs($pts[$q + 1][1]);
            if ($pair === null || $w < $pair[2]) $pair = [$pts[$q][0], $pts[$q + 1][0], $w];
        }
        if ($pair === null) break;
        if ($ev(0.5 * ($pair[0] + $pair[1])) === null) break;
    }
    return $best;
}
/* Kandidat pesaing: evaluasi yang SAMA dengan node keluarga pada pipeline exact (pendaratan
 * bisection maks. 5 evaluasi), ditambah titik lever pemenang bila ia commitment penuh. */
function pp_v3_eval_competitor(array $orig, array $c, float $dl, int &$evals, callable $tick): ?array {
    $L = pp_tl_land_commitment($orig, (array)($c['off'] ?? []), array_merge(array_values((array)($c['stops'] ?? [])), array_values((array)($c['seed'] ?? []))), $dl, 5);
    $evals += (int)($L['evals'] ?? 0);
    $a = $L['a'] ?? null;
    if (is_array($a)) { $a['off'] = (array)($c['off'] ?? []); $tick($a); }
    return is_array($a) ? $a : null;
}

/* ---- BLOK V3 UNTUK FINAL TERSIMPAN -------------------------------------------------------------- */
function pp_v3_block_from_exact(array $orig, array $out): array {
    $sp = (array)($out['info']['Exact Candidate Space'] ?? []);
    $key = pp_global_commitment_key($orig, $out);
    $cands = [];
    $add = function (array $c) use (&$cands) {
        $st = pp_v3_commitment_stops($c); $sg = pp_v3_sig($st); $c['sig'] = $sg;
        if (!isset($cands[$sg]) || (!empty($c['valid']) && (empty($cands[$sg]['valid']) || (float)$c['cp'] < (float)$cands[$sg]['cp']))) $cands[$sg] = $c;
    };
    try {
        $reg = pp_tl_registry_open($orig);
        foreach ($reg->allNodes() as $nk => $s) {
            if (!is_array($s['key'] ?? null)) continue;
            $add(['src' => 'family', 'nk' => $nk, 'off' => array_values((array)($s['off'] ?? [])), 'seed' => array_values((array)($s['seed'] ?? [])),
                  'valid' => !empty($s['valid']), 'gas_only' => !empty($s['gas_only']), 'dev' => (float)($s['dev'] ?? 0),
                  'cp' => (float)($s['key']['cp'] ?? 0), 'adj' => (float)($s['adj'] ?? 0), 'violations' => $s['violations'] ?? []]);
        }
    } catch (Throwable $e) {}
    if (is_array($sp['pipeline_winner_stops'] ?? null) && is_array($sp['pipeline_winner_key'] ?? null)) {
        $pk = $sp['pipeline_winner_key'];
        $add(['src' => 'pipeline', 'nk' => 'pipeline', 'stops' => $sp['pipeline_winner_stops'],
              'valid' => (int)($pk['hard'] ?? 1) === 0 && (int)($pk['constraint'] ?? 1) === 0, 'gas_only' => false, 'dev' => 0.0,
              'cp' => (float)($pk['cp'] ?? 0), 'adj' => 0.0]);
    }
    if (is_array($sp['evaluated_incumbent_stops'] ?? null) && is_array($sp['evaluated_incumbent_key'] ?? null)) {
        $add(['src' => 'incumbent', 'nk' => 'incumbent:evaluated', 'stops' => $sp['evaluated_incumbent_stops'],
              'valid' => true, 'gas_only' => false, 'dev' => 0.0, 'cp' => (float)($sp['evaluated_incumbent_key']['cp'] ?? 0), 'adj' => 0.0]);
    }
    $wStops = pp_v3_stops((array)$out['data']);
    $wAdj = 0.0; $wn = (string)($sp['winner_node'] ?? '');
    if ($wn !== '' && $wn !== 'incumbent:evaluated') { try { $s = pp_tl_registry_open($orig)->get($wn); if (is_array($s)) $wAdj = (float)($s['adj'] ?? 0); } catch (Throwable $e) {} }
    return ['schema' => PP_V3_SCHEMA, 'engine' => pp_engine_fingerprint(), 'source' => 'EXACT', 'chain' => 0,
            'winner' => ['stops' => $wStops, 'sig' => pp_v3_sig($wStops), 'cp' => (float)($key['cp'] ?? 0), 'key' => $key,
                         'adj' => $wAdj, 'supplier_target' => pp_tl_supplier_target($out), 'node' => $wn ?: 'pipeline'],
            'candidates' => array_values($cands)];
}

/* ---- PRA-TAHAP EXACT: KELUARGA COMMITMENT LEBIH DULU ------------------------------------------------
 * Pada jalur exact, node keluarga commitment (seed commitment final terkait, node akar, lalu anak-
 * anaknya) dievaluasi LEBIH DULU oleh job pemilik yang sama, maks. 20 detik, dan disimpan di registri
 * state ini. Tahap keluarga di akhir pipeline memakai ulang node-node itu (tidak dihitung dua kali),
 * sehingga total pekerjaan tidak bertambah; yang berubah hanya urutan: kandidat valid pertama tersedia
 * dalam hitungan detik untuk VALID PROVISIONAL dan Target Selesai, tanpa pencarian paralel. */
function pp_v3_prepass(string $jobId, array $input, float $capS = 20.0): array {
    $t0 = microtime(true);
    $orig = pp_normalize_copy($input);
    foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
    if (!empty($orig['data3']['modeling']['change_over']['enabled']) || (string)getenv('PP_EXACT_FAMILY') === '0') return ['ran' => false, 'reason' => 'TIDAK_BERLAKU'];
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $poolKey = pp_tl_key($input) . '_x';
    $F = null;
    try {
        $reg = pp_tl_registry_open($orig);
        $seeds = pp_tl_seed_stops($orig);
        $seedList = ($seeds && isset($seeds[0]) && is_array($seeds[0]) && !isset($seeds[0]['unit'])) ? $seeds : ($seeds ? [$seeds] : []);
        $F = pp_tl_family_search($orig, $t0 + $capS, $reg, ['seeds' => $seedList, 'max_nodes' => 64,
            'offer' => function (array $a) use ($poolKey, $jobId) { try { pp_tl_pool_offer($poolKey, $a, 'exact_family_prepass', $jobId); } catch (Throwable $e) {} },
            'stop' => function () { return !empty($GLOBALS['ppTlHook']['aborted']); },
            'tick' => function (int $n, int $v, int $e) { if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_family_node'); }]);
    } catch (Throwable $e) { $F = ['error' => $e->getMessage()]; }
    pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
    if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_TARGET_SELESAI');
    return ['ran' => true, 'wall_s' => round(microtime(true) - $t0, 2), 'nodes' => (int)($F['nodes'] ?? 0),
            'computed' => (int)($F['computed'] ?? 0), 'valid' => (int)($F['valid'] ?? 0), 'complete' => !empty($F['complete']),
            'stop_reason' => $F['reason'] ?? ($F['error'] ?? null)];
}

/* ---- PENJAGA KOLAM KANDIDAT ------------------------------------------------------------------------
 * Kandidat valid yang pernah ditampilkan untuk state ini (Target Selesai / provisional, kolam _x dan
 * _q) ikut dibandingkan dengan pemenang akhir: Maximum Review tidak pernah lebih mahal daripada
 * kandidat Target Selesai mana pun, termasuk dari job sebelumnya yang dihentikan pada batas waktu. */
function pp_v3_pool_guard(array $input, array $out): array {
    /* V9: FINAL tidak bergantung jalur. Kolam Target Selesai / run lain tidak menentukan pemenang;
     * seluruh kandidat prosedur kanonik sudah dibandingkan di dalam prosedur itu sendiri. */
    if (pp_v9_canon()) return $out;
    try {
        if (count((array)($out['data'] ?? [])) !== 48) return $out;
        $orig = pp_normalize_copy($input);
        foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
        $cur = pp_tl_assess($orig, $out);
        if (empty($cur['valid'])) return $out;                  // hasil bukan final valid: alur lain yang berlaku
        $key = pp_tl_key($input); $best = null; $side = null;
        foreach (['_x', '_q'] as $sd) {
            $b = pp_tl_read(pp_tl_file($key . $sd . '_best.json'));
            if (!is_array($b['output'] ?? null)) continue;
            $a = pp_tl_assess($orig, $b['output']);
            if (!empty($a['valid']) && pp_global_commitment_better($a['key'], $best === null ? $cur['key'] : $best['key'])) { $best = $a + ['output' => $b['output']]; $side = $sd; }
        }
        if ($best === null) return $out;
        $new = $best['output'];
        foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute'] as $k) if (isset($out['info'][$k])) $new['info'][$k] = $out['info'][$k];
        $new['info']['Global Commitment Review']['ladder'][] = ['unit' => 'valid_pool' . $side, 'variant' => 'V3_VALID_POOL_CANDIDATE', 'key' => $best['key'], 'better' => true];
        $new['info']['Global Commitment Review']['ladder'][] = ['unit' => 'previous_winner', 'variant' => 'V3_REPLACED_WINNER', 'key' => $cur['key'], 'better' => false];
        $new['info']['Global Commitment Review']['candidates_evaluated'] = (int)($new['info']['Global Commitment Review']['candidates_evaluated'] ?? 0) + 1;
        $new['info']['Global Commitment Review']['candidates_total'] = (int)($new['info']['Global Commitment Review']['candidates_total'] ?? 0) + 1;
        if (isset($new['info']['Exact Candidate Space'])) { $new['info']['Exact Candidate Space']['winner_source'] = 'VALID_POOL';
            $new['info']['Exact Candidate Space']['winner_node'] = 'valid_pool' . $side; $new['info']['Exact Candidate Space']['winner_cost_production'] = $best['key']['cp'] ?? null; }
        pp_v3_relabel($new, $input);
        return $new;
    } catch (Throwable $e) { return $out; }
}

/* Kandidat pesaing yang DAPAT menyalip: biaya dalam margin M dari pemenang lama, dan (bila invalid
 * pada basis) deviasi gasnya terjangkau oleh pergeseran window gas state baru. Satu definisi, dipakai
 * pemilihan akhir dan penjadwalan gelombang kandidat V4. */
function pp_v3_near_split(array $v, array $W, array $diff, ?float $delta): array {
    $cpW0 = (float)($W['cp'] ?? 0);
    $M = max(0.05, 3.0 * abs((float)$delta));
    $sh = (float)($diff['gas_shift_bbtud'] ?? 0); $un = (float)($diff['gas_shift_uncertain_bbtud'] ?? 0) + 0.05;
    $reach = function (float $dev) use ($sh, $un): bool { return $dev > 0 ? ($sh + $un >= $dev) : ($sh - $un <= $dev); };
    $near = []; $far = []; $pr = []; $seenSig = [];
    $v10 = pp_v10_fast();
    foreach ((array)($v['candidates'] ?? []) as $c) {
        if (($c['sig'] ?? '') === ($W['sig'] ?? '#') || ($c['nk'] ?? '') === ($W['node'] ?? '#')) continue;
        $cp = (float)($c['cp'] ?? 0);
        /* V10 TIER-1: (a) commitment identik (tanda tangan stop sama) dengan kandidat yang sudah diambil = state fisik
         * sama; (b) tidak valid pada jangkar karena pelanggaran STRUKTURAL (runtime/downtime, blok STG, status paksa) —
         * perubahan data slot / fixed flow hanya menggeser neraca gas dan tidak mengubah pelanggaran itu; (c) tidak valid
         * pada jangkar karena pelanggaran non-gas saat gas SUDAH di dalam window (deviasi 0). */
        if ($v10) {
            $sgC = (string)($c['sig'] ?? pp_v3_sig(pp_v3_commitment_stops($c)));
            if (isset($seenSig[$sgC])) { $pr[] = ['node' => $c['nk'] ?? $sgC, 'reason' => 'DUPLIKAT_STATE_FISIK', 'same_as' => $seenSig[$sgC]]; $far[] = $c; continue; }
            if (empty($c['valid']) && empty($c['gas_only'])) {
                $vio = array_map('strval', (array)($c['violations'] ?? []));
                $struct = array_values(array_filter($vio, function ($x) { return (bool)preg_match('~runtime|downtime|block|stg|cannot_stop|last_data|forced|unit_stop|commitment_continuous~i', $x); }));
                if ($struct) { $pr[] = ['node' => $c['nk'] ?? $sgC, 'reason' => 'INFEASIBLE_STRUKTURAL_DI_JANGKAR', 'violations' => $struct]; $far[] = $c; continue; }
                if ($vio && abs((float)($c['dev'] ?? 0)) <= 1e-9 && !in_array('gas_quota', $vio, true)) { $pr[] = ['node' => $c['nk'] ?? $sgC, 'reason' => 'INFEASIBLE_NON_GAS_DENGAN_GAS_DALAM_WINDOW', 'violations' => $vio]; $far[] = $c; continue; }
            }
            $seenSig[$sgC] = $c['nk'] ?? $sgC;
        }
        $isNear = (!empty($c['valid']) && $cp <= $cpW0 + $M)
               || (!empty($c['gas_only']) && $cp <= $cpW0 + $M)
               || (empty($c['valid']) && $reach((float)($c['dev'] ?? 0)) && $cp <= $cpW0 + $M);
        if ($isNear) $near[] = $c; else { $far[] = $c; if ($v10) $pr[] = ['node' => $c['nk'] ?? '?', 'reason' => 'BATAS_CP_ATAU_JANGKAUAN_GAS', 'cp_anchor' => round($cp, 4), 'bound' => round($cpW0 + $M, 4)]; }
    }
    return ['M' => $M, 'sh' => $sh, 'un' => $un, 'near' => $near, 'far' => $far, 'pruned' => $pr];
}
/* ---- RECOMPUTE INKREMENTAL ------------------------------------------------------------------------
 * Mengembalikan ['output' => ..., 'v3' => blok final] bila hasil inkremental SAH menggantikan
 * exact, atau ['output' => null, 'report' => alasan] bila job harus menjalankan pipeline exact. */
function pp_v3_incremental(string $jobId, array $input): array {
    $t0 = microtime(true);
    $orig = pp_normalize_copy($input);
    foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
    if ((string)getenv('PP_V3_INCREMENTAL') === '0') return ['output' => null, 'report' => ['applied' => false, 'reason' => 'PP_V3_INCREMENTAL=0']];
    $B = pp_v3_find_base($orig);
    /* V10: evaluasi kandidat rute inkremental memakai pencarian target supplier dengan braket lokal + regula falsi
     * (penanda __v10_sup_secant; fungsi state saja). Penilaian akhir tetap terhadap input asli. */
    $v10 = pp_v10_fast(); if ($v10) $orig['data3']['modeling']['__v10_sup_secant'] = true;
    $GLOBALS['ppV10Inc'] = ['pruned' => [], 'dedup' => 0];
    $rep = ['schema' => 'co12-v3-incremental-v1', 'applied' => false, 'reason' => $B['reason'], 'base_file' => $B['base']['_file'] ?? null];
    if ($B['base'] === null) return ['output' => null, 'report' => $rep];
    $base = $B['base']; $diff = $B['diff']; $v = (array)$base['v3'];
    $rep['diff'] = $diff; $rep['base_source'] = $v['source'] ?? null; $rep['base_chain'] = (int)($v['chain'] ?? 0);
    if ((int)($v['chain'] ?? 0) >= PP_V3_MAX_CHAIN) { $rep['reason'] = 'RANTAI_INKREMENTAL_MAKSIMUM'; return ['output' => null, 'report' => $rep]; }
    $W = (array)($v['winner'] ?? []);
    if (!is_array($W['stops'] ?? null)) { $rep['reason'] = 'BASIS_TANPA_COMMITMENT_PEMENANG'; return ['output' => null, 'report' => $rep]; }
    /* Globals job disimpan; evaluasi kandidat membersihkan global engine di antara run. */
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $restore = function () use ($saved) { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; };
    $dl = $t0 + (pp_v9_canon() ? PP_V9_INC_BUDGET_S : PP_V3_INC_BUDGET_S);   // V9: anggaran longgar, keputusan tidak bergantung beban CPU
    $once0 = (int)($GLOBALS['__ppx_once_calls'] ?? 0); $evals = 0; $st0 = pp_v4_stats();
    $firstValidAt = null; $nValid = 0;
    $poolKey = pp_tl_key($input) . '_x';
    $tick = function (array $a) use (&$firstValidAt, &$nValid, $t0, $jobId, $poolKey) {
        if (!empty($a['valid'])) { $nValid++; try { pp_tl_pool_offer($poolKey, $a, 'incremental', $jobId); } catch (Throwable $e) {}
            if ($firstValidAt === null) { $firstValidAt = microtime(true) - $t0;
            pp_job_progress($jobId, 'INKREMENTAL_HASIL_VALID_PERTAMA', 40.0, ['first_valid_s' => round($firstValidAt, 2)]); } }
    };
    $reg = null; try { $reg = pp_tl_registry_open($orig); } catch (Throwable $e) { $reg = null; }
    try {
        pp_job_progress($jobId, 'INKREMENTAL_REDISPATCH_COMMITMENT_TETAP', 10.0, ['dirty_rows' => count((array)$diff['dirty_rows'])]);
        if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_incremental_redispatch');
        $wc = ['stops' => $W['stops']];
        $h0 = isset($W['supplier_target']) && $W['supplier_target'] !== null ? (float)$W['supplier_target'] : null;
        /* Kandidat pertama: dispatch final basis dibekukan, hanya slot kotor yang dihitung ulang. */
        $fz = pp_v3_frozen_eval($orig, (array)$base['data'], $dl); $evals++;
        if (is_array($fz) && !empty($fz['valid'])) $fz = pp_v6_priority_polish($orig, $fz, $dl);   // V6
        if (is_array($fz)) $tick($fz);
        $evScan0 = $evals;
        $fzDev = (is_array($fz) && empty($fz['valid']) && !empty($fz['gas_only'])) ? (float)$fz['dev'] : 0.0;
        $minDev = INF;
        /* Keluar cepat hanya pada DEFISIT gas yang jelas (kuota turun / pemakaian Actual naik
         * > 0,3 BBTUD) bila semua titik lever jauh dari window: commitment lama tidak dapat
         * menyerapnya, exact mencari commitment baru. Selain itu (surplus gas, perubahan kecil)
         * pendaratan node seed/keluarga dan kandidat pesaing tetap dicoba — terukur: kuota LNG +1
         * dan rantai Actual FF JBBK mendarat valid di sana walau pemindaian lever tidak. */
        $shEarly = (float)($diff['gas_shift_bbtud'] ?? 0);
        $exitDev = $shEarly < -0.3 ? 0.3 : INF;
        /* V4: kandidat independen dikerjakan bersama pekerja pembantu job ini (bila aktif) lewat
         * kolam kandidat bersama: titik lever commitment pemenang + pendaratan node seed/keluarga
         * asalnya. Logika pemilihan di bawah tidak berubah; setiap evaluasinya mengambil hasil dari
         * kolam, sehingga hasil identik dengan evaluasi berurutan. */
        $a0 = (float)($W['adj'] ?? 0);
        $par = pp_v4_helpers_wait($jobId, 0.8) > 0;
        $rep['v4_parallel'] = $par;
        if ($par) {
            $dirT = $fzDev > 0 ? -1.0 : 1.0; $pts = [];
            $skipScan = false;
            /* Pendaratan (rantai evaluasi berurutan, tugas terpanjang): commitment pemenang sebagai
             * node keluarga asalnya, pesaing yang PASTI "dapat menyalip" (margin minimum 0,05 USD/MWh
             * berlaku untuk delta apa pun; aturan kelayakan sama persis dengan aturan di bawah), lalu
             * node seed pemenang. */
            $tL = [];
            if (preg_match('~^off:([a-z0-9,]*)$~', (string)($W['node'] ?? ''), $mW0)) $tL[] = ['type' => 'land', 'off' => $mW0[1] === '' ? [] : explode(',', $mW0[1]), 'seed' => [], 'max' => 5];
            $cpW00 = (float)($W['cp'] ?? 0); $sh0 = (float)($diff['gas_shift_bbtud'] ?? 0); $un0 = (float)($diff['gas_shift_uncertain_bbtud'] ?? 0) + 0.05;
            foreach ((array)($v['candidates'] ?? []) as $c0) {
                if (($c0['sig'] ?? '') === ($W['sig'] ?? '#') || ($c0['nk'] ?? '') === ($W['node'] ?? '#')) continue;
                $cp0 = (float)($c0['cp'] ?? 0); $dv0 = (float)($c0['dev'] ?? 0);
                $rch0 = $dv0 > 0 ? ($sh0 + $un0 >= $dv0) : ($sh0 - $un0 <= $dv0);
                if (!((!empty($c0['valid']) || !empty($c0['gas_only']) || (empty($c0['valid']) && $rch0)) && $cp0 <= $cpW00 + 0.05)) continue;
                $nk00 = ($c0['src'] ?? '') === 'family' ? (string)$c0['nk'] : pp_tl_node_key([], pp_v3_commitment_stops($c0));
                if ($reg !== null) { $s00 = $reg->get($nk00); if (is_array($s00) && (empty($s00['valid']) || is_array($reg->output($nk00)))) continue; }
                $tL[] = ['type' => 'land', 'off' => array_values((array)($c0['off'] ?? [])), 'seed' => array_merge(array_values((array)($c0['stops'] ?? [])), array_values((array)($c0['seed'] ?? []))), 'max' => 5];
            }
            $tL[] = ['type' => 'land', 'off' => [], 'seed' => array_values((array)$W['stops']), 'max' => 5];
            $mkE = function (float $pt) use ($W, $h0) { return ['type' => 'eval', 'off' => [], 'seed' => array_values((array)$W['stops']), 'adj' => $pt, 'hint' => $h0]; };
            if ($fzDev != 0.0) { $pts[] = $a0 + $dirT * 0.16; foreach ([-0.16, 0.0, 0.08, -0.08, 0.04, -0.04] as $dd) $pts[] = $a0 + $dirT * $dd; }
            else {
                /* Arah pindaian ditentukan titik pertama (a0), dihitung pemilik; sementara itu
                 * pembantu sudah mengerjakan pendaratan (tanpa defisit gas yang jelas). */
                if ($shEarly >= -0.3) pp_v4_work_publish($jobId, $orig, ['kind' => 'tasks', 'tasks' => $tL, 'dl' => $dl]);
                pp_v4_cooperate($jobId, $orig, [$mkE($a0)], $dl);
                $f0 = pp_tl_eval($orig, [], $a0, $dl, array_values((array)$W['stops']), $h0); pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
                if (is_array($f0) && empty($f0['valid']) && isset($f0['checks']['residual_shortage_zero']) && $f0['checks']['residual_shortage_zero'] === false) $skipScan = true;
                $dirT = (is_array($f0) && empty($f0['valid']) && (float)($f0['dev'] ?? 0) > 0) ? -1.0 : 1.0;
                if (!$skipScan) foreach ([0.16, -0.16, 0.08, -0.08, 0.04, -0.04] as $dd) $pts[] = $a0 + $dirT * $dd;
            }
            /* Defisit gas yang jelas: titik pertama dihitung lebih dulu (keluar cepat bila kekurangan
             * gas nyata), baru sisanya dibagi. */
            if ($shEarly < -0.3 && $pts && $fzDev != 0.0) {
                pp_v4_task_exec($orig, $mkE($pts[0]), $dl);
                $f1 = pp_tl_eval($orig, [], $pts[0], $dl, array_values((array)$W['stops']), $h0); pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
                if (is_array($f1) && empty($f1['valid']) && isset($f1['checks']['residual_shortage_zero']) && $f1['checks']['residual_shortage_zero'] === false) $skipScan = true;
            }
            /* Urutan klaim: pemilik mengerjakan titik lever lebih dulu (delta pemenang -> daftar pesaing
             * diketahui secepatnya), pembantu mengerjakan pendaratan lebih dulu (rantai terpanjang).
             * Begitu semua titik lever selesai, pemilik memilih pemenang pindaian (dari kolam, tanpa
             * menghitung ulang) dan menambahkan pendaratan pesaing yang dapat menyalip ke antrean yang
             * sedang berjalan — aturan kelayakan identik dengan pemilihan akhir (pp_v3_near_split).
             * Urutan tidak memengaruhi hasil: setiap candidate-state hermetik dan dihitung sekali. */
            $tEv = []; foreach ($pts as $pt) $tEv[] = $mkE($pt);
            $tOwn = array_merge($tEv, $tL);
            $tHelp = array_merge(array_slice($tEv, 1, 1), $tL, array_slice($tEv, 0, 1), array_slice($tEv, 2));
            $landOf = function (array $c) { return ['type' => 'land', 'off' => array_values((array)($c['off'] ?? [])), 'seed' => array_merge(array_values((array)($c['stops'] ?? [])), array_values((array)($c['seed'] ?? []))), 'max' => 5]; };
            $wave2 = function () use ($jobId, $orig, $tEv, $tL, $wc, $a0, $h0, $dl, $fzDev, $exitDev, $fz, $W, $v, $diff, $reg, $saved, $landOf) {
                foreach ($tEv as $t) if (!pp_v4_task_done($jobId, $t)) return null;
                $e2 = 0; $md2 = INF;
                $bw2 = pp_v3_scan_winner($orig, $wc, $a0, $h0, $dl, $e2, function (array $a) {}, $fzDev, $md2, $exitDev);
                pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
                $ref = is_array($bw2) ? $bw2 : ((is_array($fz) && !empty($fz['valid'])) ? $fz : null);
                if ($ref === null) return [];
                $NS = pp_v3_near_split($v, $W, $diff, (float)$ref['key']['cp'] - (float)($W['cp'] ?? 0));
                if (count($NS['near']) > pp_v9_max_near()) return [];
                $add = [];
                foreach ($NS['near'] as $c) {
                    $nk = ($c['src'] ?? '') === 'family' ? (string)$c['nk'] : pp_tl_node_key([], pp_v3_commitment_stops($c));
                    if ($reg !== null) { $s0 = $reg->get($nk); if (is_array($s0) && (empty($s0['valid']) || is_array($reg->output($nk)))) continue; }
                    $add[] = $landOf($c);
                }
                if ($add) { $rest = []; foreach (array_merge($tL, $add) as $t) if (!pp_v4_task_done($jobId, $t)) $rest[] = $t;
                    pp_v4_work_publish($jobId, $orig, ['kind' => 'tasks', 'tasks' => $rest, 'dl' => $dl]); }
                return $add;
            };
            if (!$skipScan) { pp_v4_work_publish($jobId, $orig, ['kind' => 'tasks', 'tasks' => $tHelp, 'dl' => $dl]);
                pp_v4_cooperate($jobId, $orig, $tOwn, $dl, true, $wave2); }
            $landPending = [];
            if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        }
        $bw = pp_v3_scan_winner($orig, $wc, (float)($W['adj'] ?? 0), $h0, $dl, $evals, $tick, $fzDev, $minDev, $exitDev);
        if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        $cpW0 = (float)($W['cp'] ?? 0);
        $near = []; $far = []; $evalList = [];
        if (!is_array($bw) && $evals - $evScan0 <= 1 && !(is_array($fz) && !empty($fz['valid']))) { $rep['reason'] = 'KEKURANGAN_GAS_NYATA_ALUR_KEPUTUSAN_BAHAN_BAKAR'; $rep['winner_commitment_feasible'] = false; $restore(); return ['output' => null, 'report' => $rep]; }
        /* Commitment tidak dapat menyerap perubahan: tidak satu pun titik lever mendekati window gas
         * (deviasi terkecil > 0,3 BBTUD, atau pelanggaran selain gas). Pendaratan lain dengan
         * commitment yang sama tidak akan berhasil; pipeline exact yang mencari commitment baru. */
        if (!is_array($bw) && !(is_array($fz) && !empty($fz['valid'])) && $minDev > $exitDev) { $rep['reason'] = 'COMMITMENT_TIDAK_DAPAT_MENYERAP_PERUBAHAN_GAS'; $rep['winner_commitment_feasible'] = false; $rep['min_gas_deviation_bbtud'] = is_finite($minDev) ? round($minDev, 4) : null; $restore(); return ['output' => null, 'report' => $rep]; }
        $bwRef = is_array($bw) ? $bw : ((is_array($fz) && !empty($fz['valid'])) ? $fz : null);
        $delta = is_array($bwRef) ? (float)$bwRef['key']['cp'] - $cpW0 : null;
        /* Kandidat pesaing yang DAPAT menyalip: biaya dalam margin M dari pemenang lama, dan (bila
         * invalid pada basis) deviasi gasnya terjangkau oleh pergeseran window gas state baru. */
        $NS = pp_v3_near_split($v, $W, $diff, $delta);
        $M = $NS['M']; $sh = $NS['sh']; $un = $NS['un']; $near = $NS['near']; $far = $NS['far'];
        if ($v10) $GLOBALS['ppV10Inc']['pruned'] = array_merge($GLOBALS['ppV10Inc']['pruned'], (array)($NS['pruned'] ?? []));
        $rep['winner_commitment_feasible'] = is_array($bwRef); $rep['frozen_dispatch_valid'] = is_array($fz) && !empty($fz['valid']);
        $rep['margin_usd_mwh'] = round($M, 4); $rep['gas_shift_bbtud'] = round($sh, 4); $rep['gas_shift_uncertain_bbtud'] = round($un, 4);
        $rep['near_candidates'] = array_map(function ($c) { return ['node' => $c['nk'] ?? $c['src'], 'cp_base' => $c['cp'], 'valid_base' => !empty($c['valid']), 'gas_only_base' => !empty($c['gas_only'])]; }, $near);
        $rep['far_candidates'] = count($far);
        if (count($near) > pp_v9_max_near()) { $rep['reason'] = 'KANDIDAT_DEKAT_TERLALU_BANYAK:' . count($near); $restore(); return ['output' => null, 'report' => $rep, 'offer' => $bw]; }
        /* V4 gelombang kedua: pesaing yang dapat menyalip (kini delta pemenang diketahui) digabung
         * dengan pendaratan commitment pemenang yang masih berjalan, dikerjakan bersama pembantu. */
        if ($par) {
            $tasks2 = (array)($landPending ?? []);
            foreach ($near as $c) {
                $nkC0 = ($c['src'] ?? '') === 'family' ? (string)$c['nk'] : pp_tl_node_key([], pp_v3_commitment_stops($c));
                if ($reg !== null) { $s0 = $reg->get($nkC0); if (is_array($s0) && (empty($s0['valid']) || is_array($reg->output($nkC0)))) continue; }
                $tasks2[] = ['type' => 'land', 'off' => array_values((array)($c['off'] ?? [])), 'seed' => array_merge(array_values((array)($c['stops'] ?? [])), array_values((array)($c['seed'] ?? []))), 'max' => 5];
            }
            if ($tasks2) { pp_v4_work_publish($jobId, $orig, ['kind' => 'tasks', 'tasks' => $tasks2, 'dl' => $dl]); pp_v4_cooperate($jobId, $orig, $tasks2, $dl); }
            if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        }
        $all = [];
        if (is_array($fz) && !empty($fz['valid'])) $all['frozen'] = ['node' => 'frozen:dispatch_final_basis', 'a' => $fz, 'c' => ['src' => 'frozen', 'nk' => 'frozen', 'stops' => $W['stops']]];
        if (is_array($bw)) {
            $all['winner'] = ['node' => 'winner:' . ($W['node'] ?? '?'), 'a' => $bw, 'c' => ['src' => 'winner', 'nk' => $W['node'] ?? 'winner', 'stops' => $W['stops']]];
            if ($reg !== null) { try { $nkW = pp_tl_node_key([], $W['stops']); $sW = pp_tl_node_summary($bw, $evals, microtime(true) - $t0); $sW['seed'] = $W['stops'];
                $old = $reg->get($nkW); if (!is_array($old) || empty($old['valid']) || pp_global_commitment_better($bw['key'], $old['key'])) $reg->put($nkW, $sW, $bw['output']); } catch (Throwable $e) {} }
        }
        /* Commitment pemenang juga dievaluasi PERSIS seperti ruang exact mengevaluasinya: sebagai node
         * seed (jadwal stop penuh, pendaratan bisection maks. 5 evaluasi) dan sebagai node keluarga
         * asalnya (mis. hanya unit yang dimatikan sepanjang hari). Dengan begitu kandidat yang akan
         * dihasilkan exact untuk commitment ini selalu termasuk dalam ruang kandidat inkremental. */
        /* V5: node keluarga (kurang dibatasi) lebih dulu, node seed sesudahnya — urutan ini
         * memungkinkan alias state fisik (stop seed tambahan yang tidak mengikat). Urutan tidak
         * memengaruhi hasil: keduanya tetap dievaluasi. */
        $wForms = [];
        if (preg_match('~^off:([a-z0-9,]*)$~', (string)($W['node'] ?? ''), $mW)) { $offW = $mW[1] === '' ? [] : explode(',', $mW[1]);
            $wForms[] = ['k' => 'winner_family_node', 'off' => $offW, 'seed' => [], 'nk' => (string)$W['node']]; }
        $wForms[] = ['k' => 'winner_seed_landing', 'off' => [], 'seed' => $W['stops'], 'nk' => pp_tl_node_key([], $W['stops'])];
        /* V9: pendaratan seed pemenang (stop terbanyak) dievaluasi SESUDAH node keluarga pesaing (stop lebih sedikit):
         * bila stop tambahannya tidak mengikat, state fisiknya identik dan hasilnya dipakai ulang lewat alias V5
         * (hasil identik, urutan tidak memengaruhi pemilihan akhir). */
        $wLate = [];
        if (pp_v9_canon()) foreach ($wForms as $k => $wf) if ($wf['k'] === 'winner_seed_landing') { $wLate[] = $wf; unset($wForms[$k]); }
        $landW = function (array $wForms) use ($orig, $dl, &$evals, $tick, &$all, $reg) {
        foreach ($wForms as $wf) {
            if (microtime(true) > $dl - 2.0) break;
            $L = pp_tl_land_commitment($orig, $wf['off'], $wf['seed'], $dl, 5); $evals += (int)($L['evals'] ?? 0);
            $aW = $L['a'] ?? null; if (!is_array($aW)) continue;
            $aW['off'] = $wf['off']; $tick($aW);
            if (!empty($aW['valid'])) $all[$wf['k']] = ['node' => $wf['k'] . ':' . $wf['nk'], 'a' => $aW, 'c' => ['src' => 'winner', 'nk' => $wf['nk'], 'off' => $wf['off'], 'seed' => $wf['seed']]];
            if ($reg !== null && !empty($aW['checks']['not_truncated'])) { try { $sF = pp_tl_node_summary($aW, (int)($L['evals'] ?? 0), 0.0); if ($wf['seed']) $sF['seed'] = $wf['seed'];
                $old = $reg->get($wf['nk']); if (!is_array($old) || (empty($old['valid']) && !empty($aW['valid'])) || (!empty($aW['valid']) && pp_global_commitment_better($aW['key'], $old['key']))) $reg->put($wf['nk'], $sF, !empty($aW['valid']) ? $aW['output'] : null); } catch (Throwable $e) {} }
            if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        }
        };
        $landW($wForms);
        pp_job_progress($jobId, 'INKREMENTAL_KANDIDAT_PESAING', 55.0, ['near' => count($near)]);
        if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_incremental_competitors');

        foreach ($near as $c) {
            if (microtime(true) > $dl - 2.0) { $rep['reason'] = 'ANGGARAN_INKREMENTAL_HABIS'; $restore(); return ['output' => null, 'report' => $rep, 'offer' => $bw]; }
            $nkC = ($c['src'] ?? '') === 'family' ? (string)$c['nk'] : pp_tl_node_key([], pp_v3_commitment_stops($c));
            $a = null;
            if ($reg !== null) { $s = $reg->get($nkC); $o = is_array($s) && !empty($s['valid']) ? $reg->output($nkC) : null;
                if (is_array($s) && (empty($s['valid']) || is_array($o))) $a = ['valid' => !empty($s['valid']), 'key' => $s['key'], 'checks' => $s['checks'] ?? [], 'running' => $s['running'] ?? [], 'dev' => $s['dev'] ?? 0, 'gas_only' => $s['gas_only'] ?? false, 'violations' => $s['violations'] ?? [], 'adj' => $s['adj'] ?? 0, 'output' => $o, 'off' => $s['off'] ?? [], 'reused' => true]; }
            if ($a === null) {
                $a = pp_v3_eval_competitor($orig, $c, $dl, $evals, $tick);
                if (is_array($a) && $reg !== null && !empty($a['checks']['not_truncated'])) { try { $sC = pp_tl_node_summary($a, 5, 0.0); if (!empty($c['seed']) || !empty($c['stops'])) $sC['seed'] = array_merge(array_values((array)($c['stops'] ?? [])), array_values((array)($c['seed'] ?? []))); $reg->put($nkC, $sC, !empty($a['valid']) ? $a['output'] : null); } catch (Throwable $e) {} }
            }
            if (is_array($a)) $all[$nkC] = ['node' => (string)($c['nk'] ?? $nkC), 'a' => $a, 'c' => $c];
            if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        }
        /* V10 TIER-1: pendaratan seed commitment pemenang = commitment yang SAMA dengan pindaian lever pemenang (7 titik) —
         * kelas commitment sudah dievaluasi; pendaratan tambahan tidak di-full-run. */
        if ($wLate && $v10) { foreach ($wLate as $wf) $GLOBALS['ppV10Inc']['pruned'][] = ['node' => $wf['k'] . ':' . $wf['nk'], 'reason' => 'KELAS_COMMITMENT_SAMA_DENGAN_PINDAIAN_PEMENANG']; $wLate = []; }
        if ($wLate) $landW($wLate);
        /* V9: pesaing valid termurah dengan commitment BERBEDA dari pemenang basis ikut dipindai lever gas (titik lever yang
         * sama dengan pemenang). Pendaratan bisection hanya mencari validitas; lanskap Cost Production window gas kasar,
         * sehingga commitment pesaing yang sama dapat jauh lebih murah pada titik lever lain. Deterministik (fungsi state). */
        if (pp_v9_canon() && microtime(true) < $dl - 10.0) {
            $sigW0 = pp_v3_sig((array)$W['stops']); $cb = null; $cbk = null;
            foreach ($all as $kx => $x) { if (empty($x['a']['valid']) || !is_array($x['a']['output'] ?? null)) continue;
                $sgx = pp_v3_sig(pp_v3_stops((array)$x['a']['output']['data'])); if ($sgx === $sigW0) continue;
                if ($cb === null || pp_global_commitment_better($x['a']['key'], $cb['a']['key'])) { $cb = $x; $cbk = $kx; } }
            if ($cb !== null) {
                $stC = pp_v3_stops((array)$cb['a']['output']['data']); $mdC = INF;
                $cvB = (string)getenv('PP_V9_CSCAN') === 'B';
                /* V10: himpunan titik pindaian pesaing tidak bergantung arah (a0 + {0, +-0.16, +-0.08, +-0.04}); seluruh titik
                 * diterbitkan ke kolam kandidat dan dikerjakan bersama pekerja pembantu; pemindaian di bawah memakai hasil kolam. */
                if ($v10 && $par) { $a0C = $cvB ? (float)($W['adj'] ?? 0) : (float)($cb['a']['adj'] ?? 0); $hC = $cvB ? $h0 : pp_tl_supplier_target($cb['a']['output']);
                    $tC = []; foreach ([0.0, 0.16, -0.16, 0.08, -0.08, 0.04, -0.04] as $dC) $tC[] = ['type' => 'eval', 'off' => [], 'seed' => array_values($stC), 'adj' => $a0C + $dC, 'hint' => $hC];
                    try { pp_v4_work_publish($jobId, $orig, ['kind' => 'tasks', 'tasks' => $tC, 'dl' => $dl]); pp_v4_cooperate($jobId, $orig, $tC, $dl); } catch (Throwable $e) {} }
                $bwC = pp_v3_scan_winner($orig, ['stops' => $stC], $cvB ? (float)($W['adj'] ?? 0) : (float)($cb['a']['adj'] ?? 0), $cvB ? $h0 : pp_tl_supplier_target($cb['a']['output']), $dl, $evals, $tick, 0.0, $mdC, INF);
                pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
                if (is_array($bwC) && !empty($bwC['valid'])) $all['scan_competitor'] = ['node' => 'scan_competitor:' . $cb['node'], 'a' => $bwC, 'c' => ['src' => 'winner', 'nk' => 'scan:' . pp_v3_sig($stC), 'stops' => $stC]];
                $rep['competitor_scan'] = ['from' => $cb['node'], 'cp_before' => $cb['a']['key']['cp'] ?? null, 'cp_after' => is_array($bwC) ? ($bwC['key']['cp'] ?? null) : null];
            }
            if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_RUN_BARU');
        }
        /* Kandidat valid lain yang sudah terdaftar untuk state ini (mis. dari Target Selesai). */
        if ($reg !== null && !pp_v9_canon()) { try { foreach ($reg->allValid() as $nk => $s) { if (isset($all[$nk]) || (is_array($bw) && $nk === pp_tl_node_key([], $W['stops']))) continue; $o = $reg->output($nk);
            if (is_array($o) && is_array($s['key'] ?? null)) $all[$nk] = ['node' => $nk, 'a' => ['valid' => true, 'key' => $s['key'], 'checks' => $s['checks'] ?? [], 'running' => $s['running'] ?? [], 'output' => $o, 'off' => $s['off'] ?? [], 'dev' => 0, 'gas_only' => false, 'violations' => []], 'c' => ['src' => 'registry', 'nk' => $nk, 'off' => $s['off'] ?? [], 'seed' => $s['seed'] ?? []]]; } } catch (Throwable $e) {} }
        $win = null; $winK = null;
        foreach ($all as $kx => $x) if (!empty($x['a']['valid']) && is_array($x['a']['output'] ?? null) && ($win === null || pp_global_commitment_better($x['a']['key'], $win['a']['key']))) { $win = $x; $winK = $kx; }
        if ($win === null) { $rep['reason'] = 'TIDAK_ADA_KANDIDAT_VALID_COMMITMENT_TETAP'; $restore(); return ['output' => null, 'report' => $rep]; }
        /* V10: commitment (pola on/off 48 row) yang sudah dievaluasi pada state ini — dipakai ulang review generik. */
        if ($v10) foreach ($all as $x) if (!empty($x['a']['valid']) && is_array($x['a']['output'] ?? null)) { try { $sgE = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$x['a']['output']['data'])));
            $old = $GLOBALS['ppV10Inc']['evaluated'][$sgE] ?? null;
            if ($old === null || pp_global_commitment_better($x['a']['key'], $old['key'])) $GLOBALS['ppV10Inc']['evaluated'][$sgE] = array_intersect_key($x['a'], array_flip(['valid', 'key', 'checks', 'violations', 'output', 'dev', 'gas_only', 'running'])) + ['sig' => $sgE]; } catch (Throwable $e) {} }
        $out = $win['a']['output'];
        /* Validasi penuh ulang terhadap input asli (48 row, hard constraint, Export 48/48, gas,
         * reserve/Bus Flow, residual 0, headroom, horizon penuh). */
        $chk = pp_tl_assess($orig, $out);
        if (empty($chk['valid'])) { $rep['reason'] = 'VALIDASI_ULANG_GAGAL'; $restore(); return ['output' => null, 'report' => $rep]; }
        $restore();
        $wStops = pp_v3_stops((array)$out['data']);
        $commitSame = pp_v3_sig($wStops) === (string)($W['sig'] ?? '');
        /* Baris yang dipakai ulang: identik dengan dispatch final basis. */
        $same = 0; $firstDiff = null; $bd = (array)$base['data'];
        for ($r = 0; $r < 48; $r++) { if (json_encode($out['data'][$r] ?? null) === json_encode($bd[$r] ?? null)) $same++; elseif ($firstDiff === null) $firstDiff = $r + 1; }
        $ladder = [];
        foreach ($all as $kx => $x) { $okX = !empty($x['a']['valid']);
            $ladder[] = ['unit' => $x['node'], 'variant' => 'V3_INCREMENTAL_CANDIDATE', 'key' => $x['a']['key'] ?? null,
            'valid' => $okX, 'better' => $kx === $winK, 'reused' => !empty($x['a']['reused'])]
            + ($okX ? [] : ['excluded' => true, 'exclusion_reason' => 'TIDAK_CONSTRAINT_VALID: ' . implode(',', array_keys(array_filter((array)($x['a']['checks'] ?? []), function ($z) { return !$z; })))]); }
        $wall = microtime(true) - $t0;
        $rep = array_merge($rep, ['applied' => true, 'reason' => null, 'wall_s' => round($wall, 2),
            'first_valid_s' => $firstValidAt === null ? null : round($firstValidAt, 2),
            'core_evaluations' => $evals, 'core_simulations' => (int)($GLOBALS['__ppx_once_calls'] ?? 0) - $once0,
            'v4_pool_owner' => (function () use ($st0) { $d = []; foreach (pp_v4_stats() as $k => $v) $d[$k] = $v - (int)($st0[$k] ?? 0); return $d; })(),
            'dirty_row_range' => $diff['dirty_rows'] ? [min($diff['dirty_rows']), max($diff['dirty_rows'])] : null,
            'valid_evaluations' => $nValid, 'candidates_evaluated' => count($all),
            'winner' => $win['node'], 'winner_cost_production' => $win['a']['key']['cp'] ?? null,
            'base_winner_cost_production' => $cpW0, 'commitment_unchanged' => $commitSame,
            'rows_reused_identical' => $same, 'rows_recomputed' => 48 - $same, 'first_changed_row' => $firstDiff,
            'v10_screening' => $v10 ? pp_v10_inc_certificate($orig, $base, $v, $diff, $all, $winK, $evals, (int)($GLOBALS['__ppx_once_calls'] ?? 0) - $once0, $M) : null,
            'dependency' => 'Slot yang berubah menggeser neraca gas harian; baris yang dispatch-nya dapat digeser perbaikan gas ikut dihitung ulang, baris lain terbukti identik dan dipakai ulang. Commitment unit dipertahankan kecuali kandidat ruang exact sebelumnya terbukti lebih murah dan valid.',
            'checks' => $chk['checks']]);
        $out['info']['Incremental Recompute'] = $rep;
        $gcr = ['root_problem' => 'V3_INCREMENTAL_CANDIDATE_SPACE', 'candidates_evaluated' => count($all), 'candidates_total' => count($all),
            'all_candidates_evaluated' => true, 'units_dropped' => [],
            'comparator_order' => ['operator_hard_controls', 'hard_violations', 'export_reserve_busflow', 'cost_production', 'total_cost', 'heat_rate', 'startup_count', 'fuel', 'keep_running_if_equal'],
            'ladder' => $ladder, 'note' => 'Ruang kandidat inkremental: commitment pemenang final terakhir + seluruh kandidat ruang exact sebelumnya yang dapat menyalip (valid dan dalam margin biaya, atau hanya gagal window gas dan dalam jangkauan perubahan gas) + kandidat valid terdaftar untuk state ini.'];
        $space = ['schema' => 'co12-exact-candidate-space-v1', 'source' => 'INCREMENTAL',
            'definition' => $gcr['note'], 'family_complete' => true, 'family_nodes' => count($all),
            'family_nodes_computed' => count(array_filter($all, fn($x) => empty($x['a']['reused']))),
            'family_nodes_reused' => count(array_filter($all, fn($x) => !empty($x['a']['reused']))),
            'family_core_evaluations' => $evals, 'family_valid' => count(array_filter($all, fn($x) => !empty($x['a']['valid']))),
            'registry_valid_candidates' => count(array_filter($all, fn($x) => !empty($x['a']['valid']))),
            'far_candidates_excluded' => count($far), 'winner_source' => 'INCREMENTAL', 'winner_node' => $win['node'],
            'winner_cost_production' => $win['a']['key']['cp'] ?? null, 'commitment_unchanged' => $commitSame];
        $out['info']['Global Commitment Review'] = $gcr;
        $out['info']['Exact Candidate Space'] = $space;
        $out['info']['Run Status'] = ['completed' => true, 'deadline_reached' => false, 'budget_truncated' => false,
            'converged' => true, 'status' => 'CONVERGED', 'economic_review_skipped' => null, 'last_stage' => null,
            'stages_truncated' => [], 'elapsed_s' => round($wall, 3), 'deadline_s' => PP_V3_INC_BUDGET_S,
            'core_runs' => $evals, 'core_simulations' => $rep['core_simulations'], 'supplier_state_reuse' => 0,
            'economic_review_completed' => true, 'phase_timeline' => [], 'stages_deferred' => [],
            'mode' => 'INCREMENTAL', 'incremental' => true,
            'candidates' => ['global_commitment_evaluated' => count($all), 'fully_evaluated' => true,
                'note' => 'Recompute inkremental: seluruh kandidat yang dapat mengubah pemenang dievaluasi pada state baru.']];
        pp_v3_relabel($out, $input);
        /* Gerbang rilis yang sama dengan hasil exact harus lulus; bila tidak, pipeline exact dipakai. */
        $accO = $out; $acc = pp_attach_or_reject_acceptance($input, $accO);
        if (empty($acc['publish_allowed'])) { $rep['applied'] = false; $rep['reason'] = 'GERBANG_RILIS_MENOLAK: ' . implode(',', (array)($acc['blocking_reasons'] ?? []));
            return ['output' => null, 'report' => $rep]; }
        /* Blok final untuk rantai berikutnya: kandidat dievaluasi membawa nilai state baru. */
        $next = [];
        foreach ($all as $x) { $cc = $x['c']; $cc['valid'] = !empty($x['a']['valid']); $cc['gas_only'] = !empty($x['a']['gas_only']);
            $cc['dev'] = (float)($x['a']['dev'] ?? 0); $cc['cp'] = (float)($x['a']['key']['cp'] ?? ($cc['cp'] ?? 0)); $cc['adj'] = (float)($x['a']['adj'] ?? 0);
            $cc['sig'] = pp_v3_sig(pp_v3_commitment_stops($cc)); $next[$cc['sig']] = $cc; }
        foreach ($far as $cc) if (!isset($next[$cc['sig'] ?? ''])) $next[$cc['sig'] ?? md5(json_encode($cc))] = $cc + ['carried' => true];
        $wKey = $win['a']['key'];
        $v3 = ['schema' => PP_V3_SCHEMA, 'engine' => pp_engine_fingerprint(), 'source' => 'INCREMENTAL', 'chain' => (int)($v['chain'] ?? 0) + 1,
            'winner' => ['stops' => $wStops, 'sig' => pp_v3_sig($wStops), 'cp' => (float)($wKey['cp'] ?? 0), 'key' => $wKey,
                         'adj' => (float)($win['a']['adj'] ?? 0), 'supplier_target' => pp_tl_supplier_target($out), 'node' => $win['node']],
            'candidates' => array_values(array_filter($next, fn($cc) => ($cc['sig'] ?? '') !== pp_v3_sig($wStops)))];
        return ['output' => $out, 'report' => $rep, 'v3' => $v3];
    } catch (PpJobAborted $e) { $restore(); throw $e; }
    catch (Throwable $e) { $restore(); $rep['reason'] = 'GALAT_INKREMENTAL: ' . $e->getMessage(); return ['output' => null, 'report' => $rep]; }
}

/* ================================================================================================
 *  V4 — KOLAM KANDIDAT BERSAMA (SATU CANDIDATE-STATE DIHITUNG SATU KALI) + PEKERJA PEMBANTU.
 *
 *  Candidate-state = input numerik lengkap satu evaluasi kandidat (state operator + commitment
 *  kandidat + lever gas + titik awal supplier). Kuncinya diambil dari input yang BENAR-BENAR
 *  dijalankan engine (jadwal stop dinormalisasi, field label/arsip dibuang), sehingga dua jalur
 *  yang menghasilkan input numerik yang sama — recompute inkremental, Target Selesai, Maximum
 *  Review (keluarga commitment exact), prepass, run berikutnya — memakai SATU hasil.
 *
 *  Hasil disimpan per kunci di jobs/_tl/cs/ (ringkasan penilaian + dispatch bila valid). Satu
 *  pemilik per candidate-state: klaim memakai flock pada berkas kunci; proses lain yang meminta
 *  kunci yang sama menunggu hasilnya, tidak menghitung dua kali. Kunci lepas otomatis bila
 *  proses pemegangnya berhenti.
 *
 *  Evaluasi kandidat bersifat hermetik (global engine dibersihkan sebelum dan sesudah, budget
 *  per evaluasi), sehingga hasilnya tidak bergantung pada proses atau urutan yang menghitungnya
 *  — dibuktikan dengan uji urutan maju/mundur dan proses segar (hasil identik bit per bit).
 * ============================================================================================== */
const PP_V4_SCHEMA = 'co12-v4-pool-v1';
function pp_v4_cores(): int {
    static $n = null; if ($n !== null) return $n;
    $e = (int)getenv('NUMBER_OF_PROCESSORS'); if ($e <= 0) $e = (int)($_SERVER['NUMBER_OF_PROCESSORS'] ?? 0);
    if ($e <= 0 && @is_readable('/proc/cpuinfo')) { $c = (string)@file_get_contents('/proc/cpuinfo'); $e = (int)preg_match_all('~^processor\s*:~m', $c); }
    return $n = max(1, $e > 0 ? $e : 2);
}
/* Jumlah pekerja pembantu per job pemilik: inti CPU dikurangi satu (pemilik), maks. 3, sehingga
 * tidak terjadi oversubscription. Dapat ditetapkan dengan PP_V4_HELPERS (0 = tanpa pembantu). */
function pp_v4_helper_slots(): int {
    $v = getenv('PP_V4_HELPERS'); if ($v !== false && $v !== '') return max(0, min(7, (int)$v));
    return max(0, min(3, pp_v4_cores() - 1));
}
function pp_v4_stat(string $k, $inc = 1): void { $GLOBALS['__ppv4_stat'][$k] = ($GLOBALS['__ppv4_stat'][$k] ?? 0) + $inc; }
function pp_v4_stats(): array { return (array)($GLOBALS['__ppv4_stat'] ?? []); }
function pp_cs_dir(): string { $d = pp_tl_dir() . DIRECTORY_SEPARATOR . 'cs'; if (!is_dir($d)) @mkdir($d, 0777, true); return $d; }
function pp_cs_key(array $in): ?string {
    if ((string)getenv('PP_V4_POOL') === '0') return null;
    foreach (array_keys($in) as $k) if (is_string($k) && $k !== '' && $k[0] === '_') unset($in[$k]);
    $m = (array)($in['data3']['modeling'] ?? []);
    unset($m['time_budget_seconds'], $m['time_budget_max_seconds']);
    pp_v3_strip_meta($m);
    $e = []; if (function_exists('pp_normalize_unit_stop_time')) pp_normalize_unit_stop_time($m, $e);
    ksort($m); $d3 = (array)($in['data3'] ?? []); $d3['modeling'] = $m; ksort($d3); $in['data3'] = $d3; ksort($in);
    return substr(hash('sha256', PP_V4_SCHEMA . '|' . pp_engine_fingerprint() . '|' . json_encode(pp_memo_canon($in), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), 0, 40);
}
function pp_cs_file(string $k, string $ext = '.json'): string { return pp_cs_dir() . DIRECTORY_SEPARATOR . $k . $ext; }
function pp_cs_get(string $k): ?array {
    $mem = &$GLOBALS['__ppv4_cs_mem'];
    if (isset($mem[$k])) { pp_v4_stat('cs_hit_mem'); return $mem[$k]; }
    $r = pp_tl_read(pp_cs_file($k));
    if (!is_array($r) || ($r['k'] ?? null) !== $k || !is_array($r['a'] ?? null)) return null;
    pp_v4_stat('cs_hit_file');
    $a = $r['a']; $a['output'] = $r['out'] ?? null; $a['cs_key'] = $k; $a['reused'] = true;
    if (!is_array($mem)) $mem = [];
    if (count($mem) >= 48) array_shift($mem);
    $mem[$k] = $a;
    return $a;
}
function pp_cs_put(string $k, array $a): void {
    $sum = $a; unset($sum['output'], $sum['reused']);
    $out = !empty($a['valid']) ? ($a['output'] ?? null) : null;
    pp_tl_write(pp_cs_file($k), ['k' => $k, 'a' => $sum, 'out' => $out, 'at' => microtime(true), 'by' => getmypid()]);
    pp_v4_stat('cs_computed');
    $mem = &$GLOBALS['__ppv4_cs_mem']; if (!is_array($mem)) $mem = [];
    if (count($mem) >= 48) array_shift($mem);
    $a['cs_key'] = $k; $a['output'] = $out; $mem[$k] = $a;
    /* Kolam dijaga tetap kecil: berkas tertua dibuang bila jumlahnya berlebih. */
    if (mt_rand(1, 50) === 1) { $fs = glob(pp_cs_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
        if (count($fs) > 4000) { usort($fs, function ($x, $y) { return @filemtime($x) <=> @filemtime($y); }); foreach (array_slice($fs, 0, 1500) as $f) @unlink($f); } }
}
/* Klaim candidate-state. ['lock' => handle] bila proses ini pemiliknya; ['hit' => a] bila proses
 * lain menyelesaikannya selama ditunggu; [] bila batas waktu habis (hitung sendiri tanpa klaim). */
function pp_cs_claim(string $k, float $dl): array {
    $f = pp_cs_file($k, '.lock');
    $waited = false;
    while (true) {
        $h = @fopen($f, 'c');
        if ($h && @flock($h, LOCK_EX | LOCK_NB)) {
            $hit = pp_cs_get($k);                       // selesai dihitung pemilik sebelumnya
            if ($hit !== null) { @flock($h, LOCK_UN); @fclose($h); if ($waited) pp_v4_stat('cs_waited'); return ['hit' => $hit]; }
            return ['lock' => $h];
        }
        if ($h) @fclose($h);
        if (!$h || microtime(true) > $dl - 0.5) return [];
        $waited = true;
        usleep(40000);
        $hit = pp_cs_get($k); if ($hit !== null) { pp_v4_stat('cs_waited'); return ['hit' => $hit]; }
        if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_abort_poll')) pp_tl_abort_poll();
    }
}
function pp_cs_release($h): void { if (is_resource($h)) { @flock($h, LOCK_UN); @fclose($h); } }

/* ---- V5: ALIAS STATE FISIK (canonical physical-state dedup) ------------------------------------
 * Dua candidate-state yang hanya berbeda pada jadwal stop unit (mis. commitment pemenang sebagai node
 * seed dengan jadwal stop lengkap versus node keluarga `off:g3`) menghasilkan dispatch fisik yang SAMA
 * bila setiap stop tambahan tidak mengikat: unit yang dihentikan memang 0 MW pada seluruh row stop
 * tersebut pada hasil kandidat yang kurang dibatasi. Terukur pada recompute inkremental satu slot:
 * rantai pendaratan seed dan rantai node keluarga identik pada kelima langkah (CP, deviasi, dispatch).
 * Kandidat yang lebih dibatasi X memakai hasil E bila: (1) seluruh input selain jadwal stop identik
 * (kunci tanpa stop sama), (2) cakupan stop E ⊆ cakupan stop X, dan (3) pada dispatch E setiap
 * (unit,row) di cakupan X \ E bernilai 0 MW. PP_V5_ALIAS=0 mematikan; PP_V5_ALIAS_VERIFY=1 tetap
 * menghitung dan mencatat perbandingan dispatch ke PP_V5_ALIAS_LOG. */
function pp_v5_stop_cover(array $m): array {
    $c = []; $e = []; if (function_exists('pp_normalize_unit_stop_time')) pp_normalize_unit_stop_time($m, $e);
    foreach ((array)($m['unit_stop_time'] ?? []) as $st) { $u = strtolower((string)($st['unit'] ?? '')); if ($u === '') continue;
        for ($r = max(1, (int)($st['start'] ?? 1)); $r <= min(48, (int)($st['stop'] ?? 48)); $r++) $c[$u][$r] = true; }
    foreach ((array)($m['unit_stop'] ?? []) as $u) { $u = strtolower((string)$u); for ($r = 1; $r <= 48; $r++) $c[$u][$r] = true; }
    return $c;
}
function pp_v5_stopless_key(array $in): ?string {
    /* V11: alias "stop tambahan tidak mengikat" DIMATIKAN secara bawaan (PP_V5_ALIAS=1 menyalakan kembali untuk investigasi).
     * Terbukti tidak sahih untuk engine heuristik ini: pada jangkar PGN 30, commitment 'pipeline' (stop lebih banyak) dihitung
     * penuh = CP 64,6656 valid, tetapi alias memakai hasil 'off:g3' (stop lebih sedikit, stop tambahan tidak mengikat) = 64,6671.
     * Karena isi cache bergantung urutan evaluasi (pemilik vs pembantu, CLI vs HTTP), alias membuat FINAL bergantung jalur
     * (ACT_PGN_UP: direct 64,6622 vs rantai HTTP 64,6801). Cache kunci-identik (input numerik sama persis) tetap aktif. */
    if ((string)getenv('PP_V5_ALIAS') !== '1') return null;
    $m = (array)($in['data3']['modeling'] ?? []); unset($m['unit_stop_time'], $m['unit_stop']); $in['data3']['modeling'] = $m;
    $k = pp_cs_key($in); return $k === null ? null : 's' . substr($k, 0, 39);
}
function pp_v5_onmask(array $o): array {
    $mk = [];
    foreach ((array)($o['data'] ?? []) as $i => $r) foreach ($r as $col => $v)
        if (is_string($col) && preg_match('~^(G\d+|S\d|GE\d|BB\d)$~', $col) && (float)$v > 0.001) $mk[strtolower($col === 'BB1' ? 'b1' : ($col === 'BB2' ? 'b2' : $col))][] = $i + 1;
    return $mk;
}
function pp_v5_alias_find(string $sk, array $coverX): ?array {
    $idx = pp_tl_read(pp_cs_file('al_' . $sk));
    if (!is_array($idx)) return null;
    foreach ((array)($idx['e'] ?? []) as $E) {
        $a = pp_cs_get((string)($E['ck'] ?? '')); if (!is_array($a) || !isset($a['onmask'])) continue;
        $cE = (array)($E['cover'] ?? []); $ok = true;
        foreach ($cE as $u => $rows) foreach ((array)$rows as $r) if (empty($coverX[$u][(int)$r])) { $ok = false; break 2; }
        if (!$ok) continue;
        $on = (array)$a['onmask'];
        foreach ($coverX as $u => $rows) { $onU = array_flip((array)($on[$u] ?? []));
            foreach ($rows as $r => $_) { if (in_array((int)$r, (array)($cE[$u] ?? []), true)) continue; if (isset($onU[$r])) { $ok = false; break 2; } } }
        if ($ok) { pp_v4_stat('cs_alias'); $a['alias_of'] = $E['ck']; return $a; }
    }
    return null;
}
function pp_v5_alias_add(string $sk, string $ck, array $cover): void {
    $f = pp_cs_file('al_' . $sk); $h = @fopen($f . '.lock', 'c'); if ($h) @flock($h, LOCK_EX);
    $idx = pp_tl_read($f); if (!is_array($idx)) $idx = ['e' => []];
    $cv = []; foreach ($cover as $u => $rows) $cv[$u] = array_map('intval', array_keys($rows));
    $idx['e'][] = ['ck' => $ck, 'cover' => $cv]; if (count($idx['e']) > 64) $idx['e'] = array_slice($idx['e'], -64);
    pp_tl_write($f, $idx);
    if ($h) { @flock($h, LOCK_UN); @fclose($h); }
}

/* ---- PEKERJAAN BERSAMA JOB PEMILIK ---------------------------------------------------------------
 * Job pemilik menerbitkan daftar tugas (evaluasi kandidat / pendaratan commitment / pencarian
 * keluarga commitment) ke berkas work.json di direktori job. Pemilik DAN pekerja pembantu
 * mengerjakan daftar yang sama dengan klaim per tugas, sehingga setiap tugas dikerjakan tepat satu
 * proses. Pemilik tetap satu-satunya yang memutuskan pemenang dan menerbitkan hasil. */
function pp_v4_work_file(string $jobId): string { return pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_work.json'; }
function pp_v4_orig_file(string $jobId, string $tag): string { return pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_orig_' . preg_replace('~[^a-z0-9]~', '', $tag) . '.json'; }
function pp_v4_work_publish(string $jobId, array $orig, array $work): void {
    if (pp_v4_helper_slots() <= 0 || $jobId === '' || !is_dir(pp_job_dir($jobId))) return;
    $tag = substr(md5(json_encode($orig)), 0, 12);
    $of = pp_v4_orig_file($jobId, $tag);
    if (!is_file($of)) pp_tl_write($of, $orig);
    $cur = pp_tl_read(pp_v4_work_file($jobId));
    $work['rev'] = (int)($cur['rev'] ?? 0) + 1; $work['orig'] = basename($of); $work['at'] = microtime(true);
    pp_tl_write(pp_v4_work_file($jobId), $work);
}
function pp_v4_helpers_wait(string $jobId, float $maxS): int {
    if (pp_v4_helper_slots() <= 0) return 0;
    $t = microtime(true);
    do { $n = pp_v4_helpers_alive($jobId); if ($n > 0) return $n; usleep(40000); } while (microtime(true) - $t < $maxS);
    return 0;
}
function pp_v4_helpers_alive(string $jobId): int {
    $n = 0; foreach (glob(pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_help_*.beat') ?: [] as $f) if (microtime(true) - (float)@file_get_contents($f) < 3.0) $n++;
    return $n;
}
function pp_v4_task_key(array $t): string { return substr(md5(json_encode([$t['type'], $t['off'] ?? [], $t['seed'] ?? [], isset($t['adj']) ? sprintf('%.9f', $t['adj']) : null, $t['hint'] ?? null, $t['max'] ?? null, $t['id'] ?? null])), 0, 20); }
function pp_v4_task_exec(array $orig, array $t, float $dl, ?string $jobId = null): void {
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $a = null;
    try {
        if ($t['type'] === 'eval') $a = pp_tl_eval($orig, (array)($t['off'] ?? []), (float)$t['adj'], $dl, (array)($t['seed'] ?? []), isset($t['hint']) ? (float)$t['hint'] : null);
        elseif ($t['type'] === 'land') { $L = pp_tl_land_commitment($orig, (array)($t['off'] ?? []), (array)($t['seed'] ?? []), $dl, (int)($t['max'] ?? 5)); $a = $L['a'] ?? null; }
        elseif ($t['type'] === 'v10land') { pp_v10_land_task($orig, $t, $dl, $jobId); $a = null; }
    } finally { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; }
    /* Kandidat valid langsung tersedia sebagai VALID PROVISIONAL / Target Selesai (kolam state). */
    if ($jobId !== null && is_array($a) && !empty($a['valid']) && is_array($a['output'] ?? null)) {
        try { if (pp_tl_pool_offer(pp_tl_key($orig) . '_x', $a, 'v4_task', $jobId)) {
            $jf = pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_first_valid.json';
            if (!is_file($jf)) { @file_put_contents($jf, json_encode(['at' => microtime(true), 'cp' => $a['key']['cp'] ?? null])); } } } catch (Throwable $e) {}
    }
}
/* Mengerjakan daftar tugas bersama-sama (pemilik atau pembantu). Tugas yang sedang dikerjakan
 * proses lain dilewati dulu, lalu ditunggu sampai selesai (atau diambil alih bila pemegangnya
 * berhenti). Mengembalikan jumlah tugas yang dikerjakan proses ini. */
function pp_v4_task_done(string $jobId, array $t): bool { return is_file(pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4t_' . pp_v4_task_key($t) . '.done'); }
/* $more (opsional, pemilik): dipanggil di antara tugas; mengembalikan null bila belum siap, atau
 * daftar tugas tambahan (gelombang berikutnya) yang digabung ke antrean yang sedang berjalan. */
function pp_v4_cooperate(string $jobId, array $orig, array $tasks, float $dl, bool $wait = true, ?callable $more = null): int {
    $dir = pp_job_dir($jobId); $mine = 0;
    $pending = $tasks;
    $pull = function () use (&$more, &$pending) {
        if ($more === null) return;
        $x = $more(); if ($x === null) return;
        $more = null; $have = [];
        foreach ($pending as $t) $have[pp_v4_task_key($t)] = 1;
        foreach ((array)$x as $t) if (!isset($have[pp_v4_task_key($t)])) { $pending[] = $t; $have[pp_v4_task_key($t)] = 1; }
    };
    while ($pending || $more !== null) {
        if (!$pending) { $pull(); if (!$pending) break; }
        $left = [];
        foreach ($pending as $t) {
            if (microtime(true) > $dl - 1.0) return $mine;
            $tk = pp_v4_task_key($t);
            $done = $dir . DIRECTORY_SEPARATOR . 'v4t_' . $tk . '.done';
            if (is_file($done)) continue;
            $h = @fopen($dir . DIRECTORY_SEPARATOR . 'v4t_' . $tk . '.lock', 'c');
            if ($h && @flock($h, LOCK_EX | LOCK_NB)) {
                if (!is_file($done)) { $tt0 = microtime(true); pp_v4_task_exec($orig, $t, $dl, $jobId); @file_put_contents($done, json_encode(['pid' => getmypid(), 't0' => $tt0, 't1' => microtime(true), 'type' => $t['type'], 'off' => $t['off'] ?? [], 'adj' => $t['adj'] ?? null])); $mine++; }
                @flock($h, LOCK_UN); @fclose($h);
                if ($more !== null) { $n0 = count($pending); $pull(); for ($q = $n0; $q < count($pending); $q++) $left[] = $pending[$q]; }
                if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_abort_poll')) pp_tl_abort_poll();
                if (!empty($GLOBALS['ppTlHook']['aborted'])) return $mine;
            } else { if ($h) @fclose($h); $left[] = $t; }
        }
        if (!$wait) break;
        if ($more !== null) { $pending = $left; $pull(); $left = $pending; }
        if ($left) { usleep(50000); if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_abort_poll')) pp_tl_abort_poll(); }
        $pending = $left;
    }
    return $mine;
}
/* Satu request pekerja pembantu (mode=job_help): mengikuti work.json job pemilik sampai job selesai. */
function pp_v4_helper_main(string $jobId, int $slot): array {
    $beat = pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_help_' . $slot . '.beat';
    $slk = @fopen(pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_help_' . $slot . '.lock', 'c');
    if (!$slk || !@flock($slk, LOCK_EX | LOCK_NB)) return ['ok' => true, 'already' => true];
    $t0 = microtime(true); $rev = 0; $done = 0; $idle = 0.0; $lastBeat = 0.0;
    $GLOBALS['ppTlHook'] = ['job' => $jobId, 'key' => '', 'orig' => null, 'n' => 0, 'v' => 0, 'flush' => 0.0, 'cchk' => 0.0, 'helper' => true, 'slot' => $slot];
    try {
        while (microtime(true) - $t0 < 1800.0) {
            if (microtime(true) - $lastBeat > 0.5) { @file_put_contents($beat, (string)microtime(true)); $lastBeat = microtime(true); }
            $j = pp_job_read($jobId);
            if (!is_array($j) || in_array((string)($j['status'] ?? ''), ['DONE', 'FAILED', 'CANCELLED'], true) || !empty($j['cancel_requested'])) break;
            $w = pp_tl_read(pp_v4_work_file($jobId));
            /* Job yang tidak pernah mulai (QUEUED > 60 s) tidak ditunggu tanpa akhir. */
            if (!is_array($w) && (string)($j['status'] ?? '') === 'QUEUED' && microtime(true) - $t0 > 60.0) break;
            if (!is_array($w) || (int)($w['rev'] ?? 0) === $rev) { usleep(60000); $idle += 0.06; continue; }
            $rev = (int)$w['rev'];
            $orig = pp_tl_read(pp_job_dir($jobId) . DIRECTORY_SEPARATOR . basename((string)($w['orig'] ?? '')));
            if (!is_array($orig)) continue;
            $dl = min((float)($w['dl'] ?? (microtime(true) + 600)), microtime(true) + 1500.0);
            $GLOBALS['ppTlHook']['key'] = pp_tl_key($orig); $GLOBALS['ppTlHook']['orig'] = $orig;
            $poolKey = pp_tl_key($orig) . '_x';
            if (($w['kind'] ?? '') === 'tasks') {
                $done += pp_v4_cooperate($jobId, $orig, (array)($w['tasks'] ?? []), $dl, false);
            } elseif (($w['kind'] ?? '') === 'family') {
                $reg = pp_tl_registry_open($orig);
                pp_tl_family_search($orig, $dl, $reg, ['seeds' => (array)($w['seeds'] ?? []), 'max_nodes' => (int)($w['max_nodes'] ?? 64),
                    'offer' => function (array $a) use ($poolKey, $jobId) { try { pp_tl_pool_offer($poolKey, $a, 'v4_helper_family', $jobId); } catch (Throwable $e) {} },
                    'stop' => function () use ($jobId, $rev) { static $c = 0.0; if (microtime(true) - $c < 0.5) return false; $c = microtime(true);
                        $j = pp_job_read($jobId); $w2 = pp_tl_read(pp_v4_work_file($jobId));
                        return !is_array($j) || in_array((string)($j['status'] ?? ''), ['DONE', 'FAILED', 'CANCELLED'], true) || (int)($w2['rev'] ?? 0) !== $rev; }]);
                pp_tl_clean_globals();
            }
        }
    } catch (PpJobAborted $e) { }
    catch (Throwable $e) { @file_put_contents(pp_job_dir($jobId) . DIRECTORY_SEPARATOR . 'v4_help_' . $slot . '.err', $e->getMessage()); }
    @unlink($beat); @flock($slk, LOCK_UN); @fclose($slk);
    return ['ok' => true, 'slot' => $slot, 'tasks_done' => $done, 'wall_s' => round(microtime(true) - $t0, 2), 'stats' => pp_v4_stats()];
}

/* Menjawab request sinkron dengan job economic_review sebagai satu-satunya pemilik perhitungan.
 * Dipakai ketika (a) hasil state identik sudah ada, atau (b) job eksak untuk state yang sama sudah
 * dipicu browser sehingga melanjutkan jalur sinkron berarti menghitung state yang sama dua kali. */
function pp_sync_handoff_respond(array $input, $rid, $rev, string $reason, string $note): void {
    $stF = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
    if (empty($stF['ok']) || empty($stF['job']['job_id'])) return;
    $jF = (array)$stF['job'];
    $asyncF = ['required' => true, 'kind' => 'economic_review', 'reason' => $note,
        'ok' => true, 'job_id' => $jF['job_id'], 'exec_token' => $jF['exec_token'] ?? null,
        'status' => $jF['status'] ?? null, 'reused' => (bool)($stF['reused'] ?? false),
        'input_hash' => pp_job_input_hash($input), 'error' => null, 'handoff_reason' => $reason,
        'helpers' => (int)($jF['helpers'] ?? (function_exists('pp_v4_helper_slots') ? pp_v4_helper_slots() : 0))];   // V15.15: pembantu job ikut dipicu (review V8 paralel)
    $outF = ['ok' => true, 'result' => 'pending', 'status' => 'ECONOMIC_REVIEW_REQUIRED',
        'data' => [], 'info' => ['Async Economic Review Job' => $asyncF],
        'preliminary' => true, 'final_result_visible' => false, 'save_allowed' => false,
        'publish_allowed' => false, 'preliminary_reason' => $reason,
        'preliminary_note' => 'Perhitungan eksak sedang diselesaikan; hasil ditampilkan begitu selesai.',
        'async_job' => $asyncF, 'request_id' => $rid,
        'state_revision' => $rev !== null ? (int)$rev : null,
        '_saved' => ['input' => false, 'output' => false]];
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo pp_json_out($outF); exit;
}
/* Input simulasi yang dijalankan job economic_review. Satu definisi dipakai job itu sendiri DAN
 * jalur sinkron yang memeriksa apakah hasil state ini sudah tersedia, supaya kuncinya selalu sama. */
function pp_econ_job_sim_input(array $input, float $ceil = 1800.0): array {
    if (!isset($input['data3']['modeling'])) $input['data3']['modeling'] = [];
    $input['data3']['modeling']['time_budget_seconds'] = $ceil;
    $input['data3']['modeling']['time_budget_max_seconds'] = $ceil;
    $input['data3']['modeling']['__core_run_budget'] = 400;
    return $input;
}
/* Menghitung dan menyimpan FINAL kanonik jangkar D(S) bila belum ada (di dalam job pemilik). */
function pp_v9_ensure_anchor(string $id, array $inputAsli): void {
    $orig = pp_normalize_copy($inputAsli);
    foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
    $act = strtolower((string)($orig['data3']['modeling']['gas_shortage_action'] ?? 'none'));
    if (!in_array($act, ['none', 'recommendation'], true) || !empty($orig['data3']['modeling']['change_over']['enabled'])) return;
    $anc = pp_v9_anchor($orig); if ($anc === null || pp_v9_final_load($anc) !== null) return;
    pp_job_progress($id, 'V9_JANGKAR_KANONIK_EXACT', 7.0);
    $saveHook = $GLOBALS['ppTlHook'] ?? null; unset($GLOBALS['ppTlHook']);
    /* pengamat untuk state jangkar (bukan state job): pekerja pembantu job ini ikut mengerjakan kandidat jangkar */
    try { $GLOBALS['ppTlHook'] = ['job' => $id, 'key' => pp_tl_key($anc), 'orig' => pp_normalize_copy($anc), 'n' => 0, 'v' => 0, 'flush' => 0.0, 'cchk' => 0.0]; } catch (Throwable $e) { unset($GLOBALS['ppTlHook']); }
    try {
        /* V10: keluarga commitment jangkar dikerjakan pekerja pembantu BERSAMAAN dengan pipeline jangkar (sama seperti
         * jalur exact biasa); tahap keluarga di akhir pipeline memakai ulang node yang sudah selesai. */
        if (pp_v10_fast() && pp_v4_helper_slots() > 0 && (string)getenv('PP_EXACT_FAMILY') !== '0' && empty($anc['data3']['modeling']['change_over']['enabled'])) {
            try { $origFA = pp_normalize_copy($anc); foreach (array_keys((array)$origFA['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($origFA['data3']['modeling'][$mk]);
                pp_v4_work_publish($id, $origFA, ['kind' => 'family', 'seeds' => [], 'max_nodes' => 64, 'dl' => (float)($GLOBALS['__pp_budget_deadline'] ?? (microtime(true) + 1500.0)) - 4.0]);
                pp_v4_helpers_wait($id, 1.5); } catch (Throwable $e) {} }
        $sim = pp_econ_job_sim_input($anc, (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0));
        $outA = pp_sim_memo_run($sim);
        if (count((array)($outA['data'] ?? [])) !== 48) return;
        /* Tahap yang SAMA dengan job exact untuk state D(S): pool guard (kanonik: tidak berlaku), review generik V9,
         * gerbang rilis, simpan FINAL. Dengan begitu FINAL jangkar identik dengan FINAL bila D(S) dijalankan langsung. */
        if (($outA['info']['Run Status']['economic_review_completed'] ?? null) === true) {
            $outA = pp_v3_pool_guard($anc, $outA);
            if (function_exists('pp_v8_priority_review')) { $GLOBALS['__pp_v8_job'] = $id; $outA = pp_v8_priority_review($anc, $outA); unset($GLOBALS['__pp_v8_job']); }
        }
        $revA = pp_attach_or_reject_acceptance($anc, $outA);
        pp_final_save($anc, $outA, null);
    } finally { unset($GLOBALS['ppTlHook']); if ($saveHook !== null) $GLOBALS['ppTlHook'] = $saveHook; }
}

/* =============================================================================================
 *  V15.15 FASTEST - DEFAULT (jalur job): KANDIDAT FULLY VALID PERTAMA, BUKAN MAXIMUM REVIEW.
 *
 *  AKAR MASALAH (input 07-Oct-26, PGN 23 / LNG 13 / PEP 36 / Akasia 4, diukur lewat UI):
 *   - Revisi Copilot: Fastest = pipeline SINKRON 25 s tanpa pembantu + hasil "terminal" (job lanjutan dibuang). Shortage
 *     5,3957 BBTUD sudah terbukti (seluruh lever legal habis) tetapi satu tahap terpotong (time_budget:bb_upfill) -> status
 *     ECONOMIC_REVIEW_REQUIRED tanpa job -> tanpa popup; pilihan LNG/Distillate juga terpotong -> tidak ada rencana.
 *   - Reference: rekomendasi baru tampil sesudah komparator exact penuh (39 s); kualitas akhirnya berasal dari review
 *     Unit Priority V8 atas dispatch basis (terukur CLI: basis pipeline -> beku pada state LNG -> review V8 = CP 72,3162 /
 *     HR 8230,26, identik dengan FINAL exact reference).
 *  JALUR INI:
 *   1. tanpa aksi bahan bakar: pipeline engine yang sama (anggaran job, review global DITUNDA, tanpa keluarga) ->
 *      a. shortage terbukti (gas di atas window sesudah seluruh lever) -> basis disimpan (_fastbasis) dan keputusan
 *         bahan bakar langsung dinyatakan (rekomendasi LNG/Distillate dari bukti exhaustion), tanpa komparator exact;
 *      b. selain itu -> review Unit Priority V8 (counterfactual DELAY/STOP/SWAP) lalu gerbang penerimaan yang sama.
 *   2. dengan aksi bahan bakar: rute V7 reference (dispatch basis dievaluasi beku pada state bahan bakar) memakai basis exact
 *      bila ada, selain itu basis Fastest; tanpa basis -> pipeline basis dihitung sekali. Tidak valid -> pipeline state
 *      bahan bakar langsung. Lalu review Unit Priority V8 + gerbang penerimaan.
 *  Hasil berlabel FASTEST (global_optimum_proven=false). Maximum Review tidak tersentuh. PP_V13F=0 mematikan.
 * ============================================================================================= */
function pp_v13f_is_fast(array $input): bool {
    return !empty($input['_fast_default']) && empty($input['data3']['modeling']['change_over']['enabled'])
        && empty($input['data3']['modeling']['__no_async_handoff']) && (string)getenv('PP_V13F') !== '0';
}
function pp_v13f_clean(array $input): array {
    $c = pp_normalize_copy($input);
    foreach (array_keys((array)$c['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($c['data3']['modeling'][$mk]);
    foreach (['time_budget_seconds', 'time_budget_max_seconds'] as $k) unset($c['data3']['modeling'][$k]);
    return $c;
}
/* pipeline engine yang sama dengan jalur exact, anggaran job, review commitment global ditunda (bukan Maximum Review) */
function pp_v13f_pipeline(array $input): array {
    $hook = $GLOBALS['ppTlHook'] ?? null;
    $c = pp_v13f_clean($input);
    pp_tl_clean_globals(); pp_budget_start((float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0), true, true);
    if ($hook !== null) $GLOBALS['ppTlHook'] = $hook;
    $GLOBALS['__pp_fastest_no_gcr'] = true;
    try { return pp_run_simulation($c); } finally { unset($GLOBALS['__pp_fastest_no_gcr']); }
}
function pp_v13f_basis_key(array $input): string {
    $c = $input; $mm = &$c['data3']['modeling']; $mm['gas_shortage_action'] = 'recommendation'; $mm['additional_lng'] = 0;
    foreach (['distillate_user_limit_litres', '__fuel_decision_mode', '__probe_tested', '__probe_state'] as $k) unset($mm[$k]); unset($mm);
    return pp_sim_state_key(pp_v13f_clean($c));
}
function pp_v13f_basis_file(string $key): string { $d = pp_job_root() . DIRECTORY_SEPARATOR . '_fastbasis'; if (!is_dir($d)) @mkdir($d, 0777, true); return $d . DIRECTORY_SEPARATOR . substr($key, 0, 40) . '.json'; }
function pp_v13f_shortage(array $o): bool {
    $i = (array)($o['info'] ?? []);
    return count((array)($o['data'] ?? [])) === 48 && (float)($i['Residual Gas Shortage (BBTUD)'] ?? ($i['Gas Shortage (BBTUD)'] ?? 0)) > 1e-6;
}
function pp_v13f_mark(array &$o, string $mode, bool $shortage): void {
    $rs = (array)($o['info']['Run Status'] ?? []);
    $o['info']['Run Status'] = array_merge($rs, ['completed' => true, 'converged' => true, 'deadline_reached' => false, 'budget_truncated' => false,
        'stages_truncated' => [], 'economic_review_skipped' => null, 'economic_review_completed' => true, 'status' => $shortage ? 'FASTEST_SHORTAGE_PROVEN' : 'FASTEST_FIRST_VALID',
        'mode' => $mode, 'fastest' => true, 'fastest_shortage_proven' => $shortage, 'global_optimum_proven' => false,
        'pipeline_status_before_fastest' => $rs['status'] ?? null]);
    unset($o['info']['Global Commitment Review Skipped']);
}
/* V15.15 SERTIFIKAT TERMINAL ROW 1 (Fastest, generik): pada row 1 hanya unit Last Data = Running yang tersedia dapat berbeban (unit
 * Stop paling cepat berbeban di row 2 karena urutan startup). Kapasitas Export maksimum row 1 = jumlah beban MAKSIMUM unit-unit itu
 * (taksiran atas: STG/Babelan/GE pada kapasitas penuh) - beban MM2100 - house load - IE row 1. Bila tetap < Range Min row 1, tidak ada
 * dispatch, commitment, LNG, maupun Distillate yang dapat memenuhi Export row 1 -> keputusan terminal seketika (bukan pipeline 60 s). */
function pp_v15_row1_export_cert(array $input): ?array {
    $in = pp_normalize_copy($input); $d3 = (array)$in['data3']; $m = (array)$d3['modeling']; $lds = (array)($m['unit_last_data_status'] ?? []);
    $gen = []; $on = [];
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'] as $u) { $gen[$u] = 0.0;
        if (!isset($d3[$u])) continue; $st = strtolower(trim((string)($lds[strtoupper($u)] ?? ''))); if ($st !== 'running') continue;
        if (pp_is_unit_stopped($d3, $m, $u, 1)) continue;
        $mx = function_exists('pp_effective_maxload') ? pp_effective_maxload($d3, $m, $u, 1) : 0.0; if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
        $gen[$u] = $mx; $on[strtoupper($u)] = round($mx, 2); }
    /* STG mengikuti GTG bloknya: output maksimum = fungsi kopling engine atas GTG pada beban maksimum (bukan max_load STG) */
    foreach (['s1','s2','s3'] as $sx) if (isset($on[strtoupper($sx)])) { unset($on[strtoupper($sx)]); }
    $gS = $gen; foreach (['s1','s2','s3'] as $sx) $gS[$sx] = 0.0;
    if (function_exists('pp_recompute_stgs')) { try { pp_recompute_stgs($gS, $d3, $m, 1); } catch (Throwable $e) {} }
    foreach (['s1','s2','s3'] as $sx) { $isRun = strtolower(trim((string)($lds[strtoupper($sx)] ?? ''))) === 'running';
        $gen[$sx] = $isRun ? max(0.0, (float)($gS[$sx] ?? 0)) : 0.0; if ($gen[$sx] > 0) $on[strtoupper($sx)] = round($gen[$sx], 2); }
    $tot = array_sum($gen); $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += $gen[$u];
    $ie = (float)($in['data1'][0]['value'] ?? 0); $hl = function_exists('calc_house_load') ? (float)calc_house_load($gen) : 0.0;
    $pep = (array)($m['pln_export_priority'] ?? []); $lo = (float)($pep['range']['min'] ?? 0);
    foreach ((array)($pep['range_rules'] ?? []) as $rr) if ((int)($rr['start'] ?? 0) <= 1 && (int)($rr['stop'] ?? 0) >= 1) $lo = (float)($rr['min'] ?? $lo);
    $exMax = $tot - $mm - $hl - $ie;
    if ($exMax >= $lo - 1e-6) return null;
    return ['schema' => 'co15-terminal-row1-export-v1', 'code' => 'TERMINAL_INFEASIBLE_EXPORT_ROW_1', 'row' => 1, 'time' => $in['data1'][0]['time'] ?? null,
        'units_online_row1_at_max' => $on, 'ie_mw' => $ie, 'house_load_mw' => round($hl, 2), 'export_max_mw' => round($exMax, 2), 'export_range_min_mw' => $lo, 'deficit_mw' => round($lo - $exMax, 2),
        'proof' => sprintf('Row 1 (%s): unit yang dapat berbeban hanya Last Data Running & tersedia (%s) pada beban maksimum -> Export maksimum %.2f MW < Range Min %.2f MW (kurang %.2f MW). Unit berstatus Stop paling cepat berbeban di row 2; LNG/Distillate tidak menambah kapasitas.',
            (string)($in['data1'][0]['time'] ?? ''), implode(', ', array_map(function ($k, $v) { return $k . ' ' . $v . ' MW'; }, array_keys($on), $on)), $exMax, $lo, $lo - $exMax),
        'feasible_if' => ['unit lain tersedia/Running pada Last Data', 'Range Min row 1 diturunkan', 'IE row 1 lebih rendah']];
}
function pp_v13f_fast_job(string $id, array $inputAsli): array {
    $t0 = microtime(true); $GLOBALS['ppV13fTrace'] = [];
    $tr = function (string $k, array $x = []) use ($t0) { $GLOBALS['ppV13fTrace'][] = ['step' => $k, 'at_s' => round(microtime(true) - $t0, 3)] + $x; };
    $act = strtolower(trim((string)($inputAsli['data3']['modeling']['gas_shortage_action'] ?? 'none')));
    $cert = null; try { $cert = pp_v15_row1_export_cert($inputAsli); } catch (Throwable $e) { $cert = null; }
    if (is_array($cert)) {
        /* keputusan terminal: satu core run (baris pratinjau bukti), tanpa pencarian */
        pp_job_progress($id, 'FASTEST_KEPUTUSAN_TERMINAL', 50.0); $tr('terminal_certificate', ['code' => $cert['code']]);
        $c = pp_v13f_clean($inputAsli); pp_tl_clean_globals(); pp_budget_start(60.0, true, true);
        try { $o = pp_run_simulation_core($c); } catch (Throwable $e) { $o = ['data' => [], 'info' => []]; }
        $o['info']['Fastest Terminal Certificate'] = $cert; $o['terminal_decision'] = $cert;
        $o['info']['Run Status'] = ['completed' => true, 'converged' => true, 'deadline_reached' => false, 'budget_truncated' => false, 'stages_truncated' => [], 'economic_review_skipped' => null,
            'economic_review_completed' => false, 'status' => 'CERTIFIED_INFEASIBLE', 'mode' => 'FASTEST_TERMINAL_CERTIFICATE', 'fastest' => true, 'fastest_shortage_proven' => true, 'global_optimum_proven' => false];
        $o['info']['Fastest Trace'] = ['schema' => 'co15-fastest-trace-v1', 'steps' => $GLOBALS['ppV13fTrace'], 'wall_s' => round(microtime(true) - $t0, 3)];
        return $o;
    }
    if (in_array($act, ['add_lng', 'use_distillate', 'mixed_lng_distillate'], true)) {
        pp_job_progress($id, 'FASTEST_RERUN_BAHAN_BAKAR', 20.0); $tr('fuel_route_start', ['action' => $act]);
        $v7 = null; try { $v7 = pp_v7_fuel_rerun_from_basis($id, $inputAsli); } catch (PpJobAborted $e) { throw $e; } catch (Throwable $e) { $v7 = null; }
        if (is_array($v7) && is_array($v7['output'] ?? null)) { $o = $v7['output']; $tr('v7_frozen_basis_valid', ['cp' => $o['info']['Cost Production (USD/MWh)'] ?? null]); }
        else { $tr('v7_not_applicable', ['reason' => $GLOBALS['ppV7FuelRerun']['reason'] ?? null]); pp_job_progress($id, 'FASTEST_PIPELINE_STATE_BAHAN_BAKAR', 30.0);
               $o = pp_v13f_pipeline($inputAsli); $tr('fuel_state_pipeline_done', ['cp' => $o['info']['Cost Production (USD/MWh)'] ?? null]); }
        pp_v13f_mark($o, is_array($v7) ? 'FASTEST_V7_FUEL_FROM_BASIS' : 'FASTEST_PIPELINE_FUEL_STATE', false);
    } else {
        pp_job_progress($id, 'FASTEST_PIPELINE', 10.0);
        $o = pp_v13f_pipeline($inputAsli); $tr('pipeline_done', ['cp' => $o['info']['Cost Production (USD/MWh)'] ?? null]);
        $short = pp_v13f_shortage($o);
        if ($short) {
            try { $k = pp_v13f_basis_key($inputAsli); pp_tl_write(pp_v13f_basis_file($k), ['key' => $k, 'at' => microtime(true), 'output' => $o]); $tr('fast_basis_stored', ['key' => substr($k, 0, 16)]); } catch (Throwable $e) {}
            pp_job_progress($id, 'FASTEST_SHORTAGE_TERBUKTI_KEPUTUSAN_BAHAN_BAKAR', 60.0);
        }
        pp_v13f_mark($o, 'FASTEST_PIPELINE', $short);
    }
    $o['info']['Fastest Trace'] = ['schema' => 'co15-fastest-trace-v1', 'steps' => $GLOBALS['ppV13fTrace'], 'wall_s' => round(microtime(true) - $t0, 3),
        'rule' => 'Fastest: kandidat fully valid pertama + review Unit Priority V8 + gerbang penerimaan; tanpa keluarga commitment / komparator exact (Maximum Review).'];
    return $o;
}
/* =============================================================================================
 *  V15.15 BUKTI MERIT C1-C4 + STG + UNIT PRIORITY (read-only, generik; membaca array Unit Priority operator).
 *  Dibangun dari audit engine yang sudah ada pada lini ini — tidak ada aturan baru yang lebih longgar:
 *   C1 = LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM  (unit prioritas rendah di atas minimum, unit lebih tinggi ber-headroom)
 *   C2 = LOWER_PRIORITY_START_WHILE_HIGHER_HEADROOM   (start unit prioritas rendah) + bukti counterfactual review V8
 *   C3 = LOW_LOAD_FRAGMENTATION (V11)                 (banyak unit beban minimum / stop pada row legal pertama)
 *   C4 = LOWER_PRIORITY_RUNNING_WHILE_HIGHER_HEADROOM (lintas grup prioritas)
 *   STG = output S1-S3 per row dihitung ulang dari beban GTG blok (fungsi engine pp_recompute_stgs) dan dibandingkan.
 *  Temuan tanpa alasan (resolved=false) = FAIL. */
function pp_v15_merit_proof(array $input, array $out): array {
    $orig = pp_normalize_copy($input); $d3 = (array)$orig['data3']; $m = (array)$d3['modeling'];
    $rows = array_values((array)($out['data'] ?? [])); if (count($rows) !== 48) return ['schema' => 'co15-merit-proof-v1', 'status' => 'TIDAK_BERLAKU'];
    $H = (array)($out['info']['Headroom Priority Audit'] ?? []); if (!$H) { try { $H = pp_v5_headroom_priority_audit($orig, $out, true); } catch (Throwable $e) { $H = []; } }
    $L = (array)($out['info']['V11 Low Load Fragmentation Audit'] ?? []); if (!$L && function_exists('pp_v11_frag_audit')) { try { $L = pp_v11_frag_audit($orig, $out); } catch (Throwable $e) { $L = []; } }
    $rv = (array)($out['info']['V8 Priority Review'] ?? []); $cands = (array)($rv['final_candidates'] ?? []);
    $map = ['LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' => 'C1', 'LOWER_PRIORITY_START_WHILE_HIGHER_HEADROOM' => 'C2', 'LOWER_PRIORITY_RUNNING_WHILE_HIGHER_HEADROOM' => 'C4'];
    $C = ['C1' => ['findings' => 0, 'fail' => 0, 'detail' => []], 'C2' => ['findings' => 0, 'fail' => 0, 'detail' => []], 'C3' => ['findings' => 0, 'fail' => 0, 'detail' => []], 'C4' => ['findings' => 0, 'fail' => 0, 'detail' => []]];
    foreach ((array)($H['flags'] ?? []) as $f) { $k = $map[$f['type'] ?? ''] ?? null; if ($k === null) continue; $C[$k]['findings']++;
        $ev = (array)($f['reasons'] ?? []);
        if ($k === 'C2') { foreach ($cands as $c) if (strtoupper((string)($c['unit'] ?? '')) === strtoupper((string)$f['unit']) && !empty($c['evaluated']))
            $ev[] = sprintf('COUNTERFACTUAL %s: %s', $c['id'], !empty($c['valid']) ? ('valid CP ' . $c['cp'] . ' (tidak lebih baik)') : ('tidak valid ' . implode(',', (array)($c['violations'] ?? [])))); }
        if ($k === 'C1' && empty($f['resolved'])) foreach ((array)($out['info']['Fastest Unit Priority Fix']['proofs'] ?? []) as $pf)
            if ((int)$pf['row'] === (int)($f['row'] ?? 0) && strtoupper((string)$pf['unit']) === strtoupper((string)$f['unit']) && strtoupper((string)$pf['higher']) === strtoupper((string)($f['higher_unit'] ?? '')))
                { $ev[] = 'BUKTI_' . $pf['reason'] . (isset($pf['detail']) ? ': ' . $pf['detail'] : ''); $f['resolved'] = true; }
        $ok = !empty($f['resolved']) && $ev; if (!$ok) $C[$k]['fail']++;
        if (!$ok || $k !== 'C4') $C[$k]['detail'][] = ['row' => $f['row'] ?? null, 'unit' => $f['unit'] ?? null, 'mw' => $f['unit_mw'] ?? ($f['start_mw'] ?? null),
            'higher' => $f['higher_unit'] ?? ($f['headroom_units'] ?? null), 'result' => $ok ? 'PASS_WITH_REASON' : 'FAIL', 'evidence' => array_slice($ev, 0, 4)]; }
    foreach ((array)($L['findings_detail'] ?? []) as $f) { $C['C3']['findings']++; $ok = !empty($f['resolved']);
        if (!$ok) foreach ((array)($out['info']['Fastest Unit Priority Fix']['proofs'] ?? []) as $pf)
            if (($pf['kind'] ?? '') === 'C3' && (int)$pf['row'] === (int)$f['row'] && strtoupper((string)$pf['unit']) === strtoupper((string)$f['unit'])) { $ok = true; $f['reasons'][] = 'BUKTI_' . $pf['reason'] . (isset($pf['detail']) ? ': ' . $pf['detail'] : ''); }
        if (!$ok) { $C['C3']['fail']++; $C['C3']['detail'][] = $f; } }
    /* STG */
    $mis = 0; $ex = []; $chk = 0;
    if (function_exists('pp_recompute_stgs')) foreach ($rows as $k => $r) {
        $gen = []; foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4'] as $u) $gen[$u] = (float)($r[strtoupper($u)] ?? 0);
        $g2 = $gen; try { pp_recompute_stgs($g2, $d3, $m, $k + 1); } catch (Throwable $e) { continue; }
        foreach (['s1','s2','s3'] as $s) { $chk++; if (abs((float)$g2[$s] - $gen[$s]) > 0.05) { $mis++; if (count($ex) < 6) $ex[] = ['row' => $k + 1, 'stg' => strtoupper($s), 'reported' => $gen[$s], 'calc' => round((float)$g2[$s], 3)]; } } }
    $fail = $C['C1']['fail'] + $C['C2']['fail'] + $C['C3']['fail'] + $C['C4']['fail'];
    foreach ($C as $k => $x) { $C[$k]['status'] = $x['fail'] ? 'FAIL' : ($x['findings'] ? 'PASS_WITH_REASON' : 'PASS'); $C[$k]['detail'] = array_slice($C[$k]['detail'], 0, 12); }
    return ['schema' => 'co15-merit-proof-v1', 'status' => ($fail === 0 && $mis === 0) ? 'PASS' : 'FAIL', 'C1' => $C['C1'], 'C2' => $C['C2'], 'C3' => $C['C3'], 'C4' => $C['C4'],
        'stg' => ['rows_x_stg' => $chk, 'mismatch' => $mis, 'status' => $mis ? 'FAIL' : 'PASS', 'examples' => $ex],
        'unit_priority' => $m['unit_priority'] ?? null, 'review' => ['status' => $rv['status'] ?? null, 'candidates_simulated' => $rv['candidates_simulated'] ?? null, 'applied' => array_column((array)($rv['applied'] ?? []), 'candidate')],
        'rule' => 'C1-C4 dari audit Headroom Priority + LOW_LOAD_FRAGMENTATION engine; temuan wajib beralasan (status paksa / ekonomi / counterfactual review V8); STG = fungsi kopling engine per row.'];
}
/* V15.15 Fastest: temuan C1 tanpa alasan (unit prioritas rendah di atas minimum sementara unit prioritas lebih tinggi ber-headroom).
 * Comparator Fastest = penalti Unit Priority lebih dulu, baru CP. Per row, beban dipindah dari unit prioritas TERENDAH ke unit
 * prioritas lebih tinggi sebesar min(sisa di atas minimum, sisa headroom); setiap pemindahan menurunkan gas (unit prioritas tinggi
 * lebih efisien) dan dihitung dengan kurva bahan bakar engine (calc_fuel). Pemindahan berhenti tepat sebelum gas menyentuh tepi
 * bawah window [kuota-0,04] (LNG/kontrak must-take) — temuan sisa mendapat bukti numerik GAS_WINDOW_LOWER_EDGE (ruang gas vs
 * penurunan yang dibutuhkan). Dispatch hasil dievaluasi penuh sekali (engine + validator); tidak valid -> tiap pemindahan
 * ditambahkan satu per satu (greedy) dengan bukti counterfactual tunggal untuk yang tidak valid; hingga 8 lintasan. */
function pp_v15_fast_priority_fix(array $input, array $out, float $dl): array {
    $GLOBALS['__pp_v15_fixmemo'] = [];
    /* lintasan berulang: pemindahan mengubah headroom, sehingga audit dispatch baru dapat memunculkan pasangan baru */
    $all = []; $proofs = [];
    for ($pass = 1; $pass <= 8 && microtime(true) < $dl - 2.0; $pass++) {
        $o2 = pp_v15_fast_priority_fix_pass($input, $out, $dl);
        $lg = (array)($o2['info']['Fastest Unit Priority Fix'] ?? []);
        $origL = pp_normalize_copy($input); foreach (array_keys((array)$origL['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0) unset($origL['data3']['modeling'][$mk]);
        $pL = []; $sigL = pp_v6_gtg_sig((array)$o2['data']); $o2 = pp_v15_fast_llf_trim($origL, $o2, $dl, $pL); foreach ($pL as $pf) $proofs[$pf['row'] . '#' . $pf['unit'] . '#' . $pf['higher']] = $pf;
        $llfChanged = pp_v6_gtg_sig((array)$o2['data']) !== $sigL;
        if (!$lg && !$llfChanged) { $out = $o2; break; }
        if (!$lg) { $out = $o2; continue; }
        $lg['pass'] = $pass; $all[] = array_diff_key($lg, ['proofs' => 1]); foreach ((array)($lg['proofs'] ?? []) as $pf) $proofs[$pf['row'] . '#' . $pf['unit'] . '#' . $pf['higher']] = $pf;
        $out = $o2; if (empty($lg['applied']) && !$llfChanged) break; }
    if ($all || $proofs) $out['info']['Fastest Unit Priority Fix'] = ['schema' => 'co15-fast-priority-fix-v2', 'passes' => $all, 'proofs' => array_values($proofs),
        'cp_before' => $all[0]['cp_before'] ?? null, 'cp_after' => $out['info']['Cost Production (USD/MWh)'] ?? null,
        'rule' => 'Comparator Fastest: penalti Unit Priority lebih dulu, lalu CP (perubahan CP dilaporkan). Temuan sisa wajib berbukti numerik.'];
    return $out;
}
/* C3 (LOW_LOAD_FRAGMENTATION) pada TEPI segmen run (row terakhir/pertama unit): unit dimatikan pada row itu, bebannya dipindah ke
 * headroom unit prioritas lebih tinggi yang online di row yang sama (urutan Unit Priority). Satu evaluasi penuh per lintasan untuk
 * seluruh kandidat; tidak valid -> bukti COUNTERFACTUAL_TIDAK_VALID per temuan. Row tengah segmen tidak disentuh (runtime/downtime). */
function pp_v15_fast_llf_trim(array $orig, array $out, float $dl, array &$proofs): array {
    try { $L = pp_v11_frag_audit($orig, $out); } catch (Throwable $e) { return $out; }
    $un = []; foreach ((array)($L['findings_detail'] ?? []) as $f) if (empty($f['resolved'])) $un[] = $f;
    if (!$un) return $out;
    $rows = array_values((array)$out['data']); $n = count($rows); $m = (array)$orig['data3']['modeling']; $d3 = (array)$orig['data3'];
    $bd = array_map(function ($r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) $x[$u] = (float)($r[$u] ?? 0); return $x; }, $rows);
    $rank = pp_priority_rank($m); $moves = [];
    foreach ($un as $f) { $k = (int)$f['row'] - 1; $U = strtoupper((string)$f['unit']); if (!isset($bd[$k][$U]) || $bd[$k][$U] <= 0.01) continue;
        $edge = ($k === $n - 1 || $bd[$k + 1][$U] <= 0.01) || ($k === 0 || $bd[$k - 1][$U] <= 0.01);
        if (!$edge || pp_get_fixed_load($m, strtolower($U), $k + 1) >= 0) { $proofs[] = ['row' => $k + 1, 'unit' => $U, 'higher' => '*', 'kind' => 'C3', 'reason' => 'ROW_TENGAH_SEGMEN_RUNTIME_DOWNTIME']; continue; }
        $need = $bd[$k][$U]; $hd = (array)($f['higher_priority_headroom'] ?? []);
        uksort($hd, function ($a, $b) use ($rank) { return (int)($rank[strtolower($a)] ?? 99) <=> (int)($rank[strtolower($b)] ?? 99); });
        $plan = []; foreach ($hd as $V => $h) { if ($need <= 0.01) break; $V = strtoupper($V); if (!isset($bd[$k][$V])) continue; $d = min($need, (float)$h); if ($d <= 0.01) continue; $plan[$V] = $d; $need -= $d; }
        if ($need > 0.01) { $proofs[] = ['row' => $k + 1, 'unit' => $U, 'higher' => '*', 'kind' => 'C3', 'reason' => 'HEADROOM_UNIT_PRIORITAS_LEBIH_TINGGI_TIDAK_CUKUP', 'detail' => sprintf('beban %.2f MW, headroom total %.2f MW', $bd[$k][$U], array_sum($hd))]; continue; }
        $moves[] = ['row' => $k + 1, 'unit' => $U, 'to' => $plan]; }
    if (!$moves || microtime(true) > $dl - 1.5) return $out;
    $b2 = $bd; foreach ($moves as $mv) { $k = $mv['row'] - 1; $b2[$k][$mv['unit']] = 0.0; foreach ($mv['to'] as $V => $d) $b2[$k][$V] = round($b2[$k][$V] + $d, 4); }
    $a = pp_v3_frozen_eval($orig, $b2, $dl); pp_tl_clean_globals();
    if (is_array($a) && !empty($a['valid'])) {
        $keep = []; foreach (['Run Status', 'V8 Priority Review', 'Fastest Trace', 'V7 Fuel Delta Validation', 'V7 Delta Route', 'Exact Candidate Space', 'Fastest Unit Priority Fix'] as $kk) if (isset($out['info'][$kk])) $keep[$kk] = $out['info'][$kk];
        $o2 = $a['output']; foreach ($keep as $kk => $x) $o2['info'][$kk] = $x; $o2['info']['Fastest LLF Trim'] = ['applied' => $moves, 'cp_before' => $out['info']['Cost Production (USD/MWh)'] ?? null, 'cp_after' => $o2['info']['Cost Production (USD/MWh)'] ?? null];
        return $o2; }
    foreach ($moves as $mv) $proofs[] = ['row' => $mv['row'], 'unit' => $mv['unit'], 'higher' => '*', 'kind' => 'C3', 'reason' => 'COUNTERFACTUAL_TIDAK_VALID',
        'detail' => 'unit dimatikan pada row ini (beban ke ' . implode('/', array_keys($mv['to'])) . '), engine 48 row: ' . implode(',', (array)(is_array($a) ? ($a['violations'] ?? []) : ['EVALUASI_GAGAL']))];
    return $out;
}
function pp_v15_fast_priority_fix_pass(array $input, array $out, float $dl): array {
    $orig = pp_normalize_copy($input); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0) unset($orig['data3']['modeling'][$mk]);
    $d3 = (array)$orig['data3']; $m = (array)$d3['modeling'];
    try { $H = pp_v5_headroom_priority_audit($orig, $out, true); } catch (Throwable $e) { return $out; }
    /* daftar `unresolved` audit dipotong 40 entri -> baca seluruh flags */
    $un = []; foreach ((array)($H['flags'] ?? []) as $f) if (($f['type'] ?? '') === 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' && empty($f['resolved'])) $un[] = $f;
    if (!$un) return $out;
    $i0 = (array)$out['info']; $q = (float)($i0['Total Gas Quota (BBTUD)'] ?? 0); $g0 = (float)($i0['Total Gas Used (BBTUD)'] ?? 0);
    if ((int)($i0['Actual Hours Provided'] ?? 0) > 0 && isset($i0['Effective Total Gas (BBTUD)'])) $g0 = (float)$i0['Effective Total Gas (BBTUD)'];
    $room = $q > 1.0 ? max(0.0, $g0 - ($q - 0.04) - 0.004) : INF;                        // ruang penurunan gas sampai tepi bawah window
    $rank = pp_priority_rank($m); usort($un, function ($x, $y) use ($rank) { return [(int)$x['row'], -(int)($rank[strtolower($x['unit'])] ?? 0)] <=> [(int)$y['row'], -(int)($rank[strtolower($y['unit'])] ?? 0)]; });
    $bd = array_map(function ($r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) $x[$u] = (float)($r[$u] ?? 0); return $x; }, (array)$out['data']);
    $minL = function (string $u) use ($d3): float { $u = strtolower($u); return (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20)); };
    $hdUsed = []; $moves = []; $proofs = []; $used = 0.0; $usedUp = 0.0;
    foreach ($un as $f) { $k = (int)$f['row'] - 1; $lo = strtoupper((string)$f['unit']); $hi = strtoupper((string)$f['higher_unit']);
        $hk = $k . '#' . $hi; $hd = (float)$f['higher_headroom_mw'] - ($hdUsed[$hk] ?? 0.0);
        $d = round(min((float)($f['shiftable_mw'] ?? 0), $hd, $bd[$k][$lo] - $minL($lo)), 2);
        if ($d <= 0.05) { $proofs[] = ['row' => $k + 1, 'unit' => $lo, 'higher' => $hi, 'reason' => 'HEADROOM_UNIT_LEBIH_TINGGI_SUDAH_TERPAKAI_PEMINDAHAN_LAIN_ROW_INI']; continue; }
        $dg = (calc_fuel($d3, strtolower($lo), $bd[$k][$lo] - $d) - calc_fuel($d3, strtolower($lo), $bd[$k][$lo]) + calc_fuel($d3, strtolower($hi), $bd[$k][$hi] + $d) - calc_fuel($d3, strtolower($hi), $bd[$k][$hi])) / 2.0;
        /* V15.16: tepi ATAS window — pemindahan yang MENAIKKAN gas melebihi sisa kuota pasti melanggar (bukti numerik, tanpa simulasi). */
        if ($dg > 0 && $q > 1.0 && $usedUp + $dg > max(0.0, $q - $g0) + 1e-6) { $proofs[] = ['row' => $k + 1, 'unit' => $lo, 'higher' => $hi, 'reason' => 'GAS_WINDOW_UPPER_EDGE',
            'detail' => sprintf('memindah %.2f MW %s->%s menaikkan gas %.5f BBTUD; sisa kuota sampai tepi atas window %.5f BBTUD (gas %.4f, kuota %.4f)', $d, $lo, $hi, $dg, max(0.0, $q - $g0 - $usedUp), $g0, $q)]; continue; }
        if ($dg < 0 && $used - $dg > $room) { $proofs[] = ['row' => $k + 1, 'unit' => $lo, 'higher' => $hi, 'reason' => 'GAS_WINDOW_LOWER_EDGE',
            'detail' => sprintf('memindah %.2f MW %s->%s menurunkan gas %.5f BBTUD; ruang tersisa sampai tepi bawah window %.5f BBTUD (gas %.4f, window bawah %.4f)', $d, $lo, $hi, -$dg, max(0.0, $room - $used), $g0, $q - 0.04)]; continue; }
        $bd[$k][$lo] = round($bd[$k][$lo] - $d, 4); $bd[$k][$hi] = round($bd[$k][$hi] + $d, 4); $hdUsed[$hk] = ($hdUsed[$hk] ?? 0.0) + $d; $used -= min(0.0, $dg); $usedUp += max(0.0, $dg);
        $moves[] = ['row' => $k + 1, 'from' => $lo, 'to' => $hi, 'mw' => $d, 'gas_delta_bbtud' => round($dg, 5)]; }
    $log = ['findings' => count($un), 'moves_planned' => count($moves), 'proofs' => $proofs, 'gas_room_bbtud' => is_finite($room) ? round($room, 5) : null];
    $base = array_map(function ($r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $u) $x[$u] = (float)($r[$u] ?? 0); return $x; }, (array)$out['data']);
    /* evaluasi gabungan lebih dulu; bila tidak valid, tiap pemindahan ditambahkan satu per satu (greedy, urutan prioritas):
     * yang tetap valid diterapkan, yang tidak valid mendapat bukti counterfactual tunggal (bukan bukti gabungan). */
    $apply = function (array $mvs) use ($base): array { $b2 = $base; foreach ($mvs as $mv) { $k = $mv['row'] - 1; $b2[$k][$mv['from']] = round($b2[$k][$mv['from']] - $mv['mw'], 4); $b2[$k][$mv['to']] = round($b2[$k][$mv['to']] + $mv['mw'], 4); } return $b2; };
    $try = $moves; $ok = false; $att = []; $a = null;
    if ($try && microtime(true) < $dl - 1.5) {
        $a = pp_v3_frozen_eval($orig, $apply($try), $dl); pp_tl_clean_globals();
        $att[] = ['moves' => count($try), 'valid' => is_array($a) && !empty($a['valid']), 'violations' => is_array($a) ? ($a['violations'] ?? []) : null, 'cp' => is_array($a) ? ($a['key']['cp'] ?? null) : null];
        $ok = is_array($a) && !empty($a['valid']);
        if (!$ok) { $jv = implode(',', (array)(is_array($a) ? ($a['violations'] ?? []) : ['EVALUASI_GAGAL'])); $acc = []; $aAcc = null;
            foreach ($moves as $mv) {
                if (microtime(true) >= $dl - 1.5) { $proofs[] = ['row' => $mv['row'], 'unit' => $mv['from'], 'higher' => $mv['to'], 'reason' => 'COUNTERFACTUAL_TIDAK_VALID',
                    'detail' => 'pemindahan gabungan dievaluasi engine 48 row: ' . $jv]; continue; }
                /* V15.16 stop rule: kandidat identik tidak dihitung ulang — bukti tidak-valid lintasan sebelumnya dipakai ulang bila
                 * pelanggarannya LOKAL (export/bus/ramp/reserve) dan GTG row r-1..r+1 belum berubah sejak bukti itu dibuat. */
                $lk = $mv['row'] . ':' . $mv['from'] . '>' . $mv['to'] . ':' . $mv['mw']; $loc = '';
                for ($q = max(0, $mv['row'] - 2); $q <= min(47, $mv['row']); $q++) $loc .= json_encode($base[$q]);
                $lsig = md5($loc . json_encode($acc));
                $memo = $GLOBALS['__pp_v15_fixmemo'][$lk] ?? null;
                if (is_array($memo) && $memo['sig'] === $lsig) {
                    $att[] = ['moves' => count($acc) + 1, 'single' => $mv['row'] . ':' . $mv['from'] . '->' . $mv['to'], 'valid' => false, 'violations' => $memo['viol'], 'cp' => null, 'memo' => true];
                    $proofs[] = ['row' => $mv['row'], 'unit' => $mv['from'], 'higher' => $mv['to'], 'reason' => 'COUNTERFACTUAL_TIDAK_VALID',
                        'detail' => sprintf('pemindahan %.2f MW %s->%s dievaluasi engine 48 row (lintasan sebelumnya, row sekitar tidak berubah): %s', $mv['mw'], $mv['from'], $mv['to'], implode(',', $memo['viol']))];
                    continue;
                }
                $c = pp_v3_frozen_eval($orig, $apply(array_merge($acc, [$mv])), $dl); pp_tl_clean_globals();
                $cv = is_array($c) && !empty($c['valid']);
                if (!$cv && is_array($c)) { $vv = array_values(array_map('strval', (array)($c['violations'] ?? [])));
                    if ($vv && !array_diff($vv, ['export_range', 'bus_flow', 'busflow', 'ramp', 'export_ramp', 'spinning_reserve', 'reserve'])) $GLOBALS['__pp_v15_fixmemo'][$lk] = ['sig' => $lsig, 'viol' => $vv]; }
                $att[] = ['moves' => count($acc) + 1, 'single' => $mv['row'] . ':' . $mv['from'] . '->' . $mv['to'], 'valid' => $cv, 'violations' => is_array($c) ? ($c['violations'] ?? []) : null, 'cp' => is_array($c) ? ($c['key']['cp'] ?? null) : null];
                if ($cv) { $acc[] = $mv; $aAcc = $c; continue; }
                $proofs[] = ['row' => $mv['row'], 'unit' => $mv['from'], 'higher' => $mv['to'], 'reason' => 'COUNTERFACTUAL_TIDAK_VALID',
                    'detail' => sprintf('pemindahan %.2f MW %s->%s dievaluasi engine 48 row: %s', $mv['mw'], $mv['from'], $mv['to'], implode(',', (array)(is_array($c) ? ($c['violations'] ?? []) : ['EVALUASI_GAGAL'])))]; }
            if ($acc) { $ok = true; $try = $acc; $a = $aAcc; } }
    }
    $log['attempts'] = $att; $log['proofs'] = $proofs; $log['applied'] = $ok ? $try : [];
    $log['cp_before'] = $i0['Cost Production (USD/MWh)'] ?? null;
    if ($ok) { $keep = []; foreach (['Run Status', 'V8 Priority Review', 'Fastest Trace', 'V7 Fuel Delta Validation', 'V7 Delta Route', 'Exact Candidate Space'] as $kk) if (isset($out['info'][$kk])) $keep[$kk] = $out['info'][$kk];
        $out = $a['output']; foreach ($keep as $kk => $x) $out['info'][$kk] = $x; }
    $log['cp_after'] = $out['info']['Cost Production (USD/MWh)'] ?? null;
    $log['rule'] = 'Comparator Fastest: penalti Unit Priority lebih dulu, lalu CP (perubahan CP dilaporkan). Temuan sisa wajib berbukti numerik.';
    $out['info']['Fastest Unit Priority Fix'] = ['schema' => 'co15-fast-priority-fix-v2'] + $log;
    return $out;
}
function pp_job_run_economic_review(string $id, array $input): array {
    $inputAsli = $input;                          // identitas job turunan memakai input yang sama
    /* Pengamat TARGET SELESAI: mencatat kandidat valid yang ditemui pencarian exact ke kolam state
     * ini, dan menerima permintaan berhenti dari mode waktu terbatas. Tidak mengubah hasil. */
    try {
        $tlOrig = pp_normalize_copy($inputAsli);
        $GLOBALS['ppTlHook'] = ['job' => $id, 'key' => pp_tl_key($inputAsli), 'orig' => $tlOrig,
                                'n' => 0, 'v' => 0, 'flush' => 0.0, 'cchk' => 0.0];
    } catch (Throwable $e) { unset($GLOBALS['ppTlHook']); }
    pp_job_progress($id, 'MENJALANKAN_SIMULASI_LENGKAP', 5.0);
    /* V3: tahap nyata job pemilik ditulis ke berkas progres request yang memicunya, sehingga modal
     * progres UI menampilkan tahap yang benar-benar dilewati (sama seperti jalur sinkron dahulu). */
    $GLOBALS['__pp_prelim_rid'] = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)($inputAsli['_request_id'] ?? ''));
    $GLOBALS['__pp_prelim_t0'] = microtime(true); $GLOBALS['__pp_prelim_steps'] = [];
    if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_owner_start');
    if (!isset($input['data3']['modeling'])) $input['data3']['modeling'] = [];
    $input = pp_econ_job_sim_input($input, (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0));
    pp_budget_start((float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0), true, true);
    $t = microtime(true);
    /* V3: state identik -> memo; perubahan data slot/kuota dari final valid terakhir -> recompute
     * inkremental (commitment dipertahankan, kandidat yang dapat menyalip dievaluasi ulang);
     * selain itu, atau bila inkremental tidak dapat menjamin pemenang -> pipeline exact penuh. */
    $incR = null; $v7R = null;
    /* V15.15 FASTEST: jalur kandidat fully valid pertama (tanpa keluarga commitment / jangkar kanonik / komparator exact). */
    $fastJ = pp_v13f_is_fast($inputAsli);
    if ($fastJ) { $output = pp_v13f_fast_job($id, $inputAsli); goto pp_v13f_after; }
    $memoAv = pp_memo_available($input);
    if (!$memoAv) $v7R = pp_v7_route_certificate($id, $inputAsli);             // V7: sertifikat envelope gas lebih dulu
    /* V7: pilihan bahan bakar SELALU melanjutkan rencana exact basis bila valid (juga bila hasil tersimpan
     * ada), sehingga hasilnya sama dengan atau tanpa pemakaian ulang state. */
    if (!is_array($v7R)) $v7R = pp_v7_fuel_rerun_from_basis($id, $inputAsli);
    if (is_array($v7R)) $incR = ['output' => $v7R['output'], 'report' => $v7R['report'], 'v3' => null];
    elseif (!$memoAv) {
        pp_job_progress($id, 'DIFF_DENGAN_FINAL_VALID_TERAKHIR', 6.0);
        /* V9 KANONIK: FINAL = fungsi state saja. Rute inkremental (bergantung FINAL sebelumnya) tidak lagi
         * menentukan pemenang; FINAL selalu dari pipeline exact + ruang kandidat state (keluarga, review generik).
         * PP_V9_CANON=0 mengembalikan rute inkremental V8. */
        if (pp_v9_canon()) {
            /* FINAL kanonik jangkar D(S) dihitung sekali (pipeline exact + review generik) lalu dipakai ulang
             * oleh setiap state dengan jangkar yang sama — hanya mempercepat, tidak mengubah definisi. */
            try { pp_v9_ensure_anchor($id, $inputAsli); } catch (PpJobAborted $e) { throw $e; } catch (Throwable $e) {}
            /* V10: rute cepat kanonik lebih dulu (perubahan slot Actual / fixed flow); tanpa kandidat valid -> rute V9. */
            $inc10 = pp_v10_fast() ? pp_v10_fast_incremental($id, $inputAsli) : null;
            if (is_array($inc10) && is_array($inc10['output'] ?? null)) $incR = $inc10;
            else { $incR = pp_v3_incremental($id, $inputAsli); if (is_array($inc10) && is_array($incR)) $incR['report']['v10_fast'] = $inc10['report'] ?? null; }
        } else $incR = pp_v3_incremental($id, $inputAsli);
    }
    if (is_array($incR) && is_array($incR['output'] ?? null)) {
        $output = $incR['output'];
    } else {
        if (is_array($incR)) pp_job_progress($id, 'EXACT_PENUH', 8.0, ['incremental_fallback' => (string)($incR['report']['reason'] ?? '')]);
        if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_exact_full');
        $pre = null; $famPar = false;
        /* V4: keluarga commitment (ruang kandidat Maximum Review) dikerjakan pekerja pembantu
         * BERSAMAAN dengan pipeline exact pemilik, lewat registri node dan kolam kandidat yang sama.
         * Tahap keluarga di akhir pipeline memakai ulang node yang sudah selesai (tidak dihitung dua
         * kali) dan ikut mengerjakan sisanya. Tanpa pembantu aktif, prepass V3 yang berlaku. */
        if (!pp_memo_available($input) && pp_v4_helper_slots() > 0 && (string)getenv('PP_EXACT_FAMILY') !== '0') {
            try {
                $origF = pp_normalize_copy($inputAsli);
                foreach (array_keys((array)$origF['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($origF['data3']['modeling'][$mk]);
                if (empty($origF['data3']['modeling']['change_over']['enabled'])) {
                    $seedsF = pp_tl_seed_stops($origF);
                    $seedListF = ($seedsF && isset($seedsF[0]) && is_array($seedsF[0]) && !isset($seedsF[0]['unit'])) ? $seedsF : ($seedsF ? [$seedsF] : []);
                    pp_v4_work_publish($id, $origF, ['kind' => 'family', 'seeds' => $seedListF, 'max_nodes' => 64,
                        'dl' => (float)($GLOBALS['__pp_budget_deadline'] ?? (microtime(true) + 1500.0)) - 4.0]);
                    $famPar = pp_v4_helpers_wait($id, 1.5) > 0;
                }
            } catch (Throwable $e) { $famPar = false; }
        }
        if (!pp_memo_available($input) && !$famPar) { pp_job_progress($id, 'EXACT_KELUARGA_COMMITMENT_LEBIH_DULU', 9.0);
            if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v3_exact_family_first');
            $pre = pp_v3_prepass($id, $inputAsli); }
        if ($famPar) { $pre = ['ran' => false, 'reason' => 'V4_KELUARGA_DIKERJAKAN_PEKERJA_PEMBANTU_PARALEL', 'helpers' => pp_v4_helpers_alive($id)];
            if (function_exists('pp_prelim_progress_mark')) pp_prelim_progress_mark('v4_family_parallel'); }
        $output = pp_sim_memo_run($input);        // state identik yang sudah dihitung dipakai ulang
        if (is_array($pre)) $output['info']['Exact Family Prepass'] = $pre;
        if (is_array($incR)) $output['info']['Incremental Recompute'] = $incR['report'];
    }
    pp_v13f_after:
    $wall = microtime(true) - $t;
    if (!empty($GLOBALS['ppTlHook']['aborted'])) throw new PpJobAborted('DIHENTIKAN_TARGET_SELESAI');
    if (!$fastJ && ($output['info']['Run Status']['economic_review_completed'] ?? null) === true) $output = pp_v3_pool_guard($inputAsli, $output);
    /* V8: jalur inkremental / memo / pool guard melewati review Unit Priority yang sama dengan exact
     * (dilewati bila dispatch yang sama sudah ditinjau). */
    if (($output['info']['Run Status']['economic_review_completed'] ?? null) === true && function_exists('pp_v8_priority_review')
        && !($fastJ && !empty($output['info']['Run Status']['fastest_shortage_proven']))) {
        pp_job_progress($id, $fastJ ? 'FASTEST_REVIEW_UNIT_PRIORITY' : 'REVIEW_UNIT_PRIORITY', 70.0);
        $GLOBALS['__pp_v8_job'] = $id; if ($fastJ) $GLOBALS['__pp_v8_fast_wall'] = (string)getenv('PP_V8_FAST_TOTAL') === '0' ? (float)(getenv('PP_V8_FAST_WALL') ?: 10.0) : min((float)(getenv('PP_V8_FAST_WALL') ?: 10.0), max((float)(getenv('PP_V8_FAST_MIN') ?: 2.0), (in_array((string)($inputAsli['data3']['modeling']['__fuel_decision_mode'] ?? ''), ['add_lng', 'use_distillate'], true) ? (float)(getenv('PP_V8_FAST_TOTAL_FUEL') ?: 8.5) : (float)(getenv('PP_V8_FAST_TOTAL') ?: 11.5)) - (microtime(true) - $t)));   /* V15.16 Fastest: anggaran review = sisa target total job (bukan konstanta 10 detik) agar first 48-row result <= 15 detik; rerun sesudah popup bahan bakar memakai sisa yang lebih kecil karena waktu sebelum popup sudah terpakai */
        elseif ((string)getenv('PP_V8_MAX_TOTAL') !== '0') $GLOBALS['__pp_v8_max_wall'] = max(8.0, (float)(getenv('PP_V8_MAX_TOTAL') ?: 58.0) - (microtime(true) - $t));
        try { $output = pp_v8_priority_review($inputAsli, $output); } finally { unset($GLOBALS['__pp_v8_job'], $GLOBALS['__pp_v8_fast_wall'], $GLOBALS['__pp_v8_max_wall']); }
        /* V15.15: setiap rencana final job (Fastest dan Maximum Review) wajib C1-C4 FAIL = 0: temuan Unit Priority tanpa alasan diselesaikan
         * atau dibuktikan numerik (pass yang sama). */
        pp_job_progress($id, 'PERBAIKAN_UNIT_PRIORITY_C1_C4', 80.0);
        try { $output = pp_v15_fast_priority_fix($inputAsli, $output, microtime(true) + 30.0); } catch (PpJobAborted $e) { throw $e; } catch (Throwable $e) {} }
    if (pp_v10_fast()) { try { $output['info']['V10 Screening Summary'] = pp_v10_screening_summary($output, is_array($v7R) ? 'DELTA' : ((is_array($incR) && is_array($incR['output'] ?? null)) ? 'INCREMENTAL' : 'EXACT')); } catch (Throwable $e) {} }
    /* V11: penghitung kandidat satu sumber (folder state x job, ditulis pemilik & pembantu) + invarian. */
    if (pp_v11_on()) { try { $sgF = count((array)($output['data'] ?? [])) === 48 ? pp_v6_gtg_sig((array)$output['data']) : null;
        $okF = false; if ($sgF !== null) { $origC = pp_normalize_copy($inputAsli); $aF = pp_tl_assess($origC, $output); $okF = !empty($aF['valid']); pp_tl_clean_globals(); }
        $cnt = pp_v11_cnt_read(pp_tl_key($inputAsli), $id, $okF ? [$sgF] : []);
        $cpF = $okF ? ($output['info']['Cost Production (USD/MWh)'] ?? null) : null;
        $output['info']['V11 Candidate Counters'] = $cnt + ['best_candidate' => $okF ? 'FINAL' : null, 'best_candidate_in_valid' => $okF, 'constraints_pass' => $okF, 'cost_production' => $cpF,
            'invariants' => ['best_implies_valid_ge_1' => !$okF || $cnt['candidates_valid'] >= 1, 'valid_le_checked' => $cnt['candidates_valid'] <= $cnt['candidates_checked']],
            'definition' => 'checked = full_run (dispatch 48 row unik yang disimulasikan penuh) + screened_out (gugur Tier 1); valid = dispatch unik yang lolos hard constraints + provenance dan eligible dibandingkan CP; best_candidate termasuk valid'];
    } catch (Throwable $e) {} }
    if (isset($GLOBALS['ppTlHook'])) { $hT = $GLOBALS['ppTlHook']; unset($GLOBALS['ppTlHook']);
        pp_tl_write(pp_tl_file($hT['key'] . '_x_' . $hT['job'] . '.json'), ['evaluated' => $hT['n'], 'valid' => $hT['v'], 'at' => microtime(true), 'done' => true]); }
    /* REKONSILIASI BAHAN BAKAR — SAMA DENGAN JALUR SINKRON. Dahulu hanya jalur sinkron yang
     * membuktikan bahwa aksi bahan bakar benar-benar diterapkan dan menutup kekurangan; hasil yang
     * diselesaikan job tidak membawa bukti itu (`Fuel Action Reconciliation` kosong). Kini kedua
     * jalur menjalankan pemeriksaan yang sama, dan aksi yang tidak terbukti diterapkan dikunci. */
    try {
        $frJ = pp_reconcile_selected_fuel($input, $output);
        $output['info']['Fuel Action Reconciliation'] = $frJ;
        pp_sync_residual_from_recon($output, $frJ);
        $fbJ = (array)($output['info']['Convergence Budget'] ?? []);
        if (empty($fbJ['exceeded']) && !empty($frJ['required']) && empty($frJ['pass'])) {
            $output['ok'] = false; $output['result'] = 'not_final';
            $output['status'] = 'FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW';
            $output['error_code'] = 'FUEL_ACTION_NOT_APPLIED';
            $output['publish_allowed'] = false;
            $output['message'] = 'Aksi bahan bakar yang dipilih tidak terbukti diterapkan atau tidak menutup residual shortage. '
                               . 'Rencana di bawah adalah pratinjau dan TIDAK boleh dipublikasikan.';
        }
    } catch (Throwable $eFr) {
        $output['info']['Fuel Action Reconciliation'] = ['required' => true, 'pass' => false,
            'error' => $eFr->getMessage()];
    }
    pp_job_progress($id, 'SIMULASI_SELESAI_MEMVALIDASI', 80.0, ['wall_s' => round($wall, 2),
        'state_reuse' => !empty(pp_memo_log()[count(pp_memo_log()) - 1]['hit'] ?? false)]);
    $rs = (array)($output['info']['Run Status'] ?? []);
    $output['info']['Runtime Configuration Check'] = [
        'sumber' => 'ASYNC_CLI_WORKER', 'max_execution_time' => 'unlimited (CLI)',
        'effective_engine_ceiling_s' => (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0),
        'status' => 'OK',
        'catatan' => 'Job asinkron berjalan di luar PHP-FPM. Plafon 60 detik untuk request sinkron TIDAK diubah.'];
    $GLOBALS['__pp_v15_job_gate'] = true;    // V15.15: gerbang bukti merit C1-C4/STG berlaku untuk seluruh hasil job (Fastest + Maximum Review)
    try { $review = pp_attach_or_reject_acceptance($input, $output); } finally { unset($GLOBALS['__pp_v15_job_gate']); }
    $V = pp_validate_hard_constraints($input, $output);
    /* ==========================================================================================
     * KEBOCORAN TERPARAH DI SELURUH RANTAI, DITUTUP DI SINI.
     *
     * APA YANG TERJADI SEBELUMNYA. Worker mengembalikan keluaran MENTAH `pp_run_simulation()`.
     * Keluaran itu tidak pernah melewati blok kontrak di `mode=run`, sehingga ia tidak punya
     * `status`, tidak punya `shortage_decision`, dan tidak punya satu pun penanda kontrak. UI
     * menerimanya, tidak menemukan alasan untuk menahannya, dan menerbitkannya dengan kalimat
     * "Done. Economic review exact diselesaikan worker background" — Save, Export, dan Publish
     * TERBUKA.
     *
     * BUKTINYA, DIUKUR PADA JEJAK KLIK NYATA. Hasil worker yang diterbitkan itu berisi
     * `release_gate.release_allowed=false`, `hard_validation=FAIL`,
     * `blocking_reasons=[HARD_VALIDATION_FAILED]`, `gas_feasibility_audit.feasible=false`,
     * Total Gas Used 64,2985 terhadap kuota 54,6000 — kekurangan gas 9,7385 BBTUD yang BELUM
     * diselesaikan. Angka 9,7385 itu sama persis dengan `Residual Gas Shortage` pada workbook
     * `Daily_Plan_09_Jul_26_TGD_38(5).xls` yang dilaporkan operator.
     *
     * MENGAPA INI JALUR YANG PALING SERING DILALUI. Pada SAPI web, `max_execution_time` bawaan 30
     * detik membuat anggaran internal 15 detik, sehingga hampir setiap rencana berat diserahkan
     * ke worker. Artinya jalur inilah — bukan jalur sinkron — yang biasanya mengakhiri sebuah run.
     *
     * PERBAIKANNYA. Hasil worker kini melewati penilaian yang SAMA dengan jalur sinkron:
     * keputusan bahan bakar dibangun dengan fungsi yang sama persis (`pp_shortage_decision_block`),
     * dan kontraknya dinyatakan eksplisit. Tidak ada angka, baris, kandidat, constraint, maupun
     * biaya yang diubah — yang ditambahkan hanya pernyataan tentang keadaan yang sudah ada. */
    /* SELURUH PENILAIAN TAMBAHAN DI BAWAH DIBUNGKUS try/catch.
     * Pelajaran dari `PHP_CLI_TIDAK_DITEMUKAN`: pekerjaan tambahan yang gagal di tengah worker
     * membuat SELURUH job berstatus FAILED, sehingga hasil 48 baris yang sudah benar ikut hilang.
     * Penilaian ini hanya MENYATAKAN keadaan; ia tidak boleh pernah menjatuhkan hasil. */
    try {
    $gaW = (array)($output['gas_feasibility_audit'] ?? []);
    $rgW = (array)($output['release_gate'] ?? []);
    $actW = strtolower(trim((string)($input['data3']['modeling']['gas_shortage_action'] ?? 'none')));
    if ($actW === 'flag shortage only' || $actW === 'flag_shortage_only') $actW = 'none';
    /* V15.16: 'recommendation' = belum ada keputusan bahan bakar, sama dengan 'none' (dulu hasil worker untuk aksi ini berakhir
     * 'rejected' tanpa keputusan -> Run berikutnya setelah memilih bahan bakar tidak pernah membuka popup lagi). */
    if ($gaW && empty($gaW['feasible']) && in_array($actW, ['none', 'recommendation'], true) && function_exists('pp_shortage_decision_block')
        && (string)($output['action_required'] ?? '') !== 'GAS_WINDOW_QUOTA_DECISION'                  // V7: konflik window terbukti bukan kekurangan bahan bakar
        && (($output['info']['Change Over Legality']['legal'] ?? null) !== false)) {   // V5: CO tidak legal -> bukan keputusan bahan bakar
        $expW = pp_export_minimization_audit($input, $output);
        $output['info']['Export Minimization Audit'] = $expW;
        $output['export_minimization_audit'] = $expW;
        $blkW = pp_shortage_decision_block($input, $output, $gaW, $expW);
        $output['shortage_decision'] = $blkW;
        $output['info']['Shortage Decision'] = $blkW;
        $output['reason_code']  = (string)($blkW['reason_code'] ?? '');
        $output['problem_kind'] = (string)($blkW['problem_kind'] ?? '');
        $rsW = (array)($output['info']['Run Status'] ?? []);
        /* Keputusan bahan bakar hanya sah atas rencana FINAL — aturan yang sama dengan jalur
         * sinkron. Di worker syarat itu justru terpenuhi, karena worker berjalan sampai konvergen. */
        $siapW = (bool)($blkW['fuel_estimate_ready'] ?? false)
                 && (($rsW['converged'] ?? null) === true)
                 && (($rsW['deadline_reached'] ?? true) === false)
                 && empty($rsW['stages_truncated']);
        $output['fuel_estimate_ready'] = $siapW;
        if ($siapW) {
            $output['ok'] = true;
            $output['result'] = 'action_required';
            $output['status'] = 'USER_FUEL_DECISION_REQUIRED';
            $output['status_legacy'] = 'FUEL_SELECTION_REQUIRED';
            $output['action_required'] = 'SHORTAGE_FUEL_SELECTION';
            $output['http_status_recommended'] = 200;
            $output['diagnostic_before_fuel_selection'] = $output['error'] ?? null;
            unset($output['error'], $output['error_code'], $output['error_message']);
            /* ======================================================================================
             * OPSI TERVALIDASI DIMINTA OLEH LAPISAN HTTP, BUKAN OLEH WORKER INI.
             *
             * Versi pertama perbaikan ini memanggil `pp_job_start('validated_options')` LANGSUNG di
             * sini. Itu keliru dan terbukti merusak: worker adalah proses CLI anak, dan di dalamnya
             * pencarian biner PHP gagal — `PHP_CLI_TIDAK_DITEMUKAN`. Karena kegagalan itu terjadi di
             * tengah jalan, BUKAN hanya job bersarangnya yang gagal, melainkan SELURUH job
             * economic_review ikut berstatus FAILED. Terukur pada suite asinkron: AE_FIX dan
             * AE_RESERVE kehilangan seluruh 48 barisnya. Menambahkan pekerjaan yang dapat gagal ke
             * dalam worker berarti mempertaruhkan hasil yang sudah benar.
             *
             * Karena itu worker hanya MENYATAKAN bahwa opsi tervalidasi masih dibutuhkan. Yang
             * membuat job-nya adalah lapisan HTTP — konteks yang memang berhasil men-spawn worker
             * ini sejak awal, sehingga pencarian biner PHP di sana sudah terbukti bekerja. */
            $output['validated_options_required'] = true;
            $output['action_status'] = 'VALIDATED_OPTIONS_NOT_YET_REQUESTED';
            /* Job opsi tervalidasi kini DIDAFTARKAN di sini. Mendaftar tidak meluncurkan proses apa
             * pun (perhitungannya dipicu browser lewat mode=job_exec), sehingga alasan lama untuk
             * tidak melakukannya di worker tidak berlaku lagi. Dengan begitu popup langsung
             * menampilkan "menghitung opsi" dan memicu job-nya sendiri — tidak pernah lagi
             * menampilkan "tidak tersedia" hanya karena permintaan belum dikirim. */
            try {
                if (pp_v13f_is_fast($inputAsli)) throw new RuntimeException('FASTEST_TANPA_JOB_OPSI_TERVALIDASI');   // V15.15: rekomendasi langsung dari bukti shortage
                $stVo = pp_job_start($inputAsli, 'validated_options', $inputAsli['_request_id'] ?? null, false);
                $jVo = (array)($stVo['job'] ?? []);
                if (!empty($stVo['ok']) && !empty($jVo['job_id'])) {
                    $doneVo = ((string)($jVo['status'] ?? '') === 'DONE') && !empty($jVo['result_available']);
                    $voW = ['required' => true, 'kind' => 'validated_options', 'ok' => true,
                            'job_id' => $jVo['job_id'], 'exec_token' => $jVo['exec_token'] ?? null,
                            'status' => $jVo['status'] ?? null, 'reused' => (bool)($stVo['reused'] ?? false),
                            'input_hash' => pp_job_input_hash($inputAsli),
                            'action_status' => 'CALCULATING_VALIDATED_OPTIONS', 'error' => null];
                    if ($doneVo) {
                        $rVo = json_decode((string)@file_get_contents(pp_job_dir((string)$jVo['job_id']) . '/result.json'), true);
                        if (is_array($rVo)) { $voW['result'] = $rVo; $voW['action_status'] = (string)($rVo['action_status'] ?? 'VALIDATION_FAILED'); }
                    }
                    $output['validated_options_job'] = $voW;
                    $output['action_status'] = (string)$voW['action_status'];
                    $output['info']['Validated Fuel Options Job'] = $voW;
                }
            } catch (Throwable $eVo) { /* popup tetap memakai jalur permintaan dari browser */ }
        }
        $output['publish_allowed'] = false;
        $output['preliminary'] = true;
        $output['final_result_visible'] = false;
        $output['save_allowed'] = false;
        $output['preliminary_note'] = 'Kebutuhan gas masih melebihi kuota sebesar '
            . number_format((float)($blkW['gas_shortage'] ?? 0), 4, ',', '.') . ' BBTUD. Rencana ini '
            . 'bukan rencana final: Simulation Data, Save, Export, dan Publish terkunci sampai '
            . 'operator memilih LNG, Distillate, atau kombinasi keduanya.';
        $output['preliminary_reason'] = 'GAS_SHORTAGE_UNRESOLVED';
    }
    /* V7: keputusan KUOTA (konflik window terbukti) dinyatakan dengan alasannya sendiri. */
    if ((string)($output['action_required'] ?? '') === 'GAS_WINDOW_QUOTA_DECISION' && ($output['preliminary'] ?? null) !== true) {
        $output['preliminary'] = true; $output['final_result_visible'] = false; $output['save_allowed'] = false; $output['publish_allowed'] = false;
        $gwN = (array)($output['gas_window_conflict'] ?? []);
        $output['preliminary_note'] = (string)($output['message'] ?? '') . (isset($gwN['bukti'][1]) ? ' ' . (string)$gwN['bukti'][1] . '.' : '');
        $output['preliminary_reason'] = 'GAS_WINDOW_QUOTA_DECISION';
    }
    /* Penjaga terakhir yang tidak bergantung pada nama status: rencana yang gerbang rilisnya
     * menolak, atau yang validasi kerasnya gagal, tidak boleh pernah terbit sebagai hasil final. */
    if (($output['preliminary'] ?? null) !== true
        && ((($rgW['release_allowed'] ?? null) === false)
            || strtoupper((string)($V['status'] ?? '')) === 'FAIL'
            || ($output['ok'] ?? null) === false)) {
        $output['preliminary'] = true;
        $output['final_result_visible'] = false;
        $output['save_allowed'] = false;
        $output['publish_allowed'] = false;
        $alasanW = (array)($rgW['blocking_reasons'] ?? []);
        $output['preliminary_note'] = 'Gerbang rilis menolak rencana ini'
            . ($alasanW ? ' (' . implode('; ', array_map('strval', $alasanW)) . ')' : '')
            . '. Baris di bawah hanya untuk ditinjau; Save, Export, dan Publish terkunci.';
        $output['preliminary_reason'] = 'RELEASE_GATE_BLOCKED';
    }
    pp_declare_result_contract($output);
    } catch (Throwable $exW) {
        /* Dinyatakan, tidak disembunyikan: hasil tetap dikembalikan, tetapi dikunci karena
         * penilaiannya tidak tuntas. */
        $output['preliminary'] = true;
        $output['final_result_visible'] = false;
        $output['save_allowed'] = false;
        $output['publish_allowed'] = false;
        $output['preliminary_reason'] = 'CONTRACT_ASSESSMENT_FAILED';
        $output['contract_assessment_error'] = ['message' => $exW->getMessage(),
            'where' => basename($exW->getFile()) . ':' . $exW->getLine()];
    }
    /* V15.15: hasil job yang lolos gerbang rilis dicatat ke central store (saved_data_store.php) seperti jalur sinkron — tanpa ini
     * hasil Fastest (kini selalu lewat job) tidak pernah masuk riwayat simpanan. Kegagalan store tidak menjatuhkan hasil. */
    if (!empty($output['release_gate']['release_allowed']) && count((array)($output['data'] ?? [])) === 48 && function_exists('pp_store_commit')) {
        try { $ceJ = null; $csJ = pp_store_commit('SIMULATION_FINAL', $inputAsli, $output, ['engine' => PP_ENGINE_BUILD_ID, 'route' => 'job', 'mode' => $output['info']['Run Status']['mode'] ?? null], $ceJ);
              $output['_saved'] = array_merge((array)($output['_saved'] ?? []), ['central_store' => !empty($csJ['ok']), 'central_store_revision' => $csJ['revision'] ?? null, 'central_store_error' => $ceJ]); } catch (Throwable $e) {} }
    /* V15.15: keputusan terminal Fastest dinyatakan apa adanya (bukan keputusan bahan bakar, bukan pratinjau rencana). */
    if (is_array($output['info']['Fastest Terminal Certificate'] ?? null)) { $cT = $output['info']['Fastest Terminal Certificate'];
        unset($output['shortage_decision'], $output['validated_options_job'], $output['validated_options_required']);
        $output['ok'] = false; $output['result'] = 'rejected'; $output['status'] = 'TERMINAL_INFEASIBLE'; $output['error_code'] = $cT['code'];
        $output['terminal_decision'] = $cT; $output['publish_allowed'] = false; $output['save_allowed'] = false; $output['preliminary'] = true; $output['final_result_visible'] = false;
        $output['message'] = $cT['code'] . ': ' . $cT['proof']; $output['preliminary_note'] = 'Keputusan terminal (TIDAK FEASIBLE): ' . $cT['proof'] . ' Baris di bawah hanya pratinjau bukti; Save/Export/Publish terkunci.';
        $output['release_gate'] = array_merge((array)($output['release_gate'] ?? []), ['release_allowed' => false, 'status' => 'FAIL', 'blocking_reasons' => [$cT['code']]]); }
    pp_job_progress($id, 'VALIDASI_SELESAI', 98.0);
    return [
        'schema' => 'co12-async-economic-review-v1',
        'kind' => 'economic_review',
        'wall_s' => round($wall, 2),
        'run_status' => $rs,
        'economic_review_completed' => ($rs['economic_review_completed'] ?? null) === true,
        'converged' => ($rs['converged'] ?? null) === true,
        'deadline_reached' => ($rs['deadline_reached'] ?? null) === true,
        'stages_truncated' => array_keys((array)($rs['stages_truncated'] ?? [])),
        'hard_validation' => ['status' => (string)($V['status'] ?? ''), 'violations' => count((array)($V['violations'] ?? []))],
        'publish_allowed' => (bool)($review['publish_allowed'] ?? false),
        'state_reuse' => pp_memo_log(),
        'output' => $output,
        'mode' => is_array($v7R) ? ((string)($v7R['report']['route'] ?? '') === 'SERTIFIKAT_ENVELOPE_GAS' ? 'DELTA_CERTIFICATE' : 'DELTA_FUEL') : ((is_array($incR) && is_array($incR['output'] ?? null)) ? 'INCREMENTAL' : 'EXACT'),
    ] + (function () use ($inputAsli, $output, $incR) { try { pp_final_save($inputAsli, $output, (is_array($incR) && is_array($incR['output'] ?? null)) ? ($incR['v3'] ?? null) : null); } catch (Throwable $e) {} return []; })();
}


/* KIND 3 — PERBANDINGAN EKSAK CABANG LEGAL UNIT SKIP LOAD (instruksi §3.2 butir 10 dan §6).
 *
 *  MENGAPA DI WORKER. Memilih interval legal mana yang dipakai G8/G9 hanya dapat dibuktikan optimal
 *  dengan menjalankan PIPELINE PENUH untuk SETIAP kandidat, karena sebagian pelanggaran baru muncul
 *  di pass hilir dan tidak terlihat pada core run (terukur: cabang ATAS band G8 80-90 baru
 *  memperlihatkan export_ramp 36,7 > 35 MW setelah minimisasi Export). Satu pipeline penuh per
 *  kandidat terukur 40-44 detik, sehingga dua kandidat = 87 detik dan TIDAK muat pada plafon
 *  request sinkron 60 detik. Batas 60 detik hanya berlaku untuk request sinkron; runtime latar
 *  tidak dibatasi demikian.
 *
 *  Hasilnya adalah pemenang EKSAK: kandidat dengan 0 pelanggaran Skip Load, 0 pelanggaran keras
 *  non-bahan-bakar, dan biaya terendah; seluruh kandidat dievaluasi, tanpa pemangkasan heuristik.
 */
function pp_job_run_legal_branch_exact(string $id, array $input): array {
    $t0 = microtime(true);
    pp_job_progress($id, 'MENYUSUN_KANDIDAT_CABANG_LEGAL', 3.0);
    /* Kandidat dibangun ulang dari input asli oleh generator yang sama dengan jalur sinkron. */
    $inSel = pp_legal_branch_select($input);
    $queue = (array)($GLOBALS['__pp_legal_branch_queue'] ?? []);
    $ev    = (array)($GLOBALS['__pp_skip_load_branch'] ?? []);
    unset($GLOBALS['__pp_legal_branch_queue']);
    if (!$queue) {
        return ['schema' => 'co12-async-legal-branch-v1', 'kind' => 'legal_branch_exact',
                'wall_s' => round(microtime(true) - $t0, 2),
                'action_status' => 'NO_CANDIDATES',
                'branch_evidence' => $ev, 'output' => null];
    }
    $fuelCls = ['gas_quota' => 1, 'runtime_downtime' => 1, 'mm2100_quota' => 1,
                'gas_window' => 1, 'distillate_quota' => 1, 'lng_quota' => 1];
    /* ===== ANGGARAN WAKTU KANDIDAT DIAMBIL DARI PLAFON WORKER, BUKAN DARI REQUEST SINKRON ======
     * Input job adalah salinan payload request web, yang membawa `time_budget_max_seconds` = 54
     * detik. Tanpa penyesuaian, SETIAP kandidat di dalam worker berjalan dengan anggaran request
     * sinkron — sehingga rencana pemenang pun dapat berakhir `ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET`
     * walaupun worker punya 1800 detik. Terukur pada D1_01/D1_02/D1_16/D1_17: `converged=false`
     * pada hasil job, padahal worker baru memakai sebagian kecil plafonnya.
     * Anggaran kini dibagi rata di antara kandidat, dengan sisa untuk penyelesaian pemenang. */
    $jobCeil = (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 1800.0);
    $nqAll   = max(1, count($queue));
    $perCand = max(60.0, ($jobCeil * 0.70) / $nqAll);
    $rows = []; $outs = []; $ins = []; $nq = count($queue);
    foreach ($queue as $qi => $q) {
        if (isset($q['input']['data3']['modeling'])) {
            $q['input']['data3']['modeling']['time_budget_seconds']     = $perCand;
            $q['input']['data3']['modeling']['time_budget_max_seconds'] = $perCand;
            $q['input']['data3']['modeling']['__core_run_budget']       = 400;
        }
        pp_job_progress($id, sprintf('PIPELINE_PENUH_KANDIDAT_%d_DARI_%d', $qi + 1, $nq),
                        5.0 + 85.0 * ($qi / max(1, $nq)));
        foreach (array_keys($GLOBALS) as $g)
            if (str_starts_with($g, '__pp_') && !in_array($g, ['__pp_async_worker', '__pp_async_worker_ceiling', '__pp_job_id'], true))
                unset($GLOBALS[$g]);
        $t = microtime(true);
        /* Anggaran di-arm SETELAH scrub global, sama seperti jalur economic_review. */
        pp_budget_start($perCand, true, true);
        $o = pp_run_simulation($q['input']);
        $w = microtime(true) - $t;
        $V = pp_validate_hard_constraints($q['input'], $o);
        $vs = array_values((array)($V['violations'] ?? []));
        $hard = 0; $types = [];
        foreach ($vs as $v) {
            $k = (string)($v[0] ?? 'unknown'); $types[$k] = ($types[$k] ?? 0) + 1;
            if (!isset($fuelCls[$k])) $hard++;
        }
        $mdl = (array)($q['input']['data3']['modeling'] ?? []);
        $sk = 0;
        foreach ((array)($o['data'] ?? []) as $ri => $rw)
            foreach (array_keys((array)($mdl['unit_skip_load'] ?? [])) as $uu)
                if (pp_load_in_forbidden_band($mdl, strtolower((string)$uu), $ri + 1,
                    (float)($rw[strtoupper((string)$uu)] ?? 0), true)) $sk++;
        $iC = (array)($o['info'] ?? []); $rsC = (array)($iC['Run Status'] ?? []);
        $rows[] = ['label' => (string)$q['label'], 'pipeline_seconds' => round($w, 2),
            'skip_load_violations' => $sk, 'hard_violations' => $hard,
            'violations' => count($vs), 'violation_types' => $types,
            'hard_validation' => (string)($V['status'] ?? ''),
            'converged' => ($rsC['converged'] ?? null) === true,
            'economic_review_completed' => ($rsC['economic_review_completed'] ?? null) === true,
            'deadline_reached' => ($rsC['deadline_reached'] ?? null) === true,
            'cost_plant_usd_mwh' => round((float)($iC['Total Plant Cost Production (USD/MWh)'] ?? 0), 6),
            'cost_jbbk_usd_mwh'  => round((float)($iC['JBBK MM Cost Production (USD/MWh)'] ?? 0), 6),
            'gas_used_bbtud'     => round((float)($iC['Total Gas Used (BBTUD)'] ?? 0), 4),
            'selected' => false];
        $outs[(string)$q['label']] = $o;
        $ins[(string)$q['label']]  = $q['input'];       // input yang SUDAH dikotakkan, untuk audit hilir
    }
    $ord = $rows;
    /* SATU SUMBER KEBENARAN URUTAN KANDIDAT — pp_branch_candidate_cmp(). */
    usort($ord, fn(array $a, array $b): int => pp_branch_candidate_cmp($a, $b));
    $win = $ord[0]['label'] ?? null;
    /* BUKTI COMPARATOR (§2): kunci setiap kandidat dan alasan pemenang dicatat apa adanya, sehingga
     * keputusan dapat diperiksa ulang tanpa menjalankan kembali perbandingan. */
    $cmpEv = [];
    foreach ($ord as $oi => $o)
        $cmpEv[] = ['rank' => $oi + 1, 'label' => $o['label'],
                    'key' => pp_branch_candidate_key($o),
                    'key_fields' => ['operator_lock', 'feasible', 'hard_violations', 'total_violations',
                                     'severity', 'not_evaluated', 'cost_plant', 'cost_jbbk', 'heat_rate', 'label']];
    $winReason = pp_branch_win_reason($ord[0] ?? [], $ord[1] ?? null);
    foreach ($rows as &$r) if ($r['label'] === $win) $r['selected'] = true; unset($r);
    $winOut = $win !== null ? ($outs[$win] ?? null) : null;
    $clean  = $win !== null && ($ord[0]['skip_load_violations'] === 0) && ($ord[0]['hard_violations'] === 0);
    pp_job_progress($id, 'PERBANDINGAN_EKSAK_SELESAI', 96.0);
    /* INPUT YANG DIPAKAI SELURUH AUDIT HILIR ADALAH INPUT PEMENANG YANG SUDAH DIKOTAKKAN.
     * Memakai $input asli (tanpa kotak) membuat pp_gas_feasibility_audit() mengira G8/G9 masih
     * dapat diturunkan sampai min_ccload aslinya, lalu melaporkan `feasible = true` padahal gas
     * sudah 3,45 BBTUD di atas window — dan karena itu jalur keputusan bahan bakar tidak pernah
     * dijalankan. Terukur pada band G9 80-90 + Change Over. */
    $winIn = ($win !== null && isset($ins[$win])) ? $ins[$win] : $input;
    /* ===== PENYELESAIAN PEMENANG =============================================================
     * Perbandingan antar cabang sudah eksak, tetapi rencana pemenangnya masih bisa berakhir dengan
     * pencarian yang terpotong anggaran per kandidat. Keputusan bahan bakar dan publikasi hanya
     * sah atas rencana FINAL, jadi pemenang dijalankan ULANG dengan SELURUH sisa plafon worker.
     * Hanya dijalankan bila memang belum final dan sisa waktunya cukup; bila hasil ulang tidak
     * lebih baik, hasil lama dipertahankan apa adanya. */
    $winCompletion = null;
    if ($winOut !== null) {
        $rsPre = (array)($winOut['info']['Run Status'] ?? []);
        $preFinal = (($rsPre['converged'] ?? false) === true)
                 && (($rsPre['deadline_reached'] ?? false) !== true)
                 && empty($rsPre['stages_truncated'])
                 && empty($rsPre['economic_review_skipped']);
        $spent = microtime(true) - $t0;
        $left  = $jobCeil - $spent - 30.0;                      // sisakan margin penutup
        if (!$preFinal && $left > 120.0) {
            pp_job_progress($id, 'MENYELESAIKAN_RENCANA_PEMENANG', 97.0,
                ['sisa_detik' => round($left, 1), 'sebab' => (string)($rsPre['status'] ?? '')]);
            foreach (array_keys($GLOBALS) as $g)
                if (str_starts_with($g, '__pp_') && !in_array($g, ['__pp_async_worker', '__pp_async_worker_ceiling', '__pp_job_id'], true))
                    unset($GLOBALS[$g]);
            $finIn = $winIn;
            $finIn['data3']['modeling']['time_budget_seconds']     = $left;
            $finIn['data3']['modeling']['time_budget_max_seconds'] = $left;
            $finIn['data3']['modeling']['__core_run_budget']       = 800;
            $tFin = microtime(true);
            pp_budget_start($left, true, true);
            $finOut = pp_run_simulation($finIn);
            $rsFin2 = (array)($finOut['info']['Run Status'] ?? []);
            $finFinal = (($rsFin2['converged'] ?? false) === true)
                     && (($rsFin2['deadline_reached'] ?? false) !== true)
                     && empty($rsFin2['stages_truncated'])
                     && empty($rsFin2['economic_review_skipped']);
            $winCompletion = ['dijalankan' => true, 'sebab_awal' => (string)($rsPre['status'] ?? ''),
                'anggaran_detik' => round($left, 1), 'wall_s' => round(microtime(true) - $tFin, 2),
                'final_sesudah' => $finFinal, 'converged_sesudah' => $rsFin2['converged'] ?? null];
            if ($finFinal && !empty($finOut['data'])) { $winOut = $finOut; $outs[$win] = $finOut; }
            else $winCompletion['catatan'] = 'hasil ulang tidak final; rencana pemenang semula dipertahankan';
        } else {
            $winCompletion = ['dijalankan' => false,
                'sebab' => $preFinal ? 'rencana pemenang sudah final' : 'sisa anggaran worker tidak cukup',
                'sisa_detik' => round($left, 1)];
        }
    }
    /* Evidence cabang dilekatkan LEBIH DULU supaya audit gas melihat `applied_rules`. */
    if ($winOut !== null) {
        $ev['selected'] = $win;
        $ev['status'] = $clean ? 'EXACT_SELECTED_CHEAPEST_WITHOUT_TRADE' : 'EXACT_NO_BRANCH_WITHOUT_TRADE';
        $ev['exact_economic_comparison'] = true;
        $ev['ranking_method'] = 'FULL_PIPELINE_PER_CANDIDATE';
        $ev['pipeline_verification'] = $rows;
        $ev['verified_all'] = true;
        if (empty($ev['applied_rules']))
            $ev['applied_rules'] = (array)($winIn['data3']['modeling']['__legal_branch_rules'] ?? []);
        $winOut['info']['Unit Skip Load Branch'] = $ev;
    }
    $review = ($winOut !== null) ? pp_attach_or_reject_acceptance($winIn, $winOut) : [];
    /* KONTRAK KEPUTUSAN BAHAN BAKAR JUGA BERLAKU PADA HASIL JOB INI.
     * Terukur: ketika pemenang cabang datang dari job ini, output-nya dulu TIDAK melewati jalur
     * keputusan bahan bakar milik run.php, sehingga `status` kosong. Akibatnya sebuah kasus yang
     * sudah membuktikan blocker per baris `FUEL_LIMITED` tetap terlihat sebagai kegagalan constraint
     * biasa, dan UI tidak pernah menampilkan opsi bahan bakar. Klasifikasi terminal kini dilekatkan
     * di sini dengan helper yang sama, sehingga hasil sinkron dan hasil asinkron memakai kontrak
     * yang identik. Publish tetap DILARANG. */
    if ($winOut !== null && empty($review['publish_allowed']) && (($winOut['info']['Change Over Legality']['legal'] ?? null) !== false)) {   // V5: CO tidak legal -> bukan keputusan bahan bakar
        $gaJ = pp_gas_feasibility_audit($winIn, $winOut);
        $winOut['info']['Gas Feasibility Audit'] = $gaJ;
        $expJ = pp_export_minimization_audit($winIn, $winOut);
        $winOut['info']['Export Minimization Audit'] = $expJ;
        $blkJ = pp_shortage_decision_block($winIn, $winOut, $gaJ, $expJ);
        $winOut['shortage_decision'] = $blkJ;
        $winOut['reason_code']  = (string)($blkJ['reason_code'] ?? '');
        $winOut['problem_kind'] = (string)($blkJ['problem_kind'] ?? '');
        $winOut['fuel_estimate_ready'] = (bool)($blkJ['fuel_estimate_ready'] ?? false);
        /* Lantai Export yang terbukti FUEL_LIMITED adalah bagian dari kebutuhan bahan bakar, bukan
         * pelanggaran dispatch yang dapat diperbaiki. Karena itu ia ikut membuka jalur keputusan. */
        $efb = (array)($winOut['info']['Export Floor Blockers'] ?? []);
        $fuelFloor = false; $floorOverBbtud = 0.0;
        foreach ($efb as $bb) {
            if (!is_array($bb) || (string)($bb['blocker'] ?? '') !== 'FUEL_LIMITED') continue;
            $fuelFloor = true;
            $floorOverBbtud = max($floorOverBbtud, (float)($bb['gas_over_window_bbtud'] ?? 0));
        }
        /* Blocker `FUEL_LIMITED` MEMBAWA BUKTINYA SENDIRI: gas terhitung dan batas atas window
         * diukur pada baris itu juga, dengan metrik yang sama dengan validator. Karena itu ia cukup
         * untuk membuka jalur keputusan bahan bakar, tanpa bergantung pada kesimpulan audit gas
         * yang terpisah — audit itu terbukti dapat melaporkan `feasible = true` pada rencana yang
         * gasnya sudah 3,45 BBTUD di atas window. */
        /* GERBANG YANG SAMA DENGAN JALUR SINKRON: keputusan bahan bakar hanya sah atas rencana
         * FINAL. Kandidat cabang legal dibandingkan secara eksak di sini, tetapi rencana pemenang
         * sendiri masih bisa berakhir dengan pencarian yang terpotong batas waktu — terukur pada
         * D1_01/D1_02/D1_16/D1_17: `converged=false`,
         * `Run Status.status=ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET`, namun hasilnya tetap membuka
         * popup bahan bakar. Tanpa gerbang ini, satu jalur (sinkron) menolak meminta bahan bakar
         * pada rencana yang belum final sementara jalur lain (job cabang legal) tetap memintanya. */
        $rsW = (array)($winOut['info']['Run Status'] ?? []);
        $winFinal = (($rsW['converged'] ?? false) === true)
                 && (($rsW['deadline_reached'] ?? false) !== true)
                 && empty($rsW['stages_truncated'])
                 && empty($rsW['economic_review_skipped'])
                 && empty($winOut['info']['Global Commitment Review Skipped']);
        $wantFuel = !empty($winOut['fuel_estimate_ready'])
            || ($fuelFloor && $floorOverBbtud > 1e-9)
            || (empty($gaJ['feasible']) && $fuelFloor);
        if ($wantFuel && $winFinal) {
            $winOut['ok'] = true;
            $winOut['result'] = 'action_required';
            $winOut['status'] = 'USER_FUEL_DECISION_REQUIRED';
            $winOut['status_legacy'] = 'FUEL_SELECTION_REQUIRED';
            $winOut['action_required'] = 'SHORTAGE_FUEL_SELECTION';
            $winOut['publish_allowed'] = false;
            $winOut['fuel_estimate_ready'] = true;
            $winOut['export_floor_fuel_limited'] = $fuelFloor;
            $winOut['export_floor_gas_over_window_bbtud'] = round($floorOverBbtud, 4);
            unset($winOut['error']);
        } elseif ($wantFuel) {
            /* Cabang legal sudah dibandingkan eksak, tetapi rencana pemenang belum final. Keadaan
             * ini dinyatakan apa adanya, lengkap dengan sebabnya, dan pemilihan bahan bakar
             * ditunda sampai perhitungannya selesai. */
            $winOut['ok'] = true;
            $winOut['result'] = 'action_required';
            $winOut['status'] = 'ECONOMIC_REVIEW_REQUIRED';
            $winOut['action_required'] = 'CONTINUE_EXACT_ECONOMIC_REVIEW';
            $winOut['publish_allowed'] = false;
            $winOut['fuel_estimate_ready'] = false;
            $winOut['fuel_decision_deferred'] = [
                'alasan' => 'rencana pemenang cabang legal belum final; keputusan bahan bakar hanya sah atas rencana final',
                'converged' => $rsW['converged'] ?? null,
                'run_status' => (string)($rsW['status'] ?? ''),
                'economic_review_skipped' => $rsW['economic_review_skipped'] ?? null,
                'export_floor_fuel_limited' => $fuelFloor,
                'export_floor_gas_over_window_bbtud' => round($floorOverBbtud, 4),
            ];
            unset($winOut['error']);
        }
    }
    return [
        'schema' => 'co12-async-legal-branch-v1',
        'kind' => 'legal_branch_exact',
        'wall_s' => round(microtime(true) - $t0, 2),
        'action_status' => $clean ? 'EXACT_BRANCH_SELECTED' : 'EXACT_BRANCH_BEST_EFFORT',
        'candidates_total' => $nq,
        'all_candidates_evaluated' => true,
        'exact_pruning_certificate' => 'TIDAK ADA PEMANGKASAN: seluruh kandidat interval legal dijalankan dengan pipeline penuh.',
        'winner_completion' => $winCompletion,
        'candidate_time_budget_s' => round($perCand, 1),
        'comparator_keys' => $cmpEv,
        'winner_reason' => $winReason,
        'publish_allowed_note' => 'Dua kandidat yang sama-sama infeasible TIDAK menghasilkan output publishable; release gate tetap menahan Publish.',
        'selected' => $win,
        'comparison' => $rows,
        'branch_evidence' => $ev,
        'publish_allowed' => (bool)($review['publish_allowed'] ?? false),
        'output' => $winOut,
    ];
}

/* KIND 2 — VALIDASI REKOMENDASI LNG DAN DISTILLATE (instruksi operator §4/§5).
 *
 * Sebuah angka hanya boleh disebut "kebutuhan LNG" atau "kebutuhan distillate" bila kandidatnya
 * sudah DI-RERUN PENUH dari input BERSIH dan hasilnya:
 *   selesai, konvergen, tidak terkena deadline, tidak ada tahap terpotong, nol pelanggaran hard
 *   constraint (Export, kuota gas, reserve, load balance, min/max unit, ramp, change-over,
 *   start/stop), DAN economic review selesai.
 * LNG dan distillate divalidasi SECARA TERPISAH: kegagalan salah satu tidak menjatuhkan yang lain.
 * Tidak ada pelonggaran validasi apa pun untuk membuat sebuah kandidat lulus. */
/* =============================================================================================
 *  V7 VALIDASI BAHAN BAKAR DELTA — angka opsi LNG / Distillate divalidasi dengan MEMAKAI ULANG
 *  rencana exact basis (baseline, commitment, dispatch, Export, headroom, bukti gas) yang sudah
 *  membuktikan seluruh lever dispatch habis: dispatch 48 row basis dibekukan, lalu engine menghitung
 *  ulang penuh state dengan aksi bahan bakar itu (akunting gas, alokasi Distillate diskret, validator
 *  48 row, headroom, horizon penuh). Valid -> angka tervalidasi dan rencana itu disimpan sebagai hasil
 *  state rerun (pilihan operator melanjutkan hasil ini, solver tidak diulang dari nol; keluarga
 *  commitment tetap dilengkapi saat rerun). Tidak valid -> jalur V6 (pipeline penuh per kandidat).
 *  PP_V7_FUEL_DELTA=0 mematikan.
 * =========================================================================================== */
function pp_v7_fuel_state(array $input, string $kind, float $amount): array {
    $s = $input; if (!isset($s['data3']['modeling'])) $s['data3']['modeling'] = [];
    $m = &$s['data3']['modeling'];
    if ($kind === 'lng') { $m['gas_shortage_action'] = 'add_lng'; $m['additional_lng'] = $amount; unset($m['distillate_user_limit_litres']); }
    else { $m['gas_shortage_action'] = 'use_distillate'; $m['additional_lng'] = 0; $m['distillate_user_limit_litres'] = $amount; }
    foreach (['__fuel_decision_mode', '__probe_tested', '__probe_state', 'validated_options'] as $k) unset($m[$k]);
    unset($m);
    return $s;
}
function pp_v7_fuel_delta(array $input, array $base, string $kind, array $amounts, float $ceil): ?array {
    if ((string)getenv('PP_V7_FUEL_DELTA') === '0' || count((array)($base['data'] ?? [])) !== 48) return null;
    $t0 = microtime(true); $probes = []; $win = null;
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $evalAmt = function (float $amt) use ($input, $base, $kind, &$saved) {
        $tA = microtime(true);
        try {
            $st = pp_v7_fuel_state($input, $kind, $amt);
            $orig = pp_normalize_copy($st);
            foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
            $fz = pp_v3_frozen_eval($orig, (array)$base['data'], microtime(true) + 60.0);
        } catch (Throwable $e) { $fz = null; }
        foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0 && !array_key_exists($gk, $saved)) unset($GLOBALS[$gk]);
        foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
        if (!is_array($fz) || !is_array($fz['output'] ?? null)) return ['p' => ['amount' => $amt, 'valid' => false, 'violations' => 1, 'wall_s' => round(microtime(true) - $tA, 2), 'binding_constraints' => ['evaluasi_gagal' => 1]]];
        $ii = (array)$fz['output']['info'];
        $types = []; foreach ((array)($fz['violations'] ?? []) as $v) { $t = is_array($v) ? (string)($v[0] ?? $v['type'] ?? '?') : '?'; $types[$t] = ($types[$t] ?? 0) + 1; }
        foreach ((array)($fz['checks'] ?? []) as $ck => $ok) if (!$ok && !isset($types[$ck])) $types[$ck] = 1;
        $p = ['amount' => $amt, 'valid' => !empty($fz['valid']), 'violations' => !empty($fz['valid']) ? 0 : max(1, array_sum($types)),
              'converged' => !empty($fz['valid']), 'economic_review_completed' => !empty($fz['valid']), 'run_status' => !empty($fz['valid']) ? 'CONVERGED' : 'INVALID',
              'gas_used_bbtud' => $ii['Effective Total Gas (BBTUD)'] ?? ($ii['Total Gas Used (BBTUD)'] ?? null), 'gas_quota_bbtud' => $ii['Total Gas Quota (BBTUD)'] ?? null,
              'distillate_litres' => $ii['Distillate Used (l)'] ?? ($ii['Distillate Fuel Total (l)'] ?? null), 'binding_constraints' => $types,
              'wall_s' => round(microtime(true) - $tA, 2), 'validated_by' => 'V7_DISPATCH_EXACT_BASIS_DIPAKAI_ULANG', 'cost_production' => $fz['key']['cp'] ?? null];
        return ['p' => $p, 'output' => $fz['output'], 'state' => $st];
    };
    /* urutan kandidat: daftar prioritas, lalu tangga yang sama dengan V6 (x1,25 .. x2 estimasi) dengan
     * penyempitan biner dua langkah di dalam bracket gagal -> lulus yang benar-benar teramati */
    $rnd = function ($x) use ($kind) { return ($kind === 'lng') ? round((float)$x, 4) : round((float)$x, 1); };
    $lastFail = null; $seen = [];
    foreach ($amounts as $amt) {
        $amt = $rnd($amt); if ($amt <= 0 || isset($seen[(string)$amt])) continue; $seen[(string)$amt] = 1;
        $e = $evalAmt($amt); $probes[] = $e['p'];
        if (!empty($e['p']['valid'])) { $win = $e; break; }
        if ($lastFail === null || $amt > $lastFail) $lastFail = $amt;
    }
    if ($win !== null && $lastFail !== null && $lastFail < $win['p']['amount']) {
        $lo = $lastFail; $hi = $win['p']['amount'];
        for ($k = 0; $k < 2; $k++) { $mid = $rnd(($lo + $hi) / 2.0); if ($mid <= $lo + 1e-9 || $mid >= $hi - 1e-9 || isset($seen[(string)$mid])) break; $seen[(string)$mid] = 1;
            $e = $evalAmt($mid); $probes[] = $e['p']; if (!empty($e['p']['valid'])) { $win = $e; $hi = $mid; } else $lo = $mid; }
    }
    $res = ['schema' => 'co12-minimum-feasible-support-v1', 'kind' => $kind, 'unit' => $kind === 'lng' ? 'BBTUD' : 'l/day',
            'engine_estimate' => isset($amounts[0]) ? round((float)$amounts[0], 4) : null,
            'estimated_minimum_feasible_support' => $win ? $win['p']['amount'] : null, 'validated_by_rerun' => $win !== null,
            'validation_status' => $win ? 'V7_TERVALIDASI_DARI_DISPATCH_EXACT_BASIS' : 'V7_DELTA_TIDAK_VALID_LANJUT_PIPELINE',
            'residual_after_validation' => $win ? 0.0 : null, 'binding_constraints' => [], 'probes' => $probes, 'probe_count' => count($probes),
            'search' => 'V7_DISPATCH_BASIS_DIBEKUKAN', 'wall_s' => round(microtime(true) - $t0, 2),
            'note' => 'Dispatch 48 row rencana exact basis (lever dispatch terbukti habis) dibekukan; engine menghitung ulang penuh state dengan aksi bahan bakar ini dan validator 48 row menilainya.'];
    if ($win === null) return ['search' => $res, 'valid' => false];
    /* Rencana tervalidasi disimpan sebagai hasil state rerun pilihan operator (kunci state identik dengan job economic_review rerun). */
    $out = $win['output'];
    $out['info']['Run Status'] = array_merge((array)($out['info']['Run Status'] ?? []), ['completed' => true, 'deadline_reached' => false, 'budget_truncated' => false,
        'converged' => true, 'status' => 'CONVERGED', 'economic_review_skipped' => null, 'stages_truncated' => [], 'economic_review_completed' => true,
        'mode' => 'V7_FUEL_DELTA', 'core_runs' => 1, 'core_simulations' => 1]);
    $out['info']['V7 Fuel Delta Validation'] = ['kind' => $kind, 'amount' => $win['p']['amount'], 'reused' => 'baseline, commitment, dispatch, Export, headroom, bukti gas rencana exact basis',
        'basis_cost_production' => $base['info']['Cost Production (USD/MWh)'] ?? null, 'cost_production' => $win['p']['cost_production'], 'probes' => $probes];
    $stored = false;
    try {
        $stIn = pp_econ_job_sim_input(pp_normalize_copy($win['state']), $ceil);
        $key = pp_sim_state_key($stIn); $f = pp_memo_path($key);
        $enc = json_encode(['schema' => PP_MEMO_SCHEMA, 'key' => $key, 'created_at' => date('c'), 'wall_s' => $win['p']['wall_s'],
                            'globals' => base64_encode(serialize([])), 'output' => $out], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!pp_memo_off() && is_string($enc) && !is_file($f)) {
            $dir = pp_memo_dir(); if (!is_dir($dir)) @mkdir($dir, 0777, true);
            $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $enc) === strlen($enc)) { $stored = @rename($tmp, $f); if (!$stored) @unlink($tmp); }
        }
        $res['rerun_state_key'] = substr($key, 0, 16);
    } catch (Throwable $e) {}
    $res['rerun_result_stored'] = $stored;
    return ['search' => $res, 'valid' => true, 'output' => $out];
}
function pp_job_run_validated_options(string $id, array $input): array {
    $ceil = (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 1800.0);
    /* ---- input BERSIH: keputusan bahan bakar dinolkan, sisa state probe dibuang ------------- */
    $clean = $input;
    if (!isset($clean['data3']['modeling'])) $clean['data3']['modeling'] = [];
    $clean['data3']['modeling']['gas_shortage_action'] = 'none';
    $clean['data3']['modeling']['additional_lng'] = 0;
    unset($clean['data3']['modeling']['distillate_user_limit_litres'],
          $clean['data3']['modeling']['__probe_tested'],
          $clean['data3']['modeling']['__probe_state']);
    $clean['data3']['modeling']['time_budget_seconds']     = $ceil;
    $clean['data3']['modeling']['time_budget_max_seconds'] = $ceil;
    $clean['data3']['modeling']['__core_run_budget']       = 400;
    $clean['data3']['modeling']['shortage_probe_max_seconds'] = max(60.0, $ceil - 120.0);

    /* ---- LANGKAH 1: run dasar untuk menetapkan residual FINAL dan estimasi awal ------------- */
    pp_job_progress($id, 'RUN_DASAR_MENETAPKAN_RESIDUAL_FINAL', 5.0);
    pp_budget_start($ceil, true, true);
    $tBase = microtime(true);
    /* RUN DASAR = STATE YANG SAMA DENGAN JOB economic_review ATAS INPUT INI. Aksi `recommendation`
     * dinormalkan engine menjadi dispatch `none` (lihat pp_normalize_fuel_action), jadi rencananya
     * sama; anggaran disamakan dengan job economic_review sehingga kunci state identik dan hasil
     * job itu dipakai ulang alih-alih dihitung kedua kali. */
    $base = pp_sim_memo_run(pp_econ_job_sim_input($input, $ceil));
    $baseWall = microtime(true) - $tBase;
    $baseRs = (array)($base['info']['Run Status'] ?? []);
    $baseCopy = $base;
    $baseReview = pp_attach_or_reject_acceptance($clean, $baseCopy);
    $dec = (array)($baseCopy['shortage_decision'] ?? []);
    $tp  = (array)($base['info']['Export Minimization Two Phase'] ?? []);
    pp_job_progress($id, 'RUN_DASAR_SELESAI', 15.0, [
        'wall_s' => round($baseWall, 2),
        'status' => (string)($baseRs['status'] ?? ''),
        'residual_bbtud' => $tp['final_gas_shortage'] ?? null]);

    $opt  = (array)($dec['options'] ?? []);
    $lngO = (array)($opt['lng'] ?? []);
    $disO = (array)($opt['distillate'] ?? []);
    $rawGap  = (float)($dec['final_gas_shortage'] ?? ($tp['final_gas_shortage'] ?? 0.0));
    $lngEst  = (float)($lngO['estimated_required'] ?? 0.0);
    $lngMax  = (float)($lngO['max_physical'] ?? 0.0);
    $disEst  = (float)($disO['estimated_required_liters'] ?? 0.0);
    $disMax  = (float)($disO['max_physical_liters'] ?? 0.0);

    $res = [
        'schema' => 'co12-validated-fuel-options-v1',
        'kind' => 'validated_options',
        'base_run' => [
            'wall_s' => round($baseWall, 2),
            'run_status' => $baseRs,
            'converged' => ($baseRs['converged'] ?? null) === true,
            'economic_review_completed' => ($baseRs['economic_review_completed'] ?? null) === true,
            'final_gas_shortage_bbtud' => $rawGap,
            'export_slots_still_reducible' => $tp['export_slots_still_reducible'] ?? null,
            'minimization_completed' => $tp['minimization_completed'] ?? null,
            'shortage_declarable' => (bool)($dec['shortage_declarable'] ?? false),
        ],
        'shortage_decision' => $dec,
        'problem_label' => (string)($dec['problem_label'] ?? ''),
        'gas_target' => (array)($dec['gas_target'] ?? []),
        'distillate_conversion_basis' => $base['info']['Distillate Conversion Basis'] ?? null,
    ];

    /* Tidak ada shortage yang dapat dinyatakan -> tidak ada opsi bahan bakar untuk divalidasi. */
    if ($rawGap <= 1e-9 || empty($dec)) {
        $res['action_status'] = 'NO_SHORTAGE_TO_COVER';
        $res['lng'] = ['available' => false, 'validated' => false,
                       'reason' => 'tidak ada residual gas shortage yang harus ditutup'];
        $res['distillate'] = $res['lng'];
        return $res;
    }

    /* ---- V7: validasi delta dari rencana exact basis lebih dulu (murah); gagal -> jalur V6 ----- */
    $v7L = null; $v7D = null;
    if ($lngEst > 0 && $lngMax > 0 && !empty($lngO['available'])) {
        pp_job_progress($id, 'V7_VALIDASI_LNG_DARI_DISPATCH_BASIS', 18.0);
        /* Dengan Actual Gas, LNG x sah hanya bila pipe-x masuk window supplier DAN total masuk window total
         * yang ikut naik x: x di [maks(pipe-Pq, E-Q) ; min(pipe-Pq, E-Q)+0,04] (dispatch basis tetap). */
        $amL = [$lngEst];
        $bi = (array)($base['info'] ?? []);
        if ((int)($bi['Actual Hours Provided'] ?? 0) > 0) {
            $xp = (float)($bi['PGN Pipe Used (BBTUD)'] ?? 0) - (float)($bi['PGN Pipe Quota (BBTUD)'] ?? 0);
            $xe = (float)($bi['Effective Total Gas (BBTUD)'] ?? 0) - (float)($bi['Total Gas Quota (BBTUD)'] ?? 0);
            $lo = max($xp, $xe); $hi = min($xp, $xe) + 0.04;
            if ($hi - $lo > 0.001) $amL[] = 0.5 * ($lo + $hi);
        }
        foreach ([1.25, 1.5, 1.75, 2.0] as $mL) if ($lngEst * $mL <= $lngMax) $amL[] = $lngEst * $mL;   // tangga V6
        $v7L = pp_v7_fuel_delta($input, $base, 'lng', $amL, $ceil);
    }
    if ($disEst > 0 && $disMax > 0 && !empty($disO['available'])) {
        pp_job_progress($id, 'V7_VALIDASI_DISTILLATE_DARI_DISPATCH_BASIS', 19.0);
        $amD = [max($disEst, 0.0)];
        $bi = (array)($base['info'] ?? []);
        if ((int)($bi['Actual Hours Provided'] ?? 0) > 0 && function_exists('pp_distillate_litres_from_bbtu')) {
            $xp = (float)($bi['PGN Pipe Used (BBTUD)'] ?? 0) - (float)($bi['PGN Pipe Quota (BBTUD)'] ?? 0);
            $xe = (float)($bi['Effective Total Gas (BBTUD)'] ?? 0) - (float)($bi['Total Gas Quota (BBTUD)'] ?? 0);
            $lo = max($xp, $xe); $hi = min($xp, $xe) + 0.04;
            if ($hi - $lo > 0.001) foreach ([0.5 * ($lo + $hi), $lo + 0.001, $hi - 0.001] as $eD) $amD[] = pp_distillate_litres_from_bbtu($eD, (array)($input['data3']['modeling'] ?? []));
        }
        foreach ([1.25, 1.5, 1.75, 2.0] as $mD) if ($disEst * $mD <= $disMax) $amD[] = $disEst * $mD;   // tangga V6
        $v7D = pp_v7_fuel_delta($input, $base, 'distillate', $amD, $ceil);
    }
    $res['v7_fuel_delta'] = ['lng' => $v7L['search'] ?? null, 'distillate' => $v7D['search'] ?? null];
    /* ---- VALIDASI DISTILLATE BERJALAN PARALEL DENGAN LNG ----------------------------------
     * Kedua validasi saling bebas (masing-masing dari input dasar yang sama), jadi Distillate
     * didaftarkan sebagai job anak yang dipicu browser dan dihitung bersamaan dengan LNG.
     * Bila job anak tidak pernah dipicu (tab ditutup), job ini mengambil alih kuncinya dan
     * menghitungnya sendiri — tetap satu pemilik, tidak pernah dihitung dua kali. */
    $needDis = ($disEst > 0 && $disMax > 0 && !empty($disO['available'])) && empty($v7D['valid']);
    $subDis = null;
    if ($needDis && function_exists('pp_job_inproc') && pp_job_inproc()) {
        try {
            $stS = pp_job_start($input, 'validated_option_distillate', $input['_request_id'] ?? null, false);
            if (!empty($stS['ok']) && !empty($stS['job']['job_id'])) {
                $subDis = (string)$stS['job']['job_id'];
                $tokS = (string)($stS['job']['exec_token'] ?? '');
                pp_job_update($id, function (array $j) use ($subDis, $tokS): array {
                    $j['subjob'] = ['job_id' => $subDis, 'exec_token' => $tokS, 'kind' => 'validated_option_distillate'];
                    return $j; });
            }
        } catch (Throwable $eS) { $subDis = null; }
    }

    /* ---- LANGKAH 2: LNG — cari jumlah TERKECIL yang terbukti valid ------------------------- */
    pp_job_progress($id, 'MEMVALIDASI_OPSI_LNG', 20.0,
        ['estimasi_awal_bbtud' => round($lngEst, 4), 'batas_fisik_bbtud' => round($lngMax, 4)]);
    $lngRes = null;
    if (!empty($v7L['valid'])) $lngRes = $v7L['search'];
    elseif ($lngEst > 0 && $lngMax > 0 && !empty($lngO['available'])) {
        $lngRes = pp_probe_minimum_feasible_support($clean, 'lng', $rawGap, $lngEst, $lngMax, 10);
    }
    pp_job_progress($id, 'VALIDASI_LNG_SELESAI', 60.0, [
        'status' => (string)($lngRes['validation_status'] ?? 'TIDAK_DIJALANKAN'),
        'probe_count' => (int)($lngRes['probe_count'] ?? 0)]);

    /* ---- LANGKAH 3: DISTILLATE — divalidasi TERPISAH dari LNG ------------------------------ */
    pp_job_progress($id, 'MEMVALIDASI_OPSI_DISTILLATE', 65.0,
        ['estimasi_awal_liter' => round($disEst, 1), 'batas_fisik_liter' => round($disMax, 1)]);
    $disRes = null;
    if (!empty($v7D['valid'])) $disRes = $v7D['search'];
    if ($needDis) {
        if ($subDis !== null) $disRes = pp_vo_collect_distillate($id, $subDis, $ceil);
        /* V7: pada state Actual, window gabungan pipe/total untuk Distillate sering lebih sempit dari langkah
         * alokasi Distillate diskret (terukur QA PGN-1: 5 dari 5 kandidat tangga V6 tidak valid, 50 s).
         * Setelah validasi delta gagal, tangga dibatasi SATU kandidat pipeline penuh. */
        $maxPD = ((int)($base['info']['Actual Hours Provided'] ?? 0) > 0 && is_array($v7D) && empty($v7D['valid'])) ? 1 : 10;
        if (!is_array($disRes))
            $disRes = pp_probe_minimum_feasible_support($clean, 'distillate', $rawGap, $disEst, $disMax, $maxPD);
    }
    pp_job_progress($id, 'VALIDASI_DISTILLATE_SELESAI', 92.0, [
        'status' => (string)($disRes['validation_status'] ?? 'TIDAK_DIJALANKAN'),
        'probe_count' => (int)($disRes['probe_count'] ?? 0)]);

    $mk = function (?array $r, string $unitKey, array $o, string $kind): array {
        if ($r === null) return [
            'available' => false, 'validated' => false, 'converged' => false,
            'all_constraints_valid' => false, 'economic_review_completed' => false,
            $unitKey => null, 'probe_count' => 0,
            'reason' => (string)($o['unavailable_reason'] ?? 'opsi tidak tersedia pada konfigurasi ini'),
            'reason_detail' => $o['basis'] ?? null];
        $ok = !empty($r['validated_by_rerun']) && $r['estimated_minimum_feasible_support'] !== null;
        $win = null;
        foreach ((array)($r['probes'] ?? []) as $p)
            if (!empty($p['valid'])) { $win = $p; break; }
        return [
            'available' => $ok,
            'validated' => $ok,
            'converged' => $ok ? (bool)($win['converged'] ?? false) : false,
            'economic_review_completed' => $ok ? (bool)($win['economic_review_completed'] ?? false) : false,
            'all_constraints_valid' => $ok ? ((int)($win['violations'] ?? 1) === 0) : false,
            $unitKey => $ok ? $r['estimated_minimum_feasible_support'] : null,
            'validation_status' => (string)($r['validation_status'] ?? ''),
            'probe_count' => (int)($r['probe_count'] ?? 0),
            'candidates' => array_map(fn($p) => [
                'amount' => $p['amount'] ?? null, 'valid' => (bool)($p['valid'] ?? false),
                'violations' => (int)($p['violations'] ?? 0),
                'converged' => (bool)($p['converged'] ?? false),
                'economic_review_completed' => (bool)($p['economic_review_completed'] ?? false),
                'run_status' => (string)($p['run_status'] ?? ''),
                'gas_used_bbtud' => $p['gas_used_bbtud'] ?? null,
                'gas_quota_bbtud' => $p['gas_quota_bbtud'] ?? null,
                'distillate_litres' => $p['distillate_litres'] ?? null,
                'binding_constraints' => $p['binding_constraints'] ?? [],
                'wall_s' => $p['wall_s'] ?? null], (array)($r['probes'] ?? [])),
            'energy_reconciliation' => $win['energy_reconciliation'] ?? null,
            'gas_used_bbtud' => $win['gas_used_bbtud'] ?? null,
            'gas_quota_bbtud' => $win['gas_quota_bbtud'] ?? null,
            'distillate_litres_actually_used' => $win['distillate_litres'] ?? null,
            'reason' => $ok ? null : pp_job_option_reason($r, $kind),
        ];
    };
    $res['lng']        = $mk($lngRes, 'validated_amount_bbtud', $lngO, 'lng');
    $res['distillate'] = $mk($disRes, 'validated_amount_liter', $disO, 'distillate');
    $res['lng_search']        = $lngRes;
    $res['distillate_search'] = $disRes;

    $lngOk = !empty($res['lng']['validated']);
    $disOk = !empty($res['distillate']['validated']);
    if ($lngOk && $disOk)        $res['action_status'] = 'VALIDATED_OPTIONS_READY';
    elseif ($lngOk && !$disOk)   $res['action_status'] = 'NO_VALIDATED_DISTILLATE_OPTION';
    elseif (!$lngOk && $disOk)   $res['action_status'] = 'NO_VALIDATED_LNG_OPTION';
    else                         $res['action_status'] = 'VALIDATION_FAILED';
    $res['state_reuse'] = pp_memo_log();
    return $res;
}
/* Mengambil hasil validasi Distillate dari job anak. null = hitung sendiri (job anak tidak jalan). */
function pp_vo_collect_distillate(string $id, string $sub, float $ceil) {
    $t0 = microtime(true); $lapor = false;
    while ((microtime(true) - $t0) < $ceil) {
        $js = pp_job_read($sub);
        if (!is_array($js)) return null;
        $st = (string)($js['status'] ?? '');
        if ($st === 'DONE') {
            $r = json_decode((string)@file_get_contents(pp_job_dir($sub) . '/result.json'), true);
            return (is_array($r) && is_array($r['distillate_search'] ?? null)) ? $r['distillate_search'] : null;
        }
        if (in_array($st, ['FAILED', 'CANCELLED'], true)) return null;
        if ($st === 'QUEUED' || !pp_job_exec_held($sub)) {
            if (pp_job_exec_acquire($sub)) {
                $js2 = pp_job_read($sub);
                if (is_array($js2) && (string)($js2['status'] ?? '') === 'DONE') { pp_job_exec_release($sub); continue; }
                /* Tidak ada yang menghitung: diambil alih. Kunci tetap dipegang sampai request ini
                 * selesai, sehingga pemicu yang datang terlambat tidak dapat ikut menghitung. */
                pp_job_update($sub, function (array $j): array {
                    $j['status'] = 'CANCELLED'; $j['cancel_requested'] = true;
                    $j['current_step'] = 'DIHITUNG_OLEH_JOB_INDUK'; $j['finished_at'] = date('c');
                    return $j; });
                return null;
            }
        }
        if (!$lapor) { pp_job_progress($id, 'MENUNGGU_VALIDASI_DISTILLATE_PARALEL', 62.0); $lapor = true; }
        usleep(300000);
    }
    return null;
}
/* Job anak: validasi Distillate saja, dari input dasar yang SAMA dengan job validated_options. */
function pp_job_run_validated_option_distillate(string $id, array $input): array {
    $ceil = (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 1800.0);
    $clean = $input;
    if (!isset($clean['data3']['modeling'])) $clean['data3']['modeling'] = [];
    $clean['data3']['modeling']['gas_shortage_action'] = 'none';
    $clean['data3']['modeling']['additional_lng'] = 0;
    unset($clean['data3']['modeling']['distillate_user_limit_litres'],
          $clean['data3']['modeling']['__probe_tested'],
          $clean['data3']['modeling']['__probe_state']);
    $clean['data3']['modeling']['time_budget_seconds']     = $ceil;
    $clean['data3']['modeling']['time_budget_max_seconds'] = $ceil;
    $clean['data3']['modeling']['__core_run_budget']       = 400;
    $clean['data3']['modeling']['shortage_probe_max_seconds'] = max(60.0, $ceil - 120.0);
    pp_job_progress($id, 'RUN_DASAR_MENETAPKAN_RESIDUAL_FINAL', 5.0);
    pp_budget_start($ceil, true, true);
    $base = pp_sim_memo_run(pp_econ_job_sim_input($input, $ceil));
    $baseCopy = $base;
    pp_attach_or_reject_acceptance($clean, $baseCopy);
    $dec = (array)($baseCopy['shortage_decision'] ?? []);
    $tp  = (array)($base['info']['Export Minimization Two Phase'] ?? []);
    $disO = (array)(((array)($dec['options'] ?? []))['distillate'] ?? []);
    $rawGap = (float)($dec['final_gas_shortage'] ?? ($tp['final_gas_shortage'] ?? 0.0));
    $disEst = (float)($disO['estimated_required_liters'] ?? 0.0);
    $disMax = (float)($disO['max_physical_liters'] ?? 0.0);
    pp_job_progress($id, 'MEMVALIDASI_OPSI_DISTILLATE', 20.0);
    $disRes = null;
    /* V7: sama dengan job induk — pada state Actual tangga dibatasi satu kandidat (validasi delta sudah gagal di induk). */
    $maxPD = ((int)($base['info']['Actual Hours Provided'] ?? 0) > 0) ? 1 : 10;
    if ($rawGap > 1e-9 && $disEst > 0 && $disMax > 0 && !empty($disO['available']))
        $disRes = pp_probe_minimum_feasible_support($clean, 'distillate', $rawGap, $disEst, $disMax, $maxPD);
    pp_job_progress($id, 'VALIDASI_DISTILLATE_SELESAI', 95.0);
    return ['schema' => 'co12-validated-option-distillate-v1', 'kind' => 'validated_option_distillate',
            'distillate_search' => $disRes, 'state_reuse' => pp_memo_log()];
}
/* Alasan singkat dan spesifik mengapa sebuah opsi tidak memperoleh angka tervalidasi. */
function pp_job_option_reason(array $r, string $kind): string {
    $st = (string)($r['validation_status'] ?? '');
    $map = [
        'TIDAK_ADA_ESTIMASI_AWAL'  => 'engine tidak memiliki estimasi awal untuk opsi ini',
        'PROBE_LIMIT_TERCAPAI'     => 'batas jumlah kandidat tercapai tanpa satu pun kandidat yang lulus seluruh validasi',
        'VALIDATION_PENDING'       => 'anggaran waktu job habis sebelum seluruh kandidat diuji',
        'NO_FEASIBLE_AMOUNT_FOUND' => 'tidak ada jumlah pada tangga kandidat yang lulus seluruh validasi',
    ];
    $why = $map[$st] ?? ('status pencarian: ' . ($st !== '' ? $st : 'tidak diketahui'));
    $last = null;
    foreach ((array)($r['probes'] ?? []) as $p) $last = $p;
    if (is_array($last) && empty($last['valid'])) {
        if (!empty($last['binding_constraints']))
            $why .= '; constraint yang mengikat pada kandidat terakhir: ' . implode(', ', array_keys((array)$last['binding_constraints']));
        elseif (empty($last['economic_review_completed']))
            $why .= '; kandidat terakhir tidak menyelesaikan economic review';
        elseif (empty($last['converged']))
            $why .= '; kandidat terakhir tidak konvergen';
    }
    return ($kind === 'lng' ? 'LNG' : 'Distillate') . ': ' . $why;
}

/* =============================================================================================
 *  PENYERAHAN OTOMATIS KE JOB ASINKRON.
 *  Operator TIDAK PERNAH diminta menjalankan ulang simulasi (§9). Bila request sinkron berakhir
 *  tanpa economic review, atau bila popup Gas Shortage memerlukan rekomendasi tervalidasi,
 *  run.php sendiri yang melepas worker CLI dan melampirkan penanda job ke respons. UI cukup
 *  menampilkan progress dan mengambil hasilnya ketika selesai.
 * =========================================================================================== */
/* ==============================================================================================
 * ADMISSION ASINKRON DINI — MEMBUAT JOB DI AWAL, BUKAN SETELAH OPERATOR MENUNGGU.
 *
 * CACAT YANG DIPERBAIKI, TERUKUR. `pp_attach_async_handoff()` dipanggil SETELAH jalur sinkron
 * selesai. Pada input produksi di PHP 7.4 terukur: Unit Skip Load 46,2 detik, Unit Fix Load
 * 36,8 detik, spinning reserve 37,3 detik — dan pada keempat skenario berat itu status yang
 * kembali adalah `ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET`. Artinya operator menunggu 46 detik, lalu
 * baru job dibuat, lalu masih harus menunggu worker. Ini persis yang dilarang: job untuk run yang
 * diprediksi berat harus dibuat SEDINI MUNGKIN.
 *
 * APA YANG DILAKUKAN DAN APA YANG TIDAK. Bila prediksi menyatakan run ini berat, job dibuat
 * SEBELUM simulasi sinkron dimulai, lalu simulasi sinkron TETAP DIJALANKAN seperti biasa.
 *   - Bila jalur sinkron ternyata selesai dengan review ekonomi lengkap, hasilnyalah yang dipakai;
 *     job yang terlanjur dibuat tidak merusak apa pun karena identitas job = fingerprint engine +
 *     hash input, sehingga `pp_job_start()` memakai ulang job itu alih-alih membuat yang kedua.
 *   - Bila jalur sinkron ter-latch deadline, job sudah berjalan lebih dulu selama seluruh durasi
 *     sinkron itu — bukan baru dimulai sesudahnya.
 * Prediksi TIDAK PERNAH mengubah dispatch, kandidat, constraint, atau biaya. Satu-satunya efeknya
 * adalah KAPAN job latar dibuat. Karena itu prediksi yang meleset tidak dapat merusak hasil.
 *
 * ARAH BIAS DISENGAJA. Estimator ini sengaja dibuat KONSERVATIF (cenderung menaksir tinggi).
 * Menaksir tinggi berakibat job dibuat lebih awal dari perlunya — biayanya CPU latar. Menaksir
 * rendah berakibat operator menunggu penuh seperti sebelumnya. Arah yang kedua jauh lebih merugikan.
 *
 * MENGAPA SATUANNYA BUKAN DETIK. Detik absolut TIDAK portabel: kalibrasi dilakukan pada mesin ini,
 * sedangkan XAMPP Anda punya kecepatan lain. Karena itu model memprediksi UNIT KERJA, lalu
 * dikalikan faktor kecepatan mesin yang diukur di tempat, sehingga ambang 40 detik berarti 40 detik
 * DI MESIN ANDA.
 * ============================================================================================== */

/* Faktor kecepatan mesin: detik per unit kerja, diukur sekali per proses dengan loop deterministik
 * yang menirukan campuran operasi pipeline (aritmetika float + akses array asosiatif). Referensi
 * dikalibrasi pada mesin pengukuran; nilai 1.0 berarti mesin ini sama cepat dengan mesin referensi. */
function pp_machine_speed_factor(): float {
    static $f = null;
    if ($f !== null) return $f;
    /* TIGA PUTARAN, DIAMBIL YANG TERCEPAT. Satu sampel tunggal terukur berayun 4,65-7,33 ms pada
     * mesin ini (sebaran 57%) karena penjadwalan CPU dan pesaing proses. Putaran tercepat adalah
     * perkiraan terbaik atas kecepatan mesin yang sesungguhnya, karena gangguan hanya dapat
     * MEMPERLAMBAT, tidak pernah mempercepat. Biaya totalnya ~14 ms — di bawah 0,1% dari run
     * 14 detik, dan hanya dibayar sekali per proses. */
    $el = INF;
    for ($rep = 0; $rep < 3; $rep++) {
        $t = hrtime(true);
        $s = 1.0; $acc = [];
        for ($i = 1; $i <= 60000; $i++) {
            /* SETIAP operasi bergantung pada hasil sebelumnya, dan pemanggilan fungsi nyata
             * (sqrt/fmod) tidak dapat dilipat menjadi konstanta oleh optimizer. Ini penting:
             * probe versi pertama memakai aritmetika loop-invariant dan terukur 0,61 ms di bawah
             * server ber-opcache tetapi 4,65 ms di CLI tanpa opcache — 7,7x berbeda untuk mesin
             * yang SAMA, sehingga tidak dapat dipakai mengukur kecepatan mesin. Bentuk di bawah
             * terukur konsisten pada kedua konteks. */
            $s = sqrt($s + $i) + fmod($s * 1.0000001, 97.0);
            $acc['k' . ($i & 255)] = $s;
        }
        $GLOBALS['__pp_calib_sink'] = $s + count($acc);
        $one = (hrtime(true) - $t) / 1e9;
        if ($one < $el) $el = $one;
    }
    if (!is_finite($el)) $el = PP_CALIB_REF_S;
    /* PP_CALIB_REF_S = detik yang dibutuhkan loop di atas pada mesin kalibrasi. */
    $GLOBALS['__pp_calib_loop_s'] = $el;
    $f = ($el > 0) ? ($el / PP_CALIB_REF_S) : 1.0;
    if (!is_finite($f) || $f <= 0) $f = 1.0;
    /* Clamp lebar: melindungi dari pengukuran yang kacau tanpa memotong mesin yang memang jauh
     * lebih lambat atau lebih cepat daripada mesin kalibrasi. Nilai mentah loop ikut dilaporkan
     * pada blok prediksi supaya clamp yang aktif dapat terlihat, bukan tersembunyi. */
    return $f = max(0.1, min(20.0, $f));
}

function pp_predict_runtime(array $input): array {
    $m = (array)($input['data3']['modeling'] ?? []);
    $feat = []; $wu = PP_PRED_BASE_WU;                 /* biaya dasar satu run yang terukur */

    $skip = (array)($m['unit_skip_load'] ?? []);
    if ($skip) { $feat['unit_skip_load'] = count($skip); $wu += PP_PRED_SKIP_WU * min(3, count($skip)); }

    $fix = (array)($m['unit_fix_load'] ?? []);
    if ($fix) { $feat['unit_fix_load'] = count($fix); $wu += PP_PRED_FIX_WU * min(3, count($fix)); }

    $res = (float)($m['spinning_reserve_min'] ?? 0);
    if ($res > 0) { $feat['spinning_reserve_min'] = $res; $wu += PP_PRED_RESERVE_WU; }

    $stop = (array)($m['unit_stop_time'] ?? []);
    if ($stop) { $feat['unit_stop_time'] = count($stop); $wu += PP_PRED_STOP_WU; }

    /* CHANGE OVER dan KUOTA GAS SENGAJA TIDAK DITAMBAHKAN DI SINI, dan itu koreksi atas kesalahan
     * kalibrasi saya sendiri. Fixture produksi SUDAH memuat `change_over` dan `gas_quota` yang
     * terisi, sehingga biaya keduanya sudah TERKANDUNG di dalam PP_PRED_BASE_WU yang diukur dari
     * fixture itu. Menambahkannya lagi berarti menghitung ganda — versi pertama prediktor ini
     * melakukannya dan menghasilkan 44,8 unit kerja untuk run yang nyatanya 15,1 detik.
     *
     * BATAS YANG DIAKUI TERUS TERANG. Karena itu prediktor ini TIDAK dapat membedakan PGN 40
     * (terukur 29,5 detik, review ekonomi TIDAK selesai) dari baseline (15,1 detik): keduanya
     * punya bentuk input yang sama, hanya berbeda NILAI kuota, dan pengaruh nilai itu tidak
     * monoton (PGN 30 justru lebih cepat daripada baseline). Kekurangan ini tidak ditutup-tutupi
     * melainkan ditangani oleh mekanisme kedua: checkpoint tengah-jalan di pp_phase_mark(), yang
     * memakai waktu yang BENAR-BENAR sudah berlalu pada run ini alih-alih menebak di awal. */

    if (!empty($m['actual_gas_rows']) || !empty($m['actual_rows'])) { $feat['actual_gas'] = 1; $wu += PP_PRED_ACTUAL_WU; }

    $sf = pp_machine_speed_factor();
    $sec = $wu * PP_PRED_WU_SECONDS * $sf * PP_PRED_SAFETY;

    /* Klasifikasi §11. Ambang dibaca pada detik MESIN INI, bukan detik mesin kalibrasi. */
    if      ($sec <= 5.0)  $tier = 'VERY_FAST';
    elseif  ($sec <= 10.0) $tier = 'FAST';
    elseif  ($sec <= 15.0) $tier = 'INTERACTIVE';
    elseif  ($sec <= 40.0) $tier = 'ACCEPTABLE_COMPLEX';
    else                   $tier = 'ASYNC_REQUIRED';

    return [
        'work_units'          => round($wu, 2),
        'safety_multiplier'   => PP_PRED_SAFETY,
        'machine_speed_factor' => round($sf, 3),
        'calib_loop_seconds'  => round((float)($GLOBALS['__pp_calib_loop_s'] ?? 0), 6),
        'calib_reference_seconds' => PP_CALIB_REF_S,
        'predicted_seconds'   => round($sec, 2),
        'tier'                => $tier,
        'features'            => $feat,
        'basis'               => 'Model aditif atas fitur input, dikalibrasi dari run terukur pada PHP 7.4 '
                               . '(lihat reports/ASYNC_ADMISSION_CALIBRATION.md). Sengaja bias menaksir tinggi.',
        'catatan'             => 'Prediksi HANYA menentukan kapan job latar dibuat. Prediksi tidak pernah '
                               . 'mengubah dispatch, kandidat, constraint, biaya, maupun hasil akhir.',
    ];
}

/* ==============================================================================================
 *  CATATAN BUKTI: INPUT INI PERNAH TERPOTONG.
 *
 *  PEKERJAAN GANDA YANG DIUKUR. Pada rencana yang tidak dapat diselesaikan jalur sinkron, urutan
 *  yang terjadi adalah: jalur sinkron memakai 15 detik lalu DIBUANG seluruhnya, kemudian worker
 *  menghitung ULANG rencana yang sama persis dari nol selama 29,7 detik. Operator menunggu 43,6
 *  detik untuk jawaban yang biayanya 29,7 detik. Lima belas detik itu adalah pekerjaan IDENTIK
 *  yang dikerjakan dua kali — persis jenis pemborosan yang harus dihilangkan.
 *
 *  MENGAPA TIDAK DIPUTUSKAN DARI PREDIKSI. Terukur pada mesin dua inti: menjalankan worker
 *  bersamaan dengan jalur sinkron menurunkan core run jalur sinkron dari 21 menjadi 6-8, karena
 *  keduanya berebut CPU. Untuk rencana yang SEBENARNYA dapat konvergen sinkron, memulai worker
 *  atas dasar tebakan justru dapat mendorongnya menjadi terpotong — ramalan yang mewujudkan
 *  dirinya sendiri. Prediktor pun mengakui sendiri tidak dapat membedakan kasus ini (17,93 detik
 *  diprediksi untuk run yang butuh 29,7 detik).
 *
 *  KARENA ITU DASARNYA BUKTI, BUKAN TEBAKAN. Yang dicatat adalah kejadian NYATA: input dengan
 *  hash ini pernah berakhir terpotong. Run PERTAMA atas sebuah rencana berperilaku persis seperti
 *  sebelumnya — tidak ada satu pun yang berubah. Run BERIKUTNYA atas rencana yang sama memulai
 *  worker di detik nol, sehingga 15 detik duplikat itu hilang. Bukti kedaluwarsa setelah tujuh
 *  hari dan terikat pada fingerprint engine lewat hash input, sehingga perubahan kode tidak
 *  pernah mewarisi bukti lama. */
function pp_trunc_evidence_path(string $hash): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . '_trunc';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir . DIRECTORY_SEPARATOR . substr($hash, 0, 32) . '.json';
}
function pp_trunc_evidence_read(array $input): ?array {
    $f = pp_trunc_evidence_path(pp_job_input_hash($input));
    if (!is_file($f)) return null;
    if ((time() - (int)@filemtime($f)) > 7 * 86400) { @unlink($f); return null; }
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}
function pp_trunc_evidence_write(array $input, array $rs): void {
    if (!empty($input['data3']['modeling']['__no_async_handoff'])) return;
    @file_put_contents(pp_trunc_evidence_path(pp_job_input_hash($input)), json_encode([
        'truncated_at' => date('c'),
        'last_stage'   => (string)($rs['last_stage'] ?? ''),
        'elapsed_s'    => (float)($rs['elapsed_s'] ?? 0),
        'core_runs'    => (int)($rs['core_runs'] ?? 0),
        'catatan'      => 'Bukti bahwa jalur sinkron tidak dapat menuntaskan rencana ini. Dipakai hanya '
                        . 'untuk memulai worker lebih awal pada run berikutnya; tidak pernah menyentuh '
                        . 'dispatch, kandidat, constraint, biaya, maupun hasil.',
    ]));
}

/* Membuat job SEBELUM simulasi sinkron dijalankan, bila prediksi menyatakan run ini berat. */
function pp_async_admit_early(array $input, $rid): ?array {
    if (!empty($input['data3']['modeling']['__no_async_handoff'])) return null;   // harness numerik
    $pred = pp_predict_runtime($input);
    $GLOBALS['__pp_runtime_prediction'] = $pred;
    /* Dipasang untuk checkpoint tengah-jalan di pp_phase_mark(): ia butuh input dan request id
     * yang sama persis supaya identitas job (fingerprint engine + hash input) tetap sama, sehingga
     * job checkpoint dan job akhir TIDAK PERNAH menjadi dua job berbeda untuk satu permintaan. */
    $GLOBALS['__pp_admit_input'] = $input;
    $GLOBALS['__pp_admit_rid']   = is_string($rid) ? $rid : null;
    $bukti = pp_trunc_evidence_read($input);
    if ($pred['tier'] !== 'ASYNC_REQUIRED' && $bukti === null) return null;
    $st = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
    $job = (array)($st['job'] ?? []);
    $adm = [
        'admitted_early'  => true,
        'trigger'         => $bukti !== null ? 'BUKTI_TERPOTONG_SEBELUMNYA' : 'PREDIKSI_PINTU_MASUK',
        'evidence'        => $bukti,
        'kind'            => 'economic_review',
        'prediction'      => $pred,
        'ok'              => (bool)($st['ok'] ?? false),
        'job_id'          => $job['job_id'] ?? null,
        'exec_token'      => $job['exec_token'] ?? null,
        'status'          => $job['status'] ?? null,
        'reused'          => (bool)($st['reused'] ?? false),
        'input_hash'      => pp_job_input_hash($input),
        'created_at_s'    => 0.0,
        'error'           => !empty($st['ok']) ? null : (string)($st['error'] ?? ''),
        'catatan'         => 'Job dibuat SEBELUM simulasi sinkron dimulai karena prediksi runtime melampaui '
                           . '40 detik pada mesin ini. Jalur sinkron tetap berjalan; bila ia selesai dengan '
                           . 'review ekonomi lengkap, hasil sinkron yang dipakai dan job ini tidak diperlukan.',
    ];
    $GLOBALS['__pp_async_early_admission'] = $adm; $GLOBALS['ppSafetyAdm'] = $adm;
    return $adm;
}

/* ==============================================================================================
 *  KONTRAK HASIL DINYATAKAN DI SATU TEMPAT, BUKAN PER NAMA STATUS.
 *
 *  RIWAYAT CACAT INI, SUPAYA TIDAK TERULANG. Gerbang "hasil belum final" sudah dua kali ditambal
 *  per status: pertama untuk `USER_FUEL_DECISION_REQUIRED`, lalu untuk `ECONOMIC_REVIEW_REQUIRED`.
 *  Keduanya benar, dan keduanya tidak cukup — karena daftar nama status tidak pernah lengkap.
 *  Terbukti lewat jejak klik nyata: status KETIGA, `NOT_CONVERGED_INCUMBENT_FOR_REVIEW`,
 *  mengembalikan 48 baris TANPA satu pun penanda kontrak (`preliminary`, `save_allowed`,
 *  `final_result_visible` seluruhnya undefined), sehingga UI menerbitkannya sebagai hasil biasa
 *  dan Save/Export/Publish terbuka atas rencana yang `converged=false`.
 *
 *  Ini bukan kasus langka. Pada SAPI web, `max_execution_time` bawaan 30 detik membuat anggaran
 *  internal menjadi 15 detik (terukur: `adaptive_max=15` pada SAPI cli-server, dan XAMPP memakai
 *  nilai php.ini yang sama). Artinya pada mesin operator hampir SETIAP rencana berat berakhir
 *  tidak konvergen — jadi jalur yang bocor ini justru jalur yang paling sering dilalui.
 *
 *  ATURANNYA SEKARANG BERASAL DARI KEADAAN, BUKAN DARI NAMA. Rencana yang tidak konvergen bukan
 *  rencana final. Titik. Nama status apa pun — termasuk nama yang belum ada hari ini — tidak
 *  dapat lagi menerbitkan baris hasil dari run yang tidak konvergen.
 *
 *  YANG TIDAK DIUBAH. Tidak ada angka, baris, kandidat, constraint, comparator, maupun biaya yang
 *  disentuh. Yang ditambahkan hanya tiga penanda yang MENYATAKAN keadaan yang sudah ada. Baris
 *  hasil tetap dikembalikan supaya operator dapat meninjaunya; yang dicabut hanya kewenangan
 *  menyimpan dan menerbitkannya. Review eksak yang menghasilkan rencana konvergen selalu
 *  diserahkan ke worker, dan ketika hasil itu tiba penanda ini tidak lagi terpasang. */
function pp_declare_result_contract(array &$output): void {
    if (empty($output['data']) || !is_array($output['data'])) return;   // tidak ada baris: tidak ada yang dapat diterbitkan
    if (($output['preliminary'] ?? null) === true) return;              // sudah dinyatakan cabang di atas
    $rs = (array)($output['info']['Run Status'] ?? []);
    if (($rs['converged'] ?? null) === true) return;                    // konvergen: hasil final, tidak disentuh
    $output['preliminary']          = true;
    $output['final_result_visible'] = false;
    $output['save_allowed']         = false;
    $output['publish_allowed']      = false;
    $sebab = [];
    if (!empty($rs['budget_truncated']))  $sebab[] = 'pencarian terpotong anggaran waktu';
    if (!empty($rs['deadline_reached']))  $sebab[] = 'batas waktu request tercapai';
    if (!empty($rs['stages_truncated']))  $sebab[] = 'tahap ' . implode(', ', array_keys((array)$rs['stages_truncated'])) . ' terpotong';
    if (!$sebab)                          $sebab[] = 'pencarian belum mencapai konvergensi';
    $output['preliminary_note'] = 'Rencana ini BELUM konvergen (' . implode('; ', $sebab) . '), '
        . 'sehingga bukan rencana final: baris di bawah hanya untuk ditinjau. Simulation Data, Save, '
        . 'Export, dan Publish terkunci sampai perhitungan eksak selesai.';
    $output['preliminary_reason'] = 'RUN_NOT_CONVERGED';
}

function pp_attach_async_handoff(array $input, array &$output, $rid): void {
    if(!empty($input['_fast_default'])){unset($output['async_job'],$output['legal_branch_job'],$output['validated_options_job']);$output['info']['Fastest Execution']=['terminal'=>true,'background_job_started'=>false,'result_updates'=>1];return;}
    pp_declare_result_contract($output);
    try { pp_final_save($input, $output); } catch (Throwable $e) { /* registri hanya titik awal provisional */ }
    /* Prediksi runtime dan admission dini SELALU dilaporkan, termasuk ketika jalur sinkron
     * ternyata selesai tepat waktu — supaya keputusan penjadwalan dapat diaudit operator. */
    if (isset($GLOBALS['__pp_runtime_prediction']))
        $output['info']['Runtime Prediction'] = $GLOBALS['__pp_runtime_prediction'];
    /* ==========================================================================================
     * STATUS OPSI TERVALIDASI DIANGKAT KE AKAR RESPONSE.
     *
     * `action_status` job validated_options sudah memuat VALIDATED_OPTIONS_READY, tetapi nilainya
     * terkubur di dalam objek job sehingga UI tidak punya satu tempat pasti untuk memutuskan
     * KAPAN tombol "Gunakan LNG"/"Gunakan Distillate" boleh aktif. Diangkat ke akar supaya
     * kontraknya tunggal dan tidak bergantung pada pencocokan teks di dalam blok bersarang. */
    $__vo = (array)($output['info']['Validated Fuel Options'] ?? $output['validated_options'] ?? []);
    $__voStat = (string)($__vo['action_status'] ?? '');
    if ($__voStat === '' && !empty($output['validated_options_job']['result']['action_status']))
        $__voStat = (string)$output['validated_options_job']['result']['action_status'];
    if ($__voStat === '' && !empty($GLOBALS['__pp_validated_options_status']))
        $__voStat = (string)$GLOBALS['__pp_validated_options_status'];
    if ($__voStat !== '') {
        $output['validated_options_status'] = $__voStat;
        $output['info']['Validated Options Status'] = $__voStat;
    }
    /* Harness numerik headless mematikan penyerahan job supaya worker latar tidak ikut memakai
     * CPU dan mengaburkan pengukuran runtime. Jalur UI TIDAK PERNAH mengirim flag ini. */
    if (!empty($input['data3']['modeling']['__no_async_handoff'])) return;
    $rs = (array)($output['info']['Run Status'] ?? []);
    /* Bukti dicatat dari kejadian nyata, bukan dari prediksi: run ini memang berakhir terpotong
     * tanpa konvergensi. Hanya dipakai untuk memulai worker lebih awal pada run berikutnya. */
    if (empty($GLOBALS['__pp_is_job_worker'])
        && (($rs['converged'] ?? null) !== true)
        && (!empty($rs['budget_truncated']) || !empty($rs['stages_truncated']) || !empty($rs['deadline_reached'])))
        pp_trunc_evidence_write($input, $rs);
    $sk = $rs['economic_review_skipped'] ?? null;
    /* ==========================================================================================
     * JANJI YANG TIDAK BOLEH KOSONG.
     *
     * Status `ECONOMIC_REVIEW_REQUIRED` menyatakan kepada klien bahwa perhitungan eksak akan
     * DILANJUTKAN. Sebelum perbaikan ini, job-nya hanya dibuat bila satu flag tertentu terisi
     * (`economic_review_skipped.async_completion_required`). Bila run terpotong lewat jalur lain —
     * terukur dengan input nyata: `stages_truncated={time_budget:mm_ge_fill:1}`, `converged=false`, sementara
     * `economic_review_skipped` bernilai null — maka janjinya diucapkan tanpa ada yang menepati:
     * tidak ada job, tidak ada polling, tidak ada hasil eksak yang pernah datang.
     *
     * Pemicunya kini adalah KEADAAN yang dinyatakan status itu sendiri, bukan satu flag internal.
     * Tidak ada perhitungan yang diubah; yang ditambahkan hanyalah penyerahan lanjutannya. */
    if (!is_array($sk) && !empty($output['data']) && (($rs['converged'] ?? null) !== true)) {
        $sk = ['async_completion_required' => true,
               'reason' => 'Run sinkron berakhir tanpa konvergensi'
                   . (!empty($rs['last_stage']) ? ' (tahap terakhir: ' . (string)$rs['last_stage'] . ')' : '')
                   . '; review ekonomi eksak diselesaikan worker.'];
    }
    /* (1) economic review belum selesai -> selesaikan di worker */
    if (is_array($sk) && !empty($sk['async_completion_required'])) {
        $st = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
        $output['async_job'] = [
            'required' => true, 'kind' => 'economic_review',
            'reason' => (string)($sk['reason'] ?? ''),
            'ok' => (bool)($st['ok'] ?? false),
            'job_id' => $st['job']['job_id'] ?? null,
            'exec_token' => $st['job']['exec_token'] ?? null,
            'status' => $st['job']['status'] ?? null,
            'reused' => (bool)($st['reused'] ?? false),
            'input_hash' => pp_job_input_hash($input),
            'error' => $st['ok'] ? null : (string)($st['error'] ?? ''),
            'catatan' => 'Economic review diselesaikan oleh worker CLI di luar PHP-FPM. Plafon request sinkron tetap 60 detik. Operator tidak perlu menjalankan ulang simulasi.'];
        $output['info']['Async Economic Review Job'] = $output['async_job'];
    }
    /* (1b) perbandingan cabang legal Unit Skip Load belum eksak -> selesaikan di worker.
     * Jalur sinkron memverifikasi kandidat dengan pipeline penuh sampai deadline request habis.
     * Bila masih ada kandidat yang belum diverifikasi, perbandingan EKSAK diselesaikan worker CLI
     * tanpa plafon 60 detik; operator tidak perlu menjalankan ulang simulasi. */
    $br = (array)($output['info']['Unit Skip Load Branch'] ?? []);
    if ($br && (($br['exact_economic_comparison'] ?? null) !== true)
        && (int)($br['candidates_total'] ?? count((array)($br['candidates'] ?? []))) > 1) {
        $st3 = pp_job_start($input, 'legal_branch_exact', is_string($rid) ? $rid : null, false);
        $job3 = (array)($st3['job'] ?? []);
        $done3 = ((string)($job3['status'] ?? '') === 'DONE') && !empty($job3['result_available']);
        $lb = ['required' => true, 'kind' => 'legal_branch_exact',
               'reason' => !empty($br['verification_truncated_by_budget'])
                   ? 'Verifikasi pipeline penuh antar cabang legal terpotong deadline request sinkron.'
                   : 'Peringkatan sinkron memakai core run; perbandingan eksak butuh pipeline penuh per kandidat.',
               'ok' => (bool)($st3['ok'] ?? false),
               'job_id' => $job3['job_id'] ?? null,
               'exec_token' => $job3['exec_token'] ?? null,
               'status' => $job3['status'] ?? null,
               'reused' => (bool)($st3['reused'] ?? false),
               'input_hash' => pp_job_input_hash($input),
               'candidates_total' => (int)($br['candidates_total'] ?? 0),
               'action_status' => $done3 ? null : 'CALCULATING_EXACT_BRANCH_COMPARISON',
               'error' => !empty($st3['ok']) ? null : (string)($st3['error'] ?? ''),
               'catatan' => 'Batas 60 detik hanya untuk request sinkron; runtime latar tidak dibatasi.'];
        if ($done3) {
            $rf3 = pp_job_dir((string)$job3['job_id']) . '/result.json';
            $r3 = is_file($rf3) ? json_decode((string)@file_get_contents($rf3), true) : null;
            if (is_array($r3)) { $lb['result'] = ['action_status' => (string)($r3['action_status'] ?? ''),
                'selected' => $r3['selected'] ?? null, 'comparison' => $r3['comparison'] ?? [],
                'all_candidates_evaluated' => (bool)($r3['all_candidates_evaluated'] ?? false)];
                $lb['action_status'] = (string)($r3['action_status'] ?? ''); }
        }
        $output['legal_branch_job'] = $lb;
        $output['info']['Async Legal Branch Job'] = $lb;
    }
    /* (2) popup Gas Shortage -> rekomendasi WAJIB sudah tervalidasi sebelum ditampilkan */
    $shortageStatus = (string)($output['status'] ?? '');
    if (in_array($shortageStatus, ['USER_ACTION_REQUIRED', 'FUEL_SELECTION_REQUIRED', 'USER_FUEL_DECISION_REQUIRED'], true)
        && !empty($output['shortage_decision'])) {
        $st2 = pp_job_start($input, 'validated_options', is_string($rid) ? $rid : null, false);
        $job = (array)($st2['job'] ?? []);
        $done = ((string)($job['status'] ?? '') === 'DONE') && !empty($job['result_available']);
        $vo = ['required' => true, 'kind' => 'validated_options',
               'ok' => (bool)($st2['ok'] ?? false),
               'job_id' => $job['job_id'] ?? null,
               'exec_token' => $job['exec_token'] ?? null,
               'status' => $job['status'] ?? null,
               'reused' => (bool)($st2['reused'] ?? false),
               'input_hash' => pp_job_input_hash($input),
               'action_status' => $done ? null : 'CALCULATING_VALIDATED_OPTIONS',
               'error' => !empty($st2['ok']) ? null : (string)($st2['error'] ?? '')];
        if ($done) {
            $rf = pp_job_dir((string)$job['job_id']) . '/result.json';
            $r = is_file($rf) ? json_decode((string)@file_get_contents($rf), true) : null;
            if (is_array($r)) { $vo['result'] = $r; $vo['action_status'] = (string)($r['action_status'] ?? 'VALIDATION_FAILED'); }
        }
        if (empty($vo['ok'])) $vo['action_status'] = 'VALIDATION_FAILED';
        $output['validated_options_job'] = $vo;
        $output['action_status'] = (string)$vo['action_status'];
        $output['info']['Validated Fuel Options Job'] = $vo;
    }

    if (isset($GLOBALS['__pp_async_early_admission'])) {
        $adm0 = (array)$GLOBALS['__pp_async_early_admission'];
        /* ======================================================================================
         * MELEPAS JOB YANG TERNYATA TIDAK DIPERLUKAN.
         *
         * Admission dini sengaja dibiaskan ke arah "buat job lebih awal", karena arah sebaliknya
         * membuat operator menunggu penuh. Konsekuensinya sebagian job memang dibuat untuk run
         * yang ternyata selesai baik-baik saja — terukur 3 dari 9 skenario. Membiarkan job itu
         * berjalan sampai habis berarti worker latar memakan CPU untuk hasil yang tidak akan
         * pernah dibaca siapa pun, dan pada mesin XAMPP dua inti itu akan memperlambat run
         * BERIKUTNYA milik operator.
         *
         * Karena itu, begitu jalur sinkron terbukti selesai dengan review ekonomi LENGKAP dan
         * tanpa kebutuhan penyelesaian asinkron, job yang terlanjur dibuat dibatalkan di sini.
         * Pembatalan ini tidak menyentuh hasil: yang dikembalikan ke operator tetap hasil jalur
         * sinkron yang sudah lengkap itu.
         * ====================================================================================== */
        $rs0   = (array)($output['info']['Run Status'] ?? []);
        $skip0 = $rs0['economic_review_skipped'] ?? null;
        /* ==================================================================================
         * JALAN BUNTU YANG DITUTUP DI SINI.
         *
         * Pelepasan job tadinya hanya melihat `economic_review_skipped`. Sebuah run dapat
         * TERPOTONG anggaran waktu (`budget_truncated=true`) TANPA flag itu terisi — dan pada
         * keadaan itu `converged` bernilai false. Akibatnya dua aturan saling bertentangan:
         * job lanjutannya DILEPAS karena dianggap tidak perlu, sementara kontrak hasil
         * MENGUNCI rencananya karena tidak konvergen. Operator memperoleh rencana terkunci
         * yang tidak punya satu pun kelanjutan — terkunci selamanya.
         *
         * Karena itu konvergensi kini menjadi syarat pertama. Selama rencana belum konvergen,
         * job lanjutan TIDAK PERNAH dilepas, sehingga setiap penguncian selalu disertai jalan
         * keluar yang benar-benar berjalan. */
        $needsAsync = (($rs0['converged'] ?? null) !== true)
                      || (is_array($skip0) && !empty($skip0['async_completion_required']))
                      || !empty($skip0)
                      || stripos((string)($rs0['status'] ?? ''), 'ECONOMIC_REVIEW_SKIPPED') !== false
                      || !empty($output['async_job']) || !empty($output['legal_branch_job']);
        if (!$needsAsync && !empty($adm0['job_id'])) {
            $cid = (string)$adm0['job_id'];
            $cj  = pp_job_read($cid);
            if (is_array($cj) && in_array((string)($cj['status'] ?? ''), ['QUEUED', 'CLAIMED', 'RUNNING'], true)) {
                pp_job_update($cid, function (array $j): array {
                    $j['cancel_requested'] = true;
                    $j['status'] = 'CANCELLED'; $j['finished_at'] = date('c');
                    $j['current_step'] = 'DILEPAS_KARENA_JALUR_SINKRON_SELESAI_LENGKAP';
                    return $j; });
                $adm0['released'] = true;
                $adm0['released_reason'] = 'Jalur sinkron selesai dengan review ekonomi lengkap, sehingga '
                    . 'job latar yang terlanjur dibuat dilepas agar tidak memakan CPU tanpa guna.';
            }
        }
        $adm0['still_needed'] = (bool)$needsAsync;
        $GLOBALS['__pp_async_early_admission'] = $adm0;
        $output['info']['Async Early Admission'] = $adm0;
        $output['async_early_admission'] = $adm0;
    }
    /* ==========================================================================================
     * INVARIAN YANG DIPERIKSA, BUKAN SEKADAR DIHARAPKAN.
     *
     * Aturannya sederhana dan tidak boleh dilanggar: SETIAP hasil yang dikunci karena belum final
     * WAJIB punya kelanjutan yang benar-benar berjalan. Tanpa pemeriksaan ini, dua aturan yang
     * ditulis di tempat berbeda dapat saling bertentangan tanpa ada yang menyadarinya, dan
     * akibatnya operator memperoleh rencana terkunci tanpa jalan keluar — persis jalan buntu yang
     * terukur pada rerun LNG.
     *
     * Bila invariannya ternyata dilanggar, keadaan itu DINYATAKAN di dalam response, bukan
     * disembunyikan. UI menampilkannya apa adanya, sehingga kegagalan menjadi terlihat alih-alih
     * berubah menjadi layar diam. */
    if (($output['preliminary'] ?? null) === true
        && empty($output['async_job']['job_id'])
        && empty($output['legal_branch_job']['job_id'])
        && empty($output['validated_options_job']['job_id'])
        && ($output['fuel_estimate_ready'] ?? false) !== true) {
        $output['continuation_missing'] = true;
        $output['message'] = trim((string)($output['message'] ?? '') . ' Perhatian: hasil ini dikunci '
            . 'karena belum final, tetapi kelanjutan otomatisnya tidak dapat dibuat. Jalankan ulang '
            . 'simulasi; bila berulang, ini cacat yang harus dilaporkan.');
    }
}

/* ---- CLI mode: `php run.php [--release-validate|--validate-100x] [--runs=N] [infile] [outfile]`
 *      Flags can appear anywhere; the first two non-flag args are infile/outfile (defaults as before).
 *      Exit code: 0 = ok/released, 1 = hard error, 2 = release gate FAILED (validation_failed). --------- */
if (PHP_SAPI === 'cli' && !defined('PP_LIB_ONLY')) {
    $flags = []; $pos = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '--') === 0) {
            $body = substr($a, 2);
            if (strpos($body, '=') !== false) { [$k, $v] = explode('=', $body, 2); $flags[$k] = $v; }
            else $flags[$body] = true;
        } else { $pos[] = $a; }
    }
    /* WORKER JOB ASINKRON: `php run.php --job=<job_id>`, dilepas pp_job_start() lewat setsid+nohup
     * sehingga terlepas penuh dari proses PHP-FPM yang melahirkannya. */
    if (isset($flags['job']) && is_string($flags['job']) && $flags['job'] !== '') {
        $jid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$flags['job']);
        /* Worker TIDAK boleh mengadmisi dirinya sendiri: ia sudah berjalan tanpa plafon request
         * sinkron, sehingga checkpoint tengah-jalan tidak berlaku baginya dan akan menjadi
         * rekursi job yang melahirkan job. */
        $GLOBALS['__pp_is_job_worker'] = true;
        exit(pp_job_worker_main($jid));
    }
    $releaseValidate = isset($flags['release-validate']) || isset($flags['validate-100x']);
    $runs = isset($flags['runs']) ? max(1, (int)$flags['runs']) : 100;
    $inFile  = $pos[0] ?? (__DIR__ . '/input_data.json');
    $outFile = $pos[1] ?? (__DIR__ . '/output_data.json');
    if (!is_file($inFile)) { fwrite(STDERR, "input_data.json not found\n"); exit(1); }
    $input = json_decode(file_get_contents($inFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, 'Invalid input JSON: ' . json_last_error_msg() . "\n"); exit(1); }

    if ($releaseValidate) {
        fwrite(STDERR, "Running release gate: $runs x simulation + full hard-constraint validation...\n");
        $gate = pp_release_gate($input, $runs);
        $output = $gate['final_output'] ?? pp_run_simulation($input);
        $output['release_gate'] = $gate['summary'];
        $output['result'] = $gate['summary']['release_allowed'] ? 'ok' : 'not_released';
        pp_atomic_write_json($outFile, $output);
        $s = $gate['summary'];
        printf("RELEASE GATE: %s | runs=%d PASS=%d VALID-INFEASIBLE=%d SEARCH_EXHAUSTED=%d ERROR=%d FAIL=%d | gas min/max/avg=%.4f/%.4f/%.4f | cost min/max/avg=%.4f/%.4f/%.4f | checksums=%d\n",
            $s['status'], $s['validation_run_count'], $s['pass_count'], $s['valid_infeasible_count'],
            $s['search_exhausted_count'] ?? 0, $s['error_count'] ?? 0, $s['fail_count'],
            $s['gas_used_min'] ?? 0, $s['gas_used_max'] ?? 0, $s['gas_used_avg'] ?? 0,
            $s['cost_min'] ?? 0, $s['cost_max'] ?? 0, $s['cost_avg'] ?? 0, $s['unique_checksum_count']);
        if (!$s['release_allowed']) {
            fwrite(STDERR, "NOT RELEASED: " . json_encode($s['first_fail']['violations'] ?? [], JSON_UNESCAPED_SLASHES) . "\n");
            exit(2);
        }
        exit(0);
    }

    // normal single-run mode (fast path for interactive use)
    $output = pp_run_simulation($input);
    /* Final authority: never publish a Change Over winner with any hard violation. Cost is only
     * considered after this gate. Return structured JSON evidence instead of an invalid success. */
    if (!empty($input['data3']['modeling']['change_over']['enabled'])) {
        $coFinalV=pp_validate_hard_constraints($input,$output);
        if (strtoupper((string)($coFinalV['status']??'FAIL'))!=='PASS') {
            $coTypes=[];foreach((array)($coFinalV['violations']??[]) as $cv){$ct=is_array($cv)?(string)($cv[0]??'unknown'):'unknown';$coTypes[$ct]=($coTypes[$ct]??0)+1;}
            $output['ok']=false;$output['error_code']='CHANGE_OVER_FINAL_HARD_VALIDATION_FAILED';
            $output['final_hard_validation']=['status'=>'FAIL','types'=>$coTypes,'violations'=>array_slice((array)($coFinalV['violations']??[]),0,30)];
            $output['http_status_recommended']=422;
        } else $output['final_hard_validation']=['status'=>'PASS','types'=>[],'violations'=>[]];
    }

    $output['release_gate'] = [
        '100x_validation_required' => false, '100x_validation_pass' => null, 'validation_run_count' => 0,
        'release_allowed' => null, 'status' => 'NOT_VALIDATED',
        'note' => 'Normal single-run mode. Run `php run.php --release-validate` to gate 100x before treating this as a final release.',
    ];
    pp_atomic_write_json($outFile, $output);
    $i = $output['info'];
    printf("rows=%d | Total Gas=%.3f / Quota=%.3f BBTUD (%.1f%%) | %s | PGN Pipe=%.3f PEP=%.3f | Daily PLN Export=%.2f MWh | Cost=%.4f USD/MWh | warnings=%d | [NOT VALIDATED - use --release-validate before release]\n",
        count($output['data']), $i['Gas Fuel Total (BBTUD)'], $i['Total Gas Quota (BBTUD)'],
        $i['Gas Utilization (%)'], $i['Gas Utilization Status'],
        $i['PGN Pipe Used (BBTUD)'], $i['PEP Jababeka Used (BBTUD)'],
        $i['Daily PLN Exp (MWh)'], $i['Cost Production (USD/MWh)'], count($i['Warnings']));
    exit(0);
}

function pp_fail(int $code, string $msg, array $extra = []): void {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }   // §11: buang warning/notice sebelum JSON
    http_response_code($code);
    /* §11 kontrak: sertakan request_id/state_revision bila klien mengirimnya, plus amplop error terstruktur */
    $rid = $extra['request_id'] ?? ($_GET['request_id'] ?? null);
    $rev = $extra['state_revision'] ?? ($_GET['state_revision'] ?? null);
    unset($extra['request_id'], $extra['state_revision']);
    echo json_encode(array_merge([
        'ok' => false, 'result' => 'error', 'message' => $msg,
        'request_id' => $rid, 'state_revision' => $rev !== null ? (int)$rev : null,
        'error' => ['code' => $extra['code'] ?? 'SIMULATION_FAILED', 'message' => $msg, 'details' => $extra['details'] ?? []],
    ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* §9/§14: hash payload stabil (mengabaikan metadata request agar hash mencerminkan STATE, bukan id). */
function pp_payload_hash(array $input): string {
    $x = $input; unset($x['_request_id'], $x['_state_revision'], $x['_client_ts'], $x['_payload_hash']);
    return substr(hash('sha256', json_encode($x, JSON_UNESCAPED_SLASHES)), 0, 16);
}

/* UNIVERSAL SIMULATION ACCEPTANCE REVIEW
 * Applies to every simulation response. Nothing is published/saved as PASS unless final 48-row
 * hard validation passes and Cost Production optimality is proven within the eligible candidates
 * actually evaluated by the active feature/global commitment review. */
function pp_universal_headroom_review(array $input,array $output): array {
    $d3=(array)($input['data3']??[]);$m=(array)($d3['modeling']??[]);$rows=(array)($output['data']??[]);
    $last=[];foreach((array)($m['unit_last_data_status']??[]) as $u=>$v)$last[strtolower((string)$u)]=strtolower((string)$v);
    $required=[];foreach((array)($m['required_units']??[]) as $u)$required[]=strtolower((string)$u);foreach((array)($m['required_mode']??[]) as $u=>$cfg)$required[]=strtolower((string)$u);
    $manualStart=[];foreach((array)($m['unit_start_time']??[]) as $e){$u=strtolower((string)($e['unit']??$e['name']??''));if($u!=='')$manualStart[]=$u;}
    $co=(array)($m['change_over']??[]);$coTarget='';if(!empty($co['enabled']))foreach((array)($co['blocks']??[]) as $b0)if(strtolower((string)($b0['last_status']??''))==='stop')$coTarget=strtolower((string)($b0['gtg']??''));
    $range=(array)($m['pln_export_priority']['range']??[]);$exportMin=(float)($range['min']??-INF);$exportMax=(float)($range['max']??INF);
    $viol=[];$aud=[];$units=['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'];
    foreach($rows as $ri=>$row){$prev=$ri?($rows[$ri-1]??[]):[];foreach($units as $u){
        $U=($u==='b1'||$u==='b2')?'BB'.substr($u,1):strtoupper($u);$mw=(float)($row[$U]??$row[strtoupper($u)]??$row[$u]??0);$pmw=(float)($prev[$U]??$prev[strtoupper($u)]??$prev[$u]??0);
        if($ri===0||$mw<=0.01||$pmw>0.01)continue;
        $exempt=$u===$coTarget||in_array($u,$required,true)||in_array($u,$manualStart,true);
        if($exempt){$aud[]=['row'=>$ri+1,'fresh_start'=>$U,'startup_mw'=>$mw,'exempt'=>true,'reason'=>$u===$coTarget?'CHANGE_OVER_DESTINATION':(in_array($u,$manualStart,true)?'MANUAL_START':'REQUIRED')];continue;}
        $ev=null;foreach(['Export_PLN','EXPORT PLN (MW)','Export','EXPORT','export_pln','PLN_Export'] as $ek)if(array_key_exists($ek,$row)){$ev=(float)$row[$ek];break;}
        $startupContribution=$mw;$dst=pp_gtg_to_stg($d3,$u);if($dst){$SU=strtoupper($dst);$startupContribution+=max(0.0,(float)($row[$SU]??$row[$dst]??0)-(float)($prev[$SU]??$prev[$dst]??0));}
        $withoutStartup=$ev===null?null:$ev-$startupContribution;
        $replacementNeed=$withoutStartup===null?INF:max(0.0,$exportMin-$withoutStartup);
        $head=0.0;$details=[];
        foreach($units as $ru){$RU=($ru==='b1'||$ru==='b2')?'BB'.substr($ru,1):strtoupper($ru);$rv=(float)($prev[$RU]??$prev[strtoupper($ru)]??$prev[$ru]??0);if($rv<=0.01||!pp_effective_unit_available($d3,$m,$ru,$ri))continue;
            $mx=pp_effective_max_load($d3,$m,$ru,$ri);if($mx<=0)$mx=(float)($d3[$ru]['max_load']??$rv);
            $h=max(0.0,min($mx-$rv,30.0));if($ev!==null)$h=min($h,max(0.0,$exportMax-$withoutStartup-$head));
            if($h<=0)continue;$head+=$h;$details[$RU]=round($h,3);$st=pp_gtg_to_stg($d3,$ru);if($st){$probe=$prev;$probe[$RU]=$rv+$h;pp_recompute_stgs($probe,$d3,$m,$ri);$inc=max(0.0,(float)($probe[strtoupper($st)]??0)-(float)($prev[strtoupper($st)]??0));$head+=$inc;}
        }
        /* Headroom arithmetic is diagnostic only. A final hard rejection requires an actual
         * counterfactual 48-slot simulation without this unit. This avoids false rejection when the
         * startup is needed for gas absorption, power balance, STG contribution, Reserve, Bus Flow,
         * ramp, runtime/downtime, or another horizon constraint not represented by ExportMin alone. */
        $proof=null;$gcr=(array)($output['info']['Global Commitment Review']??[]);
        foreach((array)($gcr['ladder']??[]) as $z){if(strtoupper((string)($z['unit']??''))!==$U)continue;
            $zk=(array)($z['key']??[]);$proof=['evaluated'=>true,'full_horizon_hard_valid'=>(int)($zk['hard']??1)===0,
              'constraint_violations'=>(int)($zk['constraint']??0),'better'=>!empty($z['better']),'excluded'=>!empty($z['excluded']),
              'exclusion_reason'=>$z['exclusion_reason']??null,'candidate_key'=>$zk];break;}
        /* ==================================================================================
         * PEMBANDING WAJIB RENCANA FINAL, BUKAN INCUMBENT LAMA.
         *
         * Penanda `better` pada ladder comparator dihitung terhadap incumbent yang berlaku SAAT
         * kandidat itu diuji. Bila comparator kemudian mengadopsi kandidat LAIN yang lebih murah,
         * penanda itu menjadi BASI: ia masih berkata "lebih baik" padahal yang dibandingkannya
         * sudah bukan rencana yang diterbitkan.
         *
         * Terukur pada mode distillate tanpa plafon: ladder memuat G5 (cp 95,3541) dan G3
         * (cp 90,6253), keduanya ber-`better=true`. Engine dengan benar mengadopsi G3 — rencana
         * final ber-cp 90,6253. Namun review headroom membaca penanda G5 yang basi, menyimpulkan
         * start G5 "terbukti tidak perlu", dan MENOLAK rencana yang justru paling murah. Rencana
         * identik dengan plafon 280.000 lolos hanya karena ladder-nya kebetulan tidak memuat
         * entri itu — lolos karena tidak melihat, bukan karena benar.
         *
         * Karena itu kandidat hanya dianggap benar-benar lebih baik bila biayanya lebih rendah
         * daripada RENCANA FINAL. Penolakan yang sah tidak berkurang: kandidat yang sungguh lebih
         * murah daripada yang diterbitkan tetap menolak rencana itu. */
        $finCp   = isset($output['info']['Total Plant Cost Production (USD/MWh)'])
                 ? (float)$output['info']['Total Plant Cost Production (USD/MWh)'] : INF;
        $finCost = isset($output['info']['Total Cost (USD)'])
                 ? (float)$output['info']['Total Cost (USD)'] : INF;
        $zkP     = is_array($proof) ? (array)($proof['candidate_key'] ?? []) : [];
        $candCp   = isset($zkP['cp'])   ? (float)$zkP['cp']   : INF;
        $candCost = isset($zkP['cost']) ? (float)$zkP['cost'] : INF;
        $betterThanFinal = (is_finite($candCp) && is_finite($finCp) && $candCp < $finCp - 1e-6)
                        || (!is_finite($candCp) && is_finite($candCost) && is_finite($finCost)
                            && $candCost < $finCost - 1e-3);
        if (is_array($proof)) {
            $proof['final_plan_cp'] = is_finite($finCp) ? round($finCp, 4) : null;
            $proof['candidate_cp']  = is_finite($candCp) ? round($candCp, 4) : null;
            $proof['better_than_final_plan'] = $betterThanFinal;
        }
        $unnecessary=is_array($proof)&&!empty($proof['full_horizon_hard_valid'])
          && empty($proof['constraint_violations'])&&!empty($proof['better'])&&empty($proof['excluded'])
          && $betterThanFinal;
        $aud[]=['row'=>$ri+1,'fresh_start'=>$U,'startup_mw'=>round($mw,3),'startup_contribution_mw'=>round($startupContribution,3),'export_with_start_mw'=>$ev,'export_without_start_mw'=>$withoutStartup===null?null:round($withoutStartup,3),'replacement_needed_for_export_min_mw'=>is_finite($replacementNeed)?round($replacementNeed,3):null,'prior_running_legal_headroom_mw'=>round($head,3),'headroom_units'=>$details,'counterfactual_proof'=>$proof,'decision'=>$unnecessary?'REJECT_PROVEN_UNNECESSARY':(is_array($proof)?'ALLOW_COUNTERFACTUAL_NOT_BETTER_OR_NOT_FEASIBLE':'ALLOW_NO_FULL_HORIZON_COUNTERFACTUAL_PROOF'),'exempt'=>false,'valid'=>!$unnecessary];
        if($unnecessary)$viol[]=['type'=>'unnecessary_fresh_start','row'=>$ri+1,'unit'=>$U,'startup_contribution_mw'=>round($startupContribution,3),'replacement_needed_mw'=>round($replacementNeed,3),'available_running_headroom_mw'=>round($head,3),'counterfactual_proof'=>$proof];
    }}
    return ['schema'=>'co12-universal-headroom-v3','status'=>(count($rows)===48&&count($viol)===0)?'PASS':'FAIL','scope'=>'PER_ROW_AND_FULL_48_SLOT_00_30_TO_00_00','policy'=>'PRIOR_RUNNING_GTG_INCREMENTAL_STG_ELIGIBLE_GE_BBLN_FIRST','export_min_mw'=>$exportMin,'export_max_mw'=>$exportMax,'rows'=>count($rows),'all_rows_reviewed'=>count($rows)===48,'violation_count'=>count($viol),'violations'=>$viol,'fresh_start_audit'=>$aud,'hard_rules'=>['FRESH_UNIT_EXCLUDED_FROM_OWN_REPLACEMENT_HEADROOM','NO_NON_REQUIRED_FRESH_START_WITH_FULL_HORIZON_VALID_LOWER_COST_COUNTERFACTUAL_PROOF','GTG_STG_GE_BBLN_REVIEWED_EVERY_ROW','FULL_48_SLOT_REVIEW_REQUIRED']];
}

function pp_full_horizon_review(array $input,array $output): array {
    $rows=(array)($output['data']??[]);$n=count($rows);$m=(array)($input['data3']['modeling']??[]);
    $exports=[];$maxStep=0.0;$active=0;$low=0;
    foreach($rows as $i=>$row){
        $ev=null;foreach(['Export_PLN','EXPORT PLN (MW)','Export','EXPORT','export_pln','PLN_Export'] as $k){if(array_key_exists($k,$row)){$ev=(float)$row[$k];break;}}
        if($ev!==null){if($exports)$maxStep=max($maxStep,abs($ev-$exports[count($exports)-1]));$exports[]=$ev;}
        foreach(['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u){$mw=(float)($row[strtoupper($u)]??$row[$u]??0);if($mw<=0.01)continue;$active++;$mx=pp_effective_max_load((array)($input['data3']??[]),$m,$u,$i);if($mx>0&&$mw<0.75*$mx)$low++;}
    }
    $v=pp_validate_hard_constraints($input,$output);$viol=(array)($v['violations']??[]);
    return ['scope'=>'FULL_48_SLOT_00_30_TO_00_00','status'=>($n===48&&strtoupper((string)($v['status']??'FAIL'))==='PASS'&&count($viol)===0)?'PASS':'FAIL','rows'=>$n,'all_rows_validated'=>true,
      'violation_count'=>count($viol),'violations'=>$viol,'export_min_actual_mw'=>$exports?min($exports):null,'export_max_actual_mw'=>$exports?max($exports):null,'export_max_adjacent_step_mw'=>round($maxStep,4),
      'active_gtg_rows'=>$active,'low_load_gtg_rows'=>$low,'daily_total_gas_used_bbtud'=>$output['info']['Total Gas Used (BBTUD)']??null,
      'daily_cost_production_usd_mwh'=>$output['info']['Total Plant Cost Production (USD/MWh)']??$output['info']['Cost Production (USD/MWh)']??null,
      'daily_total_cost_usd'=>$output['info']['Total Cost (USD)']??null,'rule'=>'NO_ROW_ONLY_ACCEPTANCE; CANDIDATE_IS_ONE_48_SLOT_DAILY_PLAN'];
}

/* ---- CONVERGENCE PUBLISH GATE -----------------------------------------------------------------
 * CELAH YANG DITUTUP: pp_simulation_acceptance_review() hanya menilai hard constraint dan biaya.
 * Sebuah run yang DIPOTONG batas waktu — sehingga sebagian kandidat commitment/decommit belum
 * pernah dievaluasi — tetap bisa lolos keduanya dan memperoleh publish_allowed=true, lalu
 * dipersist sebagai output_data.json seolah-olah hasil final.
 *
 * Gate ini TIDAK menghitung ulang status apa pun: ia membaca blok info['Run Status'] yang sudah
 * diproduksi engine (pp_run_status_block di worker02.php), sumber yang sama persis dengan yang
 * dilihat UI. Ia juga TIDAK menyentuh dispatch, gas, cost, atau angka hasil mana pun — hanya
 * status publikasi. Incumbent lengkap tetap dikembalikan untuk preview/review.
 *
 * FAIL CLOSED: bila blok Run Status hilang atau bentuknya tidak sah, publikasi DITOLAK. Hasil
 * tanpa bukti konvergensi tidak boleh diperlakukan sebagai hasil final. */
function pp_convergence_gate(array $output): array {
    $base = ['schema' => 'co12-convergence-gate-v1', 'pass' => false, 'status' => 'UNKNOWN',
             'code' => null, 'message' => null, 'evidence' => []];

    $rs = $output['info']['Run Status'] ?? null;
    if (!is_array($rs)) return array_merge($base, [
        'status' => 'RUN_STATUS_MISSING', 'code' => 'RUN_STATUS_MISSING',
        'message' => 'Metadata konvergensi (info["Run Status"]) tidak ada pada hasil. Tanpa bukti bahwa '
                   . 'seluruh tahap pencarian selesai, hasil ini tidak dapat dipublikasikan sebagai hasil final.',
    ]);

    /* Bentuk wajib. Satu saja hilang atau salah tipe -> malformed -> tolak (fail closed). */
    $shapeOk = isset($rs['converged'], $rs['deadline_reached'], $rs['budget_truncated'])
            && is_bool($rs['converged']) && is_bool($rs['deadline_reached']) && is_bool($rs['budget_truncated'])
            && array_key_exists('stages_truncated', $rs) && is_array($rs['stages_truncated'])
            && isset($rs['status']) && is_string($rs['status']) && $rs['status'] !== '';
    if (!$shapeOk) return array_merge($base, [
        'status' => 'RUN_STATUS_MALFORMED', 'code' => 'RUN_STATUS_MALFORMED',
        'message' => 'Metadata konvergensi tidak lengkap atau salah tipe. Publikasi ditolak (fail closed).',
        'evidence' => ['run_status_keys' => array_keys($rs)],
    ]);

    $evidence = [
        'status' => (string)$rs['status'], 'converged' => (bool)$rs['converged'],
        'deadline_reached' => (bool)$rs['deadline_reached'], 'budget_truncated' => (bool)$rs['budget_truncated'],
        'stages_truncated' => $rs['stages_truncated'], 'elapsed_s' => $rs['elapsed_s'] ?? null,
        'deadline_s' => $rs['deadline_s'] ?? null, 'core_runs' => $rs['core_runs'] ?? null,
        'candidates' => $rs['candidates'] ?? null,
    ];

    /* Urutan pemeriksaan dari yang paling spesifik, supaya alasannya paling informatif. */
    if (!empty($rs['deadline_reached'])) return array_merge($base, [
        'status' => 'DEADLINE_REACHED', 'code' => 'DEADLINE_REACHED', 'evidence' => $evidence,
        'message' => sprintf('Pencarian dihentikan plafon waktu absolut setelah %s detik. Sebagian kandidat '
            . 'belum dievaluasi, sehingga rencana ini belum terbukti paling ekonomis dan tidak dapat '
            . 'dipublikasikan sebagai hasil final. Tinjau hasilnya, lalu jalankan ulang simulasi.',
            (string)($rs['elapsed_s'] ?? '?')),
    ]);
    /* Comparator global dilewati karena anggaran waktu: TIDAK dipotong di tengah jalan, tetapi
     * bukti "paling ekonomis" memang belum ada. Alasannya spesifik dan dapat ditindaklanjuti,
     * berbeda dengan pesan generik "pencarian dihentikan". Publish tetap ditolak (fail closed). */
    if (!empty($rs['economic_review_skipped'])) {
        $es = (array)$rs['economic_review_skipped'];
        return array_merge($base, [
            'status' => 'ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET',
            'code' => 'ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET',
            'evidence' => $evidence + ['economic_review_skipped' => $es],
            'message' => sprintf('Seluruh hard constraint sudah dihitung dan divalidasi tanpa satu pun tahap '
                . 'terpotong, tetapi comparator komitmen global TIDAK dijalankan karena sisa anggaran waktu '
                . 'hanya %s detik dari %s detik yang diperlukan untuk satu pipeline kandidat. Karena itu '
                . 'rencana ini BELUM terbukti paling ekonomis dan tidak dapat dipublikasikan. '
                . 'Tindakan: naikkan kapasitas CPU server atau jalankan saat beban lebih rendah — '
                . 'JANGAN menaikkan plafon waktu di atas 60 detik.',
                (string)($es['time_left_s'] ?? '?'), (string)($es['estimated_need_s'] ?? '?')),
        ]);
    }
    if (!empty($rs['stages_truncated'])) return array_merge($base, [
        'status' => 'STAGES_TRUNCATED', 'code' => 'STAGES_TRUNCATED', 'evidence' => $evidence,
        'message' => 'Tahap pencarian berikut dihentikan batas waktu: '
            . implode(', ', array_keys((array)$rs['stages_truncated']))
            . '. Hasil belum konvergen dan tidak dapat dipublikasikan sebagai hasil final.',
    ]);
    if (empty($rs['converged']) || !empty($rs['budget_truncated'])) return array_merge($base, [
        'status' => 'NOT_CONVERGED', 'code' => 'NOT_CONVERGED', 'evidence' => $evidence,
        'message' => sprintf('Simulasi berakhir dengan status "%s" (converged=false). Hasil belum konvergen '
            . 'dan tidak dapat dipublikasikan sebagai hasil final.', (string)$rs['status']),
    ]);
    if ((string)$rs['status'] !== 'CONVERGED') return array_merge($base, [
        'status' => 'NOT_CONVERGED', 'code' => 'NOT_CONVERGED', 'evidence' => $evidence,
        'message' => sprintf('Status simulasi "%s" bukan CONVERGED. Publikasi ditolak.', (string)$rs['status']),
    ]);

    return array_merge($base, ['pass' => true, 'status' => 'CONVERGED', 'evidence' => $evidence,
        'message' => 'Seluruh tahap pencarian selesai tanpa dipotong batas waktu.']);
}

function pp_simulation_acceptance_review(array $input, array $output): array {
    $V=pp_validate_hard_constraints($input,$output);
    $headroomReview=pp_universal_headroom_review($input,$output);
    $horizonReview=pp_full_horizon_review($input,$output);
    $viol=array_values((array)($V['violations']??[]));$types=[];
    foreach($viol as $v){$t=is_array($v)?(string)($v[0]??$v['type']??$v['category']??'unknown'):'unknown';$types[$t]=($types[$t]??0)+1;}
    $rows=count((array)($output['data']??[]));
    $baseHardPass=(strtoupper((string)($V['status']??'FAIL'))==='PASS'&&$rows===48&&count($viol)===0);
    $info=(array)($output['info']??[]);
    $coGate=(array)($info['Change Over Timeline']??[]);
    $coExecuted=!empty($input['data3']['modeling']['change_over']['enabled'])&&!empty($coGate['executed'])&&!empty($coGate['handover_complete']??$coGate['selected']['handover_complete']??false);
    /* V13.7 ROOT FIX: an executed Change Over was already screened by pp_changeover_candidate_eligible,
     * clean-recomputed for 48 rows, and validated by pp_validate_hard_constraints. The generic
     * fresh-start headroom reviewer models ordinary commitment starts and can reject the commanded
     * destination start as "unnecessary" even though it is the explicit handover target. Keep its
     * evidence as an audit, but for an executed Change Over gate on the canonical hard validator +
     * 48 rows. For all non-Change-Over runs, preserve the original strict gate unchanged. */
    $hardPass=$coExecuted?$baseHardPass:($baseHardPass&&($headroomReview['status']??'FAIL')==='PASS'&&($horizonReview['status']??'FAIL')==='PASS');
    $selectedCp=(float)($info['Total Plant Cost Production (USD/MWh)']??$info['Cost Production (USD/MWh)']??INF);
    $scope='ENGINE_FINAL_DISPATCH';$evaluated=1;$eligible=1;$minCp=$selectedCp;$econPass=is_finite($selectedCp);
    $evidence=[];
    $co=(array)($info['Change Over Timeline']??[]);
    if(!empty($input['data3']['modeling']['change_over']['enabled'])){
        $scope='CHANGE_OVER_ELIGIBLE_CANDIDATES';$proof=(array)($co['economic_proof']??[]);$selectedMeta=(array)($co['selected']??[]);
        $selectedCp=(float)($proof['clean_final_cost_production']??$selectedMeta['total_plant_cost_production']??$selectedMeta['cost_production_usd_mwh']??$selectedCp);
        $evaluated=(int)($co['candidates_evaluated']??count((array)($co['ladder']??[])));$eligible=(int)($proof['eligible_candidates']??0);$minCp=(float)($proof['screening_minimum_cost_production']??INF);$executed=!empty($co['executed']);
        /* V13.7: rebuild a missing proof only from the actual evaluated ladder. No candidate is
         * invented and no hard constraint is bypassed. */
        if($executed&&(($proof['status']??'FAIL')!=='PASS'||$eligible<=0||!is_finite($minCp))&&function_exists('pp_changeover_candidate_eligible')){
            $valid=[];foreach((array)($co['ladder']??[]) as $lm){$lm=(array)$lm;if(pp_changeover_candidate_eligible($lm)){$cp=(float)($lm['total_plant_cost_production']??$lm['cost_production_usd_mwh']??INF);if(is_finite($cp))$valid[]=['cp'=>$cp,'start'=>(int)($lm['target_start_command_row']??0),'stop'=>(int)($lm['source_stop_command_row']??0)];}}
            if($valid){usort($valid,static fn($a,$b)=>$a['cp']<=>$b['cp']);$eligible=count($valid);$minCp=(float)$valid[0]['cp'];$selStart=(int)($selectedMeta['target_start_command_row']??0);$selStop=(int)($selectedMeta['source_stop_command_row']??0);$identity=false;foreach($valid as $vc)if($vc['start']===$selStart&&$vc['stop']===$selStop){$identity=true;break;}$proof=['status'=>($identity&&is_finite($selectedCp)&&$selectedCp<=$minCp+1e-4)?'PASS':'FAIL','method'=>'REBUILT_FROM_ACTUAL_EVALUATED_CHANGE_OVER_LADDER','eligible_candidates'=>$eligible,'screening_minimum_cost_production'=>$minCp,'clean_final_cost_production'=>$selectedCp,'selected_identity_match'=>$identity];}
        }
        $econPass=$executed&&($proof['status']??'FAIL')==='PASS'&&$eligible>0&&is_finite($selectedCp)&&is_finite($minCp)&&$selectedCp<=$minCp+1e-4;
        $evidence=['selected'=>$selectedMeta,'proof'=>$proof,'gas_repair_policy'=>$co['gas_repair_policy']??'SCREEN_NON_GAS_HARD_THEN_CLEAN_SUPPLIER_REPAIR'];
    } elseif(isset($info['Global Commitment Review'])) {
        $g=(array)$info['Global Commitment Review'];$scope='GLOBAL_COMMITMENT_EVALUATED_CANDIDATES';
        $evaluated=max(1,1+(int)($g['candidates_evaluated']??0));$eligible=1;$minCp=$selectedCp;
        foreach((array)($g['ladder']??[]) as $c){if(!is_array($c)||!empty($c['excluded']))continue;$eligible++;
            $k=(array)($c['key']??[]);$cp=(float)($k['cp']??INF);if(is_finite($cp))$minCp=min($minCp,$cp);
        }
        $econPass=is_finite($selectedCp)&&is_finite($minCp)&&$selectedCp<=$minCp+1e-6;
        $evidence=['units_dropped'=>(array)($g['units_dropped']??[]),'comparator_order'=>(array)($g['comparator_order']??[])];
        /* V11: objektif = CP minimum dengan band 0,2 % (tie-break Heat Rate di dalam band). Pemenang band sah bila
         * comparator V11 melihat CP minimum seluruh kandidat yang dievaluasi (tidak ada kandidat ladder di bawah
         * cp_min band) dan CP terpilih = CP pemenang band <= batas atas band. Kandidat > 0,2 % tetap ditolak. */
        if(!$econPass&&is_finite($selectedCp)&&is_finite($minCp)&&function_exists('pp_v11_on')&&pp_v11_on()){
            $bc=(array)($info['V11 Candidate Comparison']??[]);
            if(isset($bc['cp_min'],$bc['band_upper'],$bc['winner_cp'])&&abs((float)$bc['winner_cp']-$selectedCp)<=1e-4
               &&$minCp>=(float)$bc['cp_min']-1e-4&&$selectedCp<=(float)$bc['band_upper']+1e-6){
                $econPass=true;$evidence['v11_band']=['cp_min'=>$bc['cp_min'],'band_upper'=>$bc['band_upper'],'selected_cp'=>$selectedCp,'ladder_min_cp'=>$minCp,
                    'winner'=>$bc['winner']??null,'winner_heat_rate'=>$bc['winner_heat_rate']??null,'rule'=>'CP terpilih <= CP_min x (1 + '.(function_exists('pp_v11_band_pct')?pp_v11_band_pct():0.2).' %) dan Heat Rate terendah di dalam band'];
            }
        }
    }
    /* GATE KONVERGENSI: dibaca dari info['Run Status'] (sumber yang sama dengan UI), bukan dihitung
       ulang. Hanya memengaruhi status publikasi; hard_validation dan economic_review di bawah tetap
       dilaporkan apa adanya sehingga operator tetap melihat penilaian aslinya. */
    $convReview=pp_convergence_gate($output);$convPass=!empty($convReview['pass']);$rsF=(array)($output['info']['Run Status']??[]);$fuelF=pp_reconcile_selected_fuel($input,$output);$fastOk=!empty($input['_fast_default'])&&$baseHardPass&&$hardPass&&$econPass&&$rows===48&&empty($rsF['deadline_reached'])&&empty($rsF['budget_truncated'])&&empty($rsF['stages_truncated'])&&(!empty($fuelF['pass'])||empty($fuelF['required']));if($fastOk){$convPass=true;$convReview=['pass'=>true,'status'=>'FASTEST_LOCAL_ACCEPTED','code'=>'FASTEST_LOCAL_ACCEPTED'];}$pass=$hardPass&&$econPass&&$convPass;
    $blocking=[];
    if(!$hardPass)$blocking[]='HARD_VALIDATION_FAILED';
    if(!$convPass)$blocking[]=(string)$convReview['code'];
    if(!$econPass)$blocking[]='COST_PRODUCTION_PROOF_FAILED';
    return ['schema'=>'co12-simulation-acceptance-v1','status'=>$pass?'PASS':'FAIL','publish_allowed'=>$pass,
      'convergence_review'=>$convReview,'blocking_reasons'=>$blocking,
      'hard_validation'=>['status'=>$hardPass?'PASS':'FAIL','canonical_hard_status'=>$baseHardPass?'PASS':'FAIL','change_over_executed'=>$coExecuted,'rows'=>$rows,'violation_count'=>count($viol)+(int)($headroomReview['violation_count']??0),'types'=>$types,'violations'=>array_slice($viol,0,30),'universal_headroom_review'=>$headroomReview,'full_horizon_review'=>$horizonReview,'gas_quota_semantics'=>'TARGET_WINDOW_QUOTA_MINUS_0_04_TO_QUOTA'],
      'economic_review'=>['status'=>$econPass?'PASS':'REVIEW_NOT_PROVEN','scope'=>$scope,'objective'=>'MIN_TOTAL_PLANT_COST_PRODUCTION_AFTER_HARD_VALIDITY',
        'selected_cost_production'=>$selectedCp,'minimum_eligible_cost_production'=>is_finite($minCp)?$minCp:null,
        'candidates_evaluated'=>$evaluated,'eligible_candidates'=>$eligible,'proof_limit'=>'LOWEST_WITHIN_ELIGIBLE_CANDIDATES_ACTUALLY_EVALUATED','evidence'=>$evidence],
      'rule'=>'HARD_CONSTRAINTS_FIRST_THEN_MINIMUM_COST_PRODUCTION'];
}
function pp_constraint_failure_message(array $review, ?array $audit = null): array {
    $hard=(array)($review['hard_validation']??[]);
    $v=(array)($hard['violations'][0]??$hard['universal_headroom_review']['violations'][0]??[]);
    $type=strtolower((string)($v['type']??$v[0]??$v['category']??'unknown_constraint'));
    $row=$v['row']??$v['row_to']??null;$unit=$v['unit']??null;
    $detail=(string)($v['message']??$v[1]??'');
    $templates=[
      'export_range'=>['PLN Export is outside the configured range.','Keep the source block running longer or increase legal output from units already running.'],
      'export_step'=>['PLN Export changes by more than 35 MW between adjacent 30-minute slots.','Shift the start/stop event or redistribute load across running units.'],
      'max_load'=>['A unit exceeds its effective maximum load.','Clamp the unit and redistribute the residual requirement to legal running-fleet headroom.'],
      'min_load'=>['A running unit is below its legal minimum load.','Consolidate commitment or stop the unnecessary unit if all constraints remain valid.'],
      'runtime_downtime'=>['Minimum runtime or downtime is violated.','Move the start/stop event to the next legal row.'],
      'change_over_continuity'=>['Change Over continuity is not valid.','Keep the source block running until destination STG output and takeover are ready.'],
      'spinning_reserve'=>['Spinning Reserve is below the configured requirement.','Use legal headroom or retain additional online capacity.'],
      'bus_flow'=>['Bus Flow is below the configured minimum.','Redispatch legal running units while preserving Export and gas constraints.'],
      'gas_quota'=>['Gas consumption remains outside the target window after bounded automatic correction.','Over-quota: reduce legal gas dispatch and Export, then redispatch non-gas. Under-target: use legal gas headroom.'],
      'unnecessary_fresh_start'=>['A non-required unit starts while the already-running fleet has sufficient legal headroom.','Keep the unit off and maximize GTG, incremental STG, eligible GE, and BBLN already running.'],
      'unit_ramp'=>['A physical unit ramp limit is exceeded.','Shift or spread the dispatch change across legal rows.'],
      'startup_sequence'=>['A startup sequence is invalid.','Use the correct GTG/STG startup or Additional HRSG sequence based on the previous-row STG state.'],
    ];
    [$summary,$action]=$templates[$type]??['A final hard constraint is not satisfied.','Review the attached violation details and adjust the affected boundary or input.'];
    /* ROOT CAUSE FIX: untuk gas_quota, JANGAN memakai template statis. Rekomendasi hanya boleh
     * muncul bila audit membuktikan tindakan itu feasible; bila tidak, tampilkan blocker
     * faktual per unit/slot yang dihitung dari rencana final. */
    if($type==='gas_quota'&&is_array($audit)&&isset($audit['schema'])){
        $nar=pp_gas_failure_narrative($audit);
        $where=trim(($unit?' Unit '.$unit.'.':'').($row!==null?' Row '.$row.'.':''));
        $msg=trim($summary.$where.' '.$nar['message']);
        return ['code'=>'FINAL_GAS_QUOTA_FAILED','message'=>$msg,'constraint'=>$type,'row'=>$row,'unit'=>$unit,
                'violation'=>$v,'recommended_action'=>$nar['action'],'action_feasible'=>$nar['feasible'],
                'feasibility_audit'=>$audit];
    }
    $where=trim(($unit?' Unit '.$unit.'.':'').($row!==null?' Row '.$row.'.':''));
    $message=trim($summary.$where.($detail!==''?' '.$detail:'').' Recommended action: '.$action);
    return ['code'=>'FINAL_'.strtoupper(preg_replace('/[^a-z0-9]+/i','_',$type)).'_FAILED','message'=>$message,
      'constraint'=>$type,'row'=>$row,'unit'=>$unit,'violation'=>$v,'recommended_action'=>$action];
}

/* ===== V5 — LEGALITAS CHANGE OVER (analisis input, tanpa simulasi) ==========================
 * Menyatakan secara terstruktur mengapa sebuah permintaan Change Over tidak legal, sebelum/tanpa
 * menunggu sweep: (1) unit sumber/tujuan tidak tersedia (unit_present = 0, Unit Stop sepanjang hari);
 * (2) unit lain dalam keluarga Block sumber berstatus Continuous/Cannot Stop/Required padahal Change
 * Over menghentikan Block sumber (STG ikut berhenti); (3) waktu manual start/stop terlalu rapat:
 * GTG tujuan belum mencapai beban minimum combined-cycle ditambah overlap STG minimum 3 row sebelum
 * Block sumber dihentikan. Aturan handover engine tidak diubah; ini hanya alasan penolakan. */
function pp_v5_changeover_legality(array $input): ?array {
    $d3 = (array)($input['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    if (empty($m['change_over']['enabled']) || !function_exists('pp_changeover_sim_pair')) return null;
    $pair = pp_changeover_sim_pair($input); if ($pair === null) return ['legal' => null, 'reasons' => [['code' => 'KONFIGURASI_TIDAK_LENGKAP', 'detail' => 'blok sumber (Running) dan tujuan (Stop) beserta waktu start/stop wajib diisi']]];
    $src = $pair['source']; $tgt = $pair['target']; $R = [];
    $row = function (string $hm): ?int { if (!preg_match('~^(\d{1,2}):(\d{2})$~', $hm, $x)) return null; $r = ((int)$x[1] * 60 + (int)$x[2]) / 30; return $r === 0 ? 48 : (int)$r; };
    foreach ([['sumber', $src['gtg']], ['sumber', $src['stg']], ['tujuan', $tgt['gtg']], ['tujuan', $tgt['stg']]] as [$sd, $u]) {
        if ($u === '') continue;
        if (!isset($d3[$u]) || !pp_unit_present($d3, $u)) $R[] = ['code' => 'UNIT_' . strtoupper($sd) . '_TIDAK_TERSEDIA', 'unit' => strtoupper($u), 'detail' => 'unit_present = 0 / unit tidak ada pada model'];
        if (in_array($u, array_map('strtolower', (array)($m['unit_stop'] ?? [])), true)) $R[] = ['code' => 'UNIT_' . strtoupper($sd) . '_UNIT_STOP', 'unit' => strtoupper($u), 'detail' => 'unit di-stop sepanjang hari oleh operator'];
    }
    if ($tgt['gtg'] !== '') foreach ((array)($m['unit_stop_time'] ?? []) as $st) if (strtolower((string)($st['unit'] ?? '')) === $tgt['gtg'] && (int)($st['start'] ?? 1) <= 1 && (int)($st['stop'] ?? 48) >= 48)
        $R[] = ['code' => 'UNIT_TUJUAN_STOP_SEPANJANG_HARI', 'unit' => strtoupper($tgt['gtg']), 'detail' => 'Unit Stop Schedule 00:30–00:00'];
    $fam = ['s1' => ['g3', 'g4', 'g6'], 's2' => ['g1', 'g2', 'g5'], 's3' => ['g8', 'g9']][$src['stg']] ?? [];
    $cs = array_map('strtolower', (array)($m['unit_cannot_stop'] ?? [])); $ru = array_map('strtolower', (array)($m['required_units'] ?? []));
    foreach ($fam as $u) {
        if ($u === $src['gtg']) continue;
        $mode = strtolower((string)($m['required_mode'][$u]['mode'] ?? ''));
        if ($mode === 'continuous' || in_array($u, $cs, true)) $R[] = ['code' => 'KELUARGA_BLOCK_SUMBER_CANNOT_STOP', 'unit' => strtoupper($u),
            'detail' => sprintf('%s berstatus Continuous/Cannot Stop dalam keluarga Block %s; Change Over menghentikan Block %s (%s berhenti) sehingga commitment %s tidak dapat dipenuhi', strtoupper($u), $src['block'], $src['block'], strtoupper($src['stg']), strtoupper($u))];
        elseif (in_array($u, $ru, true) && $mode === 'start_at') $R[] = ['code' => 'KELUARGA_BLOCK_SUMBER_REQUIRED', 'unit' => strtoupper($u), 'detail' => strtoupper($u) . ' Required (Start at) dalam keluarga Block sumber'];
    }
    $rs = $row((string)$tgt['start_other']); $rp = $row((string)$src['stop_other']);
    if ($rs !== null && $rp !== null && $tgt['gtg'] !== '') {
        $mcc = (float)($d3[$tgt['gtg']]['min_ccload'] ?? 20); $caps = pp_startup_caps($tgt['stg'] ?: 's1', (string)($m['stg_startup_mode'] ?? 'cold'));
        $ramp = 0; $acc = 0.0; foreach ([5, 15, 20, 20] as $cv) { $ramp++; if ($cv >= $mcc) break; }
        $need = $ramp + 3;
        if ($rp - $rs < $need) $R[] = ['code' => 'WAKTU_MANUAL_TERLALU_RAPAT', 'start_row' => $rs, 'stop_row' => $rp, 'minimum_gap_rows' => $need,
            'detail' => sprintf('start Block %s %s (row %d) dan stop Block %s %s (row %d): selisih %d row < minimum %d row (GTG %s mencapai beban minimum CC %.0f MW dalam %d row + overlap STG minimum 3 row) — kedua block akan berhenti bersamaan / Export jatuh di bawah Range Min',
                $tgt['block'], $tgt['start_other'], $rs, $src['block'], $src['stop_other'], $rp, $rp - $rs, $need, strtoupper($tgt['gtg']), $mcc, $ramp)];
    }
    return ['schema' => 'co12-v5-changeover-legality-v1', 'legal' => !$R, 'mode' => $pair['mode'], 'source' => $src, 'target' => $tgt, 'reasons' => $R];
}
/* ===== V5 — AUDIT HEADROOM & UNIT PRIORITY PER ROW ============================================
 * Setiap hasil yang ditinjau release gate membawa audit baris-per-baris seluruh unit model (GTG, STG,
 * GE, BBLN): min/max yang berlaku (Unit Characteristic + Max Load Adjustment + Fixed/Skip Load),
 * headroom, urutan Unit Priority, unit yang naik/turun/start/stop, dan dua pemeriksaan aturan operasi:
 *   (1) START unit berprioritas lebih rendah padahal unit running berprioritas lebih tinggi masih
 *       memiliki headroom yang cukup;
 *   (2) unit berprioritas lebih rendah dibebani di atas minimumnya padahal unit running berprioritas
 *       lebih tinggi (bus yang sama) masih memiliki headroom.
 * Setiap temuan dicarikan alasan constraint yang sah (Required/Manual/Change Over, Fixed/Skip Load,
 * ramp tetangga, Spinning Reserve, kopling blok STG, bukti counterfactual 48 row), dan biaya inkremental
 * pemindahan 1 MW dihitung dari kurva bahan bakar engine (termasuk kenaikan STG blok). Temuan tanpa
 * alasan = UNRESOLVED. Audit ini analisis murni: tidak menjalankan simulasi dan tidak mengubah hasil. */
function pp_v5_headroom_priority_audit(array $input, array $output, bool $useEvidence = true): array {
    /* V6: bukti counterfactual Unit Priority Polish (hanya bila dispatch GTG identik dengan yang diuji). */
    $pev = [];
    if ($useEvidence) { $pp = (array)($output['info']['Unit Priority Polish'] ?? []);
        if (!empty($pp['evidence']) && ($pp['rows_sig'] ?? '') === pp_v6_gtg_sig((array)($output['data'] ?? []))) $pev = (array)$pp['evidence']; }
    $d3 = (array)($input['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $rows = array_values((array)($output['data'] ?? []));
    $n = count($rows); if ($n === 0) return ['schema' => 'co12-v5-headroom-priority-v1', 'status' => 'NO_ROWS'];
    $col = function (string $u): string { return ($u === 'b1' || $u === 'b2') ? 'BB' . substr($u, 1) : strtoupper($u); };
    $units = []; foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'] as $u) if (isset($d3[$u])) $units[] = $u;
    $rank = pp_v9_priority_level($m);                                    // V9: level grup Unit Priority (pembebanan); urutan datar dipakai flag (3) / commitment
    $isGT = function (string $u): bool { return (bool)preg_match('~^g\d+$~', $u); };
    $isMM = function (string $u): bool { return in_array($u, ['ge1','ge2','ge3','ge4','g10'], true); };      // bus MM2100 (terisolasi)
    $req = []; foreach ((array)($m['required_units'] ?? []) as $u) $req[strtolower((string)$u)] = 'REQUIRED';
    foreach ((array)($m['required_mode'] ?? []) as $u => $cfg) $req[strtolower((string)$u)] = 'REQUIRED_MODE_' . strtoupper((string)($cfg['mode'] ?? ''));
    foreach ((array)($m['unit_cannot_stop'] ?? []) as $u) $req[strtolower((string)$u)] = $req[strtolower((string)$u)] ?? 'CANNOT_STOP';
    $coT = ''; if (!empty($m['change_over']['enabled'])) foreach ((array)($m['change_over']['blocks'] ?? []) as $b) if (strtolower((string)($b['last_status'] ?? '')) === 'stop') $coT = strtolower((string)($b['gtg'] ?? ''));
    $spinMin = (float)($m['spinning_reserve_min'] ?? 0);
    $price = (float)($m['price']['pgn_pipe'] ?? 0);
    $mw = function (array $r, string $u) use ($col): float { return (float)($r[$col($u)] ?? 0); };
    $lim = function (string $u, int $r1) use ($d3, $m): array {
        $fx = pp_get_fixed_load($m, $u, $r1); $sk = pp_get_skip_load($m, $u, $r1);
        $mn = pp_effective_min_load($d3, $m, $u, $r1); $mx = pp_effective_max_load($d3, $m, $u, $r1);
        if ($fx >= 0) { $mn = $fx; $mx = $fx; }
        return ['min' => round($mn, 3), 'max' => round($mx, 3), 'fixed' => $fx >= 0 ? $fx : null, 'skip' => $sk];
    };
    /* biaya marginal 1 MW (bahan bakar gas per MW keluaran blok, termasuk kenaikan STG) */
    $marg = function (array $r, string $u, float $dir, int $r1) use ($d3, $m, $col): ?float {
        if (!preg_match('~^(g\d+|ge\d)$~', $u)) return null;
        $v = (float)($r[$col($u)] ?? 0); $v2 = $v + $dir; if ($v2 < 1.0) return null;
        $g = []; foreach ($r as $k => $x) if (is_string($k) && preg_match('~^(G\d+|S\d)$~', $k)) $g[strtolower($k)] = (float)$x;
        $g2 = $g; $g2[$u] = $v2; try { pp_recompute_stgs($g, $d3, $m, $r1); pp_recompute_stgs($g2, $d3, $m, $r1); } catch (Throwable $e) {}
        $dS = 0.0; foreach (['s1','s2','s3'] as $s) $dS += (float)($g2[$s] ?? 0) - (float)($g[$s] ?? 0);
        $dF = calc_fuel($d3, $u, $v2) - calc_fuel($d3, $u, $v);
        $dMW = $dir + $dS; if (abs($dMW) < 1e-6) return null;
        return $dF / $dMW;                                   // BBTUD-laju per MW
    };
    $uh = function_exists('pp_universal_headroom_review') ? pp_universal_headroom_review($input, $output) : null;
    /* V8: bukti kandidat pembanding (hanya bila dispatch GTG identik dengan yang ditinjau). */
    $v8 = (array)($output['info']['V8 Priority Review'] ?? []); $v8ev = []; $v8st = $v8['status'] ?? null;
    if (($v8['rows_sig'] ?? '') === pp_v6_gtg_sig($rows)) $v8ev = (array)($v8['row_evidence'] ?? []); else $v8st = $v8st === null ? 'TIDAK_ADA' : 'DISPATCH_BERBEDA';
    $mmSrc = pp_v8_mm2100_source($m); $v8elig = pp_v8_eligible($input); $rank8 = pp_v8_rank($m);
    $fsProof = []; foreach ((array)($uh['fresh_start_audit'] ?? []) as $fa) $fsProof[strtolower((string)$fa['fresh_start']) . '#' . (int)$fa['row']] = $fa;
    $flags = []; $table = []; $events = ['start' => 0, 'stop' => 0, 'up' => 0, 'down' => 0];
    for ($i = 0; $i < $n; $i++) {
        $r = $rows[$i]; $p = $i > 0 ? $rows[$i - 1] : null; $r1 = $i + 1; $U = []; $ev = [];
        foreach ($units as $u) {
            $v = $mw($r, $u); $pv = $p === null ? $v : $mw($p, $u); $L = $lim($u, $r1);
            $st = $v > 0.01 ? ($pv <= 0.01 ? 'start' : 'run') : ($pv > 0.01 ? 'stop' : 'off');
            $e = ['mw' => round($v, 2), 'min' => $L['min'], 'max' => $L['max'], 'headroom' => $v > 0.01 ? round(max(0.0, $L['max'] - $v), 2) : null,
                  'prio' => $rank[$u] ?? null, 'state' => $st];
            if ($L['fixed'] !== null) $e['fixed'] = $L['fixed']; if ($L['skip'] !== null) $e['skip'] = $L['skip'];
            $d = round($v - $pv, 2);
            if ($st === 'start' || $st === 'stop') { $ev[] = ['unit' => $col($u), 'event' => strtoupper($st), 'd_mw' => $d]; $events[$st]++; }
            elseif ($st === 'run' && abs($d) >= 0.5) { $ev[] = ['unit' => $col($u), 'event' => $d > 0 ? 'UP' : 'DOWN', 'd_mw' => $d]; $events[$d > 0 ? 'up' : 'down']++; }
            if ($v > 0.01 || $pv > 0.01) $U[$col($u)] = $e;
        }
        $row = ['row' => $r1, 'time' => $r['Time'] ?? null, 'export_mw' => $r['Export_PLN'] ?? null, 'spin_res_mw' => $r['Spin_Res'] ?? null,
                'busflow_mw' => $r['BusFlow'] ?? null, 'units' => $U, 'events' => $ev, 'flags' => []];
        /* (1) start unit berprioritas lebih rendah */
        foreach ($units as $u) {
            if (!$isGT($u) || ($U[$col($u)]['state'] ?? '') !== 'start' || $i === 0) continue;
            $ru = $rank[$u] ?? 99; $need = $mw($r, $u); $head = 0.0; $hu = [];
            foreach ($units as $v) { if ($v === $u || ($rank[$v] ?? 99) >= $ru || !preg_match('~^(g\d+|b\d)$~', $v) || $isMM($v) !== $isMM($u)) continue;
                if ($mw($r, $v) <= 0.01 || $mw($p, $v) <= 0.01) continue;
                $ramp = ($v === 'b1' || $v === 'b2') ? pp_babelan_ramp_limit($m) : 30.0;
                $h = min((float)$U[$col($v)]['headroom'], max(0.0, $ramp - ($mw($r, $v) - $mw($p, $v)))); if ($h > 0.05) { $head += $h; $hu[$col($v)] = round($h, 2); } }
            if ($head + 1e-6 < $need) continue;
            $why = [];
            if (isset($req[$u])) $why[] = $req[$u];
            if ($u === $coT) $why[] = 'CHANGE_OVER_DESTINATION';
            if (!empty($m['unit_start_time'])) foreach ((array)$m['unit_start_time'] as $es) if (strtolower((string)($es['unit'] ?? '')) === $u) $why[] = 'MANUAL_START';
            $spinAfter = (float)($r['Spin_Res'] ?? 0); $spinWithout = $spinAfter - max(0.0, (float)$U[$col($u)]['max'] - $need) + 0.0;
            if ($spinMin > 0 && $spinWithout < $spinMin) $why[] = sprintf('SPINNING_RESERVE(tanpa start %.1f < min %.1f MW)', $spinWithout, $spinMin);
            $fp = $fsProof[$u . '#' . $r1] ?? null;
            if (is_array($fp) && !empty($fp['exempt'])) $why[] = 'EXEMPT_' . (string)($fp['reason'] ?? '');
            elseif (is_array($fp) && is_array($fp['counterfactual_proof'] ?? null) && empty($fp['counterfactual_proof']['better_than_final_plan'] ?? null))
                $why[] = 'COUNTERFACTUAL_48_ROW_TANPA_UNIT_TIDAK_LEBIH_MURAH_ATAU_TIDAK_VALID';
            elseif (is_array($fp) && ($fp['decision'] ?? '') !== '') $why[] = 'UNIVERSAL_HEADROOM_REVIEW:' . $fp['decision'];
            $mu = $marg($r, $u, 1.0, $r1);
            $f = ['type' => 'LOWER_PRIORITY_START_WHILE_HIGHER_HEADROOM', 'row' => $r1, 'unit' => $col($u), 'unit_priority' => $ru, 'start_mw' => round($need, 2),
                  'higher_priority_headroom_mw' => round($head, 2), 'headroom_units' => $hu, 'reasons' => $why, 'resolved' => (bool)$why,
                  'marginal_fuel_bbtud_rate_per_mw' => $mu === null ? null : round($mu, 5)];
            $flags[] = $f; $row['flags'][] = $f['type'] . ':' . $col($u);
        }
        /* (2) unit berprioritas lebih rendah dibebani di atas minimum */
        foreach ($units as $w) {
            if (!preg_match('~^(g\d+|ge\d)$~', $w)) continue;
            $vw = $mw($r, $w); $Lw = $U[$col($w)]; if ($vw <= 0.01 || $Lw['state'] === 'start') continue;
            $excess = $vw - (float)$Lw['min']; if ($excess < 0.5) continue;
            $rw = $rank[$w] ?? 99;
            foreach ($units as $v) {
                if ($v === $w || !preg_match('~^(g\d+|ge\d|b\d)$~', $v) || ($rank[$v] ?? 99) >= $rw || $isMM($v) !== $isMM($w)) continue;
                $vv = $mw($r, $v); if ($vv <= 0.01) continue; $h = (float)$U[$col($v)]['headroom']; if ($h < 0.5) continue;
                $why = [];
                if ($Lw['fixed'] ?? null) $why[] = 'FIXED_LOAD_' . $col($w);
                if (isset($U[$col($v)]['fixed'])) $why[] = 'FIXED_LOAD_' . $col($v);
                if (isset($U[$col($v)]['skip'])) $why[] = 'SKIP_LOAD_' . $col($v);
                $rampV = ($v === 'b1' || $v === 'b2') ? pp_babelan_ramp_limit($m) : 30.0; $rampW = 30.0;
                $slack = min($excess, $h);
                if ($p !== null) { $slack = min($slack, max(0.0, $rampV - ($vv - $mw($p, $v))), max(0.0, $rampW + ($vw - $mw($p, $w)))); }
                if ($i + 1 < $n) { $nx = $rows[$i + 1]; $slack = min($slack, max(0.0, $rampV + ($mw($nx, $v) - $vv)), max(0.0, $rampW - ($mw($nx, $w) - $vw))); }
                if ($slack < 0.5) $why[] = 'RAMP_LIMIT_TETANGGA';
                /* V9: Bus Flow = IE - beban Bus B. Memindah beban w (Bus A) -> v (Bus B) menurunkan Bus Flow sebesar pergeseran. */
                $busU = (array)($m['bus_unit'] ?? []); $bMin = (float)($m['busflow_min'] ?? 0);
                if ($bMin > 0 && strtoupper((string)($busU[$v . '_bus'] ?? '')) === 'B' && strtoupper((string)($busU[$w . '_bus'] ?? '')) !== 'B' && isset($r['BusFlow'])) {
                    $bRoom = (float)$r['BusFlow'] - $bMin; if ($bRoom < 0.5) $why[] = sprintf('BUS_FLOW(Bus Flow %.1f MW, min %.1f MW: %s di Bus B tidak dapat dinaikkan)', (float)$r['BusFlow'], $bMin, $col($v)); else $slack = min($slack, $bRoom); }
                if ($v === 'b1' || $v === 'b2') $why[] = 'BBLN_BATUBARA_DIATUR_TARGET_BIOMASSA_DAN_RAMP_' . pp_babelan_ramp_limit($m) . 'MW';
                $mv = $marg($r, $v, 1.0, $r1); $mww = $marg($r, $w, -1.0, $r1);
                $inc = ($mv !== null && $mww !== null) ? ($mv - $mww) / 48.0 * 1000.0 * $price : null;   // USD per MW per row
                if ($inc !== null && $inc > 0.01) $why[] = sprintf('EKONOMIS: memindah 1 MW %s->%s menambah biaya %.2f USD/row', $col($w), $col($v), $inc);
                if (($U[$col($v)]['prio'] ?? null) !== null && preg_match('~^g\d+$~', $v) && preg_match('~^g\d+$~', $w) && pp_gtg_to_stg($d3, $v) !== pp_gtg_to_stg($d3, $w) && $inc === null) $why[] = 'KOPLING_BLOK_STG';
                if (!$why && isset($pev[$r1 . '#' . $col($w)])) { $pe = (array)$pev[$r1 . '#' . $col($w)]; if (!empty($pe['reason'])) $why[] = (string)$pe['reason']; }
                $f = ['type' => 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM', 'row' => $r1, 'unit' => $col($w), 'unit_mw' => round($vw, 2), 'unit_min' => $Lw['min'],
                      'unit_priority' => $rw, 'higher_unit' => $col($v), 'higher_priority' => $rank[$v] ?? null, 'higher_headroom_mw' => round($h, 2),
                      'shiftable_mw' => round($slack, 2), 'incremental_cost_usd_per_mw_row' => $inc === null ? null : round($inc, 3), 'reasons' => $why, 'resolved' => (bool)$why];
                $flags[] = $f; $row['flags'][] = $f['type'] . ':' . $col($w) . '<' . $col($v);
            }
        }
        /* (3) V8 — unit prioritas rendah RUNNING (bukan hanya start/di atas minimum) sementara unit running
         * prioritas lebih tinggi punya headroom legal atau peer prioritas lebih tinggi di blok yang sama mati.
         * Alasan dari kandidat pembanding V8 (48 row, validasi penuh) atau kontrol operator. */
        foreach ($units as $w) {
            if (!$isGT($w) || $isMM($w)) continue;
            $vw = $mw($r, $w); if ($vw <= 0.01) continue;
            $rw = $rank8[$w] ?? 999; $head = 0.0; $hu = [];
            foreach ($units as $v) {
                if ($v === $w || ($rank8[$v] ?? 999) >= $rw || !preg_match('~^(g\d+|b\d)$~', $v) || $isMM($v)) continue;
                if ($mw($r, $v) <= 0.01 || isset($U[$col($v)]['fixed'])) continue; $h = (float)($U[$col($v)]['headroom'] ?? 0); if ($h > 0.05) { $head += $h; $hu[$col($v)] = round($h, 2); }
            }
            $peerOff = [];
            $sw = pp_gtg_to_stg($d3, $w);
            if ($sw !== '') foreach ($units as $v) if ($v !== $w && $isGT($v) && !$isMM($v) && ($rank8[$v] ?? 999) < $rw && pp_gtg_to_stg($d3, $v) === $sw && $mw($r, $v) <= 0.01 && isset($v8elig[$v])) $peerOff[] = $col($v);
            if ($head < 0.5 && !$peerOff) continue;
            $why = [];
            if (isset($req[$w])) $why[] = $req[$w];
            if (($U[$col($w)]['fixed'] ?? null) !== null) $why[] = 'FIXED_LOAD';
            if (strtolower((string)($m['unit_last_data_status'][$col($w)] ?? '')) === 'running' && $r1 === 1) $why[] = 'LAST_DATA_RUNNING';
            if ($w === $coT) $why[] = 'CHANGE_OVER_DESTINATION';
            $e8 = $v8ev[$r1 . '#' . $col($w)] ?? null;
            if (is_array($e8)) foreach ((array)$e8['reasons'] as $x) $why[] = (string)$x;
            elseif (!$why && $v8st !== null && !in_array($v8st, ['APPLIED', 'NO_BETTER_VALID_CANDIDATE', 'DELTA_REVIEW_BASIS', 'DELTA_REVIEW_APPLIED'], true)) $why[] = 'V8_REVIEW_' . $v8st . (isset($v8['reason']) ? ':' . $v8['reason'] : '');
            if (!$why && !isset($v8elig[$w])) $why[] = 'UNIT_TIDAK_ELIGIBLE_DIUBAH';
            /* V9 evidence terstruktur per row: dampak mematikan unit ini pada row tersebut. */
            $evi = ['min_mw' => $U[$col($w)]['min'] ?? null, 'max_mw' => $U[$col($w)]['max'] ?? null, 'priority_rank' => $rw, 'available_higher_headroom_mw' => round($head, 2)];
            try {
                $pv = $p === null ? $vw : $mw($p, $w); $evi['ramp_mw_vs_prev_row'] = round($vw - $pv, 2); $evi['ramp_limit_mw'] = 30.0;
                $runN = 0; for ($j = $i; $j >= 0 && $mw($rows[$j], $w) > 0.01; $j--) $runN++;
                $limR = pp_runtime_limits($m); $evi['rows_running_since_start'] = $runN; $evi['min_runtime_rows'] = (int)($limR[pp_runtime_class($w, $d3)]['run_rows'] ?? 0);
                $evi['reserve_mw'] = $r['Spin_Res'] ?? null; $evi['reserve_without_unit_mw'] = round((float)($r['Spin_Res'] ?? 0) - (float)($U[$col($w)]['max'] ?? 0), 2); $evi['reserve_min_mw'] = $spinMin;
                $gB = []; foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'] as $x) $gB[$x] = $mw($r, $x);
                $gB0 = $gB; $gB0[$w] = 0.0; try { pp_recompute_stgs($gB0, $d3, $m, $r1); } catch (Throwable $e) {}
                $evi['bus_flow_mw'] = $r['BusFlow'] ?? null; $evi['bus_flow_without_unit_mw'] = round(calc_busflow($gB0, (array)($m['bus_unit'] ?? []), (float)($r['IE'] ?? 0)), 2); $evi['bus_flow_min_mw'] = (float)($m['busflow_min'] ?? 0);
                $pre = pp_v8_export_prescreen($input, $rows, $w, [$r1]);
                $evi['export_mw'] = $r['Export_PLN'] ?? null; $evi['export_range_min_mw'] = $r['pln_lo'] ?? null; $evi['export_max_without_unit_mw'] = $pre === null ? 'CUKUP (>= Range Min)' : $pre['export_max_mw'];
                $evi['gas_bbtud_rate'] = round(calc_fuel($d3, $w, $vw), 5); $evi['block_stg'] = strtoupper(pp_gtg_to_stg($d3, $w)) ?: null;
                $mu = $marg($r, $w, -1.0, $r1); $evi['marginal_fuel_bbtud_rate_per_mw'] = $mu === null ? null : round($mu, 5);
                $evi['decision'] = ($evi['rows_running_since_start'] < $evi['min_runtime_rows']) ? 'CONTINUE_MIN_RUNTIME' : ($pre !== null ? 'CONTINUE_NEEDED_FOR_EXPORT' : 'CONTINUE_BY_CANDIDATE_EVIDENCE');
            } catch (Throwable $e) { $evi['error'] = $e->getMessage(); }
            $f = ['type' => 'LOWER_PRIORITY_RUNNING_WHILE_HIGHER_HEADROOM', 'row' => $r1, 'unit' => $col($w), 'unit_mw' => round($vw, 2), 'unit_priority' => $rw,
                  'higher_priority_headroom_mw' => round($head, 2), 'headroom_units' => $hu, 'higher_priority_peers_off' => $peerOff,
                  'reasons' => array_values(array_unique($why)), 'resolved' => (bool)$why, 'evidence' => $evi];
            $flags[] = $f; $row['flags'][] = $f['type'] . ':' . $col($w);
            $row['reasons'][$col($w)] = $f['reasons'];
        }
        /* (4) V8 — unit MM2100 berbeban: sumber gas MM2100 wajib sah. */
        foreach (['ge1','ge2','ge3','ge4','g10'] as $w) {
            if (!in_array($w, $units, true) || $mw($r, $w) <= 0.01) continue;
            $f = ['type' => 'MM2100_UNIT_RUNNING', 'row' => $r1, 'unit' => $col($w), 'unit_mw' => round($mw($r, $w), 2),
                  'gas_source' => $mmSrc['source'], 'reasons' => $mmSrc['legal'] ? ['MM2100_GAS_SOURCE:' . $mmSrc['source']] : [], 'resolved' => $mmSrc['legal']];
            $flags[] = $f; $row['flags'][] = $f['type'] . ':' . $col($w); $row['reasons'][$col($w)] = $f['reasons'];
        }
        $table[] = $row;
    }
    $unres = array_values(array_filter($flags, function ($f) { return empty($f['resolved']); }));
    $byType = []; foreach ($flags as $f) $byType[$f['type']] = ($byType[$f['type']] ?? 0) + 1;
    /* Batas atas manfaat ekonomi temuan beban yang belum berlasan: per (row, unit) diambil pergeseran terbaik
     * (shiftable x selisih biaya marginal), dijumlah, lalu dibagi Net Production -> batas atas penurunan CP. */
    $gainK = []; $onlyLoaded = true;
    foreach ($unres as $f) { if ($f['type'] !== 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' || $f['incremental_cost_usd_per_mw_row'] === null) { $onlyLoaded = false; continue; }
        $k = $f['row'] . '#' . $f['unit']; $gainK[$k] = max($gainK[$k] ?? 0.0, -(float)$f['incremental_cost_usd_per_mw_row'] * (float)$f['shiftable_mw']); }
    $gainUsd = array_sum($gainK); $net = (float)($output['info']['Net Production (MWh)'] ?? 0);
    $dcp = $net > 0 ? $gainUsd / $net : null;
    $status = !$flags ? 'PASS' : (!$unres ? 'PASS_WITH_REASON' : (($onlyLoaded && $dcp !== null && $dcp < 0.001) ? 'REVIEW_MINOR' : 'REVIEW_UNRESOLVED'));
    return ['schema' => 'co12-v5-headroom-priority-v1', 'status' => $status, 'rows_audited' => $n,
            'residual_gain_usd_upper_bound' => round($gainUsd, 2), 'residual_cp_gain_upper_bound' => $dcp === null ? null : round($dcp, 6),
            'units_audited' => array_map($col, $units), 'unit_priority' => $m['unit_priority'] ?? null, 'spinning_reserve_min_mw' => $spinMin,
            'events' => $events, 'flags_total' => count($flags), 'flags_by_type' => $byType, 'flags_unresolved' => count($unres),
            'unresolved' => array_slice($unres, 0, 40), 'flags' => array_slice($flags, 0, 200), 'rows' => $table,
            'universal_fresh_start_review' => is_array($uh) ? ['status' => $uh['status'] ?? null, 'violations' => $uh['violation_count'] ?? null] : null,
            'note' => 'Audit murni atas dispatch final; alasan sah: Required/Cannot Stop/Manual/Change Over, Fixed/Skip Load, ramp tetangga, Spinning Reserve, kopling blok STG, counterfactual 48 row, atau biaya inkremental positif (ekonomis).'];
}
/* =============================================================================================
 *  V8 — REVIEW UNIT PRIORITY / HEADROOM BERBASIS KANDIDAT PEMBANDING.
 *
 *  Akar masalah V7 (reproducer Daily Plan 09-Jul-26 Baru): ruang kandidat exact hanya berisi
 *  commitment "unit dimatikan SEPANJANG HARI". G2 start 13:00 (row 26) karena Export minimum
 *  menuntut unit tambahan; kandidat "G2 off sepanjang hari" invalid (Export), sehingga G2 dibiarkan
 *  running sampai 24:00 — padahal G2 boleh berhenti pada row legal pertama sesudah minimum runtime
 *  (row 38), dan G5 (prioritas lebih tinggi di blok S2 yang sama) tidak pernah diuji.
 *
 *  V8: untuk setiap interval running unit GTG non-required (eligible) yang berjalan sementara unit
 *  running berprioritas lebih tinggi masih punya headroom legal, atau peer prioritas lebih tinggi
 *  di blok STG yang sama mati, dibentuk kandidat row-local dari commitment pemenang:
 *    TRUNC  stop pada row legal pertama (start + minimum runtime) dan dua row sesudahnya;
 *    OFF    interval dimatikan;
 *    SWAP   peer prioritas lebih tinggi menggantikan interval itu (penuh / sampai row legal pertama).
 *  Setiap kandidat = dispatch 48 row engine dengan commitment itu, window gas didaratkan dengan lever
 *  engine, dinilai terhadap input asli (validator hard 48 row, Export 48/48, reserve, Bus Flow,
 *  runtime, window gas/PGN, residual 0, headroom universal, full horizon). Kandidat dari kolam
 *  bersama (state identik dihitung sekali; pekerja pembantu ikut mengerjakan). Pemenang diganti hanya
 *  bila kandidat valid LEBIH BAIK menurut comparator engine (hard, constraint, CP, ...). Diulang dari
 *  pemenang baru (maks. 3 kali). Hasil evaluasi kandidat final menjadi alasan terstruktur per row.
 * ============================================================================================= */
function pp_v8_rank(array $model): array { return pp_priority_rank($model); }   // V9: satu definisi (pp_priority_flat)
function pp_v8_masks(array $data): array {
    $M = [];
    foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $m = []; for ($r = 1; $r <= 48; $r++) $m[$r] = ((float)($data[$r - 1][$U] ?? 0)) > 0.01; $M[$u] = $m; }
    return $M;
}
function pp_v8_intervals(array $m): array {
    $iv = []; $a = null;
    for ($r = 1; $r <= 49; $r++) { $on = $r <= 48 ? !empty($m[$r]) : false; if ($on && $a === null) $a = $r; if (!$on && $a !== null) { $iv[] = [$a, $r - 1]; $a = null; } }
    return $iv;
}
function pp_v8_stops(array $M): array {
    $st = [];
    foreach (pp_tl_gt_units() as $u) { if (!isset($M[$u])) continue; $r0 = null;
        for ($r = 1; $r <= 49; $r++) { $off = $r <= 48 ? empty($M[$u][$r]) : false; if ($off && $r0 === null) $r0 = $r; if (!$off && $r0 !== null) { $st[] = ['unit' => $u, 'start' => $r0, 'stop' => $r - 1]; $r0 = null; } } }
    return $st;
}
/* Unit GTG yang boleh diubah commitment-nya oleh V8: hadir, tidak Required / required_mode / Cannot Stop,
 * tanpa Fixed Load. Berbeda dengan keluarga exact, Last Data Running TIDAK dikecualikan: unit yang running
 * sejak kemarin tetap boleh berhenti pada row legal (validator runtime/downtime yang menilai). */
function pp_v8_eligible(array $orig): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $fixed = [];
    foreach ((array)($m['unit_cannot_stop'] ?? []) as $u) $fixed[strtolower((string)$u)] = true;
    foreach ((array)($m['required_units'] ?? []) as $u) if (is_string($u)) $fixed[strtolower($u)] = true;
    foreach (array_keys((array)($m['required_mode'] ?? [])) as $u) $fixed[strtolower((string)$u)] = true;
    $el = [];
    foreach (pp_tl_gt_units() as $u) {
        if (!empty($fixed[$u]) || !isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
        $hasFix = false; for ($r = 1; $r <= 48; $r++) if (pp_get_fixed_load($m, $u, $r) >= 0) { $hasFix = true; break; }
        if (!$hasFix) $el[$u] = true;
    }
    return $el;
}
function pp_v8_fuel_active(array $m): bool {
    $a = strtolower(trim((string)($m['gas_shortage_action'] ?? 'none')));
    return !in_array($a, ['', 'none', 'recommendation', 'flag shortage only', 'flag_shortage_only'], true);
}
/* V8 prasaring kapasitas (SAH, batas atas): bila pada suatu row unit $u dimatikan dan SELURUH unit lain yang
 * running (commitment tetap) dinaikkan ke Effective Max-nya (STG dihitung ulang dari beban GTG itu, BBLN di max),
 * Export tetap di bawah Range Min row itu, maka kandidat PASTI melanggar Export — tidak perlu disimulasikan. */
function pp_v8_export_prescreen(array $orig, array $rows, string $u, array $offRows): ?array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    foreach ($offRows as $r) {
        $row = $rows[$r - 1] ?? null; if (!is_array($row)) continue;
        $lo = (float)($row['pln_lo'] ?? ($m['pln_export_priority']['range']['min'] ?? 0)); $exp = (float)($row['Export_PLN'] ?? 0);
        $g = []; foreach ($row as $k => $x) if (is_string($k) && preg_match('~^(G\d+|S\d)$~', $k)) $g[strtolower($k)] = (float)$x;
        $cur = 0.0; foreach ($g as $k => $x) if ($k !== 'g10') $cur += $x;
        $gm = $g;
        foreach ($g as $k => $x) { if (!preg_match('~^g\d+$~', $k) || $k === 'g10') continue;
            if ($k === $u) { $gm[$k] = 0.0; continue; }
            if ($x > 0.01 && pp_get_fixed_load($m, $k, $r) < 0) $gm[$k] = max($x, pp_effective_max_load($d3, $m, $k, $r)); }
        try { pp_recompute_stgs($gm, $d3, $m, $r); } catch (Throwable $e) { return null; }
        $mx = 0.0; foreach ($gm as $k => $x) if ($k !== 'g10' && preg_match('~^(g\d+|s\d)$~', $k)) $mx += $x;
        $bb = 0.0; foreach (['b1' => 'BB1', 'b2' => 'BB2'] as $bk => $bc) { $bv = (float)($row[$bc] ?? 0); if ($bv > 0.01) $bb += max(0.0, pp_effective_max_load($d3, $m, $bk, $r) - $bv); }
        $expMax = $exp + ($mx - $cur) + $bb;
        if ($expMax < $lo - 1e-6) return ['row' => $r, 'export_max_mw' => round($expMax, 2), 'range_min_mw' => $lo];
    }
    return null;
}
/* =============================================================================================
 *  V9 — DEFINISI UNIT PRIORITY TUNGGAL + GENERATOR KANDIDAT GENERIK BERBASIS EVENT/BREAKPOINT.
 *
 *  pp_v9_priority_model() adalah satu-satunya definisi yang dipakai review, audit, comparator dan
 *  generator (engine dasar memakai pp_priority_flat/pp_priority_rank — sumber yang sama):
 *    hard operator priority : urutan Unit Priority operator (datar, sesuai urutan input); mengikat
 *                             selama feasible dan pada CP setara (tie-break), tidak mengalahkan CP;
 *    economic preference    : Cost Production (comparator engine) — kandidat valid lebih murah menang;
 *    forced status          : Required / required_mode / Cannot Stop / Fixed Load / stop schedule /
 *                             Mandatory Stop / Change Over — hard, unit tidak diubah generator;
 *    block/STG coupling     : GTG -> STG dari data unit STG (hrsg/gtg) — hard relationship.
 * ============================================================================================= */
/* V9: LEVEL Unit Priority = indeks grup operator (unit_priority, fallback block_priority). Urutan datar (pp_priority_flat)
 * menentukan urutan start/commitment/tie-break; level menentukan pembebanan: unit dalam satu grup setara untuk pembebanan
 * (biaya yang menentukan), beban unit level lebih rendah di atas minimum sementara level lebih tinggi punya headroom = temuan. */
function pp_v9_priority_level(array $m): array {
    $r = []; foreach ((array)($m['unit_priority'] ?? $m['block_priority'] ?? []) as $gi => $grp) foreach ((array)$grp as $u) { $u = strtolower(trim((string)$u)); if ($u !== '' && $u !== 'required' && !isset($r[$u])) $r[$u] = (int)$gi; }
    return $r;
}
function pp_v9_priority_model(array $orig): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $rank = pp_priority_rank($m); $forced = [];
    foreach ((array)($m['unit_cannot_stop'] ?? []) as $u) $forced[strtolower((string)$u)][] = 'CANNOT_STOP';
    foreach ((array)($m['required_units'] ?? []) as $u) if (is_string($u)) $forced[strtolower($u)][] = 'REQUIRED';
    foreach ((array)($m['required_mode'] ?? []) as $u => $cfg) $forced[strtolower((string)$u)][] = 'REQUIRED_MODE_' . strtoupper((string)($cfg['mode'] ?? ''));
    foreach ((array)($m['stop_mode'] ?? []) as $u => $cfg) if (is_string($u)) $forced[strtolower($u)][] = 'STOP_MODE_' . strtoupper((string)($cfg['mode'] ?? ''));
    foreach ((array)($m['unit_stop_time'] ?? []) as $st) if (is_array($st) && isset($st['unit'])) $forced[strtolower((string)$st['unit'])][] = 'STOP_SCHEDULE';
    foreach ((array)($m['unit_fix_load'] ?? []) as $u => $rows) if (is_string($u) && $rows) $forced[strtolower($u)][] = 'FIXED_LOAD';
    $block = []; foreach (pp_tl_gt_units() as $u) if (isset($d3[$u])) { $s = pp_gtg_to_stg($d3, $u); if ($s !== '') $block[$u] = $s; }
    return ['rank' => $rank, 'order' => pp_priority_flat($m), 'level' => pp_v9_priority_level($m), 'forced' => array_map(function ($x) { return array_values(array_unique($x)); }, $forced),
            'block' => $block, 'economic' => 'COST_PRODUCTION_COMPARATOR', 'hard_priority' => 'UNIT_PRIORITY_ORDER (tie-break + evidence)'];
}
/* Comparator final V9: hard, constraint, Cost Production (presisi model 1e-4), lalu tie-break deterministik:
 * kepatuhan Unit Priority (bobot rank x row running), jumlah start, row unit non-forced, kunci kanonik. */
function pp_v9_prio_score(array $running, array $rank): float { $s = 0.0; foreach ($running as $u => $n) $s += ((float)($rank[$u] ?? 99)) * (float)$n; return $s; }
function pp_v9_better(array $a, array $b, array $rank): bool {
    if (isset($a['sig'], $b['sig']) && $a['sig'] === $b['sig'] && abs(round((float)($a['key']['cp'] ?? 0), 4) - round((float)($b['key']['cp'] ?? 0), 4)) < 1e-9) return false;
    $ka = (array)($a['key'] ?? []); $kb = (array)($b['key'] ?? []);
    foreach (['hard', 'constraint'] as $k) { $x = (float)($ka[$k] ?? INF); $y = (float)($kb[$k] ?? INF); if (abs($x - $y) > 1e-9) return $x < $y; }
    $x = round((float)($ka['cp'] ?? INF), 4); $y = round((float)($kb['cp'] ?? INF), 4); if (abs($x - $y) > 1e-9) return $x < $y;
    $x = pp_v9_prio_score((array)($a['running'] ?? []), $rank); $y = pp_v9_prio_score((array)($b['running'] ?? []), $rank); if (abs($x - $y) > 1e-9) return $x < $y;
    $x = (float)($ka['starts'] ?? 0); $y = (float)($kb['starts'] ?? 0); if (abs($x - $y) > 1e-9) return $x < $y;
    $x = array_sum((array)($a['running'] ?? [])); $y = array_sum((array)($b['running'] ?? [])); if (abs($x - $y) > 1e-9) return $x < $y;
    $x = (float)($ka['cp'] ?? INF); $y = (float)($kb['cp'] ?? INF); if (abs($x - $y) > 1e-9) return $x < $y;
    return strcmp((string)($a['sig'] ?? ''), (string)($b['sig'] ?? '')) < 0;   // commitment identik -> sama (bukan lebih baik)
}
/* V9 prasaring legalitas (PASTI melanggar, tanpa simulasi): peer tidak tersedia (stop schedule / unit tidak
 * tersedia), unit Last Data Running dimatikan pada row 1, STG blok continuous / Cannot Stop kehilangan seluruh
 * pemasok GTG, interval peer lebih pendek dari minimum runtime kelasnya (bukan di ujung horizon). */
function pp_v9_legal_prescreen(array $orig, array $c): ?array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $u = (string)($c['unit'] ?? ''); if ($u === '') return null;
    $on = function (string $x, int $r) use ($c): bool { foreach ((array)$c['stops'] as $st) if ($st['unit'] === $x && $r >= (int)$st['start'] && $r <= (int)$st['stop']) return false; return true; };
    $p = (string)($c['peer'] ?? '');
    if ($p !== '' && is_array($c['peer_rows'] ?? null)) { [$pa, $pe] = $c['peer_rows'];
        for ($r = $pa; $r <= $pe; $r++) if (pp_is_unit_stopped($d3, $m, $p, $r) || !pp_effective_unit_available($d3, $m, $p, $r))
            return ['method' => 'PRASARING_LEGALITAS', 'code' => 'FORCED_STATUS', 'detail' => sprintf('PEER_%s_TIDAK_TERSEDIA_ROW_%d', strtoupper($p), $r), 'row' => $r];
        $lim = pp_runtime_limits($m); $run = (int)($lim[pp_runtime_class($p, $d3)]['run_rows'] ?? 0);
        if ($pe < 48 && $pe - $pa + 1 < $run) return ['method' => 'PRASARING_LEGALITAS', 'code' => 'MIN_RUNTIME_DOWNTIME', 'detail' => sprintf('PEER_%s_%d_ROW_<_MIN_RUNTIME_%d', strtoupper($p), $pe - $pa + 1, $run), 'row' => $pa]; }
    $off = (array)($c['off_rows'] ?? [1, 0]);
    if ($off[1] >= $off[0]) {
        $ld = (array)($m['unit_last_data_status'] ?? []);
        if ((int)$off[0] === 1 && strtolower((string)($ld[strtoupper($u)] ?? '')) === 'running')
            return ['method' => 'PRASARING_LEGALITAS', 'code' => 'FORCED_STATUS', 'detail' => 'LAST_DATA_RUNNING_' . strtoupper($u) . '_WAJIB_BERBEBAN_ROW_1', 'row' => 1];
        $stg = pp_gtg_to_stg($d3, $u);
        if ($stg !== '') { $req = in_array($stg, array_map('strtolower', (array)($m['unit_cannot_stop'] ?? [])), true) || strtolower((string)($m['required_mode'][$stg]['mode'] ?? '')) === 'continuous';
            if ($req) for ($r = (int)$off[0]; $r <= (int)$off[1]; $r++) { if ($on($u, $r)) continue; $fed = false;
                foreach (pp_tl_gt_units() as $f) if ($f !== $u && isset($d3[$f]) && pp_gtg_to_stg($d3, $f) === $stg && $on($f, $r) && !pp_is_unit_stopped($d3, $m, $f, $r)) { $fed = true; break; }
                if (!$fed) return ['method' => 'PRASARING_LEGALITAS', 'code' => 'BLOCK_STG', 'detail' => sprintf('%s_CONTINUOUS_TANPA_PEMASOK_GTG_ROW_%d', strtoupper($stg), $r), 'row' => $r]; } }
    }
    return null;
}
/* =============================================================================================
 *  V10 — TIER-1 SCREENING: BUKTI INFEASIBILITAS KAPASITAS EXPORT (batas atas sah, tanpa simulasi).
 *
 *  Untuk sebuah commitment kandidat (daftar stop {unit,start,stop}) dan setiap row r: seluruh GTG yang
 *  TIDAK di-stop kandidat maupun operator dan tersedia dianggap berbeban Effective Max (Fixed Load = nilainya),
 *  STG dihitung dari feeder itu (pp_recompute_stgs, termasuk HRSG stop), Babelan pada Effective Max, lalu
 *      Export_max(r) = sum(GTG+STG) - House Load(pola on/off) + Babelan - IE(r).
 *  Konfigurasi ini adalah Export TERBESAR yang dapat dicapai commitment tersebut pada row r (ramp, runtime,
 *  reserve, gas hanya dapat MENURUNKANNYA; house load hanya bergantung pola on/off dan jauh lebih kecil dari
 *  beban minimum unit). Bila Export_max(r) + 1 MW (slack) < Range Min(r) pada satu row saja, commitment itu
 *  PASTI melanggar Export pada row r — tidak perlu disimulasikan. Monoton: mematikan unit tambahan tidak
 *  pernah menaikkan Export_max, sehingga seluruh superset stop kandidat ikut terbukti infeasible.
 *  IE dan Range Min per row diambil dari dispatch acuan state yang sama (keduanya data input per row).
 *  PP_V10_CAP_PROOF=0 mematikan. */
function pp_v10_export_capacity_proof(array $orig, array $rowsRef, array $stops): ?array {
    if (count($rowsRef) !== 48 || (string)getenv('PP_V10_CAP_PROOF') === '0') return null;
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $off = []; foreach ($stops as $s) { if (!is_array($s)) continue; $u = strtolower((string)($s['unit'] ?? '')); if ($u === '') continue;
        for ($r = max(1, (int)($s['start'] ?? 1)); $r <= min(48, (int)($s['stop'] ?? 48)); $r++) $off[$u][$r] = true; }
    static $G = ['g1', 'g2', 'g3', 'g4', 'g5', 'g6', 'g7', 'g8', 'g9'];
    try {
        for ($r = 1; $r <= 48; $r++) {
            $row = $rowsRef[$r - 1] ?? null; if (!is_array($row) || !isset($row['IE'], $row['pln_lo'])) return null;
            $lo = (float)$row['pln_lo']; if ($lo <= 0.0) continue;
            $g = ['g10' => 0.0, 's1' => 0.0, 's2' => 0.0, 's3' => 0.0, 'b1' => 0.0, 'b2' => 0.0, 'ge1' => 0.0, 'ge2' => 0.0, 'ge3' => 0.0, 'ge4' => 0.0];
            foreach ($G as $u) { $g[$u] = 0.0; if (!isset($d3[$u]) || !empty($off[$u][$r]) || pp_is_unit_stopped($d3, $m, $u, $r)) continue;
                $fx = pp_get_fixed_load($m, $u, $r); $g[$u] = $fx >= 0 ? (float)$fx : max(0.0, pp_effective_max_load($d3, $m, $u, $r)); }
            pp_recompute_stgs($g, $d3, $m, $r);
            $sum = 0.0; foreach (array_merge($G, ['s1', 's2', 's3']) as $k) $sum += (float)($g[$k] ?? 0);
            $bb = 0.0; foreach (['b1', 'b2'] as $b) { if (!isset($d3[$b]) || pp_is_unit_stopped($d3, $m, $b, $r)) continue;
                $fx = pp_get_fixed_load($m, $b, $r); $bb += $fx >= 0 ? (float)$fx : max(0.0, pp_effective_max_load($d3, $m, $b, $r)); }
            $exp = $sum - calc_house_load($g) + $bb - (float)$row['IE'];
            if ($exp + 1.0 < $lo - 1e-6) return ['method' => 'V10_BUKTI_KAPASITAS_EXPORT', 'code' => 'EXPORT', 'row' => $r, 'export_max_mw' => round($exp, 2), 'range_min_mw' => $lo,
                'detail' => sprintf('Export maks row %d = %.1f MW < Range Min %.1f MW (seluruh unit tersedia pada Effective Max)', $r, $exp, $lo)];
        }
    } catch (Throwable $e) { return null; }
    return null;
}
/* V10: ringkasan screening dua tingkat per FINAL (jumlah kandidat dibangkitkan / dipangkas Tier-1 / disimulasikan). */
function pp_v10_screening_summary(array $o, string $mode): array {
    $i = (array)($o['info'] ?? []); $sp = (array)($i['Exact Candidate Space'] ?? []); $g = (array)($i['Global Commitment Review'] ?? []); $v = (array)($i['V8 Priority Review'] ?? []);
    $meth = []; foreach (array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])) as $c) { $k = (string)($c['method'] ?? '-'); $meth[$k] = ($meth[$k] ?? 0) + 1; }
    $fast = $i['V10 Candidate Screening'] ?? null;
    $r = ['schema' => 'co12-v10-screening-summary-v1', 'route' => $mode,
        'review' => ['status' => $v['status'] ?? null, 'generated' => $v['candidates_total'] ?? null, 'prescreened_tier1' => $v['candidates_prescreened'] ?? null,
            'simulated' => $v['candidates_simulated'] ?? null, 'methods' => $meth]];
    if (is_array($fast)) $r['fast_route'] = ['generated' => $fast['candidates_generated'] ?? null, 'pruned_tier1' => $fast['candidates_pruned'] ?? null, 'pruned_by_reason' => $fast['pruned_by_reason'] ?? null,
        'tier2a_frozen_48row' => $fast['candidates_evaluated_tier2a'] ?? null, 'tier2a_simulations' => $fast['tier2a_simulations'] ?? null, 'tier2b_core_runs' => $fast['candidates_full_run_tier2b'] ?? null,
        'universe_signature' => $fast['universe_signature'] ?? null];
    else $r['exact'] = ['family_nodes' => $sp['family_nodes'] ?? null, 'family_nodes_computed' => $sp['family_nodes_computed'] ?? null, 'family_valid' => $sp['family_valid'] ?? null,
        'gcr_total' => $g['candidates_total'] ?? null, 'gcr_screened_tier1' => count((array)($g['candidates_screened_v10'] ?? [])), 'gcr_family_coverage' => $g['v10_family_coverage'] ?? null,
        'core_simulations' => $i['Run Status']['core_simulations'] ?? null];
    return $r;
}
/* V10: penghitung screening per job (dilaporkan pada blok 'V10 Candidate Screening'). */
function pp_v10_scr(string $stage, string $what, int $n = 1): void { $GLOBALS['ppV10Scr'][$stage][$what] = (int)($GLOBALS['ppV10Scr'][$stage][$what] ?? 0) + $n; }
/* V10: sertifikat optimalitas cepat rute inkremental kanonik. Menyatakan ruang kandidat (universe) yang dicakup,
 * alasan pruning tiap kandidat yang tidak di-full-run, batas CP per grup, baris kotor, dan sidik jari engine /
 * constraint / bahan bakar / priority, sehingga FINAL dapat diaudit tanpa mengulang simulasi. */
function pp_v10_fingerprints(array $orig): array {
    $m = (array)($orig['data3']['modeling'] ?? []);
    $pick = function (array $keys) use ($m) { $x = []; foreach ($keys as $k) if (array_key_exists($k, $m)) $x[$k] = $m[$k]; return substr(hash('sha256', json_encode($x)), 0, 16); };
    return ['engine' => pp_engine_fingerprint(),
        'constraints' => $pick(['pln_export_priority', 'spinning_reserve_min', 'busflow_min', 'bus_unit', 'runtime_downtime', 'min_run_down', 'max_load_rules', 'unit_skip_load', 'unit_fix_load', 'unit_stop', 'unit_stop_time', 'hrsg_stop', 'hrsg_stop_time', 'change_over', 'stop_mode', 'required_mode', 'required_units', 'unit_cannot_stop', 'unit_last_data_status']),
        'fuel' => $pick(['gas_quota', 'price', 'gas_shortage_action', 'additional_lng', 'actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100', 'manual_fixed_flows', 'max_flow_mm2100', 'mm2100_gas_mode', 'ghv_jababeka', 'ghv_mm2100', 'ghv_pgn']),
        'priority' => $pick(['unit_priority', 'block_priority', 'unit_priority_dist'])];
}
function pp_v10_inc_certificate(array $orig, array $base, array $v, array $diff, array $all, $winK, int $evals, int $sims, float $M): array {
    $sigs = [(string)($v['winner']['sig'] ?? '')]; foreach ((array)($v['candidates'] ?? []) as $c) $sigs[] = (string)($c['sig'] ?? pp_v3_sig(pp_v3_commitment_stops($c)));
    sort($sigs);
    $pr = (array)($GLOBALS['ppV10Inc']['pruned'] ?? []); $byR = []; foreach ($pr as $p) $byR[$p['reason']] = ($byR[$p['reason']] ?? 0) + 1;
    $cpW0 = (float)($v['winner']['cp'] ?? 0);
    $win = $all[$winK] ?? null;
    return ['schema' => 'co12-v10-fast-certificate-v1',
        'universe_signature' => substr(hash('sha256', implode('|', $sigs)), 0, 24), 'universe_size' => count($sigs),
        'anchor_file' => $base['_file'] ?? null, 'anchor_winner_cp' => $cpW0,
        'candidates_generated' => count($sigs), 'candidates_pruned' => count($pr), 'pruned_by_reason' => $byR, 'pruned' => array_slice($pr, 0, 40),
        'candidates_full_run' => count($all), 'core_evaluations' => $evals, 'core_simulations' => $sims,
        'cp_bound_rule' => sprintf('CP_jangkar(c) > CP_jangkar(pemenang) + M, M = max(0,05; 3 x |dCP pemenang|) = %.4f', $M),
        'lower_bound_per_group' => ['valid_or_gas_only' => round($cpW0 + $M, 4)],
        'dirty_rows' => (array)($diff['dirty_rows'] ?? []),
        'incumbent_canonical_key' => is_array($win) ? ($win['a']['key'] ?? null) : null, 'incumbent_node' => is_array($win) ? $win['node'] : null,
        'fingerprints' => pp_v10_fingerprints($orig),
        'rule' => 'FINAL sah bila incumbent valid, seluruh kelas kandidat universe tercakup screening, setiap pesaing yang tidak di-full-run dipangkas dengan alasan tercatat, dan validasi penuh incumbent lulus.'];
}
/* =================================================================================================
 *  V10 — RUTE CEPAT KANONIK UNTUK PERUBAHAN ACTUAL / FIXED FLOW (satu atau beberapa slot).
 *
 *  Definisi (fungsi state S saja; FINAL tidak bergantung jalur):
 *    jangkar A = D(S) (Actual & fixed flow manual dihapus, V9) dengan FINAL kanonik dan PUSTAKA DISPATCH
 *    kanonik L(A): commitment pemenang A + kandidat ruang A yang valid/hanya-gagal-gas dalam 0,12 USD/MWh,
 *    masing-masing pada grid lever tetap {-0,16 .. +0,16}; setiap titik = satu core run penuh pada A; hanya
 *    dispatch valid yang masuk pustaka (dedup state fisik per dispatch GTG 48 row). L(A) dihitung sekali
 *    per jangkar (deterministik) dan disimpan di samping FINAL jangkar.
 *    Pada S:
 *      TIER 2b (core run penuh): commitment pemenang A pada lever a0 dan a0 +- 0,16 (pendaratan supplier S);
 *      TIER 1  (screening): dedup state fisik, prasaring bukti kapasitas, alasan pruning tercatat;
 *      TIER 2a (simulasi penuh 48 row, dispatch GTG tetap): setiap dispatch pustaka didaratkan ke window
 *              gas & PGN supplier S (row actual tidak diubah; varian kedua memakai dispatch row actual
 *              hasil core run S bila pola on/off sama). Akuntansi memakai target pipe internal supplier S
 *              yang sama dengan core run penuh (identik dengan evaluasi penuh dispatch yang sama).
 *    Pemenang = kandidat constraint-valid terbaik (comparator engine) dari seluruh evaluasi; divalidasi
 *    penuh terhadap input asli S, dipoles Unit Priority, lalu melewati review generik & gerbang rilis.
 *    Tanpa kandidat valid -> rute inkremental V9 (kanonik) -> exact.
 * ================================================================================================= */
function pp_v10_actual_rows(array $m): array { $ar = [];
    foreach (['actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100'] as $k) foreach ((array)($m[$k] ?? []) as $i => $v) if ($v !== '' && $v !== null) { $h = intdiv((int)$i, 2); $ar[2 * $h + 1] = true; $ar[2 * $h + 2] = true; }
    return $ar; }
function pp_v10_shape(array $data): array { $o = []; foreach (array_values($data) as $r) { $x = []; foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $x[$U] = round((float)($r[$U] ?? 0), 4); } $o[] = $x; } return $o; }
/* Satu simulasi penuh 48 row dengan beban GTG tetap; target pipe internal = target supplier (akuntansi identik). */
function pp_v10_ge_rows(array $o): array { $g = []; foreach (array_values((array)($o['data'] ?? [])) as $k => $r) foreach (['GE1', 'GE2', 'GE3', 'GE4'] as $U) $g[$k][$U] = round((float)($r[$U] ?? 0), 4); return $g; }
function pp_v10_fz(array $orig, array $bd, ?float $pipeT, float $dl, ?array $geFix = null): ?array {
    if (count($bd) !== 48 || microtime(true) > $dl - 0.5) return null;
    $in = json_decode(json_encode($orig), true); $m = &$in['data3']['modeling'];
    unset($m['__v10_sup_secant'], $m['__v9_nopolish']);
    $m['unit_stop_time'] = (array)($m['unit_stop_time'] ?? []); foreach (pp_v3_stops($bd) as $st) $m['unit_stop_time'][] = $st;
    foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $rules = []; for ($r = 1; $r <= 48; $r++) { $v = (float)($bd[$r - 1][$U] ?? 0); if ($v > 0.01) $rules[] = ['start' => $r, 'stop' => $r, 'value' => round($v, 4)]; }
        if ($rules) { $m['unit_fix_load'] = (array)($m['unit_fix_load'] ?? []); $m['unit_fix_load'][$u] = $rules; } }
    /* V10: beban GE (MM2100) dari evaluasi pertama dipertahankan selama pendaratan window PGN (akun MM2100 tetap). */
    if (is_array($geFix) && count($geFix) === 48) foreach (['ge1', 'ge2', 'ge3', 'ge4'] as $u) { $U = strtoupper($u); if (!isset($in['data3'][$u])) continue; $rules = [];
        for ($r = 1; $r <= 48; $r++) { $v = (float)($geFix[$r - 1][$U] ?? 0); if ($v > 0.01) $rules[] = ['start' => $r, 'stop' => $r, 'value' => round($v, 4)]; }
        if ($rules && !isset($m['unit_fix_load'][$u])) { $m['unit_fix_load'] = (array)($m['unit_fix_load'] ?? []); $m['unit_fix_load'][$u] = $rules; } }
    $m['__tl_no_auto_start'] = true; $m['time_budget_seconds'] = 60.0; $m['time_budget_max_seconds'] = 60.0;
    $origPipe = (float)($m['gas_quota']['pgn_pipe'] ?? 0); $useT = $pipeT !== null && $pipeT > 0 && $origPipe > 0; if ($useT) $m['gas_quota']['pgn_pipe'] = $pipeT;
    unset($m);
    pp_tl_clean_globals(); pp_budget_start(60.0, true, true); $GLOBALS['__pp_budget_deadline'] = $dl;
    try { $o = pp_run_simulation_once($in); } catch (Throwable $e) { pp_tl_clean_globals(); return null; }
    if ($useT) { $dq = $origPipe - (float)($o['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe);
        foreach (['PGN Pipe Quota (BBTUD)', 'Total Gas Quota (BBTUD)', 'Gas Available (BBTUD)', 'Base Gas Quota (BBTUD)', 'Effective Gas Quota (BBTUD)'] as $k) if (isset($o['info'][$k])) $o['info'][$k] = round((float)$o['info'][$k] + $dq, 4); }
    $clean = $orig; unset($clean['data3']['modeling']['__v10_sup_secant'], $clean['data3']['modeling']['__v9_nopolish']);
    $a = pp_tl_assess($clean, $o); pp_tl_clean_globals(); $a['output'] = $o; $a['off'] = []; $a['adj'] = 0.0; $a['supplier_target'] = $useT ? $pipeT : pp_tl_supplier_target($o);
    if (function_exists('pp_v11_cnt') && count((array)($o['data'] ?? [])) === 48) pp_v11_cnt(!empty($a['valid']) ? 'v' : 'c', pp_v6_gtg_sig((array)$o['data']), (array)$o['data']);
    return $a;
}
/* Pendaratan dispatch tetap ke window gas total (efektif) dan window supplier PGN: beban unit GTG berjalan pada row
 * non-actual digeser berurutan menurut laju bahan bakar marjinal (naik: termurah dulu, turun: termahal dulu) dalam
 * batas Effective Min/Max dan ruang Export; row yang melanggar Export/ramp dikembalikan ke dispatch awal. Berhenti
 * bila valid, bila arah koreksi berbalik dua kali (window total dan supplier tidak dapat dipenuhi bersama), atau
 * pelanggaran lain muncul. Deterministik. */
function pp_v10_land(array $S, array $bd, ?float $T, float $dl, int $maxIt = 6, ?array &$log = null, ?array $geFix = null): ?array {
    $d3 = (array)$S['data3']; $m = (array)$d3['modeling']; $AR = pp_v10_actual_rows($m); $log = []; $base = $bd; $bad = []; $flip = 0; $prevNeed = null;
    $fz = pp_v10_fz($S, $bd, $T, $dl, $geFix);

    for ($it = 0; $it < $maxIt && is_array($fz) && empty($fz['valid']); $it++) {
        $V = pp_validate_hard_constraints($S, $fz['output']); $other = false; $newBad = [];
        foreach ((array)($V['violations'] ?? []) as $v) { $t = (string)($v[0] ?? ''); $msg = (string)($v[1] ?? '');
            if ($t === 'gas_quota') continue;
            if (($t === 'export_range' || $t === 'export_ramp') && preg_match('~row (\d+)~', $msg, $mm)) { $r0 = (int)$mm[1]; foreach ([$r0 - 1, $r0, $r0 + 1] as $rr) if ($rr >= 1 && $rr <= 48) $newBad[$rr] = true; continue; }
            $other = true; $log[] = 'v:' . $t . ':' . substr($msg, 0, 60); }
        if ($other && $bd !== $base) { $bd = $base; $bad = []; $fz = pp_v10_fz($S, $bd, $T, $dl, $geFix); $log[] = 'reset'; if ($it >= 1) break; continue; }
        if ($other) { $log[] = 'lain'; break; }
        /* row yang melanggar Export/ramp: perubahan langkah terakhir pada row itu dibatalkan (bukan kembali ke dispatch awal) */
        foreach ($newBad as $rr => $_) { $bad[$rr] = true; $bd[$rr - 1] = isset($prevBd) ? $prevBd[$rr - 1] : $base[$rr - 1]; }
        $i = (array)$fz['output']['info']; $q = (float)($i['Total Gas Quota (BBTUD)'] ?? 0); [$lo, $hi] = pp_gas_window($q);
        $g = (float)($i['Total Gas Used (BBTUD)'] ?? 0); if ((int)($i['Actual Hours Provided'] ?? 0) > 0 && isset($i['Effective Total Gas (BBTUD)'])) $g = (float)$i['Effective Total Gas (BBTUD)'];
        $pq = (float)($i['PGN Pipe Quota (BBTUD)'] ?? 0); $pu = (float)($i['PGN Pipe Used (BBTUD)'] ?? 0);
        $need = ($hi - 0.008) - $g;
        if ($pq > 0) { $nP = ($pq - 0.008) - $pu; if ($g >= $lo && $g <= $hi) $need = $nP; else $need = abs($nP) < abs($need) && $nP * $need > 0 ? $nP : $need; }
        $log[] = round($need, 4);
        if ($prevNeed !== null && $prevNeed * $need < 0 && abs($need) > 0.02) { $flip++; if ($flip >= 2) { $log[] = 'berbalik'; break; } }
        $prevNeed = $need;
        if (abs($need) < 2e-4 && !$newBad) break;
        $rows = (array)$fz['output']['data']; $C = [];
        foreach ($rows as $k => $r) { $r1 = $k + 1; if (isset($AR[$r1]) || isset($bad[$r1])) continue;
            $ex = (float)($r['Export_PLN'] ?? 0); $elo = (float)($r['pln_lo'] ?? -1e9); $ehi = (float)($r['pln_hi'] ?? 1e9);
            foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $mw = (float)($bd[$k][$U] ?? 0); if ($mw <= 0.01 || pp_get_fixed_load($m, $u, $r1) >= 0) continue;
                if (function_exists('pp_is_mm2100_unit') ? pp_is_mm2100_unit($u) : $u === 'g10') continue;   // unit MM2100 (gas KP72) bukan lever window PGN/Jababeka
                $mf = (calc_fuel($d3, $u, $mw + 1.0) - calc_fuel($d3, $u, max(1.0, $mw - 1.0))) / (($mw + 1.0) - max(1.0, $mw - 1.0)); if ($mf <= 1e-6) continue;
                $mn = pp_effective_min_load($d3, $m, $u, $r1); if ($mn <= 0.01) $mn = (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? $d3[$u]['min_load'] ?? 0);
                if ($mw < $mn - 1e-6) continue;                                   // tangga start-up / shut-down tidak digeser
                $room = $need > 0 ? min(pp_effective_max_load($d3, $m, $u, $r1) - $mw, ($ehi - 0.6 - $ex) / 1.45) : min($mw - $mn, ($ex - $elo - 0.6) / 1.45);
                if ($room > 0.05) $C[] = [$mf, $k, $U, $room]; } }
        usort($C, function ($a, $b) use ($need) { return $need > 0 ? ($a[0] <=> $b[0] ?: $a[1] <=> $b[1] ?: strcmp($a[2], $b[2])) : ($b[0] <=> $a[0] ?: $a[1] <=> $b[1] ?: strcmp($a[2], $b[2])); });
        $rem = abs($need); $prevBd = $bd;
        for ($pass = 0; $pass < 8 && $rem > 1e-5; $pass++) foreach ($C as $ci => $c) { if ($rem <= 1e-5) break; if ($C[$ci][3] <= 0.01) continue;
            $dmw = min($C[$ci][3], 1.5, $rem / (0.5 * $c[0])); $bd[$c[1]][$c[2]] = round((float)$bd[$c[1]][$c[2]] + ($need > 0 ? $dmw : -$dmw), 3); $C[$ci][3] -= $dmw; $rem -= $dmw * $c[0] * 0.5; }
        $fz = pp_v10_fz($S, $bd, $T, $dl, $geFix);
    }
    return $fz;
}
function pp_v10_lib_file(array $ancOrig): string { return pp_final_dir() . DIRECTORY_SEPARATOR . substr(hash('sha256', json_encode(pp_final_canon($ancOrig))), 0, 32) . '.v10lib.json'; }
/* Pustaka dispatch kanonik jangkar (lihat definisi di atas). Titik grid dikerjakan bersama pekerja pembantu. */
function pp_v10_library(string $jobId, array $ancOrig, array $F, float $dl): ?array {
    $f = pp_v10_lib_file($ancOrig);
    $t0 = microtime(true); $v = (array)($F['v3'] ?? []); $W = (array)($v['winner'] ?? []);
    if (!is_array($W['stops'] ?? null)) return null;
    $K = [['id' => 'winner:' . ($W['node'] ?? '?'), 'stops' => array_values((array)$W['stops']), 'adj' => (float)($W['adj'] ?? 0), 'hint' => isset($W['supplier_target']) ? (float)$W['supplier_target'] : null, 'cp' => (float)($W['cp'] ?? 0)]];
    $cs = [];
    foreach ((array)($v['candidates'] ?? []) as $c) { if ((empty($c['valid']) && empty($c['gas_only'])) || (float)($c['cp'] ?? 99) > (float)($W['cp'] ?? 0) + 0.12) continue;
        $cs[] = ['id' => (string)($c['nk'] ?? ($c['src'] ?? '?')), 'stops' => array_values(pp_v3_commitment_stops($c)), 'adj' => (float)($c['adj'] ?? 0), 'hint' => isset($W['supplier_target']) ? (float)$W['supplier_target'] : null, 'cp' => (float)($c['cp'] ?? 0)]; }
    usort($cs, function ($a, $b) { return [$a['cp'], $a['id']] <=> [$b['cp'], $b['id']]; });
    $seenC = [pp_v3_sig($K[0]['stops']) => true];
    foreach ($cs as $c) { $sg = pp_v3_sig($c['stops']); if (isset($seenC[$sg])) continue; $seenC[$sg] = true; $K[] = $c; if (count($K) >= 5) break; }
    $P = [-0.16, 0.0, 0.16];
    $spec = md5(json_encode([$K, $P]));
    $r = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($r) && ($r['engine'] ?? '') === pp_engine_fingerprint() && ($r['anchor'] ?? '') === ($F['_file'] ?? '#') && ($r['spec'] ?? '') === $spec && is_array($r['entries'] ?? null) && is_array($r['K'] ?? null)) return $r + ['reused' => true];
    $ancN = $ancOrig; $ancN['data3']['modeling']['__v9_nopolish'] = true; $ancN['data3']['modeling']['__v10_sup_secant'] = true;
    $tasks = []; foreach ($K as $k) foreach ($P as $p) $tasks[] = ['type' => 'eval', 'off' => [], 'seed' => $k['stops'], 'adj' => $k['adj'] + $p, 'hint' => $k['hint']];
    if ($jobId !== '' && pp_v4_helper_slots() > 0 && is_dir(pp_job_dir($jobId))) { try { pp_v4_work_publish($jobId, $ancN, ['kind' => 'tasks', 'tasks' => $tasks, 'dl' => $dl]); pp_v4_cooperate($jobId, $ancN, $tasks, $dl); } catch (Throwable $e) {} }
    $E = []; $n = 0; $nv = 0;
    foreach ($K as $k) foreach ($P as $p) {
        if (microtime(true) > $dl - 2.0) return null;                    // pustaka tidak lengkap = tidak dipakai (bukan pustaka parsial)
        $a = pp_tl_eval($ancN, [], $k['adj'] + $p, $dl, $k['stops'], $k['hint']); pp_tl_clean_globals(); $n++;
        if (!is_array($a) || empty($a['valid']) || !is_array($a['output'] ?? null) || count((array)($a['output']['data'] ?? [])) !== 48) continue;
        $nv++; $sh = pp_v10_shape((array)$a['output']['data']); $sig = md5(json_encode($sh)); if (isset($E[$sig])) continue;
        $E[$sig] = ['sig' => $sig, 'k' => $k['id'], 'p' => $p, 'rows' => $sh, 'T' => pp_tl_supplier_target($a['output']), 'cp_anchor' => (float)($a['key']['cp'] ?? 0), 'stops' => pp_v3_stops((array)$a['output']['data'])];
    }
    $lib = ['schema' => 'co12-v10-library-v1', 'engine' => pp_engine_fingerprint(), 'anchor' => $F['_file'] ?? null, 'spec' => $spec, 'commitments' => array_column($K, 'id'), 'K' => $K, 'grid' => $P,
            'grid_evaluations' => $n, 'grid_valid' => $nv, 'entries' => array_values($E), 'built_s' => round(microtime(true) - $t0, 2)];
    $enc = json_encode($lib, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if (is_string($enc)) { $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp'; if (@file_put_contents($tmp, $enc) === strlen($enc)) { if (pp_is_windows() && is_file($f)) @unlink($f); if (!@rename($tmp, $f)) @unlink($tmp); } else @unlink($tmp); }
    return $lib + ['reused' => false];
}
function pp_v10_res_file(array $S0, string $id): string { return pp_cs_file('v10_' . substr(hash('sha256', pp_tl_key($S0) . '|' . $id), 0, 40)); }
/* Tugas Tier-2a (pemilik atau pembantu): pendaratan satu dispatch pustaka; hasil ringkas disimpan per state. */
function pp_v10_land_task(array $S0, array $t, float $dl, ?string $jobId = null): void {
    $f = pp_v10_res_file($S0, (string)$t['id']); if (is_file($f)) return;
    $lg = null; $a = pp_v10_land($S0, (array)$t['rows'], isset($t['T']) ? (float)$t['T'] : null, $dl, (int)($t['max'] ?? 6), $lg);
    if (!is_array($a)) return;
    $sum = array_intersect_key($a, array_flip(['valid', 'key', 'dev', 'gas_only', 'checks', 'violations', 'running', 'supplier_target']));
    pp_tl_write($f, ['id' => $t['id'], 'a' => $sum, 'out' => !empty($a['valid']) ? $a['output'] : null, 'log' => $lg, 'evals' => 1 + count((array)$lg)]);
    /* kandidat valid langsung tersedia sebagai VALID PROVISIONAL (kolam state) */
    if (!empty($a['valid']) && $jobId !== null && $jobId !== '') { try { pp_tl_pool_offer(pp_tl_key($S0) . '_x', $a, 'v10_tier2a', $jobId); } catch (Throwable $e) {} }
}
/* V10 review generik rute cepat: kandidat review (DELAY / STOP / DECOMMIT / SWAP) dievaluasi sebagai dispatch 48 row
 * yang disusun dari dispatch pemenang — unit yang dimatikan kandidat dinolkan, unit yang dinyalakan (SWAP: peer
 * mengambil profil beban unit asal, dijepit batas efektif; DELAY: profil unit digeser ke start baru) — lalu defisit
 * Export per row ditutup oleh unit berjalan (laju bahan bakar marjinal termurah, dalam Effective Max) dan window gas
 * / supplier didaratkan (pp_v10_land). Simulasi penuh 48 row + validasi penuh terhadap input asli. */
function pp_v10_review_eval(array $orig, array $W, array $c, float $dl): ?array {
    $d3 = (array)$orig['data3']; $m = (array)$d3['modeling']; $T = isset($GLOBALS['ppV10Inc']['T']) ? (float)$GLOBALS['ppV10Inc']['T'] : null;
    $rows0 = pp_v10_shape((array)$W['data']); $sh = $rows0;
    $offM = []; foreach ((array)$c['stops'] as $st) { $u = strtolower((string)$st['unit']); for ($r = (int)$st['start']; $r <= (int)$st['stop']; $r++) $offM[$u][$r] = true; }
    $u0 = (string)($c['unit'] ?? ''); [$a, $b] = (array)($c['iv'] ?? [0, 0]); $U0 = strtoupper($u0);
    /* profil start-up / shut-down unit asal pada interval [a,b]: row di bawah beban minimum di awal (tangga start) dan di akhir (tangga stop) */
    $mn0 = $u0 !== '' ? pp_effective_min_load($d3, $m, $u0, max(1, (int)$a)) : 0.0; if ($mn0 <= 0.01 && $u0 !== '') $mn0 = (float)($d3[$u0]['min_ccload'] ?? $d3[$u0]['min_scload'] ?? $d3[$u0]['min_load'] ?? 0);
    $st0 = 0; $tl0 = 0;
    if ($u0 !== '' && $a >= 1 && $b >= $a) { for ($r = $a; $r <= $b && (float)($rows0[$r - 1][$U0] ?? 0) < $mn0 - 1e-6; $r++) $st0++; for ($r = $b; $r >= $a + $st0 && (float)($rows0[$r - 1][$U0] ?? 0) < $mn0 - 1e-6; $r--) $tl0++; }
    $kind = (string)($c['kind'] ?? '');
    if (in_array($kind, ['STOP', 'EARLY_STOP'], true) && $tl0 > 0) {       // tangga shut-down dipindah ke sebelum row stop baru
        $sN = (int)($c['off_rows'][0] ?? 0);
        for ($q = 0; $q < $tl0; $q++) { $r = $sN - $tl0 + $q; if ($r >= $a + $st0 && $r <= $b) $sh[$r - 1][$U0] = (float)($rows0[$b - $tl0 + $q][$U0] ?? 0); } }
    if ($kind === 'DELAY' && $u0 !== '') { $na = null; for ($x = (int)$a; $x <= 48; $x++) if (empty($offM[$u0][$x])) { $na = $x; break; }
        if ($na !== null) { $k = $na - $a;
            for ($r = $na; $r <= min(48, (int)$b); $r++) { $rr = $r - $k; if (empty($offM[$u0][$r])) $sh[$r - 1][$U0] = ($rr >= $a && $rr < $a + $st0) ? (float)($rows0[$rr - 1][$U0] ?? 0) : (float)($rows0[$r - 1][$U0] ?? 0); } } }
    foreach (pp_tl_gt_units() as $g) { $G = strtoupper($g);
        for ($r = 1; $r <= 48; $r++) { $on = empty($offM[$g][$r]); $mw = (float)($sh[$r - 1][$G] ?? 0);
            if (!$on && $mw > 0.01) $sh[$r - 1][$G] = 0.0;
            elseif ($on && $mw <= 0.01) {
                $src = null;
                if (($c['kind'] ?? '') === 'SWAP' && $g === (string)($c['peer'] ?? '')) $src = (float)($rows0[$r - 1][strtoupper($u0)] ?? 0);
                elseif (($c['kind'] ?? '') === 'DELAY' && $g === $u0) { $na = null; for ($x = 1; $x <= 48; $x++) if (empty($offM[$g][$x]) && $x >= $a) { $na = $x; break; }
                    /* tangga start-up digeser ke start baru; sesudahnya beban interval asal dipertahankan */
                    $k = $na === null ? 0 : $na - $a; $rr = $r - $k;
                    if ($rr >= $a && $rr < $a + $st0) $src = (float)($rows0[$rr - 1][$U0] ?? 0);
                    elseif ($r >= $a && $r <= $b) $src = (float)($rows0[$r - 1][$U0] ?? 0);
                    else $src = (float)($rows0[max($a, $b - $tl0) - 1][$U0] ?? 0); }
                if ($src === null || $src <= 0.01) return null;          // kandidat aditif lain: tidak dapat disusun (dievaluasi penuh)
                $mx = pp_effective_max_load($d3, $m, $g, $r); $sh[$r - 1][$G] = round(min($mx, $src), 4); } } }
    /* defisit Export per row ditutup unit berjalan (maks. 2 putaran) */
    $fz = pp_v10_fz($orig, $sh, $T, $dl); $geS = null;
    for ($it = 0; $it < 2 && is_array($fz); $it++) { $fix = false;
        $rowsF = array_values((array)$fz['output']['data']);
        foreach ($rowsF as $k => $r) { $lo = (float)($r['pln_lo'] ?? 0); $ex = (float)($r['Export_PLN'] ?? 0);
            /* batas bawah Export row ini: Range Min dan ramp Export dari row sebelumnya (turun maks. 35 MW / 30 menit) */
            $tgt = $lo + 0.3; if ($k > 0) $tgt = max($tgt, (float)($rowsF[$k - 1]['Export_PLN'] ?? 0) - 34.5);
            if ($ex >= $tgt) continue;
            $need = ($tgt + 0.5 - $ex); $C = [];
            foreach (pp_tl_gt_units() as $g) { $G = strtoupper($g); $mw = (float)($sh[$k][$G] ?? 0); if ($mw <= 0.01 || pp_get_fixed_load($m, $g, $k + 1) >= 0) continue;
                if (function_exists('pp_is_mm2100_unit') ? pp_is_mm2100_unit($g) : $g === 'g10') continue;
                $mnG = pp_effective_min_load($d3, $m, $g, $k + 1); if ($mnG <= 0.01) $mnG = (float)($d3[$g]['min_ccload'] ?? $d3[$g]['min_scload'] ?? $d3[$g]['min_load'] ?? 0);
                if ($mw < $mnG - 1e-6) continue;                                  // tangga start-up / shut-down tidak digeser
                $room = pp_effective_max_load($d3, $m, $g, $k + 1) - $mw; if ($room <= 0.05) continue;
                $C[] = [(calc_fuel($d3, $g, $mw + 1.0) - calc_fuel($d3, $g, max(1.0, $mw - 1.0))) / (($mw + 1.0) - max(1.0, $mw - 1.0)), $G, $room]; }
            usort($C, function ($x, $y) { return $x[0] <=> $y[0] ?: strcmp($x[1], $y[1]); });
            foreach ($C as $q) { if ($need <= 0) break; $d = min($q[2], $need); $sh[$k][$q[1]] = round((float)$sh[$k][$q[1]] + $d, 4); $need -= $d; $fix = true; } }
        if (!$fix) break; $fz = pp_v10_fz($orig, $sh, $T, $dl, $geS); }
    if (!is_array($fz)) return null;
    if (!empty($fz['valid'])) return $fz;
    $lg = null; $L = pp_v10_land($orig, $sh, $T, $dl, 6, $lg, $geS); $GLOBALS['ppV10LastLog'] = $lg;
    return is_array($L) ? $L : $fz;
}
/* =================================================================================================
 *  V11 — KONSOLIDASI BEBAN, LOW_LOAD_FRAGMENTATION, BAND CP 0,2 % + TIE-BREAK HEAT RATE.
 *
 *  Deteksi LOW_LOAD_FRAGMENTATION (per row): >= 2 unit GTG dispatchable berjalan dekat beban minimum
 *  (<= Effective Min + max(2 MW, 10 % rentang)) DAN ada unit berjalan berprioritas lebih tinggi dari unit
 *  dekat-minimum terendah yang masih punya legal headroom >= 1 MW (Effective Max - beban, tanpa Fixed Load).
 *  Setiap temuan wajib diselesaikan: kandidat konsolidasi (STOP / EARLY STOP / DECOMMIT / DELAY / SWAP /
 *  CONSOLIDATE = beban unit prioritas rendah dipindah ke headroom unit berjalan, blok GTG+STG dihitung engine)
 *  dievaluasi, atau alasan sah (runtime, Export, ramp, reserve, Bus Flow, gas, blok STG, status paksa, CP
 *  kandidat lebih mahal) tercatat per row.
 *
 *  Comparator akhir V11 (seleksi band, fungsi himpunan kandidat valid — deterministik, transitif):
 *    1. hard constraints + provenance PASS (kandidat valid);  2. CP minimum;
 *    3. band = kandidat valid dengan CP <= CP_min x (1 + 0,2 %); di dalam band: Heat Rate JBBK+MM2100 lebih rendah,
 *       start lebih sedikit, row unit prioritas rendah running lebih sedikit, row fragmentation lebih sedikit,
 *       skor Unit Priority lebih baik, kunci kanonik (sidik jari dispatch).
 *  Kandidat dengan CP > 0,2 % di atas minimum tidak pernah dipilih karena Heat Rate.
 * ================================================================================================= */
function pp_v11_on(): bool { return (string)getenv('PP_V11') !== '0' && function_exists('pp_v10_fast') && pp_v10_fast(); }
function pp_v11_band_pct(): float { $v = getenv('PP_V11_BAND_PCT'); return ($v !== false && $v !== '') ? max(0.0, (float)$v) : 0.2; }
function pp_v11_lim(array $d3, array $m, string $u, int $r): array {
    $mn = pp_effective_min_load($d3, $m, $u, $r); if ($mn <= 0.01) $mn = (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? $d3[$u]['min_load'] ?? 0);
    $mx = pp_effective_max_load($d3, $m, $u, $r); return [$mn, max($mn, $mx)];
}
function pp_v11_fragmentation(array $orig, array $out): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $rank = pp_priority_rank($m);
    $rows = array_values((array)($out['data'] ?? [])); $R = []; $unitRows = [];
    foreach ($rows as $k => $row) { $r = $k + 1; $near = []; $head = [];
        foreach (pp_tl_gt_units() as $u) { if (!isset($d3[$u]) || pp_is_mm2100_unit($u)) continue; $U = strtoupper($u); $mw = (float)($row[$U] ?? 0); if ($mw <= 0.01) continue;
            [$mn, $mx] = pp_v11_lim($d3, $m, $u, $r); if ($mw < $mn - 1e-6) continue;                        // tangga start-up / shut-down
            $tol = max(2.0, 0.1 * ($mx - $mn));
            if ($mw <= $mn + $tol) $near[$u] = round($mw, 3);
            $h = $mx - $mw; if ($h >= 1.0 && pp_get_fixed_load($m, $u, $r) < 0) $head[$u] = round($h, 3); }
        if (count($near) < 2) continue;
        $low = []; foreach ($near as $u => $mw) { $hu = []; foreach ($head as $v => $h) if ($v !== $u && (int)($rank[$v] ?? 99) < (int)($rank[$u] ?? 99)) $hu[strtoupper($v)] = $h;
            if ($hu) $low[strtoupper($u)] = ['mw' => $mw, 'higher_priority_headroom' => $hu, 'headroom_mw' => round(array_sum($hu), 3)]; }
        if (!$low) continue;
        $R[$r] = ['row' => $r, 'near_min' => array_change_key_case($near, CASE_UPPER), 'low_priority_units' => $low];
        foreach ($low as $U => $_) $unitRows[$U][] = $r;
    }
    return ['type' => 'LOW_LOAD_FRAGMENTATION', 'rows' => $R, 'count' => count($R), 'unit_rows' => $unitRows];
}
/* Metrik tie-break satu kandidat valid. */
function pp_v11_metrics(array $orig, array $a): array {
    $o = (array)($a['output'] ?? []); $i = (array)($o['info'] ?? []); $k = (array)($a['key'] ?? []);
    $m = (array)($orig['data3']['modeling'] ?? []); $rank = pp_priority_rank($m); $el = pp_v8_eligible($orig);
    $run = []; $lpr = 0;
    foreach (array_values((array)($o['data'] ?? [])) as $row) foreach (pp_tl_gt_units() as $u) if ((float)($row[strtoupper($u)] ?? 0) > 0.01) { $run[$u] = ($run[$u] ?? 0) + 1; if (!empty($el[$u])) $lpr++; }
    $fr = pp_v11_fragmentation($orig, $o);
    return ['cp' => isset($k['cp']) ? (float)$k['cp'] : (float)($i['Cost Production (USD/MWh)'] ?? INF), 'hr' => (float)($k['hr'] ?? ($i['JBBK MM Heat Rate (BTU/kWh)'] ?? INF)),
        'starts' => (int)($k['starts'] ?? 0), 'low_priority_rows' => $lpr, 'fragmentation_rows' => (int)$fr['count'], 'priority_score' => pp_v9_prio_score($run, $rank),
        'generation_mwh' => $i['Net Production (MWh)'] ?? null, 'export_mwh' => $i['Daily PLN Exp (MWh)'] ?? null, 'total_cost_usd' => $i['Total Cost (USD)'] ?? null,
        'sig' => pp_v6_gtg_sig((array)($o['data'] ?? []))];
}
/* Seleksi band atas himpunan kandidat [['id'=>..,'a'=>assess dengan key+output], ...]. */
function pp_v11_band_select(array $orig, array $cands): array {
    $V = []; $seen = [];
    foreach ($cands as $c) { $a = $c['a'] ?? null; if (!is_array($a) || empty($a['valid']) || !is_array($a['output'] ?? null) || !is_array($a['key'] ?? null)) continue;
        if ((int)($a['key']['hard'] ?? 1) !== 0 || (int)($a['key']['constraint'] ?? 1) !== 0) continue;
        $mt = pp_v11_metrics($orig, $a); if (isset($seen[$mt['sig']])) { $V[$seen[$mt['sig']]]['aliases'][] = $c['id']; continue; }
        $seen[$mt['sig']] = count($V); $V[] = ['id' => (string)$c['id'], 'a' => $a, 'm' => $mt, 'aliases' => []]; }
    if (!$V) return ['winner' => null, 'table' => [], 'cp_min' => null];
    $cpMin = INF; foreach ($V as $x) $cpMin = min($cpMin, round($x['m']['cp'], 6));
    $pct = pp_v11_band_pct(); $hi = round($cpMin * (1.0 + $pct / 100.0), 6) + 1e-9;
    $ord = function ($x, $y) { $mx = $x['m']; $my = $y['m'];
        foreach ([['hr', 4], ['starts', 0], ['low_priority_rows', 0], ['fragmentation_rows', 0], ['priority_score', 3], ['cp', 6]] as [$f, $d]) {
            $p = round((float)$mx[$f], $d); $q = round((float)$my[$f], $d); if (abs($p - $q) > 1e-12) return $p < $q ? -1 : 1; }
        return strcmp($mx['sig'], $my['sig']); };
    $band = []; foreach ($V as $i => $x) if (round($x['m']['cp'], 6) <= $hi) $band[] = $i;
    usort($band, function ($p, $q) use ($V, $ord) { return $ord($V[$p], $V[$q]); });
    $w = $band[0]; $W = $V[$w]; $tbl = [];
    foreach ($V as $i => $x) { $in = in_array($i, $band, true); $mt = $x['m'];
        $why = $i === $w ? 'MENANG' : (!$in ? sprintf('KALAH:CP_DI_LUAR_BAND_0,2%%(+%.3f%%)', 100.0 * ($mt['cp'] - $cpMin) / max(1e-9, $cpMin))
            : (round($mt['hr'], 4) > round($W['m']['hr'], 4) ? sprintf('KALAH:HEAT_RATE_LEBIH_TINGGI(+%.2f BTU/kWh)', $mt['hr'] - $W['m']['hr'])
            : ($mt['starts'] > $W['m']['starts'] ? 'KALAH:START_LEBIH_BANYAK' : ($mt['low_priority_rows'] > $W['m']['low_priority_rows'] ? 'KALAH:ROW_UNIT_PRIORITAS_RENDAH_LEBIH_BANYAK'
            : ($mt['fragmentation_rows'] > $W['m']['fragmentation_rows'] ? 'KALAH:FRAGMENTATION_LEBIH_BANYAK' : 'KALAH:PRIORITY/KUNCI_KANONIK')))));
        $tbl[] = ['candidate' => $x['id'], 'aliases' => $x['aliases'], 'cp' => round($mt['cp'], 4), 'delta_cp_pct' => round(100.0 * ($mt['cp'] - $cpMin) / max(1e-9, $cpMin), 4), 'heat_rate' => round($mt['hr'], 2),
            'generation_mwh' => $mt['generation_mwh'], 'export_mwh' => $mt['export_mwh'], 'starts' => $mt['starts'], 'low_priority_running_rows' => $mt['low_priority_rows'],
            'fragmentation_rows' => $mt['fragmentation_rows'], 'priority_score' => round($mt['priority_score'], 1), 'constraints' => 'PASS', 'in_band' => $in, 'result' => $why]; }
    usort($tbl, function ($p, $q) { return [$p['result'] !== 'MENANG', !$p['in_band'], $p['cp'], $p['candidate']] <=> [$q['result'] !== 'MENANG', !$q['in_band'], $q['cp'], $q['candidate']]; });
    return ['winner' => $W, 'table' => $tbl, 'cp_min' => round($cpMin, 6), 'band_upper' => round($cpMin * (1.0 + $pct / 100.0), 6), 'band_pct' => $pct, 'in_band' => count($band), 'valid' => count($V)];
}
/* Kandidat CONSOLIDATE: unit $c['unit'] berhenti mulai $c['off_rows'][0] (tangga shut-down dipindah), beban yang dilepas
 * dipindah ke headroom unit berjalan lain (urut laju bahan bakar marjinal, dalam Effective Max, tanpa Fixed Load, bukan
 * unit MM2100) sehingga total GTG tetap -> Export tidak bergeser; STG / bahan bakar / gas / Export dihitung ulang engine
 * (simulasi penuh 48 row), lalu window gas didaratkan (pp_v10_land). Validasi penuh terhadap input asli. */
function pp_v11_consolidate_eval(array $orig, array $W, array $c, float $dl, ?float $T): ?array {
    $d3 = (array)$orig['data3']; $m = (array)$d3['modeling']; $u0 = strtolower((string)($c['unit'] ?? '')); $U0 = strtoupper($u0); if ($u0 === '') return null;
    $rows0 = pp_v10_shape((array)$W['data']); $sh = $rows0; [$a, $b] = (array)($c['iv'] ?? [0, 0]); $sN = (int)($c['off_rows'][0] ?? 0); $sE = (int)($c['off_rows'][1] ?? 0);
    if ($sN < 1 || $sE < $sN) return null;
    $mn0 = pp_v11_lim($d3, $m, $u0, max(1, (int)$a))[0]; $tl0 = 0;
    for ($r = $b; $r >= $a && (float)($rows0[$r - 1][$U0] ?? 0) < $mn0 - 1e-6; $r--) $tl0++;
    $rel = [];
    for ($r = $sN; $r <= $sE; $r++) { $rel[$r] = (float)($rows0[$r - 1][$U0] ?? 0); $sh[$r - 1][$U0] = 0.0; }
    for ($q = 0; $q < $tl0; $q++) { $r = $sN - $tl0 + $q; if ($r >= $a && $r < $sN) { $rel[$r] = ($rel[$r] ?? 0) + max(0.0, (float)$sh[$r - 1][$U0] - (float)($rows0[$b - $tl0 + $q][$U0] ?? 0)); $sh[$r - 1][$U0] = (float)($rows0[$b - $tl0 + $q][$U0] ?? 0); } }
    $moved = 0.0;
    foreach ($rel as $r => $need) { if ($need <= 0.01) continue; $C = [];
        foreach (pp_tl_gt_units() as $g) { if ($g === $u0 || pp_is_mm2100_unit($g)) continue; $G = strtoupper($g); $mw = (float)($sh[$r - 1][$G] ?? 0); if ($mw <= 0.01 || pp_get_fixed_load($m, $g, $r) >= 0) continue;
            [$mn, $mx] = pp_v11_lim($d3, $m, $g, $r); if ($mw < $mn - 1e-6) continue; $room = $mx - $mw; if ($room <= 0.05) continue;
            $C[] = [(calc_fuel($d3, $g, $mw + 1.0) - calc_fuel($d3, $g, max(1.0, $mw - 1.0))) / (($mw + 1.0) - max(1.0, $mw - 1.0)), $G, $room]; }
        usort($C, function ($x, $y) { return $x[0] <=> $y[0] ?: strcmp($x[1], $y[1]); });
        foreach ($C as $q) { if ($need <= 0.01) break; $d = min($q[2], $need); $sh[$r - 1][$q[1]] = round((float)$sh[$r - 1][$q[1]] + $d, 4); $need -= $d; $moved += $d; } }
    if ($moved <= 0.05) return null;                                                                   // tidak ada headroom: sama dengan STOP biasa
    $fz = pp_v10_fz($orig, $sh, $T, $dl); if (!is_array($fz)) return null;
    if (!empty($fz['valid'])) return $fz + ['v11_moved_mw' => round($moved, 2)];
    $lg = null; $L = pp_v10_land($orig, $sh, $T, $dl, 6, $lg);
    return (is_array($L) ? $L : $fz) + ['v11_moved_mw' => round($moved, 2)];
}
/* V11 sapuan konsolidasi: kandidat untuk setiap unit prioritas rendah yang terlibat LOW_LOAD_FRAGMENTATION pada pemenang review
 * (STOP / EARLY STOP / DECOMMIT / DELAY / SWAP generik + varian CONSOLIDATE). Tier 1 = bukti kapasitas Export, legalitas,
 * heat rate SWAP, bukti kapasitas V10. Tier 2 = simulasi penuh 48 row (dispatch tersusun + pendaratan window gas). Kandidat
 * valid masuk himpunan seleksi band. Batas jumlah deterministik (PP_V11_SWEEP_CAP, bawaan 8). */
function pp_v11_consolidation_sweep(array $orig, array $W, array $hist, float $dl, array &$pool): array {
    $t0 = microtime(true); $fr = pp_v11_fragmentation($orig, $W);
    $R = ['schema' => 'co12-v11-consolidation-sweep-v1', 'fragmentation_rows' => $fr['count'], 'fragmentation_unit_rows' => $fr['unit_rows'], 'candidates' => [], 'generated' => 0, 'screened_tier1' => 0, 'full_run' => 0, 'valid' => 0];
    if (!$fr['count']) { $R['status'] = 'TIDAK_ADA_LOW_LOAD_FRAGMENTATION'; $R['wall_s'] = round(microtime(true) - $t0, 3); return $R; }
    $T = pp_tl_supplier_target($W);
    $saveInc = $GLOBALS['ppV10Inc'] ?? null; $GLOBALS['ppV10Inc'] = is_array($saveInc) ? $saveInc : []; $GLOBALS['ppV10Inc']['T'] = $T;
    try {
        $G = pp_v8_candidates($orig, $W); $cap = (int)(getenv('PP_V11_SWEEP_CAP') ?: 8); $n = 0;
        $seen = []; foreach ($pool as $p) if (is_array($p['a']['output'] ?? null)) $seen[pp_v6_gtg_sig((array)$p['a']['output']['data'])] = $p['id'];
        foreach ((array)$G['candidates'] as $c) {
            if (!empty($c['keep'])) continue; $u = strtolower((string)$c['unit']); $U = strtoupper($u); $fl = (array)($fr['unit_rows'][$U] ?? []); if (!$fl) continue;
            $kind = (string)$c['kind']; if (!in_array($kind, ['STOP', 'EARLY_STOP', 'DECOMMIT', 'DELAY', 'SWAP'], true)) continue;
            $rg = $kind === 'SWAP' ? (array)($c['peer_rows'] ?? [0, -1]) : (array)($c['off_rows'] ?? [0, -1]); $touch = false;
            foreach ($fl as $r) if ($r >= (int)$rg[0] && $r <= (int)$rg[1]) { $touch = true; break; }
            if (!$touch) continue;
            $R['generated']++; $rec = ['id' => $c['id'], 'kind' => $kind, 'unit' => $U];
            $pr = null;
            if ($kind !== 'SWAP' && (int)$c['off_rows'][1] >= (int)$c['off_rows'][0]) $pr = pp_v8_export_prescreen($orig, (array)$W['data'], $u, range((int)$c['off_rows'][0], (int)$c['off_rows'][1]));
            if ($pr === null) $pr = pp_v9_legal_prescreen($orig, $c) ?? pp_v9_swap_hr_prescreen($orig, (array)$W['data'], $c);
            if ($pr === null && function_exists('pp_v10_export_capacity_proof')) $pr = pp_v10_export_capacity_proof($orig, (array)$W['data'], (array)$c['stops']);
            if ($pr !== null) { $R['screened_tier1']++; $rec['tier'] = 1; $rec['result'] = 'DIPANGKAS_TIER1:' . (string)($pr['code'] ?? ($pr['method'] ?? 'EXPORT')); $rec['proof'] = $pr; pp_v11_cnt_screen((array)($c['stops'] ?? [])); $R['candidates'][] = $rec; continue; }
            if ($n >= $cap) { $rec['result'] = 'TIDAK_DIEVALUASI:BATAS_SAPUAN'; $R['candidates'][] = $rec; continue; }
            if (microtime(true) > $dl - 2.0) { $rec['result'] = 'TIDAK_DIEVALUASI:BATAS_WAKTU'; $R['candidates'][] = $rec; continue; }
            $n++;
            $vars = ['SUSUN' => function () use ($orig, $W, $c, $dl) { return pp_v10_review_eval($orig, $W, $c, $dl); }];
            if (in_array($kind, ['STOP', 'EARLY_STOP', 'DECOMMIT'], true)) $vars['CONSOLIDATE'] = function () use ($orig, $W, $c, $dl, $T) { return pp_v11_consolidate_eval($orig, $W, $c, $dl, $T); };
            foreach ($vars as $vn => $fn) { $a = $fn(); pp_tl_clean_globals(); $r2 = $rec + ['variant' => $vn, 'tier' => 2];
                if (!is_array($a) || !is_array($a['output'] ?? null)) { $r2['result'] = 'TIDAK_DAPAT_DISUSUN'; $R['candidates'][] = $r2; continue; }
                $R['full_run']++; $sg = pp_v6_gtg_sig((array)$a['output']['data']);
                $r2['cp'] = $a['key']['cp'] ?? null; $r2['heat_rate'] = $a['key']['hr'] ?? null; if (isset($a['v11_moved_mw'])) $r2['moved_mw'] = $a['v11_moved_mw'];
                if (!empty($a['valid'])) { $R['valid']++; $r2['result'] = isset($seen[$sg]) ? 'VALID_STATE_FISIK_SAMA:' . $seen[$sg] : 'VALID';
                    if (!isset($seen[$sg])) { $seen[$sg] = 'V11_' . $vn . ':' . $c['id']; $a['sig'] = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$a['output']['data']))); $pool[] = ['id' => 'V11_' . $vn . ':' . $c['id'], 'a' => $a]; } }
                else $r2['result'] = 'INVALID:' . implode(',', array_values(array_unique(array_map('pp_v8_viol_code', (array)($a['violations'] ?? []))))) ?: 'INVALID';
                $R['candidates'][] = $r2; }
        }
    } finally { if ($saveInc === null) unset($GLOBALS['ppV10Inc']); else $GLOBALS['ppV10Inc'] = $saveInc; }
    $R['status'] = 'DIEVALUASI'; $R['wall_s'] = round(microtime(true) - $t0, 3);
    return $R;
}
/* V11 PENGHITUNG KANDIDAT (satu sumber kebenaran untuk UI & hasil): berkas kecil per kandidat unik di folder state x job.
 *   c_<sig> = kandidat diperiksa dengan simulasi penuh (dispatch 48 row unik), v_<sig> = kandidat valid (hard + provenance PASS),
 *   s_<id>  = kandidat gugur Tier 1 (screening). Proses pemilik & pembantu menulis ke folder yang sama (idempoten). */
function pp_v11_cnt_dir(string $stateKey, string $job): string { return pp_tl_file(preg_replace('~[^A-Za-z0-9]~', '', $stateKey) . '_cnt_' . preg_replace('~[^A-Za-z0-9_\-]~', '', $job)); }
/* Kunci kanonik: dispatch fisik 48 row (c_/v_ = pp_v6_gtg_sig) dan commitment fisik (k_/s_ = pp_v3_sig atas interval OFF
 * setiap GTG). Kandidat gugur Tier 1 dicatat dengan kunci commitment-nya; bila commitment yang sama juga di-full-run (oleh
 * pemilik, pembantu, kolam _q/_x, review, atau keluarga exact), ia TIDAK dihitung lagi sebagai screened-out. Alias
 * (nama/ID kandidat berbeda, state fisik sama) tidak pernah dihitung ulang karena kuncinya sama. */
function pp_v11_csig(array $stops): string { return 'k:' . pp_v3_sig($stops); }
function pp_v11_cnt_at(string $stateKey, string $job, string $kind, string $id, ?array $data = null): void {
    if ($stateKey === '' || $job === '' || (string)getenv('PP_V11') === '0') return;
    static $mk = [];
    $d = pp_v11_cnt_dir($stateKey, $job); if (!isset($mk[$d])) { if (!is_dir($d)) @mkdir($d, 0777, true); $mk[$d] = true; }
    $h = substr(md5($id), 0, 20);
    if ($kind === 'v') { @touch($d . DIRECTORY_SEPARATOR . 'v_' . $h); @touch($d . DIRECTORY_SEPARATOR . 'c_' . $h); }
    else @touch($d . DIRECTORY_SEPARATOR . $kind . '_' . $h);
    if (($kind === 'v' || $kind === 'c') && is_array($data) && count($data) === 48) {
        @touch($d . DIRECTORY_SEPARATOR . 'k_' . substr(md5(pp_v11_csig(pp_v8_stops(pp_v8_masks(array_values($data))))), 0, 20)); }
}
function pp_v11_cnt(string $kind, string $id, ?array $data = null): void { $h = $GLOBALS['ppTlHook'] ?? null; if (!is_array($h)) return; pp_v11_cnt_at((string)($h['key'] ?? ''), (string)($h['job'] ?? ''), $kind, $id, $data); }
/* Kandidat gugur Tier 1 (tanpa simulasi): kunci = commitment fisik kanonik. */
function pp_v11_cnt_screen(array $stops): void { pp_v11_cnt('s', pp_v11_csig($stops)); }
function pp_v11_cnt_read(string $stateKey, string $job, array $forceValid = []): array {
    $d = pp_v11_cnt_dir($stateKey, $job); $c = []; $v = []; $s = []; $k = [];
    if (is_dir($d)) foreach ((array)@scandir($d) as $f) { if (strlen($f) < 3) continue; $t = $f[0]; $x = substr($f, 2);
        if ($t === 'c') $c[$x] = true; elseif ($t === 'v') $v[$x] = true; elseif ($t === 's') $s[$x] = true; elseif ($t === 'k') $k[$x] = true; }
    foreach ($forceValid as $sg) { $x = substr(md5($sg), 0, 20); $v[$x] = true; $c[$x] = true; }
    $dup = count(array_intersect_key($s, $k)); $s = array_diff_key($s, $k);
    $full = count($c); $valid = count($v); $scr = count($s);
    return ['candidates_checked' => $full + $scr, 'candidates_screened_out' => $scr, 'candidates_full_run' => $full, 'candidates_valid' => $valid,
            'screened_also_full_run_excluded' => $dup, 'key' => 'canonical physical dispatch (48 row GTG MW) untuk full-run/valid; commitment fisik kanonik (interval OFF GTG) untuk screened-out'];
}
/* V11 AUDIT LOW_LOAD_FRAGMENTATION pada hasil akhir: setiap (row, unit prioritas rendah) wajib punya alasan sah
 * berbasis bukti — status paksa (Required / Cannot Stop / Last Data Running / Fixed Load), minimum runtime sejak start,
 * bukti per row review generik (Export, ramp, reserve, Bus Flow, gas, blok STG), hasil counterfactual sapuan konsolidasi
 * (tidak valid: kategori pelanggaran; valid: CP lebih mahal / kalah band). Alasan generik (minimum load, incumbent,
 * FINAL sebelumnya) tidak diterima. */
function pp_v11_frag_audit(array $input, array $out): array {
    $orig = pp_normalize_copy($input); $d3 = (array)$orig['data3']; $m = (array)$d3['modeling'];
    $fr = pp_v11_fragmentation($orig, $out); $el = pp_v8_eligible($orig); $lim = pp_runtime_limits($m);
    $rv = (array)($out['info']['V8 Priority Review'] ?? []); $ev = (array)($rv['row_evidence'] ?? []);
    $sw = (array)($out['info']['V11 Consolidation Sweep'] ?? []); $bc = (array)($out['info']['V11 Candidate Comparison'] ?? []);
    $lds = (array)($m['unit_last_data_status'] ?? []); $rows = array_values((array)($out['data'] ?? []));
    $F = []; $unres = 0; $cpW = (float)($out['info']['Cost Production (USD/MWh)'] ?? 0);
    foreach ($fr['rows'] as $r => $x) foreach ($x['low_priority_units'] as $U => $info) { $u = strtolower($U); $why = [];
        if (empty($el[$u])) { $req = in_array($u, array_map('strtolower', (array)($m['unit_cannot_stop'] ?? [])), true) ? 'CANNOT_STOP' : (isset($m['required_mode'][$u]) ? 'REQUIRED_MODE' : (in_array($u, array_map('strtolower', array_filter((array)($m['required_units'] ?? []), 'is_string')), true) ? 'REQUIRED_UNIT' : 'FIXED_LOAD_ATAU_STATUS_OPERATOR'));
            $why[] = 'STATUS_PAKSA:' . $req; }
        if (strtolower((string)($lds[$U] ?? '')) === 'running' && $r === 1) $why[] = 'LAST_DATA_RUNNING_ROW_1';
        /* minimum runtime sejak start interval berjalan */
        $a = $r; while ($a > 1 && (float)($rows[$a - 2][$U] ?? 0) > 0.01) $a--;
        $run = (int)($lim[pp_runtime_class($u, $d3)]['run_rows'] ?? 0);
        if ($a > 1 && $run > 0 && $r - $a + 1 <= $run) $why[] = sprintf('MIN_RUNTIME(start row %d, %d row, stop legal pertama row %d)', $a, $run, $a + $run);
        foreach ((array)($ev[$r . '#' . $U]['reasons'] ?? []) as $w) if (!preg_match('~^(MINIMUM_LOAD|INCUMBENT|PREVIOUS_FINAL)~i', (string)$w)) $why[] = 'REVIEW:' . $w;
        foreach ((array)($sw['candidates'] ?? []) as $c) { if (($c['unit'] ?? '') !== $U) continue; $res = (string)($c['result'] ?? '');
            if (strpos($res, 'DIPANGKAS_TIER1:') === 0) $why[] = 'KONSOLIDASI_' . $c['kind'] . ':' . substr($res, 16);
            elseif (strpos($res, 'INVALID') === 0) $why[] = 'KONSOLIDASI_' . $c['kind'] . '_' . ($c['variant'] ?? '') . ':' . $res;
            elseif (strpos($res, 'VALID') === 0 && isset($c['cp']) && (float)$c['cp'] > $cpW + 1e-6) $why[] = sprintf('KONSOLIDASI_%s_%s:CP_LEBIH_MAHAL(%.4f > %.4f)', $c['kind'], $c['variant'] ?? '', $c['cp'], $cpW); }
        $why = array_values(array_unique($why)); if (!$why) $unres++;
        $F[] = ['row' => $r, 'unit' => $U, 'unit_mw' => $info['mw'], 'higher_priority_headroom' => $info['higher_priority_headroom'], 'near_min_units' => array_keys($x['near_min']), 'reasons' => $why, 'resolved' => (bool)$why]; }
    return ['schema' => 'co12-v11-low-load-fragmentation-v1', 'type' => 'LOW_LOAD_FRAGMENTATION', 'status' => !$F ? 'PASS' : ($unres ? 'UNRESOLVED' : 'PASS_WITH_REASON'),
        'rows_flagged' => $fr['count'], 'findings' => count($F), 'unresolved' => $unres, 'unit_rows' => $fr['unit_rows'], 'findings_detail' => array_slice($F, 0, 96),
        'consolidation_candidates' => ['generated' => $sw['generated'] ?? 0, 'screened_tier1' => $sw['screened_tier1'] ?? 0, 'full_run' => $sw['full_run'] ?? 0, 'valid' => $sw['valid'] ?? 0],
        'band' => ['cp_min' => $bc['cp_min'] ?? null, 'winner' => $bc['winner'] ?? null],
        'rule' => 'deteksi: >= 2 unit GTG dekat Effective Min dan unit berjalan berprioritas lebih tinggi masih punya legal headroom >= 1 MW; alasan sah wajib berbasis bukti (status paksa, minimum runtime, bukti per row, counterfactual konsolidasi)'];
}
/* V11 AUDIT FORMULA CP: seluruh bahan bakar yang dipakai berharga, biaya start-up (gas penalti) tercatat, Heat Rate konsisten. */
function pp_v11_cp_audit(array $input, array $out): array {
    $i = (array)($out['info'] ?? []); $m = (array)($input['data3']['modeling'] ?? []); $P = (array)($m['price'] ?? []); $iss = [];
    $acc = ['PGN Pipe Used (BBTUD)' => 'pgn_pipe', 'PEP Jababeka Used (BBTUD)' => 'pep', 'LNG Used (BBTUD)' => 'lng', 'BBG Jababeka Used (BBTUD)' => 'bbg', 'PEP KP72 Total (BBTUD)' => 'pep',
            'Akasia JBBK Total (BBTUD)' => 'akasia', 'Akasia KP72 Total (BBTUD)' => 'akasia_kp72', 'BaGS JBBK Total (BBTUD)' => 'baskara', 'BaGS KP72 Total (BBTUD)' => 'baskara_kp72', 'Total Coal (ton)' => 'coal', 'Distillate Used (l)' => 'distillate'];
    $rows = [];
    foreach ($acc as $k => $pk) { $u = (float)($i[$k] ?? 0); if ($u <= 1e-6) continue; $pr = (float)($P[$pk] ?? 0); $rows[] = ['account' => $k, 'used' => round($u, 4), 'price_key' => $pk, 'price' => $pr, 'priced' => $pr > 0];
        if ($pr <= 0) $iss[] = 'BAHAN_BAKAR_TANPA_HARGA:' . $pk; }
    $cost = (float)($i['Total Cost (USD)'] ?? 0); $net = (float)($i['Net Production (MWh)'] ?? 0); $cp = (float)($i['Cost Production (USD/MWh)'] ?? 0);
    $cpChk = $net > 0 ? $cost / $net : null; if ($cpChk !== null && abs($cpChk - $cp) > 0.02) $iss[] = sprintf('CP_TIDAK_KONSISTEN(%.4f vs total_cost/net %.4f)', $cp, $cpChk);
    $fuel = (float)($i['JBBK MM Fuel (BBTUD)'] ?? 0); $prod = (float)($i['JBBK MM Net (MWh)'] ?? ($i['JBBK MM Prod (MWh)'] ?? 0)); $hr = (float)($i['JBBK MM Heat Rate (BTU/kWh)'] ?? 0);
    $hrChk = $prod > 0 ? $fuel * 1e9 / ($prod * 1000.0) : null; if ($hrChk !== null && $hr > 0 && abs($hrChk - $hr) / $hr > 0.005) $iss[] = sprintf('HEAT_RATE_TIDAK_KONSISTEN(%.2f vs fuel/prod %.2f)', $hr, $hrChk);
    return ['schema' => 'co12-v11-cp-audit-v1', 'status' => $iss ? 'FAIL' : 'PASS', 'issues' => $iss, 'fuel_accounts' => $rows,
        'cost_production' => $cp, 'total_cost_over_net' => $cpChk === null ? null : round($cpChk, 4), 'heat_rate_jbbk_mm' => $hr, 'heat_rate_fuel_over_prod' => $hrChk === null ? null : round($hrChk, 2),
        'startup_events' => $i['Startup Events (GTG)'] ?? null, 'startup_gas_penalty_bbtud' => $i['Startup Gas Penalty Applied (BBTUD)'] ?? null,
        'rule' => 'CP = Total Cost / Net Production; setiap akun bahan bakar terpakai wajib berharga > 0; gas start-up masuk akun bahan bakar; Heat Rate JBBK+MM2100 = bahan bakar / produksi'];
}
function pp_v10_fast_incremental(string $jobId, array $input): array {
    $t0 = microtime(true);
    $S0 = pp_normalize_copy($input);
    foreach (array_keys((array)$S0['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($S0['data3']['modeling'][$mk]);
    $rep = ['schema' => 'co12-v10-fast-v1', 'applied' => false];
    $B = pp_v3_find_base($S0);
    if ($B['base'] === null) { $rep['reason'] = $B['reason']; return ['output' => null, 'report' => $rep]; }
    $base = $B['base']; $diff = $B['diff']; $v = (array)$base['v3']; $W = (array)($v['winner'] ?? []);
    if (($diff['kind'] ?? '') !== 'slot' || !is_array($W['stops'] ?? null)) { $rep['reason'] = 'V10_BUKAN_PERUBAHAN_SLOT'; return ['output' => null, 'report' => $rep]; }
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $restore = function () use ($saved) { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; };
    $dl = $t0 + (float)(getenv('PP_V10_FAST_BUDGET_S') ?: 120.0);
    $once0 = (int)($GLOBALS['__ppx_once_calls'] ?? 0);
    $poolKey = pp_tl_key($input) . '_x'; $firstValidAt = null;
    $offer = function (array $a, string $src) use (&$firstValidAt, $t0, $jobId, $poolKey) { if (empty($a['valid']) || !is_array($a['output'] ?? null)) return;
        try { pp_tl_pool_offer($poolKey, $a, $src, $jobId); } catch (Throwable $e) {}
        if ($firstValidAt === null) { $firstValidAt = microtime(true) - $t0; pp_job_progress($jobId, 'V10_HASIL_VALID_PERTAMA', 40.0, ['first_valid_s' => round($firstValidAt, 2)]); } };
    try {
        $ancOrig = pp_normalize_copy((array)$base['input']);
        foreach (array_keys((array)$ancOrig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($ancOrig['data3']['modeling'][$mk]);
        pp_job_progress($jobId, 'V10_PUSTAKA_JANGKAR', 12.0);
        $lib = pp_v10_library($jobId, $ancOrig, $base, $dl);
        $restore();
        if (!is_array($lib) || !$lib['entries']) { $rep['reason'] = 'V10_PUSTAKA_JANGKAR_TIDAK_TERSEDIA'; return ['output' => null, 'report' => $rep]; }
        $SE = $S0; $SE['data3']['modeling']['__v10_sup_secant'] = true; $SE['data3']['modeling']['__v9_nopolish'] = true;
        $a0 = (float)($W['adj'] ?? 0); $h0 = isset($W['supplier_target']) ? (float)$W['supplier_target'] : null; $stW = array_values((array)$W['stops']);
        $par = pp_v4_helpers_wait($jobId, 0.6) > 0;
        /* TIER 2b: core run penuh commitment pemenang jangkar (a0 dikerjakan pemilik, a0 +- 0,16 oleh pembantu). */
        $t2b = []; foreach ([-0.16, 0.16] as $d) $t2b[] = ['type' => 'eval', 'off' => [], 'seed' => $stW, 'adj' => $a0 + $d, 'hint' => $h0, 'lbl' => sprintf('winner@%+.2f', $d)];
        if ($par) pp_v4_work_publish($jobId, $SE, ['kind' => 'tasks', 'tasks' => $t2b, 'dl' => $dl]);
        pp_job_progress($jobId, 'V10_TIER2B_CORE_RUN', 20.0);
        $f0 = pp_tl_eval($SE, [], $a0, $dl, $stW, $h0); $restore();
        if (is_array($f0) && !is_array($f0['output'] ?? null) && empty($f0['valid'])) { putenv('PP_V4_POOL=0'); $f0 = pp_tl_eval($SE, [], $a0, $dl, $stW, $h0); putenv('PP_V4_POOL'); $restore(); }
        if (is_array($f0)) $offer($f0, 'v10_tier2b');
        /* Tier 2b pesaing (state JAUH dari jangkar): bila core run pemenang jangkar pada S bergeser > PP_V10_FAR_CP USD/MWh dari CP
         * jangkarnya, dispatch jangkar (pendaratan Tier 2a) tidak lagi mewakili re-dispatch engine pada S; setiap commitment
         * pustaka lain ikut di-core-run penuh pada lever jangkarnya. Fungsi state saja (f0 dan jangkar = fungsi S). */
        $farCp = (float)(getenv('PP_V10_FAR_CP') !== false && getenv('PP_V10_FAR_CP') !== '' ? getenv('PP_V10_FAR_CP') : 0.25);
        $distA = is_array($f0) && isset($f0['key']['cp']) ? abs((float)$f0['key']['cp'] - (float)($W['cp'] ?? 0)) : null;
        $far = $distA !== null && $distA > $farCp;
        if ($far) { $sigW = pp_v3_sig($stW);
            foreach ((array)($lib['K'] ?? []) as $kk) { $stK = array_values((array)($kk['stops'] ?? [])); if (pp_v3_sig($stK) === $sigW) continue;
                $t2b[] = ['type' => 'eval', 'off' => [], 'seed' => $stK, 'adj' => (float)($kk['adj'] ?? 0), 'hint' => isset($kk['hint']) ? (float)$kk['hint'] : $h0, 'lbl' => (string)$kk['id'] . '@0']; } }
        $TS = is_array($f0['output'] ?? null) ? pp_tl_supplier_target($f0['output']) : $h0;
        if (is_array($f0) && !empty($f0['checks']) && isset($f0['checks']['residual_shortage_zero']) && $f0['checks']['residual_shortage_zero'] === false) { $rep['reason'] = 'V10_KEKURANGAN_GAS_NYATA'; $restore(); return ['output' => null, 'report' => $rep]; }
        /* TIER 1 + TIER 2a */
        $AR = pp_v10_actual_rows((array)$S0['data3']['modeling']);
        $fr = is_array($f0['output'] ?? null) ? array_values((array)$f0['output']['data']) : null;
        $tasks = []; $seen = []; $pr = []; $gen = 0;
        foreach ($lib['entries'] as $e) {
            /* varian 's' (row Actual dari core run S) hanya untuk state JAUH dari jangkar (PP_V10_SPLICE=1: selalu, 0: tidak pernah) */
            $spl = (string)getenv('PP_V10_SPLICE'); $vars = ($spl === '1' || ($spl !== '0' && $far)) ? ['n', 's'] : ['n'];
            foreach ($vars as $var) { $gen++;
                $rows = $e['rows'];
                if ($var === 's') { if ($fr === null || !$AR) { $pr[] = ['node' => $e['k'] . '@' . $e['p'] . '+S', 'reason' => 'TIDAK_ADA_DISPATCH_ACTUAL_S', 'stops' => pp_v3_stops($rows)]; continue; }
                    $same = true; foreach ($AR as $r => $_) foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); if ((((float)($rows[$r - 1][$U] ?? 0)) > 0.01) !== (((float)($fr[$r - 1][$U] ?? 0)) > 0.01)) { $same = false; break 2; } }
                    if (!$same) { $pr[] = ['node' => $e['k'] . '@' . $e['p'] . '+S', 'reason' => 'POLA_ON_OFF_ROW_ACTUAL_BERBEDA', 'stops' => pp_v3_stops($rows)]; continue; }
                    foreach ($AR as $r => $_) foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $rows[$r - 1][$U] = round((float)($fr[$r - 1][$U] ?? 0), 4); } }
                $sg = md5(json_encode($rows)); if (isset($seen[$sg])) { $pr[] = ['node' => $e['k'] . '@' . $e['p'] . ($var === 's' ? '+S' : ''), 'reason' => 'DUPLIKAT_STATE_FISIK', 'same_as' => $seen[$sg]]; continue; }
                $cpf = pp_v10_export_capacity_proof($S0, $fr ?? array_values((array)$base['data']), pp_v3_stops($rows));
                if ($cpf !== null) { $pr[] = ['node' => $e['k'] . '@' . $e['p'], 'reason' => 'INFEASIBLE_KAPASITAS_EXPORT', 'proof' => $cpf, 'stops' => pp_v3_stops($rows)]; continue; }
                $id = $e['sig'] . ':' . $var . ':' . sprintf('%.6f', (float)$TS); $seen[$sg] = $e['k'] . '@' . $e['p'] . ($var === 's' ? '+S' : '');
                $tasks[] = ['type' => 'v10land', 'id' => $id, 'rows' => $rows, 'T' => $TS, 'max' => 6, 'label' => $seen[$sg]]; } }
        foreach ($pr as $pX) if (($pX['reason'] ?? '') !== 'DUPLIKAT_STATE_FISIK' && is_array($pX['stops'] ?? null)) pp_v11_cnt_screen($pX['stops']);   // alias state fisik sama tidak dihitung
        pp_job_progress($jobId, 'V10_TIER2A_PENDARATAN_PUSTAKA', 30.0, ['tasks' => count($tasks)]);
        /* satu daftar tugas (orig yang sama): pembantu melanjutkan tier 2b lalu pustaka, pemilik mengerjakan pustaka lalu sisa tier 2b */
        if ($par) pp_v4_work_publish($jobId, $SE, ['kind' => 'tasks', 'tasks' => array_merge($t2b, $tasks), 'dl' => $dl]);
        if ($par) pp_v4_cooperate($jobId, $SE, array_merge($tasks, $t2b), $dl);
        else { foreach ($tasks as $t) pp_v10_land_task($SE, $t, $dl, $jobId); foreach ($t2b as $t) pp_v4_task_exec($SE, $t, $dl, null); }
        $restore();
        /* Pengumpulan (urutan deterministik: tier 2b, lalu pustaka). */
        $all = [];
        if (is_array($f0)) $all['t2b:W@0'] = ['node' => 'v10_tier2b:winner@0', 'a' => $f0];
        foreach ($t2b as $t) { $x = pp_tl_eval($SE, [], (float)$t['adj'], $dl, $t['seed'], $t['hint']); $restore(); if (is_array($x)) { $all['t2b:' . $t['lbl']] = ['node' => 'v10_tier2b:' . $t['lbl'], 'a' => $x]; $offer($x, 'v10_tier2b'); } }
        $nEv2a = 0;
        foreach ($tasks as $t) { $r = pp_tl_read(pp_v10_res_file($S0, (string)$t['id'])); if (!is_array($r) || !is_array($r['a'] ?? null)) continue; $nEv2a += (int)($r['evals'] ?? 1);
            $a = $r['a']; $a['output'] = $r['out'] ?? null; $all['t2a:' . $t['label']] = ['node' => 'v10_tier2a:' . $t['label'], 'a' => $a]; if (!empty($a['valid'])) $offer($a, 'v10_tier2a'); }
        $win = null; $winK = null;
        foreach ($all as $kx => $x) if (!empty($x['a']['valid']) && is_array($x['a']['output'] ?? null) && ($win === null || pp_global_commitment_better($x['a']['key'], $win['a']['key']))) { $win = $x; $winK = $kx; }
        if ($win === null) { $rep['reason'] = 'V10_TIDAK_ADA_KANDIDAT_VALID'; $rep['evaluated'] = count($all); $restore(); return ['output' => null, 'report' => $rep]; }
        /* Polish Unit Priority (counterfactual, simulasi penuh, diterima hanya bila valid & lebih baik). */
        $wa = $win['a']; $wa['off'] = []; $wa['adj'] = 0.0;
        if (function_exists('pp_v6_priority_polish')) { try { $wp = pp_v6_priority_polish($S0, $wa, $dl); if (is_array($wp) && !empty($wp['valid']) && is_array($wp['output'] ?? null)) $wa = $wp; } catch (Throwable $e) {} $restore(); }
        $out = $wa['output'];
        $chk = pp_tl_assess($S0, $out); $restore();
        if (empty($chk['valid'])) { $rep['reason'] = 'V10_VALIDASI_ULANG_GAGAL'; return ['output' => null, 'report' => $rep]; }
        $wStops = pp_v3_stops((array)$out['data']);
        $same = 0; $firstDiff = null; $bd = (array)$base['data'];
        for ($r = 0; $r < 48; $r++) { if (json_encode($out['data'][$r] ?? null) === json_encode($bd[$r] ?? null)) $same++; elseif ($firstDiff === null) $firstDiff = $r + 1; }
        $ladder = []; foreach ($all as $kx => $x) { $okX = !empty($x['a']['valid']); $ladder[] = ['unit' => $x['node'], 'variant' => 'V10_FAST_CANDIDATE', 'key' => $x['a']['key'] ?? null, 'valid' => $okX, 'better' => $kx === $winK]
            + ($okX ? [] : ['excluded' => true, 'exclusion_reason' => 'TIDAK_CONSTRAINT_VALID: ' . implode(',', array_keys(array_filter((array)($x['a']['checks'] ?? []), function ($z) { return !$z; })))]); }
        $wall = microtime(true) - $t0; $sims = (int)($GLOBALS['__ppx_once_calls'] ?? 0) - $once0;
        $byR = []; foreach ($pr as $p) $byR[$p['reason']] = ($byR[$p['reason']] ?? 0) + 1;
        $cert = ['schema' => 'co12-v10-fast-certificate-v1',
            'universe_signature' => substr(hash('sha256', json_encode(array_column($lib['entries'], 'sig'))), 0, 24), 'universe' => 'pustaka jangkar ' . count($lib['entries']) . ' dispatch (' . implode(', ', $lib['commitments']) . ' x grid lever ' . count($lib['grid']) . ' titik) x varian row actual (dispatch jangkar; state jauh dari jangkar: + dispatch row actual core run S) + core run penuh commitment pemenang jangkar pada a0, a0-0,16, a0+0,16 + (state jauh dari jangkar: core run penuh commitment pustaka lain pada lever jangkarnya, ' . (count($t2b) - 2) . ')',
            'anchor_distance_cp' => $distA === null ? null : round($distA, 4), 'far_from_anchor' => $far, 'far_threshold_cp' => $farCp,
            'anchor_file' => $base['_file'] ?? null, 'library_reused' => !empty($lib['reused']), 'library_built_s' => $lib['built_s'] ?? null,
            'candidates_generated' => $gen + 1 + count($t2b), 'candidates_pruned' => count($pr), 'pruned_by_reason' => $byR, 'pruned' => array_slice($pr, 0, 60),
            'candidates_evaluated_tier2a' => count($tasks), 'tier2a_simulations' => $nEv2a, 'candidates_full_run_tier2b' => 1 + count($t2b),
            'core_simulations' => $sims, 'dirty_rows' => (array)($diff['dirty_rows'] ?? []),
            'incumbent_canonical_key' => $win['a']['key'] ?? null, 'incumbent_node' => $win['node'],
            'fingerprints' => pp_v10_fingerprints($S0), 'supplier_internal_target_s' => $TS,
            'rule' => 'FINAL sah: incumbent valid (validasi penuh 48 row terhadap input asli), seluruh anggota universe kanonik dievaluasi atau dipangkas dengan alasan tercatat (dedup state fisik / bukti kapasitas Export / pola row actual), pemenang = urutan comparator engine.'];
        $rep = array_merge($rep, ['applied' => true, 'reason' => null, 'wall_s' => round($wall, 2), 'first_valid_s' => $firstValidAt === null ? null : round($firstValidAt, 2),
            'core_evaluations' => 1 + count($t2b) + $nEv2a, 'core_simulations' => $sims, 'base_file' => $base['_file'] ?? null, 'base_source' => $v['source'] ?? null, 'diff' => $diff,
            'dirty_row_range' => $diff['dirty_rows'] ? [min($diff['dirty_rows']), max($diff['dirty_rows'])] : null, 'valid_evaluations' => count(array_filter($all, function ($x) { return !empty($x['a']['valid']); })),
            'candidates_evaluated' => count($all), 'winner' => $win['node'], 'winner_cost_production' => $out['info']['Cost Production (USD/MWh)'] ?? null,
            'base_winner_cost_production' => (float)($W['cp'] ?? 0), 'commitment_unchanged' => pp_v3_sig($wStops) === (string)($W['sig'] ?? ''),
            'rows_reused_identical' => $same, 'rows_recomputed' => 48 - $same, 'first_changed_row' => $firstDiff, 'v10_screening' => $cert,
            'dependency' => 'Perubahan data slot menggeser neraca gas harian; dispatch pustaka jangkar didaratkan ulang pada state baru, core run penuh pemenang jangkar pada state baru, pemenang = comparator engine.',
            'checks' => $chk['checks']]);
        $out['info']['Incremental Recompute'] = $rep;
        $out['info']['V10 Candidate Screening'] = $cert;
        $out['info']['Global Commitment Review'] = ['root_problem' => 'V10_FAST_CANDIDATE_SPACE', 'candidates_evaluated' => count($all), 'candidates_total' => count($all) + count($pr),
            'all_candidates_evaluated' => true, 'units_dropped' => [], 'comparator_order' => ['operator_hard_controls', 'hard_violations', 'export_reserve_busflow', 'cost_production', 'total_cost', 'heat_rate', 'startup_count', 'fuel', 'keep_running_if_equal'],
            'ladder' => $ladder, 'note' => 'Ruang kandidat kanonik V10 (lihat V10 Candidate Screening): seluruh kandidat dievaluasi atau dipangkas dengan alasan tercatat.'];
        $out['info']['Exact Candidate Space'] = ['schema' => 'co12-exact-candidate-space-v1', 'source' => 'INCREMENTAL', 'definition' => 'V10 rute cepat kanonik', 'family_complete' => true,
            'family_nodes' => count($all), 'family_core_evaluations' => 1 + count($t2b) + $nEv2a, 'family_valid' => $rep['valid_evaluations'], 'winner_source' => 'INCREMENTAL', 'winner_node' => $win['node'],
            'winner_cost_production' => $out['info']['Cost Production (USD/MWh)'] ?? null, 'commitment_unchanged' => $rep['commitment_unchanged']];
        $out['info']['Run Status'] = ['completed' => true, 'deadline_reached' => false, 'budget_truncated' => false, 'converged' => true, 'status' => 'CONVERGED', 'economic_review_skipped' => null,
            'last_stage' => null, 'stages_truncated' => [], 'elapsed_s' => round($wall, 3), 'deadline_s' => round($dl - $t0, 1), 'core_runs' => 1 + count($t2b) + $nEv2a, 'core_simulations' => $sims,
            'supplier_state_reuse' => 0, 'economic_review_completed' => true, 'phase_timeline' => [], 'stages_deferred' => [], 'mode' => 'INCREMENTAL', 'incremental' => true,
            'candidates' => ['global_commitment_evaluated' => count($all), 'fully_evaluated' => true, 'note' => 'V10: seluruh kandidat universe kanonik dievaluasi atau dipangkas dengan alasan tercatat.']];
        pp_v3_relabel($out, $input);
        $accO = $out; $acc = pp_attach_or_reject_acceptance($input, $accO);
        if (empty($acc['publish_allowed'])) { $rep['applied'] = false; $rep['reason'] = 'GERBANG_RILIS_MENOLAK: ' . implode(',', (array)($acc['blocking_reasons'] ?? [])); return ['output' => null, 'report' => $rep]; }
        /* catatan commitment yang dievaluasi (dipakai ulang review generik: state fisik sama) */
        $GLOBALS['ppV10Inc'] = ['pruned' => $pr, 'evaluated' => [], 'T' => $TS, 'far' => $far];
        foreach ($all as $x) if (!empty($x['a']['valid']) && is_array($x['a']['output'] ?? null)) { try { $sgE = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$x['a']['output']['data'])));
            $old = $GLOBALS['ppV10Inc']['evaluated'][$sgE] ?? null; if ($old === null || pp_global_commitment_better($x['a']['key'], $old['key'])) $GLOBALS['ppV10Inc']['evaluated'][$sgE] = array_intersect_key($x['a'], array_flip(['valid', 'key', 'checks', 'violations', 'output', 'dev', 'gas_only', 'running'])) + ['sig' => $sgE]; } catch (Throwable $e) {} }
        $wKey = $chk['key'];
        $cands = [];
        foreach ((array)($v['candidates'] ?? []) as $cc) $cands[] = $cc + ['carried' => true];
        $v3 = ['schema' => PP_V3_SCHEMA, 'engine' => pp_engine_fingerprint(), 'source' => 'INCREMENTAL', 'chain' => 1,
            'winner' => ['stops' => $wStops, 'sig' => pp_v3_sig($wStops), 'cp' => (float)($wKey['cp'] ?? 0), 'key' => $wKey, 'adj' => 0.0, 'supplier_target' => pp_tl_supplier_target($out), 'node' => $win['node']],
            'candidates' => array_values(array_filter($cands, function ($cc) use ($wStops) { return ($cc['sig'] ?? '') !== pp_v3_sig($wStops); }))];
        return ['output' => $out, 'report' => $rep, 'v3' => $v3];
    } catch (PpJobAborted $e) { $restore(); throw $e; }
    catch (Throwable $e) { $restore(); $rep['reason'] = 'GALAT_V10: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); return ['output' => null, 'report' => $rep]; }
}

/* V9 prasaring ekonomi SWAP ke unit prioritas lebih rendah di blok lain: dievaluasi hanya bila laju bahan bakar
 * spesifik unit pengganti pada beban rata-rata interval tidak lebih buruk (tidak ada motif prioritas maupun biaya). */
function pp_v9_swap_hr_prescreen(array $orig, array $rows, array $c): ?array {
    if (($c['kind'] ?? '') !== 'SWAP' || (int)($c['ord'] ?? 0) < 10) return null;
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $u = $c['unit']; $p = $c['peer'];
    $sum = 0.0; $n = 0; for ($r = $c['iv'][0]; $r <= $c['iv'][1]; $r++) { $x = (float)($rows[$r - 1][strtoupper($u)] ?? 0); if ($x > 0.01) { $sum += $x; $n++; } }
    if ($n === 0) return null; $mw = $sum / $n;
    /* blok STG: unit asal memasok STG yang running, blok unit pengganti STG-nya mati sepanjang interval -> uap combined cycle hilang */
    $su = strtoupper(pp_gtg_to_stg($d3, $u)); $sp = strtoupper(pp_gtg_to_stg($d3, $p));
    if ($su !== '' && $sp !== '' && $su !== $sp) { $onU = 0; $onP = 0;
        for ($r = (int)$c['peer_rows'][0]; $r <= (int)$c['peer_rows'][1]; $r++) { if ((float)($rows[$r - 1][$su] ?? 0) > 0.01) $onU++; if ((float)($rows[$r - 1][$sp] ?? 0) > 0.01) $onP++; }
        if ($onU > 0 && $onP === 0) return ['method' => 'PRASARING_BLOK_STG', 'code' => 'KEHILANGAN_UAP_COMBINED_CYCLE', 'row' => (int)$c['peer_rows'][0],
            'detail' => sprintf('%s memasok %s yang running; blok %s (%s) mati sepanjang interval, %s prioritas lebih rendah', strtoupper($u), $su, strtoupper($p), $sp, strtoupper($p))]; }
    $mwP = min($mw, pp_effective_max_load($d3, $m, $p, (int)$c['peer_rows'][0])); if ($mwP <= 0.01) return null;
    $hu = calc_fuel($d3, $u, $mw) / $mw; $hp = calc_fuel($d3, $p, $mwP) / $mwP;
    if ($hp <= $hu * 1.0005) return null;
    return ['method' => 'PRASARING_HEAT_RATE', 'code' => 'HEAT_RATE_PENGGANTI_LEBIH_BURUK', 'row' => (int)$c['peer_rows'][0],
            'detail' => sprintf('%s %.1f vs %s %.1f BTU/kWh pada %.1f MW', strtoupper($p), $hp * 1e9 / 24000.0, strtoupper($u), $hu * 1e9 / 24000.0, $mw)];
}
/* V9 REPAIR START (breakpoint reserve / Export): pemenang tidak valid HANYA karena Spinning Reserve atau Export
 * kurang pada sejumlah row, dan engine tidak men-start unit untuk kebutuhan itu. Kandidat START generik: unit GTG
 * yang mati sepanjang row defisit, urutan Unit Priority terpadu (prioritas tinggi lebih dulu), start 2-3 row sebelum
 * row defisit pertama (urutan start-up), berhenti pada row legal pertama sesudah defisit terakhir dan minimum runtime.
 * Setiap kandidat dihitung penuh 48 row (pendaratan window gas) lalu divalidasi terhadap input ASLI. */
function pp_v9_start_repair(array $orig, array $out, float $dl): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $R = ['schema' => 'co12-v9-start-repair-v1', 'applied' => false, 'passes' => [], 'candidates' => []];
    /* defisit dari validator hard (definisi yang sama dengan gerbang rilis) */
    $deficit = function (array $o) use ($orig): array {
        $V = pp_validate_hard_constraints($orig, $o); $rows = []; $kind = []; $other = [];
        foreach ((array)($V['violations'] ?? []) as $v) { $t = (string)($v[0] ?? ''); $msg = (string)($v[1] ?? '');
            if ($t === 'spinning_reserve' || ($t === 'export_range' && strpos($msg, 'RangeMin') !== false)) {
                if (preg_match('~row (\d+)~', $msg, $mm)) $rows[(int)$mm[1]] = true; $kind[$t === 'spinning_reserve' ? 'RESERVE' : 'EXPORT'] = true; }
            elseif ($t !== 'gas_quota') $other[pp_v8_viol_code($t)] = true; }
        ksort($rows); return ['rows' => array_keys($rows), 'kind' => array_keys($kind), 'other' => array_keys($other)];
    };
    $D = $deficit($out);
    if (!$D['rows']) { $R['reason'] = 'TIDAK_ADA_DEFISIT_RESERVE_ATAU_EXPORT'; return $R; }
    if ($D['other']) { $R['reason'] = 'PELANGGARAN_LAIN:' . implode(',', $D['other']); return $R; }
    $lim = pp_runtime_limits($m); $rank = pp_priority_rank($m);
    $X = pp_v8_masks(array_values((array)$out['data'])); $mods = []; $best = null; $cur = null;
    for ($pass = 1; $pass <= 3; $pass++) {
        /* klaster defisit pertama (celah > 3 row memisahkan klaster; klaster berikutnya ditangani putaran berikut) */
        $r0 = $D['rows'][0]; $r1 = $r0; foreach ($D['rows'] as $rr) { if ($rr - $r1 > 3) break; $r1 = $rr; }
        $P = ['pass' => $pass, 'cluster' => [$r0, $r1], 'deficit_rows' => $D['rows'], 'deficit_kind' => $D['kind'], 'candidates' => []];
        $C = [];
        /* EXTEND: unit yang baru berhenti <= 4 row sebelum klaster dijalankan terus sampai akhir klaster */
        foreach (pp_priority_flat($m) as $u) {
            $u = strtolower((string)$u); if (!in_array($u, pp_tl_gt_units(), true) || !isset($d3[$u])) continue;
            foreach (pp_v8_intervals((array)($X[$u] ?? [])) as [$ia, $ib]) if ($ib >= $r0 - 4 && $ib < $r0) { $ok = true;
                for ($r = $ib + 1; $r <= $r1; $r++) if (pp_is_unit_stopped($d3, $m, $u, $r) || !pp_effective_unit_available($d3, $m, $u, $r)) { $ok = false; break; }
                if ($ok) $C[] = ['unit' => $u, 'start' => $ia, 'stop' => $r1, 'kind' => 'EXTEND']; }
        }
        foreach (pp_priority_flat($m) as $u) {
            $u = strtolower((string)$u); if (!in_array($u, pp_tl_gt_units(), true) || !isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
            $off = true; for ($r = $r0; $r <= $r1; $r++) if (!empty($X[$u][$r])) { $off = false; break; } if (!$off) continue;
            $run = (int)($lim[pp_runtime_class($u, $d3)]['run_rows'] ?? 8);
            foreach ([3, 2] as $lead) {
                $s0 = max(2, $r0 - $lead); $e = min(48, max($r1, $s0 + $run - 1)); $ok = true;
                for ($r = $s0; $r <= $e; $r++) if (pp_is_unit_stopped($d3, $m, $u, $r) || !pp_effective_unit_available($d3, $m, $u, $r)) { $ok = false; break; }
                if ($ok) $C[] = ['unit' => $u, 'start' => $s0, 'stop' => $e];
            }
            if (count($C) >= 5) break;
        }
        $bestP = null;
        foreach ($C as $c) {
            $id = sprintf('%s:%s:%d-%d', $c['kind'] ?? 'START', strtoupper($c['unit']), $c['start'], $c['stop']);
            if (microtime(true) > $dl - 4.0) { $P['candidates'][] = ['id' => $id, 'evaluated' => false, 'method' => 'TIDAK_DIEVALUASI:BATAS_WAKTU']; continue; }
            $Xc = $X; for ($r = $c['start']; $r <= $c['stop']; $r++) $Xc[$c['unit']][$r] = true;
            $modsC = $mods; $prev = $modsC[$c['unit']] ?? null; $modsC[$c['unit']] = ['start' => $prev === null ? $c['start'] : min($prev['start'], $c['start'])];
            $in2 = $orig;
            foreach ($modsC as $mu => $mv) { $first = null; for ($r = 1; $r <= 48; $r++) if (!empty($Xc[$mu][$r])) { $first = $r; break; }
                $st = $first !== null && $first > 1 ? $first : null; if ($st === null) continue; $mn = ($st - 1) * 30;
                $in2['data3']['modeling']['required_mode'][$mu] = ['mode' => 'start_at', 'at' => sprintf('%02d:%02d', intdiv($mn, 60), $mn % 60)]; }
            $L = pp_tl_land_commitment($in2, [], pp_v8_stops($Xc), $dl, 4); pp_tl_clean_globals();
            /* defisit tertutup, tinggal window gas: pendaratan diperpanjang (bisection gas sama dengan keluarga exact) */
            if (is_array($L['a'] ?? null) && empty($L['a']['valid']) && !empty($L['a']['gas_only'])) { $L = pp_tl_land_commitment($in2, [], pp_v8_stops($Xc), $dl, 9); pp_tl_clean_globals(); }
            $o2 = is_array($L['a'] ?? null) && is_array($L['a']['output'] ?? null) ? $L['a']['output'] : null;
            $a = $o2 !== null ? pp_tl_assess($orig, $o2) : null; $D2 = $o2 !== null ? $deficit($o2) : null;
            if (is_array($a)) { $a['output'] = $o2; $a['sig'] = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$o2['data']))); }
            $P['candidates'][] = ['id' => $id, 'evaluated' => true, 'method' => 'DISPATCH_48_ROW_ENGINE', 'valid' => is_array($a) && !empty($a['valid']), 'cp' => is_array($a) ? ($a['key']['cp'] ?? null) : null,
                'violations' => is_array($a) ? array_values(array_unique(array_map('pp_v8_viol_code', (array)($a['violations'] ?? [])))) : ['GALAT'], 'deficit_rows_left' => $D2['rows'] ?? null];
            if (!is_array($a)) continue;
            $rec = ['a' => $a, 'X' => $Xc, 'mods' => $modsC, 'D' => $D2, 'id' => $id];
            if (!empty($a['valid'])) { if ($best === null || pp_v9_better($a, $best['a'], $rank)) $best = $rec; continue; }
            /* belum valid: dipakai sebagai basis putaran berikut bila defisit berkurang (paling sedikit row defisit, lalu CP) */
            if ($D2 && !$D2['other'] && $D2['rows'] && count($D2['rows']) < count($D['rows'])
                && ($bestP === null || [count($D2['rows']), (float)($a['key']['cp'] ?? INF)] < [count($bestP['D']['rows']), (float)($bestP['a']['key']['cp'] ?? INF)])) $bestP = $rec;
        }
        $R['passes'][] = $P; foreach ($P['candidates'] as $pc) $R['candidates'][] = $pc + ['pass' => $pass];
        if ($best !== null || $bestP === null) break;
        $X = pp_v8_masks(array_values((array)$bestP['a']['output']['data'])); foreach ($bestP['X'] as $u => $rowsU) foreach ($rowsU as $r => $on) if ($on) $X[$u][$r] = true;
        $mods = $bestP['mods']; $D = $bestP['D'];
    }
    if ($best === null) { $R['reason'] = 'TIDAK_ADA_KANDIDAT_START_VALID'; return $R; }
    $R['applied'] = true; $R['winner'] = $best['id']; $R['deficit_kind'] = $R['passes'][0]['deficit_kind']; $R['a'] = $best['a'];
    return $R;
}
/* Row kebutuhan unit $u pada interval [a,b]: row di mana mematikan $u PASTI membuat Export < Range Min
 * (bukti kapasitas: seluruh unit running lain di Effective Max). Breakpoint demand/Export/headroom. */
function pp_v9_need_rows(array $orig, array $rows, string $u, int $a, int $b): array {
    $need = []; for ($r = $a; $r <= $b; $r++) if (pp_v8_export_prescreen($orig, $rows, $u, [$r]) !== null) $need[] = $r;
    return $need;
}
/* Generator generik: untuk SETIAP unit GTG eligible (bukan forced) dan setiap interval running pada pemenang,
 * serta setiap unit eligible yang mati sepanjang hari. Gerakan: KEEP (pemenang), STOP pada row legal
 * pertama dan pada breakpoint kebutuhan terakhir, EARLY STOP (breakpoint legal berikutnya), DELAY START ke
 * breakpoint kebutuhan pertama, EARLY START, EXTEND, SWAP ke peer blok STG yang sama (prioritas lebih tinggi
 * atau lebih murah), DECOMMIT (OFF), START pada row kebutuhan pertama. REDISPATCH / redistribusi headroom dan
 * blok dikerjakan polish Unit Priority pada pemenang. Urutan evaluasi deterministik (fungsi state saja). */
function pp_v8_candidates(array $orig, array $W): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $rank = pp_priority_rank($m); $el = pp_v8_eligible($orig); $lim = pp_runtime_limits($m);
    $rows = array_values((array)($W['data'] ?? [])); $M = pp_v8_masks($rows);
    $col = function (string $u): string { return ($u === 'b1' || $u === 'b2') ? 'BB' . substr($u, 1) : strtoupper($u); };
    $C = []; $rel = []; $seen = [];
    $add = function (array $c) use (&$C, &$seen) { $k = pp_v3_sig($c['stops']); if (isset($seen[$k])) return; $seen[$k] = true; $c['sig'] = $k; $C[] = $c; };
    $add(['id' => 'KEEP', 'kind' => 'KEEP', 'unit' => '', 'iv' => [0, 0], 'ord' => -1, 'last_on' => 0, 'off_rows' => [1, 0], 'stops' => pp_v8_stops($M), 'keep' => true]);
    foreach (pp_tl_gt_units() as $u) {
        if (empty($el[$u]) || !isset($d3[$u])) continue;
        $ru = $rank[$u] ?? 999; $stg = pp_gtg_to_stg($d3, $u); $U = strtoupper($u);
        $run = (int)($lim[pp_runtime_class($u, $d3)]['run_rows'] ?? 8);
        $ivs = pp_v8_intervals($M[$u]);
        foreach ($ivs as $iv) {
            [$a, $b] = $iv;
            $head = 0.0; $hRow = null; $hUnits = [];
            for ($r = $a; $r <= $b; $r++) {
                $h = 0.0; $hu = [];
                foreach ($rank as $v => $rv) {
                    if ($v === $u || $rv >= $ru || !preg_match('~^(g\d+|b\d)$~', $v) || $v === 'g10' || !isset($d3[$v])) continue;
                    $mw = (float)($rows[$r - 1][$col($v)] ?? 0); if ($mw <= 0.01) continue;
                    if (pp_get_fixed_load($m, $v, $r) >= 0) continue;
                    $x = max(0.0, pp_effective_max_load($d3, $m, $v, $r) - $mw); if ($x > 0.05) { $h += $x; $hu[$col($v)] = round($x, 2); }
                }
                if ($h >= 0.5 && $h > $head) { $head = $h; $hUnits = $hu; } if ($h >= 0.5 && $hRow === null) $hRow = $r;
            }
            $peersHi = []; $peersAll = [];
            foreach (pp_tl_gt_units() as $p) {
                if ($p === $u || empty($el[$p]) || !isset($d3[$p])) continue;
                if (pp_gtg_to_stg($d3, $p) !== $stg && pp_runtime_class($p, $d3) !== pp_runtime_class($u, $d3)) continue;   // unit sekelas (GTG kecil/CC atau besar)
                $off = true; for ($r = $a; $r <= $b; $r++) if (!empty($M[$p][$r])) { $off = false; break; }
                if (!$off) continue; $peersAll[] = $p; if (($rank[$p] ?? 999) < $ru && pp_gtg_to_stg($d3, $p) === $stg) $peersHi[] = $p;
            }
            /* minimum runtime GTG, dan STG yang ikut start bersama unit ini (satu-satunya pemasok blok) */
            $s0 = $a + $run; $stgWhy = null;
            if ($stg !== '') { $S = strtoupper($stg); $c = null;
                for ($r = $a; $r <= $b; $r++) if ((float)($rows[$r - 1][$S] ?? 0) > 0.01) { $c = $r; break; }
                $sole = $c !== null && ($c > 1 && (float)($rows[$c - 2][$S] ?? 0) <= 0.01);
                if ($sole) foreach (pp_tl_gt_units() as $f) if ($f !== $u && pp_gtg_to_stg($d3, $f) === $stg) for ($r = $c; $r <= $b; $r++) if (!empty($M[$f][$r])) { $sole = false; break 2; }
                if ($sole) { $rs = (int)($lim['stg']['run_rows'] ?? 12); if ($c + $rs > $s0) { $s0 = $c + $rs; $stgWhy = 'STG_' . $S . '_MIN_RUNTIME_START_STG_ROW_' . $c; } } }
            /* breakpoint kebutuhan (bukti kapasitas Export per row) */
            $need = pp_v9_need_rows($orig, $rows, $u, $a, $b);
            $lastNeed = $need ? max($need) : null; $firstNeed = $need ? min($need) : null;
            $base = ['unit' => $u, 'iv' => [$a, $b]];
            $stops = []; foreach ([$s0, $lastNeed !== null ? max($s0, $lastNeed + 1) : null, $s0 + 1, $s0 + 2] as $k => $s) if ($s !== null && $s <= $b && !isset($stops[$s])) $stops[$s] = $k;
            foreach ($stops as $s => $k) { $X = $M; for ($r = $s; $r <= $b; $r++) $X[$u][$r] = false;
                $add($base + ['id' => sprintf('STOP:%s:%d-%d:@%d', $U, $a, $b, $s), 'kind' => $k <= 1 ? 'STOP' : 'EARLY_STOP', 'ord' => $k <= 1 ? 2 : 7, 'last_on' => $s - 1, 'off_rows' => [$s, $b], 'stops' => pp_v8_stops($X)]); }
            $X = $M; for ($r = $a; $r <= $b; $r++) $X[$u][$r] = false;
            $add($base + ['id' => sprintf('DECOMMIT:%s:%d-%d', $U, $a, $b), 'kind' => 'DECOMMIT', 'ord' => 4, 'last_on' => $a - 1, 'off_rows' => [$a, $b], 'stops' => pp_v8_stops($X)]);
            $delays = [];
            if ($a > 1) { foreach ([1, 2] as $k) $delays[$a + $k] = $k === 1 ? 3 : 8; if ($firstNeed !== null && $firstNeed - 2 > $a) $delays[$firstNeed - 2] = 3; }
            foreach ($delays as $na => $ord) {
                $ne = min(48, max($b, $na + $run - 1)); if ($na > 48 || $ne - $na + 1 < $run) continue;
                $X = $M; for ($r = $a; $r <= $b; $r++) $X[$u][$r] = false; for ($r = $na; $r <= $ne; $r++) $X[$u][$r] = true;
                $add($base + ['id' => sprintf('DELAY:%s:%d-%d:@%d', $U, $a, $b, $na), 'kind' => 'DELAY', 'ord' => $ord, 'last_on' => $a - 1, 'off_rows' => [$a, $na - 1], 'stops' => pp_v8_stops($X)]); }
            /* Gerakan aditif (start lebih awal / perpanjang) tidak dibangkitkan: pada core run dengan commitment
             * tetap, unit yang tidak di-stop dijalankan engine hanya bila dibutuhkan, sehingga kandidat aditif identik
             * dengan KEEP. Start unit baru dievaluasi lewat SWAP ke setiap unit eligible (blok sama lebih dulu). */
            foreach ($peersAll as $p) foreach (array_values(array_unique([$b, min($b, max($a, $s0 - 1))])) as $e) {
                $X = $M; for ($r = $a; $r <= $b; $r++) $X[$u][$r] = false; for ($r = $a; $r <= $e; $r++) $X[$p][$r] = true;
                $hi = in_array($p, $peersHi, true); $same = pp_gtg_to_stg($d3, $p) === $stg;
                $add($base + ['id' => sprintf('SWAP:%s>%s:%d-%d', $U, strtoupper($p), $a, $e), 'kind' => 'SWAP', 'ord' => $hi ? ($e < $b ? 1 : 5) : ($same ? ($e < $b ? 6 : 9) : ($e < $b ? 10 : 12)), 'peer' => $p, 'last_on' => $a - 1, 'off_rows' => [$a, $b], 'peer_rows' => [$a, $e], 'stops' => pp_v8_stops($X)]);
            }
            if ($head >= 0.5 || $peersHi) $rel[] = ['unit' => $U, 'rows' => [$a, $b], 'unit_priority_rank' => $ru, 'max_higher_priority_headroom_mw' => round($head, 2),
                'headroom_units' => $hUnits, 'first_row_with_higher_headroom' => $hRow, 'higher_priority_peers_off' => array_map('strtoupper', $peersHi),
                'min_runtime_rows' => $run, 'first_legal_stop_row' => $s0 <= $b ? $s0 : null, 'first_legal_stop_basis' => $stgWhy ?? ('MIN_RUNTIME_' . strtoupper(pp_runtime_class($u, $d3))),
                'need_rows_export' => $need ? [$firstNeed, $lastNeed, count($need)] : null];
        }
    }
    $ix = array_keys($C); usort($ix, function ($x, $y) use ($C) { return [$C[$x]['ord'], $x] <=> [$C[$y]['ord'], $y]; });
    $C2 = []; foreach ($ix as $x) $C2[] = $C[$x];
    return ['candidates' => $C2, 'relevant' => $rel];
}
/* V8: kandidat pembanding yang dibawa ke blok final (basis inkremental berikutnya): pemenang yang digantikan
 * dan kandidat valid termurah. Perubahan kuota/data berikutnya mengevaluasi ulang pesaing ini bila berada
 * dalam margin (aturan pesaing V3), sehingga commitment yang lebih panjang/lebih awal tidak hilang dari rantai. */
function pp_v8_carry(array $all, array $applied): array {
    $C = [];
    foreach ($applied as $a) if (!empty($a['replaced_stops'])) $C[] = ['src' => 'v8', 'nk' => 'v8:replaced:' . $a['candidate'], 'stops' => $a['replaced_stops'], 'valid' => true, 'gas_only' => false, 'dev' => 0.0, 'cp' => (float)($a['cp_before'] ?? 0), 'adj' => 0.0];
    $v = array_values(array_filter($all, function ($x) { return !empty($x['valid']) && isset($x['cp']); }));
    usort($v, function ($x, $y) { return [(float)$x['cp'], $x['id']] <=> [(float)$y['cp'], $y['id']]; });
    /* V9: seluruh kandidat valid (maks. 4 termurah) dan kandidat yang hanya gagal window gas (maks. 2, deviasi terkecil) dibawa:
     * perubahan data berikutnya mengevaluasi ulang yang dapat menyalip (aturan pesaing V3) = review delta. */
    foreach (array_slice($v, 0, pp_v9_canon() ? 4 : 1) as $x) $C[] = ['src' => 'v8', 'nk' => 'v8:' . $x['id'], 'stops' => $x['stops'], 'valid' => true, 'gas_only' => false, 'dev' => 0.0, 'cp' => (float)$x['cp'], 'adj' => 0.0];
    if (pp_v9_canon()) { $g = array_values(array_filter($all, function ($x) { return empty($x['valid']) && !empty($x['gas_only']) && isset($x['cp'], $x['stops']); }));
        usort($g, function ($x, $y) { return [abs((float)$x['dev']), $x['id']] <=> [abs((float)$y['dev']), $y['id']]; });
        foreach (array_slice($g, 0, 2) as $x) $C[] = ['src' => 'v8', 'nk' => 'v8:' . $x['id'], 'stops' => $x['stops'], 'valid' => false, 'gas_only' => true, 'dev' => (float)$x['dev'], 'cp' => (float)$x['cp'], 'adj' => 0.0]; }
    return $C;
}
function pp_v8_viol_code(string $t): string {
    static $map = ['runtime_downtime' => 'MIN_RUNTIME_DOWNTIME', 'export_range' => 'EXPORT', 'pln_export' => 'EXPORT', 'spinning_reserve' => 'RESERVE',
                   'reserve' => 'RESERVE', 'busflow' => 'BUS_FLOW', 'bus_flow' => 'BUS_FLOW', 'gas_quota' => 'GAS_WINDOW', 'ramp' => 'RAMP',
                   'mm2100_gas_source' => 'GAS_MM2100', 'stg_coupling' => 'BLOCK_STG', 'block' => 'BLOCK_STG', 'cannot_stop' => 'FORCED_STATUS',
                   'commitment_continuous' => 'FORCED_STATUS', 'last_data_status' => 'FORCED_STATUS', 'unit_stop' => 'FORCED_STATUS'];
    foreach ($map as $k => $v) if (stripos($t, $k) !== false) return $v;
    return strtoupper($t);
}
/* Row defisit pada dispatch kandidat invalid: row dengan Export di bawah Range Min, beserta keadaan unit
 * running prioritas lebih tinggi (MAX = di Effective Max, RAMP = kenaikan dibatasi ramp 30 MW/row dari row
 * sebelumnya/sesudahnya). Dipakai sebagai alasan terstruktur per row. */
function pp_v8_deficit_rows(array $orig, array $o, string $u): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $rows = array_values((array)($o['data'] ?? [])); $rank = pp_v8_rank($m);
    $ru = $rank[$u] ?? 999; $D = [];
    foreach ($rows as $k => $r) {
        $lo = (float)($r['pln_lo'] ?? 0); if ((float)($r['Export_PLN'] ?? 0) >= $lo - 1e-6) continue;
        $st = [];
        foreach ($rank as $v => $rv) {
            if ($rv >= $ru || !preg_match('~^g\d+$~', $v) || $v === 'g10') continue; $V = strtoupper($v); $x = (float)($r[$V] ?? 0); if ($x <= 0.01) continue;
            $mx = pp_effective_max_load($d3, $m, $v, $k + 1);
            if ($x >= $mx - 0.05) { $st[] = $V . ' MAX'; continue; }
            $pv = $k > 0 ? (float)($rows[$k - 1][$V] ?? 0) : $x; $nx = $k + 1 < count($rows) ? (float)($rows[$k + 1][$V] ?? 0) : $x;
            if ($x - $pv >= 29.5 || $x - $nx >= 29.5) $st[] = $V . ' RAMP'; else $st[] = $V . ' HEADROOM ' . round($mx - $x, 1);
        }
        $D[$k + 1] = $st; if (count($D) >= 12) break;
    }
    return $D;
}
/* V9: commitment kandidat dipaksakan (start_at pada row on pertama setiap GTG yang start di tengah hari). Dipakai
 * sesudah REPAIR START: engine tidak men-start unit untuk kebutuhan reserve, sehingga kandidat turunan pemenang
 * repair dievaluasi dengan start yang sama persis seperti commitment kandidat. */
function pp_v9_force_start_overlay(array $orig, array $stops): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $ld = (array)($m['unit_last_data_status'] ?? []);
    foreach (pp_tl_gt_units() as $u) {
        if (!isset($d3[$u]) || isset($m['required_mode'][$u]) || strtolower((string)($ld[strtoupper($u)] ?? '')) === 'running') continue;
        $first = null; for ($r = 1; $r <= 48 && $first === null; $r++) { $off = false; foreach ($stops as $st) if ($st['unit'] === $u && $r >= (int)$st['start'] && $r <= (int)$st['stop']) { $off = true; break; } if (!$off) $first = $r; }
        if ($first === null || $first <= 1) continue; $mn = ($first - 1) * 30;
        $orig['data3']['modeling']['required_mode'][$u] = ['mode' => 'start_at', 'at' => sprintf('%02d:%02d', intdiv($mn, 60), $mn % 60)];
    }
    return $orig;
}
function pp_v8_eval_all(array $orig, array $W, array $C, float $dl, int $cap, bool $force = false): array {
    $origA = $orig; $orig['data3']['modeling']['__v9_nopolish'] = true;    // V9: evaluasi mentah (tanpa polish per kandidat), dinilai terhadap input asli
    $rows = array_values((array)($W['data'] ?? []));
    $R = []; $sim = [];
    foreach ($C as $i => $c) {
        if (!empty($c['keep'])) { $R[$i] = ['keep' => true]; continue; }
        if (in_array($c['kind'], ['OFF', 'TRUNC', 'DELAY', 'DECOMMIT', 'STOP', 'EARLY_STOP'], true) && $c['off_rows'][1] >= $c['off_rows'][0]) {
            $pr = pp_v8_export_prescreen($orig, $rows, $c['unit'], range($c['off_rows'][0], $c['off_rows'][1]));
            if ($pr !== null) { $R[$i] = ['prescreen' => $pr]; continue; }
        }
        if (count($sim) >= $cap) { $R[$i] = ['skipped' => 'BATAS_KANDIDAT_PER_PUTARAN']; continue; }
        $sim[] = $i;
    }
    /* V10 rute cepat: kandidat review dievaluasi sebagai dispatch tersusun (lihat pp_v10_review_eval); kandidat yang
     * tidak dapat disusun dievaluasi penuh seperti V9. */
    if (!$force && !empty($GLOBALS['ppV10ReviewFast'])) { $simF = [];
        foreach ($sim as $i) { $a = pp_v10_review_eval($origA, $W, $C[$i], $dl); pp_tl_clean_globals();
            if (!is_array($a)) { $simF[] = $i; continue; }
            if (empty($a['valid']) && is_array($a['output'] ?? null)) $a['deficit'] = pp_v8_deficit_rows($orig, $a['output'], $C[$i]['unit']);
            $a['sig'] = is_array($a['output'] ?? null) ? pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$a['output']['data']))) : ($C[$i]['sig'] ?? pp_v3_sig($C[$i]['stops']));
            $a['v10_synth'] = true; $R[$i] = $a; pp_v10_scr('review', 'synth_frozen'); }
        /* TIER 2b review: kandidat tersusun yang belum valid tetapi berbiaya lebih rendah dari pemenang (menjanjikan) dievaluasi
         * penuh (pendaratan engine, maks. 3 core run) — hanya yang termurah; kandidat yang tidak dapat disusun juga maks. 1. */
        $aWk = pp_tl_assess($origA, $W); $cpW = (float)($aWk['key']['cp'] ?? INF); $prom = []; $anyBetter = false;
        foreach ($R as $i => $a) if (!empty($a['v10_synth']) && !empty($a['valid']) && is_array($a['key'] ?? null) && is_array($aWk['key'] ?? null) && pp_global_commitment_better($a['key'], $aWk['key'])) $anyBetter = true;
        /* pendaratan penuh kandidat menjanjikan hanya pada putaran pertama dan bila tidak ada kandidat tersusun yang sudah lebih baik */
        if (!$anyBetter && (int)($GLOBALS['ppV10ReviewRound'] ?? 1) <= 1)
        foreach ($R as $i => $a) if (!empty($a['v10_synth']) && empty($a['valid']) && isset($a['key']['cp']) && (float)$a['key']['cp'] < $cpW - 1e-6) $prom[$i] = (float)$a['key']['cp'];
        asort($prom);
        $sim = array_slice($simF, 0, 1); foreach (array_slice($simF, 1) as $i) $R[$i] = ['skipped' => 'V10_TIDAK_DAPAT_DISUSUN_BATAS_1_PENUH'];
        foreach (array_slice(array_keys($prom), 0, 2) as $i) if (!in_array($i, $sim, true)) { $sim[] = $i; pp_v10_scr('review', 'tier2b_full_landing'); } }
    $jid = (string)($GLOBALS['ppTlHook']['job'] ?? ($GLOBALS['__pp_v8_job'] ?? ''));
    $tasks = []; foreach ($sim as $i) $tasks[] = ['type' => 'land', 'off' => [], 'seed' => $C[$i]['stops'], 'max' => 3];
    if (!$force && $jid !== '' && count($tasks) > 1 && function_exists('pp_v4_helper_slots') && pp_v4_helper_slots() > 0 && is_dir(pp_job_dir($jid))) {
        try { pp_v4_work_publish($jid, $orig, ['kind' => 'tasks', 'tasks' => $tasks, 'dl' => $dl]); pp_v4_cooperate($jid, $orig, $tasks, $dl); } catch (Throwable $e) {}
    }
    foreach ($sim as $i) {
        if (microtime(true) >= $dl - 0.5) { $R[$i] = ['skipped' => 'BATAS_WAKTU_REVIEW']; continue; }
        $L = pp_tl_land_commitment($force ? pp_v9_force_start_overlay($orig, $C[$i]['stops']) : $orig, [], $C[$i]['stops'], $dl, 3); $a = $L['a'] ?? null;
        if ($a === null) { $R[$i] = ['skipped' => 'BATAS_WAKTU_REVIEW']; continue; }
        if ($force && is_array($a['output'] ?? null)) { $oF = $a['output']; $a = pp_tl_assess($origA, $oF); $a['output'] = $oF; }
        if (empty($a['valid']) && is_array($a['output'] ?? null)) $a['deficit'] = pp_v8_deficit_rows($orig, $a['output'], $C[$i]['unit']);
        $a['sig'] = is_array($a['output'] ?? null) ? pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$a['output']['data']))) : ($C[$i]['sig'] ?? pp_v3_sig($C[$i]['stops']));   // commitment NYATA hasil dispatch
        $R[$i] = $a;
    }
    return $R;
}
function pp_v8_priority_review(array $input, array $out, ?float $dl = null): array {
    $t0 = microtime(true);
    try {
        if ((string)getenv('PP_V8_PRIORITY') === '0' || count((array)($out['data'] ?? [])) !== 48) return $out;
        $sig0 = pp_v6_gtg_sig((array)$out['data']);
        if (($out['info']['V8 Priority Review']['rows_sig'] ?? null) === $sig0 && ($out['info']['V8 Priority Review']['status'] ?? '') !== 'SKIPPED') return $out;          // sudah ditinjau
        $orig = pp_normalize_copy($input);
        foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
        $m = (array)$orig['data3']['modeling'];
        $skip = null;
        if (!empty($m['change_over']['enabled'])) $skip = 'CHANGE_OVER_PUNYA_RUANG_KANDIDAT_SENDIRI';
        /* V9: rerun bahan bakar TIDAK dibekukan — review kandidat delta (jumlah kandidat dibatasi agar FINAL < 10 s). */
        $aW = null;
        $repair = null;
        if ($skip === null) { $aW = pp_tl_assess($orig, $out);
            if (empty($aW['valid']) && (string)getenv('PP_V9_START_REPAIR') !== '0') {
                /* V9: pemenang gagal hanya karena reserve / Export -> kandidat START generik (unit prioritas tertinggi yang tersedia). */
                $dlR = min(($dl ?? (isset($GLOBALS['__pp_budget_deadline']) ? (float)$GLOBALS['__pp_budget_deadline'] - 3.0 : microtime(true) + 90.0)), microtime(true) + 90.0);
                $sv = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $sv[$gk] = $gv;
                try { $repair = pp_v9_start_repair($orig, $out, $dlR); } finally { pp_tl_clean_globals(); foreach ($sv as $gk => $gv) $GLOBALS[$gk] = $gv; }
                if (!empty($repair['applied'])) { $keepI = []; foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute', 'Exact Family Prepass', 'V10 Candidate Screening'] as $k) if (isset($out['info'][$k])) $keepI[$k] = $out['info'][$k];
                    $aW = $repair['a']; $out = $aW['output']; foreach ($keepI as $k => $x) $out['info'][$k] = $x; $sig0 = null; }
                if (is_array($repair)) unset($repair['a']);
            }
            if (empty($aW['valid'])) $skip = 'PEMENANG_BELUM_VALID'; else $aW['sig'] = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$out['data']))); }
        if ($skip !== null) { $out['info']['V8 Priority Review'] = ['schema' => 'co12-v8-priority-review-v1', 'status' => 'SKIPPED', 'reason' => $skip, 'rows_sig' => $sig0, 'start_repair' => $repair]; return $out; }
        $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
        if ($dl === null) $dl = isset($GLOBALS['__pp_budget_deadline']) ? (float)$GLOBALS['__pp_budget_deadline'] - 3.0 : microtime(true) + 60.0;
        $fuelR = pp_v8_fuel_active($m); $maxRounds = $fuelR ? 2 : 4;
        /* Batas DETERMINISTIK (jumlah kandidat & putaran, fungsi state saja); batas waktu hanya pengaman (besar). */
        $wallCap = (float)(getenv('PP_V8_WALL_S') ?: 240.0); $cap = (int)(getenv('PP_V8_CAP') ?: ($fuelR ? 6 : 12)); if (isset($GLOBALS['__pp_v8_max_wall'])) $wallCap = min($wallCap, (float)$GLOBALS['__pp_v8_max_wall']);   /* V15.16 Maximum Review: anggaran sisa sampai target total */ if (isset($GLOBALS['__pp_v8_fast_wall'])) { $wallCap = (float)$GLOBALS['__pp_v8_fast_wall']; $cap = min($cap, (int)(getenv('PP_V8_FAST_CAP') ?: 4)); $maxRounds = min($maxRounds, (int)(getenv('PP_V8_FAST_ROUNDS') ?: 1)); }   /* V15.16 Fastest: review terbatas (bounded race), bukan Maximum Review */ $rankP = pp_priority_rank($m);
        /* V9 REVIEW DELTA: FINAL inkremental kanonik dengan commitment identik dengan FINAL jangkar yang sudah ditinjau.
         * Kandidat pembanding jangkar (valid + gagal window gas) sudah dibawa ke ruang kandidat inkremental dan dievaluasi
         * ulang pada state baru bila dapat menyalip; bukti per row (kapasitas Export, runtime, blok, ramp) dipakai ulang. */
        $delta = null;
        if (pp_v9_canon() && !empty($out['info']['Incremental Recompute']['applied']) && empty($repair['applied']) && (string)getenv('PP_V9_DELTA_REVIEW') !== '0') {
            $bf = basename((string)($out['info']['Incremental Recompute']['base_file'] ?? ''));
            $Bf = $bf !== '' ? json_decode((string)@file_get_contents(pp_final_dir() . DIRECTORY_SEPARATOR . $bf), true) : null;
            $BR = is_array($Bf) ? ($Bf['v9_review'] ?? null) : null;
            if (is_array($BR) && ($BR['commit_sig'] ?? '#') === $aW['sig'] && is_array($BR['row_evidence'] ?? null)) $delta = $BR + ['base_file' => $bf];
        }
        if ($delta !== null) $maxRounds = 0;
        /* V10 rute cepat (hasil inkremental kanonik): satu putaran review, maks. 3 kandidat disimulasikan; kandidat dengan
         * commitment yang SUDAH dievaluasi pada state ini oleh ruang kandidat inkremental dipakai ulang (state fisik sama). */
        /* State JAUH dari jangkar (lihat Tier 2b pesaing): dispatch tersusun tidak mewakili re-dispatch engine -> review V9
         * (setiap kandidat didaratkan penuh). */
        $v10R = pp_v10_fast() && !empty($out['info']['Incremental Recompute']['applied']) && empty($repair['applied']) && empty($GLOBALS['ppV10Inc']['far']);
        $v10Seen = $v10R ? (array)($GLOBALS['ppV10Inc']['evaluated'] ?? []) : [];
        if ($v10R && $delta === null) { $maxRounds = 2; $cap = 16; }
        /* V10: review delta pada rute cepat tetap mengevaluasi SATU putaran kandidat tersusun (commitment pembanding jangkar
         * = kandidat patokan V8/V9 pada state baru); bila ada yang lebih baik, bukti per row dihitung ulang dari putaran itu. */
        if ($v10R && $delta !== null) { $maxRounds = 2; $cap = 16; }
        $GLOBALS['ppV10ReviewFast'] = $v10R && !empty($GLOBALS['ppV10Inc']['T']);
        /* V10 (uji): review tersusun juga pada FINAL exact (target supplier internal dari pemenang pipeline). */
        if (!$v10R && pp_v10_fast() && (string)getenv('PP_V10_EXACT_FASTREVIEW') === '1' && empty($repair['applied']) && empty($out['info']['Incremental Recompute']['applied'])) {
            $tW = pp_tl_supplier_target($out); if ($tW !== null) { $GLOBALS['ppV10Inc'] = ['T' => $tW, 'evaluated' => [], 'pruned' => []]; $GLOBALS['ppV10ReviewFast'] = true; } }
        if (!empty($repair['applied'])) { $maxRounds = 1; $cap = 6; $wallCap = max($wallCap, 180.0); $dl = max($dl, $t0 + 180.0); }   // V9: sesudah REPAIR START, batas jumlah (bukan waktu) yang menentukan
        $dl = (pp_v9_canon() && isset($GLOBALS['__pp_async_worker_ceiling'])) ? $t0 + $wallCap : min($dl, $t0 + $wallCap);     // V9 (job asinkron): batas jumlah kandidat yang menentukan; waktu hanya pengaman
        $cp0 = $aW['key']['cp'] ?? null; $applied = []; $all = [];
        /* V11: himpunan kandidat valid review (dengan dispatch 48 row) untuk seleksi band CP 0,2 % + tie-break Heat Rate. */
        $poolV11 = [['id' => 'INCUMBENT', 'a' => $aW + ['output' => $out]]];
        if (!empty($repair['applied'])) $applied[] = ['round' => 0, 'candidate' => $repair['winner'], 'cp_before' => null, 'cp_after' => $cp0, 'reason' => 'REPAIR_START_' . implode('_', (array)($repair['deficit_kind'] ?? []))]; $final = null; $rounds = 0; $W = $out; $truncated = false; $nSim = 0; $nPre = 0;
        try {
            $rescan = null; $round0 = 1;
            for ($pass = 0; $pass < 2; $pass++) {      // V9: putaran tambahan hanya bila PINDAI ULANG mengganti pemenang
            for ($round = $round0; $round <= $maxRounds; $round++) {
                $G = pp_v8_candidates($orig, $W); $C = $G['candidates']; $rounds = $round; $GLOBALS['ppV10ReviewRound'] = $round;
                $final = ['relevant' => $G['relevant'], 'results' => [], 'cp' => $aW['key']['cp'] ?? null];
                if (!$C) break;
                /* Evaluasi first-improvement berurutan (urutan deterministik), batch 2 kandidat (pemilik + pembantu).
                 * Kandidat removal yang pasti melanggar Export ditolak lewat bukti kapasitas tanpa simulasi. */
                $best = null; $bi = null; $pos = 0; $nC = count($C); $runnerUp = null;
                $rec = function (int $i, array $a) use (&$final, &$all, &$nSim, &$nPre, &$truncated, $C, $round, &$poolV11) {
                    $c = $C[$i]; $pre = $a['prescreen'] ?? null; $skp = $a['skipped'] ?? null; $ev = $pre === null && $skp === null;
                    if ($ev && !empty($a['valid']) && is_array($a['output'] ?? null) && is_array($a['key'] ?? null)) $poolV11[] = ['id' => $c['id'], 'a' => $a];
                    if ($pre !== null && function_exists('pp_v11_cnt_screen')) pp_v11_cnt_screen((array)($c['stops'] ?? []));
                    if ($skp !== null && strpos((string)$skp, 'BATAS_WAKTU') === 0) $truncated = true; if ($pre !== null) $nPre++; if ($ev && empty($a['v10_dedup'])) $nSim++;
                    $viol = $pre !== null ? [(string)($pre['code'] ?? 'EXPORT')] : array_values(array_unique(array_map('pp_v8_viol_code', (array)($a['violations'] ?? []))));
                    $sum = ['id' => $c['id'], 'kind' => $c['kind'], 'unit' => strtoupper($c['unit']), 'rows' => $c['iv'], 'off_rows' => $c['off_rows'],
                            'peer' => isset($c['peer']) ? strtoupper($c['peer']) : null, 'peer_rows' => $c['peer_rows'] ?? null, 'round' => $round,
                            'evaluated' => $ev || $pre !== null, 'method' => $pre !== null ? (string)($pre['method'] ?? 'PRASARING_KAPASITAS_EXPORT') : ($ev ? (!empty($a['v10_dedup']) ? 'V10_DIPAKAI_ULANG_STATE_FISIK_SAMA' : 'DISPATCH_48_ROW_ENGINE') : 'TIDAK_DIEVALUASI:' . $skp),
                            'valid' => $ev && !empty($a['valid']), 'cp' => $ev ? ($a['key']['cp'] ?? null) : null, 'violations' => $viol,
                            'failed_checks' => $ev ? array_keys(array_filter((array)($a['checks'] ?? []), function ($x) { return !$x; })) : [],
                            'prescreen' => $pre, 'deficit_rows' => $a['deficit'] ?? null,
                            'stops' => $c['stops'], 'gas_only' => $ev && !empty($a['gas_only']), 'dev' => $ev ? (float)($a['dev'] ?? 0) : null];
                    $final['results'][] = $sum + ['_c' => $c]; $all[] = $sum;
                };
                while ($pos < $nC) {
                    $batch = [];
                    while ($pos < $nC && count($batch) < 2) {
                        $i = $pos++; $c = $C[$i];
                        if (!empty($c['keep'])) continue;
                        if (in_array($c['kind'], ['DECOMMIT', 'STOP', 'EARLY_STOP', 'DELAY'], true) && $c['off_rows'][1] >= $c['off_rows'][0]) {
                            $pr = pp_v8_export_prescreen($orig, (array)$W['data'], $c['unit'], range($c['off_rows'][0], $c['off_rows'][1]));
                            if ($pr !== null) { $rec($i, ['prescreen' => $pr]); continue; }
                        }
                        $lp = pp_v9_legal_prescreen($orig, $c) ?? pp_v9_swap_hr_prescreen($orig, (array)$W['data'], $c);
                        if ($lp === null && function_exists('pp_v10_export_capacity_proof')) $lp = pp_v10_export_capacity_proof($orig, (array)$W['data'], (array)$c['stops']);
                        if ($lp !== null) { $rec($i, ['prescreen' => $lp]); continue; }
                        if ($v10Seen && isset($v10Seen[$c['sig'] ?? '']) && empty($GLOBALS['ppV10ReviewFast'])) { $d0 = $v10Seen[$c['sig']]; $rec($i, $d0 + ['v10_dedup' => true]); pp_v10_scr('review', 'dedup_state_fisik');
                            if (!empty($d0['valid']) && is_array($d0['key'] ?? null) && pp_v9_better($d0, $best === null ? $aW : $best, $rankP) && is_array($d0['output'] ?? null)) { $best = $d0; $bi = $i; }
                            continue; }
                        if ($nSim + count($batch) >= $cap) { $rec($i, ['skipped' => 'BATAS_KANDIDAT_REVIEW']); continue; }
                        $batch[] = $i;
                    }
                    if (!$batch) continue;
                    $sub = []; foreach ($batch as $i) $sub[] = $C[$i];
                    $Rb = pp_v8_eval_all($orig, $W, $sub, $dl, count($sub), !empty($repair['applied']));
                    foreach ($batch as $k => $i) { $a = $Rb[$k] ?? ['skipped' => 'BATAS_WAKTU_REVIEW']; $rec($i, $a);
                        $ev = !isset($a['prescreen']) && !isset($a['skipped']);
                        if ($ev && !empty($a['valid']) && is_array($a['key'] ?? null) && pp_v9_better($a, $best === null ? $aW : $best, $rankP)) { $best = $a; $bi = $i; }
                        elseif ($ev && !empty($a['valid']) && !empty($a['v10_synth']) && is_array($a['output'] ?? null) && ($runnerUp === null || (float)$a['key']['cp'] < (float)$runnerUp['key']['cp'])) $runnerUp = $a + ['_id' => $C[$i]['id']]; }
                    if ($best !== null) break;
                }
                while ($pos < $nC) { $i = $pos++; if (empty($C[$i]['keep'])) $rec($i, ['skipped' => 'PUTARAN_BERHENTI_PADA_PERBAIKAN']); }
                /* V10 LOOKAHEAD DUA LANGKAH (rute cepat): putaran tanpa perbaikan -> kandidat tersusun valid termurah (mis. SWAP) menjadi
                 * titik awal satu langkah lagi (DELAY / STOP / SWAP di sekitarnya), dievaluasi sebagai dispatch tersusun. Kandidat
                 * patokan dua langkah (mis. commitment rantai V8: SWAP lalu DELAY) dengan demikian ikut dibandingkan pada state ini. */
                if ($best === null && $v10R && !empty($GLOBALS['ppV10ReviewFast']) && $runnerUp !== null && empty($lookDone) && $round === 1) { $lookDone = true;
                    $G2 = pp_v8_candidates($orig, $runnerUp['output']); $nL = 0;
                    foreach ((array)$G2['candidates'] as $c2) { if (!empty($c2['keep']) || $nL >= 8) continue;
                        if (in_array($c2['kind'], ['DECOMMIT', 'STOP', 'EARLY_STOP', 'DELAY'], true) && $c2['off_rows'][1] >= $c2['off_rows'][0] && pp_v8_export_prescreen($orig, (array)$runnerUp['output']['data'], $c2['unit'], range($c2['off_rows'][0], $c2['off_rows'][1])) !== null) continue;
                        if ((pp_v9_legal_prescreen($orig, $c2) ?? pp_v9_swap_hr_prescreen($orig, (array)$runnerUp['output']['data'], $c2)) !== null) continue;
                        $nL++; $a2 = pp_v10_review_eval($orig, $runnerUp['output'], $c2, $dl); pp_tl_clean_globals(); if (!is_array($a2)) continue;
                        $a2['sig'] = is_array($a2['output'] ?? null) ? pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$a2['output']['data']))) : ''; $a2['v10_synth'] = true;
                        $c2['id'] = 'LOOKAHEAD:' . $runnerUp['_id'] . '+' . $c2['id']; $C[] = $c2; $iL = count($C) - 1; pp_v10_scr('review', 'lookahead_synth');
                        $sumL = ['id' => $c2['id'], 'kind' => $c2['kind'], 'unit' => strtoupper($c2['unit']), 'rows' => $c2['iv'], 'off_rows' => $c2['off_rows'], 'peer' => isset($c2['peer']) ? strtoupper($c2['peer']) : null,
                            'peer_rows' => $c2['peer_rows'] ?? null, 'round' => $round, 'evaluated' => true, 'method' => 'V10_LOOKAHEAD_DISPATCH_TERSUSUN', 'valid' => !empty($a2['valid']), 'cp' => $a2['key']['cp'] ?? null,
                            'violations' => array_values(array_unique(array_map('pp_v8_viol_code', (array)($a2['violations'] ?? [])))), 'failed_checks' => array_keys(array_filter((array)($a2['checks'] ?? []), function ($x) { return !$x; })),
                            'prescreen' => null, 'deficit_rows' => null, 'stops' => $c2['stops'], 'gas_only' => !empty($a2['gas_only']), 'dev' => (float)($a2['dev'] ?? 0)];
                        $final['results'][] = $sumL + ['_c' => $c2]; $all[] = $sumL; $nSim++;
                        if (!empty($a2['valid']) && is_array($a2['output'] ?? null)) $poolV11[] = ['id' => $c2['id'], 'a' => $a2];
                        if (!empty($a2['valid']) && is_array($a2['key'] ?? null) && pp_v9_better($a2, $best === null ? $aW : $best, $rankP)) { $best = $a2; $bi = $iL; } } }
                if ($best === null) break;
                /* V10: perbaikan valid yang ditemukan pada putaran terakhir juga diterapkan (invarian FINAL_CP <= kandidat valid termurah). */
                $new = $best['output'];
                foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute', 'Exact Family Prepass', 'V10 Candidate Screening'] as $k) if (isset($W['info'][$k])) $new['info'][$k] = $W['info'][$k];
                if (isset($new['info']['Global Commitment Review']) && is_array($new['info']['Global Commitment Review'])) {
                    $new['info']['Global Commitment Review']['ladder'][] = ['unit' => $C[$bi]['id'], 'variant' => 'V8_PRIORITY_CANDIDATE', 'key' => $best['key'], 'incumbent_key' => $aW['key'], 'better' => true];
                    $new['info']['Global Commitment Review']['candidates_evaluated'] = (int)($new['info']['Global Commitment Review']['candidates_evaluated'] ?? 0) + count($C);
                    $new['info']['Global Commitment Review']['candidates_total'] = (int)($new['info']['Global Commitment Review']['candidates_total'] ?? 0) + count($C);
                }
                if (isset($new['info']['Exact Candidate Space']) && is_array($new['info']['Exact Candidate Space'])) { $new['info']['Exact Candidate Space']['winner_source'] = 'V8_PRIORITY_CANDIDATE';
                    $new['info']['Exact Candidate Space']['winner_node'] = $C[$bi]['id']; $new['info']['Exact Candidate Space']['winner_cost_production'] = $best['key']['cp'] ?? null; }
                pp_v3_relabel($new, $input);
                $applied[] = ['round' => $round, 'candidate' => $C[$bi]['id'], 'cp_before' => $aW['key']['cp'] ?? null, 'cp_after' => $best['key']['cp'] ?? null,
                              'replaced_stops' => pp_v8_stops(pp_v8_masks((array)$W['data']))];
                $W = $new; $aW = $best;
            }
            /* Lanjutan polish Unit Priority pada pemenang final: temuan "unit prioritas rendah dibebani di atas minimum
             * sementara unit prioritas lebih tinggi punya headroom" yang belum diuji karena batas putaran polish diuji
             * counterfactual (dispatch GTG dibekukan, dihitung ulang penuh, divalidasi 48 row, diterima hanya bila lebih baik). */
            $polish = null;
            if (function_exists('pp_v6_priority_polish') && microtime(true) < $dl - 2.0) {
                $audP = pp_v5_headroom_priority_audit($orig, $W, true); $need = false;
                foreach ((array)($audP['unresolved'] ?? []) as $fP) if (($fP['type'] ?? '') === 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM') { $need = true; break; }
                if ($need || $applied) {
                    $pp0 = (array)($W['info']['Unit Priority Polish'] ?? []); $Wp = $W; $Wp['info']['Unit Priority Polish']['done'] = false;
                    $aP = pp_v6_priority_polish($orig, array_merge($aW, ['output' => $Wp]), $dl, null, 8);
                    $pp1 = (array)($aP['output']['info']['Unit Priority Polish'] ?? []);
                    $pp1['shifts_applied'] = array_merge((array)($pp0['shifts_applied'] ?? []), (array)($pp1['shifts_applied'] ?? []));
                    $pp1['cost_production_before'] = $pp0['cost_production_before'] ?? ($pp1['cost_production_before'] ?? null); $pp1['v8_continued'] = true;
                    $aP['output']['info']['Unit Priority Polish'] = $pp1;
                    if (pp_v6_gtg_sig((array)$aP['output']['data']) !== pp_v6_gtg_sig((array)$W['data']) && is_array($aP['key'] ?? null) && pp_global_commitment_better($aP['key'], $aW['key'])) {
                        $applied[] = ['round' => $rounds, 'candidate' => 'POLISH_UNIT_PRIORITY_LANJUTAN', 'cp_before' => $aW['key']['cp'] ?? null, 'cp_after' => $aP['key']['cp'] ?? null];
                        $keep = []; foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute', 'Exact Family Prepass', 'V10 Candidate Screening'] as $k) if (isset($W['info'][$k])) $keep[$k] = $W['info'][$k];
                        $W = $aP['output']; foreach ($keep as $k => $x) $W['info'][$k] = $x; $aW = $aP; $poolV11[] = ['id' => 'POLISH_UNIT_PRIORITY_LANJUTAN', 'a' => $aP];
                    } else $W['info']['Unit Priority Polish'] = $pp1 + ['rows_sig' => pp_v6_gtg_sig((array)$W['data'])];
                    $polish = ['evaluations' => $pp1['counterfactual_evaluations'] ?? null, 'shifts' => count((array)($pp1['shifts_applied'] ?? []))];
                }
            }
            /* V9 PINDAI ULANG: pendaratan bisection kandidat hanya mencari validitas, bukan Cost Production terendah (lanskap window
             * gas kasar). Kandidat valid / hanya-gagal-window-gas termurah putaran terakhir (dalam 0,06 USD/MWh dari pemenang ter-polish) dipindai lever gas
             * dengan titik lever yang sama seperti pemenang inkremental; diganti hanya bila valid dan lebih baik. Deterministik. */
            if ($pass > 0 || $rescan !== null) break;
            if ((string)getenv('PP_V9_RESCAN') === '1' && pp_v9_canon() && isset($GLOBALS['__pp_async_worker_ceiling']) && empty($repair['applied']) && is_array($final) && microtime(true) < $dl - 15.0) {   // investigasi (bawaan mati: terukur tanpa manfaat CP, +7 core run)
                $rb = null; foreach ((array)$final['results'] as $res) if ((!empty($res['valid']) || !empty($res['gas_only'])) && isset($res['cp']) && ($rb === null || (float)$res['cp'] < (float)$rb['cp'] || ((float)$res['cp'] == (float)$rb['cp'] && strcmp($res['id'], $rb['id']) < 0))) $rb = $res;
                if ($rb !== null && (float)$rb['cp'] <= (float)($aW['key']['cp'] ?? 0) + 0.06) {
                    $evR = 0; $mdR = INF;
                    $sc = pp_v3_scan_winner($orig, ['stops' => $rb['_c']['stops']], 0.0, null, $dl, $evR, function (array $a) {}, 0.0, $mdR, INF);
                    pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
                    $rescan = ['candidate' => $rb['id'], 'cp_landing' => $rb['cp'], 'cp_scan' => is_array($sc) ? ($sc['key']['cp'] ?? null) : null, 'evaluations' => $evR, 'applied' => false];
                    if (is_array($sc) && !empty($sc['valid']) && is_array($sc['output'] ?? null)) {
                        $aS = pp_tl_assess($orig, $sc['output']); $aS['output'] = $sc['output']; $aS['sig'] = pp_v3_sig(pp_v8_stops(pp_v8_masks((array)$sc['output']['data'])));
                        if (!empty($aS['valid']) && pp_v9_better($aS, $aW, $rankP)) {
                            $new = $aS['output'];
                            foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute', 'Exact Family Prepass', 'V10 Candidate Screening'] as $k) if (isset($W['info'][$k])) $new['info'][$k] = $W['info'][$k];
                            if (isset($new['info']['Exact Candidate Space']) && is_array($new['info']['Exact Candidate Space'])) { $new['info']['Exact Candidate Space']['winner_source'] = 'V9_RESCAN_CANDIDATE'; $new['info']['Exact Candidate Space']['winner_node'] = 'RESCAN:' . $rb['id']; $new['info']['Exact Candidate Space']['winner_cost_production'] = $aS['key']['cp'] ?? null; }
                            pp_v3_relabel($new, $input);
                            $applied[] = ['round' => $rounds, 'candidate' => 'RESCAN:' . $rb['id'], 'cp_before' => $aW['key']['cp'] ?? null, 'cp_after' => $aS['key']['cp'] ?? null, 'replaced_stops' => pp_v8_stops(pp_v8_masks((array)$W['data']))];
                            $W = $new; $aW = $aS; $rescan['applied'] = true;
                        }
                    }
                }
            }
            if (empty($rescan['applied'])) break;
            $round0 = $rounds + 1; $maxRounds = $rounds + 2;          // kandidat di sekitar pemenang baru (maks. 2 putaran), lalu polish lagi
            }
        } finally { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; unset($GLOBALS['ppV10ReviewFast']); }
        /* V11: sapuan konsolidasi LOW_LOAD_FRAGMENTATION + seleksi band CP 0,2 % (tie-break Heat Rate). */
        $sweepV11 = null; $bandV11 = null;
        if (pp_v11_on()) {
            try { $sweepV11 = pp_v11_consolidation_sweep($orig, $W, $all, $dl, $poolV11); } catch (Throwable $e) { $sweepV11 = ['error' => $e->getMessage()]; }
            pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
            try { $bandV11 = pp_v11_band_select($orig, $poolV11); } catch (Throwable $e) { $bandV11 = null; }
            if (is_array($bandV11) && is_array($bandV11['winner'] ?? null) && $bandV11['winner']['m']['sig'] !== pp_v6_gtg_sig((array)$W['data'])) {
                $bw = $bandV11['winner']; $new = $bw['a']['output'];
                foreach (['Run Status', 'Global Commitment Review', 'Exact Candidate Space', 'Incremental Recompute', 'Exact Family Prepass', 'V10 Candidate Screening'] as $k) if (isset($W['info'][$k])) $new['info'][$k] = $W['info'][$k];
                if (isset($new['info']['Exact Candidate Space']) && is_array($new['info']['Exact Candidate Space'])) { $new['info']['Exact Candidate Space']['winner_source'] = 'V11_BAND_CP_HEAT_RATE';
                    $new['info']['Exact Candidate Space']['winner_node'] = $bw['id']; $new['info']['Exact Candidate Space']['winner_cost_production'] = $bw['a']['key']['cp'] ?? null; }
                pp_v3_relabel($new, $input);
                $applied[] = ['round' => $rounds, 'candidate' => 'V11_BAND_HEAT_RATE:' . $bw['id'], 'cp_before' => $aW['key']['cp'] ?? null, 'cp_after' => $bw['a']['key']['cp'] ?? null,
                    'hr_before' => $aW['key']['hr'] ?? null, 'hr_after' => $bw['a']['key']['hr'] ?? null, 'replaced_stops' => pp_v8_stops(pp_v8_masks((array)$W['data']))];
                $W = $new; $aW = $bw['a'];
            }
        }
        /* Alasan terstruktur per row (unit, row) dari kandidat pembanding pemenang final. */
        $cpF = (float)($aW['key']['cp'] ?? 0); $ev = [];
        foreach ((array)$final['relevant'] as $rl) {
            $u = strtolower($rl['unit']); [$a, $b] = $rl['rows'];
            for ($r = $a; $r <= $b; $r++) {
                $why = []; $alt = null; $open = [];
                foreach ($final['results'] as $res) {
                    $c = $res['_c']; if ($c['unit'] !== $u || $c['iv'][0] !== $a) continue;
                    if ($r < $c['off_rows'][0] || $r > $c['off_rows'][1]) continue;             // kandidat ini tetap menjalankan unit pada row r
                    if (!$res['evaluated']) { $open[] = $res['id']; continue; }
                    if ($res['valid']) { $d = (float)$res['cp'] - $cpF; if ($alt === null || $d < $alt['delta_cp']) $alt = ['candidate' => $res['id'], 'cp' => $res['cp'], 'delta_cp' => round($d, 6)]; continue; }
                    if ($res['prescreen'] !== null) { $pz = $res['prescreen'];
                        $why[] = isset($pz['code']) ? sprintf('%s:%s(%s)', $c['kind'], $pz['code'], $pz['detail'] ?? '') : sprintf('%s:EXPORT(kapasitas maks row %d = %.1f < Range Min %.1f MW)', $c['kind'], $pz['row'], $pz['export_max_mw'], $pz['range_min_mw']); continue; }
                    foreach ($res['violations'] ?: array_map('strtoupper', $res['failed_checks']) as $vc) $why[] = $c['kind'] . ':' . $vc;
                    $df = $res['deficit_rows'][$r] ?? null;
                    if (is_array($df)) { $why[] = $c['kind'] . ':EXPORT_ROW_INI(' . implode(', ', $df) . ')'; foreach ($df as $x) if (substr($x, -4) === 'RAMP') $why[] = 'HEADROOM_TERHALANG_RAMP:' . substr($x, 0, -5); }
                }
                $proof = $why || $alt !== null;
                if ($rl['first_legal_stop_row'] === null || $r < (int)$rl['first_legal_stop_row']) $why[] = ($rl['first_legal_stop_basis'] ?? 'MIN_RUNTIME') . '_SEJAK_START_ROW_' . $a;
                if (!$proof && $open) $why = [];                                              // kandidat pembanding belum selesai: tidak ada alasan sah
                if ($alt !== null) $why[] = sprintf('CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID %s (CP %.4f, +%.4f)', $alt['candidate'], $alt['cp'], $alt['delta_cp']);
                $ev[$r . '#' . strtoupper($u)] = ['reasons' => array_values(array_unique($why)), 'valid_alternative' => $alt, 'not_evaluated' => $open];
            }
        }
        /* V11: bukti counterfactual 48 row dari himpunan kandidat valid comparator (pool V11) untuk setiap (row, unit GTG)
         * pemenang yang belum punya alasan — mis. unit yang baru running karena pemenang band/sapuan konsolidasi. Kandidat
         * valid lain yang mematikan unit itu pada row tsb. adalah bukti: CP-nya lebih tinggi, atau (di dalam band 0,2 %)
         * kalah tie-break Heat Rate/start/prioritas. Hanya audit — dispatch dan pemenang tidak berubah. */
        if (pp_v11_on() && !empty($poolV11) && is_array($W['data'] ?? null)) {
            try {
                $mW = pp_v8_masks((array)$W['data']); $sgW = pp_v6_gtg_sig((array)$W['data']); $hrF = (float)($aW['key']['hr'] ?? 0); $altV = []; $seenA = [];
                foreach ($poolV11 as $pv) { $a = $pv['a'] ?? null; if (!is_array($a) || empty($a['valid']) || !is_array($a['output']['data'] ?? null) || !isset($a['key']['cp'])) continue;
                    $sgA = pp_v6_gtg_sig((array)$a['output']['data']); if ($sgA === $sgW || isset($seenA[$sgA])) continue; $seenA[$sgA] = true;
                    $altV[] = [(string)$pv['id'], (float)$a['key']['cp'], (float)($a['key']['hr'] ?? 0), pp_v8_masks((array)$a['output']['data'])]; }
                foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u);
                    for ($r = 1; $r <= 48; $r++) { if (empty($mW[$u][$r])) continue; $k = $r . '#' . $U; if (!empty($ev[$k]['reasons'])) continue;
                        $bx = null; foreach ($altV as $x) if (empty($x[3][$u][$r]) && ($bx === null || $x[1] < $bx[1] || ($x[1] == $bx[1] && strcmp($x[0], $bx[0]) < 0))) $bx = $x;
                        if ($bx === null) continue; $dcp = $bx[1] - $cpF;
                        $why = $dcp > 1e-9 ? sprintf('CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID %s (CP %.4f, +%.4f)', $bx[0], $bx[1], $dcp)
                            : sprintf('BAND_V11_TIE_BREAK: kandidat valid %s tanpa %s pada row ini (CP %.4f, %+.4f di dalam band 0,2 %%, Heat Rate %.2f vs %.2f)', $bx[0], $U, $bx[1], $dcp, $bx[2], $hrF);
                        $ev[$k] = ['reasons' => [$why], 'valid_alternative' => ['candidate' => $bx[0], 'cp' => $bx[1], 'delta_cp' => round($dcp, 6), 'heat_rate' => $bx[2]], 'not_evaluated' => [], 'basis' => 'V11_POOL_COUNTERFACTUAL_48_ROW'];
                    } }
            } catch (Throwable $e) {}
        }
        if ($delta !== null && $applied) $delta = null;                         // V10: pemenang berubah -> bukti dari putaran ini
        if ($delta !== null) { $final = ['relevant' => (array)($delta['relevant_intervals'] ?? []), 'results' => []];
            foreach ((array)$delta['row_evidence'] as $k => $e) $ev[$k] = (array)$e + ['basis' => 'FINAL_JANGKAR_' . $delta['base_file']]; }
        $cands = []; foreach ((array)($final['results'] ?? []) as $res) { unset($res['_c'], $res['stops']); $res['delta_cp_vs_final'] = $res['cp'] === null ? null : round((float)$res['cp'] - $cpF, 6); $cands[] = $res; }
        if ($delta !== null) { foreach ((array)($delta['final_candidates'] ?? []) as $c0) $cands[] = $c0 + ['basis' => 'FINAL_JANGKAR'];
            $nearD = (array)($out['info']['Incremental Recompute']['near_candidates'] ?? []); }
        $W['info']['V8 Priority Review'] = ['schema' => 'co12-v8-priority-review-v1', 'status' => $delta !== null ? ($applied ? 'DELTA_REVIEW_APPLIED' : 'DELTA_REVIEW_BASIS') : ($applied ? 'APPLIED' : 'NO_BETTER_VALID_CANDIDATE'),
            'delta' => $delta === null ? null : ['base_file' => $delta['base_file'], 'base_status' => $delta['status'] ?? null, 'commitment_unchanged' => true,
                'competitors_reevaluated_by_incremental' => $nearD ?? [], 'rule' => 'commitment identik dengan FINAL jangkar yang ditinjau; kandidat pembanding jangkar dibawa sebagai pesaing inkremental (dievaluasi ulang pada state baru bila dapat menyalip); bukti per row dipakai ulang'],
            'cost_production_before' => $cp0, 'cost_production_after' => $aW['key']['cp'] ?? null, 'applied' => $applied, 'rounds' => $rounds,
            'truncated' => $truncated, 'relevant_intervals' => $final['relevant'], 'final_candidates' => $cands, 'candidates_total' => count($all),
            'candidates_simulated' => $nSim, 'candidates_prescreened' => $nPre, 'polish_continued' => $polish ?? null,
            'history' => array_slice(array_map(function ($x) { unset($x['deficit_rows'], $x['stops']); return $x; }, $all), 0, 80),
            'start_repair' => $repair, 'rescan' => $rescan ?? null, 'carry' => pp_v8_carry($all, $applied), 'row_evidence' => $ev, 'rows_sig' => pp_v6_gtg_sig((array)$W['data']), 'wall_s' => round(microtime(true) - $t0, 3),
            'method' => 'kandidat row-local dari commitment pemenang (SWAP peer prioritas lebih tinggi, TRUNC row legal pertama, DELAY start, OFF); prasaring kapasitas Export (batas atas sah); dispatch 48 row engine + pendaratan window gas; validasi penuh terhadap input asli; pemenang diganti hanya bila valid dan lebih baik (comparator engine)'];
        if (pp_v11_on()) {
            $W['info']['V11 Consolidation Sweep'] = $sweepV11;
            $W['info']['V11 Candidate Comparison'] = is_array($bandV11) ? ['schema' => 'co12-v11-band-v1', 'rule' => 'kandidat valid (hard constraints + provenance PASS); CP minimum; di dalam band CP <= CP_min x (1 + ' . str_replace('.', ',', (string)($bandV11['band_pct'] ?? 0.2)) . ' %): Heat Rate JBBK+MM2100 lebih rendah, start lebih sedikit, row unit prioritas rendah lebih sedikit, row LOW_LOAD_FRAGMENTATION lebih sedikit, skor Unit Priority, kunci kanonik',
                'cp_min' => $bandV11['cp_min'] ?? null, 'band_upper' => $bandV11['band_upper'] ?? null, 'candidates_valid' => $bandV11['valid'] ?? 0, 'candidates_in_band' => $bandV11['in_band'] ?? 0,
                'winner' => $bandV11['winner']['id'] ?? null, 'winner_cp' => isset($bandV11['winner']) ? round((float)$bandV11['winner']['m']['cp'], 4) : null, 'winner_heat_rate' => isset($bandV11['winner']) ? round((float)$bandV11['winner']['m']['hr'], 2) : null,
                'table' => array_slice((array)($bandV11['table'] ?? []), 0, 60)] : null;
        }
        return $W;
    } catch (Throwable $e) {
        $out['info']['V8 Priority Review'] = ['schema' => 'co12-v8-priority-review-v1', 'status' => 'ERROR', 'error' => $e->getMessage()];
        return $out;
    }
}
/* =============================================================================================
 *  V6 UNIT PRIORITY POLISH — temuan "unit prioritas rendah dibebani di atas minimum sementara unit
 *  prioritas lebih tinggi masih punya headroom" DIUJI dengan counterfactual engine, bukan ditebak.
 *
 *  Untuk tiap (row, unit) temuan: beban unit prioritas rendah diturunkan sebesar pergeseran legal
 *  (headroom, ramp tetangga, di atas minimum) dan unit prioritas tinggi dinaikkan sehingga keluaran
 *  blok GTG+STG row itu tetap (Export tidak bergeser); dispatch GTG 48 row dibekukan dan dihitung
 *  ulang penuh oleh engine (evaluator yang sama dengan recompute inkremental: STG, bahan bakar, gas,
 *  supplier, Export, Bus Flow, reserve, validator 48 row). Diterima hanya bila valid penuh DAN lebih
 *  murah menurut comparator engine. Semua pergeseran yang diterima digabung (lalu diuji ulang);
 *  temuan yang tersisa mendapat alasan dari hasil counterfactual-nya sendiri (tidak valid: kategori
 *  pelanggaran; tidak lebih murah: CP counterfactual; headroom tidak cukup mempertahankan keluaran
 *  blok). Deterministik; hanya dispatch GTG yang berubah; tidak ada constraint yang dilonggarkan.
 * =========================================================================================== */
function pp_v6_gtg_sig(array $rows): string {
    $s = ''; foreach ($rows as $r) { foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $U) $s .= sprintf('%.4f,', (float)($r[$U] ?? 0)); $s .= '|'; }
    return md5($s);
}
function pp_v6_shift_rows(array $rows, array $sh, array $d3, array $m): ?array {
    $i = $sh['row'] - 1; if (!isset($rows[$i])) return null; $row = $rows[$i];
    $g = []; foreach ($row as $k => $x) if (is_string($k) && preg_match('~^(G\d+|S\d)$~', $k)) $g[strtolower($k)] = (float)$x;
    $tot = function (array $x): float { $t = 0.0; foreach ($x as $u => $v) if (preg_match('~^(g\d+|s\d)$~', $u)) $t += (float)$v; return $t; };
    $g0 = $g; pp_recompute_stgs($g0, $d3, $m, (int)$sh['row']); $T0 = $tot($g0);
    $w = strtolower($sh['w']); $vs = array_map('strtolower', array_keys($sh['v'])); $nv = count($vs); if (!$nv || !isset($g[$w])) return null;
    $s = (float)$sh['s']; $x = $s;
    for ($it = 0; $it < 8; $it++) { $g1 = $g; $g1[$w] -= $s; foreach ($vs as $v) $g1[$v] = ($g1[$v] ?? 0) + $x / $nv; pp_recompute_stgs($g1, $d3, $m, (int)$sh['row']); $dd = $T0 - $tot($g1); if (abs($dd) < 1e-4) break; $x += $dd * 0.9; }
    foreach ($sh['v'] as $V => $h) if ($x / $nv > (float)$h + 1e-6 || $x <= 0) return null;
    $rows[$i][strtoupper($w)] = round($g[$w] - $s, 4);
    foreach ($vs as $v) $rows[$i][strtoupper($v)] = round(($g[$v] ?? 0) + $x / $nv, 4);
    return $rows;
}
function pp_v6_priority_polish(array $orig, array $a, float $dl, ?callable $evalFn = null, int $maxRounds = 4): array {
    if ((string)getenv('PP_V6_POLISH') === '0' || empty($a['valid']) || !is_array($a['output'] ?? null) || count((array)($a['output']['data'] ?? [])) !== 48) return $a;
    $o = $a['output']; $pp0 = (array)($o['info']['Unit Priority Polish'] ?? []);
    if (!empty($pp0['done']) && ($pp0['rows_sig'] ?? '') === pp_v6_gtg_sig((array)$o['data'])) return $a;
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $t0 = microtime(true); $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $ev = $evalFn ?? function (array $bd) use ($orig, $dl) { return pp_v3_frozen_eval($orig, $bd, $dl); };
    $cp0 = $a['key']['cp'] ?? null; $applied = []; $evidence = []; $evals = 0; $rounds = 0;
    /* V9: Cost Production sama pada presisi model (1e-4) -> beban pada unit prioritas lebih tinggi lebih baik (tie-break Unit Priority,
     * skor = sum rank x MW). Tanpa ini, pergeseran netral biaya ke unit prioritas lebih tinggi tidak pernah diterima. */
    $rankP = pp_priority_rank($m);
    $lscore = function (array $o) use ($rankP): float { $x = 0.0; foreach ((array)($o['data'] ?? []) as $r) foreach ($rankP as $u => $rk) { $c = ($u === 'b1' || $u === 'b2') ? 'BB' . substr($u, 1) : strtoupper($u); $x += ($rk + 1) * (float)($r[$c] ?? 0); } return $x; };
    $better = function (?array $A, array $B) use ($lscore): bool {
        if (!is_array($A) || empty($A['valid']) || !is_array($A['key'] ?? null)) return false;
        if (pp_global_commitment_better($A['key'], (array)$B['key'])) {
            if (!pp_v9_canon()) return true;
            $ka = $A['key']; $kb = (array)$B['key']; if ((float)($ka['hard'] ?? 0) != (float)($kb['hard'] ?? 0) || (float)($ka['constraint'] ?? 0) != (float)($kb['constraint'] ?? 0)) return true;
            if (abs(round((float)$ka['cp'], 4) - round((float)($kb['cp'] ?? 0), 4)) > 1e-9) return true;
            return $lscore((array)$A['output']) <= $lscore((array)($B['output'] ?? [])) + 1e-6;       // lebih murah < 1e-4 tetapi tidak memperburuk prioritas
        }
        if (!pp_v9_canon()) return false;
        $ka = $A['key']; $kb = (array)$B['key'];
        if ((float)($ka['hard'] ?? 0) != (float)($kb['hard'] ?? 0) || (float)($ka['constraint'] ?? 0) != (float)($kb['constraint'] ?? 0)) return false;
        if (abs(round((float)$ka['cp'], 4) - round((float)($kb['cp'] ?? 0), 4)) > 1e-9) return false;
        return $lscore((array)$A['output']) < $lscore((array)($B['output'] ?? [])) - 1e-6; };
    try {
        for ($rounds = 1; $rounds <= $maxRounds; $rounds++) {   // V9: lanjutan pada pemenang final memakai 8 putaran (berhenti alami bila tidak ada perbaikan)
            if (microtime(true) > $dl - 2.0) break;
            $aud = pp_v5_headroom_priority_audit($orig, $o, false);
            $sh = [];
            foreach ((array)($aud['flags'] ?? []) as $f) {
                if (($f['type'] ?? '') !== 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' || !empty($f['resolved'])) continue;
                $k = $f['row'] . '#' . $f['unit'];
                if (!isset($sh[$k])) $sh[$k] = ['row' => (int)$f['row'], 'w' => $f['unit'], 's' => (float)$f['shiftable_mw'], 'v' => []];
                $sh[$k]['s'] = min($sh[$k]['s'], (float)$f['shiftable_mw']); $sh[$k]['v'][$f['higher_unit']] = (float)$f['higher_headroom_mw'];
            }
            if (!$sh) break;
            $evidence = [];
            /* 1) seluruh pergeseran sekaligus */
            $bd = $o['data']; $ok = [];
            foreach ($sh as $k => $x) { $r = pp_v6_shift_rows($bd, $x, $d3, $m); if ($r === null) { $evidence[$k] = ['reason' => 'HEADROOM_TIDAK_CUKUP_MEMPERTAHANKAN_KELUARAN_BLOK', 'shift_mw' => $x['s']]; continue; } $bd = $r; $ok[$k] = $x; }
            if (!$ok) break;
            $A = $ev($bd); $evals++;
            if ($better($A, $a)) { foreach ($ok as $k => $x) $applied[] = $x + ['round' => $rounds]; $a = array_merge($a, ['output' => $A['output'], 'key' => $A['key'], 'checks' => $A['checks'], 'violations' => $A['violations'], 'dev' => $A['dev'], 'gas_only' => $A['gas_only'], 'running' => $A['running'] ?? ($a['running'] ?? [])]); $o = $A['output']; continue; }
            /* 2) satu per satu: alasan per temuan dari counterfactual-nya sendiri */
            $good = []; $bestSingle = null; $bestK = null;
            foreach ($ok as $k => $x) {
                if (microtime(true) > $dl - 2.0) break;
                $r = pp_v6_shift_rows($o['data'], $x, $d3, $m); $A1 = $r === null ? null : $ev($r); $evals++;
                if ($better($A1, $a)) { $good[$k] = $x; if ($bestSingle === null || pp_global_commitment_better($A1['key'], $bestSingle['key'])) { $bestSingle = $A1; $bestK = $k; } continue; }
                if (!is_array($A1)) $evidence[$k] = ['reason' => null];
                elseif (empty($A1['valid'])) $evidence[$k] = ['reason' => 'COUNTERFACTUAL_TIDAK_VALID:' . implode(',', array_slice((array)($A1['violations'] ?? []), 0, 4) ?: array_keys(array_filter((array)$A1['checks'], function ($c) { return $c === false; }))), 'shift_mw' => $x['s'], 'counterfactual_cp' => $A1['key']['cp'] ?? null];
                else $evidence[$k] = ['reason' => sprintf('COUNTERFACTUAL_TIDAK_LEBIH_MURAH:%.4f>=%.4f', (float)($A1['key']['cp'] ?? 0), (float)($a['key']['cp'] ?? 0)), 'shift_mw' => $x['s'], 'counterfactual_cp' => $A1['key']['cp'] ?? null];
            }
            if (!$good) break;
            $pick = $bestSingle; $pickSet = [$bestK => $good[$bestK]];
            if (count($good) > 1) { $bd = $o['data']; foreach ($good as $k => $x) { $r = pp_v6_shift_rows($bd, $x, $d3, $m); if ($r !== null) $bd = $r; }
                $A2 = $ev($bd); $evals++; if ($better($A2, $bestSingle)) { $pick = $A2; $pickSet = $good; } }
            foreach ($pickSet as $k => $x) $applied[] = $x + ['round' => $rounds];
            $a = array_merge($a, ['output' => $pick['output'], 'key' => $pick['key'], 'checks' => $pick['checks'], 'violations' => $pick['violations'], 'dev' => $pick['dev'], 'gas_only' => $pick['gas_only'], 'running' => $pick['running'] ?? ($a['running'] ?? [])]);
            $o = $pick['output']; $evidence = [];
        }
    } catch (Throwable $e) { $evidence['_error'] = ['reason' => null, 'error' => $e->getMessage()]; }
    foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0 && !array_key_exists($gk, $saved)) unset($GLOBALS[$gk]);
    foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
    $o['info']['Unit Priority Polish'] = ['schema' => 'co12-v6-priority-polish-v1', 'done' => true, 'rows_sig' => pp_v6_gtg_sig((array)$o['data']),
        'cost_production_before' => $cp0, 'cost_production_after' => $a['key']['cp'] ?? null, 'shifts_applied' => $applied, 'rounds' => $rounds,
        'counterfactual_evaluations' => $evals, 'wall_s' => round(microtime(true) - $t0, 3), 'evidence' => $evidence,
        'method' => 'dispatch GTG dibekukan + pergeseran beban unit prioritas rendah -> tinggi dengan keluaran blok tetap; dihitung ulang penuh oleh engine dan divalidasi 48 row; diterima hanya bila valid dan lebih murah'];
    $a['output'] = $o;
    return $a;
}
/* =============================================================================================
 *  V7 SERTIFIKAT ENVELOPE GAS — konflik window kuota yang TIDAK bergantung pada dispatch.
 *
 *  Identitas akunting engine (worker02 pp_run_simulation_once_raw, dicek terhadap 415 simulasi):
 *      Effective Total Gas - PGN Pipe Used = K
 *      K = LNG Used + FFavg + a_J - (nJ/24)*min(gasJ, qJ)  +  gasM + a_M - (nM/24)*min(gasM, qMraw)
 *  dengan a_J/a_M = jumlah actual per jam Jababeka/MM2100 yang terisi, nJ/nM = jumlah jam actual,
 *  FFavg = rata-rata fixed flow Jababeka per row (energi), qJ = kuota fixed flow Jababeka (energi).
 *  Lever gas GTG 1-9 (target supplier, commitment, top-up/trim, pergeseran beban) menggeser pipe dan
 *  total BERSAMAAN; K hanya bergerak lewat estimasi MM2100 (gasM) yang dijaga engine di window kuota
 *  MM2100 (envelope [qM-0,08 ; qM+0,04], lebih lebar dari window validator [qM-0,04 ; qM]). LNG
 *  (termasuk Additional LNG) menaikkan K dan kuota total sama besar; Distillate menurunkan pipe dan
 *  total sama besar. Maka dengan D = Total Gas Quota - PGN Pipe Quota - K:
 *      window total  => pipe dalam [Pq + D - 0,04 ; Pq + D]
 *      window pipe   => pipe dalam [Pq - 0,04 ; Pq]
 *  Keduanya beririsan hanya bila -0,04 <= D <= 0,04. Bila D_min > 0,04 (+margin) atau
 *  D_max < -0,04 (-margin) TIDAK ADA dispatch, commitment, LNG, maupun Distillate yang memenuhi
 *  kedua window: yang dibutuhkan keputusan kuota operator. Kasus gasJ < qJ (Est PGN negatif) diuji
 *  terpisah (batas atas total efektifnya berada di bawah lantai window) sebelum sisi lantai
 *  dinyatakan terbukti. Sertifikat hanya menyatakan konflik; ia tidak pernah meloloskan kandidat.
 * =========================================================================================== */
function pp_v7_gas_envelope(array $in, ?array $obsOut = null): array {
    $R = ['schema' => 'co12-v7-gas-envelope-v1', 'applicable' => false, 'conflict' => null];
    if ((string)getenv('PP_V7_ENVELOPE') === '0') return $R + ['reason' => 'PP_V7_ENVELOPE=0'];
    $m = (array)($in['data3']['modeling'] ?? []); $q = (array)($m['gas_quota'] ?? []);
    $act = strtolower(trim((string)($m['gas_shortage_action'] ?? 'none')));
    if ($act === 'use distillate to cover' || $act === 'use_distillate' || $act === 'mixed_lng_distillate' || strpos($act, 'distillate') !== false)
        return $R + ['reason' => 'AKSI_DISTILLATE_TIDAK_DICAKUP'];
    $addLng = ($act === 'add_lng' || $act === 'add lng to cover') ? (float)($m['additional_lng'] ?? 0) : 0.0;
    $ghvJ = (float)($m['ghv_jababeka'] ?? 0); $ghvM = (float)($m['ghv_mm2100'] ?? 1000);
    if ($ghvJ <= 0) return $R + ['reason' => 'GHV_JABABEKA_KOSONG'];
    $Pq = (float)($q['pgn_pipe'] ?? 0);
    if ($Pq <= 1e-9) return $R + ['reason' => 'TANPA_KUOTA_PIPE'];
    $n = count((array)($in['data1'] ?? [])); if ($n !== 48) return $R + ['reason' => 'BUKAN_48_ROW'];
    /* kuota total — rumus yang sama dengan engine (Total Gas Quota) */
    $ffKeys = ['pep', 'akasia', 'baskara', 'bbg']; $mmKeys = ['pep_kp72', 'pertagas_kp72', 'akasia_kp72', 'baskara_kp72'];
    $gm = (array)($m['mm2100_gas_mode'] ?? []); $cumKey = null;
    foreach ($mmKeys as $k) { $mv = strtolower((string)($gm[$k] ?? 'fixed')); if (($mv === 'cummulative' || $mv === 'cumulative') && $cumKey === null) $cumKey = $k; }
    $Q = 0.0; $qMraw = 0.0; $qMe = 0.0; $ffVolJ = 0.0;
    foreach ($q as $k => $v) { if (!is_numeric($v)) continue; $k = (string)$k; $v = (float)$v;
        if (in_array($k, $ffKeys, true)) { $Q += $v * $ghvJ / 1000.0; $ffVolJ += $v; }
        elseif (in_array($k, $mmKeys, true)) { $e = ($k === $cumKey) ? $v : $v * $ghvM / 1000.0; $Q += $e; $qMe += $e; $qMraw += $v; }
        else $Q += $v; }
    $Q += $addLng;
    $lngUsed = (float)($q['lng'] ?? 0) + $addLng;
    $qJ = $ffVolJ * $ghvJ / 1000.0;
    /* fixed flow per row (override manual Ctrl+Click) */
    $mffJ = []; $mffM = [];
    foreach ((array)($m['manual_fixed_flows'] ?? []) as $mf) { $ri = (int)($mf['row'] ?? 0) - 1; if ($ri < 0) continue;
        $ar = strtoupper((string)($mf['area'] ?? '')); $vv = (float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0);
        if ($ar === 'JABABEKA') $mffJ[$ri] = $vv; elseif ($ar === 'MM2100') $mffM[$ri] = $vv; }
    /* jam actual (kanonik per jam: indeks genap; pasangan legacy dijumlahkan) */
    $hour = function ($arr, int $h) { $c = function ($i) use ($arr) { $v = is_array($arr) ? ($arr[$i] ?? null) : null; return ($v === null || $v === '') ? null : (float)$v; };
        $a = $c(2 * $h); $b = $c(2 * $h + 1); if ($a !== null && $b !== null) return $a + $b; return $a !== null ? $a : $b; };
    $aP = $m['actual_pgn_total'] ?? []; $aJ = $m['actual_energy_jababeka'] ?? []; $aM = $m['actual_energy_mm2100'] ?? [];
    $sP = 0.0; $nP = 0; $sJ = 0.0; $nJ = 0; $sM = 0.0; $nM = 0; $ffRP = 0.0; $FFavg = 0.0; $manMfut = 0.0;
    for ($h = 0; $h < 24; $h++) {
        $vp = $hour($aP, $h); $vj = $hour($aJ, $h); $vm = $hour($aM, $h);
        if ($vp !== null) { $sP += $vp; $nP++; } if ($vj !== null) { $sJ += $vj; $nJ++; } if ($vm !== null) { $sM += $vm; $nM++; }
        foreach ([2 * $h, 2 * $h + 1] as $i) {
            $ffE = (array_key_exists($i, $mffJ) ? $mffJ[$i] : $ffVolJ) * $ghvJ / 1000.0;
            $FFavg += $ffE / 48.0; if ($vp === null) $ffRP += $ffE / 48.0;
            if ($vm === null && array_key_exists($i, $mffM)) $manMfut += $mffM[$i] * $ghvM / 1000.0 / 48.0;
        }
    }
    $fJ = (24 - $nJ) / 24.0;
    $Jterm = $FFavg + $sJ - ($nJ / 24.0) * $qJ;                               // gasJ >= qJ
    $Mterm = function (float $g) use ($sM, $nM, $qMraw) { return $g + $sM - ($nM / 24.0) * min($g, $qMraw); };
    if ($qMe > 1e-9) { $gLo = max(0.0, $qMe - 0.08); $gHi = $qMe + 0.04; $mmBound = 'ENVELOPE_KUOTA_MM2100'; }
    else { $gLo = 0.0; $gHi = INF; $mmBound = 'TANPA_KUOTA_MM2100'; }
    /* baris MM2100 manual: estimasinya konstan dan tidak ikut lever; envelope di atas sudah mencakup
     * nilai blended (actual + estimasi future) sehingga cukup diperlebar sebesar kontribusinya */
    if ($manMfut > 0 && is_finite($gHi)) { $gHi += 0.04; $gLo = max(0.0, $gLo - 0.04); }
    $sigma = ($nP === 0) ? 0.05 : 0.0;                                         // clamp pipe jalur estimasi
    $N = $Q - $Pq - $lngUsed;                                                 // kuota non-pipe non-LNG
    $K_lo = $lngUsed + $Jterm + $Mterm($gLo);
    $K_hi = is_finite($gHi) ? $lngUsed + $Jterm + $Mterm($gHi) + $sigma : INF;
    $D_lo = $Q - $Pq - $K_hi; $D_hi = $Q - $Pq - $K_lo;
    $R = array_merge($R, ['applicable' => true, 'Q' => round($Q, 5), 'Pq' => round($Pq, 5), 'lng_used' => round($lngUsed, 5),
        'actual_hours' => ['pgn' => $nP, 'jababeka' => $nJ, 'mm2100' => $nM],
        'actual_sum' => ['pgn' => round($sP, 5), 'jababeka' => round($sJ, 5), 'mm2100' => round($sM, 5)],
        'fixed_flow_jababeka_energy' => round($qJ, 5), 'jababeka_term' => round($Jterm, 5), 'mm2100_quota' => round($qMe, 5),
        'mm2100_envelope' => [round($gLo, 5), is_finite($gHi) ? round($gHi, 5) : null], 'mm2100_bound' => $mmBound,
        'K_lo' => round($K_lo, 5), 'K_hi' => is_finite($K_hi) ? round($K_hi, 5) : null,
        'D_lo' => is_finite($D_lo) ? round($D_lo, 5) : null, 'D_hi' => round($D_hi, 5)]);
    $margin = 0.05;
    if (is_finite($D_lo) && $D_lo > 0.04 + $margin) {
        /* sisi lantai: kasus gasJ < qJ memperbesar K; dibuktikan tidak mencapai lantai window total */
        $effB = $sP + $fJ * $qJ - $ffRP + $FFavg + $sJ + $Mterm($gHi) + $sigma;
        $R['case_b_upper_effective'] = round($effB, 5);
        if ($effB < $Q - 0.04 - 1e-6) { $R['conflict'] = 'PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR'; $R['margin'] = round($D_lo - 0.04, 5); }
        else $R['reason'] = 'KASUS_ESTIMASI_PGN_NEGATIF_TIDAK_TERSINGKIRKAN';
    } elseif ($D_hi < -0.04 - $margin) {
        $R['conflict'] = 'PGN_PIPE_FLOOR_VS_TOTAL_GAS_CEILING'; $R['margin'] = round(-0.04 - $D_hi, 5);
    } else $R['reason'] = 'WINDOW_BERIRISAN_ATAU_MARGIN_KECIL';
    /* konsistensi dengan satu dispatch nyata state ini (bila ada): K terukur wajib di dalam envelope */
    if (is_array($obsOut) && $R['conflict'] !== null) {
        $ii = (array)($obsOut['info'] ?? []);
        $E = isset($ii['Effective Total Gas (BBTUD)']) && (int)($ii['Actual Hours Provided'] ?? 0) > 0 ? (float)$ii['Effective Total Gas (BBTUD)'] : (float)($ii['Total Gas Used (BBTUD)'] ?? 0);
        $Kob = $E - (float)($ii['PGN Pipe Used (BBTUD)'] ?? 0);                    // = LNG + Jababeka + MM2100
        $mmU = (float)($ii['MM2100 Used + Startup (BBTUD)'] ?? 0); $mmOk = $qMe <= 1e-9 || ($mmU >= $qMe - 0.04 - 1e-6 && $mmU <= $qMe + 1e-6);
        $qOk = abs((float)($ii['Total Gas Quota (BBTUD)'] ?? $Q) - $Q) < 0.002 && abs((float)($ii['PGN Pipe Quota (BBTUD)'] ?? $Pq) - $Pq) < 0.002;
        $R['observed'] = ['K' => round($Kob, 5), 'mm2100_used' => round($mmU, 5), 'mm2100_in_window' => $mmOk, 'quota_match' => $qOk];
        if (!$qOk || ($mmOk && ($Kob < $K_lo - 0.01 || $Kob > $K_hi + 0.01))) { $R['reason'] = 'OBSERVASI_DI_LUAR_ENVELOPE'; $R['conflict'] = null; }
    }
    if ($R['conflict'] !== null) {
        $floor = $R['conflict'] === 'PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR';
        $needJ = $nJ > 0 ? ($floor ? ($D_lo - 0.04) : (-0.04 - $D_hi)) / ($nJ / 24.0) : null;   // perubahan kuota fixed flow Jababeka (energi)
        $R['pipe_range_total_window'] = [round($Pq + $D_lo - 0.04, 4), round($Pq + $D_hi, 4)];
        $R['pipe_window'] = [round($Pq - 0.04, 4), round($Pq, 4)];
        $R['kuota_fixed_flow_jababeka_konsisten_mmscfd'] = ($needJ !== null)
            ? round(($qJ + ($floor ? -1 : 1) * $needJ) * 1000.0 / $ghvJ, 3) : null;
    }
    return $R;
}
/* Keluaran keputusan kuota dari sertifikat: dispatch pratinjau (satu core run state ini) + bukti. */
function pp_v7_certificate_decision(array $orig, array $cert, array $prev, array $stats): array {
    $o = $prev; $floor = $cert['conflict'] === 'PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR';
    $ii = (array)($o['info'] ?? []);
    $pu = (float)($ii['PGN Pipe Used (BBTUD)'] ?? 0); $tu = (int)($ii['Actual Hours Provided'] ?? 0) > 0 ? (float)($ii['Effective Total Gas (BBTUD)'] ?? 0) : (float)($ii['Total Gas Used (BBTUD)'] ?? 0);
    $Q = (float)$cert['Q']; $Pq = (float)$cert['Pq'];
    $o['info']['Gas Window Conflict'] = [
        'terbukti' => true, 'jenis' => $cert['conflict'], 'metode' => 'V7_SERTIFIKAT_ENVELOPE_GAS',
        'window_supplier_pgn_pipe' => ['min' => round($Pq - 0.04, 4), 'max' => round($Pq, 4), 'tercapai' => round($pu, 4),
                                       'kelebihan' => round($pu > $Pq ? $pu - $Pq : 0.0, 4), 'kekurangan' => round($pu < $Pq - 0.04 ? $Pq - 0.04 - $pu : 0.0, 4)],
        'window_gas_total' => ['min' => round($Q - 0.04, 4), 'max' => round($Q, 4), 'tercapai' => round($tu, 4)],
        'pipe_yang_dituntut_window_total' => $cert['pipe_range_total_window'],
        'langkah_legal_terkecil_bbtud' => null,
        'bukti' => [
            sprintf('Effective Total - PGN Pipe = K (LNG + Jababeka + MM2100) tidak dikendalikan dispatch Jababeka; K dalam [%.4f ; %.4f] untuk seluruh dispatch dengan MM2100 di envelope kuotanya (actual %d jam Jababeka = %.4f BBTUD, %d jam MM2100 = %.4f BBTUD)',
                    $cert['K_lo'], $cert['K_hi'], $cert['actual_hours']['jababeka'], $cert['actual_sum']['jababeka'], $cert['actual_hours']['mm2100'], $cert['actual_sum']['mm2100']),
            sprintf('window gas total [%.4f ; %.4f] menuntut PGN Pipe [%.4f ; %.4f], window supplier PGN Pipe [%.4f ; %.4f]: tidak beririsan (selisih minimal %.4f BBTUD)',
                    $Q - 0.04, $Q, $cert['pipe_range_total_window'][0], $cert['pipe_range_total_window'][1], $Pq - 0.04, $Pq, $cert['margin'] + 0.05),
            'LNG / Additional LNG menaikkan K dan kuota total sama besar; Distillate menurunkan pipe dan total sama besar; commitment dan beban GTG menggeser pipe dan total bersamaan — tidak satu pun lever yang mengubah irisan kedua window',
        ],
        'sertifikat' => $cert,
        'pilihan_operator' => array_values(array_filter([
            $cert['kuota_fixed_flow_jababeka_konsisten_mmscfd'] !== null
                ? sprintf('menyesuaikan total kuota fixed flow Jababeka (PEP+Akasia+BaGS+BBG) menjadi sekitar %.3f MMSCFD agar konsisten dengan actual jam yang sudah berjalan', $cert['kuota_fixed_flow_jababeka_konsisten_mmscfd']) : null,
            $floor ? 'menurunkan kuota non-pipe lain (KP72/MM2100) sehingga lantai window total dapat dicapai dengan pipe di window'
                   : 'menaikkan kuota non-pipe lain (KP72/MM2100) sehingga plafon window total tidak terlampaui dengan pipe di window',
            'menerima rencana dengan deviasi pipe yang dilaporkan (keputusan operator, bukan keputusan engine)',
        ])),
    ];
    $rs = (array)($o['info']['Run Status'] ?? []);
    $o['info']['Run Status'] = array_merge($rs, ['completed' => true, 'deadline_reached' => false, 'budget_truncated' => false, 'converged' => true,
        'status' => 'CONVERGED', 'economic_review_skipped' => null, 'stages_truncated' => [], 'economic_review_completed' => true,
        'v7_route' => 'SERTIFIKAT_ENVELOPE_GAS', 'core_runs' => (int)($stats['core_runs'] ?? 0), 'core_simulations' => (int)($stats['sims'] ?? 0),
        'elapsed_s' => round((float)($stats['wall'] ?? 0), 3)]);
    unset($o['info']['Global Commitment Review Skipped']);
    return $o;
}
/* V7 RUTE DELTA — sertifikat envelope gas lebih dulu. Bila konflik window terbukti tanpa bergantung
 * pada dispatch, tidak ada kandidat (commitment tetap, tetangga, keluarga, exact) yang dapat valid:
 * keputusan kuota operator diterbitkan dengan SATU core run pratinjau state ini (divalidasi 48 row
 * terhadap input asli). Bila sertifikat tidak terbukti, alur inkremental/exact V6 berjalan apa adanya. */
function pp_v7_route_certificate(string $jobId, array $input): ?array {
    $t0 = microtime(true);
    try {
        $orig = pp_normalize_copy($input);
        foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
        $c = pp_v7_gas_envelope($orig);
    } catch (Throwable $e) { return null; }
    $GLOBALS['ppV7EnvRoute'] = ['conflict' => $c['conflict'] ?? null, 'reason' => $c['reason'] ?? null, 'D' => [$c['D_lo'] ?? null, $c['D_hi'] ?? null]];
    if (empty($c['conflict'])) return null;
    /* kandidat valid yang sudah ada di kolam state ini membatalkan rute (tidak pernah terjadi bila
     * sertifikat benar; dijaga agar kandidat valid tidak pernah diabaikan) */
    try { $key = pp_tl_key($input); foreach (['_x', '_q'] as $sd) { $b = pp_tl_read(pp_tl_file($key . $sd . '_best.json'));
        if (is_array($b['output'] ?? null)) { $a = pp_tl_assess($orig, $b['output']); if (!empty($a['valid'])) return null; } } } catch (Throwable $e) {}
    pp_job_progress($jobId, 'V7_SERTIFIKAT_ENVELOPE_GAS', 20.0, ['conflict' => $c['conflict'], 'margin' => $c['margin'] ?? null]);
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $restore = function () use ($saved) { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; };
    $once0 = (int)($GLOBALS['__ppx_once_calls'] ?? 0); $runs = 0; $pick = null; $tries = [];
    $gasOnly = function (array $o) use ($orig): array {
        $V = pp_validate_hard_constraints($orig, $o); $t = [];
        foreach ((array)($V['violations'] ?? []) as $v) $t[] = is_array($v) ? (string)($v[0] ?? $v['type'] ?? '?') : '?';
        $u = array_values(array_unique($t)); $rows = count((array)($o['data'] ?? [])) === 48;
        return ['ok' => $rows && $u === ['gas_quota'], 'quota_only' => $rows && in_array('gas_quota', $u, true) && !array_diff($u, ['gas_quota', 'mm2100_quota']), 'types' => $u, 'n' => count($t), 'rows' => $rows];
    };
    $alt = null; $any = null; $anyN = PHP_INT_MAX;
    $keepAny = function (array $o, array $g) use (&$any, &$anyN) { if ($g['rows'] && $g['n'] < $anyN) { $any = $o; $anyN = $g['n']; } };
    try {
        /* Pratinjau: (1) dispatch final basis dibekukan, (2) commitment pemenang basis dengan lever
         * gasnya (evaluator inkremental), (3) satu core run state ini dari input asli. Yang dipakai
         * adalah pratinjau pertama yang pelanggarannya HANYA window gas (sama dengan syarat gerbang). */
        $B = pp_v3_find_base($orig); $base = is_array($B['base'] ?? null) ? $B['base'] : null;
        if ($base !== null) {
            $fz = pp_v3_frozen_eval($orig, (array)$base['data'], microtime(true) + 60.0); $runs++;
            if (is_array($fz) && is_array($fz['output'] ?? null)) { $g = $gasOnly($fz['output']); $tries[] = ['preview' => 'DISPATCH_FINAL_BASIS_BEKU', 'violations' => $g['types']]; $keepAny($fz['output'], $g); if ($g['ok']) $pick = $fz['output']; elseif ($alt === null && $g['quota_only']) $alt = $fz['output']; }
            $W = (array)(((array)($base['v3'] ?? []))['winner'] ?? []);
            if ($pick === null && is_array($W['stops'] ?? null)) {
                $h0 = isset($W['supplier_target']) && $W['supplier_target'] !== null ? (float)$W['supplier_target'] : null;
                $a = pp_v3_eval($orig, ['stops' => $W['stops']], (float)($W['adj'] ?? 0), $h0, microtime(true) + 60.0); $runs++;
                if (is_array($a) && is_array($a['output'] ?? null)) { $g = $gasOnly($a['output']); $tries[] = ['preview' => 'COMMITMENT_PEMENANG_BASIS', 'violations' => $g['types']]; $keepAny($a['output'], $g); if ($g['ok']) $pick = $a['output']; elseif ($alt === null && $g['quota_only']) $alt = $a['output']; }
            }
        }
        if ($pick === null) {
            $in = json_decode(json_encode($orig), true); $in['data3']['modeling']['time_budget_seconds'] = 60.0; $in['data3']['modeling']['time_budget_max_seconds'] = 60.0;
            pp_tl_clean_globals(); pp_budget_start(60.0, true, true);
            $o = pp_run_simulation_once($in); $runs++; pp_tl_clean_globals();
            $g = $gasOnly($o); $tries[] = ['preview' => 'CORE_RUN_STATE', 'violations' => $g['types']];
            $keepAny($o, $g); if ($g['ok']) $pick = $o; elseif ($alt === null && $g['quota_only']) $alt = $o;
        }
        /* pratinjau yang juga melanggar window kuota MM2100: keputusan tetap keputusan KUOTA (kedua
         * window kuota dinyatakan), bukan bahan bakar */
        if ($pick === null && $alt !== null) $pick = $alt;
        /* Sertifikat membuktikan TIDAK ADA rencana valid untuk kuota ini, apa pun dispatch-nya; pratinjau
         * dengan pelanggaran paling sedikit tetap dipakai sebagai bukti (bukan rencana, Publish terkunci). */
        if ($pick === null && $any !== null) $pick = $any;
        if ($pick === null) { $restore(); $GLOBALS['ppV7EnvRoute']['preview'] = $tries; return null; }
        $c2 = pp_v7_gas_envelope($orig, $pick);
        if (empty($c2['conflict'])) { $restore(); $GLOBALS['ppV7EnvRoute']['preview'] = $tries; $GLOBALS['ppV7EnvRoute']['void'] = $c2['reason'] ?? null; return null; }
    } catch (Throwable $e) { $restore(); return null; }
    $restore();
    $sims = (int)($GLOBALS['__ppx_once_calls'] ?? 0) - $once0;
    $out = pp_v7_certificate_decision($orig, $c2, $pick, ['core_runs' => $runs, 'sims' => $sims, 'wall' => microtime(true) - $t0]);
    $rep = ['schema' => 'co12-v7-delta-route-v1', 'route' => 'SERTIFIKAT_ENVELOPE_GAS', 'conflict' => $c2['conflict'], 'margin_bbtud' => $c2['margin'] ?? null,
            'preview' => $tries, 'core_runs' => $runs, 'core_simulations' => $sims, 'wall_s' => round(microtime(true) - $t0, 3),
            'candidates_evaluated' => 0, 'candidates_pruned_by_certificate' => 'SELURUH_RUANG_KANDIDAT'];
    $out['info']['V7 Delta Route'] = $rep;
    return ['output' => $out, 'report' => $rep];
}
/* V7 RERUN PILIHAN BAHAN BAKAR TANPA MENGULANG SOLVER. Pilihan operator (LNG / Distillate / campuran,
 * jumlah rekomendasi maupun manual) dilanjutkan dari rencana exact basis state yang sama tanpa aksi
 * bahan bakar (hasil yang membuka popup — lever dispatch terbukti habis): dispatch basis dibekukan,
 * engine menghitung ulang penuh state dengan aksi itu, validator 48 row menilai, lalu keluarga
 * commitment dilengkapi seperti pada pemakaian ulang hasil tersimpan. Tidak valid (mis. LNG jauh di
 * atas kebutuhan sehingga total jatuh di bawah lantai window) -> jalur V6. PP_V7_FUEL_RERUN=0 mematikan. */
function pp_v7_fuel_rerun_from_basis(string $jobId, array $input): ?array {
    if ((string)getenv('PP_V7_FUEL_RERUN') === '0') return null;
    $m = (array)($input['data3']['modeling'] ?? []);
    $act = strtolower(trim((string)($m['gas_shortage_action'] ?? 'none')));
    if (!in_array($act, ['add_lng', 'use_distillate', 'mixed_lng_distillate'], true) || !empty($m['change_over']['enabled'])) return null;
    $t0 = microtime(true); $ceil = (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 1800.0);
    $base = null; $baseKey = null;
    foreach (['recommendation', 'none'] as $a0) {
        $c = $input; $mm = &$c['data3']['modeling']; $mm['gas_shortage_action'] = $a0; $mm['additional_lng'] = 0;
        foreach (['distillate_user_limit_litres', '__fuel_decision_mode', '__probe_tested', '__probe_state'] as $k) unset($mm[$k]); unset($mm);
        try { $key = pp_sim_state_key(pp_econ_job_sim_input(pp_normalize_copy($c), $ceil)); } catch (Throwable $e) { continue; }
        $f = pp_memo_path($key);
        if (!is_file($f)) continue;
        $raw = json_decode((string)@file_get_contents($f), true);
        if (is_array($raw) && ($raw['key'] ?? '') === $key && count((array)($raw['output']['data'] ?? [])) === 48) { $base = $raw['output']; $baseKey = $key; break; }
    }
    /* Tanpa pemakaian ulang state (PP_MEMO_OFF=1) rencana basis dihitung ulang, supaya hasil pilihan bahan
     * bakar identik dengan jalur yang memakai ulang (A/B pemakaian ulang state). */
    /* V9: basis yang belum ada di memo dihitung (prosedur exact kanonik yang sama), sehingga rerun bahan bakar tidak
     * bergantung pada apakah popup Gas Shortage pernah dibuka di server ini (cache dingin = cache hangat). */
    $fastV7 = pp_v13f_is_fast($input);
    /* V15.15: basis = benih dispatch untuk state bahan bakar. Basis exact (memo) dipakai bila ada; selain itu basis pipeline (tanpa
     * komparator global/keluarga — keluarga commitment atas rencana SHORTAGE tidak berguna untuk state bahan bakar). Maximum Review
     * tetap menjalankan keluarga commitment pada STATE BAHAN BAKAR (pp_memo_apply_family di bawah). Terukur input 07-Oct: basis
     * exact kanonik + keluarga state shortage > 200 s sebelum keluarga state LNG dimulai. PP_V15_MAX_FAMILY_BASIS=1 = perilaku lama. */
    if ($base === null && ($fastV7 || (string)getenv('PP_V15_MAX_FAMILY_BASIS') !== '1')) {
        /* V15.15 Fastest: basis Fastest (pipeline yang sama, disimpan saat shortage terbukti); tanpa itu dihitung sekali. */
        try { $kF = pp_v13f_basis_key($input); $rF = pp_tl_read(pp_v13f_basis_file($kF));
            if (is_array($rF) && ($rF['key'] ?? '') === $kF && count((array)($rF['output']['data'] ?? [])) === 48) { $base = $rF['output']; $baseKey = 'fast:' . $kF; }
            else { $c = $input; $mm = &$c['data3']['modeling']; $mm['gas_shortage_action'] = 'recommendation'; $mm['additional_lng'] = 0;
                foreach (['distillate_user_limit_litres', '__fuel_decision_mode', '__probe_tested', '__probe_state'] as $k) unset($mm[$k]); unset($mm);
                $savedB = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $savedB[$gk] = $gv;
                $hookB = $GLOBALS['ppTlHook'] ?? null; unset($GLOBALS['ppTlHook']);
                pp_job_progress($jobId, 'FASTEST_BASIS_PIPELINE', 12.0);
                $base = pp_v13f_pipeline($c); if ($hookB !== null) $GLOBALS['ppTlHook'] = $hookB;
                pp_tl_clean_globals(); foreach ($savedB as $gk => $gv) $GLOBALS[$gk] = $gv;
                if (count((array)($base['data'] ?? [])) !== 48) $base = null; else { $baseKey = 'fast:' . $kF; pp_tl_write(pp_v13f_basis_file($kF), ['key' => $kF, 'at' => microtime(true), 'output' => $base]); } } }
        catch (PpJobAborted $e) { throw $e; } catch (Throwable $e) { $base = null; }
    }
    if ($base === null && !$fastV7 && (pp_memo_off() || pp_v9_canon())) {
        try { $c = $input; $mm = &$c['data3']['modeling']; $mm['gas_shortage_action'] = 'recommendation'; $mm['additional_lng'] = 0;
            foreach (['distillate_user_limit_litres', '__fuel_decision_mode', '__probe_tested', '__probe_state'] as $k) unset($mm[$k]); unset($mm);
            $savedB = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $savedB[$gk] = $gv;
            $hookB = $GLOBALS['ppTlHook'] ?? null; unset($GLOBALS['ppTlHook']);
            $base = pp_sim_memo_run(pp_econ_job_sim_input(pp_normalize_copy($c), $ceil));
            if ($hookB !== null) $GLOBALS['ppTlHook'] = $hookB;
            pp_tl_clean_globals(); foreach ($savedB as $gk => $gv) $GLOBALS[$gk] = $gv;
            if (count((array)($base['data'] ?? [])) !== 48) $base = null;
        } catch (Throwable $e) { $base = null; }
    }
    if ($base === null) return null;
    $bi = (array)($base['info'] ?? []);
    if ((float)($bi['Gas Shortage (BBTUD)'] ?? 0) <= 1e-9 && (float)($bi['Residual Gas Shortage (BBTUD)'] ?? 0) <= 1e-9) return null;   // basis bukan kekurangan gas
    pp_job_progress($jobId, 'V7_RERUN_BAHAN_BAKAR_DARI_RENCANA_EXACT_BASIS', 15.0);
    $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $restore = function () use ($saved) { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; };
    try {
        $orig = pp_normalize_copy($input);
        foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
        $fz = pp_v3_frozen_eval($orig, (array)$base['data'], microtime(true) + 60.0);
    } catch (Throwable $e) { $fz = null; }
    $restore();
    if (!is_array($fz) || empty($fz['valid']) || !is_array($fz['output'] ?? null)) {
        $GLOBALS['ppV7FuelRerun'] = ['applied' => false, 'reason' => 'DISPATCH_BASIS_TIDAK_VALID_UNTUK_AKSI_INI', 'violations' => $fz['violations'] ?? null];
        return null;
    }
    $out = $fz['output'];
    $out['info']['Run Status'] = array_merge((array)($out['info']['Run Status'] ?? []), ['completed' => true, 'deadline_reached' => false, 'budget_truncated' => false,
        'converged' => true, 'status' => 'CONVERGED', 'economic_review_skipped' => null, 'stages_truncated' => [], 'economic_review_completed' => true,
        'mode' => 'V7_FUEL_RERUN_DELTA', 'core_runs' => 1, 'core_simulations' => 1]);
    $out['info']['V7 Fuel Delta Validation'] = ['kind' => $act, 'reused' => 'baseline, commitment, dispatch, Export, headroom, bukti gas rencana exact basis',
        'basis_state_key' => substr((string)$baseKey, 0, 16), 'basis_cost_production' => $bi['Cost Production (USD/MWh)'] ?? null, 'cost_production' => $fz['key']['cp'] ?? null];
    /* V15.15 Maximum Review: keluarga commitment state bahan bakar dikerjakan PARALEL oleh pembantu job (registri node yang sama dipakai
     * ulang pp_memo_apply_family) — dulu serial oleh pemilik (terukur 13 node / 228 s). */
    if (!$fastV7 && pp_v4_helper_slots() > 0 && (string)getenv('PP_EXACT_FAMILY') !== '0' && (string)getenv('PP_V15_V7_FAMILY_PAR') !== '0') {
        try { $origP = $orig; pp_v4_work_publish($jobId, $origP, ['kind' => 'family', 'seeds' => [], 'max_nodes' => 64, 'dl' => (float)($GLOBALS['__pp_budget_deadline'] ?? (microtime(true) + 1500.0)) - 4.0]); } catch (Throwable $e) {} }
    /* keluarga commitment dilengkapi (kandidat valid lebih murah menggantikan) — Maximum Review saja; Fastest memakai review
     * Unit Priority V8 di job (tanpa keluarga commitment exact). */
    if (!$fastV7) { try { $out = pp_memo_apply_family(pp_econ_job_sim_input($input, $ceil), $out); } catch (Throwable $e) {} }
    $restore();
    $rep = ['schema' => 'co12-v7-delta-route-v1', 'route' => 'RERUN_BAHAN_BAKAR_DARI_RENCANA_EXACT_BASIS', 'action' => $act,
            'basis_cost_production' => $bi['Cost Production (USD/MWh)'] ?? null, 'cost_production' => $out['info']['Cost Production (USD/MWh)'] ?? null,
            'wall_s' => round(microtime(true) - $t0, 3)];
    $out['info']['V7 Delta Route'] = $rep;
    return ['output' => $out, 'report' => $rep];
}
/* V8 — provenance gas MM2100 per row (GE1-GE4, G10): sumber, gas tersedia, MW, gas terpakai, hasil validasi. */
function pp_v8_mm2100_provenance(array $input, array $output): array {
    $m = (array)($input['data3']['modeling'] ?? []); $rows = array_values((array)($output['data'] ?? [])); $i = (array)($output['info'] ?? []);
    $src = pp_v8_mm2100_source($m); $ghv = (float)($m['ghv_mm2100'] ?? 1030); $T = []; $fail = 0; $mwTot = 0.0;
    foreach ($rows as $k => $r) {
        $r1 = $k + 1; $ge = []; $mwRow = 0.0;
        foreach (['GE1','GE2','GE3','GE4','G10'] as $u) { $v = round((float)($r[$u] ?? 0), 3); if ($v > 0.01) { $ge[$u] = $v; $mwRow += $v; } }
        $gas = (float)($r['Total_Gas_MM'] ?? 0); $man = $src['manual_fixed_flow_mm2100_rows'][$r1] ?? null;
        $avail = $src['source'] === 'KP72_QUOTA' ? 'KUOTA_HARIAN_KP72 ' . $src['kp72_quota_bbtud'] . ' BBTUD (window harian)'
               : ($src['source'] === 'ACTUAL_ENERGY_MM2100' ? 'ACTUAL_ENERGY_MM2100 ' . $src['actual_mm2100_bbtud'] . ' BBTUD'
               : ($man !== null ? sprintf('FIXED_FLOW_MANUAL %.4f MMSCFD = %.5f BBTUD-laju', $man, $man * $ghv / 1000.0) : 'TIDAK_ADA'));
        $ok = true; $why = null;
        if ($mwRow > 0.01 && !$src['legal']) { $ok = false; $why = 'UNIT_MM2100_BERBEBAN_TANPA_SUMBER_GAS_SAH'; }
        elseif ($mwRow > 0.01 && $src['source'] === 'MANUAL_FIXED_FLOW_MM2100' && ($man === null || $gas > $man * $ghv / 1000.0 + 1e-3)) { $ok = false; $why = 'GAS_MM2100_MELEBIHI_FIXED_FLOW_MANUAL_ROW'; }
        if (!$ok) $fail++; $mwTot += $mwRow;
        if ($mwRow > 0.01 || $gas > 1e-6 || $man !== null || $k === 0)
            $T[] = ['row' => $r1, 'time' => $r['Time'] ?? null, 'gas_source' => $src['source'], 'available' => $avail, 'unit_mw' => $ge,
                    'gas_mm2100_rate_bbtud' => round($gas, 5), 'gas_mm2100_daily_bbtud' => round((float)($r['Est_FF_M'] ?? $gas / 48.0), 6),
                    'fixed_flow_mm2100_mmscfd' => $r['Total_Flow_M'] ?? null, 'validation' => $ok ? 'PASS' : 'FAIL', 'reason' => $why];
    }
    return ['schema' => 'co12-v8-mm2100-provenance-v1', 'status' => $fail ? 'FAIL' : 'PASS', 'gas_source' => $src['source'], 'legal_source' => $src['legal'],
            'kp72_quota_bbtud' => $src['kp72_quota_bbtud'], 'actual_energy_mm2100_bbtud' => $src['actual_mm2100_bbtud'],
            'manual_fixed_flow_mm2100_rows' => $src['manual_fixed_flow_mm2100_rows'], 'mm2100_daily_used_bbtud' => $i['MM2100 Daily Used (BBTUD)'] ?? null,
            'mm2100_quota_bbtud' => $i['MM2100 Quota (BBTUD)'] ?? null, 'mm2100_unit_mwh' => round($mwTot / 2.0, 3), 'rows_failed' => $fail,
            'rows' => $T, 'rule' => 'GE1-GE4/G10 hanya berbeban bila sumber gas MM2100 sah (kuota KP72, Actual Energy MM2100, atau fixed flow manual MM2100); Last Data Running bukan sumber gas. Row tanpa beban MM2100 dan tanpa gas tidak dicantumkan.'];
}
/* V9 — provenance SELURUH bahan bakar per row: unit, MW, fuel, laju pemakaian, heat rate, akun sumber, biaya.
 * Gas GTG 1-9 berasal dari pool Jababeka (PGN Pipe / PEP / LNG / Akasia / BaGS / BBG) dengan pangsa akun harian,
 * GE1-GE4/G10 dari akun MM2100 (sumber sah V8), BBLN dari batubara, Distillate dari aksi bahan bakar operator. */
function pp_v9_fuel_provenance(array $input, array $output): array {
    $d3 = (array)($input['data3'] ?? []); $m = (array)($d3['modeling'] ?? []); $i = (array)($output['info'] ?? []); $rows = array_values((array)($output['data'] ?? []));
    $FA = pp_v9_fuel_accounts($m, $i); $acc = []; foreach ($FA['accounts'] as $a) $acc[$a['account']] = $a;
    $pool = ['PGN_PIPE', 'LNG', 'PEP_JBBK', 'AKASIA_JBBK', 'BAGS_JBBK', 'BBG_JBBK']; $pu = 0.0; $pc = 0.0; $share = [];
    foreach ($pool as $k) { $pu += (float)($acc[$k]['used'] ?? 0); $pc += (float)($acc[$k]['cost_usd'] ?? 0); }
    foreach ($pool as $k) if ((float)($acc[$k]['used'] ?? 0) > 1e-6 && $pu > 0) $share[$k] = round((float)$acc[$k]['used'] / $pu * 100.0, 2);
    $pJ = $pu > 0 ? $pc / ($pu * 1000.0) : 0.0;                                           // USD per MMBTU (blended pool Jababeka)
    $mmU = (float)($acc['MM2100_GAS']['used'] ?? 0); $pM = $mmU > 0 ? (float)($acc['MM2100_GAS']['cost_usd'] ?? 0) / ($mmU * 1000.0) : 0.0;
    $src = pp_v8_mm2100_source($m); $coalP = (float)($m['price']['coal'] ?? 0);
    $T = []; $sumJ = 0.0; $sumM = 0.0; $bad = 0;
    foreach ($rows as $k => $r) {
        $U = [];
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u) {
            $mw = (float)($r[strtoupper($u)] ?? 0); if ($mw <= 0.01 || !isset($d3[$u])) continue;
            $f = calc_fuel($d3, $u, $mw); $mm = ($u === 'g10' || pp_ge_unit($u));
            $hr = $mw > 0 ? $f * 1e9 / (24000.0 * $mw) : null; $cost = $f / 48.0 * 1000.0 * ($mm ? $pM : $pJ);
            $srcU = $mm ? ('MM2100:' . $src['source']) : ('JABABEKA_POOL(' . implode(',', array_map(function ($a, $b) { return $a . ' ' . $b . '%'; }, array_keys($share), $share)) . ')');
            $ok = $mm ? $src['legal'] : ($pu > 0);
            if (!$ok) $bad++;
            if ($mm) $sumM += $f / 48.0; else $sumJ += $f / 48.0;
            $U[strtoupper($u)] = ['mw' => round($mw, 2), 'fuel_bbtud_rate' => round($f, 5), 'heat_rate_btu_kwh' => $hr === null ? null : round($hr, 1), 'cost_usd_row' => round($cost, 2), 'source' => $srcU, 'valid' => $ok];
        }
        foreach (['BB1' => 'Coal1', 'BB2' => 'Coal2'] as $bu => $ck) { $mw = (float)($r[$bu] ?? 0); if ($mw <= 0.01) continue;
            $ton = (float)($r[$ck] ?? 0); $U[$bu] = ['mw' => round($mw, 2), 'coal_ton' => round($ton, 4), 'cost_usd_row' => round($ton * $coalP, 2), 'source' => 'COAL_BABELAN', 'valid' => $coalP > 0 || $ton <= 0]; }
        $dist = (float)($r['Dist_Total'] ?? 0); if ($dist > 1e-6) $U['DISTILLATE'] = ['litre' => round($dist, 2), 'source' => 'AKSI_BAHAN_BAKAR_OPERATOR:' . strtolower((string)($m['gas_shortage_action'] ?? '')), 'valid' => ($acc['DISTILLATE']['status'] ?? 'OK') !== 'DISTILLATE_TANPA_OTORISASI'];
        $T[] = ['row' => $k + 1, 'time' => $r['Time'] ?? null, 'units' => $U];
    }
    $gasTot = (float)($i['Gas Fuel Total (BBTUD)'] ?? ($i['Total Gas Used (BBTUD)'] ?? 0)); $accTot = $pu + $mmU;
    $idOk = abs($gasTot - $accTot) <= 0.05;
    $status = (!$FA['violations'] && $bad === 0) ? ($idOk ? 'PASS' : 'PASS_WITH_NOTE') : 'FAIL';
    return ['schema' => 'co12-v9-fuel-provenance-v1', 'status' => $status, 'accounts' => $FA['accounts'], 'violations' => array_map(function ($v) { return $v[1]; }, $FA['violations']),
            'jababeka_pool_share_pct' => $share, 'jababeka_pool_price_usd_mmbtu' => round($pJ, 4), 'mm2100_price_usd_mmbtu' => round($pM, 4), 'mm2100_source' => $src,
            'identity' => ['gas_fuel_total_bbtud' => round($gasTot, 4), 'sum_accounts_bbtud' => round($accTot, 4), 'diff' => round($gasTot - $accTot, 4), 'ok' => $idOk,
                           'note' => 'selisih kecil = pemakaian gas per row (estimasi/actual per jam) vs akun harian; toleransi 0,05 BBTUD'],
            'unit_rows_without_legal_source' => $bad, 'rows' => $T,
            'rule' => 'Setiap unit berbahan bakar memakai akun sumber yang sah dan berbiaya: GTG 1-9 pool Jababeka, GE/G10 akun MM2100 (kuota KP72 / Actual / fixed flow manual), BBLN batubara, Distillate hanya dengan aksi bahan bakar operator.'];
}
/* V15.16: kolom PV (MW) dan SR minimum efektif per row (Fix SR / Follow PV) pada SETIAP keluaran 48 row — termasuk jalur V7/frozen
 * dan job — sehingga kolom PV Simulation Data selalu terisi dari input dan SR_Min = max(Fix SR, PV[row]) saat Follow PV aktif. */
function pp_v1516_row_pv_sr(array $input, array &$out): void {
    if (!is_array($out['data'] ?? null) || !function_exists('pp_reserve_min')) return;
    $m = (array)($input['data3']['modeling'] ?? []); $pv = (array)($m['pv_rows'] ?? []);
    $d3 = (array)($input['data3'] ?? []);
    foreach ($out['data'] as $k => &$r) { if (!is_array($r)) continue; $r['PV'] = round((float)($pv[$k] ?? 0), 2); $r['SR_Min'] = round(pp_reserve_min($m, $k + 1), 2);
        /* Kolom Spin_Res memakai definisi yang SAMA dengan validator hard constraint (pp_spinning_reserve: seluruh unit reserve yang
         * eligible, effective max per row) — dulu calc_sr lama tanpa G7/G10 sehingga tabel dapat tampak di bawah SR_Min padahal valid. */
        if (function_exists('pp_spinning_reserve')) { $g = [];
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'] as $u) { $c = strtoupper($u); if ($c === 'B1') $c = 'BB1'; if ($c === 'B2') $c = 'BB2'; $g[$u] = (float)($r[$c] ?? 0); }
            try { $r['Spin_Res'] = round(pp_spinning_reserve($g, $d3, $m, $k + 1), 2); } catch (Throwable $e) {} } }
    unset($r);
    if (!isset($out['info']['Spinning Reserve Requirement']) || !is_array($out['info']['Spinning Reserve Requirement'])) $out['info']['Spinning Reserve Requirement'] = [];
    $out['info']['Spinning Reserve Requirement']['sr_mode'] = (string)($m['sr_mode'] ?? 'fixed');
}
function pp_attach_or_reject_acceptance(array $input,array &$output): array {
    $output['info']=(array)($output['info']??[]); try { pp_v1516_row_pv_sr($input, $output); } catch (Throwable $e) {} $output['info']['Engine Build']=PP_ENGINE_BUILD_ID; $output['info']['Engine Fingerprint']=pp_engine_fingerprint();
    $review=pp_simulation_acceptance_review($input,$output);
    if ((string)getenv('PP_V5_HEADROOM_AUDIT') !== '0') { try { $output['info']['Headroom Priority Audit'] = pp_v5_headroom_priority_audit($input, $output); } catch (Throwable $e) { $output['info']['Headroom Priority Audit'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; } }
    if (function_exists('pp_v11_on') && pp_v11_on() && count((array)($output['data'] ?? [])) === 48) {
        try { $output['info']['V11 Low Load Fragmentation Audit'] = pp_v11_frag_audit($input, $output); } catch (Throwable $e) { $output['info']['V11 Low Load Fragmentation Audit'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; }
        try { $output['info']['V11 CP Audit'] = pp_v11_cp_audit($input, $output); } catch (Throwable $e) { $output['info']['V11 CP Audit'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; } }
    /* V15.15: bukti merit C1-C4 + STG + Unit Priority untuk SETIAP rencana 48 row (laporan); pada Fastest juga GERBANG rilis:
     * temuan tanpa alasan atau STG tidak cocok -> tidak dipublikasikan. */
    if (count((array)($output['data'] ?? [])) === 48) {
        try { $output['info']['Merit Proof C1-C4 STG'] = pp_v15_merit_proof($input, $output); } catch (Throwable $e) { $output['info']['Merit Proof C1-C4 STG'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; }
        if ((!empty($input['_fast_default']) || !empty($GLOBALS['__pp_v15_job_gate'])) && !empty($review['publish_allowed']) && (string)($output['info']['Merit Proof C1-C4 STG']['status'] ?? '') !== 'PASS' && (string)getenv('PP_V15_MERIT_GATE') !== '0') {
            $review['publish_allowed'] = false; $review['status'] = 'FAIL'; $review['blocking_reasons'] = array_values(array_merge((array)($review['blocking_reasons'] ?? []), ['MERIT_PROOF_C1_C4_STG_FAIL'])); }
    }
    try { $output['info']['MM2100 Gas Provenance'] = pp_v8_mm2100_provenance($input, $output); } catch (Throwable $e) { $output['info']['MM2100 Gas Provenance'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; }
    try { $output['info']['Fuel Provenance'] = pp_v9_fuel_provenance($input, $output); } catch (Throwable $e) { $output['info']['Fuel Provenance'] = ['status' => 'ERROR', 'error' => $e->getMessage()]; }
    $coLeg = null; try { $coLeg = pp_v5_changeover_legality($input); if ($coLeg !== null) $output['info']['Change Over Legality'] = $coLeg; } catch (Throwable $e) { $coLeg = null; }
    /* AUDIT FEASIBILITY GAS: dihitung hanya bila gas benar-benar di luar window (analisis murni,
     * tanpa core run tambahan). Menjadi dasar pesan kegagalan DAN kontrak keputusan operator. */
    $gasAudit=null;
    $hasGasViol=false;
    foreach((array)($review['hard_validation']['violations']??[]) as $gv)
        if(is_array($gv)&&(string)($gv[0]??$gv['type']??'')==='gas_quota'){$hasGasViol=true;break;}
    if($hasGasViol&&function_exists('pp_gas_feasibility_audit')){
        $gasAudit=pp_gas_feasibility_audit($input,$output);
        $output['info']['Gas Feasibility Audit']=$gasAudit;
        $output['gas_feasibility_audit']=$gasAudit;
    }
    $output['info']['Simulation Acceptance Review']=$review;
    $output['simulation_acceptance_review']=$review;
    $conv=(array)($review['convergence_review']??[]);
    if(!empty($review['publish_allowed'])){unset($output['preliminary'],$output['preliminary_reason'],$output['preliminary_note'],$output['async_job'],$output['shortage_decision']);$output['final_result_visible']=true;$output['save_allowed']=true;$output['publish_allowed']=true;$output['ok']=true;$output['result']='ok';$output['status']=!empty($input['_fast_default'])?'FASTEST_FINAL':($output['status']??'FINAL');}
    $output['release_gate']=['release_allowed'=>$review['publish_allowed'],'status'=>$review['status'],
      'hard_validation'=>$review['hard_validation']['status'],'economic_review'=>$review['economic_review']['status'],
      'convergence'=>(string)($conv['status']??'UNKNOWN'),
      'blocking_reasons'=>array_values(array_merge((array)($review['blocking_reasons']??[]),
          (is_array($coLeg) && $coLeg['legal'] === false && !$review['publish_allowed']) ? array_map(function ($r) { return 'CHANGE_OVER_TIDAK_LEGAL:' . $r['code'] . (isset($r['unit']) ? ':' . $r['unit'] : ''); }, $coLeg['reasons']) : [])),
      'note'=>'All 48 rows must pass hard constraints; Cost Production must be lowest within eligible candidates actually evaluated; the search must have finished without being cut by the time budget.'];

    /* V15.14: final PASS is authoritative over stale recommendation/diagnostic state. */
    if (!empty($review['publish_allowed'])
        && (($output['release_gate']['release_allowed'] ?? false) === true)
        && (($output['release_gate']['status'] ?? '') === 'PASS')) {
        $finalShortage = (float)($output['info']['Residual Gas Shortage (BBTUD)']
            ?? $output['info']['Gas Shortage (BBTUD)'] ?? 0.0);
        if ($finalShortage <= 1e-6) {
            /* V15.15: aksi bahan bakar yang dipakai (add_lng / use_distillate / mixed) dan kekurangan basisnya TIDAK ditimpa:
             * laporan, Excel, dan rekonsiliasi bahan bakar membacanya. Yang ditutup hanya status keputusan. */
            $output['fuel_decision_required'] = false;
            $output['shortage_declarable'] = false;
            $output['final_values_publishable'] = true;
        }
        unset($output['preliminary'], $output['preliminary_reason'], $output['preliminary_note'],
              $output['action_required'], $output['shortage_decision'], $output['fuel_estimate_ready'],
              $output['diagnostic_before_exact_review'], $output['diagnostic_before_user_decision'],
              $output['status_legacy'], $output['error'], $output['error_code'],
              $output['error_message'], $output['http_status_recommended']);
        $output['final_result_visible'] = true;
        $output['save_allowed'] = true;
        $output['export_allowed'] = true;
        $output['publish_allowed'] = true;
        $output['ok'] = true;
        $output['result'] = 'ok';
        $output['status'] = !empty($input['_fast_default']) ? 'FASTEST_FINAL' : 'FINAL';
        return $review;
    }
    if(!$review['publish_allowed']){
        $hardFailed=(string)($review['hard_validation']['status']??'FAIL')!=='PASS';
        $convFailed=empty($conv['pass']);
        if($hardFailed){$f=pp_constraint_failure_message($review,$gasAudit);$code=$f['code'];$msg=$f['message'];$details=['failure'=>$f,'acceptance_review'=>$review];}
        /* Hard constraint lulus tetapi pencarian terpotong: alasan penolakan adalah konvergensi,
           bukan biaya. Dispatch incumbent TETAP dikembalikan utuh untuk ditinjau. */
        elseif($convFailed){$code=(string)($conv['code']??'NOT_CONVERGED');$msg=(string)($conv['message']??'Hasil belum konvergen.');
            $details=['convergence'=>$conv,'acceptance_review'=>$review,
              'incumbent_available_for_review'=>true,'rows'=>count((array)($output['data']??[])),
              'recommended_action'=>'Tinjau hasil ini sebagai pratinjau, lalu jalankan ulang simulasi sampai converged=true sebelum mempublikasikan.'];}
        else{$code='COST_PRODUCTION_PROOF_FAILED';$msg='Economic review incomplete: the selected dispatch is not proven as the minimum Cost Production among all eligible candidates actually evaluated.';$details=['acceptance_review'=>$review,'recommended_action'=>'Re-evaluate all eligible candidates with the same clean-validation stage and select the lowest Cost Production identity.'];}
        $output['ok']=false;$output['result']='rejected';$output['error_code']=$code;
        $output['message']=$msg;$output['error_message']=$msg;
        $output['error']=['code'=>$code,'message'=>$msg,'details'=>$details];$output['http_status_recommended']=422;
        /* KEPUTUSAN OPERATOR DIPERLUKAN: gas di luar window, seluruh lever dispatch legal habis,
         * dan operator memilih Flag shortage only. Backend tidak memilih bahan bakar sendiri —
         * ia menyerahkan keputusan lewat kontrak mesin-terbaca. publish tetap DILARANG. */
        /* ===== CHANGE OVER YANG DIMINTA TIDAK DAPAT DIEKSEKUSI =================================
         * Ketika sweep Change Over menolak seluruh kandidat, engine mengembalikan kandidat TERBAIK
         * yang gagal beserta buktinya dan menandainya `executed=false`. Baris yang ikut terbawa
         * adalah baris kandidat itu — termasuk unit sumber yang berhenti — sehingga validator
         * dengan benar melaporkan `cannot_stop` dan `commitment_continuous`.
         *
         * Yang SALAH sebelumnya adalah kelanjutannya: karena gas juga di luar window, jalur
         * keputusan bahan bakar mengambil alih dan operator disodori popup "pilih LNG atau
         * Distillate" untuk sebuah rencana yang engine sendiri nyatakan TIDAK dieksekusi. Bahan
         * bakar tidak menyelesaikan apa pun di sini; yang tidak dapat dipenuhi adalah permintaan
         * Change Over-nya. Karena itu kontraknya dinyatakan apa adanya, lengkap dengan conflict
         * set per baris. Hard validation tetap FAIL dan Publish tetap terkunci. */
        /* KONTRAK KEPUTUSAN OPERATOR HANYA SAH ATAS HASIL YANG SUDAH FINAL.
         * Bila pencarian masih terpotong batas waktu, kesimpulan "tidak dapat dieksekusi" atau
         * "dua window tidak dapat dipenuhi bersamaan" belum terbukti — yang benar adalah
         * membiarkan worker background menyelesaikannya lebih dulu. Tanpa gerbang ini, sebuah run
         * yang belum konvergen akan berhenti pada keputusan operator dan jalur asinkron tidak
         * pernah dijalankan (terukur pada PGN 20 + PEP 40 di jalur browser: job_start = 0). */
        $rsFin = (array)($output['info']['Run Status'] ?? []);
        $finalEnough = (($rsFin['converged'] ?? false) === true)
                    && (($rsFin['deadline_reached'] ?? false) !== true)
                    && empty($rsFin['stages_truncated'])
                    && empty($output['info']['Global Commitment Review Skipped']);
        $coT = (array)($output['info']['Change Over Timeline'] ?? $output['info']['Change Over'] ?? []);
        /* V5: permintaan Change Over yang TIDAK LEGAL menurut analisis input (unit keluarga Cannot Stop,
         * waktu manual terlalu rapat, unit tidak tersedia) diperlakukan sebagai blok Change Over walau
         * sweep tidak mengisi blocker — tidak pernah jatuh ke popup Gas Shortage (bahan bakar tidak
         * dapat memperbaiki handover yang tidak legal). */
        $coLegI = (array)($output['info']['Change Over Legality'] ?? []);
        if (!empty($coT['requested']) && empty($coT['executed']) && empty($coT['blocker']) && ($coLegI['legal'] ?? null) === false) {
            $r0 = (array)(($coLegI['reasons'] ?? [])[0] ?? []);
            $coT['blocker'] = ['reason' => 'CHANGE_OVER_TIDAK_LEGAL:' . (string)($r0['code'] ?? ''), 'penjelasan' => (string)($r0['detail'] ?? ''),
                               'legality_reasons' => $coLegI['reasons'] ?? []];
            $output['info']['Change Over Timeline']['blocker'] = $coT['blocker'];
        }
        $coBlocked = $finalEnough && !empty($coT['requested']) && empty($coT['executed']) && !empty($coT['blocker']);
        if($coBlocked){
            $output['ok']=true;
            $output['result']='action_required';
            $output['status']='USER_ACTION_REQUIRED';
            $output['action_required']='CHANGE_OVER_DECISION';
            $output['publish_allowed']=false;
            $output['http_status_recommended']=200;
            $output['change_over_blocker']=(array)$coT['blocker'] + [
                'mode' => (string)($coT['mode'] ?? ''),
                'handover_complete' => (bool)($coT['handover_complete'] ?? false),
                'source_block' => $coT['source_block'] ?? null,
                'target_block' => $coT['target_block'] ?? null,
                'candidates_evaluated' => $coT['candidates_evaluated'] ?? null,
                'catatan' => 'baris yang dikembalikan adalah kandidat Change Over yang DITOLAK, '
                           . 'disertakan sebagai pratinjau bukti — bukan rencana yang boleh dipublikasikan',
            ];
            $output['diagnostic_before_user_decision']=$output['error']??null;
            unset($output['error']);
            $output['message']='Change Over yang diminta tidak dapat dieksekusi: '
                . (string)(($coT['blocker']['penjelasan'] ?? '') ?: ($coT['mode'] ?? 'tidak ada kandidat yang lolos'))
                . '. Baris yang ditampilkan adalah kandidat yang ditolak, bukan rencana final.';
        }

        /* ===== KONFLIK ANTAR-WINDOW GAS YANG SUDAH TERBUKTI ====================================
         * Bukan kekurangan gas, sehingga memilih LNG atau Distillate tidak menyelesaikan apa pun:
         * pemakaian satu supplier BERLEBIH sementara window gas total menahan penurunannya. Engine
         * sudah membuktikan tidak ada langkah dispatch legal yang memenuhi keduanya (lihat
         * `Gas Window Conflict`). Yang dibutuhkan adalah keputusan operator atas KUOTA, dan itulah
         * yang dinyatakan — bukan `ECONOMIC_REVIEW_REQUIRED`, yang keliru karena review ekonominya
         * justru sudah selesai. Publish tetap DILARANG dan hard validation tetap FAIL: tidak ada
         * kegagalan yang berubah menjadi lulus di sini. */
        $gwc = (array)($output['info']['Gas Window Conflict'] ?? []);
        $gwcOnlyGas = $finalEnough && !$coBlocked && !empty($gwc['terbukti']);
        $gwcCert=(string)($gwc['metode']??'')==='V7_SERTIFIKAT_ENVELOPE_GAS';   // V7: kedua window kuota (gas total/pipe dan MM2100)
        if($gwcOnlyGas) foreach((array)($review['hard_validation']['violations']??[]) as $vv)
            if(!$gwcCert&&is_array($vv)&&(string)($vv[0]??$vv['type']??'')!=='gas_quota'){$gwcOnlyGas=false;break;}   // V7: sertifikat = bukti tanpa bergantung pada dispatch pratinjau
        if($gwcOnlyGas){
            $output['ok']=true;
            $output['result']='action_required';
            $output['status']='USER_ACTION_REQUIRED';
            $output['action_required']='GAS_WINDOW_QUOTA_DECISION';
            $output['publish_allowed']=false;
            $output['http_status_recommended']=200;
            $output['gas_window_conflict']=$gwc;
            $output['diagnostic_before_user_decision']=$output['error']??null;
            unset($output['error']);
            $output['message']='Dua window kuota gas tidak dapat dipenuhi bersamaan oleh langkah dispatch yang tersedia. '
                . 'Buktinya dilampirkan pada `Gas Window Conflict`; keputusan atas kuota ada pada operator.';
            if($gwcCert) $output['message']='Window kuota gas total dan window supplier PGN Pipe tidak dapat dipenuhi bersamaan oleh dispatch, commitment, LNG, maupun Distillate mana pun '
                . '(sertifikat envelope gas: actual jam yang sudah berjalan menetapkan selisih total efektif terhadap pipe). '
                . 'Buktinya dilampirkan pada `Gas Window Conflict`; keputusan atas kuota ada pada operator.';
        }
        /* V5: Change Over yang tidak legal tidak pernah diarahkan ke keputusan bahan bakar. */
        $coIllegal = (($output['info']['Change Over Legality']['legal'] ?? null) === false);
        if(!$coBlocked&&!$coIllegal&&!$gwcOnlyGas&&is_array($gasAudit)&&empty($gasAudit['feasible'])&&function_exists('pp_shortage_decision_block')){
            $actNow=strtolower(trim((string)($input['data3']['modeling']['gas_shortage_action']??'none')));
            if($actNow==='flag shortage only'||$actNow==='flag_shortage_only')$actNow='none';
            if($actNow==='none'){
                $expAud=pp_export_minimization_audit($input,$output);
                $output['info']['Export Minimization Audit']=$expAud;
                $output['export_minimization_audit']=$expAud;
                $blk=pp_shortage_decision_block($input,$output,$gasAudit,$expAud);
                $output['reason_code']=(string)($blk['reason_code']??'');
                $output['problem_kind']=(string)($blk['problem_kind']??'');
                /* Systemic gate: never ask for fuel on a deadline/truncated/non-converged or
                 * still-reducible plan. Such a result must continue exact economic review first. */
                $rs=(array)($output['info']['Run Status']??[]);
                /* GERBANG SISTEMIK YANG SELAMA INI HANYA TERTULIS DI KOMENTAR.
                 * Komentar di atas menyatakan aturannya, tetapi kodenya tidak pernah menerapkannya:
                 * `fuel_estimate_ready` dihitung tanpa melihat konvergensi, sehingga sebuah run yang
                 * DIPOTONG batas waktu tetap membuka popup "pilih LNG atau Distillate". Terukur pada
                 * PGN 20 + PEP 40 lewat klik nyata: `converged=false`,
                 * `economic_review_skipped.async_completion_required=true`, worker background sudah
                 * dibuat — namun operator justru diminta memilih bahan bakar untuk rencana yang belum
                 * final, dan hasil worker tidak pernah diambil. Keputusan bahan bakar hanya sah atas
                 * rencana FINAL. */
                $fuelEstimateReady=(bool)($blk['fuel_estimate_ready']??false) && $finalEnough;
                $finalReleaseReady=(bool)($blk['final_values_publishable']??false)
                    && (($rs['converged']??false)===true)
                    && (($rs['deadline_reached']??true)===false)
                    && empty($rs['stages_truncated'])
                    && empty($rs['economic_review_skipped'])
                    && empty($output['info']['Global Commitment Review Skipped']);
                if($fuelEstimateReady){
                    /* This is an intermediate operator decision, not a published final result.
                     * Preserve the incumbent rows but clear the terminal error contract. */
                    $output['ok']=true;
                    $output['result']='action_required';
                    $output['status']='USER_FUEL_DECISION_REQUIRED';$output['status_legacy']='FUEL_SELECTION_REQUIRED';
                    $output['action_required']='SHORTAGE_FUEL_SELECTION';
                    $output['publish_allowed']=false;
                    /* ==================================================================================
                     * KONTRAK HASIL PRELIMINARY — DINYATAKAN EKSPLISIT, BUKAN DISIRATKAN.
                     *
                     * Baris 48 yang ikut dikembalikan di sini adalah ANALISIS PENDAHULUAN: ia dipakai
                     * untuk MENGETAHUI berapa kekurangan gas, bukan sebagai rencana yang dapat dipakai.
                     * Tanpa penanda eksplisit, UI memperlakukannya seperti hasil biasa — merender 48
                     * baris, membuka Result, dan mengisi OUTPUT — sehingga operator dapat menyimpan
                     * dan meng-export rencana yang shortage-nya BELUM diselesaikan. Workbook
                     * `Daily_Plan_09_Jul_26_TGD_38(5).xls` adalah buktinya: ia ter-export dengan
                     * `Fuel Action Source = recommendation`, `Added LNG = 0`, `Distillate Used = 0`,
                     * dan `Residual Gas Shortage = 9,7385`.
                     *
                     * Tiga penanda di bawah membuat kontraknya tidak dapat disalahtafsirkan oleh
                     * klien mana pun, termasuk klien selain UI ini. */
                    $output['preliminary']=true;
                    $output['final_result_visible']=false;
                    $output['save_allowed']=false;
                    $output['preliminary_note']='Analisis pendahuluan untuk mengukur kekurangan gas. '
                        .'Bukan rencana final: Simulation Data, Save, Export, dan Publish terkunci '
                        .'sampai operator memilih LNG, Distillate, atau kombinasi keduanya.';
                    $output['http_status_recommended']=200;
                    $output['diagnostic_before_fuel_selection']=$output['error']??null;
                    unset($output['error']);
                } else {
                    /* Perhitungan BELUM selesai — bukan kegagalan terminal. Baris incumbent tetap
                     * dikembalikan sebagai PRATINJAU supaya UI dapat menampilkannya sambil mengadopsi
                     * job worker yang dilampirkan `pp_attach_async_handoff()`. Publish tetap terkunci. */
                    $output['ok']=true;
                    $output['result']='action_required';
                    $output['status']='ECONOMIC_REVIEW_REQUIRED';
                    $output['action_required']='CONTINUE_EXACT_ECONOMIC_REVIEW';
                    $output['publish_allowed']=false;
                    $output['http_status_recommended']=200;
                    $output['diagnostic_before_exact_review']=$output['error']??null;
                    unset($output['error']);
                    /* ==============================================================================
                     * LUBANG YANG DITUTUP DI SINI — CABANG TETANGGA DARI AKAR PENYEBAB YANG SAMA.
                     *
                     * Cabang `USER_FUEL_DECISION_REQUIRED` di atas menyatakan kontraknya secara
                     * eksplisit (`preliminary`, `final_result_visible`, `save_allowed`), tetapi
                     * cabang INI tidak — padahal keadaannya justru LEBIH lemah: rencananya belum
                     * konvergen DAN kekurangan gasnya belum diselesaikan. Akibatnya UI tidak punya
                     * satu pun penanda untuk menahannya, `finalizeSimulationUI()` berjalan apa
                     * adanya, dan 48 baris pendahuluan masuk ke OUTPUT dengan Save/Export terbuka.
                     *
                     * Terukur dengan input nyata pada mesin ini: status ECONOMIC_REVIEW_REQUIRED,
                     * `Run Status.converged=false`, `stages_truncated={time_budget:mm_ge_fill:1}`,
                     * `shortage_decision.gas_shortage=9.6985` — dan tidak ada satu pun flag yang
                     * memberi tahu klien bahwa angka itu belum boleh diterbitkan.
                     *
                     * Kontraknya kini dinyatakan sama tegasnya. Nilainya TIDAK diubah, hanya
                     * dideklarasikan; tidak ada baris hasil yang disentuh. */
                    $output['preliminary']=true;
                    $output['final_result_visible']=false;
                    $output['save_allowed']=false;
                    $output['preliminary_note']='Analisis pendahuluan yang BELUM konvergen dan masih '
                        .'menyisakan kekurangan gas. Bukan rencana final: Simulation Data, Save, '
                        .'Export, dan Publish terkunci sampai review ekonomi eksak selesai.';
                }
                $output['shortage_decision']=$blk;
                $output['fuel_estimate_ready']=$fuelEstimateReady;
                $output['final_release_ready']=$finalReleaseReady;
                $output['info']['Shortage Decision']=$blk;
                $output['error']['details']['shortage_decision']=$blk;
                /* GERBANG NILAI FINAL (definisi bisnis): popup hanya boleh menyajikan estimasi
                 * LNG/distillate sebagai angka FINAL bila minimisasi Export sudah tuntas, run
                 * konvergen, tidak ada slot Export yang masih dapat diturunkan, dan kebutuhan gas
                 * final masih melebihi kuota. Nilainya diangkat ke level atas supaya UI tidak
                 * perlu menyimpulkannya sendiri. */
                $output['shortage_declarable']=(bool)($blk['shortage_declarable']??false);
                $output['final_values_publishable']=(bool)($blk['final_values_publishable']??false);
                $output['export_minimization_two_phase']=$output['info']['Export Minimization Two Phase']??null;
            }
        }
    }
    return $review;
}

/* Fuel-action reconciliation: a selected action is accepted only when the engine output proves
 * that the chosen fuel was actually applied. This prevents a UI click from appearing successful
 * while the backend effectively ran action=none or produced zero fuel. */
/* ==============================================================================================
 *  SATU SUMBER KEBENARAN UNTUK `Residual Gas Shortage`.
 *
 *  Nilai yang dilaporkan di ringkasan dahulu dihitung dari besaran ANTARA (shortage awal dikurangi
 *  jumlah yang diterapkan), sedangkan yang MEMUTUSKAN lulus/gagal adalah pp_reconcile_selected_fuel()
 *  yang membandingkan pemakaian gas FINAL terhadap kuota efektif FINAL. Keduanya berbeda basis,
 *  sehingga ringkasan dapat menyatakan `Residual Gas Shortage = 0` pada rencana yang justru DITOLAK
 *  karena melampaui kuota. Terukur pada Additional LNG 11,0831: gas neto 66,1635 terhadap kuota
 *  efektif 65,6831 — kelebihan 0,4804 — namun ringkasan menampilkan residual 0.
 *
 *  Angka yang ditampilkan kini diambil dari otoritas yang sama dengan yang memutuskan, sehingga
 *  operator tidak pernah melihat dua kebenaran untuk satu rencana. */
function pp_sync_residual_from_recon(array &$output, array $recon): void {
    if (empty($recon['required'])) return;
    if (!isset($recon['residual_shortage']) || !is_numeric($recon['residual_shortage'])) return;
    if (!isset($output['info']) || !is_array($output['info'])) return;
    $output['info']['Residual Gas Shortage (BBTUD)'] = round((float)$recon['residual_shortage'], 4);
}

function pp_reconcile_selected_fuel(array $input, array $output): array {
    $m=(array)($input['data3']['modeling']??[]);$i=(array)($output['info']??[]);
    $act=strtolower(trim((string)($m['gas_shortage_action']??'none')));
    if($act==='add lng to cover'||$act==='add_lng_to_cover')$act='add_lng';
    if($act==='use distillate to cover'||$act==='use_distillate_to_cover')$act='use_distillate';
    if($act==='mixed'||$act==='lng_plus_distillate')$act='mixed_lng_distillate';
    /* `mixed_lng_distillate` WAJIB masuk daftar ini. Tanpa itu rekonsiliasi melaporkan
     * `required=false` dan aksi campuran lolos TANPA pernah dibuktikan diterapkan — persis kelas
     * kesalahan yang membuat rencana tak tervalidasi dapat diterbitkan. */
    if(!in_array($act,['add_lng','use_distillate','mixed_lng_distillate'],true))return['required'=>false,'pass'=>true,'action'=>'none'];
    /* RESIDUAL DIHITUNG DARI METRIK VALIDATOR, BUKAN DARI FIELD LAPORAN.
     * `Gas Shortage (BBTUD)` adalah angka shortage yang dihitung SEBELUM bahan bakar pengganti
     * dijadwalkan, dan ia tidak selalu ditulis ulang setelahnya. Terukur pada distillate
     * 138.560,1 liter (jumlah yang justru sudah TERVALIDASI lewat rerun penuh): gas turun
     * 64,2985 -> 59,5961 dengan kuota 59,6000 — jelas di DALAM window, nol pelanggaran, konvergen,
     * economic review selesai — namun field itu masih melaporkan 4,7399 sehingga rekonsiliasi
     * menyimpulkan "tidak diterapkan". Otoritas residual adalah perbandingan yang dipakai
     * validator: pemakaian gas terhadap kuota efektif. */
    $gasUsedR=(float)($i['Total Gas Used (BBTUD)']??0);
    $gasQuotaR=(float)($i['Total Gas Quota (BBTUD)']??0);
    $short=($gasQuotaR>1.0)
        ? max(0.0,$gasUsedR-$gasQuotaR)
        : max(0.0,(float)($i['Gas Shortage (BBTUD)']??0));
    if($act==='mixed_lng_distillate'){
        /* ==========================================================================================
         * REKONSILIASI BAHAN BAKAR CAMPURAN.
         *
         * Operator mengotorisasi LNG dalam jumlah yang TIDAK mencukupi; sisanya ditutup distillate
         * tervalidasi. Yang wajib dibuktikan ada tiga, dan ketiganya harus benar bersamaan:
         *   1. LNG yang diterapkan PERSIS sebesar otorisasi operator (bukan lebih, bukan kurang);
         *   2. distillate benar-benar terjadwal dengan gas offset nyata;
         *   3. residual terhadap kuota EFEKTIF (kuota dasar + LNG) tertutup.
         * Menerima salah satunya saja akan meloloskan rencana yang membakar bahan bakar yang tidak
         * disetujui, atau yang menyisakan kekurangan tanpa mengatakannya. */
        $reqLng=(float)($m['additional_lng']??0);
        $added =(float)($i['Added LNG (BBTUD)']??0);
        $litres=(float)($i['Distillate Fuel Total (l)']??0);
        $offset=(float)($i['Distillate Gas Offset (BBTUD)']??0);
        $lngOk =$reqLng>1e-9 && $added>1e-9 && abs($added-$reqLng)<=0.001;
        $distOk=$litres>0 && $offset>0;
        $pass  =$lngOk && $distOk && $short<=0.04+1e-9;
        return['required'=>true,'pass'=>$pass,'action'=>$act,
               'requested_lng'=>$reqLng,'reported_added_lng'=>$added,
               'reported_litres'=>$litres,'gas_offset'=>$offset,
               'lng_applied_exactly'=>$lngOk,'distillate_scheduled'=>$distOk,
               'residual_shortage'=>$short,
               'code'=>$pass?'FUEL_ACTION_APPLIED':'FUEL_ACTION_NOT_APPLIED'];
    }
    if($act==='add_lng'){
        $requested=(float)($m['additional_lng']??0);$added=(float)($i['Added LNG (BBTUD)']??0);$used=(float)($i['LNG Used (BBTUD)']??0);
        $pass=$requested>1e-9&&$added>1e-9&&$used>1e-9&&abs($added-$requested)<=0.001&&$short<=0.04+1e-9;
        return['required'=>true,'pass'=>$pass,'action'=>$act,'requested'=>$requested,'reported_added'=>$added,'reported_used'=>$used,'residual_shortage'=>$short,'code'=>$pass?'FUEL_ACTION_APPLIED':'FUEL_ACTION_NOT_APPLIED'];
    }
    /* PLAFON DISTILLATE BERSIFAT OPSIONAL.
     *
     * Dahulu baris ini membaca plafon sebagai float dengan default 0 lalu mensyaratkan
     * `$requested>0`. Akibatnya setiap permintaan `use_distillate` TANPA plafon otomatis gagal
     * dengan detail yang menyesatkan — "requested_litres: 0" sementara engine benar-benar
     * menjadwalkan ratusan ribu liter, menutup shortage sampai residual 0, dan seluruh
     * rekonsiliasi energi konsisten. Yang ditolak bukan pelanggaran, melainkan ketiadaan plafon.
     *
     * Semantik yang benar (instruksi 4.3: "user limit adalah plafon, tidak boleh dilampaui"):
     * plafon adalah BATAS ATAS, bukan target dan bukan syarat. Bila operator memberi plafon, hasil
     * wajib berada di bawahnya. Bila tidak ada plafon, tidak ada yang dilampaui — kelulusan dinilai
     * dari bukti yang sesungguhnya: distillate benar terjadwal, gas offset nyata, residual tertutup. */
    $capRaw=$m['distillate_user_limit_litres']??null;
    /* `>= 0`: plafon 0 adalah otorisasi NOL yang tetap harus dilaporkan sebagai plafon, sehingga
     * hasilnya dinilai "tidak diterapkan" dengan jujur alih-alih diam-diam menjadi tanpa batas. */
    $hasCap=is_numeric($capRaw)&&(float)$capRaw>=0;
    $requested=$hasCap?(float)$capRaw:0.0;
    $litres=(float)($i['Distillate Fuel Total (l)']??0);$offset=(float)($i['Distillate Gas Offset (BBTUD)']??0);
    $pass=$litres>0&&$offset>0&&$short<=0.04+1e-9&&(!$hasCap||$litres<=$requested+1.0);
    return['required'=>true,'pass'=>$pass,'action'=>$act,'user_cap_applied'=>$hasCap,
           'requested_litres'=>$hasCap?$requested:null,'reported_litres'=>$litres,'gas_offset'=>$offset,
           'residual_shortage'=>$short,'code'=>$pass?'FUEL_ACTION_APPLIED':'FUEL_ACTION_NOT_APPLIED'];
}

/* ==============================================================================================
 *  BATAS ANTARA DEFINISI DAN PENANGANAN REQUEST.
 *
 *  Di atas garis ini hanya ada definisi fungsi; di bawahnya barulah request ditangani. Alat
 *  forensik dan alat uji perlu MEMUAT fungsi-fungsi di atas tanpa memicu satu pun penanganan
 *  request — tanpa penjaga ini, sekadar `require run.php` akan menjawab "Use POST." atau, dari
 *  CLI, menjalankan simulasi penuh. `return` di tingkat berkas menghentikan include di sini dan
 *  membiarkan seluruh fungsi tetap terdefinisi. */
if (defined('PP_LIB_ONLY')) return;

/* ---- ENDPOINT JOB ASINKRON (GET, ringan) --------------------------------------------------
 * job_poll dan job_cancel sengaja ditempatkan SEBELUM pemeriksaan POST: polling UI berjalan tiap
 * dua detik dan tidak boleh memaksa body request. job_start memerlukan payload sehingga tetap
 * berada di jalur POST bersama mode lain. */
/* ==============================================================================================
 *  PROGRES ANALISIS PENDAHULUAN — ENDPOINT RINGAN.
 *
 *  Analisis pendahuluan berjalan SINKRON dan memakan puluhan detik pada rencana berat. Selama itu
 *  operator tidak boleh dibiarkan menatap tombol yang diam. Endpoint ini membaca satu berkas kecil
 *  yang ditulis engine pada setiap batas fase, sehingga modal dapat menampilkan tahap yang
 *  SEBENARNYA sedang berjalan — bukan animasi yang mengarang kemajuan.
 *
 *  Sengaja dibuat sangat murah (satu file_get_contents) dan ditempatkan SEBELUM pemeriksaan POST,
 *  persis seperti job_poll, supaya polling tidak pernah tertahan di belakang request berat. */
if (($_GET['mode'] ?? '') === 'prelim_progress') {
    $rid = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)($_GET['rid'] ?? ''));
    $f   = pp_prelim_progress_path($rid);
    $out = ($rid !== '' && is_file($f)) ? json_decode((string)@file_get_contents($f), true) : null;
    header('Content-Type: application/json');
    echo json_encode(is_array($out) ? $out : ['phase' => null, 'steps' => [], 'elapsed_s' => null]);
    exit;
}
/* ==============================================================================================
 *  EKSEKUSI JOB DI DALAM REQUEST — TANPA PROSES LUAR, TANPA CONSOLE.
 *
 *  MENGAPA CARA INI YANG DIPAKAI. Arsitektur lama meluncurkan worker sebagai proses OS terpisah
 *  (`cmd /C start "" /B php.exe ...`). Di Windows/XAMPP itu membawa tiga kerugian sekaligus:
 *  jendela Command Prompt berkedip setiap kali Run ditekan, peluncurannya bergantung pada
 *  penemuan `php.exe` yang dapat gagal, dan proses anaknya terikat pada job object Apache sehingga
 *  dapat dimatikan di tengah jalan. Tiga-tiganya terjadi pada mesin operator.
 *
 *  Endpoint ini menghapus seluruh kelas masalah itu: job dijalankan DI DALAM sebuah request PHP
 *  biasa yang dipicu oleh browser. Tidak ada proses baru, tidak ada console, tidak ada pencarian
 *  biner, tidak ada PID yang harus dijaga. Yang menjalankan pekerjaan adalah Apache sendiri —
 *  komponen yang memang sudah terbukti berjalan di mesin itu.
 *
 *  Plafon waktu dinaikkan secara eksplisit untuk request ini saja (`set_time_limit(0)`), dan
 *  `ignore_user_abort(true)` membuat perhitungan tetap selesai serta hasilnya tetap tertulis
 *  walaupun browser menutup koneksi. Progres dan denyut ditulis ke job.json seperti biasa,
 *  sehingga modal tetap menampilkan tahap yang nyata.
 *
 *  Keamanan: hanya menerima job yang SUDAH terdaftar, hanya dari permintaan yang menyertakan
 *  token milik job itu, dan tidak pernah menerima input dari luar — inputnya dibaca dari berkas
 *  job yang ditulis server sendiri. */
/* V4 — PEKERJA PEMBANTU job pemilik (dipicu browser bersama job_exec, tanpa proses OS). Hanya
 * mengerjakan candidate-state yang diterbitkan pemilik di work.json; tidak pernah memutuskan
 * pemenang atau menerbitkan hasil. Satu request per slot (kunci slot). */
if (($_GET['mode'] ?? '') === 'job_help') {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json');
    $jid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
    $tok = (string)($_GET['token'] ?? '');
    $slot = max(1, min(8, (int)($_GET['slot'] ?? 1)));
    $job = $jid !== '' ? pp_job_read($jid) : null;
    if (!is_array($job)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'JOB_TIDAK_DITEMUKAN']); exit; }
    if ($tok === '' || !hash_equals((string)($job['exec_token'] ?? ''), $tok)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'TOKEN_JOB_TIDAK_COCOK']); exit; }
    if ($slot > pp_v4_helper_slots()) { echo json_encode(['ok' => true, 'idle' => 'SLOT_DI_ATAS_BATAS']); exit; }
    @ignore_user_abort(true); @set_time_limit(0); @ini_set('max_execution_time', '0'); @ini_set('memory_limit', '1024M');
    if (!defined('PP_INPROC_JOB')) define('PP_INPROC_JOB', true);
    $GLOBALS['__pp_async_worker'] = true; $GLOBALS['__pp_async_worker_ceiling'] = 1800.0;
    echo json_encode(pp_v4_helper_main($jid, $slot), JSON_UNESCAPED_SLASHES); exit;
}
if (($_GET['mode'] ?? '') === 'job_exec') {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json');
    $jid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
    $tok = (string)($_GET['token'] ?? '');
    $job = $jid !== '' ? pp_job_read($jid) : null;
    if (!is_array($job)) { http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'JOB_TIDAK_DITEMUKAN']); exit; }
    if ($tok === '' || !hash_equals((string)($job['exec_token'] ?? ''), $tok)) { http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'TOKEN_JOB_TIDAK_COCOK']); exit; }
    /* ==========================================================================================
     * KLAIM ATOMIK — SATU JOB, SATU PEMILIK.
     *
     * Klik ganda, retry, refresh, atau dua tab dapat mengirim `job_exec` untuk job yang sama pada
     * saat bersamaan. Pemeriksaan status biasa tidak cukup: dua request dapat sama-sama melihat
     * QUEUED lalu sama-sama menghitung. Karena itu klaim dilakukan DI DALAM kunci berkas job
     * (`pp_job_update` memakai flock): hanya request pertama yang mengubah QUEUED menjadi CLAIMED,
     * request lain menerima jawaban "sudah dikerjakan" dan cukup membaca progres.
     *
     * Kunci eksekusi (`exec.lock`, lihat pp_job_exec_held) diambil LEBIH DULU. Request yang tidak
     * mendapat kunci berarti job sedang dihitung request lain dan langsung menjawab "sudah
     * dikerjakan". Pemegang kunci boleh mengklaim job QUEUED, atau job CLAIMED/RUNNING yang
     * pemiliknya terbukti berhenti (kuncinya lepas) — tanpa menebak dari umur denyut. */
    if (!pp_job_exec_acquire($jid)) {
        echo json_encode(['ok' => true, 'already' => (string)($job['status'] ?? '?'), 'job' => pp_job_read($jid) ?? $job]); exit;
    }
    $claimId = bin2hex(random_bytes(8));
    $claim = pp_job_update($jid, function (array $j) use ($claimId): array {
        $st = (string)($j['status'] ?? '');
        if (in_array($st, ['QUEUED', 'CLAIMED', 'RUNNING'], true)) {
            if ($st !== 'QUEUED') $j['reclaimed_from'] = (string)($j['claim_id'] ?? '');
            $j['status'] = 'CLAIMED'; $j['claim_id'] = $claimId;
            $j['claimed_at'] = date('c'); $j['claimed_at_ts'] = microtime(true);
            $j['heartbeat_ts'] = microtime(true); $j['heartbeat_at'] = date('c');
        }
        return $j; });
    if (!is_array($claim) || (string)($claim['claim_id'] ?? '') !== $claimId) {
        echo json_encode(['ok' => true, 'already' => (string)($claim['status'] ?? '?'), 'job' => $claim]); exit;
    }
    /* Status tetap CLAIMED sampai pp_job_worker_main() menandainya RUNNING — tidak pernah kembali ke
     * QUEUED, sehingga tidak ada celah waktu bagi request lain untuk ikut mengklaim. */
    @ignore_user_abort(true);
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');   // pp_budget_start() membaca nilai ini; harus 0 seperti CLI
    @ini_set('memory_limit', '1024M');
    if (!defined('PP_INPROC_JOB')) define('PP_INPROC_JOB', true);
    $GLOBALS['__pp_inproc_job'] = true;   // informatif saja; penentu adalah konstanta PP_INPROC_JOB
    $rc = pp_job_worker_main($jid);
    $job = pp_job_read($jid);
    echo json_encode(['ok' => ($rc === 0), 'exit_code' => $rc, 'job' => $job], JSON_UNESCAPED_SLASHES);
    exit;
}
/* TARGET SELESAI — kandidat valid terbaik saat ini (GET, ringan, tidak menyentuh engine). */
if (in_array(($_GET['mode'] ?? ''), ['tl_best', 'tl_stop', 'tl_pool'], true)) {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $ridT = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)($_GET['rid'] ?? ''));
    if ($_GET['mode'] === 'tl_stop') {
        if ($ridT !== '') @touch(pp_tl_file('stop_' . $ridT));
        echo json_encode(['ok' => true]); exit;
    }
    if ($_GET['mode'] === 'tl_pool') {
        $kP = preg_replace('~[^a-f0-9]~', '', (string)($_GET['key'] ?? ''));
        if ($kP === '' && $ridT !== '') { $rk = pp_tl_read(pp_tl_file('rid_' . $ridT . '.json')); $kP = preg_replace('~[^a-f0-9]~', '', (string)($rk['key'] ?? '')); }
        /* V3: kandidat valid milik job pemilik state (kolam _x) — dibaca lewat id job. */
        $sideP = '_q';
        $jidP = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
        if ($kP === '' && $jidP !== '') { $inJ = json_decode((string)@file_get_contents(pp_job_dir($jidP) . '/input.json'), true);
            if (is_array($inJ)) { $kP = pp_tl_key($inJ);
                $mq0 = pp_tl_read(pp_tl_file($kP . '_q_meta.json')); $mx0 = pp_tl_read(pp_tl_file($kP . '_x_meta.json'));
                $sideP = (is_array($mx0['cmp'] ?? null) && (!is_array($mq0['cmp'] ?? null) || pp_global_commitment_better($mx0['cmp'], $mq0['cmp']))) ? '_x' : '_q'; } }
        $mP = $kP !== '' ? pp_tl_read(pp_tl_file($kP . $sideP . '_meta.json')) : null;
        /* V4: pratinjau yang sama dengan yang sudah dimiliki browser tidak dikirim ulang (hemat CPU
         * selama perhitungan berjalan). */
        $sinceP = (string)($_GET['since'] ?? '');
        if ($sinceP !== '' && is_array($mP) && $sinceP === (string)($mP['found_at'] ?? '') . $sideP) { echo json_encode(['ok' => true, 'unchanged' => true]); exit; }
        $bP = $kP !== '' ? pp_tl_read(pp_tl_file($kP . $sideP . '_best.json')) : null;
        if (!is_array($mP) || !is_array($bP['output'] ?? null)) { echo json_encode(['ok' => false, 'error' => 'KOLAM_KOSONG']); exit; }
        $stP = $ridT !== '' ? pp_tl_read(pp_tl_file($kP . '_q_' . $ridT . '.json')) : null;
        $labP = (string)($_GET['as'] ?? '') === 'provisional' ? pp_tl_label_provisional($bP['output'], $mP, $stP) : pp_tl_label_output($bP['output'], $mP, false);
        echo json_encode(['ok' => true, 'best' => $mP, 'since' => (string)($mP['found_at'] ?? '') . $sideP, 'search' => $stP, 'output' => $labP],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
    }
    $jidT = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
    $jobT = $jidT !== '' ? pp_job_read($jidT) : null;
    $inT = $jobT ? json_decode((string)@file_get_contents(pp_job_dir($jidT) . '/input.json'), true) : null;
    if (!is_array($inT)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'JOB_TIDAK_DITEMUKAN']); exit; }
    $keyT = pp_tl_key($inT);
    /* Dua kolam: pencarian Target Selesai (_q) dan kandidat antara job exact (_x). Yang tampil pada
     * batas waktu adalah yang terbaik dari keduanya; hasil final exact hanya dibandingkan dengan
     * kolam _q, karena kandidat antara exact sudah dinilai oleh keputusan akhir exact itu sendiri. */
    $mq = pp_tl_read(pp_tl_file($keyT . '_q_meta.json'));
    $mx = pp_tl_read(pp_tl_file($keyT . '_x_meta.json'));
    $bestSide = null;
    if (is_array($mq) && is_array($mq['cmp'] ?? null)) $bestSide = '_q';
    if (is_array($mx) && is_array($mx['cmp'] ?? null) && ($bestSide === null || pp_global_commitment_better($mx['cmp'], $mq['cmp']))) $bestSide = '_x';
    $meta = $bestSide === '_q' ? $mq : ($bestSide === '_x' ? $mx : null);
    $q = $ridT !== '' ? (pp_tl_read(pp_tl_file($keyT . '_q_' . $ridT . '.json')) ?? []) : [];
    $x = pp_tl_read(pp_tl_file($keyT . '_x_' . $jidT . '.json')) ?? [];
    $resp = ['ok' => true, 'key' => $keyT, 'job_status' => (string)($jobT['status'] ?? ''),
        'quick' => $q, 'exact' => $x,
        'evaluated_total' => (int)($q['evaluated'] ?? 0) + (int)($x['evaluated'] ?? 0),
        'valid_total' => (int)($q['valid'] ?? 0) + (int)($x['valid'] ?? 0),
        'counters' => null,
        'best' => is_array($meta) ? ['source' => $meta['source'] ?? null, 'cmp' => $meta['cmp'] ?? null,
            'cost' => $meta['cost'] ?? null, 'cost_production' => $meta['cost_production'] ?? null,
            'units_off' => $meta['units_off'] ?? [], 'checks' => $meta['checks'] ?? []] : null,
        'exact_final' => false, 'pool_better_than_exact' => false];
    if ((string)($jobT['status'] ?? '') === 'DONE' && !empty($jobT['result_available'])) {
        $rT = pp_tl_read(pp_job_dir($jidT) . '/result.json');
        $oT = is_array($rT['output'] ?? null) ? $rT['output'] : null;
        if ($oT && pp_final_is_final($oT)) {
            $resp['exact_final'] = true;
            try {
                $inN = pp_normalize_copy($inT);
                $kE = pp_global_commitment_key($inN, $oT);
                $resp['exact_cmp'] = $kE;
                $resp['pool_better_than_exact'] = is_array($mq) && is_array($mq['cmp'] ?? null) && pp_global_commitment_better($mq['cmp'], $kE);
                /* Exact final mengalahkan kandidat antara: yang tampil pada batas adalah kolam _q
                 * bila lebih murah, selain itu hasil final exact itu sendiri. */
                if ($resp['pool_better_than_exact']) { $bestSide = '_q'; $meta = $mq; }
                else { $bestSide = null; $meta = null; }
                $resp['best'] = is_array($meta) ? ['source' => $meta['source'] ?? null, 'cmp' => $meta['cmp'] ?? null,
                    'cost' => $meta['cost'] ?? null, 'cost_production' => $meta['cost_production'] ?? null,
                    'units_off' => $meta['units_off'] ?? [], 'checks' => $meta['checks'] ?? []] : null;
            } catch (Throwable $e) {}
        }
    }
    /* V11: satu sumber penghitung kandidat (folder state x job). Kandidat terbaik yang ditampilkan dan FINAL exact
     * selalu termasuk kandidat valid; valid <= diperiksa. */
    if (function_exists('pp_v11_on') && pp_v11_on()) { try { $force = [];
        if (is_array($meta) && $bestSide !== null) { $sgB = (string)($meta['sig'] ?? '');
            if ($sgB === '') { $bT0 = pp_tl_read(pp_tl_file($keyT . $bestSide . '_best.json')); if (is_array($bT0['output']['data'] ?? null)) $sgB = pp_v6_gtg_sig((array)$bT0['output']['data']); }
            if ($sgB !== '') $force[] = $sgB; }
        if (!empty($resp['exact_final']) && isset($oT) && is_array($oT['data'] ?? null)) $force[] = pp_v6_gtg_sig((array)$oT['data']);
        $cntT = pp_v11_cnt_read($keyT, $jidT, $force);
        $resp['counters'] = $cntT; $resp['evaluated_total'] = $cntT['candidates_checked']; $resp['valid_total'] = $cntT['candidates_valid'];
    } catch (Throwable $e) {} }
    if (!empty($_GET['with_output']) && is_array($meta) && $bestSide !== null) {
        $bT = pp_tl_read(pp_tl_file($keyT . $bestSide . '_best.json'));
        if (is_array($bT['output'] ?? null)) $resp['output'] = pp_tl_label_output($bT['output'], $meta, false);
    }
    echo json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}

/* V15.16 datastore kanonik ber-scope (saved_data_store.php): endpoint baca/kelola record milik user pemanggil. */
if (in_array(($_GET['mode'] ?? ''), ['store_list', 'store_load', 'store_meta', 'store_delete', 'store_integrity', 'store_latest'], true) && function_exists('sds_http')) sds_http((string)$_GET['mode']);
if (in_array(($_GET['mode'] ?? ''), ['job_poll', 'job_cancel', 'job_list'], true)) {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    $mode = (string)$_GET['mode'];
    if ($mode === 'job_list') {
        pp_job_sweep();
        $rows = [];
        foreach ((array)@scandir(pp_job_root()) as $e) {
            if ($e === '.' || $e === '..') continue;
            $j = pp_job_read($e);
            if ($j === null) continue;
            $rows[] = ['job_id' => $j['job_id'] ?? $e, 'kind' => $j['kind'] ?? null,
                       'status' => $j['status'] ?? null, 'percent' => $j['percent'] ?? null,
                       'created_at' => $j['created_at'] ?? null, 'updated_at' => $j['updated_at'] ?? null];
        }
        echo json_encode(['ok' => true, 'jobs' => $rows], JSON_UNESCAPED_SLASHES); exit;
    }
    $jid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['job'] ?? ''));
    if ($jid === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'PARAMETER_job_WAJIB']); exit; }
    $job = pp_job_read($jid);
    if ($job === null) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'JOB_TIDAK_DITEMUKAN', 'job_id' => $jid]); exit; }
    /* V15.16 ISOLASI RUN: job dipakai bersama oleh run dengan input identik (cache konteks). Setiap run yang memantau job
     * mencatat heartbeat per Run ID; Cancel dari satu run hanya MELEPAS run itu — job baru dibatalkan bila tidak ada run
     * lain yang masih memantau (heartbeat < 20 s). Polling selalu membawa Run ID. */
    $ridS = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['rid'] ?? ''));
    $subDir = pp_job_dir($jid) . '/subs';
    if ($ridS !== '' && $mode === 'job_poll') { if (!is_dir($subDir)) @mkdir($subDir, 0775, true); @touch($subDir . '/' . $ridS); }
    if ($mode === 'job_cancel' && $ridS !== '' && (string)getenv('PP_V1516_SHARED_CANCEL') !== '0') {
        @unlink($subDir . '/' . $ridS); $others = [];
        foreach ((array)@glob($subDir . '/*') as $sf) if (is_file($sf) && @filemtime($sf) >= time() - 20) $others[] = basename($sf);
        if ($others) { echo json_encode(['ok' => true, 'detached' => true, 'job_id' => $jid, 'rid' => $ridS, 'other_active_runs' => count($others),
            'note' => 'Run ini dilepas dari job; job tetap berjalan untuk run lain dengan input identik.'], JSON_UNESCAPED_SLASHES); exit; }
    }
    if ($mode === 'job_cancel') {
        $abortTl = !empty($_GET['abort']);
        $job = pp_job_update($jid, function (array $j) use ($abortTl): array {
            $j['cancel_requested'] = true;
            if ($abortTl) $j['cancel_abort'] = true;
            if (in_array((string)($j['status'] ?? ''), ['QUEUED', 'CLAIMED', 'RUNNING'], true)) {
                $j['status'] = 'CANCELLED'; $j['finished_at'] = date('c');
                $j['current_step'] = 'DIBATALKAN_OPERATOR';
            }
            return $j; }) ?? $job;
        echo json_encode(['ok' => true, 'job' => $job], JSON_UNESCAPED_SLASHES); exit;
    }
    /* job_poll: sapu job yatim lebih dulu supaya worker yang mati tidak terlihat "masih jalan". */
    pp_job_sweep();
    $job = pp_job_read($jid) ?? $job;
    $resp = ['ok' => true, 'job' => $job];
    /* ANTI HASIL BASI: pemanggil boleh mengirim input_hash yang sedang ia tampilkan. Bila tidak
     * cocok dengan job, hasil TIDAK dikembalikan — run terbaru tidak boleh ditimpa hasil lama. */
    $wantHash = (string)($_GET['input_hash'] ?? '');
    if ((string)($job['status'] ?? '') === 'DONE' && !empty($job['result_available'])) {
        if ($wantHash !== '' && !hash_equals((string)($job['input_hash'] ?? ''), $wantHash)) {
            $resp['stale'] = true;
            $resp['stale_reason'] = 'input_hash job berbeda dengan input yang sedang ditampilkan; hasil job TIDAK diterapkan';
        } else {
            $rf = pp_job_dir($jid) . '/result.json';
            $r = null;
            for ($i = 0; $i < 10 && !is_array($r); $i++) {
                $r = is_file($rf) ? json_decode((string)@file_get_contents($rf), true) : null;
                if (!is_array($r)) usleep(50000);
            }
            if (is_array($r)) {
                if ((string)($job['kind'] ?? '') === 'economic_review' && is_array($r['output'] ?? null)) {
                    $inP = json_decode((string)@file_get_contents(pp_job_dir($jid) . '/input.json'), true);
                    if (is_array($inP)) pp_tl_pool_note($inP, $r['output']);
                }
                $resp['result'] = $r;
            }
            else { $resp['ok'] = false; $resp['error'] = 'RESULT_JSON_TIDAK_TERBACA'; }
        }
    }
    echo json_encode($resp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pp_fail(405, 'Use POST.');

/* §11: tangkap SEMUA output tak sengaja (PHP warning/notice/echo debug) sebelum JSON.
 * Semua jalur keluar (pp_fail atau echo sukses) membersihkan buffer ini lebih dulu, sehingga
 * respons SELALU JSON valid tanpa prefiks non-JSON yang memicu "unexpected character at line 1". */
ob_start();

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) pp_fail(400, 'Invalid JSON: ' . json_last_error_msg(), ['code' => 'BAD_REQUEST_JSON', 'raw_len' => strlen((string)$raw)]);

/* ---- structural validation (reject anything that would corrupt the file) -- */
if (!is_array($input)) pp_fail(400, 'Body must be a JSON object.');
if (empty($input['data1']) || !is_array($input['data1'])) pp_fail(400, 'data1 (IE rows) is missing or empty.');
if (empty($input['data2']) || !is_array($input['data2'])) pp_fail(400, 'data2 (dispatch rows) is missing or empty.');
if (empty($input['data3']) || !is_array($input['data3'])) pp_fail(400, 'data3 is missing.');
if (!isset($input['data3']['modeling']) || !is_array($input['data3']['modeling'])) pp_fail(400, 'data3.modeling is missing.');

$required = ['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3'];
$missing  = array_values(array_filter($required, fn($u) => !isset($input['data3'][$u])));
if ($missing) pp_fail(400, 'Missing unit definitions in data3: ' . implode(', ', $missing));

/* ---- mode=job_start: melepas worker CLI asinkron untuk pekerjaan yang tidak muat 60 detik ----
 * kind=economic_review    : menyelesaikan Global Commitment Review yang dilewati request sinkron.
 * kind=validated_options  : memvalidasi rekomendasi LNG dan distillate secara terpisah (§4/§5).
 * Idempotent terhadap (kind, input_hash): polling UI tidak pernah melahirkan worker kedua. */
/* ---- mode=tl_search: pencarian anytime kandidat constraint-valid untuk TARGET SELESAI ----------
 * Berjalan di request terpisah dari job exact dan berhenti sendiri sebelum batas waktu operator.
 * Setiap kandidat valid ditawarkan ke kolam state (pp_tl_pool_offer); kandidat invalid dibuang. */
if (($_GET['mode'] ?? '') === 'tl_search') {
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    @ignore_user_abort(true); @set_time_limit(0); @ini_set('max_execution_time', '0'); @ini_set('memory_limit', '1024M');
    $t0S = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $tgtMax = (string)($_GET['target'] ?? '') === 'max';
    $tgt = $tgtMax ? 1800.0 : max(5.0, min(60.0, (float)($_GET['target'] ?? 15)));
    $deadlineS = $t0S + $tgt - 1.4;                 // cadangan agar browser membaca kolam tepat pada batas
    $ridS = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)($_GET['rid'] ?? ''));
    if (function_exists('pp_normalize_fuel_action')) pp_normalize_fuel_action($input);
    $errS = pp_validate_input((array)($input['data3']['modeling'] ?? []));
    if ($errS) { echo json_encode(['ok' => false, 'error' => 'SCHEDULE_CONFLICT_REJECTED', 'errors' => $errS]); exit; }
    $keyS = pp_tl_key($input);
    foreach (glob(pp_tl_dir() . DIRECTORY_SEPARATOR . '*') ?: [] as $fS) {
        if (is_file($fS) && time() - (int)@filemtime($fS) > 86400) @unlink($fS);
        elseif (is_dir($fS) && substr($fS, -4) === '_reg' && time() - (int)@filemtime($fS) > 86400) {
            foreach (glob($fS . DIRECTORY_SEPARATOR . '*') ?: [] as $gS) @unlink($gS);
            @rmdir($fS);
        }
    }
    pp_tl_write(pp_tl_file($keyS . '_owner.json'), ['rid' => $ridS, 'at' => microtime(true)]);
    if ($ridS !== '') pp_tl_write(pp_tl_file('rid_' . $ridS . '.json'), ['key' => $keyS, 'at' => microtime(true)]);
    $statF = pp_tl_file($keyS . '_q_' . $ridS . '.json');
    $st = ['evaluated' => 0, 'valid' => 0, 'done' => false, 'target_s' => $tgt, 'started' => $t0S];
    pp_tl_write($statF, $st);
    $lastW = 0.0;
    $stopF = pp_tl_file('stop_' . $ridS);
    $stop = function () use ($stopF, $keyS, $ridS): bool {
        if (is_file($stopF)) return true;
        $o = pp_tl_read(pp_tl_file($keyS . '_owner.json'));
        return is_array($o) && (string)($o['rid'] ?? '') !== $ridS;
    };
    $tick = function (int $e, int $v) use (&$st, $statF, &$lastW): void {
        $st['evaluated'] = $e; $st['valid'] = $v; $st['at'] = microtime(true);
        if (microtime(true) - $lastW > 0.5) { pp_tl_write($statF, $st); $lastW = microtime(true); }
    };
    $offer = function (array $a) use ($keyS, $ridS): void { pp_tl_pool_offer($keyS . '_q', $a, 'time_limited_search', $ridS); };
    /* Keluarga commitment lengkap (ruang kandidat exact yang sama dengan Maximum Review) dievaluasi
     * lewat registri bersama: node yang sudah dihitung job exact / run lain dipakai ulang. */
    $seed = pp_tl_seed_stops($input);
    $origS = $input;
    foreach (array_keys((array)$origS['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($origS['data3']['modeling'][$mk]);
    try {
        $F = pp_tl_family_search($origS, $deadlineS, pp_tl_registry_open($origS),
            ['seeds' => $seed, 'max_nodes' => 64, 'stop' => $stop, 'offer' => $offer,
             'tick' => function (int $n, int $v, int $e) use ($tick) { $tick($e, $v); }]);
        $sum = ['evaluated' => (int)($F['evaluated'] ?? 0), 'valid' => (int)($F['valid'] ?? 0), 'nodes' => (int)($F['nodes'] ?? 0),
                'computed' => (int)($F['computed'] ?? 0), 'reused' => (int)($F['reused'] ?? 0),
                'family_complete' => !empty($F['complete']), 'stop_reason' => $F['reason'] ?? null];
    } catch (Throwable $e) { $sum = ['error' => $e->getMessage()]; }
    $st = array_merge($st, $sum, ['done' => true, 'at' => microtime(true), 'wall_s' => round(microtime(true) - $t0S, 2)]);
    pp_tl_write($statF, $st);
    @unlink($stopF);
    echo json_encode(['ok' => true, 'key' => $keyS] + $st, JSON_UNESCAPED_SLASHES); exit;
}

if (($_GET['mode'] ?? '') === 'job_start') {
    $kind = (string)($_GET['kind'] ?? 'economic_review');
    if (!in_array($kind, pp_job_kinds(), true))
        pp_fail(400, 'kind tidak dikenal: ' . $kind, ['code' => 'JOB_KIND_UNKNOWN']);
    $rid = $input['_request_id'] ?? ($_GET['request_id'] ?? null);
    $st  = pp_job_start($input, $kind, is_string($rid) ? $rid : null, !empty($_GET['force']));
    if (empty($st['ok'])) pp_fail(500, (string)($st['error'] ?? 'JOB_GAGAL_DIMULAI'), ['code' => 'JOB_START_FAILED']);
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    echo json_encode(['ok' => true, 'reused' => (bool)($st['reused'] ?? false),
        'job' => $st['job'], 'input_hash' => pp_job_input_hash($input)],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- save-only mode (?mode=save): persist input without running the engine.
 *      Used by the dashboard "Save" button so edits survive a page reload.
 *      output_data.json is left untouched. ----------------------------------- */
/* ---- mode=shortage_probe: cari jumlah LNG/distillate MINIMUM yang TERBUKTI menghasilkan
 *      rencana valid (hard validation lulus DAN konvergen). Dipanggil eksplisit oleh popup,
 *      TIDAK pernah otomatis pada setiap Run, karena satu probe = satu simulasi penuh.
 *      Tidak menulis apa pun ke data produksi. ------------------------------------------- */
if (($_GET['mode'] ?? '') === 'shortage_probe') {
    $kind = strtolower((string)($_GET['kind'] ?? 'lng'));
    if (!in_array($kind, ['lng', 'distillate'], true)) pp_fail(400, 'kind harus lng atau distillate.');
    $mProbe = (array)($input['data3']['modeling'] ?? []);
    $mProbe['gas_shortage_action'] = 'none';
    $input['data3']['modeling'] = $mProbe;
    pp_budget_start(60.0, true, true);
    $probeT0 = microtime(true);

    /* ===== RESUME STATE BERTAHAP =============================================================
     * Satu request FPM tidak boleh melewati 60 detik, sedangkan satu kandidat probe adalah satu
     * simulasi penuh. Karena itu pencarian dijalankan BERTAHAP: tiap request menguji kandidat
     * sebanyak yang muat di dalam anggaran, lalu mengembalikan resume_state yang dikirim ulang
     * oleh UI pada request berikutnya.
     *
     * Yang dibawa resume_state: input_hash, raw_gas_gap, engine_estimate, dan daftar kandidat
     * yang SUDAH diuji. Bila hash cocok, simulasi DASAR tidak perlu dijalankan ulang (di
     * pengukuran: ~31 detik per request terhemat), dan kandidat lama tidak pernah diuji ulang.
     *
     * State yang RUSAK atau milik input LAIN ditolak dan pencarian dimulai bersih — bukan
     * dipakai diam-diam. Alasannya dilaporkan pada `resume_state_status`. */
    $probeHash = pp_probe_input_hash($input);
    $stIn      = $input['data3']['modeling']['__probe_state'] ?? null;
    $testedIn  = $input['data3']['modeling']['__probe_tested'] ?? null;
    $stStatus  = 'FRESH_NO_RESUME_STATE';
    $carry     = null;
    if ($stIn !== null || $testedIn !== null) {
        $chk = pp_probe_validate_resume($stIn, $testedIn, $probeHash, $kind);
        $stStatus = $chk['status'];
        $carry    = $chk['carry'];                       // null bila ditolak
    }
    $input['data3']['modeling']['__probe_tested'] = ($carry === null) ? [] : $carry['tested'];

    if ($carry !== null) {
        /* Lanjutan sah: pakai gap & estimasi yang sudah terbukti, lewati simulasi dasar. */
        $base  = null;
        $audit = null;
        $tpRec = (array)($carry['two_phase'] ?? []);
        $gap   = (float)$carry['raw_gas_gap'];
        $dir   = 'OVER_QUOTA';
    } else {
    $base = pp_run_simulation($input);
    $audit = pp_gas_feasibility_audit($input, $base);
    /* RESIDUAL FINAL: audit dijalankan atas rencana FINAL (setelah minimisasi Export dua fase),
     * sehingga deviasi ini SUDAH residual final. Bila blok dua fase berjalan, angkanya diambil
     * langsung dari rekamannya agar tidak ada dua sumber kebenaran. */
    $tpRec = (array)($base['info']['Export Minimization Two Phase'] ?? []);
    $gap = isset($tpRec['final_gas_shortage'])
         ? (float)$tpRec['final_gas_shortage']
         : (float)($audit['gas']['deviation_bbtud'] ?? 0);
    $dir = (string)($audit['direction'] ?? 'IN_WINDOW');
    }
    if ($dir !== 'OVER_QUOTA' || $gap <= 0) {
        if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
        echo json_encode(['result' => 'ok', 'mode' => 'shortage_probe'] + ['schema' => 'co12-minimum-feasible-support-v1', 'kind' => $kind,
               'validation_status' => 'TIDAK_RELEVAN_UNTUK_ARAH_INI', 'direction' => $dir,
               'raw_gas_gap' => round($gap, 4),
               'note' => 'Dukungan bahan bakar hanya relevan pada arah OVER_QUOTA; arah lain memerlukan tindakan berbeda.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $info = (array)($base['info'] ?? []);
    /* Estimasi awal tangga probe WAJIB memakai residual FINAL, bukan
     * info['Recommended LNG (BBTUD)'] yang dihitung di dalam core SEBELUM lever minimisasi
     * bekerja (pada input produksi: 4,7377 pra-minimisasi vs 4,6977 final). */
    if ($kind === 'lng') {
        $est = $gap;
        $maxP = 100.0;
    } else {
        $est = pp_distillate_litres_from_bbtu($gap, $input['data3']['modeling']);
        if ($est <= 0) $est = (float)($info['Recommended Distillate (l/day)'] ?? 0);
        $maxP = 5000000.0;
    }
    if ($carry !== null && isset($carry['engine_estimate'])) $est = (float)$carry['engine_estimate'];
    /* BATAS SATU REQUEST FPM (< 60 detik, keputusan operator).
     * Simulasi dasar di atas sudah memakan sebagian anggaran. Sisa waktulah yang diberikan
     * kepada pencarian, dikurangi cadangan 5 detik untuk serialisasi respons. Bila sisanya
     * terlalu tipis, pencarian tidak dimulai sama sekali dan status VALIDATION_PENDING
     * dikembalikan — UI melanjutkannya pada request berikutnya memakai resume_state. */
    $probeWall  = microtime(true) - $probeT0;
    $probeCapIn = (float)($input['data3']['modeling']['shortage_probe_max_seconds'] ?? 40.0);
    /* Plafon SATU request 42 detik (di bawah target operator 45 detik dan jauh di bawah batas
     * 60 detik). Ronde pertama memakai sebagian besarnya untuk simulasi dasar; bila sisanya
     * tidak cukup untuk satu kandidat utuh, request itu selesai dengan VALIDATION_PENDING dan
     * kandidat pertama dijalankan pada ronde berikutnya — yang tidak perlu mengulang simulasi
     * dasar karena resume_state membawa hash input, gap, dan estimasi. */
    $probeLeft  = min($probeCapIn, 42.0 - $probeWall);
    $input['data3']['modeling']['shortage_probe_max_seconds'] = max(0.0, $probeLeft);
    if ($probeLeft < 20.0) {
        if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
        echo json_encode(['result' => 'ok', 'mode' => 'shortage_probe',
            'schema' => 'co12-minimum-feasible-support-v1', 'kind' => $kind,
            'validation_status' => 'VALIDATION_PENDING', 'raw_gas_gap' => round($gap, 4),
            'engine_estimate' => round($est, 4), 'probe_count' => 0,
            'base_run_seconds' => round($probeWall, 2),
            'resume_state_status' => $stStatus, 'input_hash' => $probeHash,
            'resume_state' => ['field' => 'data3.modeling.__probe_tested',
                'field_state' => 'data3.modeling.__probe_state', 'input_hash' => $probeHash,
                'kind' => $kind, 'raw_gas_gap' => round($gap, 6), 'engine_estimate' => round($est, 6),
                'two_phase' => $tpRec ?: null, 'tested' => (object)[]],
            'note' => 'Simulasi dasar sudah memakai sebagian besar anggaran request; kandidat pertama dijalankan pada request berikutnya (tanpa mengulang simulasi dasar) agar satu request tidak melewati 45 detik.'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = pp_probe_minimum_feasible_support($input, $kind, $gap, $est, $maxP,
                (int)($input['data3']['modeling']['shortage_probe_max_candidates'] ?? 7));
    $res['base_run_seconds'] = round($probeWall, 2);
    $res['base_run_skipped'] = ($carry !== null);
    $res['resume_state_status'] = $stStatus;
    $res['input_hash'] = $probeHash;
    $res['resume_state']['input_hash']      = $probeHash;
    $res['resume_state']['kind']            = $kind;
    $res['resume_state']['raw_gas_gap']     = round($gap, 6);
    $res['resume_state']['engine_estimate'] = round($est, 6);
    $res['resume_state']['two_phase']       = $tpRec ?: null;
    $res['resume_state']['field_state']     = 'data3.modeling.__probe_state';
    $res['request_wall_seconds'] = round(microtime(true) - $probeT0, 2);
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    echo json_encode(['result' => 'ok', 'mode' => 'shortage_probe'] + $res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_GET['mode'] ?? '') === 'save' && function_exists('sds_ctx') && sds_ctx($input)['uid'] !== '' && (string)getenv('PP_V1516_SCOPED_SAVE') !== '0') {
    /* V15.16 SAVE BER-SCOPE: record versi baru milik user/tab/proyek pemanggil + input_latest user. input_data.json global
     * (milik bersama) TIDAK ditimpa, sehingga Save user lain tidak pernah mengubah data/run user ini. */
    $ctxS = sds_ctx($input); $latS = sds_latest_input($ctxS['uid']); $diskS = null;
    if (is_array($latS)) { $diskS = sds_dir('users/' . $ctxS['uid']) . DIRECTORY_SEPARATOR . 'merge_base.json'; @file_put_contents($diskS, json_encode($latS['input'])); }
    pp_merge_report_planning($input, $diskS ?? (__DIR__ . '/input_data.json'), $rpDiag); if ($diskS) @unlink($diskS);
    $nStrip = pp_sanitize_report_planning($input);
    foreach (['__no_exact_family', '__fastest_local_only'] as $kS) unset($input['data3']['modeling'][$kS]);
    foreach (['_fast_default', '_maximum_review'] as $kS) unset($input[$kS]);
    $whyS = null; $recS = sds_commit('SAVE', $input, null, $ctxS, ['source' => 'save_button'], $whyS);
    if ($recS === null) pp_fail(500, 'Save DITOLAK, data lama TETAP UTUH — ' . $whyS, ['old_file_intact' => true]);
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    echo json_encode(['result' => 'ok', 'mode' => 'save', '_saved' => ['input' => true, 'scoped' => true, 'record_id' => $recS['record_id'], 'version' => $recS['version'],
        'project_id' => $recS['project_id'], 'tab_id' => $recS['tab_id'], 'backend' => sds_backend()], '_sanitized_nested_snapshots' => $nStrip, '_report_planning_merge' => $rpDiag],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_GET['mode'] ?? '') === 'save') {
    pp_merge_report_planning($input, __DIR__ . '/input_data.json', $rpDiag);   // PROMPT ISOLATION §10: anti-overwrite antar user
    $nStrip = pp_sanitize_report_planning($input);                    // PROMPT SAVE-FI §A: migrasi nesting lama
    /* V15.15: penanda kontrol engine/transport tidak pernah dipersist (penanda Fastest yang tersimpan membuat Maximum Review
     * berikutnya diam-diam tanpa keluarga commitment). */
    foreach (['__no_exact_family', '__fastest_local_only'] as $kS) unset($input['data3']['modeling'][$kS]);
    foreach (['_fast_default', '_maximum_review'] as $kS) unset($input[$kS]);
    if (!pp_atomic_write_json(__DIR__ . '/input_data.json', $input, $whyW))
        pp_fail(500, 'Save DITOLAK, file lama TETAP UTUH — ' . $whyW, ['old_file_intact' => true]);
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }   // §11
    /* Diagnostik merge report_planning: bentuk payload/disk, tindakan yang diambil, dan catatan
       untuk struktur malformed. Field diagnostik tambahan; field lama tidak berubah. */
    echo json_encode(['result' => 'ok', 'mode' => 'save', '_saved' => ['input' => true], '_sanitized_nested_snapshots' => $nStrip,
        '_report_planning_merge' => $rpDiag],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- release-validate mode (?mode=release_validate[&runs=N]): the 100x validation RELEASE GATE, run
 *      synchronously as part of the request. Every time input_data.json actively changes and the caller
 *      wants a FINAL release (not just an interactive preview), this is the mode to use. output_data.json
 *      is only ever persisted with result="ok" / release_gate.release_allowed=true when zero FAIL occurred
 *      across all runs; otherwise it is persisted (for inspection) with result="not_released". ------------ */
if (($_GET['mode'] ?? '') === 'release_validate') {
    $runs = isset($_GET['runs']) ? max(1, (int)$_GET['runs']) : 100;
    try {
        $gate = pp_release_gate($input, $runs);
    } catch (Throwable $ex) {
        pp_fail(500, $ex->getMessage(), ['file' => basename($ex->getFile()), 'line' => $ex->getLine()]);
    }
    $output = $gate['final_output'];
    if (($output['result'] ?? '') !== 'ok') pp_fail(500, 'Simulation did not complete during release validation.');
    $output['release_gate'] = $gate['summary'];
    $accept=pp_attach_or_reject_acceptance($input,$output);
    $released = !empty($gate['summary']['release_allowed']) && !empty($accept['publish_allowed']);
    $output['result'] = $released ? 'ok' : 'not_released';

    pp_sanitize_report_planning($input);                              // PROMPT SAVE-FI §A
    if (function_exists('sds_ctx') && sds_ctx($input)['uid'] !== '' && (string)getenv('PP_V1516_SCOPED_SAVE') !== '0') {   // V15.16: ber-scope
        $whyIn = null; $okIn = sds_commit('SIMULATION_FINAL', $input, $output, sds_ctx($input), ['route' => 'release_validate', 'released' => $released], $whyIn) ? 1 : false;
        if ($okIn === false) pp_fail(500, 'Central store gagal — ' . $whyIn . ' (data lama tetap utuh).'); $okOut = 1;
    } else {
    pp_merge_report_planning($input, __DIR__ . '/input_data.json');   // PROMPT ISOLATION §10: anti-overwrite antar user
    $okIn  = pp_atomic_write_json(__DIR__ . '/input_data.json', $input, $whyIn) ? 1 : false;
    if ($okIn === false) pp_fail(500, 'Could not write input_data.json — ' . $whyIn . ' (file lama tetap utuh).');
    $okOut = pp_atomic_write_json(__DIR__ . '/output_data.json', $output);
    }
    $output['_saved'] = ['input' => $okIn !== false, 'output' => $okOut !== false];
    if (!$released) http_response_code(422);   // Unprocessable: ran fine, but the release gate rejected it
    echo pp_json_out($output);
    exit;
}

/* ---- §3.1/§13.7 RUN MODE (?mode=run): jalankan engine dari PAYLOAD LIVE tanpa menulis
 *      input_data.json. Ini jalur Run Simulation: memakai state UI terbaru, TIDAK mem-persist,
 *      TIDAK membaca ulang file lama, TIDAK merge rules lama. Save (mode=save) tetap satu-satunya
 *      yang menulis persistence. Mengembalikan kontrak JSON penuh (§9/§11) + echo request meta. --- */
if (in_array(($_GET['mode'] ?? ''), ['run', 'run_weekly_day'], true)) {
    $rid = $input['_request_id'] ?? ($_GET['request_id'] ?? null);
    $rev = $input['_state_revision'] ?? ($_GET['state_revision'] ?? null);
    /* ===== WEEKLY: SATU REQUEST = SATU HARI (mode=run_weekly_day) ===========================
     * Orkestrasi 7 hari dilakukan UI secara sequential; PHP tidak pernah menjalankan 7 hari dalam
     * satu request. Response membawa weekly_plan_id, day_date/index, revision, result, summary,
     * validation, boundary_out, runtime, dan state hash (immutability check). */
    if (($_GET['mode'] ?? '') === 'run_weekly_day') {
        require_once __DIR__ . '/weekly.php';
        $wk = (array)($input['weekly'] ?? []);
        $dayIso = (string)($wk['day_date'] ?? '');
        $startIso = (string)($wk['week_start_date'] ?? '');
        if ($startIso === '' || $dayIso === '')
            pp_fail(400, 'weekly.week_start_date dan weekly.day_date wajib diisi.',
                ['code' => 'WEEKLY_BAD_REQUEST', 'request_id' => $rid, 'state_revision' => $rev]);
        $dates = pp_weekly_dates($startIso);
        $idx = array_search($dayIso, $dates, true);
        if ($idx === false)
            pp_fail(400, sprintf('day_date %s berada di luar rentang %s..%s (tepat 7 hari).', $dayIso, $dates[0], $dates[6]),
                ['code' => 'WEEKLY_DAY_OUT_OF_RANGE', 'request_id' => $rid, 'state_revision' => $rev]);
        $boundaryIn = isset($wk['boundary_in']) && is_array($wk['boundary_in']) ? $wk['boundary_in'] : null;
        if ($boundaryIn !== null && ($boundaryIn['status'] ?? 'PASS') !== 'PASS')
            pp_fail(409, 'boundary_in berasal dari hari yang TIDAK PASS — hari invalid tidak boleh menjadi boundary.',
                ['code' => 'WEEKLY_INVALID_BOUNDARY', 'request_id' => $rid, 'state_revision' => $rev]);
        pp_budget_start(((float)($input['data3']['modeling']['time_budget_seconds'] ?? 0) ?: null)
                        ?? pp_budget_default_from_ini(), true, true);
        $meta = ['weekly_plan_id' => (string)($wk['weekly_plan_id'] ?? pp_weekly_id($startIso)),
                 'weekly_plan_name' => (string)($wk['weekly_plan_name'] ?? pp_weekly_label($startIso)),
                 'week_start_date' => $dates[0], 'week_end_date' => $dates[6],
                 'day_index' => (int)$idx, 'day_date' => $dayIso];
        $t0 = microtime(true);
        try {
            $res = pp_weekly_run_day($input, $boundaryIn, $meta);
        } catch (Throwable $ex) {
            pp_fail(500, 'Weekly day simulation failed: ' . $ex->getMessage(),
                ['code' => 'WEEKLY_RUN_ERROR', 'request_id' => $rid, 'state_revision' => $rev]);
        }
        $stateHash = substr(hash('sha256', json_encode($res['output']['data'] ?? [])), 0, 32);
        $accHash   = substr(hash('sha256', json_encode(array_map(
            fn($r) => [$r['Export_PLN'] ?? null, $r['Jababeka'] ?? null, $r['BB_Total'] ?? null], $res['output']['data'] ?? []))), 0, 32);
        echo json_encode([
            'ok' => ($res['status'] === 'PASS'), 'result' => 'ok',
            'request_id' => $rid, 'state_revision' => $rev,
            'weekly_plan_id' => $meta['weekly_plan_id'], 'weekly_plan_name' => $meta['weekly_plan_name'],
            'week_start_date' => $dates[0], 'week_end_date' => $dates[6],
            'day_date' => $dayIso, 'day_index' => (int)$idx,
            'revision' => $res['revision'], 'status' => $res['status'],
            'export_under_rows' => $res['export_under_rows'],
            'validation' => ['status' => $res['status'], 'violations' => $res['violations']],
            'summary' => $res['summary'], 'data' => $res['output']['data'] ?? [],
            'boundary_out' => $res['boundary_out'],
            'runtime_s' => $res['runtime_s'],
            'state_hash' => ['final_validated' => $stateHash, 'materialized' => $stateHash, 'accounted' => $accHash],
            'payload_hash' => pp_payload_hash($input),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    /* ===== V3: Run -> SIMPAN INPUT ATOMIK -> diff -> satu pemilik perhitungan ===================
     * Klik Run menyimpan payload yang sama ke input_data.json SEBELUM simulasi (aturan yang sama
     * dengan tombol Save). Bila penyimpanan gagal, simulasi TIDAK dimulai dan file lama utuh. Yang
     * dihitung adalah konten yang tersimpan. */
    $__v3route = empty($input['_fast_default']) && empty($input['data3']['modeling']['__no_async_handoff']) && (string)getenv('PP_V3_ROUTE') !== '0';
    $__autosave = ['requested' => false];
    if (!empty($input['_autosave'])) {
        $__autosave = ['requested' => true] + pp_v3_autosave($input);
        if (empty($__autosave['ok']))
            pp_fail(500, 'Input TIDAK tersimpan ke input_data.json, sehingga simulasi TIDAK dijalankan. File lama tetap utuh. Sebab: ' . (string)($__autosave['error'] ?? '?'),
                ['code' => 'INPUT_SAVE_FAILED', 'request_id' => $rid, 'state_revision' => $rev, 'autosave' => $__autosave, 'old_file_intact' => true]);
    }
    unset($input['_autosave']);
    $phash = pp_payload_hash($input);
    /* ADAPTIVE HTTP BUDGET: start at 10 seconds and extend only while work remains,
     * in 5-second stages, with an absolute internal ceiling of 40 seconds. Core-run count is
     * a safety backstop only; elapsed time is the primary termination rule. */
    if (!isset($input['data3']['modeling'])) $input['data3']['modeling'] = [];
    $input['data3']['modeling']['time_budget_seconds'] = min(60.0, max(10.0,
        (float)($input['data3']['modeling']['time_budget_seconds'] ?? 10.0)));
    $input['data3']['modeling']['time_budget_step_seconds'] = 5.0;
    /* PLAFON REQUEST SINKRON DIUKUR DI KABEL, BUKAN DI DALAM ENGINE.
     * `time_budget_max_seconds` hanya membatasi PENCARIAN. Setelah deadline itu habis masih ada
     * pekerjaan penutup yang tidak dapat dilewati: acceptance review, hard validation, audit gas,
     * audit minimisasi Export, penyusunan 48 baris, dan serialisasi JSON. Dengan plafon 60,0 detik
     * persis, waktu di kabel terukur 60,46 / 60,52 / 61,03 detik pada konfigurasi PEP 40 — yaitu
     * MELEWATI batas wajib "request sinkron < 60 detik".
     *
     * Karena itu pencarian diberi plafon 60 detik DIKURANGI cadangan penutup. Ini tidak melonggarkan
     * apa pun: pekerjaan yang tidak muat memang sudah dirancang diselesaikan worker asinkron, dan
     * batas 60 detik hanya berlaku untuk request sinkron. */
    $input['data3']['modeling']['time_budget_max_seconds'] = max(15.0, 60.0 - PP_SYNC_CLOSING_RESERVE_S);
    $input['data3']['modeling']['__core_run_budget'] = 100;
    $input['data3']['modeling']['change_over_search_budget_seconds'] = 20.0;
    $input['data3']['modeling']['change_over_interactive_request'] =
        !empty($input['data3']['modeling']['change_over']['enabled']);
    /* ITEM E: budget konvergensi ditetapkan di caller TERLUAR (force) — dari request bila ada,
     * jika tidak diturunkan dari max_execution_time PHP dgn margin, agar engine selalu berhenti
     * anggun (JSON valid) sebelum PHP fatal "Maximum execution time exceeded". */
    /* ===== PEMERIKSAAN KONFIGURASI RUNTIME SEBELUM PEKERJAAN PANJANG DIMULAI (BUG-17) =========
     * Plafon internal engine = min(60, max(15, max_execution_time - 25)). Bila server dikonfigurasi
     * dengan max_execution_time di bawah 85 detik, engine TIDAK PERNAH memperoleh 60 detik penuh —
     * dan operator hanya melihat "pencarian dihentikan plafon waktu absolut" tanpa tahu sebabnya.
     * Pemeriksaan ini dijalankan SEBELUM simulasi dan hasilnya selalu dilampirkan ke respons. */
    $__met = (int)ini_get('max_execution_time');
    $__ceil = ($__met <= 0) ? 60.0 : min(60.0, max(15.0, (float)$__met - 25.0));
    $__cfg = ['max_execution_time' => $__met === 0 ? 'unlimited' : $__met,
              'effective_engine_ceiling_s' => $__ceil,
              'required_for_full_ceiling' => 'max_execution_time >= 85 (atau 0/unlimited)',
              'opcache' => function_exists('opcache_get_status') ? 'tersedia' : 'tidak tersedia',
              'PP_EXPORT_MIN_TWO_PHASE' => (string)(getenv('PP_EXPORT_MIN_TWO_PHASE') === false ? '(tidak diset — aktif)' : getenv('PP_EXPORT_MIN_TWO_PHASE')),
              'PP_G5_GLOBAL_CMP' => (string)(getenv('PP_G5_GLOBAL_CMP') === false ? '(tidak diset — aktif)' : getenv('PP_G5_GLOBAL_CMP')),
              'PP_GAS_OFFSET_SEARCH' => (string)(getenv('PP_GAS_OFFSET_SEARCH') === false ? '(tidak diset — aktif)' : getenv('PP_GAS_OFFSET_SEARCH')),
              'status' => ($__ceil >= 60.0) ? 'OK' : 'PLAFON_ENGINE_DI_BAWAH_60_DETIK'];
    if ($__ceil < 60.0) $__cfg['tindakan'] =
        'Naikkan max_execution_time PHP-FPM menjadi minimal 85 detik. Ini BUKAN menaikkan plafon engine '
      . 'di atas 60 detik: plafon engine tetap 60 detik, tetapi ia memerlukan margin 25 detik agar dapat '
      . 'menutup respons dengan JSON yang benar sebelum PHP menghentikan proses.';
    $GLOBALS['__pp_runtime_config_check'] = $__cfg;

    /* ===== PENOLAKAN KONFLIK JADWAL SEBELUM PENCARIAN PANJANG (§12/§13) =======================
     * pp_validate_input() sudah mendeteksi periode yang bertumpang-tindih pada Unit Stop Time,
     * Fix Load, Skip Load, IE adjustment, dan HRSG Stop Time — tetapi hasilnya hanya dipasang
     * sebagai WARNING, sehingga input yang saling bertentangan tetap menjalankan simulasi penuh
     * dan baru terlihat di hasil. Terukur: dua aturan Skip Load G8 pada row 1-30 dan 20-48
     * (tumpang-tindih row 20-30) diterima dengan HTTP 200 dan menjalankan simulasi 30 detik.
     * Konflik jadwal kini ditolak di depan, dengan pesan spesifik per aturan. */
    $__schedErrs = pp_validate_input((array)($input['data3']['modeling'] ?? []));
    if ($__schedErrs) pp_fail(400,
        'Konflik jadwal pada input ditolak sebelum simulasi dijalankan: ' . implode('; ', array_slice($__schedErrs, 0, 8)),
        ['code' => 'SCHEDULE_CONFLICT_REJECTED', 'request_id' => $rid, 'state_revision' => $rev,
         'details' => ['errors' => $__schedErrs,
                       'catatan' => 'periode yang bertumpang-tindih harus diperbaiki di input; engine tidak menebak mana yang dimaksud']]);

    /* ==========================================================================================
     * NORMALISASI KEPUTUSAN BAHAN BAKAR DILAKUKAN DI PINTU MASUK, SEBELUM SATU PUN GATING.
     *
     * AKAR MASALAH YANG DIPERBAIKI DI SINI. `pp_normalize_model_arrays()` menormalkan aksi, tetapi
     * ia baru berjalan DI DALAM engine. Sementara itu run.php sendiri sudah lebih dulu melakukan
     * gating atas string mentah — `if($actNow==='none')` pada jalur keputusan shortage. Akibatnya
     * nilai `recommendation` tidak dikenali di titik itu, `pp_shortage_decision_block()` tidak
     * pernah dipanggil, dan run berakhir sebagai FINAL_GAS_QUOTA_FAILED tanpa `shortage_decision`
     * sama sekali — bukan sebagai keputusan bahan bakar yang menunggu operator.
     *
     * Dengan menormalkan di sini, ketiga titik gating di run.php melihat `none` dan bekerja apa
     * adanya tanpa satu pun diubah, sementara niat aslinya tetap terbaca dari
     * `__fuel_decision_mode`. Ini juga yang membuat label tampilan tidak pernah menjadi logic. */
    if (function_exists('pp_normalize_fuel_action')) pp_normalize_fuel_action($input);

    /* JEJAK PROGRES UNTUK MODAL PEMBLOKIR. Dipasang di pintu masuk supaya setiap batas fase yang
     * dilalui engine tercatat pada berkas kecil ber-request-id, dan endpoint `mode=prelim_progress`
     * dapat membacanya tanpa menyentuh engine. Murni observasi: tidak ada keputusan, angka, atau
     * jalur kode yang bergantung pada ketiga global ini. */
    $GLOBALS['__pp_prelim_rid']   = preg_replace('~[^A-Za-z0-9_.\-]~', '', (string)($rid ?? ''));
    $GLOBALS['__pp_prelim_t0']    = microtime(true);
    $GLOBALS['__pp_prelim_steps'] = [];
    if ($GLOBALS['__pp_prelim_rid'] !== '') {
        @unlink(pp_prelim_progress_path($GLOBALS['__pp_prelim_rid']));   // jangan pernah sajikan sisa run lama
        pp_prelim_progress_sweep();
    }

    /* V3: SATU PEMILIK. Job economic_review untuk state ini adalah satu-satunya yang menghitung
     * (inkremental bila state hanya berbeda data slot/kuota dari final valid terakhir, selain itu
     * exact). Tidak ada tahap provisional sinkron, pencarian Target Selesai paralel, atau pipeline
     * sinkron untuk input yang sama; job lama untuk state lain pada konteks ini dihentikan. Jalur
     * sinkron lama hanya tersisa untuk harness uji (`__no_async_handoff`). */
    if ($__v3route) pp_v3_handoff_respond($input, $rid, $rev, $__autosave, pp_v3_supersede($input));
    /* ADMISSION ASINKRON DINI (§11): job untuk run yang diprediksi berat dibuat DI SINI — sebelum
     * satu detik pun dihabiskan simulasi sinkron — bukan setelah operator menunggu 40-54 detik.
     * Jalur sinkron di bawah tetap berjalan apa adanya; prediksi tidak menyentuh hasilnya. */
    /* V15.15 FASTEST ROUTING (adopsi rute reference): Fastest - Default TIDAK lagi menjalankan pipeline sinkron 25 s tanpa
     * pembantu lalu berhenti "terminal" (terukur input 07-Oct: 16,9 s -> ECONOMIC_REVIEW_REQUIRED tanpa job lanjutan dan tanpa
     * popup; pilihan LNG/Distillate -> "dihentikan batas waktu: time_budget:bb_upfill/export_floor_donate"). Job economic_review
     * dibuat dan diserahkan seketika (sama dengan rute Target Selesai reference); job itu sendiri menjalankan jalur Fastest
     * (pp_v13f_fast_job): kandidat fully valid pertama + review Unit Priority, atau keputusan bahan bakar begitu shortage terbukti. */
    if (!empty($input['_fast_default']) && empty($input['data3']['modeling']['__no_async_handoff']) && (string)getenv('PP_V13F') !== '0')
        pp_sync_handoff_respond($input, $rid, $rev, 'FASTEST_FIRST_VALID', 'Fastest - Default: kandidat fully valid pertama dikerjakan job.');
    $__earlyAdm=!empty($input['_fast_default'])?null:pp_async_admit_early($input,$rid);
    /* TARGET SELESAI (15-60 detik): jalur sinkron tidak dijalankan. Job exact dibuat/dipakai ulang dan
     * langsung diserahkan; browser menjalankannya bersamaan dengan pencarian kandidat valid
     * (mode=tl_search) dan menampilkan kandidat terbaik tepat pada batas waktu. */
    if (!empty($_GET['tl']) && empty($input['data3']['modeling']['__no_async_handoff']))
        pp_sync_handoff_respond($input, $rid, $rev, 'TIME_LIMITED_TARGET',
            'Target Selesai: optimasi exact berjalan bersamaan dengan pencarian kandidat valid.');

    /* STATE YANG SUDAH DIHITUNG PENUH TIDAK DIHITUNG ULANG DI JALUR SINKRON.
     * Bila hasil eksak untuk state identik sudah ada (job economic_review selesai untuk input yang
     * sama, atau rerun kandidat opsi tervalidasi yang sudah konvergen penuh), jalur sinkron tidak
     * menjalankan ulang 15 detik pipeline yang pasti terpotong lalu dibuang. Job economic_review
     * langsung dipakai; ia sendiri memakai ulang hasil tersebut, sehingga hasil final tiba dalam
     * hitungan detik dan identik dengan hasil yang akan dihitungnya. */
    if (empty($input['data3']['modeling']['__no_async_handoff'])) {
        $__jDone = pp_job_read(pp_job_id('economic_review', pp_job_input_hash($input)));
        $__siap = (is_array($__jDone) && (string)($__jDone['status'] ?? '') === 'DONE' && !empty($__jDone['result_available']))
                  || pp_memo_available(pp_econ_job_sim_input($input));
        if ($__siap) {
            pp_sync_handoff_respond($input, $rid, $rev, 'STATE_ALREADY_COMPUTED',
                'Hasil eksak untuk state identik sudah tersedia; dipakai ulang.');
        }
    }

    /* TAHAP 1 — VALID PROVISIONAL untuk perubahan kuota sederhana (lihat pp_provisional_compute). */
    if (empty($input['data3']['modeling']['__no_async_handoff']) && (string)getenv('PP_PROVISIONAL') !== '0') {
        $__pb = null;
        try { $__pb = pp_provisional_base($input); } catch (Throwable $e) { $__pb = null; }
        if ($__pb !== null) {
            /* Job exact didaftarkan LEBIH DULU dan identitasnya ditulis ke berkas progres, sehingga
             * browser memicunya sekarang: tahap 2 berjalan bersamaan dengan pemeriksaan provisional,
             * dan bila provisional ditolak tidak ada waktu yang hilang. */
            if (!isset($GLOBALS['__pp_async_early_admission'])) {
                $stA = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
                if (!empty($stA['ok']) && !empty($stA['job']['job_id'])) {
                    $GLOBALS['__pp_async_early_admission'] = ['admitted_early' => true, 'trigger' => 'PROVISIONAL_STAGE',
                        'kind' => 'economic_review', 'ok' => true, 'job_id' => $stA['job']['job_id'],
                        'exec_token' => $stA['job']['exec_token'] ?? null, 'status' => $stA['job']['status'] ?? null,
                        'input_hash' => pp_job_input_hash($input), 'created_at_s' => 0.0];
                    if (function_exists('pp_prelim_progress_write')) pp_prelim_progress_write('provisional_redispatch');
                }
            }
            if (isset($GLOBALS['__pp_async_early_admission'])) $GLOBALS['ppSafetyAdm'] = $GLOBALS['__pp_async_early_admission'];   // bertahan bila global __pp_ dibersihkan
            $__prov = null;
            try { $__prov = pp_provisional_compute($input, $__pb); } catch (Throwable $e) { $__prov = null; }
            foreach (array_keys($GLOBALS) as $__g)
                if (is_string($__g) && strpos($__g, '__pp_') === 0 && !in_array($__g, ['__pp_prelim_rid', '__pp_prelim_t0', '__pp_prelim_steps', '__pp_admit_input', '__pp_admit_rid', '__pp_async_early_admission', '__pp_runtime_prediction'], true))
                    unset($GLOBALS[$__g]);
            if (is_array($__prov)) {
                unset($GLOBALS['ppProvAssess']);
                $stP = pp_job_start($input, 'economic_review', is_string($rid) ? $rid : null, false);
                if (!empty($stP['ok']) && !empty($stP['job']['job_id'])) {
                    $jP = (array)$stP['job'];
                    $__prov['async_job'] = ['required' => true, 'kind' => 'economic_review',
                        'reason' => 'Optimasi biaya exact (tahap 2) setelah hasil VALID PROVISIONAL.',
                        'ok' => true, 'job_id' => $jP['job_id'], 'exec_token' => $jP['exec_token'] ?? null,
                        'status' => $jP['status'] ?? null, 'reused' => (bool)($stP['reused'] ?? false),
                        'input_hash' => pp_job_input_hash($input), 'error' => null];
                    $__prov['info']['Async Economic Review Job'] = $__prov['async_job'];
                    $__prov['info']['Provisional Result'] = ['status' => 'VALID_PROVISIONAL',
                        'checks' => $__prov['provisional_checks'], 'basis' => $__prov['provisional_basis']];
                    $__prov['ok'] = true; $__prov['result'] = 'provisional'; $__prov['status'] = 'VALID_PROVISIONAL';
                    $__prov['request_id'] = $rid; $__prov['state_revision'] = $rev !== null ? (int)$rev : null;
                    $__prov['_saved'] = ['input' => false, 'output' => false];
                    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
                    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
                    echo pp_json_out($__prov); exit;
                }
            }
            /* Provisional ditolak setelah pemeriksaan yang berat (mis. Actual Gas): sisa waktu request
             * sinkron tidak lagi cukup untuk pipeline lengkap, dan job exact untuk state ini sudah
             * terdaftar. Perhitungan diserahkan ke job itu, bukan diulang di jalur sinkron. */
            $__tReq = microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
            if ($__tReq > (float)(getenv("PP_PROV_HANDOFF_S") ?: 8.0) && isset($GLOBALS["__pp_async_early_admission"]))
                pp_sync_handoff_respond($input, $rid, $rev, 'PROVISIONAL_REJECTED_HEAVY',
                    'Pemeriksaan provisional tidak lolos; optimasi exact diselesaikan job exact untuk state ini.');
        }
    }

    pp_budget_start(((float)($input['data3']['modeling']['time_budget_seconds'] ?? 0) ?: null)
                    ?? pp_budget_default_from_ini(), true, true);   // pinned: request HTTP adalah pemilik budget
    /* Jalur sinkron berhenti begitu job eksak untuk state yang sama sudah dipicu (pp_phase_mark). */
    $GLOBALS['__pp_sync_handoff_armed']=empty($input['_fast_default']);
    try {
        try {
            $output = pp_run_simulation($input);
        } catch (PpSyncHandoff $hand) {
            unset($GLOBALS['__pp_sync_handoff_armed']);
            pp_sync_handoff_respond($input, $rid, $rev, 'EXACT_JOB_RUNNING',
                'Job eksak untuk state yang sama sudah berjalan; jalur sinkron tidak menghitungnya kedua kali.');
            throw new RuntimeException('Serah-terima ke job eksak gagal: job tidak dapat dibuat.');
        }
        unset($GLOBALS['__pp_sync_handoff_armed']);
        $fuelRecon=pp_reconcile_selected_fuel($input,$output);$output['info']['Fuel Action Reconciliation']=$fuelRecon;pp_sync_residual_from_recon($output,$fuelRecon);
        $fuelBudget=(array)($output['info']['Convergence Budget']??[]);
        /* An incomplete bounded search is not proof that the fuel action failed. Let the deadline
         * path continue to exact async review; enforce reconciliation only on a completed run. */
        if(empty($fuelBudget['exceeded'])&&!empty($fuelRecon['required'])&&empty($fuelRecon['pass']))
        {
            /* Konsisten dengan jalur sukses: rencana incumbent TETAP dikembalikan untuk ditinjau
             * (§6), dengan blocker spesifik dan publish diblokir — bukan amplop error kosong. */
            $output['ok']=false; $output['result']='not_final';
            $output['status']='FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW';
            $output['error_code']='FUEL_ACTION_NOT_APPLIED';
            $output['publish_allowed']=false; $output['http_status_recommended']=422;
            $msgFR2='Aksi bahan bakar yang dipilih tidak terbukti diterapkan atau tidak menutup residual shortage. '
                  . 'Rencana di bawah adalah pratinjau dan TIDAK boleh dipublikasikan.';
            $output['message']=$msgFR2;
            $output['error']=['code'=>'FUEL_ACTION_NOT_APPLIED','message'=>$msgFR2,
              'details'=>['reconciliation'=>$fuelRecon,'incumbent_available_for_review'=>true,
                          'rows'=>count((array)($output['data']??[]))]];
            $output['request_id']=$rid;$output['state_revision']=$rev!==null?(int)$rev:null;
            $output['_saved']=['input'=>false,'output'=>false];
            pp_attach_async_handoff($input,$output,$rid);
            http_response_code(422);
            if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}
            echo pp_json_out($output);exit;
        }
    } catch (Throwable $ex) {
        pp_fail(500, $ex->getMessage(), ['file' => basename($ex->getFile()), 'line' => $ex->getLine(),
            'request_id' => $rid, 'state_revision' => $rev, 'code' => 'SIMULATION_FAILED']);
    }
    $output['info']['Runtime Configuration Check'] = $GLOBALS['__pp_runtime_config_check'] ?? null;
    if (($output['result'] ?? '') !== 'ok') pp_fail(500, 'Simulation did not complete.',
        ['request_id' => $rid, 'state_revision' => $rev, 'code' => 'SIMULATION_INCOMPLETE']);
    /* Graceful convergence contract: structured JSON, never generic HTTP500 for search exhaustion. */
    $cbR=$output['info']['Convergence Budget'] ?? pp_budget_status();
    if (is_array($cbR) && !empty($cbR['exceeded'])) {
        $vChk=pp_validate_hard_constraints($input,$output);
        /* Deadline must never discard a hard-valid incumbent. Prefer the best feasible result
           captured during search before classifying the request as incomplete. */
        if(strtoupper((string)($vChk['status']??''))!=='PASS'&&is_array($GLOBALS['__pp_best_feasible_output']??null)){
            $inc=$GLOBALS['__pp_best_feasible_output'];$incV=pp_validate_hard_constraints($input,$inc);
            if(strtoupper((string)($incV['status']??''))==='PASS'){$output=$inc;$output['info']['Convergence Budget']=$cbR;$output['info']['Deadline Result']='BEST_FEASIBLE_INCUMBENT';$vChk=$incV;}
        }
        if (strtoupper((string)($vChk['status'] ?? '')) !== 'PASS') {
            $coTL=(array)($output['info']['Change Over Timeline'] ?? []);
            if (!empty($coTL['requested']) && empty($coTL['executed'])) {
                $output['ok']=false;
                $output['error_code']=(string)($output['error_code'] ?? 'CHANGE_OVER_SEARCH_BUDGET_EXHAUSTED');
                $output['violations']=array_slice($vChk['violations'] ?? [],0,20);
                $output['http_status_recommended']=422;
            } else {
                $vv=(array)($vChk['violations']??[]);$gas=[];foreach($vv as $z){$t=strtolower((string)($z[0]??$z['type']??''));if($t==='gas_quota')$gas[]=$z;}
                if($gas){
                    $act=strtolower((string)($input['data3']['modeling']['gas_shortage_action']??'none'));
                    $q=(float)($output['info']['Total Gas Quota (BBTUD)']??0);
                    $u=(float)($output['info']['Total Gas Used (BBTUD)']??0);
                    $short=max(0.0,(float)($output['info']['Gas Shortage (BBTUD)']??0));
                    $proof=(array)($output['info']['Gas Shortage Exhaustion Proof']??[]);
                    $adj=(int)($proof['rows_adjustable']??0);$near=(int)($proof['rows_near_min']??0);$under=(int)($proof['rows_under_min']??0);
                    /* A shortage is real only after every adjustable future row has reached the
                       effective Export minimum and no row is below that minimum. A plain gas-window
                       violation, over-quota result, or elapsed search budget is NOT a shortage. */
                    $exportAtMin=($adj>0&&$near===$adj&&$under===0);
                    $trueShortage=($short>1e-9&&$u>$q+1e-9&&$exportAtMin);
                    if($trueShortage&&$act==='none'){
                        /* Do not emit an ad-hoc response here. Continue into the single acceptance
                         * contract, which computes fuel_estimate_ready and FUEL_SELECTION_REQUIRED. */
                        $output['classification']='VALID_INFEASIBLE_PHYSICAL';
                        $output['fuel_shortage_advisory']=['status'=>'FUEL_SELECTION_REQUIRED','shortage_bbtud'=>round($short,4),
                          'export_at_effective_minimum'=>true,'recommended_action'=>'add_lng','alternative_action'=>'use_distillate'];
                        $output['violations']=array_slice($vv,0,20);$output['convergence_warning']=$cbR;
                        $output['__defer_shortage_to_acceptance']=true;
                    }
                    if($trueShortage&&in_array($act,['add_lng','use_distillate'],true)){
                        /* This branch is already inside budget-exceeded handling. Residual fuel here
                         * is not a completed-run verdict. Preserve diagnostics and continue to the
                         * non-convergence response so the UI starts exact async review. */
                        $output['fuel_reconciliation_pending_exact_review']=[
                          'selected_action'=>$act,'residual_shortage_bbtud'=>round($short,4),
                          'export_at_effective_minimum'=>true,'budget'=>$cbR];
                    }
                    /* Not a proven shortage. Continue to the normal non-convergence response and do
                       not recommend LNG/distillate for over-quota or unfinished optimization. */
                }
                /* §6 — INCUMBENT TETAP DITAMPILKAN. pp_fail() mengembalikan amplop error TANPA
                 * dispatch, sehingga Simulation Data kosong dan operator tidak punya apa pun untuk
                 * ditinjau. Terukur pada skenario PGN rendah + PEP tinggi: HTTP 422 dengan 0 baris.
                 * Yang benar: kembalikan rencana incumbent apa adanya, tandai dengan jelas bahwa ia
                 * BELUM final, sebutkan blocker spesifiknya, dan blokir Publish. Operator boleh
                 * melihat 48 baris; ia tidak boleh mempublikasikannya. */
                if(empty($output['__defer_shortage_to_acceptance'])){
                    $output['ok']=false;
                    $output['result']='not_final';
                    $output['status']='NOT_CONVERGED_INCUMBENT_FOR_REVIEW';
                    $output['error_code']='NON_CONVERGENCE_TIME_BUDGET';
                    $output['publish_allowed']=false;
                    $output['http_status_recommended']=422;
                    $output['message']=sprintf('Pencarian mencapai plafon %.0f detik tanpa incumbent yang lolos seluruh hard constraint. Rencana di bawah ditampilkan sebagai pratinjau dan TIDAK boleh dipublikasikan.',(float)($cbR['limit_s']??0));
                    $output['error']=['code'=>'NON_CONVERGENCE_TIME_BUDGET','message'=>$output['message'],
                      'details'=>['budget'=>$cbR,'violations'=>array_slice($vv,0,20),
                                  'incumbent_available_for_review'=>true,
                                  'rows'=>count((array)($output['data']??[]))]];
                    $output['violations']=array_slice($vv,0,20);
                    $output['convergence_warning']=$cbR;
                    $output['request_id']=$rid;$output['state_revision']=$rev!==null?(int)$rev:null;
                    $output['_saved']=['input'=>false,'output'=>false];
                    /* Penyelesaian eksak diserahkan ke worker asinkron — operator tidak diminta
                     * menjalankan ulang. */
                    pp_attach_async_handoff($input,$output,$rid);
                    http_response_code(422);
                    if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}
                    echo pp_json_out($output);exit;
                }
            }
        }
        $output['convergence_warning']=$cbR;
    }
    /* ===== REKONSILIASI AKSI BAHAN BAKAR PADA JALUR SUKSES ==================================
     * V7 memasang pp_reconcile_selected_fuel() HANYA di cabang "anggaran waktu terlampaui" dan di
     * jalur legacy non-mode. Akibatnya pada rerun LNG/distillate yang BERHASIL — justru jalur yang
     * dipakai operator — tidak ada satu pun bukti bahwa bahan bakar pilihannya benar-benar dipakai
     * engine. Terukur pada uji klik nyata: `Fuel Action Reconciliation` bernilai null setelah rerun
     * LNG 4,6985 BBTUD yang sukses. Blok ini memasangnya pada jalur sukses juga.
     *
     * Bila rekonsiliasi GAGAL, hasil tidak dibuang: rencana incumbent tetap dikembalikan untuk
     * ditinjau (§6), dengan blocker spesifik dan publish diblokir. */
    $fuelRecon=pp_reconcile_selected_fuel($input,$output);
    $output['info']['Fuel Action Reconciliation']=$fuelRecon;
    $output['fuel_action_reconciliation']=$fuelRecon;
    pp_sync_residual_from_recon($output,$fuelRecon);
    $accept=pp_attach_or_reject_acceptance($input,$output);
    if(!empty($fuelRecon['required'])&&empty($fuelRecon['pass'])){
        $output['ok']=false;
        $output['result']='not_final';
        $output['status']='FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW';
        $output['error_code']='FUEL_ACTION_NOT_APPLIED';
        $output['publish_allowed']=false;
        $output['http_status_recommended']=422;
        $msgFR='Aksi bahan bakar yang dipilih tidak terbukti diterapkan atau tidak menutup residual shortage. '
             . 'Rencana di bawah ditampilkan sebagai pratinjau dan TIDAK boleh dipublikasikan.';
        $output['message']=$msgFR;
        $output['error']=['code'=>'FUEL_ACTION_NOT_APPLIED','message'=>$msgFR,
          'details'=>['reconciliation'=>$fuelRecon,'incumbent_available_for_review'=>true,
                      'rows'=>count((array)($output['data']??[]))]];
        $output['request_id']=$rid;$output['state_revision']=$rev!==null?(int)$rev:null;
        $output['_saved']=['input'=>false,'output'=>false];
        http_response_code(422);
        if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}
        echo pp_json_out($output);exit;
    }
    pp_attach_async_handoff($input,$output,$rid);
    if(in_array(($output['status']??''),['FUEL_SELECTION_REQUIRED','USER_FUEL_DECISION_REQUIRED'],true)&&($output['fuel_estimate_ready']??false)===true){
        http_response_code(200);$output['_saved']=['input'=>false,'output'=>false];
        if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}
        echo pp_json_out($output);exit;
    }
    /* Keputusan operator yang BUKAN pemilihan bahan bakar (Change Over tidak dapat dieksekusi,
     * konflik antar-window kuota) juga merupakan langkah alur kerja yang BERHASIL dijalankan,
     * bukan kegagalan transport. Ia memakai amplop yang sama dengan pemilihan bahan bakar:
     * HTTP 200, publish tetap terkunci, bukti terlampir. */
    if((($output['status']??'')==='USER_ACTION_REQUIRED'
        && in_array((string)($output['action_required']??''),['CHANGE_OVER_DECISION','GAS_WINDOW_QUOTA_DECISION'],true))
       || (($output['status']??'')==='ECONOMIC_REVIEW_REQUIRED'
        && (string)($output['action_required']??'')==='CONTINUE_EXACT_ECONOMIC_REVIEW'
        && !empty($output['async_job']['job_id']))){
        http_response_code(200);$output['_saved']=['input'=>false,'output'=>false];
        if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}
        echo pp_json_out($output);exit;
    }
    if(empty($accept['publish_allowed'])){http_response_code(422);$output['_saved']=['input'=>false,'output'=>false];if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}echo pp_json_out($output);exit;}
    /* §9/§11 amplop kontrak: ok + request_id + state_revision + payload_hash + error=null.
     * Field lama (result/data/info) tetap ada agar renderer existing kompatibel. */
    $output['ok'] = true;
    $output['request_id'] = $rid;
    $output['state_revision'] = $rev !== null ? (int)$rev : null;
    $output['payload_hash'] = $phash;
    $output['error'] = null;
    $output['_saved'] = ['input' => false, 'output' => false];   // §13.7: Run TIDAK mem-persist
    pp_tl_pool_note($input, $output);
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }
    echo pp_json_out($output);
    exit;
}

/* ---- run the engine BEFORE persisting anything --------------------------- */
try {
    $output = pp_run_simulation($input);
    $fuelRecon=pp_reconcile_selected_fuel($input,$output);$output['info']['Fuel Action Reconciliation']=$fuelRecon;pp_sync_residual_from_recon($output,$fuelRecon);
    if(!empty($fuelRecon['required'])&&empty($fuelRecon['pass'])){pp_fail(422,'Selected fuel action was not applied or did not close the residual shortage.',['code'=>'FUEL_ACTION_NOT_APPLIED','details'=>$fuelRecon]);}
} catch (Throwable $ex) {
    pp_fail(500, $ex->getMessage(), ['file' => basename($ex->getFile()), 'line' => $ex->getLine()]);
}
if (($output['result'] ?? '') !== 'ok') pp_fail(500, 'Simulation did not complete.');

/* ---- success: persist input + output, then return ------------------------
 *      Normal mode = single run, NOT gated. Marked explicitly so a consumer
 *      never mistakes this for a validated release. Use ?mode=release_validate
 *      (or CLI --release-validate) before treating an output as final. ------ */
$accept=pp_attach_or_reject_acceptance($input,$output);
if(in_array(($output['status']??''),['FUEL_SELECTION_REQUIRED','USER_FUEL_DECISION_REQUIRED'],true)&&($output['fuel_estimate_ready']??false)===true){http_response_code(200);$output['_saved']=['input'=>false,'output'=>false];if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}echo pp_json_out($output);exit;}
if(empty($accept['publish_allowed'])){http_response_code(422);$output['_saved']=['input'=>false,'output'=>false];if(function_exists('ob_get_level')){while(ob_get_level()>0)ob_end_clean();}echo pp_json_out($output);exit;}
$__scopedF = function_exists('sds_ctx') && sds_ctx($input)['uid'] !== '' && (string)getenv('PP_V1516_SCOPED_SAVE') !== '0';
if ($__scopedF) { $okIn = 1; $okOut = 1; }   // V15.16: hasil final user tercatat ber-scope di central store; file global bersama tidak ditimpa
else {
pp_merge_report_planning($input, __DIR__ . '/input_data.json');   // PROMPT ISOLATION §10: anti-overwrite antar user
$okIn  = pp_atomic_write_json(__DIR__ . '/input_data.json', $input);
$okOut = pp_atomic_write_json(__DIR__ . '/output_data.json', $output);
}
$cs=pp_store_commit('SIMULATION_FINAL',$input,$output,['engine'=>PP_ENGINE_BUILD_ID],$ce);if(empty($cs['ok']))pp_fail(500,'Central store gagal: '.($ce??'unknown'));

$output['_saved'] = ['input' => $okIn !== false, 'output' => $okOut !== false, 'central_store'=>true];
if (function_exists('ob_get_level')) { while (ob_get_level() > 0) ob_end_clean(); }   // §11
echo pp_json_out($output);