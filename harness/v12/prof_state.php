<?php
/* V12 sampling profiler: pipeline exact (pp_run_simulation) satu state, tanpa pembantu.
 * php prof_state.php <root> <SC> [maxdepth-lines] ; env STOPAT=<fase> tidak dipakai */
ini_set('memory_limit','4G'); define('PP_LIB_ONLY', true);
$GLOBALS['S'] = []; $GLOBALS['I'] = []; $GLOBALS['ST'] = 0; $GLOBALS['LN']=[]; $GLOBALS['PH']=[];
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, function () { $GLOBALS['ST']++; $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 200);
  $f1 = $bt[1]['function'] ?? '?'; $GLOBALS['S'][$f1] = ($GLOBALS['S'][$f1] ?? 0) + 1;
  $ln = ($bt[1]['function'] ?? '?').':'.($bt[0]['line'] ?? 0); $GLOBALS['LN'][$ln] = ($GLOBALS['LN'][$ln] ?? 0) + 1;
  $seen = []; $path = [];
  for ($i = count($bt) - 1; $i >= 1; $i--) { $f = $bt[$i]['function'] ?? '?'; $path[] = $f . '@' . ($bt[$i - 1]['line'] ?? 0); }
  for ($i = 1; $i < count($bt); $i++) { $f = $bt[$i]['function'] ?? '?'; if (isset($seen[$f])) continue; $seen[$f] = 1; $GLOBALS['I'][$f] = ($GLOBALS['I'][$f] ?? 0) + 1; }
  /* jalur panggilan (4 tingkat di bawah pp_run_simulation) */
  $k = implode(' > ', array_slice($path, 0, (int)(getenv('DEPTH') ?: 9))); $GLOBALS['PH'][$k] = ($GLOBALS['PH'][$k] ?? 0) + 1;
});
$dir=$argv[1]; chdir($dir); require $dir.'/run.php'; require '/home/claude/t5/v3/scen.php';
$in = pp_econ_job_sim_input(pp_normalize_copy(v3_input($argv[2])), 900.0);
pp_budget_start(900.0, true, true);
$pid = getmypid(); $killer = proc_open("while kill -USR1 $pid 2>/dev/null; do sleep 0.004; done", [], $pp);
$t=microtime(true);
$o = pp_run_simulation($in);
$w=microtime(true)-$t; proc_terminate($killer);
$tot=max(1,$GLOBALS['ST']); arsort($GLOBALS['S']); arsort($GLOBALS['I']); arsort($GLOBALS['LN']); arsort($GLOBALS['PH']);
echo "wall $w samples $tot cp ".($o['info']['Cost Production (USD/MWh)'] ?? '-')." core ".json_encode($o['info']['Run Status']['core_simulations'] ?? null)."\n";
foreach ((array)($o['info']['Run Status']['phase_timeline'] ?? []) as $p) echo "  ", $p['phase'], ' ', $p['at_s'], ' core ', $p['core_runs'], "\n";
echo "== inclusive\n"; foreach (array_slice($GLOBALS['I'],0,70,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
echo "== self\n"; foreach (array_slice($GLOBALS['S'],0,30,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
echo "== paths\n"; foreach (array_slice($GLOBALS['PH'],0,40,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
echo "== lines\n"; foreach (array_slice($GLOBALS['LN'],0,60,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
