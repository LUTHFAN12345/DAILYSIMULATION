# AUDIT LOGIC PRIORITY PER ROW — V15.27 (basis V15.26, lihat harness/v1526/AUDIT_PRIORITY_LOGIC.md)

STATUS LOGIC EXISTING: **ADA LENGKAP** untuk alokasi per row, penundaan start, stop lebih awal, dan pencegahan start/minimum-load tak perlu;
satu implementasi (tidak ada logic kedua). V15.27 hanya mengubah URUTAN OBJECTIVE pada comparator yang sudah ada dan menutup jalur yang
membatalkan langkah prioritas yang lebih ekonomis.

## Implementasi yang dipakai (tidak berubah lokasi)
| fungsi | peran Unit Priority | perubahan V15.27 |
|---|---|---|
| `pp_solve_block` / `$run_blocks` (worker02) | alokasi awal per row antarblok/dalam blok | — |
| `pp_v5_headroom_priority_audit` (run.php) | temuan "unit prioritas rendah berbeban saat unit Running prioritas tinggi punya headroom legal" per row; headroom = max legal row (effective max, batas ramp tetangga) − beban row; temuan < 0,05 MW per unit-row diabaikan sebagai noise | bukti `EKONOMIS` (memindah 1 MW menaikkan biaya marginal) kembali sah sebagai pengecualian (keputusan user 5: prioritas bukan objective keras di atas CP) |
| `pp_v15_fast_priority_fix` (run.php) | memindah MW ke unit Running prioritas tinggi | dipakai untuk temuan yang tidak terbukti lebih mahal |
| `pp_v1523_tighten` / `pp_v1524_event_proof` / closure | tunda start, stop lebih awal unit prioritas rendah, decommit | closure juga menguji SEKALI langkah valid yang paling mengurangi inversi tanpa startup/blok baru melalui Unit Priority Fix; diterima hanya bila objective ekonomi lebih baik (T050: 85,1301 -> 84,7478) |
| `pp_v1523_cmp` (search/tightening/stage guard) | inversi, startup tak wajib, blok baru | urutan: bal3x1 (dijaga CP) -> startup tak wajib -> blok baru -> CP ekonomi (band 0,02 %) -> HR -> inversi |
| `pp_sp_final_cmp` (final Fastest + Maximum Review) | unused legal higher-priority headroom | headroom legal dinilai sesudah CP ekonomi/CP resmi (bukan sebelum) |
| `pp_v1526_consol_cmp` (konsolidasi blok) | sama | sama |

## Objective final (sama untuk Fastest dan Maximum Review)
hard constraint -> intent user/Required -> tanpa blocking (C1–C4, STG/HPA, fuel, Export, proof) -> eliminasi start otomatis tak perlu ->
CP ekonomi -> CP resmi -> HR (band kecil) -> startup / online unit-row / minimum-load / fragmentasi / latest start / earliest stop -> sidik jari.
Inversi prioritas = penalti/diagnostik sesudah ekonomi; tidak pernah mengalahkan CP tanpa batas. Flag rollback: `PP_V1527_ECON_FIRST=0`.

## Jalur yang melewati / membatalkan (status)
1. Stage guard `UNIT_PRIORITY_FIX` yang menolak hasil lebih ekonomis karena inversi naik — dinonaktifkan di bawah objective ekonomi.
2. Closure bukti event yang tidak pernah menguji langkah prioritas yang baru menguntungkan sesudah Unit Priority Fix — ditutup (lihat tabel).
3. Jalur warm IE / Change Over tanpa tightening — tidak berubah (V15.26), Unit Priority Fix + bukti event tetap berjalan.

## Legal headroom (keputusan user 4)
Semua audit memakai `legal max row − beban actual/planned row` (`pp_v5_headroom_priority_audit`, `pp_v1523_start_context`: legal = min(effective max,
beban + ramp)); bukan max − min. Toleransi 0,05 MW hanya membuang noise pembulatan per unit-row; jumlah headroom material dilaporkan apa adanya.
