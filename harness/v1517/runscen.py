# runscen.py <src> <outdir> <fixture...> : alur 2 langkah seperti UI (rekomendasi -> keputusan bahan bakar bila shortage terbukti)
import json, subprocess, sys, os, time, shutil
SRC, OUT = sys.argv[1], sys.argv[2]; os.makedirs(OUT, exist_ok=True)
DUMP = '/home/claude/ab2/tools/dumpjob.php'
def run(fx, out):
    t = time.time(); subprocess.run(['timeout', '300', 'php', DUMP, SRC, fx, out], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); w = time.time() - t
    try: return json.load(open(out)), w
    except Exception: return None, w
def summ(d):
    o = d['output']; i = o.get('info', {}); rows = o.get('data', []); rg = o.get('release_gate') or {}
    on = {}
    for U in ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10']:
        rr = [k + 1 for k, r in enumerate(rows) if float(r.get(U, 0) or 0) > 0.01]
        if rr: on[U] = f'{rr[0]}-{rr[-1]}'
    mp = i.get('Merit Proof C1-C4 STG') or {}
    return {'rows': len(rows), 'hard': d.get('hard'), 'gate': rg.get('status'), 'blk': rg.get('blocking_reasons'), 'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'),
            'gas_used': i.get('Total Gas Used (BBTUD)'), 'gas_quota': i.get('Total Gas Quota (BBTUD)'), 'pgn_pipe': i.get('PGN Pipe Used (BBTUD)'), 'lng_added': i.get('Added LNG (BBTUD)'),
            'dist_l': i.get('Distillate Fuel Total (l)') or i.get('Recommended Distillate (l/day)'), 'ff_total_mmscfd': round(sum(float(r.get('FixedFlow_J', 0) or 0) for r in rows), 3),
            'merit': mp.get('status'), 'C': {c: (mp.get(c) or {}).get('fail') for c in ['C1','C2','C3','C4']}, 'stg': (mp.get('stg') or {}).get('status'),
            'dist_cont': (i.get('Distillate Continuity Audit') or {}).get('status'), 'on': on, 'mode': (i.get('Run Status') or {}).get('mode'),
            'terminal': (o.get('terminal_decision') or {}).get('code'), 'viol': [str(v)[:120] for v in (d.get('viol') or [])][:3],
            'rec_lng': i.get('Recommended LNG (BBTUD)'), 'rec_dist': i.get('Recommended Distillate (l/day)'), 'shortage': i.get('Residual Gas Shortage (BBTUD)')}
for fx in sys.argv[3:]:
    n = os.path.basename(fx)[:-5]; shutil.rmtree(os.path.join(SRC, 'jobs'), ignore_errors=True)
    d1, w1 = run(fx, f'{OUT}/{n}_s1.json'); rec = {'case': n, 'step1_s': round(w1, 2)}
    if d1 is None: rec['error'] = 'NO_OUTPUT'; print(json.dumps(rec)); continue
    s1 = summ(d1); rec['step1'] = {k: s1[k] for k in ['gate', 'cp', 'shortage', 'rec_lng', 'rec_dist', 'terminal', 'mode']}
    final = s1; total = w1
    need = (s1['gate'] != 'PASS') and (float(s1.get('shortage') or 0) > 1e-6) and not s1.get('terminal')
    if need:
        p = json.load(open(fx)); m = p['data3']['modeling']; act = m.pop('__scenario_fuel', None) or 'add_lng'
        m['gas_shortage_action'] = act; m['__fuel_decision_mode'] = act
        if act == 'add_lng': m['additional_lng'] = float(s1.get('rec_lng') or 0)
        else: m['distillate_user_limit_litres'] = float(s1.get('rec_dist') or 0)
        fx2 = f'{OUT}/{n}_fx2.json'; json.dump(p, open(fx2, 'w'))
        d2, w2 = run(fx2, f'{OUT}/{n}_s2.json'); total += w2; rec['step2_s'] = round(w2, 2); rec['fuel'] = act
        if d2 is not None: final = summ(d2)
    rec['total_s'] = round(total, 2); rec['final'] = final
    print(json.dumps(rec), flush=True)
