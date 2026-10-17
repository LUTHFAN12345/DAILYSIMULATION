#!/bin/bash
# par.sh <tag> <scenario...> : jalankan paralel, tiap run dipin ke CPU berbeda (1..3), bukan benchmark
tag=$1; shift; i=0; pids=()
for sc in "$@"; do cpu=$(( (i % 3) + 1 )); 
  ( env $EXTRA taskset -c $cpu /opt/php74/bin/php /tmp/w/h/runjob2.php ${APP:-/tmp/w/app} /tmp/w/reg/$sc.json /tmp/w/h/out/${tag}_$sc.json 2>/tmp/w/h/out/${tag}_$sc.log ) &
  pids+=($!); i=$((i+1)); if [ $((i % 3)) -eq 0 ]; then wait; fi
done; wait
