#!/bin/bash
# 3 cold UI runs per fixture; usage: cold3.sh tag fx...
R=/tmp/claude-0/ui18; O=/home/claude/ab3/perf; N="env NODE_PATH=/opt/node22/lib/node_modules"; B=http://127.0.0.1:8818; T=$1; shift
for f in "$@"; do for i in 1 2 3; do cp /home/claude/ab3/fx/$f.json $R/input_data.json; rm -rf "${R:?}"/jobs/*
 timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/${T}_${f}_$i.json lng > /dev/null 2>&1
 python3 -I -c "import json,sys;d=json.load(open(sys.argv[1]));print(sys.argv[2],sys.argv[3],d.get('t_total'),d.get('cp'),d.get('hr'),d.get('gate'),d.get('merit'))" $O/${T}_${f}_$i.json $f $i >> $O/$T.log
done; done; echo DONE >> $O/$T.log
