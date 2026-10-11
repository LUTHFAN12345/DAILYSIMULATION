# IE Adjustment — Incremental Redispatch (Addendum §6A, R3A)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — IE_EFFECTIVE = IE_PREDICTION + IE_ADJUSTMENT diterapkan sekali, sumber tunggal `pp_ie_series()`; label row inklusif (00:30 = row 1, 00:00 = row 48); chart, payload, worker, Simulation Data, Summary, Save/Reload, Excel konsisten (IE16) |
| Validation | **PASS** — IE0–IE17 hard PASS & gate PASS kecuali tidak ada; C1–C4 FAIL = 0, STG PASS |
| Quality | **PASS** — CP warm ≤ cold atau dalam band 0,2 % (IE3 warm 80,6838 vs cold 80,6967; IE6 browser warm 79,7102 vs cold 79,9362) |
| Performance | **PASS dengan catatan** — engine warm jendela kecil 3,6–4,3 s (< 5 s: IE2 4,27 · IE3 4,27 · IE4 3,62); multi-jendela ≤ 10 s (IE6 5,95 · IE5 6,64); commitment repair (IE1/IE7) ±11 s dengan bukti critical path; di browser job warm 3,8–4,6 s, klik→FINAL 6,1–9,2 s karena langkah rekomendasi wajib (±2,5–3 s) menghitung ulang liter Distillate untuk IE baru |
| Release blocker | Tidak ada |

## Rantai data
UI tabel adjustment (`IEADJ_RULES`, kolom "Row") → payload `data3.modeling.ie_adjustments[{operator,value,start_period,stop_period}]` → `pp_apply_ie_adjustment` (label inklusif) → `pp_ie_series()` (pred/adj/eff) → worker net-load per row → rute warm `pp_v1517_ie_warm` (Stage A jendela ±2 row + polish; Stage B 48 row commitment tetap + pendaratan gas; fallback Fastest penuh dengan alasan) → validasi hard + gerbang → `data[].IE_Pred/IE_Adj/IE` → Summary/Excel/Save.

Basis warm: rencana **fully valid** terakhir dengan konteks fisik identik (key mengecualikan IE, jumlah bahan bakar, actual gas, dan metadata request UI `_*`); disimpan hanya setelah hard PASS + gate PASS. Penurunan IE ≥ min-load GTG terkecil (20 MW) → review commitment penuh (unit mungkin tidak perlu lagi). Tidak ada IE basi: `affected_rows` dihitung dari selisih IE efektif per row terhadap basis.

