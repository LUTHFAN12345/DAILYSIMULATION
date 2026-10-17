#!/bin/bash
cd /tmp/w/h
IN=/tmp/w/reg/A0_actual.json
# referensi serial tanpa pembantu
PP_V4_HELPERS=0 /opt/php74/bin/php runjob2.php /tmp/w/app $IN /tmp/w/h/hf/ref_serial.json 2>&1 | tail -1
for ms in none: crash:1 hang:1 malformed: partial:; do m=${ms%%:*}; sl=${ms#*:}
  find /tmp/w/app/jobs -mindepth 1 -delete
  PP_V4_TAKEOVER_S=20 PP_V1524_FAULT_HANG_S=900 ./hf/run.sh hf_$m $IN $m "$sl"
done
find /tmp/w/app/jobs -mindepth 1 -delete; PP_V1524_FAULT_SLOW_S=25 ./hf/run.sh hf_slow1 $IN slow 1
find /tmp/w/app/jobs -mindepth 1 -delete; ./hf/run.sh hf_allfail $IN crash "1,2,3"
