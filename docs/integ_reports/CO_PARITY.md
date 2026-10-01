# Parity Change Over Block 1-2 — V12 checkpoint vs integrasi vs kandidat Copilot

Dua basis nyata (WB09_3 = Daily_Plan_09_Jul_26_Baru(3), WB09) x B2->B1 (sim/sim Cold/Warm/Hot, manual start/sim stop, sim start/manual stop, manual/manual) dan B1->B2 (sim/sim Cold/Warm/Hot, manual/manual). Payload persis format UI (block, last_status, gtg prioritas-1, stg, start_other/stop_other). Job HTTP, cold dari direktori job kosong.

| kasus | V12 gate | integrasi gate | Copilot gate | identik integrasi = V12 (dispatch/CP/HR/timeline) | Copilot = V12 | executed | GTG target row | STG target row | overlap (row) | STG sumber stop row | urutan wajib | CP V12 / integ / Copilot |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| WB09_3_B1B2_MAN0800_MAN1800 | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 63.9821 / 63.9821 / 63.9821 |
| WB09_3_B1B2_SIMSIM_COLD | PASS | PASS | PASS | YA | YA | True | 19 | 26 | 9 | 34 | PASS | 63.8636 / 63.8636 / 63.8636 |
| WB09_3_B1B2_SIMSIM_HOT | FAIL | FAIL | FAIL | YA | YA | False | 34 | 35 | 9 | 43 | - | 63.8699 / 63.8699 / 63.8699 |
| WB09_3_B1B2_SIMSIM_WARM | FAIL | FAIL | FAIL | YA | YA | False | 34 | 36 | 9 | 44 | - | 63.9912 / 63.9912 / 63.9912 |
| WB09_3_B2B1_MAN0800_MAN1800 | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 64.0633 / 64.0633 / 64.0633 |
| WB09_3_B2B1_MAN0800_SIMSTOP | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 20 | 43 | PASS | 64.4109 / 64.4109 / 64.4109 |
| WB09_3_B2B1_SIMSIM_COLD | PASS | PASS | PASS | YA | YA | True | 19 | 26 | 9 | 34 | PASS | 63.9304 / 63.9304 / 63.9304 |
| WB09_3_B2B1_SIMSIM_HOT | FAIL | FAIL | FAIL | YA | YA | False | 32 | 34 | 6 | 39 | - | 63.748 / 63.748 / 63.748 |
| WB09_3_B2B1_SIMSIM_WARM | FAIL | FAIL | FAIL | YA | YA | False | 32 | 34 | 7 | 40 | - | 63.7807 / 63.7807 / 63.7807 |
| WB09_3_B2B1_SIMSTART_MAN1800 | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 64.0633 / 64.0633 / 64.0633 |
| WB09_B1B2_MAN0800_MAN1800 | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 64.9684 / 64.9684 / 64.9684 |
| WB09_B1B2_SIMSIM_COLD | PASS | PASS | PASS | YA | YA | True | 19 | 26 | 9 | 34 | PASS | 64.8363 / 64.8363 / 64.8363 |
| WB09_B1B2_SIMSIM_HOT | FAIL | FAIL | FAIL | YA | YA | False | 32 | 33 | 7 | 39 | - | 64.6584 / 64.6584 / 64.6584 |
| WB09_B1B2_SIMSIM_WARM | FAIL | FAIL | FAIL | YA | YA | False | 34 | 36 | 9 | 44 | - | 64.8684 / 64.8684 / 64.8684 |
| WB09_B2B1_MAN0800_MAN1800 | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 64.967 / 64.967 / 64.967 |
| WB09_B2B1_MAN0800_SIMSTOP | PASS | PASS | PASS | YA | YA | True | 17 | 24 | 12 | 35 | PASS | 64.967 / 64.967 / 64.967 |
| WB09_B2B1_SIMSIM_COLD | PASS | PASS | PASS | YA | YA | True | 19 | 26 | 9 | 34 | PASS | 64.8233 / 64.8233 / 64.8233 |
| WB09_B2B1_SIMSIM_HOT | FAIL | FAIL | FAIL | YA | YA | False | 32 | 34 | 6 | 39 | - | 64.6608 / 64.6608 / 64.6608 |
| WB09_B2B1_SIMSIM_WARM | FAIL | FAIL | FAIL | YA | YA | False | 32 | 34 | 7 | 40 | - | 64.7124 / 64.7124 / 64.7124 |
| WB09_B2B1_SIMSTART_MAN1800 | PASS | PASS | PASS | YA | YA | True | 19 | 26 | 10 | 35 | PASS | 64.8723 / 64.8723 / 64.8723 |

**Paritas integrasi = V12 checkpoint: 20/20 kasus identik** (dispatch, CP, Heat Rate, gate, mode, executed, timeline start/stop/overlap).
Kasus PASS pada V12 checkpoint: 12; regresi ke HARD_VALIDATION_FAILED / COST_PRODUCTION_PROOF_FAILED pada integrasi: **0**.
Urutan wajib lulus pada seluruh kasus PASS integrasi: 12/12.
Kasus FAIL pada V12 checkpoint ditampilkan apa adanya (blocker fisik pasangan/hari itu, sama pada ketiga source) — bukan regresi integrasi.
