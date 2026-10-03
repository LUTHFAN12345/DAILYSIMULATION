#!/bin/bash
# Paket FINAL: akar = 5 PHP + dokumen + SHA256SUMS + rollback/ (V12 checkpoint 7ac6ab8, integrasi V4 88165df) + reports/. Arg: <akar-beku> <folder-dok> <nama-zip> <folder-laporan...>
set -e
cd /home/claude/t5; R=$1; DOC=$2; ZN=$3; shift 3; P=/home/claude/t5/pkg_final; Z=/home/claude/t5/$ZN; G=/home/user/DAILYSIMULATION
rm -rf $P; mkdir -p $P/reports $P/rollback/V12_CHECKPOINT_7ac6ab8 $P/rollback/INTEGRASI_V4_88165df
cp -L $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $R/saved_data_store.php $P/
for f in run.php worker02.php worker_functions.php index.php; do git -C $G show 7ac6ab8:$f > $P/rollback/V12_CHECKPOINT_7ac6ab8/$f; git -C $G show 88165df:$f > $P/rollback/INTEGRASI_V4_88165df/$f; done
(cd $P/rollback/V12_CHECKPOINT_7ac6ab8 && sha256sum *.php > SHA256SUMS.txt); (cd $P/rollback/INTEGRASI_V4_88165df && sha256sum *.php > SHA256SUMS.txt)
D=$(cd $DOC && ls *.md); for d in $D; do cp $DOC/$d $P/; done
for d in "$@"; do n=$(basename $d); mkdir -p $P/reports/$n; (cd $d && find . -type f \( -name '*.md' -o -name '*.jsonl' -o -name '*.txt' -o -name '*.csv' -o -name '*.log' \) ! -path './merit_out/*' ! -path './pathind/*' ! -path './integ/merit/out_*' -exec cp --parents {} $P/reports/$n/ \;); done
(cd $P && find run.php worker02.php worker_functions.php index.php saved_data_store.php $D rollback reports -type f | sort | xargs sha256sum > SHA256SUMS.txt)
rm -f $Z; (cd $P && zip -qr $Z run.php worker02.php worker_functions.php index.php saved_data_store.php $D SHA256SUMS.txt rollback reports)
sha256sum $Z
