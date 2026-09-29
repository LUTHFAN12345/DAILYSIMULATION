# Audit UI dan candidate counter V11

Sumber: `TL_UI_V11.md`, `WB09_3_UI.md` (browser nyata, backend nyata) dan `V11_CASES.md` (R04) dari folder uji; output reproducer WB09_3.

## SUMMARY kuning (lima field)

`#tl-banner` hanya berisi: Target waktu | Waktu aktual | Kandidat diperiksa | Kandidat valid | Cost Production. Status constraints, Global optimum proven, Total Cost, 48 rows/Export/residual/Unit Priority, ruang kandidat dan lima audit V11 ditampilkan di panel `#tl-audit` ("Detail audit"). Jalur yang diuji: Target < 15, < 25, < 35, < 45, < 55, < 60, Maximum Review, VALID PROVISIONAL, exact final, no valid result, Gas Shortage (popup, LNG, Distillate, campuran), rerun bahan bakar.

- `TL_UI`: 20/20 PASS
- `WB09_3_UI`: 5/5 PASS
- `PROV_UI_PGN1`: 6/6 PASS
- `TL_NOVALID`: lihat berkas
- `GAS_SHORTAGE_PGN25`: 9/9 PASS
- `GAS_SHORTAGE_PGN20`: 9/9 PASS
- `TARGETED12`: 57/57 PASS

## Counter kandidat (satu sumber, kunci fisik kanonik) — reproducer WB09_3

| field | nilai |
|---|---|
| candidates_checked | 29 |
| candidates_full_run | 25 |
| candidates_screened_out | 4 |
| screened_also_full_run_excluded | 1 |
| candidates_valid | 14 |
| best_candidate | FINAL |
| best_candidate_in_valid | True |
| constraints_pass | True |
| cost_production | 63.4469 |

Invarian: {"best_implies_valid_ge_1": true, "valid_le_checked": true}

Definisi: checked = full_run (dispatch 48 row unik yang disimulasikan penuh) + screened_out (gugur Tier 1); valid = dispatch unik yang lolos hard constraints + provenance dan eligible dibandingkan CP; best_candidate termasuk valid

Kunci: canonical physical dispatch (48 row GTG MW) untuk full-run/valid; commitment fisik kanonik (interval OFF GTG) untuk screened-out

### Sapuan konsolidasi (dihitung terpisah dari counter di atas)

| dibangkitkan | gugur Tier 1 | full-run | valid | row fragmentation | wall |
|---|---|---|---|---|---|
| 5 | 4 | 1 | 1 | 5 | 0.077 s |

| kandidat | jenis | unit | tier | varian | hasil | CP | Heat Rate |
|---|---|---|---|---|---|---|---|
| DECOMMIT:G5:27-38 | DECOMMIT | G5 | 1 | - | DIPANGKAS_TIER1:EXPORT | - | - |
| SWAP:G5>G2:27-38 | SWAP | G5 | 2 | SUSUN | VALID | 63.4469 | 8109.13 |
| SWAP:G5>G3:27-38 | SWAP | G5 | 1 | - | DIPANGKAS_TIER1:KEHILANGAN_UAP_COMBINED_CYCLE | - | - |
| SWAP:G5>G4:27-38 | SWAP | G5 | 1 | - | DIPANGKAS_TIER1:KEHILANGAN_UAP_COMBINED_CYCLE | - | - |
| SWAP:G5>G6:27-38 | SWAP | G5 | 1 | - | DIPANGKAS_TIER1:KEHILANGAN_UAP_COMBINED_CYCLE | - | - |
