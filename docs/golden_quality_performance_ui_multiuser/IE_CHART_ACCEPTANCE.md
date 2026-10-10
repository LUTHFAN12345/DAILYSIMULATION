# IE_CHART_ACCEPTANCE

Chart IE (MW, 48 slot setengah jam) di menu **Name Plan & IE/Dispatch**: SVG inline, tanpa CDN, responsif.

| # | Uji | Hasil | Bukti |
|---|---|---|---|
| U4a | Chart tampil 48 titik dari tabel IE | PASS — `n = 48`, path 48 titik; statistik "IE min 428 · max 598 MW (14:00) · rata-rata 515.9 MW" | uitest.json `ie_before`, U4_ie_chart.png |
| U4b | Edit sel IE row 1 → chart & statistik ikut berubah tanpa reload | PASS — titik 1 naik (y 92.5 → 62.5), statistik max 600 MW (00:30), rata-rata 518.4 | uitest.json `ie_after_edit`, `U4_changed: true` |
| U4c | Tooltip per titik | PASS — "01:00 (slot 2) · IE: 483 MW · Dispatch PLN: 150 MW" | U4_ie_chart_edit_tooltip.png |
| U5 | Import CSV (preview → Apply) memperbarui tabel dan chart | PASS — preview "valid 48 row · error 0 row"; row 21: 581 → 601; max 598 → 618 MW | uiimport.json, U5_ie_chart_after_import.png |
| U6 | Save → reload → chart dari data tersimpan user | PASS — `chart_n = 48`, IE row 6 = 490 setelah reload | uisave.json `ie_chart_after_reload`, U6_ie_chart_after_reload.png |
| U6b | Isolasi user | PASS — user A reload IE row 1 = 471, user B = 500 | conc C6 |

## Implementasi
- `drawIEChart()` dipanggil dari `updateIEDsum()`, sehingga setiap perubahan tabel (edit, paste, import, reload) menggambar ulang chart.
- Garis IE dan Dispatch PLN, sumbu Y dengan grid redup, label jam tiap 3 jam, area hit per slot lebih lebar dari marker.
- Tema terang/gelap mengikuti variabel CSS aplikasi.
