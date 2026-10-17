"""V15.24 matrix framework: fixture mutation, engine run (PHP 7.4.33, pinned CPU), oracle, report.
Usage: python3 -I mx.py run <group|all|ids...>   |   python3 -I mx.py report
Engine runs use the matrix app copy (APP) and are cached by canonical payload hash (identical payload -> one run)."""
import json, os, sys, copy, hashlib, subprocess, time, glob, csv, re
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.dirname(os.path.abspath(__file__))
APP = os.environ.get('MX_APP', '/tmp/w/app')
PHP = '/opt/php74/bin/php'
RUNJOB = os.path.join(os.path.dirname(ROOT), 'runjob2.php')
FIX = '/tmp/w/reg'
EVD = os.path.join(ROOT, 'evidence')
RUNS = os.path.join(EVD, 'runs')
CPUS = [int(x) for x in os.environ.get('MX_CPUS', '1,2,3').split(',')]
os.makedirs(RUNS, exist_ok=True)

def load(name):
    return json.load(open(os.path.join(FIX, name + '.json')))

def canon(inp):
    x = copy.deepcopy(inp)
    for k in list(x.keys()):
        if k.startswith('_'): del x[k]
    return hashlib.sha256(json.dumps(x, sort_keys=True, separators=(',', ':')).encode()).hexdigest()[:24] + ('_mr' if inp.get('_maximum_review') else '')

# ---------- mutation helpers (mirror UI applyCommitToModel) ----------
def M(inp): return inp['data3']['modeling']

def set_unit(inp, u, last=None, commit=None, stop=None, start_at=None, stop_at=None):
    """commit: None/'-' | 'sim' | 'request' | 'continuous'; stop: None/'-' | 'hold' | 'must' | 'opt' | 'request'"""
    m = M(inp); u = u.lower(); U = u.upper()
    rm = m.setdefault('required_mode', {}); sm = m.setdefault('stop_mode', {})
    ru = [x for x in m.get('required_units', []) if x != u]; cs = [x for x in m.get('unit_cannot_stop', []) if x != u]
    rm.pop(u, None); sm.pop(u, None)
    if commit == 'sim': rm[u] = {'mode': 'based_on_sim'}; ru.append(u); last = 'Stop'
    elif commit == 'request': rm[u] = {'mode': 'start_at', 'at': start_at or ''}; ru.append(u); last = 'Stop'
    elif commit == 'continuous': rm[u] = {'mode': 'continuous'}; cs.append(u); last = 'Running'; stop = None
    if stop == 'hold': sm[u] = {'mode': 'hold_to_end'}
    elif stop == 'must': sm[u] = {'mode': 'based_on_sim_must'}
    elif stop == 'opt': sm[u] = {'mode': 'based_on_sim'}
    elif stop == 'request': sm[u] = {'mode': 'stop_at', 'at': stop_at or ''}
    m['required_units'] = ru; m['unit_cannot_stop'] = cs
    if last: m.setdefault('unit_last_data_status', {})[U] = last
    return inp

def trip(inp, u, start=None, stop=None):
    m = M(inp); u = u.lower()
    if start is None: m['unit_stop'] = sorted(set(m.get('unit_stop', [])) | {u})
    else: m.setdefault('unit_stop_time', []).append({'unit': u, 'start': start, 'stop': stop})
    return inp

def block_req(inp, name, on=True):
    M(inp).setdefault('block_required_user', {})[name] = on; return inp

def range_rule(inp, mn, mx, start, stop, replace=False):
    pep = M(inp)['pln_export_priority']; rr = [] if replace else [r for r in pep.get('range_rules', []) if r['stop'] < start or r['start'] > stop]
    rr.append({'min': mn, 'max': mx, 'start': start, 'stop': stop}); pep['range_rules'] = sorted(rr, key=lambda r: r['start']); return inp

def ie_scale(inp, delta, rows=None):
    for i, r in enumerate(inp['data1']):
        if rows is None or (i + 1) in rows: r['value'] = round(float(r['value']) + delta, 5)
    return inp

def fuel(inp, action, lng=None, dist=None):
    m = M(inp); m['gas_shortage_action'] = action; m['__fuel_decision_mode'] = action
    if lng is not None: m['additional_lng'] = lng
    if dist is not None: m['distillate_user_limit_litres'] = dist
    return inp

