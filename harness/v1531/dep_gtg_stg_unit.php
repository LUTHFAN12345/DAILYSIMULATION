<?php
// V15.31 targeted test dependency GTG–STG (resolver engine pp_v1518_semantics), generik untuk S1/S2/S3: php dep_gtg_stg_unit.php <app> <fixture.json>
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$base = json_decode(file_get_contents($argv[2]), true); $res = []; $ok = 0; $fail = 0;
$t = function (string $id, string $n, $c, $d = null) use (&$res, &$ok, &$fail) { $st = $c === null ? 'NOT_RUN' : ($c ? 'PASS' : 'FAIL'); if ($c === true) $ok++; elseif ($c === false) $fail++;
    $res[] = ['id' => $id, 'test' => $n, 'status' => $st, 'detail' => $d]; echo "$st $id $n" . ($d !== null ? ' ' . substr(json_encode($d), 0, 260) : '') . "\n"; };
$D3 = $base['data3']; $M0 = $D3['modeling'];
$feed = fn($s) => array_map('strtolower', (array)($D3[$s]['gtg'] ?? []));
$prio = function ($s, $m) { foreach ((array)$m['unit_priority'] as $g) { $lg = array_map('strtolower', (array)$g); if (in_array($s, $lg, true)) return $lg; } return []; };
/* blok bersih: STG + seluruh GTG blok tanpa request, Initial Stop, CC */
$clean = function (string $s, ?array $in = null) use ($base, $feed) { $x = $in ?? $base; $m = &$x['data3']['modeling'];
    foreach (array_merge([$s], $feed($s)) as $u) { unset($m['required_mode'][$u], $m['stop_mode'][$u]); $m['required_units'] = array_values(array_diff(array_map('strtolower', (array)($m['required_units'] ?? [])), [$u]));
        $m['unit_cannot_stop'] = array_values(array_diff(array_map('strtolower', (array)($m['unit_cannot_stop'] ?? [])), [$u])); $m['unit_last_data_status'][strtoupper($u)] = 'Stop'; if ($u[0] === 'g') $m['sc_cc'][$u] = 'CC';
        $m['unit_stop'] = array_values(array_diff(array_map('strtolower', (array)($m['unit_stop'] ?? [])), [$u])); }
    unset($m['block_required_user']); unset($m); return $x; };
$req = function (array $x, string $u, string $mode, ?string $at = null, bool $hold = false) { $m = &$x['data3']['modeling']; $m['required_units'][] = $u;
    $m['required_mode'][$u] = $mode === 'request' ? ['mode' => 'start_at', 'at' => $at ?? '10:00'] : ['mode' => $mode === 'continuous' ? 'continuous' : 'based_on_sim'];
    if ($mode === 'continuous') { $m['unit_cannot_stop'][] = $u; $m['unit_last_data_status'][strtoupper($u)] = 'Running'; }
    if ($hold) $m['stop_mode'][$u] = ['mode' => 'hold_to_end']; unset($m); return $x; };
$sem = function (array $x) { pp_v1518_semantics($x); return $x['data3']['modeling']; };
$autoFor = fn($m, $re) => array_values(array_filter((array)($m['auto_semantics'] ?? []), fn($a) => preg_match($re, (string)($a['reason'] ?? ''))));
$lead1 = function ($s, $m0) use ($feed, $prio) { foreach ($prio($s, $m0) as $g) if (in_array($g, $feed($s), true)) return $g; return $feed($s)[0]; };
$tid = 0;
foreach (['s1', 's2', 's3'] as $s) { $tid++;
    $m = $sem($req($clean($s), $s, 'simulation')); $a = $autoFor($m, '/^STG_' . strtoupper($s) . '_LEAD_GTG_CC$/'); $L = $lead1($s, $M0);
    $others = array_diff($feed($s), [$L]); $noOther = !array_intersect($others, array_map('strtolower', (array)$m['required_units']));
    $t('T0' . $tid, strtoupper($s) . ' Stop + seluruh GTG blok Stop + STG Simulation -> GTG prioritas tertinggi legal (' . strtoupper($L) . ') mengikuti Simulation; GTG lain tidak',
        count($a) === 1 && $a[0]['unit'] === $L && ($m['commitment_semantics'][$L] ?? '') === 'START_BASED_ON_SIMULATION' && strtolower((string)($m['unit_last_data_status'][strtoupper($L)] ?? '')) === 'stop' && $noOther, ['auto' => $a, 'lead' => $L]); }
