import json,sys
T,tag=sys.argv[1],sys.argv[2]
d=json.load(open(f'/home/claude/ab3/out/{tag}.json')); o=d['output']; i=o.get('info',{}); rows=o.get('data') or []
col=lambda u:[float(r.get(u) or 0) for r in rows]
on=lambda u:[k+1 for k,v in enumerate(col(u)) if v>0.01]
mp=i.get('Merit Proof C1-C4 STG',{}); rv=o.get('simulation_acceptance_review') or {}
bs=i.get('Block Sequence Audit',{}); pb=i.get('Proof Binding',{}); cs=i.get('Commitment Semantics',{}); ep=i.get('Effective Priority',{})
res=[]
def c(name,cond,det=''): res.append((name,bool(cond),det))
c('hard_PASS',d['hard']=='PASS',d['hard']); c('gate_PASS',rv.get('status')=='PASS',rv.get('blocking_reasons'))
c('C1-C4_fail_0',all(((mp.get(k) or {}).get('fail') or 0)==0 for k in ('C1','C2','C3','C4')) and mp.get('status')=='PASS',{k:(mp.get(k) or {}).get('fail') for k in ('C1','C2','C3','C4')})
c('STG_proof_PASS',(mp.get('stg') or {}).get('status')=='PASS')
c('block_sequence_PASS',bs.get('status')=='PASS',bs.get('violations'))
c('proof_binding_v1518',all(pb.get(k) for k in ('sc_cc_hash','effective_priority_hash','block_sequence_hash','commitment_semantics_version')))
U=cs.get('units',{}); blk={b['stg']:b for b in bs.get('blocks',[])}
notes=[n['text'] for n in ep.get('notifications',[])]
if T=='T0':
    g=on('G6'); c('G6_hold_to_end_after_start',g and g[-1]==48 and len(g)==48-g[0]+1,f'{g[:1]}..{g[-1:]} n={len(g)}')
    c('G6_semantics',U['G6']['commitment']=='START_BASED_ON_SIMULATION' and U['G6']['stop_policy']=='HOLD_TO_END_AFTER_START',U['G6'])
if T=='T1':
    c('G6_all_48_rows',len(on('G6'))==48,len(on('G6'))); c('G6_full_horizon',U['G6']['commitment']=='CONTINUOUS_FULL_HORIZON')
if T=='T2':
    ev=(i.get('Stop Family Evidence') or {}).get('units',[])
    c('family_evidence_G6_G9',{e.get('unit') for e in ev}>={'G6','G9'} and all(e.get('status') in ('EVALUATED','TIDAK_START','TIDAK_LEGAL','TIDAK_ADA_STOP_LEGAL') for e in ev),[(e.get('unit'),e.get('family_current'),e.get('family_alt'),(e.get('alt') or {}).get('valid'),e.get('chosen')) for e in ev])
if T=='T3':
    g=on('G6'); c('G6_zero_from_16:00',all(v<0.01 for v in col('G6')[31:]),g[-1:] )
    c('notification_16:00',any('G6 adalah Unit Priority #1 Block 1, tetapi dijadwalkan stop mulai 16:00. Mulai 16:00 Effective Priority berubah menjadi G4 -> G3 -> G6.'==t for t in notes),notes)
    c('effective_row32',('G4 -> G3 -> G6' in ep.get('rows',{}).get('32','')) and ('G6 -> G4 -> G3' in ep.get('rows',{}).get('31','')),[ep.get('rows',{}).get('31'),ep.get('rows',{}).get('32')])
if T=='T4':
    g=col('G6'); c('G6_marker_05:00_zero',g[9]<0.01 and all(v<0.01 for v in g[:10]),g[8:12]); c('G6_free_05:30',g[10]>=20-0.01,g[10])
    c('S1_not_fed_by_G6','G6' not in blk['S1']['cc_feed'] and all(s<0.01 for s,a,b in zip(col('S1'),col('G3'),col('G4')) if a<0.01 and b<0.01),blk['S1']['cc_feed'])
    c('G6_SC_last',(i.get('SC CC Mode') or {}).get('sc_units')==['G6'] and any('G4 -> G3 -> G6' in t for t in notes),notes)
if T=='T5':
    g=col('G9'); c('G9_marker_05:00_zero',all(v<0.01 for v in g[:10]),g[8:12]); c('G9_free_05:30_ge_min_scload',g[10]>=30-0.01,g[10])
    dd=[abs(g[k]-g[k-1]) for k in range(11,48) if g[k]>0.01 and g[k-1]>0.01]; c('G9_ramp_le_55',max(dd)<=55+1e-6,max(dd))
    c('S3_fed_by_G8_only',blk['S3']['cc_feed']==['G8'],blk['S3']['cc_feed']); c('G9_SC',(i.get('SC CC Mode') or {}).get('sc_units')==['G9'])
if T=='T6':
    a=cs.get('auto',[]); c('G6_auto_lead',any(x.get('unit')=='g6' and 'LEAD' in x.get('reason','') for x in a),a)
    c('G6_hold_policy',U['G6']['stop_policy']=='HOLD_TO_END_AFTER_START' and U['G6']['required']); c('G4_G3_not_auto',not U['G4']['required'] and not U['G3']['required'])
if T=='T7':
    c('G4_lead_S1',blk['S1']['lead']['unit']=='G4',blk['S1']['lead']); c('SC_notification',any('G6 dipindahkan dari CC ke SC' in t for t in notes))
if T=='T8':
    b=blk['S1']; L=b['lead']; F=b['stg_first_positive_row']
    c('lead_first',L and all(a['first_load_row']>=L['first_load_row'] for a in b['additional']),L)
    c('S1_cold_hold',F is not None and F-L['first_load_row']>=b['stg_release_rows'],(L['first_load_row'],F,b['stg_release_rows']))
    c('additional_legal_window',all(a['legal'] and a['first_load_row']>=a['earliest_legal_first_load_row'] for a in b['additional']),[(a['unit'],a['first_load_row'],a.get('earliest_legal_first_load_row')) for a in b['additional']])
    c('connect_ge_S1_first_plus1',all(a['connect_row']>=F+1 for a in b['additional']),[(a['unit'],a['connect_row'],F) for a in b['additional']])
    lock=i.get('Block Sequence Lock') or []; c('no_all_at_once_without_proof',b['additional_before_established']<=1 or any(x.get('kind')=='SECOND_ADDITIONAL_PROOF' for x in lock),(b['additional_before_established'],lock))
    c('BB1_zero',all(v<0.01 for v in col('BB1')))
if T=='T9g':
    c('golden_CP_le_80.2521',float(i.get('Cost Production (USD/MWh)') or 99)<=80.2521,i.get('Cost Production (USD/MWh)')); c('golden_wall_le_15',d['wall_s']<=15,d['wall_s'])
fail=[r for r in res if not r[1]]
for n,ok,det in res: print(('PASS ' if ok else 'FAIL ')+T+' '+n+(' '+json.dumps(det,default=str)[:300] if det not in ('',None) else ''))
print(f"== {T} {tag} wall {d['wall_s']} CP {i.get('Cost Production (USD/MWh)')} HR {i.get('JBBK MM Heat Rate (BTU/kWh)')} -> {'PASS' if not fail else 'FAIL'}")
sys.exit(1 if fail else 0)
