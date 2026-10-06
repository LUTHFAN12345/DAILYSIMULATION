#!/usr/bin/env python3
"""parity.py <dirA> <dirB> [cases] : bandingkan output numerik UI (Simulation Data ringkas, export, audit) antar platform.
Field waktu (t_*_s, applied_at, msg 'Waktu aktual', finalize_s, *_s) dikecualikan; selain itu harus identik."""
import json,sys,re
A,B=sys.argv[1],sys.argv[2]; cases=(sys.argv[3] if len(sys.argv)>3 else 'A A2 B C D E CM').split()
TIMEKEYS=re.compile(r'(^t_|_s$|applied_at|^at$|_at$|elapsed|duration|timestamp|^ts$|search_s|proof_s|finalize|^msg$|^requests$|^jobs$|job_id|^js_errors$|saved_at|created|updated|^id$|^entry_id$|^name$)')
def load(p):
    return json.loads(open(p).read().strip().split('\n')[-1])
def strip(x,path=''):
    if isinstance(x,dict): return {k:strip(v,path+'.'+k) for k,v in x.items() if not TIMEKEYS.search(k)}
    if isinstance(x,list): return [strip(v,path) for v in x]
    if isinstance(x,str):
        x=re.sub(r'Waktu aktual [0-9.,]+ detik','Waktu aktual * detik',x)
        x=re.sub(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?Z?','<ts>',x)
        x=re.sub(r'\b(xampp|linux)_','<plat>_',x)
        x=re.sub(r'(job|economic_review)-[0-9a-f]{8,}','<job>',x)
        return x
    return x
def diff(a,b,p='',out=None):
    if out is None: out=[]
    if type(a)!=type(b): out.append((p,a,b)); return out
    if isinstance(a,dict):
        for k in sorted(set(a)|set(b)):
            if k not in a or k not in b: out.append((p+'.'+k,a.get(k,'<missing>'),b.get(k,'<missing>')))
            else: diff(a[k],b[k],p+'.'+k,out)
    elif isinstance(a,list):
        if len(a)!=len(b): out.append((p+'[len]',len(a),len(b)))
        for i,(x,y) in enumerate(zip(a,b)): diff(x,y,f'{p}[{i}]',out)
    elif a!=b: out.append((p,a,b))
    return out
allok=True
for c in cases:
    ra,rb=load(f'{A}/{c}.json'),load(f'{B}/{c}.json')
    sa={k:strip(ra.get(k)) for k in ('result','reload','import_preview','sr_ui')}
    sb={k:strip(rb.get(k)) for k in ('result','reload','import_preview','sr_ui')}
    sa['checks']={k:(v['pass'],strip(v['evidence'])) for k,v in ra['checks'].items()}
    sb['checks']={k:(v['pass'],strip(v['evidence'])) for k,v in rb['checks'].items()}
    d=diff(sa,sb)
    res=ra.get('result') or {}
    print(f"{c}: {'IDENTIK' if not d else 'BEDA '+str(len(d))} · cp {res.get('cp')} / {(rb.get('result') or {}).get('cp')} · xls_len {res.get('xls_len')} / {(rb.get('result') or {}).get('xls_len')} · checks {sum(v['pass'] for v in ra['checks'].values())}/{len(ra['checks'])} vs {sum(v['pass'] for v in rb['checks'].values())}/{len(rb['checks'])}")
    for p,x,y in d[:12]: print('   ',p,json.dumps(x)[:150],'|',json.dumps(y)[:150])
    allok&=not d
print('PARITY', 'PASS' if allok else 'FAIL')
