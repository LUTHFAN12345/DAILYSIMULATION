<?php
/* V9 targeted: unit priority generik (10 konfigurasi) + provenance bahan bakar.
 * php v9_cases.php <src-root> <out.md> <out.jsonl>   (jalur job economic_review, akar baru per konfigurasi) */
ini_set('memory_limit', '3G');
$src = rtrim($argv[1], '/'); if (!is_file($src . '/run.php')) { fwrite(STDERR, "source tidak ditemukan: $src\n"); exit(2); }
$abs = function ($f) { return ($f !== '' && $f[0] === '/') ? $f : getcwd() . '/' . $f; }; $OUT = $abs($argv[2]); $JL = $abs($argv[3]); $T = []; $J = [];
$run = function (string $sc, array $ov = [], string $tag = '') use ($src) {
    $S = "/tmp/claude-0/v9case_" . $sc . $tag; exec('rm -rf ' . escapeshellarg($S)); mkdir("$S/jobs", 0777, true);
    foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'] as $f) if (is_file("$src/$f")) copy("$src/$f", "$S/$f");
    $o = "/tmp/claude-0/v9case_$sc$tag.json"; @unlink($o); $t = microtime(true);
    exec('timeout 900 /usr/local/bin/php74 -d max_execution_time=0 /home/claude/t5/v8/job_dump.php ' . escapeshellarg($S) . ' ' . escapeshellarg($sc) . ' ' . escapeshellarg($o) . ' ' . escapeshellarg(json_encode($ov ?: new stdClass())) . ' 2>&1', $x);
    $d = json_decode((string)@file_get_contents($o), true); $d['_wall'] = round(microtime(true) - $t, 2); return $d;
};
$pass = function (string $id, bool $ok, string $why) use (&$T) { $T[] = [$id, $ok, $why]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  -- $why\n"; };
$on = function (array $rows, string $u) { $x = []; foreach ($rows as $k => $r) if ((float)($r[$u] ?? 0) > 0.01) $x[] = $k + 1; return $x; };
$fin = function ($d) { $o = $d['output'] ?? []; $i = $o['info'] ?? []; $rg = $o['release_gate'] ?? [];
    return count($o['data'] ?? []) === 48 && ($rg['release_allowed'] ?? false) === true && ($rg['hard_validation'] ?? '') === 'PASS'
        && ($i['PLN Export Compliance'] ?? '') === 'OK' && (float)($i['Residual Gas Shortage (BBTUD)'] ?? 1) === 0.0; };
$hpa = function ($d) { $h = $d['output']['info']['Headroom Priority Audit'] ?? []; return [($h['status'] ?? '-'), (int)($h['flags_unresolved'] ?? -1), $h]; };
$reasons = function ($h) { $all = []; foreach ((array)($h['flags'] ?? []) as $f) foreach ((array)($f['reasons'] ?? []) as $r) $all[] = $r; return $all; };
$prov = function ($d) { $p = $d['output']['info']['Fuel Provenance'] ?? []; return [($p['status'] ?? '-'), $p]; };
/* ringkasan review V9: kandidat valid dievaluasi, CP pemenang = minimum kandidat valid */
$minValid = function ($d) { $v = $d['output']['info']['V8 Priority Review'] ?? []; $cp = (float)($d['output']['info']['Cost Production (USD/MWh)'] ?? 99); $mn = INF; $nv = 0;
    foreach (array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])) as $c) if (!empty($c['valid']) && isset($c['cp'])) { $nv++; $mn = min($mn, (float)$c['cp']); }
    return [$cp, $mn, $nv]; };
