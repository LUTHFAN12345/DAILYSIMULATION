# Audit Spinning Reserve — Fix Spinning Reserve / Follow PV

## UI (Frequently Input → *Bus Flow & Spinning Reserve*)

Dua section terpisah dengan urutan tetap:
1. **BUS FLOW** (atas). Input `Bus flow min` dengan token `busflow_min` yang sama; semantics dan validasi Bus Flow tidak diubah.
2. **SPINNING RESERVE** (bawah):
   - radio eksklusif `Fix Spinning Reserve` / `Follow PV` (hanya satu aktif);
   - input nilai fix (label berubah menjadi "SR floor (Fix)" pada Follow PV);
   - tabel 48 row: #, Date, Time (00:30 … 00:00), PV (MW, dapat diedit per 30 menit), Effective SR Minimum (MW).

## Token stabil (`data3.modeling`)

| Token | Isi |
|---|---|
| `sr_mode` | `fixed` atau `follow_pv` |
| `sr_fixed_mw` | nilai fix / floor (MW). `spinning_reserve_min` tetap ditulis sama untuk konsumen lama |
| `pv_rows[48]` | PV per row (`null` = kosong) |
| `sr_effective_rows[48]` | SR minimum efektif per row (hasil rumus) |

Input lama tanpa `sr_mode`/`sr_fixed_mw` memakai mode `fixed` dengan nilai `spinning_reserve_min`, sehingga perilakunya identik dengan versi sebelumnya (fingerprint U31 tidak berubah, lihat TARGETED_TEST_A_H.md, grup H).

## Rumus (satu sumber: `pp_reserve_rows()` di worker_functions.php, identik dengan `srEffectiveRows()` di UI)

- `fixed`: `SR_min[row] = sr_fixed_mw` untuk 48 row.
- `follow_pv`: `SR_min[row] = max(sr_fixed_mw, PV[row])`. PV kosong/invalid → `sr_fixed_mw`, dicatat di `info['Spinning Reserve Requirement'].pv_invalid_rows` dan `warning` (bukan 0 diam-diam).

## Pemakai array yang sama

Pembanding scalar lama `pp_reserve_min()` diganti `pp_reserve_min_row($model, $row)` pada:
- **hard validator:** `spinning_reserve` per row;
- **repair/screening:** `pp_reserve_repair`, `pp_reserve_*` (deficit per row), re-maximize `$needRM`, dan screening kandidat (`worker02.php`, hitung `resU`);
- **sertifikat dan bukti:** `pp_reserve_certificate` dan `pp_reserve_infeasibility_proof`;
- **audit merit:** `reserve_allowance_mw` per row.

`pp_reserve_min()` kini mengembalikan nilai maksimum array dan hanya dipakai sebagai guard "ada requirement".

Output dan persistensi:
- Setiap row output memuat `SR_Min`. Bila Follow PV aktif, row juga memuat `PV`, yaitu nilai PV input yang dipakai run itu (lihat PV_SIMULATION_DATA_AND_CHART.md).
- Simulation Data menampilkan kolom **SR MINIMUM (MW)**, juga ikut di export Excel. Urutan kolom: BUSFLOW | PV (hanya Follow PV) | SPINNING RESERVE | SR MINIMUM.
- Pewarnaan sel Spinning Reserve memakai `SR_Min` row itu.
- `info['Spinning Reserve Requirement']` memuat mode, rumus, 48 nilai, min/max, dan warning.
- Save/Reload, Report Planning, Monitoring twin, dan export/import JSON membawa token karena tersimpan di `data3.modeling` (snapshot `input`) dan `data[].SR_Min` (snapshot `output`).

## Perubahan ronde ini

- **Kolom PV (MW)** di Simulation Data, Export, dan Report: hanya untuk Follow PV, di antara BUSFLOW dan SPINNING RESERVE.
- **Grafik line PV** di bawah tabel PV: hanya untuk Follow PV, diperbarui saat edit/import, dan disembunyikan pada Fix tanpa menghapus data.
- **Input PV negatif / bukan angka ditolak** dengan pesan, dan nilai sebelumnya dipertahankan. Sebelumnya input seperti itu dikosongkan secara diam-diam.
- Rumus, token, dan pemakai array **tidak berubah**.

## Bukti uji

Lihat TARGETED_TEST_A_H.md:
- **Grup C (Fix SR 10 MW):** 48 row = 10, Follow PV OFF, kolom PV tidak tampil.
- **Grup D (Follow PV, floor 2 MW, PV row 20 diedit 25,5):** 48 row = `max(2, PV)`, posisi dan nilai kolom PV, serta grafik.
- **Grup E:** import CSV/Excel/format lokal, Save/Reload, dan Report.
- Effective SR dicek di 48 row terhadap rumus, `SR_Min` engine identik dengan array UI, dan `Spin_Res ≥ SR_Min` di 48 row.
