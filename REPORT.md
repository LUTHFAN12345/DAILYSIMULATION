# REPORT V15.26 — Prioritas per row, Actual Gas dua arah, konsolidasi blok, runtime (basis V15.25)

Engine build: `V15.26-PRIORITY-ACTUALGAS-CONSOLIDATION-20261018`. Baseline: V15.25 (`debf01f`). Platform: **PHP 7.4.33**, setiap run engine
dipin ke **1 logical CPU** (`taskset`, `Cpus_allowed_list` tercatat di setiap evidence). Laporan V15.25 tetap ada di riwayat git (`debf01f:REPORT.md`).

## 0. Ringkasan gate

| gate | hasil |
|---|---|
| Matrix engine 236 test (229 V15.25 + 7 baru grup P) | **199 PASS, 35 EXPECTED_REJECTION (bukti terminal), 2 FAIL** — T139 (ketiga Actual Gas bersamaan: V15.25 menghasilkan sertifikat `Gas Window Conflict`, V15.26 berakhir `FUEL_ACTION_NOT_APPLIED` tanpa sertifikat), T236 (baru; Use Distillate pada hari gas cukup memakai 4122 l — identik di V15.25). CP terhadap V15.25 pada test yang publish di kedua versi: 147 lebih baik, 5 sama, 41 lebih buruk (bagian 1) |
| Audit 48 row (A0, A1, A3, T154, T214, T034) | 6/6 skenario 48/48 row tanpa temuan tak-terbukti; 7 invariant Rule 1 PASS |
| Actual Gas dua arah (PGN / TOTAL Fixed Flow Jababeka / MM2100) | 29 kasus: 24 FASTEST_FINAL; dingin == warm 29/29 (dispatch & CP identik); actual row immutable 29/29; warm ulang identik 29/29; setiap pasangan input tunggal: CP(lebih tinggi) > CP(lebih rendah); MM2100 jam 1 lebih rendah tetap +0,02 di atas basis (offset biaya MM2100 bawaan V15.25, bagian 3). Terbuka: kombinasi ketiga input bersamaan (4 kasus, juga gagal di V15.25) dan A2 LNG + Jababeka ±10 % (keputusan / repair penuh) — bagian 3 |
| Maximum Review vs Fastest | satu objective final (`pp_sp_final_cmp`) untuk seleksi final Fastest, Maximum Review, dan konsolidasi; T227 (A0) pemenang kandidat Fastest CP 93,4046 vs MR 94,4173; T228 (A3) pemenang MR CP 94,3807 / HR 8482,23 vs Fastest 93,5863 / 8520,07 karena Fastest menyisakan headroom legal unit prioritas tinggi 1 MW ; uji sensitivitas 100 himpunan kandidat (`harness/v1526/evidence/sensitivity.md`): 0 perubahan pemenang untuk band CP 0,1–1 % dan toleransi CP ekonomi 0,01–0,05; 1 perubahan (T228) bila toleransi headroom dinaikkan dari 0,05 ke 1 MW |
| Runtime 1 CPU (target normal 30 s / sulit 40 s / Fix 10 s / Actual Gas 5 s / exact rerun 15 s) | **TIDAK LULUS** untuk run dingin dan Fix Load (bagian 4); exact rerun 0,58 s dan Actual Gas inkremental median 1,1 s lulus |
| UI / MySQL | source UI (`index.php`), store, schema, install tidak berubah terhadap V15.25 (byte-identik) -> evidence UI V15.25 (popup 203/203 + 372 screenshot, distillate 5/5, yesterday 12/12, smoke 26/26) dan grup I/H15/H16 14/14 tetap berlaku (strategi uji hemat) |

**Keputusan paket:** runtime dingin masih di atas target dan beberapa kasus Actual Gas gabungan masih terbuka, sehingga paket **tidak** diberi nama
FINAL/VALIDATED. Dibangun sebagai release candidate `XAMPP_V1526_RC_RUNTIME_GATE_OPEN_*`. Limitation runtime memerlukan persetujuan user (bagian 7).

