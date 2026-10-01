/* V11 salinan v6/tests/tl_ui.js — perubahan disengaja: Global optimum proven & status teknis kini di panel Detail audit
 * (#tl-audit), SUMMARY kuning #tl-banner tepat lima field; invarian counter V11 diperiksa terhadap info['V11 Candidate Counters']
 * dan tl_best.counters. T8 memakai band CP 0,2 %.
 * UI Target Selesai + notifikasi. node tl_ui.js <base> <out.md> [targets=15,25,60,max] */
const { chromium } = require('playwright'); const fs=require('fs');
const [,,BASE,OUT,TG]=process.argv; const TARGETS=(TG||'15,25,60,max').split(','); const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
 const p=await b.newPage({viewport:{width:1500,height:950}}); const errs=[]; p.on('pageerror',e=>errs.push(String(e.message)));
 let TLB=null; p.on('response',async r=>{ if(r.url().includes('mode=tl_best')){ try{ const j=await r.json(); if(j&&j.ok&&j.counters) TLB=j.counters; }catch(e){} } });
 await p.goto(BASE+'/index.php',{waitUntil:'domcontentloaded'}); await sleep(1500);
 const R=[];
 // T1 dropdown
 const dd=await p.evaluate(()=>{ const s=document.getElementById('f-tl-target'); if(!s) return null; const bar=s.closest('.runbar'); const save=document.getElementById('btn-save');
   const rs=s.getBoundingClientRect(), rv=save.getBoundingClientRect();
   return {opts:[...s.options].map(o=>o.textContent.trim()), inRunbar:!!bar, right:rs.left>rv.left, sameRow:Math.abs((rs.top+rs.bottom)/2-(rv.top+rv.bottom)/2)<30, label:(s.closest('label')||{}).innerText||''}; });
 R.push(['T1','Dropdown Target Selesai di kanan bawah sejajar SAVE, 8 pilihan (Fastest - Default teratas; perubahan disengaja integrasi)', !!dd&&dd.inRunbar&&dd.right&&dd.sameRow&&dd.opts.join('|')==='Fastest - Default|< 15 detik|< 25 detik|< 35 detik|< 45 detik|< 55 detik|< 60 detik|Maximum Review'&&/Target Selesai/.test(dd.label), JSON.stringify(dd)]);
 // T2 notifikasi
 const vis=()=>p.evaluate(()=>{ const n=document.getElementById('gsd-never-note'); return !!n && n.offsetParent!==null && getComputedStyle(n).display!=='none'; });
 const click=async act=>{ await p.evaluate(a=>{ const bt=document.querySelector('#gsd-seg .gsd-opt[data-act="'+a+'"]'); bt.click(); },act); await sleep(150); };
 const disp=act=>p.evaluate(()=>{ const n=document.getElementById('gsd-never-note'); return n?n.style.display:'?'; });
 await click('recommendation'); const v1=await disp(); await click('add_lng'); const v2=await disp(); await click('use_distillate'); const v3=await disp(); await click('recommendation'); const v4=await disp();
 R.push(['T2','Notifikasi "never auto-switches to distillate" hanya pada Gas Shortage Recommendation (tanpa reload)', v1===''&&v2==='none'&&v3==='none'&&v4==='', JSON.stringify({rec:v1,lng:v2,dist:v3,rec2:v4})]);
 // persist
 await p.evaluate(()=>{ const s=document.getElementById('f-tl-target'); s.value='25'; s.dispatchEvent(new Event('change',{bubbles:true})); });
 await p.reload({waitUntil:'domcontentloaded'}); await sleep(1500);
 const kept=await p.evaluate(()=>document.getElementById('f-tl-target').value);
 R.push(['T3','Pilihan tersimpan dan dipakai lagi setelah reload', kept==='25', 'value='+kept]);
 const setQ=async()=>p.evaluate(()=>{ for(const [id,v] of [['q-pgn_pipe','29'],['q-pep','32']]){ const el=document.getElementById(id); el.value=v; el.dispatchEvent(new Event('input',{bubbles:true})); el.dispatchEvent(new Event('change',{bubbles:true})); } });
 await setQ();
 const st=()=>p.evaluate(()=>({msg:(document.getElementById('run-msg')||{}).innerText||'', banner:(document.getElementById('tl-banner')||{}).innerText||'',
   rows:(typeof OUTPUT!=='undefined'&&OUTPUT)?(OUTPUT.data||[]).length:0, tl:!!(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.time_limited),
   cp:(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info['Cost Production (USD/MWh)']:null, ck:(typeof OUTPUT!=='undefined'&&OUTPUT)?OUTPUT.time_limited_checks||null:null,
   xls:(document.getElementById('btn-xls')||{}).disabled, pub:(document.getElementById('btn-save-actual')||{disabled:'na'}).disabled, running:(document.getElementById('btn-run')||{}).disabled,
   pop:!!document.getElementById('gsf-mask')&&getComputedStyle(document.getElementById('gsf-mask')).display!=='none',
   aud:(document.getElementById('tl-audit')||{}).textContent||'', cells:document.querySelectorAll('#tl-banner .v11-sum-c').length,
   labels:[...document.querySelectorAll('#tl-banner .v11-sum-l')].map(x=>x.textContent).join('|'), ds:Object.assign({},(document.getElementById('tl-banner')||{dataset:{}}).dataset),
   cnt:(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info['V11 Candidate Counters']||null:null}));
 const res={};
 for(const T of TARGETS){
   await p.evaluate(v=>{ const s=document.getElementById('f-tl-target'); s.value=v; s.dispatchEvent(new Event('change',{bubbles:true})); },T);
   await p.evaluate(()=>{ if(typeof OUTPUT!=='undefined') OUTPUT=null; ['tl-banner','tl-audit'].forEach(i=>{ const b=document.getElementById(i); if(b) b.remove(); }); }); TLB=null;
   const t0=Date.now(); await p.evaluate(()=>document.getElementById('btn-run').click()); await sleep(800);
   let s, tShow=null; const lim=T==='max'?1800000:(+T*1000+20000);
   while(Date.now()-t0<lim){ s=await st(); if(!s.running && (/BEST VALID|FINAL OPTIMAL|NO VALID RESULT|Global optimum proven/.test(s.msg))){ tShow=Date.now()-t0; break; } await sleep(200); }
   res[T]={t:tShow,s};
   const lbl=s? (s.msg.match(/BEST VALID — MAXIMUM REVIEW|BEST VALID WITHIN TIME LIMIT|FINAL OPTIMAL|NO VALID RESULT WITHIN TIME LIMIT/)||['?'])[0]:'?';
   const ckOk=s&&(!s.ck||Object.values(s.ck).every(v=>v===true));
   if(T!=='max') R.push(['T4-'+T,'Target < '+T+' detik: hasil tampil sebelum batas; valid 48 baris; label & Global optimum proven dinyatakan', tShow!==null&&tShow<=(+T*1000)&&s.rows===48&&ckOk&&/Kandidat diperiksa/.test(s.msg)&&/Global optimum proven: (YES|NO)/.test(s.aud)&&(lbl!=='BEST VALID WITHIN TIME LIMIT'||(/Global optimum proven: NO/.test(s.aud)&&s.xls===false)),
     `t=${tShow} ms label=${lbl} cp=${s&&s.cp} rows=${s&&s.rows} excel_terbuka=${s&&s.xls===false} msg=${s?s.msg.slice(0,230):''}`]);
   else R.push(['T5-max','Maximum Review: exact selesai, FINAL OPTIMAL publishable, Global optimum proven: YES (ruang kandidat exact); kandidat Target Selesai yang lebih murah (bila ada) dinyatakan sebagai catatan', tShow!==null&&s.rows===48&&/FINAL OPTIMAL/.test(s.msg)&&/Global optimum proven: YES/.test(s.aud)&&s.xls===false, `t=${tShow} ms label=${lbl} cp=${s&&s.cp} catatan=${/PERHATIAN/.test(s.msg)} msg=${s?s.msg.slice(0,300):''}`]);
   { const d=(s&&s.ds)||{}; const ch=+d.checked, va=+d.valid, hasCp=d.cp!==''&&d.cp!=null; const c=s&&s.cnt;
     const five=s&&s.cells===5&&s.labels==='Target waktu|Waktu aktual|Kandidat diperiksa|Kandidat valid|Cost Production';
     const inv=(!hasCp||va>=1)&&(va>0||!hasCp)&&va<=ch;
     const src=/FINAL OPTIMAL/.test(lbl)&&c?(ch===+c.candidates_checked&&va===+c.candidates_valid&&c.best_candidate_in_valid===true):(TLB?(ch===+TLB.candidates_checked&&va===+TLB.candidates_valid):true);
     R.push(['V11-'+T,'SUMMARY kuning tepat lima field; CP tersedia -> Valid >= 1; Valid <= Diperiksa; counter = satu sumber (info V11 Candidate Counters / tl_best.counters); best termasuk valid; teknis hanya di Detail audit',
       !!(five&&inv&&src&&s.aud.length>0&&!/Global optimum|Total Cost|Status constraints/.test((s.banner||''))), `labels=${s&&s.labels} ds=${JSON.stringify(d)} cnt=${c?JSON.stringify({ch:c.candidates_checked,va:c.candidates_valid,best:c.best_candidate_in_valid}):'-'} tl_best=${TLB?JSON.stringify(TLB):'-'}`]); }
   console.log('RUN',T,JSON.stringify({t:tShow,label:lbl,cp:s&&s.cp}));
 }
 const TLT=TARGETS.filter(T=>T!=='max'); const cps=TLT.map(T=>res[T]&&res[T].s?+res[T].s.cp:null);
 let mono=true; for(let i=1;i<cps.length;i++) if(cps[i]!=null&&cps[i-1]!=null&&cps[i]>cps[i-1]+1e-9) mono=false;
 if(TLT.length) R.push(['T6','Cost Production tidak memburuk ketika target waktu diperpanjang (15 -> 25 -> 60)',mono&&cps.every(x=>x!=null),JSON.stringify(TLT.map((T,i)=>T+':'+cps[i]))+(res.max&&res.max.s?' | Maximum Review (exact): '+res.max.s.cp:'')]);
 if(TLT.length && res.max && res.max.s){ const mx=+res.max.s.cp; const mn=Math.min(...cps.filter(x=>x!=null));
   R.push(['T8','Maximum Review (FINAL OPTIMAL) tidak lebih mahal daripada kandidat valid Target Selesai mana pun di luar band CP 0,2 % (V11)', mx<=mn*1.002+1e-9, 'Maximum Review '+mx+' vs Target Selesai terbaik '+mn]); }
 R.push(['T7','tidak ada JavaScript error',errs.length===0,errs.slice(0,2).join(';')]);
 let md='# UI Target Selesai & notifikasi (PGN 29 + PEP 32)\n\n| id | pemeriksaan | hasil | rincian |\n|---|---|---|---|\n'; let np=0;
 for(const r of R){ console.log((r[2]?'PASS':'FAIL')+'  '+r[0]+'  '+r[1]+'  -- '+r[3]); md+=`| ${r[0]} | ${r[1]} | ${r[2]?'LULUS':'GAGAL'} | ${String(r[3]).replace(/\|/g,'/')} |\n`; if(r[2])np++; }
 fs.writeFileSync(OUT,md+`\nHasil: **${np}/${R.length}**\n`); console.log(np+'/'+R.length+' PASS'); await b.close();
})().catch(e=>{console.error('ERR',e);process.exit(2)});
