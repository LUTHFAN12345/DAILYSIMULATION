#!/bin/bash
# ucpu.sh <srcdir> <fx> : CPU time (user+sys, detik) satu job Fastest CLI (getrusage anak lewat bash `times`)
S=$1; D=/tmp/claude-0/uc_$$; rm -rf "${D:?}"; mkdir -p $D; cp $S/*.php $D/
php74 /home/claude/ab2/tools/dumpjob.php $D /home/claude/ab3/fx/$2.json $D/o.json >/dev/null 2>&1
times > $D.t; tail -1 $D.t | awk '{u=$1; s=$2; gsub("s","",u); gsub("s","",s); split(u,a,"m"); split(s,b,"m"); printf "%.2f\n", a[1]*60+a[2]+b[1]*60+b[2]}'
rm -rf "${D:?}" "$D.t"
