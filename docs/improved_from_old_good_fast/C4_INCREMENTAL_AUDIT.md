# C4 — audit multi-lintasan, inkremental, dan cache bukti

## Alur (tidak berubah secara aturan)

1. `pp_v12_merit_audit` mengaudit 48 row.
   - Temuan C4: unit GTG/GE prioritas rendah di atas minimum, sementara unit grup prioritas lebih tinggi punya headroom legal > 0,5 MW.
   - Kategori: status-forced, akun MM2100 penuh, counterfactual infeasible (berbukti), atau FAIL.
   - Biaya audit ±0,005 s, sehingga audit ulang 48 row setiap lintasan tidak menjadi bottleneck. Yang mahal adalah evaluasi counterfactual, dan itu hanya dilakukan untuk row/unit yang punya temuan.
2. **Pencarian per row** (row saling bebas pada dispatch dasar yang sama): x = min(M; 0,5) → M → bisection sampai resolusi 0,5 MW (maks. 6 langkah).
   - **Baru:** dijalankan bergiliran untuk semua row. Setiap putaran, satu langkah tiap row aktif diterbitkan sekaligus ke pembantu, lalu pemilik mengambil hasil berkunci yang sama.
3. **Gabungan** hasil row → evaluasi penuh 48 row (+ pendaratan window gas bila perlu).
4. **Transfer legal sisa** pada dispatch akhir.
   - Maks. 8 lintasan; berhenti bila tidak ada MW legal baru yang berpindah.
   - **Baru:** setiap lintasan menguji cepat langkah pertama seluruh temuan sekaligus (paralel). Hanya temuan yang langkah pertamanya VALID yang dicari penuh. Bila dispatch berubah di tengah lintasan, langkah pertama temuan berikutnya diuji ulang terhadap dispatch baru.
   - Temuan yang gagal diuji lagi pada lintasan berikutnya hanya bila dispatch berubah (tidak ada pergerakan → berhenti).
5. **Bukti temuan sisa** (tambahan ≤ 0,5 MW unit itu ke unit prioritas lebih tinggi, langsung dan dengan pendaratan).
   - **Baru:** seluruh temuan diterbitkan sekaligus.
   - Bila kedua uji tidak valid, temuan menjadi PASS_WITH_REASON (bukti numerik). Bila salah satu valid, temuan tetap FAIL.

## Kunci cache bukti

- Setiap evaluasi counterfactual memakai `pp_v10_fz`, dengan kunci `vz` = md5(`pp_cs_key(input tersusun)` + `pp_tl_key(orig)` + mode target).
  - **Input tersusun** memuat seluruh dispatch kandidat 48 row sesudah pergeseran (row, donor, penerima, delta tercermin di `unit_fix_load` per row).
  - **orig** memuat konteks constraint lengkap: gas_quota, Min PGN, KP72, MM2100, SR per row (Fix / Follow PV = max(Fix SR, PV[row])), pv_rows, Fixed Flow, stop/required mode.
  - Jadi kuncinya setara dengan *candidate_hash + row + donor + receiver + delta + constraint_context_hash*.
- Bukti C4 di output (`V12 C4 Counterfactual Proof`) membawa `rows_sig`. Audit hanya memakai bukti bila `rows_sig` sama dengan dispatch yang diaudit, sehingga bukti dispatch lama tidak berlaku pada dispatch baru.
- Follow PV mengubah `orig` (SR per row), sehingga kunci berbeda dari Follow PV OFF. Tidak ada pemakaian silang.

## Hasil

| Kasus | C4 sebelum → sesudah | Row dievaluasi | Evaluasi row | MW legal dipindah | Bukti | Dinding C4 |
|---|---|---|---|---|---|---|
| R1 PV OFF (XAMPP) | 21 → 0 | 14 | 38 | 208,25 (13 row TRANSFER_LEGAL_APPLIED) | 1 PASS_WITH_REASON | 14,9 s |
| R3 PV ON | 21 → 0 | 14 | 38 | 216,24 total digeser | — | ±13 s |
| R1 Linux | 21 → 0 | 14 | 38 | identik XAMPP | identik | — |
| 0a2cedf R1 (rencana utama) | 21 → 0 | — | 38 + 13 polish C4 + bukti | — | — | 42,3 s |

**Catatan.** Pada reproducer, family yang dirilis (G4 CONTINUOUS) hanya membutuhkan satu lintasan. Transfer legal sisa dan bukti temuan sisa tetap aktif untuk kasus yang membutuhkannya (rencana utama 0a2cedf: 4 bukti G5 row 28–31; R3 lama: 24 transfer dalam 4 lintasan).
