import sys,re,collections
names={}; self=collections.Counter(); incl=collections.Counter(); cnt=collections.Counter()
cur=None; aftercall=False; cfn=None
for line in open(sys.argv[1],errors='replace'):
    if line.startswith('fn='):
        m=re.match(r'fn=\((\d+)\)(?: (.*))?',line.strip()); i=m.group(1)
        if m.group(2): names[i]=m.group(2)
        cur=names.get(i,i); cnt[cur]+=1; aftercall=False; continue
    if line.startswith('cfn='):
        m=re.match(r'cfn=\((\d+)\)(?: (.*))?',line.strip()); i=m.group(1)
        if m.group(2): names[i]=m.group(2)
        cfn=names.get(i,i); continue
    if line.startswith('calls='): aftercall=True; continue
    if line[:1].isdigit() or line[:1] in '+-*':
        p=line.split()
        if len(p)>=2:
            c=int(p[1])
            if aftercall: incl[(cur,cfn)]+=c; aftercall=False
            else: self[cur]+=c
tot=sum(self.values()); print('total',tot)
for f,c in self.most_common(40): print(f"{c/tot*100:6.2f}% {cnt[f]:8d} {f}")
