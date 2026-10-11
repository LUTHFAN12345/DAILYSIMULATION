// copayload.js <base> : tangkap payload Run untuk input P14 (Change Over) — apakah change_over.enabled terkirim
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage();
  let pl = null; p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { try { pl = JSON.parse(rq.postData()); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  const ui = await p.evaluate(() => ({ co: JSON.stringify(CHANGE_OVER), bp: JSON.stringify((BP_STATE || []).map(r => [r.block, r.required])) }));
  await p.evaluate(() => document.getElementById('btn-run').click()); await sleep(3000);
  console.log(JSON.stringify({ ui, sent: pl && pl.data3.modeling.change_over, fast: pl && pl._fast_default }));
  await b.close(); })();
