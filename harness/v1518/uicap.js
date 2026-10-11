// uicap.js <base> <out.json> : muat UI (input_data.json server), klik Run, simpan body POST mode=run, lalu keluar
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage();
  let body = null; p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST' && body === null) body = rq.postData(); });
  await p.goto(BASE + '/index.php'); await sleep(1500); await p.evaluate(() => document.getElementById('btn-run').click());
  for (let i = 0; i < 50 && body === null; i++) await sleep(100); fs.writeFileSync(OUT, body || ''); await b.close(); })();
