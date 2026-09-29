# Laporan V11 — konsolidasi beban, band CP 0,2 % + Heat Rate, counter kanonik, FINAL tidak bergantung jalur

Paket `php_simulation_REVISI_OPTIMASI_ENGINE_V11_FINAL.zip` (lanjutan langsung checkpoint V11 atas V10 FINAL). Mesin uji: PHP 7.4.3 (CLI + server multi-backend 6 proses meniru Apache), 4 inti CPU, 3 pekerja pembantu. Arsitektur: `ARSITEKTUR_V11.md`; runtime: `PERFORMA_V11.md`; audit: `AUDIT_UI_COUNTER_V11.md`, `AUDIT_CP_HEATRATE_V11.md`, `AUDIT_LOW_LOAD_FRAGMENTATION_V11.md`; path independence: `PATH_INDEPENDENCE_V11.md`. Log mentah di `reports/`.

## Source beku (SHA-256)

```
12137dab1340ce220eee1b8436bf7d78f0f04c43c72799e1c707afc5dc995f6e  run.php
b2dc4d47fe5c558fdea24ee12aa2e3a5bb9783e3f0b457b2c98634090ac1d88c  worker02.php
cc39968b2245b11e87b920b3f6145abe08763a97e1f3bb28554e9d90e7535803  worker_functions.php
78429d333fb468c40176693aa5c362e51be8dfeff7e99a8cf02c188cb187690c  index.php
```

## Ringkasan hasil

- Reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3): FINAL 48 row, hard constraints PASS, Export 48/48, residual shortage 0, rilis True; Cost Production **63.4469 USD/MWh**, Heat Rate **8109.13 BTU/kWh** (workbook (3): 63,7145 / 8165,81; sebelum review Unit Priority 63.7144).
- Comparator V11: CP_min 63.4469, band s.d. 63.5738, 7 kandidat valid, 5 di dalam band, pemenang POLISH_UNIT_PRIORITY_LANJUTAN.
- Kandidat (counter satu sumber): diperiksa 29 = full-run 25 + gugur Tier 1 4; valid 14; FINAL termasuk valid: True. Sapuan konsolidasi: dibangkitkan 5, gugur Tier 1 4, full-run 1, valid 1.
- LOW_LOAD_FRAGMENTATION: PASS_WITH_REASON (row 5, temuan 10, tanpa alasan 0). Unit Priority (Headroom Priority Audit 48 row): PASS_WITH_REASON, tanpa alasan 0. CP audit: PASS. Provenance bahan bakar: PASS.
- Path independence: **13/13 state identik di seluruh jalur yang dijalankan** (47 perbandingan terhadap direct).

## Perubahan V11 (ringkas; rinci di ARSITEKTUR_V11.md)

1. **UI**: SUMMARY kuning tepat lima field (Target waktu, Waktu aktual, Kandidat diperiksa, Kandidat valid, Cost Production); detail teknis di panel Detail audit; berlaku di seluruh jalur (Target < 15…< 60, Maximum Review, provisional, exact final, no valid result, Gas Shortage, rerun bahan bakar). JavaScript error Target Selesai (V11_RUN_T0/V11_SUM_DONE/v11AuditExtra) diperbaiki.
2. **Counter satu sumber** berkunci dispatch fisik (full-run/valid) dan commitment fisik kanonik (screened-out); alias dan screened yang juga di-full-run tidak dihitung ulang.
3. **Objective**: CP minimum lalu band 0,2 % dengan tie-break Heat Rate → start → row prioritas rendah → row fragmentation → skor prioritas → kunci kanonik; release gate menerima pemenang band dengan bukti.
4. **Konsolidasi generik + audit LOW_LOAD_FRAGMENTATION**, bukti counterfactual 48 row dari kandidat valid comparator untuk (row, unit) pemenang.
5. **Path independence**: alias V5 "stop tambahan tidak mengikat" (sumber FINAL berbeda antar jalur) dimatikan.
6. **Runtime**: prefetch spekulatif keluarga commitment oleh pembantu yang menganggur; indeks unit_stop_time.

## Runtime terhadap target V11

## 0. Ringkasan terhadap target runtime V11

