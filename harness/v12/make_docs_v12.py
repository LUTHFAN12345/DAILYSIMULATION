#!/usr/bin/env python3
"""Dokumen paket V12 dari hasil uji nyata. Arg:
  <tgt-dir> <reg-dir> <reg-log> <ce-dir> <ce-log> <perf-dir> <freeze-sha> <out-dir> <status: FINAL|BELUM_FINAL>
Menulis LAPORAN_V12.md, AKAR_MASALAH_RUNTIME_V12.md, AUDIT_DISPATCH_MERIT_V12.md, PERFORMA_V12.md, PATH_INDEPENDENCE_V12.md, CARA_PASANG.md."""
import json, os, re, sys, subprocess, glob, statistics
TGT, REG, REGL, CE, CEL, PERF, FRZ, OUT, STATUS = sys.argv[1:10]
os.makedirs(OUT, exist_ok=True)
def rd(p):
    try: return open(p, encoding='utf-8', errors='replace').read()
    except (FileNotFoundError, IsADirectoryError): return ''
def jl(p):
    try: return [json.loads(l) for l in open(p) if l.strip().startswith('{')]
    except FileNotFoundError: return []
def counts(d, log):
    if not d or not os.path.isdir(d): return '(belum dijalankan)'
    return subprocess.run(['python3', '/home/claude/t5/v12/suite_counts.py', d, log], capture_output=True, text=True).stdout.strip()
def f(x, n=2):
    try: return (('%.' + str(n) + 'f') % float(x)).replace('.', ',')
    except Exception: return '-' if x is None else str(x)
perf_md = rd(os.path.join(PERF, 'PERF.md')); perf = json.loads(rd(os.path.join(PERF, 'PERF.json')) or '[]')
m0 = re.search(r'## Ringkasan terhadap target V12.*', perf_md, re.S); perf_sum = m0.group(0) if m0 else ''
merit = jl(os.path.join(TGT, 'V12_MERIT.jsonl')); merit_md = rd(os.path.join(TGT, 'V12_MERIT.md'))
mm = re.search(r'\*\*(\d+)/(\d+)\*\*', merit_md)
pi = rd(os.path.join(TGT, 'PATH_INDEPENDENCE.md')); mp = re.search(r'\*\*(\d+)/(\d+) state identik[^*]*\*\*', pi)
wb = None
for r in merit:
    if r['id'].startswith('M02'): wb = r
fails = [x for x in perf if x.get('cat') and not x.get('ok')]

