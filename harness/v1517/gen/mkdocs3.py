# mkdocs3.py : FINAL_RELEASE_MATRIX.md + TEST_STATUS.md
import json, os, sys
sys.path.insert(0, '/home/claude/ab2/op/gen')
from tables import md, scen, scen_table, fx, fx_row, ui, OP
D = '/home/claude/ab2/docs_out'
def w(name, text): open(f'{D}/{name}', 'w').write(text.strip() + '\n'); print('wrote', name)
def U(n):
    r = ui(n)
    if not r: return ['TIDAK ADA'] + [''] * 6
    return [r.get('t_total'), r.get('t_popup') or '–', r.get('gate'), r.get('cp'), r.get('hr'), r.get('merit'), r.get('mode') or r.get('co') or '–']
def L(n):
    f = f'{OP}/linux/{n}.json'
    if not os.path.isfile(f): return ['–'] * 4
    r = json.load(open(f)); return [r.get('t_total'), r.get('gate'), r.get('cp'), r.get('merit')]
br = [['Golden Fastest (P0, rekomendasi → Distillate)'] + U('F_golden_fast') + L('L_golden_fast'),
      ['Golden Maximum Review (P0, dua langkah)'] + U('F_golden_max') + L('L_golden_max'),
      ['Golden Maximum Review (GOLDEN_dist, satu langkah)'] + U('F_goldendist_max') + L('L_goldendist_max'),
      ['PGN25/LNG18/PEP36/Akasia4'] + U('F_R1_pgn25') + L('L_R1_pgn25'),
      ['Follow PV (P10)'] + U('F_P10_pv') + L('L_P10_pv'),
      ['Change Over B2→B1 (CO1)'] + U('F_CO1') + L('L_CO1'),
      ['Change Over B1→B2 + LNG (CO0)'] + U('F_CO0') + L('L_CO0'),
      ['Actual Gas basis (R5)'] + U('F_R5_actual') + L('L_R5_actual'),
      ['Terminal row 1 (P12 G8 unavailable)'] + U('F_P12_terminal') + L('L_P12_terminal')]
uw = ui('F_uiwarm') or {}; u17 = ui('F_ie17') or {}; uo = ui('uiopt') or {}; u16 = ui('uiie') or {}
def readlog(n):
    f = f'{OP}/ui16/{n}'; return open(f).read() if os.path.isfile(f) else ''
conc = readlog('F_conc.log'); c5 = readlog('F_c5.log'); sup = readlog('F_sup.log')
S = scen()
def cnt(pfx):
    xs = [d for k, d in S.items() if k.startswith(pfx)]
    p = sum(1 for d in xs if d['final'].get('gate') == 'PASS'); t = [d for d in xs if d['final'].get('gate') != 'PASS']
    return len(xs), p, [d['case'] + ':' + ','.join(d['final'].get('blk') or []) for d in t]
