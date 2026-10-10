# DISTILLATE_LNG_FAST_PATH_AUDIT

## Jalur setelah klik popup
1. `gsfRerun` mengirim payload dengan `use_lng` / `use_distillate`, `_fast_default` sesuai target, dan flag internal dibuang.
2. `run.php mode=run` → handoff job.
3. `pp_job_run_economic_review` → `pp_v13f_fast_job` → `pp_v7_fuel_rerun_from_basis`.
4. Basis = `jobs/_fastbasis/<key>.json` dari run P0. Bila tidak ada, `pp_v13f_pipeline` pada state rekomendasi lalu disimpan.
5. `pp_v3_frozen_eval` pada state bahan bakar, tanpa family dan tanpa GCR.
6. V8 priority review → `pp_v15_fast_priority_fix` → acceptance + merit gate → `pp_store_commit('SIMULATION_FINAL')`.

## Temuan dan perbaikan
| Temuan | Dampak | Perbaikan |
|---|---|---|
| Copilot mengganti `rsort` → `sort` untuk slot Distillate | Golden berubah, CP berbeda dari Excel | `rsort` dipulihkan; golden kembali identik (CP 80,2321, 96.057,7 l) |
| `$gasTotal()` di `pp_anticipatory_commit_for_reserve` tanpa `/2` | Semua kandidat commit untuk SR ditolak (gas dihitung 2×) | Ditambah `/2.0` |
| Batas gas atas untuk commit SR saat LNG tidak memasukkan `additional_lng` | SR baris 17–18 gagal pada Follow PV + LNG | `$gHiW += additional_lng` untuk add_lng/mixed; `$gHiW = 0` untuk distillate |
| Kandidat SR diurutkan menurut gain | Unit prioritas rendah (G7) di-start | Diurutkan menurut prioritas |
| Daftar `unresolved` audit dipotong 40 | Pasangan C1 tidak terlihat | Membaca `flags` lengkap |
| Follow PV hanya UI | SR tidak mengikuti PV | Per-baris di semua titik SR (repair, redispatch, commit, certificate, proof, validator) |

## Waktu (lihat COLD_WARM_RUNTIME.md)
- LNG diterima: median 15,97 detik setelah klik (cold), max 17,58. Critical path: V8 priority review ±13 detik.
- Distillate diterima: median 6,94 detik setelah klik (cold).
- Warm (state identik): ±0,8 detik.
