# DISTILLATE_FULL_FIRST_AUDIT

Sumber: output job Fastest nyata pada payload golden + keputusan `use_distillate` (otomatis oleh `harness/ab_golden_multiuser/tools/gen_audits.py`).

- Unit Priority Distillate (GTG only) dari input: `[["g6", "g4", "g3"], ["g1", "g2", "g5"], ["g9", "g8"], ["g7"], ["g10"]]`
- Mix per unit: `{"G6": 100}`
- Rekonsiliasi: required 93348.6 l, scheduled 93641.1 l, consumed 93641.1 l, summary 93641.4 l, selisih 0.3 l → **PASS**
- Langkah penutup diskret: mode `SINGLE`, status `RESOLVED`, priority_tiers `[]`, langkah `[{"row": 1, "unit": "G6", "level_dari": 0, "level_ke": 0.3, "energi_bbtud": 0.044898, "liter": 1246.2}]`
- Jumlah unit yang memakai Distillate: **1** (G6)

## Aturan yang diverifikasi

1. Full-first: unit prioritas 1 diisi sampai slot/headroom habis sebelum unit berikutnya (greedy `__fullFirstHold`, worker02.php).
2. Tidak ada Distillate pada slot unit yang sedang lead-in/minimum-load (≤ min load) bila unit prioritas lebih tinggi masih eligible.
3. Langkah penutup diskret mencari per tier prioritas (L = 1..n) — tier lebih rendah hanya bila tier lebih tinggi tidak dapat menutup residual.

## Per row (hanya row dengan Distillate)

| Row | Jam | G6 MW | Dist_G6 l | Min-load? |
|---|---|---|---|---|
| 1 | 00:30 | 20 | 1246.2 | tidak |
| 29 | 14:30 | 29.29 | 2751.9 | tidak |
| 30 | 15:00 | 29.29 | 5503.8 | tidak |
| 31 | 15:30 | 31 | 5712.3 | tidak |
| 32 | 16:00 | 27 | 5205.2 | tidak |
| 33 | 16:30 | 27 | 5205.2 | tidak |
| 34 | 17:00 | 27 | 5205.2 | tidak |
| 35 | 17:30 | 31 | 5712.3 | tidak |
| 36 | 18:00 | 31 | 5712.3 | tidak |
| 37 | 18:30 | 20 | 4153.9 | tidak |
| 38 | 19:00 | 20 | 4153.9 | tidak |
| 39 | 19:30 | 20 | 4153.9 | tidak |
| 40 | 20:00 | 20 | 4153.9 | tidak |
| 41 | 20:30 | 20 | 4153.9 | tidak |
| 42 | 21:00 | 20 | 4153.9 | tidak |
| 43 | 21:30 | 24 | 4780.2 | tidak |
| 44 | 22:00 | 26 | 5067.8 | tidak |
| 45 | 22:30 | 20 | 4153.9 | tidak |
| 46 | 23:00 | 20 | 4153.9 | tidak |
| 47 | 23:30 | 20 | 4153.9 | tidak |
| 48 | 00:00 | 20 | 4153.9 | tidak |

**Distillate pada unit ≤ 5 MW (lead-in/minimum-load): 0 slot.**
