/* node failfast_ui.js <base_url> <out.json> [force]: buka UI, catat banner instalasi; bila force=1 tombol Run diaktifkan paksa lalu diklik
 * (meniru UI lama yang terbuka) -> ukur waktu sampai progres berhenti + tombol aktif + pesan error; hitung request ke run.php 5 s sesudahnya. */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, OUT, FORCE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const reqs = []; p.on('request', r => { if (/run\.php/.test(r.url())) reqs.push({ t: Date.now(), url: r.url().replace(/^.*run\.php/, 'run.php') }); });
  const resp = await p.goto(BASE + '/index.php'); const html = await p.content();
  await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(2500);
  const R = { index_http: resp.status(), php_warning_in_html: /<b>(Warning|Fatal error|Notice|Parse error)<\/b>:/i.test(html) || /^\s*(PHP )?(Warning|Fatal error)/m.test(html.replace(/<script[\s\S]*?<\/script>/g, "")) };
  Object.assign(R, await p.evaluate(() => ({ banner: (document.getElementById('install-err') || {}).innerText || null, run_disabled: document.getElementById('btn-run').disabled, preflight: window.PP_PREFLIGHT || null })));
  if (FORCE === '1') {
    await p.evaluate(() => { document.getElementById('btn-run').disabled = false; const e = document.getElementById('install-err'); if (e) e.remove(); });
    const n0 = reqs.length; const t0 = Date.now(); await p.click('#btn-run'); let stop = null;
    while (Date.now() - t0 < 30000) { await sleep(50);
      const s = await p.evaluate(() => ({ fm: (document.getElementById('fast-msg') || {}).textContent || '', msg: (document.getElementById('run-msg') || {}).innerText || '', dis: document.getElementById('btn-run').disabled }));
      if (!s.fm && !s.dis && /Instalasi|Error|tidak dimulai|bukan JSON|Server/i.test(s.msg)) { stop = { t_s: (Date.now() - t0) / 1000, msg: s.msg.slice(0, 300) }; break; } }
    const tS = Date.now(); await sleep(5000);
    const fmAfter = await p.evaluate(() => (document.getElementById('fast-msg') || {}).textContent || '');
    Object.assign(R, { ui_stop: stop, requests_after_click: reqs.slice(n0).map(x => ({ t_s: (x.t - t0) / 1000, url: x.url.slice(0, 60) })), requests_after_stop_5s: reqs.filter(x => x.t > tS).length, fast_msg_after_5s: fmAfter,
      cancel_sent: reqs.slice(n0).some(x => /job_cancel/.test(x.url)) });
  }
  R.js_errors = errs; fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify(R)); await b.close();
})();
