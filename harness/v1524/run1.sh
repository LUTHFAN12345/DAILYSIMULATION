#!/bin/bash
# run1.sh <input> <out> [env...]   COLD=1 -> kosongkan cache jobs sebelum run
in=$1; out=$2; shift 2
if [ "${COLD:-0}" = "1" ]; then find /tmp/w/app/jobs -mindepth 1 -delete; fi
env "$@" taskset -c 0 /opt/php74/bin/php /tmp/w/h/runjob2.php /tmp/w/app $in $out
