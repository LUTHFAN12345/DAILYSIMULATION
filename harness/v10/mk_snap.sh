#!/bin/bash
# snapshot V10: jobs dengan FINAL kanonik jangkar (PGN 30 tanpa Actual) + BASE_ACT10, dihitung engine SRC
cd /home/claude/t5; SRC=${SRC:-tgs10}; S=/tmp/claude-0/mksnap10; rm -rf $S; mkdir -p $S/jobs; cp $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $S/
for SC in BASE_PGN30 BASE_ACT10; do /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $S $SC /tmp/claude-0/mksnap10_$SC.json 2>/dev/null | tail -1; done
rm -rf v10/snap_act; mkdir -p v10/snap_act; cp -a $S/jobs/_final $S/jobs/_tl v10/snap_act/; ls v10/snap_act/_final
