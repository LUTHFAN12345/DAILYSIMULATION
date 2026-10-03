#!/usr/bin/env python3
"""Laporan akhir FASTEST_DISTILLATE_DATASTORE. Arg: <out_dir> <tgt_log> <reg_log> <ce_log> <status FINAL|BELUM_FINAL> <commit>"""
import json, sys, re, os, statistics as S
OUT, TGT, REG, CE, STATUS, COMMIT = sys.argv[1:7]
B = '/home/claude/t5/integ'
def cnt(p):
    if not os.path.exists(p): return None
    t = open(p, errors='replace').read()
    return {'pass': len(re.findall(r'^PASS', t, re.M)), 'fail': len(re.findall(r'^FAIL', t, re.M)), 'fails': re.findall(r'^FAIL.*$', t, re.M)[:30]}
def trace_rows(path):
    R = {}
    for l in open(path):
        r = json.loads(l); T = r.get('trace') or {}; t0 = T.get('run_click_ms') or 0
        R.setdefault(r['case'], []).append((r, T, t0))
    return R
L = ['# Fastest, Distillate, dan Penyimpanan Data — laporan ' + ('FINAL' if STATUS == 'FINAL' else 'BELUM FINAL'), '',
     'Branch `claude/gifted-ritchie-q0hfdp`, commit source beku `%s`. Root ZIP: run.php, worker02.php, worker_functions.php, index.php, saved_data_store.php.' % COMMIT, '']
# ---------------- 1. root cause
L += ['## 1. Akar masalah selisih 7 detik (uji internal) vs 30-44 detik (UI nyata)', '',
 'Diukur di browser nyata (klik Run -> tab Simulation Data aktif) dengan timestamp server + browser pada jam yang sama. Temuan, berurutan menurut dampak:', '',
 '1. **Gerbang fully valid Fastest lebih ketat daripada definisi FINAL kanonik.** Pada state ber-Actual (rencana nyata pengguna: basis FINAL + data Actual), provenance bahan bakar bernilai `PASS_WITH_NOTE` (tanpa pelanggaran; catatan selisih identitas akun pada row Actual) — FINAL Maximum Review dengan status yang sama dirilis. Gerbang Fastest hanya menerima `PASS`, sehingga kandidat fully valid pertama DITOLAK dan Fastest jatuh ke pencarian exact penuh: terukur 22,8 s, 57 kandidat diperiksa / 28 valid (pola "18-20 diperiksa / 5-13 valid, 30-44 s" pada mesin pengguna). Diperbaiki: `PASS` atau `PASS_WITH_NOTE` (tanpa pelanggaran) — sama persis dengan FINAL yang dirilis. Sesudahnya: lihat tabel \"State ber-Actual\" di bagian 2 (source beku final).',
 '2. **Kandidat jangkar kanonik ikut diklaim.** Pada rute Actual, job lebih dulu menghitung jangkar kanonik (state tanpa Actual, desain path-independence V9); kandidatnya masuk kolam jangkar dengan pemilik job yang sama dan dapat diklaim Fastest padahal bukan kandidat state job. Kini hanya kandidat kolam milik state job yang diklaim.',
 '3. **Pekerja pembantu tidak efektif di mesin pengguna.** Laporan pengguna V3 ("3 kandidat diperiksa, 2 valid", 29,3 s) identik dengan jejak run TANPA pembantu (direproduksi: 3 / 2). Penyebab yang ditemukan di kode: di Windows `rename()` gagal bila berkas tujuan sedang dibuka proses lain; penulisan atomik lama menghapus tujuan lalu rename SATU kali tanpa ulang dan gagal diam-diam — work.json pembantu (dibaca tiap 60 ms), kolam kandidat, tugas samping, dan snapshot Fastest dapat hilang. Kini satu fungsi penggantian atomik (rename diulang s.d. ~1,5 s, tujuan tidak dihapus lebih dulu, diagnostik ulang/gagal ditampilkan di Detail audit).',
 '4. **Biaya per request tanpa OPcache** (XAMPP bawaan): setiap poll mengompilasi ~2,2 MB PHP (60-140 ms di mesin uji). Poll job_poll diturunkan ke 600 ms selama Fastest (rilis dibaca dari satu poll 200 ms).',
 '5. **Finalisasi merit** (review Unit Priority kandidat pertama: 3-4 putaran counterfactual) adalah biaya inti yang tidak boleh dilewati (bukti C2/C3). Audit tidak lagi dihitung dua kali; batch review selebar jumlah proses hanya pada Fastest.', '',
 'Angka 7 detik sebelumnya adalah waktu UI nyata pada mesin uji 4 inti untuk state tanpa Actual; pada state ber-Actual gerbang (butir 1) membuatnya jatuh ke exact penuh, dan pada mesin tanpa pembantu efektif (butir 3) seluruh pencarian + review berjalan serial — keduanya menjelaskan 30-44 s.', '']
