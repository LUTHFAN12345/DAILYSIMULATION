# Performa sebelum / sesudah — PGN 30 + PEP 34 (Fastest - Default)

**Pengukuran:** dari klik Run sampai Simulation Data menampilkan 48 row, lewat UI asli (Chromium), di mesin uji 4 core yang sama.
**Rincian tahap:** dari `jobs/<job>/v12_fast_ready.json` (`timeline_s`, `polish_trace`) dan `job.json` (`progress`).

## Sebelum (d6439e9)

| Test | Hasil | Jejak |
|---|---|---|
| R1 (CSV) | **tidak ada Simulation Data dalam 300 s**; harness berhenti di 300 s, job masih RUNNING | klaim kandidat pertama ±1 s → finalisasi inline 79,2 s (review 54,7 + C4 24,5) → ditolak (C4 4 FAIL tanpa bukti) → exact penuh: pipeline end 107 s, family 199 s, review 272 s, Stop-or-Continuous G4 274 s, … |
| R2 (tanpa CSV) | **tidak ada Simulation Data dalam 300 s** | baseline 94,1 s (finalisasi inline) → exact penuh: family 201 s, review 299 s |

## Sesudah (hotfix)

| Test | Platform | Klik → 48 row | Klaim sesudah job mulai | Finalisasi | Hasil |
|---|---|---|---|---|---|
| R1 | XAMPP | **88,5 s** | 0,85 s | 86,7 s | FASTEST VALID PLAN, CP 95,8967 |
| R1 | Linux | **92,7 s** | 0,94 s | 90,7 s | identik |
| R2 | XAMPP | 107,8 s | 0,84 s | 106,0 s | identik dengan R1 |
| R3 ON | XAMPP | 166,1 s | 0,81 s | 164,0 s | FASTEST VALID PLAN, CP 96,0078 |

R1 juga diukur pada source antara (perbaikan yang sama, sebelum freeze): 75,7 / 76,0 / 81,7 / 92,7 / 95,8 / 103,3 s. **Min/max seluruh R1 sesudah perbaikan: 75,7 / 103,3 s.**

### Rincian finalisasi R1 (XAMPP, freeze hotfix)

| Tahap | Detik (relatif klaim) | Durasi |
|---|---|---|
| Review putaran r1/r2 | 0 → 4,0 | 4,0 s |
| Polish Unit Priority (6 putaran, 131 evaluasi, 14 hit cache dari pembantu) | 4,0 → 41,0 | 37,0 s |
| Sweep konsolidasi + laporan | 41,0 → 44,2 | 3,2 s |
| Redistribusi C4 (38 evaluasi baris + polish C4 13 evaluasi + bukti) | 44,2 → 86,6 | 42,3 s |
| Audit C1–C4 / LLF / provenance | — | 0,05 s |
| Gerbang fully valid + Stop-or-Continuous (sudah dihitung pembantu) | — | 0,1 s |
| Snapshot → fetch → render 48 row | — | ±1,8 s |

## Apa yang berubah

| Perbaikan | Efek terukur |
|---|---|
| Bukti C4 untuk temuan sisa + transfer legal sisa | Kandidat pertama kini lolos gerbang (C4 0 FAIL), tidak jatuh ke exact penuh. Ini mengubah "tidak ada hasil > 300 s" menjadi hasil fully valid |
| Penerbit tugas samping memakai `pp_v12_side_job_any()` | Pembantu ikut menghitung polish/C4 (log `side_log.txt`). Finalisasi R1 di source antara turun 99,8 s → 73,7–79,4 s |
| Progres finalisasi | Label tidak lagi tertahan di fase pipeline terakhir ("gas window correction 40%") |
| Batas pengaman C4 180 s | R3 ON tidak terpotong waktu (keputusan C4 ditentukan jumlah evaluasi) |

## Percobaan yang ditolak (tidak masuk source)

- **Membatasi polish Fastest ke 1 putaran.** Gagal: C4 tidak tuntas (48 FAIL), klaim ditolak, exact 389 s. Pergeseran polish memang dibutuhkan C4 pada input ini.
- **Menunda Stop-or-Continuous sampai awal C4.** Polish 36 → 29 s, tetapi C4 30 → 38 s, sehingga total tidak berubah (±74 s). Batasnya kapasitas CPU 4 core.

## Mengapa < 60 s belum tercapai

Kandidat fully valid pertama untuk input ini membutuhkan kerja CPU nyata:
- **Polish:** 131 evaluasi 48 row × ±0,37 s ≈ 48 s CPU.
- **Redistribusi C4:** ±40 s CPU.
- **Family alternatif Stop-or-Continuous** (G4/G8, termasuk review spekulatif): ±50 s CPU.

Di 4 core, termasuk pemilik, totalnya tidak dapat turun di bawah ±60 s tanpa mengurangi pemeriksaan yang diwajibkan untuk fully valid (merit C1–C4, Stop-or-Continuous). Pemeriksaan itu tidak dilemahkan.