## 1. Rule 1 — prioritas per row (audit sebelum patch: `harness/v1526/AUDIT_PRIORITY_LOGIC.md`)

STATUS LOGIC EXISTING V15.25: **ADA PARSIAL** (detail source / caller / cakupan row / jalur yang melewati di dokumen audit). Tindakan: perbaiki logic
yang sudah ada, tanpa logic kedua.

| akar masalah | perbaikan (file / fungsi) | bukti |
|---|---|---|
| `EKONOMIS` (biaya marginal) me-resolve temuan "unit prioritas rendah berbeban saat unit Running prioritas tinggi punya headroom" -> Unit Priority Fix tidak pernah memindah beban | `run.php::pp_v5_headroom_priority_audit` + `pp_sp_proof_ref`: EKONOMIS bukan pengecualian; hanya constraint numerik / counterfactual 48 row; `pp_v1523_stage_guard` menilai tahap Unit Priority Fix dengan metrik prioritas/commitment | A0: 58 perpindahan MW ke unit prioritas tinggi, CP 93,4567 -> 93,4046 |
| metrik inversi false positive (T034: start ber-bukti kapasitas/event dihitung inversi) | `pp_sp_proof_ref` jenis `CAPACITY_OR_EVENT_PROOF`; invariant hanya tipe LOADED | T034 publish, invariant PASS |
| tidak ada gerbang invariant | `pp_v1526_priority_invariants` + gerbang publish `PRIORITY_INVARIANT:<nama>` (7 invariant) | `info['Priority Invariants']` pada setiap hasil |
| Maximum Review memakai comparator pencarian (inv sebelum CP) | `pp_job_run_economic_review`: pemenang = `pp_sp_final_cmp` (objective final Fastest) | T227/T228 |
| konsolidasi blok menilai alternatif dengan jendela tahan startup incumbent (alternatif valid terbaca TIDAK VALID), dua fase memangkas alternatif lebih murah, comparator menaruh startup sebelum CP | `pp_sp_block_consolidation`: jendela tahan incumbent lalu fallback start aturan lama + pendaratan gas yang sama dengan kandidat utama (opsional latest legal start alternatif, `PP_V1526_CONSOL_OWN=1`), semua alternatif tak-dominated dievaluasi, permintaan start user dijaga (T204), pemenang urutan objective final (`pp_v1526_consol_cmp`); `pp_sp_proof_completion` melengkapi counterfactual SWAP lintas blok (T034) dan DECOMMIT start di batas Export (T218) | A1: CP 68,4615 / HR 8425,24 / 8 startup -> 68,2940 / 8396,51 / 7 (T230, T231) |
| ADVANCE_REPLACE+PEER hanya mencoba peer pertama global | tightening: peer pertama satu blok + pertama lintas blok (pola SWAP) | A0: varian G6@10 + G4 kini dievaluasi |

Audit 48 row (`harness/v1526/evidence/row_audit/*.md`, dibuat `row_audit.py` dari output final): setiap row mencantumkan unit Running urut Effective
Unit Priority dengan MW / min / max / headroom, temuan prioritas dan buktinya; event start/stop dengan uji start+1 / stop-1. Hasil: A0, A1, A3, T154,
T214 (2+2), T034 masing-masing **48/48 row** tanpa temuan tak-terbukti; RUNNING_HIGH_PRIORITY_FIRST, DELAY_LOWER_PRIORITY_START,
EARLY_STOP_LOWER_PRIORITY, NO_IDLE_MINLOAD_WHILE_HIGHER_PRIORITY_HAS_HEADROOM, NO_PRIORITY_INVERSION_WITHOUT_PROOF, ROW_BALANCE_REOPTIMIZED,
FINAL_CP_HR_OPTIMAL_WITHIN_SEARCH_SET = PASS.

