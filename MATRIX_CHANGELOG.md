# MATRIX CHANGELOG — V15.27 (basis matrix V15.26: 236 test engine, `harness/v1526/matrix`)

Definisi test T001–T236 tidak berubah (payload identik). Kolom `baseline_version` tetap V15.25 (debf01f) sebagai pembanding CP/HR.

Perubahan oracle (satu):
- `cmp_final` (grup M, T227–T228, reimplementasi `pp_sp_final_cmp` untuk memeriksa Maximum Review vs Fastest) mengikuti objective final V15.27:
  CP ekonomi -> CP resmi (band 0,2 %) -> HR -> headroom legal unit prioritas tinggi tak terpakai (tie-break) -> startup / unit-row / fragmentasi.
  V15.26 menempatkan headroom sebelum CP ekonomi; dengan oracle lama T227 tercatat FAIL walaupun engine memilih dan mempublikasikan pemenang
  objective final yang benar (Fastest CP 93,0321 vs Maximum Review 93,8532).

Uji di luar `mx.py` (V15.27):
- `harness/v1527/cp41.py` — audit 41 test yang CP-nya naik pada V15.26 (kategori root cause A–G, CP/HR V15.25 / V15.26 / V15.27).
- `harness/v1527/sensitivity.py` — sensitivitas objective final & konsolidasi dengan urutan V15.27.
- `harness/v1527/BLOCK3_TIMELINE.md` — timeline per row Block 3 cold start (T220).
- `harness/v1527/AUDIT_PRIORITY_LOGIC.md` — audit logic prioritas per row V15.27.
- Actual Gas dua arah (`harness/v1526/actual_gas/ag_tests.py`), benchmark (`harness/v1526/bench/bench.py`), row audit (`harness/v1526/row_audit.py`) — skrip V15.26, dijalankan ulang pada source V15.27.
