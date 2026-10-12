import json,sys,hashlib
def load(f):
    d=json.load(open(f));o=d['output'];i=o['info']
    keys=['Cost Production (USD/MWh)','JBBK MM Heat Rate (BTU/kWh)','Total Gas Used (BBTUD)','PGN Pipe Used (BBTUD)','Distillate Used (l)','Distillate Mix per Unit (%)','Startup Events (GTG)','Merit Proof C1-C4 STG','Startup Timing Proof','Proof Binding','Gas Quota Status','PLN Export Compliance']
    I={k:i.get(k) for k in keys}
    if isinstance(I.get('Proof Binding'),dict): I['Proof Binding'].pop('validated_at',None)
    for c in ((I.get('Startup Timing Proof') or {}).get('candidates_tried') or []):
        if isinstance(c,dict): c.pop('s',None)
    return d['hard'], o['data'], I, o.get('simulation_acceptance_review',{}).get('status')
    return d['hard'], o['data'], {k:i.get(k) for k in keys}, o.get('simulation_acceptance_review',{}).get('status')
names=sys.argv[1:]; R=[load(f) for f in names]
h=lambda x: hashlib.sha256(json.dumps(x,sort_keys=True).encode()).hexdigest()[:16]
for n,(hd,data,info,gate) in zip(names,R): print(n.split('/')[-1],'hard',hd,'gate',gate,'rows',len(data),'data48',h(data),'info',h(info),'CP',info['Cost Production (USD/MWh)'],'HR',info['JBBK MM Heat Rate (BTU/kWh)'])
ok=all(r[1]==R[0][1] and r[2]==R[0][2] and r[0]==R[0][0] for r in R)
if not ok:
    for k in R[0][2]:
        if any(r[2][k]!=R[0][2][k] for r in R): print('DIFF info',k)
    for ri in range(48):
        if any(r[1][ri]!=R[0][1][ri] for r in R): print('DIFF row',ri+1); break
print('INCREMENTAL VS FULL PARITY:', 'PASS' if ok else 'INCREMENTAL_PARITY_FAIL')
