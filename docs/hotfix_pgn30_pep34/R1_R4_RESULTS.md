# R1–R4 — hasil (source hotfix)

**Source hotfix (SHA-256):**

```
96837f622909d53c3dfc9815662637459e082dcac54520da1a390646610c05bd  index.php
b765318e4a1d0ff56fa6305eee3c5a6e39ea459596c56e0d444f13ee6e9faa8c  run.php
5636e58e5f1295da707c75189a9b8ececa12197ad28ed6678f828dec166acafc  saved_data_store.php
04901bb46209a057e92391b300eeb844629c1305ee20770c2d0b4108be7aba86  worker02.php
4e4ed7a2b85d89de75e454b9bb344a929765b53075615430b641ce55603af563  worker_functions.php
```

Lint PHP 7.4: `No syntax errors detected` untuk kelima berkas. `index.php`, `saved_data_store.php`, dan `worker_functions.php` identik dengan d6439e9.

**Jalur uji:** UI asli di Chromium (`tools/hx_ui.js`, `tools/rx.sh`):
1. input gas diisi lewat field form;
2. upload CSV + Apply Import;
3. klik Run, lalu waktu diukur sampai Simulation Data menampilkan 48 row.

**Lingkungan:** XAMPP-semantics (server PHP 7.4, 6 worker) dan Linux Apache 2.4 + PHP-FPM 7.4, di mesin 4 core.

## Ringkasan

| Test | Kondisi | d6439e9 | Hotfix | Status |
|---|---|---|---|---|
| **R1** | CSV + PGN 30 + PEP 34, Fastest | tidak ada Simulation Data dalam 300 s | **FASTEST VALID PLAN, 88,5 s** (XAMPP) | PASS: hasil tampil, tidak tertahan, tidak ada kandidat identik berulang. Target < 60 s **belum tercapai** |
| **R2** | sama, tanpa upload CSV | tidak ada Simulation Data dalam 300 s | FASTEST VALID PLAN, 107,8 s, hasil identik R1 | PASS: akar masalah di engine, bukan import |
| **R3 OFF** | CSV, Fix SR 0 (= R1) | lihat R1 | 88,5 s | PASS |
| **R3 ON** | CSV, Follow PV floor 0 | tidak diukur di d6439e9. Pada source antara (sebelum perbaikan transfer legal sisa): VALID PROVISIONAL sesudah > 400 s | **FASTEST VALID PLAN, 166,1 s** | PASS (fully valid). Lambat, lihat di bawah |
| **R4** | R1 di XAMPP vs Linux | — | XAMPP 88,5 s / Linux 92,7 s, output identik | PASS |
| Smoke U31 | — | sig 7775db96324d, CP 64,7205 | identik | PASS |
| Smoke CO Block 1-2 | — | sig e15860b82ee7, CP 63,8636 | identik | PASS |

## R1 — reproducer asli (CSV + PGN 30 + PEP 34)

**Payload**
- Upload hanya mengubah `data3.modeling.pv_rows[*]`; IE/Dispatch CSV sama dengan fixture.
- 48 row: row 1 = 05-Oct-26 00:30, row 48 = 06-Oct-26 00:00.
- Tetap: `gas_quota` {pgn_pipe 30, lng 0, pep 34, pep_kp72 4,6 (cummulative), lainnya 0}, `min_pgn_flow` 3, `max_flow_mm2100` 0, GHV 1040/1080, `manual_fixed_flows` 0, `sr_mode` fixed 0, `stop_mode` g4/g8/s1 = `stop_or_continuous_sim`.
- Hash `assembleInput`:
  - sebelum upload `690d7163…`, sesudah `be95bb81…`;
  - sama di d6439e9 dan hotfix, serta di XAMPP dan Linux.
- Satu POST `run.php?mode=run&fast=1` (488.381 byte) dan tidak ada job kedua.

**Hasil:** FASTEST VALID PLAN, 48 row.

| Besaran | Nilai |
|---|---|
| Hard / gerbang | PASS / `FASTEST_FIRST_FULLY_VALID` |
| Merit | PASS; C4 151 temuan, FAIL 0 |
| STG | 144/144, 0 pelanggaran |
| CP / HR | 95,8967 USD/MWh / 8510,49 BTU/kWh |
| PGN min | 3,0155 MMSCFD (≥ 3) |
| Gas used / quota | 71,2989 / 71,32 BBTUD (di dalam window) |
| Signature dispatch | `708ea39169b5` |

