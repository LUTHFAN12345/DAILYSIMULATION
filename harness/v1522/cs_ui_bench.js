// cs_ui_bench.js <base> <label> <runs> <out.json> : browser Run (Fastest default, job async + helpers started by the page) -> FINAL
//   measures wall (click -> FINAL), CP/HR, G9 interval, hard status, phases, core runs, helper/task utilization; run 1 = cold, runs 2.. = warm
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, LABEL, RUNS, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const res = [];
  for (let i = 0; i < +(RUNS || 3); i++) {
    const p = await (await b.newContext()).newPage({ viewport: { width: 1500, height: 1000 } }); p.on('dialog', d => d.accept());
    await p.goto(BASE + '/index.php'); await sleep(2500);
    await p.evaluate(() => { window.__ppOut0 = OUTPUT; document.getElementById('btn-run').click(); }); const t0 = Date.now(); let st = null, seen = false;
    while (Date.now() - t0 < 600000) { await sleep(250); st = await p.evaluate(() => window.ppRunTimerState ? window.ppRunTimerState().state : null); if (st && st !== 'FINAL') seen = true; if (seen && /FINAL|ERROR|FAIL|INFEAS|TERMINAL|DONE/i.test(st || '')) break; }
    const wall = (Date.now() - t0) / 1000;
    const r = await p.evaluate(() => { const o = OUTPUT || {}; const i = o.info || {}; const rows = o.data || [];
      const on = c => rows.map((x, k) => (+x[c] || 0) > 0.01 ? k : -1).filter(k => k >= 0); const T = r => { const m = (r * 30) % 1440; return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); };
      const g9 = on('G9'); const d = i['Runtime Platform Diagnostics'] || {}; const rs = i['Run Status'] || {}; const tr = i['Commitment Semantics Trace'] || {};
      return { cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], g9: g9.length ? T(g9[0] + 1) + '-' + T(g9[g9.length - 1] + 1) : 'OFF', g9_rows: g9.length,
        status: rs.status, core: rs.core_simulations, hard: (i['Hard Constraint Validation'] || {}).status || i['Validator Status'] || null, trace: tr.status,
        engine_s: rs.elapsed_seconds || rs.wall_seconds || null, helpers: d.helper_slots, tasks: d.tasks_total, workers: d.workers, max_conc: d.max_concurrency, task_busy_s: d.task_busy_s, task_span_s: d.task_span_s,
        phases: (d.phase_timeline || []).map(x => [x.phase, x.delta_s, x.core_delta]), sig: JSON.stringify(rows.map(x => ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'].map(c => Math.round((+x[c] || 0) * 100) / 100))) }; });
    r.wall = wall; r.state = st; r.run = i + 1; r.kind = i === 0 ? 'cold' : 'warm'; res.push(r);
    console.log(LABEL, r.kind, 'run', i + 1, 'wall', wall.toFixed(1), 'cp', r.cp, 'hr', r.hr, 'G9', r.g9, 'state', st, 'core', r.core, 'tasks', r.tasks, 'workers', r.workers, 'maxc', r.max_conc, 'busy', r.task_busy_s, 'span', r.task_span_s, 'trace', r.trace);
    await p.close();
  }
  fs.writeFileSync(OUT, JSON.stringify(res, null, 1)); await b.close();
})();
