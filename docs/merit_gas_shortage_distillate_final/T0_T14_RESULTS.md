# T0_T14_RESULTS

UI asli (Playwright Chromium), cold, server XAMPP-semantics (proksi `v3/proxy.js` 6 worker), target **Fastest - Default**, input `input_data.json` pengguna. Validator = source final (hard + C1–C4 + LLF + STG).

Rerun keputusan bahan bakar mengikuti alur UI: Run → popup Gas Shortage → pilihan operator → rerun satu kali. Waktu = klik Run sampai hasil akhir tampil.

| T | Variasi | Waktu UI | First fully valid | Kandidat (periksa/valid) | Status rilis | Blocking | CP | HR | Gas used / quota | PGN pipe used / quota | LNG used | Distillate | C1–C4 | STG | Menu |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| T0 | input persis | 10,8 s | — | — | Keputusan bahan bakar (kekurangan 6,5086; LNG 6,5486 / Distillate 185.397 l) | — | — | — | 85,71 / 79,20 | — | 13 | — | — | — | terkunci (preview), alasan jelas |
| T1 | PGN 22 | 8,9 s | — | — | keputusan: kekurangan 7,5086, LNG 7,5486 | — | — | — | — | — | — | 213.706 l | — | — | terkunci (preview) |
| T2 | PGN 24 | 9,6 s | — | — | keputusan: 5,5086, LNG 5,5486 | — | — | — | — | — | — | 157.085 l | — | — | terkunci (preview) |
| T3 | PEP 35 | 9,5 s | — | — | keputusan: 7,5886, LNG 7,6286 | — | — | — | — | — | — | 215.971 l | — | — | terkunci (preview) |
| T4 | PEP 37 | 8,9 s | — | — | keputusan: 5,4286, LNG 5,4686 | — | — | — | — | — | — | 154.820 l | — | — | terkunci (preview) |
| T5 | Akasia 3 | 8,9 s | — | — | keputusan: 7,5886, LNG 7,6286 | — | — | — | — | — | — | 215.971 l | — | — | terkunci (preview) |
| T6 | Akasia 5 | 8,9 s | — | — | keputusan: 5,4286, LNG 5,4686 | — | — | — | — | — | — | 154.820 l | — | — | terkunci (preview) |
| T7 | LNG 0, recommendation | 9,5 s | — | — | keputusan: 19,5086, LNG 19,5486 | — | — | — | — | — | 0 | 553.434 l | — | — | terkunci (preview) |
| T8 | rekomendasi LNG 6,5486 diterima | **28,1 s** | 3,8 s | 80 / 14 | **FINAL OPTIMAL (PASS)** | — | 75,043 | 8562,30 | 85,7092 / 85,7486 | 23,0 / 23 | 19,5486 | 709,5 l G4 (sisa 0,0256) | 0 / 0 / 0 FAIL | 144/144 | Excel, Image Full, Start From, Image Partial, Add to comparison, Save: **aktif, klik nyata OK** |
| T9 | rekomendasi Distillate diterima | **5,3 s** | 0,9 s | 6 / 3 | **FASTEST VALID PLAN** | — | 97,2024 | 8443,60 | 79,1765 / 79,20 | 23 / 23 | 13 | 261.680 l (G3 126.033 + G6 135.648) | 0 FAIL | PASS | Excel/Image/Compare aktif; Save Plan/Publish mengikuti aturan TIME-LIMITED (pilih Maximum Review) |
| T10 | PGN 23 + PEP 35 (LNG 7,6286 diterima) | 28,3 s | 3,5 s | 80 / 14 | **FINAL OPTIMAL** | — | 75,5037 | 8562,30 | 85,7092 / 85,7486 | 23 / 23 | 20,6286 | 709,5 l G4 | 0 FAIL | PASS | aktif |
| T11 | PGN 23 + PEP 37 (LNG 5,4686 diterima) | 28,5 s | 3,5 s | 80 / 14 | **FINAL OPTIMAL** | — | 74,5823 | 8562,30 | 85,7092 / 85,7486 | 23 / 23 | 18,4686 | 709,5 l G4 | 0 FAIL | PASS | aktif |
| T12 | actual gas kosong (= input) | sama dengan T0/T8 | | | lihat T0 / T8 | | | | | | | | | | |
| T13 | 1 jam Actual PGN = rencana (LNG diterima) | 40,4 s | 12,1 s | — | **FASTEST VALID PLAN** | — | 75,0325 | 8561,47 | Effective 85,7417 / 85,7486 | 22,9999 / 23 | 19,5486 | 709,5 l | 0 FAIL | PASS | aktif |
| T13+ | 1 jam Actual PGN = rencana + 2 % | 90 s | — | — | **GAGAL**: Effective Total 85,7492 > 85,7486 (0,0006) | HARD_VALIDATION_FAILED | | | | 22,96 | | | | | terkunci |
| T14 | Fixed Flow redistribution smoke (S9, G8 unavailable) | 21,7 s | — | — | koreksi Fixed Flow otomatis (row 1–13, 35 penerima, total harian identik) → rerun → `FASTEST_NO_FULLY_VALID_PLAN_WITHIN_BUDGET` | (terminal anggaran, bukan bukti infeasible) | | | | | | | | | terkunci (terminal) |
| Golden | Fastest smoke | 4,8 s | 0,5 s | 5 / 3 | **FASTEST VALID PLAN**, sig **identik** (bc883f0a3aab) | — | 63,9783 | 8020,59 | 71,8527 / 71,88 | 32,97 / 33 | 0 | — | 0 FAIL | PASS | aktif |

## Target vs hasil

| Target | Hasil |
|---|---|
| Fastest normal ≤ 15 s | Golden 4,8 s; T9 5,3 s; keputusan Gas Shortage T0–T7 8,9–10,8 s |
| Gas Shortage feasible ≤ 30 s | T8 28,1 s; T10 28,3 s; T11 28,5 s (sebelumnya 43–105 s dan ditolak) |
| Terminal infeasible ≤ 15 s sesudah proof | T14: proof Fixed Flow 12,3 s, terminal anggaran 21,7 s total |
| C1–C4 FAIL = 0, hard PASS, STG PASS | Semua hasil final / Fastest valid: ya |
| Seluruh menu aktif untuk hasil final | T8 FINAL: Excel (xls 42 KB), Image Full (PNG 3,7 MB), Start From + Image Partial (PNG 3,4 MB), Add to comparison (kartu tampil), Save (dialog "Save simulation" → `run.php?mode=save` HTTP 200) |

## Catatan jujur

1. **T13 dengan deviasi actual +2 % masih gagal** dengan selisih 0,0006 BBTUD di atas window total. Penyebabnya, irisan window pipe dan window total hanya selebar sekitar 0,01 BBTUD akibat selisih Fixed Flow manual vs kuota (lihat audit PGN23). Hasilnya pratinjau terkunci, 90 s. Dengan actual = rencana: PASS.
2. **T13 = 40,4 s**, di atas target 30 s untuk rerun bahan bakar.
3. Image Full/Partial memuat html2canvas 1.4.1 dari cdnjs. Uji sandbox (tanpa internet) melayani file yang sama dari paket npm resmi; mesin pengguna memuatnya dari CDN.
4. Hasil Fastest (TIME-LIMITED VALID PLAN, mis. T9): Excel / Image / Comparison aktif. Save Plan / Publish Final tetap mengikuti aturan produk yang sudah ada (pesan "pilih Maximum Review untuk hasil final"); aturan itu tidak diubah.
