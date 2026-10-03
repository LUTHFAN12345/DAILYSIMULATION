# Fastest, Distillate, dan Penyimpanan Data — laporan FINAL

Branch `claude/gifted-ritchie-q0hfdp`, commit source beku `940af0a`. Root ZIP: run.php, worker02.php, worker_functions.php, index.php, saved_data_store.php.

## 1. Akar masalah selisih 7 detik (uji internal) vs 30-44 detik (UI nyata)

Diukur di browser nyata (klik Run -> tab Simulation Data aktif) dengan timestamp server + browser pada jam yang sama. Temuan, berurutan menurut dampak:

1. **Gerbang fully valid Fastest lebih ketat daripada definisi FINAL kanonik.** Pada state ber-Actual (rencana nyata pengguna: basis FINAL + data Actual), provenance bahan bakar bernilai `PASS_WITH_NOTE` (tanpa pelanggaran; catatan selisih identitas akun pada row Actual) — FINAL Maximum Review dengan status yang sama dirilis. Gerbang Fastest hanya menerima `PASS`, sehingga kandidat fully valid pertama DITOLAK dan Fastest jatuh ke pencarian exact penuh: terukur 22,8 s, 57 kandidat diperiksa / 28 valid (pola "18-20 diperiksa / 5-13 valid, 30-44 s" pada mesin pengguna). Diperbaiki: `PASS` atau `PASS_WITH_NOTE` (tanpa pelanggaran) — sama persis dengan FINAL yang dirilis. Sesudahnya: lihat tabel "State ber-Actual" di bagian 2 (source beku final).
2. **Kandidat jangkar kanonik ikut diklaim.** Pada rute Actual, job lebih dulu menghitung jangkar kanonik (state tanpa Actual, desain path-independence V9); kandidatnya masuk kolam jangkar dengan pemilik job yang sama dan dapat diklaim Fastest padahal bukan kandidat state job. Kini hanya kandidat kolam milik state job yang diklaim.
3. **Pekerja pembantu tidak efektif di mesin pengguna.** Laporan pengguna V3 ("3 kandidat diperiksa, 2 valid", 29,3 s) identik dengan jejak run TANPA pembantu (direproduksi: 3 / 2). Penyebab yang ditemukan di kode: di Windows `rename()` gagal bila berkas tujuan sedang dibuka proses lain; penulisan atomik lama menghapus tujuan lalu rename SATU kali tanpa ulang dan gagal diam-diam — work.json pembantu (dibaca tiap 60 ms), kolam kandidat, tugas samping, dan snapshot Fastest dapat hilang. Kini satu fungsi penggantian atomik (rename diulang s.d. ~1,5 s, tujuan tidak dihapus lebih dulu, diagnostik ulang/gagal ditampilkan di Detail audit).
4. **Biaya per request tanpa OPcache** (XAMPP bawaan): setiap poll mengompilasi ~2,2 MB PHP (60-140 ms di mesin uji). Poll job_poll diturunkan ke 600 ms selama Fastest (rilis dibaca dari satu poll 200 ms).
5. **Finalisasi merit** (review Unit Priority kandidat pertama: 3-4 putaran counterfactual) adalah biaya inti yang tidak boleh dilewati (bukti C2/C3). Audit tidak lagi dihitung dua kali; batch review selebar jumlah proses hanya pada Fastest.

Angka 7 detik sebelumnya adalah waktu UI nyata pada mesin uji 4 inti untuk state tanpa Actual; pada state ber-Actual gerbang (butir 1) membuatnya jatuh ke exact penuh, dan pada mesin tanpa pembantu efektif (butir 3) seluruh pencarian + review berjalan serial — keduanya menjelaskan 30-44 s.

## 2. Fastest — browser nyata (Chromium), source beku final, 3 run per kasus (median / min / maks, detik dari klik Run sampai Simulation Data aktif)

