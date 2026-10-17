# CHECKPOINT V15.23 — Commitment Tightening + Fuel-State Start Assessment (+ runtime Fastest)

**Baseline:** `XAMPP_START_PRIORITY_RC_V1522_COMMITMENT_STOP.zip` (V15.22; perbaikan semantik Initial Status / Commitment Mode / Stop Status / Block Required / Save-Reload dipertahankan, `index.php` dan `weekly_ui.php` tidak diubah).
**Output:** `XAMPP_START_PRIORITY_RC_V1523_COMMITMENT_TIGHTENING.zip`.
**Branch:** `claude/keen-galileo-sjjhsk`. **Engine build:** `V15.23-COMMITMENT-TIGHTENING-FUEL-STATE-20261017`.
**File diubah:** `run.php` (utama), `worker02.php` (satu titik: decommit seharian di pipeline Fastest).

## 1. Reproduksi V15.22 (snapshot `plan-2-mvc1ltkp`, job hash `09197ea553c5…` = identik dengan snapshot)
G6 dan G9 start 01:00, G5 07:30–19:30, G1 10:00–17:30, G8/G9 di 65 MW pada row 4–14, CP 105,8901, distillate 318.362 L, 85 s (mesin uji; ±120 s di server user).

## 2. Lima penyebab kode terbesar
1. **Kandidat waktu-start dinilai pada state yang salah** (`pp_v13f_pipeline_core` dipanggil dari `pp_v7_fuel_rerun_from_basis` dengan `gas_shortage_action=recommendation`). Semua kandidat (latest legal, ±1, latest−2/−4) gagal `residual_shortage_zero/gas_window` karena bahan bakar belum diterapkan → `FALLBACK_EARLIEST` (`pp_v1520_fast_pipeline_run`) → G6/G9 01:00. Bukti: kandidat G9 05:00/G6 10:00 = CP basis 64,48 (tidak valid), tetapi **valid** di state distillate (CP 99,07, 247.940 L) vs 01:00/01:00 (CP 104,92, 310.670 L).
2. **Basis shortage dipakai ulang**: basis Fastest/memo dari run pendahuluan (*recommendation*) — dibuat dari kandidat yang semuanya gagal shortage → start paling awal — dipakai ulang oleh rerun bahan bakar; rekomendasi distillate juga dihitung dari jadwal 01:00 (327.522 L vs 261.648 L).
3. **Commitment hanya diuji seharian dan dengan prasaring statis**: `pp_decommit_pass` (pipeline) hanya decommit seharian dan dinilai di state shortage (4,9 s/pipeline tanpa hasil); `pp_v8_export_prescreen` menolak STOP/DECOMMIT memakai dispatch lama tanpa redispatch. Tidak ada uji tunda start / stop legal paling awal per interval → G1/G3 start 04:30, G5 berjalan sampai 19:30.
4. **V8 Priority Review menerapkan kandidat dengan CP lebih buruk** (V15.22: `SWAP:G4>G1` CP 104,9153 → 106,3155; sesudah perbaikan #1: `PRIO_ADVANCE_DEFER:G3>G6` 96,38 → 97,09) — tidak ada invariant komparator antar tahap.
5. **Runtime**: 6 pipeline kandidat (~10 s, semuanya gagal) + fallback + `decommit_screening` 4,9 s per pipeline; run pendahuluan 75 s.

Temuan tambahan: kunci basis warm IE (`pp_v1517_ie_base_key`) tidak terikat sidik jari engine → rencana build lama dapat menjadi basis redispatch sesudah deploy (stale state).

## 3. Perbaikan (generik, tanpa nama unit)
- `pp_v1523_fuel_assess`: kandidat basis yang gagal dinilai ulang pada **state bahan bakar** operator (`pp_v3_frozen_eval`, 0,1 s). Ranking kandidat memakai output state bahan bakar.
- Basis Fastest hanya dipakai ulang bila dibuat untuk **aksi bahan bakar yang sama** (`fuel_action`), pool kandidat + rencana startup disimpan/dipulihkan (cold = warm). Memo basis shortage tidak dipakai Fastest.
- Shortage seed: di state tanpa aksi bahan bakar, kandidat latest-legal yang membuktikan kekurangan gas menjadi basis bukti + estimasi bahan bakar (tanpa fallback start awal, tanpa konsolidasi sia-sia).
- Kandidat yang gagal **hanya** window gas didaratkan (lever gas engine) sebelum ditolak.
- **Commitment Tightening** (`pp_v1523_tighten`, sesudah V8/family, sebelum Unit Priority Fix): DELAY_REQUIRED_START (latest legal start unit Required+Sim+Hold), DECOMMIT, EARLY_STOP (stop legal paling awal sesudah runtime minimum), DELAY_START, SWAP ke unit prioritas lebih tinggi, ADVANCE_REPLACE(+PEER), DELAY_REQUIRED+BACKFILL. Evaluasi = redispatch engine **jendela lokal** (`pp_v1523_local_eval`): row di luar jendela dikunci pada incumbent (GTG + Babelan), startup di jendela dipaksa (Start-at), STG sequence/pelepasan masuk jendela, penilaian pada state bersih (`pp_sp_assess`), dedup sidik jari commitment, batas 36 evaluasi + 12 bukti. Penghentian dini terbukti bila window gas wajib-terpakai mengikat (`GAS_WINDOW_BOUND`).
- **Comparator V15.23** (invariant F): valid → startup tak-wajib → CP ekonomi (incl. startup penalty, band 0,02%) → penalti prioritas (row × level) → HR. `pp_v1523_stage_guard` membungkus V8 / family / Unit Priority Fix: hasil yang memburuk dikembalikan ke dispatch sebelumnya (dicatat `Comparator Invariant Guard`).
- Bukti per event (`Commitment Tightening.proofs`): start satu row lebih lambat (invariant B) dan stop satu row lebih awal (invariant C) dievaluasi; konteks numerik: beban/min/max/ramp/legal headroom unit running, headroom prioritas lebih tinggi, export & batas, gas/distillate row, STG + lead-in, defisit per row.
- Pipeline Fastest: decommit seharian digantikan tightening (`worker02.php`), satu seed latest-legal pada rerun bahan bakar.
- Kunci basis warm IE memuat sidik jari engine.

## 4. Hasil skenario aktual (cold, mesin uji 1 proses tanpa helper)
| | V15.22 | V15.23 |
|---|---|---|
| Event | G6+01:00 G9+01:00 G5 07:30–19:30 G1 10:00–17:30 | G9+05:00 (latest legal) G6+10:00 G4/G3 05:30–17:00 (lead Block 1) G5 07:30–18:30, **G1 tidak start** |
| CP | 105,8901 | **92,93–93,6** (lihat regression_report) |
| Distillate | 318.362 L | ~203–210 rb L |
| Hard / publish | PASS / true | PASS / true |
| Runtime rerun bahan bakar | 82–86 s | **31–32 s** |
| Run pendahuluan | 75 s | **5,3 s** |
| Warm | — | 0,35 s (hasil identik) |
Laporan lengkap (48 row, bukti numerik per start, periode G8/G9 ≤ 70 MW, regresi, benchmark): `harness/v1523/REPORT.md`.

## 5. Regresi (V15.22 vs V15.23) — `harness/v1523/REPORT.md` §Regresi
A0 aktual, A1 gas cukup, A2 LNG, A3 konfigurasi G6→G4, A4 Unit Priority blok 1 di atas blok 2, A5 Block 2 Required ON, A6 IE −40, A7 IE +20 (infeasible kedua versi), A8 konfigurasi G9→G5, B0 `input_data.json`. CP V15.23 ≤ V15.22 pada seluruh kasus; tidak ada kasus PASS→FAIL. Cold = warm = cache OFF (hash dispatch identik); parallel ON (3 helper, server HTTP) = serial.
Rollback: `PP_V1523_FUEL_ASSESS=0 PP_V1523_TIGHTEN=0 PP_V1523_STAGE_GUARD=0 PP_V1523_SHORTAGE_SEED=0 PP_V1523_CAND_GAS_LAND=0` → identik V15.22 (CP 105,8901, event sama).

## 6. Belum tercapai / risiko
1. Runtime di server user 1-core diperkirakan ±50 s (faktor 1,5× dari mesin uji); target ideal < 40 s belum terbukti di server user. Bottleneck tersisa: konsolidasi blok (4 pipeline, ~8 s), tightening (~9–11 s), V8 (~5 s, sering ditolak guard).
2. A1 (gas cukup) dan A7 (IE +20, infeasible) tetap 75–100 s: pipeline kandidat non-shortage dengan koreksi window gas berat (perilaku V15.22, tidak diperburuk).
3. A8: G9 (unit bebas) tetap start 01:00 — penundaan ditolak validator karena PGN Pipe turun di bawah window kuota sementara alokasi distillate (kontinuitas) tidak ikut turun; CP tetap lebih baik dari V15.22.
4. Hasil tightening sensitif terhadap urutan/anggaran pencarian (lokal, bukan optimum global).
5. `pp_stg_model_sig()` meng-cache signature per jumlah entri (potensi memo STG basi lintas kandidat); percobaan perbaikan mengubah hasil tanpa bukti manfaat → tidak diterapkan, perlu investigasi tersendiri.
6. Matriks UI 342 kasus dan injeksi helper failure tidak dijalankan ulang (UI tidak diubah).

## 7. Flag
`PP_V1523_FUEL_ASSESS`, `PP_V1523_TIGHTEN`, `PP_V1523_STAGE_GUARD`, `PP_V1523_SHORTAGE_SEED`, `PP_V1523_CAND_GAS_LAND`, `PP_V1523_SINGLE_SEED`, `PP_V1523_PIPE_DECOMMIT=1` (decommit lama), `PP_V1523_EVAL_CAP` (36), `PP_V1523_PROOF_CAP` (12), `PP_V1523_BACKFILL`, `PP_V1523_HOLD_IV`, `PP_V1523_SHORT_WINDOW`, `PP_V1523_GAS_BOUND_N` (6), `PP_V1523_LEFT_MARGIN` (1), `PP_V1523_IE_KEY_FP`.
Harness: `harness/v1523/` (`runjob.php` job CLI, `mkvar.py` varian regresi, `cmp.py`, `report.py`, `serial.sh`, `srvrun.sh` + `mkjob.php` jalur helper HTTP, `tt.php`/`dbg*.php` tightening terisolasi).
