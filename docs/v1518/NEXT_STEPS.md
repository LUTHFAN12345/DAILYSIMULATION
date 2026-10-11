# NEXT STEPS (after V15.18 FINAL)

1. The STG memo still quantises loads to 0.01 MW inside a single evaluation (10.6 % of hits on T3 are inexact). An
   exact key would make the core fully exact, but it changes the reference fingerprints. Run it as a separate project
   with a full re-baseline.
2. On a 4-core host with the browser on the same machine, T3 IEW3 (commitment repair) sits at about 27 s. More
   headroom needs either more cores (helpers > 3) or a dedicated partial commitment repair.
3. M1 (MIN SC/CC) is heavy in the UI (≈ 70 s cold). It is not a test with a time target, but it is worth profiling.
4. Harness: `hrun.js` does not do the golden final fuel step. The fuel-decision step is covered by the UI (`uiie.js`).
