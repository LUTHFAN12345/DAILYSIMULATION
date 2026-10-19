# CHECKPOINT V15.31 — INCOMPLETE (source checkpoint, BUKAN deployment)

Status: pekerjaan instruksi `INSTRUKSI_FOKUS_CLAUDE_V1530_RUNTIME_MONITORING_ACTUAL_GAS.md` dihentikan karena batas credit.
Paket `SOURCE_V1531_INCOMPLETE_CHECKPOINT.zip` = source kerja untuk melanjutkan. Tidak ada deployment ZIP baru. Tidak ada klaim FINAL.

---

## A. BASELINE

| item | nilai |
|---|---|
| Deployment baseline terakhir | `dist/v1529/XAMPP_V1529_RC_FULL_DEPLOYMENT.zip` (SHA-256 `1d0f7286380079a43752d07cf563e068a8947f9b871c6d02c9cdd890f06e4aae`) |
| Commit V15.29 | `52c0b4b` |
| Commit sebelum pekerjaan terbaru (V15.30, perbaikan CP) | `1d3f4e9` |
| Commit WIP V15.31 | `1bc1e4d` → `d03320e` → `b7db2dc` |
| Commit checkpoint | lihat `git log -1` pada branch (commit "V15.31 INCOMPLETE checkpoint"); hash dicatat juga di SHA256SUMS_CHECKPOINT.txt |
| Branch | `claude/keen-galileo-sjjhsk` (repo `luthfan12345/dailysimulation`) |
| Status working tree saat checkpoint | clean sesudah commit checkpoint (file sementara tidak di-commit) |
| PHP | 7.4.33 (`/opt/php74/bin/php`), OPcache aktif; PHP 8.3 + Xdebug hanya dipakai untuk profil relatif satu simulasi |
| MariaDB | 10.11.14 (tidak dipakai pada test sprint ini; store Report legacy/JSON di server uji `php -S`) |
| CPU | 4 logical CPU; setiap run dipin satu CPU (`taskset -c N`); run paralel 2–4 job (waktu mengandung kontensi ringan) |
| Instruksi berjalan | `INSTRUKSI_FOKUS_CLAUDE_V1530_RUNTIME_MONITORING_ACTUAL_GAS.md` (dan sebelumnya `..._V1529_ROOT_CAUSE_CP_RUNTIME.md`) |
| Fixture | `/tmp/w/reg/A0_actual.json`, `A1_gas_cukup.json`, `A2_lng.json` (salinan lokal; TIDAK ikut repo — harus dibuat ulang dari fixture harness/matrix bila container baru), input matrix `/tmp/w/mx25h/runs/*.in.json` (payload V15.25 per test) |

Catatan lingkungan: direktori `/tmp/w` (fixture, output job, app uji) hilang bila container baru. Fixture A0/A1/A2 berasal dari `harness/v1526/matrix/mxdefs.py` (fixture `A0_actual` dst.) / workbook proyek.

---

## B. STATUS PEKERJAAN

