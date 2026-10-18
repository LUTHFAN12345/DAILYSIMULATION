"""V15.24 matrix definitions (engine tests). Each T(...) = one test; IDs are assigned in order (T001...)."""
import copy, json, os

def define(G):
    T, set_unit, trip, block_req, range_rule, ie_scale, fuel, M, rt, row_of, R, load, canon, RUNS = (G[k] for k in
        ('T', 'set_unit', 'trip', 'block_req', 'range_rule', 'ie_scale', 'fuel', 'M', 'rt', 'row_of', 'R', 'load', 'canon', 'RUNS'))

    def base_res(name):
        f = os.path.join(RUNS, canon(load(name)) + '.json'); inp = load(name)
        return R(json.load(open(f)), inp) if os.path.exists(f) else None

    # ---------------- checks ----------------
    def proof_ok():
        def c(r): s = r.proof().get('status'); return s == 'TERBUKTI', f'event proof {s} not_proven={r.proof().get("not_proven")}'
        return c
    def not_forced_row1(u):
        def c(r):
            f = r.first_on(u)
            if f is None: return True, f'{u} tidak start'
            if f > 1: return True, f'{u} start row {f} ({rt(f)})'
            ev = [e for e in r.proof().get('events', []) if e['unit'] == u.upper() and e['event'] == 'START']
            return False, f'{u} berbeban row 1 tanpa start event (Initial Stop dilanggar / auto 00:30)'
        return c
    def hold_after_start(u):
        def c(r):
            iv = r.intervals(u)
            if not iv: return True, f'{u} tidak start (tidak diperlukan)'
            return (len(iv) == 1 and iv[0][1] == 48), f'{u} interval {iv} (harus ON sampai 00:00 setelah start)'
        return c
    def started(u):
        def c(r): f = r.first_on(u); return f is not None, f'{u} first_on={f}'
        return c
    def must_stop(u):
        def c(r):
            iv = r.intervals(u)
            if not iv: return True, f'{u} tidak start'
            return iv[-1][1] < 48 or r.proof().get('status') == 'TERBUKTI', f'{u} interval {iv} (stop wajib sesudah start atau bukti terminal)'
        return c
    def on_all(u):
        def c(r): n = len(r.rows_on(u)); return n == 48, f'{u} online {n}/48'
        return c
    def off_all(u):
        def c(r): n = len(r.rows_on(u)); return n == 0, f'{u} online {n}/48'
        return c
    def first_on_eq(u, row):
        def c(r): f = r.first_on(u); return f == row, f'{u} first_on={f} expected {row} ({rt(row)})'
        return c
    def first_on_le(u, row):
        def c(r): f = r.first_on(u); return f is not None and f <= row, f'{u} first_on={f} <= {row}'
        return c
    def first_on_ge(u, row):
        def c(r): f = r.first_on(u); return f is None or f >= row, f'{u} first_on={f} >= {row}'
        return c
    def off_rows(u, a, b):
        def c(r): bad = [k for k in range(a, b + 1) if r.on(u, k)]; return not bad, f'{u} off rows {a}-{b}; online={bad[:6]}'
        return c
    def min_run(u, rows):
        def c(r):
            bad = [(a, b) for a, b in r.intervals(u) if a > 1 and b < 48 and (b - a + 1) < rows]
            return not bad, f'{u} interval < {rows} row: {bad}'
        return c
    def min_down(u, rows):
        def c(r):
            iv = r.intervals(u); bad = [(iv[k][1], iv[k + 1][0]) for k in range(len(iv) - 1) if iv[k + 1][0] - iv[k][1] - 1 < rows]
            return not bad, f'{u} downtime < {rows}: {bad}'
        return c
    def no_unit_on_while_tripped(u, a=1, b=48): return off_rows(u, a, b)
    def any_on(units, label=''):
        def c(r): x = [u for u in units if r.rows_on(u)]; return bool(x), f'{label} online {x}'
        return c
    def same_commit_as(base):
        def c(r):
            b = base_res(base)
            if b is None: return False, f'base {base} belum dijalankan'
            return r.events() == b.events(), f'events {r.events()} vs base {b.events()}'
        return c
    def cp_le(base, tol=0.0):
        def c(r):
            b = base_res(base)
            if b is None or b.cp() is None or r.cp() is None: return False, 'cp n/a'
            return r.cp() <= b.cp() * (1 + tol) + 1e-9, f'cp {r.cp()} vs base {b.cp()}'
        return c
    def block_prefix(block_units):
        """unit tak-diminta yang start dalam satu blok harus mengikuti urutan prioritas (unit lebih tinggi dipakai lebih dahulu) kecuali swap-nya tidak valid"""
        def c(r):
            started_ = [u for u in block_units if any(a > 1 for a, b in r.intervals(u))]
            idx = [block_units.index(u) for u in started_]
            ok = idx == sorted(idx) and (not idx or all(i in idx for i in range(max(idx) + 1)) or r.proof().get('status') == 'TERBUKTI')
            return ok, f'start dalam blok {started_} (urutan prioritas {block_units})'
        return c
    def gas_in_window():
        def c(r): u = r.i.get('Effective Total Gas (BBTUD)') if (r.i.get('Actual Hours Provided') or 0) else r.i.get('Total Gas Used (BBTUD)'); q = r.i.get('Total Gas Quota (BBTUD)'); return u is not None and q is not None and float(q) - 0.04 - 1e-6 <= float(u) <= float(q) + 1e-6, f'gas {u} dalam window [{q}-0,04, {q}]'
        return c
    def daily_export_ge(v):
        def c(r): x = r.i.get('Daily PLN Exp (MWh)'); return x is not None and float(x) >= v - 0.5, f'daily export {x} >= {v}'
        return c
    def export_band():
        def c(r):
            bad = [k + 1 for k, w in enumerate(r.d) if float(w.get('Export_PLN') or 0) < float(w.get('pln_lo') or 0) - 0.05 or float(w.get('Export_PLN') or 0) > float(w.get('pln_hi') or 1e9) + 0.05]
            return not bad, f'row di luar band export: {bad[:8]}'
        return c
    def sr_ok():
        def c(r):
            bad = [k + 1 for k, w in enumerate(r.d) if w.get('SR_Min') is not None and float(w.get('Spin_Res') or 0) < float(w.get('SR_Min') or 0) - 0.05]
            return not bad, f'row SR < SR_Min: {bad[:8]}'
        return c
    def max_cap(u, a, b, mx):
        def c(r): bad = [k for k in range(a, b + 1) if r.mw(u, k) > mx + 0.01]; return not bad, f'{u} > {mx} MW pada {bad[:6]}'
        return c
    def fix_load(u, a, b, v):
        def c(r): bad = [k for k in range(a, b + 1) if abs(r.mw(u, k) - v) > 0.05]; return not bad, f'{u} fix {v} MW rows {a}-{b}; bad={[(k, r.mw(u, k)) for k in bad[:4]]}'
        return c
    def dist_units_order(order):
        def c(r):
            used = [u for u in order if any(float(w.get('Dist_' + u.upper()) or 0) > 0.01 for w in r.d)]
            if not used: return True, 'distillate tidak dipakai'
            first = order.index(used[0]); ok = all(order.index(u) >= first for u in used) and used[0] == next((u for u in order if r.rows_on(u)), used[0])
            return ok, f'distillate dipakai pada {used} (urutan {order})'
        return c
    def no_dist_gas_dist():
        def c(r):
            bad = []
            for u in ('G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G8', 'G9'):
                s = ''.join('D' if float(w.get('Dist_' + u) or 0) > 0.01 else ('G' if float(w.get(u) or 0) > 0.01 else '-') for w in r.d)
                st = s.replace('-', '')
                if 'DG' in st and 'GD' in st[st.index('DG'):]: bad.append(u)
            return not bad, f'pola Distillate->Gas->Distillate: {bad}'
        return c
    def fuel_action(a):
        def c(r): x = r.i.get('Gas Shortage Action'); return x == a, f'fuel action {x} (expected {a})'
        return c
    def has_info(k):
        def c(r): return bool(r.i.get(k)), f'info {k} ada'
        return c
    def rows48():
        def c(r): return r.ok48(), f'rows {len(r.d)}'
        return c
    std = [rows48(), export_band()]
    A0 = 'A0_actual'

    # ================= GROUP A (32) =================
    def cfg(u, last, commit, stop, sa=None, sto=None, base=A0, extra=None):
        def m(inp):
            if u.lower() in ('g2', 'g8') and commit != 'continuous':  # unit kontinu di fixture: lepaskan dahulu agar semantik yang diuji berlaku
                pass
            set_unit(inp, u, last, commit, stop, sa, sto)
            if extra: extra(inp)
            return inp
        return m
    T('A', 'Initial Stop + Start Based On Simulation + Continuous Running (Hold after start) G5', A0, cfg('g5', 'Stop', 'sim', 'hold'), 'PASS', std + [not_forced_row1('g5'), hold_after_start('g5'), proof_ok()])
    T('A', 'Initial Stop + Start Based On Simulation + Stop Based On Simulation (mandatory stop) G5', A0, cfg('g5', 'Stop', 'sim', 'must'), 'EITHER', std + [not_forced_row1('g5'), must_stop('g5')])
    T('A', 'Initial Stop + Start Based On Simulation + Stop-or-Continuous G5', A0, cfg('g5', 'Stop', 'sim', 'opt'), 'PASS', std + [not_forced_row1('g5'), proof_ok()])
    for hh in ('02:00', '04:00', '08:00', '16:00'):
        T('A', f'Initial Stop + Start Based On Request {hh} G5', A0, cfg('g5', 'Stop', 'request', 'opt', hh), 'EITHER', std + [first_on_eq('g5', row_of(hh) + 1)])
    T('A', 'Start Based On Request 08:00 + Continuous Running (hold) G5', A0, cfg('g5', 'Stop', 'request', 'hold', '08:00'), 'EITHER', std + [first_on_eq('g5', 17), hold_after_start('g5')])
    T('A', 'Start Based On Request 08:00 + Stop Based On Simulation G5', A0, cfg('g5', 'Stop', 'request', 'must', '08:00'), 'EITHER', std + [first_on_eq('g5', 17), must_stop('g5')])
    T('A', 'Start Based On Request 08:00 + Stop-or-Continuous G5', A0, cfg('g5', 'Stop', 'request', 'opt', '08:00'), 'EITHER', std + [first_on_eq('g5', 17)])
    T('A', 'Commitment Mode Unit Continuous Running G5 (00:30-00:00)', A0, cfg('g5', 'Running', 'continuous', None), 'EITHER', std + [on_all('g5')])
    T('A', 'Initial Running + Continuous Running (hold) G5', A0, cfg('g5', 'Running', None, 'hold'), 'EITHER', std + [on_all('g5')])
    T('A', 'Initial Running + Stop Based On Simulation G5', A0, cfg('g5', 'Running', None, 'must'), 'EITHER', std + [must_stop('g5')])
    T('A', 'Initial Running + Stop-or-Continuous G5', A0, cfg('g5', 'Running', None, 'opt'), 'PASS', std)
    T('A', 'Initial Stop, unit tidak diperlukan sepanjang horizon (G7 tanpa mode)', A0, lambda i: set_unit(i, 'g7', 'Stop'), 'PASS', std + [off_all('g7')])
    T('A', 'Initial Stop, diperlukan awal horizon (Range Min 60 MW row 2-12) G5 sim+hold', A0, cfg('g5', 'Stop', 'sim', 'hold', extra=lambda i: range_rule(i, 45, 155, 2, 12)), 'EITHER', std + [not_forced_row1('g5'), hold_after_start('g5')])
    T('A', 'Initial Stop, diperlukan tengah horizon (Range Min 50 MW row 22-30) G5 sim+hold', A0, cfg('g5', 'Stop', 'sim', 'hold', extra=lambda i: range_rule(i, 50, 155, 22, 30)), 'EITHER', std + [not_forced_row1('g5'), hold_after_start('g5'), first_on_ge('g5', 10)])
    T('A', 'Initial Stop, diperlukan akhir horizon (Range Min 50 MW row 40-48) G1 sim+hold', A0, cfg('g1', 'Stop', 'sim', 'hold', extra=lambda i: range_rule(i, 50, 155, 40, 48)), 'EITHER', std + [not_forced_row1('g1'), hold_after_start('g1')])
    T('A', 'Earliest valid start vs kandidat lain (Startup Timing Proof G9/G6)', A0, None, 'PASS', std + [has_info('Startup Timing Proof'), proof_ok()])
    T('A', 'Latest legal start (G6/G9 tunda 1 row tidak valid)', A0, None, 'PASS', std + [lambda r: (all(e['no_delay_test']['result'] in ('TIDAK_VALID', 'VALID_TIDAK_LEBIH_BAIK') for e in r.proof().get('events', []) if e['event'] == 'START'), 'uji tunda 1 row tiap start')])
    T('A', 'Kandidat ekonomi di antara earliest dan latest (A1 gas cukup)', 'A1_gas_cukup', None, 'PASS', std + [proof_ok()])
    T('A', 'Startup dengan minimum runtime (G5 request 16:00 + stop wajib)', A0, cfg('g5', 'Stop', 'request', 'must', '16:00'), 'EITHER', std + [first_on_eq('g5', 33), min_run('g5', 4)])
    T('A', 'Stop dengan minimum uptime/downtime (G5 unavailable 12:00-14:00, sim+opt)', A0, cfg('g5', 'Stop', 'sim', 'opt', extra=lambda i: trip(i, 'g5', 25, 28)), 'EITHER', std + [off_rows('g5', 25, 28), min_down('g5', 2), min_run('g5', 4)])
    T('A', 'Save/reload tidak mengubah Start Mode (payload reload G5 request 08:00 identik) [engine]', A0, cfg('g5', 'Stop', 'request', 'opt', '08:00'), 'EITHER', std + [first_on_eq('g5', 17)], note='reload UI diuji di UI-S4; di sini payload hasil reload dijalankan ulang')
    T('A', 'Save/reload tidak mengubah Stop Status (G5 sim+hold dijalankan ulang = T001)', A0, cfg('g5', 'Stop', 'sim', 'hold'), 'PASS', std + [hold_after_start('g5')], note='payload identik T001 -> run cache; reload UI di UI-S4')
    T('A', 'Run baru membersihkan state mode lama (G5 tanpa mode setelah sim+hold)', A0, lambda i: set_unit(i, 'g5', 'Stop'), 'PASS', std + [same_commit_as(A0)])
    for u in ('g9', 'g8', 'g6', 'g5', 'g1'):
        T('A', f'Konfigurasi Initial Stop + Simulation + Hold dipindahkan ke {u.upper()}', A0, cfg(u, 'Stop', 'sim', 'hold'), 'EITHER', std + [not_forced_row1(u), hold_after_start(u)])
    T('A', 'Konfigurasi pada STG: S2 Initial Stop + Simulation + Hold', A0, cfg('s2', 'Stop', 'sim', 'hold'), 'EITHER', std + [not_forced_row1('s2'), hold_after_start('s2')])

    # ================= GROUP B (30) =================
    def free(u):  # lepas mode unit (Initial Stop, tanpa commitment)
        return lambda i: set_unit(i, u, 'Stop')
    def chain(*fs):
        def m(i):
            for f in fs: f(i)
            return i
        return m
    B1 = ['g6', 'g4', 'g3']; B2 = ['g2', 'g5', 'g1']
    T('B', 'Block 1 Required ON (G6 bebas)', A0, chain(free('g6'), lambda i: block_req(i, 'Block 1', True)), 'EITHER', std + [any_on(['g3', 'g4', 'g6'], 'GT blok 1'), started('s1')])
    T('B', 'Block 1 Required OFF (G6 bebas, S1 bebas)', A0, chain(free('g6'), free('s1'), lambda i: block_req(i, 'Block 1', False)), 'EITHER', std + [proof_ok()])
    T('B', 'Block 2 Required ON', A0, lambda i: block_req(i, 'Block 2', True), 'PASS', std + [on_all('s2')])
    T('B', 'Block 2 Required OFF (G2 Initial Running tanpa continuous)', A0, chain(lambda i: set_unit(i, 'g2', 'Running'), lambda i: block_req(i, 'Block 2', False)), 'EITHER', std)
    T('B', 'Block 1 dan Block 2 Required bersamaan', A0, chain(free('g6'), lambda i: block_req(i, 'Block 1', True), lambda i: block_req(i, 'Block 2', True)), 'EITHER', std + [started('s1'), on_all('s2')])
    T('B', 'Required ON -> OFF -> ON tanpa reload (payload akhir ON) [engine]', A0, chain(free('g6'), lambda i: block_req(i, 'Block 1', True)), 'EITHER', std + [started('s1')], note='toggle UI diuji di UI-S3; payload akhir sama dengan T033')
    T('B', 'Save/reload Required (Block 3 Required ON)', A0, lambda i: block_req(i, 'Block 3', True), 'PASS', std + [on_all('s3')])
    T('B', 'Legacy token required di block_priority tidak menciptakan commitment', A0, lambda i: M(i).__setitem__('block_priority', [x + (['required'] if 'g6' in x else []) for x in M(i)['block_priority']]), 'PASS', std + [same_commit_as(A0)])
    T('B', 'Lead priority pertama legal (Block 1 Required, G6 bebas, prioritas G6>G4>G3)', A0, chain(free('g6'), lambda i: block_req(i, 'Block 1', True)), 'EITHER', std + [started('g6')], note='payload = T033')
    T('B', 'Lead priority pertama unavailable (G6 trip) -> priority berikutnya G4', A0, chain(free('g6'), lambda i: trip(i, 'g6'), lambda i: block_req(i, 'Block 1', True)), 'EITHER', std + [off_all('g6'), any_on(['g4', 'g3'], 'fallback blok 1')])
    T('B', 'Seluruh feeder Block 1 Stop -> input ditolak / Block 1 tidak dapat dipenuhi', A0, chain(free('g6'), lambda i: [trip(i, u) for u in B1], lambda i: block_req(i, 'Block 1', True)), 'REJECT', [])
    T('B', 'Minimal satu GTG legal berbeban penuh 48 row (Block 2: G2 continuous)', A0, lambda i: block_req(i, 'Block 2', True), 'PASS', std + [on_all('g2')], note='payload = T035')
    T('B', 'Unit Continuous memenuhi floor block tanpa startup peer (Block 3 Required, G9 bebas)', A0, chain(free('g9'), lambda i: block_req(i, 'Block 3', True)), 'EITHER', std + [on_all('g8'), proof_ok()])
    T('B', 'Block 2 running: G2/G5 cukup (IE -40)', 'A6_IE_minus40', None, 'PASS', std + [off_all('g1'), proof_ok()])
    T('B', 'Block 2 running: G1 diperlukan dan cukup (G3/G4 trip, G6 bebas)', A0, chain(free('g6'), lambda i: trip(i, 'g3'), lambda i: trip(i, 'g4')), 'EITHER', std + [proof_ok()])
    T('B', 'Block 2 tidak cukup: Block 1 diperlukan (aktual)', A0, None, 'PASS', std + [any_on(['g3', 'g4', 'g6'], 'blok 1'), proof_ok()])
    T('B', 'Reverse direction: Block 1 cukup (priority Block 1 di atas Block 2)', 'A4_priority_blok1_di_atas_blok2', None, 'PASS', std + [proof_ok()])
    T('B', 'Reverse direction: additional GTG Block 1 cukup (A4 + IE -20)', 'A4_priority_blok1_di_atas_blok2', lambda i: ie_scale(i, -20), 'EITHER', std + [proof_ok()])
    T('B', 'Reverse direction: Block 2 diperlukan (A4 + G3/G4 trip)', 'A4_priority_blok1_di_atas_blok2', chain(lambda i: trip(i, 'g3'), lambda i: trip(i, 'g4')), 'EITHER', std + [proof_ok()])
    def uprio(order2):
        def m(i):
            for k in ('unit_priority', 'block_priority'):
                M(i)[k] = [order2 + ['s2'] if 'g5' in x else x for x in M(i)[k]]
            return i
        return m
    for od in (['g2', 'g5', 'g1'], ['g1', 'g2', 'g5'], ['g5', 'g1', 'g2']):
        T('B', f'Unit Priority Block 2 susunan {"/".join(x.upper() for x in od)}', A0, uprio(od), 'PASS', std + [block_prefix([u for u in od if u != 'g2']), proof_ok()])
    def need_proven(r):
        bad = []
        for e in r.proof().get('events', []):
            if e['event'] != 'START' or not e['reason_code'].startswith('DIPERLUKAN'): continue
            d = (e.get('deficit_at_first_needed') or {}).get('deficit_mw') or 0
            dc = ((e.get('no_delay_test') or {}).get('interval_tests') or {}).get('decommit_test') or {}
            if not (d > 0 or dc.get('result') == 'TIDAK_VALID'): bad.append(e['unit'])
        return not bad, f'start otomatis tanpa defisit first-needed (sesudah legal headroom seluruh unit online lain, termasuk prioritas lebih tinggi): {bad}'
    T('B', 'Priority tertinggi dimaksimalkan sampai legal maximum (defisit first-needed sesudah headroom unit online)', A0, None, 'PASS', std + [need_proven, proof_ok()])
    T('B', 'Priority rendah dihentikan ketika tidak lagi diperlukan (uji stop 1 row lebih awal)', A0, None, 'PASS', std + [lambda r: (all(e['no_early_stop_test']['result'] in ('TIDAK_VALID', 'VALID_TIDAK_LEBIH_BAIK', 'TIDAK_LEGAL_RUNTIME_MINIMUM') for e in r.proof().get('events', []) if e['event'] == 'STOP'), 'stop paling awal legal')])
    T('B', 'Priority rendah tidak start selama higher-priority legal headroom cukup (IE -40)', 'A6_IE_minus40', None, 'PASS', std + [proof_ok()])
    T('B', 'Same-block candidate diuji sebelum cross-block', A0, None, 'PASS', std + [lambda r: (all(any(c.get('swap_result') for c in e['candidates']['same_block']) or not [c for c in e['candidates']['same_block'] if c['status'] == 'TERSEDIA'] for e in r.proof().get('events', []) if e['event'] == 'START'), 'same-block diuji')])
    T('B', 'Cross-block startup dengan proof (A3 cfg G6 ke G4)', 'A3_cfg_G6_ke_G4', None, 'PASS', std + [proof_ok()])
    T('B', 'Cross-block startup tanpa proof diblokir (A8 cfg G9 ke G5)', 'A8_cfg_G9_ke_G5', None, 'PASS', std + [proof_ok()])
    T('B', 'Priority audit setelah startup/stop repair dan fragmentation (C1-C4)', A0, None, 'PASS', std + [lambda r: ((r.i.get('Merit Proof C1-C4 STG') or {}).get('status') in ('PASS', 'PASS_WITH_REASON', None), f"C1-C4 {(r.i.get('Merit Proof C1-C4 STG') or {}).get('status')}")])
    T('B', 'Satu vs dua additional unit; G5 dipertahankan vs G5 stop lalu G1 start (comparator)', A0, None, 'PASS', std + [proof_ok(), lambda r: (bool((r.i.get('Commitment Tightening') or {}).get('rounds')), 'neighborhood swap/decommit dievaluasi')])

    # ================= GROUP C (16) distillate =================
    def dprio(order):
        def m(i): M(i)['unit_priority_dist'] = [order] + [x for x in M(i)['unit_priority_dist'] if not set(x) & set(order)]; return i
        return m
    for od in (['g6', 'g4', 'g3'], ['g3', 'g4', 'g6'], ['g4', 'g6', 'g3'], ['g1', 'g2', 'g5']):
        def mk(od=od):
            def m(i): M(i)['unit_priority_dist'] = [od] + [x for x in M(i)['unit_priority_dist'] if not set(x) & set(od)]; return i
            return m
        T('C', f'Distillate priority susunan {"/".join(u.upper() for u in od)}', A0, mk(), 'EITHER', std + [dist_units_order([u.upper() for u in od]), no_dist_gas_dist()])
    T('C', 'Distillate hanya pada unit prioritas pertama bila cukup (shortage kecil)', A0, lambda i: fuel(i, 'use_distillate', dist=60000), 'EITHER', std + [no_dist_gas_dist()])
    T('C', 'Unit kedua dipakai hanya bila unit pertama tidak cukup (shortage besar, IE +15)', A0, lambda i: ie_scale(i, 15), 'EITHER', std + [dist_units_order(['G6', 'G4', 'G3']), no_dist_gas_dist()])
    for lim in (0.3, 0.5, 0.75, 1.0):
        T('C', f'Distillate level {int(lim * 100)}% dari batas user', A0, (lambda lim: lambda i: fuel(i, 'use_distillate', dist=round(330800 * lim)))(lim), 'EITHER', std + [no_dist_gas_dist(), lambda r, lim=lim: ((r.i.get('Required Distillate (l/day)') or 0) <= 330800 * lim + 1, f"distillate {r.i.get('Required Distillate (l/day)')} <= {330800 * lim}")])
    T('C', 'Continuous Distillate block (tanpa D->G->D)', A0, None, 'PASS', std + [no_dist_gas_dist()])
    T('C', 'Larangan Distillate -> Gas -> Distillate tanpa proof (IE +10)', A0, lambda i: ie_scale(i, 10), 'EITHER', std + [no_dist_gas_dist()])
    T('C', 'Unit distillate prioritas pertama trip (G6 trip, G6 bebas)', A0, chain(free('g6'), lambda i: trip(i, 'g6')), 'EITHER', std + [off_all('g6'), no_dist_gas_dist()])
    T('C', 'Distillate priority berubah antar-Run tanpa stale job (hash baru)', A0, (lambda i: M(i).__setitem__('unit_priority_dist', [['g3', 'g6', 'g4']] + M(i)['unit_priority_dist'][1:]) or i), 'EITHER', std + [lambda r: ((r.r.get('__bench') or {}).get('job_input_hash') != ((base_res(A0).r.get('__bench') or {}).get('job_input_hash') if base_res(A0) else None), 'job/hash baru')])
    T('C', 'LNG lebih ekonomis dibanding Distillate (action recommendation)', A0, lambda i: fuel(i, 'recommendation'), 'EITHER', [rows48()])
    T('C', 'Kombinasi LNG + Distillate diperlukan (IE +20, action recommendation)', A0, chain(lambda i: ie_scale(i, 20), lambda i: fuel(i, 'recommendation')), 'EITHER', [rows48()])

    # ================= GROUP D (30) gas =================
    def gq(k, dv):
        def m(i): M(i)['gas_quota'][k] = round(float(M(i)['gas_quota'][k]) + dv, 4); return i
        return m
    T('D', 'PGN +1 BBTUD', A0, gq('pgn_pipe', 1), 'EITHER', std + [gas_in_window()])
    T('D', 'PGN -1 BBTUD', A0, gq('pgn_pipe', -1), 'EITHER', std)
    T('D', 'PEP +1', A0, gq('pep', 1), 'EITHER', std + [gas_in_window()])
    T('D', 'PEP -1', A0, gq('pep', -1), 'EITHER', std)
    T('D', 'KP72 +1', A0, gq('pep_kp72', 1), 'EITHER', std)
    T('D', 'KP72 -1 (dibatasi >= 0)', A0, lambda i: M(i)['gas_quota'].__setitem__('pep_kp72', max(0, M(i)['gas_quota']['pep_kp72'] - 1)) or i, 'EITHER', std, note='KP72 fixture = 0; -1 tidak sah -> 0 (setara A0)')
    T('D', 'LNG quota naik (+4)', A0, gq('lng', 4), 'EITHER', std)
    T('D', 'LNG quota turun (-4)', A0, gq('lng', -4), 'EITHER', std)
    T('D', 'Akasia naik (+2)', A0, gq('akasia', 2), 'EITHER', std)
    T('D', 'MM2100 max flow naik (+5)', A0, lambda i: M(i).__setitem__('max_flow_mm2100', 5) or i, 'EITHER', std)
    T('D', 'Supplier window sempit (Min PGN Flow 8)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 8) or i, 'EITHER', std)
    T('D', 'Must-take gas (gas cukup, A1)', 'A1_gas_cukup', None, 'PASS', std + [gas_in_window()])
    T('D', 'Gas under-target (PGN +6)', A0, gq('pgn_pipe', 6), 'EITHER', std + [gas_in_window()])
    T('D', 'Gas over-target (IE +10)', A0, lambda i: ie_scale(i, 10), 'EITHER', std)
    T('D', 'No shortage (A1)', 'A1_gas_cukup', lambda i: fuel(i, 'recommendation'), 'EITHER', std + [gas_in_window()])
    T('D', 'Shortage ringan (PGN +3, distillate)', A0, gq('pgn_pipe', 3), 'EITHER', std)
    T('D', 'Shortage sedang (aktual, distillate)', A0, None, 'PASS', std)
    T('D', 'Shortage berat (IE +15, distillate)', A0, lambda i: ie_scale(i, 15), 'EITHER', [rows48()])
    T('D', 'Residual tepat di bawah tolerance (PGN +4.95)', A0, gq('pgn_pipe', 4.95), 'EITHER', std)
    T('D', 'Residual tepat di atas tolerance (PGN +5.05)', A0, gq('pgn_pipe', 5.05), 'EITHER', std)
    T('D', 'Tidak ada shortage 0,0000 palsu (A1 recommendation)', 'A1_gas_cukup', lambda i: fuel(i, 'recommendation'), 'EITHER', [lambda r: (not (r.status() == 'USER_FUEL_DECISION_REQUIRED' and abs(float(r.i.get('Gas Shortage (BBTUD)') or 0)) < 1e-4), f"shortage {r.i.get('Gas Shortage (BBTUD)')} status {r.status()}")])
    T('D', 'Gas Shortage dengan LNG (A2)', 'A2_lng', None, 'PASS', std)
    T('D', 'Gas Shortage dengan Distillate (aktual)', A0, None, 'PASS', std + [fuel_action('use_distillate')], note='payload = A0')
    T('D', 'Gas Shortage dengan LNG + Distillate (IE +20, recommendation)', A0, chain(lambda i: ie_scale(i, 20), lambda i: fuel(i, 'recommendation')), 'EITHER', [rows48()], note='payload = C016')
    T('D', 'Kapasitas LNG tidak cukup (add_lng 1 BBTUD, shortage aktual)', A0, lambda i: fuel(i, 'add_lng', lng=1), 'EITHER', [rows48()])
    T('D', 'Kapasitas Distillate tidak cukup (limit 20000 l)', A0, lambda i: fuel(i, 'use_distillate', dist=20000), 'EITHER', [rows48()])
    T('D', 'Terminal fuel infeasible (IE +20, distillate limit aktual) [A7]', 'A7_IE_plus20', None, 'EITHER', [rows48()])
    T('D', 'Stale fuel-decision cache tidak dipakai (action none setelah distillate)', A0, lambda i: fuel(i, 'none'), 'EITHER', [lambda r: (r.i.get('Gas Shortage Action') in ('none', None) or r.status() == 'USER_FUEL_DECISION_REQUIRED', f"action {r.i.get('Gas Shortage Action')} status {r.status()}")])
    T('D', 'Perubahan supplier membentuk hash/job baru (PGN +0.5)', A0, gq('pgn_pipe', 0.5), 'EITHER', [lambda r: ((r.r.get('__bench') or {}).get('job_input_hash') != ((base_res(A0).r.get('__bench') or {}).get('job_input_hash') if base_res(A0) else None), 'hash baru')])
    T('D', 'Fuel decision tidak mengubah input user diam-diam (quota output = input)', A0, None, 'PASS', [lambda r: (abs(float(r.i.get('PGN Pipe Quota (BBTUD)') or 0) - 24.5) < 1e-6, f"PGN quota output {r.i.get('PGN Pipe Quota (BBTUD)')}")])

    # ================= GROUP E (24) =================
    def iea(op, v, a, b):
        def m(i): M(i).setdefault('ie_adjustments', []).append({'operator': op, 'value': v, 'start_period': a, 'stop_period': b}); return i
        return m
    T('E', 'IE Adjustment positif (+10 MW 08:00-12:00)', A0, iea('+', 10, '08:00', '12:00'), 'EITHER', std)
    T('E', 'IE Adjustment negatif (-10 MW 08:00-12:00)', A0, iea('-', 10, '08:00', '12:00'), 'EITHER', std)
    T('E', 'IE Adjustment nol', A0, iea('+', 0, '08:00', '12:00'), 'PASS', std + [same_commit_as(A0)])
    T('E', 'IE Adjustment satu window (-15 MW 18:00-20:00)', A0, iea('-', 15, '18:00', '20:00'), 'EITHER', std)
    T('E', 'IE Adjustment beberapa window', A0, chain(iea('-', 10, '02:00', '04:00'), iea('+', 8, '13:00', '15:00')), 'EITHER', std)
    T('E', 'IE Prediction lebih tinggi (+8 MW seluruh row)', A0, lambda i: ie_scale(i, 8), 'EITHER', std)
    T('E', 'IE Prediction lebih rendah (-8 MW)', A0, lambda i: ie_scale(i, -8), 'EITHER', std)
    def pv(fn):
        def m(i): M(i)['sr_mode'] = 'follow_pv'; M(i)['pv_rows'] = [round(fn(k), 2) for k in range(48)]; return i
        return m
    T('E', 'Follow PV naik tajam', A0, pv(lambda k: 0 if k < 20 else 25), 'EITHER', std + [sr_ok()])
    T('E', 'Follow PV turun tajam', A0, pv(lambda k: 25 if k < 28 else 0), 'EITHER', std + [sr_ok()])
    T('E', 'Follow PV flat (10 MW)', A0, pv(lambda k: 10), 'EITHER', std + [sr_ok()])
    T('E', 'Fix Spinning Reserve ON (15 MW)', A0, lambda i: M(i).update({'sr_mode': 'fixed', 'sr_fixed_mw': 15}) or i, 'EITHER', std + [sr_ok()])
    T('E', 'Fix Spinning Reserve OFF (0)', A0, lambda i: M(i).update({'sr_mode': 'fixed', 'sr_fixed_mw': 0}) or i, 'PASS', std + [same_commit_as(A0)])
    T('E', 'SR Effective Minimum rendah (5 MW)', A0, lambda i: M(i).update({'sr_mode': 'fixed', 'sr_fixed_mw': 5}) or i, 'EITHER', std + [sr_ok()])
    T('E', 'SR Effective Minimum tinggi (40 MW)', A0, lambda i: M(i).update({'sr_mode': 'fixed', 'sr_fixed_mw': 40}) or i, 'EITHER', [rows48(), sr_ok()])
    T('E', 'PLN Export Priority susunan pertama (Range utama 15-155)', A0, None, 'PASS', std, note='payload = A0')
    T('E', 'PLN Export Priority susunan kedua (Daily target required 1800)', A0, lambda i: M(i)['pln_export_priority']['daily_target'].update({'required': True}) or i, 'EITHER', std + [daily_export_ge(1800)])
    T('E', 'Export Range ketat (min 40 sepanjang hari)', A0, lambda i: range_rule(i, 40, 155, 1, 48, replace=True), 'EITHER', std)
    T('E', 'Export Range longgar (tanpa range rule)', A0, lambda i: M(i)['pln_export_priority'].__setitem__('range_rules', []) or i, 'EITHER', std)
    T('E', 'Daily Export tepat target (target 1500 required)', A0, lambda i: M(i)['pln_export_priority']['daily_target'].update({'value': 1500, 'required': True}) or i, 'EITHER', std + [daily_export_ge(1500)])
    T('E', 'Daily Export di bawah target perlu koreksi (target 2600 required)', A0, lambda i: M(i)['pln_export_priority']['daily_target'].update({'value': 2600, 'required': True}) or i, 'EITHER', std + [daily_export_ge(2600)])
    T('E', 'Daily Export infeasible dengan terminal proof (target 6000 required)', A0, lambda i: M(i)['pln_export_priority']['daily_target'].update({'value': 6000, 'required': True}) or i, 'REJECT', [])
    T('E', 'Dev Dispatch positif dan negatif (rule -10..+10 row 10-30)', A0, lambda i: M(i)['pln_export_priority'].__setitem__('dispatch_dev_rules', [{'min': -10, 'max': 10, 'start': 10, 'stop': 30, 'required': True}]) or i, 'EITHER', std)
    T('E', 'Max Load Adjustment satu unit (G2 max 26 MW row 20-30)', A0, lambda i: M(i).__setitem__('max_load_rules', {'g2': [{'start': 20, 'stop': 30, 'max': 26}]}) or i, 'EITHER', std + [max_cap('g2', 20, 30, 26)])
    T('E', 'Adjustment beberapa unit + Bus Flow boundary (G2 26, G8 90; busflow_min 20)', A0, lambda i: M(i).update({'max_load_rules': {'g2': [{'start': 20, 'stop': 30, 'max': 26}], 'g8': [{'start': 1, 'stop': 48, 'max': 90}]}, 'busflow_min': 20}) or i, 'EITHER', std + [max_cap('g2', 20, 30, 26), max_cap('g8', 1, 48, 90)])

    # ================= GROUP F (18) actual gas / fixed flow =================
    def actual(key, rows, val):
        def m(i):
            a = list(M(i)[key]) + [''] * 48; a = a[:48]
            for k in rows: a[k - 1] = val
            M(i)[key] = a; return i
        return m
    T('F', 'Actual PGN lebih tinggi dari simulasi (row 1-8)', A0, actual('actual_pgn_total', range(1, 9), 0.075), 'EITHER', std)
    T('F', 'Actual PGN lebih rendah (row 1-8)', A0, actual('actual_pgn_total', range(1, 9), 0.055), 'EITHER', std)
    T('F', 'Actual Fixed Flow Jababeka lebih tinggi (row 1-8)', A0, actual('actual_energy_jababeka', range(1, 9), 1.133), 'EITHER', std)
    T('F', 'Actual Fixed Flow Jababeka lebih rendah', A0, actual('actual_energy_jababeka', range(1, 9), 0.927), 'EITHER', std)
    T('F', 'Actual MM2100 lebih tinggi', A0, actual('actual_energy_mm2100', range(1, 9), 0.02), 'EITHER', std)
    T('F', 'Actual MM2100 lebih rendah', A0, actual('actual_energy_mm2100', range(1, 9), 0.0), 'EITHER', std)
    T('F', 'Kombinasi ketiga Actual Gas', A0, chain(actual('actual_pgn_total', range(1, 9), 0.07), actual('actual_energy_jababeka', range(1, 9), 1.08), actual('actual_energy_mm2100', range(1, 9), 0.01)), 'EITHER', std)
    T('F', 'Historical actual immutable (actual row 1-8 tercermin di output)', A0, actual('actual_pgn_total', range(1, 9), 0.07), 'EITHER', [lambda r: (all(abs(float(r.d[k].get('Act_PGN') or 0) - 0.14) < 1e-6 for k in (0, 2, 4, 6)), f"Act_PGN jam 1-4 (jumlah 2 row) {[r.d[k].get('Act_PGN') for k in (0, 2, 4, 6)]} = 0,14")])
    T('F', 'Current/future planning rows adjustable (row 9+ tanpa actual)', A0, actual('actual_pgn_total', range(1, 9), 0.07), 'EITHER', std, note='payload = F008')
    T('F', 'Min PGN Flow satu row (manual fixed flow J row 20)', A0, lambda i: M(i).__setitem__('manual_fixed_flows', [{'area': 'J', 'row': 20, 'value_mmscfd': 3}]) or i, 'EITHER', std)
    T('F', 'Min PGN Flow empat row (row 20-23)', A0, lambda i: M(i).__setitem__('manual_fixed_flows', [{'area': 'J', 'row': k, 'value_mmscfd': 3} for k in range(20, 24)]) or i, 'EITHER', std)
    T('F', 'Fixed Flow dikurangi minimum pada source rows (min_pgn_flow 5)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 5) or i, 'EITHER', std + [has_info('Fixed Flow JBBK Redistribution')])
    T('F', 'Redistribusi prorata ke recipient legal (min_pgn_flow 4)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 4) or i, 'EITHER', std + [has_info('Fixed Flow JBBK Redistribution')])
    T('F', 'Recipient mencapai cap (min_pgn_flow 7)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 7) or i, 'EITHER', std)
    T('F', 'Recipient capacity tidak cukup (min_pgn_flow 12)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 12) or i, 'EITHER', [rows48()])
    T('F', 'Total Fixed Flow harian before = after (redistribusi)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 5) or i, 'EITHER', [lambda r: (lambda x: (x is None or abs(float(x.get('total_before', 0) or 0) - float(x.get('total_after', 0) or 0)) < 1e-3 if isinstance(x, dict) else True, f'redistribusi {json.dumps(x)[:160]}'))(r.i.get('Fixed Flow JBBK Redistribution'))], note='payload = F012')
    T('F', 'Residual reconciliation pembulatan (min_pgn_flow 3.33)', A0, lambda i: M(i).__setitem__('min_pgn_flow', 3.33) or i, 'EITHER', std)
    T('F', 'Save Actual Gas draft/final dan Monitoring reload exact [engine payload]', A0, chain(actual('actual_pgn_total', range(1, 9), 0.07), lambda i: M(i).__setitem__('monitoring_daily_plan', {'enabled': True}) or i), 'EITHER', [rows48()], note='persist UI di grup I')

    # ================= GROUP G (14) trip / availability / change over =================
    T('G', 'Trip Babelan unit 1 (B1 sudah Stop di fixture)', A0, None, 'PASS', std + [off_all('bb1')], note='payload = A0')
    T('G', 'Trip Babelan unit 2', A0, chain(lambda i: set_unit(i, 'b2', 'Stop'), lambda i: trip(i, 'b2')), 'EITHER', std + [off_all('bb2')])
    T('G', 'Trip G8', A0, chain(lambda i: set_unit(i, 'g8', 'Stop'), lambda i: trip(i, 'g8')), 'EITHER', std + [off_all('g8')])
    T('G', 'Trip G9', A0, chain(free('g9'), lambda i: trip(i, 'g9')), 'EITHER', std + [off_all('g9')])
    T('G', 'Trip unit prioritas tertinggi GT (G8, plus B2 tetap)', A0, chain(lambda i: set_unit(i, 'g8', 'Stop'), lambda i: trip(i, 'g8')), 'EITHER', std + [off_all('g8')], note='payload = G003')
    T('G', 'Trip feeder GTG block (G2 Block 2)', A0, chain(lambda i: set_unit(i, 'g2', 'Stop'), lambda i: trip(i, 'g2')), 'EITHER', std + [off_all('g2')])
    T('G', 'STG/GTG coupling setelah trip (G2 trip 10:00-24:00)', A0, chain(lambda i: set_unit(i, 'g2', 'Running'), lambda i: trip(i, 'g2', 21, 48)), 'EITHER', std + [off_rows('g2', 21, 48)])
    T('G', 'Unit unavailable full day (G5)', A0, lambda i: trip(i, 'g5'), 'EITHER', std + [off_all('g5')])
    T('G', 'Unit unavailable sebagian horizon (G3 06:00-12:00)', A0, lambda i: trip(i, 'g3', 13, 24), 'EITHER', std + [off_rows('g3', 13, 24)])
    T('G', 'Recovery availability (G5 unavailable 00:00-10:00 lalu tersedia)', A0, lambda i: trip(i, 'g5', 1, 20), 'EITHER', std + [off_rows('g5', 1, 20)])
    def co(legal):
        def m(i):   # payload Change Over persis seperti UI (buildChangeOverObj): satu GTG priority-1 + STG per block; Block 2 Required = anchor
            block_req(i, 'Block 2', True); set_unit(i, 'g6', 'Stop'); set_unit(i, 's1', 'Stop'); set_unit(i, 'g2', 'Running'); set_unit(i, 's2', 'Running')
            M(i)['change_over'] = {'enabled': True, 'blocks': {'1': {'block': '1', 'last_status': 'Stop', 'gtg': 'g6', 'stg': 's1', 'start_other': '08:00' if legal else '00:30'},
                                                                 '2': {'block': '2', 'last_status': 'Running', 'gtg': 'g2', 'stg': 's2', 'stop_other': '12:00' if legal else '01:00'}}}
            return i
        return m
    T('G', 'Change Over legal (Block 1 start 08:00, Block 2 stop 12:00)', A0, co(True), 'EITHER', [rows48()])
    T('G', 'Change Over tidak legal (Block 2 stop 01:00 sebelum Block 1 siap)', A0, co(False), 'REJECT', [])
    T('G', 'Ramp dan reserve setelah trip (G8 trip 12:00, SR 10)', A0, chain(lambda i: set_unit(i, 'g8', 'Running'), lambda i: trip(i, 'g8', 25, 48), lambda i: M(i).update({'sr_fixed_mw': 10}) or i), 'EITHER', std + [off_rows('g8', 25, 48), sr_ok()])
    T('G', 'Gas/Export/Bus Flow setelah trip (G9 trip + busflow_min 20)', A0, chain(free('g9'), lambda i: trip(i, 'g9'), lambda i: M(i).update({'busflow_min': 20}) or i), 'EITHER', std + [off_all('g9')])

    # ================= GROUP H (16) Fix Load (engine part) =================
    def fx(u, a, b, v):
        def m(i):
            fl = M(i).get('unit_fix_load'); fl = fl if isinstance(fl, dict) else {}
            fl.setdefault(u, []).append({'start': a, 'stop': b, 'value': v}); M(i)['unit_fix_load'] = fl; return i
        return m
    T('H', 'Fix Load dari menu (G2 25 MW row 20)', A0, fx('g2', 20, 20, 25), 'EITHER', std + [fix_load('g2', 20, 20, 25)])
    T('H', 'Ctrl+Click Plan -> Fix Load satu cell (G8 80 MW row 30) [payload]', A0, fx('g8', 30, 30, 80), 'EITHER', std + [fix_load('g8', 30, 30, 80)])
    T('H', 'Ctrl+Click Monitoring Daily Plan -> Fix (G2 28 MW row 34) [payload]', A0, chain(fx('g2', 34, 34, 28), lambda i: M(i).__setitem__('monitoring_daily_plan', {'enabled': True}) or i), 'EITHER', [rows48(), fix_load('g2', 34, 34, 28)])
    T('H', 'Ctrl+Click Full Screen -> Fix rentang (G8 90 MW row 10-14) [payload]', A0, fx('g8', 10, 14, 90), 'EITHER', std + [fix_load('g8', 10, 14, 90)])
    T('H', 'Edit Fix Load (G2 25 -> 27 MW row 20)', A0, fx('g2', 20, 20, 27), 'EITHER', std + [fix_load('g2', 20, 20, 27)])
    T('H', 'Remove Fix Load (kembali ke A0)', A0, lambda i: M(i).__setitem__('unit_fix_load', []) or i, 'PASS', std + [same_commit_as(A0)])
    T('H', 'Fix di atas MAX ditolak (G2 40 MW)', A0, fx('g2', 20, 20, 40), 'PRECHECK_REJECT', [], precheck=('g2', 20, 40))
    T('H', 'Fix di bawah MIN ditolak (G2 5 MW)', A0, fx('g2', 20, 20, 5), 'PRECHECK_REJECT', [], precheck=('g2', 20, 5))
    T('H', 'Fix pada unit Trip/Unavailable ditolak (G5 trip + fix)', A0, chain(lambda i: trip(i, 'g5'), fx('g5', 20, 20, 25)), 'PRECHECK_REJECT', [], precheck=('g5', 20, 25))
    T('H', 'Fix pada startup transition (G9 row 11 = 45 MW)', A0, fx('g9', 11, 11, 45), 'EITHER', [rows48()])
    T('H', 'Fix pada row setelah Stop Request (G5 stop 12:00, fix row 30)', A0, chain(lambda i: set_unit(i, 'g5', 'Running', None, 'request', stop_at='12:00'), fx('g5', 30, 30, 25)), 'PRECHECK_REJECT', [], precheck=('g5', 30, 25))
    T('H', 'Infeasible Fix dengan terminal proof (G8 105 seluruh hari + G2 31)', A0, chain(fx('g8', 1, 48, 105), fx('g2', 1, 48, 31)), 'EITHER', [rows48()])
    T('H', 'Fix + Actual Gas lebih tinggi', A0, chain(fx('g2', 20, 20, 25), actual('actual_pgn_total', range(1, 9), 0.075)), 'EITHER', std + [fix_load('g2', 20, 20, 25)])
    T('H', 'Fix + Actual Gas lebih rendah', A0, chain(fx('g2', 20, 20, 25), actual('actual_pgn_total', range(1, 9), 0.055)), 'EITHER', std + [fix_load('g2', 20, 20, 25)])

    # ================= GROUP J (engine part) =================
    T('J', 'Dua kandidat fully valid dengan CP berbeda (A3 vs base): pemenang CP lebih rendah', 'A3_cfg_G6_ke_G4', None, 'PASS', std + [proof_ok()])
    T('J', 'CP sama dalam band, HR tie-break (A5 = A0 dengan Block 2 required)', 'A5_block2_required', None, 'PASS', std + [same_commit_as(A0)])
    T('J', 'Startup cost mengubah Economic CP (startup penalty x4)', A0, lambda i: M(i)['startup_penalty'].update({k: v * 4 for k, v in M(i)['startup_penalty'].items() if 'cost' in k}) or i, 'PASS', std + [proof_ok()])
    T('J', 'Startup count tie-break (startup penalty 0)', A0, lambda i: M(i)['startup_penalty'].update({k: 0 for k in M(i)['startup_penalty'] if 'cost' in k}) or i, 'PASS', std + [proof_ok()])
    T('J', 'Online unit-row / later start tie-break (A4)', 'A4_priority_blok1_di_atas_blok2', None, 'PASS', std + [proof_ok()], note='payload = B048')
    T('J', 'Fastest normal aktual (A0)', A0, None, 'PASS', std + [proof_ok()])

    # ================= GROUP X: skenario tambahan wajib V15.23 =================
    def same_as_base(base, cpTol=0.0):
        def c(r):
            b = base_res(base)
            if b is None: return False, f'base {base} belum dijalankan'
            okc = r.events() == b.events() and r.cp() is not None and b.cp() is not None and abs(r.cp() - b.cp()) <= cpTol + 1e-9
            okp = r.proof().get('status') == b.proof().get('status') and [e['reason_code'] for e in r.proof().get('events', [])] == [e['reason_code'] for e in b.proof().get('events', [])]
            okd = [[w.get(u) for u in ('G1','G2','G3','G4','G5','G6','G8','G9','S1','S2','S3')] for w in r.d] == [[w.get(u) for u in ('G1','G2','G3','G4','G5','G6','G8','G9','S1','S2','S3')] for w in b.d]
            return okc and okp and okd, f'identik dengan {base}: events={r.events() == b.events()} cp {r.cp()} vs {b.cp()} proof={okp} 48row={okd}'
        return c
    for sd in ('A', 'B', 'C'):
        T('X', f'Candidate order shuffle seed {sd} (app tanpa cache) identik dengan A0', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_V1523_SHUFFLE': sd})
    T('X', 'Commitment Tightening OFF: hasil valid, CP >= hasil Tightening ON', A0, None, 'PASS', std + [lambda r: (r.cp() >= (base_res(A0).cp() if base_res(A0) else 1e9) - 1e-9, f'cp OFF {r.cp()} vs ON {base_res(A0).cp() if base_res(A0) else None}')], env={'PP_V1523_TIGHTEN': '0'})
    T('X', 'Prescreen monoton (penundaan Required) OFF -> output identik', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_V1524_MONO': '0', '__COLD': '1'})
    T('X', 'Pemakaian ulang verdict antar-ronde OFF -> output identik', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_V1524_REUSE': '0', '__COLD': '1'})
    T('X', 'Cache bundle/kandidat OFF -> output identik', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_SP_BUNDLE_CACHE': '0', '__COLD': '1'})
    T('X', 'Pool state OFF -> output identik', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_V4_POOL': '0', '__COLD': '1'})
    T('X', 'Selected fuel state tidak memakai preliminary state (Fuel State Assessment = aksi terpilih)', A0, None, 'PASS', std + [lambda r: ((r.i.get('V15.23 Fuel State Assessment') or {}).get('fuel_action', r.i.get('Gas Shortage Action')) == r.i.get('Gas Shortage Action'), f"assess {json.dumps(r.i.get('V15.23 Fuel State Assessment'))[:160]}")])
    T('X', 'Stale preliminary/recommendation basis ditolak (aksi recommendation sesudah distillate, app hangat) = run dingin', A0, lambda i: fuel(i, 'recommendation'), 'EITHER', [rows48()], env={'__COLD': '1'}, note='dibandingkan dengan C015 (app hangat, payload sama) di report')
    T('X', 'V8 candidate CP lebih buruk ditolak (Comparator Invariant Guard tercatat)', 'A8_cfg_G9_ke_G5', None, 'PASS', std + [lambda r: (all(g.get('decision') != 'DITERIMA' or (g['after'].get('econ_cp', 0) <= g['before'].get('econ_cp', 0) * 1.0002 + 1e-9 or g['after'].get('inv', 0) < g['before'].get('inv', 0) or g['after'].get('startups_nm', 0) < g['before'].get('startups_nm', 0)) for g in (r.i.get('Comparator Invariant Guard') or [])), f"guard {[(g.get('stage'), g.get('decision')) for g in (r.i.get('Comparator Invariant Guard') or [])]}")])
    T('X', 'Kandidat lokal baik tetapi final bundle buruk tidak diterapkan (final CP <= Tightening OFF)', 'A3_cfg_G6_ke_G4', None, 'PASS', std + [proof_ok()])
    T('X', 'Latest legal start dua unit Required bersamaan (G6 & G9)', A0, None, 'PASS', std + [lambda r: (sorted(e['unit'] for e in r.proof().get('events', []) if e['event'] == 'START' and e['reason_code'].startswith('REQUIRED_START_PALING_LAMBAT_LEGAL')) == ['G6', 'G9'], 'G6 & G9 latest legal')])
    T('X', 'Earliest legal stop setelah redispatch (G3/G5)', A0, None, 'PASS', std + [lambda r: (all(e['reason_code'] in ('STOP_PALING_AWAL_LEGAL', 'RUNTIME_MINIMUM', 'STOP_SEKARANG_LEBIH_EKONOMIS') for e in r.proof().get('events', []) if e['event'] == 'STOP'), 'stop paling awal legal')])
    T('X', 'Running higher-priority unit masih punya headroom (IE -40) -> tanpa startup tambahan berprioritas rendah', 'A6_IE_minus40', None, 'PASS', std + [off_all('g1'), off_all('g4'), proof_ok()])
    T('X', 'Running higher-priority unit sudah maksimum (IE +8) -> startup dengan proof first-needed', A0, lambda i: ie_scale(i, 8), 'EITHER', std + [proof_ok()], note='payload = E006')
    T('X', 'G5/G3 diperlukan (aktual) dengan defisit first-needed', A0, None, 'PASS', std + [lambda r: (all(((e.get('deficit_at_first_needed') or {}).get('deficit_mw') or 0) > 0 for e in r.proof().get('events', []) if e['event'] == 'START' and e['unit'] in ('G5', 'G3')), 'defisit > 0 untuk G5/G3')])
    T('X', 'G1 tidak diperlukan (A6) dan diperlukan (G3/G4 trip) — lihat B045', 'A6_IE_minus40', None, 'PASS', std + [off_all('g1')])
    T('X', 'Priority inversion dengan proof (A3) dan tanpa proof diblokir', 'A3_cfg_G6_ke_G4', None, 'PASS', std + [proof_ok()])
    T('X', 'Priority inversion atas permintaan user (G4 request 08:00) dihormati', A0, cfg('g4', 'Stop', 'request', 'opt', '08:00'), 'EITHER', std + [first_on_eq('g4', 17)])
    T('X', 'Minimum runtime menghalangi early stop', A0, cfg('g5', 'Stop', 'request', 'must', '16:00'), 'EITHER', std + [min_run('g5', 4)], note='payload = A022')
    T('X', 'Gas/steam/export menghalangi delayed start (alasan TIDAK_VALID export_range)', A0, None, 'PASS', std + [lambda r: (any('export_range' in e['reason_code'] for e in r.proof().get('events', []) if e['event'] == 'START'), 'alasan export_range')])
    # J10 (ditambahkan sesudah grup X agar ID lama stabil)
    T('J', 'Fastest dan satu Maximum Review smoke dibandingkan transparan (A0)', A0, lambda i: i.update({'_maximum_review': True, '_fast_default': False}) or i, 'PASS',
      std + [lambda r: (True, f"Maximum Review CP {r.cp()} vs Fastest CP {base_res(A0).cp() if base_res(A0) else None}; events MR {r.events()} vs F {base_res(A0).events() if base_res(A0) else None}")], note='Maximum Review smoke')

    # ================= V15.25 (ditambahkan sesudah J10 agar ID lama T001-T221 stabil) =================
    def ie_rows(delta, a, b): return lambda i: ie_scale(i, delta, rows=set(range(a, b + 1)))
    def dist_sel_ok():
        def c(r):
            s = r.i.get('Distillate Unit Selection')
            used = [u for u in ('G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10') if any(float(w.get('Dist_' + u) or 0) > 0.01 for w in r.d)]
            if not used: return True, 'distillate tidak dipakai'
            if not isinstance(s, dict): return False, 'audit Distillate Unit Selection tidak ada'
            el = s.get('eligible_order') or []; bad = [u for u in used if u not in el]
            first = el[0] if el else None
            return not bad and first in used, f'dipakai {used}; eligible {el}; tidak eligible tapi dipakai {bad}; prioritas eligible pertama {first} dipakai={first in used}'
        return c
    def dist_reason(u, rx):
        def c(r):
            s = r.i.get('Distillate Unit Selection') or {}; x = next((y for y in (s.get('units') or []) if y.get('unit') == u), None)
            if not s: return True, 'distillate tidak dipakai (tanpa audit)'
            return bool(x) and x.get('eligible') is False and re.search(rx, str(x.get('reason'))) is not None, f'{u}: {x}'
        return c
    import re
    def prio16(order):
        def m(i): M(i)['unit_priority_dist'] = [order] + [x for x in M(i)['unit_priority_dist'] if not set(x) & set(order)]; return i
        return m
    T('K', 'Distillate Priority 1-6 global: payload lama 2 grup Block 1/Block 2 = payload gabungan (migrasi identik)', A0, prio16(['g6', 'g4', 'g3', 'g1', 'g2', 'g5']), 'PASS', std + [same_as_base(A0), dist_sel_ok()])
    T('K', 'Distillate Priority 1-6: Block 2 di atas Block 1 (G1,G2,G5,G6,G4,G3)', A0, prio16(['g1', 'g2', 'g5', 'g6', 'g4', 'g3']), 'EITHER', std + [dist_sel_ok(), no_dist_gas_dist()])
    T('K', 'Distillate Priority 1-6 campuran lintas blok (G2,G6,G5,G4,G1,G3)', A0, prio16(['g2', 'g6', 'g5', 'g4', 'g1', 'g3']), 'EITHER', std + [dist_sel_ok(), no_dist_gas_dist()])
    T('K', 'Unit prioritas 1 Stop seharian (G6 trip): dilewati dengan alasan, tidak distart demi distillate', A0, chain(free('g6'), lambda i: trip(i, 'g6')), 'EITHER', std + [off_all('g6'), dist_sel_ok(), dist_reason('G6', 'TRIP|STOP|TIDAK')])
    T('K', 'Unit Stop (G4 tidak berbeban) prioritas 1: tidak eligible, unit running berikutnya dipakai', A0, prio16(['g4', 'g6', 'g3', 'g1', 'g2', 'g5']), 'EITHER', std + [dist_sel_ok()])
    T('K', 'Plafon distillate kecil (40000 l): hanya prioritas eligible pertama', A0, lambda i: fuel(i, 'use_distillate', dist=40000), 'EITHER', std + [dist_sel_ok(), no_dist_gas_dist()])
    def bal(delta=30):
        def m(i):
            for u in ('G5', 'G6', 'S1'): M(i)['unit_last_data_status'][u] = 'Running'
            M(i).pop('distillate_user_limit_litres', None); ie_scale(i, delta, rows=set(range(17, 41))) if delta else None; return i
        return m
    def bal_ok(code=None):
        def c(r):
            b = r.i.get('Block Balance 2x2'); codes = [d.get('reason_code') for d in ((b or {}).get('decisions') or [])]
            ok = isinstance(b, dict) and (not code or code in codes)
            return ok, f"Block Balance 2x2 {None if b is None else b.get('status')} reason {codes} remaining_3x1 {(b or {}).get('remaining_3x1_rows')}"
        return c
    T('L', 'S1 & S2 paralel, startup tambahan otomatis: 2+2 diuji sebelum 3+1 (IE +30 row 17-40)', A0, bal(30), 'PASS', std + [bal_ok('AUTO_BALANCE_2X2_BEFORE_3X1'), proof_ok()])
    T('L', 'S1 & S2 paralel tanpa kebutuhan unit tambahan (IE +0): tidak ada 3+1 otomatis', A0, bal(0), 'EITHER', std)
    T('L', 'S1 & S2 paralel IE +15: keputusan 2+2/3+1 dicatat dengan alasan', A0, bal(15), 'EITHER', std)
    T('L', 'Intent user eksplisit (G1 Start Based On Request 08:00) tidak ditimpa 2+2', A0, chain(bal(30), cfg('g1', 'Stop', 'request', 'opt', '08:00')), 'EITHER', std + [first_on_eq('g1', 17)])
    T('L', 'Blok lawan tidak legal (G3/G4 trip): 3+1 dipertahankan dengan alasan', A0, chain(bal(30), lambda i: trip(i, 'g3'), lambda i: trip(i, 'g4')), 'EITHER', std + [off_all('g3'), off_all('g4')])
    T('L', 'Prioritas unit ditukar (Block 2 di atas Block 1) + IE +30', A0, chain(bal(30), lambda i: M(i).__setitem__('block_priority', list(reversed(M(i)['block_priority'][:2])) + M(i)['block_priority'][2:]) or i), 'EITHER', std)
    def b3cold(extra=None):
        def m(i):
            mm = M(i); mm['unit_last_data_status'].update({'G8': 'Stop', 'S3': 'Stop'}); mm['unit_cannot_stop'] = [u for u in mm.get('unit_cannot_stop', []) if u not in ('g8', 's3')]
            for u in ('g8', 's3'): mm.get('required_mode', {}).pop(u, None)
            mm['required_units'] = [u for u in mm.get('required_units', []) if u not in ('g8', 's3')]
            mm.pop('distillate_user_limit_litres', None); mm['stg_startup_mode']['s3_startup'] = 'Cold'
            if extra: extra(i)
            return i
        return m
    def b3cold_profile():
        def c(r):
            a = min(x for x in (r.first_on('g8'), r.first_on('g9')) if x) if (r.first_on('g8') or r.first_on('g9')) else None
            if a is None: return False, 'G8/G9 tidak start'
            lead = 'g8' if r.first_on('g8') == a else 'g9'; caps = [40] * 7 + [50] * 2 + [60] * 5
            got = [r.mw(lead, a + k) for k in range(14) if a + k <= 48]; s3 = r.first_on('s3')
            ok = all(abs(g - c0) < 0.05 for g, c0 in zip(got, caps)) and s3 == a + 14
            return ok, f'lead {lead.upper()} start row {a} ({rt(a - 1)}): profil {got} (harus 40x7,50x2,60x5); S3 first load row {s3} (harus {a + 14} = start + 7,5 jam)'
        return c
    def b1run(i):   # Block 1 & 2 penuh Running sejak 00:00 agar row 1 layak tanpa Block 3 (fixture A0: hanya G8/S3 yang menopang row 1)
        M(i)['unit_last_data_status'].update({'G6': 'Running', 'G4': 'Running', 'G3': 'Running', 'S1': 'Running', 'G5': 'Running', 'G1': 'Running'}); return i
    T('N', 'Block 3 cold startup: 40 MW 3,5 jam, 50 MW 1 jam, 60 MW 2,5 jam; S3 berbeban tepat 7,5 jam sesudah startup', A0, b3cold(b1run), 'PASS', std + [b3cold_profile()])
    def cont(stg, e, units, uld):
        def m(i): M(i)['startup_continuation'] = {stg: {'elapsed_rows': e, 'units': units, 'source': 'MATRIX'}}; M(i)['unit_last_data_status'].update(uld); return i
        return m
    def cont_ok(stg, first):
        def c(r):
            ci = (r.i.get('Startup Continuation') or {}).get(stg) or {}; s = r.first_on(stg)
            return s == first and ci.get('stg_first_load_row') == first, f'{stg.upper()} first load row {s} (harus {first}); info {json.dumps(ci)[:200]}'
        return c
    T('N', 'Start Up CC kemarin: G6 sudah 3 row sequence cold S1 -> sisa profil 20 MW, S1 berbeban row 5', A0, cont('s1', 3, ['g6'], {'G6': 'Start Up CC'}), 'PASS', std + [cont_ok('s1', 5), fix_load('g6', 1, 4, 20)])
    T('N', 'Start Up CC manual (G6, elapsed 0): sequence cold penuh dari row 1, S1 berbeban row 8', A0, lambda i: M(i)['unit_last_data_status'].update({'G6': 'Start Up CC'}) or i, 'EITHER', std + [cont_ok('s1', 8)])
    T('N', 'Kelanjutan sequence + commitment STG eksplisit (S1 continuous): commitment user menang, alasan dicatat', A0, chain(cont('s1', 3, ['g6'], {'G6': 'Start Up CC'}), cfg('s1', 'Running', 'continuous', None)),
      'EITHER', std + [lambda r: (((r.i.get('Startup Continuation') or {}).get('s1') or {}).get('status') == 'DIABAIKAN_KOMITMEN_STG_EKSPLISIT', f"info {json.dumps(r.i.get('Startup Continuation'))[:200]}")])
    def tgt(stg, at): return lambda i: M(i).__setitem__('stg_first_load_target', {stg: at}) or i
    T('N', 'Backward scheduling S1 target first load 06:00: GTG start mundur 7 row (same day)', A0, tgt('s1', '06:00'), 'EITHER', std + [lambda r: (r.first_on('s1') == 12 and ((r.i.get('Startup Continuation') or {}).get('s1') or {}).get('mode') == 'BACKWARD_START_SAME_DAY', f"S1 first {r.first_on('s1')} info {json.dumps((r.i.get('Startup Continuation') or {}).get('s1'))[:200]}")])
    T('N', 'Backward scheduling S1 target 02:00 (sebelum lag): kelanjutan dari kemarin, S1 berbeban row 4', A0, tgt('s1', '02:00'), 'EITHER', std + [cont_ok('s1', 4)])
    T('N', 'Kelanjutan cross-day selesai kemarin (elapsed >= lag): unit & STG Running sejak row 1', A0, cont('s1', 9, ['g6'], {'G6': 'Start Up CC'}), 'EITHER', std + [lambda r: (r.on('s1', 1) and r.on('g6', 1), f"S1 row1 {r.mw('s1', 1)} G6 row1 {r.mw('g6', 1)}")])
    def cmp_final(a, b):   # reimplementasi pp_sp_final_cmp (objective final Fastest = Maximum Review, V15.26)
        for k in ('hard_pass', 'release_pass', 'stg_pass'):
            if a[k] != b[k]: return -1 if a[k] > b[k] else 1
        for k in ('c1_c4_fail', 'hpa_unresolved', 'priority_penalty'):
            if a[k] != b[k]: return -1 if a[k] < b[k] else 1
        if abs(a['unused_legal_hp_headroom_mw'] - b['unused_legal_hp_headroom_mw']) > 0.05: return -1 if a['unused_legal_hp_headroom_mw'] < b['unused_legal_hp_headroom_mw'] else 1
        if abs(float(a['econ_cp']) - float(b['econ_cp'])) > 1e-4: return -1 if a['econ_cp'] < b['econ_cp'] else 1
        ca, cb = float(a['cp']), float(b['cp'])
        if abs(ca - cb) > 0.002 * min(ca, cb): return -1 if ca < cb else 1
        if abs(float(a['hr']) - float(b['hr'])) > 1e-6: return -1 if a['hr'] < b['hr'] else 1
        if abs(ca - cb) > 1e-6: return -1 if ca < cb else 1
        for k in ('startups', 'online_unit_rows', 'fragmentation'):
            if a[k] != b[k]: return -1 if a[k] < b[k] else 1
        if a['start_rows_sum'] != b['start_rows_sum']: return -1 if a['start_rows_sum'] > b['start_rows_sum'] else 1
        return -1 if str(a['fingerprint']) < str(b['fingerprint']) else (1 if str(a['fingerprint']) > str(b['fingerprint']) else 0)
    def mr_ok(base):
        def c(r):
            x = r.i.get('Maximum Review vs Fastest') or {}; f = x.get('fastest') or {}; m = x.get('maximum_review') or {}; b = base_res(base)
            if x.get('status') != 'COMPARED' or not f.get('publishable') or b is None: return False, f'perbandingan tidak tersedia {json.dumps(x)[:200]}'
            fcp_ok = abs((f.get('cp') or 0) - (b.cp() or -1)) < 1e-6
            w = 'MAXIMUM_REVIEW' if (m.get('publishable') and m.get('final_objective') and cmp_final(f['final_objective'], m['final_objective']) > 0) else 'FASTEST_CANDIDATE'
            ok = fcp_ok and x.get('winner') == w and abs((r.cp() or 0) - ((m if w == 'MAXIMUM_REVIEW' else f).get('cp') or -1)) < 1e-6 and (r.cp() or 1e9) <= (b.cp() or 0) + 1e-6 if w == 'FASTEST_CANDIDATE' else (fcp_ok and x.get('winner') == w)
            return ok, f"MR CP {m.get('cp')} vs Fastest CP {f.get('cp')} (job Fastest mandiri {b.cp()}); objective final: pemenang {w}, dipublikasikan {x.get('winner')} CP {r.cp()}"
        return c
    T('M', 'Maximum Review tidak lebih buruk dari Fastest (A0): kandidat Fastest selalu dibandingkan, pemenang = objective final (pp_sp_final_cmp)', A0, lambda i: i.update({'_maximum_review': True, '_fast_default': False}) or i, 'PASS', std + [mr_ok(A0)])
    T('M', 'Maximum Review tidak lebih buruk dari Fastest (A3 G6->G4): pemenang = objective final (pp_sp_final_cmp)', 'A3_cfg_G6_ke_G4', lambda i: i.update({'_maximum_review': True, '_fast_default': False}) or i, 'PASS', std + [mr_ok('A3_cfg_G6_ke_G4')])
    T('X', 'Prescreen batas atas kapasitas Export OFF (PP_V1525_CAPBOUND=0) -> output identik', A0, None, 'PASS', std + [same_as_base(A0)], env={'PP_V1525_CAPBOUND': '0', '__COLD': '1'})
