// cs_srv_bench.js <base> <payload.json> <label> <out.json> : server path exactly as the browser drives it
//   job_start(kind=economic_review) -> job_exec (owner) + job_help x helpers (slots from server) -> job_poll every 250 ms until DONE
const http = require('http'); const fs = require('fs'); const [,, BASE, PAY, LABEL, OUT] = process.argv;
const req = (path, body) => new Promise((ok, ko) => { const u = new URL(BASE + '/' + path);
  const r = http.request({ host: u.hostname, port: u.port, path: u.pathname + u.search, method: body ? 'POST' : 'GET', headers: body ? { 'Content-Type': 'application/json' } : {} }, res => { const c = []; res.on('data', d => c.push(d)); res.on('end', () => { try { ok(JSON.parse(Buffer.concat(c).toString())); } catch (e) { ok({ raw: Buffer.concat(c).toString().slice(0, 300) }); } }); });
  r.on('error', ko); if (body) r.write(body); r.end(); });
(async () => {
  const p = JSON.parse(fs.readFileSync(PAY)); p._fast_default = true; p._request_id = 'bench-' + Date.now(); p._context = 'plan';
  const t0 = Date.now(); const cj = await req('run.php?mode=job_start&kind=economic_review', JSON.stringify(p));
  if (!cj.ok) { console.log('job_start failed', JSON.stringify(cj).slice(0, 300)); process.exit(1); }
  const id = cj.job.job_id, tok = cj.job.exec_token, hash = cj.input_hash || cj.job.input_hash || ''; const nh = Math.max(0, Math.min(7, +cj.job.helpers || 0));
  req('run.php?mode=job_exec&job=' + encodeURIComponent(id) + '&token=' + encodeURIComponent(tok)).catch(() => {});
  for (let k = 1; k <= nh; k++) req('run.php?mode=job_help&job=' + encodeURIComponent(id) + '&token=' + encodeURIComponent(tok) + '&slot=' + k).catch(() => {});
  let sj = null;
  for (let n = 0; n < 4000; n++) { await new Promise(r => setTimeout(r, 250)); sj = await req('run.php?mode=job_poll&job=' + encodeURIComponent(id) + '&input_hash=' + encodeURIComponent(hash));
    const st = sj && sj.job && sj.job.status; if (st === 'DONE' && sj.result) break; if (/FAIL|ERROR|CANCEL/i.test(st || '')) break; }
  const wall = (Date.now() - t0) / 1000; const res = (sj && sj.result) || {}; const o = res.output || {}; const i = o.info || {}; const rows = o.data || [];
  const T = r => { const m = (r * 30) % 1440; return String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0'); };
  const iv = c => { const q = rows.map((x, k) => (+x[c] || 0) > 0.01 ? k + 1 : -1).filter(k => k > 0); return q.length ? T(q[0]) + '-' + T(q[q.length - 1]) + '/' + q.length : 'OFF'; };
  const d = i['Runtime Platform Diagnostics'] || {}; const rs = i['Run Status'] || res.run_status || {};
  const sp = (i['Start Priority Stage Profile'] || {}).stages || [];
  const r = { label: LABEL, wall, job_wall_s: res.wall_s, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], status: rs.status, core_simulations: rs.core_simulations, core_runs: rs.core_runs,
    hard: (res.hard_validation || {}).status, publish: res.publish_allowed, g9: iv('G9'), g4: iv('G4'), trace: (i['Commitment Semantics Trace'] || {}).status, helpers: nh,
    tasks: d.tasks_total, workers: d.workers, max_conc: d.max_concurrency, task_busy_s: d.task_busy_s, task_span_s: d.task_span_s, tasks_by_process: d.tasks_by_process,
    phases: (d.phase_timeline || []).map(x => [x.phase, x.delta_s, x.core_delta]), sp_stages: sp.map(s => [s.stage, s.s, s.cand ? JSON.stringify(s.cand) : '']),
    sig: require('crypto').createHash('md5').update(JSON.stringify(rows.map(x => ['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'].map(c => Math.round((+x[c] || 0) * 1000) / 1000)))).digest('hex').slice(0, 12) };
  fs.writeFileSync(OUT, JSON.stringify(r, null, 1));
  console.log(LABEL, 'wall', wall.toFixed(2), 'job', res.wall_s, 'cp', r.cp, 'hr', r.hr, r.status, 'core', r.core_simulations, 'hard', r.hard, 'G9', r.g9, 'G4', r.g4, 'trace', r.trace, 'helpers', nh, 'tasks', r.tasks, 'workers', r.workers, 'busy', r.task_busy_s, 'span', r.task_span_s, 'sig', r.sig);
})();
