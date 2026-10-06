#!/bin/bash
# h_smoke.sh <src> <port> <tag> : U31 merit, Gas Shortage normal, Distillate cold & warm, Change Over Block 1-2 (CLI replay HTTP)
SRC=$1; P=$2; TAG=$3; R=/tmp/claude-0/h_$TAG; cd /home/claude/t5; rm -rf $R; mkdir -p $R/jobs; cp $SRC/*.php $R/; cp integ/input_pv.json $R/input_data.json
(setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); for i in $(seq 1 30); do c=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$P/index.php); [ "$c" = 200 ] && break; sleep 1; done
mkdir -p integ/ap/h
one(){ n=$1; f=$2; for i in $(seq 1 60); do rm -rf $R/jobs 2>/dev/null && break; sleep 1; done; mkdir -p $R/jobs
  RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P $f integ/ap/h/${TAG}_$n.json > integ/ap/h/${TAG}_$n.log 2>&1
  echo "$TAG $n $(python3 integ/bs/sig.py integ/ap/h/${TAG}_$n.json)"; }
one U31 integ/fast5/U31.json
one GS integ/fast5/PGN25_PEP30_KP0.json
one DIST_cold integ/dist/D_rec.json
for i in $(seq 1 60); do rm -rf $R/jobs 2>/dev/null && break; sleep 1; done; mkdir -p $R/jobs
RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P integ/fast5/PGN25_PEP30_KP0.json /tmp/claude-0/h_$TAG.gs.json > /dev/null 2>&1
for i in $(seq 1 40); do [ -z "$(ls $R/jobs/*/v4_help_*.beat 2>/dev/null)" ] && break; sleep 1; done
RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P integ/dist/D_rec.json integ/ap/h/${TAG}_DIST_warm.json > integ/ap/h/${TAG}_DIST_warm.log 2>&1
echo "$TAG DIST_warm $(python3 integ/bs/sig.py integ/ap/h/${TAG}_DIST_warm.json)"
one CO integ/co3/WB09_3_B1B2_SIMSIM_COLD.json
for pid in $(ps aux | grep -v grep | grep -E "proxy.js $P |-t $R\$" | awk '{print $2}'); do kill $pid; done
