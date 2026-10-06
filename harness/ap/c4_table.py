#!/usr/bin/env python3
"""c4_table.py <fast_replay_or_result.json> <label> -> markdown tabel C4 per temuan (dari info['V12 C4 Redistribution'])"""
import json,sys
d=json.load(open(sys.argv[1])); lab=sys.argv[2]
o=(d.get('fast_ready') or {}).get('output') or d.get('job_result') or d
i=o.get('info') or {}; c4=i.get('V12 C4 Redistribution') or {}; ma=i.get('V12 Dispatch Merit Audit') or {}; cc=ma.get('c4_cross_group_priority') or {}
rw=None
for R in c4.get('rounds',[]):
    if R.get('rowwise'): rw=R['rowwise']
print(f"### {lab}\n")
print(f"- CP sebelum → sesudah: {c4.get('cost_production_before')} → {c4.get('cost_production_after')} USD/MWh; Heat Rate {c4.get('heat_rate_before')} → {c4.get('heat_rate_after')} BTU/kWh")
print(f"- Temuan C4 tanpa alasan sebelum: {c4.get('c4_fail_before')}; sesudah: {c4.get('c4_fail_after')}; mode evaluasi: {c4.get('evaluation_mode')} ({c4.get('evaluation_mode_reason') or '-'})")
print(f"- Audit merit akhir: {ma.get('status')} · C4 findings {cc.get('findings')}, status paksa {cc.get('status_forced')}, akun MM2100 penuh {cc.get('mm2100_account_full')}, PASS_WITH_REASON (bukti counterfactual) {cc.get('counterfactual_infeasible')}, FAIL {cc.get('fail')}")
if rw: print(f"- Evaluasi: {rw.get('evaluations')} evaluasi engine 48 row, fase {rw.get('phase')}, pencarian {rw.get('search_s')} s, bukti {rw.get('proof_s')} s · {rw.get('result')}\n")
print("| Row | Time | Donor | Receiver (rencana MW) | Legal headroom receiver (MW) | Δ diterapkan (MW) | Δ tambahan diuji (MW) | Langsung | Dengan pendaratan window gas | Gas used (BBTUD) sebelum→sesudah, window | Export (MW) sebelum→sesudah, range | Hasil |")
print("|---|---|---|---|---|---|---|---|---|---|---|---|")
for x in (rw or {}).get('rows',[]):
    g=x.get('gas_window_impact') or {}; e=x.get('export_impact') or {}
    gs=f"{g.get('gas_used_before_bbtud')}→{g.get('gas_used_after_bbtud')}, [{', '.join(str(v) for v in g.get('window_bbtud',[]))}]" if g else '—'
    es=f"{e.get('export_before_mw')}→{e.get('export_after_mw')}, [{', '.join(str(v) for v in e.get('range_mw',[]))}]" if e else '—'
    rec=', '.join(f"{r['unit']} {r['planned_mw']}" for r in x.get('receivers',[]))
    hd=x.get('legal_headroom'); hds=', '.join(f"{k} {v}" for k,v in hd.items()) if isinstance(hd,dict) else '—'
    print(f"| {x['row']} | {x.get('time')} | {'/'.join(x.get('donor',[]))} | {rec} | {hds} | {x.get('accepted_mw')} / {x.get('total_mw')} | {x.get('tested_additional_mw','—')} | {x.get('direct','—') or '—'} | {x.get('gas_window_landing','—') or '—'} | {gs} | {es} | {x.get('outcome')} |")
print()
