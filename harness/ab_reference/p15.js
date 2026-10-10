/* p15.js <base> <out.json> : Fastest + Distillate dari popup -> final; klik Excel, Image Full, Save (TABEL DETAIL SLOT), Save input;
 * cek central store; reload halaman -> input tersimpan terbaca, flag internal tidak tersimpan. */
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const ctx = await b.newContext({ acceptDownloads: true, viewport: { width: 1500, height: 950 } }); const p = await ctx.newPage(); const R = { steps: {} }; const errs = [];
  await p.route('**/html2canvas.min.js', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: fs.readFileSync('/tmp/claude-0/h2c/package/dist/html2canvas.min.js', 'utf8') }));
  p.on('pageerror', e => errs.push(String(e.message).slice(0, 160))); p.on('dialog', async d => { try { await d.accept(); } catch (e) {} });
  const resps = []; p.on('response', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m && /save|store/.test(m)) resps.push({ m, status: r.status() }); });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  await p.evaluate(() => { const s = document.getElementById('f-tl-target'); s.value = 'fast'; s.dispatchEvent(new Event('change', { bubbles: true })); });
  const t0 = Date.now(); await p.click('#btn-run'); let clicked = false;
  while (Date.now() - t0 < 120000) { await sleep(300);
    const s = await p.evaluate(() => ({ pop: !!document.getElementById('gsf-dist') && !document.getElementById('gsf-dist').disabled, rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, gate: GSD_GATE, d: document.getElementById('btn-run').disabled }));
    if (s.pop && !clicked) { clicked = true; await p.click('#gsf-dist'); continue; }
    if (clicked && !s.d && s.rows === 48 && !s.gate) break; }
  R.final_s = (Date.now() - t0) / 1000;
  R.final = await p.evaluate(() => ({ status: OUTPUT.status, gate: (OUTPUT.release_gate || {}).status, cp: OUTPUT.info['Cost Production (USD/MWh)'], saved: OUTPUT._saved || null }));
  const dl = async (id) => { const en = await p.evaluate(i => { const e = document.getElementById(i); return !!e && !e.disabled; }, id); if (!en) return { enabled: false };
    try { const [d] = await Promise.all([p.waitForEvent('download', { timeout: 30000 }), p.click('#' + id)]); const f = '/tmp/claude-0/abw/p15_' + d.suggestedFilename(); await d.saveAs(f); return { enabled: true, file: d.suggestedFilename(), bytes: fs.statSync(f).size }; }
    catch (e) { return { enabled: true, error: String(e.message).slice(0, 100) }; } };
  R.steps.excel = await dl('btn-xls'); await sleep(500); R.steps.image_full = await dl('btn-img-full');
  await p.evaluate(() => { const t = document.querySelector('[data-cp="cp-simdata"]'); if (t) t.click(); }); await sleep(800);
  const n0 = resps.length; const ens = await p.evaluate(() => { const e = document.getElementById('btn-save-actual'); return !!e && !e.disabled; });
  if (ens) { await p.click('#btn-save-actual').catch(async () => { await p.evaluate(() => document.getElementById('btn-save-actual').click()); }); await sleep(1500);
    const opt = await p.$('#sv-opt-report') || await p.$('.sv-opt'); if (opt) { await opt.click().catch(() => {}); await sleep(2500); } }
  R.steps.save_actual = { enabled: ens, requests: resps.slice(n0) };
  const n1 = resps.length; await p.click('#btn-save'); await sleep(2500); R.steps.save_input = { requests: resps.slice(n1) };
  await p.reload(); await sleep(2500);
  R.steps.reload = await p.evaluate(() => { const m = (INPUT && INPUT.data3 && INPUT.data3.modeling) || {}; return { rows1: (INPUT.data1 || []).length, gas_action: m.gas_shortage_action, has_no_exact_family_flag: '__no_exact_family' in m, has_fastest_flag: '__fastest_local_only' in m, sr_mode: m.sr_mode, pv_rows: (m.pv_rows || []).length }; });
  R.errors = errs; fs.writeFileSync(OUT, JSON.stringify(R, null, 1)); console.log(JSON.stringify(R)); await b.close();
})().catch(e => { console.error('ERR', e); process.exit(1); });
