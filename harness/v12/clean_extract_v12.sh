#!/bin/bash
# Clean-extract ZIP V12 lalu ulang targeted test V12 (PHP 7.4) dari hasil ekstrak. Arg: <zip> <folder-laporan> <port> <freeze-sha>
cd /home/claude/t5; ZIP=$1; OUT=$2; P=${3:-9850}; FRZ=$4; mkdir -p $OUT; X=/home/claude/t5/v12ce; rm -rf $X; mkdir -p $X
cd $X && unzip -q $ZIP && cd /home/claude/t5
echo "=== ISI ZIP ==="; (cd $X && ls -la && ls rollback rollback/* | head -20)
echo "=== SHA256SUMS ==="; (cd $X && sha256sum -c --quiet SHA256SUMS.txt && echo "SHA256SUMS OK")
echo "=== SAMA DENGAN SOURCE BEKU ==="; (cd $X && sha256sum run.php worker02.php worker_functions.php index.php) | diff - $FRZ && echo "IDENTIK DENGAN FREEZE"
echo "=== ROLLBACK V11 = V11 FINAL ==="; (cd $X/rollback/V11 && sha256sum run.php worker02.php worker_functions.php index.php) | diff - v11/FREEZE_SHA256_V11.txt >/dev/null 2>&1; (cd $X/rollback/V11 && sha256sum -c --quiet SHA256SUMS_V11.txt && echo "ROLLBACK V11 OK")
echo "=== LINT PHP 7.4 ==="; for f in run.php worker02.php worker_functions.php index.php; do /usr/local/bin/php74 -l $X/$f; done
echo "=== TARGETED V12 DARI HASIL EKSTRAK ==="
SRC=$X bash v12/targeted_v12.sh $OUT $P
echo CLEAN_EXTRACT_SELESAI
