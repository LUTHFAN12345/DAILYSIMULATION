<?php
// capprobe.php <app> <input.json> <final_out.json> <offunits csv>: optimistic Export capacity bound with off-set units removed all day
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2]), true); $o = json_decode(file_get_contents($argv[3]), true); $o = $o['output'] ?? $o;
$orig = pp_v1523_orig($in); $off = array_map('strtolower', explode(',', $argv[4]));
$X = []; foreach (pp_tl_gt_units() as $u) { $X[$u] = []; for ($r = 1; $r <= 48; $r++) $X[$u][$r] = !in_array($u, $off, true); }
$t = microtime(true); $b = pp_v1525_cap_bound($orig, $o, $X);
echo json_encode(['bad' => $b ? array_slice($b, 0, 6) : null, 'n_bad' => $b ? count($b) : 0, 's' => round(microtime(true) - $t, 3)]), "\n";
