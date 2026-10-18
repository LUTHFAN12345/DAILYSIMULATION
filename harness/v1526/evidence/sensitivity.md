# Uji sensitivitas objective final

Himpunan kandidat: 100 (MR vs Fastest: 2, konsolidasi: 98)

| variasi | pemenang berubah | detail |
|---|---|---|
| default | 0/100 |  |
| band 0,1% | 0/100 |  |
| band 0,5% | 0/100 |  |
| band 1% | 0/100 |  |
| econ tol 0,01 | 0/100 |  |
| econ tol 0,05 | 0/100 |  |
| headroom tol 1 MW | 1/100 | b3252f0a02baf9 MR_vs_FASTEST: MAXIMUM_REVIEW -> FASTEST_CANDIDATE (cp 94.3807 vs 93.5863, econ 94.8147 vs 94.0672) |
| headroom tol 5 MW | 1/100 | b3252f0a02baf9 MR_vs_FASTEST: MAXIMUM_REVIEW -> FASTEST_CANDIDATE (cp 94.3807 vs 93.5863, econ 94.8147 vs 94.0672) |

Pemenang yang berubah hanya ketika toleransi melebihi selisih metrik yang sebenarnya; keputusan default tidak bergantung urutan atau cache (comparator total order dengan sidik jari).
