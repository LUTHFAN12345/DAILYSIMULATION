# Audit — koreksi PGN minimum flow otomatis (redistribusi Fixed Flow JBBK)

## 1. Pemicu (sertifikat, run.php `pp_bs_row1_certificate`)

Koreksi berjalan **hanya** bila engine membuktikan, sebelum pipeline/keluarga dijalankan, bahwa pada satu blok row berurutan:

```
FLOW PGN RT maks[row] = (24 x Σ calc_fuel(unit gas tersedia @ beban maksimum efektif) - FixedFlow_J[row] x GHV_J/1000) / GHV_PGN x 1000
                      < Min PGN Flow
```

"Unit tersedia" per row ditentukan sebagai berikut:
- Unit Running pada Last Data yang tidak dihentikan jadwal (`pp_is_unit_stopped`).
- Unit Stop pada Last Data, hanya bila row ≥ row berbeban paling awal. Row itu adalah row 2 (aturan `last_data_status`), atau jam *Start Based on Request* (`required_mode start_at`) bila lebih lambat, dan unit tidak sedang dalam jadwal unavailable.

Beban maksimum efektif adalah batas atas yang sah, sehingga bukti ini konservatif: bila pada beban maksimum pun tidak cukup, tidak ada dispatch yang dapat memenuhi minimum.

Sertifikat ini adalah generalisasi sertifikat row 1 lama. Untuk input yang hanya row 1-nya terkendala, isinya sama dengan versi sebelumnya (`row`, `time`, `units_online_row1`, `fixed_flow_max_feasible_mmscfd`), ditambah:
- `rows`: seluruh row terkendala;
- `period`: label periode, misalnya `00:30` atau `00:30-02:00`;
- `per_row`: bukti numerik tiap row.

Delapan syarat dicatat di `conditions`:
1. PGN di bawah minimum;
2. required block dipertahankan;
3. tiap required block gas punya unit gas running;
4. unit required/continuous tidak legal dimatikan;
5. headroom unit running sudah maksimum;
6. startup lain tidak dapat berbeban di row itu;
7. Distillate tidak menaikkan flow PGN;
8. penyebab residual adalah Fixed Flow JBBK.

Koreksi tidak pernah dipicu oleh kegagalan kandidat.

## 2. Pengurangan minimum per row (`pp_bs_pgn_recommendation`)

- **Nilai awal per row sumber:** `floor(FixedFlow_max_feasible[row], 0,01)` dari sertifikat.
- **Verifikasi:** satu core run engine dengan Manual Fixed Flow tersebut, divalidasi `pp_validate_hard_constraints`, yaitu validator yang sama dengan rerun.
- **Row yang masih gagal:** PGN di bawah minimum, atau pelanggaran lain yang menyebut row itu (misalnya Spinning Reserve). Row tersebut saja yang diturunkan 0,25 MMSCFD per langkah, maksimal 25 langkah; row lain tidak disentuh.
- **Hasil fixture:**
  - SR 0 → 34,82;
  - Fix SR 10 → 32,57;
  - Follow PV floor 2 → 34,32;
  - varian 00:30–02:00 → 34,82 pada row 1–4.

## 3. Redistribusi water-filling (`pp_bs_ff_redistribute`)

**Recipient.** Hanya row **sesudah** periode terkendala, bukan row actual, dan dengan kapasitas aman > 0. Row sebelum dan di dalam periode tidak pernah menjadi recipient. Row sebelum periode hanya dipakai bila tidak ada satu pun row aman sesudahnya; alasannya dicatat di `earlier_rows_used_reason`.

**Kapasitas aman per row.**

```
kapasitas[row] = max(0, FLOW_PGN_RT[row] − Min PGN Flow − margin) × GHV_PGN / GHV_J
```

FLOW dibaca dari core run dengan row sumber sudah dikurangi; margin 0,10 MMSCFD, atau 0,50 bila verifikasi pertama gagal.

**Water-filling deterministik.** Unit alokasinya 0,0001 MMSCFD per slot 30 menit:
1. Volume dibagi rata ke seluruh recipient.
2. Row yang mencapai kapasitas aman dikunci (`capped`), lalu sisanya dibagi rata lagi ke recipient yang masih punya kapasitas, sampai habis.
3. Sisa yang lebih kecil dari jumlah recipient diberikan +0,0001 ke row dengan sisa kapasitas terbesar (tie: row terkecil).

**Volume.**
- `Σ FixedFlow[row] × jam_slot[row] / 24` dihitung sebelum dan sesudah. `slot_hours` dicatat (48 × 0,5 jam).
- Selisih dan toleransinya (1e-6 MMSCF) ditulis di audit.
- Hasil fixture: 36,000000 → 36,000000 MMSCF, selisih 0.

