# CHECKPOINT V15.25 — Remediasi UI + Logic + Full Regression

**Baseline:** V15.24 (`d207780`, XAMPP_FINAL_VALIDATED_FULL_DEPLOYMENT V15.24 — direproduksi identik: 14 FAIL, A0 CP 93,4567).
**Engine build:** `V15.25-REMEDIATION-UI-LOGIC-FULL-REGRESSION-20261018`. **Branch:** `claude/keen-galileo-sjjhsk`.
**File diubah terhadap V15.24:** `index.php`, `run.php`, `saved_data_store.php`, `worker02.php`, `worker_functions.php` (+ CHECKPOINT/DEPLOYMENT_MANIFEST/SHA256SUMS).

## Ringkasan perubahan (detail: REPORT.md di paket dokumentasi)
1. 14 FAIL V15.24 ditutup: Export harian REQUIRED sebagai batas kumulatif + sertifikat terminal; pita dispatch-dev vs langkah Export; rekonsiliasi Actual Gas + distillate (jam actual immutable); SC last resort hanya pada kekurangan kapasitas nyata; sertifikat kapasitas Export; sertifikat terminal di jalur non-Fastest.
2. Maximum Review selalu membandingkan kandidat Fastest (dingin) dengan comparator final yang sama.
3. Popup Ctrl+Click / Fix Load selalu terlihat (portal, clamp/flip, footer sticky, Esc/Enter, focus trap).
4. Distillate Unit Priority Block 1–2 -> Priority 1–6 (migrasi payload lama; hanya unit Running & legal).
5. 2+2 sebelum 3+1 otomatis (S1 & S2 paralel), dibatasi comparator ekonomi, intent user dipertahankan.
6. Actual Gas rerun inkremental (dispatch terkunci, rekonsiliasi akuntansi; bukti event basis dipakai ulang).
7. Check Yesterday Data, Start Up CC, kelanjutan startup lintas tanggal, backward scheduling dari target first STG load, profil cold Block 3 (40/50/60 MW, S3 tepat 7,5 jam).
8. Runtime: prescreen kapasitas Export di tightening; rekonsiliasi Actual Gas tanpa ulang dispatch.
9. Integritas: sidik jari engine memuat flag env `PP_*`; `publish_allowed` job konsisten dengan kontrak hasil; 2+2 mempertahankan metadata pipeline.

## Validasi (PHP 7.4.33, worker dipin 1 logical CPU)
- Matrix engine 229 test: 193 PASS, 36 EXPECTED_REJECTION (bukti terminal), 0 FAIL; grup I/H15/H16 MySQL 14/14; UI smoke 26/26, popup 203/203, distillate 5/5, yesterday 12/12 + backend 9/9.
- A0: CP 93,4567 / HR 8481,93 (identik V15.24), Event Proof TERBUKTI.
- Benchmark 1 CPU before/after: REPORT.md bagian 3.

## Status paket
Gate runtime 1 CPU belum lulus -> paket dibangun sebagai release candidate `XAMPP_V1525_RC_RUNTIME_GATE_OPEN_*` (bukan FINAL/VALIDATED).

## Keterbatasan
Runtime cold 1 CPU di atas target 30 s / 40 s (normal / sulit); Actual Gas cold tanpa basis > 40 s (jalur inkremental < 1 s); Fix Load warm ~27 s (target 10 s); T139 keputusan operator gas-window; detail di REPORT.md bagian 6.

## Flag rollback (env)
Lihat DEPLOYMENT_MANIFEST.md (`PP_V1525_*`; flag V15.24 tetap berlaku).
