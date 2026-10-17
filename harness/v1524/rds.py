import json,collections,sys
r=json.load(open(sys.argv[1]));ct=r['output']['info']['Commitment Tightening']
for rd in ct['rounds']: print({k:v for k,v in rd.items() if k not in('before','after')})
c=collections.Counter((m['round'],m['kind'],m['result']) for m in ct['moves'])
for k,v in sorted(c.items()): print(k,v)
print('evals',ct['evaluations'],'wall',ct['wall_s'],'hits',ct['cache_hits'],ct['stopped'],ct['round_truncated'])
