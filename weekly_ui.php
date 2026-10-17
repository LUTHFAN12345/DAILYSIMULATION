<?php /* weekly_ui.php — WEEKLY PLAN (matrix UI). Struktur, istilah, dan renderer mengikuti Daily Plan.
 * Periodic Input : matriks parameter x tujuh tanggal, nilai tersimpan per hari (data3.<unit>.<key>).
 * Frequently Input: matriks parameter x tujuh tanggal, subtab mengikuti childpanel Frequently Daily.
 * Result         : matriks Summary x tujuh tanggal + aksi per tanggal yang memakai renderer Daily.
 * TIDAK ADA TIME PASSED pada UI, matrix, state, payload, backend, Result, maupun lock behaviour.
 *
 * REDESIGN UI (scope: presentasi saja).
 *   Tidak ada perubahan engine, formula, validator, constraint, input produksi, atau certificate logic.
 *   Seluruh DOM ID, class hook (wk-tab/wk-subtab/wk-datecol/wk-rowhdr/wk-corner/wk-diff/wk-empty/
 *   wk-copied/wk-invalid/wk-changed/wk-detail-link) dan window API WEEKLY_* dipertahankan apa adanya.
 *   Yang berubah: hierarki visual, struktur kolom (Parameter | Unit | 7 tanggal | Action), toolbar
 *   sticky dengan hierarki tombol, section collapsible, pencarian parameter/unit, panel CSV dengan
 *   validasi, panel IE/Dispatch 48 slot, segmented control mode gas MM2100, dan editor rule
 *   terstruktur yang menggantikan textarea JSON (JSON tetap tersedia di Advanced View). */ ?>
<style>
/* Weekly Plan memakai DESIGN TOKEN Daily (var(--accent), --card, --radius, ...) sehingga warna,
 * font, spacing, radius, dan status color identik dengan Daily Plan. Tidak ada framework baru
 * dan seluruh selector ter-scope ke #wk-panel-root / #wk-detail-* / #wk-rule-* agar tidak bocor ke Daily. */
#wk-panel-root{font:inherit;color:var(--ink);--wk-navy:#0f1f3d;--wk-navy-2:#16294d;--wk-blue:#1763d6}
#wk-panel-root h2{letter-spacing:-.01em}

/* ---------- §5.1 HEADER RINGKAS ---------- */
#wk-panel-root .wk-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;
  padding:16px 22px;border-bottom:1px solid var(--card-line);
  background:linear-gradient(180deg,#fbfdff,#f4f8fe)}
#wk-panel-root .wk-head h2{margin:0;font-size:20px;font-weight:800;letter-spacing:-.02em;color:var(--wk-navy)}
#wk-panel-root .wk-head .wk-range{font-family:var(--mono);font-size:12.5px;color:var(--ink-soft);font-weight:600}
#wk-panel-root .wk-head .wk-sep{display:none}
#wk-panel-root .wk-head .wk-days{font-size:11px;font-weight:800;letter-spacing:.4px;color:var(--accent);
  background:var(--accent-soft);border:1px solid var(--accent-line);border-radius:999px;padding:3px 11px}
#wk-panel-root .wk-phase{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:999px;
  font-size:11px;font-weight:700;background:#fff;border:1px solid var(--card-line);color:var(--ink-soft)}
#wk-panel-root .wk-phase .dot{width:7px;height:7px;border-radius:50%;background:#c2cde0}
#wk-panel-root .wk-phase.run{color:#8a5800;border-color:var(--warn-line);background:var(--warn-bg)}
#wk-panel-root .wk-phase.run .dot{background:var(--warn)}
#wk-panel-root .wk-phase.done{color:#0a7b6b;border-color:var(--ok-line);background:var(--ok-bg)}
#wk-panel-root .wk-phase.done .dot{background:var(--ok)}

/* ---------- §5.3 TOOLBAR STICKY ---------- */
#wk-panel-root .wk-toolbar{position:sticky;top:var(--nav-h,60px);z-index:12;background:var(--card);
  border-bottom:1px solid var(--card-line);padding:10px 20px;
  display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end}
#wk-panel-root .wk-toolbar label.fld{min-width:120px}
#wk-panel-root .wk-toolbar .wk-tb-right{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
#wk-panel-root .wk-tb-sep{width:1px;height:26px;background:var(--card-line);margin:0 3px}
#wk-panel-root .wk-prog{display:flex;flex-direction:column;gap:5px}
#wk-panel-root .wk-prog .wk-prog-l{font-size:11px;color:var(--ink-soft);font-weight:650;letter-spacing:.2px}
#wk-panel-root .wk-prog .wk-prog-row{display:flex;align-items:center;gap:9px}
#wk-panel-root .wk-prog-bar{width:118px;height:6px;border-radius:99px;background:var(--field);
  border:1px solid var(--field-line);overflow:hidden}
#wk-panel-root .wk-prog-bar i{display:block;height:100%;width:0;border-radius:99px;
  background:linear-gradient(90deg,var(--accent),var(--accent-2));transition:width .2s ease}
#wk-panel-root #wk-progress{font-family:var(--mono);font-weight:700;font-size:12px;color:var(--ink-soft);min-width:26px}
#wk-panel-root #wk-unsaved{display:none;align-items:center;gap:7px;color:var(--bad);font-weight:700;font-size:11.5px}
#wk-panel-root #wk-msg{font-size:11.5px;font-weight:650;max-width:340px}
#wk-panel-root .btn.danger{background:#fff;border-color:var(--bad-line);color:#b22f37}
#wk-panel-root .btn{white-space:nowrap}
/* Save All 7 Days — satu-satunya tombol gelap, sesuai referensi */
#wk-panel-root .btn.dark{background:var(--wk-navy);border-color:var(--wk-navy);color:#fff;font-weight:700}
#wk-panel-root .btn.dark:hover{background:var(--wk-navy-2);border-color:var(--wk-navy-2)}
#wk-panel-root .btn.danger:hover{background:var(--bad-bg);border-color:var(--bad)}
#wk-panel-root .btn.danger.solid{background:var(--bad);border-color:var(--bad);color:#fff}
#wk-panel-root .btn.sm{padding:6px 11px;font-size:11.5px;border-radius:8px}
#wk-panel-root .btn.link{border-color:transparent;background:transparent;box-shadow:none;color:var(--accent);
  text-decoration:underline;text-underline-offset:2px;padding:4px 6px}
#wk-panel-root .btn.link:hover{background:var(--accent-soft);transform:none}

/* ---------- §5.2 TAB UTAMA (tinggi/format mengikuti childtab Daily) ---------- */
#wk-panel-root .wk-tabs{display:flex;gap:0;flex-wrap:wrap;padding:0 20px;border-bottom:1px solid var(--card-line)}
#wk-panel-root .wk-tab{border:0;border-bottom:3px solid transparent;background:transparent;color:var(--ink-soft);
  padding:12px 18px;font-family:var(--sans);font-weight:700;font-size:13px;cursor:pointer;
  display:inline-flex;align-items:center;gap:9px;transition:color .15s,border-color .15s}
#wk-panel-root .wk-tab .n{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;
  border-radius:50%;background:var(--field);border:1px solid var(--field-line);color:var(--ink-dim);font-size:10px;font-weight:800}
#wk-panel-root .wk-tab:hover{color:var(--accent)}
#wk-panel-root .wk-tab:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
#wk-panel-root .wk-tab.active{color:var(--accent);border-bottom-color:var(--accent)}
#wk-panel-root .wk-tab.active .n{background:var(--accent);border-color:var(--accent);color:#fff}
#wk-panel-root .wk-tab:disabled{opacity:.5;cursor:not-allowed}

/* ---------- SUBTAB ---------- */
#wk-panel-root .wk-subbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:11px 20px;
  border-bottom:1px solid var(--card-line)}
#wk-panel-root .wk-sub{display:flex;gap:6px;flex-wrap:wrap}
#wk-panel-root .wk-subtab{border:1px solid var(--field-line);background:var(--field);color:var(--ink-soft);
  border-radius:8px;padding:6px 13px;font-family:var(--sans);font-size:12px;font-weight:650;cursor:pointer;
  white-space:nowrap;transition:all .15s}
#wk-panel-root .wk-subtab:hover{border-color:var(--accent-line);background:var(--accent-soft);color:var(--accent)}
#wk-panel-root .wk-subtab:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
#wk-panel-root .wk-subtab.active{background:var(--wk-navy);border-color:var(--wk-navy);color:#fff}
#wk-panel-root .wk-subtab.active:hover{background:var(--wk-navy-2);border-color:var(--wk-navy-2);color:#fff}
#wk-panel-root .wk-find{margin-left:auto;display:flex;gap:7px;align-items:center}
#wk-panel-root .wk-find input{width:190px;font-family:var(--sans);font-size:12px;padding:6px 10px;border-radius:8px}
#wk-panel-root .wk-find input.u{width:120px}

/* ---------- AKSI BARIS KEDUA ---------- */
#wk-panel-root .wk-actbar{display:flex;gap:7px;flex-wrap:wrap;align-items:center;padding:10px 20px;
  border-bottom:1px solid var(--card-line);background:var(--field)}
#wk-panel-root .wk-actbar .wk-right{margin-left:auto;display:flex;gap:7px;flex-wrap:wrap}

/* ---------- §5.5 MATRIKS ---------- */
#wk-panel-root .wk-body{padding:18px 20px 20px}
#wk-panel-root .wk-mwrap{overflow:auto;max-height:min(62vh,620px);border:1px solid var(--card-line);
  border-radius:12px;background:var(--card);position:relative}
#wk-panel-root table.wk-matrix{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;
  font-family:var(--sans);font-size:12.5px}
#wk-panel-root table.wk-matrix th,#wk-panel-root table.wk-matrix td{border-bottom:1px solid #edf1f8;
  padding:5px 10px;white-space:nowrap;vertical-align:middle}
#wk-panel-root table.wk-matrix thead th{position:sticky;top:0;z-index:3;background:#f2f6fd;
  font-weight:700;text-align:center;color:#33476a;font-size:11px;letter-spacing:.4px;text-transform:uppercase;
  border-bottom:2px solid #e1e9f5;padding:8px 8px}
#wk-panel-root table.wk-matrix tbody tr:nth-child(even) td{background:#fafcff}
#wk-panel-root table.wk-matrix tbody tr:hover td{background:#eef6ff}
/* sticky: kolom Parameter dan kolom Unit */
#wk-panel-root table.wk-matrix tbody th.wk-rowhdr,#wk-panel-root table.wk-matrix thead th.wk-corner{
  position:sticky;left:0;z-index:2;background:var(--card);text-align:left;font-weight:600;
  width:250px;min-width:250px;max-width:250px;overflow:hidden;text-overflow:ellipsis;color:var(--ink)}
#wk-panel-root table.wk-matrix tbody tr:nth-child(even) th.wk-rowhdr{background:#fafcff}
#wk-panel-root table.wk-matrix tbody tr:hover th.wk-rowhdr{background:#eef6ff}
#wk-panel-root table.wk-matrix thead th.wk-corner{z-index:5;background:#e8effb}
#wk-panel-root table.wk-matrix th.wk-unitcol,#wk-panel-root table.wk-matrix td.wk-unitcol{
  position:sticky;left:250px;z-index:2;background:var(--card);text-align:left;
  font-family:var(--mono);font-size:10.5px;color:var(--ink-dim);font-weight:600;
  width:80px;min-width:80px;max-width:80px;overflow:hidden;text-overflow:ellipsis;
  border-right:1px solid var(--card-line)}
#wk-panel-root table.wk-matrix thead th.wk-unitcol{z-index:5;background:#e8effb;color:#33476a}
#wk-panel-root table.wk-matrix tbody tr:nth-child(even) td.wk-unitcol{background:#fafcff}
#wk-panel-root table.wk-matrix tbody tr:hover td.wk-unitcol{background:#eef6ff}
/* §4/§5.6 baris kategori — collapsible */
#wk-panel-root table.wk-matrix tbody tr.wk-group th{background:#e9f0fb;color:var(--wk-navy);
  font-weight:800;font-size:10.5px;letter-spacing:.8px;text-transform:uppercase;padding:8px 12px;
  text-align:left;cursor:pointer;border-top:1px solid #d8e4f6;
  border-bottom:1px solid #d8e4f6}
#wk-panel-root table.wk-matrix tbody tr.wk-group th:hover{background:#dfeafa}
#wk-panel-root table.wk-matrix tbody tr.wk-group .wk-chev{display:inline-block;width:11px;
  transition:transform .15s;font-size:9px;color:var(--accent)}
#wk-panel-root table.wk-matrix tbody tr.wk-group.collapsed .wk-chev{transform:rotate(-90deg)}
#wk-panel-root table.wk-matrix tbody tr.wk-group .wk-gcount{font-weight:600;color:var(--ink-dim);
  letter-spacing:.2px;text-transform:none;margin-left:8px}
/* input dalam sel */
#wk-panel-root table.wk-matrix td input,#wk-panel-root table.wk-matrix td select{width:100%;min-width:96px;
  box-sizing:border-box;border:1px solid var(--field-line);border-radius:7px;padding:4px 8px;
  font-family:var(--mono);font-size:12px;height:29px;background:var(--field);color:var(--ink);
  transition:border-color .15s,box-shadow .15s,background .15s}
/* §7 angka rata kanan + tabular numerals; teks & dropdown tetap rata kiri */
#wk-panel-root table.wk-matrix td input[type=number]{text-align:right;font-variant-numeric:tabular-nums}
#wk-panel-root table.wk-matrix td input:hover,#wk-panel-root table.wk-matrix td select:hover{border-color:#c3d2ea}
#wk-panel-root table.wk-matrix td input:focus,#wk-panel-root table.wk-matrix td select:focus{
  outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(23,99,214,.14);background:#fff}
#wk-panel-root td.wk-diff input,#wk-panel-root td.wk-diff select{background:var(--warn-bg);border-color:var(--warn-line)}
#wk-panel-root td.wk-empty input,#wk-panel-root td.wk-empty select{background:var(--field);border-style:dashed}
#wk-panel-root td.wk-invalid input,#wk-panel-root td.wk-invalid select{background:var(--bad-bg);border-color:var(--bad)}
#wk-panel-root td.wk-copied input,#wk-panel-root td.wk-copied select{background:var(--ok-bg);border-color:var(--ok-line)}
#wk-panel-root td.wk-changed input{box-shadow:inset 0 0 0 2px var(--warn)}
#wk-panel-root td.wk-cellactive input,#wk-panel-root td.wk-cellactive select{border-color:var(--accent-line)}
/* §5.9 segmented control mode gas MM2100 */
#wk-panel-root .wk-seg{display:inline-flex;border:1px solid var(--field-line);border-radius:8px;overflow:hidden;background:var(--field)}
#wk-panel-root .wk-seg button{border:0;background:transparent;font-family:var(--sans);font-size:11px;font-weight:700;
  padding:5px 10px;cursor:pointer;color:var(--ink-soft);transition:background .15s,color .15s}
#wk-panel-root .wk-seg button+button{border-left:1px solid var(--field-line)}
#wk-panel-root .wk-seg button:hover{background:var(--accent-soft);color:var(--accent)}
#wk-panel-root .wk-seg button:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
#wk-panel-root .wk-seg button.on{background:var(--accent);color:#fff}
/* §5.10 ringkasan rule + tombol editor */
#wk-panel-root .wk-rulecell{display:flex;align-items:center;gap:9px;min-width:260px}
#wk-panel-root .wk-rulecell .s{font-family:var(--mono);font-size:11px;color:var(--ink-soft);
  overflow:hidden;text-overflow:ellipsis;max-width:230px}
/* §3 header tanggal: blok dua baris + badge status; hari aktif jadi kartu biru solid */
#wk-panel-root th.wk-datecol{cursor:pointer;min-width:112px;padding:6px 6px;transition:background .15s}
#wk-panel-root th.wk-datecol:hover{background:#e6effc}
#wk-panel-root th.wk-datecol .wk-dbox{display:flex;flex-direction:column;align-items:center;gap:2px;
  padding:7px 9px;border-radius:10px;border:1px solid transparent;transition:background .15s,border-color .15s}
#wk-panel-root th.wk-datecol .wk-d1{display:block;font-size:9.5px;font-weight:800;color:var(--ink-dim);letter-spacing:.7px}
#wk-panel-root th.wk-datecol .wk-d2{display:block;font-size:14px;font-weight:800;color:var(--ink);letter-spacing:-.2px;text-transform:none;line-height:1.15}
#wk-panel-root th.wk-datecol .wk-d3{display:block;margin-top:3px}
#wk-panel-root th.wk-datecol.wk-wknd .wk-d1{color:#c0392b}
#wk-panel-root th.wk-datecol.wk-wknd .wk-dbox{background:#fdf2f1}
#wk-panel-root th.wk-datecol.wk-today .wk-dbox{border-color:var(--accent-line)}
#wk-panel-root th.wk-datecol.wk-active{background:transparent}
#wk-panel-root th.wk-datecol.wk-active .wk-dbox{background:var(--accent);border-color:var(--accent);
  box-shadow:0 4px 14px rgba(23,99,214,.28)}
#wk-panel-root th.wk-datecol.wk-active .wk-d1{color:rgba(255,255,255,.85)}
#wk-panel-root th.wk-datecol.wk-active .wk-d2{color:#fff}
#wk-panel-root th.wk-datecol.wk-active .wk-badge{background:rgba(255,255,255,.2) !important;
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.35)}
#wk-panel-root td.wk-activecol{background:#f2f8ff !important}
#wk-panel-root .wk-u{color:var(--ink-dim);font-weight:500;font-size:10.5px}
#wk-panel-root .wk-badge{border-radius:20px;padding:2px 8px;font-size:9px;font-weight:800;color:#fff;
  letter-spacing:.4px;display:inline-block;white-space:nowrap}
