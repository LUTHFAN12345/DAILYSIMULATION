# A/B — OLD_GOOD_FAST_REFERENCE vs source terbaru (Fastest - Default)

**Jalur uji:** sama untuk kedua source. UI asli di Chromium (`tools/hx_ui.js`, `tools/abrun.sh`): input lewat field form, upload CSV, klik Run, timer sampai Simulation Data menampilkan 48 row.
**Kondisi sama:** mesin 4 core yang sama, server XAMPP-semantics dengan 6 worker PHP 7.4 (3 pembantu), cold state (`jobs/` termasuk cache `_tl`, `_memo`, dan `data/` dihapus), tanpa memo. Kasus berikutnya baru dimulai setelah seluruh worker PHP diam.

## Source

| | OLD_GOOD_FAST_REFERENCE | Terbaru (freeze paket ini) |
|---|---|---|
| index.php | `7babf11a…` | `6c773205…` |
| run.php | `ccf4ffc2…` | `73ac4269…` |
| worker02.php | `e5bf6807…` | `8b8a78c4…` |
| worker_functions.php | `4d8652cc…` | `4e4ed7a2…` (= 0a2cedf) |
| saved_data_store.php | tidak ada (normal) | `5636e58e…` (= 0a2cedf) |
| Basis | V11 FINAL + patch Change Over V13.4 (`PP_ENGINE_BUILD_ID V13.4-CHANGEOVER-WORKING-CACHE-RESET-20261001`) | commit 0a2cedf + minimal patch (MINIMAL_PERFORMANCE_PATCH.md) |

**Fungsi.**
- Yang **tidak ada** di source lama: Stop-or-Continuous (`pp_bs_*`), audit merit C1–C4 (`pp_v12_merit_audit`), redistribusi C4 (`pp_v12_c4_*`), finalisasi Fastest (`pp_v12_fast_release_try`, `pp_v12_fast_check`), tugas samping V12 (fz/rv/vz/land), Follow PV/SR per row, redistribusi Fixed Flow PGN otomatis, dan datastore.
- Yang **sama**: core run engine, pipeline exact V11, review Unit Priority V8/V10, dan Change Over V13.4.

## A. Reproducer: CSV 5 Oct, PGN 30, PEP 34, LNG/Akasia/Baskara/BBG 0, Min PGN 3, PEP KP72 4,6 Cumulative, MM2100 max 0, Follow PV OFF

**Payload.**
- Body POST `run`: lama `5afe8735…` (487.933 byte), terbaru `8debf02c…` (488.381 byte).
- `assembleInput` sesudah upload: diff struktural **0 path** (data1, data2, gas_quota, modeling identik). Hash string berbeda hanya karena urutan kunci JSON dan field fitur baru.
- UI lama menolak CSV 4 kolom (Apply disabled). IE/Dispatch tetap identik dengan fixture, dan source lama tidak memakai PV.

| Besaran | OLD_GOOD_FAST | Terbaru |
|---|---|---|
| Row | 48 | 48 |
| Waktu klik Run → hasil di UI | **171,4 s**, berakhir "Done … HARD_VALIDATION_FAILED; Save/Export/Publish terkunci" (**tidak ada rencana valid**) | **26,8 s**, FASTEST VALID PLAN (Linux 28,2 s) |
| Job / core run | job 1 exact V11: 113,4 s, 86 core run; job 2 rerun bahan bakar V7 (use_distillate): 56,1 s, 60 core run | 1 job. Kandidat hard-valid pertama: core run baseline (1), diklaim 0,82 s sesudah klik |
| First candidate found | — (UI hanya menampilkan hasil akhir) | 0,55 s sesudah job mulai |
| First fully valid | tidak pernah | 26,15 s (snapshot), Simulation Data 26,70 s |
| Kandidat diperiksa (review) | — | 6 disimulasikan + 10 prasaring (review family terpilih G4 CONTINUOUS) |
| Polish | tidak ada polish C4/merit | 7 evaluasi, 18 pergeseran, 2,3 s (family terpilih). Rencana utama (sebelumnya 131 evaluasi, 37 s) tidak perlu direview |
| C4 temuan awal / FAIL akhir | tidak diaudit (audit terbaru: job 1 C4 FAIL 45, job 2 C4 FAIL 40) | 21 → **0** (151 temuan; seluruhnya status-forced / akun MM2100 penuh / bukti) |
| C4 lintasan, MW legal, bukti | — | 14 row: 13 TRANSFER_LEGAL_APPLIED (208,25 MW), 1 PASS_WITH_REASON dengan bukti counterfactual; 38 evaluasi row (search 6,7 s, proof 3,6 s); dinding C4 14,9 s |
| Cache C4 | — | kunci = dispatch tersusun + konteks input (lihat C4_INCREMENTAL_AUDIT.md) |
| Stop-or-Continuous | tidak ada | G4 CONTINUOUS_SELECTED (alternatif unggul pada tingkat kandidat hard-valid pertama 97,6576 vs 99,0827; versi review-nya dirilis), G8 CONTINUOUS (95,8967 vs STOP 101,116), S1 mengikuti GTG |
| Snapshot → render | — | snapshot 26,15 s → render selesai 26,53 s → Simulation Data 26,70 s |
| Sisa kerja worker sesudah hasil tampil | — | ≤ 2 s (semua worker diam) |
| CP / HR | job 1: 60,3295 / 8601,03 (tidak sah, gas 81,74 > window); job 2: 93,4984 / 8542,22 (tidak sah) | **95,8967 / 8510,49** |
| Signature dispatch GTG | job 2 `c51d712f…` | `708ea39169b5` (UI) / `f3d7f738…` (rows_sig) |
| Startup/shutdown | — | G4 CONTINUOUS sampai akhir, G8 CONTINUOUS |
| Distillate per unit | job 2 diminta 289.445 L, dilaporkan 251.867 L (dibatasi user cap) | G4 243.922,7 L (mix 100 %), G1 35.520 L. One-unit-first: 0 row dengan dua unit parsial |
| Hard constraints (validator terbaru) | job 1: gas_quota, export_range ×2, pgn_rt_min (−2,0), runtime_downtime. Job 2: pgn_rt_min (0,14 < 3), unit_ramp (G9 34,8 > 30) | **PASS** (PGN min 3,0155; gas 71,2989 / 71,32) |
| C1–C4 | FAIL (C2 4/3, C4 45/40) | **PASS** (C2 0, C3 0, C4 0) |
| STG | — | 144/144 (139 = calc, 5 startup hold), 0 pelanggaran |
| Status terminal | HARD_VALIDATION_FAILED | FASTEST_FIRST_FULLY_VALID |

