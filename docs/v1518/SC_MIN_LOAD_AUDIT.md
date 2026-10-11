# SC_MIN_LOAD_AUDIT — MIN SC / MIN CC / MAX per unit (V15.18)

## 1. Aturan kanonik
| Mode unit | Batas bawah dispatch stabil | Batas atas |
|---|---|---|
| SC | **MIN SC** unit (Unit Characteristic → Presence & load limits) | **MAX** unit |
| CC | **MIN CC** unit | **MAX** unit |

- Tidak ada angka lantai yang di-hardcode. Aturan lama "G1–G6 SC = 20 MW" (lihat catatan terbuka di
  SC_CC_SEQUENCE_AUDIT.md §2) **dicabut**: G1–G6 SC sekarang memakai MIN SC user, sama seperti G8/G9 SC.
- Startup transient (STG Cold S1/S2 `[5,15,20,…]`, Additional HRSG `[5,15]`, marker SC 0 MW) tetap mengikuti
  sequence startup unit. Nilainya tidak ditimpa dan tidak menimpa nilai user.
- Fallback `?? 20` / `?? 5` di kode hanya berlaku bila field benar-benar tidak ada di input. Itu tidak terjadi
  pada payload UI karena `#tbl-units` selalu menulis `min_scload`/`min_ccload`/`max_load`.

## 2. Jalur end-to-end yang diaudit
| Tahap | Lokasi | Status |
|---|---|---|
| UI input | `index.php` `#tbl-units` (`data-k="min_scload"`, `min_ccload`, `max_load`) | nilai user ditulis ke `data3[gX]` |
| State / Save / Reload | `saved_data_store.php` (payload utuh) + autosave Run | round-trip utuh (U14) |
| Input Snapshot / payload | `run.php` mode=run, `Export Current Input Snapshot` | sama dengan state (U14) |
| Normalisasi | `pp_v1518_semantics` (`worker_functions.php` ±2140) | mode SC → `min_ccload := min_scload` (salinan engine); nilai CC asli disimpan di `min_ccload_configured`; mode CC → `min_ccload_configured` dipulihkan |
| Worker / kandidat / V8 | semua pembaca memakai `min_ccload ?? min_scload` | otomatis mengikuti mode |
| Repair "MIN LOAD ABSOLUT" | `worker_functions.php` `$minOf` | **diperbaiki**: sebelumnya `max(min_cc, min_sc, min_load)`, sehingga unit CC dengan MIN SC > MIN CC salah lantai. Sekarang `min_ccload ?? min_scload ?? min_load` |
| Validator startup | `worker_functions.php` ±8890 | unit SC: beban ≥ MIN SC bukan langkah 5/15 startup |
| C1–C4 / STG proof | lantai yang sama dengan engine | konsisten |
| CP / HR | dihitung dari dispatch nyata | tidak ada lantai tambahan |
| Simulation Data / Excel | `info['SC CC Mode'].limits` per unit: mode, min_sc, min_cc, max, min_effective | dapat diaudit di output |
| Context hash / proof | `pp_v1517_proof_binding.unit_limits_hash` = hash [MIN SC, MIN CC, MAX] G1–G10 | berubah setiap kali nilai user berubah. Kandidat, legal envelope dan proof dari nilai lama tidak dapat dipakai ulang |

`calc_sr` (tampilan lama, angka 31/108) tidak dipakai untuk keputusan. Nilainya ditimpa oleh `pp_spinning_reserve`
kanonik di `run.php` sebelum output ditulis.

## 3. Uji M0–M5 (CLI, `harness/v1518/mchk.py`)
| Test | Perubahan input | Hasil | CP | limits hash |
|---|---|---|---|---|
| M0 | G6 SC, MIN SC 5 | PASS: min_effective 5; G6 dalam [5, 31]; C1–C4 0; block seq PASS | 72.7408 | f9123cd3c1607cb6 |
| M1 | G6 MIN SC 20 | PASS: min_effective 20; tidak ada beban G6 < 20 | 72.8253 | d2b94f9f0eb93a3d |
| M2 | MIN SC kembali 5 | PASS: fingerprint pemenang dan hash **identik** dengan M0 (tidak ada sisa dari M1) | 72.7408 | f9123cd3c1607cb6 |
| M3 | G9 SC, MIN SC 40 | PASS: min_effective 40; minimum aktual 41.8 | 77.7365 | e7f5828a07151910 |
| M4 | G6 CC, MIN CC 22 | PASS: beban < 22 hanya di langkah startup eksak | 71.3373 | b9778f246ce2083f |
| M5 | MAX G6 28, G4 29 | PASS: G6 ≤ 28, G4 ≤ 29 | 72.7439 | 303454b58b6c5579 |

UI (U14, `harness/v1518/u14.js`): edit MIN SC/MIN CC/MAX di Unit Characteristic → state → payload Run → Input
Snapshot. Nilai tiba utuh. PASS.

## 4. Catatan
- M1 (MIN SC 20) lebih lambat daripada M0. Lantai 20 MW mempersempit ruang koreksi jendela gas, sehingga loop
  supplier window berosilasi lebih lama. Detail ada di V8_CRITICAL_PATH_PROFILE.md.
