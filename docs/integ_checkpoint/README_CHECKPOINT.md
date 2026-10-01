# INTEGRASI MERIT + FASTEST — CHECKPOINT, BELUM FINAL

**Ini CHECKPOINT, BELUM FINAL. Jangan dipakai sebagai production final.**

- Branch: `claude/gifted-ritchie-q0hfdp`
- Source commit merit (source produksi di root ZIP): `a905f10`
- Harness commit: `bc4cdf1`
- Commit laporan checkpoint terbaru: `43d47ce` (sudah dipush; working tree bersih)
- Baseline: V12 checkpoint (`7ac6ab8`). `worker02.php` dan `worker_functions.php` byte-identical dengan V12 checkpoint; perubahan hanya di `run.php` (audit merit C4, gate Fastest) dan `index.php` (Fastest - Default).
- Kandidat Copilot: tidak ada perubahan engine Copilot yang diambil (seluruhnya Change Over / acceptance / cache reset); hanya tampilan dropdown dan diagnostik gate di `index.php`.

## Status
- **12 merit states PASS** (MERIT_DISPATCH_AUDIT.md, merit/MERIT_12_STATES.json): 22 butir per state + kriteria FAIL (start unit rendah dengan headroom cukup, unit tinggi tidak dinaikkan, STG, stop row legal pertama, LOW_LOAD_FRAGMENTATION, comparator CP, band Heat Rate 0,2 %, cold/warm/tanpa pembantu identik, hard constraints + provenance).
- **C4 (lintas grup prioritas, baru di engine, memblokir FINAL): 0 temuan tanpa alasan** pada 12 state (merit/C4_REPORT.md). Temuan sah: status paksa, atau GE/G10 pada akun MM2100 yang kuotanya terpakai penuh (GTG tidak boleh membakar gas MM2100).
- **Exact STG proof PASS**: setiap row x STG (144/144 per state, FINAL dan sebelum redistribusi) = calc_stg engine atas GTG pemasok uap (merit/STG_PROOF.md).
- WB09_3: CP 63,4469 USD/MWh, Heat Rate 8109,13 BTU/kWh; G8/G9 108 MW, G1 31 MW saat diperlukan; G5 start row 27 hanya karena kapasitas Export row 28-31 tidak cukup (DELAY @28/@29 tidak valid: EXPORT), stop row 39 = row legal pertama sesudah minimum runtime 12 row.
- **Dua Change Over smoke tests identik dengan V12 checkpoint** (B2->B1 dan B1->B2 sim/sim, WB09_3) — CHANGE_OVER_SMOKE.md.
- Fastest - Default: 6/6 (CO OFF) dan 8/8 (CO ON) — FASTEST_AUDIT.md.

## Targeted suite terakhir (source a905f10): **316 PASS / 3 FAIL**
- FAIL `P03` dan `P07` (V9 Unit Priority generik, butir "FINAL ... rilis") dan `T8` (V8 kopling blok STG, G1 stop 10:00): CP, alasan, dan audit identik dengan run V12 checkpoint yang lulus (CP 64,7133 / 64,5005); yang berbeda hanya status FINAL/rilis.
  Dugaan sebab (BELUM diverifikasi, sesuai instruksi tidak ada perbaikan panjang): gate C4 baru menolak FINAL pada state dengan perintah stop G1 / repair reserve. Langkah berikutnya: baca `V12 Dispatch Merit Audit.c4_cross_group_priority.detail` pada ketiga state ini; bila temuan sah (mis. ramp/kopling blok STG) tambahkan pengecualian numerik generik, bila tidak sah perbaiki dispatch.
- Log: reports/targeted/targeted_integ.log; laporan per suite: reports/targeted/.

## Belum dijalankan
- Full regression — BELUM.
- Clean-extract — BELUM.
- ZIP FINAL — belum dibuat.
