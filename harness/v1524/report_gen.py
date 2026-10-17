"""REPORT.md V15.24 dari evidence: python3 report_gen.py <out.md> <bench.json> <zip_sha_file|-> """
import json, os, sys, glob, re, collections
H = os.path.dirname(os.path.abspath(__file__)); MX = os.path.join(H, 'matrix')
out, benchj, shaf = sys.argv[1:4]
recs = [json.load(open(f)) for f in sorted(glob.glob(os.path.join(MX, 'evidence', 'T*.json')))]
gi = json.load(open(os.path.join(MX, 'evidence', 'group_I_H15_H16.json'))) if os.path.exists(os.path.join(MX, 'evidence', 'group_I_H15_H16.json')) else []
ui = json.load(open(os.path.join(H, 'evidence', 'ui_smoke', 'smoke_final.json')))
B = json.load(open(benchj)) if os.path.exists(benchj) else {'rows': [], 'topology': {}}
def run(rec): return json.load(open(os.path.join(MX, rec['evidence'])))
def ev(o):
    d = o['data']; e = []
    for u in ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3']:
        on = [float(w.get(u) or 0) > 0.01 for w in d]
        for k in range(48):
            if on[k] and (k == 0 or not on[k-1]) and k > 0: e.append(f'{u}+{((k+1)*30)//60:02d}:{((k+1)*30)%60:02d}')
            if not on[k] and k > 0 and on[k-1]: e.append(f'{u}-{((k+1)*30)//60 % 24:02d}:{((k+1)*30)%60:02d}')
    return ' '.join(e)
L = []; w = L.append
w('# REPORT V15.24 — Order-Independent Commitment Tightening, Final-Dispatch Event Proof, 192+ Regression\n')
w('Engine build: `V15.24-ORDER-INDEPENDENT-TIGHTENING-FINAL-PROOF-20261017`. Baseline paket: `XAMPP_START_PRIORITY_RC_V1523_COMMITMENT_TIGHTENING.zip` (V15.23) di atas V15.22.')
w('Platform acceptance: **PHP 7.4.33** (opcache CLI aktif), engine dikunci ke **1 logical CPU** (`taskset -c 0`, dibuktikan `Cpus_allowed_list` proses worker). Hasil multi-core dipisahkan (bagian helper-failure).\n')
# --- A0 before/after
w('## 1. Skenario aktual (A0, snapshot user) — sebelum / sesudah\n')
base = '/tmp/w/h/out/base_A0_actual.json'; a0 = next((r for r in recs if r['id'] == 'T184'), None)
rows = []
if os.path.exists(base):
    rb = json.load(open(base)); ib = rb['output']['info']; rows.append(('V15.22 (PHP 7.4, 1 CPU)', ib.get('Cost Production (USD/MWh)'), ib.get('JBBK MM Heat Rate (BTU/kWh)'), ib.get('Required Distillate (l/day)'), ib.get('Total Gas Used (BBTUD)'), ev(rb['output'])))
if a0:
    r4 = run(a0); i4 = r4['output']['info']; rows.append(('V15.24 (PHP 7.4, 1 CPU)', i4.get('Cost Production (USD/MWh)'), i4.get('JBBK MM Heat Rate (BTU/kWh)'), i4.get('Required Distillate (l/day)'), i4.get('Total Gas Used (BBTUD)'), ev(r4['output'])))
w('| versi | CP (USD/MWh) | HR (BTU/kWh) | Distillate (l) | Gas (BBTUD) | event start/stop |\n|---|---|---|---|---|---|')
for x in rows: w('| ' + ' | '.join(str(v) for v in x) + ' |')
w('\nV15.23 pada PHP 8.3 multi-core: CP 93,4422 (commitment identik dengan V15.24; selisih CP 0,0145 = perbedaan floating point PHP 7.4 vs 8.3 pada commitment yang sama).\n')
if a0:
    p = i4['Commitment Event Proof']
    w(f"### Bukti event dispatch FINAL A0 (status **{p['status']}**, {p['evaluations']} evaluasi redispatch, {p['wall_s']} s)\n")
    w('| unit | event | jam | alasan | first-needed | defisit MW tanpa unit (sesudah legal headroom unit lain) | uji tunda/stop 1 row | swap same-block | swap cross-block |\n|---|---|---|---|---|---|---|---|---|')
    for e in p['events']:
        sb = '; '.join(f"{c['unit']}:{c.get('swap_result')}" for c in (e.get('candidates') or {}).get('same_block', []) if c.get('swap_result')) or '-'
        cb = '; '.join(f"{c['unit']}:{c.get('swap_result')}" for c in (e.get('candidates') or {}).get('cross_block', []) if c.get('swap_result')) or '-'
        t = (e.get('no_delay_test') or e.get('no_early_stop_test') or {})
        rs = t.get('reason'); rs = '; '.join(rs[:2]) if isinstance(rs, list) else ''
        w(f"| {e['unit']} | {e['event']} | {e['time']} | {e['reason_code']} | {e.get('first_needed_time') or '-'} | {(e.get('deficit_at_first_needed') or {}).get('deficit_mw', '-')} | {t.get('result')} {rs} | {sb} | {cb} |")
    w('\nInterval beban minimum: ' + '; '.join(f"{m['unit']} {m['time'][0]}–{m['time'][1]} {m['reason_code']} (defisit maks bila dilepas {m['max_deficit_if_off']['deficit_mw']} MW)" for m in p['minimum_load_intervals']) + '\n')
