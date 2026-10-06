#!/usr/bin/env python3
"""fast_replay.py <base_url> <payload.json> <out_prefix> : jalur UI Fastest (run&fast=1 + exec + helpers + fast_ready arm/poll).
Mencatat timeline: job_created, claimed, ready (FASTEST_RELEASE_READY / alasan gagal), job DONE; menyimpan v12_fast_ready & hasil job."""
import json,sys,time,urllib.request,threading,os
B,P,OUT=sys.argv[1],sys.argv[2],sys.argv[3]; MAX=float(os.environ.get('RP_MAX','900'))
pl=json.load(open(P)); t0=time.time(); T={}
def get(u,timeout=30):
    with urllib.request.urlopen(B+u,timeout=timeout) as r: return r.status,r.read().decode('utf-8','replace')
def post(u,body,timeout=600):
    rq=urllib.request.Request(B+u,data=json.dumps(body).encode(),headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(rq,timeout=timeout) as r: return r.status,r.read().decode('utf-8','replace')
st,tx=post('/run.php?mode=run&fast=1',pl); d=json.loads(tx); aj=d.get('async_job') or {}; T['run_response']=round(time.time()-t0,3)
print('%.2f run -> status=%s result=%s job=%s'%(time.time()-t0,d.get('status'),d.get('result'),aj.get('job_id')),flush=True)
res={'run_response':d}
if aj.get('job_id'):
    jid,tok=aj['job_id'],aj.get('exec_token','')
    def fire(u):
        try: get(u,timeout=3600)
        except Exception as e: pass
    threading.Thread(target=fire,args=('/run.php?mode=job_exec&job=%s&token=%s'%(jid,tok),),daemon=True).start()
    for k in range(1,int(aj.get('helpers') or 0)+1):
        threading.Thread(target=fire,args=('/run.php?mode=job_help&job=%s&token=%s&slot=%d'%(jid,tok,k),),daemon=True).start()
    threading.Thread(target=fire,args=('/run.php?mode=fast_ready&arm=1&job=%s'%jid,),daemon=True).start()
    last=None; fr=None
    while time.time()-t0<MAX:
        time.sleep(0.25)
        try: st,tx=get('/run.php?mode=fast_ready&job=%s'%jid,timeout=10); r=json.loads(tx)
        except Exception as e: continue
        if r.get('claimed') and 'claimed' not in T: T['claimed']=round(time.time()-t0,3); print('%.2f claimed'%(time.time()-t0),flush=True)
        if r.get('ready'):
            T['ready']=round(time.time()-t0,3); fr=r
            print('%.2f ready RELEASE=%s reasons=%s stages=%s finalize_s=%s'%(time.time()-t0,r.get('FASTEST_RELEASE_READY'),json.dumps((r.get('fast') or {}).get('reasons')),json.dumps(r.get('stages_s')),r.get('finalize_s')),flush=True)
            break
        if r.get('job_status') in ('DONE','FAILED','CANCELLED'):
            T['job_done_before_ready']=round(time.time()-t0,3); print('%.2f job %s before ready'%(time.time()-t0,r.get('job_status')),flush=True); break
    res['fast_ready']=fr
    if fr and fr.get('FASTEST_RELEASE_READY'):
        get('/run.php?mode=job_cancel&abort=1&job=%s'%jid)
    else:
        # sama seperti UI lama: tunggu job selesai (tanpa membuat job kedua) untuk mengukur exact
        while time.time()-t0<MAX:
            time.sleep(0.5)
            st,tx=get('/run.php?mode=job_poll&job=%s&input_hash=%s'%(jid,aj.get('input_hash','')))
            j=json.loads(tx); job=j.get('job') or {}
            if job.get('status') in ('DONE','FAILED','CANCELLED'):
                T['job_done']=round(time.time()-t0,3); res['job_result']=(j.get('result') or {}).get('output') or j; print('%.2f job %s'%(time.time()-t0,job.get('status')),flush=True); break
res['timeline_s']=T
json.dump(res,open(OUT+'.json','w')); print(json.dumps(T))
