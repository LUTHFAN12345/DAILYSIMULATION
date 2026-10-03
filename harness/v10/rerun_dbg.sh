#!/bin/bash
# $1 root, $2 scenario, $3 keep-final-file (anchor) ; copy tgs10, relabel, delete other finals, run
R=$1; SC=$2; KEEP=$3
cp /home/claude/t5/tgs10/{run,worker02,worker_functions,index,saved_data_store}.php $R/
for f in $R/jobs/_final/*.json; do b=$(basename $f); k=${b%%.*}; if [ "$KEEP" = ANCHORS ]; then [ -f $R/jobs/_final/$k.v10lib.json ] || rm -f $f; else case $b in $KEEP*) ;; *) rm -f $f;; esac; fi; done
rm -rf $R/jobs/_cs 2>/dev/null
FP=$(cd $R && /usr/local/bin/php74 -r 'define("PP_LIB_ONLY",1); require "run.php"; echo "FP=".pp_engine_fingerprint();' 2>/dev/null | grep -o 'FP=[0-9a-f]*' | cut -c4-)
python3 - "$FP" "$R" <<'PY'
import json,glob,sys
for f in glob.glob(sys.argv[2]+'/jobs/_final/*.json'):
    d=json.load(open(f))
    if 'v3' in d: d['v3']['engine']=sys.argv[1]
    elif f.endswith('.v10lib.json'): d['engine']=sys.argv[1]
    json.dump(d,open(f,'w'))
PY
cd /home/claude/t5; timeout 900 /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $R $SC /tmp/claude-0/dbg_$SC.json | tail -1
python3 - /tmp/claude-0/dbg_$SC.json <<'PY'
import json,sys
d=json.load(open(sys.argv[1]));i=d['output']['info'];c=i.get('V10 Candidate Screening') or {}
print(i.get('Cost Production (USD/MWh)'), c.get('incumbent_node'), (c.get('incumbent_canonical_key') or {}).get('cp'), c.get('candidates_full_run_tier2b'), c.get('library_reused'), (i.get('Incremental Recompute') or {}).get('wall_s'))
PY