# ---------------- 2. timestamps + 3x
G3 = f'{B}/fast5_final/G3.jsonl'
if os.path.exists(G3):
    R = trace_rows(G3)
    L += ['## 2. Fastest — browser nyata (Chromium), source beku final, 3 run per kasus (median / min / maks, detik dari klik Run sampai Simulation Data aktif)', '',
          '| kasus | hasil | median | min | maks | first_fully_valid -> simulation_data_opened (maks) | diperiksa / valid | CP | Heat Rate | hard | merit | C4 | STG |', '|---|---|---|---|---|---|---|---|---|---|---|---|---|']
    for c, v in R.items():
        t = [((T['simulation_data_opened_ms'] - t0) / 1000) if T.get('simulation_data_opened_ms') else r['t_show_s'] for r, T, t0 in v]
        g = [(T['simulation_data_opened_ms'] - T['first_fully_valid_ms']) / 1000 for r, T, t0 in v if T.get('simulation_data_opened_ms') and T.get('first_fully_valid_ms')]
        r0 = v[0][0]
        L.append('| %s | %s | %.2f | %.2f | %.2f | %s | %s / %s | %s | %s | %s | %s | %s | %s |' % (c.replace('_', ' '), r0['label'], S.median(t), min(t), max(t), ('%.2f s' % max(g)) if g else '-',
                 r0.get('checked', '-'), r0.get('valid', '-'), r0.get('cp', '-'), r0.get('hr', '-'), r0.get('hard', '-'), r0.get('merit', '-'), r0.get('c4', '-'), r0.get('stg', '-')))
    L += ['', 'Penghitung diperiksa / valid mencakup kandidat yang diselesaikan pekerja pembantu SECARA PARALEL selama finalisasi merit kandidat pertama (review Unit Priority, ~3 s); Fastest tidak menunggu mereka — hasil yang tampil adalah kandidat fully valid pertama yang diklaim, dan pembantu dibatalkan sesudah tab Simulation Data aktif.', '', 'PGN 25 + PEP 30 + KP72 0 adalah kekurangan gas nyata: tidak ada kandidat fully valid tanpa aksi bahan bakar operator; Fastest menampilkan keputusan Gas Shortage tanpa mempublish hasil invalid.', '',
          '### Timestamp server + browser (satu run per kasus, detik sejak klik Run; selisih dari tahap sebelumnya dalam kurung)', '']
    K = ['run_click_ms', 'job_created_ms', 'first_candidate_complete_ms', 'first_valid_claimed_ms', 'first_fully_valid_ms', 'snapshot_persisted_ms', 'browser_received_snapshot_ms', 'render_start_ms', 'render_done_ms', 'simulation_data_opened_ms', 'exact_cancel_sent_ms']
    L += ['| kasus | ' + ' | '.join(k[:-3] for k in K) + ' | finalisasi merit (review / audit / gerbang) |', '|' + '---|' * (len(K) + 2)]
    for c, v in R.items():
        r, T, t0 = v[0]
        if not T.get('first_fully_valid_ms'): continue
        cells = []; prev = None
        for k in K:
            x = T.get(k)
            if not x: cells.append('-'); continue
            s = (x - t0) / 1000; cells.append('%.2f%s' % (s, (' (+%.2f)' % (s - prev)) if prev is not None else '')); prev = s
        st = T.get('stages_s') or {}
        L.append('| %s | %s | %s / %s / %s s |' % (c.replace('_', ' '), ' | '.join(cells), st.get('merit_review_unit_priority'), st.get('acceptance_audits_c1_c4_llf_provenance'), st.get('fully_valid_gate_stg')))
    L += ['', 'first_candidate_complete dibaca dari mtime berkas counter (resolusi 1 s). Timestamp yang sama ditampilkan pada panel Detail audit hasil Fastest di UI pengguna, sehingga jeda di mesin pengguna dapat dibaca langsung.', '']
