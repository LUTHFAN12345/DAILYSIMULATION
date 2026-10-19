// V15.31 UI test Monitoring Actual Gas kumulatif + Time Passed + header + lock: node ui_monitoring_ag.js <baseurl> <out.json>
const { chromium } = require('/opt/node-tools/node_modules/playwright');
const fs = require('fs');
const base = process.argv[2], outF = process.argv[3];
const res = []; let page, browser;
const rec = (id, desc, ok, detail) => { res.push({ id, desc, status: ok ? 'PASS' : 'FAIL', detail }); console.log(ok ? 'PASS' : 'FAIL', id, desc, JSON.stringify(detail).slice(0, 300)); };
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function open() { await page.goto(base + '/index.php', { waitUntil: 'load' }); await page.waitForFunction(() => typeof assembleInput === 'function' && typeof saveActualGas === 'function', null, { timeout: 60000 }); }
async function toMon() { await page.evaluate(() => { const b = document.getElementById('dpb-mon'); if (b && !dpMon()) b.click(); });
  await page.waitForFunction(() => dpMon(), null, { timeout: 30000 });
  await page.evaluate(() => { const t = document.querySelector('.subtab[data-sub="result"]'); if (t) t.click(); }); }
async function waitRun(ms) { const t0 = Date.now(); await sleep(500);
  while (Date.now() - t0 < ms) { const st = await page.evaluate(() => ({ fin: !!(OUTPUT && agIsFinal(OUTPUT)), busy: !!(window.PP_RUN_JOB_PENDING && Object.keys(window.PP_RUN_JOB_PENDING).length), msg: (document.getElementById('run-msg') || {}).innerText || '' }));
    if (st.fin && !st.busy) return st; await sleep(1000); } return null; }
