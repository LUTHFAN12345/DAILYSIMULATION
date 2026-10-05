# Test T1–T4 (tanpa full regression)

**Source beku:** SHA di SHA256SUMS.txt.

**Lingkungan:**
- Emulasi XAMPP: `php -S` × 6 di belakang proksi, satu pid, OPcache mati.
- Linux: Apache 2.4 + PHP-FPM 7.4, user www-data.
- Browser: Chromium (Playwright). Waktu diukur dari klik Run sampai status terminal tampil.

XAMPP Windows asli tidak tersedia di lingkungan uji.

## Ringkasan

| Test | Input | Hasil | Waktu | Status |
|---|---|---|---|---|
| **T1** emulasi XAMPP | `input_data.json` asli, Fastest - Default | `GAS_SHORTAGE_REQUIRED_START_NOT_FEASIBLE` (terminal, dengan bukti) | **2,34 s** | **PASS** |
| **T1** Linux | sama | sama | **1,66 s** | **PASS** |
| **T2** | basis feasible*, G6 → Unit Continuous Running | G6 row 2–48 > 0, hard PASS, FINAL, Save/Reload OK | 115,1 s | **PASS** |
| **T3** | basis feasible*, G6 → Stop Based on Simulation or Unit Continuous Running | dua family dievaluasi per unit; G6 STOP_SELECTED, G8 CONTINUOUS_SELECTED, bukti CP/HR | 181,5 s | **PASS** (rencana dikunci audit merit C4 bawaan) |
| **T4a** | input asli, gas dinaikkan (PGN 24 → 31, tanpa shortage), commitment sama | `REQUIRED_START_NOT_FEASIBLE` (row 1 tetap infeasible), pratinjau startup benar | **1,73 s** | **PASS** |
| **T4b** | basis feasible* tanpa shortage | = payload T3 (opsi Stop lama dipetakan UI ke token baru); startup G6 row 19, G8 row 2, S1 row 17; hard PASS | 181,5 s | jalur normal jalan; terkunci C4 bawaan |
| **T4 smoke** U31 / U30 (baseline ronde lalu) | PGN 31/30 + PEP 30 | fingerprint **f3acd04c7971acfb / 17b40201749fe55a** identik; CP 64,7205 / 64,8461; merit PASS, C4 0, STG 144/144 | lihat catatan waktu | **PASS** |
| **T4 smoke** Change Over Block 1-2 (WB09_3_B1B2_SIMSIM_COLD) | baseline CO | signature `e15860b82ee7` identik dengan mesin sebelumnya; CP 63,8636, HR 8197,81 (= CO_PARITY) | 3,6 s | **PASS** |
| Watchdog (tertarget) | input asli, `job_exec`/`job_help` dibuang (job tetap QUEUED) | pesan terminal "job tidak pernah mulai dalam 60 detik", dipicu ulang 1×, `job_cancel` 1×, 0 polling sesudahnya, Run aktif | 61,8 s | **PASS** |

\* **Basis feasible T2–T4b.** Input asli tidak mempunyai rencana valid (row 1), sehingga T2/T3 tidak dapat diuji di atasnya. Basis ini memakai commitment sama persis (G6/G8/S1 required start based on simulation, Last Data Stop, S1 Cold), dengan dua perubahan eksplisit:
- PEP 32 → 28: fixed flow row 1 = 32 ≤ 34,83, batas dari sertifikat;
- PGN 24 → 34: tanpa shortage.

Plafon Distillate tidak dikirim (field UI kosong).

## T1 — detail

**Form:** target `fast`, Gas Shortage Action `use_distillate`, field Distillate limit kosong. UI tidak memuat `distillate_user_limit_litres` dari file. Nilai 166.910,6 di file adalah sisa payload rerun lama, dan UI selalu memakai isi form.

**Acceptance:**
- hasil terminal muncul;
- ≤ 60 s;
- tidak ada polling 1.400 s (0 request polling dalam 6 s sesudah terminal);
- 1 job saja, tanpa popup bahan bakar;
- timer Fastest berhenti dan Run aktif kembali;
- 0 error JS.

**Isi keputusan:**

| Bagian | Isi |
|---|---|
| Row / unit | Row 1 (00:30). Unit online G1 maks 31 MW, G9 maks 108 MW. G6/G8/S1 (Last Data Stop) paling awal berbeban row 2. |
| Defisit | FLOW PGN maks 1,782 < 3,00 MMSCFD (kurang 1,218 MMSCFD = 1,2669 BBTUD-eq ≈ 4 MW unit gas) |
| Gas | kuota 70,88 BBTUD, kebutuhan ±77,0, kekurangan ±6,2 BBTUD |
| Distillate | menggantikan gas sehingga tidak menaikkan flow PGN. Kebutuhan tanpa plafon ±172.200 l (G3 dan G6 100%, satu-unit-dulu). Dengan plafon file 166.910,6 l (replay backend) cap_sufficient = false. |
| Blocker | `pgn_rt_min` row 1 (aturan hard `last_data_status` + Min PGN Flow) |
| Feasible bila | Fixed Flow Jababeka row 1 ≤ 34,827 MMSCFD (Manual Fixed Flow), atau Min PGN Flow ≤ 1,782 MMSCFD, atau unit gas lain Running pada Last Data |

**Startup G6/S1/G8 (pratinjau core run):**

| Unit | Mulai | Sequence (MW) |
|---|---|---|
| G6 | row 2 | 5, 15, 20 … (GTG kecil) |
| G8 | row 2 | 40, 40, 50, 60, 60, 70, lalu ≥ 65 (GTG besar, hubungan S3) |
| S1 Cold | row 9 | 11,2 … (feeder G6) |

**Jejak request:**

| Lingkungan | preflight | run | job_exec | job_help | job_poll | fast_ready |
|---|---|---|---|---|---|---|
| Emulasi XAMPP | 2 | 1 | 1 | 3 | 3 | 8 |
| Linux | 2 | 1 | 1 | 3 | 2 | 6 |

**Sebelum perbaikan (source sebelumnya, emulasi XAMPP):** dua job (recommendation lalu rerun Distillate), terminal 52,7 s dengan 0 row dan `HARD_VALIDATION_FAILED`, ditambah job `validated_options` QUEUED yatim. Job `use_distillate` langsung: 76 s (HTTP) / 102 s (CLI), termasuk 39,4 s tanpa progres sesudah `PIPELINE_END`.

## Catatan waktu (jujur)

U31 Fastest pada mesin yang sama dengan beban saat ini, diukur berselang (n = 3):

| Source | Waktu (s) | Median |
|---|---|---|
| Source sebelumnya | 17,0 / 14,6 / 15,2 | **15,2 s** |
| Freeze | 17,2 / 17,1 / 16,6 | **17,1 s** |

- Dispatch identik.
- Waktu core run sama (1,5–2,2 s, proses segar), sehingga selisihnya tidak dapat diatribusikan.
- Mesin uji saat ini lebih lambat daripada ronde lalu (ronde lalu U31 11,5 s untuk source yang sama).
- T2/T3 lama karena perbandingan dua family memakai pipeline penuh per unit (±30–52 s per family alternatif) dan rilis Fastest menunggu perbandingan itu.

## Uji tertarget lain

- `php -l` kelima PHP: OK.
- Varian diagnostik Manual Fixed Flow row 1 = 30 (core run): FLOW PGN row 1 2,142 → 3,028 (lantai terpenuhi).
- Basis PEP 28 / PGN 34, job penuh: mesin sebelumnya gagal `pgn_rt_min`; freeze hard PASS.

Full regression **tidak** dijalankan (sesuai instruksi).
