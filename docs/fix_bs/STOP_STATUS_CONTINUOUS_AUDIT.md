# Audit Stop Status — `Unit Continuous Running` dan `Stop Based on Simulation or Unit Continuous Running`

## Pilihan dropdown Stop Status (urutan di UI)

| Label UI | Token tersimpan (`stop_mode[u].mode`) | Semantik |
|---|---|---|
| - | (tidak ada) | — |
| Stop Based On Simulation | `based_on_sim_must` | mandatory stop (tidak berubah) |
| Stop Based On Request | `stop_at` + `at` | tidak berubah |
| **Stop Based on Simulation or Unit Continuous Running** | **`stop_or_continuous_sim`** | dua family dievaluasi: STOP pada row legal terbaik vs CONTINUOUS sampai 00:00; hanya kandidat **hard-valid** dibandingkan; **CP terendah** dipilih, **HR tie-break dalam pita CP 0,2%**; bukti `STOP_SELECTED` / `CONTINUOUS_SELECTED` |
| **Unit Continuous Running** | **`continuous_to_end`** | sesudah start, unit wajib berbeban sampai row terakhir 00:00; tidak pernah menjadi kandidat stop/decommit/konsolidasi/fragmentasi/mandatory/ekonomi; min/max load, ramp, fuel, STG, dan hard constraints tetap berlaku |

Kedua token baru berbeda. `continuous_to_end` **bukan** alias `based_on_sim`.

## Kompatibilitas

- Token lama `based_on_sim` (label lama "Stop Based On Simulation or Continuous Running") dimuat ke pilihan **Stop Based on Simulation or Unit Continuous Running**. Saat Run/Save berikutnya, UI menulis token baru `stop_or_continuous_sim`.
- Input lama yang dikirim langsung ke backend tanpa melalui UI (`based_on_sim`) berperilaku seperti dahulu: optimizer bebas, tanpa perbandingan family.
- Token tersimpan apa adanya di payload, `input_data.json` (autosave Run dan Save), datastore (`data/saved/state`, `data/backups`), Report Planning/Actual (snapshot `input` memuat `data3.modeling.stop_mode`), dan export/import JSON. Tidak ada konversi lossy.

## Implementasi

### `continuous_to_end` (`run.php: pp_bs_resolve_continuous`, dipanggil di awal job)

Implementasi memakai primitive engine yang sudah teruji:

- **Unit Running pada Last Data, atau commitment continuous:** unit masuk `unit_cannot_stop`.
- **Last Data Stop + Start Based on Request:** `start_at` (jam operator) + `unit_cannot_stop`.
- **Last Data Stop + Start Based On Simulation:** jam start diambil dari **satu core run simulasi** (Stop Status unit itu dibebaskan), lalu dijadikan `start_at` + `unit_cannot_stop`. Unit ditahan OFF sebelum start dan tidak pernah berhenti sesudahnya.
- **Unit tanpa start request yang tidak di-start simulasi:** tidak dipaksa start (aturan berlaku bila unit start).

Pendukung lain:
- Bukti resolusi dicatat di `info['Stop Status Continuous Resolution']`.
- Input teresolusi ditulis ke `jobs/<id>/input_resolved.json` dan dipakai juga oleh finalisasi Fastest.
- Seluruh pass stop/decommit/review sudah menghormati `unit_cannot_stop`. Unit ini tidak pernah menjadi kandidat stop.

**Validator** (`worker_functions.php` 8a-ter, pelanggaran `continuous_to_end`): sejak row pertama berbeban sampai row 48, setiap row wajib > 0 MW. Satu-satunya pengecualian adalah row yang dihentikan input hard terpisah (Stop Schedule `unit_stop`/`unit_stop_time`).

### `stop_or_continuous_sim` (`run.php: pp_bs_stop_or_continuous`, akhir job exact)

Langkah evaluasi:
1. Family rencana utama ditentukan dari baris unit: CONTINUOUS bila berbeban sampai row 48 tanpa jeda, selain itu STOP.
2. Family lain dihitung dengan **pipeline exact yang sama** (tanpa tahap keluarga commitment):
   - STOP → `based_on_sim_must` (optimizer mandatory stop memilih row legal terbaik);
   - CONTINUOUS → `continuous_to_end` dengan jam start yang sama dengan rencana utama.
3. Keduanya divalidasi `pp_validate_hard_constraints`. Hanya yang hard-valid dibandingkan: CP terendah menang; bila selisih CP ≤ 0,2%, HR terendah menang.
4. Bila family alternatif menang, rencana diganti. Rencana tetap melewati review merit, acceptance, dan release gate yang sama.
5. Bukti dicatat di `info['Stop Or Continuous Decision']`: per family `valid`, `violations`, `cp`, `hr`, `total_cost`, `first`, `stop_row`, `source`, `wall_s`; ditambah `selected`, `rule`, `tie_break_heat_rate`, `replaced_main_plan`.

Batasan dan catatan:
- **Fastest:** kandidat pertama **tidak** dirilis selama ada unit `stop_or_continuous_sim`, karena keputusan memerlukan dua family (`pp_v12_fast_release_try`). Hasil tampil saat job exact selesai.
- **STG (S1/S2/S3):** dilaporkan `NOT_APPLICABLE_STG`; STG mengikuti GTG feeder, dan family dibandingkan pada GTG.
- **Tanpa hardcode unit:** daftar unit dibaca dari `stop_mode`. Pada T3, G6 menghasilkan STOP_SELECTED dan G8 menghasilkan CONTINUOUS_SELECTED.

## Bukti (freeze, UI Chromium, emulasi XAMPP)

**T2:** G6 Stop Status = Unit Continuous Running (Start Based On Simulation, Last Data Stop).
- Resolusi: `START_AT_00:30 (jam start pilihan simulasi) + UNIT_CANNOT_STOP`.
- G6: 0, 5, 15, 20 … 20 (row 2–48 seluruhnya > 0; **0 row nol sesudah start**).
- Hard PASS, gerbang tanpa blocker, FINAL OPTIMAL, CP 72,4646, HR 8582,03.
- Save OK; `input_data.json` dan datastore memuat `continuous_to_end`; setelah reload dropdown = "Unit Continuous Running".

**T3:** G6 Stop Status = Stop Based on Simulation or Unit Continuous Running.

| Unit | Family | Valid | CP (USD/MWh) | HR (BTU/kWh) | Stop row | Keputusan |
|---|---|---|---|---|---|---|
| G6 | STOP | hard PASS | 71,1857 | 8486,97 | 34 | **STOP_SELECTED** (CP terendah) |
| G6 | CONTINUOUS | hard PASS | 73,4737 | 8712,10 | — | |
| G8 | CONTINUOUS | hard PASS | 71,1857 | 8486,97 | — | **CONTINUOUS_SELECTED** (CP terendah) |
| G8 | STOP | hard PASS | 76,2869 | 8706,79 | 37 | |

Save/Reload mempertahankan `stop_or_continuous_sim`. Rencana T3 terkunci oleh audit merit C4 bawaan (`V12_MERIT_DISPATCH_TANPA_BUKTI`: G3 di atas minimum sementara G5 berprioritas lebih tinggi punya headroom 11 MW pada row 18–22). Merit dispatch tidak diubah; gerbang tidak dipaksa.
