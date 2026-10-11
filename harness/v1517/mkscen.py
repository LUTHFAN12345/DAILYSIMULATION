# mkscen.py : fixture skenario operasional (ADDENDUM §4/§8/§6A) dari input aktual P0, golden, P10 (PV), P14 (Change Over)
import json, copy, os
P = '/home/claude/ab/payloads/'; O = '/home/claude/ab2/op/sc/'; os.makedirs(O, exist_ok=True)
def ld(n):
    p = json.load(open(P + n)); p['_fast_default'] = True; m = p['data3']['modeling']; m['__fuel_decision_mode'] = 'recommendation'; m['gas_shortage_action'] = 'recommendation'
    m['additional_lng'] = 0; m.pop('distillate_user_limit_litres', None)
    if isinstance(m.get('stop_mode'), list): m['stop_mode'] = {}
    return p
def put(name, p): json.dump(p, open(O + name + '.json', 'w'))
def free(m, u): m['unit_cannot_stop'] = [x for x in m.get('unit_cannot_stop', []) if x != u]; m.get('required_mode', {}).pop(u, None)
IE3 = [{'operator': '+', 'value': 15, 'start_period': '10:00', 'stop_period': '12:00'}]
B = 'P0.json'
def sc(name, base, f):
    p = ld(base); f(p['data3']['modeling'], p); put(name, p)
sc('S0_normal', B, lambda m, p: None)
def s1(m, p): free(m, 'g2'); m['unit_last_data_status']['G2'] = 'Stop'
sc('S1_g2_initially_off', B, s1)
def s2(m, p): free(m, 'g5'); m['unit_stop'] = list(dict.fromkeys(m.get('unit_stop', []) + ['g5'])); m['unit_last_data_status']['G5'] = 'Stop'
sc('S2_g5_trip_unavail', B, s2)
def s3(m, p): free(m, 'g8'); m['unit_stop_time'] = list(m.get('unit_stop_time') or []) + [{'unit': 'g8', 'start': '12:00', 'stop': '00:00'}]
sc('S3_g8_trip_1200', B, s3)
def s4(m, p): m.setdefault('required_mode', {})['g1'] = {'mode': 'start_at', 'at': '08:00'}; m['required_units'] = list(dict.fromkeys(m.get('required_units', []) + ['g1']))
sc('S4_g1_required_0800', B, s4)
def s5(m, p): m.setdefault('required_mode', {})['g5'] = {'mode': 'continuous'}; m['unit_cannot_stop'] = list(dict.fromkeys(m['unit_cannot_stop'] + ['g5'])); m['required_units'] = list(dict.fromkeys(m.get('required_units', []) + ['g5']))
sc('S5_g5_continuous', B, s5)
def s6(m, p): s1(m, p); s2(m, p)
sc('S6_oneoff_onetrip', B, s6)
for k, d, nm in [('pgn_pipe', 1, 'S7_pgn_p1'), ('pgn_pipe', -1, 'S8_pgn_m1'), ('pep', 1, 'S9_pep_p1'), ('pep', -1, 'S10_pep_m1'), ('akasia', 1, 'S11_akasia_p1'), ('akasia', -1, 'S12_akasia_m1'),
                 ('lng', 1, 'S13_lng_p1'), ('lng', -1, 'S14_lng_m1'), ('bbg', 1, 'S15_ffjbbk_p1_bbg'), ('pep_kp72', 1, 'S17_kp72_p1')]:
    sc(nm, B, lambda m, p, k=k, d=d: m['gas_quota'].__setitem__(k, m['gas_quota'][k] + d))
sc('S16_ffjbbk_m1_pep', B, lambda m, p: m['gas_quota'].__setitem__('pep', m['gas_quota']['pep'] - 1))
for d, nm in [(-1, 'S19_shortage_ringan'), (-3, 'S20_shortage_sedang'), (-6, 'S21_shortage_berat_lng')]:
    sc(nm, B, lambda m, p, d=d: m['gas_quota'].__setitem__('pgn_pipe', m['gas_quota']['pgn_pipe'] + d))
