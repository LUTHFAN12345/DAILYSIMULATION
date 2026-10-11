import json,sys,glob
U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
for f in sorted(glob.glob('/home/claude/ab2/bb/ttf/out_*.json')):
    d=json.load(open(f)); o=d['output']; i=o.get('info',{}); rows=o.get('data') or []
    rv=o.get('simulation_acceptance_review') or i.get('Simulation Acceptance Review') or {}
    mp=i.get('Merit Proof C1-C4 STG',{}); pb=i.get('Proof Binding',{}); rs=i.get('Run Status',{}); ie=i.get('IE Incremental Redispatch',{})
    on={}
    for u in U:
        rr=[k+1 for k,r in enumerate(rows) if float(r.get(u) or 0)>0.01]
        if rr: on[u]=f'{rr[0]}-{rr[-1]}({len(rr)})'
    print(f.split('out_')[1][:-5], 'wall',d['wall_s'],'hard',d['hard'],'gate',rv.get('status'),rv.get('blocking_reasons'),'merit',mp.get('status'),'stg',(mp.get('stg') or {}).get('status'),
          'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'),'rows',len(rows),'mode',rs.get('mode'),'cand',pb.get('candidate_hash'),'ie',ie.get('applied'),ie.get('cache'))
    print('    ',on, 'PGN',i.get('PGN Pipe Used (BBTUD)'),'gasStatus',i.get('Gas Quota Status'))
