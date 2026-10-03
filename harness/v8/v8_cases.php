<?php
/* V8 targeted (jalur job economic_review, akar baru per skenario): reproducer workbook + GE gas + unit priority.
 * php v8_cases.php <src-root> <out.md> <out.jsonl> */
ini_set('memory_limit', '3G');
$src = rtrim($argv[1], '/'); if (!is_file($src . '/run.php')) { fwrite(STDERR, "source tidak ditemukan: $src\n"); exit(2); } $abs = function ($f) { return ($f !== '' && $f[0] === '/') ? $f : getcwd() . '/' . $f; }; $OUT = $abs($argv[2]); $JL = $abs($argv[3]); $T = []; $J = [];
$v7 = function (string $sc) { $f = "/home/claude/t5/v8/ref/v7_$sc.json"; return is_file($f) ? json_decode(file_get_contents($f), true) : null; };
$run = function (string $sc, string $tag = '') use ($src) {
    $S = "/tmp/claude-0/v8case_" . $sc . $tag; exec('rm -rf ' . escapeshellarg($S)); mkdir("$S/jobs", 0777, true);
    foreach (['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'] as $f) if (is_file("$src/$f")) copy("$src/$f", "$S/$f");
    $o = "/tmp/claude-0/v8case_$sc$tag.json"; @unlink($o); $t = microtime(true);
    if (!is_file("$S/run.php")) { fwrite(STDERR, "source tidak ditemukan: $src\n"); exit(2); }
    exec('timeout 900 /usr/local/bin/php74 -d max_execution_time=0 /home/claude/t5/v8/job_dump.php ' . escapeshellarg($S) . ' ' . escapeshellarg($sc) . ' ' . escapeshellarg($o) . ' 2>&1', $x);
    $d = json_decode((string)@file_get_contents($o), true); $d['_wall'] = round(microtime(true) - $t, 2); return $d;
};
$pass = function (string $id, bool $ok, string $why) use (&$T) { $T[] = [$id, $ok, $why]; echo ($ok ? 'PASS' : 'FAIL') . "  $id  -- $why\n"; };
$on = function (array $rows, string $u) { $x = []; foreach ($rows as $k => $r) if ((float)($r[$u] ?? 0) > 0.01) $x[] = $k + 1; return $x; };
$fin = function ($d) { $o = $d['output'] ?? []; $i = $o['info'] ?? []; $rg = $o['release_gate'] ?? [];
    return count($o['data'] ?? []) === 48 && ($rg['release_allowed'] ?? false) === true && ($rg['hard_validation'] ?? '') === 'PASS'
        && ($i['PLN Export Compliance'] ?? '') === 'OK' && (float)($i['Residual Gas Shortage (BBTUD)'] ?? 1) === 0.0; };
$hpa = function ($d) { $h = $d['output']['info']['Headroom Priority Audit'] ?? []; return [($h['status'] ?? '-'), (int)($h['flags_unresolved'] ?? -1), $h]; };
$reasons = function ($h) { $all = []; foreach ((array)($h['flags'] ?? []) as $f) foreach ((array)($f['reasons'] ?? []) as $r) $all[] = $r; return $all; };

/* 1 reproducer */
$A = $v7('WB09'); $d = $run('WB09'); $o = $d['output']; $i = $o['info']; $rows = $o['data']; [$hs, $hu, $h] = $hpa($d);
$cpA = (float)($A['output']['info']['Cost Production (USD/MWh)'] ?? 0); $cp = (float)($i['Cost Production (USD/MWh)'] ?? 99);
$J[] = ['sc' => 'WB09', 'wall' => $d['_wall'], 'cp' => $cp, 'cp_v7' => $cpA, 'v8' => $i['V8 Priority Review']['applied'] ?? null];
$pass('T1 reproducer Daily_Plan_09_Jul_26_Baru: FINAL 48 row, hard PASS, Export 48/48, residual 0', $fin($d), "wall {$d['_wall']} s, CP $cp");
$ge = array_sum(array_map(function ($u) use ($on, $rows) { return count($on($rows, $u)); }, ['GE1', 'GE2', 'GE3', 'GE4']));
$mp = $i['MM2100 Gas Provenance'] ?? [];
$pass('T2 kuota MM2100 0 + fixed/actual MM2100 0: GE1-GE4 = 0 MW, provenance PASS (sumber NONE), MM2100 Daily Used 0', $ge === 0 && ($mp['gas_source'] ?? '') === 'NONE' && ($mp['status'] ?? '') === 'PASS' && (float)($i['MM2100 Daily Used (BBTUD)'] ?? 1) === 0.0,
      "GE rows $ge, sumber " . ($mp['gas_source'] ?? '-') . ", used " . ($i['MM2100 Daily Used (BBTUD)'] ?? '-'));
