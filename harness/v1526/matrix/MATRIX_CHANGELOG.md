# MATRIX CHANGELOG — V15.25 (basis matrix V15.24: 207 test engine + 14 test DB/UI-store = 221)

ID engine lama T001–T207 dipertahankan (definisi identik dengan V15.24; hanya kolom `baseline_version` = V15.24 (d207780) dan `build` = V15.25).
Test baru ditambahkan **sesudah** J10 sehingga ID lama stabil:

| ID | grup | isi | oracle |
|---|---|---|---|
| T208–T213 | K | Distillate Unit Priority 1–6 (Block 1–2 digabung): payload lama 2 grup = payload gabungan (output identik A0); Block 2 di atas Block 1; campuran lintas blok; unit prioritas 1 trip; unit prioritas 1 Stop (tidak berbeban); plafon kecil | hanya unit eligible (Running & legal) yang memakai distillate, prioritas eligible pertama dipakai, unit Stop tidak distart, alasan unit dilewati tercatat |
| T214–T219 | L | 2+2 sebelum 3+1 (S1 & S2 paralel): IE +30 (reason code AUTO_BALANCE_2X2_BEFORE_3X1), IE +0, IE +15, intent user eksplisit G1 Start Based On Request 08:00, blok lawan tidak legal (G3/G4 trip), prioritas blok ditukar | info `Block Balance 2x2` dengan reason code; intent user tidak ditimpa |
| T220–T226 | N | Block 3 cold (40 MW 3,5 jam, 50 MW 1 jam, 60 MW 2,5 jam; S3 berbeban tepat start + 7,5 jam); Start Up CC kemarin (elapsed 3); Start Up CC manual; kelanjutan + commitment STG eksplisit; backward target S1 06:00 (same day) dan 02:00 (kelanjutan dari kemarin); kelanjutan selesai kemarin | profil per row eksak, row first load STG, info `Startup Continuation` |
| T227–T228 | M | Maximum Review tidak lebih buruk dari Fastest (A0, A3) | CP MR <= CP Fastest |
| T229 | X | prescreen batas atas kapasitas Export OFF (`PP_V1525_CAPBOUND=0`, app dingin) | output identik A0 |

Total V15.25: **229 test engine + 14 test grup I/H15/H16 (gi.php) = 243**, ditambah UI (smoke 26, popup 203, distillate 5, yesterday 12) dan backend yesterday 9.

Catatan penomoran: ID ditetapkan berurutan menurut urutan definisi di `mxdefs.py`; tabel di atas mengikuti urutan itu.

Oracle T207 (J10 Maximum Review smoke) tetap informatif seperti V15.24; oracle tegas MR >= Fastest ada di grup M.

Uji di luar `mx.py` (tetap bagian gate):
- `../ui/popup.js` — Ctrl+Click / Fix Load popup (laptop 1366x768, FHD 1920x1080, tablet 1024x768; zoom 100/125/150 %; tabel normal & Full Screen; Esc/Enter/klik luar/Tab trap/scroll).
- `../ui/dist.js` — Distillate Priority 1–6 (gabung, migrasi payload lama, reorder klik nyata, Save/Reload).
- `../ui/yd.js` + `../yd/yd_test.php` — Check Yesterday Data (Monitoring > Daily Plan > No Data, versi terbaru, akhir bulan/tahun, data rusak, project lain, tanggal tidak valid; Apply/Cancel/Reload/override; commitment unit/STG eksplisit tidak ditimpa; Start Up CC manual; target backward).
- `../../v1524/ui/smoke.js` — 26 UI semantic smoke V15.24 (tanpa perubahan).
- `gi.php` — grup I + H15/H16 (MySQL).
- `../bench/bench.py` — benchmark PHP 7.4.33 satu logical CPU (3 cold A0 + gas/LNG/terminal/actual cold + 3x warm: exact rerun, Actual PGN/FFJ/MM2100, Fix Load).
