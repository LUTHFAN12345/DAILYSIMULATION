# Path independence V11 (clean-extract ZIP final)

Setiap sel: md5 dispatch 48 row (kolom unit + Export, 3 desimal) / Cost Production. SAMA = identik dengan jalur direct.

| state | direct (CLI, cache dingin, tanpa pembantu) | rantai A (CLI) | rantai B (CLI, urutan terbalik) | cache hangat (ulang di akar rantai B) | rantai HTTP (pembantu aktif) | Target Selesai dulu (HTTP, cold) | hasil |
|---|---|---|---|---|---|---|---|
| BASE_PGN30 | 93ada6bad9 / 64.6644 | 93ada6bad9 / 64.6644 | 93ada6bad9 / 64.6644 | - | 93ada6bad9 / 64.6644 | - | IDENTIK (4 jalur) |
| WB09 | 550095db8c / 64.3644 | 550095db8c / 64.3644 | 550095db8c / 64.3644 | 550095db8c / 64.3644 | 550095db8c / 64.3644 | 550095db8c / 64.3644 | IDENTIK (6 jalur) |
| WB09_KP72 | 17ec197523 / 64.1989 | 17ec197523 / 64.1989 | 17ec197523 / 64.1989 | - | 17ec197523 / 64.1989 | - | IDENTIK (4 jalur) |
| BASE_ACT10 | 73f88465fc / 64.6214 | 73f88465fc / 64.6214 | 73f88465fc / 64.6214 | - | 73f88465fc / 64.6214 | - | IDENTIK (4 jalur) |
| ACT_PGN_UP | bf94dc3fe3 / 64.6801 | bf94dc3fe3 / 64.6801 | bf94dc3fe3 / 64.6801 | bf94dc3fe3 / 64.6801 | bf94dc3fe3 / 64.6801 | - | IDENTIK (5 jalur) |
| ACT_PGN_DOWN | e0e437e57f / 64.5676 | e0e437e57f / 64.5676 | e0e437e57f / 64.5676 | - | e0e437e57f / 64.5676 | - | IDENTIK (4 jalur) |
| ACT_FFJ_UP | d80b4fe08a / 64.6309 | d80b4fe08a / 64.6309 | d80b4fe08a / 64.6309 | - | d80b4fe08a / 64.6309 | d80b4fe08a / 64.6309 | IDENTIK (5 jalur) |
| ACT_FFM_DOWN | 688200238e / 64.6214 | 688200238e / 64.6214 | 688200238e / 64.6214 | - | 688200238e / 64.6214 | - | IDENTIK (4 jalur) |
| KP72_DOWN | 73f88465fc / 64.6214 | 73f88465fc / 64.6214 | 73f88465fc / 64.6214 | - | 73f88465fc / 64.6214 | - | IDENTIK (4 jalur) |
| ACT_PGN_2SLOT_C | 99496b3a7d / 64.6987 | 99496b3a7d / 64.6987 | 99496b3a7d / 64.6987 | - | 99496b3a7d / 64.6987 | - | IDENTIK (4 jalur) |
| QA_pgn_pipe_1 | 2d8ea565fc / 63.8609 | 2d8ea565fc / 63.8609 | 2d8ea565fc / 63.8609 | - | 2d8ea565fc / 63.8609 | - | IDENTIK (4 jalur) |
| QA_pep_-1 | f038709283 / 65.6235 | f038709283 / 65.6235 | f038709283 / 65.6235 | f038709283 / 65.6235 | f038709283 / 65.6235 | f038709283 / 65.6235 | IDENTIK (6 jalur) |
| WB09_3 | 2b5270f108 / 63.4469 | 2b5270f108 / 63.4469 | 2b5270f108 / 63.4469 | 2b5270f108 / 63.4469 | 2b5270f108 / 63.4469 | 2b5270f108 / 63.4469 | IDENTIK (6 jalur) |

**13/13 state identik di seluruh jalur yang dijalankan** (47 perbandingan terhadap direct).
