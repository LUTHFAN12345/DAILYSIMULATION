"""fixrun.py <srcdir> <cpu> <tag> [ENV=V ...]: A0 basis then Fix Load g2 row20=25 MW on same app (warm path); writes /tmp/w/one/<tag>_{basis,fix}.json"""
import json, os, sys, subprocess, copy
SRC, CPU, TAG = sys.argv[1], sys.argv[2], sys.argv[3]; ENV = dict(os.environ)
for kv in sys.argv[4:]: k, v = kv.split('=', 1); ENV[k] = v.replace('@TAG@', TAG)
A0 = json.load(open('/tmp/w/reg/A0_actual.json'))
d = f'/tmp/w/one/app_{TAG}'
subprocess.run(['bash', '-c', f'rm -rf "{d}" && mkdir -p "{d}/jobs" && cp "{SRC}"/*.php "{d}/" && cp -r "{SRC}/config" "{SRC}/install" "{SRC}/database" "{d}/"'])
def run(inp, t, extra=None):
    fi = f'/tmp/w/one/{TAG}_{t}.in.json'; fo = f'/tmp/w/one/{TAG}_{t}.json'; json.dump(inp, open(fi, 'w'))
    e = dict(ENV); e.update(extra or {})
    subprocess.run(['taskset', '-c', CPU, '/opt/php74/bin/php', '/home/user/DAILYSIMULATION/harness/v1526/runjob2.php', d, fi, fo], env=e, capture_output=True)
    r = json.load(open(fo)); i = (r.get('output') or {}).get('info') or {}; b = r.get('__bench') or {}
    print(TAG, t, (r.get('output') or {}).get('status'), 'CP', i.get('Cost Production (USD/MWh)'), 'wall', b.get('wall_s'), 'cpu', b.get('cpu_s'), flush=True)
base_env = {k: v for k, v in ENV.items()}
run(A0, 'basis', {'PP_SIMPROF': ''})
F = copy.deepcopy(A0); m = F['data3']['modeling']; fl = m.get('unit_fix_load'); fl = fl if isinstance(fl, dict) else {}
fl.setdefault('g2', []).append({'start': 20, 'stop': 20, 'value': 25}); m['unit_fix_load'] = fl
run(F, 'fix')
