#!/bin/bash
# regresi fx final: cold (bersihkan job per fixture) + rantai warm IE + rantai warm Actual Gas
S=/home/claude/ab2/devm; O=/home/claude/ab2/op/fxfinal; mkdir -p $O; cd /home/claude/ab2/op
echo "== COLD"; bash runfx.sh $S $O/cold fx/D0_golden.json fx/D2_g6_stop2100.json fx/D3_g6_unavail.json fx/D3b_first_prio_unavail.json fx/D4_g6_stop0800.json fx/D4b_two_units.json fx/D4c_two_units.json fx/D5_g6_stop1000_1400.json fx/D5_two_blocks_fixload.json fx/D5b_two_blocks_stopwin.json fx/R1_pgn25_lng18_pep36_ak4.json fx/R2_minflow26.json fx/R2_minflow45_infeas.json fx/SR1_g5_req1600.json fx/SR2_g6_req2100.json fx/SR3_g5_simmust.json fx/SR4_g6_stop_or_cont.json fx/SR5_g5_continuous.json fx/SR6_g6_req0800.json
echo "== IE WARM"; rm -rf $S/jobs; bash ierun.sh $S $O/ie fx/IE0_golden.json fx/IE3_golden.json fx/IE4_golden.json fx/IE5_golden.json fx/IE6_golden.json fx/IE2_golden.json fx/IE1_golden.json fx/IE7_golden.json fx/IE0_golden.json fx/IE8_golden.json
echo "== A WARM"; rm -rf $S/jobs; bash ierun.sh $S $O/a fx/A0_base.json fx/A1_pgn_r13.json fx/A3_mm_r13.json fx/A7_under.json fx/A0_base.json fx/A5_multi.json fx/A2_ffj_r13.json fx/A4_all_r13.json fx/A6_over.json
echo DONE
