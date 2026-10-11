#!/bin/bash
# runv.sh <srcdir> <fixture.json> <tag> : satu job Fastest CLI PHP 7.4 dari sumber tertentu
S=$1; F=$2; T=$3; D=/tmp/claude-0/a3_$T; rm -rf "${D:?}"; mkdir -p $D; cp $S/*.php $D/
php74 /home/claude/ab2/tools/dumpjob.php $D $F /home/claude/ab3/out/$T.json >/dev/null 2>&1
