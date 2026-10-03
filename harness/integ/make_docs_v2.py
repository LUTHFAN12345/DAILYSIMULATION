#!/usr/bin/env python3
"""Laporan V2. Arg: <out_dir> <tgt_log> <reg_log> <ce_log> <commit>"""
import json, sys, os, re, statistics as S
OUT, TGT, REG, CE, COMMIT = sys.argv[1:6]; B = '/home/claude/t5/integ'
def cnt(p):
    if not os.path.exists(p): return None
    t = open(p, errors='replace').read(); return {'pass': len(re.findall(r'^PASS', t, re.M)), 'fail': len(re.findall(r'^FAIL', t, re.M)), 'fails': re.findall(r'^FAIL.*$', t, re.M)[:30]}
def rows(p): return [json.loads(l) for l in open(p)] if os.path.exists(p) else []
K = [('run_click_ms', 'run_click'), ('job_created_ms', 'job_created'), ('first_candidate_complete_ms', 'first_candidate_complete'), ('first_valid_claimed_ms', 'first_valid (klaim)'), ('first_fully_valid_ms', 'first_fully_valid'),
     ('snapshot_persisted_ms', 'snapshot_written'), ('browser_received_snapshot_ms', 'browser_received'), ('render_start_ms', 'render_start'), ('render_done_ms', 'render_done'), ('simulation_data_opened_ms', 'simulation_data_opened'),
     ('exact_cancel_sent_ms', 'exact_cancel_sent'), ('helpers_stopped_ms', 'helpers_stopped')]
def tl(r):
    T = r.get('trace') or {}; t0 = T.get('run_click_ms') or 0; c = []
    for k, n in K: c.append('%.2f' % ((T[k] - t0) / 1000) if T.get(k) else '-')
    return c
def ui(r):
    T = r.get('trace') or {}; t0 = T.get('run_click_ms') or 0
    return (T['simulation_data_opened_ms'] - t0) / 1000 if T.get('simulation_data_opened_ms') else r['t_show_s']
NM = {'U31': 'PGN 31 + PEP 30 (input pengguna)', 'U30': 'PGN 30 + PEP 30 (input pengguna)'}
L = ['# Fastest, Merit, Distillate, Datastore — V2 (laporan akar masalah 62 detik)', '', 'Branch `claude/gifted-ritchie-q0hfdp`, commit source beku `%s`. Input reproducer: `input_data.json` pengguna (PGN 31, PEP 30, KP72 0, Unit Priority G8/G9 > G1/G5/G2 > ...); varian PGN 30 = input yang sama dengan PGN Pipe 30. Pembanding hasil aktual: `Daily_Plan_09_Jul_26_Baru(9).xls`.' % COMMIT, '']
L += ['## 1. Akar masalah 62 detik (browser XAMPP) vs < 10 detik (uji internal)', '',
 'Hasil xls pengguna = CP 64,7178 — identik dengan hasil Fastest source `940af0a` di mesin uji untuk input yang sama (CP, G2 26-37, G5 tidak running). Jadi mesin pengguna menjalankan jalur yang sama; selisih waktunya berasal dari lingkungan Windows/XAMPP dan dari pekerjaan sesudah klaim kandidat pertama. Label "TIME-LIMITED VALID PLAN" di xls BUKAN tanda fallback: keluaran Fastest membawa `time_limited=true`, dan header ekspor mencetak label itu untuk setiap hasil Fastest (diperbaiki: kini "FASTEST VALID PLAN").', '',
 'Temuan, berurutan menurut dampak:', '',
 '1. **pid dipakai sebagai identitas proses — salah di Apache Windows.** Di XAMPP (mod_php, mpm_winnt) seluruh request adalah thread dari SATU proses, sehingga `getmypid()` sama untuk pemilik job, pembantu, dan pengklaim. Akibatnya (a) pembantu menganggap tugas review yang diterbitkan pengklaim sebagai "tugas terbitan sendiri" dan tidak pernah mengambilnya — seluruh counterfactual review dihitung serial oleh satu thread; (b) pemilik pencarian exact tidak pernah berhenti setelah klaim (perbandingan pid selalu "saya sendiri"), sehingga pencarian exact terus berebut CPU dengan review; (c) berkas sementara result.json bernama pid saja. Diperbaiki dengan identitas request (`pp_req_id`). Diuji dengan kait `PP_EMU_PID` (semua request satu pid, meniru Apache Windows).',
 '2. **Sesudah klaim, review merit Fastest masih mencari CP lebih baik.** Kandidat valid pertama input pengguna men-start G5 (sesuai Unit Priority). Review menerapkan stop pada row legal pertama (benar), lalu SWAP G5 -> G2 (unit prioritas LEBIH RENDAH) karena band CP 0,2 % memutus seri dengan Heat Rate sebelum Unit Priority: CP 0,0027 USD/MWh (0,004 %) lebih murah, Heat Rate 0,86 BTU/kWh lebih rendah. Setiap counterfactual adalah pendaratan dispatch 48 row (3,5-4,7 s per kandidat di mesin uji). Inilah sumber utama waktu dan sumber G2 start sebelum G5. Diperbaiki: (a) review Fastest = mode bukti — SWAP ke peer berprioritas lebih rendah (uji ekonomi, bukan bukti merit C2/C3) tidak dievaluasi; (b) di dalam band, Unit Priority start didahulukan sebelum Heat Rate (juga untuk Maximum Review).',
 '3. **Pembantu terus mencari exact sesudah klaim** dan baru mengambil tugas review sekitar 3 s kemudian. Kini begitu klaim terlihat, pembantu meninggalkan pekerjaan exact pemilik dan hanya mengerjakan tugas yang diterbitkan pengklaim.',
 '4. **Arsip Report (`report_planning`) 531 KB ikut di setiap salinan input kandidat** (input pengguna 2,4 MB pretty-print). Setiap evaluasi/tugas/hash menyalin arsip itu. Kini tidak dibawa ke input job (penulisan `input_data.json` tetap mengadopsi arsip dari disk — tidak ada data hilang).',
 '5. **Datastore di jalur kritis Run:** auto-save sebelum setiap Run membuat backup + cermin `input_data.json` (2,4 MB x 3 tulis); keluaran internal juga di-backup. Kini backup + cermin hanya pada Save pengguna.',
 '6. **Progres "hilang" ~5 s:** saat klaim, teks progres diganti pesan statis. Kini progres tetap menghitung detik selama bukti merit dihitung.', '']