| item | status |
|---|---|
| V15.30 perbaikan CP (T017 = V15.25, T115 90,2185, T174 94,2979) | DONE + TESTED (targeted; full matrix belum) |
| Resolver kanonik Actual Gas kumulatif (latest populated row per supplier) | DONE + TESTED (unit 16/16, engine 16/16 cek) |
| Time Passed maju saat Save + lock UI Actual Gas | DONE + TESTED (UI Playwright 17/19 + 2 FAIL dijelaskan di D) |
| Lock backend (409 TIME_PASSED_LOCKED) + unlock resmi dipersist | DONE + TESTED (UI T08, unit T08/T11) |
| Header PGN/Jababeka/MM2100 dari resolver backend | DONE + TESTED (UI T16/T17/T18/T20b) |
| Warm Actual Gas A1 (bukti wajib V8 tidak dipotong deadline) | IMPLEMENTED, TESTED targeted (A1 PGN warm FINAL 7,4 s); suite legacy belum lengkap |
| Report/Database (MySQL) path untuk snapshot | IMPLEMENTED BUT NOT FULLY TESTED (hanya store legacy diuji) |
| Concurrency/versioning dua tab | NOT STARTED (tidak ada perubahan; mekanisme state_revision lama) |
| Update lower sesudah unlock (incremental) | PARTIAL — warm DEADLINE_REACHED (keterbatasan jalur warm lama, terbukti juga tanpa lock) |
| Dependency GTG–STG di resolver bersama | IMPLEMENTED, resolver test 21 PASS / 1 FAIL / 1 NOT_RUN; engine smoke 1 (T220) |
| Preview dependency UI (backend `sem_preview`) | IMPLEMENTED BUT NOT FULLY TESTED (tidak ada UI test) |
| Runtime T236/gas-cukup ≤ 40 s, Fix Load ≤ 10 s, A0 ≤ 30 s | NOT DONE (analisis root cause ada; tidak ada pemangkasan eksak yang aman ditemukan) |
| T046 grup | NOT STARTED (start point PRE_CONSOL terbukti tidak mereproduksi; dibuang) |
| Suite Actual Gas legacy (A0 Distillate, A2 LNG) | BLOCKED BY CREDIT LIMIT (terputus, parsial — lihat H) |
| Affected regression, full matrix, clean install, packaging | BLOCKED BY CREDIT LIMIT / NOT STARTED |

---

## C. PERUBAHAN SOURCE TERBARU (terhadap `1d3f4e9`)

### Production
| file | fungsi / blok | tujuan | status test | flag | risiko |
|---|---|---|---|---|---|
| `worker_functions.php` | `pp_actual_gas_fields`, `pp_actual_gas_canonical`, `pp_actual_gas_apply_snapshots`, `pp_actual_gas_lock_violations` (awal file) | resolver tunggal snapshot kumulatif, turunan array engine (total kumulatif dibagi rata ke jam 1..h, jam sesudah h = estimasi), validasi lock | unit 16/16, engine PASS | — (aktif hanya bila `modeling.actual_gas_snapshots` ada) | per-jam actual = rata (bukan meter jam); total benar |
| `worker_functions.php` | `pp_v1518_semantics`: `$upCfg`, loop STG→lead (lead harus CC & legal, `lead_fallback` reason code), loop baru GTG CC→STG | dependency GTG–STG | resolver 21/1/1; T220 identik | `PP_V1531_DEP=0` | STG ditambahkan ke required_units bila GTG CC diminta & STG belum diminta (1/184 fixture berubah semantik, hasil identik) |
| `worker02.php` | info output `Actual Gas Canonical` | echo kanonik untuk header/echo check | PASS | — | — |
| `run.php` | route HTTP: apply snapshot + lock check (mode run/save/ag_precheck), route `ag_canonical`, `sem_preview`, `actual_canonical` di `ag_precheck` | backend kanonik, lock API, preview | UI PASS | `PP_V1531_TP_LOCK=0` | lock memakai `sds_latest_input(uid)` sebagai referensi (scope per user + plan_date/name) |
| `run.php` | `pp_job_start`, `pp_normalize_copy` memanggil `pp_actual_gas_apply_snapshots` | semua jalur job/validator memakai array turunan sama | engine PASS | — | — |
| `run.php` | `pp_v3_meta_keys` + `actual_gas_snapshots` | snapshot = arsip, tidak masuk hash state | unit T24 PASS | — | — |
| `run.php` | `pp_v1531_mdp_strip` dipakai `pp_v1517_ie_base_key`, `pp_v1522_struct_hash` | cutoff Time Passed tidak membuang basis warm / precheck | UI PASS (Save FINAL warm) | `PP_V1531_TP_KEY=0` | basis warm berganti kunci sesudah deploy (aman) |
| `run.php` | review V8: `$C[$i]['mandatory']` + `pp_v8_eval_all` deadline `$dlI` | counterfactual DECOMMIT wajib tidak dipotong anggaran (warm A1 UNREQUESTED_START_NO_EVIDENCE) | A1 warm FINAL; A0/T236 tetap | `PP_V1531_MANDATORY_DL=0` | warm bisa lebih lama hingga +60 s/kandidat wajib (pengaman) |
| `run.php` | V15.30: `pp_v1527_tighten_pre_v8` (PRE_V8_PRIO_ORDER, rantai final + memo `ppV1530ChainMemo`, dedup dispatch), `pp_v1523_tighten` (kunci cache bersama lengkap) | perbaikan CP | targeted PASS | `PP_V1530_PREV8_INV`, `PP_V1530_TCACHE_KEY`, `PP_V1530_SP_FINAL`, `PP_V1530_SP_BAND` | runtime naik (lihat P3) |
| `run.php` | build id `V15.31-RC-MONITORING-CUMULATIVE-DEP-20261019`; `PP_V1523_DUMP` dump per panggilan (harness) | — | — | — | — |
| `saved_data_store.php` | `rs_final_guard` memanggil `pp_actual_gas_apply_snapshots` | echo Report = array turunan | tidak diuji MySQL | — | — |
| `index.php` | `ppAgSnapKey/ppAgCanonForInput/ppAgPaintHeader/ppAgRefreshCanon`, `agEchoMatches` (cabang snapshot), `saveActualGas` (snapshot, header + cutoff dari `pre.actual_canonical`), `assembleInput` (snapshot di Monitoring), render sel (tampilkan snapshot), lock tidak dibuka `applySimViewMode`, `dpDeriveBranchState` (hapus snapshot), dropdown TIME PASSED (unlock dipersist `_tp_unlock`), `ppDepPreview` + `renderSemSummary` | UI Monitoring + preview dependency | UI 17/19 | — | preview dependency tanpa UI test |
| `CHECKPOINT_RECOVERY.md`, `REPORT_RECOVERY.md`, `DEPLOYMENT_MANIFEST.md` | seksi V15.30/V15.31 | dokumentasi | — | — | REPORT_RECOVERY.md belum memuat seksi V15.31 (checkpoint ini sumber utama) |

