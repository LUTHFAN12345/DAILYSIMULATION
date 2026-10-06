# C4 Targeted Audit — T1 (Fix SR 10) dan T2 (Follow PV)

**Source:** freeze final (SHA di SHA256SUMS.txt).
**Data:** replay Fastest (`tools/fast_replay.py`, payload `fixtures/pl/T1_corr.json` / `T2_corr.json`) di Linux Apache + PHP-FPM. Hasilnya identik dengan run UI case C (T1) dan D (T2) di XAMPP dan Linux (TARGETED_TEST_A_H.md).
**Laporan mentah:** `reports/c4/T1_linux_f2.json`, `reports/c4/T2_linux_f2.json`. Tabel dibuat oleh `tools/c4_table.py`.

## 1. Aturan C4 (validator tidak diubah)

`pp_v12_merit_audit` → `c4_cross_group_priority`. Temuan C4 terjadi bila unit GTG/GE berprioritas lebih rendah dibebani di atas minimum, sementara unit berprioritas lebih tinggi yang berjalan pada row yang sama masih punya legal headroom > 0,5 MW.

Temuan dihitung sah hanya bila salah satu syarat ini terpenuhi:
- unit rendah berstatus paksa (`status_forced`);
- unit GE/G10 pada akun MM2100 yang kuotanya terpakai penuh;
- ada bukti counterfactual engine untuk row#unit itu, dengan `rows_sig` yang **identik** dengan dispatch GTG yang diaudit (`counterfactual_infeasible`, ditampilkan sebagai PASS_WITH_REASON).

Selain itu temuan berstatus **FAIL** dan memblokir FINAL/FASTEST. Ambang 0,5 MW, kategori, dan syarat `rows_sig` tidak diubah. Ronde ini tidak menambah pengecualian baru.

## 2. Akar masalah T1/T2 VALID PROVISIONAL di source lama (72168ee)

| | T1 (Fix SR 10) | T2 (Follow PV) |
|---|---|---|
| C4 FAIL (source lama) | 30 | 29 |
| Merit | FAIL | FAIL |
| Gerbang | `V12_MERIT_DISPATCH_TANPA_BUKTI` → "Hasil BELUM final" (VALID PROVISIONAL) | sama |
| CP (lama) | 90,6625 | 90,7767 |

Redistribusi C4 mengevaluasi setiap pergeseran dengan `pp_v10_fz` dalam mode bebas. Mode ini mendispatch ulang Babelan (BB1/BB2). Karena itu, bahkan dispatch kandidat **sendiri** (tanpa pergeseran apa pun) dinilai tidak valid (EXPORT, RAMP).

Akibatnya setiap pergeseran legal ditolak, dan bukti counterfactual tidak dapat dibuat karena baseline evaluasinya sendiri tidak valid. Seluruh temuan tetap FAIL. Ini **false positive**: validatornya benar, tetapi alat evaluasinya tidak mereproduksi kandidat.

## 3. Perbaikan (run.php)

1. **Mode evaluasi** (`pp_v12_c4_redistribute`).
   - Mula-mula diuji apakah evaluasi bebas mereproduksi dispatch kandidat secara valid (`FREE`).
   - Bila tidak, Babelan dikunci seperti pada kandidat (`BB_FIXED_AS_CANDIDATE`), sehingga pergeseran C4 hanya terjadi antar GTG. Alasannya dicatat di `evaluation_mode_reason`.
   - Bila kedua mode tidak mereproduksi kandidat (`NOT_REPRODUCIBLE`), tidak ada pergeseran maupun bukti, dan temuan tetap FAIL.
2. **Transfer legal dulu.**
   - Seluruh pergeseran (unit prioritas rendah → legal headroom unit prioritas lebih tinggi) mula-mula dievaluasi sekaligus. Bila hasilnya tidak valid, evaluasi dilakukan per row (`pp_v12_c4_rowwise`).
   - **Fase A:** setiap row dicari terhadap dispatch dasar yang sama dengan binary search (resolusi 0,5 MW, maks. 6 iterasi; `pp_v12_c4_row_search`). Pencarian dikerjakan paralel oleh pembantu job (tugas samping `c4row`).
   - **Fase B:** seluruh hasil fase A digabung dan dievaluasi ulang penuh. Bila gabungan tidak valid, pencarian diulang berurutan pada dispatch terakumulasi.
