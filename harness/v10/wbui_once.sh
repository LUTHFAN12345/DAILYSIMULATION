#!/bin/bash
# WB09 UI sekali pada tgs10 (akar baru). Arg: <port> <tag>
cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules; S=/tmp/claude-0/scratch; P=$1; W=$S/wbui_$2; rm -rf $W; mkdir -p $W/jobs; cp ${SRC:-tgs10}/{run,worker02,worker_functions,index,saved_data_store}.php $W/; cp v8/input_WB09.json $W/input_data.json; export PP_PHP=/usr/local/bin/php74
(setsid node v3/proxy.js $P $W 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 1800 node v8/tests/wb09_ui.js http://127.0.0.1:$P /tmp/claude-0/wbt_$2.md /tmp/claude-0/wbt_$2.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | grep -E "U1|U7|U8|FAIL" | cut -c1-140; bash v4/kill_port.sh $P
for f in $W/jobs/economic_review-*/result.json; do python3 -c "
import json,hashlib;d=json.load(open('$f'));o=d.get('output') or d.get('result') or d;print(hashlib.md5(json.dumps(o['data'],sort_keys=True).encode()).hexdigest()[:12], o['info']['Cost Production (USD/MWh)'], o['info']['Run Status']['elapsed_s'], (o['info'].get('V10 Screening Summary') or {}).get('exact'))"; done
