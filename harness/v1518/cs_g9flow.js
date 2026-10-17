// cs_g9flow.js <base> : operator set G9 = Start Based On Simulation + Stop Status Continuous -> collect -> snapshot -> save -> reload
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const probe = () => { const u = 'g9'; const row = k => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); return el ? (el.type === 'checkbox' ? el.checked : el.value) : null; };
  const m = INPUT.data3.modeling;
  return { dom: { uld: (document.getElementById('uld-G9') || {}).value, mode: row('mode'), stopmode: row('stopmode') }, state: { COMMIT: COMMIT_MODE.g9, STOP: STOP_MODE.g9, CANNOT: CANNOT_STOP.includes('g9') },
    model: { uld: (m.unit_last_data_status || {}).G9, rm: (m.required_mode || {}).g9 || null, sm: (m.stop_mode || {}).g9 || null, cs: (m.unit_cannot_stop || []).includes('g9') } }; };
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext(); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  p.on('dialog', d => d.accept()); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/index.php'); await sleep(2500);
  console.log('1 LOAD      ', JSON.stringify(await p.evaluate(probe)));
  await p.evaluate(() => { const s = document.querySelector('[data-flag="mode"][data-u="g9"]'); s.value = 'simulation'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.evaluate(() => { const s = document.querySelector('[data-flag="stopmode"][data-u="g9"]'); s.value = 'continuous'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  console.log('2 EDIT      ', JSON.stringify(await p.evaluate(probe)));
  const pay = await p.evaluate(() => { const x = assembleInput(); const m = x.data3.modeling; return { uld: m.unit_last_data_status.G9, rm: (m.required_mode || {}).g9 || null, sm: (m.stop_mode || {}).g9 || null, cs: (m.unit_cannot_stop || []).includes('g9'), ru: (m.required_units || []).includes('g9') }; });
  console.log('3 PAYLOAD   ', JSON.stringify(pay));
  const snap = await p.evaluate(async () => { const r = await fetch('run.php?mode=input_snapshot', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(assembleInput()) }); const j = await r.json(); const s = j.snapshot || j; const m = s.payload.data3.modeling;
    return { uld: m.unit_last_data_status.G9, rm: (m.required_mode || {}).g9 || null, sm: (m.stop_mode || {}).g9 || null, cs: (m.unit_cannot_stop || []).includes('g9'), sem: (s.semantics && s.semantics.units && s.semantics.units.G9) || null }; });
  console.log('4 SNAPSHOT  ', JSON.stringify(snap));
  await p.evaluate(() => saveInput()); await sleep(2500);
  console.log('5 AFTER SAVE', JSON.stringify(await p.evaluate(probe)), await p.evaluate(() => (document.getElementById('run-msg') || {}).textContent.slice(0, 120)));
  await p.reload(); await sleep(2500);
  console.log('6 RELOAD    ', JSON.stringify(await p.evaluate(probe)));
  console.log('errs', JSON.stringify(errs.slice(0, 5))); await b.close(); })();
