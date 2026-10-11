# Actual Gas — Incremental Rerun (Addendum §6, R3)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — context hash memisahkan actual gas dari struktur; row actual yang berubah + jendela maju sampai 00:00 di-redispatch, commitment basis dipertahankan |
| Validation | **PASS** untuk A0, A1, A3, A5, A7; A2/A4/A6 **VALID-INFEASIBLE** (input tidak layak, lihat bukti) |
| Quality | PASS — CP warm ≈ cold (A0 kembali 64,6652 vs cold 64,6663) |
| Performance | **PASS** — warm 1,5–3,4 s (target < 5 s; A5 turun dari 11,4 s); cold 14,5 s (≤ 15 s) |
| Release blocker | Tidak ada (A2/A4/A6 = state input infeasible, dibuktikan juga oleh Maximum Review) |

## Hasil (CLI, rantai warm berurutan)
| Kasus | Wall (s) | Hard | Gate | CP | HR | C1–C4 FAIL | STG | Kontinuitas Dist. | Cache warm |
|---|---|---|---|---|---|---|---|---|---|
| A0 basis (cold, lalu kembali di akhir rantai) | 1.47 | PASS | PASS | 64.6652 | 8386.83 | 0 | PASS | PASS | HIT |
| A1 Actual PGN row 13 | 1.5 | PASS | PASS | 64.7077 | 8389.74 | 0 | PASS | PASS | HIT |
| A3 Actual MM2100 row 13 | 1.56 | PASS | PASS | 64.6662 | 8387.03 | 0 | PASS | PASS | HIT |
| A7 actual di bawah estimasi | 1.31 | PASS | PASS | 64.2399 | 8359.24 | 0 | PASS | PASS | HIT |
| A5 multi row | 3 | PASS | PASS | 64.8157 | 8396.95 | 0 | PASS | PASS | HIT |
| A2 Actual Fixed Flow JBBK row 13 | 11.55 | VALID-INFEASIBLE | FAIL | 64.5684 | 8394.83 | 0 | PASS | PASS | HIT |
| A4 semua akun row 13 | 11.14 | VALID-INFEASIBLE | FAIL | 64.5964 | 8394.81 | 0 | PASS | PASS | HIT |
| A6 actual di atas kuota | 12.64 | VALID-INFEASIBLE | FAIL | 65.0476 | 8394.83 | 0 | PASS | PASS | HIT |

## Bukti A2/A4/A6
- Fastest dan **Maximum Review** (87 s) sama-sama tidak menemukan rencana valid; window gas tidak dapat mendarat.
- Envelope identitas akunting engine (`pp_v7_gas_envelope`): A2 D ∈ [−0,138; −0,055], A4 D ∈ [−0,147; −0,064] → irisan window pipe [Pq−0,04; Pq] dan window total butuh D ≥ −0,04: **tidak beririsan** secara identitas, tetapi selisihnya (0,015–0,024 BBTUD) di bawah margin sertifikat 0,05 — sertifikat terminal tidak diterbitkan (margin tidak dilemahkan). A6 (actual di atas kuota) D ∈ [−0,038; 0,045] beririsan; kegagalannya adalah kelebihan pemakaian jam aktual yang tidak dapat dikompensasi row masa depan di atas beban minimum.
- Keterbatasan diketahui: pada state infeasible ini Maximum Review menerbitkan rencana jangkar kanonik (tanpa actual) dengan gate FAIL — lihat NEXT_STEPS.
