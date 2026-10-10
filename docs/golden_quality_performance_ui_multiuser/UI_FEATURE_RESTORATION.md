# UI_FEATURE_RESTORATION

Inventaris fitur UI yang wajib ada (INSTRUKSI §9), dengan alur DOM → payload → normalisasi → worker → result → save/load. Seluruh bukti diambil dari browser nyata (Chromium/Playwright) pada source final; screenshot Seluruh bukti diambil dari browser nyata (Chromium/Playwright) pada source final. JSON di `harness/ab_golden_multiuser/ui_final/`. Skrip ada di `harness/ab_golden_multiuser/tools/`.

| Fitur | DOM (index.php) | Payload (`data3.modeling`) | Worker / result | Save / reload | Bukti |
|---|---|---|---|---|---|
| Fix SR | radio `#sr-radio-fixed` + input SR (MW) | `sr_mode = "fixed"`, `sr_fixed_mw` | `pp_reserve_min()` → kolom `SR_Min` per row | tersimpan & pulih | uisave.json `U0_off`: efektif 15 MW di semua row |
| Follow PV | radio `#sr-radio-pv` | `sr_mode = "follow_pv"`, `pv_rows[48]` | `SR_Min = max(Fix SR, PV)` per row; info `Spinning Reserve Requirement.sr_mode` | pulih (`radio_pv: true`) | U1_follow_pv_on.png; uisave.json `srmin_23_31` = `eff_23_31`, `sr_under = 0` |
| SR Effective per row | tabel `#tbl-pv` (kolom Effective) | `sr_effective_rows` (dihitung ulang saat mode berubah) | validator `pp_spinning_reserve` vs `SR_Min` | pulih | uisave.json `eff_23_31` |
| PV table + PV chart | `#pv-card`, `#tbl-pv`, `#pv-chart` (SVG), `#pv-chart-tooltip` | `pv_rows` | kolom `PV` di setiap row hasil | pulih | tab_SR_Bus_Flow.png |
| Kolom PV di Simulation Data | kolom PV selalu dirender | — | `pp_v1516_row_pv_sr()` mengisi `PV`, `SR_Min`, `Spin_Res` di semua row | — | U1_result_pv_column.png, `pv_col: true` di seluruh matrix |
| Spin_Res selaras validator | kolom `Spin_Res` | — | dihitung `pp_spinning_reserve()` (sama dengan validator; dulu `calc_sr` lama tidak menghitung G7) | — | matrix `sr_under = 0` pada semua kasus (33 run) dan pada uji Follow PV |
| IE chart | `#ie-chart-card`, SVG `#ie-chart`, tooltip, statistik | `ie_dispatch` 48 slot | — | pulih | IE_CHART_ACCEPTANCE.md |
| Import CSV IE/Dispatch/PV | `#csv-preview` + Apply/Cancel | 48 slot | — | — | uiimport.json (tabel 581→601, chart max 598→618) |
| Timer elapsed kanan bawah | `#run-timer` (tick 1 detik, terikat Run ID) | — | tahap nyata dari `prelim_progress` / `job_poll` | — | U7_timer.json, U7_timer_running/final.png |
| Save / Reload | `#btn-save`, "Reload saved input" | seluruh input + report tabs | `sds_commit` (scope uid/tab/project/run) | `saved/users/<uid>/input_latest.json` | uisave.json `reload`, conc C6 |
| Popup keputusan bahan bakar | `#gsf-box`, `#gsf-lng`, `#gsf-dist` | `gas_shortage_action`, `additional_lng` / `distillate_user_limit_litres` | Normal Run = `recommendation`; Run ulang sesudah keputusan diselesaikan otomatis | — | matrix R2/R3 popup 2,8–3,5 s |

## Timer (U7)
- Tick setiap 1 detik (`el` 00:01, 00:02, …), dengan tahap nyata (mis. "fastest review unit priority").
- Run ID berubah saat run ulang (`plan-1-…` → `plan-2-…`).
- Status akhir FINAL setelah 48 row tampil.
- Uji final (P0 + LNG): 13 tick, 00:01 → 00:14, FINAL "Selesai · 48 row dirender".
- Saat menunggu popup bahan bakar, status MENUNGGU_KEPUTUSAN_BAHAN_BAKAR (timer berhenti, tidak dihitung sebagai berjalan).

## Follow PV end-to-end (uisave.json)
- Payload: `sr_mode = follow_pv`, `sr_fixed_mw = 15`, PV rows 25–30 = 40 MW.
- Result: gate PASS, CP 91.3765. `SR_Min` row 23–31 = [24.11, 17.12, 40 ×6, 15] = max(Fix 15, PV).
- Reload: `sr_mode` follow_pv, `sr_fixed_mw` 15, PV pulih, IE row 6 = 490, chart 48 titik.
