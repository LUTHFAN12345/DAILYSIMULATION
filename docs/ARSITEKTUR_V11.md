# Arsitektur V11 — konsolidasi beban, band CP 0,2 % + Heat Rate, counter kanonik, FINAL tidak bergantung jalur

V11 melanjutkan V10 (FINAL = fungsi state S, hard constraint, Unit Priority terpadu, provenance bahan bakar). Perubahan:

## 1. Objective dan comparator

1. Kandidat wajib lolos hard constraints + provenance (kandidat valid).
2. CP minimum dicari di himpunan kandidat valid review (incumbent, kandidat review Unit Priority, polish, sapuan
   konsolidasi).
3. Band = kandidat valid dengan CP ≤ CP_min × (1 + 0,2 %). Di dalam band dipilih berurutan: Heat Rate JBBK+MM2100
   terendah, start lebih sedikit, row unit prioritas rendah lebih sedikit, row LOW_LOAD_FRAGMENTATION lebih sedikit,
   skor Unit Priority, kunci kanonik (sidik jari dispatch). Kandidat > 0,2 % di atas CP_min tidak pernah menang karena
   Heat Rate. Tabel lengkap di `info['V11 Candidate Comparison']`.
4. Release gate (`pp_simulation_acceptance_review`) menerima pemenang band hanya bila comparator V11 melihat CP minimum
   seluruh kandidat ladder dan CP terpilih = CP pemenang band ≤ batas atas band (`economic_review.evidence.v11_band`).

## 2. LOW_LOAD_FRAGMENTATION dan konsolidasi generik

- Deteksi per row (tanpa nama unit keras): ≥ 2 GTG dispatchable dekat Effective Min (≤ min + max(2 MW, 10 % rentang))
  dan ada unit berjalan berprioritas lebih tinggi dengan legal headroom ≥ 1 MW.
- Sapuan konsolidasi (`pp_v11_consolidation_sweep`): kandidat STOP / EARLY_STOP / DECOMMIT / DELAY / SWAP untuk setiap unit
  prioritas rendah yang terlibat, plus varian CONSOLIDATE (beban unit dipindah ke headroom unit berjalan lain, urut laju
  bahan bakar marjinal, dalam Effective Max, tanpa Fixed Load, sehingga Export tidak dinaikkan). Tier 1 (bukti kapasitas
  Export, legalitas/blok STG, heat rate SWAP, bukti kapasitas V10) memangkas tanpa simulasi; Tier 2 = simulasi penuh
  48 row + pendaratan window gas; kandidat valid masuk himpunan band.
- Audit `info['V11 Low Load Fragmentation Audit']`: setiap (row, unit) wajib punya alasan berbasis bukti (status paksa,
  minimum runtime + row stop legal pertama, bukti per row review, counterfactual konsolidasi); alasan incumbent / FINAL
  sebelumnya / minimum load saja ditolak. Status PASS atau PASS_WITH_REASON; UNRESOLVED = gagal.
- Bukti counterfactual 48 row baru (`row_evidence`, basis `V11_POOL_COUNTERFACTUAL_48_ROW`): untuk setiap (row, unit)
  pemenang yang belum beralasan (mis. unit yang running karena pemenang band/sapuan), kandidat valid lain yang mematikan
  unit itu pada row tersebut dicatat beserta selisih CP (atau alasan tie-break band). Hanya audit; dispatch tidak berubah.

## 3. Counter kandidat satu sumber

`info['V11 Candidate Counters']` dan `tl_best.counters` dibaca dari folder yang sama (state × job), ditulis pemilik dan
pembantu:

- full-run / valid dikunci **dispatch fisik 48 row** (MW GTG), sehingga kandidat yang sama dari review, keluarga exact,
  kolam `_q`/`_x`, pemilik atau pembantu dihitung satu kali;
- screened-out (gugur Tier 1) dikunci **commitment fisik kanonik** (interval OFF setiap GTG); screened yang commitmentnya
  juga di-full-run tidak dihitung lagi (`screened_also_full_run_excluded`); alias (node keluarga `off:{u}` yang mencakup
  comparator global, DUPLIKAT_STATE_FISIK) tidak dihitung;
- `checked = full_run + screened_out`, `valid ≤ full_run ≤ checked`, kandidat terbaik/FINAL selalu termasuk valid.

## 4. UI

SUMMARY kuning (`#tl-banner`) tepat lima field: Target waktu | Waktu aktual | Kandidat diperiksa | Kandidat valid | Cost
Production. Status constraints, Global optimum proven, Total Cost, 48 rows/Export/residual/Unit Priority, ruang kandidat,
dan lima audit V11 ada di panel terpisah "Detail audit" (`#tl-audit`). Berlaku untuk Target < 15…< 60, Maximum Review,
VALID PROVISIONAL (dibungkus `#prov-banner`), exact final, no valid result, hasil setelah Gas Shortage, dan rerun bahan
bakar. Invarian UI: CP tersedia + PASS → Valid ≥ 1; tanpa kandidat valid → Valid 0 dan CP kosong; Valid ≤ Diperiksa.

## 5. Path independence — cacat V10 yang ditemukan dan diperbaiki

Alias V5 "stop tambahan tidak mengikat" (`pp_v5_alias_find`) memakai hasil kandidat lain di cache bila stop tambahan
tampak tidak mengikat. Engine ini heuristik: pada jangkar PGN 30, commitment `pipeline` yang dihitung penuh = CP 64,6656
(valid), tetapi alias memakai hasil `off:g3` = 64,6671. Isi cache bergantung urutan evaluasi (pemilik vs pembantu, CLI vs
HTTP), sehingga pustaka jangkar V10 dan FINAL ACT_PGN_UP berbeda antar jalur (direct 64,6622 vs rantai HTTP 64,6801).
V11 mematikan alias secara bawaan; cache kandidat-state berkunci input identik tetap aktif.

## 6. Runtime

- **Prefetch spekulatif keluarga commitment**: keluarga sering berupa rantai (off:[] → off:g5 → off:g2,g5 → …), sehingga
  pembantu menganggur menunggu induk. Pembantu yang menunggu kini mendaratkan dua anak paling mungkin (unit running pada
  induk, lalu urutan prioritas) ke cache berkunci input identik. Registri, himpunan node, urutan, dan pemenang tidak
  berubah; saat node dicapai, pendaratannya cache hit bit-identik. Pengamat Target Selesai tetap memantau abort; kandidat
  spekulatif tidak masuk kolam/penghitung.
- **Indeks `unit_stop_time` per unit** di `pp_is_unit_stopped` (hasil identik).
- Biaya: alias V5 dimatikan menambah core run pada rute cepat (±20–40 %); ini harga path independence.
