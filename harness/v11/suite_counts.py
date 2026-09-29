#!/usr/bin/env python3
"""Hitung PASS/FAIL per suite dari folder laporan (targeted / regression / clean-extract) + log runner.
Arg: <folder-laporan> [<log>] ; cetak tabel markdown dan total. Exit 0 selalu (laporan)."""
import re, sys, os, glob
D = sys.argv[1]; LOG = sys.argv[2] if len(sys.argv) > 2 else None
rows = []
def md_count(p):
    t = open(p, encoding='utf-8', errors='replace').read()
    m = re.search(r'PASS (\d+) / (\d+)', t)
    if m: return int(m.group(1)), int(m.group(2)) - int(m.group(1))
    m = re.search(r'\*\*(\d+)/(\d+)\*\*', t)
    if m: return int(m.group(1)), int(m.group(2)) - int(m.group(1))
    p_ = len(re.findall(r'\| (LULUS|PASS) \|', t)); f_ = len(re.findall(r'\| (GAGAL|FAIL) \|', t))
    if p_ + f_: return p_, f_
    p_ = len(re.findall(r'^PASS\b', t, re.M)); f_ = len(re.findall(r'^FAIL\b', t, re.M))
    return p_, f_
for p in sorted(glob.glob(os.path.join(D, '*.md'))):
    n = os.path.basename(p)[:-3]
    if n in ('CANDIDATE_SCREENING', 'PATH_INDEPENDENCE'): continue
    ps, fs = md_count(p)
    if ps + fs: rows.append((n, ps, fs))
pi = os.path.join(D, 'PATH_INDEPENDENCE.md')
if os.path.exists(pi):
    t = open(pi).read(); m = re.search(r'\*\*(\d+)/(\d+) state identik', t)
    if m: rows.append(('PATH_INDEPENDENCE (state identik)', int(m.group(1)), int(m.group(2)) - int(m.group(1))))
extra = []
if LOG and os.path.exists(LOG):
    L = open(LOG, encoding='utf-8', errors='replace').read()
    for k in ('HASH TIDAK BERUBAH SELAMA REGRESSION', 'HASH BERUBAH!', 'IDENTIK DENGAN FREEZE', 'DETERMINISME_IDENTIK', 'DETERMINISME_BEDA', 'JS OK'):
        if k in L: extra.append(k)
    fl = [l for l in L.splitlines() if l.startswith('FAIL')]
    extra.append('baris FAIL di log: %d' % len(fl))
tp = sum(r[1] for r in rows); tf = sum(r[2] for r in rows)
print('| suite | PASS | FAIL |'); print('|---|---|---|')
for n, ps, fs in rows: print('| %s | %d | %d |' % (n, ps, fs))
print('| **total** | **%d** | **%d** |' % (tp, tf))
for e in extra: print('\n- ' + e)
