/* Proksi uji: meniru server web multi-thread (Apache/XAMPP). Setiap request diteruskan ke backend
 * `php -S` yang sedang TIDAK melayani request lain, sehingga request ringan (poll, kolam kandidat)
 * tidak pernah antre di belakang request job_exec yang panjang pada worker yang sama.
 * node proxy.js <port> <root> <n_backend> */
const http = require('http'); const { spawn } = require('child_process');
const [,, PORT, ROOT, NB] = process.argv; const N = +(NB || 6); const base = +PORT + 100;
const be = []; for (let i = 0; i < N; i++) {
  const port = base + i;
  const env = Object.assign({}, process.env); delete env.PHP_CLI_SERVER_WORKERS;
  const pr = spawn('/usr/local/bin/php74', ['-d', 'max_execution_time=30', '-S', '127.0.0.1:' + port, '-t', ROOT], { cwd: ROOT, env, stdio: 'ignore' });
  be.push({ port, busy: 0, pr });
}
const pick = () => { let b = be[0]; for (const x of be) if (x.busy < b.busy) b = x; return b; };
const srv = http.createServer((req, res) => {
  const b = pick(); b.busy++;
  /* INTEGRASI: suite UI lama ditulis untuk Maximum Review (bawaan lama). PP_LEGACY_TARGET=max meniru pengguna yang sebelumnya
   * memilih Maximum Review: localStorage pp_tl_target diisi HANYA bila kosong. Default produk (Fastest - Default) tidak diubah. */
  const legacy = process.env.PP_LEGACY_TARGET && req.method === 'GET' && /^\/(index\.php)?(\?|$)/.test(req.url);
  const up = http.request({ host: '127.0.0.1', port: b.port, path: req.url, method: req.method, headers: req.headers }, r => {
    if (!legacy) { res.writeHead(r.statusCode, r.headers); r.pipe(res); r.on('end', () => { b.busy--; }); return; }
    const ch = []; r.on('data', d => ch.push(d)); r.on('end', () => { b.busy--;
      let body = Buffer.concat(ch).toString('utf8');
      body = body.replace(/<head([^>]*)>/i, m => m + "<script>try{if(!localStorage.getItem('pp_tl_target'))localStorage.setItem('pp_tl_target','" + process.env.PP_LEGACY_TARGET + "')}catch(e){}</script>");
      const h = Object.assign({}, r.headers); delete h['content-length']; res.writeHead(r.statusCode, h); res.end(body); });
  });
  up.on('error', e => { b.busy--; try { res.writeHead(502); res.end('proxy error ' + e.message); } catch (x) {} });
  req.pipe(up);
});
srv.keepAliveTimeout = 1000;
setTimeout(() => srv.listen(+PORT, '127.0.0.1'), 1200);
const stop = () => { for (const x of be) try { x.pr.kill(); } catch (e) {} process.exit(0); };
process.on('SIGTERM', stop); process.on('SIGINT', stop);
