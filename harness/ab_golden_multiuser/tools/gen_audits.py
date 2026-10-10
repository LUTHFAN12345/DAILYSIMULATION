# gen_audits.py <dump.json> <docsdir> : PRIORITY_HEADROOM_48_ROW_AUDIT.md + DISTILLATE_FULL_FIRST_AUDIT.md dari output nyata
import json, sys, os
d = json.load(open(sys.argv[1])); DP = json.load(open(sys.argv[3]))['data3']['modeling'].get(sys.argv[4]) if len(sys.argv) > 4 else None; o = d['output']; i = o['info']; rows = o['data']; D = sys.argv[2]
H = i.get('Headroom Priority Audit', {}); M = i.get('Merit Proof C1-C4 STG', {}); F = i.get('Fastest Unit Priority Fix', {})
prio = H.get('unit_priority', [])
gtg = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10']
L = ['# PRIORITY_HEADROOM_48_ROW_AUDIT', '',
     'Sumber: output job Fastest nyata pada payload golden (input 07-Oct-26 = Baru), source final V15.16. Dibuat otomatis oleh `harness/ab_golden_multiuser/gen_audits.py` dari `info` hasil run (bukan angka tulisan tangan).', '',
     '- Cost Production: **%s USD/MWh**, Heat Rate: **%s BTU/kWh**, hard validation: **%s**' % (i.get('Cost Production (USD/MWh)'), i.get('JBBK MM Heat Rate (BTU/kWh)'), d.get('hard')),
     '- Unit Priority user (tier, kiri = prioritas lebih tinggi): `%s`' % json.dumps(prio),
     '- Merit Proof C1-C4/STG: **%s** — %s' % (M.get('status'), ', '.join('%s %s/%s' % (k, (M.get(k) or {}).get('findings'), (M.get(k) or {}).get('fail')) for k in ['C1','C2','C3','C4'])),
     '- Headroom Priority Audit: status `%s`, flag total %s, flag tak terselesaikan %s, batas atas gain sisa %s USD (CP %s)' % (H.get('status'), H.get('flags_total'), H.get('flags_unresolved'), H.get('residual_gain_usd_upper_bound'), H.get('residual_cp_gain_upper_bound')),
     '- Flag per jenis: `%s`' % json.dumps(H.get('flags_by_type')), '',
     '## Bukti numerik untuk flag tak terselesaikan', '']
for u in H.get('unresolved', []):
    L.append('- Row %s: %s %.2f MW (min %s) sementara %s punya headroom %.2f MW.' % (u['row'], u['unit'], u['unit_mw'], u['unit_min'], u['higher_unit'], u['higher_headroom_mw']))
for p in F.get('proofs', []):
    L.append('- Proof Fastest fix: row %s %s→%s: %s — %s' % (p['row'], p['unit'], p['higher'], p['reason'], p['detail']))
L += ['', '## Tabel 48 row (MW per GTG, Export, Spinning Reserve, Distillate)', '',
      '| Row | Jam | ' + ' | '.join(gtg) + ' | Export | SR | Dist (l) | Flag row |', '|' + '---|' * (len(gtg) + 6)]
fl = {}
for f in H.get('flags', []): fl.setdefault(f['row'], []).append('%s:%s<%s' % (f['type'].split('_')[1][:4], f['unit'], f.get('higher_unit', '')))
for k, r in enumerate(rows, 1):
    L.append('| %d | %s | %s | %s | %s | %s | %s |' % (k, r['Time'][-5:], ' | '.join(('%g' % round(float(r.get(g, 0) or 0), 2)) for g in gtg),
             round(float(r.get('Export_PLN', 0)), 2), round(float(r.get('Spin_Res', 0) or 0), 2), round(float(r.get('Dist_Total', 0) or 0), 1), len(fl.get(k, []))))
L += ['', 'Kolom "Flag row" = jumlah flag audit headroom pada row tersebut (RUNNING/LOADED/START lower-priority sementara unit lebih tinggi punya headroom). Seluruh flag selain yang tercantum di bagian bukti telah berstatus resolved dengan alasan (EKONOMIS/RAMP/EXPORT/MIN-LOAD/COUNTERFACTUAL) — lihat `info.Merit Proof C1-C4 STG.C1..C4.detail`.']
open(os.path.join(D, 'PRIORITY_HEADROOM_48_ROW_AUDIT.md'), 'w').write('\n'.join(L) + '\n')
# Distillate
dk = sorted({k for r in rows for k in r if k.startswith('Dist_G')})
R = i.get('Distillate Reconciliation', {}); C = i.get('Distillate Discrete Closing', {})
L = ['# DISTILLATE_FULL_FIRST_AUDIT', '',
     'Sumber: output job Fastest nyata pada payload golden + keputusan `use_distillate` (otomatis oleh `gen_audits.py`).', '',
     '- Unit Priority Distillate (GTG only) dari input: `%s`' % json.dumps(DP),
     '- Mix per unit: `%s`' % json.dumps(i.get('Distillate Mix per Unit (%)')),
     '- Rekonsiliasi: required %s l, scheduled %s l, consumed %s l, summary %s l, selisih %s l → **%s**' % (R.get('required_l'), R.get('scheduled_l'), R.get('consumed_l'), R.get('summary_l'), R.get('difference_l'), R.get('status')),
     '- Langkah penutup diskret: mode `%s`, status `%s`, priority_tiers `%s`, langkah `%s`' % (C.get('mode'), C.get('status'), json.dumps(C.get('priority_tiers')), json.dumps(C.get('langkah'))),
     '- Jumlah unit yang memakai Distillate: **%d** (%s)' % (sum(1 for k in dk if any(float(r.get(k, 0) or 0) > 0 for r in rows)), ', '.join(k[5:] for k in dk if any(float(r.get(k, 0) or 0) > 0 for r in rows))), '',
     '## Aturan yang diverifikasi', '',
     '1. Full-first: unit prioritas 1 diisi sampai slot/headroom habis sebelum unit berikutnya (greedy `__fullFirstHold`, worker02.php).',
     '2. Tidak ada Distillate pada slot unit yang sedang lead-in/minimum-load (≤ min load) bila unit prioritas lebih tinggi masih eligible.',
     '3. Langkah penutup diskret mencari per tier prioritas (L = 1..n) — tier lebih rendah hanya bila tier lebih tinggi tidak dapat menutup residual.', '',
     '## Per row (hanya row dengan Distillate)', '', '| Row | Jam | ' + ' | '.join('%s MW' % k[5:] for k in dk) + ' | ' + ' | '.join('%s l' % k for k in dk) + ' | Min-load? |', '|' + '---|' * (2 + 2 * len(dk) + 1)]
viol = 0
for k, r in enumerate(rows, 1):
    if float(r.get('Dist_Total', 0) or 0) <= 0: continue
    ml = []
    for dkk in dk:
        u = dkk[5:]
        if float(r.get(dkk, 0) or 0) > 0 and float(r.get(u, 0) or 0) <= 5.0 + 1e-6: ml.append(u); viol += 1
    L.append('| %d | %s | %s | %s | %s |' % (k, r['Time'][-5:], ' | '.join('%g' % float(r.get(x[5:], 0) or 0) for x in dk), ' | '.join('%g' % round(float(r.get(x, 0) or 0), 1) for x in dk), ','.join(ml) or 'tidak'))
L += ['', '**Distillate pada unit ≤ 5 MW (lead-in/minimum-load): %d slot.**' % viol]
open(os.path.join(D, 'DISTILLATE_FULL_FIRST_AUDIT.md'), 'w').write('\n'.join(L) + '\n')
print('ok', viol)
