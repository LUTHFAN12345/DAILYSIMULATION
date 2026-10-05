/* T1 UI: node t1_ui.js <base> <label> -> JSON: klik Run sampai status terminal, lalu 6 s pengamatan pasca-terminal */
const { chromium } = require('playwright'); const [,, BASE, LABEL] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(String(e.message))); const reqs = []; let t0 = 0;
  p.on('request', r => { const u = r.url(); if (/run\.php\?mode=/.test(u)) reqs.push({ t: t0 ? (Date.now() - t0) / 1000 : -1, m: (u.match(/mode=([a-z_]+)/) || [])[1] }); });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  const form = await p.evaluate(() => ({ target: document.getElementById('f-tl-target').value, gas_action: (document.getElementById('f-gas_action') || {}).value, dist_limit: (document.getElementById('f-distillate_litres') || {}).value }));
  t0 = Date.now(); await p.click('#btn-run'); let tTerm = null, msg = '', fast = '';
  while (Date.now() - t0 < 300000) { await sleep(200);
    const s = await p.evaluate(() => ({ m: ((document.getElementById('run-msg') || {}).innerText || ''), f: ((document.getElementById('fast-msg') || {}).innerText || ''), dis: document.getElementById('btn-run').disabled }));
    msg = s.m; fast = s.f;
    if (!s.dis && /FEASIBLE|FASTEST VALID PLAN|FINAL OPTIMAL|gagal|Error|BELUM final/.test(s.m)) { tTerm = (Date.now() - t0) / 1000; break; } }
  const nAt = reqs.length; await sleep(6000);
  const after = reqs.slice(nAt).filter(x => ['job_poll', 'fast_ready', 'prelim_progress'].includes(x.m)).length;
  const fin = await p.evaluate(() => ({ f: ((document.getElementById('fast-msg') || {}).innerText || ''), dis: document.getElementById('btn-run').disabled, gsf: !!document.getElementById('gsf-mask') }));
  const modes = {}; reqs.forEach(x => { modes[x.m] = (modes[x.m] || 0) + 1; });
  console.log(JSON.stringify({ case: LABEL, form, t_terminal_s: tTerm, msg: msg.slice(0, 900), fast_msg_at_terminal: fast, fast_msg_after_6s: fin.f, run_enabled: !fin.dis, fuel_popup_open: fin.gsf,
    polls_after_terminal_6s: after, requests: modes, job_start_runs: reqs.filter(x => x.m === 'run').length, js_errors: errs }));
  await b.close(); })();