| kategori | n | median | maks | target (maks) | lulus |
|---|---|---|---|---|---|
| update Actual / FF / KP72 satu slot | 14 | 7,65 | 8,66 | 5,0 (5,0) | 1/14 — lewat: ACT_PGN_UP 7,63, ACT_PGN_DOWN 7,37, ACT_FFJ_UP 8,66, ACT_FFM_UP 8,38, ACT_FFM_DOWN 8,62, KP72_UP 7,92, KP72_DOWN 8,61, ACT_PGN_2SLOT_C 6,11, ACT_PGN_UP 7,32, ACT_PGN_DOWN 6,95, ACT_PGN_2SLOT_C 5,66, KP72_DOWN 8,20, ACT_FFJ_UP 7,68 |
| update Actual / FF / KP72 satu slot (valid pertama) | 14 | 3,02 | 4,06 | 2,0 (2,0) | 1/14 — lewat: ACT_PGN_UP 3,03, ACT_PGN_DOWN 3,28, ACT_FFJ_UP 4,06, ACT_FFM_UP 2,99, ACT_FFM_DOWN 3,53, KP72_UP 3,06, KP72_DOWN 3,24, ACT_PGN_2SLOT_C 3,02, ACT_PGN_UP 2,98, ACT_PGN_DOWN 2,84, ACT_PGN_2SLOT_C 2,59, KP72_DOWN 2,59, ACT_FFJ_UP 3,60 |
| kuota ±1..4 (basis Actual, termasuk FINAL jangkar baru) | 7 | 28,33 | 34,16 | 10,0 (10,0) | 1/7 — lewat: QA_pgn_pipe_1 29,10, QA_pgn_pipe_2 26,85, QA_pgn_pipe_4 34,16, QA_lng_1 29,07, QA_lng_1 28,33, QA_pgn_pipe_1 28,12 |
| kuota ±1..4 (basis PGN 30) | 9 | 20,70 | 22,53 | 10,0 (10,0) | 0/9 — lewat: Q_pgn_pipe_1 20,86, Q_pgn_pipe_2 16,07, Q_pgn_pipe_4 14,58, Q_pep_1 22,23, Q_pep_2 20,10, Q_lng_1 20,49, Q_akasia_1 22,53, Q_pep_1 21,99, Q_pgn_pipe_1 20,70 |
| exact berat | 6 | 26,52 | 52,42 | 40,0 (40,0) | 4/6 — lewat: Q_pep_kp72_1 50,45, BASE_ACT10 52,42 |
| UI update slot | 9 | 8,35 | 11,19 | 5,0 (5,0) | 0/9 — lewat: PGN_UP 8,77, PGN_DOWN 7,78, FFJ_UP 11,19, FFM_UP 7,98, FFM_DOWN 8,95, KP72_UP 8,84, KP72_DOWN 8,08, PGN_2SLOT 6,22, FFJ_SMALL 8,35 |
| commitment berubah (UI) | 8 | 14,57 | 36,41 | 15,0 (15,0) | 4/8 — lewat: F0_BASE 30,47, F1_STOP_G1_1000 32,32, F2_STOP_G1_SLOTS 36,41, F5_COMBO 21,07 |
| reproducer UI | 1 | 17,30 | 17,30 | 15,5 (15,5) | 0/1 — lewat: WB09 17,30 |
| reproducer UI WB09_3 | 1 | 18,63 | 18,63 | 15,5 (15,5) | 0/1 — lewat: WB09_3 18,63 |
| reproducer CP WB09 (<= V10 x 1,002) | 1 | 64,3644 | 64,3644 | 64,3644 (64,4931) | 1/1 |
| Gas Shortage opsi → FINAL | 1 | 10,61 | 10,61 | 20,0 (20,0) | 1/1 |
| rerun bahan bakar (Gas Shortage opsi) | 8 | 10,83 | 38,11 | 10,0 (10,0) | 2/8 — lewat: PGN 25 T2 10,61, PGN 25 T3 11,04, PGN 25 T5 38,11, PGN 20 T2 10,03, PGN 20 T3 12,52, PGN 20 T5 35,93 |


Catatan jujur: target yang belum tercapai dicantumkan apa adanya di tabel di atas (kolom "lewat"). Mesin uji cloud ini ±26 % lebih lambat per inti daripada mesin uji V10 (reproducer CLI dengan V11 dimatikan: 31,6 s vs ±25 s referensi), dan mematikan alias V5 demi path independence menambah core run pada rute cepat.

## Targeted test dari clean-extract ZIP final

| suite | PASS | FAIL |
|---|---|---|
| GAS_SHORTAGE_PGN20 | 9 | 0 |
| GAS_SHORTAGE_PGN25 | 9 | 0 |
| OPS_UI | 3 | 0 |
| POPUP | 8 | 0 |
| PROV_UI_PGN1 | 6 | 0 |
| SAVE_UI | 10 | 0 |
| TARGETED12 | 57 | 0 |
| TL_NOVALID | 1 | 0 |
| TL_UI | 20 | 0 |
| V10_CASES | 19 | 0 |
| V11_CASES | 16 | 0 |
| V8_CASES | 12 | 0 |
| V9_CASES | 23 | 0 |
| WB09_3_UI | 5 | 0 |
| WB09_UI | 9 | 0 |
| PATH_INDEPENDENCE (state identik) | 13 | 0 |
| **total** | **220** | **0** |

