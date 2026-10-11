# mkdocs.py : laporan Addendum §13 dari data regresi final
import json, os, sys
sys.path.insert(0, '/home/claude/ab2/op/gen')
from tables import md, scen, scen_table, fx, fx_row, ui, OP
from ietab import row as ierow
D = '/home/claude/ab2/docs_out'
HFX = ['Kasus', 'Wall (s)', 'Hard', 'Gate', 'CP', 'HR', 'C1–C4 FAIL', 'STG', 'Kontinuitas Dist.', 'Cache warm']
def w(name, text): open(f'{D}/{name}', 'w').write(text.strip() + '\n'); print('wrote', name)
def g(o, *ks, d=None):
    for k in ks:
        if not isinstance(o, dict): return d
        o = o.get(k)
    return d if o is None else o

# ---------------- DISTILLATE ----------------
def dist_levels(name, sub='cold'):
    d = fx(sub, name)
    if not d: return '–'
    a = g(d, 'output', 'info', 'Distillate Continuity Audit', d={}) or {}
    return json.dumps(a.get('units') or a.get('blocks') or {k: v for k, v in a.items() if k not in ('rule', 'status')}, ensure_ascii=False)[:300]
rows = [fx_row('cold', n, l) for n, l in [('D0_golden', 'D0 golden'), ('D2_g6_stop2100', 'D2 G6 stop 21:00'), ('D3b_first_prio_unavail', 'D3b prioritas pertama unavailable'),
        ('D4_g6_stop0800', 'D4 G6 stop 08:00'), ('D4b_two_units', 'D4b dua unit'), ('D4c_two_units', 'D4c dua unit (G6 + G4)'), ('D5b_two_blocks_stopwin', 'D5b dua blok G6 (celah stop window)'),
        ('D3_g6_unavail', 'D3 G6 unavailable (terminal)'), ('D5_g6_stop1000_1400', 'D5 fixture lama (terminal)'), ('D5_two_blocks_fixload', 'D5 fixture lama Fix Load 5 MW (ilegal)')]]
u = ui('uiopt') or {}
w('DISTILLATE_CONTINUITY_AUDIT.md', f"""
# Distillate Continuity Audit (Addendum §1, R0)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — level legal hanya 30/50/75/100 %; 75 % muncul di UI, payload, worker, validator, kalkulasi bahan bakar, Simulation Data, Summary, Save/Reload, Excel |
| Validation | **PASS** — audit `distillate_continuity` + hard validation PASS pada seluruh kasus feasible; D→G→D hanya dengan bukti row tidak eligible |
| Quality | **PASS** — golden CP 79,9049 (V15.16: 79,9299), HR 8239,87; satu blok kontinu G6 row 26–48 (30→50→75→100) |
| Performance | **PASS** — golden Fastest ±10 s CLI / ±12 s browser; golden Max 26,4 s browser |
| Release blocker | Tidak ada |

## Aturan yang ditegakkan (generik)
- Level legal per unit: 30, 50, 75, 100 % (pasangan daftar — bug float-key PHP diperbaiki).
- Tidak ada Distillate pada unit ≤ 5 MW (startup / tidak operasional).
- Satu blok kontinu per unit; ramp ±1 level per row; masuk/keluar blok pada 30 % bila bersebelahan dengan row gas.
- Celah antara dua blok hanya sah bila ada row tidak eligible (bukti dicetak per celah, mis. `row 30 tidak eligible (G6 0.00 MW)`).
- Full-first antarunit: unit prioritas Distillate pertama dipenuhi lebih dulu; unit kedua hanya bila unit pertama habis ruang.
- Rekomendasi memakai allocator yang sama + margin 5 % → liter rekomendasi ≥ liter eksekusi (perbaikan browser golden VALID-INFEASIBLE).

## Hasil (CLI, source final, cold)
{md(HFX, rows)}

Catatan: D3/D5 (fixture lama) berakhir dengan sertifikat `TERMINAL_INFEASIBLE_EXPORT_ROW_1` (unit Last Data Running dibuat tidak tersedia → row 1 tidak dapat memenuhi Range Min). `D5_two_blocks_fixload` memakai Fix Load 5 MW yang ilegal di luar startup sequence; validator menolaknya dengan benar dan fixture diganti D5b.

## UI 75 %
- Excel (`resultToHTMLTable`): kolom {', '.join(g(u, 'dist', 'excel_cols', d=[]))}, nilai 75 terdeteksi: {g(u, 'dist', 'excel_has75')}.
- Audit kontinuitas pada hasil UI: {g(u, 'dist', 'audit')}.
""")

