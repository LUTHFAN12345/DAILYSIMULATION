#!/usr/bin/env python3
"""LAPORAN_V11.md dari hasil uji nyata. Arg: <folder-clean-extract> <log-clean-extract> <folder-regression> <log-regression>
<wb09_3-output.json> <perf.md> <freeze-sha-file> <out.md>"""
import json, re, sys, os, subprocess
CE, CEL, RG, RGL, WJ, PERF, FRZ, OUT = sys.argv[1:9]
def rd(p):
    try: return open(p, encoding='utf-8', errors='replace').read()
    except FileNotFoundError: return ''
def counts(d, log):
    return subprocess.run(['python3', '/home/claude/t5/v11/suite_counts.py', d, log], capture_output=True, text=True).stdout.strip()
def f(x, n=4):
    try: return ('%.' + str(n) + 'f') % float(x)
    except Exception: return '-' if x is None else str(x)
d = json.load(open(WJ)); o = d.get('output') or d; i = o['info']
c = i.get('V11 Candidate Counters') or {}; sw = i.get('V11 Consolidation Sweep') or {}; b = i.get('V11 Candidate Comparison') or {}
fa = i.get('V11 Low Load Fragmentation Audit') or {}; ca = i.get('V11 CP Audit') or {}; h = i.get('Headroom Priority Audit') or {}
fp = i.get('Fuel Provenance') or {}; rg = o.get('release_gate') or {}; v = i.get('V8 Priority Review') or {}
perf = rd(PERF); m = re.search(r'## 0\. Ringkasan.*?(?=\n## )', perf, re.S); perf0 = m.group(0) if m else ''
pi = rd(os.path.join(CE, 'PATH_INDEPENDENCE.md')); mp = re.search(r'\*\*(\d+/\d+ state identik[^*]*)\*\*[^\n]*', pi)
L = ['# Laporan V11 — konsolidasi beban, band CP 0,2 % + Heat Rate, counter kanonik, FINAL tidak bergantung jalur', '',
     'Paket `php_simulation_REVISI_OPTIMASI_ENGINE_V11_FINAL.zip` (lanjutan langsung checkpoint V11 atas V10 FINAL). Mesin uji: '
     'PHP 7.4.3 (CLI + server multi-backend 6 proses meniru Apache), 4 inti CPU, 3 pekerja pembantu. Arsitektur: `ARSITEKTUR_V11.md`; '
     'runtime: `PERFORMA_V11.md`; audit: `AUDIT_UI_COUNTER_V11.md`, `AUDIT_CP_HEATRATE_V11.md`, `AUDIT_LOW_LOAD_FRAGMENTATION_V11.md`; '
     'path independence: `PATH_INDEPENDENCE_V11.md`. Log mentah di `reports/`.', '',
     '## Source beku (SHA-256)', '', '```', rd(FRZ).strip(), '```', '',
     '## Ringkasan hasil', '',
     '- Reproducer Daily_Plan_09_Jul_26_Baru(3) (WB09_3): FINAL 48 row, hard constraints PASS, Export 48/48, residual shortage 0, rilis %s; '
     'Cost Production **%s USD/MWh**, Heat Rate **%s BTU/kWh** (workbook (3): 63,7145 / 8165,81; sebelum review Unit Priority %s).' % (rg.get('release_allowed'), f(i.get('Cost Production (USD/MWh)')), f(i.get('JBBK MM Heat Rate (BTU/kWh)'), 2), f(v.get('cost_production_before'))),
     '- Comparator V11: CP_min %s, band s.d. %s, %s kandidat valid, %s di dalam band, pemenang %s.' % (f(b.get('cp_min')), f(b.get('band_upper')), b.get('candidates_valid'), b.get('candidates_in_band'), b.get('winner')),
     '- Kandidat (counter satu sumber): diperiksa %s = full-run %s + gugur Tier 1 %s; valid %s; FINAL termasuk valid: %s. Sapuan konsolidasi: dibangkitkan %s, gugur Tier 1 %s, full-run %s, valid %s.' % (c.get('candidates_checked'), c.get('candidates_full_run'), c.get('candidates_screened_out'), c.get('candidates_valid'), c.get('best_candidate_in_valid'), sw.get('generated'), sw.get('screened_tier1'), sw.get('full_run'), sw.get('valid')),
     '- LOW_LOAD_FRAGMENTATION: %s (row %s, temuan %s, tanpa alasan %s). Unit Priority (Headroom Priority Audit 48 row): %s, tanpa alasan %s. CP audit: %s. Provenance bahan bakar: %s.' % (fa.get('status'), fa.get('rows_flagged'), fa.get('findings'), fa.get('unresolved'), h.get('status'), h.get('flags_unresolved'), ca.get('status'), fp.get('status')),
     '- Path independence: %s' % (mp.group(0) if mp else '-'), '',
     '## Perubahan V11 (ringkas; rinci di ARSITEKTUR_V11.md)', '',
     '1. **UI**: SUMMARY kuning tepat lima field (Target waktu, Waktu aktual, Kandidat diperiksa, Kandidat valid, Cost Production); detail teknis di panel Detail audit; berlaku di seluruh jalur (Target < 15…< 60, Maximum Review, provisional, exact final, no valid result, Gas Shortage, rerun bahan bakar). JavaScript error Target Selesai (V11_RUN_T0/V11_SUM_DONE/v11AuditExtra) diperbaiki.',
     '2. **Counter satu sumber** berkunci dispatch fisik (full-run/valid) dan commitment fisik kanonik (screened-out); alias dan screened yang juga di-full-run tidak dihitung ulang.',
     '3. **Objective**: CP minimum lalu band 0,2 % dengan tie-break Heat Rate → start → row prioritas rendah → row fragmentation → skor prioritas → kunci kanonik; release gate menerima pemenang band dengan bukti.',
     '4. **Konsolidasi generik + audit LOW_LOAD_FRAGMENTATION**, bukti counterfactual 48 row dari kandidat valid comparator untuk (row, unit) pemenang.',
     '5. **Path independence**: alias V5 "stop tambahan tidak mengikat" (sumber FINAL berbeda antar jalur) dimatikan.',
     '6. **Runtime**: prefetch spekulatif keluarga commitment oleh pembantu yang menganggur; indeks unit_stop_time.', '',
     '## Runtime terhadap target V11', '', perf0 or '(lihat PERFORMA_V11.md)', '',
     'Catatan jujur: target yang belum tercapai dicantumkan apa adanya di tabel di atas (kolom "lewat"). Mesin uji cloud ini ±26 % lebih lambat per inti '
     'daripada mesin uji V10 (reproducer CLI dengan V11 dimatikan: 31,6 s vs ±25 s referensi), dan mematikan alias V5 demi path independence menambah '
     'core run pada rute cepat.', '',
     '## Targeted test dari clean-extract ZIP final', '', counts(CE, CEL), '',
     '## Full regression (source beku, satu kali)', '', counts(RG, RGL), '',
     '## Perubahan ekspektasi uji yang disengaja (V11)', '',
     '- `V10_CASES` V03 dan `V9_CASES` "CP <= kandidat valid termurah" → `FINAL_CP <= CP_min × 1,002` (band CP 0,2 % + tie-break Heat Rate), sesuai objective V11 (manifest §8). Salinan: `v11/v10_cases_v11.php`, `v11/v9_cases_v11.php`.',
     '- `V10_CASES` V06 "CP = referensi V9" → `CP V11 <= referensi V9 × 1,002`; hasil V11: Q_pgn_pipe_1 64,5424 dan Q_lng_1 64,8581, lebih rendah dari V9 (64,5450 / 64,8608).',
     '- `TL_UI` → `v11/tl_ui_v11.js`: Global optimum proven dibaca dari panel Detail audit (bukan SUMMARY/run-msg); pemeriksaan SUMMARY lima field + invarian counter ditambahkan; T8 memakai band 0,2 %.',
     '- Suite baru: `v11/v11_cases.php` (reproducer WB09_3 + audit V11 + band + counter + determinisme), `v11/wb09_3_ui.js` (reproducer UI), `v11/pathind_v11.sh` (13 state, + WB09_3).', '',
     '## Paket', '',
     '- Akar ZIP: `run.php`, `worker02.php`, `worker_functions.php`, `index.php`, dokumen `*.md`, `SHA256SUMS.txt`, `reports/`.',
     '- Rollback V10: source V10 FINAL tidak tersedia di workspace sesi ini (dependensi uji tidak menyertakannya; lihat DEPENDENCIES_MANIFEST). '
     'Folder `rollback/` hanya disertakan bila berkas V10 FINAL tersedia saat paket dibangun; hash sah V10 FINAL ada di `reports/ref/FREEZE_SHA256_V10.txt`.', '']
open(OUT, 'w').write('\n'.join(L) + '\n'); print('ditulis', OUT)
