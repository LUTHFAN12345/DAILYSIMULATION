import json,hashlib,sys
U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
def load(p):
    d=json.load(open(p)); o=d.get('output') or (d.get('result') or {}).get('output') or d
    return o
def summ(o):
    i=o['info']; pb=i.get('Proof Binding') or {}
    fp=hashlib.md5(json.dumps([[r.get(u) for u in U] for r in o['data']]).encode()).hexdigest()[:16]
    on={u:[k+1 for k,r in enumerate(o['data']) if float(r.get(u) or 0)>0.01] for u in U}
    com={u:(v[0],v[-1],len(v)) if v else None for u,v in on.items()}
    return {'fp':fp,'cp':i.get('Cost Production (USD/MWh)'),'hr':i.get('JBBK MM Heat Rate (BTU/kWh)'),'commit':hashlib.md5(json.dumps(com).encode()).hexdigest()[:12],
            'merit':(i.get('Merit Proof C1-C4 STG') or {}).get('status'),'blockseq':(i.get('Block Sequence Audit') or {}).get('status'),
            'proof':{k:v for k,v in pb.items() if 'hash' in k}}
a=summ(load(sys.argv[1])); b=summ(load(sys.argv[2]))
for k in a:
    if k=='proof':
        for h in sorted(set(a[k])|set(b[k])): print(('SAME ' if a[k].get(h)==b[k].get(h) else 'DIFF ')+h, a[k].get(h), b[k].get(h))
    else: print(('SAME ' if a[k]==b[k] else 'DIFF ')+k, a[k], b[k])
