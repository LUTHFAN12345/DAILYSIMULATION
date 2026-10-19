<?php
// tdump2.php <app> <dumpN.json> <keep-globals csv|ALL|NONE> : replay tighten with dumped globals
define('PP_LIB_ONLY', 1); chdir($argv[1]); require $argv[1] . '/run.php';
$d = json_decode(file_get_contents($argv[2]), true); $keep = $argv[3];
pp_tl_clean_globals();
foreach ((array)$d['g'] as $k => $v) { if ($k === 'ppV1523DumpN') continue; if ($keep === 'ALL' || in_array($k, explode(',', $keep), true)) $GLOBALS[$k] = $v; }
if (!empty($d['inv'])) $GLOBALS['ppV1527InvSearch'] = true;
$o = pp_v1523_tighten($d['input'], $d['out']); $T = $o['info']['Commitment Tightening'] ?? [];
echo $keep, ' after=', $T['after']['econ_cp'] ?? 'null', ' applied=', json_encode($T['applied'] ?? null), ' evals=', $T['evaluations'] ?? '', "\n";
