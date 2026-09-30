# UJI UNIT KUNCI EKSEKUSI (PENGGANTI PID)

Hasil: **12/12**

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| L1 | job baru QUEUED, tanpa pid, tanpa proses | LULUS | economic_review-f0d66e9dce514b76ba99 |
| L2 | job QUEUED dipakai ulang (bukan dibuat ulang) | LULUS |  |
| L3 | pemilik pertama mendapat kunci eksekusi | LULUS |  |
| L4 | handle kedua TIDAK mendapat kunci (satu pemilik) | LULUS |  |
| L5 | kunci dipegang -> pp_job_is_live=true walau denyut basi 2 jam | LULUS |  |
| L6 | penyapu tidak menyentuh job yang kuncinya dipegang | LULUS |  |
| L12 | pembersihan global __pp_* oleh mesin hitung TIDAK melepas kunci eksekusi | LULUS |  |
| L7 | Run ulang saat berjalan -> dipakai ulang | LULUS |  |
| L8 | Run setelah batal saat perhitungan masih berjalan -> pembatalan dicabut, dipakai ulang | LULUS |  |
| L9 | kunci lepas -> job RUNNING dinilai tidak hidup | LULUS |  |
| L10 | penyapu menandai FAILED dengan sebab JOB_REQUEST_BERHENTI | LULUS | FAILED JOB_REQUEST_BERHENTI |
| L11 | Run setelah gagal -> job baru QUEUED yang bersih | LULUS |  |
