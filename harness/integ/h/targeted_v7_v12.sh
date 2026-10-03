#!/bin/bash
# V11 salinan v7/targeted_v7.sh (UI V6 -> v11/targeted_ui_v11.sh). Targeted V7 (HTTP + UI). Arg: <folder-laporan> <port-dasar>. Env: SRC (akar source), SNAPA (snapshot basis Actual), SKIPUI=1, SKIP12=1
cd /home/claude/t5; PHP=/usr/local/bin/php74; N=/home/claude/.npm-global/lib/node_modules; T=/home/claude/t5/v6/tests; T7=/home/claude/t5/v7/tests
OUT=$1; P=$2; mkdir -p $OUT; S=/tmp/claude-0/tv7_$P; rm -rf $S; mkdir -p $S
SRC=${SRC:-tgs7}; SNAPA=${SNAPA:-/home/claude/t5/v3/snap_base}
H7=v7/v7_http.php
SLOTS="ACT_PGN_UP,ACT_PGN_DOWN,ACT_FFJ_UP,ACT_FFJ_DOWN,ACT_FFM_UP,ACT_FFM_DOWN,KP72_UP,KP72_DOWN,ACT_PGN_2SLOT_C,ACT_PGN_BIG"
QA="QA_pgn_pipe_1,QA_pgn_pipe_-1,QA_pgn_pipe_2,QA_pgn_pipe_-2,QA_pgn_pipe_4,QA_pgn_pipe_-4,QA_pep_1,QA_pep_-1,QA_pep_2,QA_pep_-2,QA_lng_1,QA_lng_-1,QA_pep_kp72_1,QA_pep_kp72_-1,QA_akasia_1,QA_akasia_-1"
INCOK="ACT_PGN_UP,ACT_PGN_DOWN,ACT_FFJ_UP,ACT_FFM_DOWN,KP72_DOWN,ACT_PGN_2SLOT_C,QA_pgn_pipe_1,QA_lng_1"
echo "=== LINT ==="; for f in run.php worker02.php worker_functions.php index.php; do $PHP -l $SRC/$f; done
H=$S/slot; SRC=$SRC SNAP=$SNAPA bash v3/prep_ui.sh $H > /dev/null
echo "=== SIMULATION DATA + KUOTA (basis Actual 11 jam): COLD ==="; rm -f $OUT/SLOT_COLD.jsonl
timeout 9000 $PHP $H7 $H $P $OUT/SLOT_COLD.jsonl cold $SLOTS,$QA,"PGN29_PEP32_ACT|QAX:pgn_pipe=-1;pep=2" $SNAPA 2>&1 | cut -c1-220
echo "=== TRANSITION / WARM (rantai dari final sebelumnya) ==="; rm -f $OUT/SLOT_WARM.jsonl
timeout 5400 $PHP $H7 $H $P $OUT/SLOT_WARM.jsonl warm ACT_PGN_UP,ACT_PGN_DOWN,ACT_PGN_UP,ACT_PGN_2SLOT_C,KP72_DOWN,ACT_FFJ_UP,QA_lng_1,QA_pep_1,QA_pgn_pipe_1,QA_pep_-1,QA_pgn_pipe_1 $SNAPA 2>&1 | cut -c1-220
echo "=== REFERENSI EXACT PENUH (inkremental dimatikan, state sama) ==="; rm -f $OUT/SLOT_EXACT_REF.jsonl
PP_V3_INCREMENTAL=0 timeout 5400 $PHP $H7 $H $P $OUT/SLOT_EXACT_REF.jsonl cold $INCOK $SNAPA 2>&1 | cut -c1-220
echo "=== KUOTA DARI BASIS PGN 30 (tanpa Actual) ==="
Q=$S/q; SRC=$SRC SNAP=v3/snap_empty bash v3/prep_ui.sh $Q > /dev/null; rm -f $OUT/QUOTA_BASE.jsonl $OUT/QUOTA_COLD.jsonl $OUT/QUOTA_WARM.jsonl $OUT/QUOTA_EXACT_REF.jsonl
timeout 1200 $PHP $H7 $Q $((P+1)) $OUT/QUOTA_BASE.jsonl warm "BASE_PGN30,BASE_PGN30_RERUN_IDENTIK|BASE_PGN30" /home/claude/t5/v3/snap_empty 2>&1 | cut -c1-220
SQ=$S/snap_pgn30; rm -rf $SQ; mkdir -p $SQ; cp -a $Q/jobs/_final $Q/jobs/_tl $SQ/
QS="Q_pgn_pipe_1,Q_pgn_pipe_-1,Q_pgn_pipe_2,Q_pgn_pipe_-2,Q_pgn_pipe_4,Q_pgn_pipe_-4,Q_pep_1,Q_pep_-1,Q_pep_2,Q_pep_-2,Q_lng_1,Q_lng_-1,Q_pep_kp72_1,Q_pep_kp72_-1,Q_akasia_1,Q_akasia_-1,PGN29_PEP32|OPS:pgn_pipe=29;pep=32"
timeout 7200 $PHP $H7 $Q $((P+1)) $OUT/QUOTA_COLD.jsonl cold "$QS" $SQ 2>&1 | cut -c1-220
echo "--- transisi PGN 29 + PEP 32 (warm: basis -> 29/32 -> kembali 30 -> 29/32) ---"
timeout 3600 $PHP $H7 $Q $((P+1)) $OUT/QUOTA_WARM.jsonl warm "PGN29_PEP32|OPS:pgn_pipe=29;pep=32,BASE_PGN30|OPS:pgn_pipe=30,PGN29_PEP32_LAGI|OPS:pgn_pipe=29;pep=32,Q_pep_1,Q_pgn_pipe_1" $SQ 2>&1 | cut -c1-220
PP_V3_INCREMENTAL=0 timeout 5400 $PHP $H7 $Q $((P+1)) $OUT/QUOTA_EXACT_REF.jsonl cold "Q_pgn_pipe_1,Q_pep_1,Q_lng_1,Q_pep_kp72_1,Q_pep_kp72_-1,Q_akasia_1" $SQ 2>&1 | cut -c1-220
echo "=== EVENT BERAT: STATUS UNIT, MANDATORY STOP, SKIP/FIX LOAD, CHANGE OVER ==="
F=$S/feat; SRC=$SRC SNAP=v3/snap_empty bash v3/prep_ui.sh $F > /dev/null; rm -f $OUT/FEATURES.jsonl
timeout 3600 $PHP $H7 $F $((P+2)) $OUT/FEATURES.jsonl warm /home/claude/t5/v3/feat_seq.txt /home/claude/t5/v3/snap_empty 2>&1 | cut -c1-220
echo "=== EXACT PENUH STATE ACTUAL (BASE_ACT10 dari nol) ==="; rm -f $OUT/EXACT_ACT10.jsonl
E=$S/ex; SRC=$SRC SNAP=v3/snap_empty bash v3/prep_ui.sh $E > /dev/null
timeout 1800 $PHP $H7 $E $((P+3)) $OUT/EXACT_ACT10.jsonl warm BASE_ACT10 /home/claude/t5/v3/snap_empty 2>&1 | cut -c1-220
if [ -z "$SKIPUI" ]; then
  R=$S/ui; mkdir -p $R; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $SRC/saved_data_store.php $R/
  SKIP12=${SKIP12:-} bash integ/h/targeted_ui_v12.sh $R $OUT $((P+4))
  echo "=== SAVE UI (C1-C7, S1) ==="
  U=$S/save; SRC=$SRC SNAP=$SNAPA bash v3/prep_ui.sh $U > /dev/null
  (setsid node v3/proxy.js $((P+5)) $U 6 >/dev/null 2>&1 </dev/null &); sleep 3
  FAST=1 NODE_PATH=$N timeout 2400 node v3/save_ui.js http://127.0.0.1:$((P+5)) $U $OUT/SAVE_UI.md 2>&1 | tail -12 | cut -c1-220
  bash v4/kill_port.sh $((P+5))
  echo "=== V7 UI: PERUBAHAN SIMULATION DATA PER SLOT ==="
  V=$S/slotui; SRC=$SRC SNAP=$SNAPA bash v3/prep_ui.sh $V > /dev/null
  (setsid node v3/proxy.js $((P+6)) $V 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 5400 node $T/slot_ui.js http://127.0.0.1:$((P+6)) $OUT/SLOT_UI.md $OUT/SLOT_UI.jsonl 2>&1 | cut -c1-240
  bash v4/kill_port.sh $((P+6))
  echo "=== V7 UI: PERUBAHAN KUOTA (basis Actual 11 jam) ==="
  K=$S/quotaui; SRC=$SRC SNAP=$SNAPA bash v3/prep_ui.sh $K > /dev/null
  (setsid node v3/proxy.js $((P+8)) $K 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 7200 node $T7/quota_ui.js http://127.0.0.1:$((P+8)) $OUT/QUOTA_UI_ACT.md $OUT/QUOTA_UI_ACT.jsonl "Actual 11 jam" \
    "pgn_pipe:1,pgn_pipe:-1,pgn_pipe:2,pgn_pipe:-2,pgn_pipe:4,pgn_pipe:-4,pep:1,pep:-1,pep:2,pep:-2,lng:1,lng:-1,pep_kp72:1,pep_kp72:-1,akasia:1,akasia:-1,pgn_pipe:-1+pep:2" 2>&1 | cut -c1-240
  bash v4/kill_port.sh $((P+8))
  echo "=== V7 UI: PERUBAHAN KUOTA (basis PGN 30 tanpa Actual) ==="
  K2=$S/quotaui30; rm -rf $K2; mkdir -p $K2/jobs; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $SRC/saved_data_store.php $K2/; cp fixtures/input_actual.json $K2/input_data.json
  (setsid node v3/proxy.js $((P+9)) $K2 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 7200 node $T7/quota_ui.js http://127.0.0.1:$((P+9)) $OUT/QUOTA_UI_PGN30.md $OUT/QUOTA_UI_PGN30.jsonl "PGN 30 tanpa Actual" \
    "pgn_pipe:1,pgn_pipe:-1,pgn_pipe:2,pgn_pipe:-2,pgn_pipe:4,pgn_pipe:-4,pep:1,pep:-1,pep:2,pep:-2,lng:1,lng:-1,pep_kp72:1,pep_kp72:-1,akasia:1,akasia:-1,pgn_pipe:-1+pep:2" 30 2>&1 | cut -c1-240
  bash v4/kill_port.sh $((P+9))
  echo "=== V7 UI: FORCED STOP/START DAN CHANGE OVER ==="
  O=$S/opsui; rm -rf $O; mkdir -p $O/jobs; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $SRC/saved_data_store.php $O/; cp fixtures/input_actual.json $O/input_data.json
  (setsid node v3/proxy.js $((P+7)) $O 6 >/dev/null 2>&1 </dev/null &); sleep 3
  CASE_MS=300000 NODE_PATH=$N timeout 5400 node $T/ops_ui.js http://127.0.0.1:$((P+7)) $O /home/claude/t5/fixtures/input_actual.json $OUT/OPS_UI.md $OUT/OPS_UI.jsonl 2>&1 | cut -c1-240
  bash v4/kill_port.sh $((P+7))
fi
echo TARGETED_V7_SELESAI
