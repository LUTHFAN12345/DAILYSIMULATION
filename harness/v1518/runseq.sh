#!/bin/bash
# runseq.sh <srcdir> <tag> fx1 fx2 ... : beberapa job Fastest CLI berurutan pada SATU direktori (cache warm dipertahankan)
S=$1; T=$2; shift 2; D=/tmp/claude-0/sq_$T; rm -rf "${D:?}"; mkdir -p $D; cp $S/*.php $D/
for f in "$@"; do php74 /home/claude/ab2/tools/dumpjob.php $D /home/claude/ab3/fx/$f.json /home/claude/ab3/out/${T}_$f.json >/dev/null 2>&1; done
