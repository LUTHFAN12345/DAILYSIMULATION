#!/bin/bash
# sesudah bench new: (1) V15.22 Fix Load feasible (A0_fix2) agar sebanding; (2) A0/A8 cold x3 V15.24 terisolasi ulang
run(){ app=/tmp/w/bench/app_$1; out=/tmp/w/bench/res_$1; taskset -c 0 /opt/php74/bin/php /tmp/w/h/runjob2.php $app $2 $out/$3.json 2>>$out/log.txt; echo "$3 $(tail -1 $out/log.txt)" >> $out/summary.txt; }
A=/tmp/w/bench/app_old
mv /tmp/w/bench/res_old/fix_warm1.json /tmp/w/bench/res_old/fixrepair_warm1.json 2>/dev/null; rm -f /tmp/w/bench/res_old/fix_warm2.json /tmp/w/bench/res_old/fix_warm3.json /tmp/w/bench/res_old/fix_cold1.json
find $A/jobs -mindepth 1 -delete; run old /tmp/w/reg/A0_actual.json fixseed_b
for i in 1 2 3; do find $A/jobs -mindepth 1 -maxdepth 1 -name 'economic_review-*' -exec rm -rf {} +; run old /tmp/w/bench/A0_fix2.json fix_warm$i; done
find $A/jobs -mindepth 1 -delete; run old /tmp/w/bench/A0_fix2.json fix_cold1
N=/tmp/w/bench/app_new; mkdir -p /tmp/w/bench/res_new_iso
for sc in A0_actual A8_cfg_G9_ke_G5; do for i in 1 2 3; do find $N/jobs -mindepth 1 -delete; taskset -c 0 /opt/php74/bin/php /tmp/w/h/runjob2.php $N /tmp/w/reg/$sc.json /tmp/w/bench/res_new_iso/${sc}_cold$i.json 2>>/tmp/w/bench/res_new_iso/log.txt; done; done
echo POSTDONE >> /tmp/w/bench/res_new/summary.txt