// 4 Request
$s = 's1'; $L = $lead1($s, $M0); $m = $sem($req($clean($s), $s, 'request', '12:00'));
$t('T04', 'STG Start Based On Request -> lead GTG Required (Simulation: engine memajukan start lead sesuai sequence agar STG masuk 12:00); request STG dipertahankan',
    in_array($L, (array)$m['required_units'], true) && ($m['required_mode'][$s]['mode'] ?? '') === 'start_at' && ($m['required_mode'][$s]['at'] ?? '') === '12:00', ['lead' => $L, 'rm_lead' => $m['required_mode'][$L] ?? null, 'rm_stg' => $m['required_mode'][$s] ?? null]);
// 5 Continuous dari Initial Stop
$x = $req($clean($s), $s, 'continuous'); $x['data3']['modeling']['unit_last_data_status'][strtoupper($s)] = 'Stop'; $m = $sem($x);
$t('T05', 'STG Unit Continuous Running dari Initial Stop -> lead tidak dipalsukan Running (startup legal)', strtolower((string)($m['unit_last_data_status'][strtoupper($L)] ?? '')) !== 'running',
    ['lead_last' => $m['unit_last_data_status'][strtoupper($L)] ?? null, 'lead_com' => $m['commitment_semantics'][$L] ?? null, 'stg_last' => $m['unit_last_data_status'][strtoupper($s)] ?? null]);
// 6 Stop Status Continuous
$m = $sem($req($clean($s), $s, 'simulation', null, true));
$t('T06', 'STG Stop Status Continuous -> lead GTG hold sesudah actual start', ($m['stop_policy'][$L] ?? '') === 'HOLD_TO_END_AFTER_START', ['pol' => $m['stop_policy'][$L] ?? null]);
// 7 priority-1 unavailable -> fallback + reason code
$x = $req($clean($s), $s, 'simulation'); $x['data3']['modeling']['unit_stop'][] = $L; $m = $sem($x); $a = $autoFor($m, '/_LEAD_GTG_CC$/');
$L2 = null; foreach ($prio($s, $M0) as $g) if (in_array($g, $feed($s), true) && $g !== $L) { $L2 = $g; break; }
$t('T07', 'GTG prioritas-1 unavailable -> fallback prioritas berikutnya + reason code', $a && $a[0]['unit'] === $L2 && ($a[0]['lead_fallback'][0]['reason'] ?? '') === 'UNAVAILABLE_ALL_DAY', $a);
// 8 satu GTG sudah Running
$x = $req($clean($s), $s, 'simulation'); $x['data3']['modeling']['unit_last_data_status'][strtoupper($L2)] = 'Running'; $m = $sem($x);
$t('T08', 'satu GTG blok sudah Running -> dipakai, tidak ada start GTG lain tanpa kebutuhan', !$autoFor($m, '/_LEAD_GTG_CC$/') && !in_array($L, (array)$m['required_units'], true), $autoFor($m, '/_LEAD_GTG_CC$/'));
// 9-12 GTG CC -> STG
foreach (['s1', 's2', 's3'] as $s) { $L = $lead1($s, $M0); $m = $sem($req($clean($s), $L, 'simulation')); $a = $autoFor($m, '/_CC_REQUIRES_STG_' . strtoupper($s) . '$/');
    $t('T09-' . strtoupper($s), 'GTG ' . strtoupper($L) . ' Simulation + CC, ' . strtoupper($s) . ' Stop -> STG mengikuti Simulation/sequence (tidak Running palsu)',
        count($a) === 1 && in_array($s, (array)$m['required_units'], true) && ($m['commitment_semantics'][$s] ?? '') === 'START_BASED_ON_SIMULATION' && strtolower((string)$m['unit_last_data_status'][strtoupper($s)]) === 'stop', $a); }
