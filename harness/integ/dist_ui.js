/* Distillate UI: warna gradasi + tooltip dari data numerik output. node dist_ui.js <base> <out.md>
 * Bagian A: hasil engine nyata (PGN 25 + PEP 30 + KP72 0, aksi Distillate) — hover sel, bandingkan tooltip dengan angka output.
 * Bagian B: uji render persen 100/75/50/40/25/0 (nilai disuntikkan ke OUTPUT di browser untuk menguji pemetaan warna & tooltip). */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 1000 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  const R = []; const t0 = Date.now(); await p.click('#btn-run');
  while (Date.now() - t0 < 300000) { await sleep(300); const ok = await p.evaluate(() => !!(typeof OUTPUT !== 'undefined' && OUTPUT && (OUTPUT.data || []).length === 48 && (OUTPUT.data || []).some(r => Object.keys(r).some(k => /^DistMix_/.test(k) && +r[k] > 0)) && document.querySelector('td[data-dist-pct]'))); if (ok) break; }
  await p.evaluate(() => { try { showSimulationDataResult(); } catch (e) {} }); await sleep(800);
  const A = await p.evaluate(() => { const cells = [...document.querySelectorAll('#cp-simdata td[data-dist-pct], td[data-dist-pct]')]; const units = {};
    for (const c of cells) units[c.dataset.distUnit] = (units[c.dataset.distUnit] || 0) + 1;
    const dist = {}; for (const r of OUTPUT.data) for (const k of Object.keys(r)) { const m = /^DistMix_(G\d+)$/.exec(k); if (m && +r[k] > 0) dist[m[1]] = (dist[m[1]] || 0) + 1; }
    return { cells: cells.length, units, engine_units: dist, perUnitL: (OUTPUT.info || {})['Distillate per unit (l)'] }; });
  R.push(['D1', 'alokasi Distillate dipusatkan: satu unit bila satu unit cukup (hasil engine nyata)', Object.keys(A.engine_units).length === 1, JSON.stringify(A)]);
  // hover dua sel berbeda persen
  const targets = await p.evaluate(() => { const out = []; const seen = {}; for (const c of document.querySelectorAll('td[data-dist-pct]')) { const k = c.dataset.distPct; if (seen[k] || c.offsetParent === null) continue; seen[k] = 1; const rr = c.getBoundingClientRect(); c.scrollIntoView({ block: 'center' }); out.push({ pct: k, unit: c.dataset.distUnit, time: c.dataset.distTime }); } return out; });
  for (const t of targets) {
    const box = await p.evaluate(t => { const c = [...document.querySelectorAll('td[data-dist-pct]')].find(x => x.dataset.distPct === t.pct && x.offsetParent !== null); c.scrollIntoView({ block: 'center' }); const r = c.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; }, t);
    await p.mouse.move(box.x, box.y); await sleep(250);
    const tipTxt = await p.evaluate(() => { const t = document.getElementById('dist-tip'); return t && t.style.display === 'block' ? t.textContent : null; });
    const exp = await p.evaluate(t => { const r = OUTPUT.data.find(x => x.Time === t.time); const k = t.unit; return { pct: r['DistPct_' + k], gas: r['GasPct_' + k], l: r['Dist_' + k], flow: r['GasFlow_' + k], bg: getComputedStyle([...document.querySelectorAll('td[data-dist-pct]')].find(x => x.dataset.distPct === t.pct && x.offsetParent !== null)).backgroundColor }; }, t);
    const ok = !!tipTxt && tipTxt.includes('Distillate : ' + Math.round(exp.pct) + '%') && tipTxt.includes('Gas        : ' + Math.round(exp.gas) + '%') && tipTxt.includes('Unit       : ' + t.unit) && /Time       : /.test(tipTxt) && tipTxt.includes('Gas flow   :');
    R.push(['D2-' + t.pct, `hover sel ${t.unit} ${t.time} (${t.pct}% Distillate): tooltip = angka output (Distillate %, Gas %, liter, gas flow, unit, waktu)`, ok, JSON.stringify({ tip: tipTxt, output: exp }).replace(/\\n/g, ' / ')]);
  }
  // Bagian B: pemetaan warna & tooltip untuk 100/75/50/40/25/0
  const B = await p.evaluate(() => { const levels = [100, 75, 50, 40, 25, 0]; const o = JSON.parse(JSON.stringify(OUTPUT)); const k = 'G1';
    levels.forEach((pc, i) => { const r = o.data[i]; r.DistMix_G1 = pc / 100; r.DistPct_G1 = pc; r.GasPct_G1 = 100 - pc; r.Dist_G1 = Math.round(4153.9 * pc) / 100; r.GasFlow_G1 = +(6.65 * (100 - pc) / 100).toFixed(4); if (pc === 0) { delete r.DistMix_G1; delete r.DistPct_G1; delete r.GasPct_G1; delete r.Dist_G1; } });
    OUTPUT = o; renderResult(o); showSimulationDataResult();
    const res = []; for (let i = 0; i < levels.length; i++) { const t = o.data[i].Time; const td = [...document.querySelectorAll('td[data-dist-unit="G1"]')].find(x => x.dataset.distTime === t && x.offsetParent !== null);
      const plain = td ? null : [...document.querySelectorAll('#cp-simdata td, td')].length;
      res.push({ pct: levels[i], has_cell: !!td, bg: td ? getComputedStyle(td).backgroundColor : null, txt: td ? distTipText(td) : null }); }
    return res; });
  const lum = c => { const m = /rgb\((\d+), (\d+), (\d+)\)/.exec(c || ''); return m ? 0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3] : null; };
  let mono = true; for (let i = 1; i < B.length; i++) if (B[i].has_cell && B[i - 1].has_cell && !(lum(B[i].bg) > lum(B[i - 1].bg))) mono = false;
  for (const x of B) { const g = 100 - x.pct;
    const ok = x.pct === 0 ? !x.has_cell : (x.has_cell && x.txt.includes('Distillate : ' + x.pct + '%') && x.txt.includes('Gas        : ' + g + '%'));
    R.push(['B-' + x.pct + '/' + (100 - x.pct), `render ${x.pct}% Distillate / ${100 - x.pct}% Gas: ${x.pct === 0 ? 'tanpa biru' : 'biru proporsional + tooltip'}`, ok, JSON.stringify({ bg: x.bg, luminance: lum(x.bg), tip: x.txt }).replace(/\\n/g, ' / ')]); }
  R.push(['B-mono', 'gradasi: makin tinggi persen Distillate makin gelap (100% paling tua)', mono, JSON.stringify(B.map(x => [x.pct, lum(x.bg)]))]);
  R.push(['D9', 'tidak ada JavaScript error', errs.length === 0, errs.join(' | ').slice(0, 200)]);
  const pass = R.filter(x => x[2]).length; let md = `# Distillate per sel — warna & tooltip\n\n| id | uji | hasil | bukti |\n|---|---|---|---|\n`;
  for (const [id, n, ok, ev] of R) { md += `| ${id} | ${n} | ${ok ? 'LULUS' : 'GAGAL'} | ${String(ev).replace(/\|/g, '/')} |\n`; console.log(`${ok ? 'PASS' : 'FAIL'}  ${id}  ${n}  -- ${String(ev).slice(0, 260)}`); }
  md += `\n${pass}/${R.length} LULUS\n`; fs.writeFileSync(OUT, md); console.log(`${pass}/${R.length} PASS`); await b.close();
})();