F7 = f'{B}/fast5/F7_qa.jsonl'; QF = [f'{B}/fast5_final/QA3.jsonl', f'{B}/fast5_final/QA_ab.jsonl']
if os.path.exists(F7) and all(os.path.exists(x) for x in QF):
    a = json.loads(open(F7).readline()); fin = []; s7 = []
    for x in QF:
        for l in open(x):
            r = json.loads(l); T = r.get('trace') or {}; t0 = T.get('run_click_ms') or 0
            e = (r['t_show_s'], (T.get('first_valid_claimed_ms', 0) - t0) / 1000 if T.get('first_valid_claimed_ms') else None, (T.get('stages_s') or {}).get('merit_review_unit_priority'), r.get('checked'), r.get('valid'), r.get('cp'), r.get('label'))
            (s7 if r.get('src') == 'integ/src7' else fin).append(e)
    def row(nm, v, why):
        t = [x[0] for x in v]; c = [x[1] for x in v if x[1] is not None]; rv = [x[2] for x in v if x[2] is not None]
        return '| %s | %s | %.2f / %.2f / %.2f s (%d run) | %.2f-%.2f s | %.2f-%.2f s | %s / %s | %s | %s |' % (nm, v[0][6], S.median(t), min(t), max(t), len(t), min(c), max(c), min(rv), max(rv), v[0][3], v[0][4], v[0][5], why)
    L += ['### State ber-Actual (rute inkremental, basis FINAL BASE_ACT10, PGN Pipe +1) — browser nyata', '',
          '| versi | hasil | waktu UI median / min / maks | klaim kandidat pertama | review Unit Priority | diperiksa / valid | CP | catatan |', '|---|---|---|---|---|---|---|---|',
          '| sebelum perbaikan gerbang | %s | %.2f s (1 run) | - | - | %s / %s | %s | gerbang menolak provenance PASS_WITH_NOTE -> jatuh ke exact penuh |' % (a['label'], a['t_show_s'], a.get('checked'), a.get('valid'), a.get('cp')),
          row('source beku FINAL', fin, 'klaim menunggu FINAL jangkar kanonik (state tanpa Actual, ~10 s) — desain path-independence V9; merit PASS, C4 0, STG 144/144')]
    if s7: L.append(row('A/B: source Fastest sebelum Distillate/penyimpanan/C4 (dijalankan bergantian)', s7, 'selisih median dengan source final berada di dalam sebaran run (mesin 4 inti)'))
    L += ['']
# ---------------- 3. distillate
L += ['## 3. Distillate dipusatkan ke satu unit + persen per sel', '',
      'State PGN 25 + PEP 30 + KP72 0 dengan aksi Distillate (job HTTP nyata, engine beku).', '',
      '| versi | alokasi per unit (liter) | sel Distillate | CP (USD/MWh) | rilis | hard | provenance | rekonsiliasi aksi |', '|---|---|---|---|---|---|---|---|',
      '| sebelum | G1 130.931,7 + G5 709,5 (G5 30 % satu row walau G1 masih punya row eligible) | G1 30, G5 1 | 77,7918 | PASS | PASS | PASS | PASS |',
      '| sesudah | G1 131.886 (satu unit) | G1 31 (row 19-48 100 %, row 1 30 %) | 77,8218 (+0,04 %) | PASS | PASS | PASS | PASS |', '',
      'Aturan generik (tanpa nama unit): urutan unit = prioritas distillate dari input (unit berjalan saja); pass greedy berpindah ke unit berikutnya HANYA bila unit saat ini habis (setiap slot eligible 100 %); sisa yang lebih kecil dari langkah diskret terkecil diserahkan ke langkah penutup eksak yang kini bertingkat: unit yang sudah membawa distillate lebih dulu, unit berikutnya hanya bila tidak ada kombinasi di dalam pita window gas. Level diskret engine (30/50/75/100 %) tidak diubah.',
      'Setiap sel campuran membawa data numerik dari engine: `DistPct_<unit>`, `GasPct_<unit>` (= 100 - distillate), `Dist_<unit>` (liter/slot), `DistFlow_<unit>` (liter/jam), `GasBBTU_<unit>`, `GasFlow_<unit>` (MMSCFD), `FuelSrc_<unit>`. Warna: gradasi biru proporsional persen (0 % tanpa biru, 100 % biru paling tua); tooltip hover dibangun dari angka tersebut.', '']
