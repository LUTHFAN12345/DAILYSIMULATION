# URUTAN DAN WAKTU POPUP GAS SHORTAGE — KLIK NYATA

Basis: `http://127.0.0.1:9300` · 2026-09-30T09:26:58.163Z

Hasil: **8/8** lulus.

| ID | Pemeriksaan | Hasil | Rincian |
|---|---|---|---|
| P1 | Klik Run -> modal pemblokir muncul <= 1 detik | LULUS | 150 ms |
| P2 | Klik Run -> tahap progres PERTAMA tampil <= 2 detik | LULUS | 934 ms; Input tersimpan; job pemilik state dimulai 0,0 s (kumulatif 0,0 s)Optimasi exact penuh 0,0 s (kumulatif 0,0 s)v4 family parallel 0,3 s (kumulatif 0,3 s)Menyiapk |
| P3 | Modal menutupi halaman (Run/Export/input); Save Input tetap aktif dan dapat diklik di atas modal (V3) | LULUS | {"ok":true,"z":"10000","pos":"fixed","tertutup":true,"saveInputDiAtasModal":true} |
| P4 | OUTPUT tetap kosong selama analisis berjalan | LULUS | {"output":"kosong","gate":true} |
| P5 | Daftar tahap bertambah mengikuti mesin hitung (bukan animasi) | LULUS | awal=4 -> terakhir teramati=10 (5582 ms); judul="Gas shortage analysis in progress" |
| P6 | Modal berganti menjadi popup keputusan tanpa lapisan ganda | LULUS | popup pada 6727 ms; lapisan ganda=0; jeda tanpa lapisan=0 |
| P7 | Export/Publish/Save hasil terkunci selama keputusan belum diambil; Save Input tetap aktif (V3) | LULUS | {"st":{"btn-xls":true,"btn-img-full":true,"btn-img-partial":true,"btn-publish":"tidak ada","btn-save-actual":"tidak ada"},"gate":true,"output":"kosong","saveInputAktif":true} |
| P8 | Tidak ada JavaScript error di halaman | LULUS |  |

## Catatan

Modal dibuka SEBELUM `fetch`, sebagai satu operasi DOM sinkron. Karena itu jaraknya dari klik
tidak bergantung pada jaringan maupun berat rencana. Nama tahap dibaca dari
`run.php?mode=prelim_progress`, yang melaporkan batas fase yang benar-benar sudah dilewati
mesin hitung; tidak ada satu pun angka estimasi yang ditampilkan sebelum validasi eksak selesai.
