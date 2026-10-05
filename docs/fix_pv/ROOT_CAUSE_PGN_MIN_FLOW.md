# Root cause — PGN Minimum Flow & rekomendasi Fixed Flow JBBK (dengan redistribusi)

Fixture: `input_data.json` (5-Oct-26; required G4/G8/S1 Start Based On Simulation, Last Data Stop; G1/G9 Running; PGN 24, LNG 8, PEP 32, Akasia 4; `use_distillate`) dan `CSV Load Pred dan Dispatch (5 Oct).csv`.

## 1. Mengapa PGN minimum flow tidak mungkin dipenuhi

Row 1 (00:30) hanya dapat dibebani unit yang Running pada Last Data (G1, G9), karena aturan hard `last_data_status` membuat startup paling awal berbeban di row 2. Pada beban maksimum efektif G1 31 MW + G9 108 MW:

```
FLOW PGN RT = (24 × Σ calc_fuel − FixedFlow_J × GHV_J/1000) / GHV_PGN × 1000
            = (24 × 1,69721 − 36,00 × 1080/1000) / 1040 × 1000 = 1,782 MMSCFD  <  Min PGN Flow 3,00
```

Rumus ini sama dengan validator `pgn_rt_min`. Distillate mengurangi gas sehingga tidak dapat menaikkan flow PGN. Satu-satunya input yang dapat mengubah hasil adalah Fixed Flow JBBK pada row tersebut.

Kedelapan syarat popup dibuktikan oleh sertifikat (`pgn_recommendation.conditions`):
1. PGN di bawah minimum.
2. Required block dipertahankan.
3. Setiap required block gas punya unit running: G9+G8+S3 (G9), G1+G5+G2+S2 (G1).
4. Unit running required tidak legal dimatikan (cannot-stop).
5. Tidak ada startup legal di row itu.
6. Unit running sudah di beban maksimum dan masih kurang.
7. Distillate tidak membantu.
8. Penyebabnya Fixed Flow JBBK.

Popup **tidak** pernah dibangun dari kegagalan kandidat. Sertifikat dihitung sebelum pipeline (±1,6–3 s).

## 2. Kesalahan engine yang ditemukan dan diperbaiki

- **Clamp kuota memutus ramp G9 (`worker_functions.php`, "mini quota clamp" pasca FIXED RE-ASSERT).**
  - Pass ini menurunkan G9/G8 sebesar 0,4 MW per langkah pada row yang flow PGN-nya surplus, tanpa cek ramp unit. Row 2 G9 jatuh ke 65 MW sementara row 1 diangkat lantai PGN ke 108 MW → `unit_ramp` 38–42 MW.
  - Terlacak dengan probe per statement. Kini langkah yang memutus ramp 30 MW ditolak.
  - Sesudahnya, satu-satunya pelanggaran input asli adalah `pgn_rt_min` row 1 pada 1,782 MMSCFD, tepat sama dengan batas sertifikat.
- **Nilai rekomendasi harus memenuhi seluruh constraint row sumber, bukan hanya PGN.**
  - Dengan Spinning Reserve > 0 di row 1, beban maksimum menghabiskan headroom reserve.
  - Pencarian kini menurunkan Fixed Flow per 0,25 MMSCFD sampai core run engine bersih dari seluruh pelanggaran yang menyebut row sumber.
  - Contoh: SR 0 → 34,82; Follow PV (floor 2 MW) → 34,32 (34,82 dan 34,57 masih melanggar `spinning_reserve` row 1).

## 3. Redistribusi — kuota harian tetap (`pp_bs_ff_redistribute`, run.php)

1. **Row sumber:** Fixed Flow diturunkan ke nilai rekomendasi terverifikasi. `reduction_mmscfd` dan `reduction_volume_mmscf` (= MMSCFD × 0,5/24) dicatat.
2. **Recipient:** semua row non-sumber dan non-actual. Kapasitas aman:
   ```
   kapasitas[row] = max(0, FLOW_PGN_RT[row] − Min PGN Flow − margin) × GHV_PGN/GHV_J
   ```
   FLOW dibaca dari core run engine dengan row sumber sudah dikurangi; margin 0,10 MMSCFD (0,50 bila verifikasi pertama gagal).
3. **Proporsional:** alokasi = total × kapasitas/Σkapasitas, dibulatkan ke 0,0001 MMSCFD (floor), lalu sisa dibagikan +0,0001 per row menurut pecahan sisa terbesar (Hamilton; tie → headroom terbesar → row terkecil). Hasilnya deterministik dan tidak pernah menumpuk di satu row.
4. **Verifikasi:** satu core run dengan ke-48 nilai, divalidasi `pp_validate_hard_constraints`. Syaratnya: tidak ada pelanggaran **baru** dibanding run sumber-saja, dan `pgn_rt_min` bersih. Rerun penuh sesudah Apply menilai ulang seluruh hard constraint dan gerbang rilis.
5. **Kapasitas < kebutuhan, atau verifikasi gagal:** keputusan terminal `PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE`, memuat volume, kapasitas, defisit, row sumber, jumlah recipient yang dievaluasi, dan pembatas utama. Tidak ada nilai yang diterapkan dan kuota tidak dikurangi.

