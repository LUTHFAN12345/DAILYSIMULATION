# ROOT CAUSE — BBLN1 Stop All Day + Start Block 1 + G8/G9: `MERIT_PROOF_C1_C4_STG_FAIL`

Build: `V15.17b` (lanjutan dari commit `e63dd91`). Checksum source ada di `CHECKPOINT_BBLN1.md`.

## 1. Ringkasan

Gerbang rilis menolak rencana ini bukan karena rencananya melanggar C1–C4 atau STG. Penyebabnya, **audit STG di merit proof menghitung ulang STG tanpa aturan "hold" start dingin yang dipakai engine**. Akibatnya dua row startup S1 tampak tidak cocok, padahal sebenarnya cocok (hipotesis 2/8: proof salah hitung, bukan kandidat salah).

Patch minimal: aturan hold STG dijadikan **satu fungsi bersama** (`pp_stg_hold_from_rows`). Fungsi ini dipakai engine (worker02, PATCH H03) **dan** merit proof. Tidak ada validator yang dilonggarkan, dan gate tidak dimatikan.

Selama penelusuran ditemukan enam masalah lain yang menghalangi acceptance T0. Semuanya diperbaiki:

1. **UI menimpa pilihan operator.** G6/S1 "Start Based On Simulation" dikirim sebagai Continuous + Cannot Stop. Pada run ke-2, Last Data juga dibalik menjadi Running.
2. **PHP 7.4 (target XAMPP) menabrak deadline 60 s.** Penyebabnya bisection window supplier PGN yang sia-sia pada respons tidak kontinu.
3. **Fastest tidak deterministik.** Hasilnya berbeda antar run dan antar beban CPU (CP 72,6698 / 72,6602 / 72,6545) karena polish dan review V8 dibatasi jam dinding.
4. **Arsip `report_planning` (2,04 MB dari 2,05 MB payload) ikut di setiap deep-copy engine.** Biayanya +10 s.
5. **Proof belum terikat ke kandidat yang dirender.** Tidak ada hash kandidat, commitment, fuel, maupun proof.
6. **T2 (IE Adjustment) jatuh ke pipeline penuh 88 s lalu gate FAIL.** Penyebabnya pendaratan gas IE warm yang memakai hint supplier.

Hasil T0 lewat UI nyata (6 cold + 6 warm, XAMPP-like dan Linux): semua **FINAL, gate PASS, merit PASS (C1–C4 FAIL 0, STG PASS)**.

- BB1 = 0 MW di seluruh row.
- Save/Export aktif.
- `candidate_hash 0b63b7e26b3bc2e628e5253c` identik di 12 run.
- CP 72,3124 USD/MWh, HR 8509,21 BTU/kWh.
- Klik → 48 row FINAL: 23,7–24,9 s cold, 0,36–0,41 s warm.

## 2. Reproducer dan payload

- **Input**: P0 + CSV `CSV_Load_Pred_dan_Dispatch_5_Oct.csv`, diimpor lewat `csvHandleText` + `csvApplyPending` UI. Row 1 = `05-Oct-26 00:30` dan row 48 = `06-Oct-26 00:00`, jadi tidak bergeser satu interval. PV row 12 = 0,43.
- **Gas**: PGN 23 BBTUD, LNG 13, PEP 36, Akasia 4, Baskara 0, BBG 0. GHV PGN 1040 / JBBK 1080 / MM2100 1030. Min PGN Flow 3. KP72 semuanya 0 dan Max Flow MM2100 0. Mode "No Shortage" (`gas_shortage_action = recommendation`, tidak pernah auto-Distillate).
- **Status unit** di-set lewat elemen UI (`data-flag`/`data-u` + event `change`), persis seperti operator:
  - G2/G8/S2/S3/BBLN2: Running + Continuous.
  - G6/G9/S1: Stop + Required + Start Based On Simulation + Stop Based On Simulation.
  - BBLN1: Stop All Day.
  - Unit lain: Stop dan Required OFF.

