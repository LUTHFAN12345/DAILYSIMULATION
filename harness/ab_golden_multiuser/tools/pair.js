const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const mk = async (tgt, ie) => { const c = await b.newContext(); const p = await c.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e.message).slice(0, 200)));
    const reqs = []; p.on('response', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m && !/progress|tl_/.test(m)) reqs.push(m + ':' + r.status()); });
    await p.goto(BASE + '/index.php'); await sleep(1200);
    await p.evaluate(v => { const s = document.getElementById('f-tl-target'); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, tgt);
    if (ie) await p.evaluate(v => { const e = document.querySelector('[data-ie="0"]'); e.value = String(v); e.dispatchEvent(new Event('input', { bubbles: true })); }, ie);
    return { p, errs, reqs }; };
  const A = await mk('max', null), B = await mk('fast', 500);
  const t0 = Date.now(); await Promise.all([A.p.click('#btn-run'), B.p.click('#btn-run')]);
  const st = p => p.evaluate(() => ({ m: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 110), pop: !!document.getElementById('gsf-box'), rid: window.PP_CUR_RID }));
  let la = '', lb = '';
  while (Date.now() - t0 < 45000) { await sleep(1000); const a = JSON.stringify(await st(A.p)), bb = JSON.stringify(await st(B.p)); const t = ((Date.now() - t0) / 1000).toFixed(0);
    if (a !== la) { console.log(t, 'A', a); la = a; } if (bb !== lb) { console.log(t, 'B', bb); lb = bb; } }
  console.log('A reqs', A.reqs.join(' ')); console.log('B reqs', B.reqs.join(' ')); console.log('errs', A.errs, B.errs); await b.close(); })();
