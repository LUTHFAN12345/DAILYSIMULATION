/* INTEGRASI: uji UI Fastest - Default. node fastest_ui.js <base> <out.md> <label> [maxWaitS]
 * Halaman memuat input_data.json di akar server (diatur pemanggil: Change Over OFF atau ON). */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, OUT, LABEL, MW, CO] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' }); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} });
  await p.reload({ waitUntil: 'domcontentloaded' }); await sleep(1500);
  const R = [];
  const dd = await p.evaluate(() => { const s = document.getElementById('f-tl-target'); const o = [...s.options];
    const st = x => { const c = getComputedStyle(x); return { bg: x.style.background || c.backgroundColor, fg: x.style.color }; };
    return { value: s.value, labels: o.map(x => x.textContent.trim()), values: o.map(x => x.value), first: st(o[0]), mid: st(o[1]), last: st(o[o.length - 1]) }; });
  R.push(['F1', 'Fastest - Default di atas < 15 detik, default terpilih, kuning; target biasa putih; Maximum Review merah terang',
    dd.value === 'fast' && dd.labels[0] === 'Fastest - Default' && dd.labels[1] === '< 15 detik' && dd.labels[dd.labels.length - 1] === 'Maximum Review'
    && /ffd54f|255, 213, 79/i.test(dd.first.bg) && /#fff\b|255, 255, 255/i.test(dd.mid.bg) && /ff2d2d|255, 45, 45/i.test(dd.last.bg), JSON.stringify(dd)]);
  if (CO === 'on') { await p.evaluate(() => { const y = document.getElementById('co-yes'); if (y) y.click(); }); await sleep(500);
    R.push(['F0', 'Change Over ON lewat UI (Block 2 Running -> Block 1, sim/sim)', await p.evaluate(() => { const c = buildChangeOverObj(); return !!c.enabled; }), await p.evaluate(() => JSON.stringify(buildChangeOverObj()))]); }
  if (process.env.TARGET) await p.evaluate(v => { const s = document.getElementById('f-tl-target'); s.value = v; s.dispatchEvent(new Event('change', { bubbles: true })); }, process.env.TARGET);
  const t0 = Date.now(); let modal = false, blue = false, blueTxt = '';
  await p.click('#btn-run');
  let done = false; const maxW = (+MW || 600) * 1000;
  while (Date.now() - t0 < maxW) {
    await sleep(200);
    const s = await p.evaluate(() => { const m = document.getElementById('ppm-mask'); const vis = !!m && m.offsetParent !== null && getComputedStyle(m).display !== 'none';
      const rm = document.getElementById('run-msg'); const fm = document.getElementById('fast-msg'); const sp = fm && fm.textContent ? fm : (rm && rm.querySelector('span'));
      const gs = [...document.querySelectorAll('.sv-mask')].some(x => x.offsetParent !== null && getComputedStyle(x).display !== 'none' && x.id !== 'ppm-mask');
      return { vis, txt: (fm && fm.textContent ? fm.textContent + ' ' : '') + (rm ? rm.innerText : ''), color: sp ? sp.style.color : '', fs: sp ? sp.style.fontSize : '', btn: document.getElementById('btn-run').disabled, banner: !!document.getElementById('tl-banner'), gs }; });
    if (s.vis) modal = true;
    if (/Fastest - Default/.test(s.txt) && /1763d6|23, 99, 214/.test(s.color)) { blue = true; blueTxt = s.txt; }
    if (s.banner || /FINAL OPTIMAL|FASTEST VALID PLAN|NO VALID RESULT|Keputusan bahan bakar|Gas Shortage Decision/i.test(s.txt)) { done = true; break; }
  }
  const tDone = (Date.now() - t0) / 1000;
  const res = await p.evaluate(() => { const o = (typeof OUTPUT !== 'undefined' && OUTPUT) || {}; const i = o.info || {}; const bn = document.getElementById('tl-banner');
    const co = i['Change Over Timeline'] || null;
    return { rows: (o.data || []).length, label: o.result_label || '', status: i['Result Status'] || '', cp: i['Cost Production (USD/MWh)'], fast: i['V12 Fastest Check'] || null,
      banner: bn ? bn.dataset : null, msg: (document.getElementById('run-msg') || {}).innerText || '', co: co ? { executed: co.executed, mode: co.mode } : null,
      data: o.data || [], gate: (o.release_gate || {}).status || '' }; });
  R.push(['F2', 'tanpa popup progress besar selama run', !modal, 'modal_terlihat=' + modal]);
  R.push(['F3', 'progres berupa teks biru kecil "Fastest - Default"', blue, blueTxt.slice(0, 120)]);
  const fin = /FINAL OPTIMAL/.test(res.msg) || /FASTEST VALID PLAN/.test(res.msg);
  R.push(['F4', 'selesai dengan hasil fully valid 48 row (FASTEST VALID PLAN atau FINAL OPTIMAL)', done && fin && res.rows === 48,
    `t=${tDone.toFixed(2)} s rows=${res.rows} cp=${res.cp} msg=${res.msg.slice(0, 200)} fast=${JSON.stringify(res.fast)}`]);
  R.push(['F5', 'label jujur: FASTEST VALID PLAN menyatakan Global optimum proven NO; FINAL OPTIMAL hanya bila exact selesai',
    /FASTEST VALID PLAN/.test(res.msg) ? /Global optimum proven: NO/.test(res.status) : (/FINAL OPTIMAL/.test(res.msg) ? true : false), res.status.slice(0, 160)]);
  if (res.co) {
    const tg = Object.keys(res.data[0] || {}).filter(k => /^S[12]$/.test(k)); let ov = 0, run = 0;
    for (const r of res.data) { if ((+r.S1 || 0) > 0.01 && (+r.S2 || 0) > 0.01) { run++; ov = Math.max(ov, run); } else run = 0; }
    R.push(['F6', 'Change Over aktif: executed dan overlap S1/S2 >= 3 row (1,5 jam)', !!res.co.executed && ov >= 3, `executed=${res.co.executed} overlap=${ov} row`]);
  }
  R.push(['F7', 'tidak ada JavaScript error', errs.length === 0, errs.join(' | ').slice(0, 200)]);
  const pass = R.filter(x => x[2]).length;
  let md = `# Fastest - Default UI — ${LABEL}\n\n| id | uji | hasil | bukti |\n|---|---|---|---|\n`;
  for (const [id, n, ok, ev] of R) { md += `| ${id} | ${n} | ${ok ? 'LULUS' : 'GAGAL'} | ${String(ev).replace(/\|/g, '/').replace(/\n/g, ' ')} |\n`; console.log(`${ok ? 'PASS' : 'FAIL'}  ${id}  ${n}  -- ${String(ev).slice(0, 220)}`); }
  md += `\n${pass}/${R.length} LULUS; waktu selesai ${tDone.toFixed(2)} s.\n`; fs.writeFileSync(OUT, md); console.log(`${pass}/${R.length} PASS t=${tDone.toFixed(2)}`);
  await b.close();
})();
