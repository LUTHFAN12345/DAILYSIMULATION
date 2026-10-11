<?php
/* jobval.php <src> <jobdir> : validasi ulang hasil job (input.json + result.json) dan tampilkan pelanggaran */
define('PP_LIB_ONLY', 1); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2] . '/input.json'), true); $r = json_decode(file_get_contents($argv[2] . '/result.json'), true); $o = $r['output'] ?? $r;
$V = pp_validate_hard_constraints(pp_normalize_copy($in), $o);
echo json_encode(['status' => $V['status'], 'viol' => array_slice($V['violations'], 0, 10), 'mode' => $o['info']['Run Status']['mode'] ?? null, 'act' => $in['data3']['modeling']['gas_shortage_action'] ?? null,
  'fdm' => $in['data3']['modeling']['__fuel_decision_mode'] ?? null, 'lim' => $in['data3']['modeling']['distillate_user_limit_litres'] ?? null, 'ieadj' => $in['data3']['modeling']['ie_adjustments'] ?? null,
  'dca' => $o['info']['Distillate Continuity Audit']['status'] ?? null, 'dclose' => $o['info']['Distillate Discrete Closing']['status'] ?? null, 'cp' => $o['info']['Cost Production (USD/MWh)'] ?? null], JSON_PRETTY_PRINT) . "\n";
