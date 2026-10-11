<?php /* proof.php <src> <input.json> <output.json> <out.json> : merit proof dari source <src> atas output yang SAMA */
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $d = json_decode(file_get_contents($argv[3]), true); $o = $d['output'] ?? $d;
unset($o['info']['Merit Proof C1-C4 STG'], $o['info']['Proof Binding']);
$mp = pp_v15_merit_proof($in, $o);
file_put_contents($argv[4], json_encode(['merit' => $mp, 'rows' => $o['data']]));
echo json_encode(['status' => $mp['status'], 'stg' => $mp['stg']['status'] ?? null, 'mismatch' => $mp['stg']['mismatch'] ?? null, 'examples' => $mp['stg']['examples'] ?? null, 'C' => array_map(fn($c) => ['f' => $mp[$c]['findings'] ?? null, 'fail' => $mp[$c]['fail'] ?? null], ['C1' => 'C1', 'C2' => 'C2', 'C3' => 'C3', 'C4' => 'C4'])]), "\n";
