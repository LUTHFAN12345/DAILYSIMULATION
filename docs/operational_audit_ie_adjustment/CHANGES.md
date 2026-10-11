# CHANGES V15.17: Operational Audit, 75 % Distillate, IE Adjustment

Basis: V15.16 (commit `2b66dd4`). Tidak ada rollback pekerjaan V15.16. Semua perubahan bersifat generik, tanpa hardcode tanggal, unit, atau skenario. Setiap perubahan perilaku punya saklar env untuk A/B.

## Distillate (Addendum §1)

- `worker02.php` `pp_dist_continuous_alloc()`: allocator **blok kontinu** per unit.
  - Level legal hanya 30/50/75/100 %.
  - Ramp bertahap ±1 level per row; masuk dan keluar blok pada 30 % bila bersebelahan dengan row gas.
  - Tidak ada Distillate pada unit ≤ 5 MW.
  - Plafon liter ditegakkan dalam liter; band landing [r, r+0,04] atau floor mode.
  - Saklar: `PP_DIST_CONT=0`.
- Jalur rekomendasi memakai allocator yang sama dengan margin `PP_DIST_REC_MARGIN` (5 %). Plafon tidak lagi memperkecil sisa kebutuhan lewat capBBTU, sehingga rekomendasi ≥ eksekusi.
- `worker_functions.php` `pp_dist_continuity_audit()` dan pelanggaran validator `distillate_continuity`. D → G → D hanya sah dengan bukti row tidak eligible. Bug float key PHP diperbaiki dengan daftar pasangan.
- `run.php`: `info['Distillate Continuity Audit']`.
- `index.php`: Excel memuat kolom `Distillate G* (%)` dan liter.
- `worker02.php`: status kuota gas dinilai pada gas **neto** (gross − offset Distillate). Rencana Distillate yang valid tidak lagi berlabel SHORTAGE di pill dan badge UI. Global `__pp_dist_gas_offset` di-reset per simulasi (sebelumnya bisa basi).

## IE Adjustment (Addendum §6A)

- `worker_functions.php`:
  - Pemetaan label row inklusif (00:30 = row 1, 00:00 = row 48); saklar `PP_IE_LABEL_INCLUSIVE`.
  - `pp_ie_series()` sebagai sumber tunggal prediction/adjustment/effective.
- `run.php`:
  - Rute warm `pp_v1517_ie_warm()`:
    - Basis = rencana fully valid terakhir dengan konteks fisik sama. Basis hanya disimpan setelah hard PASS dan gate PASS.
    - Stage A: jendela lokal ±2 row ditambah polish.
    - Stage B: 48 row dengan commitment tetap, plus pendaratan gas.
    - Fallback ke Fastest penuh (commitment repair) dengan alasan tercatat.
    - Penurunan IE ≥ min-load GTG terkecil memaksa review commitment penuh.
  - Key basis mengecualikan IE, jumlah bahan bakar, actual gas, dan **seluruh metadata request UI tingkat atas `_*`**. Perbaikan terakhir ini membuat basis warm akhirnya kena di browser.
  - Kolom `IE_Pred` / `IE_Adj` per row dan `info['IE Adjustment']`.
- `index.php`:
  - Garis "IE Effective" di chart, tooltip, dan statistik.
  - Kolom "Row" di tabel adjustment.
  - Kolom Excel IE Prediction/Adjustment/Effective.

## Actual Gas fast rerun (§6)

- Rute warm yang sama: row actual yang berubah ditambah jendela maju sampai 00:00, dengan landing gas 20 s / 10 evaluasi.
- Key basis memisahkan hash actual gas.

## Fixed Flow JBBK (§5)

- `worker02.php` `PP_FF_REDIST`: redistribusi darurat Min Flow PGN.
  - Pengurangan minimal, water-filling prorata, Σ tetap.
  - Maksimal 3 iterasi, dengan bukti terminal bila envelope tidak cukup.

## Stop Status (§7)

- `index.php`:
  - `STOP_MODES` + `Unit Continuous Running` dengan sinkronisasi dua arah ke Commitment Mode.
  - Payload memakai enum kanonik tunggal: continuous tidak menulis `stop_mode`.
- `worker02.php`: perbaikan jembatan STG.
  - `PP_V1517_BRIDGE_DOWN`: feeder tetap online melewati celah yang lebih pendek dari minimum downtime.
  - `PP_V1517_BRIDGE_STGOUT`: jendela off dideteksi dari output STG.

## Change Over (§8)

Detail di `CHANGEOVER_BIDIRECTIONAL.md`.

- `index.php` `CO_INITED`: payload Change Over dari UI kini benar-benar terkirim. Sebelumnya selalu `enabled:false`.
- `worker02.php` `pp_changeover_sim_sweep`:
  - Refinement defisit Export/SR (start lebih awal, varian forced start, stop lebih akhir, varian sibling).
  - What-if LNG untuk rekomendasi.
  - Polish dengan evaluator single-pass, first-improvement, plafon 20 s.
  - `Global Commitment Review` berisi comparator Change Over, sehingga job validated-options/probe menilai review ekonomi tuntas.
- `worker_functions.php`: varian `change_over_force_target_start` (hanya pada clone kandidat).
- `run.php`:
  - Alasan C3 `STATUS_PAKSA:CHANGE_OVER_*`.
  - Audit STG memakai gerbang release STG engine.
  - Opsi bahan bakar tervalidasi untuk Change Over yang gagal hanya karena gas. Sebelumnya tombol LNG/Distillate di popup terkunci.

## Performa (§3, §9)

- Polish Fastest dibatasi `PP_FAST_POLISH_S` (3 s).
- Fix pass Unit Priority:
  - Ruang Distillate.
  - Prasaring Export numerik.
  - Divide & conquer (`PP_FIX_DC`).
- Pendaratan gas Fastest (`PP_FAST_GAS_LAND`): bila hasil pipeline gagal hanya karena window gas (mis. IE + Actual Gas, IE11), commitment yang sama didaratkan dengan lever gas engine.
- `pp_tl_eval`: polish per node dibatasi `PP_TL_POLISH_S` (3 s).
  - Allocator kontinu membuat lebih banyak node keluarga Max yang valid, sehingga keluarga 13 node memakan 56 s dan golden Max mencapai 86 s.
  - Sekarang keluarga 7,8 s dan golden Max 26,4 s dengan CP identik 79,9049.

## UI (§2)

- `#run-msg` ringkas satu baris: status · target · waktu · kandidat/valid · CP · HR · bahan bakar.
- Footer build diperbarui ke V15.17.

## Lain-lain

- `run.php`: notice "Undefined array key" pada audit headroom diperbaiki (urutan pengecekan; logika identik).
