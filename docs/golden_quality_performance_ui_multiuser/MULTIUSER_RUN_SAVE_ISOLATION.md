# MULTIUSER_RUN_SAVE_ISOLATION

Ringkasan isolasi Run dan Save. Arsitektur rinci dan bug yang diperbaiki ada di MULTIUSER_CONCURRENCY.md; schema datastore ada di DATASTORE_FEATURE_PARITY.md.

## Kunci isolasi
| Lapisan | Kunci | Sumber |
|---|---|---|
| User / session | `pp_uid` (localStorage + cookie) | index.php (identity script) |
| Tab | `pp_tab` (sessionStorage) | index.php |
| Project | plan_type + tanggal + nama plan | run.php `sds_ctx` |
| Run | `_request_id` per klik Run (`window.PP_CUR_RID`) | index.php |
| Job | hash konteks input (job dipakai bersama hanya bila input identik) + `flock` per job-id | run.php `pp_job_start` |
| Cancel | subscriber per Run ID (`jobs/<id>/subs/<rid>`) | run.php `job_poll` / `job_cancel` |
| Save | record per uid/tab/project/run, versi monoton per user+project | saved_data_store.php |
| Reload | `saved/users/<uid>/input_latest.json` | index.php (cookie) |

## Hasil uji (C0–C6)
Hasil final per platform tercantum di TEST_STATUS.md (bagian R8).
