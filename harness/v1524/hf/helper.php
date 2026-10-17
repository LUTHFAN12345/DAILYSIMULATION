<?php // helper.php <app> <job> <slot> : pekerja pembantu (setara mode=job_help) untuk uji
$app=$argv[1]; define('PP_LIB_ONLY',1); chdir($app); require $app.'/run.php';
if (!defined('PP_INPROC_JOB')) define('PP_INPROC_JOB', true);
$GLOBALS['__pp_async_worker'] = true; $GLOBALS['__pp_async_worker_ceiling'] = 1800.0;
echo json_encode(pp_v4_helper_main($argv[2], (int)$argv[3])),"\n";
