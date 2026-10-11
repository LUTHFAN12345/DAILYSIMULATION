# CHECKPOINT — V15.17b (BBLN1 Stop All Day / Block 1 / G8-G9 / Merit Proof)

Basis: commit `e63dd91` (ZIP php_simulation_GOLDEN_FASTEST_UI_CONCURRENCY_FINAL.zip, SHA256 208d71ad…706b).

## SHA256 source freeze
```
099848262d5b3ce9b44f73384a2de7099e87f24dc8e510dcbd9a89220dbbf3a4  index.php
f2dd61c2ba0c7e78f704bfb98fac7f8cb077d09e7ce19362b4fc187a20afcf39  run.php
96a0d7e52f8cd66a5a740d4c2e05e26ce50b25bd8c964920fb6fccccc48398d5  saved_data_store.php
d672b2b3ab4ba65775040a8f92290251cd9f3194b17b602df856f352523a9986  worker02.php
784fb41ccb4f6e9eb11c69bfd8a3eedad26d44e1fdb760964422a5cfc0a23d7b  worker_functions.php
```

## Lint
PHP 7.4 dan PHP 8.4: `No syntax errors` untuk kelima berkas. Tidak ada instrumentasi debug tersisa.

## Gerbang paket (instruksi §8)
| Syarat | Status |
|---|---|
| MERIT_PROOF_C1_C4_STG_FAIL hilang karena proof benar | YA — proof STG memakai hold engine; kandidat sama 618587c0…: STG FAIL → PASS, C1–C4 hash identik |
| Current input snapshot dapat diekspor | YA — tombol ⇩ Input Snapshot, 12 unduhan berhasil, payload_hash = Proof Binding |
| T0 PASS 3 cold + 3 warm | YA — XAMPP-like dan Linux, 12/12 FINAL gate PASS |
| Hasil XAMPP dan Linux identik | YA — candidate_hash 0b63b7e26b3bc2e628e5253c, CP 72,3124, HR 8509,21 |
| Tanpa stale cache/kontaminasi run | YA — cold = jobs/ dihapus + server restart; snapshot sesudah run: same_payload_as_snapshot true |
