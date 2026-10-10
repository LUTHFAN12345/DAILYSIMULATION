# AB_REFERENCE_VS_LATEST_FUNCTION_DIFF

## Sumber yang dibandingkan

| Paket | Berkas | SHA-256 |
|---|---|---|
| Reference (`File_Reference_…_fiture.zip`) | run.php | `ccf4ffc2…f0bb6a`… lihat tabel bawah |
| Revisi terakhir Copilot (`Revisi_terkahir_Copilot.zip`) | 5 PHP + `saved_data_store.php` | |

| Berkas | Reference | Revisi terakhir | Baris berbeda |
|---|---|---|---|
| run.php | `ccf4ffc2910159a23f02d0dbb13a657d27561478185ec230baf85f8e9370fc24` | `e3c3519ff2648816c25286cafac0f795d7d4f7fc72cc48e0b3d21b78964fd1b1` | 50 |
| worker02.php | `e5bf68077b8fb52e5c7be6865c9150759edf7dd4a2cede08ff9a94782f7e21f6` | `8a60bfcff5fc53cedc2c1c884378a91729666c77a5f1d511617935add0bdf71b` | 20 |
| worker_functions.php | `4d8652cc457886a0f93e2268601e32e7abd37e88af4c38d35f51b731569e85eb` | `339bb4cd19a88819b72f90943d034244f20de8b7864af69c20c2434fda0efacd` | 4 |
| index.php | `7babf11a353744ab4fa736aaea3c5551f49cf131b813450c9de363e992ac1edb` | `08167ade860af90af41fc30d20575b27835038e70c48db754b4f139455f0bb6a` | 159 |
| saved_data_store.php | (tidak ada) | `af420702c1a3271cf1387742a40d56d3e794b1ba5bfcf95f5f48e710dbfdd7b2` | baru |

Fakta penting: keempat PHP reference **byte-identik** dengan upload 1 Oktober (build `V13.4-CHANGEOVER-WORKING-CACHE-RESET-20261001`). Revisi terakhir = reference + patch kecil Copilot (build `V15.14`). Jadi perbedaan perilaku berasal dari patch Copilot, bukan dari perbedaan engine besar.

Catatan pada input yang diupload: `data3.modeling` berisi penanda internal `__no_exact_family=true` dan `__fastest_local_only=true` yang tersimpan dari run Fastest Copilot. Pada reference, penanda itu membuat **Maximum Review berjalan tanpa keluarga commitment** — ini salah satu sebab reference tampak "cepat".

## Perbandingan per fungsi (keputusan adopt / adapt / reject)

