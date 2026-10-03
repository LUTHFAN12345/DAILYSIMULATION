# Fastest, Merit, Distillate, Datastore — V2 (laporan akar masalah 62 detik)

Branch `claude/gifted-ritchie-q0hfdp`, commit source beku `3b43955`. Input reproducer: `input_data.json` pengguna (PGN 31, PEP 30, KP72 0, Unit Priority G8/G9 > G1/G5/G2 > ...); varian PGN 30 = input yang sama dengan PGN Pipe 30. Pembanding hasil aktual: `Daily_Plan_09_Jul_26_Baru(9).xls`.

## 1. Akar masalah 62 detik (browser XAMPP) vs < 10 detik (uji internal)

Hasil xls pengguna = CP 64,7178 — identik dengan hasil Fastest source `940af0a` di mesin uji untuk input yang sama (CP, G2 26-37, G5 tidak running). Jadi mesin pengguna menjalankan jalur yang sama; selisih waktunya berasal dari lingkungan Windows/XAMPP dan dari pekerjaan sesudah klaim kandidat pertama. Label "TIME-LIMITED VALID PLAN" di xls BUKAN tanda fallback: keluaran Fastest membawa `time_limited=true`, dan header ekspor mencetak label itu untuk setiap hasil Fastest (diperbaiki: kini "FASTEST VALID PLAN").

Temuan, berurutan menurut dampak:

1. **pid dipakai sebagai identitas proses — salah di Apache Windows.** Di XAMPP (mod_php, mpm_winnt) seluruh request adalah thread dari SATU proses, sehingga `getmypid()` sama untuk pemilik job, pembantu, dan pengklaim. Akibatnya (a) pembantu menganggap tugas review yang diterbitkan pengklaim sebagai "tugas terbitan sendiri" dan tidak pernah mengambilnya — seluruh counterfactual review dihitung serial oleh satu thread; (b) pemilik pencarian exact tidak pernah berhenti setelah klaim (perbandingan pid selalu "saya sendiri"), sehingga pencarian exact terus berebut CPU dengan review; (c) berkas sementara result.json bernama pid saja. Diperbaiki dengan identitas request (`pp_req_id`). Diuji dengan kait `PP_EMU_PID` (semua request satu pid, meniru Apache Windows).
2. **Sesudah klaim, review merit Fastest masih mencari CP lebih baik.** Kandidat valid pertama input pengguna men-start G5 (sesuai Unit Priority). Review menerapkan stop pada row legal pertama (benar), lalu SWAP G5 -> G2 (unit prioritas LEBIH RENDAH) karena band CP 0,2 % memutus seri dengan Heat Rate sebelum Unit Priority: CP 0,0027 USD/MWh (0,004 %) lebih murah, Heat Rate 0,86 BTU/kWh lebih rendah. Setiap counterfactual adalah pendaratan dispatch 48 row (3,5-4,7 s per kandidat di mesin uji). Inilah sumber utama waktu dan sumber G2 start sebelum G5. Diperbaiki: (a) review Fastest = mode bukti — SWAP ke peer berprioritas lebih rendah (uji ekonomi, bukan bukti merit C2/C3) tidak dievaluasi; (b) di dalam band, Unit Priority start didahulukan sebelum Heat Rate (juga untuk Maximum Review).
3. **Pembantu terus mencari exact sesudah klaim** dan baru mengambil tugas review sekitar 3 s kemudian. Kini begitu klaim terlihat, pembantu meninggalkan pekerjaan exact pemilik dan hanya mengerjakan tugas yang diterbitkan pengklaim.
4. **Arsip Report (`report_planning`) 531 KB ikut di setiap salinan input kandidat** (input pengguna 2,4 MB pretty-print). Setiap evaluasi/tugas/hash menyalin arsip itu. Kini tidak dibawa ke input job (penulisan `input_data.json` tetap mengadopsi arsip dari disk — tidak ada data hilang).
5. **Datastore di jalur kritis Run:** auto-save sebelum setiap Run membuat backup + cermin `input_data.json` (2,4 MB x 3 tulis); keluaran internal juga di-backup. Kini backup + cermin hanya pada Save pengguna.
6. **Progres "hilang" ~5 s:** saat klaim, teks progres diganti pesan statis. Kini progres tetap menghitung detik selama bukti merit dihitung.

### PGN 31 + PEP 30 (input pengguna) — timeline browser nyata (Chromium, cold, detik sejak klik Run)

