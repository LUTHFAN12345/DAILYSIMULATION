const { chromium } = require('playwright'); const [,, BASE, IE0, TARGET] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(String(e.message).slice(0, 200))); p.on('console', m => { if (m.type() === 'error') errs.push('console:' + m.text().slice(0, 200)); });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  if (TARGET) await p.evaluate(v => { const s = document.getElementById('f-tl-target'); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, TARGET);
  if (IE0) await p.evaluate(v => { const e = document.querySelector('[data-ie="0"]'); e.value = String(v); e.dispatchEvent(new Event('input', { bubbles: true })); }, IE0);
  const t0 = Date.now(); await p.click('#btn-run'); let last = '';
  while (Date.now() - t0 < 90000) { await sleep(1000); const s = await p.evaluate(() => ({ m: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 200), pop: !!document.getElementById('gsf-box'), popTxt: ((document.getElementById('gsf-box') || {}).innerText || '').slice(0, 300), d: document.getElementById('btn-run').disabled, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, distEn: !!(document.getElementById('gsf-dist') && !document.getElementById('gsf-dist').disabled) }));
    const line = JSON.stringify(s); if (line !== last) { console.log(((Date.now() - t0) / 1000).toFixed(1), line); last = line; } if (s.pop && s.distEn && !globalThis.CL) { globalThis.CL = 1; await p.click('#gsf-dist'); } if (s.rows === 48 && !s.d) break; }
  console.log('errs', errs); await b.close(); })();
