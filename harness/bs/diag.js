/* diag: node diag.js <base> <secs> -> timeline run-msg + request log */
const { chromium } = require('playwright'); const [,, BASE, SECS] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const t0 = Date.now(); const T = () => ((Date.now() - t0) / 1000).toFixed(1);
  p.on('pageerror', e => console.log(T(), 'PAGEERR', e.message));
  p.on('response', async r => { const u = r.url(); if (!/run\.php/.test(u)) return; let body = ''; try { body = (await r.text()).slice(0, 300).replace(/\s+/g, ' '); } catch (e) {} console.log(T(), 'RESP', r.status(), u.replace(BASE, '').slice(0, 120), body); });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  console.log(T(), 'target', await p.evaluate(() => document.getElementById('f-tl-target').value));
  await p.click('#btn-run'); console.log(T(), 'CLICK'); let last = '';
  while ((Date.now() - t0) / 1000 < +SECS) { await sleep(500); const s = await p.evaluate(() => ((document.getElementById('run-msg') || {}).innerText || '').replace(/\s+/g, ' ').slice(0, 400) + ' | runDisabled=' + document.getElementById('btn-run').disabled + ' rows=' + (((typeof OUTPUT !== 'undefined' && OUTPUT) || {}).data || []).length);
    if (s !== last) { console.log(T(), 'MSG', s); last = s; } }
  await b.close(); })();
