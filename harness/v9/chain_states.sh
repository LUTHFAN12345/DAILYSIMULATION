#!/bin/bash
# job berurutan dalam SATU akar (rantai): <src> <out-dir> <SC...>
cd /home/claude/t5; SRC=$1; OUT=$2; shift 2; mkdir -p $OUT
S=/tmp/claude-0/cs_$(basename $OUT); rm -rf $S; mkdir -p $S/jobs; cp $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $S/
for SC in "$@"; do
  timeout 1500 /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $S $SC /home/claude/t5/$OUT/$SC.json 2>/dev/null | tail -1 | sed "s/^/$SC /"
  python3 v9/sum.py $OUT/$SC.json
done
