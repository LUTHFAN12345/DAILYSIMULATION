<?php
// V15.31 unit test resolver Actual Gas kumulatif + lock Time Passed: php ag_cum_unit.php <app>
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$E = fn() => array_fill(0, 48, ''); $ok = 0; $fail = 0; $res = [];
$t = function (string $n, bool $c, $d = null) use (&$ok, &$fail, &$res) { $c ? $ok++ : $fail++; $res[] = ['test' => $n, 'pass' => $c, 'detail' => $d]; echo ($c ? 'PASS ' : 'FAIL ') . $n . ($d !== null ? ' ' . json_encode($d) : '') . "\n"; };
// row index 0 = 00:30, 1 = 01:00, 2 = 01:30, 3 = 02:00 ... (sel jam disimpan di indeks genap pasangan)
$p = $E(); $p[0] = 3.0; $p[2] = 4.5;            // PGN 3,0 jam 00:30-01:00, lalu 4,5 jam 01:30-02:00
$j = $E(); $j[0] = 1.9;                         // Jababeka 1,9 di 01:00, tidak ada update
$mm = $E(); $mm[6] = 0.7;                       // MM2100 update terakhir 03:30-04:00 (jam ke-4)
$m = ['actual_gas_snapshots' => ['pgn' => $p, 'ffj' => $j, 'ffm' => $mm]];
$C = pp_actual_gas_canonical($m);
$t('09 PGN 3,0 lalu 4,5 -> kanonik 4,5 (bukan 7,5)', $C['suppliers']['pgn']['value'] === 4.5, $C['suppliers']['pgn']);
$t('10 snapshot 3,0 tersimpan sebagai historis diabaikan', count($C['suppliers']['pgn']['ignored_historical']) === 1 && $C['suppliers']['pgn']['ignored_historical'][0]['value'] === 3.0);
$t('Jababeka 1,9 tanpa update -> 1,9', $C['suppliers']['ffj']['value'] === 1.9);
$t('13 MM2100 latest row 7 (03:30) independen dari supplier lain', $C['suppliers']['ffm']['latest_row'] === 7 && $C['suppliers']['ffm']['covered_rows'] === 8);
$t('03 cutoff = row actual terbaru seluruh supplier (8)', $C['cutoff_row'] === 8, $C['cutoff_row']);
$z = $E(); $z[4] = 0; $C0 = pp_actual_gas_canonical(['actual_gas_snapshots' => ['pgn' => $z, 'ffj' => $E(), 'ffm' => $E()]]);
$t('15 nol = nilai sah, kosong = tanpa data', $C0['suppliers']['pgn']['value'] === 0.0 && $C0['suppliers']['ffj']['value'] === null && $C0['cutoff_row'] === 6);
$in = ['data3' => ['modeling' => $m]]; pp_actual_gas_apply_snapshots($in); $a = $in['data3']['modeling']['actual_pgn_total'];
$sum = 0.0; foreach ($a as $v) if ($v !== '') $sum += $v;
$t('21 array engine turunan: jumlah = kanonik, jam sesudah cutoff kosong', abs($sum - 4.5) < 1e-12 && $a[4] === '' && $a[0] === 2.25 && $a[2] === 2.25, $a);
$in2 = $in; pp_actual_gas_apply_snapshots($in2); $t('resolver idempoten', $in2 === $in);
$p2 = $E(); $p2[2] = 4.5; $inB = ['data3' => ['modeling' => ['actual_gas_snapshots' => ['pgn' => $p2, 'ffj' => $j, 'ffm' => $mm]]]]; pp_actual_gas_apply_snapshots($inB);
$t('snapshot lama tidak memengaruhi array engine', $inB['data3']['modeling']['actual_pgn_total'] === $in['data3']['modeling']['actual_pgn_total']);
// lock
$prev = ['data3' => ['modeling' => ['plan_date' => '2026-10-05', 'monitoring_daily_plan' => ['enabled' => true, 'time_passed_cutoff_row' => 4], 'actual_gas_snapshots' => ['pgn' => $p, 'ffj' => $j, 'ffm' => $E()]]]];
$new = $prev; $new['data3']['modeling']['actual_gas_snapshots']['pgn'][0] = 3.5;
$t('08 backend menolak edit Actual Gas row Y', count(pp_actual_gas_lock_violations($prev, $new)) === 1, pp_actual_gas_lock_violations($prev, $new));
$new2 = $prev; $new2['data3']['modeling']['actual_gas_snapshots']['pgn'][0] = '';
$t('11 hapus nilai row Y ditolak', count(pp_actual_gas_lock_violations($prev, $new2)) === 1);
$new3 = $new2; $new3['data3']['modeling']['monitoring_daily_plan']['time_passed_cutoff_row'] = 0;
$t('11 sesudah TIME PASSED resmi = N, edit diizinkan + kanonik dihitung ulang', pp_actual_gas_lock_violations($prev, $new3) === [] && pp_actual_gas_canonical($new3['data3']['modeling'])['suppliers']['pgn']['value'] === 4.5);
$new4 = $prev; $new4['data3']['modeling']['actual_gas_snapshots']['pgn'][4] = 6.0; $new4['data3']['modeling']['monitoring_daily_plan']['time_passed_cutoff_row'] = 6;
$t('04 row sesudah cutoff boleh diisi (snapshot baru)', pp_actual_gas_lock_violations($prev, $new4) === []);
$new5 = $prev; $new5['data3']['modeling']['actual_gas_snapshots']['ffj'][0] = 2.0;
$t('08 Jababeka row Y juga terkunci', count(pp_actual_gas_lock_violations($prev, $new5)) === 1);
// hash: snapshot historis tidak mengubah kunci basis bila kanonik sama
$hb = fn($x) => pp_v1517_ie_base_key(pp_v13f_clean(pp_v1517_engine_input($x)));
$base = json_decode(file_get_contents('/tmp/w/reg/A0_actual.json'), true);
$bA = $base; $bA['data3']['modeling']['actual_gas_snapshots'] = ['pgn' => $p, 'ffj' => $E(), 'ffm' => $E()]; pp_actual_gas_apply_snapshots($bA);
$bB = $base; $bB['data3']['modeling']['actual_gas_snapshots'] = ['pgn' => $p2, 'ffj' => $E(), 'ffm' => $E()]; pp_actual_gas_apply_snapshots($bB);
$t('24 state hash: snapshot historis tanpa efek bila kanonik sama', pp_v3_state_hash($bA) === pp_v3_state_hash($bB));
$bC = $bA; $bC['data3']['modeling']['monitoring_daily_plan'] = ['enabled' => true, 'time_passed_cutoff_row' => 4, 'locked_rows' => []];
$bD = $bA; $bD['data3']['modeling']['monitoring_daily_plan'] = ['enabled' => true, 'time_passed_cutoff_row' => 8, 'locked_rows' => ['0' => ['row' => 1]]];
$t('kunci basis warm tidak berubah karena cutoff Time Passed maju', $hb($bC) === $hb($bD) && pp_v1522_struct_hash(pp_v13f_clean($bC)) === pp_v1522_struct_hash(pp_v13f_clean($bD)));
echo json_encode(['pass' => $ok, 'fail' => $fail]), "\n";
file_put_contents(__DIR__ . '/evidence/ag_cum_unit.json', json_encode(['pass' => $ok, 'fail' => $fail, 'tests' => $res], JSON_PRETTY_PRINT));
