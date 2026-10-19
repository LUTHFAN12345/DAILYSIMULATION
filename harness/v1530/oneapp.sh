# oneapp.sh <srcdir> <cpu> <tag> <input> [ENV=V ...] -> /tmp/w/one/<tag>.json (fresh jobs dir)
S=$1; C=$2; N=$3; I=$4; shift 4; X=/tmp/w/one/app_$N; R=/home/user/DAILYSIMULATION
rm -rf "/tmp/w/one/app_$N"; mkdir -p "$X/jobs" /tmp/w/one
for f in run.php worker02.php worker_functions.php index.php saved_data_store.php weekly.php weekly_ui.php; do cp "$S/$f" "$X/"; done
cp -r "$S/config" "$S/install" "$S/database" "$X/"
env "$@" taskset -c $C /opt/php74/bin/php $R/harness/v1526/runjob2.php "$X" "$I" "/tmp/w/one/$N.json" >/dev/null 2>&1
python3 - "$N" <<'P'
import json,sys
r=json.load(open(f'/tmp/w/one/{sys.argv[1]}.json')); o=r.get('output') or {}; i=o.get('info') or {}
print(sys.argv[1], o.get('status'), r.get('publish_allowed'), 'CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'), 'wall',(r.get('__bench') or {}).get('wall_s'), 'cpu',(r.get('__bench') or {}).get('cpu_s'))
P
