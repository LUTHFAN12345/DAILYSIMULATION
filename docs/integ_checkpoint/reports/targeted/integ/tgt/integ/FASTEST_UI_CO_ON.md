# Fastest - Default UI — Change Over ON (WB09_3)

| id | uji | hasil | bukti |
|---|---|---|---|
| F1 | Fastest - Default di atas < 15 detik, default terpilih, kuning; target biasa putih; Maximum Review merah terang | LULUS | {"value":"fast","labels":["Fastest - Default","< 15 detik","< 25 detik","< 35 detik","< 45 detik","< 55 detik","< 60 detik","Maximum Review"],"values":["fast","15","25","35","45","55","60","max"],"first":{"bg":"rgb(255, 213, 79)","fg":"rgb(93, 58, 0)"},"mid":{"bg":"rgb(255, 255, 255)","fg":"rgb(31, 41, 55)"},"last":{"bg":"rgb(255, 45, 45)","fg":"rgb(255, 255, 255)"}} |
| F0 | Change Over ON lewat UI (Block 2 Running -> Block 1, sim/sim) | LULUS | {"enabled":true,"blocks":{"1":{"block":"1","last_status":"Stop","gtg":"g4","stg":"s1","start_other":"sim"},"2":{"block":"2","last_status":"Running","gtg":"g1","stg":"s2","stop_other":"sim"}}} |
| F2 | tanpa popup progress besar selama run | LULUS | modal_terlihat=false |
| F3 | progres berupa teks biru kecil "Fastest - Default" | LULUS | Fastest - Default — mencari kandidat fully valid pertama · 3,6 s Pratinjau — perhitungan eksak selesai tetapi BELUM leng |
| F4 | selesai dengan hasil fully valid 48 row (FASTEST VALID PLAN atau FINAL OPTIMAL) | LULUS | t=4.00 s rows=48 cp=63.9304 msg=Pratinjau — perhitungan eksak selesai tetapi BELUM lengkap. Publish tetap terkunci. · FINAL OPTIMAL — Target waktu Fastest - Default / Waktu aktual 3,8 detik / Kandidat diperiksa 2 / Kandidat valid 1  fast=null |
| F5 | label jujur: FASTEST VALID PLAN menyatakan Global optimum proven NO; FINAL OPTIMAL hanya bila exact selesai | LULUS |  |
| F6 | Change Over aktif: executed dan overlap S1/S2 >= 3 row (1,5 jam) | LULUS | executed=true overlap=9 row |
| F7 | tidak ada JavaScript error | LULUS |  |

8/8 LULUS; waktu selesai 4.00 s.
