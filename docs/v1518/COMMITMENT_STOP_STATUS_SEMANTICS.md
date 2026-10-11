# COMMITMENT MODE vs STOP STATUS — semantik V15.18

Satu sumber: `pp_v1518_semantics(array &$input)` (worker_functions), idempoten, dipanggil pada salinan engine
(`pp_normalize_model_arrays`, `pp_run_simulation_once_raw`, `pp_normalize_copy`, validator). Payload operator TIDAK
dimutasi (hash payload stabil = UI selection).

## Enum internal
| Input | Enum | Arti |
|---|---|---|
| Commitment `continuous` | `CONTINUOUS_FULL_HORIZON` | load > 0 di 48 row; LDS = Running; `unit_cannot_stop`; Stop Status diabaikan. Tidak boleh dihentikan polish/decommit/gas repair/priority repair; bila fisik tidak mungkin → terminal infeasible dengan bukti (validator `commitment_continuous`) |
| Commitment `based_on_sim` | `START_BASED_ON_SIMULATION` | wajib start minimal sekali, waktu dipilih solver |
| Commitment `start_at` | `START_AT_REQUEST` | marker = Start At, beban 30 mnt kemudian |
| Stop `hold_to_end` (UI: Stop Status = Unit Continuous Running) | `HOLD_TO_END_AFTER_START` | OFF boleh sebelum start; setelah start ON sampai 00:00 |
| Stop `based_on_sim` | `OPTIMIZE_STOP_OR_HOLD_TO_END` | Family A (stop legal/ekonomis pertama) vs Family B (hold sampai 00:00), keduanya divalidasi penuh |
| Stop `based_on_sim_must` | `STOP_BASED_ON_SIMULATION` | wajib stop, waktu dipilih solver |
| Stop `stop_at` | `STOP_AT_REQUEST` | 0 MW mulai Stop At |

Data lama: `stop_mode {mode:'continuous'}` dibaca sebagai `HOLD_TO_END_AFTER_START`; `required_mode continuous` tetap full horizon.

## Comparator Family A vs B (`pp_v1518_family_evidence`, run.php)
Setelah review V8: untuk setiap GTG `OPTIMIZE_STOP_OR_HOLD_TO_END` yang start, family yang tidak diwakili hasil saat ini
didaratkan (`pp_tl_land_commitment`: commitment hasil + perubahan unit itu, window gas didaratkan lever engine, dinilai
terhadap input asli). Urutan: valid penuh → hard → constraint → CP → HR → starts (`pp_global_commitment_better`);
adopsi hanya bila valid & lebih baik. Evaluasi dibatasi jumlah (`PP_V1518_FAMILY_EVALS`=3), bukan jam. Bukti di
`info['Stop Family Evidence']`. Contoh T2: G6 A (stop, CP 71,6464) → B (hold) valid CP 71,5772 diadopsi; G9 B tetap
(A tidak valid: gas_quota/export_range/export_ramp).

## Block Required (§2)
- Required = wajib di-start dalam horizon sesuai mode; BUKAN full horizon.
- UI: unit primer blok (STG + GTG CC effective priority pertama) tanpa mode → Start Based On Simulation; mode eksplisit tidak ditimpa; unit Running kemarin dibiarkan.
- Engine: token `required` (lantai 1 unit/row) dibuang bila tidak ada unit blok yang diminta dan tidak ada unit full-horizon; STG/lead → `required_units` (`BLOCK_REQUIRED_START`).
- Hanya Commitment Continuous memaksa beban sejak 00:30.
- UI menampilkan "Ringkasan semantik sebelum Run" (blok Required, mode per unit, SC, notifikasi).

## STG → GTG lead (§6)
STG diminta (Simulation/Request/Continuous) tanpa GTG CC blok yang diminta → GTG CC effective priority pertama yang
available otomatis Required + (Continuous bila STG Continuous, else Start Based On Simulation) + Stop Status mengikuti
STG + CC. GTG SC/stop all day/unavailable dilewati (T7: G6 SC → G4). Arah sebaliknya tidak otomatis. UI dan engine
melakukan hal yang sama; UI menyimpan perubahan (terlihat, dinotifikasi, Save/Reload, Snapshot).
