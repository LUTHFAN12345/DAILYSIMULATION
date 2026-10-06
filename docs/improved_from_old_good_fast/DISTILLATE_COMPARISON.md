# Distillate — OLD_GOOD_FAST vs terbaru

**Aturan terbaru: satu-unit-first.**
- Unit pertama diisi sampai mix 100 % sebelum unit berikutnya menerima Distillate pada row itu.
- Unit kedua hanya dipakai bila kapasitas unit pertama tidak cukup.
- Pemeriksaan per row: hitung row dengan lebih dari satu unit ber-mix parsial (< 100 %).

| Kasus | Source | Distillate per unit (L) | Total (L) | Row ≥ 2 unit | Row ≥ 2 unit parsial | CP |
|---|---|---|---|---|---|---|
| Smoke Distillate (`D_rec.json`, replay) | **lama** | G1 130.931,7 + **G5 709,5 (mix 30 %, row 26)** | 131.640,7 | 1 | 0 | 77,7918 |
| | **terbaru** | **G1 132.177,9** | 132.177,5 | 0 | 0 | 77,8572 |
| Reproducer PGN30/PEP34 | lama | job 2 diminta 289.445 L (dibatasi user cap); rencana tidak sah | — | — | — | — |
| | terbaru | G4 243.922,7 (mix 100 %) + G1 35.520 | 279.440,8 | 7 | 0 | 95,8967 |
| State pengguna | lama (final 212 s) | G6 228.855,5 + G4 91.221,1 + G1 19.183,4 | 339.259,1 | 21 | 0 | 103,0982 (C4 15 FAIL) |
| | terbaru (Fastest) | G6 204.252,1 + G3 93.136,4 + G2 56.200,7 | 353.588,1 | 33 | 0 | 104,7755 (C4 0) |

## Temuan

- **Smoke Distillate.**
  - Dispatch GTG identik (sig `7009a446c9d1`).
  - Source lama menyebarkan **709,5 L** ke unit kedua (G5, mix 30 %) pada satu row. Source terbaru menahan seluruh Distillate di G1 (satu unit), dengan liter sedikit lebih besar karena level diskrit (+537 L, CP +0,065 USD/MWh).
  - Sesuai §9, perilaku menyebar sedikit itu **tidak ditiru**.
- **Reproducer dan state pengguna.**
  - Unit kedua/ketiga hanya menerima Distillate pada row yang unit sebelumnya sudah 100 %. Tidak ada row dengan dua unit parsial, sehingga satu-unit-first terpenuhi.
  - Kebutuhan Distillate di sini melebihi kapasitas satu unit (pengganti gas 10,07 / 12,74 BBTUD).
- Perbedaan unit pembawa Distillate pada state pengguna (lama G6/G4/G1, terbaru G6/G3/G2) mengikuti commitment yang berbeda. Rencana lama melanggar C4, sedangkan rencana terbaru lolos merit.

Engine Distillate tidak diubah di paket ini (`worker_functions.php` identik dengan 0a2cedf).