- IDENTIK DENGAN FREEZE

- baris FAIL di log: 0

## Full regression (source beku, satu kali)

| suite | PASS | FAIL |
|---|---|---|
| DISPATCH_RELIABILITY | 25 | 0 |
| EXEC_LOCK_UNIT | 12 | 0 |
| FUEL_RECOMPUTE | 15 | 0 |
| GAS_SHORTAGE_PGN20 | 9 | 0 |
| GAS_SHORTAGE_PGN25 | 9 | 0 |
| OPS_UI | 3 | 0 |
| POPUP | 8 | 0 |
| PROV_UI_PGN1 | 6 | 0 |
| PROV_UI_PGN33 | 6 | 0 |
| SAVE_UI | 10 | 0 |
| TARGETED12 | 57 | 0 |
| TL_NOVALID | 1 | 0 |
| TL_UI | 20 | 0 |
| TL_UI_MAX_FRESH | 6 | 0 |
| V10_CASES | 19 | 0 |
| V11_CASES | 16 | 0 |
| V8_CASES | 12 | 0 |
| V9_CASES | 23 | 0 |
| VO_CLOSE_RERUN | 3 | 0 |
| WB09_UI | 9 | 0 |
| **total** | **269** | **0** |

- HASH TIDAK BERUBAH SELAMA REGRESSION

- DETERMINISME_IDENTIK

- JS OK

- baris FAIL di log: 0

## Perubahan ekspektasi uji yang disengaja (V11)

- `V10_CASES` V03 dan `V9_CASES` "CP <= kandidat valid termurah" → `FINAL_CP <= CP_min × 1,002` (band CP 0,2 % + tie-break Heat Rate), sesuai objective V11 (manifest §8). Salinan: `v11/v10_cases_v11.php`, `v11/v9_cases_v11.php`.
- `V10_CASES` V06 "CP = referensi V9" → `CP V11 <= referensi V9 × 1,002`; hasil V11: Q_pgn_pipe_1 64,5424 dan Q_lng_1 64,8581, lebih rendah dari V9 (64,5450 / 64,8608).
- `TL_UI` → `v11/tl_ui_v11.js`: Global optimum proven dibaca dari panel Detail audit (bukan SUMMARY/run-msg); pemeriksaan SUMMARY lima field + invarian counter ditambahkan; T8 memakai band 0,2 %.
- Suite baru: `v11/v11_cases.php` (reproducer WB09_3 + audit V11 + band + counter + determinisme), `v11/wb09_3_ui.js` (reproducer UI), `v11/pathind_v11.sh` (13 state, + WB09_3).

## Paket

- Akar ZIP: `run.php`, `worker02.php`, `worker_functions.php`, `index.php`, dokumen `*.md`, `SHA256SUMS.txt`, `reports/`.
- Rollback V10: source V10 FINAL tidak tersedia di workspace sesi ini (dependensi uji tidak menyertakannya; lihat DEPENDENCIES_MANIFEST). Folder `rollback/` hanya disertakan bila berkas V10 FINAL tersedia saat paket dibangun; hash sah V10 FINAL ada di `reports/ref/FREEZE_SHA256_V10.txt`.


## Pengukuran ulang reproducer WB09_3 UI (source beku, kondisi VM berbeda)

Waktu klik Run → FINAL pada UI nyata (3 pekerja pembantu), kode identik:

| jendela ukur | patokan CLI WB09_3 (tanpa pembantu) | reproducer UI WB09_3 |
|---|---|---|
| sesi pengembangan (sebelum freeze, kode sama) | 31,5–32,8 s | 15,2 / 15,3 / 15,3 / 15,6 / 15,9 / 16,2 s |
| sesudah clean-extract (source beku) | 35,8 s | 17,7 / 18,1 / 18,5 / 18,5 / 19,2 s |
| clean-extract (satu sampel, di PERFORMA) | — | 18,6 s |

Kesimpulan jujur: target reproducer UI ≤ 15,5 s **belum tercapai secara andal** di VM uji ini; hasilnya bergantung
kecepatan VM (±12 % antar jendela ukur). Prefetch spekulatif keluarga commitment menurunkan waktu dari 17,6 s ke
±15,5 s pada jendela yang sama. Target runtime lain yang belum tercapai tercantum di tabel ringkasan PERFORMA di atas
(kolom "lewat"); penyebab utama: (1) jangkar exact baru untuk perubahan kuota di atas basis Actual, (2) alias V5 dimatikan
demi path independence (core run rute cepat bertambah), (3) VM cloud ini lebih lambat per inti daripada mesin uji V10.
Seluruh hasil fungsional (constraints, provenance, Unit Priority, CP minimum dalam band, path independence, determinisme)
tetap PASS.
