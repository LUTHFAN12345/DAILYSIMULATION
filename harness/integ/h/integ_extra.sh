#!/bin/bash
# INTEGRASI: MERIT DISPATCH 12 state (22 butir + kriteria FAIL + bukti STG), smoke Change Over (2 kasus vs V12 checkpoint),
# Fastest UI (CO OFF/ON). Arg: <src> <port> <outdir>
cd /home/claude/t5; S=$1; P=$2; O=$3; mkdir -p $O; unset PP_LEGACY_TARGET; M=$O/merit; mkdir -p $M
echo "=== INTEGRASI: MERIT DISPATCH 12 STATE (cold, counterfactual tanpa redistribusi, tanpa pembantu, warm) ==="
rm -rf $M/out_*; V12_SAVE_OUT=/home/claude/t5/$M/out_cold bash v12/http1.sh $S $((P+1)) $M/COLD.jsonl cold v12/merit_cases.txt > /dev/null 2>&1
PP_V8_PRIORITY=0 PP_V6_POLISH=0 V12_SAVE_OUT=/home/claude/t5/$M/out_before bash v12/http1.sh $S $((P+2)) $M/BEFORE.jsonl cold v12/merit_cases.txt > /dev/null 2>&1
PP_V4_HELPERS=0 V12_SAVE_OUT=/home/claude/t5/$M/out_nohelp bash v12/http1.sh $S $((P+3)) $M/NOHELP.jsonl cold v12/merit_cases.txt > /dev/null 2>&1
V12_SAVE_OUT=/home/claude/t5/$M/out_warm bash v12/http1.sh $S $((P+4)) $M/WARM.jsonl warm v12/merit_cases.txt > /dev/null 2>&1
/usr/local/bin/php74 -d memory_limit=2G integ/stg_proof.php $M v12/merit_cases.map $M/STG_PROOF.json > /dev/null 2>&1
python3 integ/merit_report.py $M v12/merit_cases.map $O/MERIT_REPORT.md $O/MERIT_REPORT.json 2>&1 | grep -E "^(PASS|FAIL)"
echo "=== INTEGRASI: SMOKE CHANGE OVER (B2->B1 dan B1->B2 sim/sim feasible) vs V12 CHECKPOINT ==="
bash v12/http1.sh $S $((P+5)) $O/CO_SMOKE.jsonl cold integ/co_smoke.txt v12/snap_empty > /dev/null 2>&1
python3 - $O/CO_SMOKE.jsonl integ/CO3_ckpt.jsonl <<'PY'
import json,sys
A={json.loads(l)['sc']:json.loads(l) for l in open(sys.argv[2]) if l.startswith('{')}
for l in open(sys.argv[1]):
    if not l.startswith('{'): continue
    b=json.loads(l); a=A.get(b['sc'],{}); ok=(a.get('sig'),a.get('cp'),a.get('hr'))==(b.get('sig'),b.get('cp'),b.get('hr'))
    print(('PASS' if ok else 'FAIL')+'  CO_SMOKE_%s identik V12 checkpoint  -- %s/%s cp %s/%s hr %s/%s' % (b['sc'].split('/')[-1][:-5], a.get('sig'), b.get('sig'), a.get('cp'), b.get('cp'), a.get('hr'), b.get('hr')))
PY
echo "=== INTEGRASI: FASTEST - DEFAULT UI (Change Over OFF dan ON) ==="
bash integ/fast_ui.sh $S $((P+6)) $O 2>&1
