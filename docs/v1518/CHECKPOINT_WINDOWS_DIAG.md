# CHECKPOINT — Windows/XAMPP runtime diagnostics
Branch `claude/gifted-ritchie-q0hfdp`, based on `6a3e5a5`. Logic, dispatch, CP/HR and startup are unchanged.

XAMPP Windows reports 75.7 s with the same result (CP 71.1216, 10 candidates / 1 valid). The Linux build server
measures 21–27 s. This build adds an observation-only block, `info['Runtime Platform Diagnostics']`, which is also
included in the Input Snapshot (`jobs_for_this_input[].runtime_diagnostics`):
- os, sapi, php, zts, opcache;
- cores_detected, NUMBER_OF_PROCESSORS, helper_slots, job helpers;
- per-process helper tasks (pid, count, busy_s, type);
- task_busy_s, task_span_s, max_concurrency → PARALLEL or SERIAL;
- phase_timeline, engine_s.

How to read it from one XAMPP run:
- max_concurrency 1 or SERIAL → the helpers did not run in parallel (Apache worker or connection limit).
- cores_detected 1–2 or helper_slots 0–1 → core detection failed, so the fallback is serial.
- zts true / opcache false → PHP ZTS without OPcache. That costs CPU per core; it does not cause serial fallback.
- PARALLEL with engine_s ≈ 70 → a per-core CPU difference (PHP on Windows/ZTS, antivirus file I/O on `jobs/`).
