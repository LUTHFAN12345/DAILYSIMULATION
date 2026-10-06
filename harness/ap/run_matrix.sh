#!/bin/bash
# run_matrix.sh <xampp|linux> <base_url> <app_root> <outdir> [cases]  — targeted test A-F (UI asli, Chromium headless)
PLAT=$1; BASE=$2; ROOT=$3; OUT=$4; CASES=${5:-"A A2 B C D E CM"}; mkdir -p $OUT; cd /home/claude/t5; N=/home/claude/.npm-global/lib/node_modules
CSV="/home/claude/t5/integ/pv/CSV Load Pred dan Dispatch (5 Oct).csv"; XLSX=/home/claude/t5/integ/ap/IE_Dispatch_PV_5Oct.xlsx; LOC=/home/claude/t5/integ/ap/IE_Dispatch_PV_semicolon_comma.csv
reset_root(){ IN=$1
  if [ "$PLAT" = linux ]; then for i in $(seq 1 60); do rm -rf "$ROOT/jobs" "$ROOT/data" 2>/dev/null && break; sleep 1; done
    install -d -o www-data -g www-data -m 2775 "$ROOT/jobs" "$ROOT/data"; install -o www-data -g www-data -m 0664 $IN "$ROOT/input_data.json";
  else for i in $(seq 1 60); do rm -rf "$ROOT/jobs" "$ROOT/data" 2>/dev/null && break; sleep 1; done; mkdir -p "$ROOT/jobs"; cp $IN "$ROOT/input_data.json"; fi; }
run(){ c=$1; shift; env "$@" NODE_PATH=$N timeout 1500 node integ/ap/ap_ui.js $BASE ${PLAT}_$c > $OUT/$c.json 2>&1; }
for c in $CASES; do
  case $c in
    A)  reset_root integ/input_pv.json; run A CSV="$CSV" SR_MODE=fixed SR_FIX=0 EXPECT=auto EXPECT_PERIOD=00:30 T6=1 EXPORT=1 SAVE=input;;
    A2) reset_root integ/ap/pl/A2_in.json; run A2 EXPECT=auto EXPECT_PERIOD=00:30-02:00 SAVE=input;;
    B)  reset_root integ/ap/pl/B_in.json; run B EXPECT=infeasible SAVE=none;;
    C)  reset_root integ/input_pv.json; run C CSV="$CSV" SR_MODE=fixed SR_FIX=10 EXPECT=auto EXPORT=1 SAVE=input;;
    D)  reset_root integ/input_pv.json; run D CSV="$CSV" SR_MODE=follow_pv SR_FIX=2 EDITPV=20:25.5 CHART=1 EXPECT=auto EXPORT=1 SAVE=input;;
    E)  reset_root integ/input_pv.json; run E XLSX=$XLSX CSV_REF="$CSV" LOCALE_CSV=$LOC SR_MODE=follow_pv SR_FIX=2 EXPECT=auto EXPORT=1 TARGET=max SAVE=report;;
    CM) reset_root integ/input_pv.json; run CM CSV="$CSV" SR_MODE=fixed SR_FIX=10 EXPECT=auto TARGET=max SAVE=none;;
  esac
  python3 - "$OUT/$c.json" <<'PY'
import json,sys
try: r=json.loads(open(sys.argv[1]).read().strip().split('\n')[-1])
except Exception as e: print(sys.argv[1],'PARSE_ERR',open(sys.argv[1]).read()[-400:]); sys.exit()
run=r.get('run') or {}
print(r['case'],'ALL',r['all_pass'],'checks',len(r['checks']),'t_done',run.get('t_done_s'),'runs',run.get('runs_total'),'label',(r.get('result') or {}).get('result_label') or ((r.get('result') or {}).get('gate') or {}).get('status'), 'cp',(r.get('result') or {}).get('cp'))
for k,v in r['checks'].items():
    if not v['pass']: print('   FAIL',k,json.dumps(v['evidence'])[:300])
if r.get('js_errors'): print('   JS',r['js_errors'][:3])
PY
done
