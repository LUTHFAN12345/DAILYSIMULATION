#!/bin/bash
# Menyiapkan akar HTTP UI: 4 berkas dari tgs, jobs = snapshot basis (sidik jari disesuaikan),
# input_data.json = BASE_ACT10, output_data.json = hasil final basis. Arg: <akar>
R=$1; cd /home/claude/t5
SRC=${SRC:-tgs}; mkdir -p $R && cp $SRC/run.php $SRC/worker02.php $SRC/worker_functions.php $SRC/index.php $SRC/saved_data_store.php $R/
rm -rf $R/jobs && cp -a ${SNAP:-v3/snap_base} $R/jobs
FP=$(cd $R && /usr/local/bin/php74 -r 'define("PP_LIB_ONLY",1); require "run.php"; echo "FP=".pp_engine_fingerprint();' 2>/dev/null | grep -o 'FP=[0-9a-f]*' | cut -c4-)
python3 - "$FP" "$R" <<'PY'
import json,glob,sys
for f in glob.glob(sys.argv[2]+'/jobs/_final/*.json'):
    d=json.load(open(f))
    if 'v3' in d: d['v3']['engine']=sys.argv[1]
    elif f.endswith('.v10lib.json'): d['engine']=sys.argv[1]
    json.dump(d,open(f,'w'))
PY
/usr/local/bin/php74 -r 'require "/home/claude/t5/v3/scen.php"; $x=v3_input("BASE_ACT10"); foreach(array_keys($x) as $k) if($k[0]==="_") unset($x[$k]); file_put_contents($argv[1]."/input_data.json", json_encode($x, JSON_PRETTY_PRINT));' $R
cp v3/base_act10_output.json $R/output_data.json
rm -rf $R/input_data.json.lock
echo "prep $R FP=$FP"
