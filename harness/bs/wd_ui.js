/* Watchdog: job_exec + job_help dibuang (job tetap QUEUED, meniru request job_exec yang tidak pernah diterima server).
 * node wd_ui.js <base> -> UI wajib berhenti dengan pesan, timer berhenti, Run aktif, tidak polling lagi. */
const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await b.newPage(); const errs = [];
  p.on('pageerror', e => errs.push(String(e.message))); let t0 = 0; const reqs = []; let blocked = 0;
  await p.route(/mode=(job_exec|job_help)/, r => { blocked++; r.abort(); });
  p.on('request', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m) reqs.push({ t: t0 ? (Date.now() - t0) / 1000 : -1, m }); });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  t0 = Date.now(); await p.click('#btn-run'); let tT = null, msg = '';
  while (Date.now() - t0 < 200000) { await sleep(500); const s = await p.evaluate(() => ({ m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled, f: (document.getElementById('fast-msg') || {}).innerText || '' }));
    msg = s.m; if (!s.d && /gagal|tidak pernah mulai/.test(s.m)) { tT = (Date.now() - t0) / 1000; break; } }
  const n0 = reqs.length; await sleep(6000); const after = reqs.slice(n0).filter(x => ['job_poll', 'fast_ready'].includes(x.m)).length;
  const fin = await p.evaluate(() => ({ f: (document.getElementById('fast-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled }));
  console.log(JSON.stringify({ case: 'WATCHDOG_QUEUED', t_terminal_s: tT, msg: msg.slice(0, 300), job_exec_blocked: blocked, refire_seen: reqs.filter(x => x.m === 'job_exec').length,
    cancel_sent: reqs.filter(x => x.m === 'job_cancel').length, polls_after_terminal_6s: after, fast_msg_after: fin.f, run_enabled: !fin.d, js_errors: errs }));
  await b.close(); })();