/* dispatch V7 (GE1 3 MW tanpa gas) wajib ditolak validator V8 */
error_reporting(E_ALL & ~E_WARNING); define('PP_LIB_ONLY', true); chdir($src); require $src . '/run.php'; require '/home/claude/t5/v3/scen.php';
$orig = pp_normalize_copy(v3_input('WB09')); $V = pp_validate_hard_constraints($orig, $A['output']); $ty = array_values(array_unique(array_map(function ($v) { return $v[0]; }, $V['violations'])));
$pass('T2b dispatch V7 (GE1 3 MW row 1, kuota KP72 0) ditolak validator V8: mm2100_gas_source', in_array('mm2100_gas_source', $ty, true), 'status ' . $V['status'] . ' pelanggaran ' . implode(',', $ty));
$g2 = $on($rows, 'G2'); $g5 = $on($rows, 'G5');
$pass('T4 unit prioritas rendah start lalu kebutuhan turun: stop pada row legal pertama (G5 = minimum runtime 12 row, G2 tidak running)', !$g2 && count($g5) === 12 && end($g5) - $g5[0] === 11,
      'G2 ' . count($g2) . ' row; G5 row ' . ($g5[0] ?? '-') . '-' . (end($g5) ?: '-') . ' (V7: G2 row 26-48 = 23 row)');
$v8 = $i['V8 Priority Review'] ?? []; $proof = null;
foreach (array_merge((array)($v8['history'] ?? []), (array)($v8['final_candidates'] ?? [])) as $c) if (in_array($c['kind'], ['OFF', 'DECOMMIT'], true) && in_array('EXPORT', $c['violations'], true)) $proof = $c;   // V9: kandidat OFF bernama DECOMMIT
$pr = $proof['prescreen'] ?? null;
$pass('T5 headroom G1/G8/G9 dipakai sebelum unit prioritas rendah: tanpa G5, G1/G8/G9/BBLN di Effective Max pun Export di bawah Range Min (bukti kapasitas)', $proof !== null && (is_array($pr) || $proof['method'] === 'DISPATCH_48_ROW_ENGINE'),
      $proof === null ? 'tidak ada bukti' : ($proof['id'] . ': ' . (is_array($pr) ? sprintf('row %d Export maks %.2f < Range Min %.1f MW', $pr['row'], $pr['export_max_mw'], $pr['range_min_mw']) : 'simulasi 48 row: ' . implode(',', $proof['violations']))));
$pass('T5b CP lebih rendah dari incumbent V7 dan kandidat pembanding tercatat (incumbent, G2 off/trunc, G5 swap, delay)', $cp < $cpA - 1e-4 && count((array)($v8['history'] ?? [])) >= 6,
      "CP V7 $cpA -> V8 $cp; diterapkan " . implode(', ', array_map(function ($a) { return $a['candidate']; }, (array)($v8['applied'] ?? []))));
$rs = $reasons($h); $hasEx = (bool)preg_grep('~EXPORT~', $rs);
$pass('T7 alasan terhalang Export (reserve/Export/Bus Flow) tercatat per row; Headroom/Unit Priority PASS_WITH_REASON', preg_match('~^PASS~', $hs) && $hu === 0 && $hasEx, "$hs, tanpa alasan $hu, alasan EXPORT=" . ($hasEx ? 'YA' : 'TIDAK'));
/* 3 GE fixed flow manual sah */
$d = $run('WB09_MANFF'); $i = $d['output']['info']; $rows = $d['output']['data']; $mp = $i['MM2100 Gas Provenance'] ?? [];
$geR = []; foreach (['GE1', 'GE2', 'GE3', 'GE4'] as $u) $geR = array_merge($geR, $on($rows, $u)); $geR = array_values(array_unique($geR));
$J[] = ['sc' => 'WB09_MANFF', 'wall' => $d['_wall'], 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'cp_v7' => $v7('WB09_MANFF')['output']['info']['Cost Production (USD/MWh)'] ?? null];
$pass('T3 kuota MM2100 0 + fixed flow manual MM2100 sah (row 1-2): GE hanya pada row bersumber, provenance jelas, gas tidak melebihi sumber, biaya gas dihitung',
      $fin($d) && !array_diff($geR, [1, 2]) && ($mp['gas_source'] ?? '') === 'MANUAL_FIXED_FLOW_MM2100' && (int)($mp['rows_failed'] ?? 1) === 0 && (float)($i['Cost PEP KP72 (USD)'] ?? 0) > 0,
      'GE row ' . json_encode($geR) . ', sumber ' . ($mp['gas_source'] ?? '-') . ', row gagal ' . ($mp['rows_failed'] ?? '-') . ', biaya gas MM2100 ' . ($i['Cost PEP KP72 (USD)'] ?? '-') . ' USD, Heat Rate MM2100 ' . ($i['MM2100 Heat Rate (BTU/kWh)'] ?? '-'));
