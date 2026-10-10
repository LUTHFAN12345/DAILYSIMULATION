#!/bin/bash
# matrix.sh <port> <root> <outdir> <spec...> ; spec = tag:payload:target:fuel(lng|dist|-):mode(cold|warm)
P=$1; R=$2; OUT=$3; shift 3; mkdir -p $OUT
for sp in "$@"; do IFS=: read tag pl tg fu md <<< "$sp"
  [ "$fu" = "-" ] && fu=""; W=""; [ "$md" = "warm" ] && W=1
  WARM=$W MAXT=${MAXT:-240} TARGET=$tg FUEL=$fu /home/claude/ab/tools/abrun.sh $P $R /home/claude/ab/payloads/$pl.json $OUT $tag > $OUT/$tag.log 2>&1
  python3 -I -c "
import json,sys;R=json.load(open('$OUT/$tag.json'));r=R['result']
print('%-22s t_end=%-6s popup=%-6s click=%-6s rows=%s status=%s gate=%s cp=%s hr=%s act=%s lng=%s dist=%s proof=%s srU=%s blk=%s err=%s'%('$tag',R['t_end'],R.get('popup_ready_at'),R.get('fuel_click_at'),r.get('rows'),r.get('status'),r.get('gate'),r.get('cp'),r.get('hr'),r.get('action'),r.get('lng_added'),r.get('dist_l'),json.dumps(r.get('proof')),r.get('sr_under'),r.get('blocking'),R['errors'][:1]))
print('   msg:',R.get('msg_final','')[:200].replace('\n',' | '))" 2>&1 | tee -a $OUT/summary.txt
done
