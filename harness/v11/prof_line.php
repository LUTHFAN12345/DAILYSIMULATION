<?php
/* sampling profiler job economic_review penuh. php prof_job.php <root> <SC> [prefill SC,...] */
ini_set('memory_limit','3G'); define('PP_LIB_ONLY', true);
$GLOBALS['S'] = []; $GLOBALS['I'] = []; $GLOBALS['ST'] = 0; $GLOBALS['PATH'] = [];
pcntl_async_signals(true);
pcntl_signal(SIGUSR1, function () { if (empty($GLOBALS['PROF_ON'])) return; $GLOBALS['ST']++; $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 200);
  $f1 = ($bt[1]["function"] ?? "?") . "@" . basename($bt[0]["file"] ?? "?") . ":" . ($bt[0]["line"] ?? 0); $GLOBALS['S'][$f1] = ($GLOBALS['S'][$f1] ?? 0) + 1;
  $seen = []; $chain = [];
  for ($i = count($bt) - 1; $i >= 1; $i--) { $f = ($bt[$i]['function'] ?? '?'); if ($f === '{closure}') continue; $chain[] = $f; }
  $key = implode('>', array_slice($chain, 0, 9)); $GLOBALS['PATH'][$key] = ($GLOBALS['PATH'][$key] ?? 0) + 1;
  for ($i = 1; $i < count($bt); $i++) { $f = $bt[$i]['function'] ?? '?'; if (isset($seen[$f])) continue; $seen[$f] = 1; $GLOBALS['I'][$f] = ($GLOBALS['I'][$f] ?? 0) + 1; }
});
$dir=$argv[1]; chdir($dir); require $dir.'/run.php'; require '/home/claude/t5/v3/scen.php';
$run = function ($sc) { $in = v3_input($sc); $in['_context']='plan'; $in['_request_id']='plan-'.substr(md5($sc.microtime()),0,6);
  $m=&$in['data3']['modeling']; $m['time_budget_seconds']=10.0; $m['time_budget_step_seconds']=5.0; $m['time_budget_max_seconds']=max(15.0, 60.0 - PP_SYNC_CLOSING_RESERVE_S); $m['__core_run_budget']=100; unset($m);
  $in = pp_normalize_copy($in); $st = pp_job_start($in, 'economic_review', $in['_request_id'], false); $id = $st['job']['job_id']; $t=microtime(true); pp_job_worker_main($id); return microtime(true)-$t; };
foreach (array_filter(explode(',', $argv[3] ?? '')) as $p) echo "prefill $p ".round($run($p),1)." s\n";
$pid = getmypid(); $killer = proc_open("while kill -USR1 $pid 2>/dev/null; do sleep 0.01; done", [], $pp);
$GLOBALS['PROF_ON'] = true; $w = $run($argv[2]); $GLOBALS['PROF_ON'] = false; proc_terminate($killer);
$tot=max(1,$GLOBALS['ST']); arsort($GLOBALS['S']); arsort($GLOBALS['I']); arsort($GLOBALS['PATH']);
echo "wall ".round($w,1)." samples $tot\n== inclusive\n"; foreach (array_slice($GLOBALS['I'],0,70,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
echo "== self\n"; foreach (array_slice($GLOBALS["S"],0,30,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
echo "== paths\n"; foreach (array_slice($GLOBALS['PATH'],0,40,true) as $l=>$c) printf("%5.1f %s\n",100*$c/$tot,$l);