/* 3b GE dengan kuota KP72 sah */
$d = $run('WB09_KP72'); $i = $d['output']['info']; $rows = $d['output']['data']; $mp = $i['MM2100 Gas Provenance'] ?? [];
$J[] = ['sc' => 'WB09_KP72', 'wall' => $d['_wall'], 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'cp_v7' => $v7('WB09_KP72')['output']['info']['Cost Production (USD/MWh)'] ?? null];
$pass('T3b kuota KP72 2,2 sah: GE berbeban, heat rate dan cost MM2100 dihitung, provenance KP72 PASS', $fin($d) && count($on($rows, 'GE1')) > 0 && ($mp['gas_source'] ?? '') === 'KP72_QUOTA' && (float)($i['MM2100 Cost Production (USD/MWh)'] ?? 0) > 0 && (float)($i['MM2100 Heat Rate (BTU/kWh)'] ?? 0) > 0,
      'GE1 ' . count($on($rows, 'GE1')) . ' row, HR MM2100 ' . ($i['MM2100 Heat Rate (BTU/kWh)'] ?? '-') . ', CP MM2100 ' . ($i['MM2100 Cost Production (USD/MWh)'] ?? '-') . ', used ' . ($i['MM2100 Daily Used (BBTUD)'] ?? '-') . '/' . ($i['MM2100 Quota (BBTUD)'] ?? '-'));
/* 6 ramp */
$d = $run('WB09_G8FIX'); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $i = $d['output']['info'];
$J[] = ['sc' => 'WB09_G8FIX', 'wall' => $d['_wall'], 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'cp_v7' => $v7('WB09_G8FIX')['output']['info']['Cost Production (USD/MWh)'] ?? null];
$pass('T6 headroom terhalang ramp (Fixed Load G8/G9 76 MW row 24-27): alasan RAMP per row, FINAL, PASS_WITH_REASON', $fin($d) && preg_match('~^PASS~', $hs) && $hu === 0 && (bool)preg_grep('~RAMP~', $rs),
      "$hs, tanpa alasan $hu, contoh " . implode(' | ', array_slice(array_values(preg_grep('~RAMP~', $rs)), 0, 2)) . ", CP " . ($i['Cost Production (USD/MWh)'] ?? '-'));
/* 8 STG / block coupling */
$d = $run('WB09_G1STOP'); [$hs, $hu, $h] = $hpa($d); $rs = $reasons($h); $i = $d['output']['info'];
$J[] = ['sc' => 'WB09_G1STOP', 'wall' => $d['_wall'], 'cp' => $i['Cost Production (USD/MWh)'] ?? null, 'cp_v7' => $v7('WB09_G1STOP')['output']['info']['Cost Production (USD/MWh)'] ?? null];
$blk = array_values(preg_grep('~STG_|FORCED_STATUS~', $rs));
$pass('T8 kopling blok STG (G1 stop 10:00, S2 continuous): alasan STG/blok per row, FINAL, PASS_WITH_REASON', $fin($d) && preg_match('~^PASS~', $hs) && $hu === 0 && count($blk) > 0,
      "$hs, tanpa alasan $hu, contoh " . implode(' | ', array_slice(array_unique($blk), 0, 2)) . ", CP " . ($i['Cost Production (USD/MWh)'] ?? '-'));
/* determinisme */
$d1 = json_decode(file_get_contents('/tmp/claude-0/v8case_WB09.json'), true); $d2 = $run('WB09', '_ulang');
$s1 = md5(json_encode($d1['output']['data'])); $s2 = md5(json_encode($d2['output']['data']));
$pass('T9 deterministik: reproducer dijalankan ulang di akar baru -> 48 row identik', $s1 === $s2, "$s1 / $s2");
file_put_contents($JL, implode("\n", array_map('json_encode', $J)) . "\n");
$L = ['# V8 targeted — reproducer, gas GE, unit priority', '', '| id | uji | hasil | bukti |', '|---|---|---|---|'];
foreach ($T as $k => $t) $L[] = sprintf('| %d | %s | %s | %s |', $k + 1, $t[0], $t[1] ? 'PASS' : 'FAIL', str_replace('|', '/', $t[2]));
$L[] = ''; $L[] = sprintf('**%d/%d PASS**', count(array_filter($T, function ($t) { return $t[1]; })), count($T));
file_put_contents($OUT, implode("\n", $L) . "\n");
