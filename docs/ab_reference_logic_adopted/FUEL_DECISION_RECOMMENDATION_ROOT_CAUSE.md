# FUEL_DECISION_RECOMMENDATION_ROOT_CAUSE

## Gejala P0 (direproduksi lewat UI yang sama, input_data.json terbaru)
Revisi terakhir Copilot (V15.14), tombol **Fastest - Default**:
- 16,9 detik → pesan *"FASTEST membutuhkan keputusan bahan bakar. Analisis pendahuluan yang BELUM konvergen…"*.
- **Popup pilihan LNG/Distillate tidak muncul**, dan tidak ada angka rekomendasi.
- Klik ulang dengan LNG/Distillate (lewat `gsfRerun`) gagal di ~34 detik dengan pesan *"dihentikan batas waktu bb_upfill/export_floor_donate"*.

Reference (V13.4, UI default = Maximum Review):
- Popup muncul pada 39 detik (LNG 5,4357 BBTUD / Distillate 150.871 l).
- LNG final 54,75 detik, CP 72,3162.
- Distillate final 46,67 detik, CP 82,0764.

## Akar masalah (terbukti di kode, bukan dugaan)
1. **Fastest Copilot berjalan sinkron sekitar 25 detik tanpa helper.**
   - `run.php mode=run` menjalankan pipeline penuh dalam request PHP (`max_execution_time` 30 detik di XAMPP).
   - Tahap `bb_upfill` dan `export_floor_donate` terpotong oleh budget.
   - Hasilnya berstatus `ECONOMIC_REVIEW_REQUIRED` / `CONTINUE_EXACT_ECONOMIC_REVIEW`, tetapi blok `async_job` dibuang. UI tidak punya job untuk diikuti, sehingga yang tampil hanya teks "BELUM konvergen" tanpa popup.
2. **Handoff async tidak membawa jumlah helper.** Di `pp_sync_handoff_respond`, `$asyncF` tidak berisi `helpers`. Akibatnya job yang dibuat tidak pernah menyalakan helper paralel.
3. **Rerun bahan bakar memakai jalur sinkron yang sama**, sehingga terpotong lagi pada budget yang sama.
4. **Flag internal basi tersimpan di input.**
   - `input_data.json` membawa `modeling.__no_exact_family` dan `modeling.__fastest_local_only` dari sesi lama.
   - Di reference, flag ini membuat Max Review berjalan tanpa family exact. Di revisi terakhir, flag ini mengacaukan rute.
   - `mode=save` tidak membersihkannya.
5. **Blok V15.14 menimpa hasil keputusan bahan bakar.** `Gas Shortage (BBTUD)` dan `Gas Shortage Action` ditimpa menjadi "none" setelah rerun, sehingga UI kehilangan konteks keputusan.

## Perbaikan (surgical, tanpa menyalin file reference utuh)
| # | Perubahan | Lokasi |
|---|---|---|
| F1 | Fastest langsung diserahkan ke job (`pp_sync_handoff_respond` dengan reason `FASTEST_FIRST_VALID`), tidak lagi sinkron 25 detik | run.php `mode=run` |
| F2 | `helpers` ditambahkan ke handoff async | run.php `pp_sync_handoff_respond` |
| F3 | Job Fastest khusus (`pp_v13f_fast_job`): satu pipeline basis tanpa GCR/family. Bila shortage terbukti, basis disimpan ke `jobs/_fastbasis/<key>.json` dan rekomendasi langsung dikirim | run.php |
| F4 | Rerun LNG/Distillate (V7) memakai basis Fastest yang tersimpan, lalu `pp_v3_frozen_eval` pada state bahan bakar. Tidak ada re-solve canonical dan tidak ada family pada Fastest | run.php `pp_v7_fuel_rerun_from_basis` |
| F5 | Flag `__no_exact_family`, `__fastest_local_only`, `_fast_default`, `_maximum_review` dibuang saat Save. UI juga menghapusnya sebelum kirim (`ppStripInternalFlags`) | run.php `mode=save`, index.php |
| F6 | Blok V15.14 tidak lagi menimpa `Gas Shortage (BBTUD)` / `Gas Shortage Action` | run.php |
| F7 | Rekomendasi sementara di popup: LNG = shortage + 0,02 BBTUD; Distillate = ceil(rec × 1,01 / 10) × 10 liter, dengan teks penjelasan | index.php |
| F8 | Sertifikat terminal: jika ekspor baris-1 mustahil dipenuhi meski semua unit available berjalan maksimal (STG dihitung ulang dengan `pp_recompute_stgs`), job selesai `TERMINAL_INFEASIBLE` beserta buktinya, tanpa popup bahan bakar | run.php `pp_v15_row1_export_cert` |

## Bukti setelah perbaikan
- P0: popup rekomendasi muncul pada median 9,69 detik cold (9,24 / 9,69 / 10,19), dengan shortage 5,3957 BBTUD. Dari 9 run cold alur P0, max 11,52 detik.
- Warm: 0,84 detik.
- Klik LNG → FASTEST_FINAL, median 15,97 detik setelah klik. CP 72,3059, gate PASS, C1–C4/STG PASS.
- Klik Distillate → FASTEST_FINAL, median 6,94 detik setelah klik. CP 82,0968; 114.378,9 l.
- Tidak ada lagi pesan "BELUM konvergen" dan tidak ada "dihentikan batas waktu" pada P0/P7/P8.