async function setCell(key, row, val) { await page.evaluate(([k, r, v]) => { const e = document.querySelector(`#tbl-result .actin[data-act="${k}"][data-row="${r}"]`); if (!e) throw new Error('no cell ' + k + r); e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, [key, row, String(val)]); }
async function save() { const t0 = Date.now(); await page.evaluate(() => { svSaveEntry(); });
  await sleep(300); await page.waitForFunction(() => !(window.PP_AGOP && window.PP_AGOP.busy), null, { timeout: 240000 });
  return page.evaluate(t0 => { const op = window.PP_AGOP_LAST || {}; return { state: op.state, code: op.code || null, adjust: !!op.adjust, rid: op.rid || null, elapsed_s: op.elapsed_s, cutoff_before: op.cutoff_before, cutoff_after: op.cutoff_after }; }, t0); }
const st = () => page.evaluate(() => { const cut = mdpCutoff(); const tp = [...document.querySelectorAll('#tbl-result .tp-sel')].map(e => e.value).join('');
  const cells = {}; document.querySelectorAll('#tbl-result .actin').forEach(e => { if (e.value !== '') cells[e.dataset.act + e.dataset.row] = { v: +e.value, dis: e.disabled }; });
  const dis = [...document.querySelectorAll('#tbl-result .actin')].filter(e => e.disabled).map(e => e.dataset.act + e.dataset.row);
  const h = id => parseFloat(String((document.getElementById(id) || {}).textContent || 'NaN').replace(',', '.')); return { cut, tp, cells, disabled: dis, hdr: { pgn: h('gm-pgn'), ffj: h('gm-ffj'), ffm: h('gm-ffm') } }; });
(async () => {
  browser = await chromium.launch(); const ctx = await browser.newContext(); page = await ctx.newPage(); page.on('dialog', d => d.accept());
  await open(); await toMon();
  await page.evaluate(() => { runSim(); }); const r0 = await waitRun(400000); rec('PRE', 'Run Monitoring FINAL (basis)', !!r0, r0 && r0.msg.slice(0, 120));
  // 1: PGN 3,0 pada 01:00 (sel jam 00:30–01:00) -> 00:30 & 01:00 = Y
  await setCell('pgn', 0, 0.13); let sv = await save(); let s = await st();
  rec('T01', 'PGN 0,13 @01:00 Save -> row 00:30/01:00 = Y, row lain N', s.cut === 2 && s.tp.startsWith('YYN') && sv.state === 'FINAL', { sv, cut: s.cut, tp: s.tp.slice(0, 8) });
  rec('T17', 'header PGN = 0,13 langsung setelah Save', s.hdr.pgn === 0.13, s.hdr);
  rec('T07', 'row Y terkunci: sel Actual PGN 01:00 disabled', s.disabled.includes('pgn0') && s.disabled.includes('ffj0') && s.disabled.includes('ffm0'), s.disabled);
  // 9: PGN 4,5 pada 02:00 -> kanonik 4,5 (bukan 7,5), 3,0 tetap tersimpan & terkunci
  await setCell('pgn', 2, 0.3); sv = await save(); s = await st();
  rec('T09', 'PGN 0,13@01:00 + 0,30@02:00 -> header 0,30 (bukan 0,43); 0,13 tetap tersimpan terkunci', s.hdr.pgn === 0.3 && s.cells.pgn0 && s.cells.pgn0.v === 0.13 && s.cells.pgn0.dis && s.cut === 4 && sv.state === 'FINAL', { sv, hdr: s.hdr, cells: s.cells, cut: s.cut });
  rec('T10', 'hasil dihitung dengan latest row saja (Actual Gas Canonical engine = 0,30 row 3)', await page.evaluate(() => { const c = (OUTPUT.info || {})['Actual Gas Canonical']; return !!c && c.suppliers.pgn.value === 0.3 && c.suppliers.pgn.latest_row === 3; }), null);
  // 2/16: Jababeka 1,9 pada 03:00 -> header 1,9, cutoff 6
  await setCell('ffj', 4, 6.18); sv = await save(); s = await st();
  rec('T02', 'Jababeka @03:00 Save -> seluruh row s/d 03:00 = Y', s.cut === 6 && s.tp.startsWith('YYYYYYN'), { cut: s.cut, tp: s.tp.slice(0, 8), sv });
  rec('T16', 'header Jababeka 6,18 langsung setelah Save (bukan 0)', s.hdr.ffj === 6.18, s.hdr);
  // 3/18: MM2100 pada 04:00 -> cutoff maju, header MM2100
  await setCell('ffm', 6, 0); sv = await save(); s = await st();
  rec('T03', 'MM2100 @04:00 -> cutoff maju ke 8', s.cut === 8, { cut: s.cut, sv });
  rec('T04', 'row sesudah cutoff tetap N', s.tp.slice(8) === 'N'.repeat(40), s.tp);
  rec('T18', 'header MM2100 latest 0 (nol sah -> cutoff tetap maju); PGN 0,30; Jababeka 6,18 (supplier independen)', s.hdr.ffm === 0 && s.hdr.pgn === 0.3 && s.hdr.ffj === 6.18, s.hdr);
  rec('T14', 'ketiga supplier terisi pada timestamp berbeda, seluruh Save FINAL', sv.state === 'FINAL', sv);
  // 26: Save tanpa perubahan tidak menjalankan simulasi ulang
  sv = await save(); rec('T26', 'Save tanpa perubahan -> tanpa rerun (hasil FINAL sesi dipakai)', sv.state === 'FINAL' && !sv.adjust, sv);
  // 8/11: backend menolak edit row Y lewat request manual
  const rj = await page.evaluate(async () => { const p = assembleInput(); p.data3.modeling.actual_gas_snapshots.pgn[0] = 9.9; p.data3.modeling.actual_pgn_total[0] = 9.9;
    const r = await fetch('run.php?mode=save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p) }); const j = await r.json().catch(() => null); return { http: r.status, code: j && (j.code || (j.error || {}).code || j.error_code), msg: j && j.message }; });
  rec('T08', 'backend menolak edit Actual Gas row Y di luar UI (HTTP 409 TIME_PASSED_LOCKED)', rj.http === 409 && /TIME_PASSED_LOCKED/.test(JSON.stringify(rj)), rj);
  // 20: full screen / view mode tidak membuka kunci
  await page.evaluate(() => { if (typeof applySimViewMode === 'function') applySimViewMode(); }); await sleep(200); s = await st();
  rec('T20a', 'applySimViewMode/Full Screen tidak menghapus kunci row Y', s.disabled.includes('pgn0') && s.disabled.includes('pgn2'), s.disabled.slice(0, 12));
  // 11/19: unlock resmi (TIME PASSED = N mulai row 02:00), hapus 4,5 -> header kembali ke 3,0
  await page.evaluate(() => { const e = document.querySelector('#tbl-result .tp-sel[data-tprow="2"]'); e.value = 'N'; e.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.waitForFunction(() => !!window.PP_TP_UNLOCK_LAST, null, { timeout: 20000 }); await setCell('pgn', 2, ''); sv = await save(); s = await st();
  rec('T19', 'unlock resmi + hapus latest -> header PGN kembali ke 0,13 (latest prior)', s.hdr.pgn === 0.13 && sv.state === 'FINAL', { hdr: s.hdr, sv, cut: s.cut });
  rec('T11', 'sesudah unlock resmi, edit diterima dan kanonik dihitung ulang', sv.state === 'FINAL' || sv.state === 'DRAFT', sv);
  // 5/6/20: reload mempertahankan Y/N, nilai, header
  await open(); await toMon(); await sleep(2500); s = await st(); s.diag = await page.evaluate(() => ({ out: !!(OUTPUT && OUTPUT.data), snap: !!(INPUT.data3.modeling.actual_gas_snapshots), mon: dpMon() }));
  const sn = await page.evaluate(() => INPUT.data3.modeling.actual_gas_snapshots); rec('T05', 'reload mempertahankan TIME PASSED (cutoff 8) dan snapshot historis', s.cut === 8 && sn && sn.pgn[0] === 0.13 && sn.ffj[4] === 6.18 && sn.ffm[6] === 0 && (sn.pgn[2] === '' || sn.pgn[2] === null), { cut: s.cut, snap: sn && { pgn: sn.pgn.slice(0, 4), ffj: sn.ffj.slice(0, 6), ffm: sn.ffm.slice(0, 8) }, diag: s.diag });
  rec('T20b', 'reload: header tetap kanonik', s.hdr.pgn === 0.13 && s.hdr.ffj === 6.18 && s.hdr.ffm === 0, s.hdr);
  fs.writeFileSync(outF, JSON.stringify({ results: res, pass: res.filter(r => r.status === 'PASS').length, fail: res.filter(r => r.status !== 'PASS').length }, null, 1));
  await browser.close();
})().catch(async e => { console.error('ERR', e); rec('ERR', 'exception', false, String(e)); fs.writeFileSync(outF, JSON.stringify({ results: res }, null, 1)); try { await browser.close(); } catch (x) {} process.exit(1); });
