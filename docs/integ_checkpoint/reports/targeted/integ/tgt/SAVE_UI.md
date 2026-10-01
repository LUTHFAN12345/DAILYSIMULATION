# V3 UI: auto-save, Save Input, satu pemilik, inkremental

| id | pemeriksaan | hasil | rincian |
|---|---|---|---|
| B0 | Run dasar selesai FINAL OPTIMAL (exact penuh, basis inkremental) | LULUS | t=0 ms cp=64.6657 msg=FINAL OPTIMAL (basis final tersimpan dari run sebelumnya) |
| C4 | Run menyimpan input_data.json lebih dulu (nilai slot baru sudah di disk saat respons Run tiba, sebelum hasil) | LULUS | {"resp_ms":308,"autosave_ms":1.9,"disk":1.6598,"ui":"1.6598"} |
| C5 | Identitas state yang tersimpan = yang dihitung (hash) | LULUS | {"saved":"28d6e5a4b861f48b","computed":"28d6e5a4b861f48b","owner":"economic_review-0a741d03f3c0bccd65d4"} |
| C1 | Save Input aktif dan berfungsi selama VALID PROVISIONAL (Export/Publish tetap terkunci) | LULUS | {"prov_ms":2492,"save":"ok","xls_locked":true} |
| S1 | Actual PGN satu slot: hasil valid pertama < 5 s, FINAL OPTIMAL tanpa menunggu menit | LULUS | valid_tampil=2492 ms kolam_terbaca=2488 ms job_valid_pertama=- final=5586 ms cp=64.6801 msg=FINAL OPTIMAL — recompute inkremental memilih rencana yang sama dengan hasil provisional.Done. Recompute inkremental selesai — Anda tidak perlu menjalankan ulang. · FINAL OPTIMAL — Target waktu Maximu |
| C7 | Klik ganda Run: tidak menulis dan tidak menghitung dua kali | LULUS | {"run_requests":1,"writes":1,"owners":["economic_review-0a741d03f3c0bccd65d4"],"jobs_before":1,"jobs_after":1,"skipped_identical":[false],"owner_reused":[true]} |
| C6 | Penyimpanan gagal: simulasi tidak dimulai, sebab ditampilkan, file lama utuh | LULUS | {"code":"INPUT_SAVE_FAILED","msg":"Run dibatalkan: input gagal disimpan ke input_data.json (tidak bisa membuka lock file input_data.json.lock (permission?)). File lama tetap utuh; simulasi tidak dijalankan.","file_intact":true} |
| C3 | Save Input aktif dan dapat diklik di atas modal selama job exact berjalan | LULUS | {"mask":true,"disabled":false,"topmost":true,"save":"ok","jobs":["DONE","RUNNING"]} |
| C2 | Save Input aktif dan dapat diklik saat popup Gas Shortage terbuka (Export/Publish terkunci) | LULUS | {"t_popup_ms":12558,"pop":true,"disabled":false,"topmost":true,"save":"ok","xls_locked":true} |
| J1 | tidak ada JavaScript error | LULUS |  |

Hasil: **10/10**
