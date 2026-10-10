// uitimer.js <base> <out.json> <fuel> : timer lifecycle sepanjang run + klik popup bahan bakar sampai FINAL
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT, FUEL] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 } }); const R = { errs: [], ticks: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 200)));
  await p.goto(BASE + '/index.php'); await sleep(1500); const t0 = Date.now(); await p.click('#btn-run'); let clicked = false;
  while (Date.now() - t0 < 90000) { await sleep(1000);
    const st = await p.evaluate(() => ({ s: window.ppRunTimerState && window.ppRunTimerState(), txt: (document.getElementById('run-timer') || {}).innerText || '', pop: !!document.getElementById('gsf-box'), rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0 }));
    R.ticks.push({ t: +((Date.now() - t0) / 1000).toFixed(1), el: (st.txt.match(/Elapsed: (\S+)/) || [])[1], stage: (st.txt.match(/Stage: ([^\n]+)/) || [])[1], state: st.s && st.s.state, rid: st.s && st.s.rid, rows: st.rows });
    if (st.pop && !clicked && FUEL) { clicked = true; await sleep(800); await p.click(FUEL === 'lng' ? '#gsf-lng' : '#gsf-dist'); continue; }
    if (st.s && st.s.state === 'FINAL' && st.rows === 48) { await p.screenshot({ path: OUT.replace('.json', '_final.png') }); break; } }
  fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); await b.close(); })();
