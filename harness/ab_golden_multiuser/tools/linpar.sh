#!/bin/bash
# linpar.sh : R10 paritas Linux (Apache + PHP-FPM 7.4) pada source beku + R8 concurrency ulang
bash /home/claude/t5/integ/linux/setup_linux.sh /home/claude/ab2/freeze1 /home/claude/ab/payloads/P0.json 8074 > /dev/null 2>&1
cd /home/claude/ab; P=payloads
FUEL= tools/linrun.sh $P/GOLDEN_dist.json L_R0 fast
FUEL=lng tools/linrun.sh $P/P0.json L_R2 fast
FUEL=dist tools/linrun.sh $P/P0.json L_R3 fast
FUEL=lng tools/linrun.sh $P/P10_pv_on.json L_R4 fast
FUEL= tools/linrun.sh $P/P14_co_b2b1.json L_R9 fast
FUEL= tools/linrun.sh $P/P12_g8_unavail.json L_P12 fast
FUEL=lng tools/linrun.sh $P/R5_BASE_ACT10.json L_R5 fast
MAXT=300 FUEL= tools/linrun.sh $P/GOLDEN_dist.json L_R1 max
echo LINDONE > /home/claude/ab/runs/linux/LINDONE
install -o www-data -g www-data -m 0664 $P/P0.json /var/www/html/php74/opr-simulation/input_data.json
NODE_PATH=/home/claude/.npm-global/lib/node_modules timeout 900 node /home/claude/ab2/tools/conc.js http://127.0.0.1:8074/php74/opr-simulation /home/claude/ab2/ui/conc_linux_final.json > /home/claude/ab2/ui/conc_linux_final.log 2>&1
echo CONCDONE >> /home/claude/ab2/ui/conc_linux_final.log
