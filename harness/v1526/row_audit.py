"""row_audit.py <run_output.json> <out.md> [title] : audit ringkas 48 row — unit Running diurutkan Effective Unit Priority, beban/min/max/headroom,
temuan prioritas (Headroom Priority Audit sesudah rekonsiliasi) dan bukti; ringkasan invariant + event start/stop (latest start / earliest stop)."""
import json, sys
r = json.load(open(sys.argv[1])); o = r.get('output', r); i = o['info']; H = i['Headroom Priority Audit']; title = sys.argv[3] if len(sys.argv) > 3 else sys.argv[1]
rows = H['rows']; rows = json.loads(rows) if isinstance(rows, str) else rows
flags = {}
for f in H.get('flags', []): flags.setdefault(int(f['row']), []).append(f)
L = [f"# Audit 48 row — {title}", '', f"status {o.get('status')} | CP {i.get('Cost Production (USD/MWh)')} | HR {i.get('JBBK MM Heat Rate (BTU/kWh)')} | HPA {H.get('status')} (temuan {H.get('flags_total')}, unresolved {H.get('flags_unresolved')})", '']
pi = i.get('Priority Invariants') or {}
L.append('## Invariant'); L.append('')
for k, v in (pi.get('invariants') or {}).items(): L.append(f"- {k}: **{v.get('status')}** " + json.dumps({x: v[x] for x in v if x in ('findings', 'by_proof_kind', 'auto_starts_tested', 'stops_tested', 'not_proven', 'unproven')})[:300])
L.append(''); L.append('## Event start/stop (bukti final)'); L.append('')
for e in (i.get('Commitment Event Proof') or {}).get('events', []):
    t = e.get('no_delay_test') or e.get('no_early_stop_test') or {}
    L.append(f"- {e['unit']} {e['event']} {e.get('time')} ({e.get('requested', 'AUTO')}) — {e.get('reason_code')}; uji {t.get('test')}: {t.get('result')}; " + (json.dumps(t.get('reason'))[:160] if t.get('result') == 'TIDAK_VALID' else ''))
L.append(''); L.append('## Per row (unit Running urut prioritas: MW [min-max] headroom; temuan -> bukti)'); L.append('')
L.append('| row | Export | unit Running (prio: MW [min-max] h) | temuan prioritas | status |'); L.append('|---|---|---|---|---|')
okRows = 0
for rw in rows:
    k = int(rw['row']); us = rw.get('units', {})
    run = sorted([(v.get('prio', 99) if v.get('prio') is not None else 99, u, v) for u, v in us.items() if (v.get('mw') or 0) > 0.01 and v.get('prio') is not None], key=lambda x: (x[0], x[1]))
    s = '; '.join(f"{u}(p{p}): {v['mw']} [{v.get('min')}-{v.get('max')}] h{v.get('headroom')}" for p, u, v in run)
    fl = flags.get(k, []); un = [f for f in fl if not f.get('resolved')]
    fs = '; '.join(f"{f['type'].split('_')[1][:5]} {f['unit']}<{f.get('higher_unit') or ','.join((f.get('headroom_units') or {}).keys())}: {(f.get('proof') or {}).get('kind') or ','.join(f.get('reasons', []))[:40]}" for f in fl[:4]) + (f' (+{len(fl) - 4})' if len(fl) > 4 else '')
    st = 'FAIL' if un else ('PASS (dengan bukti)' if fl else 'PASS'); okRows += 0 if un else 1
    L.append(f"| {k} | {rw.get('export_mw')} | {s} | {fs} | {st} |")
L.insert(4, f"Row tanpa temuan tak-terbukti: {okRows}/48"); L.insert(5, '')
open(sys.argv[2], 'w').write('\n'.join(L) + '\n'); print(okRows, 'rows OK;', pi.get('status'), pi.get('failed'))
