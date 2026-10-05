#!/bin/bash
# Paket lintas platform. Arg: <akar-beku> <folder-dok> <nama-zip> <folder-laporan...>
set -e
cd /home/claude/t5; R=$1; DOC=$2; ZN=$3; shift 3; P=/home/claude/t5/pkg_x; Z=/home/claude/t5/$ZN; G=/home/user/DAILYSIMULATION
rm -rf $P; mkdir -p $P/reports $P/rollback/FINAL_V2_3b43955 $P/tools
cp -L $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $R/saved_data_store.php $P/
for f in run.php worker02.php worker_functions.php index.php saved_data_store.php; do git -C $G show 3b43955:$f > $P/rollback/FINAL_V2_3b43955/$f; done
(cd $P/rollback/FINAL_V2_3b43955 && sha256sum *.php > SHA256SUMS.txt)
cp integ/linux/setup_linux.sh $P/tools/contoh_apache_phpfpm_linux.sh
D=$(cd $DOC && ls *.md); for d in $D; do cp $DOC/$d $P/; done
for d in "$@"; do n=$(basename $d); mkdir -p $P/reports/$n; (cd $d && find . -type f \( -name '*.md' -o -name '*.jsonl' -o -name '*.json' -o -name '*.txt' -o -name '*.log' -o -name '*.csv' \) ! -path './integ/merit/out_*' -exec cp --parents {} $P/reports/$n/ \;); done
(cd $P && sha256sum run.php worker02.php worker_functions.php index.php saved_data_store.php > SHA256SUMS.txt && find $D rollback reports tools -type f | sort | xargs sha256sum >> SHA256SUMS.txt)
rm -f $Z; (cd $P && zip -qr $Z run.php worker02.php worker_functions.php index.php saved_data_store.php $D SHA256SUMS.txt rollback reports tools)
sha256sum $Z
