#!/bin/bash
# Targeted V3 tahap akhir (sebelum freeze): popup, fitur lama lewat jalur V3, matrix slot/kuota, Save UI.
# Arg: <folder-laporan> <port-dasar>
cd /home/claude/t5; PHP=/usr/local/bin/php74; N=/home/claude/.npm-global/lib/node_modules; T=/home/claude/t5/v3/tests
OUT=$1; P=$2; mkdir -p $OUT; S=/tmp/claude-0/tf_$P; rm -rf $S; mkdir -p $S
SRC=${SRC:-tgs}; R=$S/ui; mkdir -p $R; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $SRC/saved_data_store.php $R/
clean(){ cp fixtures/input_actual.json $R/input_data.json; rm -f $R/output_data.json; rm -rf $R/input_data.json.lock $R/jobs; mkdir -p $R/jobs; }
clean; (setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
[ -z "$SKIPA" ] && echo "=== POPUP PGN20 ===" && NODE_PATH=$N timeout 1200 node $T/browser_popup_responsiveness.js http://127.0.0.1:$P $OUT/shots $OUT/POPUP.md 2>&1 | grep -E "^(PASS|FAIL)|lulus" | cut -c1-200
for q in $(ps aux | grep "[p]roxy.js $P " | awk '{print $2}'); do kill $q; done; sleep 1
echo "=== FITUR LAMA LEWAT JALUR V3 (status unit, Mandatory Stop, Skip/Fix Load, Change Over) ==="
H=$S/feat; SRC=$SRC bash v3/prep_ui.sh $H > /dev/null; rm -f $OUT/FEATURES.jsonl
[ -z "$SKIPA" ] && timeout 5400 $PHP v3/v3_http.php $H $((P+1)) $OUT/FEATURES.jsonl warm /home/claude/t5/v3/feat_seq.txt /home/claude/t5/v3/snap_empty 2>&1 | cut -c1-260
echo "=== SLOT & KUOTA (cold; basis final BASE_ACT10) ==="
H2=$S/slot; SRC=$SRC bash v3/prep_ui.sh $H2 > /dev/null; rm -f $OUT/SLOT_COLD.jsonl
timeout 9000 $PHP v3/v3_http.php $H2 $((P+2)) $OUT/SLOT_COLD.jsonl cold ACT_PGN_UP,ACT_PGN_DOWN,ACT_FFJ_UP,ACT_FFJ_DOWN,ACT_FFM_DOWN,KP72_DOWN,ACT_PGN_2SLOT_C,QA_pgn_pipe_1,QA_lng_1,QA_pgn_pipe_-1,ACT_PGN_BIG $SNAP 2>&1 | cut -c1-260
echo "=== WARM (rantai satu server) ==="; rm -f $OUT/SLOT_WARM.jsonl
timeout 5400 $PHP v3/v3_http.php $H2 $((P+2)) $OUT/SLOT_WARM.jsonl warm ACT_PGN_UP,ACT_PGN_DOWN,ACT_PGN_UP,ACT_PGN_2SLOT_C,KP72_DOWN,ACT_FFJ_UP,QA_lng_1 $SNAP 2>&1 | cut -c1-260
echo "=== SAVE UI (C1-C7) ==="
U=$S/save; SRC=$SRC bash v3/prep_ui.sh $U > /dev/null
(setsid node v3/proxy.js $((P+3)) $U 6 >/dev/null 2>&1 </dev/null &); sleep 3
FAST=1 NODE_PATH=$N timeout 2400 node v3/save_ui.js http://127.0.0.1:$((P+3)) $U $OUT/SAVE_UI.md 2>&1 | tail -12 | cut -c1-260
for q in $(ps aux | grep "[p]roxy.js $((P+3)) " | awk '{print $2}'); do kill $q; done
echo TARGETED_FINAL_SELESAI
