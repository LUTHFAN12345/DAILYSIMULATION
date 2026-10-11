// conc_sup.js <base> <out.json> : regresi supersede lintas user. B Run (Fastest, input beda) lalu 1 s kemudian A Run;
// dahulu job B dibatalkan "DIGANTIKAN_RUN_BARU" oleh Run A. Juga cek supersede milik sendiri tetap bekerja (A Run ulang input beda).
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const R = { steps: [], errs: [] }; let T0 = Date.now(); const log = (k, v) => { R.steps.push({ t: +((Date.now() - T0) / 1000).toFixed(1), k, v }); console.log(k, JSON.stringify(v).slice(0, 400)); };
async function open(ctx, n) { const p = await ctx.newPage({ viewport: { width: 1400, height: 900 } }); p.on('pageerror', e => R.errs.push(n + ':' + String(e.message).slice(0, 160))); await p.goto(BASE + '/index.php'); await sleep(1500); return p; }
const st = p => p.evaluate(() => ({ rid: window.PP_CUR_RID || null, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, cp: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.info) ? OUTPUT.info['Cost Production (USD/MWh)'] : null,
  pop: !!document.getElementById('gsf-box'), timer: window.ppRunTimerState ? window.ppRunTimerState() : null, msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 140) }));
async function setIE0(p, v) { await p.evaluate(v => { const e = document.querySelector('[data-ie="0"]'); e.value = String(v); e.dispatchEvent(new Event('input', { bubbles: true })); }, v); }
async function waitDone(p, fuel, maxS) { const t = Date.now(); let c = false; while (Date.now() - t < maxS * 1000) { await sleep(600); const s = await st(p);
  if (s.pop && !c) { const id = fuel === 'lng' ? '#gsf-lng' : '#gsf-dist'; if (await p.evaluate(i => { const e = document.querySelector(i); return !!e && !e.disabled; }, id)) { c = true; await p.click(id); } continue; }
  if (s.rows === 48 && s.timer && s.timer.state === 'FINAL') return Object.assign(s, { s: +((Date.now() - t) / 1000).toFixed(1) }); } return Object.assign(await st(p), { timeout: true }); }
const api = (p, q) => p.evaluate(q => fetch('run.php?' + q).then(r => r.json()), q);
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const A = await open(await b.newContext(), 'A'), B = await open(await b.newContext(), 'B');
  await setIE0(B, 503); await setIE0(A, 477); T0 = Date.now();
  await B.evaluate(() => document.getElementById('btn-run').click()); await sleep(1000); await A.evaluate(() => document.getElementById('btn-run').click()); log('S1_B_then_A_clicked', {});
  await sleep(2500); const jl = await api(A, 'mode=job_list'); log('S1_jobs', (jl.jobs || []).slice(0, 6).map(j => [j.job_id && j.job_id.slice(0, 26), j.status, j.current_step, j.request_id]));
  const [rb, ra] = await Promise.all([waitDone(B, 'dist', 120), waitDone(A, 'lng', 120)]);
  log('S1_B_done', { rows: rb.rows, cp: rb.cp, s: rb.s, timeout: rb.timeout || false }); log('S1_A_done', { rows: ra.rows, cp: ra.cp, s: ra.s, timeout: ra.timeout || false });
  // S2 supersede milik sendiri: A Run (input X) lalu segera Run lagi (input Y) -> job X milik A dibatalkan, Y selesai
  await setIE0(A, 466); await A.evaluate(() => document.getElementById('btn-run').click()); await sleep(1200); const ridX = (await st(A)).rid;
  await setIE0(A, 467); await A.evaluate(() => document.getElementById('btn-run').click()); await sleep(2000);
  const jl2 = await api(A, 'mode=job_list'); const jx = (jl2.jobs || []).find(j => j.request_id === ridX);
  log('S2_own_old_job', { rid: ridX, status: jx && jx.status, step: jx && jx.current_step });
  const r2 = await waitDone(A, 'lng', 120); log('S2_A_new_done', { rows: r2.rows, cp: r2.cp, s: r2.s, timeout: r2.timeout || false });
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); await b.close(); })();