**Verifikasi.** Satu core run dengan ke-48 nilai dijalankan. Syaratnya: tidak ada pelanggaran baru dibanding run sumber-saja, dan `pgn_rt_min` bersih (`constraint_validation.status = PASS_NO_NEW_VIOLATION`).

**Hasil.**

| Kasus | Periode terkendala | Sumber | Recipient | Tambahan per recipient | Total harian |
|---|---|---|---|---|---|
| A (SR 0) | 00:30 | 36,00 → 34,82 | 47 row 01:00–00:00 | +0,0251 / +0,0252 | 36,0000 → 36,0000 |
| A2 (varian unit lain tidak tersedia row 1–4, G8 Start at 02:00) | 00:30–02:00 | 4 × (36,00 → 34,82) | 44 row 02:30–00:00 | +0,1072 / +0,1073 | 36,0000 → 36,0000 |
| C (Fix SR 10) | 00:30 | 36,00 → 32,57 | 47 row | +0,0729 / +0,0730 | sama |
| D (Follow PV floor 2) | 00:30 | 36,00 → 34,32 | 47 row | +0,0357 / +0,0358 | sama |

PGN minimum sesudah koreksi: 3,0072 (A/A2), 3,0233 (C), dan 3,0127 (D) MMSCFD, semuanya ≥ 3,00.

## 4. Tidak feasible → terminal

Bila kapasitas aman row sesudah periode < volume yang harus dipindah, atau verifikasi gagal, keputusan terminalnya `PGN_FIXED_FLOW_REDISTRIBUTION_NOT_FEASIBLE`:
- tidak ada perubahan sebagian;
- tidak ada audit yang ditulis;
- tidak ada polling.

Pesannya memuat periode terkendala, volume yang harus dipindah (MMSCFD-slot dan MMSCF), kapasitas aman, volume yang tidak teralokasi, row yang dievaluasi, dan constraint pembatas.

Contoh kasus B: varian 00:30–02:00 dengan Manual Fixed Flow row 5–48 dinaikkan sehingga headroom PGN sempit.

| Besaran | Nilai |
|---|---|
| Volume yang harus dipindah | 4,72 MMSCFD-slot (0,098332 MMSCF) |
| Kapasitas aman | 3,242 |
| Tidak teralokasi | 1,478 MMSCFD-slot (0,030792 MMSCF) |
| Pembatas | `pgn_rt_min` (headroom recipient tidak cukup) |
| Waktu sampai terminal | ±2,5 s |

## 5. Otomatis di UI (tanpa popup)

**Popup dihapus.** Popup *Low PGN Flow Recommendation* beserta tombol Apply/Keep tidak ada lagi.

**Hasil job pertama.** Job pertama mengembalikan `status = PGN_MIN_FLOW_AUTOMATIC_CORRECTION` dan `pgn_recommendation.available = true` (sekitar 2–3 s). `pgnAutoApply()` lalu:
1. menulis 48 `fixed_flow_after` sebagai Manual Fixed Flow JBBK, hanya untuk row sumber dan recipient;
2. menyimpan audit di `data3.modeling.pgn_fixed_flow_recommendation_applied`;
3. menampilkan status singkat: *"PGN minimum-flow correction applied automatically. Fixed Flow JBBK was reduced during the constrained period and redistributed to later safe periods. The daily total remains unchanged."*;
4. menjalankan **satu** rerun (`_run_source = pgn_auto_correction`).

**Tanpa loop.** Rerun yang masih terkendala tidak dikoreksi lagi dan langsung menjadi keputusan terminal.

**Catatan informasi `#pgn-auto-note`.** Catatan di bawah tombol Run memuat periode, nilai sumber, rentang tambahan recipient, total harian sebelum/sesudah, PGN sumber sebelum/sesudah, jumlah rerun, status validasi, dan tabel per row yang dapat dibuka. Bila Fixed Flow JBBK diedit manual sesudahnya, catatan ditandai *superseded* dan audit tetap disimpan.

**Field audit (`correction_mode = automatic`).**
- `source_rows`, `recipient_rows`, `fixed_flow_before[48]`, `fixed_flow_after[48]`;
- `daily_total_before` / `daily_total_after` (MMSCFD dan MMSCF), `daily_total_difference_mmscf`, `tolerance_mmscf`;
- `pgn_flow_before[48]` dari core run sertifikat, dan `pgn_flow_after[48]` dari Simulation Data rerun, diisi saat hasil rerun tampil;
- `rerun_count = 1`;
- `constraint_proof` (bukti sertifikat + per row + 8 syarat), `constraint_validation`, `water_filling`, `recipient_period`, `constrained_period`, `slot_hours`, `verification`.

Audit tersimpan di `input_data.json` / `saved_data_store.php` dan bertahan pada Save/Reload, Report Planning/Monitoring, dan export/import JSON. Diuji di grup E, termasuk XAMPP dan Linux.
