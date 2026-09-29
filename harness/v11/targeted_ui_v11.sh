#!/bin/bash
# V11 salinan v6/targeted_ui.sh (TL UI memakai v11/tl_ui_v11.js). Targeted test V6 (UI + HTTP) — fitur lama lewat jalur V3 (satu pemilik, auto-save, Save Input aktif).
# Arg: <akar> <folder-laporan> <port>
cd /home/claude/t5; PHP=/usr/local/bin/php74; N=/home/claude/.npm-global/lib/node_modules; T=/home/claude/t5/v6/tests
R=$1; OUT=$2; P=$3; mkdir -p $OUT
clean(){ cp fixtures/input_actual.json $R/input_data.json; rm -f $R/output_data.json; rm -rf $R/input_data.json.lock $R/jobs; mkdir -p $R/jobs; }
for f in run.php worker02.php worker_functions.php index.php; do $PHP -l $R/$f; done
clean; (setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
clean; echo "=== POPUP PGN20 ==="; NODE_PATH=$N timeout 1200 node $T/browser_popup_responsiveness.js http://127.0.0.1:$P $OUT/shots $OUT/POPUP.md 2>&1 | tail -9
clean; echo "=== GAS SHORTAGE PGN 25 ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN25.md 25 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-260
clean; echo "=== GAS SHORTAGE PGN 20 ==="; NODE_PATH=$N timeout 3600 node $T/targeted_gs.js http://127.0.0.1:$P $R/jobs $OUT/GAS_SHORTAGE_PGN20.md 20 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-260
clean; echo "=== TL UI (15,25,35,45,55,60,max) ==="; NODE_PATH=$N timeout 3600 node /home/claude/t5/v11/tl_ui_v11.js http://127.0.0.1:$P $OUT/TL_UI.md 15,25,35,45,55,60,max 2>&1 | grep -v "^RUN" | tail -14 | cut -c1-260
clean; echo "=== TL NOVALID ==="; NODE_PATH=$N timeout 300 node $T/tl_novalid.js http://127.0.0.1:$P $R/jobs $OUT/TL_NOVALID.md 2>&1 | tail -3 | cut -c1-260
clean; echo "=== PROV UI PGN 30->31 ==="; NODE_PATH=$N timeout 1500 node $T/prov_ui.js http://127.0.0.1:$P pgn_pipe 30 31 $OUT/PROV_UI_PGN1.md 2>&1 | grep -v RESP | tail -8 | cut -c1-260
if [ -z "$SKIP12" ]; then clean; echo "=== TARGETED12 ==="; NODE_PATH=$N timeout 7200 node $T/targeted12_gas_decision.js http://127.0.0.1:$P $OUT/shots $OUT/TARGETED12.md 2>&1 | grep -E "PASS|FAIL|/" | tail -70 | cut -c1-220; fi
bash /home/claude/t5/v4/kill_port.sh $P
echo TARGETED_SELESAI
