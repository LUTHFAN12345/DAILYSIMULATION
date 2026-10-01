cd /home/claude/t5; S=$1; P=$2; OUT=$3; : > $OUT; R=/tmp/claude-0/f5root_$P; N=/home/claude/.npm-global/lib/node_modules
for n in ${CASES:-PGN31 PGN29 PGN32 PEP0 KP72_0}; do rm -rf $R; mkdir -p $R/jobs; cp $S/*.php $R/; python3 -c "
import json,sys; d=json.load(open('/home/claude/t5/integ/fast5/'+sys.argv[1].replace('PGN31_WARM','PGN32')+'.json')); [d.pop(k) for k in list(d) if k.startswith('_')]; json.dump(d,open(sys.argv[2]+'/input_data.json','w'))" $n $R
  (setsid node v3/proxy.js $P $R 6 >/dev/null 2>&1 </dev/null &); sleep 3
  W=""; n2=$n; case $n in *_WARM) W=31; n2=PGN32;; esac
  NODE_PATH=$N timeout 900 node integ/fast5.js http://127.0.0.1:$P $n $W >> $OUT 2>>$OUT.err; bash v4/kill_port.sh $P >/dev/null 2>&1; done
