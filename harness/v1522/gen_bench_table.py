#!/usr/bin/env python3
"""gen_bench_table.py <bench_dir> : markdown table min/median/max per (build, payload, path, kind) + result identity."""
import json, sys, glob, os, statistics as st, re
D = sys.argv[1]; rows = {}
for f in sorted(glob.glob(os.path.join(D, '*.json'))):
    r = json.load(open(f)); lab = r.get('label') or os.path.basename(f)[:-5]
    m = re.match(r'(ZIP|FIX)-(.+?)-(srv-cold|srv-warm|srv-cached|cli)\d*$', lab)
    if not m: continue
    b, pay, kind = m.groups(); rows.setdefault((pay, kind), {}).setdefault(b, []).append(r)
print('| Payload | Jalur | Build | n | min (s) | median (s) | max (s) | CP | HR | status | core sim | tasks/workers | sig |')
print('|---|---|---|---|---|---|---|---|---|---|---|---|---|')
summ = {}
for (pay, kind), bb in sorted(rows.items()):
    for b in ('ZIP', 'FIX'):
        L = bb.get(b) or []
        if not L: continue
        w = [float(x['wall']) for x in L]; x0 = L[0]
        sigs = sorted(set(x.get('sig') for x in L))
        tw = f"{x0.get('tasks')}/{x0.get('workers')}" if kind.startswith('srv') else '-'
        print(f"| {pay} | {kind} | {b} | {len(L)} | {min(w):.2f} | {st.median(w):.2f} | {max(w):.2f} | {x0.get('cp')} | {x0.get('hr')} | {x0.get('status')} | {x0.get('core_simulations')} | {tw} | {','.join(s or '-' for s in sigs)} |")
        summ[(pay, kind, b)] = st.median(w)
print()
print('| Payload | Jalur | median ZIP | median FIX | perubahan |')
print('|---|---|---|---|---|')
for (pay, kind), bb in sorted(rows.items()):
    z, f = summ.get((pay, kind, 'ZIP')), summ.get((pay, kind, 'FIX'))
    if z and f: print(f"| {pay} | {kind} | {z:.2f} | {f:.2f} | {100 * (f - z) / z:+.1f}% |")
