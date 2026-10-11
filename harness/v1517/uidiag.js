const { chromium } = require('playwright'); const [,, BASE, FUEL, MAXS] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  p.on('pageerror', e => console.log('ERR', String(e.message).slice(0, 200)));
  p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { const j = JSON.parse(rq.postData()); console.log('POST run', JSON.stringify({ co: (j.data3.modeling.change_over || {}).enabled, act: j.data3.modeling.gas_shortage_action, lng: j.data3.modeling.additional_lng })); } });
  p.on('response', async r => { if (/mode=(job_status|job_result|run)\b/.test(r.url())) { try { const t = await r.text(); if (/"status":"(DONE|FAILED|ERROR)/.test(t) || /mode=run\b/.test(r.url())) console.log('RESP', r.url().replace(/.*mode=/, '').slice(0, 40), t.slice(0, 200)); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500); await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now(); let last = '';
  while (Date.now() - t0 < (+MAXS || 90) * 1000) { await sleep(1000);
    const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? JSON.stringify(window.ppRunTimerState()).slice(0, 120) : null, pop: !!document.getElementById('gsf-box'),
      lngDis: (document.getElementById('gsf-lng') || {}).disabled, popTxt: ((document.getElementById('gsf-box') || {}).innerText || '').slice(0, 300), msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 200) }));
    const k = JSON.stringify(s); if (k !== last) { console.log(((Date.now() - t0) / 1000).toFixed(1), k); last = k; }
    if (s.pop && !s.lngDis && FUEL) { await p.click(FUEL === 'dist' ? '#gsf-dist' : '#gsf-lng'); console.log('clicked', FUEL); }
    if (s.rows === 48 && /FINAL/.test(s.st || '')) break; }
  await b.close(); })();
