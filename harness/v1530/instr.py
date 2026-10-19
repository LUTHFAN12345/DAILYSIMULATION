import sys
p = sys.argv[1]; s = open(p).read()
old = "function pp_run_simulation_once_raw(array $input): array {"
assert s.count(old) == 1
w = '''function pp_run_simulation_once_raw(array $input): array { if (($pf = (string)getenv('PP_SIMPROF')) === '') return pp_run_simulation_once_raw__i($input);
    $t = microtime(true); $o = pp_run_simulation_once_raw__i($input); $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60); $ch = [];
    foreach ($bt as $f) { $fn = $f['function'] ?? ''; if (strpos($fn, 'pp_') === 0 && strpos($fn, 'pp_run_simulation') !== 0) $ch[] = $fn; }
    $GLOBALS['__simprof'][] = ['h' => md5(serialize($input)), 'hd' => md5(json_encode($o['data'] ?? null)), 's' => round(microtime(true) - $t, 4), 'c' => $ch, 'rows' => count((array)($input['data1'] ?? []))];
    if (empty($GLOBALS['__simprof_reg'])) { $GLOBALS['__simprof_reg'] = 1; register_shutdown_function(function () use ($pf) { @file_put_contents($pf, json_encode($GLOBALS['__simprof'] ?? [])); }); }
    return $o; }
function pp_run_simulation_once_raw__i(array $input): array {'''
s = s.replace(old, w); open(p, 'w').write(s)
