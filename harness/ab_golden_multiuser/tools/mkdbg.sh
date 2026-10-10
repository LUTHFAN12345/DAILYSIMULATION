#!/bin/bash
# mkdbg.sh : salin dev -> dbg2 + dump NEED/levers (env PP_DBGP)
rm -rf /home/claude/ab2/dbg2 && cp -r /home/claude/ab2/dev /home/claude/ab2/dbg2 && cd /home/claude/ab2/dbg2 && python3 -I - <<'PY'
p='worker_functions.php'; s=open(p).read()
def arr(n): return "'"+n+"='.json_encode(array_map(function($v){return round($v,1);},$"+n+"))"
dump=lambda tag: "    if (getenv('PP_DBGP')) { $xx=[]; foreach (array_keys($need ?? []) as $r) $xx[]=$r+1; file_put_contents(getenv('PP_DBGP'), '"+tag+" need='.implode(',',$xx).' '."+".' '.".join(arr(x) for x in ['g1row','g2row','g3row','g4row','g5row','g6row'])+".\"\\n\", FILE_APPEND); }\n"
a="    /* V15.16 RUNNING-FIRST"
s=s.replace(a, dump('PRE_RF')+a,1)
a3="    if ($need) {\n        $rn = array_keys($need); $first = min($rn); $last = max($rn);\n        /* PRIORITY AUDIT (sistemik — GANTI"
s=s.replace(a3, dump('POST_RF')+a3,1)
a4="        $rkB1 = array_flip("
s=s.replace(a4, dump('POST_B2')+a4,1)
a5="    if (!empty($b1PrefAB) && isset($g3Esc)) {"
s=s.replace(a5, dump('POST_B1')+a5,1)
a6="    /* ===== MEMOISASI EKSAK $exAt"
s=s.replace(a6, dump('END_LEV')+a6,1)
open(p,'w').write(s)
PY
php -l /home/claude/ab2/dbg2/worker_functions.php | tail -1
cd /home/claude/ab2/dbg2 && python3 -I - <<'PY'
import re
p='worker02.php'; s=open(p).read()
s=s.replace("function pp_run_simulation_once_raw(array $input): array {", """function pp_dbgp(string $t, $g): void { $f = getenv('PP_DBGP'); if (!$f || !is_array($g)) return; foreach (explode(',', getenv('PP_DBGU') ?: 'G5') as $u) { $on = []; foreach ($g as $k => $r) if ((float)(is_array($r) ? ($r[$u] ?? ($r[strtolower($u)] ?? 0)) : $r) > 0.01) $on[] = $k + 1;
  $s = $on ? ($on[0] . '-' . end($on) . '(' . count($on) . ')') : '-'; file_put_contents($f, $t . ' ' . $u . '=' . $s . "\\n", FILE_APPEND); } }
function pp_run_simulation_once_raw(array $input): array {""",1)
L=s.split('\n')
idx=[i for i,l in enumerate(L) if 'pp_shape_final_dispatch($genRows, $d3, $model, $ieVals, $shaperQuota' in l][0]
L.insert(idx+1, "    pp_dbgp('AFTER_SHAPER', $genRows);")
idx=[i for i,l in enumerate(L) if '$su = pp_apply_startup_constraints($genRows, $d3, $model);' in l or '$suFinal = pp_apply_startup_constraints($genRows, $d3, $model);' in l][0]
L.insert(idx, "    pp_dbgp('BEFORE_SHAPER', $genRows);")
open(p,'w').write('\n'.join(L))
p='worker_functions.php'; L=open(p).read().split('\n')
start=[i for i,l in enumerate(L) if l.startswith('function pp_shape_final_dispatch')][0]
cands=[]; inc=False
for i in range(start+1, len(L)):
    st=L[i].strip()
    if inc:
        if '*/' in st: inc=False
        continue
    if st.startswith('/*') and '*/' not in st: inc=True; continue
    if re.match(r'^    [\$a-z]', L[i]) and not L[i].startswith('    }'):
        prev=L[i-1].rstrip()
        if prev.endswith(';') or prev.endswith('}') or prev.endswith('*/'): cands.append(i)
sel=[]; last=-999
for i in cands:
    if i-last>=40: sel.append(i); last=i
for i in reversed(sel):
    L.insert(i, "    if (isset($rows)) pp_dbgp('Rw"+str(i+1)+"', $rows); if (isset($genRows)) pp_dbgp('Gn"+str(i+1)+"', $genRows);")
open(p,'w').write('\n'.join(L))
PY
php -l /home/claude/ab2/dbg2/worker_functions.php | tail -1; php -l /home/claude/ab2/dbg2/worker02.php | tail -1
