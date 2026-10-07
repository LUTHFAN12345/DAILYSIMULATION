# S7_COMMITMENT_CONSOLIDATION

Skenario S7: G8 awalnya Stop tetapi available. Turunan input S6 (G9/G2 Running + Continuous, PGN 33).

## Masalah sebelum perbaikan

- Fastest 338 s lalu gagal.
  - Kandidat hard-valid pertama (3,6 s) memakai G1 30–48, G2, dan G6 14–48 pada 20 MW (beban minimum), sementara G8/G9 104–108 MW.
  - Gerbang menolak dengan `LOW_LOAD_FRAGMENTATION_UNRESOLVED`, review V8 butuh 102 s, lalu `KELUARGA_COMMITMENT_BELUM_LENGKAP:EVALUASI_TERPOTONG_WAKTU`.
- Root cause (core, debug supplier):
  1. G1 di-start pada row 30 oleh lever Export Range Min.
  2. Ekspansi gas patch B hanya mempertimbangkan unit yang **belum berbeban**. G1, yang prioritasnya lebih tinggi dari G6, dilewati ("unit sudah dipakai"), sehingga G6 dinyalakan sebagai unit **kedua** di beban minimum.
  3. Pin ekspansi diambil dari attempt supplier dengan target internal tinggi (72,73). Pada target itu G1 saja memang tidak cukup. Pin G6 lalu dipakai untuk semua target berikutnya (~72,0), padahal di sana G1 saja sudah cukup.

## Perbaikan (worker_functions.php, patch B — generik)

1. **Perpanjangan window unit yang sudah running.**
   - Unit yang running pada satu segmen kontigu (mulai sesudah row 1) kini dievaluasi untuk start **lebih awal**: segmen legal yang berakhir tepat sebelum start lamanya, dengan startup sequence dan minimum runtime yang sama.
   - Unit itu diperlakukan sebagai kandidat menurut Unit Priority, sebelum unit berprioritas lebih rendah di-start.
   - Beban yang sudah ada tidak diturunkan (`max(v, beban sekarang)`).
2. **Konsolidasi sebelum unit pin.**
   - Pada attempt dengan pin ekspansi, unit yang berprioritas lebih tinggi dari unit pin (start baru atau perpanjangan window) dicoba lebih dulu pada target attempt itu.
   - Bila unit itu sendirian (plus fine top-up menurut Unit Priority) mendaratkan gas di window, unit pin tidak dinyalakan.
   - Hasilnya unit lebih sedikit dan priority-compliant. Pemetaan target → gas tetap kontinu karena kedua cabang mendarat di window target yang sama.
3. Tidak ada nama unit/tanggal yang di-hardcode. Kandidat berasal dari `pp_priority_flat` (Unit Priority user), batas dari `d3` dan `pp_runtime_limits`. Babelan tidak disentuh.

## Hasil S7 (UI asli, cold, Fastest - Default)

| Platform | Waktu | CP | HR | Startup | Hard | C1–C4 | LLF | Gerbang | Sig |
|---|---|---|---|---|---|---|---|---|---|
| Dev (sebelum freeze) | 10,7 s | 65,5288 | 8286,38 | G8 2–48, **G1 15–48** (satu unit Block 2) | PASS | PASS (C2/C3/C4 = 0) | resolved | FASTEST_FIRST_FULLY_VALID | 9d96309193be |
| XAMPP-semantics (final) | 8,86 s | 65,5288 | 8286,38 | sama | PASS | PASS | resolved | FASTEST_FIRST_FULLY_VALID | 9d96309193be |
| Linux Apache + FPM (final) | 8,66 s | 65,5288 | 8286,38 | sama | PASS | PASS | resolved | FASTEST_FIRST_FULLY_VALID | 9d96309193be |

- Sebelum perbaikan: CP kandidat 66,35 dengan 3 unit beban minimum (G1, G2, G6). Sesudahnya: **65,53 (−0,82 USD/MWh)**. G6 tidak dinyalakan; G1 (prioritas di atas G6) diperpanjang dari row 30 ke row 15.
- `KELUARGA_COMMITMENT_BELUM_LENGKAP` dan `ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET` tidak muncul pada Fastest S7, karena kandidat pertama lolos gerbang fully valid.

## Acceptance S7

| Butir | Status |
|---|---|
| 48 row fully valid | PASS |
| C4 FAIL = 0 | PASS |
| LOW_LOAD_FRAGMENTATION resolved | PASS |
| hard / STG / gas / Bus Flow / Export / SR | PASS |
| KELUARGA_COMMITMENT_BELUM_LENGKAP tidak muncul (Fastest) | PASS |
| Fastest ≤ 30 s | PASS (8,7–10,7 s) |

## Batasan yang jujur

1. **Maximum Review S7 belum selesai dalam 450 s** (uji tambahan, tidak termasuk F1–F14).
   - Jangkar kanonik exact menghabiskan 358 s pada tahap keluarga commitment; job dihentikan.
   - Perilaku "node keluarga terpotong menghentikan keluarga" (`break` pada `EVALUASI_TERPOTONG_WAKTU`) **tidak diubah** pada rilis ini.
2. Efek samping pada S6 (F5): review counterfactual kini juga memilih unit tunggal.
   - Hasil Fastest S6: G6 14–48 saja (satu unit) dengan CP 65,7256, HR 8318,24.
   - Sebelumnya G1 30–48 + G6 14–32 dengan CP 65,6523.
   - S6 tetap fully valid (hard, C1–C4, LLF PASS), tetapi CP Fastest +0,0733 USD/MWh (+0,11 %).
