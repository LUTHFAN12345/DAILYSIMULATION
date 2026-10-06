# Minimal performance patch (di atas 0a2cedf)

Tidak ada source lama yang disalin. Tidak ada validator, aturan C4 (ambang 0,5 MW, kategori, `rows_sig`), aturan pilih Stop-or-Continuous, atau expected test yang diubah.

| # | Berkas / fungsi | Perubahan | Alasan (ROOT_CAUSE) |
|---|---|---|---|
| 1 | run.php `pp_v12_fast_release_try`, `pp_bs_stop_or_continuous` | **Review malas.** Keputusan Stop-or-Continuous Fastest (tingkat kandidat hard-valid pertama, aturan tetap) dijalankan **sebelum** review. Rencana utama direview hanya bila family utama menang, atau bila versi review alternatif tidak fully valid; sesudah itu alurnya diulang persis seperti 0a2cedf. Bila alternatif menang, versi review-nya yang dirilis (sama dengan 0a2cedf). | 1 |
| 2 | run.php `pp_job_abort_is_terminal` + `pp_v12_fz_compute`, `pp_v10_fz`, `pp_v8_priority_review` | Pembatalan tingkat job diteruskan, tidak ditelan. `SOC_FIRST_VALID` (sinyal internal) tetap ditangani seperti sebelumnya. | 2 |
| 3 | worker02.php `pp_side_*`, publish/run fz/rv/vz/land, `pp_v12_side_drain` | Antrean per penerbit (`v12_side_{fz,rv,vz}.<id>.json`); penerbit hanya mengganti tugas miliknya. Kelas tugas `claim` > `soc` > `exact`; sesudah klaim, tugas `exact` tidak dikerjakan. | 3 |
| 4 | worker02.php run_vz / run_rv / run_fz | Isi tugas dibaca dan divalidasi dulu, baru penanda selesai dibuat. | 4 |
| 5 | run.php `pp_v12_c4_rows_search_par` + `pp_v12_c4_rowwise` | Pencarian row C4 bergiliran: satu langkah tiap row aktif per putaran, satu putaran diterbitkan sebagai tugas `fz`. Titik evaluasi per row identik dengan `pp_v12_c4_row_search` (min(M; 0,5) → M → bisection 0,5 MW, maks. 6). | 5 |
| 6 | run.php `pp_v12_c4_rowwise` (transfer legal sisa) | Uji cepat paralel langkah pertama tiap temuan; hanya yang valid dicari penuh. Bila dispatch berubah, langkah pertama diuji ulang. Maks. 8 lintasan; berhenti bila tidak ada MW legal yang berpindah. | 5 |
| 7 | run.php `pp_v12_c4_rowwise` (bukti temuan sisa) | Bukti langsung + pendaratan seluruh temuan sisa diterbitkan sekaligus (saling bebas pada dispatch akhir yang sama). | 5 |
| 8 | run.php `pp_bs_soc_get` | Pemilik yang menunggu hasil family alternatif ikut mengerjakan tugas samping. | 1, 3 |
| 9 | run.php `pp_tl_owner_drain` + `pp_tl_abort_poll` | Pemilik yang dijeda (klaim dipegang proses lain) mengerjakan tugas samping finalisasi. Global engine pemilik disimpan dan dipulihkan persis (pola `pp_v12_side_drain_nested`). Tugas samping yang sedang dikerjakan pemilik tidak ikut dijeda. | 7 |
| 10 | run.php `pp_job_progress`, `fast_ready`; index.php `fastestPoll` | Sesudah klaim, label job hanya mengikuti fase `FASTEST_*` / `STOP_OR_CONTINUOUS_*`. `fast_ready` membawa `step`/`percent`, dan UI menampilkannya di status utama ("Fastest - Default: finalisasi merit c4 75%"). Persen kini monoton: 50 → 55 → 60 → 75 → 85 → 90. | 6 |
| 11 | run.php `pp_v12_fast_release_try` | Batas waktu review rencana utama = batas review family alternatif (600 s → efektif batas dinding review 240 s). Batas jumlah tetap yang menentukan. | 8 |
| 12 | run.php, worker02.php | Instrumentasi diagnosa 0a2cedf dihapus: `timeline_s`, `polish_trace`, `Unit Priority Polish.trace`, `side_log.txt`, penanda waktu. | §10 |

**Yang sengaja tidak dilakukan.**
- **Memangkas polish.** Terbukti memperburuk CP (+0,33) atau membuat C4 tidak tuntas (48 FAIL).
- **Cap polish satu putaran.** Ditolak di hotfix.
- **Menambah jumlah pembantu.** Mesin 4 core; pemilik dan web server tetap mendapat kapasitas.

**Determinisme.** Seluruh hasil tugas samping berkunci input lengkap (dispatch tersusun + konteks gas/SR/PV), sehingga bit-identik siapa pun yang menghitung. Urutan dan penerimaan tetap milik pemilik. Hasil terbukti identik dengan 0a2cedf: CP/HR/signature R1, R2, R3 ON dan smoke U31/CO.
