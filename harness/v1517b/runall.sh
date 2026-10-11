#!/bin/bash
# runall.sh : T1-T5 final pada source freeze (fz2), berurutan, PHP 7.4 CLI
F=/home/claude/ab2/fz2; O=/home/claude/ab2/bb/ttf
one() { D=/tmp/claude-0/ttf_$2; mkdir -p $D; [ -f $D/run.php ] || cp $F/*.php $D/; php74 /home/claude/ab2/tools/dumpjob.php $D $3 $O/out_$1.json >/dev/null 2>&1; }
rm -rf /tmp/claude-0/ttf_*
one T0_base W $O/T0_base.json
one T2_ie_plus10 W $O/T2_ie_plus10.json
for n in T1_F2_g9_not_required T1_F1_g9_start_0700 T1_F1_g9_start_1400 T3_pgn22 T4_g5_unavailable; do one $n $n $O/$n.json; done
one T5_golden_D0 G /home/claude/ab2/op/fx/D0_golden.json
echo ALLDONE
