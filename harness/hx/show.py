import json,sys,glob,os
r=json.loads(open(sys.argv[1]).read().strip().split('\n')[-1])
if 'error' in r: print(r['error']); sys.exit()
for t in r['timeline']:
    if t['ev']!='poll': print(t)
print('posts',[(x['t'],x['url'],x['sha256'][:12]) for x in r['posts']])
print('run',r['run']); print('result',r['result'])
if len(sys.argv)>2:
    for d in glob.glob(sys.argv[2]+'/jobs/economic_review-*'):
        j=json.load(open(d+'/job.json')); print(d.split('/')[-1], j.get('status'), j.get('fastest_claimed'), j.get('fastest_gate_failed'))
        for p in j.get('progress',[]): print('   ',{k:v for k,v in p.items() if k!='at'})
        f=d+'/v12_fast_ready.json'
        if os.path.exists(f): x=json.load(open(f)); print('  ready',x.get('ok'),x.get('finalize_s'),x.get('stages_s'),json.dumps(x.get('fast'))[:300]); c=x.get('c4_redistribution') or {}; print('  c4',c.get('result'),c.get('wall_s'))
