# CHANGES — V15.17b

Lihat `ROOT_CAUSE_BBLN1_BLOCK1_G8G9_MERIT_PROOF.md` §5 (patch A–G) dan §10 (berkas). Ringkas:

- A. Merit proof STG memakai hold start STG engine (`pp_stg_hold_from_rows`, satu sumber engine + proof).
- B. UI Block Required tidak lagi menimpa Start/Stop Based On Simulation operator.
- C. Pencarian window supplier PGN berhenti pada respons tidak kontinu (stagnasi).
- D. Fastest deterministik: batas jumlah evaluasi/kandidat, bukan jam dinding.
- E. `report_planning` tidak ikut input komputasi engine (−10 s pada T0).
- F. Proof Binding (payload/candidate/commitment/fuel/proof hash + proof_version + validated_at) dan rebind sebelum publish.
- G. IE warm: pendaratan gas diulang tanpa hint supplier bila gagal.
- Fitur: tombol **⇩ Input Snapshot** (`run.php?mode=input_snapshot`, read-only).

Semua patch punya saklar env (default aktif) untuk A/B.
