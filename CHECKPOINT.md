# CHECKPOINT V15.27 — Objective ekonomi dulu, audit CP 41 test, envelope gas T139, runtime

**Baseline:** V15.26 (`6e03d39`, release candidate runtime gate open). **Engine build:** `V15.27-ECON-OBJECTIVE-START-POINTS-20261019`.
**Branch:** `claude/keen-galileo-sjjhsk`. **File runtime diubah terhadap V15.26:** `run.php`, `worker02.php`, `worker_functions.php` (+ CHECKPOINT/DEPLOYMENT_MANIFEST/SHA256SUMS).

## Ringkasan perubahan (detail: REPORT.md)
1. Objective final satu urutan untuk search, stage guard, konsolidasi, Fastest dan Maximum Review: hard -> intent -> tanpa blocking -> startup otomatis
   tak perlu -> CP ekonomi -> CP resmi -> HR -> headroom prioritas (tie-break). Unit Priority tetap untuk alokasi, tunda start, stop awal, eliminasi start tak perlu.
2. Local search: titik awal tightening ganda (urutan ekonomi, urutan prioritas, sebelum V8) dinilai sesudah 2+2 + Unit Priority Fix; closure bukti event
   menilai langkah terbaik kedua urutan sesudah fix.
3. warm == dingin: IE atau Fix Load berubah -> review commitment penuh; Actual Gas saja tetap warm (rencana jangkar).
4. T139: sertifikat envelope gas di jalur Fastest dengan feasible envelope + residual setiap target.
5. Runtime eksak: offset kuota JBBK tanpa probe berulang, lewati pemeriksaan HRSG stop kosong; window supplier berhenti sesudah satu langkah stagnan.

## Validasi (PHP 7.4.33, 1 logical CPU per run)
- Matrix engine 236 test: 200 PASS, 35 EXPECTED_REJECTION, 1 FAIL (T236, identik V15.25).
- CP vs V15.25 (229 test): 163 lebih rendah, 54 sama, 11 lebih tinggi (E/G, REPORT.md bagian 1); 41 test V15.26: 36 FIXED.
- Fastest vs Maximum Review: objective sama, sensitivitas 0/101 pemenang berubah.
- Audit 48 row A0, A1, T034, T154, T214: 48/48, HPA PASS_ALL_FINDINGS_PROVEN.
- Actual Gas dua arah: 29 kasus, 23 publish, dingin == warm 29/29, actual immutable 29/29, warm ulang identik 29/29, CP(lebih tinggi) > CP(lebih rendah) 10/10 pasangan, warm median 0,83 s.
- Benchmark 1 CPU V15.25 vs V15.27 (CPU sama, berurutan): REPORT.md bagian 7.

## Status paket
Runtime dingin 1 CPU dan Fix Load masih di atas target -> paket release candidate `XAMPP_V1527_RC_RUNTIME_GATE_OPEN_*` (bukan FINAL/VALIDATED).

## Flag rollback (env)
Lihat DEPLOYMENT_MANIFEST.md (`PP_V1527_*`; flag V15.26/V15.25 tetap berlaku).
