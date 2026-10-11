<?php define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true);
foreach (array_slice($argv, 3) as $f) { $x = json_decode(file_get_contents($f), true); $o = ['data' => $x['rows'], 'info' => ['Merit Proof C1-C4 STG' => $x['merit']]];
  $b = pp_v1517_proof_binding($in, $o); echo basename($f), ' ', json_encode(['candidate_hash' => $b['candidate_hash'], 'commitment_hash' => $b['commitment_hash'], 'fuel_hash' => $b['fuel_hash'], 'payload_hash' => $b['payload_hash'], 'proof_hash' => $b['proof_hash'], 'proof_status' => $b['proof_status']]), "\n"; }
