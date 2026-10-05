import json,sys,hashlib
o=json.load(open(sys.argv[1])); D=o.get('data') or []
C=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','GE1','GE2','GE3','GE4','BB1','BB2','Export_PLN','Total_Gas']
txt='|'.join(','.join('%.2f'%float(r.get(c) or 0) for c in C) for r in D)
i=o.get('info') or {}; hv=((o.get('simulation_acceptance_review') or {}).get('hard_validation') or {})
def first(c):
    for k,r in enumerate(D):
        if float(r.get(c) or 0)>0.01: return k+1
def last(c):
    x=None
    for k,r in enumerate(D):
        if float(r.get(c) or 0)>0.01: x=k+1
    return x
print(json.dumps({'sig':hashlib.sha256(txt.encode()).hexdigest()[:12],'rows':len(D),'hard':hv.get('status'),'gate':(o.get('release_gate') or {}).get('status'),'cp':i.get('Cost Production (USD/MWh)'),'hr':i.get('JBBK MM Heat Rate (BTU/kWh)'),
  'first':{u:first(u) for u in ['G1','G3','G4','G6','G8','S1','S2']},'last':{u:last(u) for u in ['G1','G2','G5','S2','S1']}}))