**Kandidat identik:** jejak polish mencatat 131 evaluasi dengan 130 hash unik. Satu ulangan (gabungan semua pergeseran) diambil dari cache.

**Progres UI:** finalisasi kini melaporkan `FASTEST_FINALISASI_*`, sehingga label tidak lagi tertahan di "gas window correction 40%".

## R2 — tanpa upload CSV

- Payload sama dengan R1 kecuali `pv_rows`, yang tidak dipakai karena SR mode Fix.
- Route sama: satu job `economic_review`, klaim kandidat pertama 0,84 s sesudah job mulai.
- Jumlah evaluasi polish sama (131), dan hasil identik dengan R1 (CP 95,8967, sig `708ea39169b5`).
- Waktu 107,8 s (R1 88,5 s). Variasinya karena pembantu yang sedang menjalankan family alternatif Stop-or-Continuous; hanya 2 cache hit polish di R2 dibanding 14 di R1.
- Di d6439e9, R2 juga hang (baseline 94 s, exact penuh sampai lebih dari 300 s).

**Kesimpulan:** akar masalah ada di engine (finalisasi Fastest + C4), bukan di import CSV.

## R3 — Follow PV OFF vs ON (CSV sama)

| | OFF (Fix SR 0) | ON (Follow PV, floor 0; SR min = PV, maks 24,15 MW) |
|---|---|---|
| Hasil | FASTEST VALID PLAN | FASTEST VALID PLAN |
| CP | 95,8967 | 96,0078 |
| T (klik → 48 row) | 88,5 s | 166,1 s |
| Polish | 6 putaran, 131 evaluasi | 6 putaran, 131 evaluasi |
| Tahap C4 | detik 44 → 87 (42 s) | detik 57 → 164 (107 s) |

**Apakah SR per row menyebabkan pencarian tambahan? Ya, di tahap C4.**
- Dengan SR floor per row, pencarian per row menyisakan transfer legal sesudah hasilnya digabung. Hotfix menerapkannya: di CLI, 24 transfer dalam 4 lintasan, 63,3 MW.
- Tahap C4 karena itu 2,5× lebih lama.
- Review dan polish tidak bertambah.

## R4 — cross-platform (R1)

| | XAMPP-semantics | Linux Apache/PHP-FPM |
|---|---|---|
| SHA 5 PHP | = freeze hotfix | = freeze hotfix |
| Payload `assembleInput` | `be95bb81…` | `be95bb81…` |
| Status | FASTEST VALID PLAN, `FASTEST_FIRST_FULLY_VALID` | sama |
| CP / HR / sig / PGN min / gas | 95,8967 / 8510,49 / `708ea39169b5` / 3,0155 / 71,2989 | identik |
| C4 / merit | 151 temuan, 0 FAIL / PASS | identik |
| T | 88,5 s | 92,7 s |

## Keterbatasan

- **Target < 60 s belum tercapai** untuk kombinasi PGN 30 + PEP 34 (R1 88–93 s, R3 ON 166 s).
  - Kandidat pertama untuk input ini membutuhkan review Unit Priority (polish 6 putaran × ±22 pergeseran) dan redistribusi C4 yang berat.
  - Di mesin 4 core, dua dari tiga pembantu sedang menghitung family alternatif Stop-or-Continuous selama awal review.
  - Rincian ada di PERFORMANCE_BEFORE_AFTER.md.
- Kandidat terklaim pada job hotfix menghasilkan 21 temuan C4 sebelum redistribusi, sedangkan kandidat yang diklaim d6439e9 menghasilkan 48. Keduanya diselesaikan: kandidat d6439e9 dibuktikan di CLI (48 → 0), dan hasil job hotfix identik di semua run R1/R2 serta di kedua platform.
- Tidak ada full regression. A/C/D dari matriks sebelumnya diukur sekali dengan perbaikan C4 (dan instrumentasi) sebelum perubahan penerbit tugas samping; hasilnya identik (CP 90,0739 / 89,9882 / 90,0877). Sesudah freeze hotfix, sesuai instruksi, hanya R1–R4, U31, dan CO yang dijalankan.
