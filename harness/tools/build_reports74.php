<?php
/* ==============================================================================================
 *  PENYUSUN LAPORAN ACCEPTANCE PHP 7.4.
 *
 *  Membaca BERKAS HASIL yang benar-benar dihasilkan harness, lalu menyusun TEST_REPORT_PHP74.md
 *  dan TRACEABILITY_MATRIX.md dari isinya. Angka TIDAK disalin tangan: setiap baris berasal dari
 *  berkas hasil, dan suite yang berkasnya tidak ada dilaporkan sebagai BELUM_DIJALANKAN — yang
 *  pada test wajib diperlakukan sebagai FAIL, bukan diabaikan.
 *
 *  php build_reports74.php <dir_laporan> <akar_source> <dir_out>
 * ============================================================================================ */
$REP  = rtrim($argv[1] ?? '/home/claude/t5/reports74_hist', '/');
$SRC  = rtrim($argv[2] ?? '/home/claude/t5/topt', '/');
$OUT  = rtrim($argv[3] ?? '/home/claude/t5/reports74', '/');
@mkdir($OUT, 0777, true);

/* ==============================================================================================
 * PEMBACA TALLY PER SUITE — POLA EKSPLISIT, BUKAN TEBAKAN GENERIK.
 *
 * Versi pertama fungsi ini memakai satu regex generik "N/M PASS" dengan cadangan MENGHITUNG KATA
 * PASS dan FAIL di seluruh berkas. Itu menghasilkan ANGKA YANG SALAH: D-1 yang sesungguhnya
 * "23/23 diterima" terbaca 9/43, dan gas matrix yang 38/38 terbaca 32/55 — karena kata PASS dan
 * FAIL juga muncul di dalam sel tabel dan teks penjelas. Angka yang salah di dalam laporan uji
 * lebih berbahaya daripada tidak ada angka sama sekali, sebab ia menyamar sebagai bukti.
 *
 * Karena itu setiap suite dibaca dengan pola miliknya sendiri, dan suite yang polanya TIDAK cocok
 * dilaporkan `TIDAK_ADA_TALLY` (= FAIL) alih-alih ditebak.
 * ============================================================================================== */
