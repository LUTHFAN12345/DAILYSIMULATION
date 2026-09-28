const { chromium } = require('/opt/node22/lib/node_modules/playwright');
const U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3'];
function mkOut(cp,cnt){ const data=[]; for(let i=0;i<48;i++){ const r={TIME:'r'+(i+1)}; U.forEach(u=>r[u]=(u==='G8'||u==='G9')?100:(u==='G1'?20:0)); data.push(r);} 
  const info={'Cost Production (USD/MWh)':cp,'Total Cost (USD)':920103.47,'Net Production (MWh)':14501.94,'PLN Export Compliance':'OK','Gas Quota Status':'WITHIN QUOTA','Residual Gas Shortage (BBTUD)':0,'JBBK MM Heat Rate (BTU/kWh)':8109.13,
   'V11 Candidate Comparison':{cp_min:cp,band_upper:cp*1.002,candidates_valid:7,candidates_in_band:5,winner:'POLISH_UNIT_PRIORITY_LANJUTAN',winner_cp:cp,winner_heat_rate:8109.13},
   'V11 Consolidation Sweep':{status:'DIEVALUASI',fragmentation_rows:5,generated:5,screened_tier1:4,full_run:1,valid:1,wall_s:1.2},
   'V11 Low Load Fragmentation Audit':{status:'PASS_WITH_REASON',rows_flagged:5,findings:5,unresolved:0,unit_rows:{G5:[33,34,35,37,38]}},
   'V11 CP Audit':{status:'PASS',cost_production:cp,total_cost_over_net:cp,heat_rate_jbbk_mm:8109.13,fuel_accounts:[{priced:true},{priced:true}],issues:[]}};
  if(cnt) info['V11 Candidate Counters']=cnt; return {ok:true,data,info}; }
const PAY={data1:[{}],data2:[{}],data3:{modeling:{}}};
async function scenario(b,name,T,tlBest,extra){
  const p=await b.newPage(); const errs=[];
  p.on('pageerror',e=>errs.push('PAGEERROR '+e.message)); p.on('console',m=>{ if(m.type()==='error') errs.push('CONSOLE '+m.text()); });
  await p.route('**/run.php**',async route=>{ const u=route.request().url(); let body={ok:true};
    if(/mode=run&tl=1/.test(u)) body={ok:true,async_job:{job_id:'J1',input_hash:'h'}};
    else if(/mode=tl_best/.test(u)) body=tlBest(u);
    else if(/mode=job_poll/.test(u)) body={ok:true,result:{output:mkOut(63.4469,{candidates_checked:12,candidates_valid:7,candidates_full_run:8,candidates_screened_out:4,best_candidate_in_valid:true})}};
    await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(body)}); });
  await p.goto('http://127.0.0.1:8765/index.php'); await p.waitForTimeout(500);
  const t0=Date.now();
  const res=await p.evaluate(async ({T,extra,PAY})=>{
    const out=(x)=>{ const b=document.getElementById('tl-banner'); const a=document.getElementById('tl-audit');
      return {cells:b?[...b.querySelectorAll('.v11-sum-c')].map(c=>c.innerText.replace(/\s+/g,' ')):null, n:b?b.querySelectorAll('.v11-sum-c').length:0,
        ds:b?Object.assign({},b.dataset):null, audit:a?a.innerText.slice(0,900):null, rm:(document.getElementById('run-msg')||{}).innerText, x}; };
    const sel=document.getElementById('f-tl-target'); sel.value=String(T);
    if(extra==='max'){ V11_RUN_T0=performance.now()-3200; V11_SUM_DONE=false; finalizeSimulationUI(JSON.parse(JSON.stringify(PAY)),window.__OUT,null); return out(); }
    if(extra==='prov'){ V11_RUN_T0=performance.now()-1500; gsdProvisionalBanner(window.__OUT); return out(); }
    if(extra==='inv'){ tlBanner('X','green',{target:'25',elapsed:3,evaluated:0,valid:0,cp:63.1,checks_ok:true}); const a=out(); tlBanner('X','green',{target:'25',elapsed:3,evaluated:2,valid:5,cp:63.1,checks_ok:true}); const c=out(); tlBanner('X','amber',{target:'25',elapsed:3,evaluated:4,valid:0,cp:null}); return {a,c,d:out()}; }
    await runTimeLimited(JSON.parse(JSON.stringify(PAY)),T); return out();
  },{T,extra,PAY}).catch(e=>({evalError:String(e)}));
  console.log('=== '+name+' ('+((Date.now()-t0)/1000).toFixed(1)+' s)'); console.log(JSON.stringify(res,null,1)); console.log('ERRORS: '+(errs.length?errs.join(' | '):'none'));
  await p.close(); return errs.length;
}
(async()=>{
  const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome'});
  let E=0; const OUTJS=JSON.stringify(mkOut(63.4469,{candidates_checked:12,candidates_valid:7,candidates_full_run:8,candidates_screened_out:4,best_candidate_in_valid:true}));
  const ctxInit=async()=>{};
  b.newPageOrig=b.newPage.bind(b); b.newPage=async()=>{ const p=await b.newPageOrig(); await p.addInitScript(`window.__OUT=${OUTJS};`); return p; };
  E+=await scenario(b,'TARGET<15 exact FINAL',15,()=>({ok:true,evaluated_total:12,valid_total:7,exact_final:true,pool_better_than_exact:false,best:null,job_status:'DONE'}));
  E+=await scenario(b,'TARGET<15 BEST VALID',15,(u)=>/with_output/.test(u)?{ok:true,evaluated_total:9,valid_total:3,best:{cost_production:63.5},output:Object.assign(mkOut(63.5,null),{time_limited_checks:{a:true,b:true}})}:{ok:true,evaluated_total:9,valid_total:3,best:{cost_production:63.5},job_status:'RUNNING'});
  E+=await scenario(b,'TARGET<15 NO VALID',15,()=>({ok:true,evaluated_total:6,valid_total:0,best:null,job_status:'RUNNING'}));
  E+=await scenario(b,'MAXIMUM REVIEW FINAL',null,null,'max');
  E+=await scenario(b,'PROVISIONAL',null,null,'prov');
  E+=await scenario(b,'COUNTER INVARIANTS',null,null,'inv');
  for(const T of [25,35,45,55,60]) E+=await scenario(b,'TARGET<'+T+' exact FINAL',T,()=>({ok:true,evaluated_total:12,valid_total:7,exact_final:true,pool_better_than_exact:false,best:null,job_status:'DONE'}));
  console.log('TOTAL JS ERRORS', E); await b.close();
})();
