<?php
define('PP_LIB_ONLY', 1); ini_set('memory_limit', '3G'); @ini_set('max_execution_time', '0');
require $argv[1] . '/run.php';
$in = json_decode(file_get_contents($argv[2] . '/input.json'), true); $in = $in['input'] ?? $in;
$d = json_decode(file_get_contents($argv[2] . '/result.json'), true); $o = $d['output'];
$orig = pp_normalize_copy($in); foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0) unset($orig['data3']['modeling'][$mk]);
echo "has_audit_in_info=", isset($o['info']['Headroom Priority Audit']) ? 1 : 0, "\n";
$Hs = (array)($o['info']['Headroom Priority Audit'] ?? []); $Hf = pp_v5_headroom_priority_audit($orig, $o, true);
$pr = []; foreach ((array)($o['info']['Fastest Unit Priority Fix']['proofs'] ?? []) as $p) $pr[$p['row'] . '#' . strtoupper($p['unit']) . '#' . strtoupper($p['higher'])] = $p['reason'];
foreach (['stored' => $Hs, 'fresh' => $Hf] as $nm => $H) { echo "== $nm\n";
  foreach ((array)($H['flags'] ?? []) as $f) if (($f['type'] ?? '') === 'LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM' && empty($f['resolved'])) {
    $k = $f['row'] . '#' . strtoupper($f['unit']) . '#' . strtoupper($f['higher_unit']);
    if (!isset($pr[$k])) echo " UNPROVEN row=", $f['row'], " ", $f['unit'], "(", $f['unit_mw'] ?? '', ")->", $f['higher_unit'], " hd=", $f['higher_headroom_mw'] ?? '', " shift=", $f['shiftable_mw'] ?? '', "\n"; } }
$r = $o['data']; foreach ([1, 13, 14, 18, 19, 20, 23] as $k) { echo "row", $k + 1, ": "; foreach (['G1','G2','G4','G6','G8','G9'] as $u) echo $u, "=", $r[$k][$u] ?? 0, " "; echo "\n"; }
