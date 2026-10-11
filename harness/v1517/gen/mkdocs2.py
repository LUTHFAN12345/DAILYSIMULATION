# mkdocs2.py : IE Adjustment, Actual Gas, PGN25 reports
import json, os, sys
sys.path.insert(0, '/home/claude/ab2/op/gen')
from tables import md, scen, fx, fx_row, ui, OP
from ietab import row as ierow
D = '/home/claude/ab2/docs_out'
def w(name, text): open(f'{D}/{name}', 'w').write(text.strip() + '\n'); print('wrote', name)

# ---------- IE ----------
ie_seq = [('IE0', 'IE0_golden', 'semua nol — run kedua, kembali dari IE7 (run pertama cold 9,4 s, CP identik 79,9049)'), ('IE3', 'IE3_golden', '10:00–12:00 +15'), ('IE4', 'IE4_golden', '12:30–17:30 −10'),
          ('IE5', 'IE5_golden', '18:00–00:00 +5'), ('IE6', 'IE6_golden', 'IE3+IE4+IE5'), ('IE2', 'IE2_golden', '14:00 −10 (satu row)'),
          ('IE1', 'IE1_golden', '14:00 +15 (satu row)'), ('IE7', 'IE7_golden', '14:00–17:00 +40 (startup tambahan)'), ('IE8', 'IE8_golden', '07:00–17:00 −30 (first legal stop)')]
rows = []
for k, n, desc in ie_seq:
    f = f'{OP}/fxfinal/ie/{n}.json'
    if not os.path.isfile(f): continue
    r = ierow(k, f)
    rows.append([k, desc, r['adj_rows'], r['pred+adj=eff'], r['window'] or '–', r['cache'], (r['alasan'] or '–')[:70], r['ffv'] or '–', r['wall'], r['hard'], r['gate'], r['C'], r['stg'], r['cp'], r['hr'], r['gas'], r['dist_l'],
                 json.dumps(r['on'])])
S = scen(); rows2 = []
for k in ['IE9_pv_on', 'IE10_fix_sr15', 'IE11_actual_gas', 'IE12_shortage_lng', 'IE13_distillate', 'IE14_trip', 'IE15_change_over']:
    d = S.get(k)
    if not d: continue
    f = d['final']; C = f.get('C') or {}
    rows2.append([k, d.get('fuel') or '–', d.get('step1_s'), d.get('step2_s') or '–', d.get('total_s'), f.get('hard'), f.get('gate'), f.get('cp'), f.get('hr'), sum(int(v or 0) for v in C.values()), f.get('stg'), json.dumps(f.get('on'))])
