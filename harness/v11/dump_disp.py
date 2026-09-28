import json,sys
d=json.load(open(sys.argv[1])); o=d.get('output') or d; i=o['info']; rows=o['data']
print('CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('Heatrate'),'JBBK HR',i.get('Jababeka Heat Rate (BTU/kWh)'),'startups',i.get('Startup Events (GTG)'),'PGN',i.get('PGN Pipe Used (BBTUD)'),'Total cost',i.get('Total Cost (USD)'),'Net',i.get('Net Production (MWh)'))
keys=list(rows[0].keys()); print(keys)
cols=['G1','G2','G3','G4','G5','G6','S1','S2','G8','G9','S3','G7','G10','GE1','EXPORT PLN','SPINNING RESERVE','BUSFLOW']
cols=[c for c in cols if c in rows[0]] or cols
for r,x in enumerate(rows):
    print(r+1, ' '.join('%s=%s'%(c,x.get(c)) for c in cols))