## B. State pengguna dari OLD_GOOD_FAST (`input_data.json` lama, gas dari state, tanpa CSV)

Payload: diff struktural 0 path.

| Besaran | OLD_GOOD_FAST | Terbaru |
|---|---|---|
| Hasil pertama di UI | **13,5 s**: "VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS … belum dibuktikan terendah", CP 103,9521 | **5,9 s**: FASTEST VALID PLAN, CP 104,7755 / HR 8273,74 |
| Hasil final | job 1 exact (10 s, 17 core run) gagal gerbang (gas 84,19 > 70,38). Job 2 rerun Distillate: 212 s, CP 103,0982 / HR 8233,65. Worker sibuk 210 s sesudah hasil provisional tampil | rilis = hasil fully valid; worker diam 1 s sesudahnya |
| Validator terbaru pada rencana itu | provisional: hard PASS, **merit FAIL (C4 14 FAIL)**. Final: hard PASS, **merit FAIL (C4 15 FAIL)** | hard PASS, merit PASS (C4 0 FAIL) |
| Distillate per unit | final: G6 228.855,5 / G4 91.221,1 / G1 19.183,4 L | G6 204.252,1 / G3 93.136,4 / G2 56.200,7 L |

## Kesimpulan A/B

1. **Source lama tidak lebih cepat mencapai rencana valid.**
   - Pada reproducer, source lama tidak pernah menghasilkan rencana sah (171 s, HARD_VALIDATION_FAILED).
   - Pada state pengguna, "cepat"-nya berasal dari tampilan **provisional** 13,5 s yang melanggar urutan merit C4. Hasil final baru ada sesudah 212 s.
2. **Source lama lebih ringan karena tidak memiliki validator.** Ia tidak menjalankan merit C1–C4, Stop-or-Continuous, maupun gerbang fully valid Fastest. Kelemahan itu tidak ditiru; validator terbaru tidak dilemahkan.
3. **CP lama lebih rendah karena melanggar constraint.** Pada state pengguna, CP lama 103,10–103,95 dicapai dengan 14–15 temuan C4 FAIL: unit prioritas rendah dibebani di atas minimum sementara unit prioritas lebih tinggi masih punya headroom legal > 0,5 MW, tanpa bukti counterfactual. Selisih CP terbaru (+0,82 vs provisional) adalah biaya kepatuhan urutan merit.
4. **Tidak ada jalur cepat sah yang bisa diambil dari source lama.** Jalur cepat diperoleh dari pembenahan orkestrasi source terbaru (ROOT_CAUSE_RUNTIME_REGRESSION.md).

Pemeriksaan §7 (asal kecepatan source lama):

| # | Dugaan | Hasil |
|---|---|---|
| 1 | Polish lebih sedikit | ya, karena tidak ada polish merit/C4 sama sekali |
| 2 | Polish berhenti bila kandidat tidak berubah | terbaru juga berhenti bila putaran tanpa pergeseran; kandidat identik diambil dari cache |
| 3 | Tidak menghitung kandidat ekonomik yang tidak perlu | terbaru kini tidak mereview rencana utama bila family alternatif menang |
| 4 | Langsung publish fully valid | lama: publish provisional (belum valid merit). Terbaru: publish atomik saat fully valid |
| 5 | Transfer C4 batch | lama tidak ada C4. Terbaru kini pencarian row paralel bergiliran |
| 6 | Pembantu menerima tugas lebih awal | terbaru kini memakai antrean per penerbit dengan prioritas claim > soc > exact |
| 7 | Tugas ditandai selesai sebelum dibaca | diperbaiki di terbaru: isi dibaca dulu, baru ditandai |
| 8 | Owner/helper menunggu evaluasi sama | terbaru: penanda selesai + kunci cs; pemilik ikut mengerjakan tugas saat menunggu |
| 9 | Stop-or-Continuous di critical path | terbaru: keputusan di tingkat first-valid lebih dulu; review hanya untuk family terpilih |
| 10 | Nested review | tidak ada reset timer. Batas review kini sama untuk kedua family |
| 11 | Fallback exact sesudah fully valid | tidak ada di Fastest |
| 12 | Cache key lebih efektif | kunci terbaru mencakup SR/PV/gas; tidak dilonggarkan |
| 13 | Melewati validator | **ya**: C1–C4, Stop-or-Continuous, dan gerbang Fastest tidak ada di source lama |
