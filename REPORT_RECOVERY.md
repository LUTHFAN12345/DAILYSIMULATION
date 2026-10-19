# REPORT RECOVERY — V15.30 (root cause CP dan runtime)

## CP root cause (lineage trace / replay; dump per panggilan tightening, replay offline V15.25 vs V15.30)
| test | titik pertama kandidat V15.25 hilang | file / fungsi / caller | alasan | fix generik |
|---|---|---|---|---|
| T017 | titik awal tightening | `run.php` `pp_v1527_tighten_pre_v8` <- `pp_sp_final_bundle` | lintasan V15.25 = dispatch sebelum V8 + urutan prioritas; V15.27 hanya urutan prioritas dari dispatch sesudah V8 | titik awal PRE_V8_PRIO_ORDER |
| T115 | cache evaluasi tightening | `run.php` `pp_v1523_tighten` (`$GLOBALS['ppV1527TCache']`) | kunci = signature commitment saja, padahal evaluasi = redispatch jendela lokal terhadap dispatch incumbent ronde; run urutan prioritas memakai hasil lintasan ekonomi (bukti: sharing mati -> 99,4146 = V15.25) | kunci = signature + hash dispatch incumbent + jenis evaluasi; cache lokal per run (semantik V15.25) |
| T174 | pemilihan titik awal | `run.php` `pp_v1527_tighten_pre_v8` (score) | dinilai pada CP sesudah Unit Priority Fix (tahap antara), bukan objective final; tightening/2+2/Unit Priority Fix identik V15.25 (replay 97,8302 -> 95,9327), closure menurunkan lintasan lain lebih jauh | rantai final (pelengkap bukti + bukti event/closure) untuk kandidat dalam band 1 %; hasil pemenang dipakai ulang bundle; dedup dispatch identik |
| T046 (=T057/T199/T202) | Block Consolidation | `run.php` `pp_sp_block_consolidation` <- `pp_sp_final_bundle` | alternatif s2:ONLY_G5 kini valid (fallback V15.26) dan lebih murah (78,29 vs 81,27); lintasan V15.25 = V8 pada incumbent (81,27 -> 74,86) lalu tightening; commitment final sama, dispatch berbeda row 13–31 (row di luar jendela terkunci pada dispatch lintasan) | BELUM: titik awal sebelum konsolidasi tanpa V8 diuji (73,82 / 76,35) dan dibuang; meniru lintasan butuh V8 tambahan (+10–30 s per kasus) |
| T136 | Actual Gas warm (dibekukan) | — | basis A0 berbeda (V15.25 93,4567, sekarang 93,0321) | tidak disentuh |

## Hasil targeted (PHP 7.4.33, 1 CPU per run, 4 run paralel; seluruh hasil valid: assess valid, 0 pelanggaran hard, Priority Invariants PASS)
| test | V15.25 CP / s | V15.29 CP | V15.30 CP / s |
|---|---|---|---|
| T017 | 97,2551 / 34,8 | 97,5898 | **97,2551** / 88,4 |
| T115 | 99,4146 / 46,2 | 99,4367 | **90,2185** / 89,6 (Distillate 261 395 -> 178 223 l; start G9 row 10) |
| T174 | 94,905 / 89,8 | 95,1197 | **94,2979** / 163,6* (HR 8506,0 vs 8485,7; CP lebih rendah di luar band) |
| T046 | 71,8566 / 18,2 | 72,004 | 72,004 / 85,6* (belum) |
| T236 | — | 68,294 / 0 l / 144,9 | 68,294 / 0 l / 140,9 |
| T118 / T121 | 93,1327 / 93,1548 | 92,9735 / 92,7266 | 92,9735 / 92,7266 (108,7 / 115,1 s; V15.28 72–82 / 89–102 s) |
| A0 | 93,4567 / 48,2 | 93,0321 / 54,2 | 93,0321 / 76,0 |
| A1 gas-cukup | 68,4413 / 113 | 68,294 / 136 | 68,294 / 166* |
| Fix Load (warm g2 r20=25) | 92,8227 / 30,4 | 92,8227 / 61,7 | 92,8227 / 61,0 |
(* run dengan titik awal pra-konsolidasi yang kemudian dibuang — waktu final source lebih rendah, belum diukur ulang.)

