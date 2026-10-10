# MULTIUSER_CONCURRENCY

## Arsitektur isolasi
- **Identitas**:
  - `pp_uid` per browser (localStorage + cookie) = user/session ID.
  - `pp_tab` per tab (sessionStorage) = tab ID.
  - project ID dari plan.
  - Run ID = `_request_id` per klik Run.
  - Wrapper `fetch` menambahkan `uid` dan `tab` pada setiap request `run.php`, serta `rid` (Run ID aktif tab) pada `job_poll` / `job_cancel`.
- **Penyimpanan**: Save / autosave / final ditulis ke record milik `uid` (lihat DATASTORE_FEATURE_PARITY.md); user lain tidak dapat membaca atau menimpanya.
- **Run**:
  - Job dipakai bersama hanya untuk input identik (context hash sama, hasil deterministik).
  - Pembuatan job diserialisasi dengan `flock` per job-id. Dulu dua user dengan input identik yang Run bersamaan saling menghapus direktori job, sehingga salah satunya gagal dengan "TIDAK_BISA_MENULIS_INPUT_JOB".
  - Polling membawa Run ID dan menulis heartbeat `jobs/<id>/subs/<rid>`.
  - **Cancel** dari satu run hanya MELEPAS Run ID itu; job dibatalkan hanya bila tidak ada run lain yang masih memantau (heartbeat < 20 s).
  - Progres (`prelim_progress&rid=`) dan panel timer terikat Run ID tab tersebut.

## Uji browser nyata (2 context Chromium independen + tab kedua)
Skrip `harness/ab_golden_multiuser/tools/conc.js` (+ `c5.js`, `conc_sup.js`, `suptest.php`). Dijalankan pada:
- Linux Apache 2.4 + PHP-FPM 7.4 (24 worker) — server nyata;
- proksi semantik XAMPP (12 backend PHP 7.4).

| Kasus | Langkah | Linux Apache + FPM 7.4 (final) | XAMPP-12 (final) |
|---|---|---|---|
| C1 | dua context browser independen | uid berbeda | uid berbeda |
| C2 | A Maximum Review + B Fastest Run bersamaan | A berjalan dengan timer & Run ID sendiri; B FINAL 11,5 s, CP 80.1442 | B FINAL 11,7 s, CP 80.1442 |
| C3 | B Save saat A berjalan | A tetap berjalan → FINAL CP 71.834 (60,4 s) | A FINAL CP 71.834 (60,1 s) |
| C4 | Save bersamaan A & B | keduanya tersimpan; versi per user berbeda; `cross_owner: false` | sama |
| — | A memuat record B | NOT_FOUND_OR_NOT_OWNED | sama |
| C6 | reload masing-masing | A IE row 1 = 471, B = 500 (data sendiri) | sama |
| C0 | dua tab dalam browser yang sama | uid sama, tab ID berbeda; record mencatat tab ID tab kedua | sama |
| C5 | input identik (job bersama), B cancel (cold, `c5.js`) | `detached: true, other_active_runs: 1`; A FINAL OPTIMAL (66 s) | `detached: true`; A FINAL OPTIMAL (68 s) |
| S1 | B Run, 1 s kemudian A Run dengan input berbeda (`conc_sup.js`) | B FINAL 14,2 s, A FINAL 12,4 s | B FINAL 13,0 s, A FINAL 12,4 s |
| S0 | Uji unit `pp_v3_supersede` (`suptest.php`) | final: hanya job A/tab1 milik sendiri yang dibatalkan → PASS; source sebelum perbaikan: keempat job (termasuk milik B dan job bersama) dibatalkan → FAIL | — |

Catatan pengujian:
- Proksi uji 6 backend `php -S` single-thread dapat kehabisan slot ketika dua Max/Fastest (masing-masing exec + 3 helper) berjalan bersamaan. Itu batas lingkungan uji, bukan perilaku XAMPP/Apache (ThreadsPerChild ±150) atau FPM (24 worker).
- Pada urutan penuh `conc.js`, langkah C5 tidak menemukan job bersama yang masih berjalan, karena hasil identik sudah ada di cache. Bukti cancel-isolation diambil dari uji C5 terpisah (`c5.js`) setelah cache job dibersihkan.
- Uji Linux pertama pada source final dijalankan bersamaan dengan uji C5 XAMPP di mesin 4 vCPU yang sama. Akibatnya A (Max) tidak FINAL dalam 240 s. Uji diulang sendirian (tanpa beban lain) dan hasilnya PASS seperti di tabel. Log kedua percobaan disimpan.

## Bug yang ditemukan dan diperbaiki saat uji
1. Race pembuatan job input identik → `flock` per job-id (`pp_job_start`).
2. Cancel membatalkan job bersama → subscriber per Run ID.
3. Run kedua setelah keputusan bahan bakar tidak membuka popup dan berakhir "Gerbang rilis menolak". Penyebab: (a) penanda internal `__fuel_decision_mode` basi, (b) worker tidak menilai keputusan bahan bakar untuk aksi `recommendation`. Keduanya diperbaiki; Run kedua kini menyelesaikan pilihan operator otomatis → FINAL.
4. **Run user A membatalkan job user B yang sedang berjalan.** Ditemukan pada uji Linux, ketika A dan B menekan Run hampir bersamaan: job B berstatus CANCELLED "DIGANTIKAN_RUN_BARU" pada detik pembuatannya, dan B tidak pernah mendapat hasil. Penyebab: `pp_v3_supersede` hanya menyaring prefiks konteks request (`plan-`), yang sama untuk semua user. Perbaikan: job menyimpan `owner_uid` / `owner_tab`, sehingga Run baru hanya menggantikan job milik user+tab yang sama, dan job yang masih dipantau run lain tidak dibatalkan (`PP_V1516_SUPERSEDE_SCOPE`).
