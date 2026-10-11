# FINAL RELEASE MATRIX — V15.17

Source beku: lihat `CHECKPOINT.md` (SHA256). Status dipisah: Functional / Validation / Quality / Performance / Release blocker.

## Gate Addendum §12 (+ Addendum 2: Maximum Review ≤ 80 s)
| Blocker | Status | Bukti |
|---|---|---|
| Distillate → gas → Distillate tanpa bukti | TIDAK TERJADI | audit kontinuitas PASS di seluruh kasus; celah blok selalu dengan bukti row tidak eligible |
| Distillate pada unit 5 MW | TIDAK TERJADI | aturan eligibility > 5 MW + validator |
| Remark duplikat di bawah hasil | TIDAK ADA | #run-msg 1 baris (desktop/fullscreen/mobile): [1, 1, 1] |
| PGN25 fixture > 30 s | TIDAK | browser 13.076 s, CLI 13,6 s |
| Actual Gas warm > 5 s tanpa bukti | TIDAK | warm 1,3–3,0 s (A1/A3/A5/A7/A0) |
| IE Adjustment jendela kecil warm > 5 s tanpa bukti | TIDAK | engine warm IE3 4,27 s / IE2 4,27 s / IE4 3,62 s; IE1 commitment repair dengan bukti export_range |
| IE Adjustment tidak mengubah dispatch | TIDAK | IE3: 9 row dispatch berubah, CP 79,9049 → 80,6838; IE8: commitment G4/G5 berubah |
| Fixed Flow total berubah | TIDAK | R2 Σ Fixed Flow 1920 = 1920 |
| Min Flow PGN gagal setelah koreksi legal | TIDAK (26) / terminal terbukti (45) | Min Flow 45 envelope tidak cukup — sertifikat |
| Stop Status semantics salah | TIDAK | SR1–SR6 PASS; sinkronisasi UI PASS |
| Unit Continuous Running dapat stop | TIDAK | SR5 G5 2–48; validator cannot_stop |
| Stop Request 16:00 masih berbeban pada 16:00 | TIDAK | SR1 row 16:00 = 0 MW |
| Change Over satu arah belum PASS | TIDAK | CO0–CO7 8/8 PASS CLI; CO0 & CO1 PASS lewat UI |
| Follow PV / Fix SR / SR Effective hilang | TIDAK | IE9/IE10/CO2/CO3/P10 PASS; fitur di UI |
| Fastest / Maximum Review tertukar | TIDAK | label run-msg & Run Status konsisten (Fastest - Default / Maximum Review) |
| CP/HR regress tanpa bukti | TIDAK | golden CP 79,9049 < V15.16 79,9299; HR 8239,87 sama |
| Multi-user isolation gagal | TIDAK | conc C0–C6, c5, supersede, IE17 PASS |
| Hard / C1–C4 / STG gagal | TIDAK | skenario S 20/23, IE 7/7, CO 8/8 gate PASS; sisanya sertifikat terminal |
| Maximum Review > 80 s (Addendum 2) | TIDAK | golden Max browser 26.395 s / dua langkah 27.095 s |

