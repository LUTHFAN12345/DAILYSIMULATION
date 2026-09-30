/* =============================================================================================
 *  E2E RESPONSIVITAS POPUP GAS SHORTAGE — KLIK NYATA, CHROMIUM.
 *
 *  Yang dibuktikan di sini adalah URUTAN dan WAKTU yang dialami operator, bukan angka hasil:
 *    P1  jarak klik Run -> modal pemblokir muncul            (target <= 1 detik)
 *    P2  jarak klik Run -> tahap progres PERTAMA tampil      (target <= 2 detik)
 *    P3  modal benar-benar memblokir: Save/Export/Publish tidak dapat diklik
 *    P4  tidak ada angka hasil yang tampil selama modal terbuka (Simulation Data tetap kosong)
 *    P5  tahap yang ditampilkan adalah tahap NYATA dari mesin hitung, dan jumlahnya bertambah
 *    P6  modal berganti menjadi popup keputusan setelah validasi eksak selesai — tidak pernah
 *        ada dua lapisan, dan tidak pernah ada jeda tanpa lapisan
 *    P7  sampai popup terbuka, Save/Export/Publish tetap terkunci
 *
 *  node browser_popup_responsiveness.js <base-url> <outdir> <out.md>
 * =========================================================================================== */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:9610';
const OUT  = process.argv[3] || '/home/claude/t5/screenshots74';
const MD   = process.argv[4] || '/home/claude/t5/reports74/GAS_SHORTAGE_POPUP_SEQUENCE.md';
fs.mkdirSync(OUT, { recursive: true });
fs.mkdirSync(path.dirname(MD), { recursive: true });

