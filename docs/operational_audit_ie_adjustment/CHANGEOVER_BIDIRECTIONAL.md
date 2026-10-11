# Change Over Block 1 ⇄ Block 2: audit dua arah (V15.17)

## Ringkasan status

| Aspek | Status |
|---|---|
| Functional | **PASS**: kedua arah (Block 1 → Block 2 dan Block 2 → Block 1) menyelesaikan handover dan target running sampai row 48. |
| Validation | **PASS**: hard validation PASS dan release gate PASS untuk CO0–CO7 dan IE15. C1–C4 FAIL = 0, STG PASS, dan kontinuitas Distillate PASS. |
| Quality | **PASS**: comparator Change Over memilih CP terendah di antara kandidat yang fully valid. Polish Unit Priority dijalankan pada pemenang. |
| Performance | **PASS**: total dua langkah (rekomendasi → keputusan bahan bakar) 1,8–12,3 s di CLI. Target sulit ≤ 30 s. |
| Release blocker | Tidak ada. |

## Kondisi sebelum perbaikan

Sebelum perbaikan, CO0–CO7 dan IE15 **FAIL** dalam kurang dari 1 s. Kode freeze V15.16 menunjukkan perilaku yang sama, jadi masalahnya sudah ada sebelum rilis ini. Pada CO1 (G1/S2 Running → G4/S1), mode yang muncul adalah `CHANGE_OVER_HANDOVER_COMPLETE_BUT_EXPORT_FLOOR_UNREACHABLE` dengan Export row 28–31 sebesar 14,75 / 15,47 / 17,26 / 19,89 MW, di bawah Range Min 25 MW.

Selain itu ada cacat di UI: Change Over tidak pernah benar-benar terkirim. `buildChangeOverObj()` dipanggil sebelum `coInit()`, sehingga payload berisi `enabled:false`. Akibatnya "CO smoke" di rilis-rilis sebelumnya tidak pernah menguji Change Over.

## Akar penyebab

1. **Generator kandidat sim/sim hanya memilih start terlambat.** Generator mengambil dua event row terakhir, misalnya row 31 dan 33. Akibatnya armada source saja harus memikul puncak demand row 28–31, dan Export jatuh di bawah Range Min. Defisit itu terjadi sebelum target start, sehingga tidak ada kandidat yang bisa lolos.
2. **Start command hanya berfungsi sebagai batas paling awal.** Engine tetap menunda start target sampai beban membutuhkannya. Pada Follow PV (CO3), kebutuhan Spinning Reserve row 18–23 (20–24 MW) tidak memicu start lebih awal.
3. **Rekomendasi LNG langkah 1 diambil dari kandidat yang tidak layak Export.** Kandidat pemenang setelah LNG ditambahkan (start lebih awal) membakar gas lebih banyak, sehingga langkah 2 gagal tipis pada kuota gas. Pada CO0 kelebihannya 0,0867 BBTUD.
4. **Audit merit tidak mengenal status paksa Change Over.**
   - C3 menandai GTG target dan source pada beban minimum selama overlap tanpa alasan.
   - Audit STG tidak memodelkan jeda release STG cold-start (7 row). Akibatnya S2 = 0 selama start-up dilaporkan sebagai mismatch palsu.
5. **Polish Unit Priority Change Over lambat.** Polish memakai deadline +60 s dan evaluator `pp_run_simulation_core`, yang butuh 4–5 s per evaluasi batch. Total CO3 mencapai 45 s.

## Perbaikan (generik, tanpa hardcode tanggal, unit, atau skenario)

| # | Lokasi | Perubahan |
|---|---|---|
| 1 | `index.php` | `CO_INITED`: `buildChangeOverObj()` mengembalikan `change_over` dari input sampai `coInit()` selesai. Payload UI kini benar-benar berisi `enabled:true` beserta blok. |
| 2 | `worker02.php` `pp_changeover_sim_sweep` | **Export/SR-deficit refinement.** Bila tidak ada kandidat eligible, row defisit kapasitas (Export < Range Min atau SR kurang) dari kandidat terbaik dipakai untuk menurunkan start = row defisit pertama − {2, 3, 5}. Setiap start dicoba dalam dua varian: bebas dan *forced*. Comparator dan validasi penuh tetap sama, dan tidak ada kandidat lama yang dihapus. Bisa dimatikan dengan `PP_CO_DEFICIT_REFINE=0`. |
| 3 | `worker_functions.php` `pp_normalize_change_over` | Varian *forced* (`change_over_force_target_start`, hanya di clone kandidat): target mengikuti start-up sequence tepat pada start command (`required_mode start_at`). |
| 4 | `worker02.php` | Putaran ke-2: bila defisit masih ada tepat setelah source berhenti, stop source digeser lebih akhir (overlap lebih panjang). Ada juga varian sibling blok target boleh di-commit. |
| 5 | `worker02.php` (sebelum keluar dari sweep) | **What-if LNG.** Pada langkah rekomendasi, sweep yang sama dijalankan dengan `add_lng = shortage`, lalu residual ditambahkan sampai rencana fully valid (maks. 3 iterasi). Hanya angka rekomendasi yang berubah. Bisa dimatikan dengan `PP_CO_LNG_WHATIF=0`. |
| 6 | `run.php` `pp_v11_frag_audit` | Alasan `STATUS_PAKSA:CHANGE_OVER_TARGET/SOURCE` hanya diberikan bila Change Over **dieksekusi**: target dari start command sampai akhir horizon, source sampai stop command. |
| 7 | `run.php` `pp_v15_merit_proof` | Gerbang release STG yang sama dengan engine (4d): feeder dalam jendela start-up cold/additional HRSG tidak berkontribusi. Golden tidak berubah (CP 79,9049, merit PASS). |
| 8 | `worker02.php` / `run.php` | Polish Change Over memakai evaluator single-pass (dispatch GTG dibekukan; validitas tetap dinilai penuh oleh `pp_tl_assess`), first-improvement, maks. 8 putaran, dan plafon 20 s. Bisa diatur lewat `PP_CO_POLISH_ONCE`, `PP_V6_FIRST_IMPROVE`, dan `PP_CO_POLISH_S`. |

