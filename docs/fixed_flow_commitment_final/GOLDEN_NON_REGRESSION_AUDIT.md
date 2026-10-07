# GOLDEN_NON_REGRESSION_AUDIT (rilis Fixed Flow First + konsolidasi S7)

Payload: `golden_payload.json` (Excel golden pengguna). Validator: source final (hard + C1–C4 + STG + LLF). Setiap baris adalah run UI asli, cold.

| Tahap | Platform | Mode | Waktu (s) | CP | HR | Gas / PGN | Babelan MWh | Startup | Hard | C1–C4 | Sig dispatch |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Target (instruksi) | — | Fastest / Max | ≈2,8 / ≤80 | 63,978–63,980 | 8020,58–8021,06 | — | 5759,76 | G1 21–32 | PASS | FAIL 0 | — |
| Sebelum rilis ini (patch A+B) | XAMPP-sem | Fastest | 2,79 | 63,9783 | 8020,59 | 71,8527 / 32,9727 | 5759,75 | G1 21–32 | PASS | PASS | bc883f0a3aab |
| Sebelum rilis ini (patch A+B) | XAMPP-sem | Max Review | 35,9 | 63,9782 | 8020,58 | 71,8527 / 32,9727 | 5759,76 | G1 21–32 | PASS | PASS | b72cb0f66569 |
| **F1** (dev) | XAMPP-sem | Fastest | **1,84** | **63,9783** | **8020,59** | 71,8527 / 32,9727 | 5759,75 | **G1 21–32** | PASS | PASS (0/0/0) | **bc883f0a3aab** |
| **F2** (dev) | XAMPP-sem | Max Review (38 kandidat / 11 valid) | **20,17** | **63,9782** | **8020,58** | 71,8527 / 32,9727 | 5759,76 | G1 21–32 | PASS | PASS | **b72cb0f66569** |
| F14 (final) | XAMPP-sem | Fastest | 1,58 | 63,9783 | 8020,59 | 71,8527 / 32,9727 | 5759,75 | G1 21–32 | PASS | PASS | bc883f0a3aab |
| F14 (final) | Linux Apache + PHP-FPM | Fastest | 1,39 | 63,9783 | 8020,59 | 71,8527 / 32,9727 | 5759,75 | G1 21–32 | PASS | PASS | bc883f0a3aab |
| F10a KP72 = 0 (final dev) | XAMPP-sem | Fastest | 1,58 | 63,9783 | 8020,59 | 71,8527 | 5759,75 | G1 21–32 | PASS | PASS | bc883f0a3aab |

## Kesimpulan

- Golden gate **PASS**. Dispatch Fastest dan Maximum Review **identik bit-per-bit** (sig sama) dengan versi sebelum rilis ini.
- CP dan HR berada di dalam rentang target. STG PASS (gerbang `FASTEST_FIRST_FULLY_VALID` / `PASS` mencakup bukti STG).
- Babelan 5759,75–5759,76 MWh. G1 startup row 21–32.
- Expected golden tidak diubah.
- Perubahan rilis ini tidak menyentuh golden, karena:
  - probe Fixed Flow first hanya aktif bila Min PGN Flow gagal pada core run baseline (golden: PGN RT minimum 27,27 MMSCFD);
  - perpanjangan window dan konsolidasi pin patch B hanya aktif bila gas di bawah window sesudah seluruh lever (golden tidak memicunya);
  - pengecualian GTG Change Over hanya aktif bila Change Over enabled.
- F2 dijalankan pada source dev sebelum pengecualian GTG Change Over dan penghapusan baris debug (`getenv`-gated). Kedua perubahan itu tidak aktif untuk golden (Change Over off), dan core golden pada source final identik (CP 63,9783, sig sama).
