#!/bin/bash
# serve.sh <port> : salin source repo ke /tmp/w/ui25/app lalu jalankan PHP 7.4 dev server (DB uji via env DB_*)
P=${1:-8125}; A=/tmp/w/ui25/app; R=/home/user/DAILYSIMULATION
mkdir -p $A/jobs $A/saved; [ -f $A/input_data.json ] || cp ${INPUT_SRC:-/root/.claude/uploads/892bda2b-3230-5398-ba77-04aa94363823/7c490821-input_data.json} $A/input_data.json; cp $R/*.php $A/; cp -r $R/config $R/install $R/database $A/ 2>/dev/null
[ -f /tmp/w/ui25/srv.pid ] && kill $(cat /tmp/w/ui25/srv.pid) 2>/dev/null; sleep 0.5
cd $A && nohup env DB_SOCKET=/run/mysqld/mysqld.sock DB_NAME=${DB_NAME:-pp_test} DB_USER=pp_app DB_PASSWORD=pp_test_only /opt/php74/bin/php -S 127.0.0.1:$P -t $A > /tmp/w/ui25/srv.log 2>&1 &
echo $! > /tmp/w/ui25/srv.pid
sleep 1; echo "http://127.0.0.1:$P"