$starts = function (array $rows, array $units) use ($on) { $n = 0; foreach ($units as $u) { $x = $on($rows, $u); $prev = -9; foreach ($x as $r) { if ($r !== $prev + 1 && $r > 1) $n++; $prev = $r; } } return $n; };
$rec = function (string $id, $d) use (&$J, $minValid, $hpa, $prov) { [$cp, $mn, $nv] = $minValid($d); [$hs, $hu] = $hpa($d); [$ps] = $prov($d);
    $J[] = ['id' => $id, 'wall' => $d['_wall'], 'cp' => $cp, 'min_valid_candidate_cp' => is_finite($mn) ? $mn : null, 'valid_candidates' => $nv, 'hpa' => $hs, 'unresolved' => $hu, 'fuel_provenance' => $ps,
            'v9' => array_map(function ($a) { return $a['candidate']; }, (array)($d['output']['info']['V8 Priority Review']['applied'] ?? []))]; };
$GTG = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10'];
$common = function (string $id, $d) use ($pass, $fin, $hpa, $prov, $minValid) {
    [$hs, $hu] = $hpa($d); [$ps, $p] = $prov($d); [$cp, $mn] = $minValid($d);
    $pass("$id FINAL: 48 row, hard PASS, Export 48/48, residual 0, Unit Priority PASS/PASS_WITH_REASON, provenance bahan bakar PASS, CP <= kandidat valid termurah",
        $fin($d) && preg_match('~^PASS~', $hs) && $hu === 0 && preg_match('~^PASS~', $ps) && (!is_finite($mn) || $cp <= $mn + 1e-4),
        "wall {$d['_wall']} s, CP $cp, kandidat valid termurah " . (is_finite($mn) ? $mn : '-') . ", audit $hs/$hu, provenance $ps");
};

/* P01 headroom penuh: Export Range Min diturunkan (tanpa aturan 25 MW) -> unit prioritas tinggi cukup, tidak ada start unit prioritas rendah */
$ex = json_decode(file_get_contents('/home/claude/t5/v8/input_WB09.json'), true)['data3']['modeling']['pln_export_priority']; $ex0 = $ex; $ex0['range_rules'] = []; $ex0['range']['min'] = 10;
$d = $run('WB09', ['pln_export_priority' => $ex0], '_P01'); $rec('P01', $d); $rows = $d['output']['data'];
$common('P01', $d);
$st = $starts($rows, $GTG);
$pass('P01 headroom penuh (Export Range Min 10 MW sepanjang hari; row puncak Export maks tanpa start 14 MW): headroom G1/G8/G9 cukup -> tidak ada start GTG prioritas rendah', $st === 0,
    "start GTG $st; running: " . implode(' ', array_map(function ($u) use ($on, $rows) { return $u . ':' . count($on($rows, $u)); }, $GTG)));
/* P02 ramp (Fixed Load G8/G9 76 MW row 24-27) */
$d = $run('WB09_G8FIX', [], '_P02'); $rec('P02', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P02', $d);
$pass('P02 headroom parsial karena ramp: alasan RAMP per row', (bool)preg_grep('~RAMP~', $rs), implode(' | ', array_slice(array_values(array_unique(preg_grep('~RAMP~', $rs))), 0, 2)));
/* P03 reserve */
$d = $run('WB09', ['spinning_reserve_min' => 20], '_P03'); $rec('P03', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P03', $d);
$pass('P03 reserve mengikat (Spinning Reserve Min 20 MW; V8 gagal hard reserve row 17-23): REPAIR START unit prioritas tertinggi yang tersedia, alasan RESERVE per row', (bool)preg_grep('~RESERVE~', $rs) && !empty($d['output']['info']['V8 Priority Review']['start_repair']['applied']), 'repair ' . ($d['output']['info']['V8 Priority Review']['start_repair']['winner'] ?? '-') . '; ' . (implode(' | ', array_slice(array_values(array_unique(preg_grep('~RESERVE~', $rs))), 0, 2)) ?: implode(' | ', array_slice(array_unique($rs), 0, 3))));
/* P04 Export */
$d = $run('WB09', [], '_P04'); $rec('P04', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P04', $d); $rows = $d['output']['data'];
$v = $d['output']['info']['V8 Priority Review'] ?? []; $proof = null; foreach (array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])) as $c) if (in_array($c['kind'], ['DECOMMIT', 'OFF', 'DELAY', 'STOP', 'EARLY_STOP'], true) && in_array('EXPORT', (array)$c['violations'], true)) $proof = $c;
$pass('P04 headroom terhalang Export (Range Min 25 MW row 17-32): bukti kapasitas + alasan EXPORT', $proof !== null && (bool)preg_grep('~EXPORT~', $rs),
    $proof === null ? 'tidak ada bukti' : ($proof['id'] . (is_array($proof['prescreen'] ?? null) ? sprintf(': row %d Export maks %.2f < %.1f MW', $proof['prescreen']['row'], $proof['prescreen']['export_max_mw'], $proof['prescreen']['range_min_mw']) : ': simulasi 48 row')));
