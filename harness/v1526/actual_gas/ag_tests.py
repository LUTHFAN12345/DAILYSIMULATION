"""ag_tests.py <app_src> <cpu> <outdir> : uji Actual Gas eksplisit (lebih tinggi & lebih rendah) untuk Actual PGN, Actual TOTAL Fixed Flow
Jababeka, Actual Fixed Flow MM2100 — satu row, beberapa row, ketiganya bersamaan, distillate / LNG / Fix Load / supplier window, sisa row cukup
dan tidak cukup. Basis dijalankan sekali per konteks; setiap varian dijalankan inkremental (warm) dari SNAPSHOT jobs basis yang sama, lalu
diulang (repeatability) dan dijalankan dingin (pembanding). Nilai simulasi diambil dari output basis (Est_PGN / Est_FF_J / Est_FF_M per row)."""
import json, os, sys, subprocess, copy, hashlib
SRC0, CPU, OUT = sys.argv[1], sys.argv[2], sys.argv[3]; os.makedirs(OUT, exist_ok=True)
# snapshot source sekali (source repo boleh berubah selama suite berjalan tanpa mencampur versi)
SRC = OUT + '/src'; subprocess.run(['bash', '-c', f'rm -rf {SRC} && mkdir -p {SRC} && cp {SRC0}/*.php {SRC}/ && cp -r {SRC0}/config {SRC0}/install {SRC0}/database {SRC}/ 2>/dev/null; true'], check=True)
HERE = os.path.dirname(os.path.abspath(__file__)); RUNJOB = os.path.join(os.path.dirname(HERE), 'runjob2.php'); PHP = '/opt/php74/bin/php'
def load(n): return json.load(open(f'/tmp/w/reg/{n}.json'))
def M(i): return i['data3']['modeling']
def sh(c): subprocess.run(['bash', '-c', c], check=True)
def mkapp(d): sh(f'rm -rf {d} && mkdir -p {d}/jobs && cp {SRC}/*.php {d}/ && cp -r {SRC}/config {SRC}/install {SRC}/database {d}/ 2>/dev/null; true')
def run(app, inp, tag):
    fi = f'{OUT}/{tag}.in.json'; fo = f'{OUT}/{tag}.json'; json.dump(inp, open(fi, 'w'))
    subprocess.run(['taskset', '-c', CPU, PHP, RUNJOB, app, fi, fo], capture_output=True, text=True)
    return json.load(open(fo))
def dh(rows): return hashlib.sha256(json.dumps([[r.get(u) for u in ('G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2')] for r in rows]).encode()).hexdigest()[:16]
KEY = {'PGN': ('actual_pgn_total', 'Est_PGN', 'Act_PGN'), 'FFJ': ('actual_energy_jababeka', 'Est_FF_J', 'Act_FF_J'), 'MM': ('actual_energy_mm2100', 'Est_FF_M', 'Act_FF_M')}
def setact(inp, base_rows, kind, rows, f):
    # Actual Gas = nilai per JAM (UI: satu sel per jam, indeks genap kanonik; indeks ganjil kosong) -> row r dipetakan ke jam (r-1)//2
    k, est, _ = KEY[kind]; a = (list(M(inp).get(k) or []) + [''] * 48)[:48]
    for h in HOURS(rows): a[2 * h] = round((float(base_rows[2 * h].get(est) or 0) + float(base_rows[2 * h + 1].get(est) or 0)) * f, 6); a[2 * h + 1] = ''
    M(inp)[k] = a; return inp
