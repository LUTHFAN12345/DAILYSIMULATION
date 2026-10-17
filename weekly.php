<?php
/* =============================================================================
 *  weekly.php — WEEKLY PLAN (7 hari) BACKEND MODULE
 *
 *  ARSITEKTUR (prompt §0):
 *      ONE SHARED DAILY-PLAN ENGINE   -> seluruh perhitungan memakai pp_run_simulation()
 *                                        dan pp_validate_hard_constraints() yang SAMA dengan
 *                                        Daily Plan. TIDAK ada optimizer kedua di file ini.
 *      SEVEN ISOLATED DAILY STATES    -> tiap tanggal punya deep copy input sendiri (day key ISO)
 *      ONE WEEKLY ORCHESTRATOR        -> file ini: rentang tanggal, boundary continuity,
 *                                        status/stale, agregasi, record Report Planning
 *
 *  Weekly Plan BUKAN Monitoring: tidak ada TIME PASSED. pp_weekly_sanitize_day_input()
 *  membuang actual_data / monitoring_daily_plan / locked rows / plan_type monitoring dari
 *  setiap payload harian sehingga 48 row selalu planning rows.
 * ============================================================================= */

if (!function_exists('pp_run_simulation')) require __DIR__ . '/worker02.php';

/* ---------------------------------------------------------------------------
 *  1. RENTANG TANGGAL — tepat 7 hari kalender inklusif (§2)
 * ------------------------------------------------------------------------- */
function pp_weekly_dates(string $startIso): array {
    $t = strtotime($startIso . ' 00:00:00');
    if ($t === false) throw new InvalidArgumentException("Start Date tidak valid: $startIso");
    $out = [];
    for ($i = 0; $i < 7; $i++) $out[] = date('Y-m-d', strtotime("+$i day", $t));
    return $out;
}
function pp_weekly_end_date(string $startIso): string { $d = pp_weekly_dates($startIso); return $d[6]; }

/** Validasi rentang: inklusif WAJIB tepat 7 hari. */
function pp_weekly_range_valid(string $startIso, string $endIso): array {
    $a = strtotime($startIso . ' 00:00:00'); $b = strtotime($endIso . ' 00:00:00');
    if ($a === false || $b === false) return ['ok' => false, 'days' => 0, 'reason' => 'tanggal tidak valid'];
    $days = (int)round(($b - $a) / 86400) + 1;                       // inklusif
    return ['ok' => ($days === 7), 'days' => $days,
            'reason' => $days === 7 ? '' : "rentang $days hari — wajib tepat 7 hari kalender inklusif"];
}

function pp_weekly_label(string $startIso): string {
    $d = pp_weekly_dates($startIso);
    return 'Weekly Plan ' . date('d-M-y', strtotime($d[0])) . ' to ' . date('d-M-y', strtotime($d[6]));
}
function pp_weekly_day_name(string $dayIso): string { return 'Daily Plan ' . date('d-M-y', strtotime($dayIso)); }
function pp_weekly_id(string $startIso): string { return 'WK-' . date('Ymd', strtotime($startIso)); }

/* ---------------------------------------------------------------------------
 *  2. SANITASI PAYLOAD HARIAN — Weekly TIDAK punya TIME PASSED (§5)
 * ------------------------------------------------------------------------- */
function pp_weekly_sanitize_day_input(array $input, string $dayIso, array $meta = []): array {
    $in = json_decode(json_encode($input), true);                     // deep copy: no shared reference
    $m = &$in['data3']['modeling'];
    $m['plan_type'] = 'weekly_plan_day';
    unset($m['monitoring_daily_plan'], $m['actual_data'], $m['time_passed'], $m['locked_rows']);
    $m['monitoring'] = false;
    /* actual gas/energy hanya relevan pada Monitoring -> dikosongkan agar 48 row planning murni */
    foreach (['actual_pgn_total', 'actual_energy_jababeka', 'actual_energy_mm2100'] as $k) $m[$k] = [];
    $m['weekly_plan'] = [
        'weekly_plan_id'   => (string)($meta['weekly_plan_id'] ?? ''),
        'weekly_plan_name' => (string)($meta['weekly_plan_name'] ?? ''),
        'week_start_date'  => (string)($meta['week_start_date'] ?? ''),
        'week_end_date'    => (string)($meta['week_end_date'] ?? ''),
        'day_index'        => (int)($meta['day_index'] ?? 0),
        'day_date'         => $dayIso,
    ];
    $m['plan_date'] = $dayIso;
    unset($m);
    return $in;
}

/* ---------------------------------------------------------------------------
 *  3. BOUNDARY OUT — state akhir hari N (row 48 / 00:00) (§7)
 * ------------------------------------------------------------------------- */
