#!/bin/bash
# abrun.sh <old|cur> <base> <root> <outdir> <A|U> : A/B lewat UI asli, kondisi sama (cold: jobs + cache dihapus, worker PHP diam sebelum dan sesudah)
# A = reproducer (CSV 5 Oct, PGN 30, PEP 34, Fastest - Default, Follow PV OFF); U = state pengguna dari OLD_GOOD_FAST (input_data.json lama, gas dari state)
SRC=$1; BASE=$2; ROOT=$3; OUT=$4; C=$5; mkdir -p $OUT; cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules
CSV="/home/claude/t5/integ/pv/CSV Load Pred dan Dispatch (5 Oct).csv"
source <(sed -n '/^wait_idle(){/,/^}/p' integ/hx/rx.sh)
wait_idle "$ROOT" > /dev/null
for i in $(seq 1 60); do rm -rf "$ROOT/jobs" "$ROOT/data" 2>/dev/null && break; sleep 1; done; mkdir -p "$ROOT/jobs"
if [ "$C" = A ]; then cp integ/input_pv.json "$ROOT/input_data.json"; else cp oldgood/input_data.json "$ROOT/input_data.json"; fi
OLD=""; [ "$SRC" = old ] && OLD=1
if [ "$C" = A ]; then env CSV="$CSV" OLDUI=$OLD MAXT=400 SAVE_PAYLOADS=$OUT/${SRC}_$C SAVE_OUTPUT=$OUT/${SRC}_$C NODE_PATH=$N timeout 450 node integ/hx/hx_ui.js $BASE ${SRC}_$C > $OUT/${SRC}_$C.json 2>&1
else env OLDUI=$OLD NOGAS=1 MAXT=400 SAVE_PAYLOADS=$OUT/${SRC}_$C SAVE_OUTPUT=$OUT/${SRC}_$C NODE_PATH=$N timeout 450 node integ/hx/hx_ui.js $BASE ${SRC}_$C > $OUT/${SRC}_$C.json 2>&1; fi
IDLE=$(wait_idle "$ROOT"); echo "$IDLE" > $OUT/${SRC}_${C}_idle_s.txt
for J in $(ls -td $ROOT/jobs/economic_review-* 2>/dev/null); do b=$(basename $J); cp $J/job.json $OUT/${SRC}_${C}_${b}_job.json; [ -f $J/result.json ] && cp $J/result.json $OUT/${SRC}_${C}_${b}_result.json
  [ -f $J/v12_fast_ready.json ] && python3 -c "
import json; x=json.load(open('$J/v12_fast_ready.json')); x.pop('output',None); json.dump(x,open('$OUT/${SRC}_${C}_${b}_fast_ready_meta.json','w'))"; done
python3 integ/hx/show.py $OUT/${SRC}_$C.json | grep -E "^run |^result" | sed "s/^/$SRC $C /"; echo "$SRC $C idle_after_s $IDLE"
