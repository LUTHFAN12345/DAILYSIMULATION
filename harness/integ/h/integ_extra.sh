#!/bin/bash
# INTEGRASI: Fastest UI (CO OFF/ON), parity Change Over (V12 checkpoint vs source uji vs Copilot), merit CO OFF vs Copilot. Arg: <src> <port> <outdir>
cd /home/claude/t5; S=$1; P=$2; O=$3; mkdir -p $O; unset PP_LEGACY_TARGET
echo "=== INTEGRASI: FASTEST - DEFAULT UI (Change Over OFF dan ON) ==="
bash integ/fast_ui.sh $S $P $O 2>&1
echo "=== INTEGRASI: PARITY CHANGE OVER (20 kasus x 3 source) ==="
rm -rf integ/o3_integ; V12_SAVE_OUT=/home/claude/t5/integ/o3_integ bash v12/http1.sh $S $((P+1)) integ/CO3_integ.jsonl cold integ/co3_cases.txt v12/snap_empty > /dev/null 2>&1
python3 integ/co_parity.py $O/CO_PARITY.md $O/CO_PARITY.json
python3 - $O/CO_PARITY.json <<'PY'
import json,sys
J=json.load(open(sys.argv[1]))
for x in J:
    r=x['results']; ok=x['parity_integ_vs_v12'] and not (r['ckpt']['gate']=='PASS' and r['integ']['gate']!='PASS') and (r['integ']['gate']!='PASS' or all(r['integ']['sequence'].values()))
    print(('PASS' if ok else 'FAIL')+'  CO_%s identik V12 checkpoint, tanpa regresi gate, urutan wajib  -- gate %s/%s overlap %s cp %s' % (x['case'], r['ckpt']['gate'], r['integ']['gate'], r['integ']['overlap_rows'], r['integ']['cp']))
PY
echo "=== INTEGRASI: MERIT DISPATCH CHANGE OVER OFF vs KANDIDAT COPILOT (12 state) ==="
rm -rf integ/out_merit_integ; V12_SAVE_OUT=/home/claude/t5/integ/out_merit_integ bash v12/http1.sh $S $((P+2)) $O/MERIT_INTEG.jsonl cold v12/merit_cases.txt > /dev/null 2>&1
python3 - $O/MERIT_INTEG.jsonl integ/MERIT_cop.jsonl <<'PY'
import json,sys
a={json.loads(l)['sc']:json.loads(l) for l in open(sys.argv[1]) if l.startswith('{')}; b={json.loads(l)['sc']:json.loads(l) for l in open(sys.argv[2]) if l.startswith('{')}
for k in b:
    x=a.get(k,{}); y=b[k]; ok=x.get('sig')==y.get('sig') and x.get('cp')==y.get('cp') and x.get('hr')==y.get('hr')
    print(('PASS' if ok else 'FAIL')+'  MERIT_%s dispatch/CP/HR = Copilot  -- %s/%s cp %s/%s hr %s/%s' % (k[:40], x.get('sig'), y.get('sig'), x.get('cp'), y.get('cp'), x.get('hr'), y.get('hr')))
PY