$s = 's1'; $L = $lead1($s, $M0);
$m = $sem($req($clean($s), $L, 'request', '09:00')); $t('T10', 'GTG Request + CC -> STG Simulation (first STG load ditentukan profil blok sesudah GTG)', in_array($s, (array)$m['required_units'], true) && ($m['required_mode'][$L]['at'] ?? '') === '09:00', ['stg_com' => $m['commitment_semantics'][$s] ?? null]);
$x = $req($clean($s), $L, 'continuous'); $m = $sem($x);
$t('T11', 'GTG Unit Continuous Running + CC -> STG dependency legal (Simulation + hold), STG tidak dipalsukan Running', in_array($s, (array)$m['required_units'], true) && ($m['stop_policy'][$s] ?? '') === 'HOLD_TO_END_AFTER_START' && strtolower((string)$m['unit_last_data_status'][strtoupper($s)]) === 'stop', ['pol' => $m['stop_policy'][$s] ?? null]);
$m = $sem($req($clean($s), $L, 'simulation', null, true)); $t('T12', 'GTG Stop Status Continuous + CC, sole lead -> STG Stop Status Continuous sesudah start', ($m['stop_policy'][$s] ?? '') === 'HOLD_TO_END_AFTER_START', ['pol' => $m['stop_policy'][$s] ?? null]);
$x = $req($clean($s), $L, 'simulation'); $x['data3']['modeling']['sc_cc'][$L] = 'SC'; $m = $sem($x);
$t('T13', 'GTG mode SC -> STG tidak ikut', !in_array($s, (array)$m['required_units'], true) && !$autoFor($m, '/_CC_REQUIRES_STG_/'), ['ru' => $m['required_units']]);
$x2 = $req($clean($s), $L, 'simulation'); $x2['data3']['modeling']['required_mode'][$s] = ['mode' => 'start_at', 'at' => '15:00']; $x2['data3']['modeling']['required_units'][] = $s; $x2['data3']['modeling']['sc_cc'][$L] = 'SC'; $m2 = $sem($x2);
$t('T14', 'GTG CC -> SC: hanya dependency otomatis hilang; request STG eksplisit user tetap', ($m2['required_mode'][$s]['at'] ?? '') === '15:00' && !$autoFor($m2, '/_CC_REQUIRES_STG_/'), $m2['required_mode'][$s] ?? null);
$m = $sem($req($clean($s), $s, 'simulation')); $others = array_diff($feed($s), [$L]);
$t('T15', 'GTG kedua/ketiga tidak otomatis mengikuti', !array_intersect($others, array_map('strtolower', (array)$m['required_units'])), ['ru' => $m['required_units']]);
$x = $req($req($clean($s), $s, 'simulation'), $L2, 'request', '07:00'); $m = $sem($x);
$t('T16', 'explicit user request unit lain (' . strtoupper($L2) . ' Request 07:00) tidak dioverride; tidak ada lead tambahan', ($m['required_mode'][$L2]['at'] ?? '') === '07:00' && !$autoFor($m, '/_LEAD_GTG_CC$/'), $m['required_mode'][$L2] ?? null);
$x = $req($clean($s), $s, 'simulation'); foreach ($feed($s) as $g) if ($g !== $L && $g !== $L2) $x['data3']['modeling']['unit_stop'][] = $g; $x['data3']['modeling']['unit_stop'][] = $L; $m = $sem($x); $a = $autoFor($m, '/_LEAD_GTG_CC$/');
$t('T17', 'dua GTG CC kandidat, satu unavailable -> lead = GTG CC legal', $a && $a[0]['unit'] === $L2, $a);
$x = $clean($s); $bn = null; foreach (pp_sp_block_defs() as $n => $us) if (in_array($s, $us, true)) $bn = $n; $x['data3']['modeling']['block_required_user'] = [$bn => true]; $m = $sem($x);
$t('T18', 'Block Required + dependency -> lead GTG CC + STG continuous dari resolver yang sama', (bool)$autoFor($m, '/^BLOCK_REQUIRED_CONTINUOUS$/'), $autoFor($m, '/^BLOCK_REQUIRED/'));
$t('T19', 'Check Yesterday Data Start Up CC tidak direset resolver', null, 'tidak diuji pada sprint ini (perlu fixture Check Yesterday)');
$x = $req($clean($s), $L, 'simulation', null, true); $a1 = $x; pp_v1518_semantics($a1); $a2 = $a1; pp_v1518_semantics($a2);
$t('T20', 'resolver idempoten (Save/Reload: dijalankan ulang menghasilkan state yang sama, tanpa penggandaan)', json_encode($a1['data3']['modeling']['required_units']) === json_encode($a2['data3']['modeling']['required_units']) && json_encode($a1['data3']['modeling']['stop_policy']) === json_encode($a2['data3']['modeling']['stop_policy']), null);
$x = $req($clean($s), $s, 'simulation'); foreach ($x['data3']['modeling']['unit_priority'] as $gi => $g) if (in_array($s, array_map('strtolower', (array)$g), true)) { $lg = array_map('strtolower', (array)$g); $i1 = array_search($L, $lg, true); $i2 = array_search($L2, $lg, true); [$lg[$i1], $lg[$i2]] = [$lg[$i2], $lg[$i1]]; $x['data3']['modeling']['unit_priority'][$gi] = $lg; }
$m = $sem($x); $a = $autoFor($m, '/_LEAD_GTG_CC$/'); $t('T21', 'perubahan Unit Priority sebelum startup -> lead dievaluasi ulang', $a && $a[0]['unit'] === $L2, $a);
echo json_encode(['pass' => $ok, 'fail' => $fail]), "\n";
@mkdir(__DIR__ . '/evidence'); file_put_contents(__DIR__ . '/evidence/dep_gtg_stg_unit.json', json_encode(['pass' => $ok, 'fail' => $fail, 'tests' => $res], JSON_PRETTY_PRINT));