## R0–R12
| Tahap | Functional | Validation | Quality | Performance | Laporan |
|---|---|---|---|---|---|
| R0 Distillate continuity | PASS | PASS | PASS (CP 79,9049) | PASS | DISTILLATE_CONTINUITY_AUDIT.md |
| R1 PGN25/LNG18/PEP36/Akasia4 | PASS | PASS | PASS | PASS (13.076 s browser) | PGN25_LNG18_PERFORMANCE.md |
| R2 Min Flow PGN | PASS | PASS / terminal terbukti | PASS | PASS | MIN_PGN_FLOW_FIXED_FLOW_REDISTRIBUTION.md |
| R3 Actual Gas incremental | PASS | PASS (A2/A4/A6 input infeasible) | PASS | PASS (warm ≤ 3,0 s) | ACTUAL_GAS_INCREMENTAL_RERUN.md |
| R3A IE Adjustment | PASS | PASS | PASS | PASS (engine warm 3,6–4,3 s); browser klik→FINAL 6,1–9,2 s dicatat | IE_ADJUSTMENT_INCREMENTAL_REDISPATCH.md |
| R4 Stop Status | PASS | PASS | PASS | PASS | STOP_STATUS_SEMANTICS.md |
| R5 one-off / one-trip | PASS | S2/S3 PASS; S1/S6 sertifikat terminal row 1 | PASS | PASS | tabel skenario di bawah |
| R6 supplier ±1 | PASS | PASS (S7–S17) | PASS | PASS (≤ 12,4 s dua langkah) | tabel skenario |
| R7 Change Over dua arah | PASS | PASS | PASS | PASS | CHANGEOVER_BIDIRECTIONAL.md |
| R8 Follow PV / Fix SR / SR Effective | PASS | PASS | PASS | PASS (kasus sulit ≤ 30 s) | IE9/IE10/CO2/CO3/P10 |
| R9 remark UI | PASS | – | – | – | `#run-msg` 1 baris; screenshot desktop/fullscreen/mobile/error |
| R10 golden Fastest / Maximum Review | PASS | PASS | PASS | PASS | tabel browser |
| R11 concurrency + save/reload | PASS | PASS | – | PASS | conc/c5/supersede/IE17 |
| R12 XAMPP/Linux parity | PASS — CP identik di seluruh kasus; waktu Linux ±0–1,6 s dari XAMPP-like | | | | tabel browser (kolom Linux) |

## Browser (UI nyata) — XAMPP-like (6 worker PHP 7.4) dan Linux (Apache 2.4 + PHP-FPM 7.4)
| Kasus | Klik→FINAL (s) | Klik→popup (s) | Gate | CP | HR | Merit C1–C4/STG | Mode | Linux klik→FINAL (s) | Linux gate | Linux CP | Linux merit |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Golden Fastest (P0, rekomendasi → Distillate) | 12.821 | 3.531 | PASS | 79.9049 | 8239.87 | PASS | FASTEST_V7_FUEL_FROM_BASIS | 12.06 | PASS | 79.9049 | PASS |
| Golden Maximum Review (P0, dua langkah) | 27.095 | 8.567 | PASS | 79.9049 | 8239.87 | PASS | V7_FUEL_RERUN_DELTA | 28.696 | PASS | 79.9049 | PASS |
| Golden Maximum Review (GOLDEN_dist, satu langkah) | 26.395 | – | PASS | 79.9049 | 8239.87 | PASS | V7_FUEL_RERUN_DELTA | 26.055 | PASS | 79.9049 | PASS |
| PGN25/LNG18/PEP36/Akasia4 | 13.076 | – | PASS | 71.7784 | 8173.52 | PASS | FASTEST_PIPELINE | 14.293 | PASS | 71.7784 | PASS |
| Follow PV (P10) | 15.345 | 6.543 | PASS | 74.933 | 8549.41 | PASS | FASTEST_V7_FUEL_FROM_BASIS | 16.337 | PASS | 74.933 | PASS |
| Change Over B2→B1 (CO1) | 3.026 | – | PASS | 64.5875 | 8369.12 | PASS | sim/sim (bounded timeline optimisation) | 3.016 | PASS | 64.5875 | PASS |
| Change Over B1→B2 + LNG (CO0) | 24.737 | 22.66 | PASS | 65.1851 | 8475.31 | PASS | sim/sim (bounded timeline optimisation) | 24.751 | PASS | 65.1851 | PASS |
| Actual Gas basis (R5) | 13.079 | – | PASS | 64.6663 | 8387.22 | PASS | FASTEST_PIPELINE | 14.067 | PASS | 64.6663 | PASS |
| Terminal row 1 (P12 G8 unavailable) | 2.0 | – | FAIL (TERMINAL_INFEASIBLE_EXPORT_ROW_1, bukti tampil) |  |  | – | FASTEST_TERMINAL_CERTIFICATE | – | – | – | – |