### Perbedaan payload yang ditemukan (dilaporkan, tidak diubah diam-diam)

| Field | Yang dipilih operator | Yang dikirim UI e63dd91 | Sesudah patch |
|---|---|---|---|
| `required_mode.g6/s1` | `based_on_sim` | `continuous` (oleh `syncBlockRequiredToCommitment`) | `based_on_sim` |
| `unit_cannot_stop` | g2, g8, s2, s3, b2 | + g6, s1 | g2, g8, s2, s3, b2 |
| Last Data G6/S1 (run ke-2) | Stop | Running (otomatis dari sinkronisasi blok) | Stop |
| G8 | Running, Continuous | Running, Continuous (sama) | sama: G8 tidak di-start ulang, 1–48 |

G8 pada payload aktual sama dengan konfigurasi tertulis. Tidak ada perbedaan status G8.

## 3. Audit jalur Save / Run / datastore

| Item | Lokasi / nilai |
|---|---|
| Tombol Save | `index.php saveInput()` → `POST run.php?mode=save`. Scoped save (`sds_ctx(uid)` ada) → `sds_commit('SAVE', …)`. Tanpa uid → `input_data.json` legacy (atomic write). |
| Autosave saat Run | `runSim()` mengirim `payload._autosave=true` ke `run.php?mode=run`. Di server `pp_v3_autosave()` → `sds_commit('AUTOSAVE_RUN', …)` (record immutable per run). |
| Datastore kanonik | `saved_data_store.php` (`sds_*`). Backend `sqlite-wal` bila PDO-SQLite tersedia, selain itu `json-lock-atomic`. |
| Lokasi fisik XAMPP | `C:\xampp\htdocs\<app>\saved\store.sqlite` (tabel `records`), atau fallback `…\saved\json\<uid>\<project>\v00000001_<record_id>.json`. Input terakhir per user: `…\saved\users\<uid>\input_latest.json`. Job: `…\jobs\<kind>-<hash20>\input.json`, `job.json`, `result.json`, `subs\<run_id>`. |
| Lokasi fisik Linux | `/var/www/html/php74/opr-simulation/saved/…` dan `/var/www/html/php74/opr-simulation/jobs/…` (struktur sama). |
| Session/user ID | `pp_uid`: dibuat sekali, di localStorage + cookie (`window.PP_UID`). Ditambahkan ke setiap `run.php?…` sebagai `uid=`. |
| Tab ID | `pp_tab`: di sessionStorage (`window.PP_TAB`), parameter `tab=`. |
| Project ID | `sds_ctx`: `_project`, atau `p_` + sha256(plan_type\|plan_date\|nama plan)[0:16]. Pada reproducer ini `p_12bc23195a4987dc`. |
| Run ID | `_request_id` = `<plan\|monitoring>-<state_revision>-<base36 waktu>` (mis. `plan-2-mv3e4ryb`). Juga `window.PP_CUR_RID`. |
| Record / version ID | `rec_<YmdHis>_<hex10>` + `version` naik per (uid, project). |
| Snapshot input terbaru | `run.php?mode=store_latest` / `sds_latest_input(uid)`. Untuk payload yang benar-benar dihitung: `jobs/<id>/input.json` (ditemukan lewat `jobs/*/subs/<run_id>`). |
| Mencegah stale | Hasil diikat ke run ID (subscriber) dan `input_hash` job. Proof Binding baru membawa `payload_hash`/`candidate_hash`. Snapshot membandingkan payload operator job dengan payload layar (`same_payload_as_snapshot`). |

**Export Current Input Snapshot** (tombol `⇩ Input Snapshot` di run bar). Payload dibangun **persis seperti klik Run**: `assembleInput` + pembersihan + flag Fastest/Maximum Review. Server menormalisasinya (`run.php?mode=input_snapshot`, read-only: tidak menjalankan engine dan tidak menulis datastore). Hasilnya diunduh sebagai `input_snapshot_<project>_<run_id>_<timestamp>.json`.

Isi snapshot:

