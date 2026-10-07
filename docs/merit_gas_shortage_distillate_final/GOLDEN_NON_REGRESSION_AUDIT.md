# GOLDEN_NON_REGRESSION_AUDIT (hotfix merit / Gas Shortage / Distillate)

| Run | Mode | Waktu | CP | HR | Gas / kuota | Babelan MWh | Startup | Hard | C1–C4 | STG | Sig |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Rilis sebelumnya (edef289), XAMPP-sem | Fastest | 1,58 s | 63,9783 | 8020,59 | 71,8527 / 71,88 | 5759,75 | G1 21–32 | PASS | 0 FAIL | PASS | bc883f0a3aab |
| **Hotfix ini (smoke 1)** | Fastest | 4,8 s | **63,9783** | **8020,59** | 71,8527 / 71,88 | 5759,75 | **G1 21–32** | PASS | 0 FAIL | PASS | **bc883f0a3aab** |
| Hotfix ini (smoke final) | Fastest | 4,8 s | 63,9783 | 8020,59 | 71,8527 / 71,88 | 5759,75 | G1 21–32 | PASS | 0 FAIL | PASS | bc883f0a3aab |

- Dispatch golden **identik bit-per-bit**. Profil Babelan tidak berubah. Fastest tetap Fastest (5 kandidat / 3 valid).
- Seluruh perubahan hotfix tidak aktif pada golden:
  - SR minimum 0;
  - gas tidak di atas kuota (pass merit swap tidak berjalan);
  - tidak ada temuan C4 (fase B2 tidak berjalan);
  - tanpa aksi bahan bakar, tanpa actual, Min PGN Flow terpenuhi.
- Waktu 4,8 s vs 1,6 s sebelumnya, masih di bawah target 15 s:
  - diukur dua kali pada kondisi server yang sama dengan batch matrix (bukan run terisolasi);
  - golden tidak menjalankan kode baru apa pun;
  - selisihnya belum diprofilkan.