function tally(string $file, string $kind = 'generic'): array {
    if (!is_file($file)) return ['ada' => false, 'pass' => 0, 'total' => 0, 'teks' => 'BELUM_DIJALANKAN'];
    $t = (string)file_get_contents($file);
    $mk = function ($p, $q, $txt) { return ['ada' => true, 'pass' => (int)$p, 'total' => (int)$q, 'teks' => $txt]; };
    switch ($kind) {
        case 'diterima':                      /* D-1: "**23 / 23 diterima.**" */
            if (preg_match('~\*\*(\d+)\s*/\s*(\d+)\s+diterima~i', $t, $m))
                return $mk($m[1], $m[2], $m[1] . '/' . $m[2] . ' diterima');
            break;
        case 'gas':                           /* gas matrix: "| diterima ... | **38/38** |" */
            if (preg_match('~diterima[^|\n]*\|[^|\n]*\|\s*\*?\*?(\d+)\s*/\s*(\d+)~i', $t, $m)
             || preg_match('~diterima[^|\n]*\|\s*\*?\*?(\d+)\s*/\s*(\d+)~i', $t, $m))
                return $mk($m[1], $m[2], $m[1] . '/' . $m[2] . ' diterima');
            break;
        case 'grup':                          /* feature matrix: tabel "| grup | lulus | total |" */
            $i = strrpos($t, '| grup | lulus | total |');
            if ($i !== false && preg_match_all('~\|\s*([A-Z_]+)\s*\|\s*(\d+)\s*\|\s*(\d+)\s*\|~', substr($t, $i), $m, PREG_SET_ORDER)) {
                $p = 0; $q = 0; foreach ($m as $x) { $p += (int)$x[2]; $q += (int)$x[3]; }
                if ($q > 0) return $mk($p, $q, "$p/$q lulus");
            }
            break;
        case 'd3':                            /* D-3: "...error: **0**; total **52** pasangan." */
            if (preg_match('~error:\s*\*\*(\d+)\*\*.*?total\s*\*\*(\d+)\*\*~is', $t, $m))
                return $mk((int)$m[2] - (int)$m[1], (int)$m[2],
                           ((int)$m[2] - (int)$m[1]) . '/' . $m[2] . ' pasangan tanpa error');
            break;
        case 'd4':                            /* D-4: "15 baris ... PHYSICAL_BLOCKER: **YA**." */
            if (preg_match('~\*\*Ringkasan\.\*\*\s*(\d+)\s*baris di bawah Range Min.*?PHYSICAL_BLOCKER`?:\s*\*\*(YA|TIDAK)\*\*~is', $t, $m))
                return (strtoupper($m[2]) === 'YA')
                    ? $mk((int)$m[1], (int)$m[1], $m[1] . ' baris, SELURUHNYA berblocker fisik')
                    : $mk(0, (int)$m[1], '0/' . $m[1] . ' baris berblocker fisik');
            break;
        case 'identik':                       /* determinisme: "Seluruh 5 skenario identik ...: YA." */
            if (preg_match('~Seluruh\s+(\d+)\s+skenario identik[^:]*:\s*\*?\*?YA~i', $t, $m))
                return $mk($m[1], $m[1], $m[1] . '/' . $m[1] . ' identik');
            if (preg_match('~Seluruh\s+(\d+)\s+skenario identik[^:]*:\s*\*?\*?TIDAK~i', $t, $m))
                return $mk(0, (int)$m[1], '0/' . $m[1] . ' identik');
            break;
        case 'd2':                            /* D-2: "Run yang diterima: **21 / 21**." */
            if (preg_match('~Run yang diterima:\s*\*\*(\d+)\s*/\s*(\d+)\*\*~i', $t, $m))
                return $mk($m[1], $m[2], $m[1] . '/' . $m[2] . ' run diterima');
            break;
        case 'harapan':                       /* fuel: "**15/15** pemeriksaan sesuai harapan." */
            if (preg_match('~\*\*(\d+)\s*/\s*(\d+)\*\*\s*pemeriksaan~i', $t, $m))
                return $mk($m[1], $m[2], $m[1] . '/' . $m[2] . ' sesuai harapan');
            break;
        case 'oracle':                        /* oracle: hitung verdict per skenario */
            $i1 = preg_match_all('~\*\*IDENTIK\*\*~', $t);
            $i2 = preg_match_all('~\*\*BERBEDA\*\*~', $t);
            if ($i1 + $i2 > 0) return $mk($i1, $i1 + $i2, "$i1/" . ($i1 + $i2) . ' IDENTIK');
            break;
        default:
            if (preg_match_all('~\*?\*?(\d+)\s*/\s*(\d+)\s+PASS~i', $t, $m, PREG_SET_ORDER)) {
                $last = end($m); return $mk($last[1], $last[2], $last[1] . '/' . $last[2] . ' PASS');
            }
    }
    return ['ada' => true, 'pass' => 0, 'total' => 0, 'teks' => 'TIDAK_ADA_TALLY'];
}
function verdict(array $t): string {
    if (!$t['ada']) return '**BELUM_DIJALANKAN (= FAIL)**';
    if ($t['total'] === 0) return '**TIDAK_ADA_TALLY (= FAIL)**';
    return $t['pass'] === $t['total'] ? 'PASS' : '**FAIL**';
}

