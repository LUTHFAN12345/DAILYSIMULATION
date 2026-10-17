#!/bin/bash
# srvrun.sh <app> <input> <out> <port> <helpers>
app=$1; in=$2; out=$3; port=$4; nh=$5
read jid tok < <(cd $app && php /tmp/w/h/mkjob.php $app $in)
t0=$(date +%s.%N)
curl -s "http://127.0.0.1:$port/run.php?mode=job_exec&job=$jid&token=$tok" > /dev/null &
for s in $(seq 1 $nh); do curl -s "http://127.0.0.1:$port/run.php?mode=job_help&job=$jid&token=$tok&slot=$s" > /dev/null & done
until grep -q '"status": *"\(DONE\|FAILED\|CANCELLED\)"' $app/jobs/$jid/job.json 2>/dev/null; do sleep 0.5; done
t1=$(date +%s.%N); cp $app/jobs/$jid/result.json $out; echo "wall=$(echo "$t1-$t0"|bc)"; wait