#wk-panel-root .wk-hint{font-size:11px;color:var(--ink-soft);margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
#wk-panel-root .wk-hint span{padding:4px 11px;border-radius:999px;font-weight:650}
/* §7 legend status */
#wk-panel-root .wk-legend{display:flex;gap:8px;flex-wrap:wrap;font-size:11px;color:var(--ink-dim);margin-top:12px;align-items:center}
#wk-panel-root .wk-legend .wk-badge{font-size:9.5px}
#wk-panel-root .wk-empty-state{padding:26px 20px;text-align:center;color:var(--ink-dim);font-size:12.5px}

/* ---------- §5.8 PANEL CSV / IE / DISPATCH ---------- */
#wk-panel-root .wk-panels{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}
@media(max-width:1080px){#wk-panel-root .wk-panels{grid-template-columns:1fr}}
#wk-panel-root .wk-card{border:1px solid var(--card-line);border-radius:12px;background:var(--card);overflow:hidden}
#wk-panel-root .wk-card-h{display:flex;align-items:center;gap:10px;padding:11px 15px;
  border-bottom:1px solid var(--card-line);background:linear-gradient(180deg,#fbfdff,#f6f9fe);
  font-weight:700;font-size:12.5px}
#wk-panel-root .wk-card-h .wk-kicker{font-size:9.5px;letter-spacing:1.6px;text-transform:uppercase;color:#fff;
  font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent-2));padding:3px 8px;border-radius:6px}
#wk-panel-root .wk-card-h .wk-hr{margin-left:auto;display:flex;gap:6px;align-items:center}
#wk-panel-root .wk-card-b{padding:15px}
#wk-panel-root .wk-drop{border:1.5px dashed var(--field-line);border-radius:10px;padding:18px;text-align:center;
  color:var(--ink-soft);font-size:12.5px;cursor:pointer;transition:all .15s;background:var(--field)}
#wk-panel-root .wk-drop:hover,#wk-panel-root .wk-drop.over{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}
#wk-panel-root .wk-kv{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 18px;margin-top:13px;font-size:12px}
#wk-panel-root .wk-kv>div{display:flex;justify-content:space-between;gap:12px;
  border-bottom:1px solid #edf1f8;padding-bottom:4px}
#wk-panel-root .wk-kv .k{color:var(--ink-soft)}
#wk-panel-root .wk-kv .v{font-family:var(--mono);font-weight:700;color:var(--ink);text-align:right;
  overflow:hidden;text-overflow:ellipsis}
#wk-panel-root .wk-kv .v.ok{color:#0a7b6b}#wk-panel-root .wk-kv .v.bad{color:#b22f37}
#wk-panel-root .wk-callout{border-radius:9px;padding:9px 12px;font-size:11.5px;margin-top:12px;
  border:1px solid var(--card-line);background:var(--field);color:var(--ink-soft)}
#wk-panel-root .wk-callout.ok{background:var(--ok-bg);border-color:var(--ok-line);color:#0a7b6b}
#wk-panel-root .wk-callout.warn{background:var(--warn-bg);border-color:var(--warn-line);color:#8a5800}
#wk-panel-root .wk-callout.bad{background:var(--bad-bg);border-color:var(--bad-line);color:#b22f37}
#wk-panel-root .wk-slotwrap{max-height:280px;overflow:auto;border:1px solid var(--card-line);border-radius:10px;margin-top:12px}
#wk-panel-root table.wk-slots{border-collapse:separate;border-spacing:0;width:100%;font-family:var(--mono);font-size:11.5px}
#wk-panel-root table.wk-slots th{position:sticky;top:0;z-index:2;background:#eef4fc;color:#33476a;font-weight:700;
  font-size:10px;letter-spacing:.4px;text-transform:uppercase;padding:6px 9px;border-bottom:2px solid #e1e9f5}
#wk-panel-root table.wk-slots td{padding:4px 9px;border-bottom:1px solid #edf1f8;text-align:right;
  font-variant-numeric:tabular-nums}
#wk-panel-root table.wk-slots td.t{text-align:left;font-weight:700;color:var(--ink-soft)}
#wk-panel-root table.wk-slots tbody tr:nth-child(even) td{background:#fafcff}
#wk-panel-root table.wk-slots td.bad{background:var(--bad-bg);color:#b22f37;font-weight:700}
#wk-panel-root table.wk-slots td input{width:100%;border:1px solid var(--field-line);border-radius:6px;
  padding:3px 7px;font-family:var(--mono);font-size:11.5px;text-align:right;background:var(--field)}
#wk-panel-root table.wk-slots td input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(23,99,214,.14);background:#fff}

/* ---------- §5.10 EDITOR RULE TERSTRUKTUR ---------- */
#wk-rule-overlay{position:fixed;inset:0;background:rgba(16,26,36,.5);z-index:9100;display:none}
#wk-rule-panel{position:fixed;top:6vh;left:50%;transform:translateX(-50%);width:min(880px,94vw);max-height:88vh;
  background:var(--card);border:1px solid var(--card-line);border-top:3px solid var(--accent);
  border-radius:var(--radius,14px);z-index:9101;display:none;flex-direction:column;overflow:hidden;
  box-shadow:0 18px 48px rgba(16,26,36,.3);font-family:var(--sans);color:var(--ink)}
#wk-rule-panel .wk-rh{display:flex;align-items:center;gap:11px;padding:14px 18px;border-bottom:1px solid var(--card-line);
  background:linear-gradient(180deg,#fbfdff,#f6f9fe)}
#wk-rule-panel .wk-rh b{font-size:13.5px}
#wk-rule-panel .wk-rh .wk-rsub{font-family:var(--mono);font-size:11px;color:var(--ink-dim)}
#wk-rule-panel .wk-rh .wk-rr{margin-left:auto;display:flex;gap:7px;align-items:center}
#wk-rule-body{flex:1;overflow:auto;padding:16px 18px;display:flex;flex-direction:column;gap:8px}
#wk-rule-panel .wk-rf{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:12px 18px;
  border-top:1px solid var(--card-line);background:var(--field)}
#wk-rule-panel .wk-rf .wk-right{margin-left:auto;display:flex;gap:7px}
#wk-rule-panel .wk-row{display:grid;grid-template-columns:30px 1fr auto;gap:12px;align-items:center;
  border:1px solid var(--card-line);border-radius:10px;padding:9px 12px;background:var(--card)}
#wk-rule-panel .wk-row:nth-child(even){background:#fafcff}
#wk-rule-panel .wk-row .wk-n{font-family:var(--mono);font-weight:800;font-size:12px;color:var(--ink-dim);text-align:center}
#wk-rule-panel .wk-row .wk-f{display:flex;gap:9px;flex-wrap:wrap;align-items:center}
#wk-rule-panel .wk-row .wk-f label{display:flex;flex-direction:column;gap:3px;font-size:10px;
  color:var(--ink-soft);font-weight:700;letter-spacing:.3px;text-transform:uppercase}
#wk-rule-panel .wk-row .wk-f input,#wk-rule-panel .wk-row .wk-f select{font-family:var(--mono);font-size:12px;
  padding:5px 8px;border:1px solid var(--field-line);border-radius:7px;background:var(--field);min-width:98px}
#wk-rule-panel .wk-row .wk-f input:focus,#wk-rule-panel .wk-row .wk-f select:focus{outline:none;
  border-color:var(--accent);box-shadow:0 0 0 3px rgba(23,99,214,.14);background:#fff}
#wk-rule-panel .wk-row .wk-f input.bad{border-color:var(--bad);background:var(--bad-bg)}
#wk-rule-panel .wk-ops{display:flex;gap:5px}
#wk-rule-panel .wk-ops button{width:26px;height:26px;border:1px solid var(--field-line);background:#fff;
  border-radius:7px;cursor:pointer;font-size:11px;color:var(--ink-soft);line-height:1;transition:all .15s}
#wk-rule-panel .wk-ops button:hover{background:var(--accent-soft);border-color:var(--accent-line);color:var(--accent)}
#wk-rule-panel .wk-ops button.del:hover{background:var(--bad-bg);border-color:var(--bad-line);color:#b22f37}
#wk-rule-panel .wk-ops button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
#wk-rule-panel .wk-chips{display:flex;gap:5px;flex-wrap:wrap;align-items:center}
#wk-rule-panel .wk-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--accent-line);
  background:var(--accent-soft);color:var(--accent);border-radius:7px;padding:3px 8px;
  font-family:var(--mono);font-size:11px;font-weight:700}
#wk-rule-panel .wk-chip.req{border-color:var(--warn-line);background:var(--warn-bg);color:#8a5800}
#wk-rule-panel .wk-chip button{border:0;background:transparent;cursor:pointer;color:inherit;font-size:12px;padding:0 1px;line-height:1}
#wk-rule-json{width:100%;min-height:220px;font-family:var(--mono);font-size:12px;padding:11px;
  border:1px solid var(--field-line);border-radius:9px;background:var(--field);color:var(--ink);resize:vertical}
#wk-rule-json:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(23,99,214,.14);background:#fff}
#wk-rule-panel .wk-rnote{font-size:11.5px;color:var(--ink-soft);line-height:1.5}

/* ---------- DETAIL (Simulation Data) ---------- */
#wk-detail-overlay{position:fixed;inset:0;background:rgba(16,26,36,.55);z-index:9000;display:none}
#wk-detail-panel{position:fixed;inset:24px;background:var(--card);border:1px solid var(--card-line);
  border-top:3px solid var(--accent);border-radius:var(--radius,14px);z-index:9001;display:none;
  flex-direction:column;overflow:hidden;box-shadow:0 18px 48px rgba(16,26,36,.28);font-family:var(--sans);color:var(--ink)}
#wk-detail-panel .wk-dh{display:flex;justify-content:space-between;align-items:center;gap:11px;flex-wrap:wrap;
  padding:14px 18px;border-bottom:1px solid var(--card-line);background:linear-gradient(180deg,#fbfdff,#f6f9fe)}
#wk-detail-title{font-size:14px;font-weight:800}
#wk-detail-status{padding:9px 18px;border-bottom:1px solid var(--card-line);font-size:12px;
  font-weight:650;color:var(--ink-soft);display:flex;align-items:center;gap:9px;flex-wrap:wrap}
#wk-detail-body{flex:1;overflow:auto;padding:16px 18px}
#wk-detail-panel .wk-dh .wk-dbtns{display:flex;gap:6px;flex-wrap:wrap}

@media(max-width:820px){
  #wk-panel-root .wk-toolbar,#wk-panel-root .wk-head,#wk-panel-root .wk-subbar,
  #wk-panel-root .wk-actbar,#wk-panel-root .wk-body{padding-left:14px;padding-right:14px}
  #wk-panel-root .wk-toolbar .wk-tb-right{margin-left:0;width:100%}
  #wk-panel-root .wk-find{margin-left:0;width:100%}
  #wk-panel-root .wk-find input{flex:1;width:auto}
  #wk-panel-root table.wk-matrix tbody th.wk-rowhdr,#wk-panel-root table.wk-matrix thead th.wk-corner{
    width:180px;min-width:180px;max-width:180px}
  #wk-panel-root table.wk-matrix th.wk-unitcol,#wk-panel-root table.wk-matrix td.wk-unitcol{left:180px}
  #wk-detail-panel{inset:8px}
}
</style>

<div id="wk-panel-root" class="card" style="margin-top:14px;border-top:3px solid var(--accent)">

  <!-- §5.1 header ringkas: judul + rentang + jumlah hari + fase -->
  <div class="wk-head">
    <h2>Weekly Plan</h2>
    <span class="wk-range" id="wk-range-label"></span>
    <span class="wk-sep">·</span>
    <span class="wk-days">7 Days</span>
    <span class="wk-phase" id="wk-phase"><span class="dot"></span><span id="wk-phase-t">Draft</span></span>
    <span id="wk-unsaved"><span class="dot" style="width:7px;height:7px;border-radius:50%;background:var(--bad);display:inline-block"></span>unsaved changes</span>
    <span id="wk-msg" style="margin-left:auto"></span>
  </div>

  <!-- §5.3 toolbar sticky -->
  <div class="wk-toolbar">
    <label class="fld">Start Date<input type="date" id="wk-start"></label>
    <label class="fld">End Date <span class="u">otomatis +6 hari</span><input type="date" id="wk-end" readonly></label>
    <label class="fld" style="min-width:210px">Run Mode<select id="wk-mode">
      <option value="sequential">Sequential (boundary chaining)</option>
      <option value="independent">Independent</option></select></label>
    <div class="wk-prog">
      <span class="wk-prog-l">Progress</span>
      <span class="wk-prog-row"><span class="wk-prog-bar"><i id="wk-prog-fill"></i></span><span id="wk-progress"></span></span>
    </div>
    <div class="wk-tb-right">
      <button type="button" class="btn primary" id="wk-run-day">Run Current Day</button>
      <button type="button" class="btn primary" id="wk-run-all">Run All 7 Days</button>
      <button type="button" class="btn" id="wk-cancel" disabled>Cancel</button>
      <span class="wk-tb-sep"></span>
      <button type="button" class="btn" id="wk-save-draft">Save Weekly Draft</button>
      <button type="button" class="btn" id="wk-save-done">Save Completed Days</button>
      <button type="button" class="btn dark" id="wk-save-all">Save All 7 Days</button>
    </div>
  </div>

  <!-- §5.2 tab utama -->
  <div class="wk-tabs" id="wk-maintabs" role="tablist">
    <button type="button" class="wk-tab" role="tab" data-wktab="periodic"><span class="n">1</span> Periodic Input</button>
    <button type="button" class="wk-tab active" role="tab" data-wktab="frequent"><span class="n">2</span> Frequently Input</button>
    <button type="button" class="wk-tab" role="tab" data-wktab="result"><span class="n">3</span> Result</button>
  </div>

  <!-- ============================ PERIODIC ============================ -->
  <div class="wk-pane" id="wk-pane-periodic" style="display:none">
    <div class="wk-subbar">
      <div class="wk-sub" id="wk-periodic-sub"></div>
      <div class="wk-find">
        <input type="search" id="wk-p-search" placeholder="Search parameter…" aria-label="Search parameter">
        <input type="search" id="wk-p-search-unit" class="u" placeholder="Search unit…" aria-label="Search unit">
      </div>
    </div>
    <div class="wk-actbar">
      <button type="button" class="btn sm" id="wk-p-expand">Expand All</button>
      <button type="button" class="btn sm" id="wk-p-collapse">Collapse All</button>
      <span class="wk-tb-sep"></span>
      <button type="button" class="btn sm" id="wk-p-fill-right">Fill Right</button>
      <button type="button" class="btn sm" id="wk-p-copy-all">Copy Day 1 to All</button>
      <button type="button" class="btn sm" id="wk-p-copy-prev">Copy Previous Day</button>
      <span class="wk-right">
        <button type="button" class="btn sm danger" id="wk-p-reset-day">Reset Day</button>
        <button type="button" class="btn sm danger" id="wk-p-reset-sub">Reset Section</button>
      </span>
    </div>
    <div class="wk-body">
      <div style="color:var(--ink-soft);font-size:12px;margin-bottom:12px;line-height:1.55">
        Periodic Input dirender sebagai matriks tujuh tanggal. Nilai tersimpan per hari pada
        <code style="font-family:var(--mono);font-size:11.5px">data3.&lt;unit&gt;.&lt;key&gt;</code>, sehingga tiap daily run
        menerima snapshot Periodic milik tanggalnya sendiri. Schema diturunkan dari DOM
        <b>Daily Plan &rarr; Periodic Input</b> pada tiap render — bukan daftar hardcode.
      </div>
      <div class="wk-mwrap"><table class="wk-matrix" id="wk-periodic-table"></table></div>
      <div class="wk-hint">
        <span style="background:var(--warn-bg);border:1px solid var(--warn-line);color:#8a5800">berbeda dari hari sebelumnya</span>
        <span style="background:var(--field);border:1px dashed var(--field-line);color:var(--ink-soft)">belum diisi</span>
        <span style="background:var(--bad-bg);border:1px solid var(--bad-line);color:#b22f37">invalid</span>
        <span style="background:var(--ok-bg);border:1px solid var(--ok-line);color:#0a7b6b">hasil copy</span>
      </div>
    </div>
  </div>

  <!-- =========================== FREQUENTLY =========================== -->
  <div class="wk-pane" id="wk-pane-frequent">
    <div class="wk-subbar">
      <div class="wk-sub" id="wk-frequent-sub"></div>
      <div class="wk-find">
        <input type="search" id="wk-f-search" placeholder="Search parameter…" aria-label="Search parameter">
        <input type="search" id="wk-f-search-unit" class="u" placeholder="Search unit…" aria-label="Search unit">
      </div>
    </div>
    <div class="wk-actbar">
      <button type="button" class="btn sm" id="wk-expand">Expand All</button>
      <button type="button" class="btn sm" id="wk-collapse">Collapse All</button>
      <span class="wk-tb-sep"></span>
      <button type="button" class="btn sm" id="wk-fill-right">Fill Right</button>
      <button type="button" class="btn sm" id="wk-copy-prev">Copy Previous Day</button>
      <button type="button" class="btn sm" id="wk-copy-next">Copy to Next Day</button>
      <button type="button" class="btn sm" id="wk-copy-all">Copy to All Remaining Days</button>
      <button type="button" class="btn sm" id="wk-capture">Capture Live Input &rarr; Active Day</button>
      <span class="wk-right">
        <button type="button" class="btn sm danger" id="wk-reset-day">Reset Day</button>
        <button type="button" class="btn sm danger" id="wk-reset-sub">Reset Selected Subtab</button>
        <button type="button" class="btn sm danger solid" id="wk-reset-draft">Reset Weekly Draft</button>
      </span>
    </div>
    <div class="wk-body">
      <div class="wk-mwrap"><table class="wk-matrix" id="wk-frequent-table"></table></div>
      <div class="wk-hint">
        <span style="background:var(--warn-bg);border:1px solid var(--warn-line);color:#8a5800">berbeda dari hari sebelumnya</span>
        <span style="background:var(--field);border:1px dashed var(--field-line);color:var(--ink-soft)">belum diisi</span>
        <span style="background:var(--bad-bg);border:1px solid var(--bad-line);color:#b22f37">invalid</span>
        <span style="background:var(--ok-bg);border:1px solid var(--ok-line);color:#0a7b6b">hasil copy</span>
        <span style="background:#fff;box-shadow:inset 0 0 0 2px var(--warn);color:#8a5800">berubah setelah hasil simulasi</span>
      </div>

      <!-- §5.8 IE / Dispatch PLN / CSV — HANYA untuk subtab yang memuat deret 48 slot
           (Name Plan & IE/Dispatch). Pada subtab lain kedua panel disembunyikan. -->
      <div class="wk-panels" id="wk-panels">
        <div class="wk-card">
          <div class="wk-card-h"><span class="wk-kicker">CSV</span>Import CSV &rarr; tanggal aktif
            <span class="wk-hr"><span id="wk-csv-target" class="wk-u"></span></span></div>
          <div class="wk-card-b">
            <div class="wk-drop" id="wk-csv" tabindex="0" role="button">
              Tarik file <b>.csv</b> ke sini atau klik untuk memilih file.<br>
              <span style="font-size:11px;color:var(--ink-dim)">Kolom IE &rarr; <code style="font-family:var(--mono)">data1[i].value</code> · kolom Dispatch &rarr; <code style="font-family:var(--mono)">data2[i].dispatch</code> · maksimum 48 slot.</span>
            </div>
            <input type="file" id="wk-csv-file" accept=".csv,text/csv" style="display:none">
            <div class="wk-kv" id="wk-csv-kv">
              <div><span class="k">File</span><span class="v" id="wk-csv-name">—</span></div>
              <div><span class="k">Tanggal terdeteksi</span><span class="v" id="wk-csv-date">—</span></div>
              <div><span class="k">Row valid</span><span class="v ok" id="wk-csv-valid">—</span></div>
              <div><span class="k">Error</span><span class="v bad" id="wk-csv-err">—</span></div>
              <div><span class="k">Mapping IE</span><span class="v" id="wk-csv-mapie">—</span></div>
              <div><span class="k">Mapping Dispatch PLN</span><span class="v" id="wk-csv-mapdisp">—</span></div>
            </div>
            <div class="wk-callout" id="wk-csv-status">Belum ada file yang dibaca.</div>
            <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:12px">
              <button type="button" class="btn primary sm" id="wk-csv-apply" disabled>Apply ke tanggal aktif</button>
              <button type="button" class="btn sm" id="wk-csv-preview" disabled>Preview 48 slot</button>
              <button type="button" class="btn sm danger" id="wk-csv-clear" style="margin-left:auto">Clear</button>
            </div>
            <div id="wk-csv-previewbox"></div>
          </div>
        </div>

        <div class="wk-card">
          <div class="wk-card-h"><span class="wk-kicker">Series</span>IE &amp; Dispatch PLN — 48 slot
            <span class="wk-hr">
              <span class="wk-seg" id="wk-slot-mode">
                <button type="button" data-slot="ie" class="on">IE</button>
                <button type="button" data-slot="disp">Dispatch PLN</button>
              </span>
              <button type="button" class="btn sm" id="wk-slot-reset">Reset</button>
            </span></div>
          <div class="wk-card-b">
            <div id="wk-slot-info" class="wk-callout">Pilih tanggal aktif pada header matriks untuk mengedit deret 48 slot miliknya.</div>
            <div class="wk-slotwrap"><table class="wk-slots" id="wk-slot-table"></table></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================= RESULT ============================= -->
  <div class="wk-pane" id="wk-pane-result" style="display:none">
    <div class="wk-actbar">
      <button type="button" class="btn sm" id="wk-r-save-done">Save Completed Days to Report</button>
      <button type="button" class="btn sm" id="wk-r-save-all">Save All 7 Days to Report</button>
    </div>
    <div class="wk-body">
      <div class="wk-mwrap"><table class="wk-matrix" id="wk-result-table"></table></div>
      <div class="wk-legend" id="wk-status-legend"></div>
      <div id="wk-weekly-summary" style="margin-top:18px"></div>
      <div id="wk-compare"></div>
    </div>
  </div>