### Harness / evidence (`harness/v1530/`, `harness/v1531/`)
| file | isi |
|---|---|
| `harness/v1531/ag_cum_unit.php` | unit test resolver + lock + hash (16 tes; evidence `evidence/ag_cum_unit.json`: 16 PASS) |
| `harness/v1531/ag_cum_engine.py` | engine warm dari basis A0: 3,0→4,5 (=4,5), histori diabaikan bit-identik, setara legacy, higher/lower 3 supplier, nol vs kosong, median warm 1,3 s (evidence `ag_cum_engine.json`, semua cek PASS; cek zero_vs_empty diperbaiki skrip) |
| `harness/v1531/ui_monitoring_ag.js` | Playwright UI Monitoring (fixture A1, server `php -S`): evidence `ui_monitoring_ag.json` 17 PASS / 2 FAIL |
| `harness/v1531/shdiff.php` | diagnostik: diff bentuk structural-hash dua payload |
| `harness/v1531/capprobe.php` | diagnostik: batas atas kapasitas Export alternatif konsolidasi (membuktikan tidak ada sertifikat untuk NO_ADDITIONAL_PEER A1) |
| `harness/v1531/dep_gtg_stg_unit.php` | test resolver GTG–STG (evidence `dep_gtg_stg_unit.json`) |
| `harness/v1531/evidence/dep_impact_fixtures.txt`, `dep_T220_onoff.txt` | dampak dependency pada 184 fixture |
| `harness/v1531/evidence/partial_interrupted/*.log` | suite Actual Gas legacy TERPUTUS (parsial) |
| `harness/v1530/*` | instrumen counter simulasi (`instr.py`, `prof.py`, `stg2.py`), replay tightening (`tdump*.php`, `tchain.php`), evidence targeted V15.30 |

