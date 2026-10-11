#!/bin/bash
# ab.sh : T0-T9 + M0-M5, referensi a38cede vs dev, 4 paralel
cd /home/claude/ab3
L="T0 T1 T2 T3 T4 T5 T6 T7 T8 T9_golden M0 M1 M2 M3 M4 M5"
jobs=()
for f in $L; do echo "ref_a38 $f r_$f"; echo "dev $f d_$f"; done | xargs -P 4 -L 1 bash -c '/home/claude/ab3/perf/runv.sh /home/claude/ab3/$0 fx/$1.json $2'
echo ABDONE
