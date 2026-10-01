# V10 targeted (versi V11, band CP 0,2 %): rute cepat kanonik, sertifikat, screening dua tingkat

PASS 19 / 19

| id | hasil | bukti |
|---|---|---|
| V01 ACT_PGN_UP FINAL rute cepat: 48 row, hard PASS, Export 48/48, residual 0, rilis | PASS | mode INCREMENTAL, pemenang v10_tier2a:pipeline@-0.16, CP 64.6801 |
| V02 ACT_PGN_UP sertifikat optimalitas cepat lengkap (universe, kunci incumbent, alasan pruning, baris kotor, sidik jari engine/constraint/fuel/priority) | PASS | universe 3dc068cea988b5bdadcbc642, dibangkitkan 13, dipangkas 0 [], tier2a 10, tier2b 3 |
| V03 ACT_PGN_UP invarian V11: FINAL_CP <= CP kandidat valid termurah x 1,002 (band CP 0,2 %) | PASS | FINAL 64.6801, kandidat valid termurah 64.6587 (12 kandidat valid) |
| V01 KP72_DOWN FINAL rute cepat: 48 row, hard PASS, Export 48/48, residual 0, rilis | PASS | mode INCREMENTAL, pemenang v10_tier2a:winner:V11_SUSUN:SWAP:G5>G2:26-37@0.16, CP 64.6214 |
| V02 KP72_DOWN sertifikat optimalitas cepat lengkap (universe, kunci incumbent, alasan pruning, baris kotor, sidik jari engine/constraint/fuel/priority) | PASS | universe 3dc068cea988b5bdadcbc642, dibangkitkan 13, dipangkas 0 [], tier2a 10, tier2b 3 |
| V03 KP72_DOWN invarian V11: FINAL_CP <= CP kandidat valid termurah x 1,002 (band CP 0,2 %) | PASS | FINAL 64.6214, kandidat valid termurah 64.6214 (17 kandidat valid) |
| V01 ACT_PGN_2SLOT_C FINAL rute cepat: 48 row, hard PASS, Export 48/48, residual 0, rilis | PASS | mode INCREMENTAL, pemenang v10_tier2a:winner:V11_SUSUN:SWAP:G5>G2:26-37@0.16, CP 64.6987 |
| V02 ACT_PGN_2SLOT_C sertifikat optimalitas cepat lengkap (universe, kunci incumbent, alasan pruning, baris kotor, sidik jari engine/constraint/fuel/priority) | PASS | universe 3dc068cea988b5bdadcbc642, dibangkitkan 13, dipangkas 0 [], tier2a 10, tier2b 3 |
| V03 ACT_PGN_2SLOT_C invarian V11: FINAL_CP <= CP kandidat valid termurah x 1,002 (band CP 0,2 %) | PASS | FINAL 64.6987, kandidat valid termurah 64.6987 (12 kandidat valid) |
| V01 ACT_FFM_DOWN FINAL rute cepat: 48 row, hard PASS, Export 48/48, residual 0, rilis | PASS | mode INCREMENTAL, pemenang v10_tier2a:winner:V11_SUSUN:SWAP:G5>G2:26-37@0.16, CP 64.6214 |
| V02 ACT_FFM_DOWN sertifikat optimalitas cepat lengkap (universe, kunci incumbent, alasan pruning, baris kotor, sidik jari engine/constraint/fuel/priority) | PASS | universe 3dc068cea988b5bdadcbc642, dibangkitkan 13, dipangkas 0 [], tier2a 10, tier2b 3 |
| V03 ACT_FFM_DOWN invarian V11: FINAL_CP <= CP kandidat valid termurah x 1,002 (band CP 0,2 %) | PASS | FINAL 64.6214, kandidat valid termurah 64.6214 (17 kandidat valid) |
| V04 pustaka dispatch jangkar dibangun ulang (akar tanpa pustaka) vs dipakai ulang: dispatch 48 row & CP identik | PASS | md5 fa80b4e938 / fa80b4e938, CP 64.6309 / 64.6309, pustaka dibangun 32.5 s |
| V05 rute cepat tanpa kandidat valid (window total dan supplier tidak dapat dipenuhi bersama) -> jalur kanonik V9 / exact, bukan FINAL palsu | PASS | mode EXACT, alasan rute cepat V10_TIDAK_ADA_KANDIDAT_VALID, status USER_ACTION_REQUIRED |
| V06 Q_pgn_pipe_1 exact: kandidat comparator global yang dicakup node keluarga off:{u} tidak di-full-run; keluarga lengkap; CP V11 <= referensi V9 x 1,002 (band) | PASS | dipangkas ["G5"], cakupan LENGKAP, keluarga 9 node, CP 64.5424 (V9 64.5450), 33.9 s CLI |
| V06 Q_lng_1 exact: kandidat comparator global yang dicakup node keluarga off:{u} tidak di-full-run; keluarga lengkap; CP V11 <= referensi V9 x 1,002 (band) | PASS | dipangkas ["G5"], cakupan LENGKAP, keluarga 9 node, CP 64.8581 (V9 64.8608), 33.8 s CLI |
| V06 WB09 exact: kandidat comparator global yang dicakup node keluarga off:{u} tidak di-full-run; keluarga lengkap; CP V11 <= referensi V9 x 1,002 (band) | PASS | dipangkas [], cakupan -, keluarga 7 node, CP 64.3644 (V9 64.3644), 26.0 s CLI |
| V07 bukti kapasitas Export: seluruh GTG dimatikan -> terbukti infeasible; commitment pemenang jangkar (Export valid 48 row) -> tidak dipangkas (batas atas sah) | PASS | semua off: "Export maks row 1 = -246.6 MW < Range Min 20.0 MW (seluruh unit tersedia pada Effective Max)"; pemenang: null |
| V08 KP72_DOWN lewat rantai (sesudah ACT_PGN_DOWN) vs langsung (akar baru): dispatch & CP identik; state ulang = cache FINAL | PASS | rantai 9bc52c0f57 CP 64.6214 / langsung 9bc52c0f57 CP 64.6214; ulang 0.28 s |
