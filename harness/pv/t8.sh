#!/bin/bash
# t8.sh <src> <port> <tag> : replay T8 regresi (U31 merit, Gas Shortage normal, Distillate, CO Block 1-2) pada source <src>
SRC=$1; P=$2; TAG=$3; R=/tmp/claude-0/t8_$TAG; cd /home/claude/t5; rm -rf $R; mkdir -p $R/jobs; cp $SRC/*.php $R/; cp integ/input_pv.json $R/input_data.json
(setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3; mkdir -p integ/pv/t8
for c in U31:integ/fast5/U31.json GS:integ/fast5/PGN25_PEP30_KP0.json DIST:integ/dist/D_rec.json CO:integ/co3/WB09_3_B1B2_SIMSIM_COLD.json; do n=${c%%:*}; f=${c#*:}
  rm -rf $R/jobs; mkdir -p $R/jobs; t=$(date +%s.%N)
  RP_MAX=1500 python3 integ/bs/replay.py http://127.0.0.1:$P $f integ/pv/t8/${TAG}_$n.json > integ/pv/t8/${TAG}_$n.log 2>&1
  echo "$TAG $n $(python3 -c "print(round($(date +%s.%N)-$t,1))")s $(python3 integ/bs/sig.py integ/pv/t8/${TAG}_$n.json)"
done
for pid in $(ps aux | grep "proxy.js $P " | grep -v grep | awk '{print $2}'); do kill $pid; done
