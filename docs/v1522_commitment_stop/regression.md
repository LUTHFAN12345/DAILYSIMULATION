| Kasus | Deskripsi | Hasil | CP | Status | G9 |
|---|---|---|---|---|---|
| A1 | G9 Stop + Start Based On Simulation + Stop Status Continuous | PASS | 73.1462 | CONVERGED | 05:30-00:00 |
| A2s | G9 Stop + Sim + Stop-or-Continuous; malam ringan (stop menguntungkan) | PASS | 74.0977 | CONVERGED | 05:00-00:00 |
| A2c | G9 Stop + Sim + Stop-or-Continuous; malam berat (continuous menguntungkan) | PASS | 73.9168 | CONVERGED | 06:00-00:00 |
| A3 | G9 Stop + Sim + Stop Based On Simulation (wajib stop) | PASS | 73.9957 | ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET | 06:00-20:00 |
| A4 | G9 Stop + Sim + Hold; sistem feasible tanpa G9 (IE rendah sepanjang hari) | PASS | 71.2969 | CONVERGED | 05:30-00:00 |
| A5 | G9 Stop + Sim + Hold; profil IE berbeda (G9 dibutuhkan lebih awal) | PASS | 72.8377 | CONVERGED | 01:30-00:00 |
| B1 | G9 Stop + Start Based On Request 04:00 + Stop Status Continuous | PASS | 73.7268 | FASTEST_FIRST_VALID | 04:30-00:00 |
| B2 | G9 Stop + Request 04:00 + Stop Based On Simulation (wajib stop) | PASS | 74.856 | FASTEST_FIRST_VALID | 04:30-22:00 |
| B3 | G9 Stop + Request 04:00 + Stop-or-Continuous | PASS | 73.2225 | FASTEST_FIRST_VALID | 04:30-00:00 |
| C1 | G9 Initial Running + Stop Status Continuous (tanpa Commitment) | PASS | 72.1756 | FASTEST_FIRST_VALID | 00:30-00:00 |
| C2 | G9 Initial Running + Stop Based On Simulation (wajib stop) | PASS | 72.0672 | FASTEST_FIRST_VALID | 00:30-20:00 |
| F1 | Block 3 Required ON, tanpa unit Continuous di blok (G8/G9 tanpa Commitment) | PASS | 72.4847 | FASTEST_FIRST_VALID | 01:00-00:00 |
| F2 | Block 1 Required OFF + token legacy "required" di block_priority; G6 tanpa Commitment | PASS | 80.4837 | CONVERGED | 01:00-00:00 |
| F3 | Block 3 Required ON + seluruh GTG blok Stop All Day (konflik input) | PASS | 81.0106 | CERTIFIED_INFEASIBLE | OFF |
| A6 | G4 Stop + Commitment "-" + Stop Status Continuous (tidak wajib start; bila start, hold) | PASS | 73.2179 | CONVERGED | 05:30-00:00 |
| A6x | kontrol: G4 Stop + Request 07:30 + hold | PASS | 73.2624 | CONVERGED | 05:00-00:00 |
| A6s | kontrol: G4 Stop + Sim + hold | PASS | 73.4767 | CONVERGED | 05:30-00:00 |
| E1 | Matriks: G3 (GTG Block 1) Stop + Sim + Hold | PASS | 73.0934 | CONVERGED | 05:00-00:00 |
| E2 | Matriks: G7 (standby SC) Stop + Sim + Hold | PASS | 74.4726 | CONVERGED | 05:00-00:00 |
| F5 | Block 3 Required ON + G8 tanpa Commitment (Stop) + G9 Sim + Stop Status Continuous | PASS | 72.4164 | CONVERGED | 05:30-00:00 |
| E3 | Matriks: G1 (GTG Block 2) Stop + Sim + Hold | PASS | 73.2503 | CONVERGED | 05:00-00:00 |
| E4 | Matriks: G5 (GTG Block 2) Stop + Sim + Hold | PASS | 73.1309 | CONVERGED | 05:30-00:00 |
| E5 | Matriks: GE1 Stop + Sim + Hold tanpa sumber gas MM2100 legal (konflik input -> wajib ditolak) | PASS | 73.1555 | FASTEST_FIRST_VALID | 01:00-00:00 |
| E6 | Matriks: G3 Stop + Commitment "-" + Stop Status Continuous (hold-only, GTG Block 1) | PASS | 73.5274 | CONVERGED | 05:30-00:00 |
| E7 | Matriks: G3 Stop + Sim + Stop-or-Continuous (Stop Allowed) | PASS | 73.2311 | CONVERGED | 05:30-00:00 |
| E8 | Matriks: G8 Initial Running + Stop Based On Simulation (wajib stop) | REJECTED (konflik input terbukti: NO_FEASIBLE_STOP) | 73.3683 | FASTEST_FIRST_VALID | 01:00-00:00 |
| E9 | Matriks: G8 Stop + Sim + Hold (konfigurasi G9 dipindah ke G8; G9 tanpa Commitment) | PASS | 73.1555 | CONVERGED | 00:30-00:00 |
| E10 | Matriks: G5 Stop + Request 06:00 + Hold | PASS | 73.1314 | CONVERGED | 05:30-00:00 |
| P1 | Unit Priority ditukar G9 > G8 (Block 3), G9 Stop + Sim + Hold | PASS | 73.1462 | CONVERGED | 05:30-00:00 |
| P2 | Unit Priority ditukar G1 > G2 (Block 2), G1 Stop + Sim + Hold | PASS | 73.0618 | CONVERGED | 05:30-00:00 |
| F7 | Block 2 Required ON + G1 Stop + Sim + Hold (G2 Continuous sudah memenuhi blok) | PASS | 73.2503 | CONVERGED | 05:00-00:00 |
| D1 | Gas shortage -> Use Distillate, G9 Stop + Sim + Hold | PASS | 93.6813 | CONVERGED | 05:00-00:00 |
| F4 | G4 Commitment = Unit Continuous Running (Block Required OFF) | PASS | 73.3894 | CONVERGED | 05:30-00:00 |
| F6 | Block 3 Required ON + G8 Unit Continuous Running (G9 Sim+Hold) | PASS | 73.1462 | CONVERGED | 05:30-00:00 |
