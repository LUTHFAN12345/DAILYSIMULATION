// uiie.js <base> <outdir> : IE16 — IE Adjustment UI -> chart efektif -> payload -> worker -> Simulation Data -> Save -> Reload -> Excel
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext();
  const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } }); const R = { errs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 160)));
  let lastPayload = null; p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { try { lastPayload = JSON.parse(rq.postData()); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  // 3 aturan: 10:00-12:00 +15, 12:30-17:30 -10, 18:00-00:00 +5 (lewat state UI yang sama dengan klik)
  await p.evaluate(() => { IEADJ_RULES = [{ op: '+', value: 15, start: '10:00', stop: '12:00' }, { op: '-', value: 10, start: '12:30', stop: '17:30' }, { op: '+', value: 5, start: '18:00', stop: '00:00' }]; renderIeAdj(); });
  R.rows_label = await p.evaluate(() => Array.from(document.querySelectorAll('#tbl-ie-adj .ieadj-rows')).map(e => e.innerText));
  R.chart = await p.evaluate(() => { const s = document.getElementById('ie-chart'); return { n: s.dataset.n, adjRows: s.dataset.adjRows, eff: !!document.getElementById('ie-chart-eff'), stats: (document.getElementById('ie-chart-stats') || {}).innerText }; });
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now();
  while (Date.now() - t0 < 120000) { await sleep(700); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop) await p.evaluate(() => { const e = document.getElementById('gsf-dist') || document.getElementById('gsf-lng'); if (e && !e.disabled) e.click(); });
    if (s.rows === 48 && s.st === 'FINAL') break; }
  R.t_end = (Date.now() - t0) / 1000;
  R.payload_rules = lastPayload && lastPayload.data3.modeling.ie_adjustments;
  R.result = await p.evaluate(() => { const D = OUTPUT.data || []; const pick = k => [20, 24, 25, 35, 36, 48].map(r => [r, D[r - 1][k]]);
    return { gate: (OUTPUT.release_gate || {}).status, cp: OUTPUT.info['Cost Production (USD/MWh)'], adj: pick('IE_Adj'), pred: pick('IE_Pred'), eff: pick('IE'), row19adj: D[18].IE_Adj, row1adj: D[0].IE_Adj,
      consistent: D.every(r => Math.abs((+r.IE_Pred + +r.IE_Adj) - +r.IE) < 1e-6), info: OUTPUT.info['IE Adjustment'], mode: (OUTPUT.info['Run Status'] || {}).mode }; });
  const xls = await p.evaluate(() => resultToHTMLTable()); R.excel = { has_cols: /IE Prediction \(MW\)/.test(xls) && /IE Adjustment \(MW\)/.test(xls) && /IE Effective \(MW\)/.test(xls), dist_cols: (xls.match(/Distillate G\d+ \(%\)/g) || []) };
  await p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); R.save_msg = await p.evaluate(() => (document.getElementById('run-msg') || {}).innerText);
  await p.reload(); await sleep(2000);
  R.reload = await p.evaluate(() => ({ rules: IEADJ_RULES, rows_label: Array.from(document.querySelectorAll('#tbl-ie-adj .ieadj-rows')).map(e => e.innerText), adjRows: document.getElementById('ie-chart').dataset.adjRows }));
  await p.screenshot({ path: OUT + '/IE16_after_reload.png', fullPage: false }); fs.writeFileSync(OUT + '/uiie.json', JSON.stringify(R, null, 1)); await b.close(); })();
