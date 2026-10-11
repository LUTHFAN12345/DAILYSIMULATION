#!/bin/bash
# prof1.sh <fx> <tag> : satu cold UI run di server profil 8820, trace -> perf/<tag>.trace
R=/tmp/claude-0/uiprof; O=/home/claude/ab3/perf; N="env NODE_PATH=/opt/node22/lib/node_modules"
cp /home/claude/ab3/fx/$1.json $R/input_data.json; rm -rf "${R:?}"/jobs/*; : > /tmp/claude-0/ptrace.log
date +%s.%N > $O/$2.t0
timeout 200 $N node /home/claude/ab2/op/uirun.js http://127.0.0.1:8840 $O/$2.json lng > /dev/null 2>&1
cp /tmp/claude-0/ptrace.log $O/$2.trace
