# CHECKPOINT V15.22 — Commitment Mode / Stop Status / Block Required (+ runtime)

**Baseline:** `XAMPP_START_PRIORITY_RC_FULL_DEPLOYMENT.zip` (sha256 index.php 9731fc09…).
**Output:** `XAMPP_START_PRIORITY_RC_V1522_COMMITMENT_STOP.zip`.
**Branch:** `claude/gifted-ritchie-q0hfdp`.

## 1. Root cause (first corruption, terbukti)

| Tahap | ZIP (G1/G3/G5/G7/G9, hasilnya identik untuk kelima unit) | FIX |
|---|---|---|
| R0 record server | uld Stop, rm based_on_sim, sm hold_to_end, unit ∈ unit_cannot_stop | sama |
| **S1/S2 load UI (`index.php` `initUnitFlags`)** | **COMMIT_MODE=continuous, STOP_MODE='-', CANNOT_STOP=true, uld Running ← FIRST CORRUPTION** | simulation / continuous / false / Stop |
| S3 payload | rm continuous, sm null, cs true, uld Running | rm based_on_sim, sm hold_to_end, cs false, uld Stop |
| S4 Save (datastore) | korupsi tersimpan permanen | identik dengan input |
| S5/S6 reload → payload Run | tetap continuous | identik |
| S7/S8 worker + `pp_v1518_semantics` | memetakan apa adanya → CONTINUOUS_FULL_HORIZON | START_BASED_ON_SIMULATION + HOLD_TO_END_AFTER_START |
| S9–S13 kandidat start → dispatch | 48 row 00:30–00:00 | G9: kandidat 05:00 (CP 73,79) / **05:30 (CP 73,1475, dipilih)** / 06:00 (gagal EXPORT_RANGE_MIN row 17), hold sampai 00:00 |

- **Fungsi penyebab:** `initUnitFlags()` (index.php ±2698) mendahulukan `unit_cannot_stop.includes(u)` di atas `required_mode` eksplisit. `unit_cannot_stop` adalah field turunan dengan dua arti: (a) Unit Continuous Running dan (b) "tidak boleh stop setelah start" (ditulis UI V15.17 `e63dd91` dan normalisasi engine). Loader memperlakukan (b) sebagai (a). Lalu `renderUnitFlags` memaksa Last Data = Running, dan `applyCommitToModel` menulis `rm continuous`.
- **Mengapa semua unit:** loader berjalan per unit tanpa membedakan nama unit. Bukti: matriks UI 19 unit × 18 kombinasi; ZIP gagal 76 / 342 (L1, L2, L6, L7 untuk seluruh 19 unit).
- **Korupsi kedua (engine, shared):** unit *hold-only* (Stop Status Continuous tanpa Commitment) masuk `unit_cannot_stop`, lalu diperlakukan "online sepanjang hari" oleh:
  - `pri1Feeder` (realisasi STG Cannot-Stop);
  - `csSet`/`csStgFeeders` row 0;
  - `pp_block_in_service`;
  - commitBaseRow / g1cs / g5cs / contC;
  - forcing shaper dan forcing Tahap-1 di worker02.

  Akibatnya unit dipaksa start pada slot legal paling awal. Kasus A6: G4 dipaksa 01:00, CP 73,50 → setelah perbaikan 07:30, CP 73,22.
- **Bug ketiga:** mandatory stop (`pp_mandatory_stop_pass`, validator 8a-bis) menghitung OFF *sebelum* start sebagai "sudah stop". A3/B2 di ZIP tidak pernah stop tetapi tetap PASS.

## 2. Perbaikan generik (tanpa nama unit)
- **index.php `initUnitFlags`:**
  - `required_mode` eksplisit menang;
  - `unit_cannot_stop` hanya berarti Continuous pada record lama tanpa `required_mode` dan tanpa hold (`holdS` = stop_policy HOLD / stop_mode hold_to_end|continuous);
  - Stop Status Continuous dipulihkan dari `stop_mode` / `stop_policy` / `cs`.
- **weekly_ui.php:** mapping `continuous ↔ hold_to_end`.
- **worker_functions.php:**
  - `pp_v1522_hold_only($model,$u)` (predikat generik dari stop_policy, commitment, required, last data) dipakai di semua titik "online sepanjang hari": commitBaseRow, g1cs, g5cs, contC, shaper, pri1Feeder, csSet, `pp_block_in_service`;
  - validator 8a-bis hanya menghitung OFF sesudah start;
  - `pp_v1522_commitment_trace` mencatat per unit: raw / normalized / state_path / start-stop + alasan / interval / invariant I1–I6, I9, I12(warn), I13, ditambah bukti start tanpa permintaan (headroom unit prioritas lebih tinggi).
- **worker02.php:**
  - mandatory stop: OFF sesudah start; pruning eksak untuk start tetap; screening paralel deterministik;
  - Tahap-1 hold-only.
- **run.php:**
  - `Commitment Semantics Trace` ditambahkan pada output; trace FAIL → publish diblokir (`COMMITMENT_SEMANTICS_INVARIANT`);
  - optimasi paralel (lihat §4).