| source / lingkungan | run_click | job_created | first_candidate_complete | first_valid (klaim) | first_fully_valid | snapshot_written | browser_received | render_start | render_done | simulation_data_opened | exact_cancel_sent | helpers_stopped | waktu UI | diperiksa / valid | CP | Heat Rate | review merit |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| lama 940af0a, native 4 inti | 0.00 | 0.39 | 1.07 | 6.62 | 12.77 | 12.78 | 12.85 | 12.85 | 12.97 | 13.20 | 13.20 | - | 13.20 | 14 / 7 | 64.7178 | 8374.16 | 6.134 s |
| baru V2, native 4 inti | 0.00 | 0.36 | 0.96 | 6.11 | 10.17 | 10.18 | 10.26 | 10.26 | 10.37 | 10.48 | 10.48 | 10.77 | 10.48 | 12 / 6 | 64.7205 | 8375.02 | 4.038 s |
| lama 940af0a, emulasi Windows (1 pid, 2 inti, tanpa OPcache) | 0.00 | 0.20 | 1.96 | 20.18 | 26.98 | 26.99 | 27.10 | 27.10 | 27.22 | 27.43 | 27.43 | - | 27.43 | 23 / 8 | 64.7178 | 8374.16 | 6.745 s |
| baru V2, emulasi Windows (1 pid, 2 inti, tanpa OPcache) | 0.00 | 0.18 | 2.59 | 14.30 | 21.48 | 21.49 | 21.63 | 21.63 | 21.73 | 21.82 | 21.82 | 22.17 | 21.82 | 14 / 6 | 64.7205 | 8375.02 | 7.165 s |

| source | G1 | G2 | G5 | G8 | G9 | S2 | S3 | CP | Heat Rate |
|---|---|---|---|---|---|---|---|---|---|
| lama 940af0a | row 1-48 (48 row, maks 31 MW) | row 26-37 (12 row, maks 20 MW) | tidak running | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 33.93 MW) | row 1-48 (48 row, maks 121.02 MW) | 64.7178 | 8374.16 |
| baru V2 | row 1-48 (48 row, maks 31 MW) | tidak running | row 26-37 (12 row, maks 20 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 33.93 MW) | row 1-48 (48 row, maks 121.02 MW) | 64.7205 | 8375.02 |

### PGN 30 + PEP 30 (input pengguna) — timeline browser nyata (Chromium, cold, detik sejak klik Run)

| source / lingkungan | run_click | job_created | first_candidate_complete | first_valid (klaim) | first_fully_valid | snapshot_written | browser_received | render_start | render_done | simulation_data_opened | exact_cancel_sent | helpers_stopped | waktu UI | diperiksa / valid | CP | Heat Rate | review merit |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| lama 940af0a, native 4 inti | 0.00 | 0.42 | 1.14 | 3.03 | 12.12 | 12.12 | 12.16 | 12.16 | 12.25 | 12.34 | 12.34 | - | 12.34 | 8 / 3 | 64.8447 | 8420.1 | 9.067 s |
| baru V2, native 4 inti | 0.00 | 0.38 | 1.67 | 2.90 | 6.81 | 6.81 | 6.87 | 6.87 | 6.96 | 7.07 | 7.07 | 7.35 | 7.07 | 7 / 2 | 64.8461 | 8420.61 | 3.888 s |
| lama 940af0a, emulasi Windows (1 pid, 2 inti, tanpa OPcache) | 0.00 | 0.19 | 1.71 | 4.19 | 27.03 | 27.04 | 27.08 | 27.08 | 27.23 | 27.34 | 27.35 | - | 27.34 | 21 / 5 | 64.8447 | 8420.1 | 22.793 s |
| baru V2, emulasi Windows (1 pid, 2 inti, tanpa OPcache) | 0.00 | 0.18 | 2.67 | 5.80 | 13.51 | 13.51 | 13.62 | 13.62 | 13.72 | 13.81 | 13.81 | 14.81 | 13.81 | 7 / 2 | 64.8461 | 8420.61 | 7.687 s |

