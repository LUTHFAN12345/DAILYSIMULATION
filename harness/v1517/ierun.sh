#!/bin/bash
# ierun.sh <src> <outdir> <fixture...> : dumpjob berurutan TANPA membersihkan cache job (basis warm dipertahankan)
SRC=$1; OUT=$2; shift 2; mkdir -p $OUT
for f in "$@"; do n=$(basename $f .json); timeout 300 php /home/claude/ab2/tools/dumpjob.php $SRC $f $OUT/$n.json >/dev/null 2>&1; python3 -I /home/claude/ab2/op/sumfx.py $OUT/$n.json $n | grep -v "^    G"; done
