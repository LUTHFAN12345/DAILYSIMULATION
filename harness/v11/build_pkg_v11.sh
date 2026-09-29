#!/bin/bash
# Bangun paket V11 dari source beku. Arg: <akar-beku> <folder-dokumen> <folder-laporan...>
set -e
cd /home/claude/t5; R=$1; DOC=$2; shift 2; P=/home/claude/t5/pkg_v11; Z=/home/claude/t5/php_simulation_REVISI_OPTIMASI_ENGINE_V11_FINAL.zip
rm -rf $P; mkdir -p $P/reports
cp -L $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $P/
RB=""
if [ -f v10base/run.php ]; then mkdir -p $P/rollback; cp v10base/run.php v10base/worker02.php v10base/worker_functions.php v10base/index.php $P/rollback/; RB="rollback"; fi
D=$(cd $DOC && ls *.md)
for d in $D; do cp $DOC/$d $P/; done
for d in "$@"; do n=$(basename $d); mkdir -p $P/reports/$n; (cd $d && find . -type f ! -name '*.png' ! -name '*.jpg' ! -name '*.json' -exec cp --parents {} $P/reports/$n/ \;); done
(cd $P && sha256sum run.php worker02.php worker_functions.php index.php $D $( [ -n "$RB" ] && echo rollback/*.php ) > SHA256SUMS.txt)
rm -f $Z
(cd $P && zip -qr $Z run.php worker02.php worker_functions.php index.php $D SHA256SUMS.txt $RB reports)
sha256sum $Z
