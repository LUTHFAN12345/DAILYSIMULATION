#!/bin/bash
# run_matrix.sh <platform: xampp|linux> <base_url> <app_root> <outdir> [cases]
PLAT=$1; BASE=$2; ROOT=$3; OUT=$4; CASES=${5:-"A B C D"}; mkdir -p $OUT; cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules
CSV="/home/claude/t5/integ/pv/CSV Load Pred dan Dispatch (5 Oct).csv"
reset_root(){ if [ "$PLAT" = linux ]; then rm -rf "$ROOT/jobs" "$ROOT/data"; install -d -o www-data -g www-data -m 2775 "$ROOT/jobs" "$ROOT/data"; install -o www-data -g www-data -m 0664 integ/input_pv.json "$ROOT/input_data.json";
  else rm -rf "$ROOT/jobs" "$ROOT/data"; mkdir -p "$ROOT/jobs"; cp integ/input_pv.json "$ROOT/input_data.json"; fi; }
for c in $CASES; do reset_root
  case $c in
    A) env CSV="$CSV" SR_MODE=fixed SR_FIX=0 ACTION=apply SAVE=input EXPECT_SRC=34.82 T6=1 NODE_PATH=$N timeout 1500 node integ/pv/pv_ui.js $BASE ${PLAT}_A_T4ABD_T6 > $OUT/A.json 2>&1;;
    E) env CSV="$CSV" SR_MODE=fixed SR_FIX=0 ACTION=apply SAVE=report TARGET=max EXPECT_SRC=34.82 NODE_PATH=$N timeout 1500 node integ/pv/pv_ui.js $BASE ${PLAT}_E_report > $OUT/E.json 2>&1;;
    B) env CSV="$CSV" SR_MODE=fixed SR_FIX=10 ACTION=apply SAVE=input NODE_PATH=$N timeout 1500 node integ/pv/pv_ui.js $BASE ${PLAT}_B_T1_fixSR10 > $OUT/B.json 2>&1;;
    C) env CSV="$CSV" SR_MODE=follow_pv SR_FIX=2 EDITPV=20:25.5 ACTION=apply SAVE=input NODE_PATH=$N timeout 1500 node integ/pv/pv_ui.js $BASE ${PLAT}_C_T2_T3_followPV > $OUT/C.json 2>&1;;
    D) env CSV="$CSV" ACTION=keep SAVE=none NODE_PATH=$N timeout 900 node integ/pv/pv_ui.js $BASE ${PLAT}_D_T5_keep > $OUT/D.json 2>&1;;
  esac
  python3 - "$OUT/$c.json" <<'PY'
import json,sys
try: r=json.loads(open(sys.argv[1]).read().strip().split('\n')[-1])
except Exception as e: print(sys.argv[1],'PARSE_ERR',open(sys.argv[1]).read()[-400:]); sys.exit()
print(r['case'],'ALL',r['all_pass'],'popup',r['popup'].get('t_s'),'rerun',(r.get('apply') or {}).get('t_rerun_s'))
for k,v in r['checks'].items():
    if not v['pass']: print('   FAIL',k,json.dumps(v['evidence'])[:300])
PY
done
