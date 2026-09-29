#!/bin/bash
# V11 helper: satu state pada akar baru. Arg: <src> <tag> <SC> [snap|none]
cd /home/claude/t5; SRC=$1; TAG=$2; SC=$3; SN=${4:-none}; S=/tmp/claude-0/v11one_$TAG; rm -rf $S; mkdir -p $S/jobs
cp -L $SRC/{run,worker02,worker_functions,index}.php $S/
if [ "$SN" = snap ]; then SRC=$S SNAP=/home/claude/t5/v10/snap_act bash v3/prep_ui.sh $S >/dev/null 2>&1; fi
timeout 1500 /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $S $SC /tmp/claude-0/v11one_$TAG.json 2>/dev/null | tail -1
python3 - /tmp/claude-0/v11one_$TAG.json <<'PY'
import json,sys
d=json.load(open(sys.argv[1])); o=d['output']; i=o.get('info',{}); rg=o.get('release_gate',{})
c=i.get('V11 Candidate Comparison') or {}; k=i.get('V11 Candidate Counters') or {}
print('rows',len(o.get('data',[])),'release',rg.get('release_allowed'),rg.get('blocking_reasons'),'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'),
 '| band min',c.get('cp_min'),'win',c.get('winner'),'| cnt',k.get('candidates_checked'),k.get('candidates_full_run'),k.get('candidates_screened_out'),k.get('candidates_valid'),
 '| frag',(i.get('V11 Low Load Fragmentation Audit') or {}).get('status'),'| cpaudit',(i.get('V11 CP Audit') or {}).get('status'),'| fuelprov',(i.get('Fuel Provenance') or {}).get('status'))
PY
