#!/usr/bin/env python3
"""Paritas merit 12 state vs checkpoint. Arg: <ref COLD.jsonl> <baru dir merit> <out.md>
PASS bila identik; PASS_UNIT_PRIORITY bila rencana baru men-start unit berprioritas lebih tinggi (Unit Priority pengguna) dengan CP di
dalam band 0,2 % CP referensi; PASS_NUMERIK bila MW seluruh GTG identik dan CP sama (4 desimal) — efek turunan (mis. jangkar berubah).
Selain itu FAIL."""
import json, sys, os, glob
REF, NEW, OUT = sys.argv[1:4]
RD = os.path.join(os.path.dirname(REF), 'out_cold')
def rd(p): return {json.loads(l)['sc']: json.loads(l) for l in open(p) if l.startswith('{')} if os.path.exists(p) else {}
def outf(d, sc):
    pre = sc.replace(':', '_').replace(';', '_').replace('=', '=')
    for f in glob.glob(os.path.join(d, '*.json')):
        b = os.path.basename(f).rsplit('_', 1)[0]
        if b == ''.join(c if c.isalnum() or c in '=_-.' else '_' for c in sc)[:len(b)]: return f
    return None
def load(f):
    d = json.load(open(f)); return d.get('output', d), d.get('input')
GT = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10']
def ranks(*outs):
    rank = {}
    for o in outs:
        for rw in ((o.get('info') or {}).get('V12 Dispatch Merit Audit') or {}).get('rows') or []:
            for e in rw.get('units') or []:
                if e.get('priority_rank') is not None: rank[e['unit'].lower()] = int(e['priority_rank'])
    return rank
def starts(o, inp, rank):
    m = ((inp or {}).get('data3') or {}).get('modeling') or {}
    lds = m.get('unit_last_data_status') or {}; s = []
    if not lds: lds = {e['unit']: 'running' for e in (((o.get('info') or {}).get('V12 Dispatch Merit Audit') or {}).get('rows') or [{}])[0].get('units', []) if 'LAST_DATA_RUNNING' in (e.get('status') or [])}
    for U in GT:
        prev = str(lds.get(U, '')).lower() == 'running'
        for r in o['data']:
            on = float(r.get(U) or 0) > 0.01
            if on and not prev: s.append(rank.get(U.lower(), 99))
            prev = on
    return sorted(s, reverse=True)
A = rd(REF); L = ['# Paritas merit dispatch 12 state vs checkpoint integrasi', '', 'Referensi: run cold checkpoint integrasi (source merit `a905f10`). Baru: source beku. Dibandingkan: dispatch fisik 48 row, CP, Heat Rate untuk cold, tanpa pembantu, dan warm. Perubahan disengaja V2: di dalam band CP 0,2 %, Unit Priority start (pengguna) didahulukan sebelum Heat Rate.', '',
     '| state | ref sig / CP / HR | cold | tanpa pembantu | warm | hasil |', '|---|---|---|---|---|---|']
B = {k: rd(os.path.join(NEW, k + '.jsonl')) for k in ['COLD', 'NOHELP', 'WARM']}
for sc, a in A.items():
    ref = (a.get('sig'), a.get('cp'), a.get('hr')); cells = []; same_modes = True; got0 = None
    for k in ['COLD', 'NOHELP', 'WARM']:
        b = B[k].get(sc, {}); got = (b.get('sig'), b.get('cp'), b.get('hr')); got0 = got0 or got; same_modes = same_modes and got == got0
        cells.append('identik' if got == ref else '%s / %s / %s' % got)
    if all(c == 'identik' for c in cells): res = 'PASS'; why = 'identik'
    elif not same_modes: res = 'FAIL'; why = 'cold/tanpa pembantu/warm berbeda satu sama lain'
    else:
        fr = outf(RD, sc); fn = outf(os.path.join(NEW, 'out_cold'), sc); res = 'FAIL'; why = 'berkas keluaran tidak ditemukan'
        if fr and fn:
            (oa, ia), (ob, ib) = load(fr), load(fn)
            rk = ranks(oa, ob); sa, sb = starts(oa, ia, rk), starts(ob, ib or ia, rk)
            mw_same = all(abs(float(oa['data'][r].get(U) or 0) - float(ob['data'][r].get(U) or 0)) < 1e-6 for r in range(48) for U in GT)
            cpa, cpb = float(ref[1]), float(got0[1])
            if sb != sa and sb < sa and cpb <= cpa * 1.002 + 1e-9: res = 'PASS_UNIT_PRIORITY'; why = 'start rank %s -> %s (unit prioritas lebih tinggi), CP %.4f -> %.4f (%+.4f %%)' % (sa, sb, cpa, cpb, 100 * (cpb - cpa) / cpa)
            elif mw_same and abs(cpa - cpb) < 1e-4: res = 'PASS_NUMERIK'; why = 'MW seluruh GTG identik, CP sama; HR %s -> %s (efek turunan jangkar/pendaratan)' % (ref[2], got0[2])
            else: why = 'start rank %s -> %s, CP %.4f -> %.4f, MW GTG identik=%s' % (sa, sb, cpa, cpb, mw_same)
    L.append('| %s | %s / %s / %s | %s | %s | %s | %s: %s |' % (sc.replace('|', '/')[:70], ref[0], ref[1], ref[2], cells[0], cells[1], cells[2], res, why))
    print(('PASS' if res.startswith('PASS') else 'FAIL') + '  MERIT_PARITY %s %s  -- %s' % (sc[:60], res, why))
open(OUT, 'w').write('\n'.join(L) + '\n')
