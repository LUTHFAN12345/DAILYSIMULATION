/* PV/SR/PGN-recommendation UI test. node pv_ui.js <base> <label>
 * env: CSV=<path> SR_MODE=fixed|follow_pv SR_FIX=<MW> EDITPV=<row>:<val> ACTION=apply|keep TARGET=fast|max SAVE=report|input|none ROOT=<app root> */
const { chromium } = require('playwright'); const fs = require('fs');
const [,, BASE, LABEL] = process.argv; const E = process.env; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const reqs = []; let t0 = 0; p.on('request', r => { const m = (r.url().match(/mode=([a-z_]+)/) || [])[1]; if (m) reqs.push({ t: t0 ? (Date.now() - t0) / 1000 : -1, m }); });
  const R = { case: LABEL, checks: {} }; const ck = (k, ok, ev) => { R.checks[k] = { pass: !!ok, evidence: ev }; };
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  if (E.TARGET) await p.evaluate(t => { const s = document.getElementById('f-tl-target'); s.value = t; s.dispatchEvent(new Event('change', { bubbles: true })); }, E.TARGET);
  /* ---- import ---- */
  if (E.CSV) {
    await p.setInputFiles('#csv-file', E.CSV); await sleep(800);
    const prev = await p.evaluate(() => ({ txt: (document.getElementById('csv-preview') || {}).innerText || '', st: (document.getElementById('csv-status') || {}).innerText || '', applyDis: document.getElementById('csv-apply').disabled,
      applyTxt: document.getElementById('csv-apply').innerText, cancelTxt: document.getElementById('csv-clear').innerText }));
    R.import_preview = prev;
    await p.click('#csv-apply'); await sleep(400);
    const lines = fs.readFileSync(E.CSV, 'utf8').split(/\r?\n/).filter(l => l.trim()); const body = lines.slice(1).map(l => l.split(','));
    const st = await p.evaluate(() => ({ ie: INPUT.data1.map(x => +x.value), disp: INPUT.data2.map(x => +x.dispatch), time: INPUT.data1.map(x => x.time), pv: SR_PV.slice(), mode: SR_MODE }));
    const okIE = body.every((c, i) => Math.abs(st.ie[i] - +c[1]) < 1e-9), okD = body.every((c, i) => Math.abs(st.disp[i] - +c[2]) < 1e-9), okPV = body.every((c, i) => Math.abs(st.pv[i] - +c[3]) < 1e-9);
    ck('import_header_skipped_48_rows', body.length === 48 && /Rows detected: 48/.test(prev.txt) && st.ie.length >= 48, { data_rows_in_file: body.length, preview: prev.txt.split('\n').slice(0, 8) });
    ck('import_A_C_to_IE_Dispatch', okIE && okD, { first: [st.time[0], st.ie[0], st.disp[0]], last: [st.time[47], st.ie[47], st.disp[47]] });
    ck('import_D_to_PV', okPV && /PV min\/max: 0\.22 \/ 24\.15 MW/.test(prev.txt), { pv_min: Math.min(...st.pv), pv_max: Math.max(...st.pv), buttons: [prev.applyTxt, prev.cancelTxt] });
    ck('import_keeps_sr_mode', st.mode === 'fixed', { mode_after_import: st.mode });
  }
  /* ---- SR mode ---- */
  if (E.SR_MODE) await p.evaluate(([md, fx]) => { const r = document.getElementById(md === 'follow_pv' ? 'sr-mode-pv' : 'sr-mode-fixed'); r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true }));
    const f = document.getElementById('f-spinning_reserve_min'); f.value = fx; f.dispatchEvent(new Event('input', { bubbles: true })); }, [E.SR_MODE, E.SR_FIX || '0']);
  if (E.EDITPV) { const [rw, vv] = E.EDITPV.split(':'); await p.evaluate(([i, v]) => { const el = document.querySelector('#tbl-srpv input.srpv[data-i="' + i + '"]'); el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); }, [String(+rw - 1), vv]); }
  const ui = await p.evaluate(() => ({ mode: SR_MODE, fixedChecked: document.getElementById('sr-mode-fixed').checked, pvChecked: document.getElementById('sr-mode-pv').checked,
    eff: [...document.querySelectorAll('#tbl-srpv td[data-sreff]')].map(td => +td.textContent), pv: SR_PV.slice(), fx: +document.getElementById('f-spinning_reserve_min').value,
    rows: document.querySelectorAll('#tbl-srpv tr').length - 1, order: [...document.querySelectorAll('#cp-sr .fcard .ft')].map(x => x.textContent), model: { sr_mode: INPUT.data3.modeling.sr_mode, sr_fixed_mw: INPUT.data3.modeling.sr_fixed_mw } }));
  const expEff = ui.pv.map(v => ui.mode === 'follow_pv' ? (v == null ? ui.fx : Math.max(ui.fx, v)) : ui.fx);
  ck('sr_sections_order', ui.order[0] === 'BUS FLOW' && ui.order[1] === 'SPINNING RESERVE', ui.order);
  ck('sr_modes_exclusive', (ui.fixedChecked !== ui.pvChecked) && ui.mode === (E.SR_MODE || ui.mode), { fixed: ui.fixedChecked, follow_pv: ui.pvChecked });
  ck('sr_table_48_rows_formula', ui.rows === 48 && ui.eff.length === 48 && ui.eff.every((v, i) => Math.abs(v - expEff[i]) < 0.006), { night_row1: [ui.pv[0], ui.eff[0]], peak: [Math.max(...ui.pv.filter(x => x != null)), Math.max(...ui.eff)], fixed: ui.fx });
  R.sr_ui = { mode: ui.mode, fixed: ui.fx, eff: ui.eff, pv: ui.pv };
  /* ---- Run ---- */
  t0 = Date.now(); await p.click('#btn-run'); let popup = null, tPop = null;
  while (Date.now() - t0 < 300000) { await sleep(200); const s = await p.evaluate(() => ({ pop: !!document.getElementById('pgnrec-mask'), m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled }));
    if (s.pop) { tPop = (Date.now() - t0) / 1000; popup = await p.evaluate(() => document.getElementById('pgnrec-mask').innerText); break; }
    if (!s.d && /FASTEST VALID PLAN|FINAL OPTIMAL|BELUM final|FEASIBLE|gagal|Error/.test(s.m)) { R.no_popup_msg = s.m.slice(0, 300); break; } }
  R.popup = { shown: !!popup, t_s: tPop, text: popup }; const runsBefore = reqs.filter(x => x.m === 'run').length;
  if (popup) ck('popup_text_redistribution_and_quota', /Low PGN Flow Recommendation/.test(popup) && /redistributed proportionally to other safe time slots/.test(popup) && /total daily Fixed Flow JBBK quota remains unchanged/.test(popup)
      && /Apply Recommendation/.test(popup) && /Keep Current Input/.test(popup) && (() => { const b = popup.match(/Daily quota before: ([\d.]+)/), a2 = popup.match(/Daily quota after redistribution: ([\d.]+)/); return b && a2 && b[1] === a2[1]; })(),
      { t_click_to_popup_s: tPop, lines: popup.split('\n').filter(l => /Affected period|Current Fixed|Recommended Fixed|Temporary|Daily quota|Recipient rows|Expected PGN/.test(l)) });
  if (popup && E.ACTION === 'keep') {
    const tk = Date.now(); await p.click('#pgnrec-keep'); await sleep(300);
    const m = await p.evaluate(() => (document.getElementById('run-msg') || {}).innerText || ''); const n0 = reqs.length; await sleep(6000);
    const polls = reqs.slice(n0).filter(x => ['job_poll', 'fast_ready', 'run', 'job_exec'].includes(x.m)).length;
    const ff = await p.evaluate(() => (INPUT.data3.modeling.manual_fixed_flows || []).length);
    R.keep_input_unchanged = ff === 0;
    ck('keep_terminal_fast', /PGN_MIN_FLOW_NOT_FEASIBLE_WITH_CURRENT_FIXED_FLOW/.test(m) && polls === 0 && ff === 0, { t_after_click_s: (Date.now() - tk - 6000) / 1000, msg: m.slice(0, 220), requests_after_6s: polls, manual_fixed_flows: ff, t_click_to_terminal_s: tPop });
  }
  if (popup && E.ACTION === 'apply') {
    await p.click('#pgnrec-apply'); const ta = Date.now(); let m = '';
    while (Date.now() - ta < 900000) { await sleep(300); const s = await p.evaluate(() => ({ m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled, pop: !!document.getElementById('pgnrec-mask') }));
      m = s.m; if (s.pop) { R.second_popup = true; break; } if (!s.d && /FASTEST VALID PLAN|FINAL OPTIMAL|VALID PROVISIONAL tetap ditampilkan|tidak lolos release gate|BELUM final|FEASIBLE|gagal|Error/.test(s.m)) break; }
    R.apply = { t_rerun_s: (Date.now() - ta) / 1000, msg: m.slice(0, 260), runs_total: reqs.filter(x => x.m === 'run').length };
    const o = await p.evaluate(() => { const O = (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data && OUTPUT.data.length) ? OUTPUT : ((typeof PRELIM !== 'undefined' && PRELIM && PRELIM.data) ? PRELIM.data : {}); const D = O.data || []; const i = O.info || {}; const f = i['V12 Fastest Check'] || {}; const mA = i['V12 Dispatch Merit Audit'] || {};
      const first = u => { const k = D.findIndex(r => (+r[u] || 0) > 0.01); return k < 0 ? null : k + 1; };
      return { rows: D.length, sr_min: D.map(r => r.SR_Min), spin: D.map(r => r.Spin_Res), ffj: D.map(r => r.FixedFlow_J), pgn: D.map(r => r.Flow_PGN_RT),
        hard: (O.simulation_acceptance_review || {}).hard_validation ? O.simulation_acceptance_review.hard_validation.status : null, gate: O.release_gate || null, label: i['Result Status'] || null,
        merit: mA.status, c4: (mA.c4_cross_group_priority || {}).fail, stg: f.stg_proof ? (f.stg_proof.equal_calc + f.stg_proof.startup_hold) + '/' + f.stg_proof.rows_x_stg + ' langgar ' + f.stg_proof.violations : null,
        prov: (i['Fuel Provenance'] || {}).status, fuel: i['Fuel Action Reconciliation'] || null, dist_l: i['Distillate Used (l)'], dist_unit: i['Distillate per unit (l)'], cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'],
        sr_req: i['Spinning Reserve Requirement'] || null, starts: { G4: first('G4'), G8: first('G8'), S1: first('S1'), G3: first('G3') }, seq: { G8: D.slice(1, 7).map(r => +r.G8), G4: (() => { const k = first('G4'); return k ? D.slice(k - 1, k + 3).map(r => +r.G4) : null; })() },
        mff: INPUT.data3.modeling.manual_fixed_flows, audit: INPUT.data3.modeling.pgn_fixed_flow_recommendation_applied || null }; });
    R.result = o;
    const srOK = o.rows === 48 && o.sr_min.every((v, k) => Math.abs(v - R.sr_ui.eff[k]) < 0.006) && o.spin.every((v, k) => v >= o.sr_min[k] - 1e-6);
    ck('sr_array_used_by_engine_and_met', srOK, { sr_min_first_last_peak: [o.sr_min[0], o.sr_min[47], Math.max(...o.sr_min)], spin_min_margin: Math.min(...o.spin.map((v, k) => v - o.sr_min[k])) });
    const au = o.audit || {}; const rc = au.recipient_rows || []; const sumB = o.ffj.length ? 36 * 48 : 0, sumA = o.ffj.reduce((x, y) => x + y, 0);
    const ratios = rc.map(x => x.additional_mmscfd / x.safe_capacity_mmscfd); const rMin = Math.min(...ratios), rMax = Math.max(...ratios);
    const propOK = rc.every(x => Math.abs(x.additional_mmscfd - au.redistributed_volume.mmscfd_rows * x.weight) <= 0.0001 + 1e-9);
    const srcAfter = ((au.source_rows || [])[0] || {}).fixed_flow_after; const expSrc = E.EXPECT_SRC ? +E.EXPECT_SRC : srcAfter;
    ck('T4A_source_reduced_daily_total_identical', o.ffj[0] === srcAfter && srcAfter === expSrc && Math.abs(sumA - sumB) < 1e-6 && Math.abs(au.daily_total_after.mmscfd - au.daily_total_before.mmscfd) < 1e-6,
      { ffj_row1: o.ffj[0], expected_source_after: expSrc, sum_before_mmscfd_rows: sumB, sum_after_mmscfd_rows: +sumA.toFixed(6), daily_before: au.daily_total_before, daily_after: au.daily_total_after, diff_mmscf: au.daily_total_difference_mmscf });
    ck('T4A_engine_fixed_flow_equals_audit', o.ffj.every((v, k) => Math.abs(v - au.fixed_flow_after[k]) < 1e-4), { engine_row2_3_48: [o.ffj[1], o.ffj[2], o.ffj[47]], audit_row2_3_48: [au.fixed_flow_after[1], au.fixed_flow_after[2], au.fixed_flow_after[47]] });
    ck('T4A_proportional_to_safe_headroom', rc.length > 1 && propOK, { recipients: rc.length, ratio_additional_over_capacity_min_max: [+rMin.toFixed(6), +rMax.toFixed(6)], rounding_unit: 0.0001 });
    ck('T4B_not_stacked_on_one_row', rc.length > 1 && Math.max(...rc.map(x => x.additional_mmscfd)) < 0.5 * au.redistributed_volume.mmscfd_rows, { recipients: rc.length, largest_share: +(Math.max(...rc.map(x => x.additional_mmscfd)) / au.redistributed_volume.mmscfd_rows).toFixed(4), largest_row: rc.reduce((a, b) => b.additional_mmscfd > a.additional_mmscfd ? b : a, rc[0] || {}) });
    ck('apply_pgn_min_met', o.pgn.every(v => v >= 3 - 1e-3), { pgn_row1: o.pgn[0], pgn_min: Math.min(...o.pgn) });
    ck('apply_rerun_once_no_loop', R.apply.runs_total === runsBefore + 1 && !R.second_popup, { runs_before_apply: runsBefore, runs_total: R.apply.runs_total });
    ck('apply_audit_saved', !!(o.audit && o.audit.user_decision === 'APPLY' && (o.audit.source_rows || [])[0] && o.audit.source_rows[0].fixed_flow_before === 36 && o.audit.source_rows[0].fixed_flow_after === srcAfter && String((o.audit.constraint_validation || {}).status).startsWith('PASS')), o.audit && { source: o.audit.source_rows, validation: o.audit.constraint_validation });
    ck('apply_result_hard_valid_sr_met', o.hard === 'PASS' && o.rows === 48 && (o.prov || '').startsWith('PASS') && (!o.fuel || o.fuel.pass) && o.spin.every((v, k) => v >= o.sr_min[k] - 1e-6),
      { hard: o.hard, prov: o.prov, fuel: o.fuel && o.fuel.code, merit_info: o.merit, c4_info: o.c4, gate: o.gate && (o.gate.status || o.gate.blocking_reasons), msg: (R.apply.msg || '').slice(0, 120) });
    if (E.T6) ck('T6_apply_result_fully_valid', o.hard === 'PASS' && o.merit === 'PASS' && !(o.c4 > 0) && (o.prov || '').startsWith('PASS') && (!o.fuel || o.fuel.pass) && o.rows === 48, { hard: o.hard, merit: o.merit, c4: o.c4, stg: o.stg, prov: o.prov, fuel: o.fuel && o.fuel.code, label: o.label, gate: o.gate && (o.gate.status || o.gate.blocking_reasons) });
    ck('required_startups_kept', o.starts.G4 && o.starts.G8 && o.starts.S1, { first_load_row: o.starts, G8_seq: o.seq.G8, G4_seq: o.seq.G4 });
  }
  /* ---- Save / Reload / Report ---- */
  if (E.SAVE && E.SAVE !== 'none') {
    if (E.SAVE === 'report') { await p.evaluate(() => document.getElementById('btn-save-actual').click()); await sleep(500);
      const has = await p.evaluate(() => !!document.getElementById('sv-opt-both')); if (has) await p.click('#sv-opt-both'); await sleep(4000); }
    else { await p.evaluate(() => document.getElementById('btn-save').click()); await sleep(2500); }
    await p.reload(); await sleep(2000);
    const rl = await p.evaluate(() => { const m = INPUT.data3.modeling; const rp = m.report_planning || {}; let recs = []; Object.values(rp).forEach(y => Object.values(y).forEach(mo => Object.values(mo).forEach(arr => (arr || []).forEach(r => recs.push(r)))));
      const last = recs.slice(-2).map(r => ({ type: r.plan_type, name: r.name_plan, sr_mode: ((r.snapshot || {}).input || {}).data3 && r.snapshot.input.data3.modeling.sr_mode,
        sr_fixed_mw: r.snapshot && r.snapshot.input.data3.modeling.sr_fixed_mw, pv_len: r.snapshot && (r.snapshot.input.data3.modeling.pv_rows || []).length, pv20: r.snapshot && (r.snapshot.input.data3.modeling.pv_rows || [])[19],
        eff_len: r.snapshot && (r.snapshot.input.data3.modeling.sr_effective_rows || []).length, audit: !!(r.snapshot && r.snapshot.input.data3.modeling.pgn_fixed_flow_recommendation_applied),
        out_sr_min: r.snapshot && r.snapshot.output && r.snapshot.output.data ? r.snapshot.output.data.map(x => x.SR_Min).slice(0, 3).concat(['…', Math.max(...r.snapshot.output.data.map(x => x.SR_Min))]) : null }));
      return { mode: SR_MODE, radio: document.getElementById('sr-mode-pv').checked ? 'follow_pv' : 'fixed', fx: +document.getElementById('f-spinning_reserve_min').value, pv: SR_PV.slice(),
        eff: [...document.querySelectorAll('#tbl-srpv td[data-sreff]')].map(td => +td.textContent), tok: { sr_mode: m.sr_mode, sr_fixed_mw: m.sr_fixed_mw, pv_rows: (m.pv_rows || []).length, sr_effective_rows: (m.sr_effective_rows || []).length },
        mff: m.manual_fixed_flows, audit: m.pgn_fixed_flow_recommendation_applied || null, ie0: INPUT.data1[0].value, report_records: recs.length, report_last: last }; });
    R.reload = rl;
    ck('save_reload_sr', rl.mode === R.sr_ui.mode && rl.radio === R.sr_ui.mode && Math.abs(rl.fx - R.sr_ui.fixed) < 1e-9 && rl.pv.every((v, k) => (v == null && R.sr_ui.pv[k] == null) || Math.abs(v - R.sr_ui.pv[k]) < 1e-9) && rl.tok.pv_rows === 48 && rl.tok.sr_effective_rows === 48,
      { mode: rl.mode, fixed: rl.fx, pv_row_edited: E.EDITPV ? [E.EDITPV, rl.pv[+E.EDITPV.split(':')[0] - 1]] : null, tokens: rl.tok });
    if (E.ACTION === 'apply') { const A0 = (R.result || {}).audit || {}; const mffMap = {}; (rl.mff || []).filter(e => e.area === 'JABABEKA').forEach(e => { mffMap[e.row] = +e.value_mmscfd; });
      const ff48 = Array.from({ length: 48 }, (_, k) => mffMap[k + 1] != null ? mffMap[k + 1] : 36);
      ck('T4D_save_reload_48_fixed_flow_and_audit', !!rl.audit && ff48.every((v, k) => Math.abs(v - A0.fixed_flow_after[k]) < 1e-9) && (rl.audit.fixed_flow_after || []).length === 48 && (rl.audit.fixed_flow_before || []).length === 48 && rl.audit.user_decision === 'APPLY',
        { reloaded_manual_rows: (rl.mff || []).length, ff48_sum: +ff48.reduce((a, b) => a + b, 0).toFixed(6), audit_keys: rl.audit ? Object.keys(rl.audit) : null }); }
    if (E.SAVE === 'report') ck('report_planning_and_monitoring_keep_sr', rl.report_last.length === 2 && rl.report_last.every(r => r.sr_mode === R.sr_ui.mode && r.pv_len === 48 && r.eff_len === 48 && r.audit), rl.report_last);
  }
  R.requests = {}; reqs.forEach(x => { R.requests[x.m] = (R.requests[x.m] || 0) + 1; }); R.js_errors = errs;
  R.all_pass = Object.values(R.checks).every(c => c.pass) && !errs.length;
  console.log(JSON.stringify(R)); await b.close(); })();