**Keterbatasan yang diketahui (pencarian Fastest bersifat lokal):** dengan Fix Load G2 = 25 MW row 20, run Fastest menemukan commitment A0 lain
(G6 start row 10, G4 row 19–32, G3 tidak start) yang juga valid untuk A0 tanpa Fix: CP 92,7498 / HR 8481,35 vs hasil A0 93,4046 / 8482,47. Kandidat itu
sekarang dihasilkan generator tightening (ADVANCE_REPLACE+PEER G4, `VALID_LEBIH_BAIK` pada tahapnya) tetapi kalah pada langkah best-improvement
tahap itu oleh EARLY_STOP G3 yang lebih baik secara lokal; keunggulannya baru tampak sesudah tahap hilir. Menangkapnya butuh beam / lookahead lintas
tahap (biaya runtime besar). Hal yang sama pada A1: cabang "G4 tidak start" berakhir di 68,1978 bila dijalankan penuh, sedangkan pemilihan tahap
konsolidasi memilih cabang "G3 tidak start" (68,2940). Kedua hasil tetap lebih baik dari V15.25.

### CP/HR terhadap V15.25 (matrix, test yang publish di kedua versi)
147 lebih baik, 5 sama, 41 lebih buruk. Kenaikan terbesar: T217 +1,68, T046/T057/T199/T202 +1,48, T136 +0,40, T050 +0,20 USD/MWh — seluruhnya
dengan HR lebih baik atau sama dan startup lebih sedikit / lebih lambat; sumbernya penegakan Rule 1 (beban dipindah ke unit Running prioritas
tinggi walau biaya marginal naik; EKONOMIS tidak lagi pengecualian) dan dibuktikan invariant PASS. Daftar per test: `harness/v1526/evidence/matrix/`.

## 2. Actual Gas — akar masalah dan perbaikan

Uji dua arah (`harness/v1526/actual_gas/ag_tests.py`; actual per JAM sesuai UI: satu sel per jam pada indeks genap) mengungkap:

1. **Run dingin me-redispatch jam actual (lampau).** Dengan actual jam 1–4, run dingin menyalakan G9 di row 2 dan menaikkan Export 297 MWh pada jam
   yang sudah terjadi, lalu biaya gas jam itu diganti nilai terukur -> CP turun semu (A0 PGN +10 %: dingin 93,15 vs warm 94,11) dan hasil bergantung
   cache. Bukti: dengan jam actual dikunci, optimasi penuh row mendatang memberi 94,1109 vs tahap terkunci warm 94,1097.
   Perbaikan: **jangkar jam actual** (`pp_v1526_ensure_act_anchor` / `pp_v1526_act_lock`): dispatch jam actual = rencana state yang sama tanpa Actual
   Gas (fungsi state, bukan riwayat cache); warm memakai berkas jangkar, dingin menghitung jangkar sekali dengan job yang sama; Maximum Review memakai
   jangkar yang sama. Gerbang `ACTUAL_HOUR_DISPATCH_CHANGED` + evidence `Actual Hour Anchor`.
2. **Actual = estimasi yang ditampilkan tidak netral.** (a) jam actual dikeluarkan dari realokasi Fixed Flow JBBK (Min PGN Flow) sehingga FF / Est_PGN
   jam itu kembali ke rencana asli; (b) estimasi Jababeka / MM2100 jam actual memakai rata-rata harian datar, padahal UI dan validator MM2100 memakai
   estimasi per row (MM2100 bergantung beban G10/GE). Perbaikan `worker02.php`: jam historis memakai FF rencana jangkar (`act_hour_ff_plan`); estimasi jam
   actual = estimasi per row; Actual x1,00 kini netral (A0: Effective 65,4562 tetap; CP 93,4046 / 93,4017 — sisa 0,003 = pembulatan diskret distillate).
3. **MM2100 jam actual / tahap terkunci tidak mengunci GE1–GE4** (GE2 row 2 3,5 -> 12,5 MW). Perbaikan: GE ikut dikunci; pass lantai/plafon kuota
   MM2100 memakai ukuran validator (actual + estimasi) dan menyerap deviasi actual terukur; tahap 0c membebaskan GE hanya pada row mendatang.

### Hasil (warm dari basis yang sama, warm ulang, dingin; `harness/v1526/evidence/actual_gas/ag_results*.json`)

