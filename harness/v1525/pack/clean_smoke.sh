#!/bin/bash
# clean_smoke.sh <FULL_DEPLOYMENT.zip> <A0_input.json> <outdir> : clean install dari ZIP + MySQL baru + run A0 (PHP 7.4, 1 CPU) + HTTP smoke
set -e
ZIP=${1:?zip}; A0=${2:?a0}; OUT=${3:?out}; PHP74=/opt/php74/bin/php; R=/home/user/DAILYSIMULATION
CI=$(mktemp -d /tmp/w/cleanXXXX); mkdir -p "$OUT"
(cd "$CI" && unzip -q "$ZIP" && sha256sum -c --quiet SHA256SUMS.txt && echo "sha256 (zip) OK")
for f in $(cd "$CI" && find . -name '*.php'); do $PHP74 -l "$CI/$f" >/dev/null || { echo "LINT FAIL (zip) $f"; exit 1; }; done; echo "lint74 (zip) OK"
[ -e "$CI/config/db.php" ] && { echo "config/db.php ada di ZIP"; exit 1; }; mkdir -p "$CI/jobs"; cp "$CI/install/input_data.template.json" "$CI/input_data.json"
mysql -S /run/mysqld/mysqld.sock -uroot -e "DROP DATABASE IF EXISTS pp_zipclean; CREATE DATABASE pp_zipclean CHARACTER SET utf8mb4; GRANT ALL ON pp_zipclean.* TO 'pp_app'@'localhost';"
mysql -S /run/mysqld/mysqld.sock -upp_app -ppp_test_only pp_zipclean < "$CI/database/schema.sql"; echo "schema OK"
export DB_SOCKET=/run/mysqld/mysqld.sock DB_NAME=pp_zipclean DB_USER=pp_app DB_PASSWORD=pp_test_only
(cd "$CI" && nohup taskset -c 0 $PHP74 -S 127.0.0.1:8199 -t "$CI" > "$OUT/http.log" 2>&1 & echo $! > "$OUT/srv.pid"); sleep 1
curl -s http://127.0.0.1:8199/run.php?mode=report_status | tee "$OUT/report_status.json"; echo
curl -s -o /dev/null -w "index.php HTTP %{http_code}\n" http://127.0.0.1:8199/index.php
curl -s "http://127.0.0.1:8199/run.php?mode=report_yesterday&plan_date=2026-10-05" | tee "$OUT/report_yesterday.json"; echo
taskset -c 1 $PHP74 $R/harness/v1525/runjob2.php "$CI" "$A0" "$OUT/a0_from_zip.json"
python3 $R/harness/v1525/dev/summ.py "$OUT/a0_from_zip.json" | head -2
kill $(cat "$OUT/srv.pid") 2>/dev/null || true; echo "CLEAN_INSTALL_DIR $CI"
