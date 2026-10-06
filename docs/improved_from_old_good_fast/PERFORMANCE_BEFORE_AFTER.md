# Performa sebelum / sesudah (klik Run → Simulation Data 48 row, UI asli)

Mesin 4 core yang sama, 6 worker PHP 7.4 (3 pembantu), cold state, kasus berikutnya dimulai sesudah seluruh worker diam.

| Test | 0a2cedf (hotfix) | Paket ini (freeze) | Hasil |
|---|---|---|---|
| R1 CSV, Follow PV OFF, XAMPP | 88,5 s | **26,8 s** | identik: CP 95,8967 / HR 8510,49 / sig `708ea39169b5` |
| R1 Linux Apache + PHP-FPM | 92,7 s | **28,2 s** | identik |
| R2 tanpa CSV | 107,8 s | **26,2 s** | identik R1 |
| R3 Follow PV ON | 166,1 s | **26,0 s** | identik: CP 96,0078 / HR 8524,1 / sig `d6b400167d5b` |
| State pengguna OLD_GOOD_FAST | 8,96 s | **5,9 s** | identik: CP 104,7755 / sig `a862b5cd76f7` |
| Smoke U31 (replay exact) | — | 14,1 s | identik `7775db96324d` |
| Smoke Distillate | — | 5,6 s | identik `7009a446c9d1` |
| Smoke CO Block 1-2 | — | 3,1 s | identik `e15860b82ee7` |

**Sebaran run pengembangan** (logika sama, sebelum pembersihan instrumentasi, worker diam di antara kasus): R1 25,4–26,7 s, R2 25,6 s, R3 ON 25,4 s. Run tanpa jeda idle tercemar sisa proses harness (30–43 s; lihat ROOT_CAUSE).

## Rincian finalisasi R1 (freeze, detik sesudah klik)

| Tahap | Detik |
|---|---|
| Kandidat lengkap pertama / klaim | 0,55 / 0,82 |
| Stop-or-Continuous G4 (tingkat first-valid, family alternatif 0,76 s) | 0,83 → 1,63 |
| Review family terpilih G4 CONTINUOUS: r1, r2, polish (7 evaluasi, 2,3 s), sweep | 1,63 → 10,6 |
| Merit C4 (38 evaluasi row, search 6,7 s, bukti 3,6 s) | 10,6 → 25,5 |
| Stop-or-Continuous G8 (hasil pembantu, 0,01 s) + gerbang fully valid + STG | 25,56 → 25,57 |
| Snapshot → fetch → render → Simulation Data | 26,15 → 26,70 |
| Worker diam sesudah hasil tampil | ≤ 2 s |

## Dari mana penghematannya

| Perubahan | Efek terukur |
|---|---|
| Review malas Stop-or-Continuous | review rencana utama (polish 131 evaluasi ±37 s + C4 ±42 s) tidak dijalankan bila family alternatif menang. Pada reproducer itu selalu terjadi (R1, R2, R3) |
| Abort diteruskan + antrean per penerbit + prioritas kelas | sebelum review malas: R1 88,5 → 79,1 s, hasil identik; evaluasi pemilik 140–157 → 66 |
| Pencarian row C4 paralel + residual/bukti paralel | C4 42 → ±15 s |
| Pemilik ikut bekerja saat dijeda | kasus klaim oleh pembantu: 41,4 → 26,7 s |

## Target

| Target | Hasil |
|---|---|
| Follow PV OFF ≤ 60 s | **26,8 s** XAMPP / 28,2 s Linux |
| Follow PV ON ≤ 90 s (ideal ≤ 60 s) | **26,0 s** |
| C4 FAIL 0; hard, merit, STG PASS; PGN min PASS | ya (C4 0, STG 144/144, PGN min 3,0155) |
| CP/HR tidak memburuk | identik dengan 0a2cedf |

**Batasan.** Bila family utama yang menang Stop-or-Continuous (input lain), rencana utama tetap direview penuh seperti sebelumnya. Waktu kasus itu mengikuti perbaikan no. 2–9 saja (terukur R1 79,1 s sebelum review malas, dengan C4 kini lebih cepat), bukan 26 s.
