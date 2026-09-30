# UI Target Selesai & notifikasi (PGN 29 + PEP 32)

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| T1 | Dropdown Target Selesai di kanan bawah sejajar SAVE, 7 pilihan | LULUS | {"opts":["< 15 detik","< 25 detik","< 35 detik","< 45 detik","< 55 detik","< 60 detik","Maximum Review"],"inRunbar":true,"right":true,"sameRow":true,"label":"Target Selesai\n< 15 detik\n< 25 detik\n< 35 detik\n< 45 detik\n< 55 detik\n< 60 detik\nMaximum Review"} |
| T2 | Notifikasi "never auto-switches to distillate" hanya pada Gas Shortage Recommendation (tanpa reload) | LULUS | {"rec":"","lng":"none","dist":"none","rec2":""} |
| T3 | Pilihan tersimpan dan dipakai lagi setelah reload | LULUS | value=25 |
| T5-max | Maximum Review: exact selesai, FINAL OPTIMAL publishable, Global optimum proven: YES (ruang kandidat exact); kandidat Target Selesai yang lebih murah (bila ada) dinyatakan sebagai catatan | LULUS | t=18753 ms label=FINAL OPTIMAL cp=64.3188 catatan=false msg=FINAL OPTIMAL — optimasi exact mengganti hasil provisional. Commitment: G2 12→0 row, G5 0→12 row; dispatch berubah pada 14 dari 48 row; Total Cost 877,959.03 → 877,923.92 USD; Cost Production 64.3215 → 64.3188 USD/MWh.Done. Perhitungan eksak selesai — Anda tidak perlu menjalankan ulang. · FINAL OPTI |
| V11-max | SUMMARY kuning tepat lima field; CP tersedia -> Valid >= 1; Valid <= Diperiksa; counter = satu sumber (info V11 Candidate Counters / tl_best.counters); best termasuk valid; teknis hanya di Detail audit | LULUS | labels=Target waktu/Waktu aktual/Kandidat diperiksa/Kandidat valid/Cost Production ds={"target":"max","elapsed":"18.68","checked":"30","valid":"8","cp":"64.3188"} cnt={"ch":30,"va":8,"best":true} tl_best=- |
| T7 | tidak ada JavaScript error | LULUS |  |

Hasil: **6/6**
