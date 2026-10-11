# Harness V15.17b — BBLN1 Stop All Day / Block 1 / G8-G9

- `t0ui.js <base> <outdir> <tag>` (env `NRUN`): reproducer T0 lewat UI nyata (P0 + CSV 5 Oct + flag unit via elemen UI), mencatat waktu klik→48 row FINAL, gate, merit, hash kandidat/payload, status tombol, dan mengunduh Input Snapshot sebelum/sesudah run.
- `t0cold.sh <tag>`: restart server 8792 + hapus `jobs/` (cold) lalu `t0ui.js` dengan NRUN=2 (cold + warm).
- `runall.sh`: T0–T5 CLI (PHP 7.4) pada source freeze; `sumtt.py` ringkasan.
- `proof.php` / `hash.php`: merit proof dua versi source atas output yang sama + Proof Binding hash.
- `gen.py` + `root_cause_template.md`: membangun dokumen dari hasil nyata.
- `fixtures/`: payload T0–T4 (payload UI aktual, `report_planning` dibuang — tidak dibaca engine) + CSV.
