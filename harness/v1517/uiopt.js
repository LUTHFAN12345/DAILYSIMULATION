// uiopt.js <base> <outdir> : R4 Stop Status sync (UI) + R9 remark ringkas (desktop/fullscreen/mobile/error) + Distillate 75% (Simulation Data/Excel)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const c = await b.newContext(); const p = await c.newPage({ viewport: { width: 1500, height: 1000 } });
  const R = { errs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 160)));
  await p.goto(BASE + '/index.php'); await sleep(1500);
  const sel = async (flag, u, v) => { await p.evaluate(([f, u, v]) => { const e = document.querySelector(`[data-flag="${f}"][data-u="${u}"]`); e.disabled = false; e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); }, [flag, u, v]); await sleep(200); };
  const st = u => p.evaluate(u => ({ commit: COMMIT_MODE[u], stop: STOP_MODE[u], stopAt: STOP_AT[u], modeSel: (document.querySelector(`[data-flag="mode"][data-u="${u}"]`) || {}).value,
    stopSel: (document.querySelector(`[data-flag="stopmode"][data-u="${u}"]`) || {}).value, rm: buildRequiredModeObj()[u] || null, sm: buildStopModeObj()[u] || null, cannot: CANNOT_STOP.includes(u) }), u);
  R.sync = {}; await sel('stopmode', 'g5', 'continuous'); R.sync.stop_to_cont = await st('g5');
  await sel('stopmode', 'g5', 'request'); await sel('stopat', 'g5', '16:00'); R.sync.cont_to_request = await st('g5');
  await sel('req', 'g5', '') ; await p.evaluate(() => { const e = document.querySelector('[data-flag="req"][data-u="g5"]'); e.checked = true; e.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(200);
  await sel('mode', 'g5', 'continuous'); R.sync.mode_to_cont = await st('g5');
  R.sync.pass = R.sync.stop_to_cont.commit === 'continuous' && R.sync.stop_to_cont.rm && R.sync.stop_to_cont.rm.mode === 'continuous' && R.sync.stop_to_cont.sm === null && R.sync.stop_to_cont.cannot
    && R.sync.cont_to_request.commit === '-' && R.sync.cont_to_request.sm && R.sync.cont_to_request.sm.mode === 'stop_at' && R.sync.cont_to_request.sm.at === '16:00' && !R.sync.cont_to_request.cannot
    && R.sync.mode_to_cont.stopSel === 'continuous' && R.sync.mode_to_cont.sm === null;
  await p.reload(); await sleep(1500);   // kembali ke input golden (perubahan di atas tidak di-save)
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now();
  while (Date.now() - t0 < 120000) { await sleep(600); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop) await p.evaluate(() => { const e = document.getElementById('gsf-dist'); if (e && !e.disabled) e.click(); });
    if (s.rows === 48 && s.st === 'FINAL') break; }
  R.t = (Date.now() - t0) / 1000; await sleep(1000);
  const msg = () => p.evaluate(() => { const e = document.getElementById('run-msg'); return { text: e ? e.innerText : null, lines: e ? e.innerText.split('\n').filter(x => x.trim()).length : 0, h: e ? e.getBoundingClientRect().height : 0 }; });
  R.msg_desktop = await msg(); await p.screenshot({ path: OUT + '/R9_desktop.png' });
  await p.setViewportSize({ width: 1920, height: 1080 }); await sleep(500); R.msg_full = await msg(); await p.screenshot({ path: OUT + '/R9_fullscreen.png' });
  await p.setViewportSize({ width: 390, height: 844 }); await sleep(500); R.msg_mobile = await msg(); await p.screenshot({ path: OUT + '/R9_mobile.png', fullPage: false });
  R.overflow_mobile = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1 ? document.documentElement.scrollWidth : 0);
  await p.setViewportSize({ width: 1500, height: 1000 });
  R.dist = await p.evaluate(() => { const x = resultToHTMLTable(); const cols = (x.match(/Distillate G\d+ \(%\)/g) || []); const D = OUTPUT.data; const lv = {};
    D.forEach(r => Object.keys(r).forEach(k => { const m = k.match(/^Dist_?(G\d+)_?pct$|^(G\d+)_Dist_Pct$/i); if (m) { const v = +r[k]; if (v > 0) lv[v] = (lv[v] || 0) + 1; } }));
    const a = (OUTPUT.info || {})['Distillate Continuity Audit'] || {}; return { excel_cols: cols, excel_has75: /<td[^>]*>75(\.0+)?<\/td>/.test(x), levels_from_rows: lv, audit: a.status, keys_sample: Object.keys(D[30]).filter(k => /dist/i.test(k)) }; });
  await c.setOffline(true); await p.evaluate(() => document.getElementById('btn-run').click()); await sleep(6000); R.msg_error = await msg(); await p.screenshot({ path: OUT + '/R9_error.png' }); await c.setOffline(false);
  fs.writeFileSync(OUT + '/uiopt.json', JSON.stringify(R, null, 1)); console.log(JSON.stringify(R).slice(0, 3000)); await b.close(); })();