function pp_weekly_boundary_out(array $input, array $output): array {
    $d3 = $input['data3'] ?? []; $model = $d3['modeling'] ?? [];
    $rows = $output['data'] ?? [];
    $n = count($rows);
    if ($n === 0) return [];
    $last = $rows[$n - 1];
    /* PEMETAAN KOLOM OUTPUT: Babelan dimaterialisasi sebagai kolom 'BB1'/'BB2', sedangkan model
     * memakai kunci 'B1'/'B2' (unit_last_data_status). Membaca $last['B1'] menghasilkan 0 sehingga
     * Babelan dikira STOP dan hari berikutnya kehilangan seluruh generasi non-gas (bug nyata:
     * hari-3 kehilangan 2.880 MWh non-gas -> gas over-quota). */
    $UNITS = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','B1','B2','GE1','GE2','GE3','GE4'];
    $COL = ['B1' => 'BB1', 'B2' => 'BB2'];
    $status = []; $lastLoad = []; $runTail = []; $downTail = [];
    foreach ($UNITS as $U) {
        $col = $COL[$U] ?? $U;
        $v = (float)($last[$col] ?? 0);
        $on = ($v > 0.51);
        $status[$U]   = $on ? 'Running' : 'Stop';
        $lastLoad[$U] = round($v, 3);
        /* panjang ekor ON/OFF di akhir hari -> kredit minimum runtime/downtime hari berikutnya */
        $tail = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            $on2 = ((float)($rows[$i][$col] ?? 0) > 0.51);
            if ($on2 !== $on) break;
            $tail++;
        }
        if ($on) { $runTail[$U] = $tail; $downTail[$U] = 0; }
        else     { $runTail[$U] = 0;     $downTail[$U] = $tail; }
    }
    /* STG state: STG yang masih jalan di akhir hari -> hari berikutnya TIDAK butuh cold startup */
    $stgMode = [];
    foreach (['s1','s2','s3'] as $s) {
        $U = strtoupper($s);
        $stgMode[$s . '_startup'] = ($status[$U] === 'Running') ? 'Hot'
            : (string)(($model['stg_startup_mode'][$s . '_startup'] ?? 'Cold'));
    }
    $info = $output['info'] ?? [];
    return [
        'day_date'              => (string)($model['weekly_plan']['day_date'] ?? ($model['plan_date'] ?? '')),
        'unit_last_data_status' => $status,
        'unit_last_load_mw'     => $lastLoad,
        'run_tail_rows'         => $runTail,
        'down_tail_rows'        => $downTail,
        'stg_startup_mode'      => $stgMode,
        'change_over'           => (array)($model['change_over'] ?? ['enabled' => false]),
        'gas_cumulative'        => [
            'pgn_pipe'  => (float)($info['PGN Pipe Used (BBTUD)'] ?? 0),
            'lng'       => (float)($info['LNG Used (BBTUD)'] ?? 0),
            'total_gas' => (float)($info['Total Gas Used (BBTUD)'] ?? 0),
        ],
        'net_production_mwh'    => (float)($info['Net Production (MWh)'] ?? 0),
        'total_cost_usd'        => (float)($info['Total Cost (USD)'] ?? 0),
        'signature'             => substr(hash('sha256', json_encode([$status, $lastLoad, $stgMode])), 0, 16),
    ];
}

/* ---------------------------------------------------------------------------
 *  4. BOUNDARY IN — pasang state akhir hari N sebagai kondisi awal hari N+1 (§7)
 * ------------------------------------------------------------------------- */
