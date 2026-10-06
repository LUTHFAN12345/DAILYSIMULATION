# Performa T6 — klik Run sampai Simulation Data 48 row

## Definisi pengukuran

T6 diukur oleh `tools/ap_ui.js`, yang menjalankan UI asli di Chromium headless.

- **Mulai (t0):** klik **Run**.
- **Selesai:** tabel Simulation Data menampilkan 48 row hasil akhir.

Rentang ini mencakup seluruh alur:
1. job 1 (sertifikat + koreksi PGN otomatis);
2. `pgnAutoApply`;
3. job 2 (Fastest: klaim kandidat pertama, review, audit, gerbang fully valid, Stop-or-Continuous);
4. render tabel.

**Lingkungan.**
- **XAMPP-semantics:** server PHP 7.4 dengan 6 worker.
- **Linux:** Apache 2.4 + PHP-FPM 7.4 (www-data).

Keduanya berjalan di mesin yang sama dan memakai source yang sama (SHA identik).

**Case T6:** case A, yaitu fixture 5 Okt, Fix SR 0, constraint PGN pada 00:30, target *Fastest - Default*.

## Sebelum (source 72168ee)

| Platform | Rerun sesudah Apply → 48 row | Popup | Hasil |
|---|---|---|---|
| XAMPP | **236,761 s** | 2,779 s + klik Apply | FINAL OPTIMAL, 100 kandidat diperiksa, CP 83,8124 |
| Linux | **233,396 s** | 2,139 s + klik Apply | sama |

**Penyebab.** Stop Status *Stop Based on Simulation or Unit Continuous Running* (G4, G8) memblokir rilis Fastest. Job menunggu urutan berikut:
1. pipeline exact (≈ 81,5 s);
2. evaluasi exact family alternatif G4 (≈ 71,5 s);
3. evaluasi exact family alternatif G8 (≈ 80,2 s).

Ketiganya berjalan berurutan dan tidak berhenti pada kandidat fully valid pertama.

## Sesudah (freeze final)

| Run | Source | Platform | T6 (s) | Koreksi otomatis diterapkan (s) | Finalisasi Fastest (s) | Hasil |
|---|---|---|---|---|---|---|
| 1 | freeze-1 | XAMPP | 36,838 | 3,502 | 31,985 | FASTEST VALID PLAN, CP 90,0739 |
| 2 | freeze-1 | Linux | 32,855 | 2,935 | 28,288 | identik |
| 3 | **freeze final** | XAMPP | 39,007 | 2,864 | 34,541 | identik |
| 4 | **freeze final** | Linux | 49,508 | 2,854 | 44,982 | identik (dijalankan tepat sesudah A2; pembantu job A2 masih menyelesaikan tugas samping) |
| 5 | **freeze final** | Linux | 34,804 | 3,106 | 30,168 | identik (run tersendiri) |

- **Min / max:** 32,855 s / 49,508 s, dari 5 run. Semuanya **< 60 s**.
- **Median:** 36,838 s.
- **Penurunan** dari 233–237 s: sekitar 5–7×.

Freeze-1 dan freeze final hanya berbeda pada satu cabang Stop-or-Continuous Fastest (lihat *Perbaikan terakhir*). Cabang itu tidak dilewati case A (G4/G8: `replaced_main_plan = false`), sehingga run 1–2 sah sebagai sampel T6. Hasil numerik kelima run identik.

**Rincian run 5** (Linux, freeze final; `jobs/<job>/v12_fast_ready.json`):

| Tahap | Waktu |
|---|---|
| Klik Run → job 1 selesai + koreksi otomatis diterapkan | ≈ 3,5 s |
| Job 2: klaim kandidat valid pertama | 0,76 s sesudah job mulai |
| Review Unit Priority + redistribusi C4 (kandidat yang diklaim) | 30,10 s |
| Audit C1–C4 / LLF / provenance | 0,04 s |
| Gerbang fully valid + bukti STG | 0,03 s |
| Stop-or-Continuous (family alternatif sudah dihitung paralel oleh pembantu) | 0,03 s |
| Rilis (FASTEST_RELEASE_READY) | 30,93 s sesudah job mulai |
| Render 48 row | < 0,5 s |

## Perubahan yang menurunkan T6

1. **Stop-or-Continuous tidak lagi memblokir Fastest.**
   - Kandidat valid pertama diklaim langsung.
   - Family alternatif G4/G8 diterbitkan sebagai tugas samping (`soc`) dan dihitung paralel oleh 3 pembantu job.
   - Kedua family dibandingkan pada tingkat yang sama: kandidat hard-valid pertama masing-masing (aturan pilih CP terendah, pita 0,2 % → Heat Rate).
   - Hook kandidat valid pertama (`ppSocFirstValid`) dipasang juga pada jalur iso/compute, sehingga pipeline family alternatif berhenti pada kandidat valid pertamanya.