---

## D. MONITORING DAILY PLAN / ACTUAL GAS

| kebutuhan | status | bukti |
|---|---|---|
| Time Passed → Y setelah Save (row actual terbaru + seluruh row sebelumnya) | IMPLEMENTED + TESTED | UI T01/T02/T03 (cutoff 2/6/8), T04 row sesudah cutoff N |
| Seluruh row s/d cutoff dikunci (sel Actual) | TESTED untuk sel Actual Gas (UI T07, T20a: applySimViewMode/Full Screen tidak membuka); Ctrl+Click Fix Load/Fixed Flow sudah ditolak di versi lama | input IE/dispatch tab lain belum diaudit |
| Actual PGN/Jababeka/MM2100 terkunci saat Y | TESTED (UI T07; unit T08 PGN & Jababeka) | — |
| Kanonik = row terisi paling akhir per supplier | TESTED (unit T09/T10/T13, engine 4,5 bukan 7,5; UI T09/T10 0,30 bukan 0,43) | — |
| Snapshot lama tidak dijumlahkan, tetap tersimpan | TESTED (unit, engine bit-identik, UI T09 sel 0,13 tetap terkunci) | — |
| Header diperbarui setelah Save | TESTED (UI T16 Jababeka, T17 PGN, T18 tiga supplier, T20b reload) | — |
| Save/Reload | Reload: cutoff + snapshot + header PASS (T05/T20b); tabel tidak dirender sesudah reload bila belum ada OUTPUT tersimpan (perilaku lama) | report/version reopen BELUM diuji |
| Report/Database MySQL | NOT TESTED | guard Report memakai array turunan (kode) |
| Incremental rerun | Save PGN/Jababeka/MM2100 FINAL warm 2,5–10,3 s pada A1 (UI); engine warm median 1,3 s (A0) | update LOWER sesudah unlock → DEADLINE_REACHED (UI T19/T11 FAIL; header benar) — keterbatasan jalur warm (juga gagal tanpa lock: Total Gas 79,62 vs window 81,9) |
| Backend/API lock | TESTED (UI T08: HTTP 409 TIME_PASSED_LOCKED) | — |
| Concurrency/versioning | NOT STARTED | — |
| Save tanpa perubahan tanpa rerun | TESTED (UI T26 adjust=false) | — |

Hasil test Monitoring: unit 16/16 PASS; engine 16 cek PASS; UI 17 PASS / 2 FAIL (T19 dan T11: adjustment update lower sesudah unlock DEADLINE_REACHED; header 0,13 benar). Dari 27 targeted tests instruksi: belum diuji = #6 reopen report/version, #20 version switch, #27 simultaneous tabs; #12 lower sesudah unlock FAIL di UI. **Bagian ini BELUM lulus penuh.**

Catatan nilai uji UI: angka contoh instruksi (PGN 3,0/4,5, Jababeka 1,9) dipakai di unit/engine (A0). Pada A1 angka itu infeasible secara fisik (estimasi PGN ±0,065 BBTUD/row) sehingga UI end-to-end memakai 0,13/0,30 dan Jababeka 6,18 (= jadwal 3 jam).

---

## E. DEPENDENCY GTG–STG

