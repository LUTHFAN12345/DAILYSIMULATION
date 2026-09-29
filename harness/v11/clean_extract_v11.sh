#!/bin/bash
# Clean-extract ZIP V11 lalu ulang targeted test V11 dari hasil ekstrak. Arg: <zip> <folder-laporan> <port>
cd /home/claude/t5; ZIP=$1; OUT=$2; P=${3:-9850}; mkdir -p $OUT; X=/home/claude/t5/v11ce; rm -rf $X; mkdir -p $X
cd $X && unzip -q $ZIP && cd /home/claude/t5
echo "=== ISI ZIP ==="; (cd $X && ls -la && ls rollback reports 2>&1 | head -40)
echo "=== SHA256SUMS ==="; (cd $X && sha256sum -c SHA256SUMS.txt)
echo "=== SAMA DENGAN SOURCE BEKU ==="; (cd $X && sha256sum run.php worker02.php worker_functions.php index.php) | diff - v11/FREEZE_SHA256_V11.txt && echo "IDENTIK DENGAN FREEZE"
echo "=== ROLLBACK = V10 FINAL ==="; if [ -f $X/rollback/run.php ]; then (cd $X/rollback && sha256sum run.php worker02.php worker_functions.php index.php) | diff - v10/FREEZE_SHA256_V10.txt && echo "ROLLBACK IDENTIK DENGAN V10 FINAL"; else echo "ROLLBACK V10 TIDAK DISERTAKAN (source V10 FINAL tidak tersedia di workspace)"; fi
echo "=== LINT PHP 7.4 ==="; for f in run.php worker02.php worker_functions.php index.php; do /usr/local/bin/php74 -l $X/$f; done
echo "=== TARGETED V11 DARI HASIL EKSTRAK ==="
SRC=$X bash v11/targeted_v11.sh $OUT $P
echo CLEAN_EXTRACT_SELESAI
