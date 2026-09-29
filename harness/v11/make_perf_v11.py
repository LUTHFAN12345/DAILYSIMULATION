#!/usr/bin/env python3
"""PERFORMA_V11.md: runtime & CP V9 / V10 / V11 pada battery dan VM yang sama (clean-extract masing-masing versi).
Arg: <ce-v10> <ce-v9> <ce-v8> <out.md>"""
import json, re, sys, statistics
CE, V10, V9, OUTF = sys.argv[1:5]

def load(p):
    try: return [json.loads(l) for l in open(p) if l.strip().startswith('{')]
    except FileNotFoundError: return []
def f(x, d=2):
    if x is None or x == '': return '-'
    try: return (('%.' + str(d) + 'f') % float(x)).replace('.', ',')
    except Exception: return str(x)
def first(rows, key='sc'):
    d = {}
    for r in rows: d.setdefault(r.get(key), r)
    return d

O = []; P = O.append
P('# Performa V11 — runtime V9 / V10 / V11\n')
P('Mesin uji V11: PHP 7.4, 4 inti CPU (3 pekerja pembantu); angka V9/V10 berasal dari baseline clean-extract masing-masing (2 inti, 1 pembantu) sehingga hanya indikatif, server multi-backend yang meniru Apache. Setiap versi diukur dari '
  'clean-extract ZIP finalnya pada battery yang sama. Waktu = detik sejak klik Run sampai hasil valid pertama / FINAL '
  '(HTTP, tanpa CMD/runner). CP = Cost Production USD/MWh FINAL.\n')

TG = []   # (kategori, skenario, nilai, batas, lulus)
def http_table(title, name, cat=None, lim=None, limf=None, warm=False):
    a = load(CE + '/' + name + '.jsonl'); b = load(V10 + '/' + name + '.jsonl'); c = load(V9 + '/' + name + '.jsonl')
    if not a: return
    P('## ' + title + '\n')
    P('| skenario | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | Δ CP V11−V10 | jalur V11 |')
    P('|---|---|---|---|---|---|---|---|---|---|')
    bb = first(b); cc = first(c)
    for k, r in enumerate(a):
        if warm:
            v = b[k] if k < len(b) and b[k].get('sc') == r['sc'] else {}
            w = c[k] if k < len(c) and c[k].get('sc') == r['sc'] else {}
        else:
            v = bb.get(r['sc']) or {}; w = cc.get(r['sc']) or {}
        d = '-'
        try: d = f(float(r['cp']) - float(v['cp']), 4)
        except Exception: pass
        P('| %s | %s s | %s | %s s | %s | %s s | %s s | %s | %s | %s |' % (r['sc'], f(w.get('t_final_s')), f(w.get('cp'), 4), f(v.get('t_final_s')), f(v.get('cp'), 4),
          f(r.get('t_first_valid_s')), f(r.get('t_final_s')), f(r.get('cp'), 4), d, r.get('computation') or '-'))
        cc_ = cat(r) if callable(cat) else cat
        if cc_ and r.get('t_final_s') is not None and r.get('status') not in ('USER_ACTION_REQUIRED', 'USER_FUEL_DECISION_REQUIRED'):
            L = lim(r) if callable(lim) else lim
            if L: TG.append((cc_, r['sc'], r['t_final_s'], L, r['t_final_s'] <= L[1]))
            if limf and cc_.startswith('update') and r.get('t_first_valid_s') is not None: TG.append((cc_ + ' (valid pertama)', r['sc'], r['t_first_valid_s'], limf, r['t_first_valid_s'] <= limf[1]))
    P('')

def slotcat(r):
    s = r['sc']
    if s.startswith('QA_'): return 'kuota ±1..4 (basis Actual, termasuk FINAL jangkar baru)'
    if s in ('ACT_PGN_BIG',): return None
    return 'update Actual / FF / KP72 satu slot'
def slotlim(r):
    return (10, 10) if r['sc'].startswith('QA_') else (5, 5)
http_table('1. Update Simulation Data / kuota dari basis FINAL Actual 11 jam (HTTP, cold)', 'SLOT_COLD', slotcat, slotlim, (2, 2))
http_table('2. Transisi berantai (HTTP, warm)', 'SLOT_WARM', slotcat, slotlim, (2, 2), warm=True)
def qcat(r):
    s = r['sc']
    if s in ('BASE_PGN30',): return None
    if 'PEP32' in s or 'kp72' in s: return 'exact berat'
    return 'kuota ±1..4 (basis PGN 30)'
def qlim(r):
    s = r['sc']
    return (40, 40) if ('PEP32' in s or 'kp72' in s) else (10, 10)
http_table('3. Kuota dari basis PGN 30 tanpa Actual (HTTP)', 'QUOTA_COLD', qcat, qlim)
http_table('4. Kuota berantai (HTTP, warm)', 'QUOTA_WARM', qcat, qlim, warm=True)
http_table('5. Exact penuh state Actual dari nol (HTTP)', 'EXACT_ACT10', 'exact berat', (40, 40), warm=True)
http_table('6. Event berat: status unit, Mandatory Stop, Skip/Fix Load, Change Over (HTTP, warm)', 'FEATURES', None, None, warm=True)