- Audit existing: ADA PARSIAL. `worker_functions.php::pp_v1518_semantics` sudah memuat STG→lead GTG (lead = GTG effective priority pertama yang available; Continuous → lead Continuous; Stop Status hold diteruskan); Block Required kanonik (lead CC + STG continuous); GTG→STG hanya implisit lewat fisika `pp_recompute_stgs`. UI (`index.php` `semStgAutoFollow`) hanya pesan info (Addendum A: UI tidak menulis field turunan).
- Diubah (resolver yang sama, tidak ada resolver kedua):
  - STG→GTG: lead wajib CC (GTG SC dilewati), unavailable all day dilewati; `lead_fallback` reason code (`MODE_SC`, `UNAVAILABLE_ALL_DAY`, `NOT_LEGAL`) dari urutan prioritas terkonfigurasi; `auto_dependency_source = STG_Sx_REQUIRES_LEAD_GTG`.
  - GTG→STG (baru): GTG diminta (Simulation/Request/Continuous) mode CC, STG belum diminta, tidak ada GTG blok lain Running → STG `START_BASED_ON_SIMULATION` (tidak Running palsu), Stop Status Continuous diteruskan bila GTG itu lead tunggal (hold/continuous); GTG SC tidak memicu; `auto_dependency_source = GTG_Gx_CC_REQUIRES_STG`.
  - Mapping blok dari `data3[sN].gtg` dan `unit_priority` (generik S1/S2/S3).
  - Start Based On Request: lead = Required (Simulation); engine sequence (R2) memajukan lead — belum diverifikasi engine untuk request.
  - Unit Continuous Running: tidak diubah (lihat T05).
- Save/Reload/stale: resolver stateless dari payload user (idempoten, T20 PASS); auto-derived tidak ditulis ke pilihan user.
- Validator/proof: tidak ada perubahan; invariant/gerbang lama tetap. Proof dependency khusus (lead, first GTG/STG load) belum ditambahkan.
- Preview UI: `mode=sem_preview` + `ppDepPreview` (belum diuji UI).

Hasil `dep_gtg_stg_unit.php` (fixture A1): **21 PASS, 1 FAIL, 1 NOT_RUN**.
- PASS: T01–T03 (S1/S2/S3 lead = G6/G2/G8), T04, T06, T07 (fallback G4 + `UNAVAILABLE_ALL_DAY`), T08, T09-S1/S2/S3, T10, T11, T12, T13, T14, T15, T16, T17, T18, T20, T21.
- FAIL **T05** (STG Unit Continuous Running dari Initial Stop): resolver menormalisasi STG dan lead menjadi `Running` + `CONTINUOUS_FULL_HORIZON` — semantik lama V15.18 §1.1 (`pp_v1518_semantics` baris `if ($md === 'continuous') … unit_last_data_status = 'Running'`), dan UI selalu memaksa Last Status Running saat Continuous dipilih. Instruksi meminta startup legal tanpa Running palsu → butuh perubahan semantik continuous (berdampak luas: invariant I1/I4, UI, banyak fixture). Belum diubah.
- NOT_RUN **T19** (Check Yesterday Data Start Up CC tidak direset resolver): tidak ada fixture Check Yesterday pada sprint ini.
- Test instruksi 22–24 (cold/warm/cache ON/OFF, Fastest vs Maximum Review, CP/HR optimal sesudah dependency): hanya dicakup T220 engine (ON/OFF identik). Lainnya NOT_RUN.
- Dampak fixture: dari 184 fixture (reg A0–A8/B0 + 174 input matrix V15.25) hanya **T220** berubah semantik (S3 Required + hold dari G9 CC); T220 dependency ON/OFF identik: FASTEST_FINAL CP 115,5544, HR 8860,62, G9 start row 12, S3 row 16–48.

---

## F. HASIL YANG WAJIB DIBEKUKAN

| test | nilai | bukti terakhir |
|---|---|---|
| A0 | CP 93,0321 (HR 8481,88), publish | source final V15.31 run 69,9 s |
| T236 | CP 68,294, Distillate 0 l | source final V15.31 run 131,4 s |
| T017 | CP 97,2551 (= V15.25) | V15.30 |
| T115 | CP 90,2185 (< V15.25 99,4146), valid, invariants PASS | V15.30 |
| T174 | CP 94,2979 (< V15.25 94,905), valid, invariants PASS | V15.30 |
| T118 | CP 92,9735 | V15.30 |
| T121 | CP 92,7266 | V15.30 |
| T220 | CP 115,5544 | V15.31 dependency ON/OFF |
| Fix Load (A0 + g2 row 20 = 25 MW) | CP 92,8227 | V15.30 |
| Hard validation + Priority Invariants | PASS pada T017/T115/T174 (chk.php) dan seluruh run FINAL di atas | — |

