// uirun.js <base> <out.json> <fuel:lng|dist> [target:fast|max] : satu Run lewat UI nyata (input_data.json server), klik keputusan bahan bakar bila popup muncul
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT, FUEL, TGT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const c = await b.newContext(); const p = await c.newPage({ viewport: { width: 1400, height: 900 } });
  const R = { errs: [], payloads: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 160)));
  p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { try { const j = JSON.parse(rq.postData()); R.payloads.push({ t: Date.now(), co: (j.data3.modeling.change_over || {}).enabled || false, act: j.data3.modeling.gas_shortage_action, lng: j.data3.modeling.additional_lng }); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  if (TGT === 'max') await p.evaluate(() => { const s = document.getElementById('f-tl-target'); s.value = 'max'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now(); let tPop = null, clicked = false;
  while (Date.now() - t0 < 200000) { await sleep(500);
    const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop && !clicked) { const id = FUEL === 'dist' ? 'gsf-dist' : 'gsf-lng'; const en = await p.evaluate(id => { const e = document.getElementById(id); return !!e && !e.disabled; }, id);
      if (en) { tPop = (Date.now() - t0) / 1000; clicked = true; await p.click('#' + id); } continue; }
    if (s.rows === 48 && s.st === 'FINAL') break; }
  R.t_total = (Date.now() - t0) / 1000; R.t_popup = tPop;
  R.msg_raw = await p.evaluate(() => ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 400)); if (!(await p.evaluate(() => typeof OUTPUT !== 'undefined' && !!OUTPUT))) { fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify(R)); await b.close(); return; }
  Object.assign(R, await p.evaluate(() => { const i = OUTPUT.info || {}; const mp = i['Merit Proof C1-C4 STG'] || {}; return { gate: (OUTPUT.release_gate || {}).status, blk: (OUTPUT.release_gate || {}).blocking_reasons,
    cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], merit: mp.status, co: (i['Change Over Timeline'] || {}).mode, co_exec: (i['Change Over Timeline'] || {}).executed,
    msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 300), mode: (i['Run Status'] || {}).mode }; }));
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify(R)); await b.close(); })();
