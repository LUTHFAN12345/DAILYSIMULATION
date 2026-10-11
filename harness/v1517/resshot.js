// resshot.js <base> <outdir> : Run (Fastest), tunggu FINAL, screenshot area hasil + teks di bawah tabel hasil
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 } }); await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now();
  while (Date.now() - t0 < 90000) { await sleep(700); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop) { await p.evaluate(() => { const e = document.getElementById('gsf-dist') || document.getElementById('gsf-lng'); if (e && !e.disabled) e.click(); }); }
    if (s.rows === 48 && s.st === 'FINAL') break; }
  await sleep(1200);
  const info = await p.evaluate(() => { const m = document.getElementById('run-msg'); const out = { runmsg: m ? m.innerText : null };
    // blok teks yang terlihat di bawah tabel hasil (sesudah #run-msg dalam panel hasil)
    const panel = m ? m.closest('section,.card,.panel,div') : null; out.blocks = [];
    document.querySelectorAll('[id]').forEach(el => { const r = el.getBoundingClientRect(); if (el.offsetParent && /remark|note|audit|warn|gate|explain|detail|acc|summary-note|run-/i.test(el.id) && (el.innerText || '').trim().length > 40) out.blocks.push([el.id, (el.innerText || '').trim().slice(0, 300)]); });
    return out; });
  info.gate = await p.evaluate(() => !OUTPUT ? null : JSON.stringify({ rg: OUTPUT.release_gate, hv: OUTPUT.hard_validation || (OUTPUT.info||{})['Hard Validation'], dca: (OUTPUT.info||{})['Distillate Continuity Audit'], rs: ((OUTPUT.info||{})['Run Status']||{}).mode, ie: (OUTPUT.info||{})['IE Incremental Redispatch'] }).slice(0, 4000));
  fs.writeFileSync(OUT + '/result_blocks.json', JSON.stringify(info, null, 1));
  await p.screenshot({ path: OUT + '/result_full.png', fullPage: true });
  await b.close(); })();
