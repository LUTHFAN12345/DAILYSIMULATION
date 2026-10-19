<?php
// tdump.php <app> <dump.json> <inv:0|1> <out.json> : run pp_v1523_tighten offline on a dumped (input, out) pair
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$d = json_decode(file_get_contents($argv[2]), true);
if ($argv[3] === '1') $GLOBALS['ppV1527InvSearch'] = true;
$t = microtime(true); pp_tl_clean_globals();
$o = pp_v1523_tighten($d['input'], $d['out']);
$T = $o['info']['Commitment Tightening'] ?? [];
$r = ['s' => round(microtime(true) - $t, 2), 'before' => $T['before'] ?? null, 'after' => $T['after'] ?? null, 'applied' => $T['applied'] ?? null, 'evaluations' => $T['evaluations'] ?? null,
      'rounds' => array_map(fn($x) => ['applied' => $x['applied'] ?? null, 'combined' => $x['combined'] ?? null, 'cands' => $x['candidates'] ?? null, 'improving' => $x['improving'] ?? null, 'after' => $x['after']['econ_cp'] ?? null, 'after_c' => $x['after_combined']['econ_cp'] ?? null], (array)($T['rounds'] ?? []))];
file_put_contents($argv[4], json_encode(['r' => $r, 'data' => $o['data']]));
echo json_encode($r), "\n";
