#!/bin/bash
# suite browser final (server 8792, source beku)
cd /home/claude/t5; O=/home/claude/ab2/op/ui16; R=/tmp/claude-0/abD2; N="env NODE_PATH=/opt/node22/lib/node_modules"; B=http://127.0.0.1:8792
inp(){ cp "$1" $R/input_data.json; rm -rf $R/jobs/*; }
inp /home/ab_dummy 2>/dev/null
inp /home/claude/ab/payloads/P0.json;            timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_golden_fast.json dist > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_golden_max.json dist max > /dev/null 2>&1
inp /home/claude/ab/payloads/GOLDEN_dist.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_goldendist_max.json dist max > /dev/null 2>&1
inp /home/claude/ab/payloads/OP_R1_pgn25.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_R1_pgn25.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P10_pv_on.json;     timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_P10_pv.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P14_co_b2b1.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_CO1.json lng > /dev/null 2>&1
inp /home/claude/ab2/op/sc/CO0_b1_to_b2.json;    timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_CO0.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/R5_BASE_ACT10.json; timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/F_R5_actual.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P12_g8_unavail.json; timeout 100 $N node /home/claude/ab2/op/uirun.js $B $O/F_P12_terminal.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 400 $N node /home/claude/ab2/op/uiwarm.js $B $O/F_uiwarm.json > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 300 $N node /home/claude/ab2/op/uiie.js $B $O > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 400 $N node /home/claude/ab2/op/ie17.js $B $O/F_ie17.json > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 300 $N node /home/claude/ab2/op/uiopt.js $B $O > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 400 $N node /home/claude/ab2/tools/conc.js $B $O/F_conc.json > $O/F_conc.log 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 300 $N node /home/claude/ab2/tools/c5.js $B > $O/F_c5.log 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 300 $N node /home/claude/ab2/tools/conc_sup.js $B $O/F_sup.json > $O/F_sup.log 2>&1
cp /home/claude/ab/payloads/P0.json $R/input_data.json
echo UIDONE > $O/F_done
