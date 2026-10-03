<?php
/* BINER PHP TARGET DAPAT DIPILIH. */
if (!defined('PP_PHP_BIN')) define('PP_PHP_BIN', getenv('PP_PHP') ?: 'php');
/* ==============================================================================================
 *  MATRIKS KONFIGURASI — MENGUJI PADA SETELAN YANG BENAR-BENAR BERBEDA DI LAPANGAN.
 *
 *  MENGAPA INI ADA. Landmine `<?xml` hanya meledak ketika `short_open_tag=On`, dan pemasangan
 *  XAMPP sangat beragam dalam hal ini. Menguji satu setelan saja berarti membiarkan separuh dunia
 *  nyata tidak teruji — dan justru separuh yang berbahaya. Matriks ini menjalankan pemeriksaan
 *  yang sama pada kedua setelan, ditambah opcache hidup/mati.
 *
 *  php config_matrix_74.php <akar> <port_awal> <input.json> <out.md>
 * ============================================================================================ */
$root = $argv[1]; $port0 = (int)$argv[2]; $inFile = $argv[3]; $out = $argv[4];
$base = json_decode((string)file_get_contents($inFile), true);

$configs = [
    ['SOT_OFF_OPC_ON',  'short_open_tag=Off, opcache=On',  ['short_open_tag=0', 'opcache.enable_cli=1']],
    ['SOT_ON_OPC_ON',   'short_open_tag=On,  opcache=On',  ['short_open_tag=1', 'opcache.enable_cli=1']],
    ['SOT_ON_OPC_OFF',  'short_open_tag=On,  opcache=Off', ['short_open_tag=1', 'opcache.enable_cli=0']],
    ['SOT_OFF_OPC_OFF', 'short_open_tag=Off, opcache=Off', ['short_open_tag=0', 'opcache.enable_cli=0']],
];

