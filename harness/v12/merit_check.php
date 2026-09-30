<?php
/* V12 uji generik merit dispatch atas FINAL nyata (engine, backend HTTP) — tidak ada fixture/hasil sintetis.
 * php merit_check.php <dir-output-tersimpan> <merit_cases.map> <out.md> <out.jsonl> */
[$_, $DIR, $MAP, $OUT, $JL] = $argv;
$map = []; foreach (file($MAP, FILE_IGNORE_NEW_LINES) as $l) { if (trim($l) === '') continue; [$id, $sc] = explode("\t", $l, 2); $map[$sc] = $id; }
$outs = []; foreach (glob($DIR . '/*.json') as $f) { $j = json_decode(file_get_contents($f), true); if (is_array($j) && isset($map[$j['sc']])) $outs[$map[$j['sc']]] = $j['output']; }
ksort($outs); $T = []; $R = [];
$pass = function ($id, $ok, $why) use (&$T) { $T[] = [$id, (bool)$ok, $why]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  -- $why\n"; };
foreach ($map as $sc => $id) {
    $o = $outs[$id] ?? null;
    if (!is_array($o)) { $pass("$id.00 FINAL tersedia", false, 'tidak ada output'); continue; }
    $i = (array)($o['info'] ?? []); $rg = (array)($o['release_gate'] ?? []); $rows = array_values((array)($o['data'] ?? []));
    $A = (array)($i['V12 Dispatch Merit Audit'] ?? []); $L = (array)($i['V12 Low Load Fragmentation Outcome'] ?? []); $C = (array)($i['V12 CP Report'] ?? []); $Q = (array)($i['V12 Reuse Certificate'] ?? []);
    $inb = count(array_filter($rows, function ($r) { return !empty($r['in_band']); }));
    $pass("$id.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis", count($rows) === 48 && ($rg['hard_validation'] ?? '') === 'PASS' && $inb === 48 && ($rg['release_allowed'] ?? null) === true,
        sprintf('rows=%d hard=%s in_band=%d rilis=%s CP=%s', count($rows), $rg['hard_validation'] ?? '-', $inb, json_encode($rg['release_allowed'] ?? null), $i['Cost Production (USD/MWh)'] ?? '-'));
    /* legal headroom seluruh unit direview tiap row; headroom <= Max-MW dan <= allowance ramp */
    $bad = 0; $nrec = 0; $cls = [];
    foreach ((array)($A['rows'] ?? []) as $rw) foreach ((array)$rw['units'] as $u) { $nrec++; $cls[$u['class']] = true;
        if ($u['mw'] > 0.01 && ($u['legal_headroom_mw'] > max(0.0, $u['max'] - $u['mw']) + 1e-6 || ($u['ramp_allowance_mw'] !== null && $u['legal_headroom_mw'] > $u['ramp_allowance_mw'] + 1e-6))) $bad++; }
    $pass("$id.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp)", ($A['rows_audited'] ?? 0) === 48 && $nrec > 0 && $bad === 0 && isset($cls['GTG'], $cls['STG']),
        sprintf('row=%s catatan=%d kelas=%s pelanggaran=%d', $A['rows_audited'] ?? '-', $nrec, implode('/', array_keys($cls)), $bad));
    $c2 = (array)($A['c2_start_with_headroom'] ?? []); $c3 = (array)($A['c3_first_legal_stop'] ?? []);
    $pass("$id.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status)", ($c2['fail'] ?? 1) === 0,
        sprintf('temuan=%s tanpa bukti=%s %s', $c2['findings'] ?? '-', $c2['fail'] ?? '-', json_encode(array_map(function ($x) { return $x['unit'] . '@' . $x['row'] . ':' . substr(implode(' | ', $x['reasons']), 0, 90); }, (array)($c2['detail'] ?? [])))));
    $pass("$id.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana)", ($c3['fail'] ?? 1) === 0,
        sprintf('interval=%s belum diuji=%s %s', $c3['intervals'] ?? '-', $c3['fail'] ?? '-', json_encode(array_map(function ($x) { return $x['unit'] . ' ' . implode('-', $x['rows']) . ' stop@' . ($x['first_legal_stop_row'] ?? '-') . ' ' . $x['result'] . ' ' . substr((string)($x['outcome'] ?? $x['reason'] ?? ''), 0, 60); }, (array)($c3['detail'] ?? [])))));
    $pass("$id.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik)", in_array($L['status'] ?? '', ['PASS', 'PASS_WITH_OUTCOMES'], true),
        sprintf('%s %s', $L['status'] ?? '-', json_encode($L['counts'] ?? null)));
    $okC = ($C['status'] ?? '') !== 'OK' || ((float)$C['absolute_cp_min'] <= (float)$C['winner_cp'] + 1e-9 && (float)$C['winner_cp'] <= (float)$C['absolute_cp_min'] * 1.002 + 1e-6 && $C['tie_break_reason'] !== null && $C['min_heat_rate_in_band'] !== null && (float)$C['winner_heat_rate'] <= (float)$C['min_heat_rate_in_band'] + 0.01);
    $pass("$id.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break", $okC,
        ($C['status'] ?? '-') === 'OK' ? sprintf('CPmin %s (%s) pemenang %s d=%s%% HR %s / min band %s; %s', $C['absolute_cp_min'], $C['absolute_cp_min_candidate'], $C['winner_cp'], $C['delta_winner_vs_min_pct'], $C['winner_heat_rate'], $C['min_heat_rate_in_band'], $C['tie_break_reason']) : (string)($C['status'] ?? '-'));
    $pass("$id.07 sertifikat reuse kanonik lengkap", !empty($Q['numerical_state_signature']) && !empty($Q['engine_fingerprint']) && !empty($Q['constraint_fingerprint']) && !empty($Q['priority_fingerprint']) && !empty($Q['fuel_fingerprint'])
        && !empty($Q['provenance_fingerprint']) && !empty($Q['dirty_row_dependency_signature']) && array_key_exists('candidate_universe_signature', $Q) && !empty($Q['physical_dispatch_signature']) && !empty($Q['proof_version']),
        sprintf('rute %s dirty %s universe %s dispatch %s', $Q['route'] ?? '-', $Q['dirty_row_dependency_signature'] ?? '-', $Q['candidate_universe_signature'] ?? '-', $Q['physical_dispatch_signature'] ?? '-'));
    /* khusus kasus */
    $rows12 = (array)($A['rows'] ?? []);
    if ($id === 'M05_RESERVE') { $mn = min(array_map(function ($r) { return $r['reserve_allowance_mw']; }, $rows12)); $pass("$id.10 reserve blocker: spinning reserve >= minimum pada 48 row", $mn >= -1e-6, 'allowance reserve minimum ' . $mn . ' MW'); }
    if ($id === 'M06_BUSFLOW') { $mn = min(array_map(function ($r) { return $r['busflow_allowance_mw']; }, $rows12)); $pass("$id.10 Bus Flow blocker: Bus Flow >= minimum pada 48 row", $mn >= -1e-6, 'allowance Bus Flow minimum ' . $mn . ' MW'); }
    if ($id === 'M07_EXPORT_RANGE' || $id === 'M08_DEV_DISPATCH') { $bad = 0; foreach ($rows as $r) if ((float)$r['Export_PLN'] < (float)$r['pln_lo'] - 1e-6 || (float)$r['Export_PLN'] > (float)$r['pln_hi'] + 1e-6) $bad++;
        /* Dev Dispatch rows 23-26: band = [dispatch - 63, dispatch + 60] = [27, 150] (dispatch PLN 90 MW) — lebih sempit dari Range PLN */
        $lo23 = (float)($rows[22]['pln_lo'] ?? 0); $ex23 = (float)($rows[22]['Export_PLN'] ?? 0);
        $pass("$id.10 Export tetap di dalam rentang / Dev Dispatch user pada setiap row", $bad === 0 && ($id !== 'M08_DEV_DISPATCH' || ($lo23 >= 27 - 1e-6 && $ex23 >= 27 - 1e-6)), sprintf('row di luar band=%d; row 23 band bawah %.2f, Export %.2f', $bad, $lo23, $ex23)); }
    if ($id === 'M09_REQUIRED_G5') { $first = null; foreach ($rows as $k => $r) if ((float)($r['G5'] ?? 0) > 0.01) { $first = $k + 1; break; } $on = count(array_filter($rows, function ($r) { return (float)($r['G5'] ?? 0) > 0.01; })); $st = null;
        foreach ($rows12 as $rw) foreach ($rw['units'] as $u) if ($u['unit'] === 'G5') { $st = $u['status']; break 2; }
        $ivs = array_filter((array)($A['c3_first_legal_stop']['detail'] ?? []), function ($x) { return $x['unit'] === 'G5'; });
        $pass("$id.10 forced/required: G5 Start at 10:00 (Required) berjalan mulai row 21, berstatus paksa, tidak dijadikan kandidat stop", $first === 21 && $on >= 12 && is_array($st) && preg_grep('~^REQUIRED~', $st) && !$ivs, "G5 mulai row $first, $on row; status " . json_encode($st) . '; interval stop-test G5: ' . count($ivs)); }
    if ($id === 'M11_GAS_WINDOW' || $id === 'M01_HEADROOM_BASE') { $q = (float)($i['Total Gas Quota (BBTUD)'] ?? 0); $u = (float)($i['Decision Gas Used (BBTUD)'] ?? ($i['Total Gas Used (BBTUD)'] ?? 0));
        $pass("$id.10 gas blocker: pemakaian gas di window [kuota-0,04 ; kuota]", $u >= $q - 0.04 - 1e-9 && $u <= $q + 1e-9, sprintf('gas %.4f kuota %.4f', $u, $q)); }
    if ($id === 'M10_MULTI_START' || $id === 'M04_MIN_RUNTIME_STOP') { $rv = (array)($i['V8 Priority Review'] ?? []); $peers = [];
        foreach ((array)($rv['final_candidates'] ?? []) as $c) if (($c['kind'] ?? '') === 'SWAP' && !empty($c['evaluated'])) $peers[strtoupper((string)$c['unit'])][strtoupper((string)$c['peer'])] = true;
        $mx = 0; foreach ($peers as $p) $mx = max($mx, count($p));
        $pass("$id.10 start diperlukan: seluruh unit eligible dibandingkan (SWAP ke setiap peer eligible dievaluasi) atau tidak ada start baru", $mx >= 2 || !($c2['findings'] ?? 0) || !empty($rv['relevant_intervals']),
            'peer per unit ' . json_encode(array_map('array_keys', $peers)) . '; interval relevan ' . count((array)($rv['relevant_intervals'] ?? []))); }
    if ($id === 'M02_NEAR_MIN_WB09_3' || $id === 'M03_CONSOLIDATION_WB09') { $sw = (array)($i['V11 Consolidation Sweep'] ?? []);
        $pass("$id.10 beberapa unit dekat minimum: sapuan konsolidasi dievaluasi dan setiap temuan berakhir sah", ($L['counts']['FAIL'] ?? 1) === 0 && isset($sw['status']),
            sprintf('sapuan %s dibangkitkan %s valid %s; hasil %s', $sw['status'] ?? '-', $sw['generated'] ?? '-', $sw['valid'] ?? '-', json_encode($L['counts'] ?? null))); }
    $R[] = ['id' => $id, 'sc' => $sc, 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'hr' => $i['JBBK MM Heat Rate (BTU/kWh)'] ?? null, 'merit' => $A['status'] ?? null, 'c1' => $A['c1_merit_headroom']['findings'] ?? null,
        'c2' => $c2['findings'] ?? null, 'c2_fail' => $c2['fail'] ?? null, 'c3' => $c3['intervals'] ?? null, 'c3_fail' => $c3['fail'] ?? null, 'llf' => $L['counts'] ?? null, 'cp_report' => array_intersect_key($C, array_flip(['absolute_cp_min', 'absolute_cp_min_candidate', 'winner_cp', 'delta_winner_vs_min_pct', 'winner_heat_rate', 'min_heat_rate_in_band', 'tie_break_reason']))];
}
file_put_contents($JL, implode("\n", array_map('json_encode', $R)) . "\n");
$np = count(array_filter($T, function ($t) { return $t[1]; }));
$md = "# V12 uji generik merit dispatch (FINAL nyata, backend HTTP)\n\n| id | hasil | rincian |\n|---|---|---|\n";
foreach ($T as $t) $md .= '| ' . $t[0] . ' | ' . ($t[1] ? 'LULUS' : 'GAGAL') . ' | ' . str_replace('|', '/', $t[2]) . " |\n";
file_put_contents($OUT, $md . "\nHasil: **$np/" . count($T) . "**\n"); echo "$np/" . count($T) . " PASS\n";
