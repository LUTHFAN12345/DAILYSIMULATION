# REFERENCE_GOOD_LOGIC_ADOPTION

Basis: **Revisi terakhir Copilot (V15.14)**. Reference (V13.4) dipakai hanya sebagai sumber logika.
Tidak ada file reference yang disalin utuh. `worker02.php` dan `worker_functions.php` hasil akhir = file Copilot + patch kecil (diff: worker02 35 baris, worker_functions 90 baris).

## Diadopsi dari reference
| Logika reference | Alasan | Bentuk adopsi |
|---|---|---|
| Rute job async + helper untuk review ekonomi (bukan sinkron 25 detik) | Copilot memotong pipeline sehingga popup hilang | Fastest diserahkan ke job (`FASTEST_FIRST_VALID`), helper ikut dikirim |
| Urutan slot Distillate `rsort` (mundur dari akhir hari) | Golden Baru(5) dibuat dengan urutan mundur. Urutan maju Copilot menggeser 96.057,7 l dan CP | `rsort($eligible)` dipulihkan di dua lokasi; normalisasi `strtolower(trim)` Copilot dipertahankan |
| Rerun bahan bakar V7 dari basis beku (`pp_v3_frozen_eval`) | Cepat dan deterministik | Dipakai, dengan basis Fastest yang tersimpan |
| Popup keputusan bahan bakar dengan angka rekomendasi | Ini yang hilang di P0 | Dipulihkan, plus rekomendasi ≤10 detik |

## Diadaptasi (bukan disalin)
| Logika | Adaptasi |
|---|---|
| V8 priority review | Tetap sumber kualitas. Dilewati bila `fastest_shortage_proven` (keputusan bahan bakar dulu), lalu disusul `pp_v15_fast_priority_fix` untuk perbaikan Unit Priority per baris dengan bukti |
| Exact family / GCR | Hanya untuk Maximum Review. Fastest menetapkan `__pp_fastest_no_gcr`. Family Max diparalelkan dengan helper (`pp_v4_work_publish`) |
| Basis canonical V7 | Max memakai basis pipeline (env `PP_V15_MAX_FAMILY_BASIS=1` mengembalikan canonical) |

## Ditolak
| Logika reference | Alasan |
|---|---|
| UI default = Maximum Review | Fastest dan Max harus tetap terpisah |
| Efek `__no_exact_family` dari input | Flag basi, dibersihkan |
| Build ID / cache reset V13.4 | Fitur baru (Save/Reload, `saved_data_store.php`, Follow PV) harus tetap ada |

## Fitur revisi terakhir yang dipertahankan
- Follow PV ON/OFF dan PV MW (sekarang benar-benar memengaruhi SR per baris, bukan sekadar UI).
- `saved_data_store.php` / `pp_store_commit`: sekarang juga dipanggil untuk `SIMULATION_FINAL` dari rute job.
- Change Over, Required Start, trip/unavailable, Export/Publish, laporan.

Detail per fungsi: `AB_REFERENCE_VS_LATEST_FUNCTION_DIFF.md`.