| source | G1 | G2 | G5 | G8 | G9 | S2 | S3 | CP | Heat Rate |
|---|---|---|---|---|---|---|---|---|---|
| lama 940af0a | row 1-48 (48 row, maks 31 MW) | row 26-37 (12 row, maks 20 MW) | tidak running | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 31.56 MW) | row 1-48 (48 row, maks 121.02 MW) | 64.8447 | 8420.1 |
| baru V2 | row 1-48 (48 row, maks 31 MW) | tidak running | row 26-37 (12 row, maks 20 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 108 MW) | row 1-48 (48 row, maks 31.56 MW) | row 1-48 (48 row, maks 121.02 MW) | 64.8461 | 8420.61 |

### 3 run per kasus (source beku V2, browser nyata, detik klik Run -> Simulation Data)

| lingkungan | kasus | median | min | maks | first_fully_valid -> simulation_data_opened (maks) | CP | merit | C4 | STG |
|---|---|---|---|---|---|---|---|---|---|
| emulasi Windows | PGN 30 + PEP 30 (input pengguna) | 13.58 | 11.83 | 14.29 | 0.50 s | 64.8461 | PASS | 0 | 144/144 langgar 0 |
| emulasi Windows | PGN 31 + PEP 30 (input pengguna) | 19.66 | 18.72 | 20.66 | 0.50 s | 64.7205 | PASS | 0 | 144/144 langgar 0 |
| native 4 inti | PGN 30 + PEP 30 (input pengguna) | 7.00 | 6.43 | 7.46 | 0.44 s | 64.8461 | PASS | 0 | 144/144 langgar 0 |
| native 4 inti | PGN 31 + PEP 30 (input pengguna) | 10.20 | 8.73 | 11.44 | 0.55 s | 64.7205 | PASS | 0 | 144/144 langgar 0 |

Batas jujur: XAMPP Windows tidak tersedia di lingkungan uji ini. Angka "emulasi Windows" memakai kondisi yang terbukti berbeda di Apache Windows (satu pid untuk semua request), CPU 2 inti, dan PHP tanpa OPcache — bukan pengukuran di mesin pengguna. Panel Detail audit hasil Fastest menampilkan timestamp yang sama (run_click ... helpers_stopped) sehingga waktu di mesin pengguna dapat dibaca langsung.

## 2. Merit: Unit Priority start

Seluruh source sebelum V2 (V12 checkpoint 7ac6ab8, merit a905f10, Fastest 2173cc2, final 940af0a) menghasilkan CP 64,7178 dengan G2 start pada input pengguna (Maximum Review, dijalankan ulang). G5 start (prioritas lebih tinggi) valid dengan CP 64,7205 (+0,0042 %) — di dalam band 0,2 %; pemenang lama dipilih oleh Heat Rate (8374,16 vs 8375,02). Aturan V2: di dalam band, rencana yang men-start unit prioritas lebih rendah kalah dari rencana valid yang men-start unit prioritas lebih tinggi; Heat Rate memutus seri sesudahnya. Di luar band, CP tetap menang (tidak ada constraint yang dilonggarkan).

Dampak pada 12 merit state: 8 identik; BASE_PGN30, Q_pep_kp72_1, busflow 22 berubah G2 -> G5 (CP +0,0022 %, di dalam band); ACT_PGN_UP: commitment dan MW GTG identik, CP sama, Heat Rate 8387,69 -> 8387,68 (jangkar PGN 30 kini rencana G5). Kriteria uji merit .06 dan paritas merit diperbarui mengikuti aturan baru (perubahan kebutuhan eksplisit, bukan pelonggaran).

### Paritas merit dispatch 12 state vs checkpoint integrasi

Referensi: run cold checkpoint integrasi (source merit `a905f10`). Baru: source beku. Dibandingkan: dispatch fisik 48 row, CP, Heat Rate untuk cold, tanpa pembantu, dan warm. Perubahan disengaja V2: di dalam band CP 0,2 %, Unit Priority start (pengguna) didahulukan sebelum Heat Rate.

