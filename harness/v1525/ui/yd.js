// UI Check Yesterday Data / Start Up CC / backward target (Fase 6): node yd.js <baseurl> <outdir>
// Klik nyata: tombol header -> pratinjau (sumber, versi, status 00:00) -> Cancel (tanpa perubahan) / Apply (status + kelanjutan + provenance)
// -> Save -> Reload (persisten); no-data; commitment eksplisit tidak ditimpa; Start Up CC manual; target STG backward tersimpan di payload.
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const [base, outDir] = process.argv.slice(2);
const res = []; const rec = (id, desc, ok, detail) => { res.push({ id, desc, status: ok ? 'PASS' : 'FAIL', detail }); console.log(ok ? 'PASS' : 'FAIL', id, desc, ok ? '' : JSON.stringify(detail).slice(0, 400)); };
(async () => {
  const browser = await chromium.launch(); const ctx = await browser.newContext({ viewport: { width: 1366, height: 680 } }); const page = await ctx.newPage(); page.on('dialog', d => d.accept());
  const open = async () => { await page.goto(base + '/index.php', { waitUntil: 'load' }); await page.waitForFunction(() => typeof assembleInput === 'function' && document.querySelector('#tbl-unit-flags select'), null, { timeout: 60000 }); await page.click('button.childtab[data-cp="cp-unitstatus"]'); await page.waitForSelector('#btn-yday', { state: 'visible' }); };
  const setDate = async d => { await page.evaluate(d => { const e = document.getElementById('f-plan_date'); e.value = d; e.dispatchEvent(new Event('change', { bubbles: true })); INPUT.data3.modeling.plan_date = d; }, d); };
  const pay = () => page.evaluate(() => assembleInput().data3.modeling);
  await open();
  await page.evaluate(() => { ['g6', 'g3', 'g4', 's1'].forEach(u => { if (COMMIT_MODE[u] && COMMIT_MODE[u] !== '-') { COMMIT_MODE[u] = '-'; } }); syncFlagArrays(); applyCommitToModel(); renderUnitFlags(); });
  await setDate('2026-10-05');
  const before = await pay();
  // tombol terlihat di header section, tidak menutup collapse control
  const bb = await page.$eval('#btn-yday', b => { const r = b.getBoundingClientRect(), a = b.parentElement.querySelector('.accv').getBoundingClientRect(); return { r: [r.left, r.top, r.right, r.bottom], arrow: [a.left, a.top, a.right, a.bottom], vis: r.width > 0 && r.height > 0 }; });
  rec('UI-YD-BTN', 'Tombol Check Yesterday Data terlihat di header Required & Commitment, tidak menutupi kontrol collapse', bb.vis && bb.r[2] <= bb.arrow[0] + 0.5, bb);
  // Cancel
  await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state && s.dataset.state !== 'loading'; }, null, { timeout: 20000 });
  const st1 = await page.$eval('#yd-state', e => [e.dataset.state, e.textContent]);
  await page.screenshot({ path: outDir + '/UI-YD-PREVIEW.png' });
  rec('UI-YD-PREVIEW', 'Pratinjau: sumber Monitoring Daily Plan tanggal kemarin + versi + pembuat + waktu simpan', st1[0] === 'success' && /Monitoring Daily Plan/.test(st1[1]) && /2026-10-04/.test(st1[1]) && /versi 1/.test(st1[1]), st1);
  const g6Row = await page.$eval('tr[data-yd-unit="G6"] .yd-st', e => e.textContent);
  rec('UI-YD-STARTUPCC', 'Status 00:00 G6 = Start Up CC (sequence blok S1 berjalan)', g6Row === 'Start Up CC', g6Row);
  await page.click('#yd-cancel');
  const afterCancel = await pay();
  rec('UI-YD-CANCEL', 'Cancel tidak mengubah state', JSON.stringify(afterCancel.unit_last_data_status) === JSON.stringify(before.unit_last_data_status) && !afterCancel.startup_continuation && !afterCancel.yesterday_data_source, afterCancel.unit_last_data_status);
  // Apply
  await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state === 'success'; }, null, { timeout: 20000 });
  await page.click('#yd-apply');
  const ap = await pay(); const selG6 = await page.$eval('#uld-G6', e => e.value);
  rec('UI-YD-APPLY', 'Apply: G6 Start Up CC di UI & payload, kelanjutan S1 elapsed 5, provenance Report ID/versi', selG6 === 'Start Up CC' && ap.unit_last_data_status.G6 === 'Start Up CC' && ap.startup_continuation && ap.startup_continuation.s1 && ap.startup_continuation.s1.elapsed_rows === 5 && ap.yesterday_data_source && ap.yesterday_data_source.source_type === 'MONITORING' && ap.yesterday_data_source.version_no === 1,
    { selG6, uld: ap.unit_last_data_status, cont: ap.startup_continuation, src: ap.yesterday_data_source });
  fs.writeFileSync(outDir + '/payload_after_apply.json', JSON.stringify(await page.evaluate(() => assembleInput())));
  // Save -> Reload
  await page.evaluate(async () => { await saveInput(); }); await open();
  const rl = await pay(); const selG6b = await page.$eval('#uld-G6', e => e.value); const prov = await page.$eval('#stg-target-row', e => e.textContent);
  rec('UI-YD-RELOAD', 'Save/Reload mempertahankan Start Up CC, kelanjutan, dan provenance', selG6b === 'Start Up CC' && rl.startup_continuation && rl.startup_continuation.s1 && rl.yesterday_data_source && /Monitoring Daily Plan/.test(prov), { selG6b, cont: rl.startup_continuation, prov });
  // user override setelah Apply: G6 -> Stop melepas kelanjutan (tidak ada state basi)
  await page.selectOption('#uld-G6', 'Stop'); const ov = await pay();
  rec('UI-YD-OVERRIDE', 'Override user sesudah Apply (G6 -> Stop) melepas kelanjutan S1', ov.unit_last_data_status.G6 === 'Stop' && !ov.startup_continuation, { uld: ov.unit_last_data_status.G6, cont: ov.startup_continuation });
  // commitment eksplisit tidak ditimpa
  await page.evaluate(() => { COMMIT_MODE.g6 = 'simulation'; syncFlagArrays(); applyCommitToModel(); renderUnitFlags(); });
  await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state === 'success'; }, null, { timeout: 20000 }); await page.click('#yd-apply');
  const ex = await pay();
  rec('UI-YD-EXPLICIT', 'Commitment eksplisit (G6 Required + Simulation) tidak ditimpa Apply', ex.unit_last_data_status.G6 === 'Stop' && (ex.required_mode.g6 || {}).mode === 'based_on_sim' && (ex.yesterday_data_source.units_skipped_explicit_commitment || []).includes('G6'), { uld: ex.unit_last_data_status.G6, rm: ex.required_mode.g6, src: ex.yesterday_data_source });
  await page.evaluate(() => { COMMIT_MODE.g6 = '-'; COMMIT_MODE.s1 = 'continuous'; syncFlagArrays(); applyCommitToModel(); renderUnitFlags(); });
  await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state === 'success'; }, null, { timeout: 20000 }); await page.click('#yd-apply');
  const es = await pay();
  rec('UI-YD-EXPLICIT-STG', 'STG S1 dengan commitment eksplisit: kelanjutan S1 tidak dipaksakan, G6 = Running, alasan tercatat', es.unit_last_data_status.G6 === 'Running' && !es.startup_continuation && (es.yesterday_data_source.continuation_skipped_explicit_stg || []).includes('S1'), { uld: es.unit_last_data_status.G6, cont: es.startup_continuation, src: es.yesterday_data_source });
  await page.evaluate(() => { COMMIT_MODE.s1 = '-'; syncFlagArrays(); applyCommitToModel(); renderUnitFlags(); });
  // no-data
  await setDate('2026-10-10'); await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state && s.dataset.state !== 'loading'; }, null, { timeout: 20000 });
  const nd = await page.$eval('#yd-state', e => [e.dataset.state, e.textContent]); const applyDis = await page.$eval('#yd-apply', e => e.disabled);
  await page.screenshot({ path: outDir + '/UI-YD-NODATA.png' }); await page.keyboard.press('Escape');
  rec('UI-YD-NODATA', 'Tanpa data kemarin: No Yesterday Data, Apply nonaktif, input tidak berubah', nd[0] === 'no-data' && /No Yesterday Data/.test(nd[1]) && applyDis === true, nd);
  // partial/corrupt -> fallback Daily Plan + keterangan
  await setDate('2026-10-15'); await page.click('#btn-yday'); await page.waitForFunction(() => { const s = document.getElementById('yd-state'); return s && s.dataset.state && s.dataset.state !== 'loading'; }, null, { timeout: 20000 });
  const pc = await page.$eval('#yd-state', e => [e.dataset.state, e.textContent]); await page.click('#yd-cancel');
  rec('UI-YD-PARTIAL', 'Monitoring kemarin rusak -> dilewati dengan alasan, Daily Plan dipakai (state partial)', pc[0] === 'partial' && /RESULT_HASH_MISMATCH/.test(pc[1]) && /Daily Plan/.test(pc[1]), pc);
  // Start Up CC manual + target backward
  await page.selectOption('#uld-G4', 'Start Up CC'); await page.selectOption('[data-stgt="s3"]', '02:00'); const mb = await pay();
  rec('UI-YD-MANUAL-SU', 'Start Up CC manual (G4) dan target first STG load S3 02:00 masuk payload', mb.unit_last_data_status.G4 === 'Start Up CC' && (mb.stg_first_load_target || {}).s3 === '02:00', { g4: mb.unit_last_data_status.G4, tg: mb.stg_first_load_target });
  await browser.close();
  fs.writeFileSync(outDir + '/yd_result.json', JSON.stringify(res, null, 1));
  console.log('TOTAL', res.length, 'PASS', res.filter(x => x.status === 'PASS').length, 'FAIL', res.filter(x => x.status === 'FAIL').length);
})().catch(e => { console.error(e); process.exit(1); });