---

## G. PEKERJAAN YANG BELUM SELESAI (prioritas)

**P0** — T05 (keputusan semantik continuous dari Initial Stop untuk STG/lead); T19 NOT_RUN (fixture Check Yesterday); Monitoring end-to-end: report/version reopen, MySQL Report/DB, version switch, dua tab/concurrency, update lower sesudah unlock (warm DEADLINE_REACHED), lock seluruh input row Y di luar sel Actual.

**P1** — Suite Actual Gas legacy diulang dari awal (A0_distillate, A2_lng, A0_fixload, A0_supplier_window, MM2100_active): higher/lower PGN, Fixed Flow Jababeka, MM2100, cold/warm equivalence, repeatability. Hasil parsial tidak dihitung PASS.

**P2** — GTG–STG: UI preview test, payload Save/Reload, worker/proof dependency (lead, first GTG/STG load, reason), CC vs SC, ketiga Block secara engine, Start Mode/Stop Status, Fastest vs Maximum Review, cold/warm.

**P3** — Runtime (belum ada pemangkasan eksak aman): A0 ±69,9 s (target 30), T236 ±131,4 s (target 40), A1 gas-cukup ±135 s; fallback konsolidasi A1 194 sim/57,6 s (NO_ADDITIONAL_PEER 96 sim/29,7 s invalid; batas kapasitas tidak membuktikan); Fix Load full cold review (±59 s; warm V15.26 MISS + jalur warm tanpa tightening berbeda dari cold di T174); biaya start point/closure V15.30; `pp_recompute_stgs` (23 752 panggilan/sim, sudah dimemoisasi), `pp_shape_final_dispatch`, closure `worker_functions.php:12187`; validated-prefix / suffix-only; dedup final bundle/proof.

**P4** — T046/T057/T199/T202 (72,004 vs 71,8566): lineage V15.25 = V8 pada incumbent pra-konsolidasi lalu tightening; perbaiki hanya bila tanpa runtime material.

**P5** — targeted + affected regression, full matrix sekali, benchmark PHP 7.4.33 satu CPU, clean install, paket deployment final.

---

## H. BACKGROUND COMMAND YANG TERPUTUS

Dihentikan oleh restart worker/weekly limit; tidak ada proses yang berjalan saat checkpoint (diverifikasi `ps`).

| suite | command | selesai | output parsial | dapat dipakai? |
|---|---|---|---|---|
| Actual Gas legacy A0 Distillate | `python3 -I harness/v1526/actual_gas/ag_tests.py <src> 3 /tmp/w/ag31a A0_distillate` | 14 varian (PGN/FFJ eq0, hi/lo 10 % jam 1 & 1–4, ALL3 hi/lo, PGN hi/lo 80 % jam 1–8); warm = cold dan repeatable pada 14/14, actual immutable 14/14; MM2100-only belum tercapai | `harness/v1531/evidence/partial_interrupted/ag_A0_distillate_PARTIAL.log` | indikasi saja — BUKAN PASS final; jalankan ulang dari awal |
| Actual Gas legacy A2 LNG | `python3 -I harness/v1526/actual_gas/ag_tests.py <src> 2 /tmp/w/ag31b A2_lng` | 5 varian (PGN eq0/hi/lo, FFJ hi/lo), warm = cold 5/5 | `.../ag_A2_lng_PARTIAL.log` | indikasi saja — jalankan ulang |
| Monitor tunggu suite (`bvovq29yn`) | until-loop | dihentikan batas waktu | — | — |
| Server uji `php -S 127.0.0.1:8931–8934` | — | dihentikan | — | — |

Catatan: suite dijalankan dengan source sebelum perubahan build id (identik logika). Status ALL3_lo10 = tidak publish (status null, residual −0,61; identik warm/cold, sama dengan versi sebelumnya: USER_ACTION_REQUIRED/terminal untuk kombinasi tiga supplier).

