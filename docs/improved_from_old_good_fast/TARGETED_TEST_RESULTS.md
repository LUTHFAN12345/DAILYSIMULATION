# Targeted test — freeze paket ini (tanpa full regression)

**Freeze (SHA-256)**
```
6c773205c97c44373e5491e968411440c56cbe0ece99a495a223aaef6bb2be9d  index.php
73ac4269bf2fe6e118959f205573fb9061241ff3f498efca1fd3fe729c1b5026  run.php
5636e58e5f1295da707c75189a9b8ececa12197ad28ed6678f828dec166acafc  saved_data_store.php
8b8a78c44ace6b939c9099489c202106f310c8bf15457dc747538217676c8ae2  worker02.php
4e4ed7a2b85d89de75e454b9bb344a929765b53075615430b641ce55603af563  worker_functions.php
```
- Lint PHP 7.4: kelima berkas `No syntax errors detected`.
- Root XAMPP dan Linux diverifikasi sama dengan freeze sebelum uji.
- CSV yang di-upload pengguna identik dengan CSV uji (SHA `43e15355…`).

| # | Test | Platform | Waktu | Hasil | Status |
|---|---|---|---|---|---|
| 1 | A/B OLD-GOOD vs current, Follow PV OFF | XAMPP | lama 171,4 s (HARD_VALIDATION_FAILED); terbaru **26,8 s** | terbaru FASTEST VALID PLAN, CP 95,8967, HR 8510,49, sig `708ea39169b5`, C4 21→0, merit PASS, STG 144/144, PGN min 3,0155, gas 71,2989/71,32 (AB_OLD_GOOD_VS_CURRENT.md) | PASS |
| 2 | Follow PV ON (R3, floor 0) | XAMPP | **26,0 s** | FASTEST VALID PLAN, CP 96,0078, HR 8524,1, sig `d6b400167d5b` (= 0a2cedf), C4 0 FAIL | PASS |
| 3 | Tanpa CSV (R2) | XAMPP | 26,2 s | identik R1 | PASS |
| 4 | Linux Apache 2.4 + PHP-FPM 7.4 (R1) | Linux | 28,2 s | status, CP/HR, sig, `rows_sig` `f3d7f738…`, C4 (21→0, 0 FAIL), STG, merit, Fixed Flow identik dengan XAMPP | PASS |
| 5 | U31 merit smoke | replay | 14,1 s | sig `7775db96324d`, CP 64,7205, hard/gerbang PASS (= baseline) | PASS |
| 6 | Distillate smoke (`D_rec.json`) | replay | 5,6 s | sig `7009a446c9d1`, CP 77,8572 (= baseline), G1 132.177,9 L satu unit | PASS |
| 7 | Change Over Block 1-2 | replay | 3,1 s | sig `e15860b82ee7`, CP 63,8636 (= baseline) | PASS |
| 8 | Save/Reload + Report (matriks E: import XLSX/CSV locale, Follow PV, auto PGN Fixed Flow, Save/Reload, Report Planning+Monitoring) | XAMPP | 77,0 s (Maximum Review) | 25/25 cek PASS, CP 90,0877 (= ronde sebelumnya) | PASS |

**Tambahan**
- Progres UI kini menampilkan fase nyata: "Fastest - Default: stop or continuous g4 55%" → "finalisasi review family g4 continuous 60%" → "finalisasi merit c4 75%" → "finalisasi gerbang fully valid 90%".
- Seluruh worker PHP diam ≤ 2 s sesudah hasil tampil (XAMPP dan Linux).

**Kekurangan tersisa**
- Bila family utama menang Stop-or-Continuous, rencana utama tetap direview penuh; waktunya tidak sependek reproducer ini.
- Statistik pembantu per jenis tugas dan waktu idle pembantu diukur dengan instrumentasi sementara selama pengembangan, bukan pada freeze (instrumentasi wajib dihapus).
- Keputusan Stop-or-Continuous Fastest tetap pada tingkat kandidat hard-valid pertama (aturan dari ronde sebelumnya, tidak diubah). Maximum Review membandingkan family secara penuh.
