#!/bin/bash
# devrun.sh <TestID|input.json> [cpu] [env...] : sinkronkan source repo -> /tmp/w/dev/app<cpu>, run dingin PHP 7.4 pinned, ringkas hasil
T=$1; C=${2:-1}; shift 2; R=/home/user/DAILYSIMULATION; A=/tmp/w/dev/app$C
mkdir -p $A/jobs; cp $R/*.php $A/; cp -r $R/config $R/install $R/database $A/ 2>/dev/null; [ -n "$WARM" ] || find $A/jobs -mindepth 1 -delete
if [ -f "$T" ]; then IN=$T; else H=$(python3 -c "import json;print(json.load(open('/tmp/w/base1524_evd/$T.json'))['payload_hash'])"); IN=/tmp/w/base1524_evd/runs/$H.in.json; fi
O=/tmp/w/dev/out_$(basename $T .json)_c$C.json
env "$@" taskset -c $C /opt/php74/bin/php $R/harness/v1524/runjob2.php $A $IN $O 2>&1 | tail -1
python3 $R/harness/v1525/dev/summ.py $O
