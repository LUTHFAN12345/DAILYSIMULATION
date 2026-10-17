"""V15.23 report: before/after actual scenario + numeric proofs. usage: python3 report.py <old.json> <new.json> <regdir> <bench.txt> > report.md"""
import json,sys,os
sys.path.insert(0,os.path.dirname(__file__)); import cmp
old,new,regdir,bench=sys.argv[1:5]
def load(p): r=json.load(open(p)); return r,r.get('output',r)
ro,o=load(old); rn,n=load(new)
U=['BB2','G8','G9','S3','G2','G5','G1','S2','G6','G4','G3','S1']
def tm(r): m=(r*30)%1440; return '%02d:%02d'%(m//60,m%60)
P=print
P('# V15.23 — Laporan dispatch skenario aktual (snapshot plan-2-mvc1ltkp, 05-Oct-26)\n')
so=cmp.summ(old); sn=cmp.summ(new)
P('## Ringkasan before/after\n')
P('| Metrik | V15.22 | V15.23 |'); P('|---|---|---|')
for k,l in [('cp','CP (USD/MWh)'),('hr','HR JBBK MM (BTU/kWh)'),('gas','Gas (BBTUD)'),('dist','Distillate (l)'),('exp','Export harian (MWh)'),('hard','Hard validation'),('publish','Publish'),('starts','Jumlah startup GTG'),('minrows','Unit-row di beban minimum (≤ min+0,5)'),('inv_rows','Unit-row inversi prioritas statis*')]:
    P(f'| {l} | {so[k]} | {sn[k]} |')
P(f"| Event start/stop | {so['events']} | {sn['events']} |")
P(f"| Jam operasi | {so['hours']} | {sn['hours']} |")
P('\n*inversi statis = unit level lebih rendah online sementara headroom (max − beban, tanpa ramp/STG) unit online level lebih tinggi ≥ bebannya; indikator kasar, bukti legal ada di bagian bukti.\n')
P('## Dispatch 48 row (MW)\n')
P('| Row | Jam | IE | '+' | '.join(f'{u} lama/baru' for u in U)+' | Export lama/baru |'); P('|'+'---|'*(len(U)+4))
for k in range(48):
    a=o['data'][k]; b=n['data'][k]
    P(f"| {k+1} | {tm(k+1)} | {b['IE']:.1f} | "+' | '.join(f"{float(a.get(u) or 0):.1f}/{float(b.get(u) or 0):.1f}" for u in U)+f" | {a['Export_PLN']:.1f}/{b['Export_PLN']:.1f} |")
i=n['info']; ct=i.get('Commitment Tightening') or {}
P('\n## Bukti numerik event start (invariant B: start satu row lebih lambat)\n')
for p in ct.get('proofs',[]):
    c=p.get('context') or {}
    P(f"### {p['unit']} {p['event']} {p['time']} (row {p['row']})")
    if p['event']=='START':
        P(f"- Jenis: {p.get('requested')}; level Unit Priority: {p.get('priority_level')}")
        P(f"- Row sebelum start: {c.get('time_before')} — IE {c.get('ie_mw')} MW, Export {c.get('export_mw')} MW (min {c.get('export_min_mw')}, max {c.get('export_max_mw')}), gas row {c.get('gas_row_bbtud')} BBTUD, distillate row {c.get('distillate_row_l')} l")
        P('- Unit running (MW / min / max / ramp / legal headroom / level):')
        for un,v in (c.get('running_units') or {}).items(): P(f"  - {un}: {v['mw']} / {v['min']} / {v['max']} / {v['ramp_mw_per_row']} / {v['legal_headroom_mw']} / {v['priority_level']}")
        P(f"- Legal headroom unit running berprioritas lebih tinggi: {c.get('higher_priority_legal_headroom_mw')} MW")
        P(f"- STG/HRSG: {c.get('stg')}; startup lead-in: {c.get('startup_leadin_mw')}")
    P(f"- Uji: {p['test']} → **{p['result']}**")
    if isinstance(p.get('reason'),list):
        for w in p['reason']: P(f"  - {w}")
    if c.get('delay_test_deficits'):
        P('  - Defisit Export pada uji tunda: '+'; '.join(f"row {d['row']} ({d['time']}): {d['export_mw']} < {d['export_min_mw']} (−{d['deficit_mw']})" for d in c['delay_test_deficits']))
    P('')
P('## Langkah Commitment Tightening\n')
P(f"Status {ct.get('status')}; sebelum {ct.get('before')}; sesudah {ct.get('after')}; evaluasi {ct.get('evaluations')}, cache hit {ct.get('cache_hits')}, {ct.get('wall_s')} s\n")
for m in ct.get('moves',[]):
    r=m.get('reason'); P(f"- {m['move']}: {m['result']}" + (': '+'; '.join(r[:3]) if isinstance(r,list) and r else ''))
P('\n## Comparator Invariant Guard (V8 / family / Unit Priority Fix)\n')
for g in i.get('Comparator Invariant Guard') or []:
    P(f"- {g['stage']}: {g['decision']} — econ CP {g['before']['econ_cp']} → {g['after']['econ_cp']}, startup tak-wajib {g['before']['startups_nm']} → {g['after']['startups_nm']}")
v8=i.get('V8 Priority Review') or {}
for a in v8.get('applied') or []: P(f"  - V8 mengusulkan {a.get('candidate')} CP {a.get('cp_before')} → {a.get('cp_after')}")
P('\n## Periode G8/G9 ≤ 70 MW sementara unit prioritas lebih rendah running (dispatch final)\n')
for k,b in enumerate(n['data']):
    g8=float(b['G8'] or 0); g9=float(b['G9'] or 0); low=[u for u in ['G5','G1','G6','G4','G3'] if float(b.get(u) or 0)>0.01]
    if low and ((0<g8<=70) or (0<g9<=70)):
        P(f"- row {k+1} ({tm(k+1)}): G8 {g8}, G9 {g9}, S3 {b['S3']}, Export {b['Export_PLN']:.2f} (min {b['pln_lo']}); unit lebih rendah: "+', '.join(f"{u} {b[u]}" for u in low))
P('\n## Regresi\n'); P('| Kasus | CP lama | CP baru | Hard lama/baru | Publish lama/baru | Event baru | wall baru (s) |'); P('|---|---|---|---|---|---|---|')
names=sorted({f[8:-5] for f in os.listdir(regdir) if f.startswith('out_new_')})
for nm in names:
    a=cmp.summ(f'{regdir}/out_old_{nm}.json') or {}; b=cmp.summ(f'{regdir}/out_new_{nm}.json') or {}
    P(f"| {nm} | {a.get('cp')} | {b.get('cp')} | {a.get('hard')}/{b.get('hard')} | {a.get('publish')}/{b.get('publish')} | {b.get('events')} | {b.get('wall')} |")
P('\n## Benchmark (CLI job, 1 proses, tanpa helper)\n'); P('```'); P(open(bench).read()); P('```')
P('\n## Profil tahap V15.23 (run cold)\n')
for s in (i.get('Start Priority Stage Profile') or {}).get('stages',[]): P(f"- {s['stage']}: {s['s']} s")
