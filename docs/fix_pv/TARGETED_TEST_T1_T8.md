# Targeted Test T1–T8 (termasuk T4A–T4D)

**Cakupan.** Hanya targeted test; full regression 400+ tidak dijalankan.

**Fixture.**
- `input_data.json`.
- `CSV Load Pred dan Dispatch (5 Oct).csv`, diimpor lewat UI tab IE Prediction & Dispatch: row 1 header, 48 row data, A Tanggal, B IE, C Dispatch, D PV.

**Source yang diuji.** 5 PHP dengan SHA-256 berikut, identik di semua lingkungan:

```
892f808aafea13df1fb84378f033de13c3ca1ab2e797238cb425545ce44ca47a  run.php
7f751f1296db6820f04b6ae0ffd027e026b760fcc7565b3b0b29539438d52804  worker02.php
4e4ed7a2b85d89de75e454b9bb344a929765b53075615430b641ce55603af563  worker_functions.php
599d51fe3b97954d21598a72c7b284c2769b3fa5ae7f46405b9baa4eba092cce  index.php
5636e58e5f1295da707c75189a9b8ececa12197ad28ed6678f828dec166acafc  saved_data_store.php
```

**Lingkungan.**
- **XAMPP-semantics:** server PHP 7.4 dengan 6 worker paralel.
- **Linux nyata:** Apache 2.4 + PHP-FPM 7.4 (www-data), app di `/var/www/html/php74/opr-simulation`.
- **Browser:** Chromium headless (Playwright) menjalankan UI asli. Harness: `tools/pv_ui.js`, `tools/run_matrix.sh`.

## Ringkasan

| Tes | Isi | XAMPP | Linux |
|---|---|---|---|
| T1 | Fix SR 10 MW, Follow PV OFF: 48 row effective SR = 10, tidak ada row di bawah batas | PASS | PASS |
| T2 | Follow PV, floor 2 MW: PV 0,22 → 2; PV 24,15 → 24,15; 48 row = `max(fix, PV)` | PASS | PASS |
| T3 | Import CSV: row 1 dilewati, tepat 48 row, A–C → IE/Dispatch, D → PV; edit PV 20:00 = 25,5 bertahan setelah Save/Reload | PASS | PASS |
| T4 | Apply: popup hanya setelah sertifikat 8 syarat; hanya row sumber + recipient yang berubah; PGN ≥ minimum | PASS | PASS |
| T4A | 36,00 → 34,82; total harian identik; proporsional; hard PASS; rerun 1× | PASS | PASS |
| T4B | Tidak menumpuk di satu row (47 recipient, porsi terbesar 3,27%) | PASS | PASS |
| T4C | Kapasitas tidak cukup → `PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE`, tanpa popup, Fixed Flow tidak berubah, 0 polling | PASS | PASS |
| T4D | Save/Reload: 48 Fixed Flow + audit before/after utuh | PASS | PASS |
| T5 | Keep → `PGN_MIN_FLOW_NOT_FEASIBLE_WITH_CURRENT_FIXED_FLOW`, terminal 0,36 s, 0 request sesudahnya | PASS | PASS |
| T6 | Startup + shortage: required start dipertahankan; hard, merit, STG, Bus, Export, ramp, runtime, provenance, fuel valid; FINAL OPTIMAL | PASS | PASS |
| T7 | Parity XAMPP vs Linux: SHA source, payload popup/redistribusi, hasil, status | identik | identik |
| T8 | U31 merit, Gas Shortage normal, Distillate, CO Block 1-2, Save/Reload, Report | PASS (= baseline) | – |

Matriks UI: case A–E masing-masing ALL PASS di kedua platform (`reports/xampp_matrix.log`, `reports/linux_matrix.log`).

## Rincian

### Case A — T4A, T4B, T4D, T6 (SR fix 0, Apply)

- **Popup:** muncul 2,1–2,8 s setelah Run. Teks bahasa Inggris memuat "redistributed proportionally … total daily Fixed Flow JBBK quota remains unchanged" dan "Daily quota before/after".
- **Row sumber:** row 1 (00:30), 36,00 → 34,82 MMSCFD. Pengurangan 1,18 MMSCFD ≈ 0,024583 MMSCF.
- **Redistribusi ke 47 recipient:**
  - rasio tambahan/kapasitas aman 0,000754–0,000761 (seragam; pembulatan 0,0001);
  - porsi terbesar row 30 (15:00) 3,27%, tambahan 0,0386.
- **Total harian:** Σ48 row 1728,0000 → 1728,0000; harian 36,0000 → 36,0000 MMSCFD; selisih 0 MMSCF (toleransi 1e-6).
- **Engine = audit:** Fixed Flow yang dipakai engine sama dengan audit, misalnya row 2/3/48 = 36,0083 / 36,0075 / 36,0235.
- **Rerun:** tepat satu kali (`runs_total` 2 = run awal + rerun); tidak ada popup kedua.
- **PGN row 1:** 3,0072 ≥ 3,00.
- **Hasil:** FINAL OPTIMAL, release gate PASS, hard PASS, merit PASS (C4 0), provenance PASS, fuel `FUEL_ACTION_APPLIED` (Distillate 118.946 l). CP 83,8124 USD/MWh.
- **Required start tetap:** G8 row 2, S1 row 18, G4 row 20.
- **Save/Reload:** 48 manual Fixed Flow (Σ 1728) dan audit (`source_rows`, `recipient_rows`, `fixed_flow_before/after[48]`, `redistributed_volume`, `daily_total_before/after`, `constraint_validation`, `user_decision`) utuh.
- **Waktu rerun:** 237 s di XAMPP, 233 s di Linux. Rute exact: kandidat Fastest pertama gagal merit dan dilanjutkan ke perhitungan exact.