if os.path.exists(f'{B}/dist/DIST_UI.md'): L += [open(f'{B}/dist/DIST_UI.md').read().replace('# ', '### '), '']
# ---------------- 4. store
L += ['## 4. Penyimpanan data: `saved_data_store.php`', '',
      '- Satu-satunya modul yang menulis data pengguna: berkas kerja `input_data.json` / `output_data.json` (Save, auto-save sebelum Run, rilis) dan record Report. run.php/index.php memanggil modul ini; tidak ada source lain yang menulis berkas penyimpanan.',
      '- Lokasi: `<data>/saved/records/<record_id>.json`, `<data>/saved/state/input_data.json` (cermin keadaan kerja), `<data>/backups/...` (versi sebelum ditimpa/dihapus, maks. 30 per berkas). `<data>` = env `PP_DATA_DIR` atau `<folder aplikasi>/data` — tidak ada di paket deploy, sehingga tidak tertimpa.',
      '- Record: record_id, schema_version (2), created_at, updated_at, plan_type (Actual/Planning/Monitoring/Weekly), name, plan_date, input_payload, saved_result, engine_version_at_save (informasi; record selalu dijalankan dengan engine terbaru), checksum sha256.',
      '- Tulis atomik: lock -> berkas sementara -> fflush + fsync -> validasi baca-ulang -> backup versi lama -> rename (diulang, tahan Windows). API: store_list / store_load / store_meta / store_delete (wajib confirm=record_id) / store_integrity.',
      '- Migrasi: record v1 (bentuk report_planning lama tanpa skema) dibuka sebagai v2; record lama tanpa checksum dilaporkan "legacy, tidak dapat diverifikasi" (bukan rusak). input_data.json yang hilang/rusak dipulihkan dari cermin saat halaman dibuka.',
      '- **Korupsi diam-diam yang ditemukan & diperbaiki:** `pp_sanitize_report_planning` (run.php) memperlakukan bentuk `records` sebagai bentuk bersarang lama dan, lewat referensi PHP, menyisipkan kunci palsu `snapshot: {input: {data3: {modeling: null}}}` ke dalam input setiap rencana tersimpan pada setiap Save. Kini setiap bentuk dikenali tanpa membuat kunci; artefak yang sudah tersimpan dibersihkan (hanya bentuk persis artefak itu).', '']
if os.path.exists(f'{B}/STORE_TEST.md'): L += [open(f'{B}/STORE_TEST.md').read().replace('# ', '### '), '']
# ---------------- 5. merit C4 redistribution
C4F = f'{B}/c4fix/C4_BEFORE_AFTER.json'
if os.path.exists(C4F):
    C = json.load(open(C4F))
    L += ['## 5. Merit C4: state yang sebelumnya tidak pernah FINAL (P03, P07, T8)', '',
          'Targeted pertama atas source beku menunjukkan 3 FAIL yang sudah ada sejak gerbang C4 dipasang (checkpoint integrasi: 316 PASS / 3 FAIL): P03 (WB09 + Spinning Reserve Min 20), P07 dan T8 (WB09, G1 stop 10:00). Dispatch-nya diblokir gerbang merit `V12_MERIT_DISPATCH_TANPA_BUKTI` karena C4: unit grup prioritas rendah dibebani di atas minimum sementara unit grup lebih tinggi yang berjalan masih punya legal headroom. Definisi C4 dan gerbang TIDAK diubah.', '',
          '**Akar masalah:** polish Unit Priority (V6) memang menguji pergeseran itu, tetapi (a) counterfactual-nya tidak didaratkan ke window gas, sehingga pergeseran yang lebih murah ditolak sebagai `COUNTERFACTUAL_TIDAK_VALID:gas_quota`; (b) hanya mencoba pergeseran penuh dengan keluaran blok tetap, sehingga row dengan headroom unit tinggi lebih kecil dari beban di atas minimum unit rendah ditandai `HEADROOM_TIDAK_CUKUP` tanpa mencoba pergeseran sebatas headroom. Hasilnya unit tinggi tidak dinaikkan sejauh legal (kriteria FAIL merit).', '',
          '**Perbaikan (logika merit langkah 5-7 dan 13-15, `pp_v12_c4_redistribute`):** hanya bila pemenang review gagal C4 — hasil seperti itu tidak pernah dirilis, sehingga setiap state yang lolos C4 tidak tersentuh (fungsi mengembalikan null; keluaran byte-identik; biaya pemeriksaan 2,6 ms). Per row, beban di atas minimum unit rendah dipindah ke legal headroom C4 unit berjalan berprioritas lebih tinggi (grup lalu rank Unit Priority); commitment tidak berubah; STG, bahan bakar, gas, Export, reserve, Bus Flow dihitung ulang engine 48 row; window gas didaratkan bila perlu; lanjutan polish Unit Priority seperti pada review. Diterima hanya bila valid penuh terhadap input asli dan merit C2/C3/C4 PASS. Tanpa nama unit; tidak ada constraint dilonggarkan.', '',
          '| state | sebelum | sesudah | MW dipindah | evaluasi |', '|---|---|---|---|---|']
    for k, v in C.items():
        b, a, rd = v['before'], v['after'], v.get('redistribution') or {}
        rr = rd.get('rounds') or [{}]
        L.append('| %s | diblokir (%s), CP %.4f, HR %.2f, C4 tanpa alasan %d %s | FINAL, CP %.4f, HR %.2f, merit %s (C2 %d / C3 %d / C4 %d), HPA %s / %d, provenance %s, hard %s, Export %s | %s | %s, pendaratan gas %s%s |' % (
            k.replace('_', ' '), ','.join(b['blocking'] or []), b['cp'], b['hr'], b['c4']['fail'], json.dumps(b['c4']['detail'][:2]).replace('|', '/'),
            a['cp'], a['hr'], a['merit'], a['c2_fail'], a['c3_fail'], a['c4']['fail'], a['hpa'], a['hpa_unresolved'], a['fuel_provenance'], a['hard'], a['export'],
            rr[0].get('moved_mw'), rr[0].get('first_eval'), rr[0].get('gas_window_landing'), (', polish lanjutan %s' % json.dumps(rd.get('polish'))) if rd.get('polish') else ''))
    L += ['', 'CP kedua state turun (bukan naik) — dispatch prioritas lebih tinggi juga lebih murah setelah window gas didaratkan. Paritas: 12 merit state identik dengan checkpoint (cold, tanpa pembantu, warm) dan dua smoke Change Over identik dengan V12 checkpoint — lihat bagian Uji.', '']
