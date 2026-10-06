# Root cause — Fastest PGN30/PEP34 lambat (0a2cedf: R1 88,5 s, R2 107,8 s, R3 ON 166,1 s)

**Metode.**
- Diukur pada source 0a2cedf dengan instrumentasi sementara di jalur UI yang sama.
- Instrumentasi: timestamp tahap finalisasi, jejak polish, dan log aktivitas pembantu per proses (pid, jenis tugas, mulai/selesai).
- Instrumentasi itu **sudah dihapus** dari paket final. Hasil engine tidak berubah oleh instrumentasi (CP 95,8967, signature `708ea39169b5` di setiap run).

## Rincian 0a2cedf (R1, detik relatif klaim kandidat pertama)

| Tahap | Waktu |
|---|---|
| Review r1/r2 | 0 → 4 |
| Polish Unit Priority rencana utama (6 putaran, 131 evaluasi) | 4 → 41–45 |
| Sweep + laporan | → 48 |
| C4 rencana utama | 48 → 93: search row 25 s, bukti 15,9 s |
| Gerbang + Stop-or-Continuous | ±0,1 s |

## Penyebab terbukti

1. **Review rencana utama dikerjakan lalu dibuang (penyebab terbesar).**
   - Keputusan Stop-or-Continuous Fastest membandingkan family pada tingkat kandidat hard-valid pertama.
   - Family alternatif G4 CONTINUOUS sudah unggul pada detik 1,4 (97,6576 vs 99,0827), dan versi review-nya (pembantu, 1,4 → 39,2 s) yang dirilis.
   - Review rencana utama (±76 s CPU pemilik) tidak dipakai, padahal berebut CPU dengan review alternatif.

2. **Pembatalan job ditelan sebagai "evaluasi gagal".**
   - Sesudah klaim, pembantu yang sedang menjalankan pekerjaan exact menerima `PpJobAborted` di setiap core run.
   - `pp_v12_fz_compute`, `pp_v10_fz`, dan `pp_v8_priority_review` menangkapnya sebagai evaluasi gagal lalu melanjutkan evaluasi berikutnya.
   - Akibatnya pembantu menghabiskan 0–16 s pada pekerjaan yang sudah usang.

3. **Antrean tugas samping tunggal saling ditimpa.**
   - `v12_side_fz.json`, `v12_side_rv.json`, dan `v12_side_vz.json` adalah satu berkas per job yang diganti oleh setiap penerbit.
   - Review spekulatif alternatif (pembantu) dan finalisasi pemilik saling menimpa: terukur 56 tugas tertunda hilang, dan pemilik menghitung sendiri.
   - Tidak ada prioritas: tugas exact usang setara dengan tugas jalur kritis.

4. **Tugas ditandai selesai sebelum isinya dibaca.** `run_vz` dan `run_rv` membuat penanda `done` dulu, lalu membaca berkas tugas. Bila pembacaan gagal (berkas sedang diganti), tugas hilang dan tidak dikerjakan siapa pun.

5. **Pencarian row C4 berurutan di pemilik.**
   - `pp_v12_c4_rowwise` menerbitkan seluruh row sebagai tugas `c4row` (satu tugas = seluruh pencarian biner row itu), lalu pemilik mencari row demi row.
   - Pembantu dan pemilik mengerjakan row yang sama secara berbeda waktu, sehingga terukur pemilik menghitung ±90 evaluasi berurutan.
   - Transfer legal sisa dan bukti temuan sisa juga berurutan tanpa penerbitan.

6. **Label progres tidak bergerak di UI.**
   - Sesudah klaim, UI berhenti memanggil `job_poll` dan hanya memanggil `fast_ready`, yang tidak membawa fase.
   - Pipeline exact yang usang juga menimpa label (mis. "mandatory stop pass 14%").
   - Hasilnya, sepanjang finalisasi layar tetap menampilkan "entry 3%" atau "gas window correction 40%". Langkah `FASTEST_FINALISASI_*` dari hotfix 0a2cedf tidak pernah tampil di UI.

7. **Pemilik tidur saat klaim dipegang pembantu.**
   - Bila kandidat pertama diklaim pembantu (acak), pemilik job exact dijeda dan hanya `usleep`.
   - Finalisasi kehilangan satu pekerja: R2 41,4 s vs 27,6 s.

8. **Batas waktu review tidak simetris.** Review rencana utama dibatasi 90 s, sedangkan review family alternatif 600 s (efektif dinding 240 s). Pada mesin lambat hanya review rencana utama yang terpotong, sehingga hasil bergantung kecepatan mesin.

## Mengapa R3 (Follow PV ON) 166 s di 0a2cedf

- Pada R3 rencana utama juga kalah dari family alternatif G4.
- Waktu 166 s berasal dari review + C4 multi-lintasan rencana utama yang dibuang (penyebab 1), ditambah antrean yang saling menimpa.
- C4 family terpilih pada R3 ON kini sama ringannya dengan R1 (21 → 0 FAIL, 38 evaluasi row). SR per row tidak memicu recheck C4 penuh.

## Bukan penyebab (diperiksa)

| Dugaan | Bukti |
|---|---|
| Audit C4 48 row mahal | `pp_v12_merit_audit` ±0,005 s; biaya C4 ada di evaluasi counterfactual, bukan audit |
| Kandidat identik dievaluasi berulang | cache vz/cs berkunci input; polish 130 hash unik dari 131 |
| Payload/CSV | diff struktural 0 path; R1 = R2 |
| Validator lama lebih efisien | source lama tidak punya validator yang sama (AB_OLD_GOOD_VS_CURRENT.md) |

## Artefak uji yang ditemukan (bukan bug produk)

- Harness lama menghapus `jobs/` sekitar 1 s sesudah hasil tampil. Pemilik job yang belum sempat membaca pembatalan kehilangan job-nya, sehingga terus menghitung dan mengganggu kasus berikutnya (variasi 30–43 s).
- Harness kini menunggu seluruh worker PHP diam sebelum kasus berikutnya. Di produk, folder job tidak dihapus saat berjalan; terukur seluruh worker diam ≤ 2 s sesudah hasil tampil.
