#!/bin/bash
# V11 salinan v8/targeted_v8b.sh (battery V7 -> v11/targeted_v7_v11.sh). Targeted V8 = battery V7 (HTTP + UI, SRC) + V8: reproducer workbook, gas GE, unit priority (CLI job + UI).
# Arg: <folder-laporan> <port-dasar>. Env: SRC, SKIPUI=1
cd /home/claude/t5; PHP=/usr/local/bin/php74; N=/home/claude/.npm-global/lib/node_modules; OUT=$1; P=$2; mkdir -p $OUT
SRC=${SRC:-tgs8}; S=/tmp/claude-0/tv8_$P; rm -rf $S; mkdir -p $S
echo "=== V8: REPRODUCER + GAS GE + UNIT PRIORITY (jalur job) ==="
timeout 3600 $PHP -d max_execution_time=0 v8/v8_cases.php $(cd $SRC && pwd) $OUT/V8_CASES.md $OUT/V8_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
if [ -z "$SKIPUI" ]; then
  echo "=== V8 UI: REPRODUCER Daily_Plan_09_Jul_26_Baru ==="
  W=$S/wbui; rm -rf $W; mkdir -p $W/jobs; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $W/; cp v8/input_WB09.json $W/input_data.json
  (setsid node v3/proxy.js $((P+10)) $W 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 1800 node v8/tests/wb09_ui.js http://127.0.0.1:$((P+10)) $OUT/WB09_UI.md $OUT/WB09_UI.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
  bash v4/kill_port.sh $((P+10)) >/dev/null 2>&1
  echo "=== V8 UI: PGN 30 -> reproducer lewat perubahan kuota (PEP +2, PEP KP72 -2,2) ==="
  K=$S/wbq; rm -rf $K; mkdir -p $K/jobs; cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $K/; cp fixtures/input_actual.json $K/input_data.json
  (setsid node v3/proxy.js $((P+11)) $K 6 >/dev/null 2>&1 </dev/null &); sleep 3
  NODE_PATH=$N timeout 1800 node v7/tests/quota_ui.js http://127.0.0.1:$((P+11)) $OUT/WB09_QUOTA_UI.md $OUT/WB09_QUOTA_UI.jsonl "PGN 30 tanpa Actual" "pep:2+pep_kp72:-2.2" 30 2>&1 | cut -c1-240
  bash v4/kill_port.sh $((P+11)) >/dev/null 2>&1
fi
SRC=$SRC SKIPUI=$SKIPUI bash v12/targeted_v7_v12.sh $OUT $P
echo TARGETED_V8_SELESAI
