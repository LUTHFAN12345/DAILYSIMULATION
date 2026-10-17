import json,sys
s=json.load(open(sys.argv[1]))
p=s['payload']; p.update(s['summary'].get('transport',{}))
json.dump(p,open(sys.argv[2],'w'))
