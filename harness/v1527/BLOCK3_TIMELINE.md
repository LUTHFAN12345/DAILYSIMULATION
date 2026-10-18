# Block 3 Cold Start — timeline per row (T220)

Keputusan user: Block 3 Cold Start membutuhkan 7,5 jam sampai first S3 load. Label row = AKHIR slot 30 menit.
Startup G8 pada slot 00:30–01:00 (row 2, label 01:00); S3 pertama berbeban pada row 16 (slot 07:30–08:00, label 08:00) = 15 slot x 0,5 jam = 7,5 jam sesudah 00:30.
Profil cold (startup sequence engine): 40 MW 3,5 jam (7 row), 50 MW 1 jam (2 row), 60 MW 2,5 jam (5 row), lalu S3 release.

| Row | Slot | Label | G8 MW | G9 MW | S3 MW | Fase G8 |
|---|---|---|---|---|---|---|
| 1 | 00:00–00:30 | 00:30 | 0 | 0 | 0 | stop (Last Status Stop) |
| 2 | 00:30–01:00 | 01:00 | 40 | 0 | 0 | cold 40 MW (jam 0.5) |
| 3 | 01:00–01:30 | 01:30 | 40 | 0 | 0 | cold 40 MW (jam 1.0) |
| 4 | 01:30–02:00 | 02:00 | 40 | 0 | 0 | cold 40 MW (jam 1.5) |
| 5 | 02:00–02:30 | 02:30 | 40 | 0 | 0 | cold 40 MW (jam 2.0) |
| 6 | 02:30–03:00 | 03:00 | 40 | 0 | 0 | cold 40 MW (jam 2.5) |
| 7 | 03:00–03:30 | 03:30 | 40 | 0 | 0 | cold 40 MW (jam 3.0) |
| 8 | 03:30–04:00 | 04:00 | 40 | 0 | 0 | cold 40 MW (jam 3.5) |
| 9 | 04:00–04:30 | 04:30 | 50 | 0 | 0 | cold 50 MW (jam 4.0) |
| 10 | 04:30–05:00 | 05:00 | 50 | 0 | 0 | cold 50 MW (jam 4.5) |
| 11 | 05:00–05:30 | 05:30 | 60 | 0 | 0 | cold 60 MW (jam 5.0) |
| 12 | 05:30–06:00 | 06:00 | 60 | 40 | 0 | cold 60 MW (jam 5.5) |
| 13 | 06:00–06:30 | 06:30 | 60 | 40 | 0 | cold 60 MW (jam 6.0) |
| 14 | 06:30–07:00 | 07:00 | 60 | 50 | 0 | cold 60 MW (jam 6.5) |
| 15 | 07:00–07:30 | 07:30 | 60 | 60 | 0 | cold 60 MW (jam 7.0) |
| 16 | 07:30–08:00 | 08:00 | 90 | 60 | 51.26 | S3 first load (jam 7,5) |
| 17 | 08:00–08:30 | 08:30 | 90.76 | 70 | 100.48 | combined cycle |
| 18 | 08:30–09:00 | 09:00 | 101 | 100 | 115.45 | combined cycle |
| 19 | 09:00–09:30 | 09:30 | 102.5 | 102.5 | 116.93 | combined cycle |
| 20 | 09:30–10:00 | 10:00 | 103 | 103 | 117.31 | combined cycle |

G9 (peer blok 3) mengikuti sequence yang sama mulai row 12; continuity lintas tanggal: Last Status row 1 = Stop (input), tidak ada row yang melompati fase.
Sumber: matrix V15.26 T220 (PASS), engine build V15.26; urutan sequence tidak diubah di V15.27.
