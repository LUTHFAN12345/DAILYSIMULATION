#!/usr/bin/env python3
"""summ.py <xampp_dir> <linux_dir> : tabel ringkas per case (checks, t_done, label, CP) untuk TARGETED_TEST_A_H.md"""
import json,sys
X,L=sys.argv[1],sys.argv[2]
names={'A':'A — SR 0, PGN 00:30 (T6)','A2':'A2 — constrained 00:30–02:00','B':'B — redistribusi tidak feasible','C':'C — T1 Fix SR 10','D':'D — T2/T3 Follow PV, grafik','E':'E — XLSX + CSV lokal, Report (Maximum Review)','CM':'CM — T1 Maximum Review'}
def ld(d,c):
    try: return json.loads(open(f'{d}/{c}.json').read().strip().split('\n')[-1])
    except Exception: return None
print('| Case | XAMPP | t XAMPP (s) | Linux | t Linux (s) | Label / keputusan | CP (USD/MWh) |')
print('|---|---|---|---|---|---|---|')
for c in ['A','A2','B','C','D','E','CM']:
    x,l=ld(X,c),ld(L,c)
    def st(r): return '—' if not r else (('PASS' if r['all_pass'] else 'FAIL')+f" ({sum(v['pass'] for v in r['checks'].values())}/{len(r['checks'])})")
    def t(r): return '—' if not r else str((r.get('run') or {}).get('t_done_s')).replace('.',',')
    res=(x or {}).get('result') or {}
    lab='PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE (terminal)' if c=='B' else (res.get('result_label') or ('FINAL OPTIMAL (gate '+str((res.get('gate') or {}).get('status'))+')'))
    cp=res.get('cp'); cp='—' if c=='B' or cp is None else str(cp).replace('.',',')
    print(f'| {names[c]} | {st(x)} | {t(x)} | {st(l)} | {t(l)} | {lab} | {cp} |')