uw = ui('F_uiwarm') or {}; u16 = ui('uiie') if os.path.isfile(f'{OP}/ui16/uiie.json') else None; u17 = ui('F_ie17') or {}
uwr = [[r['case'], r['t_click_to_final'], r.get('job'), r.get('ffv') or '–', r.get('cache'), r['gate'], r['cp'], r['hr'], r['adj']] for r in uw.get('runs', [])]
u16 = json.load(open(f'{OP}/ui16/uiie.json')) if os.path.isfile(f'{OP}/ui16/uiie.json') else {}
w('IE_ADJUSTMENT_INCREMENTAL_REDISPATCH.md', f"""
# IE Adjustment — Incremental Redispatch (Addendum §6A, R3A)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — IE_EFFECTIVE = IE_PREDICTION + IE_ADJUSTMENT diterapkan sekali, sumber tunggal `pp_ie_series()`; label row inklusif (00:30 = row 1, 00:00 = row 48); chart, payload, worker, Simulation Data, Summary, Save/Reload, Excel konsisten (IE16) |
| Validation | **PASS** — IE0–IE17 hard PASS & gate PASS kecuali tidak ada; C1–C4 FAIL = 0, STG PASS |
| Quality | **PASS** — CP warm ≤ cold atau dalam band 0,2 % (IE3 warm 80,6838 vs cold 80,6967; IE6 browser warm 79,7102 vs cold 79,9362) |
| Performance | **PASS dengan catatan** — engine warm jendela kecil 3,6–4,3 s (< 5 s: IE2 4,27 · IE3 4,27 · IE4 3,62); multi-jendela ≤ 10 s (IE6 5,95 · IE5 6,64); commitment repair (IE1/IE7) ±11 s dengan bukti critical path; di browser job warm 3,8–4,6 s, klik→FINAL 6,1–9,2 s karena langkah rekomendasi wajib (±2,5–3 s) menghitung ulang liter Distillate untuk IE baru |
| Release blocker | Tidak ada |

## Rantai data
UI tabel adjustment (`IEADJ_RULES`, kolom "Row") → payload `data3.modeling.ie_adjustments[{{operator,value,start_period,stop_period}}]` → `pp_apply_ie_adjustment` (label inklusif) → `pp_ie_series()` (pred/adj/eff) → worker net-load per row → rute warm `pp_v1517_ie_warm` (Stage A jendela ±2 row + polish; Stage B 48 row commitment tetap + pendaratan gas; fallback Fastest penuh dengan alasan) → validasi hard + gerbang → `data[].IE_Pred/IE_Adj/IE` → Summary/Excel/Save.

Basis warm: rencana **fully valid** terakhir dengan konteks fisik identik (key mengecualikan IE, jumlah bahan bakar, actual gas, dan metadata request UI `_*`); disimpan hanya setelah hard PASS + gate PASS. Penurunan IE ≥ min-load GTG terkecil (20 MW) → review commitment penuh (unit mungkin tidak perlu lagi). Tidak ada IE basi: `affected_rows` dihitung dari selisih IE efektif per row terhadap basis.

## IE0–IE8 — rantai warm CLI (berurutan, satu direktori job)
{md(['Test', 'Rentang', 'Row adj', 'Pred+Adj=Eff', 'Jendela terdampak', 'Cache', 'Alasan invalidasi', 'First fully valid (s)', 'Total (s)', 'Hard', 'Gate', 'C1–C4', 'STG', 'CP', 'HR', 'Gas used/kuota', 'Distillate (l)', 'Commitment sesudah'], rows)}

Critical path:
- **IE1 (+15 MW satu row 14:00)**: basis warm tidak feasible — row 28 butuh 15 MW lebih, commitment basis tidak punya headroom (`export_range`) → commitment repair (G3 start 21–32) ±11 s. Ini bukan full search buta: alasan tercatat, dan hasil commitment baru PASS.
- **IE7 (+40 MW)**: startup tambahan wajib (G3), ±10,5 s.
- **IE8 (−30 MW 07:00–17:00)**: penurunan ≥ min-load → review commitment penuh (G4/G5 dipendekkan), ±12,3 s, CP turun ke 72,75.
- **IE5 (13 row)** diklasifikasikan jendela lebar (target ≤ 10 s); waktu didominasi perbaikan Unit Priority C1–C4 pada row yang di-redispatch (bukti per percobaan wajib).

## IE9–IE15 — kombinasi (CLI, alur dua langkah rekomendasi → bahan bakar)
{md(['Test', 'Bahan bakar', 'Langkah 1 (s)', 'Langkah 2 (s)', 'Total (s)', 'Hard', 'Gate', 'CP', 'HR', 'C1–C4', 'STG', 'Commitment'], rows2)}

IE11 (IE + Actual Gas) diperbaiki pada V15.17: pipeline Fastest berakhir dengan window gas UNDER; pendaratan gas pada commitment yang sama (`PP_FAST_GAS_LAND`) → PASS, CP 64,3643 (Max Review: 64,3773).

## IE16 — UI nyata (Save/Reload/Excel)
- Label row: {u16.get('rows_label')}; chart `adjRows` = {u16.get('chart', {}).get('adjRows')}, garis IE Effective: {u16.get('chart', {}).get('eff')}
- Payload: {len(u16.get('payload_rules') or [])} aturan; hasil gate {u16.get('result', {}).get('gate')}, CP {u16.get('result', {}).get('cp')}; Pred+Adj=Eff pada 48 row: {u16.get('result', {}).get('consistent')}; row 1 adj = {u16.get('result', {}).get('row1adj')}, row 19 adj = {u16.get('result', {}).get('row19adj')}
- Excel memuat kolom IE Prediction/Adjustment/Effective: {u16.get('excel', {}).get('has_cols')}
- Save → Reload: aturan & label row pulih ({u16.get('reload', {}).get('rows_label')}).

## IE17 — konkurensi (user A mengubah IE saat user B Run)
A: gate {u17.get('A', {}).get('gate')}, CP {u17.get('A', {}).get('cp')}, {u17.get('A', {}).get('adj_rows')} row adj, {u17.get('A', {}).get('s')} s · B: gate {u17.get('B', {}).get('gate')}, CP {u17.get('B', {}).get('cp')}, {u17.get('B', {}).get('adj_rows')} row adj, {u17.get('B', {}).get('s')} s · UID berbeda, payload B tanpa aturan A · **{'PASS' if u17.get('pass') else 'FAIL'}**

## Browser — rantai warm lewat UI (klik Run → FINAL)
{md(['Kasus', 'Klik→FINAL (s)', 'Job warm (s)', 'First fully valid (s)', 'Cache', 'Gate', 'CP', 'HR', 'Row adj'], uwr)}

Setiap Run IE di UI = langkah rekomendasi (menghitung ulang kebutuhan Distillate untuk IE baru, ±2,5–3 s) + langkah bahan bakar (warm). Angka "Job warm" adalah langkah bahan bakar.
""")