## Hasil (CLI, alur dua langkah seperti UI)

Langkah 1 adalah rekomendasi. Langkah 2 adalah keputusan bahan bakar, dijalankan bila shortage terbukti.

| Kasus | Arah | Rek. LNG | Langkah 1 (s) | Langkah 2 (s) | Total (s) | Hard | Gate | CP | HR | Start/Stop cmd | Overlap STG | Unit |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CO0 | B1 → B2 | 0,3325 | 7,95 | 2,44 | 10,39 | PASS | PASS | 65,1851 | 8475,31 | 25 / 40 | 7 | G1 26–48, G4 1–39 |
| CO1 | B2 → B1 | 0 | 1,78 | – | 1,78 | PASS | PASS | 64,5875 | 8369,12 | 23 / 32 | 5 | G1 1–31, G4 26–48 |
| CO2 | B2 → B1, PV off | 0 | 1,76 | – | 1,76 | PASS | PASS | 64,5875 | 8369,12 | 23 / 32 | 5 | idem |
| CO3 | B2 → B1, Follow PV | 0,4862 | 5,65 | 6,66 | 12,31 | PASS | PASS | 65,1609 | 8456,33 | 16 / 34 | 16 | G1 1–33, G4 17–48 |
| CO4 | B2 → B1, gas shortage | 2,8281 | 3,93 | 2,78 | 6,71 | PASS | PASS | 65,5629 | 8388,01 | 23 / 33 | 6 | G1 1–32, G4 26–48 |
| CO5 | B2 → B1, trip G3 | 0 | 2,58 | – | 2,58 | PASS | PASS | 64,5875 | 8369,12 | 23 / 32 | 5 | – |
| CO6 | B2 → B1, stop request G5 | 0 | 2,64 | – | 2,64 | PASS | PASS | 64,5875 | 8369,12 | 23 / 32 | 5 | – |
| CO7 | B2 → B1, G9 continuous | 0 | 3,07 | – | 3,07 | PASS | PASS | 64,5875 | 8369,12 | 23 / 32 | 5 | – |
| IE15 | IE Adjustment + Change Over | 0,4316 | 4,18 | 2,39 | 6,57 | PASS | PASS | 64,9648 | 8416,22 | – | – | G1 1–31, G4 19–48 |

Untuk semua kasus di atas: C1–C4 FAIL = 0, STG PASS, dan kontinuitas Distillate PASS.

Catatan CO3 (Follow PV): sebelum perbaikan, kebutuhan SR row 18–23 tidak terpenuhi (SR 0,5–5,5 MW, kebutuhan 20–24 MW). Kandidat *forced* start row 16 membuat G4 online sejak row 17. Overlap STG 16 row diterima karena SR baru terpenuhi dengan kedua blok berjalan. Tidak ada kandidat yang lebih murah dan valid: kandidat start bebas dan kandidat start lebih akhir masing-masing gagal pada SR atau Export.

## Uji melalui UI nyata (browser, server XAMPP-like 6 worker PHP 7.4)

Payload UI kini benar-benar `change_over.enabled = true` beserta blok. Sebelumnya selalu `false` karena cacat init `CO_INITED`.

| Kasus | Arah | Alur | Klik → popup opsi tervalidasi (s) | Klik → FINAL (s) | Gate | CP | HR | Merit | CO dieksekusi |
|---|---|---|---|---|---|---|---|---|---|
| CO1 | B2 → B1 | satu langkah | – | 3,0 | PASS | 64,5875 | 8369,12 | PASS | ya |
| CO0 | B1 → B2 | rekomendasi → LNG 0,3325 | 22,7 | 24,9 | PASS | 65,1851 | 8475,31 | PASS | ya |
| CO3 | B2 → B1, Follow PV | rekomendasi → LNG 0,4862 | 25,8 | 27,9 | PASS | 65,1609 | 8456,33 | PASS | ya |
| CO4 | B2 → B1, gas shortage | rekomendasi → LNG 2,8281 | 13,7 | 15,8 | PASS | 65,5629 | 8388,01 | PASS | ya |

### Cacat UI tambahan yang ditemukan dan diperbaiki saat uji browser

Popup Gas Shortage untuk Change Over menampilkan "tidak ada residual gas shortage yang harus ditutup" dan **mengunci tombol LNG/Distillate**. Penyebabnya dua:

- Keluaran Change Over yang gagal karena gas tidak membawa `shortage_decision`.
- Kandidat probe ditolak karena `economic_review_completed = false`.

Perbaikannya:

- Opsi diambil dari what-if Change Over lalu divalidasi dengan rerun penuh.
- Comparator Change Over dicatat sebagai `Global Commitment Review`, dan dinilai tuntas bila tidak ada kandidat yang terlewati budget.

Langkah opsi tervalidasi (±14–20 s) didominasi validasi rerun penuh opsi LNG dan Distillate yang berjalan paralel. Total tetap ≤ 30 s, sesuai target kasus sulit.