# ---------------- AKAR MASALAH RUNTIME ----------------
A = ['# Akar masalah runtime V11 dan perbaikan V12', '',
     'Diukur pada VM yang sama (PHP 7.4, 4 inti, 3 pekerja pembantu, server multi-backend meniru Apache) dengan profil sampling, jejak '
     'evaluasi kandidat per proses (PP_V5_EVLOG), jejak decommit (PP_V12_DECLOG), statistik idle pembantu, dan penanda tahap job. '
     'Tabel median/min/maks: PERFORMA_V12.md.', '',
     '| # | akar masalah (terukur) | dampak V11 | perbaikan V12 | kesetaraan hasil |', '|---|---|---|---|---|',
     '| 1 | LNG di atas kebutuhan: dispatch basis Gas Shortage dibekukan, tidak lagi masuk window gas (kuota total naik), lalu jatuh ke **exact penuh** state bahan bakar | rerun LNG lebih 20 s (VM ini; 36–38 s di VM V11) | commitment rencana basis didaratkan ulang pada state bahan bakar (redispatch 48 row + pendaratan window gas dengan lever engine), review Unit Priority generik sesudahnya; tanpa keluarga penuh, tanpa exact | dispatch, CP 66,5411 dan Heat Rate 8307,17 identik dengan exact penuh |',
     '| 2 | Screening decommit berjalan berurutan di pemilik (kandidat stop + 6 window trim saat baseline infeasible, lalu iterasi berikutnya) sementara pembantu mengerjakan keluarga | 4–8 s jalur kritis setiap pipeline exact / jangkar | core run kandidat dalam **konteks global terisolasi** (fungsi input saja) -> kolam lintas proses; kandidat diterbitkan ke pembantu (tugas samping, jarak pandang 3), iterasi berikutnya spekulatif (X diterima -> Y di atasnya), pemilik *work stealing* saat kandidat yang dibutuhkan sedang dihitung | urutan evaluasi & keputusan pemilik tidak berubah; hasil bit-identik (tanda tangan dispatch sama pada seluruh battery) |',
     '| 3 | Penanda tugas V4 tidak memuat identitas state: tugas Tier 2b state S bertabrakan dengan tugas grid pustaka jangkar (penanda .done sama) sehingga pembantu melewatinya dan pemilik menghitung sendiri berurutan | +2–4 s pada kuota basis Actual (QA) | penanda tugas berkunci state (tag orig) | perilaku pencarian sama; hanya pembagian kerja |',
     '| 4 | Pencarian target internal supplier PGN berjalan di lintasan datar (dispatch identik berturut-turut) 8–16 kali sebelum lookahead aktif (ambang 7 tanpa hint) | 0,5–1 s per core run berat (Tier 2b rute slot 1,8 s) | DIUJI: ambang 2/3/4. Ditolak — asumsi kontiguitas lintasan datar tidak berlaku pada setiap state (ambang 4 mengubah FINAL perintah stop G1: CP 64,1346 vs 64,1378, dan menimbulkan variasi dispatch antar-run). Ambang tetap 7 (V11); tersedia sebagai `PP_V12_SUPFLAT_MIN` | identik V11 dengan ambang 7 |',
     '| 5 | Evaluasi beku (polish Unit Priority) dan dispatch tersusun (review V10) tidak dapat dibagi antar proses | review/polish berurutan di pemilik | kolam berkunci input lengkap untuk evaluasi beku & tersusun (efek samping — pembersihan global, penghitung kandidat — direplay pada cache hit) + tugas samping pembantu | identik (termasuk penghitung kandidat) |',
     '| 6 | Review Unit Priority first-improvement berjalan per batch 2 kandidat (pendaratan penuh) sementara dua dari empat pekerja menganggur | review 12–15 s pada perintah stop G1 (UI F1/F2) | batch BERIKUTNYA (urutan & penyaring sama) diterbitkan sebagai tugas samping pendaratan | first-improvement dan titik berhenti sama; hasil identik |',
     '| 7 | Pembantu hanya memeriksa tugas samping di antara evaluasi kandidat penuh (latensi 1–1,5 s) | kandidat decommit menunggu | DIUJI: sisipan tugas samping di antara percobaan supplier (global engine disimpan & dipulihkan). Tanpa perbaikan terukur (A/B 2 x 5 rute dalam noise) sehingga opsional (`PP_V12_NESTED=1`), bawaan mati | - |',
     '| 8 | Pengukuran: harness polling 250 ms (UI 150 ms) | +0,1 s rata-rata | harness V12 memakai 150 ms seperti UI (berlaku sama untuk V11 dan V12 di tabel perbandingan) | - |', '',
     '## Temuan CP (tidak diaktifkan bawaan)', '',
     'Review tersusun V10 (`PP_V10_EXACT_FASTREVIEW=1`) pada FINAL exact menemukan rencana VALID dengan CP lebih rendah pada beberapa state '
     '(mis. Q_pgn_pipe_1 64,5200 vs 64,5424; WB09 64,3465 vs 64,3644; Q_lng_1 64,8355 vs 64,8581; seluruhnya hard constraints PASS, rilis, merit PASS), '
     'tetapi memperlambat Q_pgn_pipe_2 (19 s), WB09 (15 s) dan rute QA (+1–3 s). Karena target runtime dan kesetaraan hasil V11 menjadi syarat penerimaan, '
     'mode ini TIDAK dijadikan bawaan; tersedia sebagai opsi dan dicatat sebagai kandidat perbaikan CP berikutnya.', '',
     '## Rute yang belum mencapai target', '']