| kasus | hasil | median | min | maks | first_fully_valid -> simulation_data_opened (maks) | diperiksa / valid | CP | Heat Rate | hard | merit | C4 | STG |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PGN25 PEP30 KP0 | GAS_SHORTAGE_DECISION (tidak ada kandidat fully valid) | 5.96 | 5.87 | 6.68 | - | - / - | - | - | None | - | None | None |
| PGN29 | FASTEST VALID PLAN | 5.36 | 5.32 | 5.89 | 0.45 s | 11 / 7 | 63.8518 | 8237.75 | PASS | PASS | 0 | 144/144 langgar 0 |
| PGN30 | FASTEST VALID PLAN | 8.81 | 8.20 | 8.81 | 0.48 s | 16 / 10 | 63.7129 | 8192.75 | PASS | PASS | 0 | 144/144 langgar 0 |
| PGN31 | FASTEST VALID PLAN | 7.77 | 6.96 | 8.03 | 0.43 s | 15 / 10 | 63.5711 | 8148.58 | PASS | PASS | 0 | 144/144 langgar 0 |
| PGN32 | FASTEST VALID PLAN | 7.64 | 7.45 | 8.77 | 0.64 s | 13 / 8 | 63.4469 | 8109.13 | PASS | PASS | 0 | 144/144 langgar 0 |

Penghitung diperiksa / valid mencakup kandidat yang diselesaikan pekerja pembantu SECARA PARALEL selama finalisasi merit kandidat pertama (review Unit Priority, ~3 s); Fastest tidak menunggu mereka — hasil yang tampil adalah kandidat fully valid pertama yang diklaim, dan pembantu dibatalkan sesudah tab Simulation Data aktif.

PGN 25 + PEP 30 + KP72 0 adalah kekurangan gas nyata: tidak ada kandidat fully valid tanpa aksi bahan bakar operator; Fastest menampilkan keputusan Gas Shortage tanpa mempublish hasil invalid.

### Timestamp server + browser (satu run per kasus, detik sejak klik Run; selisih dari tahap sebelumnya dalam kurung)

| kasus | run_click | job_created | first_candidate_complete | first_valid_claimed | first_fully_valid | snapshot_persisted | browser_received_snapshot | render_start | render_done | simulation_data_opened | exact_cancel_sent | finalisasi merit (review / audit / gerbang) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PGN29 | 0.00 | 0.28 (+0.28) | -0.15 (+-0.43) | 1.29 (+1.44) | 4.91 (+3.61) | 4.91 (+0.00) | 5.10 (+0.18) | 5.10 (+0.00) | 5.21 (+0.11) | 5.36 (+0.15) | 5.36 (+0.00) | 3.596 / 0.017 / 0 s |
| PGN30 | 0.00 | 0.28 (+0.28) | 0.07 (+-0.22) | 1.47 (+1.40) | 7.93 (+6.46) | 7.93 (+0.00) | 8.01 (+0.07) | 8.01 (+0.00) | 8.11 (+0.10) | 8.20 (+0.10) | 8.20 (+0.00) | 6.443 / 0.019 / 0 s |
| PGN31 | 0.00 | 0.29 (+0.29) | 0.47 (+0.18) | 1.60 (+1.13) | 7.34 (+5.74) | 7.34 (+0.00) | 7.41 (+0.07) | 7.41 (+0.00) | 7.55 (+0.14) | 7.77 (+0.22) | 7.77 (+0.00) | 5.719 / 0.017 / 0 s |
| PGN32 | 0.00 | 0.30 (+0.30) | 0.15 (+-0.14) | 1.77 (+1.62) | 7.04 (+5.28) | 7.05 (+0.00) | 7.19 (+0.14) | 7.19 (+0.00) | 7.30 (+0.11) | 7.45 (+0.15) | 7.45 (+0.00) | 5.258 / 0.017 / 0 s |

first_candidate_complete dibaca dari mtime berkas counter (resolusi 1 s). Timestamp yang sama ditampilkan pada panel Detail audit hasil Fastest di UI pengguna, sehingga jeda di mesin pengguna dapat dibaca langsung.

### State ber-Actual (rute inkremental, basis FINAL BASE_ACT10, PGN Pipe +1) — browser nyata

