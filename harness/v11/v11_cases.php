<?php
/* V11 targeted: reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3) + WB09, audit V11 (Candidate Counters, Candidate Comparison,
 * Consolidation Sweep, Low Load Fragmentation, CP Audit), band CP 0,2 % + tie-break Heat Rate, counter satu sumber (kunci
 * kanonik), LOW_LOAD_FRAGMENTATION terselesaikan, Unit Priority, provenance, determinisme, PP_V11=0 tetap berjalan.
 * php v11_cases.php <src-root> <out.md> <out.jsonl>   (jalur job economic_review, akar baru per kasus)
 * Nilai referensi (CP 63,4469 / HR 8109,13) hanya DICATAT sebagai pembanding; kelulusan memakai invarian, bukan angka keras. */
ini_set('memory_limit', '3G');
$src = rtrim($argv[1], '/'); if (!is_file($src . '/run.php')) { fwrite(STDERR, "source tidak ditemukan: $src\n"); exit(2); }
$abs = function ($f) { return ($f !== '' && $f[0] === '/') ? $f : getcwd() . '/' . $f; }; $OUT = $abs($argv[2]); $JL = $abs($argv[3]); $T = []; $J = [];
$run = function (string $sc, string $tag, array $env = []) use ($src) {
    $S = "/tmp/claude-0/v11case_" . $sc . $tag; exec('rm -rf ' . escapeshellarg($S)); mkdir("$S/jobs", 0777, true);
    foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php'] as $f) copy("$src/$f", "$S/$f");
    $o = "/tmp/claude-0/v11case_$sc$tag.json"; @unlink($o); $t = microtime(true); $e = '';
    foreach ($env as $k => $v) $e .= escapeshellarg($k) . '=' . escapeshellarg($v) . ' ';
    exec('env ' . $e . 'timeout 1500 /usr/local/bin/php74 -d max_execution_time=0 /home/claude/t5/v8/job_dump.php ' . escapeshellarg($S) . ' ' . escapeshellarg($sc) . ' ' . escapeshellarg($o) . ' 2>/dev/null');
    $d = json_decode((string)@file_get_contents($o), true); if (!is_array($d)) $d = ['output' => []]; $d['_wall'] = round(microtime(true) - $t, 2); return $d;
};
$pass = function (string $id, bool $ok, string $why) use (&$T) { $T[] = [$id, $ok, $why]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  -- $why\n"; };
$fin = function ($d) { $o = $d['output'] ?? []; $i = $o['info'] ?? []; $rg = $o['release_gate'] ?? [];
    return count($o['data'] ?? []) === 48 && ($rg['release_allowed'] ?? false) === true && ($rg['hard_validation'] ?? '') === 'PASS'
        && ($i['PLN Export Compliance'] ?? '') === 'OK' && (float)($i['Residual Gas Shortage (BBTUD)'] ?? 1) === 0.0; };
$md5 = function ($d) { return md5(json_encode(array_map(function ($r) { $x = []; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','GE1','GE2','GE3','GE4','BB1','BB2'] as $u) $x[] = round((float)($r[$u] ?? 0), 3); return $x; }, (array)($d['output']['data'] ?? [])))); };
$I = function ($d) { return (array)($d['output']['info'] ?? []); };
$cp = function ($d) use ($I) { return (float)($I($d)['Cost Production (USD/MWh)'] ?? 99); };
$hr = function ($d) use ($I) { return (float)($I($d)['JBBK MM Heat Rate (BTU/kWh)'] ?? 0); };
/* kandidat valid termurah dari seluruh bukti (GCR ladder, review V8, comparator V11) */
$minValid = function ($d) use ($I) { $i = $I($d); $mn = INF; $n = 0;
    foreach ((array)($i['Global Commitment Review']['ladder'] ?? []) as $x) if (!empty($x['valid']) && isset($x['key']['cp'])) { $n++; $mn = min($mn, (float)$x['key']['cp']); }
    $v = $i['V8 Priority Review'] ?? [];
    foreach (array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])) as $c) if (!empty($c['valid']) && isset($c['cp'])) { $n++; $mn = min($mn, (float)$c['cp']); }
    foreach ((array)($i['V11 Candidate Comparison']['table'] ?? []) as $c) if (isset($c['cp'])) { $n++; $mn = min($mn, (float)$c['cp']); }
    return [$mn, $n]; };
$rec = function (string $id, $d, array $x = []) use (&$J, $cp, $hr, $md5, $I) { $i = $I($d);
    $J[] = ['id' => $id, 'wall' => $d['_wall'] ?? null, 'mode' => $d['mode'] ?? null, 'cp' => $cp($d), 'heat_rate' => $hr($d), 'md5' => $md5($d),
            'counters' => $i['V11 Candidate Counters'] ?? null, 'band' => array_diff_key((array)($i['V11 Candidate Comparison'] ?? []), ['table' => 1, 'rule' => 1]),
            'sweep' => array_diff_key((array)($i['V11 Consolidation Sweep'] ?? []), ['candidates' => 1]), 'frag' => array_diff_key((array)($i['V11 Low Load Fragmentation Audit'] ?? []), ['findings_detail' => 1]),
            'cp_audit' => $i['V11 CP Audit']['status'] ?? null] + $x; };
$audits = ['V11 Candidate Counters', 'V11 Candidate Comparison', 'V11 Consolidation Sweep', 'V11 Low Load Fragmentation Audit', 'V11 CP Audit'];

$REF = ['WB09_3' => [63.4469, 8109.13], 'WB09' => [64.3644, null]];
$D = [];
foreach ($REF as $sc => [$cpRef, $hrRef]) {
    $d = $run($sc, '_a'); $D[$sc] = $d; $rec("R_$sc", $d, ['cp_ref' => $cpRef, 'hr_ref' => $hrRef]); $i = $I($d);
    $miss = array_values(array_filter($audits, function ($k) use ($i) { return !is_array($i[$k] ?? null); }));
    $pass("R01 $sc FINAL: 48 row, hard PASS, Export 48/48, residual 0, rilis; kelima audit V11 terpasang", $fin($d) && !$miss,
        sprintf('mode %s, CP %.4f (ref %.4f), HR %.2f%s, %.1f s CLI, audit hilang %s', $d['mode'] ?? '-', $cp($d), $cpRef, $hr($d), $hrRef ? " (ref $hrRef)" : '', $d['_wall'], json_encode($miss)));
    $fp = (string)($i['Fuel Provenance']['status'] ?? '-'); $mp = (string)($i['MM2100 Gas Provenance']['status'] ?? '-'); $ca = (array)($i['V11 CP Audit'] ?? []);
    $free = array_values(array_filter((array)($ca['fuel_accounts'] ?? []), function ($a) { return empty($a['priced']); }));
    $pass("R02 $sc CP audit: CP = Total Cost / Net, seluruh bahan bakar terpakai berharga (tidak ada fuel gratis), Heat Rate konsisten, provenance fuel & MM2100 PASS",
        ($ca['status'] ?? '') === 'PASS' && !$free && preg_match('~^PASS~', $fp) && preg_match('~^PASS~', $mp),
        sprintf('CP audit %s %s, akun %d (tanpa harga %d), fuel %s, MM2100 %s', $ca['status'] ?? '-', json_encode($ca['issues'] ?? []), count((array)($ca['fuel_accounts'] ?? [])), count($free), $fp, $mp));
    [$mn, $n] = $minValid($d); $b = (array)($i['V11 Candidate Comparison'] ?? []);
    $bandOk = isset($b['cp_min']) && abs((float)$b['cp_min'] - $mn) < 1e-4 && $cp($d) <= $mn * 1.002 + 1e-6 && abs((float)($b['winner_cp'] ?? -1) - $cp($d)) < 1e-4;
    $inBand = array_values(array_filter((array)($b['table'] ?? []), function ($x) { return !empty($x['in_band']); }));
    $hrMin = INF; foreach ($inBand as $x) $hrMin = min($hrMin, (float)$x['heat_rate']);
    $out = array_values(array_filter((array)($b['table'] ?? []), function ($x) { return empty($x['in_band']) && ($x['result'] ?? '') === 'MENANG'; }));
    $pass("R03 $sc comparator V11: CP_min = kandidat valid termurah; FINAL_CP <= CP_min x 1,002; pemenang = Heat Rate terendah di dalam band; kandidat di luar band tidak pernah menang",
        $bandOk && $inBand && abs($hr($d) - $hrMin) < 0.02 + 1e-9 && !$out,
        sprintf('CP_min %.4f (bukti %.4f, %d kandidat), band s.d. %.4f, FINAL %.4f, pemenang %s HR %.2f, HR terendah band %.2f, di band %d', $b['cp_min'] ?? -1, $mn, $n, $b['band_upper'] ?? -1, $cp($d), $b['winner'] ?? '-', $hr($d), $hrMin, count($inBand)));
    $c = (array)($i['V11 Candidate Counters'] ?? []);
    $cntOk = isset($c['candidates_checked'], $c['candidates_valid'], $c['candidates_full_run'], $c['candidates_screened_out']) && (int)$c['candidates_valid'] >= 1
        && (int)$c['candidates_valid'] <= (int)$c['candidates_full_run'] && (int)$c['candidates_checked'] === (int)$c['candidates_full_run'] + (int)$c['candidates_screened_out']
        && !empty($c['best_candidate_in_valid']) && !empty($c['invariants']['best_implies_valid_ge_1']) && !empty($c['invariants']['valid_le_checked']);
    $pass("R04 $sc counter satu sumber: checked = full-run unik + screened-out unik (kunci fisik kanonik); valid >= 1 bila CP tersedia; valid <= full-run <= checked; best termasuk valid",
        $cntOk, json_encode(array_diff_key($c, ['definition' => 1, 'key' => 1])));
    $f = (array)($i['V11 Low Load Fragmentation Audit'] ?? []); $gen = true;
    foreach ((array)($f['findings_detail'] ?? []) as $x) { $ok = false; foreach ((array)$x['reasons'] as $w) if (!preg_match('~^(MINIMUM_LOAD|INCUMBENT|PREVIOUS_FINAL)~i', (string)$w)) $ok = true; if (!$ok) $gen = false; }
    $pass("R05 $sc LOW_LOAD_FRAGMENTATION: setiap temuan terselesaikan (PASS) atau PASS_WITH_REASON berbasis bukti (bukan incumbent / FINAL sebelumnya / minimum load saja)",
        in_array((string)($f['status'] ?? ''), ['PASS', 'PASS_WITH_REASON'], true) && (int)($f['unresolved'] ?? 1) === 0 && $gen,
        sprintf('%s, row %s, temuan %s, tanpa alasan %s, unit_rows %s, kandidat konsolidasi %s', $f['status'] ?? '-', $f['rows_flagged'] ?? '-', $f['findings'] ?? '-', $f['unresolved'] ?? '-', json_encode($f['unit_rows'] ?? []), json_encode($f['consolidation_candidates'] ?? [])));
    $h = (array)($i['Headroom Priority Audit'] ?? []); $v = (array)($i['V8 Priority Review'] ?? []);
    $pass("R06 $sc Unit Priority: audit headroom seluruh row PASS/PASS_WITH_REASON tanpa flag tak terselesaikan; review prioritas selesai",
        preg_match('~^PASS~', (string)($h['status'] ?? '')) && (int)($h['flags_unresolved'] ?? 1) === 0 && (int)($h['rows_audited'] ?? 0) === 48 && !empty($v['status']),
        sprintf('HPA %s/%s row %s, review %s (%s -> %s)', $h['status'] ?? '-', $h['flags_unresolved'] ?? '-', $h['rows_audited'] ?? '-', $v['status'] ?? '-', $v['cost_production_before'] ?? '-', $v['cost_production_after'] ?? '-'));
}
/* R07 LOW_LOAD_FRAGMENTATION generik: tidak ada nama unit keras — unit yang ditandai berasal dari dispatch & prioritas input */
$src11 = (string)@file_get_contents("$src/run.php"); $a0 = strpos($src11, 'function pp_v11_fragmentation'); $a1 = strpos($src11, 'function pp_v11_frag_audit');
$body = $a0 !== false && $a1 !== false ? substr($src11, $a0, $a1 - $a0) : '';
$pass('R07 logika konsolidasi / fragmentation generik (tanpa hardcode G5/G1/unit tertentu)', $body !== '' && !preg_match("~['\"](g5|G5|g1|G1|g8|G8|g9|G9)['\"]~", $body), 'panjang badan ' . strlen($body) . ' byte');
/* R08 unit prioritas rendah: stop pada row legal pertama setelah minimum runtime (WB09_3: unit start otomatis) */
$d = $D['WB09_3']; $rows = (array)($d['output']['data'] ?? []); $i = $I($d);
$lim = []; $ok8 = true; $why8 = [];
foreach ((array)($i['V11 Low Load Fragmentation Audit']['findings_detail'] ?? []) as $x) foreach ((array)$x['reasons'] as $w) if (preg_match('~MIN_RUNTIME\(start row (\d+), (\d+) row, stop legal pertama row (\d+)\)~', (string)$w, $m)) $lim[$x['unit']] = [(int)$m[1], (int)$m[3]];
foreach ($lim as $U => [$a, $first]) { $end = $a; while ($end <= 48 && (float)($rows[$end - 1][$U] ?? 0) > 0.01) $end++;
    $why8[] = "$U start $a stop row $end (legal pertama $first)"; if ($end !== $first) $ok8 = false; }
$pass('R08 unit prioritas rendah yang di-start diuji stop dan berhenti pada row legal pertama setelah minimum runtime', $ok8, $why8 ? implode('; ', $why8) : 'tidak ada unit start dengan minimum runtime');
/* R09 determinisme: state sama, akar baru kedua -> dispatch, CP, HR, pemenang identik */
$d2 = $run('WB09_3', '_b'); $rec('R09_WB09_3_ulang', $d2);
$pass('R09 WB09_3 determinisme (akar baru kedua): dispatch 48 row, CP, Heat Rate, pemenang band identik',
    $md5($d2) === $md5($D['WB09_3']) && abs($cp($d2) - $cp($D['WB09_3'])) < 1e-9 && abs($hr($d2) - $hr($D['WB09_3'])) < 1e-9 && ($I($d2)['V11 Candidate Comparison']['winner'] ?? 1) === ($I($D['WB09_3'])['V11 Candidate Comparison']['winner'] ?? 2),
    sprintf('md5 %s / %s, CP %.4f / %.4f', substr($md5($d2), 0, 10), substr($md5($D['WB09_3']), 0, 10), $cp($d2), $cp($D['WB09_3'])));
/* R10 PP_V11=0 (sakelar investigasi) tetap menghasilkan FINAL valid tanpa audit V11 */
$d3 = $run('WB09_3', '_off', ['PP_V11' => '0']); $rec('R10_WB09_3_V11_OFF', $d3);
$pass('R10 PP_V11=0: engine tetap FINAL valid (audit V11 tidak dilampirkan), V11 aktif tidak lebih mahal di luar band', $fin($d3) && !isset($I($d3)['V11 Candidate Comparison']) && $cp($D['WB09_3']) <= $cp($d3) * 1.002 + 1e-6,
    sprintf('V11 off CP %.4f HR %.2f / V11 on CP %.4f HR %.2f', $cp($d3), $hr($d3), $cp($D['WB09_3']), $hr($D['WB09_3'])));

$np = count(array_filter($T, function ($t) { return $t[1]; }));
$md = "# V11 targeted: reproducer WB09_3, audit V11, band CP 0,2 % + Heat Rate, counter kanonik\n\nPASS $np / " . count($T) . "\n\n| id | hasil | bukti |\n|---|---|---|\n";
foreach ($T as $t) $md .= '| ' . str_replace('|', '/', $t[0]) . ' | ' . ($t[1] ? 'PASS' : 'FAIL') . ' | ' . str_replace('|', '/', $t[2]) . " |\n";
file_put_contents($OUT, $md); file_put_contents($JL, implode("\n", array_map('json_encode', $J)) . "\n");
echo "SELESAI PASS $np / " . count($T) . "\n";
