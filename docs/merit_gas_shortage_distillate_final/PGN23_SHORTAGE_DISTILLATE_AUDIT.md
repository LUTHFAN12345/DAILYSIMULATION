# PGN23_SHORTAGE_DISTILLATE_AUDIT

Input: `input_data.json` pengguna (PGN 23, LNG 13, PEP 36, Akasia 4, `gas_shortage_action=recommendation`, 48 Manual Fixed Flow JBBK, B1 Stop, Bus Flow min 10, SR follow PV).

## Kekurangan gas dan rekomendasi (T0)

| | Sebelum hotfix | Sesudah hotfix |
|---|---|---|
| Keputusan | Popup bahan bakar, 7,1 s | Popup bahan bakar, 8,3–10,8 s |
| Kekurangan final | 5,0662 BBTUD (rencana basis melanggar SR 8 row, tidak terlihat) | **6,5086 BBTUD** (rencana basis SR-feasible, unit merit-compliant) |
| LNG ditawarkan | 5,1062 | **6,5486** |
| Distillate ditawarkan | 260.557 l (dari residual 9,3862 sebelum minimisasi Export: basis lain) | **185.397 l** (= 6,5486 BBTU × 1,02 / 36.028,8 BTU/l: konversi langsung + margin level diskrit 2 %) |

Monoton terhadap input (T1–T7):
- PGN ±1 menggeser kekurangan ∓1,0 BBTUD;
- PEP / Akasia ±1 MMSCFD menggeser ∓1,08 BBTUD (GHV 1080);
- LNG 0 menaikkannya +13,0.

## Basis kekurangan Distillate dan residual

Input ini memiliki Manual Fixed Flow JBBK rata-rata 36,08 MMSCFD, sedangkan kuota PEP + Akasia = 40 MMSCFD.

- Validator menilai **total gas** terhadap kuota kontrak (79,2 BBTUD).
- Jalur Distillate lama mengukur kekurangan dengan basis "PGN need" (dikurangi Fixed Flow manual). Selisih basis ±4,3 BBTUD membuat Distillate dijadwalkan berlebih: 392.992 l pada 3 unit, gas jatuh ke 74,86 di bawah window.

Perbaikan:
- kebutuhan Distillate = gas total − (kuota total efektif − 0,02), basis validator;
- residual Distillate dinilai pada basis yang sama.

## Distillate fast path

| Butir | Status |
|---|---|
| shortage BBTUD → liter langsung (basis konversi diaudit: densitas 0,8424, LHV 19.400 BTU/lb, 2,2046 lb/kg = 36.028,8 BTU/l) | Ya |
| satu unit eligible pertama menurut Unit Priority Distillate (G3 → G4 → G6 …), 100 % dulu per slot dari slot terakhir | Ya: T9 G3 100 % pada slot G3 eligible, lalu G6 hanya sesudah slot G3 habis |
| unit kedua hanya bila kapasitas legal unit pertama habis | Ya |
| validasi incremental (jadwal sel dipaku, bukan solver harian ulang per liter) | Ya (`__dist_schedule_pin` / `__dist_budget_pin_bbtud` yang sudah ada) |
| tanpa double-count dengan LNG | Ya: LNG Used = kuota + LNG tambahan; Distillate Energy hanya mengurangi gas GTG |
| startup / runtime / ramp / Export / Bus Flow / SR / STG / C1–C4 | Divalidasi penuh (hard PASS, merit PASS, STG 144/144) |

T9 (Distillate diterima), FASTEST VALID PLAN dalam **5,3 s**:
- Distillate 261.679,9 l: G3 126.033,1 l + G6 135.647,8 l, energi 9,428 BBTUD;
- gas 79,1765 dalam window [79,16; 79,20]; CP 97,2024; HR 8443,60; C1–C4 0 FAIL.

## Fixed Flow JBBK saat Min PGN Flow gagal (T14)

Fixture S9 (G8 unavailable):
- koreksi otomatis Fixed Flow row 1–13 dengan delta minimum, water-filling ke 35 row sesudahnya;
- total harian identik (36,000000 → 36,000000 MMSCF);
- margin verifikasi sekarang 0,5 / 1,0 / 2,0 MMSCFD (sebelumnya 0,5 / 1,0; margin 2,0 diperlukan sesudah pass merit swap menggeser dispatch siang).

Rerun koreksi berakhir dengan terminal jujur `FASTEST_NO_FULLY_VALID_PLAN_WITHIN_BUDGET` pada 21,7 s, sama dengan rilis sebelumnya. Babelan tidak diturunkan.

## Batasan yang jujur

1. **Inkonsistensi input.** Manual Fixed Flow JBBK (36,08) ≠ PEP + Akasia (40) MMSCFD; tabel Fixed Flow manual tampaknya belum diperbarui sesudah PEP diubah 32 → 36. Engine kini konsisten pada basis total validator, tetapi angka "PGN need" per row tetap memakai Fixed Flow manual. Disarankan menyamakan tabel Fixed Flow manual dengan kuota PEP + Akasia.
2. **Distillate T9 lebih besar dari rekomendasi.** Rencana Distillate memakai 261.680 l, padahal popup menawarkan 185.397 l. Commitment yang ditemukan jalur Distillate (G3 + G5 start) membakar gas lebih banyak daripada dispatch basis rekomendasi (G4 + G5). Plafon liter operator tetap dihormati bila dikirim; uji T9 dijalankan dengan aksi Distillate tanpa plafon.
3. **Rerun bahan bakar Fastest + LNG (T8/T10/T11).** Total sekitar 28 s: popup sekitar 9 s + rerun sekitar 19 s, di bawah target 30 s. Kandidat pertama yang diklaim (dari kolam) kadang gagal gerbang, lalu rencana V7 yang dirilis.
4. **Distillate otomatis kecil pada rerun LNG.** Rerun LNG memakai aksi campuran (perilaku lama `mixed_lng_distillate`): sisa 0,0256 BBTUD ditutup 709,5 l Distillate pada G4.
