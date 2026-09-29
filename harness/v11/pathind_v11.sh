#!/bin/bash
# Path independence V11 (V9 + WB09_3): state numerik sama lewat jalur berbeda -> dispatch (md5) + CP identik.
# Arg: <src> <out-dir> <port>
cd /home/claude/t5; SRC=$1; OUT=$2; P=$3; mkdir -p $OUT; PHP=/usr/local/bin/php74
ST="BASE_PGN30 WB09 WB09_KP72 BASE_ACT10 ACT_PGN_UP ACT_PGN_DOWN ACT_FFJ_UP ACT_FFM_DOWN KP72_DOWN ACT_PGN_2SLOT_C QA_pgn_pipe_1 QA_pep_-1 WB09_3"
echo "== 1 DIRECT (CLI, akar baru per state, cache dingin, tanpa pembantu)"
bash v9/run_states.sh $SRC $OUT/direct $ST > $OUT/direct.log 2>&1
echo "== 2 CHAIN A (CLI, satu akar, urutan UI: data Actual lalu kuota)"
bash v9/chain_states.sh $SRC $OUT/chainA BASE_ACT10 ACT_PGN_UP ACT_PGN_2SLOT_C KP72_DOWN ACT_FFJ_UP ACT_FFM_DOWN ACT_PGN_DOWN QA_pgn_pipe_1 QA_pep_-1 BASE_PGN30 WB09 WB09_KP72 WB09_3 > $OUT/chainA.log 2>&1
echo "== 3 CHAIN B (CLI, satu akar, urutan terbalik + state ulang = cache hangat)"
bash v9/chain_states.sh $SRC $OUT/chainB WB09_3 WB09_KP72 WB09 QA_pep_-1 QA_pgn_pipe_1 BASE_PGN30 ACT_PGN_DOWN ACT_FFM_DOWN ACT_FFJ_UP KP72_DOWN ACT_PGN_2SLOT_C ACT_PGN_UP BASE_ACT10 > $OUT/chainB.log 2>&1
mkdir -p $OUT/warm; S=/tmp/claude-0/cs_chainB; for SC in ACT_PGN_UP WB09 QA_pep_-1 WB09_3; do timeout 900 $PHP -d max_execution_time=0 v8/job_dump.php $S $SC /home/claude/t5/$OUT/warm/$SC.json 2>/dev/null | tail -1 | sed "s/^/warm $SC /"; done >> $OUT/chainB.log
echo "== 4 HTTP CHAIN (pembantu aktif, urutan UI)"
H=/tmp/claude-0/pi_http_$P; rm -rf $H; mkdir -p $H; cp $SRC/{run,worker02,worker_functions,index}.php $H/
timeout 7200 $PHP v9/v9_http.php $H $P $OUT/http_chain.jsonl warm BASE_PGN30,BASE_ACT10,ACT_PGN_UP,ACT_PGN_2SLOT_C,KP72_DOWN,ACT_FFJ_UP,ACT_FFM_DOWN,ACT_PGN_DOWN,QA_pgn_pipe_1,QA_pep_-1,WB09,WB09_KP72,WB09_3 /home/claude/t5/v3/snap_empty > $OUT/http_chain.log 2>&1
echo "== 5 HTTP TARGET SELESAI LEBIH DULU (cold per state, tl_search 25 s sebelum Run)"
T=/tmp/claude-0/pi_tl_$P; rm -rf $T; mkdir -p $T; cp $SRC/{run,worker02,worker_functions,index}.php $T/
TLFIRST=25 timeout 5400 $PHP v9/v9_http.php $T $((P+1)) $OUT/http_tl.jsonl cold ACT_FFJ_UP,QA_pep_-1,WB09,WB09_3 /home/claude/t5/v3/snap_empty > $OUT/http_tl.log 2>&1
for d in direct chainA chainB warm; do $PHP v9/pi_md5.php $OUT/$d > $OUT/$d.md5.jsonl; done
echo PATHIND_SELESAI
