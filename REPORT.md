# REPORT V15.27 — Objective ekonomi dulu, audit CP 41 test, envelope gas T139, runtime (basis V15.26)

Engine build `V15.27-ECON-OBJECTIVE-START-POINTS-20261019`, branch `claude/keen-galileo-sjjhsk`. PHP 7.4.33, setiap run dipin ke satu logical CPU.
Evidence: `harness/v1527/evidence/` (matrix, cp41, sensitivity, row_audit, bench, actual_gas).

## 0. Ringkasan gate

| gate | hasil |
|---|---|
| Matrix engine 236 test (full, satu kali sesudah shared core final) | **200 PASS / 35 EXPECTED_REJECTION / 1 FAIL** (T236, identik V15.25: Use Distillate pada gas cukup A1 memakai 4122 l G6). V15.26: 199 / 35 / 2. |
| CP terhadap V15.25 (229 test publish di kedua versi) | lebih rendah 163, sama 54, lebih tinggi 11 (selisih +0,02 … +0,60; rincian bagian 1). T139 tidak dihitung (USER_ACTION_REQUIRED). |
| 41 test yang CP-nya naik di V15.26 | **36/41 FIXED** (CP ≤ V15.25); 5 terbuka: T046/T057/T199/T202 (skenario sama, kategori E, +0,147) dan T136 (G, +0,093). `evidence/cp41.md` |
| Fastest vs Maximum Review | objective final sama (`pp_sp_final_cmp`), kandidat Fastest selalu di pool MR, seri -> Fastest; T227/T228 PASS; sensitivitas 101 himpunan kandidat, 0 pemenang berubah pada 8 variasi toleransi |
| T139 (PGN Pipe + Effective Total Gas sama-sama target) | USER_ACTION_REQUIRED dengan sertifikat envelope gas: feasible envelope + residual masing-masing target (bagian 3) |
| Audit 48 row (A0, A1, T034, T154, T214) | 48/48 row tanpa temuan tak-terbukti, HPA PASS_ALL_FINDINGS_PROVEN |
| Actual Gas dua arah | bagian 6 |
| Runtime 1 CPU | **TIDAK LULUS** untuk run dingin (A0 40,8 s vs 30; A1 111 s, A2 59 s, A7 90 s vs 40) dan Fix Load (39,7 s vs 10). Lulus: exact rerun 0,49 s, Actual Gas inkremental 0,68–0,73 s (bagian 7) |

Status paket: runtime gate belum lulus -> paket **release candidate** `XAMPP_V1527_RC_RUNTIME_GATE_OPEN_*` (bukan FINAL/VALIDATED).

## 1. Objective, comparator, candidate pool — audit 41 test (keputusan user 5)

Objective final (sama untuk Fastest, Maximum Review, konsolidasi, stage guard): hard constraint -> intent user/Required -> tanpa blocking (C1–C4,
STG/HPA, fuel, Export, proof) -> startup otomatis tak perlu / blok baru -> **CP ekonomi -> CP resmi (band 0,2 %) -> HR** -> headroom legal unit prioritas
tinggi tak terpakai -> startup / unit-row / fragmentasi / latest start / earliest stop -> sidik jari. Inversi prioritas = tie-break/diagnostik.
Unit Priority tetap dipakai untuk alokasi beban unit Running (Unit Priority Fix), penundaan start dan stop lebih awal unit prioritas rendah (tightening,
bukti event), dan eliminasi start/minimum-load tak perlu. Pengecualian `EKONOMIS` (memindah MW menaikkan biaya marginal) kembali sah.

Root cause kenaikan CP V15.26 (stage-by-stage V15.25 vs V15.26/V15.27):

