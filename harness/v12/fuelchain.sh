#!/bin/bash
# V12: rantai Gas Shortage PGN 25 (warm, satu server): popup basis -> LNG rekomendasi -> LNG lebih -> Distillate -> LNG kurang.
# Arg: <src> <port> <out.jsonl> [snap]
cd /home/claude/t5; SRC=$1; P=$2; OUT=$3; SN=${4:-/home/claude/t5/v12/snap_empty}
S="OPS:pgn_pipe=25,OPS:pgn_pipe=25;act=add_lng;addlng=4.7385,OPS:pgn_pipe=25;act=add_lng;addlng=6.7385,OPS:pgn_pipe=25;act=use_distillate;distlim=132198,OPS:pgn_pipe=25;act=add_lng;addlng=2.3693"
bash v12/http1.sh $SRC $P $OUT warm "$S" $SN
python3 v12/tl.py /tmp/claude-0/h12_$P 5 2>&1 | grep -v "^  \(family\|gcr\|fast\|sweep\)" | cut -c1-700
