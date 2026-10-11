import json, copy, sys
base = json.load(open('/home/claude/ab/payloads/GOLDEN_dist.json'))
base['_fast_default'] = True; base['data3']['modeling']['__fuel_decision_mode'] = 'recommendation'
def var(name, f):
    p = copy.deepcopy(base); m = p['data3']['modeling']; f(m); json.dump(p, open(f'fx/{name}.json', 'w'))
def free_g6(m):
    m['unit_cannot_stop'] = [u for u in m['unit_cannot_stop'] if u != 'g6']; m['required_mode'].pop('g6', None)
def stop(u, a, b):
    def f(m):
        free_g6(m)
        if a == '00:00' and b == '24:00':                       # unavailable/trip sepanjang hari (seperti fixture P12)
            m['unit_stop'] = list(dict.fromkeys(m.get('unit_stop', []) + [u])); m['unit_last_data_status'][u.upper()] = 'Stop'
        else:                                                   # Stop Based On Request (format UI: stop_mode stop_at)
            m['stop_mode'] = {} if isinstance(m.get('stop_mode'), list) else m['stop_mode']; m['stop_mode'][u] = {'mode': 'stop_at', 'at': a}
    return f
import os; os.makedirs('fx', exist_ok=True)
var('D0_golden', lambda m: None)
var('D2_g6_stop2100', stop('g6', '21:00', '24:00'))
var('D3_g6_unavail', stop('g6', '00:00', '24:00'))
var('D4_g6_stop0800', stop('g6', '08:00', '24:00'))


def d3(m):                                          # D3: unit prioritas pertama Distillate (G3) tidak running/unavailable -> unit berikutnya
    m['unit_priority_dist'] = [["g3", "g6", "g4"], ["g1", "g2", "g5"], ["g9", "g8"], ["g7"], ["g10"]]
    m['unit_stop'] = list(dict.fromkeys(m.get('unit_stop', []) + ['g3']))
var('D3b_first_prio_unavail', d3)
def d4(m):                                          # D4: kebutuhan dua unit — kuota PGN pipe -3 BBTUD, plafon liter dinaikkan
    m['gas_quota']['pgn_pipe'] = m['gas_quota']['pgn_pipe'] - 3; m['distillate_user_limit_litres'] = 400000
var('D4b_two_units', d4)

def d4c(m):
    m['gas_quota']['pgn_pipe'] = m['gas_quota']['pgn_pipe'] - 7; m['distillate_user_limit_litres'] = 900000
var('D4c_two_units', d4c)
def d5(m):                                          # D5: constraint operator (Fix Load G6 = 5 MW row 30-33) memutus segmen G6
    m['gas_quota']['pgn_pipe'] = m['gas_quota']['pgn_pipe'] - 4; m['distillate_user_limit_litres'] = 900000
    m['unit_fix_load'] = {'g6': [{'start': 30, 'stop': 33, 'value': 5}]}
var('D5_two_blocks_fixload', d5)

def d5b(m):                                         # D5b: jendela stop G6 15:00-18:00 (unit_stop + unit_stop_time kanonik) memutus segmen G6
    m['gas_quota']['pgn_pipe'] = m['gas_quota']['pgn_pipe'] - 4; m['distillate_user_limit_litres'] = 900000
    free_g6(m); m['unit_stop_time'] = [{'unit': 'g6', 'start': '15:00', 'stop': '18:00'}]
var('D5b_two_blocks_stopwin', d5b)
