# Feature Inventory V15.17 (Addendum §10)

Kolom: UI element → payload key → normalisasi → konsumen worker → field hasil → key Save/Reload → test ID → status.

Status di sini adalah status **fungsional end-to-end** pada source final. Angka performa ada di `FINAL_RELEASE_MATRIX.md`.

| Fitur | UI element | Payload key (`data3.modeling.*`) | Normalisasi | Konsumen worker | Field hasil | Save/Reload | Test ID | Status |
|---|---|---|---|---|---|---|---|---|
| Follow PV | SR menu: mode *Follow PV* | `sr_mode='follow_pv'`, `pv_rows[48]` | `pp_reserve_min()` (worker_functions) | validasi `spinning_reserve` per row, dispatch headroom | `data[].PV`, `data[].SR_Min`, `Spin_Res` | input tersimpan (`sr_mode`, `pv_rows`) | IE9, CO3, P10 | PASS |
| Fix Spinning Reserve | SR menu: *Fix SR* (MW) | `sr_mode='fixed'`, `sr_fixed_mw` | `pp_reserve_min()` | idem | `SR_Min` konstan | idem | IE10, CO2 | PASS |
| SR Effective Minimum | tabel SR Effective (UI) | `sr_effective_rows` (turunan UI: `max(fix, pv)` pada follow_pv) | dihitung ulang di worker dari `sr_mode`/`pv_rows`/`sr_fixed_mw` (sumber tunggal) | idem | `SR_Min` | idem | IE9/IE10 | PASS |
| PV import + kolom PV | Import CSV/Excel A–D, tabel PV | `pv_rows` | header skip, pemetaan 48 row | `pp_run_simulation` (kolom PV) | `data[].PV`, chart PV | idem | P10, IE9 | PASS |
| IE Prediction & Dispatch chart | chart `#ie-chart` | `data1[].value` | – | – | chart prediksi | input | IE16 | PASS |
| IE Adjustment (incremental redispatch) | tabel `#tbl-ie-adj` (+/−, nilai, start, stop, kolom "Row") | `ie_adjustments[{operator,value,start_period,stop_period}]` | `pp_apply_ie_adjustment` (label row inklusif: 00:30 = row 1, 00:00 = row 48); `pp_ie_series()` = sumber tunggal pred/adj/eff | jalur warm `pp_v1517_ie_warm` (Stage A/B, landing) → fallback Fastest penuh | `data[].IE_Pred`, `IE_Adj`, `IE` (efektif), `info['IE Adjustment']`, `info['IE Incremental Redispatch']` | `ie_adjustments` tersimpan, chart & label row pulih setelah reload | IE0–IE17 | PASS |
| IE Effective chart/data | garis hijau "IE Effective", legend, tooltip, statistik | – | `ieAdjSeries()` (UI) = `pp_ie_series()` (worker) | – | kolom `IE EFFECTIVE (MW)`, Excel: IE Prediction/Adjustment/Effective | – | IE16 | PASS |
| Actual Gas fields | Simulation Data (actual per row) | `actual_data.rows`, actual gas per supplier | context hash Actual Gas (§6.1) | jalur warm actual gas (affected rows + jendela maju) | gas reconciliation, `Total Gas Used` | idem | A0–A8, IE11 | PASS (A2/A4/A6: sertifikat envelope) |
| Fixed Flow redistribution | Min PGN Flow | `min_pgn_flow`, `fixed_flow_*` | – | `PP_FF_REDIST` water-filling prorata JBBK (Σ tetap) | `FixedFlow_J`, `info['Fixed Flow JBBK Redistribution']` | idem | R2 (26 / 45) | PASS / terminal envelope |
| Gas Shortage recommendation | popup `#gsf-box` (LNG / Distillate) | `gas_shortage_action`, `__fuel_decision_mode` | – | rekomendasi + keputusan bahan bakar dua langkah | `Recommended LNG`, `Recommended Distillate`, `Residual Gas Shortage` | – | S19–S22, IE12, CO0/CO3/CO4 | PASS |
| LNG | tombol `#gsf-lng` | `additional_lng` | – | `add_lng` | `Added LNG`, gas reconciliation | idem | S7–S21, IE12 | PASS |
| Distillate continuous full-first | tombol `#gsf-dist`, plafon liter | `distillate_user_limit_litres` | level legal 30/50/75/100 % | `pp_dist_continuous_alloc` (blok kontinu, ramp bertahap) | `Dist_Total`, per unit G%, liter; `info['Distillate Continuity Audit']` | idem; Excel kolom `Distillate G* (%)` | D0–D6, S22, IE13 | PASS |
| Required / Continuous / Stop Status | Commitment Mode, Stop Status (termasuk **Unit Continuous Running**) | `required_mode`, `required_units`, `unit_cannot_stop`, `stop_mode`, `unit_stop_time` | enum kanonik tunggal; sinkronisasi dua arah STOP_MODES ⇄ COMMIT_MODE; continuous tidak menulis `stop_mode` | dispatcher commitment + validator `cannot_stop` / `commitment_continuous` | status unit per row | idem | SR1–SR6, S4, S5 | PASS |
| Trip / unavailable | Stop (sepanjang hari / jendela waktu) | `unit_stop`, `unit_stop_time` | – | dispatcher | unit 0 MW | idem | S2, S3, S6, CO5 | PASS (S6: sertifikat terminal) |
| Change Over | panel Change Over Block 1/2 | `change_over{enabled,blocks}` | `pp_normalize_change_over` | `pp_changeover_sim_sweep` (+ refinement defisit, what-if LNG) | `info['Change Over Timeline']` | idem (`CO_INITED`) | CO0–CO7, IE15 | PASS |
| C1–C4 | – | – | – | Headroom Priority + LLF + Fastest Unit Priority Fix | `info['Merit Proof C1-C4 STG']` | – | semua | PASS |
| STG | – | `stg_startup_mode` | – | `pp_recompute_stgs` + gerbang release 4d | kolom S1–S3, audit STG | – | semua | PASS |
| Simulation Data | tab Simulation Data | – | – | – | `data[48]` | hasil tersimpan | semua | PASS |
| Summary | tab Summary | – | – | – | `info[...]` | – | semua | PASS |
| Save/Reload | tombol Save, reload halaman | seluruh input | datastore SQLite WAL / fallback JSON | `saved_data_store.php` | – | – | IE16, conc | PASS |
| Report tabs | tab laporan + Excel (`resultToHTMLTable`) | – | – | – | Excel: info sebagai JSON, kolom IE dan Distillate | – | IE16 | PASS |
| Multi-user Run/Save isolation | 2 context browser | `_request_id`, UID/tab | jobs per request | job worker | – | per user | IE17, conc.js, c5.js | PASS |
| Progress elapsed timer | `#run-msg`, `ppRunTimerState` | – | – | `pp_job_progress` | baris ringkas: status, target, waktu, kandidat, CP, HR | – | R9 | PASS |
| Remark ringkas di bawah hasil | `#run-msg` (satu baris) | – | – | – | – | – | R9 | PASS (duplikat dihapus) |
| Fastest / Maximum Review | `#f-tl-target` | `_fast_default` / target | – | `pp_v13f_fast_job` / jalur exact | `Run Status.mode` | – | R10 | PASS |
| XAMPP / Linux parity | – | – | – | PHP 7.4 lint, setup Linux | – | – | R12 | lihat matrix |
