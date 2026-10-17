import json,sys,hashlib
def strip(x):
    if isinstance(x,dict): return {k:strip(v) for k,v in x.items() if k not in ('wall_s','s','at_s','elapsed_s','job_started_at','job_finished_at','engine_s','task_busy_s','task_span_s','tasks_by_process','_request_id','request_id','job_id','generated_at','created_at','finished_at','timing','profile')}
    if isinstance(x,list): return [strip(v) for v in x]
    return x
def h(o): return hashlib.md5(json.dumps(strip(o),sort_keys=True).encode()).hexdigest()[:10]
rs=[json.load(open(f)) for f in sys.argv[1:]]
keys=['data','Commitment Event Proof','Commitment Tightening','Cost Production (USD/MWh)','JBBK MM Heat Rate (BTU/kWh)']
for f,r in zip(sys.argv[1:],rs):
    o=r['output']; i=o['info']
    row=[h(o['data'])]+[h(i.get(k)) for k in keys[1:]]+[h(o.get('release_gate')), h(r.get('acceptance') or o.get('acceptance'))]
    print(f.split('/')[-1], row)