function pp_weekly_boundary_in(array $input, ?array $boundary): array {
    if (!$boundary) return $input;      // hari pertama / tanpa boundary
    $in = json_decode(json_encode($input), true);
    $m = &$in['data3']['modeling'];
    if (!empty($boundary['unit_last_data_status'])) {
        foreach ((array)$boundary['unit_last_data_status'] as $U => $st)
            $m['unit_last_data_status'][strtoupper((string)$U)] = (string)$st;
    }
    /* Ramp boundary 00:00 -> 00:30: beban akhir hari sebelumnya dipakai engine sebagai
     * pembatas ramp row 1 (lihat pp_prev_day_load() di worker_functions.php). */
    if (!empty($boundary['unit_last_load_mw'])) $m['unit_last_load_mw'] = (array)$boundary['unit_last_load_mw'];
    if (!empty($boundary['run_tail_rows']))     $m['unit_run_tail_rows'] = (array)$boundary['run_tail_rows'];
    if (!empty($boundary['down_tail_rows']))    $m['unit_down_tail_rows'] = (array)$boundary['down_tail_rows'];
    if (!empty($boundary['stg_startup_mode']))  $m['stg_startup_mode'] = array_merge((array)($m['stg_startup_mode'] ?? []), (array)$boundary['stg_startup_mode']);
    /* Change Over adalah INPUT OPERATOR per tanggal, bukan state yang diwariskan. Boundary hanya
     * dipakai bila hari ini TIDAK mendefinisikan Change Over sendiri (bug: konfigurasi hari ini
     * sempat tertimpa oleh change_over hari sebelumnya sehingga enabled=true menjadi false). */
    if (isset($boundary['change_over']) && empty($m['change_over']['enabled']) && !isset($m['change_over']['blocks']))
        $m['change_over'] = (array)$boundary['change_over'];
    /* MINIMUM RUNTIME LINTAS TENGAH MALAM (ITEM-1): unit yang BARU start di ekor hari N (ekor ON
     * lebih pendek dari minimum runtime kelasnya) WAJIB tetap jalan di hari N+1 sampai kewajiban
     * runtime terpenuhi — bukan dimatikan lagi di row 1 (anti start-stop). Unit tsb ditambahkan ke
     * required_units hari N+1 dan sisa kewajiban runtime-nya dicatat agar engine/validator sinkron. */
    $limB = pp_runtime_limits($m);
    $d3B  = $in['data3'] ?? [];
    $carry = [];
    foreach ((array)($boundary['run_tail_rows'] ?? []) as $U => $tail) {
        $u = strtolower((string)$U);
        if ((int)$tail <= 0 || !isset($d3B[$u])) continue;
        if (strtolower((string)($boundary['unit_last_data_status'][$U] ?? '')) !== 'running') continue;
        $cls = pp_runtime_class($u, $d3B);
        $need = (int)($limB[$cls]['run_rows'] ?? 0) - (int)$tail;
        if ($need <= 0) continue;
        /* Kewajiban TERBATAS: hanya $need row pertama (bukan required_units sepanjang hari, yang
         * membuat unit besar menyala 48 row dan menembus kuota gas). Engine membacanya lewat
         * modeling.carry_min_runtime_rows (lihat pass "CARRY MINIMUM RUNTIME" di worker02). */
        $carry[$u] = $need;
        /* Setelah kewajiban runtime selesai unit KEMBALI BEBAS — TIDAK dipaksa stop. Menjadwalkan
         * stop paksa (versi sebelumnya) menghilangkan kapasitas siang hari dan memicu pelanggaran
         * Export floor; kini keputusan diserahkan ke optimizer (decommit / over-quota shutdown
         * hanya boleh menyentuh row >= carry window). */
    }
    if ($carry) $m['carry_min_runtime_rows'] = $carry;

    $m['weekly_boundary_in'] = [
        'from_day'  => (string)($boundary['day_date'] ?? ''),
        'signature' => (string)($boundary['signature'] ?? ''),
        'carry_min_runtime_rows' => $carry,
    ];
    unset($m);
    return $in;
}

/* ---------------------------------------------------------------------------
 *  5. RUN SATU HARI — memakai engine Daily Plan yang SAMA (§6/§8)
 * ------------------------------------------------------------------------- */
function pp_weekly_run_day(array $dayInput, ?array $boundaryIn, array $meta = []): array {
    $dayIso = (string)($meta['day_date'] ?? '');
    $in = pp_weekly_sanitize_day_input($dayInput, $dayIso, $meta);
    if ($boundaryIn) $in = pp_weekly_boundary_in($in, $boundaryIn);

    $t0 = microtime(true);
    $out = pp_run_simulation($in);                                    // SHARED ENGINE
    if (function_exists('pp_v1522_ff_postprocess')) pp_v1522_ff_postprocess($in, $out);   // V15.22: koreksi Min PGN via Fixed Flow sesudah dispatch
    $V   = pp_v1522_validate_final($in, $out);                   // SHARED VALIDATOR
    $el  = microtime(true) - $t0;

    $status = (strtoupper((string)($V['status'] ?? '')) === 'PASS') ? 'PASS' : 'FAIL';
    $rMin = (float)($in['data3']['modeling']['pln_export_priority']['range']['min'] ?? -INF);
    $under = 0;
    foreach (($out['data'] ?? []) as $r) if ((float)($r['Export_PLN'] ?? 0) < $rMin - 1e-9) $under++;

    return [
        'day_date'      => $dayIso,
        'day_index'     => (int)($meta['day_index'] ?? 0),
        'status'         => $status,
        'violations'     => array_values(array_map(fn($v) => is_array($v) ? ['type' => $v[0] ?? '', 'message' => $v[1] ?? ''] : ['type' => '', 'message' => (string)$v], $V['violations'] ?? [])),
        'export_under_rows' => $under,
        'runtime_s'      => round($el, 3),
        'input'          => $in,
        'output'         => $out,
        'summary'        => $out['info'] ?? [],
        'boundary_out'   => pp_weekly_boundary_out($in, $out),
        'boundary_in'    => $boundaryIn ?: null,
        'revision'       => substr(hash('sha256', json_encode([$in['data3']['modeling'] ?? [], $boundaryIn])), 0, 16),
    ];
}