| Grup | Fungsi reference | Fungsi revisi terakhir | Perbedaan keputusan | Validitas | CP | HR | Runtime | Risiko | Keputusan |
|---|---|---|---|---|---|---|---|---|---|
| K Routing | `index.php`: opsi "Fastest - Default" bernilai `max` → `runSimCore` → job `economic_review` + 3 helper | `__tl==='fast'` → `_fast_default`, `__no_exact_family`, `__fastest_local_only`, budget 25 s → `runSimCore` sinkron | Copilot memaksa satu request sinkron tanpa helper, lalu hasil "terminal" | Tahap terpotong (`time_budget:bb_upfill`, `export_floor_donate`) → konvergensi gagal → tidak ada rencana | — | — | 16,9 s lalu buntu | tinggi | **Reject** rute Copilot; **adopt** rute job reference (handoff seketika `FASTEST_FIRST_VALID`) |
| K Routing backend | `pp_async_admit_early`, `pp_sync_handoff_respond`, `$__v3route` | `_fast_default` mematikan handoff/early-admission, `pp_attach_async_handoff` diganti "terminal" (`Fastest Execution`) | Copilot membuang `async_job` | Job lanjutan tidak pernah ada | — | — | — | tinggi | **Reject**; Fastest diserahkan ke job (`run.php` mode=run) |
| J Release gate | `pp_simulation_acceptance_review` (hard + ekonomi + konvergensi) | + `fastOk` (`FASTEST_LOCAL_ACCEPTED`) | Copilot menerima Fastest bila tidak terpotong | Benar secara aturan | — | — | — | rendah | **Adopt** (dipakai job Fastest) |
| J Release gate | — | V15.14 "final PASS authoritative" menimpa `Gas Shortage Action='none'`, `Gas Shortage=0` | Menghapus jejak aksi bahan bakar dari laporan/Excel | Tidak mengubah dispatch | — | — | — | sedang (laporan salah) | **Adapt**: status keputusan ditutup, aksi bahan bakar TIDAK ditimpa |
| D Shortage/rekomendasi | Popup hanya bila `USER_FUEL_DECISION_REQUIRED` (sesudah review exact penuh, 39 s) | sama; Fastest berhenti di `ECONOMIC_REVIEW_REQUIRED` | Shortage sudah terbukti di pipeline (~8–10 s) tetapi kontrak menunggu konvergensi | — | — | — | 39 s (ref) / tak pernah (Copilot) | — | **Adapt**: job Fastest menyatakan keputusan bahan bakar begitu shortage terbukti (exhaustion lever) |
| D Rekomendasi | job `validated_options` (rerun penuh per opsi) | UI: `FASTEST_RECOMMENDATION_READY` dari `Recommended LNG/Distillate` | Copilot memakai angka mentah tepi window | LNG = kekurangan tepat di tepi atas window (risiko pembulatan) | — | — | ref +9 s | rendah | **Adapt**: Fastest LNG = kekurangan + 0,02 (tengah window), Distillate = estimasi + 1 % margin penutupan diskret; Maximum Review tetap job opsi tervalidasi |
| E/F Rute bahan bakar | `pp_v7_fuel_rerun_from_basis` (dispatch basis dibekukan pada state bahan bakar) + `pp_memo_apply_family` | sama + `__fuel_decision_mode` stale fix | Ref membutuhkan basis exact di memo; tanpa itu menghitung basis kanonik (exact + keluarga) | Valid | Kualitas datang dari review V8 | — | basis kanonik >200 s tanpa memo | — | **Adopt** rute V7; **adapt**: basis Fastest/pipeline (tanpa komparator/keluarga) dipakai bila memo exact tidak ada |
| F Distillate slot | `rsort($eligible)` — slot AKHIR lebih dulu | `sort($eligible)` — slot awal lebih dulu | Copilot mengubah arah alokasi | Valid keduanya | golden: backward 80,2321 vs forward 80,2667 | sama | — | — | **Reject** perubahan arah (golden = backward); **adopt** normalisasi `strtolower(trim())` Copilot |
| F Fuel mode | `__fuel_decision_mode` bisa basi dari snapshot | Copilot: aksi eksplisit operator menang atas penanda basi | — | Benar | — | — | — | rendah | **Adopt** |
| H Follow PV | tidak ada | UI (radio, grafik, tabel PV, kolom PV), `pp_reserve_min($model,$row1)` | **Tidak pernah dipakai engine**: seluruh pemanggil memanggil tanpa row | Follow PV tidak berefek pada dispatch/validasi | — | — | — | tinggi (fitur palsu) | **Adapt**: disambung ke validator + seluruh repair reserve per row; kolom `PV`/`SR_Min` di output |
| H Repair reserve | `pp_anticipatory_commit_for_reserve`: gas kandidat dijumlah tanpa `/2` | sama | Setiap kandidat ditolak "melewati kuota" begitu batas gas diberikan | Reserve tidak pernah diperbaiki lever ini | — | — | — | — | **Fix**: BBTUD harian; kuota efektif aksi bahan bakar; urutan kandidat Unit Priority; lever "majukan start" |
| I/J Merit | `Headroom Priority Audit`, `V11 Low Load Fragmentation Audit` (laporan) | sama | Tidak ada gerbang C1–C4/STG | — | — | — | — | — | **Adapt**: `Merit Proof C1-C4 STG` dari audit yang sama + STG via `pp_recompute_stgs`; gerbang rilis job |
| B/C Unit Priority | `pp_v8_priority_review` (DELAY/STOP/SWAP/OFF), `pp_v6_priority_polish` | sama | Inilah sumber kualitas reference (CLI: basis pipeline → review V8 = CP FINAL exact reference) | Valid | ref 72,3162 | 8230,26 | 14 s (dgn helper) | — | **Adopt** sebagai inti Fastest |
| B Commitment | `pp_global_commitment_review` (komparator), `pp_exact_family_stage` | sama | Bagian Maximum Review (14–15 s komparator + keluarga) | — | — | — | — | — | **Adopt untuk Max**; dilewati pada Fastest (`__pp_fastest_no_gcr`) |
| L Cache/flag | — | `__no_exact_family`, `__fastest_local_only` ikut tersimpan ke input | Penanda basi mematikan keluarga pada Maximum Review berikutnya | — | — | — | — | tinggi | **Fix**: UI tidak mengirim, `mode=save` tidak menyimpan |
| M Save/Report | `mode=save`, report tabs | + `saved_data_store.php` (`pp_store_commit` hanya di mode=run sinkron) | Hasil job tidak dicatat store | — | — | — | — | sedang | **Adapt**: hasil job yang lolos gerbang juga dicommit (`route=job`) |
| UI CSV/XLSX | CSV 3 kolom | CSV/XLSX 4 kolom (PV) | — | — | — | — | — | — | **Adopt** apa adanya |
