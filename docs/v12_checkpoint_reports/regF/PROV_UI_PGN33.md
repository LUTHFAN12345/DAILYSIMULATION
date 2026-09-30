# UI dua tahap pgn_pipe 32 -> 33

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| U1 | run dasar final valid, Save terbuka | LULUS | {"rows":48,"save":false} |
| U2 | VALID PROVISIONAL tampil <= 15 s dengan label, 48 baris | LULUS | t=879 ms |
| U3 | selama provisional Export/Publish terkunci, Save Input tetap aktif (V3) | LULUS | {"save":false,"gate":true,"xls":true} |
| U4 | tahap 2 selesai: status akhir dinyatakan (FINAL OPTIMAL, exact ditolak release gate, atau rencana valid lebih murah dipertahankan) | LULUS | t=9111 ms msg=FINAL OPTIMAL — optimasi exact mengganti hasil provisional. Commitment: sama; dispatch berubah pada 2 dari 48 row; Total Cost 896,421 → 896,346.7 USD; Cost Production 64.266 → 64.2607 USD/MWh.Done. Perhitungan eksak selesai — Anda tidak perlu menjalankan ulang |
| U5 | Export hanya terbuka bila FINAL OPTIMAL; Save Input selalu aktif (V3) | LULUS | {"save":false,"xls":false} |
| U6 | tidak ada JavaScript error | LULUS |  |

Hasil: **6/6**
