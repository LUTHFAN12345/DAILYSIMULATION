// ie17.js <base> <out.json> : IE17 — user A ubah IE Adjustment + Run saat user B sedang Run (2 context browser independen)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const R = { errs: [] };
  const mk = async (name) => { const c = await b.newContext(); const p = await c.newPage({ viewport: { width: 1400, height: 900 } }); const S = { p, payload: null };
    p.on('pageerror', e => R.errs.push(name + ':' + String(e.message).slice(0, 160)));
    p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { try { S.payload = JSON.parse(rq.postData()); } catch (e) {} } });
    await p.goto(BASE + '/index.php'); await sleep(1500); return S; };
  const wait = async (S, maxS) => { const t = Date.now(); while (Date.now() - t < maxS * 1000) { await sleep(600);
      const s = await S.p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
      if (s.pop) await S.p.evaluate(() => { const e = document.getElementById('gsf-dist') || document.getElementById('gsf-lng'); if (e && !e.disabled) e.click(); });
      if (s.rows === 48 && s.st === 'FINAL') return +((Date.now() - t) / 1000).toFixed(1); } return null; };
  const res = S => S.p.evaluate(() => { const D = OUTPUT.data; return { uid: window.PP_UID, gate: (OUTPUT.release_gate || {}).status, cp: OUTPUT.info['Cost Production (USD/MWh)'],
      adj_rows: D.filter(r => Math.abs(+r.IE_Adj) > 1e-9).length, adj_sum: D.reduce((s, r) => s + (+r.IE_Adj || 0), 0), consistent: D.every(r => Math.abs((+r.IE_Pred + +r.IE_Adj) - +r.IE) < 1e-6),
      ui_rules: IEADJ_RULES.length, chart_adj: document.getElementById('ie-chart').dataset.adjRows }; });
  const A = await mk('A'), B = await mk('B');
  await B.p.evaluate(() => { IEADJ_RULES = []; renderIeAdj(); });
  await B.p.evaluate(() => document.getElementById('btn-run').click()); const tB0 = Date.now(); await sleep(1500);
  await A.p.evaluate(() => { IEADJ_RULES = [{ op: '+', value: 15, start: '10:00', stop: '12:00' }, { op: '-', value: 10, start: '12:30', stop: '17:30' }, { op: '+', value: 5, start: '18:00', stop: '00:00' }]; renderIeAdj(); });
  await A.p.evaluate(() => document.getElementById('btn-run').click()); const tA0 = Date.now();
  const [sA, sB] = await Promise.all([wait(A, 150), wait(B, 150)]);
  R.A = Object.assign(await res(A), { s: sA, payload_rules: (A.payload.data3.modeling.ie_adjustments || []).length });
  R.B = Object.assign(await res(B), { s: sB, payload_rules: (B.payload.data3.modeling.ie_adjustments || []).length });
  R.B_chart_after_A = await B.p.evaluate(() => ({ rules: IEADJ_RULES.length, adjRows: document.getElementById('ie-chart').dataset.adjRows }));
  R.pass = R.A.gate === 'PASS' && R.B.gate === 'PASS' && R.A.adj_rows === 29 && R.B.adj_rows === 0 && R.A.consistent && R.B.consistent && R.A.uid !== R.B.uid && R.B.payload_rules === 0 && R.A.payload_rules === 3 && !R.errs.length;
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify(R)); await b.close(); })();
