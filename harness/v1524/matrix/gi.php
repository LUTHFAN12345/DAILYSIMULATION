<?php
/* Grup I (Save/Autosave/Report/Monitoring/Versioning/Delete/MySQL) + H15/H16 — dijalankan pada salinan aplikasi dengan MySQL uji (env DB_*).
 * php gi.php <app> <A0_run.json> <A0_input.json> <fix_run.json> <fix_input.json> <out.json> */
[$_, $app, $runA, $inA, $runF, $inF, $outF] = $argv;
define('PP_LIB_ONLY', 1); chdir($app); require $app . '/run.php';
$res = []; $rec = function (string $id, string $title, bool $ok, $detail) use (&$res) { $res[] = ['id' => $id, 'title' => $title, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail]; echo ($ok ? 'PASS ' : 'FAIL ') . "$id $title\n"; };
$RA = json_decode(file_get_contents($runA), true); $IA = json_decode(file_get_contents($inA), true);
$RF = json_decode(file_get_contents($runF), true); $IF = json_decode(file_get_contents($inF), true);
$outOf = function (array $r) { $o = $r['output']; $o['publish_allowed'] = $r['publish_allowed'] ?? null; return $o; };
$strip = function (array $in) { foreach (array_keys($in) as $k) if ($k[0] === '_') unset($in[$k]); unset($in['data3']['modeling']['report_planning']); return $in; };
$proj = 'mxtest_' . bin2hex(random_bytes(3));
$entry = function (array $in, array $out, string $name, string $type = 'PLANNING') use ($strip) {
    return ['report_type' => $type, 'role' => $type === 'MONITORING' ? 'monitoring' : 'plan', 'plan_date' => '2026-10-05', 'plan_name' => $name, 'remark' => 'mx',
            'input_json' => json_encode($strip($in), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'output_json' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'summary' => ['cp' => $out['info']['Cost Production (USD/MWh)'] ?? null]]; };
$save = function (array $entries, string $uid, ?string $rid = null) use ($proj) { return rs_report_save(['request_id' => $rid ?? ('r' . bin2hex(random_bytes(6))), 'project_key' => $proj, 'run_key' => 'run' . bin2hex(random_bytes(4)), 'mode' => 'FASTEST', 'entries' => $entries], ['uid' => $uid, 'tab' => 'tab1', 'project' => $proj, 'run' => '']); };
$st = rs_status(); if (empty($st['ok'])) { $rec('I-PRE', 'MySQL store aktif', false, $st); file_put_contents($outF, json_encode($res, JSON_PRETTY_PRINT)); exit(1); }

/* I1 Run selalu autosave input UI terbaru */
$uid = 'mxu_' . bin2hex(random_bytes(3)); $in1 = $IA; $in1['_uid'] = $uid; $in1['_tab'] = 'tab1'; $in1['data3']['modeling']['note'] = 'autosave-1'; $a1 = pp_v3_autosave($in1);
$in2 = $in1; $in2['data3']['modeling']['note'] = 'autosave-2'; $a2 = pp_v3_autosave($in2); $lat = sds_latest_input($uid);
$rec('I01', 'Run selalu autosave input UI terbaru', $a1['ok'] && $a2['ok'] && ($lat['input']['data3']['modeling']['note'] ?? '') === 'autosave-2', ['a1' => $a1, 'a2' => $a2, 'latest_note' => $lat['input']['data3']['modeling']['note'] ?? null]);
/* I2 nilai false/empty membersihkan state lama */
$in3 = $in2; $in3['data3']['modeling']['unit_fix_load'] = []; $in3['data3']['modeling']['block_required_user']['Block 1'] = false; unset($in3['data3']['modeling']['stop_mode']['g6']); pp_v3_autosave($in3); $lat = sds_latest_input($uid);
$m = $lat['input']['data3']['modeling'];
$rec('I02', 'Nilai false/empty membersihkan state lama', $m['unit_fix_load'] === [] && $m['block_required_user']['Block 1'] === false && !isset($m['stop_mode']['g6']), ['fix' => $m['unit_fix_load'], 'b1' => $m['block_required_user']['Block 1'], 'stop_g6' => $m['stop_mode']['g6'] ?? null]);
/* I3-I5 state lama tidak terbawa ke Run baru: payload engine dinormalisasi dari input saja (semantik ulang) */
$sem = function (array $in) { pp_v1518_semantics($in); return $in['data3']['modeling']; };
$old = $IA; $old['data3']['modeling']['stop_mode']['g5'] = ['mode' => 'stop_at', 'at' => '12:00']; $old['data3']['modeling']['block_required_user']['Block 1'] = true; $old['data3']['modeling']['required_mode']['g5'] = ['mode' => 'start_at', 'at' => '08:00'];
$sOld = $sem($old); $sNew = $sem($IA);
$rec('I03', 'Stop lama tidak terbawa ke Run baru', isset($sOld['stop_mode']['g5']) && !isset($sNew['stop_mode']['g5']) && !in_array('g5', array_map('strtolower', (array)($sNew['unit_stop_time'] ?? [])), true), ['old' => $sOld['stop_mode']['g5'] ?? null, 'new' => $sNew['stop_mode']['g5'] ?? null]);
$rec('I04', 'Block Required lama tidak terbawa', ($sNew['block_required_user']['Block 1'] ?? null) === false, ['new' => $sNew['block_required_user'] ?? null]);
$rec('I05', 'Start/Stop Mode lama tidak terbawa', !isset($sNew['required_mode']['g5']) && !isset($sNew['stop_mode']['g5']), ['new_req' => $sNew['required_mode']['g5'] ?? null]);
/* I6 Save Planning dan buka exact snapshot/result */
$oA = $outOf($RA); $s1 = $save([$entry($IA, $oA, 'Plan MX A')], 'owner1'); $k1 = $s1['entries'][0]['report_key'] ?? ($s1['report_key'] ?? null);
if ($k1 === null && isset($s1['entries'])) foreach ($s1['entries'] as $e) $k1 = $k1 ?? ($e['report_key'] ?? null);
$op = $k1 ? rs_report_open($k1) : ['ok' => false];
$exact = !empty($op['ok']) && $op['output_json'] === json_encode($oA, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) && json_decode($op['input_json'], true) == $strip($IA);
$rec('I06', 'Save Planning dan buka exact snapshot/result', $exact, ['save' => array_intersect_key($s1, ['ok' => 1, 'error' => 1, 'message' => 1]), 'key' => $k1, 'open_ok' => $op['ok'] ?? null, 'cp' => $op['meta']['cp'] ?? null]);
/* I7 Same-name -> versi baru, active version benar */
$s2 = $save([$entry($IF, $outOf($RF), 'Plan MX A')], 'owner1'); $v = $k1 ? rs_report_versions($k1) : ['versions' => []]; $op2 = $k1 ? rs_report_open($k1) : [];
$rec('I07', 'Same-name menambah versi dan active version benar', count($v['versions']) === 2 && ($op2['meta']['active_version_no'] ?? 0) === 2 && ($op2['meta']['cp'] ?? null) == ($RF['output']['info']['Cost Production (USD/MWh)'] ?? -1), ['versions' => count($v['versions']), 'active' => $op2['meta']['active_version_no'] ?? null, 'save2' => $s2['ok'] ?? null]);
/* I8 Different-name -> report baru */
$s3 = $save([$entry($IA, $oA, 'Plan MX B')], 'owner1'); $k3 = null; foreach ((array)($s3['entries'] ?? []) as $e) $k3 = $k3 ?? ($e['report_key'] ?? null);
$rec('I08', 'Different-name membuat report baru', $k3 !== null && $k3 !== $k1, ['k1' => $k1, 'k3' => $k3]);
/* I9 Monitoring 48 row */
$s4 = $save([$entry($IA, $oA, 'Plan MX C'), $entry($IA, $oA, 'Monitoring Daily Plan MX C', 'MONITORING')], 'owner1'); $p = rs_pdo();
$km = null; foreach ((array)($s4['entries'] ?? []) as $e) if (($e['report_type'] ?? '') === 'MONITORING') $km = $e['report_key'] ?? null;
$n = $km ? (int)$p->query("SELECT COUNT(*) FROM monitoring_rows mr JOIN report_entries e ON e.id = mr.report_entry_id WHERE e.report_key = " . $p->quote($km))->fetchColumn() : -1;
$rec('I09', 'Monitoring mempunyai 48 row', $n === 48, ['monitoring_key' => $km, 'rows' => $n, 'save' => $s4['ok'] ?? null]);
/* I10/I11 Delete sesuai creator / tanpa permission ditolak */
$d1 = $save([$entry($IA, $oA, 'Plan MX D')], 'creator9'); $kd = null; foreach ((array)($d1['entries'] ?? []) as $e) $kd = $kd ?? ($e['report_key'] ?? null);
$no = rs_report_delete(['report_key' => $kd, 'project_key' => $proj, 'request_id' => 'del1'], ['uid' => 'intruder', 'tab' => 't', 'project' => $proj, 'run' => '']);
$yes = rs_report_delete(['report_key' => $kd, 'project_key' => $proj, 'request_id' => 'del2'], ['uid' => 'creator9', 'tab' => 't', 'project' => $proj, 'run' => '']);
putenv('PP_REPORT_ADMINS=admin7'); $d2 = $save([$entry($IA, $oA, 'Plan MX E')], 'creator8'); $ke = null; foreach ((array)($d2['entries'] ?? []) as $e) $ke = $ke ?? ($e['report_key'] ?? null);
$adm = rs_report_delete(['report_key' => $ke, 'project_key' => $proj, 'request_id' => 'del3'], ['uid' => 'admin7', 'tab' => 't', 'project' => $proj, 'run' => '']);
$rec('I10', 'Delete sesuai creator / project owner / report_admins', !empty($yes['ok']) && !empty($adm['ok']) && (rs_report_open($kd)['error'] ?? '') === 'REPORT_DELETED', ['creator' => $yes['ok'] ?? null, 'admin' => $adm['ok'] ?? null]);
$rec('I11', 'User tanpa permission mendapat penolakan', ($no['error'] ?? '') === 'NO_PERMISSION', $no);
/* I12 MySQL migration idempotent, rollback, isolasi multi-user */
$sch = file_get_contents($app . '/database/schema.sql'); $mig0 = (int)$p->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$cnt0 = (int)$p->query('SELECT COUNT(*) FROM report_entries')->fetchColumn();
$bad = $save([$entry($IA, $oA, 'Plan MX F'), ['report_type' => 'PLANNING', 'plan_date' => '2026-10-05', 'plan_name' => 'Plan MX G', 'input_json' => '{}', 'output_json' => '{}']], 'owner1');
$cnt1 = (int)$p->query('SELECT COUNT(*) FROM report_entries')->fetchColumn();
$rp = $save([$entry($IA, $oA, 'Plan MX H')], 'owner1', 'same-rid'); $rp2 = $save([$entry($IA, $oA, 'Plan MX H')], 'owner1', 'same-rid');
$u1 = sds_latest_input($uid); $other = 'mxu_other'; $inO = $IA; $inO['_uid'] = $other; $inO['data3']['modeling']['note'] = 'other-user'; pp_v3_autosave($inO); $u1b = sds_latest_input($uid);
$rec('I12', 'MySQL: schema idempoten, transaksi rollback (entry rusak -> tidak ada yang tersimpan), request_id idempoten, isolasi multi-user',
    empty($bad['ok']) && $cnt1 === $cnt0 && !empty($rp2['idempotent_replay']) && ($u1b['input']['data3']['modeling']['note'] ?? '') === ($u1['input']['data3']['modeling']['note'] ?? 'x'),
    ['bad' => $bad['error'] ?? null, 'entries_before' => $cnt0, 'entries_after' => $cnt1, 'replay' => $rp2['idempotent_replay'] ?? null, 'migrations' => $mig0, 'isolation' => [$u1['input']['data3']['modeling']['note'] ?? null, $u1b['input']['data3']['modeling']['note'] ?? null]]);
/* H15 Fix Save/Reload/Report exact; H16 dua user/tab Fix berbeda */
$sf = $save([$entry($IF, $outOf($RF), 'Plan MX FIX')], 'fixuser'); $kf = null; foreach ((array)($sf['entries'] ?? []) as $e) $kf = $kf ?? ($e['report_key'] ?? null);
$of = $kf ? rs_report_open($kf) : []; $fixDb = $p->query("SELECT unit_id, row_index, fixed_mw FROM user_fixed_loads ORDER BY id DESC LIMIT 5")->fetchAll();
$fl = json_decode($of['input_json'] ?? '{}', true)['data3']['modeling']['unit_fix_load'] ?? null;
$rec('H15', 'Fix Save/Reload/Report exact (snapshot + user_fixed_loads)', $fl == $IF['data3']['modeling']['unit_fix_load'] && ($of['output_json'] ?? '') === json_encode($outOf($RF), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), ['fix' => $fl, 'db' => $fixDb]);
$ua = 'fixA_' . bin2hex(random_bytes(2)); $ub = 'fixB_' . bin2hex(random_bytes(2));
$ia = $IF; $ia['_uid'] = $ua; $ia['_tab'] = 'ta'; $ib = $IA; $ib['_uid'] = $ub; $ib['_tab'] = 'tb'; $ib['data3']['modeling']['unit_fix_load'] = ['g8' => [['start' => 30, 'stop' => 30, 'value' => 80]]];
pp_v3_autosave($ia); pp_v3_autosave($ib); $la = sds_latest_input($ua); $lb = sds_latest_input($ub);
$rec('H16', 'Dua user/tab mempunyai Fix berbeda (terisolasi)', ($la['input']['data3']['modeling']['unit_fix_load'] ?? null) == $IF['data3']['modeling']['unit_fix_load'] && isset($lb['input']['data3']['modeling']['unit_fix_load']['g8']), ['a' => $la['input']['data3']['modeling']['unit_fix_load'] ?? null, 'b' => $lb['input']['data3']['modeling']['unit_fix_load'] ?? null]);
file_put_contents($outF, json_encode($res, JSON_PRETTY_PRINT));
