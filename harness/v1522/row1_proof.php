<?php
/* row1_proof.php <src> <payload.json> : bukti numerik row 1 (00:30) — kapasitas legal per unit, Unit Priority, Block Required, sumber bahan bakar.
 * Hanya MEMBACA fungsi engine (normalisasi + batas unit); tidak mengubah apa pun. */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '2G');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $c = pp_normalize_copy($in); if (function_exists('pp_v1518_semantics')) pp_v1518_semantics($c);
$d3 = $c['data3']; $m = $d3['modeling']; $rank = pp_priority_rank($m); $ie = (array)(pp_ie_series($c)['eff'] ?? []);
$pt = pp_compute_pln_targets($m, (array)($c['data2'] ?? [])); $lo = (array)($pt['lo'] ?? []);
$last = []; foreach ((array)$m['unit_last_data_status'] as $k => $v) $last[strtolower($k)] = (string)$v;
$blocks = []; foreach ((array)($m['block_priority'] ?? []) as $bi => $b) foreach ((array)$b as $u) if ($u !== 'required') $blocks[strtolower($u)] = $bi;
$bru = (array)($m['block_required_user'] ?? []);
$rowsU = []; $g = [];
foreach (array_keys($rank) as $u) { $u = strtolower((string)$u); if (!isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
    $run = preg_match('/run/i', $last[$u] ?? '') === 1; $stopped = pp_is_unit_stopped($d3, $m, $u, 1);
    $mn = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? ($d3[$u]['min_load'] ?? 0))); $mx = (float)pp_effective_max_load($d3, $m, $u, 1);
    $legal = $run && !$stopped;
    $rowsU[] = ['unit' => strtoupper($u), 'priority_rank' => $rank[$u], 'priority_group' => $blocks[$u] ?? null, 'last_data_status' => $last[$u] ?? null,
        'commitment' => $m['commitment_semantics'][$u] ?? 'NONE', 'stop_policy' => $m['stop_policy'][$u] ?? 'NONE', 'stop_schedule_row1' => $stopped,
        'legal_min_mw' => $mn, 'legal_max_mw' => $mx, 'can_load_row1' => $legal,
        'reason_if_not' => $legal ? null : ($stopped ? 'STOP_SCHEDULE/UNAVAILABLE' : 'LAST_DATA_STOP: startup sequence -> paling cepat berbeban row 2')];
    if ($legal && !preg_match('/^s\d$/', $u)) $g[$u] = $mx;
}
foreach (['s1', 's2', 's3'] as $s) if (isset($d3[$s])) $g[$s] = 0.0;
pp_recompute_stgs($g, $d3, $m, 1);
$tot = 0.0; foreach ($g as $u => $v) if (!in_array($u, ['g10', 'ge1', 'ge2', 'ge3', 'ge4'], true)) $tot += (float)$v;
$house = calc_house_load($g); $exp = $tot - $house - (float)($ie[0] ?? 0);
$out = ['row' => 1, 'time' => '00:30', 'ie_mw' => (float)($ie[0] ?? 0), 'range_min_export_mw' => (float)($lo[0] ?? 0), 'house_load_mw' => round($house, 2),
    'units_at_legal_max_row1' => array_map(fn($v) => round((float)$v, 2), $g), 'generation_at_legal_max_mw' => round($tot, 2), 'export_max_row1_mw' => round($exp, 2),
    'deficit_mw' => round(max(0.0, (float)($lo[0] ?? 0) - $exp), 2), 'block_required_user' => $bru,
    'fuel' => ['gas_quota' => $m['gas_quota'] ?? null, 'gas_shortage_action' => $m['gas_shortage_action'] ?? null, 'additional_lng' => $m['additional_lng'] ?? null,
               'note' => 'LNG/Distillate menambah bahan bakar, bukan kapasitas MW; tidak dapat menutup defisit kapasitas row 1'],
    'units' => $rowsU];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
