// UI semantic smoke (P0.6): node smoke.js <baseurl> <outjson>
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const base = process.argv[2], outF = process.argv[3];
const res = []; let page, ctx, browser;
const rec = (id, desc, ok, detail) => { res.push({ id, desc, status: ok ? 'PASS' : 'FAIL', detail }); console.log(ok ? 'PASS' : 'FAIL', id, desc, ok ? '' : JSON.stringify(detail).slice(0, 400)); };
async function open() { await page.goto(base + '/index.php', { waitUntil: 'load' }); await page.waitForFunction(() => typeof assembleInput === 'function' && document.querySelector('#tbl-unit-flags select'), null, { timeout: 60000 }); await page.evaluate(() => { document.querySelectorAll('details').forEach(d => d.open = true); }); }
async function setFlag(u, kind, val) {
  // handler UI asli dipicu lewat event 'change' (panel anak tersembunyi tidak dapat diklik headless)
  await page.evaluate(([u, kind, val]) => { const e = document.querySelector(`#tbl-unit-flags [data-flag="${kind}"][data-u="${u}"]`); if (!e) throw new Error('no ' + kind + ' ' + u);
    if (kind === 'req') { if (e.checked === val) return; e.checked = val; } else { if (e.value === val) return; e.value = val; }
    e.dispatchEvent(new Event('change', { bubbles: true })); }, [u, kind, val]);
}
const pay = () => page.evaluate(() => { const p = assembleInput(); return p.data3.modeling; });
function sem(m, u) { const U = u.toUpperCase(); return { req: m.required_mode ? (m.required_mode[u] || null) : null, stop: m.stop_mode ? (m.stop_mode[u] || null) : null,
  cannot: (m.unit_cannot_stop || []).map(x => String(x).toLowerCase()).includes(u), last: (m.unit_last_data_status || {})[U] ?? null, inReq: (m.required_units || []).map(x => String(x).toLowerCase()).includes(u) }; }
async function save() { await page.evaluate(async () => { await saveInput(); }); const msg = await page.$eval('#run-msg', e => e.textContent); return msg; }
async function resetUnit(u) { await setFlag(u, 'req', false); await setFlag(u, 'stopmode', '-'); }
(async () => {
  browser = await chromium.launch(); ctx = await browser.newContext(); page = await ctx.newPage();
  page.on('dialog', d => d.accept());
  await open();
  const units = ['g1', 'g3', 'g4', 'g5'];
  for (const u of units) await resetUnit(u);
  // S1: Initial Stop + Start Based On Simulation + Stop Status Unit Continuous (Hold after start) — dicoba lintas unit (pertukaran konfigurasi)
  const sigs = {};
  for (const u of units) {
    for (const x of units) await resetUnit(x);
    await setFlag(u, 'req', true); await setFlag(u, 'mode', 'simulation'); await setFlag(u, 'stopmode', 'continuous');
    const s = sem(await pay(), u); fs.writeFileSync(`/tmp/w/ui/payload_S1_${u}.json`, JSON.stringify(await page.evaluate(() => assembleInput())));
    const ok = s.req && s.req.mode === 'based_on_sim' && s.stop && s.stop.mode === 'hold_to_end' && !s.cannot && /stop/i.test(String(s.last));
    rec('UI-S1-' + u.toUpperCase(), 'Initial Stop + Simulation + Hold after Start -> required_mode based_on_sim, stop_mode hold_to_end, bukan unit_cannot_stop, Last Status Stop', ok, s);
    sigs[u] = JSON.stringify({ req: s.req, stop: s.stop, cannot: s.cannot, last: s.last });
    // unit lain tidak terdampak

  }
  rec('UI-SWAP-1', 'Pertukaran konfigurasi antarunit: semantik payload identik untuk G1/G3/G4/G5 (tanpa hard-code unit)', new Set(Object.values(sigs)).size === 1, sigs);
  // S2: Commitment Continuous
  for (const x of units) await resetUnit(x);
  const sigC = {};
  for (const u of ['g1', 'g5']) { for (const x of units) await resetUnit(x);
    await setFlag(u, 'req', true); await setFlag(u, 'mode', 'continuous'); const s = sem(await pay(), u);
    rec('UI-S2-' + u.toUpperCase(), 'Commitment Mode Unit Continuous Running -> unit_cannot_stop + Last Status Running', s.cannot && /run/i.test(String(s.last)), s); sigC[u] = JSON.stringify(s); }
  rec('UI-SWAP-2', 'Continuous G1 vs G5 simetris', sigC.g1 === sigC.g5, sigC);
  for (const x of units) await resetUnit(x);
  // S3: Block Required ON/OFF
  const nb = await page.$$eval('#tbl-block-prio [data-bpreq]', a => a.length);
  for (let i = 0; i < nb; i++) {
    for (const v of [true, false]) {
      await page.evaluate(([i, v]) => { const e = document.querySelector(`#tbl-block-prio [data-bpreq="${i}"]`); if (e.checked !== v) { e.checked = v; e.dispatchEvent(new Event('change', { bubbles: true })); } }, [i, v]);
      const m = await pay(); const bru = m.block_required_user || {}; const bp = JSON.stringify(m.block_priority || []);
      const vals = Object.values(bru); const name = await page.evaluate(i => BP_STATE[i].block, i);
      rec(`UI-S3-B${i}-${v ? 'ON' : 'OFF'}`, `Block Required ${name} ${v ? 'ON' : 'OFF'} -> block_required_user, tanpa token required di block_priority`, bru[name] === v && !/required/i.test(bp), { name, bru });
    }
  }
  // S4: Save/Reload/Reload
  await setFlag('g5', 'req', true); await setFlag('g5', 'mode', 'simulation'); await setFlag('g5', 'stopmode', 'continuous');
  const before = sem(await pay(), 'g5'); const before3 = sem(await pay(), 'g3');
  const msg = await save(); rec('UI-S4-SAVE', 'Save Input berhasil', /saved/i.test(msg), msg);
  await open(); const r1 = sem(await pay(), 'g5'); await open(); const r2 = sem(await pay(), 'g5'); const r2g3 = sem(await pay(), 'g3');
  rec('UI-S4-RELOAD', 'Save/Reload/Reload: semantik G5 identik sesudah dua kali reload', JSON.stringify(before) === JSON.stringify(r1) && JSON.stringify(r1) === JSON.stringify(r2), { before, r1, r2 });
  rec('UI-S4-OTHER', 'Unit lain (G3) tidak berubah oleh Save/Reload', JSON.stringify(before3) === JSON.stringify(r2g3), { before3, r2g3 });
  // S5: stale state — matikan, simpan, reload: tidak ada sisa required/stop/cannot_stop
  await resetUnit('g5'); await save(); await open(); const st = sem(await pay(), 'g5');
  rec('UI-S5-STALE', 'Nilai false/empty membersihkan state lama (required_mode/stop_mode/unit_cannot_stop G5 hilang)', !st.req && !st.stop && !st.cannot && !st.inReq, st);
  fs.writeFileSync(outF, JSON.stringify(res, null, 1));
  await browser.close();
})().catch(async e => { console.error('ERROR', e); rec('UI-ERROR', 'eksekusi', false, String(e)); fs.writeFileSync(outF, JSON.stringify(res, null, 1)); if (browser) await browser.close(); process.exit(1); });
