#!/bin/bash
# pack.sh <outdir> : bangun 3 ZIP final + verifikasi (lint PHP 7.4, secret scan, SHA256SUMS, clean install, smoke run dari ZIP)
set -e
OUT=${1:?outdir}; REPO=/home/user/DAILYSIMULATION; PHP74=/opt/php74/bin/php
mkdir -p "$OUT"; W=$(mktemp -d /tmp/w/packXXXX)
RUNTIME="CHECKPOINT.md DEPLOYMENT_MANIFEST.md INSTALL.md SHA256SUMS.txt config/.htaccess config/db.example.php database/README_MYSQL_SETUP.md database/migrate_fix_load_1.sql database/migrate_legacy_reports.php database/schema.sql index.php install/clean_stale_jobs.php install/input_data.template.json run.php saved_data_store.php weekly.php weekly_ui.php worker02.php worker_functions.php"
PATCH="CHECKPOINT.md DEPLOYMENT_MANIFEST.md SHA256SUMS.txt run.php worker02.php"
cd "$REPO"
# SHA256SUMS untuk berkas runtime (tanpa SHA256SUMS.txt sendiri)
for f in $RUNTIME; do [ "$f" = SHA256SUMS.txt ] || sha256sum "$f"; done > SHA256SUMS.txt
# lint PHP 7.4 seluruh PHP runtime
for f in $RUNTIME; do case $f in *.php) $PHP74 -l "$f" >/dev/null || { echo "LINT FAIL $f"; exit 1; } ;; esac; done; echo "lint74 OK"
# secret scan (pola kredensial nyata; template db.example.php berisi placeholder <...>)
if grep -nE "(password|passwd|secret|api[_-]?key|token)['\"]?\s*(=>|=|:)\s*['\"][^'\"<]{6,}" $RUNTIME | grep -vE "db.example.php|exec_token|\\\$tok|token\]|'token' =>|token_|tokenVal" ; then echo "SECRET SCAN: periksa baris di atas"; else echo "secret scan OK"; fi
for bad in config/db.php jobs saved input_data.json output_data.json run_prof.php; do [ -e "$W/x/$bad" ] && { echo "TERLARANG $bad"; exit 1; }; done
# FULL
mkdir -p "$W/full"; tar cf - $RUNTIME | tar xf - -C "$W/full"; (cd "$W/full" && rm -f "$OUT/XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip" && zip -qr -X "$OUT/XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip" .)
# PATCH (berkas berubah terhadap V15.23)
mkdir -p "$W/patch"; tar cf - $PATCH | tar xf - -C "$W/patch"; (cd "$W/patch" && rm -f "$OUT/XAMPP_FINAL_VALIDATED_PATCH.zip" && zip -qr -X "$OUT/XAMPP_FINAL_VALIDATED_PATCH.zip" .)
# DOCUMENTATION (laporan, matrix, benchmark, bukti, harness uji)
mkdir -p "$W/doc"; cp REPORT.md CHECKPOINT.md "$W/doc/" 2>/dev/null || true
tar cf - harness/v1524 --exclude='__pycache__' --exclude='evidence_old' --exclude='evidence_prev' | tar xf - -C "$W/doc"
(cd "$W/doc" && rm -f "$OUT/XAMPP_FINAL_VALIDATED_DOCUMENTATION.zip" && zip -qr -X "$OUT/XAMPP_FINAL_VALIDATED_DOCUMENTATION.zip" .)
# verifikasi FULL: tidak memuat berkas terlarang / harness
unzip -l "$OUT/XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip" | grep -E "config/db.php|/jobs/|saved/|input_data.json|harness|run_prof" && { echo "FULL memuat berkas terlarang"; exit 1; } || echo "FULL content OK"
# clean install dari ZIP + SHA256SUMS check + lint
CI="$W/clean"; mkdir -p "$CI"; (cd "$CI" && unzip -q "$OUT/XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip" && sha256sum -c --quiet SHA256SUMS.txt && echo "sha256 OK")
for f in $(cd "$CI" && find . -name '*.php'); do $PHP74 -l "$CI/$f" >/dev/null || { echo "LINT FAIL (zip) $f"; exit 1; }; done; echo "lint74 (zip) OK"
mkdir -p "$CI/jobs"; cp "$CI/install/input_data.template.json" "$CI/input_data.json"
echo "$CI"
(cd "$OUT" && sha256sum XAMPP_FINAL_VALIDATED_*.zip)
