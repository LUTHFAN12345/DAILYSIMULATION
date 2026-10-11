import json,sys
U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
for t in sys.argv[1:]:
    try: d=json.load(open(f'/home/claude/ab3/out/{t}.json'))
    except Exception as e: print(t,'ERR',e); continue
    o=d['output']; i=o.get('info',{}); rows=o.get('data') or []
    rv=o.get('simulation_acceptance_review') or {}; mp=i.get('Merit Proof C1-C4 STG',{})
    on={}
    for u in U:
        rr=[k+1 for k,r in enumerate(rows) if float(r.get(u) or 0)>0.01]
        if rr: on[u]=f'{rr[0]}-{rr[-1]}({len(rr)})'
    print(t,'wall',d['wall_s'],'hard',d['hard'],'gate',rv.get('status'),rv.get('blocking_reasons'),'merit',mp.get('status'),{c:(mp.get(c) or {}).get('fail') for c in ('C1','C2','C3','C4')},(mp.get('stg') or {}).get('status'),'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'))
    print('   ',on); 
    if d.get('viol'): print('    viol',[v for v in d['viol']][:6])