- **Audit hard-code:** baris yang diubah hanya memuat enumerasi armada penuh (pola lama) dan variabel lama `$g1cs`/`$g5cs`, tempat hanya predikat generik yang ditambahkan. Tidak ada kondisi `unit == X`.
- **Backward compatibility / stale state:**
  - record lama tanpa `required_mode` + cs tetap dimuat sebagai Continuous (L3);
  - record V15.17 yang sudah terkonversi menjadi `rm continuous` tidak dapat dibedakan dari Continuous asli. Operator perlu menyetel ulang unit itu satu kali, lalu Save.

## 3. Validasi
- **Regression engine (34 kasus, kode final):**
  - 33 PASS;
  - E8 (G8 Running + wajib stop) = konflik input yang ditolak dengan benar (12 kandidat stop melanggar window PGN Pipe / export row 1 → `NO_FEASIBLE_STOP`, result rejected);
  - kasus konflik E5/F3 juga ditolak dengan benar;
  - cakupan:
    - Sim + Hold / Sim + Allowed / Sim + Must;
    - Request + Hold / Request + Must;
    - Running + Hold / Running + Must;
    - Continuous;
    - hold-only;
    - Block Required ON/OFF (B1, B2, B3), token legacy;
    - tukar Unit Priority (G8↔G9, G1↔G2);
    - perpindahan konfigurasi ke G1, G3, G5, G7, G8, GE1;
    - LNG, distillate;
  - cek metamorfik state machine per unit lulus. Detail: `regression.md`, `regression_report.json`.
- **UI:** matriks 342/342 PASS (ZIP 266/342). Save → reload → reload stabil.
- **Row 1 (00:30):** `row1_proof.json`.
  - Hanya unit Last Data Running yang legal: B2 120, G8 108, G2 31, G6 31, S1/S2/S3. Semuanya sudah di maks. → export maks −107,31 MW < Range Min 15 (defisit 122,31).
  - G5, G9, G1, G4, G3 berstatus Stop dan paling cepat berbeban di row 2.
  - Engine **tidak** memaksa G9 dan mengeluarkan `TERMINAL_INFEASIBLE_EXPORT_ROW_1` (0,65 s) pada `input_data.json` maupun snapshot.
- **Determinisme:**
  - semua run server cold / warm / cached memberi sig dispatch identik ZIP = FIX;
  - CLI `data_md5` identik dengan env optimasi OFF/ON (`PP_V1522_FUEL_REUSE`, `PP_V1522_ROWCAP`);
  - smoke final identik.
  - **Belum diuji:** helper failure / timeout injection.

## 4. Runtime (mesin uji 4 core, 3 helper, median 3 run; `benchmark.md`)

| Payload | Jalur | ZIP | FIX | Δ |
|---|---|---|---|---|
| cs_bench (G9 Sim+Hold, LNG 6) | server cold | 20,74 | 18,04 (smoke 17,75) | −13% |
| cs_bench | server warm | 20,65 | 17,72 | −14% |
| cs_lng (snapshot + LNG rekomendasi 3,4191) | server cold | 10,22 | 9,62 (sesudah cache per-core ±8,7) | −6…−14% |
| cs_bench | CLI serial | 35,72 | 36,59 | ±0 |

Optimasi eksak:
- job hook spekulatif untuk pipeline basis;
- sub-tugas helper bertingkat + carry-over task list + beat helper;
- cache calc_fuel per-row;
- reuse bahan bakar di loop koreksi gas;
- cache cap per-row bbSplit / mkG12;
- cache uji kandidat per row0.

Semua bisa dimatikan lewat env:
- `PP_V1522_BASIS_PAR=0`
- `PP_V1522_NESTED_PAR=0`
- `PP_V1522_FUEL_REUSE=0`
- `PP_V1522_ROWCAP=0`

Jalur kritis tersisa adalah rantai core serial (gas window correction 4 core pada kandidat terberat, dan landing V8 3 evaluasi).

**Kasus berat (server):**
- A2s 39,6 → 36,9;
- F2 95,0 → 85,4;
- A3 42,5 → 54,2;
- B2 38,0 → 119,2.

Untuk A3/B2, hasil ZIP salah (G9 tidak pernah stop meski wajib stop). Pada FIX, setiap pipeline konsolidasi blok kini menjalankan pencarian mandatory-stop 12 kandidat (161 tugas).

## 5. Acceptance belum tercapai
1. Target runtime −30% end-to-end **belum** tercapai (aktual −6…−14%).
2. Runtime B2 119 s. Akarnya mandatory-stop × konsolidasi blok, dan belum dioptimasi.
3. Uji injeksi helper failure/timeout belum dijalankan.
4. Record user yang sudah terkonversi V15.17 harus disetel ulang manual.

## 6. Rollback / lanjut
- **Rollback:** pasang ulang ZIP baseline, atau matikan optimasi lewat env di atas. `PP_V1522_HOLD_ONLY=0`, `PP_V1522_STOP_AFTER_START=0`, `PP_V1522_TRACE_GATE=0` mengembalikan perilaku lama per bagian.
- **Lanjut (chat baru):**
  1. B2: berbagi hasil screening mandatory-stop antar kandidat konsolidasi.
  2. Injeksi helper failure.
  3. Runtime −30%.
- **Harness:** `harness/v1522/`
  - `cs_regress.py` (`CS_PAR=3 python3 cs_regress.py <src> /tmp/.../cs_bench.json <out>`);
  - `cs_ui_matrix.js`;
  - `cs_first_corruption*.{js,php}`;
  - `cs_srv_bench.js`;
  - `row1_proof.php`;
  - `gen_bench_table.py`.
