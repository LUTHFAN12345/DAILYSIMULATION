# CHECKPOINT — php_simulation_GOLDEN_QUALITY_PERFORMANCE_UI_MULTIUSER_FINAL

- Build: `V15.16-GOLDEN-QUALITY-PERFORMANCE-UI-MULTIUSER-20261010`
- Branch: `claude/gifted-ritchie-q0hfdp`
- Basis: commit `92263fb` (`php_simulation_REFERENCE_LOGIC_ADOPTED_FASTEST_FINAL.zip`, V15.15). Pekerjaan yang sudah benar tidak di-rollback; seluruh perubahan bersifat surgical dan memiliki saklar env (lihat CHANGES.md).
- Commit paket ini: commit di ujung branch yang memuat file ini (hash dilaporkan pada pesan serah terima).

## Source beku (SHA-256)
| File | SHA-256 |
|---|---|
| index.php | 2585ca2ef78f88fc30e860e282c0f4037a21fd09920e842ab6d05b586bbd7323 |
| run.php | 6663fc44d36342c0f95517dc8c554a07c949b43067099c0b264204fefa4727ee |
| saved_data_store.php | 96a0d7e52f8cd66a5a740d4c2e05e26ce50b25bd8c964920fb6fccccc48398d5 |
| worker02.php | 4566b91d0e0c21f23d68f7d3f6c66bfde429166ada33a04484098c25b4c5893c |
| worker_functions.php | be2069ac6d65f74c48c195ee909bdda205f36d414582a46a4f18b0a1368d3809 |

Lint: PHP 7.4.3 (`php7.4 -l`) dan PHP 8.4 bersih untuk kelima file.

## Ringkasan status (detail: TEST_STATUS.md)
| Kategori | Status |
|---|---|
| Functional | PASS |
| Validation (hard / gate / C1–C4 / STG) | PASS |
| Quality (golden CP 79.9299 ≤ 80.2521, HR 8239.87 ≤ 8251.58) | PASS |
| Performance (Fastest ≤ 15 s, Max ≤ 80 s, rekomendasi ≤ 10 s, accepted ≤ 15 s, difficult ≤ 30 s) | PASS |
| Release Blocker | TIDAK ADA |

## Isi paket
- 5 PHP di root ZIP: `run.php`, `worker02.php`, `worker_functions.php`, `index.php`, `saved_data_store.php`.
- Checkpoint: CHECKPOINT.md, BASELINE.md, CHANGES.md, TEST_STATUS.md, NEXT_STEPS.md, SHA256SUMS.txt.
- Laporan ADDENDUM:
  - FIRST_DIVERGENCE_GOLDEN_VS_LATEST.md
  - GOLDEN_ROW_BY_ROW_PARITY.md
  - PERFORMANCE_CRITICAL_PATH.md
  - DATASTORE_FEATURE_PARITY.md
  - MULTIUSER_CONCURRENCY.md
- Laporan INSTRUKSI:
  - PRIORITY_HEADROOM_48_ROW_AUDIT.md
  - DISTILLATE_FULL_FIRST_AUDIT.md
  - FASTEST_CRITICAL_PATH.md
  - UI_FEATURE_RESTORATION.md
  - IE_CHART_ACCEPTANCE.md
  - MULTIUSER_RUN_SAVE_ISOLATION.md
  - FINAL_TARGETED_TESTS.md

## Cara pasang
1. Salin 5 PHP ke folder aplikasi (XAMPP `htdocs/...` atau Linux `/var/www/html/...`), menimpa versi lama.
2. Folder aplikasi harus dapat ditulis PHP: `jobs/`, `saved/` (dibuat otomatis), `input_data.json`.
3. Datastore memakai SQLite (WAL) bila `pdo_sqlite` aktif; bila tidak, otomatis memakai fallback JSON (flock + atomic rename). Data lama (`saved_data_history/`, `saved/records/`) dimigrasikan sekali ke scope `legacy`.
4. Seluruh saklar `PP_V1516_*` / `PP_V8_FAST_*` bawaan aktif; tidak perlu konfigurasi.
