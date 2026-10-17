import json,sys
o=json.load(open(sys.argv[1]))
if 'output' in o: o=o['output']
d=o['data']; i=o['info']
units=['BB2','G8','G9','S3','G2','G5','G1','S2','G6','G4','G3','S1','G10','G7']
print('CP',i.get('Cost Production (USD/MWh)'),'HR',i.get('JBBK MM Heat Rate (BTU/kWh)'),'Dist',i.get('Distillate Used (l)'),'Gas',i.get('Total Gas Used (BBTUD)'))
print('row  t     '+' '.join(f'{u:>5}' for u in units)+'    IE  Exp  Dist')
for k,r in enumerate(d):
    print(f'{k+1:3d} {r["Time"][-5:]} '+' '.join(f'{float(r.get(u,0) or 0):5.1f}' for u in units)+f' {r["IE"]:5.0f} {r["Export_PLN"]:4.0f} {r["Dist_Total"]:5.0f}')
