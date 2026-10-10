/* ab_ui.js <base> <label> <out.json> : UI asli. env TARGET=f-tl-target value (fast|max|...), FUEL=lng|dist (klik tombol popup),
 * MAXT detik. Mencatat timeline pesan, request, popup bahan bakar, gate, OUTPUT ringkas. */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, LABEL, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const MAXT = +(process.env.MAXT || 240) * 1000, TARGET = process.env.TARGET || '', FUEL = process.env.FUEL || '';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const ctx = await b.newContext({ viewport: { width: 1500, height: 950 } }); const p = await ctx.newPage();
  const R = { case: LABEL, target: TARGET, fuel: FUEL, timeline: [], reqs: [], errors: [], dialogs: [] }; const t0 = { v: 0 }; const T = () => +((Date.now() - t0.v) / 1000).toFixed(2);
  p.on('pageerror', e => R.errors.push(String(e.message).slice(0, 200)));
  p.on('dialog', async d => { R.dialogs.push(d.message().slice(0, 200)); try { await d.accept(); } catch (e) {} });
  p.on('request', r => { const u = r.url(); const m = (u.match(/mode=([a-z_]+)/) || [])[1]; if (m && t0.v && !/job_poll|tl_best|fast_ready/.test(m)) R.reqs.push({ t: T(), m, u: u.replace(BASE, '').slice(0, 120) }); });
  R.run_responses = [];
  p.on('response', async r => { const u = r.url(); if (!/mode=(run|job_poll)/.test(u)) return; try { const j = await r.json(); if (/mode=run/.test(u)) { try { fs.writeFileSync(OUT.replace(/\.json$/, '') + '_run' + R.run_responses.length + '.json', JSON.stringify(j)); } catch (e) {} } const o = j.result ? (j.result.output || j.result) : (j.output && j.output.info ? j.output : j); if (/job_poll/.test(u) && !(j.job && j.job.status === 'DONE')) return;
    const i = (o && o.info) || {}; R.run_responses.push({ t: t0.v ? T() : 0, url: u.replace(BASE, '').slice(0, 60), status: o.status, ok: o.ok, preliminary: o.preliminary, preliminary_reason: o.preliminary_reason, action_required: o.action_required,
      rows: (o.data || []).length, has_shortage_decision: !!o.shortage_decision, vo_job: !!o.validated_options_job, async_job: !!o.async_job, fuel_decision_required: o.fuel_decision_required, gate: (o.release_gate || {}).status, blocking: (o.release_gate || {}).blocking_reasons,
      shortage: i['Gas Shortage (BBTUD)'], rec_lng: i['Recommended LNG (BBTUD)'], rec_dist: i['Recommended Distillate (l/day)'], req_dist: i['Required Distillate (l/day)'], action: i['Gas Shortage Action'], cp: i['Cost Production (USD/MWh)'],
      run_status: i['Run Status'] || null, fastest_exec: i['Fastest Execution'] || null, msg: String(o.message || o.preliminary_note || '').slice(0, 300) }); } catch (e) {} });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(2000);
  if (TARGET) await p.evaluate(v => { const s = document.getElementById('f-tl-target'); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, TARGET);
  R.target_label = await p.evaluate(() => { const s = document.getElementById('f-tl-target'); return s ? s.options[s.selectedIndex].text + ' [' + s.value + ']' : null; });
  t0.v = Date.now(); await p.click('#btn-run'); let last = ''; let fuelDone = false; let popupAt = null;
  while (Date.now() - t0.v < MAXT) { await sleep(250);
    const s = await p.evaluate(() => ({ m: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 400), d: document.getElementById('btn-run').disabled,
      rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, gate: typeof GSD_GATE !== 'undefined' ? GSD_GATE : null,
      pop: !!document.getElementById('gsf-box'), popTxt: ((document.getElementById('gsf-box') || {}).innerText || '').slice(0, 600),
      lngEn: !!(document.getElementById('gsf-lng') && !document.getElementById('gsf-lng').disabled), distEn: !!(document.getElementById('gsf-dist') && !document.getElementById('gsf-dist').disabled) }));
    if (s.m !== last) { R.timeline.push({ t: T(), msg: s.m.slice(0, 300) }); last = s.m; }
    if (s.pop && popupAt === null) { popupAt = T(); R.popup_at = popupAt; }
    if (s.pop && (s.lngEn || s.distEn) && R.popup_ready_at == null) { R.popup_ready_at = T(); R.popup_text = s.popTxt; }
    if (s.pop && FUEL && !fuelDone && ((FUEL === 'lng' && s.lngEn) || (FUEL === 'dist' && s.distEn))) { fuelDone = true; R.fuel_click_at = T(); await p.click(FUEL === 'lng' ? '#gsf-lng' : '#gsf-dist'); continue; }
    if (process.env.FUELCALL && !fuelDone && !s.d && !s.pop && T() > 1 && /keputusan bahan bakar|BELUM final/i.test(s.m)) { fuelDone = true; R.fuel_click_at = T(); const [a, v, u] = process.env.FUELCALL.split(':');
      R.fuelcall = process.env.FUELCALL; await p.evaluate(([a, v, u]) => { gsfRerun(a, +v, u); }, [a, v, u]); await sleep(800); continue; }
    if (s.pop && !FUEL && !s.d && (s.lngEn || s.distEn || T() > 60)) break;
    if (!s.d && !s.pop && s.rows === 48 && !s.gate && T() > 1 && !/BELUM final|menunggu|sedang|Rerun ronde/i.test(s.m)) break;
    if (!s.d && !s.pop && T() > 3 && /gagal|error|TIDAK FEASIBLE|terminal|BELUM final|keputusan bahan bakar/i.test(s.m) && !/Perhitungan eksak|sedang|Rerun/i.test(s.m) && !((FUEL || process.env.FUELCALL) && !fuelDone)) { await sleep(1500); const s2 = await p.evaluate(() => !!document.getElementById('gsf-box')); if (!s2) break; } }
  R.t_end = T();
  R.result = await p.evaluate(() => { const o = (typeof OUTPUT !== 'undefined' && OUTPUT) || {}; const i = o.info || {}; const rg = o.release_gate || {}; const D = o.data || [];
    const units = ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','BB1','BB2']; const st = {}; units.forEach(u => { const on = D.map((r, k) => (+r[u] || 0) > 0.01 ? k + 1 : 0).filter(Boolean); if (on.length) st[u] = on[0] + '-' + on[on.length - 1] + '(' + on.length + ')'; });
    return { rows: D.length, status: o.status, ok: o.ok, result_label: o.result_label, gate: rg.status, release_allowed: rg.release_allowed, blocking: rg.blocking_reasons, publish_allowed: o.publish_allowed, save_allowed: o.save_allowed,
      cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'] || i['Heat Rate (BTU/kWh)'], gas_used: i['Total Gas Used (BBTUD)'], gas_quota: i['Total Gas Quota (BBTUD)'], shortage: i['Gas Shortage (BBTUD)'], residual: i['Residual Gas Shortage (BBTUD)'],
      action: i['Gas Shortage Action'], lng_added: i['Added LNG (BBTUD)'], dist_l: i['Distillate Used (l)'], dist_units: i['Distillate per unit (l)'], rec_lng: i['Recommended LNG (BBTUD)'], rec_dist: i['Recommended Distillate (l/day)'],
      run_status: i['Run Status'] ? { status: i['Run Status'].status, converged: i['Run Status'].converged, mode: i['Run Status'].mode, core_runs: i['Run Status'].core_runs } : null, starts: st,
      proof: (function () { const p = i['Merit Proof C1-C4 STG'] || null; if (!p) return null; const f = k => p[k] ? (p[k].status + ':' + p[k].findings + '/' + p[k].fail) : null; return { status: p.status, C1: f('C1'), C2: f('C2'), C3: f('C3'), C4: f('C4'), STG: p.stg ? (p.stg.status + ':' + p.stg.rows_x_stg + '/' + p.stg.mismatch) : null }; })(),
      fastest_mode: (i['Run Status'] || {}).mode || null, sr_under: D.filter(r => r.SR_Min != null && +r.Spin_Res < +r.SR_Min - 0.01).length, pv_col: D.length ? ('PV' in D[0]) : null,
      gateLock: typeof GSD_GATE !== 'undefined' ? GSD_GATE : null, btns: ['btn-xls','btn-img-full','btn-publish','btn-save-actual'].map(id => { const e = document.getElementById(id); return id + ':' + (e ? (e.disabled ? 'locked' : 'on') : 'na'); }) }; });
  R.msg_final = last;
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify({ case: LABEL, t_end: R.t_end, popup_at: R.popup_at, popup_ready_at: R.popup_ready_at, result: R.result, msg: last.slice(0, 250), errors: R.errors.slice(0, 3) }));
  await b.close();
})().catch(e => { console.error('ERR', e); process.exit(1); });
