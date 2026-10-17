import json,sys,os
U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3']
MIN={'G1':20,'G2':20,'G3':20,'G4':20,'G5':20,'G6':20,'G8':65,'G9':65,'G7':30,'G10':30}
LVL={'G8':1,'G9':1,'G2':2,'G5':2,'G1':2,'G6':3,'G4':3,'G3':3,'G10':5,'G7':6}
MAX={'G1':31,'G2':31,'G3':31,'G4':31,'G5':31,'G6':31,'G8':105,'G9':105,'G7':105,'G10':105}
def tm(r): m=(r*30)%1440; return '%02d:%02d'%(m//60,m%60)
def summ(path):
    if not os.path.exists(path): return None
    r=json.load(open(path)); o=r.get('output',r); d=o.get('data') or []; i=o.get('info',{})
    if len(d)!=48: return {'status':o.get('status') or r.get('status'),'wall':r.get('__wall'),'rows':len(d),'cert':(i.get('Fastest Terminal Certificate') or {}).get('code')}
    ev=[];hrs={};minr=0;starts=0;inv=0
    for u in U:
        prev=0;on=0
        for k,row in enumerate(d):
            v=float(row.get(u) or 0)
            if v>0.01: on+=1
            if v>0.01 and prev<=0.01 and k>0: ev.append(f'{u}+{tm(k+1)}'); starts+= u[0]=='G'
            if v<=0.01 and prev>0.01: ev.append(f'{u}-{tm(k+1)}')
            if u in MIN and v>0.01 and v<=MIN[u]+0.5: minr+=1
            prev=v
        hrs[u]=on/2
    for k,row in enumerate(d):
        for u in LVL:
            v=float(row.get(u) or 0)
            if v<=0.01: continue
            hd=sum(MAX[h]-float(row.get(h) or 0) for h in LVL if LVL[h]<LVL[u] and float(row.get(h) or 0)>0.01)
            if hd>=v-1e-6 and LVL[u]>=2: inv+=1
    hv=r.get('hard_validation') or {}
    return {'cp':i.get('Cost Production (USD/MWh)'),'hr':i.get('JBBK MM Heat Rate (BTU/kWh)'),'gas':i.get('Total Gas Used (BBTUD)'),'dist':i.get('Distillate Used (l)'),
      'exp':i.get('Daily PLN Exp (MWh)'),'hard':hv.get('status'),'publish':r.get('publish_allowed'),'wall':round(r.get('__wall') or 0,1),'starts':starts,'minrows':minr,'inv_rows':inv,
      'events':' '.join(ev),'hours':{k:v for k,v in hrs.items() if v and k[0]=='G'}}
if __name__=='__main__':
    names=sorted({f[8:-5] for f in os.listdir('/tmp/w/reg') if f.startswith('out_old_')}|{f[8:-5] for f in os.listdir('/tmp/w/reg') if f.startswith('out_new_')})
    for n in names:
        print('=====',n)
        for t in ['old','new']:
            s=summ(f'/tmp/w/reg/out_{t}_{n}.json'); print(' ',t,json.dumps(s))
