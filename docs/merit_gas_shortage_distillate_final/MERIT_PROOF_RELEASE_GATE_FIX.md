# MERIT_PROOF_RELEASE_GATE_FIX

## Gejala (input pengguna, PGN 23 / LNG 13 / PEP 36 / Akasia 4, B1 Stop)

- Sesudah Gas Shortage dan LNG dipilih: Fastest 43–72 s, 15 kandidat / 1 valid, CP ≈ 73.
- Gerbang menolak dengan `V12_MERIT_DISPATCH_TANPA_BUKTI`; semua menu hasil terkunci.

Reproduksi di sini (input persis + LNG rekomendasi): `V12_MERIT_DISPATCH_TANPA_BUKTI` + `V12_LOW_LOAD_FRAGMENTATION_TANPA_BUKTI`, CP 75,22, 99–105 s. Gerbang **benar**: dispatch memang tidak merit-compliant. Defek ada di hulu dan di rantai bukti, bukan di gerbangnya.

## Trace end-to-end dan akar masalah

| Tahap | Temuan | Perbaikan (generik) |
|---|---|---|
| Kandidat terpilih | Rute `V7_FUEL_RERUN_DELTA` memakai ulang dispatch basis Gas Shortage. Di dispatch itu G2/G5 31 MW sementara G8/G9 punya headroom 16–25 MW dan Export di Range Min. | — (lihat C4 di bawah) |
| Hard validation / Spinning Reserve | Repair SR menolak start G4/G3 karena "melewati kuota gas (89,0 > 76,0)". Kuota yang dipakai adalah kuota nominal tanpa LNG/Distillate, sehingga SR selalu gagal pada jalur shortage. Urutan kandidat SR: efisiensi lebih dulu dari Unit Priority (G7 dipilih sebelum G4). Unit di-start tepat di row defisit, padahal masih dalam ramp startup sehingga tidak menambah reserve. | Pada aksi Gas Shortage (recommendation/LNG/Distillate) SR tetap hard: gas tambahan untuk unit yang wajib online demi SR masuk ke angka kekurangan yang ditutup bahan bakar. Urutan start SR: non-gas → Unit Priority → blok STG → efisiensi. Start dimundurkan sebanyak langkah startup sequence agar unit sudah di min load pada row defisit. |
| C1–C3 | PASS / PASS_WITH_REASON | — |
| C4 | Redistribusi per row (6 lintasan × 0,5 MW) tidak pernah selesai. Pergeseran ke unit efisien menurunkan gas di bawah window (LNG must-take), lalu pendaratan generik menaikkan lagi unit prioritas rendah atau melanggar Export. | Fase B2: seluruh pergeseran C4 diterapkan sekaligus, lalu window gas didaratkan oleh unit berjalan **berprioritas tertinggi** (`pp_v12_c4_merit_land`): row yang Export-nya turun karena kopling STG ditutup lebih dulu, sisanya round-robin 0,5 MW pada row yang punya ruang Export, ramp ≤ 30 MW/slot, maks. 3 koreksi (stagnan berhenti). Dipakai bila valid penuh dan temuan C4 berkurang. Hasil T8: C4 12 → 0, merit PASS, 1 evaluasi. |
| Rekomendasi Gas Shortage | Dispatch basis membakar gas lebih dari perlu (unit prioritas rendah di atas min). | Pass akhir **MERIT SWAP** (worker02, hanya saat gas Jababeka di atas kuota efektif): beban dipindah dari unit prioritas rendah di atas floor ke unit prioritas lebih tinggi ber-headroom per row, Export dijaga, dan Bus Flow / SR / langkah Export / ramp / skip / fixed / startup tidak memburuk. Rekomendasi LNG 6,7385 → 6,5486. |
| Serialisasi bukti LLF | Bukti counterfactual konsolidasi (kandidat STOP/DECOMMIT/EARLY_STOP yang disimulasikan penuh dan INVALID) diserialisasi hanya sebagai kode pelanggaran. Hasil akhir LLF menuntut bukti numerik, sehingga temuan ber-bukti tetap FAIL. | Alasan kini membawa identitas kandidat + CP/HR hasil simulasi, mis. `KONSOLIDASI_STOP_SUSUN(STOP:G2:2-48:@14, CP 73.8837, HR 8380.05):INVALID:EXPORT,RAMP,RESERVE`. Aturan validasi tidak diubah. |
| Gerbang merit pada rencana Distillate | Review Unit Priority dilewati (`PEMENANG_BELUM_VALID`) karena dijalankan sebelum rekonsiliasi bahan bakar. Akibatnya `gate_applied=false` dan rencana Distillate dirilis **tanpa bukti merit**. | Bila review dilewati dengan alasan itu dan output final kini valid, review dijalankan ulang pada output final sesudah rekonsiliasi. Gerbang merit berlaku sungguhan. Residual Distillate dihitung pada basis validator (lihat audit PGN23), sehingga evaluasi beku kandidat tereproduksi valid. |
| Validator | Warning kuota gas yang menyebut "min-runtime extension" dipromosikan sebagai `runtime_downtime`. | Warning yang diawali `gas quota exceeded` tidak dipromosikan ke runtime (pelanggaran gas tetap dinilai `gas_quota` dari angka). |
| Fastest vs Maximum Review | Rerun keputusan bahan bakar (`gsfRerun`) dikirim tanpa tanda Fastest. Jalur V7 juga menjalankan `pp_memo_apply_family` (keluarga commitment penuh) meski dispatch basis sudah valid: 120 kandidat, +21 s — Maximum Review terselubung. | `gsfRerun` mengikuti target waktu (Fastest - Default → `fast=1`). Job Fastest tidak menerbitkan atau menjalankan keluarga penuh bila dispatch basis valid pada state bahan bakar. Maximum Review tidak berubah. |

## Hasil (UI asli, Fastest - Default)

T8 (rekomendasi LNG diterima):
- Sebelum: 104,8 s, `V12_MERIT_DISPATCH_TANPA_BUKTI`.
- Sesudah: **28,1 s, FINAL OPTIMAL**, CP 75,043, HR 8562,30, hard PASS, C1–C4 0 FAIL, LLF PASS, STG 144/144.
- Menu Excel, Image Full, Start From, Image Partial, Add to comparison, dan Save (dialog + `mode=save` 200) aktif dan diuji dengan klik nyata.

Tidak ada yang dilemahkan:
- `V12_MERIT_DISPATCH_TANPA_BUKTI` tetap ada dan sekarang juga berlaku pada jalur Distillate (sebelumnya terlewat);
- `publish_allowed` tidak dipaksa;
- aturan bukti LLF tidak diubah;
- tidak ada proof palsu, karena setiap alasan berasal dari evaluasi engine 48 row.
