#!/usr/bin/env python3
"""PERFORMA V12: median/min/max 3 run (V11 vs V12, VM sama, bergantian). Arg: <dir-prof> <out.md> <out.json>"""
import json, sys, glob, statistics, os, re
D, OUT, OJ = sys.argv[1:4]
def load(p):
    try: return [json.loads(l) for l in open(p) if l.strip().startswith('{')]
    except FileNotFoundError: return []
R = {}   # (ver, sc) -> [rec]
for f in sorted(glob.glob(D + '/*.jsonl')):
    b = os.path.basename(f); m = re.match(r'(v1[12])_(slot|exact|fuel|ops)_(\d+)\.jsonl', b)
    if not m: continue
    ver, grp, i = m.groups()
    for k, r in enumerate(load(f)):
        sc = r['sc']
        if grp == 'fuel': sc = ['FUEL_SHORTAGE_PGN25', 'FUEL_LNG_REKOMENDASI', 'FUEL_LNG_LEBIH', 'FUEL_DISTILLATE', 'FUEL_LNG_KURANG'][k] if k < 5 else sc
        if grp == 'exact': sc = sc + '_DARI_NOL'
        if grp == 'ops': sc = 'OPS_' + os.path.basename(sc).replace('.json', '')
        R.setdefault((ver, sc), []).append(r)
BASEC = {'slot': None, 'q': None}
def csig(ver, sc):
    x = R.get((ver, sc)) or []; return x[0].get('csig') if x else None
def cat(sc, rec, ver):
    if sc.startswith('ACT_') or sc.startswith('KP72'): return ('update slot Actual/Fixed Flow', 5.0)
    if sc.startswith('QA_'):
        b = csig(ver, 'BASE_ACT10_DARI_NOL'); ch = b is not None and rec.get('csig') != b
        return ('kuota basis Actual (commitment berubah)', 15.0) if ch else ('kuota basis Actual (commitment tetap)', 10.0)
    if sc.startswith('Q_pep_kp72'): return ('exact berat', 40.0)
    if sc.startswith('Q_'):
        b = csig(ver, 'BASE_PGN30_DARI_NOL'); ch = b is not None and rec.get('csig') != b
        return ('kuota basis PGN 30 (commitment berubah)', 15.0) if ch else ('kuota basis PGN 30 (commitment tetap)', 10.0)
    if sc.endswith('_DARI_NOL'): return ('exact berat', 40.0)
    if sc == 'FUEL_SHORTAGE_PGN25': return ('Gas Shortage tervalidasi', 20.0)
    if sc.startswith('FUEL_LNG_KURANG'): return (None, None)
    if sc.startswith('OPS_'): return ('commitment berubah (perintah stop G1)', 15.0)
    if sc.startswith('FUEL_'): return ('rerun bahan bakar', 10.0)
    if sc.startswith('WB09'): return ('reproducer (HTTP)', 15.5)
    return (None, None)
def st(v):
    v = [x for x in v if x is not None]
    return (statistics.median(v), min(v), max(v)) if v else (None, None, None)
def f(x, d=2): return '-' if x is None else (('%.' + str(d) + 'f') % x).replace('.', ',')
scs = sorted({sc for (_, sc) in R}, key=lambda s: (cat(s, (R.get(('v12', s)) or [{}])[0], 'v12')[0] or 'z', s))
L = ['# Performa V12 — median / minimum / maksimum tiga run (V11 vs V12, VM sama, bergantian)', '',
     'Mesin: PHP 7.4, 4 inti CPU, 3 pekerja pembantu, server multi-backend meniru Apache; tiap run cold dari snapshot yang sama (basis Actual: FINAL jangkar PGN 30 + BASE_ACT10; "dari nol": direktori job kosong). '
     'Waktu = detik dari request Run sampai FINAL (HTTP). Rantai bahan bakar: satu server warm, urutan popup Gas Shortage PGN 25 -> LNG rekomendasi -> LNG lebih (+2 BBTUD) -> Distillate rekomendasi -> LNG kurang.', '',
     '| rute | kategori (target) | V11 median | V11 min | V11 maks | V12 median | V12 min | V12 maks | V12 lulus | CP V11 | CP V12 | dispatch identik | core run V12 | idle pembantu V12 (s) |',
     '|---|---|---|---|---|---|---|---|---|---|---|---|---|---|']
