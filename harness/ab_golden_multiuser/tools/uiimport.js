// uiimport.js <base> <outdir> : U5 IE CSV import -> Apply -> tabel & grafik IE berubah
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1500, height: 1000 } });
  const R = { errs: [] }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 200)));
  await p.goto(BASE + '/index.php'); await sleep(1500); await p.getByText('Name Plan & IE/Dispatch', { exact: true }).first().click(); await sleep(400);
  const snap = () => p.evaluate(() => ({ ie21: (document.querySelector('[data-ie="20"]') || {}).value, path: (document.querySelectorAll('#ie-chart path')[1] || { getAttribute: () => '' }).getAttribute('d').slice(0, 60), stats: (document.getElementById('ie-chart-stats') || {}).innerText }));
  R.before = await snap();
  await p.setInputFiles('#csv-file', OUT + '/ie_import.csv'); await sleep(1500); R.status = await p.evaluate(() => (document.getElementById('csv-status') || {}).innerText); R.preview = await p.evaluate(() => ((document.getElementById('csv-preview') || {}).innerText || '').slice(0, 600));
  await p.evaluate(() => { const b = document.getElementById('csv-apply'); if (b && !b.disabled) b.click(); }); await sleep(800);
  R.after = await snap(); R.U5_table_changed = R.before.ie21 !== R.after.ie21; R.U5_chart_changed = R.before.stats !== R.after.stats;
  await p.locator('#ie-chart-card').scrollIntoViewIfNeeded(); await p.screenshot({ path: OUT + '/U5_ie_chart_after_import.png', clip: await p.locator('#ie-chart-card').boundingBox() });
  fs.writeFileSync(OUT + '/uiimport.json', JSON.stringify(R, null, 1)); await b.close(); })();
