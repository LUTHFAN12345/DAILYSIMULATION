<?php
/* suptest.php <src> : uji unit supersede ber-scope (V15.16). Job RUNNING palsu milik A/tab1, A/tab2, B/tab1, job bersama
 * (milik A/tab1 tetapi dipantau run B). Run baru dari A/tab1 hanya boleh membatalkan job A/tab1 yang tidak dipantau run lain. */
define('PP_LIB_ONLY', 1); require $argv[1] . '/run.php';
$root = pp_job_root(); $mk = function (string $id, string $uid, string $tab, string $rid, array $subs = []) use ($root) {
    $d = $root . '/' . $id; @mkdir($d . '/subs', 0777, true);
    file_put_contents($d . '/job.json', json_encode(['schema' => 'co12-async-job-v1', 'job_id' => $id, 'kind' => 'economic_review', 'input_hash' => str_repeat('0', 64),
        'request_id' => $rid, 'status' => 'RUNNING', 'owner_uid' => $uid, 'owner_tab' => $tab, 'created_at' => date('c'), 'updated_at' => date('c')]));
    foreach ($subs as $s) touch($d . '/subs/' . $s);
};
$ids = ['economic_review-suptA1' => ['uA', 't1', 'plan-1-a'], 'economic_review-suptA2' => ['uA', 't2', 'plan-1-a2'], 'economic_review-suptB1' => ['uB', 't1', 'plan-1-b'],
        'economic_review-suptSH' => ['uA', 't1', 'plan-2-a', ['plan-2-a', 'plan-7-b']]];
foreach ($ids as $id => $v) $mk($id, $v[0], $v[1], $v[2], $v[3] ?? []);
$_GET['uid'] = 'uA'; $_GET['tab'] = 't1';
$in = json_decode(file_get_contents($argv[2]), true); $in['_context'] = 'plan';
$done = pp_v3_supersede($in); $res = [];
foreach (array_keys($ids) as $id) { $j = pp_job_read($id); $res[$id] = ($j['status'] ?? '?') . ':' . ($j['current_step'] ?? ''); }
$exp = ['economic_review-suptA1' => 'CANCELLED', 'economic_review-suptA2' => 'RUNNING', 'economic_review-suptB1' => 'RUNNING', 'economic_review-suptSH' => 'RUNNING'];
$ok = true; foreach ($exp as $id => $e) if (strpos($res[$id], $e) !== 0) $ok = false;
foreach (array_keys($ids) as $id) { array_map('unlink', glob("$root/$id/subs/*") ?: []); @rmdir("$root/$id/subs"); array_map('unlink', glob("$root/$id/*") ?: []); @rmdir("$root/$id"); }
echo json_encode(['superseded' => $done, 'status' => $res, 'expect' => $exp, 'result' => $ok ? 'PASS' : 'FAIL'], JSON_PRETTY_PRINT) . "\n";