Hasil pada fixture (SR 0): pengurangan 1,18 MMSCFD di 00:30 dipindahkan ke 47 recipient. Tambahan per row 0,0075–0,0410 MMSCFD, porsi terbesar 3,5% (row 15:00). Total harian 36,0000 → 36,0000 MMSCFD (selisih 0 MMSCF, toleransi 1e-6). Verifikasi core run PASS, flow PGN row 1 = 3,007 MMSCFD.

## 4. Popup dan aksi

**Popup** "Low PGN Flow Recommendation" (bahasa Inggris) memuat:
- periode, Current/Recommended Fixed Flow, Temporary reduction;
- Daily quota before/after (nilai sama);
- preview redistribusi: row terdampak, volume, jumlah recipient, rentang tambahan, total sebelum/sesudah, PGN minimum sebelum/sesudah, status validasi;
- detail per row yang dapat dibuka.

**Apply Recommendation:**
- 48 nilai `fixed_flow_after` diterapkan sebagai Manual Fixed Flow JBBK (hanya row sumber dan recipient);
- audit `pgn_fixed_flow_recommendation_applied` disimpan: `source_rows`, `recipient_rows`, `fixed_flow_before[48]`, `fixed_flow_after[48]`, `redistributed_volume`, `daily_total_before/after`, `constraint_validation`, `user_decision`;
- rerun **satu kali** (`_run_source = pgn_recommendation_apply`, popup tidak dibuka lagi).

**Keep Current Input:** input tidak diubah; terminal `PGN_MIN_FLOW_NOT_FEASIBLE_WITH_CURRENT_FIXED_FLOW`; tidak ada polling.

## 5. Merit C4 sesudah Apply (bukti langsung pada fixture ini)

**Gejala.** Pada case A (SR 0, Apply 34,82), hasil rerun valid secara hard constraint tetapi tertahan di gerbang merit: `C4_LINTAS_GRUP_TANPA_ALASAN`, 8 temuan. Ke-8 temuan itu adalah G2 (grup 3) di atas minimum pada row 17, 23, 24, 32–36, sementara G8/G9 masih memiliki headroom 2–7 MW. Status VALID PROVISIONAL.

**Akar masalah.** `pp_v12_c4_redistribute` memindahkan seluruh beban G2 ke G8/G9 pada 8 row **sekaligus**. Kombinasinya tidak valid (`EXPORT`, `GAS_WINDOW`), sehingga semuanya ditolak.

Uji counterfactual engine per row (pp_v10_fz, 48 row):
- **Masing-masing row valid bila dipindah sendiri**, misalnya row 17 (6 MW) dan row 23 (5,34 MW).
- **Gabungannya melanggar window gas.** G8/G9 lebih efisien, sehingga pemakaian gas turun dari 70,8496 ke 70,8378 BBTUD. Angka ini di bawah window target kuota 70,88 BBTUD, sedangkan Export sudah di batas atas sehingga tidak bisa menyerap gas sisa.

Jadi sebagian headroom G8/G9 **tidak legal** pada dispatch ini. Prinsipnya sama dengan pengecualian C4 yang sudah ada untuk akun MM2100 yang wajib terpakai penuh.

**Perbaikan (sempit).**
- `pp_v12_c4_rowwise` (run.php) hanya dipanggil bila gabungan pergeseran C4 tidak valid. State yang sebelumnya lolos C4 tidak tersentuh.
- **Pergeseran yang diterima:** per row, jumlah terbesar yang valid penuh secara langsung (resolusi 0,5 MW, commitment tetap, row lain tidak disentuh).
- **Bukti counterfactual:** untuk sisa pergeseran, tambahan ≤ 0,5 MW diuji ulang pada dispatch akhir, langsung **dan** dengan pendaratan window gas. Bukti dicatat (`V12 C4 Counterfactual Proof`, terkunci `rows_sig`) hanya bila keduanya tidak valid **dan** benar-benar dievaluasi; evaluasi yang gagal atau timeout tidak dihitung sebagai bukti.
- **Audit merit C4:** temuan dengan bukti yang cocok dengan dispatch dihitung `counterfactual_infeasible`. Temuan tanpa bukti tetap FAIL.
- Hard validator, C2, dan C3 tidak berubah.

**Hasil pada fixture.**
- 9,114 MW digeser: row 17 penuh; row 23, 32, 35 sebagian.
- 7 bukti `GAS_WINDOW`.
- C4 8 → 0 tanpa alasan; merit PASS; C2 0; C3 0.
- CP 83,8908 → 83,8904 USD/MWh; Heat Rate 8492,95 → 8491,80 BTU/kWh.
- Waktu 16 s.

## 6. Yang tidak diubah

Merit dispatch (selain §5), Unit Priority, C1–C3, STG proof, Change Over, alokasi Distillate, dan datastore tidak diubah. Hard validator tidak dilemahkan.
