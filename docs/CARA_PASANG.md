# Cara pasang V11

1. Salin empat berkas di akar ZIP — `run.php`, `worker02.php`, `worker_functions.php`, dan `index.php` — menimpa
   berkas lama.
2. Buka website, lalu klik Run.

Tidak ada CMD, runner, atau proses OS baru. Perhitungan tetap berjalan di request HTTP biasa yang dipicu browser
(`mode=job_exec`) dan di pekerja pembantu (`mode=job_help`), sama seperti V10. Kompatibel PHP 7.4 (diuji dengan
PHP 7.4.3 CLI + server multi-backend yang meniru Apache).

Berkas tambahan yang ditulis V11 (boleh dihapus kapan saja; dibangun ulang otomatis):

- `jobs/_tl/<kunci-state>_cnt_<job>/` — penghitung kandidat satu sumber (berkas kosong per kandidat unik);
- `jobs/_tl/cs/spec_*.mark` — penanda prefetch spekulatif keluarga commitment (mencegah kerja ganda antar proses).

Hal-hal berikut tidak berubah: format input, `input_data.json`, auto-save dan Save Input; Target Selesai, Maximum Review,
Gas Shortage, LNG/Distillate/campuran, rerun bahan bakar; Change Over, forced stop/start, Mandatory Stop, Skip/Fix Load,
release gate; provenance seluruh bahan bakar; FINAL kanonik (fungsi state).

## Rollback

Untuk kembali ke V10, salin empat berkas `php_simulation_REVISI_OPTIMASI_ENGINE_V10_FINAL.zip` (atau folder
`rollback/` bila disertakan di ZIP ini — lihat `LAPORAN_V11.md` bagian Paket). Hash V10 FINAL yang sah tercatat di
`reports/FREEZE_SHA256_V10.txt`.

## Saklar investigasi (variabel lingkungan; uji memakai nilai bawaan)

| saklar | fungsi |
|---|---|
| `PP_V11=0` | perilaku comparator V10 (tanpa band CP, sapuan konsolidasi, audit V11) |
| `PP_V11_BAND_PCT=<persen>` | lebar band CP (bawaan 0,2) |
| `PP_V11_SWEEP_CAP=<n>` | batas kandidat Tier 2 sapuan konsolidasi (bawaan 8) |
| `PP_V11_FAMILY_SPEC=0` | tanpa prefetch spekulatif keluarga commitment |
| `PP_V11_US_INDEX=0` | pemindaian `unit_stop_time` lama (tanpa indeks) |
| `PP_V5_ALIAS=1` | menyalakan kembali alias V5 "stop tambahan tidak mengikat" (tidak sahih; lihat ARSITEKTUR_V11.md) |

Saklar V10, V9, V8, V7, dan V6 tetap berlaku.
