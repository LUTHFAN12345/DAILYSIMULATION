# C4 — lintas grup prioritas (unit rendah di atas minimum saat unit grup lebih tinggi punya legal headroom)

| state | status audit merit | temuan | status paksa | akun MM2100 terpakai penuh (kuota / terpakai BBTUD) | tanpa alasan |
|---|---|---|---|---|---|
| ACT_PGN_UP | PASS | 46 | 1 | 45 (2.2 / 2.1988) | 0 |
| BASE_PGN30 | PASS | 47 | 1 | 46 (2.2 / 2.199) | 0 |
| OPS:pgn_pipe=30;mj=busflow_min:22 | PASS | 47 | 1 | 46 (2.2 / 2.199) | 0 |
| OPS:pgn_pipe=30;mj=required_mode:{"g1":{"mode":"continuous"} | PASS | 48 | 1 | 47 (2.2 / 2.199) | 0 |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":20,"m | PASS | 47 | 1 | 46 (2.2 / 2.199) | 0 |
| OPS:pgn_pipe=33;mj=pln_export_priority:{"range":{"min":26,"m | PASS | 46 | 1 | 45 (2.2 / 2.199) | 0 |
| OPS:pgn_pipe=33;mj=spinning_reserve_min:5 | PASS | 40 | 5 | 35 (2.2 / 2.1825) | 0 |
| Q_pep_1 | PASS | 46 | 1 | 45 (2.2 / 2.199) | 0 |
| Q_pep_kp72_1 | PASS | 93 | 1 | 92 (3.2 / 3.1991) | 0 |
| Q_pgn_pipe_4 | PASS | 46 | 1 | 45 (2.2 / 2.199) | 0 |
| WB09_3 | PASS | 46 | 1 | 45 (2.2 / 2.199) | 0 |
| WB09 | PASS | 0 | 0 | 0 (0 / 0) | 0 |

Aturan: temuan sah hanya bila unit rendah berstatus paksa (Required/Cannot Stop/Fixed) atau unit GE/G10 pada akun MM2100 yang kuotanya terpakai penuh (GTG tidak boleh membakar gas MM2100 menurut provenance). Selain itu FAIL dan memblokir FINAL.
