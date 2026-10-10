// uisave.js <base> <outdir> : U0-U3/U6/R7 UI->payload->worker->result->save->reload (konteks browser baru = user baru)
const { chromium } = require('playwright'); const fs = require('fs'); const crypto = require('crypto'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext(); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  const R = { errs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 200)));
  let lastPayload = null; p.on('request', rq => { if (/mode=run/.test(rq.url()) && rq.method() === 'POST') { try { lastPayload = JSON.parse(rq.postData()); fs.writeFileSync(OUT + '/payload_' + (R._n = (R._n || 0) + 1) + '.json', rq.postData()); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.getByText('SR & Bus Flow', { exact: true }).first().click(); await sleep(400);
  // U0 -> U1: Follow PV ON, Fix SR = 15, PV row 25-30 (12:30-15:00) = 40 MW
  await p.evaluate(() => { const r = document.getElementById('sr-radio-pv'); r.click(); r.dispatchEvent(new Event('change', { bubbles: true })); });
  await p.fill('#f-spinning_reserve_min', '15'); await p.dispatchEvent('#f-spinning_reserve_min', 'input');
  for (const i of [24, 25, 26, 27, 28, 29]) { await p.fill(`[data-pv-row="${i}"]`, '40'); await p.dispatchEvent(`[data-pv-row="${i}"]`, 'input'); }
  await sleep(300); R.ui_eff = await p.evaluate(() => Array.from(document.querySelectorAll('#tbl-pv tbody tr')).slice(22, 31).map(tr => tr.innerText.replace(/\s+/g, ' ').trim()));
  await p.locator('#pv-card').scrollIntoViewIfNeeded(); await p.screenshot({ path: OUT + '/U1_follow_pv_on.png', clip: await p.locator('#pv-card').boundingBox() });
  // IE edit (U4) lalu Run Fastest -> payload & hasil worker
  await p.getByText('Name Plan & IE/Dispatch', { exact: true }).first().click(); await sleep(300);
  await p.fill('[data-ie="5"]', '490'); await p.dispatchEvent('[data-ie="5"]', 'input');
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now(); let ck = false;
  while (Date.now() - t0 < 120000) { await sleep(800); const s = await p.evaluate(() => ({ pop: !!document.getElementById('gsf-box'), en: !!(document.getElementById('gsf-dist') && !document.getElementById('gsf-dist').disabled), rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState && window.ppRunTimerState().state }));
    const ls = JSON.stringify(s) + ' ' + await p.evaluate(() => ((document.getElementById('run-msg') || {}).innerText || '').replace(/\n/g, ' ').slice(0, 160)); if (ls !== R._l) { console.log(((Date.now() - t0) / 1000).toFixed(0), ls); R._l = ls; }
    if (s.pop && s.en && !ck) { ck = true; await p.click('#gsf-dist'); } if (ck && s.rows === 48 && s.st === 'FINAL') break; }
  const m = lastPayload && lastPayload.data3 && lastPayload.data3.modeling; R.payload = m ? { sr_mode: m.sr_mode, sr_fixed_mw: m.sr_fixed_mw, pv_25_30: (m.pv_rows || []).slice(24, 30), eff_23_31: (m.sr_effective_rows || []).slice(22, 31), ie6: lastPayload.data1[5].value, hash: crypto.createHash('sha256').update(JSON.stringify(lastPayload)).digest('hex').slice(0, 16) } : null;
  R.result = await p.evaluate(() => { const D = OUTPUT.data || []; const i = OUTPUT.info || {}; return { rows: D.length, gate: (OUTPUT.release_gate || {}).status, cp: i['Cost Production (USD/MWh)'], sr_mode: (i['Spinning Reserve Requirement'] || {}).sr_mode,
    srmin_23_31: D.slice(22, 31).map(r => r.SR_Min), pv_23_31: D.slice(22, 31).map(r => r.PV), sr_under: D.filter(r => r.SR_Min != null && +r.Spin_Res < +r.SR_Min - 0.01).length, pv_col: Array.from(document.querySelectorAll('th')).some(th => /^PV/.test((th.innerText || '').trim())) }; });
  await p.screenshot({ path: OUT + '/U1_result_pv_column.png' });
  // Save (R7) lalu reload (U6)
  await p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); R.save_msg = await p.evaluate(() => (document.getElementById('run-msg') || {}).innerText.slice(0, 120));
  await p.reload(); await sleep(2000);
  R.reload = await p.evaluate(() => { const m = INPUT.data3.modeling; return { sr_mode: m.sr_mode, sr_fixed_mw: m.sr_fixed_mw, pv_25_30: (m.pv_rows || []).slice(24, 30), ie6: (document.querySelector('[data-ie="5"]') || {}).value, radio_pv: !!(document.getElementById('sr-radio-pv') || {}).checked, chart_n: (document.getElementById('ie-chart') || { dataset: {} }).dataset.n }; });
  await p.getByText('Name Plan & IE/Dispatch', { exact: true }).first().click(); await sleep(500);
  R.ie_chart_after_reload = await p.evaluate(() => { const s = document.getElementById('ie-chart'); const ps = s.querySelectorAll('path'); return { n: s.dataset.n, ie_path_has_points: ps[1] ? ps[1].getAttribute('d').split('L').length : 0, stats: (document.getElementById('ie-chart-stats') || {}).innerText }; });
  await p.locator('#ie-chart-card').scrollIntoViewIfNeeded(); await p.screenshot({ path: OUT + '/U6_ie_chart_after_reload.png', clip: await p.locator('#ie-chart-card').boundingBox() });
  // U0: Follow PV OFF -> payload sr_mode fixed, effective = fix
  await p.getByText('SR & Bus Flow', { exact: true }).first().click(); await sleep(300);
  await p.evaluate(() => { const r = document.getElementById('sr-radio-fixed'); r.click(); r.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(300);
  R.U0_off = await p.evaluate(() => { const m = INPUT.data3.modeling; return { sr_mode: m.sr_mode, eff_25_30: (m.sr_effective_rows || []).slice(24, 30) }; });
  fs.writeFileSync(OUT + '/uisave.json', JSON.stringify(R, null, 1)); await b.close(); })();
