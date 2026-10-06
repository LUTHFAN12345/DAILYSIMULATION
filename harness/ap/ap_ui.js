/* AUTO PGN / SR / PV UI test (targeted A-F). node ap_ui.js <base> <label>
 * env: CSV=<path> | XLSX=<path>  SR_MODE=fixed|follow_pv  SR_FIX=<MW>  EDITPV=<row>:<val>  EXPECT=auto|infeasible|none
 *      EXPECT_PERIOD=00:30|00:30-02:00  TARGET=fast|max  SAVE=input|report|none  CHART=1  EXPORT=1  T6=1 */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, LABEL] = process.argv; const E = process.env; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const reqs = []; let t0 = 0; p.on('request', r => { const u = r.url(); const m = (u.match(/mode=([a-z_]+)/) || [])[1]; const j = (u.match(/[?&]job=([A-Za-z0-9_\-]+)/) || [])[1]; if (m) reqs.push({ t: t0 ? (Date.now() - t0) / 1000 : -1, m, j: j || null }); });
  const R = { case: LABEL, checks: {} }; const ck = (k, ok, ev) => { R.checks[k] = { pass: !!ok, evidence: ev }; };
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  if (E.TARGET) await p.evaluate(t => { const s = document.getElementById('f-tl-target'); s.value = t; s.dispatchEvent(new Event('change', { bubbles: true })); }, E.TARGET);
  const mff0 = await p.evaluate(() => JSON.stringify(INPUT.data3.modeling.manual_fixed_flows || []));
  /* ---- format lokal: pemisah ';' + desimal koma (preview lalu Cancel; tidak mengubah input) ---- */
  if (E.LOCALE_CSV) { await p.setInputFiles('#csv-file', E.LOCALE_CSV); await sleep(900);
    const lc = await p.evaluate(() => ({ txt: (document.getElementById('csv-preview') || {}).innerText || '', rows: (CSV_PENDING && CSV_PENDING.rows) ? CSV_PENDING.rows.map(r => [r.ie, r.disp, r.pv]) : null }));
    const ref = fs.readFileSync(E.CSV_REF, 'utf8').split(/\r?\n/).filter(l => l.trim()).slice(1).map(l => l.split(','));
    const okL = lc.rows && lc.rows.length === 48 && lc.rows.every((r, i) => Math.abs(r[0] - +ref[i][1]) < 1e-9 && Math.abs(r[1] - +ref[i][2]) < 1e-9 && Math.abs(r[2] - +ref[i][3]) < 1e-9);
    ck('E_import_locale_semicolon_decimal_comma', okL && /Rows detected: 48/.test(lc.txt) && /PV min\/max: 0\.22 \/ 24\.15 MW/.test(lc.txt), { preview: lc.txt.split('\n').slice(0, 7), first: lc.rows && lc.rows[0], row22: lc.rows && lc.rows[21] });
    await p.click('#csv-clear'); await sleep(300); }
  /* ---- import (CSV atau Excel; mapping sama) ---- */
  const IMP = E.CSV || E.XLSX;
  if (IMP) {
    await p.setInputFiles('#csv-file', IMP); await sleep(1200);
    const prev = await p.evaluate(() => ({ txt: (document.getElementById('csv-preview') || {}).innerText || '', st: (document.getElementById('csv-status') || {}).innerText || '',
      applyTxt: document.getElementById('csv-apply').innerText, cancelTxt: document.getElementById('csv-clear').innerText }));
    R.import_preview = prev;
    await p.click('#csv-apply'); await sleep(500);
    const csvPath = E.CSV || E.CSV_REF; const lines = fs.readFileSync(csvPath, 'utf8').split(/\r?\n/).filter(l => l.trim()); const body = lines.slice(1).map(l => l.split(','));
    const st = await p.evaluate(() => ({ ie: INPUT.data1.map(x => +x.value), disp: INPUT.data2.map(x => +x.dispatch), time: INPUT.data1.map(x => x.time), pv: SR_PV.slice(), mode: SR_MODE }));
    const okIE = body.every((c, i) => Math.abs(st.ie[i] - +c[1]) < 1e-9), okD = body.every((c, i) => Math.abs(st.disp[i] - +c[2]) < 1e-9), okPV = body.every((c, i) => Math.abs(st.pv[i] - +c[3]) < 1e-9);
    ck('E_import_header_skipped_48_rows', body.length === 48 && /Rows detected: 48/.test(prev.txt), { source: E.XLSX ? 'xlsx' : 'csv', data_rows_in_file: body.length, preview: prev.txt.split('\n').slice(0, 8) });
    ck('E_import_A_C_to_IE_Dispatch', okIE && okD, { first: [st.time[0], st.ie[0], st.disp[0]], last: [st.time[47], st.ie[47], st.disp[47]] });
    ck('E_import_D_to_PV', okPV && /PV min\/max: 0\.22 \/ 24\.15 MW/.test(prev.txt), { pv_min: Math.min(...st.pv), pv_max: Math.max(...st.pv), buttons: [prev.applyTxt, prev.cancelTxt] });
    ck('E_import_keeps_sr_mode', st.mode === (E.MODE_BEFORE || 'fixed'), { mode_after_import: st.mode });
  }
  /* ---- SR mode ---- */
  const openSR = async () => { await p.evaluate(() => { const t = [...document.querySelectorAll('button,a')].find(x => /Frequently Input/i.test(x.textContent)); if (t) t.click(); }); await sleep(200);
    await p.evaluate(() => document.querySelector('[data-cp="cp-sr"]').click()); await sleep(500); };
  await openSR();
  if (E.SR_MODE) await p.evaluate(([md, fx]) => { const r = document.getElementById(md === 'follow_pv' ? 'sr-mode-pv' : 'sr-mode-fixed'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
    const f = document.getElementById('f-spinning_reserve_min'); f.value = fx; f.dispatchEvent(new Event('input', { bubbles: true })); f.dispatchEvent(new Event('change', { bubbles: true })); }, [E.SR_MODE, E.SR_FIX || '0']);
  await sleep(300);
  const chartState = () => p.evaluate(() => { const w = document.getElementById('sr-pv-chart-wrap'); const dots = [...document.querySelectorAll('#sr-pv-svg circle.srpv-dot')];
    return { visible: !!w && getComputedStyle(w).display !== 'none', dots: dots.length, cy: dots.map(d => +d.getAttribute('cy')), idx: dots.map(d => +d.dataset.i), stats: (document.getElementById('sr-pv-stats') || {}).textContent || '' }; });
  if (E.CHART) {
    const c1 = await chartState();
    await p.evaluate(() => { const w = document.getElementById('sr-pv-chart-wrap'); if (w) w.scrollIntoView({ block: 'center' }); }); await sleep(300);
    const box = await p.$('#sr-pv-chart'); const bb = box ? await box.boundingBox() : null; let tip = null;
    if (bb) { await p.mouse.move(bb.x + bb.width * 0.43, bb.y + 90); await sleep(200); tip = await p.evaluate(() => { const t = document.getElementById('sr-pv-tip'); return t && t.style.display !== 'none' ? t.textContent : null; }); }
    ck('D_chart_visible_48_points_tooltip', c1.visible && c1.dots === 48 && /PV minimum: 0\.22 MW/.test(c1.stats) && /PV maximum: 24\.15 MW/.test(c1.stats) && /Peak time: 11:00/.test(c1.stats) && /Time \d\d:\d\d \(row \d+\)PV [\d.]+ MW/.test(tip || ''),
      { dots: c1.dots, stats: c1.stats, tooltip: tip });
    R.chart_before = { cy19: c1.cy[c1.idx.indexOf(19)] };
  }
  if (E.EDITPV) { const [rw, vv] = E.EDITPV.split(':'); const c0 = E.CHART ? await chartState() : null;
    await p.evaluate(([i, v]) => { const el = document.querySelector('#tbl-srpv input.srpv[data-i="' + i + '"]'); el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); }, [String(+rw - 1), vv]); await sleep(300);
    if (E.CHART) { const c2 = await chartState(); const i = +rw - 1;
      ck('D_chart_updates_after_manual_edit', c2.dots === 48 && c2.cy[c2.idx.indexOf(i)] !== c0.cy[c0.idx.indexOf(i)] && /PV maximum: 25\.50 MW/.test(c2.stats) && /Peak time: 10:00/.test(c2.stats),
        { row: +rw, cy_before: c0.cy[c0.idx.indexOf(i)], cy_after: c2.cy[c2.idx.indexOf(i)], stats_after: c2.stats });
      /* negatif ditolak */
      await p.evaluate(() => { const el = document.querySelector('#tbl-srpv input.srpv[data-i="5"]'); el.value = '-3'; el.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(200);
      const neg = await p.evaluate(() => ({ pv5: SR_PV[5], sum: (document.getElementById('sr-sum') || {}).textContent || '' }));
      ck('D_negative_pv_rejected', neg.pv5 === 0.22 && /ditolak/.test(neg.sum), neg);
      /* Fix -> chart tersembunyi, data PV tetap; kembali Follow PV */
      await p.evaluate(() => { const r = document.getElementById('sr-mode-fixed'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(200);
      const cf = await chartState(); const pvKept = await p.evaluate(() => SR_PV.filter(x => x != null).length);
      await p.evaluate(() => { const r = document.getElementById('sr-mode-pv'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(300);
      const cb = await chartState();
      ck('D_chart_hidden_in_fix_mode_data_kept', !cf.visible && pvKept === 48 && cb.visible && cb.dots === 48, { fix_visible: cf.visible, pv_kept: pvKept, back_visible: cb.visible }); } }
  const ui = await p.evaluate(() => ({ mode: SR_MODE, fixedChecked: document.getElementById('sr-mode-fixed').checked, pvChecked: document.getElementById('sr-mode-pv').checked,
    eff: [...document.querySelectorAll('#tbl-srpv td[data-sreff]')].map(td => +td.textContent), pv: SR_PV.slice(), fx: +document.getElementById('f-spinning_reserve_min').value,
    rows: document.querySelectorAll('#tbl-srpv tr').length - 1, order: [...document.querySelectorAll('#cp-sr .fcard .ft')].map(x => x.textContent) }));
  const expEff = ui.pv.map(v => ui.mode === 'follow_pv' ? (v == null ? ui.fx : Math.max(ui.fx, v)) : ui.fx);
  ck('CD_sr_sections_order', ui.order[0] === 'BUS FLOW' && ui.order[1] === 'SPINNING RESERVE', ui.order);
  ck('CD_sr_modes_exclusive', (ui.fixedChecked !== ui.pvChecked) && ui.mode === (E.SR_MODE || ui.mode), { fixed: ui.fixedChecked, follow_pv: ui.pvChecked });
  ck('CD_sr_table_48_rows_formula', ui.rows === 48 && ui.eff.length === 48 && ui.eff.every((v, i) => Math.abs(v - expEff[i]) < 0.006), { night_row1: [ui.pv[0], ui.eff[0]], peak: [Math.max(...ui.pv.filter(x => x != null)), Math.max(...ui.eff)], fixed: ui.fx });
  R.sr_ui = { mode: ui.mode, fixed: ui.fx, eff: ui.eff, pv: ui.pv };
  /* ---- Run (tanpa popup; koreksi PGN otomatis bila terbukti) ---- */
  t0 = Date.now(); await p.click('#btn-run'); let sawPopup = false, sawAuto = null, sawAutoMsg = '', done = null, msg = '';
  while (Date.now() - t0 < 900000) { await sleep(150); const s = await p.evaluate(() => ({ pop: !!document.getElementById('pgnrec-mask') || /Apply Recommendation|Keep Current Input/.test(document.body.innerText),
      m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled, rows: document.querySelectorAll('#tbl-result tbody tr').length,
      note: (() => { const n = document.getElementById('pgn-auto-note'); return n && getComputedStyle(n).display !== 'none' ? n.innerText : ''; })() }));
    if (s.pop) sawPopup = true;
    if (sawAuto === null && /PGN minimum-flow correction applied automatically/.test(s.m + ' ' + s.note)) { sawAuto = (Date.now() - t0) / 1000; sawAutoMsg = s.m + ' ' + s.note; }
    msg = s.m;
    if (!s.d && /FASTEST VALID PLAN|FINAL OPTIMAL|VALID PROVISIONAL tetap ditampilkan|tidak lolos release gate|BELUM final|FEASIBLE|gagal|Error|NO VALID/.test(s.m) && (s.rows === 48 || /FEASIBLE/.test(s.m))) { done = (Date.now() - t0) / 1000; break; } }
  const runs = reqs.filter(x => x.m === 'run').length; const jobs = [...new Set(reqs.filter(x => x.j && /economic_review/.test(x.j)).map(x => x.j))];
  R.run = { t_done_s: done, t_auto_apply_s: sawAuto, msg: msg.slice(0, 320), runs_total: runs, jobs, popup_seen: sawPopup };
  ck('A_no_popup', !sawPopup, { popup_seen: sawPopup });
  const o = await p.evaluate(() => { const O = (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data && OUTPUT.data.length) ? OUTPUT : ((typeof PRELIM !== 'undefined' && PRELIM && PRELIM.data) ? PRELIM.data : {}); const D = O.data || []; const i = O.info || {}; const f = i['V12 Fastest Check'] || {}; const mA = i['V12 Dispatch Merit Audit'] || {};
    const first = u => { const k = D.findIndex(r => (+r[u] || 0) > 0.01); return k < 0 ? null : k + 1; };
    const th = [...document.querySelectorAll('#tbl-result thead th')].map(x => x.textContent.trim());
    const pvIdx = th.indexOf('PV (MW)'); const pvCol = pvIdx < 0 ? null : [...document.querySelectorAll('#tbl-result tbody tr')].map(tr => { const c = tr.children[pvIdx]; return c ? c.textContent.trim() : null; });
    return { rows: D.length, shown_rows: document.querySelectorAll('#tbl-result tbody tr').length, th, pv_col: pvCol, out_pv: D.map(r => r.PV === undefined ? 'n/a' : r.PV), sr_min: D.map(r => r.SR_Min), spin: D.map(r => r.Spin_Res), ffj: D.map(r => r.FixedFlow_J), pgn: D.map(r => r.Flow_PGN_RT),
      hard: (O.simulation_acceptance_review || {}).hard_validation ? O.simulation_acceptance_review.hard_validation.status : null, gate: O.release_gate || null, label: i['Result Status'] || null, tl: O.time_limited === true, result_label: O.result_label || null,
      merit: mA.status, c4: (mA.c4_cross_group_priority || {}).fail, c4_cf: (mA.c4_cross_group_priority || {}).counterfactual_infeasible, stg: f.stg_proof ? (f.stg_proof.equal_calc + f.stg_proof.startup_hold) + '/' + f.stg_proof.rows_x_stg + ' langgar ' + f.stg_proof.violations : null,
      prov: (i['Fuel Provenance'] || {}).status, fuel: i['Fuel Action Reconciliation'] || null, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], fastest: i['V12 Fastest Check'] || null,
      starts: { G4: first('G4'), G8: first('G8'), S1: first('S1'), G3: first('G3') }, mff: INPUT.data3.modeling.manual_fixed_flows, audit: INPUT.data3.modeling.pgn_fixed_flow_recommendation_applied || null,
      note: (document.getElementById('pgn-auto-note') || {}).innerText || '', minPgn: +INPUT.data3.modeling.min_pgn_flow,
      xls: (typeof resultToHTMLTable === 'function') ? resultToHTMLTable() : '' }; });
  R.result = Object.assign({}, o, { xls: undefined, xls_len: (o.xls || '').length });
  /* ---- grup A: koreksi otomatis ---- */
  if (E.EXPECT === 'auto') { const au = o.audit || {}; const rc = au.recipient_rows || [], sr = au.source_rows || []; const lastSrc = Math.max(...sr.map(x => x.row)); const adds = rc.map(x => +x.additional_mmscfd);
    const capped = rc.filter(x => x.capped).length;
    ck('A_auto_status_message', sawAuto !== null && /Fixed Flow JBBK was reduced during the constrained period and redistributed to later safe periods\. The daily total remains unchanged\./.test(sawAutoMsg + ' ' + o.note), { t_auto_s: sawAuto, note_first: o.note.split('\n')[0] });
    ck('A_constrained_period', (au.constrained_period || {}).label === (E.EXPECT_PERIOD || (au.constrained_period || {}).label) && sr.length >= 1, { period: au.constrained_period, source_rows: sr.map(x => [x.row, x.time, x.fixed_flow_before, x.fixed_flow_after]) });
    ck('A_recipients_only_after_period', rc.length > 1 && rc.every(x => x.row > lastSrc) && rc.length === 48 - lastSrc, { recipients: rc.length, first: rc[0] && rc[0].time, last: rc[rc.length - 1] && rc[rc.length - 1].time, period: au.recipient_period });
    ck('A_water_filling_equal', adds.length && (capped > 0 || Math.max(...adds) - Math.min(...adds) <= 0.0001 + 1e-9), { min_add: Math.min(...adds), max_add: Math.max(...adds), capped_rows: capped, unit: 0.0001 });
    const sumB = au.fixed_flow_before.reduce((a, b2) => a + b2, 0), sumA = o.ffj.reduce((a, b2) => a + b2, 0);
    ck('A_daily_total_identical', Math.abs(sumA - sumB) < 1e-6 && Math.abs(au.daily_total_difference_mmscf) <= au.tolerance_mmscf, { sum_before_mmscfd_rows: +sumB.toFixed(6), engine_sum_after: +sumA.toFixed(6), daily_before: au.daily_total_before, daily_after: au.daily_total_after, diff_mmscf: au.daily_total_difference_mmscf, tolerance: au.tolerance_mmscf });
    ck('A_engine_fixed_flow_equals_audit', o.ffj.every((v, k) => Math.abs(v - au.fixed_flow_after[k]) < 1e-4), { engine_rows_1_2_48: [o.ffj[0], o.ffj[1], o.ffj[47]], audit: [au.fixed_flow_after[0], au.fixed_flow_after[1], au.fixed_flow_after[47]] });
    ck('A_one_rerun_one_correction', runs === 2 && au.rerun_count === 1 && au.correction_mode === 'automatic', { runs_total: runs, jobs: jobs.length, rerun_count: au.rerun_count });
    ck('A_pgn_min_pass', o.pgn.length === 48 && o.pgn.every(v => v >= o.minPgn - 1e-3), { pgn_source_rows: sr.map(x => o.pgn[x.row - 1]), pgn_min: Math.min(...o.pgn), min: o.minPgn });
    ck('A_audit_fields', ['correction_mode', 'source_rows', 'recipient_rows', 'fixed_flow_before', 'fixed_flow_after', 'daily_total_before', 'daily_total_after', 'pgn_flow_before', 'pgn_flow_after', 'rerun_count', 'constraint_proof'].every(k => au[k] != null)
      && au.fixed_flow_before.length === 48 && au.fixed_flow_after.length === 48 && (au.pgn_flow_before || []).length === 48 && (au.pgn_flow_after || []).length === 48, { keys: Object.keys(au) });
  }
  /* ---- grup B: koreksi tidak feasible ---- */
  if (E.EXPECT === 'infeasible') { const n0 = reqs.length; await sleep(6000); const after = reqs.slice(n0).filter(x => ['job_poll', 'fast_ready', 'run', 'job_exec'].includes(x.m)).length;
    const mff1 = await p.evaluate(() => JSON.stringify(INPUT.data3.modeling.manual_fixed_flows || []));
    ck('B_terminal_code', /PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE/.test(msg) && /Constrained period: 00:30–02:00/.test(msg) && /volume to move/.test(msg) && /safe recipient capacity/.test(msg) && /unallocated/.test(msg) && /limiting constraint/.test(msg), { msg: msg.slice(0, 600), t_s: done });
    ck('B_no_partial_apply', mff1 === mff0 && !o.audit, { manual_fixed_flows_unchanged: mff1 === mff0, audit_written: !!o.audit });
    ck('B_no_repeated_polling', after === 0 && runs === 1, { requests_after_6s: after, runs_total: runs }); }
  /* ---- grup C/D: kolom PV di Simulation Data ---- */
  if (E.EXPECT !== 'infeasible' && o.rows === 48) {
    const iB = o.th.indexOf('BUSFLOW (MW)'), iP = o.th.indexOf('PV (MW)'), iS = o.th.indexOf('SPINNING RESERVE (MW)');
    if (ui.mode === 'follow_pv') {
      const pvOK = o.pv_col && o.pv_col.every((t, k) => Math.abs(+t - R.sr_ui.pv[k]) < 0.006);
      ck('D_pv_column_position_and_values', iB >= 0 && iP === iB + 1 && iS === iP + 1 && pvOK && o.sr_min.every((v, k) => Math.abs(v - Math.max(R.sr_ui.fixed, R.sr_ui.pv[k])) < 0.006),
        { order: o.th.slice(iB, iB + 4), pv_row1: o.pv_col && o.pv_col[0], pv_row22: o.pv_col && o.pv_col[21], sr_min_row1: o.sr_min[0], sr_min_row22: o.sr_min[21] });
    } else ck('C_pv_column_absent_fixed_mode', iP < 0 && iB >= 0 && iS === iB + 1 && o.sr_min.every(v => Math.abs(v - R.sr_ui.fixed) < 0.006), { pv_col: iP, order: o.th.slice(iB, iB + 3), sr_min_all: [...new Set(o.sr_min)] });
    ck('CD_sr_array_used_by_engine_and_met', o.sr_min.every((v, k) => Math.abs(v - R.sr_ui.eff[k]) < 0.006) && o.spin.every((v, k) => v >= o.sr_min[k] - 1e-6), { sr_min_first_last_peak: [o.sr_min[0], o.sr_min[47], Math.max(...o.sr_min)], spin_min_margin: +Math.min(...o.spin.map((v, k) => v - o.sr_min[k])).toFixed(3) });
    if (E.EXPORT) { const x = o.xls; const hasPV = /<th>PV \(MW\)<\/th>/.test(x); const orderOK = x.indexOf('<th>BUSFLOW (MW)</th>') < x.indexOf('<th>PV (MW)</th>') && x.indexOf('<th>PV (MW)</th>') < x.indexOf('<th>SPINNING RESERVE (MW)</th>');
      ck('E_export_consistent_with_simulation_data', ui.mode === 'follow_pv' ? (hasPV && orderOK) : !hasPV, { has_pv: hasPV, order_ok: orderOK }); }
    /* grup F: hasil fully valid (merit) + waktu */
    ck('F_result_fully_valid', o.hard === 'PASS' && o.merit === 'PASS' && !(o.c4 > 0) && (o.prov || '').startsWith('PASS') && (!o.fuel || o.fuel.pass) && o.rows === 48 && ((o.gate || {}).status === 'PASS' || (o.tl && o.result_label === 'FASTEST VALID PLAN')),
      { hard: o.hard, merit: o.merit, c4_fail: o.c4, c4_pass_with_reason: o.c4_cf, stg: o.stg, prov: o.prov, fuel: o.fuel && o.fuel.code, label: o.result_label || o.label, gate: o.gate && (o.gate.status || o.gate.blocking_reasons), cp: o.cp });
    if (E.T6) { ck('F_t6_click_to_48_rows_under_60s', done !== null && done < 60 && o.shown_rows === 48, { t_click_to_48_rows_s: done, t_auto_apply_s: sawAuto, runs: runs, jobs: jobs.length, fastest: o.fastest && { finalize_s: o.fastest.finalize_s, merit: o.fastest.merit_audit } });
      ck('F_required_startups_kept', o.starts.G4 && o.starts.G8 && o.starts.S1, { first_load_row: o.starts }); }
    if (E.TARGET !== 'max') ck('F_fastest_stops_at_first_fully_valid', o.tl && o.result_label === 'FASTEST VALID PLAN' && jobs.length <= 2, { label: o.result_label, jobs: jobs.length });
  }
  /* ---- Save / Reload / Report ---- */
  if (E.SAVE && E.SAVE !== 'none') {
    if (E.SAVE === 'report') { await p.evaluate(() => document.getElementById('btn-save-actual').click()); await sleep(500);
      const has = await p.evaluate(() => !!document.getElementById('sv-opt-both')); if (has) await p.click('#sv-opt-both'); await sleep(4000); }
    else { await p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); }
    await p.reload(); await sleep(2000);
    const rl = await p.evaluate(() => { const m = INPUT.data3.modeling; const rp = m.report_planning || {}; let recs = []; Object.values(rp).forEach(y => Object.values(y).forEach(mo => Object.values(mo).forEach(arr => (arr || []).forEach(r => recs.push(r)))));
      const last = recs.slice(-2).map(r => { const mi = ((r.snapshot || {}).input || {}).data3 ? r.snapshot.input.data3.modeling : {}; const od = r.snapshot && r.snapshot.output && r.snapshot.output.data ? r.snapshot.output.data : [];
        return { type: r.plan_type, sr_mode: mi.sr_mode, pv_len: (mi.pv_rows || []).length, eff_len: (mi.sr_effective_rows || []).length, audit_mode: (mi.pgn_fixed_flow_recommendation_applied || {}).correction_mode || null,
          out_pv: od.length ? od.filter(x => x.PV !== undefined).length : 0, out_sr_min_max: od.length ? Math.max(...od.map(x => x.SR_Min)) : null }; });
      return { mode: SR_MODE, radio: document.getElementById('sr-mode-pv').checked ? 'follow_pv' : 'fixed', fx: +document.getElementById('f-spinning_reserve_min').value, pv: SR_PV.slice(),
        tok: { sr_mode: m.sr_mode, sr_fixed_mw: m.sr_fixed_mw, pv_rows: (m.pv_rows || []).length, sr_effective_rows: (m.sr_effective_rows || []).length },
        mff: m.manual_fixed_flows, audit: m.pgn_fixed_flow_recommendation_applied || null, report_records: recs.length, report_last: last, note: (document.getElementById('pgn-auto-note') || {}).innerText || '' }; });
    R.reload = Object.assign({}, rl, { pv: undefined, mff: undefined, audit: rl.audit ? { keys: Object.keys(rl.audit), rerun_count: rl.audit.rerun_count, pgn_flow_after: (rl.audit.pgn_flow_after || []).length } : null });
    ck('E_save_reload_sr_pv', rl.mode === R.sr_ui.mode && rl.radio === R.sr_ui.mode && Math.abs(rl.fx - R.sr_ui.fixed) < 1e-9 && rl.pv.every((v, k) => (v == null && R.sr_ui.pv[k] == null) || Math.abs(v - R.sr_ui.pv[k]) < 1e-9) && rl.tok.pv_rows === 48 && rl.tok.sr_effective_rows === 48,
      { mode: rl.mode, fixed: rl.fx, pv_row_edited: E.EDITPV ? [E.EDITPV, rl.pv[+E.EDITPV.split(':')[0] - 1]] : null, tokens: rl.tok });
    if (E.EXPECT === 'auto') { const A0 = o.audit || {}; const mffMap = {}; (rl.mff || []).filter(e => e.area === 'JABABEKA').forEach(e => { mffMap[e.row] = +e.value_mmscfd; });
      const ff48 = Array.from({ length: 48 }, (_, k) => mffMap[k + 1] != null ? mffMap[k + 1] : A0.fixed_flow_before[k]);
      ck('E_save_reload_48_fixed_flow_and_audit', !!rl.audit && ff48.every((v, k) => Math.abs(v - A0.fixed_flow_after[k]) < 1e-9) && (rl.audit.fixed_flow_after || []).length === 48 && rl.audit.correction_mode === 'automatic' && rl.audit.rerun_count === 1 && (rl.audit.pgn_flow_after || []).length === 48 && /applied automatically/.test(rl.note),
        { ff48_sum: +ff48.reduce((a, b2) => a + b2, 0).toFixed(6), rerun_count: rl.audit && rl.audit.rerun_count, note_shown: /applied automatically/.test(rl.note) }); }
    if (E.SAVE === 'report') ck('E_report_planning_and_monitoring_keep_sr_pv_audit', rl.report_last.length === 2 && rl.report_last.every(r => r.sr_mode === R.sr_ui.mode && r.pv_len === 48 && r.eff_len === 48 && (E.EXPECT !== 'auto' || r.audit_mode === 'automatic') && (R.sr_ui.mode !== 'follow_pv' || r.out_pv === 48)), rl.report_last);
  }
  R.requests = {}; reqs.forEach(x => { R.requests[x.m] = (R.requests[x.m] || 0) + 1; }); R.js_errors = errs;
  R.all_pass = Object.values(R.checks).every(c => c.pass) && !errs.length;
  console.log(JSON.stringify(R)); await b.close(); })();
