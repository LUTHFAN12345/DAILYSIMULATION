# V3 UI: auto-save, Save Input, satu pemilik, inkremental

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| B0 | Run dasar selesai FINAL OPTIMAL (exact penuh, basis inkremental) | LULUS | t=29190 ms cp=64.6214 msg=FINAL OPTIMAL — recompute inkremental mengganti hasil provisional. Commitment: G2 12→0 row, G5 0→12 row; dispatch berubah pada 18 dari 48 row; Total Cost 870,34 |
| C4 | Run menyimpan input_data.json lebih dulu (nilai slot baru sudah di disk saat respons Run tiba, sebelum hasil) | LULUS | {"resp_ms":71,"autosave_ms":4.8,"disk":1.6598,"ui":"1.6598"} |
| C5 | Identitas state yang tersimpan = yang dihitung (hash) | LULUS | {"saved":"28d6e5a4b861f48b","computed":"28d6e5a4b861f48b","owner":"economic_review-336f279af42a2f29794d"} |
| C1 | Save Input aktif dan berfungsi selama VALID PROVISIONAL (Export/Publish tetap terkunci) | LULUS | {"prov_ms":1715,"save":"ok","xls_locked":true} |
| S1 | Actual PGN satu slot: hasil valid pertama < 5 s, FINAL OPTIMAL tanpa menunggu menit | LULUS | valid_tampil=1715 ms kolam_terbaca=1697 ms job_valid_pertama=- final=4665 ms cp=64.6801 msg=FINAL OPTIMAL — recompute inkremental memilih rencana yang sama dengan hasil provisional.Done. Recompute inkremental selesai — Anda tidak perlu menjalankan ulang. · FINAL OPTIMAL — Target waktu Maximu |
| C7 | Klik ganda Run: tidak menulis dan tidak menghitung dua kali | LULUS | {"run_requests":1,"writes":1,"owners":["economic_review-336f279af42a2f29794d"],"jobs_before":2,"jobs_after":2,"skipped_identical":[false],"owner_reused":[true]} |
| C6 | Penyimpanan gagal: simulasi tidak dimulai, sebab ditampilkan, file lama utuh | LULUS | {"code":"INPUT_SAVE_FAILED","msg":"Run dibatalkan: input gagal disimpan ke input_data.json (tidak bisa membuka lock file input_data.json.lock (permission?)). File lama tetap utuh; simulasi tidak dijalankan.","file_intact":true} |
| C3 | Save Input aktif dan dapat diklik di atas modal selama job exact berjalan | LULUS | {"mask":true,"disabled":false,"topmost":true,"save":"ok","jobs":["DONE","DONE","RUNNING"]} |
| C2 | Save Input aktif dan dapat diklik saat popup Gas Shortage terbuka (Export/Publish terkunci) | LULUS | {"t_popup_ms":12628,"pop":true,"disabled":false,"topmost":true,"save":"ok","xls_locked":true} |
| J1 | tidak ada JavaScript error | LULUS |  |

Hasil: **10/10**
