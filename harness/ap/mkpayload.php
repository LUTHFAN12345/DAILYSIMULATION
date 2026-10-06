<?php
/* mkpayload.php <src> <input.json> <out_prefix> [sr=fixed:0|fixed:10|follow_pv:2] [csv=path] [variant=none|p0200]
 * Fixture + CSV (A Tanggal, B IE, C Dispatch, D PV; row 1 header) + mode SR -> sertifikat + koreksi otomatis (pp_bs_certificate_output)
 * -> <out>_in.json (input sebelum koreksi), <out>_rec.json (keputusan), <out>_corr.json (payload sesudah koreksi otomatis). */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '2G');
require $argv[1] . '/run.php';
$opt = []; foreach (array_slice($argv, 4) as $a) { [$k, $v] = array_pad(explode('=', $a, 2), 2, ''); $opt[$k] = $v; }
$in = json_decode(file_get_contents($argv[2]), true); $m = &$in['data3']['modeling'];
if (!empty($opt['csv'])) { $L = array_map('str_getcsv', array_values(array_filter(file($opt['csv'], FILE_IGNORE_NEW_LINES), 'strlen'))); array_shift($L);
    if (count($L) !== 48) { fwrite(STDERR, "CSV bukan 48 row\n"); exit(2); }
    $pv = []; foreach ($L as $i => $row) { $in['data1'][$i]['value'] = (float)$row[1]; $in['data2'][$i]['dispatch'] = (float)$row[2]; $pv[] = (float)$row[3]; }
    $m['pv_rows'] = $pv; }
[$sm, $sf] = array_pad(explode(':', $opt['sr'] ?? 'fixed:0'), 2, '0'); $m['sr_mode'] = $sm; $m['sr_fixed_mw'] = (float)$sf; $m['spinning_reserve_min'] = (float)$sf;
$pvR = (array)($m['pv_rows'] ?? []); $m['sr_effective_rows'] = array_map(fn($k) => $sm === 'follow_pv' && isset($pvR[$k]) ? max((float)$sf, (float)$pvR[$k]) : (float)$sf, range(0, 47));
if (($opt['variant'] ?? '') === 'p0200') {   /* periode terkendala 00:30-02:00: unit Stop lain tidak tersedia row 1-4, required G8 Start at 02:00 */
    foreach (['g2','g3','g4','g5','g6','g7'] as $u) $m['unit_stop_time'][] = ['unit' => $u, 'start' => 1, 'stop' => 4];
    $m['required_mode']['g8'] = ['mode' => 'start_at', 'at' => '02:00']; }
if (($opt['variant'] ?? '') === 'infeasible') {   /* periode terkendala 00:30-22:00 (row 1-44): recipient row 45-48 tidak cukup menampung */
    foreach (['g2','g3','g4','g5','g6','g7'] as $u) $m['unit_stop_time'][] = ['unit' => $u, 'start' => 1, 'stop' => 44];
    $m['required_mode']['g8'] = ['mode' => 'start_at', 'at' => '22:00']; }
unset($m);
if (($opt['variant'] ?? '') === 'tight') {   /* p0200 + Manual Fixed Flow row 5-48 dinaikkan sampai FLOW PGN row itu ~ Min + 0,05 (headroom recipient sempit) */
    $mm = &$in['data3']['modeling']; foreach (['g2','g3','g4','g5','g6','g7'] as $u) $mm['unit_stop_time'][] = ['unit' => $u, 'start' => 1, 'stop' => 4];
    $mm['required_mode']['g8'] = ['mode' => 'start_at', 'at' => '02:00'];
    pp_tl_clean_globals(); pp_budget_start(60.0, true, true); $o0 = pp_run_simulation_once(pp_normalize_copy($in)); pp_tl_clean_globals();
    $gJ = (float)($mm['ghv_jababeka'] ?? 1034.7564); $gP = (float)($mm['ghv_pgn'] ?? $gJ); $mn = (float)$mm['min_pgn_flow'];
    for ($r = 5; $r <= 48; $r++) { $f = (float)$o0['data'][$r - 1]['Flow_PGN_RT']; $ff = (float)$o0['data'][$r - 1]['FixedFlow_J'];
        $mm['manual_fixed_flows'][] = ['area' => 'JABABEKA', 'row' => $r, 'value_mmscfd' => round($ff + max(0.0, $f - $mn - 0.15) * $gP / $gJ, 4)]; }
    unset($mm); }
file_put_contents($argv[3] . '_in.json', json_encode($in));
$t0 = microtime(true); $cert = pp_bs_row1_certificate($in); $t1 = microtime(true);
if (!$cert) { echo json_encode(['cert' => null, 'cert_s' => round($t1 - $t0, 3)]), "\n"; exit(0); }
$o = pp_bs_certificate_output('cli', $in, $cert); $t2 = microtime(true);
$rec = $o['pgn_recommendation'] ?? null; $rd = (array)($rec['redistribution'] ?? []);
file_put_contents($argv[3] . '_rec.json', json_encode(['code' => $o['status'], 'certificate' => $cert, 'rec' => $rec, 'wall_s' => round($t2 - $t0, 3)]));
$sum = ['code' => $o['status'], 'cert_rows' => $cert['rows'], 'period' => $cert['period']['label'] ?? null, 'cert_s' => round($t1 - $t0, 3), 'rec_s' => round($t2 - $t1, 3),
  'available' => $rec['available'] ?? null, 'after_by_row' => $rec['recommended_fixed_flow_by_row'] ?? null, 'verification_steps' => count((array)($rec['verification'] ?? [])),
  'recipient_period' => $rd['recipient_period'] ?? null, 'recipients' => $rd['recipient_count'] ?? null, 'water' => $rd['water_filling'] ?? null,
  'daily_before_after_mmscf' => [$rd['daily_total_before_mmscf'] ?? null, $rd['daily_total_after_mmscf'] ?? null, $rd['daily_total_difference_mmscf'] ?? null],
  'validation' => $rd['constraint_validation']['status'] ?? null, 'pgn_min_after' => $rd['expected_pgn_min_after_mmscfd'] ?? null, 'src_after' => $rd['expected_pgn_source_rows_after'] ?? null,
  'feasible' => $rd['feasible'] ?? null, 'deficit' => $rd['deficit_mmscfd_rows'] ?? null, 'limiting' => $rd['limiting_constraint'] ?? null, 'margin' => $rd['margin_mmscfd'] ?? null];
echo json_encode($sum), "\n";
if (!empty($rec['available'])) { $c = $in; $mf = [];
    foreach ((array)($c['data3']['modeling']['manual_fixed_flows'] ?? []) as $e) if (strtoupper((string)($e['area'] ?? '')) !== 'JABABEKA') $mf[] = $e;
    foreach ($rd['fixed_flow_after'] as $k => $v) if (abs($v - $rd['fixed_flow_before'][$k]) > 1e-9 || in_array($k + 1, $cert['rows'], true)) $mf[] = ['area' => 'JABABEKA', 'row' => $k + 1, 'value_mmscfd' => $v];
    $c['data3']['modeling']['manual_fixed_flows'] = $mf;
    $c['data3']['modeling']['pgn_fixed_flow_recommendation_applied'] = ['schema' => 'co12-pgn-fixed-flow-correction-v3', 'correction_mode' => 'automatic', 'source_rows' => $rd['source_rows'], 'fixed_flow_before' => $rd['fixed_flow_before'], 'fixed_flow_after' => $rd['fixed_flow_after']];
    $c['_run_source'] = 'pgn_auto_correction';
    file_put_contents($argv[3] . '_corr.json', json_encode($c)); }
