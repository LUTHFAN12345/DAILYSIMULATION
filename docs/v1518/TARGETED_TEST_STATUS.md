# TARGETED TEST STATUS — V15.18 (SC/CC, Continuous, Effective Priority, Block Sequence)

Mesin uji: container Linux, PHP 7.4 CLI (`dumpjob.php`, jalur job Fastest yang sama dengan UI), browser Chromium via proksi
multi-backend (meniru XAMPP). Fixture: skenario BBLN1 05-Oct (= file Monitoring) dan golden D0.
Checker otomatis: `harness/v1518/chk.py` (hard PASS, gate PASS, C1–C4 FAIL = 0, STG proof PASS, Block Sequence PASS,
hash proof binding v1518 + cek spesifik per test). Dijalankan BERURUTAN; berhenti bila satu gagal.

## Hasil akhir (kode final, satu proses, berurutan)
| Test | Skenario | Hasil | Wall | CP | Cek spesifik |
|---|---|---|---|---|---|
| T0 | G6 Start Sim + Stop Status Continuous | PASS | 37,2 s | 71,9896 | G6 ON 2–48 setelah start; commitment START_BASED_ON_SIMULATION + HOLD_TO_END_AFTER_START |
| T1 | G6 Commitment Continuous | PASS | 32,7 s | 72,1938 | G6 48/48 row, CONTINUOUS_FULL_HORIZON |
| T2 | Stop or Continuous (G6, G9) | PASS | 27,8 s | 71,2890 | Evidence dua family: G6 A→B (hold valid, diadopsi), G9 A ditolak prasaring kapasitas Export |
| T3 | G6 stop 16:00 | PASS | 39,6 s | 71,5546 | G6 = 0 sejak 16:00; notifikasi teks §3.2; rows[31] G6→G4→G3, rows[32] G4→G3→G6 |
| T4 | G6 SC, Start Request 05:00, Stop Sim | PASS | 29,6 s | 72,8128 | 05:00 marker 0 MW, 05:30 = 20 MW bebas; S1 feed [G3,G4]; G6 terakhir |
| T5 | G9 SC (PGN 28), Start 05:00, Stop Sim | PASS | 28,1 s | 77,7052 | 05:00 = 0, 05:30 = 40,5 ≥ 30; ramp maks 25,1 ≤ 55; S3 feed [G8] |
| T6 | S1 Start Sim + hold, G6 tidak diminta | PASS | 37,2 s | 71,9896 | G6 auto lead (Required + hold); G4/G3 tidak otomatis |
| T7 | G6 SC + S1 Required | PASS | 13,3 s | 72,0665 | lead S1 = G4; notifikasi SC |
| T8 | Reproducer Monitoring | PASS | 28,9 s | 71,2890 | lead dulu; S1 cold hold ≥ 7 row; additional ≥ row legal; connect ≥ S1 first+1; BB1 = 0 |
| T9 | Golden + smoke BBLN1 | PASS | 9,2 s | 79,9049 | golden CP ≤ 80,2521 (tidak berubah); BBLN1 = T8 |

Monitoring (78b72d5, CP 72,5145) vs V15.18 T8: CP 71,2890, tanpa additional ilegal, merit/gate PASS.

## UI (browser, `harness/v1518/t18ui.js`) — 14/14 PASS
U0 kolom SC/CC hanya G1–G6, G8, G9 · U1 Stop Status Continuous tidak mengubah Commitment · U2 Commitment Continuous =
full horizon, Stop Status disabled · U3 STG auto-follow G6 (Stop Continuous ikut) · U4 SC → G4 lead + notifikasi ·
U5 notifikasi Stop At 16:00 · U6 teks Stop All Day B1 · U7 Block Required ≠ full horizon · U8 ringkasan semantik
sebelum Run · U9 payload + Save/Reload (SC, hold) · U10 Input Snapshot (sc_cc + semantics) · U11 Run T7 via UI
(gate/merit PASS, oranye SC, hash proof) · U12 Excel (SC/CC + Effective Priority per row) · U13 tanpa error halaman.

## Regresi A/B vs 78b72d5 (38 kasus: cold, rantai warm IE Adjustment, rantai warm Actual Gas)
Tidak ada kasus yang CP-nya naik. D3 (G6 unavailable) FAIL→PASS; D5, R1, SR5, IE4, A5 lebih murah; lainnya identik.
Status FAIL yang tersisa (D5, D5_two_blocks, R2_minflow45, rantai A) identik dengan 78b72d5 (keputusan terminal/operator).
Tabel lengkap: `REGRESSION_AB_78b72d5.txt`.
Browser: golden Fastest 9,3 s PASS; IE Adjustment `ie17` PASS (isolasi A/B konsisten); `conc_sup` PASS; C5 cancel-isolation
V15.18 benar 2/2 (`detached`, `other_active_runs: 1`), 78b72d5 gagal 1/2 pada uji yang sama; R5 Actual Gas identik dengan
78b72d5 (keputusan operator dua window kuota). Maximum Review P0 berakhir "pemenang exact tidak lolos gate MERIT" pada
78b72d5 maupun V15.18 (kondisi lama, jalur Maximum Review).

## Determinisme
Admission decommit Fastest, review V8, dan kini review V8 jalur IE warm memakai batas JUMLAH (bukan jam). Terukur: rantai
IE 78b72d5 memberi IE5 80,1881 / 80,1822 pada dua run identik; V15.18 80,1823 / 80,1822 (sisa selisih ≤ 0,0002 dari
pengaman waktu lain).

## Belum memenuhi acceptance (alasan tidak membuat ZIP)
1. **Performa §12** — target kasus sulit ≤ 30 s: T0 37,2 s, T1 32,7 s, T3 39,6 s, T6 37,2 s (CLI, mesin uji). Penyebab
   utama: review V8 + landing ganda trim V4/V5 (± 6 core × 3–4 s) setelah pipeline. Sudah dipangkas: lock R1 satu langkah,
   stagger, prasaring fisik startup/STG hold, polish node family dimatikan (T8 53 → 29 s, T3 50 → 40 s).
2. **T3 PGN−1 dedup kandidat decommit** dan **cold IE sebelum Run pertama** (§9) belum dikerjakan.
3. Lantai SC G1–G6 = 20 MW adalah pilihan konservatif (5/15 MW = startup-only di validator); perlu konfirmasi data teknis.
