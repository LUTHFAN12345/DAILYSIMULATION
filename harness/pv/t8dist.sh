#!/bin/bash
# t8dist.sh <src> <port> <tag>: Distillate cold (jobs kosong) lalu warm (sesudah Gas Shortage normal, memo basis tersedia)
SRC=$1; P=$2; TAG=$3; R=/tmp/claude-0/t8d_$TAG; cd /home/claude/t5; rm -rf $R; mkdir -p $R/jobs; cp $SRC/*.php $R/; cp integ/input_pv.json $R/input_data.json
(setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P integ/dist/D_rec.json integ/pv/t8/${TAG}_DIST_cold.json > integ/pv/t8/${TAG}_DIST_cold.log 2>&1
echo "$TAG DIST_cold $(python3 integ/bs/sig.py integ/pv/t8/${TAG}_DIST_cold.json)"
sleep 20; rm -rf $R/jobs; mkdir -p $R/jobs
RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P integ/fast5/PGN25_PEP30_KP0.json /tmp/claude-0/t8d_$TAG.gs.json > /dev/null 2>&1; sleep 20
RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P integ/dist/D_rec.json integ/pv/t8/${TAG}_DIST_warm.json > integ/pv/t8/${TAG}_DIST_warm.log 2>&1
echo "$TAG DIST_warm $(python3 integ/bs/sig.py integ/pv/t8/${TAG}_DIST_warm.json) route=$(python3 -c "import json;print((json.load(open('integ/pv/t8/${TAG}_DIST_warm.json'))['info'].get('Run Status') or {}).get('mode'))")"
for pid in $(ps aux | grep "proxy.js $P " | grep -v grep | awk '{print $2}'); do kill $pid; done