S = {}; J = []
for sc in scs:
    a = R.get(('v11', sc)) or []; b = R.get(('v12', sc)) or []
    if not b: continue
    c, lim = cat(sc, b[0], 'v12')
    ta = st([x.get('t_final_s') for x in a]); tb = st([x.get('t_final_s') for x in b])
    same = (len({x.get('sig') for x in a + b}) == 1) if a else None
    idle = st([sum((h or {}).get('idle') or 0 for h in (x.get('helper_stats') or []) if h) for x in b])
    ok = (tb[0] is not None and lim is not None and tb[0] <= lim)
    if c: S.setdefault(c, []).append((sc, tb[0], lim, ok, ta[0]))
    L.append('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (sc, '%s (%s s)' % (c, f(lim, 1)) if c else '-', f(ta[0]), f(ta[1]), f(ta[2]), f(tb[0]), f(tb[1]), f(tb[2]),
        ('YA' if ok else 'TIDAK') if c else '-', a[0].get('cp') if a else '-', b[0].get('cp'), {True: 'YA', False: 'TIDAK', None: '-'}[same], b[0].get('core_simulations'), f(idle[0])))
    J.append({'sc': sc, 'cat': c, 'limit': lim, 'v11': ta, 'v12': tb, 'ok': ok, 'cp_v11': a[0].get('cp') if a else None, 'cp_v12': b[0].get('cp'), 'same_dispatch': same,
              'cnt_v12': b[0].get('cnt'), 'core_v12': b[0].get('core_simulations'), 'helper_idle_v12': idle[0], 'computation': b[0].get('computation')})
P = ['', '## Profil per rute V12 (run dengan waktu median)', '',
     'Kolom: kandidat diperiksa/valid (counter satu sumber), Tier 1 dipangkas (review + rute cepat), node keluarga (dihitung/dipakai ulang), '
     'cache hit (core run terisolasi / evaluasi beku / tersusun / kolam kandidat), row kotor, percobaan supplier (pencarian), idle pembantu, '
     'jalur kritis (penanda tahap job, detik).', '',
     '| rute | median (s) | diperiksa/valid | Tier 1 dipangkas | node keluarga | cache hit iso/beku/tersusun/kolam | row kotor | supplier (percobaan/pencarian) | idle pembantu (s) | jalur kritis |',
     '|---|---|---|---|---|---|---|---|---|---|']
for sc in scs:
    b = R.get(('v12', sc)) or []
    if not b: continue
    bs = sorted(b, key=lambda x: x.get('t_final_s') or 0); x = bs[len(bs) // 2]; pr = x.get('prof') or {}
    cn = pr.get('counters') or {}; scr = pr.get('screen') or {}; fa = pr.get('family') or {}; stt = pr.get('stats') or {}; pool = stt.get('v4_pool') or {}
    t1 = (((scr.get('review') or {}).get('prescreened_tier1') or 0) + ((scr.get('fast_route') or {}).get('pruned_tier1') or 0)) if scr else '-'
    idle = sum((h or {}).get('idle') or 0 for h in (x.get('helper_stats') or []) if h)
    steps = (x.get('job_timing') or {}).get('steps') or []
    keep = [s for s in steps if any(k in (s[0] or '') for k in ('BASELINE', 'DECOMMIT', 'PIPELINE_END', 'V12_KELUARGA', 'V12_REVIEW', 'V9_JANGKAR', 'V10_PUSTAKA', 'V10_TIER2B', 'V10_HASIL', 'V7_RERUN', 'SIMULASI_SELESAI'))]
    P.append('| %s | %s | %s/%s | %s | %s (%s/%s) | %s/%s/%s/%s | %s | %s/%s | %s | %s |' % (sc, f(x.get('t_final_s')), cn.get('candidates_checked', '-'), cn.get('candidates_valid', '-'), t1,
        fa.get('family_nodes', '-'), fa.get('family_nodes_computed', '-'), fa.get('family_nodes_reused', '-'),
        stt.get('iso_cache_hit', 0), stt.get('frozen_cache_hit', 0), stt.get('composed_cache_hit', 0), (pool.get('cs_hit_file') or 0) + (pool.get('cs_hit_mem') or 0),
        pr.get('dirty_rows', '-'), stt.get('supplier_attempts', '-'), stt.get('supplier_searches', '-'), f(idle), '; '.join('%s %s' % (s[0].replace('_', ' ').lower()[:20], f(s[1], 1)) for s in keep)))
L += P
L += ['', '## Ringkasan terhadap target V12 (median per rute)', '', '| kategori | rute | lulus | median rute terburuk | target |', '|---|---|---|---|---|']
for c, xs in S.items():
    n = sum(1 for x in xs if x[3]); w = max(xs, key=lambda x: x[1] or 0)
    L.append('| %s | %d | %d/%d | %s %s s | %s s |' % (c, len(xs), n, len(xs), w[0], f(w[1]), f(xs[0][2], 1)))
open(OUT, 'w').write('\n'.join(L) + '\n'); json.dump(J, open(OJ, 'w'), indent=1); print('ditulis', OUT)
