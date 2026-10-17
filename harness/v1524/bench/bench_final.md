# Benchmark P0.4 — PHP 7.4.33, 1 logical CPU (taskset -c 0)

Topologi host: CPU(s):                                  4 / Model name:                              Intel(R) Xeon(R) Processor @ 2.10GHz / Thread(s) per core:                      1 / Core(s) per socket:                      4 / Socket(s):                               1

Kuota cgroup: /sys/fs/cgroup/cpu/cpu.cfs_quota_us=-1, /sys/fs/cgroup/cpu/cpu.cfs_period_us=100000; PHP: PHP 7.4.33 (cli) (built: Oct 17 2026 13:10:59) ( NTS ); {"opcache.enable_cli":"1","zts":0,"pcntl":true,"memory_limit":"1024M"}

| skenario | mode | target s | V15.24 median wall (min-max) | V15.24 median CPU | V15.24 RSS MB | V15.22 median wall (min-max) | V15.22 median CPU | V15.22 RSS MB | CP V15.24 / V15.22 | det. | affinity |
|---|---|---|---|---|---|---|---|---|---|---|---|
| normal Fastest | cold | 30 | 36.02 (35.8-37.44) | 35.59 | 454.0 | 71.48 (70.47-71.73) | 57.27 | 395.4 | [93.4567] / [105.8901] | True/True | Cpus_allowed_list:	0 |
| normal Fastest | warm | 30 | 0.55 (0.51-0.63) | 0.52 | 38.9 | 5.32 (5.28-5.65) | 5.24 | 57.7 | [93.4567] / [105.8901] | True/True | Cpus_allowed_list:	0 |
| gas-cukup (mandatory window) | cold | 40 | 77.25 (76.31-81.21) | 76.33 | 555.0 | 87.2 (86.63-95.16) | 68.23 | 468.1 | [68.4413] / [68.4413] | True/True | Cpus_allowed_list:	0 |
| gas-cukup (mandatory window) | warm | 40 | 2.64 (2.49-2.74) | 2.6 | 67.3 | 4.11 (4.09-4.18) | 2.55 | 66.1 | [68.4413] / [68.4413] | True/True | Cpus_allowed_list:	0 |
| shortage/LNG | cold | 40 | 51.46 (49.46-53.51) | 50.61 | 260.9 | 115.26 (114.88-117.85) | 96.01 | 518.4 | [73.9965] / [73.9965] | True/True | Cpus_allowed_list:	0 |
| shortage/LNG | warm | 40 | 0.57 (0.53-0.62) | 0.52 | 38.8 | 2.09 (2.04-2.11) | 0.53 | 38.7 | [73.9965] / [73.9965] | True/True | Cpus_allowed_list:	0 |
| terminal/infeasible (IE +20) | cold | 40 | 89.86 (85.08-93.03) | 88.74 | 466.7 | 134.25 (133.89-137.52) | 96.66 | 578.9 | [122.0047] / [122.0047] | True/True | Cpus_allowed_list:	0 |
| terminal/infeasible (IE +20) | warm | 40 | 22.49 (21.87-23.95) | 22.14 | 115.4 | 23.32 (22.88-24.4) | 20.05 | 82.8 | [122.0047] / [122.0047] | True/True | Cpus_allowed_list:	0 |
| difficult/cross-block | cold | 40 | 41.66 (41.34-43.51) | 41.14 | 435.4 | 100.28 (98.5-101.29) | 75.06 | 317.4 | [101.7689] / [106.0179] | True/True | Cpus_allowed_list:	0 |
| difficult/cross-block | warm | 40 | 0.52 (0.49-0.54) | 0.5 | 38.8 | 3.73 (3.66-3.8) | 0.65 | 38.7 | [101.7689] / [106.0179] | True/True | Cpus_allowed_list:	0 |
| fuel decision required (input_data) | cold | 40 | 1.86 (1.79-2.39) | 1.81 | 55.7 | 1.88 (1.69-2.21) | 1.84 | 55.5 | [68.4541] / [68.4541] | True/True | Cpus_allowed_list:	0 |
| fuel decision required (input_data) | warm | 40 | 2.08 (1.97-2.16) | 2.03 | 55.7 | 1.81 (1.77-1.86) | 1.78 | 55.4 | [68.4541] / [68.4541] | True/True | Cpus_allowed_list:	0 |
| one-cell Fix Load (feasible, G8 row 8 = 95 MW) | cold | 10 | 35.03 (34.97-35.24) | 34.54 | 442.1 | 95.02 (95.02-95.02) | 69.48 | 441.5 | [93.3691] / [95.5793] | True/True | Cpus_allowed_list:	0 |
| one-cell Fix Load (feasible, G8 row 8 = 95 MW) | warm | 10 | 0.66 (0.52-36.96) | 0.63 | 38.9 | 7.88 (2.12-91.47) | 7.78 | 65.6 | [93.3691] / [107.4461] | True/False | Cpus_allowed_list:	0 |
| one-cell Fix Load memaksa commitment repair (G2 row 20 = 25 MW) | warm | - | 23.73 (23.73-23.73) | 23.37 | 284.7 | 77.4 (77.4-77.4) | 61.38 | 409.7 | [93.3625] / [106.0083] | True/True | Cpus_allowed_list:	0 |
| exact rerun | exact | 15 | 0.5 (0.48-0.55) | 0.47 | 38.9 | 5.49 (5.39-5.77) | 5.42 | 57.6 | [93.4567] / [105.8901] | True/True | Cpus_allowed_list:	0 |
