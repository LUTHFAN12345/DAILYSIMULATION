# Audit Import — IE Prediction & Dispatch (+ PV)

**Lokasi:** hanya tab *Frequently Input → Name Plan & IE/Dispatch → IE prediction & dispatch*, tombol **Import CSV / Excel…** (`.csv` dan `.xlsx`).

## Kontrak file (CSV dan Excel memakai mapping yang sama: `parseRowsAD()`)

| Row / kolom | Isi |
|---|---|
| Row 1 | header/judul — **selalu dilewati**, tidak pernah dibaca sebagai data |
| Row 2–49 | tepat **48** data row |
| A | Tanggal (`dd-mmm-yy`, `yyyy-mm-dd`, `dd/mm/yyyy`, atau serial tanggal Excel) |
| B | IE (MW) |
| C | Dispatch |
| D | PV (MW, ≥ 0) → *Spinning Reserve › Follow PV* |

- Jam tidak dibaca dari file: slot dibentuk berurutan 00:30, 01:00 … 00:00 (row ke-48 = H+1) sesuai nomor row.
- **Format lokal aman:** delimiter dideteksi dari header (`;`, tab, atau `,`), desimal koma diterima (`0,22`), dan tanda kutip dibuang.
- **Ditolak (data existing tidak diubah):** jumlah data row ≠ 48, lebih dari satu tanggal, tanggal tidak valid, IE/Dispatch non-numerik, PV non-numerik/negatif (bila kolom D ada).
- **Fixture** `CSV Load Pred dan Dispatch (5 Oct).csv`: header `Tanggal,IE (MW),Dispatch,PV (MW)`. Sebelumnya file ini ditolak ("Header tidak lengkap"), karena `IE (MW)` tidak cocok dengan pola `^ie$`. Kini dibaca posisional.

## Preview sebelum Apply

```
Rows detected: 48
Date: 05-Oct-26
IE: loaded
Dispatch: loaded
PV: loaded
PV min/max: 0.22 / 24.15 MW
```

Tombol **Apply Import** dan **Cancel** (Validate dan Reset tetap ada). Reset mengembalikan IE, Dispatch, dan PV sebelum Apply.

## Apply

- A–C masuk ke `data1[i].value` (IE), `data2[i].dispatch`, dan label waktu.
- D masuk ke `data3.modeling.pv_rows[48]` dan tabel PV. 48 row ditimpa di tempat, tidak digandakan.
- **Mode SR tidak diubah oleh import.** Bila Follow PV OFF, PV tersimpan tetapi tidak menjadi constraint.
- Setelah import, IE, Dispatch, dan PV tetap dapat diedit manual. Edit PV tersimpan melalui Save/Reload (lihat T3).
