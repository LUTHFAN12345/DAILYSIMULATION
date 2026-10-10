// conc.js <base> <out.json> : C0-C6 multi-user / multi-tab isolation (browser nyata, 2 context independen)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const R = { steps: [], errs: [] }; const log = (k, v) => { R.steps.push({ t: +((Date.now() - T0) / 1000).toFixed(1), k, v }); console.log(k, JSON.stringify(v).slice(0, 300)); };
let T0 = Date.now();
async function open(ctx, name) { const p = await ctx.newPage({ viewport: { width: 1400, height: 900 } }); p.on('pageerror', e => R.errs.push(name + ':' + String(e.message).slice(0, 160)));
  await p.goto(BASE + '/index.php'); await sleep(1500); return p; }
const state = p => p.evaluate(() => ({ uid: window.PP_UID, tab: window.PP_TAB, rid: window.PP_CUR_RID || null, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0,
  cp: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.info) ? OUTPUT.info['Cost Production (USD/MWh)'] : null, gate: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.release_gate) ? OUTPUT.release_gate.status : null,
  pop: !!document.getElementById('gsf-box'), msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 160), timer: window.ppRunTimerState ? window.ppRunTimerState() : null,
  remark: (document.getElementById('f-plan_remark') || document.querySelector('[id*=remark]') || {}).value, ie0: (document.querySelector('[data-ie="0"]') || {}).value }));
async function setTarget(p, v) { await p.evaluate(v => { const s = document.getElementById('f-tl-target'); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, v); }
async function setIE0(p, v) { await p.evaluate(v => { const e = document.querySelector('[data-ie="0"]'); if (e) { e.value = String(v); e.dispatchEvent(new Event('input', { bubbles: true })); } }, v); }
async function waitDone(p, name, fuel, maxS) { const t = Date.now(); let clicked = false;
  while (Date.now() - t < maxS * 1000) { await sleep(700); const s = await state(p);
    if (s.pop && !clicked && fuel) { const en = await p.evaluate(id => { const e = document.getElementById(id); return !!e && !e.disabled; }, fuel === 'lng' ? 'gsf-lng' : 'gsf-dist');
      if (en) { clicked = true; await p.click(fuel === 'lng' ? '#gsf-lng' : '#gsf-dist'); } continue; }
    if (s.rows === 48 && s.timer && s.timer.state === 'FINAL') return Object.assign(s, { s: +((Date.now() - t) / 1000).toFixed(1) }); }
  return Object.assign(await state(p), { timeout: true }); }
async function save(p) { await p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); return (await state(p)).msg; }
const api = (p, q) => p.evaluate(q => fetch('run.php?' + q).then(r => r.json()), q);
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const cA = await b.newContext(), cB = await b.newContext(); const A = await open(cA, 'A'), B = await open(cB, 'B');
  const sA0 = await state(A), sB0 = await state(B); log('ids', { A: [sA0.uid, sA0.tab], B: [sB0.uid, sB0.tab], distinct_uid: sA0.uid !== sB0.uid });
  // C1/C2 simultaneous run: A Maximum Review (panjang) + B Fastest dengan input berbeda (IE row1 = 500)
  await setTarget(A, 'max'); await setIE0(B, 500);
  T0 = Date.now(); await Promise.all([A.click('#btn-run'), B.click('#btn-run')]); log('C2_both_run_clicked', {});
  await sleep(6000); const sA1 = await state(A); log('A_running_at_6s', { timer: sA1.timer, rid: sA1.rid, msg: sA1.msg });
  // C3 B Save while A running
  const bRes = await waitDone(B, 'B', 'dist', 120); log('B_done', { rows: bRes.rows, cp: bRes.cp, rid: bRes.rid, s: bRes.s });
  const bSave = await save(B); log('C3_B_save_while_A_running', { msg: bSave });
  const sA2 = await state(A); log('A_after_B_save', { timer: sA2.timer, rid: sA2.rid, msg: sA2.msg, still_running: !!(sA2.timer && sA2.timer.state === 'running') || sA2.pop });
  const aRes = await waitDone(A, 'A', 'lng', 240); log('A_done', { rows: aRes.rows, cp: aRes.cp, gate: aRes.gate, rid: aRes.rid, s: aRes.s, timeout: aRes.timeout || false });
  // C4 simultaneous save
  await setIE0(A, 471); const [sa, sb] = await Promise.all([save(A), save(B)]); log('C4_simultaneous_save', { A: sa, B: sb });
  const lA = await api(A, 'mode=store_list'), lB = await api(B, 'mode=store_list');
  log('records', { A: lA.records.map(r => [r.kind, r.version, r.tab_id, r.project_id]).slice(0, 6), B: lB.records.map(r => [r.kind, r.version, r.tab_id, r.project_id]).slice(0, 6), backend: lA.backend,
    cross_owner: lA.records.some(r => r.user_or_session_id !== sA0.uid) || lB.records.some(r => r.user_or_session_id !== sB0.uid) });
  const xload = lB.records[0] ? await api(A, 'mode=store_load&id=' + lB.records[0].record_id) : null; log('A_cannot_load_B_record', { ok: xload && xload.ok, err: xload && xload.error });
  // C6 reload isolation
  await A.reload(); await B.reload(); await sleep(1500); const rA = await state(A), rB = await state(B); log('C6_reload', { A_ie0: rA.ie0, B_ie0: rB.ie0, expect: { A: '471', B: '500' } });
  // C0 two tabs same browser
  const A2 = await open(cA, 'A2'); const sT = await state(A2); log('C0_tabs', { uid_same: sT.uid === sA0.uid, tab_diff: sT.tab !== sA0.tab });
  await setIE0(A2, 488); await A2.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); const lA2 = await api(A2, 'mode=store_list&limit=3'); log('C0_tab_record', { tab_of_latest: lA2.records[0] && lA2.records[0].tab_id, tabA2: sT.tab });
  // C5 cancel isolation: A dan B (input identik = job bersama) -> B cancel, A tetap selesai
  await setIE0(A, 481); await setIE0(B, 481); await setTarget(A, 'max'); await setTarget(B, 'max');
  await Promise.all([A.evaluate(() => document.getElementById('btn-run').click()), B.evaluate(() => document.getElementById('btn-run').click())]); await sleep(5000);
  const jobs = await api(A, 'mode=job_list'); const run = (jobs.jobs || []).filter(j => /RUNNING|CLAIMED|QUEUED/.test(j.status || ''));
  const ridB = (await state(B)).rid; let cancel = null;
  if (run[0]) cancel = await B.evaluate(([j, r]) => fetch('run.php?mode=job_cancel&abort=1&job=' + encodeURIComponent(j) + '&rid=' + encodeURIComponent(r)).then(x => x.json()), [run[0].job_id, ridB]);
  log('C5_B_cancel', { job: run[0] && run[0].job_id, resp: cancel });
  const a5 = await waitDone(A, 'A', 'lng', 240); log('C5_A_after_B_cancel', { rows: a5.rows, cp: a5.cp, gate: a5.gate, timeout: a5.timeout || false });
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); await b.close(); })();
