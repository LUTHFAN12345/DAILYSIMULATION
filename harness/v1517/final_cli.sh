#!/bin/bash
cd /home/claude/ab2/op
python3 -I runscen.py /home/claude/ab2/devm scres3 $(ls sc/*.json | sort) > scen3.jsonl 2> scen3.err
bash fxall.sh > fxall.log 2>&1
echo ALLDONE >> fxall.log
