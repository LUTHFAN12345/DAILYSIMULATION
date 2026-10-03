<?php
/* =========================================================================
 *  index.php  —  Planning Pembangkit 17 Unit : operator dashboard
 *  Loads the current input/output and renders the control UI. The Daily Plan
 *  tab is fully interactive: edit inputs -> Run simulation -> results.
 * ========================================================================= */
$inputPath  = __DIR__ . '/input_data.json';
$outputPath = __DIR__ . '/output_data.json';
/* V12: berkas kerja dibaca lewat saved_data_store.php — bila hilang/rusak (mis. folder aplikasi diganti) dipulihkan dari
 * cermin data tersimpan (<data>/saved/state). index.php tidak menulis berkas penyimpanan apa pun selain lewat modul ini. */
if (is_file(__DIR__ . '/saved_data_store.php')) require_once __DIR__ . '/saved_data_store.php';
$INPUT  = function_exists('sds_load_state_input') ? sds_load_state_input($inputPath) : (file_exists($inputPath) ? json_decode(file_get_contents($inputPath), true) : null);
$OUTPUT = file_exists($outputPath) ? json_decode(file_get_contents($outputPath), true) : null;
$haveInput = is_array($INPUT) && isset($INPUT['data3']['modeling']);
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daily Plan Simulation Online</title>
<style>
  :root{
    /* light futuristic energy dashboard palette */
    --bg:#eef3fb; --bg-2:#e6edf8; --card:#ffffff; --card-line:#e3e9f4; --field:#f6f9fd; --field-line:#d8e1f0;
    --ink:#16233c; --ink-soft:#52617d; --ink-dim:#8593ad;
    --accent:#1763d6; --accent-2:#19b8e8; --accent-soft:#eaf3ff; --accent-line:#cfe2fb;
    --ok:#0fae8e; --ok-bg:#e3f8f2; --ok-line:#aee6d7;
    --warn:#e08a00; --warn-bg:#fdf2dc; --warn-line:#f4d79b;
    --bad:#e0454f; --bad-bg:#fde7e8; --bad-line:#f3b9bd;
    --busA:#e09a16; --busB:#2f6fd6;
    --shadow:0 1px 2px rgba(20,40,80,.04),0 6px 22px rgba(20,40,80,.06);
    --shadow-sm:0 1px 2px rgba(20,40,80,.05);
    --radius:14px; --radius-sm:10px;
    --mono:ui-monospace,"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;
    --sans:"Inter","Segoe UI",system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0;color:var(--ink);font-family:var(--sans);font-size:13px;line-height:1.5;
    background:
      radial-gradient(1100px 520px at 88% -8%,#dcebff,transparent 60%),
      radial-gradient(900px 480px at -6% 0%,#d7f4fb,transparent 55%),
      linear-gradient(180deg,var(--bg),var(--bg-2));
    background-attachment:fixed;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
  h1,h2,h3,h4,h5{margin:0;font-weight:700;letter-spacing:-.01em}
  a{color:var(--accent);text-decoration:none}
  .mono{font-family:var(--mono);font-variant-numeric:tabular-nums}

  /* ===== AdminLTE-like layout (light) ===== */
  :root{ --sb-w:248px; --nav-h:60px; --foot-h:42px; }
  .wrapper{min-height:100vh}

  /* top navbar */
  .main-header.navbar{position:fixed;top:0;left:0;right:0;height:var(--nav-h);z-index:40;
    display:flex;align-items:center;gap:16px;padding:0 22px 0 16px;
    background:rgba(255,255,255,.9);backdrop-filter:saturate(1.4) blur(12px);-webkit-backdrop-filter:saturate(1.4) blur(12px);
    border-bottom:1px solid var(--card-line);box-shadow:0 4px 18px rgba(20,40,80,.06)}
  .main-header.navbar::after{content:"";position:absolute;left:0;right:0;top:0;height:3px;
    background:linear-gradient(90deg,var(--accent),var(--accent-2) 55%,#5ad1c4)}
  .nav-toggle{appearance:none;border:1px solid var(--field-line);background:#fff;border-radius:9px;width:38px;height:38px;
    display:flex;flex-direction:column;justify-content:center;align-items:center;gap:3px;cursor:pointer;box-shadow:var(--shadow-sm);transition:all .15s}
  .nav-toggle:hover{background:var(--accent-soft);border-color:var(--accent-line)}
  .nav-toggle span{display:block;width:17px;height:2px;border-radius:2px;background:var(--accent)}
  .navbar-brand{display:flex;flex-direction:column;gap:1px;margin-right:auto}
  .navbar-brand .k{font-size:10px;letter-spacing:2.4px;text-transform:uppercase;color:var(--accent);font-weight:700}
  .navbar-brand .t{font-size:17px;font-weight:800;letter-spacing:-.02em;color:var(--ink)}
  .navbar-status{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
  .pill{display:inline-flex;align-items:center;gap:8px;padding:7px 13px;border-radius:999px;
    font-size:11.5px;font-weight:650;background:#fff;border:1px solid var(--card-line);color:var(--ink-soft);box-shadow:var(--shadow-sm)}
  .pill .dot{width:8px;height:8px;border-radius:50%;background:#c2cde0;box-shadow:0 0 0 3px rgba(0,0,0,.03)}
  .pill.ok{color:#0a7b6b;border-color:var(--ok-line);background:var(--ok-bg)}.pill.ok .dot{background:var(--ok);box-shadow:0 0 0 3px rgba(15,174,142,.15)}
  .pill.bad{color:#b22f37;border-color:var(--bad-line);background:var(--bad-bg)}.pill.bad .dot{background:var(--bad);box-shadow:0 0 0 3px rgba(224,69,79,.15)}
  .pill.warn{color:#8a5800;border-color:var(--warn-line);background:var(--warn-bg)}.pill.warn .dot{background:var(--warn);box-shadow:0 0 0 3px rgba(224,138,0,.15)}

  /* left sidebar */
  .main-sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sb-w);z-index:45;
    background:linear-gradient(180deg,#ffffff,#f4f8fe);border-right:1px solid var(--card-line);
    box-shadow:2px 0 18px rgba(20,40,80,.05);display:flex;flex-direction:column;transition:transform .22s ease}
  .sidebar-brand{height:var(--nav-h);display:flex;align-items:center;gap:10px;padding:0 18px;border-bottom:1px solid var(--card-line)}
  .sidebar-brand .logo-mark{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;font-size:17px;color:#fff;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));box-shadow:0 6px 16px rgba(23,99,214,.3)}
  .sidebar-brand .logo-txt{font-weight:800;font-size:15px;letter-spacing:-.01em;color:var(--ink)}
  .nav-sidebar{display:flex;flex-direction:column;gap:4px;padding:14px 12px;overflow-y:auto;flex:1}
  .nav-head{font-size:9.5px;letter-spacing:1.6px;text-transform:uppercase;color:var(--ink-dim);font-weight:800;padding:6px 10px 8px}
  .nav-link{appearance:none;text-align:left;background:transparent;border:1px solid transparent;color:var(--ink-soft);
    padding:11px 12px;border-radius:10px;font-family:var(--sans);font-size:13px;font-weight:650;cursor:pointer;
    display:flex;align-items:center;gap:11px;white-space:nowrap;transition:all .15s ease}
  .nav-link .ico{width:20px;text-align:center;color:var(--accent);font-size:13px;opacity:.85}
  .nav-link:hover{background:var(--accent-soft);color:var(--accent)}
  .nav-link[aria-selected="true"]{color:#fff;border-color:transparent;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));box-shadow:0 8px 18px rgba(23,99,214,.3)}
  .nav-link[aria-selected="true"] .ico{color:#fff;opacity:1}
  .sidebar-foot{padding:12px 18px;border-top:1px solid var(--card-line);font-size:10.5px;color:var(--ink-dim);font-weight:600}

  /* content + footer */
  .content-wrapper{margin-left:var(--sb-w);padding-top:var(--nav-h);min-height:100vh;transition:margin-left .22s ease}
  main{max-width:1320px;margin:0 auto;padding:24px 26px 30px}
  .panel{display:none;animation:fade .25s ease}.panel.active{display:block}
  @keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  .main-footer{margin-left:var(--sb-w);padding:13px 26px;border-top:1px solid var(--card-line);
    background:rgba(255,255,255,.7);color:var(--ink-soft);font-size:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;transition:margin-left .22s ease}
  .main-footer .ff-right{margin-left:auto;color:var(--ink-dim)}

  /* collapsed sidebar (toggle) */
  body.sb-collapsed .main-sidebar{transform:translateX(-100%)}
  body.sb-collapsed .content-wrapper,body.sb-collapsed .main-footer{margin-left:0}

  @media(max-width:980px){
    .content-wrapper,.main-footer{margin-left:0}
    .main-sidebar{transform:translateX(-100%)}
    body.sb-open .main-sidebar{transform:translateX(0)}
    .navbar-brand .t{font-size:15px}
  }

  /* ---- cards / sections ---- */
  .card{background:var(--card);border:1px solid var(--card-line);border-top:3px solid var(--accent);border-radius:var(--radius);margin-bottom:20px;
    box-shadow:var(--shadow);overflow:hidden}
  .card>summary,.card>.card-h{list-style:none;cursor:pointer;padding:16px 20px;display:flex;align-items:center;gap:11px;
    border-bottom:1px solid var(--card-line);font-weight:700;font-size:13.5px;
    background:linear-gradient(180deg,#fbfdff,#f6f9fe)}
  .card>.card-h{cursor:default}
  .card>summary::-webkit-details-marker{display:none}
  .card>summary .chev{margin-left:auto;color:var(--accent);transition:transform .18s;font-size:11px}
  details[open]>summary .chev{transform:rotate(90deg)}
  .card .eyebrow{font-size:9.5px;letter-spacing:1.8px;text-transform:uppercase;color:#fff;font-weight:800;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));padding:4px 9px;border-radius:7px;
    box-shadow:0 3px 9px rgba(23,99,214,.25)}
  .card-body{padding:20px}
  .sec-note{color:var(--ink-soft);font-size:12px;margin:0 0 16px}
  h4{font-size:13px;color:var(--ink)}

  .grid{display:grid;gap:14px}
  .g2{grid-template-columns:repeat(2,1fr)}.g3{grid-template-columns:repeat(3,1fr)}
  .g4{grid-template-columns:repeat(4,1fr)}.g6{grid-template-columns:repeat(6,1fr)}
  @media(max-width:1000px){.g4,.g6{grid-template-columns:repeat(3,1fr)}}
  @media(max-width:760px){.g2,.g3,.g4,.g6{grid-template-columns:repeat(2,1fr)}}

  label.fld{display:flex;flex-direction:column;gap:5px;font-size:11px;color:var(--ink-soft);font-weight:650;letter-spacing:.2px}
  label.fld span.u{color:var(--ink-dim);font-weight:500}
  input[type=text],input[type=number],select,textarea{font-family:var(--mono);font-size:12.5px;
    border:1px solid var(--field-line);border-radius:9px;padding:9px 11px;background:var(--field);color:var(--ink);width:100%;
    transition:border-color .15s,box-shadow .15s,background .15s}
  input[type=text]:hover,input[type=number]:hover,select:hover,textarea:hover{border-color:#c3d2ea}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);background:#fff;
    box-shadow:0 0 0 3px rgba(23,99,214,.14)}
  select{appearance:none;background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'><path d='M2 4l4 4 4-4' fill='none' stroke='%231763d6' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat:no-repeat;background-position:right 10px center;padding-right:28px;cursor:pointer}
  textarea{resize:vertical;min-height:64px;line-height:1.45}
  .chk{flex-direction:row;align-items:center;gap:8px;font-size:12.5px;color:var(--ink)}
  .chk input{width:auto}
  input[type=checkbox]{width:16px;height:16px;accent-color:var(--accent);cursor:pointer}

  .btn{appearance:none;border:1px solid var(--field-line);background:#fff;color:var(--ink);font-family:var(--sans);
    font-weight:700;font-size:12.5px;padding:10px 17px;border-radius:10px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;
    transition:all .15s ease;box-shadow:var(--shadow-sm)}
  .btn:hover{background:#f3f7fd;border-color:#c3d2ea;transform:translateY(-1px)}
  .btn:active{transform:translateY(0)}
  .btn.primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));border-color:transparent;color:#fff;
    box-shadow:0 8px 20px rgba(23,99,214,.3)}
  .btn.primary:hover{filter:brightness(1.05);box-shadow:0 10px 24px rgba(23,99,214,.38)}
  .btn.ghost{background:transparent;box-shadow:none}
  .btn.ghost:hover{background:var(--accent-soft);color:var(--accent);border-color:var(--accent-line)}
  .btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
  .runbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:6px 0 2px}
  .runbar .spacer{margin-left:auto}
  .runbar .tl-target{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700;color:var(--ink)}
  .runbar .tl-target select{font:inherit;font-weight:600;padding:7px 10px;border:1.5px solid var(--field-line);border-radius:9px;background:#fff;color:var(--ink)}

  table.data{border-collapse:separate;border-spacing:0;width:100%;font-family:var(--mono);font-size:11.5px}
  table.data th,table.data td{border-bottom:1px solid #edf1f8;border-right:1px solid #edf1f8;padding:7px 9px;text-align:right;white-space:nowrap}
  table.data th:first-child,table.data td:first-child{border-left:1px solid #edf1f8}
  table.data th{background:#eef4fc;color:#33476a;font-weight:700;position:sticky;top:0;z-index:1;
    border-top:1px solid #e1e9f5;letter-spacing:.2px}
  table.data td.t,table.data th.t{text-align:left}
  table.data tbody tr:nth-child(even) td{background:#f9fbfe}
  table.data tbody tr:hover td{background:#eef6ff}
  table.data td input.actin{width:88px;font-family:var(--mono);font-size:11px;padding:4px 6px;border:1px solid var(--field-line);border-radius:6px;text-align:right;background:#fff}
  table.data td input.actin:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(23,99,214,.14)}
  table.data td input.actin::placeholder{color:var(--ink-dim)}
  /* remove number spinner on actual-gas inputs (Revisi Sec.7) */
  input.no-spinner::-webkit-outer-spin-button,input.no-spinner::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  input.no-spinner[type=number]{-moz-appearance:textfield;appearance:textfield}
  .scroll{overflow:auto;max-height:560px;border:1px solid var(--card-line);border-radius:12px;background:#fff}

  /* ===== Simulation Data freeze panes (sticky header + sticky TIME column + corner) ===== */
  #tbl-result td.t,#tbl-result th.t{position:sticky;left:0;z-index:2;background:#fff}
  #tbl-result tbody tr:nth-child(even) td.t{background:#f3f7fd}
  #tbl-result tbody tr:hover td.t{background:#e7f1ff}
  #tbl-result tr.rowbad td.t{background:inherit}
  #tbl-result thead th.t{z-index:4;background:#e6eefb}
  #tbl-result thead th{z-index:3}

  /* ===== Simulation Data: modern colorful data grid (Revisi) ===== */
  /* Start/Stop unit markers (Revisi UI): the 30-min cell BEFORE a unit's first load is highlighted
     orange/yellow (start-up preparation), the first cell where a running unit drops to 0 is black (stop).
     Priority: fixed-load highlight > stop (black) > start-up (orange) > warning > default. */
  .simgrid td.cell-startup{background:#ffcf6b !important;color:#7a4a00 !important;box-shadow:inset 0 0 0 1px #f0a500}
  .simgrid td.cell-stop{background:#1c1c20 !important;color:#1c1c20 !important;box-shadow:inset 0 0 0 1px #000}
  /* PROMPT GAS SHORTAGE §11: cell Distillate BIRU, intensitas naik sesuai mix; teks tetap terbaca */
  .simgrid td.dist-m30{background:#dbeafe !important;color:#1e3a8a !important;box-shadow:inset 0 0 0 1px #93c5fd}
  .simgrid td.dist-m50{background:#93c5fd !important;color:#1e3a8a !important;box-shadow:inset 0 0 0 1px #60a5fa}
  .simgrid td.dist-m75{background:#3b82f6 !important;color:#ffffff !important;box-shadow:inset 0 0 0 1px #2563eb}
  .simgrid td.dist-m100{background:#1d4ed8 !important;color:#ffffff !important;box-shadow:inset 0 0 0 1px #1e40af}
  /* V12: gradasi biru proporsional persen Distillate (inline --dpct) + tooltip numerik */
  .simgrid td.dist-cell{background:var(--dbg) !important;color:var(--dfg) !important;box-shadow:inset 0 0 0 1px var(--dbd)}
  #dist-tip{position:fixed;z-index:20000;pointer-events:none;background:#0f172a;color:#f8fafc;font:12px/1.45 ui-monospace,Consolas,monospace;padding:7px 10px;border-radius:6px;box-shadow:0 6px 18px rgba(15,23,42,.35);white-space:pre;display:none}
  .simhead{display:flex;align-items:baseline;gap:12px;margin:0 0 10px;flex-wrap:wrap}
  .simhead .simtitle{font-weight:800;font-size:13px;letter-spacing:1.2px;color:var(--accent);text-transform:uppercase}
  .simhead .simsub{font-size:11.5px;color:var(--ink-dim);font-weight:600}
  .gas-monitor{margin-left:auto;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  .gas-monitor .gm-item{display:flex;flex-direction:column;align-items:flex-end;line-height:1.25}
  .gas-monitor .gm-lbl{font-size:9.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--ink-dim)}
  .gas-monitor .gm-val{font-size:14px;font-weight:800;color:var(--accent)}
  /* Unit Last Data Status color (Revisi): Running = green, Stop = red */
  select.uld-sel{font-weight:700;border-radius:7px;padding:4px 8px;border:1px solid var(--field-line)}
  select.uld-sel.uld-run{background:#e7f7ec;color:#0f7a3d;border-color:#7fd0a3}
  select.uld-sel.uld-stop{background:#fdecec;color:#c0322b;border-color:#e7a6a1}
  .simwrap{max-height:70vh;overflow:auto;position:relative}
  /* TABEL DETAIL SLOT (Revisi): proportional — fills its container (not full-bleed), horizontal scroll for
     the many columns, TIME column stays sticky/visible, rightmost column reachable by scroll. */
  #result-simdata{width:100%;max-width:none}
  #result-simdata .simwrap{width:100%;max-width:none;overflow-x:auto}
  #result-simdata table.simgrid{min-width:100%}
  /* PROMPT HEADER FONT 1.2x (koreksi terbaru): header TABEL DETAIL SLOT = 1.2× font body row
     (13.5px -> 16.2px), TANPA wrap (nowrap, melebar ke samping + horizontal scroll), tinggi row
     NATURAL mengikuti font (padding kecil, tanpa height/min-height paksa). Berlaku identik di
     Plan & Monitoring Daily Plan, mode normal maupun Full Screen (rule CSS yang sama). */
  :root{--detail-slot-body-font:13.5px;--detail-slot-header-font:calc(var(--detail-slot-body-font)*1.2)}
  table.simgrid{font-size:var(--detail-slot-body-font);border-collapse:separate;border-spacing:0}
  table.simgrid thead th{font-size:var(--detail-slot-header-font);font-weight:800;letter-spacing:.4px;text-transform:uppercase;
    padding:4px 10px;background:#f4f8fe;border-bottom:2px solid #e1e9f5;
    white-space:nowrap;line-height:1.15;text-align:center;vertical-align:middle}
  /* header sticky di dalam scroll container (.simwrap) — tetap terbaca saat scroll ke bawah */
  .simwrap table.simgrid thead th{position:sticky;top:0;z-index:3}
  .simwrap table.simgrid thead th.t{z-index:4}
  table.simgrid tbody td{padding:9px 13px;font-size:var(--detail-slot-body-font);font-variant-numeric:tabular-nums;color:var(--ink);border-bottom:1px solid #eef2f8}
  table.simgrid tbody tr:nth-child(even) td{background:#fafcff}
  table.simgrid tbody tr:hover td{background:#eef5ff}
  table.simgrid td.t,table.simgrid th.t{font-weight:800;text-align:center}

  /* ===== PROMPT FULLSCREEN §2/§3/§6 — modal full screen TABEL DETAIL SLOT ===== */
  .btn.simfs{background:linear-gradient(135deg,#4f46e5,#4338ca);color:#fff;border:1px solid #4338ca;font-weight:800}
  .btn.simfs:hover{background:linear-gradient(135deg,#6366f1,#4f46e5);box-shadow:0 2px 10px rgba(79,70,229,.35)}
  #simfs-modal{display:none;position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.55);backdrop-filter:blur(2px);
    align-items:center;justify-content:center}
  #simfs-modal.open{display:flex}
  #simfs-panel{width:97vw;height:94vh;background:#fff;border-radius:14px;box-shadow:0 18px 60px rgba(2,6,23,.45);
    display:flex;flex-direction:column;overflow:hidden}
  #simfs-bar{display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc}
  #simfs-bar .t1{font-weight:800;font-size:13px;letter-spacing:1.2px;color:var(--accent);text-transform:uppercase}
  #simfs-bar .t2{font-size:11.5px;color:var(--ink-dim);font-weight:600}
  #simfs-close{margin-left:auto;appearance:none;border:1px solid #cbd5e1;background:#fff;border-radius:10px;cursor:pointer;
    font-weight:800;font-size:12.5px;padding:7px 14px;color:#334155}
  #simfs-close:hover{background:#fee2e2;border-color:#ef4444;color:#b91c1c}
  #simfs-body{flex:1;overflow:auto;padding:12px 16px}
  /* tabel asli di-teleport ke sini: scroll container internal dibesarkan mengikuti tinggi modal */
  #simfs-body .simwrap{max-height:calc(94vh - 132px);overflow:auto}

  /* group header text colours (the TIME corner + sticky col keep their solid bg from freeze rules) */
  table.simgrid thead th.g-time{color:#1b2c5a}
  table.simgrid thead th.g-dispatch{color:#c2691b}
  table.simgrid thead th.g-hl{color:#5a6a86}
  table.simgrid thead th.g-ie{color:#7a3fb0}
  table.simgrid thead th.g-gtg{color:#1f5bb5}
  table.simgrid thead th.g-gtg2{color:#0e7aa8}
  table.simgrid thead th.g-stg{color:#6b3fb0}
  table.simgrid thead th.g-jbbk{color:#0d7a6f}
  table.simgrid thead th.g-bbln{color:#c2691b}
  table.simgrid thead th.g-ge{color:#0e7aa8}
  table.simgrid thead th.g-export{color:#1a7d4d}
  table.simgrid thead th.g-diff{color:#b0392b}
  table.simgrid thead th.g-spin{color:#0e6aa8}
  table.simgrid thead th.g-bus{color:#4a5a82}
  table.simgrid thead th.g-coal{color:#9a5b2b}
  table.simgrid thead th.g-dist{color:#9a6b00}
  table.simgrid thead th.g-gas{color:#0d7a6f}
  table.simgrid thead th.g-pgn{color:#1f5bb5}
  table.simgrid thead th.g-actual{color:#9a6b00}

  /* body cell accents per group (subtle, readable) */
  table.simgrid td.g-ie{color:#7a3fb0;font-weight:600}
  table.simgrid td.g-gtg{color:#1f5bb5}
  table.simgrid td.g-gtg2{color:#0e7aa8}
  table.simgrid td.g-stg{color:#6b3fb0;font-weight:600}
  table.simgrid td.g-jbbk{color:#0d7a6f;font-weight:700}
  table.simgrid td.g-bbln{color:#b5651d}
  table.simgrid td.g-ge{color:#0e7aa8}
  table.simgrid td.g-gas{color:#0d7a6f;font-weight:700}
  table.simgrid td.g-pgn{color:#2456a8}
  table.simgrid td.g-coal{color:#9a5b2b}
  table.simgrid td.g-dist{color:#9a6b00}
  table.simgrid td.g-spin{color:#0e6aa8}
  table.simgrid td.g-bus{color:#4a5a82}
  table.simgrid td.g-hl{color:#5a6a86}

  /* standout cells */
  table.simgrid td.cell-export{color:#127a45;font-weight:800;background:#ecfbf2}
  table.simgrid tbody tr:nth-child(even) td.cell-export{background:#e4f7ec}
  /* Export PLN range colouring */
  table.simgrid td.cell-export.exp-green{color:#127a45;background:#ecfbf2}
  table.simgrid tbody tr:nth-child(even) td.cell-export.exp-green{background:#e4f7ec}
  table.simgrid td.cell-export.exp-yellow{color:#9a6a00;background:#fff7da}
  table.simgrid tbody tr:nth-child(even) td.cell-export.exp-yellow{background:#fdf1c8}
  table.simgrid td.cell-export.exp-red{color:#b22f37;background:#fdecee}
  table.simgrid tbody tr:nth-child(even) td.cell-export.exp-red{background:#fbe0e3}
  /* Bus Flow / Spinning Reserve / PGN-RT conditional colour (red/yellow/green) */
  table.simgrid td.bs-red{color:#b22f37;background:#fdecee;font-weight:700}
  table.simgrid tbody tr:nth-child(even) td.bs-red{background:#fbe0e3}
  table.simgrid td.bs-yellow{color:#9a6a00;background:#fff7da;font-weight:700}
  table.simgrid tbody tr:nth-child(even) td.bs-yellow{background:#fdf1c8}
  table.simgrid td.bs-green{color:#127a45;background:#ecfbf2}
  table.simgrid tbody tr:nth-child(even) td.bs-green{background:#e4f7ec}
  /* Simple Cycle running cell (GTG online without its STG) -> bright orange */
  table.simgrid td.sc-mode{background:#ffe2bd !important;color:#a85b00;font-weight:700}
  table.simgrid tbody tr:nth-child(even) td.sc-mode{background:#ffd9a8 !important}
  /* Manual Fix Load cell -> bright red */
  table.simgrid td.fix-load{background:#ffd2d2 !important;color:#a11212;font-weight:800;cursor:pointer}
  table.simgrid tbody tr:nth-child(even) td.fix-load{background:#ffc4c4 !important}
  /* Manual Fixed Flow cell (Ctrl+Click) -> amber */
  table.simgrid td.ff-manual{background:#ffe8c2 !important;color:#9a5b00;font-weight:800;cursor:pointer}
  table.simgrid tbody tr:nth-child(even) td.ff-manual{background:#ffdfa8 !important}
  /* Manual Fixed Load popover (Ctrl+Click) */
  .fixpop{position:absolute;z-index:9999;background:#fff;border:1px solid #e2c0c4;border-radius:12px;box-shadow:0 10px 30px rgba(120,20,28,.18);padding:12px;min-width:200px;font-family:var(--sans)}
  .fixpop-h{font-weight:800;font-size:12px;color:#a3262e;margin-bottom:8px;letter-spacing:.02em}
  .fixpop-l{display:flex;flex-direction:column;gap:4px;font-size:10.5px;font-weight:700;text-transform:uppercase;color:#7a8294}
  .fixpop-l span{font-weight:600;color:#9aa1b0;text-transform:none}
  .fixpop-l input{padding:8px 10px;border:1px solid #d8dde6;border-radius:8px;font-family:var(--mono);font-size:14px}
  .fixpop-b{display:flex;gap:8px;margin-top:10px}
  .fixpop-b .btn{flex:1;padding:8px 10px;border-radius:8px;font-size:11.5px;font-weight:700;border:none;cursor:pointer;background:linear-gradient(135deg,#e0353f,#c0282f);color:#fff}
  .fixpop-b .btn.ghost{background:#f3f4f7;color:#444;border:1px solid #e0e3e8}
  /* Actual Data toolbar (Tahap 3) */
  .actual-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px;padding:10px 12px;background:#eef4ff;border:1px solid #d3e1f7;border-radius:10px}
  .actual-toolbar .actual-title{font-weight:800;color:#23457e}
  .actual-toolbar .achint{font-size:11.5px;color:#5a6b86}
  .actual-toolbar .achint.on{color:#1d7a44;font-weight:700}
  /* Actual (locked) rows in the sim grid */
  table.simgrid tr.actual-row td{background:#eaf3ff !important}
  table.simgrid tr.actual-row td.t{background:#dceafd !important;font-weight:700}
  table.simgrid td.g-actual{background:#fffbe9}
  table.simgrid td.g-actual input.actin{background:#fffdf2;text-align:right;border:1px solid #e7dca8;border-radius:7px;font-weight:600;min-width:90px}
  table.simgrid td.g-actual input.actin:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(23,99,214,.14)}
  /* PROMPT ACTUAL GAS 1H: merged cell 1 jam (rowspan=2) — input di tengah vertikal, border pemisah jam */
  table.simgrid td.g-actual-hr{vertical-align:middle;border-bottom:2px solid #e7dca8 !important}
  table.simgrid td.g-actual-hr input.actin[disabled]{background:#e2e8f0;color:#64748b;cursor:not-allowed}

  /* pills / badges */
  table.simgrid .pill{display:inline-block;min-width:46px;text-align:center;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800;font-variant-numeric:tabular-nums}
  table.simgrid .pill.green{background:#d8f5e3;color:#117a43}
  table.simgrid .pill.orange{background:#ffe9d2;color:#b5651d}
  table.simgrid .pill.slate{background:#e7edf6;color:#4a5a82}
  table.simgrid .pill.pos{background:#d8f5e3;color:#117a43}
  table.simgrid .pill.neg{background:#fcdada;color:#b0392b}
  table.simgrid .pill.zero{background:#e7edf6;color:#5a6a86}

  /* ===== comparison: sticky # + Parameter columns, deviation badge ===== */
  table.sumtbl.cmptbl td.c,table.sumtbl.cmptbl th.c{position:sticky;left:0;z-index:2;background:#fff}
  table.sumtbl.cmptbl td.p,table.sumtbl.cmptbl th.p{position:sticky;left:54px;z-index:2;background:#fff}
  table.sumtbl.cmptbl thead th.c{z-index:4;background:#eaf1fb}
  table.sumtbl.cmptbl thead th.p{z-index:4;background:#eaf1fb}
  table.sumtbl td .dev{font-size:9px;font-weight:800;margin-left:5px;padding:1px 4px;border-radius:5px;vertical-align:middle}
  table.sumtbl td .dev.up{color:#8a5800;background:var(--warn-bg)}
  table.sumtbl td .dev.down{color:#1763d6;background:var(--accent-soft)}

  /* offscreen capture table for Download Image (static positioning so html2canvas renders cleanly) */
  table.imgcap th,table.imgcap td{position:static !important}
  table.imgcap thead th{background:#eef4fc !important}
  /* Gas Shortage Recommendation panel */
  .gsr-card{margin:16px 0 4px;border:1px solid #f1c9cd;border-radius:14px;background:linear-gradient(180deg,#fff,#fff7f7);box-shadow:0 6px 22px rgba(178,47,55,.07);overflow:hidden}
  .gsr-head{display:flex;align-items:center;gap:10px;padding:13px 16px;background:linear-gradient(90deg,#fdecee,#fff5f0);border-bottom:1px solid #f3d2d5}
  .gsr-ic{font-size:16px}
  .gsr-title{font-family:var(--sans);font-weight:800;letter-spacing:.04em;color:#a3262e;font-size:13px}
  .gsr-sub{margin-left:auto;font-size:11px;color:#9a6b6e;font-style:italic}
  .gsr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;padding:16px}
  .gsr-stat{background:#fff;border:1px solid #eef0f4;border-radius:11px;padding:11px 13px;display:flex;flex-direction:column;gap:5px}
  .gsr-stat.bad{border-color:#f1c9cd;background:#fff5f6}
  .gsr-l{font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#7a8294}
  .gsr-v{font-family:var(--mono);font-weight:700;font-size:19px;color:#1c2433;line-height:1}
  .gsr-stat.bad .gsr-v{color:#b22f37}
  .gsr-v small{font-family:var(--sans);font-size:10px;font-weight:600;color:#8b93a3;margin-left:5px}
  .gsr-actions{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:0 16px 16px}
  .gsr-actions .btn{border:none;border-radius:10px;padding:11px 15px;font-family:var(--sans);font-weight:700;font-size:12px;letter-spacing:.02em;color:#fff;cursor:pointer;transition:filter .15s,transform .05s}
  .gsr-actions .btn:active{transform:translateY(1px)}
  .gsr-b1{background:linear-gradient(135deg,#2f6df0,#2456c8)}
  .gsr-b2{background:linear-gradient(135deg,#e0892a,#c9701b)}
  .gsr-b3{background:linear-gradient(135deg,#0a9b86,#0a7b6b)}
  .gsr-actions .btn:hover{filter:brightness(1.06)}
  .gsr-manual{display:flex;align-items:flex-end;gap:8px;margin-left:auto;background:#fff;border:1px solid #eef0f4;border-radius:11px;padding:9px 11px}
  .gsr-manual .fld{display:flex;flex-direction:column;gap:4px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#7a8294;margin:0}
  .gsr-manual .fld .u{font-weight:600;color:#9aa1b0;text-transform:none;letter-spacing:0}
  .gsr-manual input{width:96px;padding:8px 10px;border:1px solid #d8dde6;border-radius:8px;font-family:var(--mono);font-size:13px}
  tr.rowbad td{background:#fdecee !important}
  .tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:10.5px;font-weight:700;font-family:var(--sans)}
  .tag.ok{background:var(--ok-bg);color:#0a7b6b;border:1px solid var(--ok-line)}.tag.bad{background:var(--bad-bg);color:#b22f37;border:1px solid var(--bad-line)}

  /* unit characteristic + bus table */
  table.mini{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}
  table.mini th,table.mini td{border-bottom:1px solid #eef2f8;padding:8px 10px;text-align:left}
  table.mini thead th,table.mini tr:first-child th{color:#5a6a86;font-weight:700;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;
    background:#f6f9fd;position:sticky;top:0;z-index:1}
  table.mini tbody tr:hover td,table.mini tr:hover td{background:#f7fafe}
  table.mini input{padding:6px 8px}
  .busA{background:linear-gradient(135deg,#f4b53f,#e09a16);color:#3a2700}.busB{background:linear-gradient(135deg,#4f8af0,#2f6fd6);color:#fff}
  .buscell{font-weight:700;text-align:center;border-radius:8px;padding:5px 0;border:0;color:#fff}

  /* coefficient cards + priority editors */
  .uc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(238px,1fr));gap:14px}
  /* Fuel formula coefficients grouped into 3 categories (Revisi UI Sec.2): GTG Frame 6 / GTG Frame 9 / Gas Engine */
  .uc-cats{display:flex;flex-direction:column;gap:16px}
  .uc-cat{border:1px solid var(--card-line);border-radius:13px;background:linear-gradient(180deg,#fbfdff,#f4f8ff);padding:12px 13px}
  .uc-cat>.uc-cat-h{display:flex;align-items:center;gap:9px;margin:0 0 11px;font-size:12px;font-weight:800;letter-spacing:.3px;color:var(--accent);text-transform:uppercase}
  .uc-cat>.uc-cat-h .uc-cat-c{font-weight:600;color:var(--ink-dim);font-size:10.5px;letter-spacing:0;text-transform:none}
  .uc-card{border:1px solid var(--card-line);border-radius:12px;padding:14px;background:linear-gradient(180deg,#fff,#fafcff);
    box-shadow:var(--shadow-sm);transition:box-shadow .15s,transform .15s,border-color .15s}
  .uc-card:hover{box-shadow:var(--shadow);transform:translateY(-2px);border-color:var(--accent-line)}
  .uc-card h5{margin:0 0 10px;font-size:12.5px;font-weight:800;color:var(--ink);letter-spacing:-.01em;display:flex;align-items:center;gap:7px}
  .uc-card h5 .badge{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--accent);background:var(--accent-soft);border:1px solid var(--accent-line);border-radius:6px;padding:2px 7px}
  .uc-row{display:grid;grid-template-columns:1fr 1fr;gap:9px}
  .uc-f{display:flex;flex-direction:column;gap:4px;font-size:10px;color:var(--ink-soft);font-weight:650;letter-spacing:.2px}
  .uc-f input{padding:6px 8px;font-size:11.5px}
  .uc-seg{margin-top:10px;border-top:1px dashed #e4eaf3;padding-top:9px}
  .uc-seg .lbl{font-size:9.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--accent);font-weight:800;margin-bottom:6px}
  .uc-fmt{margin:6px 0 18px}
  .uc-fmt>h4{margin:0 0 10px;font-size:13px;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:8px}
  .uc-fmt .badge{font-size:9.5px;font-weight:700;background:var(--accent);color:#fff;border-radius:20px;padding:2px 9px;text-transform:none;letter-spacing:.3px}
  .uc-tabs{display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap}
  .uc-tab{border:1px solid var(--field-line);background:#fff;border-radius:10px;padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;color:var(--ink-soft);transition:all .15s}
  .uc-tab:hover{border-color:var(--accent-line);color:var(--accent);background:var(--accent-soft)}
  .uc-tab.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));border-color:transparent;color:#fff;box-shadow:0 6px 16px rgba(23,99,214,.28)}

  table.prio{border-collapse:separate;border-spacing:0;width:100%;font-size:12px}
  table.prio th,table.prio td{border-bottom:1px solid #eef2f8;padding:9px 11px;text-align:left;vertical-align:middle}
  table.prio th{color:#5a6a86;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.6px;background:#f6f9fd}
  table.prio tbody tr:hover td{background:#f7fafe}
  table.prio td.pn{font-family:var(--mono);color:var(--accent);width:104px;font-weight:700}
  table.prio td.bk{font-weight:700;color:var(--ink);width:220px}
  table.prio select{width:100%}
  .prio-req{display:flex;align-items:center;gap:7px;font-size:11.5px;color:var(--ink);font-weight:650}
  .prio-req input{width:auto}
  .blk-card{border:1px solid var(--card-line);border-radius:12px;margin-bottom:12px;overflow:hidden;box-shadow:var(--shadow-sm)}
  .blk-card .blk-h{background:linear-gradient(180deg,#f3f8fe,#eaf2fc);padding:10px 14px;font-weight:800;font-size:12.5px;color:var(--ink);border-bottom:1px solid var(--card-line);display:flex;align-items:center;gap:9px;flex-wrap:wrap}
  .blk-card .blk-h .units{font-family:var(--mono);font-size:10.5px;color:var(--ink-dim);font-weight:600}
  .blk-card table.prio{margin:0}
  .blk-card table.prio td,.blk-card table.prio th{padding:8px 14px}

  /* KPI / summary cards */
  .kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
  .kpi{border:1px solid var(--card-line);border-radius:13px;padding:15px 16px;background:linear-gradient(180deg,#fff,#fafcff);
    box-shadow:var(--shadow-sm);position:relative;overflow:hidden;transition:transform .15s,box-shadow .15s}
  .kpi:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
  .kpi::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:linear-gradient(180deg,var(--accent),var(--accent-2))}
  .kpi .l{font-size:10px;letter-spacing:.5px;color:var(--ink-dim);text-transform:uppercase;font-weight:700}
  .kpi .v{font-family:var(--mono);font-size:21px;font-weight:700;color:var(--ink);margin-top:5px;letter-spacing:-.02em}
  .kpi .v small{font-size:11px;color:var(--ink-dim);font-weight:500}
  .kpi.good::before{background:linear-gradient(180deg,var(--ok),#3fd6bf)}.kpi.good .v{color:#0a7b6b}
  .kpi.alert::before{background:linear-gradient(180deg,var(--warn),#f4b53f)}.kpi.alert .v{color:#a06200}

  .banner{border-radius:13px;padding:16px 18px;margin-bottom:16px;border:1px solid;display:flex;gap:20px;flex-wrap:wrap;align-items:center;box-shadow:var(--shadow-sm)}
  .banner .b-item{display:flex;flex-direction:column;gap:3px}
  .banner .b-item .l{font-size:10px;text-transform:uppercase;letter-spacing:1px;font-weight:800;opacity:.85}
  .banner .b-item .v{font-family:var(--mono);font-size:16px;font-weight:700}
  .banner.ok{background:linear-gradient(180deg,#eafaf5,#e0f6ef);border-color:var(--ok-line);color:#0a6b5e}
  .banner.bad{background:linear-gradient(180deg,#fdecee,#fbe2e4);border-color:var(--bad-line);color:#a32a31}
  .rec{background:linear-gradient(180deg,#fff8ea,#fdf1d9);border:1px solid var(--warn-line);border-radius:12px;padding:13px 15px;margin-bottom:16px;color:#7a5600;font-size:12.5px}
  .rec b{color:#5e4300}
  .warnbox{background:#fff;border:1px solid var(--card-line);border-left:4px solid var(--warn);border-radius:12px;padding:13px 16px;margin-bottom:16px;box-shadow:var(--shadow-sm)}
  .warnbox ul{margin:7px 0 0;padding-left:20px}.warnbox li{margin:4px 0;font-size:12px;color:#6b5630;font-family:var(--mono)}

  .hint{color:var(--ink-dim);font-size:11.5px}
  .placeholder{border:1.5px dashed #c6d4ea;border-radius:14px;padding:34px;text-align:center;color:var(--ink-soft);
    background:linear-gradient(180deg,#fbfdff,#f4f8fe)}
  .placeholder h3{color:var(--ink);margin-bottom:7px}
  .pri-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:5px 0;font-family:var(--mono);font-size:12px}
  .pri-row .bk{background:var(--accent-soft);border:1px solid var(--accent-line);border-radius:8px;padding:5px 10px}
  .legend{display:flex;gap:16px;flex-wrap:wrap;font-size:11.5px;color:var(--ink-soft);margin-top:10px}
  .legend i{width:12px;height:12px;border-radius:4px;display:inline-block;vertical-align:-1px;margin-right:6px}
  .toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:linear-gradient(135deg,#13233f,#1763d6);color:#fff;
    padding:12px 20px;border-radius:12px;font-size:13px;font-weight:600;box-shadow:0 12px 30px rgba(20,40,80,.3);z-index:50;opacity:0;transition:opacity .2s;pointer-events:none}
  .toast.show{opacity:1}
  .spin{width:15px;height:15px;border:2px solid #ffffff66;border-top-color:#fff;border-radius:50%;animation:sp .7s linear infinite}
  @keyframes sp{to{transform:rotate(360deg)}}
  ::-webkit-scrollbar{width:11px;height:11px}
  ::-webkit-scrollbar-thumb{background:#cdd9ec;border-radius:999px;border:3px solid transparent;background-clip:content-box}
  ::-webkit-scrollbar-thumb:hover{background:#b4c5e0;background-clip:content-box}
  @media print{.main-header,.main-sidebar,.main-footer,.nav-toggle,.runbar,.no-print{display:none!important}
    .content-wrapper{margin-left:0;padding-top:0}.card{break-inside:avoid;box-shadow:none}body{background:#fff}}

  /* ===== schedule tables (Unit Stop / Fix Load / Skip Load) ===== */
  .sched-h{display:flex;align-items:center;gap:9px;margin:18px 0 7px;flex-wrap:wrap}
  .sched-h h4{margin:0}
  .sched-wrap{border:1px solid var(--card-line);border-radius:12px;background:#fff;overflow:auto;box-shadow:var(--shadow-sm)}
  table.sched{border-collapse:separate;border-spacing:0;width:100%;font-size:12px;min-width:560px}
  table.sched thead th{background:#f6f9fd;color:#5a6a86;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.6px;
    padding:9px 11px;text-align:left;border-bottom:1px solid #e1e9f5;white-space:nowrap;position:sticky;top:0;z-index:1}
  table.sched tbody td{padding:7px 11px;border-bottom:1px solid #eef2f8;vertical-align:middle;text-align:left}
  table.sched tbody tr:last-child td{border-bottom:0}
  table.sched tbody tr:hover td{background:#f7fafe}
  table.sched td.no{font-family:var(--mono);color:var(--accent);font-weight:700;width:46px}
  table.sched td.ctr,table.sched th.ctr{text-align:center}
  table.sched select,table.sched input[type=number]{padding:7px 9px;font-size:12px;width:100%}
  table.sched .num{max-width:150px}
  .sched-empty{padding:15px;color:var(--ink-dim);font-size:12px;text-align:center}
  .rowdel{appearance:none;border:1px solid var(--bad-line);background:var(--bad-bg);color:#b22f37;width:30px;height:30px;border-radius:8px;
    cursor:pointer;font-weight:700;font-size:13px;line-height:1;transition:all .15s;display:inline-flex;align-items:center;justify-content:center}
  .rowdel:hover{background:#fbd5d8;transform:translateY(-1px)}

  /* ============================================================================================
   * AKAR MASALAH UTAMA "TIGA PILIHAN GAS SHORTAGE HILANG".
   *
   * Di sini dahulu berdiri satu baris:
   *     .gsd-locked{opacity:.45;cursor:not-allowed;filter:grayscale(1)}
#gsd-seg,#gsd-lng-wrap,#gsd-req-lng,#gsd-est-dist,#gsd-badge,#gsd-sub{display:none!important}
   *
   * Satu aturan itu menyembunyikan SELURUH kendali Gas Shortage Decision sekaligus: ketiga tombol
   * pilihan, kolom input LNG, penunjuk Required LNG, penunjuk Estimated Distillate, badge status,
   * dan subjudulnya. Ketiga pilihan tidak pernah terhapus dari markup — mereka ADA, lengkap dengan
   * event listener-nya, tetapi tidak pernah dapat dilihat maupun diklik.
   *
   * `!important` membuatnya tidak dapat dipulihkan dari JavaScript mana pun: `setGasAction()` dan
   * `refreshGasDecision()` tetap berjalan dan tetap memperbarui elemen-elemen itu, namun hasilnya
   * tidak pernah sampai ke layar. Itulah sebabnya operator melihat menu keputusan bahan bakar
   * lenyap sementara backend tetap melaporkan shortage.
   *
   * Aturan itu dihapus. Keadaan aktif/nonaktif tiap elemen memang sudah dikelola JavaScript
   * (kolom LNG hanya hidup pada mode add_lng, badge mengikuti status shortage), jadi tidak ada
   * yang perlu menggantikannya. */
  /* (baris penyembunyi dihapus — lihat keterangan di atas) */
  /* ===== Gas Shortage Decision card ===== */
  .gsd-card{border:1px solid var(--card-line);border-radius:14px;padding:16px 18px;background:linear-gradient(180deg,#fff,#fafcff);box-shadow:var(--shadow-sm)}
  .gsd-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
  .gsd-badge{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:.2px;
    border:1px solid var(--card-line);background:#eef2f8;color:var(--ink-soft)}
  .gsd-badge::before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor;opacity:.85}
  .gsd-badge.ok{background:var(--ok-bg);border-color:var(--ok-line);color:#0a7b6b}
  .gsd-badge.warn{background:var(--warn-bg);border-color:var(--warn-line);color:#8a5800}
  .gsd-badge.bad{background:var(--bad-bg);border-color:var(--bad-line);color:#b22f37}
  .gsd-seg{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
  @media(max-width:760px){.gsd-seg{grid-template-columns:1fr}}
  .gsd-opt{text-align:left;border:1.5px solid var(--field-line);background:#fff;border-radius:12px;padding:12px 13px;cursor:pointer;
    display:flex;flex-direction:column;gap:3px;transition:all .15s;font-family:var(--sans)}
  .gsd-opt:hover{border-color:var(--accent-line);background:var(--accent-soft)}
  .gsd-opt.active{border-color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 3px rgba(23,99,214,.12)}
  .gsd-opt-t{font-weight:800;font-size:12.5px;color:var(--ink)}
  .gsd-opt.active .gsd-opt-t{color:var(--accent)}
  .gsd-opt-d{font-size:11px;color:var(--ink-soft);line-height:1.4}
  .gsd-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:14px;align-items:end}
  @media(max-width:760px){.gsd-grid{grid-template-columns:1fr}}
  .gsd-grid label.off{opacity:.45}
  .gsd-read{border:1px solid var(--card-line);border-radius:11px;padding:10px 13px;background:var(--field)}
  .gsd-read .l{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-dim);font-weight:800}
  .gsd-read .v{display:block;font-family:var(--mono);font-size:16px;font-weight:700;color:var(--ink);margin-top:4px}

  /* ===== Daily Plan sub-tabs (Periodic | Frequently | Result) ===== */
  .subtabs{display:flex;gap:8px;flex-wrap:wrap;margin:2px 0 18px;padding:5px;background:linear-gradient(180deg,#eef4fc,#e7effb);
    border:1px solid var(--card-line);border-radius:14px;box-shadow:var(--shadow-sm)}
  .subtab{flex:1 1 auto;min-width:150px;appearance:none;border:1px solid transparent;background:transparent;cursor:pointer;
    font-family:var(--sans);font-weight:800;font-size:13px;color:var(--ink-soft);padding:11px 18px;border-radius:11px;
    display:inline-flex;align-items:center;justify-content:center;gap:9px;transition:all .16s ease;letter-spacing:.2px}
  .subtab:hover{color:var(--accent);background:#ffffffcc}
  .subtab.active{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;box-shadow:0 8px 18px rgba(23,99,214,.28)}
  .subtab .n{font-family:var(--mono);font-size:11px;width:19px;height:19px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;
    background:rgba(23,99,214,.12);color:var(--accent)}
  .subtab.active .n{background:rgba(255,255,255,.25);color:#fff}
  /* ===== PROMPT MONITORING DAILY PLAN: branch pills + TIME PASSED colors ===== */
  .dp-branch{display:flex;flex-direction:column;gap:8px;margin:2px 0 12px;max-width:340px}
  .dpb{appearance:none;display:flex;align-items:center;gap:10px;border:1px solid var(--field-line);background:#fff;cursor:pointer;
       font-family:var(--sans);font-weight:800;font-size:13.5px;color:#334155;border-radius:12px;padding:10px 16px;text-align:left;
       transition:all .15s ease}
  .dpb .dot{width:14px;height:14px;border-radius:50%;border:2px solid #94a3b8;background:#fff;flex:0 0 14px}
  .dpb:hover{border-color:var(--accent-line);color:var(--accent);background:var(--accent-soft)}
  .dpb.active{border-color:var(--accent);color:var(--accent);background:var(--accent-soft);box-shadow:0 0 0 3px rgba(23,99,214,.10)}
  .dpb.active .dot{border-color:var(--accent);background:radial-gradient(circle at center,var(--accent) 0 55%,#fff 60%)}
  /* TIME PASSED cell: Y = hijau terang, N = merah terang — prioritas visual di atas style row locked */
  td.tp-cell{padding:2px 4px;text-align:center}
  td.tp-cell.tp-y{background:#86efac !important}
  td.tp-cell.tp-n{background:#fca5a5 !important}
  select.tp-sel{font-family:var(--mono);font-weight:800;font-size:12px;border-radius:8px;border:1px solid rgba(0,0,0,.18);padding:2px 6px;cursor:pointer}
  select.tp-sel.tp-y{background:#22c55e !important;color:#fff !important}
  select.tp-sel.tp-n{background:#ef4444 !important;color:#fff !important}
  .tp-lock{font-size:11px;margin-left:4px}
  tr.tp-locked td:not(.tp-cell){background:#e2e8f0}         /* row Y = locked style abu-abu terang */
  tr.tp-locked td:first-child::after{content:' Locked';font-size:9px;font-weight:800;color:#64748b;vertical-align:super}
  /* Report -> Planning: row Monitoring Daily Plan = kuning terang (kecuali sel Date yang di-merge) */
  /* PROMPT PLAN SAVE §4.3: row Monitoring Daily Plan = ORANYE TERANG (sebelumnya #fde047 = kuning).
     Kontras dijaga: oranye-400 + teks abu gelap => rasio kontras tetap tinggi & teks mudah dibaca.
     Row Plan normal TIDAK ikut berubah (selector hanya menyasar tr.rp-mon). */
  tr.rp-mon td:not(.ra-datecol){background:#fb923c !important;color:#1f2937 !important}
  tr.rp-mon td:not(.ra-datecol) a{color:#1f2937 !important}
  /* PROMPT PLAN SAVE §2: modal pilihan Save (khusus tab Plan) */
  /* V3: Save Input tetap dapat dijangkau di atas lapisan modal (progres, Gas Shortage). */
  body.pp-mask-open #btn-save{position:fixed;left:16px;bottom:16px;z-index:10050;box-shadow:0 6px 18px rgba(15,23,42,.35);background:#fff;opacity:1}
  .sv-mask{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:16px 0;-webkit-overflow-scrolling:touch}
  /* ROOT CAUSE TOMBOL TIDAK DAPAT DIKLIK (penyebab mekanis, terpisah dari atribut disabled):
     mask memakai align-items:center TANPA overflow dan box TANPA max-height. Begitu isi popup
     lebih tinggi daripada viewport, box tercentang vertikal sehingga bagian atas DAN bawahnya
     keluar layar — deretan tombol aksi berada di bawah dan tidak dapat dijangkau, sementara body
     sudah dikunci overflow:hidden sehingga halaman pun tidak bisa digulir. Perbaikan: mask dapat
     digulir, box dibatasi tinggi viewport dan menggulir isinya sendiri; margin:auto tetap
     memusatkan box ketika ruang cukup tanpa memotong ketika tidak. */
  .sv-box{background:#fff;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.3);max-width:520px;width:calc(100% - 32px);padding:20px 22px;font:400 13.5px system-ui,Segoe UI,Arial;color:#1f2937;margin:auto;max-height:calc(100vh - 32px);overflow:auto}
  .sv-box h4{margin:0 0 6px;font:800 15px system-ui,Segoe UI,Arial;color:#0f172a}
  .sv-box p.sv-note{margin:0 0 14px;color:#475569;font-size:12.5px;line-height:1.5}
  .sv-opt{display:block;width:100%;text-align:left;border:1px solid #cbd5e1;border-radius:9px;padding:11px 13px;margin-bottom:9px;background:#f8fafc;cursor:pointer;font:600 13.5px system-ui,Segoe UI,Arial;color:#0f172a}
  .sv-opt:hover{background:#eef2ff;border-color:#6366f1}
  .sv-opt small{display:block;font-weight:400;color:#64748b;margin-top:3px;font-size:12px}
  .sv-opt.sv-mon:hover{background:#fff7ed;border-color:#fb923c}
  .sv-actions{display:flex;justify-content:flex-end;margin-top:4px}
  .sv-cancel{border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 16px;cursor:pointer;font:600 13px system-ui,Segoe UI,Arial;color:#475569}
  .sv-cancel:hover{background:#f1f5f9}
  .subpanel{display:none}
  .subpanel.active{display:block;animation:fadeUp .22s ease}
  @keyframes fadeUp{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
  /* Report → Actual controls + table */
  .ractrl{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin:6px 0 4px}
  .ractrl .fld.inline{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)}
  .ractrl .fld.inline select{min-width:150px}
  .ractrl .spacer{flex:1 1 auto}
  .ra-scroll{max-height:560px;overflow:auto;border:1px solid var(--line);border-radius:10px}
  table.ra-table{border-collapse:separate;border-spacing:0;font-size:12px;white-space:nowrap}
  table.ra-table th,table.ra-table td{border-right:1px solid var(--line);border-bottom:1px solid var(--line);padding:5px 8px;background:#fff;text-align:center;vertical-align:middle}
  table.ra-table thead th{position:sticky;top:0;z-index:3;background:#eef4ff;font-weight:600;color:#1e3a5f;box-shadow:0 1px 0 var(--line);text-align:center;vertical-align:middle}
  table.ra-table th.ra-datecol,table.ra-table td.ra-datecol{position:sticky;left:0;z-index:2;background:#f8fafc;font-variant-numeric:tabular-nums;font-weight:600;text-align:center;vertical-align:middle}
  table.ra-table thead th.ra-datecol{z-index:4;background:#e2ecff}
  a.rp-simbtn{display:inline-block;text-decoration:none;font-size:15px;cursor:pointer;padding:2px 6px;border-radius:6px;transition:background .15s}
  a.rp-simbtn:hover{background:#e2ecff}
  table.ra-table td input.ra-cell{width:120px;border:1px solid transparent;background:transparent;font:inherit;padding:2px 4px;border-radius:4px;text-align:center}
  table.ra-table td input.ra-cell:focus{border-color:var(--accent);background:#fff;outline:none}

  /* ===== Overview → Trend ===== */
  .trend-card{border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:18px;background:#fff}
  .trend-h{font-weight:700;font-size:14px;color:#1e3a5f;margin-bottom:10px}
  .trend-ctl{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px;margin-bottom:10px}
  .trend-ctl .fld.inline,.trend-prow .fld.inline{display:flex;flex-direction:column;gap:3px;font-size:12px;color:var(--muted)}
  .trend-ctl .spacer{flex:1 1 auto}
  .trend-ctl .chk{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);white-space:nowrap}
  .trend-rows{display:flex;flex-direction:column;gap:8px;margin-bottom:6px}
  .trend-prow{display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;padding:8px;border:1px dashed var(--line);border-radius:8px;background:#f8fafc}
  .trend-add,.trend-del{min-width:36px;font-weight:700;font-size:15px;padding:6px 10px}
  .trend-maxhint{font-size:11px;color:#b45309;padding:2px 4px}
  /* Change Over Block 1-2 */
  .co-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .co-q{font-weight:700;font-size:13px;color:#0f172a}
  .co-seg .co-yes.active{background:#16a34a;color:#fff}
  .co-seg .co-no.active{background:#dc2626;color:#fff}
  .co-badge{display:inline-block;font-size:11px;font-weight:700;border-radius:20px;padding:2px 12px}
  .co-badge.run{background:#dcfce7;color:#15803d;border:1px solid #86efac}
  .co-badge.stop{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}
  /* ===== Overview Plant — wireframe single-line diagram (redesign) =====
     Presentasi saja: seluruh nilai berasal dari state/output yang sudah ada.
     Tidak ada perhitungan engine yang diulang di UI. */
  .ovp-note{font-size:12px;color:#64748b;margin:0 0 10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .ovp-note b{color:#334155}
  .ovp-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:10px 12px;margin-bottom:12px;
    border:1px solid var(--card-line);border-radius:12px;background:var(--field)}
  .ovp-toolbar .ovp-tl{font-size:9.5px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--ink-dim)}
  .ovp-seg{display:inline-flex;border:1px solid var(--field-line);border-radius:9px;overflow:hidden;background:#fff}
  .ovp-seg button{border:0;background:transparent;font-family:var(--sans);font-size:11.5px;font-weight:700;
    padding:6px 11px;cursor:pointer;color:var(--ink-soft);white-space:nowrap;transition:background .15s,color .15s}
  .ovp-seg button+button{border-left:1px solid var(--field-line)}
  .ovp-seg button:hover{background:var(--accent-soft);color:var(--accent)}
  .ovp-seg button:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
  .ovp-seg button.on{background:var(--accent);color:#fff}
  .ovp-toolbar .ovp-chk{display:inline-flex;align-items:center;gap:7px;font-size:11.5px;color:var(--ink-soft);font-weight:650;cursor:pointer}
  .ovp-toolbar .ovp-right{margin-left:auto;display:flex;gap:6px;flex-wrap:nowrap}
  .ovp-toolbar .btn{white-space:nowrap}
  @media (max-width:1180px){.ovp-toolbar .ovp-right{margin-left:0;flex-basis:100%}}
  .ovp-viewport{overflow:auto;border:1px solid var(--card-line);border-radius:12px;background:#fff;padding:14px}
  .ovp-stage{transform-origin:0 0;transition:transform .18s ease;width:max-content;min-width:100%}
  .ovp-wrap{display:flex;gap:18px;align-items:stretch}
  .ovp-blocks{flex:1 1 auto;display:flex;flex-direction:column;gap:14px;min-width:0}
  .ovp-block{border:1px solid var(--card-line);border-radius:12px;background:#fff;padding:12px 14px}
  .ovp-block.ovp-hide{display:none}
  .ovp-bh{font-weight:800;font-size:12.5px;color:var(--ink);display:flex;align-items:baseline;gap:10px;margin-bottom:10px}
  .ovp-bt{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--ink-dim)}
  .ovp-bmw{margin-left:auto;font-family:var(--mono);font-size:14px;font-weight:800;color:var(--accent);font-variant-numeric:tabular-nums}
  .ovp-cc{display:flex;gap:10px;align-items:stretch}
  .ovp-chains{display:flex;flex-direction:column;gap:8px;flex:1 1 auto;min-width:0}
  .ovp-row{display:flex;align-items:center;gap:6px}
  .ovp-stgcol{display:flex;align-items:center;padding-left:6px;border-left:2px dashed #cbd5e1;margin-left:2px}
  .ovp-grow{display:flex;gap:10px;flex-wrap:wrap}
  .ovp-unit{border:1px solid var(--card-line);border-radius:10px;background:#fff;padding:8px 11px;
    text-align:center;min-width:138px;cursor:pointer;position:relative;
    transition:border-color .15s,box-shadow .15s,opacity .15s,transform .15s}
  .ovp-unit:hover{border-color:var(--accent-line);box-shadow:0 4px 14px rgba(23,99,214,.12);transform:translateY(-1px)}
  .ovp-unit:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
  .ovp-unit.ovp-gtg{border-top:3px solid #0ea5e9}
  .ovp-unit.ovp-hrsg{border-top:3px solid #7c3aed;background:#fdfcff;border-style:dashed}
  .ovp-unit.ovp-stg{border-top:3px solid #10b981}
  .ovp-unit.ovp-ge{border-top:3px solid #f97316}
  .ovp-unit.ovp-pltu{border-top:3px solid #64748b}
  .ovp-unit.ovp-off{opacity:.55}
  .ovp-unit.ovp-hide{display:none}
  .ovp-stage.ovp-focused .ovp-unit:not(.ovp-on){opacity:.22;filter:grayscale(.6)}
  .ovp-stage.ovp-focused .ovp-arrow:not(.ovp-on){opacity:.18}
  .ovp-stage.ovp-focused .ovp-rail:not(.ovp-on){opacity:.3}
  .ovp-unit.ovp-on{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
  .ovp-un{font-weight:800;font-size:12px;color:var(--ink);display:flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}
  .ovp-ut{font-size:9.5px;color:var(--ink-dim);letter-spacing:.6px;text-transform:uppercase;margin-top:1px}
  .ovp-chip{font-size:9px;font-weight:800;letter-spacing:.4px;border-radius:5px;padding:1px 5px;white-space:nowrap;
    background:var(--field);border:1px solid var(--field-line);color:var(--ink-soft)}
  .ovp-chip.busA{background:#fdf3e0;border-color:#eccb92;color:#8a5800}
  .ovp-chip.busB{background:#e9f1fd;border-color:#b9d2f4;color:#1f5bb5}
  .ovp-mw{font-family:var(--mono);font-size:17px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;margin-top:3px;line-height:1.15}
  .ovp-mw small{font-size:9.5px;font-weight:700;color:var(--ink-dim);margin-left:2px}
  .ovp-st{display:inline-flex;align-items:center;gap:5px;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin-top:3px}
  .ovp-st .d{width:7px;height:7px;border-radius:50%;display:inline-block}
  .ovp-st.on{color:#0a7b6b}.ovp-st.on .d{background:var(--ok)}
  .ovp-st.off{color:#8593ad}.ovp-st.off .d{background:#c2cde0}
  .ovp-st.na{color:#8a6d3b}.ovp-st.na .d{background:#e0b062}
  .ovp-bar{height:3px;border-radius:99px;background:var(--field);border:1px solid var(--field-line);margin-top:6px;overflow:hidden}
  .ovp-bar i{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
  .ovp-steam{font-size:9.5px;color:#7c3aed;letter-spacing:.4px;text-transform:uppercase;font-weight:700}
  .ovp-na{font-size:9px;color:var(--ink-dim);letter-spacing:.3px;margin-top:2px}
  .ovp-arrow{color:#94a3b8;display:flex;align-items:center;flex:0 0 auto;transition:opacity .15s}
  .ovp-arrow.dash{color:#c4b5fd}
  .ovp-arrow.ovp-on{color:var(--accent)}
  .ovp-tap{margin-top:10px;font-size:10px;color:var(--ink-soft);display:flex;align-items:center;gap:6px;
    letter-spacing:.5px;text-transform:uppercase;font-weight:700}
  .ovp-tap .dot{width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);display:inline-block}
  .ovp-rail{flex:0 0 168px;display:flex;flex-direction:column;align-items:center;padding-top:6px;transition:opacity .15s}
  .ovp-busbar{writing-mode:vertical-rl;text-orientation:mixed;background:#16233c;color:#fff;font-size:10px;
    font-weight:800;letter-spacing:1.6px;border-radius:8px;padding:16px 8px}
  .ovp-trunk{width:3px;flex:1 1 40px;min-height:26px;background:linear-gradient(#16233c,#52617d)}
  .ovp-trunk.short{flex:0 0 24px;min-height:24px}
  .ovp-node{width:100%;border:1px solid var(--card-line);border-radius:10px;background:#fff;padding:10px 12px;text-align:center}
  .ovp-node.ie{border-top:3px solid #7a3fb0}
  .ovp-node.exp{border-top:3px solid var(--ok)}
  .ovp-node .nl{font-size:9.5px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--ink-dim)}
  .ovp-node .nv{font-family:var(--mono);font-size:19px;font-weight:800;color:var(--ink);font-variant-numeric:tabular-nums;line-height:1.2}
  .ovp-node .nv small{font-size:10px;color:var(--ink-dim);font-weight:700}
  .ovp-node .ns{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin-top:4px;
    border-radius:5px;padding:2px 6px;display:inline-block}
  .ovp-node .ns.ok{background:var(--ok-bg);color:#0a7b6b;border:1px solid var(--ok-line)}
  .ovp-node .ns.warn{background:var(--warn-bg);color:#8a5800;border:1px solid var(--warn-line)}
  .ovp-node .ns.na{background:var(--field);color:var(--ink-dim);border:1px solid var(--field-line)}
  .ovp-side{width:100%;margin-top:12px;border:1px solid var(--card-line);border-radius:10px;background:#fff;padding:9px 11px;
    display:flex;flex-direction:column;gap:5px}
  .ovp-side div{display:flex;justify-content:space-between;gap:10px;font-size:11px}
  .ovp-side .k{color:var(--ink-soft)}
  .ovp-side .v{font-family:var(--mono);font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}
  .ovp-side .v.na{font-family:var(--sans);font-size:9.5px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:var(--ink-dim)}
  .ovp-legend{display:flex;flex-wrap:wrap;gap:16px;margin-top:12px;font-size:11px;color:var(--ink-soft);align-items:center}
  .ovp-legend .sw{display:inline-block;width:14px;height:8px;border-radius:2px;margin-right:5px;vertical-align:middle}
  .ovp-mapnote{margin-top:12px;border:1px solid var(--card-line);border-radius:10px;background:var(--field);
    padding:10px 13px;font-size:11.5px;color:var(--ink-soft);line-height:1.6}
  .ovp-mapnote b{color:var(--ink)}
  .ovp-mapnote code{font-family:var(--mono);font-size:11px;background:#fff;border:1px solid var(--field-line);
    border-radius:4px;padding:1px 5px}
  /* Full Screen: kartu diagram menutupi viewport (pola yang sama dengan #simfs-modal) */
  body.ovp-fs-on .card:has(#ov-plant-diagram){position:fixed;inset:0;z-index:10000;margin:0;border-radius:0;
    overflow:auto;box-shadow:none}
  body.ovp-fs-on #ov-plant-diagram .ovp-viewport{max-height:calc(100vh - 250px)}
  @media (max-width:900px){
    .ovp-wrap{flex-direction:column}
    .ovp-rail{flex-direction:row;flex:0 0 auto;width:100%;gap:10px;align-items:stretch;flex-wrap:wrap}
    .ovp-busbar{writing-mode:horizontal-tb;padding:8px 16px}
    .ovp-trunk{height:3px;width:auto;flex:1 1 20px;min-height:0;align-self:center}
    .ovp-node{width:auto;flex:1 1 140px}
    .ovp-side{margin-top:0;flex:1 1 100%}
    .ovp-cc{flex-direction:column}
    .ovp-stgcol{border-left:0;border-top:2px dashed #cbd5e1;padding-left:0;padding-top:8px;margin-left:0}
  }
  /* ===== Summary dashboard cards (grouped by category) ===== */
  #ov-summary-cards{display:flex;flex-direction:column;gap:18px}
  .summ-group{border:1px solid var(--line);border-radius:14px;background:linear-gradient(180deg,#fff,#fafbfc);box-shadow:0 1px 3px rgba(15,23,42,.05);padding:12px 14px 14px}
  .summ-gt{margin:0 0 10px;font-size:12px;font-weight:800;letter-spacing:.8px;color:#334155;display:flex;align-items:center;gap:8px}
  .summ-gt::before{content:"";width:10px;height:10px;border-radius:3px;background:#94a3b8}
  .summ-group.cat-general .summ-gt::before{background:#3b82f6}
  .summ-group.cat-eff .summ-gt::before{background:#10b981}
  .summ-group.cat-cost .summ-gt::before{background:#8b5cf6}
  .summ-group.cat-fuel .summ-gt::before{background:#f97316}
  .summ-group.cat-pgn .summ-gt::before{background:#0ea5e9}
  .summ-group.cat-pep .summ-gt::before{background:#e11d48}
  .summ-group.cat-akasia .summ-gt::before{background:#d97706}
  .summ-group.cat-bags .summ-gt::before{background:#7c3aed}
  .summ-group.cat-babelan .summ-gt::before{background:#64748b}
  .summ-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
  .summ-card{border:1px solid var(--line);border-left-width:4px;border-radius:12px;padding:12px 14px;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.05)}
  .summ-card .sc-l{font-size:10.5px;font-weight:600;letter-spacing:.4px;color:#64748b;margin-bottom:6px;line-height:1.3;min-height:27px;text-transform:uppercase}
  .summ-card .sc-v{font-size:19px;font-weight:800;color:#0f172a}
  .summ-card .sc-u{font-size:11px;font-weight:500;color:#94a3b8}
  .summ-card.cat-general{border-left-color:#3b82f6}
  .summ-card.cat-eff{border-left-color:#10b981}
  .summ-card.cat-cost{border-left-color:#8b5cf6}
  .summ-card.cat-fuel{border-left-color:#f97316}
  .summ-card.cat-pgn{border-left-color:#0ea5e9}
  .summ-card.cat-pep{border-left-color:#e11d48}
  .summ-card.cat-akasia{border-left-color:#d97706}
  .summ-card.cat-bags{border-left-color:#7c3aed}
  .summ-card.cat-babelan{border-left-color:#64748b}
  .trend-chart{margin-top:8px;border:1px solid var(--line);border-radius:8px;background:#fff;overflow-x:auto}
  .trend-empty{padding:40px 16px;text-align:center;color:#94a3b8;font-size:13px}
  .trend-empty.warn{color:#b45309}
  .trend-legend{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;margin-top:10px}
  .lg-item{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#334155}
  .lg-swatch{width:14px;height:3px;border-radius:2px;display:inline-block}
  .seg{display:inline-flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
  .seg-opt{border:0;background:#fff;padding:7px 12px;font-size:12px;cursor:pointer;color:#475569}
  .seg-opt.active{background:var(--accent,#2563eb);color:#fff}

  /* ===== child-tabs (horizontal pills) ===== */
  .childtabs{display:flex;gap:7px;flex-wrap:wrap;margin:0 0 16px;padding-bottom:13px;border-bottom:1px solid var(--card-line)}
  .childtab{appearance:none;border:1px solid var(--field-line);background:#fff;cursor:pointer;font-family:var(--sans);font-weight:700;
    font-size:12px;color:var(--ink-soft);padding:8px 15px;border-radius:999px;transition:all .15s ease;white-space:nowrap;box-shadow:var(--shadow-sm)}
  .childtab:hover{border-color:var(--accent-line);color:var(--accent);background:var(--accent-soft)}
  .childtab.active{background:var(--accent-soft);border-color:var(--accent);color:var(--accent);box-shadow:0 0 0 3px rgba(23,99,214,.10)}
  .childpanel{display:none}
  .childpanel.active{display:block;animation:fadeUp .2s ease}

  /* ===== modern form cards ===== */
  .fcard{border:1px solid var(--card-line);border-radius:14px;background:linear-gradient(180deg,#fff,#fbfdff);box-shadow:var(--shadow-sm);
    margin-bottom:16px;overflow:hidden}
  .fcard>.fh{display:flex;align-items:center;gap:11px;padding:13px 18px;border-bottom:1px solid var(--card-line);
    background:linear-gradient(180deg,#f5f9fe,#eef4fc)}
  .fcard>.fh .ft{font-weight:800;font-size:13px;color:var(--ink);letter-spacing:-.01em}
  .fcard>.fh .fi{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;font-size:14px;box-shadow:0 4px 10px rgba(23,99,214,.25)}
  .fcard>.fh .fhint{margin-left:auto;font-size:11px;color:var(--ink-dim);text-align:right}
  .fcard>.fb{padding:18px}

  /* ===== polished accordions inside child panels ===== */
  details.acc{border:1px solid var(--card-line);border-radius:13px;background:#fff;box-shadow:var(--shadow-sm);margin-bottom:13px;overflow:hidden}
  details.acc>summary{list-style:none;cursor:pointer;padding:13px 17px;font-weight:800;font-size:12.5px;color:var(--ink);
    display:flex;align-items:center;gap:10px;background:linear-gradient(180deg,#f7fbff,#eff5fd);transition:background .15s}
  details.acc>summary::-webkit-details-marker{display:none}
  details.acc>summary:hover{background:#eaf2fc}
  details.acc>summary .achint{font-weight:500;color:var(--ink-dim);font-size:11px}
  details.acc>summary .accv{margin-left:auto;color:var(--accent);transition:transform .2s;font-size:11px}
  details.acc[open]>summary .accv{transform:rotate(90deg)}
  details.acc .accb{padding:16px 17px}

  /* ===== result toolbar ===== */
  .res-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;padding:12px 15px;border:1px solid var(--card-line);
    border-radius:13px;background:linear-gradient(180deg,#fff,#fafcff);box-shadow:var(--shadow-sm)}
  .img-partial{display:inline-flex;align-items:center;gap:8px;padding:5px 8px;border:1px dashed var(--card-line);border-radius:11px;background:#f7fafe}
  .img-sf{display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:700;color:var(--ink-soft);white-space:nowrap}
  .img-sf select{width:auto;min-width:96px;padding:7px 26px 7px 10px;font-size:12px}

  /* Gas KP72 required banner + input highlight (Revisi) */
  .kp72-warn{border:1px solid var(--bad-line);background:var(--bad-bg);color:#9a2530;border-radius:12px;padding:12px 15px;
    margin-bottom:14px;font-size:12.5px;font-weight:600;line-height:1.5;box-shadow:var(--shadow-sm)}
  input.kp72hl{border-color:var(--bad-line) !important;background:#fff4f5 !important;
    box-shadow:0 0 0 3px rgba(178,47,55,.16) !important;animation:kp72pulse 1s ease 2}
  @keyframes kp72pulse{0%,100%{box-shadow:0 0 0 3px rgba(178,47,55,.16)}50%{box-shadow:0 0 0 6px rgba(178,47,55,.28)}}

  /* ===== PLN export priority: values + aligned required-toggle stack ===== */
  .pln-layout{display:grid;grid-template-columns:1fr 230px;gap:18px;align-items:start}
  @media(max-width:820px){.pln-layout{grid-template-columns:1fr}}
  .pln-reqs{border:1px solid var(--card-line);border-radius:13px;background:linear-gradient(180deg,#f7fbff,#eff5fd);padding:14px;display:flex;flex-direction:column;gap:10px}
  .pln-reqs .reqhead{font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--ink-dim);margin-bottom:2px}
  /* multi dispatch-deviation rule rows */
  .dd-rule{display:grid;grid-template-columns:26px 1fr 1fr 1fr 1fr 32px;gap:10px;align-items:end;margin-bottom:10px;
    padding:10px;border:1px solid var(--card-line);border-radius:11px;background:#fafcff}
  .dd-rule .dd-no{align-self:center;font-family:var(--mono);font-weight:800;color:var(--accent);text-align:center}
  @media(max-width:760px){.dd-rule{grid-template-columns:1fr 1fr;}.dd-rule .dd-no{grid-column:1/-1;text-align:left}}
  .toggle{display:flex;align-items:center;gap:11px;cursor:pointer;padding:9px 11px;border:1px solid var(--field-line);border-radius:11px;background:#fff;transition:all .15s}
  .toggle:hover{border-color:var(--accent-line)}
  .toggle input{position:absolute;opacity:0;width:0;height:0}
  .toggle .tk{flex:0 0 auto;width:38px;height:21px;border-radius:999px;background:#cdd8e6;position:relative;transition:background .18s}
  .toggle .tk::after{content:"";position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .18s}
  .toggle input:checked+.tk{background:linear-gradient(135deg,var(--accent),var(--accent-2))}
  .toggle input:checked+.tk::after{transform:translateX(17px)}
  .toggle .tl{font-weight:700;font-size:12.5px;color:var(--ink);display:flex;flex-direction:column;line-height:1.25}
  .toggle .tl small{font-weight:600;font-size:10px;color:var(--ink-dim)}
  .toggle.is-locked{opacity:.95;cursor:not-allowed;background:var(--accent-soft);border-color:var(--accent-line)}

  /* ===== modern Summary / Comparison tables ===== */
  .sumwrap{border:1px solid var(--card-line);border-radius:14px;overflow:auto;box-shadow:var(--shadow-sm);background:#fff}
  /* V11 SUMMARY kuning: tepat lima field; detail teknis di panel audit terpisah */
  .v11-sum{display:flex;flex-wrap:wrap;gap:6px 22px;margin:0 0 8px;padding:10px 14px;border-radius:10px;background:#fef3c7;border:1px solid #f59e0b;color:#78350f;font-size:13px}
  .v11-sum-c{display:flex;flex-direction:column;min-width:120px}
  .v11-sum-l{font-size:11px;opacity:.8}
  .v11-sum-v{font-size:14px}
  .v11-audit{margin:0 0 10px;padding:6px 12px;border:1px solid var(--card-line);border-radius:10px;background:var(--card);font-size:12px;color:var(--ink-soft)}
  .v11-audit>summary{cursor:pointer;font-weight:600;color:var(--ink)}
  .v11-audit[data-status="green"]>summary{color:#166534}
  .v11-audit[data-status="amber"]>summary{color:#b45309}
  .v11-audit-b{margin-top:6px;line-height:1.6;overflow-wrap:anywhere}
  table.sumtbl{border-collapse:separate;border-spacing:0;width:100%;font-size:13px}
  table.sumtbl thead th{position:sticky;top:0;z-index:1;background:linear-gradient(180deg,#f4f8fe,#eaf1fb);color:#46566f;
    font-size:10px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;text-align:left;padding:11px 16px;border-bottom:1px solid #dde7f4;white-space:nowrap}
  table.sumtbl thead th.r{text-align:right}
  table.sumtbl thead th.c{text-align:center;width:54px}
  table.sumtbl tbody td{padding:10px 16px;border-bottom:1px solid #eef2f8}
  table.sumtbl tbody tr:nth-child(even) td{background:#f9fbfe}
  table.sumtbl tbody tr:hover td{background:#eef5ff}
  table.sumtbl tbody tr:last-child td{border-bottom:0}
  table.sumtbl td.c{text-align:center;font-family:var(--mono);color:var(--accent);font-weight:700;width:54px}
  table.sumtbl td.p{text-align:left;font-weight:700;color:var(--ink);letter-spacing:.2px}
  table.sumtbl td.r{text-align:right;font-family:var(--mono);font-weight:700;color:var(--ink);font-variant-numeric:tabular-nums}
  table.sumtbl td.u{color:var(--ink-dim);font-size:11.5px;white-space:nowrap}
  table.sumtbl.cmptbl thead th,table.sumtbl.cmptbl tbody td{white-space:nowrap}

.fld select.mm-mode{width:100%;height:34px;padding:6px 8px;border:1px solid #d5dbe3;border-radius:8px;box-sizing:border-box;font:inherit;margin-top:2px}
.mm-mode.mm-cum{background:#c8f7c5;border-color:#31c48d;color:#0b6b45;font-weight:700}
.mm-mode.mm-fix{background:#cfe8ff;border-color:#3b82f6;color:#1e40af;font-weight:600}

/* CSV IE & Dispatch — status & preview (memakai design token aplikasi) */
.csv-status{margin-top:8px;padding:7px 11px;border-radius:8px;font-size:12px;font-weight:600;line-height:1.45}
.csv-status.ok{background:var(--ok-bg,#e3f8f2);border:1px solid var(--ok-line,#aee6d7);color:var(--ok,#0e7d6d)}
.csv-status.bad{background:var(--bad-bg,#fdecea);border:1px solid var(--bad,#d9534f);color:var(--bad,#c0392b)}
.csv-preview{margin-top:8px;padding:9px 11px;border:1px solid var(--card-line,#e2e8f0);border-radius:8px;
  background:var(--card,#fff);font-size:12px;max-height:260px;overflow:auto}
table.csvprev{border-collapse:collapse;font-size:11.5px;margin-top:2px}
table.csvprev th,table.csvprev td{border:1px solid var(--card-line,#e2e8f0);padding:2px 7px;text-align:right}
table.csvprev th{background:var(--field,#f3f7fb);text-align:center}
table.csvprev td:nth-child(2){text-align:left;font-variant-numeric:tabular-nums}
ul.csverr{margin:6px 0 0 16px;color:var(--bad,#c0392b)}
ul.csverr li{margin:2px 0}
</style>
</head>
<body class="layout">
<div class="wrapper">

  <!-- ===== top navbar ===== -->
  <header class="main-header navbar">
    <button class="nav-toggle" id="sb-toggle" aria-label="Toggle sidebar">
      <span></span><span></span><span></span>
    </button>
    <div class="navbar-brand">
      <span class="k">Dispatch Optimizer</span>
      <span class="t">Daily Plan Simulation</span>
    </div>
    <div class="navbar-status">
      <span class="pill" id="pill-plan"><span class="dot"></span><span id="pill-plan-t">—</span></span>
      <span class="pill" id="pill-pln"><span class="dot"></span>PLN Export <span id="pill-pln-t">—</span></span>
      <span class="pill" id="pill-gas"><span class="dot"></span>Gas <span id="pill-gas-t">—</span></span>
      <span class="pill" id="pill-cost"><span class="dot"></span><span id="pill-cost-t">Cost —</span></span>
    </div>
  </header>

  <!-- ===== left sidebar ===== -->
  <aside class="main-sidebar">
    <div class="sidebar-brand">
      <span class="logo-mark">⚡</span>
      <span class="logo-txt">Daily Plan Simulation</span>
    </div>
    <nav class="nav-sidebar" role="tablist">
      <div class="nav-head">Planning</div>
      <button class="tab nav-link" role="tab" data-tab="overview" aria-selected="false"><span class="ico">◧</span>Overview</button>
      <button class="tab nav-link" role="tab" data-tab="daily" aria-selected="true"><span class="ico">▣</span>Daily Plan</button>
      <button class="tab nav-link" role="tab" data-tab="weekly" aria-selected="false"><span class="ico">▤</span>Weekly Plan</button>
      <button class="tab nav-link" role="tab" data-tab="monthly" aria-selected="false"><span class="ico">▦</span>Monthly Plan</button>
      <button class="tab nav-link" role="tab" data-tab="report" aria-selected="false"><span class="ico">▥</span>Report</button>
    </nav>
    <div class="sidebar-foot">Daily Plan Simulation</div>
  </aside>

  <!-- ===== content ===== -->
  <div class="content-wrapper">
  <main>
  <!-- ============================ OVERVIEW ============================ -->
  <section class="panel" id="panel-overview" role="tabpanel">
    <!-- Overview sub-tabs (Overview Plant | Trend | Weekly KPI Achievement) -->
    <div class="subtabs" role="tablist" aria-label="Overview sections">
      <button type="button" class="subtab active" role="tab" data-osub="plant"><span class="n">1</span>Overview Plant</button>
      <button type="button" class="subtab" role="tab" data-osub="trend"><span class="n">2</span>Trend</button>
      <button type="button" class="subtab" role="tab" data-osub="wkpi"><span class="n">3</span>Weekly KPI Achievement</button>
    </div>

    <!-- ===== Overview → Overview Plant (modern plant diagram + summary cards) ===== -->
    <section class="subpanel active" id="ov-plant" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Plant</span>&nbsp;Plant diagram — blocks, units, IE &amp; PLN Export</div>
        <div class="card-body">
          <p class="sec-note">Single-line view of the fleet: blok CCGT (GTG &rarr; HRSG &rarr; STG), Gas Engine, Babelan, dan Standby, semuanya bermuara ke IE Bus lalu PLN Export. Tiap kartu unit menampilkan Bus, load MW, dan status dari slot Daily Plan terdekat. Klik unit untuk menyorot jalurnya; filter blok/status, Fit to View, dan Full Screen tersedia di toolbar. Nilai yang tidak ada sumbernya ditandai <b>data unavailable</b>, bukan nol. GTG → HRSG → STG within each CCGT block; only load-bearing units (GTG, STG, Gas Engine, Babelan) connect to IE / PLN Export — HRSG does not.</p>
          <div id="ov-plant-diagram"></div>
        </div>
      </div>
      <div class="card"><div class="card-h"><span class="eyebrow">Summary</span>&nbsp;Latest simulation summary</div>
        <div class="card-body">
          <div id="ov-summary-cards" class="summ-grid"></div>
        </div>
      </div>
      <!-- kept for refreshOverview() compatibility (existing latest-run KPI + intraday trend), hidden here -->
      <div style="display:none"><div class="kpis" id="ov-kpis"></div><div id="ov-trend"></div></div>
    </section>

    <!-- ===== Overview → Trend (3 identical trend charts) ===== -->
    <section class="subpanel" id="ov-trend-tab" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Trend</span>&nbsp;Actual &amp; Planning parameter trends</div>
        <div class="card-body">
          <p class="sec-note">Each trend plots one or more parameters over a date range. <b>Actual</b> series come from Report → Actual → Input Data; <b>Planning</b> series from Report → Planning. Add parameters with <b>+</b>. Line-with-marker, legend below, optional data labels, and merged or per-series Y-axes.</p>
          <div id="trend-cards"></div>
        </div>
      </div>
    </section>

    <!-- ===== Overview → Weekly KPI Achievement (placeholder) ===== -->
    <section class="subpanel" id="ov-wkpi" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Weekly KPI</span>&nbsp;Achievement</div>
        <div class="card-body"><div class="placeholder"><h3>Weekly KPI Achievement</h3>
          <p>Weekly KPI Achievement content will be configured later.</p></div></div>
      </div>
    </section>
  </section>

  <!-- ============================ DAILY PLAN ============================ -->
  <section class="panel active" id="panel-daily" role="tabpanel">

    <?php if(!$haveInput): ?>
      <div class="placeholder"><h3>input_data.json not found or invalid</h3>
      <p>Place a valid <code>input_data.json</code> beside these PHP files and reload.</p></div>
    <?php else: ?>

    <!-- ========== Daily Plan sub-tabs ========== -->
    <!-- PROMPT MONITORING DAILY PLAN §2.1: Daily Plan branch -> Plan | Monitoring Daily Plan.
         Kedua cabang me-render UI Daily Plan yang SAMA PERSIS (semua subtab/fitur tetap);
         cabang Monitoring menambah kolom TIME PASSED + Name Plan format Monitoring + locking. -->
    <div class="dp-branch" role="tablist" aria-label="Daily Plan branch">
      <button type="button" class="dpb active" id="dpb-plan" role="tab"><span class="dot"></span>Plan</button>
      <button type="button" class="dpb" id="dpb-mon" role="tab"><span class="dot"></span>Monitoring Daily Plan</button>
    </div>
    <div class="subtabs" role="tablist" aria-label="Daily Plan sections">
      <button type="button" class="subtab" role="tab" data-sub="periodic"><span class="n">1</span>Periodic Input</button>
      <button type="button" class="subtab active" role="tab" data-sub="frequent"><span class="n">2</span>Frequently Input</button>
      <button type="button" class="subtab" role="tab" data-sub="result"><span class="n">3</span>Result</button>
    </div>

    <!-- ========== PERIODIC INPUT ========== -->
    <section class="subpanel" id="sp-periodic" role="tabpanel">
      <p class="sec-note">Settings that change rarely. Edit only when plant configuration, tariffs, or bus assignments change.</p>
      <div class="childtabs" role="tablist">
        <button type="button" class="childtab active" data-child="periodic" data-cp="cp-unitchar">Unit Characteristic</button>
        <button type="button" class="childtab" data-child="periodic" data-cp="cp-fuelprice">Fuel Price</button>
        <button type="button" class="childtab" data-child="periodic" data-cp="cp-bus">Bus Status</button>
        <button type="button" class="childtab" data-child="periodic" data-cp="cp-priority">Unit Priority</button>
        <button type="button" class="childtab" data-child="periodic" data-cp="cp-emission">Emission Factor</button>
        <button type="button" class="childtab" data-child="periodic" data-cp="cp-runtime">Runtime &amp; Downtime</button>
      </div>

      <!-- A. Unit Characteristic -->
      <div class="childpanel active" data-child="periodic" id="cp-unitchar">
        <details class="acc" open><summary><span>Presence &amp; load limits</span><span class="achint">per unit</span><span class="accv">▶</span></summary>
          <div class="accb"><div class="scroll" style="max-height:360px"><table class="mini" id="tbl-units"></table></div></div></details>
        <!-- Time-based Maximum Load Adjustment panel relocated to Frequently Input → Unit Status & Adjustments (Revisi UI Sec.1) -->
        <details class="acc" open><summary><span>Fuel formula coefficients</span><span class="achint">GTG 1–10 &amp; Gas Engine 1–4</span><span class="accv">▶</span></summary>
          <div class="accb"><div class="uc-cats" id="uc-gas"></div></div></details>
        <details class="acc"><summary><span>STG load characteristic</span><span class="achint">format 1-1-1 / 2-2-1 / 3-3-1 · After MW · S1/S2 Format 3-3-1 = 3 segments</span><span class="accv">▶</span></summary>
          <div class="accb"><div class="uc-tabs" id="uc-stg-tabs"></div><div id="uc-stg-body"></div></div></details>
        <!-- Advanced JSON — raw unit coefficients: hidden from UI (Revisi Sec.1). Kept in DOM only;
             it carries extra fields (presence, STG links, HRSG map) read on Run. Structured inputs above take precedence. -->
        <div hidden aria-hidden="true"><textarea id="ta-data3" spellcheck="false"></textarea></div>
      </div>

      <!-- B. Fuel Price -->
      <div class="childpanel" data-child="periodic" id="cp-fuelprice">
        <div class="fcard"><div class="fh"><span class="fi">＄</span><span class="ft">FUEL PRICE</span><span class="fhint">USD/MMBTU · coal &amp; biomass USD/ton · distillate USD/Liter</span></div>
          <div class="fb"><div class="grid g3" id="grid-price"></div></div></div>
      </div>

      <!-- C. Bus Status -->
      <div class="childpanel" data-child="periodic" id="cp-bus">
        <div class="fcard"><div class="fh"><span class="fi">⇄</span><span class="ft">Bus status</span><span class="fhint">A / B per unit</span></div>
          <div class="fb"><div class="scroll" style="max-height:300px"><table class="mini" id="tbl-bus"></table></div>
            <div class="legend"><span><i class="busA" style="background:var(--busA)"></i>Bus A</span><span><i style="background:var(--busB)"></i>Bus B</span><span>Bus Flow = IE data1 − ΣBus B</span></div></div></div>
      </div>

      <!-- D. Unit Priority -->
      <div class="childpanel" data-child="periodic" id="cp-priority">
        <details class="acc" open><summary><span>Block priority</span><span class="achint">priority 1 → 7; a block picked once disappears from later slots</span><span class="accv">▶</span></summary>
          <div class="accb"><table class="prio" id="tbl-block-prio"></table></div></details>
        <details class="acc"><summary><span>Unit priority</span><span class="achint">order units inside each block; STG attaches automatically</span><span class="accv">▶</span></summary>
          <div class="accb"><div id="up-blocks"></div></div></details>
        <details class="acc"><summary><span>Unit priority — distillate</span><span class="achint">GTG only</span><span class="accv">▶</span></summary>
          <div class="accb"><div id="dist-blocks"></div></div></details>
        <!-- Required & Unit Cannot Stop panel relocated to Frequently Input → Unit Status & Adjustments (Revisi UI Sec.3) -->
        <!-- Advanced JSON — raw priority arrays: hidden from UI (Revisi Sec.3). Kept in DOM only as a
             read-only mirror target for prefill; the structured editors above are the source of truth on Run. -->
        <div hidden aria-hidden="true">
          <textarea id="ta-block_priority" spellcheck="false"></textarea>
          <textarea id="ta-unit_priority" spellcheck="false"></textarea>
          <textarea id="ta-unit_priority_dist" spellcheck="false"></textarea>
        </div>
      </div>

      <!-- E. Emission Factor -->
      <div class="childpanel" data-child="periodic" id="cp-emission">
        <div class="fcard"><div class="fh"><span class="fi">☁</span><span class="ft">EMISSION FACTOR</span><span class="fhint">gas / distillate / coal carbon</span></div>
          <div class="fb"><div class="grid g3" id="grid-emission"></div></div></div>
      </div>

      <!-- F2. Runtime & Downtime -->
      <div class="childpanel" data-child="periodic" id="cp-runtime">
        <div class="fcard"><div class="fh"><span class="fi">⏱</span><span class="ft">RUNTIME &amp; DOWNTIME</span><span class="fhint">minimum on/off durations used by anti start-stop</span></div>
          <div class="fb">
            <div class="sched-wrap"><table class="sched" id="tbl-runtime"></table></div>
            <div class="hint" style="margin-top:8px">Minimum runtime keeps a unit online long enough after start; minimum downtime keeps it off long enough before restart. Large units (G7–G10) and combined-cycle blocks use the stricter values to avoid stop-start within the day.</div>
          </div></div>
      </div>
    </section>

    <!-- ========== FREQUENTLY INPUT ========== -->
    <section class="subpanel active" id="sp-frequent" role="tabpanel">
      <div class="childtabs" role="tablist">
        <button type="button" class="childtab active" data-child="frequent" data-cp="cp-nameplan">Name Plan &amp; IE/Dispatch</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-pln">PLN Export Priority</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-sr">SR &amp; Bus Flow</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-gas">Gas Data</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-stgstartup">STG Start Up Mode</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-babelan">Babelan &amp; Biomass</button>
        <button type="button" class="childtab" data-child="frequent" data-cp="cp-unitstatus">Unit Status &amp; Adjustments</button>
      </div>

      <!-- A. Name Plan & IE/Dispatch -->
      <div class="childpanel active" data-child="frequent" id="cp-nameplan">
        <div class="fcard"><div class="fh"><span class="fi">▣</span><span class="ft">Plan basics</span><span class="fhint">name &amp; baseline load</span></div>
          <div class="fb"><div class="grid g3">
            <label class="fld">Name plan<input type="text" id="f-name_plan" readonly title="Auto-generated from Plan date + Remark: Daily Plan dd-MMM-yy [Remark]" style="background:#f1f5f9;color:#334155;cursor:not-allowed"></label>
            <label class="fld">Plan date<input type="date" id="f-plan_date"></label>
            <label class="fld">Remark<input type="text" id="f-plan_remark" placeholder="e.g. Rev 01" maxlength="60"></label>
          </div>
          <input type="hidden" id="f-house_load"></div></div>
        <div class="fcard"><div class="fh"><span class="fi">▤</span><span class="ft">IE prediction &amp; dispatch</span><span class="fhint">48 half-hour rows</span></div>
          <div class="fb">
            <div class="runbar no-print">
              <label class="btn ghost" style="cursor:pointer">Import CSV…<input type="file" id="csv-file" accept=".csv,text/csv" hidden></label>
              <button type="button" class="btn ghost" id="csv-validate" title="Validasi ulang CSV terakhir tanpa menerapkannya">Validate</button>
              <button type="button" class="btn" id="csv-apply" disabled title="Terapkan CSV valid ke 48 slot">Apply</button>
              <button type="button" class="btn ghost" id="csv-clear" title="Bersihkan preview (data existing tidak diubah)">Clear</button>
              <button type="button" class="btn ghost" id="csv-reset" title="Kembalikan IE/Dispatch ke kondisi sebelum Apply">Reset</button>
              <div id="csv-status" class="csv-status" style="display:none"></div>
              <div id="csv-preview" class="csv-preview" style="display:none"></div>
              <button class="btn ghost" id="btn-paste">Paste from clipboard</button>
              <span class="hint">CSV columns: <code>time,ie,dispatch</code> or two columns <code>ie,dispatch</code>.</span>
              <span class="spacer"></span><span class="hint" id="ied-sum"></span>
            </div>
            <div class="scroll" style="max-height:380px"><table class="data" id="tbl-ied"></table></div>
          </div></div>
        <div class="fcard"><div class="fh"><span class="fi">±</span><span class="ft">IE Adjustment</span><span class="fhint">shift predicted IE by a fixed MW over a period · max 5 · no overlap</span></div>
          <div class="fb">
            <table class="prio" id="tbl-ie-adj"></table>
            <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-ieadj-add">+ Add IE Adjustment</button></div>
            <div class="hint" id="ieadj-note" style="margin-top:6px">Operator <b>+</b> raises and <b>−</b> lowers the predicted IE for every 30-min slot inside the period. Periods cannot overlap.</div>
          </div></div>
      </div>

      <!-- B. PLN Export Priority -->
      <div class="childpanel" data-child="frequent" id="cp-pln">
        <div class="fcard"><div class="fh"><span class="fi">①</span><span class="ft">PLN export priority</span><span class="fhint">priority #1</span></div>
          <div class="fb">
            <div class="pln-layout">
              <div class="pln-values">
                <h4 style="margin:0 0 8px">Export range</h4>
                <div class="grid g2">
                  <label class="fld">Range min <span class="u">MW</span><input type="number" step="0.1" id="f-pln-range-min"></label>
                  <label class="fld">Range max <span class="u">MW</span><input type="number" step="0.1" id="f-pln-range-max"></label>
                </div>
                <h4 style="margin:16px 0 8px">Additional Range PLN rules
                  <span class="hint" style="font-weight:500">up to 4 · within main range · no overlap · outside these periods the main range applies</span></h4>
                <div id="pln-range-rules"></div>
                <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-range-add">+ Add PLN Range Export</button></div>
                <h4 style="margin:16px 0 8px">Daily target</h4>
                <div class="grid g2">
                  <label class="fld">Daily target <span class="u">MWh</span><input type="number" step="1" id="f-pln-dt-val"></label>
                  <span></span>
                </div>
                <h4 style="margin:16px 0 8px">Dispatch deviation
                  <span class="hint" style="font-weight:500">up to 5 period rules · outside these periods export is free within Range</span></h4>
                <div id="pln-dd-rules"></div>
                <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-dd-add">+ Add deviation rule</button></div>
                <label class="fld" style="margin-top:12px">Dispatch Dev mode
                  <select id="f-pln-dd-mode">
                    <option value="same_as_quota">Same as Quota</option>
                    <option value="recommend">Recommend if Exceed Quota</option>
                  </select>
                </label>
              </div>
              <div class="pln-reqs">
                <div class="reqhead">Required</div>
                <label class="toggle is-locked"><input type="checkbox" id="f-pln-range-req" checked disabled>
                  <span class="tk"></span><span class="tl">Range<small>Always required</small></span></label>
                <label class="toggle"><input type="checkbox" id="f-pln-dt-req">
                  <span class="tk"></span><span class="tl">Daily target</span></label>
                <label class="toggle"><input type="checkbox" id="f-pln-dd-req">
                  <span class="tk"></span><span class="tl">Dispatch dev<small>enable rules</small></span></label>
              </div>
            </div>
          </div></div>
      </div>

      <!-- C. SR & Bus Flow -->
      <div class="childpanel" data-child="frequent" id="cp-sr">
        <div class="fcard"><div class="fh"><span class="fi">⚙</span><span class="ft">Spinning reserve &amp; bus flow</span><span class="fhint">reliability constraints</span></div>
          <div class="fb"><div class="grid g3">
            <label class="fld">Spinning reserve min <span class="u">units</span><input type="number" step="1" id="f-spinning_reserve_min"></label>
            <label class="fld">Bus flow min <span class="u">MW</span><input type="number" step="0.1" id="f-busflow_min"></label>
          </div></div></div>
      </div>

      <!-- D. Gas Data -->
      <div class="childpanel" data-child="frequent" id="cp-gas">
        <div class="kp72-warn" id="kp72-warn" style="display:none">
          <b>Gas KP72 is required</b> because GTG 10 or Gas Engine is set as required/running.
          Please fill PEP KP72, Pertagas KP72, Akasia KP72, or Baskara/BaGS KP72.
        </div>
        <div class="fcard"><div class="fh"><span class="fi">⛽</span><span class="ft">Gas quota</span><span class="fhint">volume/day · with GHV→energy</span></div>
          <div class="fb"><div class="grid g4" id="grid-quota"></div>
</div></div>

        <div class="fcard"><div class="fh"><span class="fi">④</span><span class="ft">Gas Shortage Decision</span><span class="fhint">priority #4<span id="gsd-never-hint"> — never auto-switches to distillate</span></span></div>
          <div class="fb">
            <div class="gsd-card" style="border:none;box-shadow:none;padding:0;background:none">
              <div class="gsd-top">
                <span class="gsd-badge ok" id="gsd-badge">No Shortage</span>
                <span class="hint" id="gsd-sub">Run a plan to evaluate the PGN gas quota.</span>
              </div>
              <div class="gsd-seg" id="gsd-seg" role="radiogroup" aria-label="Action when PGN quota is exceeded">
                <button type="button" class="gsd-opt" data-act="recommendation" role="radio" aria-checked="false">
                  <span class="gsd-opt-t">Gas Shortage Recommendation</span>
                  <span class="gsd-opt-d">Hitung opsi LNG dan Distillate tervalidasi, lalu tanyakan keputusan Anda</span>
                </button>
                <button type="button" class="gsd-opt" data-act="add_lng" role="radio" aria-checked="false">
                  <span class="gsd-opt-t">Add LNG to cover</span>
                  <span class="gsd-opt-d">Inject additional LNG to close the gap</span>
                </button>
                <button type="button" class="gsd-opt" data-act="use_distillate" role="radio" aria-checked="false">
                  <span class="gsd-opt-t">Use distillate to cover</span>
                  <span class="gsd-opt-d">Switch to distillate to close the gap</span>
                </button>
              </div>
              <input type="hidden" id="f-gas_action" value="recommendation">
              <div class="gsd-grid">
                <label class="fld off" id="gsd-lng-wrap">Additional LNG <span class="u">BBTUD</span><input type="number" step="0.1" id="f-additional_lng" disabled></label>
                <!-- Field liter distillate. Dahulu TIDAK ADA: menu menyediakan opsi "Use distillate"
                     tetapi tidak menyediakan cara memasukkan jumlahnya, sehingga Run mengirim action
                     tanpa plafon liter dan rekonsiliasi backend menolak hasilnya. Instruksi 4.3
                     mewajibkan DUA cara input (liter rekomendasi tervalidasi, atau liter manual);
                     field ini adalah jalur manual, jalur rekomendasi ditempuh lewat popup. -->
                <label class="fld off" id="gsd-dist-wrap">Distillate limit <span class="u">l/day</span><input type="number" step="1" id="f-distillate_litres" disabled></label>
                <div class="gsd-read"><span class="l">Required Additional LNG</span><span class="v" id="gsd-req-lng">—</span></div>
                <div class="gsd-read"><span class="l">Estimated Distillate</span><span class="v" id="gsd-est-dist">—</span></div>
              </div>
              <div class="rec" id="gsd-never-note" style="margin:12px 0 0">The system never auto-switches to distillate. When gas is short it reports exactly how much LNG <i>or</i> distillate would close the gap; a switch happens only when you pick <b>Add LNG</b> or <b>Use distillate</b> above.</div>
            </div>
          </div></div>
      </div>

      <!-- E. STG Start Up Mode -->
      <div class="childpanel" data-child="frequent" id="cp-stgstartup">
        <div class="fcard"><div class="fh"><span class="fi">⏻</span><span class="ft">STG Start Up Mode</span><span class="fhint">drives the STG startup sequence used by the simulation</span></div>
          <div class="fb">
            <div class="sched-wrap"><table class="sched" id="tbl-stg-startup"></table></div>
            <div class="hint" style="margin-top:8px"><b>Cold / Warm / Hot</b> follow the configured startup ramp before the STG appears (per the STG &amp; Additional-HRSG procedure). <b>No Start Up Sequence</b> skips the ramp — STG load is taken directly from the feeding GTG via <code>calc_stg</code>.</div>
          </div></div>
      </div>

      <!-- Babelan & Biomass (moved from Periodic Input — PROMPT B1) -->
      <div class="childpanel" data-child="frequent" id="cp-babelan">
        <div class="fcard"><div class="fh"><span class="fi">⬢</span><span class="ft">Babelan &amp; biomass</span><span class="fhint">Biomass Target (MWh) drives Babelan biomass/coal split</span></div>
          <div class="fb"><div class="grid g2" id="grid-babelan"></div>
            <div class="hint" style="margin-top:8px"><b>Biomass Target</b> (MWh) is split equally across every <b>active</b> Babelan unit-row (BBLN1/BBLN2 rows with load &gt; 0). Each active row's Coal Load = Babelan Load − Biomass Allocation (never negative). Heatrate &amp; Heating Value fields are stored only — not yet used by the optimizer.</div>
          </div></div>
      </div>

      <!-- F. Unit Status & Adjustments -->
      <div class="childpanel" data-child="frequent" id="cp-unitstatus">
        <p class="sec-note">Time slots are 30-min steps across the day.</p>

        <!-- Required & Commitment (Revisi Commitment Start/Stop): Unit Cannot Stop column removed (replaced by
             Commitment Mode = Unit Continuous Running); Unit Last Data Status moved in (left of Required);
             STOP STATUS and STOP AT columns added (right of Start At). -->
        <details class="acc" open><summary><span>Required &amp; Commitment (Start/Stop)</span><span class="achint">commitment flags per unit</span><span class="accv">▶</span></summary>
          <div class="accb">
            <table class="prio" id="tbl-unit-flags"></table>
            <div class="hint" id="unit-flags-note" style="margin-top:10px;line-height:1.5">
              <b>Unit Last Data Status</b> — status slot terakhir hari sebelumnya. <b>Running</b> → row pertama (00:30) wajib load &gt; 0, runtime melanjutkan, bukan startup baru; <b>Stop</b> → row pertama wajib 0 MW dan start berikutnya wajib mengikuti startup sequence. Otomatis terkunci oleh Commitment Mode.<br>
              <b>Commitment Mode</b> (aktif saat <b>Required</b> dicentang; Required off → Mode = “-” dan disabled):<br>
              • <b>Start Based On Simulation</b> — unit wajib start minimal satu kali; waktu dipilih optimizer (feasibility &amp; cost). Last Data Status otomatis Stop, Start At kosong.<br>
              • <b>Start Based on Request</b> — unit wajib start pada <b>Start At</b> (cell Start At bertanda kuning/oranye di hasil); mulai berbeban 30&nbsp;menit setelahnya mengikuti startup sequence (G1–G6: 5·30′→15·30′; G8/G9: 40·60′→50·30′→60·60′→70). Last Data Status otomatis Stop.<br>
              • <b>Unit Continuous Running</b> — unit wajib berbeban penuh 00:30–00:00 (tidak boleh stop); Last Data Status otomatis Running; Stop Status &amp; Stop At disabled; backend <code>unit_cannot_stop</code> digenerate otomatis dari mode ini.<br>
              <b>Stop Status</b>: • <b>Stop Based On Simulation or Continuous Running</b> — waktu stop bebas dipilih optimizer, atau unit tetap running sampai akhir hari (constraint &amp; cost terbaik). • <b>Stop Based on Request</b> — unit wajib 0&nbsp;MW pertama kali tepat pada <b>Stop At</b> (cell Stop At berwarna hitam di hasil); row sebelumnya masih berbeban.
            </div>
          </div></details>

        <!-- Change Over Block 1-2 (Revisi Change Over · hard constraint) -->
        <details class="acc" open><summary><span>Change Over Block 1-2</span><span class="achint">daily block-swap continuity · hard constraint</span><span class="accv">▶</span></summary>
          <div class="accb">
            <div class="co-head">
              <span class="co-q">Need Change Over Block 1-2?</span>
              <span class="seg co-seg" role="group" aria-label="Need Change Over">
                <button type="button" class="seg-opt co-yes" id="co-yes">YES</button>
                <button type="button" class="seg-opt co-no active" id="co-no">NO</button>
              </span>
            </div>
            <div id="co-body" style="display:none;margin-top:10px">
              <table class="prio" id="tbl-changeover"></table>
              <div class="hint" id="co-warn" style="margin-top:8px"></div>
              <div class="hint" style="margin-top:8px;line-height:1.5">
                <b>Change Over</b> memindahkan operasi antara Block 1 (G3/G4/G6→S1) dan Block 2 (G1/G2/G5→S2). <b>Last Status Block</b> otomatis dari Block Priority → Required (Running bila block Required, Stop bila tidak). Block yang <b>Stop</b> bisa di-<b>Start Other</b> (Based on simulation / jam request); block yang <b>Running</b> bisa di-<b>Stop Other</b>. Backend menjaga: block pengganti start dulu sebelum block lama stop, dan <b>tidak pernah kedua block mati bersamaan</b> selama change over. Semua start/stop mengikuti Startup STG (Cold/Warm/Hot) &amp; Additional HRSG, ramp, gas quota, export range, bus flow, runtime/downtime.
              </div>
            </div>
          </div></details>

          <!-- ADDENDUM Bagian 3: menu ini sebelumnya tanpa judul (details/summary pembuka hilang,
               hanya </details> yatim). Judul "Max Load Adjustment" ditambahkan; logic existing
               (MAXLOAD_RULES + payload max_load_rules) TIDAK diubah. -->
          <details class="acc" open><summary><span>Max Load Adjustment</span><span class="achint">temporary per-period Maximum Load override · up to 3 periods/unit</span><span class="accv">&#9654;</span></summary>
          <div class="accb">
            <div class="grid g2" style="align-items:end">
              <label class="fld">Unit<select id="ml-unit">
                <option>G1</option><option>G2</option><option>G3</option><option>G4</option><option>G5</option><option>G6</option><option>G7</option><option>G8</option><option>G9</option><option>G10</option>
                <option value="GE1">Gas Engine 1</option><option value="GE2">Gas Engine 2</option><option value="GE3">Gas Engine 3</option><option value="GE4">Gas Engine 4</option>
              </select></label>
              <button type="button" class="btn ghost" id="btn-ml-add">+ Add max period</button>
            </div>
            <div id="ml-rules" style="margin-top:8px"></div>
          </div></details>

        <!-- Unit Last Data Status (Revisi Commitment Start/Stop): moved INTO the Required & Commitment table
             above (left of the Required column). The old standalone table was removed; the same select ids
             (uld-G1 ... uld-B2) are kept inside the commitment table so sync/collect code is unchanged. -->

        <div class="sched-h"><h4>Unit Stop Schedule</h4><span class="hint">Tick <b>All Day</b> for a full-day stop, or set a period window. HRSG (H…) units route to the HRSG stop fields.</span></div>
        <div class="sched-wrap"><table class="sched" id="tbl-unit-stop"></table></div>
        <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-add-stop">+ Add stop</button></div>

        <div class="sched-h"><h4>Unit Skip Load Schedule</h4><span class="hint">Forbid a unit from loading between a low and high MW band across a period.</span></div>
        <div class="sched-wrap"><table class="sched" id="tbl-unit-skip"></table></div>
        <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-add-skip">+ Add skip load</button></div>

        <div class="sched-h"><h4>Unit Fix Load Schedule</h4><span class="hint">Hold a unit at a fixed MW load across a period.</span></div>
        <div class="sched-wrap"><table class="sched" id="tbl-unit-fix"></table></div>
        <div style="margin-top:8px"><button type="button" class="btn ghost" id="btn-add-fix">+ Add fix load</button></div>

        <!-- pass-through field kept out of the main UI (structure preserved, not edited here) -->
        <div hidden aria-hidden="true"><textarea id="ta-ie_adjustment" spellcheck="false"></textarea></div>
      </div>

      <!-- persistent run bar -->
      <div class="runbar no-print" style="margin-top:18px;padding-top:16px;border-top:1px solid var(--card-line)">
        <button type="button" class="btn primary" id="btn-run" onclick="return window.gsRunFromButton(event)">▶ Run simulation</button>
        <button class="btn ghost" id="btn-reset">Reload saved input</button>
        <button class="btn ghost" id="btn-save" title="Save Input — menyimpan input ke input_data.json (selalu aktif)">💾 Save</button>
        <span class="hint" id="autosave-msg" style="font-size:11.5px;color:#64748b"></span>
        <span class="spacer"></span>
        <span class="hint" id="run-msg"></span>
        <label class="tl-target" for="f-tl-target">Target Selesai
          <select id="f-tl-target" title="Fastest - Default: selesai begitu kandidat fully valid pertama tersedia (bukan bukti global optimum bila exact belum selesai). Pilihan berbatas waktu menampilkan kandidat constraint-valid dengan Cost Production terendah yang sudah ditemukan saat batas tercapai; Maximum Review menjalankan optimasi exact sampai selesai.">
            <option value="fast" selected style="background:#ffd54f;color:#5d3a00;font-weight:800">Fastest - Default</option>
            <option value="15" style="background:#fff;color:#1f2937">&lt; 15 detik</option>
            <option value="25" style="background:#fff;color:#1f2937">&lt; 25 detik</option>
            <option value="35" style="background:#fff;color:#1f2937">&lt; 35 detik</option>
            <option value="45" style="background:#fff;color:#1f2937">&lt; 45 detik</option>
            <option value="55" style="background:#fff;color:#1f2937">&lt; 55 detik</option>
            <option value="60" style="background:#fff;color:#1f2937">&lt; 60 detik</option>
            <option value="max" style="background:#ff2d2d;color:#fff;font-weight:800">Maximum Review</option>
          </select>
        </label>
      </div>
    </section>

    <!-- ========== RESULT ========== -->
    <section class="subpanel" id="sp-result" role="tabpanel">
      <div class="res-toolbar no-print">
        <span style="font-weight:800;color:var(--ink)">Daily simulation result</span>
        <span class="spacer" style="margin-left:auto"></span>
        <button class="btn ghost" id="btn-xls">⬇ Excel</button>
        <button class="btn ghost" id="btn-img-full">⬇ Image Full</button>
        <span class="img-partial">
          <label class="img-sf">Start From<select id="img-start"><option value="0">—</option></select></label>
          <button class="btn ghost" id="btn-img-partial">⬇ Image Partial</button>
        </span>
        <button class="btn ghost" id="btn-cmp">+ Add to comparison</button>
      </div>
      <div class="childtabs" role="tablist">
        <button type="button" class="childtab active" data-child="result" data-cp="cp-simdata">Simulation Data</button>
        <button type="button" class="childtab" data-child="result" data-cp="cp-summary">Summary</button>
        <button type="button" class="childtab" data-child="result" data-cp="cp-comparison">Comparison</button>
        <div id="gas-monitor" class="gas-monitor">
          <span class="gm-item"><span class="gm-lbl">Actual Total Gas PGN</span><span class="gm-val" id="gm-pgn">0</span></span>
          <span class="gm-item"><span class="gm-lbl">Actual Energy Total Fixed Flow Jababeka</span><span class="gm-val" id="gm-ffj">0</span></span>
          <span class="gm-item"><span class="gm-lbl">Actual Energy Total Fixed Flow MM2100</span><span class="gm-val" id="gm-ffm">0</span></span>
        </div>
      </div>
      <div id="result-card">
        <div class="childpanel active" data-child="result" id="cp-simdata">
          <div id="result-simdata"><div class="placeholder"><h3>No simulation yet</h3><p>Run a Daily Plan from <b>Frequently Input</b> to populate the per-row dispatch table.</p></div></div>
          <!-- PROMPT FULLSCREEN: modal layar penuh TABEL DETAIL SLOT. Tabel ASLI di-teleport ke #simfs-body
               saat dibuka (DOM node sama -> semua event/edit tetap hidup), dikembalikan saat ditutup. -->
          <div id="simfs-modal" role="dialog" aria-modal="true" aria-label="TABEL DETAIL SLOT — Full Screen">
            <div id="simfs-panel">
              <div id="simfs-bar"><span class="t1">TABEL DETAIL SLOT — Full Screen</span><span class="t2">Per-row dispatch · 48 × half-hour · scroll ↔ ↕ · edit tetap aktif</span>
                <button type="button" id="simfs-close" title="Tutup full screen (Esc)">✕ Close</button></div>
              <div id="simfs-body"></div>
            </div>
          </div>
          <div class="actual-toolbar no-print">
            <span class="actual-title">Actual Data</span>
            <span class="achint" id="actual-status">No actual data loaded — simulation covers 00:30–00:00.</span>
            <span class="spacer" style="margin-left:auto"></span>
            <button class="btn ghost" id="btn-actual-format">⬇ Download Format</button>
            <label class="btn ghost" for="actual-file" style="cursor:pointer">⬆ Upload Actual Data</label>
            <input type="file" id="actual-file" accept=".csv,text/csv" hidden>
            <button class="btn ghost" id="btn-actual-clear">✕ Clear Actual Data</button>
          </div>
        </div>
        <div class="childpanel" data-child="result" id="cp-summary">
          <div id="result-summary"><div class="placeholder"><h3>No simulation yet</h3><p>Banner, KPIs, cost breakdown and warnings appear here after a run.</p></div></div>
        </div>
      </div>
      <div class="childpanel" data-child="result" id="cp-comparison">
        <div class="card" id="cmp-card" style="display:none">
          <div class="card-h"><span class="eyebrow">Comparison</span>&nbsp;Saved runs
            <button class="btn ghost no-print" id="btn-cmp-clear" style="margin-left:auto">Clear</button></div>
          <div class="card-body"><div class="sumwrap" style="border:none;box-shadow:none"><table class="sumtbl cmptbl" id="tbl-cmp"></table></div></div>
        </div>
        <div class="placeholder" id="cmp-empty">No comparison runs yet. Use <b>+ Add to comparison</b> after a run to stack plans side by side.</div>
      </div>
    </section>

    <?php endif; ?>
  </section>

  <!-- ============================ WEEKLY / MONTHLY ============================ -->
  <section class="panel" id="panel-weekly" role="tabpanel">
  </section>
  <section class="panel" id="panel-monthly" role="tabpanel">
    <div class="card"><div class="card-h"><span class="eyebrow">Monthly Plan</span>&nbsp;Calendar of daily runs</div>
      <div class="card-body">
        <div class="placeholder"><h3>Monthly planning</h3>
        <p>Monthly Plan spans every day of the month with the same engine, supporting per-day overrides on the frequently-changed inputs while keeping periodic settings fixed.</p></div>
      </div></div>
  </section>

  <!-- ============================ REPORT ============================ -->
  <section class="panel" id="panel-report" role="tabpanel">
    <!-- Report sub-tabs (Actual | Planning | Planning Vs Actual) -->
    <div class="subtabs" role="tablist" aria-label="Report sections">
      <button type="button" class="subtab active" role="tab" data-rsub="actual"><span class="n">1</span>Actual</button>
      <button type="button" class="subtab" role="tab" data-rsub="planning"><span class="n">2</span>Planning</button>
      <button type="button" class="subtab" role="tab" data-rsub="pva"><span class="n">3</span>Planning Vs Actual</button>
    </div>

    <!-- ===== Report → Actual ===== -->
    <section class="subpanel active" id="rp-actual" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Report</span>&nbsp;Actual — monthly data by area</div>
        <div class="card-body">
          <p class="sec-note"><b>Input Data</b> is the master source. <b>JBBK</b>, <b>MM2100</b> and <b>JBBK-MM2100</b> are derived views that copy their columns from Input Data and update automatically when it changes. Use <b>Download Format</b> to get an .xlsx template, fill actuals, then <b>Upload Data</b> to full-replace the active month.</p>
          <!-- Year row -->
          <div class="ractrl">
            <label class="fld inline">Year
              <select id="ra-year"></select></label>
            <label class="fld inline">Area
              <select id="ra-area">
                <option value="input">Input Data</option>
                <option value="jbbk">JBBK</option>
                <option value="mm2100">MM2100</option>
                <option value="jbbkmm">JBBK-MM2100</option>
              </select></label>
            <label class="fld inline">Month
              <select id="ra-month"></select></label>
            <span class="spacer"></span>
            <button class="btn ghost" id="ra-download" title="Download .xlsx template for the active Year + Month (Input Data, 54 columns, DATE pre-filled)">⬇ Download Format</button>
            <label class="btn ghost" id="ra-upload-lbl" style="cursor:pointer" title="Upload a filled .xlsx to insert/update the active month's Input Data (partial months allowed)">⬆ Upload Data<input type="file" id="ra-upload" accept=".xlsx" hidden></label>
            <button class="btn" id="ra-save" title="Save the uploaded/edited data into the report store so it persists (not just shown temporarily)">💾 SAVE DATA</button>
          </div>
          <div class="hint" id="ra-status" style="margin:8px 0"></div>
          <div class="scroll ra-scroll"><table class="data ra-table" id="ra-table"></table></div>
        </div></div>
    </section>

    <!-- ===== Report → Planning (placeholder) ===== -->
    <section class="subpanel" id="rp-planning" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Report</span>&nbsp;Planning — saved daily-plan simulations by month</div>
        <div class="card-body">
          <p class="sec-note">Each row is a saved simulation from <b>Daily Plan → Result → Simulation Data → Save</b>. Rows are keyed by <b>Plan Date + Name Plan</b>: same Name Plan on the same date <b>updates</b> that row; a different Name Plan (e.g. a Remark like “Rev 01”) <b>adds a new row</b> on the same date. The <b>Simulation Result</b> icon opens the full saved input + result + summary in a new tab.</p>
          <div class="ractrl">
            <label class="fld inline">Year
              <select id="rp-year"></select></label>
            <label class="fld inline">Month
              <select id="rp-month"></select></label>
            <span class="spacer"></span>
          </div>
          <div class="hint" id="rp-status" style="margin:8px 0"></div>
          <div class="scroll ra-scroll"><table class="data ra-table" id="rp-table"></table></div>
        </div></div>
    </section>

    <!-- ===== Report → Planning Vs Actual (placeholder) ===== -->
    <section class="subpanel" id="rp-pva" role="tabpanel">
      <div class="card"><div class="card-h"><span class="eyebrow">Report</span>&nbsp;Planning Vs Actual</div>
        <div class="card-body"><div class="placeholder"><h3>Planning vs Actual</h3>
          <p>Side-by-side variance of planned vs actual per day/month once both datasets exist. UI table created, persistence pending.</p></div>
        </div></div>
    </section>
  </section>
  </main>
  </div><!-- /content-wrapper -->

  <footer class="main-footer">
    <span><b>Daily Plan Simulation Online - Operation Department</b></span>
    <span class="ff-right">BUILD SYSTEMIC-SHORTAGE-PROOF-V7-TESTABLE-UNVERIFIED · Light dashboard · runs locally on XAMPP</span>
  </footer>

</div><!-- /wrapper -->

<div class="toast" id="toast"></div>
<script>
(function(){
  var btn=document.getElementById('sb-toggle');
  if(!btn)return;
  btn.addEventListener('click',function(){
    if(window.matchMedia('(max-width:980px)').matches) document.body.classList.toggle('sb-open');
    else document.body.classList.toggle('sb-collapsed');
  });
})();
</script>

<script>
/* ===================== state ===================== */
let   INPUT0 = <?php echo json_encode($INPUT ?: new stdClass(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
/* KETAHANAN TANPA input_data.json. Bila file itu tidak ada, INPUT0 adalah objek kosong dan setiap
 * pembaca `INPUT.data3.modeling` melempar TypeError yang MENGHENTIKAN sisa skrip — akibatnya bukan
 * hanya placeholder yang tampil, tetapi tombol Run dan seluruh tab pun tidak pernah terpasang.
 * Struktur minimum di bawah membuat halaman tetap hidup: placeholder tetap terlihat, dan tidak ada
 * exception yang membunuh inisialisasi. */
if(!INPUT0 || typeof INPUT0!=='object') INPUT0={};
if(!INPUT0.data1) INPUT0.data1=[];
if(!INPUT0.data2) INPUT0.data2=[];
if(!INPUT0.data3 || typeof INPUT0.data3!=='object') INPUT0.data3={};
if(!INPUT0.data3.modeling || typeof INPUT0.data3.modeling!=='object') INPUT0.data3.modeling={};
let   OUTPUT  = <?php echo json_encode($OUTPUT ?: null, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?>;
const HAVE_INPUT = <?php echo $haveInput ? 'true':'false'; ?>;
let   INPUT  = JSON.parse(JSON.stringify(INPUT0));

/* ===== Simulation Result "clone UI" view (Revisi Report Planning) =====
   When the page is opened with ?sim_view=YEAR/MONTH/YYYY-MM-DD/IDX (from the Report → Planning
   Simulation Result icon), load that saved snapshot's input + output INTO the normal state, so the
   full main UI renders with the saved plan's data instead of raw JSON. The page is then locked to a
   read-only view (see applySimViewMode below). The snapshot lives in report_planning inside the same
   input_data.json that already bootstrapped INPUT0. */
let SIM_VIEW=null;
(function(){
  try{
    const q=new URLSearchParams(location.search).get('sim_view');
    if(!q) return;
    // Format: YEAR/MONTH/YYYY-MM-DD/base64(name_plan)   (name is the identity; date+name = unique key)
    const parts=q.split('/'); if(parts.length<4) return;
    const year=parts[0], month=parts[1], dateKey=parts[2];
    let wantName=''; try{ wantName=decodeURIComponent(escape(atob(decodeURIComponent(parts.slice(3).join('/'))))); }catch(_){ wantName=''; }
    const rp=(INPUT0&&INPUT0.data3&&INPUT0.data3.modeling&&INPUT0.data3.modeling.report_planning)||{};
    const arr=(rp[year]&&rp[year][month]&&rp[year][month][dateKey])||[];
    let p=null;
    if(wantName){ p=arr.find(x=>x.name_plan===wantName)||null; }
    if(!p && /^\d+$/.test(parts[3])){ p=arr[+parts[3]]||null; }   // legacy index fallback
    if(!p||!p.snapshot){ SIM_VIEW={error:'Snapshot not found for '+q}; return; }
    const snap=p.snapshot;
    /* PROMPT PLAN SAVE §8.3 — "Jangan overwrite snapshot asal secara diam-diam".
       BUG NYATA yang ditutup di sini: snapshot.input TIDAK menyimpan report_planning (memang sengaja,
       supaya snapshot tidak bersarang rekursif). Padahal REPORT_PLANNING di bawah di-init dari
       INPUT.data3.modeling.report_planning. Tanpa penjagaan ini, membuka Simulation Result lalu menekan
       Save akan mem-persist report_planning = {} -> SELURUH record Plan/Monitoring di Report terhapus.
       Karena itu daftar record milik FILE dipertahankan dan dipasang kembali ke INPUT/INPUT0 salinan
       snapshot, sehingga Save dari tab ini hanya menambah/replace record-nya sendiri. */
    const fileRP=JSON.parse(JSON.stringify(rp||{}));
    if(snap.input){
      INPUT=JSON.parse(JSON.stringify(snap.input)); INPUT0=JSON.parse(JSON.stringify(snap.input));
      [INPUT,INPUT0].forEach(o=>{ if(o&&o.data3&&o.data3.modeling) o.data3.modeling.report_planning=JSON.parse(JSON.stringify(fileRP)); });
    }
    if(snap.output){ OUTPUT={info:snap.output.info||{},data:snap.output.data||[],result:snap.output.result||'ok'}; }
    SIM_VIEW={name:p.name_plan,date:p.plan_date||dateKey,saved_at:p.saved_at||'',
      /* PROMPT PLAN SAVE §8.2: context snapshot — row Plan membuka editable Plan, row Monitoring
         membuka editable Monitoring (TIME PASSED, warna Y/N, locked rows, actual, future rows).
         plan_type diambil dari metadata record; fallback ke pola Name Plan bila record lama belum punya. */
      plan_type:(p.plan_type||(/^Monitoring Daily Plan/i.test(p.name_plan||'')?'monitoring':'plan')),
      snapshot_id:(p.snapshot_id||''), source_plan_name:(p.source_plan_name||'')};
  }catch(e){ SIM_VIEW={error:'sim_view parse error: '+e.message}; }
})();

/* ===== Unit Last Data Status (Revisi UI) ===== */
const ULD_UNITS=[['G1','G1'],['G2','G2'],['G3','G3'],['G4','G4'],['G5','G5'],['G6','G6'],['G7','G7'],['G8','G8'],['G9','G9'],['G10','G10'],['S1','S1'],['S2','S2'],['S3','S3'],['B1','BBLN1'],['B2','BBLN2'],['GE1','Gas Engine 1'],['GE2','Gas Engine 2'],['GE3','Gas Engine 3'],['GE4','Gas Engine 4']];
function uldColor(sel){ if(!sel) return; const run=/run/i.test(sel.value); sel.classList.toggle('uld-run',run); sel.classList.toggle('uld-stop',!run); }
function buildUnitLastStatus(){
  // Revisi Commitment Start/Stop: the standalone Unit Last Data Status table was merged into the
  // Required & Commitment table (renderUnitFlags). This shim keeps old call sites working.
  if(typeof renderUnitFlags==='function') renderUnitFlags();
}
function collectUnitLastStatus(){
  const o={}; ULD_UNITS.forEach(([k])=>{
    const el=document.getElementById('uld-'+k);
    if(el){ o[k]=el.value; return; }
    const st=(INPUT&&INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.unit_last_data_status)||{};
    o[k]=String(st[k]||'Stop');
  }); return o;
}
/* ===== Time-based Maximum Load Adjustment (Revisi): {unit:[{start,stop,max}]} up to 3/unit, no overlap ===== */
let MAXLOAD_RULES={};
function renderMaxLoadRules(){
  const host=$('ml-rules'); if(!host) return;
  let h='';
  Object.keys(MAXLOAD_RULES).forEach(unit=>{
    (MAXLOAD_RULES[unit]||[]).forEach((r,i)=>{
      h+=`<div class="dd-rule" data-u="${unit}" data-i="${i}">
        <span class="dd-no">${unit}</span>
        <label class="fld">Max <span class="u">MW</span><input type="number" step="0.1" data-ml="max" value="${r.max}"></label>
        <label class="fld">Period Start<select data-ml="start">${startOptions(clamp1(r.start))}</select></label>
        <label class="fld">Period Stop<select data-ml="stop">${stopOptions(clamp1(r.stop))}</select></label>
        <button type="button" class="rowdel" data-ml="del" title="Remove">✕</button>
      </div>`;
    });
  });
  host.innerHTML=h;
  host.querySelectorAll('.dd-rule').forEach(div=>{
    const u=div.dataset.u, i=+div.dataset.i;
    div.querySelector('[data-ml="max"]').oninput=e=>{MAXLOAD_RULES[u][i].max=num(e.target.value);};
    div.querySelector('[data-ml="start"]').onchange=e=>{MAXLOAD_RULES[u][i].start=+e.target.value;};
    div.querySelector('[data-ml="stop"]').onchange=e=>{MAXLOAD_RULES[u][i].stop=+e.target.value;};
    div.querySelector('[data-ml="del"]').onclick=()=>{MAXLOAD_RULES[u].splice(i,1); if(!MAXLOAD_RULES[u].length)delete MAXLOAD_RULES[u]; renderMaxLoadRules();};
  });
}
function addMaxLoadRule(){
  const u=$('ml-unit').value; MAXLOAD_RULES[u]=MAXLOAD_RULES[u]||[];
  if(MAXLOAD_RULES[u].length>=3){ if(typeof toast==='function')toast('Maximum 3 periods per unit'); return; }
  let start=1; if(MAXLOAD_RULES[u].length)start=Math.min(48,Math.max(...MAXLOAD_RULES[u].map(r=>+r.stop))+1);
  MAXLOAD_RULES[u].push({max:0,start:start,stop:Math.min(48,start+1)}); renderMaxLoadRules();
}
function validateMaxLoadRules(){
  for(const u of Object.keys(MAXLOAD_RULES)){
    const rs=MAXLOAD_RULES[u].map(r=>({start:+r.start,stop:+r.stop}));
    for(const r of rs) if(r.start>r.stop) return `Max Load rule for ${u}: period Start must be <= Stop.`;
    for(let a=0;a<rs.length;a++)for(let b=a+1;b<rs.length;b++)
      if(rs[a].start<=rs[b].stop&&rs[b].start<=rs[a].stop) return `Max Load periods for ${u} overlap.`;
  }
  return '';
}
document.addEventListener('DOMContentLoaded',buildUnitLastStatus);
const CMP = [];

const UNIT_ORDER = ['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4'];
/* Display label helper (Revisi UI Sec.1): backend keeps internal key b1/b2, UI shows BBLN1/BBLN2. */
function UL(u){ u=(''+u).toLowerCase(); if(u==='b1')return'BBLN1'; if(u==='b2')return'BBLN2'; return (''+u).toUpperCase(); }
/* Fuel-price unit labels (Revisi UI Sec.2) — display only, never stored as part of the numeric value. */
const PRICE_UNIT={pgn_pipe:'USD/MMBTU',lng:'USD/MMBTU',pep:'USD/MMBTU',pep_kp72:'USD/MMBTU',pertagas_kp72:'USD/MMBTU',akasia:'USD/MMBTU',akasia_kp72:'USD/MMBTU',bbg:'USD/MMBTU',baskara:'USD/MMBTU',baskara_kp72:'USD/MMBTU',coal:'USD/ton',woodchip:'USD/MWh',pks:'USD/MWh',distillate:'USD/Liter'};
const QUOTA_KEYS = ['pgn_pipe','lng','pep','pep_kp72','pertagas_kp72','akasia','akasia_kp72','bbg','baskara','baskara_kp72'];
const PRICE_KEYS = ['pgn_pipe','lng','pep','pertagas_kp72','akasia','akasia_kp72','bbg','baskara','baskara_kp72','coal','woodchip','pks','distillate'];
const $ = (id)=>document.getElementById(id);
const num = (v)=>{const n=parseFloat(v);return isNaN(n)?0:n;};
const fmt = (v,d=2)=>{const n=parseFloat(v);return isNaN(n)?'—':n.toLocaleString('en-US',{maximumFractionDigits:d,minimumFractionDigits:0});};
function toast(m){const t=$('toast');t.textContent=m;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2200);}

/* ===================== Unit Status & Adjustments (Revisi UI) =====================
 * Replaces the old JSON textareas with structured tables. Output stays compatible
 * with input_data.json:
 *   - unit_stop        : ["g7","g10"]                (all-day stops, non-HRSG)
 *   - unit_stop_time   : [{unit,start,stop}]         (timed stops, non-HRSG)
 *   - hrsg_stop        : ["h3",...]                  (all-day stops, HRSG units)
 *   - hrsg_stop_time   : [{unit,start,stop}]         (timed stops, HRSG units)
 *   - unit_fix_load    : {g4:[{start,stop,value}]}
 *   - unit_skip_load   : {g4:[{start,stop,value_low,value_high}]}
 * start/stop are 1-based 30-min slot indices (1..48), matching the existing
 * fix/skip convention. Clock labels map: start slot s -> (s-1)*30 min,
 * stop slot t -> t*30 min (slot 48 stop = 00:00 next day). */
const STOP_UNITS  = ['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','h1','h2','h3','h4','h5','h6','h8','h9'];
const FIXSKIP_UNITS = ['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'];
const SLOTS = 48;
let STOP_ROWS = [], FIX_ROWS = [], SKIP_ROWS = [];
const isHrsg = (u)=>/^h\d+$/i.test(String(u||''));
const clamp1 = (n)=>{n=parseInt(n,10);return (isNaN(n)||n<1)?1:(n>SLOTS?SLOTS:n);};
function minToHHMM(mins){mins=((mins%1440)+1440)%1440;const hh=String(Math.floor(mins/60)).padStart(2,'0'),mm=String(mins%60).padStart(2,'0');return hh+':'+mm;}
function unitOptions(list,sel){return list.map(u=>`<option value="${u}" ${u===sel?'selected':''}>${u.toUpperCase()}</option>`).join('');}
function startOptions(sel){let h='';for(let i=0;i<SLOTS;i++){const slot=i+1;h+=`<option value="${slot}" ${slot===sel?'selected':''}>${minToHHMM(i*30)}</option>`;}return h;}      // 00:00..23:30
function stopOptions(sel){let h='';for(let i=0;i<SLOTS;i++){const slot=i+1;h+=`<option value="${slot}" ${slot===sel?'selected':''}>${minToHHMM((i+1)*30)}</option>`;}return h;}  // 00:30..00:00

/* ---- init state from current INPUT ---- */
function initScheduleRows(){
  const m=INPUT.data3.modeling||{};
  STOP_ROWS=[];
  (m.unit_stop||[]).forEach(u=>{if(u)STOP_ROWS.push({unit:String(u).toLowerCase(),allDay:true,start:1,stop:SLOTS});});
  (m.unit_stop_time||[]).forEach(o=>{if(o&&o.unit)STOP_ROWS.push({unit:String(o.unit).toLowerCase(),allDay:false,start:clamp1(o.start),stop:clamp1(o.stop)});});
  (m.hrsg_stop||[]).forEach(u=>{if(u)STOP_ROWS.push({unit:String(u).toLowerCase(),allDay:true,start:1,stop:SLOTS});});
  (m.hrsg_stop_time||[]).forEach(o=>{const id=o&&(o.hrsg||o.unit);if(id)STOP_ROWS.push({unit:String(id).toLowerCase(),allDay:false,start:clamp1(o.start),stop:clamp1(o.stop)});});
  FIX_ROWS=[];
  const fl=m.unit_fix_load||{};
  Object.keys(fl).forEach(u=>{(fl[u]||[]).forEach(o=>FIX_ROWS.push({unit:String(u).toLowerCase(),value:num(o.value),start:clamp1(o.start),stop:clamp1(o.stop)}));});
  SKIP_ROWS=[];
  const sl=m.unit_skip_load||{};
  Object.keys(sl).forEach(u=>{(sl[u]||[]).forEach(o=>SKIP_ROWS.push({unit:String(u).toLowerCase(),low:num(o.value_low),high:num(o.value_high),start:clamp1(o.start),stop:clamp1(o.stop)}));});
}

/* ---- render: Unit Stop Schedule ---- */
function renderStopTable(){
  let h='<thead><tr><th class="ctr" style="width:46px">No</th><th>Stop Unit</th><th>Period Start</th><th>Period Stop</th><th class="ctr" style="width:74px">All Day</th><th class="ctr" style="width:64px">Action</th></tr></thead><tbody>';
  if(!STOP_ROWS.length) h+=`<tr><td colspan="6" class="sched-empty">No unit stops. Click “+ Add stop”.</td></tr>`;
  STOP_ROWS.forEach((r,i)=>{const d=r.allDay?'disabled':'';
    h+=`<tr><td class="no ctr">${i+1}</td>
      <td><select data-st="unit" data-i="${i}">${unitOptions(STOP_UNITS,r.unit)}</select></td>
      <td><select data-st="start" data-i="${i}" ${d}>${startOptions(r.start)}</select></td>
      <td><select data-st="stop" data-i="${i}" ${d}>${stopOptions(r.stop)}</select></td>
      <td class="ctr"><input type="checkbox" data-st="allDay" data-i="${i}" ${r.allDay?'checked':''}></td>
      <td class="ctr"><button type="button" class="rowdel" data-st="del" data-i="${i}" title="Remove row">✕</button></td></tr>`;});
  $('tbl-unit-stop').innerHTML=h+'</tbody>';
  const t=$('tbl-unit-stop');
  t.querySelectorAll('[data-st="unit"]').forEach(s=>s.onchange=e=>{STOP_ROWS[+e.target.dataset.i].unit=e.target.value;});
  t.querySelectorAll('[data-st="start"]').forEach(s=>s.onchange=e=>{STOP_ROWS[+e.target.dataset.i].start=+e.target.value;});
  t.querySelectorAll('[data-st="stop"]').forEach(s=>s.onchange=e=>{STOP_ROWS[+e.target.dataset.i].stop=+e.target.value;});
  t.querySelectorAll('[data-st="allDay"]').forEach(c=>c.onchange=e=>{STOP_ROWS[+e.target.dataset.i].allDay=e.target.checked;renderStopTable();});
  t.querySelectorAll('[data-st="del"]').forEach(b=>b.onclick=e=>{STOP_ROWS.splice(+e.target.dataset.i,1);renderStopTable();});
}
/* ---- render: Unit Fix Load Schedule ---- */
function renderFixTable(){
  let h='<thead><tr><th class="ctr" style="width:46px">No</th><th>Unit</th><th>Fix Load Value (MW)</th><th>Start Fix Load</th><th>Stop Fix Load</th><th class="ctr" style="width:64px">Action</th></tr></thead><tbody>';
  if(!FIX_ROWS.length) h+=`<tr><td colspan="6" class="sched-empty">No fix-load rules. Click “+ Add fix load”.</td></tr>`;
  FIX_ROWS.forEach((r,i)=>{
    h+=`<tr><td class="no ctr">${i+1}</td>
      <td><select data-fx="unit" data-i="${i}">${unitOptions(FIXSKIP_UNITS,r.unit)}</select></td>
      <td><input class="num" type="number" step="0.1" data-fx="value" data-i="${i}" value="${r.value}"></td>
      <td><select data-fx="start" data-i="${i}">${startOptions(r.start)}</select></td>
      <td><select data-fx="stop" data-i="${i}">${stopOptions(r.stop)}</select></td>
      <td class="ctr"><button type="button" class="rowdel" data-fx="del" data-i="${i}" title="Remove row">✕</button></td></tr>`;});
  $('tbl-unit-fix').innerHTML=h+'</tbody>';
  const t=$('tbl-unit-fix');
  t.querySelectorAll('[data-fx="unit"]').forEach(s=>s.onchange=e=>{FIX_ROWS[+e.target.dataset.i].unit=e.target.value;});
  t.querySelectorAll('[data-fx="value"]').forEach(s=>s.oninput=e=>{FIX_ROWS[+e.target.dataset.i].value=num(e.target.value);});
  t.querySelectorAll('[data-fx="start"]').forEach(s=>s.onchange=e=>{FIX_ROWS[+e.target.dataset.i].start=+e.target.value;});
  t.querySelectorAll('[data-fx="stop"]').forEach(s=>s.onchange=e=>{FIX_ROWS[+e.target.dataset.i].stop=+e.target.value;});
  t.querySelectorAll('[data-fx="del"]').forEach(b=>b.onclick=e=>{FIX_ROWS.splice(+e.target.dataset.i,1);renderFixTable();});
}
/* ---- render: Unit Skip Load Schedule ---- */
function renderSkipTable(){
  let h='<thead><tr><th class="ctr" style="width:46px">No</th><th>Unit</th><th>Skip Load Low (MW)</th><th>Skip Load High (MW)</th><th>Start Skip Load</th><th>Stop Skip Load</th><th class="ctr" style="width:64px">Action</th></tr></thead><tbody>';
  if(!SKIP_ROWS.length) h+=`<tr><td colspan="7" class="sched-empty">No skip-load rules. Click “+ Add skip load”.</td></tr>`;
  SKIP_ROWS.forEach((r,i)=>{
    h+=`<tr><td class="no ctr">${i+1}</td>
      <td><select data-sk="unit" data-i="${i}">${unitOptions(FIXSKIP_UNITS,r.unit)}</select></td>
      <td><input class="num" type="number" step="0.1" data-sk="low" data-i="${i}" value="${r.low}"></td>
      <td><input class="num" type="number" step="0.1" data-sk="high" data-i="${i}" value="${r.high}"></td>
      <td><select data-sk="start" data-i="${i}">${startOptions(r.start)}</select></td>
      <td><select data-sk="stop" data-i="${i}">${stopOptions(r.stop)}</select></td>
      <td class="ctr"><button type="button" class="rowdel" data-sk="del" data-i="${i}" title="Remove row">✕</button></td></tr>`;});
  $('tbl-unit-skip').innerHTML=h+'</tbody>';
  const t=$('tbl-unit-skip');
  t.querySelectorAll('[data-sk="unit"]').forEach(s=>s.onchange=e=>{SKIP_ROWS[+e.target.dataset.i].unit=e.target.value;});
  t.querySelectorAll('[data-sk="low"]').forEach(s=>s.oninput=e=>{SKIP_ROWS[+e.target.dataset.i].low=num(e.target.value);});
  t.querySelectorAll('[data-sk="high"]').forEach(s=>s.oninput=e=>{SKIP_ROWS[+e.target.dataset.i].high=num(e.target.value);});
  t.querySelectorAll('[data-sk="start"]').forEach(s=>s.onchange=e=>{SKIP_ROWS[+e.target.dataset.i].start=+e.target.value;});
  t.querySelectorAll('[data-sk="stop"]').forEach(s=>s.onchange=e=>{SKIP_ROWS[+e.target.dataset.i].stop=+e.target.value;});
  t.querySelectorAll('[data-sk="del"]').forEach(b=>b.onclick=e=>{SKIP_ROWS.splice(+e.target.dataset.i,1);renderSkipTable();});
}
function renderSchedules(){ initScheduleRows(); renderStopTable(); renderFixTable(); renderSkipTable(); }

/* ===================== STG Start Up Mode (Revisi) =====================
 * UI label -> stored token (engine reads stg_startup_mode[s+'_startup'], case-insensitive):
 *   Cold Start Up -> Cold | Warm Start Up -> Warm | Hot Start Up -> Hot | No Start Up Sequence -> None */
const STG_MODES=[['Cold','Cold Start Up'],['Warm','Warm Start Up'],['Hot','Hot Start Up'],['None','No Start Up Sequence']];
function normStgMode(v){v=String(v||'Cold').toLowerCase();
  if(v.startsWith('cold'))return 'Cold'; if(v.startsWith('warm'))return 'Warm'; if(v.startsWith('hot'))return 'Hot';
  if(v.startsWith('no')||v==='none')return 'None'; return 'Cold';}
function renderStgStartup(){
  const sm=(INPUT.data3.modeling&&INPUT.data3.modeling.stg_startup_mode)||{};
  let h='<thead><tr><th class="ctr" style="width:64px">No</th><th>STG</th><th>Start Up Mode</th></tr></thead><tbody>';
  ['s1','s2','s3'].forEach((s,i)=>{const cur=normStgMode(sm[s+'_startup']);
    h+=`<tr><td class="no ctr">${i+1}</td><td>STG ${i+1}</td>
      <td><select data-stg="${s}">${STG_MODES.map(([v,l])=>`<option value="${v}" ${v===cur?'selected':''}>${l}</option>`).join('')}</select></td></tr>`;});
  $('tbl-stg-startup').innerHTML=h+'</tbody>';
}
function assembleStgStartup(m){
  const o={};
  document.querySelectorAll('#tbl-stg-startup select[data-stg]').forEach(sel=>{o[sel.dataset.stg+'_startup']=sel.value;});
  if(Object.keys(o).length) m.stg_startup_mode=o;
}

/* ===================== Runtime & Downtime (Revisi) =====================
 * Minimum runtime/downtime params (hours) used by the anti start-stop logic. */
const RUNTIME_ROWS=[
  ['minimum_runtime_gtg_small','Minimum runtime — GTG small',4],
  ['minimum_downtime_gtg_small','Minimum downtime — GTG small',2],
  ['minimum_runtime_gtg_large','Minimum runtime — GTG large',6],
  ['minimum_downtime_gtg_large','Minimum downtime — GTG large',4],
  ['minimum_runtime_combined_cycle','Minimum runtime — combined cycle',6],
  ['minimum_downtime_combined_cycle','Minimum downtime — combined cycle',3],
  ['minimum_runtime_stg','Minimum runtime — STG',6],
  ['minimum_downtime_stg','Minimum downtime — STG',3]
];
function renderRuntime(){
  const rd=(INPUT.data3.modeling&&INPUT.data3.modeling.runtime_downtime)||{};
  let h='<thead><tr><th class="ctr" style="width:60px">No</th><th>Parameter</th><th style="width:130px">Value</th><th style="width:90px">Unit</th></tr></thead><tbody>';
  RUNTIME_ROWS.forEach(([k,label,def],i)=>{
    const v=(rd[k]!=null)?rd[k]:def;
    h+=`<tr><td class="no ctr">${i+1}</td><td>${label}</td>`+
       `<td><input class="no-spinner" type="number" step="0.5" min="0" data-rt="${k}" value="${v}" style="text-align:right"></td>`+
       `<td>Hour</td></tr>`;
  });
  $('tbl-runtime').innerHTML=h+'</tbody>';
}
function assembleRuntime(m){
  const o={};
  document.querySelectorAll('#tbl-runtime input[data-rt]').forEach(el=>{o[el.dataset.rt]=num(el.value);});
  if(Object.keys(o).length) m.runtime_downtime=o;
}

/* ===================== Multi Dispatch Deviation (Revisi) =====================
 * Up to 5 period rules: each {min,max,start,stop}. start/stop are 1..48 slots
 * (Period Start 00:00..23:30, Period Stop 00:30..00:00). Outside any rule the
 * export is free within Range Min/Max. Mapped to dispatch_dev_rules[]. */
let DD_RULES=[];
function renderDdRules(){
  const host=$('pln-dd-rules'); if(!host) return;
  let h='';
  DD_RULES.forEach((r,i)=>{
    h+=`<div class="dd-rule">
      <span class="dd-no">${i+1}</span>
      <label class="fld">Dispatch dev − <span class="u">MW</span><input type="number" step="0.1" data-dd="min" data-i="${i}" value="${r.min}"></label>
      <label class="fld">Dispatch dev + <span class="u">MW</span><input type="number" step="0.1" data-dd="max" data-i="${i}" value="${r.max}"></label>
      <label class="fld">Period Start<select data-dd="start" data-i="${i}">${startOptions(clamp1(r.start))}</select></label>
      <label class="fld">Period Stop<select data-dd="stop" data-i="${i}">${stopOptions(clamp1(r.stop))}</select></label>
      <button type="button" class="rowdel" data-dd="del" data-i="${i}" title="Remove rule" ${DD_RULES.length<=1?'disabled':''}>✕</button>
    </div>`;
  });
  host.innerHTML=h;
  host.querySelectorAll('[data-dd="min"]').forEach(el=>el.oninput=e=>{DD_RULES[+e.target.dataset.i].min=num(e.target.value);});
  host.querySelectorAll('[data-dd="max"]').forEach(el=>el.oninput=e=>{DD_RULES[+e.target.dataset.i].max=num(e.target.value);});
  host.querySelectorAll('[data-dd="start"]').forEach(el=>el.onchange=e=>{DD_RULES[+e.target.dataset.i].start=+e.target.value;});
  host.querySelectorAll('[data-dd="stop"]').forEach(el=>el.onchange=e=>{DD_RULES[+e.target.dataset.i].stop=+e.target.value;});
  host.querySelectorAll('[data-dd="del"]').forEach(el=>el.onclick=e=>{if(DD_RULES.length>1){DD_RULES.splice(+e.target.dataset.i,1);renderDdRules();}});
  const add=$('btn-dd-add'); if(add) add.disabled=DD_RULES.length>=5;
}

/* ===== Additional Range PLN Rules (Revisi): up to 4 {min,max,start,stop}; within main range; no overlap.
 * Outside any rule period the main Range Min/Max applies. start/stop are 1..48 slots. ===== */
let RANGE_RULES=[];
function renderRangeRules(){
  const host=$('pln-range-rules'); if(!host) return;
  let h='';
  RANGE_RULES.forEach((r,i)=>{
    h+=`<div class="dd-rule">
      <span class="dd-no">${i+1}</span>
      <label class="fld">Range min <span class="u">MW</span><input type="number" step="0.1" data-rr="min" data-i="${i}" value="${r.min}"></label>
      <label class="fld">Range max <span class="u">MW</span><input type="number" step="0.1" data-rr="max" data-i="${i}" value="${r.max}"></label>
      <label class="fld">Period Start<select data-rr="start" data-i="${i}">${startOptions(clamp1(r.start))}</select></label>
      <label class="fld">Period Stop<select data-rr="stop" data-i="${i}">${stopOptions(clamp1(r.stop))}</select></label>
      <button type="button" class="rowdel" data-rr="del" data-i="${i}" title="Remove rule">✕</button>
    </div>`;
  });
  host.innerHTML=h;
  host.querySelectorAll('[data-rr="min"]').forEach(el=>el.oninput=e=>{RANGE_RULES[+e.target.dataset.i].min=num(e.target.value);});
  host.querySelectorAll('[data-rr="max"]').forEach(el=>el.oninput=e=>{RANGE_RULES[+e.target.dataset.i].max=num(e.target.value);});
  host.querySelectorAll('[data-rr="start"]').forEach(el=>el.onchange=e=>{RANGE_RULES[+e.target.dataset.i].start=+e.target.value;});
  host.querySelectorAll('[data-rr="stop"]').forEach(el=>el.onchange=e=>{RANGE_RULES[+e.target.dataset.i].stop=+e.target.value;});
  host.querySelectorAll('[data-rr="del"]').forEach(el=>el.onclick=e=>{RANGE_RULES.splice(+e.target.dataset.i,1);renderRangeRules();});
  const add=$('btn-range-add'); if(add) add.disabled=RANGE_RULES.length>=4;
}
function addRangeRule(){
  if(RANGE_RULES.length>=4){if(typeof toast==='function')toast('Maximum 4 Range PLN rules');return;}
  const mn=num($('f-pln-range-min').value)||0, mx=num($('f-pln-range-max').value)||0;
  let start=1; if(RANGE_RULES.length){start=Math.min(48,Math.max(...RANGE_RULES.map(r=>+r.stop))+1);}
  RANGE_RULES.push({min:mn,max:mx,start:start,stop:Math.min(48,start+1)});
  renderRangeRules();
}
function validateRangeRules(mainMin,mainMax){
  const rs=RANGE_RULES.map(r=>({min:+r.min,max:+r.max,start:+r.start,stop:+r.stop}));
  for(const r of rs){
    if(r.min<mainMin-1e-9||r.max>mainMax+1e-9) return `Range PLN rule [${r.min},${r.max}] must stay within main range [${mainMin},${mainMax}].`;
    if(r.min>r.max+1e-9) return `Range PLN rule min ${r.min} must be <= max ${r.max}.`;
    if(r.start>r.stop) return 'Range PLN rule period Start must be <= Stop.';
  }
  for(let a=0;a<rs.length;a++)for(let b=a+1;b<rs.length;b++)
    if(rs[a].start<=rs[b].stop&&rs[b].start<=rs[a].stop) return 'Additional Range PLN rules overlap. Please adjust the periods.';
  return '';
}

/* ---- assemble back into modeling (called from assembleInput) ---- */
function assembleSchedules(m){
  const us=[],ust=[],hs=[],hst=[];
  STOP_ROWS.forEach(r=>{if(!r.unit)return;const h=isHrsg(r.unit);
    if(r.allDay){(h?hs:us).push(r.unit);}
    else if(h){hst.push({hrsg:r.unit,start:clamp1(r.start),stop:clamp1(r.stop)});}   // engine reads hrsg_stop_time by ['hrsg']
    else{ust.push({unit:r.unit,start:clamp1(r.start),stop:clamp1(r.stop)});}});       // engine reads unit_stop_time by ['unit']
  m.unit_stop=us; m.unit_stop_time=ust; m.hrsg_stop=hs; m.hrsg_stop_time=hst;
  const fx={};
  FIX_ROWS.forEach(r=>{if(!r.unit)return;(fx[r.unit]=fx[r.unit]||[]).push({start:clamp1(r.start),stop:clamp1(r.stop),value:num(r.value)});});
  m.unit_fix_load=fx;
  const sk={};
  SKIP_ROWS.forEach(r=>{if(!r.unit)return;(sk[r.unit]=sk[r.unit]||[]).push({start:clamp1(r.start),stop:clamp1(r.stop),value_low:num(r.low),value_high:num(r.high)});});
  m.unit_skip_load=sk;
}

/* ===================== Gas Shortage Decision ===================== */
function setGasAction(act){
  /* 'recommendation' WAJIB ada di daftar ini. Tanpa itu, klik opsi pertama jatuh ke 'none'
     dan pilihan operator hilang sebelum sempat dikirim. */
  if(!['none','recommendation','add_lng','use_distillate','force_same_as_quota'].includes(act)) act='recommendation';
  const hid=$('f-gas_action'); if(hid) hid.value=act;
  document.querySelectorAll('#gsd-seg .gsd-opt').forEach(b=>{const on=b.dataset.act===act;b.classList.toggle('active',on);b.setAttribute('aria-checked',on?'true':'false');});
  const lngOn=act==='add_lng';
  const wrap=$('gsd-lng-wrap'); if(wrap) wrap.classList.toggle('off',!lngOn);
  const inp=$('f-additional_lng'); if(inp) inp.disabled=!lngOn;
  /* Simetris dengan LNG: mode distillate membuka field liter. Tanpa ini opsi ketiga tidak punya
     jalur input manual sama sekali. */
  const distOn=act==='use_distillate';
  const dwrap=$('gsd-dist-wrap'); if(dwrap) dwrap.classList.toggle('off',!distOn);
  const dinp=$('f-distillate_litres'); if(dinp) dinp.disabled=!distOn;
  /* Notifikasi "never auto-switches to distillate" hanya relevan untuk Gas Shortage Recommendation. */
  const recOn=act==='recommendation';
  ['gsd-never-note','gsd-never-hint'].forEach(id=>{ const el=$(id); if(el) el.style.display=recOn?'':'none'; });
}
function refreshGasDecision(){
  const badge=$('gsd-badge'); if(!badge) return;          // only present when input exists
  const sub=$('gsd-sub'), reqL=$('gsd-req-lng'), estD=$('gsd-est-dist');
  const act=($('f-gas_action')||{}).value||'none';
  let shortage=0, evaluated=false;
  if(OUTPUT&&OUTPUT.info){evaluated=true;shortage=num(OUTPUT.info['Gas Shortage (BBTUD)']);
    if(OUTPUT.info['Gas Quota Status']==='WITHIN QUOTA') shortage=0;}
  badge.className='gsd-badge';
  if(!evaluated||shortage<=0){
    badge.classList.add('ok'); badge.textContent='No Shortage';
    sub.textContent=evaluated?'Gas use is within the PGN quota.':'Run a plan to evaluate the PGN gas quota.';
    reqL.textContent='—'; estD.textContent='—'; return;
  }
  /* SATU SUMBER: angka liter berasal dari backend (info Required/Recommended Distillate) atau
   * dari basis konversi backend. UI tidak lagi memakai konstanta sendiri; bila basis belum ada,
   * liter TIDAK ditampilkan daripada menampilkan angka yang tidak dapat diaudit. */
  const oi=(OUTPUT&&OUTPUT.info)||{};
  let litres=num(oi['Recommended Distillate (l/day)'])||num(oi['Required Distillate (l/day)']);
  if(!(litres>0)) litres=gsfLitresFromBbtu(shortage, oi);
  reqL.textContent=fmt(shortage,3)+' BBTUD';
  estD.textContent=(litres>0)?('~'+fmt(litres,0)+' l/day'):'estimasi tidak tersedia (faktor konversi belum ada)';
  if(act==='none'){badge.classList.add('warn');badge.textContent='Need User Decision';
    sub.textContent='PGN quota exceeded — pick how to cover the gap (it is not done automatically).';}
  else{badge.classList.add('bad');badge.textContent='Shortage Detected';
    sub.textContent=act==='add_lng'?'Covering the gap with additional LNG.':'Covering the gap with distillate.';}
}

/* ===================== priority editors (Revisi Lanjutan Sec.3) ===================== */
const PRI_BLOCKS = [
  {name:'Block 1',               gtg:['g3','g4','g6'],          stg:'s1'},
  {name:'Block 2',               gtg:['g1','g2','g5'],          stg:'s2'},
  {name:'Block 3',               gtg:['g8','g9'],               stg:'s3'},
  {name:'Gas Engine',            gtg:['ge1','ge2','ge3','ge4'], stg:null},
  {name:'Block Babelan',         gtg:['b1','b2'],               stg:null},
  {name:'Standby Unit Jababeka', gtg:['g7'],                    stg:null},
  {name:'Standby Unit MM2100',   gtg:['g10'],                   stg:null},
];
const DIST_BLOCKS = [
  {name:'Block 1',               units:['g3','g4','g6']},
  {name:'Block 2',               units:['g1','g2','g5']},
  {name:'Block 3',               units:['g8','g9']},
  {name:'Standby Unit Jababeka', units:['g7']},
  {name:'Standby Unit MM2100',   units:['g10']},
];
const BP_NAMES=PRI_BLOCKS.map(b=>b.name);
const byName=(n)=>PRI_BLOCKS.find(b=>b.name===n);
function setEq(a,b){a=[...a].sort();b=[...b].sort();return a.length===b.length&&a.every((x,i)=>x===b[i]);}
let BP_STATE=[], UP_STATE={}, DIST_STATE={};

function initPriorityState(){
  const m=INPUT.data3.modeling;
  UP_STATE={};
  PRI_BLOCKS.forEach(b=>{
    let order=b.gtg.slice();
    (m.unit_priority||[]).forEach(blk=>{
      const us=blk.filter(x=>x!=='required'&&b.gtg.includes(x));
      if(us.length&&setEq(us,b.gtg)) order=us.slice();
    });
    UP_STATE[b.name]=order;
  });
  BP_STATE=[];
  (m.block_priority||[]).forEach(blk=>{
    const req=blk.includes('required');
    const units=blk.filter(x=>x!=='required');
    const match=PRI_BLOCKS.find(b=>{const core=units.filter(x=>x!==b.stg);
      return setEq(core,b.gtg)||setEq(units,[...b.gtg,...(b.stg?[b.stg]:[])]);});
    if(match&&!BP_STATE.some(r=>r.block===match.name)) BP_STATE.push({block:match.name,required:req});
  });
  while(BP_STATE.length<7) BP_STATE.push({block:'',required:false});
  BP_STATE=BP_STATE.slice(0,7);
  DIST_STATE={};
  DIST_BLOCKS.forEach(b=>{
    let order=b.units.slice();
    (m.unit_priority_dist||[]).forEach(grp=>{const us=grp.filter(x=>b.units.includes(x));
      if(us.length&&setEq(us,b.units)) order=us.slice();});
    DIST_STATE[b.name]=order;
  });
}
/* put value v at position i, keep the array a valid permutation of pool */
function reorderPick(arr,i,v,pool){
  if(!v) return;
  const above=arr.slice(0,i), out=above.concat([v]);
  arr.forEach(x=>{if(!above.includes(x)&&x!==v&&pool.includes(x)&&!out.includes(x))out.push(x);});
  pool.forEach(x=>{if(!out.includes(x))out.push(x);});
  arr.length=0; out.forEach(x=>arr.push(x));
}
function renderBlockPriority(){
  let h='<tr><th>Priority</th><th>Block</th><th>Units (incl. STG)</th><th>Required</th></tr>';
  BP_STATE.forEach((row,i)=>{
    const elsewhere=BP_STATE.filter((r,j)=>j!==i&&r.block).map(r=>r.block);
    const opts=['<option value="">— none —</option>'].concat(
      BP_NAMES.filter(n=>n===row.block||!elsewhere.includes(n))
        .map(n=>`<option value="${n}" ${row.block===n?'selected':''}>${n}</option>`)).join('');
    const b=row.block?byName(row.block):null;
    const units=b?b.gtg.concat(b.stg?[b.stg]:[]).map(u=>u.toUpperCase()).join(', '):'';
    h+=`<tr><td class="pn">Priority ${i+1}</td><td class="bk"><select data-bp="${i}">${opts}</select></td>
      <td class="hint" style="font-family:var(--mono)">${units}</td>
      <td><label class="prio-req"><input type="checkbox" data-bpreq="${i}" ${row.required?'checked':''}> Required</label></td></tr>`;
  });
  $('tbl-block-prio').innerHTML=h;
  $('tbl-block-prio').querySelectorAll('[data-bp]').forEach(s=>s.addEventListener('change',e=>{
    BP_STATE[+e.target.dataset.bp].block=e.target.value;
    syncBlockRequiredToCommitment();   // full reconcile against the new set of Required blocks
    renderBlockPriority(); syncPriorityMirror();
  }));
  $('tbl-block-prio').querySelectorAll('[data-bpreq]').forEach(c=>c.addEventListener('change',e=>{
    BP_STATE[+e.target.dataset.bpreq].required=e.target.checked;
    syncBlockRequiredToCommitment();   // full reconcile
    syncPriorityMirror();
  }));
}
/* ===== Block Priority Required → Required & Commitment synchronization (Revisi Block Priority Sync) =====
   syncBlockRequiredToCommitment() is the SINGLE authoritative reconciler. It takes no arguments: it reads
   the CURRENT set of Required blocks from BP_STATE, computes each Required block's primary units (STG +
   priority-1 GTG, or priority-1 GE for the Gas Engine block), and makes the commitment table match:

     • Units that this automation set for a block that is NO LONGER Required (or whose block changed) are
       RESET to default (Last Data Status = Stop, Required = false, Commitment Mode = "-", Start At = "-",
       Stop Status = "-", Stop At = "-") — unless the user changed that unit manually after the auto-set,
       in which case it is preserved (metadata auto/manual diff) and a one-time confirm is offered.
     • Units of every currently-Required block are auto-set to Unit Continuous Running + Running + Required.

   AUTO_BLOCK_UNITS = { blockName: { unit: 'continuous' } } is the auto/manual metadata: a unit listed here
   was auto-set by THIS block; if COMMIT_MODE[unit] later differs, the user changed it manually.
   Called on: block dropdown change, Required checkbox change, Unit Priority change, model load, and before
   collect/save. Refreshes the Required & Commitment table (renderUnitFlags) and the model (applyCommitToModel). */
let AUTO_BLOCK_UNITS = {};
function blockPrimaryUnits(b){
  // STG (if any) + priority-1 GTG/GE per Unit Priority; falls back to the block's declared order.
  const out=[];
  if(b.stg) out.push((''+b.stg).toLowerCase());
  const ord=(UP_STATE[b.name]&&UP_STATE[b.name].length)?UP_STATE[b.name]:b.gtg;
  if(ord&&ord.length) out.push((''+ord[0]).toLowerCase());
  return out.filter(u=>FLAG_UNITS.includes(u));
}
function resetUnitCommitment(u){
  COMMIT_MODE[u]='-'; START_AT[u]=''; STOP_MODE[u]='-'; STOP_AT[u]='';
  setUnitLast(u,'Stop');
}
function syncBlockRequiredToCommitment(opts){
  opts=opts||{};
  if(typeof COMMIT_MODE!=='object') return;
  // 1. Current set of Required blocks (by name) and their desired primary units.
  const requiredBlocks={};
  (BP_STATE||[]).forEach(row=>{ if(row&&row.required&&row.block){ const b=byName(row.block); if(b) requiredBlocks[b.name]=blockPrimaryUnits(b); } });

  // 2. RESET phase: for every block that previously auto-set units but is no longer Required (or whose
  //    desired unit set changed, e.g. Unit Priority reorder), reset its now-stale auto units.
  let manualHits=[];
  Object.keys(AUTO_BLOCK_UNITS).forEach(bn=>{
    const prevAuto=AUTO_BLOCK_UNITS[bn]||{};
    const stillReq=requiredBlocks[bn];
    Object.keys(prevAuto).forEach(u=>{
      const stillWanted = stillReq && stillReq.includes(u);
      if(stillWanted) return;                                  // keep: this unit is still a primary of a Required block
      if(COMMIT_MODE[u]!==prevAuto[u]) { manualHits.push(UL(u)); return; }  // user changed it manually -> defer to confirm below
      resetUnitCommitment(u);                                  // pure auto -> safe to reset
    });
  });

  // 3. Manual-override protection: if some stale auto units were changed manually, ask once.
  if(manualHits.length && !opts.silent){
    const ok=(typeof confirm==='function')?confirm(`Block Required berubah. Unit ${manualHits.join(', ')} sudah diubah manual setelah auto-set. Reset ke default juga?`):false;
    if(ok){
      Object.keys(AUTO_BLOCK_UNITS).forEach(bn=>{ const prevAuto=AUTO_BLOCK_UNITS[bn]||{}; const stillReq=requiredBlocks[bn];
        Object.keys(prevAuto).forEach(u=>{ if(!(stillReq&&stillReq.includes(u))) resetUnitCommitment(u); }); });
    }
    // if not ok: leave the manually-changed units as the user set them
  }

  // 4. SET phase: (re)assert every currently-Required block's primary units to Continuous Running + Running.
  const newAuto={};
  Object.keys(requiredBlocks).forEach(bn=>{
    newAuto[bn]={};
    requiredBlocks[bn].forEach(u=>{
      COMMIT_MODE[u]='continuous'; STOP_MODE[u]='-'; STOP_AT[u]=''; START_AT[u]='';
      setUnitLast(u,'Running');
      newAuto[bn][u]='continuous';
    });
  });
  AUTO_BLOCK_UNITS=newAuto;   // metadata now reflects exactly the current Required blocks

  // 5. Refresh model + UI so backend receives exactly what the table shows.
  if(typeof syncFlagArrays==='function') syncFlagArrays();
  if(typeof applyCommitToModel==='function') applyCommitToModel();
  if(typeof renderUnitFlags==='function') renderUnitFlags();
  if(typeof coRender==='function' && CHANGE_OVER && CHANGE_OVER.enabled) coRender();   // Last Status follows Required
}
function renderUnitPriority(){
  let html='';
  PRI_BLOCKS.forEach(b=>{
    const order=UP_STATE[b.name]; let rows='';
    for(let i=0;i<b.gtg.length;i++){
      const above=order.slice(0,i);
      const opts=b.gtg.filter(u=>!above.includes(u))
        .map(u=>`<option value="${u}" ${order[i]===u?'selected':''}>${u.toUpperCase()}</option>`).join('');
      rows+=`<tr><td class="pn">Priority ${i+1}</td><td><select data-up="${b.name}" data-i="${i}">${opts}</select></td></tr>`;
    }
    const stgNote=b.stg?` <span class="units">· STG ${b.stg.toUpperCase()} auto-attached</span>`:'';
    html+=`<div class="blk-card"><div class="blk-h">${b.name}<span class="units">(${b.gtg.map(u=>u.toUpperCase()).join(', ')})</span>${stgNote}</div><table class="prio">${rows}</table></div>`;
  });
  $('up-blocks').innerHTML=html;
  $('up-blocks').querySelectorAll('[data-up]').forEach(s=>s.addEventListener('change',e=>{
    const bn=e.target.dataset.up,i=+e.target.dataset.i;
    reorderPick(UP_STATE[bn],i,e.target.value,byName(bn).gtg);
    renderUnitPriority();
    syncBlockRequiredToCommitment();   // priority-1 GTG may have changed -> re-map auto-required unit
    syncPriorityMirror();}));
}
function renderDistPriority(){
  let html='';
  DIST_BLOCKS.forEach(b=>{
    const order=DIST_STATE[b.name]; let rows='';
    for(let i=0;i<b.units.length;i++){
      const above=order.slice(0,i);
      const opts=b.units.filter(u=>!above.includes(u))
        .map(u=>`<option value="${u}" ${order[i]===u?'selected':''}>${u.toUpperCase()}</option>`).join('');
      rows+=`<tr><td class="pn">Priority ${i+1}</td><td><select data-dist="${b.name}" data-i="${i}">${opts}</select></td></tr>`;
    }
    html+=`<div class="blk-card"><div class="blk-h">${b.name}<span class="units">(${b.units.map(u=>u.toUpperCase()).join(', ')})</span></div><table class="prio">${rows}</table></div>`;
  });
  $('dist-blocks').innerHTML=html;
  $('dist-blocks').querySelectorAll('[data-dist]').forEach(s=>s.addEventListener('change',e=>{
    const bn=e.target.dataset.dist,i=+e.target.dataset.i;
    reorderPick(DIST_STATE[bn],i,e.target.value,DIST_BLOCKS.find(x=>x.name===bn).units); renderDistPriority(); syncPriorityMirror();}));
}
function assemblePriorities(){
  const block_priority=[], unit_priority=[];
  BP_STATE.forEach(row=>{
    if(!row.block) return;
    const b=byName(row.block);
    const ordered=(UP_STATE[b.name]||b.gtg).slice();
    const withStg=b.stg?ordered.concat([b.stg]):ordered.slice();
    block_priority.push(row.required?withStg.concat(['required']):withStg.slice());
    unit_priority.push(withStg.slice());            // aligned index; STG kept for CC min/max
  });
  const unit_priority_dist=DIST_BLOCKS.map(b=>(DIST_STATE[b.name]||b.units).slice());
  return {block_priority,unit_priority,unit_priority_dist};
}
function syncPriorityMirror(){
  const p=assemblePriorities();
  ta('ta-block_priority',p.block_priority); ta('ta-unit_priority',p.unit_priority); ta('ta-unit_priority_dist',p.unit_priority_dist);
}

/* ============== Unit Characteristic coefficient editors (Revisi Lanjutan Sec.2) ============== */
const GTG_UNITS=['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
const GE_UNITS=['ge1','ge2','ge3','ge4'];
const STG_UNITS=['s1','s2','s3'];
let STG_TAB='s1';
function ucBind(scope){
  $(scope).querySelectorAll('[data-uc]').forEach(inp=>inp.addEventListener('input',e=>{
    const u=e.target.dataset.uc,k=e.target.dataset.uck;
    if(!INPUT.data3[u])INPUT.data3[u]={};
    INPUT.data3[u][k]=e.target.value===''?0:num(e.target.value);}));
}
function ucF(u,key,label,opt){
  const c=INPUT.data3[u]||{};
  if(opt&&!(key in c)) return '';
  const v=(key in c)?c[key]:'';
  return `<label class="uc-f">${label}<input type="number" step="any" data-uc="${u}" data-uck="${key}" value="${v}"></label>`;
}
function renderGasCoeffs(){
  // GTG card (with optional 2nd segment)
  const gtgCard=(u)=>{
    const c=INPUT.data3[u]||{};
    const hasF2=('x2_f2'in c)||('x1_f2'in c)||('x0_f2'in c)||('xsp_f2'in c);
    let h=`<div class="uc-card"><h5>${u.toUpperCase()} <span class="badge">GTG</span></h5><div class="uc-row">
      ${ucF(u,'x2_f1','x² coefficient')}${ucF(u,'x1_f1','x coefficient')}${ucF(u,'x0_f1','constant')}${ucF(u,'xcf','correction factor')}</div>`;
    if(hasF2) h+=`<div class="uc-seg"><div class="lbl">Second segment (after breakpoint)</div><div class="uc-row">
      ${ucF(u,'xsp_f2','breakpoint x')}${ucF(u,'x2_f2','x² coefficient')}${ucF(u,'x1_f2','x coefficient')}${ucF(u,'x0_f2','constant')}</div></div>`;
    return h+`</div>`;
  };
  const geCard=(u)=>`<div class="uc-card"><h5>${u.toUpperCase()} <span class="badge">Gas Engine</span></h5><div class="uc-row">
      ${ucF(u,'x2_f1','x² coefficient')}${ucF(u,'x1_f1','x coefficient')}${ucF(u,'x0_f1','constant')}${ucF(u,'xcf','correction factor')}</div></div>`;
  // 3 categories (Revisi UI Sec.2): GTG Frame 6 (G1–G6), GTG Frame 9 (G7–G10), Gas Engine (GE1–GE4)
  const cat=(title,cnt,cards)=>`<div class="uc-cat"><div class="uc-cat-h">${title}<span class="uc-cat-c">${cnt}</span></div><div class="uc-grid">${cards}</div></div>`;
  const frame6=['g1','g2','g3','g4','g5','g6'].map(gtgCard).join('');
  const frame9=['g7','g8','g9','g10'].map(gtgCard).join('');
  const ge=GE_UNITS.map(geCard).join('');
  $('uc-gas').innerHTML = cat('GTG Frame 6','G1 – G6',frame6) + cat('GTG Frame 9','G7 – G10',frame9) + cat('Gas Engine','GE1 – GE4',ge);
  ucBind('uc-gas');
}
function renderStgTabs(){
  $('uc-stg-tabs').innerHTML=STG_UNITS.map(s=>`<button class="uc-tab ${STG_TAB===s?'active':''}" data-stgtab="${s}">${s.toUpperCase()}</button>`).join('');
  $('uc-stg-tabs').querySelectorAll('[data-stgtab]').forEach(b=>b.addEventListener('click',e=>{STG_TAB=e.target.dataset.stgtab;renderStgTabs();renderStgBody();}));
}
/* PROMPT STG 3-SEGMENT: auto-migrate S1/S2 Format 3-3-1 lama (2 segment) menjadi 3 segment.
   Segment 3 default = copy koefisien Segment 2; threshold default T1=55, T2=71 (wajib T1<T2).
   Nilai masuk ke INPUT.data3[s] sehingga tersimpan saat Save/Run (bukan hanya tampilan). */
let STG_MIGRATED={};
function stgMigrate3Seg(s){
  const c=INPUT.data3&&INPUT.data3[s]; if(!c) return false;
  if(((c.gtg||[]).length)<3) return false;
  let mig=false;
  if(!('s1_f3u3'in c)||!('s0_f3u3'in c)||c.s1_f3u3===''||c.s0_f3u3===''){
    c.s0_f3u3=c.s0_f2u3??0; c.s1_f3u3=c.s1_f2u3??0;
    if('s2_f2u3'in c) c.s2_f3u3=c.s2_f2u3;
    mig=true;
  }
  const t1=Number(c.ssp_f2u3), t2=Number(c.ssp_f3u3);
  if(!('ssp_f3u3'in c)||!isFinite(t1)||!isFinite(t2)||!(t1<t2)){
    if(!isFinite(t1)||!(t1<71)) c.ssp_f2u3=55;
    c.ssp_f3u3=71;
    mig=true;
  }
  if(mig) STG_MIGRATED[s]=true;
  return mig;
}
/* PROMPT ISOLASI FORMULA STG: koefisien per-KOMBO GTG. Data lama hanya punya key shared
   per-count (s1_f1u1 dipakai semua kartu "with G1/G2/G5 connected" -> mengubah satu kartu
   mengubah semuanya + id input duplikat). Migrasi: materialisasi key per-kombo
   (s1_f1u1_g1, s1_f1u1_g2, ..., s1_f1u2_g1g2, ...) dgn nilai awal = copy dari key shared,
   sehingga setiap kombo independen. Key shared lama TIDAK dihapus (fallback engine +
   kompatibilitas snapshot lama). Format 3-3-1 = satu kombo per STG, key u3 tetap. */
function stgMigratePerCombo(s){
  const c=INPUT.data3&&INPUT.data3[s]; if(!c) return false;
  const gtg=(c.gtg||[]).map(x=>String(x).toLowerCase());
  const B1=['s2_f1u1','s1_f1u1','s0_f1u1','ssp_f2u1','s2_f2u1','s1_f2u1','s0_f2u1'];
  const B2=['s2_f1u2','s1_f1u2','s0_f1u2','ssp_f2u2','s2_f2u2','s1_f2u2','s0_f2u2'];
  let mig=false;
  const mat=(bases,sfx)=>{bases.forEach(b=>{ if((b in c)&&!((b+sfx) in c)){ c[b+sfx]=c[b]; mig=true; } });};
  gtg.forEach(g=>mat(B1,'_'+g));
  for(let i=0;i<gtg.length;i++)for(let j=i+1;j<gtg.length;j++)mat(B2,'_'+gtg[i]+gtg[j]);
  return mig;
}
function renderStgBody(){
  ['s1','s2'].forEach(stgMigrate3Seg);   // migrasi utk KEDUA unit agar Save mempersistkan keduanya
  ['s1','s2','s3'].forEach(stgMigratePerCombo);   // PROMPT ISOLASI: per-kombo utk SEMUA STG
  const s=STG_TAB, c=INPUT.data3[s]||{};
  const gtgL=(c.gtg||[]).map(x=>String(x).toLowerCase());
  const gtg=gtgL.map(x=>x.toUpperCase());
  /* Segment block per connected-GTG count (u1/u2) — key PER-KOMBO via sfx (mis. _g1, _g1g2).
     2 segments: Segment 1 (f1) + Segment 2 (f2), "After MW" = breakpoint. Khusus S1/S2
     Format 3-3-1 (u3) dipakai seg3() dengan 3 segment eksplisit (satu kombo, tanpa sfx). */
  const seg2=(uc,sfx)=>{
    const s1=[ucF(s,`s1_f1u${uc}${sfx}`,'x coefficient',true),ucF(s,`s0_f1u${uc}${sfx}`,'constant',true)].filter(Boolean).join('');
    const s2=[ucF(s,`ssp_f2u${uc}${sfx}`,'After MW',true),ucF(s,`s2_f2u${uc}${sfx}`,'x² coefficient',true),ucF(s,`s1_f2u${uc}${sfx}`,'x coefficient',true),ucF(s,`s0_f2u${uc}${sfx}`,'constant',true)].filter(Boolean).join('');
    let h='';
    if(s1) h+=`<div class="uc-seg"><div class="lbl">Segment 1</div><div class="uc-row">${s1}</div></div>`;
    if(s2) h+=`<div class="uc-seg"><div class="lbl">Segment 2</div><div class="uc-row">${s2}</div></div>`;
    if(!s1&&!s2) h=`<div class="hint">No coefficients defined for this configuration.</div>`;
    return h;
  };
  /* PROMPT STG 3-SEGMENT (S1/S2 u3): tiga segment PENUH, masing-masing set koefisien sendiri.
     Threshold 1 = ssp_f2u3 (default 55 MW), Threshold 2 = ssp_f3u3 (default 71 MW).
     Segment 1: Load < T1 · Segment 2: T1 ≤ Load ≤ T2 · Segment 3: Load > T2 (tanpa gap/overlap). */
  const seg3=(uc)=>{
    const row=(f)=>[ucF(s,`s2_${f}u${uc}`,'x² coefficient',true),ucF(s,`s1_${f}u${uc}`,'x coefficient'),ucF(s,`s0_${f}u${uc}`,'constant')].filter(Boolean).join('');
    return `<div class="uc-seg"><div class="lbl">${s.toUpperCase()} Segment 1 · Load &lt; Threshold 1</div><div class="uc-row">${row('f1')}</div></div>`
         + `<div class="uc-seg"><div class="lbl">${s.toUpperCase()} Segment 2 · Threshold 1 ≤ Load ≤ Threshold 2</div><div class="uc-row">${ucF(s,`ssp_f2u${uc}`,'Threshold 1 (MW)')}${row('f2')}</div></div>`
         + `<div class="uc-seg"><div class="lbl">${s.toUpperCase()} Segment 3 · Load &gt; Threshold 2</div><div class="uc-row">${ucF(s,`ssp_f3u${uc}`,'Threshold 2 (MW)')}${row('f3')}</div></div>`;
  };
  const combo=(title,uc,three,sfx)=>`<div class="uc-card"><h5>${title}</h5>${three?seg3(uc):seg2(uc,sfx||'')}</div>`;
  const pairs=[]; for(let i=0;i<gtgL.length;i++)for(let j=i+1;j<gtgL.length;j++)pairs.push([gtgL[i],gtgL[j]]);
  let html='';
  if(gtg.length>=1){
    html+=`<div class="uc-fmt"><h4>Format 1-1-1 <span class="badge">1 GTG connected · formula per GTG</span></h4><div class="uc-grid">`;
    gtgL.forEach(g=>html+=combo(`${s.toUpperCase()} with ${g.toUpperCase()} connected`,'1',false,`_${g}`));   // PROMPT ISOLASI: key per-kombo
    html+=`</div></div>`;
  }
  if(pairs.length>=1){
    html+=`<div class="uc-fmt"><h4>Format 2-2-1 <span class="badge">2 GTG connected · formula per pasangan</span></h4><div class="uc-grid">`;
    pairs.forEach(p=>html+=combo(`${s.toUpperCase()} with ${p[0].toUpperCase()} + ${p[1].toUpperCase()} connected`,'2',false,`_${p[0]}${p[1]}`));
    html+=`</div></div>`;
  }
  if(gtg.length>=3 && (s==='s1'||s==='s2')){   // Format 3-3-1 only for S1 and S2 — 3 SEGMENT
    html+=`<div class="uc-fmt"><h4>Format 3-3-1 <span class="badge">3 GTG connected · 3 segments</span></h4><div class="uc-grid">`;
    html+=combo(`${s.toUpperCase()} with ${gtg.join(' + ')} connected`,'3',true);
    html+=`</div></div>`;
    if(STG_MIGRATED[s]) html+=`<div class="hint" style="color:#b45309">${s.toUpperCase()} STG Load Characteristic migrated from 2 segments to 3 segments. Please verify Segment 3 coefficients.</div>`;
  }
  html+=`<div class="hint" style="margin-top:8px">Format 1-1-1 / 2-2-1: up to 2 segments; <b>After MW</b> is the load above which Segment 2 applies. <b>S1/S2 Format 3-3-1 memakai 3 segment</b>: Segment 1 dipakai saat load &lt; Threshold 1 (default 55 MW), Segment 2 saat Threshold 1 ≤ load ≤ Threshold 2 (default 71 MW), Segment 3 saat load &gt; Threshold 2. Threshold 1 harus &lt; Threshold 2. <b>Setiap kombinasi GTG punya koefisien SENDIRI</b> — mengubah "with G1 connected" TIDAK mengubah "with G2 connected" (isolasi formula per kombo; data lama otomatis dimigrasi dgn nilai awal sama).</div>`;
  $('uc-stg-body').innerHTML=html||'<div class="hint">No connected GTG for this STG.</div>'; ucBind('uc-stg-body');
}
function renderUnitChar(){ renderGasCoeffs(); renderStgTabs(); renderStgBody(); }
function renderPriorityEditors(){
  initPriorityState(); initUnitFlags();
  // On load, treat any block currently ticked Required as an auto-source so its primary units are seeded
  // and reconciled to the commitment table (Block Priority is the source of truth). silent = no confirm dialog.
  AUTO_BLOCK_UNITS={};
  (BP_STATE||[]).forEach(row=>{ if(row&&row.required&&row.block){ const b=byName(row.block); if(b){ AUTO_BLOCK_UNITS[b.name]={}; blockPrimaryUnits(b).forEach(u=>{AUTO_BLOCK_UNITS[b.name][u]='continuous';}); } } });
  renderBlockPriority(); renderUnitPriority(); renderDistPriority();
  syncBlockRequiredToCommitment({silent:true});
  renderUnitFlags(); syncPriorityMirror();
  if(typeof coInit==='function') coInit();   // Change Over Last Status derives from Block Priority Required
}

/* ===== Required / Commitment flags (Tahap 1 · Revisi Commitment Start/Stop) =====
   Unit Cannot Stop column removed from the UI: Commitment Mode = Unit Continuous Running replaces it.
   unit_cannot_stop is still generated for the backend from that mode. STOP STATUS / STOP AT added. */
const FLAG_UNITS=['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'];
const COMMIT_MODES=[['-','-'],['simulation','Start Based On Simulation'],['request','Start Based on Request'],['continuous','Unit Continuous Running']];
/* STOP STATUS (4 mode, urutan wajib): '-' | Stop Based On Simulation (MANDATORY, waktu dipilih
   optimizer) | Stop Based On Request (waktu ditentukan user) | Stop Based On Simulation or
   Continuous Running (OPTIONAL: optimizer membandingkan stop vs continuous). Enum internal
   'sim_must' baru; 'sim'/'request' dipertahankan agar data lama tetap terbaca. */
const STOP_MODES=[['-','-'],['sim_must','Stop Based On Simulation'],['request','Stop Based On Request'],['sim','Stop Based On Simulation or Continuous Running']];
const STOPAT_TIMES=(()=>{const a=[];for(let m=30;m<24*60;m+=30){const h=String(Math.floor(m/60)).padStart(2,'0'),mm=String(m%60).padStart(2,'0');a.push(h+':'+mm);}a.push('00:00');return a;})();
let REQUIRED_ORDER=[];   // units committed via Request/Simulation (run at least once)
let CANNOT_STOP=[];      // units in Continuous Running (cannot stop) — auto-generated, no UI column
let COMMIT_MODE={};      // u -> '-','request','simulation','continuous'
let START_AT={};         // u -> 'HH:MM' (Start Based on Request only)
let STOP_MODE={};        // u -> '-','sim','request'  (STOP STATUS column)
let STOP_AT={};          // u -> 'HH:MM' (Stop Based on Request only)
function buildRequiredModeObj(){
  const rm={};
  FLAG_UNITS.forEach(u=>{
    if(COMMIT_MODE[u]==='request') rm[u]={mode:'start_at',at:START_AT[u]||''};
    else if(COMMIT_MODE[u]==='simulation') rm[u]={mode:'based_on_sim'};
    else if(COMMIT_MODE[u]==='continuous') rm[u]={mode:'continuous'};
  });
  return rm;
}
function buildStopModeObj(){
  const sm={};
  FLAG_UNITS.forEach(u=>{
    if(COMMIT_MODE[u]==='continuous') return;                      // continuous: Stop Status/Stop At disabled
    if(STOP_MODE[u]==='request') sm[u]={mode:'stop_at',at:STOP_AT[u]||''};
    else if(STOP_MODE[u]==='sim_must') sm[u]={mode:'based_on_sim_must'};   // mandatory stop
    else if(STOP_MODE[u]==='sim') sm[u]={mode:'based_on_sim'};              // optional stop-or-continuous
  });
  return sm;
}
function syncFlagArrays(){
  REQUIRED_ORDER=FLAG_UNITS.filter(u=>COMMIT_MODE[u]==='request'||COMMIT_MODE[u]==='simulation');
  CANNOT_STOP=FLAG_UNITS.filter(u=>COMMIT_MODE[u]==='continuous');
}
function applyCommitToModel(){
  if(!(INPUT.data3&&INPUT.data3.modeling))return;
  const m=INPUT.data3.modeling;
  m.required_units=REQUIRED_ORDER.slice();
  m.unit_cannot_stop=CANNOT_STOP.slice();      // backend structure auto-generated from Unit Continuous Running
  m.required_mode=buildRequiredModeObj();
  m.stop_mode=buildStopModeObj();
  if(typeof buildChangeOverObj==='function') m.change_over=buildChangeOverObj();
}
/* ===== Change Over Block 1-2 (Revisi Change Over · hard constraint) =====
   UI writes model.change_over = { enabled, blocks:{ '1':{last,startOther,stopOther}, '2':{...} } }.
   Last Status Block is derived from Block Priority → Required (Running if that block is Required, else Stop);
   the user cannot edit it. Start Other is enabled only for a Stopped block; Stop Other only for a Running
   block. Values: 'sim' (Based on simulation) or 'HH:MM'. The backend reads this as a hard constraint. */
const CO_TIMES=(()=>{const a=[];for(let m=30;m<24*60;m+=30){const h=String(Math.floor(m/60)).padStart(2,'0'),mm=String(m%60).padStart(2,'0');a.push(h+':'+mm);}a.push('00:00');return a;})();
let CHANGE_OVER={enabled:false, b:{'1':{startOther:'sim',stopOther:'sim'}, '2':{startOther:'sim',stopOther:'sim'}}};
function coBlockRequired(num){
  // Block 1 = 'Block 1', Block 2 = 'Block 2' in BP_STATE
  const name='Block '+num;
  return (BP_STATE||[]).some(r=>r&&r.block===name&&r.required);
}
function coLastStatus(num){ return coBlockRequired(num)?'Running':'Stop'; }
function coBlockPrimary(num){
  // priority-1 GTG (from Unit Priority) + STG for the block, lowercased
  const b=byName('Block '+num); if(!b) return {gtg:'',stg:''};
  const ord=(UP_STATE[b.name]&&UP_STATE[b.name].length)?UP_STATE[b.name]:b.gtg;
  return {gtg:(ord&&ord[0]?(''+ord[0]).toLowerCase():''), stg:(b.stg?(''+b.stg).toLowerCase():'')};
}
function buildChangeOverObj(){
  if(!CHANGE_OVER.enabled) return {enabled:false};
  const mk=(num)=>{
    const last=coLastStatus(num); const prim=coBlockPrimary(num);
    const o={block:num, last_status:last, gtg:prim.gtg, stg:prim.stg};
    if(last==='Stop'){ o.start_other=CHANGE_OVER.b[num].startOther||'sim'; }
    else { o.stop_other=CHANGE_OVER.b[num].stopOther||'sim'; }
    return o;
  };
  return {enabled:true, blocks:{'1':mk('1'), '2':mk('2')}};
}
function coRender(){
  const yes=$('co-yes'), no=$('co-no'), body=$('co-body');
  if(yes) yes.classList.toggle('active',CHANGE_OVER.enabled);
  if(no) no.classList.toggle('active',!CHANGE_OVER.enabled);
  if(body) body.style.display=CHANGE_OVER.enabled?'':'none';
  const t=$('tbl-changeover'); if(!t) return;
  if(!CHANGE_OVER.enabled){ t.innerHTML=''; const w=$('co-warn'); if(w)w.innerHTML=''; return; }
  const timeOpts=(cur)=>['<option value="sim"'+((cur==='sim'||!cur)?' selected':'')+'>Based on simulation</option>']
    .concat(CO_TIMES.map(x=>`<option value="${x}"${x===cur?' selected':''}>${x}</option>`)).join('');
  let h='<tr><th>Block</th><th style="text-align:center">Last Status Block</th><th style="text-align:center">Start Other Block 1 or 2</th><th style="text-align:center">Stop Other Block 1 or 2</th></tr>';
  ['1','2'].forEach(num=>{
    const last=coLastStatus(num); const running=(last==='Running');
    const st=CHANGE_OVER.b[num];
    h+=`<tr><td>Block ${num}</td>
      <td style="text-align:center"><span class="co-badge ${running?'run':'stop'}">${last}</span></td>
      <td style="text-align:center"><select data-co="${num}" data-k="startOther" ${running?'disabled':''} style="min-width:170px">${timeOpts(st.startOther)}</select></td>
      <td style="text-align:center"><select data-co="${num}" data-k="stopOther" ${running?'':'disabled'} style="min-width:170px">${timeOpts(st.stopOther)}</select></td></tr>`;
  });
  t.innerHTML=h;
  t.querySelectorAll('[data-co]').forEach(el=>el.addEventListener('change',e=>{
    CHANGE_OVER.b[e.target.dataset.co][e.target.dataset.k]=e.target.value;
    applyCommitToModel();
  }));
  // warning: change over needs at least one block Required
  const w=$('co-warn');
  if(w){
    if(!coBlockRequired('1')&&!coBlockRequired('2')){
      w.innerHTML='<span style="color:#b91c1c;font-weight:600">⚠ Change Over Block 1-2 membutuhkan minimal salah satu Block 1 atau Block 2 Required (di Block Priority). Tanpa anchor block, candidate tidak boleh PASS.</span>';
    } else { w.innerHTML=''; }
  }
  applyCommitToModel();
}
function coInit(){
  const m=(INPUT.data3&&INPUT.data3.modeling)||{};
  const co=m.change_over||{};
  CHANGE_OVER.enabled=!!co.enabled;
  if(co.blocks){ ['1','2'].forEach(n=>{ const b=co.blocks[n]||{};
    CHANGE_OVER.b[n].startOther=b.start_other||'sim'; CHANGE_OVER.b[n].stopOther=b.stop_other||'sim'; }); }
  const yes=$('co-yes'), no=$('co-no');
  if(yes&&!yes._coBound){ yes._coBound=1; yes.addEventListener('click',()=>{ CHANGE_OVER.enabled=true; coRender(); }); }
  if(no&&!no._coBound){ no._coBound=1; no.addEventListener('click',()=>{ CHANGE_OVER.enabled=false; coRender(); }); }
  coRender();
}

function setUnitLast(u,status){  // keep the Unit Last Data Status table in sync with Commitment Mode (Revisi Sec.A/B)
  const sel=document.getElementById('uld-'+u.toUpperCase());
  if(sel){ sel.value=status; if(typeof uldColor==='function')uldColor(sel); }
  if(INPUT.data3&&INPUT.data3.modeling){ const m=INPUT.data3.modeling; m.unit_last_data_status=m.unit_last_data_status||{}; m.unit_last_data_status[u.toUpperCase()]=status; }
}
function initUnitFlags(){
  const m=(INPUT.data3&&INPUT.data3.modeling)||{};
  const req=(m.required_units||[]).map(u=>(''+u).toLowerCase());
  const cs=(m.unit_cannot_stop||[]).map(u=>(''+u).toLowerCase());
  const rm=m.required_mode||{};
  const sm=m.stop_mode||{};
  COMMIT_MODE={}; START_AT={}; STOP_MODE={}; STOP_AT={};
  FLAG_UNITS.forEach(u=>{
    const c=rm[u]||rm[u.toUpperCase()]||{};
    const cm=(''+(c.mode||'')).toLowerCase();
    if(cs.includes(u)||cm==='continuous') COMMIT_MODE[u]='continuous';
    else if(cm==='start_at'){ COMMIT_MODE[u]='request'; START_AT[u]=c.at||c.start_at||''; }
    else if(req.includes(u)||cm==='based_on_sim') COMMIT_MODE[u]='simulation';
    else COMMIT_MODE[u]='-';
    const s=sm[u]||sm[u.toUpperCase()]||{};
    const smode=(''+(s.mode||'')).toLowerCase();
    if(COMMIT_MODE[u]==='continuous') STOP_MODE[u]='-';
    else if(smode==='stop_at'){ STOP_MODE[u]='request'; STOP_AT[u]=s.at||s.stop_at||''; }
    else if(smode==='based_on_sim_must') STOP_MODE[u]='sim_must';
    else if(smode==='based_on_sim') STOP_MODE[u]='sim';
    else STOP_MODE[u]='-';
  });
  syncFlagArrays();
}
function uldStatusOf(u){
  const st=(INPUT&&INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.unit_last_data_status)||{};
  return String(st[u.toUpperCase()]||st[u]||'Stop');
}
function renderUnitFlags(){
  if(!Object.keys(COMMIT_MODE).length) initUnitFlags();
  /* Columns (Revisi Commitment Start/Stop):
       Unit · Unit Last Data Status · Required · Commitment Mode · Start At · STOP STATUS · STOP AT
     Unit Cannot Stop column removed (Unit Continuous Running replaces it; unit_cannot_stop is generated).
     Unit Last Data Status moved in from its old standalone table (same select ids, so existing collect/sync
     code keeps working) and is auto-forced by Commitment Mode:
       Continuous -> Running (locked) · Request/Simulation -> Stop (locked) · '-' -> user editable. */
  let h='<tr><th>Unit</th><th style="text-align:center">Unit Last Data Status</th><th style="text-align:center">Required</th><th style="text-align:center">Commitment Mode</th><th style="text-align:center">Start At</th><th style="text-align:center">Stop Status</th><th style="text-align:center">Stop At</th></tr>';
  FLAG_UNITS.forEach(u=>{
    const mode=COMMIT_MODE[u]||'-';
    const req=(mode!=='-'), isReq=(mode==='request'), isCont=(mode==='continuous');
    const opts=COMMIT_MODES.map(([v,lbl])=>`<option value="${v}"${v===mode?' selected':''}>${lbl}</option>`).join('');
    const uldLocked=(mode!=='-');
    const uldV=isCont?'Running':(req?'Stop':uldStatusOf(u));
    const uldRun=/run/i.test(uldV);
    const smode=isCont?'-':(STOP_MODE[u]||'-');
    const sOpts=STOP_MODES.map(([v,lbl])=>`<option value="${v}"${v===smode?' selected':''}>${lbl}</option>`).join('');
    const isStopReq=(smode==='request');
    const saOpts=['<option value=""'+((STOP_AT[u]||'')===''?' selected':'')+'>-</option>']
      .concat(STOPAT_TIMES.map(t=>`<option value="${t}"${t===(STOP_AT[u]||'')?' selected':''}>${t}</option>`)).join('');
    h+=`<tr><td>${UL(u)}</td>
      <td style="text-align:center"><select id="uld-${u.toUpperCase()}" data-flag="uld" data-u="${u}" class="uld-sel ${uldRun?'uld-run':'uld-stop'}" ${uldLocked?'disabled':''}>
        <option value="Running"${uldRun?' selected':''}>Running</option><option value="Stop"${uldRun?'':' selected'}>Stop</option></select></td>
      <td style="text-align:center"><input type="checkbox" data-flag="req" data-u="${u}" ${req?'checked':''}></td>
      <td style="text-align:center"><select class="uld-sel" data-flag="mode" data-u="${u}" ${req?'':'disabled'} style="min-width:190px">${opts}</select></td>
      <td style="text-align:center"><input type="time" data-flag="startat" data-u="${u}" value="${START_AT[u]||''}" ${isReq?'':'disabled'} style="width:108px"></td>
      <td style="text-align:center"><select data-flag="stopmode" data-u="${u}" ${isCont?'disabled':''} style="min-width:230px">${sOpts}</select></td>
      <td style="text-align:center"><select data-flag="stopat" data-u="${u}" ${isStopReq?'':'disabled'} style="width:90px">${saOpts}</select></td></tr>`;
  });
  const t=$('tbl-unit-flags'); if(!t)return; t.innerHTML=h;
  t.querySelectorAll('[data-flag]').forEach(el=>el.addEventListener('change',e=>{
    const u=e.target.dataset.u, kind=e.target.dataset.flag;
    if(kind==='req'){
      if(e.target.checked){ if(COMMIT_MODE[u]==='-')COMMIT_MODE[u]='simulation'; if(COMMIT_MODE[u]!=='continuous')setUnitLast(u,'Stop'); }
      else COMMIT_MODE[u]='-';
    } else if(kind==='mode'){
      const v=e.target.value; COMMIT_MODE[u]=v;
      if(v==='continuous'){ setUnitLast(u,'Running'); STOP_MODE[u]='-'; STOP_AT[u]=''; if(typeof toast==='function')toast(`${UL(u)} Continuous Running → wajib berbeban 00:30–00:00, Last Data Status = Running, Stop Status/Stop At disabled.`); }
      else if(v==='request'||v==='simulation'){ setUnitLast(u,'Stop'); }
    } else if(kind==='startat'){
      START_AT[u]=e.target.value;
    } else if(kind==='stopmode'){
      STOP_MODE[u]=e.target.value;
      if(STOP_MODE[u]!=='request') STOP_AT[u]='';
    } else if(kind==='stopat'){
      STOP_AT[u]=e.target.value;
    } else if(kind==='uld'){
      setUnitLast(u,e.target.value); if(typeof uldColor==='function')uldColor(e.target);
    }
    syncFlagArrays(); applyCommitToModel(); renderUnitFlags();
  }));
}

/* ===================== tabs ===================== */
document.querySelectorAll('.tab').forEach(b=>b.addEventListener('click',()=>{
  document.querySelectorAll('.tab').forEach(x=>x.setAttribute('aria-selected','false'));
  document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
  b.setAttribute('aria-selected','true');
  $('panel-'+b.dataset.tab).classList.add('active');
  if(b.dataset.tab==='report' && typeof raInit==='function') raInit();   // build/refresh Report → Actual on open
  if(b.dataset.tab==='overview'){ if(typeof ovTrendInit==='function') ovTrendInit(); if(typeof ovPlantRender==='function') ovPlantRender(); }   // build Trend + Plant on open
}));

/* ---- Daily Plan sub-tabs (Periodic | Frequently | Result) ---- */
function activateSub(sub){
  document.querySelectorAll('#panel-daily .subtab').forEach(t=>t.classList.toggle('active',t.dataset.sub===sub));
  document.querySelectorAll('#panel-daily .subpanel').forEach(p=>p.classList.toggle('active',p.id==='sp-'+sub));
}
function activateChild(group,cp){
  document.querySelectorAll(`.childtab[data-child="${group}"]`).forEach(t=>t.classList.toggle('active',t.dataset.cp===cp));
  document.querySelectorAll(`.childpanel[data-child="${group}"]`).forEach(p=>p.classList.toggle('active',p.id===cp));
}
/* V12 DISTILLATE PER SEL: persen dari data numerik output (DistPct_/GasPct_ dari engine; DistMix_ sebagai cadangan output lama).
 * 0 % = tanpa biru (warna gas normal), 100 % = biru paling tua, nilai antara = gradasi proporsional. */
function distPctOf(r,k){ const p=r['DistPct_'+k]; if(p!=null&&p!=='') return Math.max(0,Math.min(100,+p)); const m=+(r['DistMix_'+k]||0); return Math.max(0,Math.min(100,m*100)); }
function distCellStyle(r,k){
  const p=distPctOf(r,k)/100; if(!(p>0)) return '';
  const a=[255,255,255], b=[29,78,216]; const c=a.map((x,i)=>Math.round(x+(b[i]-x)*p));
  const bd=a.map((x,i)=>Math.round(x+(b[i]-x)*Math.min(1,p+0.15)));
  return ` style="--dbg:rgb(${c.join(',')});--dfg:${p>=0.55?'#ffffff':'#1e3a8a'};--dbd:rgb(${bd.join(',')})"`;
}
function distCellData(r,k){
  const p=distPctOf(r,k); const g=(r['GasPct_'+k]!=null)?+r['GasPct_'+k]:(100-p);
  const at=(n,v)=>(v==null||v==='')?'':` data-${n}="${gsfEsc(String(v))}"`;
  return at('dist-unit',k)+at('dist-time',r.Time)+at('dist-pct',p)+at('gas-pct',g)+at('dist-l',r['Dist_'+k])+at('dist-flow',r['DistFlow_'+k])+at('gas-flow',r['GasFlow_'+k])+at('gas-bbtu',r['GasBBTU_'+k])+at('fuel-src',r['FuelSrc_'+k]);
}
function distTipText(td){
  const d=td.dataset; const f=(v,n)=>(v==null||v==='')?'—':fmt(+v,n);
  return 'Distillate : '+f(d.distPct,0)+'%\nGas        : '+f(d.gasPct,0)+'%\nDistillate : '+f(d.distL,1)+' l/slot ('+f(d.distFlow,1)+' l/jam)'
    +'\nGas flow   : '+f(d.gasFlow,4)+' MMSCFD ('+f(d.gasBbtu,4)+' BBTU/slot)\nUnit       : '+(d.distUnit||'—')+'\nTime       : '+(d.distTime||'—')+(d.fuelSrc?'\nSumber     : '+d.fuelSrc:'');
}
(function(){
  let tip=null;
  const show=(td,e)=>{ if(!tip){ tip=document.createElement('div'); tip.id='dist-tip'; document.body.appendChild(tip); }
    tip.textContent=distTipText(td); tip.style.display='block';
    const x=Math.min(window.innerWidth-260,(e.clientX||0)+14), y=Math.min(window.innerHeight-150,(e.clientY||0)+14); tip.style.left=x+'px'; tip.style.top=y+'px'; };
  document.addEventListener('mouseover',e=>{ const td=e.target&&e.target.closest?e.target.closest('td[data-dist-pct]'):null; if(td){ td.removeAttribute('title'); show(td,e); } else if(tip) tip.style.display='none'; });
  document.addEventListener('mousemove',e=>{ if(tip&&tip.style.display==='block'){ const td=e.target&&e.target.closest?e.target.closest('td[data-dist-pct]'):null; if(td) show(td,e); else tip.style.display='none'; } });
})();
function showSimulationDataResult(){
  const apply=()=>{
    document.querySelectorAll('.tab').forEach(t=>t.setAttribute('aria-selected',t.dataset.tab==='daily'?'true':'false'));
    document.querySelectorAll('.panel').forEach(x=>x.classList.toggle('active',x.id==='panel-daily'));
    activateSub('result');
    activateChild('result','cp-simdata');
    const daily=document.getElementById('panel-daily');
    const result=document.getElementById('sp-result');
    const sim=document.getElementById('cp-simdata');
    return !!(daily&&result&&sim&&daily.classList.contains('active')&&result.classList.contains('active')&&sim.classList.contains('active'));
  };
  apply();
  /* Re-assert after render/layout callbacks so no late UI rebuild can return to Frequently Input. */
  requestAnimationFrame(()=>{
    apply();
    requestAnimationFrame(()=>{
      apply();
      const target=document.getElementById('result-simdata');
      if(target)target.scrollIntoView({behavior:'smooth',block:'start'});
    });
  });
}
document.querySelectorAll('#panel-daily .subtab').forEach(b=>b.addEventListener('click',()=>activateSub(b.dataset.sub)));
document.querySelectorAll('.childtab').forEach(b=>b.addEventListener('click',()=>activateChild(b.dataset.child,b.dataset.cp)));

/* ============================================================================
   REPORT → ACTUAL (Revisi UI Report Actual/Planning/Planning-Vs-Actual)
   Input Data is the master (54 cols). JBBK (24) / MM2100 (17) / JBBK-MM2100 (30)
   are DERIVED views that copy their columns from Input Data by header name, so
   they always stay in sync. Data persists in-memory (REPORT_ACTUAL) keyed
   report_actual[year][month][YYYY-MM-DD] = {col:value}; upload full-replaces a
   month; Download Format emits an .xlsx template built entirely client-side.
   ========================================================================== */
const RA_INPUT_COLS=["DATE","NET HEATRATE JBBK","NET HEATRATE MM","NET HEATRATE JBBK-MM","GROSS HEATRATE JBBK","GROSS HEATRATE MM","GROSS HEATRATE JBBK-MM","PLN Export","PLN Export (Jam 10:00)","IE (MWh)","IE Max (MW)","GROSS GENERATION JBBK (MWh)","GROSS GENERATION MM (MWh)","GROSS GENERATION JBBK-MM (MWh)","JABABEKA-MM CF (%)","BABELAN CF (%)","HOUSELOAD JBBK (MWh)","HOUSELOAD MM (MWh)","HOUSELOAD JBBK-MM (MWh)","NET GENERATION JBBK (MWh)","NET GENERATION MM (MWh)","NET GENERATION JBBK-MM (MWh)","TOTAL GAS JBBK (MMBTU)","TOTAL GAS MM (MMBTU)","TOTAL GAS JBBK-MM (MMBTU)","PGN (MMSCFD)","PGN (MMBTUD)","LNG (MMBTUD)","LNG QUOTA (MMBTUD)","PEP TGD (MMSCFD)","PEP TGD (MMBTUD)","AKASIA TOTAL (MMSCFD)","AKASIA TOTAL (MMBTUD)","AKASIA JBBK (MMSCFD)","AKASIA JBBK (MMBTUD)","AKASIA MM (MMSCFD)","AKASIA MM (MMBTUD)","BAGS TOTAL (MMSCFD)","BAGS TOTAL (MMBTUD)","BAGS JBBK (MMSCFD)","BAGS JBBK (MMBTUD)","BAGS MM (MMSCFD)","BAGS MM (MMBTUD)","DISTILLATE JBBK (Liter)","DISTILLATE MM (Liter)","DISTILLATE JBBK-MM (Liter)","CANAL WATER (m3)","MAKE UP WATER BLOCK 1 (m3)","MAKE UP WATER BLOCK 2 (m3)","MAKE UP WATER BLOCK 3 (m3)","RATIO MAKE UP WATER (m3/MWh)","GHG EMISSION JBBK (ton CO2e/MWh)","GHG EMISSION MM (ton CO2e/MWh)","GHG EMISSION JBBK-MM (ton CO2e/MWh)"];
const RA_JBBK_COLS=["DATE","NET HEATRATE JBBK","GROSS HEATRATE JBBK","GROSS GENERATION JBBK (MWh)","NET GENERATION JBBK (MWh)","HOUSELOAD JBBK (MWh)","TOTAL GAS JBBK (MMBTU)","PGN (MMSCFD)","PGN (MMBTUD)","LNG (MMBTUD)","LNG QUOTA (MMBTUD)","PEP TGD (MMSCFD)","PEP TGD (MMBTUD)","AKASIA JBBK (MMSCFD)","AKASIA JBBK (MMBTUD)","BAGS JBBK (MMSCFD)","BAGS JBBK (MMBTUD)","DISTILLATE JBBK (Liter)","CANAL WATER (m3)","MAKE UP WATER BLOCK 1 (m3)","MAKE UP WATER BLOCK 2 (m3)","MAKE UP WATER BLOCK 3 (m3)","RATIO MAKE UP WATER (m3/MWh)","GHG EMISSION JBBK (ton CO2e/MWh)"];
// MM2100 has 4 area-specific columns (PEP KP72 / PERTAGAS KP72) that are not in Input Data — shown blank (derived source has no matching header).
const RA_MM_COLS=["DATE","NET HEATRATE MM","GROSS HEATRATE MM","GROSS GENERATION MM (MWh)","NET GENERATION MM (MWh)","HOUSELOAD MM (MWh)","TOTAL GAS MM (MMBTU)","PEP KP72 (MMSCFD)","PEP KP72 (MMBTUD)","PERTAGAS KP72 (MMSCFD)","PERTAGAS KP72 (MMBTUD)","AKASIA MM (MMSCFD)","AKASIA MM (MMBTUD)","BAGS MM (MMSCFD)","BAGS MM (MMBTUD)","DISTILLATE MM (Liter)","GHG EMISSION MM (ton CO2e/MWh)"];
const RA_JBBKMM_COLS=["DATE","NET HEATRATE JBBK-MM","GROSS HEATRATE JBBK-MM","PLN Export","PLN Export (Jam 10:00)","IE (MWh)","IE Max (MW)","GROSS GENERATION JBBK-MM (MWh)","NET GENERATION JBBK-MM (MWh)","HOUSELOAD JBBK-MM (MWh)","JABABEKA-MM CF (%)","BABELAN CF (%)","TOTAL GAS JBBK-MM (MMBTU)","PGN (MMSCFD)","PGN (MMBTUD)","LNG (MMBTUD)","LNG QUOTA (MMBTUD)","PEP TGD (MMSCFD)","PEP TGD (MMBTUD)","AKASIA TOTAL (MMSCFD)","AKASIA TOTAL (MMBTUD)","BAGS TOTAL (MMSCFD)","BAGS TOTAL (MMBTUD)","DISTILLATE JBBK-MM (Liter)","CANAL WATER (m3)","MAKE UP WATER BLOCK 1 (m3)","MAKE UP WATER BLOCK 2 (m3)","MAKE UP WATER BLOCK 3 (m3)","RATIO MAKE UP WATER (m3/MWh)","GHG EMISSION JBBK-MM (ton CO2e/MWh)"];
const RA_MONTHS=['January','February','March','April','May','June','July','August','September','October','November','December'];
const RA_YEARS=[]; for(let y=2026;y<=2035;y++) RA_YEARS.push(y);
// Columns displayed with Round 0 (no decimals); every other numeric column uses Round 2.
const RA_ROUND0_COLS=new Set(["NET HEATRATE JBBK","NET HEATRATE MM","NET HEATRATE JBBK-MM","IE (MWh)","IE Max (MW)","GROSS GENERATION JBBK (MWh)","GROSS GENERATION MM (MWh)","GROSS GENERATION JBBK-MM (MWh)","NET GENERATION JBBK (MWh)","NET GENERATION MM (MWh)","NET GENERATION JBBK-MM (MWh)","GROSS HEATRATE JBBK","GROSS HEATRATE MM","GROSS HEATRATE JBBK-MM","HOUSELOAD JBBK (MWh)","HOUSELOAD MM (MWh)","HOUSELOAD JBBK-MM (MWh)","PLN Export"]);
function raFmt(col,val){
  // Format a report value for display: Round 0 for RA_ROUND0_COLS, else Round 2. Non-numeric passes through.
  if(val===''||val===null||val===undefined) return '';
  const n=Number(val);
  if(!isFinite(n)||(''+val).trim()==='') return ''+val;
  return RA_ROUND0_COLS.has(col) ? String(Math.round(n)) : n.toFixed(2);
}
// Display-only header labels: heatrate params show "(BTU/kWh)". Internal column KEYS stay unchanged so
// data storage, RA_ROUND0_COLS, upload/download headers, and derived-view lookups are unaffected.
const RA_COL_LABELS={
  /* PROMPT REPORT_ACTUAL_UNIT: satuan tampilan gas MMBTUD -> BBTUD (angka sudah besaran BBTUD;
   * key data internal & data tersimpan TIDAK berubah — hanya label UI/template). */
  "PGN (MMBTUD)":"PGN (BBTUD)","LNG (MMBTUD)":"LNG (BBTUD)","LNG QUOTA (MMBTUD)":"LNG QUOTA (BBTUD)",
  "PEP TGD (MMBTUD)":"PEP TGD (BBTUD)","PEP KP72 (MMBTUD)":"PEP KP72 (BBTUD)","PERTAGAS KP72 (MMBTUD)":"PERTAGAS KP72 (BBTUD)",
  "AKASIA TOTAL (MMBTUD)":"AKASIA TOTAL (BBTUD)","AKASIA JBBK (MMBTUD)":"AKASIA JBBK (BBTUD)","AKASIA MM (MMBTUD)":"AKASIA MM (BBTUD)",
  "BAGS TOTAL (MMBTUD)":"BAGS TOTAL (BBTUD)","BAGS JBBK (MMBTUD)":"BAGS JBBK (BBTUD)","BAGS MM (MMBTUD)":"BAGS MM (BBTUD)",
  "NET HEATRATE JBBK":"NET HEATRATE JBBK (BTU/kWh)",
  "NET HEATRATE MM":"NET HEATRATE MM (BTU/kWh)",
  "NET HEATRATE JBBK-MM":"NET HEATRATE JBBK-MM (BTU/kWh)",
  "GROSS HEATRATE JBBK":"GROSS HEATRATE JBBK (BTU/kWh)",
  "GROSS HEATRATE MM":"GROSS HEATRATE MM (BTU/kWh)",
  "GROSS HEATRATE JBBK-MM":"GROSS HEATRATE JBBK-MM (BTU/kWh)"
};
function raColLabel(c){ return RA_COL_LABELS[c]||c; }
let REPORT_ACTUAL=(INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.report_actual)||{};
function raColsFor(area){ return area==='jbbk'?RA_JBBK_COLS:area==='mm2100'?RA_MM_COLS:area==='jbbkmm'?RA_JBBKMM_COLS:RA_INPUT_COLS; }
function raDaysInMonth(year,monthIdx){ return new Date(year,monthIdx+1,0).getDate(); }
function raDateKey(year,monthIdx,day){ return `${year}-${String(monthIdx+1).padStart(2,'0')}-${String(day).padStart(2,'0')}`; }
function raMonthStore(year,month){
  REPORT_ACTUAL[year]=REPORT_ACTUAL[year]||{};
  REPORT_ACTUAL[year][month]=REPORT_ACTUAL[year][month]||{};
  return REPORT_ACTUAL[year][month];
}
function raInit(){
  const ys=$('ra-year'); if(ys&&!ys.options.length){ ys.innerHTML=RA_YEARS.map(y=>`<option value="${y}">${y}</option>`).join(''); }
  const ms=$('ra-month'); if(ms&&!ms.options.length){ ms.innerHTML=RA_MONTHS.map((m,i)=>`<option value="${i}">${m}</option>`).join(''); ms.value=String(new Date().getMonth()); }
  ['ra-year','ra-area','ra-month'].forEach(id=>{ const el=$(id); if(el&&!el._raBound){ el._raBound=1; el.addEventListener('change',raRender); } });
  const dl=$('ra-download'); if(dl&&!dl._raBound){ dl._raBound=1; dl.addEventListener('click',raDownloadFormat); }
  const up=$('ra-upload'); if(up&&!up._raBound){ up._raBound=1; up.addEventListener('change',raUpload); }
  const sv=$('ra-save'); if(sv&&!sv._raBound){ sv._raBound=1; sv.addEventListener('click',raSaveData); }
  raRender();
}
function raRender(){
  const year=+($('ra-year')?.value||RA_YEARS[0]);
  const area=$('ra-area')?.value||'input';
  const monthIdx=+($('ra-month')?.value||0);
  const month=RA_MONTHS[monthIdx];
  const cols=raColsFor(area);
  const days=raDaysInMonth(year,monthIdx);
  const store=raMonthStore(year,month);   // MASTER month store (Input Data), keyed by INPUT-Data column names
  const editable=(area==='input');        // only Input Data is editable; JBBK/MM2100/JBBK-MM2100 are derived views
  let h='<thead><tr>'+cols.map((c,i)=>`<th class="${i===0?'ra-datecol':''}">${raColLabel(c)}</th>`).join('')+'</tr></thead><tbody>';
  for(let d=1;d<=days;d++){
    const key=raDateKey(year,monthIdx,d);
    const row=store[key]||{};
    h+='<tr>';
    cols.forEach((c,ci)=>{
      if(ci===0){ h+=`<td class="ra-datecol" data-key="${key}">${fmtPlanDate(key)}</td>`; return; }   // dd-MMM-yy display; storage key stays YYYY-MM-DD
      // derived views copy by header name from the Input-Data month store (blank if the header has no source, e.g. MM2100 KP72 cols)
      const raw=(row[c]!==undefined&&row[c]!==null)?row[c]:'';
      const disp=raFmt(c,raw);   // Round 0 for listed params, Round 2 otherwise
      if(editable) h+=`<td><input class="ra-cell" data-key="${key}" data-col="${c.replace(/"/g,'&quot;')}" value="${(''+disp).replace(/"/g,'&quot;')}"></td>`;
      else h+=`<td>${disp}</td>`;
    });
    h+='</tr>';
  }
  h+='</tbody>';
  const t=$('ra-table'); if(!t) return; t.innerHTML=h;
  // Download/Upload/Save only meaningful on Input Data (the master); hide on derived views
  const dl=$('ra-download'), upl=$('ra-upload-lbl'), sv=$('ra-save');
  if(dl) dl.style.display=editable?'':'none';
  if(upl) upl.style.display=editable?'':'none';
  if(sv) sv.style.display=editable?'':'none';
  const st=$('ra-status');
  if(st) st.textContent=editable
    ? `Input Data · ${month} ${year} · ${days} days · ${cols.length} columns (master). Edits here flow to JBBK / MM2100 / JBBK-MM2100.`
    : `${area==='jbbk'?'JBBK (24 cols)':area==='mm2100'?'MM2100 (17 cols)':'JBBK-MM2100 (30 cols)'} · derived from Input Data · ${month} ${year} · ${days} days (read-only).`;
  if(editable){
    t.querySelectorAll('input.ra-cell').forEach(inp=>inp.addEventListener('change',e=>{
      const k=e.target.dataset.key, col=e.target.dataset.col;
      let val=e.target.value.trim();
      if(val!==''){ const fmt=raFmt(col,val); if(fmt!==''){ val=fmt; e.target.value=fmt; } }   // apply Round 0/2 on edit
      const s=raMonthStore(year,month); s[k]=s[k]||{}; if(val==='') delete s[k][col]; else s[k][col]=val;
      if(INPUT.data3&&INPUT.data3.modeling) INPUT.data3.modeling.report_actual=REPORT_ACTUAL;
    }));
  }
}
/* ---- Self-contained .xlsx writer/reader (no external library, no CDN) ------
   An .xlsx file is a ZIP archive of XML parts. We implement a minimal STORE-only
   (uncompressed) ZIP writer with correct CRC-32 + local/central headers, and a
   matching reader that locates the sheet XML and pulls cell values (inline +
   shared strings). This is enough for a single-sheet template with a header row
   and DATE column, exactly what Download Format / Upload Data require. */
const XLSXMini=(function(){
  // CRC-32
  const CRC=(function(){ const t=new Uint32Array(256); for(let n=0;n<256;n++){ let c=n; for(let k=0;k<8;k++) c=(c&1)?(0xEDB88320^(c>>>1)):(c>>>1); t[n]=c>>>0; } return t; })();
  function crc32(u8){ let c=0xFFFFFFFF; for(let i=0;i<u8.length;i++) c=CRC[(c^u8[i])&0xFF]^(c>>>8); return (c^0xFFFFFFFF)>>>0; }
  const enc=new TextEncoder();
  const dec=new TextDecoder();
  function esc(s){ return (''+s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function colName(n){ let s=''; n++; while(n>0){ const m=(n-1)%26; s=String.fromCharCode(65+m)+s; n=(n-m-1)/26|0; } return s; }
  // Build sheet XML from array-of-arrays (all cells as inlineStr for simplicity/robustness)
  /* KOMPATIBILITAS DEPLOYMENT: deklarasi XML di bawah ditulis dengan escape heksadesimal
     (\x3c\x3f) sehingga urutan byte pembuka tag PHP tidak pernah muncul di dalam berkas .php
     ini. Pada server dengan `short_open_tag=On` — dan XAMPP memang pernah mengirimkannya
     demikian — urutan byte itu membuka blok PHP dan SELURUH halaman gagal di-parse, bukan
     hanya baris ini. Nilai string JavaScript yang dihasilkan tetap identik. */
  function sheetXml(aoa){
    let rows='';
    for(let r=0;r<aoa.length;r++){
      let cells='';
      for(let c=0;c<aoa[r].length;c++){
        const v=aoa[r][c];
        if(v===''||v===null||v===undefined) continue;
        const ref=colName(c)+(r+1);
        const num=(typeof v==='number')||(/^-?\d+(\.\d+)?$/.test(''+v)&&(''+v).trim()!=='');
        if(num&&c>0) cells+=`<c r="${ref}"><v>${esc(v)}</v></c>`;
        else cells+=`<c r="${ref}" t="inlineStr"><is><t xml:space="preserve">${esc(v)}</t></is></c>`;
      }
      rows+=`<row r="${r+1}">${cells}</row>`;
    }
    return `\x3c\x3fxml version="1.0" encoding="UTF-8" standalone="yes"\x3f\x3e`+
      `<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${rows}</sheetData></worksheet>`;
  }
  function part(name,xml){ return {name, data:enc.encode(xml)}; }
  function buildZip(files){
    // STORE method (0). Assemble local headers + data, then central directory.
    const chunks=[]; const central=[]; let offset=0;
    function u16(n){ return [n&0xFF,(n>>8)&0xFF]; }
    function u32(n){ n>>>=0; return [n&0xFF,(n>>8)&0xFF,(n>>16)&0xFF,(n>>24)&0xFF]; }
    for(const f of files){
      const nameBytes=enc.encode(f.name); const crc=crc32(f.data); const sz=f.data.length;
      const local=[].concat([0x50,0x4B,0x03,0x04],u16(20),u16(0),u16(0),u16(0),u16(0),u32(crc),u32(sz),u32(sz),u16(nameBytes.length),u16(0));
      chunks.push(new Uint8Array(local)); chunks.push(nameBytes); chunks.push(f.data);
      const cen=[].concat([0x50,0x4B,0x01,0x02],u16(20),u16(20),u16(0),u16(0),u16(0),u16(0),u32(crc),u32(sz),u32(sz),u16(nameBytes.length),u16(0),u16(0),u16(0),u16(0),u32(0),u32(offset));
      central.push({head:new Uint8Array(cen),name:nameBytes});
      offset+=local.length+nameBytes.length+sz;
    }
    let cenSize=0; const cenChunks=[];
    for(const c of central){ cenChunks.push(c.head); cenChunks.push(c.name); cenSize+=c.head.length+c.name.length; }
    const eocd=[].concat([0x50,0x4B,0x05,0x06],u16(0),u16(0),u16(central.length),u16(central.length),u32(cenSize),u32(offset),u16(0));
    const all=chunks.concat(cenChunks,[new Uint8Array(eocd)]);
    let total=0; all.forEach(a=>total+=a.length);
    const out=new Uint8Array(total); let p=0; all.forEach(a=>{ out.set(a,p); p+=a.length; });
    return out;
  }
  function write(aoa){
    const files=[
      part('[Content_Types].xml',`\x3c\x3fxml version="1.0" encoding="UTF-8" standalone="yes"\x3f\x3e<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>`),
      part('_rels/.rels',`\x3c\x3fxml version="1.0" encoding="UTF-8" standalone="yes"\x3f\x3e<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>`),
      part('xl/workbook.xml',`\x3c\x3fxml version="1.0" encoding="UTF-8" standalone="yes"\x3f\x3e<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Input Data" sheetId="1" r:id="rId1"/></sheets></workbook>`),
      part('xl/_rels/workbook.xml.rels',`\x3c\x3fxml version="1.0" encoding="UTF-8" standalone="yes"\x3f\x3e<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>`),
      part('xl/worksheets/sheet1.xml',sheetXml(aoa))
    ];
    return buildZip(files);
  }
  // ---- Reader ----
  function u16r(a,o){ return a[o]|(a[o+1]<<8); }
  function u32r(a,o){ return (a[o]|(a[o+1]<<8)|(a[o+2]<<16)|(a[o+3]<<24))>>>0; }
  // ---- Raw DEFLATE (INFLATE) decompressor (RFC 1951), self-contained (no CDN/library).
  // Normal Excel-saved .xlsx uses ZIP method 8 (deflate); Download-Format files use method 0 (store).
  function inflateRaw(input){
    let bytePos=0, bitBuf=0, bitCnt=0;
    const out=[];
    function getBit(){ if(bitCnt===0){ bitBuf=input[bytePos++]; bitCnt=8; } const b=bitBuf&1; bitBuf>>=1; bitCnt--; return b; }
    function getBits(n){ let v=0; for(let i=0;i<n;i++) v|=getBit()<<i; return v; }
    function buildTree(lengths){
      const maxBits=Math.max.apply(null,lengths);
      const blCount=new Array(maxBits+1).fill(0);
      for(const l of lengths) if(l) blCount[l]++;
      const nextCode=new Array(maxBits+1).fill(0); let code=0;
      for(let bits=1;bits<=maxBits;bits++){ code=(code+blCount[bits-1])<<1; nextCode[bits]=code; }
      const codes=new Array(lengths.length);
      for(let n=0;n<lengths.length;n++){ const len=lengths[n]; if(len){ codes[n]=nextCode[len]++; } }
      return {lengths,codes,maxBits};
    }
    function decodeSym(tree){
      let code=0, len=0;
      while(true){
        code=(code<<1)|getBit(); len++;
        if(len>tree.maxBits) throw new Error('inflate: bad code');
        for(let n=0;n<tree.lengths.length;n++){ if(tree.lengths[n]===len && tree.codes[n]===code) return n; }
      }
    }
    const LEN_BASE=[3,4,5,6,7,8,9,10,11,13,15,17,19,23,27,31,35,43,51,59,67,83,99,115,131,163,195,227,258];
    const LEN_EXTRA=[0,0,0,0,0,0,0,0,1,1,1,1,2,2,2,2,3,3,3,3,4,4,4,4,5,5,5,5,0];
    const DIST_BASE=[1,2,3,4,5,7,9,13,17,25,33,49,65,97,129,193,257,385,513,769,1025,1537,2049,3073,4097,6145,8193,12289,16385,24577];
    const DIST_EXTRA=[0,0,0,0,1,1,2,2,3,3,4,4,5,5,6,6,7,7,8,8,9,9,10,10,11,11,12,12,13,13];
    let last=0;
    do{
      last=getBit();
      const type=getBits(2);
      if(type===0){
        // stored
        if(bitCnt>0){ bitBuf=0; bitCnt=0; }
        const len=input[bytePos]|(input[bytePos+1]<<8); bytePos+=4;
        for(let i=0;i<len;i++) out.push(input[bytePos++]);
      } else {
        let litTree, distTree;
        if(type===1){
          // fixed Huffman
          const litLen=new Array(288);
          for(let i=0;i<=143;i++) litLen[i]=8;
          for(let i=144;i<=255;i++) litLen[i]=9;
          for(let i=256;i<=279;i++) litLen[i]=7;
          for(let i=280;i<=287;i++) litLen[i]=8;
          litTree=buildTree(litLen);
          distTree=buildTree(new Array(30).fill(5));
        } else if(type===2){
          const hlit=getBits(5)+257, hdist=getBits(5)+1, hclen=getBits(4)+4;
          const order=[16,17,18,0,8,7,9,6,10,5,11,4,12,3,13,2,14,1,15];
          const clLen=new Array(19).fill(0);
          for(let i=0;i<hclen;i++) clLen[order[i]]=getBits(3);
          const clTree=buildTree(clLen);
          const all=[];
          while(all.length<hlit+hdist){
            const sym=decodeSym(clTree);
            if(sym<16) all.push(sym);
            else if(sym===16){ const rep=getBits(2)+3; const prev=all[all.length-1]; for(let i=0;i<rep;i++) all.push(prev); }
            else if(sym===17){ const rep=getBits(3)+3; for(let i=0;i<rep;i++) all.push(0); }
            else { const rep=getBits(7)+11; for(let i=0;i<rep;i++) all.push(0); }
          }
          litTree=buildTree(all.slice(0,hlit));
          distTree=buildTree(all.slice(hlit));
        } else throw new Error('inflate: bad block type');
        // decode block
        while(true){
          const sym=decodeSym(litTree);
          if(sym===256) break;
          if(sym<256){ out.push(sym); }
          else {
            const li=sym-257; const length=LEN_BASE[li]+getBits(LEN_EXTRA[li]);
            const dsym=decodeSym(distTree); const dist=DIST_BASE[dsym]+getBits(DIST_EXTRA[dsym]);
            let start=out.length-dist;
            for(let i=0;i<length;i++) out.push(out[start+i]);
          }
        }
      }
    } while(!last);
    return new Uint8Array(out);
  }
  function unzip(u8){
    // find EOCD
    let i=u8.length-22;
    for(;i>=0;i--){ if(u8[i]===0x50&&u8[i+1]===0x4B&&u8[i+2]===0x05&&u8[i+3]===0x06) break; }
    if(i<0) throw new Error('not a zip');
    const cnt=u16r(u8,i+10); let cen=u32r(u8,i+16);
    const files={};
    for(let n=0;n<cnt;n++){
      if(!(u8[cen]===0x50&&u8[cen+1]===0x4B&&u8[cen+2]===0x01&&u8[cen+3]===0x02)) break;
      const method=u16r(u8,cen+10);
      const csize=u32r(u8,cen+20), nlen=u16r(u8,cen+28), elen=u16r(u8,cen+30), clen=u16r(u8,cen+32);
      const lho=u32r(u8,cen+42);
      const name=dec.decode(u8.subarray(cen+46,cen+46+nlen));
      // local header -> data
      const lnlen=u16r(u8,lho+26), lelen=u16r(u8,lho+28);
      const dstart=lho+30+lnlen+lelen;
      const raw=u8.subarray(dstart,dstart+csize);
      if(method===0) files[name]=raw;                    // STORE (Download Format files)
      else if(method===8) files[name]=inflateRaw(raw);   // DEFLATE (normal Excel-saved files)
      else throw new Error('unsupported-zip-method-'+method);
      cen+=46+nlen+elen+clen;
    }
    return files;
  }
  function parseCells(sheetXmlStr, sharedStrings){
    // returns aoa
    const rows=[];
    const rowRe=/<row[^>]*r="(\d+)"[^>]*>([\s\S]*?)<\/row>/g; let rm;
    const cellRe=/<c\s+r="([A-Z]+)(\d+)"([^>]*)>([\s\S]*?)<\/c>|<c\s+r="([A-Z]+)(\d+)"([^>]*)\/>/g;
    function colIdx(s){ let n=0; for(let k=0;k<s.length;k++) n=n*26+(s.charCodeAt(k)-64); return n-1; }
    function unesc(s){ return s.replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&apos;/g,"'").replace(/&amp;/g,'&'); }
    while((rm=rowRe.exec(sheetXmlStr))){
      const rIdx=+rm[1]-1; const content=rm[2]; const arr=[]; let cm;
      cellRe.lastIndex=0;
      while((cm=cellRe.exec(content))){
        const col=cm[1]||cm[5]; const attrs=(cm[3]||cm[7]||''); const inner=cm[4]||'';
        const ci=colIdx(col);
        let val='';
        const tMatch=/t="([^"]+)"/.exec(attrs); const type=tMatch?tMatch[1]:'';
        if(type==='inlineStr'){ const t=/<t[^>]*>([\s\S]*?)<\/t>/.exec(inner); val=t?unesc(t[1]):''; }
        else if(type==='s'){ const v=/<v>([\s\S]*?)<\/v>/.exec(inner); const idx=v?+v[1]:-1; val=(idx>=0&&sharedStrings[idx]!==undefined)?sharedStrings[idx]:''; }
        else { const v=/<v>([\s\S]*?)<\/v>/.exec(inner); val=v?unesc(v[1]):''; }
        arr[ci]=val;
      }
      rows[rIdx]=arr;
    }
    // normalize (fill holes)
    const maxLen=rows.reduce((m,r)=>Math.max(m,r?r.length:0),0);
    return rows.map(r=>{ r=r||[]; for(let i=0;i<maxLen;i++) if(r[i]===undefined) r[i]=''; return r; });
  }
  function read(u8){
    const files=unzip(u8);
    const dec2=new TextDecoder();
    // shared strings (if any)
    let shared=[];
    if(files['xl/sharedStrings.xml']){
      const ss=dec2.decode(files['xl/sharedStrings.xml']);
      const siRe=/<si>([\s\S]*?)<\/si>/g; let sm;
      while((sm=siRe.exec(ss))){ const t=[...sm[1].matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)].map(x=>x[1]).join(''); shared.push(t.replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&quot;/g,'"').replace(/&apos;/g,"'").replace(/&amp;/g,'&')); }
    }
    // first worksheet
    let sheetKey=Object.keys(files).find(k=>/^xl\/worksheets\/sheet1\.xml$/i.test(k))||Object.keys(files).find(k=>/^xl\/worksheets\/.*\.xml$/i.test(k));
    if(!sheetKey) throw new Error('no worksheet');
    const sheetStr=dec2.decode(files[sheetKey]);
    return parseCells(sheetStr, shared);
  }
  return {write, read};
})();

function raDownloadFormat(){
  const year=+($('ra-year')?.value||RA_YEARS[0]);
  const monthIdx=+($('ra-month')?.value||0);
  const month=RA_MONTHS[monthIdx];
  const days=raDaysInMonth(year,monthIdx);
  const aoa=[RA_INPUT_COLS.map(raColLabel)];   /* template header ikut label BBTUD */
  for(let d=1;d<=days;d++){ const r=new Array(RA_INPUT_COLS.length).fill(''); r[0]=raDateKey(year,monthIdx,d); aoa.push(r); }
  let bytes; try{ bytes=XLSXMini.write(aoa); }catch(err){ alert('Gagal membuat .xlsx: '+err.message); return; }
  const blob=new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
  a.download=`Actual_Input_Data_${year}_${month}_Format.xlsx`; a.click(); URL.revokeObjectURL(a.href);
  const st=$('ra-status'); if(st){ st.style.color='#334155'; st.textContent=`Downloaded template: Actual_Input_Data_${year}_${month}_Format.xlsx (${days} dates, ${RA_INPUT_COLS.length} columns).`; }
}
function raUpload(e){
  const file=e.target.files&&e.target.files[0]; if(!file){ return; }
  const year=+($('ra-year')?.value||RA_YEARS[0]);
  const monthIdx=+($('ra-month')?.value||0);
  const month=RA_MONTHS[monthIdx];
  const st=$('ra-status');
  const fail=(msg)=>{ if(st){ st.style.color='#b91c1c'; st.textContent='Upload ditolak: '+msg; } e.target.value=''; };
  if(!/\.xlsx$/i.test(file.name)) return fail('file harus format .xlsx.');
  const reader=new FileReader();
  reader.onload=(ev)=>{
    let aoa;
    try{ aoa=XLSXMini.read(new Uint8Array(ev.target.result)); }
    catch(err){
      const em=(err&&err.message)||'';
      if(em.indexOf('unsupported-zip-method')>=0) return fail('metode kompresi .xlsx tidak dikenal ('+em+'). Coba simpan ulang sebagai .xlsx standar dari Excel.');
      return fail('file tidak dapat dibaca sebagai .xlsx ('+em+').');
    }
    if(!aoa||!aoa.length) return fail('file kosong.');
    const header=(aoa[0]||[]).map(x=>(''+x).trim());
    if(header.filter(x=>x!=='').length===0) return fail('file kosong.');
    if(header.length<RA_INPUT_COLS.length) return fail('header tidak sesuai format Input Data (jumlah kolom kurang).');
    for(let i=0;i<RA_INPUT_COLS.length;i++){ const h=(header[i]||'').trim(); if(h!==RA_INPUT_COLS[i]&&h!==raColLabel(RA_INPUT_COLS[i])) return fail(`header tidak sesuai format Input Data (kolom ${i+1} harus "${raColLabel(RA_INPUT_COLS[i])}").`); }
    const body=aoa.slice(1).filter(r=>r.some(c=>(''+c).trim()!==''));
    if(!body.length) return fail('tidak ada baris data (file kosong).');
    // NOTE: partial months are allowed — we do NOT require every day of the month to be present.
    // Validation is per available row: valid DATE within the active month/year, no duplicates.
    const norm=(s)=>{ s=(''+s).trim();
      let m=/^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s); if(m) return `${m[1]}-${String(+m[2]).padStart(2,'0')}-${String(+m[3]).padStart(2,'0')}`;
      m=/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s); if(m) return `${m[3]}-${String(+m[1]).padStart(2,'0')}-${String(+m[2]).padStart(2,'0')}`;
      const n=Number(s); if(!isNaN(n)&&n>1){ const dt=new Date(Date.UTC(1899,11,30+Math.floor(n))); return dt.toISOString().slice(0,10); }
      return s; };
    const parsed={}; const seen={}; let count=0;
    for(let i=0;i<body.length;i++){
      const r=body[i]; const dk=norm(r[0]);
      if(!/^\d{4}-\d{2}-\d{2}$/.test(dk)) return fail(`DATE tidak valid pada baris ${i+2}.`);
      if(dk.slice(0,7)!==`${year}-${String(monthIdx+1).padStart(2,'0')}`) return fail(`tanggal ${dk} di luar bulan aktif ${month} ${year}.`);
      if(seen[dk]) return fail(`tanggal duplikat ${dk}.`); seen[dk]=1;
      const obj={};
      for(let c=1;c<RA_INPUT_COLS.length;c++){
        let val=(r[c]!==undefined?(''+r[c]).trim():'');
        if(val===''){ continue; }
        const fmt=raFmt(RA_INPUT_COLS[c],val); if(fmt!=='') val=fmt;   // store already rounded (Round 0/2)
        obj[RA_INPUT_COLS[c]]=val;
      }
      parsed[dk]=obj; count++;
    }
    // INSERT/UPDATE by date (not a full-month replace): dates present in the file are added or overwritten;
    // dates NOT in the file are left untouched. This lets a partial upload (e.g. 1–29) update just those rows.
    const s=raMonthStore(year,month);
    let inserted=0, updated=0;
    Object.keys(parsed).forEach(dk=>{ if(s[dk]!==undefined) updated++; else inserted++; s[dk]=parsed[dk]; });
    if(INPUT.data3&&INPUT.data3.modeling) INPUT.data3.modeling.report_actual=REPORT_ACTUAL;
    RA_DIRTY=true;   // there is unsaved uploaded/edited data
    if(st){ st.style.color='#15803d'; st.textContent=`Upload berhasil: ${month} ${year} — ${count} baris (${inserted} baru, ${updated} diperbarui). Klik SAVE DATA untuk menyimpan permanen. JBBK / MM2100 / JBBK-MM2100 otomatis ikut update.`; }
    e.target.value='';
    raRender();   // refresh; derived views recompute from the new Input Data when their area is selected
    if(typeof ovTrendRenderAll==='function') ovTrendRenderAll();   // ADDENDUM 2.4: Trend ikut segar
  };
  reader.readAsArrayBuffer(file);
}
/* SAVE DATA — persist the uploaded/edited Report Actual data so it is not just shown temporarily.
   report_actual lives under INPUT.data3.modeling; saving the input model (run.php?mode=save writes
   input_data.json) persists it. We assemble the full input (which carries report_actual) and POST it. */
let RA_DIRTY=false;
async function raSaveData(){
  const st=$('ra-status'), btn=$('ra-save');
  if(INPUT.data3&&INPUT.data3.modeling) INPUT.data3.modeling.report_actual=REPORT_ACTUAL;   // ensure latest in model
  let payload; try{ payload=(typeof assembleInput==='function')?assembleInput():JSON.parse(JSON.stringify(INPUT)); }
  catch(e){ if(st){ st.style.color='#b91c1c'; st.textContent='Gagal menyiapkan data untuk disimpan: '+e.message; } return; }
  // make sure report_actual is present in the outgoing payload even if assembleInput rebuilt modeling
  if(payload.data3&&payload.data3.modeling) payload.data3.modeling.report_actual=REPORT_ACTUAL;
  const old=btn?btn.innerHTML:''; if(btn){ btn.disabled=true; btn.innerHTML='<span class="spin"></span> Saving…'; }
  try{
    const res=await fetch('run.php?mode=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const data=await res.json();
    if(data.result==='ok'){
      INPUT=payload; if(typeof INPUT0!=='undefined') INPUT0=JSON.parse(JSON.stringify(payload));
      RA_DIRTY=false;
      if(st){ st.style.color='#15803d'; st.textContent='SAVE DATA berhasil — data report tersimpan permanen (input_data.json).'; }
      if(typeof toast==='function') toast('Report Actual data saved');
    } else {
      if(st){ st.style.color='#b91c1c'; st.textContent='SAVE DATA gagal: '+(data.message||'unknown'); }
    }
  }catch(err){ if(st){ st.style.color='#b91c1c'; st.textContent='SAVE DATA gagal: '+err.message; } }
  finally{ if(btn){ btn.disabled=false; btn.innerHTML=old; } }
}
/* ============================================================================
   REPORT → PLANNING (Revisi Report Planning + Save integration)
   Each saved daily-plan simulation is stored in REPORT_PLANNING keyed by
   [year][month][planDate] -> array of { name_plan, plan_date, saved_at, summary{}, snapshot{} }.
   Unique key per row = plan_date + name_plan: same name on the same date updates that row;
   a different name (e.g. a Remark) inserts a new row on the same date.
   Columns 4–25 map optimizer OUTPUT.info summary keys. The Simulation Result icon opens the full
   saved snapshot (input + result + info) in a new browser tab. Persists inside INPUT model
   (report_planning) so run.php?mode=save writes it to input_data.json like report_actual. ========= */
const RP_COLS=["Date","Name Plan","Simulation Result","IE PREDICTION","JBBK MM PROD","BABELAN PROD","BABELAN CF","DAILY PLN EXPORT","TOTAL FUEL","GAS FUEL TOTAL","DISTILLATE FUEL TOTAL","PGN TOTAL","PGN PIPE TOTAL","LNG TOTAL","PEP JBBK TOTAL","PEP KP72 TOTAL","AKASIA JBBK TOTAL","AKASIA KP72 TOTAL","BaGS JBBK TOTAL","BaGS KP72 TOTAL","TOTAL COAL","% RE BABELAN","GHG EMISSION","HEATRATE","TOTAL COST","COST PRODUCTION"];
// Planning column -> OUTPUT.info key (columns 4..25). Order matches RP_COLS[3..24].
const RP_INFO_MAP=[
  ["IE PREDICTION","IE Prediction (MWh)"],["JBBK MM PROD","JBBK MM Prod (MWh)"],["BABELAN PROD","Babelan Prod (MWh)"],
  ["BABELAN CF","Babelan CF (%)"],["DAILY PLN EXPORT","Daily PLN Exp (MWh)"],["TOTAL FUEL","Total Fuel (BBTUD)"],
  ["GAS FUEL TOTAL","Gas Fuel Total (BBTUD)"],["DISTILLATE FUEL TOTAL","Distillate Fuel Total (l)"],["PGN TOTAL","PGN Total (BBTUD)"],
  ["PGN PIPE TOTAL","PGN Pipe Total (BBTUD)"],["LNG TOTAL","LNG Total (BBTUD)"],["PEP JBBK TOTAL","PEP JBBK Total (BBTUD)"],
  ["PEP KP72 TOTAL","PEP KP72 Total (BBTUD)"],["AKASIA JBBK TOTAL","Akasia JBBK Total (BBTUD)"],["AKASIA KP72 TOTAL","Akasia KP72 Total (BBTUD)"],
  ["BaGS JBBK TOTAL","BaGS JBBK Total (BBTUD)"],["BaGS KP72 TOTAL","BaGS KP72 Total (BBTUD)"],["TOTAL COAL","Total Coal (ton)"],["GHG EMISSION","GHG Emission (ton CO2e/MWh)"],
  ["% RE BABELAN","% RE Babelan (%)"],["HEATRATE","Heatrate"],["TOTAL COST","Total Cost (USD)"],["COST PRODUCTION","Cost Production (USD/MWh)"]
];
let REPORT_PLANNING=(INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.report_planning)||{};
function rpMonthStore(year,month){
  REPORT_PLANNING[year]=REPORT_PLANNING[year]||{};
  REPORT_PLANNING[year][month]=REPORT_PLANNING[year][month]||{};
  return REPORT_PLANNING[year][month];
}
function rpBuildSummary(info){
  // Extract only the mapped summary values from a simulation's OUTPUT.info.
  const s={}; RP_INFO_MAP.forEach(([col,key])=>{ s[col]=(info&&info[key]!==undefined&&info[key]!==null)?info[key]:''; });
  return s;
}
function rpInit(){
  const ys=$('rp-year'); if(ys&&!ys.options.length){ ys.innerHTML=RA_YEARS.map(y=>`<option value="${y}">${y}</option>`).join(''); }
  const ms=$('rp-month'); if(ms&&!ms.options.length){ ms.innerHTML=RA_MONTHS.map((m,i)=>`<option value="${i}">${m}</option>`).join(''); ms.value=String(new Date().getMonth()); }
  ['rp-year','rp-month'].forEach(id=>{ const el=$(id); if(el&&!el._rpBound){ el._rpBound=1; el.addEventListener('change',rpRender); } });
  rpRender();
}
function rpFmtVal(col,v){
  if(v===''||v===null||v===undefined) return '';
  const n=Number(v); if(!isFinite(n)) return ''+v;
  return n.toFixed(2);
}
function rpRender(){
  const year=+($('rp-year')?.value||RA_YEARS[0]);
  const monthIdx=+($('rp-month')?.value||0);
  const month=RA_MONTHS[monthIdx];
  const days=raDaysInMonth(year,monthIdx);
  const store=rpMonthStore(year,month);
  let h='<thead><tr>'+RP_COLS.map((c,i)=>`<th class="${i===0?'ra-datecol':''}">${c}</th>`).join('')+'</tr></thead><tbody>';
  let rowCount=0;
  for(let d=1;d<=days;d++){
    const key=raDateKey(year,monthIdx,d);       // internal storage key stays YYYY-MM-DD
    const disp=fmtPlanDate(key);                 // UI display is dd-MMM-yy (e.g. 02-Jul-26)
    const plans=store[key]||[];
    if(!plans.length){
      // still show the date with empty cells so the month grid is complete
      h+='<tr><td class="ra-datecol">'+disp+'</td>'+RP_COLS.slice(1).map(()=>'<td></td>').join('')+'</tr>';
      continue;
    }
    // MERGE DATE: one Date cell spanning all plans of this date (rowspan). Only the first row of the
    // group emits the Date cell; the rest omit it so the Date visually merges (Excel-style, centered).
    plans.forEach((p,pi)=>{
      rowCount++;
      /* PROMPT MONITORING §7: row Monitoring Daily Plan = kuning terang. Kriteria: metadata
         plan_type='monitoring' (reliable) ATAU Name Plan diawali 'Monitoring Daily Plan'. */
      const isMon=(p.plan_type==='monitoring')||/^Monitoring Daily Plan/i.test(p.name_plan||'');
      h+=`<tr${isMon?' class="rp-mon" title="Monitoring Daily Plan"':''}>`;
      if(pi===0){ h+=`<td class="ra-datecol" rowspan="${plans.length}">${disp}</td>`; }
      h+=`<td>${(p.name_plan||'').replace(/</g,'&lt;')}</td>`;
      // Simulation Result icon -> opens the clone UI (main UI populated from this snapshot) in a new tab.
      // URL identity = Plan Date + Name Plan (base64), so same date + different Name Plan opens different data,
      // and a re-save under the same Name Plan opens the latest snapshot.
      const nameB64=encodeURIComponent(btoa(unescape(encodeURIComponent(p.name_plan||''))));
      const url=`?sim_view=${encodeURIComponent(year)}/${encodeURIComponent(month)}/${encodeURIComponent(key)}/${nameB64}`;
      h+=`<td><a class="rp-simbtn" href="${url}" target="_blank" rel="noopener" title="Open this saved simulation in the full UI (new tab)">🔍</a></td>`;
      const sm=p.summary||{};
      for(let ci=3;ci<RP_COLS.length;ci++){ h+=`<td>${rpFmtVal(RP_COLS[ci], sm[RP_COLS[ci]])}</td>`; }
      h+='</tr>';
    });
  }
  h+='</tbody>';
  const t=$('rp-table'); if(t) t.innerHTML=h;
  const st=$('rp-status');
  if(st) st.textContent=`${month} ${year} · ${rowCount} saved plan${rowCount===1?'':'s'}. Save a simulation from Daily Plan → Result → Simulation Data to add rows here.`;
}
/* Called by the Daily Plan Save button: persist the current simulation into REPORT_PLANNING keyed by
   Plan Date + Name Plan + plan_type (update when identical, insert when any part differs).
   PROMPT PLAN SAVE §4: opts.alsoMonitoring = true -> buat DUA record terpisah dari state Plan yang
   SAMA pada saat Save: record Plan + kembaran Monitoring Daily Plan. */
function rpSaveCurrentPlan(opts){
  opts=opts||{};
  if(!(INPUT.data3&&INPUT.data3.modeling)) return;
  const m=INPUT.data3.modeling;
  const planDate=(m.plan_date&&/^\d{4}-\d{2}-\d{2}$/.test(m.plan_date))?m.plan_date:new Date().toISOString().slice(0,10);
  const namePlan=(typeof computeNamePlan==='function')?computeNamePlan(planDate, m.plan_remark||''):(m.name_plan||('Daily Plan '+planDate));
  const [y,mo]=planDate.split('-'); const year=+y, monthIdx=+mo-1, month=RA_MONTHS[monthIdx];
  const info=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info:{};
  const newId=()=>Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);
  const rec={
    name_plan:namePlan, plan_date:planDate, plan_remark:m.plan_remark||'',
    plan_type:(typeof dpMon==='function'&&dpMon())?'monitoring':'plan',   // PROMPT MONITORING §7: metadata reliable utk warna row
    snapshot_id:newId(),   // PROMPT ISOLATION §3.2
    saved_at:new Date().toISOString().slice(0,19).replace('T',' '),
    summary:rpBuildSummary(info),
    snapshot:{
      /* PROMPT SAVE-FI §A — ROOT CAUSE REKURSI: INPUT memuat modeling.report_planning (di-assign di akhir
       * save sebelumnya), sehingga menyalin INPUT utuh MENYARANGKAN seluruh record lama BESERTA snapshot
       * mereka ke dalam snapshot record baru. Terukur forensik pada input_data.json user: nesting depth 4,
       * report_planning = 99.6% isi file, record 58 KB membengkak jadi 1.84 MB, file 40 MB -> POST melebihi
       * post_max_size -> body di-drop -> spinner tak selesai / "1 input tidak tersave" / data hilang.
       * Fix: snapshot TIDAK PERNAH membawa report_planning (dan plan bookkeeping turunannya). */
      input:(function(){ const c=JSON.parse(JSON.stringify(INPUT));
        if(c&&c.data3&&c.data3.modeling) delete c.data3.modeling.report_planning; return c; })(),
      output:(typeof OUTPUT!=='undefined'&&OUTPUT)?JSON.parse(JSON.stringify({info:OUTPUT.info,data:OUTPUT.data,result:OUTPUT.result})):null
    }
  };
  const store=rpMonthStore(year,month);
  const arr=store[planDate]=store[planDate]||[];
  /* PROMPT ISOLATION §7 / PLAN SAVE §6: unique key = plan_date + name_plan + plan_type — save Monitoring
     TIDAK PERNAH me-replace row Plan (dan sebaliknya) walau tanggal/nama kebetulan sama. */
  const putRec=(r)=>{
    const i=arr.findIndex(x=>x.name_plan===r.name_plan&&((x.plan_type||'plan')===r.plan_type));
    if(i>=0){ arr[i]=r; return true; } arr.push(r); return false;
  };
  const updated=putRec(rec);

  /* ---- §4.2 kembaran Monitoring (hanya dari tab Plan, hanya bila user memilihnya) ---------------- */
  let monInfo=null;
  if(opts.alsoMonitoring && rec.plan_type==='plan'){
    const r=(m.plan_remark||'').trim();
    const monName='Monitoring Daily Plan '+fmtPlanDate(planDate)+(r?(' '+r):'');
    /* §4.4 DEEP COPY, bukan shared reference: seluruh input/output/summary di-serialize ulang dari
       record Plan sehingga perubahan Plan berikutnya TIDAK menyentuh snapshot Monitoring ini. */
    const monInput=JSON.parse(JSON.stringify(rec.snapshot.input));
    const mm=monInput.data3&&monInput.data3.modeling;
    if(mm){
      /* §5: baseline Monitoring = salinan Plan. Cabang Monitoring diaktifkan, TAPI TIME PASSED TIDAK
         dikunci sembarangan: cutoff/locked_rows existing dipertahankan; bila belum ada cutoff aktual
         maka cutoff=0 dan locked_rows kosong -> TIDAK ada row yang otomatis 'Y' (§5.3/§5.4). */
      const prev=mm.monitoring_daily_plan||{};
      mm.monitoring_daily_plan={
        enabled:true,
        time_passed_cutoff_row:+(prev.time_passed_cutoff_row||0),
        locked_rows:JSON.parse(JSON.stringify(prev.locked_rows||{}))
      };
      mm.plan_type='monitoring';
      mm.name_plan=monName;
    }
    const monRec={
      name_plan:monName, plan_date:planDate, plan_remark:m.plan_remark||'',
      plan_type:'monitoring',
      source_plan_name:rec.name_plan,           // §4.2 metadata jejak sumber
      source_plan_type:'plan',
      source_snapshot_id:rec.snapshot_id,
      snapshot_id:newId(),                       // §4.4 snapshot ID sendiri
      saved_at:new Date().toISOString().slice(0,19).replace('T',' '),   // §4.4 timestamp sendiri
      summary:JSON.parse(JSON.stringify(rec.summary||{})),
      snapshot:{ input:monInput, output:JSON.parse(JSON.stringify(rec.snapshot.output)) }
    };
    const monUpdated=putRec(monRec);
    monInfo={namePlan:monName, updated:monUpdated};
  }

  m.report_planning=REPORT_PLANNING;
  if(typeof ovTrendRenderAll==='function') ovTrendRenderAll();   // ADDENDUM FINAL 2.4: Trend ikut segar setelah save planning
  return {namePlan,planDate,updated,monitoring:monInfo};
}
/* Report sub-tabs (Actual | Planning | Planning Vs Actual) */
function activateRSub(sub){
  document.querySelectorAll('#panel-report .subtab').forEach(t=>t.classList.toggle('active',t.dataset.rsub===sub));
  document.querySelectorAll('#panel-report .subpanel').forEach(p=>p.classList.toggle('active',p.id==='rp-'+sub));
  if(sub==='actual') raInit();
  if(sub==='planning') rpInit();
}
document.querySelectorAll('#panel-report .subtab').forEach(b=>b.addEventListener('click',()=>activateRSub(b.dataset.rsub)));

/* ============================================================================
   OVERVIEW → TREND (Revisi UI Overview Trend)
   3 identical trend cards. Each plots Actual (Report → Actual → Input Data) and/or
   Planning (Report → Planning summary) parameters over a date range, as an SVG
   line-with-marker chart with a bottom legend, optional data labels (With Number),
   and merged or per-series (un-merged) Y-axes. UI-only: reads existing REPORT_ACTUAL
   and REPORT_PLANNING client state. ========================================== */
const OV_ACTUAL_PARAMS=RA_INPUT_COLS.filter(c=>c!=='DATE');       // all Report Actual Input Data columns (minus DATE)
const OV_PLANNING_PARAMS=RP_INFO_MAP.map(x=>x[0]);               // all Report Planning summary parameters
const OV_SERIES_COLORS=['#2563eb','#dc2626','#059669','#d97706','#7c3aed','#0891b2','#db2777','#65a30d','#ea580c','#4f46e5'];
const TREND_STATE={};   // { trendId: { interval, date1, date2, withNumber, ymerge, rows:[{srcA,paramA,srcP,paramP}] } }
function ovTrendDefault(){
  const today=new Date().toISOString().slice(0,10);
  const past=new Date(Date.now()-13*864e5).toISOString().slice(0,10);
  return {interval:'more',date1:past,date2:today,withNumber:false,ymerge:false,ymin:'',ymax:'',
    rows:[{srcA:'yes',paramA:'',srcP:'no',paramP:''}]};
}
function ovTrendInit(){
  const host=$('trend-cards'); if(!host) return;
  if(!host.dataset.built){
    host.innerHTML=[1,2,3].map(i=>`<div class="trend-card" id="trend-card-${i}">
      <div class="trend-h">Trend ${i}</div>
      <div class="trend-ctl" id="trend-ctl-${i}"></div>
      <div class="trend-rows" id="trend-rows-${i}"></div>
      <div class="trend-actions" id="trend-actions-${i}"></div>
      <div class="trend-chart" id="trend-chart-${i}"></div>
      <div class="trend-legend" id="trend-legend-${i}"></div>
    </div>`).join('');
    host.dataset.built='1';
  }
  [1,2,3].forEach(i=>{ if(!TREND_STATE[i]) TREND_STATE[i]=ovTrendDefault(); ovRenderTrend(i); });
}
function ovUsedParams(i,which,exceptRow){
  // parameters already chosen in this trend for a given source (actual|planning), to prevent duplicates
  const st=TREND_STATE[i], used=new Set();
  st.rows.forEach((r,ri)=>{ if(ri===exceptRow) return;
    if(which==='actual' && r.srcA==='yes' && r.paramA) used.add(r.paramA);
    if(which==='planning' && r.srcP==='yes' && r.paramP) used.add(r.paramP);
  });
  return used;
}
function ovParamOptions(all,used,current){
  // build <option> list excluding already-used params (but keep the current selection)
  let h='<option value="">— select —</option>';
  all.forEach(p=>{ if(used.has(p)&&p!==current) return; h+=`<option value="${p.replace(/"/g,'&quot;')}"${p===current?' selected':''}>${p}</option>`; });
  return h;
}
function ovRenderTrend(i){
  const st=TREND_STATE[i];
  // control bar: interval + date1 + date2 + withNumber + Y-axis mode
  const ctl=$('trend-ctl-'+i);
  if(ctl) ctl.innerHTML=`
    <label class="fld inline">Interval Data
      <select data-t="${i}" data-k="interval">
        <option value="more"${st.interval==='more'?' selected':''}>More Than</option>
        <option value="between"${st.interval==='between'?' selected':''}>Between</option>
      </select></label>
    <label class="fld inline">Date 1<input type="date" data-t="${i}" data-k="date1" value="${st.date1||''}"></label>
    <label class="fld inline">Date 2<input type="date" data-t="${i}" data-k="date2" value="${st.date2||''}" ${st.interval==='more'?'disabled':''}></label>
    <span class="spacer"></span>
    <label class="chk"><input type="checkbox" data-t="${i}" data-k="withNumber" ${st.withNumber?'checked':''}> With Number</label>
    <span class="seg" role="group" aria-label="Y-axis">
      <button type="button" class="seg-opt ${st.ymerge?'active':''}" data-t="${i}" data-yaxis="merge">Merge Y-Axis</button>
      <button type="button" class="seg-opt ${st.ymerge?'':'active'}" data-t="${i}" data-yaxis="unmerge">Un-Merge Y-Axis</button>
    </span>
    ${st.ymerge?`<label class="fld inline">Y-Axis Min<input type="number" step="any" data-t="${i}" data-k="ymin" value="${st.ymin!==''&&st.ymin!==undefined?st.ymin:''}" placeholder="auto" style="width:100px"></label>
    <label class="fld inline">Y-Axis Max<input type="number" step="any" data-t="${i}" data-k="ymax" value="${st.ymax!==''&&st.ymax!==undefined?st.ymax:''}" placeholder="auto" style="width:100px"></label>`:''}`;
  // parameter rows
  const rowsBox=$('trend-rows-'+i);
  if(rowsBox){
    rowsBox.innerHTML=st.rows.map((r,ri)=>{
      const aUsed=ovUsedParams(i,'actual',ri), pUsed=ovUsedParams(i,'planning',ri);
      return `<div class="trend-prow">
        <label class="fld inline">Source Actual
          <select data-t="${i}" data-ri="${ri}" data-k="srcA">
            <option value="yes"${r.srcA==='yes'?' selected':''}>YES</option>
            <option value="no"${r.srcA==='no'?' selected':''}>NO</option></select></label>
        <label class="fld inline">Parameter Actual
          <select data-t="${i}" data-ri="${ri}" data-k="paramA" ${r.srcA==='yes'?'':'disabled'} style="min-width:220px">${ovParamOptions(OV_ACTUAL_PARAMS,aUsed,r.paramA)}</select></label>
        <label class="fld inline">Source Planning
          <select data-t="${i}" data-ri="${ri}" data-k="srcP">
            <option value="yes"${r.srcP==='yes'?' selected':''}>YES</option>
            <option value="no"${r.srcP==='no'?' selected':''}>NO</option></select></label>
        <label class="fld inline">Parameter Planning
          <select data-t="${i}" data-ri="${ri}" data-k="paramP" ${r.srcP==='yes'?'':'disabled'} style="min-width:200px">${ovParamOptions(OV_PLANNING_PARAMS,pUsed,r.paramP)}</select></label>
        ${ri===0?`<button type="button" class="btn ghost trend-add" data-t="${i}" ${st.rows.length>=5?'disabled title="Maximum 5 parameter rows"':'title="Add parameter row"'}>+</button>`
                :`<button type="button" class="btn ghost trend-del" data-t="${i}" data-ri="${ri}" title="Remove this row">×</button>`}
      </div>`;
    }).join('')+(st.rows.length>=5?'<div class="trend-maxhint">Maximum 5 parameter rows.</div>':'');
  }
  ovBindTrend(i);
  ovDrawChart(i);
}
function ovBindTrend(i){
  const card=$('trend-card-'+i); if(!card) return;
  card.querySelectorAll('[data-k]').forEach(el=>{
    const ev=(el.tagName==='SELECT'||el.type==='checkbox'||el.type==='date')?'change':'input';
    el.addEventListener(ev,e=>{
      const st=TREND_STATE[i], k=e.target.dataset.k, ri=e.target.dataset.ri;
      let v=(e.target.type==='checkbox')?e.target.checked:e.target.value;
      if(ri!==undefined){ st.rows[+ri][k]=v; if(k==='srcA'&&v==='no')st.rows[+ri].paramA=''; if(k==='srcP'&&v==='no')st.rows[+ri].paramP=''; }
      else { st[k]=v; }
      /* ADDENDUM 1.2 (auto-render robust): nilai date & Y-Min/Y-Max hanya perlu REDRAW chart —
       * rebuild penuh membuat input kehilangan fokus saat user masih mengetik. Kontrol struktural
       * (interval, source YES/NO, parameter, checkbox) tetap full re-render utk enable/disable &
       * dedupe dropdown. Kedua jalur sama-sama auto-render tanpa tombol tambahan. */
      if(k==='date1'||k==='date2'||k==='ymin'||k==='ymax') ovDrawChart(i);
      else ovRenderTrend(i);   // re-render (updates enable/disable, dedupe dropdowns, chart)
    });
  });
  card.querySelectorAll('[data-yaxis]').forEach(b=>b.addEventListener('click',()=>{
    TREND_STATE[i].ymerge=(b.dataset.yaxis==='merge'); ovRenderTrend(i);
  }));
  const add=card.querySelector('.trend-add');
  if(add) add.addEventListener('click',()=>{ if(TREND_STATE[i].rows.length>=5){ if(typeof toast==='function')toast('Maximum 5 parameter rows'); return; } TREND_STATE[i].rows.push({srcA:'yes',paramA:'',srcP:'no',paramP:''}); ovRenderTrend(i); });
  card.querySelectorAll('.trend-del').forEach(b=>b.addEventListener('click',()=>{
    TREND_STATE[i].rows.splice(+b.dataset.ri,1); ovRenderTrend(i);
  }));
}
// ---- data access ----
function ovParseDate(s){
  // accepts YYYY-MM-DD, DD-MMM-YY, DD-MMM-YYYY -> returns YYYY-MM-DD or null
  s=(''+s).trim(); if(!s) return null;
  let m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(s); if(m) return s;
  m=/^(\d{1,2})-([A-Za-z]{3})-(\d{2,4})$/.exec(s);
  if(m){ const mi=MONTHS_ABBR.findIndex(x=>x.toLowerCase()===m[2].toLowerCase()); if(mi<0) return null;
    let y=m[3]; if(y.length===2) y='20'+y; return `${y}-${String(mi+1).padStart(2,'0')}-${String(+m[1]).padStart(2,'0')}`; }
  return null;
}
function ovDateList(st){
  const d1=ovParseDate(st.date1); if(!d1) return {error:'Date 1 kosong / tidak valid'};
  let d2;
  if(st.interval==='more'){ d2=new Date().toISOString().slice(0,10); }
  else { d2=ovParseDate(st.date2); if(!d2) return {error:'Date 2 kosong / tidak valid'};
    if(d2<d1) return {error:'Date 2 lebih kecil dari Date 1'}; }
  const out=[]; let cur=new Date(d1+'T00:00:00'), end=new Date(d2+'T00:00:00');
  let guard=0; while(cur<=end && guard++<3660){ out.push(cur.toISOString().slice(0,10)); cur=new Date(cur.getTime()+864e5); }
  return {dates:out};
}
/* ADDENDUM FINAL 2.4 (kandidat root cause #5/#6/#8): angka dari Excel bisa membawa pemisah ribuan /
 * desimal-koma ("1,234.56" / "1.234,56") -> Number() = NaN -> seri "kosong" padahal data ada. Parser
 * toleran di bawah menormalkan keduanya. Lookup tanggal juga toleran: bila key YYYY-MM-DD tidak
 * ditemukan, seluruh key bulan tsb dinormalisasi via ovParseDate (menangani store lama ber-key
 * dd-MMM-yy). Nilai gagal-parse mengembalikan null (bukan crash / bukan chart diam-diam kosong). */
function ovNum(v){
  if(v===undefined||v===''||v===null) return null;
  if(typeof v==='number') return isFinite(v)?v:null;
  let s=(''+v).trim(); if(!s) return null;
  if(/^-?\d{1,3}(\.\d{3})+(,\d+)?$/.test(s)) s=s.replace(/\./g,'').replace(',','.');   // 1.234,56 (EU)
  else s=s.replace(/,/g,'');                                                             // 1,234.56 (US)
  const n=Number(s); return isFinite(n)?n:null;
}
function ovMonthRec(store,dateKey){
  const [y,mo]=dateKey.split('-'); const month=RA_MONTHS[+mo-1];
  const mstore=(store[y]&&store[y][month])||null; if(!mstore) return null;
  if(mstore[dateKey]!==undefined) return mstore[dateKey];
  for(const k of Object.keys(mstore)){ if(ovParseDate(k)===dateKey) return mstore[k]; }   // key format lain
  return null;
}
function ovActualValue(dateKey,param){
  // Report → Actual → Input Data master store: REPORT_ACTUAL[year][MonthName][YYYY-MM-DD][param]
  const rec=ovMonthRec(REPORT_ACTUAL,dateKey);
  return rec?ovNum(rec[param]):null;
}
function ovPlanningValue(dateKey,param){
  // Report → Planning: REPORT_PLANNING[year][MonthName][YYYY-MM-DD] = [ {summary:{param:val}} ] (take latest)
  const arr=ovMonthRec(REPORT_PLANNING,dateKey);
  if(!arr||!arr.length) return null; const rec=arr[arr.length-1];
  return ovNum((rec.summary||{})[param]);
}
function ovBuildSeries(i){
  const st=TREND_STATE[i];
  const series=[];
  st.rows.forEach(r=>{
    if(r.srcA==='yes'&&r.paramA) series.push({source:'Actual',param:r.paramA,label:'Actual - '+r.paramA,get:(dk)=>ovActualValue(dk,r.paramA)});
    if(r.srcP==='yes'&&r.paramP) series.push({source:'Planning',param:r.paramP,label:'Planning - '+r.paramP,get:(dk)=>ovPlanningValue(dk,r.paramP)});
  });
  return series;
}
// ---- SVG line-with-marker chart ----
/* ADDENDUM FINAL 2.4: fungsi render AUTHORITATIVE. Semua kontrol memanggil ovTrendRender(id);
 * ovTrendRenderAll() dipanggil saat data Report berubah (upload/save) agar chart ikut segar. */
function ovTrendRender(i){ ovRenderTrend(i); }
function ovTrendRenderAll(){ [1,2,3].forEach(i=>{ if(TREND_STATE[i]&&$('trend-card-'+i)) ovRenderTrend(i); }); }
function ovDrawChart(i){
  try{ ovDrawChartInner(i); }
  catch(err){
    const c=$('trend-chart-'+i);
    if(c) c.innerHTML='<div class="trend-empty warn">Trend render error: '+((err&&err.message)||err)+'</div>';
    if(typeof console!=='undefined') console.error('Trend render error',err);
  }
}
function ovDrawChartInner(i){
  const st=TREND_STATE[i], chart=$('trend-chart-'+i), legend=$('trend-legend-'+i);
  if(!chart) return;
  const series=ovBuildSeries(i);
  if(!series.length){ chart.innerHTML='<div class="trend-empty">Select source and parameter to render trend.</div>'; if(legend)legend.innerHTML=''; return; }   // ADDENDUM 1.6 empty state
  const dl=ovDateList(st);
  if(dl.error){ chart.innerHTML='<div class="trend-empty warn">'+dl.error+'</div>'; if(legend)legend.innerHTML=''; return; }
  const dates=dl.dates;
  // gather values per series
  let anyActual=false, anyPlanning=false, anyData=false;
  series.forEach(s=>{ s.values=dates.map(d=>s.get(d)); if(s.source==='Actual')anyActual=true; else anyPlanning=true;
    if(s.values.some(v=>v!==null)) anyData=true; });
  if(!anyData){
    const msg=anyActual&&!anyPlanning?'No data found for selected range/parameter (Actual).'
      :(!anyActual&&anyPlanning?'No data found for selected range/parameter (Planning).':'No data found for selected range/parameter.');
    chart.innerHTML='<div class="trend-empty">'+msg+'</div>'; if(legend)legend.innerHTML=''; return;
  }
  // layout
  const W=Math.max(680, 60+dates.length*54), H=340, padL=56, padR=20, padT=18, padB=54;
  const plotW=W-padL-padR, plotH=H-padT-padB;
  const xAt=(idx)=>padL+(dates.length<=1?plotW/2:(idx/(dates.length-1))*plotW);
  // Y ranges: merged (one global) or per-series
  let globalMin=Infinity, globalMax=-Infinity;
  series.forEach(s=>s.values.forEach(v=>{ if(v!==null){ if(v<globalMin)globalMin=v; if(v>globalMax)globalMax=v; }}));
  series.forEach(s=>{ let mn=Infinity,mx=-Infinity; s.values.forEach(v=>{ if(v!==null){ if(v<mn)mn=v; if(v>mx)mx=v; }});
    if(mn===Infinity){mn=0;mx=1;} if(mn===mx){mn-=1;mx+=1;} s.min=mn; s.max=mx; });
  if(globalMin===Infinity){globalMin=0;globalMax=1;} if(globalMin===globalMax){globalMin-=1;globalMax+=1;}
  // Merge Y-Axis manual range (Revisi): when Merge is active and both Min & Max are provided, use them.
  // If Min >= Max -> warning, do not render. If either is blank -> auto (global min/max).
  if(st.ymerge){
    const hasMin=(st.ymin!==''&&st.ymin!==undefined&&st.ymin!==null&&isFinite(+st.ymin));
    const hasMax=(st.ymax!==''&&st.ymax!==undefined&&st.ymax!==null&&isFinite(+st.ymax));
    if(hasMin&&hasMax){
      if(+st.ymin>=+st.ymax){ chart.innerHTML='<div class="trend-empty warn">Y-Axis Min harus lebih kecil dari Y-Axis Max.</div>'; if(legend)legend.innerHTML=''; return; }
      globalMin=+st.ymin; globalMax=+st.ymax;
    }
  }
  const yAt=(v,s)=>{ const mn=st.ymerge?globalMin:s.min, mx=st.ymerge?globalMax:s.max;
    return padT+plotH-((v-mn)/(mx-mn))*plotH; };
  let svg=`<svg viewBox="0 0 ${W} ${H}" width="100%" preserveAspectRatio="xMinYMin meet" font-family="system-ui,Segoe UI,Arial" font-size="11">`;
  // gridlines + Y labels (merged axis only; un-merged would overlap, so show a neutral grid)
  const gridN=4;
  for(let g=0;g<=gridN;g++){ const yy=padT+plotH-(g/gridN)*plotH;
    svg+=`<line x1="${padL}" y1="${yy}" x2="${W-padR}" y2="${yy}" stroke="#e2e8f0"/>`;
    if(st.ymerge){ const val=globalMin+(g/gridN)*(globalMax-globalMin); svg+=`<text x="${padL-6}" y="${yy+3}" text-anchor="end" fill="#64748b">${(+val.toFixed(2))}</text>`; }
  }
  if(!st.ymerge) svg+=`<text x="${padL-6}" y="${padT+plotH/2}" text-anchor="end" fill="#94a3b8" transform="rotate(-90 ${padL-40} ${padT+plotH/2})">per-series axes</text>`;
  // X labels (dd-MMM-yy) — thin out if many
  const step=Math.ceil(dates.length/12);
  dates.forEach((d,idx)=>{ if(idx%step!==0 && idx!==dates.length-1) return;
    svg+=`<text x="${xAt(idx)}" y="${H-padB+16}" text-anchor="middle" fill="#64748b">${fmtPlanDate(d)}</text>`;
    svg+=`<line x1="${xAt(idx)}" y1="${padT}" x2="${xAt(idx)}" y2="${padT+plotH}" stroke="#f1f5f9"/>`;
  });
  // series lines + markers + optional labels
  series.forEach((s,si)=>{
    const col=OV_SERIES_COLORS[si%OV_SERIES_COLORS.length];
    let path='', pts=[];
    s.values.forEach((v,idx)=>{ if(v===null){ return; } const x=xAt(idx), y=yAt(v,s); pts.push([x,y,v]); path+=(path?'L':'M')+x+' '+y+' '; });
    if(path) svg+=`<path d="${path}" fill="none" stroke="${col}" stroke-width="2"/>`;
    pts.forEach(([x,y,v])=>{ svg+=`<circle cx="${x}" cy="${y}" r="3.5" fill="#fff" stroke="${col}" stroke-width="2"/>`;
      if(st.withNumber) svg+=`<text x="${x}" y="${y-8}" text-anchor="middle" fill="${col}" font-size="10">${(+(+v).toFixed(2))}</text>`; });
  });
  svg+='</svg>';
  chart.innerHTML=svg;
  // legend below chart
  if(legend) legend.innerHTML=series.map((s,si)=>{ const col=OV_SERIES_COLORS[si%OV_SERIES_COLORS.length];
    return `<span class="lg-item"><span class="lg-swatch" style="background:${col}"></span>${s.label.replace(/</g,'&lt;')}</span>`; }).join('');
}
/* Overview sub-tabs (Overview Plant | Trend | Weekly KPI Achievement) */
function activateOSub(sub){
  document.querySelectorAll('#panel-overview .subtab').forEach(t=>t.classList.toggle('active',t.dataset.osub===sub));
  const map={plant:'ov-plant',trend:'ov-trend-tab',wkpi:'ov-wkpi'};
  document.querySelectorAll('#panel-overview .subpanel').forEach(p=>p.classList.toggle('active',p.id===map[sub]));
  if(sub==='trend') ovTrendInit();
  if(sub==='plant' && typeof ovPlantRender==='function') ovPlantRender();
}
document.querySelectorAll('#panel-overview .subtab').forEach(b=>b.addEventListener('click',()=>activateOSub(b.dataset.osub)));

/* ============================================================================
   OVERVIEW → OVERVIEW PLANT — "Plant diagram — blocks, units, IE & PLN Export"
   REDESIGN (UI/presentasi saja; engine, formula, constraint tidak disentuh).

   MAPPING — hanya dari bukti source, tidak ada tebakan:
     · Blok & anggota unit DITURUNKAN LANGSUNG dari PRI_BLOCKS (satu-satunya deklarasi
       blok yang ada di source, dipakai assemblePriorities() -> block_priority /
       unit_priority yang dikirim ke engine). Karena diturunkan, diagram tidak bisa
       menyimpang dari data yang dipakai engine.
     · Bus A/B DIBACA LANGSUNG dari kontrol [data-bus] (sumber modeling.bus_unit.<u>_bus).
       Tidak di-hardcode. Unit tanpa nilai ditandai "-".
     · HRSG: TIDAK ADA mapping GTG↔HRSG↔STG di source manapun. HRSG hanya muncul sebagai
       h1..h6,h8,h9 pada STOP_UNITS. Node HRSG karena itu ditampilkan mengikuti KONVENSI
       PENOMORAN dan diberi label eksplisit "assumed — not source-verified".
     · Utilization %, Effective Min/Max, dan Reserve headroom: tidak ada sumber MW maksimum
       per unit pada source yang tersedia -> ditampilkan sebagai "data unavailable",
       tidak dihitung ulang di UI.
   ========================================================================== */
/* Blok CCGT & grup non-CCGT diturunkan dari PRI_BLOCKS (lihat catatan di atas). */
function ovpBlocks(){
  const src = (typeof PRI_BLOCKS !== 'undefined' && Array.isArray(PRI_BLOCKS)) ? PRI_BLOCKS : [];
  return src.map(b => ({ name: b.name, units: (b.gtg||[]).slice(), stg: b.stg || null,
                         ccgt: !!b.stg, id: b.name.toLowerCase().replace(/[^a-z0-9]+/g,'-') }));
}
/* backend key (g3/s1/ge1/b1) -> key kolom output (G3/S1/GE1/BB1) */
function ovpKey(u){ u=String(u).toLowerCase(); if(u==='b1')return'BB1'; if(u==='b2')return'BB2'; return u.toUpperCase(); }
function ovpLabel(u){
  u=String(u).toLowerCase();
  if(/^g\d+$/.test(u))  return 'GTG '+u.slice(1);
  if(/^s\d$/.test(u))   return 'STG '+u.slice(1);
  if(/^ge\d$/.test(u))  return 'Gas Engine '+u.slice(2);
  if(u==='b1') return 'BBLN 1'; if(u==='b2') return 'BBLN 2';
  if(/^h\d+$/.test(u))  return 'HRSG '+u.slice(1);
  return u.toUpperCase();
}
/* Bus per unit dari kontrol live [data-bus] (modeling.bus_unit.<u>_bus). '-' bila tidak ada. */
function ovpBusMap(){
  const map={};
  document.querySelectorAll('[data-bus]').forEach(el=>{ map[String(el.dataset.bus).toLowerCase()]=el.value||''; });
  if(!Object.keys(map).length){
    try{ const bu=((window.INPUT||{}).data3||{}).modeling.bus_unit||{};
      Object.keys(bu).forEach(k=>{ map[k.replace(/_bus$/,'').toLowerCase()]=bu[k]; }); }catch(e){}
  }
  return map;
}
function ovpTypeOf(u){ u=String(u).toLowerCase();
  if(/^h\d/.test(u))return'HRSG'; if(/^s\d$/.test(u))return'STG';
  if(/^ge\d/.test(u))return'GE'; if(/^b\d$/.test(u))return'PLTU'; return'GTG'; }
// Per-type modern SVG icon (inline, offline). Returns a <g> centred at (0,0) in ~44px box.
function ovpIcon(type){
  if(type==='GTG'){ // gas turbine: intake cone + compressor turbine + generator casing + exhaust
    return '<g><rect x="-23" y="-11" width="26" height="22" rx="4" fill="#e0f2fe" stroke="#0284c7" stroke-width="1.6"/>'
      +'<path d="M-23 -7 L-30 -3 L-30 3 L-23 7 Z" fill="#bae6fd" stroke="#0284c7" stroke-width="1.4"/>'
      +'<path d="M-17 -7 l4 7 -4 7 M-11 -7 l4 7 -4 7 M-5 -7 l4 7 -4 7" stroke="#0284c7" stroke-width="1.4" fill="none"/>'
      +'<rect x="5" y="-9" width="18" height="18" rx="4" fill="#0ea5e9" stroke="#0369a1" stroke-width="1.4"/>'
      +'<circle cx="14" cy="0" r="5" fill="#e0f2fe" stroke="#0369a1" stroke-width="1.3"/>'
      +'<path d="M14 -3.4 v6.8 M10.6 0 h6.8" stroke="#0369a1" stroke-width="1.2"/></g>';
  }
  if(type==='HRSG'){ // horizontal HRSG: boiler body + tube banks + stack
    return '<g><rect x="-24" y="-8" width="40" height="17" rx="3" fill="#f3e8ff" stroke="#7c3aed" stroke-width="1.6"/>'
      +'<path d="M-19 -8 v17 M-12 -8 v17 M-5 -8 v17 M2 -8 v17 M9 -8 v17" stroke="#a78bfa" stroke-width="1.2"/>'
      +'<rect x="16" y="-20" width="7" height="29" rx="2" fill="#ede9fe" stroke="#7c3aed" stroke-width="1.6"/>'
      +'<path d="M-24 3 h-5" stroke="#7c3aed" stroke-width="1.6"/></g>';
  }
  if(type==='STG'){ // steam turbine: expanding casing + rotor blades + generator
    return '<g><path d="M-20 -5 L2 -11 L2 11 L-20 5 Z" fill="#dcfce7" stroke="#059669" stroke-width="1.6"/>'
      +'<path d="M-14 -3.4 v6.8 M-8 -4.8 v9.6 M-2 -6.4 v12.8" stroke="#059669" stroke-width="1.3"/>'
      +'<rect x="6" y="-9" width="17" height="18" rx="4" fill="#10b981" stroke="#047857" stroke-width="1.4"/>'
      +'<circle cx="14.5" cy="0" r="5" fill="#dcfce7" stroke="#047857" stroke-width="1.3"/></g>';
  }
  if(type==='GE'){ // gas engine: engine block + cylinder heads + flywheel
    return '<g><rect x="-22" y="-7" width="28" height="15" rx="3" fill="#ffedd5" stroke="#ea580c" stroke-width="1.6"/>'
      +'<rect x="-19" y="-13" width="5.5" height="7" rx="1" fill="#fed7aa" stroke="#ea580c" stroke-width="1.3"/>'
      +'<rect x="-11" y="-13" width="5.5" height="7" rx="1" fill="#fed7aa" stroke="#ea580c" stroke-width="1.3"/>'
      +'<rect x="-3" y="-13" width="5.5" height="7" rx="1" fill="#fed7aa" stroke="#ea580c" stroke-width="1.3"/>'
      +'<circle cx="13" cy="0.5" r="8" fill="#fb923c" stroke="#c2410c" stroke-width="1.4"/>'
      +'<circle cx="13" cy="0.5" r="3.4" fill="#ffedd5" stroke="#c2410c" stroke-width="1.2"/></g>';
  }
  if(type==='PLTU'){ // coal plant: cooling tower + building + chimney
    return '<g><path d="M6 12 c-5 0 -7 -4 -7 -9 c0 -5 2 -9 7 -9 c5 0 7 4 7 9 c0 5 -2 9 -7 9 Z" fill="#e2e8f0" stroke="#475569" stroke-width="1.5"/>'
      +'<rect x="-24" y="-4" width="20" height="16" rx="2" fill="#cbd5e1" stroke="#475569" stroke-width="1.5"/>'
      +'<rect x="-21" y="1" width="4" height="4" fill="#f8fafc"/><rect x="-14" y="1" width="4" height="4" fill="#f8fafc"/>'
      +'<rect x="-11" y="-18" width="5" height="14" rx="1.5" fill="#e2e8f0" stroke="#475569" stroke-width="1.5"/></g>';
  }
  return '';
}
// ---- latest Daily Plan for today ----
function ovpTodayKey(){ return new Date().toISOString().slice(0,10); }
function ovpNearestSlotIdx(){ const n=new Date(); const mins=n.getHours()*60+n.getMinutes(); let idx=Math.floor(mins/30); if(idx>47)idx=47; return idx; }
function ovpLatestTodaySnapshot(){
  const key=ovpTodayKey(); const [y,mo]=key.split('-'); const month=RA_MONTHS[+mo-1];
  const arr=(REPORT_PLANNING[y]&&REPORT_PLANNING[y][month]&&REPORT_PLANNING[y][month][key])||null;
  if(!arr||!arr.length) return null;
  let best=arr[0];
  arr.forEach(p=>{ if((p.saved_at||'')>=(best.saved_at||'')) best=p; });
  return best;
}
function ovpPlanningRow(){
  const snap=ovpLatestTodaySnapshot();
  if(!snap||!snap.snapshot||!snap.snapshot.output||!snap.snapshot.output.data) return null;
  const data=snap.snapshot.output.data; const idx=Math.min(ovpNearestSlotIdx(),data.length-1);
  return data[idx]||null;
}
function ovpUnitPlanningLoad(row,k){
  if(!row) return null;                       /* null = data unavailable, BUKAN nol */
  const v=row[k]; const n=Number(v); return isFinite(n)?n:null;
}
/* state UI diagram (filter, fokus, zoom) — murni presentasi */
const OVP_UI={block:'all',status:'all',showOff:true,focus:null,scale:1};

function ovPlantRender(){
  const host=$('ov-plant-diagram'); if(!host) return;
  const prow=ovpPlanningRow();
  const bus=ovpBusMap();
  const blocks=ovpBlocks();
  const has=v=>v!==null&&v!==undefined;
  const fmtMW=v=>has(v)?(+(+v).toFixed(1)).toLocaleString('en-US'):'—';
  /* skala bar per unit dipakai HANYA sebagai indikator relatif terhadap unit termuat
     tertinggi pada slot yang sama; ini bukan utilization (tidak ada MW maksimum di source). */
  let peak=0;
  blocks.forEach(b=>{ b.units.concat(b.stg?[b.stg]:[]).forEach(u=>{
    const v=ovpUnitPlanningLoad(prow,ovpKey(u)); if(has(v)&&v>peak) peak=v; }); });

  function statusOf(v){
    if(!has(v)) return {cls:'na',txt:'No data'};
    return v>0 ? {cls:'on',txt:'Online'} : {cls:'off',txt:'Offline'};
  }
  function unitCard(u,chain){
    const t=ovpTypeOf(u), k=ovpKey(u);
    const isH=(t==='HRSG');
    const v=isH?null:ovpUnitPlanningLoad(prow,k);
    const st=statusOf(v);
    const typeName={GTG:'Gas Turbine',HRSG:'HRSG',STG:'Steam Turbine',GE:'Gas Engine',PLTU:'Coal Plant'}[t];
    const b=isH?'':(bus[String(u).toLowerCase()]||'');
    const busChip=isH?'':('<span class="ovp-chip '+(b==='A'?'busA':(b==='B'?'busB':''))+'">Bus '+(b||'-')+'</span>');
    const body=isH
      ? '<div class="ovp-steam">steam path</div><div class="ovp-na">mapping assumed · load data unavailable</div>'
      : '<div class="ovp-mw">'+fmtMW(v)+'<small>MW</small></div>'
        +'<div class="ovp-st '+st.cls+'"><span class="d"></span>'+st.txt+'</div>'
        +'<div class="ovp-bar"><i style="width:'+(peak>0&&has(v)?Math.max(0,Math.min(100,v/peak*100)):0)+'%"></i></div>'
        +'<div class="ovp-na">utilization · reserve headroom: data unavailable</div>';
    const title=isH
      ? ovpLabel(u)+' — hubungan ke GTG/STG mengikuti konvensi penomoran; tidak dapat diverifikasi dari source.'
      : ovpLabel(u)+' — Bus '+(b||'tidak diketahui')+' · load '+fmtMW(v)+' MW · '+st.txt
        +'. Utilization dan Reserve headroom tidak tersedia pada source.';
    return '<div class="ovp-unit ovp-'+t.toLowerCase()+(has(v)&&v<=0?' ovp-off':'')+'" tabindex="0" role="button"'
      +' data-unit="'+u+'" data-chain="'+chain+'" data-online="'+(has(v)&&v>0?'1':'0')+'"'
      +' title="'+title.replace(/"/g,'&quot;')+'">'
      +'<svg viewBox="-34 -30 68 52" width="58" height="44" aria-hidden="true">'+ovpIcon(t)+'</svg>'
      +'<div class="ovp-un">'+ovpLabel(u)+' '+busChip+'</div>'
      +'<div class="ovp-ut">'+typeName+'</div>'+body+'</div>';
  }
  const arrow=(dashed,chain)=>'<div class="ovp-arrow'+(dashed?' dash':'')+'" data-chain="'+chain+'">'
    +'<svg viewBox="0 0 34 12" width="30" height="12" aria-hidden="true"><line x1="0" y1="6" x2="24" y2="6" stroke="currentColor" stroke-width="2"'
    +(dashed?' stroke-dasharray="4 3"':'')+'/><path d="M24 1 L31 6 L24 11 Z" fill="currentColor"/></svg></div>';

  function blockCard(b){
    let mw=0, any=false;
    b.units.concat(b.stg?[b.stg]:[]).forEach(u=>{ const v=ovpUnitPlanningLoad(prow,ovpKey(u));
      if(has(v)){ mw+=v; any=true; } });
    const head='<div class="ovp-bh">'+b.name
      +'<span class="ovp-bt">'+(b.ccgt?'CCGT — GTG → HRSG → STG':'Output → IE Bus')+'</span>'
      +'<span class="ovp-bmw">'+(any?fmtMW(mw):'—')+' MW</span></div>';
    if(b.ccgt){
      /* HRSG mengikuti nomor GTG-nya (assumed, not source-verified) */
      let rows='';
      b.units.forEach(u=>{ const h='h'+String(u).replace(/^g/,'');
        rows+='<div class="ovp-row">'+unitCard(u,b.id)+arrow(false,b.id)+unitCard(h,b.id)+arrow(true,b.id)+'</div>'; });
      return '<div class="ovp-block" data-block="'+b.id+'">'+head
        +'<div class="ovp-cc"><div class="ovp-chains">'+rows+'</div>'
        +'<div class="ovp-stgcol">'+unitCard(b.stg,b.id)+'</div></div>'
        +'<div class="ovp-tap"><span class="dot"></span>GTG + STG output → IE Bus</div></div>';
    }
    return '<div class="ovp-block" data-block="'+b.id+'">'+head
      +'<div class="ovp-grow">'+b.units.map(u=>unitCard(u,b.id)).join('')+'</div>'
      +'<div class="ovp-tap"><span class="dot"></span>Output → IE Bus</div></div>';
  }

  /* ---- node agregasi: IE Bus → PLN Export, plus Bus Flow / Spinning Reserve / Houseload ---- */
  const ieVal   = ovpUnitPlanningLoad(prow,'IE');
  const expVal  = ovpUnitPlanningLoad(prow,'Export_PLN');
  const busFlow = ovpUnitPlanningLoad(prow,'BusFlow');
  const spinRes = ovpUnitPlanningLoad(prow,'Spin_Res');
  const houseLd = ovpUnitPlanningLoad(prow,'HL');
  const info=(OUTPUT&&OUTPUT.info)?OUTPUT.info:{};
  const expState = !prow ? {c:'na',t:'unavailable'}
    : (info['PLN Export Compliance']===undefined ? {c:'na',t:'status unavailable'}
      : (info['PLN Export Compliance']==='OK' ? {c:'ok',t:'within range'} : {c:'warn',t:String(info['PLN Export Compliance'])}));
  const rail='<div class="ovp-rail">'
    +'<div class="ovp-busbar"><span>IE&nbsp;BUS</span></div>'
    +'<div class="ovp-trunk"></div>'
    +'<div class="ovp-node ie"><div class="nl">Total IE</div><div class="nv">'+fmtMW(ieVal)+'<small> MWh</small></div>'
      +'<span class="ns '+(has(ieVal)?'ok':'na')+'">'+(has(ieVal)?'from plan slot':'unavailable')+'</span></div>'
    +'<div class="ovp-trunk short"></div>'
    +'<div class="ovp-node exp"><div class="nl">PLN Export</div><div class="nv">'+fmtMW(expVal)+'<small> MW</small></div>'
      +'<span class="ns '+expState.c+'">'+expState.t+'</span></div>'
    +'<div class="ovp-side">'
      +'<div><span class="k">Bus Flow</span><span class="v'+(has(busFlow)?'':' na')+'">'+(has(busFlow)?(fmtMW(busFlow)+' MW'):'unavailable')+'</span></div>'
      +'<div><span class="k">Spinning Reserve</span><span class="v'+(has(spinRes)?'':' na')+'">'+(has(spinRes)?(fmtMW(spinRes)+' MW'):'unavailable')+'</span></div>'
      +'<div><span class="k">Houseload</span><span class="v'+(has(houseLd)?'':' na')+'">'+(has(houseLd)?(fmtMW(houseLd)+' MW'):'unavailable')+'</span></div>'
      +'<div><span class="k">Reserve headroom</span><span class="v na">data unavailable</span></div>'
    +'</div></div>';

  /* ---- §6.6 toolbar: filter blok, filter status, offline, Fit to View, Reset, Full Screen ---- */
  const seg=(name,opts,cur)=>'<span class="ovp-seg" data-seg="'+name+'">'
    +opts.map(o=>'<button type="button" data-v="'+o[0]+'" class="'+(o[0]===cur?'on':'')+'">'+o[1]+'</button>').join('')+'</span>';
  const toolbar='<div class="ovp-toolbar">'
    +'<span class="ovp-tl">Block</span>'
    +seg('block',[['all','All']].concat(blocks.map(b=>[b.id,b.name])),OVP_UI.block)
    +'<span class="ovp-tl">Status</span>'
    +seg('status',[['all','All'],['on','Online'],['off','Offline']],OVP_UI.status)
    +'<label class="ovp-chk"><input type="checkbox" id="ovp-showoff"'+(OVP_UI.showOff?' checked':'')+'>Show offline units</label>'
    +'<span class="ovp-right">'
      +'<button type="button" class="btn ghost" id="ovp-fit">Fit to View</button>'
      +'<button type="button" class="btn ghost" id="ovp-reset">Reset View</button>'
      +'<button type="button" class="btn ghost" id="ovp-fs">Full Screen</button>'
    +'</span></div>';

  const note='<div class="ovp-note">'+(prow
    ? ('<b>Planning</b> Daily Plan tersimpan untuk hari ini '+fmtPlanDate(ovpTodayKey())
       +' · slot '+(ovpNearestSlotIdx()+1)+' dari 48. Status dan load dibaca dari baris slot tersebut.')
    : '<b>Data unavailable</b> — belum ada Daily Plan tersimpan untuk hari ini. Status, load, IE, dan PLN Export ditampilkan sebagai “—”, bukan nol.')
    +'</div>';

  host.innerHTML=toolbar+note
    +'<div class="ovp-viewport" id="ovp-viewport"><div class="ovp-stage" id="ovp-stage">'
    +'<div class="ovp-wrap"><div class="ovp-blocks">'+blocks.map(blockCard).join('')+'</div>'+rail+'</div>'
    +'</div></div>'
    +'<div class="ovp-legend">'
      +'<span><span class="sw" style="background:#0fae8e"></span>Online</span>'
      +'<span><span class="sw" style="background:#c2cde0"></span>Offline / no load</span>'
      +'<span><span class="sw" style="background:#e0b062"></span>No data</span>'
      +'<span><span class="sw" style="background:#94a3b8"></span>GTG → HRSG (solid)</span>'
      +'<span><span class="sw" style="background:#c4b5fd"></span>HRSG → STG (dashed, steam)</span>'
      +'<span><span class="sw" style="background:#16233c"></span>Block output → IE Bus → PLN Export</span>'
      +'<span style="margin-left:auto;color:var(--accent)">Klik unit untuk menyorot jalur; klik lagi untuk melepas.</span>'
    +'</div>'
    +'<div class="ovp-mapnote"><b>Mapping yang dipakai.</b> Blok dan anggota unit diturunkan langsung dari '
      +'<code>PRI_BLOCKS</code> — deklarasi blok yang sama yang menghasilkan <code>block_priority</code> / '
      +'<code>unit_priority</code> untuk engine. Bus A/B dibaca dari kontrol <code>[data-bus]</code> '
      +'(<code>modeling.bus_unit</code>). <b>HRSG: assumed, not source-verified</b> — tidak ada mapping '
      +'GTG↔HRSG↔STG di source; node HRSG mengikuti konvensi penomoran dan ditandai. '
      +'<b>Data unavailable</b> — utilization %, Effective Min/Max, dan Reserve headroom per unit tidak '
      +'memiliki sumber pada source yang tersedia dan tidak dihitung ulang di UI.</div>';

  ovpBindDiagram();
  ovSummaryRender();
}

/* interaksi diagram: filter, highlight jalur, zoom, full screen (presentasi saja) */
function ovpBindDiagram(){
  const host=$('ov-plant-diagram'); if(!host) return;
  const stage=$('ovp-stage'), vp=$('ovp-viewport');
  host.querySelectorAll('.ovp-seg[data-seg]').forEach(seg=>{
    seg.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
      OVP_UI[seg.dataset.seg]=b.dataset.v;
      seg.querySelectorAll('button').forEach(x=>x.classList.toggle('on',x===b));
      ovpApplyFilters();
    }));
  });
  const off=$('ovp-showoff');
  if(off) off.addEventListener('change',()=>{ OVP_UI.showOff=off.checked; ovpApplyFilters(); });
  host.querySelectorAll('.ovp-unit[data-unit]').forEach(el=>{
    const act=()=>{ OVP_UI.focus=(OVP_UI.focus===el.dataset.unit)?null:el.dataset.unit; ovpApplyFocus(); };
    el.addEventListener('click',act);
    el.addEventListener('keydown',e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); act(); } });
  });
  const fit=$('ovp-fit'), rst=$('ovp-reset'), fs=$('ovp-fs');
  if(fit) fit.addEventListener('click',()=>{
    if(!stage||!vp) return;
    stage.style.transform='none';
    const w=stage.scrollWidth, avail=vp.clientWidth-28;
    OVP_UI.scale=(w>avail&&w>0)?Math.max(.45,avail/w):1;
    stage.style.transform='scale('+OVP_UI.scale+')';
    stage.style.height=(stage.scrollHeight*OVP_UI.scale)+'px';
  });
  if(rst) rst.addEventListener('click',()=>{
    OVP_UI.scale=1; OVP_UI.focus=null;
    if(stage){ stage.style.transform='none'; stage.style.height=''; }
    ovpApplyFocus(); if(vp) vp.scrollLeft=0;
  });
  if(fs) fs.addEventListener('click',()=>{
    const on=document.body.classList.toggle('ovp-fs-on');
    fs.textContent=on?'Exit Full Screen':'Full Screen';
  });
  document.addEventListener('keydown',ovpEscFs);
  ovpApplyFilters(); ovpApplyFocus();
}
function ovpEscFs(e){
  if(e.key!=='Escape') return;
  if(document.body.classList.contains('ovp-fs-on')){
    document.body.classList.remove('ovp-fs-on');
    const fs=$('ovp-fs'); if(fs) fs.textContent='Full Screen';
  }
}
function ovpApplyFilters(){
  const host=$('ov-plant-diagram'); if(!host) return;
  host.querySelectorAll('.ovp-block[data-block]').forEach(b=>
    b.classList.toggle('ovp-hide',OVP_UI.block!=='all'&&b.dataset.block!==OVP_UI.block));
  host.querySelectorAll('.ovp-unit[data-unit]').forEach(u=>{
    const online=u.dataset.online==='1';
    const isH=u.classList.contains('ovp-hrsg');
    let hide=false;
    if(!isH){
      if(OVP_UI.status==='on'  && !online) hide=true;
      if(OVP_UI.status==='off' &&  online) hide=true;
      if(!OVP_UI.showOff && !online) hide=true;
    }
    u.classList.toggle('ovp-hide',hide);
  });
}
function ovpApplyFocus(){
  const host=$('ov-plant-diagram'), stage=$('ovp-stage'); if(!host||!stage) return;
  const f=OVP_UI.focus;
  host.querySelectorAll('.ovp-unit,.ovp-arrow,.ovp-rail').forEach(e=>e.classList.remove('ovp-on'));
  if(!f){ stage.classList.remove('ovp-focused'); return; }
  const el=host.querySelector('.ovp-unit[data-unit="'+f+'"]'); if(!el){ stage.classList.remove('ovp-focused'); return; }
  const chain=el.dataset.chain;
  /* jalur = seluruh anggota blok unit tersebut (GTG → HRSG → STG) + rail IE Bus → PLN Export */
  host.querySelectorAll('[data-chain="'+chain+'"]').forEach(e=>e.classList.add('ovp-on'));
  const rail=host.querySelector('.ovp-rail'); if(rail) rail.classList.add('ovp-on');
  stage.classList.add('ovp-focused');
}
/* Summary dashboard — grouped KPI cards (Addendum: category grouping). The 22 known summary parameters
   (RP_INFO_MAP) are organised into 9 categories; each category is a titled section of mini KPI cards.
   Names are normalized (BaGS/BAGS -> BaGS, BABEALN -> BABELAN, uppercase+trim for matching) but values
   and units are left untouched. Empty categories are hidden; no dummy params are added; value 0 still shows. */
function ovSummaryRender(){
  const box=$('ov-summary-cards'); if(!box) return;
  const info=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info:null;
  // normalize a parameter name for matching: uppercase, collapse whitespace, fix known typo, unify BAGS spelling
  const norm=(s)=>(''+s).toUpperCase().replace(/\s+/g,' ').trim().replace('BABEALN','BABELAN').replace(/BA[GS]{2,}/g,'BAGS');
  // Build normalized lookup. Source: OUTPUT.info via RP_INFO_MAP canonical cols, else latest planning
  // snapshot summary. Also scan RAW summary keys so typo'd/odd-cased names still match a category.
  const byNorm={};
  if(info){
    RP_INFO_MAP.forEach(([col,key])=>{ const v=info[key]; if(v!==undefined&&v!==null&&v!=='') byNorm[norm(col)]={display:col,value:v}; });
  } else {
    const snap=ovpLatestTodaySnapshot();
    const summary=(snap&&snap.summary)?snap.summary:null;
    if(summary){
      RP_INFO_MAP.forEach(([col])=>{ const v=summary[col]; if(v!==undefined&&v!==null&&v!=='') byNorm[norm(col)]={display:col,value:v}; });
      Object.keys(summary).forEach(k=>{ const nk=norm(k); if(byNorm[nk]===undefined){ const v=summary[k]; if(v!==undefined&&v!==null&&v!=='') byNorm[nk]={display:k,value:v}; } });
    }
  }
  if(!Object.keys(byNorm).length){ box.innerHTML='<div class="placeholder" style="grid-column:1/-1"><h3>No latest simulation</h3><p>Run a Daily Plan or save a plan to populate summary.</p></div>'; return; }
  const UNITS={'IE PREDICTION':'MWh','JBBK MM PROD':'MWh','BABELAN PROD':'MWh','BABELAN CF':'%','DAILY PLN EXPORT':'MWh',
    'TOTAL FUEL':'BBTUD','GAS FUEL TOTAL':'BBTUD','DISTILLATE FUEL TOTAL':'liter','PGN TOTAL':'BBTUD','PGN PIPE TOTAL':'BBTUD',
    'LNG TOTAL':'BBTUD','PEP JBBK TOTAL':'BBTUD','PEP KP72 TOTAL':'BBTUD','AKASIA JBBK TOTAL':'BBTUD','AKASIA KP72 TOTAL':'BBTUD',
    'BAGS JBBK TOTAL':'BBTUD','BAGS KP72 TOTAL':'BBTUD','TOTAL COAL':'ton','% RE BABELAN':'%','GHG EMISSION':'ton CO2e/MWh','HEATRATE':'BTU/kWh',
    'TOTAL COST':'USD','COST PRODUCTION':'USD/MWh'};
  // category -> ordered list of member params (by normalized name) + preferred display label
  const CATS=[
    {key:'general', title:'GENERAL', members:['IE PREDICTION','JBBK MM PROD','DAILY PLN EXPORT']},
    {key:'eff',     title:'HEATRATE JBBK-MM', members:['HEATRATE']},
    {key:'cost',    title:'COST', members:['TOTAL COST','COST PRODUCTION','JABABEKA COST PRODUCTION','MM2100 COST PRODUCTION','COMBINED COST PRODUCTION']},
    {key:'fuel',    title:'FUEL TOTAL', members:['TOTAL FUEL','GAS FUEL TOTAL','DISTILLATE FUEL TOTAL']},
    {key:'pgn',     title:'PGN', members:['PGN TOTAL','PGN PIPE TOTAL','LNG TOTAL']},
    {key:'pep',     title:'PEP', members:['PEP JBBK TOTAL','PEP KP72 TOTAL']},
    {key:'akasia',  title:'AKASIA', members:['AKASIA JBBK TOTAL','AKASIA KP72 TOTAL']},
    {key:'bags',    title:'BaGS', members:['BAGS JBBK TOTAL','BAGS KP72 TOTAL']},
    {key:'babelan', title:'BABELAN', members:['BABELAN CF','BABELAN PROD','TOTAL COAL','% RE BABELAN','GHG EMISSION']}
  ];
  // preferred display labels (normalized -> nice label): unify BaGS casing, force BABELAN PROD spelling
  const NICE={'BAGS JBBK TOTAL':'BaGS JBBK TOTAL','BAGS KP72 TOTAL':'BaGS KP72 TOTAL','BABELAN PROD':'BABELAN PROD'};
  const fmtVal=(v)=>{ const n=Number(v); if(!isFinite(n)||(''+v).trim()==='') return ''+v;
    return n.toLocaleString('en-US',{minimumFractionDigits:(Math.abs(n)>=1000?1:2),maximumFractionDigits:(Math.abs(n)>=1000?1:2)}); };
  let html='';
  CATS.forEach(cat=>{
    // members present in the data (normalized match) — hide category if none
    const present=cat.members.map(m=>byNorm[norm(m)]?{norm:norm(m),rec:byNorm[norm(m)]}:null).filter(Boolean);
    if(!present.length) return;
    const cards=present.map(({norm:nm,rec})=>{
      const label=NICE[nm]||rec.display; const unit=UNITS[label]||UNITS[nm]||'';
      return `<div class="summ-card cat-${cat.key}"><div class="sc-l">${(''+label).replace(/</g,'&lt;')}</div><div class="sc-v">${fmtVal(rec.value)}${unit?` <span class="sc-u">${unit}</span>`:''}</div></div>`;
    }).join('');
    html+=`<section class="summ-group cat-${cat.key}"><h4 class="summ-gt">${cat.title}</h4><div class="summ-grid">${cards}</div></section>`;
  });
  box.innerHTML=html||'<div class="placeholder" style="grid-column:1/-1"><h3>No summary parameters</h3><p>Latest simulation has no recognised summary parameters.</p></div>';
}

/* ===================== prefill forms ===================== */
function val(id,v){const el=$(id);if(el)el.value=(v??'');}
function chk(id,v){const el=$(id);if(el)el.checked=!!v;}
function ta(id,obj){const el=$(id);if(el)el.value=JSON.stringify(obj??[],null,1);}

function buildPeriodic(){
  const d3=INPUT.data3, m=d3.modeling;
  // unit limits table
  let h='<tr><th>Unit</th><th>min SC</th><th>min CC</th><th>max</th><th>STG link</th></tr>';
  UNIT_ORDER.forEach(u=>{const c=d3[u]||{};
    h+=`<tr><td><b>${UL(u)}</b></td>
      <td>${'min_scload'in c?`<input type="number" step="0.1" style="width:80px" data-u="${u}" data-k="min_scload" value="${c.min_scload}">`:'<span class=hint>—</span>'}</td>
      <td>${'min_ccload'in c?`<input type="number" step="0.1" style="width:80px" data-u="${u}" data-k="min_ccload" value="${c.min_ccload}">`:'<span class=hint>—</span>'}</td>
      <td>${'max_load'in c?`<input type="number" step="0.1" style="width:80px" data-u="${u}" data-k="max_load" value="${c.max_load}">`:'<span class=hint>—</span>'}</td>
      <td class="hint">${c.stg?('→ '+c.stg):(c.gtg?('◀ '+c.gtg.join(',')):'')}</td></tr>`;});
  $('tbl-units').innerHTML=h;
  // advanced data3 JSON (units only)
  const unitsOnly={};Object.keys(d3).forEach(k=>{if(k!=='modeling')unitsOnly[k]=d3[k];});
  $('ta-data3').value=JSON.stringify(unitsOnly,null,1);
  // price
  $('grid-price').innerHTML=PRICE_KEYS.map(k=>`<label class="fld">${k.toUpperCase()}${PRICE_UNIT[k]?` <span class="u">${PRICE_UNIT[k]}</span>`:''}<input type="number" step="0.01" id="p-${k}" value="${(m.price&&m.price[k])??0}"></label>`).join('');
  // GHG emission factors. Shared Periodic Input is used by both Plan and Monitoring Daily Plan.
  const ef=m.emission_factor||{};
  const gasEf=ef.gas_tco2_per_bbtud??53.7;
  const distEf=ef.distillate_tco2_per_liter??0.00233;
  const coalPct=ef.coal_carbon_pct??m.carbon_pct??43;
  $('grid-emission').innerHTML=`
    <label class="fld">Gas Emission Factor <span class="u">ton CO2/BBTUD</span><input type="number" step="0.0001" min="0" id="em-gas_tco2_per_bbtud" value="${gasEf}"></label>
    <label class="fld">Distillate Emission Factor <span class="u">ton CO2/liter</span><input type="number" step="0.000001" min="0" id="em-distillate_tco2_per_liter" value="${distEf}"></label>
    <label class="fld">% Coal Carbon Content <span class="u">%</span><input type="number" step="0.01" min="0" max="100" id="em-coal_carbon_pct" value="${coalPct}"></label>`;
  renderRuntime();                                        // Runtime & Downtime table
  // babelan/biomass
  /* PROMPT B2/B3/B6: RE Babelan (%) removed; Biomass Target (MWh) is the primary input on top.
   * Heatrate / Heating Value fields are UI/input storage only (not yet used by the optimizer). */
  const bbm=m.babelan_biomass||{};
  const bbTarget=bbm.biomass_target_mwh??m.biomass_MWh_target??0;
  $('grid-babelan').innerHTML=`
    <label class="fld">Biomass Target <span class="u">MWh</span><input type="number" step="1" id="b-biomass_MWh_target" value="${bbTarget}"></label>
    <label class="fld">Babelan CF target <span class="u">%</span><input type="number" step="1" id="b-babelan_CF_target" value="${m.babelan_CF_target??0}"></label>
    <label class="fld">PKS share <span class="u">%</span><input type="number" step="0.1" id="b-pks_pct" value="${m.pks_pct??0}"></label>
        <label class="fld">Heatrate Babelan <span class="u">kcal/kWh</span><input type="number" step="1" id="b-hr_babelan" value="${bbm.heatrate_babelan_kcal_kwh??0}"></label>
    <label class="fld">Heating Value PKS <span class="u">kcal/kg</span><input type="number" step="1" id="b-hv_pks" value="${bbm.heating_value_pks_kcal_kg??0}"></label>
    <label class="fld">Heating Value Woodchip <span class="u">kcal/kg</span><input type="number" step="1" id="b-hv_woodchip" value="${bbm.heating_value_woodchip_kcal_kg??0}"></label>`;
  // bus
  let bh='<tr><th>Unit</th><th>Bus</th><th>Unit</th><th>Bus</th></tr>';
  const bu=m.bus_unit||{};const half=Math.ceil(UNIT_ORDER.length/2);
  for(let i=0;i<half;i++){const mk=(u)=>{if(!u)return '<td></td><td></td>';const cur=bu[u+'_bus']||'A';
    return `<td><b>${UL(u)}</b></td><td><select class="buscell ${cur==='A'?'busA':'busB'}" data-bus="${u}" onchange="this.className='buscell '+(this.value==='A'?'busA':'busB')">
      <option ${cur==='A'?'selected':''}>A</option><option ${cur==='B'?'selected':''}>B</option></select></td>`;};
    bh+=`<tr>${mk(UNIT_ORDER[i])}${mk(UNIT_ORDER[i+half])}</tr>`;}
  $('tbl-bus').innerHTML=bh;
  // unit characteristic coefficient editors + structured priority editors
  renderUnitChar();
  renderPriorityEditors();
}

/* ---- Name Plan auto-generation (Revisi UI Name Plan) ----
   Name Plan is readonly and always derived: "Daily Plan dd-MMM-yy [Remark]".
   Source of truth = plan_date + plan_remark; plan_name stored as a convenience value. */
const MONTHS_ABBR=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function fmtPlanDate(iso){
  const m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(iso||''); if(!m) return '';
  const dd=m[3], mon=MONTHS_ABBR[parseInt(m[2],10)-1], yy=m[1].slice(2);
  return `${dd}-${mon}-${yy}`;
}
function computeNamePlan(iso,remark){
  const d=fmtPlanDate(iso);
  const prefix=(typeof dpMon==='function'&&dpMon())?'Monitoring Daily Plan':'Daily Plan';   // PROMPT MONITORING §4.2
  if(!d) return prefix;
  const r=(remark||'').trim();
  return prefix+' '+d+(r?(' '+r):'');
}

/* ============================================================================
   PROMPT MONITORING DAILY PLAN — branch state + TIME PASSED logic
   - Branch: 'plan' | 'monitoring'. Sumber kebenaran = modeling.monitoring_daily_plan.enabled.
   - TIME PASSED: single continuous cutoff (tanpa zig-zag). cutoff = jumlah row Y (1-based).
     Y di row r  -> semua row <= r jadi Y (hijau terang), sisanya N (merah terang).
     N di row r  -> semua row >= r jadi N.
   - Row Y = locked: nilai di-snapshot dari OUTPUT terakhir (atau snapshot existing) ke
     modeling.monitoring_daily_plan.locked_rows, lalu di-inject ke modeling.actual_data
     saat Run — memakai primitive engine TERUJI (actualRows: optimizer tidak menyentuh,
     total harian tetap menghitung 48 row penuh).
   ========================================================================== */
function dpMon(){
  return !!(INPUT&&INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.monitoring_daily_plan
            &&INPUT.data3.modeling.monitoring_daily_plan.enabled);
}
function mdpObj(create){
  const m=INPUT&&INPUT.data3&&INPUT.data3.modeling; if(!m) return null;
  if(!m.monitoring_daily_plan&&create) m.monitoring_daily_plan={enabled:false,time_passed_cutoff_row:0,locked_rows:{}};
  return m.monitoring_daily_plan||null;
}
function mdpCutoff(){ const o=mdpObj(false); return o?Math.max(0,Math.min(48,+o.time_passed_cutoff_row||0)):0; }
function mdpRowFromOutput(r,i){
  const o={row:i+1};
  [['g1','G1'],['g2','G2'],['g3','G3'],['g4','G4'],['g5','G5'],['g6','G6'],['g7','G7'],['g8','G8'],['g9','G9'],['g10','G10'],
   ['s1','S1'],['s2','S2'],['s3','S3'],['ge1','GE1'],['ge2','GE2'],['ge3','GE3'],['ge4','GE4'],['b1','BB1'],['b2','BB2']]
   .forEach(([k,K])=>{o[k]=+(r[K]??0)||0;});
  o.ie=+(r.IE??0)||0; o.hl=+(r.HL??0)||0; o.export=+(r.Export_PLN??0)||0;
  return o;
}
function mdpSetCutoff(cut){
  const o=mdpObj(true); if(!o) return;
  cut=Math.max(0,Math.min(48,cut|0));
  o.enabled=true; o.time_passed_cutoff_row=cut;
  const src=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.data)?OUTPUT.data:null;
  const lr={};
  for(let i=0;i<cut;i++){
    if(o.locked_rows&&o.locked_rows[i]){ lr[i]=o.locked_rows[i]; continue; }   // §3.5.1: snapshot existing dipertahankan
    if(src&&src[i]) lr[i]=mdpRowFromOutput(src[i],i);                          // §3.5.2: hasil simulasi saat ini
    /* §3.5.3: tanpa data sama sekali -> row tidak di-inject (engine memakai input existing, tanpa error) */
  }
  o.locked_rows=lr;
  /* konsistensi §6.1: TIME PASSED adalah otoritas cutoff — actual_data (upload) di atas cutoff
     ikut dibuang agar tidak ada row terkunci diam-diam melebihi pilihan user */
  const mC=INPUT.data3.modeling;
  if(mC.actual_data&&mC.actual_data.rows){
    mC.actual_data.rows=mC.actual_data.rows.filter(a=>+(a.row||0)<=cut);
    if(!mC.actual_data.rows.length) delete mC.actual_data;
  }
  if(typeof updateActualStatus==='function') updateActualStatus();
}
/* PROMPT MONITORING §3.4/§8.2 + LEAK GUARD antar-cabang:
   - Row injeksi monitoring ditandai _mdp:1. Setiap assemble, injeksi lama DIBUANG dulu
     (setelah Run, INPUT=payload menyimpan actual_data — tanpa guard ini, pindah ke cabang
     Plan akan membawa row locked lama dan mengunci Daily Plan normal).
   - CSV Actual Data asli (tanpa _mdp) selalu dipertahankan dan MENANG pada row yang sama.
   - Cabang Plan / cutoff 0: hanya CSV asli yang dipakai; injeksi monitoring hilang bersih. */
function mdpApplyActualData(m){
  const baseRows=((m.actual_data&&m.actual_data.rows)||[]).filter(a=>!a._mdp);
  const mdp=m.monitoring_daily_plan;
  if(!(mdp&&mdp.enabled&&(+mdp.time_passed_cutoff_row||0)>0)){
    if(baseRows.length) m.actual_data={rows:baseRows}; else delete m.actual_data;
    return;
  }
  const byRow={};
  Object.keys(mdp.locked_rows||{}).forEach(k=>{
    const i=+k, lr=mdp.locked_rows[k];
    if(lr&&i>=0&&i<(+mdp.time_passed_cutoff_row)) byRow[i+1]={...lr,row:i+1,_mdp:1};
  });
  baseRows.forEach(a=>{const rn=+(a.row||0); if(rn>=1){ byRow[rn]={...(byRow[rn]||{}),...a,row:rn}; delete byRow[rn]._mdp; }});   // CSV asli menang & TIDAK ber-flag _mdp
  const rowsArr=Object.keys(byRow).map(k=>byRow[k]).sort((a,b)=>a.row-b.row);
  if(rowsArr.length) m.actual_data={rows:rowsArr}; else delete m.actual_data;
}
/* ============================================================================
   PROMPT STATE ISOLATION §1/§3/§4/§5 — Plan vs Monitoring Daily Plan TERPISAH TOTAL.
   Arsitektur: dua slot state penuh (input+output) per cabang. TIDAK ADA key generik
   bersama: cabang aktif bekerja pada INPUT/OUTPUT, cabang non-aktif tersimpan beku di
   slot-nya. Pindah cabang = snapshot slot lama -> muat slot baru -> rebuild seluruh UI.
   Semua state §3.1 (Plan Basics, Periodic/Frequently Input, Simulation Data, output,
   TIME PASSED, locked rows, upload state) otomatis terisolasi karena hidup di dalam
   input/output masing-masing slot. Metadata wajib: modeling.plan_type per slot. */
let DP_STATES={plan:null,monitoring:null};
function dpDeepCopy(o){ return o==null?null:JSON.parse(JSON.stringify(o)); }
function dpActiveBranch(){ return dpMon()?'monitoring':'plan'; }
function dpSnapshotCurrent(){ DP_STATES[dpActiveBranch()]={input:dpDeepCopy(INPUT),output:dpDeepCopy(OUTPUT)}; }
/* Derivasi slot baru dari state cabang lain: SELALU dibersihkan sesuai §2/§6.2 —
   Plan turunan: plan_type='plan', monitoring OFF, TANPA actual_data/locked/TIME PASSED;
   Monitoring turunan: plan_type='monitoring', monitoring ON (cutoff 0, tanpa locked awal). */
function dpDeriveBranchState(srcInput,branch){
  const inp=dpDeepCopy(srcInput)||{data3:{modeling:{}}};
  if(!inp.data3) inp.data3={}; if(!inp.data3.modeling) inp.data3.modeling={};
  const m=inp.data3.modeling;
  m.plan_type=branch;
  /* PROMPT UI-TRIGGER §2.6/§2.7 (state isolation): derivasi cabang baru menyalin srcInput
   * (INPUT0/baseline) utuh, TERMASUK tiga array actual per-slot. Bila baseline memuat actual
   * (mis. tersimpan dari sesi Plan), array itu bocor ke cabang lain -> actual Plan "muncul" di
   * Monitoring turunan (dan sebaliknya). Cabang turunan WAJIB mulai TANPA actual per-slot;
   * masing-masing cabang mengisi actual-nya sendiri lewat Simulation Data. actual_data (locked
   * rows) sudah dibersihkan di bawah; ini melengkapi utk PGN/Jababeka/MM2100. */
  m.actual_pgn_total=Array(48).fill('');
  m.actual_energy_jababeka=Array(48).fill('');
  m.actual_energy_mm2100=Array(48).fill('');
  if(branch==='plan'){
    m.monitoring_daily_plan={enabled:false};
    delete m.actual_data;                                   // §6.2: Plan bersih dari locked rows/upload actual
  } else {
    m.monitoring_daily_plan={enabled:true,time_passed_cutoff_row:0,locked_rows:{},actual_upload_source:{}};
    delete m.actual_data;                                   // monitoring baru mulai bersih; upload/Y mengisinya
  }
  /* ROOT CAUSE FIX (PROMPT CTRLCLICK/FIXFLOW §2): derivasi lama men-set output:null sehingga
     saat pertama pindah ke Monitoring, TABEL DETAIL SLOT tidak dirender sama sekali ->
     kolom TIME PASSED "tidak muncul" dan tidak ada sel utk Ctrl+Click Fix Load / Fixed Flow.
     Monitoring wajib COPY PERSIS Plan TERMASUK hasil simulasi terakhir sbg snapshot awal:
     tabel + kolom TIME PASSED + seluruh Ctrl+Click langsung tersedia tanpa harus re-run. */
  return {input:inp,output:dpDeepCopy(typeof OUTPUT!=='undefined'?OUTPUT:null)};
}
function dpRebuildUI(){
  if(typeof buildPeriodic==='function') buildPeriodic();
  if(typeof buildFrequent==='function') buildFrequent();
  if(typeof autoNamePlan==='function') autoNamePlan();
  if(typeof updateActualStatus==='function') updateActualStatus();
  if(OUTPUT&&OUTPUT.data&&typeof renderResult==='function') renderResult(OUTPUT);
  else { const rs=$('result-summary'); if(rs) rs.innerHTML='<div class="placeholder"><h3>No simulation yet</h3><p>Run a simulation on this branch to see results here.</p></div>'; const tr=$('result-table'); if(tr) tr.innerHTML=''; }
  if(typeof refreshOverview==='function') refreshOverview();
  if(typeof refreshPills==='function') refreshPills();
  if(typeof refreshGasDecision==='function') refreshGasDecision();
}
function setDpBranch(b){
  const mon=b==='monitoring';
  const cur=dpActiveBranch();
  if(b!==cur){
    dpSnapshotCurrent();                                     // bekukan state cabang yang ditinggalkan
    if(!DP_STATES[b]){
      /* slot baru diderivasi dari BASELINE TERSIMPAN (INPUT0), bukan dari editan belum-save
         cabang aktif — perubahan Plan yang belum disave tidak bocor ke Monitoring baru (§4) */
      const baseSrc=(typeof INPUT0!=='undefined'&&INPUT0&&INPUT0.data3)?INPUT0:INPUT;
      DP_STATES[b]=dpDeriveBranchState(baseSrc,b);
    }
    INPUT=dpDeepCopy(DP_STATES[b].input);
    OUTPUT=dpDeepCopy(DP_STATES[b].output);
    const o=mdpObj(true); if(o) o.enabled=mon;               // konsistensi flag di slot yang dimuat
    INPUT.data3.modeling.plan_type=b;
    dpRebuildUI();
  } else {
    const o=mdpObj(true); if(o) o.enabled=mon;
    INPUT.data3.modeling.plan_type=b;
    if(typeof autoNamePlan==='function') autoNamePlan();
    if(OUTPUT&&OUTPUT.data&&typeof renderResult==='function') renderResult(OUTPUT);
  }
  const bp=$('dpb-plan'), bm=$('dpb-mon');
  if(bp) bp.classList.toggle('active',!mon);
  if(bm) bm.classList.toggle('active',mon);
  /* §6.2: toolbar Upload/Clear Actual Data hanya utk Monitoring (fitur ini yang me-lock row) */
  document.querySelectorAll('.actual-toolbar').forEach(el=>{el.style.display=mon?'':'none';});
}
function dpBranchInit(){ setDpBranch(dpMon()?'monitoring':'plan'); }
document.addEventListener('DOMContentLoaded',()=>{
  const bp=$('dpb-plan'), bm=$('dpb-mon');
  if(bp) bp.addEventListener('click',()=>setDpBranch('plan'));
  if(bm) bm.addEventListener('click',()=>setDpBranch('monitoring'));
  dpBranchInit();                                                               // restore state dari save/reload/snapshot (warna tetap benar)
});
/* dropdown TIME PASSED: delegated (table di-render ulang) — auto-normalize prefix + warna */
document.addEventListener('change',e=>{
  const t=e.target;
  if(!(t&&t.classList&&t.classList.contains('tp-sel'))) return;
  const ri=+t.dataset.tprow;
  mdpSetCutoff(t.value==='Y'?ri+1:ri);                                          // Y@r -> cutoff r+1; N@r -> cutoff r
  if(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.data) renderResult(OUTPUT);    // semua cell recolor hijau/merah
});
function autoNamePlan(){
  const pd=$('f-plan_date'), rk=$('f-plan_remark'), np=$('f-name_plan');
  if(!np) return;
  const name=computeNamePlan(pd?pd.value:'', rk?rk.value:'');
  np.value=name;
  if(INPUT&&INPUT.data3&&INPUT.data3.modeling){
    INPUT.data3.modeling.plan_name=name;
    if(pd) INPUT.data3.modeling.plan_date=pd.value;
    if(rk) INPUT.data3.modeling.plan_remark=rk.value;
    INPUT.data3.modeling.name_plan=name;   // keep legacy field consistent
  }
}
document.addEventListener('DOMContentLoaded',()=>{
  const pd=$('f-plan_date'), rk=$('f-plan_remark');
  if(pd) pd.addEventListener('change',autoNamePlan);
  if(rk){ rk.addEventListener('input',autoNamePlan); rk.addEventListener('change',autoNamePlan); }
});

function buildFrequent(){
  const m=INPUT.data3.modeling;
  { const pd=$('f-plan_date'); if(pd) pd.value=(m.plan_date&&/^\d{4}-\d{2}-\d{2}$/.test(m.plan_date))?m.plan_date:new Date().toISOString().slice(0,10); }
  { const rk=$('f-plan_remark'); if(rk) rk.value=m.plan_remark||''; }
  autoNamePlan();   // Name Plan is auto-generated (readonly) from plan_date + plan_remark
  val('f-house_load',m.house_load); val('f-spinning_reserve_min',m.spinning_reserve_min);
  const p=m.pln_export_priority||{};
  val('f-pln-range-min',p.range?.min); val('f-pln-range-max',p.range?.max); chk('f-pln-range-req',true); // Range is always required
  val('f-pln-dt-val',p.daily_target?.value); chk('f-pln-dt-req',p.daily_target?.required);
  { const _m=$('f-pln-dd-mode'); if(_m) _m.value=(p.dispatch_dev_mode==='recommend'?'recommend':'same_as_quota'); }
  // dispatch deviation: multi-rule (Revisi). Load from dispatch_dev_rules[] or legacy dispatch_dev.
  let ddr = Array.isArray(p.dispatch_dev_rules) ? p.dispatch_dev_rules.slice(0,5) : [];
  if(!ddr.length && p.dispatch_dev && (p.dispatch_dev.min||p.dispatch_dev.max||p.dispatch_dev.required))
    ddr=[{min:p.dispatch_dev.min,max:p.dispatch_dev.max,start:p.dispatch_dev.start,stop:p.dispatch_dev.stop}];
  DD_RULES = ddr.length ? ddr.map(r=>({min:num(r.min),max:num(r.max),start:clamp1(r.start||1),stop:clamp1(r.stop||1)}))
                        : [{min:0,max:0,start:1,stop:1}];
  const ddEnabled = Array.isArray(p.dispatch_dev_rules) ? p.dispatch_dev_rules.length>0 : !!(p.dispatch_dev&&p.dispatch_dev.required);
  chk('f-pln-dd-req', ddEnabled);
  renderDdRules();
  RANGE_RULES = Array.isArray(p.range_rules) ? p.range_rules.slice(0,4).map(r=>({min:num(r.min),max:num(r.max),start:clamp1(r.start||1),stop:clamp1(r.stop||1)})) : [];
  renderRangeRules();
  MAXLOAD_RULES=(m.max_load_rules&&typeof m.max_load_rules==='object')?JSON.parse(JSON.stringify(m.max_load_rules)):{};
  renderMaxLoadRules();
  initIeAdj(); renderIeAdj();
  FF_ROWS=((INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.manual_fixed_flows)||[]).map(e=>({area:e.area,row:+e.row,value_mmscfd:+e.value_mmscfd}));
  /* §7 PROMPT MM2100 REDESIGN: Gas Quota dua bagian — A. GAS JABABEKA (PGN PIPE, LNG, PEP, AKASIA,
   * BASKARA, BBG; max 4 per row) lalu GHV Jababeka + Min PGN Flow di section HTML di bawah;
   * B. GAS MM2100 (PEP/PERTAGAS/AKASIA/BASKARA KP72) + dropdown Fixed Flow / Cummulative per gas (§8):
   * hanya satu Cummulative (hijau terang); lainnya otomatis Fixed Flow (biru terang). */
  const JBBK_KEYS=['pgn_pipe','lng','pep','akasia','baskara','bbg'];
  const MM_KEYS=['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'];
  const gm=m.mm2100_gas_mode||{};
  /* PROMPT GAS QUOTA MMSCFD (§1): PEP/AKASIA/BASKARA/BBG = MMSCFD (fixed flow Jababeka;
     energi = flow x GHV Jababeka / 1000). Komponen lain tetap BBTUD. */
  const MMSCFD_KEYS=['pep','akasia','baskara','bbg'];
  /* Satuan MM2100 MENGIKUTI MODE: Cummulative -> BBTUD, Fixed Flow -> MMSCFD.
   * Hanya presentasi; value payload gas_quota.<k> dan mm2100_gas_mode.<k> TIDAK berubah. */
  const MM_UNIT=k=>((String(gm[k]||'fixed').toLowerCase().indexOf('cum')===0)?'BBTUD':'MMSCFD');
  const unitOfQ=k=>(MM_KEYS.includes(k)?MM_UNIT(k):(MMSCFD_KEYS.includes(k)?'MMSCFD':'BBTUD'));
  const fld=k=>`<label class="fld"${MM_KEYS.includes(k)?' title="Cummulative uses BBTUD; Fixed Flow uses MMSCFD."':''}>${k.replace(/_/g,' ').toUpperCase()} <span class="u" id="u-${k}">${unitOfQ(k)}</span><input type="number" step="0.1" id="q-${k}" value="${(m.gas_quota&&m.gas_quota[k])??0}"></label>`;
  const modeSel=k=>{const md=(String(gm[k]||'fixed').toLowerCase().indexOf('cum')===0)?'cummulative':'fixed';
    return `<label class="fld">${k.replace(/_/g,' ').toUpperCase()} MODE
      <select id="mode-${k}" class="mm-mode ${md==='cummulative'?'mm-cum':'mm-fix'}" data-gas="${k}">
        <option value="fixed"${md==='fixed'?' selected':''}>Fixed Flow</option>
        <option value="cummulative"${md==='cummulative'?' selected':''}>Cummulative</option>
      </select></label>`;};
  /* §4 PROMPT_FINAL_MIN_PGN: GHV Jababeka & Min PGN Flow DI DALAM kategori GAS JABABEKA,
   * di bawah 6 jenis gas (Row1: PGN PIPE|LNG|PEP|AKASIA; Row2: BASKARA|BBG; Row3: GHV|MinPGN).
   * GHV MM2100 & Maximum Flow MM2100 di dalam kategori GAS MM2100. */
  $('grid-quota').innerHTML=
    `<div style="grid-column:1/-1;font-weight:700;color:#1668b4;margin-top:2px">A. GAS JABABEKA <span class="fhint">PGN PIPE &amp; LNG dalam BBTUD · PEP / AKASIA / BASKARA / BBG dalam MMSCFD (fixed flow Jababeka = PEP+AKASIA+BASKARA+BBG)</span></div>`
    + JBBK_KEYS.map(fld).join('')
    + `<label class="fld" style="grid-column:1/span 1">GHV PGN <span class="u">BTU/SCF</span><input type="number" step="1" id="f-ghv_pgn"></label>`
    + `<label class="fld">GHV From Tegalgede to Jababeka <span class="u">BTU/SCF</span><input type="number" step="1" id="f-ghv_jababeka"></label>`
    + `<label class="fld">Min PGN Flow <span class="u">MMSCFD</span><input type="number" step="0.1" id="f-min_pgn_flow"></label>`
    + `<div style="grid-column:1/-1;font-weight:700;color:#0e7d6d;margin-top:10px">B. GAS MM2100 <span class="fhint">(BBTUD) — dropdown Fixed Flow / Cummulative per gas</span></div>`
    + MM_KEYS.map(k=>fld(k)+modeSel(k)).join('')
    + `<label class="fld" style="grid-column:1/span 1">GHV MM2100 <span class="u">BTU/SCF</span><input type="number" step="1" id="f-ghv_mm2100"></label>`
    + `<label class="fld">Maximum Flow MM2100 <span class="u">MMSCFD</span><input type="number" step="0.1" min="0" id="f-max_flow_mm2100" title="Batas maksimum volume gas MM2100 per row (MMSCFD). 0 = tanpa batas."></label>`;
  // enforce satu Cummulative (§8) + warna
  document.querySelectorAll('.mm-mode').forEach(sel=>{
    sel.addEventListener('change',()=>{
      if(sel.value==='cummulative'){
        document.querySelectorAll('.mm-mode').forEach(o=>{ if(o!==sel){o.value='fixed';o.classList.remove('mm-cum');o.classList.add('mm-fix');} });
      }
      sel.classList.toggle('mm-cum',sel.value==='cummulative');
      sel.classList.toggle('mm-fix',sel.value!=='cummulative');
      /* update label satuan LIVE untuk SELURUH gas MM2100 (mutual exclusion dapat mengubah yang lain) */
      document.querySelectorAll('.mm-mode').forEach(o=>{
        const gk=o.dataset.gas; const sp=document.getElementById('u-'+gk);
        if(sp) sp.textContent = (String(o.value).toLowerCase().indexOf('cum')===0) ? 'BBTUD' : 'MMSCFD';
      });
    });
  });
  val('f-ghv_jababeka',m.ghv_jababeka); val('f-ghv_pgn',m.ghv_pgn??''); val('f-ghv_mm2100',m.ghv_mm2100); val('f-min_pgn_flow',m.min_pgn_flow); val('f-busflow_min',m.busflow_min);
  val('f-additional_lng',m.additional_lng);
  val('f-max_flow_mm2100',m.max_flow_mm2100??0);   // Bagian G: Maximum Flow MM2100 (BBTUD per row, 0 = unlimited)
  ta('ta-ie_adjustment',m.ie_adjustment);                 // hidden pass-through (structure preserved)
  renderSchedules();                                      // Unit Stop / Fix Load / Skip Load tables
  renderStgStartup();                                     // STG Start Up Mode table
  setGasAction(m.gas_shortage_action||'none'); refreshGasDecision();
  buildIED();
}

function buildIED(){
  const d1=INPUT.data1||[], d2=INPUT.data2||[];
  let h='<tr><th class="t">#</th><th class="t">Time</th><th>IE (MW)</th><th>Dispatch (MW)</th></tr>';
  for(let i=0;i<48;i++){const t=(d1[i]&&d1[i].time)||(d2[i]&&d2[i].time)||'';
    h+=`<tr><td class="t">${i+1}</td><td class="t">${t}</td>
      <td><input type="number" step="0.1" style="width:96px" data-ie="${i}" value="${(d1[i]&&d1[i].value)??0}"></td>
      <td><input type="number" step="0.1" style="width:96px" data-disp="${i}" value="${(d2[i]&&d2[i].dispatch)??0}"></td></tr>`;}
  $('tbl-ied').innerHTML=h;
  updateIEDsum();
  $('tbl-ied').querySelectorAll('input').forEach(inp=>inp.addEventListener('input',updateIEDsum));
}
function updateIEDsum(){let ie=0,dp=0;
  document.querySelectorAll('[data-ie]').forEach(e=>ie+=num(e.value));
  document.querySelectorAll('[data-disp]').forEach(e=>dp+=num(e.value));
  $('ied-sum').textContent=`ΣIE ${fmt(ie/2,0)} MWh · ΣDispatch ${fmt(dp/2,0)} MWh`;}

/* ===================== assemble input from form ===================== */
function jsonField(id,fallback){try{const v=JSON.parse($(id).value);return v;}catch(e){toast('Invalid JSON in '+id.replace('ta-',''));throw e;}}
function assembleInput(){
  const out=JSON.parse(JSON.stringify(INPUT));
  // data3 base from advanced JSON, then overlay limit-table inputs
  let units;try{units=JSON.parse($('ta-data3').value);}catch(e){toast('Invalid JSON in unit coefficients');throw e;}
  Object.keys(units).forEach(k=>{out.data3[k]=units[k];});
  // structured fuel/STG coefficients take precedence over the advanced JSON
  [...GTG_UNITS,...GE_UNITS,...STG_UNITS].forEach(u=>{ if(INPUT.data3[u]) out.data3[u]=JSON.parse(JSON.stringify(INPUT.data3[u])); });
  document.querySelectorAll('#tbl-units [data-u]').forEach(el=>{
    const u=el.dataset.u,k=el.dataset.k; if(!out.data3[u])out.data3[u]={};
    out.data3[u][k]= el.type==='checkbox' ? (el.checked?1:0) : num(el.value);});
  const m=out.data3.modeling;
  { const pd=$('f-plan_date'); if(pd&&pd.value) m.plan_date=pd.value; }
  { const rk=$('f-plan_remark'); m.plan_remark=rk?rk.value:''; }
  { const nm=computeNamePlan(m.plan_date||'', m.plan_remark||''); m.plan_name=nm; m.name_plan=nm; m.note=nm; }
  m.house_load=num($('f-house_load').value); m.spinning_reserve_min=num($('f-spinning_reserve_min').value);
  const ddOn=$('f-pln-dd-req').checked;
  const ddRules = ddOn ? DD_RULES.map(r=>({min:num(r.min),max:num(r.max),start:+r.start,stop:+r.stop,required:true})) : [];
  m.pln_export_priority={
    range:{min:num($('f-pln-range-min').value),max:num($('f-pln-range-max').value),required:true},
    daily_target:{value:num($('f-pln-dt-val').value),required:$('f-pln-dt-req').checked},
    dispatch_dev_rules: ddRules,
    range_rules: RANGE_RULES.map(r=>({min:num(r.min),max:num(r.max),start:+r.start,stop:+r.stop})),
    dispatch_dev_mode: ($('f-pln-dd-mode')?$('f-pln-dd-mode').value:'same_as_quota'),
    // legacy single-rule mirror for backward compatibility (first rule)
    dispatch_dev: ddRules.length ? ddRules[0] : {min:0,max:0,start:1,stop:1,required:false}
  };
  m.gas_quota={}; QUOTA_KEYS.forEach(k=>m.gas_quota[k]=num($('q-'+k).value));
  m.mm2100_gas_mode={}; ['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'].forEach(k=>{const s=$('mode-'+k); if(s)m.mm2100_gas_mode[k]=s.value;});
  m.price={};     PRICE_KEYS.forEach(k=>m.price[k]=num($('p-'+k).value));
  /* GHG factors: blank/non-finite returns documented defaults; zero is a valid input. */
  const efNum=(id,def,max=null)=>{const el=$(id),raw=el?String(el.value).trim():'';let v=raw===''?def:Number(raw);if(!isFinite(v))v=def;v=Math.max(0,v);if(max!==null)v=Math.min(max,v);return v;};
  m.emission_factor={
    gas_tco2_per_bbtud:efNum('em-gas_tco2_per_bbtud',53.7),
    distillate_tco2_per_liter:efNum('em-distillate_tco2_per_liter',0.00233),
    coal_carbon_pct:efNum('em-coal_carbon_pct',43,100)
  };
  /* Legacy mirror retained for old reports; the GHG formula reads emission_factor. */
  m.carbon_pct=m.emission_factor.coal_carbon_pct;
  /* PROMPT B2/B7: %RE Babelan removed from UI — legacy m.re_babelan_pct is kept as-is in the model
   * for backward compatibility but is never read again by the new Biomass calculation. */
  m.pks_pct=num($('b-pks_pct').value);
  m.biomass_MWh_target=num($('b-biomass_MWh_target').value); m.babelan_CF_target=num($('b-babelan_CF_target').value);
  m.babelan_biomass={
    biomass_target_mwh:num($('b-biomass_MWh_target').value),
    heatrate_babelan_kcal_kwh:num($('b-hr_babelan').value),
    heating_value_pks_kcal_kg:num($('b-hv_pks').value),
    heating_value_woodchip_kcal_kg:num($('b-hv_woodchip').value)
  };
  m.ghv_jababeka=num($('f-ghv_jababeka').value); m.ghv_mm2100=num($('f-ghv_mm2100').value);
  m.min_pgn_flow=num($('f-min_pgn_flow').value); m.ghv_pgn=num(($('f-ghv_pgn')||{}).value); m.busflow_min=num($('f-busflow_min').value);
  m.gas_shortage_action=$('f-gas_action').value; m.additional_lng=num($('f-additional_lng').value);
  { const mf=$('f-max_flow_mm2100'); m.max_flow_mm2100=mf?num(mf.value):(m.max_flow_mm2100||0); }   // Bagian G
  // bus
  m.bus_unit=m.bus_unit||{};
  document.querySelectorAll('[data-bus]').forEach(el=>{m.bus_unit[el.dataset.bus+'_bus']=el.value;});
  // Unit Last Data Status (Revisi UI)
  m.unit_last_data_status=collectUnitLastStatus();
  m.max_load_rules=MAXLOAD_RULES;   // time-based Maximum Load Adjustment per unit
  // JSON arrays
  // priorities from the structured editors (Revisi Lanjutan Sec.3)
  // Reconcile Block Priority Required -> commitment ONE more time before collecting, so the model sent to
  // the backend is guaranteed to match the Block Priority table (belt-and-suspenders on top of the live
  // event handlers). silent = never pop a confirm during save.
  if(typeof syncBlockRequiredToCommitment==='function') syncBlockRequiredToCommitment({silent:true});
  const P=assemblePriorities();
  m.block_priority=P.block_priority; m.unit_priority=P.unit_priority; m.unit_priority_dist=P.unit_priority_dist;
  m.required_units=REQUIRED_ORDER.slice(); m.unit_cannot_stop=CANNOT_STOP.slice();
  m.required_mode=(typeof buildRequiredModeObj==='function')?buildRequiredModeObj():(m.required_mode||{});
  m.stop_mode=(typeof buildStopModeObj==='function')?buildStopModeObj():(m.stop_mode||{});
  m.change_over=(typeof buildChangeOverObj==='function')?buildChangeOverObj():(m.change_over||{enabled:false});
  m.plan_type=(typeof dpMon==='function'&&dpMon())?'monitoring':'plan';   // PROMPT ISOLATION §3.2/§5: metadata dikirim ke backend di setiap payload
  mdpApplyActualData(m);   // PROMPT MONITORING §3.4/§8.2: locked rows -> actual_data (+ leak guard antar-cabang, CSV asli menang)
  m.manual_fixed_flows=(FF_ROWS||[]).map(e=>({area:e.area,row:+e.row,value_mmscfd:num(e.value_mmscfd)}));
  assembleSchedules(m);                                   // unit_stop / unit_stop_time / hrsg_stop(_time) / unit_fix_load / unit_skip_load
  assembleStgStartup(m);                                  // stg_startup_mode
  assembleRuntime(m);                                     // runtime_downtime
  m.ie_adjustment=jsonField('ta-ie_adjustment');          // hidden pass-through (structure preserved)
  assembleIeAdj(m);                                        // Tahap 4: structured IE Adjustment table -> ie_adjustments
  // IE & dispatch
  out.data1=out.data1||[]; out.data2=out.data2||[];
  document.querySelectorAll('[data-ie]').forEach(el=>{const i=+el.dataset.ie; out.data1[i]=out.data1[i]||{time:''}; out.data1[i].value=num(el.value);});
  document.querySelectorAll('[data-disp]').forEach(el=>{const i=+el.dataset.disp; out.data2[i]=out.data2[i]||{time:(out.data1[i]?.time||'')}; out.data2[i].dispatch=num(el.value);});
  // per-row manual actual gas (Revisi Sec.5): blank stays '' so backend uses estimation
  /* PROMPT ACTUAL GAS 1H §6: input actual = PER 1 JAM (satu field per pasangan 2 row).
     Kanonik model: nilai jam di index GENAP pasangan, index ganjil '' — engine menghitung 1x per jam. */
  const aP=[],aJ=[],aM=[];
  document.querySelectorAll('#tbl-result .actin').forEach(el=>{
    const ri=+el.dataset.row,key=el.dataset.act,val=el.value===''?'':num(el.value);
    const T=key==='pgn'?aP:(key==='ffj'?aJ:aM);
    T[ri]=val; T[ri+1]='';});
  if(aP.length){for(let i=0;i<SLOTS;i++)if(aP[i]===undefined)aP[i]='';m.actual_pgn_total=aP;}
  if(aJ.length){for(let i=0;i<SLOTS;i++)if(aJ[i]===undefined)aJ[i]='';m.actual_energy_jababeka=aJ;}
  if(aM.length){for(let i=0;i<SLOTS;i++)if(aM[i]===undefined)aM[i]='';m.actual_energy_mm2100=aM;}
  return out;
}

/* ===================== run ===================== */
/* ===================== Gas KP72 validation (Revisi) =====================
 * If the G10 block or the Gas-Engine block is set Required in Block Priority,
 * the KP72 gas inputs must be filled. If all four are empty/0, block the Run,
 * show the popup, jump to Frequently Input -> Gas Data, and highlight the inputs. */
const KP72_KEYS=['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'];
function blockRequired(bp,units){
  return (bp||[]).some(blk=>Array.isArray(blk)&&blk.includes('required')&&blk.some(u=>units.includes(u)));
}
function focusKp72(){
  const ids=KP72_KEYS.map(k=>'q-'+k);
  let first=null;
  ids.forEach(id=>{const el=$(id); if(el){el.classList.add('kp72hl'); if(!first)first=el;}});
  if(first){ first.scrollIntoView({behavior:'smooth',block:'center'}); setTimeout(()=>first.focus(),250); }
  setTimeout(()=>ids.forEach(id=>{const el=$(id); if(el)el.classList.remove('kp72hl');}),6000);
}
function validateGasKP72BeforeRun(payload){
  const m=(payload&&payload.data3&&payload.data3.modeling)||{};
  const bp=m.block_priority||[], q=m.gas_quota||{};
  const need = blockRequired(bp,['g10']) || blockRequired(bp,['ge1','ge2','ge3','ge4']);
  if(!need) return true;
  const allEmpty = KP72_KEYS.every(k=>!(+q[k]>0));
  if(!allEmpty) return true;
  // fail: required but KP72 area empty
  const banner=$('kp72-warn'); if(banner) banner.style.display='';
  alert('GAS KP72 BELUM TERISI');
  activateSub('frequent'); activateChild('frequent','cp-gas');
  focusKp72();
  return false;
}

/* PROMPT UI-TRIGGER §3 (run coordination): revision counter agar hanya response dari
 * request TERBARU yang boleh memperbarui UI. Plan & Monitoring memakai token TERPISAH
 * (di-key per plan_type) sehingga run di satu cabang tidak menandai-stale run cabang lain.
 * Response yang tiba terlambat (stale) di-abaikan: tidak me-render, tidak menimpa OUTPUT. */
let RUN_SEQ={plan:0,monitoring:0};
function runBranchKey(){ return (typeof dpMon==='function'&&dpMon())?'monitoring':'plan'; }
/* PROMPT LIVE-STATE §7: flush edit yang masih fokus/pending SEBELUM collect, agar nilai yang
 * sedang diketik (belum blur) ikut terbaca. Commit input aktif via blur+change; debounce di-flush. */
function flushPendingEdits(){
  try{
    const ae=document.activeElement;
    if(ae && (ae.tagName==='INPUT'||ae.tagName==='TEXTAREA'||ae.tagName==='SELECT')){
      ae.dispatchEvent(new Event('input',{bubbles:true}));
      ae.dispatchEvent(new Event('change',{bubbles:true}));
      ae.dispatchEvent(new Event('blur',{bubbles:true}));
    }
    if(typeof RANGE_RULES!=='undefined' && typeof renderRangeRules==='function'){/* rules sudah live di array */}
  }catch(e){}
}
/* ============================================================================================
 * GAS SHORTAGE FUEL DECISION — popup keputusan operator + rerun tervalidasi.
 *
 * ROOT CAUSE yang ditutup: backend sudah mampu menjalankan simulasi dengan LNG tambahan
 * (gas_shortage_action=add_lng + additional_lng) atau distillate (use_distillate), dan sudah
 * menghitung estimasi keduanya — tetapi tidak ada satu pun jalur UI yang menawarkan keputusan
 * itu kepada operator saat simulasi gagal. Yang terlihat hanya kalimat rekomendasi statis.
 * Blok ini menambahkan titik keputusan tersebut, dengan rerun dari INPUT BERSIH (bukan state
 * parsial), guard anti double-click/stale response, dan batas ronde agar tidak berputar.
 *
 * SATU SUMBER KONVERSI: seluruh angka liter diambil dari backend
 * (info['Required/Recommended Distillate (l/day)'] dan info['Distillate Conversion Basis']).
 * UI TIDAK LAGI menghitung ulang dengan konstanta sendiri.
 * ============================================================================================ */
let GSF_ROUND = 0;                       // ronde keputusan pada satu sesi Run
const GSF_MAX_ROUNDS = 3;                // §9.8: jangan berputar tanpa batas
let GSF_BASE_PAYLOAD = null;             // input BERSIH untuk rerun (bukan hasil parsial)
let GSF_HISTORY = [];                    // jejak pilihan ronde sebelumnya

/* Faktor konversi liter: SELALU dari backend. Bila belum ada, kembalikan null supaya UI
 * menyatakan "estimasi tidak tersedia" alih-alih menampilkan angka karangan. */
function gsfConversion(info){
  const c = info && info['Distillate Conversion Basis'];
  if (c && isFinite(+c.btu_per_litre) && +c.btu_per_litre > 0) return c;
  return null;
}
function gsfLitresFromBbtu(bbtu, info){
  const c = gsfConversion(info);
  if (!c || !isFinite(+bbtu) || +bbtu <= 0) return null;
  return (+bbtu * 1e9) / (+c.btu_per_litre);
}
function gsfEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
function gsfN(v,d){ const x=Number(v); return isFinite(x)? x.toFixed(d==null?2:d) : '—'; }

/* Validasi input manual — menolak negatif, bukan angka, NaN, INF, string malformed, dan nilai
 * di atas batas fisik. Mengembalikan {ok, value, msg}. */
function gsfValidateAmount(raw, max, unit){
  const s = String(raw==null?'':raw).trim();
  if (s === '') return {ok:false, msg:'Jumlah wajib diisi ('+unit+').'};
  if (!/^[0-9]+([.,][0-9]+)?$/.test(s)) return {ok:false, msg:'Nilai harus angka desimal tanpa huruf atau simbol. Nilai negatif, NaN, dan INF ditolak.'};
  const v = Number(s.replace(',','.'));
  if (!isFinite(v)) return {ok:false, msg:'Nilai bukan bilangan berhingga (NaN/INF ditolak).'};
  if (v < 0) return {ok:false, msg:'Nilai tidak boleh negatif.'};
  if (v === 0) return {ok:false, msg:'Nilai 0 tidak menyelesaikan shortage — pilih Batal bila tidak ingin memakai bahan bakar tambahan.'};
  if (isFinite(+max) && +max > 0 && v > +max) return {ok:false, msg:'Nilai '+v+' '+unit+' melampaui batas fisik '+max+' '+unit+' — ditolak.'};
  return {ok:true, value:v};
}

function gsfClose(){ const m=document.getElementById('gsf-mask'); if(m) m.remove();
  document.body.style.overflow='';
  try{ document.removeEventListener('keydown', gsfEscHandler); }catch(e){}
  if(typeof gsfStopPolling==='function') gsfStopPolling(); }

/* Popup keputusan. `dec` = blok shortage_decision dari backend; `info` = OUTPUT.info run terakhir. */
/* =============================================================================================
 *  POPUP KEPUTUSAN BAHAN BAKAR — TAMPILAN SEDERHANA (instruksi operator §2/§3/§4)
 *
 *  AKAR PENYEBAB YANG DITUTUP DI SINI (lihat ROOT_CAUSE_TOMBOL_DISABLED.md):
 *   1. GERBANG SALAH TEMPAT. Versi lama menghitung
 *        blockFuel = overRound || (!isUnder && dec.final_values_publishable !== true)
 *      lalu meneruskannya ke SEMUA tombol, termasuk Batal dan input manual. Karena hasil awal
 *      pada mesin operator berakhir "BELUM KONVERGEN (terpotong batas waktu)",
 *      final_values_publishable = false dan KELIMA tombol menerima atribut disabled.
 *      Itu adalah release gate yang mengunci tindakan KOREKSI — padahal release gate hanya boleh
 *      memblokir Publish. Memilih bahan bakar justru cara memperoleh hasil yang valid.
 *   2. POPUP LEBIH TINGGI DARIPADA VIEWPORT. Tabel audit 15 baris + empat banner membuat box
 *      melampaui tinggi layar; mask memusatkan box tanpa scroll sehingga deretan tombol di
 *      bagian bawah keluar layar dan tidak dapat dijangkau sama sekali (body juga sudah dikunci
 *      overflow:hidden). Diperbaiki di CSS .sv-mask/.sv-box dan dengan memindahkan seluruh audit
 *      ke panel "Lihat Detail Teknis" yang tertutup secara default.
 *
 *  ATURAN TOMBOL SEKARANG:
 *   - Batal SELALU aktif.
 *   - Input Manual SELALU aktif selama basis konversi tersedia.
 *   - Gunakan LNG / Gunakan Distillate aktif HANYA setelah backend mengembalikan rekomendasi
 *     yang sudah DI-RERUN dan terbukti valid (VALIDATED_OPTIONS_READY). Selama validasi berjalan
 *     popup menampilkan progress, bukan tombol mati tanpa keterangan, dan tombol diaktifkan
 *     OTOMATIS ketika hasil tervalidasi tiba.
 * =========================================================================================== */
/* Niat operator yang dipilih di menu tetapi belum bernilai. Dipakai untuk menyorot opsi yang
   sama di popup setelah angka tervalidasi tersedia. Tidak pernah dipakai untuk auto-apply. */
let GSD_PENDING_CHOICE = null;
let GSF_VO = null;          // hasil job validated_options terakhir
let GSF_VO_TIMER = null;    // handle polling
let GSF_VO_JOB = null;      // {job_id, input_hash}
let GSF_VO_NORES = 0;       // jumlah polling DONE tanpa hasil terbaca

function gsfStopPolling(){ if(GSF_VO_TIMER){ clearInterval(GSF_VO_TIMER); GSF_VO_TIMER=null; } }

function gsfFmtLng(v){ return (v==null||!isFinite(+v)) ? '—' : (+v).toFixed(4).replace('.', ','); }
function gsfFmtLitre(v){ return (v==null||!isFinite(+v)) ? '—' : (+v).toLocaleString('id-ID',{maximumFractionDigits:0}); }

/* Popup keputusan. `dec` = blok shortage_decision dari backend; `info` = OUTPUT.info run terakhir;
 * `vo` = blok validated_options_job dari backend (boleh null). */
function gsfOpen(dec, info, msgText, vo){
  /* Popup keputusan menggantikan modal progres sebagai lapisan pemblokir — tidak pernah
   * ada dua lapisan sekaligus, dan tidak pernah ada jeda tanpa lapisan sama sekali. */
  if(typeof ppmClose==='function') ppmClose();
  gsfClose();
  gsfStopPolling();
  GSF_VO = (vo && vo.result) ? vo.result : null;
  GSF_VO_JOB = (vo && vo.job_id) ? {job_id:vo.job_id, input_hash:vo.input_hash||''} : null;
  /* Job opsi tervalidasi juga dijalankan lewat request HTTP, bukan proses OS. Tanpa pemicu ini
   * job-nya akan menunggu selamanya dan kedua tombol bahan bakar tidak pernah aktif. */
  if(vo && vo.job_id && vo.exec_token && !(vo.result)){
    fetch('run.php?mode=job_exec&job='+encodeURIComponent(vo.job_id)
      +'&token='+encodeURIComponent(vo.exec_token),{cache:'no-store'}).catch(()=>{});
  }
  const gt=dec.gas_target||{}, ex=dec.export||{}, hr=dec.headroom||{}, op=dec.options||{};
  const lng=op.lng||{}, dist=op.distillate||{};
  const conv=gsfConversion(info);
  const isUnder = (gt.direction==='UNDER_TARGET');

  const mask=document.createElement('div'); mask.className='sv-mask'; mask.id='gsf-mask';
  const box=document.createElement('div'); box.className='sv-box'; box.id='gsf-box'; box.style.maxWidth='560px';
  box.setAttribute('role','dialog'); box.setAttribute('aria-modal','true');
  box.setAttribute('aria-label', isUnder ? 'Konsumsi gas di bawah target' : 'Gas Shortage — keputusan bahan bakar');

  /* ---------- TAMPILAN UTAMA: singkat, hanya judul, dua angka, dan tombol ------------------- */
  const h=document.createElement('h4'); h.id='gsf-title';
  h.textContent = isUnder ? 'Konsumsi gas di bawah target' : 'Gas Shortage';
  box.appendChild(h);
  const p=document.createElement('p'); p.className='sv-note'; p.id='gsf-subtitle';
  p.textContent = isUnder
    ? 'Konsumsi gas berada di bawah batas bawah window kontrak. Ini bukan Gas Shortage: pasokan gas cukup, penyerapannya yang kurang. LNG dan distillate tidak ditawarkan.'
    : 'Kebutuhan gas masih melebihi kuota setelah koreksi dispatch.';
  box.appendChild(p);

  /* Kotak angka rekomendasi — isinya diperbarui gsfRenderOptions() sesuai status validasi. */
  const numbers=document.createElement('div'); numbers.id='gsf-numbers';
  numbers.style.cssText='border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-bottom:12px;background:#f8fafc';
  box.appendChild(numbers);

  /* ---------- TOMBOL AKSI ------------------------------------------------------------------ */
  const actions=document.createElement('div'); actions.id='gsf-actions'; actions.style.cssText='margin-bottom:10px';
  const mkBtn=(id,label,cls)=>{
    const b=document.createElement('button'); b.type='button'; b.id=id; b.className='sv-opt'+(cls?(' '+cls):'');
    b.textContent=label; b.style.marginBottom='8px'; return b;
  };
  const bLng =mkBtn('gsf-lng','Gunakan LNG');
  const bDist=mkBtn('gsf-dist','Gunakan Distillate');
  const bMan =mkBtn('gsf-manual','Input Manual');
  const bCancel=mkBtn('gsf-cancel','Batal');
  if(!isUnder){ actions.appendChild(bLng); actions.appendChild(bDist); }
  actions.appendChild(bMan); actions.appendChild(bCancel);
  box.appendChild(actions);

  /* Panel input manual — tertutup sampai operator menekan Input Manual, tetapi TOMBOLNYA
   * tidak pernah dikunci oleh status validasi otomatis (§6). */
  const manual=document.createElement('div'); manual.id='gsf-manual-panel'; manual.hidden=true;
  manual.style.cssText='border:1px solid #cbd5e1;border-radius:10px;padding:12px 14px;margin-bottom:10px;background:#fff';
  box.appendChild(manual);

  const err=document.createElement('div'); err.id='gsf-err';
  err.style.cssText='color:#b91c1c;font-size:12.5px;margin:6px 0 10px;min-height:16px'; box.appendChild(err);

  /* ---------- DETAIL TEKNIS: tertutup secara default ---------------------------------------- */
  const dBtn=document.createElement('button'); dBtn.type='button'; dBtn.id='gsf-detail-toggle';
  dBtn.className='sv-opt'; dBtn.textContent='Lihat Detail Teknis';
  dBtn.setAttribute('aria-expanded','false'); dBtn.setAttribute('aria-controls','gsf-detail-panel');
  box.appendChild(dBtn);
  const detail=document.createElement('div'); detail.id='gsf-detail-panel'; detail.hidden=true;
  detail.style.cssText='border:1px solid #e2e8f0;border-radius:10px;padding:12px 14px;margin-top:8px;background:#f8fafc';
  box.appendChild(detail);

  mask.appendChild(box); document.body.appendChild(mask);
  /* body TIDAK dikunci overflow:hidden lagi — penguncian itu ikut membuat tombol yang berada di
   * luar viewport tak terjangkau ketika box lebih tinggi daripada layar. */

  /* ---------- perilaku --------------------------------------------------------------------- */
  const detailHtml=()=>gsfDetailHtml(dec, info, conv, GSF_VO);
  dBtn.addEventListener('click', ()=>{
    const open = detail.hidden;
    if(open && !detail.dataset.built){ detail.innerHTML=detailHtml(); detail.dataset.built='1'; }
    detail.hidden = !open;
    dBtn.setAttribute('aria-expanded', open?'true':'false');
    dBtn.textContent = open ? 'Sembunyikan Detail Teknis' : 'Lihat Detail Teknis';
  });

  const doCancel=()=>{ gsfStopPolling(); gsfClose(); const rm=document.getElementById('run-msg');
    if(rm) rm.innerHTML='<span style="color:#b45309">Dibatalkan. Input dan hasil sebelumnya tidak diubah.</span>'; };
  bCancel.addEventListener('click', doCancel);
  mask.addEventListener('click', e=>{ if(e.target===mask) doCancel(); });
  document.addEventListener('keydown', gsfEscHandler);

  bMan.addEventListener('click', ()=>{
    const open = manual.hidden;
    if(open && !manual.dataset.built){ gsfBuildManual(manual, lng, dist, conv); manual.dataset.built='1'; }
    manual.hidden = !open;
    bMan.textContent = open ? 'Tutup Input Manual' : 'Input Manual';
    if(open){ const f=manual.querySelector('input'); if(f) f.focus(); }
  });

  /* Guard anti double-click: klik kedua pada tombol yang sama tidak melahirkan request kedua. */
  const once=(btn, fn)=>btn.addEventListener('click', ()=>{
    if(btn.disabled || btn.dataset.busy==='1') return;
    btn.dataset.busy='1';
    try{ fn(); } finally { setTimeout(()=>{ btn.dataset.busy=''; }, 1500); }
  });
  once(bLng, ()=>{
    const amt = gsfValidatedLng();
    if(amt==null){ err.textContent='Jumlah LNG tervalidasi belum tersedia.'; bLng.dataset.busy=''; return; }
    gsfRerun('add_lng', amt, 'BBTUD');
  });
  once(bDist, ()=>{
    const amt = gsfValidatedDist();
    if(amt==null){ err.textContent='Jumlah distillate tervalidasi belum tersedia.'; bDist.dataset.busy=''; return; }
    gsfRerun('use_distillate', amt, 'l/hari');
  });

  /* Tanpa job validasi sama sekali, popup TIDAK boleh menggantung pada status "menghitung":
   * ia menyatakan dengan jujur bahwa rekomendasi otomatis tidak tersedia, dan Input Manual serta
   * Batal tetap dapat dipakai. */
  if(!isUnder && !GSF_VO && !GSF_VO_JOB && !(vo && vo.action_status==='CALCULATING_VALIDATED_OPTIONS')){
    GSF_VO={action_status:'VALIDATION_FAILED',
      lng:{validated:false,reason:'Validasi otomatis tidak dijalankan untuk request ini — gunakan Input Manual.'},
      distillate:{validated:false,reason:'Validasi otomatis tidak dijalankan untuk request ini — gunakan Input Manual.'}};
  }
  gsfRenderOptions(vo || null, isUnder);
  if(!isUnder && GSF_VO_JOB && !GSF_VO) gsfStartPolling();
  bCancel.focus();
}
function gsfEscHandler(e){
  if(e.key!=='Escape') return;
  const b=document.getElementById('gsf-cancel'); if(b) b.click();
}
function gsfValidatedLng(){
  const o=GSF_VO&&GSF_VO.lng; if(!o||!o.validated) return null;
  const v=+o.validated_amount_bbtud; return isFinite(v)&&v>0?v:null;
}
function gsfValidatedDist(){
  const o=GSF_VO&&GSF_VO.distillate; if(!o||!o.validated) return null;
  const v=+o.validated_amount_liter; return isFinite(v)&&v>0?v:null;
}

/* Menyusun kotak angka + status tombol dari status validasi backend. Dipanggil ulang tiap kali
 * polling membawa status baru, sehingga tombol AKTIF OTOMATIS begitu hasil tervalidasi tiba. */
function gsfRenderOptions(vo, isUnder){
  const box=document.getElementById('gsf-numbers'); if(!box) return;
  const bLng=document.getElementById('gsf-lng'), bDist=document.getElementById('gsf-dist');
  const setBtn=(b, on, label, state)=>{
    if(!b) return;
    b.disabled = !on;
    b.dataset.state = state;
    b.textContent = label;
    b.style.opacity = on ? '' : '.55';
    b.style.cursor  = on ? 'pointer' : 'not-allowed';
    b.setAttribute('aria-disabled', on?'false':'true');
  };
  if(isUnder){
    box.innerHTML='<div style="font-size:12.5px;color:#475569">Tidak ada opsi bahan bakar untuk kondisi di bawah target. '
      +'Tindakan yang relevan ada pada Detail Teknis.</div>';
    box.dataset.status='NOT_APPLICABLE_UNDER_TARGET';
    return;
  }
  const st = GSF_VO ? String(GSF_VO.action_status||'VALIDATION_FAILED')
           : String((vo&&vo.action_status)||'CALCULATING_VALIDATED_OPTIONS');
  box.dataset.status = st;
  if(st==='CALCULATING_VALIDATED_OPTIONS'){
    box.innerHTML='<div id="gsf-progress" style="font-size:13px;color:#0f172a">'
      +'<span class="spin"></span> <b>Menghitung opsi yang tervalidasi…</b>'
      +'<div id="gsf-progress-step" style="font-size:12px;color:#475569;margin-top:6px">Menjalankan rerun kandidat dan memeriksa seluruh constraint.</div>'
      +'<div style="height:6px;border-radius:4px;background:#e2e8f0;margin-top:8px;overflow:hidden">'
      +'<div id="gsf-progress-bar" style="height:6px;width:2%;background:#4f46e5;transition:width .4s"></div></div>'
      +'<div style="font-size:11.5px;color:#64748b;margin-top:6px">Tombol LNG dan Distillate aktif otomatis setelah angka terbukti valid. '
      +'Input Manual dan Batal dapat dipakai sekarang juga.</div></div>';
    setBtn(bLng, false, 'Gunakan LNG — menghitung…', 'CALCULATING');
    setBtn(bDist,false, 'Gunakan Distillate — menghitung…', 'CALCULATING');
    return;
  }
  const L=(GSF_VO&&GSF_VO.lng)||{}, D=(GSF_VO&&GSF_VO.distillate)||{};
  const lngOk=!!L.validated, disOk=!!D.validated;
  let html='';
  html += '<div id="gsf-lng-line" style="font-size:14px;margin-bottom:6px">Tambah LNG: <b>'
       + (lngOk ? (gsfFmtLng(L.validated_amount_bbtud)+' BBTUD') : 'tidak tersedia')
       + '</b>' + (lngOk?'':' <span style="font-size:12px;color:#b45309">— lihat alasan</span>') + '</div>';
  html += '<div id="gsf-dist-line" style="font-size:14px">Gunakan Distillate: <b>'
       + (disOk ? (gsfFmtLitre(D.validated_amount_liter)+' liter') : 'tidak tersedia')
       + '</b>' + (disOk?'':' <span style="font-size:12px;color:#b45309">— lihat alasan</span>') + '</div>';
  if(lngOk||disOk)
    html += '<div style="font-size:11.5px;color:#166534;margin-top:8px">Angka di atas sudah di-rerun dari input bersih dan terbukti '
         +  'konvergen, seluruh hard constraint valid, dan economic review selesai.</div>';
  if(!lngOk&&L.reason) html+='<div style="font-size:11.5px;color:#7f1d1d;margin-top:8px">'+gsfEsc(L.reason)+'</div>';
  if(!disOk&&D.reason) html+='<div style="font-size:11.5px;color:#7f1d1d;margin-top:4px">'+gsfEsc(D.reason)+'</div>';
  box.innerHTML=html;
  setBtn(bLng, lngOk, lngOk?('Gunakan LNG '+gsfFmtLng(L.validated_amount_bbtud)+' BBTUD'):'Gunakan LNG — tidak tersedia',
         lngOk?'READY':'UNAVAILABLE');
  setBtn(bDist, disOk, disOk?('Gunakan Distillate '+gsfFmtLitre(D.validated_amount_liter)+' liter'):'Gunakan Distillate — tidak tersedia',
         disOk?'READY':'UNAVAILABLE');
}

/* Polling job validated_options. Berhenti sendiri ketika job selesai, gagal, dibatalkan, atau
 * popup ditutup. input_hash dikirim serta supaya hasil job milik input LAIN tidak pernah dipakai. */
function gsfStartPolling(){
  gsfStopPolling();
  let n=0; GSF_VO_NORES=0;
  GSF_VO_TIMER=setInterval(async ()=>{
    if(!document.getElementById('gsf-mask')){ gsfStopPolling(); return; }
    if(!GSF_VO_JOB){ gsfStopPolling(); return; }
    n++;
    try{
      const u='run.php?mode=job_poll&job='+encodeURIComponent(GSF_VO_JOB.job_id)
             +'&input_hash='+encodeURIComponent(GSF_VO_JOB.input_hash||'');
      const r=await fetch(u,{cache:'no-store'});
      const j=await r.json();
      const job=(j&&j.job)||{};
      /* Validasi Distillate berjalan paralel sebagai job anak: dipicu sekali, begitu terdaftar. */
      if(job.subjob&&job.subjob.job_id&&job.subjob.exec_token&&!PPM_FIRED[job.subjob.job_id]){
        PPM_FIRED[job.subjob.job_id]=1;
        fetch('run.php?mode=job_exec&job='+encodeURIComponent(job.subjob.job_id)
          +'&token='+encodeURIComponent(job.subjob.exec_token),{cache:'no-store'}).catch(()=>{});
      }
      /* DONE tanpa hasil yang terbaca tidak boleh membuat popup menunggu tanpa akhir. */
      if(job.status==='DONE'&&!j.result&&!j.stale){
        GSF_VO_NORES=(GSF_VO_NORES||0)+1;
        if(GSF_VO_NORES>=15){ gsfStopPolling(); GSF_VO={action_status:'VALIDATION_FAILED',
          lng:{validated:false,reason:'Hasil validasi selesai tetapi tidak dapat dibaca server. Klik Run sekali lagi.'},
          distillate:{validated:false,reason:'Hasil validasi selesai tetapi tidak dapat dibaca server. Klik Run sekali lagi.'}};
          gsfRenderOptions(null,false); return; }
      }
      const bar=document.getElementById('gsf-progress-bar');
      const stp=document.getElementById('gsf-progress-step');
      if(bar&&job.percent!=null) bar.style.width=Math.max(2,Math.min(100,+job.percent))+'%';
      if(stp&&job.current_step) stp.textContent=String(job.current_step).replace(/_/g,' ').toLowerCase();
      if(j&&j.stale){ gsfStopPolling(); GSF_VO={action_status:'VALIDATION_FAILED',
        lng:{validated:false,reason:'Hasil job tidak cocok dengan input yang sedang ditampilkan (stale).'},
        distillate:{validated:false,reason:'Hasil job tidak cocok dengan input yang sedang ditampilkan (stale).'}};
        gsfRenderOptions(null,false); return; }
      if(job.status==='DONE'&&j.result){ gsfStopPolling(); GSF_VO=j.result;
        try{ const v=+((j.result.lng||{}).validated_amount_bbtud); if(isFinite(v)&&v>0) GSD_REQ_LNG=v; }catch(e){}
        gsfRenderOptions(null,false);
        const d=document.getElementById('gsf-detail-panel'); if(d&&d.dataset.built){ d.dataset.built=''; d.innerHTML=''; }
        return; }
      if(job.status==='CANCELLED'&&job.fastest_claimed){ gsfStopPolling(); return; }
      if(job.status==='FAILED'||job.status==='CANCELLED'){ gsfStopPolling();
        GSF_VO={action_status:'VALIDATION_FAILED',
          lng:{validated:false,reason:'Validasi gagal: '+((job.error&&job.error.message)||job.status)},
          distillate:{validated:false,reason:'Validasi gagal: '+((job.error&&job.error.message)||job.status)}};
        gsfRenderOptions(null,false); return; }
    }catch(e){ /* jaringan sesaat: polling berikutnya mencoba lagi */ }
    if(n>3600) gsfStopPolling();                      // 60 menit, pengaman
  }, 1000);
}

/* Panel input manual: dua field sederhana, masing-masing dengan tombolnya sendiri (§2/§6). */
function gsfBuildManual(panel, lng, dist, conv){
  const mkRow=(id,label,unit,est,max,dec2)=>{
    const w=document.createElement('div'); w.style.cssText='margin-bottom:10px';
    const l=document.createElement('label'); l.setAttribute('for',id);
    l.style.cssText='display:block;font-size:12.5px;font-weight:600;margin-bottom:4px'; l.textContent=label;
    const i=document.createElement('input'); i.type='text'; i.id=id; i.inputMode='decimal';
    i.style.cssText='width:170px;padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font:inherit';
    i.setAttribute('aria-label',label);
    const g=document.createElement('button'); g.type='button'; g.id=id+'-go'; g.textContent='Jalankan rerun';
    g.style.cssText='margin-left:10px;border:1px solid #4f46e5;background:#4f46e5;color:#fff;border-radius:8px;padding:7px 14px;cursor:pointer;font:600 13px system-ui';
    const hint=document.createElement('div'); hint.id=id+'-hint';
    hint.style.cssText='font-size:11.5px;color:#64748b;margin-top:5px;min-height:14px';
    hint.textContent = (isFinite(+max)&&+max>0) ? ('Batas fisik maksimum '+(+max).toFixed(dec2)+' '+unit+'.') : '';
    w.appendChild(l); w.appendChild(i); w.appendChild(g); w.appendChild(hint);
    return {wrap:w, input:i, go:g, hint:hint};
  };
  const note=document.createElement('div');
  note.style.cssText='font-size:12px;color:#475569;margin-bottom:10px';
  note.textContent='Nilai manual diperlakukan sebagai kandidat: backend menjalankan rerun dari input bersih dan memeriksa seluruh constraint serta economic review sebelum hasilnya boleh dipublikasikan.';
  panel.appendChild(note);
  const rl=mkRow('gsf-lng-amt','LNG manual (BBTUD)','BBTUD', lng.estimated_required, lng.max_physical, 2);
  const rd=mkRow('gsf-dist-amt','Distillate manual (liter)','liter', dist.estimated_required_liters, dist.max_physical_liters, 0);
  panel.appendChild(rl.wrap); panel.appendChild(rd.wrap);
  const err=document.getElementById('gsf-err');
  const bind=(row,kind,unit,max)=>{
    const run=()=>{
      const v=gsfValidateAmount(row.input.value, max, unit);
      if(!v.ok){ if(err) err.textContent=v.msg; row.input.focus(); return; }
      if(err) err.textContent='';
      gsfStopPolling();
      gsfRerun(kind==='lng'?'add_lng':'use_distillate', v.value, unit);
    };
    row.go.addEventListener('click', ()=>{ if(row.go.dataset.busy==='1') return;
      row.go.dataset.busy='1'; try{ run(); } finally { setTimeout(()=>{row.go.dataset.busy='';},1500); } });
    row.input.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); row.go.click(); } });
  };
  bind(rl,'lng','BBTUD', lng.max_physical);
  bind(rd,'dist','l/hari', dist.max_physical_liters);
}

/* Seluruh audit teknis — hanya dibangun ketika panel dibuka, sehingga tampilan utama tetap pendek
 * dan biaya render tidak dibayar bila operator tidak membukanya. */
function gsfDetailHtml(dec, info, conv, vo){
  const gt=dec.gas_target||{}, ex=dec.export||{}, hr=dec.headroom||{}, op=dec.options||{};
  const lng=op.lng||{}, dist=op.distillate||{};
  const rows=[
    ['Jenis masalah', gsfEsc(dec.problem_label||'-')],
    ['Target gas (window)', gsfN(gt.min,4)+' – '+gsfN(gt.max,4)+' '+(gt.unit||'BBTUD')+' (kuota '+gsfN(gt.quota,4)+')'],
    ['Hasil gas saat ini', gsfN(gt.simulated,4)+' '+(gt.unit||'BBTUD')+(gt.actual!=null?(' (actual '+gsfN(gt.actual,4)+')'):'')],
    ['Shortage / residual', gsfN(gt.residual,4)+' '+(gt.unit||'BBTUD')],
    ['Kebutuhan gas SEBELUM minimisasi Export', (dec.gas_required_before_export_minimization!=null? gsfN(dec.gas_required_before_export_minimization,4)+' BBTUD':'—')],
    ['Kebutuhan gas SESUDAH minimisasi Export', gsfN(dec.gas_required_after_export_minimization,4)+' BBTUD'],
    ['Kuota gas efektif', gsfN(dec.effective_gas_quota,4)+' BBTUD'],
    ['Residual gas shortage FINAL', '<b>'+gsfN(dec.final_gas_shortage,4)+' BBTUD</b>'],
    ['Export PLN', 'terendah '+gsfN(ex.current_min_mw,2)+' MW; di Range Min pada '+(ex.rows_at_minimum||0)+'/'+(ex.rows_total||0)+' slot; sisa penurunan legal '+gsfN(ex.reduction_remaining_mw_slot,2)+' MW-slot'],
    ['Slot Export di Range Min', (dec.export_slots_at_range_min!=null?dec.export_slots_at_range_min:'—')+' dari '+(dec.export_slots_total_relevant||0)+' slot relevan'],
    ['Slot Export terblokir constraint', (dec.export_slots_blocked!=null?dec.export_slots_blocked:'—')],
    ['Slot Export masih dapat diturunkan', (dec.export_slots_still_reducible!=null?dec.export_slots_still_reducible:'—')],
    ['Headroom legal tersisa', 'gas turun '+gsfN(hr.gas_down_mw_slot,1)+' MW-slot; gas naik '+gsfN(hr.gas_up_mw_slot,1)+' MW-slot; non-gas naik '+gsfN(hr.non_gas_up_mw_slot,1)+' MW-slot'],
    ['Batas atas koreksi dispatch', gsfN(hr.gas_reduction_upper_bound_bbtud,4)+' BBTUD dari '+gsfN(gt.residual,4)+' BBTUD yang dibutuhkan'],
    ['Minimisasi Export selesai', (dec.minimization_completed===true?'YA':'BELUM')+' — '+gsfEsc(dec.export_minimization_claim||'-')],
    ['Konvergensi run awal', (dec.converged===true?'CONVERGED':(dec.converged===false?'BELUM KONVERGEN (terpotong batas waktu)':'—'))],
    ['Estimasi awal engine (BELUM tervalidasi)', 'LNG '+gsfN(lng.estimated_required,4)+' BBTUD · Distillate '+(dist.estimated_required_liters!=null?gsfN(dist.estimated_required_liters,1)+' l/hari':'—')],
    ['Perkiraan biaya', 'LNG '+(lng.estimated_cost_usd!=null?('USD '+gsfN(lng.estimated_cost_usd,2)):'—')+' · Distillate '+(dist.estimated_cost_usd!=null?('USD '+gsfN(dist.estimated_cost_usd,2)):'—')],
    ['Basis LNG', gsfEsc(lng.basis||'—')],
    ['Basis distillate', gsfEsc(dist.basis||'—')+(conv?(' — density '+conv.density_kg_per_l+' kg/l, LHV '+conv.lhv_btu_per_lb+' BTU/lb, '+conv.lb_per_kg+' lb/kg → '+Math.round(conv.btu_per_litre)+' BTU/liter'):'')],
  ];
  let html='<table style="width:100%;border-collapse:collapse;font-size:12px">';
  rows.forEach(([k,v])=>{ html+='<tr><td style="padding:4px 8px 4px 0;color:#475569;vertical-align:top;width:44%">'+gsfEsc(k)+'</td><td style="padding:4px 0;color:#0f172a">'+v+'</td></tr>'; });
  html+='</table>';
  const sr=(dec.stop_reason||[]);
  if(sr.length) html+='<div style="margin-top:10px;font-size:12px"><b>Alasan koreksi otomatis berhenti</b><ul style="margin:5px 0 0;padding-left:18px">'
    +sr.map(r=>'<li style="margin-bottom:3px">'+gsfEsc(r)+'</li>').join('')+'</ul></div>';
  const slots=(dec.slots_needing_support||[]).slice(0,8);
  if(slots.length) html+='<div style="margin-top:10px;font-size:12px"><b>Slot dengan lever paling mampet</b><br>'
    +slots.map(s=>'row '+s.row+' ('+gsfEsc(String(s.time).slice(-5))+')').join(', ')+'</div>';
  if(Array.isArray(dec.relevant_actions)&&dec.relevant_actions.length)
    html+='<div style="margin-top:10px;font-size:12px"><b>Tindakan yang relevan</b><ul style="margin:5px 0 0;padding-left:18px">'
      +dec.relevant_actions.map(a=>'<li style="margin-bottom:3px">'+gsfEsc(a)+'</li>').join('')+'</ul></div>';
  /* bukti validasi kandidat: inilah yang membuat sebuah angka boleh disebut "kebutuhan" */
  if(vo){
    html+='<div style="margin-top:12px;font-size:12px"><b>Bukti validasi rekomendasi</b> — status: '+gsfEsc(String(vo.action_status||''))+'</div>';
    ['lng','distillate'].forEach(k=>{
      const o=vo[k]||{}; const cs=(o.candidates||[]);
      html+='<div style="margin-top:6px;font-size:11.5px;color:#475569">'+(k==='lng'?'LNG':'Distillate')+': '
        +(o.validated?('tervalidasi pada '+gsfEsc(String(k==='lng'?o.validated_amount_bbtud:o.validated_amount_liter))):'tidak tervalidasi')
        +' · kandidat diuji: '+cs.length+'</div>';
      if(cs.length){
        html+='<table style="width:100%;border-collapse:collapse;font-size:11px;margin-top:4px"><tr>'
          +['jumlah','valid','pelanggaran','konvergen','economic review','gas','kuota','detik'].map(x=>'<th style="text-align:left;color:#64748b;font-weight:600;padding:2px 6px 2px 0">'+x+'</th>').join('')+'</tr>';
        cs.forEach(c=>{ html+='<tr>'
          +'<td style="padding:2px 6px 2px 0">'+gsfEsc(String(c.amount))+'</td>'
          +'<td style="padding:2px 6px 2px 0;color:'+(c.valid?'#166534':'#b91c1c')+'">'+(c.valid?'YA':'tidak')+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+gsfEsc(String(c.violations))+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+(c.converged?'ya':'tidak')+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+(c.economic_review_completed?'selesai':'belum')+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+gsfEsc(String(c.gas_used_bbtud))+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+gsfEsc(String(c.gas_quota_bbtud))+'</td>'
          +'<td style="padding:2px 6px 2px 0">'+gsfEsc(String(c.wall_s))+'</td></tr>'; });
        html+='</table>';
      }
    });
  }
  const aj=(info&&info['Async Economic Review Job'])||null;
  if(aj) html+='<div style="margin-top:10px;font-size:11.5px;color:#475569"><b>Job economic review asinkron:</b> '
    +gsfEsc(String(aj.job_id||''))+' — '+gsfEsc(String(aj.reason||''))+'</div>';
  return html;
}

/* Mencari jumlah minimum yang TERBUKTI valid lewat endpoint probe. Mahal (beberapa simulasi
 * penuh), karena itu hanya berjalan bila operator menekannya sendiri. */
async function gsfProbe(kind){
  if(!GSF_BASE_PAYLOAD) return;
  const err=document.getElementById('gsf-err');
  const btn=document.getElementById('gsf-probe');
  if(btn){ btn.disabled=true; btn.style.opacity='.6'; }
  if(err) err.innerHTML='<span class="spin"></span> Menjalankan probe… ini memerlukan beberapa menit.';
  try{
    const p=JSON.parse(JSON.stringify(GSF_BASE_PAYLOAD));
    const res=await fetch('run.php?mode=shortage_probe&kind='+encodeURIComponent(kind),
      {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)});
    const j=await res.json();
    if(!err) return;
    if(j && j.validated_by_rerun && j.estimated_minimum_feasible_support!=null){
      const amt=j.estimated_minimum_feasible_support;
      err.innerHTML='<span style="color:#166534">Jumlah minimum yang TERBUKTI valid: <b>'+amt+' '+gsfEsc(j.unit||'')+'</b> '
        +'(raw gas gap '+gsfEsc(String(j.raw_gas_gap))+', estimasi engine '+gsfEsc(String(j.engine_estimate))+'). '
        +'Diuji lewat '+j.probe_count+' simulasi penuh.</span>';
      const inp=document.getElementById('gsf-amt'); if(inp) inp.value=String(amt);
    } else {
      err.innerHTML='<span style="color:#b45309">Tidak ditemukan jumlah yang terbukti valid dalam batas probe ('
        +gsfEsc(String(j&&j.validation_status||'-'))+'). Jangan memakai angka estimasi mentah sebagai jaminan.</span>';
    }
  }catch(e){ if(err) err.textContent='Probe gagal: '+e.message; }
  finally{ if(btn){ btn.disabled=false; btn.style.opacity=''; } }
}

/* Rerun dari INPUT BERSIH + pilihan operator. Tidak pernah melanjutkan state parsial. */
async function gsfRerun(action, amount, unit){
  if(!GSF_BASE_PAYLOAD){ const e=document.getElementById('gsf-err'); if(e) e.textContent='Input bersih untuk rerun tidak tersedia — jalankan Run ulang.'; return; }
  if(GSF_ROUND>=GSF_MAX_ROUNDS){ const e=document.getElementById('gsf-err'); if(e) e.textContent='Batas ronde keputusan tercapai.'; return; }
  GSF_ROUND++;
  GSF_HISTORY.push(action==='add_lng' ? ('LNG '+amount+' BBTUD') : ('Distillate'+(amount!=null?(' '+amount+' l'):' (estimasi engine)')));
  /* deep copy: form operator TIDAK diubah oleh popup; pilihan baru menjadi milik payload rerun */
  const p=JSON.parse(JSON.stringify(GSF_BASE_PAYLOAD));
  p.data3.modeling.gas_shortage_action=action;
  if(action==='add_lng'){
    p.data3.modeling.additional_lng=+amount;
    delete p.data3.modeling.distillate_user_limit_litres;
  }
  if(action==='use_distillate'){
    p.data3.modeling.additional_lng=0;
    p.data3.modeling.distillate_user_limit_litres=+amount;
  }
  /* CAMPURAN: `amount` adalah LNG yang DIOTORISASI operator; sisa kekurangan ditutup distillate
     yang dihitung engine lewat allocator diskret, bukan lewat rasio. Plafon distillate sengaja
     TIDAK dikirim supaya engine bebas memilih jumlah legal terkecil yang menutup sisa. */
  if(action==='mixed_lng_distillate'){
    p.data3.modeling.additional_lng=+amount;
    delete p.data3.modeling.distillate_user_limit_litres;
  }
  GSD_USER_LNG=(action==='add_lng'||action==='mixed_lng_distillate')?(+amount):0;
  p._run_source='fuel_action_rerun';
  p._shortage_resolution={source:'USER_APPROVED_SHORTAGE_RESOLUTION', action:action,
    amount:(amount!=null?+amount:null), unit:unit||null, round:GSF_ROUND, at:new Date().toISOString()};
  const rm=document.getElementById('run-msg');
  if(rm) rm.innerHTML='<span class="spin"></span> Rerun ronde '+GSF_ROUND+' dengan '+gsfEsc(action)+'…';
  gsfClose();
  return await runSimCore(p, {shortage:true,source:'fuel_action_rerun'});
}

/* Setelah rerun berhasil, samakan kontrol form dengan keputusan yang benar-benar dipakai supaya
 * input yang terlihat cocok dengan hasil yang terlihat. Ini BUKAN penyimpanan: tidak ada
 * ?mode=save yang dipanggil — data produksi hanya berubah bila operator menekan Save. */
function gsfSyncFormToDecision(p){
  try{
    const m=p&&p.data3&&p.data3.modeling; if(!m) return;
    if(typeof setGasAction==='function') setGasAction(m.gas_shortage_action||'none');
    const inp=document.getElementById('f-additional_lng');
    if(inp && m.gas_shortage_action==='add_lng'){ inp.disabled=false; inp.value=String(m.additional_lng||0); }
  }catch(e){}
}

/* §9: revision monotonik + hash payload untuk anti-stale (per branch token sudah ada di RUN_SEQ). */
let STATE_REVISION={plan:0,monitoring:0};
async function gsSha256Object(value){const text=JSON.stringify(value);if(window.crypto&&window.crypto.subtle&&typeof TextEncoder!=='undefined'){const bytes=new TextEncoder().encode(text),digest=await window.crypto.subtle.digest('SHA-256',bytes);return Array.from(new Uint8Array(digest),b=>b.toString(16).padStart(2,'0')).join('');}throw new Error('Web Crypto SHA-256 tidak tersedia. Gunakan Chrome/Edge terbaru melalui localhost.');}
let ASYNC_REVIEW={plan:null,monitoring:null};
/* V4 — PEKERJA PEMBANTU JOB PEMILIK. Bersama job_exec, browser memicu sejumlah request pembantu
 * (jumlahnya ditentukan server dari inti CPU; 0 = tanpa pembantu). Pembantu hanya mengerjakan
 * candidate-state yang diterbitkan job pemilik — satu pemilik per state, satu pemilik per
 * candidate-state — lalu berhenti sendiri ketika job selesai. Dipicu sekali per job. */
const PPV4_HELPED={};
function ppFireHelpers(id,tok,n){
  n=Math.max(0,Math.min(7,Math.floor(+n||0)));
  if(!id||!tok||!n||PPV4_HELPED[id]) return;
  PPV4_HELPED[id]=1;
  for(let k=1;k<=n;k++) fetch('run.php?mode=job_help&job='+encodeURIComponent(id)+'&token='+encodeURIComponent(tok)+'&slot='+k,{cache:'no-store'}).catch(()=>{});
}
function needsAsyncEconomicReview(data){const i=(data&&data.info)||{},e=(data&&data.error)||{};return /ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET|NON_CONVERGENCE_TIME_BUDGET|economic.review.*time|search budget|GLOBAL_COMMITMENT.*BUDGET|plafon waktu absolut|sebagian kandidat belum dievaluasi/i.test(JSON.stringify([i['Convergence Status'],i.economic_review_skipped,i['Global Commitment Review Skipped'],e.code,e.message,data&&data.message]));}
/* ===== MENGADOPSI JOB YANG SUDAH DIBUAT BACKEND ============================================
 * ROOT CAUSE (terukur pada uji klik nyata, PGN 20 + PEP 40): UI memutuskan perlu-tidaknya
 * penyelesaian asinkron dengan MENCOCOKKAN TEKS pesan (`needsAsyncEconomicReview`). Backend
 * sementara itu sudah menilai sendiri keadaannya dan MEMBUAT job-nya, lalu melampirkannya pada
 * `async_job` / `legal_branch_job`. Ketika teks pesan tidak cocok dengan pola, UI merender hasil
 * yang BELUM konvergen sebagai hasil biasa dan job yang sudah berjalan itu tidak pernah diambil —
 * terpantau: job_start=0, job_poll=0, `economic_review_completed=false`, `converged=false`.
 *
 * Sumber kebenaran yang benar adalah keputusan backend, bukan pencocokan teks. Fungsi ini
 * mengadopsi job yang SUDAH ada: tidak membuat job kedua, memakai jalur polling dan seluruh
 * penjaga anti-stale yang sama. */
async function adoptBackendAsyncJob(payload,branch,token,job,opts){
  const rm=document.getElementById('run-msg');
  const id=job&&job.job_id; if(!id) return false;
  const hash=(job&&job.input_hash)||'';
  const expectedRequest=payload._request_id;
  ASYNC_REVIEW[branch]={id,token,request_id:expectedRequest,input_hash:hash};
  if(rm)rm.textContent='Perhitungan eksak sedang diselesaikan…';
  fastestStart(payload,branch,token,id);   // V12: setiap jalur job (Fastest) dipantau rilis kandidat fully valid pertama
  /* ============================================================================================
   * PERHITUNGAN DIPICU DARI SINI, BUKAN OLEH PROSES OS.
   *
   * Dahulu server meluncurkan worker sebagai proses terpisah lewat `cmd /C start`. Di Windows itu
   * memunculkan jendela Command Prompt setiap kali Run ditekan, dan peluncurannya dapat gagal
   * tanpa jejak. Sekarang browser memicu perhitungan lewat satu request HTTP biasa
   * (`mode=job_exec`) yang dijalankan Apache sendiri — tidak ada proses baru dan tidak ada console.
   *
   * Request ini sengaja TIDAK ditunggu: kemajuan dibaca dari `job_poll` seperti biasa, sehingga
   * modal tetap menampilkan tahap nyata. Server memasang `ignore_user_abort`, jadi perhitungan
   * tetap selesai dan hasilnya tetap tertulis walaupun koneksi ini ditutup. */
  const tok=(job&&job.exec_token)||'';
  if(tok){
    fetch('run.php?mode=job_exec&job='+encodeURIComponent(id)+'&token='+encodeURIComponent(tok),
      {cache:'no-store'}).catch(()=>{});     // dipicu, tidak ditunggu
    ppFireHelpers(id,tok,job&&job.helpers);
  }
  let tanpaHasil=0;
  for(let n=0;n<3600;n++){
    /* V10: interval baca status 250 ms selama 60 detik pertama (FINAL tampil <= 0,25 s sesudah job selesai), lalu 1 s. */
    await new Promise(r=>setTimeout(r,(FASTEST_CTX&&FASTEST_CTX.branch===branch&&FASTEST_CTX.token===token)?600:(n<400?150:1000)));   // V11: 150 ms selama 60 detik pertama   /* V12 Fastest: rilis dibaca fastestPoll; job_poll 600 ms agar polling tidak merebut CPU engine (tanpa OPcache tiap request mengompilasi ~2,2 MB PHP) */
    const live=ASYNC_REVIEW[branch];
    if(token!==RUN_SEQ[branch]||!live||live.id!==id||live.request_id!==expectedRequest)return true;
    const sr=await fetch('run.php?mode=job_poll&job='+encodeURIComponent(id)
      +(hash?('&input_hash='+encodeURIComponent(hash)):''),{cache:'no-store'});
    const sj=await sr.json();
    /* DONE tetapi hasil belum terbaca: dicoba lagi sebentar, lalu dinyatakan — tidak menunggu tanpa akhir. */
    if((!sj.ok&&sj.error==='RESULT_JSON_TIDAK_TERBACA')||(sj.job&&sj.job.status==='DONE'&&!sj.result&&!sj.stale)){
      if(++tanpaHasil<15) continue;
      throw new Error('hasil perhitungan selesai tetapi tidak dapat dibaca server — klik Run sekali lagi');
    }
    if(!sr.ok||!sj.ok)throw new Error((sj.error&&sj.error.message)||sj.error||'gagal membaca async job');
    if(sj.stale)throw new Error('Async job identity mismatch: '+(sj.stale_reason||'input hash berbeda'));
    const j=sj.job||{};
    const langkah=String(j.current_step||j.status||'').replace(/_/g,' ').toLowerCase();
    if(rm)rm.textContent='Perhitungan eksak: '+langkah+' '+Math.round(+j.percent||0)+'%';
    ppmSetWorker(langkah,j.percent);
    if(j.status==='DONE'){
      const res=sj.result; if(!res||!res.output)throw new Error('hasil async tidak tersedia');
      /* Worker SELESAI tidak selalu berarti perhitungan LENGKAP. Bila review ekonominya masih
       * belum selesai, hasilnya tetap ditampilkan — tetapi sebagai PRATINJAU dengan sebab yang
       * dinyatakan, bukan dengan kalimat "Done". Menyebutnya selesai padahal belum adalah persis
       * jenis kebohongan halus yang membuat rencana tidak final terlihat siap pakai. */
      const rsA=(res.output.info&&res.output.info['Run Status'])||{};
      if(res.economic_review_completed!==true||rsA.converged!==true){
        const sk=rsA.economic_review_skipped||null;
        finalizeSimulationUI(payload,res.output,
          '<span style="color:#b45309">Pratinjau — perhitungan eksak selesai tetapi BELUM lengkap'
          +(sk&&sk.reason?(': '+gsfEsc(String(sk.reason))):'')
          +(sk&&sk.estimated_need_s?(' (perkiraan butuh '+gsfEsc(String(sk.estimated_need_s))+' detik, sisa '
            +gsfEsc(String(sk.time_left_s||0))+' detik)'):'')
          +'. Publish tetap terkunci.</span>');
        ASYNC_REVIEW[branch]=null;return true;
      }
      /* Rerun keputusan bahan bakar: form disamakan dengan keputusan yang benar-benar dipakai
       * (bukan penyimpanan), sama seperti jalur sinkron dahulu. */
      if(opts&&opts.shortage&&!gsdContractSaysNotFinal(res.output)){
        try{ gsfSyncFormToDecision(payload); }catch(e){}
        finalizeSimulationUI(payload,res.output,'<span style="color:#166534">Done — hasil ini memakai keputusan bahan bakar Anda ('
          +gsfEsc(String(payload.data3.modeling.gas_shortage_action))+'). Pilihan belum tersimpan; tekan Save bila ingin menyimpannya.</span>');
        ASYNC_REVIEW[branch]=null;return true;
      }
      finalizeSimulationUI(payload,res.output,
        '<span style="color:#166534">Done. '+(res.mode==='INCREMENTAL'?'Recompute inkremental selesai':'Perhitungan eksak selesai')+' — Anda tidak perlu menjalankan ulang.</span>');
      ASYNC_REVIEW[branch]=null;return true;
    }
    if(j.status==='CANCELLED'&&j.fastest_claimed) return false;   // V12: dirilis oleh Fastest (FASTEST_RELEASE_READY)
    if(j.status==='FAILED'||j.status==='CANCELLED')
      throw new Error((j.error&&j.error.message)||j.error||('job '+j.status));
  }
  throw new Error('async job timeout');
}
async function startAsyncEconomicReview(payload,branch,token){
  /* PERBAIKAN REFERENSI MATI: versi V7 memanggil `async_job.php`, file yang TIDAK ADA di dalam
   * paket, sehingga seluruh jalur asinkron berakhir 404 dan operator hanya melihat
   * "Async review gagal". Job runner kini berada DI DALAM run.php (mode=job_start/job_poll/
   * job_cancel), sehingga paket tetap hanya berisi empat file PHP dan jalur ini benar-benar ada.
   * Worker berjalan sebagai proses CLI terlepas dari PHP-FPM. */
  const rm=document.getElementById('run-msg');
  const expectedRequest=payload._request_id;
  if(rm)rm.textContent='Review ekonomi exact sedang diselesaikan…';
  const cr=await fetch('run.php?mode=job_start&kind=economic_review',
    {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  const cj=await cr.json();
  if(!cr.ok||!cj.ok||!cj.job)throw new Error((cj.error&&cj.error.message)||cj.error||'gagal membuat async job');
  const id=cj.job.job_id, hash=cj.input_hash||cj.job.input_hash||'';
  ASYNC_REVIEW[branch]={id,token,request_id:expectedRequest,input_hash:hash};
  /* Sama seperti jalur adopsi: perhitungan dipicu lewat request HTTP, bukan proses OS. */
  const tok2=cj.job.exec_token||'';
  if(tok2){
    fetch('run.php?mode=job_exec&job='+encodeURIComponent(id)+'&token='+encodeURIComponent(tok2),
      {cache:'no-store'}).catch(()=>{});
    ppFireHelpers(id,tok2,cj.job.helpers);
  }
  let tanpaHasil2=0;
  for(let n=0;n<3600;n++){
    await new Promise(r=>setTimeout(r,n<400?150:1000));   // V11: 150 ms selama 60 detik pertama
    /* ANTI HASIL BASI: run yang lebih baru atau identitas job yang berubah membatalkan polling
     * ini tanpa menyentuh UI. */
    const live=ASYNC_REVIEW[branch];
    if(token!==RUN_SEQ[branch]||!live||live.id!==id||live.request_id!==expectedRequest)return;
    const sr=await fetch('run.php?mode=job_poll&job='+encodeURIComponent(id)+'&input_hash='+encodeURIComponent(hash),{cache:'no-store'});
    const sj=await sr.json();
    if((!sj.ok&&sj.error==='RESULT_JSON_TIDAK_TERBACA')||(sj.job&&sj.job.status==='DONE'&&!sj.result&&!sj.stale)){
      if(++tanpaHasil2<15) continue;
      throw new Error('hasil perhitungan selesai tetapi tidak dapat dibaca server — klik Run sekali lagi');
    }
    if(!sr.ok||!sj.ok)throw new Error((sj.error&&sj.error.message)||sj.error||'gagal membaca async job');
    if(sj.stale)throw new Error('Async job identity mismatch: '+(sj.stale_reason||'input hash berbeda'));
    const j=sj.job||{};
    if(j.input_hash&&hash&&j.input_hash!==hash)throw new Error('Async job identity mismatch');
    const langkah2=String(j.current_step||j.status||'').replace(/_/g,' ').toLowerCase();
    if(rm)rm.textContent='Review ekonomi: '+langkah2+' '+Math.round(+j.percent||0)+'%';
    ppmSetWorker(langkah2,j.percent);
    if(j.status==='DONE'){
      const res=sj.result;
      if(!res||!res.output)throw new Error('hasil async tidak tersedia');
      if(res.economic_review_completed!==true)throw new Error('perhitungan selesai tetapi economic review belum lengkap');
      const out=res.output;
      const fr=(out.info&&out.info['Fuel Action Reconciliation'])||null;
      if(payload._run_source==='fuel_action_rerun'&&(!fr||fr.pass!==true))throw new Error('Fuel reconciliation async tidak lulus');
      finalizeSimulationUI(payload,out,'<span style="color:#166534">Done. Economic review exact selesai — Anda tidak perlu menjalankan ulang.</span>');
      ASYNC_REVIEW[branch]=null;return;
    }
    if(j.status==='CANCELLED'&&j.fastest_claimed) return false;   // V12: dirilis oleh Fastest (FASTEST_RELEASE_READY)
    if(j.status==='FAILED'||j.status==='CANCELLED')
      throw new Error((j.error&&j.error.message)||j.error||('job '+j.status));
  }
  throw new Error('async review timeout');
}
/* V3: hasil auto-save yang dilakukan server sebelum simulasi. false = penyimpanan gagal dan
 * simulasi TIDAK dijalankan (file lama utuh). */
function v3AutosaveOutcome(payload,data){
  const el=document.getElementById('autosave-msg');
  const a=data&&data.autosave;
  const code=data&&data.error&&data.error.code;
  if(code==='INPUT_SAVE_FAILED'){
    const why=(a&&a.error)||(data.error&&data.error.message)||'sebab tidak diketahui';
    const rm=document.getElementById('run-msg');
    if(rm) rm.innerHTML='<span style="color:#c0392b"><b>Run dibatalkan:</b> input gagal disimpan ke input_data.json ('+gsfEsc(String(why))+'). File lama tetap utuh; simulasi tidak dijalankan.</span>';
    if(el){ el.style.color='#c0392b'; el.textContent='Auto-save GAGAL — simulasi tidak dijalankan'; }
    return false;
  }
  if(a&&a.requested&&a.ok){
    try{ const c=JSON.parse(JSON.stringify(payload)); Object.keys(c).forEach(k=>{ if(k.charAt(0)==='_') delete c[k]; }); INPUT0=c; }catch(e){}
    if(el){ el.style.color='#166534'; el.textContent='💾 Input tersimpan otomatis ('+(a.skipped_identical?'tidak berubah':(String(a.ms).replace('.',',')+' ms'))+')'; el.title='input_data.json · sha256 '+String(a.sha256||'').slice(0,16)+' · identitas state tersimpan = dihitung: '+(a.saved_equals_computed?'YA':'TIDAK'); }
  }
  return true;
}
(function(){
  /* Selama ada lapisan modal (progres perhitungan, popup Gas Shortage), tombol Save Input yang SAMA
   * dipindah ke <body> dan diangkat di atas lapisan itu; begitu modal tertutup ia kembali ke
   * tempatnya. Elemen dan handler-nya tidak diganti. */
  try{
    let home=null;
    const upd=()=>{
      const open=!!document.querySelector('.sv-mask'); const sv=document.getElementById('btn-save'); if(!sv) return;
      document.body.classList.toggle('pp-mask-open', open);
      if(open && sv.parentNode!==document.body){ home=document.createComment('btn-save-home'); sv.parentNode.insertBefore(home,sv); document.body.appendChild(sv); }
      else if(!open && home && home.parentNode){ home.parentNode.insertBefore(sv,home); home.remove(); home=null; }
    };
    new MutationObserver(upd).observe(document.body,{childList:true});
  }catch(e){}
})();
async function runSim(){
  flushPendingEdits();                                    // §7: commit edit fokus/pending dulu
  let payload; try{payload=assembleInput();}catch(e){return;}
  if(!validateStg3SegBeforeSave(payload)) return;         // PROMPT STG 3-SEGMENT: T1 < T2 wajib
  if(!validateGasKP72BeforeRun(payload)) return;          // Revisi: block Run until KP72 is filled
  /* SHORTAGE DECISION: Run baru = sesi keputusan baru. Payload ini adalah INPUT BERSIH yang
   * dipakai ulang oleh rerun popup, sehingga rerun tidak pernah melanjutkan state parsial. */
  /* ISOLASI STATE ANTAR-RUN (§7). Normal Run TIDAK BOLEH mewarisi keputusan bahan bakar dari run
   * sebelumnya: input dasar yang berubah (PGN/PEP/KP72/GHV/IE/status unit/Export rules/Change Over/
   * Actual Gas) membatalkan keputusan lama. Selain field payload, identitas JOB dan hasil validasi
   * lama juga dibuang supaya polling run lama tidak pernah menimpa hasil run baru. */
  /* ============================================================================================
   * AKAR MASALAH "TIGA PILIHAN TIDAK BEKERJA".
   *
   * Baris ini dahulu MEMAKSA `gas_shortage_action='none'` pada setiap Run normal. Niatnya benar —
   * Run baru tidak boleh mewarisi keputusan bahan bakar dari run SEBELUMNYA — tetapi eksekusinya
   * membuang terlalu banyak: ia juga menghapus pilihan yang BARU SAJA ditetapkan operator di menu
   * Gas Shortage Decision pada sesi ini. Akibatnya "Add LNG" dan "Using Distillate" tidak pernah
   * sampai ke backend, dan opsi pertama pun selalu terkirim sebagai `none` sehingga engine
   * menandainya FLAG_SHORTAGE_ONLY_SELECTED alih-alih menghitung rekomendasi tervalidasi.
   *
   * Yang dibersihkan sekarang adalah HASIL run lama (opsi tervalidasi, state probe, identitas job),
   * BUKAN niat operator. Niat dibaca dari selector saat ini. Jumlah LNG hanya dibawa bila memang
   * mode add_lng — sehingga tidak ada angka menggantung dari mode lain. */
  var __act = (document.getElementById('f-gas_action')||{}).value || 'recommendation';
  if(['none','recommendation','add_lng','use_distillate','force_same_as_quota'].indexOf(__act)<0) __act='recommendation';
  var __lngAmt  = num((document.getElementById('f-additional_lng')||{}).value);
  var __distAmt = num((document.getElementById('f-distillate_litres')||{}).value);

  /* JUMLAH WAJIB ADA SEBELUM RERUN BAHAN BAKAR (instruksi 4.2 dan 4.3).
   *
   * Memilih `Add LNG` atau `Use distillate` di menu hanya menyatakan NIAT, bukan jumlah. Bila
   * operator menekan Run tanpa mengisi jumlah, aplikasi tidak boleh mengarang angka dan tidak
   * boleh mengirim action tanpa plafon: backend akan menerima action distillate dengan plafon 0
   * lalu menolak hasilnya sebagai FUEL_ACTION_NOT_APPLIED — run mahal yang berakhir sia-sia dan
   * tanpa angka yang dapat dipilih operator.
   *
   * Yang benar: hitung dahulu opsi TERVALIDASI untuk input hash saat ini (mode recommendation),
   * lalu popup menawarkan angka itu. Niat operator disimpan di GSD_PENDING_CHOICE supaya popup
   * dapat menyorot opsi yang tadi dipilih. Tidak ada auto-apply: rerun tetap menunggu klik. */
  /* SELURUH pilihan bahan bakar langsung melewati ANALISIS PENDAHULUAN lebih dahulu, bukan hanya
     yang jumlahnya kosong. Alasannya: jumlah yang diketik operator hanya dapat dinilai setelah
     kebutuhan terukur. LNG 8 BBTUD tampak sah sampai diketahui kebutuhannya 9,7385 — dan tanpa
     pendahuluan, engine menjalankan add_lng yang pasti menyisakan kekurangan lalu ditolak, alih-alih
     membentuk bahan bakar campuran. Pendahuluan menjadikan ketiga keputusan (cukup, kurang, kosong)
     dapat dibedakan sebelum rerun final dijalankan. */
  var __pending = null;
  if(__act==='add_lng' || __act==='use_distillate') { __pending=__act; __act='recommendation'; }
  try{ GSD_PENDING_CHOICE = __pending; }catch(e){}

  payload.data3.modeling.gas_shortage_action=__act;
  payload.data3.modeling.additional_lng = (__act==='add_lng') ? __lngAmt : 0;
  delete payload.data3.modeling.distillate_user_limit_litres;
  if(__act==='use_distillate') payload.data3.modeling.distillate_user_limit_litres = __distAmt;
  GSF_BASE_PAYLOAD=JSON.parse(JSON.stringify(payload));
  delete payload.data3.modeling.validated_options;
  delete payload.data3.modeling.validated_shortage_options;
  delete payload.data3.modeling._validated_option;
  delete payload.data3.modeling.__probe_tested;
  delete payload.data3.modeling.__probe_state;
  delete payload._shortage_resolution;
  delete payload._validated_option;
  delete payload._run_source;
  try{ ASYNC_REVIEW.plan=null; ASYNC_REVIEW.monitoring=null; }catch(e){}
  if(typeof gsfStopPolling==='function') gsfStopPolling();
  GSF_VO=null; GSF_VO_JOB=null;
  GSF_ROUND=0; GSF_HISTORY=[]; GSF_BASE_PAYLOAD=JSON.parse(JSON.stringify(payload));
  /* V3: setiap klik Run menyimpan payload INI ke input_data.json lebih dulu (atomik, di server,
   * dalam request yang sama). Rerun dari popup keputusan bahan bakar tidak membawa penanda ini. */
  payload._autosave=true;
  const __tl=tlTarget();
  if(__tl==='fast') return runSimCore(payload, {fastest:true});
  if(__tl!=='max') return runTimeLimited(payload, +__tl);
  return runSimCore(payload, {});
}
/* Maximum Review: keluarga commitment (bagian ruang kandidat exact) mulai dievaluasi bersamaan dengan
 * pipeline exact. Node yang selesai disimpan di registri bersama state ini, sehingga tahap akhir job
 * exact memakai ulang hasilnya alih-alih menghitung ulang. */
var TL_MAX_RID=null; var TL_FINAL_TOKEN=null;
/* V11: awal run (performance.now) untuk Waktu aktual SUMMARY, dan penanda SUMMARY sudah ditulis jalur Target Selesai. */
var V11_RUN_T0=null; var V11_SUM_DONE=false;
function tlWinnerLabel(sp){
  const n=String((sp&&sp.winner_node)||''); const src=String((sp&&sp.winner_source)||'PIPELINE');
  if(src==='INCREMENTAL') return 'recompute inkremental ('+(sp.commitment_unchanged===false?'commitment berganti ke kandidat ruang exact sebelumnya':'commitment final terakhir dipertahankan')+')';
  if(src==='PIPELINE') return 'hasil pipeline exact';
  if(src==='EVALUATED_INCUMBENT') return 'incumbent valid termurah yang dievaluasi pipeline';
  if(n.indexOf('seed:')===0) return 'commitment hasil final state terkait'+(n.indexOf('+')>0?' + unit mati '+gsfEsc(n.split('+')[1].toUpperCase()):'');
  const off=n.replace(/^off:/,''); return off?('unit mati sepanjang hari: '+gsfEsc(off.toUpperCase())):'commitment dasar (tanpa start otomatis)';
}
/* Selama optimasi exact berjalan, kandidat valid terbaik yang sudah selesai dievaluasi (bagian ruang
 * kandidat exact) ditampilkan sebagai VALID PROVISIONAL — terkunci, bukan hasil final. Hanya
 * diganti bila kandidat baru lebih murah; berhenti begitu hasil final atau keputusan Gas Shortage
 * tampil. */
async function tlFamilyProvisionalPoll(payload,branch,token,jobId){
  const rid=jobId||TL_MAX_RID; if(!rid) return; TL_MAX_RID=rid;
  let shownCp=(GSD_PROVISIONAL&&GSD_PROVISIONAL.data&&GSD_PROVISIONAL.data.info)?+GSD_PROVISIONAL.data.info['Cost Production (USD/MWh)']:null;
  let sinceP='';                      // V4: kandidat yang sudah diterima tidak dikirim ulang server
  for(let n=0;n<3600;n++){
    await new Promise(r=>setTimeout(r,n<60?150:(n<120?700:1500)));
    if(token!==RUN_SEQ[branch]||TL_FINAL_TOKEN===token||TL_MAX_RID!==rid) return;
    try{ if(PRELIM&&PRELIM.payload&&PRELIM.payload._request_id===payload._request_id) return; }catch(e){}
    const r=await tlJson('run.php?mode=tl_pool&as=provisional&'+(jobId?'job=':'rid=')+encodeURIComponent(rid)+(sinceP?'&since='+encodeURIComponent(sinceP):''),null,4000,1);
    if(token!==RUN_SEQ[branch]||TL_FINAL_TOKEN===token) return;
    if(r&&r.ok&&r.unchanged) continue;
    if(r&&r.ok&&r.since) sinceP=String(r.since);
    if(!r||!r.ok||!r.output||!Array.isArray(r.output.data)||r.output.data.length!==48) continue;
    const cp=+((r.output.info||{})['Cost Production (USD/MWh)']);
    if(shownCp!=null&&!(cp<shownCp-1e-9)) continue;
    shownCp=cp;
    if(typeof ppmClose==='function') ppmClose();
    INPUT=payload; OUTPUT=r.output; GSD_PROVISIONAL={data:r.output};
    GSD_GATE=true; gsdApplyGate();
    try{ renderResult(r.output); refreshOverview(); refreshPills(); showSimulationDataResult(); }catch(e){}
    gsdProvisionalBanner(r.output);
    const rmP=document.getElementById('run-msg');
    if(rmP) rmP.innerHTML='<span style="color:#b45309"><b>VALID PROVISIONAL</b> — EXACT COST OPTIMIZATION IN PROGRESS. '
      +'Kandidat valid terbaik sejauh ini: Cost Production <b>'+fmt(cp,4)+' USD/MWh</b>; seluruh constraint valid; belum dibuktikan terendah. Save/Export/Publish terkunci.</span>';
  }
}
function tlMaxFamilyStart(payload){
  try{
    if(TL_MAX_RID) fetch('run.php?mode=tl_stop&rid='+encodeURIComponent(TL_MAX_RID),{cache:'no-store'}).catch(()=>{});
    const m=(payload&&payload.data3&&payload.data3.modeling)||{};
    if(m.change_over&&m.change_over.enabled){ TL_MAX_RID=null; return; }
    TL_MAX_RID='max-'+Date.now().toString(36)+Math.random().toString(36).slice(2,6);
    fetch('run.php?mode=tl_search&target=max&rid='+encodeURIComponent(TL_MAX_RID),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),cache:'no-store'}).catch(()=>{});
  }catch(e){}
}
/* =============================================================================================
 *  GERBANG KEPUTUSAN BAHAN BAKAR.
 *
 *  Selama gerbang aktif, aplikasi TIDAK memiliki rencana final. Yang ada hanyalah analisis
 *  pendahuluan yang dipakai untuk mengukur kekurangan gas. Karena itu:
 *    - PRELIM menyimpannya terpisah; OUTPUT sengaja TIDAK disentuh supaya seluruh jalur yang
 *      membaca OUTPUT (Save, Excel, Publish, snapshot) tidak menemukan apa pun untuk diterbitkan;
 *    - tombol dikunci secara VISUAL sekaligus dijaga di dalam fungsinya (pertahanan berlapis),
 *      karena tombol yang sekadar disabled masih dapat dipicu lewat jalan lain.
 *  Gerbang dibuka hanya oleh rerun final yang berhasil, atau oleh Run baru yang tidak shortage. */
let GSD_GATE=false;            // true = menunggu keputusan operator, tidak ada hasil final
let PRELIM=null;               // hasil pendahuluan yang ditahan (tidak pernah menjadi OUTPUT)

/* =============================================================================================
 *  MODAL PEMBLOKIR DENGAN PROGRES NYATA.
 *
 *  MASALAH YANG DISELESAIKAN. Analisis pendahuluan memakai belasan sampai puluhan detik, dan
 *  selama itu satu-satunya umpan balik adalah tombol Run yang berputar. Operator tidak tahu
 *  apakah aplikasi bekerja, menggantung, atau sudah gagal — dan dapat menekan apa pun di
 *  belakangnya.
 *
 *  YANG TIDAK DILAKUKAN. Comparator global dan decommit screening TIDAK dipotong, tidak ada
 *  tolerance yang dilebarkan, dan tidak ada angka estimasi yang disajikan sebagai hasil. Modal
 *  ini tidak mempercepat apa pun; ia membuat penantian menjadi JUJUR dan memblokir betul.
 *
 *  SUMBER PROGRES. Nama fase dibaca dari `run.php?mode=prelim_progress`, yang melaporkan batas
 *  fase yang BENAR-BENAR sudah dilewati engine. Tidak ada animasi yang mengarang kemajuan: bila
 *  engine berhenti, daftar fase berhenti bertambah, dan itu sendiri adalah informasi.
 *
 *  KUNCI. Selama modal terbuka, gerbang keputusan aktif (GSD_GATE), sehingga Save/Export/Publish
 *  terkunci secara visual DAN dijaga di dalam fungsinya. Angka dan tombol rekomendasi baru aktif
 *  setelah validasi eksak selesai — itu dikerjakan popup gsfOpen, bukan modal ini. */
const PPM_PHASE_ID={
  entry:                       'Menyiapkan data masukan',
  baseline_core_run:           'Menjalankan rencana dasar',
  mandatory_stop_pass:         'Memeriksa mandatory stop',
  decommit_screening:          'Penyaringan decommit unit',
  gas_window_correction:       'Koreksi window kuota gas',
  gas_quota_offset_search:     'Pencarian offset kuota gas',
  export_min_phase_a:          'Minimisasi Export — tahap A',
  export_min_phase_b_decommit: 'Minimisasi Export — tahap B (decommit)',
  export_min_phase_b_gcr:      'Minimisasi Export — tahap B (comparator global)',
  pipeline_end:                'Merapikan hasil dan validasi',
  v3_owner_start:              'Input tersimpan; job pemilik state dimulai',
  v3_incremental_redispatch:   'Recompute inkremental: redispatch commitment tetap',
  v3_incremental_competitors:  'Recompute inkremental: kandidat pesaing',
  v3_exact_full:               'Optimasi exact penuh',
  v3_exact_family_first:       'Keluarga commitment (kandidat valid awal)',
  v3_family_node:              'Node keluarga commitment selesai dievaluasi'
};
const PPM_SHORTAGE_PHASES=['decommit_screening','gas_window_correction','gas_quota_offset_search',
                           'export_min_phase_a','export_min_phase_b_decommit','export_min_phase_b_gcr'];
let PPM_TIMER=null, PPM_POLL=null, PPM_T0=0, PPM_RID='', PPM_SEEN=0, PPM_SHORTAGE=false;
let PPM_FIRED={};           // job lanjutan yang sudah dipicu dari berkas progres
let PPM_LAST_PCT=-1, PPM_LAST_MOVE=0;   // watchdog tanpa-kemajuan

function ppmClose(){
  if(PPM_TIMER){ clearInterval(PPM_TIMER); PPM_TIMER=null; }
  if(PPM_POLL){ clearInterval(PPM_POLL); PPM_POLL=null; }
  const m=document.getElementById('ppm-mask'); if(m) m.remove();
}
function ppmOpen(rid,onBatal){
  ppmClose();
  PPM_T0=Date.now(); PPM_RID=String(rid||''); PPM_SEEN=0; PPM_SHORTAGE=false;
  PPM_LAST_PCT=-1; PPM_LAST_MOVE=Date.now();
  const mask=document.createElement('div');
  mask.className='sv-mask'; mask.id='ppm-mask'; mask.style.alignItems='center';
  const box=document.createElement('div');
  box.className='sv-box'; box.style.maxWidth='460px';
  box.setAttribute('role','dialog'); box.setAttribute('aria-modal','true');
  box.innerHTML=
    '<h4 id="ppm-title">Analisis rencana sedang berjalan</h4>'
   +'<p class="sv-note" id="ppm-sub">Perhitungan penuh sedang dijalankan. Tidak ada angka yang '
   +'ditampilkan sampai validasi eksak selesai; Save, Export, dan Publish terkunci.</p>'
   +'<div style="display:flex;align-items:center;gap:10px;margin:2px 0 10px">'
   +'<span class="spin"></span><b id="ppm-phase" style="font-size:13px">Menyiapkan…</b>'
   +'<span style="margin-left:auto;font-variant-numeric:tabular-nums;color:#475569" id="ppm-clock">0,0 s</span></div>'
   +'<ol id="ppm-steps" style="margin:0;padding-left:18px;color:#475569;font-size:12.5px;line-height:1.7"></ol>'
   +'<p class="sv-note" style="margin:12px 0 0;color:#94a3b8;font-size:11.5px">Tahap yang tercantum '
   +'adalah tahap yang sudah BENAR-BENAR dilewati mesin hitung, bukan perkiraan waktu.</p>'
   +'<div id="ppm-watchdog" style="display:none;margin-top:10px;padding:9px 11px;border-radius:8px;'
   +'background:#fef3c7;border:1px solid #fcd34d;color:#92400e;font-size:12px;line-height:1.5"></div>'
   +'<div style="margin-top:12px;text-align:right"><button type="button" id="ppm-batal" '
   +'style="border:1px solid #cbd5e1;border-radius:8px;padding:7px 14px;background:#f8fafc;'
   +'cursor:pointer;font:600 12.5px system-ui,Segoe UI,Arial;color:#334155">Batalkan</button></div>';
  mask.appendChild(box);
  document.body.appendChild(mask);
  /* JALAN KELUAR. Lapisan pemblokir tanpa jalan keluar berubah menjadi aplikasi yang menggantung
   * begitu ada satu tahap yang tidak selesai. Membatalkan TIDAK menerbitkan apa pun: hasil yang
   * mungkin datang belakangan sudah menjadi basi dan dibuang oleh penjaga anti-stale. */
  const tb=document.getElementById('ppm-batal');
  if(tb) tb.addEventListener('click',()=>{
    ppmClose();
    try{ if(typeof onBatal==='function') onBatal(); }catch(e){}
    const rm=document.getElementById('run-msg');
    if(rm) rm.innerHTML='<span style="color:#b45309">Dibatalkan operator. Tidak ada hasil yang '
      +'diterbitkan; Save, Export, dan Publish tetap mengikuti keadaan sebelumnya.</span>';
  });
  /* Jam berjalan lokal: memberi tanda hidup setiap 100 ms tanpa satu pun request tambahan. */
  PPM_TIMER=setInterval(()=>{
    const el=document.getElementById('ppm-clock');
    if(el) el.textContent=((Date.now()-PPM_T0)/1000).toFixed(1).replace('.',',')+' s';
  },100);
  /* Progres nyata dari mesin hitung. Polling pertama pada 700 ms sehingga tahap pertama tampil
   * jauh di bawah dua detik. Endpoint-nya satu file_get_contents dan berada SEBELUM pemeriksaan
   * POST, jadi ia tidak pernah antre di belakang request berat. */
  const tick=async()=>{
    if(!PPM_RID) return;
    try{
      const r=await fetch('run.php?mode=prelim_progress&rid='+encodeURIComponent(PPM_RID),{cache:'no-store'});
      const j=await r.json();
      /* Job lanjutan didaftarkan di tengah jalur sinkron: picu perhitungannya SEKARANG. Klaim atomik
       * di server menjamin pemicu kedua (dari jalur adopsi) tidak pernah menghitung ulang. */
      if(j&&j.async_job&&j.async_job.job_id&&j.async_job.exec_token&&!PPM_FIRED[j.async_job.job_id]){
        PPM_FIRED[j.async_job.job_id]=1;
        fetch('run.php?mode=job_exec&job='+encodeURIComponent(j.async_job.job_id)
          +'&token='+encodeURIComponent(j.async_job.exec_token),{cache:'no-store'}).catch(()=>{});
        ppFireHelpers(j.async_job.job_id,j.async_job.exec_token,j.async_job.helpers);
      }
      ppmRender(Array.isArray(j.steps)?j.steps:[]);
    }catch(e){ /* polling tidak boleh pernah menggagalkan run */ }
  };
  setTimeout(tick,700);
  PPM_POLL=setInterval(tick,900);
}
function ppmRender(steps){
  if(!document.getElementById('ppm-mask')) return;
  if(steps.length===PPM_SEEN) return;
  PPM_SEEN=steps.length;
  const ol=document.getElementById('ppm-steps'); if(!ol) return;
  /* ============================================================================================
   * DURASI FASE DIPISAHKAN DARI WAKTU KUMULATIF.
   *
   * AKAR SALAH BACA YANG DIPERBAIKI DI SINI. Daftar ini dahulu hanya menampilkan SATU angka per
   * fase, yaitu `at_s` — jam sejak run dimulai. Angka itu terbaca seolah-olah durasi fase, dan
   * karenanya `Penyaringan decommit unit 56,1 s` tampak seperti fase yang memakan 56 detik.
   * Padahal, terukur pada profil: pada rencana yang sama durasi fase decommit adalah 9,3 detik
   * sementara waktu kumulatifnya 10,7 detik. Salah baca ini menuntun pada kesimpulan yang salah
   * tentang di mana waktu sebenarnya habis.
   *
   * Kini keduanya ditampilkan apa adanya: durasi fase lebih dahulu (itulah yang dicari ketika
   * bertanya "fase mana yang mahal"), lalu waktu kumulatif di belakangnya. */
  let prevAt=0;
  ol.innerHTML=steps.map(s=>{
    const nama=PPM_PHASE_ID[s.phase]||String(s.phase||'').replace(/_/g,' ');
    const at=Number(s.at_s||0);
    const dur=Math.max(0, at-prevAt); prevAt=at;
    const f=v=>String(v.toFixed(1)).replace('.',',');
    return '<li>'+gsfEsc(nama)
      +' <b style="color:#334155">'+f(dur)+' s</b>'
      +' <span style="color:#94a3b8">(kumulatif '+f(at)+' s)</span></li>';
  }).join('');
  const last=steps[steps.length-1]||{};
  const ph=document.getElementById('ppm-phase');
  if(ph) ph.textContent=PPM_PHASE_ID[last.phase]||String(last.phase||'').replace(/_/g,' ');
  /* Judul berubah menjadi pernyataan kekurangan gas hanya ketika mesin hitung BENAR-BENAR masuk
   * ke fase yang menangani kekurangan gas. Sampai saat itu judulnya netral, supaya run biasa
   * tidak pernah diberi label masalah yang tidak dialaminya. */
  if(!PPM_SHORTAGE && steps.some(s=>PPM_SHORTAGE_PHASES.includes(s.phase))){
    PPM_SHORTAGE=true;
    const t=document.getElementById('ppm-title');
    if(t) t.textContent='Gas shortage analysis in progress';
    const sub=document.getElementById('ppm-sub');
    if(sub) sub.textContent='Kebutuhan gas melebihi kuota. Rekomendasi LNG dan Distillate sedang '
      +'dihitung dan divalidasi ulang secara penuh. Angka belum ditampilkan sampai validasi eksak '
      +'selesai; Save, Export, dan Publish terkunci.';
  }
}
/* Dipakai jalur worker background: modal TETAP terbuka, isinya berganti menjadi kemajuan worker
 * sehingga tidak pernah ada jeda di mana layar tampak selesai padahal belum. */
function ppmSetWorker(teks,persen){
  if(!document.getElementById('ppm-mask')) return;
  /* V3: polling tahap tetap berjalan — job pemilik menulis tahapnya ke berkas progres request ini. */
  /* ============================================================================================
   * WATCHDOG TANPA-KEMAJUAN.
   *
   * Kegagalan yang dilaporkan operator berbunyi "antre 0%" selama 64,9 dan 145,2 detik. Yang
   * membuatnya menyakitkan bukan lamanya, melainkan bahwa layar tidak pernah mengatakan ada yang
   * salah — operator menunggu sesuatu yang sudah mati. Watchdog ini mengukur berapa lama angka
   * kemajuan TIDAK berubah, dan setelah 30 detik ia mengatakannya terus terang, lengkap dengan
   * apa yang harus dilakukan. Ia tidak membatalkan apa pun; keputusan tetap pada operator. */
  const kini=(isFinite(+persen)?Math.round(+persen):-1);
  if(kini!==PPM_LAST_PCT){ PPM_LAST_PCT=kini; PPM_LAST_MOVE=Date.now(); }
  const diam=(Date.now()-(PPM_LAST_MOVE||Date.now()))/1000;
  const wd=document.getElementById('ppm-watchdog');
  if(wd){
    /* Ambang 120 detik, bukan 30. Progres ditulis pada batas fase, dan pada mesin lambat satu fase
     * (penyaringan decommit) terukur 33-49 detik tanpa perubahan persen. Ambang 30 detik akan
     * membunyikan peringatan palsu di tengah perhitungan yang sehat — jam di modal tetap berjalan
     * sehingga layar tidak pernah tampak beku. */
    if(diam>=120){
      wd.style.display='block';
      wd.innerHTML='<b>Tidak ada kemajuan selama '+Math.round(diam)+' detik.</b> '
        +'Perhitungan mungkin terhenti. Tekan Batalkan lalu klik Run kembali; hasil yang sudah '
        +'selesai dihitung akan langsung dipakai ulang tanpa dihitung dari awal.';
    } else wd.style.display='none';
  }
  const ph=document.getElementById('ppm-phase');
  if(ph) ph.textContent=teks+(isFinite(+persen)?(' '+Math.round(+persen)+'%'):'');
  const sub=document.getElementById('ppm-sub');
  if(sub) sub.textContent='Perhitungan eksak sedang diselesaikan di luar batas waktu request. '
    +'Anda tidak perlu menjalankan ulang simulasi.';
}

function gsdApplyGate(){
  const lock=!!GSD_GATE;
  const tl=!lock && !!(OUTPUT && OUTPUT.time_limited===true);
  /* V3: Save Input (btn-save) TIDAK pernah dikunci — gerbang rilis hanya mengunci penyimpanan,
   * ekspor, dan publikasi HASIL simulasi. */
  const sv=document.getElementById('btn-save');
  if(sv && sv.classList.contains('gsd-locked')){ sv.disabled=false; sv.classList.remove('gsd-locked'); sv.title=''; }
  ['btn-xls','btn-img-full','btn-img-partial','btn-publish','btn-save-actual'].forEach(id=>{
    const b=document.getElementById(id); if(!b) return;
    const l=lock || (tl && (id==='btn-publish'||id==='btn-save-actual'));
    b.disabled=l;
    b.classList.toggle('gsd-locked',l);
    b.title=lock?'Terkunci: keputusan bahan bakar belum diambil'
           :(l?'Terkunci: TIME-LIMITED VALID PLAN belum dibuktikan global optimum — hanya dapat dilihat dan diekspor':'');
  });
}
let GSD_REQ_LNG=null, GSD_USER_LNG=null;   // untuk ringkasan keputusan bahan bakar pada hasil final
function gsdHoldPreliminary(payload,data){
  try{ const ii=(data&&data.info)||{};
    const rq=num(ii['Recommended LNG (BBTUD)'])||num(ii['Gas Shortage (BBTUD)']);
    if(rq>0) GSD_REQ_LNG=rq; }catch(e){}
  GSD_GATE=true;
  PRELIM={payload:payload,data:data};
  gsdApplyGate();
  refreshGasDecision();          // badge/angka boleh diperbarui — itu bukan hasil final
}
function gsdReleaseGate(){ GSD_GATE=false; PRELIM=null; gsdApplyGate(); }
/* Penjaga tunggal yang dipakai Save/Excel/Publish. Mengembalikan true bila aksi harus DIBATALKAN. */
function gsdBlocked(aksi){
  if(!GSD_GATE) return false;
  const m=document.getElementById('run-msg');
  const t='“'+aksi+'” terkunci: keputusan bahan bakar belum diambil. '
         +'Hasil yang ada baru analisis pendahuluan, bukan rencana final.';
  if(m) m.innerHTML='<span style="color:#c0392b">'+gsfEsc(t)+'</span>';
  if(typeof toast==='function') toast(t);
  return true;
}
/* =============================================================================================
 *  KONTRAK BACKEND DIBACA SEBAGAI KONTRAK, BUKAN SEBAGAI SARAN.
 *
 *  Gerbang sebelumnya dipicu oleh DAFTAR NAMA STATUS. Daftar itu tidak pernah lengkap: status
 *  `ECONOMIC_REVIEW_REQUIRED` tidak ada di dalamnya, sehingga hasil PENDAHULUAN yang belum
 *  konvergen dan masih menyisakan kekurangan gas 9,6985 BBTUD tetap lolos ke OUTPUT dengan
 *  Save/Export terbuka — akar penyebab yang sama persis, hanya lewat cabang tetangga.
 *
 *  Penjaga ini tidak membaca nama. Ia membaca tiga penanda yang dinyatakan backend sendiri.
 *  Selama salah satunya menyatakan "belum final", tidak ada nama status apa pun — termasuk nama
 *  yang belum ada hari ini — yang dapat menjadikannya rencana final. */
function gsdContractSaysNotFinal(data){
  if(!data || typeof data!=='object') return false;
  if(data.preliminary===true || data.final_result_visible===false || data.save_allowed===false) return true;
  /* PERTAHANAN KEDUA — TIDAK BERGANTUNG PADA PENANDA YANG DIKIRIM BACKEND.
   *
   * Hasil worker latar dahulu sampai ke sini sebagai keluaran MENTAH: tanpa `status`, tanpa
   * `shortage_decision`, tanpa satu pun penanda kontrak — sehingga pemeriksaan di atas tidak
   * punya apa pun untuk dipegang dan rencananya terbit dengan kalimat "Done". Yang terbit pada
   * jejak klik nyata adalah rencana dengan `release_allowed=false`, `HARD_VALIDATION_FAILED`,
   * dan kekurangan gas 9,7385 BBTUD yang belum diselesaikan.
   *
   * Tiga pemeriksaan di bawah membaca BUKTI yang selalu ada di dalam hasil itu sendiri, bukan
   * penanda yang harus diingat backend untuk dikirim. Dengan begitu versi backend mana pun —
   * termasuk yang lebih lama — tidak dapat membuat UI ini menerbitkan rencana yang ditolak. */
  if(data.ok===false) return true;
  if(data.release_gate && data.release_gate.release_allowed===false) return true;
  if(data.gas_feasibility_audit && data.gas_feasibility_audit.feasible===false) return true;
  return false;
}
/* =============================================================================================
 *  PENYELESAIAN OTOMATIS BILA OPERATOR SUDAH MEMILIH BAHAN BAKAR SEBELUM Run (§5 dan §6).
 *
 *  Memilih `Add LNG` atau `Use distillate` di menu sudah merupakan keputusan. Menampilkan popup
 *  lagi berarti menanyakan hal yang sama dua kali. Yang belum diketahui hanyalah JUMLAH-nya, dan
 *  itu baru terukur setelah analisis pendahuluan — karena itu urutannya: pendahuluan dahulu, lalu
 *  rerun final otomatis, tanpa popup kedua.
 *
 *    - jumlah kosong       -> pakai jumlah tervalidasi yang direkomendasikan;
 *    - LNG >= kebutuhan    -> pakai angka operator sebagai otorisasi;
 *    - LNG <  kebutuhan    -> BAHAN BAKAR CAMPURAN: LNG sebesar otorisasi, sisanya distillate.
 *
 *  AKAR PENYEBAB YANG DIPERBAIKI DENGAN MENGEKSTRAK INI. Logika di atas dahulu tertanam HANYA di
 *  dalam cabang gerbang jalur SINKRON. Padahal pada mesin dengan `max_execution_time` bawaan,
 *  keputusan bahan bakar justru biasanya tiba lewat hasil WORKER — dan di jalur itu logikanya
 *  tidak ada sama sekali. Akibatnya operator yang sudah memilih "Add LNG" di menu tetap disodori
 *  popup, pertanyaan yang sama ditanyakan dua kali, dan alur otomatisnya tidak pernah berjalan.
 *  Satu fungsi ini kini dipakai KEDUA jalur, sehingga keduanya tidak dapat berbeda.
 *
 *  Mengembalikan Promise bila ia menangani keputusannya, atau null bila tidak ada yang dipilih. */
function gsdAutoResolveIfChosen(payload,data){
  if(GSD_PENDING_CHOICE!=='add_lng' && GSD_PENDING_CHOICE!=='use_distillate') return null;
  const pilih=GSD_PENDING_CHOICE; GSD_PENDING_CHOICE=null;
  const inf=data.info||{};
  const reqLng=num(inf['Recommended LNG (BBTUD)'])||num(inf['Gas Shortage (BBTUD)']);
  const reqDist=num(inf['Recommended Distillate (l/day)'])||num(inf['Required Distillate (l/day)']);
  const ketik=num((document.getElementById(pilih==='add_lng'?'f-additional_lng':'f-distillate_litres')||{}).value);
  GSF_BASE_PAYLOAD=JSON.parse(JSON.stringify(payload));
  if(pilih==='add_lng'){
    if(!(ketik>0)){
      $('run-msg').innerHTML='<span class="spin"></span> Kebutuhan LNG terukur '+gsfEsc(String(reqLng))+' BBTUD — menjalankan rerun final otomatis…';
      return gsfRerun('add_lng', reqLng, 'BBTUD');
    }
    if(ketik < reqLng - 1e-9){
      const sisa=Math.round((reqLng-ketik)*10000)/10000;
      $('run-msg').innerHTML='<span class="spin"></span> LNG '+gsfEsc(String(ketik))+' BBTUD belum menutup kebutuhan '
        +gsfEsc(String(reqLng))+' BBTUD — sisa '+gsfEsc(String(sisa))+' BBTUD ditutup Distillate tervalidasi…';
      return gsfRerun('mixed_lng_distillate', ketik, 'BBTUD');
    }
    return gsfRerun('add_lng', ketik, 'BBTUD');
  }
  const lit=(ketik>0)?ketik:reqDist;
  $('run-msg').innerHTML='<span class="spin"></span> Menjalankan rerun final Distillate '+gsfEsc(String(lit))+' l/hari…';
  return gsfRerun('use_distillate', lit, 'l/hari');
}
/* Hasil non-final ditahan DAN ditindaklanjuti. Menahan saja tidak cukup: bila yang ditahan adalah
 * keputusan bahan bakar, operator justru kehilangan satu-satunya jalan untuk memutuskannya.
 * Satu tempat ini dipakai oleh jalur sinkron maupun jalur worker background, supaya perilakunya
 * tidak bisa berbeda antar-jalur. */
function gsdHandleNonFinalResult(payload,data,extraMsgHtml){
  gsdHoldPreliminary(payload,data);
  const dec=data.shortage_decision||null;
  /* Popup HANYA dibuka ketika backend sendiri menyatakan estimasi bahan bakarnya sudah siap
   * (status keputusan bahan bakar). `shortage_decision.action_required` TIDAK cukup: pada hasil
   * yang belum konvergen nilainya sudah berbunyi USER_FUEL_DECISION padahal angkanya belum
   * tervalidasi — menampilkannya berarti menyodorkan estimasi mentah sebagai rekomendasi. */
  const perluBahanBakar=!!dec
    && (data.status==='USER_FUEL_DECISION_REQUIRED'
     || data.status==='FUEL_SELECTION_REQUIRED');
  const rmG=document.getElementById('run-msg');
  /* V7: keputusan kuota operator (konflik window gas terbukti) dari jalur worker —
   * dinyatakan sama seperti jalur sinkron, bukan sebagai kekurangan bahan bakar. */
  if(data.status==='USER_ACTION_REQUIRED'&&String(data.action_required||'')==='GAS_WINDOW_QUOTA_DECISION'){
    const blk=data.gas_window_conflict||null;
    const bk=(blk&&Array.isArray(blk.bukti)&&blk.bukti.length)?' — '+gsfEsc(String(blk.bukti[Math.min(1,blk.bukti.length-1)])):'';
    if(rmG) rmG.innerHTML=(extraMsgHtml!=null?(extraMsgHtml+'<br>'):'')
      +'<span style="color:#b45309"><b>Perlu keputusan operator.</b> '+gsfEsc(String(data.message||''))+bk
      +' Hasil BELUM final: baris yang tampil adalah pratinjau bukti, bukan rencana; Save/Export/Publish terkunci.</span>';
    return;
  }
  if(rmG) rmG.innerHTML=(extraMsgHtml!=null?(extraMsgHtml+'<br>'):'')
    +'<span style="color:#b45309"><b>Hasil BELUM final.</b> '
    +gsfEsc(String(data.preliminary_note||data.message
      ||'Backend menyatakan hasil ini belum boleh disimpan atau diterbitkan.'))
    +' Save/Export/Publish terkunci.</span>';
  if(!perluBahanBakar) return;
  /* Keputusan yang sudah diambil operator di menu diselesaikan otomatis di sini juga — bukan
   * hanya di jalur sinkron — sehingga pertanyaan yang sama tidak pernah ditanyakan dua kali. */
  const auto2=gsdAutoResolveIfChosen(payload,data);
  if(auto2){ auto2.catch(e=>{ const m=document.getElementById('run-msg');
      if(m) m.innerHTML='<span style="color:#c0392b">Rerun otomatis gagal: '+gsfEsc(String(e.message||e))+'</span>'; });
    return; }
  /* ============================================================================================
   * OPSI TERVALIDASI DIMINTA DARI SINI BILA HASILNYA DATANG DARI WORKER.
   *
   * Tombol "Gunakan LNG"/"Gunakan Distillate" hanya aktif setelah VALIDATED_OPTIONS_READY. Hasil
   * worker sengaja TIDAK membuat job itu sendiri — mencoba men-spawn job dari dalam worker terbukti
   * menjatuhkan seluruh job (`PHP_CLI_TIDAK_DITEMUKAN`). Worker hanya menyatakan bahwa opsinya
   * masih dibutuhkan, dan permintaannya dikirim dari sini lewat jalur HTTP yang sudah terbukti
   * bekerja. Tanpa langkah ini popup terbuka dengan kedua tombol mati selamanya.
   *
   * Bila permintaannya gagal, popup TETAP dibuka dengan sebab yang dinyatakan — operator masih
   * dapat memakai Input Manual dan Batal, bukan berhadapan dengan layar diam. */
  if(!data.validated_options_job && data.validated_options_required===true){
    /* Popup dibuka dalam status MENGHITUNG — bukan "tidak tersedia" — sementara job diminta. */
    gsfOpen(dec,data.info||{},'',{action_status:'CALCULATING_VALIDATED_OPTIONS'});
    fetch('run.php?mode=job_start&kind=validated_options',
      {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
      .then(r=>r.json())
      .then(cj=>{
        if(!cj||!cj.ok||!cj.job) throw new Error((cj&&cj.error&&cj.error.message)||(cj&&cj.error)||'gagal membuat job opsi tervalidasi');
        GSF_VO_JOB={job_id:cj.job.job_id, input_hash:cj.input_hash||cj.job.input_hash||''};
        const tok3=cj.job.exec_token||'';
        if(tok3) fetch('run.php?mode=job_exec&job='+encodeURIComponent(cj.job.job_id)
          +'&token='+encodeURIComponent(tok3),{cache:'no-store'}).catch(()=>{});
        if(typeof gsfStartPolling==='function') gsfStartPolling();
      })
      .catch(e=>{ const el=document.getElementById('gsf-err');
        GSF_VO={action_status:'VALIDATION_FAILED',
          lng:{validated:false,reason:'Permintaan validasi gagal: '+String(e.message||e)},
          distillate:{validated:false,reason:'Permintaan validasi gagal: '+String(e.message||e)}};
        gsfRenderOptions(null,false);
        if(el) el.textContent='Opsi tervalidasi tidak dapat dihitung: '+String(e.message||e)
                            +'. Gunakan Input Manual atau Batal.'; });
    return;
  }
  gsfOpen(dec,data.info||{},'',data.validated_options_job||null);
}
let GSD_PROVISIONAL=null;   // hasil tahap 1 yang sedang ditampilkan (VALID PROVISIONAL)
let GSD_EXACT_BLOCKED=null; // hasil exact yang ditolak release gate (untuk ditinjau)
function gsdProvisionalBanner(data){
  const rs=document.getElementById('result-summary'); if(!rs) return;
  const ii=(data&&data.info)||{}; const c=ii['Total Cost (USD)'];
  const oldB=document.getElementById('prov-banner'); if(oldB) oldB.remove();
  const fam=/keluarga/.test(String((data.provisional_basis||{}).commitment_from||''));
  const cF=v11Counters(data);
  const st={target:tlTarget(),elapsed:V11_RUN_T0!=null?(performance.now()-V11_RUN_T0)/1000:0,evaluated:cF?cF.candidates_checked:0,valid:cF?cF.candidates_valid:0,
    cp:ii['Cost Production (USD/MWh)'],checks_ok:true,proven:false};
  tlBanner('VALID PROVISIONAL — EXACT COST OPTIMIZATION IN PROGRESS','amber',st,
    (fam?'Kandidat valid terbaik ruang kandidat exact yang sudah dievaluasi':'Commitment hasil final terakhir dipakai ulang')+'; seluruh hard constraint, '
    +'PLN Export 48/48, gas window, reserve dan Bus Flow valid. Total Cost '+fmt(c,2)+' USD <u>belum</u> dibuktikan terendah. '
    +'Publish Final dibuka setelah optimasi exact selesai.'+v11AuditExtra(data),'prov-banner');
}
/* Perbandingan provisional -> exact: commitment, dispatch, dan biaya. */
function gsdProvisionalDiff(prov,fin){
  const U=['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10','S1','S2','S3','BB1','BB2'];
  const pr=(prov.data||[]), fr=(fin.data||[]); let rowsDiff=0; const com=[];
  U.forEach(u=>{ let a=0,b=0; for(let i=0;i<48;i++){ if(+((pr[i]||{})[u]||0)>0.01)a++; if(+((fr[i]||{})[u]||0)>0.01)b++; }
    if(a!==b) com.push(u+' '+a+'→'+b+' row'); });
  for(let i=0;i<48;i++){ const a=pr[i]||{}, b=fr[i]||{}; if(U.some(u=>Math.abs((+a[u]||0)-(+b[u]||0))>1e-6)) rowsDiff++; }
  const cp=+((prov.info||{})['Total Cost (USD)']||0), cf=+((fin.info||{})['Total Cost (USD)']||0);
  const pp=+((prov.info||{})['Cost Production (USD/MWh)']||0), pf=+((fin.info||{})['Cost Production (USD/MWh)']||0);
  const sama=(rowsDiff===0&&!com.length);
  const incF=((fin.info||{})['Incremental Recompute']||{}).applied===true; const siapa=incF?'recompute inkremental':'optimasi exact';
  return {sama,html: sama
    ? '<b>FINAL OPTIMAL</b> — '+siapa+' memilih rencana yang sama dengan hasil provisional.'
    : '<b>FINAL OPTIMAL</b> — '+siapa+' mengganti hasil provisional. Commitment: '
      +(com.length?gsfEsc(com.join(', ')):'sama')+'; dispatch berubah pada '+rowsDiff+' dari 48 row; '
      +'Total Cost '+fmt(cp,2)+' → '+fmt(cf,2)+' USD; Cost Production '+fmt(pp,4)+' → '+fmt(pf,4)+' USD/MWh.'};
}
function finalizeSimulationUI(payload,data,messageHtml){
  if(GSD_PROVISIONAL && !gsdContractSaysNotFinal(data)){
    const d=gsdProvisionalDiff(GSD_PROVISIONAL.data,data); GSD_PROVISIONAL=null;
    messageHtml='<span style="color:#166534">'+d.html+'</span>'+(messageHtml?'<br>'+messageHtml:'');
  } else if(GSD_PROVISIONAL && gsdContractSaysNotFinal(data) && !data.shortage_decision) {
    /* Optimasi exact selesai tetapi rencana pemenangnya tidak lolos release gate. Rencana VALID
     * PROVISIONAL tetap ditampilkan (terkunci) — rencana yang valid tidak diganti rencana yang
     * ditolak — dan alasan penolakan exact dinyatakan apa adanya. */
    const d=gsdProvisionalDiff(GSD_PROVISIONAL.data,data);
    const rg=data.release_gate||{}; const why=(rg.blocking_reasons||[]).join(', ')||String(data.preliminary_reason||'');
    /* Diagnostik gate (diambil dari kandidat Copilot, tanpa field Change Over): status hard, tipe pelanggaran, status ekonomi. */
    const ar=data.simulation_acceptance_review||data.acceptance_review||{}; const hv=ar.hard_validation||{}; const er=ar.economic_review||{};
    const vtypes=Object.keys(hv.types||{}).map(k=>k+':'+hv.types[k]).join(', ');
    const diag=((hv.status&&hv.status!=='PASS')||er.status==='REVIEW_NOT_PROVEN')
      ?(' · Detail: hard='+String(hv.status||'-')+(vtypes?', violations='+vtypes:'')+', economic='+String(er.status||'-')+', evaluated='+String(er.candidates_evaluated??'-')+', eligible='+String(er.eligible_candidates??'-')):'';
    const viol=((data.simulation_acceptance_review||{}).hard_validation||{}).violations||[];
    const v0=viol.length?(Array.isArray(viol[0])?String(viol[0][1]||viol[0][0]):JSON.stringify(viol[0])):'';
    GSD_EXACT_BLOCKED=data;
    const rm=document.getElementById('run-msg');
    if(rm) rm.innerHTML='<span style="color:#b45309"><b>VALID PROVISIONAL</b> tetap ditampilkan. Optimasi exact selesai, tetapi '
      +'rencana pemenangnya <b>tidak lolos release gate</b> ('+gsfEsc(why)+(v0?': '+gsfEsc(v0.slice(0,160)):'')+').'+gsfEsc(diag)+' '
      +'Perbandingan dengan exact: '+d.html.replace('<b>FINAL OPTIMAL</b> — ','')+' Publish Final tetap terkunci.</span>';
    if(typeof ppmClose==='function') ppmClose();
    return;
  } else if(GSD_PROVISIONAL && gsdContractSaysNotFinal(data)) {
    /* Hasil exact memerlukan keputusan operator (mis. Gas Shortage): alur yang sudah ada dipakai apa
     * adanya; operator diberi tahu bahwa hasil provisional digantikan hasil exact ini. */
    const d=gsdProvisionalDiff(GSD_PROVISIONAL.data,data); GSD_PROVISIONAL=null;
    messageHtml='<span style="color:#b45309">Optimasi exact selesai dan menggantikan hasil VALID PROVISIONAL: '
      +d.html.replace('<b>FINAL OPTIMAL</b> — ','')+'</span>'+(messageHtml?'<br>'+messageHtml:'');
  }
  /* PERTAHANAN BERLAPIS: satu-satunya pintu menuju OUTPUT memeriksa kontraknya sendiri. Bila
   * pemanggil keliru membawa hasil non-final ke sini, hasilnya DITAHAN, bukan diterbitkan. */
  if(gsdContractSaysNotFinal(data)){
    gsdHandleNonFinalResult(payload,data,messageHtml);
    return;
  }
  /* Sampai di sini berarti ada HASIL FINAL: gerbang keputusan dibuka kembali. */
  try{ TL_FINAL_TOKEN=RUN_SEQ[runBranchKey()]; }catch(e){}
  if(typeof ppmClose==='function') ppmClose();
  gsdReleaseGate();
  INPUT=payload;
  OUTPUT=data;
  dpSnapshotCurrent();
  renderResult(data);
  refreshOverview();
  refreshPills();
  refreshGasDecision();
  showSimulationDataResult();
  const rm=document.getElementById('run-msg');
  if(rm){ if(messageHtml!=null)rm.innerHTML=messageHtml; else rm.textContent='Done.'; }
  /* V11 SUMMARY (lima field) untuk setiap hasil FINAL yang belum diringkas jalur Target Selesai: Maximum Review,
   * hasil exact setelah Gas Shortage tervalidasi, dan rerun bahan bakar. Bukti teknis (ruang kandidat, status
   * constraints, global optimum, total cost, audit V11) dipindah ke panel "Detail audit" terpisah. */
  try{
    if(data && data.time_limited!==true && !V11_SUM_DONE){
      V11_SUM_DONE=true;
      const ii=data.info||{}; const gcr=ii['Global Commitment Review']||{}; const sp=ii['Exact Candidate Space']||null;
      const spOk=(!sp||sp.family_complete!==false)&&!(data.tl_pool&&data.tl_pool.better===true);
      const incR=ii['Incremental Recompute']; const isInc=!!(incR&&incR.applied===true);
      const cF=v11Counters(data);
      const st={target:tlTarget(),elapsed:V11_RUN_T0!=null?(performance.now()-V11_RUN_T0)/1000:0,
        evaluated:cF?cF.candidates_checked:(gcr.candidates_evaluated||0),valid:cF?cF.candidates_valid:0,
        cp:ii['Cost Production (USD/MWh)'],checks_ok:true,proven:spOk};
      let det=(isInc?('Recompute inkremental selesai dalam '+fmt(incR.wall_s,1)+' s ('+(incR.rows_recomputed)+' row dihitung ulang, '+(incR.rows_reused_identical)+' row dipakai ulang; '+(incR.candidates_evaluated)+' kandidat pesaing dievaluasi)'):'Optimasi exact selesai')
        +(gcr.candidates_total!=null?' ('+gcr.candidates_evaluated+'/'+gcr.candidates_total+' kandidat comparator dievaluasi)':'')
        +(sp?(isInc?'; ruang kandidat inkremental: commitment final terakhir + '+Math.max(0,(sp.family_nodes||1)-1)+' kandidat ruang exact sebelumnya ('+(sp.far_candidates_excluded||0)+' kandidat lain tidak terjangkau perubahan ini); pemenang: '+tlWinnerLabel(sp)
                :'; ruang kandidat exact: pipeline + keluarga commitment '+(sp.family_nodes||0)+' node + '+(sp.registry_valid_candidates!=null?sp.registry_valid_candidates:(sp.family_valid||0))+' kandidat valid terdaftar; pemenang: '+tlWinnerLabel(sp)):'')
        +'; ruang kandidat '+(isInc?'inkremental':'exact')+(spOk?' lengkap':' belum lengkap')+'.<br>'+v11StatusDetail(data);
      let title='FINAL OPTIMAL', color='green';
      /* Kandidat valid yang lebih murah dari Target Selesai untuk state ini TIDAK disembunyikan:
       * hasil exact tetap FINAL, tetapi selisihnya dinyatakan apa adanya. */
      const tp=data.tl_pool;
      if(tp && tp.better===true){
        det+='<br><span style="color:#b45309">PERHATIAN: Target Selesai sebelumnya menemukan kandidat constraint-valid dengan Cost Production <b>'
          +fmt(tp.cost_production,4)+' USD/MWh</b> (lebih rendah dari exact '+fmt(tp.exact_cost_production,4)
          +(tp.units_off&&tp.units_off.length?'; unit mati sepanjang hari: '+gsfEsc(tp.units_off.join(', ')):'')
          +(tp.v9_path_independent?'). Kandidat itu berasal dari pencarian berbatas waktu dan TIDAK menjadi pemenang FINAL: FINAL ditetapkan prosedur kanonik yang sama untuk setiap jalur, sehingga hasilnya tidak bergantung pada riwayat run.'
            :'). Kandidat itu ditemukan sesudah hasil exact ini disimpan; klik Run (Maximum Review) sekali lagi untuk memasukkannya ke comparator exact.')+'</span>';
        title='FINAL OPTIMAL — CATATAN KANDIDAT LEBIH MURAH'; color='amber';
      }
      tlBanner(title,color,st,det+v11AuditExtra(data));
      if(rm && !/v11-rm/.test(rm.innerHTML)) rm.innerHTML+=(rm.innerHTML?' ':'')+'<span class="v11-rm" style="color:#166534">· <b>FINAL OPTIMAL</b> — '+tlSummaryHtml(st)+'</span>';
    }
  }catch(e){ try{ console.error('V11 SUMMARY',e); }catch(_){} }
}
/* =============================================================================================
 *  TARGET SELESAI (< 15 … < 60 detik).
 *
 *  Job exact dan pencarian kandidat valid berjalan bersamaan. Selama batas belum tercapai, bila
 *  exact selesai dan hasilnya final serta tidak dikalahkan kandidat lain, hasilnya langsung tampil
 *  sebagai FINAL OPTIMAL. Tepat pada batas, kandidat constraint-valid dengan Cost Production
 *  terendah yang sudah ditemukan ditampilkan sebagai BEST VALID WITHIN TIME LIMIT (TIME-LIMITED
 *  VALID PLAN, Global optimum proven: NO) dan job exact dihentikan. Kandidat invalid tidak pernah
 *  ditampilkan: bila belum ada kandidat valid, yang tampil adalah NO VALID RESULT WITHIN TIME
 *  LIMIT dan job exact dibiarkan selesai supaya alur Gas Shortage tetap berjalan seperti biasa.
 * ============================================================================================= */
function tlTarget(){ const s=document.getElementById('f-tl-target'); const v=s?String(s.value):'fast'; return ['fast','15','25','35','45','55','60'].indexOf(v)>=0?v:'max'; }
function tlFmtS(x){ return (Math.round((+x||0)*10)/10).toFixed(1).replace('.',','); }
/* V11 SUMMARY: satu sumber penghitung (backend 'V11 Candidate Counters' / tl_best.counters) dengan invarian UI:
 * CP tersedia + constraints PASS -> Kandidat valid >= 1; tanpa kandidat valid -> Valid 0 dan CP kosong; valid <= diperiksa. */
function v11Norm(st){
  const o=Object.assign({},st||{}); let va=Math.max(0,Math.floor(+o.valid||0)), ev=Math.max(0,Math.floor(+o.evaluated||0));
  const hasCp=o.cp!=null&&o.cp!==''&&isFinite(+o.cp)&&o.checks_ok!==false;
  if(hasCp&&va<1) va=1;
  if(!hasCp||va===0){ o.cp=null; }
  if(ev<va) ev=va; o.valid=va; o.evaluated=ev; return o;
}
function v11TargetLabel(t){ return t==='fast'?'Fastest - Default':((t==null||t==='max')?'Maximum Review':'&lt; '+t+' detik'); }
function tlSummaryHtml(st){
  const s=v11Norm(st);
  return 'Target waktu '+v11TargetLabel(s.target)+' | Waktu aktual '+tlFmtS(s.elapsed)+' detik | Kandidat diperiksa '+s.evaluated+' | Kandidat valid '+s.valid+' | Cost Production '+(s.cp!=null?fmt(s.cp,4)+' USD/MWh':'—');
}
/* SUMMARY kuning: tepat lima field. Status dan uraian teknis ada di panel "Detail audit" terpisah. */
function tlBanner(title,color,st,detailHtml,wrapId){
  const rs=document.getElementById('result-summary'); if(!rs) return;
  ['tl-banner','tl-audit','prov-banner'].forEach(id=>{ const old=document.getElementById(id); if(old) old.remove(); });
  const s=v11Norm(st);
  const cell=(lab,val)=>'<div class="v11-sum-c"><span class="v11-sum-l">'+lab+'</span><b class="v11-sum-v">'+val+'</b></div>';
  const sum='<div id="tl-banner" class="v11-sum" data-target="'+gsfEsc(String(s.target==null?'max':s.target))+'" data-elapsed="'+(+s.elapsed||0).toFixed(2)
    +'" data-checked="'+s.evaluated+'" data-valid="'+s.valid+'" data-cp="'+(s.cp!=null?(+s.cp).toFixed(4):'')+'">'
    +cell('Target waktu',v11TargetLabel(s.target))+cell('Waktu aktual',tlFmtS(s.elapsed)+' detik')+cell('Kandidat diperiksa',String(s.evaluated))
    +cell('Kandidat valid',String(s.valid))+cell('Cost Production',s.cp!=null?fmt(s.cp,4)+' USD/MWh':'—')+'</div>';
  const aud='<details id="tl-audit" class="v11-audit" data-status="'+gsfEsc(String(color||''))+'"><summary>Detail audit — '+gsfEsc(String(title||''))+'</summary><div class="v11-audit-b">'
    +'Status constraints: <b>'+(st&&st.checks_ok==null?'—':(st&&st.checks_ok?'PASS':'FAIL'))+'</b> · Global optimum proven: <b>'+(st&&st.proven?'YES':'NO')+'</b>'
    +(detailHtml?'<br>'+detailHtml:'')+'</div></details>';
  /* wrapId: penanda status (mis. 'prov-banner' selama VALID PROVISIONAL) membungkus SUMMARY + panel audit. */
  rs.insertAdjacentHTML('afterbegin',wrapId?'<div id="'+gsfEsc(String(wrapId))+'">'+sum+aud+'</div>':sum+aud);
}
function v11Counters(out){ const c=((out||{}).info||{})['V11 Candidate Counters']; return (c&&typeof c==='object')?c:null; }
/* V11 panel audit (bukan SUMMARY): ringkasan lima audit V11 yang dilampirkan engine pada hasil akhir.
 * Hanya membaca OUTPUT.info — tidak mengubah hasil engine. Selalu mengembalikan string (tidak pernah melempar). */
function v11AuditExtra(out){
  try{
    const ii=((out||{}).info)||{}; const E=x=>gsfEsc(String(x==null?'—':x)); const L=[];
    const n=(x,d)=>(x==null||x===''||!isFinite(+x))?'—':fmt(+x,d);
    const c=ii['V11 Candidate Counters'];
    if(c&&typeof c==='object') L.push('<b>V11 Candidate Counters</b>: diperiksa '+E(c.candidates_checked)+' = full-run '+E(c.candidates_full_run)
      +' + gugur Tier 1 '+E(c.candidates_screened_out)+'; valid '+E(c.candidates_valid)+'; kandidat terbaik termasuk valid: <b>'+(c.best_candidate_in_valid?'YA':'TIDAK')+'</b>');
    const b=ii['V11 Candidate Comparison'];
    if(b&&typeof b==='object') L.push('<b>V11 Candidate Comparison</b>: CP minimum '+n(b.cp_min,4)+' USD/MWh; band 0,2 % s.d. '+n(b.band_upper,4)
      +'; kandidat valid '+E(b.candidates_valid)+', di dalam band '+E(b.candidates_in_band)+'; pemenang '+E(b.winner)+' (CP '+n(b.winner_cp,4)+', Heat Rate '+n(b.winner_heat_rate,2)+' BTU/kWh)');
    const w=ii['V11 Consolidation Sweep'];
    if(w&&typeof w==='object') L.push('<b>V11 Consolidation Sweep</b>: '+E(w.status||'—')+'; row fragmentation '+E(w.fragmentation_rows)+'; kandidat dibangkitkan '+E(w.generated)
      +', gugur Tier 1 '+E(w.screened_tier1)+', full-run '+E(w.full_run)+', valid '+E(w.valid)+(w.wall_s!=null?'; '+n(w.wall_s,2)+' s':''));
    const f=ii['V11 Low Load Fragmentation Audit'];
    if(f&&typeof f==='object'){ const ur=f.unit_rows&&typeof f.unit_rows==='object'?Object.keys(f.unit_rows).map(u=>u+' row '+(f.unit_rows[u]||[]).join(',')).join('; '):'';
      L.push('<b>V11 Low Load Fragmentation Audit</b>: <b>'+E(f.status)+'</b>; row ditandai '+E(f.rows_flagged)+', temuan '+E(f.findings)+', tanpa alasan '+E(f.unresolved)+(ur?' ('+E(ur)+')':'')); }
    const p=ii['V11 CP Audit'];
    if(p&&typeof p==='object') L.push('<b>V11 CP Audit</b>: <b>'+E(p.status)+'</b>; CP '+n(p.cost_production,4)+' = Total Cost / Net '+n(p.total_cost_over_net,4)
      +'; Heat Rate JBBK+MM2100 '+n(p.heat_rate_jbbk_mm,2)+' BTU/kWh; akun bahan bakar berharga '+((p.fuel_accounts||[]).filter(a=>a&&a.priced).length)+'/'+((p.fuel_accounts||[]).length)
      +((p.issues||[]).length?'; isu: '+E((p.issues||[]).join(', ')):''));
    /* V12: comparator CP (minimum absolut vs pemenang), hasil akhir LOW_LOAD_FRAGMENTATION, audit merit dispatch, sertifikat reuse */
    const r=ii['V12 CP Report'];
    if(r&&typeof r==='object'&&r.status==='OK') L.push('<b>V12 CP Report</b>: CP minimum absolut '+n(r.absolute_cp_min,4)+' ('+E(r.absolute_cp_min_candidate)+'); CP pemenang '+n(r.winner_cp,4)
      +' (selisih '+n(r.delta_winner_vs_min_usd_mwh,4)+' USD/MWh = '+n(r.delta_winner_vs_min_pct,4)+' %); Heat Rate pemenang '+n(r.winner_heat_rate,2)+', minimum di band '+n(r.min_heat_rate_in_band,2)+' BTU/kWh; '+E(r.tie_break_reason));
    const o=ii['V12 Low Load Fragmentation Outcome'];
    if(o&&typeof o==='object'){ const k=o.counts||{}; L.push('<b>V12 LOW_LOAD_FRAGMENTATION</b>: <b>'+E(o.status)+'</b>; RESOLVED_BY_CONSOLIDATION '+E(k.RESOLVED_BY_CONSOLIDATION)+', RESOLVED_BY_STOP '+E(k.RESOLVED_BY_STOP)
      +', PASS_WITH_REASON '+E(k.PASS_WITH_REASON)+', FAIL '+E(k.FAIL)); }
    const d=ii['V12 Dispatch Merit Audit'];
    if(d&&typeof d==='object'&&d.c2_start_with_headroom) L.push('<b>V12 Dispatch Merit Audit</b>: <b>'+E(d.status)+'</b>; '+E(d.rows_audited)+' row x unit ('+E(d.unit_row_records)+' catatan legal headroom); start dengan headroom unit prioritas tinggi: '
      +E(d.c2_start_with_headroom.findings)+' (tanpa bukti '+E(d.c2_start_with_headroom.fail)+'); stop row legal pertama: '+E(d.c3_first_legal_stop.intervals)+' interval (belum diuji '+E(d.c3_first_legal_stop.fail)+'); headroom prioritas (C1) '
      +E(d.c1_merit_headroom.findings)+' temuan, beralasan '+E(d.c1_merit_headroom.with_reason)
      +(d.c4_cross_group_priority?'; lintas grup prioritas (C4) '+E(d.c4_cross_group_priority.findings)+' temuan: status paksa '+E(d.c4_cross_group_priority.status_forced)+', akun MM2100 terpakai penuh '+E(d.c4_cross_group_priority.mm2100_account_full)+', tanpa alasan '+E(d.c4_cross_group_priority.fail):''));
    const q=ii['V12 Reuse Certificate'];
    if(q&&typeof q==='object'&&q.schema) L.push('<b>V12 Reuse Certificate</b>: rute '+E(q.route)+'; state '+E(q.numerical_state_signature).slice(0,12)+'…; dispatch fisik '+E(q.physical_dispatch_signature)+'; universe '+E(q.candidate_universe_signature)+'; '+E(q.proof_version));
    return L.length?'<br>'+L.join('<br>'):'';
  }catch(e){ return ''; }
}
/* V11 status akhir (untuk panel audit): constraints, Export, residual shortage, Unit Priority. */
function v11StatusDetail(out){
  try{
    const ii=((out||{}).info)||{}; const rows=((out||{}).data)||[]; const E=x=>gsfEsc(String(x==null?'—':x));
    const up=ii['Unit Priority Compliance']||ii['V8 Priority Review']||{}; const ups=(up&&typeof up==='object')?(up.status||up.final_status||up.compliance):null;
    return '48 rows: <b>'+(rows.length===48?'YA':'TIDAK ('+rows.length+')')+'</b> · PLN Export: <b>'+E(ii['PLN Export Compliance'])+'</b> · Residual shortage '
      +fmt(+(ii['Residual Gas Shortage (BBTUD)']||0),4)+' BBTUD · Total Cost '+fmt(ii['Total Cost (USD)'],2)+' USD · Heat Rate '+fmt(ii['JBBK MM Heat Rate (BTU/kWh)'],2)+' BTU/kWh'
      +(ups?' · Unit Priority: <b>'+E(ups)+'</b>':'');
  }catch(e){ return ''; }
}
async function tlJson(url,opts,toMs,nTry){
  /* Permintaan ringan (kolam kandidat) diberi batas waktu dan diulang: server yang sedang memegang
   * request berat pada koneksi yang sama tidak boleh menahan tampilan hasil melewati batas. */
  const tries=toMs?(nTry||3):1;
  for(let k=0;k<tries;k++){
    const ctl=toMs?new AbortController():null; const tm=toMs?setTimeout(()=>ctl.abort(),toMs):null;
    try{
      const r=await fetch(url,Object.assign({cache:'no-store'},opts||{},ctl?{signal:ctl.signal}:{})); const t=await r.text();
      if(tm) clearTimeout(tm);
      try{ return JSON.parse(t.replace(/^\uFEFF/,'')); }catch(e){ return {ok:false,error:'NON_JSON',status:r.status,raw:t.slice(0,160)}; }
    }catch(e){ if(tm) clearTimeout(tm); if(k===tries-1) return {ok:false,error:String(e&&e.message||e)}; }
  }
  return {ok:false,error:'TIMEOUT'};
}
async function runTimeLimited(payload, T){
  const branch=runBranchKey();
  const myToken=++RUN_SEQ[branch];
  const myRev=++STATE_REVISION[branch];
  const reqId=branch+'-'+myRev+'-'+Date.now().toString(36);
  payload._request_id=reqId; payload._state_revision=myRev; payload._context=branch;
  GSD_PROVISIONAL=null;
  try{ ASYNC_REVIEW[branch]=null; }catch(e){}
  const t0=performance.now(); const el=()=>(performance.now()-t0)/1000; V11_RUN_T0=t0; V11_SUM_DONE=false;
  const btn=$('btn-run'); const old=btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Running…';
  const rm=$('run-msg'); if(rm) rm.textContent='';
  const stale=()=>myToken!==RUN_SEQ[branch];
  const done=()=>{ const b=$('btn-run'); if(b){ b.disabled=false; b.innerHTML=old; } };
  const stopQuick=()=>{ tlJson('run.php?mode=tl_stop&rid='+encodeURIComponent(reqId),null,1500).catch(()=>{}); };
  const abortExact=id=>{ tlJson('run.php?mode=job_cancel&abort=1&job='+encodeURIComponent(id),null,1500).catch(()=>{}); };
  let data=null;
  try{
    data=await tlJson('run.php?mode=run&tl=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)},5000);
  }catch(e){ data={ok:false,error:String(e&&e.message||e)}; }
  if(stale()) return;
  if(v3AutosaveOutcome(payload,data)===false){ done(); return; }
  const job=data&&data.async_job;
  if(!job||!job.job_id){
    done();
    if(rm) rm.innerHTML='<span style="color:#c0392b">Run gagal dimulai: '+gsfEsc(String((data&&(data.message||data.error))||'respons tidak dikenal'))+'</span>';
    return;
  }
  /* Perhitungan exact dan pencarian kandidat valid dipicu bersamaan; keduanya tidak ditunggu. */
  if(job.exec_token){ fetch('run.php?mode=job_exec&job='+encodeURIComponent(job.job_id)+'&token='+encodeURIComponent(job.exec_token),{cache:'no-store'}).catch(()=>{});
    ppFireHelpers(job.job_id,job.exec_token,job.helpers); }
  /* V3: tidak ada pencarian Target Selesai paralel; kandidat valid berasal dari job pemilik. */
  const bestUrl='run.php?mode=tl_best&job='+encodeURIComponent(job.job_id)+'&rid='+encodeURIComponent(reqId);
  let b=null;
  /* V12: output kandidat valid terbaik diambil lebih awal setiap kali Cost Production terbaik berubah, sehingga
   * pembacaan akhir pada batas waktu yang lambat (server sibuk, output besar) tidak menghilangkan hasil valid. */
  let bOut=null, bOutCp=null, bLast=null;
  while(true){
    await new Promise(r=>setTimeout(r,350));
    if(stale()){ stopQuick(); return; }
    try{ b=await tlJson(bestUrl,null,Math.max(200,Math.min(1200,(T-1.2-el())*1000)),1); }catch(e){ b=null; }
    if(stale()){ stopQuick(); return; }
    if(b&&b.ok) bLast=b;
    if(b&&b.ok&&b.best&&b.best.cost_production!=null&&b.best.cost_production!==bOutCp&&!b.exact_final&&T-1.2-el()>0.4){
      let r=null; try{ r=await tlJson(bestUrl+'&with_output=1',null,Math.max(300,Math.min(2500,(T-1.2-el())*1000)),1); }catch(e){ r=null; }
      if(stale()){ stopQuick(); return; }
      if(r&&r.ok&&r.output&&Array.isArray(r.output.data)&&r.output.data.length===48){ bOut=r; bOutCp=r.best?r.best.cost_production:b.best.cost_production; }
    }
    if(b&&b.ok){
      if(rm) rm.innerHTML='<span style="color:#1763d6">Target &lt; '+T+' detik — '+tlFmtS(el())+' s · kandidat diperiksa '
        +(b.evaluated_total||0)+' · valid '+(b.valid_total||0)
        +(b.best&&b.best.cost_production!=null?' · terbaik Cost Production '+fmt(b.best.cost_production,4):'')+'</span>';
      /* Exact selesai lebih dahulu dan tidak dikalahkan kandidat mana pun: FINAL OPTIMAL. */
      if(b.exact_final && !b.pool_better_than_exact){
        stopQuick();
        const sj=await tlJson('run.php?mode=job_poll&job='+encodeURIComponent(job.job_id)+'&input_hash='+encodeURIComponent(job.input_hash||''),null,3000);
        if(stale()) return;
        const out=sj&&sj.result&&sj.result.output;
        if(out){
          done();
          const cF=v11Counters(out);
          const st={target:T,elapsed:el(),evaluated:cF?cF.candidates_checked:b.evaluated_total,valid:cF?cF.candidates_valid:b.valid_total,
            cp:(out.info||{})['Cost Production (USD/MWh)'],checks_ok:true,proven:true};
          V11_SUM_DONE=true;
          finalizeSimulationUI(payload,out,'<span style="color:#166534"><b>FINAL OPTIMAL</b> — '+tlSummaryHtml(st)+'</span>');
          tlBanner('FINAL OPTIMAL','green',st,'Optimasi exact selesai dalam batas waktu.<br>'+v11StatusDetail(out)+v11AuditExtra(out));
          return;
        }
      }
      /* Exact selesai TANPA hasil final (mis. Gas Shortage) dan belum ada kandidat valid:
       * alur keputusan bahan bakar yang sudah ada dipakai apa adanya. */
      if(b.job_status==='DONE' && !b.exact_final && !b.best){
        stopQuick(); done();
        adoptBackendAsyncJob(payload,branch,myToken,job).catch(e=>{ if(!stale()&&rm) rm.innerHTML='<span style="color:#c0392b">'+gsfEsc(String(e.message||e))+'</span>'; });
        return;
      }
    }
    if(el()>=T-1.2) break;
  }
  /* BATAS WAKTU TERCAPAI. */
  stopQuick();
  /* Pembacaan akhir dibatasi sisa waktu sampai batas: tiap percobaan memakai koneksi baru. */
  b=null;
  /* V12: dengan output cadangan, pembacaan akhir berhenti lebih awal agar render tetap sebelum batas. */
  const tEnd=bOut?T-0.9:T-0.25;
  while(el()<tEnd){
    const toMs=Math.max(150,(tEnd-el())*1000);
    let r=null; try{ r=await tlJson(bestUrl+'&with_output=1',null,toMs,1); }catch(e){ r=null; }
    if(r&&r.ok){ b=r; break; }
  }
  /* V12: pembacaan akhir gagal/tanpa output -> kandidat valid terbaik yang sudah diambil (counter dari poll terakhir). */
  if(!(b&&b.ok&&b.output)&&bOut){ b=Object.assign({},bOut,{evaluated_total:(bLast||bOut).evaluated_total,valid_total:(bLast||bOut).valid_total,counters:(bLast||bOut).counters}); }
  if(stale()){ stopQuick(); return; }
  stopQuick();
  const elapsed=el();
  if(b&&b.ok&&b.output&&Array.isArray(b.output.data)&&b.output.data.length===48){
    if(!b.exact_final) abortExact(job.job_id);
    const out=b.output; const ck=out.time_limited_checks||{};
    const st={target:T,elapsed:elapsed,evaluated:b.evaluated_total,valid:b.valid_total,
      cp:(out.info||{})['Cost Production (USD/MWh)'],checks_ok:Object.keys(ck).length>0&&Object.values(ck).every(v=>v===true),proven:false};
    if(typeof ppmClose==='function') ppmClose();
    INPUT=payload; OUTPUT=out; GSD_GATE=false; PRELIM=null; gsdApplyGate();
    try{ renderResult(out); refreshOverview(); refreshPills(); refreshGasDecision(); showSimulationDataResult(); }catch(e){}
    tlBanner('BEST VALID WITHIN TIME LIMIT — TIME-LIMITED VALID PLAN','amber',st,
      'Total Cost '+fmt((out.info||{})['Total Cost (USD)'],2)+' USD. Seluruh 48 row lolos hard constraint, PLN Export 48/48, window gas, reserve, Bus Flow, dan residual shortage 0. '
      +'Rencana ini <u>bukan</u> bukti global optimum: boleh dilihat dan diekspor; Publish Final terkunci (pilih Maximum Review untuk hasil final).'
      +(b.exact_final?' Optimasi exact juga selesai, tetapi kandidat ini lebih murah.':'')+v11AuditExtra(out));
    done();
    V11_SUM_DONE=true;
    if(rm) rm.innerHTML='<span style="color:#b45309"><b>BEST VALID WITHIN TIME LIMIT</b> — '+tlSummaryHtml(st)+'</span>';
    return;
  }
  /* Tidak ada kandidat valid: hasil invalid TIDAK ditampilkan, dan tidak ada worker yang dibiarkan
   * berjalan melewati batas waktu (Maximum Review menjalankannya sampai selesai). */
  abortExact(job.job_id);
  done();
  const st0={target:T,elapsed:elapsed,evaluated:b&&b.evaluated_total,valid:0,cp:null,checks_ok:null,proven:false}; V11_SUM_DONE=true;
  if(rm) rm.innerHTML='<span style="color:#c0392b"><b>NO VALID RESULT WITHIN TIME LIMIT</b> — '+tlSummaryHtml(st0)+'</span>';
  tlBanner('NO VALID RESULT WITHIN TIME LIMIT','amber',st0,'Belum ada kandidat yang lolos seluruh constraint; hasil invalid tidak ditampilkan. '
    +'Pilih <b>Maximum Review</b> untuk optimasi exact lengkap (termasuk analisis Gas Shortage bila diperlukan).');
}
/* V12 FASTEST - DEFAULT: selesai begitu kandidat FULLY VALID pertama tersedia. Server (tl_best&fast=1) memeriksa kandidat
 * terbaik kolam: 48 row, hard constraints + provenance PASS, review Unit Priority selesai, audit merit (legal headroom, start
 * prioritas rendah, stop row legal pertama) PASS, LOW_LOAD_FRAGMENTATION tidak unresolved, Change Over executed + overlap >= 3 row.
 * Bila FINAL exact selesai lebih dulu, alur runSimCore biasa yang menampilkannya (FINAL OPTIMAL). */
var FASTEST_CTX=null, FASTEST_TRACE=null;
/* Timestamp Fastest (detik sejak klik Run; server dan browser memakai jam epoch yang sama di mesin lokal) + durasi tiap tahap. */
function fastestTraceHtml(T){
  try{
    const K=[['run_click_ms','run_click'],['job_created_ms','job_created'],['first_candidate_complete_ms','first_candidate_complete'],['first_valid_claimed_ms','first_valid_claimed'],
      ['first_fully_valid_ms','first_fully_valid'],['snapshot_persisted_ms','snapshot_persisted'],['browser_received_snapshot_ms','browser_received_snapshot'],['render_start_ms','render_start'],
      ['render_done_ms','render_done'],['simulation_data_opened_ms','simulation_data_opened'],['exact_cancel_sent_ms','exact_cancel_sent'],['helpers_stopped_ms','helpers_stopped']];
    const t0=T.run_click_ms||0; let prev=null; const L=[];
    for(const [k,n] of K){ const v=T[k]; if(!v){ L.push(n+' —'); continue; } const s=(v-t0)/1000; L.push(n+' '+s.toFixed(2)+' s'+(prev!=null?' (+'+(s-prev).toFixed(2)+')':'')); prev=s; }
    const st=T.stages_s||{}; const rc=T.review_counterfactuals||{};
    return '<b>Fastest timestamp</b>: '+gsfEsc(L.join(' · '))+'<br>finalisasi merit: review Unit Priority '+gsfEsc(String(st.merit_review_unit_priority))+' s ('+gsfEsc(String(rc.simulated))+' counterfactual, '+gsfEsc(String(rc.rounds))+' putaran, '+gsfEsc(String(rc.helpers))+' pembantu), audit C1-C4/LLF/provenance '
      +gsfEsc(String(st.acceptance_audits_c1_c4_llf_provenance))+' s, gerbang+STG '+gsfEsc(String(st.fully_valid_gate_stg))+' s; tulis berkas: ulang '+gsfEsc(String((T.write_diag||{}).retries||0))+', gagal '+gsfEsc(String((T.write_diag||{}).failed||0));
  }catch(e){ return ''; }
}
function fastestStart(payload,branch,token,jobId){
  const c=FASTEST_CTX; if(!c||c.branch!==branch||c.token!==token||!jobId||c.jobs[jobId]) return;
  c.jobs[jobId]=1; fastestPoll(payload,branch,token,jobId).catch(()=>{});
}
async function fastestPoll(payload,branch,myToken,jobId){
  /* V12 FASTEST_RELEASE_READY. Urutan wajib: SNAPSHOT (server, atomik) -> FETCH -> RENDER -> SHOW SIMULATION DATA -> CANCEL EXACT.
   * Job ditandai lewat arm=1 (jalur pembuatan job apa pun); kandidat valid pertama membawa bukti merit (review Unit Priority, C1-C4,
   * STG, LLF, Change Over) saat masuk kolam. Tidak ada finalisasi kedua, tidak menunggu FINAL exact / kandidat berikutnya. */
  const url='run.php?mode=fast_ready&job='+encodeURIComponent(jobId); let claimSeen=false, myTok2=myToken;
  const T=FASTEST_TRACE={job:jobId,run_click_ms:V11_RUN_T0!=null?Math.round(performance.timeOrigin+V11_RUN_T0):null};
  fetch(url+'&arm=1',{cache:'no-store'}).catch(()=>{});            // tidak ditunggu
  while(!V11_SUM_DONE){
    await new Promise(r=>setTimeout(r,200));
    if(myTok2!==RUN_SEQ[branch] || V11_SUM_DONE) return;
    let r=null; try{ r=await tlJson(url,null,4000,1); }catch(e){ r=null; }
    if(myTok2!==RUN_SEQ[branch] || V11_SUM_DONE) return;
    if(!r||!r.ok) continue;
    if(r.claimed&&!claimSeen){ claimSeen=true; T.claim_seen_ms=Date.now(); RUN_SEQ[branch]++; myTok2=RUN_SEQ[branch];   // alur runSimCore berhenti; Fastest yang merilis
      try{ const fm=document.getElementById('fast-msg'); if(fm) fm.textContent='Fastest - Default — kandidat valid pertama ditemukan; bukti merit dibawa bersama kandidat…'; }catch(e){} }
    if(!claimSeen){ if(r.job_status==='DONE'||r.job_status==='FAILED'||r.job_status==='CANCELLED') return; continue; }   // FINAL exact lebih dulu: runSimCore
    if(!r.ready){ /* progres tetap hidup selama bukti merit kandidat pertama dihitung (bukan berhenti diam) */
      try{ const fm=document.getElementById('fast-msg'); if(fm) fm.textContent='Fastest - Default — kandidat valid pertama ditemukan ('+((T.claim_seen_ms-(T.run_click_ms||T.claim_seen_ms))/1000).toFixed(1).replace('.',',')+' s); membuktikan merit kandidat itu (counterfactual Unit Priority) · '+((Date.now()-(T.run_click_ms||Date.now()))/1000).toFixed(1).replace('.',',')+' s'; }catch(e){}
      continue; }
    T.browser_received_snapshot_ms=Date.now(); Object.assign(T,r.trace_server||{}); T.stages_s=r.stages_s||null; T.review_counterfactuals=r.review_counterfactuals||null; T.write_diag=r.write_diag||null;
    if(!(r.FASTEST_RELEASE_READY&&r.output&&Array.isArray(r.output.data)&&r.output.data.length===48)){
      try{ const fm=document.getElementById('fast-msg'); if(fm) fm.textContent='Fastest - Default — kandidat pertama gagal gerbang fully valid ('+gsfEsc(String(((r.fast||{}).reasons)||r.error||'?'))+'); melanjutkan pencarian'; }catch(e){}
      T.fallback={reasons:((r.fast||{}).reasons)||null,error:r.error||null,ready_flag:!!r.FASTEST_RELEASE_READY,rows:r.output&&r.output.data?r.output.data.length:null,stages_s:r.stages_s||null,fast:r.fast||null};
      tlJson('run.php?mode=job_cancel&abort=1&job='+encodeURIComponent(jobId),null,1500).catch(()=>{});
      runSimCore(payload,{fastest:true,noFastFinalize:true}); return; }
    const out=r.output; const cnt=r.counters||{};
    const st={target:'fast',elapsed:(Date.now()-(T.run_click_ms||Date.now()))/1000,evaluated:cnt.candidates_checked!=null?cnt.candidates_checked:1,
      valid:cnt.candidates_valid!=null?cnt.candidates_valid:1,cp:(out.info||{})['Cost Production (USD/MWh)'],checks_ok:true,proven:false};
    if(typeof ppmClose==='function') ppmClose();
    INPUT=payload; OUTPUT=out; GSD_GATE=false; PRELIM=null; gsdApplyGate(); V11_SUM_DONE=true;
    T.render_start_ms=Date.now();
    try{ renderResult(out); refreshOverview(); refreshPills(); refreshGasDecision(); }catch(e){ T.render_error=String(e&&e.message||e); }
    T.render_done_ms=Date.now();
    try{ showSimulationDataResult(); }catch(e){}
    await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));
    T.simulation_data_opened_ms=Date.now();
    const f=r.fast||{}; st.elapsed=(T.simulation_data_opened_ms-(T.run_click_ms||T.simulation_data_opened_ms))/1000;
    out.info=out.info||{}; out.info['V12 Fastest Timing']=T;
    tlBanner('FASTEST VALID PLAN','amber',st,'Kandidat fully valid pertama (FASTEST_RELEASE_READY): 48 row, hard constraints PASS, provenance PASS, audit merit '+gsfEsc(String(f.merit_audit))+' (C4 tanpa alasan '+gsfEsc(String((f.c4||{}).fail))+'), STG = calc_stg '
      +gsfEsc(String((f.stg_proof||{}).equal_calc))+'/'+gsfEsc(String((f.stg_proof||{}).rows_x_stg))+', LOW_LOAD_FRAGMENTATION '+gsfEsc(String(f.llf))+(f.change_over?', Change Over executed + overlap '+f.change_over.overlap_rows+' row':'')
      +'. Pencarian exact dihentikan; bukan CP minimum global — pilih Maximum Review untuk hasil final.'+v11AuditExtra(out)+'<div id="fast-trace">'+fastestTraceHtml(T)+'</div>');
    const b2=$('btn-run'); if(b2){ b2.disabled=false; b2.innerHTML='▶ Run simulation'; }
    try{ const fm=document.getElementById('fast-msg'); if(fm) fm.textContent=''; }catch(e){}
    const rm=$('run-msg'); if(rm) rm.innerHTML='<span style="color:#b45309"><b>FASTEST VALID PLAN</b> — '+tlSummaryHtml(st)+'</span>';
    /* baru SETELAH hasil tampil di Simulation Data: batalkan job exact/pembantu */
    T.exact_cancel_sent_ms=Date.now();
    tlJson('run.php?mode=job_cancel&abort=1&job='+encodeURIComponent(jobId),null,3000).catch(()=>{});
    try{ const ft=document.getElementById('fast-trace'); if(ft) ft.innerHTML=fastestTraceHtml(T); }catch(e){}
    /* helpers_stopped: pembantu berhenti sesudah pembatalan (hanya pencatatan; hasil sudah tampil) */
    for(let q=0;q<40;q++){ await new Promise(r=>setTimeout(r,250)); let h=null; try{ h=await tlJson(url,null,2000,1); }catch(e){ h=null; }
      if(h&&h.ok&&Number(h.helpers_alive||0)===0){ T.helpers_stopped_ms=Date.now(); break; } }
    try{ const ft=document.getElementById('fast-trace'); if(ft) ft.innerHTML=fastestTraceHtml(T); }catch(e){}
    return;
  }
}
async function runSimCore(payload, opts){
  opts=opts||{};
  const branch=runBranchKey();                            // §3.8: identifier terpisah Plan vs Monitoring
  const myToken=++RUN_SEQ[branch];                        // §3.6: revisi request ini
  const myRev=++STATE_REVISION[branch];                   // §9: state_revision per context
  const reqId=branch+'-'+myRev+'-'+Date.now().toString(36);
  payload._request_id=reqId; payload._state_revision=myRev; payload._context=branch;   // §9 request snapshot meta
  GSD_PROVISIONAL=null;                                   // provisional milik run sebelumnya tidak berlaku lagi
  V11_RUN_T0=performance.now(); V11_SUM_DONE=false;       // V11: Waktu aktual SUMMARY diukur dari klik Run ini
  /* V3: tidak ada pencarian paralel — satu job pemilik state ini menghitung seluruhnya. */
  const btn=$('btn-run'); const old=btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Running…'; $('run-msg').textContent='';
  /* MODAL PEMBLOKIR DIBUKA SEBELUM fetch, BUKAN SESUDAH RESPONSE. Ini satu operasi DOM sinkron,
   * sehingga jaraknya dari klik selalu jauh di bawah satu detik — tidak bergantung pada jaringan,
   * pada berat rencana, atau pada apakah rencana ini akan berakhir shortage. */
  FASTEST_CTX=(opts.fastest&&!opts.noFastFinalize)?{branch,token:myToken,jobs:{}}:null;
  /* V12 Fastest - Default: tanpa popup progress besar; progres cukup teks biru kecil. */
  if(opts.fastest){ const t0f=performance.now();
    let fm=document.getElementById('fast-msg'); const rm0=$('run-msg');
    if(!fm&&rm0&&rm0.parentNode){ fm=document.createElement('span'); fm.id='fast-msg'; fm.className='hint'; fm.style.cssText='color:#1763d6;font-size:12px;margin-right:8px'; rm0.parentNode.insertBefore(fm,rm0); }
    const tk=setInterval(()=>{ const f=document.getElementById('fast-msg'); const rmT=(($('run-msg')||{}).textContent||''); if(myToken!==RUN_SEQ[branch]||V11_SUM_DONE||performance.now()-t0f>1800000||/FINAL|FASTEST|Gas Shortage|NO VALID|gagal|BELUM final|Kekurangan|shortage/i.test(rmT)){ clearInterval(tk); if(f) f.textContent=''; return; }
      if(f) f.textContent='Fastest - Default — mencari kandidat fully valid pertama · '+tlFmtS((performance.now()-t0f)/1000)+' s'; },400); }
  else ppmOpen(reqId,()=>{
    /* Membatalkan = menaikkan nomor urut run. Response yang datang setelah ini gagal pada penjaga
     * anti-stale, sehingga tidak ada satu pun angka dari run yang dibatalkan yang dapat mendarat
     * di OUTPUT. Worker latar yang sudah terlanjur dibuat dibiarkan selesai sendiri. */
    RUN_SEQ[branch]++;
    try{ ASYNC_REVIEW[branch]=null; }catch(e){}
    const b2=$('btn-run'); if(b2){ b2.disabled=false; b2.innerHTML=old; }
  });
  try{
    /* §3.1/§13.7: Run memakai ?mode=run -> engine dari payload LIVE, TIDAK menulis input_data.json.
       §11: baca RAW TEXT dulu untuk diagnostics, lalu parse aman (tangani HTML/empty/BOM/truncated). */
    const res=await fetch('run.php?mode=run'+(opts.fastest&&!opts.noFastFinalize?'&fast=1':''),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const rawText=await res.text();
    let data=null, parseErr=null;
    try{ data=JSON.parse(rawText.replace(/^\uFEFF/,'')); }catch(e){ parseErr=e; }
    if(myToken!==RUN_SEQ[branch]){                         // §3.5/§10: sudah ada request lebih baru -> STALE
      return;        // jangan render, jangan timpa OUTPUT terbaru, dan JANGAN tutup modal run baru
    }
    if(parseErr){                                          // §11: response bukan JSON valid -> diagnostik jelas, input user TETAP
      ppmClose();
      const snip=(rawText||'').slice(0,180).replace(/</g,'&lt;');
      $('run-msg').innerHTML='<span style="color:#c0392b">Server returned non-JSON (HTTP '+res.status+'). '+(snip?('Response starts: '+snip):'Empty response')+'</span>';
      return;
    }
    /* §10: response harus cocok context+revision terbaru (buang balasan basah/nyasar antar-cabang). */
    if(data.state_revision!=null && data.state_revision!==STATE_REVISION[branch]) return;   // modal milik revisi terbaru
    if(v3AutosaveOutcome(payload,data)===false){ ppmClose(); return; }
    /* ============================================================================================
     * TAHAP 1 — VALID PROVISIONAL. Commitment hasil final terakhir dipakai ulang untuk kuota baru
     * dan seluruh constraint sudah diperiksa backend. Hasilnya DITAMPILKAN agar operator dapat
     * bekerja, tetapi BUKAN cost minimum global: Save/Export/Publish tetap terkunci sampai tahap 2
     * (optimasi exact) selesai dan menggantikannya sebagai FINAL OPTIMAL. */
    if(data.provisional===true && data.async_job && data.async_job.job_id){
      ppmClose();
      INPUT=payload; OUTPUT=data; GSD_PROVISIONAL={data:data};
      GSD_GATE=true; gsdApplyGate();
      try{ renderResult(data); refreshOverview(); refreshPills(); showSimulationDataResult(); }catch(e){}
      gsdProvisionalBanner(data);
      const rmP=document.getElementById('run-msg');
      if(rmP) rmP.innerHTML='<span style="color:#b45309"><b>VALID PROVISIONAL</b> — EXACT COST OPTIMIZATION IN PROGRESS. '
        +'Seluruh constraint valid; biaya belum dibuktikan terendah. Save/Export/Publish terkunci.</span>';
      adoptBackendAsyncJob(payload,branch,myToken,data.async_job).catch(e=>{
        if(myToken===RUN_SEQ[branch]) $('run-msg').innerHTML='<span style="color:#c0392b">Optimasi exact gagal: '
          +gsfEsc(String(e.message||e))+' — hasil tetap VALID PROVISIONAL dan terkunci.</span>';});
      if(tlTarget()==='max') tlFamilyProvisionalPoll(payload,branch,myToken,data.async_job.job_id).catch(()=>{});
      return;
    }
    /* Keputusan bahan bakar adalah state alur kerja yang SUKSES, bukan error terminal.
     * Popup dibuka bersama blok validated_options_job supaya angka yang disajikan sudah melewati
     * rerun kandidat dan validasi lengkap; selama job masih berjalan popup menampilkan progress
     * dan tombol LNG/Distillate aktif OTOMATIS ketika hasil tervalidasi tiba. */
    if((data.status==='FUEL_SELECTION_REQUIRED'||data.status==='USER_FUEL_DECISION_REQUIRED'||data.status==='USER_ACTION_REQUIRED')
       && data.shortage_decision){
      /* ======================================================================================
       * GERBANG KEPUTUSAN — AKAR PENYEBAB "popup tidak memblokir apa pun".
       *
       * Baris ini DAHULU memanggil finalizeSimulationUI() lebih dahulu, baru membuka popup.
       * Akibatnya, sebelum operator memutuskan apa pun, aplikasi sudah:
       *   - mengisi OUTPUT dengan hasil PENDAHULUAN,
       *   - merender 48 baris Simulation Data,
       *   - berpindah ke tab Result,
       *   - dan mengambil snapshot state.
       * Popup memang muncul, tetapi hanya sebagai lapisan di ATAS rencana yang sudah terlanjur
       * disajikan sebagai hasil. Operator dapat menutupnya lalu menekan Save atau Excel —
       * `downloadExcel()` hanya memeriksa `if(!OUTPUT)`. Itulah cara workbook
       * `Daily_Plan_09_Jul_26_TGD_38(5).xls` ter-export dengan `Fuel Action Source =
       * recommendation`, `Added LNG = 0`, `Distillate Used = 0`, dan residual 9,7385.
       *
       * Sekarang hasil pendahuluan DITAHAN: ia disimpan terpisah sebagai PRELIM, tidak pernah
       * menyentuh OUTPUT, tidak dirender, dan tidak membuka tab Result. Save/Excel/Publish
       * dikunci sampai ada rerun final. Popup menjadi gerbang keputusan yang sesungguhnya. */
      const dec=data.shortage_decision;
      gsdHoldPreliminary(payload,data);
      /* ======================================================================================
       * PENYELESAIAN OTOMATIS BILA OPERATOR SUDAH MEMILIH BAHAN BAKAR SEBELUM Run (§5 dan §6).
       *
       * Memilih `Add LNG` atau `Use distillate` di menu sudah merupakan keputusan. Menampilkan
       * popup lagi berarti menanyakan hal yang sama dua kali. Yang belum diketahui hanyalah
       * JUMLAH-nya, dan itu baru terukur setelah analisis pendahuluan — karena itu urutannya:
       * pendahuluan dahulu, lalu rerun final otomatis, tanpa popup kedua.
       *
       *   - jumlah kosong       -> pakai jumlah tervalidasi yang direkomendasikan;
       *   - LNG >= kebutuhan    -> pakai angka operator sebagai otorisasi;
       *   - LNG <  kebutuhan    -> BAHAN BAKAR CAMPURAN: LNG sebesar otorisasi, sisanya distillate.
       * Popup hanya muncul untuk mode `recommendation`, yang memang meminta keputusan. */
      const auto1=gsdAutoResolveIfChosen(payload,data);
      if(auto1) return await auto1;
      $('run-msg').innerHTML='<span style="color:#b45309">Analisis pendahuluan selesai — kekurangan gas '
        +gsfEsc(String((data.info||{})['Gas Shortage (BBTUD)']??'?'))+' BBTUD. '
        +'Hasil BELUM final: pilih LNG, Distillate, atau kombinasi. Save/Export/Publish terkunci.</span>';
      gsfOpen(dec,data.info||{},'',data.validated_options_job||null);
      return;
    }
    /* ==========================================================================================
     * JOB YANG SUDAH DIBUAT BACKEND DIIKUTI LEBIH DAHULU — SEBELUM SATU PUN PENCOCOKAN TEKS.
     *
     * AKAR PENYEBAB YANG DIPERBAIKI DI SINI, TERUKUR PADA JEJAK KLIK NYATA. Blok ini DAHULU
     * berada di BAWAH cabang error. Rerun LNG mengembalikan amplop `ok=false`,
     * `result='rejected'`, HTTP 422 (sebabnya `STAGES_TRUNCATED`) — padahal backend SUDAH membuat
     * job lanjutannya dan melampirkannya pada `async_job`. Karena cabang error berjalan lebih
     * dahulu dan `needsAsyncEconomicReview()` hanya MENCOCOKKAN TEKS pesan, polanya tidak cocok,
     * UI berhenti pada pesan error, dan job yang sudah berjalan itu tidak pernah diambil.
     * Terpantau di log server: POST 422 pada 13:47:32, lalu TIDAK ADA request apa pun sesudahnya.
     * Operator melihat layar diam selamanya, padahal jawabannya selesai 23 detik kemudian.
     *
     * Sumber kebenarannya adalah keputusan backend, bukan bunyi pesannya. Karena itu adopsi
     * dinaikkan ke atas SELURUH cabang heuristik. Ini juga menghapus pembuatan job kedua yang
     * mubazir: `startAsyncEconomicReview()` membuat job baru untuk pekerjaan yang sudah berjalan.
     * ========================================================================================== */
    const bj=(data.legal_branch_job&&data.legal_branch_job.job_id)?data.legal_branch_job
            :((data.async_job&&data.async_job.job_id)?data.async_job:null);
    if(bj){
      /* V3: amplop "job pemilik" tidak membawa baris hasil — ia bukan hasil (final maupun
       * pendahuluan). Ekspor/Publish hasil lama dikunci selama perhitungan; Save Input tidak. */
      if(Array.isArray(data.data)&&data.data.length) finalizeSimulationUI(payload,data,
        '<span style="color:#b45309">Pratinjau — perhitungan eksak sedang diselesaikan…</span>');
      else { GSD_GATE=true; gsdApplyGate(); $('run-msg').innerHTML='<span style="color:#1763d6">Perhitungan berjalan — satu job untuk state ini ('
        +gsfEsc(String(bj.job_id||''))+(data.autosave&&data.autosave.ok?'; input sudah tersimpan':'')+').</span>'; }
      if(tlTarget()==='max' && !data.shortage_decision) tlFamilyProvisionalPoll(payload,branch,myToken,bj.job_id).catch(()=>{});
      adoptBackendAsyncJob(payload,branch,myToken,bj,opts).catch(e=>{
        ppmClose();
        if(myToken===RUN_SEQ[branch])
          $('run-msg').innerHTML='<span style="color:#c0392b">Perhitungan eksak gagal: '+String(e.message||e)+'</span>';});
      return;
    }
    /* §6 — hasil BELUM final tetap ditampilkan sebagai incumbent dengan label jelas; Publish
     * diblokir oleh release gate. Yang dilarang adalah membiarkan UI kosong. */
    if(data.status==='NOT_CONVERGED_INCUMBENT_FOR_REVIEW' && Array.isArray(data.data) && data.data.length){
      finalizeSimulationUI(payload,data,'<span style="color:#b45309">Pratinjau — hasil BELUM final: '
        +gsfEsc(String(data.message||''))+'</span>');
      if(needsAsyncEconomicReview(data)){startAsyncEconomicReview(payload,branch,myToken).catch(e=>{
        ppmClose();
        if(myToken===RUN_SEQ[branch])$('run-msg').innerHTML='<span style="color:#c0392b">Async review gagal: '+String(e.message||e)+'</span>';});}
      return;
    }
    if(data.ok===false || (data.result && !['ok','action_required'].includes(data.result))){
      if(!needsAsyncEconomicReview(data)) ppmClose();       // error terminal: tidak ada lanjutan yang ditunggu
      const em=(data.error&&data.error.message)||data.message||'unknown';
      $('run-msg').innerHTML='<span style="color:#c0392b">Error: '+em+(data.line?(' (line '+data.line+')'):'')+'</span>';
      if(needsAsyncEconomicReview(data)){startAsyncEconomicReview(payload,branch,myToken).catch(e=>{ppmClose();if(myToken===RUN_SEQ[branch])$('run-msg').innerHTML='<span style="color:#c0392b">Async review gagal: '+String(e.message||e)+'</span>';});return;}
      /* Fuel selection is handled before this terminal-error branch. */
      return;                                              // §3.3/§16: input user TIDAK dihapus saat gagal
    }
    /* KEPUTUSAN OPERATOR SELAIN BAHAN BAKAR (Change Over tidak dapat dieksekusi; konflik antar-
     * window kuota gas). Baris yang dikembalikan adalah PRATINJAU BUKTI, bukan rencana final —
     * tanpa pesan eksplisit operator dapat mengira ini rencana yang siap dipakai. Publish tetap
     * dikunci oleh release gate; di sini yang ditambahkan adalah keterangannya. */
    if(data.status==='USER_ACTION_REQUIRED'
       && ['CHANGE_OVER_DECISION','GAS_WINDOW_QUOTA_DECISION'].includes(String(data.action_required||''))){
      const blk=data.change_over_blocker||data.gas_window_conflict||null;
      let extra='';
      const rowsCs=blk&&blk.conflict_set&&blk.conflict_set.rows;
      if(Array.isArray(rowsCs)&&rowsCs.length) extra=' — '+gsfEsc(rowsCs.slice(0,3).join('; '));
      else if(blk&&Array.isArray(blk.bukti)&&blk.bukti.length) extra=' — '+gsfEsc(String(blk.bukti[0]));
      finalizeSimulationUI(payload,data,'<span style="color:#b45309"><b>Perlu keputusan operator.</b> '
        +gsfEsc(String(data.message||''))+extra+'</span>');
      return;
    }
    if(opts.shortage){
      gsfSyncFormToDecision(payload);
      finalizeSimulationUI(payload,data,'<span style="color:#166534">Done — hasil ini memakai keputusan bahan bakar Anda ('+gsfEsc(payload.data3.modeling.gas_shortage_action)+'). Pilihan belum tersimpan; tekan Save bila ingin menyimpannya.</span>');
    } else finalizeSimulationUI(payload,data,null);
  }catch(err){
    ppmClose();
    if(myToken===RUN_SEQ[branch]) $('run-msg').innerHTML='<span style="color:#c0392b">Request failed: '+err.message+'</span>';
    throw err;
  }
  finally{                                                 // §3.10/§11: spinner SELALU berhenti (hanya reset bila kita run terakhir)
    if(myToken===RUN_SEQ[branch]){ btn.disabled=false; btn.innerHTML=old; }
  }
}

/* ===================== save input (no simulation) ===================== */
/* PROMPT STG 3-SEGMENT Validasi: S1/S2 Format 3-3-1 wajib Threshold 1 < Threshold 2.
   Segment ranges (<T1, T1..T2, >T2) dipilih if/elif/else sehingga tidak ada gap/overlap;
   satu-satunya input yang bisa merusak urutan adalah threshold — jika invalid, model
   TIDAK disimpan/di-run sebagai valid (alert + abort). */
function validateStg3SegBeforeSave(payload){
  for(const s of ['s1','s2']){
    const c=payload&&payload.data3&&payload.data3[s]; if(!c) continue;
    if(((c.gtg||[]).length)<3) continue;
    const t1=Number(c.ssp_f2u3), t2=Number(c.ssp_f3u3);
    if(!isFinite(t1)||!isFinite(t2)||!(t1<t2)){
      alert(`${s.toUpperCase()} STG Load Characteristic (Format 3-3-1): Threshold 1 (${c.ssp_f2u3}) harus < Threshold 2 (${c.ssp_f3u3}).\nPerbaiki di Periodic Input → Unit Characteristic → STG load characteristic sebelum Save/Run.`);
      $('run-msg').innerHTML=`<span style="color:#c0392b">${s.toUpperCase()} STG threshold invalid: Threshold 1 harus &lt; Threshold 2 — model tidak disimpan.</span>`;
      return false;
    }
  }
  return true;
}
async function saveInput(){
  /* PROMPT SAVE-FI §A3/§A5 — spinner WAJIB selalu berhenti (finally), double-submit dicegah
   * (btn.disabled sejak awal), timeout eksplisit (AbortController), dan pesan error SPESIFIK:
   * membedakan payload gagal dibangun, validasi lokal, HTTP error + alasan server, body bukan JSON,
   * network error, dan timeout — bukan sekadar "1 input data tidak tersave". Server menjamin
   * ALL-OR-NOTHING (atomic write): pada kegagalan apa pun file lama tetap utuh. */
  /* V3: Save Input menyimpan INPUT, bukan hasil simulasi — tetap aktif selama provisional,
   * Gas Shortage, Target Selesai, dan job exact berjalan. */
  const btn=$('btn-save');
  if(btn.disabled) return;                                                     // §A3.10 anti double-click
  let payload;
  try{payload=assembleInput();}
  catch(e){ $('run-msg').innerHTML='<span style="color:#c0392b">Save DIBATALKAN sebelum kirim — payload gagal dibangun: '+String(e&&e.message||e).replace(/</g,'&lt;')+'. File lama tetap utuh.</span>'; return; }
  if(!validateStg3SegBeforeSave(payload)) return;         // PROMPT STG 3-SEGMENT: T1 < T2 wajib
  const old=btn.innerHTML;
  btn.disabled=true; btn.innerHTML='<span class="spin"></span> Saving…'; $('run-msg').textContent='';
  const ctl=new AbortController(); const tmr=setTimeout(()=>ctl.abort(),45000);   // §A3.7 timeout 45s
  try{
    let body;
    try{ body=JSON.stringify(payload); }
    catch(e){ throw new Error('payload tidak bisa di-serialize (JSON.stringify): '+e.message); }
    const res=await fetch('run.php?mode=save',{method:'POST',headers:{'Content-Type':'application/json'},body,signal:ctl.signal});
    let data=null, rawTxt='';
    try{ rawTxt=await res.text(); data=JSON.parse(rawTxt); }
    catch(e){ throw new Error('server menjawab bukan JSON (HTTP '+res.status+'): '+rawTxt.slice(0,180)); }
    if(res.ok&&data.result==='ok'){
      INPUT=payload; INPUT0=JSON.parse(JSON.stringify(payload)); dpSnapshotCurrent();   // saved state becomes the reload baseline
      $('run-msg').innerHTML='<span style="color:#1a7d4d">Input saved — will persist after reload.'+
        (data._sanitized_nested_snapshots?(' ('+data._sanitized_nested_snapshots+' nested snapshot lama dibersihkan)'):'')+'</span>';
      toast('Input saved to input_data.json');
    } else {
      $('run-msg').innerHTML='<span style="color:#c0392b">Save GAGAL (HTTP '+res.status+'): '+((data&&data.message)||'unknown').replace(/</g,'&lt;')+
        ((data&&data.detail&&data.detail.old_file_intact)?' — file lama TETAP UTUH; perbaiki lalu coba lagi.':'')+'</span>';
    }
  }catch(err){
    const msg=(err&&err.name==='AbortError')?'timeout 45 detik — server tidak menjawab; file lama tetap utuh (atomic write), coba lagi':String(err&&err.message||err);
    $('run-msg').innerHTML='<span style="color:#c0392b">Save GAGAL: '+msg.replace(/</g,'&lt;')+'</span>';
  }
  finally{ clearTimeout(tmr); btn.disabled=false; btn.innerHTML=old; }          // §A3.8 spinner SELALU reset
}
/* SIMULATION DATA — exactly 40 columns in the order required by Revisi Sec.4.
 * type: 't' time (HH:MM only), 'g' gas (3 dp), 'act:KEY' manual actual input,
 * '' numeric (2 dp). G7/G10, pln_lo/pln_hi/in_band are intentionally NOT shown. */
const COLS=[
  ['Time','t','TIME','time'],['HL','','HOUSELOAD (MW)','hl'],['IE','','IE (MW)','ie'],['Dispatch','','DISPATCH PLN (MW)','dispatch'],
  ['Export_PLN','','EXPORT PLN (MW)','export'],
  ['G3','','G3 (MW)','gtg'],['G4','','G4 (MW)','gtg'],['G6','','G6 (MW)','gtg'],['S1','','S1 (MW)','stg'],
  ['G1','','G1 (MW)','gtg'],['G2','','G2 (MW)','gtg'],['G5','','G5 (MW)','gtg'],['S2','','S2 (MW)','stg'],
  ['G8','','G8 (MW)','gtg2'],['G9','','G9 (MW)','gtg2'],['S3','','S3 (MW)','stg'],
  ['G7','','G7 (MW)','gtg2'],['G10','','G10 (MW)','gtg2'],
  ['Jababeka','','JABABEKA','jbbk'],['BB1','','BBLN1 (MW)','bbln'],['BB2','','BBLN2 (MW)','bbln'],['BB_Total','','TOTAL BBLN (MW)','bbln'],
  ['GE1','','GE1 (MW)','ge'],['GE2','','GE2 (MW)','ge'],['GE3','','GE3 (MW)','ge'],['GE4','','GE4 (MW)','ge'],['Total_GE','','TOTAL GE (MW)','ge'],
  ['Spin_Res','','SPINNING RESERVE (MW)','spin'],['BusFlow','','BUSFLOW (MW)','bus'],
  ['Total_Coal','','COAL','coal'],['Dist_Total','','DISTILLATE','dist'],['Total_Gas','g','TOTAL GAS (BBTUD)','gas'],
  ['Total_Gas_JBBK','','TOTAL GAS JBBK (BBTUD)','gas'],['Total_Gas_MM','','TOTAL GAS MM2100 (BBTUD)','gas'],   /* §6: TOTAL GAS = JBBK + MM2100 */
  ['EnergyPGN_RT','g','ENERGY PGN REAL TIME (BBTUD)','pgn'],
  ['Flow_PGN_RT','','FLOW PGN REAL TIME (MMSCFD)','pgn'],
  ['FixedFlow_J','','TOTAL FIXED FLOW JABABEKA (MMSCFD)','pgn'],
  ['Total_Flow_M','','TOTAL FLOW GAS MM2100 (MMSCFD)','pgn'],                                                   /* §9.1/§10 */
  ['FixedFlow_M','','Cummulative Gas MM2100 (BBTUD)','pgn'],                                                    /* §9.1 rename; key lama dipertahankan */
  ['Only_FF_M','','ONLY FIXED FLOW MM2100 (MMSCFD)','pgn'],                                                     /* §9.1 baru */
  ['Est_PGN','g','ESTIMATION PGN TOTAL (BBTUD)','pgn'],['Act_PGN','act:pgn','ACTUAL PGN TOTAL (BBTUD)','actual'],
  ['Est_FF_J','g','TOTAL FIXED FLOW JABABEKA - ESTIMATION ENERGY TOTAL (BBTUD)','pgn'],
  ['Act_FF_J','act:ffj','TOTAL FIXED FLOW JABABEKA - ACTUAL ENERGY TOTAL (BBTUD)','actual'],
  ['Est_FF_M','g','TOTAL GAS MM2100 - ESTIMATION ENERGY TOTAL (BBTUD)','pgn'],
  ['Act_FF_M','act:ffm','TOTAL GAS MM2100 - ACTUAL ENERGY TOTAL (BBTUD)','actual']
];
/* show only HH:MM in the SIMULATION DATA TIME column (Revisi Sec.6). */
function hm(t){ if(t==null) return ''; t=String(t); const m=t.match(/(\d{1,2}:\d{2})(?::\d{2})?\s*$/); return m?m[1]:t; }
/* shared parameter list for Summary + Comparison: [label, info-key, unit, decimals] */
/* ============================================================================================
 *  PANEL EVIDENCE MM2100 / KP72 — Plan dan Monitoring Daily Plan
 *  Seluruh angka diambil dari OUTPUT.info (hasil backend final). TIDAK ada perhitungan gas
 *  ulang dari DOM. Field presence legacy TIDAK ditampilkan (tanpa kontrak UI).
 *  Style memakai token AdminLTE existing (.card/.fld/.u), tanpa redesign halaman.
 * ========================================================================================== */
function mmNum(v,d){ const n=Number(v); return (v===null||v===undefined||!isFinite(n))?'-':n.toFixed(d===undefined?4:d); }
/* Evidence Bus — SELURUH angka dari OUTPUT.info['Bus Metrics'] (backend). Tidak ada
 * perhitungan bus dari DOM, dan tidak ada daftar unit hardcoded di sisi UI. */
/* Backend-derived Change Over runtime review. No silent override or auto-commit. */
/* V8 — Review Unit Priority berbasis kandidat pembanding + provenance gas MM2100 (dari OUTPUT.info engine). */
function buildV8PriorityCard(){
  try{
    const I=((typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)||{}); const v=I['V8 Priority Review']; const p=I['MM2100 Gas Provenance']; const fp=I['Fuel Provenance'];
    if(!v&&!p&&!fp) return '';
    const esc=x=>String(x==null?'-':x).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    let h='<details class="warnbox" id="v8-priority-card" style="margin-top:10px"><summary><b>Review Unit Priority generik V9 (kandidat pembanding) &amp; Provenance Bahan Bakar</b> — '
      +esc(v?v.status:'-')+(v&&v.cost_production_before!=null?' · CP '+esc(v.cost_production_before)+' → '+esc(v.cost_production_after):'')
      +(p?' · Gas MM2100: <b>'+esc(p.gas_source)+'</b> ('+esc(p.status)+')':'')+(fp?' · Provenance bahan bakar: <b>'+esc(fp.status)+'</b>':'')+'</summary><div>';
    h+='<div class="fld" style="color:#5b6b7a">Unit Priority terpadu: urutan operator (Unit Priority) = satu urutan untuk baseline, inkremental, delta, Mandatory Stop, decommit, keluarga commitment, Gas Shortage, Change Over, polish, dan comparator final. Required / Cannot Stop / Fixed Load / stop schedule = status wajib (tidak diubah kandidat); kopling blok GTG-STG dicek sebagai legalitas; preferensi ekonomi = Cost Production (comparator). Kandidat: KEEP, STOP row legal pertama, EARLY STOP, DECOMMIT, DELAY START, SWAP (start unit lain); prasaring pasti (kapasitas Export, legalitas status/blok/runtime, heat rate) tanpa simulasi; sisanya dispatch 48 row engine.</div>';
    const s10=I['V10 Screening Summary'], c10=I['V10 Candidate Screening'];
    if(s10||c10){ h+='<div class="fld" style="margin-top:4px"><b>V10 screening dua tingkat</b> · rute '+esc(s10?s10.route:'-')
      +(c10?' · universe '+esc(c10.universe_signature)+': dibangkitkan '+esc(c10.candidates_generated)+', dipangkas Tier-1 '+esc(c10.candidates_pruned)+' '+esc(JSON.stringify(c10.pruned_by_reason||{}))+', Tier-2a (48 row, dispatch tetap) '+esc(c10.candidates_evaluated_tier2a)+', Tier-2b (core run penuh) '+esc(c10.candidates_full_run_tier2b)+' · sertifikat: incumbent '+esc(c10.incumbent_node)+', baris kotor '+esc((c10.dirty_rows||[]).length):'')
      +(s10&&s10.exact?' · keluarga '+esc(s10.exact.family_nodes)+' node, comparator global dipangkas (dicakup keluarga) '+esc(s10.exact.gcr_screened_tier1):'')
      +(s10&&s10.review?' · review: '+esc(s10.review.generated)+' kandidat, prasaring '+esc(s10.review.prescreened_tier1)+', disimulasikan '+esc(s10.review.simulated):'')+'</div>'; }
    if(v){
      if(v.reason) h+='<div class="fld">Tidak ditinjau: '+esc(v.reason)+'</div>';
      (v.applied||[]).forEach(a=>{ h+='<div class="fld">Putaran '+a.round+': <b>'+esc(a.candidate)+'</b> menggantikan pemenang (CP '+esc(a.cp_before)+' → '+esc(a.cp_after)+')</div>'; });
      (v.relevant_intervals||[]).forEach(r=>{ h+='<div class="fld">'+esc(r.unit)+' row '+r.rows[0]+'–'+r.rows[1]+': headroom unit prioritas lebih tinggi maks '+esc(r.max_higher_priority_headroom_mw)+' MW '+esc(JSON.stringify(r.headroom_units||{}))
        +(r.higher_priority_peers_off&&r.higher_priority_peers_off.length?' · peer prioritas lebih tinggi mati: '+esc(r.higher_priority_peers_off.join(', ')):'')+' · minimum runtime '+esc(r.min_runtime_rows)+' row · row stop legal pertama '+esc(r.first_legal_stop_row)+'</div>'; });
      const fc=v.final_candidates||[];
      if(fc.length){ h+='<table class="prio" style="margin-top:6px"><tr><th>Kandidat pembanding</th><th>Metode</th><th>Valid</th><th>CP</th><th>Δ CP vs final</th><th>Pelanggaran / bukti</th></tr>';
        fc.forEach(c=>{ const pz=c.prescreen; h+='<tr><td>'+esc(c.id)+'</td><td>'+esc(c.method)+'</td><td>'+(c.valid?'YA':'TIDAK')+'</td><td>'+esc(c.cp)+'</td><td>'+esc(c.delta_cp_vs_final)+'</td><td>'+esc((c.violations||[]).join(', ')||(c.failed_checks||[]).join(', ')||'-')+(pz&&pz.detail?' — '+esc(pz.detail):'')+'</td></tr>'; });
        h+='</table>'; }
      const hist=(v.history||[]).filter(c=>c.round<(v.rounds||1));
      if(hist.length){ h+='<details style="margin-top:6px"><summary>Kandidat putaran sebelumnya ('+hist.length+')</summary><table class="prio"><tr><th>Putaran</th><th>Kandidat</th><th>Valid</th><th>CP</th><th>Pelanggaran</th></tr>';
        hist.forEach(c=>{ h+='<tr><td>'+c.round+'</td><td>'+esc(c.id)+'</td><td>'+(c.valid?'YA':'TIDAK')+'</td><td>'+esc(c.cp)+'</td><td>'+esc((c.violations||[]).join(', ')||'-')+'</td></tr>'; });
        h+='</table></details>'; }
    }
    if(p&&p.rows){ h+='<div class="fld" style="margin-top:6px">Sumber gas MM2100: <b>'+esc(p.gas_source)+'</b> · kuota KP72 '+esc(p.kp72_quota_bbtud)+' BBTUD · Actual Energy MM2100 '+esc(p.actual_energy_mm2100_bbtud)+' BBTUD · fixed flow manual '+esc(JSON.stringify(p.manual_fixed_flow_mm2100_rows||{}))+' · MM2100 Daily Used '+esc(p.mm2100_daily_used_bbtud)+' BBTUD</div>';
      h+='<table class="prio"><tr><th>Row</th><th>Waktu</th><th>Sumber</th><th>Gas tersedia</th><th>Unit MM2100 (MW)</th><th>Gas MM2100 (BBTUD-laju)</th><th>Validasi</th></tr>';
      p.rows.slice(0,48).forEach(r=>{ h+='<tr><td>'+r.row+'</td><td>'+esc(r.time)+'</td><td>'+esc(r.gas_source)+'</td><td>'+esc(r.available)+'</td><td>'+esc(Object.keys(r.unit_mw||{}).map(u=>u+' '+r.unit_mw[u]).join(', ')||'0')+'</td><td>'+esc(r.gas_mm2100_rate_bbtud)+'</td><td>'+esc(r.validation)+(r.reason?' — '+esc(r.reason):'')+'</td></tr>'; });
      h+='</table><div class="fld" style="color:#5b6b7a">'+esc(p.rule)+'</div>'; }
    if(fp&&fp.accounts){ h+='<div class="fld" style="margin-top:6px"><b>Provenance bahan bakar (seluruh fuel)</b>: '+esc(fp.status)+' · pool Jababeka '+esc(JSON.stringify(fp.jababeka_pool_share_pct||{}))+' @ '+esc(fp.jababeka_pool_price_usd_mmbtu)+' USD/MMBTU · MM2100 '+esc((fp.mm2100_source||{}).source)+' @ '+esc(fp.mm2100_price_usd_mmbtu)+' USD/MMBTU · unit-row tanpa sumber sah: '+esc(fp.unit_rows_without_legal_source)+' · identitas gas '+esc(JSON.stringify(fp.identity||{}))+'</div>';
      h+='<table class="prio"><tr><th>Akun</th><th>Dipakai</th><th>Biaya (USD)</th><th>Harga</th><th>Status</th></tr>';
      fp.accounts.forEach(a=>{ h+='<tr><td>'+esc(a.account)+'</td><td>'+esc(a.used)+' '+esc(a.unit)+'</td><td>'+esc(a.cost_usd)+'</td><td>'+esc(a.price)+'</td><td>'+esc(a.status)+'</td></tr>'; });
      h+='</table>';
      if(fp.rows){ h+='<details style="margin-top:6px"><summary>Per row: unit, MW, laju bahan bakar, heat rate, biaya, sumber</summary><table class="prio"><tr><th>Row</th><th>Waktu</th><th>Unit</th></tr>';
        fp.rows.forEach(r=>{ h+='<tr><td>'+r.row+'</td><td>'+esc(r.time)+'</td><td style="font-size:11px">'+esc(Object.keys(r.units||{}).map(u=>{const x=r.units[u]; return u+' '+(x.mw!=null?x.mw+' MW':'')+(x.fuel_bbtud_rate!=null?' · '+x.fuel_bbtud_rate+' BBTUD-laju':'')+(x.heat_rate_btu_kwh!=null?' · HR '+x.heat_rate_btu_kwh:'')+(x.coal_ton!=null?' · '+x.coal_ton+' ton':'')+(x.litre!=null?' · '+x.litre+' l':'')+(x.cost_usd_row!=null?' · '+x.cost_usd_row+' USD':'')+' · '+x.source+(x.valid?'':' · TIDAK SAH');}).join(' | '))+'</td></tr>'; });
        h+='</table></details>'; }
      h+='<div class="fld" style="color:#5b6b7a">'+esc(fp.rule)+'</div>'; }
    h+='</div></details>';
    return h;
  }catch(e){ return ''; }
}
/* V5 — Audit Headroom & Unit Priority per row (dari info['Headroom Priority Audit'] engine). */
function buildHeadroomAuditCard(){
  try{
    const a=((typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)||{})['Headroom Priority Audit']; if(!a||!a.rows) return '';
    const esc=x=>String(x==null?'-':x).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const ev=a.events||{}; const st=(a.status==='PASS'||a.status==='PASS_WITH_REASON')?'<b style="color:#166534">'+esc(a.status)+'</b>':'<b style="color:#b45309">'+esc(a.status)+'</b>';
    let h='<details class="warnbox" id="headroom-audit-card" style="margin-top:10px"><summary><b>Headroom &amp; Unit Priority Audit</b> — '+st+' · temuan '+(a.flags_total||0)+', tanpa alasan sah '+(a.flags_unresolved||0)+'</summary><div>'
      +'<div class="fld">Row diaudit: <b>'+a.rows_audited+'</b> · Unit: '+esc((a.units_audited||[]).join(', '))+' · Unit Priority: '+esc(JSON.stringify(a.unit_priority||[]))+'</div>'
      +'<div class="fld">Start: <b>'+(ev.start||0)+'</b> · Stop: <b>'+(ev.stop||0)+'</b> · Naik: <b>'+(ev.up||0)+'</b> · Turun: <b>'+(ev.down||0)+'</b> · Temuan prioritas: <b>'+(a.flags_total||0)+'</b> (tanpa alasan sah: <b>'+(a.flags_unresolved||0)+'</b>)</div>'
      +((a.flags_total&&!a.flags_unresolved)?'<div class="fld">Setiap temuan memiliki alasan sah terukur (counterfactual engine / constraint); lihat kolom alasan.</div>':'')
      +(a.flags_unresolved?'<div class="fld">Batas atas manfaat bila seluruh temuan tanpa alasan dipindah ke unit prioritas lebih tinggi: <b>'+esc(a.residual_gain_usd_upper_bound)+' USD</b> (Cost Production turun paling banyak <b>'+esc(a.residual_cp_gain_upper_bound)+' USD/MWh</b>)</div>':'');
    const fl=(a.flags||[]).slice(0,60);
    if(fl.length){ h+='<table class="prio" style="margin-top:6px"><tr><th>Row</th><th>Temuan</th><th>Unit</th><th>Prioritas</th><th>Unit prioritas lebih tinggi / headroom</th><th>Alasan constraint / ekonomi</th><th>Biaya inkremental</th></tr>';
      fl.forEach(f=>{ h+='<tr><td>'+f.row+'</td><td>'+esc(({LOWER_PRIORITY_START_WHILE_HIGHER_HEADROOM:'start unit prioritas rendah',LOWER_PRIORITY_LOADED_WHILE_HIGHER_HEADROOM:'beban unit prioritas rendah di atas min',LOWER_PRIORITY_RUNNING_WHILE_HIGHER_HEADROOM:'unit prioritas rendah running',MM2100_UNIT_RUNNING:'unit MM2100 berbeban (sumber gas)'})[f.type]||f.type)+'</td><td>'+esc(f.unit)+' ('+esc(f.start_mw!=null?f.start_mw:f.unit_mw)+' MW)</td><td>'+esc(f.unit_priority)+'</td><td>'
        +esc(f.higher_unit?(f.higher_unit+' '+f.higher_headroom_mw+' MW'):JSON.stringify(f.headroom_units||{}))+'</td><td>'+esc((f.reasons||[]).join('; ')||'TANPA ALASAN')+'</td><td>'+esc(f.incremental_cost_usd_per_mw_row!=null?f.incremental_cost_usd_per_mw_row+' USD/MW/row':'-')+'</td></tr>'; });
      h+='</table>'; }
    h+='<details style="margin-top:6px"><summary>Tabel per row (unit berbeban: MW · min–max · headroom · prioritas)</summary><table class="prio"><tr><th>Row</th><th>Waktu</th><th>Export</th><th>Spin Res</th><th>Unit</th><th>Perubahan</th></tr>';
    a.rows.forEach(r=>{ const us=Object.keys(r.units||{}).map(u=>{const x=r.units[u]; return u+' '+x.mw+' ['+x.min+'–'+x.max+'] h'+(x.headroom==null?'-':x.headroom)+' p'+(x.prio==null?'-':x.prio);}).join(' · ');
      h+='<tr><td>'+r.row+'</td><td>'+esc(r.time)+'</td><td>'+esc(r.export_mw)+'</td><td>'+esc(r.spin_res_mw)+'</td><td style="font-size:11px">'+esc(us)+'</td><td style="font-size:11px">'+esc((r.events||[]).map(e=>e.unit+' '+e.event+' '+e.d_mw).join(', '))+'</td></tr>'; });
    h+='</table></details><div class="fld" style="color:#5b6b7a">'+esc(a.note||'')+'</div></div></details>';
    return h;
  }catch(e){ return ''; }
}
function buildChangeOverRuntimeReview(){try{const info=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)||{},r=info['Change Over Runtime Review'];if(!r)return'';const src=r.source||{},tgt=r.target||{},yes=x=>x?'PASS':'FAIL',note=r.recommendation_available?'Alternatif runtime full-valid tersedia untuk ditinjau.':'Belum ada alternatif runtime lebih pendek yang lolos clean re-simulation. Hasil saat ini dipertahankan.';return '<div class="fld" id="co-runtime-review" style="border:1px solid #90a4ae;padding:10px;margin-top:10px"><div style="font-weight:700">Rekomendasi Runtime Change Over</div><div>Minimum runtime input: <b>'+Number(r.user_input_minimum_runtime_hours||0).toLocaleString('id-ID')+' jam</b> ('+(r.minimum_runtime_rows||0)+' row)</div><div>Source '+String(src.gtg||'-').toUpperCase()+'/'+String(src.stg||'-').toUpperCase()+': '+(src.runtime_rows||0)+' row, last positive '+(src.last_positive_row??'-')+'</div><div>Destination '+String(tgt.gtg||'-').toUpperCase()+'/'+String(tgt.stg||'-').toUpperCase()+': '+(tgt.runtime_rows||0)+' row, first positive '+(tgt.first_positive_row??'-')+'</div><div>Overlap STG: '+(r.stg_overlap_rows||0)+' row, minimum '+(r.stg_overlap_minimum_rows||3)+' row: <b>'+yes(r.stg_overlap_ok)+'</b></div><div>Minimum runtime destination: <b>'+yes(r.target_minimum_runtime_ok)+'</b></div><div style="margin-top:6px;color:#455a64">'+note+'</div><div style="margin-top:6px"><button type="button" disabled title="Alternatif wajib lolos clean re-simulation">Gunakan Rekomendasi Runtime</button> <button type="button" onclick="var e=document.getElementById(\'co-runtime-detail\');if(e)e.hidden=!e.hidden">Lihat Detail</button></div><div id="co-runtime-detail" hidden style="margin-top:6px">Start command row: '+(r.selected_start_command_row??'-')+' · Stop command row: '+(r.selected_stop_command_row??'-')+' · Auto-commit: TIDAK · Default: hasil saat ini.</div></div>';}catch(e){return'';}}

function buildBusEvidence(){
  try{
    const I=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info:null;
    const m=I?I['Bus Metrics']:null; if(!m) return '';
    return '<div class="fld"><b>Bus A Generation:</b> '+mmNum(m.bus_a_generation_mwh,1)+' MWh'
      +' &nbsp;|&nbsp; <b>Bus B Generation:</b> '+mmNum(m.bus_b_generation_mwh,1)+' MWh'
      +' &nbsp;|&nbsp; <b>MM2100 Bus A Contribution:</b> '+mmNum(m.mm2100_bus_a_contribution_mwh,1)+' MWh'
      +' &nbsp;|&nbsp; <b>MM2100 Bus B Contribution:</b> '+mmNum(m.mm2100_bus_b_contribution_mwh,1)+' MWh</div>'
      +'<div class="fld"><b>Bus Flow (min):</b> '+mmNum(m.bus_flow_min_mw,2)+' MW'
      +' &nbsp;|&nbsp; <b>Bus Flow Limit:</b> '+mmNum(m.bus_flow_limit_mw,2)+' MW'
      +' &nbsp;|&nbsp; <b>Bus Flow Margin (min):</b> '+mmNum(m.bus_flow_margin_min_mw,2)+' MW'
      +' &nbsp;|&nbsp; <span style="color:#5b6b7a">assignment: '+(m.bus_assignment_source||'-')+'</span></div>';
  }catch(e){ return ''; }
}
function buildMM2100Panel(){
  try{
    const I=(typeof OUTPUT!=='undefined'&&OUTPUT&&OUTPUT.info)?OUTPUT.info:null;
    if(!I) return '';
    const M=(typeof INPUT!=='undefined'&&INPUT&&INPUT.data3&&INPUT.data3.modeling)?INPUT.data3.modeling:{};
    const q=(M.gas_quota||{}), gm=(M.mm2100_gas_mode||{}), ghvM=Number(M.ghv_mm2100||0);
    const rows=(OUTPUT.data||[]);
    const MMU=['ge1','ge2','ge3','ge4','g10'];
    /* profil per unit dari dispatch final */
    const prof=u=>{ const U=u.toUpperCase(); let n=0,f=null,l=null,mx=0;
      rows.forEach((r,i)=>{ const v=Number(r[U]||0); if(v>0.01){ n++; if(f===null)f=i+1; l=i+1; mx=Math.max(mx,v); } });
      return {rows:n,first:f,last:l,max:mx}; };
    /* sumber gas KP72 aktif */
    let gasKey=null; ['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'].forEach(k=>{ if(gasKey===null&&Number(q[k]||0)>0) gasKey=k; });
    const mode=gasKey?String(gm[gasKey]||'fixed'):'-';
    const isCum=/^cum/i.test(mode);
    const rawUnit=isCum?'BBTUD':'MMSCFD';
    const quota=Number(I['MM2100 Quota (BBTUD)']||0);
    const used =Number(I['MM2100 Used + Startup (BBTUD)']||0);
    const remain=quota-used;
    const over =(used>quota+1e-9);
    const shortage=Number(I['Gas Shortage (BBTUD)']||0);
    /* prioritas dari block_priority input */
    const bp=(M.block_priority||[]); const blkOf={},rkOf={};
    bp.forEach((grp,bi)=>{ let k=0; (grp||[]).forEach(g=>{ g=String(g).toLowerCase(); if(g==='required')return; blkOf[g]=bi; rkOf[g]=k++; }); });
    const reqMode=(M.required_mode||{}), stopMode=(M.stop_mode||{});
    const modeOf=(o,u)=>{ const c=o[u]||o[u.toUpperCase()]; if(!c) return '-';
      const m=String(c.mode||'based_on_sim'); return c.at?(m+' @'+c.at):m; };
    /* status kandidat: SELECTED_BASELINE / VALID_WHEN_REQUESTED / REJECTED_BASELINE */
    const sel=MMU.filter(u=>prof(u).rows>0);
    const stat=u=>prof(u).rows>0?'SELECTED_BASELINE':(u==='g10'?'REJECTED_BASELINE':'VALID_WHEN_REQUESTED');
    const reason=u=>{ if(prof(u).rows>0) return '-';
      return u==='g10' ? 'MM2100_QUOTA_INSUFFICIENT_FOR_MINIMUM_LOAD'
                       : 'tidak diperlukan dispatch baseline (full-valid bila di-Required)'; };
    let cand='<table class="mini"><tr><th>Unit</th><th>Status</th><th>Blok</th><th>Unit Priority</th>'
      +'<th>Start Mode</th><th>Stop Mode</th><th class="r">Rows ON</th><th class="r">First</th>'
      +'<th class="r">Last</th><th class="r">Max MW</th><th>Reason</th></tr>';
    MMU.forEach(u=>{ const p=prof(u);
      cand+=`<tr><td>${u.toUpperCase()}</td><td>${stat(u)}</td><td class="r">${blkOf[u]!==undefined?blkOf[u]:'-'}</td>`
        +`<td class="r">${rkOf[u]!==undefined?rkOf[u]:'-'}</td><td>${modeOf(reqMode,u)}</td><td>${modeOf(stopMode,u)}</td>`
        +`<td class="r">${p.rows}</td><td class="r">${p.first===null?'-':p.first}</td>`
        +`<td class="r">${p.last===null?'-':p.last}</td><td class="r">${p.max.toFixed(1)}</td>`
        +`<td>${reason(u)}</td></tr>`; });
    cand+='</table>';
    /* konteks Monitoring: TIME PASSED, historical vs future */
    let monBlk='';
    const mdp=M.monitoring_daily_plan||null;
    if(String(M.plan_type||'').toLowerCase()==='monitoring' && mdp){
      const cut=Number(mdp.time_passed_cutoff_row||0);
      let hist=0, fut=0;
      rows.forEach((r,i)=>{ let g=0; MMU.forEach(u=>{ g+=Number(r[u.toUpperCase()]||0); });
        if(g>0.01){ if(i<cut) hist++; else fut++; } });
      const fh=(cut>0&&used>0)?(used*hist/Math.max(1,hist+fut)):0;
      monBlk='<div class="fld"><b>Monitoring</b> &nbsp; TIME PASSED cutoff row: '+cut
        +' &nbsp;|&nbsp; Actual History rows: '+hist+' &nbsp;|&nbsp; Future Optimized rows: '+fut
        +' &nbsp;|&nbsp; Historical Gas Used: '+mmNum(fh)+' BBTUD'
        +' &nbsp;|&nbsp; Future Projected Gas: '+mmNum(used-fh)+' BBTUD'
        +' &nbsp;|&nbsp; Total Projected Usage: '+mmNum(used)+' BBTUD'
        +' &nbsp;|&nbsp; Future Remaining Quota: '+mmNum(remain)+' BBTUD</div>';
    }
    return '<div class="card" id="mm2100-panel" style="margin-top:10px">'
      +'<div class="cardh"><b>MM2100 / KP72 — Gas Engine Evidence</b></div><div class="cardb">'
      +'<div class="fld"><b>Selected MM2100 Units:</b> '+(sel.length?sel.map(u=>u.toUpperCase()).join(', '):'(tidak ada)')
      +' &nbsp;|&nbsp; <b>Candidate Order:</b> '+MMU.map(u=>u.toUpperCase()).join(' &rarr; ')+'</div>'
      +'<div class="fld"><b>Gas Source:</b> '+(gasKey?gasKey.toUpperCase():'-')
      +' &nbsp;|&nbsp; <b>Gas Mode:</b> '+mode
      +' &nbsp;|&nbsp; <b>Raw Value:</b> '+mmNum(gasKey?q[gasKey]:0,4)+' <span class="u">'+rawUnit+'</span>'
      +' &nbsp;|&nbsp; <b>GHV:</b> '+ghvM+' BTU/SCF'
      +' &nbsp;|&nbsp; <b>Energy Equivalent:</b> '+mmNum(quota)+' BBTUD</div>'
      +'<div class="fld"><b>Cumulative Quota:</b> '+mmNum(quota)+' BBTUD'
      +' &nbsp;|&nbsp; <b>Cumulative Used:</b> '+mmNum(used)+' BBTUD'
      +' &nbsp;|&nbsp; <b>Remaining Quota:</b> '+mmNum(remain)+' BBTUD'
      +' &nbsp;|&nbsp; <b>Over-Quota:</b> '+(over?'YES':'NO')
      +' &nbsp;|&nbsp; <b>Actual Gas Shortage:</b> '+mmNum(shortage)+' BBTUD</div>'
      +'<div class="fld" style="color:#5b6b7a">Maximum quota: hard limit. No upper tolerance. '
      +'Lower absorption tolerance: 0,04 BBTUD.</div>'
      +buildBusEvidence()+buildChangeOverRuntimeReview()+monBlk+cand+'</div></div>';
  }catch(e){ return ''; }
}
const SUMMARY_ROWS=[
  ['IE PREDICTION','IE Prediction (MWh)','MWh',1],
  ['JBBK MM PROD','JBBK MM Prod (MWh)','MWh',1],
  /* Produksi MM2100 (GE1-4 + G10) memasok pelanggan MM2100, terpisah dari bus Jababeka.
   * Arti JBBK MM PROD TIDAK diubah; dua baris di bawah bersifat TAMBAHAN. */
  ['JABABEKA BUS NET','Jababeka Bus Net (MWh)','MWh',1],
  ['MM2100 PROD','MM2100 Production (MWh)','MWh',1],
  ['TOTAL PLANT PRODUCTION','Total Plant Production (MWh)','MWh',1],
  ['COMBINED JBBK+MM2100 PROD','Combined JBBK+MM2100 Prod (MWh)','MWh',1],
  ['BABELAN PROD','Babelan Prod (MWh)','MWh',1],
  ['BABELAN CF','Babelan CF (%)','%',1],
  ['DAILY PLN EXPORT','Daily PLN Exp (MWh)','MWh',2],
  ['TOTAL FUEL','Total Fuel (BBTUD)','BBTUD',3],
  ['GAS FUEL TOTAL','Gas Fuel Total (BBTUD)','BBTUD',3],
  ['DISTILLATE FUEL TOTAL','Distillate Fuel Total (l)','Liter',0],
  ['PGN TOTAL','PGN Total (BBTUD)','BBTUD',3],
  ['PGN PIPE TOTAL','PGN Pipe Total (BBTUD)','BBTUD',3],
  ['LNG TOTAL','LNG Total (BBTUD)','BBTUD',3],
  ['PEP JBBK TOTAL','PEP JBBK Total (BBTUD)','BBTUD',3],
  ['PEP KP72 TOTAL','PEP KP72 Total (BBTUD)','BBTUD',3],
  ['AKASIA JBBK TOTAL','Akasia JBBK Total (BBTUD)','BBTUD',3],
  ['AKASIA KP72 TOTAL','Akasia KP72 Total (BBTUD)','BBTUD',3],
  ['BaGS JBBK TOTAL','BaGS JBBK Total (BBTUD)','BBTUD',3],
  ['BaGS KP72 TOTAL','BaGS KP72 Total (BBTUD)','BBTUD',3],
  ['TOTAL COAL','Total Coal (ton)','ton',2],
  ['% RE BABELAN','% RE Babelan (%)','%',1],
  ['GHG EMISSION','GHG Emission (ton CO2e/MWh)','ton CO2e/MWh',4],
  ['HEATRATE','Heatrate','BTU/kWh',0],
  /* Boundary konsisten: numerator dan denominator pada lingkup yang sama.
   * Jababeka = fuel Jababeka / produksi Jababeka; MM2100 = fuel KP72 / produksi MM2100;
   * Combined = seluruh fuel / (JBBK MM Prod + MM2100 Production). */
  ['JBBK MM FUEL','JBBK MM Fuel (BBTUD)','BBTUD',5],
  ['JBBK MM HEAT RATE','JBBK MM Heat Rate (BTU/kWh)','BTU/kWh',0],
  ['JABABEKA HEAT RATE','Jababeka Heat Rate (BTU/kWh)','BTU/kWh',0],
  ['MM2100 HEAT RATE','MM2100 Heat Rate (BTU/kWh)','BTU/kWh',0],
  ['COMBINED HEAT RATE','Combined Heat Rate (BTU/kWh)','BTU/kWh',0],
  ['TOTAL COST','Total Cost (USD)','USD',0],
  ['COST PRODUCTION','Cost Production (USD/MWh)','USD/MWh',2],
  ['JBBK MM TOTAL COST','JBBK MM Total Cost (USD)','USD',0],
  ['JBBK MM COST PRODUCTION','JBBK MM Cost Production (USD/MWh)','USD/MWh',2],
  ['BABELAN COST PRODUCTION','Babelan Cost Production (USD/MWh)','USD/MWh',2],
  ['TOTAL PLANT COST PRODUCTION','Total Plant Cost Production (USD/MWh)','USD/MWh',2],
  ['JABABEKA COST PRODUCTION','Jababeka Cost Production (USD/MWh)','USD/MWh',2],
  ['MM2100 COST PRODUCTION','MM2100 Cost Production (USD/MWh)','USD/MWh',2],
  ['COMBINED COST PRODUCTION','Combined Cost Production (USD/MWh)','USD/MWh',2]

];
/* RESULT > SUMMARY UI ONLY
 * Full SUMMARY_ROWS remains unchanged for Excel downloads and comparison data.
 * Requested legacy rows: 1,2,3,4,5,7-25,31,32.
 * Display order moves legacy #5 directly below IE Prediction.
 * Legacy #3 is shown as JBBK PROD only in the UI. */
const SUMMARY_UI_ORIGINAL_NUMBERS=[1,5,2,3,4,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,31,32];
const SUMMARY_UI_ROWS=SUMMARY_UI_ORIGINAL_NUMBERS.map(n=>{
  const row=SUMMARY_ROWS[n-1].slice();
  if(n===3) row[0]='JBBK PROD';
  return row;
});
const esc=s=>String(s==null?'':s).replace(/[<&>]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
const IMG_LAST_COL='FixedFlow_M';   // Download Image cutoff: Energy Real Time Gas MM2100, inclusive

/* ===== SHARED SIMULATION DATA RENDERER ==========================================
 * Satu-satunya pembangun tabel Simulation Data. Dipakai oleh Daily Plan, Monitoring
 * Daily Plan, Weekly Plan (detail per tanggal), dan Report rerun. Karena hanya ada satu
 * implementasi, urutan kolom, label, precision, formatting, warning/evidence, status,
 * dan constraint columns DIJAMIN identik di seluruh jalur.
 * forceNoMonitoring=true -> kolom TIME PASSED tidak pernah dirender (dipakai Weekly). */
function ppBuildSimTable(rows, o, forceNoMonitoring){
  let tbl='<div class="simhead"><span class="simtitle">TABEL DETAIL SLOT</span>'+
          '<span class="simsub">Per-row dispatch · 48 × half-hour</span>'+
          '<button type="button" id="btn-sim-fullscreen" class="btn simfs" style="margin-left:auto" title="Buka TABEL DETAIL SLOT dalam tampilan layar penuh">⛶ Full Screen</button>'+
          '<button type="button" id="btn-save-actual" class="btn primary">Save</button></div>';
  // PROMPT MONITORING §3.1/§9: kolom TIME PASSED (dropdown Y/N, 48 row) hanya di cabang Monitoring.
  const MON=forceNoMonitoring?false:((typeof dpMon==='function')&&dpMon());
  const tpCut=MON?mdpCutoff():0;
  tbl+='<div class="scroll simwrap"><table class="data simgrid" id="tbl-result"><thead><tr>'+
    (MON?'<th class="g-time" title="Monitoring Daily Plan — Y = jam sudah lewat (locked), N = future">TIME PASSED</th>':'')+
    COLS.map(c=>`<th class="g-${c[3]||''}${c[1]==='t'?' t':''}">${c[2]}</th>`).join('')+'</tr></thead><tbody>';
  const tpCell=(ri)=>{const y=ri<tpCut;
    return `<td class="tp-cell ${y?'tp-y':'tp-n'}"><select class="tp-sel ${y?'tp-y':'tp-n'}" data-tprow="${ri}" title="${y?'Time Passed — locked (optimizer tidak mengubah row ini)':'Future row — dioptimasi normal'}">`+
      `<option value="Y"${y?' selected':''}>Y</option><option value="N"${y?'':' selected'}>N</option></select>${y?'<span class="tp-lock" title="Locked">🔒</span>':''}</td>`;};
  rows.forEach((r,ri)=>{
    tbl+=`<tr class="${r.Actual?'actual-row ':''}${MON&&ri<tpCut?'tp-locked':''}">`+(MON?tpCell(ri):'')+COLS.map(c=>{
      const k=c[0], t=c[1], g=c[3]||''; let v=r[k];
      if(t==='t') return `<td class="t g-time">${hm(v)}</td>`;
      if(t.indexOf('act:')===0){
        /* PROMPT ACTUAL GAS 1H §1/§6/§7: actual gas = input PER 1 JAM. Cell di-MERGE 2 row 30-menit
           (rowspan=2 di row genap pasangan; row ganjil tidak merender cell — tertutup rowspan).
           Placeholder = jumlah estimation pasangan (nilai 1 jam). Row TIME PASSED=Y (jam penuh
           di dalam cutoff) -> input disabled: locked tapi TETAP dihitung (§7.5). */
        if(ri%2===1) return '';
        const key=t.split(':')[1];
        const rNext=rows[ri+1]||{};
        const estPair=key==='pgn'?(+(r.Est_PGN||0)+ +(rNext.Est_PGN||0))
                     :key==='ffj'?(+(r.Est_FF_J||0)+ +(rNext.Est_FF_J||0))
                                 :(+(r.Est_FF_M||0)+ +(rNext.Est_FF_M||0));
        const lockedHr=MON&&(ri+2)<=tpCut;
        return `<td class="g-actual g-actual-hr" rowspan="2"><input class="actin no-spinner" type="number" step="0.0001" data-act="${key}" data-hour="${ri/2}" data-row="${ri}" `+
               `value="${v==null?'':v}" placeholder="${fmt(estPair,3)}"${lockedHr?' disabled':''} title="${lockedHr?'TIME PASSED = Y — actual 1 jam locked (tetap dihitung ke total)':'Manual actual per 1 JAM ('+hm(r.Time)+'–'+hm(rNext.Time||r.Time)+') — leave blank to use estimation'}"></td>`;
      }
      // decimals by group (Revisi Sec.4): GTG/GE=1, gas=3, others=2
      let dec=2;
      if(g==='gtg'||g==='gtg2'||g==='ge') dec=1;
      else if(t==='g'||g==='gas'||g==='pgn') dec=3;
      const num = typeof v==='number';
      const fk=fixKeyOf(k); const fixed=fk?cellFixed(fk,ri):null;
      const fixAttr=fk?` data-fix="${fk}" data-row="${ri}"`:''; const fixCls=fixed!=null?' fix-load':'';
      const showV=(fixed!=null)?fmt(fixed,2):(num?fmt(v,dec):(v??''));
      /* Zero-blank + Start/Stop markers (Revisi UI) for unit-load columns G1–G10, B1/B2, GE1–GE4 only
         (totals like TOTAL BBLN / TOTAL GE are NOT blanked). Display-only: r[k] stays numeric 0. */
      const MARK_KEYS={G1:1,G2:1,G3:1,G4:1,G5:1,G6:1,G7:1,G8:1,G9:1,G10:1,BB1:1,BB2:1,GE1:1,GE2:1,GE3:1,GE4:1};
      let blankZero=false, unitMark='';
      if(MARK_KEYS[k]){
        const cur=num?v:0;
        const prv=ri>0?(+(rows[ri-1][k])||0):null;
        const nxt=ri<rows.length-1?(+(rows[ri+1][k])||0):null;
        if(cur<=1e-9){
          blankZero=true;                                  // load 0 -> blank cell
          if(prv!==null&&prv>1e-9)        unitMark=' cell-stop';     // first 0 after running -> black
          else if(nxt!==null&&nxt>1e-9)   unitMark=' cell-startup';  // 30-min before first load -> orange
          if(fixCls) unitMark='';                          // fixed-load highlight wins over markers
        }
      }
      // ---- special value rendering ----
      if(g==='dispatch' && num){
        const cls = v>=150?'green':(v>=90?'orange':'slate');
        return `<td class="g-dispatch"><span class="pill ${cls}">${fmt(v,2)}</span></td>`;
      }
      if(g==='export'){
        if(!num) return `<td class="g-export">${v??''}</td>`;
        const rg=(INPUT.data3?.modeling?.pln_export_priority?.range)||{}; const rmin=+rg.min||0, rmax=+rg.max||1e9;
        let ec='exp-green'; if(v<rmin-1e-6||v>rmax+1e-6)ec='exp-red'; else if(v<=rmin+10)ec='exp-yellow';
        return `<td class="g-export cell-export ${ec}">${fmt(v,2)}</td>`;
      }
      if(g==='diff' && num){
        const sign = v>1e-6?'pos':(v<-1e-6?'neg':'zero');
        const txt = (v>1e-6?'+':'')+fmt(v,2);
        return `<td class="g-diff"><span class="pill ${sign}">${txt}</span></td>`;
      }
      if((g==='gtg'||g==='gtg2') && num){
        const stgMap={G3:'S1',G4:'S1',G6:'S1',G1:'S2',G2:'S2',G5:'S2',G8:'S3',G9:'S3'};
        const sk=stgMap[k]; const sc=(v>0)?(sk?((r[sk]||0)<=1e-6):true):false;
        /* PROMPT GAS SHORTAGE Sec.11: cell BIRU + tooltip bila unit memakai Distillate slot ini
           (DistMix_G{n} per row dari engine: 0.3/0.5/0.75/1.0; liter/row = Dist_G{n}). */
        const dmx=+(r['DistMix_'+k]||0); let dCls='', dTitle='Ctrl+Click to fix/cancel load';
        let dStyle='', dData='';
        if(dmx>0&&!blankZero){
          dCls=' dist-m'+Math.round(dmx*100)+' dist-cell'; dStyle=distCellStyle(r,k); dData=distCellData(r,k);
          const dl=r['Dist_'+k];                                 // Distillate Liter slot (schedule final)
          /* §11 tooltip lengkap: unit, waktu, %, load, Total Fuel Energy, Gas Energy, Distillate Energy, Liter.
             Distillate Energy (BBTU/slot) diturunkan dari liter via faktor project; Total = Dist/frac. */
          /* Basis konversi dari backend (Distillate Conversion Basis); x2 = basis per slot 30 menit. */
          /* BUG: `info` tidak pernah didefinisikan di dalam ppBuildSimTable() — satu-satunya
           * sumber di sini adalah `o.info`. Akibatnya setiap kali TABEL DETAIL SLOT merender
           * rencana ber-distillate (ada DistMix_*), render berhenti dengan
           * `ReferenceError: info is not defined`. Terukur pada uji klik nyata jalur Distillate. */
          const _cvb=gsfConversion((o&&o.info)||{})||null;
          const HEATL=(_cvb?(+_cvb.btu_per_litre):(0.8424*19400*2.2046))*2;
          const distE = (dl!=null)? (dl*HEATL/1e9) : null;       // BBTU distillate slot
          const totE  = (distE!=null&&dmx>0)? (distE/dmx) : null; // Total Fuel Energy slot
          const gasE  = (totE!=null)? (totE*(1-dmx)) : null;      // Gas Energy slot
          const pctS=Math.round(dmx*100);
          dTitle=`${pctS}% Distillate — ${k} ${hm(r.Time)}`
            +` · load ${fmt(v,1)} MW`
            +(totE!=null?` · fuel ${fmt(totE,4)} BBTU`:'')
            +(gasE!=null?` · gas ${fmt(gasE,4)} BBTU (${100-pctS}%)`:'')
            +(distE!=null?` · dist ${fmt(distE,4)} BBTU`:'')
            +(dl!=null?` · ${fmt(dl,1)} l`:'');
        }
        return `<td class="g-${g}${sc&&!fixCls?' sc-mode':''}${fixCls}${unitMark}${dCls}"${fixAttr}${dStyle}${dData} title="${dTitle}">${blankZero?'':showV}</td>`;
      }
      if(MARK_KEYS[k]){            // GE1–GE4 and Babelan B1/B2 (G1–G10 handled in the GTG branch above)
        const sv=blankZero?'':(num?fmt(v,dec):(v??''));
        if(fk) return `<td class="g-${g}${fixCls}${unitMark}"${fixAttr} title="Ctrl+Click to fix/cancel load">${fixed!=null?fmt(fixed,2):sv}</td>`;
        return `<td class="g-${g}${unitMark}">${sv}</td>`;
      }
      if(g==='bus' && num){
        const mn=+(INPUT.data3?.modeling?.busflow_min)||0;
        let bc='bs-green'; if(v<mn-1e-6)bc='bs-red'; else if(v<mn+10)bc='bs-yellow';
        return `<td class="g-bus ${bc}">${fmt(v,2)}</td>`;
      }
      if(g==='spin' && num){
        const mn=+(INPUT.data3?.modeling?.spinning_reserve_min)||0;
        let sc2='bs-green'; if(v<mn-1e-6)sc2='bs-red'; else if(v<mn+10)sc2='bs-yellow';
        return `<td class="g-spin ${sc2}">${fmt(v,2)}</td>`;
      }
      if(k==='Total_Gas' && num){
        // TOTAL GAS (BBTUD) DISPLAY = per-30-min gas * 48 = Total_Gas(per-hour) * 24 (daily-equivalent)
        return `<td class="g-gas">${fmt(v*24,3)}</td>`;
      }
      if(g==='pgn' && k==='Flow_PGN_RT' && num){
        /* §3.3: merah < Min; kuning terang Min..Min+0.5; hijau >= Min+0.5. */
        const fv=+v||0;
        const mn=(+(INPUT.data3?.modeling?.min_pgn_flow)||0);
        let pc=''; if(mn>0){ pc=fv<mn-1e-9?' bs-red':(fv<mn+0.5-1e-9?' bs-yellow':' bs-green'); }
        return `<td class="g-pgn${pc}" title="FLOW PGN REAL TIME = ENERGY PGN RT / GHV PGN x 1000; Min ${mn} MMSCFD">${fmt(fv,3)}</td>`;
      }
      if(g==='pgn' && k==='EnergyPGN_RT' && num){
        /* §3.3: indikator warna dipindah ke FLOW PGN REAL TIME; kolom ini polos. */
        return `<td class="g-pgn">${fmt(+v||0,3)}</td>`;
      }
      if(g==='pgn' && k==='Est_PGN' && num){
        // ESTIMATION PGN TOTAL = ENERGY PGN REAL TIME / 48 (per-30-min, computed in backend)
        return `<td class="g-pgn">${fmt(v,3)}</td>`;
      }
      if(k==='FixedFlow_J' || k==='Total_Flow_M'){
        /* §11: manual Ctrl+Click kini di TOTAL FLOW GAS MM2100 (MMSCFD) — mengubah TOTAL GAS MM2100
         * = flow x GHV/1000 dan seluruh turunan (Cummulative, Est, Total Gas, quota, MaxFlow check). */
        const area=(k==='FixedFlow_J')?'JABABEKA':'MM2100';
        const man=cellFixedFlow(area,ri);
        let cls=(man!=null)?' ff-manual':'';
        if(k==='Total_Flow_M'){
          /* §10: TOTAL FLOW GAS MM2100 (MMSCFD) vs Maximum Flow MM2100: <=max-5 hijau;
           * (max-5,max] kuning terang; >max merah. Max kosong/0 -> tanpa warna. */
          const mx=+(INPUT.data3?.modeling?.max_flow_mm2100)||0;
          if(mx>0){
            const flowV=(man!=null)?(+man||0):(+v||0);
            cls+= flowV>mx+1e-9?' bs-red':(flowV>mx-5-1e-9?' bs-yellow':' bs-green');
          }
        }
        const sv=(man!=null)?fmt(man,2):(num?fmt(v,2):(v??''));
        return `<td class="g-pgn${cls}" data-ff="${area}" data-row="${ri}" title="Ctrl+Click to set/cancel manual flow (MMSCFD)">${sv}</td>`;
      }
      if(fk){
        return `<td class="g-${g}${fixCls}"${fixAttr} title="Ctrl+Click to fix/cancel load">${showV}</td>`;
      }
      return `<td class="g-${g}">${num?fmt(v,dec):(v??'')}</td>`;
    }).join('')+'</tr>';
  });
  tbl+='</tbody></table></div>';
  tbl+=buildGasShortagePanel(o.info||{});
  return tbl;
}
/* Entry point bersama untuk pemanggil di luar Daily (Weekly detail, Report rerun). */
window.renderSimDataInto=function(el, rows, dateLabel, info){
  if(!el) return false;
  el.innerHTML = ppBuildSimTable(rows||[], {data:rows||[], info:info||{}}, true);
  el.setAttribute('data-wk-date', dateLabel||'');
  el.setAttribute('data-shared-renderer','ppBuildSimTable');
  return true;
};

function toggleSummaryWarnings(panelId,btn){
  const panel=document.getElementById(panelId); if(!panel||!btn) return;
  const willOpen=panel.hidden;
  panel.hidden=!willOpen;
  btn.textContent=willOpen?'−':'+';
  btn.setAttribute('aria-expanded',willOpen?'true':'false');
  btn.title=willOpen?'Sembunyikan Notes & warnings':'Tampilkan Notes & warnings';
}

function renderResult(o){
  const info=o.info, rows=o.data;
  // Gas monitoring header (Revisi): Actual Total Gas PGN, Actual Energy Total Fixed Flow Jababeka / MM2100
  try{
    const gp=document.getElementById('gm-pgn'), gj=document.getElementById('gm-ffj'), gm=document.getElementById('gm-ffm');
    if(gp) gp.textContent=fmt(info['Actual Total Gas PGN (BBTUD)']||0,3);
    if(gj) gj.textContent=fmt(info['Actual Energy Total Fixed Flow Jababeka (BBTUD)']||0,3);
    if(gm) gm.textContent=fmt(info['Actual Energy Total Fixed Flow MM2100 (BBTUD)']||0,3);
  }catch(e){}
  const okPln=info['PLN Export Compliance']==='OK', okGas=info['Gas Quota Status']==='WITHIN QUOTA';
  let html='';
  // ---- Summary table (Number | Parameter | Value | Unit) ----
  const SUMMARY=SUMMARY_UI_ROWS;
  html+='<div class="sumwrap"><table class="sumtbl"><thead><tr><th class="c">#</th><th>Parameter</th><th class="r">Value</th><th>Unit</th></tr></thead><tbody>';
  SUMMARY.forEach(([label,key,unit,dec],i)=>{
    html+=`<tr><td class="c">${i+1}</td><td class="p">${label}</td><td class="r">${fmt(info[key],dec)}</td><td class="u">${unit}</td></tr>`;});
  html+='</tbody></table></div>';
  /* RINGKASAN KEPUTUSAN BAHAN BAKAR: angka yang dibutuhkan, yang diotorisasi operator, yang
   * benar-benar diterapkan, dan sisa kekurangan — seluruhnya dibaca dari hasil engine, kecuali
   * kebutuhan LNG dan LNG operator yang berasal dari keputusan di popup. */
  const fas=String(info['Fuel Action Source']||'');
  if(['add_lng','use_distillate','mixed_lng_distillate'].includes(fas)){
    const reqL=(info['Required LNG Equivalent (BBTUD)']!=null)?num(info['Required LNG Equivalent (BBTUD)']):GSD_REQ_LNG;
    const usrL=(info['User Authorized LNG (BBTUD)']!=null)?num(info['User Authorized LNG (BBTUD)']):(fas==='use_distillate'?0:GSD_USER_LNG);
    const dUsed=(info['Distillate Used (l)']!=null)?info['Distillate Used (l)']:info['Distillate Fuel Total (l)'];
    const FR=[['Required LNG',reqL,'BBTUD',4],['User LNG',usrL,'BBTUD',4],['Applied LNG',info['Added LNG (BBTUD)'],'BBTUD',4],
      ['Distillate Used',dUsed,'l/hari',0],['Effective Gas Quota',info['Effective Gas Quota (BBTUD)'],'BBTUD',4],
      ['Residual Shortage',info['Residual Gas Shortage (BBTUD)'],'BBTUD',4]];
    html+='<div class="sumwrap" id="fuel-decision-summary" style="margin-top:10px"><table class="sumtbl"><thead><tr><th class="c">#</th><th>Keputusan bahan bakar ('
      +gsfEsc(fas)+')</th><th class="r">Value</th><th>Unit</th></tr></thead><tbody>';
    FR.forEach(([l,v,u,d],i)=>{ html+=`<tr><td class="c">${i+1}</td><td class="p">${l}</td><td class="r">${(v==null||v==='')?'—':fmt(v,d)}</td><td class="u">${u}</td></tr>`; });
    html+='</tbody></table></div>';
  }
  // operational notes (shortage / distillate / warnings) kept below the table
  const shortage=num(info['Gas Shortage (BBTUD)']);
  if(!okGas && shortage>0){
    let litres=num(info['Recommended Distillate (l/day)'])||num(info['Required Distillate (l/day)']);
    if(!(litres>0)) litres=gsfLitresFromBbtu(shortage, info);
    html+=`<div class="rec">⚠ <b>Gas shortage ${fmt(shortage,3)} BBTUD</b> beyond the PGN quota — not auto-switched.
      To close it: add <b>${fmt(shortage,3)} BBTUD LNG</b>, or use <b>${litres>0?('~'+fmt(litres,0)+' litre/day distillate'):'distillate (estimasi liter tidak tersedia)'}</b>.
      Set the action under “Gas Shortage Decision” and run again.</div>`;
  }
  if(info['Distillate Fuel Total (l)']>0){
    const du=info['Distillate per unit (l)']||{};
    html+=`<div class="rec" style="background:#eef6ff;border-color:#bcd8f5;color:#1c4b7a">Distillate in use: <b>${fmt(info['Distillate Fuel Total (l)'],0)} litre/day</b> — `+
      Object.keys(du).map(k=>`${k.toUpperCase()} ${fmt(du[k],0)} l`).join(' · ')+`</div>`;
  }
  const w=info['Warnings']||[];
  if(w.length){
    const panelId='summary-notes-warnings-panel';
    html+=`<div class="warnbox summary-warnings-collapsed">`+
      `<div style="display:flex;align-items:center;gap:8px">`+
      `<button type="button" class="btn ghost" aria-expanded="false" aria-controls="${panelId}" `+
      `title="Tampilkan Notes & warnings" onclick="toggleSummaryWarnings('${panelId}',this)" `+
      `style="min-width:32px;padding:2px 9px;font-size:18px;line-height:1.2">+</button>`+
      `<b>Notes &amp; warnings (${w.length})</b></div>`+
      `<div id="${panelId}" hidden><ul>${w.map(x=>`<li>${esc(x)}</li>`).join('')}</ul></div></div>`;
  }
  /* Summary sengaja berhenti pada tabel ringkas + Notes & warnings. Panel MM2100/Bus/Evidence
     tetap tersedia di data OUTPUT dan export, tetapi tidak lagi dirender di bagian Summary. */
  html+=buildHeadroomAuditCard();   // V5: audit headroom & Unit Priority per row (terlipat)
  html+=buildV8PriorityCard();      // V8: kandidat pembanding Unit Priority + provenance gas MM2100
  $('result-summary').innerHTML=html;
  // ---- per-row dispatch table -> Simulation Data child (RENDERER BERSAMA) ----
  const tbl = ppBuildSimTable(rows, o, false);
  $('result-simdata').innerHTML=tbl;
  bindFixLoadCells();
  bindFixedFlowCells();
  updateActualStatus();
  // populate "Start From" dropdown for Download Image Partial (row times 00:30..00:00)
  const sf=$('img-start');
  if(sf){ sf.innerHTML=rows.map((r,ri)=>`<option value="${ri}">${hm(r.Time)}</option>`).join(''); }
}

/* ===================== IE Adjustment (Tahap 4) ===================== */
let IEADJ_RULES=[];   // {op:'+'/'-', value:Number, start:'HH:MM', stop:'HH:MM'}
function hm2min(t){ const m=/^(\d{1,2}):(\d{2})$/.exec((t||'').trim()); if(!m) return null; let v=(+m[1])*60+(+m[2]); return v; }
function ieStopMin(t){ const v=hm2min(t); return v===0?1440:v; }                 // "00:00" stop = 1440
function ieOccupied(excludeIdx){
  const occ=new Set();
  IEADJ_RULES.forEach((r,i)=>{ if(i===excludeIdx) return;
    const s=hm2min(r.start), e=ieStopMin(r.stop); if(s==null||e==null||e<=s) return;
    for(let m=s;m<=e && m<1440;m+=30) occ.add(m);   // inclusive of the stop slot: a 00:00–10:00 rule frees the next start at 10:30
  });
  return occ;
}
function ieStartOptions(i){
  const occ=ieOccupied(i), cur=IEADJ_RULES[i].start; let h='';
  for(let m=0;m<1440;m+=30){ if(occ.has(m) && minToHHMM(m)!==cur) continue;
    h+=`<option value="${minToHHMM(m)}" ${minToHHMM(m)===cur?'selected':''}>${minToHHMM(m)}</option>`; }
  return h;
}
function ieStopOptions(i){
  const occ=ieOccupied(i), cur=IEADJ_RULES[i].stop, S=hm2min(IEADJ_RULES[i].start)??0; let h='';
  for(let e=30;e<=1440;e+=30){
    if(e<=S){ if(minToHHMM(e%1440)!==cur) continue; }
    let free=true; for(let m=S;m<e;m+=30){ if(occ.has(m)){free=false;break;} }
    const lbl=minToHHMM(e%1440);                                                 // 1440 -> "00:00"
    if(!free && lbl!==cur) continue;
    h+=`<option value="${lbl}" ${lbl===cur?'selected':''}>${lbl}</option>`;
  }
  return h;
}
function earliestFreeStart(){
  const occ=ieOccupied(-1);
  for(let m=0;m<1440;m+=30) if(!occ.has(m)) return m;
  return null;
}
function renderIeAdj(){
  const host=$('tbl-ie-adj'); if(!host) return;
  let h='<tr><th>No</th><th>Operator</th><th>Adjustment Value</th><th>Start Period</th><th>Stop Period</th><th>Action</th></tr>';
  if(!IEADJ_RULES.length) h+='<tr><td colspan="6" class="sched-empty">No IE adjustment. Click “+ Add IE Adjustment”.</td></tr>';
  IEADJ_RULES.forEach((r,i)=>{
    h+=`<tr>
      <td>${i+1}</td>
      <td><select data-ie="op" data-i="${i}"><option value="+" ${r.op==='+'?'selected':''}>+</option><option value="-" ${r.op==='-'?'selected':''}>−</option></select></td>
      <td><input type="number" step="0.1" min="0" data-ie="value" data-i="${i}" value="${r.value}" style="width:90px"> <span class="u">MW</span></td>
      <td><select data-ie="start" data-i="${i}">${ieStartOptions(i)}</select></td>
      <td><select data-ie="stop" data-i="${i}">${ieStopOptions(i)}</select></td>
      <td><button type="button" class="rowdel" data-ie="del" data-i="${i}" title="Remove">✕</button></td>
    </tr>`;
  });
  host.innerHTML=h;
  host.querySelectorAll('[data-ie="op"]').forEach(el=>el.onchange=e=>{IEADJ_RULES[+e.target.dataset.i].op=e.target.value;});
  host.querySelectorAll('[data-ie="value"]').forEach(el=>el.oninput=e=>{IEADJ_RULES[+e.target.dataset.i].value=num(e.target.value);});
  host.querySelectorAll('[data-ie="start"]').forEach(el=>el.onchange=e=>{
    const i=+e.target.dataset.i, v=e.target.value;
    if(ieWouldOverlap(i,v,IEADJ_RULES[i].stop)){ toast('IE Adjustment period cannot overlap with existing adjustment period.'); renderIeAdj(); return; }
    IEADJ_RULES[i].start=v; if(ieStopMin(IEADJ_RULES[i].stop)<=hm2min(v)) IEADJ_RULES[i].stop=minToHHMM((hm2min(v)+30)%1440); renderIeAdj();
  });
  host.querySelectorAll('[data-ie="stop"]').forEach(el=>el.onchange=e=>{
    const i=+e.target.dataset.i, v=e.target.value;
    if(ieWouldOverlap(i,IEADJ_RULES[i].start,v)){ toast('IE Adjustment period cannot overlap with existing adjustment period.'); renderIeAdj(); return; }
    IEADJ_RULES[i].stop=v; renderIeAdj();
  });
  host.querySelectorAll('[data-ie="del"]').forEach(el=>el.onclick=e=>{IEADJ_RULES.splice(+e.target.dataset.i,1);renderIeAdj();});
  const add=$('btn-ieadj-add'); if(add) add.disabled=IEADJ_RULES.length>=5;
}
function ieWouldOverlap(idx,start,stop){
  const s=hm2min(start), e=ieStopMin(stop); if(s==null||e==null||e<=s) return false;
  const occ=ieOccupied(idx);
  for(let m=s;m<e;m+=30) if(occ.has(m)) return true;
  return false;
}
function initIeAdj(){
  const m=(INPUT.data3&&INPUT.data3.modeling)||{};
  const src=m.ie_adjustments||m.ie_adjustment||[];
  IEADJ_RULES=(Array.isArray(src)?src:[]).filter(a=>a&&(a.start_period||a.start)).slice(0,5).map(a=>({
    op:(a.operator==='-'||(a.value!=null&&+a.value<0))?'-':'+',
    value:Math.abs(num(a.value)),
    start:a.start_period||minToHHMM(((a.start||1)-1)*30),
    stop:a.stop_period||minToHHMM(((a.stop||1))*30)
  }));
}
function assembleIeAdj(m){
  m.ie_adjustments=IEADJ_RULES.filter(r=>num(r.value)!==0).map(r=>({
    operator:r.op, value:Math.abs(num(r.value)), start_period:r.start, stop_period:r.stop
  }));
}


const ACTUAL_COLS=[ // CSV header -> backend key (null = TIME, handled separately)
  ['TIME',null],['HOUSELOAD','hl'],['IE','ie'],['EXPORT PLN','export'],
  ['G1','g1'],['G2','g2'],['G3','g3'],['G4','g4'],['G5','g5'],['G6','g6'],['G7','g7'],['G8','g8'],['G9','g9'],['G10','g10'],
  ['S1','s1'],['S2','s2'],['S3','s3'],
  ['GAS ENGINE 1','ge1'],['GAS ENGINE 2','ge2'],['GAS ENGINE 3','ge3'],['GAS ENGINE 4','ge4'],
  ['BABELAN 1','b1'],['BABELAN 2','b2']
];
function actualRowTime(i){ return minToHHMM((i+1)*30); }   // row i(0-based) -> 00:30..00:00
function timeToActualRow(t){
  const m=/^(\d{1,2}):(\d{2})$/.exec((t||'').trim()); if(!m) return 0;
  let mins=(+m[1])*60+(+m[2]); if(mins===0) mins=1440;     // 00:00 = end of day
  const r=Math.round(mins/30); return (r>=1&&r<=SLOTS)?r:0;
}
function downloadActualFormat(){
  let csv=ACTUAL_COLS.map(c=>c[0]).join(',')+'\n';
  for(let i=0;i<SLOTS;i++){ csv+=actualRowTime(i)+','.repeat(ACTUAL_COLS.length-1)+'\n'; }
  const blob=new Blob([csv],{type:'text/csv'}); const a=document.createElement('a');
  a.href=URL.createObjectURL(blob); a.download='actual_data_format.csv'; a.click(); URL.revokeObjectURL(a.href);
}
function parseActualCSV(text){
  const lines=text.split(/\r?\n/).filter(l=>l.trim()!=='');
  if(!lines.length) return {rows:[]};
  const head=lines[0].split(',').map(s=>s.trim().toUpperCase());
  const idx={}; ACTUAL_COLS.forEach(c=>{idx[c[0]]=head.indexOf(c[0]);});
  const rows=[];
  for(let li=1;li<lines.length;li++){
    const cells=lines[li].split(',');
    const tv=idx['TIME']>=0?cells[idx['TIME']]:'';
    const row=timeToActualRow(tv); if(!row) continue;
    const o={row}; let any=false;
    ACTUAL_COLS.forEach(c=>{ if(c[1]===null)return; const j=idx[c[0]]; if(j<0)return;
      const raw=(cells[j]||'').trim(); if(raw===''){return;} const val=num(raw);
      o[c[1]]=val; any=true; });
    if(any) rows.push(o);
  }
  rows.sort((a,b)=>a.row-b.row);
  return {rows};
}
function applyActualData(ad){
  /* PROMPT STATE ISOLATION §6: upload actual Excel/CSV HANYA berlaku di Monitoring Daily Plan.
     Di Monitoring: cutoff TIME PASSED otomatis = row actual terakhir; locked_rows diisi dari
     nilai UPLOAD (bukan simulasi) sehingga angka sesuai Excel; metadata actual_upload_source.
     Di Plan: DIBLOKIR — Plan tidak boleh punya TIME PASSED / locked rows (§6.2). */
  if(!(typeof dpMon==='function'&&dpMon())){
    toast('Upload Actual Data hanya tersedia di cabang Monitoring Daily Plan');
    return false;
  }
  if(!INPUT.data3) INPUT.data3={}; if(!INPUT.data3.modeling) INPUT.data3.modeling={};
  INPUT.data3.modeling.actual_data=ad;
  const o=mdpObj(true);
  let lastRow=0; const lr={};
  (ad.rows||[]).forEach(a=>{
    const rn=+(a.row||0); if(rn<1||rn>48) return;
    lastRow=Math.max(lastRow,rn);
    const cp={...a}; delete cp._mdp; lr[rn-1]=cp;           // locked value = nilai upload (angka sesuai Excel)
  });
  o.enabled=true;
  o.time_passed_cutoff_row=lastRow;                          // §6.1: upload s/d 11:00 -> Y s/d 11:00 otomatis
  o.locked_rows=lr;
  o.actual_upload_source={rows:(ad.rows||[]).length,last_row:lastRow,
    last_time:lastRow?minToHHMM(lastRow*30):'',uploaded_at:new Date().toISOString().slice(0,19).replace('T',' ')};
  updateActualStatus();
  return true;
}
function updateActualStatus(){
  const el=$('actual-status'); if(!el) return;
  const ad=(INPUT.data3&&INPUT.data3.modeling&&INPUT.data3.modeling.actual_data)||null;
  const n=ad&&ad.rows?ad.rows.length:0;
  if(!n){ el.textContent='No actual data loaded — simulation covers 00:30–00:00.'; el.classList.remove('on'); return; }
  const last=ad.rows[ad.rows.length-1].row; const lastT=minToHHMM(last*30);
  const startT=last<SLOTS?minToHHMM((last+1)*30):'—';
  el.textContent=`Actual data loaded for ${n} slot(s), up to ${lastT}. Simulation window: ${startT}–00:00.`; el.classList.add('on');
}


/* ===================== Manual Fixed Flow — Ctrl+Click (Jababeka/MM2100) ===================== */
let FF_ROWS=[];   // {area:'JABABEKA'/'MM2100', row:1-based, value_mmscfd}
function cellFixedFlow(area,row){
  const r1=row+1;
  for(const e of (FF_ROWS||[])) if((e.area||'')===area && +e.row===r1) return num(e.value_mmscfd);
  return null;
}
function setFixedFlow(area,row,value){
  const r1=row+1;
  FF_ROWS=(FF_ROWS||[]).filter(e=>!((e.area||'')===area && +e.row===r1));
  FF_ROWS.push({area,row:r1,value_mmscfd:+value});
}
function clearFixedFlow(area,row){
  const r1=row+1;
  FF_ROWS=(FF_ROWS||[]).filter(e=>!((e.area||'')===area && +e.row===r1));
}
function openFFPop(td,area,row){
  closeFixPop();
  const cur=cellFixedFlow(area,row);
  const rect=td.getBoundingClientRect();
  const pop=document.createElement('div'); pop.id='fixpop'; pop.className='fixpop';
  pop.innerHTML=`<div class="fixpop-h">${area} · ${hm(OUTPUT.data[row].Time)}</div>
    <label class="fixpop-l">Fixed Flow <span>MMSCFD</span><input type="number" step="0.1" id="fixpop-val" value="${cur!=null?cur:(td.textContent.trim()||'')}"></label>
    <div class="fixpop-b">
      <button type="button" class="btn" id="fixpop-apply">Input Fixed Flow Manual</button>
      <button type="button" class="btn ghost" id="fixpop-cancel">Cancel Input</button>
    </div>`;
  document.body.appendChild(pop);
  pop.style.top=(window.scrollY+rect.bottom+6)+'px';
  pop.style.left=Math.min(window.scrollX+rect.left, window.scrollX+document.documentElement.clientWidth-pop.offsetWidth-12)+'px';
  $('fixpop-val').focus();
  $('fixpop-apply').onclick=()=>{ const v=num($('fixpop-val').value); setFixedFlow(area,row,v); closeFixPop(); toast('Fixed Flow '+area+' = '+v.toFixed(1)+' MMSCFD — re-running…'); runSim(); };
  $('fixpop-cancel').onclick=()=>{ clearFixedFlow(area,row); closeFixPop(); toast('Cleared manual fixed flow ('+area+') — re-running…'); runSim(); };
  setTimeout(()=>document.addEventListener('mousedown',fixPopOutside),0);
}
function bindFixedFlowCells(){
  const tbl=$('tbl-result'); if(!tbl) return;
  tbl.querySelectorAll('td[data-ff]').forEach(td=>{
    td.addEventListener('click',e=>{ if(!(e.ctrlKey||e.metaKey)) return; e.preventDefault(); e.stopPropagation();
      const rFF=+td.dataset.row;
      /* PROMPT CTRLCLICK §11: row TIME PASSED = Y = historical locked — tolak edit + warning */
      if(typeof dpMon==='function'&&dpMon()&&rFF<mdpCutoff()){
        toast('Row '+(OUTPUT&&OUTPUT.data&&OUTPUT.data[rFF]?hm(OUTPUT.data[rFF].Time)+' ':'')+'TIME PASSED = Y (locked/historical) — Fixed Flow tidak bisa diubah. Set TIME PASSED = N dulu jika ingin mengedit.');
        return;
      }
      openFFPop(td, td.dataset.ff, rFF); });
  });
}

function fixKeyOf(k){
  if(/^G\d+$/.test(k)) return k.toLowerCase();         // G1..G10
  if(/^S\d$/.test(k))  return k.toLowerCase();         // S1..S3
  if(/^GE\d$/.test(k)) return k.toLowerCase();         // GE1..GE4
  if(k==='BB1') return 'b1'; if(k==='BB2') return 'b2'; // Babelan 1/2
  return null;
}
function cellFixed(unit,row){
  const r1=row+1;
  for(const e of (FIX_ROWS||[])){
    if((e.unit||'')===unit && clamp1(e.start)<=r1 && clamp1(e.stop)>=r1) return num(e.value);
  }
  return null;
}
function setFixLoad(unit,row,value){
  const r1=row+1;
  FIX_ROWS=(FIX_ROWS||[]).filter(e=>!((e.unit||'')===unit && clamp1(e.start)<=r1 && clamp1(e.stop)>=r1));
  FIX_ROWS.push({unit,start:r1,stop:r1,value:+value});
}
function clearFixLoad(unit,row){
  const r1=row+1;
  FIX_ROWS=(FIX_ROWS||[]).filter(e=>!((e.unit||'')===unit && clamp1(e.start)<=r1 && clamp1(e.stop)>=r1));
}
function closeFixPop(){ const p=$('fixpop'); if(p) p.remove(); }
function openFixPop(td,unit,row){
  closeFixPop();
  const cur=cellFixed(unit,row);
  const rect=td.getBoundingClientRect();
  const pop=document.createElement('div'); pop.id='fixpop'; pop.className='fixpop';
  pop.innerHTML=`<div class="fixpop-h">${unit.toUpperCase()} · ${hm(OUTPUT.data[row].Time)}</div>
    <label class="fixpop-l">Fixed Load <span>MW</span><input type="number" step="0.1" id="fixpop-val" value="${cur!=null?cur:(td.textContent.trim()||'')}"></label>
    <div class="fixpop-b">
      <button type="button" class="btn" id="fixpop-apply">Input Fixed Load</button>
      <button type="button" class="btn ghost" id="fixpop-cancel">Cancel Fix Load</button>
    </div>`;
  document.body.appendChild(pop);
  pop.style.top=(window.scrollY+rect.bottom+6)+'px';
  pop.style.left=Math.min(window.scrollX+rect.left, window.scrollX+document.documentElement.clientWidth-pop.offsetWidth-12)+'px';
  $('fixpop-val').focus();
  $('fixpop-apply').onclick=()=>{ const v=num($('fixpop-val').value); setFixLoad(unit,row,v); closeFixPop(); toast('Fixed '+unit.toUpperCase()+' = '+v.toFixed(1)+' MW — re-running…'); runSim(); };
  $('fixpop-cancel').onclick=()=>{ clearFixLoad(unit,row); closeFixPop(); toast('Cleared fixed load on '+unit.toUpperCase()+' — re-running…'); runSim(); };
  setTimeout(()=>document.addEventListener('mousedown',fixPopOutside),0);
}
function fixPopOutside(e){ const p=$('fixpop'); if(p && !p.contains(e.target)){ closeFixPop(); document.removeEventListener('mousedown',fixPopOutside); } }
function bindFixLoadCells(){
  const tbl=$('tbl-result'); if(!tbl) return;
  tbl.querySelectorAll('td[data-fix]').forEach(td=>{
    td.addEventListener('click',e=>{
      if(!(e.ctrlKey||e.metaKey)) return;       // only Ctrl/Cmd + Click
      e.preventDefault(); e.stopPropagation();
      const rFL=+td.dataset.row;
      /* PROMPT CTRLCLICK §10.1: row TIME PASSED = Y = historical locked — tolak edit + warning */
      if(typeof dpMon==='function'&&dpMon()&&rFL<mdpCutoff()){
        toast('Row '+(OUTPUT&&OUTPUT.data&&OUTPUT.data[rFL]?hm(OUTPUT.data[rFL].Time)+' ':'')+'TIME PASSED = Y (locked/historical) — Fix Load tidak bisa diubah. Set TIME PASSED = N dulu jika ingin mengedit.');
        return;
      }
      openFixPop(td, td.dataset.fix, rFL);
    });
  });
}

/* ===================== overview / pills ===================== */
function refreshPills(){
  const m=INPUT.data3?.modeling;
  $('pill-plan-t').textContent=((m&&m.name_plan)||'No plan')+((m&&m.plan_date)?(' · '+m.plan_date):'');
  $('pill-plan').className='pill';
  if(!OUTPUT||!OUTPUT.info){return;}
  const info=OUTPUT.info;
  const setp=(id,ok,txt)=>{const e=$(id);e.className='pill '+(ok?'ok':'bad');$(id+'-t').textContent=txt;};
  setp('pill-pln',info['PLN Export Compliance']==='OK',info['PLN Export Compliance']);
  setp('pill-gas',info['Gas Quota Status']==='WITHIN QUOTA',info['Gas Quota Status']);
  const c=$('pill-cost');c.className='pill';$('pill-cost-t').textContent='Cost $'+fmt(info['Cost Production (USD/MWh)'],2)+'/MWh';
}
function refreshOverview(){
  const box=$('ov-kpis'), trend=$('ov-trend');
  if(!OUTPUT||!OUTPUT.info){box.innerHTML='<div class="placeholder" style="grid-column:1/-1"><h3>No run yet</h3><p>Run a Daily Plan to populate the overview.</p></div>';trend.innerHTML='';return;}
  const info=OUTPUT.info;
  const items=[['Daily PLN Exp (MWh)','MWh','PLN export',info['PLN Export Compliance']==='OK'?'good':'alert'],
    ['Total Fuel (BBTUD)','BBTUD','Total fuel (gross)',''],
    ['Gas Fuel Total (BBTUD)','BBTUD','Gas fuel (effective)',info['Gas Quota Status']==='WITHIN QUOTA'?'good':'alert'],
    ...(((+info['Distillate Energy (BBTUD)']||0)>0)?[['Distillate Energy (BBTUD)','BBTUD','Distillate energy','']]:[]),
    ['Cost Production (USD/MWh)','$/MWh','Cost',''],['Total Cost (USD)','USD','Total cost',''],
    ['JBBK MM Prod (MWh)','MWh','JBBK prod',''],['Babelan Prod (MWh)','MWh','Babelan prod',''],
    ['PGN Total (BBTUD)','BBTUD','PGN',''],['Total Coal (ton)','ton','Coal','']];
  box.innerHTML=items.map(([k,u,l,cls])=>`<div class="kpi ${cls}"><div class="l">${l}</div><div class="v">${fmt(info[k],k.includes('Cost Production')?2:0)} <small>${u}</small></div></div>`).join('');
  // mini trend table
  const rows=OUTPUT.data||[];
  let h='<table class="data"><thead><tr><th class="t">Time</th><th>IE</th><th>Export</th><th>Band</th><th>Gas Σ</th><th>JBBK</th><th>BB Σ</th></tr></thead><tbody>';
  rows.forEach(r=>{h+=`<tr class="${r.in_band?'':'rowbad'}"><td class="t">${hm(r.Time)}</td><td>${fmt(r.IE,1)}</td><td>${fmt(r.Export_PLN,1)}</td>
    <td><span class="tag ${r.in_band?'ok':'bad'}">${r.in_band?'IN':'OUT'}</span></td><td>${fmt(r.Total_Gas,3)}</td><td>${fmt(r.Jababeka,1)}</td><td>${fmt(r.BB_Total,1)}</td></tr>`;});
  trend.innerHTML=h+'</tbody></table>';
}

/* ===================== CSV import ===================== */
/* ============================================================================================
 *  CSV IE PREDICTION & DISPATCH — FORMAT TIGA KOLOM (Date, IE, Dispatch)
 *
 *  Date  : dd-mmm-yy (contoh 05-Aug-26). Header case-insensitive, trim; "Tanggal" diterima.
 *  Jam   : TIDAK dibaca dari CSV. 48 slot aplikasi tetap 00:30 .. 00:00 (indeks tidak digeser).
 *          Hanya bagian TANGGAL pada kolom Time yang mengikuti CSV; slot ke-48 (00:00) jatuh
 *          pada H+1 sesuai konvensi harian aplikasi.
 *  Nilai : 0 SAH. Empty / NaN / teks nonnumerik DITOLAK.
 *  CSV dua kolom lama DITOLAK dengan pesan eksplisit.
 * ========================================================================================== */
const CSV_MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function csvFmtDate(d){ return String(d.getDate()).padStart(2,'0')+'-'+CSV_MON[d.getMonth()]+'-'+String(d.getFullYear()%100).padStart(2,'0'); }
/* dd-mmm-yy -> Date (yy: 70..99 = 19xx, selain itu 20xx). null bila tidak valid. */
function csvParseDate(sv){
  const t=String(sv==null?'':sv).trim();
  const m=t.match(/^(\d{1,2})-([A-Za-z]{3})-(\d{2}|\d{4})$/);
  if(!m) return null;
  const mi=CSV_MON.findIndex(x=>x.toLowerCase()===m[2].toLowerCase());
  if(mi<0) return null;
  const dd=+m[1]; if(dd<1||dd>31) return null;
  let yy=+m[3]; if(m[3].length===2) yy=(yy>=70?1900+yy:2000+yy);
  const d=new Date(yy,mi,dd);
  if(d.getFullYear()!==yy||d.getMonth()!==mi||d.getDate()!==dd) return null;
  return d;
}
/* slot i (0..47) -> 'HH:MM'; slot 0 = 00:30, slot 47 = 00:00 (H+1) */
function csvSlotTime(i){ const mm=(i+1)*30; const hh=Math.floor(mm/60)%24; return String(hh).padStart(2,'0')+':'+String(mm%60).padStart(2,'0'); }
function csvSlotIsNextDay(i){ return i===47; }
/* label Time lengkap: 'dd-mmm-yy HH:MM' */
function csvTimeLabel(baseDate,i){
  const d=new Date(baseDate.getTime()); if(csvSlotIsNextDay(i)) d.setDate(d.getDate()+1);
  return csvFmtDate(d)+' '+csvSlotTime(i);
}
/* nilai numerik ketat: '' / NaN / teks -> null (invalid); '0' -> 0 (sah) */
function csvNum(v){ const t=String(v==null?'':v).trim(); if(t==='') return null;
  const n=Number(t.replace(',','.')); return isFinite(n)?n:null; }

/* Parser + validator. Mengembalikan report; TIDAK memutasi apa pun. */
function parseCSV3(text){
  const rep={ok:false,rows:[],errors:[],dates:[],validRows:0,errorRows:0,expected:'Date,IE,Dispatch (Date = dd-mmm-yy)'};
  const raw=String(text==null?'':text).replace(/^\uFEFF/,'');
  const lines=raw.split(/\r?\n/).filter(l=>l.trim()!=='');
  if(!lines.length){ rep.errors.push('File CSV kosong.'); return rep; }
  const split=l=>l.split(/[,;\t]/).map(x=>x.trim());
  const head=split(lines[0]).map(x=>x.toLowerCase());
  const hasHeader=head.some(h=>/^(date|tanggal|ie|dispatch)$/.test(h));
  let iD=0,iI=1,iP=2,start=0;
  if(hasHeader){
    start=1;
    const f=n=>head.findIndex(h=>n.test(h));
    const d=f(/^(date|tanggal)$/), a=f(/^ie$/), b=f(/^dispatch$/);
    if(d<0&&a>=0&&b>=0){
      /* Header lama dua kolom (IE,Dispatch) — tolak dengan pesan format baru yang eksplisit. */
      rep.errors.push('Format CSV dua kolom TIDAK didukung lagi. Format baru membutuhkan tiga kolom: Date, IE, Dispatch (Date = dd-mmm-yy).');
      return rep;
    }
    if(d<0||a<0||b<0){
      rep.errors.push('Header tidak lengkap. Wajib ada kolom Date (atau Tanggal), IE, dan Dispatch. Ditemukan: '+split(lines[0]).join(', '));
      return rep;
    }
    iD=d; iI=a; iP=b;
  } else {
    const c0=split(lines[0]);
    if(c0.length<3){
      rep.errors.push('Format CSV dua kolom TIDAK didukung lagi. Format baru membutuhkan tiga kolom: Date, IE, Dispatch (Date = dd-mmm-yy).');
      return rep;
    }
  }
  const body=lines.slice(start);
  if(!body.length){ rep.errors.push('Tidak ada baris data setelah header.'); return rep; }
  body.forEach((ln,k)=>{
    const c=split(ln); const rowNo=k+1+start;
    if(c.length<3){
      rep.errors.push('Baris '+rowNo+': hanya '+c.length+' kolom. Format baru membutuhkan Date, IE, Dispatch.');
      rep.errorRows++; return;
    }
    const d=csvParseDate(c[iD]); const ie=csvNum(c[iI]); const dp=csvNum(c[iP]);
    const bad=[];
    if(!d)  bad.push('Date "'+c[iD]+'" tidak valid (format dd-mmm-yy)');
    if(ie===null) bad.push('IE "'+c[iI]+'" bukan numerik');
    if(dp===null) bad.push('Dispatch "'+c[iP]+'" bukan numerik');
    if(bad.length){ rep.errors.push('Baris '+rowNo+': '+bad.join('; ')); rep.errorRows++; return; }
    rep.rows.push({date:d,dateStr:csvFmtDate(d),ie:ie,disp:dp});
    rep.validRows++;
    const ds=csvFmtDate(d); if(rep.dates.indexOf(ds)<0) rep.dates.push(ds);
  });
  if(rep.dates.length>1)
    rep.errors.push('File memuat lebih dari satu tanggal ('+rep.dates.join(', ')+'). Satu file hanya boleh untuk satu hari.');
  if(rep.errorRows===0 && rep.dates.length===1 && rep.validRows!==48)
    rep.errors.push('Jumlah baris data = '+rep.validRows+', wajib tepat 48 baris untuk satu hari.');
  rep.ok = rep.errors.length===0 && rep.validRows===48 && rep.dates.length===1;
  return rep;
}
/* kompatibilitas nama lama: selalu lewat validator baru */
function parseCSV(text){ const r=parseCSV3(text); return r.ok?r.rows.map(x=>({ie:x.ie,disp:x.disp,date:x.date})):[]; }

function applyIED(arr){
  /* Jalur lama dipertahankan untuk paste/kompatibilitas, tetapi kini memakai kontrak baru:
   * arr = [{ie,disp,date}] hasil parseCSV3 yang SUDAH tervalidasi. Invalid -> tidak memutasi apa pun. */
  if(!Array.isArray(arr)||!arr.length){
    csvSetStatus('CSV ditolak: format baru membutuhkan tiga kolom Date, IE, Dispatch (Date = dd-mmm-yy).',true);
    return false;
  }
  return csvApplyRows(arr.slice(0,48));
}
/* Terapkan 48 baris tervalidasi ke INPUT (source of truth) lalu render ulang tabel. */
function csvApplyRows(rows){
  if(!Array.isArray(rows)||rows.length!==48) return false;
  const base=rows[0].date instanceof Date ? rows[0].date : csvParseDate(rows[0].dateStr);
  if(!base) return false;
  if(!INPUT.data1) INPUT.data1=[]; if(!INPUT.data2) INPUT.data2=[];
  for(let i=0;i<48;i++){
    const label=csvTimeLabel(base,i);                       /* tanggal CSV + slot aplikasi */
    INPUT.data1[i]=INPUT.data1[i]||{}; INPUT.data2[i]=INPUT.data2[i]||{};
    INPUT.data1[i].time=label; INPUT.data2[i].time=label;
    INPUT.data1[i].value=rows[i].ie;                        /* 0 tetap 0 */
    INPUT.data2[i].dispatch=rows[i].disp;
  }
  if(typeof buildIED==='function') buildIED();
  if(typeof updateIEDsum==='function') updateIEDsum();
  csvSetStatus('Applied: '+csvFmtDate(base)+' · 48 slot (00:30 → 00:00) · IE & Dispatch diperbarui.',false);
  return true;
}
/* ---- UX: status, preview, validate, apply, clear, reset ---- */
let CSV_LAST_TEXT=null, CSV_LAST_NAME='';
let CSV_PENDING=null;        /* report hasil parse terakhir (belum di-apply) */
let CSV_SNAPSHOT=null;       /* salinan data1/data2 sebelum apply -> untuk Reset */
function csvSetStatus(msg,isErr){
  const el=document.getElementById('csv-status');
  if(!el){ if(typeof toast==='function') toast(msg); return; }
  el.style.display='block';
  el.className='csv-status '+(isErr?'bad':'ok');
  el.innerHTML=String(msg).replace(/</g,'&lt;');
}
function csvRenderPreview(rep,fileName){
  const box=document.getElementById('csv-preview'); if(!box) return;
  const head='<div style="font-weight:700;margin-bottom:4px">'+(fileName?('File: '+fileName+' · '):'')
    +'tanggal terdeteksi: '+(rep.dates.length?rep.dates.join(', '):'-')
    +' · valid '+rep.validRows+' row · error '+rep.errorRows+' row</div>';
  let body='';
  if(rep.rows.length){
    const base=rep.rows[0].date;
    body='<table class="csvprev"><tr><th>#</th><th>Time</th><th>IE</th><th>Dispatch</th></tr>'
      + rep.rows.slice(0,5).map((r,k)=>'<tr><td>'+(k+1)+'</td><td>'+csvTimeLabel(base,k)+'</td><td>'+r.ie+'</td><td>'+r.disp+'</td></tr>').join('')
      + (rep.rows.length>5?('<tr><td colspan="4">… '+(rep.rows.length-5)+' baris lain</td></tr>'):'')
      + '</table>';
  }
  const errs=rep.errors.length
    ? '<ul class="csverr">'+rep.errors.slice(0,8).map(e=>'<li>'+String(e).replace(/</g,'&lt;')+'</li>').join('')
      +(rep.errors.length>8?('<li>… '+(rep.errors.length-8)+' error lain</li>'):'')+'</ul>'
    : '';
  box.style.display='block';
  box.innerHTML=head+body+errs
    +'<div style="margin-top:6px;color:#5b6b7a;font-size:11px">Format diharapkan: '+rep.expected+'</div>';
}
function csvHandleText(text,fileName){
  const rep=parseCSV3(text);
  CSV_PENDING=rep;
  csvRenderPreview(rep,fileName);
  if(rep.ok) csvSetStatus('CSV valid — tekan Apply untuk menerapkan ke 48 slot.',false);
  else csvSetStatus('CSV DITOLAK ('+rep.errors.length+' masalah). Data existing TIDAK diubah.',true);
  const ab=document.getElementById('csv-apply'); if(ab) ab.disabled=!rep.ok;
  return rep;
}
function csvApplyPending(){
  if(!CSV_PENDING||!CSV_PENDING.ok){ csvSetStatus('Tidak ada CSV valid untuk di-apply. Data existing TIDAK diubah.',true); return false; }
  CSV_SNAPSHOT={d1:JSON.parse(JSON.stringify(INPUT.data1||[])),d2:JSON.parse(JSON.stringify(INPUT.data2||[]))};
  return csvApplyRows(CSV_PENDING.rows);
}
function csvClear(){
  CSV_PENDING=null;
  const b=document.getElementById('csv-preview'); if(b){ b.style.display='none'; b.innerHTML=''; }
  const ab=document.getElementById('csv-apply'); if(ab) ab.disabled=true;
  csvSetStatus('Preview dibersihkan. Data existing tidak diubah.',false);
}
function csvReset(){
  if(!CSV_SNAPSHOT){ csvSetStatus('Tidak ada apply sebelumnya untuk di-reset.',true); return false; }
  INPUT.data1=JSON.parse(JSON.stringify(CSV_SNAPSHOT.d1));
  INPUT.data2=JSON.parse(JSON.stringify(CSV_SNAPSHOT.d2));
  CSV_SNAPSHOT=null;
  if(typeof buildIED==='function') buildIED();
  if(typeof updateIEDsum==='function') updateIEDsum();
  csvSetStatus('Reset: data IE/Dispatch dikembalikan ke kondisi sebelum Apply.',false);
  return true;
}

function resultToHTMLTable(){
  if(!OUTPUT) return '';
  const info=OUTPUT.info, rows=OUTPUT.data;
  let s='<table border="1"><tr><th colspan="2">'+(INPUT.data3.modeling.name_plan||'Daily Plan')+'</th></tr>';
  if(OUTPUT.time_limited===true) s+=(OUTPUT.result_label==='FASTEST VALID PLAN')?'<tr><th colspan="2" style="background:#fef3c7">FASTEST VALID PLAN — kandidat fully valid pertama (Global optimum proven: NO)</th></tr>':'<tr><th colspan="2" style="background:#fef3c7">TIME-LIMITED VALID PLAN — BEST VALID WITHIN TIME LIMIT (Global optimum proven: NO)</th></tr>';
  Object.keys(info).forEach(k=>{if(k==='Warnings'||k==='Distillate per unit (l)')return;
    s+=`<tr><td>${k}</td><td>${info[k]}</td></tr>`;});
  s+='</table><br><table border="1"><tr>'+COLS.map(c=>`<th>${c[2]}</th>`).join('')+'</tr>';
  rows.forEach((r,ri)=>{s+='<tr>'+COLS.map(c=>{
    const fk=fixKeyOf(c[0]); const fx=fk?cellFixed(fk,ri):null;
    if(fx!=null) return `<td style="background:#ffd2d2;font-weight:bold">${fmt(fx,2)}</td>`;
    return `<td>${r[c[0]]??''}</td>`;
  }).join('')+'</tr>';});
  return s+'</table>';
}
/* ===== PROMPT PLAN SAVE §2 — pilihan Save khusus tab Plan =====================================
   ROOT CAUSE "Save existing tidak punya dua pilihan": tombol Save di TABEL DETAIL SLOT (#btn-save-actual)
   ter-bind LANGSUNG ke saveActualGas() lewat event delegation, dan rpSaveCurrentPlan() hanya pernah
   membuat SATU record — plan_type-nya ditentukan otomatis dari cabang aktif (dpMon()), tanpa pilihan user.
   Jadi tidak ada titik keputusan sama sekali. Modal ini menyisipkan titik keputusan itu, HANYA di tab Plan;
   di cabang Monitoring perilaku lama dipertahankan apa adanya (§2).
   Modal memakai DOM asli + addEventListener (bukan innerHTML tanpa binding), sehingga tombolnya benar-benar
   dapat diklik dan tidak menutup fungsi tombol lain. */
function svAskSaveOption(cb){
  const old=document.getElementById('sv-mask'); if(old) old.remove();
  const mask=document.createElement('div'); mask.className='sv-mask'; mask.id='sv-mask';
  const box=document.createElement('div'); box.className='sv-box'; box.setAttribute('role','dialog'); box.setAttribute('aria-modal','true');
  const h=document.createElement('h4'); h.textContent='Save simulation'; box.appendChild(h);
  const note=document.createElement('p'); note.className='sv-note';
  note.textContent='Pilih tujuan penyimpanan. Keduanya menyimpan input, hasil simulasi, summary, dan TABEL DETAIL SLOT sebagai snapshot lengkap di Report → Planning.';
  box.appendChild(note);
  const mk=(id,cls,title,sub)=>{ const b=document.createElement('button'); b.type='button'; b.id=id; b.className=cls;
    const t=document.createTextNode(title); b.appendChild(t);
    const sm=document.createElement('small'); sm.textContent=sub; b.appendChild(sm); return b; };
  const b1=mk('sv-opt-report','sv-opt','Save Only to Report',
    'Satu record Plan di Report → Planning. Tidak membuat Monitoring Daily Plan dan tidak mengubah state Monitoring.');
  const b2=mk('sv-opt-both','sv-opt sv-mon','Save to Report and Monitoring Daily Plan',
    'Dua record terpisah: Plan + Monitoring Daily Plan (row oranye). Snapshot Monitoring berdiri sendiri (deep copy).');
  box.appendChild(b1); box.appendChild(b2);
  const act=document.createElement('div'); act.className='sv-actions';
  const bc=document.createElement('button'); bc.type='button'; bc.id='sv-opt-cancel'; bc.className='sv-cancel'; bc.textContent='Cancel';
  act.appendChild(bc); box.appendChild(act);
  mask.appendChild(box); document.body.appendChild(mask);
  const done=(v)=>{ mask.remove(); document.removeEventListener('keydown',onKey); cb(v); };
  const onKey=(e)=>{ if(e.key==='Escape') done(null); };
  b1.addEventListener('click',()=>done('report'));
  b2.addEventListener('click',()=>done('both'));
  bc.addEventListener('click',()=>done(null));
  mask.addEventListener('click',(e)=>{ if(e.target===mask) done(null); });
  document.addEventListener('keydown',onKey);
  try{ b1.focus(); }catch(e){}
  return mask;
}
/* Titik masuk tombol Save di TABEL DETAIL SLOT. Di tab Plan -> tanya dulu; di Monitoring -> perilaku lama. */
function svSaveEntry(){
  if(OUTPUT && OUTPUT.time_limited===true){
    const m=$('run-msg'); const t='Save Plan / Publish Final terkunci: hasil ini TIME-LIMITED VALID PLAN (belum dibuktikan global optimum). Export tetap tersedia; pilih Maximum Review untuk hasil final.';
    if(m) m.innerHTML='<span style="color:#b45309">'+gsfEsc(t)+'</span>'; if(typeof toast==='function') toast(t); return;
  }
  const isMon=(typeof dpMon==='function')&&dpMon();
  if(isMon){ saveActualGas(); return; }                       // §2: fitur ini khusus tab Plan
  svAskSaveOption((choice)=>{
    if(!choice) return;                                        // Cancel -> tidak menyimpan apa pun
    saveActualGas({alsoMonitoring:(choice==='both')});
  });
}
function saveActualGas(opts){
  opts=opts||{};
  // Revisi: read the manual ACTUAL cells, persist to INPUT + OUTPUT, recompute the 3 monitoring sums (actual-only).
  /* PROMPT ACTUAL GAS 1H §6: satu field per JAM — nilai di index genap pasangan, ganjil '' */
  const aP=[],aJ=[],aM=[];
  document.querySelectorAll('#tbl-result .actin').forEach(el=>{
    const ri=+el.dataset.row,key=el.dataset.act,val=el.value===''?'':num(el.value);
    const T=key==='pgn'?aP:(key==='ffj'?aJ:aM);
    T[ri]=val; T[ri+1]='';});
  const fill=a=>{for(let i=0;i<SLOTS;i++)if(a[i]===undefined)a[i]='';return a;};
  if(!INPUT.data3)INPUT.data3={}; if(!INPUT.data3.modeling)INPUT.data3.modeling={};
  const m=INPUT.data3.modeling;
  m.actual_pgn_total=fill(aP); m.actual_energy_jababeka=fill(aJ); m.actual_energy_mm2100=fill(aM);
  // reflect into OUTPUT.data so downloads (Excel/Image) use the latest values without a re-run
  let sP=0,sJ=0,sM=0;
  if(OUTPUT&&OUTPUT.data){OUTPUT.data.forEach((row,i)=>{
    /* echo per JAM: nilai hanya di row genap pasangan (merged cell), row ganjil null — sums 1x per jam */
    const vp=(i%2===0&&aP[i]!==''&&aP[i]!==undefined)?aP[i]:null;
    const vj=(i%2===0&&aJ[i]!==''&&aJ[i]!==undefined)?aJ[i]:null;
    const vm=(i%2===0&&aM[i]!==''&&aM[i]!==undefined)?aM[i]:null;
    row.Act_PGN=vp; row.Act_FF_J=vj; row.Act_FF_M=vm;
    if(vp!==null)sP+=vp; if(vj!==null)sJ+=vj; if(vm!==null)sM+=vm;
  });}
  // monitoring (actual-only; 0 if blank)
  const gp=document.getElementById('gm-pgn'),gj=document.getElementById('gm-ffj'),gm2=document.getElementById('gm-ffm');
  if(gp)gp.textContent=fmt(sP,3); if(gj)gj.textContent=fmt(sJ,3); if(gm2)gm2.textContent=fmt(sM,3);
  if(OUTPUT&&OUTPUT.info){OUTPUT.info['Actual Total Gas PGN (BBTUD)']=sP;
    OUTPUT.info['Actual Energy Total Fixed Flow Jababeka (BBTUD)']=sJ;
    OUTPUT.info['Actual Energy Total Fixed Flow MM2100 (BBTUD)']=sM;}
  // ---- Report → Planning integration (Revisi): snapshot the full simulation into REPORT_PLANNING,
  //      keyed by Plan Date + Name Plan, then persist to input_data.json so it survives reload and
  //      appears in the Report → Planning table.
  let planInfo=null;
  try{ if(typeof rpSaveCurrentPlan==='function') planInfo=rpSaveCurrentPlan({alsoMonitoring:!!opts.alsoMonitoring}); }catch(e){}
  const persistAndToast=()=>{
    if(typeof rpRender==='function' && document.getElementById('rp-table')) rpRender();
    if(typeof toast==='function'){
      if(planInfo) toast(`Saved — Report → Planning ${planInfo.updated?'updated':'added'}: ${planInfo.namePlan}`);
      else toast('Actual gas saved — monitoring updated');
    }
  };
  /* PROMPT LIVE-STATE §3: Save dan Run TERPISAH. Save Actual Gas WAJIB mem-persist actual ke
     input_data.json (via ?mode=save), lalu Run (runSim, ?mode=run) hanya me-redispatch & render —
     Run sendiri TIDAK lagi menulis file. Urutan: persist dulu (agar reload mempertahankan actual),
     baru auto re-run untuk memperbarui summary/report/comparison. */
  (async()=>{
    try{ if(typeof rpRender==='function' && document.getElementById('rp-table')) rpRender(); }catch(e){}
    if(typeof toast==='function'){
      if(planInfo){
        /* PROMPT PLAN SAVE §4: laporkan KEDUA record bila kembaran Monitoring ikut dibuat. */
        const mi=planInfo.monitoring;
        toast(`Saved — Report → Planning ${planInfo.updated?'updated':'added'}: ${planInfo.namePlan}`
              + (mi?` · Monitoring ${mi.updated?'updated':'added'}: ${mi.namePlan}`:'')
              + ' — re-running with actual gas…');
      }
      else toast('Actual gas saved — auto re-running simulation…');
    }
    /* §3.2: persist state aktif (termasuk actual per-jam) ke input_data.json — Save eksplisit. */
    try{
      const payload=assembleInput();
      await fetch('run.php?mode=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    }catch(e){}
    if(typeof runSim==='function') await runSim();          // §4.2: future rows redispatch dgn actual gas sbg prioritas (render, non-persist)
    else persistAndToast();
  })();
}
function downloadExcel(){
  /* Penjaga di DALAM fungsi, bukan sekadar tombol disabled: inilah jalur yang menghasilkan
     workbook ber-`Fuel Action Source = recommendation` dengan residual 9,7385. */
  if(gsdBlocked('Export Excel')) return;
  if(!OUTPUT){toast('Run a simulation first');return;}
  const html='<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'+resultToHTMLTable()+'</body></html>';
  const blob=new Blob([html],{type:'application/vnd.ms-excel'});
  const a=document.createElement('a');a.href=URL.createObjectURL(blob);
  a.download=(INPUT.data3.modeling.name_plan||'daily_plan').replace(/[^\w]+/g,'_')+'.xls';a.click();URL.revokeObjectURL(a.href);
}
/* Download Image (Revisi Sec.6): capture Simulation Data from TIME up to and including
 * Energy Real Time Gas MM2100 (key FixedFlow_M). Full = all rows; Partial = from a chosen Start From row.
 * A cropped clone is rendered offscreen so the live (sticky/scrolled) table is untouched. */
/* ===================== Gas Shortage Recommendation (Revisi) =====================
 * Shown below TABEL DETAIL SLOT when the dispatch needs more gas than the quota.
 * Figures are recommendations only — nothing is auto-applied; the user picks an
 * action which re-runs the simulation with the chosen scenario. */
function fmtInt(n){ return (Math.round(+n||0)).toLocaleString('en-US'); }
function buildGasShortagePanel(info){ return ''; }

function gsrForceSameAsQuota(){
  if(typeof setGasAction==='function') setGasAction('force_same_as_quota');
  toast('Re-running: forcing Total Gas into quota window (Export held at Range Min)…');
  runSim();
}
function gsrSetLng(bbtud){
  // setGasAction enables the field; assembleInput reads f-gas_action + f-additional_lng
  if(typeof setGasAction==='function') setGasAction('add_lng');
  const inp=$('f-additional_lng'); if(inp){ inp.disabled=false; inp.value=(+bbtud).toFixed(3); }
}
function gsrAddLngRecommendation(bbtud){
  gsrSetLng(+bbtud);
  toast('Re-running with +'+(+bbtud).toFixed(2)+' BBTUD LNG…');
  runSim();
}
function gsrUseDistillate(){
  if(typeof setGasAction==='function') setGasAction('use_distillate');
  toast('Re-running with distillate…');
  runSim();
}
function gsrAddLngQuota(){
  const v=num(($('gsr-lng-input')||{}).value);
  if(v<=0){toast('Enter an LNG quota to add');return;}
  gsrSetLng(v);
  toast('Re-running with +'+v.toFixed(2)+' BBTUD LNG…');
  runSim();
}

function buildSimImageTable(startRow){
  const rows=OUTPUT.data||[];
  const cut=COLS.findIndex(c=>c[0]===IMG_LAST_COL);
  const cols=COLS.slice(0,cut+1);
  // mirror the live Simulation Data look (group colours, pills, Export/Diff order),
  // but static-positioned (imgcap) so html2canvas renders cleanly.
  let h='<table class="data simgrid imgcap"><thead><tr>'+
    cols.map(c=>`<th class="g-${c[3]||''}${c[1]==='t'?' t':''}">${esc(c[2])}</th>`).join('')+'</tr></thead><tbody>';
  for(let ri=Math.max(0,startRow|0);ri<rows.length;ri++){const r=rows[ri];
    h+='<tr>'+cols.map(c=>{
      const k=c[0],t=c[1],g=c[3]||'';let v=r[k];
      if(t==='t')return `<td class="t g-time">${hm(v)}</td>`;
      const num=typeof v==='number';
      const fk=fixKeyOf(k); const fixed=fk?cellFixed(fk,ri):null; const fcls=fixed!=null?' fix-load':'';
      if(g==='dispatch'&&num){const cls=v>=150?'green':(v>=90?'orange':'slate');return `<td class="g-dispatch"><span class="pill ${cls}">${fmt(v,2)}</span></td>`;}
      if(g==='export')return `<td class="g-export cell-export">${num?fmt(v,2):(v??'')}</td>`;
      if(g==='diff'&&num){const sign=v>1e-6?'pos':(v<-1e-6?'neg':'zero');return `<td class="g-diff"><span class="pill ${sign}">${(v>1e-6?'+':'')+fmt(v,2)}</span></td>`;}
      let dec=2; if(g==='gtg'||g==='gtg2'||g==='ge')dec=1; else if(t==='g'||g==='gas'||g==='pgn')dec=3;
      const shw=(fixed!=null)?fmt(fixed,2):(num?fmt(v,dec):(v??''));
      // zero-blank + start/stop markers for unit-load columns (Revisi UI Sec.4/5) — mirror the live grid
      const MK={G1:1,G2:1,G3:1,G4:1,G5:1,G6:1,G7:1,G8:1,G9:1,G10:1,BB1:1,BB2:1,GE1:1,GE2:1,GE3:1,GE4:1};
      if(MK[k]){
        let mk='';
        const cur=num?v:0;
        const prv=ri>0?(+(rows[ri-1][k])||0):null;
        const nxt=ri<rows.length-1?(+(rows[ri+1][k])||0):null;
        if(cur<=1e-9 && !fcls){ if(prv!==null&&prv>1e-9)mk=' cell-stop'; else if(nxt!==null&&nxt>1e-9)mk=' cell-startup'; }
        /* PROMPT GAS SHORTAGE Sec.11: warna Distillate ikut terekam di Image Full/Partial */
        const dmx2=+(r['DistMix_'+k]||0); const dist2=(dmx2>0&&cur>1e-9); const dcls2=dist2?(' dist-m'+Math.round(dmx2*100)+' dist-cell'):'';
        return `<td class="g-${g}${fcls}${mk}${dcls2}"${dist2?distCellStyle(r,k)+distCellData(r,k):''}>${(cur<=1e-9)?'':shw}</td>`;
      }
      return `<td class="g-${g}${fcls}">${shw}</td>`;
    }).join('')+'</tr>';}
  return h+'</tbody></table>';
}
/* ===== PROMPT IMAGE EXPORT §4/§7: Summary offscreen table (SELALU 22 baris No.1..Cost Production) =====
 * Dibangun dari SUMMARY_ROWS + OUTPUT.info (sumber yang sama dgn render live), sehingga TIDAK bergantung
 * scroll/viewport dan tidak pernah memotong baris bawah. Context-correct otomatis: OUTPUT = state cabang
 * aktif (Plan atau Monitoring). Partial & Full identik utk Summary (tabel ringkas, wajib utuh). */
function buildSummaryImageTable(){
  const info=(OUTPUT&&OUTPUT.info)||{};
  let h='<table class="data sumtbl imgcap"><thead><tr>'+
    '<th class="c">#</th><th>Parameter</th><th class="r">Value</th><th>Unit</th></tr></thead><tbody>';
  SUMMARY_UI_ROWS.forEach(([label,key,unit,dec],i)=>{
    h+=`<tr><td class="c">${i+1}</td><td class="p">${esc(label)}</td><td class="r">${fmt(info[key],dec)}</td><td class="u">${esc(unit)}</td></tr>`;
  });
  return h+'</tbody></table>';
}
/* ===== PROMPT IMAGE EXPORT §5: Comparison offscreen table (hanya bila CMP terisi) =====
 * Mirror renderCompare() tetapi statis (imgcap). Return null bila tidak ada data -> pemanggil TIDAK
 * membuat file kosong. CMP milik context aktif. */
function buildComparisonImageTable(){
  if(!CMP.length) return null;
  let h='<table class="data sumtbl cmptbl imgcap"><thead><tr><th class="c">#</th><th class="p">Parameter</th>'+
    CMP.map(c=>`<th class="r">${esc(c.name)}</th>`).join('')+'<th>Unit</th></tr></thead><tbody>';
  SUMMARY_UI_ROWS.forEach(([label,key,unit,dec],i)=>{
    h+=`<tr><td class="c">${i+1}</td><td class="p">${esc(label)}</td>`;
    const base=num(CMP[0].info[key]);
    CMP.forEach((c,ci)=>{
      const v=num(c.info[key]); let dev='';
      if(ci>0){const d=v-base; if(Math.abs(d)>1e-9) dev=` <span class="dev ${d>0?'up':'down'}">${d>0?'▲':'▼'}${fmt(Math.abs(d),dec)}</span>`;}
      h+=`<td class="r">${fmt(v,dec)}${dev}</td>`;
    });
    h+=`<td class="u">${esc(unit)}</td></tr>`;
  });
  return h+'</tbody></table>';
}

/* ===== PROMPT IMAGE EXPORT §2/§6/§7/§8/§9: unduh Simulation Data + Summary (+ Comparison) sebagai
 * FILE TERPISAH. mode='full' -> seluruh kolom+row; mode='partial' -> Simulation Data mulai img-start,
 * Summary tetap utuh 22 baris, Comparison utuh. Loading state + anti double-click + try/finally +
 * per-section error report + UI restore (offscreen build, tidak menyentuh scroll/sticky tabel live). */
let IMG_EXPORT_BUSY=false;
function fnameSanitize(s){ return String(s||'').replace(/[^\w\-]+/g,'_').replace(/_+/g,'_').replace(/^_|_$/g,''); }
function imgBaseName(){
  const m=(INPUT&&INPUT.data3&&INPUT.data3.modeling)||{};
  const nm=(typeof computeNamePlan==='function')
    ? computeNamePlan(m.plan_date||'', m.plan_remark||'')
    : (m.name_plan||'Daily Plan');            // sudah context-aware: "Daily Plan .." / "Monitoring Daily Plan .."
  return fnameSanitize(nm)||'Daily_Plan';
}
function capNodeToPng(node){
  return window.html2canvas(node,{backgroundColor:'#ffffff',scale:2,logging:false}).then(cv=>{
    if(!cv||!cv.width||!cv.height) throw new Error('canvas kosong');
    return cv.toDataURL('image/png');
  });
}
function triggerDownload(dataUrl,filename){
  const a=document.createElement('a'); a.href=dataUrl; a.download=filename; document.body.appendChild(a); a.click(); a.remove();
}
async function ensureHtml2canvas(){
  if(window.html2canvas) return true;
  await new Promise((res,rej)=>{ const s=document.createElement('script');
    s.src='https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    s.onload=res; s.onerror=()=>rej(new Error('gagal memuat library gambar (offline?)')); document.head.appendChild(s); });
  return !!window.html2canvas;
}
async function downloadResultImages(mode){
  if(gsdBlocked('Download Image')) return;
  if(!OUTPUT){ toast('Run a simulation first'); return; }
  if(IMG_EXPORT_BUSY) return;                                   // §9.1/§9.10 anti double-click
  IMG_EXPORT_BUSY=true;
  const bF=$('btn-img-full'), bP=$('btn-img-partial');
  const oF=bF?bF.innerHTML:'', oP=bP?bP.innerHTML:'';
  if(bF){bF.disabled=true;} if(bP){bP.disabled=true;}
  const active=(mode==='partial')?bP:bF;
  if(active) active.innerHTML='<span class="spin"></span> Capturing…';
  const startRow=(mode==='partial')?(+(($('img-start')||{}).value||0)):0;
  const base=imgBaseName();
  const modeTag=(mode==='partial')?'Partial':'Full';
  /* daftar section deterministik; Comparison hanya bila terisi (§2.1/§2.2/§5) */
  const sections=[
    {name:'Simulation_Data', html:buildSimImageTable(startRow)},
    {name:'Summary',         html:buildSummaryImageTable()},
  ];
  const cmpHtml=buildComparisonImageTable();
  if(cmpHtml) sections.push({name:'Comparison', html:cmpHtml});
  const okList=[], failList=[];
  try{
    await ensureHtml2canvas();
    for(const sec of sections){                                 // §9.3/§9.4 satu per satu, tunggu selesai
      const host=document.createElement('div');
      host.style.cssText='position:fixed;left:-99999px;top:0;background:#fff;padding:14px;font-family:var(--mono)';
      host.innerHTML=sec.html;
      document.body.appendChild(host);
      try{
        const url=await capNodeToPng(host.firstChild);
        triggerDownload(url, `${base}_${sec.name}_${modeTag}.png`);   // §8 nama unik per section+mode
        okList.push(sec.name);
      }catch(e){ failList.push(sec.name+' ('+(e&&e.message||e)+')'); }
      finally{ try{host.remove();}catch(e){} }                  // §6.6 restore: host offscreen dibersihkan
    }
  }catch(e){
    failList.push('library: '+(e&&e.message||e));
  }finally{                                                     // §9.6/§9.7 loading SELALU berhenti
    if(bF){bF.disabled=false; bF.innerHTML=oF;}
    if(bP){bP.disabled=false; bP.innerHTML=oP;}
    IMG_EXPORT_BUSY=false;
  }
  if(failList.length && okList.length) toast('Sebagian gambar gagal: '+failList.join(', ')+'. Terunduh: '+okList.join(', '));
  else if(failList.length) toast('Gagal membuat gambar: '+failList.join(', '));
  else{
    const note=cmpHtml?'':' (Comparison dilewati — belum ada data)';
    toast(okList.length+' gambar '+modeTag+' terunduh: '+okList.join(', ')+note);
  }
}
/* kompatibilitas: pemanggil lama tetap bekerja, kini mengekspor semua section */
function downloadSimImage(startRow){ return downloadResultImages((startRow|0)>0?'partial':'full'); }
function _legacyDownloadSimImage(startRow){
  if(!OUTPUT){toast('Run a simulation first');return;}
  const host=document.createElement('div');
  host.style.cssText='position:fixed;left:-99999px;top:0;background:#fff;padding:14px;font-family:var(--mono)';
  host.innerHTML=buildSimImageTable(startRow);
  document.body.appendChild(host);
  const base=(INPUT.data3.modeling.name_plan||'simulation_data').replace(/[^\w]+/g,'_');
  const suffix=(startRow|0)>0?('_from_'+hm(OUTPUT.data[startRow].Time).replace(':','')):'_full';
  const done=()=>{try{host.remove();}catch(e){}};
  const go=()=>window.html2canvas(host.firstChild,{backgroundColor:'#ffffff',scale:2}).then(cv=>{
    const a=document.createElement('a');a.href=cv.toDataURL('image/png');a.download=base+suffix+'.png';a.click();done();
  }).catch(()=>{done();toast('Image capture failed');});
  if(window.html2canvas){go();return;}
  toast('Preparing image…');
  const s=document.createElement('script');
  s.src='https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
  s.onload=go; s.onerror=()=>{done();toast('Offline — cannot load image library');};
  document.head.appendChild(s);
}

/* ===================== comparison ===================== */
function addCompare(){
  if(!OUTPUT){toast('Run a simulation first');return;}
  /* §12.4: header tiap kolom/dataset = Name Plan (identitas sama dgn Report → Planning:
   * computeNamePlan(plan_date, remark) => "Daily Plan 02-Jul-26", "... Rev 01", dst). */
  const m=INPUT.data3.modeling||{};
  const pd=(m.plan_date&&/^\d{4}-\d{2}-\d{2}$/.test(m.plan_date))?m.plan_date:new Date().toISOString().slice(0,10);
  const nm=(typeof computeNamePlan==='function')?computeNamePlan(pd, m.plan_remark||''):(m.name_plan||('Plan '+(CMP.length+1)));
  CMP.push({name:nm, info:OUTPUT.info});
  renderCompare(); toast('Added to comparison ('+CMP.length+')');
}
function renderCompare(){
  const empty=$('cmp-empty');
  if(!CMP.length){$('cmp-card').style.display='none';if(empty)empty.style.display='';return;}
  $('cmp-card').style.display='';if(empty)empty.style.display='none';
  // header: # | Parameter | <plan 1..N> | Unit  (new plans append left of Unit)
  let h='<thead><tr><th class="c">#</th><th class="p">Parameter</th>'+
    CMP.map(c=>`<th class="r">${esc(c.name)}</th>`).join('')+'<th>Unit</th></tr></thead><tbody>';
  SUMMARY_UI_ROWS.forEach(([label,key,unit,dec],i)=>{
    h+=`<tr><td class="c">${i+1}</td><td class="p">${label}</td>`;
    const base=num(CMP[0].info[key]);
    CMP.forEach((c,ci)=>{
      const v=num(c.info[key]); let dev='';
      if(ci>0){const d=v-base; if(Math.abs(d)>1e-9) dev=` <span class="dev ${d>0?'up':'down'}" title="vs ${esc(CMP[0].name)}">${d>0?'▲':'▼'}</span>`;}
      h+=`<td class="r">${fmt(v,dec)}${dev}</td>`;
    });
    h+=`<td class="u">${unit}</td></tr>`;
  });
  $('tbl-cmp').innerHTML=h+'</tbody>';
}

window.gsRunFromButton=function(ev){if(ev){ev.preventDefault();ev.stopPropagation();}const b=document.getElementById('btn-run'),m=document.getElementById('run-msg');try{if(typeof window.runSim!=='function')throw new Error('runSim belum tersedia');if(b&&b.dataset.gsRunning==='1')return false;if(b)b.dataset.gsRunning='1';Promise.resolve(window.runSim()).catch(e=>{if(m)m.textContent='Run gagal: '+String(e&&e.message||e);}).finally(()=>{if(b)delete b.dataset.gsRunning;});}catch(e){if(m)m.textContent='Run gagal dipanggil: '+String(e&&e.message||e);if(b)delete b.dataset.gsRunning;}return false;};
/* ===================== wire up ===================== */
if(HAVE_INPUT){
  buildPeriodic(); buildFrequent();
  /* direct Run bridge */
  $('btn-save').addEventListener('click',saveInput);
  $('btn-dd-add').addEventListener('click',()=>{ if(DD_RULES.length<5){DD_RULES.push({min:0,max:0,start:1,stop:1});renderDdRules();} });
  { const rb=$('btn-range-add'); if(rb) rb.addEventListener('click',addRangeRule); }
  { const mb=$('btn-ml-add'); if(mb) mb.addEventListener('click',addMaxLoadRule); }
  $('btn-ieadj-add').addEventListener('click',()=>{
    if(IEADJ_RULES.length>=5){ toast('Maximum 5 IE adjustments.'); return; }
    const sMin=earliestFreeStart();
    if(sMin===null){ toast('No free period left for a new IE adjustment.'); return; }
    // earliest free contiguous stop
    const occ=ieOccupied(-1); let eMin=sMin+30; while(eMin<1440 && !occ.has(eMin)) eMin+=30;
    IEADJ_RULES.push({op:'+',value:0,start:minToHHMM(sMin),stop:minToHHMM(eMin%1440)});
    renderIeAdj();
  });
  // hide KP72 required-banner once any KP72 input gets a value
  $('grid-quota').addEventListener('input',e=>{
    if(e.target&&e.target.id&&e.target.id.indexOf('q-')===0&&e.target.id.endsWith('_kp72')){
      const any=KP72_KEYS.some(k=>+(($('q-'+k)||{}).value)>0);
      const b=$('kp72-warn'); if(b&&any)b.style.display='none';
    }
  });
  $('btn-reset').addEventListener('click',()=>{INPUT=JSON.parse(JSON.stringify(INPUT0));buildPeriodic();buildFrequent();toast('Reloaded saved input');});
  $('btn-xls').addEventListener('click',downloadExcel);
  document.addEventListener('click',e=>{ if(e.target&&e.target.id==='btn-save-actual') svSaveEntry(); });   // PROMPT PLAN SAVE §2
  /* ===== PROMPT FULLSCREEN §2/§4/§7 — teleport DOM asli (bukan clone/screenshot) =====
     Open : sisipkan anchor di posisi #result-simdata, lalu PINDAHKAN node #result-simdata
            (berisi simhead + Save + tabel utuh) ke dalam #simfs-body. Karena node-nya SAMA,
            semua handler tetap hidup: input .actin (dibind langsung), TIME PASSED .tp-sel,
            Save, Full Screen (delegated di document) — dan setiap perubahan data otomatis
            adalah data utama (tidak ada sinkronisasi ganda yang bisa gagal).
     Close: kembalikan node ke posisi anchor semula; tidak ada duplikasi, layout utama utuh.
     Re-render saat full screen (mis. ganti TIME PASSED) aman: renderResult mengisi ulang
     #result-simdata yang sedang berada DI DALAM modal. */
  function simfsOpen(){
    const modal=$('simfs-modal'), body=$('simfs-body'), sd=$('result-simdata');
    if(!modal||!body||!sd||modal.classList.contains('open')) return;
    if(!$('simfs-anchor')){ const a=document.createElement('div'); a.id='simfs-anchor'; a.hidden=true; sd.parentNode.insertBefore(a,sd); }
    body.appendChild(sd);                       // teleport table asli
    modal.classList.add('open');
    document.body.style.overflow='hidden';      // scroll halaman dikunci; scroll tabel via .simwrap/modal
  }
  function simfsClose(){
    const modal=$('simfs-modal'), sd=$('result-simdata'), a=$('simfs-anchor');
    if(!modal||!modal.classList.contains('open')) return;
    if(sd&&a&&a.parentNode){ a.parentNode.insertBefore(sd,a); a.remove(); }   // kembalikan ke posisi semula
    modal.classList.remove('open');
    document.body.style.overflow='';
  }
  document.addEventListener('click',e=>{
    if(e.target&&e.target.id==='btn-sim-fullscreen') simfsOpen();
    if(e.target&&e.target.id==='simfs-close') simfsClose();
    if(e.target&&e.target.id==='simfs-modal') simfsClose();                   // klik backdrop = close
  });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') simfsClose(); });
  { const gf=$('btn-img-full'); if(gf) gf.addEventListener('click',()=>downloadResultImages('full')); }
  // Actual Data (Tahap 3)
  { const af=$('btn-actual-format'); if(af) af.addEventListener('click',downloadActualFormat); }
  { const afl=$('actual-file'); if(afl) afl.addEventListener('change',e=>{
    const f=e.target.files&&e.target.files[0]; if(!f)return;
    const rd=new FileReader();
    rd.onload=()=>{ const ad=parseActualCSV(String(rd.result||''));
      if(!ad.rows.length){toast('No valid actual rows found in the file');return;}
      if(applyActualData(ad)===false) return;               // §6.2: diblokir di cabang Plan
      toast('Actual data loaded ('+ad.rows.length+' slots) — TIME PASSED locked s/d row terakhir, re-running…'); runSim(); };
    rd.readAsText(f); e.target.value='';
  }); }
  { const acl=$('btn-actual-clear'); if(acl) acl.addEventListener('click',()=>{
    if(INPUT.data3&&INPUT.data3.modeling) delete INPUT.data3.modeling.actual_data;
    updateActualStatus(); toast('Actual data cleared — re-running…'); runSim();
  }); }
  { const ip=$('btn-img-partial'); if(ip) ip.addEventListener('click',()=>downloadResultImages('partial')); }
  { const bc=$('btn-cmp'); if(bc) bc.addEventListener('click',addCompare); }
  { const cc=$('btn-cmp-clear'); if(cc) cc.addEventListener('click',()=>{CMP.length=0;renderCompare();}); }
  /* BUGFIX (Master Audit Bagian C/D root cause): #btn-report-xls tidak ada di DOM, sehingga
   * addEventListener pada null melempar TypeError dan MEMATIKAN semua binding di bawahnya
   * (Import CSV #csv-file, Paste, + Add Stop/Fix/Skip, Gas Shortage segmented). Semua binding
   * di blok ini sekarang null-guarded agar satu elemen hilang tidak melumpuhkan yang lain. */
  { const rx=$('btn-report-xls'); if(rx) rx.addEventListener('click',downloadExcel); }
  { const cf=$('csv-file'); if(cf) cf.addEventListener('change',ev=>{const f=ev.target.files[0];if(!f)return;
      const rd=new FileReader(); CSV_LAST_TEXT=null; CSV_LAST_NAME=f.name;
      rd.onload=()=>{ CSV_LAST_TEXT=String(rd.result); csvHandleText(CSV_LAST_TEXT,CSV_LAST_NAME); };
      rd.readAsText(f); ev.target.value='';}); }
  { const b=$('csv-validate'); if(b) b.addEventListener('click',()=>{
      if(CSV_LAST_TEXT==null){ csvSetStatus('Belum ada CSV yang dimuat.',true); return; }
      csvHandleText(CSV_LAST_TEXT,CSV_LAST_NAME); }); }
  { const b=$('csv-apply');    if(b) b.addEventListener('click',()=>csvApplyPending()); }
  { const b=$('csv-clear');    if(b) b.addEventListener('click',()=>csvClear()); }
  { const b=$('csv-reset');    if(b) b.addEventListener('click',()=>csvReset()); }
  { const bp=$('btn-paste'); if(bp) bp.addEventListener('click',async()=>{try{const t=await navigator.clipboard.readText();csvHandleText(t,'(paste)');}catch(e){toast('Clipboard blocked — use Import CSV');}}); }
  // Unit Status & Adjustments — add-row buttons (null-guarded so a missing element elsewhere can't leave these unbound)
  { const bs=$('btn-add-stop'); if(bs) bs.addEventListener('click',()=>{STOP_ROWS.push({unit:STOP_UNITS[0],allDay:true,start:1,stop:SLOTS});renderStopTable();}); }
  { const bf=$('btn-add-fix'); if(bf) bf.addEventListener('click',()=>{FIX_ROWS.push({unit:FIXSKIP_UNITS[0],value:0,start:1,stop:1});renderFixTable();}); }
  { const bk=$('btn-add-skip'); if(bk) bk.addEventListener('click',()=>{SKIP_ROWS.push({unit:FIXSKIP_UNITS[0],low:0,high:0,start:1,stop:1});renderSkipTable();}); }
  // Gas Shortage Decision — segmented control
  document.querySelectorAll('#gsd-seg .gsd-opt').forEach(b=>b.addEventListener('click',()=>{setGasAction(b.dataset.act);refreshGasDecision();}));
  { const sel=$('f-tl-target'); if(sel){
      try{ const v=localStorage.getItem('pp_tl_target'); if(v && [...sel.options].some(o=>o.value===v)) sel.value=v; }catch(e){}
      sel.addEventListener('change',()=>{ try{ localStorage.setItem('pp_tl_target',sel.value); }catch(e){} }); } }
  setGasAction(($('f-gas_action')||{}).value||'recommendation');
}
// initial paint of result/overview if a previous output exists
if(OUTPUT&&OUTPUT.info&&$('result-summary')){renderResult(OUTPUT);}
refreshOverview(); refreshPills(); refreshGasDecision();

/* ===== Apply the Simulation Result EDITABLE "clone UI" mode (PROMPT PLAN SAVE §8) =====
   ROOT CAUSE versi lama: fungsi ini SENGAJA mengunci halaman — ia memasang atribut `disabled` pada
   SETIAP input/select/textarea di dokumen dan menyembunyikan btn-run/btn-save/btn-save-actual/btn-reset.
   Jadi "UI tidak bisa diklik" bukan JS error / duplicate ID / overlay / pointer-events, melainkan
   read-only mode yang memang dirancang begitu. §8 sekarang mewajibkan sebaliknya: tab baru harus
   membuka clone UI Daily Plan yang PENUH dan EDITABLE, dapat Run Simulation dan Save.

   Snapshot sudah di-deep-copy ke INPUT/INPUT0/OUTPUT oleh loader SIM_VIEW di atas
   (JSON.parse(JSON.stringify(...))), sehingga tab ini punya state sendiri: mengedit di sini TIDAK
   menyentuh record tersimpan sampai user menekan Save (§8.3), dan tidak menyentuh tab lain. */
function applySimViewMode(){
  if(!SIM_VIEW) return;
  const banner=document.createElement('div');
  banner.id='sim-view-banner';
  banner.style.cssText='position:sticky;top:0;z-index:9999;background:#1e3a5f;color:#fff;padding:10px 16px;font:600 13px system-ui,Segoe UI,Arial;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.2)';
  if(SIM_VIEW.error){
    banner.style.background='#b91c1c';
    banner.innerHTML='⚠ Simulation Result — '+SIM_VIEW.error.replace(/</g,'&lt;');
    document.body.insertBefore(banner,document.body.firstChild);
    return;
  }
  const isMon=(SIM_VIEW.plan_type==='monitoring');
  if(isMon) banner.style.background='#9a3412';                       // oranye gelap = konteks Monitoring
  banner.innerHTML='📝 <span>Simulation Result — <b>'+(SIM_VIEW.name||'').replace(/</g,'&lt;')+
    '</b> · '+(isMon?'Monitoring snapshot':'Plan snapshot')+' · Plan Date '+(SIM_VIEW.date||'')+
    (SIM_VIEW.saved_at?(' · saved '+SIM_VIEW.saved_at):'')+
    '</span><span style="margin-left:auto;opacity:.9;font-weight:400">Saved snapshot — editable. '+
    'Run Simulation aktif; Save memakai key Plan Date + Name Plan + type (ubah Name Plan untuk Save As).</span>';
  document.body.insertBefore(banner,document.body.firstChild);

  /* §8.2: snapshot Monitoring WAJIB terbuka pada cabang Monitoring supaya TIME PASSED, warna Y/N,
     locked rows, dan actual data ikut tampil. Cabang dibaca dari INPUT snapshot itu sendiri. */
  try{
    const m=INPUT&&INPUT.data3&&INPUT.data3.modeling;
    if(m){
      if(isMon){ m.monitoring_daily_plan=m.monitoring_daily_plan||{enabled:true,time_passed_cutoff_row:0,locked_rows:{}};
                 m.monitoring_daily_plan.enabled=true; m.plan_type='monitoring'; }
      else if(m.monitoring_daily_plan){ m.monitoring_daily_plan.enabled=false; m.plan_type='plan'; }
    }
  }catch(e){}

  // Land on Daily Plan → Result: dispatch table + summary adalah yang pertama dilihat user.
  try{ document.querySelectorAll('.tab').forEach(x=>x.setAttribute('aria-selected','false'));
       document.querySelectorAll('.panel').forEach(x=>x.classList.remove('active'));
       const dt=document.querySelector('.tab[data-tab="daily"]'); if(dt){dt.setAttribute('aria-selected','true');}
       const dp=$('panel-daily'); if(dp) dp.classList.add('active');
       if(typeof activateSub==='function') showSimulationDataResult();
  }catch(e){}

  /* TIDAK ADA penguncian: tidak memasang disabled, tidak menyembunyikan tombol, tidak memasang overlay
     atau pointer-events:none. Justru bersihkan sisa kunci bila ada dari render sebelumnya, supaya
     seluruh input/dropdown/tab/Ctrl+Click/Full Screen/Run/Save benar-benar hidup (§8.1). */
  setTimeout(()=>{
    ['btn-run','btn-save','btn-save-actual','btn-reset'].forEach(id=>{ const b=$(id); if(b) b.style.display=''; });
    document.querySelectorAll('#tbl-result .actin[disabled]').forEach(el=>el.removeAttribute('disabled'));
  },0);
}
applySimViewMode();

/* ===== §3 PROMPT_UI_AUDIT: window.__uiAudit() — audit binding ringan (dev/console) ===== */
window.__uiAudit=function(){
  const rep={buttons:0,noHandler:[],dupIds:[],missing:[],activeTab:null,ok:true};
  const ids={};document.querySelectorAll('[id]').forEach(e=>{ids[e.id]=(ids[e.id]||0)+1;});
  rep.dupIds=Object.entries(ids).filter(([k,v])=>v>1).map(([k])=>k);
  const must=['grid-quota','result-simdata','f-min_pgn_flow','f-ghv_pgn','f-busflow_min'];
  must.forEach(id=>{ if(!document.getElementById(id)) rep.missing.push(id); });
  const btns=[...document.querySelectorAll('button')]; rep.buttons=btns.length;
  btns.forEach(b=>{ if(!b.onclick && !b.dataset.tab && !b.closest('[data-tab]') && b.type!=='submit'){
    /* heuristik: tombol tanpa onclick inline mungkin ter-bind via addEventListener — tidak bisa
       diinspeksi; hanya laporkan yang benar2 tanpa id/class actionable */
    if(!b.id && !b.className) rep.noHandler.push(b.textContent.trim().slice(0,30)); } });
  const at=document.querySelector('.tab.active,[data-tab].active'); rep.activeTab=at?(at.dataset.tab||at.textContent.trim()):null;
  rep.ok=rep.dupIds.length===0&&rep.missing.length===0;
  console.table?console.log('[__uiAudit]',rep):null;
  return rep;
};
</script>
<?php
$weeklyUi=__DIR__.'/weekly_ui.php';if(is_file($weeklyUi))include_once $weeklyUi;
?>
</body>
</html>