def HOURS(rows): return sorted({(r - 1) // 2 for r in rows})
BASES = {
  'A0_distillate': lambda: load('A0_actual'),
  'A2_lng': lambda: load('A2_lng'),
  'A0_fixload': lambda: (lambda i: (M(i).__setitem__('unit_fix_load', {'g2': [{'start': 20, 'stop': 20, 'value': 25}]}), i)[1])(load('A0_actual')),
  'A0_supplier_window': lambda: (lambda i: (M(i).__setitem__('min_pgn_flow', 5), i)[1])(load('A0_actual')),
  # MM2100 aktif: kuota KP72 (pep cummulative 1 BBTUD + pertagas fixed 2 MMSCFD) -> Est_FF_M > 0 (fixture A0 berkuota 0 sehingga MM2100 = 0)
  'MM2100_active': lambda: (lambda i: (M(i)['gas_quota'].update({'pep_kp72': 1, 'pertagas_kp72': 2}), M(i).__setitem__('max_flow_mm2100', 5), i)[2])(load('A0_actual')),
}
VARS = {
  'A0_distillate': [('PGN', range(1, 9), 1.00), ('FFJ', range(1, 9), 1.00), ('PGN', [1, 2], 1.10), ('PGN', [1, 2], 0.90), ('PGN', range(1, 9), 1.10), ('PGN', range(1, 9), 0.90), ('FFJ', [1, 2], 1.10), ('FFJ', [1, 2], 0.90),
                    ('FFJ', range(1, 9), 1.10), ('FFJ', range(1, 9), 0.90), ('ALL3', range(1, 9), 1.10), ('ALL3', range(1, 9), 0.90),
                    ('PGN', range(1, 17), 1.80), ('PGN', range(1, 17), 0.20)],
  'A2_lng': [('PGN', range(1, 9), 1.00), ('PGN', range(1, 9), 1.10), ('PGN', range(1, 9), 0.90), ('FFJ', range(1, 9), 1.10), ('FFJ', range(1, 9), 0.90)],
  'A0_fixload': [('PGN', range(1, 9), 1.10), ('PGN', range(1, 9), 0.90)],
  'A0_supplier_window': [('PGN', range(1, 9), 1.10), ('PGN', range(1, 9), 0.90)],
  'MM2100_active': [('MM', [1, 2], 1.10), ('MM', [1, 2], 0.90), ('MM', range(1, 9), 1.10), ('MM', range(1, 9), 0.90), ('ALL3', range(1, 9), 1.10), ('ALL3', range(1, 9), 0.90)],
}
only = sys.argv[4].split(',') if len(sys.argv) > 4 else list(BASES)
res = []
for bn in only:
    base = BASES[bn](); app = f'/tmp/w/ag26b/{bn}'; mkapp(app)
    rb = run(app, base, f'{bn}__basis'); ob = rb['output']; brow = ob['data']; ib = ob['info']
    sh(f'rm -rf {app}.jobs0 && cp -a {app}/jobs {app}.jobs0')
    for kind, rows, f in VARS[bn]:
        rows = list(rows); tag = f"{bn}__{kind}_{'hi' if f > 1 else ('eq' if f == 1 else 'lo')}{int(round(abs(f - 1) * 100))}_h{HOURS(rows)[0] + 1}-{HOURS(rows)[-1] + 1}"
        inp = copy.deepcopy(base)
        for kd in (['PGN', 'FFJ', 'MM'] if kind == 'ALL3' else [kind]): setact(inp, brow, kd, rows, f)
        out = {}
        for rep in ('warm', 'warm_repeat'):
            sh(f'rm -rf {app}/jobs && cp -a {app}.jobs0 {app}/jobs'); out[rep] = run(app, copy.deepcopy(inp), f'{tag}__{rep}')
        capp = f'/tmp/w/ag26b/cold_{bn}'; mkapp(capp); out['cold'] = run(capp, copy.deepcopy(inp), f'{tag}__cold')
        rec = {'tag': tag, 'base': bn, 'kind': kind, 'rows': [rows[0], rows[-1]], 'factor': f, 'direction': 'HIGHER' if f > 1 else ('EQUAL' if f == 1 else 'LOWER')}
        for rep, r in out.items():
            o = r.get('output') or {}; i = o.get('info') or {}; d = o.get('data') or []; b = r.get('__bench') or {}
            imm = []
            for kd in (['PGN', 'FFJ', 'MM'] if kind == 'ALL3' else [kind]):
                k, est, act = KEY[kd]; a = (list(M(inp).get(k) or []) + [''] * 48)[:48]
                for h in range(0, 48, 2):
                    x, y = a[h], a[h + 1]; exp = None if (x == '' and y == '') else (float(x or 0) + float(y or 0))
                    got = d[h].get(act) if len(d) == 48 else None
                    if (exp is None) != (got in (None, '')) or (exp is not None and abs(exp - float(got)) > 1e-6): imm.append(f'{kd} jam {h // 2 + 1}: exp {exp} got {got}')
            simv = {kd: round(sum(float(brow[x].get(KEY[kd][1]) or 0) for h in HOURS(rows) for x in (2 * h, 2 * h + 1)), 6) for kd in (['PGN', 'FFJ', 'MM'] if kind == 'ALL3' else [kind])}
            actv = {kd: round(sum(float((M(inp)[KEY[kd][0]][2 * h]) or 0) for h in HOURS(rows)), 6) for kd in simv}
            u15 = ('G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','BB1','BB2')
            past_mw_changed = [x + 1 for h in HOURS(rows) for x in (2 * h, 2 * h + 1) if len(d) == 48 and any(abs(float(d[x].get(u) or 0) - float(brow[x].get(u) or 0)) > 0.01 for u in u15)]
            fut = [k for k in range(rows[-1], 48) if len(d) == 48 and (abs(float(d[k].get('Flow_PGN_RT') or 0) - float(brow[k].get('Flow_PGN_RT') or 0)) > 1e-6 or abs(float(d[k].get('Est_PGN') or 0) - float(brow[k].get('Est_PGN') or 0)) > 1e-6 or dh([d[k]]) != dh([brow[k]]))]
            q = i.get('Total Gas Quota (BBTUD)'); eff = i.get('Effective Total Gas (BBTUD)'); used = i.get('Total Gas Used (BBTUD)')
            rec[rep] = {'status': o.get('status'), 'publish': r.get('publish_allowed') and o.get('preliminary') is not True, 'hard': (r.get('hard_validation') or {}).get('status'),
                'sim_value': simv, 'actual_value': actv, 'delta': {kd: round(actv[kd] - simv[kd], 6) for kd in simv}, 'actual_rows_immutable': not imm, 'immutable_issues': imm[:4], 'actual_hour_dispatch_changed_rows': past_mw_changed, 'export_mwh': i.get('Daily PLN Exp (MWh)'),
                'future_rows_adjusted': len(fut), 'future_rows_first': [x + 1 for x in fut[:6]], 'effective_total_gas_bbtud': eff, 'total_gas_used_bbtud': used, 'quota_bbtud': q,
                'window': [round(q - 0.04, 4), q] if q else None, 'residual_bbtud': round(eff - q, 4) if (eff is not None and q) else None,
                'basis_effective_gas_bbtud': ib.get('Effective Total Gas (BBTUD)') or ib.get('Total Gas Used (BBTUD)'), 'cp': i.get('Cost Production (USD/MWh)'), 'basis_cp': ib.get('Cost Production (USD/MWh)'),
                'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'), 'distillate_l': i.get('Required Distillate (l/day)'), 'basis_distillate_l': ib.get('Required Distillate (l/day)'), 'lng_used': i.get('LNG Used (BBTUD)'),
                'dispatch_hash': dh(d) if len(d) == 48 else None, 'basis_dispatch_hash': dh(brow), 'warm_stage': ((i.get('IE Incremental Redispatch') or {}).get('tahap') or [{}])[-1].get('tahap') if (i.get('IE Incremental Redispatch') or {}).get('applied') else None,
                'reconciliation': i.get('Actual Gas Distillate Reconciliation'), 'terminal_or_decision': (o.get('message') or '')[:200] if not r.get('publish_allowed') else None,
                'priority_invariants': (i.get('Priority Invariants') or {}).get('status'), 'anchor': {k: (i.get('Actual Hour Anchor') or {}).get(k) for k in ('status', 'sumber', 'actual_rows_dispatch_identical', 'anchor_cp')}, 'event_proof': (i.get('Commitment Event Proof') or {}).get('status'),
                'wall_s': b.get('wall_s'), 'cpu_s': b.get('cpu_s'), 'payload_hash': b.get('payload_hash')}
        rec['repeatable_warm'] = rec['warm']['dispatch_hash'] == rec['warm_repeat']['dispatch_hash'] and rec['warm']['cp'] == rec['warm_repeat']['cp']
        print(json.dumps({k: rec[k] for k in ('tag', 'repeatable_warm')} | {rep: {x: rec[rep][x] for x in ('status', 'publish', 'actual_rows_immutable', 'future_rows_adjusted', 'residual_bbtud', 'cp', 'wall_s')} for rep in ('warm', 'cold')}), flush=True)
        res.append(rec); json.dump(res, open(f'{OUT}/ag_results.json', 'w'), indent=1)
