<?php
/* T4C: kapasitas redistribusi tidak cukup. php t4c.php <src> <input.json> <out.json>
 * Profil flow PGN recipient row 2..48 = Min PGN Flow + 0,12 MMSCFD (headroom aman 0,02 x GHV_P/GHV_J per row). */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '2G');
require $argv[1] . '/run.php';
$in = pp_normalize_copy(json_decode(file_get_contents($argv[2]), true));
$minF = (float)$in['data3']['modeling']['min_pgn_flow'];
$D = []; for ($r = 0; $r < 48; $r++) $D[] = ['Flow_PGN_RT' => $r === 0 ? 3.0072 : $minF + 0.12];
$res = pp_bs_ff_redistribute($in, [1], 34.82, $minF, $D, []);
$ffQ = 36.0;
$out = ['case' => 'T4C', 'result' => $res,
  'checks' => ['terminal_not_feasible' => empty($res['feasible']), 'deficit_reported' => isset($res['deficit_mmscfd_rows']) && $res['deficit_mmscfd_rows'] > 0,
               'no_fixed_flow_after_returned' => !isset($res['fixed_flow_after']), 'fields' => array_keys($res)]];
file_put_contents($argv[3], json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out['checks']), "\n", json_encode(array_diff_key($res, ['source_rows' => 1])), "\n";
