# Root cause — Fastest tertahan pada PGN 30 + PEP 34 (setelah import CSV)

**Source dasar:** commit `d6439e9` (`php_simulation_AUTO_PGN_REDISTRIBUTION_SR_PV_FINAL.zip`).
**Reproducer:** UI asli di Chromium (`tools/hx_ui.js`):
- upload `CSV Load Pred dan Dispatch (5 Oct).csv`;
- Fastest - Default;
- PGN 30 BBTUD; LNG 0; PEP 34 MMSCFD; Akasia/Baskara/BBG 0;
- GHV PGN 1040, GHV JBBK 1080; Min PGN Flow 3 MMSCFD;
- PEP KP72 4,6 BBTUD Cummulative; Pertagas/Akasia/Baskara KP72 0; Maximum Flow MM2100 0.

Semua nilai diisi lewat field form.

## 1. Gejala terukur pada d6439e9

| | R1 (dengan CSV) | R2 (tanpa CSV) |
|---|---|---|
| Simulation Data dalam 300 s | **tidak ada** | **tidak ada** |
| Progres UI | "entry 3%" selama 81 s, lalu fase exact; di detik 274: "stop or continuous g4 92%" | sama; di detik 299: "stop or continuous g4" |
| `ENTRY → BASELINE_CORE_RUN` (1 core run) | **80,06 s** | **94,11 s** |
| Sesudahnya | pipeline exact penuh: family selesai detik 199, review detik 272, Stop-or-Continuous detik 274, masih berjalan | family selesai detik 201, review detik 299 |

Satu core run baseline yang sama, bila diukur tersendiri di CLI, hanya **0,77 s** (`tools/core1.php`). Jadi 80 s itu bukan hasil perhitungan core run.

## 2. Rantai penyebab (terbukti)

**a) Finalisasi Fastest berjalan di dalam hook core run pemilik.**
- Kandidat hard-valid pertama diklaim 0,8–1,1 s sesudah job mulai.
- Pemilik langsung menjalankan finalisasi (review Unit Priority + redistribusi C4 + gerbang) di dalam hook core run yang sedang berjalan. Di mesin uji itu terjadi saat baseline; di mesin pengguna sesudah fase `gas_window_correction`.
- Selama finalisasi, progres job tidak diperbarui. Karena itu label tertahan di fase pipeline terakhir: "entry 3%" di sini, dan "gas window correction 40%" di layar pengguna. 40% adalah bobot tetap fase itu (`worker_functions.php`, `gas_window_correction => 40.0`).

**b) Kandidat itu ditolak gerbang fully valid karena 4 temuan C4 tanpa bukti.**
- Review 79 s menghasilkan merit FAIL, C4 FAIL 48.
- Redistribusi C4 per row menurunkannya ke **4 FAIL**, tetapi seluruh redistribusi tidak diterapkan karena merit masih FAIL.
- Keempat temuan itu (CLI `tools/c4dbg.php`, deterministik) ada di row 28–31, unit G5:

  | Row | G3 / G2 / G5 (MW) | Legal headroom G8 / G9 (MW) |
  |---|---|---|
  | 28 | 31 / 31 / 31 | 11 / 11 |
  | 29 | 31 / 31 / 31 | 10 / 10 |
  | 30 | 31 / 31 / 31 | 8,55 / 9,25 |
  | 31 | 31 / 31 / 31 | 9,7 / 10,25 |

- Headroom penerima habis direncanakan untuk G3 dan G2. Karena itu **G5 tidak pernah masuk daftar pergeseran**, tidak pernah diuji, dan tidak punya bukti, padahal temuan C4-nya tetap ada di dispatch akhir.

**c) Kandidat ditolak, lalu job jatuh ke exact penuh.**
- Klaim dilepas dan job yang sama melanjutkan pipeline exact: pipeline 107 s, family 92 s, review 73 s, Stop-or-Continuous.
- Selama itu tidak ada hasil yang dapat ditampilkan, sehingga pengguna melihat proses tertahan.

**d) Review lambat: tugas samping tidak pernah diterbitkan di jalur Fastest.**
- Finalisasi Fastest menghapus `ppTlHook` ("tanpa hook abort").
- Empat penerbit tugas samping memakai `pp_v12_side_job()`, yang hanya membaca `ppTlHook`: `pp_v12_side_publish_fz` (counterfactual polish), `pp_v12_side_publish` (kandidat iso), `pp_v12_land_publish`, dan `pp_v12_side_mark`.
- Akibatnya penerbitan selalu batal diam-diam, dan pemilik menghitung sendirian 131 evaluasi polish (6 putaran, ±0,37 s per evaluasi).

**e) Follow PV (R3): transfer legal tertinggal.**
- Dengan SR per row = max(0, PV) sampai 24,15 MW, pencarian per row terhadap dispatch dasar yang sama menyisakan ruang legal sesudah hasilnya digabung. Tambahan 0,5 MW di row 29–31 dievaluasi engine **VALID**.
- C4 dengan benar tetap FAIL (transfer legal tetapi tidak dilakukan), sehingga hasil akhirnya VALID PROVISIONAL setelah lebih dari 400 s.
- Batas pengaman C4 (`sekarang + 60 s`) juga memotong tahap ini di UI (2 dari 4 lintasan), sehingga hasilnya bergantung waktu.

## 3. Yang diperiksa dan terbukti BUKAN penyebab

