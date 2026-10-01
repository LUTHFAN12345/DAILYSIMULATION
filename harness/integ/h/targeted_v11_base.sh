#!/bin/bash
# Targeted V11 = suite V11 (reproducer WB09_3, audit V11, band CP 0,2 % + Heat Rate, counter kanonik)
#   + suite V10 versi V11 (band) + suite V9 versi V11 (band) + path independence 13 state (V9 + WB09_3)
#   + snapshot V10 (engine SRC) + battery V8/V7 (HTTP runtime + UI) + UI Target Selesai V11 (SUMMARY lima field).
# Arg: <folder-laporan> <port-dasar>. Env: SRC
cd /home/claude/t5; OUT=$1; P=$2; SRC=${SRC:-tgs11}; mkdir -p $OUT; PHP=/usr/local/bin/php74; N=/home/claude/.npm-global/lib/node_modules
echo "=== LINT PHP 7.4 ==="; for f in run.php worker02.php worker_functions.php index.php; do $PHP -l $SRC/$f; done
echo "=== SNAPSHOT BASIS ACTUAL (FINAL jangkar PGN 30 + pustaka dispatch + BASE_ACT10, engine SRC) — SEBELUM suite yang memakainya ==="
SRC=$SRC bash v10/mk_snap.sh 2>&1 | tail -4
echo "=== V11: REPRODUCER WB09_3 + AUDIT V11 + BAND CP/HEAT RATE + COUNTER ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v11_cases.php $(cd $SRC && pwd) $OUT/V11_CASES.md $OUT/V11_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-320
echo "=== V10 (versi V11): RUTE CEPAT, SERTIFIKAT, SCREENING DUA TINGKAT ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v10_cases_v11.php $(cd $SRC && pwd) $OUT/V10_CASES.md $OUT/V10_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-320
echo "=== V9 (versi V11): UNIT PRIORITY GENERIK (10 konfigurasi) + PROVENANCE BAHAN BAKAR ==="
timeout 7200 $PHP -d max_execution_time=0 v11/v9_cases_v11.php $(cd $SRC && pwd) $OUT/V9_CASES.md $OUT/V9_CASES.jsonl 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-320
echo "=== V11: PATH INDEPENDENCE (13 state) ==="
bash v11/pathind_v11.sh $SRC $OUT/pathind $((P+20)) > $OUT/pathind.log 2>&1; python3 v11/pi_report_v11.py $OUT/pathind $OUT/PATH_INDEPENDENCE.md; grep -E "IDENTIK|BEDA|state" $OUT/PATH_INDEPENDENCE.md | tail -3
export SNAPA=/home/claude/t5/v10/snap_act
SRC=$SRC bash integ/h/targeted_v8b_v12.sh $OUT $P
if [ -z "$SKIPUI" ]; then echo "=== V11 UI REPRODUCER WB09_3 (klik Run -> FINAL <= 15,5 s, SUMMARY lima field) ==="; bash v11/wb3ui_once.sh $SRC $((P+16)) $OUT; fi
python3 v10/screening_report.py $OUT $OUT/CANDIDATE_SCREENING.md 2>&1 | tail -3
echo TARGETED_V11_SELESAI
