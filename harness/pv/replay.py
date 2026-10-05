#!/usr/bin/env python3
"""replay.py <base_url> <payload.json> [out_result.json] -- start mode=run, fire exec+helpers, poll job to terminal; print summary"""
import json,sys,time,urllib.request,threading
B,P=sys.argv[1],sys.argv[2]; OUT=sys.argv[3] if len(sys.argv)>3 else None
pl=json.load(open(P)); t0=time.time()
def get(u,timeout=30):
    with urllib.request.urlopen(B+u,timeout=timeout) as r: return r.status,r.read().decode('utf-8','replace')
def post(u,body,timeout=600):
    rq=urllib.request.Request(B+u,data=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(rq,timeout=timeout) as r: return r.status,r.read().decode('utf-8','replace')
st,tx=post('/run.php?mode=run'+('&fast=1' if '--fast' in sys.argv else ''),pl); d=json.loads(tx)
aj=d.get('async_job') or {}
print('%.1f run -> %s status=%s result=%s job=%s'%(time.time()-t0,st,d.get('status'),d.get('result'),aj.get('job_id')),flush=True)
res=d
if aj.get('job_id'):
    jid,tok=aj['job_id'],aj.get('exec_token','')
    def fire(u):
        try: get(u,timeout=3600)
        except Exception as e: pass
    threading.Thread(target=fire,args=('/run.php?mode=job_exec&job=%s&token=%s'%(jid,tok),),daemon=True).start()
    for k in range(1,int(aj.get('helpers') or 0)+1):
        threading.Thread(target=fire,args=('/run.php?mode=job_help&job=%s&token=%s&slot=%d'%(jid,tok,k),),daemon=True).start()
    last=None
    while time.time()-t0<float(__import__('os').environ.get('RP_MAX','600')):
        time.sleep(0.5)
        st,tx=get('/run.php?mode=job_poll&job=%s&input_hash=%s'%(jid,aj.get('input_hash','')))
        j=json.loads(tx); job=j.get('job') or {}
        s=(job.get('status'),job.get('current_step'))
        if s!=last: print('%.1f %s %s %s'%(time.time()-t0,job.get('status'),job.get('current_step'),job.get('percent')),flush=True); last=s
        if job.get('status') in ('DONE','FAILED','CANCELLED'):
            res=(j.get('result') or {}).get('output') or j; break
o=res; i=o.get('info') or {}
hv=((o.get('simulation_acceptance_review') or {}).get('hard_validation') or {})
print('T=%.1f status=%s result=%s rows=%d hard=%s viol=%s'%(time.time()-t0,o.get('status'),o.get('result'),len(o.get('data') or []),hv.get('status'),json.dumps(hv.get('violations'))[:600]))
for k in ['Gas Shortage Action','Gas Shortage (BBTUD)','Residual Gas Shortage (BBTUD)','Distillate Used (l)','Distillate per unit (l)','Distillate Mix per Unit (%)','Cost Production (USD/MWh)','JBBK MM Heat Rate (BTU/kWh)','Total Gas Used (BBTUD)','Fuel Action Reconciliation']:
    print('  ',k,json.dumps(i.get(k))[:300])
print('  msg',str(o.get('message'))[:400])
D=o.get('data') or []
for u in ['G1','G3','G6','G8','G9','S1','S2','S3']:
    print('  %-3s'%u,' '.join('%d'%round(float(r.get(u) or 0)) for r in D))
if OUT: json.dump(o,open(OUT,'w'))
