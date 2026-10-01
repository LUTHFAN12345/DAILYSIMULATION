# INTEGRASI MERIT + FASTEST — CHECKPOINT V2, BELUM FINAL

- Branch: `claude/gifted-ritchie-q0hfdp`; source commit: `6a5444a` (Fastest first fully valid); merit commit `a905f10`; harness `bc4cdf1`.
- Tidak berubah dari checkpoint V1: dispatch, merit audit C1-C4, bukti STG, comparator CP/Heat Rate, Change Over baseline (`worker02.php`, `worker_functions.php` byte-identical V12 checkpoint). Perubahan V2 hanya `run.php` (endpoint `fast_finalize` + gerbang fully valid diperketat: hard constraints, C4, bukti STG per row) dan `index.php` (polling Fastest).
- Fastest - Default: publish kandidat fully valid pertama, hentikan pencarian lanjutan; tidak menunggu kandidat berikutnya / FINAL exact / CP minimum global. Maximum Review tidak berubah. Lihat FASTEST_AUDIT.md.
- Test minimum (PGN 29, PGN 30, PGN 32, PEP 0, KP72 0): 4 FASTEST VALID PLAN 7,9-10,7 s (4 diperiksa / 2 valid; hard, provenance, merit, C4 0, STG 144/144, LLF PASS), PEP 0 = keputusan Gas Shortage 7,5 s (tidak ada kandidat fully valid).
- Belum dijalankan: targeted suite pada source ini, full regression, clean-extract (sesuai instruksi). Targeted terakhir (source a905f10): 316 PASS / 3 FAIL (P03, P07, T8 — lihat checkpoint V1).