for case in ['U31', 'U30']:
    L += ['### %s — timeline browser nyata (Chromium, cold, detik sejak klik Run)' % NM[case], '', '| source / lingkungan | ' + ' | '.join(n for _, n in K) + ' | waktu UI | diperiksa / valid | CP | Heat Rate | review merit |', '|' + '---|' * (len(K) + 6)]
    for lab, f in [('lama 940af0a, native 4 inti', 'OLD_native'), ('baru V2, native 4 inti', 'NEW_native'), ('lama 940af0a, emulasi Windows (1 pid, 2 inti, tanpa OPcache)', 'OLD_winemu'), ('baru V2, emulasi Windows (1 pid, 2 inti, tanpa OPcache)', 'NEW_winemu')]:
        for r in rows(f'{B}/v2/{f}.jsonl'):
            if r['case'] != case: continue
            st = ((r.get('trace') or {}).get('stages_s') or {}).get('merit_review_unit_priority')
            L.append('| %s | %s | %.2f | %s / %s | %s | %s | %s s |' % (lab, ' | '.join(tl(r)), ui(r), r.get('checked'), r.get('valid'), r.get('cp'), r.get('hr'), st))
    L += ['', '| source | G1 | G2 | G5 | G8 | G9 | S2 | S3 | CP | Heat Rate |', '|---|---|---|---|---|---|---|---|---|---|']
    for lab, f in [('lama 940af0a', 'OLD_native'), ('baru V2', 'NEW_native')]:
        for r in rows(f'{B}/v2/{f}.jsonl'):
            if r['case'] != case: continue
            U = r.get('units') or {}; c = lambda u: ('row %s (%s row, maks %s MW)' % (U[u]['rows'], U[u]['n'], U[u]['max_mw'])) if U.get(u) else 'tidak running'
            L.append('| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |' % (lab, c('G1'), c('G2'), c('G5'), c('G8'), c('G9'), c('S2'), c('S3'), r.get('cp'), r.get('hr')))
    L += ['']
G3 = rows(f'{B}/v2/G3.jsonl')
if G3:
    R = {}
    for r in G3: R.setdefault((r.get('env', ''), r['case']), []).append(r)
    L += ['### 3 run per kasus (source beku V2, browser nyata, detik klik Run -> Simulation Data)', '', '| lingkungan | kasus | median | min | maks | first_fully_valid -> simulation_data_opened (maks) | CP | merit | C4 | STG |', '|---|---|---|---|---|---|---|---|---|---|']
    for (env, c), v in sorted(R.items()):
        t = [ui(x) for x in v]; g = [((x['trace']['simulation_data_opened_ms'] - x['trace']['first_fully_valid_ms']) / 1000) for x in v if (x.get('trace') or {}).get('first_fully_valid_ms') and (x.get('trace') or {}).get('simulation_data_opened_ms')]
        L.append('| %s | %s | %.2f | %.2f | %.2f | %s | %s | %s | %s | %s |' % (env, NM.get(c, c), S.median(t), min(t), max(t), ('%.2f s' % max(g)) if g else '-', v[0].get('cp'), v[0].get('merit'), v[0].get('c4'), v[0].get('stg')))
    L += ['']