| kasus | arah | delta actual - sim (BBTUD) | status warm | CP basis -> hasil | distillate (l) | residual window (BBTUD) | tahap warm | warm s | dingin s | dingin == warm | jam actual immutable | warm ulang identik |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| A0_distillate PGN_eq0_h1-4 | EQUAL | PGN +0.0000 | FASTEST_FINAL | 93.4046 -> 93.4017 | 207987 | -0.0038 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.97 | 51.16 | ya | ya | ya |
| A0_distillate FFJ_eq0_h1-4 | EQUAL | FFJ +0.0000 | FASTEST_FINAL | 93.4046 -> 93.4046 | 207987 | -0.0038 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.06 | 49.85 | ya | ya | ya |
| A0_distillate PGN_hi10_h1-1 | HIGHER | PGN +0.0130 | FASTEST_FINAL | 93.4046 -> 93.493 | 208798 | -0.017 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.07 | 51.94 | ya | ya | ya |
| A0_distillate PGN_lo10_h1-1 | LOWER | PGN -0.0130 | FASTEST_FINAL | 93.4046 -> 93.3918 | 207987 | -0.0169 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.92 | 51.74 | ya | ya | ya |
| A0_distillate PGN_hi10_h1-4 | HIGHER | PGN +0.0635 | FASTEST_FINAL | 93.4046 -> 93.688 | 210200 | -0.0168 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.01 | 50.83 | ya | ya | ya |
| A0_distillate PGN_lo10_h1-4 | LOWER | PGN -0.0635 | FASTEST_FINAL | 93.4046 -> 93.2023 | 206675 | -0.0187 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1 | 51.66 | ya | ya | ya |
| A0_distillate FFJ_hi10_h1-1 | HIGHER | FFJ +0.1316 | FASTEST_FINAL | 93.4046 -> 93.9835 | 212093 | -0.0218 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.05 | 50.87 | ya | ya | ya |
| A0_distillate FFJ_lo10_h1-1 | LOWER | FFJ -0.1316 | FASTEST_FINAL | 93.4046 -> 92.9528 | 204784 | -0.0186 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.94 | 51.65 | ya | ya | ya |
| A0_distillate FFJ_hi10_h1-4 | HIGHER | FFJ +0.5387 | FASTEST_FINAL | 93.4046 -> 95.4808 | 222840 | -0.0018 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.96 | 51.22 | ya | ya | ya |
| A0_distillate FFJ_lo10_h1-4 | LOWER | FFJ -0.5387 | FASTEST_FINAL | 93.4046 -> 91.3817 | 193485 | -0.0196 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.11 | 53.94 | ya | ya | ya |
| A0_distillate ALL3_hi10_h1-4 | HIGHER | PGN +0.0635, FFJ +0.5387, MM +0.0000 | FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW | 93.4046 -> 96.5953 | 228951 | 0.6008 | repair penuh | 60.16 | 119.77 | ya | ya | ya |
| A0_distillate ALL3_lo10_h1-4 | LOWER | PGN -0.0635, FFJ -0.5387, MM +0.0000 | TIDAK PUBLISH | 93.4046 -> 96.3254 | 229699 | -0.6139 | repair penuh | 88.74 | 150.43 | ya | ya | ya |
| A0_distillate PGN_hi80_h1-8 | HIGHER | PGN +3.9548 | FASTEST_FINAL | 93.4046 -> 108.739 | 318205 | -0.0165 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.27 | 50.25 | ya | ya | ya |
| A0_distillate PGN_lo80_h1-8 | LOWER | PGN -3.9548 | FASTEST_FINAL | 93.4046 -> 78.1483 | 98669 | -0.0181 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.17 | 50.55 | ya | ya | ya |
| A2_lng PGN_eq0_h1-4 | EQUAL | PGN +0.0000 | FASTEST_FINAL | 73.9968 -> 73.9968 | 0 | -0.0361 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.62 | 80.27 | ya | ya | ya |
| A2_lng PGN_hi10_h1-4 | HIGHER | PGN +0.1198 | FASTEST_FINAL | 73.9968 -> 73.9988 | 0 | -0.0278 | 0b_ACTUAL_GAS_TRIM_ROW_PLANN | 4.02 | 86.91 | ya | ya | ya |
| A2_lng PGN_lo10_h1-4 | LOWER | PGN -0.1198 | FASTEST_FINAL | 73.9968 -> 73.7954 | 0 | -0.04 | A_JENDELA_LOKAL | 5.35 | 88.21 | ya | ya | ya |
| A2_lng FFJ_hi10_h1-4 | HIGHER | FFJ +0.5493 | FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW | 73.9968 -> 74.108 | 0 | 0.5154 | repair penuh | 111.57 | 210.2 | ya | ya | ya |
| A2_lng FFJ_lo10_h1-4 | LOWER | FFJ -0.5493 | FASTEST_FINAL | 73.9968 -> 73.8543 | 14640 | -0.0218 | repair penuh | 75.18 | 166.51 | ya | ya | ya |
| A0_fixload PGN_hi10_h1-4 | HIGHER | PGN +0.0522 | FASTEST_FINAL | 92.7498 -> 93.0136 | 203210 | -0.0347 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.97 | 36.86 | ya | ya | ya |
| A0_fixload PGN_lo10_h1-4 | LOWER | PGN -0.0522 | FASTEST_FINAL | 92.7498 -> 92.5844 | 200313 | -0.0268 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.12 | 36.61 | ya | ya | ya |
| A0_supplier_window PGN_hi10_h1-4 | HIGHER | PGN +0.0892 | FASTEST_FINAL | 93.4046 -> 93.8587 | 210913 | -0.0398 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 0.94 | 51.42 | ya | ya | ya |
| A0_supplier_window PGN_lo10_h1-4 | LOWER | PGN -0.0892 | FASTEST_FINAL | 93.4046 -> 93.0988 | 205961 | -0.0174 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.07 | 52.59 | ya | ya | ya |
| MM2100_active MM_hi10_h1-1 | HIGHER | MM +0.0062 | FASTEST_FINAL | 93.6233 -> 93.6527 | 217049 | -0.0239 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.24 | 48.86 | ya | ya | ya |
| MM2100_active MM_lo10_h1-1 | LOWER | MM -0.0062 | FASTEST_FINAL | 93.6233 -> 93.6449 | 217049 | -0.0364 | 0_ACTUAL_GAS_DISPATCH_TERKUN | 1.2 | 47.94 | ya | ya | ya |
| MM2100_active MM_hi10_h1-4 | HIGHER | MM +0.0442 | FASTEST_FINAL | 93.6233 -> 93.6695 | 217049 | -0.0154 | 0c_ACTUAL_MM2100_GE_ROW_MEND | 5.04 | 54.71 | ya | ya | ya |
| MM2100_active MM_lo10_h1-4 | LOWER | MM -0.0442 | FASTEST_FINAL | 93.6233 -> 93.5968 | 217049 | -0.0304 | 0c_ACTUAL_MM2100_GE_ROW_MEND | 5.53 | 53.54 | ya | ya | ya |
| MM2100_active ALL3_hi10_h1-4 | HIGHER | PGN +0.0635, FFJ +0.5387, MM +0.0442 | FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW | 93.6233 -> 95.672 | 227878 | 0.6473 | repair penuh | 79.22 | 136.92 | ya | ya | ya |
| MM2100_active ALL3_lo10_h1-4 | LOWER | PGN -0.0635, FFJ -0.5387, MM -0.0442 | TIDAK PUBLISH | 93.6233 -> 95.6192 | 230537 | -0.6751 | repair penuh | 82.72 | 141.36 | ya | ya | ya |

