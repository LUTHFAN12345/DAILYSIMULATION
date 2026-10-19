"""V15.31 engine test Actual Gas kumulatif (warm dari basis A0 pada app yang sama, satu CPU):
python3 -I ag_cum_engine.py <app_src> <cpu> <outdir>"""
import json, os, sys, subprocess, copy
SRC, CPU, OUT = sys.argv[1], sys.argv[2], sys.argv[3]; os.makedirs(OUT, exist_ok=True)
RUN = '/home/user/DAILYSIMULATION/harness/v1526/runjob2.php'; A0 = json.load(open('/tmp/w/reg/A0_actual.json'))
app = os.path.join(OUT, 'app'); subprocess.run(['bash', '-c', f'rm -rf "{app}" && mkdir -p "{app}/jobs" && cp "{SRC}"/*.php "{app}/" && cp -r "{SRC}/config" "{SRC}/install" "{SRC}/database" "{app}/"'])
def E(): return [''] * 48
def snap(p=None, j=None, m=None):
    i = copy.deepcopy(A0); s = {'pgn': E(), 'ffj': E(), 'ffm': E()}
    for k, d in (('pgn', p), ('ffj', j), ('ffm', m)):
        for r, v in (d or {}).items(): s[k][r] = v
    i['data3']['modeling']['actual_gas_snapshots'] = s; return i
def legacy(p):
    i = copy.deepcopy(A0); a = E()
    for r, v in p.items(): a[r] = v
    i['data3']['modeling']['actual_pgn_total'] = a; return i
res = {}
def run(tag, inp):
    fi = os.path.join(OUT, tag + '.in.json'); fo = os.path.join(OUT, tag + '.json'); json.dump(inp, open(fi, 'w'))
    subprocess.run(['taskset', '-c', CPU, '/opt/php74/bin/php', RUN, app, fi, fo], capture_output=True)
    r = json.load(open(fo)); o = r.get('output') or {}; i = o.get('info') or {}; b = r.get('__bench') or {}
    c = i.get('Actual Gas Canonical') or {}
    rec = {'status': o.get('status'), 'publish': r.get('publish_allowed'), 'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'),
           'hdr_pgn': i.get('Actual Total Gas PGN (BBTUD)'), 'hdr_ffj': i.get('Actual Energy Total Fixed Flow Jababeka (BBTUD)'), 'hdr_ffm': i.get('Actual Energy Total Fixed Flow MM2100 (BBTUD)'),
           'eff_gas': i.get('Effective Total Gas (BBTUD)'), 'pgn_pipe': i.get('PGN Pipe Total (BBTUD)'), 'residual': i.get('Residual Gas Shortage (BBTUD)'),
           'canon': {k: (v.get('value'), v.get('latest_row')) for k, v in (c.get('suppliers') or {}).items()}, 'cutoff': c.get('cutoff_row'),
           'wall_s': b.get('wall_s'), 'warm': bool(i.get('IE Incremental Redispatch')), 'dispatch_hash': __import__('hashlib').md5(json.dumps(o.get('data'), sort_keys=True).encode()).hexdigest()[:12],
           'payload_hash': b.get('payload_hash')}
    res[tag] = rec; print(tag, json.dumps(rec), flush=True); os.remove(fi); return rec
run('basis_A0', A0)
run('pgn_3.0_at_01', snap(p={0: 3.0}))
r2 = run('pgn_3.0_then_4.5_at_02', snap(p={0: 3.0, 2: 4.5}))
r3 = run('pgn_4.5_at_02_only', snap(p={2: 4.5}))
r4 = run('legacy_equiv_2.25x2', legacy({0: 2.25, 2: 2.25}))
run('pgn_higher_6.0_at_02', snap(p={0: 3.0, 2: 6.0}))
run('pgn_lower_3.5_at_02', snap(p={0: 3.0, 2: 3.5}))
run('ffj_1.9_at_01', snap(j={0: 1.9}))
run('ffj_higher_2.6_at_02', snap(j={0: 1.9, 2: 2.6}))
run('ffj_lower_1.2_at_02', snap(j={0: 1.9, 2: 1.2}))
run('mm_0.4_at_04', snap(m={6: 0.4}))
run('mm_higher_0.9_at_04', snap(m={2: 0.2, 6: 0.9}))
run('mm_lower_0.1_at_04', snap(m={2: 0.2, 6: 0.1}))
run('all3_diff_rows', snap(p={0: 3.0, 2: 4.5}, j={0: 1.9}, m={6: 0.4}))
run('zero_pgn_at_02', snap(p={2: 0}))
run('all3_repeat_identical', snap(p={0: 3.0, 2: 4.5}, j={0: 1.9}, m={6: 0.4}))
chk = {
  'canon_4.5_not_7.5': res['pgn_3.0_then_4.5_at_02']['hdr_pgn'] == 4.5 and res['pgn_3.0_then_4.5_at_02']['canon']['pgn'][0] == 4.5,
  'history_ignored_identical_result': (r2['cp'], r2['dispatch_hash']) == (r3['cp'], r3['dispatch_hash']),
  'legacy_equivalent_identical_result': (r2['cp'], r2['dispatch_hash']) == (r4['cp'], r4['dispatch_hash']),
  'ffj_header_1.9': res['ffj_1.9_at_01']['hdr_ffj'] == 1.9,
  'mm_header_latest': res['mm_0.4_at_04']['hdr_ffm'] == 0.4,
  'all3_headers': (res['all3_diff_rows']['hdr_pgn'], res['all3_diff_rows']['hdr_ffj'], res['all3_diff_rows']['hdr_ffm']) == (4.5, 1.9, 0.4),
  'zero_vs_empty': tuple(res['zero_pgn_at_02']['canon']['pgn']) == (0, 3) and res['zero_pgn_at_02']['cp'] != res['basis_A0']['cp'],
  'repeat_identical': (res['all3_repeat_identical']['cp'], res['all3_repeat_identical']['dispatch_hash']) == (res['all3_diff_rows']['cp'], res['all3_diff_rows']['dispatch_hash']),
}
for a, b in (('pgn_higher_6.0_at_02', 'pgn_lower_3.5_at_02'), ('ffj_higher_2.6_at_02', 'ffj_lower_1.2_at_02'), ('mm_higher_0.9_at_04', 'mm_lower_0.1_at_04')):
    chk[f'{a}_vs_{b}_differ'] = res[a]['eff_gas'] != res[b]['eff_gas'] or res[a]['cp'] != res[b]['cp']
warm = [v['wall_s'] for k, v in res.items() if k != 'basis_A0' and v['wall_s'] is not None]; warm.sort()
chk['warm_median_le_5s'] = warm[len(warm) // 2] <= 5.0
json.dump({'runs': res, 'checks': chk, 'warm_median_s': warm[len(warm) // 2]}, open(os.path.join(OUT, 'ag_cum_engine.json'), 'w'), indent=1)
print(json.dumps(chk, indent=1), 'warm median', warm[len(warm) // 2])
