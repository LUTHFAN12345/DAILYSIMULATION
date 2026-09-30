# Targeted Acceptance — 12 Skenario Gas Shortage Decision

URL: `http://127.0.0.1:9300` · Chromium, klik nyata, payload POST ditangkap.

Yang diuji adalah **kontrak urutan**, bukan keberadaan angka: hasil pendahuluan tidak boleh
tampil sebagai hasil final, dan Save/Export/Publish wajib terkunci sampai operator memilih.

| id | pemeriksaan | verdict | keterangan |
|---|---|---|---|
| S1 | Recommendation shortage>0: popup keputusan MUNCUL | PASS |  |
| S2.a | sebelum keputusan: OUTPUT final TIDAK diisi | PASS | outputAda=false rows=0 |
| S2.b | sebelum keputusan: tabel HASIL tidak berisi baris | PASS | baris #tbl-result=0 |
| S2.d | sebelum keputusan: ringkasan hasil masih kosong | PASS |  |
| S2.c | sebelum keputusan: gerbang aktif | PASS | GSD_GATE=true |
| S3.a | sebelum keputusan: Save Input TETAP AKTIF (V3: gerbang hanya mengunci hasil) | PASS | disabled=false |
| S3.b | sebelum keputusan: Export Excel TERKUNCI | PASS | disabled=true |
| S3.c | Export dipanggil paksa tetap diblokir gerbang | PASS | {"diblokir":true,"unduh":false} |
| S4.a | tombol LNG tervalidasi menjadi aktif | PASS |  |
| S4.b | rerun final berjalan dan gerbang dibuka | PASS | src=add_lng |
| S4.c | hasil final tampil 48 baris | PASS | rows=48 |
| S4.d | Save terbuka kembali setelah hasil final | PASS | disabled=false |
| S4.d2 | Export terbuka kembali setelah hasil final | PASS | disabled=false |
| S4.q | hasil final: 48 baris, konvergen, economic review selesai, release gate lulus | PASS | {"rows":48,"conv":true,"econ":true,"rel":true,"hard":"PASS"} |
| S4.e | action final add_lng dengan LNG > 0 dan residual 0 | PASS | {"src":"add_lng","added":9.7385,"resid":0} |
| S5.a | tombol Distillate tervalidasi menjadi aktif | PASS |  |
| S5.b | rerun final Distillate dan gerbang dibuka | PASS | src=use_distillate |
| S5.c | action use_distillate, liter > 0, residual 0 | PASS | {"src":"use_distillate","used":270299.2,"resid":0} |
| S6.a | Add LNG kosong -> otomatis Required LNG: tidak ada popup kedua | PASS |  |
| S6.b | Add LNG kosong -> otomatis Required LNG: rerun final otomatis berjalan | PASS | payload=["recommendation","add_lng"] |
| S6.c | Add LNG kosong -> otomatis Required LNG: action final = add_lng | PASS | src=add_lng |
| S6.d | Add LNG kosong -> otomatis Required LNG: residual tertutup | PASS | residual=0 |
| S6.e | Add LNG kosong -> otomatis Required LNG: 48 baris hasil final | PASS | rows=48 |
| S7.a | Add LNG manual = Required LNG: tidak ada popup kedua | PASS |  |
| S7.b | Add LNG manual = Required LNG: rerun final otomatis berjalan | PASS | payload=["recommendation","add_lng"] |
| S7.c | Add LNG manual = Required LNG: action final = add_lng | PASS | src=add_lng |
| S7.d | Add LNG manual = Required LNG: residual tertutup | PASS | residual=0 |
| S7.e | Add LNG manual = Required LNG: 48 baris hasil final | PASS | rows=48 |
| S8.a | Add LNG manual > Required LNG: tidak ada popup kedua | PASS |  |
| S8.b | Add LNG manual > Required LNG: rerun final otomatis berjalan | PASS | payload=["recommendation","add_lng"] |
| S8.c | Add LNG manual > Required LNG: action final = add_lng | PASS | src=add_lng |
| S8.d | Add LNG manual > Required LNG: residual tertutup | PASS | residual=0 |
| S8.e | Add LNG manual > Required LNG: 48 baris hasil final | PASS | rows=48 |
| S9.a | Add LNG manual < Required LNG -> CAMPURAN: tidak ada popup kedua | PASS |  |
| S9.b | Add LNG manual < Required LNG -> CAMPURAN: rerun final otomatis berjalan | PASS | payload=["recommendation","mixed_lng_distillate"] |
| S9.c | Add LNG manual < Required LNG -> CAMPURAN: action final = mixed_lng_distillate | PASS | src=mixed_lng_distillate |
| S9.d | Add LNG manual < Required LNG -> CAMPURAN: residual tertutup | PASS | residual=0 |
| S9.e | Add LNG manual < Required LNG -> CAMPURAN: 48 baris hasil final | PASS | rows=48 |
| S9.f | CAMPURAN: LNG operator dipakai DAN distillate menutup sisa | PASS | LNG=7.9856 distillate=48952.1 l |
| S10.a | Distillate kosong -> otomatis rekomendasi: tidak ada popup kedua | PASS |  |
| S10.b | Distillate kosong -> otomatis rekomendasi: rerun final otomatis | PASS | src=use_distillate |
| S10.c | Distillate kosong -> otomatis rekomendasi: action use_distillate | PASS | src=use_distillate |
| S10.d | Distillate kosong -> otomatis rekomendasi: residual tertutup | PASS | residual=0 |
| S10.e | Distillate kosong -> otomatis rekomendasi: 48 baris hasil final | PASS | rows=48 |
| S11.a | Distillate manual cukup: tidak ada popup kedua | PASS |  |
| S11.b | Distillate manual cukup: rerun final otomatis | PASS | src=use_distillate |
| S11.c | Distillate manual cukup: action use_distillate | PASS | src=use_distillate |
| S11.d | Distillate manual cukup: residual tertutup | PASS | residual=0 |
| S11.e | Distillate manual cukup: 48 baris hasil final | PASS | rows=48 |
| S11.f | plafon operator tidak dilampaui | PASS | 270299.2 <= 300000 |
| S12.a | Batal: popup tertutup | PASS |  |
| S12.b | Batal: TIDAK ada rerun final terkirim | PASS | [] |
| S12.c | Batal: hasil final tetap TIDAK ada | PASS | outputAda=false rows=0 |
| S12.d | Batal: Save Input tetap aktif (V3) | PASS |  |
| S12.e | Batal: Export tetap terkunci | PASS |  |
| S0.a | tidak ada galat JavaScript sepanjang 12 skenario | PASS | nol galat kode |
| S0.b | pemberitahuan HTTP hanya 422 (kontrak hasil tidak dapat dipublikasikan) | PASS | total=0 (422=0); 422 adalah kontrak backend untuk hasil yang belum final, bukan galat kode |

**57/57 PASS** (0 FAIL)