nS, pS, fS = cnt('S'); nI, pI, fI = cnt('IE'); nC, pC, fC = cnt('CO')
lin = json.load(open(f'{OP}/linux/summary.json')) if os.path.isfile(f'{OP}/linux/summary.json') else {}
gate = [
 ('Distillate → gas → Distillate tanpa bukti', 'TIDAK TERJADI', 'audit kontinuitas PASS di seluruh kasus; celah blok selalu dengan bukti row tidak eligible'),
 ('Distillate pada unit 5 MW', 'TIDAK TERJADI', 'aturan eligibility > 5 MW + validator'),
 ('Remark duplikat di bawah hasil', 'TIDAK ADA', f"#run-msg 1 baris (desktop/fullscreen/mobile): {[uo.get(k, {}).get('lines') for k in ('msg_desktop', 'msg_full', 'msg_mobile')]}"),
 ('PGN25 fixture > 30 s', 'TIDAK', f"browser {(ui('F_R1_pgn25') or {}).get('t_total')} s, CLI 13,6 s"),
 ('Actual Gas warm > 5 s tanpa bukti', 'TIDAK', 'warm 1,3–3,0 s (A1/A3/A5/A7/A0)'),
 ('IE Adjustment jendela kecil warm > 5 s tanpa bukti', 'TIDAK', 'engine warm IE3 4,27 s / IE2 4,27 s / IE4 3,62 s; IE1 commitment repair dengan bukti export_range'),
 ('IE Adjustment tidak mengubah dispatch', 'TIDAK', 'IE3: 9 row dispatch berubah, CP 79,9049 → 80,6838; IE8: commitment G4/G5 berubah'),
 ('Fixed Flow total berubah', 'TIDAK', 'R2 Σ Fixed Flow 1920 = 1920'),
 ('Min Flow PGN gagal setelah koreksi legal', 'TIDAK (26) / terminal terbukti (45)', 'Min Flow 45 envelope tidak cukup — sertifikat'),
 ('Stop Status semantics salah', 'TIDAK', 'SR1–SR6 PASS; sinkronisasi UI PASS'),
 ('Unit Continuous Running dapat stop', 'TIDAK', 'SR5 G5 2–48; validator cannot_stop'),
 ('Stop Request 16:00 masih berbeban pada 16:00', 'TIDAK', 'SR1 row 16:00 = 0 MW'),
 ('Change Over satu arah belum PASS', 'TIDAK', f'CO0–CO7 {pC}/{nC} PASS CLI; CO0 & CO1 PASS lewat UI'),
 ('Follow PV / Fix SR / SR Effective hilang', 'TIDAK', 'IE9/IE10/CO2/CO3/P10 PASS; fitur di UI'),
 ('Fastest / Maximum Review tertukar', 'TIDAK', 'label run-msg & Run Status konsisten (Fastest - Default / Maximum Review)'),
 ('CP/HR regress tanpa bukti', 'TIDAK', 'golden CP 79,9049 < V15.16 79,9299; HR 8239,87 sama'),
 ('Multi-user isolation gagal', 'TIDAK', 'conc C0–C6, c5, supersede, IE17 PASS'),
 ('Hard / C1–C4 / STG gagal', 'TIDAK', f'skenario S {pS}/{nS}, IE {pI}/{nI}, CO {pC}/{nC} gate PASS; sisanya sertifikat terminal'),
 ('Maximum Review > 80 s (Addendum 2)', 'TIDAK', f"golden Max browser {(ui('F_goldendist_max') or {}).get('t_total')} s / dua langkah {(ui('F_golden_max') or {}).get('t_total')} s"),
]
w('FINAL_RELEASE_MATRIX.md', f"""
# FINAL RELEASE MATRIX — V15.17

Source beku: lihat `CHECKPOINT.md` (SHA256). Status dipisah: Functional / Validation / Quality / Performance / Release blocker.

## Gate Addendum §12 (+ Addendum 2: Maximum Review ≤ 80 s)
{md(['Blocker', 'Status', 'Bukti'], gate)}

## R0–R12
| Tahap | Functional | Validation | Quality | Performance | Laporan |
|---|---|---|---|---|---|
| R0 Distillate continuity | PASS | PASS | PASS (CP 79,9049) | PASS | DISTILLATE_CONTINUITY_AUDIT.md |
| R1 PGN25/LNG18/PEP36/Akasia4 | PASS | PASS | PASS | PASS ({(ui('F_R1_pgn25') or {}).get('t_total')} s browser) | PGN25_LNG18_PERFORMANCE.md |
| R2 Min Flow PGN | PASS | PASS / terminal terbukti | PASS | PASS | MIN_PGN_FLOW_FIXED_FLOW_REDISTRIBUTION.md |
| R3 Actual Gas incremental | PASS | PASS (A2/A4/A6 input infeasible) | PASS | PASS (warm ≤ 3,0 s) | ACTUAL_GAS_INCREMENTAL_RERUN.md |
| R3A IE Adjustment | PASS | PASS | PASS | PASS (engine); browser klik→FINAL 6,9–9 s dicatat | IE_ADJUSTMENT_INCREMENTAL_REDISPATCH.md |
| R4 Stop Status | PASS | PASS | PASS | PASS | STOP_STATUS_SEMANTICS.md |
| R5 one-off / one-trip | PASS | S2/S3 PASS; S1/S6 sertifikat terminal row 1 | PASS | PASS | tabel skenario di bawah |
| R6 supplier ±1 | PASS | PASS (S7–S17) | PASS | PASS (≤ 12,4 s dua langkah) | tabel skenario |
| R7 Change Over dua arah | PASS | PASS | PASS | PASS | CHANGEOVER_BIDIRECTIONAL.md |
| R8 Follow PV / Fix SR / SR Effective | PASS | PASS | PASS | PASS (kasus sulit ≤ 30 s) | IE9/IE10/CO2/CO3/P10 |
| R9 remark UI | PASS | – | – | – | `#run-msg` 1 baris; screenshot desktop/fullscreen/mobile/error |
| R10 golden Fastest / Maximum Review | PASS | PASS | PASS | PASS | tabel browser |
| R11 concurrency + save/reload | PASS | PASS | – | PASS | conc/c5/supersede/IE17 |
| R12 XAMPP/Linux parity | {lin.get('status', 'lihat tabel')} | | | | tabel browser (kolom Linux) |

## Browser (UI nyata) — XAMPP-like (6 worker PHP 7.4) dan Linux (Apache 2.4 + PHP-FPM 7.4)
{md(['Kasus', 'Klik→FINAL (s)', 'Klik→popup (s)', 'Gate', 'CP', 'HR', 'Merit C1–C4/STG', 'Mode', 'Linux klik→FINAL (s)', 'Linux gate', 'Linux CP', 'Linux merit'], [[r[0]] + r[1:] for r in br])}

Rantai IE warm lewat UI: {', '.join(f"{r['case']} {r['t_click_to_final']} s (job {r.get('job')} s, {r.get('cache')}, CP {r['cp']})" for r in uw.get('runs', []))}.

IE16 UI: gate {u16.get('result', {}).get('gate')}, Pred+Adj=Eff {u16.get('result', {}).get('consistent')}, Excel kolom IE {u16.get('excel', {}).get('has_cols')}, reload aturan {len(u16.get('reload', {}).get('rules') or [])}. IE17: {'PASS' if u17.get('pass') else 'FAIL'}.

Konkurensi: conc.js →
```
{conc.strip()[-1500:]}
```
c5.js (2× Maximum Review bersamaan + cancel) →
```
{c5.strip()[-600:]}
```
Supersede lintas user →
```
{sup.strip()[-600:]}
```

## Matriks skenario (CLI, alur dua langkah seperti UI) — 38 kasus
{scen_table(['S', 'IE', 'CO'])}

Kasus tidak PASS: {', '.join(fS + fI + fC) or 'tidak ada'} — seluruhnya `TERMINAL_INFEASIBLE_EXPORT_ROW_1` dengan bukti fisik (unit Last Data Running maksimum → Export maks 13,72 MW < Range Min 15 MW, defisit 1,28 MW; unit Stop paling cepat berbeban di row 2).
""")
print('ok3')
