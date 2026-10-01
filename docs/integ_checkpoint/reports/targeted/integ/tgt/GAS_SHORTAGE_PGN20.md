# TARGETED TEST GAS SHORTAGE — PGN 20 / PGN 30

Hasil: **9/9**

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| T1 | PGN 20: popup Gas Shortage menampilkan angka LNG dan Distillate, tombol aktif setelah validasi | LULUS | popup 5603 ms (awal: "Menghitung opsi yang tervalidasi…"), angka 6626 ms: LNG 9.7385 BBTUD, Distillate 272475.8 l |
| T2 | PGN 20 -> LNG rekomendasi -> rerun final otomatis, hasil final valid | LULUS | 4428 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"add_lng","added":9.7385,"used":0} |
| T3 | PGN 20 -> Distillate rekomendasi -> rerun final otomatis, limit dihormati | LULUS | 3297 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"use_distillate","added":0,"used":270299.2} |
| T4 | PGN 20 -> LNG manual kurang -> sisa ditutup Distillate otomatis | LULUS | LNG 4.8693; 3426 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"mixed_lng_distillate","added":4.8693,"used":135245.6} |
| T5 | PGN 20 -> LNG manual lebih -> memakai kuota LNG user, tanpa Distillate | LULUS | LNG 11.7385; 3732 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"add_lng","added":11.7385,"used":0} |
| T6 | PGN 30 selesai tanpa popup bila tidak ada shortage, hasil final valid | LULUS | modal 68 ms, progres 765 ms, total 16396 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"recommendation","added":0,"used":0} |
| T9 | PGN 20 -> Cancel: popup tertutup, hasil pendahuluan tidak diterbitkan, Export/Publish tetap terkunci, Save Input tetap aktif (V3) | LULUS | popup 310 ms; sesudah Batal: popup=false, gate=true, saveInputAktif=true, exportAktif=false, msg="Dibatalkan. Input dan hasil sebelumnya tidak diubah." |
| T7 | Progress 100% langsung selesai: hasil tampil <= 2 s setelah job DONE, polling berhenti | LULUS | jeda DONE->tampil per skenario (ms): [180,223,195,210,261]; polling sesudah hasil tampil: [0,0,0,0,0] |
| T8 | Tidak ada CMD/proses OS, tidak ada job gagal/menggantung, tidak ada JavaScript error | LULUS | jobs=7 (DONE), gagal=0, menggantung=0, scan=0 temuan, jsErr=0 |

## Runtime (ms)

```json
{
 "pgn25": {
  "modal": 54,
  "firstProgress": 763,
  "popup": 5603,
  "validated": 6626,
  "optionToFinal": 4428,
  "total": 11058
 },
 "pgn30": {
  "modal": 68,
  "firstProgress": 765,
  "total": 16396
 }
}
```