# UI browser
def ui_table(title, name, cat=None, lim=None):
    a = load(CE + '/' + name + '.jsonl'); b = first(load(V10 + '/' + name + '.jsonl'), 'id'); c = first(load(V9 + '/' + name + '.jsonl'), 'id')
    if not a: return
    P('## ' + title + '\n')
    P('| kasus | V9 akhir | CP V9 | V10 akhir | CP V10 | V11 valid pertama | V11 akhir | CP V11 | hasil V11 |'); P('|---|---|---|---|---|---|---|---|---|')
    for x in a:
        v = b.get(x['id']) or {}; w = c.get(x['id']) or {}
        def tf(z): t = z.get('tFin') or z.get('t'); return t / 1000 if t else None
        def cp(z): return z.get('cp') if z.get('cp') is not None else (z.get('snap') or {}).get('cp')
        P('| %s | %s s | %s | %s s | %s | %s s | %s s | %s | %s |' % (x['id'], f(tf(w)), f(cp(w), 4), f(tf(v)), f(cp(v), 4), f(x['tVal'] / 1000 if x.get('tVal') else None), f(tf(x)), f(cp(x), 4), x.get('kind')))
        cc_ = cat(x) if callable(cat) else cat
        if cc_ and tf(x) is not None and x.get('kind') == 'FINAL':
            L = lim(x) if callable(lim) else lim
            if L: TG.append((cc_, x['id'], tf(x), L, tf(x) <= L[1]))
    P('')
ui_table('7. UI browser: perubahan Simulation Data per slot', 'SLOT_UI', lambda x: None if x['id'] == 'BASE' else 'UI update slot', (5, 5))
ui_table('8. UI browser: perubahan kuota — basis Actual 11 jam', 'QUOTA_UI_ACT')
ui_table('9. UI browser: perubahan kuota — basis PGN 30 tanpa Actual', 'QUOTA_UI_PGN30')
ui_table('10. UI browser: forced stop/start dan Change Over (commitment berubah)', 'OPS_UI', lambda x: 'commitment berubah (UI)' if x.get('kind') == 'FINAL' else None, (15, 15))
ui_table('11. UI browser: reproducer Daily_Plan_09_Jul_26_Baru', 'WB09_UI', lambda x: 'reproducer UI' if x['id'] == 'WB09' else None, (15.5, 15.5))
ui_table('11b. UI browser: reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3)', 'WB09_3_UI', lambda x: 'reproducer UI WB09_3' if x['id'] == 'WB09_3' else None, (15.5, 15.5))
w = first(load(CE + '/WB09_UI.jsonl'), 'id').get('WB09')
if w: cpw = (w.get('snap') or {}).get('cp'); TG.append(('reproducer CP WB09 (<= V10 x 1,002)', 'WB09', cpw, (64.3644, 64.3644 * 1.002), cpw is not None and float(cpw) <= 64.3644 * 1.002 + 1e-9))

# Gas Shortage
def gs(p):
    try: t = open(p).read()
    except FileNotFoundError: return None
    m = re.search(r'```json\n(.*?)```', t, re.S)
    try: return json.loads(m.group(1)) if m else None
    except Exception: return None
P('## 12. Gas Shortage (UI)\n')
P('| PGN | versi | popup | angka tervalidasi | opsi → FINAL |'); P('|---|---|---|---|---|')
for g in ('25', '20'):
    for lab, d in (('V9', V9), ('V10', V10), ('V11', CE)):
        j = (gs(d + '/GAS_SHORTAGE_PGN' + g + '.md') or {}).get('pgn' + g, {})
        P('| %s | %s | %s s | %s s | %s s |' % (g, lab, f((j.get('popup') or 0) / 1000 if j.get('popup') else None), f((j.get('validated') or 0) / 1000 if j.get('validated') else None), f((j.get('optionToFinal') or 0) / 1000 if j.get('optionToFinal') else None)))
        if lab == 'V11' and j.get('optionToFinal'): TG.append(('Gas Shortage opsi → FINAL', 'PGN ' + g, j['optionToFinal'] / 1000, (20, 20), j['optionToFinal'] / 1000 <= 20))
P('')
for g in ('25', '20'):
    try: t = open(CE + '/GAS_SHORTAGE_PGN' + g + '.md').read()
    except FileNotFoundError: continue
    for tid in ('T2', 'T3', 'T4', 'T5'):
        m = re.search(r'\| ' + tid + r' \|[^|]*\|[^|]*\| (?:LNG [0-9.]+; )?([0-9]+) ms', t)
        if m: v = int(m.group(1)) / 1000; TG.append(('rerun bahan bakar (Gas Shortage opsi)', 'PGN ' + g + ' ' + tid, v, (10, 10), v <= 10))

# ringkasan target
P('## 0. Ringkasan terhadap target runtime V11\n')
P('| kategori | n | median | maks | target (maks) | lulus |'); P('|---|---|---|---|---|---|')
cats = {}
for c_, s, v, L, ok in TG: cats.setdefault(c_, []).append((s, v, L, ok))
for c_, xs in cats.items():
    vals = [float(x[1]) for x in xs]; L = xs[0][2]; nok = sum(1 for x in xs if x[3])
    P('| %s | %d | %s | %s | %s (%s) | %d/%d%s |' % (c_, len(xs), f(statistics.median(vals), 4 if 'CP' in c_ else 2), f(max(vals), 4 if 'CP' in c_ else 2), f(L[0], 4 if 'CP' in c_ else 1), f(L[1], 4 if 'CP' in c_ else 1), nok, len(xs),
      '' if nok == len(xs) else ' — lewat: ' + ', '.join('%s %s' % (x[0], f(x[1])) for x in xs if not x[3])))
P('')
# pindahkan ringkasan ke atas
i = O.index('## 0. Ringkasan terhadap target runtime V11\n')
O = O[:2] + O[i:] + O[2:i]
open(OUTF, 'w').write('\n'.join(O) + '\n'); print('ditulis', OUTF, len(O), 'baris')
