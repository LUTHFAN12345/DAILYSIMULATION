# UI: perubahan kuota lewat browser — basis PGN 30 tanpa Actual

JS error: 0

| kasus | perubahan | hasil | valid pertama | FINAL / keputusan | angka rekomendasi | jalur | kandidat (valid) / pruned | core run / simulasi | CP | constraint | rilis |
|---|---|---|---|---|---|---|---|---|---|---|---|
| BASIS | - | FINAL | 3,29 s | 18,37 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 79 | 64.6644 | hard PASS; Export 48/48; gas 64.5918/64.6; PGN 29.9928/30; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:1 | pgn_pipe 30 → 31 | FINAL | 5,88 s | 11,98 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (1) / - | 9 / 77 | 64.5424 | hard PASS; Export 48/48; gas 65.578/65.6; PGN 30.979/31; residual 0; headroom REVIEW_MINOR | YA |
| pgn_pipe:-1 | pgn_pipe 30 → 29 | GAS_SHORTAGE_POPUP | - s | 6,21 s | 7,23 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 12 | 64.2315 | hard FAIL; Export 48/48; gas 64.2985/63.6; PGN 29/29; residual 0.7385; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:2 | pgn_pipe 30 → 32 | FINAL | 0,76 s | 11,70 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (4) / - | 3 / 51 | 64.4029 | hard PASS; Export 48/48; gas 66.5686/66.6; PGN 31.9695/32; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:-2 | pgn_pipe 30 → 28 | GAS_SHORTAGE_POPUP | - s | 5,14 s | 6,16 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 11 | 63.5829 | hard FAIL; Export 48/48; gas 64.2985/62.6; PGN 28/28; residual 1.7385; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:4 | pgn_pipe 30 → 34 | FINAL | 1,35 s | 11,46 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (6) / - | 4 / 57 | 64.1256 | hard PASS; Export 48/48; gas 68.5915/68.6; PGN 33.9925/34; residual 0; headroom PASS_WITH_REASON | YA |
| pgn_pipe:-4 | pgn_pipe 30 → 26 | GAS_SHORTAGE_POPUP | - s | 5,37 s | 6,38 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 62.2857 | hard FAIL; Export 48/48; gas 64.2985/60.6; PGN 26/26; residual 3.7385; headroom PASS_WITH_REASON | tidak |
| pep:1 | pep 30 → 31 | FINAL | 5,74 s | 13,05 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 57 | 64.4253 | hard PASS; Export 48/48; gas 65.6648/65.68; PGN 29.9858/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep:-1 | pep 30 → 29 | GAS_SHORTAGE_POPUP | - s | 5,82 s | 6,83 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 64.2762 | hard FAIL; Export 48/48; gas 64.2985/63.52; PGN 30/30; residual 0.8185; headroom PASS_WITH_REASON | tidak |
| pep:2 | pep 30 → 32 | FINAL | 1,18 s | 12,37 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 7 (4) / - | 2 / 73 | 64.1989 | hard PASS; Export 48/48; gas 66.7218/66.76; PGN 29.9628/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep:-2 | pep 30 → 28 | GAS_SHORTAGE_POPUP | - s | 5,90 s | 6,92 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 63.6723 | hard FAIL; Export 48/48; gas 64.2985/62.44; PGN 30/30; residual 1.8985; headroom PASS_WITH_REASON | tidak |
| lng:1 | lng 0 → 1 | FINAL | 6,58 s | 12,65 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (1) / - | 9 / 41 | 64.8581 | hard PASS; Export 48/48; gas 65.578/65.6; PGN 29.979/30; residual 0; headroom REVIEW_MINOR | YA |
| lng:-1 | lng 0 → -1 | GAS_SHORTAGE_POPUP | - s | 5,57 s | 6,59 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 12 | 63.9109 | hard FAIL; Export 48/48; gas 64.2985/63.6; PGN 30/30; residual 0.7385; headroom PASS_WITH_REASON | tidak |
| pep_kp72:1 | pep_kp72 2.2 → 3.2 | FINAL | 3,60 s | 20,24 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 18 / 123 | 64.631 | hard PASS; Export 48/48; gas 65.5918/65.6; PGN 29.9928/30; residual 0; headroom PASS_WITH_REASON | YA |
| pep_kp72:-1 | pep_kp72 2.2 → 1.2 | FINAL | 3,54 s | 16,07 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 79 | 64.786 | hard PASS; Export 48/48; gas 63.5919/63.6; PGN 29.9942/30; residual 0; headroom PASS_WITH_REASON | YA |
| akasia:1 | akasia 0 → 1 | FINAL | 6,80 s | 14,55 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 66 | 64.6661 | hard PASS; Export 48/48; gas 65.6648/65.68; PGN 29.9858/30; residual 0; headroom PASS_WITH_REASON | YA |
| akasia:-1 | akasia 0 → -1 | GAS_SHORTAGE_POPUP | - s | 5,71 s | 6,72 s | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (0) / - | 24 / 13 | 64.0315 | hard FAIL; Export 48/48; gas 64.2985/63.52; PGN 30/30; residual 0.8185; headroom PASS_WITH_REASON | tidak |
| pgn_pipe:-1+pep:2 | pgn_pipe 30 → 29, pep 30 → 32 | FINAL | 6,82 s | 18,31 s | - | EXACT (V9_STATE_TANPA_ACTUAL_EXACT_KANONIK) | 9 (2) / - | 9 / 53 | 64.3188 | hard PASS; Export 48/48; gas 65.7433/65.76; PGN 28.9843/29; residual 0; headroom PASS_WITH_REASON | YA |
