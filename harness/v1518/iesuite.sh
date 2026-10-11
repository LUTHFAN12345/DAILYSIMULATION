#!/bin/bash
# iesuite.sh tag fx... : per fixture satu tab: base + IEW1..3 (IE berubah di halaman yang sama)
R=/tmp/claude-0/ui18; T=$1; shift; SPEC='[[["+",5,"10:00","12:00"]],[["-",5,"14:00","15:00"]],[["+",8,"18:00","19:30"]]]'
for f in "$@"; do rm -rf "${R:?}"/jobs/*; cp /home/claude/ab3/fx/$f.json $R/input_data.json
 env NODE_PATH=/opt/node22/lib/node_modules timeout 900 node /home/claude/ab3/perf/uiie.js http://127.0.0.1:8818 /home/claude/ab3/perf/${T}_$f.json "$SPEC" "$PRE"
 python3 -I -c "
import json,sys;R=json.load(open(sys.argv[1]))
for r in R['runs']: print(sys.argv[2],r['label'],r['t_ui'],r['ok'],r['cp'],r['hr'],r['gate'],r['merit'],r['cls'],(r['reason'] or '')[:60],r['engine_s'],[(p['act'],p['skip']) for p in r['posts']])" /home/claude/ab3/perf/${T}_$f.json $f >> /home/claude/ab3/perf/$T.log; done; echo DONE >> /home/claude/ab3/perf/$T.log