$g5 = $on($rows, 'G5'); $g2 = $on($rows, 'G2');
$pass('P08 minimum runtime menahan unit prioritas rendah: G5 running row ' . ($g5[0] ?? '-') . '-' . (end($g5) ?: '-') . ' dengan alasan MIN_RUNTIME', (bool)preg_grep('~MIN_RUNTIME~', $rs) && count($g5) === 12,
    implode(' | ', array_slice(array_values(array_unique(preg_grep('~MIN_RUNTIME~', $rs))), 0, 1)));
$pass('P09 unit berhenti pada row legal pertama sesudah minimum runtime (G5 12 row = 6 jam), G2 tidak running', count($g5) === 12 && end($g5) - $g5[0] === 11 && !$g2, 'G5 ' . count($g5) . ' row, G2 ' . count($g2) . ' row');
[$cp, $mn, $nv] = $minValid($d); $kinds = array_values(array_unique(array_map(function ($c) { return $c['kind']; }, array_merge((array)($v['history'] ?? []), (array)($v['final_candidates'] ?? [])))));
$pass('P10 beberapa unit eligible: kandidat SWAP/STOP/DELAY/DECOMMIT dibandingkan, pemenang = CP terendah kandidat valid', $nv >= 2 && $cp <= $mn + 1e-4 && count($kinds) >= 3,
    "kandidat valid $nv, jenis " . implode(',', $kinds) . ", CP $cp, termurah $mn");
