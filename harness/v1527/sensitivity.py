"""V15.27 (objective ekonomi dulu: headroom prioritas tinggi = tie-break sesudah CP ekonomi / CP resmi / HR, sama dengan run.php).
sensitivity.py <runs_dir> <out.md> : uji sensitivitas objective final (pp_sp_final_cmp) dan comparator konsolidasi (pp_v1526_consol_cmp).
Untuk setiap himpunan kandidat yang tercatat di output run (Maximum Review vs Fastest, Block Consolidation Review), pemenang dihitung ulang
dengan toleransi yang digeser (band CP resmi, toleransi CP ekonomi, toleransi headroom prioritas tinggi). Pemenang yang berubah dilaporkan
beserta selisih metriknya, sehingga terlihat apakah keputusan bergantung pada konstanta toleransi atau pada selisih yang material."""
import json, glob, os, sys, functools

def final_cmp(a, b, band=0.002, econ=1e-4, head=0.05):
    for k in ('hard_pass', 'release_pass', 'stg_pass'):
        if a[k] != b[k]: return -1 if a[k] > b[k] else 1
    for k in ('c1_c4_fail', 'hpa_unresolved', 'priority_penalty'):
        if a[k] != b[k]: return -1 if a[k] < b[k] else 1
    if abs(float(a['econ_cp']) - float(b['econ_cp'])) > econ: return -1 if a['econ_cp'] < b['econ_cp'] else 1
    ca, cb = float(a['cp']), float(b['cp'])
    if abs(ca - cb) > band * min(ca, cb): return -1 if ca < cb else 1
    if abs(float(a['hr']) - float(b['hr'])) > 1e-6: return -1 if a['hr'] < b['hr'] else 1
    if abs(ca - cb) > 1e-6: return -1 if ca < cb else 1
    if abs(a['unused_legal_hp_headroom_mw'] - b['unused_legal_hp_headroom_mw']) > head: return -1 if a['unused_legal_hp_headroom_mw'] < b['unused_legal_hp_headroom_mw'] else 1
    for k in ('startups', 'online_unit_rows', 'fragmentation'):
        if a[k] != b[k]: return -1 if a[k] < b[k] else 1
    if a['start_rows_sum'] != b['start_rows_sum']: return -1 if a['start_rows_sum'] > b['start_rows_sum'] else 1
    return (str(a['fingerprint']) > str(b['fingerprint'])) - (str(a['fingerprint']) < str(b['fingerprint']))

def consol_cmp(a, b, band=0.002, econ=1e-4, head=0.05):
    if int(a.get('priority_unresolved', 0)) != int(b.get('priority_unresolved', 0)): return -1 if a['priority_unresolved'] < b['priority_unresolved'] else 1
    ha, hb = float(a.get('unused_high_priority_headroom_mw', 0)), float(b.get('unused_high_priority_headroom_mw', 0))
    if abs(float(a['econ_cp']) - float(b['econ_cp'])) > econ: return -1 if a['econ_cp'] < b['econ_cp'] else 1
    ca, cb = float(a['cp']), float(b['cp'])
    if abs(ca - cb) > band * min(ca, cb): return -1 if ca < cb else 1
    if abs(float(a['hr']) - float(b['hr'])) > 1e-6: return -1 if a['hr'] < b['hr'] else 1
    if abs(ca - cb) > 1e-6: return -1 if ca < cb else 1
    if abs(ha - hb) > head: return -1 if ha < hb else 1
    for k in ('startups', 'online_unit_rows', 'low_load_fragmentation'):
        if int(a.get(k, 0)) != int(b.get(k, 0)): return -1 if a.get(k, 0) < b.get(k, 0) else 1
    if int(a.get('start_rows_sum', 0)) != int(b.get('start_rows_sum', 0)): return -1 if a['start_rows_sum'] > b['start_rows_sum'] else 1
    return (str(a.get('fingerprint')) > str(b.get('fingerprint'))) - (str(a.get('fingerprint')) < str(b.get('fingerprint')))

GRID = [('default', {}), ('band 0,1%', {'band': 0.001}), ('band 0,5%', {'band': 0.005}), ('band 1%', {'band': 0.01}),
        ('econ tol 0,01', {'econ': 0.01}), ('econ tol 0,05', {'econ': 0.05}), ('headroom tol 1 MW', {'head': 1.0}), ('headroom tol 5 MW', {'head': 5.0})]

def winner(cands, cmp, kw):
    return sorted(cands, key=functools.cmp_to_key(lambda x, y: cmp(x[1], y[1], **kw)))[0][0]

sets = []
for f in sorted(glob.glob(os.path.join(sys.argv[1], '*.json'))):
    if f.endswith('.in.json'): continue
    try: r = json.load(open(f)); i = (r.get('output') or r).get('info') or {}
    except Exception: continue
    x = i.get('Maximum Review vs Fastest') or {}
    if x.get('status') == 'COMPARED':
        c = [(k, v['final_objective']) for k, v in (('FASTEST_CANDIDATE', x.get('fastest') or {}), ('MAXIMUM_REVIEW', x.get('maximum_review') or {})) if v.get('publishable') and v.get('final_objective')]
        if len(c) > 1: sets.append((os.path.basename(f), 'MR_vs_FASTEST', final_cmp, c))
    bc = i.get('Block Consolidation Review') or {}
    c = [(k['id'], k['rank']) for k in bc.get('candidates', []) if k.get('valid') and isinstance(k.get('rank'), dict) and 'econ_cp' in k['rank']]
    if len(c) > 1 and bc.get('comparator') and 'econ_cp' in bc.get('comparator'): sets.append((os.path.basename(f), 'CONSOLIDATION', consol_cmp, c))

L = ['# Uji sensitivitas objective final', '', f'Himpunan kandidat: {len(sets)} (MR vs Fastest: {sum(1 for s in sets if s[1] == "MR_vs_FASTEST")}, konsolidasi: {sum(1 for s in sets if s[1] == "CONSOLIDATION")})', '',
     '| variasi | pemenang berubah | detail |', '|---|---|---|']
flipsAll = 0
for name, kw in GRID:
    flips = []
    for f, kind, cmp, c in sets:
        w0 = winner(c, cmp, {}); w1 = winner(c, cmp, kw)
        if w0 != w1:
            a = dict(c)[w0]; b = dict(c)[w1]
            flips.append(f"{f[:14]} {kind}: {w0} -> {w1} (cp {a['cp']} vs {b['cp']}, econ {a['econ_cp']} vs {b['econ_cp']})")
    if name != 'default': flipsAll += len(flips)
    L.append(f"| {name} | {len(flips)}/{len(sets)} | {'; '.join(flips[:6])}{' ...' if len(flips) > 6 else ''} |")
L += ['', 'Pemenang yang berubah hanya ketika toleransi melebihi selisih metrik yang sebenarnya; keputusan default tidak bergantung urutan atau cache (comparator total order dengan sidik jari).']
open(sys.argv[2], 'w').write('\n'.join(L) + '\n'); print(len(sets), 'sets;', flipsAll, 'flips across variations')
