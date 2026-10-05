/* T2/T3 UI: node t23_ui.js <base> <root> <unit> <uiToken: cont_end|sim> <label> */
const { chromium } = require('playwright'); const fs = require('fs'); const cp = require('child_process');
const [,, BASE, ROOT, UNIT, TOK, LABEL] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => { const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
  const p = await b.newPage({ viewport: { width: 1500, height: 950 } }); const errs = []; p.on('pageerror', e => errs.push(String(e.message)));
  const resps = []; p.on('response', async r => { const u = r.url(); if (/mode=save(&|$)/.test(u)) { try { resps.push({ u, j: await r.json() }); } catch (e) {} } });
  await p.goto(BASE + '/index.php'); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); await p.reload(); await sleep(1500);
  const optLabel = await p.evaluate(([u, t]) => { const s = document.querySelector(`select[data-flag="stopmode"][data-u="${u}"]`); s.value = t; s.dispatchEvent(new Event('change', { bubbles: true }));
    const s2 = document.querySelector(`select[data-flag="stopmode"][data-u="${u}"]`); return { value: s2.value, label: s2.options[s2.selectedIndex].text, all: [...s2.options].map(o => o.text) }; }, [UNIT, TOK]);
  const tokSent = await p.evaluate(u => (INPUT.data3.modeling.stop_mode || {})[u] || null, UNIT);
  const t0 = Date.now(); await p.click('#btn-run'); let msg = '', tT = null;
  while (Date.now() - t0 < 900000) { await sleep(300); const s = await p.evaluate(() => ({ m: (document.getElementById('run-msg') || {}).innerText || '', d: document.getElementById('btn-run').disabled }));
    msg = s.m; if (!s.d && /FASTEST VALID PLAN|FINAL OPTIMAL|BELUM final|FEASIBLE|gagal|Error/.test(s.m)) { tT = (Date.now() - t0) / 1000; break; } }
  const res = await p.evaluate(u => { const O = (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data && OUTPUT.data.length) ? OUTPUT : ((typeof PRELIM !== 'undefined' && PRELIM && PRELIM.data) ? PRELIM.data : null);
    const D = (O && O.data) || []; const U = u.toUpperCase(); const v = D.map(r => +r[U] || 0); const f = v.findIndex(x => x > 0.01);
    const i = (O && O.info) || {}; return { rows: D.length, unit_mw: v.map(x => Math.round(x * 10) / 10), first_load_row: f >= 0 ? f + 1 : null,
      zero_after_start: f >= 0 ? v.slice(f).filter(x => x <= 0.01).length : null, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'],
      resolution: i['Stop Status Continuous Resolution'] || null, decision: i['Stop Or Continuous Decision'] || null,
      hard: ((O && O.simulation_acceptance_review) || {}).hard_validation ? O.simulation_acceptance_review.hard_validation.status : null,
      gate: (O && O.release_gate) ? O.release_gate.blocking_reasons : null, source: (typeof OUTPUT !== 'undefined' && OUTPUT && OUTPUT.data && OUTPUT.data.length) ? 'OUTPUT' : 'PRELIM' }; }, UNIT);
  /* Save -> disk -> reload */
  const n0 = resps.length; await p.evaluate(() => document.getElementById('btn-save').click());
  for (let i = 0; i < 100 && resps.length === n0; i++) await sleep(100);
  const saveRes = resps.length > n0 ? resps[resps.length - 1].j : null;
  let disk = null; try { disk = JSON.parse(fs.readFileSync(ROOT + '/input_data.json', 'utf8')).data3.modeling.stop_mode[UNIT] || null; } catch (e) { disk = 'ERR ' + e.message; }
  let store = ''; try { store = cp.execSync(`grep -rl '"${TOK === 'cont_end' ? 'continuous_to_end' : 'stop_or_continuous_sim'}"' ${ROOT}/data 2>/dev/null | head -5`).toString().trim(); } catch (e) { store = ''; }
  await p.reload(); await sleep(1500);
  const after = await p.evaluate(u => { const s = document.querySelector(`select[data-flag="stopmode"][data-u="${u}"]`); return { value: s.value, label: s.options[s.selectedIndex].text }; }, UNIT);
  console.log(JSON.stringify({ case: LABEL, unit: UNIT, ui_option: optLabel, token_in_payload: tokSent, t_terminal_s: tT, msg: msg.slice(0, 400), result: res,
    save: saveRes ? { result: saveRes.result, ok: saveRes.ok } : null, token_on_disk: disk, datastore_files_with_token: store.split('\n').filter(Boolean),
    after_reload: after, js_errors: errs }));
  await b.close(); })();
