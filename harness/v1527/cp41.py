"""V15.27 audit 41 test yang CP-nya naik pada V15.26 terhadap baseline V15.25: python3 -I cp41.py <evd_v1527> [out.md] [evd_override]
(evd_override: run ulang pada source final untuk test yang di matrix memakai jalur warm IE; dipakai bila ada)
Kolom: CP/HR V15.25 (baseline), V15.26 (sebelum perbaikan), V15.27 (evd), delta terhadap baseline, kategori root cause A-G.
Kategori kenaikan V15.26 (dianalisis per stage, lihat REPORT.md):
  A comparator menempatkan soft priority (inversi) di atas CP ekonomi  -> diperbaiki V15.27 (objective ekonomi dulu)
  D redispatch priority berulang / closure tidak berjalan               -> diperbaiki V15.27 (kandidat prioritas closure)
  E urutan kandidat / local search (konsolidasi, tightening, closure) -> diperbaiki V15.27 (titik awal tightening ganda dinilai sesudah 2+2 +
                                                                       Unit Priority Fix; closure menilai langkah terbaik dua urutan); sisa dilaporkan
  F hasil bergantung riwayat cache (jalur warm IE / Fix Load)          -> diperbaiki V15.27 (IE / Fix Load berubah -> review commitment penuh)
  G konsekuensi objective user (startup otomatis tak perlu dieliminasi sebelum CP)
Status V15.27: FIXED bila CP <= baseline + 0,0005 USD/MWh."""
import json, os, sys
HERE = os.path.dirname(os.path.abspath(__file__)); ROOT = os.path.dirname(HERE)
EVD = sys.argv[1]; OUT = sys.argv[2] if len(sys.argv) > 2 else None; OVR = sys.argv[3] if len(sys.argv) > 3 else None
T41 = ('T004 T007 T008 T011 T012 T016 T022 T031 T034 T046 T047 T050 T051 T057 T059 T068 T074 T080 T081 T087 T092 T096 T100 T107 T110 T114 '
       'T126 T136 T157 T166 T179 T196 T199 T200 T202 T203 T204 T205 T216 T217 T218').split()
# root cause V15.26 per test (analisis stage-by-stage V15.25 vs V15.26/V15.27, /tmp/w/stagecmp.py)
CAUSE = {t: 'A' for t in T41}
CAUSE.update({'T050': 'D', 'T114': 'D', 'T200': 'D', 'T046': 'E', 'T057': 'E', 'T199': 'E', 'T202': 'E', 'T047': 'E', 'T136': 'G', 'T216': 'E+F'})
def ld(p):
    try: return json.load(open(p))
    except Exception: return None
rows = []; nfix = 0
for t in T41:
    a = ld(os.path.join(ROOT, 'v1525', 'evidence', 'matrix', t + '.json')) or {}
    b = ld(os.path.join(ROOT, 'v1526', 'evidence', 'matrix', t + '.json')) or {}
    c = (ld(os.path.join(OVR, t + '.json')) if OVR else None) or ld(os.path.join(EVD, t + '.json')) or {}
    ca, cb, cc = a.get('cp'), b.get('cp'), c.get('cp')
    d = round(cc - ca, 4) if (ca is not None and cc is not None) else None
    fixed = d is not None and d <= 0.0005; nfix += fixed
    rows.append('| %s | %s | %s / %s | %s / %s | %s / %s | %s | %s | %s |' % (t, c.get('status'), ca, a.get('hr'), cb, b.get('hr'), cc, c.get('hr'), d,
                CAUSE[t], 'FIXED' if fixed else 'TERBUKA'))
hdr = ['# Audit 41 test CP naik (V15.26 vs V15.25) — status V15.27', '', 'FIXED %d/41.' % nfix, '',
       '| Test | Status | CP / HR V15.25 | CP / HR V15.26 | CP / HR V15.27 | dCP vs V15.25 | Kategori | V15.27 |', '|---|---|---|---|---|---|---|---|']
txt = '\n'.join(hdr + rows) + '\n'
if OUT: open(OUT, 'w').write(txt)
print(txt)
