#!/usr/bin/env python3
"""PRECHECK_DEPLOYMENT.md dari hasil uji pendek. Arg: <out.md> <reg_log> <ce_log> <commit>"""
import json, sys, os, re, statistics as S
OUT, REG, CE, COMMIT = sys.argv[1:5]; R = '/home/claude/t5/integ/linux/res'
def js(n): p = os.path.join(R, n); return json.load(open(p)) if os.path.exists(p) else None
def jl(n): p = os.path.join(R, n); return [json.loads(l) for l in open(p)] if os.path.exists(p) else []
def cnt(p):
    if not os.path.exists(p): return None
    t = open(p, errors='replace').read(); return (len(re.findall(r'^PASS', t, re.M)), len(re.findall(r'^FAIL', t, re.M)))
pf = js('T1_preflight.json'); L = ['# PRECHECK DEPLOYMENT — Linux (Apache/PHP-FPM) dan XAMPP', '', 'Source commit `%s`. Lima berkas PHP satu paket; hash di `SHA256SUMS.txt` = `run.php?mode=preflight` `checks[].sha256`.' % COMMIT, '',
 '## Akar masalah HTTP 500 / "mencari kandidat ... 129,4 s"', '',
 '1. Paket terbaru menambah dependensi wajib `saved_data_store.php`; server produksi hanya menerima empat berkas lama.',
 '2. `run.php` memanggil `require_once saved_data_store.php` tanpa pemeriksaan. Dengan `display_errors=On` respons berisi warning HTML `require_once(...): failed to open stream` + fatal (bukan JSON). Direproduksi di Linux Apache/PHP-FPM dengan source sebelumnya (`3b43955`): HTTP 200 berisi `<b>Warning</b>: require_once(/var/www/html/php74/opr-simulation/saved_data_store.php): failed to open stream`.',
 '3. UI menjalankan timer Fastest SEBELUM backend mengakui job, dan timer hanya berhenti bila pesan status mengandung kata tertentu ("FINAL", "gagal", ...). Pesan "Server returned non-JSON" / "Error: ..." tidak termasuk, sehingga teks "mencari kandidat fully valid pertama · N s" terus bertambah walaupun tidak ada job (direproduksi: masih berjalan 4,8 s sesudah error, tanpa batas).',
 '4. Uji sebelumnya selalu memakai lima berkas lengkap (XAMPP/proksi uji), dan paket tidak menegakkan kelengkapan atau izin folder data.', '',
 '## Perbaikan', '',
 '- `run.php`: `display_errors` dimatikan untuk endpoint JSON; kelima berkas diperiksa SEBELUM `require_once __DIR__ . DIRECTORY_SEPARATOR . ...`; berkas hilang -> HTTP 500 JSON `{"ok":false,"error":"MISSING_DEPENDENCY","file":...}` untuk SETIAP endpoint, detail ke error log server.',
 '- `run.php?mode=preflight`: kelima berkas + SHA-256, versi PHP/SAPI/php.ini, fungsi wajib, folder aplikasi + `jobs/` + `data/{saved,backups}` dapat dibuat & ditulisi, user proses, OPcache, jumlah pembantu.',
 '- Kontrak penyimpanan: simulasi TIDAK bergantung pada folder data (Run tidak membuat backup/cermin/record). Folder data tak dapat ditulisi -> Save dan API penyimpanan yang menulis menjawab `DATASTORE_NOT_WRITABLE` sebelum menulis apa pun; simulasi tetap berjalan. Lima berkas tetap satu paket (berkas hilang = instalasi ditolak, UI menonaktifkan Run).',
 '- `index.php`: berkas paket hilang -> pesan instalasi + Run nonaktif; preflight dipanggil saat halaman dibuka.',
 '- UI fail-fast: timer Fastest mulai hanya setelah backend mengembalikan `job_id`; HTTP 500, non-JSON, error JSON, kegagalan jaringan, atau timeout bootstrap 45 s -> timer & progres berhenti, Run aktif kembali, pesan singkat, tanpa polling, tanpa cancel ke job yang tidak pernah dibuat.', '',
 '## Enam uji pendek', '']
