# V12 uji generik merit dispatch (FINAL nyata, backend HTTP)

| id | hasil | rincian |
|---|---|---|
| M01_HEADROOM_BASE.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.6644 |
| M01_HEADROOM_BASE.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M01_HEADROOM_BASE.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=1 tanpa bukti=0 ["G2@26:REVIEW:CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID INCUMBENT (CP 64.6658, +0.0014)"] |
| M01_HEADROOM_BASE.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 26-37 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M01_HEADROOM_BASE.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":0,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":34,"FAIL":0} |
| M01_HEADROOM_BASE.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.6644 (V11_SUSUN:SWAP:G5>G2:26-37) pemenang 64.6644 d=0% HR 8386.61 / min band 8386.61; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M01_HEADROOM_BASE.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 71479b553a2085c8de088002 dispatch 444d23a62fe5a577 |
| M01_HEADROOM_BASE.10 gas blocker: pemakaian gas di window [kuota-0,04 ; kuota] | LULUS | gas 64.5918 kuota 64.6000 |
| M02_NEAR_MIN_WB09_3.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=63.4469 |
| M02_NEAR_MIN_WB09_3.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M02_NEAR_MIN_WB09_3.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M02_NEAR_MIN_WB09_3.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 27-38 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M02_NEAR_MIN_WB09_3.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":12,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":10,"FAIL":0} |
| M02_NEAR_MIN_WB09_3.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 63.4469 (POLISH_UNIT_PRIORITY_LANJUTAN) pemenang 63.4469 d=0% HR 8109.13 / min band 8109.13; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M02_NEAR_MIN_WB09_3.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 541b2e98d3c827b6036ed49f dispatch a3b4344d65b9ab26 |
| M02_NEAR_MIN_WB09_3.10 beberapa unit dekat minimum: sapuan konsolidasi dievaluasi dan setiap temuan berakhir sah | LULUS | sapuan DIEVALUASI dibangkitkan 5 valid 1; hasil {"RESOLVED_BY_CONSOLIDATION":12,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":10,"FAIL":0} |
| M03_CONSOLIDATION_WB09.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.3644 |
| M03_CONSOLIDATION_WB09.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=349 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M03_CONSOLIDATION_WB09.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M03_CONSOLIDATION_WB09.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 27-38 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M03_CONSOLIDATION_WB09.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":24,"RESOLVED_BY_STOP":21,"PASS_WITH_REASON":18,"FAIL":0} |
| M03_CONSOLIDATION_WB09.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.3644 (POLISH_UNIT_PRIORITY_LANJUTAN) pemenang 64.3644 d=0% HR 8319.17 / min band 8319.17; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M03_CONSOLIDATION_WB09.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 541b2e98d3c827b6036ed49f dispatch 388edb53ce692b8a |
| M03_CONSOLIDATION_WB09.10 beberapa unit dekat minimum: sapuan konsolidasi dievaluasi dan setiap temuan berakhir sah | LULUS | sapuan DIEVALUASI dibangkitkan 5 valid 1; hasil {"RESOLVED_BY_CONSOLIDATION":24,"RESOLVED_BY_STOP":21,"PASS_WITH_REASON":18,"FAIL":0} |
| M04_MIN_RUNTIME_STOP.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.631 |
| M04_MIN_RUNTIME_STOP.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=443 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M04_MIN_RUNTIME_STOP.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=1 tanpa bukti=0 ["G2@26:REVIEW:CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID INCUMBENT (CP 64.6324, +0.0014)"] |
| M04_MIN_RUNTIME_STOP.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 26-37 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M04_MIN_RUNTIME_STOP.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":0,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":34,"FAIL":0} |
| M04_MIN_RUNTIME_STOP.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.631 (V11_SUSUN:SWAP:G5>G2:26-37) pemenang 64.631 d=0% HR 8382.58 / min band 8382.58; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M04_MIN_RUNTIME_STOP.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 71479b553a2085c8de088002 dispatch 23ab85bcfceecb34 |
| M04_MIN_RUNTIME_STOP.10 start diperlukan: seluruh unit eligible dibandingkan (SWAP ke setiap peer eligible dievaluasi) atau tidak ada start baru | LULUS | peer per unit {"G5":["G3","G4","G6","G2"]}; interval relevan 1 |
| M05_RESERVE.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.4728 |
| M05_RESERVE.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=433 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M05_RESERVE.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M05_RESERVE.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 16-37 stop@28 TESTED tidak valid: EXPORT (row 28 Export maks 14.0 < Range Min 25."] |
| M05_RESERVE.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":9,"RESOLVED_BY_STOP":2,"PASS_WITH_REASON":25,"FAIL":0} |
| M05_RESERVE.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.4728 (DELAY:G5:16-37:@20) pemenang 64.4728 d=0% HR 8296.78 / min band 8296.78; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M05_RESERVE.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 541b2e98d3c827b6036ed49f dispatch 6f716d7a44233ebd |
| M05_RESERVE.10 reserve blocker: spinning reserve >= minimum pada 48 row | LULUS | allowance reserve minimum 5.56 MW |
| M06_BUSFLOW.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.6644 |
| M06_BUSFLOW.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M06_BUSFLOW.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=1 tanpa bukti=0 ["G2@26:REVIEW:CP_LEBIH_RENDAH_DARI_KANDIDAT_VALID INCUMBENT (CP 64.6658, +0.0014)"] |
| M06_BUSFLOW.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 26-37 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M06_BUSFLOW.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":0,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":34,"FAIL":0} |
| M06_BUSFLOW.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.6644 (V11_SUSUN:SWAP:G5>G2:26-37) pemenang 64.6644 d=0% HR 8386.61 / min band 8386.61; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M06_BUSFLOW.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 71479b553a2085c8de088002 dispatch 444d23a62fe5a577 |
| M06_BUSFLOW.10 Bus Flow blocker: Bus Flow >= minimum pada 48 row | LULUS | allowance Bus Flow minimum 131.02 MW |
| M07_EXPORT_RANGE.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.273 |
| M07_EXPORT_RANGE.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M07_EXPORT_RANGE.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M07_EXPORT_RANGE.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 21-32 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M07_EXPORT_RANGE.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":4,"RESOLVED_BY_STOP":0,"PASS_WITH_REASON":9,"FAIL":0} |
| M07_EXPORT_RANGE.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.273 (POLISH_UNIT_PRIORITY_LANJUTAN) pemenang 64.273 d=0% HR 8253.06 / min band 8253.06; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M07_EXPORT_RANGE.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe a37a6dd1fc95c6bb4b5e5770 dispatch aca46477180c58fb |
| M07_EXPORT_RANGE.10 Export tetap di dalam rentang / Dev Dispatch user pada setiap row | LULUS | row di luar band=0; row 23 band bawah 27.00, Export 55.48 |
| M08_DEV_DISPATCH.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.4309 |
| M08_DEV_DISPATCH.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=403 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M08_DEV_DISPATCH.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M08_DEV_DISPATCH.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G3 21-39 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (STG_S1_MIN"] |
| M08_DEV_DISPATCH.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":24,"RESOLVED_BY_STOP":22,"PASS_WITH_REASON":24,"FAIL":0} |
| M08_DEV_DISPATCH.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.4309 (V11_SUSUN:SWAP:G3>G5:21-39) pemenang 64.4309 d=0% HR 8287.94 / min band 8287.94; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M08_DEV_DISPATCH.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 541b2e98d3c827b6036ed49f dispatch d3bc4c43a8ee7dd7 |
| M08_DEV_DISPATCH.10 Export tetap di dalam rentang / Dev Dispatch user pada setiap row | LULUS | row di luar band=0; row 23 band bawah 27.00, Export 52.37 |
| M09_REQUIRED_G5.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.7788 |
| M09_REQUIRED_G5.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=432 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M09_REQUIRED_G5.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M09_REQUIRED_G5.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=0 belum diuji=0 [] |
| M09_REQUIRED_G5.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":0,"RESOLVED_BY_STOP":0,"PASS_WITH_REASON":46,"FAIL":0} |
| M09_REQUIRED_G5.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.7788 (INCUMBENT) pemenang 64.7788 d=0% HR 8412.58 / min band 8412.58; PEMENANG = CP MINIMUM ABSOLUT |
| M09_REQUIRED_G5.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 5c7ffbff4595fe1cd13dcfdc dispatch 5ea0d9548eb59ece |
| M09_REQUIRED_G5.10 forced/required: G5 Start at 10:00 (Required) berjalan mulai row 21, berstatus paksa, tidak dijadikan kandidat stop | LULUS | G5 mulai row 21, 17 row; status ["REQUIRED:start_at"]; interval stop-test G5: 0 |
| M10_MULTI_START.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.1256 |
| M10_MULTI_START.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M10_MULTI_START.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M10_MULTI_START.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G2 27-38 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M10_MULTI_START.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":13,"RESOLVED_BY_STOP":17,"PASS_WITH_REASON":10,"FAIL":0} |
| M10_MULTI_START.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.1256 (V11_SUSUN:SWAP:G2>G5:27-38) pemenang 64.1256 d=0% HR 8207.16 / min band 8207.16; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M10_MULTI_START.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 541b2e98d3c827b6036ed49f dispatch 877074c296b6f130 |
| M10_MULTI_START.10 start diperlukan: seluruh unit eligible dibandingkan (SWAP ke setiap peer eligible dievaluasi) atau tidak ada start baru | LULUS | peer per unit {"G2":["G5","G3","G4","G6"]}; interval relevan 1 |
| M11_GAS_WINDOW.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.4253 |
| M11_GAS_WINDOW.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=396 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M11_GAS_WINDOW.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=0 tanpa bukti=0 [] |
| M11_GAS_WINDOW.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G2 27-38 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M11_GAS_WINDOW.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":29,"RESOLVED_BY_STOP":10,"PASS_WITH_REASON":23,"FAIL":0} |
| M11_GAS_WINDOW.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.4253 (V11_SUSUN:SWAP:G2>G5:27-38) pemenang 64.4253 d=0% HR 8336.79 / min band 8336.79; PEMENANG = CP MINIMUM ABSOLUT dan Heat Rate terendah di band |
| M11_GAS_WINDOW.07 sertifikat reuse kanonik lengkap | LULUS | rute EXACT dirty FULL_HORIZON universe 71479b553a2085c8de088002 dispatch 66a9461f663224d8 |
| M11_GAS_WINDOW.10 gas blocker: pemakaian gas di window [kuota-0,04 ; kuota] | LULUS | gas 65.6648 kuota 65.6800 |
| M12_ACTUAL_SLOT.01 FINAL 48 row, hard PASS, Export 48/48 dalam rentang/Dev Dispatch, rilis | LULUS | rows=48 hard=PASS in_band=48 rilis=true CP=64.6801 |
| M12_ACTUAL_SLOT.02 legal headroom GTG/STG/GE/BBLN dihitung setiap row (<= Effective Max - MW, <= allowance ramp) | LULUS | row=48 catatan=395 kelas=GTG/STG/GE/BBLN pelanggaran=0 |
| M12_ACTUAL_SLOT.03 unit prioritas rendah tidak start saat headroom prioritas tinggi cukup (atau bukti counterfactual/status) | LULUS | temuan=1 tanpa bukti=0 ["G5@26:REVIEW:BAND_V11_TIE_BREAK: kandidat valid SWAP:G5>G2:27-38 tanpa G5 pada row ini (CP 64.65"] |
| M12_ACTUAL_SLOT.04 unit prioritas rendah diuji stop pada row legal pertama sesudah minimum runtime (atau berhenti di sana) | LULUS | interval=1 belum diuji=0 ["G5 27-38 stop@- PASS INTERVAL_BERAKHIR_SEBELUM\/PADA_ROW_LEGAL_PERTAMA (MIN_RUNTIM"] |
| M12_ACTUAL_SLOT.05 LOW_LOAD_FRAGMENTATION tanpa FAIL (RESOLVED_BY_CONSOLIDATION / RESOLVED_BY_STOP / PASS_WITH_REASON numerik) | LULUS | PASS_WITH_OUTCOMES {"RESOLVED_BY_CONSOLIDATION":0,"RESOLVED_BY_STOP":0,"PASS_WITH_REASON":35,"FAIL":0} |
| M12_ACTUAL_SLOT.06 comparator CP: CP minimum absolut dilaporkan, pemenang di band 0,2 %, Heat Rate pemenang = minimum band, alasan tie-break | LULUS | CPmin 64.6587 (SWAP:G5>G2:27-38) pemenang 64.6801 d=0.0331% HR 8387.69 / min band 8387.69; TIE-BREAK HEAT RATE: pemenang 8387.69 BTU/kWh vs CP minimum SWAP:G5>G2:27-38 8388.31 BTU/kWh; selisih CP +0.0214 USD/MWh (0.0331 % <= 0,2 %) |
| M12_ACTUAL_SLOT.07 sertifikat reuse kanonik lengkap | LULUS | rute INCREMENTAL dirty a25f2957e9d1406a universe 3dc068cea988b5bdadcbc642 dispatch c18e1b66cb747cf8 |

Hasil: **95/95**
