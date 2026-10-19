import sys, collections, re
# minimal callgrind parser: self cost (Time_(10ns)) per function + call counts
self_t = collections.Counter(); calls = collections.Counter(); names = {}
fn = None; incall = False
for line in open(sys.argv[1], errors='replace'):
    line = line.rstrip('\n')
    if line.startswith('fn='):
        m = re.match(r'fn=\((\d+)\)(?: (.*))?', line); k = m.group(1)
        if m.group(2): names[k] = m.group(2)
        fn = k; incall = False; continue
    if line.startswith('cfn='):
        m = re.match(r'cfn=\((\d+)\)(?: (.*))?', line)
        if m.group(2): names[m.group(1)] = m.group(2)
        cfn = m.group(1); continue
    if line.startswith('calls='):
        calls[cfn] += int(line.split('=')[1].split()[0]); incall = True; continue
    if line and line[0].isdigit() and fn is not None:
        p = line.split()
        if incall: incall = False; continue
        if len(p) >= 2: self_t[fn] += int(p[1])
tot = sum(self_t.values())
for k, v in self_t.most_common(int(sys.argv[2]) if len(sys.argv) > 2 else 30):
    print('%5.1f%%  calls %8d  %s' % (100.0 * v / tot, calls[k], names.get(k, k)))
