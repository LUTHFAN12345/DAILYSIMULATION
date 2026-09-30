# Hitung ulang LNG dan Distillate dari input bersih

Tidak satu angka pun pada laporan ini disalin dari hasil sesi sebelumnya. Seluruh nilai dihitung
ulang oleh engine dari fixture bersih, lalu **dihitung ulang KEDUA KALINYA oleh harness** dengan
rumus terbuka dan dibandingkan. Basis konversi liter dibaca dari `Distillate Conversion Basis`
milik engine, bukan dari konstanta di dalam harness.

## Tahap 1 — rekomputasi pada empat kuota PGN

| PGN | kebutuhan PGN | kuota PGN | shortage engine | shortage hitung ulang | Recommended LNG | LNG hitung ulang | Required Distillate (l) | liter hitung ulang | btu/liter | verdict |
|---|---|---|---|---|---|---|---|---|---|---|
| 20 | 29.7385 | 20.0000 | 9.7385 | 9.7385 | 9.7385 | 9.7385 | 270.298,0 | 270.297,6 | 36.029 | **cocok** |
| 25 | 29.7385 | 25.0000 | 4.7385 | 4.7385 | 4.7385 | 4.7385 | 131.520,0 | 131.519,8 | 36.029 | **cocok** |
| 27 | 29.7385 | 27.0000 | 2.7385 | 2.7385 | 2.7385 | 2.7385 | 76.009,0 | 76.008,6 | 36.029 | **cocok** |
| 30 | 29.9928 | 30.0000 | 0.0000 | 0.0000 | 0.0000 | 0.0000 | 0,0 | 0,0 | 36.029 | **cocok** |

Nilai berbeda antar kuota: LNG **4** nilai unik dari 4 kuota (lulus), Distillate **4** nilai unik (lulus). Sebuah konstanta hardcode tidak akan bergerak.

## Tahap 2 — penerapan Additional LNG pada PGN 25

Titik acuan **L\* = 4.7385 BBTUD** adalah hasil rekomputasi tahap 1, bukan angka yang dibawa dari sesi lain.

| titik | diminta (BBTUD) | Added LNG | LNG Used | Total Gas Used | Total Gas Quota | residual shortage | reconciliation | hard | verdict |
|---|---|---|---|---|---|---|---|---|---|
| di bawah | 2.3693 | 2.3693 | 2.3693 | 64.2985 | 61.9693 | 2.3292 | FUEL_ACTION_NOT_APPLIED | FAIL | **sesuai harapan** |
| tepat | 4.7385 | 4.7385 | 4.7385 | 64.3376 | 64.3385 | 0.0000 | FUEL_ACTION_APPLIED | PASS | **sesuai harapan** |
| di atas | 6.7385 | 6.7385 | 6.7385 | 66.3146 | 66.3385 | 0.0000 | FUEL_ACTION_APPLIED | PASS | **sesuai harapan** |

## Tahap 3 — penerapan Distillate pada PGN 25

Titik acuan **D\* = 132.198,0 liter/hari** juga hasil rekomputasi tahap 1.

| titik | limit diminta (l) | Distillate Fuel Total (l) | Gas Offset (BBTUD) | Total Gas Used | Total Gas Quota | residual | reconciliation | hard | verdict |
|---|---|---|---|---|---|---|---|---|---|
| di bawah | 66.099 | 65.423,6 | 2.3571 | 67.7809 | 59.6000 | 8.1809 | FUEL_ACTION_NOT_APPLIED | FAIL | **sesuai harapan** |
| tepat | 132.198 | 131.640,7 | 4.7429 | 59.5961 | 59.6000 | 0.0000 | FUEL_ACTION_APPLIED | PASS | **sesuai harapan** |
| di atas | 198.297 | 131.640,7 | 4.7429 | 59.5961 | 59.6000 | 0.0000 | FUEL_ACTION_APPLIED | PASS | **sesuai harapan** |

## Ringkasan

**15/15** pemeriksaan sesuai harapan.
