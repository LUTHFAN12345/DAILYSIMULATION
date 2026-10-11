# ROOT CAUSE — SC/CC, Continuous Running, Block Required, Sequence Block

Basis: commit `78b72d5` (paket `php_simulation_BBLN1_BLOCK1_MERIT_PROOF_FINAL.zip`). Reproducer: file Monitoring
`Monitoring_Daily_Plan_10_May_26_Baru1.xls` (skenario 05-Oct yang sama dengan BBLN1; CP 72.5145, HR 8539.92,
engine fingerprint d89269bc). Source V15.18 di commit `13188f2` (WIP) + commit laporan ini.

## 1. Akar masalah

| # | Gejala | Akar masalah (source 78b72d5) | Perbaikan V15.18 |
|---|---|---|---|
| R1 | Stop Status "Unit Continuous Running" membuat unit berbeban 00:30–00:00 | `index.php` handler `stopmode`: nilai `continuous` menulis `COMMIT_MODE[u]='continuous'` + Last Data Running (toast "Commitment Mode ikut Continuous Running"). Payload menjadi `required_mode {continuous}` + `unit_cannot_stop` = full horizon | UI: Stop Status hanya menulis `stop_mode {mode:'hold_to_end'}`; Commitment Mode/LDS tidak disentuh. Engine `pp_v1518_semantics`: `hold_to_end` → `HOLD_TO_END_AFTER_START` (cannot-stop SETELAH start, boleh OFF sebelum start) |
| R2 | Block Required = seluruh block full horizon | (a) UI `syncBlockRequiredToCommitment` fase SET memaksa STG + GTG prioritas 1 → `continuous` + Running. (b) Engine `pp_solve_block`: token `required` pada `block_priority` = lantai 1 unit per row | UI: unit primer tanpa mode → Start Based On Simulation (mode eksplisit operator dipertahankan; unit yang sudah Running kemarin dibiarkan). Engine: blok Required tanpa unit yang diminta → token dibuang, STG/lead dijadikan `required_units` (wajib start, waktu dipilih simulasi) |
| R3 | Tidak ada konsep SC | Coupling CC = daftar `d3[sX]['hrsg'/'gtg']`; G7/G10 SC hanya karena tidak ada di daftar mana pun | Kolom SC/CC (G1–G6, G8, G9). SC → unit dikeluarkan dari daftar feed STG (salinan konfigurasi disimpan), sequence family G7/G10 |
| R4 | Additional GTG start sebelum STG berbeban (Monitoring: G3/G1 09:00, G4 10:00, S1 baru 11:30) | Tidak ada aturan window additional terhadap STG first positive. `pp_start_sequence` hanya mengklasifikasi Additional bila STG row sebelumnya > 0; GTG kedua yang start saat STG masih hold dihitung sebagai "STG startup kedua" (sah menurut validator) | Validator 8f `block_sequence`: first load additional ≥ F + 2 − lead_in (lead_in = 1 marker + caps Additional per unit). Sequence lock R1 (tunda additional)/R2 (majukan lead). Klasifikasi additional berbasis anchor lead |
| R5 | Unit stop/SC tetap dihitung sebagai prioritas tinggi | Prioritas hanya statis (`unit_priority`) | Effective Priority per row (configured disimpan terpisah), notifikasi §3 |
| R6 | Hasil tergantung CPU | `pp_stage_admits` (decommit ditunda bila estimasi wall > sisa waktu; Monitoring: 16,8 s × 4 = 67,28 > 43,17) | Fastest: admission decommit berdasar JUMLAH core simulasi (`PP_FAST_DECOMMIT_SIMS`, default 12) |
| R7 | Audit headroom/fragmentasi menilai input mentah | `pp_attach_or_reject_acceptance` memakai input operator, bukan input efektif | Audit memakai `pp_normalize_copy` (semantik yang sama dengan engine) |
| R8 | Memo prioritas basi | `pp_priority_flat` memo dikunci pattern saja | Kunci memo = pattern + md5(source) |
| R9 | G8/G9 SC berbeban 65 MW min / 5 MW cap / satu row terlambat | `min_ccload` (65) dipakai sebagai lantai; caps fallback `[5]` dari `pp_startup_caps`/`pp_additional_hrsg_caps` | SC G8/G9: `min_ccload := min_scload` (30) pada salinan engine; caps SC = [] (marker 0 MW lalu bebas) |
| R10 | Start SC langsung tinggi melanggar ramp Export (row L tanpa tuas naik) | `pp_export_ramp_repair` hanya punya tuas berpasangan | Tuas (d) H-turun saja (gas hanya turun, export H ≥ Range Min, ramp sisi lain tidak memburuk) |
| R11 | Regresi CP D4/SR6 (+0,63) setelah semantik aktif | Semantik membangun ulang `unit_priority` dari salinan `configured_*` setiap dipanggil (menimpa urutan kandidat engine), dan stop seharian yang ditambahkan kandidat decommit dianggap "unavailable" sehingga unit dipindah ke akhir | Reorder diterapkan pada urutan saat ini; unavailable-seluruh-hari diambil SEKALI dari input operator (`v1518_unavailable_all_day`). D4 kembali 80,3478 |
| R12 | Additional kedua tanpa bukti + iterasi lock ganda | Lock R1 hanya menahan unit yang melanggar pada dispatch saat itu | R1 satu langkah: semua GTG non-lead ditahan ke row legal; additional kedua (urutan effective priority) baru start setelah additional pertama connect; bila varian itu melanggar → varian tanpa stagger + bukti `SECOND_ADDITIONAL_PROOF` |
| R13 | Kandidat decommit/review mahal yang pasti tidak valid (9–10 s/core) | Prasaring kapasitas Export menganggap unit dalam langkah startup tetap & STG yang masih hold dapat dinaikkan | Prasaring memakai batas fisik startup (langkah tetap) dan STG hold = 0 (bukti numerik tetap sah) |
| R14 | Hasil IE warm bergantung kecepatan CPU (78b72d5 juga: IE5 80,1881 vs 80,1822 pada input sama) | Review V8 jalur IE warm memakai sisa jam dinding (4,5/9 s − waktu berlalu) | Anggaran dalam jumlah kandidat (2 / 3), jam dinding hanya pengaman (`PP_IE_WARM_V8_WALLCLOCK=1` = perilaku lama) |

