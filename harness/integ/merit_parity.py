#!/usr/bin/env python3
"""Paritas merit 12 state: dispatch (sig), CP, Heat Rate vs referensi checkpoint. Arg: <ref COLD.jsonl> <baru dir merit> <out.md>"""
import json, sys, os
REF, NEW, OUT = sys.argv[1:4]
def rd(p): return {json.loads(l)['sc']: json.loads(l) for l in open(p) if l.startswith('{')} if os.path.exists(p) else {}
A = rd(REF); L = ['# Paritas merit dispatch 12 state vs checkpoint integrasi (merit PASS)', '', 'Referensi: run cold checkpoint integrasi (source merit `a905f10`). Baru: source beku final. Dibandingkan: tanda tangan dispatch fisik 48 row, CP, Heat Rate — untuk cold, tanpa pembantu, dan warm.', '',
     '| state | ref sig / CP / HR | cold | tanpa pembantu | warm | hasil |', '|---|---|---|---|---|---|']
npass = 0; n = 0
B = {k: rd(os.path.join(NEW, k + '.jsonl')) for k in ['COLD', 'NOHELP', 'WARM']}
for sc, a in A.items():
    ref = (a.get('sig'), a.get('cp'), a.get('hr')); cells = []; ok = True
    for k in ['COLD', 'NOHELP', 'WARM']:
        b = B[k].get(sc, {}); got = (b.get('sig'), b.get('cp'), b.get('hr')); same = got == ref; ok = ok and same
        cells.append(('identik' if same else 'BEDA %s / %s / %s' % got))
    n += 1; npass += ok
    L.append('| %s | %s / %s / %s | %s | %s | %s | %s |' % (sc.replace('|', '/')[:70], ref[0], ref[1], ref[2], cells[0], cells[1], cells[2], 'PASS' if ok else 'FAIL'))
    print(('PASS' if ok else 'FAIL') + '  MERIT_PARITY %s cold/nohelp/warm identik checkpoint  -- %s' % (sc[:60], ' | '.join(cells)))
L += ['', '%d/%d state identik pada ketiga mode.' % (npass, n)]
open(OUT, 'w').write('\n'.join(L) + '\n')
