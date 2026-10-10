#!/bin/bash
cd /home/claude/ab; O=runs/final2; mkdir -p $O; : > $O/summary.txt
M="tools/matrix.sh 8791 /tmp/claude-0/abD $O"
for i in 1 2 3; do $M P0_c$i:P0:fast:-:cold; done; for i in 1 2 3; do $M P0_w$i:P0:fast:-:warm; done
for i in 1 2 3; do $M P7_c$i:P0:fast:lng:cold; done; for i in 1 2 3; do $M P7_w$i:P0:fast:lng:warm; done
for i in 1 2 3; do $M P8_c$i:P0:fast:dist:cold; done; for i in 1 2 3; do $M P8_w$i:P0:fast:dist:warm; done
for i in 1 2 3; do $M P10_c$i:P10_pv_on:fast:lng:cold; done; for i in 1 2 3; do $M P10_w$i:P10_pv_on:fast:lng:warm; done
$M P10d_c1:P10_pv_on:fast:dist:cold
$M P2:P2_pgn22:fast:lng:cold; $M P3:P3_pgn24:fast:lng:cold; $M P4:P4_pep35:fast:lng:cold; $M P5:P5_pep37:fast:lng:cold
$M P11:P11_g8_stop_avail:fast:lng:cold; $M P12:P12_g8_unavail:fast:-:cold; $M P13:P13_req_g1_0800:fast:lng:cold; $M P14:P14_co_b2b1:fast:-:cold
$M GOLDEN_fast:GOLDEN_dist:fast:-:cold
MAXT=600 $M P1:P0:max:lng:cold
MAXT=400 $M GOLDEN_max:GOLDEN_dist:max:-:cold
echo MATRIXDONE >> $O/summary.txt
