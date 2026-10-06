# Targeted test A–H — AUTO PGN REDISTRIBUTION + SR/PV + C4 + T6

**Cakupan.** Hanya targeted test, sesuai instruksi. Full regression 400+ tidak dijalankan.

## Source yang diuji

**Freeze final** — 5 PHP, identik di XAMPP dan Linux (diverifikasi `sha256sum` di folder aplikasi kedua lingkungan):

```
96837f622909d53c3dfc9815662637459e082dcac54520da1a390646610c05bd  index.php
c74ced7d67023cb6b916bd7211a5b1ec2f4610f785b907bed281023cfb7b8995  run.php
5636e58e5f1295da707c75189a9b8ececa12197ad28ed6678f828dec166acafc  saved_data_store.php
2892b9e548f9c42e2d58e0f36154d1d7c629e3bc5c96c37a939bdbc7d66903d9  worker02.php
4e4ed7a2b85d89de75e454b9bb344a929765b53075615430b641ce55603af563  worker_functions.php
```

**Freeze-1** berbeda hanya pada `run.php` (`6028223a…0dca`). Perbedaannya satu cabang di `pp_bs_stop_or_continuous`, yaitu fallback pipeline exact pada mode Fastest (lihat §5).

| Case | Dijalankan pada | Alasan |
|---|---|---|
| A, A2, C, D | freeze final, di kedua platform | A2 gagal parity pada freeze-1; A, C, D melewati jalur Fastest + Stop-or-Continuous yang sama |
| B, E, CM | freeze-1 | Tidak melewati cabang yang diubah: B berhenti terminal sebelum job Fastest; E dan CM memakai *Maximum Review* (Stop-or-Continuous mode `full`), sedangkan cabang yang diubah hanya aktif pada mode `fast` |

Sesuai instruksi, hanya case yang gagal dan smoke terkait yang diulang. A, C, D pada freeze final identik dengan freeze-1 di **semua** field, di kedua platform.

**Lint PHP 7.4:** `No syntax errors detected` untuk kelima berkas.

## Lingkungan

- **XAMPP-semantics:** server PHP 7.4 dengan 6 worker paralel (`node v3/proxy.js`, folder aplikasi biasa).
- **Linux nyata:** Apache 2.4 (mpm_event) + PHP-FPM 7.4, pool `www-data`, app di `/var/www/html/php74/opr-simulation` (`tools/setup_linux.sh`).
- **Browser:** Chromium headless (Playwright) menjalankan UI asli: import file, klik Run, membaca Simulation Data, Save, Reload, dan Report.
- **Harness:** `tools/ap_ui.js` dan `tools/run_matrix.sh`; parity dengan `tools/parity.py`; smoke H dengan `tools/h_smoke.sh`.

## 1. Ringkasan A–F (XAMPP dan Linux)

| Case | XAMPP | t XAMPP (s) | Linux | t Linux (s) | Label / keputusan | CP (USD/MWh) |
|---|---|---|---|---|---|---|
| A — SR 0, PGN 00:30 (T6) | PASS (26/26) | 39,007 | PASS (26/26) | 49,508 | FASTEST VALID PLAN | 90,0739 |
| A2 — constrained 00:30–02:00 | PASS (19/19) | 151,397 | PASS (19/19) | 114,518 | FASTEST VALID PLAN | 85,3183 |
| B — redistribusi tidak feasible | PASS (7/7) | 2,204 | PASS (7/7) | 2,236 | PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE (terminal) | — |
| C — T1 Fix SR 10 | PASS (24/24) | 50,375 | PASS (24/24) | 52,479 | FASTEST VALID PLAN | 89,9882 |
| D — T2/T3 Follow PV, grafik | PASS (28/28) | 49,253 | PASS (28/28) | 57,909 | FASTEST VALID PLAN | 90,0877 |
| E — XLSX + CSV lokal, Report (Maximum Review) | PASS (25/25) | 80,464 | PASS (25/25) | 82,815 | FINAL OPTIMAL (gate PASS) | 90,0877 |
| CM — T1 Maximum Review | PASS (20/20) | 78,995 | PASS (20/20) | 87,872 | FINAL OPTIMAL (gate PASS) | 89,9882 |

Semua check PASS di kedua platform. Waktu = klik Run sampai Simulation Data 48 row.

Grup instruksi dan case yang mencakupnya:

