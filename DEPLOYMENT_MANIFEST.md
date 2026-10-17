# Deployment Manifest — XAMPP_START_PRIORITY RC V15.23 (Commitment Tightening + Fuel-State Start Assessment)

Source runtime lengkap, basis XAMPP_START_PRIORITY_RC_V1522_COMMITMENT_STOP. Tidak termasuk: config/db.php, secret, jobs/, saved/, input_data.json, harness, test evidence.
Diubah terhadap V15.22: run.php, worker02.php, CHECKPOINT.md, DEPLOYMENT_MANIFEST.md, SHA256SUMS.txt. index.php / weekly_ui.php / worker_functions.php tidak diubah.
Sesudah deploy: cache job lama tidak dipakai ulang (sidik jari engine berubah; basis warm IE kini terikat sidik jari engine). Folder jobs/ boleh dibersihkan.
Lihat CHECKPOINT.md (root cause, perbaikan, validasi, runtime, acceptance yang belum tercapai, flag rollback).
