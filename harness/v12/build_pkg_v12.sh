#!/bin/bash
# Bangun paket V12 dari source beku. Arg: <akar-beku> <folder-dokumen> <nama-zip> <folder-laporan...>
# Akar ZIP: run.php worker02.php worker_functions.php index.php + dokumen *.md + SHA256SUMS.txt + rollback/ (V11, V10) + reports/
set -e
cd /home/claude/t5; R=$1; DOC=$2; ZN=$3; shift 3; P=/home/claude/t5/pkg_v12; Z=/home/claude/t5/$ZN
rm -rf $P; mkdir -p $P/reports $P/rollback/V11 $P/rollback/V10
cp -L $R/run.php $R/worker02.php $R/worker_functions.php $R/index.php $P/
cp v11final/run.php v11final/worker02.php v11final/worker_functions.php v11final/index.php $P/rollback/V11/
(cd $P/rollback/V11 && sha256sum run.php worker02.php worker_functions.php index.php > SHA256SUMS_V11.txt)
if [ -f v10base/run.php ]; then cp v10base/run.php v10base/worker02.php v10base/worker_functions.php v10base/index.php $P/rollback/V10/; (cd $P/rollback/V10 && sha256sum *.php > SHA256SUMS_V10.txt);
else cat > $P/rollback/V10/README.md <<'MD'
# Rollback V10

Source `php_simulation_REVISI_OPTIMASI_ENGINE_V10_FINAL.zip` tidak tersedia di workspace sesi ini: tidak termasuk dalam ZIP
checkpoint V11 maupun `V11_TEST_DEPENDENCIES_CHECKPOINT.zip`, dan tidak ada di repository. Rollback V10 karena itu TIDAK disertakan
(tidak direkonstruksi dari sumber lain). Hash sah V10 FINAL tercatat di `reports/ref/FREEZE_SHA256_V10.txt` (bila ada) untuk
memverifikasi berkas V10 yang Anda miliki. Rollback yang disertakan: `rollback/V11/` (V11 FINAL, hash di SHA256SUMS_V11.txt).
MD
fi
mkdir -p $P/reports/ref; [ -f v10/FREEZE_SHA256_V10.txt ] && cp v10/FREEZE_SHA256_V10.txt $P/reports/ref/; [ -f v11/FREEZE_SHA256_V11.txt ] && cp v11/FREEZE_SHA256_V11.txt $P/reports/ref/
D=$(cd $DOC && ls *.md)
for d in $D; do cp $DOC/$d $P/; done
for d in "$@"; do n=$(basename $d); mkdir -p $P/reports/$n; (cd $d && find . -type f \( -name '*.md' -o -name '*.jsonl' -o -name '*.txt' -o -name '*.csv' -o -name '*.log' \) ! -path './merit_out/*' ! -path './pathind/*' -exec cp --parents {} $P/reports/$n/ \;); done
(cd $P && find run.php worker02.php worker_functions.php index.php $D rollback reports -type f | sort | xargs sha256sum > SHA256SUMS.txt)
rm -f $Z
(cd $P && zip -qr $Z run.php worker02.php worker_functions.php index.php $D SHA256SUMS.txt rollback reports)
sha256sum $Z
