# Distillate per sel — warna & tooltip

| id | uji | hasil | bukti |
|---|---|---|---|
| D1 | alokasi Distillate dipusatkan: satu unit bila satu unit cukup (hasil engine nyata) | LULUS | {"cells":31,"units":{"G1":31},"engine_units":{"G1":31},"perUnitL":{"g1":131886}} |
| D2-30 | hover sel G1 08-Jul-26 00:30 (30% Distillate): tooltip = angka output (Distillate %, Gas %, liter, gas flow, unit, waktu) | LULUS | {"tip":"Distillate : 30% / Gas        : 70% / Distillate : 1,246.2 l/slot (2,492.4 l/jam) / Gas flow   : 4.6561 MMSCFD (0.1048 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 00:30 / Sumber     : DISTILLATE+GAS_JABABEKA","output":{"pct":30,"gas":70,"l":1246.2,"flow":4.6561,"bg":"rgb(187, 202, 243)"}} |
| D2-100 | hover sel G1 08-Jul-26 09:30 (100% Distillate): tooltip = angka output (Distillate %, Gas %, liter, gas flow, unit, waktu) | LULUS | {"tip":"Distillate : 100% / Gas        : 0% / Distillate : 5,511.4 l/slot (11,022.8 l/jam) / Gas flow   : 0 MMSCFD (0 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 09:30 / Sumber     : DISTILLATE","output":{"pct":100,"gas":0,"l":5511.4,"flow":0,"bg":"rgb(29, 78, 216)"}} |
| B-100/0 | render 100% Distillate / 0% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(29, 78, 216)","luminance":77.5462,"tip":"Distillate : 100% / Gas        : 0% / Distillate : 4,153.9 l/slot (2,492.4 l/jam) / Gas flow   : 0 MMSCFD (0.1048 BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 00:30 / Sumber     : DISTILLATE+GAS_JABABEKA"} |
| B-75/25 | render 75% Distillate / 25% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(86, 122, 226)","luminance":121.85519999999998,"tip":"Distillate : 75% / Gas        : 25% / Distillate : 3,115.4 l/slot (— l/jam) / Gas flow   : 1.6625 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 01:00"} |
| B-50/50 | render 50% Distillate / 50% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(142, 167, 236)","luminance":166.6668,"tip":"Distillate : 50% / Gas        : 50% / Distillate : 2,077 l/slot (— l/jam) / Gas flow   : 3.325 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 01:30"} |
| B-40/60 | render 40% Distillate / 60% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(165, 184, 239)","luminance":183.9316,"tip":"Distillate : 40% / Gas        : 60% / Distillate : 1,661.6 l/slot (— l/jam) / Gas flow   : 3.99 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 02:00"} |
| B-25/75 | render 25% Distillate / 75% Gas: biru proporsional + tooltip | LULUS | {"bg":"rgb(199, 211, 245)","luminance":210.90359999999998,"tip":"Distillate : 25% / Gas        : 75% / Distillate : 1,038.5 l/slot (— l/jam) / Gas flow   : 4.9875 MMSCFD (— BBTU/slot) / Unit       : G1 / Time       : 08-Jul-26 02:30"} |
| B-0/100 | render 0% Distillate / 100% Gas: tanpa biru | LULUS | {"bg":null,"luminance":null,"tip":null} |
| B-mono | gradasi: makin tinggi persen Distillate makin gelap (100% paling tua) | LULUS | [[100,77.5462],[75,121.85519999999998],[50,166.6668],[40,183.9316],[25,210.90359999999998],[0,null]] |
| D9 | tidak ada JavaScript error | LULUS |  |

11/11 LULUS
