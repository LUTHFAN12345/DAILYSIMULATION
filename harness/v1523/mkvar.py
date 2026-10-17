import json,copy,sys
base=json.load(open('/tmp/w/h/snap.json'))
V={}
def mod(name,f):
    d=copy.deepcopy(base); f(d['data3']['modeling'],d); V[name]=d
mod('A0_actual',lambda m,d:None)
def a1(m,d): m['gas_quota']['pep']=48; m['gas_shortage_action']='none'; m['__fuel_decision_mode']='none'; m.pop('distillate_user_limit_litres',None)
mod('A1_gas_cukup',a1)
def a2(m,d): m['gas_shortage_action']='add_lng'; m['__fuel_decision_mode']='add_lng'; m['additional_lng']=12; m.pop('distillate_user_limit_litres',None)
mod('A2_lng',a2)
def a3(m,d):
    m['required_units']=['g4','g9','s1']; m['required_mode'].pop('g6'); m['required_mode']['g4']={'mode':'based_on_sim'}
    m['stop_mode'].pop('g6'); m['stop_mode']['g4']={'mode':'hold_to_end'}
mod('A3_cfg_G6_ke_G4',a3)
def a4(m,d):
    up=m['unit_priority']; up[2],up[3]=up[3],up[2]; m['block_priority']=copy.deepcopy(up)
mod('A4_priority_blok1_di_atas_blok2',a4)
def a5(m,d): m['block_required_user']['Block 2']=True
mod('A5_block2_required',a5)
def a6(m,d):
    for x in d['data1']: x['value']-=40
mod('A6_IE_minus40',a6)
def a7(m,d):
    for x in d['data1']: x['value']+=20
mod('A7_IE_plus20',a7)
def a8(m,d):
    m['required_units']=['g6','g5','s1']; m['required_mode'].pop('g9'); m['required_mode']['g5']={'mode':'based_on_sim'}
    m['stop_mode'].pop('g9'); m['stop_mode']['g5']={'mode':'hold_to_end'}
mod('A8_cfg_G9_ke_G5',a8)
for k,v in V.items(): json.dump(v,open(f'/tmp/w/reg/{k}.json','w'))
d=json.load(open('/root/.claude/uploads/892bda2b-3230-5398-ba77-04aa94363823/7c490821-input_data.json')); d['_fast_default']=True; d['_request_id']='x'
json.dump(d,open('/tmp/w/reg/B0_input_data.json','w'))
print(list(V)+['B0_input_data'])
