import sys,re,collections
names={}; lc=collections.Counter(); cur=None; aftercall=False; target=sys.argv[2]
for line in open(sys.argv[1],errors='replace'):
    if line.startswith('fn='):
        m=re.match(r'fn=\((\d+)\)(?: (.*))?',line.strip()); i=m.group(1)
        if m.group(2): names[i]=m.group(2)
        cur=names.get(i,i); aftercall=False; continue
    if line.startswith('cfn='):
        m=re.match(r'cfn=\((\d+)\)(?: (.*))?',line.strip())
        if m.group(2): names[m.group(1)]=m.group(2)
        continue
    if line.startswith('calls='): aftercall=True; continue
    if line[:1].isdigit():
        p=line.split()
        if aftercall:
            if cur==target: lc[('call',int(p[0]))]+=int(p[1])
            aftercall=False
        elif cur==target: lc[('self',int(p[0]))]+=int(p[1])
tot=sum(lc.values())
for k,c in lc.most_common(25): print(f"{c/tot*100:6.2f}% {k}")
