# Kolom PV di Simulation Data dan grafik Follow PV

## 1. Kolom `PV (MW)` di Simulation Data

**Posisi wajib.** Daftar kolom dipakai bersama oleh Simulation Data, Export Excel (`resultToHTMLTable`), Download Image, dan Report yang dirender ulang (`ppBuildSimTable`):

```
… | BUSFLOW (MW) | PV (MW) | SPINNING RESERVE (MW) | SR MINIMUM (MW) | …
```

BUSFLOW dipindah ke kiri SPINNING RESERVE. Dengan begitu urutan BUS FLOW → PV → SPINNING RESERVE berlaku juga ketika PV tidak tampil.

**Kapan kolom tampil (`simCols(o)`).**
- Hanya bila run yang ditampilkan memakai Follow PV, yaitu `info['Spinning Reserve Requirement'].sr_mode = follow_pv` dari engine.
- Hasil lama tanpa info tersebut memakai mode input aktif.
- Pada mode Fix, kolom tidak dirender. Data PV tetap tersimpan di `pv_rows`.

**Isi kolom.**
- Isinya nilai **PV input per 30 menit yang dipakai run itu**. Engine menulisnya sebagai `data[].PV` (worker02.php, di samping `SR_Min`), sehingga tidak bisa tertinggal dari input yang dijalankan.
- Hasil lama tanpa field `PV` memakai `pv_rows` input.
- Kolom PV **bukan** effective SR. Effective SR ada di kolom SR MINIMUM (`max(Fix SR, PV)`), sedangkan SPINNING RESERVE tetap menampilkan SR aktual hasil dispatch.

**Header dan tooltip.** Header "PV (MW)" memberi tooltip: *PV input per 30 menit — dipakai sebagai dynamic SR floor saat Follow PV aktif: SR minimum efektif = max(Fix Spinning Reserve, PV)*.

**Contoh (fixture 5 Okt, Fix SR = 2, Follow PV).**

| Time | PV (MW) | SR MINIMUM (MW) |
|---|---|---|
| 00:30 | 0,22 | 2,00 |
| 11:00 | 24,15 | 24,15 |
| 10:00 (diedit) | 25,50 | 25,50 |

Diverifikasi di 48 row oleh check `D_pv_column_position_and_values` dan `E_export_consistent_with_simulation_data`.

## 2. Grafik line PV (Frequently Input → SR & Bus Flow → Spinning Reserve)

**Lokasi dan visibilitas.**
- Grafik berada di bawah tabel PV dan hanya tampil saat **Follow PV** aktif.
- Pada Fix Spinning Reserve, grafik disembunyikan dan data PV tetap ada (`D_chart_hidden_in_fix_mode_data_kept`).

**Implementasi.**
- SVG inline yang dibuat oleh `srChartRender()` di index.php, tanpa CDN atau library eksternal, sehingga identik di XAMPP dan Linux.
- Grafik dirender ulang hanya dari `srRender()`, yaitu saat PV diedit, file di-import, mode berubah, atau lebar container berubah (ResizeObserver).
- Tidak berada di jalur Run simulation atau engine.

**Desain.**
- Sumbu X: 00:30 … 00:00. Label setiap 2 jam (00:30, 02:00, …, 22:00, 00:00) agar tidak bertumpuk; label pertama dan terakhir di-anchor ke tepi.
- Sumbu Y: PV (MW), mulai dari 0, dengan langkah grid "rapi" (0,5/1/2/2,5/5/10/…).
- Satu seri: garis biru 2 px (`#2a78d6`) dengan titik kecil di 48 slot dan grid horizontal tipis (`#e5e7eb`). Tidak ada legend karena judul sudah menyebut seri.
- Crosshair vertikal menempel ke slot terdekat. Tooltip (`textContent`, bukan HTML) menampilkan `Time HH:MM (row n)` dan `PV x.xx MW` untuk ke-48 titik.
- Keyboard: grafik dapat difokus, ←/→ memindah titik, Esc menutup tooltip.
- PV kosong digambar sebagai celah garis dan ditandai "— (kosong → floor)".

**Ringkasan di atas grafik.** PV minimum, PV maximum, PV average, dan Peak time (slot nilai maksimum pertama). Contoh fixture: 0,22 / 24,15 / 6,61 MW / 11:00. Setelah 20:00… diedit menjadi 25,5: maximum 25,50, Peak time 10:00.

**Kebersihan.**
- Listener pointer/keyboard dipasang **sekali** pada container (`data-bound`).
- ResizeObserver dibuat sekali dan hanya merender bila lebarnya benar-benar berubah, sehingga render berulang tidak menumpuk listener (tidak ada kebocoran memori).

**Nilai negatif ditolak.**
- Input PV `< 0` atau bukan angka tidak diterima. Nilai sebelumnya dipertahankan dan ringkasan SR menampilkan, misalnya, `PV row 6 (03:00) ditolak: "-3" bukan angka ≥ 0`.
- Sel kosong berarti PV tidak tersedia, sehingga row itu memakai floor dan warning audit.
- Desimal koma diterima (`24,15` = 24.15).

Bukti: `D_chart_visible_48_points_tooltip`, `D_chart_updates_after_manual_edit`, `D_negative_pv_rejected`, dan `D_chart_hidden_in_fix_mode_data_kept` di TARGETED_TEST_A_H.md.
