# V8 UI — reproducer Daily_Plan_09_Jul_26_Baru

| id | uji | hasil | bukti |
|---|---|---|---|
| 1 | U1 reproducer FINAL 48 row, hard PASS, Export 48/48, residual 0 | PASS | FINAL 14258 ms rows=48 hard=PASS exp=OK cp=64.3644 |
| 2 | U2 GE1-GE4 = 0 MW tanpa gas MM2100 sah | PASS | GE rows [0,0,0,0] sumber=NONE used=0 |
| 3 | U3 unit prioritas rendah tidak continuous running: G2 off, G5 hanya sepanjang minimum runtime (stop pada row legal pertama) | PASS | G2 rows 0, G5 rows 27-38 (12) |
| 4 | U4 CP lebih rendah dari V7 64,5741 dan kandidat pembanding tercatat | PASS | CP 64.3644 (V8 64.5788 -> 64.3644) diterapkan SWAP:G2>G5:26-37, DELAY:G5:26-37:@27, POLISH_UNIT_PRIORITY_LANJUTAN |
| 5 | U5 Headroom/Unit Priority PASS_WITH_REASON, alasan terstruktur per row untuk G5 | PASS | PASS_WITH_REASON temuan 85 tanpa alasan 0; contoh 27:DECOMMIT:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW);DELAY:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW);DELAY:EXPORT;SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G3 (S1) mati sepanjang interval, G3 prioritas lebih rendah);SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G4 (S1) mati sepanjang interval, G4 prioritas lebih rendah);SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G6 (S1) mati sepanjang interval, G6 prioritas lebih rendah);MIN_RUNTIME_COMBINED_CYCLE_SEJAK_START_ROW_27;CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID SWAP:G5>G2:27-38 (CP 64.3723, +0.0079) / 29:DECOMMIT:EXPORT(kapasitas maks row 28 = 14.0 < Range Min 25.0 MW);SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G3 (S1) mati sepanjang interval, G3 prioritas lebih rendah);SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G4 (S1) mati sepanjang interval, G4 prioritas lebih rendah);SWAP:KEHILANGAN_UAP_COMBINED_CYCLE(G5 memasok S2 yang running; blok G6 (S1) mati sepanjang interval, G6 prioritas lebih rendah);MIN_RUNTIME_COMBINED_CYCLE_SEJAK_START_ROW_27;CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID SWAP:G5>G2:27-38 (CP 64.3723, +0.0079) |
| 6 | U6 kartu V8 (kandidat + provenance gas MM2100) tampil di UI | PASS | Review Unit Priority generik V9 (kandidat pembanding) & Provenance Bahan Bakar — APPLIED · CP 64.5788 → 64.3644 · Gas MM2100: NONE (PASS) · Provenance bahan bak |
| 7 | U7 kuota PEP KP72 2,2: GE berbeban dengan sumber KP72_QUOTA, provenance PASS | PASS | FINAL 14187 ms GE rows [48,0,0,0] used=2.199 cp=64.1989 |
| 8 | U8 kembali ke kuota KP72 0: hasil identik (deterministik) | PASS | FINAL 816 ms cp 64.3644 identik=true |
| 9 | U9 tidak ada JavaScript error | PASS |  |

**9/9 PASS**
