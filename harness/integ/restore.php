<?php
function restore($root, $snap) {
    exec('rm -rf ' . escapeshellarg($root . '/jobs') . ' && cp -a ' . escapeshellarg($snap) . ' ' . escapeshellarg($root . '/jobs'));
    $fp = trim((string)shell_exec('cd ' . escapeshellarg($root) . ' && /usr/local/bin/php74 -r \'define("PP_LIB_ONLY",1); require "run.php"; echo "FP=".pp_engine_fingerprint();\' 2>/dev/null | grep -o "FP=[0-9a-f]*" | cut -c4-'));
    foreach (glob($root . '/jobs/_final/*.json') as $f) { $d = json_decode(file_get_contents($f), true); if (isset($d['v3'])) { $d['v3']['engine'] = $fp; file_put_contents($f, json_encode($d)); } elseif (substr($f, -12) === '.v10lib.json' && is_array($d)) { $d['engine'] = $fp; file_put_contents($f, json_encode($d)); } }
    copy('/home/claude/t5/fixtures/input_actual.json', $root . '/input_data.json');
}

restore($argv[1], $argv[2]);