if fails:
    for x in fails: A.append('- %s (%s): median %s s, target %s s.' % (x['sc'], x['cat'], f(x['v12'][0]), f(x['limit'], 1)))
    A += ['', 'Jalur kritis rute ini (penanda tahap job): pipeline exact state (baseline + screening decommit berantai: iterasi decommit '
          'kedua bergantung pada kandidat yang diterima iterasi pertama) -> sisa keluarga commitment -> review Unit Priority dua putaran '
          '(putaran kedua bergantung pada pemenang putaran pertama). CPU 4 inti sudah terpakai ±85 % selama pipeline (idle pembantu diukur). '
          'Sisa waktu adalah rantai dependensi serial, bukan VM lambat; penghapusannya memerlukan perubahan definisi kanonik (mis. seed '
          'dari FINAL tetangga) yang akan membuat FINAL bergantung jalur, sehingga tidak dilakukan.']
else: A.append('Seluruh rute memenuhi target median.')
open(os.path.join(OUT, 'AKAR_MASALAH_RUNTIME_V12.md'), 'w').write('\n'.join(A) + '\n')

# ---------------- PERFORMA ----------------
P = ['# Performa V12', '', perf_md.split('\n', 1)[1] if perf_md else '(belum diukur)', '', '## UI browser (backend nyata)', '']
for name in ('SLOT_UI', 'OPS_UI', 'QUOTA_UI_ACT', 'WB09_UI', 'WB09_3_UI'):
    rows = jl(os.path.join(TGT, name + '.jsonl'))
    if not rows: continue
    P.append('### ' + name); P.append(''); P.append('| kasus | hasil | waktu klik -> hasil (s) | CP |'); P.append('|---|---|---|---|')
    for x in rows:
        t = x.get('tFin') or x.get('t') or x.get('t_ms')
        P.append('| %s | %s | %s | %s |' % (x.get('id'), x.get('kind', '-'), f(t / 1000.0 if isinstance(t, (int, float)) else None), x.get('cp') if x.get('cp') is not None else (x.get('snap') or {}).get('cp', '-')))
    P.append('')
gs = rd(os.path.join(TGT, 'GAS_SHORTAGE_PGN25.md')) or rd(os.path.join(REG, 'GAS_SHORTAGE_PGN25.md'))
m = re.search(r'```json\n(.*?)```', gs, re.S)
if m: P += ['### Gas Shortage PGN 25 (UI)', '', '```json', m.group(1).strip(), '```', '']
fc = jl(os.path.join(TGT, 'V12_FUEL_CHAIN.jsonl'))
if fc:
    P += ['### Rantai bahan bakar (HTTP, satu server)', '', '| langkah | FINAL (s) | CP | rute |', '|---|---|---|---|']
    for lab, x in zip(['popup Gas Shortage PGN 25 (angka tervalidasi)', 'LNG rekomendasi', 'LNG lebih (+2 BBTUD)', 'Distillate rekomendasi', 'LNG kurang (tanpa Distillate -> shortage)'], fc):
        P.append('| %s | %s | %s | %s |' % (lab, f(x.get('t_final_s')), x.get('cp'), x.get('computation')))
open(os.path.join(OUT, 'PERFORMA_V12.md'), 'w').write('\n'.join(P) + '\n')

# ---------------- AUDIT DISPATCH MERIT ----------------
M = ['# Audit merit dispatch V12 — generik (12 state nyata, bukan hanya reproducer)', '',
     'Audit `V12 Dispatch Merit Audit` dihitung pada setiap FINAL (analisis murni, tanpa simulasi tambahan): per row 30 menit x setiap unit '
     '(GTG, STG, GE/GEG, BBLN) — batas berlaku (Effective Min/Max), MW, legal headroom (min(Effective Max − MW, allowance ramp) untuk unit '
     'berjalan tanpa Fixed Load), rank & grup prioritas, Heat Rate inkremental (dari kurva bahan bakar unit), biaya bahan bakar inkremental '
     '(harga akun sumber yang sah), allowance ramp / reserve (helper validator yang sama) / Export (atas & bawah) / Bus Flow / gas harian, '
     'status paksa (Cannot Stop, Required, Fixed Load, Last Data Running) dan row stop legal paling awal.', '',
     'Aturan: **C2** unit prioritas rendah yang START saat legal headroom unit berjalan prioritas lebih tinggi cukup wajib punya bukti '
     '(status paksa / counterfactual DELAY-DECOMMIT-SWAP dari review / pembanding pool) — tanpa bukti = FAIL (memblokir FINAL); '
     '**C3** setiap interval unit prioritas rendah eligible diuji STOP pada row legal pertama sesudah minimum runtime (dan breakpoint '
     'kebutuhan berikutnya) atau memang berhenti di sana — selain itu FAIL; **C1** headroom unit prioritas tinggi saat unit rendah di atas '
     'minimum dilaporkan dengan alasan ekonomi (Heat Rate inkremental) / status. `V12 Low Load Fragmentation Outcome`: setiap temuan '
     'berakhir RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON (bukti numerik) / FAIL (memblokir FINAL). Gerbang rilis '
     'menerapkan FAIL pada hasil yang sudah melewati review Unit Priority.', '',
     'Uji generik (`v12/merit_check.php`, FINAL nyata dari backend HTTP): **%s**.' % ('%s/%s PASS' % (mm.group(1), mm.group(2)) if mm else '-'), '',
     '| kasus | state | CP | Heat Rate | merit | C1 | C2 (tanpa bukti) | C3 interval (belum diuji) | LLF hasil akhir | CP min absolut / pemenang / selisih % / HR pemenang / HR min band |', '|---|---|---|---|---|---|---|---|---|---|']
