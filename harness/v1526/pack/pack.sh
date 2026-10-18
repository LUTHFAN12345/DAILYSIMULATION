#!/bin/bash
# pack.sh <outdir> [prefix] : bangun 3 ZIP (PATCH / FULL_DEPLOYMENT / DOCUMENTATION) + verifikasi (lint PHP 7.4, secret scan, SHA256SUMS, isi terlarang)
# prefix default XAMPP_FINAL_VALIDATED — dipakai HANYA bila seluruh final gate lulus (lihat REPORT.md); selain itu wajib prefix non-final.
set -e
OUT=${1:?outdir}; PFX=${2:-XAMPP_FINAL_VALIDATED}; REPO=/home/user/DAILYSIMULATION; PHP74=/opt/php74/bin/php
mkdir -p "$OUT"; mkdir -p /tmp/w; W=$(mktemp -d /tmp/w/packXXXX)
RUNTIME="CHECKPOINT.md DEPLOYMENT_MANIFEST.md INSTALL.md SHA256SUMS.txt config/.htaccess config/db.example.php database/README_MYSQL_SETUP.md database/migrate_fix_load_1.sql database/migrate_legacy_reports.php database/schema.sql index.php install/clean_stale_jobs.php install/input_data.template.json run.php saved_data_store.php weekly.php weekly_ui.php worker02.php worker_functions.php"
PATCH="CHECKPOINT.md DEPLOYMENT_MANIFEST.md SHA256SUMS.txt run.php worker02.php"
cd "$REPO"
for f in $RUNTIME; do [ "$f" = SHA256SUMS.txt ] || sha256sum "$f"; done > SHA256SUMS.txt
for f in $RUNTIME; do case $f in *.php) $PHP74 -l "$f" >/dev/null || { echo "LINT FAIL $f"; exit 1; } ;; esac; done; echo "lint74 OK"
if grep -nE "(password|passwd|secret|api[_-]?key|token)['\"]?\s*(=>|=|:)\s*['\"][^'\"<]{6,}" $RUNTIME | grep -vE "db.example.php|exec_token|\\\$tok|token\]|'token' =>|token_|tokenVal|encodeURIComponent\\(tok" ; then echo "SECRET SCAN: periksa baris di atas"; exit 1; else echo "secret scan OK"; fi
mkdir -p "$W/full"; tar cf - $RUNTIME | tar xf - -C "$W/full"; (cd "$W/full" && rm -f "$OUT/${PFX}_FULL_DEPLOYMENT.zip" && zip -qr -X "$OUT/${PFX}_FULL_DEPLOYMENT.zip" .)
mkdir -p "$W/patch"; tar cf - $PATCH | tar xf - -C "$W/patch"; (cd "$W/patch" && rm -f "$OUT/${PFX}_PATCH.zip" && zip -qr -X "$OUT/${PFX}_PATCH.zip" .)
mkdir -p "$W/doc"; cp REPORT.md CHECKPOINT.md DEPLOYMENT_MANIFEST.md "$W/doc/"; cp harness/v1526/matrix/MATRIX_CHANGELOG.md "$W/doc/"
git ls-files -co --exclude-standard harness/v1526 | grep -v -E '__pycache__|\.in\.json$' | tar cf - -T - | tar xf - -C "$W/doc"
(cd "$W/doc" && rm -f "$OUT/${PFX}_DOCUMENTATION.zip" && zip -qr -X "$OUT/${PFX}_DOCUMENTATION.zip" .)
unzip -l "$OUT/${PFX}_FULL_DEPLOYMENT.zip" | grep -E "config/db.php|/jobs/|saved/|input_data.json|harness|run_prof|\.in\.json" && { echo "FULL memuat berkas terlarang"; exit 1; } || echo "FULL content OK"
unzip -l "$OUT/${PFX}_DOCUMENTATION.zip" | grep -E "config/db.php|input_data.json$" && { echo "DOC memuat berkas terlarang"; exit 1; } || echo "DOC content OK"
(cd "$OUT" && sha256sum ${PFX}_*.zip > SHA256SUMS_ZIP.txt && cat SHA256SUMS_ZIP.txt)