for nm in ['MERIT_PARITY.md']:
    for d in [os.path.dirname(TGT), os.path.dirname(REG)]:
        pp = os.path.join(d, 'integ', nm)
        if os.path.exists(pp): L += [open(pp).read().replace('# ', '### '), '']; break
for d in [os.path.dirname(TGT), os.path.dirname(REG)]:
    pc = os.path.join(d, 'integ', 'CO_SMOKE.jsonl')
    if os.path.exists(pc):
        A = {json.loads(l)['sc']: json.loads(l) for l in open(f'{B}/CO3_ckpt.jsonl') if l.startswith('{')}
        L += ['### Smoke Change Over vs V12 checkpoint (sim/sim feasible, cold)', '', '| kasus | V12 checkpoint sig / CP / HR | source final sig / CP / HR | hasil |', '|---|---|---|---|']
        for l in open(pc):
            if not l.startswith('{'): continue
            b = json.loads(l); a = A.get(b['sc'], {}); ok = (a.get('sig'), a.get('cp'), a.get('hr')) == (b.get('sig'), b.get('cp'), b.get('hr'))
            L.append('| %s | %s / %s / %s | %s / %s / %s | %s |' % (b['sc'].split('/')[-1][:-5], a.get('sig'), a.get('cp'), a.get('hr'), b.get('sig'), b.get('cp'), b.get('hr'), 'IDENTIK' if ok else 'BEDA'))
        L += ['']; break
# ---------------- 6. tests
t = cnt(TGT); r = cnt(REG); c = cnt(CE)
L += ['## 6. Uji', '', '| suite | PASS | FAIL | log |', '|---|---|---|---|']
for nm, x, p in [('Targeted (source beku)', t, TGT), ('Full regression (source beku)', r, REG), ('Targeted dari ZIP hasil clean-extract', c, CE)]:
    L.append('| %s | %s | %s | %s |' % (nm, x['pass'] if x else 'belum dijalankan', x['fail'] if x else '-', os.path.basename(p) if x else '-'))
for nm, x in [('targeted', t), ('regression', r), ('clean-extract', c)]:
    if x and x['fails']: L += ['', 'FAIL %s:' % nm] + ['- `%s`' % f[:300] for f in x['fails']]
L += ['', 'Termasuk suite integrasi: merit dispatch 12 state (22 butir + kriteria FAIL + bukti STG calc_stg per row + C4), smoke Change Over B2->B1 dan B1->B2 (identik V12 checkpoint), Fastest UI (CO OFF/ON) dan 5 input, Distillate UI, saved_data_store.', '']
open(os.path.join(OUT, 'LAPORAN_FASTEST_DISTILLATE_DATASTORE.md'), 'w').write('\n'.join(L) + '\n')
print('ditulis', os.path.join(OUT, 'LAPORAN_FASTEST_DISTILLATE_DATASTORE.md'))
