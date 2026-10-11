#!/bin/bash
# one.sh <port> <dir> <fx> <out> : satu cold UI run
R=$2; rm -rf "${R:?}"/jobs/*; cp /home/claude/ab3/fx/$3.json $R/input_data.json
env NODE_PATH=/opt/node22/lib/node_modules timeout 200 node /home/claude/ab2/op/uirun.js http://127.0.0.1:$1 $4 lng > /dev/null 2>&1
python3 -I -c "import json,sys;d=json.load(open(sys.argv[1]));print(sys.argv[2],sys.argv[3],d.get('t_total'),d.get('cp'),d.get('msg','')[:60])" $4 $1 $3
