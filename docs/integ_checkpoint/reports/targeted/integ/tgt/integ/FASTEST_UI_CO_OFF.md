# Fastest - Default UI — Change Over OFF (WB09_3)

| id | uji | hasil | bukti |
|---|---|---|---|
| F1 | Fastest - Default di atas < 15 detik, default terpilih, kuning; target biasa putih; Maximum Review merah terang | LULUS | {"value":"fast","labels":["Fastest - Default","< 15 detik","< 25 detik","< 35 detik","< 45 detik","< 55 detik","< 60 detik","Maximum Review"],"values":["fast","15","25","35","45","55","60","max"],"first":{"bg":"rgb(255, 213, 79)","fg":"rgb(93, 58, 0)"},"mid":{"bg":"rgb(255, 255, 255)","fg":"rgb(31, 41, 55)"},"last":{"bg":"rgb(255, 45, 45)","fg":"rgb(255, 255, 255)"}} |
| F2 | tanpa popup progress besar selama run | LULUS | modal_terlihat=false |
| F3 | progres berupa teks biru kecil "Fastest - Default" | LULUS | Fastest - Default — mencari kandidat fully valid pertama · 12,8 s Done. Perhitungan eksak selesai — Anda tidak perlu men |
| F4 | selesai dengan hasil fully valid 48 row (FASTEST VALID PLAN atau FINAL OPTIMAL) | LULUS | t=13.25 s rows=48 cp=63.4469 msg=Done. Perhitungan eksak selesai — Anda tidak perlu menjalankan ulang. · FINAL OPTIMAL — Target waktu Fastest - Default / Waktu aktual 13,0 detik / Kandidat diperiksa 28 / Kandidat valid 13 / Cost Prod fast=null |
| F5 | label jujur: FASTEST VALID PLAN menyatakan Global optimum proven NO; FINAL OPTIMAL hanya bila exact selesai | LULUS |  |
| F7 | tidak ada JavaScript error | LULUS |  |

6/6 LULUS; waktu selesai 13.25 s.
