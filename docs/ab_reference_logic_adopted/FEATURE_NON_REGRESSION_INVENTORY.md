# FEATURE_NON_REGRESSION_INVENTORY

| Fitur | Status | Bukti |
|---|---|---|
| Follow PV ON/OFF, PV MW di Simulation Data | Dipertahankan dan sekarang efektif di engine (SR per baris) | P10 / P10d; kolom `PV`, `SR_Min` di baris output |
| Fastest dan Maximum Review terpisah | Ya | Fastest → `pp_v13f_fast_job`; Max → V7 + family + GCR (P1, GOLDEN_max) |
| Actual Gas / Fixed Flow JBBK | Ya | P2–P5 (PGN 22/24, PEP 35/37) |
| Gas Shortage → popup LNG/Distillate | Dipulihkan | P0, P6 |
| LNG | Ya | P7, P9, P10 |
| Distillate (full-first, Unit Priority - Distillate GTG only, `rsort`) | Ya | P8, P10d, golden |
| Change Over B1-2 | Ya | P14 |
| Required Start / Continuous | Ya | P13 |
| Trip / unavailable | Ya | P11 (stop-available), P12 (unavailable → terminal bersertifikat) |
| C1–C4, STG, merit proof, release gate | Diperkuat (gate merit untuk Fastest dan job) | `Merit Proof C1-C4 STG` |
| Save / Reload | Ya; flag internal dibuang saat Save | P15 |
| Export / Publish, laporan Excel / gambar | Ya | P15 (Excel ±40 KB, gambar ±3,5 MB) |
| `saved_data_store.php` | Tidak diubah; `pp_store_commit` dipanggil juga dari job final | P15 (revision naik, `route=job`) |
| Paritas XAMPP / Linux | Ya | Lihat COLD_WARM_RUNTIME.md bagian Linux |
| PHP 7.4 | Lint bersih | SHA256SUMS / CHECKPOINT |
