"""ag_summary.py <ag_results.json> <out.md> : ringkasan suite Actual Gas dua arah (harness/v1526/actual_gas/ag_tests.py) — warm vs dingin, actual immutable,
warm ulang identik, arah CP pasangan lebih tinggi / lebih rendah dari input yang sama."""
import json, sys, re
R = json.load(open(sys.argv[1])); L = []
L += ['# Actual Gas dua arah — V15.27', '', '| kasus | arah | status warm | publish | CP basis -> warm | dingin == warm | actual immutable | warm ulang identik | warm s | dingin s |', '|---|---|---|---|---|---|---|---|---|---|']
n = {'cases': 0, 'pub': 0, 'eq': 0, 'imm': 0, 'rep': 0}; ws = []
for r in R:
    w = r.get('warm') or {}; c = r.get('cold') or {}
    eq = w.get('dispatch_hash') == c.get('dispatch_hash') and w.get('cp') == c.get('cp') and w.get('status') == c.get('status')
    n['cases'] += 1; n['pub'] += bool(w.get('publish')); n['eq'] += eq; n['imm'] += bool(w.get('actual_rows_immutable')); n['rep'] += bool(r.get('repeatable_warm'))
    if w.get('wall_s') is not None: ws.append(w['wall_s'])
    L.append('| %s | %s | %s | %s | %s -> %s | %s | %s | %s | %s | %s |' % (r['tag'], r.get('direction'), w.get('status'), w.get('publish'), w.get('basis_cp'), w.get('cp'),
             'ya' if eq else 'TIDAK', 'ya' if w.get('actual_rows_immutable') else 'TIDAK', 'ya' if r.get('repeatable_warm') else 'TIDAK', w.get('wall_s'), c.get('wall_s')))
# arah pasangan: tag ..._hiXX_.. vs ..._loXX_.. dengan konteks & jam sama -> CP(hi) > CP(lo) bila keduanya publish
by = {r['tag']: r for r in R}; pairs = []
for t, r in by.items():
    m = re.search(r'_hi(\d+)_', t)
    if not m: continue
    t2 = t.replace('_hi%s_' % m.group(1), '_lo%s_' % m.group(1)); r2 = by.get(t2)
    if not r2: continue
    a, b = (r.get('warm') or {}), (r2.get('warm') or {})
    if a.get('publish') and b.get('publish'): pairs.append((t, a.get('cp'), b.get('cp'), (a.get('cp') or 0) > (b.get('cp') or 0)))
ws.sort()
L += ['', 'Ringkasan: %d kasus, %d publish, dingin == warm %d/%d, actual immutable %d/%d, warm ulang identik %d/%d; warm median %.2f s, maks %.2f s; <= 5 s: %d/%d.'
      % (n['cases'], n['pub'], n['eq'], n['cases'], n['imm'], n['cases'], n['rep'], n['cases'], ws[len(ws) // 2] if ws else 0, ws[-1] if ws else 0, sum(1 for x in ws if x <= 5.0), len(ws)), '',
      'Pasangan input tunggal (lebih tinggi vs lebih rendah, keduanya publish): ' + ', '.join('%s CP %s > %s %s' % (t.split('__')[1], a, b, 'ya' if ok else 'TIDAK') for t, a, b, ok in pairs)]
open(sys.argv[2], 'w').write('\n'.join(L) + '\n'); print('\n'.join(L[-3:]))