Warm (inkremental) median 1.12 s, min 0.62, max 111.57; 20/29 kasus <= 5 s.

## 3. Actual Gas — kasus terbuka

- **Ketiga input bersamaan ±10 % jam 1–4** (A0 dan A0+KP72): window gas total dan window PGN Pipe tidak terpenuhi bersamaan sesudah rekonsiliasi
  distillate (residual ±0,60–0,68 BBTUD). V15.25 juga tidak mempublikasikan kasus yang sama (hi: USER_ACTION_REQUIRED `Gas Window Conflict`; lo: tidak
  publish). Perlu keputusan domain: window mana yang mengikat ketika PGN dan TOTAL Fixed Flow Jababeka actual menyimpang bersamaan.
- **A2 LNG + Jababeka +10 %**: Total Gas di atas window +0,515 BBTUD dengan LNG tetap -> `FUEL_ACTION_NOT_APPLIED_INCUMBENT_FOR_REVIEW` (sama dengan
  V15.25). **A2 LNG + Jababeka −10 %**: valid tetapi lewat commitment repair penuh (76 s).
- Biaya MM2100 jalur estimasi memakai `Estimation Energy MM2100` (3,0000) sedangkan pemakaian harian per row 3,0405; dengan actual MM2100 biaya
  memakai pemakaian per row -> offset +0,02 CP saat actual MM2100 diisi (bawaan V15.25, tidak diubah).

