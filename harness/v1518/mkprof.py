# mkprof.py <src_dir> <dst_dir> : salin sumber + tracer CORE/ISO ke /tmp/claude-0/ptrace.log (hanya salinan profil)
import sys,shutil,os
S,D=sys.argv[1],sys.argv[2]
for f in ['index.php','run.php','worker02.php','worker_functions.php','saved_data_store.php']: shutil.copy(os.path.join(S,f),os.path.join(D,f))
p=os.path.join(D,'worker02.php'); s=open(p).read()
TR="""function __ptr(string $m): void { @file_put_contents('/tmp/claude-0/ptrace.log', sprintf("%.3f %d %s\\n", microtime(true), getmypid(), $m), FILE_APPEND); }
"""
a="function pp_run_simulation_core(array $input): array {\n"
assert s.count(a)==1
s=s.replace(a,TR+a+"""    $__t = microtime(true); $__bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5); $__tr=[]; foreach ($__bt as $f) $__tr[] = basename($f['file']??'').':'.($f['line']??0);
    __ptr('CSTART ' . implode(' ', $__tr));
    $__o = (function () use ($input) {
""",1)
# close wrapper: find end of function = first "\n}\n" after the anchor
i=s.index(a)+len(a); j=s.index("\n}\n",i)
s=s[:i]+s[i:j]+"\n    })();\n    __ptr(sprintf('CEND %.2fs gas %.3f cp %s ', microtime(true)-$__t, (float)($__o['info']['Total Gas Used (BBTUD)']??0), json_encode($__o['info']['Cost Production (USD/MWh)']??null)) . implode(' ', $__tr));\n    return $__o;"+s[j:]
b="    $k = pp_v1518_iso_key($in);\n"
assert s.count(b)==1
s=s.replace(b,b+"    __ptr('ISO ' . $k . ' ' . (($jid !== null && $jid !== '' && is_file(pp_v1518_iso_file($jid, $k))) ? 'HIT' : 'MISS'));\n")
c="    $out['info']['PGN Supplier Repair Review']=['attempts'=>$attempts,"
assert s.count(c)==1
s=s.replace(c,"    __ptr(sprintf('SUP att=%d rej=%d mode=%s t=%.2f last=%s obs=%s', $attempts, $rejectedAttempts, $searchMode, microtime(true)-$__supT0, json_encode(array_slice($ev,-1)), json_encode(array_map(function($b){return [round($b['internal_target'],3),round($b['physical_pipe_used'],3),substr($b['side'],0,1)];}, (array)$bracketObservations))));\n"+c)
a="function pp_actual_gas_compensation_raw(array $input, array $out): array {\n"
s=s.replace(a,a+"    $__supT0 = microtime(true);\n")
open(p,'w').write(s)
import re
def wrapfn(path, fns):
    t=open(path).read()
    for fn in fns:
        m=re.search(r'\nfunction '+fn+r'\((.*?)\)(\s*:\s*[\?\w]+)?\s*\{\n',t)
        if not m: print('miss',fn); continue
        sig=m.group(0); args=m.group(1); rt=m.group(2) or ''
        names=re.findall(r'&?\$(\w+)',re.sub(r'=\s*[^,]+','',args)); call=', '.join('$'+n for n in names)
        ret='' if 'void' in rt else 'return '
        w='\nfunction '+fn+'('+args+')'+rt+" { $__t=microtime(true); __ptr('F> "+fn+"'); try { "+ret+'__o_'+fn+'('+call+"); } finally { __ptr(sprintf('F< "+fn+" %.2fs', microtime(true)-$__t)); } }\n"
        t=t.replace(sig,w+sig.replace('function '+fn+'(','function __o_'+fn+'(',1),1)
    open(path,'w').write(t)
wrapfn(os.path.join(D,'run.php'),['pp_v15_merit_proof','pp_simulation_acceptance_review','pp_v1518_family_evidence','pp_v8_priority_review','pp_v1517_proof_binding','pp_v6_priority_polish'])
wf=os.path.join(D,'worker_functions.php'); t=open(wf).read()
a="function pp_phase_mark(string $name): void {\n"
t=t.replace(a,a+"    if (function_exists('__ptr')) __ptr('PHASE ' . $name);\n",1); open(wf,'w').write(t)
