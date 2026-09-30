# UI: perubahan kuota lewat browser — basis PGN 30 tanpa Actual

JS error: 0

| kasus | perubahan | hasil | valid pertama | FINAL / keputusan | angka rekomendasi | jalur | kandidat (valid) / pruned | core run / simulasi | CP | constraint | rilis |
|---|---|---|---|---|---|---|---|---|---|---|---|
| BASIS | - | FINAL | 4,32 s | 14,67 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 71 | 64.6644 | hard PASS; Export 48/48; gas 64.5918/64.6; PGN 29.9928/30; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:1 | pgn_pipe 30 → 31 | FINAL | 5,90 s | 12,14 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (1) / - | 9 / 42 | 64.5424 | hard PASS; Export 48/48; gas 65.578/65.6; PGN 30.979/31; residual 0; headroom REVIEW_MINOR | YA |
| pgn_pipe:-1 | pgn_pipe 30 → 29 | GAS_SHORTAGE_POPUP | - s | 5,54 s | 6,56 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 12 | 64.2315 | hard FAIL; Export 48/48; gas 64.2985/63.6; PGN 29/29; residual 0.7385; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:2 | pgn_pipe 30 → 32 | FINAL | 0,74 s | 14,33 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (4) / - | 3 / 55 | 64.4029 | hard PASS; Export 48/48; gas 66.5686/66.6; PGN 31.9695/32; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:-2 | pgn_pipe 30 → 28 | GAS_SHORTAGE_POPUP | - s | 5,30 s | 6,33 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 63.5829 | hard FAIL; Export 48/48; gas 64.2985/62.6; PGN 28/28; residual 1.7385; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:4 | pgn_pipe 30 → 34 | FINAL | 1,25 s | 10,36 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (6) / - | 4 / 57 | 64.1256 | hard PASS; Export 48/48; gas 68.5915/68.6; PGN 33.9925/34; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:-4 | pgn_pipe 30 → 26 | GAS_SHORTAGE_POPUP | - s | 6,06 s | 7,08 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 62.2857 | hard FAIL; Export 48/48; gas 64.2985/60.6; PGN 26/26; residual 3.7385; headroom PASS_WITH_REASON | tidak |
| pep:1 | pep 30 → 31 | FINAL | 5,36 s | 13,64 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 64 | 64.4253 | hard PASS; Export 48/48; gas 65.6648/65.68; PGN 29.9858/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep:-1 | pep 30 → 29 | GAS_SHORTAGE_POPUP | - s | 5,86 s | 6,88 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 11 | 64.2762 | hard FAIL; Export 48/48; gas 64.2985/63.52; PGN 30/30; residual 0.8185; headroom PASS_WITH_REASON | tidak |
| pep:2 | pep 30 → 32 | FINAL | 1,04 s | 11,23 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (4) / - | 2 / 80 | 64.1989 | hard PASS; Export 48/48; gas 66.7218/66.76; PGN 29.9628/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep:-2 | pep 30 → 28 | GAS_SHORTAGE_POPUP | - s | 5,98 s | 7,00 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 12 | 63.6723 | hard FAIL; Export 48/48; gas 64.2985/62.44; PGN 30/30; residual 1.8985; headroom PASS_WITH_REASON | tidak |
| lng:1 | lng 0 → 1 | FINAL | 5,62 s | 12,41 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (1) / - | 9 / 68 | 64.8581 | hard PASS; Export 48/48; gas 65.578/65.6; PGN 29.979/30; residual 0; headroom REVIEW_MINOR | YA |
| lng:-1 | lng 0 → -1 | GAS_SHORTAGE_POPUP | - s | 5,51 s | 6,53 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 11 | 63.9109 | hard FAIL; Export 48/48; gas 64.2985/63.6; PGN 30/30; residual 0.7385; headroom PASS_WITH_REASON | tidak |
| pep_kp72:1 | pep_kp72 2.2 → 3.2 | FINAL | 3,77 s | 21,23 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 18 / 108 | 64.631 | hard PASS; Export 48/48; gas 65.5918/65.6; PGN 29.9928/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep_kp72:-1 | pep_kp72 2.2 → 1.2 | FINAL | 2,23 s | 12,54 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 95 | 64.786 | hard PASS; Export 48/48; gas 63.5919/63.6; PGN 29.9942/30; residual 0; headroom PASS_WITH_REASON | YA |
| akasia:1 | akasia 0 → 1 | FINAL | 6,38 s | 13,03 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 66 | 64.6661 | hard PASS; Export 48/48; gas 65.6648/65.68; PGN 29.9858/30; residual 0; headroom PASS_WITH_REASON | YA |
| akasia:-1 | akasia 0 → -1 | GAS_SHORTAGE_POPUP | - s | 5,13 s | 6,15 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 12 | 64.0315 | hard FAIL; Export 48/48; gas 64.2985/63.52; PGN 30/30; residual 0.8185; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:-1+pep:2 | pgn_pipe 30 → 29, pep 30 → 32 | FINAL | 6,54 s | 16,82 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 55 | 64.3188 | hard PASS; Export 48/48; gas 65.7433/65.76; PGN 28.9843/29; residual 0; headroom PASS_WITH_REASON | YA |
