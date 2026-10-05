#!/bin/bash
# Enam uji pendek pada source beku: <src>. Hasil di integ/linux/res.
cd /home/claude/t5; S=$1; R=integ/linux/res; APP=/var/www/html/php74/opr-simulation; N=/home/claude/.npm-global/lib/node_modules; B=http://127.0.0.1:8074/php74/opr-simulation
rm -f $R/*.json $R/*.jsonl $R/*.txt
bash integ/linux/setup_linux.sh $S /tmp/claude-0/U31_input.json 8074 >/dev/null 2>&1
echo "== T1"; curl -s -o $R/T1_preflight.json -w "%{http_code}\n" "$B/run.php?mode=preflight"
echo "== T2"; mv $APP/saved_data_store.php /tmp/claude-0/sds_hold.php
curl -s -X POST -H 'Content-Type: application/json' --data-binary @$APP/input_data.json -o $R/T2_run_body.txt -D $R/T2_run_headers.txt "$B/run.php?mode=run&fast=1"; head -1 $R/T2_run_headers.txt
for m in "job_poll&job=x" "fast_ready&job=x" "preflight" "store_list"; do curl -s -o $R/T2_ep.txt -w "$m %{http_code} %{content_type}\n" "$B/run.php?mode=$m" >> $R/T2_endpoints.txt; head -c 90 $R/T2_ep.txt >> $R/T2_endpoints.txt; echo >> $R/T2_endpoints.txt; done
NODE_PATH=$N timeout 120 node integ/linux/failfast_ui.js $B $R/T2_ui.json 1 >/dev/null
printf 'display_errors = On\nhtml_errors = On\n' > $APP/.user.ini; sleep 1
curl -s -X POST -H 'Content-Type: application/json' --data-binary @$APP/input_data.json -o $R/T2_NEW_DEON_body.txt -w "display_errors=On %{http_code} %{content_type}\n" "$B/run.php?mode=run&fast=1"
NODE_PATH=$N timeout 120 node integ/linux/failfast_ui.js $B $R/T2_NEW_DEON_ui.json 1 >/dev/null; rm -f $APP/.user.ini
mv /tmp/claude-0/sds_hold.php $APP/saved_data_store.php; chown root:www-data $APP/saved_data_store.php; chmod 0644 $APP/saved_data_store.php
echo "== T3"; rm -rf $APP/data; mkdir -p $APP/data; chown -R root:root $APP/data; chmod -R 0555 $APP/data
curl -s -o $R/T3_preflight.json -w "preflight %{http_code}\n" "$B/run.php?mode=preflight"; H0=$(sha256sum < $APP/input_data.json)
curl -s -X POST -H 'Content-Type: application/json' --data-binary @$APP/input_data.json -o $R/T3_save.json -w "save %{http_code}\n" "$B/run.php?mode=save"
[ "$H0" = "$(sha256sum < $APP/input_data.json)" ] && echo INPUT_UNCHANGED > $R/T3_input_unchanged.txt
curl -s -X POST -o $R/T3_delete.json -w "delete %{http_code}\n" "$B/run.php?mode=store_delete&id=rec_x&confirm=rec_x"
NODE_PATH=$N timeout 60 node integ/linux/failfast_ui.js $B $R/T3_ui.json 0 >/dev/null
rm -rf $APP/jobs; install -d -o www-data -g www-data -m 2775 $APP/jobs; NODE_PATH=$N timeout 600 node integ/fast5.js $B T3_U31_datastore_ro > $R/T3_run.jsonl 2>/dev/null
rm -rf $APP/data; install -d -o www-data -g www-data -m 2775 $APP/data
echo "== T4-6 Linux"; bash integ/linux/run_linux_cases.sh $R/LINUX.jsonl 3 U31:/tmp/claude-0/U31_input.json U30:/tmp/claude-0/U30_input.json
echo "== T4-6 XAMPP semantik"; : > $R/XAMPPSEM.jsonl
for rep in 1 2 3; do PP_EMU_PID=4242 PP_PROXY_NOOPCACHE=1 CASES="U31 U30" bash integ/fast5.sh $S 9600 /tmp/claude-0/xs.jsonl > /dev/null 2>&1
  python3 -c "
import json
for l in open('/tmp/claude-0/xs.jsonl'):
    r=json.loads(l); r['platform']='XAMPP semantik'; open('$R/XAMPPSEM.jsonl','a').write(json.dumps(r)+'\n')"; done
echo SIXDONE
