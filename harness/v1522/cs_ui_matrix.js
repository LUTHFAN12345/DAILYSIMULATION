// cs_ui_matrix.js <base> <out.json> : V15.22 UI matrix — Commitment Mode vs Stop Status vs Last Data, all units (G1-G10, S1-S3, GE1-GE4, B1-B2)
//   K*: operator edits in the Unit Flags table -> payload (assembleInput) -> in-page reload (initUnitFlags on that payload) -> state identical
//   L*: legacy / engine-normalized records loaded by initUnitFlags (stale unit_cannot_stop must not turn hold units into Continuous)
//   S*: real Save -> browser reload through the server datastore
const { chromium } = require('playwright'); const fs = require('fs'); const [,, BASE, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const COMBOS = {
  K1: { mode: '-', uld: 'Stop', stop: '-', exp: { uld: 'Stop', rm: null, sm: null, cs: false, ru: false } },
  K2: { mode: '-', uld: 'Stop', stop: 'continuous', exp: { uld: 'Stop', rm: null, sm: 'hold_to_end', cs: false, ru: false } },
  K3: { mode: 'simulation', stop: 'continuous', exp: { uld: 'Stop', rm: 'based_on_sim', sm: 'hold_to_end', cs: false, ru: true } },
  K4: { mode: 'request', at: '04:00', stop: 'continuous', exp: { uld: 'Stop', rm: 'start_at@04:00', sm: 'hold_to_end', cs: false, ru: true } },
  K5: { mode: 'continuous', exp: { uld: 'Running', rm: 'continuous', sm: null, cs: true, ru: false } },
  K6: { pre: 'continuous', mode: 'simulation', stop: '-', exp: { uld: 'Stop', rm: 'based_on_sim', sm: null, cs: false, ru: true } },
  K7: { mode: 'simulation', stop: 'sim_must', exp: { uld: 'Stop', rm: 'based_on_sim', sm: 'based_on_sim_must', cs: false, ru: true } },
  K8: { mode: 'simulation', stop: 'sim', exp: { uld: 'Stop', rm: 'based_on_sim', sm: 'based_on_sim', cs: false, ru: true } },
  K9: { mode: '-', uld: 'Running', stop: 'continuous', exp: { uld: 'Running', rm: null, sm: 'hold_to_end', cs: false, ru: false } },
  K10: { mode: 'request', at: '06:00', stop: 'request', stopAt: '20:00', exp: { uld: 'Stop', rm: 'start_at@06:00', sm: 'stop_at@20:00', cs: false, ru: true } },
  K11: { mode: '-', uld: 'Running', then: 'Stop', stop: '-', exp: { uld: 'Stop', rm: null, sm: null, cs: false, ru: false } },
};
const LEGACY = {   // record form -> expected UI state
  L1: { m: { rm: { mode: 'based_on_sim' }, sm: { mode: 'hold_to_end' }, cs: true, ru: true, uld: 'Stop' }, exp: { COMMIT: 'simulation', STOP: 'continuous' } },
  L2: { m: { sm: { mode: 'hold_to_end' }, cs: true, uld: 'Stop' }, exp: { COMMIT: '-', STOP: 'continuous' } },
  L3: { m: { cs: true, uld: 'Running' }, exp: { COMMIT: 'continuous', STOP: '-' } },
  L4: { m: { rm: { mode: 'continuous' }, cs: true, uld: 'Running' }, exp: { COMMIT: 'continuous', STOP: '-' } },
  L5: { m: { ru: true, uld: 'Stop' }, exp: { COMMIT: 'simulation', STOP: '-' } },
  L6: { m: { cs: true, pol: 'HOLD_TO_END_AFTER_START', uld: 'Stop' }, exp: { COMMIT: '-', STOP: 'continuous' } },
  L7: { m: { rm: { mode: 'start_at', at: '04:00' }, sm: { mode: 'hold_to_end' }, cs: true, ru: true, uld: 'Stop' }, exp: { COMMIT: 'request', STOP: 'continuous' } },
};
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const p = await (await b.newContext()).newPage({ viewport: { width: 1500, height: 1000 } });
  p.on('dialog', d => d.accept()); const errs = []; p.on('pageerror', e => errs.push(e.message));
  await p.goto(BASE + '/index.php'); await sleep(2500);
  const res = await p.evaluate(({ COMBOS, LEGACY }) => {
    const fire = (u, k, v) => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); if (!el) return false; if (el.type === 'checkbox') el.checked = v; else el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); return true; };
    const enc = (o, key) => { const c = o && (o[key]); if (!c) return null; const md = c.mode; const t = c.at || c.start_at || c.stop_at; return (md === 'start_at' || md === 'stop_at') ? md + '@' + t : md; };
    const pay = u => { const x = assembleInput(); const m = x.data3.modeling; return { uld: (m.unit_last_data_status || {})[u.toUpperCase()] || 'Stop', rm: enc(m.required_mode || {}, u), sm: enc(m.stop_mode || {}, u), cs: (m.unit_cannot_stop || []).includes(u), ru: (m.required_units || []).includes(u), _x: x }; };
    const st = u => ({ COMMIT: COMMIT_MODE[u], STOP: STOP_MODE[u], START_AT: START_AT[u] || '', STOP_AT: STOP_AT[u] || '' });
    const reset = u => { fire(u, 'req', false); if (COMMIT_MODE[u] !== '-') { COMMIT_MODE[u] = '-'; } fire(u, 'stopmode', '-'); fire(u, 'uld', 'Stop'); };
    const orig = JSON.parse(JSON.stringify(INPUT)); const rows = [];
    for (const u of FLAG_UNITS) for (const [k, c] of Object.entries(COMBOS)) {
      INPUT = JSON.parse(JSON.stringify(orig)); COMMIT_MODE = {}; initUnitFlags(); renderUnitFlags(); reset(u);
      if (c.pre) { fire(u, 'req', true); fire(u, 'mode', c.pre); }
      if (c.mode !== '-') { fire(u, 'req', true); fire(u, 'mode', c.mode); } else fire(u, 'req', false);
      if (c.at) fire(u, 'startat', c.at);
      if (c.uld) fire(u, 'uld', c.uld);
      if (c.then) fire(u, 'uld', c.then);
      if (c.stop) fire(u, 'stopmode', c.stop);
      if (c.stopAt) fire(u, 'stopat', c.stopAt);
      const p1 = pay(u); const s1 = st(u);
      INPUT = JSON.parse(JSON.stringify(p1._x)); COMMIT_MODE = {}; initUnitFlags(); renderUnitFlags();
      const s2 = st(u); const p2 = pay(u); delete p1._x; delete p2._x;
      const bad = [];
      for (const f of Object.keys(c.exp)) if (p1[f] !== c.exp[f]) bad.push(`${f}=${p1[f]} (exp ${c.exp[f]})`);
      if (JSON.stringify(s1) !== JSON.stringify(s2)) bad.push(`reload state ${JSON.stringify(s1)} -> ${JSON.stringify(s2)}`);
      if (JSON.stringify(p1) !== JSON.stringify(p2)) bad.push(`reload payload ${JSON.stringify(p1)} -> ${JSON.stringify(p2)}`);
      rows.push({ unit: u, combo: k, ok: !bad.length, payload: p1, bad });
    }
    for (const u of FLAG_UNITS) for (const [k, c] of Object.entries(LEGACY)) {
      const x = JSON.parse(JSON.stringify(orig)); const m = x.data3.modeling;
      m.required_mode = Object.assign({}, m.required_mode || {}); delete m.required_mode[u]; m.stop_mode = Object.assign({}, m.stop_mode || {}); delete m.stop_mode[u];
      m.unit_cannot_stop = (m.unit_cannot_stop || []).filter(v => v !== u); m.required_units = (m.required_units || []).filter(v => v !== u); m.stop_policy = {};
      if (c.m.rm) m.required_mode[u] = c.m.rm; if (c.m.sm) m.stop_mode[u] = c.m.sm; if (c.m.cs) m.unit_cannot_stop.push(u); if (c.m.ru) m.required_units.push(u); if (c.m.pol) m.stop_policy[u] = c.m.pol;
      m.unit_last_data_status = Object.assign({}, m.unit_last_data_status || {}); m.unit_last_data_status[u.toUpperCase()] = c.m.uld;
      INPUT = x; COMMIT_MODE = {}; initUnitFlags(); renderUnitFlags(); const s = st(u); const pp = pay(u); delete pp._x;
      const bad = []; if (s.COMMIT !== c.exp.COMMIT) bad.push(`COMMIT=${s.COMMIT} (exp ${c.exp.COMMIT})`); if (s.STOP !== c.exp.STOP) bad.push(`STOP=${s.STOP} (exp ${c.exp.STOP})`);
      if (c.exp.COMMIT !== 'continuous' && (pp.cs || pp.rm === 'continuous')) bad.push('payload became continuous ' + JSON.stringify(pp));
      if (c.exp.COMMIT !== 'continuous' && c.m.uld === 'Stop' && pp.uld !== 'Stop') bad.push('Last Data forced ' + pp.uld);
      rows.push({ unit: u, combo: k, ok: !bad.length, payload: pp, bad });
    }
    INPUT = orig; COMMIT_MODE = {}; initUnitFlags(); renderUnitFlags();
    return rows;
  }, { COMBOS, LEGACY });
  // S: real Save -> reload (G9 Sim+Hold, G4 hold-only, G8 Continuous, G5 Request 04:00 + Stop 20:00)
  await p.evaluate(() => { const f = (u, k, v) => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); if (el.type === 'checkbox') el.checked = v; else el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); };
    f('g9', 'req', true); f('g9', 'mode', 'simulation'); f('g9', 'stopmode', 'continuous');
    f('g4', 'req', false); f('g4', 'uld', 'Stop'); f('g4', 'stopmode', 'continuous');
    f('g8', 'req', true); f('g8', 'mode', 'continuous');
    f('g5', 'req', true); f('g5', 'mode', 'request'); f('g5', 'startat', '04:00'); f('g5', 'stopmode', 'request'); f('g5', 'stopat', '20:00'); });
  const before = await p.evaluate(() => ['g9', 'g4', 'g8', 'g5'].map(u => [u, COMMIT_MODE[u], STOP_MODE[u], START_AT[u] || '', STOP_AT[u] || '', (document.getElementById('uld-' + u.toUpperCase()) || {}).value]));
  await p.evaluate(() => saveInput()); await sleep(3000); const msg = await p.evaluate(() => ((document.getElementById('run-msg') || {}).textContent || '').slice(0, 160));
  await p.reload(); await sleep(3000);
  const after = await p.evaluate(() => ['g9', 'g4', 'g8', 'g5'].map(u => [u, COMMIT_MODE[u], STOP_MODE[u], START_AT[u] || '', STOP_AT[u] || '', (document.getElementById('uld-' + u.toUpperCase()) || {}).value]));
  // second reload (stale-state check: two load cycles must not drift)
  await p.reload(); await sleep(3000);
  const after2 = await p.evaluate(() => ['g9', 'g4', 'g8', 'g5'].map(u => [u, COMMIT_MODE[u], STOP_MODE[u], START_AT[u] || '', STOP_AT[u] || '', (document.getElementById('uld-' + u.toUpperCase()) || {}).value]));
  const S = { before, after, after2, save_msg: msg, ok: JSON.stringify(before) === JSON.stringify(after) && JSON.stringify(after) === JSON.stringify(after2) };
  const fail = res.filter(r => !r.ok);
  fs.writeFileSync(OUT, JSON.stringify({ total: res.length, fail: fail.length, rows: res, save_reload: S, errs }, null, 1));
  console.log('matrix', res.length, 'fail', fail.length, '| save/reload', S.ok ? 'PASS' : 'FAIL', '| pageerrors', errs.length);
  fail.slice(0, 40).forEach(r => console.log(' ', r.unit, r.combo, r.bad.join('; ')));
  console.log(' before', JSON.stringify(before)); console.log(' after ', JSON.stringify(after)); console.log(' after2', JSON.stringify(after2));
  await b.close();
})();