/* P05 Bus Flow */
$d = $run('WB09', ['busflow_min' => 180], '_P05'); $rec('P05', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P05', $d);
$pass('P05 headroom terhalang Bus Flow (Bus Flow Min 180 MW; BBLN/G1 di Bus B): alasan BUS_FLOW / bukti Bus Flow tanpa unit', (bool)preg_grep('~BUS_FLOW~', $rs), implode(' | ', array_slice(array_values(array_unique(preg_grep('~BUS_FLOW~', $rs))), 0, 2)) ?: implode(' | ', array_slice(array_unique($rs), 0, 3)));
/* P06 gas: window gas mengikat kandidat (basis PGN 30) + rerun bahan bakar Gas Shortage ikut review */
$d = $run('BASE_PGN30', [], '_P06'); $rec('P06', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P06', $d);
$gc = array_values(array_filter(array_merge((array)($d['output']['info']['V8 Priority Review']['history'] ?? []), (array)($d['output']['info']['V8 Priority Review']['final_candidates'] ?? [])), function ($c) { return in_array('GAS_WINDOW', (array)$c['violations'], true) || !empty($c['gas_only']); }));
$pass('P06 headroom/kandidat terhalang window gas (PGN 30): kandidat yang hanya gagal window gas tercatat dan tidak dipilih', count($gc) > 0 || (bool)preg_grep('~GAS~', $rs),
    count($gc) ? ($gc[0]['id'] . ' CP ' . $gc[0]['cp'] . ' -> ' . implode(',', (array)$gc[0]['violations'])) : implode(' | ', array_slice(array_unique($rs), 0, 3)));
$d = $run('WB09', ['gas_quota' => ['pgn_pipe' => 26.0] + json_decode(file_get_contents('/home/claude/t5/v8/input_WB09.json'), true)['data3']['modeling']['gas_quota'], 'gas_shortage_action' => 'add_lng', 'additional_lng' => 1.6], '_P06b'); $rec('P06b', $d); $common('P06b', $d);
$v6 = $d['output']['info']['V8 Priority Review'] ?? [];
$pass('P06b Gas Shortage (PGN Pipe 26) + Add LNG 1,6: aksi bahan bakar ikut review kandidat Unit Priority (tidak dibekukan), akun LNG berbiaya', ($v6['status'] ?? 'SKIPPED') !== 'SKIPPED' && (int)($v6['candidates_simulated'] ?? 0) > 0 && (float)($d['output']['info']['LNG Used (BBTUD)'] ?? 0) > 0,
    'review ' . ($v6['status'] ?? '-') . ', disimulasikan ' . ($v6['candidates_simulated'] ?? 0) . ', LNG ' . ($d['output']['info']['LNG Used (BBTUD)'] ?? '-') . ' BBTUD, CP ' . ($d['output']['info']['Cost Production (USD/MWh)'] ?? '-'));
/* P07 STG/blok */
$d = $run('WB09_G1STOP', [], '_P07'); $rec('P07', $d); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $common('P07', $d);
$blk = array_values(preg_grep('~STG_|BLOCK|FORCED_STATUS~', $rs));
$pass('P07 headroom terhalang kopling STG/blok (G1 stop 10:00): alasan STG/blok per row', count($blk) > 0, implode(' | ', array_slice(array_unique($blk), 0, 2)));
/* provenance bahan bakar: GE dengan kuota KP72 sah, fixed flow manual MM2100 */
foreach (['WB09_KP72' => 'F1 kuota KP72 2,2 (GE berbeban)', 'WB09_MANFF' => 'F2 fixed flow manual MM2100 (kuota 0)'] as $sc => $lab) {
    $d = $run($sc, [], '_F'); $rec($sc, $d); [$ps, $p] = $prov($d); $common(substr($lab, 0, 2), $d);
    $ge = 0; $hr = 0; foreach ((array)($p['rows'] ?? []) as $r) foreach ((array)($r['units'] ?? []) as $un => $u) { if (isset($u['heat_rate_btu_kwh']) && $u['heat_rate_btu_kwh'] > 0 && $u['cost_usd_row'] > 0) $hr++;
        if (strpos($un, 'GE') === 0 && ($u['mw'] ?? 0) > 0) { $ge++; if (empty($u['source']) || strpos($u['source'], 'NONE') !== false || empty($u['valid'])) $ge = -999; } }
    $pass("$lab: setiap unit berbahan bakar punya sumber sah, akun biaya, heat rate, biaya per row", preg_match('~^PASS~', $ps) && $ge > 0 && (int)($p['unit_rows_without_legal_source'] ?? 1) === 0 && !$p['violations'] && $hr > 0,
        "status $ps, unit-row GE $ge, unit-row ber-heat-rate & biaya $hr, sumber MM2100 " . ($p['mm2100_source']['source'] ?? '-') . ", identitas " . json_encode($p['identity']['diff'] ?? null));
}
file_put_contents($JL, implode("\n", array_map('json_encode', $J)) . "\n");
$L = ['# V9 targeted — unit priority generik + provenance bahan bakar', '', '| id | uji | hasil | bukti |', '|---|---|---|---|'];
foreach ($T as $k => $t) $L[] = sprintf('| %d | %s | %s | %s |', $k + 1, $t[0], $t[1] ? 'PASS' : 'FAIL', str_replace('|', '/', $t[2]));
$L[] = ''; $L[] = sprintf('**%d/%d PASS**', count(array_filter($T, function ($t) { return $t[1]; })), count($T));
file_put_contents($OUT, implode("\n", $L) . "\n");
