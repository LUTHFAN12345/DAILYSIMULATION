# CHECKPOINT V15.26 — Prioritas per row, Actual Gas dua arah, konsolidasi blok, runtime

**Baseline:** V15.25 (`debf01f`, release candidate runtime gate open). **Engine build:** `V15.26-PRIORITY-ACTUALGAS-CONSOLIDATION-20261018`.
**Branch:** `claude/keen-galileo-sjjhsk`. **File runtime diubah terhadap V15.25:** `run.php`, `worker02.php` (+ CHECKPOINT/DEPLOYMENT_MANIFEST/SHA256SUMS).

## Ringkasan perubahan (detail: REPORT.md)
1. Rule 1 per row: biaya marginal (EKONOMIS) bukan lagi pengecualian prioritas; Unit Priority Fix memindah beban ke unit Running prioritas tinggi;
   7 invariant Rule 1 dihitung pada setiap hasil dan menjadi gerbang publish; metrik inversi tanpa false positive (bukti kapasitas/event).
2. Satu objective final (`pp_sp_final_cmp`) untuk seleksi final Fastest, Maximum Review, dan konsolidasi blok.
3. Konsolidasi blok: alternatif dievaluasi dengan jendela tahan incumbent lalu fallback start aturan lama + pendaratan gas (superset V15.25); semua alternatif tak-dominated dinilai; permintaan start user tidak boleh bergeser; bukti lintas blok / DECOMMIT di batas Export dilengkapi.
4. Actual Gas: jangkar jam actual (dispatch jam lampau = rencana state tanpa Actual Gas; dingin == warm), estimasi jam actual = estimasi per row yang
   ditampilkan (Jababeka, MM2100, realokasi FF JBBK), GE1–GE4 ikut dikunci, kuota MM2100 menyerap deviasi actual (tahap 0c).
5. Tightening: ADVANCE_REPLACE+PEER mencoba peer satu blok dan lintas blok.

## Validasi (PHP 7.4.33, 1 logical CPU per run)
- Matrix engine 236 test: 199 PASS, 35 EXPECTED_REJECTION, 2 FAIL (T139, T236 — lihat REPORT.md).
- Audit 48 row: A0, A1, A3, T154, T214, T034 — 48/48 row, invariant PASS.
- Actual Gas dua arah: 29 kasus, 24 publish, dingin == warm 29/29, actual immutable 29/29, median inkremental 1,1 s.
- Benchmark 1 CPU before/after: REPORT.md bagian 4.

## Status paket
Runtime dingin 1 CPU dan Fix Load inkremental masih di atas target; kombinasi ketiga Actual Gas bersamaan masih terbuka -> paket release candidate
`XAMPP_V1526_RC_RUNTIME_GATE_OPEN_*` (bukan FINAL/VALIDATED). Limitation menunggu keputusan user.

## Flag rollback (env)
Lihat DEPLOYMENT_MANIFEST.md (`PP_V1526_*`; flag V15.25/V15.24 tetap berlaku).
