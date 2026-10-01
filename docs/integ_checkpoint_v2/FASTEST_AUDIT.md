# Fastest - Default — kandidat fully valid pertama (laporan waktu)

Basis: rencana nyata WB09_3 (Daily_Plan_09_Jul_26_Baru(3): PGN 32, PEP 34, KP72 2,2), variasi satu kuota. UI nyata (Chromium), cold dari direktori job kosong, server multi-backend (meniru Apache), 4 inti, 3 pembantu.

| input | hasil | kandidat valid pertama tersedia (s) | hasil tampil (s) | finalisasi merit (s) | diperiksa | valid | CP | Heat Rate | hard | merit audit | C4 tanpa alasan | STG = calc_stg | LLF | provenance | popup progress |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PGN29 | FASTEST VALID PLAN | 1.7 | 8.23 | 6.196 | 4 | 2 | 63.8518 | 8237.75 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS | tidak |
| PGN30 | FASTEST VALID PLAN | 1.61 | 9.80 | 7.892 | 4 | 2 | 63.7129 | 8192.75 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS | tidak |
| PGN32 | FASTEST VALID PLAN | 2.08 | 10.68 | 8.239 | 4 | 2 | 63.4469 | 8109.13 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS | tidak |
| PEP0 | GAS_SHORTAGE_DECISION (tidak ada kandidat fully valid) | - | 7.52 | - | - | - | - | - | None | - | None | None | - | - | tidak |
| KP72_0 | FASTEST VALID PLAN | 1.93 | 8.98 | 6.761 | 4 | 2 | 63.5889 | 8128.86 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS | tidak |

**Perilaku:** begitu kolam job memuat kandidat valid pertama (polling 300 ms), Fastest menghentikan pencarian lanjutan (job exact + pembantu dibatalkan), menjalankan langkah merit yang sama dengan pipeline atas kandidat itu (review Unit Priority V8 + polish lanjutan, audit penerimaan: hard constraints kanonik, provenance, audit merit C1-C4, LOW_LOAD_FRAGMENTATION) lalu gerbang fully valid (bukti STG per row = calc_stg; Change Over executed + overlap >= 3 row bila aktif). Lulus -> langsung dipublish sebagai FASTEST VALID PLAN (Global optimum proven: NO). Gagal -> kembali ke pencarian normal tanpa modal.
"Kandidat valid pertama tersedia" = kandidat lolos hard constraints di kolam; kandidat itu baru FULLY valid setelah finalisasi merit (kolom finalisasi). Selisih antara kandidat tersedia dan mulai finalisasi <= 0,3 s (satu interval polling); hasil tampil < 0,4 s setelah finalisasi selesai.
Sebelumnya Fastest menunggu FINAL exact (contoh pengguna: 77,5 s, 30 diperiksa / 15 valid). Kini 4 diperiksa / 2 valid, 7,9-10,7 s; Maximum Review tetap mengevaluasi seluruh kandidat (WB09_3: 28 diperiksa / 14 valid, CP 63,4469, HR 8109,13, identik sebelum perubahan).
PEP 0: kekurangan gas nyata — tidak ada kandidat fully valid; Fastest menampilkan keputusan Gas Shortage dalam 7,5 s tanpa mempublish hasil invalid.
