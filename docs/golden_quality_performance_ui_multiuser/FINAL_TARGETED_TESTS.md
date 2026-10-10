# FINAL_TARGETED_TESTS — Re-acceptance R0–R10

Hanya uji tertarget R0–R10 yang dijalankan (sesuai ADDENDUM). Browser nyata (Chromium), waktu = klik Run → 48 row FINAL. Kolom "XAMPP" = proksi semantik XAMPP (6 backend PHP 7.4); "Linux" = Apache 2.4 + PHP-FPM 7.4. Semua di source final, kecuali yang ditandai ᵃ (`runs/racc3`: identik dengan final, kecuali supersede ber-scope yang hanya aktif pada multi-user).

| # | Uji | XAMPP | Linux | Hasil |
|---|---|---|---|---|
| R0 | Golden Fastest 3 cold + 3 warm | cold 11,57 / 11,60 / 11,79 s; warm 1,24 / 1,28 / 1,32 s; CP 79.9299, HR 8239.87, Distillate 93.641,1 l (G6 100 %) | cold 12,13 s; CP 79.9299 / HR 8239.87 | PASS |
| R1 | Golden Maximum Review 1 cold + 1 warm | 25,17 s / 1,29 s; CP 79.9299 | 26,91 s; CP 79.9299 | PASS |
| R2 | LNG accepted 3 + 3 (P0) | cold 13,17 / 13,37 / 13,70ᵃ; warm 1,41–1,55ᵃ; popup 2,87–3,19 s; CP 71.9046, HR 8265.71 | 13,40 s; CP 71.9046 | PASS |
| R3 | Distillate accepted 3 + 3 (P0) | cold 11,57 / 11,79 / 11,81ᵃ; warm 1,33–1,51ᵃ; CP 79.9299, Distillate G6 saja | 11,28 s; CP 79.9299 | PASS |
| R4 | Follow PV ON / OFF | ON+LNG 14,77 s (CP 74.9099), ON+Dist 20,69 s (CP 90.9873), OFF+LNG 13,79 s (CP 71.9046)ᵃ; UI Follow PV end-to-end CP 91.3765, `sr_under` 0 | ON+LNG 15,22 s (CP 74.885) | PASS (P10 = difficult ≤ 30 s) |
| R5 | Actual Gas (`R5_BASE_ACT10`) | cold 12,99 / 13,46 / 13,46ᵃ; warm 1,02; CP 64.6663 | 13,88 s; CP 64.6663 | PASS (sebelumnya 21,4–22,0 s) |
| R6 | PGN/PEP ±1 | PGN22 13,48 · PGN24 12,93 · PEP35 12,87 · PEP37 13,77 sᵃ | PEP37 13,13 s; CP 71.4432 | PASS |
| R7 | IE chart / import / save / reload | U4 edit + tooltip, U5 import CSV 581→601 & chart max 598→618, U6 reload sr_mode / PV / IE / chart 48 titik | — | PASS |
| R8 | Dua user concurrency | C0–C6 + C5 cold (detached) + S1 | C0–C6 + C5 cold (detached) + S1; S0 unit test | PASS (1 bug ditemukan & diperbaiki) |
| R9 | Change Over smoke (B2→B1) | 12,09 sᵃ; CP 64.6658 | 12,17 s; CP 64.6658 | PASS |
| R10 | XAMPP / Linux parity | — | R0, R1, R2, R3, R4, R5, R6, R9, P12, P1 di Linux: CP identik kecuali R4 (V8 dibatasi waktu, selisih 0,025) | PASS |

Tambahan (kasus performa dari ADDENDUM):
- P11: 13,21 sᵃ.
- P13: 11,83 sᵃ.
- P12: keputusan terminal 4,63 s (Linux 4,61 s).
- P1 Max + LNG: 65,89 s, popup 8,83 s (Linux 66,32 s, popup 7,92 s).

Uji non-UI:
- `sdstest.php`: SQLite PHP 8.4, JSON PHP 8.4, JSON PHP 7.4 → PASS.
- `suptest.php`: final PASS, source sebelum perbaikan FAIL.
- Golden Excel re-validasi: VALID / hard PASS, CP 80.2858.
- Lint PHP 7.4 dan PHP 8.4: bersih.
