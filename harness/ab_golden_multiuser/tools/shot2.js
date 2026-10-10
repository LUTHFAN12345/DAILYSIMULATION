const { chromium } = require('playwright'); const [,, BASE, OUT, ...TABS] = process.argv;
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 1000 } }); await p.goto(BASE + '/index.php'); await p.waitForTimeout(1500);
  for (const t of TABS) { const el = p.getByText(t, { exact: true }).first(); try { await el.click({ timeout: 3000 }); await p.waitForTimeout(800);
      await p.screenshot({ path: OUT + '/tab_' + t.replace(/[^A-Za-z0-9]+/g, '_') + '.png', fullPage: true }); } catch (e) { console.log('ERR', t, String(e).slice(0, 100)); } }
  await b.close(); })();
