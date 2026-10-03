#!/bin/bash
# Clean-extract ZIP FINAL lalu ulang targeted dari hasil ekstrak. Arg: <zip> <folder-laporan> <port> <freeze-sha>
cd /home/claude/t5; ZIP=$1; OUT=$2; P=${3:-9850}; FRZ=$4; mkdir -p $OUT; X=/home/claude/t5/finalce; rm -rf $X; mkdir -p $X
cd $X && unzip -q $ZIP && cd /home/claude/t5
echo "=== ISI AKAR ZIP ==="; (cd $X && ls -1 | head -30)
echo "=== SHA256SUMS ==="; (cd $X && sha256sum -c --quiet SHA256SUMS.txt && echo "SHA256SUMS OK")
echo "=== SAMA DENGAN SOURCE BEKU ==="; (cd $X && sha256sum run.php worker02.php worker_functions.php index.php saved_data_store.php) | diff - $FRZ && echo "IDENTIK DENGAN FREEZE"
echo "=== LINT PHP 7.4 ==="; for f in run.php worker02.php worker_functions.php index.php saved_data_store.php; do /usr/local/bin/php74 -l $X/$f; done
echo "=== TARGETED DARI HASIL EKSTRAK ==="
SRC=$X bash integ/h/targeted_v12.sh $OUT $P
echo CLEAN_EXTRACT_SELESAI