/* ---------------------------------------------------------------------------
 *  6. STALE PROPAGATION — hari hilir wajib direrun bila upstream berubah (§7)
 * ------------------------------------------------------------------------- */
function pp_weekly_mark_stale(array $days, int $changedIndex, ?string $newBoundarySig = null): array {
    $keys = array_keys($days);
    foreach ($keys as $i => $k) {
        if ($i <= $changedIndex) continue;
        if (!isset($days[$k]) || !is_array($days[$k])) continue;
        $prevSig = (string)($days[$k]['boundary_in']['signature'] ?? '');
        $days[$k]['stale'] = true;
        $days[$k]['stale_reason'] = ($newBoundarySig !== null && $prevSig !== '' && $prevSig !== $newBoundarySig)
            ? 'boundary hari sebelumnya berubah (' . substr($prevSig, 0, 8) . ' -> ' . substr((string)$newBoundarySig, 0, 8) . ')'
            : 'upstream day diubah/direrun';
    }
    return $days;
}

/* ---------------------------------------------------------------------------
 *  7. AGREGASI WEEKLY (§9) — weighted Cost Production, BUKAN rata-rata sederhana
 * ------------------------------------------------------------------------- */
function pp_weekly_aggregate(array $dayResults): array {
    $net = 0.0; $cost = 0.0; $gross = 0.0; $hl = 0.0;
    $gas = ['pgn_pipe' => 0.0, 'lng' => 0.0, 'pep' => 0.0, 'distillate' => 0.0, 'total' => 0.0];
    $starts = 0; $viol = []; $ran = 0; $pass = 0;
    foreach ($dayResults as $d) {
        if (!is_array($d) || empty($d['summary'])) { $viol[(string)($d['day_date'] ?? '?')] = null; continue; }
        $i = $d['summary']; $ran++;
        if (($d['status'] ?? '') === 'PASS') $pass++;
        $net   += (float)($i['Net Production (MWh)'] ?? 0);
        $gross += (float)($i['Total Gross Production (MWh)'] ?? 0);
        $hl    += (float)($i['Total Houseload (MWh)'] ?? 0);
        $cost  += (float)($i['Total Cost (USD)'] ?? 0);
        $gas['pgn_pipe']   += (float)($i['PGN Pipe Used (BBTUD)'] ?? 0);
        $gas['lng']        += (float)($i['LNG Used (BBTUD)'] ?? 0);
        $gas['pep']        += (float)($i['PEP Jababeka Used (BBTUD)'] ?? 0);
        $gas['distillate'] += (float)($i['Distillate Used (kL)'] ?? 0);
        $gas['total']      += (float)($i['Total Gas Used (BBTUD)'] ?? 0);
        $starts += (int)($i['Startup Events'] ?? 0);
        $viol[(string)$d['day_date']] = count((array)($d['violations'] ?? []));
    }
    $avgSimple = 0.0; $nCp = 0;
    foreach ($dayResults as $d) if (!empty($d['summary']['Cost Production (USD/MWh)'])) { $avgSimple += (float)$d['summary']['Cost Production (USD/MWh)']; $nCp++; }
    return [
        'days_ran' => $ran, 'days_pass' => $pass,
        'total_net_production_mwh'   => round($net, 2),
        'total_gross_production_mwh' => round($gross, 2),
        'total_houseload_mwh'        => round($hl, 2),
        'total_cost_usd'             => round($cost, 2),
        'gas_by_source'              => array_map(fn($x) => round($x, 4), $gas),
        'startup_events'             => $starts,
        'violations_per_day'         => $viol,
        /* weighted = total cost / total net (BUKAN rata-rata 7 nilai harian) */
        'weighted_cost_production_usd_per_mwh' => ($net > 1e-9) ? round($cost / $net, 4) : 0.0,
        'simple_average_cost_production'       => $nCp ? round($avgSimple / $nCp, 4) : 0.0,
    ];
}

/* ---------------------------------------------------------------------------
 *  8. RECORD REPORT PLANNING — 7 record terpisah, tanpa menimpa Daily/Monitoring (§11)
 * ------------------------------------------------------------------------- */
