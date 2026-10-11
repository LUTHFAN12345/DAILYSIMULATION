#!/bin/bash
# run1.sh <fixture.json> <tag> : satu job Fastest CLI PHP 7.4 pada salinan dev terpisah
F=$1; T=$2; D=/tmp/claude-0/a3_$T; rm -rf "${D:?}"; mkdir -p $D; cp /home/claude/ab3/dev/*.php $D/
php74 /home/claude/ab2/tools/dumpjob.php $D $F /home/claude/ab3/out/$T.json >/dev/null 2>&1
