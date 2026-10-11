<?php
/* d2unit.php <src> : uji unit D1/D2 alokator blok kontinu (ramp naik 30->50->75->100 dan turun 100->75->50->30 -> gas sebelum lead-out). */
define('PP_LIB_ONLY', 1); require $argv[1] . '/run.php';
$in = pp_normalize_copy(json_decode(file_get_contents('/home/claude/ab/payloads/GOLDEN_dist.json'), true)); $d3 = $in['data3'];
$data = []; for ($i = 0; $i < 48; $i++) $data[$i] = ['G6' => $i < 40 ? 31.0 : ($i === 40 ? 5.0 : 0.0)];   // G6 31 MW s/d row 40, lead-out 5 MW row 41, off sesudahnya
$out = [];
foreach ([['D1_D2_lead_out', 1.2], ['D2_kecil', 0.35]] as [$name, $need]) {
    $al = pp_dist_continuous_alloc($d3, $data, [], ['g6'], $need, 0.04, null, 'band');
    $rows = $data; foreach ($al['cells'] as [$i, $u, $st]) $rows[$i]['DistMix_G6'] = $st;
    $aud = pp_dist_continuity_audit($rows);
    $out[$name] = ['kebutuhan' => $need, 'energi' => round($al['energy'], 4), 'profil' => array_map(fn($c) => ($c[0] + 1) . ':' . ($c[2] * 100), $al['cells']), 'audit' => $aud['status'], 'viol' => $aud['violations']];
}
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
