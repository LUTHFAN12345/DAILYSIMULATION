# CHANGES — V15.15-REFERENCE-LOGIC-ADOPTED-FASTEST-20261010

Basis: Revisi terakhir Copilot (V15.14). Reference V13.4 hanya dipakai sebagai acuan logika. Tidak ada file reference yang disalin utuh.

Baris berubah dibanding revisi terakhir (diff `<`/`>`):

| File | Baris berubah |
|---|---|
| run.php | 369 |
| worker02.php | 35 |
| worker_functions.php | 90 |
| index.php | 40 |
| saved_data_store.php | 0 |

## run.php
- Build ID `V15.15-REFERENCE-LOGIC-ADOPTED-FASTEST-20261010`.
- `mode=run`: Fastest - Default diserahkan ke job (`FASTEST_FIRST_VALID`).
- `pp_sync_handoff_respond`: kini membawa `helpers`.
- `mode=save`: membuang flag internal (`__no_exact_family`, `__fastest_local_only`, `_fast_default`, `_maximum_review`).
- Fungsi baru: `pp_v13f_is_fast`, `pp_v13f_clean`, `pp_v13f_pipeline`, `pp_v13f_basis_key`/`_file`, `pp_v13f_shortage`, `pp_v13f_mark`, `pp_v13f_fast_job`, `pp_v15_row1_export_cert` (terminal bersertifikat), `pp_v15_merit_proof`, `pp_v15_fast_llf_trim`, `pp_v15_fast_priority_fix`, `pp_v15_fast_priority_fix_pass`.
- Perbaikan Unit Priority C1–C4 (`pp_v15_fast_priority_fix_pass`):
  - Evaluasi gabungan dilakukan lebih dulu. Bila tidak valid, tiap pemindahan ditambahkan satu per satu (greedy): yang valid diterapkan, yang tidak valid mendapat bukti counterfactual tunggal. Ini menggantikan "separuh pemindahan", yang meninggalkan pasangan tanpa bukti sehingga winner Maximum Review golden diblok gate.
  - Hingga 8 lintasan, deadline 30 detik.
- `pp_job_run_economic_review`:
  - rute Fastest
  - V8 review dilewati saat shortage terbukti
  - perbaikan C1–C4 untuk Fastest dan Max
  - gate merit pada job
  - `pp_store_commit('SIMULATION_FINAL')` untuk hasil final job
  - status `TERMINAL_INFEASIBLE`
  - tahap progres `REVIEW_UNIT_PRIORITY` / `PERBAIKAN_UNIT_PRIORITY_C1_C4`
- `pp_attach_or_reject_acceptance`: 'Merit Proof C1-C4 STG' untuk 48 row. Gate `MERIT_PROOF_C1_C4_STG_FAIL` untuk Fastest dan job.
- V7 `pp_v7_fuel_rerun_from_basis`:
  - Fastest memakai basis Fastest tanpa family.
  - Max memakai basis pipeline (`PP_V15_MAX_FAMILY_BASIS=1` → canonical); family diparalelkan ke helper.
- Blok V15.14 tidak lagi menimpa `Gas Shortage (BBTUD)` / `Gas Shortage Action`.

## worker02.php
- `pp_global_commitment_review`: dilewati pada pipeline Fastest (`__pp_fastest_no_gcr`).
- `pp_run_simulation`:
  - family exact dan V8 internal dilewati pada Fastest
  - kolom `PV`, `SR_Min` per row
- Distillate: `rsort($eligible)` dipulihkan (dua lokasi).
- SR per row (`pp_reserve_min_peak` / `pp_reserve_min($m,$row)`).
- Batas gas commit-for-reserve: `+additional_lng` (LNG/mixed), 0 untuk Distillate.

## worker_functions.php
- `pp_reserve_min_peak` baru.
- SR per row di repair, redispatch, anticipatory commit, certificate, infeasibility proof, export-peak-shift, dan validator.
- `pp_anticipatory_commit_for_reserve`:
  - `$gasTotal` `/2.0`
  - urutan kandidat mengikuti Unit Priority
  - tuas "advance start" gas-netral dengan guard ekspor

## index.php
- `ppStripInternalFlags`.
- Fastest → `_fast_default` (bukan Maximum Review); `gsfRerun` menurut target.
- Validated-options dilewati pada Fastest.
- Rekomendasi sementara LNG/Distillate di popup.
- Judul FASTEST VALID PLAN dan pesan "Done. Fastest - Default selesai".
- Header TERMINAL_INFEASIBLE; `adoptBackendAsyncJob` menangani `TERMINAL_INFEASIBLE`.

## saved_data_store.php
Tidak berubah.