| versi | hasil | waktu UI median / min / maks | klaim kandidat pertama | review Unit Priority | diperiksa / valid | CP | catatan |
|---|---|---|---|---|---|---|---|
| sebelum perbaikan gerbang | FINAL OPTIMAL | 22.78 s (1 run) | - | - | 56 / 28 | 63.8609 | gerbang menolak provenance PASS_WITH_NOTE -> jatuh ke exact penuh |
| source beku FINAL | FASTEST VALID PLAN | 18.63 / 16.51 / 19.64 s (6 run) | 12.65-15.97 s | 3.04-3.87 s | 48 / 25 | 63.8492 | klaim menunggu FINAL jangkar kanonik (state tanpa Actual, ~10 s) — desain path-independence V9; merit PASS, C4 0, STG 144/144 |
| A/B: source Fastest sebelum Distillate/penyimpanan/C4 (dijalankan bergantian) | FASTEST VALID PLAN | 17.47 / 17.20 / 18.34 s (3 run) | 13.68-14.82 s | 2.93-3.16 s | 49 / 25 | 63.8492 | selisih median dengan source final berada di dalam sebaran run (mesin 4 inti) |

## 3. Distillate dipusatkan ke satu unit + persen per sel

State PGN 25 + PEP 30 + KP72 0 dengan aksi Distillate (job HTTP nyata, engine beku).

| versi | alokasi per unit (liter) | sel Distillate | CP (USD/MWh) | rilis | hard | provenance | rekonsiliasi aksi |
|---|---|---|---|---|---|---|---|
| sebelum | G1 130.931,7 + G5 709,5 (G5 30 % satu row walau G1 masih punya row eligible) | G1 30, G5 1 | 77,7918 | PASS | PASS | PASS | PASS |
| sesudah | G1 131.886 (satu unit) | G1 31 (row 19-48 100 %, row 1 30 %) | 77,8218 (+0,04 %) | PASS | PASS | PASS | PASS |

Aturan generik (tanpa nama unit): urutan unit = prioritas distillate dari input (unit berjalan saja); pass greedy berpindah ke unit berikutnya HANYA bila unit saat ini habis (setiap slot eligible 100 %); sisa yang lebih kecil dari langkah diskret terkecil diserahkan ke langkah penutup eksak yang kini bertingkat: unit yang sudah membawa distillate lebih dulu, unit berikutnya hanya bila tidak ada kombinasi di dalam pita window gas. Level diskret engine (30/50/75/100 %) tidak diubah.
Setiap sel campuran membawa data numerik dari engine: `DistPct_<unit>`, `GasPct_<unit>` (= 100 - distillate), `Dist_<unit>` (liter/slot), `DistFlow_<unit>` (liter/jam), `GasBBTU_<unit>`, `GasFlow_<unit>` (MMSCFD), `FuelSrc_<unit>`. Warna: gradasi biru proporsional persen (0 % tanpa biru, 100 % biru paling tua); tooltip hover dibangun dari angka tersebut.

### Distillate per sel — warna & tooltip

