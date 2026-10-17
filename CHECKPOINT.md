# CHECKPOINT V15.24 — Order-Independent Commitment Tightening + Final-Dispatch Event Proof + 192+ Regression

**Baseline:** `XAMPP_START_PRIORITY_RC_V1523_COMMITMENT_TIGHTENING.zip` (V15.23, di atas V15.22; semantik Initial Status / Commitment Mode / Stop Status / Block Required / Save-Reload, fuel-state assessment, latest legal start / earliest legal stop, guard V8/Fix dipertahankan).
**Output:** `XAMPP_FINAL_VALIDATED_PATCH.zip`, `XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT.zip`, `XAMPP_FINAL_VALIDATED_DOCUMENTATION.zip`.
**Engine build:** `V15.24-ORDER-INDEPENDENT-TIGHTENING-FINAL-PROOF-20261017`. **Branch:** `claude/keen-galileo-sjjhsk`.
**File diubah terhadap V15.23:** `run.php`, `worker02.php` (satu titik: basis neto Effective Total Gas). UI tidak diubah.

## Ringkasan perubahan (detail: REPORT.md di paket dokumentasi)
1. Commitment Tightening order-independent (best-improvement per ronde, ronde tidak pernah terpotong, log kanonik, galloping latest-legal-start / earliest-legal-stop, prescreen monoton terbukti).
2. Comparator V15.24 mengikuti urutan Bagian VII (inversi running-unit-first -> startup tak-wajib -> blok baru -> CP ekonomi band 0,02% -> HR -> startup -> unit-row -> start lebih lambat -> stop lebih awal -> sidik jari).
3. Bukti event pada dispatch FINAL + penutupan (closure) di jalur Fastest dan warm/Maximum Review; bukti event final dipakai sebagai bukti numerik C2.
4. Basis warm IE eksak per profil (rerun tidak bergantung riwayat).
5. Terminal TERMINAL_INFEASIBLE_FUEL_CAP (plafon distillate / LNG otorisasi < kebutuhan terbukti, sisa > batas atas penurunan gas audit) dengan opsi numerik.
6. Effective Total Gas memakai basis neto (offset distillate) — Actual Gas pada skenario distillate tidak lagi dinilai salah.
7. Core count = min(processor, affinity, cgroup) -> 1 CPU tanpa helper time-slicing; diagnostik topologi.
8. Hasil helper: SHA sidecar + 48 row + tidak terpotong; ambil alih helper hang/lambat; hook injeksi kegagalan (non-aktif).
9. Precheck Fix Load menolak Fixed Load pada/sesudah row Stop Request.

## Validasi (PHP 7.4.33, worker dipin 1 logical CPU)
- Skenario aktual A0: CP 105,8901 (V15.22) -> **93,4567**; event G9+05:00, G6+10:00, G3 05:30–17:00, G5 07:30–18:30, G1 tidak start; Event Proof **TERBUKTI**.
- Urutan kandidat: 3 seed acak identik (T185–T187); cache/pool/prescreen ON/OFF identik (T189–T192).
- Matrix: 207 test engine + 14 test DB/UI-store (grup I, H15, H16) + 26 UI smoke; hitungan PASS / EXPECTED_REJECTION / FAIL dan Test ID per grup di REPORT.md bagian 2.
- Helper failure (multi-core, dipisahkan): normal / crash / hang / malformed / partial / satu lambat / semua gagal -> hasil identik referensi serial.
- Benchmark 1 CPU before/after (3 cold + 3 warm): REPORT.md bagian 3.

## Keterbatasan (tidak dipalsukan PASS) — REPORT.md bagian 6
Daily Export target REQUIRED (lantai per row dari lift target mustahil di row 1; identik V15.22); Actual Gas menyimpang + aksi distillate aktif (tanpa kompensasi/sertifikat; V15.22 juga gagal); trip unit besar G9 tanpa commitment valid (identik V15.22); Change Over legal tanpa kandidat sweep; runtime 1 CPU di atas target pada skenario normal/sulit.

## Flag rollback (env)
`PP_V1524_CMP=0`, `PP_V1524_CLOSURE=0`, `PP_V1524_EVENT_PROOF=0`, `PP_V1524_IE_EXACT=0`, `PP_V1524_FUEL_CAP_TERMINAL=0`, `PP_V1524_MONO=0`, `PP_V1524_REUSE=0`, `PP_V1524_C2_EVENT_PROOF=0`, `PP_V1523_TIGHTEN=0`.
