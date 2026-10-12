const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext({ acceptDownloads: true }); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') errs.push(m.text()); });
  await p.goto(BASE + '/index.php'); await sleep(2000);
  await p.evaluate(() => document.getElementById('btn-run').click()); const tc = Date.now(); let st = null, seenRun = false;
  while (Date.now() - tc < 240000) { await sleep(100); st = await p.evaluate(() => ({ rows: document.querySelectorAll('#result-simdata tbody tr').length, act: !!(window.PP_RTP && window.PP_RTP.active), t: window.ppRunTimerState ? window.ppRunTimerState().state : null }));
    if (st.t && st.t !== 'FINAL') seenRun = true;
    if (seenRun && st.t === 'FINAL' && st.rows >= 48 && !st.act) break; }
  console.log('ui_wall_s', (Date.now() - tc) / 1000, JSON.stringify(st));
  await sleep(1500);
  const r = await ctx.request.get(BASE + '/run.php?mode=runtime_profile'); fs.writeFileSync(OUT, await r.body()); console.log('download', r.status(), r.headers()['content-disposition']);
  console.log('errs', JSON.stringify(errs.slice(0, 8))); await b.close(); })();