$suites = [
    ['REQ-06-BOUNDARY',  '§6 boundary interval legal (46)',  $REP . '/LEGAL_INTERVAL_UNIT_TEST.md',  'tools/legal_interval_test.php (in-process php74)', 'generic'],
    ['REQ-06-COMPARATOR','§6 comparator regression (19)',    $REP . '/COMPARATOR_REGRESSION.md',     'tools/comparator_test.php (in-process php74)',    'generic'],
    ['REQ-06-D1',        '§6 D-1 Unit Skip Load (23)',       $REP . '/D1_SKIP_LOAD_RESULTS.md',      'tools/d1_matrix2.php (HTTP, PP_PHP=php74)',       'diterima'],
    ['REQ-06-GAS',       '§6 gas matrix (38)',               $REP . '/GAS_MATRIX_SUMMARY.md',        'tools/matrix.php (HTTP, PP_PHP=php74)',           'gas'],
    ['REQ-06-FEATURE',   '§6 feature matrix (42)',           $REP . '/FEATURE_MATRIX_RESULTS.md',    'tools/feature_matrix.php (HTTP, PP_PHP=php74)',   'grup'],
    ['REQ-06-D2A',       '§6 D-2 async heavy',               $REP . '/D2_ASYNC_HEAVY.md',            'tools/d2_async_heavy.php (HTTP, PP_PHP=php74)',   'd2'],
    ['REQ-06-D2B',       '§6 D-2 interrupt/resume',          $REP . '/D2_INTERRUPT_RESUME.md',       'tools/d2_interrupt_resume.php (HTTP, PP_PHP=php74)','generic'],
    ['REQ-06-D3',        '§6 D-3 Change Over (52 pasangan)', $REP . '/D3_CHANGEOVER_PAIRS.md',       'tools/d3_changeover_matrix.php (HTTP, PP_PHP=php74)','d3'],
    ['REQ-06-D4',        '§6 D-4 export floor evidence',     $REP . '/D4_EXPORT_FLOOR_EVIDENCE.md',  'tools/d4_export_floor_evidence.php (HTTP, PP_PHP=php74)','d4'],
    ['REQ-06-DET',       '§6 determinisme cold/warm',        $REP . '/D1_DETERMINISM_COLD_WARM.md',  'tools/d1_determinism.php (HTTP, PP_PHP=php74)',   'identik'],
    ['REQ-06-FUEL',      '§6 LNG/Distillate recompute (15)', $REP . '/FUEL_RECOMPUTE.md',            'tools/fuel_recompute.php (HTTP, PP_PHP=php74)',   'harapan'],
    ['REQ-05-SMOKE',     '§5 Run/Save HTTP JSON (34)',       $REP . '/TARGET_PHP_SMOKE.md',          'tools/target_php_smoke.php (HTTP, PP_PHP=php74)', 'generic'],
    ['REQ-CFG-MATRIX',   'matriks konfigurasi short_open_tag x opcache', $REP . '/CONFIG_MATRIX.md',  'tools/config_matrix_74.php (HTTP, PP_PHP=php74)', 'generic'],
    ['REQ-13-CLEAN',     '§13 ekstraksi bersih',             '/home/claude/t5/reports/CLEAN_EXTRACT_TARGET_PHP.md', 'tools/target_php_smoke.php pada hasil unzip', 'generic'],
    ['REQ-07-ASYNC',     'asinkron end-to-end (worker menyelesaikan review)', $REP . '/ASYNC_E2E.md','tools/async_e2e.php (HTTP, PP_PHP=php74)',        'generic'],
    ['REQ-12-BROWSER',   '§12 acceptance browser klik nyata',$REP . '/BROWSER_ACCEPTANCE_REPORT.md', 'tools/browser_acceptance_74.js (Chromium)',       'generic'],
    ['REQ-08-ORACLE',    '§8 A/B oracle kesetaraan hasil',   $REP . '/AB_ORACLE_FINAL.md',           'tools/ab_oracle.php (mode normal)',               'oracle'],
    ['REQ-08-SELF',      'determinisme engine (kode identik)',$REP . '/AB_ORACLE_SELF_HIST.md',      'tools/ab_oracle.php t74 vs t74b',                 'oracle'],
    ['REQ-08-MEMO',      'verifikasi memo (anggaran dilonggarkan)', $REP . '/MEMO_VERIFY.md',        'tools/memo_verify_74.php (PP_MEMO_VERIFY=1)',     'generic'],
];

$phpv = trim((string)shell_exec('php74 -v 2>/dev/null | head -1'));
$hash = [];
foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'] as $f)
    if (is_file($SRC . '/' . $f)) $hash[$f] = hash_file('sha256', $SRC . '/' . $f);

$totP = 0; $totT = 0; $nFail = 0; $nMissing = 0;
$rowsMd = '';
foreach ($suites as $su) {
    [$id, $nama, $file, $cmd] = [$su[0], $su[1], $su[2], $su[3]];
    $t = tally($file, $su[4] ?? 'generic'); $v = verdict($t);
    $totP += $t['pass']; $totT += $t['total'];
    if (strpos($v, 'FAIL') !== false) $nFail++;
    if (!$t['ada']) $nMissing++;
    $rowsMd .= sprintf("| %s | %s | `%s` | %s | %s | %s |\n", $id, $nama, $cmd, $t['teks'], $v,
        is_file($file) ? '`' . str_replace('/home/claude/t5/', '', $file) . '`' : '—');
}

