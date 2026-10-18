// UI Distillate Unit Priority 1-6 (Fase 3): node dist.js <baseurl> <outdir>
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const [base, outDir] = process.argv.slice(2);
const res = []; const rec = (id, desc, ok, detail) => { res.push({ id, desc, status: ok ? 'PASS' : 'FAIL', detail }); console.log(ok ? 'PASS' : 'FAIL', id, desc, ok ? '' : JSON.stringify(detail).slice(0, 400)); };
(async () => {
  const browser = await chromium.launch(); const page = await (await browser.newContext({ viewport: { width: 1366, height: 680 } })).newPage(); page.on('dialog', d => d.accept());
  const open = async () => { await page.goto(base + '/index.php', { waitUntil: 'load' }); await page.waitForFunction(() => typeof assembleInput === 'function' && document.querySelector('#dist-blocks select'), null, { timeout: 60000 });
    await page.click('button.subtab[data-sub="periodic"]'); await page.click('button.childtab[data-cp="cp-priority"]');
    await page.evaluate(() => document.querySelectorAll('#cp-priority details').forEach(d => d.open = true)); await page.waitForSelector('#dist-blocks select', { state: 'visible' }); };
  const dist = () => page.evaluate(() => assembleInput().data3.modeling.unit_priority_dist);
  // payload lama (dua daftar Block 1 / Block 2) disuntikkan lewat state loader asli
  await open();
  await page.evaluate(() => { INPUT.data3.modeling.unit_priority_dist = [['g4', 'g6', 'g3'], ['g5', 'g1', 'g2'], ['g9', 'g8'], ['g7'], ['g10']]; initPriorityState(); renderDistPriority(); });
  const card = await page.$eval('[data-dist-card="Block 1–2"]', c => ({ rows: c.querySelectorAll('select[data-dist]').length, note: (c.querySelector('.dist-note') || {}).textContent || '', sel: [...c.querySelectorAll('select[data-dist]')].map(s => s.value) }));
  rec('UI-DIST-MERGED', 'Block 1 dan Block 2 digabung menjadi Priority 1-6 (satu kartu, 6 baris)', card.rows === 6 && /Priority 1–6/.test(card.note), card);
  rec('UI-DIST-MIGRATE', 'Migrasi payload lama: urutan grup & unit dipertahankan (G4,G6,G3,G5,G1,G2) + keterangan migrasi', JSON.stringify(card.sel) === JSON.stringify(['g4', 'g6', 'g3', 'g5', 'g1', 'g2']) && /dimigrasikan/.test(card.note), card);
  let d = await dist();
  rec('UI-DIST-PAYLOAD', 'Payload baru: satu daftar 6 unit di posisi pertama, blok lain tidak berubah', JSON.stringify(d[0]) === JSON.stringify(['g4', 'g6', 'g3', 'g5', 'g1', 'g2']) && JSON.stringify(d.slice(1)) === JSON.stringify([['g8', 'g9'].sort(), ['g7'], ['g10']].map((x, i) => i ? x : d[1])), d);
  await page.screenshot({ path: outDir + '/UI-DIST-CARD.png' });
  // pilih G1 sebagai Priority 1 dengan klik nyata pada select
  await page.selectOption('[data-dist-card="Block 1–2"] select[data-i="0"]', 'g1');
  d = await dist();
  rec('UI-DIST-REORDER', 'Pilih G1 di Priority 1: urutan unik 6 unit, G1 pertama', d[0][0] === 'g1' && new Set(d[0]).size === 6 && d[0].length === 6, d[0]);
  await page.evaluate(async () => { await saveInput(); }); await open();
  d = await dist(); const note2 = await page.$eval('[data-dist-card="Block 1–2"] .dist-note', e => e.textContent);
  rec('UI-DIST-RELOAD', 'Save/Reload: Priority 1-6 tersimpan (G1 pertama), tanpa label migrasi pada payload baru', d[0][0] === 'g1' && d[0].length === 6 && !/dimigrasikan/.test(note2), { d0: d[0], note2 });
  await browser.close(); fs.writeFileSync(outDir + '/dist_result.json', JSON.stringify(res, null, 1));
  console.log('TOTAL', res.length, 'PASS', res.filter(x => x.status === 'PASS').length, 'FAIL', res.filter(x => x.status === 'FAIL').length);
})().catch(e => { console.error(e); process.exit(1); });