## Runtime root cause (counter simulasi core identik, `harness/v1530/instr.py`; `evidence/simprof_*.txt`)
Simulasi core = ~90 % runtime; tidak ada input simulasi duplikat (memo once bekerja). Biaya tambahan terbesar vs V15.25:
1. Gas-cukup A1 / gas-only T236: `pp_sp_block_consolidation` -> `consol_run_fallback` (V15.26): 194 sim / 57,6 s; fallback NO_ADDITIONAL_PEER 96 sim / 29,7 s tidak valid (no-op). A1 393 -> 497 sim.
2. Fix Load: `PP_V1527_FIX_COLD` (review penuh dingin) + titik awal tightening `pp_v1527_tighten_pre_v8` 110 sim / 24,5 s (30,4 -> 61 s; CP sama).
3. Cold: titik awal tightening ganda + scoring Unit Priority Fix per kandidat (T017 +31,7 s; T118 pre_v8 18,6 s + score 36,9 s); V15.30 menambah run urutan prioritas yang kini evaluasi nyata (sebelumnya hit cache tercemar, A0 0,16 -> 13,7 s) dan rantai closure kandidat kalah (A0 7,8 s).
Per simulasi: profil Xdebug (PHP 8, relatif) — `pp_shape_final_dispatch`, `pp_recompute_stgs` (23 752 panggilan, sudah dimemoisasi), closure `worker_functions.php:12187`.

## Limitation
Gate runtime GAGAL (seluruh kasus lebih lambat dari V15.25; V15.30 menambah biaya demi CP). T046 kelompok belum. Full matrix, Actual Gas suite dan Maximum Review
tidak diuji ulang pada V15.30 (path tightening/bundle berubah -> wajib sebelum FINAL). Tidak ada ZIP V15.30.

# Riwayat V15.29 RC

## V15.29 (sprint ini)
| item | V15.28 | V15.29 |
|---|---|---|
| T236 CP / distillate | 68,294 / 0 l | 68,294 / 0 l |
| T236 runtime (1 CPU) | 345 s (dua job serial) | 144,9 s (satu job; pre-check gas-only 0,27 s) |
| A0 (liter user) | 93,0321 | 93,0321 (wrapper dilewati) |

Lima CP regression (T017 +0,3347, T046/T057/T199/T202 +0,1474, T115 +0,0221, T174 +0,2147, T136 +0,0931): winner V15.25 lolos seluruh validator
V15.27/V15.28 (bukti `chk.php`: assess valid, 0 pelanggaran hard, Priority Invariants PASS) — belum diperbaiki; injeksi winner V15.25 secara generik
memerlukan seeding pencarian baru (tidak sempat). Runtime A0/gas-cukup/Fix Load: belum dioptimasi pada sprint ini (target belum tercapai).

# Riwayat V15.28

## Root cause diperbaiki
1. **T236 — Use Distillate diperlakukan sebagai kewajiban.** Tanpa liter user, shaper memakai distillate begitu shortage lokal muncul dan kandidat
   gas-only tidak pernah dibandingkan. Sekarang Use Distillate = izin/fallback: job gas-only dijalankan dan dibandingkan dengan objective final
   (`pp_sp_final_cmp`) bila distillate menutup shortage kecil (≤ 0,5 BBTUD) atau hasil distillate tidak publish. Liter user eksplisit = intent, tidak diubah.
2. **Urutan objective search.** `pp_v1523_cmp` menilai startup tak perlu sebelum CP; sekarang CP ekonomi -> HR -> startup tak perlu -> blok baru -> inversi.
3. **Alokasi beban commitment final.** `pp_v1528_recore`: commitment final didaratkan ulang sekali; diterima hanya bila valid penuh, lebih ekonomis,
   tanpa startup tambahan (bukti event dihitung ulang bila commitment berubah).

File/fungsi: `run.php` — `pp_job_run_economic_review_v1524` (wrapper) / `pp_job_run_economic_review_v1524_core`, `pp_v1523_cmp`, `pp_v1528_recore`,
pemanggilan di `pp_sp_final_bundle` dan jalur job.

