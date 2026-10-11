import json,hashlib,sys
U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
for t in sys.argv[1:]:
    try: d=json.load(open(f'/home/claude/ab3/out/{t}.json'))
    except Exception as e: print(t,'ERR',e); continue
    o=d['output']; i=o.get('info',{})
    fp=hashlib.md5(json.dumps([[r.get(u) for u in U] for r in o.get('data') or []]).encode()).hexdigest()[:16]
    print(t, d.get('wall_s'), d.get('hard'), i.get('Cost Production (USD/MWh)'), i.get('JBBK MM Heat Rate (BTU/kWh)'), fp, (i.get('Merit Proof C1-C4 STG') or {}).get('status'), (o.get('simulation_acceptance_review') or {}).get('status'))
