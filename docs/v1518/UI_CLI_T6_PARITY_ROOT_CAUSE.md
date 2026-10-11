# UI_CLI_T6_PARITY_ROOT_CAUSE — UI CP 71.7732 vs. CLI CP 71.9896

## 1. Field-by-field trace (raw payload)
UI payload = the browser `POST mode=run` body / job `input.json`; CLI = fixture `T6.json`.

| Field | CLI fixture | UI (before fix) | Note |
|---|---|---|---|
| `required_units` | `[g9, s1]` | `[g6, g9, s1]` | UI added G6 |
| `required_mode.g6` | – | `based_on_sim` | UI |
| `stop_mode.g6` | – | **absent** | ← root cause |
| `stop_mode.s1` | `hold_to_end` | `hold_to_end` | same |
| `sc_cc` | absent (default CC) | all CC explicit | same content |
| `unit_fix_load` / `unit_skip_load` | `{}` | `[]` | same content |
| `time_budget_*`, `__core_run_budget`, `change_over_*` budget, `_request_id/_context/_state_revision` | – | present | request/transport metadata (not semantics) |
| configured priority, block priority, Commitment Mode of other units, Stop Status of other units, IE, Actual Gas, Fixed Flow, fuel action, MIN/MAX | identical | identical | – |

After engine normalization (`pp_v1518_semantics`):

| | CLI | UI (before fix) |
|---|---|---|
| G6 commitment | START_BASED_ON_SIMULATION | START_BASED_ON_SIMULATION |
| **G6 stop policy** | **HOLD_TO_END_AFTER_START** (§6: lead follows STG S1) | **STOP_BASED_ON_SIMULATION** |
| auto-semantics | `STG_S1_LEAD_GTG_CC` (g6) | – (G6 was already set explicitly by the UI) |
| Result | CP 71.9896 / HR 8459.56 / fp `153909b1…` | CP 71.7732 / HR 8426.87 |

## 2. Root cause: option B
The UI wrote **derived state** into the payload in a different order from the engine, and that changed the result.

- The T6 block (G6, G4, G3, S1) is Required and S1 is requested (Start Based On Simulation + Stop Status Unit
  Continuous Running).
- **UI:** `syncBlockRequiredToCommitment` (§2 Block Required) ran **first**. The block's primary GTG (G6) had no mode,
  so it was set to Start Based On Simulation with "Stop Status unchanged". Then `semStgAutoFollow` (§6 STG → lead GTG)
  skipped the block, because a GTG CC already had a mode. G6 therefore lost the Stop Status "follows STG" behaviour.
- **Engine:** `pp_v1518_semantics` runs the STG lead rule (§6) first. G6 becomes Required with mode following S1 **and
  Stop Status following S1 (hold to end)**.
- The agreed semantics (COMMITMENT_STOP_STATUS_SEMANTICS.md §6, test T6 `G6_auto_lead` + `G6_hold_policy`) =
  engine/CLI. CLI 71.9896 is the correct value. UI 71.7732 was cheaper because G6 was allowed to stop before 00:00,
  which violates the Stop Status of the requested STG.

## 3. Fix
1. **UI (`index.php`, `syncBlockRequiredToCommitment`).** A block's primary GTG whose STG is requested and which has
   no mode yet is not set by §2. It is handed to `semStgAutoFollow` (§6), which picks the same GTG (the first available
   effective-priority GTG CC) and sets mode **and** Stop Status to follow the STG. This is the same order as the
   engine. An explicit operator mode is still never overwritten.
2. **One canonical normalization function** (`pp_v1518_semantics` → `pp_v1518_canonical_order`, `worker_functions.php`),
   used the same way by browser, CLI, XAMPP and Linux because every path enters the same job runner:
   - unit sets (`required_units`, `unit_cannot_stop`) and per-unit maps (`required_mode`, `stop_mode`, `stop_policy`,
     `commitment_semantics`) use canonical unit order;
   - `sc_cc` is explicit for eligible units;
   - canonical Continuous enum = `required_mode continuous` + `unit_cannot_stop`, not listed in `required_units`
     (matching the UI, test T1).
3. **`normalized_payload_hash`** in Proof Binding: SHA-256 of canonical JSON (recursively sorted keys) of the normalized
   payload, excluding request metadata, time budgets, internal `__*` keys and auto-semantics provenance.
   `payload_hash` still binds the raw operator payload (snapshot integrity).

## 4. Parity proof
| Check | CLI fixture T6 | UI (after fix) |
|---|---|---|
| normalized payload (field-by-field, `normcmp.php`) | – | **0 differences** |
| `normalized_payload_hash` | `ec14b3aa…` | `ec14b3aa…` |
| winner fingerprint | `153909b165d98c33` | `153909b165d98c33` |
| CP / HR | 71.9896 / 8459.56 | 71.9896 / 8459.56 |
| commitment/startup/stop (on-rows hash) | `37cda705f97a` | `37cda705f97a` |
| proof hashes C1/C2/C3/C4/STG, block_sequence, sc_cc, effective_priority, commitment_semantics, unit_limits, candidate, commitment, fuel | identical | identical |
| UI job `input.json` run through CLI | fp `153909b1…`, CP 71.9896 | – |

Extended to every fixture. The UI `POST mode=run` payload was captured for T0–T8 and M0–M5 and run through the CLI:
15/15 have CP, HR and fingerprint identical to the CLI fixture. All 16 fixtures (T0–T9, M0–M5) are identical to the
reference before this fix (the canonical order does not change any result). Golden runs as two steps in the UI; its
final step was verified identical in the IE scenario (PERFORMANCE_FINAL_COLD_WARM.md).

## 5. XAMPP / Linux (final source)
Every path enters the same job runner, so the same canonical normalisation applies on all of them. Same payload on the
XAMPP-like server (PHP 7.4 built-in + proxy) and on Linux (Apache 2.4 + PHP-FPM 7.4):

| Case | Fingerprint (JS) | `normalized_payload_hash` | CP / HR | XAMPP = Linux |
|---|---|---|---|---|
| T0 | `e0595f935b4a49ff` | `ec14b3aafaee…` | 71.9896 / 8459.56 | ✔ |
| T6 | `e0595f935b4a49ff` | `ec14b3aafaee…` | 71.9896 / 8459.56 | ✔ |
| T1 | `af2a811e9e6e39f9` | `f40c409ac5f1…` | 72.1938 / 8490.96 | ✔ |
| T3 | `454c6190ba5a6787` | `0b30e9d7b506…` | 71.5546 / 8392.72 | ✔ |
| T3 → IEW3 | `6fca3a84a9fc0c4e` | `05bb6bbb8b5c…` | 71.4436 / 8375.69 | ✔ |
| M1 / M3 / golden step 1 / T5 | identical | identical | identical | ✔ |

(The JS fingerprint is a different hash of the same 48 × unit matrix than the CLI `fp.py`; for example, T0 JS
`e0595f93…` corresponds to CLI `153909b1…`.)
