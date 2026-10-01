#!/bin/bash
# Fastest UI: Change Over OFF (WB09_3) dan ON (WB09_3 B2->B1 sim/sim Cold). Arg: <src> <port> <outdir>
cd /home/claude/t5; S=$1; P=$2; O=$3; mkdir -p $O; R=/tmp/claude-0/fastroot_$P; N=/home/claude/.npm-global/lib/node_modules
for c in "OFF sw/base_WB09_3.json" "ON sw/base_WB09_3.json"; do set -- $c
  rm -rf $R; mkdir -p $R/jobs; cp $S/*.php $R/
  python3 -c "
import json,sys; d=json.load(open('/home/claude/t5/integ/'+sys.argv[1])); [d.pop(k) for k in list(d) if k.startswith('_')]; json.dump(d,open(sys.argv[2]+'/input_data.json','w'))" $2 $R
  (setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 900 node integ/fastest_ui.js http://127.0.0.1:$P $O/FASTEST_UI_CO_$1.md "Change Over $1 (WB09_3)" 300 $(echo $1 | tr A-Z a-z) 2>&1 | grep -E "PASS|FAIL" | cut -c1-260
  bash v4/kill_port.sh $P >/dev/null 2>&1
done
