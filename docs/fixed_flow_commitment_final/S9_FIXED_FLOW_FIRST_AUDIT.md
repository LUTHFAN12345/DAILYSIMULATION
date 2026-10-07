# S9_FIXED_FLOW_FIRST_AUDIT

Skenario S9: G8 unavailable (`unit_stop` G8, Last Data Stop), turunan input S6, PGN 33, Min PGN Flow 3 MMSCFD, Manual Fixed Flow JBBK 48 row.

## Masalah sebelum perbaikan

- Preview menggantung 140,6 s, lalu ditolak (`pgn_rt_min` row 1–12, `ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET`).
- Tanpa G8, konsumen gas malam hari hanya G9 + G2. FLOW PGN REAL TIME row 1–11 = −3,63 … 1,14 MMSCFD, padahal minimum 3.
- Pipeline exact mengevaluasi puluhan kandidat. Semuanya gagal `pgn_rt_min` karena Fixed Flow JBBK malam hari (36 MMSCFD) tidak pernah dikoreksi.

## Urutan solver (sesuai instruksi)

| Langkah | Implementasi | Hasil S9 |
|---|---|---|
| 1. Maksimalkan unit running menurut Unit Priority dan legal headroom | Core run baseline pipeline utama (shaper, patch A/B) | G9 98–108 MW. Export di bawah Range Max, tetapi Bus Flow di minimum 10 MW. |
| 2. Startup unit available menurut Unit Priority | Core run baseline (lever Export-Min patch A, ekspansi gas patch B) | Unit siang hari G1, G3, G4, G5, G6, G7 untuk Export Range Min (IE puncak 584 MW). Malam hari tidak ada unit tambahan yang legal. |
| 3. Kurangi Fixed Flow JBBK hanya di row bermasalah | `pp_ff_first_correction`: probe di core run baseline (`worker02`, depth 1, job pemilik). Delta minimum per row = (Min + 0,25 − flow) × GHV_PGN/GHV_J, dibulatkan 0,01 ke bawah, lalu diverifikasi core run (maks 4 langkah). | Row 1–11 menjadi 30,28 MMSCFD (pengurangan maks 6,64). Terverifikasi pada langkah 1: seluruh row ≥ 3,069. |
| 4. Redistribusi rata ke row aman sesudah periode | `pp_bs_ff_redistribute` (water-filling, row penuh dikunci, sisa dibagi ulang), margin 0,50 | 35 penerima, row 12–48. Tambahan 1,4775–1,6708 MMSCFD; row 14 capped; porsi maksimum per row 2,9 %. |
| 5. Total kuota harian identik | Dicek per slot | 36,000000 → 36,000000 MMSCF, selisih 0 (toleransi 1e-6). |
| 6. Validasi ulang | `pp_validate_hard_constraints` atas core run 48 nilai baru, dibandingkan run sumber-saja | `PASS_NO_NEW_VIOLATION`. `pgn_rt_min` bersih. `gas_quota` dan `bus_flow` sudah ada pada run sumber-saja; keduanya ditangani pipeline pada rerun. |
| 7. Babelan emergency | Tidak dipicu | Koreksi Fixed Flow menyelesaikan Min PGN Flow. Babelan tidak diturunkan (lihat batasan). |

Keputusan job pertama: `PGN_MIN_FLOW_AUTOMATIC_CORRECTION` (wall 4,7 s sesudah baseline). UI menerapkan 48 nilai Manual Fixed Flow, menyimpan audit `pgn_fixed_flow_recommendation_applied` (`correction_mode=automatic`, `rerun_pending`), lalu menjalankan ulang **satu kali** (`_run_source=pgn_auto_correction`).

- Tidak ada loop. Bila rerun masih gagal Min PGN Flow, terminal `PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE` tanpa koreksi kedua.
- Kapasitas penerima kurang atau verifikasi gagal juga menghasilkan `PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE`, tanpa perubahan sebagian dan tanpa mengurangi kuota harian.

## Rerun sesudah koreksi (batas Fastest)

- Rerun job koreksi memakai batas Fastest 18 s (`pp_ff_rerun_budget_s`, env `PP_FF_RERUN_BUDGET_S`). Batas ini dipasang sebagai deadline absolut pipeline (`__pp_budget_deadline`) dan dibaca pengklaim Fastest lewat `v12_ff_rerun_dl`.
- Hasil S9: tidak ada kandidat hard-valid dalam 18 s. Terminal `FASTEST_NO_FULLY_VALID_PLAN_WITHIN_BUDGET` dengan teks jujur: "Ini bukan bukti infeasible; Maximum Review dapat mencari lebih lanjut."
- Total waktu klik Run sampai keputusan terminal: **28,0 s** (XAMPP-semantics) / **26,3 s** (Linux Apache + PHP-FPM). Sebelumnya preview menggantung 140,6 s.

## Acceptance S9

| Butir | Status |
|---|---|
| G8 tetap 0 seluruh row | PASS (`unit_stop`, enforced) |
| Startup unit lain mengikuti priority | PASS (lever patch A/B, Unit Priority user) |
| Fixed Flow total harian identik | PASS (selisih 0 MMSCF) |
| Babelan tetap maksimum kecuali emergency proof | PASS (koreksi Fixed Flow tidak menurunkan Babelan) |
| Audit before/after 48 row | PASS (tabel di bawah; juga tersimpan di audit UI) |
| 48 row fully valid **atau** terminal ≤ 30 s | **Terminal 28,0 s / 26,3 s**, tetapi **bukan bukti infeasible**. Lihat batasan. |
| Tidak ada preview menggantung 141 s | PASS |

