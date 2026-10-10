#!/bin/bash
# linrun.sh <payload> <tag> <target> [FUEL env] : Apache+PHP-FPM 7.4 cold run
APP=/var/www/html/php74/opr-simulation; PL=$1; TAG=$2; OUT=/home/claude/ab/runs/linux; mkdir -p $OUT
rm -rf "$APP/jobs" "$APP/saved_data_history" "$APP/data" "$APP/output_data.json" "$APP/saved_data_store.json"
install -o www-data -g www-data -m 0664 "$PL" "$APP/input_data.json"
TARGET=$3 NODE_PATH=/home/claude/.npm-global/lib/node_modules timeout $(( ${MAXT:-240} + 60 )) node /home/claude/ab/tools/ab_ui.js http://127.0.0.1:8074/php74/opr-simulation $TAG $OUT/$TAG.json 2>&1 | tail -2 | cut -c1-600
