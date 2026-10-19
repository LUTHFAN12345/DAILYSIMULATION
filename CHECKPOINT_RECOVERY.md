# CHECKPOINT RECOVERY — V15.28 RC (sprint hemat credit, basis V15.27 `d689988`)

Branch `claude/keen-galileo-sjjhsk`. Engine build `V15.28-RC-GASONLY-STARTUP-ORDER-20261019`. File runtime diubah terhadap V15.27: `run.php` saja.

## Perubahan
1. `pp_job_run_economic_review_v1524` (wrapper baru; isi lama = `pp_job_run_economic_review_v1524_core`): Use Distillate = izin/fallback.
   Bila hasil memakai distillate tanpa liter user dan (shortage final ≤ 0,5 BBTUD atau hasil tidak publish), job gas-only (aksi none) juga
   dijalankan; pemenang = objective final (`pp_sp_final_cmp`) di antara hasil yang publish. Liter user eksplisit tidak diganggu. Flag `PP_V1528_GASONLY=0`.
2. `pp_v1523_cmp` (comparator search / stage guard): startup tak perlu dan blok baru dinilai SESUDAH CP ekonomi dan HR (urutan objective user).
   Flag `PP_V1528_STARTUP_AFTER_CP=0`.
3. `pp_v1528_recore` (baru, dipanggil sesudah closure bukti event di bundle Fastest dan jalur job): commitment final didaratkan ulang sekali oleh
   core; diterima hanya bila valid penuh (`pp_sp_assess`), lebih baik (stage guard), tanpa startup tambahan; bila commitment berubah, bukti event +
   closure dihitung ulang dan divalidasi lagi. Flag `PP_V1528_RECORE=0`.

## Targeted (PHP 7.4.33, 1 CPU per run)
- T236 PASS (68,294, 0 l; V15.25/V15.27 FAIL 71,42 dengan 4122–4569 l).
- T118 92,9735 (V15.25 93,1327, V15.27 93,6094); T121 92,7266 (V15.25 93,1548, V15.27 93,7565).
- T001, T002, T184 (A0), T179, T227, T228 (Maximum Review vs Fastest) PASS, CP sama dengan V15.27.
- Masih di atas V15.25 (winner V15.25 lolos seluruh validator V15.27 = search miss): T017 +0,3347, T046/T057/T199/T202 +0,1474, T115 +0,0221,
  T174 +0,2147, T136 +0,0931. Redispatch commitment final T046 menghasilkan 71,3309 tetapi ditolak: row 15 Export 4,72 MW < RangeMin 15 MW.
- Smoke benchmark (A0/A1/A2/A7/Fix Load/Actual Gas cold+warm): CP identik V15.27 seluruh kasus.

## Belum dijalankan (sesi berikutnya)
Full matrix 236; suite Actual Gas penuh pada source final (sedang/terakhir pada source sebelum perubahan 1b — lihat REPORT_RECOVERY.md).

## Paket
`dist/v1528/XAMPP_V1528_RC_FULL_DEPLOYMENT.zip` (+ SHA256 di REPORT_RECOVERY.md). Bukan FINAL/VALIDATED (runtime gate terbuka, full matrix belum).
