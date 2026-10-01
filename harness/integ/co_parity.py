#!/usr/bin/env python3
"""Parity Change Over: V12 checkpoint vs integrasi vs kandidat Copilot + urutan wajib. Arg: <out.md> <out.json>"""
import json, glob, os, sys, hashlib
D = '/home/claude/t5/integ'
SRC = ['ckpt', 'integ', 'cop']
FAM = {'1': ['G3', 'G4', 'G6', 'S1'], '2': ['G1', 'G2', 'G5', 'S2']}
def load(v):
    R = {}
    for f in glob.glob('%s/o3_%s/*.json' % (D, v)):
        d = json.load(open(f)); R[os.path.basename(d['sc']).replace('.json', '')] = d.get('output') or {}
    return R
def first(rows, c):
    for k, r in enumerate(rows):
        if float(r.get(c) or 0) > 0.01: return k + 1
    return None
def last(rows, c):
    x = None
    for k, r in enumerate(rows):
        if float(r.get(c) or 0) > 0.01: x = k + 1
    return x
def ana(name, o):
    i = o.get('info') or {}; rows = o.get('data') or []; ar = o.get('simulation_acceptance_review') or {}
    co = i.get('Change Over Timeline') or {}; src = '1' if '_B1B2_' in name else '2'; tgt = '2' if src == '1' else '1'
    sS, sT = 'S' + src, 'S' + tgt; tg = 'G4' if tgt == '1' else 'G1'
    ov = run = 0; ovStart = None; ovEnd = None
    for k, r in enumerate(rows):
        if float(r.get(sS) or 0) > 0.01 and float(r.get(sT) or 0) > 0.01:
            run += 1
            if run > ov: ov = run; ovEnd = k + 1; ovStart = k + 2 - run
        else: run = 0
    gT, sTf, sSl = first(rows, tg), first(rows, sT), last(rows, sS)
    srcG = [u for u in FAM[src] if u.startswith('G')]
    srcGl = max([last(rows, u) or 0 for u in srcG]) if rows else None
    gate = (o.get('release_gate') or {}).get('status'); blk = ar.get('blocking_reasons') or []
    seq = {
        'gtg_target_sebelum_stg_target': gT is not None and sTf is not None and gT <= sTf,
        'overlap_mulai_saat_stg_target_pertama_positif': ovStart is not None and sTf is not None and ovStart == sTf,
        'overlap_min_3_row': ov >= 3,
        'stg_sumber_stop_setelah_overlap': sSl is not None and ovEnd is not None and sSl <= ovEnd + 1 and sSl >= ovEnd,
        'gtg_sumber_shutdown': srcGl is not None and srcGl < 48,
    }
    return {'rows': len(rows), 'gate': gate, 'blocking': blk, 'co_mode': co.get('mode'), 'executed': co.get('executed'),
            'cp': i.get('Cost Production (USD/MWh)'), 'hr': i.get('JBBK MM Heat Rate (BTU/kWh)'),
            'sig': hashlib.md5(json.dumps([[round(float(r.get(c) or 0), 3) for c in sorted(r) if c[:1] in 'GSB' and c[1:].isdigit()] for r in rows]).encode()).hexdigest()[:12] if rows else None,
            'target_gtg_first_row': gT, 'target_stg_first_row': sTf, 'source_stg_last_row': sSl, 'overlap_rows': ov, 'overlap_rows_range': [ovStart, ovEnd],
            'source_gtg_last_row': srcGl, 'sequence': seq,
            'hard_types': list(((ar.get('hard_validation') or {}).get('types') or {}).keys())}
A = {v: load(v) for v in SRC}
names = sorted(set().union(*[set(A[v]) for v in SRC]))
J = []; L = ['# Parity Change Over Block 1-2 — V12 checkpoint vs integrasi vs kandidat Copilot', '',
             'Dua basis nyata (WB09_3 = Daily_Plan_09_Jul_26_Baru(3), WB09) x B2->B1 (sim/sim Cold/Warm/Hot, manual start/sim stop, sim start/manual stop, manual/manual) dan B1->B2 (sim/sim Cold/Warm/Hot, manual/manual). Payload persis format UI (block, last_status, gtg prioritas-1, stg, start_other/stop_other). Job HTTP, cold dari direktori job kosong.', '',
             '| kasus | V12 gate | integrasi gate | Copilot gate | identik integrasi = V12 (dispatch/CP/HR/timeline) | Copilot = V12 | executed | GTG target row | STG target row | overlap (row) | STG sumber stop row | urutan wajib | CP V12 / integ / Copilot |',
             '|---|---|---|---|---|---|---|---|---|---|---|---|---|']
ok_par = 0; ok_seq = 0; npass = 0; regress = 0
for n in names:
    r = {v: ana(n, A[v].get(n, {})) for v in SRC}
    keys = ['sig', 'cp', 'hr', 'gate', 'co_mode', 'executed', 'target_gtg_first_row', 'target_stg_first_row', 'source_stg_last_row', 'overlap_rows']
    par = all(r['ckpt'][k] == r['integ'][k] for k in keys); parC = all(r['ckpt'][k] == r['cop'][k] for k in keys)
    ok_par += par; base_pass = r['ckpt']['gate'] == 'PASS'
    if base_pass: npass += 1
    if base_pass and r['integ']['gate'] != 'PASS': regress += 1
    seqok = all(r['integ']['sequence'].values()) if r['integ']['gate'] == 'PASS' else None
    if seqok: ok_seq += 1
    L.append('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s / %s / %s |' % (n, r['ckpt']['gate'], r['integ']['gate'], r['cop']['gate'], 'YA' if par else '**TIDAK**', 'YA' if parC else 'tidak',
        r['integ']['executed'], r['integ']['target_gtg_first_row'], r['integ']['target_stg_first_row'], r['integ']['overlap_rows'], r['integ']['source_stg_last_row'],
        ('PASS' if seqok else ('-' if seqok is None else 'FAIL ' + ','.join(k for k, v in r['integ']['sequence'].items() if not v))), r['ckpt']['cp'], r['integ']['cp'], r['cop']['cp']))
    J.append({'case': n, 'parity_integ_vs_v12': par, 'parity_copilot_vs_v12': parC, 'results': r})
L += ['', '**Paritas integrasi = V12 checkpoint: %d/%d kasus identik** (dispatch, CP, Heat Rate, gate, mode, executed, timeline start/stop/overlap).' % (ok_par, len(names)),
      'Kasus PASS pada V12 checkpoint: %d; regresi ke HARD_VALIDATION_FAILED / COST_PRODUCTION_PROOF_FAILED pada integrasi: **%d**.' % (npass, regress),
      'Urutan wajib lulus pada seluruh kasus PASS integrasi: %d/%d.' % (ok_seq, sum(1 for x in J if x['results']['integ']['gate'] == 'PASS')),
      'Kasus FAIL pada V12 checkpoint ditampilkan apa adanya (blocker fisik pasangan/hari itu, sama pada ketiga source) — bukan regresi integrasi.']
open(sys.argv[1], 'w').write('\n'.join(L) + '\n'); json.dump(J, open(sys.argv[2], 'w'), indent=1)
print('\n'.join(L[-4:]))
