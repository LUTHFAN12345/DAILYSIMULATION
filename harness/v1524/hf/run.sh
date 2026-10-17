#!/bin/bash
# run.sh <tag> <input> <fault-mode|none> [slots]
tag=$1; in=$2; mode=$3; slots=$4; app=/tmp/w/app; out=/tmp/w/h/hf/$tag.json
export PP_V4_HELPERS=3
read id tok nh < <(/opt/php74/bin/php /tmp/w/h/hf/mk.php $app $in)
t0=$(date +%s.%N)
/opt/php74/bin/php $app/run.php --job=$id > /tmp/w/h/hf/$tag.owner.log 2>&1 & OW=$!
hp=()
for s in 1 2 3; do
  if [ "$mode" != none ]; then FENV="PP_V1524_FAULT=$mode PP_V1524_FAULT_SLOTS=$slots"; else FENV=""; fi
  env $FENV /opt/php74/bin/php /tmp/w/h/hf/helper.php $app $id $s > /tmp/w/h/hf/$tag.h$s.log 2>&1 & hp+=($!)
done
wait $OW; t1=$(date +%s.%N)
sleep 1; for p in "${hp[@]}"; do kill $p 2>/dev/null; done; wait 2>/dev/null
cp $app/jobs/$id/result.json $out 2>/dev/null
echo "$tag id=$id helpers=$nh wall=$(echo "$t1-$t0"|bc) rc_helpers=$(for s in 1 2 3; do tail -c 200 /tmp/w/h/hf/$tag.h$s.log | tr '\n' ' ' | cut -c1-80; echo -n '|'; done)"
