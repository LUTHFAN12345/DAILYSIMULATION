#!/bin/bash
# Fastest di Linux nyata (Apache + PHP-FPM, www-data), cold per run (jobs/ dikosongkan), input kasus dipasang sebagai input_data.json.
# Arg: <out.jsonl> <reps> <case:input.json>...
cd /home/claude/t5; OUT=$1; REPS=$2; shift 2; : > $OUT; APP=/var/www/html/php74/opr-simulation; N=/home/claude/.npm-global/lib/node_modules
for rep in $(seq 1 $REPS); do for ci in "$@"; do c=${ci%%:*}; f=${ci#*:}
  rm -rf "${APP:?}/jobs"; install -d -o www-data -g www-data -m 2775 $APP/jobs; install -o www-data -g www-data -m 0664 $f $APP/input_data.json
  NODE_PATH=$N timeout 600 node integ/fast5.js http://127.0.0.1:8074/php74/opr-simulation $c 2>>$OUT.err | python3 -c "
import json,sys
for l in sys.stdin:
    r=json.loads(l); r['platform']='Linux Apache 2.4 mpm_event + PHP-FPM 7.4 (www-data), OPcache on'; print(json.dumps(r))" >> $OUT
done; done