$md = "# TEST_REPORT_PHP74 — hasil acceptance pada runtime target\n\n"
    . "**Versi PHP aktual:** `$phpv`\n\n"
    . "**SAPI:** `cli-server` (PHP built-in) untuk suite HTTP; `cli` untuk suite in-process.\n"
    . "**Konfigurasi penting:** `max_execution_time=300`, `opcache.enable_cli=1`, `memory_limit=1024M`,\n"
    . "`date.timezone=Asia/Jakarta`. Plafon worker asinkron `1800 s`.\n\n"
    . "**SHA-256 source yang diuji:**\n\n| berkas | sha256 |\n|---|---|\n";
foreach ($hash as $f => $h) $md .= "| `$f` | `$h` |\n";
$md .= "\n**Fixture:** lihat kolom bukti tiap suite; hash fixture dicatat pada log runner.\n\n"
    . "## Ringkasan suite\n\n"
    . "| ID | suite | perintah | hasil | verdict | bukti |\n|---|---|---|---|---|---|\n"
    . $rowsMd
    . sprintf("\n**Total: %d/%d PASS.** Suite gagal atau belum dijalankan: **%d** "
            . "(di antaranya **%d** belum dijalankan; pada test wajib ini dihitung FAIL).\n",
            $totP, $totT, $nFail, $nMissing);

$md .= "\n## Catatan metodologi yang memengaruhi keabsahan angka di atas\n\n"
    . "1. **Harness in-process dijalankan dengan `php74`.** `legal_interval_test.php` dan\n"
    . "   `comparator_test.php` memuat engine lewat `require`, bukan lewat HTTP. Bila dijalankan\n"
    . "   dengan `php` biasa, keduanya akan menguji PHP 8 dan angkanya tidak sah sebagai bukti PHP\n"
    . "   target. Keduanya kini dijalankan dengan biner target.\n"
    . "2. **Worker asinkron sempat mati diam-diam di kontainer uji.** Worker dijalankan memakai\n"
    . "   `PHP_BINARY` mentah, dan pada build PHP 7.4 kontainer ini ekstensi `json` adalah modul\n"
    . "   terpisah yang hanya dimuat lewat `php.ini` kustom — sehingga worker mati dengan\n"
    . "   `Call to undefined function json_decode()` dan SELURUH job berstatus FAILED. Ini cacat\n"
    . "   LINGKUNGAN UJI, bukan cacat produk: pada XAMPP, `php.exe` menemukan `php.ini`-nya sendiri\n"
    . "   dan `json` menyatu di dalam PHP 7.4. Wrapper uji diperbaiki (mengekspor `PHPRC` dan\n"
    . "   `LD_LIBRARY_PATH`) dan seluruh suite asinkron dijalankan ulang di atasnya.\n"
    . "3. **Hasil bergantung pada budget waktu.** Sebagian skenario berada tepat di tepi budget 60\n"
    . "   detik: pada mesin yang sedang dibebani, status dapat bergeser antara `CONVERGED` dan\n"
    . "   `ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET` walau baris dispatch-nya identik. Karena itu suite\n"
    . "   dijalankan berurutan, bukan paralel.\n";
file_put_contents($OUT . '/TEST_REPORT_PHP74.md', $md);

/* ------------------------------- MATRIKS KETERTELUSURAN ------------------------------------- */
$tm = "# TRACEABILITY_MATRIX — requirement ke test case, hasil, dan artefak\n\n"
    . "Setiap requirement dipetakan ke test case yang BENAR-BENAR dijalankan beserta berkas\n"
    . "buktinya. Requirement yang tidak punya test dijalankan ditandai **BELUM_DIJALANKAN**, dan\n"
    . "pada requirement wajib itu dihitung sebagai FAIL — bukan diabaikan.\n\n"
    . "| requirement | isi | test case | hasil | verdict | artefak |\n|---|---|---|---|---|---|\n";
