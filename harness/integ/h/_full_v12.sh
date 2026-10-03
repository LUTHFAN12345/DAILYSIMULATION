#!/bin/bash
# Full regression V12 — SATU KALI atas source beku: seluruh full regression V11 (battery V7 + suite V8 + V9/V10 versi V11 + V11 + UI)
# + V12: uji generik merit dispatch, rantai bahan bakar Gas Shortage, determinisme pembantu on/off rute V12. Arg: <akar-beku> <folder-laporan> <port-dasar>
cd /home/claude/t5; export PP_LEGACY_TARGET=max; export PP_PHP=/usr/local/bin/php74; PHP=/usr/local/bin/php74
R=$1; OUT=$2; P=$3; mkdir -p $OUT
N=/home/claude/.npm-global/lib/node_modules; S=/tmp/claude-0/scratch
T=/home/claude/t5/v6/tests
clean(){ cp fixtures/input_actual.json $R/input_data.json; rm -f $R/output_data.json $R/input_data.json.lock; rm -rf $R/input_data.json.lock; rm -rf $R/jobs; mkdir -p $R/jobs; }
echo "=== HASH AWAL ==="; (cd $R && sha256sum run.php worker02.php worker_functions.php index.php saved_data_store.php) | tee $OUT/_hash_awal.txt
echo "=== LINT PHP 7.4 ==="; for f in run.php worker02.php worker_functions.php index.php saved_data_store.php; do $PHP -l $R/$f; done
echo "=== JS SYNTAX ==="; (cd $R && $PHP index.php > $S/_r_$P.html 2>/dev/null); $PHP tools/jscheck.php $S/_r_$P.html $S/_js_$P.js >/dev/null && perl -0pi -e 's/<\?php.*?\?>/0/gs' $S/_js_$P.js && node --check $S/_js_$P.js && echo "JS OK"
echo "=== PEMINDAIAN CMD / PROSES OS ==="; grep -nE "\b(exec|shell_exec|proc_open|popen|passthru|system)\s*\(|cmd /C|start \"\" /B" $R/run.php $R/worker02.php $R/worker_functions.php | grep -v "^\s*//" | head -5; echo "(akhir pemindaian)"
echo "=== SNAPSHOT BASIS ACTUAL (engine beku, sebelum suite yang memakainya) ==="; SRC=$R bash v10/mk_snap.sh 2>&1 | tail -4
clean; echo "=== SMOKE ==="; timeout 3600 $PHP -d max_execution_time=0 tools/target_php_smoke.php $R $((P+1)) fixtures/input_actual.json $OUT/SMOKE.md 2>&1 | tail -3
clean; echo "=== FUEL RECOMPUTE ==="; timeout 3600 $PHP -d max_execution_time=0 tools/fuel_recompute.php $R $((P+2)) fixtures/input_actual.json $OUT/FUEL_RECOMPUTE.md 2>&1 | tail -17
clean; echo "=== DISPATCH RELIABILITY ==="; timeout 2400 $PHP tools/dispatch_reliability.php $R $((P+3)) fixtures/input_actual.json $OUT/DISPATCH_RELIABILITY.md 2>&1 | tail -26
L=$S/lk_$P; rm -rf $L; mkdir -p $L; cp $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $R/saved_data_store.php $L/; cp fixtures/input_actual.json $L/input_data.json
echo "=== EXEC LOCK UNIT ==="; $PHP tools/exec_lock_unit.php $L $OUT/EXEC_LOCK_UNIT.md 2>&1 | tail -13
clean; echo "=== A/B PEMAKAIAN ULANG STATE (memo mati vs hidup) ==="; timeout 3600 $PHP tools/ab_state_reuse.php "$R#nomemo" $R fixtures/input_actual.json $OUT/AB_STATE_REUSE.md 20,25,27 2>&1 | tail -14
clean; echo "=== GAS MATRIX ==="; timeout 5400 $PHP -d max_execution_time=0 tools/matrix.php $R $((P+4)) fixtures/input_actual.json $OUT/gas_matrix.csv 2>&1 | tail -40
for q in $(ps aux | grep -E "[p]hp7.4 .*-S 127.0.0.1:($((P+1))|$((P+2))|$((P+3))|$((P+4))) " | awk '{print $2}'); do kill $q 2>/dev/null; done; sleep 1   # server alat uji yang tertinggal
clean; echo "=== A/B ENGINE CLI (pipeline exact, V2 vs V3, 14 skenario) ==="; PP_EXACT_FAMILY=0 tools/ab_cmp2.sh $OUT/AB_ENGINE.txt $R /home/claude/t5/tlx/scen_reg.txt; cat $OUT/AB_ENGINE.txt
clean
(setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3   # server multi-backend (meniru Apache)
echo "=== POPUP PGN20 (V3: Save Input di atas modal) ==="; NODE_PATH=$N timeout 1200 node /home/claude/t5/v12/tests/browser_popup_responsiveness_v12.js http://127.0.0.1:$P $OUT/shots $OUT/POPUP.md 2>&1 | tail -10
clean; echo "=== VO TUTUP-TAB + RUN ULANG ==="; NODE_PATH=$N timeout 1500 node $T/vo_close_rerun.js http://127.0.0.1:$P $R/jobs 0 $OUT/VO_CLOSE_RERUN.md 2>&1 | tail -4
clean; echo "=== TARGETED12 (LNG rec, LNG under+Distillate, LNG over, Distillate, Batal) ==="; NODE_PATH=$N timeout 7200 node $T/targeted12_gas_decision.js http://127.0.0.1:$P $OUT/shots $OUT/TARGETED12.md 2>&1 | tail -60
clean; echo "=== UI DUA TAHAP PGN 30->31 ==="; NODE_PATH=$N timeout 1500 node $T/prov_ui.js http://127.0.0.1:$P pgn_pipe 30 31 $OUT/PROV_UI_PGN1.md 2>&1 | grep -v RESP | tail -8
clean; echo "=== UI DUA TAHAP PGN 32->33 ==="; NODE_PATH=$N timeout 1500 node $T/prov_ui.js http://127.0.0.1:$P pgn_pipe 32 33 $OUT/PROV_UI_PGN33.md 2>&1 | grep -v RESP | tail -8
clean; echo "=== GAS SHORTAGE PGN 25 (T1-T9) ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN25.md 25 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-240
clean; echo "=== GAS SHORTAGE PGN 20 (T1-T9) ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN20.md 20 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-240
clean; echo "=== UI TARGET SELESAI: MAXIMUM REVIEW (state baru) ==="; NODE_PATH=$N timeout 1500 node /home/claude/t5/integ/tl_ui_integ.js http://127.0.0.1:$P $OUT/TL_UI_MAX_FRESH.md max 2>&1 | grep -v "^RUN" | tail -8
clean; echo "=== UI TARGET SELESAI: <15, <25, <35, <45, <55, <60, Maximum Review ==="; NODE_PATH=$N timeout 3600 node /home/claude/t5/integ/tl_ui_integ.js http://127.0.0.1:$P $OUT/TL_UI.md 15,25,35,45,55,60,max 2>&1 | grep -v "^RUN" | tail -14
clean; echo "=== UI TARGET SELESAI: tanpa kandidat valid (PGN 20, < 15 detik) ==="; NODE_PATH=$N timeout 300 node $T/tl_novalid.js http://127.0.0.1:$P $R/jobs $OUT/TL_NOVALID.md 2>&1 | tail -2
bash /home/claude/t5/v4/kill_port.sh $P
echo "=== V3 UI: AUTO-SAVE, SAVE INPUT, SATU PEMILIK, INKREMENTAL (C1-C7) ==="
U=$S/ui_$P; rm -rf $U; SRC=$R bash v3/prep_ui.sh $U > /dev/null; rm -rf $U/jobs; mkdir -p $U/jobs; rm -f $U/output_data.json
(setsid node v3/proxy.js $((P+8)) $U 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 2400 node v3/save_ui.js http://127.0.0.1:$((P+8)) $U $OUT/SAVE_UI.md 2>&1 | tail -12
bash /home/claude/t5/v4/kill_port.sh $((P+8))
echo "=== FITUR LAMA LEWAT JALUR V3 (status unit, Mandatory Stop, Skip/Fix Load, Change Over) ==="
F=$S/feat_$P; rm -rf $F; SRC=$R bash v3/prep_ui.sh $F > /dev/null; rm -f $OUT/FEATURES.jsonl
timeout 5400 $PHP v4/v4_http.php $F $((P+10)) $OUT/FEATURES.jsonl warm /home/claude/t5/v3/feat_seq.txt /home/claude/t5/v3/snap_empty 2>&1 | cut -c1-240
echo "=== V3 HTTP: SLOT & KUOTA (cold, basis final BASE_ACT10) ==="
H=$S/http_$P; rm -rf $H; SRC=$R bash v3/prep_ui.sh $H > /dev/null; rm -f $OUT/HTTP_COLD.jsonl $OUT/HTTP_BASIS.jsonl
echo "--- basis BASE_ACT10 dihitung exact penuh oleh source beku ---"
timeout 3600 $PHP v4/v4_http.php $H $((P+9)) $OUT/HTTP_BASIS.jsonl warm BASE_ACT10 /home/claude/t5/v3/snap_empty 2>&1 | tail -1
SB=$S/snapreg_$P; rm -rf $SB; mkdir -p $SB; cp -a $H/jobs/_final $H/jobs/_tl $SB/
timeout 7200 $PHP v7/v7_http.php $H $((P+9)) $OUT/HTTP_COLD.jsonl cold ACT_PGN_UP,ACT_PGN_DOWN,ACT_FFJ_UP,ACT_FFJ_DOWN,ACT_FFM_DOWN,KP72_DOWN,ACT_PGN_2SLOT_C,QA_pgn_pipe_1,QA_lng_1,QA_pgn_pipe_-1,ACT_PGN_BIG,QA_pep_1,QA_pep_-1,QA_akasia_1,QA_pep_kp72_1 $SB 2>&1 | tail -14
echo "=== V3 HTTP: WARM (rantai satu server) ==="; rm -f $OUT/HTTP_WARM.jsonl
timeout 7200 $PHP v4/v4_http.php $H $((P+9)) $OUT/HTTP_WARM.jsonl warm ACT_PGN_UP,ACT_PGN_DOWN,ACT_PGN_UP,ACT_PGN_2SLOT_C,KP72_DOWN,ACT_FFJ_UP,QA_lng_1 $SB 2>&1 | tail -6
echo "=== V4 DETERMINISME: PEKERJA PEMBANTU vs TANPA PEMBANTU (hasil wajib identik) ==="; rm -f $OUT/DET_HELPERS.jsonl $OUT/DET_NOHELPERS.jsonl
timeout 3600 $PHP v4/v4_http.php $H $((P+9)) $OUT/DET_HELPERS.jsonl cold ACT_PGN_UP,ACT_FFM_DOWN,QA_lng_1 $SB 2>&1 | tail -3
PP_V4_HELPERS=0 timeout 3600 $PHP v4/v4_http.php $H $((P+9)) $OUT/DET_NOHELPERS.jsonl cold ACT_PGN_UP,ACT_FFM_DOWN,QA_lng_1 $SB 2>&1 | tail -3
python3 - $OUT/DET_HELPERS.jsonl $OUT/DET_NOHELPERS.jsonl <<'PY'
import json,sys
a=[json.loads(l) for l in open(sys.argv[1])]; b=[json.loads(l) for l in open(sys.argv[2])]
ok=all(x['cp']==y['cp'] and x['sc']==y['sc'] for x,y in zip(a,b)) and len(a)==len(b)
for x,y in zip(a,b): print(x['sc'], 'cp', x['cp'], y['cp'], 'final', x['t_final_s'], y['t_final_s'], 'helpers', x.get('helpers'), y.get('helpers'))
print('DETERMINISME_IDENTIK' if ok else 'DETERMINISME_BEDA')
PY
echo "=== V3 A/B: INKREMENTAL vs EXACT PENUH (PGN 30 -> 31 -> PEP+1, tanpa actual) ==="; rm -f $OUT/AB_INC_EXACT.jsonl $OUT/AB_INC_ON.jsonl
PP_V3_INCREMENTAL=0 timeout 3600 $PHP v4/v4_http.php $H $((P+9)) $OUT/AB_INC_EXACT.jsonl warm BASE_PGN30,Q_pgn_pipe_1,Q_pep_1 /home/claude/t5/v3/snap_empty 2>&1 | tail -3
timeout 3600 $PHP v4/v4_http.php $H $((P+9)) $OUT/AB_INC_ON.jsonl warm BASE_PGN30,Q_pgn_pipe_1,Q_pep_1 /home/claude/t5/v3/snap_empty 2>&1 | tail -3
clean
echo "=== UI: PERUBAHAN SIMULATION DATA PER SLOT ==="
V=$S/slotui_$P; rm -rf $V; SRC=$R bash v3/prep_ui.sh $V > /dev/null
(setsid node v3/proxy.js $((P+11)) $V 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 5400 node $T/slot_ui.js http://127.0.0.1:$((P+11)) $OUT/SLOT_UI.md $OUT/SLOT_UI.jsonl 2>&1 | cut -c1-240
bash /home/claude/t5/v4/kill_port.sh $((P+11))
echo "=== UI: FORCED STOP/START DAN CHANGE OVER ==="
O=$S/opsui_$P; rm -rf $O; mkdir -p $O/jobs; cp $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $R/saved_data_store.php $O/; cp fixtures/input_actual.json $O/input_data.json
(setsid node v3/proxy.js $((P+12)) $O 6 >/dev/null 2>&1 </dev/null &); sleep 3
CASE_MS=300000 NODE_PATH=$N timeout 5400 node $T/ops_ui.js http://127.0.0.1:$((P+12)) $O /home/claude/t5/fixtures/input_actual.json $OUT/OPS_UI.md $OUT/OPS_UI.jsonl 2>&1 | cut -c1-240
bash /home/claude/t5/v4/kill_port.sh $((P+12))
clean
echo "=== UI: PERUBAHAN KUOTA (basis Actual 11 jam) ==="
K=$S/quotaui_$P; rm -rf $K; SRC=$R bash v3/prep_ui.sh $K > /dev/null
(setsid node v3/proxy.js $((P+13)) $K 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 7200 node /home/claude/t5/v7/tests/quota_ui.js http://127.0.0.1:$((P+13)) $OUT/QUOTA_UI_ACT.md $OUT/QUOTA_UI_ACT.jsonl "Actual 11 jam" "pgn_pipe:1,pgn_pipe:-1,pep:1,pep:-1,lng:1,pep_kp72:1,akasia:-1,pgn_pipe:-1+pep:2" 2>&1 | cut -c1-240
bash /home/claude/t5/v4/kill_port.sh $((P+13))
clean
echo "=== V8: REPRODUCER + GAS GE + UNIT PRIORITY (jalur job) ==="
timeout 3600 $PHP -d max_execution_time=0 v8/v8_cases.php $R $OUT/V8_CASES.md $OUT/V8_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
clean
echo "=== V8 UI: REPRODUCER Daily_Plan_09_Jul_26_Baru ==="
W=$S/wbui_$P; rm -rf $W; mkdir -p $W/jobs; cp $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $R/saved_data_store.php $W/; cp v8/input_WB09.json $W/input_data.json
(setsid node v3/proxy.js $((P+14)) $W 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=$N timeout 1800 node /home/claude/t5/v8/tests/wb09_ui.js http://127.0.0.1:$((P+14)) $OUT/WB09_UI.md $OUT/WB09_UI.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
bash /home/claude/t5/v4/kill_port.sh $((P+14))
clean
echo "=== V9: UNIT PRIORITY GENERIK (10 konfigurasi) + PROVENANCE BAHAN BAKAR ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v9_cases_v11.php $R $OUT/V9_CASES.md $OUT/V9_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
clean
echo "=== V10 (versi V11): RUTE CEPAT, SERTIFIKAT, SCREENING DUA TINGKAT ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v10_cases_v11.php $R $OUT/V10_CASES.md $OUT/V10_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
clean
echo "=== V11: REPRODUCER WB09_3 + AUDIT V11 + BAND CP/HEAT RATE + COUNTER ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v11_cases.php $R $OUT/V11_CASES.md $OUT/V11_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-300
clean
echo "=== V12: UJI GENERIK MERIT DISPATCH (12 state nyata) ==="
rm -rf $OUT/merit_out; V12_SAVE_OUT=$OUT/merit_out bash v12/http1.sh $R $((P+20)) $OUT/MERIT_RUN.jsonl cold /home/claude/t5/v12/merit_cases.txt > /dev/null 2>&1
$PHP v12/merit_check.php $OUT/merit_out v12/merit_cases.map $OUT/V12_MERIT.md $OUT/V12_MERIT.jsonl 2>&1 | grep -E "^(PASS|FAIL)|PASS$" | cut -c1-300
echo "=== V12: RANTAI BAHAN BAKAR GAS SHORTAGE PGN 25 ==="
bash v12/fuelchain.sh $R $((P+21)) $OUT/V12_FUEL_CHAIN.jsonl 2>&1 | head -5
echo "=== V12 DETERMINISME: PEMBANTU ON vs OFF (rute V12: decommit paralel, tugas samping, rerun bahan bakar) ==="
bash v12/http1.sh $R $((P+22)) $OUT/V12_DET_ON.jsonl cold "Q_pgn_pipe_1,QA_pgn_pipe_4,ACT_FFJ_UP,WB09_3" > /dev/null 2>&1
PP_V4_HELPERS=0 bash v12/http1.sh $R $((P+22)) $OUT/V12_DET_OFF.jsonl cold "Q_pgn_pipe_1,QA_pgn_pipe_4,ACT_FFJ_UP,WB09_3" > /dev/null 2>&1
python3 - $OUT/V12_DET_ON.jsonl $OUT/V12_DET_OFF.jsonl <<'PY'
import json,sys
a=[json.loads(l) for l in open(sys.argv[1]) if l.startswith('{')]; b=[json.loads(l) for l in open(sys.argv[2]) if l.startswith('{')]
ok=len(a)==len(b)==4 and all(x['sc']==y['sc'] and x['cp']==y['cp'] and x.get('sig')==y.get('sig') for x,y in zip(a,b))
for x,y in zip(a,b): print(x['sc'], 'cp', x['cp'], y['cp'], 'sig', x.get('sig'), y.get('sig'), 'final', x['t_final_s'], y['t_final_s'], 'helpers', x.get('helpers'), y.get('helpers'))
print('V12_DETERMINISME_IDENTIK' if ok else 'V12_DETERMINISME_BEDA')
PY
echo "=== HASH AKHIR ==="; (cd $R && sha256sum run.php worker02.php worker_functions.php index.php saved_data_store.php) | tee $OUT/_hash_akhir.txt
cmp -s <(awk '{print $1}' $OUT/_hash_awal.txt) <(awk '{print $1}' $OUT/_hash_akhir.txt) && echo "HASH TIDAK BERUBAH SELAMA REGRESSION" || echo "HASH BERUBAH!"
bash integ/h/integ_extra.sh $R $((P+40)) $OUT/integ
echo SELESAI