| id | uji | hasil | bukti |
|---|---|---|---|
| D1 | alokasi Distillate dipusatkan: satu unit bila satu unit cukup (hasil engine nyata) | LULUS | {"cells":31,"units":{"G1":31},"engine_units":{"G1":31},"perUnitL":{"g1":131886}} |
| D2-30 | hover sel G1 08-Jul-26 00:30 (30% Distillate): tooltip = angka output (Distillate %, Gas %, liter, gas flow, unit, waktu) | LULUS | {"tip":"Distillate : 30% / Gas        : 70% / Distillate : 1,246.2 l/slot (2,492.4 l/jam) / Gas flow   : 4.6561 MMSCFD (0.1048 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 00:30 / Sumber     : DISTILLATE+GAS_JABABEKA","output":{"pct":30,"gas":70,"l":1246.2,"flow":4.6561,"bg":"rgb(187, 202, 243)"}} |
| D2-100 | hover sel G1 08-Jul-26 09:30 (100% Distillate): tooltip = angka output (Distillate %, Gas %, liter, gas flow, unit, waktu) | LULUS | {"tip":"Distillate : 100% / Gas        : 0% / Distillate : 5,511.4 l/slot (11,022.8 l/jam) / Gas flow   : 0 MMSCFD (0 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 09:30 / Sumber     : DISTILLATE","output":{"pct":100,"gas":0,"l":5511.4,"flow":0,"bg":"rgb(29, 78, 216)"}} |
| B-100/0 | render 100% Distillate / 0% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(29, 78, 216)","luminance":77.5462,"tip":"Distillate : 100% / Gas        : 0% / Distillate : 4,153.9 l/slot (2,492.4 l/jam) / Gas flow   : 0 MMSCFD (0.1048 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 00:30 / Sumber     : DISTILLATE+GAS_JABABEKA"} |
| B-75/25 | render 75% Distillate / 25% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(86, 122, 226)","luminance":121.85519999999998,"tip":"Distillate : 75% / Gas        : 25% / Distillate : 3,115.4 l/slot (— l/jam) / Gas flow   : 1.6625 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 01:00"} |
| B-50/50 | render 50% Distillate / 50% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(142, 167, 236)","luminance":166.6668,"tip":"Distillate : 50% / Gas        : 50% / Distillate : 2,077 l/slot (— l/jam) / Gas flow   : 3.325 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 01:30"} |
| B-40/60 | render 40% Distillate / 60% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(165, 184, 239)","luminance":183.9316,"tip":"Distillate : 40% / Gas        : 60% / Distillate : 1,661.6 l/slot (— l/jam) / Gas flow   : 3.99 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 02:00"} |
| B-25/75 | render 25% Distillate / 75% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(199, 211, 245)","luminance":210.90359999999998,"tip":"Distillate : 25% / Gas        : 75% / Distillate : 1,038.5 l/slot (— l/jam) / Gas flow   : 4.9875 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 02:30"} |
| B-0/100 | render 0% Distillate / 100% Gas: tanpa biru | LULUS | {"bg":null,"luminance":null,"tip":null} |
| B-mono | gradasi: makin tinggi persen Distillate makin gelap (100% paling tua) | LULUS | [[100,77.5462],[75,121.85519999999998],[50,166.6668],[40,183.9316],[25,210.90359999999998],[0,null]] |
| D9 | tidak ada JavaScript error | LULUS |  |

11/11 LULUS


## 4. Penyimpanan data: `saved_data_store.php`

- Satu-satunya modul yang menulis data pengguna: berkas kerja `input_data.json` / `output_data.json` (Save, auto-save sebelum Run, rilis) dan record Report. run.php/index.php memanggil modul ini; tidak ada source lain yang menulis berkas penyimpanan.
- Lokasi: `<data>/saved/records/<record_id>.json`, `<data>/saved/state/input_data.json` (cermin keadaan kerja), `<data>/backups/...` (versi sebelum ditimpa/dihapus, maks. 30 per berkas). `<data>` = env `PP_DATA_DIR` atau `<folder aplikasi>/data` — tidak ada di paket deploy, sehingga tidak tertimpa.
- Record: record_id, schema_version (2), created_at, updated_at, plan_type (Actual/Planning/Monitoring/Weekly), name, plan_date, input_payload, saved_result, engine_version_at_save (informasi; record selalu dijalankan dengan engine terbaru), checksum sha256.
- Tulis atomik: lock -> berkas sementara -> fflush + fsync -> validasi baca-ulang -> backup versi lama -> rename (diulang, tahan Windows). API: store_list / store_load / store_meta / store_delete (wajib confirm=record_id) / store_integrity.
- Migrasi: record v1 (bentuk report_planning lama tanpa skema) dibuka sebagai v2; record lama tanpa checksum dilaporkan "legacy, tidak dapat diverifikasi" (bukan rusak). input_data.json yang hilang/rusak dipulihkan dari cermin saat halaman dibuka.
- **Korupsi diam-diam yang ditemukan & diperbaiki:** `pp_sanitize_report_planning` (run.php) memperlakukan bentuk `records` sebagai bentuk bersarang lama dan, lewat referensi PHP, menyisipkan kunci palsu `snapshot: {input: {data3: {modeling: null}}}` ke dalam input setiap rencana tersimpan pada setiap Save. Kini setiap bentuk dikenali tanpa membuat kunci; artefak yang sudah tersimpan dibersihkan (hanya bentuk persis artefak itu).

### saved_data_store.php — uji penyimpanan

Akar uji: salinan source di direktori sementara, server PHP 7.4 nyata (HTTP), data di <akar>/data.

| id | uji | hasil | bukti |
|---|---|---|---|
| S1 | Save data Actual dan Planning (tombol Save) -> record tersimpan di data/saved/records | LULUS | {"save":{"records":{"written":3,"unchanged":0,"failed":0},"root":"/tmp/claude-0/storetest_10210/data"},"types":{"Actual":1,"Monitoring":1,"Planning":1}} |
| S2 | source engine diubah (fingerprint engine berubah) | LULUS | ffeeee2ea7c7343a -> 36c82633dfd70043 |
| S3 | daftar Report tetap ada setelah revisi source (record & checksum sama) | LULUS | {"n":3,"checksum_ok":true} |
| S4 | record lama dibuka: input_payload identik dengan yang disimpan, checksum valid | LULUS | {"checksum_ok":true,"engine_at_save":"run.php:ceb95f6269b8 worker02.php:135fb9499cc8 worker_functions.php:cc39968b2245"} |
| S5 | record lama dijalankan dengan engine TERBARU (fingerprint hasil = fingerprint source revisi), 48 row | LULUS | {"engine_fingerprint_result":"36c82633dfd70043","engine_now":"36c82633dfd70043","engine_at_save":"run.php:ceb95f6269b8 worker02.php:135fb9499cc8 worker_functions.php:cc39968b2245","cp":63.4469} |
| S6 | record asli tidak berubah setelah dibuka + dijalankan (tanpa Save eksplisit) | LULUS | b935bf4da3779cf91c284a5da9b0d248a15c9b17ca72b287ae2c97846484bce6 = b935bf4da3779cf91c284a5da9b0d248a15c9b17ca72b287ae2c97846484bce6 |
| S7 | tulis gagal (write/validate/rename) -> Save ditolak, berkas lama utuh & JSON valid, tanpa berkas sementara tersisa | LULUS | {"write":{"result":{"saved":false,"why":"tulis terpotong (313 dari 627 byte)"},"intact":true,"json_valid":true,"tmp_left":0},"validate":{"result":{"saved":false,"why":"validasi baca-ulang berkas sementara gagal"},"intact":true,"json_valid":true,"tmp_left":0},"rename":{"result":{"saved":false,"why":"rename atomik gagal (berkas tujuan dikunci / permission)"},"intact":true,"json_valid":true,"tmp_left":0}} |
| S8 | dua Save bersamaan (2 proses x 25): record berbeda keduanya ada; record sama utuh (satu penulis, tidak tercampur), checksum valid | LULUS | [{"name":"Bersamaan sama","ok":true,"writer":"1","i":24,"pad_ok":true},{"name":"Bersamaan beda 1","ok":true,"writer":"1","i":24,"pad_ok":true},{"name":"Bersamaan beda 2","ok":true,"writer":"2","i":24,"pad_ok":true}] |
| S9 | hapus tanpa konfirmasi ditolak; metadata diperbarui tanpa mengubah checksum isi | LULUS | {"delete":{"ok":false,"error":"KONFIRMASI_DIPERLUKAN: kirim confirm=rec_c2afe46d5e7e8f79fefd9a67"},"meta":{"ok":true,"record_id":"rec_c2afe46d5e7e8f79fefd9a67","meta":{"catatan":"diperiksa"}}} |
| S10 | hapus dengan konfirmasi: record dipindah ke backups/deleted (dapat dipulihkan) | LULUS | {"delete":{"ok":true,"error":null},"backup":["20261003_135136_d88911_rec_c2afe46d5e7e8f79fefd9a67.json"]} |
| S11 | migrasi skema: record v1 lama dibuka sebagai v2 (input_payload dari snapshot), checksum dihitung | LULUS | {"schema":2,"migrated_from":1} |
| S12 | input_data.json hilang (mis. folder aplikasi diganti) -> dipulihkan dari cermin data/saved/state saat halaman dibuka | LULUS | {"restored":true} |
| S13 | pemeriksaan integritas penyimpanan | LULUS | {"ok":true,"records":6,"checksum_fail":[],"legacy_v1_unverified":["rec_legacyv1"],"state_mirror_ok":true,"root":"/tmp/claude-0/storetest_10210/data","status":"PASS"} |

13/13 LULUS


## 5. Merit C4: state yang sebelumnya tidak pernah FINAL (P03, P07, T8)

Targeted pertama atas source beku menunjukkan 3 FAIL yang sudah ada sejak gerbang C4 dipasang (checkpoint integrasi: 316 PASS / 3 FAIL): P03 (WB09 + Spinning Reserve Min 20), P07 dan T8 (WB09, G1 stop 10:00). Dispatch-nya diblokir gerbang merit `V12_MERIT_DISPATCH_TANPA_BUKTI` karena C4: unit grup prioritas rendah dibebani di atas minimum sementara unit grup lebih tinggi yang berjalan masih punya legal headroom. Definisi C4 dan gerbang TIDAK diubah.

**Akar masalah:** polish Unit Priority (V6) memang menguji pergeseran itu, tetapi (a) counterfactual-nya tidak didaratkan ke window gas, sehingga pergeseran yang lebih murah ditolak sebagai `COUNTERFACTUAL_TIDAK_VALID:gas_quota`; (b) hanya mencoba pergeseran penuh dengan keluaran blok tetap, sehingga row dengan headroom unit tinggi lebih kecil dari beban di atas minimum unit rendah ditandai `HEADROOM_TIDAK_CUKUP` tanpa mencoba pergeseran sebatas headroom. Hasilnya unit tinggi tidak dinaikkan sejauh legal (kriteria FAIL merit).

**Perbaikan (logika merit langkah 5-7 dan 13-15, `pp_v12_c4_redistribute`):** hanya bila pemenang review gagal C4 — hasil seperti itu tidak pernah dirilis, sehingga setiap state yang lolos C4 tidak tersentuh (fungsi mengembalikan null; keluaran byte-identik; biaya pemeriksaan 2,6 ms). Per row, beban di atas minimum unit rendah dipindah ke legal headroom C4 unit berjalan berprioritas lebih tinggi (grup lalu rank Unit Priority); commitment tidak berubah; STG, bahan bakar, gas, Export, reserve, Bus Flow dihitung ulang engine 48 row; window gas didaratkan bila perlu; lanjutan polish Unit Priority seperti pada review. Diterima hanya bila valid penuh terhadap input asli dan merit C2/C3/C4 PASS. Tanpa nama unit; tidak ada constraint dilonggarkan.

| state | sebelum | sesudah | MW dipindah | evaluasi |
|---|---|---|---|---|
| P07 T8 WB09 G1STOP | diblokir (V12_MERIT_DISPATCH_TANPA_BUKTI), CP 64.5005, HR 8349.83, C4 tanpa alasan 5 [{"row": 28, "unit": "G5", "mw_above_min": 6.5, "higher_priority_legal_headroom": {"G8": 2.27, "G9": 2.27}}, {"row": 29, "unit": "G5", "mw_above_min": 6, "higher_priority_legal_headroom": {"G8": 2.53, "G9": 2.53}}] | FINAL, CP 64.4089, HR 8328.71, merit PASS (C2 0 / C3 0 / C4 0), HPA PASS_WITH_REASON / 0, provenance PASS, hard PASS, Export OK | 33.1 | INVALID:GAS_WINDOW, pendaratan gas VALID |
| P03 WB09 RESERVE20 | diblokir (V12_MERIT_DISPATCH_TANPA_BUKTI), CP 64.7133, HR 8398.07, C4 tanpa alasan 1 [{"row": 23, "unit": "G2", "mw_above_min": 5.5, "higher_priority_legal_headroom": {"G8": 3.75, "G9": 3.75}}] | FINAL, CP 64.6860, HR 8391.53, merit PASS (C2 0 / C3 0 / C4 0), HPA PASS_WITH_REASON / 0, provenance PASS, hard PASS, Export OK | 5.5 | INVALID:GAS_WINDOW, pendaratan gas VALID, polish lanjutan {"evaluations": 1, "shifts": 1, "cp_after": 64.686, "applied": true} |

CP kedua state turun (bukan naik) — dispatch prioritas lebih tinggi juga lebih murah setelah window gas didaratkan. Paritas: 12 merit state identik dengan checkpoint (cold, tanpa pembantu, warm) dan dua smoke Change Over identik dengan V12 checkpoint — lihat bagian Uji.

### Paritas merit dispatch 12 state vs checkpoint integrasi (merit PASS)

Referensi: run cold checkpoint integrasi (source merit `a905f10`). Baru: source beku final. Dibandingkan: tanda tangan dispatch fisik 48 row, CP, Heat Rate — untuk cold, tanpa pembantu, dan warm.

| state | ref sig / CP / HR | cold | tanpa pembantu | warm | hasil |
|---|---|---|---|---|---|
| BASE_PGN30 | 1c2e009adeae / 64.6644 / 8386.61 | identik | identik | identik | PASS |
| WB09_3 | fdd9c3c6bb71 / 63.4469 / 8109.13 | identik | identik | identik | PASS |
| WB09 | 4dfea9ac8a43 / 64.3644 / 8319.17 | identik | identik | identik | PASS |
| Q_pep_kp72_1 | e8ff268c979f / 64.631 / 8382.58 | identik | identik | identik | PASS |
| OPS:pgn_pipe=33;mj=spinning_reserve_min:5 | d7c5d4eaf249 / 64.4728 / 8296.78 | identik | identik | identik | PASS |
| OPS:pgn_pipe=30;mj=busflow_min:22 | 1c2e009adeae / 64.6644 / 8386.61 | identik | identik | identik | PASS |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":26,"max":155,"r | 7dfc9e57cbf6 / 64.273 / 8253.06 | identik | identik | identik | PASS |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":20,"max":155,"r | 698009db6c76 / 64.4309 / 8287.94 | identik | identik | identik | PASS |
| OPS:pgn_pipe=30;mj=required_mode:{"g1":{"mode":"continuous"},"g8":{"mo | f2407cc397dc / 64.7788 / 8412.58 | identik | identik | identik | PASS |
| Q_pgn_pipe_4 | 3ab7b223502f / 64.1256 / 8207.16 | identik | identik | identik | PASS |
| Q_pep_1 | c7092c1c03cb / 64.4253 / 8336.79 | identik | identik | identik | PASS |
| ACT_PGN_UP | b80c1651bcac / 64.6801 / 8387.69 | identik | identik | identik | PASS |

12/12 state identik pada ketiga mode.


### Smoke Change Over vs V12 checkpoint (sim/sim feasible, cold)

| kasus | V12 checkpoint sig / CP / HR | source final sig / CP / HR | hasil |
|---|---|---|---|
| WB09_3_B2B1_SIMSIM_COLD | f35134e744d3 / 63.9304 / 8212.23 | f35134e744d3 / 63.9304 / 8212.23 | IDENTIK |
| WB09_3_B1B2_SIMSIM_COLD | d9682af1e648 / 63.8636 / 8197.81 | d9682af1e648 / 63.8636 / 8197.81 | IDENTIK |

## 6. Uji

| suite | PASS | FAIL | log |
|---|---|---|---|
| Targeted (source beku) | 360 | 0 | targeted.log |
| Full regression (source beku) | 395 | 0 | regression.log |
| Targeted dari ZIP hasil clean-extract (ZIP pra-final; lima source PHP byte-identik dengan ZIP final dan freeze — ZIP final hanya menambah laporan clean-extract) | 360 | 0 | clean_extract.log |

Termasuk suite integrasi: merit dispatch 12 state (22 butir + kriteria FAIL + bukti STG calc_stg per row + C4), smoke Change Over B2->B1 dan B1->B2 (identik V12 checkpoint), Fastest UI (CO OFF/ON) dan 5 input, Distillate UI, saved_data_store.

