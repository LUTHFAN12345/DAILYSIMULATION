/* DELAPAN TARGETED TEST — PGN 25 rekomendasi bahan bakar dan runtime.
 * node targeted8_pgn25.js <base-url> <folder-jobs> <out.md> */
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const BASE = process.argv[2], JOBS = process.argv[3], OUT = process.argv[4]; const PGN = +(process.argv[5] || 25);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const R = []; const RT = {};
function rec(id, n, ok, d = '') { R.push([id, n, !!ok, d]); console.log((ok ? 'PASS' : 'FAIL') + '  ' + id.padEnd(5) + ' ' + n + (d ? '  -- ' + d : '')); }
let pageErrs = [];
async function sesi(browser, pgn) {
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1000 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => pageErrs.push(String(e.message || e).slice(0, 160)));
  p._polls = []; p._t0 = 0;
  p._runs = [];
  p.on('response', async r => { const u = r.url(); if (u.includes('mode=run')) { p._runs.push(Date.now()); return; } if (!u.includes('mode=job_poll')) return;
    try { const j = await r.json(); p._polls.push({ t: Date.now(), st: (j.job || {}).status, pct: (j.job || {}).percent, res: !!j.result, kind: (j.job || {}).kind }); } catch (e) {} });
  await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded', timeout: 60000 });
  await sleep(800);
  await p.evaluate(v => { const f = document.getElementById('q-pgn_pipe'); f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); }, String(pgn));
  await p.evaluate(() => { if (typeof activateSub === 'function') activateSub('frequent'); if (typeof activateChild === 'function') activateChild('frequent', 'cp-gas'); });
  await sleep(300);
  return p;
}
async function pilih(p, act) { await p.locator(`#gsd-seg .gsd-opt[data-act="${act}"]`).first().click(); await sleep(200); }
async function klikRun(p) { p._t0 = Date.now(); p._polls = []; p._runs = []; await p.locator('#btn-run').click(); }
const keadaan = p => p.evaluate(() => {
  const o = (typeof OUTPUT !== 'undefined' && OUTPUT) ? OUTPUT : null;
  const i = (o && o.info) || {}; const rs = i['Run Status'] || {}; const rg = (o && o.release_gate) || {};
  const b = id => { const x = document.getElementById(id); return x ? !x.disabled : null; };
  return { pop: !!document.getElementById('gsf-mask'), num: ((document.getElementById('gsf-numbers') || {}).innerText || ''),
    lng: b('gsf-lng'), dist: b('gsf-dist'), gate: (typeof GSD_GATE !== 'undefined') ? GSD_GATE : null,
    rows: o ? (o.data || []).length : 0, conv: rs.converged, econ: rs.economic_review_completed,
    hard: rg.hard_validation, econGate: rg.economic_review, rel: rg.release_allowed, exp: i['PLN Export Compliance'],
    src: i['Fuel Action Source'], added: i['Added LNG (BBTUD)'], used: i['Distillate Used (l)'],
    resid: i['Residual Gas Shortage (BBTUD)'], eff: i['Effective Gas Quota (BBTUD)'],
    fsum: !!document.getElementById('fuel-decision-summary'), save: b('btn-save'), xls: b('btn-xls'),
    msg: ((document.getElementById('run-msg') || {}).innerText || '').slice(0, 200) };
});
async function tunggu(p, cond, maxMs = 900000) { const t = Date.now(); let s;
  while (Date.now() - t < maxMs) { s = await keadaan(p); if (cond(s)) return s; await sleep(250); } return s; }
const finalOk = s => s.rows === 48 && s.conv === true && s.econ === true && s.hard === 'PASS' && s.econGate === 'PASS'
  && s.exp === 'OK' && Number(s.resid) <= 1e-9 && s.rel === true && s.gate === false;
const kual = s => JSON.stringify({ rows: s.rows, conv: s.conv, econ: s.econ, hard: s.hard, econGate: s.econGate, exp: s.exp, resid: s.resid, rel: s.rel, src: s.src, added: s.added, used: s.used });
/* Jeda dari hasil TERSEDIA sampai tampil: hasil tersedia = poll DONE terakhir SETELAH respons Run
 * terakhir; bila rerun final selesai langsung di respons Run (tanpa job), yang diukur adalah jeda
 * respons Run -> tampil. Poll DONE milik job analisis sebelum rerun tidak dihitung. */
