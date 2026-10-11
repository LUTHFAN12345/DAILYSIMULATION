#!/bin/bash
cd /home/claude/t5; O=/home/claude/ab2/op/linux; APP=/var/www/html/php74/opr-simulation; N="env NODE_PATH=/opt/node22/lib/node_modules"; B=http://127.0.0.1:8074/php74/opr-simulation
inp(){ rm -rf "${APP:?}/jobs"; install -d -o www-data -g www-data -m 2775 $APP/jobs; install -o www-data -g www-data -m 0664 "$1" $APP/input_data.json; }
inp /home/claude/ab/payloads/P0.json;            timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_golden_fast.json dist > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_golden_max.json dist max > /dev/null 2>&1
inp /home/claude/ab/payloads/GOLDEN_dist.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_goldendist_max.json dist max > /dev/null 2>&1
inp /home/claude/ab/payloads/OP_R1_pgn25.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_R1_pgn25.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P10_pv_on.json;     timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_P10_pv.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P14_co_b2b1.json;   timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_CO1.json lng > /dev/null 2>&1
inp /home/claude/ab2/op/sc/CO0_b1_to_b2.json;    timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_CO0.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/R5_BASE_ACT10.json; timeout 200 $N node /home/claude/ab2/op/uirun.js $B $O/L_R5_actual.json lng > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json;            timeout 400 $N node /home/claude/ab2/op/uiwarm.js $B $O/L_uiwarm.json > /dev/null 2>&1
inp /home/claude/ab/payloads/P0.json
echo LDONE > $O/L_done
