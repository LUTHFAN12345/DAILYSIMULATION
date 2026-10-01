<?php
/* STG DIHITUNG ULANG SETIAP GTG BERUBAH — bukti per row: STG keluaran = calc_stg(engine) atas GTG blok yang benar-benar
 * memasok uap (load >= min_ccload, HRSG running; G3/G4/G6->S1, G1/G2/G5->S2, G8/G9->S3), kecuali row penahanan start-up
 * STG (Cold/Warm/Hot: STG <= nilai calc, ditahan 0 / dibatasi). php stg_proof.php <merit_dir> <map> <out.json> */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
require '/home/claude/t5/integfreeze/worker_functions.php';
require '/home/claude/t5/v3/scen.php';
[$_, $D, $MAP, $OJ] = $argv;
$R = [];
foreach (file($MAP, FILE_IGNORE_NEW_LINES) as $l) { if (trim($l) === '') continue; [$id, $sc] = explode("\t", $l, 2);
    $in = v3_input($sc); $d3 = $in['data3']; if (function_exists('pp_stg_migrate_3segment')) pp_stg_migrate_3segment($d3);
    foreach (['out_cold' => 'FINAL', 'out_before' => 'SEBELUM_REDISTRIBUSI'] as $sub => $lab) {
        $o = null; foreach (glob("$D/$sub/*.json") as $f) { $j = json_decode(file_get_contents($f), true); if (($j['sc'] ?? '') === $sc) { $o = $j['output']; break; } }
        if (!is_array($o)) continue; $rows = array_values((array)($o['data'] ?? []));
        $n = 0; $exact = 0; $held = 0; $bad = []; $chg = 0; $prev = null;
        foreach ($rows as $k => $r) foreach (['s1', 's2', 's3'] as $s) { if (!isset($d3[$s])) continue; $S = strtoupper($s);
            $feed = []; foreach ((array)($d3[$s]['hrsg'] ?? []) as $h => $g) { $ld = (float)($r[strtoupper($g)] ?? 0); $mcc = $d3[$g]['min_ccload'] ?? null;
                if ($mcc !== null && $ld > 0 && $ld < $mcc - 1e-6) continue; if ($ld > 0) $feed[$g] = $ld; }
            $exp = $feed ? round(calc_stg($d3, $s, $feed), 2) : 0.0; $got = round((float)($r[$S] ?? 0), 2); $n++;
            if (abs($exp - $got) <= 0.011) $exact++;
            elseif ($got < $exp + 0.011) $held++;                        // penahanan start-up STG / HRSG belum running: STG <= calc
            else $bad[] = sprintf('row %d %s: keluaran %.2f > calc %.2f (feed %s)', $k + 1, $S, $got, $exp, json_encode($feed));
            $key = json_encode($feed); if ($prev !== null && isset($prev[$s]) && $prev[$s] !== $key) $chg++; $prev[$s] = $key; }
        $R[$id][$lab] = ['rows_x_stg' => $n, 'stg_equal_calc' => $exact, 'stg_startup_hold_or_hrsg' => $held, 'violations' => count($bad), 'examples' => array_slice($bad, 0, 4), 'gtg_feed_changes' => $chg];
    }
    printf("%-26s FINAL %d/%d tepat, tahan %d, langgar %d | SEBELUM %d/%d, langgar %d\n", $id, $R[$id]['FINAL']['stg_equal_calc'], $R[$id]['FINAL']['rows_x_stg'], $R[$id]['FINAL']['stg_startup_hold_or_hrsg'], $R[$id]['FINAL']['violations'],
        $R[$id]['SEBELUM_REDISTRIBUSI']['stg_equal_calc'] ?? -1, $R[$id]['SEBELUM_REDISTRIBUSI']['rows_x_stg'] ?? -1, $R[$id]['SEBELUM_REDISTRIBUSI']['violations'] ?? -1);
}
file_put_contents($OJ, json_encode($R, JSON_PRETTY_PRINT));
