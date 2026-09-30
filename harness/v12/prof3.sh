#!/bin/bash
# V12 battery profil 3x (bergantian V11/V12, VM sama). Arg: <out-dir> [n=3]
cd /home/claude/t5; OUT=$1; N=${2:-3}; mkdir -p $OUT
SLOT="ACT_FFJ_UP,ACT_PGN_UP,ACT_PGN_DOWN,ACT_FFM_UP,KP72_UP,ACT_PGN_2SLOT_C"
QA="QA_pgn_pipe_1,QA_pgn_pipe_4,QA_lng_1"
Q="Q_pgn_pipe_1,Q_pgn_pipe_2,Q_pgn_pipe_4,Q_lng_1,Q_pep_1,Q_akasia_1,Q_pep_kp72_1,WB09_3,WB09"
for i in $(seq 1 $N); do for V in v12freeze v11final; do
  T=$([ $V = v12freeze ] && echo v12 || echo v11)
  bash v12/http1.sh $V 8821 $OUT/${T}_slot_$i.jsonl cold "$SLOT,$QA,$Q" > /dev/null 2>&1
  bash v12/http1.sh $V 8821 $OUT/${T}_exact_$i.jsonl cold "BASE_ACT10,BASE_PGN30" /home/claude/t5/v12/snap_empty > /dev/null 2>&1
  bash v12/fuelchain.sh $V 8821 $OUT/${T}_fuel_$i.jsonl > /dev/null 2>&1
  bash v12/http1.sh $V 8821 $OUT/${T}_ops_$i.jsonl cold "FILE:/home/claude/t5/v12/ops_inputs/F1_STOP_G1_1000.json,FILE:/home/claude/t5/v12/ops_inputs/F2_STOP_G1_SLOTS.json" /home/claude/t5/v12/snap_empty > /dev/null 2>&1
  echo "ronde $i $T selesai $(date +%T)"
done; done
echo PROF3_SELESAI
