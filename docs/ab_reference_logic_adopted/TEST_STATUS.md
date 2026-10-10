# TEST_STATUS

| Area | Status |
|---|---|
| P0–P15 targeted (UI asli) | **17/17 PASS** (P0–P15 + P10d) — lihat P0_P15_RESULTS.md |
| Cold/warm 3+3 (P0, P6, P7, P8, P9, P10) | selesai, median dan max dilaporkan — lihat COLD_WARM_RUNTIME.md |
| Gate C1–C4 / STG pada semua final | FAIL = 0; STG mismatch 0/144 |
| Golden Baru(5) CLI (validator terbaru) | VALID / hard PASS, CP 80,2321 (identik dengan Excel) |
| Golden Max Review | FINAL PASS, CP 80,1504 (lebih baik dari golden) |
| Golden Fastest | PASS, CP 82,0968 |
| Paritas Linux (Apache + PHP-FPM 7.4) | P0, P7, P8, GOLDEN, P12 dan P1 Max: CP/HR identik |
| PHP 7.4 lint (5 file) | bersih |
| Clean-extract + SHA ZIP | lihat CHECKPOINT.md |

## Patch setelah matriks pertama (stop → root cause → fix → rerun)
1. **GOLDEN_max diblok gate `MERIT_PROOF_C1_C4_STG_FAIL`.**
   - Root cause: "separuh pemindahan" selalu mempertahankan paruh pertama. Pasangan row 2 (G2→G8/G9) tidak pernah dievaluasi sendiri, sehingga tidak punya bukti.
   - Fix: evaluasi greedy per pemindahan.
2. **Pasangan baru row 33–34 muncul setelah pass ke-4.**
   - Fix: batas pass dinaikkan menjadi 8 dan deadline menjadi 30 s.

Setelah fix, matriks final dijalankan ulang penuh (`runs/final2`), ditambah golden CLI dan P15. Semua PASS, dan hasil Fastest identik dengan sebelum patch.

## Target runtime yang belum tercapai
Detail di COLD_WARM_RUNTIME.md.
- LNG diterima: median 15,97 s (target 15)
- P10: 48 s dan P13: 43 s (target 30)
- Max golden: 205 s (target 80)
- Rekomendasi: max 11,52 s (median 9,69–9,98)
