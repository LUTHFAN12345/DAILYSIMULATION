#!/bin/bash
# Targeted V12 = targeted V11 (reproducer WB09_3, audit V11, band CP, counter, V10/V9 versi V11, path independence 13 state,
# battery HTTP+UI V7/V8, UI WB09_3) + V12: uji generik merit dispatch (12 state nyata) + rantai bahan bakar Gas Shortage.
# Arg: <folder-laporan> <port-dasar>. Env: SRC
cd /home/claude/t5; export PP_LEGACY_TARGET=max; OUT=$1; P=$2; SRC=${SRC:-tgs12}; mkdir -p $OUT; OUT=$(realpath --relative-to=/home/claude/t5 $OUT)   # skrip path independence memakai jalur relatif
SRC=$SRC bash integ/h/targeted_v11_base.sh $OUT $P
echo "=== V12: UJI GENERIK MERIT DISPATCH (12 state nyata, backend HTTP) ==="
rm -rf $OUT/merit_out; V12_SAVE_OUT=$OUT/merit_out bash v12/http1.sh $SRC $((P+30)) $OUT/MERIT_RUN.jsonl cold /home/claude/t5/v12/merit_cases.txt > /dev/null 2>&1
/usr/local/bin/php74 v12/merit_check.php $OUT/merit_out v12/merit_cases.map $OUT/V12_MERIT.md $OUT/V12_MERIT.jsonl 2>&1 | grep -E "^(PASS|FAIL)|PASS$" | cut -c1-300
echo "=== V12: RANTAI BAHAN BAKAR GAS SHORTAGE PGN 25 (popup -> LNG rek -> LNG lebih -> Distillate -> LNG kurang) ==="
bash v12/fuelchain.sh $SRC $((P+31)) $OUT/V12_FUEL_CHAIN.jsonl 2>&1 | head -5
bash integ/h/integ_extra.sh $SRC $((P+40)) $OUT/integ
echo TARGETED_V12_SELESAI