| kategori | test | root cause | perbaikan V15.27 (shared logic) |
|---|---|---|---|
| A | 31 test | `pp_v1523_cmp` / `pp_sp_final_cmp` / `pp_v1526_consol_cmp` menilai inversi / headroom prioritas sebelum CP ekonomi; stage guard menolak Unit Priority Fix yang lebih ekonomis | urutan objective di atas (`PP_V1527_ECON_FIRST`), satu urutan untuk ketiga comparator |
| D | T050, T114, T200 | closure bukti event tidak pernah menguji langkah prioritas yang baru menguntungkan sesudah Unit Priority Fix | closure menguji langkah valid yang paling mengurangi inversi melalui rantai fix yang sama, diterima hanya bila ekonomi lebih baik |
| E | T046, T057, T199, T202, T047 (+ ditemukan di matrix V15.27: T017, T159, T215, T216, T220–T222, T225) | local search bergantung lintasan: urutan langkah ekonomi menutup rangkaian tunda-start/stop-awal; V8 kecil menutup lintasan dari dispatch sebelum V8; pemilihan langkah closure memakai metrik sebelum Unit Priority Fix | titik awal tightening ganda (urutan ekonomi, urutan prioritas dengan cache evaluasi dibagi, dispatch sebelum V8) dinilai SESUDAH 2+2 + Unit Priority Fix; closure menilai langkah terbaik kedua urutan sesudah fix |
| F | T174, T216 (ditemukan di matrix) | hasil bergantung riwayat cache: jalur warm IE / Fix Load mempertahankan commitment basis dan melewati V8 + tightening (T216 warm 102,52 vs dingin 101,33) | IE atau Fix Load berubah -> review commitment penuh (warm == dingin); Actual Gas saja tetap warm (rencana jangkar) |
| G | T136; T118, T121 (ditemukan di matrix) | konsekuensi aturan user: T136 jam actual yang sudah lewat tidak dioptimasi ulang (jangkar V15.26; V15.25 menurunkan CP semu); T118/T121 V15.25 lebih murah hanya dengan start otomatis G1 tambahan (7 vs 6 startup) yang tidak diperlukan constraint | diterima dengan bukti numerik: T136 +0,0931 CP / HR −0,05; T118 +0,4767 CP / HR −7,9, startup −1; T121 +0,6017 CP / HR +0,1, startup −1 |

Sisa lebih tinggi dari V15.25 (matrix final): T017 +0,3347 (E: G1 dipakai alih-alih G4, jumlah start sama), T046/T057/T199/T202 +0,1474 (E: pilihan
konsolidasi tahap blok; commitment sama, alokasi beban G5/G8 berbeda), T115 +0,0221 (E), T174 +0,2147 (E, Fix Load dingin), T118/T121/T136 (G, di atas).
Kategori B (kandidat murah hilang dari pool) dan C (oracle/proof salah menolak) tidak ditemukan.

## 2. Fastest vs Maximum Review

`pp_job_run_economic_review` membandingkan kandidat Fastest dan Maximum Review dengan `pp_sp_final_rank`/`pp_sp_final_cmp` (objective di atas); seri
-> Fastest. Oracle matrix `cmp_final` (T227/T228) diperbarui ke urutan V15.27 (MATRIX_CHANGELOG.md): dengan oracle V15.26, T227 tercatat FAIL walaupun
engine memilih dan mempublikasikan pemenang objective final yang benar. Sensitivitas (`evidence/sensitivity.md`): 101 himpunan (2 MR vs Fastest, 99
konsolidasi), 0 pemenang berubah untuk band CP 0,1/0,5/1 %, toleransi CP ekonomi 0,01/0,05, toleransi headroom 1/5 MW.

## 3. T139 — PGN Pipe dan Effective Total Gas sama-sama target

Sertifikat envelope gas V7 dijalankan juga di jalur Fastest dan pada kasus distillate (`PP_V1527_ENV_FAST`, `PP_V1527_ENV_DIST`). Hasil:
USER_ACTION_REQUIRED (tidak dipublikasikan), `Gas Window Conflict` = PGN_PIPE_FLOOR_VS_TOTAL_GAS_CEILING:
- window supplier PGN Pipe [24,46 ; 24,50], tercapai 21,1511; window gas total [65,42 ; 65,46], tercapai 65,4439;
- `envelope_feasible.jika_pipe_di_window`: Effective Total ≥ 68,6467 BBTUD -> **residual total +3,1867 BBTUD** di atas window;
- `envelope_feasible.jika_total_di_window`: PGN Pipe ≤ 21,2733 BBTUD -> **residual pipe −3,1867 BBTUD** di bawah window;
- bukti: K = Effective − Pipe (LNG + Jababeka + MM2100) tidak dikendalikan dispatch; actual jam berjalan; lever LNG/Distillate/commitment tidak mengubah irisan.

