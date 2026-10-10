# DATASTORE_FEATURE_PARITY

`saved_data_store.php` 1,5 KB versi Copilot (hanya `pp_store_commit`, satu `saved_data_store.json` global) diganti dengan datastore kanonik ber-scope. Endpoint tetap `run.php?mode=...` (tidak ada endpoint kedua); `pp_store_commit()` dipertahankan sebagai wrapper kompatibilitas.

| Fitur | Datastore 21 KB (9d4f363) | Copilot 1,5 KB | V15.16 | Bukti |
|---|---|---|---|---|
| Backend | JSON + backup | JSON tunggal global | **SQLite WAL** (pdo_sqlite) / **fallback JSON per user/proyek + flock + atomic rename** | sdstest: kedua backend lolos |
| Autosave seluruh input saat Run | ya (global input_data.json) | tidak | **AUTOSAVE_RUN** immutable per user/tab/run | conc: record AUTOSAVE_RUN v1 milik A |
| Save seluruh input + seluruh report tabs (report_planning, Monitoring) | ya | ya (global) | ya, merge report_planning terhadap **input terakhir milik user** (bukan file global) | uisave, conc |
| Planning & Monitoring | records per plan_type/date/name | — | project ID = plan_type + tanggal + nama plan; seluruh `data3.modeling` (termasuk report_planning, actual) tersimpan utuh | uisave |
| Follow PV, Fix SR, PV rows, SR effective, Fixed Flow redistribution, fuel decision | tersimpan di input | tersimpan di input | tersimpan di input + reload per user | uisave: sr_mode, sr_fixed_mw, pv_rows, IE pulih setelah reload |
| History / version | backup per save | event list 100 | versi monoton per user+proyek (transaksi/lock); 20 penulis paralel → versi 1..20 unik | sdstest `parallel_versions_unique` |
| Reload | input_data.json global | input_data.json global | `saved/users/<uid>/input_latest.json` (atomic) dibaca index.php via cookie `pp_uid` | conc C6: A=471, B=500 |
| Atomic write | ya | ya | ya (tmp + rename, Windows fallback) | sdstest |
| Corruption recovery | integrity check | — | SQLite `integrity_check` + verifikasi hash payload; JSON rusak dikarantina `*.corrupt` | sdstest `corrupt_detected` / `corrupt_quarantined` |
| Linux / XAMPP path | __DIR__ | __DIR__ | `__DIR__/saved` (DIRECTORY_SEPARATOR); diagnostik izin tulis (`sds_writable`) | Linux FPM (JSON) + PHP 8 (SQLite) |
| Retention | — | 100 event | 200 versi per user+proyek, ≤ 180 hari, dibersihkan berkala saat commit | kode `sds_retention` |
| Multi-user isolation | tidak | tidak | list/load/meta/delete hanya record milik `uid` pemanggil | conc: `A_cannot_load_B_record` = NOT_FOUND_OR_NOT_OWNED |
| Migrasi data lama | — | — | `saved_data_history/*.json` dan `saved/records/*.json` diimpor sekali ke scope `legacy` | uji migrasi: LEGACY_SIMULATION_FINAL |
| Endpoint | store_list/load/meta/delete/integrity | — | sama + `store_latest` | — |

## Schema (SQLite `records`)
`record_id, user_or_session_id, tab_id, project_id, run_id, kind (SAVE | AUTOSAVE_RUN | SIMULATION_FINAL | LEGACY_*), created_at, updated_at, input_payload_json, result_payload_json, status, version, payload_hash, meta_json`.

Fallback JSON memakai field yang sama per file `saved/json/<uid>/<project>/v<versi>_<record>.json`.

## Kompatibilitas
- Klien tanpa identitas (CLI / klien lama tanpa `uid`) tetap memakai jalur lama `input_data.json` / `output_data.json`.
- Klien UI V15.16 selalu membawa `uid`, sehingga `input_data.json` global tidak lagi ditimpa oleh Save / autosave / hasil final user mana pun. File itu hanya menjadi benih awal untuk user yang belum pernah menyimpan.
