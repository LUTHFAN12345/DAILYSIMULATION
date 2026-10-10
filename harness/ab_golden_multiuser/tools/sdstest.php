<?php
/* sdstest.php <dir_store_copy> : uji unit datastore (backend dari env PP_SDS_BACKEND) */
require $argv[1] . '/saved_data_store.php';
$R = ['backend' => sds_backend()]; $in = json_decode(file_get_contents('/home/claude/ab/payloads/P0.json'), true);
$cA = ['uid' => 'uA', 'tab' => 't1', 'project' => 'pX', 'run' => 'r1']; $cB = ['uid' => 'uB', 'tab' => 't9', 'project' => 'pX', 'run' => 'r2'];
$a1 = sds_commit('AUTOSAVE_RUN', $in, null, $cA); $a2 = sds_commit('SAVE', $in, null, $cA); $b1 = sds_commit('SAVE', $in + ['_x' => 1], null, $cB);
$R['versions_A'] = [$a1['version'], $a2['version']]; $R['version_B'] = $b1['version'];
$R['list_A_owner_only'] = !array_filter(sds_list('uA'), function ($r) { return $r['user_or_session_id'] !== 'uA'; });
$R['B_cannot_load_A'] = sds_load('uB', $a2['record_id']) === null; $R['A_load_own'] = is_array(sds_load('uA', $a2['record_id']));
$R['latest_A'] = (sds_latest_input('uA')['record_id'] ?? null) === $a2['record_id'];
$w = null; $R['meta'] = sds_update_meta('uA', $a2['record_id'], ['label' => 'x'], $w); $R['delete_needs_confirm'] = !sds_delete('uA', $a1['record_id'], 'no', $w);
$R['delete'] = sds_delete('uA', $a1['record_id'], 'DELETE', $w);
// paralel: 20 proses menulis bersamaan ke scope yang sama -> versi unik 1..20 (lock/transaksi)
$pids = []; for ($i = 0; $i < 20; $i++) { $pid = pcntl_fork(); if ($pid === 0) { sds_commit('SAVE', $in, null, ['uid' => 'uP', 'tab' => 't', 'project' => 'pP', 'run' => 'r' . $i]); exit(0); } $pids[] = $pid; }
foreach ($pids as $p) pcntl_waitpid($p, $st);
$vs = array_map(function ($r) { return (int)$r['version']; }, sds_list('uP', ['limit' => 100])); sort($vs); $R['parallel_versions_unique'] = $vs === range(1, 20);
$R['integrity_ok'] = sds_integrity()['ok'];
if (sds_backend() === 'json-lock-atomic') { $f = glob(sds_root() . '/json/uB/*/v*.json')[0]; file_put_contents($f, '{broken'); $ig = sds_integrity(); $R['corrupt_quarantined'] = count($ig['quarantined']) === 1 && is_file($f . '.corrupt'); }
else { $pdo = sds_pdo(); $pdo->exec("UPDATE records SET input_payload_json='{}' WHERE user_or_session_id='uB'"); $ig = sds_integrity(); $R['corrupt_detected'] = $ig['hash_mismatch'] !== []; }
echo json_encode($R) . "\n";