---

## I. LANGKAH LANJUT CHAT BARU (hemat credit)

1. Baca checkpoint ini + `CHECKPOINT_RECOVERY.md`.
2. Checkout commit checkpoint.
3. Verifikasi source clean (`git status`), `php -l` seluruh PHP.
4. Selesaikan T05 (keputusan semantik) dan T19 NOT_RUN; ulang `dep_gtg_stg_unit.php`.
5. Jalankan ulang suite Actual Gas legacy (semua basis) dari awal.
6. Selesaikan targeted GTG–STG (engine + UI preview).
7. Optimasi runtime (P3).
8. Targeted smoke (A0, T236, T017, T115, T174, T118, T121, T220, Fix Load).
9. Affected regression.
10. Full matrix sekali.
11. Clean install.
12. Packaging final.

---

## J. COMMAND RESUME

```bash
# checkout
git fetch origin claude/keen-galileo-sjjhsk && git checkout claude/keen-galileo-sjjhsk && git pull origin claude/keen-galileo-sjjhsk
git status --short; for f in *.php; do /opt/php74/bin/php -l $f; done
# fixture (bila /tmp/w hilang): buat ulang /tmp/w/reg/{A0_actual,A1_gas_cukup,A2_lng}.json dari harness/v1526/matrix (mxdefs.py fixture) / workbook
mkdir -p /tmp/w/one /tmp/w/rc
# T05 + NOT_RUN (resolver GTG–STG)
/opt/php74/bin/php harness/v1531/dep_gtg_stg_unit.php $PWD /tmp/w/reg/A1_gas_cukup.json
# resolver + lock Actual Gas
/opt/php74/bin/php harness/v1531/ag_cum_unit.php $PWD
# engine Actual Gas kumulatif (warm dari basis A0, 1 CPU)
python3 -I harness/v1531/ag_cum_engine.py $PWD 0 /tmp/w/agc
# suite Actual Gas legacy (ulang dari awal; satu basis per CPU)
for b in A0_distillate A2_lng A0_fixload A0_supplier_window MM2100_active; do python3 -I harness/v1526/actual_gas/ag_tests.py $PWD 1 /tmp/w/ag_$b $b; done
# UI Monitoring (server uji fixture A1)
U=/tmp/w/uim; mkdir -p $U/jobs; cp *.php $U/; cp -r config install database $U/; cp /tmp/w/reg/A1_gas_cukup.json $U/input_data.json
(cd $U && /opt/php74/bin/php -S 127.0.0.1:8934 -t $U &) ; node harness/v1531/ui_monitoring_ag.js http://127.0.0.1:8934 /tmp/w/ui_mon.json
# targeted job satu CPU
bash harness/v1530/oneapp.sh $PWD 0 A0 /tmp/w/reg/A0_actual.json
# Fix Load (basis + fix pada app yang sama)
python3 harness/v1530/fixrun.py $PWD 0 FX
# benchmark satu CPU
python3 -I harness/v1526/bench/bench.py $PWD 1 /tmp/w/bench31 v1531
# full matrix (sekali, sesudah source stabil)
MX_APP=$PWD MX_EVD=/tmp/w/mx31 MX_CPUS=0,1,2,3 python3 harness/v1526/matrix/mx.py
# clean install
mysqld_safe --socket=/run/mysqld/mysqld.sock & bash harness/v1527/pack/pack.sh /tmp/w/pack31 XAMPP_V1531_RC   # lalu ikuti install/ (schema + HTTP smoke)
```

---

## K. ACCEPTANCE GATE

Jangan menyebut selesai sebelum: 0 FAIL material; 0 NOT_RUN material; Monitoring Actual Gas end-to-end PASS (termasuk report/DB/concurrency);
GTG–STG dependency PASS; seluruh CP di bagian F sama atau lebih baik; runtime membaik terhadap V15.25 dan target; affected regression PASS;
full matrix final PASS; clean install PASS.