| Grup instruksi | Case / check |
|---|---|
| **A** Automatic PGN correction | A, A2, C, D, E, CM: `A_no_popup`, `A_auto_status_message`, `A_constrained_period`, `A_recipients_only_after_period`, `A_water_filling_equal`, `A_daily_total_identical`, `A_engine_fixed_flow_equals_audit`, `A_one_rerun_one_correction`, `A_pgn_min_pass`, `A_audit_fields`; B: `B_terminal_code`, `B_no_partial_apply`, `B_no_repeated_polling` |
| **B** Follow PV | C: `C_pv_column_absent_fixed_mode`; D/E: `D_pv_column_position_and_values`, `D_chart_visible_48_points_tooltip`, `D_chart_updates_after_manual_edit`, `D_negative_pv_rejected`, `D_chart_hidden_in_fix_mode_data_kept`, `CD_sr_table_48_rows_formula`, `CD_sr_array_used_by_engine_and_met`, `E_export_consistent_with_simulation_data`; import `E_import_*` |
| **C** Merit C4 | C, D, E, CM: `F_result_fully_valid` (C4 FAIL 0, PASS_WITH_REASON 17 dengan bukti), lihat C4_TARGETED_AUDIT.md |
| **D** Performa | A: `F_t6_click_to_48_rows_under_60s`, `F_fastest_stops_at_first_fully_valid`, lihat PERFORMANCE_T6.md |
| **E** Cross-platform | §3 parity, SHA, lint |
| **F** Fully valid | `F_result_fully_valid`, `F_required_startups_kept`, `F_fastest_stops_at_first_fully_valid` |

## 2. Koreksi PGN otomatis per case

| Case | Constrained period | Fixed Flow sumber (MMSCFD) | Recipient period | Tambahan per recipient (min/max) | Capped | Total harian sebelum → sesudah | PGN min sesudah |
|---|---|---|---|---|---|---|---|
| A (SR 0) | 00:30 (row 1) | 36,00 → 34,82 | 01:00–00:00 (47 row) | +0,0251 / +0,0252 | 0 | 36,0000 → 36,0000 MMSCF (Σ row 1728 = 1728), selisih 0 | 3,0072 |
| A2 (varian) | 00:30–02:00 (row 1–4) | 4 × (36,00 → 34,82) | 02:30–00:00 (44 row) | +0,1072 / +0,1073 | 0 | 36,0000 → 36,0000, selisih 0 | 3,0072 |
| C / CM (Fix SR 10) | 00:30 | 36,00 → 32,57 | 01:00–00:00 (47 row) | +0,0729 / +0,0730 | 0 | 36,0000 → 36,0000, selisih 0 | 3,0233 |
| D / E (Follow PV floor 2) | 00:30 | 36,00 → 34,32 | 01:00–00:00 (47 row) | +0,0357 / +0,0358 | 0 | 36,0000 → 36,0000, selisih 0 | 3,0127 |
| B (headroom recipient sempit) | 00:30–02:00 | — | — | — | — | tidak berubah, tanpa audit | terminal |

Ketentuan yang berlaku di semua case koreksi:
- **Tanpa popup.** Tidak ada Apply/Keep. Status bahasa Inggris tampil otomatis, 2,3–4,7 s sesudah klik Run (C/CM, Fix SR 10: 8,9 s).
- **Satu koreksi, satu rerun:** `runs_total = 2`, `jobs = 2`, `rerun_count = 1`.
- **Fixed Flow konsisten:** Fixed Flow engine pada Simulation Data sama dengan `fixed_flow_after` audit.
- **Audit lengkap:** `correction_mode = automatic`, `source_rows`, `recipient_rows`, `fixed_flow_before/after[48]`, `daily_total_before/after`, `pgn_flow_before/after`, `rerun_count`, `constraint_proof`.

**B (`PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE`):**
- keputusan terminal 2,2 s sesudah klik;
- Manual Fixed Flow tidak berubah dan tidak ada audit yang ditulis;
- 0 request sesudah 6 s, `runs_total = 1`;
- kebutuhan 4,72 MMSCFD-slot (0,098332 MMSCF), kapasitas aman 3,242, tidak teralokasi 1,478 (0,030792 MMSCF).

## 3. Parity XAMPP vs Linux (`tools/parity.py`)

Field yang dibandingkan:
- **Simulation Data:** Spinning Reserve, SR Minimum, PV, FLOW PGN RT, dan Fixed Flow JBBK per row;
- **ringkasan:** CP, HR, startup, Manual Fixed Flow 48;
- **audit koreksi:** seluruh field kecuali `applied_at`;
- **lain-lain:** panjang berkas export, token Save/Reload, preview import, dan evidence seluruh check.

```
A: IDENTIK · cp 90.0739 / 90.0739 · xls_len 41816 / 41816 · checks 26/26 vs 26/26
A2: IDENTIK · cp 85.3183 / 85.3183 · xls_len 41609 / 41609 · checks 19/19 vs 19/19
B: IDENTIK · cp 49.8002 / 49.8002 · xls_len 0 / 0 · checks 7/7 vs 7/7
C: IDENTIK · cp 89.9882 / 89.9882 · xls_len 42636 / 42636 · checks 24/24 vs 24/24
D: IDENTIK · cp 90.0877 / 90.0877 · xls_len 43321 / 43321 · checks 28/28 vs 28/28
E: IDENTIK · cp 90.0877 / 90.0877 · xls_len 43813 / 43813 · checks 25/25 vs 25/25
CM: IDENTIK · cp 89.9882 / 89.9882 · xls_len 43128 / 43128 · checks 20/20 vs 20/20
PARITY PASS
```

- **Dikecualikan:** hanya timestamp audit dan ukuran waktu (`t_*_s`, `finalize_s`, teks "Waktu aktual").
- **Counter UI "Kandidat diperiksa":** jumlah kandidat yang selesai sebelum klaim adalah statistik progres dan dapat berbeda antar-run (A2: 8 vs 10). Rencana yang dirilis identik.