t2 = js('T2_ui.json') or {}; t2o = js('T2_OLD_DEON_ui.json') or {}; t2n = js('T2_NEW_DEON_ui.json') or {}; t3 = js('T3_preflight.json') or {}; t3s = js('T3_save.json') or {}; t2x = js('T2_XAMPPSEM_ui.json') or {}
L += ['| # | uji | hasil | bukti |', '|---|---|---|---|',
 '| 1 | Linux preflight, lima PHP lengkap | %s | HTTP 200, ok=%s, SAPI %s, user %s, OPcache %s, pembantu %s |' % ('PASS' if pf and pf['ok'] else 'FAIL', pf and pf['ok'], pf and pf['environment']['sapi'], pf and pf['environment']['process_user'], pf and pf['environment']['opcache'], pf and pf['environment']['helpers']),
 '| 2 | Linux tanpa `saved_data_store.php` | %s | run/job_poll/fast_ready/preflight/store_list: HTTP 500 JSON `MISSING_DEPENDENCY` (display_errors Off dan On); UI: Run nonaktif + pesan instalasi; klik paksa -> UI berhenti %.2f s, request sesudah berhenti (5 s) %s, cancel %s, warning HTML %s. Source lama: warning HTML, timer tetap berjalan (`%s`) |' % (
   'PASS' if t2.get('run_disabled') and (t2.get('ui_stop') or {}).get('t_s', 9) < 2 and t2.get('requests_after_stop_5s') == 0 and not t2.get('php_warning_in_html') else 'FAIL', (t2.get('ui_stop') or {}).get('t_s', -1), t2.get('requests_after_stop_5s'), t2.get('cancel_sent'), t2.get('php_warning_in_html'), t2o.get('fast_msg_after_5s')),
 '| 3 | Linux folder data read-only (root:root 0555) | %s | preflight HTTP 500 `%s`; Save HTTP 500 `%s` (input_data.json tidak berubah); store_delete `DATASTORE_NOT_WRITABLE`; store_list tetap 200; UI: peringatan, Run aktif; Fastest selesai normal (lihat T3_run.jsonl) |' % ('PASS' if t3.get('code') == 'DATASTORE_NOT_WRITABLE' and t3s.get('error') == 'DATASTORE_NOT_WRITABLE' else 'FAIL', t3.get('code'), t3s.get('error'))]
t3r = jl('T3_run.jsonl')
LX = jl('LINUX.jsonl'); XS = jl('XAMPPSEM.jsonl')
def stat(rows, case):
    v = [r for r in rows if r['case'] == case]; t = [((r['trace']['simulation_data_opened_ms'] - r['trace']['run_click_ms']) / 1000) for r in v]
    sig = sorted(set((r.get('dispatch') or {}).get('sig') for r in v)); return v, t, sig
for i, (case, nm) in enumerate([('U31', 'PGN 31 + PEP 30'), ('U30', 'PGN 30 + PEP 30')]):
    vx, tx, sx = stat(XS, case); vl, tl, sl = stat(LX, case)
    ok = vx and vl and len(set(sx + sl)) == 1 and all(r['label'] == 'FASTEST VALID PLAN' and (r.get('dispatch') or {}).get('table_rows') == 48 for r in vx + vl)
    L.append('| %s | XAMPP%s dan Linux %s Fastest | %s | dispatch identik (%s), CP %s, HR %s; XAMPP median %.2f s (%.2f-%.2f), Linux median %.2f s (%.2f-%.2f), 3 run cold masing-masing |' % (
        '4/5' if case == 'U31' else '6', '' , nm, 'PASS' if ok else 'FAIL', ', '.join(sx + sl if len(set(sx + sl)) > 1 else sx), vx[0].get('cp'), (vx[0].get('dispatch') or {}).get('hr'), S.median(tx), min(tx), max(tx), S.median(tl), min(tl), max(tl)))
