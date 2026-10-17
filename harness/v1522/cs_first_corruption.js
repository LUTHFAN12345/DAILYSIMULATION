// cs_first_corruption.js <base> <srvdir> <seed.json> <unit> <out.json>
// Record lama (Initial Stop + Start Based On Simulation + Stop Status Continuous, unit_cannot_stop berisi unit = bentuk V15.17 / model
// ternormalisasi) dipasang sebagai data server -> tahap: [1] kontrol UI  [2] state UI  [3] payload  [4] Save -> data tersimpan (dibaca ulang
// dari server)  [5] Reload  [6] payload Run berikutnya. Tahap worker (7..13) dijalankan oleh cs_first_corruption_engine.php atas payload [6].
const { chromium } = require('playwright'); const fs = require('fs'); const path = require('path');
const [,, BASE, SRV, SEED, U, OUT] = process.argv; const sleep = ms => new Promise(r => setTimeout(r, ms));
const seed = JSON.parse(fs.readFileSync(SEED)); const m = seed.data3.modeling; const UU = U.toUpperCase();
m.required_mode = Object.assign({}, m.required_mode || {}); m.stop_mode = Array.isArray(m.stop_mode) ? {} : Object.assign({}, m.stop_mode || {});
m.required_mode[U] = { mode: 'based_on_sim' }; m.stop_mode[U] = { mode: 'hold_to_end' }; m.unit_last_data_status[UU] = 'Stop';
m.unit_cannot_stop = Array.from(new Set([...(m.unit_cannot_stop || []).filter(x => x !== U), U])); m.required_units = Array.from(new Set([...(m.required_units || []), U]));
fs.writeFileSync(path.join(SRV, 'input_data.json'), JSON.stringify(seed));
for (const f of fs.readdirSync(path.join(SRV, 'saved'))) fs.rmSync(path.join(SRV, 'saved', f), { recursive: true, force: true });
const pick = mm => ({ uld: (mm.unit_last_data_status || {})[UU] || null, required_mode: (mm.required_mode || {})[U] || null, stop_mode: (mm.stop_mode || {})[U] || null,
  in_unit_cannot_stop: (mm.unit_cannot_stop || []).includes(U), in_required_units: (mm.required_units || []).includes(U) });
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] }); const ctx = await b.newContext(); const p = await ctx.newPage({ viewport: { width: 1500, height: 1000 } });
  p.on('dialog', d => d.accept()); const st = {};
  const probe = async () => p.evaluate(u => { const row = k => { const el = document.querySelector(`[data-flag="${k}"][data-u="${u}"]`); return el ? (el.type === 'checkbox' ? el.checked : el.value) : null; };
    return { controls: { uld: (document.getElementById('uld-' + u.toUpperCase()) || {}).value, required: row('req'), commitment_mode: row('mode'), stop_status: row('stopmode') },
             state: { COMMIT_MODE: COMMIT_MODE[u], STOP_MODE: STOP_MODE[u], CANNOT_STOP: CANNOT_STOP.includes(u) } }; }, U);
  st.r0_record_on_server = pick(m);
  await p.goto(BASE + '/index.php'); await sleep(2500);
  const a = await probe(); st.s1_ui_controls_after_load = a.controls; st.s2_ui_state_after_load = a.state;
  st.s3_payload = pick(await p.evaluate(() => assembleInput().data3.modeling));
  await p.evaluate(() => saveInput()); await sleep(3000);
  const savedFiles = []; const walk = d => { for (const f of fs.readdirSync(d)) { const q = path.join(d, f); if (fs.statSync(q).isDirectory()) walk(q); else savedFiles.push(q); } }; walk(path.join(SRV, 'saved'));
  let savedRec = null; for (const f of savedFiles) { try { const j = JSON.parse(fs.readFileSync(f)); const x = j.data3 ? j : (j.payload || j.input || j.data || null); if (x && x.data3) { savedRec = x; break; } } catch (e) {} }
  st.s4_saved_record = savedRec ? pick(savedRec.data3.modeling) : { note: 'datastore bukan file JSON polos', files: savedFiles.map(f => path.basename(f)).slice(0, 5) };
  await p.reload(); await sleep(3000);
  const c = await probe(); st.s5_ui_after_reload = c;
  const pay = await p.evaluate(() => assembleInput()); st.s6_payload_next_run = pick(pay.data3.modeling);
  fs.writeFileSync(OUT.replace(/\.json$/, '.payload.json'), JSON.stringify({ data1: pay.data1, data2: pay.data2, data3: pay.data3, _fast_default: true }));
  fs.writeFileSync(OUT, JSON.stringify(st, null, 1)); console.log(JSON.stringify(st)); await b.close();
})();
