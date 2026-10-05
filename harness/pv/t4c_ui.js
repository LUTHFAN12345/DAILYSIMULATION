/* T4C UI: keputusan terminal PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE (hasil backend t4c.php) dirender tanpa popup Apply,
 * input Fixed Flow tidak berubah, tidak ada polling. node t4c_ui.js <base> <T4C_backend.json> */
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, F] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const red = JSON.parse(fs.readFileSync(F, 'utf8')).result;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const reqs = []; p.on('request', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m) reqs.push(m); });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  const r = await p.evaluate(red => { const ff0 = (FF_ROWS || []).length;
    const rec = { available: false, code: 'PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE', redistribution: red, recommended_max_fixed_flow_mmscfd: 34.82, min_pgn_flow_mmscfd: 3 };
    const data = { status: 'PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE', preliminary: true, pgn_recommendation: rec, data: [], info: {},
      terminal_decision: { terminal: true, code: 'PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE', pgn_recommendation: rec, gas: {}, distillate: {}, startup: {},
        certificate: { row: 1, time: '00:30', max_achievable_flow_mmscfd: 1.782, min_pgn_flow_mmscfd: 3, deficit_mmscfd: 1.218, units_online_row1: [{ unit: 'G1', max_mw: 31 }, { unit: 'G9', max_mw: 108 }], feasible_if: [] } } };
    gsdHandleNonFinalResult(JSON.parse(JSON.stringify(INPUT)), data);
    return { msg: (document.getElementById('run-msg') || {}).innerText || '', popup: !!document.getElementById('pgnrec-mask'), ff_before: ff0, ff_after: (FF_ROWS || []).length,
      audit: !!(INPUT.data3.modeling.pgn_fixed_flow_recommendation_applied) }; }, red);
  const n0 = reqs.length; await sleep(5000); const after = reqs.slice(n0).length;
  const ok = /PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE/.test(r.msg) && /defisit 0,2748/.test(r.msg.replace(/\./g, ',')) && !r.popup && r.ff_after === r.ff_before && !r.audit && after === 0 && !errs.length;
  console.log(JSON.stringify({ case: 'T4C_UI', pass: ok, popup_shown: r.popup, fixed_flow_rows_before_after: [r.ff_before, r.ff_after], audit_written: r.audit, requests_after_5s: after, msg: r.msg.slice(0, 700), js_errors: errs }));
  await b.close(); })();
