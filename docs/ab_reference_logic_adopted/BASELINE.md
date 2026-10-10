# BASELINE

## Artefak masuk (SHA-256)
| File | SHA-256 |
|---|---|
| Revisi_terkahir_Copilot.zip (basis) | 624d20594280e60f11e8412bce2387879dd7ef7b7abfc13ce39113842aef5c53 |
| File Reference Because the Result But Still not Update with other fiture.zip (acuan logika) | f5598a06f2363f439faffb20983843f2fe81c9a6d5b0f3509821aaaf310ebba0 |
| input_data.json | aff6913b1589b26c030873ebcd57b06729bf20b2e663e44c7d83a31579e9eabd |
| Daily_Plan_10_May_26_Baru(5).xls | c081db6851948f672552640cff3eabd692e356bd1310d412643b4213892a1333 |
| INSTRUKSI_CLAUDE_AB_REFERENCE_VS_REVISI_TERAKHIR.md | b088d1d992ec4e05912877f179076243fcb1fea89e37efa45976d6ecd9e5c34f |

## Baseline terukur (UI yang sama, input_data.json, server XAMPP-semantics `max_execution_time=30`)
| Paket | Fastest P0 | Popup bahan bakar | LNG final | Distillate final |
|---|---|---|---|---|
| Revisi terakhir (V15.14) | 16,9 detik, pesan "BELUM konvergen" | **tidak muncul** | gagal ±34 detik ("dihentikan batas waktu bb_upfill/export_floor_donate") | gagal ±34 detik |
| Reference (V13.4; UI default = Max) | — | 39 detik (LNG 5,4357 / Dist 150.871 l) | 54,75 detik; CP 72,3162; HR 8230,26 | 46,67 detik; CP 82,0764; HR 8261,22 |

## Golden oracle Daily_Plan_10_May_26_Baru(5).xls
Validasi ulang dengan validator terbaru: **VALID / hard PASS**.
- dispatch GTG dibekukan, deviasi 0 MW
- CP 80,2321; HR 8249,58
- Distillate 96.057,7 l (G6 95.348,7 + G4 709,5)
- gas 79,1963 / kuota 79,2; PLN OK
- Commitment: G1 row 16–36, G4 row 15–32, tanpa G3/G5.

Golden layak dipakai sebagai oracle. Ekspektasi golden tidak diubah.
