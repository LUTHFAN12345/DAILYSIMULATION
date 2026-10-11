// t18ui.js <base> <outdir> : uji UI V15.18 (Stop Status vs Commitment, SC/CC, STG auto-follow, notifikasi, Block Required, Save/Reload, Snapshot, Run T7)
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext({ acceptDownloads: true }); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  const R = { errs: [], toasts: [], checks: {} }; p.on('pageerror', e => R.errs.push(String(e.message).slice(0, 300)));
  const ok = (k, v, d) => { R.checks[k] = { pass: !!v, detail: d }; console.log((v ? 'PASS ' : 'FAIL ') + k + ' ' + JSON.stringify(d).slice(0, 400)); };
  await p.goto(BASE + '/index.php'); await sleep(1500);
  await p.evaluate(() => { const t0 = window.toast; window.__toasts = []; window.toast = function (m) { window.__toasts.push(String(m)); try { return t0 && t0.apply(this, arguments); } catch (e) {} }; });
  const csv = fs.readFileSync('/home/claude/ab2/bb/csv5oct.csv', 'utf8');
  await p.evaluate(t => { csvHandleText(t, 'CSV_Load_Pred_dan_Dispatch_5_Oct.csv'); csvApplyPending(); }, csv);
  const sel = async (flag, u, v) => p.evaluate(([f, u, v]) => { const e = document.querySelector(`[data-flag="${f}"][data-u="${u}"]`); if (!e) return 'NO_EL'; if (e.disabled) return 'DISABLED'; if (e.type === 'checkbox') e.checked = !!v; else e.value = v; e.dispatchEvent(new Event('change', { bubbles: true })); return 'ok'; }, [flag, u, v]);
  const st = () => p.evaluate(() => ({ cm: Object.assign({}, COMMIT_MODE), sm: Object.assign({}, STOP_MODE), sc: Object.assign({}, SC_CC), rm: buildRequiredModeObj(), smo: buildStopModeObj(), lds: Object.assign({}, INPUT.data3.modeling.unit_last_data_status), model_sc: INPUT.data3.modeling.sc_cc, req: REQUIRED_ORDER.slice(), cs: CANNOT_STOP.slice(), toasts: window.__toasts.splice(0), summary: (document.getElementById('sem-summary') || {}).innerText || '' }));
  const resetAll = async () => p.evaluate(() => { FLAG_UNITS.forEach(u => { COMMIT_MODE[u] = '-'; STOP_MODE[u] = '-'; STOP_AT[u] = ''; START_AT[u] = ''; }); SC_ELIGIBLE.forEach(u => SC_CC[u] = 'CC'); (BP_STATE || []).forEach(r => { if (r) r.required = false; }); AUTO_BLOCK_UNITS = {}; SEM_AUTO_NOTES = []; SEM_AUTO_LEAD = {}; syncFlagArrays(); applyCommitToModel(); renderUnitFlags(); window.__toasts = []; });
  // ---- SC/CC column present only for eligible units
  const cols = await p.evaluate(() => { const o = {}; FLAG_UNITS.forEach(u => { o[u] = !!document.querySelector(`[data-flag="sccc"][data-u="${u}"]`); }); return o; });
  ok('U0_sccc_column_eligible_only', ['g1','g2','g3','g4','g5','g6','g8','g9'].every(u => cols[u]) && ['g7','g10','s1','s2','s3','ge1','b1','b2'].every(u => !cols[u]), cols);
  // ---- U1 (T0): G6 Start Sim + Stop Status Continuous -> Commitment tetap simulation, payload hold_to_end
  await resetAll(); await sel('req', 'g6', true); await sel('mode', 'g6', 'simulation'); await sel('stopmode', 'g6', 'continuous'); let s = await st();
  ok('U1_stop_continuous_keeps_commitment', s.cm.g6 === 'simulation' && s.sm.g6 === 'continuous' && s.smo.g6 && s.smo.g6.mode === 'hold_to_end' && s.rm.g6.mode === 'based_on_sim' && /stop/i.test(s.lds.G6) && !s.cs.includes('g6'), { cm: s.cm.g6, sm: s.smo.g6, rm: s.rm.g6, lds: s.lds.G6, toasts: s.toasts });
  // ---- U2 (T1): Commitment Continuous -> full horizon, Stop Status disabled
  await resetAll(); await sel('req', 'g6', true); await sel('mode', 'g6', 'continuous'); s = await st(); const dis = await p.evaluate(() => document.querySelector('[data-flag="stopmode"][data-u="g6"]').disabled);
  ok('U2_commit_continuous_full_horizon', s.rm.g6.mode === 'continuous' && s.cs.includes('g6') && /run/i.test(s.lds.G6) && !s.smo.g6 && dis, { rm: s.rm.g6, cs: s.cs, lds: s.lds.G6, stop_disabled: dis });
  // ---- U3 (T6): S1 Start Sim + Stop Continuous, G6 tidak diminta -> G6 auto lead (Required + Start Sim + Stop Continuous + CC); G4/G3 tidak
  await resetAll(); await sel('req', 's1', true); await sel('mode', 's1', 'simulation'); await sel('stopmode', 's1', 'continuous'); s = await st();
  ok('U3_stg_auto_follow_lead_g6', s.cm.g6 === 'simulation' && s.sm.g6 === 'continuous' && s.sc.g6 === 'CC' && s.cm.g4 === '-' && s.cm.g3 === '-' && s.req.includes('g6'), { g6: [s.cm.g6, s.sm.g6, s.sc.g6], g4: s.cm.g4, g3: s.cm.g3, toasts: s.toasts, summary: s.summary.slice(0, 600) });
  // ---- U4 (T7): G6 SC + S1 Required -> G4 lead + notifikasi SC
  await resetAll(); await sel('sccc', 'g6', 'SC'); s = await st(); const scToast = s.toasts.join(' | ');
  await sel('req', 's1', true); await sel('mode', 's1', 'simulation'); s = await st();
  ok('U4_sc_moves_last_and_g4_leads', s.sc.g6 === 'SC' && s.cm.g4 === 'simulation' && s.cm.g6 === '-' && /G6 dipindahkan dari CC ke SC/.test(scToast) && /G4 -> G3 -> G6/.test(scToast) && s.model_sc && s.model_sc.g6 === 'SC', { scToast, g4: s.cm.g4, g6: s.cm.g6, model_sc: s.model_sc, toasts: s.toasts });
  // ---- U5 (T3): G6 Stop At 16:00 -> notifikasi efektif priority
  await resetAll(); await sel('req', 'g6', true); await sel('mode', 'g6', 'simulation'); await sel('stopmode', 'g6', 'request'); await sel('stopat', 'g6', '16:00'); await sleep(50); s = await st();
  ok('U5_stop_at_priority_notification', /G6 adalah Unit Priority #1 Block 1, tetapi dijadwalkan stop mulai 16:00\. Mulai 16:00 Effective Priority berubah menjadi G4 -> G3 -> G6\./.test(s.toasts.join(' ') + ' ' + s.summary), { toasts: s.toasts, summary: s.summary.slice(0, 500) });
  // ---- U6: B1 Stop All Day dengan configured B1 -> B2 -> teks instruksi §3.1
  await resetAll(); const n6 = await p.evaluate(() => { UP_STATE['Block Babelan'] = ['b1', 'b2']; STOP_ROWS = STOP_ROWS.filter(r => r.unit !== 'b1'); STOP_ROWS.push({ unit: 'b1', allDay: true, start: 1, stop: 48 }); renderSemSummary(); return semNotifications().map(x => x.text); });
  ok('U6_stop_all_day_babelan_text', n6.some(t => t === 'B1 dijadwalkan Stop All Day. Effective Priority Babelan berubah dari B1 -> B2 menjadi B2 -> B1 untuk seluruh horizon. B1 ditempatkan terakhir karena tidak tersedia untuk dispatch.'), n6);
  // ---- U7: Block 1 Required -> primer (S1 + G6) Start Based On Simulation, BUKAN Continuous
  await resetAll(); const u7 = await p.evaluate(() => { const r = (BP_STATE || []).find(x => x && x.block === 'Block 1'); if (!r) return null; r.required = true; syncBlockRequiredToCommitment({ silent: true }); return { s1: COMMIT_MODE.s1, g6: COMMIT_MODE.g6, rm: buildRequiredModeObj(), cs: CANNOT_STOP.slice(), lds: Object.assign({}, INPUT.data3.modeling.unit_last_data_status) }; });
  ok('U7_block_required_not_full_horizon', u7 && u7.s1 === 'simulation' && u7.g6 === 'simulation' && !u7.cs.includes('g6') && !u7.cs.includes('s1'), u7);
  // ---- U8: semantic summary visible before Run
  s = await st(); ok('U8_semantic_summary_rendered', /Ringkasan semantik sebelum Run/.test(s.summary) && /Block Required/.test(s.summary), s.summary.slice(0, 500));
  // ---- U9: Save/Reload: skenario T7 (G6 SC, S1 Start Sim) disimpan, halaman dimuat ulang, state kembali
  await resetAll(); await p.evaluate(() => { const r = (BP_STATE || []).find(x => x && x.block === 'Block 1'); if (r) r.required = true; UP_STATE['Block Babelan'] = ['b2', 'b1']; });
  for (const u of ['g2', 'g8', 's2', 's3', 'b2']) { await sel('req', u, true); await sel('mode', u, 'continuous'); }
  await sel('req', 'g9', true); await sel('mode', 'g9', 'simulation'); await sel('stopmode', 'g9', 'sim');
  await sel('sccc', 'g6', 'SC'); await sel('req', 's1', true); await sel('mode', 's1', 'simulation'); await sel('stopmode', 's1', 'continuous');
  const pay = await p.evaluate(() => { const x = assembleInput(); return { sc: x.data3.modeling.sc_cc, rm: x.data3.modeling.required_mode, sm: x.data3.modeling.stop_mode }; });
  ok('U9a_payload_has_sc_and_hold', pay.sc && pay.sc.g6 === 'SC' && pay.sm.s1 && pay.sm.s1.mode === 'hold_to_end' && pay.rm.g4 && pay.rm.g4.mode === 'based_on_sim' && pay.sm.g4 && pay.sm.g4.mode === 'hold_to_end', pay);
  const sv = await p.evaluate(async () => { const r = await fetch('run.php?mode=save', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(assembleInput()) }); return r.status + ' ' + (await r.text()).slice(0, 200); });
  await p.reload(); await sleep(1800);
  const rl = await p.evaluate(() => ({ sc: Object.assign({}, SC_CC), cm: { s1: COMMIT_MODE.s1, g4: COMMIT_MODE.g4, g6: COMMIT_MODE.g6 }, sm: { s1: STOP_MODE.s1, g4: STOP_MODE.g4 }, sel: (document.querySelector('[data-flag="sccc"][data-u="g6"]') || {}).value }));
  ok('U9b_reload_restores_sc_and_hold', rl.sc.g6 === 'SC' && rl.sel === 'SC' && rl.cm.s1 === 'simulation' && rl.cm.g4 === 'simulation' && rl.sm.s1 === 'continuous' && rl.sm.g4 === 'continuous', { save: sv, rl });
  // ---- U10: Input Snapshot memuat sc_cc + semantics
  let snapF = null; try { await p.evaluate(() => { const e = document.getElementById('btn-snapshot'); if (e) e.scrollIntoView({ block: 'center' }); }); const [dl] = await Promise.all([p.waitForEvent('download', { timeout: 30000 }), p.click('#btn-snapshot')]); snapF = `${OUT}/t18_snapshot.json`; await dl.saveAs(snapF); } catch (e) { R.errs.push('snapshot ' + e.message.split('\n')[0]); }
  let snap = null; try { snap = JSON.parse(fs.readFileSync(snapF, 'utf8')); } catch (e) {}
  const su = snap && (snap.summary || (snap.snapshot || {}).summary); const sem = snap && (snap.semantics || (snap.snapshot || {}).semantics);
  ok('U10_snapshot_sc_semantics', !!(su && su.units && su.units.G6 && su.units.G6.sc_cc === 'SC' && sem && sem.sc_cc && sem.sc_cc.g6 === 'SC'), { keys: snap ? Object.keys(snap) : null, g6: su && su.units && su.units.G6, sem_g4: sem && sem.units && sem.units.G4 });
  // ---- U11: Run T7 via UI (Fastest) -> PASS, G6 orange SC sel, notifikasi + proof binding hash
  await p.evaluate(() => { window.__prevOut = (typeof OUTPUT !== 'undefined') ? OUTPUT : null; document.getElementById('btn-run').click(); }); const t0 = Date.now(); let res = null;
  while (Date.now() - t0 < 180000) { await sleep(500); const f = await p.evaluate(() => { const st = window.ppRunTimerState ? window.ppRunTimerState().state : null; return { fresh: typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT !== window.__prevOut && (OUTPUT.data || []).length === 48, st }; }); if (f.fresh && /FINAL|GAGAL|DITOLAK|BLOCK|MENUNGGU/i.test(String(f.st))) break; }
  await sleep(1500);
  res = await p.evaluate(() => { if (typeof OUTPUT === 'undefined' || !OUTPUT) return null; const i = OUTPUT.info || {}; const pb = i['Proof Binding'] || {}; const cells = [...document.querySelectorAll('#tbl-result td.g-gtg.sc-mode, #tbl-result td.g-gtg2.sc-mode')].length;
    const g6 = OUTPUT.data.map(r => +(r.G6 || 0)); const s1 = OUTPUT.data.map(r => +(r.S1 || 0));
    return { gate: (OUTPUT.release_gate || {}).status, cp: i['Cost Production (USD/MWh)'], merit: (i['Merit Proof C1-C4 STG'] || {}).status, sc_units: (i['SC CC Mode'] || {}).sc_units, notes: ((i['Effective Priority'] || {}).notifications || []).map(n => n.text), bs: (i['Block Sequence Audit'] || {}).status,
      pb: { sc: pb.sc_cc_hash, ep: pb.effective_priority_hash, bs: pb.block_sequence_hash, ver: pb.commitment_semantics_version }, orange: cells, g6on: g6.filter(v => v > 0.01).length, g4first: OUTPUT.data.findIndex(r => +(r.G4 || 0) > 0.01) + 1, s1first: s1.findIndex(v => v > 0.01) + 1, notesUI: ((document.getElementById('sem-result-notes') || {}).innerText || '').slice(0, 400), t: 0 }; });
  if (res) res.t = (Date.now() - t0) / 1000;
  ok('U11_run_t7_ui', res && res.gate === 'PASS' && res.merit === 'PASS' && res.sc_units && res.sc_units.includes('G6') && res.pb.sc && res.pb.ep && res.pb.bs && res.pb.ver && res.notes.some(t => /G6 dipindahkan dari CC ke SC/.test(t)) && /Block Sequence Audit: PASS/.test(res.notesUI), res);
  // ---- U12: Excel memuat SC/CC dan Effective Priority per row
  const xls = await p.evaluate(() => { const h = resultToHTMLTable(); return { sc: /<td>SC\/CC<\/td><td>[^<]*G6=SC/.test(h), ep: /Effective Priority \(row\)/.test(h), sem: /Commitment Semantics/.test(h) }; });
  ok('U12_excel_sc_effective_priority', xls.sc && xls.ep && xls.sem, xls);
  R.errs = R.errs.filter(e => !/ResizeObserver/.test(e)); ok('U13_no_page_errors', R.errs.length === 0, R.errs);
  fs.writeFileSync(`${OUT}/t18_ui.json`, JSON.stringify(R, null, 1)); await b.close(); })();