function lagSetelahDone(p, tFinal) { const tr = (p._runs || []).filter(t => t <= tFinal).pop() || 0;
  const d = p._polls.filter(x => x.st === 'DONE' && x.res && x.t <= tFinal && x.t >= tr).pop();
  if (d) return tFinal - d.t; return tr ? (tFinal - tr) : null; }
async function pollSesudah(p, tFinal) { await sleep(4000); return p._polls.filter(x => x.t > tFinal + 1500).length; }
const sisaPoll = [];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  let REQ = null, DREQ = null; const lags = [];
  /* T1 + T2: PGN 25 -> popup berangka -> LNG rekomendasi -> final */
  { const p = await sesi(browser, PGN); await pilih(p, 'recommendation'); await klikRun(p);
    let tModal = null, tProg = null;
    for (let i = 0; i < 400 && (tModal === null || tProg === null); i++) {
      const m = await p.evaluate(() => ({ m: !!document.getElementById('ppm-mask'), n: document.querySelectorAll('#ppm-steps li').length }));
      if (m.m && tModal === null) tModal = Date.now() - p._t0; if (m.n > 0 && tProg === null) tProg = Date.now() - p._t0; await sleep(20); }
    const s0 = await tunggu(p, s => s.pop); const tPop = Date.now() - p._t0;
    const s1 = await tunggu(p, s => !s.pop || (s.lng && s.dist)); const tVal = Date.now() - p._t0;
    const tidakTersedia = /tidak tersedia|tidak dijalankan/i.test(s0.num);
    REQ = await p.evaluate(() => GSF_VO && GSF_VO.lng ? +GSF_VO.lng.validated_amount_bbtud : null);
    DREQ = await p.evaluate(() => GSF_VO && GSF_VO.distillate ? +GSF_VO.distillate.validated_amount_liter : null);
    rec('T1', 'PGN '+PGN+': popup Gas Shortage menampilkan angka LNG dan Distillate, tombol aktif setelah validasi',
      s0.pop && !tidakTersedia && s1.lng === true && s1.dist === true && REQ > 0 && DREQ > 0,
      `popup ${tPop} ms (awal: "${s0.num.split('\n')[0].slice(0, 60)}"), angka ${tVal} ms: LNG ${REQ} BBTUD, Distillate ${DREQ} l`);
    RT.pgn25 = { modal: tModal, firstProgress: tProg, popup: tPop, validated: tVal };
    const t1 = Date.now(); await p.locator('#gsf-lng').click();
    const s2 = await tunggu(p, s => !s.pop && s.rows === 48 && s.gate === false); const tF = Date.now();
    RT.pgn25.optionToFinal = tF - t1; RT.pgn25.total = tF - p._t0; lags.push(lagSetelahDone(p, tF)); sisaPoll.push(await pollSesudah(p, tF));
    rec('T2', 'PGN '+PGN+' -> LNG rekomendasi -> rerun final otomatis, hasil final valid',
      finalOk(s2) && s2.src === 'add_lng' && Math.abs(Number(s2.added) - REQ) < 1e-6 && s2.fsum, `${tF - t1} ms; ` + kual(s2));
    await p.context().close(); }
  /* T3: Distillate rekomendasi */
  { const p = await sesi(browser, PGN); await pilih(p, 'recommendation'); await klikRun(p);
    await tunggu(p, s => s.pop); const s1 = await tunggu(p, s => !s.pop || s.dist);
    const t1 = Date.now(); if (s1.dist) await p.locator('#gsf-dist').click();
    const s2 = await tunggu(p, s => !s.pop && s.rows === 48 && s.gate === false); const tF = Date.now(); lags.push(lagSetelahDone(p, tF)); sisaPoll.push(await pollSesudah(p, tF));
    rec('T3', 'PGN '+PGN+' -> Distillate rekomendasi -> rerun final otomatis, limit dihormati',
      s1.dist && finalOk(s2) && s2.src === 'use_distillate' && Number(s2.used) > 0 && Number(s2.used) <= DREQ + 1e-6,
      `${tF - t1} ms; ` + kual(s2));
    await p.context().close(); }
  /* T4: LNG manual kurang -> campuran */
  { const p = await sesi(browser, PGN); await pilih(p, 'add_lng');
    const ketik = Math.round(REQ * 0.5 * 10000) / 10000; await p.fill('#f-additional_lng', String(ketik)); await klikRun(p);
    const s2 = await tunggu(p, s => s.rows === 48 && s.gate === false); const tF = Date.now(); lags.push(lagSetelahDone(p, tF)); sisaPoll.push(await pollSesudah(p, tF));
    rec('T4', 'PGN '+PGN+' -> LNG manual kurang -> sisa ditutup Distillate otomatis',
      finalOk(s2) && s2.src === 'mixed_lng_distillate' && Math.abs(Number(s2.added) - ketik) < 1e-6 && Number(s2.used) > 0 && !s2.pop,
      `LNG ${ketik}; ${tF - p._t0} ms; ` + kual(s2));
    await p.context().close(); }
  /* T5: LNG manual lebih -> kuota LNG user */
  { const p = await sesi(browser, PGN); await pilih(p, 'add_lng');
    const ketik = Math.round((REQ + 2) * 10000) / 10000; await p.fill('#f-additional_lng', String(ketik)); await klikRun(p);
    const s2 = await tunggu(p, s => s.rows === 48 && s.gate === false); const tF = Date.now(); lags.push(lagSetelahDone(p, tF)); sisaPoll.push(await pollSesudah(p, tF));
    rec('T5', 'PGN '+PGN+' -> LNG manual lebih -> memakai kuota LNG user, tanpa Distillate',
      finalOk(s2) && s2.src === 'add_lng' && Math.abs(Number(s2.added) - ketik) < 1e-6 && Number(s2.used) === 0 && !s2.pop,
      `LNG ${ketik}; ${tF - p._t0} ms; ` + kual(s2));
    await p.context().close(); }
  /* T6: PGN 30 tanpa popup */
  { const p = await sesi(browser, 30); await pilih(p, 'recommendation'); const t0 = Date.now(); await klikRun(p);
    let tModal = null, tProg = null;
    for (let i = 0; i < 400 && (tModal === null || tProg === null); i++) {
      const m = await p.evaluate(() => ({ m: !!document.getElementById('ppm-mask'), n: document.querySelectorAll('#ppm-steps li').length }));
      if (m.m && tModal === null) tModal = Date.now() - t0; if (m.n > 0 && tProg === null) tProg = Date.now() - t0; await sleep(20); }
    let sawPop = false; const s2 = await tunggu(p, s => { if (s.pop) sawPop = true; return s.rows === 48 && s.gate === false; });
    const tF = Date.now(); lags.push(lagSetelahDone(p, tF)); sisaPoll.push(await pollSesudah(p, tF));
    RT.pgn30 = { modal: tModal, firstProgress: tProg, total: tF - t0 };
    rec('T6', 'PGN 30 selesai tanpa popup bila tidak ada shortage, hasil final valid',
      !sawPop && finalOk(s2) && tModal <= 1000 && tProg <= 2000, `modal ${tModal} ms, progres ${tProg} ms, total ${tF - t0} ms; ` + kual(s2));
    await p.context().close(); }
  /* T9: Cancel pada popup */
  { const p = await sesi(browser, PGN); await pilih(p, 'recommendation'); await klikRun(p);
    const s0 = await tunggu(p, s => s.pop); const t1 = Date.now();
    await p.locator('#gsf-cancel').click(); await sleep(1500);
    const s1 = await keadaan(p);
    rec('T9', 'PGN '+PGN+' -> Cancel: popup tertutup, hasil pendahuluan tidak diterbitkan, Export/Publish tetap terkunci, Save Input tetap aktif (V3)',
      s0.pop && !s1.pop && /Dibatalkan/.test(s1.msg) && s1.save === true && s1.xls !== true && s1.src !== 'recommendation',
      `popup ${t1 - p._t0} ms; sesudah Batal: popup=${s1.pop}, gate=${s1.gate}, saveInputAktif=${s1.save}, exportAktif=${s1.xls}, msg="${s1.msg.slice(0, 70)}"`);
    await p.context().close(); }
  /* T7: 100% langsung DONE dan tampil */
  const lagMax = Math.max(...lags.filter(x => x !== null));
  rec('T7', 'Progress 100% langsung selesai: hasil tampil <= 2 s setelah job DONE, polling berhenti',
    lags.every(x => x !== null) && lagMax <= 2000 && sisaPoll.every(x => x === 0),
    'jeda DONE->tampil per skenario (ms): ' + JSON.stringify(lags) + '; polling sesudah hasil tampil: ' + JSON.stringify(sisaPoll));
  /* T8: tidak ada CMD, worker error, atau hasil menggantung */
  const jobs = []; for (const d of fs.readdirSync(JOBS)) { const f = path.join(JOBS, d, 'job.json'); if (!fs.existsSync(f)) continue;
    try { const j = JSON.parse(fs.readFileSync(f, 'utf8')); jobs.push({ id: d, st: j.status, rec: !!j.reclaimed_from, err: j.error && j.error.code, step: j.current_step }); } catch (e) {} }
  const buruk = jobs.filter(j => j.st === 'FAILED' || j.rec);
  const menggantung = jobs.filter(j => ['CLAIMED', 'RUNNING'].includes(j.st));
  const cancelSah = jobs.filter(j => j.st === 'CANCELLED').every(j => j.step === 'DIHITUNG_OLEH_JOB_INDUK' || j.step === 'DILEPAS_KARENA_JALUR_SINKRON_SELESAI_LENGKAP');
  let scan = [];
  for (const f of ['run.php', 'worker02.php', 'worker_functions.php', 'index.php', 'saved_data_store.php'].filter(x => fs.existsSync(path.join(path.dirname(JOBS), x)))) {
    let src = fs.readFileSync(path.join(path.dirname(JOBS), f), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    if (f !== 'index.php') { const m = src.match(/(?<![\w>$:])(shell_exec|exec|proc_open|popen|system|passthru|posix_kill|pcntl_exec|pcntl_fork)\s*\(/gi); if (m) scan.push(f + ':' + m.join(',')); }
    const m2 = src.replace(/\/\/[^\n]*/g, '').match(/cmd\.exe|powershell|start\s+\/B|tasklist|php\.exe/gi); if (m2) scan.push(f + ':' + m2.join(','));
  }
  rec('T8', 'Tidak ada CMD/proses OS, tidak ada job gagal/menggantung, tidak ada JavaScript error',
    buruk.length === 0 && menggantung.length === 0 && cancelSah && scan.length === 0 && pageErrs.length === 0,
    `jobs=${jobs.length} (${[...new Set(jobs.map(j => j.st))].join('/')}), gagal=${buruk.length}, menggantung=${menggantung.length}, scan=${scan.length ? scan.join(';') : '0 temuan'}, jsErr=${pageErrs.length}`);
  await browser.close();
  const np = R.filter(r => r[2]).length;
  let md = '# TARGETED TEST GAS SHORTAGE — PGN ' + PGN + ' / PGN 30\n\nHasil: **' + np + '/' + R.length + '**\n\n| id | pemeriksaan | hasil | rincian |\n|---|---|---|---|\n';
  for (const r of R) md += `| ${r[0]} | ${r[1]} | ${r[2] ? 'LULUS' : 'GAGAL'} | ${String(r[3]).replace(/\|/g, '/')} |\n`;
  md += '\n## Runtime (ms)\n\n```json\n' + JSON.stringify(RT, null, 1) + '\n```\n';
  fs.writeFileSync(OUT, md); console.log(`\n${np}/${R.length} PASS`); console.log('RUNTIME ' + JSON.stringify(RT));
})().catch(e => { console.error('ERR', e); process.exit(2); });
