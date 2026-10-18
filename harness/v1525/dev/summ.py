import json,sys
r=json.load(open(sys.argv[1])); o=r.get('output') or {}; i=o.get('info') or {}; d=o.get('data') or []
ev=[]
for u in ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3']:
    on=[float(w.get(u) or 0)>0.01 for w in d]
    for k in range(1,len(on)):
        if on[k] and not on[k-1]: ev.append(f'{u}+{((k+1)*30)//60:02d}:{((k+1)*30)%60:02d}')
        if not on[k] and on[k-1]: ev.append(f'{u}-{((k+1)*30)//60%24:02d}:{((k+1)*30)%60:02d}')
print('status',o.get('status'),'pub',r.get('publish_allowed'),'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'),'daily',i.get('Daily PLN Exp (MWh)'),'gas',i.get('Total Gas Used (BBTUD)'),'dist',i.get('Required Distillate (l/day)'))
print('events',' '.join(ev))
print('msg',str(o.get('message') or r.get('error') or '')[:600])
hv=(r.get('hard_validation') or {}); print('hard',hv.get('status'),[ (v.get('type') if isinstance(v,dict) else v[0], (v.get('message') if isinstance(v,dict) else v[1])[:120]) for v in (hv.get("violations") if isinstance(hv.get("violations"),list) else [])[:4] if isinstance(v,(dict,list))])
