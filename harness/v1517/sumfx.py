import json, sys
d = json.load(open(sys.argv[1])); o = d['output']; i = o.get('info', {}); rows = o.get('data', [])
dca = i.get('Distillate Continuity Audit') or {}
prof = {}
for U in ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10']:
    p = [(k+1, int(round(r.get('DistMix_'+U, 0)*100))) for k, r in enumerate(rows) if r.get('DistMix_'+U)]
    if p: prof[U] = p
on = {}
for U in ['G1','G2','G3','G4','G5','G6']:
    rr = [k+1 for k, r in enumerate(rows) if float(r.get(U, 0) or 0) > 0.01]
    if rr: on[U] = f'{rr[0]}-{rr[-1]}({len(rr)})'
ir = i.get("IE Incremental Redispatch") or {}; rs = i.get("Run Status") or {}
print("   ie", rs.get("mode"), {k: ir.get(k) for k in ["cache","applied","alasan_invalidasi","affected_windows","commitment_dipertahankan","rows_dispatch_berubah","first_fully_valid_s","gagal_checks","violations"] if k in ir})
print(sys.argv[2], "wall", d['wall_s'], 'hard', d['hard'], 'gate', (o.get('release_gate') or {}).get('status'), 'CP', i.get('Cost Production (USD/MWh)'), 'HR', i.get('JBBK MM Heat Rate (BTU/kWh)'),
      'dist_l', i.get('Recommended Distillate (l/day)'), 'mix', i.get('Distillate Mix per Unit (%)'), 'merit', (i.get('Merit Proof C1-C4 STG') or {}).get('status'))
print('   viol', [str(v)[:160] for v in d.get('viol', [])][:6]); print('   on', on); print('   cont', dca.get('status'), {u: (v['blok'], v['level_dipakai'], v['proof_celah']) for u, v in (dca.get('unit') or {}).items()}, dca.get('violations', [])[:3])
for U, p in prof.items(): print('   ', U, p)
blk = (o.get('release_gate') or {}).get('blocking_reasons')
if blk: print('   BLOCK', json.dumps(blk)[:400])
