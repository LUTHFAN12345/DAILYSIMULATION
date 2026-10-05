/* Fastest minimum test: node fast5.js <base> <label> -> satu baris JSON */
const { chromium } = require('playwright'); const [,, BASE, LABEL, WARM] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  if (WARM) { // pengguna nyata: FINAL Maximum Review pada rencana dulu, lalu ubah PGN Pipe di UI dan Run Fastest
    await p.evaluate(() => { const s = document.getElementById('f-tl-target'); s.value = 'max'; s.dispatchEvent(new Event('change', { bubbles: true })); });
    await p.click('#btn-run'); const tw = Date.now();
    while (Date.now() - tw < 300000) { await sleep(300); const ok = await p.evaluate(() => /FINAL OPTIMAL/.test((document.getElementById('run-msg') || {}).innerText || '') && !document.getElementById('btn-run').disabled); if (ok) break; }
    await p.evaluate(v => { const el = document.getElementById('q-pgn_pipe'); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
      const s = document.getElementById('f-tl-target'); s.value = 'fast'; s.dispatchEvent(new Event('change', { bubbles: true })); }, WARM); await sleep(800); }
  const tgt = await p.evaluate(() => document.getElementById('f-tl-target').value);
  const t0 = Date.now(); if (WARM) await p.evaluate(() => document.getElementById('btn-run').click()); else await p.click('#btn-run'); let modal = false, s = null;
  while (Date.now() - t0 < 400000) { await sleep(100);
    s = await p.evaluate(() => { const m = document.getElementById('ppm-mask'); return { vis: !!m && m.offsetParent !== null && getComputedStyle(m).display !== 'none', txt: (document.getElementById('run-msg') || {}).innerText || '', banner: !!document.getElementById('tl-banner') }; });
    if (s.vis) modal = true; if ((s.banner && /FASTEST VALID PLAN|FINAL OPTIMAL|NO VALID/.test(s.txt)) || /Kebutuhan gas masih melebihi kuota/.test(s.txt)) break; }
  const tShow = (Date.now() - t0) / 1000;
  const r = await p.evaluate(() => { const o = OUTPUT || {}; const i = o.info || {}; const bn = (document.getElementById('tl-banner') || {}).dataset || {}; const f = i['V12 Fastest Check'] || {}; const m = i['V12 Dispatch Merit Audit'] || {};
    return { label: /FASTEST VALID PLAN/.test((document.getElementById('run-msg') || {}).innerText) ? 'FASTEST VALID PLAN' : (/FINAL OPTIMAL/.test((document.getElementById('run-msg') || {}).innerText) ? 'FINAL OPTIMAL' : (/Kebutuhan gas masih melebihi kuota/.test((document.getElementById('run-msg') || {}).innerText) ? 'GAS_SHORTAGE_DECISION (tidak ada kandidat fully valid)' : 'LAIN')),
      rows: (o.data || []).length, checked: bn.checked, valid: bn.valid, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], timing: i['V12 Fastest Timing'] || null,
      hard: (o.simulation_acceptance_review || {}).hard_validation ? o.simulation_acceptance_review.hard_validation.status : null, merit: m.status, c4: m.c4_cross_group_priority ? m.c4_cross_group_priority.fail : null,
      stg: f.stg_proof ? (f.stg_proof.equal_calc + f.stg_proof.startup_hold) + '/' + f.stg_proof.rows_x_stg + ' langgar ' + f.stg_proof.violations : null, llf: f.llf || (i['V12 Low Load Fragmentation Outcome'] || {}).status,
      prov: (i['Fuel Provenance'] || {}).status, fast_ok: f.ok, reasons: f.reasons, trace: (typeof FASTEST_TRACE !== 'undefined' && FASTEST_TRACE) || null,
      simdata_shown: !!(document.getElementById('panel-daily') || {}).classList && document.getElementById('panel-daily').classList.contains('active') && (document.getElementById('cp-simdata') || { classList: { contains: () => false } }).classList.contains('active'),
      simdata_rows: document.querySelectorAll('#result-simdata tr, #cp-simdata tbody tr').length, msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 160) }; });
  for (let q = 0; q < 50; q++) { const hs = await p.evaluate(() => (typeof FASTEST_TRACE !== 'undefined' && FASTEST_TRACE && FASTEST_TRACE.helpers_stopped_ms) || null); if (hs) break; await sleep(200); }
  r.trace = await p.evaluate(() => (typeof FASTEST_TRACE !== 'undefined' && FASTEST_TRACE) || null);
  r.dispatch = await p.evaluate(() => { const D = (OUTPUT || {}).data || []; const C = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','GE1','GE2','GE3','GE4','BB1','BB2','Export_PLN','Spin_Res','BusFlow','Total_Gas'];
    const txt = D.map(x => C.map(c => (+x[c] || 0).toFixed(2)).join(',')).join('|'); let h1 = 0x811c9dc5, h2 = 0; for (let i = 0; i < txt.length; i++) { h1 ^= txt.charCodeAt(i); h1 = Math.imul(h1, 16777619) >>> 0; h2 = (h2 * 31 + txt.charCodeAt(i)) >>> 0; }
    const i = (OUTPUT || {}).info || {}; return { sig: h1.toString(16) + h2.toString(16), rows: D.length, hr: i['JBBK MM Heat Rate (BTU/kWh)'], cost: i['Total Cost (USD)'], gas: i['Total Gas Used (BBTUD)'], hard: ((OUTPUT || {}).simulation_acceptance_review || {}).hard_validation ? OUTPUT.simulation_acceptance_review.hard_validation.status : null,
      merit: (i['V12 Dispatch Merit Audit'] || {}).status, c2: ((i['V12 Dispatch Merit Audit'] || {}).c2_start_with_headroom || {}).fail, c3: ((i['V12 Dispatch Merit Audit'] || {}).c3_first_legal_stop || {}).fail, c4: ((i['V12 Dispatch Merit Audit'] || {}).c4_cross_group_priority || {}).fail,
      prov: (i['Fuel Provenance'] || {}).status, export48: i['PLN Export Compliance'], table_rows: document.querySelectorAll('#cp-simdata tbody tr').length }; });
  r.units = await p.evaluate(() => { const D = (OUTPUT || {}).data || []; const U = {}; for (const u of ['G1', 'G2', 'G5', 'G8', 'G9', 'S2', 'S3']) { const on = []; let mx = 0; D.forEach((x, k) => { const v = +x[u] || 0; if (v > 0.01) on.push(k + 1); mx = Math.max(mx, v); });
    U[u] = on.length ? { rows: on[0] + '-' + on[on.length - 1], n: on.length, max_mw: Math.round(mx * 100) / 100 } : null; } return U; });
  console.log(JSON.stringify(Object.assign({ case: LABEL, target_default: tgt, t_show_s: tShow, modal, js_errors: errs.length }, r))); await b.close(); })();
