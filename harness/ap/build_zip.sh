#!/bin/bash
# build_zip.sh : bangun php_simulation_AUTO_PGN_REDISTRIBUTION_SR_PV_FINAL.zip dari apfreeze (freeze final) + apdocs + laporan/tools/fixture
set -e
T=/home/claude/t5; A=$T/integ/ap; Z=php_simulation_AUTO_PGN_REDISTRIBUTION_SR_PV_FINAL.zip; S=$T/zipstage; rm -rf $S
mkdir -p $S/reports/{xampp,linux,freeze0_xampp,freeze1_xampp,freeze1_linux,perf,a2inv,c4,h/baseline_72168ee} $S/tools $S/fixtures/pl $S/fixtures/h
cp $T/apfreeze/{run.php,worker02.php,worker_functions.php,index.php,saved_data_store.php} $S/
cp $T/apdocs/{AUTO_PGN_REDISTRIBUTION_AUDIT,SR_FOLLOW_PV_AUDIT,PV_SIMULATION_DATA_AND_CHART,C4_TARGETED_AUDIT,PERFORMANCE_T6,TARGETED_TEST_A_H,CARA_PASANG_XAMPP,CARA_PASANG_LINUX}.md $S/
# hasil final per case (A/A2/C/D freeze final, B/E/CM freeze-1) + log matriks
cp $A/final/xampp/*.json $S/reports/xampp/; cp $A/final/linux/*.json $S/reports/linux/
cp $A/final_xampp_f2/matrix.log $S/reports/xampp/matrix_freeze_final.log; cp $A/final_linux_f2/matrix.log $S/reports/linux/matrix_freeze_final.log
cp $A/final/parity.txt $A/final/summary.md $S/reports/
cp $A/final_xampp_prefix/* $S/reports/freeze0_xampp/
cp $A/final_xampp/* $S/reports/freeze1_xampp/; cp $A/final_linux/* $S/reports/freeze1_linux/
cp $A/perf_linux_f2/A.json $S/reports/perf/A_linux_standalone.json
cp $A/a2inv/lin1.json $A/a2inv/xa1.json $A/a2inv/A2_ROOT_CAUSE_TRACE.txt $S/reports/a2inv/; cp $A/a2inv/ui_lin2/A2.json $S/reports/a2inv/A2_ui_linux_rerun_freeze1.json
cp $A/reports_extra/c4/* $S/reports/c4/
cp $A/h/* $S/reports/h/; cp $T/integ/pv/t8/pv_*.log $S/reports/h/baseline_72168ee/
cp $A/{ap_ui.js,run_matrix.sh,mkpayload.php,fast_replay.py,h_smoke.sh,c4_table.py,parity.py,summ.py,build_zip.sh} $S/tools/
cp $T/integ/linux/setup_linux.sh $T/integ/bs/replay.py $T/integ/bs/sig.py $S/tools/
cp $T/integ/input_pv.json "$T/integ/pv/CSV Load Pred dan Dispatch (5 Oct).csv" $A/IE_Dispatch_PV_5Oct.xlsx $A/IE_Dispatch_PV_semicolon_comma.csv $S/fixtures/
cp $A/pl/*.json $S/fixtures/pl/
cp $T/integ/fast5/U31.json $T/integ/fast5/PGN25_PEP30_KP0.json $T/integ/dist/D_rec.json $T/integ/co3/WB09_3_B1B2_SIMSIM_COLD.json $S/fixtures/h/
( cd $S && sha256sum run.php worker02.php worker_functions.php index.php saved_data_store.php \
    AUTO_PGN_REDISTRIBUTION_AUDIT.md SR_FOLLOW_PV_AUDIT.md PV_SIMULATION_DATA_AND_CHART.md C4_TARGETED_AUDIT.md PERFORMANCE_T6.md TARGETED_TEST_A_H.md CARA_PASANG_XAMPP.md CARA_PASANG_LINUX.md > SHA256SUMS.txt )
rm -f $T/$Z; ( cd $S && TZ=UTC zip -X -q -r $T/$Z SHA256SUMS.txt run.php worker02.php worker_functions.php index.php saved_data_store.php *.md reports tools fixtures )
sha256sum $T/$Z
