// uiwarm.js <base> <out.json> : golden cold lalu rantai IE Adjustment warm lewat UI (waktu klik Run -> FINAL)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const SETS = { IE0: [], IE3: [{ op: '+', value: 15, start: '10:00', stop: '12:00' }], IE2: [{ op: '-', value: 10, start: '14:00', stop: '14:00' }],
  IE4: [{ op: '-', value: 10, start: '12:30', stop: '17:30' }], IE6: [{ op: '+', value: 15, start: '10:00', stop: '12:00' }, { op: '-', value: 10, start: '12:30', stop: '17:30' }, { op: '+', value: 5, start: '18:00', stop: '00:00' }] };
const SEQ = ['IE0', 'IE3', 'IE2', 'IE4', 'IE6', 'IE0'];
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  const R = { errs: [], runs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 160)));
  await p.goto(BASE + '/index.php'); await sleep(1500);
  for (const k of SEQ) { await p.evaluate(r => { IEADJ_RULES = r; renderIeAdj(); }, SETS[k]); await sleep(300);
    await p.evaluate(() => { window.__prevOut = (typeof OUTPUT !== 'undefined') ? OUTPUT : null; document.getElementById('btn-run').click(); }); const t0 = Date.now(); let tPop = null;
    while (Date.now() - t0 < 120000) { await sleep(250);
      const s = await p.evaluate(() => ({ fresh: typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT !== window.__prevOut, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
      if (s.pop) { if (tPop === null) tPop = (Date.now() - t0) / 1000; await p.evaluate(() => { const e = document.getElementById('gsf-dist'); if (e && !e.disabled) e.click(); }); continue; }
      if (s.fresh && s.rows === 48 && s.st === 'FINAL') break; }
    const t = (Date.now() - t0) / 1000;
    const r = await p.evaluate(() => { const i = OUTPUT.info || {}; const w = i['IE Incremental Redispatch'] || {}; return { gate: (OUTPUT.release_gate || {}).status, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'],
      mode: (i['Run Status'] || {}).mode, cache: w.cache, applied: w.applied, ffv: w.first_fully_valid_s, job: w.total_job_s, adj: OUTPUT.data.filter(x => Math.abs(+x.IE_Adj) > 1e-9).length, msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 220) }; });
    R.runs.push(Object.assign({ case: k, t_click_to_final: t, t_popup: tPop }, r)); console.log(JSON.stringify(R.runs[R.runs.length - 1])); }
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); await b.close(); })();
