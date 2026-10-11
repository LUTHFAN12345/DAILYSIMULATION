#!/bin/bash
# ab2.sh : T0-T9 + M0-M5 pada dev terbaru, 4 paralel, dibandingkan dengan d_* sebelumnya
cd /home/claude/ab3
L="T0 T1 T2 T3 T4 T5 T6 T7 T8 T9_golden M0 M1 M2 M3 M4 M5"
for f in $L; do echo "dev $f e_$f"; done | xargs -P 4 -L 1 bash -c '/home/claude/ab3/perf/runv.sh /home/claude/ab3/$0 fx/$1.json $2'
echo ABDONE
