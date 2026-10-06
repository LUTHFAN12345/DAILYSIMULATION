#!/bin/bash
# rx.sh <xampp|linux> <base> <root> <outdir> <cases...> : R1 (CSV), R2 (tanpa CSV), R3on (CSV + Follow PV, floor 0) lewat UI asli
PLAT=$1; BASE=$2; ROOT=$3; OUT=$4; shift 4; mkdir -p $OUT; cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules
CSV="/home/claude/t5/integ/pv/CSV Load Pred dan Dispatch (5 Oct).csv"
reset_root(){ if [ "$PLAT" = linux ]; then for i in $(seq 1 60); do rm -rf "$ROOT/jobs" "$ROOT/data" 2>/dev/null && break; sleep 1; done
    install -d -o www-data -g www-data -m 2775 "$ROOT/jobs" "$ROOT/data"; install -o www-data -g www-data -m 0664 integ/input_pv.json "$ROOT/input_data.json";
  else for i in $(seq 1 60); do rm -rf "$ROOT/jobs" "$ROOT/data" 2>/dev/null && break; sleep 1; done; mkdir -p "$ROOT/jobs"; cp integ/input_pv.json "$ROOT/input_data.json"; fi; }
for c in "$@"; do reset_root
  case $c in
    R1)   env CSV="$CSV" MAXT=400 SAVE_PAYLOADS=$OUT/R1 NODE_PATH=$N timeout 450 node integ/hx/hx_ui.js $BASE ${PLAT}_R1 > $OUT/R1.json 2>&1;;
    R2)   env MAXT=400 SAVE_PAYLOADS=$OUT/R2 NODE_PATH=$N timeout 450 node integ/hx/hx_ui.js $BASE ${PLAT}_R2 > $OUT/R2.json 2>&1;;
    R3on) env CSV="$CSV" SR_MODE=follow_pv SR_FIX=0 MAXT=400 NODE_PATH=$N timeout 450 node integ/hx/hx_ui.js $BASE ${PLAT}_R3on > $OUT/R3on.json 2>&1;;
  esac
  J=$(ls -td $ROOT/jobs/economic_review-* 2>/dev/null | head -1); [ -n "$J" ] && { cp $J/job.json $OUT/${c}_job.json; [ -f $J/v12_fast_ready.json ] && python3 -c "
import json,sys; x=json.load(open('$J/v12_fast_ready.json')); x.pop('output',None); json.dump(x,open('$OUT/${c}_fast_ready_meta.json','w'))"; [ -f $J/side_log.txt ] && cp $J/side_log.txt $OUT/${c}_side_log.txt; }
  python3 integ/hx/show.py $OUT/$c.json | grep -E "^run |^result" | sed "s/^/$PLAT $c /"
done
