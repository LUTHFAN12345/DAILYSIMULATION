import json,sys,os
D=sys.argv[1]; out=sys.argv[2]
ST="BASE_PGN30 WB09 WB09_KP72 BASE_ACT10 ACT_PGN_UP ACT_PGN_DOWN ACT_FFJ_UP ACT_FFM_DOWN KP72_DOWN ACT_PGN_2SLOT_C QA_pgn_pipe_1 QA_pep_-1 WB09_3".split()
def jl(f):
    r={}
    if os.path.exists(f):
        for l in open(f):
            l=l.strip()
            if l.startswith('{'):
                try: x=json.loads(l); r[x['sc']]=x
                except Exception: pass
    return r
P={'direct (CLI, cache dingin, tanpa pembantu)':jl(D+'/direct.md5.jsonl'),'rantai A (CLI)':jl(D+'/chainA.md5.jsonl'),'rantai B (CLI, urutan terbalik)':jl(D+'/chainB.md5.jsonl'),
   'cache hangat (ulang di akar rantai B)':jl(D+'/warm.md5.jsonl'),'rantai HTTP (pembantu aktif)':jl(D+'/http_chain.jsonl'),'Target Selesai dulu (HTTP, cold)':jl(D+'/http_tl.jsonl')}
def key(x): return (x.get('md5') or x.get('rows_md5'), round(float(x['cp']),4) if x.get('cp') is not None else None)
L=['# Path independence V11','','Setiap sel: md5 dispatch 48 row (kolom unit + Export, 3 desimal) / Cost Production. SAMA = identik dengan jalur direct.','',
   '| state | '+' | '.join(P.keys())+' | hasil |','|'+'---|'*(len(P)+2)]
ok=0; tot=0; pairs=0
for s in ST:
    ref=P['direct (CLI, cache dingin, tanpa pembantu)'].get(s); cells=[]; same=True; n=0
    for k,v in P.items():
        x=v.get(s)
        if x is None: cells.append('-'); continue
        kk=key(x); n+=1
        if ref is not None and kk!=key(ref): same=False
        cells.append('%s / %s%s'%(kk[0],kk[1],'' if ref is None or kk==key(ref) else ' **BEDA**'))
    tot+=1; ok+= same and n>=2; pairs+=n-1
    L.append('| %s | %s | %s |'%(s,' | '.join(cells),'IDENTIK (%d jalur)'%n if same else 'BERBEDA'))
L+=['','**%d/%d state identik di seluruh jalur yang dijalankan** (%d perbandingan terhadap direct).'%(ok,tot,pairs)]
open(out,'w').write('\n'.join(L)+'\n'); print('\n'.join(L[-1:]))
