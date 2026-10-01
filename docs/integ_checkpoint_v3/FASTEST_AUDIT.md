# Fastest - Default V3 — FASTEST_RELEASE_READY (laporan waktu)

Basis WB09_3 (Daily_Plan_09_Jul_26_Baru(3)), satu kuota diubah; UI nyata (Chromium), cold dari direktori job kosong, 4 inti, 3 pembantu. Waktu dari klik Run.

| input | hasil | kandidat valid pertama diklaim (s) | FASTEST_RELEASE_READY (s) | hasil tampil (s) | rilis - siap (s) | diperiksa / valid | CP | Heat Rate | hard | merit C1-C4 | C4 tanpa alasan | STG | LLF | provenance |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PGN31 | FASTEST VALID PLAN | 1.67 | 7.72 | 8.12 | 0.13 | 9 / 6 | 63.5711 | 8148.58 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS |
| PGN29 | FASTEST VALID PLAN | 1.38 | 5.14 | 5.60 | 0.15 | 11 / 7 | 63.8518 | 8237.75 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS |
| PGN32 | FASTEST VALID PLAN | 1.86 | 6.63 | 6.82 | -0.11 | 9 / 6 | 63.4469 | 8109.13 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS |
| PEP0 | GAS_SHORTAGE_DECISION (tidak ada kandidat fully valid) | - | - | 6.51 | - | - / - | - | - | None | - | None | None | - | - |
| KP72_0 | FASTEST VALID PLAN | 1.81 | 6.04 | 6.22 | -0.09 | 9 / 6 | 63.5889 | 8128.86 | PASS | PASS | 0 | 144/144 langgar 0 | PASS_WITH_OUTCOMES | PASS |

Mekanisme: job Fastest ditandai (run.php?mode=run&fast=1). Proses yang menawarkan kandidat valid PERTAMA ke kolam mengklaimnya (berkas klaim eksklusif) dan langsung membawa bukti merit bersama kandidat itu: review Unit Priority (+ polish), audit penerimaan (hard constraints kanonik, provenance, merit C1-C4, LOW_LOAD_FRAGMENTATION) dan gerbang fully valid (STG per row = calc_stg, Change Over bila aktif). Pemilik job exact berhenti saat klaim; pembantu mengerjakan kandidat counterfactual review secara paralel; job dibatalkan begitu v12_fast_ready.json ditulis. UI hanya membaca berkas siap-rilis (polling 250 ms) — tidak ada finalisasi kedua, tidak menunggu FINAL exact atau kandidat berikutnya, hasil tidak diganti kandidat belakangan.
Kandidat diperiksa/valid mencakup kandidat counterfactual review merit (bukti C2/C3) milik kandidat yang dirilis.
Sebelumnya: PGN 31 29,3 s (laporan pengguna); V2 10,1 s. V3: 7,9 s.
PEP 0: kekurangan gas nyata — tidak ada kandidat fully valid; keputusan Gas Shortage tampil 6,5 s tanpa hasil invalid.
Tidak berubah: dispatch/CP/Heat Rate kandidat (identik V2), merit C1-C4, bukti STG, Change Over, Maximum Review (WB09_3: CP 63,4469, HR 8109,13, 28 diperiksa / 13 valid).