## 4. Runtime 1 CPU (PHP 7.4.33, `taskset -c 3` (V15.25 dan V15.26 pada CPU yang sama), helper 0; payload identik; `harness/v1526/bench/raw/`)

| kasus | target s | V15.25 wall median/min/max | V15.26 wall median/min/max | V15.26 CPU median | V15.26 RSS MB | CP V15.25 -> V15.26 | status |
|---|---|---|---|---|---|---|---|
| A0 cold (3x) | 30 | 43.92 / 43.27 / 44.45 | 50.58 / 49.46 / 50.75 | 49.22 | 462 | 93.4567 -> 93.4046 | TIDAK LULUS |
| A1 gas cukup cold | 40 | 102.75 / 102.75 / 102.75 | 158.28 / 158.28 / 158.28 | 156.67 | 726 | 68.4413 -> 68.294 | TIDAK LULUS |
| A2 LNG cold | 40 | 71.08 / 71.08 / 71.08 | 80.76 / 80.76 / 80.76 | 79.93 | 394 | 73.9965 -> 73.9968 | TIDAK LULUS |
| A7 terminal cold | 40 | 112.17 / 112.17 / 112.17 | 107.02 / 107.02 / 107.02 | 106.05 | 473 | 122.0047 -> 122.0047 | TIDAK LULUS |
| Actual PGN cold (tanpa basis) | 40 | 73.11 / 73.11 / 73.11 | 50.70 / 50.70 / 50.70 | 50.12 | 465 | 93.0236 -> 92.7126 | TIDAK LULUS |
| A0 basis warm app (3x) | 30 | 44.86 / 44.04 / 46.08 | 49.93 / 49.04 / 51.08 | 49.19 | 462 | 93.4567 -> 93.4046 | TIDAK LULUS |
| exact rerun (3x) | 15 | 0.57 / 0.52 / 0.66 | 0.54 / 0.53 / 0.73 | 0.51 | 41 | 93.4567 -> 93.4046 | LULUS |
| Actual PGN inkremental (3x) | 5 | 0.88 / 0.76 / 0.94 | 0.91 / 0.91 / 0.97 | 0.88 | 41 | 93.183 -> 92.7126 | LULUS |
| Actual Jababeka inkremental (3x) | 5 | 0.82 / 0.79 / 0.85 | 0.99 / 0.91 / 1.07 | 0.96 | 41 | 107.3177 -> 107.7277 | LULUS |
| Actual MM2100 inkremental (3x) | 5 | 0.85 / 0.85 / 1.11 | 1.01 / 0.93 / 1.03 | 0.96 | 41 | 94.248 -> 94.1924 | LULUS |
| Fix Load G2 inkremental (3x) | 10 | 27.01 / 26.42 / 27.39 | 32.49 / 32.29 / 34.21 | 31.98 | 310 | 92.8227 -> 92.7498 | TIDAK LULUS |


