# Stop Status Semantics (Addendum §7, R4)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — dropdown: `-`, Stop Based On Simulation, Stop Based On Request, Stop Based On Simulation or Continuous Running, **Unit Continuous Running** |
| Validation | **PASS** — SR1–SR6 hard PASS, gate PASS; Unit Continuous Running tidak pernah 0 (validator `cannot_stop` / `commitment_continuous`) |
| Quality | PASS — CP terendah dari kandidat valid; Stop-or-Continuous membandingkan kedua family |
| Performance | PASS — 6,7–22,8 s CLI (SR6/D4 = kasus sulit start G3 + Distillate dua unit, ≤ 30 s) |
| Release blocker | Tidak ada |

## Enum kanonik & sinkronisasi (UI nyata, `uiopt.js`)
- Stop Status → `Unit Continuous Running`: Commitment Mode ikut `continuous`, `required_mode = {mode: continuous}`, `stop_mode` tidak ditulis, unit masuk `unit_cannot_stop`: **continuous / {'mode': 'continuous'} / None / True**
- Keluar dari continuous (Stop Based On Request 16:00): Commitment Mode `-`, `stop_mode = {stop_at, 16:00}`: **- / {'mode': 'stop_at', 'at': '16:00'}**
- Commitment Mode → Continuous: dropdown Stop Status ikut `continuous`: **continuous**
- Status uji sinkronisasi: **PASS**. Save/Reload memakai enum yang sama (payload kanonik tunggal).

## Audit row (CLI, source final)
| Kasus | Skenario | Unit online | 15:30 | 16:00 | 07:30 | 08:00 | 20:30 | 21:00 | 00:00 | Hard | Gate | Wall (s) |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| SR1_g5_req1600 | G5 (awal Stop) — Stop Based On Request 16:00 | 14–25 | 0.0 | 0.0 | 15.0 | 20.0 | 0.0 | 0.0 | 0.0 | PASS | PASS | 10.3 |
| SR2_g6_req2100 | G6 (Running) — Stop Based On Request 21:00 | 1–41 | 26.9 | 29.5 | 20.0 | 31.0 | 20.0 | 0.0 | 0.0 | PASS | PASS | 10.53 |
| SR3_g5_simmust | G5 — Stop Based On Simulation | 14–35 | 31.0 | 20.0 | 15.0 | 20.0 | 0.0 | 0.0 | 0.0 | PASS | PASS | 9.69 |
| SR4_g6_stop_or_cont | G6 — Stop Based On Simulation or Continuous Running | 1–48 | 31.0 | 27.0 | 20.0 | 31.0 | 20.0 | 20.0 | 20.0 | PASS | PASS | 9.38 |
| SR5_g5_continuous | G5 — Unit Continuous Running | 2–48 | 31.0 | 24.8 | 20.0 | 20.0 | 20.0 | 20.0 | 20.0 | PASS | PASS | 6.6 |
| SR6_g6_req0800 | G6 (Running) — Stop Based On Request 08:00 | 1–15 | 0.0 | 0.0 | 20.0 | 0.0 | 0.0 | 0.0 | 0.0 | PASS | PASS | 20.47 |

- Stop Based On Request: row jam request **0 MW** (16:00 / 21:00 / 08:00), row sebelumnya boleh berbeban (SR2 20:30 = 20 MW, SR6 07:30 = 20 MW). Untuk unit yang awalnya Stop dan di-start engine (SR1 G5), Stop At adalah batas paling lambat — engine boleh menghentikannya lebih awal bila lebih murah.
- Unit Continuous Running (SR5): G5 online sampai row 00:00 tanpa jeda.
- Perbaikan V15.17 jembatan STG (`PP_V1517_BRIDGE_DOWN`, `PP_V1517_BRIDGE_STGOUT`) menutup kegagalan minimum downtime SR2/SR6.