Rantai IE warm lewat UI: IE0 12.184 s (job 7.007 s, MISS, CP 79.9049), IE3 8.093 s (job 3.954 s, HIT, CP 80.6848), IE2 9.027 s (job 4.48 s, HIT, CP 79.8578), IE4 9.157 s (job 4.619 s, HIT, CP 78.5297), IE6 9.097 s (job 4.499 s, HIT, CP 79.7102), IE0 6.141 s (job 3.766 s, HIT, CP 79.3634).

IE16 UI: gate PASS, Pred+Adj=Eff True, Excel kolom IE True, reload aturan 3. IE17: PASS.

Konkurensi: conc.js →
```
after reload."}
A_after_B_save {"timer":{"state":"MENUNGGU_KEPUTUSAN_BAHAN_BAKAR","rid":"plan-1-mv33y7kv","elapsed":18.862600000000093,"running_interval":false},"rid":"plan-1-mv33y7kv","msg":"Done. Perhitungan eksak selesai — Anda tidak perlu menjalankan ulang.\nHasil BELUM final. Kebutuhan gas masih melebihi kuota sebesar 3,4191
A_done {"rows":48,"cp":71.7739,"gate":"PASS","rid":"plan-2-mv33ymrn","s":59.3,"timeout":false}
C4_simultaneous_save {"A":"Input saved — will persist after reload.","B":"Input saved — will persist after reload."}
records {"A":[["SAVE",3,"td67eb8df3accdeb4","p_12bc23195a4987dc"],["SIMULATION_FINAL",2,"td67eb8df3accdeb4","p_12bc23195a4987dc"],["AUTOSAVE_RUN",1,"td67eb8df3accdeb4","p_12bc23195a4987dc"]],"B":[["SAVE",4,"t57144a47997d8844","p_12bc23195a4987dc"],["SAVE",3,"t57144a47997d8844","p_12bc23195a4987dc"],["SIMULA
A_cannot_load_B_record {"ok":false,"err":"NOT_FOUND_OR_NOT_OWNED"}
C6_reload {"A_ie0":"471","B_ie0":"500","expect":{"A":"471","B":"500"}}
C0_tabs {"uid_same":true,"tab_diff":true}
C0_tab_record {"tab_of_latest":"t115dbf768196e0c4","tabA2":"t115dbf768196e0c4"}
C5_B_cancel {"job":"economic_review-61f890b0f3579b6d959d","resp":{"ok":true,"job":{"schema":"co12-async-job-v1","job_id":"economic_review-61f890b0f3579b6d959d","kind":"economic_review","input_hash":"61f890b0f3579b6d959dc786e329f61a6512807e69cc56c3448d35716cd6e715","request_id":"plan-2-mv3403yh","status":"CANCEL
C5_A_after_B_cancel {"rows":48,"cp":71.7739,"gate":"PASS","timeout":false}
```
c5.js (2× Maximum Review bersamaan + cancel) →
```
00 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_poll:200 job_exec:200 job_help:200 job_help:200 job_help:200 job_poll:200
```
Supersede lintas user →
```
S1_B_then_A_clicked {}
S1_jobs [["economic_review-0a06407d4f","DONE",null,null],["economic_review-d1984e1492","DONE",null,null]]
S1_B_done {"rows":48,"cp":79.9877,"s":11.2,"timeout":false}
S1_A_done {"rows":48,"cp":71.9048,"s":12.3,"timeout":false}
S2_own_old_job {"rid":"plan-3-mv33xi9e"}
S2_A_new_done {"rows":48,"cp":71.9346,"s":2.4,"timeout":false}
```

