import json,sys,collections
def st(f):
  r=json.load(open(f)); o=r.get('output',r); i=o.get('info') or {}
  d=collections.OrderedDict(); n=collections.Counter(); sims=collections.Counter()
  for s in (i.get('Start Priority Stage Profile') or {}).get('stages',[]):
    k=s['stage']; d[k]=d.get(k,0)+s['s']; n[k]+=1; sims[k]+=s.get('sims') or 0
  return d,n,sims,(r.get('__bench') or {}).get('wall_s') or r.get('wall_s'),i.get('Cost Production (USD/MWh)')
A=st(sys.argv[1]); B=st(sys.argv[2])
print('wall',A[3],B[3],'CP',A[4],B[4])
ks=list(dict.fromkeys(list(A[0])+list(B[0])))
rows=[(k,A[0].get(k,0),B[0].get(k,0),A[1][k],B[1][k],A[2][k],B[2][k]) for k in ks]
for r in sorted(rows,key=lambda r:-(r[2]-r[1])): print('%-40s %7.2f %7.2f %+7.2f  n %d/%d sims %d/%d'%(r[0],r[1],r[2],r[2]-r[1],r[3],r[4],r[5],r[6]))