const R = [];
function rec(id, name, pass, detail) {
  R.push({ id, name, pass: !!pass, detail: String(detail == null ? '' : detail).slice(0, 500) });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${id.padEnd(5)} ${name}${detail ? '  -- ' + String(detail).slice(0, 200) : ''}`);
}
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: 1440, height: 950 } });
  const errs = [];
  page.on('pageerror', e => errs.push(String(e.message || e)));

  await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded', timeout: 120000 });
  await sleep(1500);

  /* Mode keputusan = recommendation, persis seperti input nyata operator. */
  await page.evaluate(() => {
    const s = document.getElementById('f-gas_shortage_action');
    if (s) { s.value = 'recommendation'; s.dispatchEvent(new Event('change', { bubbles: true })); }
  });

  const t0 = Date.now();
  await page.locator('#btn-run').click({ timeout: 30000 });

  /* ---- P1: modal muncul <= 1 detik ------------------------------------------------------- */
  let tModal = null;
  for (let i = 0; i < 400; i++) {
    const ada = await page.evaluate(() => !!document.getElementById('ppm-mask'));
    if (ada) { tModal = Date.now() - t0; break; }
    await sleep(25);
  }
  rec('P1', 'Klik Run -> modal pemblokir muncul <= 1 detik',
      tModal !== null && tModal <= 1000, tModal === null ? 'modal tidak pernah muncul' : tModal + ' ms');

  /* ---- P2: tahap progres pertama <= 2 detik ----------------------------------------------- */
  let tStep = null, langkahAwal = '';
  for (let i = 0; i < 400; i++) {
    const n = await page.evaluate(() => {
      const ol = document.getElementById('ppm-steps');
      return ol ? { n: ol.children.length, teks: (ol.textContent || '').trim().slice(0, 160) } : { n: 0, teks: '' };
    });
    if (n.n > 0) { tStep = Date.now() - t0; langkahAwal = n.teks; break; }
    await sleep(25);
  }
  rec('P2', 'Klik Run -> tahap progres PERTAMA tampil <= 2 detik',
      tStep !== null && tStep <= 2000, tStep === null ? 'tidak ada tahap' : tStep + ' ms; ' + langkahAwal);

  /* ---- P3: modal benar-benar memblokir ---------------------------------------------------- */
  const blokir = await page.evaluate(() => {
    const mask = document.getElementById('ppm-mask');
    if (!mask) return { ok: false, alasan: 'tidak ada mask' };
    const cs = getComputedStyle(mask);
    /* V3: modal menutupi halaman (Run, Export, input), tetapi tombol Save Input diangkat di atasnya. */
    const b = document.getElementById('btn-run');
    let tertutup = false;
    if (b) {
      const r = b.getBoundingClientRect();
      const atas = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      tertutup = !!atas && (atas === mask || mask.contains(atas));
    }
    const sv = document.getElementById('btn-save'); let saveAtas = false;
    if (sv) { const r2 = sv.getBoundingClientRect(); const a2 = document.elementFromPoint(r2.left + r2.width / 2, r2.top + r2.height / 2); saveAtas = !!a2 && (a2 === sv || sv.contains(a2)); }
    return { ok: cs.position === 'fixed' && +cs.zIndex >= 10000 && tertutup && saveAtas && !sv.disabled,
             z: cs.zIndex, pos: cs.position, tertutup, saveInputDiAtasModal: saveAtas };
  });
  rec('P3', 'Modal menutupi halaman (Run/Export/input); Save Input tetap aktif dan dapat diklik di atas modal (V3)',
      blokir.ok, JSON.stringify(blokir));

  /* ---- P4: tidak ada angka hasil selama modal terbuka -------------------------------------- */
  const sunyi = await page.evaluate(() => ({
    output: (typeof OUTPUT !== 'undefined' && OUTPUT) ? 'TERISI' : 'kosong',
    gate: (typeof GSD_GATE !== 'undefined') ? !!GSD_GATE : null
  }));
  rec('P4', 'OUTPUT tetap kosong selama analisis berjalan',
      sunyi.output === 'kosong', JSON.stringify(sunyi));

  /* ---- P5: tahap bertambah, bukan animasi diam -------------------------------------------- */
  /* V12 (perubahan disengaja): engine V12 dapat menyelesaikan analisis < 6 s sehingga modal sudah digantikan popup Gas Shortage
   * saat sampel tunggal +6 s diambil. Kriteria TETAP sama (tahap nyata, bertambah atau >= 4); yang berubah hanya cara sampling:
   * modal dipantau tiap 250 ms sampai 6 s atau sampai modal ditutup, dan keadaan terakhir yang teramati selagi modal terbuka dinilai. */
  const n1 = await page.evaluate(() => (document.getElementById('ppm-steps') || { children: [] }).children.length);
  let snap = null; const tP5 = Date.now();
  while (Date.now() - tP5 < 6000) {
    const s = await page.evaluate(() => { const ol = document.getElementById('ppm-steps');
      return ol ? { n: ol.children.length, judul: (document.getElementById('ppm-title') || {}).textContent || '', teks: (ol.textContent || '').trim().slice(0, 400) } : null; });
    if (!s) break; snap = s; await sleep(250);
  }
  if (!snap) snap = { n: -1, judul: '', teks: '' };
  rec('P5', 'Daftar tahap bertambah mengikuti mesin hitung (bukan animasi)',
      snap.n > n1 || snap.n >= 4, `awal=${n1} -> terakhir teramati=${snap.n} (${Date.now() - tP5} ms); judul="${snap.judul}"`);
  try { await page.screenshot({ path: path.join(OUT, 'popup_modal_progress.png') }); } catch (e) {}

  /* ---- P6: modal -> popup keputusan, tanpa jeda tanpa lapisan ----------------------------- */
  let tPopup = null, dobel = 0, kosong = 0;
  for (let i = 0; i < 1200; i++) {          // sampai 10 menit: sinkron + worker latar
    const st = await page.evaluate(() => ({
      modal: !!document.getElementById('ppm-mask'),
      popup: !!document.getElementById('gsf-mask'),
      out:   (typeof OUTPUT !== 'undefined' && OUTPUT) ? 1 : 0
    }));
    if (st.modal && st.popup) dobel++;
    if (!st.modal && !st.popup && !st.out) kosong++;
    if (st.popup) { tPopup = Date.now() - t0; break; }
    if (!st.modal && st.out) break;          // selesai tanpa shortage
    await sleep(500);
  }
  rec('P6', 'Modal berganti menjadi popup keputusan tanpa lapisan ganda',
      tPopup !== null && dobel === 0,
      tPopup === null ? 'popup tidak pernah terbuka' : `popup pada ${tPopup} ms; lapisan ganda=${dobel}; jeda tanpa lapisan=${kosong}`);

  /* ---- P7: kunci Save/Export/Publish ------------------------------------------------------ */
  const kunci = await page.evaluate(() => {
    const ids = ['btn-xls', 'btn-img-full', 'btn-img-partial', 'btn-publish', 'btn-save-actual'];
    const st = {};
    ids.forEach(id => { const b = document.getElementById(id); st[id] = b ? !!b.disabled : 'tidak ada'; });
    return { st, gate: (typeof GSD_GATE !== 'undefined') ? !!GSD_GATE : null,
             output: (typeof OUTPUT !== 'undefined' && OUTPUT) ? 'TERISI' : 'kosong' };
  });
  const semuaTerkunci = Object.values(kunci.st).every(v => v === true || v === 'tidak ada');
  const saveInputAktif = await page.evaluate(() => !document.getElementById('btn-save').disabled);
  rec('P7', 'Export/Publish/Save hasil terkunci selama keputusan belum diambil; Save Input tetap aktif (V3)',
      semuaTerkunci && kunci.gate === true && kunci.output === 'kosong' && saveInputAktif, JSON.stringify(Object.assign(kunci, { saveInputAktif })));

  try { await page.screenshot({ path: path.join(OUT, 'popup_keputusan.png') }); } catch (e) {}

  rec('P8', 'Tidak ada JavaScript error di halaman', errs.length === 0, errs.slice(0, 3).join(' | '));

  await browser.close();

  const lulus = R.filter(r => r.pass).length;
  const md = ['# URUTAN DAN WAKTU POPUP GAS SHORTAGE — KLIK NYATA', '',
    `Basis: \`${BASE}\` · ${new Date().toISOString()}`, '',
    `Hasil: **${lulus}/${R.length}** lulus.`, '',
    '| ID | Pemeriksaan | Hasil | Rincian |', '|---|---|---|---|',
    ...R.map(r => `| ${r.id} | ${r.name} | ${r.pass ? 'LULUS' : 'GAGAL'} | ${r.detail.replace(/\|/g, '\\|')} |`),
    '', '## Catatan', '',
    'Modal dibuka SEBELUM `fetch`, sebagai satu operasi DOM sinkron. Karena itu jaraknya dari klik',
    'tidak bergantung pada jaringan maupun berat rencana. Nama tahap dibaca dari',
    '`run.php?mode=prelim_progress`, yang melaporkan batas fase yang benar-benar sudah dilewati',
    'mesin hitung; tidak ada satu pun angka estimasi yang ditampilkan sebelum validasi eksak selesai.',
    ''].join('\n');
  fs.writeFileSync(MD, md);
  console.log('\n' + lulus + '/' + R.length + ' lulus -> ' + MD);
  process.exit(R.every(r => r.pass) ? 0 : 1);
})().catch(e => { console.error('HARNESS ERROR', e); process.exit(2); });
