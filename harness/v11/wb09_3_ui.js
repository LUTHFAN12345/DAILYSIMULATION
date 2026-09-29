/* V11 UI: reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3: PGN 32, PEP 34 MMSCFD, PEP KP72 2,2) lewat UI nyata.
 * Input halaman = v8/reproducer/input_WB09_3.json. Run (Maximum Review) -> FINAL; periksa waktu klik->FINAL,
 * hard constraints, Export 48/48, residual 0, audit V11, SUMMARY kuning lima field + invarian counter, lalu Run ulang
 * identik (cache / determinisme). node wb09_3_ui.js <base-url> <out.md> <out.jsonl> [batas_ms=15500] */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, OUT, JL, LIM] = process.argv; const LIMIT = +(LIM || 15500); const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1600, height: 1000 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  await p.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' }); await sleep(1500);
  await p.evaluate(() => { const s = document.getElementById('f-tl-target'); if (s) { s.value = 'max'; s.dispatchEvent(new Event('change', { bubbles: true })); } });
  const st = () => p.evaluate(() => { const o = (typeof OUTPUT !== 'undefined' && OUTPUT) ? OUTPUT : null; const i = (o && o.info) || {}; const rg = (o && o.release_gate) || {};
    const bn = document.getElementById('tl-banner'); const au = document.getElementById('tl-audit');
    return { msg: (document.getElementById('run-msg') || {}).innerText || '', rows: o ? (o.data || []).length : 0, pop: !!document.getElementById('gsf-mask'),
      sig: o ? JSON.stringify((o.data || []).map(r => [r.G1, r.G2, r.G5, r.G8, r.G9, r.S2, r.S3, r.GE1])) : '', cp: i['Cost Production (USD/MWh)'] ?? null, hr: i['JBBK MM Heat Rate (BTU/kWh)'] ?? null,
      rel: rg.release_allowed ?? null, hard: rg.hard_validation ?? null, exp: i['PLN Export Compliance'] ?? null, resid: i['Residual Gas Shortage (BBTUD)'] ?? null,
      v11: ['V11 Candidate Counters', 'V11 Candidate Comparison', 'V11 Consolidation Sweep', 'V11 Low Load Fragmentation Audit', 'V11 CP Audit'].filter(k => i[k] && typeof i[k] === 'object').length,
      frag: (i['V11 Low Load Fragmentation Audit'] || {}).status || null, cpa: (i['V11 CP Audit'] || {}).status || null, prov: (i['Fuel Provenance'] || {}).status || null,
      hpa: (i['Headroom Priority Audit'] || {}).status || null, cnt: i['V11 Candidate Counters'] || null,
      cells: bn ? bn.querySelectorAll('.v11-sum-c').length : 0, labels: bn ? [...bn.querySelectorAll('.v11-sum-l')].map(x => x.textContent).join('|') : '', ds: bn ? Object.assign({}, bn.dataset) : {},
      bannerText: bn ? bn.innerText : '', audit: au ? au.textContent : '' }; });
  const waitEnd = async (t0, sig0) => { let s; while (Date.now() - t0 < 600000) { s = await st();
      if (/FINAL OPTIMAL/.test(s.msg) && s.sig !== sig0 && s.rows === 48) return { kind: 'FINAL', t: Date.now() - t0 };
      if (s.pop) return { kind: 'GAS_SHORTAGE_POPUP', t: Date.now() - t0 };
      if (/BELUM final|tidak lolos|NO VALID|gagal|Perlu keputusan/i.test(s.msg) && Date.now() - t0 > 1500) return { kind: 'NOT_FINAL', t: Date.now() - t0 };
      await sleep(100); } return { kind: 'TIMEOUT', t: null }; };
  const run = () => p.evaluate(() => document.getElementById('btn-run').click());
  const T = []; const R = [];
  const pass = (id, ok, why) => { T.push({ id, ok: !!ok, why }); console.log((ok ? 'PASS' : 'FAIL') + '  ' + id + '  -- ' + why); };
  let t0 = Date.now(); await run(); await sleep(300); let w = await waitEnd(t0, '#'); await sleep(300); const s1 = await st(); R.push({ id: 'WB09_3', kind: w.kind, t: w.t, cp: s1.cp, hr: s1.hr, cnt: s1.cnt, ds: s1.ds });
  pass('W1 reproducer WB09_3 FINAL 48 row, hard PASS, Export 48/48, residual 0, rilis', w.kind === 'FINAL' && s1.rows === 48 && s1.hard === 'PASS' && s1.exp === 'OK' && +s1.resid === 0 && s1.rel === true,
    `${w.kind} ${w.t} ms rows=${s1.rows} hard=${s1.hard} exp=${s1.exp} cp=${s1.cp} hr=${s1.hr}`);
  /* runtime = metrik (seperti battery HTTP V10), dinilai terhadap target di PERFORMA_V11.md, bukan PASS/FAIL fungsional */
  console.log(`METRIK  W2 waktu klik Run -> FINAL ${w.t} ms (target <= ${LIMIT} ms: ${w.t !== null && w.t <= LIMIT ? 'TERCAPAI' : 'BELUM'})`); R.push({ id: 'METRIK_W2', t_ms: w.t, target_ms: LIMIT });
  pass('W3 audit V11 lengkap (5), fragmentation PASS/PASS_WITH_REASON, CP audit PASS, provenance fuel PASS, Unit Priority PASS/PASS_WITH_REASON',
    s1.v11 === 5 && /^PASS/.test(s1.frag || '') && s1.cpa === 'PASS' && /^PASS/.test(s1.prov || '') && /^PASS/.test(s1.hpa || ''), `v11=${s1.v11} frag=${s1.frag} cp=${s1.cpa} prov=${s1.prov} hpa=${s1.hpa}`);
  const d = s1.ds || {}; const c = s1.cnt || {};
  pass('W4 SUMMARY kuning tepat lima field; counter = info V11 Candidate Counters; Valid >= 1 (CP tersedia); Valid <= Diperiksa; best termasuk valid; teknis hanya di Detail audit',
    s1.cells === 5 && s1.labels === 'Target waktu|Waktu aktual|Kandidat diperiksa|Kandidat valid|Cost Production' && +d.checked === +c.candidates_checked && +d.valid === +c.candidates_valid
    && +d.valid >= 1 && +d.valid <= +d.checked && c.best_candidate_in_valid === true && Math.abs(+d.cp - +s1.cp) < 1e-4 && !/Global optimum|Total Cost|Status constraints|Export/.test(s1.bannerText) && /Global optimum proven: (YES|NO)/.test(s1.audit),
    `labels=${s1.labels} ds=${JSON.stringify(d)} cnt=${JSON.stringify({ ch: c.candidates_checked, va: c.candidates_valid, best: c.best_candidate_in_valid })}`);
  const sigA = s1.sig;
  /* ubah lalu kembali: PEP KP72 0 -> 2,2 -> hasil identik dengan run pertama (determinisme, jalur UI) */
  const setQ = (k, v) => p.evaluate(([k, v]) => { const el = document.getElementById('q-' + k); if (!el) return false; el.value = String(v); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; }, [k, v]);
  await setQ('pep_kp72', 0); t0 = Date.now(); await run(); await sleep(300); w = await waitEnd(t0, sigA); const s2 = await st(); R.push({ id: 'WB09_3_KP72_0', kind: w.kind, t: w.t, cp: s2.cp });
  await setQ('pep_kp72', 2.2); t0 = Date.now(); await run(); await sleep(300); w = await waitEnd(t0, s2.sig); const s3 = await st(); R.push({ id: 'WB09_3_ULANG', kind: w.kind, t: w.t, cp: s3.cp });
  pass('W5 rantai UI (KP72 0 lalu kembali 2,2): FINAL identik dengan run pertama (path-independent, deterministik)', w.kind === 'FINAL' && s3.sig === sigA && s3.cp === s1.cp && s3.hr === s1.hr,
    `${w.kind} ${w.t} ms cp ${s3.cp} hr ${s3.hr} identik=${s3.sig === sigA}; antara KP72 0: ${s2.cp}`);
  pass('W6 tidak ada JavaScript error', errs.length === 0, errs.slice(0, 3).join(' | '));
  fs.writeFileSync(JL, R.map(x => JSON.stringify(x)).join('\n') + '\n');
  let md = '# UI reproducer WB09_3 (Daily_Plan_09_Jul_26_Baru(3))\n\n| id | hasil | rincian |\n|---|---|---|\n'; let np = 0;
  for (const r of T) { md += `| ${r.id} | ${r.ok ? 'LULUS' : 'GAGAL'} | ${String(r.why).replace(/\|/g, '/')} |\n`; if (r.ok) np++; }
  fs.writeFileSync(OUT, md + `\nHasil: **${np}/${T.length}**\n`); console.log(np + '/' + T.length + ' PASS'); await b.close();
})().catch(e => { console.error('ERR', e); process.exit(2); });
