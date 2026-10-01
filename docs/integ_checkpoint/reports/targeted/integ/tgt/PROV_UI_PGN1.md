# UI dua tahap pgn_pipe 30 -> 31

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| U1 | run dasar final valid, Save terbuka | LULUS | {"rows":48,"save":false} |
| U2 | VALID PROVISIONAL tampil <= 15 s dengan label, 48 baris | LULUS | t=5852 ms |
| U3 | selama provisional Export/Publish terkunci, Save Input tetap aktif (V3) | LULUS | {"save":false,"gate":true,"xls":true} |
| U4 | tahap 2 selesai: status akhir dinyatakan (FINAL OPTIMAL, exact ditolak release gate, atau rencana valid lebih murah dipertahankan) | LULUS | t=11882 ms msg=FINAL OPTIMAL — optimasi exact mengganti hasil provisional. Commitment: G2 0→12 row, G5 12→0 row; dispatch berubah pada 17 dari 48 row; Total Cost 878,943.62 → 879,077.94 USD; Cost Production 64.545 → 64.5424 USD/MWh.Done. Perhitungan eksak selesai — Anda tida |
| U5 | Export hanya terbuka bila FINAL OPTIMAL; Save Input selalu aktif (V3) | LULUS | {"save":false,"xls":false} |
| U6 | tidak ada JavaScript error | LULUS |  |

Hasil: **6/6**