$T = []; $pass = 0; $fail = 0;
function chk(string $cfg, string $id, string $what, $got, $want, string $note = '') {
    global $T, $pass, $fail;
    $ok = ($got === $want); $ok ? $pass++ : $fail++;
    $T[] = compact('cfg', 'id', 'what', 'got', 'want', 'ok', 'note');
    printf("%-4s %-16s %-8s %-52s got=%-22s%s\n", $ok ? 'PASS' : 'FAIL', $cfg, $id, $what,
        is_bool($got) ? var_export($got, true) : (string)$got, $note !== '' ? "  [$note]" : '');
}
function hit(string $url, ?array $post = null, int $to = 600): array {
    $ch = curl_init($url);
    $o = [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $to, CURLOPT_HEADER => 1];
    if ($post !== null) { $o[CURLOPT_POST] = 1; $o[CURLOPT_POSTFIELDS] = json_encode($post);
                          $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $raw = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdr = substr($raw, 0, $hs); $body = substr($raw, $hs);
    curl_close($ch);
    return ['code' => $code, 'hdr' => $hdr, 'body' => $body, 'json' => json_decode($body, true)];
}
/* Penanda diagnostik PHP yang BENAR-BENAR khas PHP, bukan teks aplikasi biasa. */
function phpDiag(string $b): bool {
    return (bool)(preg_match('~<b>\s*(Parse|Fatal|Warning|Notice|Deprecated)[^<]{0,40}error\s*</b>~i', $b)
        || preg_match('~<b>\s*(Warning|Notice|Deprecated)\s*</b>~i', $b)
        || preg_match('~(^|\n)\s*PHP\s+(Parse|Fatal|Warning|Notice|Deprecated)~i', $b));
}

$port = $port0;
foreach ($configs as [$cfg, $nama, $flags]) {
    /* Lint dengan setelan itu: landmine short_open_tag terdeteksi di sini, sebelum server menyala. */
    foreach (array_filter(['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'], function ($x) use ($root) { return is_file($root . '/' . $x); }) as $f) {
        $cmd = PP_PHP_BIN . ' ' . implode(' ', array_map(fn($x) => '-d ' . escapeshellarg($x), $flags))
             . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
        $o = (string)shell_exec($cmd);
        chk($cfg, 'LINT', "lint $f", strpos($o, 'No syntax errors') !== false, true, trim(explode("\n", $o)[0]));
    }

    $p = null;
    $args = PP_PHP_BIN . ' -d max_execution_time=300 '
          . implode(' ', array_map(fn($x) => '-d ' . escapeshellarg($x), $flags))
          . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root);
    $srv = proc_open($args, [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $p);
    usleep(1500000);
    $B = 'http://127.0.0.1:' . $port . '/';

    /* index.php: inilah tempat landmine `<?xml` akan meledak bila short_open_tag=On. */
    $r1 = hit($B . 'index.php');
    chk($cfg, 'INDEX', 'index.php HTTP 200', $r1['code'], 200);
    chk($cfg, 'INDEX', 'index.php tanpa diagnostik PHP', phpDiag($r1['body']), false);
    chk($cfg, 'INDEX', 'index.php tidak membocorkan blok PHP mentah',
        strpos($r1['body'], '<?php') !== false, false);
    chk($cfg, 'INDEX', 'tombol Run ada di markup', strpos($r1['body'], 'btn-run') !== false, true);

    /* Run lewat HTTP: harus JSON pada SEMUA konfigurasi. */
    $pl = json_decode(json_encode($base), true);
    $pl['data3']['modeling']['__no_async_handoff'] = 1;
    $pl['_request_id'] = 'CFG_' . $cfg; $pl['_state_revision'] = 1; $pl['_context'] = 'plan';
    $r2 = hit($B . 'run.php?mode=run', $pl, 900);
    chk($cfg, 'RUN', 'status HTTP Run sesuai kontrak', in_array($r2['code'], [200, 422], true), true, 'HTTP ' . $r2['code']);
    chk($cfg, 'RUN', 'Content-Type application/json',
        (bool)preg_match('~content-type:\s*application/json~i', $r2['hdr']), true);
    chk($cfg, 'RUN', 'byte pertama adalah `{` (tidak ada keluaran sebelum JSON)',
        substr(ltrim($r2['body']), 0, 1), '{');
    chk($cfg, 'RUN', 'body dapat di-decode sebagai JSON', is_array($r2['json']), true);
    chk($cfg, 'RUN', 'body tanpa diagnostik PHP', phpDiag($r2['body']), false);
    chk($cfg, 'RUN', 'hasil berisi 48 baris', count((array)($r2['json']['data'] ?? [])), 48);

    proc_terminate($srv); @proc_close($srv);
    $port++;
}

$fh = fopen($out, 'w');
fwrite($fh, "# Matriks konfigurasi pada PHP target\n\n"
    . "Biner: `" . PP_PHP_BIN . "`. Empat kombinasi `short_open_tag` x `opcache` diuji dengan\n"
    . "pemeriksaan yang sama. `short_open_tag=On` adalah konfigurasi tempat landmine `<?xml`\n"
    . "akan meledak bila belum ditangani.\n\n"
    . "| konfigurasi | id | pemeriksaan | hasil | verdict |\n|---|---|---|---|---|\n");
foreach ($T as $t)
    fwrite($fh, sprintf("| %s | %s | %s | `%s` | %s |\n", $t['cfg'], $t['id'], $t['what'],
        is_bool($t['got']) ? var_export($t['got'], true) : (string)$t['got'], $t['ok'] ? 'PASS' : '**FAIL**'));
fwrite($fh, sprintf("\n**%d/%d PASS** (%d FAIL, 0 SKIPPED)\n", $pass, $pass + $fail, $fail));
fclose($fh);
printf("\n%d/%d PASS (%d FAIL)\n", $pass, $pass + $fail, $fail);
exit($fail === 0 ? 0 : 1);