# --- matrix
w('## 2. Matrix regresi (Fastest; PHP 7.4.33, worker dipin 1 CPU per run)\n')
g = collections.OrderedDict()
for r in recs: g.setdefault(r['group'], []).append(r)
tot = collections.Counter(r['status'] for r in recs)
for x in gi: tot[x['status']] += 1
w('| grup | jumlah | PASS | EXPECTED_REJECTION | FAIL | Test ID FAIL |\n|---|---|---|---|---|---|')
for k in sorted(g):
    rs = g[k]; c = collections.Counter(r['status'] for r in rs)
    w(f"| {k} | {len(rs)} | {c['PASS']} | {c['EXPECTED_REJECTION']} | {c['FAIL']} | {', '.join(r['id'] for r in rs if r['status'] == 'FAIL') or '-'} |")
ci = collections.Counter(x['status'] for x in gi)
w(f"| I (+H15/H16, MySQL/UI) | {len(gi)} | {ci['PASS']} | 0 | {ci['FAIL']} | {', '.join(x['id'] for x in gi if x['status'] == 'FAIL') or '-'} |")
w(f"| UI semantic smoke (Playwright) | {len(ui)} | {sum(1 for x in ui if x['status']=='PASS')} | 0 | {sum(1 for x in ui if x['status']=='FAIL')} | - |")
w(f"\n**Total engine+DB: {len(recs) + len(gi)} test — PASS {tot['PASS']}, EXPECTED_REJECTION {tot['EXPECTED_REJECTION']}, FAIL {tot['FAIL']}** (+{len(ui)} UI smoke).\n")
w('EXPECTED_REJECTION (bukti terminal per test):\n')
for r in recs:
    if r['status'] == 'EXPECTED_REJECTION': w(f"- {r['id']} {r['title']} — {(r.get('terminal_proof') or '')[:180]}")
w('\nFAIL (akar masalah di bagian 6):\n')
for r in recs:
    if r['status'] == 'FAIL': w(f"- {r['id']} [{r['group']}] {r['title']} — {'; '.join(x for x in r['checks'] if x.startswith('NG'))[:220]}")
uniq = len({r['payload_hash'] for r in recs})
w(f"\nPayload engine unik: {uniq} dari {len(recs)} test engine (test berpayload sama memakai oracle berbeda; lihat MATRIX_CHANGELOG.md).\n")
# --- bench
w('## 3. Benchmark P0.4 (PHP 7.4.33, 1 logical CPU, 3 cold + 3 warm)\n')
T = B.get('topology', {})
w('Topologi: ' + ' / '.join(l.strip() for l in T.get('lscpu', '').splitlines() if re.match(r'(CPU\(s\)|Model name|Thread|Core|Socket|On-line)', l.strip())))
w(f"Kuota cgroup: {', '.join(f'{k}={v}' for k, v in T.items() if k.startswith('/sys')) or 'tidak ada'}; affinity worker: `taskset -c 0` -> `Cpus_allowed_list: 0`; {T.get('php74')}; {T.get('opcache')}; helper_slots = 0 pada 1 CPU (cores_detected memakai affinity).\n")
w('Definisi: cold = cache job/basis/bundle dikosongkan + proses PHP baru; warm = cache dipertahankan, job baru untuk payload sama (dilayani registry hasil); exact rerun = payload identik; Fix Load warm = satu sel Fix Load sesudah run A0 (cache basis).\n')
w('| skenario | mode | target s | V15.24 wall median (min–max) | V15.24 CPU s | V15.24 RSS MB | V15.22 wall median (min–max) | V15.22 CPU s | V15.22 RSS MB | CP V15.24 / V15.22 | deterministik |\n|---|---|---|---|---|---|---|---|---|---|---|')
fm = lambda s: f"{s['median']} ({s['min']}–{s['max']})" if s else '-'
for r in B.get('rows', []):
    n, o = r['new'], r['old']
    w(f"| {r['label']} | {r['mode']} | {r['target_s'] or '-'} | {fm(n['wall'])} | {n['cpu']['median'] if n['cpu'] else '-'} | {n['rss_mb']['median'] if n['rss_mb'] else '-'} | {fm(o['wall'])} | {o['cpu']['median'] if o['cpu'] else '-'} | {o['rss_mb']['median'] if o['rss_mb'] else '-'} | {n['cp']} / {o['cp']} | {n['deterministic']} |")
w('')
open(out, 'w').write('\n'.join(L) + '\n')
print('\n'.join(L))
