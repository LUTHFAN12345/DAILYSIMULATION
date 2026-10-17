#!/bin/bash
cd /tmp/w/reg; rm -f out_new_* out_old_*; : > /tmp/w/reg/log_final.txt
for f in /tmp/w/reg/A*.json /tmp/w/reg/B0_input_data.json; do n=$(basename $f .json)
  rm -rf /tmp/w/base/jobs; echo "old $n $(cd /tmp/w/base && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/base $f /tmp/w/reg/out_old_$n.json 2>&1 | grep wall)" >> /tmp/w/reg/log_final.txt
  rm -rf /tmp/w/app/jobs; echo "new $n $(cd /tmp/w/app && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app $f /tmp/w/reg/out_new_$n.json 2>&1 | grep wall)" >> /tmp/w/reg/log_final.txt
done
: > /tmp/w/reg/bench.txt
for i in 1 2 3; do rm -rf /tmp/w/app/jobs; echo "new cold $i $(cd /tmp/w/app && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app /tmp/w/h/snap.json /tmp/w/h/bn$i.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt; done
echo "new warm $(cd /tmp/w/app && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app /tmp/w/h/snap.json /tmp/w/h/bnw.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
rm -rf /tmp/w/app/jobs; echo "new cache-off $(cd /tmp/w/app && PP_SP_BUNDLE_CACHE=0 php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app /tmp/w/h/snap.json /tmp/w/h/bnc.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
rm -rf /tmp/w/app/jobs; echo "new prelim $(cd /tmp/w/app && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app /tmp/w/h/prelim.json /tmp/w/h/bnp.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
echo "new rerun-after-prelim $(cd /tmp/w/app && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/app /tmp/w/h/snap.json /tmp/w/h/bnr.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
rm -rf /tmp/w/base/jobs; echo "old prelim $(cd /tmp/w/base && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/base /tmp/w/h/prelim.json /tmp/w/h/bop.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
echo "old rerun-after-prelim $(cd /tmp/w/base && php -d memory_limit=2048M /tmp/w/h/runjob.php /tmp/w/base /tmp/w/h/snap.json /tmp/w/h/bor.json 2>&1 | grep wall)" >> /tmp/w/reg/bench.txt
echo ALLDONE >> /tmp/w/reg/bench.txt
