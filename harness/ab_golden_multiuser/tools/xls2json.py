import sys, json
from html.parser import HTMLParser
class P(HTMLParser):
    def __init__(s): super().__init__(); s.tables=[]; s.row=None; s.cell=None
    def handle_starttag(s,t,a):
        if t=='table': s.tables.append([])
        elif t=='tr': s.row=[]
        elif t in('td','th'): s.cell=''
    def handle_endtag(s,t):
        if t in('td','th') and s.row is not None: s.row.append((s.cell or '').strip()); s.cell=None
        elif t=='tr' and s.row is not None: s.tables[-1].append(s.row); s.row=None
    def handle_data(s,d):
        if s.cell is not None: s.cell+=d
p=P(); p.feed(open(sys.argv[1],encoding='utf-8').read()); json.dump(p.tables,open(sys.argv[2],'w'))
print([len(t) for t in p.tables])
