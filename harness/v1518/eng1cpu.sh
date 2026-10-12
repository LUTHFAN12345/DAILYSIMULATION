#!/bin/bash
# eng.sh <srcdir> <out> : cold serial Fastest job on one CPU
S=$1; rm -rf "$S/jobs" "$S/.co_cache"; mkdir -p "$S/jobs"
taskset -c 2 env $EXTRA /usr/local/bin/php74 /home/claude/hf/dj_async.php "$S" /tmp/claude-0/rtpt/pay.json "$2" >/dev/null
python3 -I - "$2" <<'P'
import json,sys,hashlib
d=json.load(open(sys.argv[1]));o=d['output'];i=o['info'];U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
fp=hashlib.md5(json.dumps([[r.get(u) for u in U] for r in o['data']],separators=(',',':')).encode()).hexdigest()[:16]
rs=i.get('Run Status',{});print('wall',d['wall_s'],'hard',d['hard'],'rows',len(o['data']),'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'),'core',rs.get('core_simulations'),'fp',fp)
print(' '.join(f"{p['phase']}={p['delta_s']}" for p in rs.get('phase_timeline') or [] if p['delta_s']>0.2))
P
