<?php
/* V11 salinan v10_cases.php — perubahan yang disengaja (manifest §8): invarian CP V03/V06 memakai band CP 0,2 %.
 * V10 targeted: rute cepat kanonik (pustaka jangkar + screening dua tingkat + sertifikat), screening exact (GCR dicakup
 * keluarga, bukti kapasitas Export), invarian CP, determinisme pustaka dingin/hangat, fallback.
 * php v10_cases.php <src-root> <out.md> <out.jsonl>   (jalur job economic_review, akar baru per kasus) */
ini_set('memory_limit', '3G');
$src = rtrim($argv[1], '/'); if (!is_file($src . '/run.php')) { fwrite(STDERR, "source tidak ditemukan: $src\n"); exit(2); }
$abs = function ($f) { return ($f !== '' && $f[0] === '/') ? $f : getcwd() . '/' . $f; }; $OUT = $abs($argv[2]); $JL = $abs($argv[3]); $T = []; $J = [];
$SNAP = '/home/claude/t5/v10/snap_act';
/* akar baru; $snap: null = jobs kosong, 'snap' = snapshot V10 lengkap, 'nolib' = snapshot tanpa pustaka dispatch */
$prep = function (string $tag, ?string $snap) use ($src, $SNAP): string {
    $S = "/tmp/claude-0/v10case_" . $tag; exec('rm -rf ' . escapeshellarg($S)); mkdir("$S/jobs", 0777, true);
    foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php'] as $f) copy("$src/$f", "$S/$f");
    if ($snap !== null) {
        exec('SRC=' . escapeshellarg($S) . ' SNAP=' . escapeshellarg($SNAP) . ' bash /home/claude/t5/v3/prep_ui.sh ' . escapeshellarg($S) . ' >/dev/null 2>&1');
        if ($snap === 'nolib') foreach (glob("$S/jobs/_final/*.v10lib.json") as $f) @unlink($f);
    }
    return $S;
};
$run = function (string $S, string $sc, array $ov = []) {
    $o = $S . '_' . $sc . '.json'; @unlink($o); $t = microtime(true);
    exec('timeout 1500 /usr/local/bin/php74 -d max_execution_time=0 /home/claude/t5/v8/job_dump.php ' . escapeshellarg($S) . ' ' . escapeshellarg($sc) . ' ' . escapeshellarg($o) . ' ' . escapeshellarg(json_encode((object)$ov)) . ' 2>/dev/null');
    $d = json_decode((string)@file_get_contents($o), true); if (!is_array($d)) $d = ['output' => []]; $d['_wall'] = round(microtime(true) - $t, 2); return $d;
};
$pass = function (string $id, bool $ok, string $why) use (&$T) { $T[] = [$id, $ok, $why]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  -- $why\n"; };
$fin = function ($d) { $o = $d['output'] ?? []; $i = $o['info'] ?? []; $rg = $o['release_gate'] ?? [];
    return count($o['data'] ?? []) === 48 && ($rg['release_allowed'] ?? false) === true && ($rg['hard_validation'] ?? '') === 'PASS'
        && ($i['PLN Export Compliance'] ?? '') === 'OK' && (float)($i['Residual Gas Shortage (BBTUD)'] ?? 1) === 0.0; };
$md5 = function ($d) { return md5(json_encode(array_map(function ($r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','GE1','GE2','GE3','GE4','BB1','BB2'] as $u) $x[] = round((float)($r[$u] ?? 0), 3); return $x; }, (array)($d['output']['data'] ?? [])))); };
$cp = function ($d) { return (float)($d['output']['info']['Cost Production (USD/MWh)'] ?? 99); };
/* CP pemenang <= setiap kandidat valid yang dievaluasi (ruang kandidat rute cepat + review) */
$minValid = function ($d) { $i = $d['output']['info'] ?? []; $mn = INF; $n = 0;
    foreach ((array)($i['Global Commitment Review']['ladder'] ?? []) as $x) if (!empty($x['valid']) && isset($x['key']['cp'])) { $n++; $mn = min($mn, (float)$x['key']['cp']); }
    $v = $i['V8 Priority Review'] ?? [];
    foreach (array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])) as $c) if (!empty($c['valid']) && isset($c['cp'])) { $n++; $mn = min($mn, (float)$c['cp']); }
    return [$mn, $n]; };
$rec = function (string $id, $d, array $x = []) use (&$J, $cp, $minValid, $md5) { [$mn, $n] = $minValid($d); $i = $d['output']['info'] ?? [];
    $J[] = ['id' => $id, 'wall' => $d['_wall'] ?? null, 'mode' => $d['mode'] ?? null, 'cp' => $cp($d), 'min_valid_candidate_cp' => is_finite($mn) ? $mn : null, 'valid_candidates' => $n, 'md5' => $md5($d),
            'screening' => $i['V10 Screening Summary'] ?? null, 'winner' => $i['Incremental Recompute']['winner'] ?? ($i['Exact Candidate Space']['winner_node'] ?? null)] + $x; };

/* ---- V01..V03: rute cepat slot (snapshot V10), sertifikat, invarian CP ---- */
foreach (['ACT_PGN_UP', 'KP72_DOWN', 'ACT_PGN_2SLOT_C', 'ACT_FFM_DOWN'] as $k => $sc) {
    $S = $prep('fast_' . $sc, 'snap'); $d = $run($S, $sc); $rec('V01_' . $sc, $d); $i = $d['output']['info'] ?? [];
    $c = $i['V10 Candidate Screening'] ?? []; [$mn, $n] = $minValid($d);
    $pass("V01 $sc FINAL rute cepat: 48 row, hard PASS, Export 48/48, residual 0, rilis", $fin($d) && ($d['mode'] ?? '') === 'INCREMENTAL' && strpos((string)($i['Incremental Recompute']['winner'] ?? ''), 'v10_') === 0,
        sprintf('mode %s, pemenang %s, CP %.4f', $d['mode'] ?? '-', $i['Incremental Recompute']['winner'] ?? '-', $cp($d)));
    $okC = !empty($c['universe_signature']) && isset($c['candidates_generated'], $c['candidates_pruned'], $c['pruned_by_reason'], $c['incumbent_canonical_key'], $c['fingerprints']['engine'], $c['fingerprints']['constraints'], $c['fingerprints']['fuel'], $c['fingerprints']['priority']) && is_array($c['dirty_rows'] ?? null);
    $pass("V02 $sc sertifikat optimalitas cepat lengkap (universe, kunci incumbent, alasan pruning, baris kotor, sidik jari engine/constraint/fuel/priority)", $okC,
        sprintf('universe %s, dibangkitkan %s, dipangkas %s %s, tier2a %s, tier2b %s', $c['universe_signature'] ?? '-', $c['candidates_generated'] ?? '-', $c['candidates_pruned'] ?? '-', json_encode($c['pruned_by_reason'] ?? []), $c['candidates_evaluated_tier2a'] ?? '-', $c['candidates_full_run_tier2b'] ?? '-'));
    $pass("V03 $sc invarian V11: FINAL_CP <= CP kandidat valid termurah x 1,002 (band CP 0,2 %)", $n > 0 && $cp($d) <= $mn * 1.002 + 1e-6, sprintf('FINAL %.4f, kandidat valid termurah %.4f (%d kandidat valid)', $cp($d), $mn, $n));
}
/* ---- V04: determinisme pustaka (dibangun ulang vs dipakai ulang) ---- */
$A = $prep('det_lib', 'snap'); $dA = $run($A, 'ACT_FFJ_UP'); $B = $prep('det_nolib', 'nolib'); $dB = $run($B, 'ACT_FFJ_UP');
$rec('V04_lib_reused', $dA); $rec('V04_lib_rebuilt', $dB);
$pass('V04 pustaka dispatch jangkar dibangun ulang (akar tanpa pustaka) vs dipakai ulang: dispatch 48 row & CP identik',
    $md5($dA) === $md5($dB) && abs($cp($dA) - $cp($dB)) < 1e-9 && !empty($dB['output']['info']['V10 Candidate Screening']) && empty($dB['output']['info']['V10 Candidate Screening']['library_reused']),
    sprintf('md5 %s / %s, CP %.4f / %.4f, pustaka dibangun %.1f s', substr($md5($dA), 0, 10), substr($md5($dB), 0, 10), $cp($dA), $cp($dB), (float)($dB['output']['info']['V10 Candidate Screening']['library_built_s'] ?? 0)));
/* ---- V05: fallback (tanpa kandidat valid rute cepat) ---- */
$S = $prep('fb', 'snap'); $d = $run($S, 'ACT_FFJ_DOWN'); $rec('V05_ACT_FFJ_DOWN', $d); $i = $d['output']['info'] ?? [];
$fb = (string)($i['Incremental Recompute']['v10_fast']['reason'] ?? ($i['Incremental Recompute']['reason'] ?? ''));
$pass('V05 rute cepat tanpa kandidat valid (window total dan supplier tidak dapat dipenuhi bersama) -> jalur kanonik V9 / exact, bukan FINAL palsu',
    (($d['mode'] ?? '') === 'EXACT' || !empty($i['Incremental Recompute']['applied'])) && ($fin($d) || ($d['output']['preliminary'] ?? null) === true),
    sprintf('mode %s, alasan rute cepat %s, status %s', $d['mode'] ?? '-', $fb ?: '-', $d['output']['status'] ?? '-'));
/* ---- V06: screening exact — GCR dicakup keluarga, CP identik referensi V9 ---- */
$ref = ['Q_pgn_pipe_1' => 64.545, 'Q_lng_1' => 64.8608, 'WB09' => 64.3644];
foreach ($ref as $sc => $cpRef) {
    $S = $prep('ex_' . $sc, null); $d = $run($S, $sc); $rec('V06_' . $sc, $d, ['cp_v9' => $cpRef]); $i = $d['output']['info'] ?? []; $g = $i['Global Commitment Review'] ?? [];
    $scr = (array)($g['candidates_screened_v10'] ?? []); $el = array_map('strtoupper', (array)($i['Exact Candidate Space']['eligible_units'] ?? []));
    $cov = true; foreach ($scr as $u) if (!in_array($u, $el, true)) $cov = false;
    $pass("V06 $sc exact: kandidat comparator global yang dicakup node keluarga off:{u} tidak di-full-run; keluarga lengkap; CP V11 <= referensi V9 x 1,002 (band)",
        $fin($d) && $cov && (!$scr || ($g['v10_family_coverage'] ?? '') === 'LENGKAP') && ($i['Run Status']['economic_review_completed'] ?? false) === true && $cp($d) <= $cpRef * 1.002 + 5e-4,
        sprintf('dipangkas %s, cakupan %s, keluarga %s node, CP %.4f (V9 %.4f), %.1f s CLI', json_encode($scr), $g['v10_family_coverage'] ?? '-', $i['Exact Candidate Space']['family_nodes'] ?? '-', $cp($d), $cpRef, $d['_wall']));
}
/* ---- V07: bukti kapasitas Export sah (batas atas) ---- */
$S = $prep('cap', 'snap');
$code = <<<'PHPC'
define('PP_LIB_ONLY', true); chdir($argv[1]); require $argv[1] . '/run.php'; require '/home/claude/t5/v3/scen.php';
$a = pp_normalize_copy(v3_input('BASE_PGN30')); foreach (array_keys($a['data3']['modeling']) as $k) if (strpos($k, '__') === 0) unset($a['data3']['modeling'][$k]);
$F = pp_v9_final_load($a); $rows = $F['data'];
$all = []; foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $u) $all[] = ['unit' => $u, 'start' => 1, 'stop' => 48];
$pAll = pp_v10_export_capacity_proof($a, $rows, $all); $pWin = pp_v10_export_capacity_proof($a, $rows, pp_v3_stops($rows));
$ok = true; foreach ($rows as $k => $r) if ((float)$r['Export_PLN'] < (float)$r['pln_lo'] - 1e-6) $ok = false;
echo json_encode(['all_off' => $pAll, 'winner' => $pWin, 'winner_valid_export' => $ok]);
PHPC;
file_put_contents("$S/_cap.php", "<?php\n" . $code); $r = json_decode((string)shell_exec('/usr/local/bin/php74 ' . escapeshellarg("$S/_cap.php") . ' ' . escapeshellarg($S) . ' 2>/dev/null'), true);
$pass('V07 bukti kapasitas Export: seluruh GTG dimatikan -> terbukti infeasible; commitment pemenang jangkar (Export valid 48 row) -> tidak dipangkas (batas atas sah)',
    is_array($r) && is_array($r['all_off'] ?? null) && array_key_exists('winner', $r) && $r['winner'] === null && !empty($r['winner_valid_export']),
    'semua off: ' . json_encode($r['all_off']['detail'] ?? null) . '; pemenang: ' . json_encode(is_array($r) && array_key_exists('winner', $r) ? $r['winner'] : 'tidak ada'));
/* ---- V08: FINAL rute cepat identik jalur langsung vs rantai (state sama, akar sama, urutan berbeda) ---- */
$C = $prep('chain', 'snap'); $d1 = $run($C, 'ACT_PGN_DOWN'); $d2 = $run($C, 'KP72_DOWN'); $d3 = $run($C, 'ACT_PGN_DOWN');
$D = $prep('direct_kp72', 'snap'); $d4 = $run($D, 'KP72_DOWN');
$rec('V08_chain_KP72_DOWN', $d2); $rec('V08_direct_KP72_DOWN', $d4);
$pass('V08 KP72_DOWN lewat rantai (sesudah ACT_PGN_DOWN) vs langsung (akar baru): dispatch & CP identik; state ulang = cache FINAL',
    $md5($d2) === $md5($d4) && abs($cp($d2) - $cp($d4)) < 1e-9 && $md5($d1) === $md5($d3),
    sprintf('rantai %s CP %.4f / langsung %s CP %.4f; ulang %.2f s', substr($md5($d2), 0, 10), $cp($d2), substr($md5($d4), 0, 10), $cp($d4), $d3['_wall']));

$np = count(array_filter($T, function ($t) { return $t[1]; }));
$md = "# V10 targeted (versi V11, band CP 0,2 %): rute cepat kanonik, sertifikat, screening dua tingkat\n\nPASS $np / " . count($T) . "\n\n| id | hasil | bukti |\n|---|---|---|\n";
foreach ($T as $t) $md .= '| ' . str_replace('|', '/', $t[0]) . ' | ' . ($t[1] ? 'PASS' : 'FAIL') . ' | ' . str_replace('|', '/', $t[2]) . " |\n";
file_put_contents($OUT, $md); file_put_contents($JL, implode("\n", array_map('json_encode', $J)) . "\n");
echo "SELESAI PASS $np / " . count($T) . "\n";
