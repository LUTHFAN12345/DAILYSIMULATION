# ietab.py : tabel IE0-IE15 dari output dumpjob (rantai warm CLI + matriks skenario)
import json, sys
def on(o):
    r = {}
    for U in ['G1','G2','G3','G4','G5','G6','G8','G9']:
        rr = [k + 1 for k, x in enumerate(o['data']) if float(x.get(U, 0) or 0) > 0.01]
        if rr: r[U] = f'{rr[0]}-{rr[-1]}'
    return r
def row(name, f, prev_on=None):
    d = json.load(open(f)); o = d['output']; i = o['info']; w = i.get('IE Incremental Redispatch') or {}; a = i.get('IE Adjustment') or {}
    mp = i.get('Merit Proof C1-C4 STG') or {}; C = sum(int((mp.get(c) or {}).get('fail') or 0) for c in ['C1','C2','C3','C4'])
    D = o['data']; adjr = [k + 1 for k, x in enumerate(D) if abs(float(x.get('IE_Adj') or 0)) > 1e-9]
    cons = all(abs(float(x['IE_Pred']) + float(x['IE_Adj']) - float(x['IE'])) < 1e-6 for x in D)
    gas = f"{i.get('Total Gas Used (BBTUD)')}/{i.get('Total Gas Quota (BBTUD)')}"
    dist = i.get('Distillate Fuel Total (l)') or 0; lng = i.get('Added LNG (BBTUD)') or 0
    return {'case': name, 'adj_rows': f'{adjr[0]}–{adjr[-1]} ({len(adjr)})' if adjr else '–', 'pred+adj=eff': 'OK' if cons else 'SALAH',
            'window': w.get('affected_windows'), 'cache': w.get('cache'), 'alasan': w.get('alasan_invalidasi'), 'ffv': w.get('first_fully_valid_s'),
            'wall': d.get('wall_s'), 'hard': d.get('hard'), 'gate': (o.get('release_gate') or {}).get('status'), 'C': C, 'stg': (mp.get('stg') or {}).get('status'),
            'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'), 'gas': gas, 'dist_l': dist, 'lng': lng, 'on': on(o), 'on_before': w.get('commitment_sebelum'), 'mode': (i.get('Run Status') or {}).get('mode')}
if __name__ == '__main__':
    for a in sys.argv[1:]:
        n, f = a.split('=', 1); print(json.dumps(row(n, f), ensure_ascii=False))
