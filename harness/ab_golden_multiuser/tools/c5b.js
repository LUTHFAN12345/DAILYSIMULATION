const { chromium } = require('playwright'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const mk = async () => { const c = await b.newContext(); const p = await c.newPage(); const reqs = []; p.on('response', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m && !/progress|tl_/.test(m)) reqs.push(m + ':' + r.status()); });
    await p.goto(BASE + '/index.php'); await sleep(1200); await p.evaluate(() => { const s = document.getElementById('f-tl-target'); s.value = 'max'; s.dispatchEvent(new Event('change', { bubbles: true })); }); return { p, reqs }; };
  const A = await mk(), B = await mk();
  // pra-kondisi seperti conc.js: A sudah menyelesaikan satu run (Max + LNG) lalu Save
  await A.p.click('#btn-run'); { const t1 = Date.now(); let ck = false; while (Date.now() - t1 < 150000) { await sleep(1000); const s = await A.p.evaluate(() => ({ pop: !!document.getElementById('gsf-box'), en: !!(document.getElementById('gsf-lng') && !document.getElementById('gsf-lng').disabled), rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState && window.ppRunTimerState() }));
    if (s.pop && s.en && !ck) { ck = true; await A.p.click('#gsf-lng'); } if (ck && s.rows === 48 && s.st && s.st.state === 'FINAL') break; } console.log('pre-run done', ((Date.now() - t1) / 1000).toFixed(0)); }
  await A.p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2000);
  const t0 = Date.now(); await Promise.all([A.p.click('#btn-run'), B.p.click('#btn-run')]); await sleep(4000);
  const jobs = await A.p.evaluate(() => fetch('run.php?mode=job_list').then(r => r.json())); const run = (jobs.jobs || []).filter(j => /RUNNING|CLAIMED|QUEUED/.test(j.status || ''));
  const ridB = await B.p.evaluate(() => window.PP_CUR_RID); console.log('running jobs', run.map(j => j.job_id + ':' + j.status));
  if (run[0]) console.log('cancel', JSON.stringify(await B.p.evaluate(([j, r]) => fetch('run.php?mode=job_cancel&abort=1&job=' + encodeURIComponent(j) + '&rid=' + encodeURIComponent(r)).then(x => x.json()), [run[0].job_id, ridB])));
  let last = '', clicked = false;
  while (Date.now() - t0 < 150000) { await sleep(1000); const s = await A.p.evaluate(() => ({ m: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 120), pop: !!document.getElementById('gsf-box'), lngEn: !!(document.getElementById('gsf-lng') && !document.getElementById('gsf-lng').disabled), rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, rid: window.PP_CUR_RID }));
    const l = JSON.stringify(s); if (l !== last) { console.log(((Date.now() - t0) / 1000).toFixed(0), l); last = l; }
    if (s.pop && s.lngEn && !clicked) { clicked = true; await A.p.click('#gsf-lng'); }
    if (s.rows === 48 && clicked && !s.pop && /FINAL|Done/.test(s.m)) break; }
  console.log('A reqs', A.reqs.join(' ')); await b.close(); })();