</div>

<!-- editor rule terstruktur (§5.10) -->
<div id="wk-rule-overlay"></div>
<div id="wk-rule-panel" role="dialog" aria-modal="true" aria-labelledby="wk-rule-title">
  <div class="wk-rh">
    <b id="wk-rule-title"></b>
    <span class="wk-rsub" id="wk-rule-path"></span>
    <span class="wk-rr">
      <button type="button" class="btn sm" id="wk-rule-adv">Advanced / JSON</button>
      <button type="button" class="btn sm" id="wk-rule-close">Close</button>
    </span>
  </div>
  <div id="wk-rule-body"></div>
  <div class="wk-rf">
    <button type="button" class="btn sm" id="wk-rule-add">+ Add row</button>
    <select id="wk-rule-copy" style="max-width:230px"></select>
    <button type="button" class="btn sm" id="wk-rule-copybtn">Copy rule ke tanggal</button>
    <span class="wk-right">
      <span id="wk-rule-msg" style="font-size:11.5px;font-weight:650;align-self:center"></span>
      <button type="button" class="btn primary sm" id="wk-rule-apply">Apply</button>
    </span>
  </div>
</div>

<div id="wk-detail-overlay"></div>
<div id="wk-detail-panel" role="dialog" aria-modal="true" aria-labelledby="wk-detail-title">
  <div class="wk-dh">
    <b id="wk-detail-title"></b>
    <div class="wk-dbtns">
      <button type="button" class="btn sm" id="wk-detail-prev">&#9664; Previous Day</button>
      <button type="button" class="btn sm" id="wk-detail-next">Next Day &#9654;</button>
      <button type="button" class="btn sm" id="wk-detail-full">Full Screen</button>
      <button type="button" class="btn sm" id="wk-detail-imgfull">Image Full</button>
      <button type="button" class="btn sm" id="wk-detail-imgpart">Image Partial</button>
      <button type="button" class="btn sm" id="wk-detail-cert">Certificate</button>
      <button type="button" class="btn sm danger" id="wk-detail-close">Close</button>
    </div>
  </div>
  <div id="wk-detail-status"></div>
  <div id="wk-detail-body"></div>
</div>