## Targeted / smoke
| test | V15.25 CP / HR | V15.27 CP | V15.28 CP / HR | status |
|---|---|---|---|---|
| T236 (gas cukup + Use Distillate) | 71,42 (4122 l, FAIL) | 71,42 (FAIL) | **68,294 / 0 l** | PASS |
| T118 | 93,1327 / 8513,86 | 93,6094 | **92,9735 / 8501,00** | PASS |
| T121 | 93,1548 / 8507,47 | 93,7565 | **92,7266 / 8492,92** | PASS |
| T017 | 97,2551 / 8518,61 | 97,5898 | 97,5898 / 8538,39 | PASS, +0,3347 |
| T046 (=T057/T199/T202) | 71,8566 / 8610,32 | 72,004 | 72,004 / 8612,39 | PASS, +0,1474 |
| T115 | 99,4146 / 8543,02 | 99,4367 | 99,4367 / 8543,04 | PASS, +0,0221 |
| T174 | 94,905 / 8485,71 | 95,1197 | 95,1197 / 8486,96 | PASS, +0,2147 |
| T136 | 100,8793 / 8481,93 | 100,9724 | 100,9724 / 8481,88 | PASS, +0,0931 |
| T001 / T002 / T184 (A0) | 97,0934 / 93,599 / 93,4567 | 93,982 / 93,2289 / 93,0321 | sama dengan V15.27 | PASS |
| T179, T227, T228 (Maximum Review vs Fastest) | — | — | 93,2509 / 92,7208 / 93,2509 | PASS |
| A1 / A2 / A7 / Fix Load (smoke) | 68,4413 / 73,9965 / 122,0047 / 92,8227 | 68,294 / 73,9965 / 122,0047 / 92,8227 | sama dengan V15.27 | OK |

Winner V15.25 untuk T017, T046, T115, T174, T136 lolos seluruh validator V15.27 (assess valid, hard constraint 0 pelanggaran, Priority Invariants
PASS) dan lebih murah -> search miss (bukan pelanggaran). T046: redispatch commitment final memberi 71,3309 tetapi ditolak karena row 15 Export 4,72 MW
< RangeMin 15 MW (margin −10,28 MW). T136: row actual 1–8 identik; selisih hanya row mendatang 22–36.

## Actual Gas dua arah (suite penuh 29 kasus, source V15.28 sebelum perubahan "hasil distillate tidak publish")
CP identik dengan V15.27 pada 29/29 kasus; dingin == warm 29/29; jam actual immutable 29/29; warm ulang identik 29/29; CP(lebih tinggi) > CP(lebih rendah)
10/10 pasangan (PGN, Fixed Flow Jababeka, MM2100). Warm median 1,44 s (mesin lebih lambat pada sesi ini), 21/29 ≤ 5 s; kombinasi ketiganya tetap
USER_ACTION_REQUIRED (sama dengan V15.25–V15.27). `harness/v1528/ag_summary.md`.

## Runtime 1 CPU (A0 cold, sesi yang sama, berurutan, CPU 1)
V15.25 48,95 s — V15.27 54,38 s — V15.28 52,20 s (195 simulasi vs 194). Mesin pada sesi ini ~30 % lebih lambat dari sesi benchmark V15.27
(A0 V15.27 40,8 s; exact rerun 0,49 -> 0,63 s pada kode sama). V15.28 tidak menambah simulasi kecuali T236-type (Use Distillate tanpa liter + shortage
kecil): satu job gas-only tambahan (T236 345 s). Target 30/40/10 s belum tercapai; V15.28 masih ~7 % lebih lambat dari V15.25 pada A0.

## Limitation
1. Runtime dingin dan Fix Load di atas target; tidak lebih cepat dari V15.25.
2. Search miss tersisa: T017 +0,33, T046 kelompok +0,15, T115 +0,02, T174 +0,21, T136 +0,09 (winner V15.25 legal).
3. Use Distillate tanpa liter + shortage kecil menjalankan dua job (runtime ×2 pada kasus itu).
4. Full matrix 236 belum dijalankan pada V15.28 (ditunda ke sesi berikutnya).

## Paket
V15.28: `XAMPP_V1528_RC_FULL_DEPLOYMENT.zip` SHA-256 `ab8fa3fa2cd13409342d4d11e86636ec544a939f21a100149efb2468d9088552`. V15.29: `dist/v1529/XAMPP_V1529_RC_FULL_DEPLOYMENT.zip` (SHA di SHA256SUMS_ZIP.txt). Clean install dari ZIP: sha256/lint OK, schema MySQL OK, HTTP 200, A0 CP 93,0321 publish (43,4 s). (Salinan REPORT di dalam ZIP dibuat sebelum baris SHA ini ditambahkan.)
