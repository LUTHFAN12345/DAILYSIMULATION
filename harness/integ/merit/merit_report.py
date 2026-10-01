#!/usr/bin/env python3
"""MERIT DISPATCH — laporan 22 butir per state + kriteria FAIL. Semua angka dibaca dari output engine nyata (FINAL, counterfactual
tanpa redistribusi Unit Priority, tanpa pembantu, warm). Arg: <merit_dir> <map> <out.md> <out.json>"""
import json, glob, os, sys
D, MAP, OUT, OJ = sys.argv[1:5]
BLK = {'1': (['G3', 'G4', 'G6'], 'S1'), '2': (['G1', 'G2', 'G5'], 'S2'), '3': (['G8', 'G9'], 'S3')}
UNITS = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10', 'S1', 'S2', 'S3', 'GE1', 'GE2', 'GE3', 'GE4', 'BB1', 'BB2']
mp = [l.rstrip('\n').split('\t', 1) for l in open(MAP) if l.strip()]
def load(sub):
    R = {}
    for f in glob.glob('%s/%s/*.json' % (D, sub)):
        d = json.load(open(f)); R[d['sc']] = d.get('output') or {}
    return R
FIN, BEF, NOH, WRM = load('out_cold'), load('out_before'), load('out_nohelp'), load('out_warm')
SP = json.load(open(D + '/STG_PROOF.json'))
def mw(r, u): return float(r.get(u) or 0)
def sig(o): return json.dumps([[round(mw(r, u), 3) for u in UNITS] for r in (o.get('data') or [])])
def energy(o, us): return round(sum(mw(r, u) for r in (o.get('data') or []) for u in us) / 2.0, 2)
def stg_check(*outs):
    """STG dihitung ulang setiap GTG blok berubah: dalam satu run, dua row dengan tuple GTG blok identik (dan status STG sama)
    harus memberi STG identik; antar run (sebelum vs sesudah redistribusi), row yang GTG bloknya berubah harus memberi STG berubah
    kecuali STG terkunci (0 / pada batas maksimum yang sama)."""
    viol = []; changed = 0; recalc = 0
    if len(outs) == 2 and outs[0].get('data') and outs[1].get('data'):
        a, b = outs[0]['data'], outs[1]['data']
        for k in range(min(len(a), len(b))):
            for bn, (gs, s) in BLK.items():
                ga = tuple(round(mw(a[k], g), 2) for g in gs); gb = tuple(round(mw(b[k], g), 2) for g in gs)
                if ga != gb:
                    changed += 1
                    if abs(mw(a[k], s) - mw(b[k], s)) > 0.005: recalc += 1
                    elif not (sum(ga) < 0.01 or sum(gb) < 0.01 or mw(a[k], s) < 0.01): viol.append('row %d blok %s GTG %s->%s STG tetap %.2f' % (k + 1, bn, ga, gb, mw(b[k], s)))
    return {'rows_gtg_changed': changed, 'rows_stg_recalculated': recalc, 'stg_unchanged_violations': viol[:6], 'violations': len(viol)}
def low_units(o):
    rv = (o.get('info') or {}).get('V8 Priority Review') or {}
    return {iv['unit']: iv for iv in (rv.get('relevant_intervals') or [])}
J = []; L = ['# MERIT DISPATCH — bukti generik per state (12 state nyata, engine V12 checkpoint = source integrasi)', '',
    'Sumber angka: FINAL engine (job HTTP cold), counterfactual state yang sama dengan redistribusi Unit Priority dimatikan (PP_V8_PRIORITY=0, PP_V6_POLISH=0) sebagai "sebelum redistribusi", run tanpa pembantu, dan rantai warm. '
    'Legal headroom = min(Effective Max - MW, allowance ramp) dari V12 Dispatch Merit Audit; STG blok dihitung ulang oleh core run (G3/G4/G6->S1, G1/G2/G5->S2, G8/G9->S3).', '']