## Batasan yang jujur

1. Pada S9 tidak ditemukan rencana fully valid dalam 30 s.
   - Pada uji terpisah tanpa batas waktu, input terkoreksi menghasilkan kandidat hard-valid pertama pada detik 26,7. Gerbang menolaknya: C4 8 temuan (G7 berbeban 50–80 MW sementara G1/G3/G5/G6 di minimum pada row 16–21) dan `LOW_LOAD_FRAGMENTATION` 38 temuan tanpa bukti.
   - Job itu berakhir 408 s tanpa rencana final.
   - Terminal 18 s adalah batas waktu Fastest, bukan sertifikat infeasibility.
2. Emergency Babelan untuk Min PGN Flow tidak diimplementasikan sebagai jalur baru. Pada S9, koreksi Fixed Flow sudah memenuhi Min PGN Flow (langkah 3–6), jadi syarat emergency (Fixed Flow terbukti gagal) tidak terpenuhi.

## Audit before/after 48 row (job `economic_review-c358866bead085898ac9`, XAMPP-semantics)

| Row | Jam | Peran | Fixed Flow JBBK sebelum | sesudah | Δ (MMSCFD) | FLOW PGN RT sebelum | sesudah (verifikasi) |
|---|---|---|---|---|---|---|---|
| 1 | 00:30 | sumber | 32.3200 | 30.2800 | -2.0400 | 1.138 | 3.069 |
| 2 | 01:00 | sumber | 36.0782 | 30.2800 | -5.7982 | -2.765 | 3.069 |
| 3 | 01:30 | sumber | 36.0783 | 30.2800 | -5.7983 | -2.765 | 3.069 |
| 4 | 02:00 | sumber | 36.0783 | 30.2800 | -5.7983 | -2.765 | 3.069 |
| 5 | 02:30 | sumber | 36.0783 | 30.3500 | -5.7283 | -2.696 | 3.699 |
| 6 | 03:00 | sumber | 36.0783 | 30.2800 | -5.7983 | -2.765 | 3.069 |
| 7 | 03:30 | sumber | 36.0783 | 30.3500 | -5.7283 | -2.696 | 3.816 |
| 8 | 04:00 | sumber | 36.0783 | 29.8300 | -6.2483 | -3.233 | 3.537 |
| 9 | 04:30 | sumber | 36.0783 | 29.4400 | -6.6383 | -3.633 | 4.410 |
| 10 | 05:00 | sumber | 36.0783 | 30.2800 | -5.7983 | -2.765 | 3.069 |
| 11 | 05:30 | sumber | 36.0783 | 33.1700 | -2.9083 | 0.231 | 4.123 |
| 12 | 06:00 | — | 36.0783 | 36.0783 | +0.0000 | 3.066 | 3.053 |
| 13 | 06:30 | — | 36.0783 | 36.0783 | +0.0000 | 3.251 | 3.238 |
| 14 | 07:00 | penerima (capped) | 36.0783 | 37.5558 | +1.4775 | 9.435 | 3.500 |
| 15 | 07:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 17.127 | 14.369 |
| 16 | 08:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 27.369 | 21.465 |
| 17 | 08:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 32.935 | 31.000 |
| 18 | 09:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 37.104 | 35.151 |
| 19 | 09:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 36.482 | 34.969 |
| 20 | 10:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 41.036 | 52.904 |
| 21 | 10:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 42.434 | 56.824 |
| 22 | 11:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 43.762 | 58.731 |
| 23 | 11:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 46.977 | 57.967 |
| 24 | 12:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 46.010 | 58.421 |
| 25 | 12:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 39.443 | 49.359 |
| 26 | 13:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 56.440 | 55.306 |
| 27 | 13:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 63.557 | 57.123 |
| 28 | 14:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 66.811 | 54.985 |
| 29 | 14:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 67.335 | 57.440 |
| 30 | 15:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 67.795 | 57.946 |
| 31 | 15:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 63.340 | 57.309 |
| 32 | 16:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 57.886 | 55.798 |
| 33 | 16:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 56.844 | 52.122 |
| 34 | 17:00 | penerima | 36.0783 | 37.7491 | +1.6708 | 56.844 | 47.340 |
| 35 | 17:30 | penerima | 36.0783 | 37.7491 | +1.6708 | 49.937 | 43.741 |
| 36 | 18:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 49.937 | 41.578 |
| 37 | 18:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 42.248 | 36.966 |
| 38 | 19:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 38.798 | 34.405 |
| 39 | 19:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 37.894 | 34.753 |
| 40 | 20:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 37.878 | 34.190 |
| 41 | 20:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 37.770 | 34.689 |
| 42 | 21:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 40.321 | 36.388 |
| 43 | 21:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 39.444 | 36.053 |
| 44 | 22:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 39.440 | 36.581 |
| 45 | 22:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 39.036 | 35.917 |
| 46 | 23:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 38.930 | 35.814 |
| 47 | 23:30 | penerima | 36.0783 | 37.7490 | +1.6707 | 38.016 | 34.928 |
| 48 | 00:00 | penerima | 36.0783 | 37.7490 | +1.6707 | 36.103 | 33.151 |

Total 48 row: sebelum 1728.0000, sesudah 1728.0000 MMSCFD-slot (selisih 0.000000); volume harian 36.000000 → 36.000000 MMSCF (selisih 0, toleransi 1e-06).