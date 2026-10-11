# DYNAMIC EFFECTIVE PRIORITY — audit V15.18

## Model
- `configured_unit_priority` / `configured_block_priority` = urutan Periodic Input user (tidak pernah ditimpa).
- `effective_priority[row]` (`pp_v1518_effective_priority`): per grup, unit SC (seluruh hari) atau unavailable pada row
  itu (UNAVAILABLE, STOP_ALL_DAY, STOP_SCHEDULE, STOP_AT_REQUEST) dipindah ke urutan terakhir. Mulai row efektif stop.
- Engine `unit_priority` (dipakai merit, decommit, headroom, C1–C4) = configured dengan unit SC/unavailable-seluruh-hari di
  akhir. Unit stop/trip pada row tertentu bukan headroom legal di row itu (sudah dijamin `pp_is_unit_stopped` di semua lever).

## Keluaran
- `info['Effective Priority']`: `configured`, `changes` (row + unit + alasan), `rows` (teks per row), `notifications`.
- Simulation Data: panel catatan di atas TABEL DETAIL SLOT; Excel: kolom "Effective Priority (row)" + baris SC/CC.
- Proof Binding: `effective_priority_hash` (configured + rows).
- Save/Reload/Snapshot: dihitung ulang dari input yang tersimpan (deterministik); Snapshot memuat `semantics`.

## Notifikasi (teks persis, UI = engine)
- Stop All Day: "B1 dijadwalkan Stop All Day. Effective Priority Babelan berubah dari B1 -> B2 menjadi B2 -> B1 untuk seluruh horizon. B1 ditempatkan terakhir karena tidak tersedia untuk dispatch." (U6 PASS)
- Stop jam tertentu: "G6 adalah Unit Priority #1 Block 1, tetapi dijadwalkan stop mulai 16:00. Mulai 16:00 Effective Priority berubah menjadi G4 -> G3 -> G6." (T3, U5 PASS)
- Trip/unavailable, CC→SC ("G6 dipindahkan dari CC ke SC ... G4 -> G3 -> G6 (configured tetap G6 -> G4 -> G3)"), STG Required tanpa GTG CC pengikut, Block Required dengan seluruh GTG OFF/unavailable/SC, lead STG otomatis.

## Hasil T3 (G6 stop 16:00)
rows[31] Block 1 = `G6 -> G4 -> G3`, rows[32] = `G4 -> G3 -> G6`; G6 = 0 sejak row 32; additional G4 row 22 legal,
G3 ditunda ke row 23 oleh counterfactual additional kedua (CP 72,4723 → 72,4059 sebelum koreksi gas; final 72,7165).
