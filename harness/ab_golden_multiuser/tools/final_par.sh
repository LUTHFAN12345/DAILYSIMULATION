#!/bin/bash
# final_par.sh : R8 (XAMPP-12) + R10 Linux parity + R8 Linux pada source final freeze2
NP=/home/claude/.npm-global/lib/node_modules; U=/home/claude/ab2/ui
NODE_PATH=$NP timeout 900 node /home/claude/ab2/tools/conc.js http://127.0.0.1:8730 $U/conc_final_xampp12.json > $U/conc_final_xampp12.log 2>&1
bash /home/claude/t5/integ/linux/setup_linux.sh /home/claude/ab2/freeze2 /home/claude/ab/payloads/P0.json 8074 > /dev/null 2>&1
cd /home/claude/ab; P=payloads; rm -rf runs/linux; mkdir -p runs/linux
FUEL= tools/linrun.sh $P/GOLDEN_dist.json L_R0 fast
FUEL=lng tools/linrun.sh $P/P0.json L_R2 fast
FUEL=dist tools/linrun.sh $P/P0.json L_R3 fast
FUEL=lng tools/linrun.sh $P/P10_pv_on.json L_R4 fast
FUEL= tools/linrun.sh $P/R5_BASE_ACT10.json L_R5 fast
FUEL=lng tools/linrun.sh $P/P5_pep37.json L_R6 fast
FUEL= tools/linrun.sh $P/P14_co_b2b1.json L_R9 fast
FUEL= tools/linrun.sh $P/P12_g8_unavail.json L_P12 fast
MAXT=300 FUEL= tools/linrun.sh $P/GOLDEN_dist.json L_R1 max
echo LINDONE > runs/linux/LINDONE
install -o www-data -g www-data -m 0664 $P/P0.json /var/www/html/php74/opr-simulation/input_data.json
NODE_PATH=$NP timeout 900 node /home/claude/ab2/tools/conc.js http://127.0.0.1:8074/php74/opr-simulation $U/conc_final_linux.json > $U/conc_final_linux.log 2>&1
NODE_PATH=$NP timeout 400 node /home/claude/ab2/tools/conc_sup.js http://127.0.0.1:8074/php74/opr-simulation $U/conc_sup_linux.json > $U/conc_sup_linux.log 2>&1
echo FINALDONE > /home/claude/ab2/runs/FINALDONE