- **`payload`**: payload worker tanpa field transport `_*`.
- **`summary.rows`**: 48 row dengan IE Prediction, Adjustment, Effective, Dispatch, PV, dan SR Effective.
- **`summary.gas`**: semua kuota, Actual Gas, GHV, Min PGN Flow, Fixed Flow, MM2100 mode, dan keputusan bahan bakar.
- **`summary.units`** untuk 19 unit: present, Last Data, Required, Commitment Mode, Stop Mode, Cannot Stop, Stop All Day, Stop At, Fix/Skip Load, Unit Priority, dan Distillate Priority.
- **`summary`** juga memuat: block priority, SR (mode, Fix SR, Follow PV, Bus Flow min), Change Over, STG startup mode, dan aturan IE.
- **`context`**: uid, tab, project, run, state_revision, run_mode, backend datastore, dan record terbaru.
- **`hashes`**: `payload_hash` dengan definisi yang sama dengan Proof Binding, `job_input_hash`, serta rows/units/gas hash.
- **`jobs_for_this_input`**: job yang dipakai run tersebut beserta `same_payload_as_snapshot`.

Snapshot tidak memuat path server maupun secret.

Terverifikasi pada T0: `payload_hash 5777f3b4cfe8cbeb8f47b8c1` di snapshot sama dengan `Proof Binding.payload_hash` hasil run dan dengan payload operator `jobs/<id>/input.json` (`same_payload_as_snapshot: true`).

## 4. Trace hipotesis 1–10 (rencana FAIL asli)

Kandidat rencana FAIL asli (payload niat operator, CP 72,4453):

- `candidate_hash 618587c00ca85d0d510e701d`
- `commitment_hash 22e9d039b70d0a8acf745e11`
- `fuel_hash 5ff86382df094640a3c8cdf8`
- `payload_hash e9019af9cb15709a8cfeee78`

