// hrun.js <base> <payload.json> : Run tanpa browser (meniru index.php): POST mode=run, job_exec, job_help x helpers, poll job_poll 150 ms.
// Cetak: total klik->DONE (ms), waktu job server, CP, mode.
const http = require('http'); const fs = require('fs'); const [,, BASE, PF] = process.argv; const u = new URL(BASE);
const req = (method, path, body) => new Promise((res, rej) => { const r = http.request({ host: u.hostname, port: u.port, path, method, headers: body ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } : {} }, rs => { const ch = []; rs.on('data', d => ch.push(d)); rs.on('end', () => { try { res(JSON.parse(Buffer.concat(ch).toString())); } catch (e) { res({ raw: Buffer.concat(ch).toString().slice(0, 300) }); } }); }); r.on('error', rej); if (body) r.write(body); r.end(); });
(async () => { const p = JSON.parse(fs.readFileSync(PF)); p._fast_default = true; p._maximum_review = false; const uid = 'uh' + Date.now(), tab = 't' + Date.now(); p._autosave = true; p._request_id = 'h-' + Date.now();
  const t0 = Date.now(); const r = await req('POST', '/run.php?mode=run&uid=' + uid + '&tab=' + tab, JSON.stringify(p)); const job = r.async_job || {}; if (!job.job_id) { console.log('NOJOB', JSON.stringify(r).slice(0, 400)); return; }
  req('GET', '/run.php?mode=job_exec&job=' + encodeURIComponent(job.job_id) + '&token=' + encodeURIComponent(job.exec_token)).catch(() => {});
  for (let k = 1; k <= (job.helpers || 0); k++) req('GET', '/run.php?mode=job_help&job=' + encodeURIComponent(job.job_id) + '&token=' + encodeURIComponent(job.exec_token) + '&slot=' + k).catch(() => {});
  for (;;) { await new Promise(r => setTimeout(r, 150)); const sj = await req('GET', '/run.php?mode=job_poll&job=' + encodeURIComponent(job.job_id) + '&input_hash=' + encodeURIComponent(job.input_hash || ''));
    const j = sj.job || {}; if (j.status === 'DONE' && sj.result) { const o = sj.result.output || {}; const i = o.info || {};
      console.log(JSON.stringify({ t_done: (Date.now() - t0) / 1000, job_wall: sj.result.wall_s, cp: i['Cost Production (USD/MWh)'], hr: i['JBBK MM Heat Rate (BTU/kWh)'], helpers: job.helpers, mode: (i['Run Status'] || {}).mode, status: o.status || null })); return; }
    if (j.status === 'FAILED' || Date.now() - t0 > 200000) { console.log('FAIL', JSON.stringify(j).slice(0, 300)); return; } } })();