| Dugaan | Bukti |
|---|---|
| Import CSV mengubah input lain / 49 row / hari kedua | Payload sebelum dan sesudah upload (`assembleInput`) hanya berbeda di `pv_rows[*]`. 48 row: 00:30 (row 1) … 00:00 (row 48 = 06-Oct-26 00:00). `gas_quota`, Fixed Flow (`manual_fixed_flows` 0), `min_pgn_flow`, mode SR, target, dan `stop_mode` tidak berubah. Hash payload identik di XAMPP dan Linux (`be95bb81…`) |
| Akar masalah dari import | R2 tanpa upload juga hang di d6439e9, dan pada hotfix R1 = R2 (CP dan signature identik) |
| Loop gas-window correction | Fase `GAS_WINDOW_CORRECTION` selesai pada detik yang sama dengan `DECOMMIT_SCREENING` (87,79 s → 87,79 s; core_runs 19 → 19), sehingga tidak ada iterasi koreksi. Loop itu juga sudah punya penghenti siklus/no-movement |
| Redistribusi PGN otomatis berulang | Tidak terpicu: sertifikat tidak menemukan row terkendala, Manual Fixed Flow 0, PGN min hasil 3,0155 ≥ 3. Hanya ada satu POST `run` |
| MM2100 max flow 0 / KP72 Cummulative 4,6 | 0 = tanpa batas (tooltip UI). Kuota KP72 4,6 terpakai 4,5986, sehingga 92 temuan C4 GE/G10 sah sebagai `mm2100_account_full` |
| Kandidat identik dievaluasi berulang | Jejak polish: 131 evaluasi, 130 hash unik. Satu ulangan (`21193bf0…`, gabungan semua pergeseran) diambil dari cache (0,004 s) |
| Stop-or-Continuous exact fallback | Sudah dihapus di d6439e9 untuk mode Fastest |

## 4. Perbaikan (hanya penyebab terbukti)

**run.php**
1. `pp_v12_c4_rowwise` — **transfer legal sisa pada dispatch akhir.**
   - Setiap temuan C4 tersisa dicari transfer legal maksimumnya dengan binary search yang sama (`pp_v12_c4_row_search`), terhadap dispatch akhir.
   - Transfer diterapkan, lalu dispatch diaudit ulang (maks. 8 lintasan, berhenti bila tidak ada pergerakan).
   - Tercatat di `residual_legal_transfers`.
2. `pp_v12_c4_rowwise` — **bukti untuk temuan sisa.**
   - Temuan C4 yang tetap tanpa bukti di dispatch final diuji sendiri: tambahan ≤ 0,5 MW unit itu ke unit prioritas lebih tinggi, langsung dan dengan pendaratan window gas.
   - Bila keduanya tidak valid, temuan menjadi PASS_WITH_REASON dengan bukti numerik (`source = TEMUAN_SISA_DISPATCH_AKHIR`).
   - Bila valid, temuan **tetap FAIL**. Aturan C4 (ambang 0,5 MW, kategori, `rows_sig`) tidak diubah.
3. `pp_v8_priority_review` — batas pengaman C4 `max(dl, sekarang + 180 s)`, sebelumnya 60 s. Dengan begitu keputusan C4 ditentukan oleh jumlah evaluasi, bukan jam. A/C/D (C4 ≤ 20 s) tidak terpengaruh.
4. `pp_v12_fast_release_try` — progres job selama finalisasi:
   - `FASTEST_FINALISASI_REVIEW_KANDIDAT_PERTAMA` (50%);
   - `FASTEST_FINALISASI_MERIT_C4` (75%);
   - `FASTEST_FINALISASI_GERBANG_FULLY_VALID` (90%).
5. Diagnosa (tidak mengubah keputusan):
   - `timeline_s` (klaim, review r1/r2/polish/sweep, C4 mulai/selesai, gerbang) dan `polish_trace` (per evaluasi: putaran, hash kandidat, valid, CP, durasi) di `jobs/<job>/v12_fast_ready.json`;
   - `Unit Priority Polish.trace`.

**worker02.php**

6. Keempat penerbit tugas samping di atas kini memakai `pp_v12_side_job_any()`, seperti `publish_vz`/`publish_rv`. Pembantu menghitung ke cache berkunci input, sehingga hasilnya bit-identik; yang berubah hanya siapa yang menghitung.
7. Log aktivitas pembantu `jobs/<job>/side_log.txt` (pid, jenis tugas, mulai/selesai).

## 5. Bukti perbaikan pada kandidat yang sama

CLI `tools/rvcmp.php` menjalankan kandidat yang diklaim d6439e9 (dari `v12_fast_reject.json`) dengan review yang sama:

| Source | C4 sebelum → sesudah | Redistribusi | CP | Waktu (1 proses) |
|---|---|---|---|---|
| d6439e9 | 48 → 48 | ditolak | — | 109,9 s |
| hotfix | 48 → **0** | **diterapkan**: 6 row transfer legal + 1 transfer legal sisa (row 27, G3 3,43 MW), 25 row PASS_WITH_REASON, 4 bukti temuan sisa (G5 row 28–31) | 97,2557 | 134,0 s |

Contoh bukti temuan sisa (CLI `tools/c4dbg.php`, row 28): tambahan 0,5 MW G5→G8 membuat gas 71,3008 → 71,3635 BBTUD, di luar window [71,28, 71,32]. Kandidat ini juga melanggar EXPORT, RAMP, dan MM2100_QUOTA, baik langsung maupun dengan pendaratan.

Hasil UI sesudah perbaikan ada di R1_R4_RESULTS.md.
