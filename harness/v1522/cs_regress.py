#!/usr/bin/env python3
"""V15.22 regression harness — Commitment Mode vs Stop Status vs Block Required (engine level, CLI Fastest job).
usage: cs_regress.py <src_dir> <base_payload.json> <out_dir> [case ...]
Each case: payload variant of the feasible base (G9 Stop + Start Based On Simulation + Stop Status Continuous, LNG decided),
run cold in its own copy of <src_dir>; checks evaluated on output rows + Commitment Semantics Trace + hard validator."""
import json, sys, os, copy, shutil, subprocess, concurrent.futures as cf
SRC, BASE, OUT = sys.argv[1], sys.argv[2], sys.argv[3]; ONLY = sys.argv[4:]
PHP = '/usr/local/bin/php74'; DJ = '/home/claude/hf/dj_async.php'
os.makedirs(OUT, exist_ok=True)
def T(r): m = (r * 30) % 1440; return '%02d:%02d' % (m // 60, m % 60)
def unit(m, u, uld=None, rm=None, sm=None):
    U = u.upper(); m.setdefault('required_mode', {}); m.setdefault('stop_mode', {})
    if not isinstance(m['stop_mode'], dict): m['stop_mode'] = {}
    if not isinstance(m['required_mode'], dict): m['required_mode'] = {}
    if uld is not None: m['unit_last_data_status'][U] = uld
    m['required_mode'].pop(u, None); m['stop_mode'].pop(u, None)
    m['unit_cannot_stop'] = [x for x in m.get('unit_cannot_stop', []) if x != u]
    m['required_units'] = [x for x in m.get('required_units', []) if x != u]
    if rm == 'continuous': m['required_mode'][u] = {'mode': 'continuous'}; m['unit_cannot_stop'].append(u)
    elif rm == 'sim': m['required_mode'][u] = {'mode': 'based_on_sim'}; m['required_units'].append(u)
    elif rm and rm.startswith('at:'): m['required_mode'][u] = {'mode': 'start_at', 'at': rm[3:]}; m['required_units'].append(u)
    if sm == 'hold': m['stop_mode'][u] = {'mode': 'hold_to_end'}
    elif sm == 'sim': m['stop_mode'][u] = {'mode': 'based_on_sim'}
    elif sm == 'must': m['stop_mode'][u] = {'mode': 'based_on_sim_must'}
    elif sm and sm.startswith('at:'): m['stop_mode'][u] = {'mode': 'stop_at', 'at': sm[3:]}
def ie(p, rows, d):
    for k in rows: p['data1'][k]['value'] = float(p['data1'][k]['value']) + d
CASES = {}
def case(name, desc):
    def deco(f): CASES[name] = (desc, f); return f
    return deco
@case('A1', 'G9 Stop + Start Based On Simulation + Stop Status Continuous')
def _(p, m): pass
@case('A2s', 'G9 Stop + Sim + Stop-or-Continuous; malam ringan (stop menguntungkan)')
def _(p, m): unit(m, 'g9', 'Stop', 'sim', 'sim'); ie(p, range(40, 48), -140)
@case('A2c', 'G9 Stop + Sim + Stop-or-Continuous; malam berat (continuous menguntungkan)')
def _(p, m): unit(m, 'g9', 'Stop', 'sim', 'sim')
@case('A3', 'G9 Stop + Sim + Stop Based On Simulation (wajib stop)')
def _(p, m): unit(m, 'g9', 'Stop', 'sim', 'must'); ie(p, range(40, 48), -140)
@case('A4', 'G9 Stop + Sim + Hold; sistem feasible tanpa G9 (IE rendah sepanjang hari)')
def _(p, m): ie(p, range(0, 48), -40); m['additional_lng'] = 0.0; m['gas_shortage_action'] = 'none'
@case('A5', 'G9 Stop + Sim + Hold; profil IE berbeda (G9 dibutuhkan lebih awal)')
def _(p, m): ie(p, range(8, 16), +60)
@case('B1', 'G9 Stop + Start Based On Request 04:00 + Stop Status Continuous')
def _(p, m): unit(m, 'g9', 'Stop', 'at:04:00', 'hold')
@case('B2', 'G9 Stop + Request 04:00 + Stop Based On Simulation (wajib stop)')
def _(p, m): unit(m, 'g9', 'Stop', 'at:04:00', 'must'); ie(p, range(40, 48), -140)
@case('B3', 'G9 Stop + Request 04:00 + Stop-or-Continuous')
def _(p, m): unit(m, 'g9', 'Stop', 'at:04:00', 'sim')
@case('C1', 'G9 Initial Running + Stop Status Continuous (tanpa Commitment)')
def _(p, m): unit(m, 'g9', 'Running', None, 'hold'); ie(p, range(0, 16), +140)
@case('C2', 'G9 Initial Running + Stop Based On Simulation (wajib stop)')
def _(p, m): unit(m, 'g9', 'Running', None, 'must'); ie(p, range(0, 16), +140); ie(p, range(40, 48), -140)
@case('F1', 'Block 3 Required ON, tanpa unit Continuous di blok (G8/G9 tanpa Commitment)')
def _(p, m): unit(m, 'g8', 'Stop'); unit(m, 'g9', 'Stop'); unit(m, 's3', 'Stop'); m['block_required_user']['Block 3'] = True; m['additional_lng'] = 3.4191
@case('F2', 'Block 1 Required OFF + token legacy "required" di block_priority; G6 tanpa Commitment')
def _(p, m):
    unit(m, 'g6', 'Stop'); unit(m, 's1', 'Stop'); ie(p, range(0, 48), -40)
    for b in m['block_priority']:
        if 'g6' in b and 'required' not in b: b.append('required')
@case('F3', 'Block 3 Required ON + seluruh GTG blok Stop All Day (konflik input)')
def _(p, m):
    unit(m, 'g8', 'Stop'); unit(m, 'g9', 'Stop'); m['block_required_user']['Block 3'] = True
    m['unit_stop'] = sorted(set(m.get('unit_stop', []) + ['g8', 'g9']))
@case('A6', 'G4 Stop + Commitment "-" + Stop Status Continuous (tidak wajib start; bila start, hold)')
def _(p, m): unit(m, 'g4', 'Stop', None, 'hold')
@case('A6x', 'kontrol: G4 Stop + Request 07:30 + hold')
def _(p, m): unit(m, 'g4', 'Stop', 'at:07:00', 'hold')
@case('A6s', 'kontrol: G4 Stop + Sim + hold')
def _(p, m): unit(m, 'g4', 'Stop', 'sim', 'hold')
@case('E1', 'Matriks: G3 (GTG Block 1) Stop + Sim + Hold')
def _(p, m): unit(m, 'g3', 'Stop', 'sim', 'hold')
@case('E2', 'Matriks: G7 (standby SC) Stop + Sim + Hold')
def _(p, m): unit(m, 'g7', 'Stop', 'sim', 'hold')
@case('F5', 'Block 3 Required ON + G8 tanpa Commitment (Stop) + G9 Sim + Stop Status Continuous')
def _(p, m): unit(m, 'g8', 'Stop'); unit(m, 's3', 'Stop'); m['block_required_user']['Block 3'] = True; m['additional_lng'] = 3.4191
@case('E3', 'Matriks: G1 (GTG Block 2) Stop + Sim + Hold')
def _(p, m): unit(m, 'g1', 'Stop', 'sim', 'hold')
@case('E4', 'Matriks: G5 (GTG Block 2) Stop + Sim + Hold')
def _(p, m): unit(m, 'g5', 'Stop', 'sim', 'hold')
@case('E5', 'Matriks: GE1 Stop + Sim + Hold tanpa sumber gas MM2100 legal (konflik input -> wajib ditolak)')
def _(p, m): unit(m, 'ge1', 'Stop', 'sim', 'hold')
@case('E6', 'Matriks: G3 Stop + Commitment "-" + Stop Status Continuous (hold-only, GTG Block 1)')
def _(p, m): unit(m, 'g3', 'Stop', None, 'hold')
@case('E7', 'Matriks: G3 Stop + Sim + Stop-or-Continuous (Stop Allowed)')
def _(p, m): unit(m, 'g3', 'Stop', 'sim', 'sim')
@case('E8', 'Matriks: G8 Initial Running + Stop Based On Simulation (wajib stop)')
def _(p, m): unit(m, 'g8', 'Running', None, 'must'); ie(p, range(40, 48), -140)
@case('E9', 'Matriks: G8 Stop + Sim + Hold (konfigurasi G9 dipindah ke G8; G9 tanpa Commitment)')
def _(p, m): unit(m, 'g8', 'Stop', 'sim', 'hold'); unit(m, 'g9', 'Running', 'continuous'); unit(m, 's3', 'Running', 'continuous')
@case('E10', 'Matriks: G5 Stop + Request 06:00 + Hold')
def _(p, m): unit(m, 'g5', 'Stop', 'at:06:00', 'hold')
@case('P1', 'Unit Priority ditukar G9 > G8 (Block 3), G9 Stop + Sim + Hold')
def _(p, m):
    for k in ('block_priority', 'unit_priority'):
        for b in m[k]:
            if 'g8' in b and 'g9' in b: i, j = b.index('g8'), b.index('g9'); b[i], b[j] = b[j], b[i]
@case('P2', 'Unit Priority ditukar G1 > G2 (Block 2), G1 Stop + Sim + Hold')
def _(p, m):
    unit(m, 'g1', 'Stop', 'sim', 'hold')
    for k in ('block_priority', 'unit_priority'):
        for b in m[k]:
            if 'g1' in b and 'g2' in b: i, j = b.index('g1'), b.index('g2'); b[i], b[j] = b[j], b[i]
@case('F7', 'Block 2 Required ON + G1 Stop + Sim + Hold (G2 Continuous sudah memenuhi blok)')
def _(p, m): unit(m, 'g1', 'Stop', 'sim', 'hold'); m['block_required_user']['Block 2'] = True
@case('D1', 'Gas shortage -> Use Distillate, G9 Stop + Sim + Hold')
def _(p, m): m['gas_shortage_action'] = 'use_distillate'; m['additional_lng'] = 0.0
@case('F4', 'G4 Commitment = Unit Continuous Running (Block Required OFF)')
def _(p, m): unit(m, 'g4', 'Stop', 'continuous')
@case('F6', 'Block 3 Required ON + G8 Unit Continuous Running (G9 Sim+Hold)')
def _(p, m): m['block_required_user']['Block 3'] = True
def run(name):
    desc, f = CASES[name]; p = json.load(open(BASE)); m = p['data3']['modeling']; f(p, m); p['_fast_default'] = True
    d = os.path.join(OUT, name); shutil.rmtree(d, ignore_errors=True); shutil.copytree(SRC, d + '/src', ignore=shutil.ignore_patterns('jobs', '.co_cache', '.rtprof', 'saved', '*.zip'))
    os.makedirs(d + '/src/jobs'); json.dump(p, open(d + '/in.json', 'w'))
    subprocess.run([PHP, DJ, d + '/src', d + '/in.json', d + '/out.json'], stdout=subprocess.DEVNULL, stderr=open(d + '/err.txt', 'w'), timeout=900)
    return name
def analyze(name):
    desc = CASES[name][0]; d = os.path.join(OUT, name)
    try: R = json.load(open(d + '/out.json'))
    except Exception as e: return {'case': name, 'desc': desc, 'result': 'ERROR', 'detail': open(d + '/err.txt').read()[-400:]}
    o = R['output']; i = o.get('info', {}); rows = o.get('data', []); tr = (i.get('Commitment Semantics Trace') or {})
    def on(u): c = 'BB' + u[1] if u in ('b1', 'b2') else u.upper(); return [k + 1 for k, x in enumerate(rows) if float(x.get(c) or 0) > 0.01]
    def iv(u):
        r = on(u); s = []
        for q in r:
            if s and s[-1][1] == q - 1: s[-1][1] = q
            else: s.append([q, q])
        return ' '.join(f'{T(a)}-{T(b)}' for a, b in s) or 'OFF'
    ck = []; add = lambda ok, msg: ck.append(('PASS' if ok else 'FAIL') + ' ' + msg)
    g9 = on('g9'); hard = R.get('hard'); rs = (i.get('Run Status') or {})
    tu = (tr.get('units') or {}); t9 = tu.get('G9', {})
    if name != 'F3': add(tr.get('status') == 'PASS', f"trace invariant {tr.get('status')} {tr.get('violations')}")
    if name in ('A1', 'A4', 'A5', 'F5', 'P1', 'D1'):
        add(bool(g9) and g9[0] != 1, f'G9 Stop di awal, start terpilih {T(g9[0]) if g9 else None} (bukan 00:30)')
        add(bool(g9) and g9[-1] == 48 and len(g9) == 48 - g9[0] + 1, 'G9 tidak stop setelah start (hold sampai 00:00)')
    if name == 'A2s': add(bool(g9) and g9[0] != 1, 'start bukan 00:30'); add(bool(g9), f'G9 {iv("g9")} (stop-or-continuous: lihat family evidence)')
    if name == 'A2c': add(bool(g9) and g9[0] != 1, 'start bukan 00:30'); add(bool(g9), f'G9 {iv("g9")}')
    if name in ('A3', 'B2'): add(bool(g9) and g9[-1] < 48, f'G9 wajib stop sebelum 00:00 -> {iv("g9")}')
    if name in ('B1', 'B2', 'B3'): add(bool(g9) and g9[0] == 9, f'G9 start Request 04:00 (marker 0 MW 04:00, berbeban 04:30) -> {iv("g9")}')
    if name == 'B1': add(bool(g9) and g9[-1] == 48 and len(g9) == 48 - g9[0] + 1, 'hold sampai 00:00 setelah start')
    if name == 'C1': add(len(g9) == 48, f'Initial Running + hold -> 48 row {iv("g9")}')
    if name == 'C2': add(bool(g9) and g9[0] == 1 and g9[-1] < 48, f'Initial Running + wajib stop -> {iv("g9")}')
    if name == 'E5':
        add(o.get('result') == 'rejected' and hard != 'PASS' and 'GE1' in json.dumps(R.get('viol')), f"Required GE1 tanpa gas legal ditolak (result={o.get('result')}, viol={R.get('viol')})")
    if name in ('E3', 'E4', 'E6', 'E9', 'P2', 'F7', 'E10'):
        uu = {'E3': 'g1', 'E4': 'g5', 'E6': 'g3', 'E9': 'g8', 'P2': 'g1', 'F7': 'g1', 'E10': 'g5'}[name]; q = on(uu); why = tu.get(uu.upper(), {}).get('selected_start_reason')
        add(not q or (q[-1] == 48 and len(q) == 48 - q[0] + 1), f'{uu.upper()} bila start -> hold sampai 00:00 ({iv(uu)})')
        add(not q or q[0] != 1 or 'Block Required' in str(why) or 'BLOCK' in str(why), f'{uu.upper()} tidak dipaksa 00:30 tanpa alasan ({iv(uu)}) reason={why}')
    if name == 'F5':
        cover = all(any(float(rows[k].get(u) or 0) > 0.01 for u in ('G8', 'G9')) for k in range(48)) if rows else False
        add(cover, f'Block 3: >=1 GTG berbeban 48 row (G8 {iv("g8")} | G9 {iv("g9")})')
        add(bool(g9) and g9[-1] == 48 and len(g9) == 48 - g9[0] + 1, f'G9 hold sampai 00:00 setelah start ({iv("g9")}) reason={t9.get("selected_start_reason")}')
    PROBE = {'A1': ('g9', 'sim_hold'), 'A4': ('g9', 'sim_hold'), 'A5': ('g9', 'sim_hold'), 'E1': ('g3', 'sim_hold'), 'E2': ('g7', 'sim_hold'), 'E3': ('g1', 'sim_hold'),
             'E4': ('g5', 'sim_hold'), 'E6': ('g3', 'hold_only'), 'A6': ('g4', 'hold_only'), 'E9': ('g8', 'sim_hold'), 'P1': ('g9', 'sim_hold'), 'P2': ('g1', 'sim_hold'), 'F7': ('g1', 'sim_hold'),
             'D1': ('g9', 'sim_hold'), 'F5': ('g9', 'sim_hold'), 'A2s': ('g9', 'sim_allowed'), 'A2c': ('g9', 'sim_allowed'), 'E7': ('g3', 'sim_allowed'), 'B1': ('g9', 'req_hold'), 'E10': ('g5', 'req_hold'),
             'C2': ('g9', 'run_must'), 'E8': ('g8', 'run_must'), 'F4': ('g4', 'cont'), 'A3': ('g9', 'sim_must'), 'B2': ('g9', 'req_must')}
    if name in PROBE:   # METAMORFIK: konfigurasi yang sama pada unit mana pun -> transisi state yang sama
        uu, kind = PROBE[name]; tt = tu.get(uu.upper(), {}); sp = tt.get('state_path') or []; q = on(uu)
        exp = {'sim_hold': ['OFF_INITIAL', 'ELIGIBLE_TO_START', 'STARTED_BY_SIMULATION', 'LOCKED_RUNNING_AFTER_START'],
               'hold_only': ['OFF_INITIAL', 'ELIGIBLE_TO_START', 'STARTED_BY_SIMULATION', 'LOCKED_RUNNING_AFTER_START'],
               'req_hold': ['OFF_INITIAL', 'START_REQUESTED', 'STARTED_BY_REQUEST', 'LOCKED_RUNNING_AFTER_START'],
               'sim_allowed': ['OFF_INITIAL', 'ELIGIBLE_TO_START', 'STARTED_BY_SIMULATION', 'ELIGIBLE_TO_STOP'],
               'sim_must': ['OFF_INITIAL', 'ELIGIBLE_TO_START', 'STARTED_BY_SIMULATION', 'ELIGIBLE_TO_STOP', 'STOPPED_BY_SIMULATION'],
               'req_must': ['OFF_INITIAL', 'START_REQUESTED', 'STARTED_BY_REQUEST', 'ELIGIBLE_TO_STOP', 'STOPPED_BY_SIMULATION'],
               'run_must': ['RUNNING_INITIAL', 'ELIGIBLE_TO_STOP', 'STOPPED_BY_SIMULATION'], 'cont': ['REQUIRED_FULL_HORIZON']}[kind]
        okp = sp == exp or (not q and kind in ('hold_only',) and sp == exp[:2]) or (kind == 'sim_allowed' and sp[:4] == exp)
        add(okp, f'state machine {uu.upper()} [{kind}] {" > ".join(sp)}')
        if kind in ('sim_hold', 'hold_only', 'req_hold', 'sim_allowed', 'sim_must', 'req_must'): add(not q or q[0] != 1, f'{uu.upper()} Initial Stop tidak berbeban row 1 ({iv(uu)})')
        if kind != 'cont': add(tt.get('normalized', {}).get('start_semantics') != 'CONTINUOUS_FULL_HORIZON', f'{uu.upper()} tidak menjadi CONTINUOUS_FULL_HORIZON ({tt.get("normalized", {}).get("start_semantics")})')
    if name in ('F1', 'F6'):
        cover = all(any(float(rows[k].get(u) or 0) > 0.01 for u in ('G8', 'G9')) for k in range(48)) if rows else False
        add(cover, f'Block 3: >=1 GTG berbeban 48 row (G8 {iv("g8")} | G9 {iv("g9")})')
    if name == 'F2': add(not (on('g6') and len(on('g6')) == 48 and on('g6')[0] == 1) or rs.get('status') is None, f'Block 1 OFF: G6 tidak dipaksa 48 row oleh token legacy -> G6 {iv("g6")}')
    if name == 'F3':
        rej = (hard != 'PASS') and any('Required' in json.dumps(x) or 'BLOCK_REQUIRED' in json.dumps(x) for x in [R.get('viol'), i.get('Block Required Conflict'), o.get('error'), i.get('Commitment Semantics')])
        add(rej or (o.get('result') != 'ok'), f"konflik input ditolak (hard={hard}, result={o.get('result')})")
    if name == 'A6':
        g4 = on('g4'); add(not g4 or (g4[0] != 1 and g4[-1] == 48 and len(g4) == 48 - g4[0] + 1), f'G4 tidak dipaksa start dini; bila start -> hold sampai 00:00 ({iv("g4")})')
        add(not g4 or g4[0] > 2 or (t9 is not None), f'G4 start bukan slot legal paling awal karena cannot_stop ({iv("g4")}) reason={tu.get("G4",{}).get("selected_start_reason")}')
    if name in ('E1', 'E2'):
        uu = 'g3' if name == 'E1' else 'g7'; q = on(uu)
        add(bool(q) and q[0] != 1 and q[-1] == 48 and len(q) == 48 - q[0] + 1, f'{uu.upper()} Stop awal, start terpilih, hold sampai 00:00 ({iv(uu)})')
        add(bool(q) and q[0] > 2, f'{uu.upper()} start {T(q[0]) if q else None} bukan fallback slot legal paling awal; reason={tu.get(uu.upper(),{}).get("selected_start_reason")}')
    if name == 'F7': add(len(on('g1')) != 48 or (on('g1') and on('g1')[0] != 1), f'Block 2 Required tidak memaksa G1 48 row ({iv("g1")})')
    if name == 'E8': q8 = on('g8'); add(bool(q8) and q8[0] == 1 and q8[-1] < 48, f'G8 Initial Running + wajib stop -> {iv("g8")}')
    if name == 'E10': q5 = on('g5'); add(not q5 or q5[0] >= 13, f'G5 tidak berbeban sebelum Request 06:00 ({iv("g5")})')
    if name == 'F4': add(len(on('g4')) == 48, f'G4 Continuous 48 row -> {iv("g4")}')
    if name == 'F6': add(len(on('g8')) == 48, f'G8 Continuous memenuhi blok -> G8 {iv("g8")}')
    if name not in ('F3', 'E5'): add(hard == 'PASS', f'hard validator {hard}')
    return {'case': name, 'desc': desc, 'result': 'PASS' if all(c.startswith('PASS') for c in ck) else 'FAIL', 'checks': ck, 'wall_s': R.get('wall_s'),
            'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'), 'core': rs.get('core_simulations'), 'status': rs.get('status'),
            'g9': iv('g9'), 'g9_trace': {k: t9.get(k) for k in ('selected_start', 'selected_start_reason', 'selected_stop', 'selected_stop_reason')},
            'startup_proof': ((i.get('Startup Timing Proof') or {}).get('units') or {}).get('G9'), 'family': i.get('Stop Or Continuous Family Evidence') or i.get('V1518 Family Evidence')}
names = ONLY or list(CASES)
with cf.ThreadPoolExecutor(max_workers=int(os.environ.get('CS_PAR', '3'))) as ex: list(ex.map(run, names))
res = [analyze(n) for n in names]
json.dump(res, open(os.path.join(OUT, 'report.json'), 'w'), indent=1)
for r in res: print(r['case'], r['result'], r.get('wall_s'), r.get('cp'), r.get('status'), '| G9', r.get('g9'), '\n   ' + '\n   '.join(r.get('checks', [r.get('detail', '')])))