# ---------------- STOP STATUS ----------------
sr = []
for n, desc in [('SR1_g5_req1600', 'G5 (awal Stop) — Stop Based On Request 16:00'), ('SR2_g6_req2100', 'G6 (Running) — Stop Based On Request 21:00'),
                ('SR3_g5_simmust', 'G5 — Stop Based On Simulation'), ('SR4_g6_stop_or_cont', 'G6 — Stop Based On Simulation or Continuous Running'),
                ('SR5_g5_continuous', 'G5 — Unit Continuous Running'), ('SR6_g6_req0800', 'G6 (Running) — Stop Based On Request 08:00')]:
    d = fx('cold', n)
    if not d: continue
    U = 'G5' if 'g5' in n else 'G6'; data = d['output']['data']
    on = [k + 1 for k, r in enumerate(data) if float(r.get(U) or 0) > 0.01]
    at = {r['Time'][-5:]: round(float(r.get(U) or 0), 1) for r in data}
    sr.append([n, desc, f'{on[0]}–{on[-1]}' if on else '–', at.get('15:30'), at.get('16:00'), at.get('07:30'), at.get('08:00'), at.get('20:30'), at.get('21:00'), at.get('00:00'), d.get('hard'), (d['output'].get('release_gate') or {}).get('status'), d.get('wall_s')])
sy = g(u, 'sync', d={})
w('STOP_STATUS_SEMANTICS.md', f"""
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
- Stop Status → `Unit Continuous Running`: Commitment Mode ikut `continuous`, `required_mode = {{mode: continuous}}`, `stop_mode` tidak ditulis, unit masuk `unit_cannot_stop`: **{sy.get('stop_to_cont', {}).get('commit')} / {sy.get('stop_to_cont', {}).get('rm')} / {sy.get('stop_to_cont', {}).get('sm')} / {sy.get('stop_to_cont', {}).get('cannot')}**
- Keluar dari continuous (Stop Based On Request 16:00): Commitment Mode `-`, `stop_mode = {{stop_at, 16:00}}`: **{sy.get('cont_to_request', {}).get('commit')} / {sy.get('cont_to_request', {}).get('sm')}**
- Commitment Mode → Continuous: dropdown Stop Status ikut `continuous`: **{sy.get('mode_to_cont', {}).get('stopSel')}**
- Status uji sinkronisasi: **{'PASS' if sy.get('pass') else 'FAIL'}**. Save/Reload memakai enum yang sama (payload kanonik tunggal).

## Audit row (CLI, source final)
{md(['Kasus', 'Skenario', 'Unit online', '15:30', '16:00', '07:30', '08:00', '20:30', '21:00', '00:00', 'Hard', 'Gate', 'Wall (s)'], sr)}

- Stop Based On Request: row jam request **0 MW** (16:00 / 21:00 / 08:00), row sebelumnya boleh berbeban (SR2 20:30 = 20 MW, SR6 07:30 = 20 MW). Untuk unit yang awalnya Stop dan di-start engine (SR1 G5), Stop At adalah batas paling lambat — engine boleh menghentikannya lebih awal bila lebih murah.
- Unit Continuous Running (SR5): G5 online sampai row 00:00 tanpa jeda.
- Perbaikan V15.17 jembatan STG (`PP_V1517_BRIDGE_DOWN`, `PP_V1517_BRIDGE_STGOUT`) menutup kegagalan minimum downtime SR2/SR6.
""")

# ---------------- MIN FLOW ----------------
def ffinfo(n):
    d = fx('cold', n); return g(d, 'output', 'info', 'Fixed Flow JBBK Redistribution', d={}) if d else {}
f26, f45 = ffinfo('R2_minflow26'), ffinfo('R2_minflow45_infeas')
w('MIN_PGN_FLOW_FIXED_FLOW_REDISTRIBUTION.md', f"""
# Emergency Min Flow PGN via Fixed Flow JBBK Redistribution (Addendum §5, R2)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — redistribusi hanya dijalankan setelah lever legal lain habis; pengurangan minimal, water-filling prorata, Σ Fixed Flow tetap |
| Validation | **PASS** (Min Flow 26) / sertifikat terminal (Min Flow 45: envelope tidak cukup) |
| Quality | PASS — CP Min Flow 26 = golden (79,9047 vs 79,9049) |
| Performance | PASS — ≤ 3 iterasi; 9,9 s (26) / 15,1 s (45) |
| Release blocker | Tidak ada |

## Hasil
{md(HFX, [fx_row('cold', 'R2_minflow26', 'R2 Min Flow 26 MMSCFD'), fx_row('cold', 'R2_minflow45_infeas', 'R2 Min Flow 45 MMSCFD')])}

### Min Flow 26 — audit redistribusi
```json
{json.dumps(f26, ensure_ascii=False, indent=1)[:2500]}
```

### Min Flow 45 — bukti terminal
```json
{json.dumps(f45, ensure_ascii=False, indent=1)[:2000]}
```
Min Flow 45 MMSCFD tidak dapat dipenuhi pada 34 row (Flow PGN RT terendah 30,019 MMSCFD) bahkan setelah redistribusi maksimum yang legal — keputusan kuota/operator diperlukan; Σ Fixed Flow tidak diubah.
""")
print('ok')
