/* Fastest minimum test: node fast5.js <base> <label> -> satu baris JSON */
const { chromium } = require('playwright'); const [,, BASE, LABEL] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  const tgt = await p.evaluate(() => document.getElementById('f-tl-target').value);
  const t0 = Date.now(); await p.click('#btn-run'); let modal = false, s = null;
  while (Date.now() - t0 < 400000) { await sleep(100);
    s = await p.evaluate(() => { const m = document.getElementById('ppm-mask'); return { vis: !!m && m.offsetParent !== null && getComputedStyle(m).display !== 'none', txt: (document.getElementById('run-msg') || {}).innerText || '', banner: !!document.getElementById('tl-banner') }; });
    if (s.vis) modal = true; if ((s.banner && /FASTEST VALID PLAN|FINAL OPTIMAL|NO VALID/.test(s.txt)) || /Kebutuhan gas masih melebihi kuota/.test(s.txt)) break; }
  const tShow = (Date.now() - t0) / 1000;
  const r = await p.evaluate(() => { const o = OUTPUT || {}; const i = o.info || {}; const bn = (document.getElementById('tl-banner') || {}).dataset || {}; const f = i['V12 Fastest Check'] || {}; const m = i['V12 Dispatch Merit Audit'] || {};
    return { label: /FASTEST VALID PLAN/.test((document.getElementById('run-msg') || {}).innerText) ? 'FASTEST VALID PLAN' : (/FINAL OPTIMAL/.test((document.getElementById('run-msg') || {}).innerText) ? 'FINAL OPTIMAL' : (/Kebutuhan gas masih melebihi kuota/.test((document.getElementById('run-msg') || {}).innerText) ? 'GAS_SHORTAGE_DECISION (tidak ada kandidat fully valid)' : 'LAIN')),
      rows: (o.data || []).length, checked: bn.checked, valid: bn.valid, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], timing: i['V12 Fastest Timing'] || null,
      hard: (o.simulation_acceptance_review || {}).hard_validation ? o.simulation_acceptance_review.hard_validation.status : null, merit: m.status, c4: m.c4_cross_group_priority ? m.c4_cross_group_priority.fail : null,
      stg: f.stg_proof ? (f.stg_proof.equal_calc + f.stg_proof.startup_hold) + '/' + f.stg_proof.rows_x_stg + ' langgar ' + f.stg_proof.violations : null, llf: f.llf || (i['V12 Low Load Fragmentation Outcome'] || {}).status,
      prov: (i['Fuel Provenance'] || {}).status, fast_ok: f.ok, reasons: f.reasons }; });
  console.log(JSON.stringify(Object.assign({ case: LABEL, target_default: tgt, t_show_s: tShow, modal, js_errors: errs.length }, r))); await b.close(); })();
