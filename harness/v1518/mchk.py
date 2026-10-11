import json,sys
T,tag=sys.argv[1],sys.argv[2]
d=json.load(open(f'/home/claude/ab3/out/{tag}.json')); o=d['output']; i=o.get('info',{}); rows=o.get('data') or []
col=lambda u:[float(r.get(u) or 0) for r in rows]
mp=i.get('Merit Proof C1-C4 STG',{}); rv=o.get('simulation_acceptance_review') or {}; pb=i.get('Proof Binding',{}); L=(i.get('SC CC Mode') or {}).get('limits',{})
res=[]
def c(n,ok,det=''): res.append((n,bool(ok),det))
c('hard_PASS',d['hard']=='PASS',d['hard']); c('gate_PASS',rv.get('status')=='PASS',rv.get('blocking_reasons'))
c('C1-C4_0',mp.get('status')=='PASS'); c('blockseq_PASS',(i.get('Block Sequence Audit') or {}).get('status')=='PASS'); c('unit_limits_hash',bool(pb.get('unit_limits_hash')))
on=lambda u:[v for v in col(u) if v>0.01]
def steps_ok(u,lo,seqs=([5,15,20,20,20,20,20],[5,15])):
    # beban < MIN hanya sah di dalam langkah startup eksak (STG Cold S1/S2 atau Additional HRSG) sejak start segmen
    g=col(u); bad=[]; st=None
    for k,v in enumerate(g):
        if v>0.01 and (k==0 or g[k-1]<=0.01): st=k
        if 0.01<v<lo-0.01:
            j=k-st; ok=any(j<len(q) and abs(v-q[j])<0.01 for q in seqs)
            if not ok: bad.append((k+1,v))
    return bad
if T in('M0','M2'):
    g=on('G6'); c('G6_SC_min_eff_5',L.get('G6',{}).get('min_effective')==5,L.get('G6')); c('G6_within_[5,31]',g and min(g)>=5-0.01 and max(g)<=31+0.01,(min(g),max(g)))
if T=='M1':
    g=on('G6'); c('G6_SC_min_eff_20',L.get('G6',{}).get('min_effective')==20,L.get('G6')); c('G6_no_load_below_20',g and min(g)>=20-0.01,min(g))
if T=='M3':
    g=on('G9'); c('G9_SC_min_eff_40',L.get('G9',{}).get('min_effective')==40,L.get('G9')); c('G9_no_load_below_40',g and min(g)>=40-0.01,min(g))
if T=='M4':
    c('G6_CC_min_eff_22',L.get('G6',{}).get('min_effective')==22,L.get('G6')); b=steps_ok('G6',22); c('G6_CC_below_22_only_startup_steps',not b,b[:5])
if T=='M5':
    c('G6_max_28',max(on('G6') or [0])<=28+0.01,max(on('G6') or [0])); c('G4_max_29',max(on('G4') or [0])<=29+0.01,max(on('G4') or [0])); c('limits_max',L.get('G6',{}).get('max')==28,L.get('G6'))
for n,ok,det in res: print(('PASS ' if ok else 'FAIL ')+T+' '+n+' '+json.dumps(det,default=str)[:200])
print(f"== {T} {tag} wall {d['wall_s']} CP {i.get('Cost Production (USD/MWh)')} cand {pb.get('candidate_hash')} limits {pb.get('unit_limits_hash')}")
