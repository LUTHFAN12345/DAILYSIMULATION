# gen.py : ROOT_CAUSE_BBLN1_BLOCK1_G8G9_MERIT_PROOF.md + TEST_STATUS_BBLN1.md dari hasil nyata
import json, glob, os
B = '/home/claude/ab2/bb'; OUT = '/home/claude/ab2/bbdocs/out'; os.makedirs(OUT, exist_ok=True)
U = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2']
def md(h, rows): return '\n'.join(['| ' + ' | '.join(h) + ' |', '|' + '---|' * len(h)] + ['| ' + ' | '.join('' if c is None else str(c) for c in r) + ' |' for r in rows])
def onmap(rows):
    o = {}
    for u in U:
        rr = [k + 1 for k, r in enumerate(rows) if float(r.get(u) or 0) > 0.01]
        if rr: o[u] = f'{rr[0]}–{rr[-1]}'
    return o
ui = []
for t in ('X1','X2','X3','Y1','Y2','Y3'):
    u = json.load(open(f'{B}/final/{t}_ui.json'))
    for r in u['runs']:
        ui.append(['XAMPP-like (proxy + 6 worker PHP 7.4)' if t[0] == 'X' else 'Linux (Apache 2.4 + PHP-FPM 7.4)', t, 'cold' if r['run'] == 1 else 'warm',
                   str(r['t_rows']).replace('.', ','), r['st'], r['gate'], r['merit'], r['cp'], r['hr'], r['bb1max'], 'aktif' if r['save'] else 'TERKUNCI', r['cand'], r['pay'], 'ya' if r['bound'] else 'TIDAK'])
def tt(n):
    f = f'{B}/ttf/out_{n}.json'
    if not os.path.isfile(f): return None
    d = json.load(open(f)); o = d['output']; i = o.get('info', {}); rv = o.get('simulation_acceptance_review') or {}; mp = i.get('Merit Proof C1-C4 STG', {}); pb = i.get('Proof Binding', {})
    V = d.get('viol') or []
    return {'wall': d['wall_s'], 'hard': d['hard'], 'gate': rv.get('status'), 'blk': ','.join(rv.get('blocking_reasons') or []) or '–', 'merit': mp.get('status'),
            'cf': '/'.join(str((mp.get(c) or {}).get('fail')) for c in ('C1','C2','C3','C4')), 'stg': (mp.get('stg') or {}).get('status'),
            'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'), 'mode': (i.get('Run Status') or {}).get('mode'), 'cand': pb.get('candidate_hash'),
            'on': onmap(o.get('data') or []), 'viol': [v[0] if isinstance(v, list) else str(v) for v in V][:6], 'pgn': i.get('PGN Pipe Used (BBTUD)')}
json.dump({'ui': ui, 'tt': {n: tt(n) for n in ['T0_base','T1_F2_g9_not_required','T1_F1_g9_start_0700','T1_F1_g9_start_1400','T2_ie_plus10','T3_pgn22','T4_g5_unavailable','T5_golden_D0']}}, open(f'{OUT}/results.json', 'w'), indent=1)
print('ok')
R = json.load(open(f'{OUT}/results.json'))
T = R['tt']
def trow(name, label, expect):
    x = T.get(name)
    if not x: return [label, 'BELUM', '', '', '', '', '', '', '', '', expect]
    on = x['on']; com = ', '.join(f'{k} {v}' for k, v in on.items() if k in ('G1','G3','G4','G5','G6','G8','G9','S1','BB1'))
    return [label, str(x['wall']).replace('.', ','), x['hard'], f"{x['gate']} {x['blk'] if x['gate'] != 'PASS' else ''}".strip(), x['merit'], x['cf'], x['stg'], x['cp'], x['hr'], com + (' · BB1 0 MW' if 'BB1' not in on else ' · BB1 >0 !'), expect]
H = ['Uji', 'CLI (s)', 'Hard', 'Gate', 'Merit', 'C1/C2/C3/C4 FAIL', 'STG', 'CP', 'HR', 'Commitment (row)', 'Catatan']
TT = [trow('T0_base', 'T0 BBLN1 Stop All Day + Block 1 + G8/G9 (payload UI)', 'acuan'),
      trow('T1_F2_g9_not_required', 'T1 F0: Block 1 + G8 saja (G9 Required dilepas)', 'engine tetap start G9 row 2 → G9 diperlukan'),
      trow('T1_F1_g9_start_0700', 'T1 F1: G9 dipaksa start 07:00', 'pembanding'),
      trow('T1_F1_g9_start_1400', 'T1 F1: G9 dipaksa start 14:00', 'pembanding'),
      trow('T2_ie_plus10', 'T2 IE Adjustment +10 MW 10:00–12:00 (warm sesudah T0)', 'jalur IE warm'),
      trow('T3_pgn22', 'T3 PGN Pipe −1 (22 BBTUD)', 'cold'),
      trow('T4_g5_unavailable', 'T4 G5 (unit startup terpilih) unavailable', 'fallback legal'),
      trow('T5_golden_D0', 'T5 golden smoke (D0_golden, Fastest)', 'acuan CP 79,9049')]
UIH = ['Platform', 'Sesi', 'Run', 'Klik→48 row FINAL (s)', 'Status', 'Gate', 'Merit', 'CP', 'HR', 'BB1 maks', 'Save/Export', 'candidate_hash', 'payload_hash', 'Proof terikat']
txt = open('/home/claude/ab2/bbdocs/root_cause_template.md').read()
txt = txt.replace('{{UI_TABLE}}', md(UIH, R['ui'])).replace('{{TT_TABLE}}', md(H, TT))
open(f'{OUT}/ROOT_CAUSE_BBLN1_BLOCK1_G8G9_MERIT_PROOF.md', 'w').write(txt)
open(f'{OUT}/TEST_STATUS_BBLN1.md', 'w').write('# TEST STATUS — BBLN1 Stop All Day (V15.17b)\n\nSumber: `ROOT_CAUSE_BBLN1_BLOCK1_G8G9_MERIT_PROOF.md` §7–§8.\n\n## T0 lewat UI nyata (3 cold + 3 warm per platform)\n' + md(UIH, R['ui']) + '\n\n## T0–T5 (CLI PHP 7.4, source freeze, berurutan tanpa beban paralel)\n' + md(H, TT) + '\n')
print('docs ok')