def rt(row):  # row label (end of 30-min row) -> 'HH:MM'
    mm = (row * 30) % 1440; return '%02d:%02d' % (mm // 60, mm % 60)

def row_of(hhmm):
    h, m = map(int, hhmm.split(':')); r = (h * 60 + m) // 30; return 48 if r == 0 else r

# ---------- result accessors ----------
GT = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10']
ALLU = GT + ['S1', 'S2', 'S3', 'BB1', 'BB2', 'GE1', 'GE2', 'GE3', 'GE4']

class R:
    def __init__(self, r, inp):
        self.r = r; self.o = r.get('output', {}) or {}; self.d = self.o.get('data') or []; self.i = self.o.get('info') or {}; self.inp = inp
    def ok48(self): return len(self.d) == 48
    def on(self, u, row): return self.ok48() and float(self.d[row - 1].get(u.upper()) or 0) > 0.01
    def mw(self, u, row): return float(self.d[row - 1].get(u.upper()) or 0)
    def rows_on(self, u): return [k + 1 for k in range(len(self.d)) if self.on(u, k + 1)]
    def intervals(self, u):
        iv = []; s = None
        for k in range(1, 49):
            o = self.on(u, k) if self.ok48() else False
            if o and s is None: s = k
            if not o and s is not None: iv.append((s, k - 1)); s = None
        if s is not None: iv.append((s, 48))
        return iv
    def first_on(self, u):
        x = self.rows_on(u); return x[0] if x else None
    def published(self): return bool(self.r.get('publish_allowed')) and (self.r.get('hard_validation') or {}).get('status') == 'PASS' and self.ok48()
    def status(self): return self.o.get('status')
    def cp(self): return self.i.get('Cost Production (USD/MWh)')
    def hr(self): return self.i.get('JBBK MM Heat Rate (BTU/kWh)')
    def gas(self): return self.i.get('Total Gas Used (BBTUD)')
    def proof(self): return self.i.get('Commitment Event Proof') or {}
    def events(self):
        ev = []
        for u in GT + ['S1', 'S2', 'S3']:
            for a, b in self.intervals(u):
                if a > 1: ev.append(f'{u}+{rt(a)}')
                if b < 48: ev.append(f'{u}-{rt(b + 1)}')
        return ev
    def terminal_proof(self):
        st = str(self.status() or ''); msg = str(self.o.get('message') or '')
        if st == 'USER_FUEL_DECISION_REQUIRED' and (self.i.get('Gas Shortage Candidates') or []): return 'FUEL_DECISION_REQUIRED_WITH_VALIDATED_OPTIONS'
        if st.startswith('TERMINAL_INFEASIBLE'): return 'TERMINAL_INFEASIBLE:' + msg[:200]
        bl = [b for b in ((self.o.get('release_gate') or {}).get('blocking_reasons') or []) if str(b).startswith('TERMINAL_')]
        if bl: return 'TERMINAL_BLOCKING:' + ','.join(map(str, bl))
        bi = [b for b in ((self.o.get('release_gate') or {}).get('blocking_reasons') or []) if re.search('CONTRADICTORY|INPUT_INVALID|INPUT_REJECT|INFEASIBLE_INPUT', str(b))]
        if bi: return 'INPUT_CONTRADICTION:' + ','.join(map(str, bi))
        tc = self.i.get('Fastest Terminal Certificate')
        if isinstance(tc, dict) and (tc.get('code') or tc.get('status')): return 'TERMINAL_CERTIFICATE:' + str(tc.get('code') or tc.get('status'))
        if self.i.get('Reserve Infeasibility Certificate'): return 'RESERVE_INFEASIBILITY_CERTIFICATE'
        if 'lever legal habis' in msg and str(self.i.get('Gas Shortage Action') or 'none') in ('none', 'recommendation', 'flag_shortage_only'): return 'GAS_LEVER_EXHAUSTION_PROOF_DECISION_REQUIRED'
        e = self.r.get('error') or self.o.get('error')
        if isinstance(e, dict) and re.search('^(INPUT_|INVALID_|VALIDATION_|FIX_LOAD_|BAD_)|REJECT', str(e.get('code') or '')): return 'INPUT_REJECTED:' + json.dumps(e)[:160]
        if st.startswith('INPUT_') or 'REJECT' in st: return 'INPUT_REJECTED:' + st
        return None

# ---------- engine run with cache ----------
def run_engine(inp, cpu, envx=None):
    envx = envx or {}
    h = canon(inp) + (('_' + hashlib.sha1(json.dumps(envx, sort_keys=True).encode()).hexdigest()[:8]) if envx else ''); f = os.path.join(RUNS, h + '.json')
    if os.path.exists(f):
        try: return json.load(open(f)), h, True
        except Exception: pass
    fi = os.path.join(RUNS, h + '.in.json'); json.dump(inp, open(fi, 'w'))
    env = dict(os.environ); env.pop('PP_V1523_SHUFFLE', None); app = APP
    for k, v in envx.items():
        if k == '__APP': app = v
        else: env[k] = v
    if 'PP_V1523_SHUFFLE' in envx or '__COLD' in envx:   # run order-shuffle / cold dijalankan pada salinan app tersendiri tanpa cache job
        app = '/tmp/w/mxcold_%d' % cpu
        subprocess.run(['bash', '-c', f'rm -rf {app}.tmp && mkdir -p {app}.tmp && cp -r {APP}/*.php {APP}/config {APP}/install {APP}/database {app}.tmp/ 2>/dev/null; mkdir -p {app}.tmp/jobs; rm -rf {app}; mv {app}.tmp {app}'])
    p = subprocess.run(['taskset', '-c', str(cpu), PHP, RUNJOB, app, fi, f], capture_output=True, text=True, env=env, timeout=1800)
    try: return json.load(open(f)), h, False
    except Exception: return {'error': 'RUN_FAILED', 'stderr': p.stderr[-2000:]}, h, False

# ---------- test registry ----------
TESTS = []
def T(group, title, base, mut, expect='PASS', checks=(), kind='engine', note='', env=None):
    TESTS.append({'group': group, 'title': title, 'base': base, 'mut': mut, 'expect': expect, 'checks': list(checks), 'kind': kind, 'note': note, 'env': env or {}})

def evaluate(t, res, inp, h, cached, wall):
    rr = R(res, inp); notes = []; ok = True
    pub = rr.published(); tp = None if pub else rr.terminal_proof()
    # pemeriksaan dispatch hanya bermakna pada hasil yang dipublikasikan; penolakan wajib membawa terminal proof
    for c in (t['checks'] if pub else []):
        try: good, msg = c(rr)
        except Exception as e: good, msg = False, 'CHECK_ERROR ' + repr(e)
        notes.append(('OK ' if good else 'NG ') + msg); ok = ok and good
    if not pub: notes.append(('OK ' if tp else 'NG ') + f'tidak dipublikasikan; terminal proof: {tp}; status {rr.status()}; msg {str(rr.o.get("message") or "")[:160]}')
    if t['expect'] == 'PASS': status = 'PASS' if (pub and ok) else 'FAIL'
    elif t['expect'] == 'REJECT': status = 'EXPECTED_REJECTION' if (not pub and tp and ok) else 'FAIL'
    else: status = 'PASS' if (pub and ok) else ('EXPECTED_REJECTION' if (not pub and tp and ok) else 'FAIL')
    pf = rr.proof(); b = res.get('__bench') or {}
    rk = (rr.i.get('Start Priority Stage Profile') or {}).get('stages') or []
    return {'status': status, 'published': pub, 'terminal_proof': tp, 'engine_status': rr.status(), 'checks': notes,
            'cp': rr.cp(), 'econ_cp': (pf.get('final_metrics') or {}).get('econ_cp'), 'hr': rr.hr(), 'gas_bbtud': rr.gas(),
            'gas_quota_bbtud': rr.i.get('Total Gas Quota (BBTUD)'), 'gas_deviation_bbtud': rr.i.get('Gas Quota Deviation (BBTUD)'),
            'daily_export_mwh': rr.i.get('Daily PLN Exp (MWh)'), 'distillate_l': rr.i.get('Required Distillate (l/day)'),
            'hard_validation': (res.get('hard_validation') or {}).get('status'), 'release_gate': (rr.o.get('release_gate') or {}).get('status'),
            'c1_c4_stg': (rr.i.get('Merit Proof C1-C4 STG') or {}).get('status'), 'proof_binding': bool(rr.i.get('Proof Binding')),
            'event_proof_status': pf.get('status'), 'first_needed': [{'unit': e['unit'], 'start': e['time'], 'first_needed': e.get('first_needed_time'), 'deficit_mw': (e.get('deficit_at_first_needed') or {}).get('deficit_mw'), 'reason': e.get('reason_code')} for e in pf.get('events', []) if e.get('event') == 'START'],
            'events': rr.events() if rr.ok48() else [], 'rows': len(rr.d), 'payload_hash': h, 'job_input_hash': b.get('job_input_hash'), 'run_cached': cached,
            'php': b.get('php'), 'affinity': b.get('affinity'), 'wall_s': b.get('wall_s'), 'cpu_s': b.get('cpu_s'), 'max_rss_kb': b.get('max_rss_kb'),
            'phase_timing': [{k: s.get(k) for k in ('stage', 's')} for s in rk][:40], 'tightening_evals': (rr.i.get('Commitment Tightening') or {}).get('evaluations'),
            'evidence': os.path.relpath(os.path.join(RUNS, h + '.json'), ROOT)}

def run_tests(sel):
    from mxdefs import define
    define(globals())
    for k, t in enumerate(TESTS): t['id'] = 'T%03d' % (k + 1)
    todo = [t for t in TESTS if (sel == ['all'] or t['group'] in sel or t['id'] in sel) and t['kind'] == 'engine']
    out = {}
    def work(args):
        idx, t = args; cpu = CPUS[idx % len(CPUS)]
        inp = load(t['base'])
        if t['mut']:
            x = t['mut'](inp); inp = x if isinstance(x, dict) else inp
        t0 = time.time(); res, h, cached = run_engine(inp, cpu, t['env']); wall = time.time() - t0
        rec = {'id': t['id'], 'group': t['group'], 'title': t['title'], 'fixture': t['base'], 'baseline_version': 'V15.22 (f822347) / V15.23 (1639a5b)', 'mode': 'Fastest',
               'expected': t['expect'], 'note': t['note'], 'env': {k: v for k, v in t['env'].items() if not k.startswith('__')}}
        rec.update(evaluate(t, res, inp, h, cached, wall))
        json.dump(rec, open(os.path.join(EVD, t['id'] + '.json'), 'w'), indent=1)
        print(t['id'], t['group'], rec['status'], rec['cp'], ' '.join(rec['events'])[:90], '|', ' ; '.join(x for x in rec['checks'] if x.startswith('NG'))[:200], flush=True)
        return rec
    with ThreadPoolExecutor(len(CPUS)) as ex:
        for rec in ex.map(work, list(enumerate(todo))): out[rec['id']] = rec
    return out

def report():
    recs = [json.load(open(f)) for f in sorted(glob.glob(os.path.join(EVD, 'T*.json')))]
    groups = {}
    for r in recs: groups.setdefault(r['group'], []).append(r)
    for g, rs in groups.items():
        d = os.path.join(ROOT, 'report', g); os.makedirs(d, exist_ok=True)
        json.dump(rs, open(os.path.join(d, 'report.json'), 'w'), indent=1)
        with open(os.path.join(d, 'report.csv'), 'w', newline='') as fh:
            w = csv.writer(fh); w.writerow(['id', 'group', 'title', 'expected', 'status', 'published', 'terminal_proof', 'cp', 'econ_cp', 'hr', 'gas_bbtud', 'daily_export_mwh', 'events', 'event_proof_status', 'payload_hash', 'wall_s', 'cpu_s', 'evidence', 'failed_checks'])
            for r in rs: w.writerow([r['id'], r['group'], r['title'], r['expected'], r['status'], r.get('published'), r.get('terminal_proof'), r.get('cp'), r.get('econ_cp'), r.get('hr'), r.get('gas_bbtud'), r.get('daily_export_mwh'), ' '.join(r.get('events') or []), r.get('event_proof_status'), r.get('payload_hash'), r.get('wall_s'), r.get('cpu_s'), r.get('evidence'), ' ; '.join(x for x in r.get('checks', []) if x.startswith('NG'))])
    tot = {}
    for r in recs: tot[r['status']] = tot.get(r['status'], 0) + 1
    print('TOTAL', len(recs), tot)
    for g in sorted(groups): print(g, len(groups[g]), {s: sum(1 for r in groups[g] if r['status'] == s) for s in ('PASS', 'FAIL', 'EXPECTED_REJECTION')}, [r['id'] for r in groups[g] if r['status'] == 'FAIL'])

if __name__ == '__main__':
    sys.path.insert(0, ROOT)
    if sys.argv[1] == 'run': run_tests(sys.argv[2:]); report()
    elif sys.argv[1] == 'report': report()