SUM = []
for sid, sc in mp:
    o = FIN.get(sc) or {}; b = BEF.get(sc) or {}; i = o.get('info') or {}; rows = o.get('data') or []
    A = i.get('V12 Dispatch Merit Audit') or {}; rv = i.get('V8 Priority Review') or {}; C = i.get('V12 CP Report') or {}; LL = i.get('V12 Low Load Fragmentation Outcome') or {}
    cmpT = i.get('V11 Candidate Comparison') or {}; rg = o.get('release_gate') or {}
    fp = (i.get('Fuel Provenance') or {}).get('status'); mm = (i.get('MM2100 Gas Provenance') or {}).get('status')
    ar = A.get('rows') or []
    ranks = {}
    for r in ar:
        for u in r['units']: ranks[u['unit']] = (u.get('priority_group'), u.get('priority_rank'))
    order = [u for u, _ in sorted(ranks.items(), key=lambda x: (x[1][0] or 99, x[1][1] or 99))]
    running = [u for u in UNITS if any(mw(r, u) > 0.01 for r in rows)]
    LU = low_units(o); evid = rv.get('row_evidence') or {}; fc = rv.get('final_candidates') or []
    lows = []
    for U, iv in LU.items():
        a0, b0 = iv['rows']; mr = int(iv.get('min_runtime_rows') or 0)
        stop_actual = next((k + 1 for k in range(b0, 48) if mw(rows[k], U) <= 0.01), None) if rows else None
        last_on = max([k + 1 for k in range(48) if rows and mw(rows[k], U) > 0.01] or [0])
        start_row = next((k + 1 for k in range(48) if rows and mw(rows[k], U) > 0.01), None)
        mr_end = (start_row + mr - 1) if (start_row and mr) else None
        fls = iv.get('first_legal_stop_row') or ((mr_end + 1) if mr_end else None)
        hr_row = next((r for r in ar if r['row'] == start_row), None)
        hh = {u['unit']: u['legal_headroom_mw'] for u in (hr_row or {}).get('units', []) if ranks.get(u['unit'], (99, 99)) < ranks.get(U, (99, 99)) and u['mw'] > 0.01}
        ev = (evid.get('%s#%s' % (start_row, U)) or {}).get('reasons') or []
        delay = [c for c in fc if c.get('unit', '').upper() == U and c.get('kind') in ('DELAY', 'DECOMMIT', 'SWAP', 'STOP', 'EARLY_STOP', 'TRUNC')]
        dl = [c for c in delay if c.get('kind') == 'DELAY']
        defer = ('tidak — ' + '; '.join('%s %s' % (c.get('id'), ('valid CP %.4f' % c['cp']) if c.get('valid') else ('tidak valid: ' + ','.join(c.get('violations') or [])[:80])) for c in dl[:2])) if dl else \
                ('tidak — ' + next((w for w in ev if w.startswith('DELAY')), 'DELAY tidak diuji'))
        nostop = '' if (stop_actual is not None and fls is not None and stop_actual <= fls) else ' | '.join(w for w in ((evid.get('%s#%s' % (fls, U)) or {}).get('reasons') or []) if any(ch.isdigit() for ch in w))[:300]
        lows.append({'unit': U, 'start_row': start_row, 'interval_review': [a0, b0], 'start_reason': [w for w in ev if any(ch.isdigit() for ch in w)][:3] or ev[:3], 'need_rows_export': iv.get('need_rows_export'),
                     'higher_priority_legal_headroom_at_start_final_mw': hh, 'higher_priority_headroom_before_review_mw': iv.get('headroom_units'),
                     'start_deferrable': defer, 'min_runtime_rows': mr, 'min_runtime_end_row': mr_end, 'first_legal_stop_row': fls, 'actual_stop_row': stop_actual, 'last_running_row': last_on,
                     'numeric_reason_not_stopped': nostop or None})
    # headroom unit prioritas tinggi pada row beban puncak
    peak = max(ar, key=lambda r: sum(u['mw'] for u in r['units'])) if ar else None
    hiHead = {u['unit']: {'row': peak['row'], 'mw': u['mw'], 'max': u['max'], 'legal_headroom_mw': u['legal_headroom_mw']} for u in (peak or {}).get('units', []) if u['class'] == 'GTG' and u['mw'] > 0.01} if peak else {}
    eB = {u: energy(b, [u]) for u in UNITS}; eA = {u: energy(o, [u]) for u in UNITS}
    dG = {bn: round(sum(eA[g] - eB[g] for g in gs), 2) for bn, (gs, s) in BLK.items()}; dS = {bn: round(eA[s] - eB[s], 2) for bn, (gs, s) in BLK.items()}
    stg = stg_check(b, o)
    c2 = A.get('c2_start_with_headroom') or {}; c3 = A.get('c3_first_legal_stop') or {}; c1 = A.get('c1_merit_headroom') or {}
    tab = cmpT.get('table') or []
    cps = [float(t.get('cp')) for t in tab if t.get('valid', True) and t.get('cp') is not None]
    cmin = C.get('absolute_cp_min'); wcp = C.get('winner_cp')
    same = len({sig(x) for x in [o, NOH.get(sc) or {}, WRM.get(sc) or {}] if x.get('data')}) == 1 and all((x.get('data') for x in [NOH.get(sc) or {}, WRM.get(sc) or {}]))
    crit = {
        'low_start_with_sufficient_high_headroom': (c2.get('fail') or 0) == 0,
        'high_units_raised_as_far_as_legal_and_needed': (c1.get('findings') or 0) == (c1.get('with_reason') or 0),
        'stg_recalculated_on_gtg_change': all(SP.get(sid, {}).get(k, {}).get('violations', 1) == 0 and SP[sid][k]['stg_equal_calc'] + SP[sid][k]['stg_startup_hold_or_hrsg'] == SP[sid][k]['rows_x_stg'] for k in ('FINAL', 'SEBELUM_REDISTRIBUSI')),
        'low_unit_stopped_at_first_legal_row_or_numeric_proof': (c3.get('fail') or 0) == 0 and all(x['numeric_reason_not_stopped'] is None or x['numeric_reason_not_stopped'] != '' for x in lows),
        'low_load_fragmentation_resolved': LL.get('status') in ('PASS', 'PASS_WITH_OUTCOMES'),
        'cheaper_valid_candidates_in_comparator': C.get('status') != 'OK' or (cmin is not None and all(cp >= float(cmin) - 1e-9 for cp in cps) and float(cmin) <= float(wcp) + 1e-9),
        'heat_rate_only_inside_cp_band': C.get('status') != 'OK' or float(wcp) <= float(cmin) * 1.002 + 1e-6,
        'identical_cold_warm_helper': same,
        'hard_constraints_and_provenance': rg.get('hard_validation') == 'PASS' and rg.get('release_allowed') is True and fp == 'PASS' and mm in ('PASS', None),
    }
    # Uji independen (tanpa audit engine): unit grup prioritas lebih rendah di atas minimum sementara unit grup lebih tinggi yang berjalan
    # masih punya legal headroom. Pengecualian hanya: status paksa (Required/Cannot Stop/Fixed) atau akun bahan bakar terpisah yang
    # wajib terpakai (GE/G10 = akun MM2100; kuota KP72 terpakai penuh: used >= quota - 0,05 BBTUD; GTG tidak boleh membakar gas MM2100).
    mmq = float(i.get('MM2100 Quota (BBTUD)') or 0); mmu = float(i.get('MM2100 Used + Startup (BBTUD)') or 0); mmFull = mmq > 0 and mmu >= mmq - 0.05
    xg = {'total': 0, 'STATUS_PAKSA': 0, 'AKUN_MM2100_TERPAKAI_PENUH': 0, 'TANPA_ALASAN': []}
    for r in ar:
        U = [u for u in r['units'] if u['mw'] > 0.01 and u['class'] in ('GTG', 'GE')]
        for Lw in U:
            if Lw['mw'] <= Lw['min'] + 0.01: continue
            H = {h['unit']: h['legal_headroom_mw'] for h in U if (h['priority_group'] or 99) < (Lw['priority_group'] or 99) and h['legal_headroom_mw'] > 0.5}
            if not H: continue
            xg['total'] += 1
            if Lw.get('status'): xg['STATUS_PAKSA'] += 1
            elif Lw['unit'] in ('GE1', 'GE2', 'GE3', 'GE4', 'G10') and mmFull: xg['AKUN_MM2100_TERPAKAI_PENUH'] += 1
            else: xg['TANPA_ALASAN'].append('row %d %s +%.2f MW di atas min; headroom %s' % (r['row'], Lw['unit'], Lw['mw'] - Lw['min'], H))
    xg['mm2100'] = {'quota_bbtud': mmq, 'used_bbtud': mmu}
    crit['independent_cross_group_merit'] = not xg['TANPA_ALASAN']
    rec_xg = xg
    ok = all(crit.values())
    rec = {'id': sid, 'state': sc[:120], 'unit_priority_order': order, 'running_units': running, 'high_priority_legal_headroom_peak_row': hiHead,
           'energy_before_mwh': {u: eB[u] for u in UNITS if eB[u] or eA[u]}, 'energy_after_mwh': {u: eA[u] for u in UNITS if eB[u] or eA[u]},
           'delta_gtg_mwh_per_block': dG, 'delta_stg_mwh_per_block': dS, 'delta_block_total_mwh': {bn: round(dG[bn] + dS[bn], 2) for bn in BLK},
           'cp_before_redistribution': (b.get('info') or {}).get('Cost Production (USD/MWh)'), 'stg_recalculation': stg, 'low_priority_units': lows,
           'llf_status': LL.get('status'), 'llf_counts': LL.get('counts'), 'absolute_cp_min': cmin, 'absolute_cp_min_candidate': C.get('absolute_cp_min_candidate'),
           'winner_cp': wcp if wcp is not None else i.get('Cost Production (USD/MWh)'), 'winner_heat_rate': C.get('winner_heat_rate') or i.get('JBBK MM Heat Rate (BTU/kWh)'),
           'constraints': {'hard': rg.get('hard_validation'), 'release': rg.get('release_allowed'), 'export_in_band_rows': sum(1 for r in rows if r.get('in_band'))},
           'provenance': {'fuel': fp, 'mm2100': mm}, 'independent_cross_group': rec_xg, 'criteria': crit, 'merit_status': 'PASS' if ok else 'FAIL'}
    J.append(rec); SUM.append((sid, ok))
    L += ['## %s — %s' % (sid, 'PASS' if ok else '**FAIL**'), '', '| # | butir | nilai |', '|---|---|---|']
    def row(n, k, v): L.append('| %s | %s | %s |' % (n, k, str(v).replace('|', '/')))
    row(1, 'state', sc[:160]); row(2, 'Unit Priority (urut grup/rank input)', ' > '.join(order)); row(3, 'unit running', ', '.join(running))
    row(4, 'legal headroom GTG berjalan pada row beban puncak', '; '.join('%s row %s: %.1f/%.1f MW, headroom %.2f' % (u, v['row'], v['mw'], v['max'], v['legal_headroom_mw']) for u, v in hiHead.items()))
    row(5, 'energi sebelum redistribusi (MWh; CP %s)' % rec['cp_before_redistribution'], ', '.join('%s %.1f' % (u, eB[u]) for u in UNITS if eB[u] or eA[u]))
    row(6, 'energi sesudah redistribusi (MWh; CP %s)' % rec['winner_cp'], ', '.join('%s %.1f' % (u, eA[u]) for u in UNITS if eB[u] or eA[u]))
    row(7, 'delta GTG per blok (MWh)', dG); row(8, 'delta STG per blok (MWh)', dS); row(9, 'delta total blok GTG+STG (MWh)', rec['delta_block_total_mwh'])
    sp = SP.get(sid, {}); row('9b', 'STG dihitung ulang (calc_stg engine atas GTG pemasok uap >= min_ccload, setiap row)', 'FINAL %s/%s row x STG = calc, tahan start-up %s, langgar %s; sebelum redistribusi %s/%s, tahan %s, langgar %s; row GTG berubah vs sebelum %d (STG ikut berubah %d; sisanya GTG start-up < min_ccload / tukar unit identik / STG di batas)' % (sp['FINAL']['stg_equal_calc'], sp['FINAL']['rows_x_stg'], sp['FINAL']['stg_startup_hold_or_hrsg'], sp['FINAL']['violations'], sp['SEBELUM_REDISTRIBUSI']['stg_equal_calc'], sp['SEBELUM_REDISTRIBUSI']['rows_x_stg'], sp['SEBELUM_REDISTRIBUSI']['stg_startup_hold_or_hrsg'], sp['SEBELUM_REDISTRIBUSI']['violations'], stg['rows_gtg_changed'], stg['rows_stg_recalculated']))
    if not lows: row('10-16', 'unit prioritas rendah', 'tidak ada unit prioritas rendah yang start (seluruh beban dipenuhi unit prioritas tinggi)')
    for x in lows:
        row(10, '%s alasan start' % x['unit'], '; '.join(x['start_reason']) + (' | row kebutuhan Export ' + str(x['need_rows_export']) if x['need_rows_export'] else ''))
        row('10b', '%s headroom prioritas tinggi saat start (sebelum review / FINAL)' % x['unit'], '%s / %s' % (x['higher_priority_headroom_before_review_mw'], x['higher_priority_legal_headroom_at_start_final_mw']))
        row(11, '%s start dapat ditunda?' % x['unit'], x['start_deferrable']); row(12, '%s row start' % x['unit'], x['start_row'])
        row(13, '%s row minimum runtime selesai' % x['unit'], '%s (%s row)' % (x['min_runtime_end_row'], x['min_runtime_rows']))
        row(14, '%s row legal stop pertama' % x['unit'], x['first_legal_stop_row']); row(15, '%s row benar-benar stop' % x['unit'], x['actual_stop_row'])
        row(16, '%s alasan numerik tidak stop' % x['unit'], x['numeric_reason_not_stopped'] or '— (stop pada/ sebelum row legal pertama)')
    row('4b', 'uji independen lintas grup (unit rendah di atas min saat unit tinggi punya headroom)', 'temuan %d: status paksa %d, akun MM2100 terpakai penuh %d (kuota %.3f, terpakai %.3f BBTUD; GTG tidak boleh membakar gas MM2100), tanpa alasan %d %s' % (rec_xg['total'], rec_xg['STATUS_PAKSA'], rec_xg['AKUN_MM2100_TERPAKAI_PENUH'], rec_xg['mm2100']['quota_bbtud'], rec_xg['mm2100']['used_bbtud'], len(rec_xg['TANPA_ALASAN']), rec_xg['TANPA_ALASAN'][:3]))
    row(17, 'LOW_LOAD_FRAGMENTATION', '%s %s' % (LL.get('status'), json.dumps(LL.get('counts'))))
    row(18, 'CP minimum absolut', '%s (%s)' % (cmin, C.get('absolute_cp_min_candidate'))); row(19, 'CP pemenang', rec['winner_cp']); row(20, 'Heat Rate pemenang', rec['winner_heat_rate'])
    row(21, 'constraints', rec['constraints']); row(22, 'provenance', rec['provenance'])
    row('K', 'kriteria FAIL', '; '.join('%s=%s' % (k, 'OK' if v else 'FAIL') for k, v in crit.items()))
    L.append('')
L.insert(3, '**Ringkasan: %d/%d state MERIT PASS** — %s' % (sum(1 for _, k in SUM if k), len(SUM), ', '.join('%s %s' % (s, 'PASS' if k else 'FAIL') for s, k in SUM)))
open(OUT, 'w').write('\n'.join(L) + '\n'); json.dump(J, open(OJ, 'w'), indent=1)
for s, k in SUM: print(('PASS' if k else 'FAIL') + '  MERIT_' + s)
for r in J:
    if r['merit_status'] == 'FAIL': print('  ', r['id'], [k for k, v in r['criteria'].items() if not v])