## 2. Jawaban audit file Monitoring (§8)

1. **G5 sebelum G6 (row 15 vs 16)**: G5 adalah Additional HRSG Block 2 (S2 sudah Running), G6 lead Block 1 fresh. Block berbeda, tidak ada aturan urutan lintas block; G5 dipilih engine untuk export/gas (fresh-start audit row 15: `ALLOW_NO_FULL_HORIZON_COUNTERFACTUAL_PROOF`, tanpa counterfactual). Di V15.18 additional Block 2 terhadap S2 Running legal; bukti merit C1–C4 PASS.
2. **G1 dan G3 sama-sama 09:00 (row 18)**: G1 = Additional Block 2 (sah). G3 = GTG kedua Block 1 saat S1 masih hold (S1 first 11:30 = row 23) → **tidak legal** menurut aturan §7.3 (earliest row 22). Di 78b72d5 lolos karena tidak ada validator window additional (R4).
3. **G4 10:00 sebelum S1 11:30**: sama dengan (2): row 20 < earliest 22 → ditolak validator 8f V15.18; sequence lock menunda additional ke row legal.
4. **Klasifikasi CC/SC**: semua GTG di file CC (tidak ada kolom SC/CC di versi lama). G6/G3/G4 berbeban saat S1 = 0 tampil oranye ("sc-mode" = GTG on & STG 0), bukan SC sungguhan. V15.18: oranye SC eksplisit dari `SC CC Mode` hasil.
5. **`ALLOW_NO_FULL_HORIZON_COUNTERFACTUAL_PROOF` terlalu longgar?** Ya: keputusan ALLOW tanpa counterfactual (proof null) untuk G5, G1, G3, G4. Di V15.18 kelonggaran ini ditutup oleh (a) validator 8f (sequence), (b) counterfactual additional kedua (`SECOND_ADDITIONAL_PROOF`), (c) V8 DECOMMIT-first untuk unit C2/C4 tanpa bukti. Label audit lama tidak dihapus (tidak menyembunyikan audit).
6. **Required sebagai pengecualian**: G6/G9 `EXEMPT_REQUIRED` — Required berarti wajib start, bukan bebas dari sequence. V15.18: Required tetap tunduk pada 8f, startup sequence, dan effective priority.
7. **V8/V11 polish mengubah startup setelah validasi**: Monitoring menerapkan `STOP:G4:20-32`, polish lanjutan, `V11_BAND_HEAT_RATE ... STOP:G3:18-33`. Setiap kandidat didaratkan penuh dan dinilai `pp_tl_assess` → `pp_validate_hard_constraints`; karena 8f kini bagian validator, kandidat V8/V11 yang melanggar sequence otomatis tidak valid.
8. **Proof binding mencakup SC/CC & milestone STG?** Tidak (v1517b: payload/candidate/commitment/fuel/C1–C4/STG). V15.18 `v1518-merit-bind-2` menambah `sc_cc_hash`, `effective_priority_hash`, `block_sequence_hash`, `commitment_semantics_version`, `commitment_semantics_hash`.

## 3. Release blocker (§9/§12) — status
Lihat TARGETED_TEST_STATUS.md (angka terukur). Ringkas: fungsi T0–T9 PASS; performa kasus sulit masih di atas 30 s
pada sebagian skenario (T0/T3/T6 ≈ 35–37 s CLI di mesin uji); T3 PGN−1 dedup dan cold IE sebelum Run pertama belum
dikerjakan; `global_optimum_proven=false` tetap dilaporkan apa adanya.
