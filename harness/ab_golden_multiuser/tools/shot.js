// shot.js <base> <outdir> : screenshot halaman + daftar tab/section
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv;
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php'); await p.waitForTimeout(2000);
  await p.screenshot({ path: OUT + '/home.png', fullPage: false });
  const info = await p.evaluate(() => {
    const tabs = Array.from(document.querySelectorAll('button, .tab, [role=tab], a')).map(e => (e.innerText || '').trim()).filter(t => t && t.length < 40);
    const ids = Array.from(document.querySelectorAll('[id]')).map(e => e.id).filter(i => /ie|pv|sr|progress|timer/i.test(i));
    return { tabs: Array.from(new Set(tabs)).slice(0, 120), ids: ids.slice(0, 200) };
  });
  fs.writeFileSync(OUT + '/info.json', JSON.stringify({ ...info, errs }, null, 1)); await b.close(); })();
