// uiie.js <base> <out.json> <ie-spec-json> : satu tab: Run basis (input_data.json server) sampai FINAL, lalu untuk setiap
// spesifikasi IE Adjustment (array of rule-list) ubah IEADJ_RULES di halaman yang sama, Run, ukur klik->FINAL 48 row (poll 100 ms).
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT, SPEC, PRE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  const R = { errs: [], runs: [] }; let posts = []; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 160)));
  p.on('request', rq => { if (/mode=run\b/.test(rq.url()) && rq.method() === 'POST') { try { const j = JSON.parse(rq.postData()); posts.push({ t: Date.now(), act: j.data3.modeling.gas_shortage_action, skip: !!j._prelim_skipped, lit: j.data3.modeling.distillate_user_limit_litres || null }); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  if (PRE) { await p.evaluate(PRE); await sleep(300); }
  const runOnce = async (label) => { posts = []; const prev = await p.evaluate(() => (typeof OUTPUT !== 'undefined' && OUTPUT) ? 1 : 0);
    await p.evaluate(() => { window.__ppOutBefore = (typeof OUTPUT !== 'undefined') ? OUTPUT : null; document.getElementById('btn-run').click(); }); const t0 = Date.now(); let ok = false;
    while (Date.now() - t0 < 200000) { await sleep(100);
      const s = await p.evaluate(() => ({ fresh: (typeof OUTPUT !== 'undefined') && OUTPUT && OUTPUT !== window.__ppOutBefore, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
      if (s.pop) { await p.evaluate(() => { const e = document.getElementById('gsf-lng'); if (e && !e.disabled) e.click(); }); }
      if (s.fresh && s.rows === 48 && s.st === 'FINAL') { ok = true; break; } }
    const t = (Date.now() - t0) / 1000;
    const info = await p.evaluate(() => { const i = (OUTPUT && OUTPUT.info) || {}; const c = i['IE Rerun Classification'] || null; const ie = i['IE Incremental Redispatch'] || {};
      const U = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'];
      return { cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], gate: (OUTPUT.release_gate || {}).status, merit: (i['Merit Proof C1-C4 STG'] || {}).status,
        cls: c ? c.class : null, reason: c ? c.reason : null, engine_s: c ? c.engine_s : (ie.total_job_s || null), dist_l: i['Distillate Used (l)'], rows: OUTPUT.data.map(r => U.map(u => r[u])),
        mix: OUTPUT.data.map(r => ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'].map(u => r['DistMix_' + u] || 0)), msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 160) }; });
    R.runs.push(Object.assign({ label, t_ui: t, ok, posts: posts.map(x => ({ dt: x.t - posts[0].t, act: x.act, skip: x.skip, lit: x.lit })) }, info)); };
  await runOnce('base');
  for (const [k, rules] of JSON.parse(SPEC).entries()) {
    await p.evaluate((rl) => { IEADJ_RULES = rl.map(x => ({ op: x[0], value: x[1], start: x[2], stop: x[3] })); try { renderIeAdj(); } catch (e) {} }, rules);
    await runOnce('ie' + (k + 1)); }
  fs.writeFileSync(OUT, JSON.stringify(R)); await b.close(); })();