for r in merit:
    c = r.get('cp_report') or {}; l = r.get('llf') or {}
    M.append('| %s | `%s` | %s | %s | %s | %s | %s (%s) | %s (%s) | %s | %s / %s / %s / %s / %s |' % (r['id'], r['sc'][:48], r.get('cp'), r.get('hr'), r.get('merit'), r.get('c1'), r.get('c2'), r.get('c2_fail'), r.get('c3'), r.get('c3_fail'),
        ', '.join('%s %s' % (k, v) for k, v in l.items() if v), c.get('absolute_cp_min', '-'), c.get('winner_cp', '-'), c.get('delta_winner_vs_min_pct', '-'), c.get('winner_heat_rate', '-'), c.get('min_heat_rate_in_band', '-')))
M += ['', '## Rincian uji', '', merit_md.split('\n', 2)[2] if merit_md else '']
open(os.path.join(OUT, 'AUDIT_DISPATCH_MERIT_V12.md'), 'w').write('\n'.join(M) + '\n')

# ---------------- PATH INDEPENDENCE ----------------
det = rd(REGL)
dl = [l for l in det.splitlines() if 'DETERMINISME' in l or re.match(r'^(Q_|QA_|ACT_|WB09)', l)]
PI = ['# Path independence dan determinisme V12', '', 'Sumber: `PATH_INDEPENDENCE.md` targeted (clean-extract) dan log full regression.', '',
      mp.group(0) if mp else '-', '', pi.split('\n', 1)[1] if pi else '', '', '## Pekerja pembantu on/off (full regression)', '', '```', '\n'.join(dl[-12:]), '```', '',
      'Alias lama V5 ("stop tambahan tidak mengikat") tetap mati. Seluruh reuse V12 berkunci input lengkap (state numerik kanonik + sidik jari engine): '
      'kolam kandidat-state, core run terisolasi, evaluasi beku, dispatch tersusun, tugas samping pembantu. FINAL sebelumnya hanya warm start.']
open(os.path.join(OUT, 'PATH_INDEPENDENCE_V12.md'), 'w').write('\n'.join(PI) + '\n')

