# Root cause — Required start Block 1 (G6+S1) + G8 dengan Gas Shortage (PGN 24, LNG 8, PEP 32)

Reproducer: `input_data.json` yang diunggah, tanpa diubah. Required start G6/G8/S1 (Last Data = Stop), S1 Cold, gas PGN 24 / LNG 8 / PEP 32 / Akasia 4, `use_distillate`, Fastest - Default.

## 1. Kesimpulan

| # | Penyebab | Bukti terukur | Perbaikan |
|---|---|---|---|
| A | **Input ini tidak mempunyai rencana valid sama sekali.** Row 1 (00:30) hanya boleh dibebani unit yang Running pada Last Data (G1, G9), karena aturan hard `last_data_status` melarang unit Stop berbeban di row 1. Pada beban maksimum G1 31 MW + G9 108 MW, FLOW PGN REAL TIME = (24 × 1,69721 − 36 × 1080/1000) / 1040 × 1000 = **1,782 MMSCFD < Min PGN Flow 3,00** (fixed flow PEP 32 + Akasia 4 = 36 MMSCFD). Distillate tidak dapat menolong karena mengurangi gas. | Validator dengan G8 dipaksa berbeban di row 1 → `last_data_status` + `startup_sequence`. Seluruh run sebelumnya (dua job UI dan job langsung) berakhir dengan `pgn_rt_min` row 1. | `pp_bs_row1_certificate` (run.php): sertifikat matematis di awal job, ditambah satu core run (±1 s) untuk bukti gas/Distillate dan pratinjau startup. Keputusan terminal **`GAS_SHORTAGE_REQUIRED_START_NOT_FEASIBLE`** dalam **1,7–2,3 s**. |
| B | **`use_distillate` yang sudah dipilih diubah UI menjadi `recommendation`.** Job pertama berjalan tanpa bahan bakar (`USER_FUEL_DECISION_REQUIRED`), lalu UI menjalankan job kedua (rerun bahan bakar) dengan plafon = rekomendasi job pertama. | Jejak browser sebelum perbaikan: job 1 dibuat 3,0 s → selesai ±37,5 s → job 2 dibuat 38,2 s → ditolak 52,7 s (`HARD_VALIDATION_FAILED`, `FUEL_ACTION_NOT_APPLIED`: kebutuhan 168.728 l > plafon 166.910,6 l = rekomendasi basis tanpa bahan bakar). | `index.php`: `use_distillate` dikirim langsung dalam **satu job**. `Distillate limit` hanya dikirim bila diisi operator; field kosong = engine menghitung kebutuhan (alokasi satu-unit-dulu). |
| C | **"Tahap engine selesai, job belum terminal."** Untuk `use_distillate`, `pp_v7_fuel_rerun_from_basis` menghitung dahulu state basis TANPA bahan bakar (pipeline + keluarga commitment), lalu membuangnya karena dispatch basis tidak valid untuk Distillate. | Profil sampling CLI: `PIPELINE_END` 22,9 s → **39,4 s tanpa progres** (`pp_exact_family_stage` → `pp_tl_family_search` basis) → `V7_RERUN_BAHAN_BAKAR…` 62,3 s → pipeline Distillate → DONE 102 s (76 s via HTTP dengan pembantu). Tahap yang terlihat di UI "selesai", padahal job masih bekerja. | Basis tanpa bahan bakar yang belum ada di memo **tidak dihitung** untuk `use_distillate` (`PP_BS_FUEL_BASE=1` = perilaku lama). |
| D | **Polling tanpa status terminal.** `adoptBackendAsyncJob` mengizinkan sampai 3.600 iterasi polling (±1 jam) bila job tidak pernah DONE/FAILED, misalnya job QUEUED yang request `job_exec`-nya tidak diterima server. Job `validated_options` yang didaftarkan backend pada hasil `USER_FUEL_DECISION_REQUIRED` juga tetap QUEUED selamanya di jalur otomatis. | Job `validated_options-…` pada run sebelum perbaikan: status `QUEUED`, `helpers 0`, tidak pernah dieksekusi. | Watchdog UI: QUEUED → dipicu ulang setelah 15 s, gagal terminal + `job_cancel` setelah 60 s. Job tanpa status terminal melewati `ceiling_s + 120 s` → gagal terminal. Watchdog yang sama dipasang pada polling popup opsi bahan bakar. |
| E | **Repair lantai FLOW PGN tidak konsisten dengan kasus `use_distillate`.** Lift PGN ditolak karena gas sudah di atas kuota *bare* (kekurangan memang ditutup Distillate sesudah dispatch). Pass over-trim dan export-ramp repair hilir menurunkan kembali row yang sudah diangkat. Repair juga mengabaikan Manual Fixed Flow per row, padahal validator memakainya. | Varian diagnostik (Manual Fixed Flow row 1 = 30): sebelum perbaikan PGN row 1 2,142 < 3; sesudah 3,028. Basis PEP 28 / PGN 34: mesin lama gagal `pgn_rt_min`, freeze hard PASS. | `worker_functions.php`: lift tidak dibatasi kuota bare bila `use_distillate`; guard lantai PGN generik (`pp_pgn_step_bad`) pada over-trim dan `pp_export_ramp_repair`; Manual Fixed Flow per row dipakai repair. Guard hanya menolak langkah yang membuat row jatuh di bawah lantai, jadi rencana yang sebelumnya valid tidak berubah. Bukti: fingerprint U31/U30 dan CO tidak berubah. |