| # | Hipotesis | Hasil |
|---|---|---|
| 1 | Kandidat memang melanggar C1–C4/STG | **Tidak.** C1 39/0, C2 2/0, C3 85/0, C4 147/0 (temuan/FAIL). STG fisik konsisten dengan engine. |
| 2 | Proof valid tetapi salah dihitung | **YA — akar masalah.** Audit STG `pp_v15_merit_proof` memanggil `pp_recompute_stgs` dengan model tanpa `__stg_hold`. |
| 3 | Proof memakai candidate hash lama | Tidak. Binding baru: `candidate_hash` proof = hash data yang dirender (`bound_to_rendered: true`, tanpa rebind) di semua run. |
| 4 | Startup Block 1 mengubah dispatch setelah proof | Tidak. Proof dihitung di `pp_attach_or_reject_acceptance` atas output final. |
| 5 | Startup G8/G9 mengubah commitment setelah proof | Tidak. `commitment_hash` proof = commitment final. |
| 6 | BBLN1 Stop All Day memakai proof family lama | Tidak. BB1 0 MW di 48 row, dan BB1 bukan lever (Stop Schedule enforced row 1). |
| 7 | Polish/fuel repair mengubah row setelah proof | Tidak. Seluruh repair berjalan sebelum acceptance, dan `fuel_hash` sama. |
| 8 | Proof array salah key/null/stale | Tidak, kecuali nilai STG yang salah dihitung (lihat #2). |
| 9 | Aggregator menganggap `not_applicable` FAIL | Tidak. `TIDAK_BERLAKU` hanya untuk output yang tidak 48 row dan tidak dipakai sebagai PASS. |
| 10 | Gate membaca snapshot sebelum publish atomik | Tidak. Gate membaca `release_gate` hasil acceptance yang sama. Job memvalidasi ulang bila hash kandidat berbeda (`rebound`). |

**Row pertama yang berbeda (expected vs actual proof).** Audit STG pada S1:

| Row | S1 dilaporkan engine | S1 dihitung audit lama | Penjelasan |
|---|---|---|---|
| 22 | 0,000 MW | 11,192 MW | S1 Last Data Stop, start Cold. Hold 7 row sejak feeder pertama ON, dan engine menahan 0 MW. |
| 23 | 20,000 MW | 25,775 MW | Cap 20 MW pada kemunculan pertama S1/S2 Cold. |

Proof atas kandidat yang **sama** (`618587c00ca85d0d510e701d`):

| | Proof lama (e63dd91) | Proof baru (V15.17b) |
|---|---|---|
| Merit | FAIL | PASS |
| STG | FAIL, mismatch 2 | PASS, mismatch 0 |
| Hash proof C1 / C2 / C3 / C4 | 54b59f1f… / a1cc5ec9… / 82dd1644… / e9eac3b3… | identik |
| Hash proof STG | `17a0640148299ee2` | `5551698e2b0c29eb` |

## 5. Akar masalah dan patch minimal

| ID | Routine | Akar masalah | Patch | Saklar (default aktif) |
|---|---|---|---|---|
| A | `run.php pp_v15_merit_proof` (bagian STG) | Audit STG tanpa hold start STG yang dipakai engine | `worker_functions.php pp_stg_hold_from_rows()` (aturan H03, satu sumber). Dipakai engine (worker02 H03) dan proof (`$mStg['__stg_hold']`). Ditambah `startup_hold` di laporan STG. | `PP_STG_PROOF_HOLD=0` |
| B | `index.php syncBlockRequiredToCommitment` | Fase SET menimpa mode manual operator (Start/Stop Based On Simulation) dengan Continuous | Mode `simulation`/`request` milik operator dipertahankan, dengan satu toast "Block Required: pilihan manual … dipertahankan". | – |
| C | `worker02.php pp_actual_gas_compensation_raw` | Respons PGN fisik tidak kontinu (21,47 → 25,29 BBTUD di dalam celah window). Bisection menyempit sampai epsilon (11–19 percobaan per core run), lalu PHP 7.4 menabrak 60 s. | Berhenti bila 2 percobaan berturut-turut di sisi yang sama menggeser PGN < 25 % jarak ke window. Hasil terdekat dipakai, sama seperti akhir pencarian penuh yang tidak mendarat. | `PP_SUP_STAGNANT=0`, `PP_SUP_STAGNANT_N` |
| D | Fastest: worker02 polish pipeline, gerbang comparator global (BUG-16), run.php review V8 dan lanjutan polish V8 | Batas **jam dinding** (polish 3 s, V8 2 s, gerbang sisa-waktu yang mematikan polish) menentukan berapa kandidat yang sempat dinilai. Hasil Fastest jadi bergantung kecepatan CPU/beban (terukur: 7 vs 0 evaluasi polish, CP 72,6698 / 72,6602 / 72,6545 pada payload sama). | Batas **jumlah**: polish 8 evaluasi; cap kandidat V8 = (48 − simulasi inti pipeline)/8, dibatasi 0..4 (golden: 4, T0: 0); lanjutan polish V8 8 evaluasi. Gerbang sisa-waktu comparator tidak berlaku di Fastest (comparator memang tidak dijalankan). Jam dinding tinggal pengaman (V8 45 s, deadline absolut). | `PP_V1517_DET_FAST=0`, `PP_FAST_PIPE_POLISH_EVALS`, `PP_FAST_POLISH_EVALS`, `PP_FAST_SIM_BUDGET`, `PP_V8_FAST_SAFETY_S` |
| E | `run.php` job runner | `data3.modeling.report_planning` (arsip UI 2,04 MB, sudah termasuk meta di semua kunci state/job) ikut di setiap deep-copy dan kunci memo per core run | `pp_v1517_engine_input()`: dibuang dari input **komputasi** job. `input.json`, identitas job, dan datastore tidak berubah. T0 CLI 36 → 28 s, hasil identik. | `PP_V1517_STRIP_RP=0` |
| F | `run.php pp_attach_or_reject_acceptance` + job | Proof tidak terikat ke kandidat yang dirender | `info['Proof Binding']`: `proof_version v1517b-merit-bind-1`, `validated_at`, `payload_hash`, `candidate_hash` (MW 48 row seluruh unit + Dist_Total), `commitment_hash`, `fuel_hash`, `proof_hash` C1/C2/C3/C4/STG, dan `proof_status`. Sebelum `VALIDASI_SELESAI` job membandingkan `candidate_hash` dengan data yang dirender; bila berbeda, acceptance dijalankan ulang (`rebound`). | – |
| G | `run.php pp_v1517_ie_warm` tahap B | Pendaratan gas memakai target supplier evaluasi sebelumnya sebagai titik awal, lalu jatuh ke cabang dispatch lain dan berhenti. Offset yang sama dari kontrak valid (+1,0696 BBTUD). Fallback pipeline penuh menabrak 60 s, kemudian C4 FAIL 14. | Bila pendaratan ber-hint gagal, ulangi **sekali** tanpa hint (`pp_tl_land_commitment_one(..., $noHint=true)`). Hasil tetap dinilai penuh. | `PP_IE_WARM_NOHINT=0` |

Yang **tidak** dilakukan:

- Gate tidak dimatikan.
- Save/Export tidak dibuka paksa.
- FAIL tidak diubah menjadi PASS, dan proof kosong tidak dianggap PASS.
- C1–C4/STG tidak dilonggarkan.
- Tidak memakai hasil run lama.

## 6. Proof lifecycle

Acceptance (hard → merit C1–C4 → STG → aggregasi → `Proof Binding`) dihitung atas output **final** setelah seluruh repair commitment/fuel. Job lalu memastikan `candidate_hash` binding = hash data yang akan dirender. Bila berbeda, acceptance dijalankan ulang, baru `VALIDASI_SELESAI` dan publish.

Contoh T0 final:

- `payload_hash 5777f3b4cfe8cbeb8f47b8c1`
- `candidate_hash 0b63b7e26b3bc2e628e5253c`
- `commitment_hash 622df51cd582bdad54d865bb`
- `fuel_hash 5ff86382df094640a3c8cdf8`
- `proof_hash`: C1 04070634…, C2 98b7bcac…, C3 0a78eae1…, C4 79af1a95…, STG 623921d2…
- `bound_to_rendered: true`

## 7. T0 — lewat UI nyata, source freeze, 3 cold + 3 warm per platform

Cold = server di-restart dan folder `jobs/` dihapus. Warm = klik Run kedua pada halaman yang sama. Waktu = klik Run → 48 row FINAL dirender (sama dengan timer "Fastest - Default · N detik" di layar).

{{UI_TABLE}}

**Commitment T0:**

- BBLN1 0 MW di 48 row; BBLN2 1–48 (115–120 MW, Continuous).
- G8 1–48 (Continuous, tidak di-start ulang, 65–108 MW).
- G9 2–48.
- Block 1: G6 15–48, G4 16–35, S1 22–48. S1 Cold: hold row 15–21 = 0 MW, row 22 = 20 MW (cap), lalu kopling normal.
- G2 1–48, G5 15–34, G1 16–29. G3 tidak dipakai.

Pada run deterministik, sapuan konsolidasi V11 mengganti G3 dengan **G5 (Unit Priority lebih tinggi)**: CP 72,6681 → 72,3129, HR 8562,85 → 8509,29. Ini berada di dalam band CP 0,2 % dengan tie-break HR.

**Fastest tetap Fastest** (`FASTEST_PIPELINE`, label "Fastest - Default") dan tidak berubah menjadi Maximum Review. Waktu ≤ 30 s (kasus sulit).

**Parity XAMPP/Linux**: `candidate_hash`, CP, HR, dan `payload_hash` identik di 12 run.

## 8. T1–T5 (setelah T0 PASS)

Dijalankan lewat CLI PHP 7.4 dengan jalur job Fastest yang sama (`pp_job_run_economic_review`), pada source freeze, berurutan.

{{TT_TABLE}}

### Tafsiran

- **T1 (family F0/F1/F2).**
  - G8 sudah Running/Continuous, jadi tidak ada family "start G8".
  - F0 (Block 1 + G8 saja, Required G9 dilepas): engine **tetap** menyalakan G9 di row 2 karena total gas berada di bawah lantai window kuota setelah lever up-dispatch habis (lever start unit sah, terurut Unit Priority). CP 72,6602, lebih mahal dari T0.
  - F1 (G9 dipaksa start lebih lambat, 07:00 atau 14:00): **tidak feasible** (hard FAIL: `gas_quota`, `export_range`, `export_ramp`). Tanpa G9 sejak dini, window gas dan pita Export tidak dapat dipenuhi bersamaan.
  - Jadi start G9 di row 2 diperlukan, dan T0 (G9 Required oleh operator) adalah family valid termurah yang diperiksa.
  - F2 (Block 1 tanpa G8/G9) tidak mungkin: G8 Continuous dan G9 diperlukan oleh window gas.
- **T2.** Jalur IE warm (commitment basis dipertahankan) dengan pendaratan tanpa hint (patch G): gate PASS dalam beberapa detik. Sebelum patch: 88 s, gate FAIL C4 14.
- **T3.** Lihat §9.
- **T4.** Unit startup terpilih (G5) dibuat unavailable. Fallback legal ke G3 (urutan Unit Priority berikutnya di Block 2/1), gate PASS.
- **T5.** CP/HR golden sama persis dengan V15.17 (79,9049 / 8239,87).

## 9. Temuan terbuka (dicatat apa adanya)

1. **T3 PGN −1 (22 BBTUD) lambat.** Gate PASS, tetapi Fastest ±64 s CLI (> 30 s).
   - Pipeline menyentuh deadline absolut 60 s.
   - Tiga kandidat decommit menghasilkan core run yang identik, termasuk deret pencarian supplier yang persis sama, sehingga dihitung ulang tiga kali.
   - Di e63dd91 kasus ini 29,6 s tetapi **gate FAIL**. Itu terjadi karena tahap decommit dilewati oleh admission berbasis waktu (baseline 13,3 s × 4 > sisa waktu).
   - Usulan: dedup kandidat decommit menurut dispatch dasar yang identik sebelum kompensasi supplier.
2. **Batas waktu yang tersisa** (admission tahap decommit dan deadline absolut 60 s) masih berbasis jam dinding, sebagai pengaman.
   - Pada mesin uji, T0 selesai jauh sebelum keduanya, sehingga hasil deterministik.
   - Pada mesin > ~2× lebih lambat, admission decommit dapat menunda tahap itu dan hasil Fastest bisa berbeda (Maximum Review tetap lengkap).
3. **T2 cold** (IE berubah tanpa basis run sebelumnya) masih memakai pipeline penuh, yang pada konfigurasi ini menabrak 60 s. Alur UI normal (Run → ubah IE → Run) memakai jalur warm yang sudah PASS.

## 10. Berkas yang berubah (terhadap e63dd91)

- `worker_functions.php`: `pp_stg_hold_from_rows()` (baru).
- `worker02.php`:
  - H03 memakai fungsi bersama;
  - stagnation stop pencarian supplier;
  - polish Fastest dibatasi jumlah evaluasi;
  - gerbang sisa-waktu comparator tidak berlaku di Fastest;
  - opsi `$noHint` pendaratan.
- `run.php`:
  - merit proof STG dengan hold;
  - `pp_v1517_operator_payload_hash`, `pp_v1517_candidate_hash`, `pp_v1517_proof_binding` dan rebind job;
  - `pp_v1517_input_snapshot` + `mode=input_snapshot`;
  - cap V8 deterministik dan lanjutan polish V8 berbasis jumlah;
  - `pp_v1517_engine_input` (tanpa `report_planning`);
  - IE warm tanpa hint.
- `index.php`: tombol **⇩ Input Snapshot** + `exportInputSnapshot()`; `syncBlockRequiredToCommitment` mempertahankan mode manual.
- `saved_data_store.php`: tidak berubah.
