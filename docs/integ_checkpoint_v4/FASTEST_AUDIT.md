# Fastest - Default V4 — render langsung ke Simulation Data (timestamp nyata)

Basis WB09_3 (Daily_Plan_09_Jul_26_Baru(3)); UI nyata (Chromium), 4 inti, 3 pembantu. Semua timestamp epoch ms pada jam yang sama (server PHP + browser di mesin yang sama), ditampilkan dalam detik sejak klik Run. "Waktu aktual" pada SUMMARY UI = showSimulationDataResult_done - klik Run (angka yang sama).

| input | hasil | job_start | first_candidate_complete* | first_valid_claimed | first_fully_valid = FASTEST_RELEASE_READY | browser_poll_received | output_snapshot_fetched | renderResult_start | renderResult_done | showSimulationDataResult_done | exact_cancel_sent | fully valid -> Simulation Data (s) | diperiksa / valid | CP | Heat Rate | Simulation Data tampil |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PGN31 | FASTEST VALID PLAN | 0.55 | 0.38 | 1.67 | 7.38 | 7.55 | 7.55 | 7.55 | 7.67 | 7.79 | 7.79 | 0.41 | 12 / 8 | 63.5711 | 8148.58 | YA |
| PGN31 (warm: FINAL Maximum Review PGN 32 lalu ubah PGN 31 di UI) | FASTEST VALID PLAN | 0.05 | -0.18 | 1.17 | 7.15 | 7.31 | 7.31 | 7.31 | 7.40 | 7.47 | 7.47 | 0.32 | 14 / 9 | 63.5711 | 8148.58 | YA |
| PGN29 | FASTEST VALID PLAN | 0.52 | -0.07 | 1.21 | 4.76 | 4.88 | 4.88 | 4.88 | 4.98 | 5.06 | 5.06 | 0.30 | 11 / 7 | 63.8518 | 8237.75 | YA |
| PGN32 | FASTEST VALID PLAN | 0.53 | 0.61 | 1.76 | 6.29 | 6.39 | 6.39 | 6.39 | 6.54 | 6.63 | 6.63 | 0.34 | 12 / 8 | 63.4469 | 8109.13 | YA |
| PEP0 | Keputusan Gas Shortage (tidak ada kandidat fully valid) | - | - | - | - | - | - | - | - | - | - | - | - / - | - | - | YA |
| KP72_0 | FASTEST VALID PLAN | 0.60 | 0.20 | 1.61 | 6.29 | 6.51 | 6.51 | 6.51 | 6.64 | 6.73 | 6.73 | 0.44 | 12 / 8 | 63.5889 | 8128.86 | YA |

*first_candidate_complete dibaca dari mtime berkas counter kandidat (resolusi 1 detik).

## Akar masalah 38,3 s / berhenti di "entry 3%"
1. Poller Fastest hanya dimulai pada dua cabang respons Run tertentu. Job yang lahir lewat jalur lain (state yang sudah pernah dihitung / handoff / admission dini) tidak pernah dipantau: tanpa klaim, Fastest jatuh ke perilaku Maximum Review (seluruh pool, 18 diperiksa / 13 valid, 38,3 s).
2. Bila klaim terjadi tetapi poller tidak berjalan, pemilik job berhenti (klaim) sementara UI masih membaca job_poll -> progres terhenti di "Perhitungan eksak: entry 3%".
3. V3 membatalkan job di server tepat setelah snapshot ditulis (CANCEL sebelum FETCH).
## Perbaikan V4
- Poller Fastest dimulai dari adoptBackendAsyncJob — titik yang dilalui SEMUA jalur job — dan memanggil arm=1: job ditandai Fastest apa pun jalurnya; kandidat valid yang sudah ada di kolam sebelum tanda dipasang langsung diklaim.
- Urutan: SNAPSHOT atomik (server) -> FETCH (polling 200 ms) -> renderResult/refreshOverview/refreshPills -> showSimulationDataResult (tab Simulation Data aktif) -> BARU job_cancel. Pembantu yang tersisa berhenti sendiri 30 s setelah rilis bila pembatalan tidak pernah tiba.
- Bukti merit (review Unit Priority, C1-C4, STG, LLF, Change Over) tetap dihitung SATU kali oleh proses yang menemukan kandidat, tidak diulang.
Tidak berubah: dispatch/CP/Heat Rate kandidat (identik V2/V3), merit C1-C4, STG, Change Over, Maximum Review.
