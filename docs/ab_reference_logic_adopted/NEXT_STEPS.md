# NEXT_STEPS

1. **LNG diterima ≤15 s.** V8 priority review (±13 s) adalah critical path. Opsi: menjalankan kandidat V8 secara paralel lewat helper job (`pp_v4_work_publish`), seperti family Max.
2. **Max Review golden ≤80 s.** Total 205 s: V7 + family 98 s, V8 review 91 s, perbaikan C1–C4 21 s. Opsi:
   - paralelkan V8 review ke helper
   - pakai ulang counterfactual family untuk kandidat V8 (node yang sama sudah dievaluasi)
3. **P10 (Follow PV + LNG) dan P13 (Required Start) ≤30 s.** Pipeline state bahan bakar dengan SR per row dan required start. Opsi: basis Follow PV disimpan saat P0, seperti basis Fastest.
4. **Kualitas Fastest vs golden (82,10 vs 80,23).** Commitment golden (G3+G5 off) valid pada 80,37, tetapi butuh ±48 s per evaluasi. Opsi: kandidat ini dijadikan kandidat kedua Fastest bila budget tersisa.
5. **Rekomendasi ≤10 s pada semua run.** Jitter start job ±1–1,5 s. Opsi: polling UI awal 250 ms.
