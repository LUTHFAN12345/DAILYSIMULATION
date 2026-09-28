<?php
/* Pustaka skenario V3: nama -> input lengkap (fixture + pgn 30 + perubahan). */
function v3_base(): array {
    $in = json_decode(file_get_contents('/home/claude/t5/fixtures/input_actual.json'), true);
    $in['data3']['modeling']['gas_quota']['pgn_pipe'] = 30.0;
    $in['data3']['modeling']['gas_shortage_action'] = 'recommendation';
    return $in;
}
/* nilai rencana per jam (jumlah dua row) dari rencana final PGN 30 */
function v3_plan_hours(): array {
    static $h = null; if ($h !== null) return $h;
    $r = json_decode(file_get_contents('/home/claude/t5/v2/base30.json'), true)['rows'];
    $h = ['pgn' => [], 'ffj' => [], 'ffm' => []];
    for ($i = 0; $i < 24; $i++) {
        $h['pgn'][$i] = round((float)$r[2*$i]['Est_PGN'] + (float)$r[2*$i+1]['Est_PGN'], 4);
        $h['ffj'][$i] = round((float)$r[2*$i]['Est_FF_J'] + (float)$r[2*$i+1]['Est_FF_J'], 4);
        $h['ffm'][$i] = round((float)$r[2*$i]['Est_FF_M'] + (float)$r[2*$i+1]['Est_FF_M'], 5);
    }
    return $h;
}
/* actual per jam untuk jam 0..$lastHour (format UI: indeks genap berisi nilai jam, ganjil '') */
function v3_actuals(array &$m, int $lastHour, array $over = []): void {
    $h = v3_plan_hours();
    foreach (['pgn' => 'actual_pgn_total', 'ffj' => 'actual_energy_jababeka', 'ffm' => 'actual_energy_mm2100'] as $k => $f) {
        $a = array_fill(0, 48, '');
        for ($i = 0; $i <= $lastHour; $i++) $a[2*$i] = $over[$k][$i] ?? $h[$k][$i];
        $m[$f] = $a;
    }
}
/* Skenario generik 'OPS:<k=v;...>' — tata bahasa yang sama dengan harness V2 (v2_seq.php mk()). */
function v3_ops(string $sc): array {
    $in = json_decode(file_get_contents('/home/claude/t5/fixtures/input_actual.json'), true); $m = &$in['data3']['modeling'];
    foreach (explode(';', $sc) as $kv) { if ($kv === '') continue; [$k, $v] = explode('=', $kv, 2);
        if ($k === 'stop') { [$u, $a, $b] = explode(':', $v); $m['unit_stop_time'][] = ['unit' => $u, 'start' => (int)$a, 'stop' => (int)$b]; }
        elseif ($k === 'skip') { [$u, $lo, $hi] = explode(':', $v); $m['unit_skip_load'][$u] = [['start' => 1, 'stop' => 48, 'value_low' => (float)$lo, 'value_high' => (float)$hi]]; }
        elseif ($k === 'fix') { [$u, $val, $a, $b] = explode(':', $v); $m['unit_fix_load'][$u] = [['start' => (int)$a, 'stop' => (int)$b, 'value' => (float)$val]]; }
        elseif ($k === 'act') { $m['gas_shortage_action'] = $v; }
        elseif ($k === 'addlng') { $m['additional_lng'] = (float)$v; }
        elseif ($k === 'distlim') { $m['distillate_user_limit_litres'] = (float)$v; }
        elseif ($k === 'mj') { [$jk, $jv] = explode(':', $v, 2); $m[$jk] = json_decode($jv, true); }
        elseif ($k === 'rmreq') { foreach (explode(',', $v) as $u) { unset($m['required_mode'][$u]); $m['unit_cannot_stop'] = array_values(array_diff((array)$m['unit_cannot_stop'], [$u])); } }
        else $m['gas_quota'][$k] = (float)$v; }
    unset($m); if (!preg_match('/act=/', $sc)) $in['data3']['modeling']['gas_shortage_action'] = 'recommendation';
    return $in;
}
function v3_input(string $name): array {
    if (strpos($name, 'OPS:') === 0) return v3_ops(substr($name, 4));
    $in = v3_base(); $m = &$in['data3']['modeling']; $h = v3_plan_hours();
    switch ($name) {
        case 'BASE_PGN30': break;
        case 'WB09_G1STOP': $in = v3_input('WB09'); $m2 = &$in['data3']['modeling']; unset($m2['required_mode']['g1']); $m2['unit_cannot_stop'] = array_values(array_diff((array)$m2['unit_cannot_stop'], ['g1'])); $m2['unit_stop_time'][] = ['unit' => 'g1', 'start' => 21, 'stop' => 48]; unset($m2); return $in;
        case 'WB09_G8FIX': $in = v3_input('WB09'); $in['data3']['modeling']['unit_fix_load']['g8'] = [['start' => 24, 'stop' => 27, 'value' => 76.0]]; $in['data3']['modeling']['unit_fix_load']['g9'] = [['start' => 24, 'stop' => 27, 'value' => 76.0]]; return $in;
        /* V11: Daily_Plan_09_Jul_26_Baru(3).xls — PGN Pipe 32, PEP Jababeka 34 MMSCFD (36,72 BBTUD), PEP KP72 2,2 BBTUD */
        case 'WB09_3': $in = v3_input('WB09'); $in['data3']['modeling']['gas_quota']['pgn_pipe'] = 32.0; $in['data3']['modeling']['gas_quota']['pep'] = 34.0; $in['data3']['modeling']['gas_quota']['pep_kp72'] = 2.2; $in['data3']['modeling']['name_plan'] = $in['data3']['modeling']['plan_name'] = $in['data3']['modeling']['note'] = 'Daily Plan 09-Jul-26 Baru (3)'; return $in;
        case 'WB09_KP72': $in = v3_input('WB09'); $in['data3']['modeling']['gas_quota']['pep_kp72'] = 2.2; return $in;
        case 'WB09_MANFF': $in = v3_input('WB09'); $in['data3']['modeling']['manual_fixed_flows'] = [['area' => 'MM2100', 'row' => 1, 'value_mmscfd' => 0.6226], ['area' => 'MM2100', 'row' => 2, 'value_mmscfd' => 0.6226]]; return $in;
        case 'WB09': $m['gas_quota']['pep'] = 32.0; foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $kk) $m['gas_quota'][$kk] = 0.0; $m['name_plan'] = $m['plan_name'] = $m['note'] = 'Daily Plan 09-Jul-26 Baru'; break;
        case 'BASE_ACT10': v3_actuals($m, 10); break;
        case 'ACT_PGN_UP':   v3_actuals($m, 10, ['pgn' => [10 => round($h['pgn'][10] * 1.03, 4)]]); break;
        case 'ACT_PGN_DOWN': v3_actuals($m, 10, ['pgn' => [10 => round($h['pgn'][10] * 0.97, 4)]]); break;
        case 'ACT_FFJ_UP':   v3_actuals($m, 10, ['ffj' => [10 => round($h['ffj'][10] * 1.03, 4)]]); break;
        case 'ACT_FFJ_DOWN': v3_actuals($m, 10, ['ffj' => [10 => round($h['ffj'][10] * 0.97, 4)]]); break;
        case 'ACT_FFM_UP':   v3_actuals($m, 10, ['ffm' => [10 => round($h['ffm'][10] * 1.03, 5)]]); break;
        case 'ACT_FFM_DOWN': v3_actuals($m, 10, ['ffm' => [10 => round($h['ffm'][10] * 0.97, 5)]]); break;
        case 'KP72_UP':   v3_actuals($m, 10); $m['manual_fixed_flows'] = [['area' => 'MM2100', 'row' => 30, 'value_mmscfd' => 2.25]]; break;
        case 'KP72_DOWN': v3_actuals($m, 10); $m['manual_fixed_flows'] = [['area' => 'MM2100', 'row' => 30, 'value_mmscfd' => 2.15]]; break;
        case 'KP72_ACT_UP': v3_actuals($m, 10); $m['manual_fixed_flows'] = [['area' => 'MM2100', 'row' => 21, 'value_mmscfd' => 2.35]]; break;
        case 'ACT_PGN_2SLOT_C': v3_actuals($m, 10, ['pgn' => [9 => round($h['pgn'][9] * 1.03, 4), 10 => round($h['pgn'][10] * 1.03, 4)]]); break;
        case 'ACT_NEW_HOUR': v3_actuals($m, 11); break;
        case 'ACT_NEW_HOUR_B': {   /* jam 11 masuk dengan nilai estimasi rencana final BASE_ACT10 */
            $o = json_decode(file_get_contents('/home/claude/t5/v3/base_act10_output.json'), true)['data'];
            $ov = ['pgn' => [11 => round((float)$o[22]['Est_PGN'] + (float)$o[23]['Est_PGN'], 4)],
                   'ffj' => [11 => round((float)$o[22]['Est_FF_J'] + (float)$o[23]['Est_FF_J'], 4)],
                   'ffm' => [11 => round((float)$o[22]['Est_FF_M'] + (float)$o[23]['Est_FF_M'], 5)]];
            v3_actuals($m, 11, $ov); break; }
        case 'FFJ_MANUAL_DOWN': v3_actuals($m, 10); $m['manual_fixed_flows'] = [['area' => 'JABABEKA', 'row' => 21, 'value_mmscfd' => 29.5]]; break;
        case 'ACT_PGN_2SLOT': v3_actuals($m, 11, ['pgn' => [10 => round($h['pgn'][10] * 1.03, 4), 11 => round($h['pgn'][11] * 1.03, 4)]]); break;
        case 'ACT_PGN_BIG':   v3_actuals($m, 10, ['pgn' => [10 => round($h['pgn'][10] * 2.2, 4)]]); break;
        default:
            /* V7: beberapa perubahan kuota sekaligus pada basis Actual 11 jam, mis. QAX:pgn_pipe=-1;pep=2 */
            if (strpos($name, 'QAX:') === 0) { v3_actuals($m, 10); foreach (explode(';', substr($name, 4)) as $kv) { if ($kv === '') continue; [$k, $d] = explode('=', $kv, 2); $m['gas_quota'][$k] = (float)$m['gas_quota'][$k] + (float)$d; } break; }
            if (preg_match('~^QA_(pgn_pipe|pep|lng|pep_kp72|akasia)_(-?[\d.]+)$~', $name, $mm)) { v3_actuals($m, 10); $m['gas_quota'][$mm[1]] = (float)$m['gas_quota'][$mm[1]] + (float)$mm[2]; break; }
            if (preg_match('~^Q_(pgn_pipe|pep|lng|pep_kp72|akasia)_(-?[\d.]+)$~', $name, $mm)) { $m['gas_quota'][$mm[1]] = (float)$m['gas_quota'][$mm[1]] + (float)$mm[2]; break; }
            throw new RuntimeException('skenario tidak dikenal: ' . $name);
    }
    unset($m);
    return $in;
}