# ---------------- CARA PASANG ----------------
C = ['# Cara pasang V12', '',
     '1. Hentikan web server / job yang berjalan.',
     '2. Cadangkan `run.php`, `worker02.php`, `worker_functions.php`, `index.php` lama (atau pakai folder `rollback/V11/`).',
     '3. Salin empat berkas PHP dari akar ZIP ke folder aplikasi (menimpa). Tidak ada perubahan konfigurasi, basis data, atau ekstensi PHP; PHP 7.4 cukup.',
     '4. Folder `jobs/` boleh dibiarkan: cache lama tidak dipakai karena sidik jari engine berubah (kunci reuse memuat sidik jari engine).',
     '5. Buka `index.php` dan jalankan simulasi seperti biasa. SUMMARY kuning tetap lima field; audit V12 ada di panel "Detail audit".',
     '6. Periksa integritas: `sha256sum -c SHA256SUMS.txt` di folder hasil ekstrak.', '',
     '## Rollback', '', '- `rollback/V11/`: empat berkas PHP V11 FINAL (hash di `rollback/V11/SHA256SUMS_V11.txt`). Salin kembali ke folder aplikasi untuk kembali ke V11.',
     '- `rollback/V10/`: lihat `rollback/V10/README.md`.', '',
     '## Sakelar (opsional, bawaan aktif)', '',
     '| variabel lingkungan | efek |', '|---|---|',
     '| PP_V12_ISO_CORE=0 | core run kandidat decommit dalam konteks pipeline (tanpa kolam lintas proses) |',
     '| PP_V12_SIDE=0 | tanpa tugas samping pembantu |', '| PP_V12_FUEL=0 | rerun bahan bakar V11 (LNG lebih -> exact penuh) |',
     '| PP_V12_SUPFLAT_MIN=7 | ambang lintasan datar supplier V11 |', '| PP_V12_TASKTAG=0 | penanda tugas V4 tanpa identitas state (perilaku V11) |',
     '| PP_V12_MERIT_GATE=0 | audit merit V12 hanya dilaporkan (tidak memblokir) |', '| PP_V12=0 | audit V12 tidak dilampirkan |',
     '| PP_V12_NESTED=0 | pembantu tidak menyisipkan tugas samping di dalam evaluasi |', '| PP_V12_REVIEW_AHEAD=0 | tanpa look-ahead batch review |',
     '| PP_V12_STEAL=0 | pemilik menunggu (tanpa work stealing) |', '| PP_V12_SIDE_LOOK=n | jarak pandang tugas samping decommit (bawaan 3) |',
     '| PP_V12_FAMHOLD=1 / PP_V12_LAND=1 | eksperimen penjadwalan (bawaan mati) |', '| PP_V10_EXACT_FASTREVIEW=1 | review tersusun pada FINAL exact (CP dapat lebih rendah, runtime berbeda; bawaan mati) |']
open(os.path.join(OUT, 'CARA_PASANG.md'), 'w').write('\n'.join(C) + '\n')

# ---------------- LAPORAN ----------------
L = ['# Laporan V12 — optimasi runtime dan merit dispatch', '', '**Status paket: %s**' % STATUS, '',
     'Lanjutan langsung V11 FINAL (branch `claude/gifted-ritchie-q0hfdp`). PHP 7.4.3, 4 inti CPU, 3 pekerja pembantu.', '',
     '## Source beku (SHA-256)', '', '```', rd(FRZ).strip(), '```', '',
     '## Runtime terhadap target (median 3 run, VM sama, V11 vs V12)', '', perf_sum, '',
     '## Merit dispatch generik', '', 'Uji generik 12 state nyata: %s. Rincian: AUDIT_DISPATCH_MERIT_V12.md.' % ('%s/%s PASS' % (mm.group(1), mm.group(2)) if mm else '-'), '',
     '## Path independence', '', mp.group(0) if mp else '-', '',
     '## Targeted test (clean-extract ZIP, PHP 7.4)', '', counts(CE, CEL), '', '## Full regression (source beku, satu kali)', '', counts(REG, REGL), '',
     '## Perubahan ekspektasi uji', '', '- Tidak ada ekspektasi uji V11 yang diubah. Suite baru V12: `v12/merit_check.php` (12 state, 95 pemeriksaan), rantai bahan bakar, determinisme pembantu on/off rute V12.',
     '- Harness HTTP V12 (`v12/v12_http.php`) = harness V7 + tanda tangan dispatch, commitment, profil; polling 150 ms (sama dengan UI).', '',
     '## Rollback', '', '- V11 FINAL disertakan di `rollback/V11/`.',
     '- V10 FINAL: source tidak tersedia di workspace sesi ini (tidak ada di ZIP checkpoint maupun ZIP dependensi uji); lihat `rollback/V10/README.md`. Hash sah V10 FINAL tercatat di `reports/ref/FREEZE_SHA256_V10.txt` bila ada.']
open(os.path.join(OUT, 'LAPORAN_V12.md'), 'w').write('\n'.join(L) + '\n')
print('ditulis ke', OUT)