## 4. Block 3 Cold Start (keputusan user 3)

Timeline setiap row: `harness/v1527/BLOCK3_TIMELINE.md` (T220). Startup G8 slot 00:30–01:00 (row 2); 40 MW 7 row (3,5 jam), 50 MW 2 row (1 jam),
60 MW 5 row (2,5 jam); S3 first load row 16 label 08:00 = 7,5 jam sesudah 00:30 (label = akhir slot).

## 5. Legal headroom dan audit prioritas per row (keputusan user 4)

Headroom = legal max row (effective max, dibatasi ramp dari row tetangga) − beban actual/planned row (`pp_v5_headroom_priority_audit`,
`pp_v1523_start_context`); bukan max − min. 0,05 MW hanya ambang noise per unit-row. Audit logic prioritas per row: `harness/v1527/AUDIT_PRIORITY_LOGIC.md`
— status ADA LENGKAP, satu implementasi; V15.27 memperbaiki jalur yang membatalkannya (stage guard, closure).

## 6. Actual Gas dua arah

Suite V15.26 dijalankan ulang pada source V15.27 (`harness/v1527/evidence/actual_gas/ag_summary.md`, `ag_results.json`): PGN / TOTAL Fixed Flow
Jababeka / MM2100, lebih tinggi dan lebih rendah, satu jam / jam 1–4 / ketiganya bersamaan, distillate / LNG / Fix Load / supplier window.
- 29 kasus, 23 publish; **dingin == warm 29/29**, jam actual immutable 29/29, warm ulang identik 29/29.
- Setiap pasangan input tunggal yang publish (10 pasangan): CP(lebih tinggi) > CP(lebih rendah).
- Warm median 0,83 s; 22/29 ≤ 5 s. Di atas 5 s hanya kasus yang memerlukan keputusan bahan bakar / commitment repair penuh (ketiga input bersamaan,
  A2 LNG + Jababeka ±10 %), sama dengan V15.25/V15.26.
- Tidak publish (sama dengan V15.26): ketiga input bersamaan ±10 % (A0 dan A0+KP72; hi: USER_ACTION_REQUIRED `Gas Window Conflict`), A2 LNG + Jababeka
  +10 % (USER_ACTION_REQUIRED), A2 LNG + Jababeka −10 % (release gate PASS, publish ditahan seperti V15.26).

## 7. Runtime 1 CPU (PHP 7.4.33, `taskset -c 1`, V15.25 dan V15.27 berurutan pada CPU yang sama, helper 0; `evidence/bench/`)

| kasus | target s | V15.25 wall median / min / max | V15.27 wall median / min / max | CP V15.25 -> V15.27 | status V15.27 |
|---|---|---|---|---|---|
| A0 cold (3x) | 30 | 37.77 / 37.61 / 38.17 | 40.76 / 40.69 / 41.66 | 93.4567 -> 93.0321 | TIDAK LULUS |
| A1 gas cukup cold | 40 | 87.25 | 111.02 | 68.4413 -> 68.294 | TIDAK LULUS |
| A2 LNG cold | 40 | 62.27 | 59.46 | 73.9965 -> 73.9965 | TIDAK LULUS |
| A7 terminal cold | 40 | 92.85 | 90.10 | 122.0047 -> 122.0047 | TIDAK LULUS |
| Actual PGN cold (tanpa basis) | 40 | 60.23 | 41.80 | 93.0236 -> 92.3612 | TIDAK LULUS |
| A0 basis warm app (3x) | 30 | 38.05 / 37.09 / 38.84 | 41.62 / 41.07 / 41.92 | 93.4567 -> 93.0321 | TIDAK LULUS |
| exact rerun (3x) | 15 | 0.49 / 0.49 / 0.53 | 0.49 / 0.48 / 0.52 | sama | LULUS |
| Actual PGN inkremental (3x) | 5 | 0.69 | 0.68 | 93.183 -> 92.3612 | LULUS |
| Actual Jababeka inkremental (3x) | 5 | 0.71 | 0.72 | 107.3177 -> 107.3017 | LULUS |
| Actual MM2100 inkremental (3x) | 5 | 0.70 | 0.73 | 94.248 -> 93.7707 | LULUS |
| Fix Load G2 (3x) | 10 | 23.00 / 22.98 / 23.14 (warm) | 39.74 / 39.47 / 40.56 (review penuh) | 92.8227 -> 92.8227 | TIDAK LULUS |

