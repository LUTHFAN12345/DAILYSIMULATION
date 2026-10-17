#!/bin/bash
# bench.sh <new|old> : PHP 7.4.33, taskset -c 0 (1 logical CPU), sequential
v=$1; app=/tmp/w/bench/app_$v; out=/tmp/w/bench/res_$v; mkdir -p $out
run(){ taskset -c 0 /opt/php74/bin/php /tmp/w/h/runjob2.php $app $1 $out/$2.json 2>>$out/log.txt; echo "$2 $(tail -1 $out/log.txt)" >> $out/summary.txt; }
wipe_all(){ find $app/jobs -mindepth 1 -delete; }
wipe_jobs(){ find $app/jobs -mindepth 1 -maxdepth 1 -name 'economic_review-*' -exec rm -rf {} +; }
for sc in A0_actual A8_cfg_G9_ke_G5 A1_gas_cukup A2_lng A7_IE_plus20 B0_input_data; do
  in=/tmp/w/reg/$sc.json
  for i in 1 2 3; do wipe_all; run $in ${sc}_cold$i; done
  for i in 1 2 3; do wipe_jobs; run $in ${sc}_warm$i; done
done
# exact rerun: hasil job identik dilayani ulang
wipe_all; run /tmp/w/reg/A0_actual.json rerun_seed
for i in 1 2 3; do run /tmp/w/reg/A0_actual.json rerun_exact$i; done
# one-cell Fix Load warm (cache dari run A0)
# Fix Load warm: satu sel yang feasible pada commitment basis (G8 row 8 = 95 MW); fix_repair = sel yang memaksa commitment repair (G2 row 20 = 25 MW)
wipe_all; run /tmp/w/reg/A0_actual.json fixseed
for i in 1 2 3; do wipe_jobs; run /tmp/w/bench/A0_fix2.json fix_warm$i; done
for i in 1 2 3; do wipe_all; run /tmp/w/bench/A0_fix2.json fix_cold$i; done
wipe_all; run /tmp/w/reg/A0_actual.json fixseed2; wipe_jobs; run /tmp/w/bench/A0_fix1.json fixrepair_warm1
echo DONE >> $out/summary.txt
