#!/usr/bin/env python3
"""Timeline job economic_review terakhir di akar: fase job, fase pipeline, keluarga, review, rute cepat."""
import json,glob,os,sys
root=sys.argv[1]; n=int(sys.argv[2]) if len(sys.argv)>2 else 1
fs=sorted(glob.glob(root+'/jobs/economic_review-*/result.json'), key=os.path.getmtime)[-n:]
for f in fs:
    r=json.load(open(f)); o=r['output']; i=o['info']; j=json.load(open(os.path.dirname(f)+'/job.json'))
    print('==', os.path.basename(os.path.dirname(f))[:34], r.get('mode'), 'cp', i.get('Cost Production (USD/MWh)'))
    print('  job', [(p['step'][:20],p['elapsed_s']) for p in j['progress']])
    rs=i.get('Run Status',{}); print('  pipe', [(p['phase'][:18],p['at_s']) for p in rs.get('phase_timeline',[])], 'core', rs.get('core_simulations'))
    e=i.get('Exact Candidate Space') or {}; print('  family wall',e.get('family_wall_s'),'nodes',e.get('family_nodes'),'computed',e.get('family_nodes_computed'),'reused',e.get('family_nodes_reused'))
    g=i.get('Global Commitment Review') or {}; print('  gcr eval',g.get('candidates_evaluated'),'/',g.get('candidates_total'),'screened',g.get('candidates_screened_v10'))
    v=i.get('V8 Priority Review') or {}; print('  review',v.get('status'),'wall',v.get('wall_s'),'rounds',v.get('rounds'),'sim',v.get('candidates_simulated'),'pre',v.get('candidates_prescreened'))
    ir=i.get('Incremental Recompute') or {}; print('  inc',ir.get('applied'),'wall',ir.get('wall_s'),'first',ir.get('first_valid_s'),'core',ir.get('core_evaluations'),'reason',ir.get('reason'))
    cs=i.get('V10 Candidate Screening') or {}; print('  fast lib_reused',cs.get('library_reused'),'lib_s',cs.get('library_built_s'),'gen',cs.get('candidates_generated'),'t2a',cs.get('candidates_evaluated_tier2a'),'t2b',cs.get('candidates_full_run_tier2b'),'far',cs.get('far_from_anchor'))
    sw=i.get('V11 Consolidation Sweep') or {}; print('  sweep',sw.get('wall_s'),'full',sw.get('full_run'),'| pre',json.dumps(i.get('Exact Family Prepass'))[:120])
