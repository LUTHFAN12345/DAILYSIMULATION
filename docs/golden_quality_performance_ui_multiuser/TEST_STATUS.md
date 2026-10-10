# TEST_STATUS — V15.16 (source final)

Status dipisah sesuai permintaan: **Functional**, **Validation**, **Quality**, **Performance**, **Release Blocker**. Status PASS pada satu kolom tidak dipakai untuk menyatakan kolom lain PASS.

## Ringkasan gerbang rilis (ADDENDUM)
| Gerbang (jangan package bila benar) | Hasil terukur (source final) | Status |
|---|---|---|
| Golden Fastest CP > 80.2521 | CP **79.9299** (XAMPP 3/3 cold + 3/3 warm, Linux, CLI) | Tidak terpicu |
| Golden Fastest HR > 8251.58 | HR **8239.87** | Tidak terpicu |
| Golden Fastest cold > 15 s | 11,57 / 11,60 / 11,79 s (XAMPP), 12,13 s (Linux) | Tidak terpicu |
| Maximum Review > 80 s | Golden 25,17 s (Linux 26,91); P1 Max + LNG 65,89 s (Linux 66,32) | Tidak terpicu |
| Rekomendasi > 10 s | Popup 2,6–3,7 s (normal), 6,9–7,2 s (PV-on), 7,9–8,8 s (Max); tertinggi teramati 9,98 s pada build pra-final (Max) | Tidak terpicu (margin Max kecil) |
| LNG / Distillate accepted > 15 s | R2 13,17–13,70; R3 11,57–11,81; R6 12,87–13,77; P11 13,21 (XAMPP); Linux R2 13,40, R3 11,28, R6 13,13 | Tidak terpicu |
| Difficult feasible > 30 s | P10 PV-on LNG 14,77 / Distillate 20,69; P13 11,83; Linux PV-on LNG 15,22 | Tidak terpicu |
| Follow PV / Fix SR / Effective SR / IE chart hilang | ada & teruji end-to-end (UI_FEATURE_RESTORATION.md, IE_CHART_ACCEPTANCE.md) | Tidak terpicu |
| G5 start dini / G9 65 MW / Distillate unit minimum-load tanpa proof | G5 mulai row 14 (07:00) dengan bukti V8; G9 minimum 84,1 MW; Distillate hanya G6, 0 slot pada unit ≤ 5 MW | Tidak terpicu |
| Isolasi concurrency gagal | C0–C6 + S0/S1 PASS di Linux & XAMPP-12 (satu bug baru ditemukan & diperbaiki: supersede lintas user) | Tidak terpicu |
| Datastore kehilangan field / report / history | sdstest PASS (SQLite PHP 8, JSON PHP 7.4 & 8); reload memulihkan seluruh field | Tidak terpicu |
| C1–C4 / STG / hard validation gagal | seluruh 48-row result: gate PASS, C1–C4 FAIL 0, STG 144/0 | Tidak terpicu |

**Release Blocker: TIDAK ADA** yang terpicu pada source final.

## Status per kategori
| Kategori | Status | Catatan |
|---|---|---|
| Functional Pass | **PASS** | R0–R10 berjalan sampai 48 row FINAL (P12 = keputusan terminal ber-evidence, sesuai harapan); UI U0–U7 PASS; Save/Reload PASS; multi-user C0–C6 + S0/S1 PASS |
| Validation Pass | **PASS** | Hard validation PASS, gate PASS, C1–C4 FAIL = 0, STG PASS 144/0, `sr_under = 0` pada semua run; tidak ada validator yang dilonggarkan |
| Quality Pass/Fail | **PASS** | Golden Fastest CP 79.9299 ≤ 80.2521, HR 8239.87 ≤ 8251.58; Distillate 93.641 l hanya G6 (full-first); G9 ≥ 84,1 MW; tanpa start G5 01:00 |
| Performance Pass/Fail | **PASS** | Seluruh target terpenuhi (tabel di atas). Catatan: rekomendasi Max 7,9–9,98 s (margin < 0,1 s pada satu pengukuran pra-final) |
| Release Blocker | **TIDAK ADA** | — |

## Catatan jujur
1. **Golden Excel dievaluasi ulang.** Dispatch GTG Excel Baru(5), dibekukan dan dihitung ulang oleh engine + validator final, menghasilkan CP **80.2858** (validator 92263fb: 80.2321). Penyebabnya aturan Distillate full-first yang baru: seluruh Distillate golden ditempatkan pada G6 (96.490,7 l, sedangkan Excel 96.057,7 l tersebar). Gerbang tetap memakai angka header golden 80.2521; hasil Fastest final (79.9299) lebih baik dari keduanya.
2. **V8 Fastest dibatasi waktu.** Kasus dengan banyak kandidat dapat sedikit berbeda antar mesin, misalnya R4 PV-on LNG: XAMPP 74.9099, Linux 74.885 (pra-final 74.8839 / 74.933). Seluruhnya valid, C1–C4 PASS. Golden, R2, R3, R5, R6, R9, R1 identik antar platform.
3. **Uji concurrency Linux pertama** pada source final dijalankan bersamaan dengan uji C5 XAMPP di mesin 4 vCPU yang sama, sehingga A (Max) belum FINAL dalam 240 s. Diulang sendirian: PASS (A FINAL 60,4 s, `ui/conc_final_linux.log`). Log percobaan pertama tertimpa oleh pengulangan; ringkasannya: B FINAL 11,5 s (CP 80.1442), C4/C6/C0 PASS, A Max PROVISIONAL CP 71.8437 saat batas 240 s uji tercapai.
4. **Uji XAMPP** memakai proksi semantik (Node + backend `php -S` PHP 7.4), bukan Apache Windows. Parity server nyata dibuktikan di Linux Apache 2.4 + PHP-FPM 7.4.
5. **Matrix Fastest XAMPP (`runs/racc3`)** dijalankan pada source yang identik dengan final, kecuali perbaikan supersede ber-scope. Perbaikan itu hanya mengubah pembatalan job lintas user/tab. Golden R0/R1/P1 diulang pada source final (`runs/final_x`), dan R0–R9 + R1 + P1 Linux seluruhnya pada source final.
