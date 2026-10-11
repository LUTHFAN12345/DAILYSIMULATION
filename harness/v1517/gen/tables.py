# tables.py : tabel markdown dari hasil regresi final (scen3.jsonl, fxfinal/*, ui16/*.json)
import json, os, sys, glob
OP = '/home/claude/ab2/op'
sys.path.insert(0, OP + '/gen')
from ietab import row as ierow

def md(head, rows):
    out = ['| ' + ' | '.join(head) + ' |', '|' + '|'.join(['---'] * len(head)) + '|']
    for r in rows: out.append('| ' + ' | '.join('' if x is None else str(x) for x in r) + ' |')
    return '\n'.join(out)

def scen():
    S = {}
    for l in open(OP + '/scen3.jsonl'):
        d = json.loads(l); S[d['case']] = d
    return S

def scen_table(prefixes):
    S = scen(); rows = []
    for k in sorted(S, key=lambda x: (x.split('_')[0].rstrip('0123456789'), int(''.join(c for c in x.split('_')[0] if c.isdigit()) or 0))):
        if not any(k.startswith(p) for p in prefixes): continue
        d = S[k]; f = d.get('final', {}); C = f.get('C') or {}
        rows.append([k, d.get('fuel') or '–', d.get('step1_s'), d.get('step2_s') or '–', d.get('total_s'), f.get('hard'), f.get('gate'), f.get('cp'), f.get('hr'),
                     sum(int(v or 0) for v in C.values()) if C else '–', f.get('stg'), f.get('dist_cont'), ', '.join(f.get('blk') or []) or '–'])
    return md(['Kasus', 'Bahan bakar', 'Langkah 1 (s)', 'Langkah 2 (s)', 'Total (s)', 'Hard', 'Gate', 'CP', 'HR', 'C1–C4 FAIL', 'STG', 'Kontinuitas Dist.', 'Blocker'], rows)

def fx(sub, name):
    f = f'{OP}/fxfinal/{sub}/{name}.json'
    return json.load(open(f)) if os.path.isfile(f) else None

def fx_row(sub, name, label=None):
    d = fx(sub, name)
    if d is None: return [label or name, 'TIDAK ADA'] + [''] * 8
    o = d['output']; i = o['info']; mp = i.get('Merit Proof C1-C4 STG') or {}; C = sum(int((mp.get(c) or {}).get('fail') or 0) for c in ['C1','C2','C3','C4'])
    ca = i.get('Distillate Continuity Audit') or {}; w = i.get('IE Incremental Redispatch') or {}
    return [label or name, d.get('wall_s'), d.get('hard'), (o.get('release_gate') or {}).get('status'), i.get('Cost Production (USD/MWh)'), i.get('JBBK MM Heat Rate (BTU/kWh)'),
            C, (mp.get('stg') or {}).get('status'), ca.get('status'), w.get('cache') or '–']

def ui(name):
    f = f'{OP}/ui16/{name}.json'
    return json.load(open(f)) if os.path.isfile(f) else None

if __name__ == '__main__':
    print(scen_table(['S', 'IE', 'CO']))
