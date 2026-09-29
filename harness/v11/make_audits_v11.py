#!/usr/bin/env python3
"""Dokumen audit V11 dari hasil uji nyata (bukan angka tulisan tangan).
Arg: <folder-targeted> <wb09_3-output.json> <folder-dokumen>
Menulis AUDIT_UI_COUNTER_V11.md, AUDIT_CP_HEATRATE_V11.md, AUDIT_LOW_LOAD_FRAGMENTATION_V11.md."""
import json, sys, os, re
T, WJ, OUT = sys.argv[1:4]
d = json.load(open(WJ)); o = d.get('output') or d; i = o['info']; rows = o['data']
def f(x, n=4):
    try: return ('%.' + str(n) + 'f') % float(x)
    except Exception: return '-' if x is None else str(x)
def rd(p):
    try: return open(p, encoding='utf-8').read()
    except FileNotFoundError: return ''
def jl(p):
    try: return [json.loads(l) for l in open(p) if l.strip().startswith('{')]
    except FileNotFoundError: return []

# ---------- UI + counter ----------
c = i.get('V11 Candidate Counters') or {}
sw = i.get('V11 Consolidation Sweep') or {}
L = ['# Audit UI dan candidate counter V11', '',
     'Sumber: `TL_UI_V11.md`, `WB09_3_UI.md` (browser nyata, backend nyata) dan `V11_CASES.md` (R04) dari folder uji; output reproducer WB09_3.', '',
     '## SUMMARY kuning (lima field)', '',
     '`#tl-banner` hanya berisi: Target waktu | Waktu aktual | Kandidat diperiksa | Kandidat valid | Cost Production. '
     'Status constraints, Global optimum proven, Total Cost, 48 rows/Export/residual/Unit Priority, ruang kandidat dan lima audit V11 '
     'ditampilkan di panel `#tl-audit` ("Detail audit"). Jalur yang diuji: Target < 15, < 25, < 35, < 45, < 55, < 60, Maximum Review, '
     'VALID PROVISIONAL, exact final, no valid result, Gas Shortage (popup, LNG, Distillate, campuran), rerun bahan bakar.', '']
for name in ('TL_UI_V11', 'TL_UI', 'TL_UI_MAX_FRESH', 'WB09_3_UI', 'PROV_UI_PGN1', 'PROV_UI_PGN33', 'TL_NOVALID', 'GAS_SHORTAGE_PGN25', 'GAS_SHORTAGE_PGN20', 'TARGETED12'):
    t = rd(os.path.join(T, name + '.md'))
    if not t: continue
    m = re.search(r'\*\*(\d+)/(\d+)\*\*', t) or re.search(r'(\d+)/(\d+) PASS', t)
    L.append('- `%s`: %s' % (name, ('%s/%s PASS' % (m.group(1), m.group(2))) if m else 'lihat berkas'))
L += ['', '## Counter kandidat (satu sumber, kunci fisik kanonik) — reproducer WB09_3', '',
      '| field | nilai |', '|---|---|']
for k in ('candidates_checked', 'candidates_full_run', 'candidates_screened_out', 'screened_also_full_run_excluded', 'candidates_valid', 'best_candidate', 'best_candidate_in_valid', 'constraints_pass', 'cost_production'):
    L.append('| %s | %s |' % (k, c.get(k)))
L += ['', 'Invarian: ' + json.dumps(c.get('invariants')), '', 'Definisi: ' + str(c.get('definition')), '', 'Kunci: ' + str(c.get('key')), '',
      '### Sapuan konsolidasi (dihitung terpisah dari counter di atas)', '',
      '| dibangkitkan | gugur Tier 1 | full-run | valid | row fragmentation | wall |', '|---|---|---|---|---|---|',
      '| %s | %s | %s | %s | %s | %s s |' % (sw.get('generated'), sw.get('screened_tier1'), sw.get('full_run'), sw.get('valid'), sw.get('fragmentation_rows'), sw.get('wall_s')), '',
      '| kandidat | jenis | unit | tier | varian | hasil | CP | Heat Rate |', '|---|---|---|---|---|---|---|---|']
