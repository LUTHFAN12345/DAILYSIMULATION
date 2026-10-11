// uitrace.js <base> <out.json> : satu cold Run; catat setiap request/response (ms sejak POST mode=run)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  const L = []; let t0 = null; const now = () => t0 === null ? -1 : Date.now() - t0;
  p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST' && t0 === null) t0 = Date.now(); if (t0 !== null) L.push([now(), 'REQ', rq.method(), rq.url().replace(BASE, '').slice(0, 110)]); });
  p.on('requestfinished', async rq => { if (t0 === null) return; let sz = 0; try { const r = await rq.response(); sz = (await r.body()).length; } catch (e) {} L.push([now(), 'END', rq.url().replace(BASE, '').slice(0, 110), sz]); });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => document.getElementById('btn-run').click()); const tc = Date.now();
  while (Date.now() - tc < 200000) { await sleep(100); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null }));
    if (s.rows === 48 && s.st === 'FINAL') { L.push([now(), 'FINAL']); break; } }
  fs.writeFileSync(OUT, JSON.stringify(L)); await b.close(); })();