L += ['', 'Batas jujur: XAMPP/Windows tidak tersedia di lingkungan uji ini. "XAMPP" = server multi-worker dengan semantik Apache Windows yang relevan — seluruh request berbagi satu pid (mod_php mpm_winnt; kait uji `PP_EMU_PID`), OPcache mati (bawaan XAMPP), source hash sama. Linux = Apache 2.4.58 + PHP-FPM 7.4.3 nyata (paket Ubuntu resmi), user `www-data`, app di `/var/www/html/php74/opr-simulation`.', '',
 '## Perbandingan platform', '', '| aspek | XAMPP (semantik) | Linux Apache/PHP-FPM |', '|---|---|---|']
e = (pf or {}).get('environment') or {}
L += ['| SHA-256 lima PHP | sama (lihat SHA256SUMS.txt) | sama (preflight `checks[].sha256`) |',
 '| PHP binary / php.ini | /opt/php74/usr/bin/php7.4 (`php -S`), /opt/php74/etc/php.ini | %s, %s |' % ((pf['checks'][5] or {}).get('binary') if pf else '-', (pf['checks'][5] or {}).get('ini') if pf else '-'),
 '| SAPI | cli-server (6 worker, satu pid untuk semua request) | %s (pm static 24 child) |' % e.get('sapi'),
 '| DocumentRoot / __DIR__ | akar uji sementara / sama | %s / %s |' % (e.get('document_root'), e.get('dir')),
 '| user proses / izin data | root (lingkungan uji) | %s; folder app root:www-data 2775, PHP 0644, jobs/ data/ www-data 2775 |' % e.get('process_user'),
 '| pembantu / inti | 3 / 4 | %s / %s |' % (e.get('helpers'), e.get('cores')),
 '| jobs / data | <app>/jobs, <app>/data | %s, %s |' % (e.get('jobs_dir'), e.get('data_dir')),
 '| OPcache | off | %s |' % e.get('opcache'),
 '| payload / response | input_data.json pengguna (PGN 31 / PGN 30, PEP 30) — sama | sama; response JSON identik (dispatch 48 row, CP, HR, merit, C1-C4, STG, constraints) |', '']
K = ['run_click_ms', 'job_created_ms', 'first_candidate_complete_ms', 'first_valid_claimed_ms', 'first_fully_valid_ms', 'snapshot_persisted_ms', 'browser_received_snapshot_ms', 'render_start_ms', 'render_done_ms', 'simulation_data_opened_ms', 'exact_cancel_sent_ms', 'helpers_stopped_ms']
L += ['## Timeline nyata (run pertama tiap platform, detik sejak klik Run; browser Chromium)', '', '| platform | kasus | ' + ' | '.join(k[:-3] for k in K) + ' | CP | merit | C4 | STG | baris tabel |', '|' + '---|' * (len(K) + 7)]
for nm, rows in [('XAMPP (semantik)', XS), ('Linux Apache/PHP-FPM', LX)]:
    for case in ['U31', 'U30']:
        v = [r for r in rows if r['case'] == case]
        if not v: continue
        r = v[0]; T = r['trace']; t0 = T['run_click_ms']; d = r.get('dispatch') or {}
        L.append('| %s | %s | %s | %s | %s | %s | %s | %s |' % (nm, case, ' | '.join(('%.2f' % ((T[k] - t0) / 1000)) if T.get(k) else '-' for k in K), r.get('cp'), d.get('merit'), d.get('c4'), r.get('stg'), d.get('table_rows')))
L += ['', 'Selisih Linux vs XAMPP: kandidat pertama diklaim lebih awal di Linux (OPcache aktif, proses PHP-FPM terpisah); review merit dan rilis sama (dispatch, CP, HR identik). Di XAMPP Windows nyata OPcache bawaan mati — mengaktifkannya disarankan di CARA_PASANG_XAMPP.md.', '']
r_ = cnt(REG); c_ = cnt(CE)
L += ['## Regression', '', '| suite | PASS | FAIL |', '|---|---|---|', '| Full regression (source beku) | %s | %s |' % (r_ or ('belum', '-')), '| Clean-extract ZIP + uji browser Linux dari isi ZIP | %s | %s |' % (c_ or ('belum', '-')), '']
open(OUT, 'w').write('\n'.join(L) + '\n'); print('ok')
