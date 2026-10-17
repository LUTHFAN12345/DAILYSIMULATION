# Deployment Manifest — XAMPP_START_PRIORITY RC V15.24 (Order-Independent Tightening + Final-Dispatch Event Proof)

Source runtime lengkap, basis XAMPP_START_PRIORITY_RC_V1523_COMMITMENT_TIGHTENING (V15.23, di atas V15.22). Tidak termasuk: config/db.php, secret, jobs/, saved/, input_data.json, output_data.json, profiler, fixture, harness, test evidence.
Diubah terhadap V15.23: run.php, worker02.php (basis neto Effective Total Gas), CHECKPOINT.md, DEPLOYMENT_MANIFEST.md, SHA256SUMS.txt. index.php / weekly_ui.php / worker_functions.php / saved_data_store.php tidak diubah.
Engine build: V15.24-ORDER-INDEPENDENT-TIGHTENING-FINAL-PROOF-20261017. Sesudah deploy: cache job lama tidak dipakai ulang (sidik jari engine berubah); folder jobs/ boleh dibersihkan (install/clean_stale_jobs.php).
Server 1 vCPU: jumlah core kini dibaca dari affinity/cgroup -> helper_slots 0 (tidak ada helper time-slicing). Tidak ada perubahan konfigurasi Apache/PHP-FPM.
Flag rollback (env): PP_V1524_CMP=0 (comparator V15.23), PP_V1524_CLOSURE=0 (penutupan bukti event), PP_V1524_EVENT_PROOF=0, PP_V1524_IE_EXACT=0, PP_V1524_FUEL_CAP_TERMINAL=0, PP_V1524_MONO=0, PP_V1524_REUSE=0, PP_V1524_C2_EVENT_PROOF=0, PP_V1523_TIGHTEN=0.
Lihat CHECKPOINT.md dan REPORT.md (paket dokumentasi) untuk root cause, perbaikan, matrix, benchmark, dan keterbatasan.