def s22(m, p): m['gas_quota']['pgn_pipe'] -= 3; m['__scenario_fuel'] = 'use_distillate'
sc('S22_shortage_distillate', B, s22)
put('S23_terminal_infeasible', ld('P12_g8_unavail.json'))
# Change Over
def co_b1b2(m, p):
    m['change_over'] = {'enabled': True, 'blocks': {'1': {'block': '1', 'last_status': 'Running', 'gtg': 'g4', 'stg': 's1', 'stop_other': 'sim'}, '2': {'block': '2', 'last_status': 'Stop', 'gtg': 'g1', 'stg': 's2', 'start_other': 'sim'}}}
    for u, st in [('G4', 'Running'), ('S1', 'Running'), ('G1', 'Stop'), ('S2', 'Stop')]: m['unit_last_data_status'][u] = st
    for u in ['g1', 's2']: free(m, u)
    for u in ['g4', 's1']: m['required_mode'][u] = {'mode': 'continuous'}; m['unit_cannot_stop'] = list(dict.fromkeys(m['unit_cannot_stop'] + [u]))
    bp = m['block_priority']; bp[2] = [x for x in bp[2] if x != 'required']; bp[3] = [x for x in bp[3] if x != 'required'] + ['required']
sc('CO0_b1_to_b2', 'P14_co_b2b1.json', co_b1b2)
sc('CO1_b2_to_b1', 'P14_co_b2b1.json', lambda m, p: None)
sc('CO2_pv_off', 'P14_co_b2b1.json', lambda m, p: m.update({'sr_mode': 'fixed'}))
pv = json.load(open(P + 'P10_pv_on.json'))['data3']['modeling']
sc('CO3_pv_on', 'P14_co_b2b1.json', lambda m, p: m.update({'sr_mode': 'follow_pv', 'pv_rows': pv['pv_rows'], 'sr_fixed_mw': pv.get('sr_fixed_mw', 15), 'sr_effective_rows': pv.get('sr_effective_rows')}))
sc('CO4_gas_shortage', 'P14_co_b2b1.json', lambda m, p: m['gas_quota'].__setitem__('pgn_pipe', m['gas_quota']['pgn_pipe'] - 3))
def co5(m, p): m['unit_stop'] = list(dict.fromkeys(m.get('unit_stop', []) + ['g3']))
sc('CO5_trip_g3', 'P14_co_b2b1.json', co5)
sc('CO6_stop_request_g5', 'P14_co_b2b1.json', lambda m, p: m['stop_mode'].update({'g5': {'mode': 'stop_at', 'at': '16:00'}}))
def co7(m, p): m['required_mode']['g9'] = {'mode': 'continuous'}; m['unit_cannot_stop'] = list(dict.fromkeys(m['unit_cannot_stop'] + ['g9']))
sc('CO7_continuous_g9', 'P14_co_b2b1.json', co7)
# IE kombinasi
sc('IE9_pv_on', 'P10_pv_on.json', lambda m, p: m.update({'ie_adjustments': IE3}))
sc('IE10_fix_sr15', 'GOLDEN_dist.json', lambda m, p: m.update({'ie_adjustments': IE3, 'sr_mode': 'fixed', 'sr_fixed_mw': 15, 'spinning_reserve_min': 15}))
sc('IE11_actual_gas', 'R5_BASE_ACT10.json', lambda m, p: m.update({'ie_adjustments': IE3}))
sc('IE12_shortage_lng', B, lambda m, p: m.update({'ie_adjustments': IE3}))
sc('IE13_distillate', 'GOLDEN_dist.json', lambda m, p: m.update({'ie_adjustments': IE3, '__scenario_fuel': 'use_distillate'}))
def ie14(m, p): s2(m, p); m['ie_adjustments'] = IE3
sc('IE14_trip', B, ie14)
sc('IE15_change_over', 'P14_co_b2b1.json', lambda m, p: m.update({'ie_adjustments': IE3}))
print(len(os.listdir(O)), 'fixtures')
