# FASTEST_CRITICAL_PATH

Pengukuran browser nyata (Chromium, klik Run → 48 row FINAL tampil). Server XAMPP-semantik: proksi + 6 backend PHP 7.4, 4 vCPU. Source final V15.16. Data mentah: `harness/ab_golden_multiuser/runs/racc3/` (Fastest) dan `runs/racc2/` (Maximum Review).

## Jalur kritis Fastest (golden R0, cold)
| Tahap | Mulai (s) | Durasi (s) | Catatan |
|---|---|---|---|
| Request Run + autosave + job handoff | 0 | ~0,6 | `mode=run` → job `economic_review` + 3 helper |
| Pipeline exact Fastest (core run, mandatory stop, decommit + prasaring export, koreksi window gas) | ~0,6 | ~3,5 | prasaring export menolak stop-window yang mustahil tanpa core run |
| Review Unit Priority V8 (bounded race) | ~4,2 | ≤ 7,3 | anggaran = 11,5 s − waktu job yang sudah terpakai; maksimum 4 kandidat, 1 ronde. Golden: polish lanjutan (80.2504 → 79.8818), lalu kandidat pita HR V11 `STOP:G5:14-37:@36` (→ CP 79.9299, HR 8239.87) |
| Perbaikan Unit Priority C1–C4 | ~10,6–11,5 | ~0,5–0,8 | 1 temuan sisa dibuktikan counterfactual (row 17 G5→G9: export_range) |
| Render 48 row + gerbang rilis | — | ~0,3 | — |
| **Total** | | **11,08 / 12,22 / 13,23 s** | median 12,22 s |

Sebelum perbaikan:
- 92263fb: 15,5 s, CP 82.10.
- Anggaran V8 tetap 10 s: 13,06–13,49 s, CP sama.

## Rerun sesudah popup bahan bakar (LNG / Distillate accepted)
- Popup muncul pada 2,6–3,3 s (normal) atau 6,9–7,2 s (PV-on).
- Job rerun memakai anggaran V8 `PP_V8_FAST_TOTAL_FUEL` 8,5 s. Waktu sebelum popup sudah terpakai, sehingga total dari klik Run tetap ≤ 15 s.
- Dengan anggaran tetap 10 s, R6 PEP37 = 15,30 s, P11 = 15,43 s, dan Linux R2 = 15,67 s, semuanya melewati target. Kini 13,21–13,77 s.

## Actual Gas (R5)
- Pipeline panjang, sekitar 10,5 s: decommit menemukan G3 yang tidak perlu (6 kandidat stop-window karena baseline actual-gas infeasible).
- Dengan anggaran tetap 10 s, V8 masih berjalan penuh sesudahnya: total 21,4–22,0 s.
- Anggaran adaptif menyisakan minimum 2 s: total 12,99–13,46 s, CP sama (64.6663, V8 tidak menemukan kandidat lebih baik).