<script>
(function(){
  const deep=o=>JSON.parse(JSON.stringify(o));
  const pad=n=>(n<10?'0':'')+n;
  const iso=d=>d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
  const addDays=(s,n)=>{const d=new Date(s+'T00:00:00');d.setDate(d.getDate()+n);return iso(d);};
  const fmt=(v,d)=>(v==null||v===''?'-':Number(v).toFixed(d==null?2:d));
  const $=id=>document.getElementById(id);
  const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const shortDate=s=>{const p=String(s).split('-');return p.length===3?(p[2]+' '+MON[+p[1]-1]):s;};
  const DAYS=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  const dowOf=s=>{const d=new Date(s+'T00:00:00');return isNaN(d)?-1:d.getDay();};
  const isWeekend=s=>{const w=dowOf(s);return w===0||w===6;};
  const isToday=s=>s===iso(new Date());
  const slotLabel=i=>pad(Math.floor(i/2))+(i%2?':30':':00');

  /* §5.4 Header kolom tanggal: blok dua baris (nama hari, tanggal) + badge status.
   * Hari aktif, hari ini, dan akhir pekan ditandai; header dapat diklik untuk memilih hari aktif. */
  function dateTh(d,i,withBadge){
    const cls=['wk-datecol']; if(i===WK.cur)cls.push('wk-active');
    if(isWeekend(d.date))cls.push('wk-wknd'); if(isToday(d.date))cls.push('wk-today');
    return '<th class="'+cls.join(' ')+'" data-di="'+i+'" title="'+esc(d.date)+(isToday(d.date)?' — hari ini':'')+'">'
      +'<span class="wk-dbox">'
      +'<span class="wk-d1">'+(dowOf(d.date)>=0?DAYS[dowOf(d.date)]:'')+(isToday(d.date)?' · TODAY':'')+'</span>'
      +'<span class="wk-d2">'+shortDate(d.date)+'</span>'
      +(withBadge===false?'':('<span class="wk-d3">'+badge(d.status,d.stale)+'</span>'))
      +'</span></th>';
  }

  const WK={start:iso(new Date()),mode:'sequential',cur:0,days:[],running:false,cancel:false,dirty:false,
            id:'',name:'',revision:0,tab:'frequent',fsub:null,psub:null,detail:-1,copied:{},
            collapsed:{},search:{p:'',pu:'',f:'',fu:''},slotKind:'ie',csv:null,rule:null};

  /* ===== SCHEMA: diturunkan dari DOM Daily Plan (shared schema, bukan daftar hardcode) ===== */
  const PATH_OVERRIDE={
    'pln-range-min':'pln_export_priority.range.min', 'pln-range-max':'pln_export_priority.range.max',
    'pln-daily-target':'pln_export_priority.daily_target.value',
    'gas_action':'gas_shortage_action', 'additional_lng':'gas_quota.additional_lng',
    'pgn_pipe':'gas_quota.pgn_pipe', 'lng':'gas_quota.lng', 'pep':'gas_quota.pep', 'distillate':'gas_quota.distillate'
  };
  function pathOf(key){ return PATH_OVERRIDE[key] || key; }
  /* root payload sesuai scope: 'modeling' -> data3.modeling · 'unit' -> data3 (tabel per-unit) */
  function rootOf(inp,scope){ const d3=(inp&&inp.data3)?inp.data3:{}; return scope==='unit'?d3:(d3.modeling||{}); }
  /* FLAG_MAP: kolom tabel Required & Commitment Daily -> path payload yang BENAR-BENAR ada.
   * 'member' = keanggotaan array (checkbox true/false) · 'map' = objek ber-key UNIT huruf besar. */
  /* Struktur payload IDENTIK dengan Daily (applyCommitToModel + assembleSchedules):
   *   m.required_mode[u] = {mode:'based_on_sim'|'start_at'|'continuous', at:'HH:MM'}
   *   m.stop_mode[u]     = {mode:'based_on_sim'|'based_on_sim_must'|'stop_at', at:'HH:MM'}
   *   m.required_units / m.unit_cannot_stop DITURUNKAN dari required_mode (bukan sumber terpisah)
   *   m.unit_last_data_status[UNIT] = 'Running'|'Stop'
   * Tidak ada key backend baru. */
  const FLAG_MAP={ req:{kind:'commit_req'},
                   mode:{kind:'commit_mode'},
                   startat:{kind:'commit_at'},
                   stopmode:{kind:'stop_mode'},
                   stopat:{kind:'stop_at'},
                   uld:{path:'unit_last_data_status',kind:'map'} };
  /* nilai UI Daily -> nilai payload (dan sebaliknya) */
  const MODE_UI2PAY={'-':'', 'simulation':'based_on_sim', 'request':'start_at', 'continuous':'continuous'};
  const MODE_PAY2UI={'':'-', 'based_on_sim':'simulation', 'start_at':'request', 'continuous':'continuous'};
  /* V15.22: Stop Status = Unit Continuous Running (hold sampai 00:00 SETELAH start) = payload stop_mode {mode:'hold_to_end'} — sama dgn Daily.
   * Dulu tidak dipetakan: Weekly menampilkan '-' untuk unit hold dan menulis enum non-kanonik. */
  const SMODE_UI2PAY={'-':'', 'sim':'based_on_sim', 'sim_must':'based_on_sim_must', 'request':'stop_at', 'continuous':'hold_to_end'};
  const SMODE_PAY2UI={'':'-', 'based_on_sim':'sim', 'based_on_sim_must':'sim_must', 'stop_at':'request', 'hold_to_end':'continuous', 'continuous':'continuous'};
  function objOf(m,key,u){ if(typeof m[key]!=='object'||m[key]===null) m[key]={};
    if(typeof m[key][u]!=='object'||m[key][u]===null) m[key][u]={}; return m[key][u]; }
  /* mirror syncFlagArrays(): required_units & unit_cannot_stop selalu diturunkan dari required_mode */
  function syncCommit(m){
    const rm=m.required_mode||{}; const req=[], cs=[];
    Object.keys(rm).forEach(u=>{ const md=(rm[u]&&rm[u].mode)||'';
      if(md==='start_at'||md==='based_on_sim') req.push(u);
      else if(md==='continuous') cs.push(u); });
    m.required_units=req; m.unit_cannot_stop=cs;
  }
  function memberGet(root,path,u){ const a=root[path]; return Array.isArray(a)&&a.map(String).map(x=>x.toLowerCase()).indexOf(u)>=0; }
  function memberSet(root,path,u,on){ let a=Array.isArray(root[path])?root[path].slice():[];
    const i=a.map(String).map(x=>x.toLowerCase()).indexOf(u);
    if(on&&i<0) a.push(u); if(!on&&i>=0) a.splice(i,1); root[path]=a; }
  function cellGet(inp,f){
    if(!inp) return undefined;
    const m=rootOf(inp,'modeling'), u=f.srcUnit;
    switch(f.scope){
      case 'member':      return memberGet(m,f.path,u)?'true':'false';
      case 'map':         { const o=m[f.path]; return o?o[u.toUpperCase()]:undefined; }
      case 'commit_req':  { const c=(m.required_mode||{})[u]||{}; return (c.mode==='start_at'||c.mode==='based_on_sim')?'true':'false'; }
      case 'commit_mode': { const c=(m.required_mode||{})[u]||{}; return MODE_PAY2UI[c.mode||'']||'-'; }
      case 'commit_at':   { const c=(m.required_mode||{})[u]||{}; return c.at||''; }
      case 'stop_mode':   { const c=(m.stop_mode||{})[u]||{}; return SMODE_PAY2UI[c.mode||'']||'-'; }
      case 'stop_at':     { const c=(m.stop_mode||{})[u]||{}; return c.at||''; }
      case 'mm2100mode': {
        const g = m.mm2100_gas_mode || {};
        return (String(g[f.gasKey] || 'fixed').toLowerCase().indexOf('cum') === 0) ? 'cummulative' : 'fixed';
      }
      case 'series': {
        const arr = f.seriesKind==='ie' ? (inp.data1||[]) : (inp.data2||[]);
        const row = arr[f.seriesIdx];
        if(!row) return undefined;
        return f.seriesKind==='ie' ? row.value : row.dispatch;
      }
    }
    return getPath(rootOf(inp,f.scope),f.path);
  }
  function cellSet(inp,f,val){
    const m=rootOf(inp,'modeling'), u=f.srcUnit;
    switch(f.scope){
      case 'member': memberSet(m,f.path,u,(val===true||val==='true'||val===1||val==='1')); return;
      case 'map':    if(typeof m[f.path]!=='object'||m[f.path]===null) m[f.path]={};
                     m[f.path][u.toUpperCase()]=val; return;
      case 'commit_req': {
        const on=(val===true||val==='true'||val===1||val==='1');
        const o=objOf(m,'required_mode',u);
        if(on){ if(o.mode!=='start_at'&&o.mode!=='based_on_sim') o.mode='based_on_sim'; }
        else  { delete m.required_mode[u]; }
        syncCommit(m); return; }
      case 'commit_mode': {
        const pay=MODE_UI2PAY[String(val)]!==undefined?MODE_UI2PAY[String(val)]:String(val);
        if(!pay){ delete (m.required_mode||{})[u];
                  if(m.required_mode && Object.keys(m.required_mode).length===0) m.required_mode=[]; }
        else { const o=objOf(m,'required_mode',u); o.mode=pay;
               if(pay!=='start_at') delete o.at;                     /* Start At hanya untuk Start Based on Request */
               if(pay==='continuous'){ delete (m.stop_mode||{})[u]; } }  /* continuous: Stop Status/At disabled (sama dgn Daily) */
        syncCommit(m); return; }
      case 'commit_at': {
        const o=objOf(m,'required_mode',u);
        if(o.mode!=='start_at') return;                              /* validasi Daily: hanya mode request */
        o.at=String(val||''); return; }
      case 'stop_mode': {
        const c=(m.required_mode||{})[u]||{};
        if(c.mode==='continuous') return;                            /* continuous: Stop Status disabled */
        const pay=SMODE_UI2PAY[String(val)]!==undefined?SMODE_UI2PAY[String(val)]:String(val);
        if(!pay){ delete (m.stop_mode||{})[u];
                  if(m.stop_mode && Object.keys(m.stop_mode).length===0) m.stop_mode=[]; }
        else { const o=objOf(m,'stop_mode',u); o.mode=pay; if(pay!=='stop_at') delete o.at; }
        return; }
      case 'stop_at': {
        const o=objOf(m,'stop_mode',u);
        if(o.mode!=='stop_at') return;
        o.at=String(val||''); return; }
      case 'mm2100mode': {
        /* Aturan Daily (§8): hanya SATU gas MM2100 boleh Cummulative; memilih satu memaksa
         * yang lain menjadi Fixed Flow. Direplikasi PER TANGGAL di Weekly. */
        const MMK = ['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'];
        if (typeof m.mm2100_gas_mode !== 'object' || m.mm2100_gas_mode === null) m.mm2100_gas_mode = {};
        const want = (String(val).toLowerCase().indexOf('cum') === 0) ? 'cummulative' : 'fixed';
        m.mm2100_gas_mode[f.gasKey] = want;
        if (want === 'cummulative') MMK.forEach(k => { if (k !== f.gasKey) m.mm2100_gas_mode[k] = 'fixed'; });
        return; }
      case 'series': {
        const kk = f.seriesKind==='ie' ? 'data1' : 'data2';
        if(!Array.isArray(inp[kk])) inp[kk]=[];
        if(typeof inp[kk][f.seriesIdx]!=='object'||inp[kk][f.seriesIdx]===null) inp[kk][f.seriesIdx]={};
        if(f.seriesKind==='ie') inp[kk][f.seriesIdx].value = val;      /* 0 tetap 0 */
        else                    inp[kk][f.seriesIdx].dispatch = val;
        return; }
    }
    setPath(rootOf(inp,f.scope),f.path,val);
  }
  function getPath(obj,path){
    const parts=String(path).split('.'); let cur=obj;
    for(const p of parts){ if(cur==null||typeof cur!=='object') return undefined; cur=cur[p]; }
    return cur;
  }
  function setPath(obj,path,val){
    const parts=String(path).split('.'); let cur=obj;
    for(let i=0;i<parts.length-1;i++){ if(typeof cur[parts[i]]!=='object'||cur[parts[i]]===null) cur[parts[i]]={}; cur=cur[parts[i]]; }
    cur[parts[parts.length-1]]=val;
  }
  function labelOf(el){
    const lab=el.closest('label');
    if(lab){ const c=lab.cloneNode(true); c.querySelectorAll('input,select,textarea').forEach(x=>x.remove());
             return c.textContent.replace(/\s+/g,' ').trim(); }
    return el.id.replace(/^f-/,'');
  }
  function unitOf(el){ const lab=el.closest('label'); const u=lab?lab.querySelector('.u'):null; return u?u.textContent.trim():''; }
  function colLabel(el){
    const tbl=el.closest('table'); if(!tbl) return el.dataset.k;
    const td=el.closest('td,th'); if(!td) return el.dataset.k;
    const idx=[...td.parentNode.children].indexOf(td);
    const head=tbl.querySelector('thead tr');
    const th=head?head.children[idx]:null;
    return th?th.textContent.replace(/\s+/g,' ').trim():el.dataset.k;
  }
  function scanGroup(child){
    const out=[]; const seen={};
    document.querySelectorAll('.childpanel[data-child="'+child+'"]').forEach(panel=>{
      const gt=document.querySelector('.childtab[data-cp="'+panel.id+'"]');
      const gname=gt?gt.textContent.replace(/\s+/g,' ').trim():panel.id;
      /* (a) field skalar ber-id f-* -> data3.modeling.<key> */
      panel.querySelectorAll('input[id^="f-"],select[id^="f-"],textarea[id^="f-"]').forEach(el=>{
        if(el.type==='button') return;
        const key=el.id.replace(/^f-/,'');
        if(/time[_-]?passed/i.test(key)) return;
        out.push({scope:'modeling', key:key, id:el.id, group:panel.id, groupName:gname, label:labelOf(el)||key,
                  unit:unitOf(el), type:(el.tagName==='SELECT'?'select':(el.type||'text')),
                  step:el.getAttribute('step')||'', path:pathOf(key)});
      });
      /* (a2) SERIES 48 ROW: IE (data-ie) dan Dispatch PLN (data-disp) -> data1[i].value / data2[i].dispatch.
       * Tiap hari Weekly membawa data1/data2 sendiri, jadi per-tanggal didukung native oleh payload. */
      panel.querySelectorAll('[data-ie],[data-disp]').forEach(el=>{
        const isIE = el.hasAttribute('data-ie');
        const idx = parseInt(isIE ? el.dataset.ie : el.dataset.disp, 10);
        if (isNaN(idx)) return;
        const hh = slotLabel(idx);
        const key = (isIE?'ie':'disp') + '.' + idx;
        if (seen[panel.id+'|'+key]) return; seen[panel.id+'|'+key]=1;
        out.push({scope:'series', key:key, id:'', group:panel.id, groupName:gname,
                  label:(isIE?'IE ':'Dispatch PLN ')+hh, unit:'MW',
                  type:'number', step:el.getAttribute('step')||'0.01',
                  path:key, seriesKind:(isIE?'ie':'disp'), seriesIdx:idx});
      });
      /* (a3) POLA DAILY LAIN -> path payload yang SUDAH ADA (tanpa key backend baru):
       *   [data-uc][data-uck] -> data3.<unit>.<koef>            (kurva fuel & STG)
       *   [data-bus]          -> modeling.bus_unit.<unit>_bus
       *   [data-rt]           -> modeling.runtime_downtime.<key>
       *   [data-stg]          -> modeling.stg_startup_mode.<unit>_startup
       *   id p-*  -> modeling.price.<key>     id q-*  -> modeling.gas_quota.<key>
       *   id em-* -> modeling.emission.<key>  id b-*  -> modeling.<key>            */
      const SIMPLE = [
        ['[data-uc][data-uck]', el => ({scope:'unit', path: el.dataset.uc + '.' + el.dataset.uck,
            label: el.dataset.uc.toUpperCase() + ' · ' + el.dataset.uck, srcUnit: el.dataset.uc, srcKey: el.dataset.uck})],
        /* MM2100/KP72 gas mode: DIRECT_PAYLOAD_FIELD -> modeling.mm2100_gas_mode.<key>
         * (dipakai engine di worker02.php: $gmMM = $model['mm2100_gas_mode']). */
        ['[data-gas]', el => ({scope:'mm2100mode', path: 'mm2100_gas_mode.' + el.dataset.gas,
            label: el.dataset.gas.replace(/_/g,' ').toUpperCase() + ' MODE', gasKey: el.dataset.gas})],
        ['[data-bus]', el => ({scope:'modeling', path: 'bus_unit.' + el.dataset.bus + '_bus',
            label: el.dataset.bus.toUpperCase() + ' · Bus'})],
        ['[data-rt]', el => ({scope:'modeling', path: 'runtime_downtime.' + el.dataset.rt,
            label: el.dataset.rt.replace(/_/g,' ')})],
        ['[data-stg]', el => ({scope:'modeling', path: 'stg_startup_mode.' + el.dataset.stg + '_startup',
            label: el.dataset.stg.toUpperCase() + ' · Startup Mode'})],
        ['input[id^="p-"],select[id^="p-"]', el => ({scope:'modeling', path: 'price.' + el.id.slice(2),
            label: 'Price · ' + el.id.slice(2)})],
        ['input[id^="q-"],select[id^="q-"]', el => ({scope:'modeling', path: 'gas_quota.' + el.id.slice(2),
            label: 'Quota · ' + el.id.slice(2)})],
        ['input[id^="em-"],select[id^="em-"]', el => ({scope:'modeling', path: 'emission.' + el.id.slice(3),
            label: 'Emission · ' + el.id.slice(3)})],
        ['input[id^="b-"],select[id^="b-"]', el => ({scope:'modeling', path: el.id.slice(2),
            label: el.id.slice(2).replace(/_/g,' ')})]
      ];
      SIMPLE.forEach(([sel, mk]) => {
        panel.querySelectorAll(sel).forEach(el => {
          if (el.type === 'button' || el.type === 'file') return;
          const d = mk(el); if (!d || !d.path) return;
          const key = d.scope + ':' + d.path;
          if (seen[panel.id + '|' + key]) return; seen[panel.id + '|' + key] = 1;
          out.push(Object.assign({key: d.path, id: el.id || '', group: panel.id, groupName: gname,
            unit: unitOf(el), type: (el.tagName === 'SELECT' ? 'select' : (el.type || 'text')),
            step: el.getAttribute('step') || '',
            options: (el.tagName === 'SELECT' ? [...el.options].map(o => ({v:o.value, t:o.textContent.trim()})) : null)}, d));
        });
      });
      /* (a4) ARRAY / RULE LIST -> satu baris per array, diedit lewat EDITOR TERSTRUKTUR per tanggal
       * (§5.10). JSON tetap tersedia di Advanced View. Seluruhnya key payload yang SUDAH ADA. */
      const ARRAYS = {
        'cp-priority':   [['block_priority','Block Priority'],['unit_priority','Unit Priority'],
                          ['unit_priority_dist','Unit Priority (distribusi)']],
        'cp-unitstatus': [['unit_fix_load','Fix Load rules'],['unit_skip_load','Skip Load rules'],
                          ['unit_stop','Stop units'],['unit_stop_time','Stop schedule'],
                          ['max_load_rules','Max Load rules'],['hrsg_stop','HRSG stop'],['hrsg_stop_time','HRSG stop schedule']],
        'cp-pln':        [['pln_export_priority.deviation','PLN deviation rules']],
        'cp-nameplan':   [['ie_adjustments','IE Adjustment'],['manual_fixed_flows','Manual fixed flows']]
      };
      (ARRAYS[panel.id] || []).forEach(([pathA, labelA]) => {
        const key = 'modeling:' + pathA;
        if (seen[panel.id + '|' + key]) return; seen[panel.id + '|' + key] = 1;
        out.push({scope:'modeling', key: pathA, path: pathA, id:'', group: panel.id, groupName: gname,
                  label: labelA, unit:'', type:'text', step:'', options:null, isArray:true});
      });
      /* (b) tabel per-unit [data-u][data-k] (Presence & Load Limits, Required & Commitment, dst)
       *     -> data3.<unit>.<key>. Dibangun JS dari schema Daily, jadi otomatis ikut bila Daily berubah. */
      panel.querySelectorAll('[data-u][data-flag]').forEach(el=>{
        const u=el.dataset.u, fl=el.dataset.flag;
        const map=FLAG_MAP[fl]; if(!u||!map) return;                 /* flag tanpa path payload terverifikasi dilewati */
        const key=u+'.'+fl; if(seen[panel.id+'|'+key]) return; seen[panel.id+'|'+key]=1;
        out.push({scope:map.kind, key:key, id:'', group:panel.id, groupName:gname,
                  label:u.toUpperCase()+' · '+colLabel(el), unit:'',
                  type:(el.tagName==='SELECT'?'select':(el.type==='checkbox'?'checkbox':(el.type||'text'))),
                  step:'', path:map.path||'', srcUnit:u, srcKey:fl,
                  options:(el.tagName==='SELECT'?[...el.options].map(o=>({v:o.value,t:o.textContent.trim()})):null)});
      });
      panel.querySelectorAll('[data-u][data-k]').forEach(el=>{
        const u=el.dataset.u, k=el.dataset.k;
        if(!u||!k) return;
        if(/time[_-]?passed/i.test(k)) return;
        const key=u+'.'+k; if(seen[panel.id+'|'+key]) return; seen[panel.id+'|'+key]=1;
        out.push({scope:'unit', key:key, id:'', group:panel.id, groupName:gname,
                  label:u.toUpperCase()+' · '+colLabel(el), unit:'',
                  type:(el.tagName==='SELECT'?'select':(el.type==='checkbox'?'checkbox':(el.type||'text'))),
                  step:el.getAttribute('step')||'', path:key, srcUnit:u, srcKey:k});
      });
    });
    return out;
  }
  let SCHEMA={periodic:[],frequent:[]};
  function buildSchema(){ SCHEMA={periodic:scanGroup('periodic'),frequent:scanGroup('frequent')};
    if(!WK.fsub && SCHEMA.frequent.length) WK.fsub=SCHEMA.frequent[0].group;
    if(!WK.psub && SCHEMA.periodic.length) WK.psub=SCHEMA.periodic[0].group;
    return SCHEMA; }
  /* §5.6 Kategori baris di dalam satu subtab -> baris group collapsible.
   * Nama kategori HANYA diturunkan dari path payload / scope yang benar-benar ada di source.
   * Field yang tidak memenuhi pola manapun jatuh ke 'Parameter Umum' — tidak ditebak. */
  function catOf(f){
    if(f.isArray) return 'Rules / Schedule';
    if(f.scope==='series') return f.seriesKind==='ie' ? 'IE Forecast (48 slot)' : 'Dispatch PLN (48 slot)';
    if(f.scope==='mm2100mode') return 'Gas MM2100 · Mode';
    if(['member','map','commit_req','commit_mode','commit_at','stop_mode','stop_at'].includes(f.scope))
      return 'Commitment & Status Unit';
    if(f.scope==='unit'){
      const k=String(f.srcKey||'');
      if(/^(x2_f1|x1_f1|x0_f1|xcf)$/.test(k)) return 'Fuel Curve';
      const u=String(f.srcUnit||'');
      if(/^ge\d/.test(u)) return 'Gas Engine Parameters';
      if(/^s\d/.test(u))  return 'STG Parameters';
      if(/^b\d/.test(u))  return 'Babelan';
      if(/^h\d/.test(u))  return 'Additional HRSG';
      if(/^g\d/.test(u))  return 'GTG Parameters';
      return 'Per Unit';
    }
    const p=String(f.path||'');
    if(p.indexOf('gas_quota.')===0) return 'Gas Quota';
    if(p.indexOf('price.')===0) return 'Fuel Price';
    if(p.indexOf('emission.')===0) return 'Emission';
    if(p.indexOf('runtime_downtime.')===0) return 'Ramp & Runtime';
    if(p.indexOf('bus_unit.')===0) return 'Bus Configuration';
    if(p.indexOf('stg_startup_mode.')===0) return 'STG Startup Mode';
    if(p.indexOf('pln_export_priority')===0) return 'PLN Export';
    return 'Parameter Umum';
  }
  function groupsOf(list){ const seen={},out=[];
    list.forEach(f=>{ if(!seen[f.group]){seen[f.group]=1;out.push({id:f.group,name:f.groupName});} }); return out; }
  /* §5.6 pencarian parameter / unit — filter presentasi saja, tidak mengubah payload */
  function matches(f,q,qu){
    if(q){ const s=(f.label+' '+(f.path||'')+' '+(f.key||'')).toLowerCase();
           if(s.indexOf(q.toLowerCase())<0) return false; }
    if(qu){ const u=((f.srcUnit||'')+' '+(f.unit||'')+' '+f.label).toLowerCase();
            if(u.indexOf(qu.toLowerCase())<0) return false; }
    return true;
  }

  /* ================================ STATE ============================================ */
  function blankDay(dateIso){ return {date:dateIso,input:null,status:'NOT RUN',result:null,summary:null,
                                     boundary:null,violations:[],revision:'',stale:false,runtime:0,certificate:null}; }
  function rebuildDays(){
    const dates=[]; for(let i=0;i<7;i++) dates.push(addDays(WK.start,i));
    const old={}; WK.days.forEach(d=>old[d.date]=d);
    WK.days=dates.map(dt=>old[dt]||blankDay(dt));
    WK.id='WK-'+WK.start.replace(/-/g,'');
    WK.name='Weekly Plan '+WK.start+' to '+addDays(WK.start,6);
    $('wk-end').value=addDays(WK.start,6);
    $('wk-range-label').textContent=shortDate(WK.start)+' '+WK.start.slice(0,4)+' \u2192 '+
      shortDate(addDays(WK.start,6))+' '+addDays(WK.start,6).slice(0,4);
    buildSchema(); renderAll();
  }
  const BADGE={'PASS':'#1e8e3e','VALID-INFEASIBLE':'#2f6f9f','SEARCH_EXHAUSTED':'#8a6d3b','FAIL':'#c0392b',
               'ERROR':'#7b241c','RUNNING':'#b7791f','STALE':'#8a6d3b','DRAFT':'#5b6b7a','NOT RUN':'#8a99a8'};
  function badge(st,stale){ const lab=(stale&&st!=='NOT RUN'&&st!=='DRAFT')?'STALE':st;
    return '<span class="wk-badge" style="background:'+(BADGE[lab]||'#8a99a8')+'">'+lab+'</span>'; }
  function isValidDay(d){ return d.status==='PASS' && !d.stale; }
  /* boleh disimpan ke Report sebagai hasil final */
  function isCompletedFinal(d){
    if(d.stale) return false;
    if(d.status==='PASS') return true;
    return d.status==='VALID-INFEASIBLE' && !!d.certificate;   /* wajib bersertifikat */
  }
  /* §5.3 indikator fase mingguan pada header */
  function renderPhase(){
    const el=$('wk-phase'), t=$('wk-phase-t'); if(!el) return;
    let cls='', lab='Draft';
    if(WK.running){ cls='run'; lab='Running'; }
    else if(WK.days.length && WK.days.every(isCompletedFinal)){ cls='done'; lab='Completed'; }
    else if(WK.days.some(d=>d.result)){ lab='Partial'; }
    el.className='wk-phase'+(cls?' '+cls:''); t.textContent=lab;
    const done=WK.days.filter(d=>d.result&&!d.stale).length;
    const fill=$('wk-prog-fill'); if(fill) fill.style.width=Math.round(done/7*100)+'%';
    if(!WK.running) $('wk-progress').textContent=done?(done+'/7'):'';
  }

  /* ====================== MATRIKS: RENDERER SEL BERSAMA ====================== */
  /* Satu fungsi sel untuk Periodic dan Frequently supaya format, kelas indikator,
   * dan binding identik di kedua tab. attrName = 'pi' | 'fi'. */
  function cellHtml(i,f,idxAttr,idx){
    const v=cellVal(i,f);
    const prev=i>0?cellVal(i-1,f):undefined;
    const cls=[];
    if(i===WK.cur) cls.push('wk-activecol');
    if(v===undefined||v===null||v==='') cls.push('wk-empty');
    else if(i>0 && JSON.stringify(v)!==JSON.stringify(prev)) cls.push('wk-diff');
    if(WK.copied[i+'|'+f.path+'|'+(f.srcUnit||'')]) cls.push('wk-copied');
    if(WK.days[i].result && WK.days[i].stale) cls.push('wk-changed');
    if(f.type==='number' && v!==undefined && v!==null && v!=='' && isNaN(Number(v))) cls.push('wk-invalid');
    const sv=(v===undefined||v===null)?'':(typeof v==='object'?JSON.stringify(v):String(v));
    const attrs='data-di="'+i+'" data-'+idxAttr+'="'+idx+'" data-ftype="'+esc(f.type)+'" data-scope="'+esc(f.scope||'modeling')+'"';

    /* §5.10 rule array -> ringkasan + tombol editor terstruktur (bukan textarea JSON) */
    if(f.isArray){
      const arr=(v&&typeof v==='object')?v:[];
      const n=Array.isArray(arr)?arr.length:0;
      return '<td class="'+cls.join(' ')+'"><div class="wk-rulecell">'+
        '<span class="s">'+(n?(n+' rule'+(n>1?'s':'')):'kosong')+'</span>'+
        '<button type="button" class="btn link" data-ruleopen="1" '+attrs+'>Edit&hellip;</button>'+
        '</div></td>';
    }
    /* §5.9 mode gas MM2100 -> segmented control (nilai tetap ditulis lewat input tersembunyi) */
    if(f.scope==='mm2100mode'){
      const cum=String(sv).toLowerCase().indexOf('cum')===0;
      return '<td class="'+cls.join(' ')+'"><span class="wk-seg" data-segfor="'+idxAttr+idx+'-'+i+'">'+
        '<button type="button" class="'+(cum?'':'on')+'" data-segval="fixed">Fixed</button>'+
        '<button type="button" class="'+(cum?'on':'')+'" data-segval="cummulative">Cumul</button>'+
        '</span><input type="hidden" id="wk-seg-'+idxAttr+idx+'-'+i+'" '+attrs+' value="'+esc(sv)+'"></td>';
    }
    if(f.options&&f.options.length){
      return '<td class="'+cls.join(' ')+'"><select '+attrs+'>'+
        f.options.map(o=>'<option value="'+esc(o.v)+'"'+(String(o.v)===sv?' selected':'')+'>'+esc(o.t)+'</option>').join('')+
        '</select></td>';
    }
    const itype=(f.type==='number'?'number':(f.type==='time'?'time':'text'));
    return '<td class="'+cls.join(' ')+'"><input '+attrs+' type="'+itype+'"'+
      (f.step?(' step="'+esc(f.step)+'"'):'')+' value="'+esc(sv)+'"></td>';
  }
  /* header matriks: Parameter | Unit | 7 tanggal | Action (§5.5) */
  function matrixHead(withBadge,firstLabel,lastLabel){
    let h='<thead><tr><th class="wk-corner">'+firstLabel+'</th><th class="wk-unitcol">Unit</th>';
    WK.days.forEach((d,i)=>{ h+=dateTh(d,i,withBadge); });
    return h+'<th>'+lastLabel+'</th></tr></thead>';
  }
  const MATRIX_COLS=10;   /* Parameter + Unit + 7 tanggal + Action */
  function groupRow(cat,count,collapsed){
    return '<tr class="wk-group'+(collapsed?' collapsed':'')+'" data-cat="'+esc(cat)+'">'+
      '<th class="wk-grouphdr" colspan="'+MATRIX_COLS+'"><span class="wk-chev">&#9660;</span> '+esc(cat)+
      '<span class="wk-gcount">'+count+' parameter</span></th></tr>';
  }
  /* Kolom Action per baris. Atribut sengaja BUKAN data-pi/data-fi supaya jumlah sel per baris
   * yang dihitung probe ([data-fi="n"]) tetap tepat tujuh — satu per tanggal. */
  function rowActionCell(idx){
    return '<td style="text-align:center"><button type="button" class="btn link" data-rowfill="1" '+
      'data-rowidx="'+idx+'" title="Salin nilai hari aktif ke seluruh hari berikutnya">Fill &rarr;</button></td>';
  }
  /* bind seragam untuk kedua matriks */
  function bindMatrix(tableId,idxAttr,list){
    const tbl=$(tableId); if(!tbl) return;
    tbl.querySelectorAll('th.wk-datecol').forEach(th=>
      th.addEventListener('click',()=>{WK.cur=+th.dataset.di;renderAll();}));
    tbl.querySelectorAll('tr.wk-group th').forEach(th=>
      th.addEventListener('click',()=>{ const cat=th.parentNode.dataset.cat;
        WK.collapsed[cat]=!WK.collapsed[cat]; renderAll(); }));
    tbl.querySelectorAll('input[data-'+idxAttr+'],select[data-'+idxAttr+']').forEach(inp=>{
      if(inp.hasAttribute('data-ruleopen')) return;
      inp.addEventListener('change',()=>writeCellF(+inp.dataset.di,list[+inp.dataset[idxAttr]],inp.value));
      inp.addEventListener('keydown',e=>{ if(e.key==='Enter'){ inp.dispatchEvent(new Event('change')); e.preventDefault(); } });
    });
    tbl.querySelectorAll('.wk-seg[data-segfor]').forEach(seg=>{
      seg.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
        const hid=$('wk-seg-'+seg.dataset.segfor); if(!hid) return;
        hid.value=b.dataset.segval; hid.dispatchEvent(new Event('change'));
      }));
    });
    tbl.querySelectorAll('button[data-ruleopen]').forEach(b=>
      b.addEventListener('click',()=>openRuleEditor(+b.dataset.di,list[+b.dataset[idxAttr]])));
    tbl.querySelectorAll('button[data-rowfill]').forEach(b=>
      b.addEventListener('click',()=>fillRightRow(list[+b.dataset.rowidx])));
  }

  /* =============================== PERIODIC ========================================== */
  function renderPeriodic(){
    buildSchema();                                   /* rescan: tabel per-unit dibangun JS Daily setelah load */
    const sub=$('wk-periodic-sub'); sub.innerHTML='';
    groupsOf(SCHEMA.periodic).forEach(g=>{
      const b=document.createElement('button'); b.type='button';
      b.className='wk-subtab'+(g.id===WK.psub?' active':''); b.dataset.g=g.id; b.textContent=g.name;
      b.addEventListener('click',()=>{WK.psub=g.id;renderPeriodic();}); sub.appendChild(b);
    });
    const all=SCHEMA.periodic.filter(f=>f.group===WK.psub);
    const rows=all.filter(f=>matches(f,WK.search.p,WK.search.pu));
    /* MATRIKS TUJUH TANGGAL: nilai Periodic disimpan per hari pada WK.days[i].input.data3.<unit>.<key>,
     * sehingga setiap daily run menerima periodic snapshot tanggalnya sendiri. */
    let h=matrixHead(true,'Parameter','Action')+'<tbody>';
    const byCat={}, order=[];
    rows.forEach(f=>{ const c=catOf(f); if(!byCat[c]){byCat[c]=[];order.push(c);} byCat[c].push(f); });
    order.forEach(cat=>{
      const collapsed=!!WK.collapsed[cat];
      h+=groupRow(cat,byCat[cat].length,collapsed);
      if(collapsed) return;
      byCat[cat].forEach(f=>{
        const idx=SCHEMA.periodic.indexOf(f);
        h+='<tr><th class="wk-rowhdr" title="'+esc(f.label)+'">'+esc(f.label)+'</th>'+
           '<td class="wk-unitcol wk-u">'+esc(f.unit||'')+'</td>';
        for(let i=0;i<7;i++) h+=cellHtml(i,f,'pi',idx);
        h+=rowActionCell(idx)+'</tr>';
      });
    });
    if(!rows.length) h+='<tr><td colspan="'+MATRIX_COLS+'" class="wk-empty-state">'+
      (all.length?'Tidak ada parameter yang cocok dengan pencarian.':'Tidak ada field pada kelompok ini.')+'</td></tr>';
    $('wk-periodic-table').innerHTML=h+'</tbody>';
    bindMatrix('wk-periodic-table','pi',SCHEMA.periodic);
  }

  /* ============================== FREQUENTLY ========================================= */
  function ensureInput(i){ if(!WK.days[i].input){ const p=captureLive(true); if(p){WK.days[i].input=p;
      if(WK.days[i].status==='NOT RUN') WK.days[i].status='DRAFT'; } } return WK.days[i].input; }
  function cellVal(i,f){ const inp=WK.days[i].input; if(!inp) return undefined; return cellGet(inp,f); }
  function renderFrequent(){
    buildSchema();
    const sub=$('wk-frequent-sub'); sub.innerHTML='';
    groupsOf(SCHEMA.frequent).forEach(g=>{
      const b=document.createElement('button'); b.type='button';
      b.className='wk-subtab'+(g.id===WK.fsub?' active':''); b.dataset.g=g.id; b.textContent=g.name;
      b.addEventListener('click',()=>{WK.fsub=g.id;renderFrequent();}); sub.appendChild(b);
    });
    const all=SCHEMA.frequent.filter(f=>f.group===WK.fsub);
    const rows=all.filter(f=>matches(f,WK.search.f,WK.search.fu));
    let h=matrixHead(true,'Parameter','Action')+'<tbody>';
    const byCat={}, order=[];
    rows.forEach(f=>{ const c=catOf(f); if(!byCat[c]){byCat[c]=[];order.push(c);} byCat[c].push(f); });
    order.forEach(cat=>{
      const collapsed=!!WK.collapsed[cat];
      h+=groupRow(cat,byCat[cat].length,collapsed);
      if(collapsed) return;
      byCat[cat].forEach(f=>{
        const idx=SCHEMA.frequent.indexOf(f);
        h+='<tr><th class="wk-rowhdr" title="'+esc(f.label)+'">'+esc(f.label)+'</th>'+
           '<td class="wk-unitcol wk-u">'+esc(f.unit||'')+'</td>';
        for(let i=0;i<7;i++) h+=cellHtml(i,f,'fi',idx);
        h+=rowActionCell(idx)+'</tr>';
      });
    });
    if(!rows.length) h+='<tr><td colspan="'+MATRIX_COLS+'" class="wk-empty-state">'+
      (all.length?'Tidak ada parameter yang cocok dengan pencarian.':'Tidak ada field pada subtab ini.')+'</td></tr>';
    $('wk-frequent-table').innerHTML=h+'</tbody>';
    bindMatrix('wk-frequent-table','fi',SCHEMA.frequent);
    /* Panel Import CSV dan panel IE & Dispatch 48 slot hanya relevan untuk subtab yang benar-benar
     * memuat deret 48 slot (data1/data2 -> scope 'series', yaitu Name Plan & IE/Dispatch).
     * Deteksi diturunkan dari schema, bukan dari id subtab yang di-hardcode. */
    const hasSeries=all.some(f=>f.scope==='series');
    const panels=$('wk-panels'); if(panels) panels.style.display=hasSeries?'':'none';
    if(hasSeries){ renderSlotPanel(); renderCsvPanel(); }
  }
  function coerce(raw,ftype){
    if(ftype==='number') return (raw===''?'':Number(raw));           /* 0 tetap 0 */
    if(raw==='true'||raw==='false') return (raw==='true');           /* false tetap false */
    if(/^\s*[\[{]/.test(raw)){ try{ return JSON.parse(raw); }catch(e){ return raw; } }
    return raw;                                                       /* '' valid tetap '' */
  }
  function writeCellF(i,f,raw){
    if(!f) return; const inp=ensureInput(i); if(!inp) return;
    cellSet(inp,f,coerce(raw,f.type));
    if(WK.days[i].status==='NOT RUN') WK.days[i].status='DRAFT';
    markDirtyFrom(i);
  }
  /* kompatibilitas API test lama: writeCell(i,path,raw,ftype,scope) */
  function writeCell(i,path,raw,ftype,scope){
    const f=SCHEMA.frequent.filter(x=>x.path===path&&(x.scope||'modeling')===(scope||'modeling'))[0]
          || {scope:scope||'modeling',path:path,type:ftype||'text'};
    writeCellF(i,f,raw);
  }

  /* ====================== §5.8 PANEL IE / DISPATCH 48 SLOT =========================== */
  /* Panel ini adalah PRESENTASI dari deret yang sudah ada (data1[i].value, data2[i].dispatch)
   * pada tanggal aktif. Tidak ada key baru dan tidak ada perhitungan ulang. */
  function renderSlotPanel(){
    const tbl=$('wk-slot-table'); if(!tbl) return;
    const d=WK.days[WK.cur]; const inp=d?d.input:null;
    const kind=WK.slotKind;
    $('wk-slot-info').className='wk-callout'+(inp?'':'');
    $('wk-slot-info').textContent=inp
      ? ('Tanggal aktif '+d.date+' · '+(kind==='ie'?'IE Forecast':'Dispatch PLN')+' · 48 slot · nilai ditulis ke '
         +(kind==='ie'?'data1[i].value':'data2[i].dispatch')+' milik tanggal ini saja.')
      : 'Tanggal aktif belum punya input. Jalankan Capture Live Input atau isi salah satu sel matriks.';
    const arr=inp?(kind==='ie'?(inp.data1||[]):(inp.data2||[])):[];
    let h='<thead><tr><th class="t" style="text-align:left">Slot</th><th style="text-align:right">'+
      (kind==='ie'?'IE (MW)':'Dispatch PLN (MW)')+'</th></tr></thead><tbody>';
    for(let i=0;i<48;i++){
      const row=arr[i]||{};
      const v=(kind==='ie'?row.value:row.dispatch);
      const sv=(v===undefined||v===null)?'':String(v);
      const bad=(sv!==''&&isNaN(Number(sv)));
      h+='<tr><td class="t">'+slotLabel(i)+'</td><td'+(bad?' class="bad"':'')+
         '><input type="number" step="0.01" data-slotidx="'+i+'" value="'+esc(sv)+'"'+(inp?'':' disabled')+'></td></tr>';
    }
    tbl.innerHTML=h+'</tbody>';
    tbl.querySelectorAll('input[data-slotidx]').forEach(el=>{
      el.addEventListener('change',()=>{
        const i=+el.dataset.slotidx;
        const f={scope:'series',path:(kind==='ie'?'ie.':'disp.')+i,seriesKind:kind,seriesIdx:i,type:'number'};
        writeCellF(WK.cur,f,el.value);
      });
    });
  }

  /* ============================ §5.8 IMPORT CSV ===================================== */
  /* Parser CSV memakai konvensi kolom Daily (kolom IE dan Dispatch per slot). Hasil parse
   * ditahan di WK.csv sampai Apply, sehingga operator dapat memeriksa validasi lebih dulu. */
  function parseCsv(text,fileName){
    const res={file:fileName,date:'',rows:[],errors:[],mapIE:-1,mapDisp:-1,dupe:[],missing:[],nonNumeric:[]};
    const lines=String(text).split(/\r?\n/).filter(x=>x.trim()!=='');
    if(!lines.length){ res.errors.push('File kosong.'); return res; }
    const head=lines[0].split(/[,;\t]/).map(x=>x.trim().toLowerCase());
    let iIE=head.findIndex(h=>/^ie\b|load|beban/.test(h));
    let iDS=head.findIndex(h=>/disp/.test(h));
    let iTS=head.findIndex(h=>/time|jam|slot|waktu/.test(h));
    let start=1;
    if(iIE<0&&iDS<0){ iIE=head.length>1?1:0; iDS=head.length>2?2:-1; iTS=head.length>1?0:-1; start=0; }
    res.mapIE=iIE; res.mapDisp=iDS;
    const dt=String(fileName).match(/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/);
    if(dt) res.date=dt[1]+'-'+dt[2]+'-'+dt[3];
    const seenTs={};
    let n=0;
    for(let r=start;r<lines.length && n<48;r++){
      const c=lines[r].split(/[,;\t]/).map(x=>x.trim());
      const ts=(iTS>=0&&c[iTS]!==undefined)?c[iTS]:slotLabel(n);
      if(seenTs[ts]){ res.dupe.push(ts); res.errors.push('slot '+ts+' duplikat'); continue; }
      seenTs[ts]=1;
      const rec={slot:n,ts:ts,ie:undefined,disp:undefined};
      let ok=true;
      if(iIE>=0 && c[iIE]!==undefined && c[iIE]!==''){
        const v=Number(c[iIE]);
        if(isNaN(v)){ res.nonNumeric.push(ts); res.errors.push('slot '+ts+' IE bukan numerik'); ok=false; }
        else rec.ie=v;
      }
      if(iDS>=0 && c[iDS]!==undefined && c[iDS]!==''){
        const v=Number(c[iDS]);
        if(isNaN(v)){ res.nonNumeric.push(ts); res.errors.push('slot '+ts+' Dispatch bukan numerik'); ok=false; }
        else rec.disp=v;
      }
      if(rec.ie===undefined && rec.disp===undefined){ res.missing.push(ts); }
      if(ok) res.rows.push(rec);
      n++;
    }
    if(res.rows.length<48) res.missing.push((48-res.rows.length)+' slot tidak ada di file');
    return res;
  }
  function renderCsvPanel(){
    const c=WK.csv;
    const t=$('wk-csv-target'); if(t) t.textContent=WK.days[WK.cur]?('tanggal aktif '+WK.days[WK.cur].date):'';
    const set=(id,v,cls)=>{ const e=$(id); if(!e) return; e.textContent=v;
      if(cls!==undefined){ e.className='v'+(cls?(' '+cls):''); } };
    if(!c){
      set('wk-csv-name','—'); set('wk-csv-date','—'); set('wk-csv-valid','—',''); set('wk-csv-err','—','');
      set('wk-csv-mapie','—'); set('wk-csv-mapdisp','—');
      $('wk-csv-status').className='wk-callout'; $('wk-csv-status').textContent='Belum ada file yang dibaca.';
      $('wk-csv-apply').disabled=true; $('wk-csv-preview').disabled=true; $('wk-csv-previewbox').innerHTML='';
      return;
    }
    set('wk-csv-name',c.file); set('wk-csv-date',c.date||'tidak terdeteksi');
    set('wk-csv-valid',String(c.rows.length),'ok'); set('wk-csv-err',String(c.errors.length),c.errors.length?'bad':'');
    set('wk-csv-mapie',c.mapIE>=0?('kolom '+(c.mapIE+1)+' → data1[i].value'):'tidak ada');
    set('wk-csv-mapdisp',c.mapDisp>=0?('kolom '+(c.mapDisp+1)+' → data2[i].dispatch'):'tidak ada');
    const st=$('wk-csv-status');
    if(c.errors.length){ st.className='wk-callout bad';
      st.textContent=c.errors.length+' baris ditolak — '+c.errors.slice(0,4).join(' · ')+
        (c.errors.length>4?(' · +'+(c.errors.length-4)+' lainnya'):'')+'. Baris lain tetap dapat diterapkan.'; }
    else if(c.date && WK.days[WK.cur] && c.date!==WK.days[WK.cur].date){ st.className='wk-callout warn';
      st.textContent='Tanggal pada nama file ('+c.date+') berbeda dari tanggal aktif ('+WK.days[WK.cur].date+'). Apply tetap menulis ke tanggal aktif.'; }
    else { st.className='wk-callout ok'; st.textContent=c.rows.length+' slot siap diterapkan ke tanggal aktif.'; }
    $('wk-csv-apply').disabled=!c.rows.length; $('wk-csv-preview').disabled=!c.rows.length;
  }
  function csvPreview(){
    const c=WK.csv; if(!c) return;
    let h='<div class="wk-slotwrap"><table class="wk-slots"><thead><tr><th class="t" style="text-align:left">Slot</th>'+
      '<th style="text-align:right">IE</th><th style="text-align:right">Dispatch</th></tr></thead><tbody>';
    c.rows.forEach(r=>{ h+='<tr><td class="t">'+esc(r.ts)+'</td>'+
      '<td>'+(r.ie===undefined?'—':fmt(r.ie,2))+'</td>'+
      '<td>'+(r.disp===undefined?'—':fmt(r.disp,2))+'</td></tr>'; });
    $('wk-csv-previewbox').innerHTML=h+'</tbody></table></div>';
  }
  function csvApply(){
    const c=WK.csv; if(!c||!c.rows.length) return;
    const inp=ensureInput(WK.cur); if(!inp){ msg('Tanggal aktif belum punya input.',true); return; }
    if(!Array.isArray(inp.data1)) inp.data1=[];
    if(!Array.isArray(inp.data2)) inp.data2=[];
    let n=0;
    c.rows.forEach(r=>{
      const i=r.slot;
      if(r.ie!==undefined){
        if(typeof inp.data1[i]!=='object'||inp.data1[i]===null) inp.data1[i]={};
        inp.data1[i].value=r.ie; }
      if(r.disp!==undefined){
        if(typeof inp.data2[i]!=='object'||inp.data2[i]===null) inp.data2[i]={};
        inp.data2[i].dispatch=r.disp; }
      n++;
    });
    if(WK.days[WK.cur].status==='NOT RUN') WK.days[WK.cur].status='DRAFT';
    markDirtyFrom(WK.cur);
    msg('CSV diterapkan ke '+WK.days[WK.cur].date+': '+n+' slot'+(c.mapDisp>=0?' (IE + Dispatch)':' (IE)')+'.');
  }

  /* ======================= §5.10 EDITOR RULE TERSTRUKTUR ============================= */
  /* Editor menurunkan bentuk barisnya dari DATA YANG ADA (array-of-array untuk priority,
   * array-of-object untuk rule schedule). Tidak ada key backend baru yang dikarang: key objek
   * diambil dari record yang sudah ada atau dari baseline live Daily. Bila sebuah array kosong
   * DAN tidak ada contoh bentuk di manapun, editor terstruktur menyatakannya dan menyerahkan
   * pengisian ke Advanced / JSON — daripada menebak skema. */
  let RULE={di:-1,f:null,val:[],adv:false,tmpl:null};
  function ruleTemplate(f){
    for(let i=0;i<7;i++){
      const v=WK.days[i].input?cellGet(WK.days[i].input,f):undefined;
      if(Array.isArray(v)&&v.length) return deep(v[0]);
    }
    const base=captureLive(true);
    if(base){ const v=cellGet(base,f); if(Array.isArray(v)&&v.length) return deep(v[0]); }
    return null;
  }
  function blankFrom(tmpl){
    if(Array.isArray(tmpl)) return [];
    if(tmpl&&typeof tmpl==='object'){ const o={}; Object.keys(tmpl).forEach(k=>{
      const t=tmpl[k]; o[k]=(typeof t==='number')?0:((typeof t==='boolean')?false:''); }); return o; }
    return '';
  }
  function ruleRowHtml(item,idx){
    let inner='';
    if(Array.isArray(item)){
      inner='<div class="wk-chips">'+item.map((u,j)=>
        '<span class="wk-chip'+(String(u)==='required'?' req':'')+'">'+esc(u)+
        '<button type="button" data-chipdel="'+idx+'|'+j+'" aria-label="hapus">&times;</button></span>').join('')+
        '<input type="text" data-chipadd="'+idx+'" placeholder="+ unit" style="width:96px">'+
        '</div>';
    } else if(item&&typeof item==='object'){
      inner='<div class="wk-f">'+Object.keys(item).map(k=>{
        const v=item[k];
        const t=(typeof v==='number')?'number':((/^\d{2}:\d{2}$/.test(String(v)))?'time':'text');
        return '<label>'+esc(k)+'<input type="'+t+'" data-rk="'+idx+'|'+esc(k)+'" value="'+esc(v)+'"></label>';
      }).join('')+'</div>';
    } else {
      inner='<div class="wk-f"><label>value<input type="text" data-rv="'+idx+'" value="'+esc(item)+'"></label></div>';
    }
    return '<div class="wk-row"><span class="wk-n">'+(idx+1)+'</span>'+inner+
      '<span class="wk-ops">'+
      '<button type="button" data-rup="'+idx+'" title="Move Up">&#9650;</button>'+
      '<button type="button" data-rdn="'+idx+'" title="Move Down">&#9660;</button>'+
      '<button type="button" class="del" data-rdel="'+idx+'" title="Delete">&times;</button>'+
      '</span></div>';
  }
  function renderRuleBody(){
    const body=$('wk-rule-body');
    if(RULE.adv){
      body.innerHTML='<div class="wk-rnote">Advanced / Diagnostic View — JSON mentah untuk '+
        '<code style="font-family:var(--mono)">data3.modeling.'+esc(RULE.f.path)+'</code> pada tanggal ini.</div>'+
        '<textarea id="wk-rule-json" spellcheck="false"></textarea>';
      $('wk-rule-json').value=JSON.stringify(RULE.val,null,2);
      $('wk-rule-add').disabled=true;
      return;
    }
    $('wk-rule-add').disabled=false;
    if(!RULE.val.length && !RULE.tmpl){
      body.innerHTML='<div class="wk-callout warn" style="margin:0">Daftar ini kosong dan tidak ada contoh bentuk record '+
        'di tujuh tanggal maupun pada baseline Daily yang aktif. Bentuk record tidak ditebak — gunakan '+
        '<b>Advanced / JSON</b> untuk membuat entri pertama, lalu editor terstruktur akan mengikuti bentuknya.</div>';
      $('wk-rule-add').disabled=true;
      return;
    }
    body.innerHTML=RULE.val.length
      ? RULE.val.map(ruleRowHtml).join('')
      : '<div class="wk-callout" style="margin:0">Belum ada rule. Tekan <b>+ Add row</b> untuk menambah.</div>';
    /* binding baris */
    body.querySelectorAll('[data-rup]').forEach(b=>b.addEventListener('click',()=>{
      const i=+b.dataset.rup; if(i<=0) return;
      const t=RULE.val[i-1]; RULE.val[i-1]=RULE.val[i]; RULE.val[i]=t; renderRuleBody(); }));
    body.querySelectorAll('[data-rdn]').forEach(b=>b.addEventListener('click',()=>{
      const i=+b.dataset.rdn; if(i>=RULE.val.length-1) return;
      const t=RULE.val[i+1]; RULE.val[i+1]=RULE.val[i]; RULE.val[i]=t; renderRuleBody(); }));
    body.querySelectorAll('[data-rdel]').forEach(b=>b.addEventListener('click',()=>{
      RULE.val.splice(+b.dataset.rdel,1); renderRuleBody(); }));
    body.querySelectorAll('[data-chipdel]').forEach(b=>b.addEventListener('click',()=>{
      const p=b.dataset.chipdel.split('|'); RULE.val[+p[0]].splice(+p[1],1); renderRuleBody(); }));
    body.querySelectorAll('[data-chipadd]').forEach(el=>el.addEventListener('keydown',e=>{
      if(e.key!=='Enter') return; e.preventDefault();
      const i=+el.dataset.chipadd, v=el.value.trim(); if(!v) return;
      /* duplicate prevention: satu unit tidak boleh muncul dua kali dalam satu blok */
      if(RULE.val[i].map(String).indexOf(v)>=0){ ruleMsg('Unit "'+v+'" sudah ada pada baris ini.',true); return; }
      RULE.val[i].push(v); renderRuleBody(); }));
    body.querySelectorAll('[data-rk]').forEach(el=>el.addEventListener('change',()=>{
      const p=el.dataset.rk.split('|'); const i=+p[0], k=p.slice(1).join('|');
      const old=RULE.val[i][k];
      let v=el.value;
      if(typeof old==='number'){ const n=Number(v);
        if(v!==''&&isNaN(n)){ el.classList.add('bad'); ruleMsg('Nilai "'+k+'" harus numerik.',true); return; }
        el.classList.remove('bad'); v=(v===''?'':n); }
      else if(typeof old==='boolean'){ v=(v==='true'||v==='1'); }
      RULE.val[i][k]=v; ruleMsg(''); }));
    body.querySelectorAll('[data-rv]').forEach(el=>el.addEventListener('change',()=>{
      RULE.val[+el.dataset.rv]=el.value; }));
  }
  function ruleMsg(t,err){ const e=$('wk-rule-msg'); if(!e) return;
    e.textContent=t||''; e.style.color=err?'var(--bad)':'var(--ok)'; }
  function openRuleEditor(di,f){
    if(!f||!f.isArray) return;
    const inp=ensureInput(di); if(!inp){ msg('Tanggal ini belum punya input.',true); return; }
    const cur=cellGet(inp,f);
    RULE={di:di,f:f,val:Array.isArray(cur)?deep(cur):[],adv:false,tmpl:null};
    RULE.tmpl=RULE.val.length?deep(RULE.val[0]):ruleTemplate(f);
    $('wk-rule-title').textContent=f.label+' — '+WK.days[di].date;
    $('wk-rule-path').textContent='data3.modeling.'+f.path;
    const sel=$('wk-rule-copy'); sel.innerHTML='<option value="">Copy rule ke tanggal…</option>'+
      WK.days.map((d,i)=>i===di?'':'<option value="'+i+'">'+d.date+'</option>').join('');
    ruleMsg('');
    renderRuleBody();
    $('wk-rule-overlay').style.display='block';
    $('wk-rule-panel').style.display='flex';
  }
  function closeRuleEditor(){ $('wk-rule-overlay').style.display='none'; $('wk-rule-panel').style.display='none'; RULE.f=null; }
  function ruleCollect(){
    if(RULE.adv){
      const ta=$('wk-rule-json'); if(!ta) return RULE.val;
      try{ const v=JSON.parse(ta.value); if(!Array.isArray(v)){ ruleMsg('JSON harus berupa array.',true); return null; }
           return v; }
      catch(e){ ruleMsg('JSON tidak valid: '+e.message,true); return null; }
    }
    return RULE.val;
  }
  function ruleApply(){
    const v=ruleCollect(); if(v===null) return;
    RULE.val=v;
    writeCellF(RULE.di,RULE.f,JSON.stringify(v));
    ruleMsg('Tersimpan ke '+WK.days[RULE.di].date+'.');
    msg(RULE.f.label+' diperbarui untuk '+WK.days[RULE.di].date+'.');
  }

  /* ================================ RESULT =========================================== */
  function summaryKeys(){
    const seen=[]; WK.days.forEach(d=>{ if(d.summary) Object.keys(d.summary).forEach(k=>{
      if(d.summary[k]&&typeof d.summary[k]==='object') return; if(seen.indexOf(k)<0) seen.push(k); }); });
    return seen;
  }
  function renderResultMatrix(){
    const keys=summaryKeys();
    let h='<thead><tr><th class="wk-corner">Parameter Summary</th>';
    WK.days.forEach((d,i)=>{ h+=dateTh(d,i,false); });
    h+='<th>Action</th></tr></thead><tbody>';
    h+='<tr><th class="wk-rowhdr">Status</th>';
    WK.days.forEach(d=>{ h+='<td style="text-align:center">'+badge(d.status,d.stale)+'</td>'; });
    h+='<td></td></tr><tr><th class="wk-rowhdr">Runtime (s)</th>';
    WK.days.forEach(d=>{ h+='<td style="text-align:right">'+(d.runtime?d.runtime.toFixed(1):'-')+'</td>'; });
    h+='<td></td></tr><tr><th class="wk-rowhdr">Hard violations</th>';
    WK.days.forEach(d=>{ h+='<td style="text-align:right">'+(d.result?d.violations.length:'-')+'</td>'; });
    h+='<td></td></tr>';
    keys.forEach(k=>{
      h+='<tr><th class="wk-rowhdr" title="'+esc(k)+'">'+esc(k)+'</th>';
      WK.days.forEach(d=>{ const v=d.summary?d.summary[k]:undefined;
        h+='<td style="text-align:right">'+(v===undefined||v===null||v===''?'-':(typeof v==='number'?fmt(v,4):esc(v)))+'</td>'; });
      h+='<td></td></tr>';
    });
    h+='<tr><th class="wk-rowhdr">Simulation Data</th>';
    WK.days.forEach((d,i)=>{ h+='<td style="text-align:center">'+(d.result?
        ('<a href="#" class="wk-detail-link" data-di="'+i+'">View Simulation Data</a>'):'<span style="color:var(--ink-dim)">-</span>')+'</td>'; });
    h+='<td></td></tr>';
    /* §5.11 aksi per tanggal: Full Screen · Image Full · Image Partial · Certificate (VALID-INFEASIBLE) */
    h+='<tr><th class="wk-rowhdr">Actions</th>';
    WK.days.forEach((d,i)=>{
      if(!d.result){ h+='<td style="text-align:center;color:var(--ink-dim)">-</td>'; return; }
      const cert=(d.status==='VALID-INFEASIBLE'||d.certificate)
        ? '<button type="button" class="btn link" data-act="cert" data-di="'+i+'">Certificate</button>' : '';
      h+='<td style="text-align:center"><span style="display:inline-flex;gap:2px;flex-wrap:wrap;justify-content:center">'+
         '<button type="button" class="btn link" data-act="full" data-di="'+i+'">Full Screen</button>'+
         '<button type="button" class="btn link" data-act="imgfull" data-di="'+i+'">Image Full</button>'+
         '<button type="button" class="btn link" data-act="imgpart" data-di="'+i+'">Image Partial</button>'+
         cert+'</span></td>';
    });
    h+='<td></td></tr></tbody>';
    $('wk-result-table').innerHTML=h;
    $('wk-result-table').querySelectorAll('th.wk-datecol').forEach(th=>
      th.addEventListener('click',()=>{WK.cur=+th.dataset.di;renderAll();}));
    $('wk-result-table').querySelectorAll('a.wk-detail-link').forEach(a=>
      a.addEventListener('click',e=>{e.preventDefault();openDetail(+a.dataset.di);}));
    $('wk-result-table').querySelectorAll('button[data-act]').forEach(b=>
      b.addEventListener('click',()=>{
        const i=+b.dataset.di; openDetail(i);
        const a=b.dataset.act;
        if(a==='full'){ const p=$('wk-detail-panel'); p.dataset.full='1'; p.style.inset='0'; }
        else if(a==='imgfull')  detailImage('full');
        else if(a==='imgpart')  detailImage('partial');
        else if(a==='cert')     showCertificate();
      }));
    const LEG=['PASS','VALID-INFEASIBLE','SEARCH_EXHAUSTED','FAIL','ERROR','RUNNING','STALE','DRAFT','NOT RUN'];
    $('wk-status-legend').innerHTML='<b>Status:</b> '+LEG.map(x=>badge(x,false)).join(' ');
    renderWeeklySummary();
  }
  function renderWeeklySummary(){
    let net=0,cost=0,gas=0,lng=0,pep=0,pipe=0,dist=0,su=0,so=0;
    const cnt={'PASS':0,'VALID-INFEASIBLE':0,'FAIL':0,'STALE':0,'NOT RUN':0,'SEARCH_EXHAUSTED':0,'ERROR':0,'DRAFT':0};
    WK.days.forEach(d=>{
      const lab=(d.stale&&d.status!=='NOT RUN'&&d.status!=='DRAFT')?'STALE':d.status;
      if(cnt[lab]!==undefined) cnt[lab]++;
      if(!isValidDay(d)) return;
      const s=d.summary||{};
      net +=Number(s['Net Production (MWh)']||0);   cost+=Number(s['Total Cost (USD)']||0);
      gas +=Number(s['Total Gas Used (BBTUD)']||0); lng +=Number(s['LNG Used (BBTUD)']||0);
      pep +=Number(s['PEP Used (BBTUD)']||0);       pipe+=Number(s['PGN Pipe Used (BBTUD)']||0);
      dist+=Number(s['Distillate Used (kL)']||0);
      su  +=Number(s['Startup Events']||0);         so  +=Number(s['Stop Events']||0);
    });
    const wcp = net>0 ? (cost/net) : 0;
    $('wk-weekly-summary').innerHTML=
      '<div style="display:flex;align-items:baseline;gap:10px;margin-bottom:8px">'+
      '<b style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:var(--accent)">Weekly Summary</b>'+
      '<span style="color:var(--ink-dim);font-size:11.5px">hanya hari PASS &amp; tidak STALE yang diagregasi</span></div>'+
      '<div class="wk-mwrap" style="max-height:none"><table class="wk-matrix" style="width:100%"><tbody>'+
      '<tr><th class="wk-rowhdr">Total Net Production</th><td style="text-align:right">'+fmt(net)+' MWh</td>'+
      '<th class="wk-rowhdr">Total Production Cost</th><td style="text-align:right">'+fmt(cost)+' USD</td></tr>'+
      '<tr><th class="wk-rowhdr">Total Gas</th><td style="text-align:right">'+fmt(gas,4)+' BBTUD</td>'+
      '<th class="wk-rowhdr">Weighted Cost Production</th><td style="text-align:right"><b>'+fmt(wcp,4)+' USD/MWh</b></td></tr>'+
      '<tr><th class="wk-rowhdr">Total PGN Pipe</th><td style="text-align:right">'+fmt(pipe,4)+'</td>'+
      '<th class="wk-rowhdr">Total LNG</th><td style="text-align:right">'+fmt(lng,4)+'</td></tr>'+
      '<tr><th class="wk-rowhdr">Total PEP</th><td style="text-align:right">'+fmt(pep,4)+'</td>'+
      '<th class="wk-rowhdr">Total Distillate</th><td style="text-align:right">'+fmt(dist,2)+' kL</td></tr>'+
      '<tr><th class="wk-rowhdr">Total startup</th><td style="text-align:right">'+su+'</td>'+
      '<th class="wk-rowhdr">Total stop</th><td style="text-align:right">'+so+'</td></tr>'+
      '<tr><th class="wk-rowhdr">Hari PASS</th><td style="text-align:right">'+cnt['PASS']+'</td>'+
      '<th class="wk-rowhdr">Hari VALID-INFEASIBLE</th><td style="text-align:right">'+cnt['VALID-INFEASIBLE']+'</td></tr>'+
      '<tr><th class="wk-rowhdr">Hari FAIL</th><td style="text-align:right">'+cnt['FAIL']+'</td>'+
      '<th class="wk-rowhdr">Hari SEARCH_EXHAUSTED</th><td style="text-align:right">'+cnt['SEARCH_EXHAUSTED']+'</td></tr>'+
      '<tr><th class="wk-rowhdr">Hari STALE</th><td style="text-align:right">'+cnt['STALE']+'</td>'+
      '<th class="wk-rowhdr">Hari NOT RUN</th><td style="text-align:right">'+cnt['NOT RUN']+'</td></tr>'+
      '</tbody></table></div>';
  }

  /* ============================ DETAIL (renderer Daily) ============================== */
  const SIM_COLS=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','GE1','GE2','GE3','GE4',
                  'S1','S2','S3','BB1','BB2','IE','Export_PLN','Spinning_Reserve','Bus_Flow'];
  function openDetail(i){
    const d=WK.days[i]; if(!d.result) return;
    WK.detail=i;
    $('wk-detail-title').textContent='Weekly Day '+(i+1)+' \u2014 Simulation Data '+d.date;
    $('wk-detail-status').innerHTML='<span>Tanggal <b>'+esc(d.date)+'</b></span>'+badge(d.status,d.stale)+
      (d.runtime?('<span>runtime '+d.runtime.toFixed(1)+' s</span>'):'')+
      '<span>violations '+d.violations.length+'</span>';
    const body=$('wk-detail-body');
    /* jalur normal: RENDERER BERSAMA Daily (ppBuildSimTable via window.renderSimDataInto) */
    if(typeof window.renderSimDataInto==='function'){ window.renderSimDataInto(body,d.result,d.date,d.summary||{}); }
    else{
      const cols=SIM_COLS.filter(c=>d.result.some(r=>r[c]!==undefined));
      let h='<table class="wk-matrix" data-wk-simtable="1" data-wk-date="'+esc(d.date)+'"><thead><tr><th class="wk-corner">TIME</th>'+
            cols.map(c=>'<th>'+c+'</th>').join('')+'</tr></thead><tbody>';
      d.result.forEach(r=>{ h+='<tr><th class="wk-rowhdr">'+esc(r.Time||r.TIME||'')+'</th>'+
          cols.map(c=>'<td style="text-align:right">'+(r[c]===undefined?'-':fmt(r[c],2))+'</td>').join('')+'</tr>'; });
      body.innerHTML=h+'</tbody></table>';
      body.setAttribute('data-wk-date',d.date);
    }
    $('wk-detail-overlay').style.display='block'; $('wk-detail-panel').style.display='flex';
    $('wk-detail-cert').style.display=(d.status==='VALID-INFEASIBLE'||d.certificate)?'':'none';
  }
  function closeDetail(){ $('wk-detail-overlay').style.display='none'; $('wk-detail-panel').style.display='none'; WK.detail=-1; }
  function detailStep(n){ let i=WK.detail+n; while(i>=0&&i<7&&!WK.days[i].result) i+=n; if(i>=0&&i<7) openDetail(i); }
  function detailImage(mode){
    const d=WK.days[WK.detail]; if(!d) return '';
    const fname='weekly_'+d.date+'_simulation_'+mode+'.png';
    if(typeof window.downloadSimImage==='function'){ window.downloadSimImage(mode,fname,d.result,d.date); }
    else msg('Image '+mode+' disiapkan: '+fname);
    return fname;
  }
  function showCertificate(){
    const d=WK.days[WK.detail]; if(!d) return;
    $('wk-detail-body').innerHTML='<pre style="white-space:pre-wrap;font-family:var(--mono);font-size:12px;'+
      'background:var(--field);border:1px solid var(--card-line);border-radius:10px;padding:14px;margin:0">'+
      esc(JSON.stringify(d.certificate||{status:d.status,violations:d.violations},null,2))+'</pre>';
  }

  /* ================================ RENDER ALL ======================================= */
  function renderAll(){
    $('wk-pane-periodic').style.display=(WK.tab==='periodic')?'':'none';
    $('wk-pane-frequent').style.display=(WK.tab==='frequent')?'':'none';
    $('wk-pane-result').style.display=(WK.tab==='result')?'':'none';
    document.querySelectorAll('#wk-maintabs .wk-tab').forEach(b=>
      b.classList.toggle('active',b.dataset.wktab===WK.tab));
    if(WK.tab==='periodic'){ for(let i=0;i<7;i++) ensureInput(i); renderPeriodic(); }
    if(WK.tab==='frequent') renderFrequent();
    if(WK.tab==='result')   renderResultMatrix();
    renderPhase();
  }
  function msg(t,err){ const el=$('wk-msg');
    el.innerHTML=err?('<span style="color:var(--bad)">'+esc(t)+'</span>'):('<span style="color:#0a7b6b">'+esc(t)+'</span>'); }
  function markDirtyFrom(idx){
    WK.dirty=true; WK.revision++;
    for(let i=idx+1;i<7;i++){ if(WK.days[i].result) WK.days[i].stale=true; }
    $('wk-unsaved').style.display='inline-flex';
    renderAll();
  }
  function captureLive(quiet,keepReport){
    if(typeof assembleInput!=='function'){ if(!quiet) msg('assembleInput() tidak tersedia',true); return null; }
    let p; try{ p=assembleInput(); }catch(e){ if(!quiet) msg('Gagal membaca input live: '+e.message,true); return null; }
    p=deep(p);
    const m=p.data3&&p.data3.modeling?p.data3.modeling:null;
    if(m){ delete m.monitoring_daily_plan; delete m.actual_data; delete m.locked_rows; delete m['time'+'_passed'];
           m.actual_pgn_total=[]; m.actual_energy_jababeka=[]; m.actual_energy_mm2100=[]; m.plan_type='weekly_plan_day';
           /* arsip report_planning berisi rekaman plan/monitoring LAIN. Payload harian Weekly tidak
            * memerlukannya: dibuang supaya draft tidak membengkak. Save tetap memakai salinan lengkap. */
           if(!keepReport) delete m.report_planning;
           /* NORMALISASI KONTRAK STORAGE: collector Daily dapat mengeluarkan {} untuk container
            * kosong, sedangkan storage PHP mengembalikan []. Perbedaan tipe itu membuat
            * round-trip Save->reload tidak identik. Container ber-tipe ARRAY pada payload
            * dinormalkan menjadi [] saat kosong; isinya TIDAK diubah bila sudah terisi. */
           ['unit_fix_load','unit_skip_load','unit_stop','unit_stop_time','unit_cannot_stop',
            'required_units','max_load_rules','hrsg_stop','hrsg_stop_time','stop_mode',
            'required_mode','ie_adjustments','manual_fixed_flows'].forEach(k=>{
             const v=m[k];
             if(v && typeof v==='object' && !Array.isArray(v) && Object.keys(v).length===0) m[k]=[];
           }); }
    return p;
  }

  /* ============================== RUN WORKFLOW ======================================= */
  let RUN_TOKEN=0;
  const BOUNDARY_OK=['PASS'];
  async function runDay(i){
    const d=WK.days[i];
    if(!d.input) d.input=captureLive();
    if(!d.input) return false;
    const prev=i>0?WK.days[i-1]:null;
    if(WK.mode==='sequential' && prev && i>0 && !(BOUNDARY_OK.indexOf(prev.status)>=0 && !prev.stale)){
      msg('Hari '+i+' berstatus '+prev.status+' \u2014 tidak menghasilkan boundary yang sah; sequential run dihentikan.',true);
      return false; }
    const boundary=(WK.mode==='sequential'&&prev&&isValidDay(prev))?prev.boundary:null;
    const payload=deep(d.input);
    payload.weekly={weekly_plan_id:WK.id,weekly_plan_name:WK.name,week_start_date:WK.start,
                    week_end_date:addDays(WK.start,6),day_date:d.date,day_index:i,
                    run_mode:WK.mode,boundary_in:boundary?Object.assign({},boundary,{status:'PASS'}):null};
    payload._request_id='wk-'+i+'-'+Date.now().toString(36);
    payload._state_revision=++WK.revision;
    const myToken=++RUN_TOKEN;
    d.status='RUNNING'; renderAll();
    $('wk-progress').textContent=(i+1)+'/7';
    const t0=performance.now();
    try{
      const res=await fetch('run.php?mode=run_weekly_day',{method:'POST',
            headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      const raw=await res.text(); let data=null;
      try{ data=JSON.parse(raw.replace(/^\uFEFF/,'')); }catch(e){
        d.status='ERROR'; msg('Response bukan JSON valid (HTTP '+res.status+')',true); renderAll(); return false; }
      if(myToken!==RUN_TOKEN) return false;
      if(data._state_revision!=null && data._state_revision!==WK.revision) return false;
      /* ok=false pada endpoint berarti hari TIDAK lolos hard constraint — itu outcome FAIL /
       * VALID-INFEASIBLE / SEARCH_EXHAUSTED yang SAH dan tetap membawa 48 row + Summary lengkap.
       * ERROR hanya untuk kegagalan nyata (tanpa dispatch): transport, non-JSON, atau engine crash. */
      const hasResult = Array.isArray(data.data) && data.data.length > 0;
      if(data.ok===false && !hasResult){
        d.status='ERROR'; d.violations=(data.validation&&data.validation.violations)||[];
        msg('Hari '+d.date+' error: '+((data.error&&data.error.message)||data.message||'unknown'),true);
        renderAll(); return false; }
      d.result=data.data||[]; d.summary=data.summary||{}; d.boundary=data.boundary_out||null;
      d.violations=(data.validation&&data.validation.violations)||[];
      d.certificate=data.certificate||null;
      const oc=data.outcome||data.status;
      d.status=(oc==='PASS'&&d.violations.length===0)?'PASS':
               (oc==='VALID-INFEASIBLE'?'VALID-INFEASIBLE':
               (oc==='SEARCH_EXHAUSTED'?'SEARCH_EXHAUSTED':'FAIL'));
      d.stale=false; d.revision=data.revision||''; d.runtime=(performance.now()-t0)/1000;
      renderAll();
      return d.status==='PASS';
    }catch(err){
      d.status='ERROR'; msg('Request gagal: '+err.message,true); renderAll(); return false;
    }finally{ if(!WK.running) renderPhase(); }
  }
  async function runAll(){
    WK.running=true; WK.cancel=false; $('wk-cancel').disabled=false;
    try{
      for(let i=0;i<7;i++){
        if(WK.cancel){ msg('Weekly run dibatalkan pada '+(i+1)+'/7 \u2014 hari yang sudah selesai TIDAK dihapus.'); break; }
        WK.cur=i; renderAll();
        const ok=await runDay(i);
        if(!ok && WK.mode==='sequential'){
          msg('Sequential run berhenti pada hari '+(i+1)+' ('+WK.days[i].status+') \u2014 tidak menghasilkan boundary sah.',true); break; }
      }
    } finally { WK.running=false; WK.cancel=false; $('wk-cancel').disabled=true; renderPhase(); }
  }
  /* ---- SNAPSHOT MURNI PER TANGGAL (tidak bergantung tab aktif, tidak memutasi WK) ---- */
  function buildWeeklyReportSnapshot(i){
    const d=WK.days[i]; if(!d||!d.input) return null;
    const inp=deep(d.input);                                  /* deep clone */
    const m=inp.data3&&inp.data3.modeling?inp.data3.modeling:null;
    if(m){ delete m.report_planning;                          /* cegah rekursi */
           delete m.monitoring_daily_plan; delete m.actual_data;
           delete m.locked_rows; delete m['time'+'_passed'];
           m.plan_type='weekly_plan_day'; }
    return {plan_date:d.date, name_plan:'Daily Plan '+d.date, plan_type:'weekly_plan_day',
            weekly_plan_id:WK.id, weekly_plan_name:WK.name, week_start_date:WK.start,
            week_end_date:addDays(WK.start,6), day_date:d.date, day_index:i,
            status:d.status, stale:!!d.stale, revision:d.revision||'', source:'weekly_plan', editable:true,
            boundary_in:null, boundary_out:d.boundary||null,
            certificate:d.certificate||null, updated_at:new Date().toISOString(),
            input:inp, output:d.result?{data:deep(d.result), info:deep(d.summary||{}),
                                        certificate:d.certificate?deep(d.certificate):null}:null};
  }
  /* identity stabil: plan_type + weekly_plan_id + day_date (urutan array tidak menentukan) */
  function weeklyReportIdentity(r){
    return String(r&&r.plan_type||'')+'|'+String(r&&r.weekly_plan_id||'')+'|'+String(r&&(r.day_date||r.plan_date)||'');
  }
  /* validasi snapshot; kembalikan null bila lengkap, atau alasan bila tidak */
  function validateWeeklySnapshot(r){
    const bad=[];
    const m=r&&r.input&&r.input.data3?r.input.data3.modeling:null;
    if(!r||!r.input||typeof r.input!=='object') bad.push('input');
    if(!r||!r.input||!r.input.data3) bad.push('data3');
    if(!m||typeof m!=='object') bad.push('data3.modeling');
    else{
      if(Object.keys(m).length<20) bad.push('modeling terlalu sedikit ('+Object.keys(m).length+')');
      if(m.spinning_reserve_min===undefined) bad.push('spinning_reserve_min');
      if(m.busflow_min===undefined) bad.push('busflow_min');
    }
    if(!Array.isArray(r&&r.input&&r.input.data1)||r.input.data1.length!==48) bad.push('IE 48 row');
    if(!Array.isArray(r&&r.input&&r.input.data2)||r.input.data2.length!==48) bad.push('Dispatch 48 row');
    if(!r||!r.status) bad.push('status');
    if(!r||r.plan_type!=='weekly_plan_day') bad.push('plan_type');
    if(!r||!r.weekly_plan_id) bad.push('weekly_plan_id');
    if(!r||!r.day_date) bad.push('day_date');
    if(r&&r.status==='VALID-INFEASIBLE'&&!r.certificate) bad.push('certificate (VALID-INFEASIBLE)');
    return bad.length?('INVALID_WEEKLY_REPORT_SNAPSHOT: '+bad.join(', ')):null;
  }
  /* upsert atomik & idempoten; created_at lama dipertahankan */
  function upsertWeeklyReportRecord(list,inc){
    const id=weeklyReportIdentity(inc);
    const k=list.findIndex(x=>x&&weeklyReportIdentity(x)===id);
    if(k>=0){ inc.created_at=list[k].created_at||list[k].updated_at||inc.updated_at; list[k]=inc; }
    else { inc.created_at=inc.updated_at; list.push(inc); }
    return list;
  }
  async function saveToReport(which){
    /* Record Report yang sudah tersimpan adalah IMMUTABLE SNAPSHOT: base list dibaca dari
     * STORAGE, bukan dari hasil captureLive()/assembleInput(). */
    const incoming=[]; const rejected=[];
    WK.days.forEach((d,i)=>{
      if(which==='done' && !isCompletedFinal(d)) return;
      if(which!=='draft' && !d.input) return;
      const snap=buildWeeklyReportSnapshot(i); if(!snap) return;
      const why=validateWeeklySnapshot(snap);
      if(why){ rejected.push(d.date+': '+why); return; }
      incoming.push(snap);
    });
    if(which!=='draft' && !incoming.length){
      msg('Tidak ada hari yang memenuhi syarat untuk disimpan.'+(rejected.length?(' Ditolak: '+rejected.join(' | ')):''),true); return; }
    if(rejected.length) msg('Sebagian hari ditolak — '+rejected.join(' | '),true);

    const payload=captureLive(false,true); if(!payload) return;
    /* base list dari STORAGE (immutable), fallback ke payload bila fetch gagal */
    let list=[];
    try{
      const res=await fetch('input_data.json?ts='+Date.now(),{cache:'no-store'});
      const disk=await res.json();
      const drp=(((disk||{}).data3||{}).modeling||{}).report_planning||{};
      if(Array.isArray(drp.records)) list=drp.records.slice();
    }catch(e){ list=[]; }
    if(!list.length){
      const rp0=payload.data3.modeling.report_planning||{};
      if(Array.isArray(rp0.records)) list=rp0.records.slice();
    }
    incoming.forEach(r=>upsertWeeklyReportRecord(list,r));
    const rp=payload.data3.modeling.report_planning||{};
    rp.records=list; payload.data3.modeling.report_planning=rp;
    if(which==='draft') payload.data3.modeling.weekly_draft={id:WK.id,name:WK.name,start:WK.start,mode:WK.mode,
                          days:WK.days.map(d=>({date:d.date,status:d.status,stale:d.stale,input:d.input}))};
    try{
      const res=await fetch('run.php?mode=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      const raw=await res.text(); let data=null; try{ data=JSON.parse(raw); }catch(e){ msg('Save: response bukan JSON',true); return; }
      if(data.ok===false){ msg('Save gagal: '+((data.error&&data.error.message)||data.message||'unknown'),true); return; }
      WK.dirty=false; $('wk-unsaved').style.display='none';
      msg((which==='draft'?'Weekly Draft':'Report Planning')+' tersimpan: '+(which==='draft'?WK.days.length:incoming.length)+' record.');
    }catch(e){ msg('Save gagal: '+e.message,true); }
  }

  /* ============================== COPY / BULK EDIT =================================== */
  /* baris yang sedang tampak, mengikuti tab aktif -> aksi Copy/Fill Right berlaku untuk Periodic MAUPUN Frequently */
  function activeRows(){ const list=WK.tab==='periodic'
    ? SCHEMA.periodic.filter(f=>f.group===WK.psub)
    : SCHEMA.frequent.filter(f=>f.group===WK.fsub);
    const q=WK.tab==='periodic'?WK.search.p:WK.search.f;
    const qu=WK.tab==='periodic'?WK.search.pu:WK.search.fu;
    return list.filter(f=>matches(f,q,qu)); }
  function markCopied(i){ activeRows().forEach(f=>{ WK.copied[i+'|'+f.path+'|'+(f.srcUnit||'')]=1; }); }
  function copyDay(from,to){ const src=WK.days[from].input; if(!src){msg('Hari sumber belum punya input.',true);return false;}
    WK.days[to].input=deep(src);
    if(WK.days[to].status==='NOT RUN') WK.days[to].status='DRAFT';
    markCopied(to); return true; }
  function fillRows(rows,label){
    const src=ensureInput(WK.cur); if(!src){msg('Hari aktif belum punya input.',true);return;}
    for(let i=WK.cur+1;i<7;i++){ const dst=ensureInput(i); if(!dst) continue;
      rows.forEach(f=>{ const v=cellGet(src,f);
        cellSet(dst,f,(v&&typeof v==='object')?deep(v):v); WK.copied[i+'|'+f.path+'|'+(f.srcUnit||'')]=1; });
      if(WK.days[i].status==='NOT RUN') WK.days[i].status='DRAFT'; }
    markDirtyFrom(WK.cur); msg(label);
  }
  function fillRight(){ fillRows(activeRows(),'Fill Right selesai untuk subtab aktif (deep copy).'); }
  function fillRightRow(f){ if(!f) return; fillRows([f],'Fill Right: '+f.label+' disalin ke hari berikutnya.'); }

  /* ================================== WIRING ========================================= */
  function bind(id,fn){ const el=$(id); if(el) el.addEventListener('click',fn); }
  document.querySelectorAll('#wk-maintabs .wk-tab').forEach(b=>
    b.addEventListener('click',()=>{WK.tab=b.dataset.wktab;renderAll();}));
  $('wk-start').addEventListener('change',e=>{
    if(WK.dirty && !confirm('Ada draft Weekly yang belum disimpan. Ganti rentang tanggal?')){ e.target.value=WK.start; return; }
    WK.start=e.target.value||WK.start; WK.days=[]; rebuildDays(); });
  $('wk-mode').addEventListener('change',e=>{WK.mode=e.target.value;});

  /* pencarian parameter / unit */
  function wireSearch(id,key,render){ const el=$(id); if(!el) return;
    el.addEventListener('input',()=>{ WK.search[key]=el.value.trim(); render();
      const again=$(id); if(again){ again.value=WK.search[key]; again.focus(); } }); }
  wireSearch('wk-p-search','p',()=>renderPeriodic());
  wireSearch('wk-p-search-unit','pu',()=>renderPeriodic());
  wireSearch('wk-f-search','f',()=>renderFrequent());
  wireSearch('wk-f-search-unit','fu',()=>renderFrequent());

  /* expand / collapse semua kategori pada subtab aktif */
  function setAllCollapsed(on){
    const list=WK.tab==='periodic'?SCHEMA.periodic.filter(f=>f.group===WK.psub)
                                  :SCHEMA.frequent.filter(f=>f.group===WK.fsub);
    list.forEach(f=>{ WK.collapsed[catOf(f)]=on; });
    renderAll();
  }
  bind('wk-expand',()=>setAllCollapsed(false));
  bind('wk-collapse',()=>setAllCollapsed(true));
  bind('wk-p-expand',()=>setAllCollapsed(false));
  bind('wk-p-collapse',()=>setAllCollapsed(true));

  bind('wk-capture',()=>{ const p=captureLive(); if(p){ WK.days[WK.cur].input=p; WK.days[WK.cur].status='DRAFT';
    markDirtyFrom(WK.cur); msg('Input live tersimpan untuk '+WK.days[WK.cur].date+'.'); } });
  bind('wk-copy-prev',()=>{ if(WK.cur===0){msg('Tidak ada hari sebelumnya.',true);return;}
    if(copyDay(WK.cur-1,WK.cur)){ markDirtyFrom(WK.cur); msg('Copy Previous Day selesai (deep copy).'); } });
  bind('wk-copy-next',()=>{ if(WK.cur===6){msg('Tidak ada hari berikutnya.',true);return;}
    if(copyDay(WK.cur,WK.cur+1)){ markDirtyFrom(WK.cur); msg('Copy to Next Day selesai (deep copy).'); } });
  bind('wk-copy-all',()=>{ let n=0; for(let i=WK.cur+1;i<7;i++){ if(copyDay(WK.cur,i)) n++; }
    if(n){ markDirtyFrom(WK.cur); msg('Copy to All Remaining Days selesai ('+n+' hari, deep copy).'); } });

  /* IMPORT CSV per TANGGAL AKTIF (§5.8): baca -> validasi -> preview -> Apply.
   * Nilai ditulis ke data1[i].value dan data2[i].dispatch milik HARI AKTIF saja (bukan global). */
  bind('wk-csv',()=>{ const f=$('wk-csv-file'); if(f) f.click(); });
  $('wk-csv').addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); $('wk-csv-file').click(); } });
  ['dragenter','dragover'].forEach(ev=>$('wk-csv').addEventListener(ev,e=>{
    e.preventDefault(); $('wk-csv').classList.add('over'); }));
  ['dragleave','drop'].forEach(ev=>$('wk-csv').addEventListener(ev,e=>{
    e.preventDefault(); $('wk-csv').classList.remove('over'); }));
  $('wk-csv').addEventListener('drop',e=>{
    const file=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0]; if(file) readCsvFile(file); });
  function readCsvFile(file){
    const rd=new FileReader();
    rd.onload=()=>{
      try{ WK.csv=parseCsv(rd.result,file.name); renderCsvPanel(); $('wk-csv-previewbox').innerHTML=''; }
      catch(e){ msg('CSV gagal dibaca: '+e.message,true); }
    };
    rd.readAsText(file);
  }
  (function(){ const f=$('wk-csv-file'); if(!f) return;
    f.addEventListener('change',ev=>{
      const file=ev.target.files&&ev.target.files[0];
      if(file) readCsvFile(file);
      ev.target.value='';
    });
  })();
  bind('wk-csv-apply',csvApply);
  bind('wk-csv-preview',csvPreview);
  bind('wk-csv-clear',()=>{ WK.csv=null; renderCsvPanel(); });

  /* panel 48 slot */
  $('wk-slot-mode').querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
    WK.slotKind=b.dataset.slot;
    $('wk-slot-mode').querySelectorAll('button').forEach(x=>x.classList.toggle('on',x===b));
    renderSlotPanel(); }));
  bind('wk-slot-reset',()=>{
    const base=captureLive(true); if(!base){ msg('Tidak dapat membaca baseline live.',true); return; }
    const inp=ensureInput(WK.cur); if(!inp) return;
    if(WK.slotKind==='ie') inp.data1=deep(base.data1||[]); else inp.data2=deep(base.data2||[]);
    markDirtyFrom(WK.cur); msg('Deret 48 slot dikembalikan ke baseline live.'); });

  bind('wk-fill-right',fillRight);
  bind('wk-p-fill-right',fillRight);
  bind('wk-p-copy-prev',()=>{ if(WK.cur===0){msg('Tidak ada hari sebelumnya.',true);return;}
    if(copyDay(WK.cur-1,WK.cur)){ markDirtyFrom(WK.cur); msg('Copy Previous Day selesai (deep copy).'); } });
  bind('wk-p-copy-all',()=>{ let n=0; for(let i=1;i<7;i++){ if(copyDay(0,i)) n++; }
    if(n){ markDirtyFrom(0); msg('Copy Day 1 to All selesai ('+n+' hari, deep copy).'); } });
  function resetSection(){ const rows=activeRows(); const base=captureLive(true);
    if(!base){msg('Tidak dapat membaca baseline live.',true);return;}
    WK.days.forEach(d=>{ if(!d.input) return; rows.forEach(f=>{
      const v=cellGet(base,f); cellSet(d.input,f,(v&&typeof v==='object')?deep(v):v); }); });
    markDirtyFrom(0); msg('Reset Section selesai.'); }
  bind('wk-p-reset-sub',resetSection);
  bind('wk-reset-sub',resetSection);
  function resetDay(){ WK.days[WK.cur]=blankDay(WK.days[WK.cur].date); markDirtyFrom(WK.cur); msg('Reset Day selesai.'); }
  bind('wk-reset-day',resetDay);
  bind('wk-p-reset-day',resetDay);
  bind('wk-reset-draft',()=>{ if(!confirm('Reset seluruh Weekly Draft (tujuh hari)?')) return;
    WK.days=[]; WK.copied={}; WK.csv=null; rebuildDays(); WK.dirty=true; msg('Weekly Draft direset.'); });
  bind('wk-run-day',async()=>{ await runDay(WK.cur); });
  bind('wk-run-all',async()=>{ await runAll(); });
  bind('wk-cancel',()=>{ WK.cancel=true; });
  bind('wk-save-draft',()=>saveToReport('draft'));
  bind('wk-save-done',()=>saveToReport('done'));
  bind('wk-save-all',()=>saveToReport('all'));
  bind('wk-r-save-done',()=>saveToReport('done'));
  bind('wk-r-save-all',()=>saveToReport('all'));

  /* editor rule */
  bind('wk-rule-close',closeRuleEditor);
  bind('wk-rule-apply',ruleApply);
  bind('wk-rule-adv',()=>{ const v=ruleCollect(); if(v===null) return; RULE.val=v; RULE.adv=!RULE.adv; renderRuleBody(); });
  bind('wk-rule-add',()=>{ RULE.val.push(blankFrom(RULE.tmpl)); renderRuleBody(); });
  bind('wk-rule-copybtn',()=>{
    const sel=$('wk-rule-copy'); const to=sel.value; if(to===''){ ruleMsg('Pilih tanggal tujuan.',true); return; }
    const v=ruleCollect(); if(v===null) return; RULE.val=v;
    const dst=ensureInput(+to); if(!dst){ ruleMsg('Tanggal tujuan belum punya input.',true); return; }
    cellSet(dst,RULE.f,deep(v));
    if(WK.days[+to].status==='NOT RUN') WK.days[+to].status='DRAFT';
    markDirtyFrom(Math.min(RULE.di,+to));
    ruleMsg('Disalin ke '+WK.days[+to].date+'.'); });
  $('wk-rule-overlay').addEventListener('click',closeRuleEditor);

  bind('wk-detail-close',closeDetail);
  bind('wk-detail-prev',()=>detailStep(-1));
  bind('wk-detail-next',()=>detailStep(1));
  bind('wk-detail-full',()=>{ const p=$('wk-detail-panel'); p.dataset.full=(p.dataset.full==='1'?'':'1');
    p.style.inset=(p.dataset.full==='1'?'0':'24px'); });
  bind('wk-detail-imgfull',()=>detailImage('full'));
  bind('wk-detail-imgpart',()=>detailImage('partial'));
  bind('wk-detail-cert',showCertificate);
  $('wk-detail-overlay').addEventListener('click',closeDetail);
  document.addEventListener('keydown',e=>{
    if(e.key!=='Escape') return;
    if($('wk-rule-panel').style.display==='flex') closeRuleEditor();
    else if($('wk-detail-panel').style.display==='flex') closeDetail();
  });

  function mountIntoExistingPanel(){
    const host=document.getElementById('panel-weekly')||document.querySelector('[data-panel="weekly"]');
    if(!host) return false;
    const root=$('wk-panel-root'); if(root.parentNode!==host) host.appendChild(root);
    return true;
  }
  function restoreDraft(){
    try{
      let root=null;
      try { root = (typeof INPUT!=='undefined' && INPUT) ? INPUT : (window.INPUT||null); }
      catch(e){ root = window.INPUT||null; }
      const src=(root&&root.data3&&root.data3.modeling&&root.data3.modeling.weekly_draft)||null;
      if(!src||!src.start) return false;
      WK.start=src.start; WK.mode=src.mode||'sequential';
      WK.id=src.id||('WK-'+WK.start.replace(/-/g,'')); WK.name=src.name||WK.name;
      const byDate={}; (src.days||[]).forEach(d=>{ if(d&&d.date) byDate[d.date]=d; });
      WK.days=[]; for(let i=0;i<7;i++){
        const dt=addDays(WK.start,i); const sv=byDate[dt]; const day=blankDay(dt);
        if(sv){ day.input=sv.input||null; day.status=sv.status||(sv.input?'DRAFT':'NOT RUN'); day.stale=!!sv.stale; }
        WK.days.push(day);
      }
      $('wk-start').value=WK.start; $('wk-mode').value=WK.mode;
      return true;
    }catch(e){ return false; }
  }
  $('wk-start').value=WK.start;
  const restored=restoreDraft();
  mountIntoExistingPanel();
  rebuildDays();

  /* API untuk functional test (jsdom) */
  window.WEEKLY_PLAN_STATE=WK;
  window.WEEKLY_RESTORE_DRAFT=restoreDraft;
  window.WEEKLY_DRAFT_RESTORED=restored;
  window.WEEKLY_SCHEMA=()=>SCHEMA;
  window.WEEKLY_BUILD_SCHEMA=buildSchema;
  window.WEEKLY_SET_TAB=t=>{WK.tab=t;renderAll();};
  window.WEEKLY_SET_SUB=g=>{WK.fsub=g;renderFrequent();};
  window.WEEKLY_SET_PSUB=g=>{WK.psub=g;renderPeriodic();};
  window.WEEKLY_RENDER=renderAll;
  window.WEEKLY_OPEN_DETAIL=openDetail;
  window.WEEKLY_CLOSE_DETAIL=closeDetail;
  window.WEEKLY_DETAIL_IMAGE=detailImage;
  window.WEEKLY_FILL_RIGHT=fillRight;
  window.WEEKLY_COPY_DAY=copyDay;
  window.WEEKLY_WRITE_CELL=writeCell;
  window.WEEKLY_WRITE_CELL_F=writeCellF;
  window.WEEKLY_CELL_GET=cellGet;
  window.WEEKLY_ENSURE_INPUT=ensureInput;
  window.WEEKLY_IS_VALID_DAY=isValidDay;
  window.WEEKLY_SUMMARY_KEYS=summaryKeys;
  window.WEEKLY_SIM_COLS=SIM_COLS;
  window.WEEKLY_REBUILD=rebuildDays;
  /* API baru khusus UI redesign (tidak dipakai engine) */
  window.WEEKLY_OPEN_RULE=openRuleEditor;
  window.WEEKLY_CSV_PARSE=parseCsv;
  window.WEEKLY_SET_COLLAPSED=(cat,on)=>{WK.collapsed[cat]=!!on;renderAll();};
  window.WEEKLY_SET_SEARCH=(k,v)=>{WK.search[k]=v||'';renderAll();};
})();
</script>