L += ['Batas jujur: XAMPP Windows tidak tersedia di lingkungan uji ini. Angka "emulasi Windows" memakai kondisi yang terbukti berbeda di Apache Windows (satu pid untuk semua request), CPU 2 inti, dan PHP tanpa OPcache — bukan pengukuran di mesin pengguna. Panel Detail audit hasil Fastest menampilkan timestamp yang sama (run_click ... helpers_stopped) sehingga waktu di mesin pengguna dapat dibaca langsung.', '']
L += ['## 2. Merit: Unit Priority start', '',
      'Seluruh source sebelum V2 (V12 checkpoint 7ac6ab8, merit a905f10, Fastest 2173cc2, final 940af0a) menghasilkan CP 64,7178 dengan G2 start pada input pengguna (Maximum Review, dijalankan ulang). G5 start (prioritas lebih tinggi) valid dengan CP 64,7205 (+0,0042 %) — di dalam band 0,2 %; pemenang lama dipilih oleh Heat Rate (8374,16 vs 8375,02). Aturan V2: di dalam band, rencana yang men-start unit prioritas lebih rendah kalah dari rencana valid yang men-start unit prioritas lebih tinggi; Heat Rate memutus seri sesudahnya. Di luar band, CP tetap menang (tidak ada constraint yang dilonggarkan).', '',
      'Dampak pada 12 merit state: 8 identik; BASE_PGN30, Q_pep_kp72_1, busflow 22 berubah G2 -> G5 (CP +0,0022 %, di dalam band); ACT_PGN_UP: commitment dan MW GTG identik, CP sama, Heat Rate 8387,69 -> 8387,68 (jangkar PGN 30 kini rencana G5). Kriteria uji merit .06 dan paritas merit diperbarui mengikuti aturan baru (perubahan kebutuhan eksplisit, bukan pelonggaran).', '']
for d in [os.path.dirname(TGT)]:
    for nm in ['MERIT_PARITY.md']:
        p = os.path.join(d, 'integ', nm)
        if os.path.exists(p): L += [open(p).read().replace('# ', '### '), '']
L += ['## 3. Distillate (input pengguna PGN 25 + PEP 30 + KP72 0, aksi Distillate)', '']
if os.path.exists(f'{B}/v2/DIST_UI_USER.md'): L += [open(f'{B}/v2/DIST_UI_USER.md').read().replace('# ', '### '), '']
L += ['Distillate seluruhnya di G1 (unit eligible yang sudah running sepanjang hari) — tidak ada start unit tambahan.', '',
      '## 4. Datastore', '', '`saved_data_store.php` tidak lagi berada di jalur kritis simulasi: auto-save sebelum Run dan persistensi hasil run hanya menulis berkas kerja secara atomik (tanpa backup, tanpa cermin, tanpa sinkron record). Backup + cermin + sinkron record Report hanya pada Save pengguna. Tidak ada backup pada job, cache, kandidat, atau keluaran internal.', '']
t = cnt(TGT); r = cnt(REG); c = cnt(CE)
L += ['## 5. Uji', '', '| suite | PASS | FAIL |', '|---|---|---|']
for nm, x in [('Targeted (source beku V2)', t), ('Full regression (source beku V2)', r), ('Targeted dari ZIP hasil clean-extract', c)]: L.append('| %s | %s | %s |' % (nm, x['pass'] if x else 'belum', x['fail'] if x else '-'))
for nm, x in [('targeted', t), ('regression', r), ('clean-extract', c)]:
    if x and x['fails']: L += ['', 'FAIL %s:' % nm] + ['- `%s`' % f[:300] for f in x['fails']]
L += ['', 'Uji pendek sebelum regression (instruksi): PGN 31 + PEP 30 dan PGN 30 + PEP 30 Fastest browser cold (PASS, timeline di atas), PGN 25 + PEP 30 + KP72 0 Distillate (11/11), pembanding merit sebelum/sesudah (bagian 2), smoke Change Over (2 kasus identik V12 checkpoint).', '']
open(os.path.join(OUT, 'LAPORAN_V2_ROOTCAUSE_FASTEST_MERIT.md'), 'w').write('\n'.join(L) + '\n'); print('ok')
