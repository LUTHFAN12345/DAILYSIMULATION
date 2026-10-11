import re,sys
def parse(f):
    r={};sec=None
    for l in open(f):
        if l.startswith('=='): sec=l.strip(); continue
        m=re.match(r'^(\S+) wall (\S+) hard (\S+) gate (\S+) CP (\S+) HR (\S+)',l)
        if m:
            k=(sec,m.group(1)); i=1
            while k in r: i+=1; k=(sec,m.group(1)+'#%d'%i)
            r[k]=dict(wall=float(m.group(2)),hard=m.group(3),gate=m.group(4),cp=float(m.group(5)) if m.group(5)!='None' else None)
    return r
a=parse(sys.argv[1]); b=parse(sys.argv[2])
for k in a:
    x=a[k]; y=b.get(k)
    if not y: print('MISSING',k); continue
    flag=''
    if x['gate']!=y['gate']: flag+=' GATE %s->%s'%(x['gate'],y['gate'])
    if x['cp'] and y['cp'] and y['cp']>x['cp']+1e-3 and y['gate']=='PASS': flag+=' CP+%.4f'%(y['cp']-x['cp'])
    if x['cp'] and y['cp'] and y['cp']<x['cp']-1e-3: flag+=' CP-%.4f'%(x['cp']-y['cp'])
    print('%-28s %-24s old %6s %8s %5.1fs | new %6s %8s %5.1fs%s'%(k[0][:10],k[1],x['gate'],x['cp'],x['wall'],y['gate'],y['cp'],y['wall'],flag))