## Matriks skenario (CLI, alur dua langkah seperti UI) — 38 kasus
| Kasus | Bahan bakar | Langkah 1 (s) | Langkah 2 (s) | Total (s) | Hard | Gate | CP | HR | C1–C4 FAIL | STG | Kontinuitas Dist. | Blocker |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CO0_b1_to_b2 | add_lng | 7.48 | 2.27 | 9.75 | PASS | PASS | 65.1851 | 8475.31 | 0 | PASS | PASS | – |
| CO1_b2_to_b1 | – | 1.78 | – | 1.78 | PASS | PASS | 64.5875 | 8369.12 | 0 | PASS | PASS | – |
| CO2_pv_off | – | 1.79 | – | 1.79 | PASS | PASS | 64.5875 | 8369.12 | 0 | PASS | PASS | – |
| CO3_pv_on | add_lng | 5.3 | 5.19 | 10.49 | PASS | PASS | 65.1609 | 8456.33 | 0 | PASS | PASS | – |
| CO4_gas_shortage | add_lng | 3.8 | 1.89 | 5.69 | PASS | PASS | 65.5629 | 8388.01 | 0 | PASS | PASS | – |
| CO5_trip_g3 | – | 1.84 | – | 1.84 | PASS | PASS | 64.5875 | 8369.12 | 0 | PASS | PASS | – |
| CO6_stop_request_g5 | – | 1.96 | – | 1.96 | PASS | PASS | 64.5875 | 8369.12 | 0 | PASS | PASS | – |
| CO7_continuous_g9 | – | 1.7 | – | 1.7 | PASS | PASS | 64.5875 | 8369.12 | 0 | PASS | PASS | – |
| IE9_pv_on | add_lng | 7.85 | 15.9 | 23.75 | PASS | PASS | 74.9314 | 8537.36 | 0 | PASS | PASS | – |
| IE10_fix_sr15 | add_lng | 7.22 | 7.28 | 14.51 | PASS | PASS | 75.3219 | 8587.68 | 0 | PASS | PASS | – |
| IE11_actual_gas | – | 15.02 | – | 15.02 | PASS | PASS | 64.3643 | 8374.01 | 0 | PASS | PASS | – |
| IE12_shortage_lng | add_lng | 2.59 | 9.24 | 11.83 | PASS | PASS | 71.929 | 8256.98 | 0 | PASS | PASS | – |
| IE13_distillate | use_distillate | 2.78 | 8.64 | 11.41 | PASS | PASS | 80.6967 | 8235.88 | 0 | PASS | PASS | – |
| IE14_trip | add_lng | 2.41 | 9.12 | 11.53 | PASS | PASS | 72.4242 | 8307.04 | 0 | PASS | PASS | – |
| IE15_change_over | add_lng | 3.92 | 2.0 | 5.92 | PASS | PASS | 64.9648 | 8416.22 | 0 | PASS | PASS | – |
| S0_normal | add_lng | 2.63 | 9.25 | 11.88 | PASS | PASS | 71.8498 | 8258.58 | 0 | PASS | PASS | – |
| S1_g2_initially_off | – | 1.1 | – | 1.1 | VALID-INFEASIBLE | FAIL | 68.272 | 8575.72 | 91 | FAIL | PASS | TERMINAL_INFEASIBLE_EXPORT_ROW_1 |
| S2_g5_trip_unavail | add_lng | 2.33 | 8.98 | 11.31 | PASS | PASS | 72.3733 | 8312.1 | 0 | PASS | PASS | – |
| S3_g8_trip_1200 | add_lng | 3.39 | 9.23 | 12.62 | PASS | PASS | 71.8498 | 8258.58 | 0 | PASS | PASS | – |
| S4_g1_required_0800 | add_lng | 3.91 | 7.21 | 11.11 | PASS | PASS | 72.4428 | 8319.01 | 0 | PASS | PASS | – |
| S5_g5_continuous | add_lng | 2.33 | 6.81 | 9.14 | PASS | PASS | 72.4575 | 8321.36 | 0 | PASS | PASS | – |
| S6_oneoff_onetrip | – | 0.83 | – | 0.83 | VALID-INFEASIBLE | FAIL | 68.663 | 8459.15 | 89 | FAIL | PASS | TERMINAL_INFEASIBLE_EXPORT_ROW_1 |
| S7_pgn_p1 | add_lng | 2.85 | 9.14 | 11.98 | PASS | PASS | 71.516 | 8258.58 | 0 | PASS | PASS | – |
| S8_pgn_m1 | add_lng | 2.74 | 9.23 | 11.97 | PASS | PASS | 72.1837 | 8258.58 | 0 | PASS | PASS | – |
| S9_pep_p1 | add_lng | 2.45 | 9.01 | 11.46 | PASS | PASS | 71.3886 | 8258.58 | 0 | PASS | PASS | – |
| S10_pep_m1 | add_lng | 2.55 | 9.21 | 11.76 | PASS | PASS | 72.311 | 8258.58 | 0 | PASS | PASS | – |
| S11_akasia_p1 | add_lng | 2.65 | 9.0 | 11.65 | PASS | PASS | 71.6435 | 8258.58 | 0 | PASS | PASS | – |
| S12_akasia_m1 | add_lng | 2.89 | 9.2 | 12.09 | PASS | PASS | 72.0561 | 8258.58 | 0 | PASS | PASS | – |
| S13_lng_p1 | add_lng | 2.75 | 9.17 | 11.92 | PASS | PASS | 71.8498 | 8258.58 | 0 | PASS | PASS | – |
| S14_lng_m1 | add_lng | 2.82 | 9.14 | 11.96 | PASS | PASS | 71.8498 | 8258.58 | 0 | PASS | PASS | – |
| S15_ffjbbk_p1_bbg | add_lng | 2.63 | 9.22 | 11.85 | PASS | PASS | 70.7597 | 8258.58 | 0 | PASS | PASS | – |
| S16_ffjbbk_m1_pep | add_lng | 2.74 | 8.98 | 11.72 | PASS | PASS | 72.311 | 8258.58 | 0 | PASS | PASS | – |
| S17_kp72_p1 | add_lng | 2.81 | 8.02 | 10.83 | PASS | PASS | 71.7872 | 8262.84 | 0 | PASS | PASS | – |
| S19_shortage_ringan | add_lng | 2.76 | 9.6 | 12.37 | PASS | PASS | 72.1837 | 8258.58 | 0 | PASS | PASS | – |
| S20_shortage_sedang | add_lng | 2.81 | 9.19 | 12.0 | PASS | PASS | 72.8514 | 8258.58 | 0 | PASS | PASS | – |
| S21_shortage_berat_lng | add_lng | 2.75 | 9.22 | 11.98 | PASS | PASS | 73.853 | 8258.58 | 0 | PASS | PASS | – |
| S22_shortage_distillate | use_distillate | 2.65 | 7.39 | 10.04 | PASS | PASS | 88.1996 | 8239.87 | 0 | PASS | PASS | – |
| S23_terminal_infeasible | – | 1.13 | – | 1.13 | VALID-INFEASIBLE | FAIL | 71.3909 | 8553.8 | 88 | PASS | PASS | TERMINAL_INFEASIBLE_EXPORT_ROW_1 |

Kasus tidak PASS: S1_g2_initially_off:TERMINAL_INFEASIBLE_EXPORT_ROW_1, S23_terminal_infeasible:TERMINAL_INFEASIBLE_EXPORT_ROW_1, S6_oneoff_onetrip:TERMINAL_INFEASIBLE_EXPORT_ROW_1 — seluruhnya `TERMINAL_INFEASIBLE_EXPORT_ROW_1` dengan bukti fisik (unit Last Data Running maksimum → Export maks 13,72 MW < Range Min 15 MW, defisit 1,28 MW; unit Stop paling cepat berbeban di row 2).
