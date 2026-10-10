// uitest.js <base> <outdir> : U4 IE chart edit, U7 timer lifecycle, PV column
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 } }); const R = { errs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 200)));
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.getByText('Name Plan & IE/Dispatch', { exact: true }).first().click(); await sleep(600);
  const pathOf = () => p.evaluate(() => { const s = document.getElementById('ie-chart'); const ps = s ? s.querySelectorAll('path') : []; return { n: s ? s.dataset.n : null, ie: ps[1] ? ps[1].getAttribute('d').slice(0, 80) : null, len: ps[1] ? ps[1].getAttribute('d').length : 0, stats: (document.getElementById('ie-chart-stats') || {}).innerText }; });
  R.ie_before = await pathOf();
  await p.locator('#ie-chart-card').scrollIntoViewIfNeeded(); await p.screenshot({ path: OUT + '/U4_ie_chart.png', clip: await p.locator('#ie-chart-card').boundingBox() });
  await p.fill('[data-ie="0"]', '600'); await p.dispatchEvent('[data-ie="0"]', 'input'); await sleep(300);
  R.ie_after_edit = await pathOf(); R.U4_changed = R.ie_before.ie !== R.ie_after_edit.ie;
  await p.hover('#ie-chart-hit', { position: { x: 30, y: 60 } }); await sleep(200); R.tooltip = await p.evaluate(() => (document.getElementById('ie-chart-tip') || {}).innerText);
  await p.screenshot({ path: OUT + '/U4_ie_chart_edit_tooltip.png', clip: await p.locator('#ie-chart-card').boundingBox() });
  await p.fill('[data-ie="0"]', '481'); await p.dispatchEvent('[data-ie="0"]', 'input'); await sleep(200);
  // U7 timer
  const t0 = Date.now(); await p.click('#btn-run'); const ticks = [];
  while (Date.now() - t0 < 60000) { await sleep(1000); const st = await p.evaluate(() => ({ s: window.ppRunTimerState && window.ppRunTimerState(), txt: (document.getElementById('run-timer') || {}).innerText, vis: (document.getElementById('run-timer') || {}).style && document.getElementById('run-timer').style.display }));
    ticks.push({ t: ((Date.now() - t0) / 1000).toFixed(1), el: st.txt ? (st.txt.match(/Elapsed: (\S+)/) || [])[1] : null, stage: st.txt ? (st.txt.match(/Stage: ([^\n]+)/) || [])[1] : null, state: st.s && st.s.state, rid: st.s && st.s.rid });
    if (ticks.length === 3) await p.screenshot({ path: OUT + '/U7_timer_running.png' });
    if (st.s && st.s.state !== 'running') break; }
  await sleep(500); await p.screenshot({ path: OUT + '/U7_timer_end.png' }); R.U7_ticks = ticks;
  R.pv_col = await p.evaluate(() => Array.from(document.querySelectorAll('th')).some(th => /^PV/.test((th.innerText || '').trim())));
  fs.writeFileSync(OUT + '/uitest.json', JSON.stringify(R, null, 1)); await b.close(); })();