function pp_weekly_report_records(string $startIso, array $dayResults, string $weeklyName = ''): array {
    $wid = pp_weekly_id($startIso);
    $name = $weeklyName !== '' ? $weeklyName : pp_weekly_label($startIso);
    $recs = [];
    foreach ($dayResults as $d) {
        if (!is_array($d) || empty($d['day_date'])) continue;
        $recs[] = [
            'plan_date'        => (string)$d['day_date'],
            'name_plan'        => pp_weekly_day_name((string)$d['day_date']),
            'plan_type'        => 'weekly_plan_day',              // unique key -> tidak menimpa 'plan'/'monitoring'
            'weekly_plan_id'   => $wid,
            'weekly_plan_name' => $name,
            'week_start_date'  => (string)$startIso,
            'week_end_date'    => pp_weekly_end_date($startIso),
            'day_index'        => (int)($d['day_index'] ?? 0),
            'day_date'         => (string)$d['day_date'],
            'status'           => (string)($d['status'] ?? 'NOT RUN'),
            'source'           => 'weekly_plan',                   // badge di Report
            'editable'         => true,
            'input'            => $d['input'] ?? null,
            'output'           => $d['output'] ?? null,
        ];
    }
    return $recs;
}

/** Merge record weekly ke report_planning existing tanpa menyentuh record plan/monitoring. */
function pp_weekly_merge_report(array $reportPlanning, array $records): array {
    $arr = $reportPlanning;
    $list = isset($arr['records']) && is_array($arr['records']) ? $arr['records'] : [];
    foreach ($records as $r) {
        $idx = -1;
        foreach ($list as $i => $x) {
            if (($x['name_plan'] ?? '') === $r['name_plan']
                && (($x['plan_type'] ?? 'plan') === $r['plan_type'])
                && (($x['weekly_plan_id'] ?? '') === $r['weekly_plan_id'])) { $idx = $i; break; }
        }
        if ($idx >= 0) $list[$idx] = $r; else $list[] = $r;        // replace-per-day, bukan seluruh minggu
    }
    $arr['records'] = $list;
    return $arr;
}

/* ---------------------------------------------------------------------------
 *  9. ORKESTRASI 7 HARI (untuk CLI/harness; UI memanggil satu request per hari)
 * ------------------------------------------------------------------------- */
function pp_weekly_run_all(string $startIso, array $dayInputs, array $opts = []): array {
    $dates = pp_weekly_dates($startIso);
    $sequential = (bool)($opts['sequential'] ?? true);              // default Sequential Weekly Run
    $stopOnFail = (bool)($opts['stop_on_fail'] ?? false);
    $cancelAfter = (int)($opts['cancel_after_days'] ?? 0);          // simulasi Cancel Weekly Run
    $meta0 = ['weekly_plan_id' => pp_weekly_id($startIso), 'weekly_plan_name' => pp_weekly_label($startIso),
              'week_start_date' => $dates[0], 'week_end_date' => $dates[6]];
    $days = []; $boundary = $opts['boundary_in'] ?? null; $progress = [];
    foreach ($dates as $i => $iso) {
        if ($cancelAfter > 0 && $i >= $cancelAfter) {                // Cancel: hari selesai TIDAK dihapus
            $days[$iso] = ['day_date' => $iso, 'day_index' => $i, 'status' => 'NOT RUN', 'cancelled' => true];
            continue;
        }
        $inDay = $dayInputs[$iso] ?? ($dayInputs[$i] ?? null);
        if ($inDay === null) { $days[$iso] = ['day_date' => $iso, 'day_index' => $i, 'status' => 'NOT RUN']; continue; }
        $meta = $meta0 + ['day_index' => $i, 'day_date' => $iso];
        $res = pp_weekly_run_day($inDay, $sequential ? $boundary : null, $meta);
        $days[$iso] = $res;
        $progress[] = ['step' => ($i + 1) . '/7', 'day' => $iso, 'status' => $res['status'], 'runtime_s' => $res['runtime_s']];
        if ($sequential) $boundary = $res['boundary_out'];
        if ($stopOnFail && $res['status'] !== 'PASS') break;
    }
    return [
        'weekly_plan_id' => $meta0['weekly_plan_id'], 'weekly_plan_name' => $meta0['weekly_plan_name'],
        'week_start_date' => $dates[0], 'week_end_date' => $dates[6],
        'mode' => $sequential ? 'sequential' : 'independent',
        'days' => $days, 'progress' => $progress,
        'weekly_summary' => pp_weekly_aggregate($days),
    ];
}
