"""V15.25 benchmark PHP 7.4.33 satu logical CPU: python3 -I bench.py <app_src_dir> <cpu> <outdir> [label]
Cold: setiap kasus pada salinan app dengan direktori jobs kosong. Warm: basis A0 dijalankan dulu pada app yang sama, lalu
exact rerun / Actual Gas / Fix Load dijalankan pada app itu (jalur incremental). Fixture: /tmp/w/reg (A0_actual dst.)."""
import json, os, sys, subprocess, copy, time
SRC, CPU, OUT = sys.argv[1], sys.argv[2], sys.argv[3]; LABEL = sys.argv[4] if len(sys.argv) > 4 else 'run'
HERE = os.path.dirname(os.path.abspath(__file__)); RUNJOB = os.path.join(os.path.dirname(HERE), 'runjob2.php'); PHP = '/opt/php74/bin/php'
FIX = '/tmp/w/reg'; os.makedirs(OUT, exist_ok=True)
def load(n): return json.load(open(os.path.join(FIX, n + '.json')))
def M(i): return i['data3']['modeling']
def actual(i, key, rows, val):
    a = (list(M(i).get(key) or []) + [''] * 48)[:48]
    for k in rows: a[k - 1] = val
    M(i)[key] = a; return i
def fx(i, u, a, b, v):
    fl = M(i).get('unit_fix_load'); fl = fl if isinstance(fl, dict) else {}; fl.setdefault(u, []).append({'start': a, 'stop': b, 'value': v}); M(i)['unit_fix_load'] = fl; return i
def app(name):
    d = f'/tmp/w/bench25/{LABEL}_{name}'
    subprocess.run(['bash', '-c', f'rm -rf {d} && mkdir -p {d}/jobs && cp {SRC}/*.php {d}/ && cp -r {SRC}/config {SRC}/install {SRC}/database {d}/ 2>/dev/null; true'])
    return d
def run(a, inp, tag):
    fi = os.path.join(OUT, f'{LABEL}_{tag}.in.json'); fo = os.path.join(OUT, f'{LABEL}_{tag}.json'); json.dump(inp, open(fi, 'w'))
    p = subprocess.run(['taskset', '-c', CPU, PHP, RUNJOB, a, fi, fo], capture_output=True, text=True)
    r = json.load(open(fo)); o = r.get('output') or {}; i = o.get('info') or {}; b = r.get('__bench') or {}
    rec = {'tag': tag, 'status': o.get('status'), 'publish_allowed': r.get('publish_allowed'), 'cp': i.get('Cost Production (USD/MWh)'), 'wall_s': b.get('wall_s'), 'cpu_s': b.get('cpu_s'),
           'max_rss_kb': b.get('max_rss_kb'), 'php': b.get('php'), 'affinity': b.get('affinity'), 'warm': bool(i.get('IE Incremental Redispatch')),
           'warm_stage': (i.get('IE Incremental Redispatch') or {}).get('stage'), 'result_cache_hit': bool((o.get('meta') or {}).get('cache_hit') or r.get('cache_hit'))}
    print(json.dumps(rec), flush=True); os.remove(fi); return rec
A0 = load('A0_actual'); res = {'label': LABEL, 'cpu': CPU, 'cold': [], 'warm': []}
for k in range(3): res['cold'].append(run(app(f'coldA0_{k}'), A0, f'cold_A0_{k}'))
for name, n in (('gas_A1', 'A1_gas_cukup'), ('lng_A2', 'A2_lng'), ('terminal_A7', 'A7_IE_plus20')): res['cold'].append(run(app('cold_' + name), load(n), 'cold_' + name))
res['cold'].append(run(app('cold_actualPGN'), actual(copy.deepcopy(A0), 'actual_pgn_total', range(1, 9), 0.055), 'cold_actual_pgn_low'))
for k in range(3):
    a = app(f'warm_{k}'); run(a, A0, f'warm{k}_basis')
    res['warm'].append(run(a, copy.deepcopy(A0), f'warm{k}_exact_rerun'))
    res['warm'].append(run(a, actual(copy.deepcopy(A0), 'actual_pgn_total', range(1, 9), 0.055), f'warm{k}_actual_pgn_low'))
    res['warm'].append(run(a, actual(copy.deepcopy(A0), 'actual_energy_jababeka', range(1, 9), 1.133), f'warm{k}_actual_ffj_high'))
    res['warm'].append(run(a, actual(copy.deepcopy(A0), 'actual_energy_mm2100', range(1, 9), 0.02), f'warm{k}_actual_mm2100_high'))
    res['warm'].append(run(a, fx(copy.deepcopy(A0), 'g2', 20, 20, 25), f'warm{k}_fixload_g2'))
json.dump(res, open(os.path.join(OUT, f'bench_{LABEL}.json'), 'w'), indent=1)
