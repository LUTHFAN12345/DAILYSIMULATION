/* HOTFIX PGN30/PEP34 reproducer (UI asli). node hx_ui.js <base> <label>
 * env: CSV=<path> (opsional)  SR_MODE=fixed|follow_pv  SR_FIX=<MW>  MAXT=<s, default 300>
 * Gas diisi lewat field form: PGN 30, LNG 0, PEP 34, Akasia/Baskara/BBG 0, GHV PGN 1040, GHV JBBK 1080, Min PGN 3,
 * PEP KP72 4.6 Cummulative, Pertagas/Akasia/Baskara KP72 0, Max Flow MM2100 0, target Fastest - Default.
 * Mencatat: payload sebelum/sesudah upload (assembleInput), hash body POST run, timeline progres, hasil. */
const { chromium } = require('playwright'); const fs = require('fs'); const crypto = require('crypto');
const [,, BASE, LABEL] = process.argv; const E = process.env; const sleep = ms => new Promise(r => setTimeout(r, ms));
const sha = s => crypto.createHash('sha256').update(s).digest('hex');
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const R = { case: LABEL, timeline: [], posts: [] }; let t0 = 0; const T = (ev, x) => R.timeline.push(Object.assign({ t: t0 ? +((Date.now() - t0) / 1000).toFixed(3) : 0, ev }, x || {}));
  p.on('request', r => { const u = r.url(); const m = (u.match(/mode=([a-z_]+)/) || [])[1];
    if (m === 'run' && r.method() === 'POST') { const body = r.postData() || ''; let j = null; try { j = JSON.parse(body); } catch (e) {}
      R.posts.push({ t: t0 ? (Date.now() - t0) / 1000 : -1, url: u.replace(BASE, ''), bytes: body.length, sha256: sha(body), run_source: j && j._run_source, rows1: j && j.data1 && j.data1.length, rows2: j && j.data2 && j.data2.length,
        mff: j && j.data3 && (j.data3.modeling.manual_fixed_flows || []).length, gas_quota: j && j.data3 && j.data3.modeling.gas_quota, body: j }); } });
  p.on('response', async r => { const u = r.url(); if (/mode=job_poll|mode=fast_ready/.test(u)) { try { const j = await r.json(); const job = j.job || {}; if (job.current_step || j.ready) T('poll', { step: job.current_step || null, pct: job.percent || null, status: job.status || j.job_status || null, ready: !!j.ready }); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  const snap = () => p.evaluate(() => JSON.parse(JSON.stringify(assembleInput())));
  await p.evaluate(() => { const s = document.getElementById('f-tl-target'); if (s) { s.value = 'fast'; s.dispatchEvent(new Event('change', { bubbles: true })); } });
  /* gas lewat field form */
  await p.evaluate(() => { const set = (id, v) => { const el = document.getElementById(id); if (!el) throw new Error('field ' + id); el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); };
    set('q-pgn_pipe', 30); set('q-lng', 0); set('q-pep', 34); set('q-akasia', 0); set('q-baskara', 0); set('q-bbg', 0);
    set('f-ghv_pgn', 1040); set('f-ghv_jababeka', 1080); set('f-min_pgn_flow', 3);
    set('mode-pep_kp72', 'cummulative'); set('q-pep_kp72', 4.6); set('q-pertagas_kp72', 0); set('q-akasia_kp72', 0); set('q-baskara_kp72', 0); set('f-max_flow_mm2100', 0); });
  const P1 = await snap();
  if (E.CSV) { await p.setInputFiles('#csv-file', E.CSV); await sleep(1200); R.import_preview = await p.evaluate(() => (document.getElementById('csv-preview') || {}).innerText || '');
    await p.click('#csv-apply'); await sleep(600); }
  const P2 = await snap();
  if (E.SR_MODE) { await p.evaluate(() => { const t = [...document.querySelectorAll('button,a')].find(x => /Frequently Input/i.test(x.textContent)); if (t) t.click(); }); await sleep(200);
    await p.evaluate(() => document.querySelector('[data-cp="cp-sr"]').click()); await sleep(300);
    await p.evaluate(([md, fx]) => { const r = document.getElementById(md === 'follow_pv' ? 'sr-mode-pv' : 'sr-mode-fixed'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
      const f = document.getElementById('f-spinning_reserve_min'); f.value = fx; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); }, [E.SR_MODE, E.SR_FIX || '0']); await sleep(300); }
  const P3 = await snap(); R.sr_after = { sr_mode: P3.data3.modeling.sr_mode, sr_fixed_mw: P3.data3.modeling.sr_fixed_mw, sr_eff_max: Math.max(...(P3.data3.modeling.sr_effective_rows || [0])) };
  /* diff payload sebelum/sesudah upload */
  const diff = []; const walk = (a, b, path) => { if (JSON.stringify(a) === JSON.stringify(b)) return;
    if (a && b && typeof a === 'object' && typeof b === 'object' && !Array.isArray(a)) { for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) walk(a[k], b[k], path + '.' + k); return; }
    if (Array.isArray(a) && Array.isArray(b) && a.length === b.length && a.length && typeof a[0] === 'object') { for (let i = 0; i < a.length; i++) walk(a[i], b[i], path + '[*]'); return; }
    diff.push(path + (Array.isArray(a) || Array.isArray(b) ? ` [len ${a && a.length}->${b && b.length}]` : '')); };
  walk(P1, P2, ''); R.upload_diff_paths = [...new Set(diff)]; R.payload_before_sha = sha(JSON.stringify(P1)); R.payload_after_sha = sha(JSON.stringify(P2));
  R.payload_after_summary = { rows1: P2.data1.length, rows2: P2.data2.length, first: [P2.data1[0].time, P2.data1[0].value, P2.data2[0].dispatch], last: [P2.data1[47].time, P2.data1[47].value, P2.data2[47].dispatch],
    gas_quota: P2.data3.modeling.gas_quota, mm2100_gas_mode: P2.data3.modeling.mm2100_gas_mode, min_pgn_flow: P2.data3.modeling.min_pgn_flow, max_flow_mm2100: P2.data3.modeling.max_flow_mm2100,
    ghv: [P2.data3.modeling.ghv_pgn, P2.data3.modeling.ghv_jababeka], mff: (P2.data3.modeling.manual_fixed_flows || []).length, sr_mode: P2.data3.modeling.sr_mode, sr_fixed_mw: P2.data3.modeling.sr_fixed_mw,
    stop_mode: P2.data3.modeling.stop_mode, pv_rows: (P2.data3.modeling.pv_rows || []).length };
  /* Run */
  t0 = Date.now(); T('run_click'); await p.click('#btn-run'); let done = null, msg = '', lastMsg = '';
  const MAXT = +(E.MAXT || 300) * 1000;
  while (Date.now() - t0 < MAXT) { await sleep(200); const s = await p.evaluate(() => ({ m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled, rows: document.querySelectorAll('#tbl-result tbody tr').length }));
    const ms = s.m.replace(/\d+[.,]\d+ detik|\d+ detik|elapsed[^·]*/g, '#').slice(0, 160); if (ms !== lastMsg) { T('ui_msg', { msg: s.m.slice(0, 240) }); lastMsg = ms; }
    msg = s.m; if (!s.d && (s.rows === 48 || /FEASIBLE|gagal|Error|NO VALID|terminal/i.test(s.m)) && !/sedang|menunggu/i.test(s.m)) { done = (Date.now() - t0) / 1000; T('done', { rows: s.rows }); break; } }
  const o = await p.evaluate(() => { const O = (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data && OUTPUT.data.length) ? OUTPUT : {}; const D = O.data || []; const i = O.info || {};
    return { rows: D.length, shown_rows: document.querySelectorAll('#tbl-result tbody tr').length, label: O.result_label || null, gate: (O.release_gate || {}).status || null, hard: ((O.simulation_acceptance_review || {}).hard_validation || {}).status || null,
      cp: i['Cost Production (USD/MWh)'] || null, hr: i['JBBK MM Heat Rate (BTU/kWh)'] || null, fuel: i['Fuel Action Reconciliation'] || null, pgn_min: D.length ? Math.min(...D.map(r => +r.Flow_PGN_RT)) : null,
      gas_used: i['Total Gas Used (BBTUD)'] || null, gas_quota: i['Total Gas Quota (BBTUD)'] || null, sig: D.map(r => ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','GE1','GE2','GE3','GE4','BB1','BB2'].map(c => (+r[c] || 0).toFixed(2)).join(',')).join('|') }; });
  o.sig = sha(o.sig).slice(0, 12); R.result = o; R.run = { t_done_s: done, msg: msg.slice(0, 400) }; R.js_errors = errs.slice(0, 5);
  R.posts = R.posts.map(x => Object.assign({}, x, { body: undefined }));
  if (E.SAVE_PAYLOADS) { fs.writeFileSync(E.SAVE_PAYLOADS + '_before_upload.json', JSON.stringify(P1)); fs.writeFileSync(E.SAVE_PAYLOADS + '_after_upload.json', JSON.stringify(P2)); }
  console.log(JSON.stringify(R)); await b.close(); })().catch(e => { console.log(JSON.stringify({ error: String(e && e.stack || e) })); process.exit(1); });
