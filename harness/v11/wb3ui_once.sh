#!/bin/bash
# UI reproducer WB09_3 sekali. Arg: <src> <port> <out-dir>
cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules; SRC=$1; P=$2; OUT=$3; mkdir -p $OUT; export PP_PHP=/usr/local/bin/php74
W=/tmp/claude-0/wb3ui_$P; rm -rf $W; mkdir -p $W/jobs; cp -L $SRC/{run,worker02,worker_functions,index}.php $W/; cp v8/reproducer/input_WB09_3.json $W/input_data.json
(setsid node v3/proxy.js $P $W 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 1800 node v11/wb09_3_ui.js http://127.0.0.1:$P $OUT/WB09_3_UI.md $OUT/WB09_3_UI.jsonl 2>&1 | grep -E "^(PASS|FAIL|METRIK)|PASS$|ERR" | cut -c1-300
bash v4/kill_port.sh $P >/dev/null 2>&1
