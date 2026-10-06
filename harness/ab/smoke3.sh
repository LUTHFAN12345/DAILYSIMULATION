#!/bin/bash
# smoke3.sh <src> <port> <tag> : U31 merit, Distillate (cold), Change Over Block 1-2 — replay HTTP (job_exec + pembantu), worker diam di antara kasus
SRC=$1; P=$2; TAG=$3; R=/tmp/claude-0/h_$TAG; cd /home/claude/t5; rm -rf $R; mkdir -p $R/jobs; cp $SRC/*.php $R/; cp integ/input_pv.json $R/input_data.json
source <(sed -n '/^wait_idle(){/,/^}/p' integ/hx/rx.sh)
(setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); for i in $(seq 1 30); do c=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$P/index.php); [ "$c" = 200 ] && break; sleep 1; done
mkdir -p integ/ab/smoke
one(){ n=$1; f=$2; wait_idle "$R" >/dev/null; for i in $(seq 1 60); do rm -rf $R/jobs 2>/dev/null && break; sleep 1; done; mkdir -p $R/jobs
  RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P $f integ/ab/smoke/${TAG}_$n.json > integ/ab/smoke/${TAG}_$n.log 2>&1
  echo "$TAG $n $(python3 integ/bs/sig.py integ/ab/smoke/${TAG}_$n.json) idle_after_s=$(wait_idle "$R")"; }
one U31 integ/fast5/U31.json
one DIST_cold integ/dist/D_rec.json
one CO integ/co3/WB09_3_B1B2_SIMSIM_COLD.json
for pid in $(ps aux | grep -v grep | grep -E "proxy.js $P " | awk '{print $2}'); do kill $pid; done
