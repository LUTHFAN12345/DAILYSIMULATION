#!/bin/bash
# racc2.sh : re-acceptance ulang kasus Fastest setelah patch anggaran V8 adaptif (server 8792)
cd /home/claude/ab; O=/home/claude/ab2/runs/racc2; mkdir -p $O; : > $O/summary.txt
M="tools/matrix.sh 8792 /tmp/claude-0/abD2 $O"
for i in 1 2 3; do $M R0_c$i:GOLDEN_dist:fast:-:cold; done; for i in 1 2 3; do $M R0_w$i:GOLDEN_dist:fast:-:warm; done
for i in 1 2 3; do $M R2_c$i:P0:fast:lng:cold; done; for i in 1 2 3; do $M R2_w$i:P0:fast:lng:warm; done
for i in 1 2 3; do $M R3_c$i:P0:fast:dist:cold; done; for i in 1 2 3; do $M R3_w$i:P0:fast:dist:warm; done
$M R4_pvon_lng:P10_pv_on:fast:lng:cold; $M R4_pvon_dist:P10_pv_on:fast:dist:cold; $M R4_pvoff_lng:P0:fast:lng:cold
for i in 1 2 3; do $M R5_c$i:R5_BASE_ACT10:fast:lng:cold; done; $M R5_w1:R5_BASE_ACT10:fast:lng:warm
$M R6_pgn22:P2_pgn22:fast:lng:cold; $M R6_pgn24:P3_pgn24:fast:lng:cold; $M R6_pep35:P4_pep35:fast:lng:cold; $M R6_pep37:P5_pep37:fast:lng:cold
$M R9_co:P14_co_b2b1:fast:-:cold
$M X_P13_reqstart:P13_req_g1_0800:fast:lng:cold; $M X_P11:P11_g8_stop_avail:fast:lng:cold; $M X_P12_terminal:P12_g8_unavail:fast:-:cold
MAXT=300 $M R1_c1:GOLDEN_dist:max:-:cold; MAXT=300 $M R1_w1:GOLDEN_dist:max:-:warm
MAXT=300 $M X_P1_max_lng:P0:max:lng:cold
echo MATRIXDONE >> $O/summary.txt
