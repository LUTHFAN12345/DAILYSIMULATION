import json, sys, collections
# prof.py <a.prof> <b.prof>: core simulation count/time per caller (first non-generic pp_ frame chain), unique/duplicate input hash
SKIP = {'pp_v1525_sim_act_dist', 'pp_run_simulation', 'pp_tl_eval', 'pp_v13f_sim'}
def load(f):
    L = json.load(open(f)); by = collections.defaultdict(lambda: [0, 0.0, set(), 0])
    seen = set(); dup = 0; dupt = 0.0
    for e in L:
        ch = [c for c in e['c'] if c not in SKIP]
        k = ' < '.join(ch[:3])
        b = by[k]; b[0] += 1; b[1] += e['s']
        if e['h'] in b[2]: b[3] += 1
        b[2].add(e['h'])
        if e['h'] in seen: dup += 1; dupt += e['s']
        seen.add(e['h'])
    return L, by, dup, dupt, len(seen)
A = load(sys.argv[1]); B = load(sys.argv[2])
for n, X in (('A', A), ('B', B)):
    print(n, 'sims', len(X[0]), 'time %.1f' % sum(e['s'] for e in X[0]), 'unique', X[4], 'dup', X[2], 'dup_time %.1f' % X[3])
ks = set(A[1]) | set(B[1])
rows = []
for k in ks:
    a = A[1].get(k, [0, 0, set(), 0]); b = B[1].get(k, [0, 0, set(), 0])
    rows.append((b[1] - a[1], k, a[0], b[0], a[1], b[1], a[3], b[3]))
rows.sort(reverse=True)
for r in rows[:int(sys.argv[3]) if len(sys.argv) > 3 else 25]:
    print('%+7.1fs  n %3d->%3d  t %6.1f->%6.1f  dupIn %d->%d  %s' % (r[0], r[2], r[3], r[4], r[5], r[6], r[7], r[1][:170]))
