// uirender.js <base> : ukur durasi fungsi render di finalizeSimulationUI (bungkus fungsi global), satu Run
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => { window.__RT = []; ['finalizeSimulationUI','dpSnapshotCurrent','renderResult','refreshOverview','refreshPills','refreshGasDecision','showSimulationDataResult','drawIEChart','renderSimData','renderUnitFlags'].forEach(n => {
    const f = window[n]; if (typeof f !== 'function') return; window[n] = function () { const t = performance.now(); try { return f.apply(this, arguments); } finally { window.__RT.push([n, +(performance.now() - t).toFixed(1)]); } }; }); });
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now();
  while (Date.now() - t0 < 200000) { await sleep(100); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null })); if (s.rows === 48 && s.st === 'FINAL') break; }
  console.log(JSON.stringify(await p.evaluate(() => window.__RT))); await b.close(); })();
