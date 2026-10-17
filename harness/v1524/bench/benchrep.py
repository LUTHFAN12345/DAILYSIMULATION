"""Ringkasan benchmark P0.4: python3 benchrep.py <res_dir_new> <res_dir_old> <out.md> <out.json>
Membaca <res>/<label>.json (keluaran runjob2.php: __bench) -> median/min/max wall, CPU, RSS per skenario & mode (cold/warm)."""
import json, os, sys, glob, statistics, re, subprocess

def load(d):
    out = {}
    for f in sorted(glob.glob(os.path.join(d, '*.json'))):
        lab = os.path.basename(f)[:-5]
        try: r = json.load(open(f))
        except Exception: continue
        b = r.get('__bench') or {}; o = r.get('output') or {}; i = o.get('info') or {}
        m = re.match(r'(.+?)_(cold|warm)(\d+)$', lab) or re.match(r'(rerun)_(exact)(\d+)$', lab) or re.match(r'(fix)_(warm|cold)(\d+)$', lab)
        if not m: continue
        sc, mode = m.group(1), m.group(2)
        out.setdefault((sc, mode), []).append({'wall': b.get('wall_s'), 'cpu': b.get('cpu_s'), 'rss_mb': round((b.get('max_rss_kb') or 0) / 1024, 1), 'aff': b.get('affinity'), 'php': b.get('php'),
            'cp': i.get('Cost Production (USD/MWh)'), 'publish': r.get('publish_allowed'), 'status': o.get('status'), 'hash': json.dumps([[w.get(u) for u in ('G1','G2','G3','G4','G5','G6','G8','G9','S1','S2','S3')] for w in (o.get('data') or [])]).__hash__()})
    return out

def st(xs):
    xs = [x for x in xs if x is not None]
    return {'median': round(statistics.median(xs), 2), 'min': round(min(xs), 2), 'max': round(max(xs), 2)} if xs else None

def topo():
    t = {}
    try: t['lscpu'] = subprocess.run(['lscpu'], capture_output=True, text=True).stdout
    except Exception: pass
    for f in ('/sys/fs/cgroup/cpu.max', '/sys/fs/cgroup/cpu/cpu.cfs_quota_us', '/sys/fs/cgroup/cpu/cpu.cfs_period_us'):
        if os.path.exists(f): t[f] = open(f).read().strip()
    t['nproc_all'] = os.cpu_count()
    try: t['php74'] = subprocess.run(['/opt/php74/bin/php', '-v'], capture_output=True, text=True).stdout.splitlines()[0]
    except Exception: pass
    try: t['opcache'] = subprocess.run(['/opt/php74/bin/php', '-r', 'echo json_encode(["opcache.enable_cli"=>ini_get("opcache.enable_cli"),"zts"=>PHP_ZTS,"pcntl"=>function_exists("pcntl_fork"),"memory_limit"=>ini_get("memory_limit")]);'], capture_output=True, text=True).stdout
    except Exception: pass
    return t

TARGET = {'A0_actual': ('normal Fastest', 30), 'A8_cfg_G9_ke_G5': ('difficult/cross-block', 40), 'A1_gas_cukup': ('gas-cukup (mandatory window)', 40), 'A2_lng': ('shortage/LNG', 40),
          'A7_IE_plus20': ('terminal/infeasible (IE +20)', 40), 'B0_input_data': ('fuel decision required (input_data)', 40), 'rerun': ('exact rerun', 15), 'fix': ('one-cell Fix Load', 10)}

if __name__ == '__main__':
    new, old, md, js = sys.argv[1:5]
    N, O = load(new), load(old); rows = []
    for (sc, mode) in sorted(set(N) | set(O)):
        n, o = N.get((sc, mode), []), O.get((sc, mode), [])
        rows.append({'scenario': sc, 'mode': mode, 'label': TARGET.get(sc, (sc, None))[0], 'target_s': TARGET.get(sc, (None, None))[1],
                     'new': {'runs': len(n), 'wall': st([x['wall'] for x in n]), 'cpu': st([x['cpu'] for x in n]), 'rss_mb': st([x['rss_mb'] for x in n]), 'cp': [x['cp'] for x in n][:1], 'deterministic': len({x['hash'] for x in n}) <= 1, 'affinity': sorted({str(x['aff']) for x in n}), 'php': sorted({str(x['php']) for x in n})},
                     'old': {'runs': len(o), 'wall': st([x['wall'] for x in o]), 'cpu': st([x['cpu'] for x in o]), 'rss_mb': st([x['rss_mb'] for x in o]), 'cp': [x['cp'] for x in o][:1], 'deterministic': len({x['hash'] for x in o}) <= 1}})
    T = topo(); json.dump({'topology': T, 'rows': rows}, open(js, 'w'), indent=1)
    with open(md, 'w') as f:
        f.write('# Benchmark P0.4 — PHP 7.4.33, 1 logical CPU (taskset -c 0)\n\n')
        f.write('Topologi host: ' + ' / '.join(l.strip() for l in T.get('lscpu', '').splitlines() if re.match(r'(CPU\(s\)|Model name|Thread|Core|Socket)', l.strip())) + '\n\n')
        f.write(f"Kuota cgroup: {', '.join(f'{k}={v}' for k, v in T.items() if k.startswith('/sys'))}; PHP: {T.get('php74')}; {T.get('opcache')}\n\n")
        f.write('| skenario | mode | target s | V15.24 median wall (min-max) | V15.24 median CPU | V15.24 RSS MB | V15.22 median wall (min-max) | V15.22 median CPU | V15.22 RSS MB | CP V15.24 / V15.22 | det. | affinity |\n|---|---|---|---|---|---|---|---|---|---|---|---|\n')
        for r in rows:
            fm = lambda s: f"{s['median']} ({s['min']}-{s['max']})" if s else '-'
            f.write(f"| {r['label']} | {r['mode']} | {r['target_s'] or '-'} | {fm(r['new']['wall'])} | {r['new']['cpu']['median'] if r['new']['cpu'] else '-'} | {r['new']['rss_mb']['median'] if r['new']['rss_mb'] else '-'} | {fm(r['old']['wall'])} | {r['old']['cpu']['median'] if r['old']['cpu'] else '-'} | {r['old']['rss_mb']['median'] if r['old']['rss_mb'] else '-'} | {r['new']['cp']} / {r['old']['cp']} | {r['new']['deterministic']}/{r['old']['deterministic']} | {','.join(r['new']['affinity'])} |\n")
    print(open(md).read())
