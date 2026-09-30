# Smoke test kontrak HTTP pada PHP target

Biner PHP: `/usr/local/bin/php74`. Akar: `/home/claude/t5/v12freeze_reg`.

Inilah uji yang gagal pada XAMPP: server menjawab HTTP 200 dengan HTML `Parse error`, bukan JSON.
Karena itu yang diperiksa bukan sekadar adanya jawaban, melainkan seluruh kontraknya.

| id | pemeriksaan | hasil | bukti |
|---|---|---|---|
| T1a | Run: status HTTP sesuai kontrak | **PASS** | HTTP 200 (diharapkan 200/422) |
| T1b | Run: Content-Type application/json | **PASS** | Content-Type: application/json; charset=utf-8 |
| T1c | Run: body TIDAK mengandung HTML atau diagnostik PHP | **PASS** | bersih |
| T1d | Run: tidak ada keluaran sebelum JSON (byte pertama `{`) | **PASS** | byte pertama = '{' |
| T1e | Run: body dapat di-decode sebagai JSON | **PASS** | 35 key tingkat atas |
| T1f | Run: Simulation Data berisi 48 baris | **PASS** | 48 baris |
| T1g | Run: hasil membawa Run Status | **PASS** | CONVERGED  wall=12.4s |
| T2a | Save: status HTTP sesuai kontrak | **PASS** | HTTP 200 (diharapkan 200) |
| T2b | Save: Content-Type application/json | **PASS** | Content-Type: application/json; charset=utf-8 |
| T2c | Save: body TIDAK mengandung HTML atau diagnostik PHP | **PASS** | bersih |
| T2d | Save: tidak ada keluaran sebelum JSON (byte pertama `{`) | **PASS** | byte pertama = '{' |
| T2e | Save: body dapat di-decode sebagai JSON | **PASS** | 5 key tingkat atas |
| T2f | Save: respons menyatakan berhasil | **PASS** | ok=NULL result='ok' |
| T2g | Save: berkas di disk tetap JSON valid (tidak korup) | **PASS** | 30262 byte |
| T2h | Save: payload yang dimaksud BENAR-BENAR tersimpan | **PASS** | plan_remark tersimpan = 'SMOKE 160739' (dikirim SMOKE 160739) |
| T3a | Run dengan payload tidak sah: status HTTP sesuai kontrak | **PASS** | HTTP 400 (diharapkan 400/422/500) |
| T3b | Run dengan payload tidak sah: Content-Type application/json | **PASS** | Content-Type: application/json; charset=utf-8 |
| T3c | Run dengan payload tidak sah: body TIDAK mengandung HTML atau diagnostik PHP | **PASS** | bersih |
| T3d | Run dengan payload tidak sah: tidak ada keluaran sebelum JSON (byte pertama `{`) | **PASS** | byte pertama = '{' |
| T3e | Run dengan payload tidak sah: body dapat di-decode sebagai JSON | **PASS** | 6 key tingkat atas |
| T3f | Jalur gagal: membawa kode error yang stabil | **PASS** | code = SIMULATION_FAILED |
| T4a | Mode tidak dikenal: status HTTP sesuai kontrak | **PASS** | HTTP 405 (diharapkan 200/400/404/405/422) |
| T4b | Mode tidak dikenal: Content-Type application/json | **PASS** | Content-Type: application/json; charset=utf-8 |
| T4c | Mode tidak dikenal: body TIDAK mengandung HTML atau diagnostik PHP | **PASS** | bersih |
| T4d | Mode tidak dikenal: tidak ada keluaran sebelum JSON (byte pertama `{`) | **PASS** | byte pertama = '{' |
| T4e | Mode tidak dikenal: body dapat di-decode sebagai JSON | **PASS** | 6 key tingkat atas |
| T5a | job_list (GET ringan): status HTTP sesuai kontrak | **PASS** | HTTP 200 (diharapkan 200) |
| T5b | job_list (GET ringan): Content-Type application/json | **PASS** | Content-Type: application/json; charset=utf-8 |
| T5c | job_list (GET ringan): body TIDAK mengandung HTML atau diagnostik PHP | **PASS** | bersih |
| T5d | job_list (GET ringan): tidak ada keluaran sebelum JSON (byte pertama `{`) | **PASS** | byte pertama = '{' |
| T5e | job_list (GET ringan): body dapat di-decode sebagai JSON | **PASS** | 2 key tingkat atas |
| T6a | index.php: HTTP 200 | **PASS** | HTTP 200 |
| T6b | index.php: tidak ada markup diagnostik PHP di halaman | **PASS** | 552399 byte, 0 markup diagnostik |
| T6c | index.php: tombol Run ada di markup | **PASS** |  |

**34 / 34 PASS.**
