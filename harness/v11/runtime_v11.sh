#!/bin/bash
# Battery runtime V11 saja (snapshot engine SRC + battery V8/V7 HTTP/UI + UI Target Selesai V11 + UI reproducer WB09_3). Arg: <out> <port>. Env: SRC
cd /home/claude/t5; OUT=$1; P=$2; SRC=${SRC:-tgs11}; mkdir -p $OUT; N=/home/claude/.npm-global/lib/node_modules
echo "=== SNAPSHOT (engine SRC) ==="; SRC=$SRC bash v10/mk_snap.sh 2>&1 | tail -4
export SNAPA=/home/claude/t5/v10/snap_act
SRC=$SRC bash v8/targeted_v8b.sh $OUT $P
echo "=== V11 UI TARGET SELESAI ==="
U=/tmp/claude-0/v11tl_$P; rm -rf $U; mkdir -p $U/jobs; cp -L $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $U/; cp fixtures/input_actual.json $U/input_data.json
(setsid node v3/proxy.js $((P+15)) $U 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 3600 node v11/tl_ui_v11.js http://127.0.0.1:$((P+15)) $OUT/TL_UI_V11.md 15,25,35,45,55,60,max 2>&1 | grep -E "^(PASS|FAIL)|PASS$" | cut -c1-300
bash v4/kill_port.sh $((P+15)) >/dev/null 2>&1
echo "=== V11 UI REPRODUCER WB09_3 ==="; bash v11/wb3ui_once.sh $SRC $((P+16)) $OUT
echo RUNTIME_V11_SELESAI
