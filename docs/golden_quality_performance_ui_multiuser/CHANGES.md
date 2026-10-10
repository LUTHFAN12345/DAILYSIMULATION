# CHANGES — V15.16 (dari checkpoint 92263fb / php_simulation_REFERENCE_LOGIC_ADOPTED_FASTEST_FINAL.zip)

Build ID: `V15.16-GOLDEN-QUALITY-PERFORMANCE-UI-MULTIUSER-20261010`. Tidak ada validator yang dilonggarkan, tidak ada data golden / tanggal / unit yang di-hardcode, ekspektasi golden tidak diubah. Setiap perubahan engine memiliki saklar env untuk A/B (`=0` mengembalikan perilaku 92263fb).

## Kualitas dispatch (worker_functions.php `pp_shape_final_dispatch`)
| Kode | Akar penyebab (lihat FIRST_DIVERGENCE_GOLDEN_VS_LATEST.md) | Perbaikan | Saklar |
|---|---|---|---|
| D1 | Probe kebutuhan export-floor memperlakukan G2/G4/G6 yang sudah commit sebagai 0 → defisit palsu → G5 dipaksa 01:00, G3 2–37, G9 turun ke 65 MW | Baris dasar commit (`$commitBaseRow`: cannot-stop / continuous / last-running → MCC) dipakai di semua probe kebutuhan & lever | `PP_V1516_BASE` |
| D1b | Unit committed tidak dinaikkan lebih dulu sebelum menyalakan unit baru | Blok RUNFIRST: naikkan unit committed menurut Unit Priority user, dijaga Spinning Reserve per row | `PP_V1516_RUNFIRST` |
| D2 | Gas-cap `$tryRemove` mengompensasi dengan G3 hardcode (swap) | Kompensasi hanya dengan unit yang running, urut prioritas; revert bila gas tidak turun | `PP_V1516_GASCAP` |
| D3 | Eskalasi G3 hardcode mendahului g4/g6 walau prioritas user g6 > g4 > g3 | Lever g4/g6 lebih dulu (`$b1PrefAB`), G3 hanya fallback; lead-in hanya untuk start baru, taper = max dengan nilai yang ada | `PP_V1516_B1ORDER` |
| D4 | Distillate greedy + langkah penutup menaruh Distillate pada G4 5 MW (lead-in) | Greedy full-first dengan `__fullFirstHold`; penutup diskret per tier prioritas (L = 1..n) | `PP_V1516_DIST_TIER` |

## Performa
- **Fastest V8 bounded race** (run.php): cap 4 kandidat, 1 ronde. Anggaran waktu = sisa target total job (`PP_V8_FAST_TOTAL` 11,5 s; rerun sesudah popup bahan bakar `PP_V8_FAST_TOTAL_FUEL` 8,5 s), minimum `PP_V8_FAST_MIN` 2 s, maksimum `PP_V8_FAST_WALL` 10 s. Dahulu konstanta 10 s selalu habis, sehingga kasus yang pipeline-nya panjang (Actual Gas 21–22 s) dan rerun LNG (15,3–15,9 s) melewati 15 s.
- **Maximum Review V8** dibatasi sisa anggaran `PP_V8_MAX_TOTAL` (58 s − elapsed). Golden Max: 205 s → 25–27 s.
- **Prasaring kapasitas export pada decommit** (worker02.php): stop-window yang terbukti numerik melanggar Export Min ditolak tanpa core run (`PP_V1516_DECOMMIT_PRESCREEN`).
- **Fix pass C1–C4** (`pp_v15_fast_priority_fix_pass`): bukti tepi atas window gas, langkah tunggal greedy, memo langkah lokal tidak valid. P10 Follow PV: 48 s → 14,8 s (LNG) / 20,7 s (Distillate).

## UI (index.php)
- Follow PV / Fix SR / SR Effective / tabel & chart PV dipulihkan; kolom PV selalu tampil di Simulation Data.
- `Spin_Res` dihitung dengan `pp_spinning_reserve` (sama dengan validator); `SR_Min` = max(Fix SR, PV) per row (`pp_v1516_row_pv_sr`).
- Chart IE (SVG inline, tooltip, statistik) di Name Plan & IE/Dispatch.
- Timer elapsed kanan bawah: tick 1 detik, tahap nyata, terikat Run ID, berhenti pada popup / FINAL.
- Normal Run selalu `__fuel_decision_mode = recommendation` (penanda basi tidak terbawa).

## Datastore & multi-user (saved_data_store.php, run.php, index.php)
- `saved_data_store.php` ditulis ulang: SQLite WAL / fallback JSON (flock + atomic rename), scope uid/tab/project/run, versi, retensi, integritas, migrasi data lama.
- Identitas `pp_uid` / `pp_tab`, wrapper `fetch`, input terakhir per user.
- `pp_job_start` diserialisasi `flock` per job-id (`PP_V1516_JOB_LOCK`); cancel hanya melepas Run ID pemanggil bila run lain masih memantau (`PP_V1516_SHARED_CANCEL`); save ber-scope (`PP_V1516_SCOPED_SAVE`).
- Supersede ber-scope: job menyimpan `owner_uid` / `owner_tab`; Run baru hanya menggantikan job milik user+tab yang sama dan tidak membatalkan job yang masih dipantau run lain (`PP_V1516_SUPERSEDE_SCOPE`). Sebelumnya Run user A membatalkan job user B yang sedang berjalan.
