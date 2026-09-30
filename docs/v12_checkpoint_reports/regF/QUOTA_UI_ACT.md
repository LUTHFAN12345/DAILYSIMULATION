# UI: perubahan kuota lewat browser — basis Actual 11 jam

JS error: 0

| kasus | perubahan | hasil | valid pertama | FINAL / keputusan | angka rekomendasi | jalur | kandidat (valid) / pruned | core run / simulasi | CP | constraint | rilis |
|---|---|---|---|---|---|---|---|---|---|---|---|
| BASIS | - | FINAL | 28,24 s | 31,24 s | - | INCREMENTAL | 13 (11) / - | 22 / 83 | 64.6214 | hard PASS; Export 48/48; gas 64.5706/64.6; PGN 29.9834/30; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:1 | pgn_pipe 30 → 31 | FINAL | 14,36 s | 15,50 s | - | INCREMENTAL | 23 (22) / - | 41 / 34 | 63.8609 | hard PASS; Export 48/48; gas 65.5719/65.6; PGN 30.9846/31; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:-1 | pgn_pipe 30 → 29 | GAS_SHORTAGE_POPUP | - s | 11,25 s | 24,47 s | EXACT (V9_JANGKAR_BELUM_ADA) | 9 (0) / - | 24 / 12 | 64.7333 | hard FAIL; Export 48/48; gas 64.353/63.6; PGN 29.7766/29; residual 0.7385; headroom PASS_WITH_REASON | tidak |
| pep:1 | pep 30 → 31 | KEPUTUSAN_KUOTA_OPERATOR | - s | 0,78 s | - | DELTA_CERTIFICATE — PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR | null (null) / - | 1 / 1 | 66.0665 | hard FAIL; Export CHECK; gas 66.1534/65.68; PGN 30.9812/30; residual 0.5222; headroom REVIEW_MINOR | tidak |
| pep:-1 | pep 30 → 29 | KEPUTUSAN_KUOTA_OPERATOR | - s | 1,06 s | - | DELTA_CERTIFICATE — PGN_PIPE_FLOOR_VS_TOTAL_GAS_CEILING | null (null) / - | 1 / 1 | 65.6235 | hard FAIL; Export CHECK; gas 66.1171/63.52; PGN 32.1149/30; residual 2.6477; headroom REVIEW_MINOR | tidak |
| lng:1 | lng 0 → 1 | FINAL | 13,53 s | 14,67 s | - | INCREMENTAL | 23 (22) / - | 41 / 32 | 64.1733 | hard PASS; Export 48/48; gas 65.5719/65.6; PGN 29.9846/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep_kp72:1 | pep_kp72 2.2 → 3.2 | KEPUTUSAN_KUOTA_OPERATOR | - s | 1,07 s | - | DELTA_CERTIFICATE — PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR | null (null) / - | 1 / 1 | 65.8037 | hard FAIL; Export CHECK; gas 66.6587/65.6; PGN 31.5299/30; residual 1.5677; headroom REVIEW_MINOR | tidak |
| akasia:-1 | akasia 0 → -1 | KEPUTUSAN_KUOTA_OPERATOR | - s | 1,06 s | - | DELTA_CERTIFICATE — PGN_PIPE_FLOOR_VS_TOTAL_GAS_CEILING | null (null) / - | 1 / 1 | 65.3788 | hard FAIL; Export CHECK; gas 66.1171/63.52; PGN 32.1149/30; residual 2.6477; headroom REVIEW_MINOR | tidak |
| pgn_pipe:-1+pep:2 | pgn_pipe 30 → 29, pep 30 → 32 | KEPUTUSAN_KUOTA_OPERATOR | - s | 0,64 s | - | DELTA_CERTIFICATE — PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR | null (null) / - | 1 / 1 | 66.2908 | hard FAIL; Export CHECK; gas 66.1534/65.76; PGN 30.3962/29; residual 0.4422; headroom REVIEW_MINOR | tidak |
