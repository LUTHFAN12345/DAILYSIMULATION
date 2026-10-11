#!/bin/bash
# runfx.sh <src> <outdir> <fixture...> : dumpjob CLI per fixture + ringkasan
SRC=$1; OUT=$2; shift 2; mkdir -p $OUT
for f in "$@"; do n=$(basename $f .json); rm -rf $SRC/jobs; timeout 300 php /home/claude/ab2/tools/dumpjob.php $SRC $f $OUT/$n.json >/dev/null 2>&1; python3 -I /home/claude/ab2/op/sumfx.py $OUT/$n.json $n; done
