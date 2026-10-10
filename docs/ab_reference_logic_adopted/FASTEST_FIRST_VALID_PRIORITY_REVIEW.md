# FASTEST_FIRST_VALID_PRIORITY_REVIEW

## Definisi yang diterapkan
**Fastest - Default** = kandidat **fully valid** pertama. Maximum Review tidak pernah dijalankan dari tombol Fastest.

Hasil Fastest hanya dirilis bila `pp_attach_or_reject_acceptance` lolos. Untuk `_fast_default` dan job gate, ada gate tambahan **Merit Proof C1-C4 STG**: bila proof ≠ PASS, hasil diblok dengan `MERIT_PROOF_C1_C4_STG_FAIL`.

Syarat fully valid:
- hard constraint
- gas window / kuota
- LNG/Dist
- Export
- Bus Flow
- SR (per baris; Follow PV memakai `pp_reserve_min($m,$row)`)
- ramp/runtime
- STG
- C1–C4 FAIL = 0
- merit proof
- release gate
- 48 baris ditampilkan

## Urutan komparator
fully valid → penalti Unit Priority → LLF → jumlah startup → unit online → CP → HR (dalam pita 0,2%) → fingerprint.

Penerapan:
- Kandidat `pp_anticipatory_commit_for_reserve` diurutkan `[scripted, peringkat prioritas, -gain]`. Prioritas didahulukan; sebelumnya diurutkan menurut gain sehingga G7 ikut start pada P10.
- `pp_v15_fast_priority_fix` memperbaiki pasangan LOWER_PRIORITY_LOADED per baris 30 menit:
  - geser beban dari unit prioritas rendah ke unit prioritas tinggi
  - ruang gas = g0 − (q − 0,04) − 0,004, dengan bukti GAS_WINDOW_LOWER_EDGE
  - satu frozen eval gabungan; bila tidak valid, tiap pemindahan ditambahkan satu per satu (greedy): yang valid diterapkan, yang tidak valid mendapat bukti counterfactual tunggal
  - hingga 8 pass (deadline 30 s), dengan bukti diagregasi
  - perbaikan diterapkan walau CP sedikit naik (priority-first). Contoh: Distillate CP 82,0764 → 82,0968
- `pp_v15_fast_llf_trim` merapikan C3 di baris tepi.
- Tidak ada nama unit di-hardcode. Semua berasal dari tab Unit Priority / Unit Priority - Distillate GTG only.

## Bukti C1–C4 / STG ('Merit Proof C1-C4 STG')
| Kode | Isi |
|---|---|
| C1 | LOWER_PRIORITY_LOADED: setiap pasangan harus punya alasan (gas edge, ramp, min-load, bus) atau sudah diperbaiki |
| C2 | START order, plus bukti counterfactual V8 |
| C3 | Temuan LLF |
| C4 | RUNNING order |
| STG | `pp_recompute_stgs` dibandingkan dengan output (selisih 0) |

Contoh P7 (LNG, cold): `C1 PASS_WITH_REASON:66/0, C2 1/0, C3 70/0, C4 101/0, STG PASS:144/0`. Format n/m = dicek/gagal.

## Shortage
- Shortage terbukti saat pipeline basis selesai (`fastest_shortage_proven`).
- Rekomendasi dikirim tanpa menunggu V8. P0 cold 9,2–10,0 detik.

## Distillate
- Alokasi full-first mengikuti tab "Unit Priority - Distillate GTG only".
- Slot diisi mundur (`rsort`), konsisten dengan golden.