## 2. Timeline yang diminta (titik sesudah tahap engine)

| Titik | Sebelum (UI, emulasi XAMPP) | Sesudah (freeze, UI, emulasi XAMPP) |
|---|---|---|
| klik Run → job dibuat | 0 → 0,2 s (job 1, mode `recommendation`) | 0 → ±0,2 s (satu job, `use_distillate`) |
| pipeline_end | job 1 ±16 s sejak job dibuat; tahap terlihat 35,6 s di mesin pengguna | — (tidak diperlukan; sertifikat 0,6 s) |
| result snapshot write | job 1 ±34 s (keluarga commitment basis) | ±1,4 s (core run bukti + validasi) |
| acceptance / release gate | FAIL: `gas_quota`, `pgn_rt_min`, `export_range`, `runtime_downtime` | keputusan terminal, gerbang terkunci (bukan rencana) |
| gas shortage decision | `USER_FUEL_DECISION_REQUIRED` + job `validated_options` QUEUED (yatim) | tidak ada popup; keputusan memuat gas & Distillate |
| fuel-action rerun | job 2 dibuat 38,2 s, selesai 52,7 s, ditolak (plafon 166.910,6 l < 168.728 l) | tidak ada (satu job) |
| job status DONE / result.json | job 1 DONE ±37 s, job 2 DONE 52,6 s | DONE ±1,6 s |
| browser polling | berlanjut ke job 2; di mesin pengguna sampai ±1.400 s | berhenti saat DONE; **0 request** polling dalam 6 s sesudahnya |
| modal close / Simulation Data | 0 row (hasil ditahan) | keputusan terminal tampil **2,34 s** (XAMPP-sem) / **1,66 s** (Linux), Run aktif |

Angka 1.400 s tidak dapat direproduksi persis di lingkungan uji ini. Rantai di atas menunjukkan job yang tidak menjadi terminal (basis terbuang, job yatim QUEUED, polling hingga 1 jam), dan kini setiap jalur itu berakhir dalam batas yang terukur.

## 3. Yang tidak diubah

Merit dispatch, C1–C4, STG proof, Change Over, Distillate one-unit-first, format penyimpanan, hard validator. Validator hanya mendapat aturan baru `continuous_to_end` (lihat STOP_STATUS_CONTINUOUS_AUDIT.md). Release gate tidak dipaksa.

## 4. Fungsi yang diubah atau ditambah

- `run.php`: `pp_bs_row1_certificate`, `pp_bs_certificate_output`, wiring di `pp_job_run_economic_review` (label `pp_bs_after_compute`), `pp_v7_fuel_rerun_from_basis` (lewati basis untuk `use_distillate`), `pp_reconcile_selected_fuel` (`FUEL_ACTION_NOT_NEEDED` bila tidak ada kekurangan), `pp_v12_fast_release_try` (input teresolusi; tunda rilis bila ada `stop_or_continuous_sim`), plus fungsi Stop Status.
- `worker_functions.php`: `pp_pgn_floor_ctx`, `pp_pgn_row_energy`, `pp_pgn_step_bad`, repair PGN (`$pgnFuelCovers`, Manual Fixed Flow per row), `pp_export_ramp_repair` (guard PGN), validator 8a-ter `continuous_to_end`.
- `worker02.php`: guard PGN pada GAS OVER-TRIM fase 1 dan 2.
- `index.php`: kirim `use_distillate` langsung, tampilan keputusan terminal, watchdog polling, dropdown Stop Status.
