#!/bin/bash
# abrun.sh <port> <root> <payload> <outdir> <tag> [WARM=1] : copy payload, cold (hapus jobs/cache) kecuali WARM, jalankan ab_ui.js
P=$1; R=$2; PL=$3; OUT=$4; TAG=$5; mkdir -p $OUT
if [ -z "$WARM" ]; then rm -rf "$R/jobs" "$R/saved_data_history" "$R/data" "$R/output_data.json" "$R/saved_data_store.json" 2>/dev/null; fi
cp "$PL" "$R/input_data.json"
NODE_PATH=/home/claude/.npm-global/lib/node_modules timeout $(( ${MAXT:-240} + 60 )) node /home/claude/ab/tools/ab_ui.js http://127.0.0.1:$P $TAG $OUT/$TAG.json 2>&1 | tail -2 | cut -c1-1500