| state | ref sig / CP / HR | cold | tanpa pembantu | warm | hasil |
|---|---|---|---|---|---|
| BASE_PGN30 | 1c2e009adeae / 64.6644 / 8386.61 | 138088121db6 / 64.6658 / 8387.15 | 138088121db6 / 64.6658 / 8387.15 | 138088121db6 / 64.6658 / 8387.15 | PASS_UNIT_PRIORITY: start rank [8] -> [7] (unit prioritas lebih tinggi), CP 64.6644 -> 64.6658 (+0.0022 %) |
| WB09_3 | fdd9c3c6bb71 / 63.4469 / 8109.13 | identik | identik | identik | PASS: identik |
| WB09 | 4dfea9ac8a43 / 64.3644 / 8319.17 | identik | identik | identik | PASS: identik |
| Q_pep_kp72_1 | e8ff268c979f / 64.631 / 8382.58 | 2989ed926462 / 64.6324 / 8383.11 | 2989ed926462 / 64.6324 / 8383.11 | 2989ed926462 / 64.6324 / 8383.11 | PASS_UNIT_PRIORITY: start rank [8] -> [7] (unit prioritas lebih tinggi), CP 64.6310 -> 64.6324 (+0.0022 %) |
| OPS:pgn_pipe=33;mj=spinning_reserve_min:5 | d7c5d4eaf249 / 64.4728 / 8296.78 | identik | identik | identik | PASS: identik |
| OPS:pgn_pipe=30;mj=busflow_min:22 | 1c2e009adeae / 64.6644 / 8386.61 | 138088121db6 / 64.6658 / 8387.15 | 138088121db6 / 64.6658 / 8387.15 | 138088121db6 / 64.6658 / 8387.15 | PASS_UNIT_PRIORITY: start rank [8] -> [7] (unit prioritas lebih tinggi), CP 64.6644 -> 64.6658 (+0.0022 %) |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":26,"max":155,"r | 7dfc9e57cbf6 / 64.273 / 8253.06 | identik | identik | identik | PASS: identik |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":20,"max":155,"r | 698009db6c76 / 64.4309 / 8287.94 | identik | identik | identik | PASS: identik |
| OPS:pgn_pipe=30;mj=required_mode:{"g1":{"mode":"continuous"},"g8":{"mo | f2407cc397dc / 64.7788 / 8412.58 | identik | identik | identik | PASS: identik |
| Q_pgn_pipe_4 | 3ab7b223502f / 64.1256 / 8207.16 | identik | identik | identik | PASS: identik |
| Q_pep_1 | c7092c1c03cb / 64.4253 / 8336.79 | identik | identik | identik | PASS: identik |
| ACT_PGN_UP | b80c1651bcac / 64.6801 / 8387.69 | 02302494664f / 64.6801 / 8387.68 | 02302494664f / 64.6801 / 8387.68 | 02302494664f / 64.6801 / 8387.68 | PASS_NUMERIK: MW seluruh GTG identik, CP sama; HR 8387.69 -> 8387.68 (efek turunan jangkar/pendaratan) |


## 3. Distillate (input pengguna PGN 25 + PEP 30 + KP72 0, aksi Distillate)

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


Distillate seluruhnya di G1 (unit eligible yang sudah running sepanjang hari) — tidak ada start unit tambahan.

## 4. Datastore

`saved_data_store.php` tidak lagi berada di jalur kritis simulasi: auto-save sebelum Run dan persistensi hasil run hanya menulis berkas kerja secara atomik (tanpa backup, tanpa cermin, tanpa sinkron record). Backup + cermin + sinkron record Report hanya pada Save pengguna. Tidak ada backup pada job, cache, kandidat, atau keluaran internal.

## 5. Uji

| suite | PASS | FAIL |
|---|---|---|
| Targeted (source beku V2) | 362 | 0 |
| Full regression (source beku V2) | 409 | 0 |
| Targeted dari ZIP hasil clean-extract (termasuk uji browser Fastest input pengguna PGN 31/PGN 30 dari berkas hasil ekstrak; lima source PHP byte-identik dengan ZIP final) | 362 | 0 |

Uji pendek sebelum regression (instruksi): PGN 31 + PEP 30 dan PGN 30 + PEP 30 Fastest browser cold (PASS, timeline di atas), PGN 25 + PEP 30 + KP72 0 Distillate (11/11), pembanding merit sebelum/sesudah (bagian 2), smoke Change Over (2 kasus identik V12 checkpoint).


Catatan uji: D14 (dispatch reliability) gagal sekali pada full regression pertama — permintaan poll menunggu ~14 s karena server uji `php -S` hanya 8 worker dan seluruhnya sibuk (job berjalan + pembantu + job langkah sebelumnya). Direproduksi dengan cap waktu (1 dari 5 run), hilang 5/5 dengan 16 worker; ekspektasi uji tidak diubah. Paritas merit pada full regression pertama gagal karena path keluaran absolut di harness (berkas tersimpan di lokasi salah) — diperbaiki; logika paritas lulus pada keluaran yang sama. Full regression diulang penuh: 409 PASS / 0 FAIL.
