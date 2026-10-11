// belowtbl.js <base> <outdir> : run golden, lalu daftar elemen yang terlihat setelah tabel hasil (remark/audit di bawah hasil)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 } }); await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => document.getElementById('btn-run').click()); const t0 = Date.now();
  while (Date.now() - t0 < 90000) { await sleep(700); const s = await p.evaluate(() => ({ rows: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data) ? OUTPUT.data.length : 0, st: window.ppRunTimerState ? window.ppRunTimerState().state : null, pop: !!document.getElementById('gsf-box') }));
    if (s.pop) await p.evaluate(() => { const e = document.getElementById('gsf-dist') || document.getElementById('gsf-lng'); if (e && !e.disabled) e.click(); });
    if (s.rows === 48 && s.st === 'FINAL') break; }
  await sleep(1500);
  const r = await p.evaluate(() => { const tbl = document.querySelector('table.simgrid'); const out = [];
    const all = Array.from(document.querySelectorAll('body *')).filter(e => e.offsetParent && e.children.length === 0 && (e.innerText || '').trim().length > 25);
    for (const e of all) { if (tbl && (tbl.compareDocumentPosition(e) & Node.DOCUMENT_POSITION_FOLLOWING) && !tbl.contains(e)) { const anc = e.closest('[id]'); out.push([(anc && anc.id) || e.tagName, (e.innerText || '').trim().slice(0, 160)]); } }
    return out.slice(0, 60); });
  fs.writeFileSync(OUT + '/below_table.json', JSON.stringify(r, null, 1));
  await p.evaluate(() => { const tbl = document.querySelector('table.simgrid'); let el = tbl; while (el && el.scrollHeight <= el.clientHeight) el = el.parentElement; if (el) el.scrollTop = el.scrollHeight; window.scrollTo(0, document.body.scrollHeight); });
  await sleep(500); await p.screenshot({ path: OUT + '/below_table.png' }); await b.close(); })();
