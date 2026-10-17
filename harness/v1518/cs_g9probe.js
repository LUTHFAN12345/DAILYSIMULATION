// cs_g9probe.js <base> : baca state G9 di UI setelah load, lalu payload yang akan dikirim (collect)
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/index.php'); await sleep(2500);
  const st = await p.evaluate(() => { const u = 'g9'; const row = k => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); return el ? (el.type === 'checkbox' ? el.checked : el.value) : null; };
    const m = INPUT.data3.modeling;
    return { dom: { uld: (document.getElementById('uld-G9') || {}).value, req: row('req'), mode: row('mode'), startat: row('startat'), stopmode: row('stopmode') },
      state: { COMMIT: COMMIT_MODE.g9, STOP: STOP_MODE.g9, CANNOT: CANNOT_STOP.includes('g9'), dpMon: (typeof dpMon === 'function') ? dpMon() : null },
      model: { uld: (m.unit_last_data_status || {}).G9, rm: (m.required_mode || {}).g9, sm: (m.stop_mode || {}).g9, cs: (m.unit_cannot_stop || []).includes('g9') } }; });
  console.log('AFTER LOAD', JSON.stringify(st));
  console.log('errs', JSON.stringify(errs.slice(0, 5))); await b.close(); })();
