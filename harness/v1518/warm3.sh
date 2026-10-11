#!/bin/bash
# warm3.sh tag fx... : per fixture: 1 cold run (prime, tidak dicatat), lalu 3 warm rerun (input sama, cache server dipertahankan),
# lalu 3 warm IE kecil (IEW1..3_<fx>, berbeda satu sama lain, basis = run fixture asli)
R=/tmp/claude-0/ui18; O=/home/claude/ab3/perf; N="env NODE_PATH=/opt/node22/lib/node_modules"; B=http://127.0.0.1:8818; T=$1; shift
one(){ cp /home/claude/ab3/fx/$1.json $R/input_data.json; timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/${T}_$2.json lng > /dev/null 2>&1
 python3 -I -c "import json,sys;d=json.load(open(sys.argv[1]));print(sys.argv[2],d.get('t_total'),d.get('cp'),d.get('hr'),d.get('gate'),d.get('merit'),(d.get('msg') or '')[:60])" $O/${T}_$2.json $2 >> $O/$T.log; }
for f in "$@"; do rm -rf "${R:?}"/jobs/*; one $f ${f}_prime
 for i in 1 2 3; do one $f ${f}_warm$i; done
 for i in 1 2 3; do one IEW${i}_$f IEW${i}_${f}; cp /home/claude/ab3/fx/$f.json $R/input_data.json; done; done; echo DONE >> $O/$T.log
