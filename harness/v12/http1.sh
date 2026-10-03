#!/bin/bash
# V12: jalankan skenario HTTP (v7_http, cold per skenario dari snapshot) pada salinan source. Arg: <src> <port> <out.jsonl> <mode cold|warm> <SC,...> [snap]
cd /home/claude/t5; SRC=$1; P=$2; OUT=$3; M=$4; SC=$5; SN=${6:-/home/claude/t5/v10/snap_act}
H=/tmp/claude-0/h12_$P; rm -rf $H; mkdir -p $H; cp -L $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $H/
bash v4/kill_port.sh $P >/dev/null 2>&1
timeout 3600 /usr/local/bin/php74 v12/v12_http.php $H $P $OUT $M "$SC" $SN > /dev/null 2>&1
python3 - "$OUT" <<'PY'
import json,sys
for l in open(sys.argv[1]):
    if not l.startswith('{'): continue
    r=json.loads(l); print('%-18s first %-6s final %-6s cp %-8s %-18s core %s sig %s csig %s hr %s cnt %s' % (r['sc'], r.get('t_first_valid_s'), r.get('t_final_s'), r.get('cp'), r.get('computation'), r.get('core_simulations'), r.get('sig'), r.get('csig'), r.get('hr'), r.get('cnt')))
PY
