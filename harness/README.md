# Harness uji V11

Berkas di sini adalah salinan harness yang dipakai untuk menguji V11 di workspace `/home/claude/t5`
(dependensi uji dari `V11_TEST_DEPENDENCIES_CHECKPOINT.zip`, diekstrak ke `/home/claude/t5`).

- `v11/` — suite dan runner V11 (targeted, full regression, clean-extract, path independence, UI, builder paket, laporan).
- `v11/FREEZE_SHA256_V11.txt` — SHA-256 empat source beku; `v11/FREEZE_HARNESS_V11.txt` — SHA-256 harness beku.
- `_full_v10.sh.patched_S` — `v10/_full_v10.sh` dengan `S=` diarahkan ke `/tmp/claude-0/scratch` (satu-satunya penyesuaian path pada harness V10).
- Penyesuaian lingkungan: dependensi diekstrak langsung ke `/home/claude/t5` (tanpa mengubah ±670 path absolut);
  `tgs11/` berisi symlink ke empat PHP repository; PHP 7.4.3 dari `env/php74/debs` di `/opt/php74` + wrapper
  `/usr/local/bin/php74`; `/home/claude/.npm-global/lib/node_modules` -> modul global playwright; scratch `/tmp/claude-0`.
- `v3/scen.php`, `v11/audit_try.php`, `v11/dump_disp.py` lama tetap di `harness/v3`, `harness/v11`.