3. **Bukti untuk sisa yang tidak dapat dipindah.**
   - Untuk setiap row yang pergeserannya tidak diterima penuh, langkah tambahan berikutnya (≤ 0,5 MW) dievaluasi engine 48 row secara langsung, lalu dengan pendaratan window gas.
   - Bila **keduanya** tidak valid, bukti PASS_WITH_REASON dicatat dengan dampak numerik: gas used vs window, serta Export vs range.
   - Bukti dikunci ke `rows_sig` dispatch akhir.
4. **Perbaikan teknis.**
   - Loop binary search kini dibatasi 6 iterasi; sebelumnya kondisinya tidak berubah sehingga loop tidak pernah berhenti.
   - Bukti hanya dicatat dari evaluasi yang benar-benar ada (bukan `EVALUASI_GAGAL`).
   - Job tugas samping dipertahankan (`ppV12C4Job`) walaupun global engine dibersihkan.

## 4. Hasil (freeze final)

| | T1 (Fix SR 10) | T2 (Follow PV floor 2) |
|---|---|---|
| Mode evaluasi | BB_FIXED_AS_CANDIDATE | BB_FIXED_AS_CANDIDATE |
| C4 tanpa alasan sebelum → sesudah redistribusi | 39 → **0** | 39 → **0** |
| Row dievaluasi | 25 | 25 |
| Transfer legal diterapkan | 11 row, penuh | 11 row, penuh |
| Row dengan transfer sebagian / nol + bukti | 14 row (17 temuan row#unit) | 14 row (17 temuan) |
| Total MW digeser | 162,990 MW | 162,990 MW |
| Evaluasi engine 48 row | 123 (cari 8,2 s, bukti 3,8 s) | 123 (cari 8,7 s, bukti 3,7 s) |
| Audit merit akhir | **PASS** (C2 0, C3 0) | **PASS** |
| C4 findings akhir | 22: status paksa 5, PASS_WITH_REASON 17, MM2100 penuh 0, **FAIL 0** | sama |
| CP | 90,5991 → **89,9882** USD/MWh | 90,6662 → **90,0877** USD/MWh |
| Heat Rate | 8638,40 → 8615,85 BTU/kWh | 8637,54 → 8615,00 BTU/kWh |
| Label UI | FASTEST VALID PLAN (bukan VALID PROVISIONAL) | FASTEST VALID PLAN |
| Maximum Review (case CM/E) | FINAL OPTIMAL, gate PASS, CP 89,9882 | FINAL OPTIMAL, gate PASS, CP 90,0877 |

**Alasan PASS_WITH_REASON.** Seluruh 17 bukti bernilai `INVALID:EXPORT`, baik langsung maupun dengan pendaratan window gas. Tambahan ≤ 0,5 MW dari G5/G2 ke G9/G8 menurunkan Export PLN di bawah batas bawah range row itu, misalnya:
- row 18: 30,11 → 29,92 MW < 30 MW;
- row 34: 15,11 → 14,98 MW < 15 MW.

Gas used tetap di dalam window (70,84–70,88 BBTUD), sehingga pembatasnya adalah Export, bukan gas. Headroom unit prioritas lebih tinggi tampak ada, tetapi tidak legal untuk tambahan tersebut.

Contoh bukti (T1, row 18):

```
tambahan 0.500 MW G5 -> G9 pada row 18 tidak valid langsung (EXPORT) maupun dengan pendaratan window gas (EXPORT);
gas 70.8626 -> 70.8625 BBTUD, window [70.8400, 70.8800]; Export 30.11 -> 29.92 MW, range [30, 155]
```

## 5. Detail per temuan

### T1 — Fix SR 10 MW

- CP sebelum → sesudah: 90.5991 → 89.9882 USD/MWh; Heat Rate 8638.4 → 8615.85 BTU/kWh
- Temuan C4 tanpa alasan sebelum: 39; sesudah: 0; mode evaluasi: BB_FIXED_AS_CANDIDATE (evaluasi bebas dispatch kandidat sendiri tidak valid (EXPORT,RAMP: Babelan didispatch ulang berbeda); Babelan dikunci seperti kandidat sehingga dispatch kandidat tereproduksi valid)
- Audit merit akhir: PASS · C4 findings 22, status paksa 5, akun MM2100 penuh 0, PASS_WITH_REASON (bukti counterfactual) 17, FAIL 0
- Evaluasi: 123 evaluasi engine 48 row, fase INDEPENDENT_ROWS, pencarian 8.181 s, bukti 3.783 s · 25 row dievaluasi, 162.990 MW digeser, 17 bukti counterfactual (BB_FIXED_AS_CANDIDATE)

| Row | Time | Donor | Receiver (rencana MW) | Legal headroom receiver (MW) | Δ diterapkan (MW) | Δ tambahan diuji (MW) | Langsung | Dengan pendaratan window gas | Gas used (BBTUD) sebelum→sesudah, window | Export (MW) sebelum→sesudah, range | Hasil |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 17 | 08:30 | G5 | G9 9 | G8 23.21, G9 23.21 | 9 / 9 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 18 | 09:00 | G5 | G9 11 | G8 21.78, G9 21.78 | 0 / 11 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8625, [70.84, 70.88] | 30.11→29.92, [30, 155] | PASS_WITH_REASON |
| 19 | 09:30 | G2/G5 | G9 1, G9 11 | G8 18.26, G9 18.26 | 1.9375 / 12 | 0.3594 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8624, [70.84, 70.88] | 30.12→29.98, [30, 155] | PASS_WITH_REASON |
| 20 | 10:00 | G2/G5 | G9 1, G9 11 | G8 18.14, G9 18.14 | 1.9375 / 12 | 0.3594 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8624, [70.84, 70.88] | 30.13→29.99, [30, 155] | PASS_WITH_REASON |
| 21 | 10:30 | G2/G5 | G9 6.32, G8 2.68, G8 3.64 | G8 6.32, G9 6.32 | 10.7432 / 12.64 | 0.3793 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8621, [70.84, 70.88] | 30.09→29.95, [30, 155] | PASS_WITH_REASON |
| 22 | 11:00 | G2 | G9 5.22, G8 4.72 | G8 4.72, G9 5.22 | 9.94 / 9.94 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 23 | 11:30 | G3/G2/G5 | G9 4, G9 5.97, G8 5.03, G8 4.94 | G8 9.97, G9 9.97 | 19.94 / 19.94 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 24 | 12:00 | G5 | G9 11 | G8 13, G9 13.5 | 8.375 / 11 | 0.3282 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8621, [70.84, 70.88] | 30.12→29.99, [30, 155] | PASS_WITH_REASON |
| 26 | 13:00 | G5 | G9 11 | G8 16.61, G9 16.61 | 0 / 11 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8625, [70.84, 70.88] | 30.1→29.91, [30, 155] | PASS_WITH_REASON |
| 27 | 13:30 | G2/G5 | G9 6.16, G8 4.84, G8 1.32 | G8 6.16, G9 6.16 | 12.32 / 12.32 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 28 | 14:00 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 29 | 14:30 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 30 | 15:00 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 31 | 15:30 | G3/G2 | G9 1.5, G9 3.82, G8 4.92 | G8 4.92, G9 5.32 | 10.24 / 10.24 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 32 | 16:00 | G3/G2/G5 | G9 4.5, G9 5.95, G8 5.05, G8 5.4 | G8 10.45, G9 10.45 | 20.9 / 20.9 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 33 | 16:30 | G5 | G9 11 | G8 13, G9 13 | 11 / 11 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 34 | 17:00 | G5 | G9 11 | G8 11.48, G9 11.48 | 1.1563 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8625, [70.84, 70.88] | 15.11→14.98, [15, 155] | PASS_WITH_REASON |
| 35 | 17:30 | G5 | G9 9.19, G8 1.81 | G8 8.69, G9 9.19 | 10.3438 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.862, [70.84, 70.88] | 15.09→14.96, [15, 155] | PASS_WITH_REASON |
| 36 | 18:00 | G5 | G9 11 | G8 13, G9 13.5 | 9.0313 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8621, [70.84, 70.88] | 15.09→14.97, [15, 155] | PASS_WITH_REASON |
| 38 | 19:00 | G5 | G9 2 | G8 5.67, G9 5.67 | 1.25 / 2 | 0.375 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8619, [70.84, 70.88] | 15→14.84, [15, 155] | PASS_WITH_REASON |
| 39 | 19:30 | G5 | G9 7.57, G8 2.93 | G8 7.07, G9 7.57 | 10.5 / 10.5 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 40 | 20:00 | G5 | G9 1.5 | G8 5.8, G9 6.3 | 0 / 1.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8617, [70.84, 70.88] | 15.04→14.83, [15, 155] | PASS_WITH_REASON |
| 42 | 21:00 | G5 | G9 2.49, G8 1.01 | G8 1.99, G9 2.49 | 0.875 / 3.5 | 0.375 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.862, [70.84, 70.88] | 15.12→14.96, [15, 155] | PASS_WITH_REASON |
| 43 | 21:30 | G5 | G9 3.5, G8 1 | G8 3, G9 3.5 | 1 / 4.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8619, [70.84, 70.88] | 15.07→14.86, [15, 155] | PASS_WITH_REASON |
| 44 | 22:00 | G5 | G9 2.5 | G8 2.97, G9 2.97 | 0.5 / 2.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8626→70.8618, [70.84, 70.88] | 15.16→14.95, [15, 155] | PASS_WITH_REASON |


### T2 — Follow PV, floor 2 MW

- CP sebelum → sesudah: 90.6662 → 90.0877 USD/MWh; Heat Rate 8637.54 → 8615 BTU/kWh
- Temuan C4 tanpa alasan sebelum: 39; sesudah: 0; mode evaluasi: BB_FIXED_AS_CANDIDATE (evaluasi bebas dispatch kandidat sendiri tidak valid (EXPORT,RAMP: Babelan didispatch ulang berbeda); Babelan dikunci seperti kandidat sehingga dispatch kandidat tereproduksi valid)
- Audit merit akhir: PASS · C4 findings 22, status paksa 5, akun MM2100 penuh 0, PASS_WITH_REASON (bukti counterfactual) 17, FAIL 0
- Evaluasi: 123 evaluasi engine 48 row, fase INDEPENDENT_ROWS, pencarian 8.731 s, bukti 3.726 s · 25 row dievaluasi, 162.990 MW digeser, 17 bukti counterfactual (BB_FIXED_AS_CANDIDATE)

| Row | Time | Donor | Receiver (rencana MW) | Legal headroom receiver (MW) | Δ diterapkan (MW) | Δ tambahan diuji (MW) | Langsung | Dengan pendaratan window gas | Gas used (BBTUD) sebelum→sesudah, window | Export (MW) sebelum→sesudah, range | Hasil |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 17 | 08:30 | G5 | G9 9 | G8 23.21, G9 23.21 | 9 / 9 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 18 | 09:00 | G5 | G9 11 | G8 21.78, G9 21.78 | 0 / 11 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8642, [70.84, 70.88] | 30.11→29.92, [30, 155] | PASS_WITH_REASON |
| 19 | 09:30 | G2/G5 | G9 1, G9 11 | G8 18.26, G9 18.26 | 1.9375 / 12 | 0.3594 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8641, [70.84, 70.88] | 30.12→29.98, [30, 155] | PASS_WITH_REASON |
| 20 | 10:00 | G2/G5 | G9 1, G9 11 | G8 18.14, G9 18.14 | 1.9375 / 12 | 0.3594 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8641, [70.84, 70.88] | 30.13→29.99, [30, 155] | PASS_WITH_REASON |
| 21 | 10:30 | G2/G5 | G9 6.32, G8 2.68, G8 3.64 | G8 6.32, G9 6.32 | 10.7432 / 12.64 | 0.3793 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8638, [70.84, 70.88] | 30.09→29.95, [30, 155] | PASS_WITH_REASON |
| 22 | 11:00 | G2 | G9 5.22, G8 4.72 | G8 4.72, G9 5.22 | 9.94 / 9.94 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 23 | 11:30 | G3/G2/G5 | G9 4, G9 5.97, G8 5.03, G8 4.94 | G8 9.97, G9 9.97 | 19.94 / 19.94 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 24 | 12:00 | G5 | G9 11 | G8 13, G9 13.5 | 8.375 / 11 | 0.3282 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8638, [70.84, 70.88] | 30.12→29.99, [30, 155] | PASS_WITH_REASON |
| 26 | 13:00 | G5 | G9 11 | G8 16.61, G9 16.61 | 0 / 11 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8642, [70.84, 70.88] | 30.1→29.91, [30, 155] | PASS_WITH_REASON |
| 27 | 13:30 | G2/G5 | G9 6.16, G8 4.84, G8 1.32 | G8 6.16, G9 6.16 | 12.32 / 12.32 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 28 | 14:00 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 29 | 14:30 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 30 | 15:00 | G2 | G9 2, G8 2 | G8 2, G9 2 | 4 / 4 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 31 | 15:30 | G3/G2 | G9 1.5, G9 3.82, G8 4.92 | G8 4.92, G9 5.32 | 10.24 / 10.24 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 32 | 16:00 | G3/G2/G5 | G9 4.5, G9 5.95, G8 5.05, G8 5.4 | G8 10.45, G9 10.45 | 20.9 / 20.9 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 33 | 16:30 | G5 | G9 11 | G8 13, G9 13 | 11 / 11 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 34 | 17:00 | G5 | G9 11 | G8 11.48, G9 11.48 | 1.1563 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8642, [70.84, 70.88] | 15.11→14.98, [15, 155] | PASS_WITH_REASON |
| 35 | 17:30 | G5 | G9 9.19, G8 1.81 | G8 8.69, G9 9.19 | 10.3438 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8637, [70.84, 70.88] | 15.09→14.96, [15, 155] | PASS_WITH_REASON |
| 36 | 18:00 | G5 | G9 11 | G8 13, G9 13.5 | 9.0313 / 11 | 0.3281 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8638, [70.84, 70.88] | 15.09→14.97, [15, 155] | PASS_WITH_REASON |
| 38 | 19:00 | G5 | G9 2 | G8 5.67, G9 5.67 | 1.25 / 2 | 0.375 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8636, [70.84, 70.88] | 15→14.84, [15, 155] | PASS_WITH_REASON |
| 39 | 19:30 | G5 | G9 7.57, G8 2.93 | G8 7.07, G9 7.57 | 10.5 / 10.5 | — | — | — | — | — | TRANSFER_LEGAL_APPLIED |
| 40 | 20:00 | G5 | G9 1.5 | G8 5.8, G9 6.3 | 0 / 1.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8635, [70.84, 70.88] | 15.04→14.83, [15, 155] | PASS_WITH_REASON |
| 42 | 21:00 | G5 | G9 2.49, G8 1.01 | G8 1.99, G9 2.49 | 0.875 / 3.5 | 0.375 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8638, [70.84, 70.88] | 15.12→14.96, [15, 155] | PASS_WITH_REASON |
| 43 | 21:30 | G5 | G9 3.5, G8 1 | G8 3, G9 3.5 | 1 / 4.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8636, [70.84, 70.88] | 15.07→14.86, [15, 155] | PASS_WITH_REASON |
| 44 | 22:00 | G5 | G9 2.5 | G8 2.97, G9 2.97 | 0.5 / 2.5 | 0.5 | INVALID:EXPORT | INVALID:EXPORT | 70.8643→70.8635, [70.84, 70.88] | 15.16→14.95, [15, 155] | PASS_WITH_REASON |


Kolom "Δ diterapkan" = MW yang dipindah / MW yang direncanakan. "Δ tambahan diuji" = langkah berikutnya yang dibuktikan tidak legal.
Row yang tidak tercantum tidak memiliki temuan C4, atau temuannya berstatus paksa (5 temuan, unit dengan status paksa / startup hold).

## 6. Kesimpulan

- C4 tidak dilemahkan. Ambang, kategori, dan syarat `rows_sig` sama; temuan tanpa bukti tetap FAIL.
- Transfer legal dilakukan: 11 row dipindah penuh, dan sebagian MW pada beberapa row lain yang sebagian legal.
- Setiap sisa transfer yang tidak dipindah punya bukti numerik engine 48 row (Export di bawah range).
- T1/T2 tidak lagi tertahan VALID PROVISIONAL: keduanya menjadi FASTEST VALID PLAN (Fastest) dan FINAL OPTIMAL gate PASS (Maximum Review), dengan hasil identik di XAMPP dan Linux.