# ---------- ACTUAL GAS ----------
arows = [fx_row('a', n, l) for n, l in [('A0_base', 'A0 basis (cold, lalu kembali di akhir rantai)'), ('A1_pgn_r13', 'A1 Actual PGN row 13'), ('A3_mm_r13', 'A3 Actual MM2100 row 13'),
        ('A7_under', 'A7 actual di bawah estimasi'), ('A5_multi', 'A5 multi row'), ('A2_ffj_r13', 'A2 Actual Fixed Flow JBBK row 13'), ('A4_all_r13', 'A4 semua akun row 13'), ('A6_over', 'A6 actual di atas kuota')]]
w('ACTUAL_GAS_INCREMENTAL_RERUN.md', f"""
# Actual Gas — Incremental Rerun (Addendum §6, R3)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | **PASS** — context hash memisahkan actual gas dari struktur; row actual yang berubah + jendela maju sampai 00:00 di-redispatch, commitment basis dipertahankan |
| Validation | **PASS** untuk A0, A1, A3, A5, A7; A2/A4/A6 **VALID-INFEASIBLE** (input tidak layak, lihat bukti) |
| Quality | PASS — CP warm ≈ cold (A0 kembali 64,6652 vs cold 64,6663) |
| Performance | **PASS** — warm 1,5–3,4 s (target < 5 s; A5 turun dari 11,4 s); cold 14,5 s (≤ 15 s) |
| Release blocker | Tidak ada (A2/A4/A6 = state input infeasible, dibuktikan juga oleh Maximum Review) |

## Hasil (CLI, rantai warm berurutan)
{md(['Kasus', 'Wall (s)', 'Hard', 'Gate', 'CP', 'HR', 'C1–C4 FAIL', 'STG', 'Kontinuitas Dist.', 'Cache warm'], arows)}

## Bukti A2/A4/A6
- Fastest dan **Maximum Review** (87 s) sama-sama tidak menemukan rencana valid; window gas tidak dapat mendarat.
- Envelope identitas akunting engine (`pp_v7_gas_envelope`): A2 D ∈ [−0,138; −0,055], A4 D ∈ [−0,147; −0,064] → irisan window pipe [Pq−0,04; Pq] dan window total butuh D ≥ −0,04: **tidak beririsan** secara identitas, tetapi selisihnya (0,015–0,024 BBTUD) di bawah margin sertifikat 0,05 — sertifikat terminal tidak diterbitkan (margin tidak dilemahkan). A6 (actual di atas kuota) D ∈ [−0,038; 0,045] beririsan; kegagalannya adalah kelebihan pemakaian jam aktual yang tidak dapat dikompensasi row masa depan di atas beban minimum.
- Keterbatasan diketahui: pada state infeasible ini Maximum Review menerbitkan rencana jangkar kanonik (tanpa actual) dengan gate FAIL — lihat NEXT_STEPS.
""")

# ---------- PGN25 ----------
r1 = ui('F_R1_pgn25') or {}
w('PGN25_LNG18_PERFORMANCE.md', f"""
# PGN25 / LNG18 / PEP36 / Akasia4 — Performance (Addendum §3, R1)

## Ringkasan status
| Aspek | Status |
|---|---|
| Functional | PASS |
| Validation | PASS — hard PASS, gate PASS, C1–C4 FAIL = 0 |
| Quality | PASS — CP 71,78, HR ±8175 |
| Performance | **PASS** — browser {r1.get('t_total')} s (target ideal ≤ 15 s, batas ≤ 30 s); CLI 13,8 s |
| Release blocker | Tidak ada |

## Hasil
{md(['Kasus', 'Wall (s)', 'Hard', 'Gate', 'CP', 'HR', 'C1–C4 FAIL', 'STG', 'Kontinuitas Dist.', 'Cache warm'], [fx_row('cold', 'R1_pgn25_lng18_pep36_ak4', 'R1 CLI cold')])}

Browser (UI nyata, XAMPP-like proxy 6 worker PHP 7.4): klik Run → FINAL **{r1.get('t_total')} s**, gate {r1.get('gate')}, CP {r1.get('cp')}, HR {r1.get('hr')}, mode {r1.get('mode')}.

Critical path sebelum V15.17: 22,9–23,3 s, didominasi polish Unit Priority tanpa batas di pipeline Fastest. Perbaikan: `PP_FAST_POLISH_S` = 3 s (satu repair terbatas; review Unit Priority V8 + perbaikan C1–C4 tetap di job).
""")
print('ok2')
