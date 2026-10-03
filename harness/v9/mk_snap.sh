#!/bin/bash
# snapshot V9: jobs dengan FINAL kanonik jangkar (PGN 30 tanpa Actual) + BASE_ACT10, dihitung engine SRC
cd /home/claude/t5; SRC=${SRC:-tgs9}; S=/tmp/claude-0/mksnap; rm -rf $S; mkdir -p $S/jobs; cp $SRC/{run,worker02,worker_functions,index,saved_data_store}.php $S/
for SC in BASE_PGN30 BASE_ACT10; do /usr/local/bin/php74 -d max_execution_time=0 v8/job_dump.php $S $SC /tmp/claude-0/mksnap_$SC.json 2>/dev/null | tail -1; done
rm -rf v9/snap_act; mkdir -p v9/snap_act; cp -a $S/jobs/_final $S/jobs/_tl v9/snap_act/; ls v9/snap_act/_final
