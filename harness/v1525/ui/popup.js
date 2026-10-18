// UI popup Ctrl+Click / Fix Load (Fase 2): node popup.js <baseurl> <run_output.json> <outdir>
// Klik nyata (mouse, modifier Control) pada sel atas/tengah/bawah x kolom kiri/tengah/kanan, tabel normal & Full Screen,
// zoom 100/125/150% (viewport CSS = layar/zoom, deviceScaleFactor = zoom), laptop 1366x768 & 1920x1080 (taskbar 48 px), tablet 1024x768.
// Lulus bila: seluruh popup di dalam viewport terlihat, OK & Cancel teratas pada titik pusatnya (elementFromPoint), Esc = batal tanpa ubah state,
// Enter = OK hanya bila nilai valid, klik luar = batal, Tab terkurung di popup, nilai tidak berubah sebelum OK.
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const [base, outJson, outDir] = process.argv.slice(2);
const OUT = JSON.parse(fs.readFileSync(outJson, 'utf8')).output;
const res = []; const rec = (id, desc, ok, detail) => { res.push({ id, desc, status: ok ? 'PASS' : 'FAIL', detail }); console.log(ok ? 'PASS' : 'FAIL', id, desc, ok ? '' : JSON.stringify(detail).slice(0, 500)); };
const SCREENS = [['laptop1366', 1366, 768 - 48 - 80], ['fhd1920', 1920, 1080 - 48 - 80], ['tablet1024', 1024, 768 - 60]];   // tinggi = layar - taskbar - chrome browser
const ZOOMS = [1, 1.25, 1.5];
(async () => {
  const browser = await chromium.launch();
  for (const [sname, W, H] of SCREENS) for (const z of ZOOMS) {
    if (sname === 'tablet1024' && z !== 1) continue;
    const ctx = await browser.newContext({ viewport: { width: Math.round(W / z), height: Math.round(H / z) }, deviceScaleFactor: z, hasTouch: sname.startsWith('tablet') });
    const page = await ctx.newPage(); page.on('dialog', d => d.accept());
    await page.goto(base + '/index.php', { waitUntil: 'load' });
    await page.waitForFunction(() => typeof renderResult === 'function' && typeof assembleInput === 'function' && document.querySelector('#tbl-unit-flags select'), null, { timeout: 60000 });
    await page.evaluate(o => { window.__runs = 0; runSim = async () => { window.__runs++; }; INPUT = assembleInput(); OUTPUT = o; renderResult(o); if (typeof showSimulationDataResult === 'function') showSimulationDataResult(); }, OUT);
    for (const mode of ['normal', 'fullscreen']) {
      if (mode === 'fullscreen') { await page.click('#btn-sim-fullscreen'); await page.waitForSelector('#simfs-modal.open'); }
      const cols = await page.$$eval('#tbl-result td[data-fix][data-row="0"]', a => a.map(td => td.dataset.fix));
      const pickC = [cols[0], cols[Math.floor(cols.length / 2)], cols[cols.length - 1]];
      for (const row of [0, 23, 47]) for (const u of pickC) { try {
        const sel = `#tbl-result td[data-fix="${u}"][data-row="${row}"]`;
        await page.$eval(sel, td => td.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
        const before = await page.evaluate(([u, r]) => cellFixed(u, r), [u, row]);
        await page.click(sel, { modifiers: ['Control'] });
        await page.waitForSelector('#fixpop', { timeout: 5000 });
        await page.waitForTimeout(150);
        const g = await page.evaluate(() => {
          const p = document.getElementById('fixpop'), r = p.getBoundingClientRect(), vv = window.visualViewport;
          const vw = vv ? vv.width : innerWidth, vh = vv ? vv.height : innerHeight;
          const top = id => { const b = document.getElementById(id); if (!b) return null; const q = b.getBoundingClientRect(); const e = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); return { hit: e === b || b.contains(e), x: q.left + q.width / 2, y: q.top + q.height / 2, r: [q.left, q.top, q.right, q.bottom] }; };
          return { pop: [r.left, r.top, r.right, r.bottom], vw, vh, ok: top('fixpop-apply'), cancel: top('fixpop-cancel'), parent: p.parentNode === document.body, placed: p.dataset.placed, z: getComputedStyle(p).zIndex };
        });
        const inside = g.pop[0] >= 0 && g.pop[1] >= 0 && g.pop[2] <= g.vw + 0.5 && g.pop[3] <= g.vh + 0.5;
        const id = `UI-POP-${sname}-z${Math.round(z * 100)}-${mode}-r${row + 1}-${u}`;
        const okVis = inside && g.ok && g.ok.hit && g.cancel && g.cancel.hit && g.parent;
        const shot = `${outDir}/${id}.png`; await page.screenshot({ path: shot });
        // Esc = Cancel: popup tertutup, Full Screen tetap terbuka, Fix tidak berubah, tidak ada Run
        await page.fill('#fixpop-val', '77.7'); await page.keyboard.press('Escape');
        const afterEsc = await page.evaluate(([u, r]) => ({ pop: !!document.getElementById('fixpop'), fix: cellFixed(u, r), fs: document.getElementById('simfs-modal').classList.contains('open'), runs: window.__runs }), [u, row]);
        const escOk = !afterEsc.pop && afterEsc.fix === before && afterEsc.fs === (mode === 'fullscreen') && afterEsc.runs === 0;
        rec(id, `Popup terlihat penuh, OK/Cancel dapat diklik; Esc batal tanpa ubah (${mode}, zoom ${z * 100}%, ${sname})`, okVis && escOk, { g, afterEsc, shot });
        if (row === 23 && u === pickC[1]) {
          // Cancel via klik mouse, klik di luar, Enter tidak valid, Tab trap, Enter valid = OK (Run distub)
          await page.click(sel, { modifiers: ['Control'] }); await page.waitForSelector('#fixpop');
          const c = await page.evaluate(() => { const q = document.getElementById('fixpop-cancel').getBoundingClientRect(); return [q.left + q.width / 2, q.top + q.height / 2]; });
          await page.fill('#fixpop-val', '66'); await page.mouse.click(c[0], c[1]);
          const aC = await page.evaluate(([u, r]) => ({ pop: !!document.getElementById('fixpop'), fix: cellFixed(u, r) }), [u, row]);
          rec(`${id}-CANCEL`, 'Klik Cancel menutup tanpa menyimpan', !aC.pop && aC.fix === before, aC);
          await page.click(sel, { modifiers: ['Control'] }); await page.waitForSelector('#fixpop');
          await page.fill('#fixpop-val', '55'); const pr = await page.$eval('#fixpop', p => p.getBoundingClientRect().toJSON());
          await page.mouse.click(Math.max(2, pr.left - 30 > 2 ? pr.left - 30 : pr.right + 30), Math.max(2, pr.top + 5));
          const aO = await page.evaluate(([u, r]) => ({ pop: !!document.getElementById('fixpop'), fix: cellFixed(u, r) }), [u, row]);
          rec(`${id}-OUTSIDE`, 'Klik di luar menutup tanpa menyimpan', !aO.pop && aO.fix === before, aO);
          await page.click(sel, { modifiers: ['Control'] }); await page.waitForSelector('#fixpop');
          await page.fill('#fixpop-val', ''); await page.focus('#fixpop-val'); await page.keyboard.press('Enter'); await page.waitForTimeout(200);
          const aE = await page.evaluate(([u, r]) => ({ pop: !!document.getElementById('fixpop'), fix: cellFixed(u, r), runs: window.__runs }), [u, row]);
          rec(`${id}-ENTER-INVALID`, 'Enter dengan nilai kosong tidak menyimpan / tidak Run', aE.pop && aE.fix === before && aE.runs === 0, aE);
          const tabs = []; for (let k = 0; k < 6; k++) { await page.keyboard.press('Tab'); tabs.push(await page.evaluate(() => document.getElementById('fixpop').contains(document.activeElement))); }
          rec(`${id}-TABTRAP`, 'Tab tetap di dalam popup (focus trap)', tabs.every(Boolean), tabs);
          // nilai legal dari precheck (MIN..MAX) agar OK sah
          await page.waitForFunction(() => /MW/.test((document.getElementById('fixpop-min') || {}).textContent || '') || /^-$/.test((document.getElementById('fixpop-min') || {}).textContent || ''), null, { timeout: 15000 }).catch(() => {});
          const mm = await page.evaluate(() => [document.getElementById('fixpop-min').textContent, document.getElementById('fixpop-max').textContent]);
          const mn = parseFloat(mm[0]), mx = parseFloat(mm[1]);
          if (isFinite(mn) && isFinite(mx) && mx > 0) {
            const v = Math.round(((mn + mx) / 2) * 10) / 10;
            await page.fill('#fixpop-val', String(v)); await page.focus('#fixpop-val'); await page.keyboard.press('Enter');
            await page.waitForFunction(() => !document.getElementById('fixpop'), null, { timeout: 15000 }).catch(() => {});
            const aK = await page.evaluate(([u, r]) => ({ pop: !!document.getElementById('fixpop'), fix: cellFixed(u, r), runs: window.__runs }), [u, row]);
            rec(`${id}-ENTER-OK`, `Enter dengan nilai legal ${v} = OK (Fix tersimpan, Run dipicu)`, !aK.pop && Math.abs(aK.fix - v) < 1e-9 && aK.runs === 1, { aK, mm });
            await page.evaluate(([u, r]) => { clearFixLoad(u, r); syncFixCells(); window.__runs = 0; }, [u, row]);
          } else rec(`${id}-ENTER-OK`, 'MIN/MAX legal dari precheck tersedia', false, mm);
        }
      } catch (e) { rec(`UI-POP-${sname}-z${Math.round(z * 100)}-${mode}-r${row + 1}-${u}-ERROR`, 'langkah uji gagal dijalankan', false, String(e.message || e).slice(0, 300));
        await page.evaluate(() => { try { closeFixPop(); } catch (e) {} }); if (mode === 'fullscreen') await page.evaluate(() => { const m = document.getElementById('simfs-modal'); if (m && !m.classList.contains('open')) document.getElementById('btn-sim-fullscreen').click(); }); } }
      if (mode === 'fullscreen') { await page.evaluate(() => { const b = document.getElementById('simfs-close'); if (b && document.getElementById('simfs-modal').classList.contains('open')) b.click(); }); }
    }
    // scroll mengikuti sel: buka popup lalu scroll tabel; popup tetap dalam viewport
    try {
    await page.click('#btn-sim-fullscreen'); await page.waitForSelector('#simfs-modal.open');
    const sel = '#tbl-result td[data-fix][data-row="40"]';
    await page.$eval(sel, td => td.scrollIntoView({ block: 'center' })); await page.click(sel, { modifiers: ['Control'] }); await page.waitForSelector('#fixpop');
    await page.evaluate(() => { const w = document.querySelector('#simfs-modal .simwrap') || document.querySelector('#simfs-body'); w.scrollTop += 200; w.scrollLeft += 150; w.dispatchEvent(new Event('scroll')); });
    await page.waitForTimeout(200);
    const gs = await page.evaluate(() => { const p = document.getElementById('fixpop').getBoundingClientRect(); return [p.left, p.top, p.right, p.bottom, innerWidth, innerHeight]; });
    rec(`UI-POP-${sname}-z${Math.round(z * 100)}-SCROLL`, 'Popup tetap di viewport setelah tabel di-scroll', gs[0] >= 0 && gs[1] >= 0 && gs[2] <= gs[4] + 0.5 && gs[3] <= gs[5] + 0.5, gs);
    await page.screenshot({ path: `${outDir}/UI-POP-${sname}-z${Math.round(z * 100)}-SCROLL.png` });
    } catch (e) { rec(`UI-POP-${sname}-z${Math.round(z * 100)}-SCROLL`, 'langkah uji gagal dijalankan', false, String(e.message || e).slice(0, 300)); }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(outDir + '/popup_result.json', JSON.stringify(res, null, 1));
  console.log('TOTAL', res.length, 'PASS', res.filter(x => x.status === 'PASS').length, 'FAIL', res.filter(x => x.status === 'FAIL').length);
})().catch(e => { console.error(e); process.exit(1); });