for x in sw.get('candidates') or []:
    L.append('| %s | %s | %s | %s | %s | %s | %s | %s |' % (x.get('id'), x.get('kind'), x.get('unit'), x.get('tier', '-'), x.get('variant', '-'), str(x.get('result'))[:80], f(x.get('cp')), f(x.get('heat_rate'), 2)))
open(os.path.join(OUT, 'AUDIT_UI_COUNTER_V11.md'), 'w').write('\n'.join(L) + '\n')

# ---------- CP / Heat Rate ----------
b = i.get('V11 Candidate Comparison') or {}; ca = i.get('V11 CP Audit') or {}; v = i.get('V8 Priority Review') or {}
L = ['# Audit Cost Production dan Heat Rate V11 — reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3)', '',
     'Skenario: PGN Pipe 32, PEP Jababeka 34 MMSCFD (36,72 BBTUD), PEP KP72 2,2 BBTUD. Input `v8/reproducer/input_WB09_3.json`.', '',
     '| besaran | nilai |', '|---|---|',
     '| Cost Production FINAL | %s USD/MWh |' % f(i.get('Cost Production (USD/MWh)')),
     '| Heat Rate JBBK+MM2100 FINAL | %s BTU/kWh |' % f(i.get('JBBK MM Heat Rate (BTU/kWh)'), 2),
     '| Total Cost | %s USD |' % f(i.get('Total Cost (USD)'), 2),
     '| Net Production | %s MWh |' % f(i.get('Net Production (MWh)'), 2),
     '| CP sebelum review Unit Priority (pemenang pipeline/keluarga) | %s USD/MWh |' % f(v.get('cost_production_before')),
     '| CP workbook Daily_Plan_09_Jul_26_Baru(3) | 63.7145 USD/MWh (Heat Rate 8165,81) |',
     '| PGN Pipe Used | %s BBTUD |' % f(i.get('PGN Pipe Used (BBTUD)')),
     '| Startup event GTG | %s |' % i.get('Startup Events (GTG)'), '',
     '## Comparator V11 (band CP 0,2 % + tie-break Heat Rate)', '',
     'CP_min %s, batas atas band %s, kandidat valid %s, di dalam band %s, pemenang **%s** (CP %s, Heat Rate %s).' % (f(b.get('cp_min')), f(b.get('band_upper')), b.get('candidates_valid'), b.get('candidates_in_band'), b.get('winner'), f(b.get('winner_cp')), f(b.get('winner_heat_rate'), 2)), '',
     '| kandidat | CP | ΔCP % | Heat Rate | start | row prioritas rendah | row fragmentation | skor prioritas | band | hasil |', '|---|---|---|---|---|---|---|---|---|---|']
