# Deployment Manifest — XAMPP_START_PRIORITY V15.27 (objective ekonomi dulu, titik awal tightening ganda, sertifikat envelope gas)

Source runtime lengkap, basis V15.26 (`6e03d39`). Tidak termasuk: config/db.php, secret, jobs/, saved/, input_data.json, output_data.json, profiler, fixture, harness, test evidence.
Diubah terhadap V15.26: `run.php`, `worker02.php`, `worker_functions.php`, `CHECKPOINT.md`, `DEPLOYMENT_MANIFEST.md`, `SHA256SUMS.txt`. `index.php`, `saved_data_store.php`, `weekly.php`, `weekly_ui.php`, `database/*`, `install/*`, `config/*` tidak diubah (skema MySQL tetap `pp-report-mysql-1`).
Engine build: `V15.28-RC-GASONLY-STARTUP-ORDER-20261019` (V15.28: lihat CHECKPOINT_RECOVERY.md; flag `PP_V1528_GASONLY=0`, `PP_V1528_STARTUP_AFTER_CP=0`, `PP_V1528_RECORE=0`). Sesudah deploy: cache job lama tidak dipakai ulang (sidik jari engine berubah). Folder jobs/ boleh dibersihkan (`install/clean_stale_jobs.php`).
Tidak ada perubahan konfigurasi Apache/PHP. Target platform tetap PHP 7.4.33.

Flag rollback (env) V15.27:
- `PP_V1527_ECON_FIRST=0` — objective V15.26 (inversi prioritas / headroom sebelum CP ekonomi; EKONOMIS bukan pengecualian).
- `PP_V1527_TIGHT_INV=0` — tanpa titik awal tightening urutan prioritas; `PP_V1527_TIGHT_PREV8=0` — tanpa titik awal sebelum V8.
- `PP_V1527_CLOSURE_INV=0` / `PP_V1527_CLOSURE_PRIO=0` — closure bukti event hanya langkah ekonomi terbaik (V15.26).
- `PP_V1527_FIX_COLD=0` — Fix Load berubah memakai jalur warm V15.26; `PP_V1527_IE_COLD=0` — IE berubah memakai jalur warm V15.26.
- `PP_V1527_ENV_FAST=0` — sertifikat envelope gas tidak dicoba di jalur Fastest; `PP_V1527_ENV_DIST=0` — envelope mengecualikan kasus distillate (V15.26).
- `PP_V1527_SUP_STAG1=0` — pencarian window supplier PGN berhenti sesudah dua langkah stagnan (V15.26).
- `PP_V1527_OFF_DUP=0` — pencarian offset kuota JBBK tanpa penghentian probe berulang; `PP_V1527_HS_SKIP=0` — pemeriksaan HRSG stop per feeder selalu dipanggil.
Flag V15.26 / V15.25 / V15.24 tetap berlaku (lihat manifest V15.26 di paket dokumentasi).
Lihat CHECKPOINT.md dan REPORT.md (paket dokumentasi) untuk root cause, perbaikan, matrix, benchmark, dan keterbatasan.