## IE0–IE8 — rantai warm CLI (berurutan, satu direktori job)
| Test | Rentang | Row adj | Pred+Adj=Eff | Jendela terdampak | Cache | Alasan invalidasi | First fully valid (s) | Total (s) | Hard | Gate | C1–C4 | STG | CP | HR | Gas used/kuota | Distillate (l) | Commitment sesudah |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| IE0 | semua nol — run kedua, kembali dari IE7 (run pertama cold 9,4 s, CP identik 79,9049) | – | OK | – | HIT | PENURUNAN_IE_DAPAT_MEMBUAT_UNIT_TIDAK_PERLU -> review commitment penuh | – | 5.52 | PASS | PASS | 0 | PASS | 79.9049 | 8239.87 | 79.1967/79.2 | 93439.7 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE3 | 10:00–12:00 +15 | 20–24 (5) | OK | [[18, 26]] | HIT | – | 1.151 | 4.27 | PASS | PASS | 0 | PASS | 80.6838 | 8234.08 | 79.1838/79.2 | 101851.8 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE4 | 12:30–17:30 −10 | 25–35 (11) | OK | [[18, 37]] | HIT | – | 1.124 | 3.62 | PASS | PASS | 0 | PASS | 78.8064 | 8246.91 | 79.1773/79.2 | 81470.3 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE5 | 18:00–00:00 +5 | 36–48 (13) | OK | [[23, 48]] | HIT | – | 1.13 | 6.64 | PASS | PASS | 0 | PASS | 80.1821 | 8232.88 | 79.185/79.2 | 96758.1 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE6 | IE3+IE4+IE5 | 20–48 (29) | OK | [[18, 37]] | HIT | – | 1.138 | 5.95 | PASS | PASS | 0 | PASS | 79.9449 | 8237.63 | 79.1993/79.2 | 94047.3 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE2 | 14:00 −10 (satu row) | 28–28 (1) | OK | [[18, 48]] | HIT | – | 1.151 | 4.27 | PASS | PASS | 0 | PASS | 79.5413 | 8238.68 | 79.1857/79.2 | 89701.3 | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE1 | 14:00 +15 (satu row) | 28–28 (1) | OK | [[26, 30]] | HIT | COMMITMENT_BASIS_TIDAK_FEASIBLE_UNTUK_IE_BARU -> commitment repair (Fa | – | 10.73 | PASS | PASS | 0 | PASS | 80.7998 | 8264.08 | 79.1997/79.2 | 100814.7 | {"G2": "1-48", "G3": "21-32", "G4": "15-28", "G5": "14-37", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE7 | 14:00–17:00 +40 (startup tambahan) | 28–34 (7) | OK | [[26, 36]] | HIT | COMMITMENT_BASIS_TIDAK_FEASIBLE_UNTUK_IE_BARU -> commitment repair (Fa | – | 10.54 | PASS | PASS | 0 | PASS | 84.3976 | 8288.63 | 79.1963/79.2 | 136400.4 | {"G2": "1-48", "G3": "21-33", "G4": "15-36", "G5": "14-37", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE8 | 07:00–17:00 −30 (first legal stop) | 14–34 (21) | OK | – | HIT | PENURUNAN_IE_DAPAT_MEMBUAT_UNIT_TIDAK_PERLU -> review commitment penuh | – | 11.85 | PASS | PASS | 0 | PASS | 72.7544 | 8248.77 | 79.1706/79.2 | 20708.8 | {"G2": "1-48", "G4": "26-37", "G5": "15-30", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |

Critical path:
- **IE1 (+15 MW satu row 14:00)**: basis warm tidak feasible — row 28 butuh 15 MW lebih, commitment basis tidak punya headroom (`export_range`) → commitment repair (G3 start 21–32) ±11 s. Ini bukan full search buta: alasan tercatat, dan hasil commitment baru PASS.
- **IE7 (+40 MW)**: startup tambahan wajib (G3), ±10,5 s.
- **IE8 (−30 MW 07:00–17:00)**: penurunan ≥ min-load → review commitment penuh (G4/G5 dipendekkan), ±12,3 s, CP turun ke 72,75.
- **IE5 (13 row)** diklasifikasikan jendela lebar (target ≤ 10 s); waktu didominasi perbaikan Unit Priority C1–C4 pada row yang di-redispatch (bukti per percobaan wajib).

## IE9–IE15 — kombinasi (CLI, alur dua langkah rekomendasi → bahan bakar)
| Test | Bahan bakar | Langkah 1 (s) | Langkah 2 (s) | Total (s) | Hard | Gate | CP | HR | C1–C4 | STG | Commitment |
|---|---|---|---|---|---|---|---|---|---|---|---|
| IE9_pv_on | add_lng | 7.85 | 15.9 | 23.75 | PASS | PASS | 74.9314 | 8537.36 | 0 | PASS | {"G2": "1-48", "G4": "15-34", "G5": "14-37", "G6": "1-48", "G7": "19-32", "G8": "1-48", "G9": "1-48"} |
| IE10_fix_sr15 | add_lng | 7.22 | 7.28 | 14.51 | PASS | PASS | 75.3219 | 8587.68 | 0 | PASS | {"G2": "1-48", "G4": "15-34", "G5": "14-46", "G6": "1-48", "G7": "20-32", "G8": "1-48", "G9": "1-48"} |
| IE11_actual_gas | – | 15.02 | – | 15.02 | PASS | PASS | 64.3643 | 8374.01 | 0 | PASS | {"G1": "1-48", "G5": "18-31", "G8": "1-48", "G9": "1-48"} |
| IE12_shortage_lng | add_lng | 2.59 | 9.24 | 11.83 | PASS | PASS | 71.929 | 8256.98 | 0 | PASS | {"G2": "1-48", "G4": "15-32", "G5": "14-37", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE13_distillate | use_distillate | 2.78 | 8.64 | 11.41 | PASS | PASS | 80.6967 | 8235.88 | 0 | PASS | {"G2": "1-48", "G4": "15-34", "G5": "14-35", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE14_trip | add_lng | 2.41 | 9.12 | 11.53 | PASS | PASS | 72.4242 | 8307.04 | 0 | PASS | {"G2": "1-48", "G3": "10-34", "G4": "14-37", "G6": "1-48", "G8": "1-48", "G9": "1-48"} |
| IE15_change_over | add_lng | 3.92 | 2.0 | 5.92 | PASS | PASS | 64.9648 | 8416.22 | 0 | PASS | {"G1": "1-31", "G4": "19-48", "G8": "1-48", "G9": "1-48"} |

IE11 (IE + Actual Gas) diperbaiki pada V15.17: pipeline Fastest berakhir dengan window gas UNDER; pendaratan gas pada commitment yang sama (`PP_FAST_GAS_LAND`) → PASS, CP 64,3643 (Max Review: 64,3773).

## IE16 — UI nyata (Save/Reload/Excel)
- Label row: ['row 20–24 (5 slot)', 'row 25–35 (11 slot)', 'row 36–48 (13 slot)']; chart `adjRows` = 29, garis IE Effective: True
- Payload: 3 aturan; hasil gate PASS, CP 79.9362; Pred+Adj=Eff pada 48 row: True; row 1 adj = 0, row 19 adj = 0
- Excel memuat kolom IE Prediction/Adjustment/Effective: True
- Save → Reload: aturan & label row pulih (['row 20–24 (5 slot)', 'row 25–35 (11 slot)', 'row 36–48 (13 slot)']).

## IE17 — konkurensi (user A mengubah IE saat user B Run)
A: gate PASS, CP 79.9617, 29 row adj, 17.2 s · B: gate PASS, CP 79.9049, 0 row adj, 10.4 s · UID berbeda, payload B tanpa aturan A · **PASS**

## Browser — rantai warm lewat UI (klik Run → FINAL)
| Kasus | Klik→FINAL (s) | Job warm (s) | First fully valid (s) | Cache | Gate | CP | HR | Row adj |
|---|---|---|---|---|---|---|---|---|
| IE0 | 12.184 | 7.007 | – | MISS | PASS | 79.9049 | 8239.87 | 0 |
| IE3 | 8.093 | 3.954 | 1.082 | HIT | PASS | 80.6848 | 8234.67 | 5 |
| IE2 | 9.027 | 4.48 | 1.178 | HIT | PASS | 79.8578 | 8238.69 | 1 |
| IE4 | 9.157 | 4.619 | 1.908 | HIT | PASS | 78.5297 | 8241.13 | 11 |
| IE6 | 9.097 | 4.499 | 1.001 | HIT | PASS | 79.7102 | 8232.39 | 29 |
| IE0 | 6.141 | 3.766 | 0.99 | HIT | PASS | 79.3634 | 8232.48 | 0 |

Setiap Run IE di UI = langkah rekomendasi (menghitung ulang kebutuhan Distillate untuk IE baru, ±2,5–3 s) + langkah bahan bakar (warm). Angka "Job warm" adalah langkah bahan bakar.