for x in b.get('table') or []:
    L.append('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (x['candidate'], f(x['cp']), f(x['delta_cp_pct'], 4), f(x['heat_rate'], 2), x['starts'], x['low_priority_running_rows'], x['fragmentation_rows'], x['priority_score'], 'ya' if x['in_band'] else 'tidak', x['result']))
L += ['', '## Formula CP dan akun bahan bakar (`V11 CP Audit` = %s)' % ca.get('status'), '',
      'CP = Total Cost / Net Production = %s (tercatat %s). Heat Rate = bahan bakar / produksi = %s (tercatat %s). Isu: %s.' % (f(ca.get('total_cost_over_net')), f(ca.get('cost_production')), f(ca.get('heat_rate_fuel_over_prod'), 2), f(ca.get('heat_rate_jbbk_mm'), 2), ca.get('issues') or 'tidak ada'), '',
      '| akun | terpakai | kunci harga | harga | berharga |', '|---|---|---|---|---|']
for a in ca.get('fuel_accounts') or []:
    L.append('| %s | %s | %s | %s | %s |' % (a['account'], a['used'], a['price_key'], a['price'], 'ya' if a['priced'] else 'TIDAK'))
fp = i.get('Fuel Provenance') or {}; mp = i.get('MM2100 Gas Provenance') or {}
L += ['', 'Fuel Provenance: **%s** (unit-row tanpa sumber sah: %s); MM2100 Gas Provenance: **%s** (sumber %s, terpakai %s / kuota %s BBTUD).' % (fp.get('status'), fp.get('unit_rows_without_legal_source'), mp.get('status'), mp.get('gas_source'), mp.get('mm2100_daily_used_bbtud'), mp.get('mm2100_quota_bbtud')), '',
      '## Dispatch G1 / G5 / G8 / G9 (MW) dan headroom', '',
      '| row | G1 | G5 | S2 | G8 | G9 | S3 | Export | Reserve |', '|---|---|---|---|---|---|---|---|---|']
for k, r in enumerate(rows):
    L.append('| %d | %s | %s | %s | %s | %s | %s | %s | %s |' % (k + 1, r.get('G1'), r.get('G5'), r.get('S2'), r.get('G8'), r.get('G9'), r.get('S3'), r.get('Export_PLN', r.get('EXPORT PLN')), r.get('Spin_Res', r.get('SPINNING RESERVE'))))
open(os.path.join(OUT, 'AUDIT_CP_HEATRATE_V11.md'), 'w').write('\n'.join(L) + '\n')

# ---------- Low-load fragmentation ----------
fa = i.get('V11 Low Load Fragmentation Audit') or {}; h = i.get('Headroom Priority Audit') or {}
L = ['# Audit LOW_LOAD_FRAGMENTATION V11 — reproducer WB09_3', '',
     'Status **%s**; row ditandai %s; temuan (row, unit) %s; tanpa alasan %s. Kandidat konsolidasi: %s.' % (fa.get('status'), fa.get('rows_flagged'), fa.get('findings'), fa.get('unresolved'), json.dumps(fa.get('consolidation_candidates'))), '',
     'Aturan: ' + str(fa.get('rule')), '',
     'Headroom Priority Audit (seluruh 48 row, semua unit): **%s**, temuan %s, tanpa alasan %s.' % (h.get('status'), h.get('flags_total'), h.get('flags_unresolved')), '',
     '| row | unit | MW | unit dekat minimum | headroom unit prioritas lebih tinggi | alasan berbasis bukti |', '|---|---|---|---|---|---|']
for x in fa.get('findings_detail') or []:
    L.append('| %s | %s | %s | %s | %s | %s |' % (x['row'], x['unit'], x['unit_mw'], ', '.join(x.get('near_min_units') or []), json.dumps(x.get('higher_priority_headroom')), '<br>'.join(str(w) for w in x.get('reasons') or [])))
L += ['', '### Unit prioritas rendah: start, minimum runtime, stop pada row legal pertama', '']
for x in fa.get('findings_detail') or []:
    for w in x.get('reasons') or []:
        m = re.search(r'MIN_RUNTIME\(start row (\d+), (\d+) row, stop legal pertama row (\d+)\)', str(w))
        if m:
            U = x['unit']; a = int(m.group(1)); e = a
            while e <= 48 and float(rows[e - 1].get(U) or 0) > 0.01: e += 1
            L.append('- %s: start row %s, minimum runtime %s row, row stop legal pertama %s, stop aktual row %d (%s).' % (U, a, m.group(2), m.group(3), e, 'SESUAI' if e == int(m.group(3)) else 'BERBEDA'))
            break
    else: continue
    break
open(os.path.join(OUT, 'AUDIT_LOW_LOAD_FRAGMENTATION_V11.md'), 'w').write('\n'.join(L) + '\n')
print('ditulis 3 audit ke', OUT)
