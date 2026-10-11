// uipay.js <base> <out_prefix> : satu cold Run lewat UI; simpan payload POST mode=run (<prefix>.payload.json) dan
// ringkasan hasil + baris dispatch (<prefix>.json) untuk paritas UI vs CLI (fingerprint dihitung oleh fp_ui.py)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, PFX] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  let payload = null; p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST' && payload === null) payload = rq.postData(); });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now(); let clicked = false;
  while (Date.now() - t0 < 200000) { await sleep(300);
    const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop && !clicked) { const en = await p.evaluate(() => { const e = document.getElementById('gsf-lng'); return !!e && !e.disabled; }); if (en) { clicked = true; await p.click('#gsf-lng'); } continue; }
    if (s.rows === 48 && s.st === 'FINAL') break; }
  const R = await p.evaluate(() => { const i = OUTPUT.info || {}; const U = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'];
    return { cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], gate: (OUTPUT.release_gate || {}).status, rows: OUTPUT.data.map(r => U.map(u => r[u])) }; });
  R.t_total = (Date.now() - t0) / 1000; fs.writeFileSync(PFX + '.payload.json', payload || ''); fs.writeFileSync(PFX + '.json', JSON.stringify(R)); await b.close(); })();
