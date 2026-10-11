#!/bin/bash
# t0cold.sh <tag> : restart server 8792 (cold: hapus jobs/), lalu T0 UI 1 cold + 1 warm
TAG=$1; PIDF=/tmp/claude-0/proxy8792.pid
[ -f $PIDF ] && kill $(cat $PIDF) 2>/dev/null; sleep 1
rm -rf /tmp/claude-0/abD2/jobs
cd /home/claude/t5; NODE_PATH=/opt/node22/lib/node_modules nohup node v3/proxy.js 8792 /tmp/claude-0/abD2 6 > /tmp/claude-0/proxy8792.log 2>&1 & echo $! > $PIDF
for i in $(seq 1 40); do curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8792/index.php 2>/dev/null | grep -q 200 && break; sleep 0.5; done
cd /home/claude/ab2/bb; NODE_PATH=/opt/node22/lib/node_modules NRUN=2 timeout 400 node t0ui2.js http://127.0.0.1:8792 ${OUTD:-/home/claude/ab2/bb/t0} $TAG 2>&1 | grep -v '^PROBE' | cut -c1-1400
for f in /tmp/claude-0/abD2/jobs/economic_review-*/result.json; do cp "$f" ${OUTD:-/home/claude/ab2/bb/t0}/${TAG}_result.json; done
