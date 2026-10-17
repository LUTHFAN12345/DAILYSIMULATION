// cs_g9run.js <base> : set G9 Simulation+Hold -> Run -> FINAL -> reload -> probe (stale-state/overwrite check)
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const probe = () => { const u = 'g9'; const row = k => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); return el ? el.value : null; };
  const m = INPUT.data3.modeling; return { dom: { uld: (document.getElementById('uld-G9') || {}).value, mode: row('mode'), stopmode: row('stopmode') }, model: { uld: (m.unit_last_data_status || {}).G9, rm: (m.required_mode || {}).g9 || null, sm: (m.stop_mode || {}).g9 || null, cs: (m.unit_cannot_stop || []).includes('g9'), pol: (m.stop_policy || {}).g9 || null } }; };
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext(); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  p.on('dialog', d => d.accept()); const errs = []; p.on('pageerror', e => errs.push(e.message));
  let sent = null; p.on('request', r => { if (/run\.php\?mode=run/.test(r.url())) { try { const m = JSON.parse(r.postData()).data3.modeling; sent = { uld: m.unit_last_data_status.G9, rm: (m.required_mode || {}).g9, sm: (m.stop_mode || {}).g9, cs: (m.unit_cannot_stop || []).includes('g9') }; } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(2500);
  await p.evaluate(() => { const s = document.querySelector('[data-flag="mode"][data-u="g9"]'); s.value = 'simulation'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.evaluate(() => { const s = document.querySelector('[data-flag="stopmode"][data-u="g9"]'); s.value = 'continuous'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  console.log('A EDIT  ', JSON.stringify(await p.evaluate(probe)));
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now(); let st = null, seen = false;
  while (Date.now() - t0 < 240000) { await sleep(300); st = await p.evaluate(() => window.ppRunTimerState ? window.ppRunTimerState().state : null); if (st && st !== 'FINAL') seen = true; if (seen && /FINAL|ERROR|FAIL|INFEAS|TERMINAL|DONE/i.test(st || '')) break; }
  console.log('B RUN   ', st, ((Date.now() - t0) / 1000).toFixed(1) + 's', 'SENT', JSON.stringify(sent), (await p.evaluate(() => (document.getElementById('run-msg') || {}).textContent || '')).slice(0, 160));
  console.log('C AFTER ', JSON.stringify(await p.evaluate(probe)));
  await sleep(1500); await p.reload(); await sleep(2500);
  console.log('D RELOAD', JSON.stringify(await p.evaluate(probe)));
  console.log('errs', JSON.stringify(errs.slice(0, 5))); await b.close(); })();
