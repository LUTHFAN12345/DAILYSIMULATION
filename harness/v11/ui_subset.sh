#!/bin/bash
# Subset UI regression V11 (baris 22-33 _full_v11.sh) pada salinan source. Arg: <src> <out> <port>
cd /home/claude/t5; export PP_PHP=/usr/local/bin/php74; SRC=$1; OUT=$2; P=$3; mkdir -p $OUT
N=/home/claude/.npm-global/lib/node_modules; T=/home/claude/t5/v6/tests; R=/tmp/claude-0/uisub_$P; rm -rf $R; mkdir -p $R; cp -L $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $R/
clean(){ cp fixtures/input_actual.json $R/input_data.json; rm -f $R/output_data.json; rm -rf $R/input_data.json.lock $R/jobs; mkdir -p $R/jobs; }
clean; (setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
echo "=== POPUP ==="; NODE_PATH=$N timeout 1200 node $T/browser_popup_responsiveness.js http://127.0.0.1:$P $OUT/shots $OUT/POPUP.md 2>&1 | tail -10
clean; echo "=== VO ==="; NODE_PATH=$N timeout 1500 node $T/vo_close_rerun.js http://127.0.0.1:$P $R/jobs 0 $OUT/VO_CLOSE_RERUN.md 2>&1 | tail -4
clean; echo "=== TARGETED12 ==="; NODE_PATH=$N timeout 7200 node $T/targeted12_gas_decision.js http://127.0.0.1:$P $OUT/shots $OUT/TARGETED12.md 2>&1 | tail -3
clean; echo "=== PROV 30->31 ==="; NODE_PATH=$N timeout 1500 node $T/prov_ui.js http://127.0.0.1:$P pgn_pipe 30 31 $OUT/PROV_UI_PGN1.md 2>&1 | grep -v RESP | tail -8
clean; echo "=== PROV 32->33 ==="; NODE_PATH=$N timeout 1500 node $T/prov_ui.js http://127.0.0.1:$P pgn_pipe 32 33 $OUT/PROV_UI_PGN33.md 2>&1 | grep -v RESP | tail -8
clean; echo "=== GS25 ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN25.md 25 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-240
clean; echo "=== GS20 ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN20.md 20 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-240
clean; echo "=== TL MAX FRESH ==="; NODE_PATH=$N timeout 1500 node /home/claude/t5/v11/tl_ui_v11.js http://127.0.0.1:$P $OUT/TL_UI_MAX_FRESH.md max 2>&1 | grep -v "^RUN" | tail -8
clean; echo "=== TL ALL ==="; NODE_PATH=$N timeout 3600 node /home/claude/t5/v11/tl_ui_v11.js http://127.0.0.1:$P $OUT/TL_UI.md 15,25,35,45,55,60,max 2>&1 | grep -v "^RUN" | tail -20
clean; echo "=== TL NOVALID ==="; NODE_PATH=$N timeout 300 node $T/tl_novalid.js http://127.0.0.1:$P $R/jobs $OUT/TL_NOVALID.md 2>&1 | tail -2
bash v4/kill_port.sh $P >/dev/null 2>&1; echo UI_SUBSET_SELESAI
