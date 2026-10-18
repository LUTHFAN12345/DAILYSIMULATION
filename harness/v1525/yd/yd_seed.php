<?php
/* yd_seed.php <app> <base_input.json> <base_output.json> : isi DB uji (env DB_*) dengan report kemarin untuk uji Check Yesterday Data.
 * Output sintetis dibentuk dari hasil engine nyata (A0 FASTEST_FINAL) lalu row akhir dimodifikasi; final guard dinonaktifkan HANYA di proses seed
 * ini (PP_REPORT_FINAL_GUARD=0) karena output sintetis tidak membawa proof binding. Tidak dipakai di runtime. */
[$_, $app, $inF, $outF] = $argv; define('PP_LIB_ONLY', 1); chdir($app); require $app . '/run.php'; putenv('PP_REPORT_FINAL_GUARD=0');
$IN = json_decode(file_get_contents($inF), true); $OUT = json_decode(file_get_contents($outF), true)['output'];
$strip = function (array $in) { foreach (array_keys($in) as $k) if ($k[0] === '_') unset($in[$k]); return $in; };
$put = function (string $proj, string $type, string $date, string $name, array $in, array $out, string $uid = 'yd_user') use ($strip) {
    $in['data3']['modeling']['plan_date'] = $date; $in['data3']['modeling']['name_plan'] = $name;
    $e = ['report_type' => $type, 'role' => $type === 'MONITORING' ? 'monitoring' : 'plan', 'plan_date' => $date, 'plan_name' => $name, 'remark' => 'yd-seed',
          'input_json' => json_encode($strip($in), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'output_json' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
          'summary' => ['cp' => $out['info']['Cost Production (USD/MWh)'] ?? null]];
    $r = rs_report_save(['request_id' => 'r' . bin2hex(random_bytes(6)), 'project_key' => $proj, 'run_key' => 'run' . bin2hex(random_bytes(4)), 'mode' => 'FASTEST', 'entries' => [$e]], ['uid' => $uid, 'tab' => 'tab1', 'project' => $proj]);
    if (empty($r['ok'])) { fwrite(STDERR, json_encode($r) . "\n"); exit(1); }
    return $r; };
/* varian output: blok S1 sedang startup pada 00:00 (G6 berbeban 5 row terakhir, S1 = 0) */
$startup = function (array $o, string $g, string $s, array $caps) { $n = count($caps); $fd = ['S1' => ['G3', 'G4', 'G6'], 'S2' => ['G1', 'G2', 'G5'], 'S3' => ['G8', 'G9']][$s];
    for ($k = 0; $k < 48; $k++) { $o['data'][$k][$s] = $k >= 48 - $n ? 0.0 : (float)$o['data'][$k][$s]; }
    foreach ($fd as $u) for ($k = 48 - $n; $k < 48; $k++) $o['data'][$k][$u] = 0.0;
    for ($k = 0; $k < 48 - $n; $k++) $o['data'][$k][$g] = 0.0;
    foreach ($caps as $i => $v) $o['data'][48 - $n + $i][$g] = (float)$v; return $o; };
$allStop = function (array $o) { foreach ($o['data'] as $k => $r) foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'] as $u) $o['data'][$k][$u] = $k === 47 ? 0.0 : $r[$u]; return $o; };
$P = 'default'; $log = [];
$log[] = $put($P, 'PLANNING', '2026-10-04', 'Plan A', $IN, $OUT);                                  // Daily Plan v1
$log[] = $put($P, 'PLANNING', '2026-10-04', 'Plan A', $IN, $startup($OUT, 'G6', 'S1', [5, 15, 20]));   // Daily Plan v2 (lebih baru)
$log[] = $put($P, 'MONITORING', '2026-10-04', 'Monitoring A', $IN, $startup($OUT, 'G6', 'S1', [5, 15, 20, 20, 20]));   // Monitoring menang
$log[] = $put($P, 'PLANNING', '2026-09-30', 'Plan Sep', $IN, $OUT);                               // month-end, hanya Daily Plan
$log[] = $put($P, 'PLANNING', '2026-09-30', 'Plan Sep', $IN, $startup($OUT, 'G1', 'S2', [5, 15, 20, 20]));  // month-end Daily Plan v2 (terbaru dipakai)
$log[] = $put($P, 'MONITORING', '2025-12-31', 'Mon YE', $IN, $allStop($OUT));                     // year-end, hanya Monitoring
$log[] = $put($P, 'MONITORING', '2026-10-14', 'Mon Corrupt', $IN, $OUT);                          // akan dirusak hash-nya
$log[] = $put($P, 'PLANNING', '2026-10-14', 'Plan OK', $IN, $startup($OUT, 'G6', 'S1', [5, 15]));
$log[] = $put('other_project', 'MONITORING', '2026-10-19', 'Other', $IN, $OUT, 'stranger');      // project lain (tidak boleh terlihat)
$p = rs_pdo(); $q = $p->prepare("UPDATE simulation_results r JOIN report_entries e ON e.result_id = r.id SET r.result_json = CONCAT(r.result_json, ' ') WHERE e.plan_name = 'Mon Corrupt'"); $q->execute();
echo json_encode(['ok' => true, 'saved' => count($log), 'corrupted_rows' => $q->rowCount()]), "\n";
