#!/bin/bash
# php job per state (akar baru): <root-src> <out-dir> <SC...>
cd /home/claude/t5; SRC=$1; OUT=$2; shift 2; mkdir -p $OUT
for SC in "$@"; do S=/tmp/claude-0/rs_$(basename $OUT)_$SC; rm -rf $S; mkdir -p $S/jobs; cp $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $S/
  timeout 1500 /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $S $SC /home/claude/t5/$OUT/$SC.json 2>/dev/null | tail -1 | sed "s/^/$SC /"
  python3 -c "
import json,sys
d=json.load(open('/home/claude/t5/$OUT/$SC.json')); v=d['output']['info'].get('V8 Priority Review',{}); h=d['output']['info'].get('Headroom Priority Audit',{})
print('   V9',v.get('status'),v.get('reason'),v.get('cost_production_before'),'->',v.get('cost_production_after'),[a['candidate'] for a in v.get('applied',[])],'wall',v.get('wall_s'),'sim',v.get('candidates_simulated'),'pre',v.get('candidates_prescreened'),'rounds',v.get('rounds'),'| HPA',h.get('status'),h.get('flags_unresolved'))" 2>/dev/null
done
