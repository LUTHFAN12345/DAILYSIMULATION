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
python3 integ/merit_parity.py integ/merit_ref/COLD.jsonl $M $O/MERIT_PARITY.md 2>&1 | grep -E "^(PASS|FAIL)"
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
echo "=== FASTEST - DEFAULT: 5 INPUT (PGN25+PEP30+KP72 0, PGN29, PGN30, PGN31, PGN32) — klik Run -> Simulation Data ==="
CASES="PGN25_PEP30_KP0 PGN29 PGN30 PGN31 PGN32" bash integ/fast5.sh $S $((P+7)) $O/FASTEST_5.jsonl > /dev/null 2>&1
python3 - $O/FASTEST_5.jsonl <<'PY'
import json,sys
for l in open(sys.argv[1]):
    r=json.loads(l); T=r.get('trace') or {}; t0=T.get('run_click_ms') or 0
    if r['case'].startswith('PGN25'):
        ok = r['label'].startswith('GAS_SHORTAGE') and not r.get('modal'); print(('PASS' if ok else 'FAIL')+'  FAST_%s keputusan Gas Shortage (tanpa kandidat fully valid), tanpa hasil invalid  -- %.2f s' % (r['case'], r['t_show_s'])); continue
    gap=(T['simulation_data_opened_ms']-T['first_fully_valid_ms'])/1000 if T.get('simulation_data_opened_ms') and T.get('first_fully_valid_ms') else 99
    ok = r['label']=='FASTEST VALID PLAN' and r.get('rows')==48 and r.get('hard')=='PASS' and r.get('merit')=='PASS' and r.get('c4')==0 and str(r.get('stg','')).endswith('langgar 0') and r.get('simdata_shown') and gap<=1.0 and not r.get('modal')
    print(('PASS' if ok else 'FAIL')+'  FAST_%s kandidat fully valid pertama -> Simulation Data (<= 1 s), hard/merit/C4/STG PASS  -- %.2f s, gap %.2f s, %s/%s, CP %s' % (r['case'], (T.get('simulation_data_opened_ms',0)-t0)/1000, gap, r.get('checked'), r.get('valid'), r.get('cp')))
PY
echo "=== DISTILLATE: SATU UNIT DULU + WARNA/TOOLTIP NUMERIK (PGN25+PEP30+KP72 0, aksi Distillate) ==="
DR=/tmp/claude-0/distui_$P; rm -rf $DR; mkdir -p $DR/jobs; cp $S/*.php $DR/; python3 -c "
import json,sys; d=json.load(open('/home/claude/t5/integ/dist/D_rec.json')); [d.pop(k) for k in list(d) if k.startswith('_')]; json.dump(d,open(sys.argv[1]+'/input_data.json','w'))" $DR
(setsid node v3/proxy.js $((P+8)) $DR 6 >/dev/null 2>&1 </dev/null &); sleep 3
NODE_PATH=/home/claude/.npm-global/lib/node_modules timeout 600 node integ/dist_ui.js http://127.0.0.1:$((P+8)) $O/DIST_UI.md 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-260
bash v4/kill_port.sh $((P+8)) >/dev/null 2>&1
echo "=== SAVED_DATA_STORE: 8 UJI + HAPUS/METADATA/MIGRASI/PEMULIHAN ==="
timeout 900 /usr/local/bin/php74 integ/store_test.php $S $((P+9)) $O/STORE_TEST.md 2>&1 | grep -E "^(PASS|FAIL)" | cut -c1-260