Profil (A0 / A1, `Start Priority Stage Profile`): A0 ~52 s = Commitment Tightening 18 s (84 evaluasi, 3 ronde), konsolidasi 7 s, V8 5 s, bukti
event 5,6 s, Unit Priority Fix 3 s, kandidat startup 3,6 s. A1 ~165 s = konsolidasi (3 alternatif x jendela tahan incumbent + fallback start aturan lama) ~95 s, kandidat startup 25 s, V8 10 s.
Duplikasi: 8 % simulasi identik (A1, 407 simulasi); konsolidasi tidak dapat dipangkas tanpa simulasi (batas atas kapasitas tidak membuktikan
infeasibility). Kenaikan runtime V15.26 berasal dari penegakan prioritas (lebih banyak perpindahan / ronde tightening) dan evaluasi alternatif
konsolidasi yang sebelumnya salah ditolak; keduanya mengubah hasil menjadi lebih baik dan tidak dapat dibuang tanpa mengurangi kandidat material.

## 5. Fitur V15.25 (Section 5)

- Popup Ctrl+Click / Fix Load, Distillate Priority 1–6 UI, Check Yesterday Data UI: `index.php` byte-identik V15.25 -> evidence E2E V15.25 berlaku.
- Distillate Priority 1–6 (engine, end-to-end job): T234 (G6 prioritas 1 Stop, G5 Running -> G5 dipakai, G6 tidak distart), T235 (spill mengikuti
  urutan eligible), T236 (gas cukup -> 0 l), T208–T213.
- 2+2 sebelum 3+1: T214–T219 (metadata, intent user, reason code); audit 48 row T214.
- Block 3 cold (T220, row-by-row dari run matrix final): G8 start row 2 (slot 00:30–01:00); 40 MW row 2–8 (7 slot = 3,5 jam), 50 MW row 9–10 (1 jam), 60 MW row 11–15 (2,5 jam) = profil 7,0 jam (00:30–07:30); S3 berbeban pertama row 16 (slot 07:30–08:00, label 08:00) = **7,5 jam sesudah instan start 00:30 menurut konvensi label = akhir slot**; menurut konvensi awal slot S3 mulai 7,0 jam sesudah start. Profil produksi tidak diubah — konvensi perlu dikonfirmasi user.

## 6. File berubah terhadap V15.25

`run.php`, `worker02.php` (+ `CHECKPOINT.md`, `DEPLOYMENT_MANIFEST.md`, `SHA256SUMS.txt`). Harness: `harness/v1526/*`.
Flag rollback: `PP_V1526_ECON_PROOF=1`, `PP_V1526_FIX_GUARD=0`, `PP_V1526_INV_GATE=0`, `PP_V1526_MR_FINAL_CMP=0`, `PP_V1526_ACT_ANCHOR=0`,
`PP_V1526_ACT_FF=0`, `PP_V1526_LOCK_GE=0`, `PP_V1526_MM_ACT_TRIM=0`, `PP_V1526_ACT_MM_STAGE=0`, `PP_V1526_CONSOL_PLAN=0`, `PP_V1526_CONSOL_FALLBACK=0`,
`PP_V1526_CONSOL_GAS_LAND=0`, `PP_V1526_CONSOL_ALL=0`, `PP_V1526_CONSOL_CMP=0`, `PP_V1526_ARP_BOTH=0`, `PP_V1526_CONSOL_USER=0`, `PP_V1526_CONSOL_OWN=1` (aktifkan tier latest legal start alternatif), `PP_V1526_LLF_USER=0`, `PP_V1526_XBLOCK_COMPLETE=0`, `PP_V1526_DECOMMIT_COMPLETE=0`.

## 7. Limitation yang memerlukan keputusan user

1. Runtime dingin 1 CPU di atas target (bagian 4).
2. Kombinasi ketiga Actual Gas bersamaan (bagian 3) — window mana yang mengikat.
3. Konvensi waktu Block 3 cold (bagian 5).
4. Toleransi headroom prioritas tinggi pada objective final (0,05 MW): T228 Maximum Review memilih hasil CP 94,3807 vs Fastest 93,5863 karena
   selisih headroom 1 MW; dengan toleransi 1 MW pemenangnya Fastest. Urutan objective (prioritas sebelum CP) mengikuti Rule 1.
5. Kenaikan CP pada 41 test akibat penegakan prioritas (bagian 1) — dapat dikembalikan per flag `PP_V1526_ECON_PROOF=1`.
6. T139 / T236 (bagian 0, 3).
