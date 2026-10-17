import sys,json,hashlib; sys.path.insert(0,'/tmp/w/h'); import cmp
for f in sys.argv[1:]:
  r=json.load(open(f)); o=r['output']; h=hashlib.md5(json.dumps([[r2.get(u) for u in ['G1','G2','G3','G4','G5','G6','G8','G9','S1','S2','S3','BB2']] for r2 in o['data']]).encode()).hexdigest()[:12] if len(o.get('data') or [])==48 else '-'
  s=cmp.summ(f); ct=o['info'].get('Commitment Tightening') or {}
  print(f.split('/')[-1], h, s.get('cp'), s.get('hr'), s.get('publish'), s.get('hard'), s.get('events'), '| T:', ct.get('applied'), ct.get('evaluations'), ct.get('wall_s'), ct.get('stopped'), 'trunc' if ct.get('round_truncated') else '', '| wall', round(r.get('__wall',0),1))
