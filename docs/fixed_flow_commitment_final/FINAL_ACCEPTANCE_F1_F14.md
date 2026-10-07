# FINAL_ACCEPTANCE_F1_F14

Source final (`run.php`, `worker02.php`, `worker_functions.php`, `index.php`, `saved_data_store.php`; SHA-256 di `SHA256SUMS.txt`) = fb3d8d8 + P0 + patch A + patch B + rilis ini:

- **S9 Fixed Flow first**: probe Min PGN Flow pada core run baseline → koreksi Fixed Flow JBBK delta minimum + redistribusi rata, total harian identik → rerun satu kali dengan batas Fastest 18 s.
- **S7 konsolidasi commitment**: perpanjangan window unit running berprioritas tinggi; unit berprioritas lebih tinggi dicoba sebelum unit pin.
- **Fix regresi Change Over B1→B2**: lever start Export-Min patch A tidak lagi men-start GTG blok Change Over; commitment GTG itu milik timeline Change Over.

Uji: UI asli (Playwright Chromium), cold, satu run per kasus. XAMPP-semantics = proksi `v3/proxy.js` 6 worker (model `php -S` XAMPP). Linux = Apache 2.4 mpm_event + PHP-FPM 7.4 (www-data, OPcache on). Validator = source final.

| ID | Uji | Waktu | Mode / hasil | CP | HR | C4 | STG | Status |
|---|---|---|---|---|---|---|---|---|
| F1 | Golden Fastest | 1,84 s (final: 1,58 XAMPP / 1,39 Linux) | FASTEST VALID PLAN, G1 21–32, Babelan 5759,75 | 63,9783 | 8020,59 | 0 | PASS | **PASS** |
| F2 | Golden Maximum Review | 20,17 s | FINAL (38 kandidat / 11 valid), G1 21–32, Babelan 5759,76 | 63,9782 | 8020,58 | 0 | PASS | **PASS** |
| F3 | S7 G8 stopped, available | 10,66 s (final: 8,86 / 8,66) | FASTEST VALID PLAN, G8 2–48, G1 15–48 (satu unit Block 2) | 65,5288 | 8286,38 | 0 | PASS | **PASS** |
| F4 | S9 G8 unavailable | 28,0 s (XAMPP) / 26,3 s (Linux) | `PGN_MIN_FLOW_AUTOMATIC_CORRECTION` (4,7 s) → rerun → terminal `FASTEST_NO_FULLY_VALID_PLAN_WITHIN_BUDGET` | — | — | — | — | **PASS bersyarat**: terminal ≤ 30 s dan Fixed Flow first terbukti; **bukan rencana fully valid dan bukan bukti infeasible** |
| F5 | S6 G8/G9 Continuous | 11,57 s | FASTEST VALID PLAN, G6 14–48 | 65,7256 | 8318,24 | 0 | PASS | **PASS** (CP +0,0733 vs 65,6523 sebelumnya) |
| F6 | PGN +1 (34) | 5,99 s | FASTEST VALID PLAN | 64,0067 | 8016,24 | 0 | PASS | **PASS** |
| F7 | PGN −1 (32) | 1,64 s | FASTEST VALID PLAN | 64,0037 | 8036,30 | 0 | PASS | **PASS** |
| F8 | PEP +2 (34) | 14,74 s | FINAL OPTIMAL (exact selesai lebih dulu, 52 kandidat / 17 valid) | 63,9719 | 8033,13 | 0 | PASS | **PASS** |
| F9 | PEP −2 (30) | 1,82 s | FASTEST VALID PLAN | 64,3392 | 8081,02 | 0 | PASS | **PASS** |
| F10a | KP72 = 0 | 1,58 s | FASTEST VALID PLAN (identik golden, sig bc883f0a3aab) | 63,9783 | 8020,59 | 0 | PASS | **PASS** |
| F10b | KP72 PEP aktual 2 MMSCFD | 1,38 s | FASTEST VALID PLAN | 63,8536 | 8008,16 | 0 | PASS | **PASS** |
| F11 | Distillate smoke (D_rec, PGN25+PEP30, aksi Distillate) | — | 11/11 LULUS: satu unit dulu (G1), warna/tooltip numerik, tanpa JS error | — | — | — | — | **PASS** |
| F12 | Change Over Block 1→2 smoke (WB09_3 sim/sim cold) + B2→B1 | 1,89 s / 3,75 s | B1→B2 hard PASS (sebelumnya `CHANGE_OVER_DECISION` sejak patch A, kini diperbaiki); B2→B1 hard PASS | 63,8629 / 63,9370 | 8197,61 / 8213,60 | — | PASS | **PASS** |
| F13 | Save / Reload / Report (`saved_data_store`) | — | 13/13 LULUS: save, reload record lama, report tetap ada sesudah revisi source, checksum, migrasi, pemulihan | — | — | — | — | **PASS** |
| F14 | Parity XAMPP-semantics vs Linux: Golden, S7, S9 | lihat bawah | sig dispatch identik; keputusan S9 identik | — | — | — | — | **PASS** |

## F14 parity (source final, SHA identik di kedua platform)

| Kasus | XAMPP-semantics | Linux Apache + PHP-FPM | Identik |
|---|---|---|---|
| Golden Fastest | 1,58 s, CP 63,9783, HR 8020,59, sig bc883f0a3aab | 1,39 s, CP 63,9783, HR 8020,59, sig bc883f0a3aab | ya |
| S7 Fastest | 8,86 s, CP 65,5288, HR 8286,38, sig 9d96309193be | 8,66 s, CP 65,5288, HR 8286,38, sig 9d96309193be | ya |
| S9 Fastest | 28,0 s: koreksi Fixed Flow → `FASTEST_NO_FULLY_VALID_PLAN_WITHIN_BUDGET` | 26,3 s: keputusan sama | ya |

## Catatan yang harus diketahui

1. **S9**: Min PGN Flow kini diselesaikan Fixed Flow first.
   - Row 1–11 dikurangi, volume dibagi rata ke row 12–48, total harian identik, Babelan tidak diturunkan.
   - Rerun belum menemukan rencana fully valid dalam batas Fastest 18 s. Terminalnya batas waktu, bukan sertifikat infeasibility.
   - Detail di `S9_FIXED_FLOW_FIRST_AUDIT.md`.
2. **S7 Maximum Review** (di luar F1–F14) belum selesai dalam 450 s: tahap keluarga jangkar kanonik 358 s. Lihat `S7_COMMITMENT_CONSOLIDATION.md`.
3. **S6 Fastest** tetap PASS, tetapi memakai satu unit (G6 14–48) dengan CP 65,7256 (+0,11 %), sebelumnya 65,6523.
4. Regresi Change Over B1→B2 berasal dari patch A, bukan dari rilis ini (bisect: before_A PASS, A2 FAIL). Ditemukan oleh F12 dan diperbaiki secara generik.

## Penutupan

- Baris instrumentasi debug dihapus: hitungan `var_dump` / `error_log(` sama dengan fb3d8d8.
- Lint PHP 7.4: 5/5 bersih.
- Clean-extract ZIP dan SHA256SUMS dicocokkan.
- Tidak ada proses uji tertinggal (proksi, harness, Apache, PHP-FPM dihentikan).
