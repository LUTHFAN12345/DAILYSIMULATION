# TARGETED TEST GAS SHORTAGE — PGN 25 / PGN 30

Hasil: **9/9**

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| T1 | PGN 25: popup Gas Shortage menampilkan angka LNG dan Distillate, tombol aktif setelah validasi | LULUS | popup 5594 ms (awal: "Menghitung opsi yang tervalidasi…"), angka 6607 ms: LNG 4.7385 BBTUD, Distillate 132198 l |
| T2 | PGN 25 -> LNG rekomendasi -> rerun final otomatis, hasil final valid | LULUS | 4640 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"add_lng","added":4.7385,"used":0} |
| T3 | PGN 25 -> Distillate rekomendasi -> rerun final otomatis, limit dihormati | LULUS | 3351 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"use_distillate","added":0,"used":131640.7} |
| T4 | PGN 25 -> LNG manual kurang -> sisa ditutup Distillate otomatis | LULUS | LNG 2.3693; 3848 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"mixed_lng_distillate","added":2.3693,"used":66133.1} |
| T5 | PGN 25 -> LNG manual lebih -> memakai kuota LNG user, tanpa Distillate | LULUS | LNG 6.7385; 3469 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"add_lng","added":6.7385,"used":0} |
| T6 | PGN 30 selesai tanpa popup bila tidak ada shortage, hasil final valid | LULUS | modal 48 ms, progres 755 ms, total 15365 ms; {"rows":48,"conv":true,"econ":true,"hard":"PASS","econGate":"PASS","exp":"OK","resid":0,"rel":true,"src":"recommendation","added":0,"used":0} |
| T9 | PGN 25 -> Cancel: popup tertutup, hasil pendahuluan tidak diterbitkan, Export/Publish tetap terkunci, Save Input tetap aktif (V3) | LULUS | popup 321 ms; sesudah Batal: popup=false, gate=true, saveInputAktif=true, exportAktif=false, msg="Dibatalkan. Input dan hasil sebelumnya tidak diubah." |
| T7 | Progress 100% langsung selesai: hasil tampil <= 2 s setelah job DONE, polling berhenti | LULUS | jeda DONE->tampil per skenario (ms): [213,249,197,203,224]; polling sesudah hasil tampil: [0,0,0,0,0] |
| T8 | Tidak ada CMD/proses OS, tidak ada job gagal/menggantung, tidak ada JavaScript error | LULUS | jobs=7 (DONE), gagal=0, menggantung=0, scan=0 temuan, jsErr=0 |

## Runtime (ms)

```json
{
 "pgn25": {
  "modal": 50,
  "firstProgress": 762,
  "popup": 5594,
  "validated": 6607,
  "optionToFinal": 4640,
  "total": 11250
 },
 "pgn30": {
  "modal": 48,
  "firstProgress": 755,
  "total": 15365
 }
}
```