Optimasi V15.27 (A1 V15.26 158 s -> 111 s pada kualitas lebih baik dari V15.25):
- eksak: pencarian offset kuota JBBK berhenti bila probe berikut sama dengan offset yang sudah dicoba (input identik, A1 −18 s); pemeriksaan HRSG stop
  dilewati bila tidak ada HRSG stop; korpus ekuivalensi 197–265 core run (hash output identik, urutan replay dibalik = 0 beda).
- perilaku (dibuktikan tidak mengubah hasil valid): window supplier PGN berhenti sesudah satu langkah stagnan (seluruh 102 pencarian stagnan A1 berakhir
  di luar window, gap ≥ 0,38 BBTUD).
- dicoba dan ditolak (tidak eksak atau tanpa manfaat terukur): inline signature memo STG (+0,1 %), slot kedua memo STG (+2,6 %), gate percobaan ketiga
  evaluator lokal (8 dari 60 penyelamatan tidak dapat dibedakan secara aman).

Mengapa target belum tercapai (terukur, `Start Priority Stage Profile` + jumlah simulasi per tahap): satu core run 0,19–0,25 s dan profilnya datar
(pp_recompute_stgs 23 %, export closure 9 %, pp_is_unit_stopped 8 %, fuel sum 7,5 %, sisanya tersebar) sehingga optimasi mikro eksak memberi ~1 %.
Waktu didominasi JUMLAH simulasi pencarian: A0 193 simulasi (tightening 82, konsolidasi 41, V8 25, bukti event 23); A1 494 (konsolidasi 278 — setiap
alternatif menjalankan pipeline kandidat penuh dengan pencarian bersarang offset kuota × window supplier). Memangkasnya tanpa bukti dominansi berarti
mengurangi kandidat material / proof, yang tidak diizinkan. Fix Load: jalur warm memberi CP lebih buruk (T174 warm 97,62 vs dingin 94,30), sehingga
review penuh dipakai demi warm == dingin; target 10 s menuntut pencarian penuh ~4x lebih cepat.

## 8. File berubah terhadap V15.26

`run.php`, `worker02.php`, `worker_functions.php` (+ `CHECKPOINT.md`, `DEPLOYMENT_MANIFEST.md`, `SHA256SUMS.txt`). Harness: `harness/v1527/*`,
oracle `harness/v1526/matrix/mxdefs.py` (`cmp_final`). Flag rollback: DEPLOYMENT_MANIFEST.md.

## 9. Keterbatasan

1. Runtime 1 CPU: run dingin dan Fix Load di atas target (bagian 7).
2. Sisa CP di atas V15.25 kategori E (local search): T017 +0,33, T046 kelompok +0,15, T115 +0,02, T174 +0,21 — dilaporkan, belum diperbaiki.
3. T236 (gas cukup + Use Distillate memakai 4122 l), identik V15.25.
4. Matrix full dijalankan pada commit `67711b4` + build ID lama; perubahan sesudahnya (IE berubah -> review penuh, build ID) hanya memengaruhi run yang
   memakai jalur warm IE (T109, T216) — keduanya dijalankan ulang pada source final (`evidence/matrix_final_code/`): T216 101,3333 (= V15.25), T109 95,0734.