2. **Review alternatif spekulatif hanya bila diperlukan.** Pembantu melanjutkan review + gerbang family alternatif hanya bila family itu sudah unggul atas kandidat utama (`main_cmp`), agar CPU tidak diperebutkan.
3. **Klaim gagal tidak membuat job baru.** Bila kandidat yang diklaim tidak lolos gerbang fully valid, klaim dilepas (`fastest_claimed = false`) dan job yang sama melanjutkan ke kandidat berikutnya. UI tetap menempel pada job tersebut, tanpa cancel dan tanpa job baru.
4. **Fastest berhenti pada first fully valid.**
   - Label: `FASTEST VALID PLAN — kandidat fully valid pertama (Global optimum proven: NO)`, gerbang `FASTEST_FIRST_FULLY_VALID`.
   - Sesudah rilis, UI membatalkan job (1 request `job_cancel`). Tidak ada global exact review sesudah first fully valid tersedia.
5. **Pembatalan bersih.** `pp_soc_cancel_poll` + `ppSocCancelJob` membuat evaluasi family alternatif yang masih berjalan berhenti begitu job dibatalkan. Tanpa ini, worker tersita sisa evaluasi run sebelumnya, dan polling run berikutnya tertahan (ditemukan pada matriks XAMPP B; diperbaiki).

## Perbaikan terakhir (freeze final): tanpa fallback exact pada Stop-or-Continuous Fastest

**Kegagalan yang terbukti.** Pada matriks freeze-1, case A2 (constraint 00:30–02:00) PASS di kedua platform, tetapi CP-nya berbeda:

| Run | CP |
|---|---|
| XAMPP | 84,9942 |
| Linux | 84,9175 |
| Linux, diulang | 84,9156 |

Jejak job (`reports/a2inv/`):
1. Untuk G4, family alternatif STOP unggul pada tingkat kandidat hard-valid pertama (87,64 < 90,04).
2. Versi review alternatif itu tidak lolos gerbang fully valid.
3. Kode lalu jatuh ke pipeline exact penuh family alternatif. Review di pipeline itu terpotong batas waktu (`BATAS_WAKTU_REVIEW`, 10 kandidat tidak dievaluasi), sehingga hasilnya bergantung kecepatan mesin.

Fallback ini juga berarti menjalankan exact sesudah first fully valid tersedia.

**Perbaikan** (run.php `pp_bs_stop_or_continuous`). Pada mode Fastest, bila versi review family alternatif tidak fully valid, rencana utama yang sudah fully valid dipertahankan. Pipeline exact tidak dijalankan, dan alasannya dicatat di `Stop Or Continuous Decision[].rule`.

| A2 | XAMPP | Linux |
|---|---|---|
| CP sesudah perbaikan | 85,3183 | 85,3183 (identik) |
| Waktu sebelum → sesudah | 172,4 s → 151,4 s | 229,9 s → 114,5 s |

CP 85,3183 sama dengan replay A2 tanpa Stop-or-Continuous (deterministik di kedua platform).

## Waktu case lain (freeze final, klik Run → 48 row)

| Case | XAMPP | Linux | Catatan |
|---|---|---|---|
| C — T1 Fix SR 10 (Fastest) | 50,4 s | 52,5 s | freeze-1: 57,1 / 52,0 s |
| D — T2/T3 Follow PV (Fastest) | 49,3 s | 57,9 s | freeze-1: 55,4 / 55,8 s |
| A2 — varian 00:30–02:00 (Fastest) | 151,4 s | 114,5 s | varian berat: review kandidat yang diklaim ≈ 77–90 s; 46 temuan C4, FAIL 0 |
| B — redistribusi tidak feasible | 2,2 s | 2,2 s | terminal |
| E — Maximum Review + Report | 80,5 s | 82,8 s | bukan target Fastest |
| CM — T1 Maximum Review | 79,0 s | 87,9 s | bukan target Fastest |

## Keterbatasan

- **Fastest ≠ optimum global.** Fastest merilis kandidat fully valid pertama, sesuai permintaan.
  - Pada case A, CP Fastest adalah 90,0739.
  - Source lama (exact 236 s) menghasilkan rencana lain dengan CP 83,8124: G4 start row 20 dengan Distillate 118.946 L, kelas rencana yang berbeda.
  - Untuk mencari optimum, gunakan target *Maximum Review*. Pada T1/T2 (CM/E), Maximum Review menghasilkan CP yang sama dengan Fastest (89,9882 / 90,0877). Untuk case A, Maximum Review tidak diukur ulang pada ronde ini.
- **Batas pengaman review 90 s.** Review kandidat yang diklaim pada jalur Fastest punya batas pengaman 90 s. Pada replay A2 di mesin uji (XAMPP dan Linux), review selesai dalam ±77 s (`truncated = false`), dan hasilnya identik. Pada mesin yang jauh lebih lambat, review dapat terpotong, dan hal itu tercatat di audit (`V8 Priority Review.truncated`).
- **Varian waktu wajar.** Waktu dapat bervariasi beberapa detik antar-run, terutama bila run dimulai saat pembantu job sebelumnya masih menyelesaikan tugas samping (run 4). Hasil numeriknya tidak berubah.
