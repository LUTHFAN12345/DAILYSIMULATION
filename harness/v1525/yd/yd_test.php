<?php
/* yd_test.php <app> <outfile.json> : uji backend Check Yesterday Data terhadap DB hasil yd_seed.php (env DB_*). */
[$_, $app, $outF] = $argv; define('PP_LIB_ONLY', 1); chdir($app); require $app . '/run.php';
$res = []; $rec = function ($id, $desc, $ok, $d) use (&$res) { $res[] = ['id' => $id, 'desc' => $desc, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $d]; echo ($ok ? 'PASS ' : 'FAIL ') . "$id $desc\n"; };
$q = function ($d, $p = 'default') { return rs_yesterday_data($p, $d); };
$r = $q('2026-10-05'); $s = $r['source'] ?? [];
$rec('YD-BE-MON', 'Monitoring Daily Plan diutamakan di atas Daily Plan (tanggal kemarin 2026-10-04)', ($r['status'] ?? '') === 'FOUND' && ($s['source_type'] ?? '') === 'MONITORING' && ($r['yesterday'] ?? '') === '2026-10-04', $s);
$rec('YD-BE-SUCC', 'G6 Start Up CC, kelanjutan S1 elapsed 5 row', ($r['units']['G6']['status'] ?? '') === 'Start Up CC' && ($r['startup_continuation']['s1']['elapsed_rows'] ?? -1) === 5, [$r['units']['G6'] ?? null, $r['startup_continuation'] ?? null]);
$r = $q('2026-10-01'); $s = $r['source'] ?? [];
$rec('YD-BE-MONTHEND', 'Lintas bulan: 2026-10-01 -> 2026-09-30, hanya Daily Plan, versi terbaru (v2) dipakai', ($r['yesterday'] ?? '') === '2026-09-30' && ($s['source_type'] ?? '') === 'PLANNING' && (int)($s['version_no'] ?? 0) === 2 && ($r['startup_continuation']['s2']['elapsed_rows'] ?? -1) === 4, [$s, $r['startup_continuation'] ?? null]);
$r = $q('2026-01-01'); $s = $r['source'] ?? [];
$rec('YD-BE-YEAREND', 'Lintas tahun: 2026-01-01 -> 2025-12-31 Monitoring, semua unit Stop', ($r['yesterday'] ?? '') === '2025-12-31' && ($s['source_type'] ?? '') === 'MONITORING' && !array_filter($r['units'] ?? ['x' => ['status' => 'Running']], function ($u) { return $u['status'] !== 'Stop'; }), $s);
$r = $q('2026-10-10');
$rec('YD-BE-NODATA', 'Tanpa report kemarin -> NO_DATA', ($r['status'] ?? '') === 'NO_DATA' && !isset($r['units']), $r);
$r = $q('2026-10-15'); $s = $r['source'] ?? [];
$rec('YD-BE-CORRUPT', 'Monitoring rusak (hash) dilewati dengan alasan, fallback Daily Plan', ($r['status'] ?? '') === 'FOUND_WITH_SKIPPED' && ($s['source_type'] ?? '') === 'PLANNING' && strpos(json_encode($r['skipped'] ?? []), 'RESULT_HASH_MISMATCH') !== false, [$s, $r['skipped'] ?? null]);
$r = $q('2026-10-20');
$rec('YD-BE-PROJECT', 'Report project lain tidak terlihat', ($r['status'] ?? '') === 'NO_DATA', $r['status'] ?? null);
$r = $q('2026-10-20', 'other_project');
$rec('YD-BE-PROJECT-OWN', 'Project pemilik melihat reportnya', ($r['status'] ?? '') === 'FOUND', $r['status'] ?? null);
$r = $q('bad');
$rec('YD-BE-BADDATE', 'Tanggal tidak valid -> ERROR tanpa query', empty($r['ok']) && ($r['error'] ?? '') === 'PLAN_DATE_INVALID', $r);
file_put_contents($outF, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo 'TOTAL ' . count($res) . ' PASS ' . count(array_filter($res, function ($x) { return $x['status'] === 'PASS'; })) . "\n";
