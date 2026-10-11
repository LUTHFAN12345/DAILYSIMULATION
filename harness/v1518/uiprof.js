// uiprof.js <base> : CDP CPU profile browser dari klik Run sampai FINAL; ringkas self-time per fungsi
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1400, height: 900 } });
  await p.goto(BASE + '/index.php'); await sleep(1500); const cdp = await p.context().newCDPSession(p);
  await cdp.send('Profiler.enable'); await cdp.send('Profiler.setSamplingInterval', { interval: 200 }); await cdp.send('Profiler.start');
  await p.evaluate(() => document.getElementById('btn-run').click()); const tc = Date.now();
  while (Date.now() - tc < 200000) { await sleep(100); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null }));
    if (s.rows === 48 && s.st === 'FINAL') break; }
  const { profile } = await cdp.send('Profiler.stop'); const self = {}; const byId = {}; profile.nodes.forEach(n => byId[n.id] = n);
  const dt = profile.timeDeltas; const cnt = {}; profile.samples.forEach((s, i) => { cnt[s] = (cnt[s] || 0) + (dt[i] || 0); });
  for (const id in cnt) { const n = byId[id]; const k = n.callFrame.functionName + ':' + n.callFrame.lineNumber; self[k] = (self[k] || 0) + cnt[id]; }
  const tot = Object.values(self).reduce((a, c) => a + c, 0); console.log('total ms', (tot / 1000).toFixed(0), 'wall', Date.now() - tc);
  Object.entries(self).sort((a, c) => c[1] - a[1]).slice(0, 25).forEach(([k, v]) => console.log((v / 1000).toFixed(0).padStart(6), k)); await b.close(); })();