## 4. Smoke H

| ID | Kasus | Freeze final | Baseline 72168ee | Hasil |
|---|---|---|---|---|
| H1 | U31 merit | sig 7775db96324d, CP 64,7205, HR 8375,02, hard PASS, gate PASS | identik | **PASS** |
| H2 | Gas Shortage normal (PGN25/PEP30/KP0) | `USER_FUEL_DECISION_REQUIRED`, sig 3af28c1d0f45, CP 61,747 | identik | **PASS** |
| H3 | Distillate cold | sig 7009a446c9d1, CP 77,8572, `FUEL_ACTION_APPLIED`, gate PASS | identik | **PASS** |
| H3 | Distillate warm (sesudah GS) | sig 3af28c1d0f45, CP 77,8218, `FUEL_ACTION_APPLIED`, gate PASS | identik | **PASS** |
| H4 | Change Over Block 1-2 (WB09_3) | sig e15860b82ee7, CP 63,8636, HR 8197,81, gate PASS | identik | **PASS** |
| H5 | Save/Reload | Lihat rincian di bawah tabel. | – | **PASS** (XAMPP + Linux) |
| H6 | Report Actual/Planning | Lihat rincian di bawah tabel. | – | **PASS** (XAMPP + Linux) |

**Catatan H1–H4.** Dijalankan dengan `tools/h_smoke.sh` (replay HTTP) pada freeze final. Laporannya ada di `reports/h/`.

**Rincian H5 (Save/Reload).** `E_save_reload_sr_pv` dan `E_save_reload_48_fixed_flow_and_audit` diuji di A, A2, C, D, dan E. Yang dipastikan bertahan:
- `sr_mode`, `sr_fixed_mw`, `pv_rows[48]`, dan `sr_effective_rows[48]`, termasuk PV row 20 yang diedit menjadi 25,5;
- 48 Manual Fixed Flow (Σ 1728);
- audit `rerun_count = 1`;
- catatan koreksi yang tampil kembali.

**Rincian H6 (Report Actual/Planning).** `E_report_planning_and_monitoring_keep_sr_pv_audit` (case E) memastikan:
- Report Planning dan Monitoring (actual) menyimpan `sr_mode = follow_pv`, 48 PV, 48 effective SR, dan audit `correction_mode = automatic`;
- output 48 PV dengan SR min maks 24,15.

**Desain lama tetap berlaku.** Save to Report untuk hasil *FASTEST VALID PLAN* terkunci; Report dibuat dari hasil final (Maximum Review / FINAL OPTIMAL). Run E awal dengan target Fastest menunjukkan penguncian ini (`reports/xampp/E_fastest_report_locked.json`), sehingga case E memakai target *Maximum Review*.

## 5. Kegagalan yang ditemukan dan diperbaiki pada ronde ini

| Temuan | Bukti | Akar masalah | Perbaikan | Verifikasi |
|---|---|---|---|---|
| XAMPP B: polling tertahan (`Perhitungan eksak sedang diselesaikan…`) | `reports/freeze0_xampp/` (matrix.log) | Worker tersita evaluasi family Stop-or-Continuous sisa run A2 yang sudah dibatalkan | `pp_soc_cancel_poll` + `ppSocCancelJob` (cek pembatalan tiap core run dan saat menunggu) | B PASS 2,2 s (XAMPP + Linux) |
| A2: CP berbeda XAMPP/Linux (84,9942 / 84,9175; Linux diulang 84,9156) | `reports/freeze1_xampp/`, `reports/freeze1_linux/`, `reports/a2inv/` | Pada Fastest, family alternatif G4 terpilih; versi review-nya tidak fully valid, lalu kode jatuh ke pipeline exact yang review-nya terpotong batas waktu (`BATAS_WAKTU_REVIEW`) | Fastest mempertahankan rencana utama yang fully valid; tidak ada pipeline exact sesudah first fully valid | A2 CP 85,3183 identik di kedua platform; A, C, D tidak berubah |

## 6. Keterbatasan

- **Fastest = kandidat fully valid pertama, bukan optimum global.** Pada case A, CP Fastest adalah 90,0739, sedangkan exact pada source lama memberi 83,8124 (rencana dengan Distillate 118.946 L). Gunakan *Maximum Review* bila diperlukan optimum. Pada T1/T2, Maximum Review = Fastest (89,9882 / 90,0877). Untuk case A, Maximum Review tidak diukur ulang.
- **A2 adalah varian berat.** A2 (unit lain tidak tersedia row 1–4, G8 Start at 02:00) membutuhkan 114–151 s karena review kandidat yang diklaim memakan ±77–90 s. T6 (case A) tetap < 60 s.
- **Batas pengaman review Fastest 90 s.** Pada mesin jauh lebih lambat, review dapat terpotong, dan hal itu tercatat di `V8 Priority Review.truncated`.
- **Tiga case di freeze-1.** B, E, dan CM dijalankan pada freeze-1. Selisihnya dengan freeze final hanya pada cabang mode `fast` yang tidak mereka lewati (lihat tabel di atas).
