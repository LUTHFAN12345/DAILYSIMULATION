# CHECKPOINT — php_simulation_REFERENCE_LOGIC_ADOPTED_FASTEST_FINAL

- Build: `V15.15-REFERENCE-LOGIC-ADOPTED-FASTEST-20261010`
- Branch: `claude/gifted-ritchie-q0hfdp`
- Basis: Revisi terakhir Copilot (V15.14). Reference V13.4 hanya dipakai sebagai acuan logika (adopsi surgical).

## Source beku (SHA-256)
| File | SHA-256 |
|---|---|
| index.php | 4b5513e384d3fe4212af1d365341706dc43d7a932a719ecc7c59af24756a797e |
| run.php | 6738d88311295c8b30394a451dc10d59292a885fca2789262f73fa7c17226abb |
| saved_data_store.php | af420702c1a3271cf1387742a40d56d3e794b1ba5bfcf95f5f48e710dbfdd7b2 (tidak diubah dari revisi terakhir) |
| worker02.php | b1416690b1057fd1f6b4154cc4603fbb5aa1fa1e52fbae91fa718eddbbcc5b27 |
| worker_functions.php | d8f85c8dba291c646579c691b630122bc7d2860e8078010171cfacf121ccfe6d |

Catatan: `run.php` di matriks final berbeda dari versi beku hanya pada satu komentar kode (deskripsi greedy). Logika identik, dan lint PHP 7.4 bersih.

## Ringkasan status
- P0–P15: semua PASS (17 run, termasuk P10d). Gate C1–C4 FAIL = 0. STG 144/0.
- Golden Baru(5): CLI identik dengan Excel (CP 80,2321). Max Review 80,1504 (lebih baik). Fastest 82,0968.
- Fuel decision P0: popup + rekomendasi pada median 9,69 s. LNG/Distillate diterima → FASTEST_FINAL.
- Paritas XAMPP/Linux: CP/HR identik.
- Target runtime yang belum tercapai: lihat COLD_WARM_RUNTIME.md dan NEXT_STEPS.md.

## Isi paket
- 5 PHP di root ZIP.
- Laporan §11:
  - AB_REFERENCE_VS_LATEST_FUNCTION_DIFF.md
  - REFERENCE_GOOD_LOGIC_ADOPTION.md
  - FASTEST_FIRST_VALID_PRIORITY_REVIEW.md
  - FUEL_DECISION_RECOMMENDATION_ROOT_CAUSE.md
  - DISTILLATE_LNG_FAST_PATH_AUDIT.md
  - FEATURE_NON_REGRESSION_INVENTORY.md
  - P0_P15_RESULTS.md
  - COLD_WARM_RUNTIME.md
- Checkpoint: CHECKPOINT.md, BASELINE.md, CHANGES.md, TEST_STATUS.md, NEXT_STEPS.md, SHA256SUMS.txt.

## Cara pasang
1. Salin 5 PHP ke folder aplikasi (XAMPP `htdocs/...` atau Linux `/var/www/html/...`), menimpa versi lama.
2. Folder aplikasi harus dapat ditulis PHP (`jobs/`, `saved_data_history/`, `input_data.json`).
3. Tidak ada konfigurasi tambahan.
