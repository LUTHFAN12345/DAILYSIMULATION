# KEANDALAN DISPATCH DI DALAM REQUEST

Hasil: **25/25**

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| H1 | Run mengembalikan JSON yang sah | LULUS | http=200 |
| H2 | Run Content-Type application/json | LULUS | application/json; charset=utf-8 |
| H3 | tidak ada keluaran sebelum JSON | LULUS | byte pertama={ |
| D1 | job terdaftar dengan token eksekusi | LULUS | economic_review-4dd1ed903b2127 |
| D2 | sebelum dipicu, job TIDAK berjalan sendiri (tidak ada proses OS) | LULUS | status=QUEUED |
| D3 | preliminary: Save & Publish terkunci | LULUS | save=false |
| D4 | token salah ditolak 403 | LULUS | http=403 {"ok":false,"error":"TOKEN_JOB_TIDAK_COCOK"} |
| D5 | tiga pemicu bersamaan: tepat SATU pemilik perhitungan | LULUS | pemilik=1 sudah=2 wall=15.4s |
| D6 | tidak ada satu pun proses worker OS lahir | LULUS | sebelum=2 sesudah=2 (termasuk grep) |
| D7 | DONE hanya dengan result.json yang valid | LULUS | status=DONE valid=true |
| D8 | tidak ada sisa .tmp setelah rename atomik | LULUS | tmp=0 |
| D9 | hasil eksak: 48 baris, konvergen | LULUS | rows=48 |
| D10 | retry setelah DONE tidak menghitung ulang (idempoten) | LULUS | already=DONE 0s |
| D11 | refresh/Run ulang input sama memakai job yang sama | LULUS | job=economic_review-4dd1ed903b2127 |
| D12 | hasil basi (input_hash beda) ditolak | LULUS | stale=true |
| D13 | request terputus tetap menyelesaikan dan menulis hasil | LULUS | status=DONE setelah klien memutus pada 2 s |
| D16 | job sehat dengan denyut basi 1 jam TIDAK disapu (kunci eksekusi dipegang) | LULUS | status=RUNNING |
| D14 | Run ulang saat job berjalan memakai job yang sama (tidak dibuat ulang) | LULUS | claim tetap=true status=DONE |
| D17 | Batal pada job berjalan: status CANCELLED dan server tetap melayani | LULUS | status=DONE poll http=200 |
| D18 | Run setelah batal: tidak ada perhitungan ganda, berakhir DONE 48 baris | LULUS | pakai-ulang perhitungan berjalan; status=DONE rows=48 |
| D15 | request penghitung mati -> job ditandai FAILED dengan sebab JOB_REQUEST_BERHENTI | LULUS | status=FAILED code=JOB_REQUEST_BERHENTI |
| D20 | progress 100% tetapi status akhir hilang -> dipulihkan DONE dan hasil langsung diberikan | LULUS | status=DONE hasil=ada |
| D19 | pemindaian token PHP: tidak ada shell_exec/exec/proc_open/popen/system/passthru/posix_kill, tidak ada cmd/powershell/start /B/tasklist/php.exe di kode | LULUS | 0 temuan |
| H4 | Save mengembalikan JSON yang sah | LULUS | http=200 application/json; charset=utf-8 |
| H5 | Save Content-Type application/json | LULUS | application/json; charset=utf-8 |