### Case B — T1 (Fix SR 10)

- 48 row SR_Min = 10; engine memakai array yang sama; reserve terpenuhi di semua row.
- Rekomendasi 32,57 (pencarian sertifikat menurunkan sampai row 1 bersih dari pelanggaran reserve). Redistribusi proporsional, total harian identik.
- Hard PASS.
- **Catatan jujur:** release gate menahan hasil sebagai VALID PROVISIONAL karena merit C4 (30 temuan). Ini sudah ada sebelumnya: source sebelum pekerjaan ini (bsfreeze) dengan input yang sama juga gagal C4 (37 temuan). Gerbang tidak dipaksa.

### Case C — T2 / T3 (Follow PV, floor 2)

- Row malam: PV 0,22 → SR 2. Row siang: PV 24,15 → 24,15. PV 20:00 diedit 25,5 → SR 25,5.
- Seluruh 48 row = `max(2, PV)`.
- Save/Reload: `sr_mode = follow_pv`, `sr_fixed_mw = 2`, `pv_rows[48]`, `sr_effective_rows[48]`.
- Rekomendasi 34,32: 34,82 dan 34,57 masih melanggar reserve row 1. Total harian identik.
- Hard PASS. Merit C4 sama seperti case B (sudah ada sebelumnya, tidak dipaksa).

### Case D — T5 (Keep Current Input)

- Terminal `PGN_MIN_FLOW_NOT_FEASIBLE_WITH_CURRENT_FIXED_FLOW` 0,36 s setelah klik.
- 0 request run/poll setelah 6 s. Manual Fixed Flow tetap kosong.

### Case E — Report

- Apply, lalu Save to Report (Planning + Monitoring).
- Snapshot plan dan monitoring menyimpan `sr_mode`, PV 48, effective SR 48, dan audit redistribusi.
- Hasil FINAL OPTIMAL, merit PASS, CP 83,8092.

### T4C — kapasitas redistribusi tidak cukup

- **Backend (`tools/t4c.php`):**
  - profil recipient = Min PGN Flow + 0,12;
  - volume 1,18; kapasitas aman 0,9052; defisit 0,2748;
  - 47 recipient dievaluasi; pembatas `pgn_rt_min`;
  - tidak ada `fixed_flow_after`.
- **UI (`tools/t4c_ui.js`):** pesan terminal dengan semua angka di atas; tanpa popup Apply; Fixed Flow 0 → 0; audit tidak ditulis; 0 polling.

### T7 — parity

- SHA 5 PHP identik di XAMPP-semantics dan Linux.
- Payload popup (termasuk 48 `fixed_flow_after`, recipient, weight, dan total) identik byte-per-byte untuk case A–E.
- Ringkasan hasil (dispatch, CP, merit, gate, provenance, fuel, Fixed Flow 48, SR) identik. Satu-satunya selisih: timestamp `applied_at` pada audit.

### T8 — regresi terarah (CLI replay, source ini vs source sebelumnya bsfreeze)

| Kasus | Source ini | Sebelumnya | Hasil |
|---|---|---|---|
| U31 merit | sig 7775db96324d, CP 64,7205, HR 8375,02, merit PASS, gate PASS | identik | sama (CP/HR = acuan U31) |
| Gas Shortage normal (PGN25/PEP30/KP0) | `USER_FUEL_DECISION_REQUIRED`, sig 3af28c1d0f45 | identik | sama |
| Distillate cold | sig 7009a446c9d1, CP 77,8572, PASS | identik | sama |
| Distillate warm (sesudah GS, memo basis) | sig 3af28c1d0f45, CP 77,8218, `V7_FUEL_RERUN_DELTA` | identik | sama |
| CO Block 1-2 (WB09_3) | sig e15860b82ee7, PASS | identik | sama (= acuan) |
| Save/Reload | case A/C (T3, T4D) | – | PASS |
| Report Actual/Planning | case E | – | PASS |

Distillate cold dan warm memang berbeda, tetapi itu perilaku lama: rute delta hanya dipakai bila memo basis Gas Shortage tersedia. Source ini dan source sebelumnya menghasilkan nilai identik untuk masing-masing kondisi.

## Perubahan merit C4 yang ikut diuji

Lihat ROOT_CAUSE_PGN_MIN_FLOW.md §5. Fallback per row dengan bukti counterfactual hanya aktif bila gabungan pergeseran C4 tidak valid. U31, GS, Distillate, dan CO menghasilkan dispatch identik dengan source sebelumnya.