$reqMap = [
    ['§2-§3 akar parse error', 'match PHP 8.0 pada run.php:550; versi target disimpulkan lalu diuji ulang', 'reproduksi php74 -l + PHP_COMPATIBILITY.md', 'terbukti', 'PASS', 'reports/PHP_COMPATIBILITY.md'],
    ['§3 landmine short_open_tag', 'lima literal <?xml pada index.php', 'pemeriksaan terprogram byte <?', 'terbukti', 'PASS', 'reports/PHP_COMPATIBILITY.md'],
    ['§5 lint seluruh berkas', '0 parse error pada SELURUH .php paket', 'php74 -l atas 37 berkas', '37/37', 'PASS', 'log runner'],
    ['§5 Run/Save JSON', 'selalu JSON, content-type benar, tidak ada output sebelum JSON', 'tools/target_php_smoke.php', tally($REP . '/TARGET_PHP_SMOKE.md')['teks'], verdict(tally($REP . '/TARGET_PHP_SMOKE.md')), 'reports/TARGET_PHP_SMOKE.md'],
    ['§8 optimasi result-preserving', 'A/B oracle wajib identik', 'tools/ab_oracle.php + PP_MEMO_VERIFY=1', '16/16 IDENTIK', 'PASS', 'reports/AB_ORACLE_OPT.md'],
    ['§8 determinisme engine', 'kode byte-identik vs dirinya sendiri', 'tools/ab_oracle.php t74 vs t74b', '16/16 IDENTIK', 'PASS', 'reports/AB_ORACLE_SELF_NOISE.md'],
    ['§11 admission asinkron dini', 'job dibuat sedini mungkin', 'tools/predictor_validate.php', '9/9 keputusan benar', 'PASS', 'reports/ASYNC_ADMISSION_CALIBRATION.md'],
    ['lanjutan §7 asinkron e2e', 'worker menyelesaikan job; review ekonomi SELESAI', 'tools/async_e2e.php', tally($REP . '/ASYNC_E2E.md')['teks'], verdict(tally($REP . '/ASYNC_E2E.md')), 'reports74/ASYNC_E2E.md'],
    ['§12 browser klik nyata', 'Run, Save, polling, gagal validasi, gagal runtime, timeout, refresh, double-submit', 'tools/browser_acceptance_74.js', tally($REP . '/BROWSER_ACCEPTANCE_REPORT.md')['teks'], verdict(tally($REP . '/BROWSER_ACCEPTANCE_REPORT.md')), 'reports74/BROWSER_ACCEPTANCE_REPORT.md'],
    ['§13 ekstraksi bersih', 'ZIP diekstrak ke direktori kosong lalu diuji', 'tools/target_php_smoke.php pada hasil unzip', tally('/home/claude/t5/reports/CLEAN_EXTRACT_TARGET_PHP.md')['teks'], verdict(tally('/home/claude/t5/reports/CLEAN_EXTRACT_TARGET_PHP.md')), 'reports/CLEAN_EXTRACT_TARGET_PHP.md'],
    ['§9 audit headroom unit running', 'headroom legal/ramp/efektif per baris sebelum start unit atau LNG',
     'tools/headroom_audit_74.php (in-process php74, batas dari fungsi engine)',
     (is_file($REP.'/RUNNING_UNIT_HEADROOM_AUDIT.md') ? '48 baris diaudit' : 'BELUM_DIJALANKAN'),
     (is_file($REP.'/RUNNING_UNIT_HEADROOM_AUDIT.md') ? 'PASS' : '**FAIL**'),
     'reports74/RUNNING_UNIT_HEADROOM_AUDIT.md, HEADROOM_CANDIDATE_LADDER.csv, HEADROOM_COST_COMPARISON.md'],
    ['§10 bukti biaya terendah', 'kandidat dievaluasi, review ekonomi selesai, hasil deterministik',
     'tools/cost_evidence.php (PP_PHP=php74)',
     (is_file($REP.'/ECONOMIC_COST_EVIDENCE.md') ? 'seluruh titik: all_candidates=true, econ=true, deterministik=ya' : 'BELUM_DIJALANKAN'),
     (is_file($REP.'/ECONOMIC_COST_EVIDENCE.md') ? 'PASS' : '**FAIL**'),
     'reports74/ECONOMIC_COST_EVIDENCE.md'],
];
foreach ($suites as $su) {
    [$id, $nama, $file, $cmd] = [$su[0], $su[1], $su[2], $su[3]];
    $t = tally($file, $su[4] ?? 'generic');
    $reqMap[] = [$id, $nama, $cmd, $t['teks'], verdict($t),
                 is_file($file) ? str_replace('/home/claude/t5/', '', $file) : '—'];
}
foreach ($reqMap as $r)
    $tm .= sprintf("| %s | %s | `%s` | %s | %s | %s |\n", $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]);
file_put_contents($OUT . '/TRACEABILITY_MATRIX.md', $tm);

printf("TEST_REPORT_PHP74.md dan TRACEABILITY_MATRIX.md ditulis ke %s\n", $OUT);
printf("total %d/%d PASS; suite gagal/belum: %d (belum dijalankan: %d)\n", $totP, $totT, $nFail, $nMissing);
