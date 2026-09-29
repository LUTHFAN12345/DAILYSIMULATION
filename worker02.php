<?php
/* =========================================================================
 *  worker02.php  —  Planning Pembangkit 17 Unit : main simulation engine
 *
 *  Priority order enforced (Sec.1):
 *    1) PLN Export Priority met (range + dispatch-dev + daily target)
 *    2) Gas use kept within quota (PGN pipe + LNG + fixed flow)
 *    3) Lowest production cost  (per row we pick the cheapest dispatch that
 *       still satisfies the export band -> least generation -> least fuel)
 *    4) On gas shortage we DO NOT auto-switch to distillate; we expose the
 *       shortage and act only on the user's choice (add LNG / use distillate).
 *
 *  Entry point:  pp_run_simulation(array $input): array   (defined below).
 *  The file can also be run standalone (CLI or direct web request) — see the
 *  guarded runner at the very bottom.
 * ========================================================================= */

/* PP_COMPAT_PHP74_SHIM */
/* ==============================================================================================
 *  KOMPATIBILITAS PHP TARGET (XAMPP 7.4) — POLYFILL, BUKAN PERUBAHAN PERILAKU.
 *
 *  AKAR MASALAH PRODUKSI. Paket sebelumnya memakai fungsi dan konstruksi PHP 8 sementara XAMPP
 *  target berjalan pada PHP 7.4, sehingga `run.php` gagal di-parse dan server menjawab HTML
 *  "Parse error" dengan HTTP 200 — Run dan Save sama-sama mati.
 *
 *  Polyfill di bawah HANYA didefinisikan bila fungsinya memang belum ada. Pada PHP 8 tidak ada
 *  satu pun yang didefinisikan, sehingga perilaku pada PHP 8 tidak berubah sedikit pun; pada
 *  PHP 7.4 implementasinya mengikuti semantik resmi PHP 8 persis, termasuk kasus needle kosong
 *  dan array kosong. Tidak ada satu pun call-site yang diubah, jadi tidak ada risiko perilaku. */
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        if ($needle === '') return true;
        $len = strlen($needle);
        return $len <= strlen($haystack) && substr_compare($haystack, $needle, -$len) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        $haystack = (string)$haystack; $needle = (string)$needle;
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('array_is_list')) {
    function array_is_list(array $array) {
        $i = 0;
        foreach ($array as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }
}


include_once __DIR__ . '/worker_functions.php';

/* ============================================================================================
 * pp_run_simulation() — WRAPPER: LANGKAH 6/7 "start hanya SATU unit priority berikutnya".
 *
 * KEPUTUSAN DOMAIN BABELAN (operator): Babelan BBLN1/BBLN2 = baseload Priority 1, dipertahankan pada
 * Effective Max; "Jangan menurunkan Babelan jika masih ada solusi valid lain". Urutan wajib SEBELUM
 * Babelan boleh turun mencantumkan pada langkah 6: "Jika masih tidak cukup, pertimbangkan start hanya
 * satu unit priority berikutnya", lalu langkah 7: "Hitung ulang residual sebelum start unit tambahan lain".
 *
 * MASALAH YANG DITUTUP: gas PGN/LNG adalah must-take (dibayar walau tidak dibakar) sehingga validator
 * mewajibkan Total Gas Used di dalam [quota-0.04, quota]. Bila commitment awal terlalu sedikit, seluruh
 * lever up-dispatch bisa HABIS tanpa mencapai window: pada B02 terukur 0 dari 48 row yang punya ruang
 * Export DAN headroom unit sekaligus (di tiap row: entah Export sudah di Range Max, entah semua unit gas
 * sudah di Effective Max). Satu-satunya lever tersisa yang SAH adalah menyalakan satu unit berikutnya —
 * bukan menurunkan Babelan.
 *
 * Wrapper ini HANYA aktif bila gas final RIIL (angka yang sama dgn validator: info['Total Gas Used'] vs
 * info['Total Gas Quota']) berada DI BAWAH window. Rencana yang sudah di dalam window keluar lebih awal
 * tanpa biaya komputasi tambahan, sehingga baseline dan skenario lain tidak tersentuh.
 *
 * Pemilihan mengikuti "constraint first, cost second": setiap candidate WAJIB lolos seluruh hard
 * constraint (validator PASS) DAN masuk window gas; di antara yang valid dipilih Total Cost TERENDAH.
 * Urutan candidate dibaca dari Unit Priority user (pp_priority_flat), bukan hardcode. Unit yang sudah
 * punya perintah operator (required_mode) tidak diganggu.
 * ============================================================================================ */
/* INCUMBENT VALID TERMURAH (V2). Setiap dispatch 48 row lengkap yang dihasilkan core run selama
 * Maximum Review dinilai terhadap input ASLI operator dengan pemeriksaan acceptance penuh
 * (pp_tl_assess). Yang lulus menjadi kandidat pada comparator akhir exact — tidak ada rencana
 * constraint-valid yang pernah dievaluasi engine dan lebih murah yang dibuang. */
function pp_run_simulation_core(array $input): array {
    $out = pp_run_simulation_core_inner($input);
    $T = $GLOBALS['ppExactTrack'] ?? null;
    if (is_array($T) && empty($input['data3']['modeling']['__no_exact_family']) && count((array)($out['data'] ?? [])) === 48) {
        try {
            $a = pp_tl_assess($T['orig'], $out);
            $GLOBALS['ppExactTrack']['n']++;
            if (!empty($a['valid'])) {
                $GLOBALS['ppExactTrack']['v']++;
                if ($T['best'] === null || pp_global_commitment_better($a['key'], $T['best']['key'])) {
                    $GLOBALS['ppExactTrack']['best'] = ['key' => $a['key'], 'checks' => $a['checks'], 'running' => $a['running'], 'output' => $out];
                    /* V3: kandidat valid terbaik sejauh ini tampil sebagai VALID PROVISIONAL (kolam job pemilik). */
                    $hk = $GLOBALS['ppTlHook'] ?? null;
                    if (is_array($hk) && function_exists('pp_tl_pool_offer')) { $a['output'] = $out; $a['off'] = []; pp_tl_pool_offer($hk['key'] . '_x', $a, 'exact_search', (string)$hk['job']); }
                }
            }
        } catch (Throwable $e) {}
    }
    return $out;
}
function pp_run_simulation_core_inner(array $input): array {
    /* Core run dicatat sebagai fase terberat. Sidik jari diambil dari INPUT saja (belum ada baris
     * hasil pada titik ini), sehingga dua core run atas input identik terlihat sebagai duplikat. */
    /* Sidik jari core run memakai SELURUH blok modeling, bukan ringkasannya. Ringkasan membuat
     * dua run yang sebenarnya BERBEDA (mis. berbeda `__gas_trim_target_bbtud` atau
     * `__shaper_quota_adjust` yang disisipkan korektor) terlihat sebagai duplikat — dan angka
     * duplikat yang dilebih-lebihkan akan menuntun pada "optimasi" yang justru merusak hasil. */
    pp_instr('core_run', substr(hash('sha256', json_encode($input['data3']['modeling'] ?? [])), 0, 16), 0.0);
    /* Penghitung monotonik untuk metadata 'Run Status'. $GLOBALS['__pp_core_runs'] TIDAK dipakai
       di sini karena pipeline mereset-nya pada tiap simulasi yang masuk di depth 1, sehingga
       angkanya tidak mewakili satu request penuh. Ini satu increment integer, bukan profiling. */
    $GLOBALS['__pp_core_runs_total'] = (int)($GLOBALS['__pp_core_runs_total'] ?? 0) + 1;
    /* BIAYA SATU CORE RUN, TERUKUR. Biaya ini berbeda jauh antar konfigurasi: terukur ~1,0 detik
     * pada rencana PGN 25/27 dan ~4,0 detik pada rencana KP72 aktif (lebih banyak unit berjalan,
     * pass perbaikan Export beriterasi lebih panjang). Seluruh gerbang admission waktu memakai
     * angka terukur ini, sehingga tidak ada konstanta yang harus ditebak per skenario. */
    $__ccT0 = microtime(true);
    $out=pp_run_simulation_once($input);$GLOBALS['__pp_last_core_output']=$out;
    $__ccDur = microtime(true) - $__ccT0;
    $__ccN = (int)($GLOBALS['__pp_core_cost_n'] ?? 0) + 1;
    $GLOBALS['__pp_core_cost_n'] = $__ccN;
    $GLOBALS['__pp_core_cost_avg'] = (float)($GLOBALS['__pp_core_cost_avg'] ?? $__ccDur)
        + (($__ccDur - (float)($GLOBALS['__pp_core_cost_avg'] ?? $__ccDur)) / $__ccN);
    $GLOBALS['__pp_core_cost_max'] = max((float)($GLOBALS['__pp_core_cost_max'] ?? 0.0), $__ccDur);
    $vv0=pp_validate_hard_constraints($input,$out);if(strtoupper((string)($vv0['status']??''))==='PASS')$GLOBALS['__pp_best_feasible_output']=$out;
    if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_exact_observe')) pp_tl_exact_observe($out, strtoupper((string)($vv0['status']??''))==='PASS');   // pengamat TARGET SELESAI (hanya mencatat)
    if (!empty($input['data3']['modeling']['__changeover_clean_core'])) $out['info']['Change Over Core Completion Entered']=true;

    $q = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
    $u = (float)($out['info']['Total Gas Used (BBTUD)']  ?? 0);
    if ($q <= 1.0 || $u >= $q - 0.04 - 1e-9) return pp_actual_gas_compensation($input, $out);   // window total OK -> lanjut cek kompensasi actual

    $d3    = $input['data3'] ?? [];
    $model = $d3['modeling'] ?? [];
    if (!$d3 || !$model) return pp_actual_gas_compensation($input, $out);
    /* STRICT GAS WINDOW (keputusan domain final): batas bawah quota-0.04 SELALU hard. Bila seluruh
     * lever up-dispatch pada unit running sudah habis dan gas tetap di bawah window, start SATU unit
     * priority berikutnya adalah satu-satunya jalan sah — didahului exhaustion proof di bawah. */

    /* kandidat = unit GTG yang BELUM berbeban sama sekali, hadir, tidak sedang di-stop operator,
     * dan belum punya required_mode (perintah operator tidak boleh ditimpa). Urutan = Unit Priority. */
    /* Pencarian TARGET SELESAI (anytime) mengatur commitment-nya sendiri; penanda internal ini
     * hanya dipasang olehnya dan tidak pernah ada pada run biasa maupun exact. */
    if (!empty($model['__tl_no_auto_start'])) return pp_actual_gas_compensation($input, $out);
    $cands = [];
    foreach (pp_priority_flat($model, '/^g\d+$/') as $uu) {
        if (!pp_unit_present($d3, $uu)) continue;
        if (isset($model['required_mode'][$uu])) continue;
        $on = false;
        foreach ($out['data'] as $rw) if ((float)($rw[strtoupper($uu)] ?? 0) > 0.01) { $on = true; break; }
        if ($on) continue;
        $allStopped = true;
        for ($r = 1; $r <= 48; $r++) if (!pp_is_unit_stopped($d3, $model, $uu, $r)) { $allStopped = false; break; }
        if ($allStopped) continue;                              // Stop Schedule penuh -> bukan lever
        $cands[] = $uu;
    }
    if (!$cands) return pp_actual_gas_compensation($input, $out);

    /* ===== SAME-BLOCK PRIORITY (§9.5-9.7, generik) =========================================
     * Blok sebuah GTG = cluster STG yang di-feed-nya (dibaca dari data unit STG: hrsg/gtg list —
     * TIDAK di-hardcode). Blok disebut AKTIF bila salah satu unit anggotanya sudah berbeban ATAU
     * STG-nya sudah running. Kandidat SAME-BLOCK (blok sudah aktif) WAJIB dievaluasi lebih dulu:
     * start di blok aktif tidak memerlukan STG startup sequence baru. Cross-block hanya boleh
     * dipilih bila SELURUH kandidat same-block ditolak dgn alasan terstruktur. */
    $blockOf = [];                                              // unit gtg -> nama blok (stg cluster)
    $blockMembers = [];
    foreach (['s1', 's2', 's3'] as $sC) {
        if (!isset($d3[$sC])) continue;
        foreach (array_map('strtolower', array_values($d3[$sC]['hrsg'] ?? ($d3[$sC]['gtg'] ?? []))) as $mU) {
            $blockOf[$mU] = $sC; $blockMembers[$sC][] = $mU;
        }
    }
    $blockActive = function (string $blk) use ($blockMembers, $out, $model, $d3): bool {
        if (strtolower((string)($model['unit_last_data_status'][strtoupper($blk)] ?? '')) === 'running') return true;
        foreach ($out['data'] as $rw) if ((float)($rw[strtoupper($blk)] ?? 0) > 0.01) return true;
        foreach (($blockMembers[$blk] ?? []) as $mU)
            foreach ($out['data'] as $rw) if ((float)($rw[strtoupper($mU)] ?? 0) > 0.01) return true;
        return false;
    };
    $sameBlock = []; $crossBlock = [];
    foreach ($cands as $uu) {
        $blk = $blockOf[$uu] ?? '';
        if ($blk !== '' && $blockActive($blk)) $sameBlock[] = $uu; else $crossBlock[] = $uu;
    }
    $prioRank = array_flip(pp_priority_flat($model, '/^g\d+$/') ?: []);
    $rejProof = [];                                             // bukti penolakan terstruktur (§9.7)
    $mkRej = function (string $uu, string $why, array $extra = []) use ($blockOf, $prioRank, $d3, $model, $out): array {
        $PU = strtoupper($uu); $downOK = true; $lastSt = strtolower((string)($model['unit_last_data_status'][$PU] ?? ''));
        for ($r = 1; $r <= 48; $r++) if (pp_is_unit_stopped($d3, $model, $uu, $r)) { $downOK = false; break; }
        return array_merge([
            'candidate' => $PU,
            'block' => strtoupper((string)($blockOf[$uu] ?? 'NONE')),
            'priority_rank' => $prioRank[$uu] ?? null,
            'startup_eligible' => (pp_unit_present($d3, $uu) && !isset($model['required_mode'][$uu])),
            'min_downtime_ok' => $downOK,
            'last_status' => $lastSt !== '' ? $lastSt : 'unknown',
            'effective_max' => (float)($d3[$uu]['max_load'] ?? 0),
            'startup_cost_usd' => (float)($model['startup_penalty']['startup_penalty_cost_gtg_small'] ?? 0),
            'reason_rejected' => $why,
        ], $extra);
    };

    $best = null; $bestCost = INF; $bestU = ''; $tried = [];
    /* Evaluasi bertahap: grup same-block dulu; grup cross-block hanya bila grup pertama gagal. */
    $groups = [];
    if ($sameBlock) $groups['same-block'] = $sameBlock;
    if ($crossBlock) $groups['cross-block'] = $crossBlock;
    $chosenGroup = '';
    foreach ($groups as $gName => $gList) {
    foreach (array_slice($gList, 0, 3) as $uu) {                // langkah 6: SATU unit per candidate
        $in2 = json_decode(json_encode($input), true);          // deep copy: putus seluruh reference
        $in2['data3']['modeling']['required_mode'][$uu] = ['mode' => 'start_at', 'at' => '00:30'];
        $o2 = pp_run_simulation_once($in2);
        $q2 = (float)($o2['info']['Total Gas Quota (BBTUD)'] ?? 0);
        $u2 = (float)($o2['info']['Total Gas Used (BBTUD)']  ?? 0);
        $c2 = (float)($o2['info']['Total Cost (USD)'] ?? INF);
        $inWin = ($u2 >= $q2 - 0.04 - 1e-9 && $u2 <= $q2 + 1e-9);
        $V2 = pp_validate_hard_constraints($in2, $o2);
        $okV = (strtoupper((string)($V2['status'] ?? '')) === 'PASS');
        $tried[] = sprintf('%s: gas %.4f %s window, validator %s, Total Cost %.2f',
            strtoupper($uu), $u2, $inWin ? 'MASUK' : 'di luar', strtoupper((string)($V2['status'] ?? '?')), $c2);
        if (!$inWin || !$okV) {                                  // langkah 7: residual belum tertutup -> lanjut candidate
            $rejProof[] = $mkRej($uu, !$okV
                ? 'hard-constraint violation setelah start (validator ' . strtoupper((string)($V2['status'] ?? '?')) . ')'
                : sprintf('gas %.4f di luar strict window [%.4f,%.4f] setelah start', $u2, $q2 - 0.04, $q2),
                ['group' => $gName, 'gas_after' => round($u2, 4), 'validator' => strtoupper((string)($V2['status'] ?? '?')),
                 'violations' => array_slice(array_map(fn($v) => is_array($v) ? ($v[1] ?? '') : (string)$v, $V2['violations'] ?? []), 0, 3),
                 'total_cost' => round($c2, 2)]);
            continue;
        }
        if ($c2 < $bestCost) { $bestCost = $c2; $best = $o2; $bestU = $uu; $chosenGroup = $gName; }
    }
        if ($best !== null) break;                               // §9.7: grup same-block menang -> cross-block TIDAK diuji
    }
    if ($best === null) {
        /* §9.7 — EVIDENCE WAJIB JUGA SAAT SEMUA KANDIDAT GAGAL: lampirkan partisi blok + rejection
         * reason terstruktur supaya operator melihat mengapa tidak ada start yang sah (sebelumnya
         * pass ini keluar tanpa jejak apa pun). Dispatch TIDAK diubah. */
        $out['info']['Start Exhaustion Proof'] = [
            'residual_gas_before_start_bbtud' => round(($q - 0.04) - $u, 4),
            'gas_before' => round($u, 4), 'gas_window_min' => round($q - 0.04, 4),
            'selected_start_unit' => null,
            'selected_from_group' => null,
            'selection_rule' => 'SAME-BLOCK dulu (blok STG aktif), lalu Unit Priority; kandidat wajib validator PASS + gas window',
            'same_block_candidates' => array_map('strtoupper', $sameBlock),
            'cross_block_candidates' => array_map('strtoupper', $crossBlock),
            'same_block_rejection_proof' => array_values(array_filter($rejProof, fn($x) => ($x['group'] ?? '') === 'same-block')),
            'cross_block_allowed' => false,
            'cross_block_justification' => 'tidak ada start yang sah pada kedua grup',
            'rejection_proof' => $rejProof,
            'candidates_tried' => $tried,
            'residual_after_start_bbtud' => round(($q - 0.04) - $u, 4),
            'note' => 'Tidak ada SATU unit pun yang dapat menutup residual tanpa melanggar hard constraint / keluar window.',
        ];
        return pp_actual_gas_compensation($input, $out);
    }   // tidak ada solusi start-unit -> tetap coba kompensasi actual

    $bbAvg = 0.0; foreach ($best['data'] as $rw) $bbAvg += (float)($rw['BB1'] ?? 0) + (float)($rw['BB2'] ?? 0);
    $bbAvg = $bbAvg / max(1, count($best['data']));
    /* §10 START EXHAUSTION PROOF (terstruktur): residual, unit running + headroom + constraint
     * pembatas, unit terpilih, residual after. Data = angka riil pass ini (tanpa mengubah keputusan). */
    $proofUnits = [];
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $puU) {
        $PU = strtoupper($puU); $on = 0; $sum = 0.0; $mx = 0.0;
        foreach ($out['data'] as $rw) { $v = (float)($rw[$PU] ?? 0); if ($v > 0.01) { $on++; $sum += $v; $mx = max($mx, $v); } }
        if (!$on) continue;
        $eM = (float)($d3[$puU]['max_load'] ?? 0); $avg = $sum / $on;
        $proofUnits[] = ['unit' => $PU, 'avg_load' => round($avg, 2), 'max_row_load' => round($mx, 2),
            'effective_max' => $eM, 'headroom_avg' => round(max(0, $eM - $avg), 2),
            'limiting' => ($mx >= $eM - 1e-6) ? 'Effective Max Load tercapai pada peak rows'
                        : 'PLN Export Range Max (generation tidak boleh naik) / gas window coupling'];
    }
    if (!empty($input['data3']['modeling']['__changeover_clean_core'])) $best['info']['Change Over Core Additional Start']=strtoupper($bestU);
    $best['info']['Start Exhaustion Proof'] = [
        'residual_gas_before_start_bbtud' => round(($q - 0.04) - $u, 4),
        'gas_before' => round($u, 4), 'gas_window_min' => round($q - 0.04, 4),
        'running_units' => $proofUnits,
        'reason_headroom_unusable' => 'tiap row: Export sudah di Range Max atau seluruh unit gas di Effective Max (lever up-dispatch habis)',
        'selected_start_unit' => strtoupper($bestU),
        'selection_rule' => 'SAME-BLOCK dulu (blok STG aktif), lalu Unit Priority; kandidat wajib validator PASS + gas window; Total Cost terendah',
        'selected_from_group' => $chosenGroup,
        'same_block_candidates' => array_map('strtoupper', $sameBlock),
        'cross_block_candidates' => array_map('strtoupper', $crossBlock),
        'same_block_rejection_proof' => array_values(array_filter($rejProof, fn($x) => ($x['group'] ?? '') === 'same-block')),
        'cross_block_allowed' => ($chosenGroup === 'cross-block'),
        'cross_block_justification' => ($chosenGroup === 'cross-block')
            ? (!$sameBlock
                ? 'tidak ada kandidat same-block yang eligible (blok aktif tidak punya unit OFF yang boleh start)'
                : 'seluruh kandidat same-block ditolak (lihat same_block_rejection_proof)')
            : 'tidak dipakai — kandidat same-block valid',
        'rejection_proof' => $rejProof,
        'candidates_tried' => $tried,
        'gas_after_start' => round((float)($best['info']['Total Gas Used (BBTUD)'] ?? 0), 4),
        'residual_after_start_bbtud' => round(max(0, ($q - 0.04) - (float)($best['info']['Total Gas Used (BBTUD)'] ?? 0)), 4),
    ];
    $best['info']['Warnings'] = $best['info']['Warnings'] ?? [];
    $best['info']['Warnings'][] = sprintf(
        'Gas must-take di bawah window: %.4f < %.4f (kurang %.4f BBTUD) sedangkan SELURUH lever up-dispatch habis '
        . '(tiap row: Export sudah di Range Max atau seluruh unit gas sudah di Effective Max). Sesuai urutan wajib '
        . 'langkah 6, di-start SATU unit priority berikutnya = %s; residual dihitung ulang dan gas masuk window '
        . '(%.4f BBTUD). Babelan TIDAK diturunkan (rata-rata %.1f MW, tetap baseload Priority 1). Candidate diuji: %s.',
        $u, $q - 0.04, ($q - 0.04) - $u, strtoupper($bestU),
        (float)($best['info']['Total Gas Used (BBTUD)'] ?? 0), $bbAvg, implode(' | ', $tried));
    return pp_actual_gas_compensation($input, $best);
}

/* ============================================================================================
 * PROMPT RUNNING-UNIT MAXIMIZATION & DECOMMITMENT — final post-pass (§4/§12/§13/§14).
 *
 * Arah KEBALIKAN dari wrapper start di atas: bila ada unit GTG ON yang menetap di LOW LOAD
 * (load factor rendah sepanjang hari) dan TIDAK dilindungi (bukan required/fixed/stop-scheduled/
 * locked/Babelan), uji candidate "SATU unit distop, bebannya dipindahkan ke unit running lain".
 * CONSTRAINT FIRST: candidate hanya sah bila validator PASS + gas tetap dalam window must-take.
 * COST SECOND: dipilih hanya bila Total Cost TURUN melebihi tolerance nyata (bukan rounding).
 * SATU STOP PER ITERASI (§4.7); anti-chattering: stop = full-day (tanpa start-stop-start).
 * Bila tidak ada candidate yang valid & lebih murah -> dispatch existing dipertahankan dan
 * SETIAP unit low-load diberi decommitment proof (§14: alasan tetap ON).
 * ============================================================================================ */
/* =============================================================================================
 *  CHANGE OVER — TIMELINE SWEEP untuk mode "Based on Simulation" (start_other/stop_other = sim)
 *
 *  Sebelumnya sim/sim dinormalisasi menjadi: target GTG masuk required_units sejak row 1
 *  (start segera) dan source GTG/STG masuk unit_cannot_stop sepanjang hari (tidak pernah
 *  handover). Keduanya bukan hasil optimasi.
 *
 *  Di sini "Based on Simulation" dikembalikan ke maknanya: simulator MEMILIH waktu. Kandidat
 *  (target_start_row, source_stop_row) dievaluasi memakai jalur request-time yang SUDAH ADA dan
 *  sudah tervalidasi, lalu pemenang dipilih dengan comparator leksikografis:
 *
 *    1. both-blocks-off rows = 0  (continuity mutlak)
 *    2. hard violation minimum
 *    3. handover selesai
 *    4. target running sampai akhir
 *    5. cost production terendah
 *    6. heat rate terendah
 *    7. overlap terpendek
 *
 *  Generik: source/target, gtg, dan stg dibaca dari konfigurasi change_over — tanpa hardcode unit
 *  maupun nomor block. Evaluasi atomik pada deep clone; hanya pemenang yang dikembalikan.
 * ============================================================================================= */
function pp_changeover_sim_pair(array $input): ?array {
    $m = $input['data3']['modeling'] ?? [];
    if (empty($m['change_over']['enabled'])) return null;
    $src = null; $tgt = null;
    foreach ((array)($m['change_over']['blocks'] ?? []) as $bn => $b) {
        $running = strtolower(trim((string)($b['last_status'] ?? ''))) === 'running';
        $e = ['block' => (string)$bn, 'gtg' => strtolower((string)($b['gtg'] ?? '')),
              'stg' => strtolower((string)($b['stg'] ?? '')),
              'start_other' => strtolower(trim((string)($b['start_other'] ?? ''))),
              'stop_other'  => strtolower(trim((string)($b['stop_other'] ?? '')))];
        if ($running) $src = $e; else $tgt = $e;
    }
    if (!$src || !$tgt) return null;
    $isTime=static fn($v):bool => (bool)preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/',(string)$v);
    $startSim=$tgt['start_other']==='sim'; $stopSim=$src['stop_other']==='sim';
    if (!$startSim&&!$isTime($tgt['start_other'])) return null;
    if (!$stopSim&&!$isTime($src['stop_other'])) return null;
    return ['source'=>$src,'target'=>$tgt,
      'mode'=>$startSim?($stopSim?'sim/sim':'sim/manual'):($stopSim?'manual/sim':'manual/manual')];
}
function pp_changeover_headroom_audit(array $in, array $out, array $pair): array {
    $d3=(array)($in['data3']??[]); $m=(array)($d3['modeling']??[]); $rows=(array)($out['data']??[]);
    $last=[]; foreach((array)($m['unit_last_data_status']??[]) as $u=>$v) $last[strtolower((string)$u)]=strtolower((string)$v);
    $running=[]; foreach(['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'] as $u)
        if(($last[$u]??'stop')==='running'&&pp_effective_unit_available($d3,$m,$u,1)) $running[]=$u;
    $srcStg=strtolower((string)($pair['source']['stg']??''));$family=['s1'=>['g3','g4','g6','s1'],'s2'=>['g1','g2','g5','s2'],'s3'=>['g8','g9','s3']];
    $srcFamily=$family[$srcStg]??array_values(array_filter([$pair['source']['gtg']??'',$pair['source']['stg']??'']));
    $range=(array)($m['pln_export_priority']['range']??[]);$exportMin=(float)($range['min']??-INF);$rowAudit=[];
    foreach($rows as $ri=>$r){
        $gtg=0.0;$ge=0.0;$stg=0.0;$bbln=0.0;$remaining=0.0;$units=[];
        foreach($running as $u){
            $U=strtoupper($u);$mw=(float)($r[$U]??$r[$u]??0);if($mw<=0.01)continue;
            $mx=pp_effective_max_load($d3,$m,$u,$ri+1);if($mx<=0)$mx=(float)($d3[$u]['max_load']??$mw);
            $h=max(0.0,$mx-$mw);if(substr($u,0,2)==='ge')$ge+=$h;elseif($u==='b1'||$u==='b2')$bbln+=$h;else$gtg+=$h;
            if(!in_array($u,$srcFamily,true))$remaining+=$h;if($h>0.01)$units[$U]=round($h,3);
            $su=pp_gtg_to_stg($d3,$u);if($su&&$h>0.01){$probe=$r;$probe[$U]=$mw+$h;pp_recompute_stgs($probe,$d3,$m,$ri+1);$inc=max(0.0,(float)($probe[strtoupper($su)]??0)-(float)($r[strtoupper($su)]??0));$stg+=$inc;if(!in_array($u,$srcFamily,true)&&!in_array($su,$srcFamily,true))$remaining+=$inc;}
        }
        $srcMw=0.0;foreach($srcFamily as $su){$UU=strtoupper($su);$srcMw+=(float)($r[$UU]??$r[$su]??0);} $ev=null;foreach(['EXPORT PLN (MW)','Export','EXPORT','export_pln','PLN_Export'] as $ek)if(array_key_exists($ek,$r)){$ev=(float)$r[$ek];break;}
        $needed=$ev===null?INF:max(0.0,$srcMw-max(0.0,$ev-$exportMin));
        $rowAudit[]=['row'=>$ri+1,'gtg_headroom_mw'=>round($gtg,3),'incremental_stg_headroom_mw'=>round($stg,3),'ge_headroom_mw'=>round($ge,3),'bbln_headroom_mw'=>round($bbln,3),'total_headroom_mw'=>round($gtg+$stg+$ge+$bbln,3),'takeover_headroom_excluding_source_mw'=>round($remaining,3),'source_block_output_mw'=>round($srcMw,3),'replacement_needed_to_hold_export_min_mw'=>is_finite($needed)?round($needed,3):null,'local_takeover_headroom_ok'=>is_finite($needed)&&$remaining+1e-6>=$needed,'units'=>$units];
    }
    return ['policy'=>'RUNNING_GTG_STG_GE_BBLN_FIRST','initial_running'=>array_map('strtoupper',$running),'source_family'=>array_map('strtoupper',$srcFamily),'export_min_mw'=>$exportMin,'rows'=>$rowAudit];
}

function pp_changeover_metrics(array $in, array $out, array $pair): array {
    $d3=(array)($in['data3']??[]);
    $V=pp_validate_hard_constraints($in,$out); $i=$out['info'];
    $vt=[];$vrows=[]; foreach(($V['violations']??[]) as $v){$k=is_array($v)?(string)($v[0]??$v['type']??$v['category']??'?'):'?';$msg=is_array($v)?(string)($v[1]??$v['message']??''):(string)$v;$vt[$k]=($vt[$k]??0)+1;if($k==='export_range')$vrows[]=$msg;}
    $pgnQ=(float)($i['PGN Pipe Quota (BBTUD)']??0);$pgnU=(float)($i['PGN Pipe Used (BBTUD)']??0);
    $pgnGap=$pgnQ<=0?0.0:($pgnU<$pgnQ-0.04?($pgnQ-0.04-$pgnU):($pgnU>$pgnQ?($pgnU-$pgnQ):0.0));
    $rows=(array)($out['data']??[]); $n=count($rows);
    $srcStg=strtoupper((string)($pair['source']['stg']??'')); $tgtStg=strtoupper((string)($pair['target']['stg']??''));
    $tgtGtg=strtoupper((string)($pair['target']['gtg']??''));
    $bothOff=0;$overlapTotal=0;$overlapRun=0;$overlapMax=0;$srcLast=null;$tgtFirst=null;$tgtLast=null;$tgtGtgFirst=null;
    foreach($rows as $ri=>$r){
        $sv=$srcStg!==''?(float)($r[$srcStg]??0):0.0; $tv=$tgtStg!==''?(float)($r[$tgtStg]??0):0.0;
        $sOn=$sv>0.01;$tOn=$tv>0.01;
        if(!$sOn&&!$tOn)$bothOff++;
        if($sOn&&$tOn){$overlapTotal++;$overlapRun++;$overlapMax=max($overlapMax,$overlapRun);}else{$overlapRun=0;}
        if($sOn)$srcLast=$ri+1;
        if($tOn){if($tgtFirst===null)$tgtFirst=$ri+1;$tgtLast=$ri+1;}
        if($tgtGtg!==''&&(float)($r[$tgtGtg]??0)>0.01&&$tgtGtgFirst===null)$tgtGtgFirst=$ri+1;
    }
    $reserveBeforeStart=null;
    if($tgtGtgFirst!==null){$idx=max(0,$tgtGtgFirst-2);$reserveBeforeStart=(float)($rows[$idx]['Spinning_Reserve']??$rows[$idx]['SPINNING RESERVE (MW)']??0);}
    $premature=($reserveBeforeStart!==null&&$reserveBeforeStart>0.5);
    $sourceStoppedAfterOverlap = ($tgtFirst !== null && $srcLast !== null && $srcLast >= ($tgtFirst + 2) && $srcLast < $n);
    $headAudit=pp_changeover_headroom_audit($in,$out,$pair);
    $exportStepViol=[];$prevExport=null;$maxExportStep=0.0;foreach($rows as $eri=>$er){$ev=null;foreach(['EXPORT PLN (MW)','Export','EXPORT','export_pln','PLN_Export'] as $ek)if(array_key_exists($ek,$er)){$ev=(float)$er[$ek];break;}if($ev===null)continue;if($prevExport!==null){$ds=abs($ev-$prevExport);$maxExportStep=max($maxExportStep,$ds);if($ds>35.0+1e-6)$exportStepViol[]=['row_from'=>$eri,'row_to'=>$eri+1,'delta_mw'=>round($ds,4),'limit_mw'=>35.0];}$prevExport=$ev;}
    $earliestHeadroomStop=null;$minOverlapReady=$tgtFirst===null?PHP_INT_MAX:$tgtFirst+2;foreach((array)($headAudit['rows']??[]) as $ha){$hr=(int)($ha['row']??0);if($hr>=$minOverlapReady&&!empty($ha['local_takeover_headroom_ok'])){$earliestHeadroomStop=$hr;break;}}
    $commandStop=(int)($out['info']['Change Over Candidate Stop Row']??0);$lateStopViolation=($commandStop>0&&$earliestHeadroomStop!==null&&$commandStop>$earliestHeadroomStop+2)?1:0;
    $activeGtgRows=0;$lowLoadRows=0;
    foreach($rows as $rr) foreach(['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $U){
        $mw=(float)($rr[$U]??0);if($mw<=0.01)continue;$activeGtgRows++;
        $u=strtolower($U);$mx=(float)($d3[$u]['max_load']??$mw);if($mx>0&&$mw<$mx*0.75)$lowLoadRows++;
    }
    return ['both_off'=>$bothOff,'overlap'=>$overlapTotal,'stg_overlap_consecutive'=>$overlapMax,
      'target_first_positive_row'=>$tgtFirst,'target_last_positive_row'=>$tgtLast,'source_last_positive_row'=>$srcLast,
      'target_gtg_first_positive_row'=>$tgtGtgFirst,'running_fleet_headroom_before_start_mw'=>$reserveBeforeStart,
      'premature_start_headroom_sufficient'=>$premature,'headroom_audit'=>$headAudit,
      'export_step_limit_mw'=>35.0,'export_step_max_mw'=>round($maxExportStep,4),'export_step_violation_count'=>count($exportStepViol),'export_step_violations'=>$exportStepViol,
      'earliest_headroom_legal_stop_row'=>$earliestHeadroomStop,'late_stop_headroom_violation_count'=>$lateStopViolation,
      'active_gtg_rows'=>$activeGtgRows,'low_load_gtg_rows'=>$lowLoadRows,
      'source_stopped_after_stg_overlap'=>$sourceStoppedAfterOverlap,
      'target_running_to_end'=>($tgtLast===$n),
      'handover_complete'=>($tgtLast===$n&&$sourceStoppedAfterOverlap),
      'final_export_band_violations'=>(int)($vt['export_range']??0),'final_export_band_details'=>$vrows,
      'pgn_pipe_quota_bbtud'=>$pgnQ,'pgn_pipe_used_bbtud'=>$pgnU,'pgn_supplier_gap_bbtud'=>round($pgnGap,6),
      'gas_quota_violations'=>(int)($vt['gas_quota']??0),
      'non_gas_hard_violations'=>array_sum($vt)-(int)($vt['gas_quota']??0),
      'gas_window_repairable'=>(int)($vt['gas_quota']??0)>0,
      'hard_violations'=>array_sum($vt),'violations'=>$vt,'status'=>strtoupper((string)($V['status']??'?')),
      'production_cost_usd'=>round((float)($i['Total Cost (USD)']??0),2),'cost_production_usd_mwh'=>round((float)($i['Cost Production (USD/MWh)']??0),4),
      'total_plant_cost_production'=>round((float)($i['Total Plant Cost Production (USD/MWh)']??0),6),
      'jbbk_mm_cost_production'=>round((float)($i['JBBK MM Cost Production (USD/MWh)']??0),6),
      'jbbk_mm_heat_rate'=>round((float)($i['JBBK MM Heat Rate (BTU/kWh)']??0),4),
      'overlap_duration_minutes'=>$overlapMax*30,'overlap_below_minimum'=>($overlapMax<3),
      'overlap_reject_reason'=>($overlapMax<3)?'REJECT_STG_OUTPUT_OVERLAP_BELOW_3_CONSECUTIVE_ROWS':null,
      'heatrate'=>round((float)($i['Heatrate']??0),2),'gas_bbtud'=>round((float)($i['Total Gas Used (BBTUD)']??0),4)];
}
function pp_changeover_candidate_eligible(array $mt): bool {
    return (int)($mt['both_off']??1)===0
      && (int)($mt['stg_overlap_consecutive']??0)>=3
      && !empty($mt['source_stopped_after_stg_overlap'])
      && (int)($mt['non_gas_hard_violations']??1)===0
      && (int)($mt['final_export_band_violations']??1)===0
      && (int)($mt['export_step_violation_count']??1)===0
      && (int)($mt['late_stop_headroom_violation_count']??1)===0
      && !empty($mt['handover_complete']) && !empty($mt['target_running_to_end']);
}
/* Comparator kandidat Change Over — HARD FILTER dulu, baru economic objective.
 *  hard  : both_off  ->  overlap < 3 (REJECT_CHANGE_OVER_OVERLAP_BELOW_MINIMUM)
 *          ->  hard_violations  ->  handover_complete  ->  target_running_to_end
 *  econ  : Total Plant Cost Production  ->  JBBK MM Cost Production  ->  JBBK MM Heat Rate
 *          ->  Total Cost  ->  overlap (lebih kecil dipilih bila seluruhnya setara)
 * Kandidat overlap 0/1/2 TIDAK PERNAH dibandingkan secara ekonomi: ia sudah kalah pada
 * elemen kedua key, sehingga hanya terpilih bila TIDAK ADA kandidat yang memenuhi minimum. */
function pp_changeover_candidate_key(array $mt): array {
    return [
        (int)($mt['both_off'] ?? 0),
        !empty($mt['overlap_below_minimum']) ? 1 : 0,
        empty($mt['source_stopped_after_stg_overlap']) ? 1 : 0,
        (int)($mt['final_export_band_violations'] ?? 0),
        (int)($mt['export_step_violation_count'] ?? 0),
        (int)($mt['late_stop_headroom_violation_count'] ?? 0),
        (int)($mt['non_gas_hard_violations'] ?? 0),
        (int)($mt['gas_quota_violations'] ?? 0),
        (float)($mt['pgn_supplier_gap_bbtud'] ?? INF),
        empty($mt['handover_complete']) ? 1 : 0,
        empty($mt['target_running_to_end']) ? 1 : 0,
        (float)($mt['total_plant_cost_production'] ?? 0),
        (float)($mt['jbbk_mm_cost_production'] ?? 0),
        (float)($mt['jbbk_mm_heat_rate'] ?? 0),
        (float)($mt['production_cost_usd'] ?? 0),
        (int)($mt['active_gtg_rows'] ?? 0),
        (int)($mt['low_load_gtg_rows'] ?? 0),
        -(int)($mt['target_gtg_first_positive_row'] ?? 0),
        (int)($mt['overlap'] ?? 0),
    ];
}
function pp_changeover_sim_sweep(array $input): ?array {
    $pair = pp_changeover_sim_pair($input);
    if (!$pair) return null;
    if (!empty($GLOBALS['__pp_co_sweeping'])) return null;          /* cegah rekursi */

    /* REAL CHANGE OVER REQUEST
     * Continuous/cannot-stop protects the source in normal dispatch. When Change Over is explicitly
     * enabled, that protection is released only inside each coordinated candidate. The candidate
     * remains eligible only after destination STG output, >=3 consecutive STG-overlap rows,
     * takeover readiness, runtime/downtime, and every hard constraint pass. */
    /* MEMOISASI: sweep menjalankan puluhan simulasi penuh. Bila timeline untuk konfigurasi
     * change_over yang IDENTIK sudah pernah diputuskan, pakai kembali hasilnya — normalizer
     * sudah memakai request-time pemenang sehingga jalur biasa cukup. */
    $sig = substr(md5(json_encode($input['data3']['modeling']['change_over'] ?? [])), 0, 16);
    if (!empty($GLOBALS['__pp_co_resolved']) && ($GLOBALS['__pp_co_resolved']['input_sig'] ?? null) === $sig) return null;
    $GLOBALS['__pp_co_sweeping'] = true;
    $hhmm = function (int $r) { $mm = $r * 30; return sprintf('%02d:%02d', intdiv($mm, 60) % 24, $mm % 60); };
    $ladder = [];
    /* sapuan start setiap 2 jam + stop 1..4 jam setelah start (kandidat relevan, bukan 3 titik tetap) */
    $cands = [];
    /* §17 BUTIR 6 — PRUNE OVERLAP < 3 SEBELUM FULL SIMULATION (result-preserving).
     * Startup sequence membuat target first-positive = start_command + 1, dan source
     * last-positive = stop_command - 1, sehingga overlap = (sp-1) - (st+1) + 1 = gap - 1.
     * Terverifikasi pada exact input: gap 2 -> overlap 1 · gap 4 -> overlap 3 · gap 8 -> overlap 7.
     * Kandidat gap < 4 karena itu PASTI melanggar MINIMUM_OVERLAP_ROWS = 3 dan tidak pernah dapat
     * menang pada comparator (elemen kedua key). Mensimulasikannya adalah pekerjaan sia-sia.
     * Prune dicatat pada certificate agar keputusan search dapat diaudit. */
    $prunedLowOverlap = []; $genStats = [];
    /* ==========================================================================================
     *  §6 EVENT-ROW GENERATION + §7 COORDINATED PAIRS + §8 SEMANTIC DEDUP
     *  Sapuan buta row 1..48 diganti candidate start yang dibangun HANYA dari event row relevan:
     *  demand turning point (IE), Export range boundary, operator boundary (stop/skip/required),
     *  TIME PASSED cutoff, startup lead-in feasible row, dan first/last feasible row.
     *  Setiap event row disertai tetangganya (r-1, r, r+1) agar optimum tidak hilang, lalu
     *  dedup + clamp ke horizon. Pair start-stop dikoordinasikan: gap hanya nilai yang memenuhi
     *  MINIMUM_OVERLAP (gap-1 >= 3), bukan Cartesian buta.
     * ========================================================================================== */
    $m0 = $input['data3']['modeling'] ?? [];
    $rowsIE = [];
    foreach ((array)($input['data1'] ?? []) as $ri => $rr) $rowsIE[$ri] = (float)($rr['IE'] ?? $rr['ie'] ?? 0);
    $ev = [];
    /* demand turning points: perubahan arah IE */
    $nIE = count($rowsIE);
    for ($i = 1; $i < $nIE - 1; $i++) {
        $d1 = $rowsIE[$i] - $rowsIE[$i - 1]; $d2 = $rowsIE[$i + 1] - $rowsIE[$i];
        if (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) $ev[] = $i + 1;
    }
    /* Export range boundary */
    foreach ((array)($m0['pln_export_priority']['range_rules'] ?? []) as $rr) {
        $ev[] = (int)($rr['start'] ?? 1); $ev[] = (int)($rr['stop'] ?? 48);
    }
    /* operator boundary: stop / skip / required window */
    foreach (['unit_stop_time', 'unit_skip_load'] as $kk)
        foreach ((array)($m0[$kk] ?? []) as $st2) { $ev[] = (int)($st2['start'] ?? 1); $ev[] = (int)($st2['stop'] ?? 48); }
    /* TIME PASSED cutoff */
    $cut = (int)($m0['monitoring_daily_plan']['time_passed_cutoff_row'] ?? 0);
    if ($cut > 0) $ev[] = $cut + 1;
    /* startup lead-in feasible rows + first/last feasible */
    $ev[] = 4; $ev[] = 40;
    $genStats['event_rows_raw'] = count($ev);
    /* tetangga + dedup + clamp */
    $starts = [];
    foreach ($ev as $r) foreach ([-1, 0, 1] as $d) { $v = $r + $d; if ($v >= 4 && $v <= 40) $starts[$v] = true; }
    $starts = array_keys($starts); sort($starts);
    $genStats['start_rows_after_neighbors_dedup'] = count($starts);
    /* §7 coordinated pairs — gap hanya yang memenuhi minimum overlap (gap-1 >= 3) */
    /* §12 TWO-STAGE — Stage 1 mengevaluasi gap minimum (overlap tepat 3 row) untuk SELURUH event
     * start row; Stage 2 hanya memperluas gap pada tetangga pemenang Stage 1. Rasionalnya: gap
     * lebih besar berarti source block Running lebih lama, sehingga fuel dan Cost Production naik
     * secara monoton pada seluruh kandidat feasible — gap besar hanya relevan bila gap minimum
     * TIDAK feasible di sekitar titik itu. Stage 2 karena itu dibatasi pada jendela pemenang.
     * Bounded dan deterministik; certificate mencatat kedua stage. */
    /* SIM/SIM candidate generator based on the physical STG startup delay and destination minimum
     * runtime. Fixed gap=4 was invalid for Cold S1/S2: STG output is not available until seven rows
     * after the GTG start. Source stop is therefore scheduled only after three actual STG-overlap rows. */
    $rd = (array)($m0['runtime_downtime'] ?? []);
    $minRuntimeHours = max((float)($rd['minimum_runtime_combined_cycle'] ?? 6),
                           (float)($rd['minimum_runtime_stg'] ?? 6));
    $minRuntimeRows = max(1, (int)ceil($minRuntimeHours * 2));
    $targetStg = strtolower((string)($pair['target']['stg'] ?? ''));
    $startupModes = (array)($m0['stg_startup_mode'] ?? []);
    $startupMode = strtolower((string)($startupModes[$targetStg . '_startup'] ?? $startupModes[$targetStg] ?? 'cold'));
    $stgReleaseRows = function_exists('pp_stg_release_rows') ? pp_stg_release_rows($targetStg, $startupMode) : 7;
    $latestStartCommand = max(4, 48 - $stgReleaseRows - $minRuntimeRows);
    $starts = array_values(array_filter($starts, static fn($r) => $r <= $latestStartCommand));
    if (!$starts) $starts = [max(4, $latestStartCommand)];
    /* Mixed boundary optimizer. Manual boundary is immutable; only the 'sim' side is searched.
     * Candidate count remains bounded. All candidates still pass hard constraints and Cost Production. */
    $toRow=static function(string $v):int{[$h,$m]=array_map('intval',explode(':',$v));$r=(int)(($h*60+$m)/30);return $r<=0?48:min(48,$r);};
    $mode=(string)($pair['mode']??'sim/sim'); $seen=[];
    if($mode==='manual/manual'){
        $st=$toRow((string)$pair['target']['start_other']);$sp=$toRow((string)$pair['source']['stop_other']);
        if($sp>$st+$stgReleaseRows+2){$seen[$st.':'.$sp]=1;$cands[]=[$st,$sp];}
    }elseif($mode==='manual/sim'){
        $st=$toRow((string)$pair['target']['start_other']);
        $earliest=min(47,$st+$stgReleaseRows+3);
        $ieSeries=[];foreach((array)($input['data1']??[]) as $ii=>$ir)$ieSeries[$ii+1]=(float)($ir['IE']??$ir['ie']??0);
        $peakRow=$ieSeries?(int)array_search(max($ieSeries),$ieSeries,true):$earliest;
        /* Stop source only after the demand/export-risk peak has passed. The late candidate is a
         * safety alternative; both still require the independent final Export Range gate. */
        /* Headroom opportunities, never automatic 23:30 fallback. */
        $events=[$earliest];$cntIE=count($ieSeries);
        for($rr=max(2,$earliest);$rr<$cntIE;$rr++){$pv=(float)($ieSeries[$rr-1]??INF);$cv=(float)($ieSeries[$rr]??INF);$nv=(float)($ieSeries[$rr+1]??INF);if($cv<=$pv&&$cv<=$nv)$events[]=$rr;}
        $postPeak=min(46,max($earliest,$peakRow+7));$events[]=$postPeak;
        $events=array_values(array_unique(array_filter($events,static fn($rr)=>$rr>=$earliest&&$rr<=46)));sort($events);
        if(count($events)>6)$events=[$events[0],$events[1],$events[(int)floor((count($events)-1)*0.5)],$events[count($events)-3],$events[count($events)-2],$events[count($events)-1]];
        foreach(array_unique($events) as $sp){if($sp<=$st+$stgReleaseRows+2)continue;$seen[$st.':'.$sp]=1;$cands[]=[$st,$sp];}
    }elseif($mode==='sim/manual'){
        $sp=$toRow((string)$pair['source']['stop_other']);
        $latest=$sp-$stgReleaseRows-3;
        $legal=array_values(array_filter($starts,static fn($r)=>$r<=$latest));
        if(!$legal)$legal=[max(1,$latest)];
        $pick=count($legal)>2?[$legal[max(0,count($legal)-3)],$legal[count($legal)-1]]:$legal;
        foreach(array_unique($pick) as $st){if($st<1||$sp<=$st+$stgReleaseRows+2)continue;$seen[$st.':'.$sp]=1;$cands[]=[$st,$sp];}
    }else{
        $pick=count($starts)>2?[$starts[max(0,count($starts)-3)],$starts[count($starts)-1]]:$starts;
        foreach(array_unique($pick) as $ix=>$st){$sp=min(47,$st+$stgReleaseRows+3+($ix===0?5:7));
            if($sp<=$st+$stgReleaseRows+2)continue;$seen[$st.':'.$sp]=1;$cands[]=[$st,$sp];}
    }
    $capCandidates=$mode==='manual/manual'?1:($mode==='manual/sim'?6:3);if(count($cands)>$capCandidates)$cands=array_slice($cands,0,$capCandidates);
    $genStats['candidate_policy']='MIXED_BOUNDARY_HARD_MANUAL_SEARCH_SIM_COST_PRODUCTION';
    $genStats['boundary_mode']=$mode;
    $genStats['minimum_runtime_rows'] = $minRuntimeRows;
    $genStats['stg_release_rows'] = $stgReleaseRows;
    $genStats['latest_start_command_row'] = $latestStartCommand;
    $best = null; $bestKey = null; $bestMeta = null; $bestInput = null; $ranked = [];
    /* CACHE KANDIDAT (§4): key mencakup checksum kedua source engine + checksum input LENGKAP
     * (sehingga Block/Unit Priority, Required/Stop/Skip, Last Data, actual context, dan TIME PASSED
     * ikut terwakili) + pasangan (start, stop). Hanya hasil LENGKAP 48 row yang disimpan; timeout,
     * partial, fatal, dan JSON invalid tidak pernah masuk cache. Jumlah kandidat TIDAK dikurangi
     * sehingga pemenang tidak dapat berubah. */
    static $__coCache = [], $__coSrc = null;
    if ($__coSrc === null) $__coSrc = md5_file(__FILE__) . md5_file(__DIR__ . '/worker_functions.php');
    $inSig = md5(json_encode($input));
    /* PERSISTENT CACHE (§2): cache in-process tidak cukup karena harness menjalankan beberapa
     * context berbeda dan proses dapat berakhir sebelum suite selesai. Key = checksum kedua source
     * engine + checksum INPUT LENGKAP (mewakili Change Over ternormalisasi, Plan/Monitoring context,
     * actual data, TIME PASSED, Last Data, Required/Stop/Skip, Block/Unit Priority) + pasangan
     * (start, stop) + versi skema. Hanya hasil LENGKAP yang ditulis. */
    $__coDir = __DIR__ . '/.co_cache';
    /* §17 CACHE CORRECTNESS — schema WAJIB dinaikkan setiap kali candidate generation atau
     * comparator berubah. Sejak co-v1: event-row generation (§6), coordinated pairs (§7),
     * semantic dedup (§8), hard filter overlap>=3, objective Total Plant Cost Production,
     * dan primary destination unit priority. Entry co-v1 lama TIDAK boleh dipakai lagi karena
     * dihasilkan generator yang berbeda. */
    $__coSchema = 'co-v32-rejected-candidate-bracket-evidence';
    /* ===== CACHE PERSISTEN CHANGE OVER: DEFAULT MATI (perbaikan non-determinisme) ============
     *  CACAT YANG DITEMUKAN LEWAT ORACLE. Cache ini menyimpan output dan metrik kandidat, tetapi
     *  TIDAK menyimpan input kandidat ($x). Karena itu pada cache HIT kodenya hanya menambahkan
     *  metrik ke $ladder lalu `continue` — kandidat tidak pernah masuk $ranked dan tidak pernah
     *  dapat menjadi pemenang (komentarnya sendiri menyatakan "evidence cache only; clean winner
     *  requires concrete candidate input").
     *
     *  Akibatnya, begitu cache hangat untuk SELURUH kandidat, $ranked kosong, tidak ada pemenang,
     *  dan sweep mengembalikan hasil dengan `data` KOSONG. Terukur: run pertama skenario
     *  start sim / stop sim menghasilkan 48 baris dan gas 64,7739; run KEDUA pada input yang sama
     *  menghasilkan 0 baris. Hasil Change Over jadi bergantung pada ada-tidaknya file cache di
     *  disk — yaitu NON-DETERMINISTIK antar-run, hal yang secara tegas tidak diizinkan.
     *
     *  Cache in-process ($__coCache) tidak menolong dan tidak merugikan: dalam satu request setiap
     *  kandidat adalah pasangan (start, stop) yang berbeda, jadi kuncinya tidak pernah berulang.
     *  Satu-satunya cache yang benar-benar berpengaruh adalah yang persisten, dan itulah yang
     *  rusak. Karena determinisme lebih penting daripada kecepatan pada input yang diulang, cache
     *  persisten kini DEFAULT MATI dan hanya dapat dinyalakan secara sadar dengan PP_CO_CACHE=1.
     *  Menyalakannya mengembalikan cacat di atas. */
    $__coPersist = (string)getenv('PP_CO_CACHE') === '1';
    if ($__coPersist && !is_dir($__coDir)) @mkdir($__coDir, 0777, true);
    /* §13 SEARCH TIME BUDGET + GRACEFUL JSON — sweep TIDAK BOLEH menghabiskan seluruh
     * max_execution_time lalu menghasilkan HTTP 500 / non-JSON. Bila budget terlampaui,
     * search berhenti pada kandidat terbaik yang SUDAH tervalidasi dan menerbitkan
     * SEARCH_BUDGET_EXCEEDED_PARTIAL_RESULT. Default konservatif agar aman pada XAMPP 120 s;
     * dapat diatur lewat modeling.change_over_search_budget_seconds. */
    $__coBudget = min(25.0, max(10.0, (float)($input['data3']['modeling']['change_over_search_budget_seconds'] ?? 20.0)));
    $__coT0 = microtime(true);
    $__coBudgetHit = false;
    foreach ($cands as [$st, $sp]) {
        if (!$__coBudgetHit && (microtime(true) - $__coT0) > $__coBudget && !empty($ladder)) {
            $__coBudgetHit = true;
        }
        if ($__coBudgetHit) { $genStats['budget_skipped'] = ($genStats['budget_skipped'] ?? 0) + 1; continue; }
        $ck = $__coSrc . '|' . $inSig . '|' . $st . '|' . $sp . '|' . $__coSchema;
        $cf = $__coDir . '/' . md5($ck) . '.json';
        if (!isset($__coCache[$ck]) && $__coPersist && is_file($cf)) {
            $j = json_decode((string)@file_get_contents($cf), true);
            /* verifikasi seluruh checksum cocok sebelum dipakai, dan hasil harus LENGKAP 48 row */
            if (is_array($j) && ($j['key'] ?? null) === $ck
                && !empty($j['o']['data']) && count($j['o']['data']) === 48
                && isset($j['mt']['hard_violations']))
                $__coCache[$ck] = ['o' => $j['o'], 'mt' => $j['mt']];
        }
        if (isset($__coCache[$ck])) {
            $o = $__coCache[$ck]['o']; $mt = $__coCache[$ck]['mt'];
            $ladder[] = $mt;
            $key = pp_changeover_candidate_key($mt);
            continue; /* evidence cache only; clean winner requires concrete candidate input */
        }
        $x = json_decode(json_encode($input), true);
        /* Explicit Change Over is the only authorized release of source Continuous/Cannot Stop.
         * Release controls in this candidate clone only. Request input and normal dispatch stay intact. */
        $xm = &$x['data3']['modeling'];
        $srcUnits = array_values(array_filter([
            strtolower((string)($pair['source']['gtg'] ?? '')),
            strtolower((string)($pair['source']['stg'] ?? ''))
        ]));
        foreach ($srcUnits as $su) {
            unset($xm['required_mode'][$su], $xm['required_mode'][strtoupper($su)]);
        }
        $xm['required_units'] = array_values(array_filter((array)($xm['required_units'] ?? []),
            static fn($u) => !in_array(strtolower((string)$u), $srcUnits, true)));
        $xm['unit_cannot_stop'] = array_values(array_filter((array)($xm['unit_cannot_stop'] ?? []),
            static fn($u) => !in_array(strtolower((string)$u), $srcUnits, true)));
        /* Commitment consolidation: only the configured destination GTG may newly start.
         * Source/destination sibling GTGs that were initially Stop remain OFF for this handover. */
        $lastCo=[];foreach((array)($xm['unit_last_data_status']??[]) as $ku=>$vu)$lastCo[strtolower((string)$ku)]=strtolower((string)$vu);
        $families=['1'=>['g3','g4','g6'],'2'=>['g1','g2','g5'],'3'=>['g8','g9']];
        $tgtPrimary=strtolower((string)($pair['target']['gtg']??''));
        foreach(array_unique(array_merge($families[$pair['source']['block']]??[],$families[$pair['target']['block']]??[])) as $cu){
            if($cu===$tgtPrimary||($lastCo[$cu]??'stop')==='running')continue;
            $xm['unit_stop_time'][]=['unit'=>$cu,'start'=>1,'stop'=>48];
        }
        $xm['change_over_headroom_policy']='RUNNING_GTG_STG_GE_FIRST';
        $xm['change_over_candidate_context'] = true;
        $xm['change_over_authorized_source_release'] = [
            'units' => $srcUnits, 'candidate_start_row' => $st, 'candidate_stop_row' => $sp,
            'requires_stg_overlap_rows' => 3, 'scope' => 'candidate_only'
        ];
        unset($xm);
        $b = &$x['data3']['modeling']['change_over']['blocks'];
        foreach ($b as $bn => &$blk) {
            if ((string)$bn === $pair['target']['block']) $blk['start_other'] = $hhmm($st);
            if ((string)$bn === $pair['source']['block']) $blk['stop_other']  = $hhmm($sp);
        }
        unset($blk, $b);
        $x['data3']['modeling']['__screen_only'] = true;
        $x['data3']['modeling']['__skip_global_commitment_review'] = true;
        $x['data3']['modeling']['__skip_decommitment_review'] = true;
        $o = pp_run_simulation_once($x); /* bounded single-pass screening */
        $o['info']['Change Over Candidate Stop Row']=$sp;
        $mt = pp_changeover_metrics($x, $o, $pair);
        $mt['target_start_command_row'] = $st; $mt['source_stop_command_row'] = $sp;
        $ladder[] = $mt;
        /* comparator leksikografis: continuity -> violations -> handover -> running-to-end
         * -> cost production -> heat rate -> overlap */
        $key = pp_changeover_candidate_key($mt);
        $ranked[] = ['key'=>$key,'eligible'=>pp_changeover_candidate_eligible($mt),'input'=>$x,'output'=>$o,'meta'=>$mt];
        if (pp_changeover_candidate_eligible($mt) && ($bestKey === null || $key < $bestKey)) {
            $bestKey=$key; $best=$o; $bestMeta=$mt; $bestInput=$x;
        }
        if (!empty($o['data']) && count($o['data']) === 48 && isset($mt['hard_violations'])) {
            $__coCache[$ck] = ['o' => $o, 'mt' => $mt];
            if ($__coPersist) {
                $enc = json_encode(['key' => $ck, 'o' => $o, 'mt' => $mt]);
                if ($enc !== false) @file_put_contents($cf, $enc);   /* hanya hasil lengkap & serializable */
            }
        }
    }
    $GLOBALS['__pp_co_sweeping'] = false;
    $GLOBALS['__pp_co_candidates'] = count($ladder);
    if ($best === null) {
        usort($ranked, static fn($a,$b) => $a['key'] <=> $b['key']);
        $fail=$ranked[0] ?? null;
        /* TIDAK PERNAH mengembalikan rencana KOSONG. Bila tidak ada satu pun kandidat yang punya
         * output (mis. seluruhnya berasal dari cache persisten yang tidak dapat menjadi pemenang),
         * sweep MENOLAK menangani request ini dan mengembalikan null, sehingga pipeline normal
         * menghasilkan rencana 48 baris yang sah. Bukti infeasibility Change Over dititipkan dan
         * dilampirkan kembali oleh pipeline. Mengembalikan `data => []` dengan `result => 'ok'`
         * membuat UI menampilkan Simulation Data kosong tanpa satu pun pesan kesalahan. */
        if (!is_array($fail)) {
            $GLOBALS['__pp_co_infeasible_evidence'] = [
                'mode' => 'INFEASIBLE_CHANGE_OVER_NO_LEGAL_HANDOVER',
                'requested' => true, 'executed' => false, 'yes_no_equivalent' => false,
                'source_block' => $pair['source']['block'], 'target_block' => $pair['target']['block'],
                'selected' => null, 'candidates_evaluated' => count($ladder), 'ladder' => $ladder,
                'deferred_reason' => 'tidak ada kandidat dengan output simulasi; sweep menolak dan pipeline normal dipakai'];
            return null;
        }
        $failOut=$fail['output'];
        /* ===== SEBAB PENOLAKAN DINAMAI APA ADANYA (D-3) =========================================
         * Label `INFEASIBLE_CHANGE_OVER_NO_LEGAL_HANDOVER` dulu dipakai untuk SETIAP kandidat yang
         * tidak eligible, termasuk kandidat yang handover-nya justru SELESAI. Terukur pada pasangan
         * blok 2 (G1/S2, running) -> blok 1 (G3/S1, stop): meta kandidat terbaik berbunyi
         * `handover_complete = true`, `source_stopped_after_stg_overlap = true`,
         * `target_running_to_end = true` — yang gagal adalah lantai Export pada tiga baris transisi
         * (row 23 Export 14,21; row 26 14,53; row 27 14,13 vs Range Min 25). Menyebutnya "tidak ada
         * handover legal" menyesatkan operator: handover-nya ada, lantai Export-nya yang tidak
         * tercapai. Sebab yang sebenarnya kini diturunkan dari meta kandidat terbaik dan
         * dilampirkan sebagai conflict set spesifik. */
        $fm = is_array($fail) ? (array)$fail['meta'] : [];
        $coMode = 'INFEASIBLE_CHANGE_OVER_NO_LEGAL_HANDOVER';
        $coWhy  = 'tidak ada kandidat handover yang menyelesaikan perpindahan blok';
        $coConflict = [];
        if (!empty($fm['handover_complete'])) {
            $expN = (int)($fm['final_export_band_violations'] ?? 0);
            $stepN = (int)($fm['export_step_violation_count'] ?? 0);
            if ($expN > 0) {
                $coMode = 'CHANGE_OVER_HANDOVER_COMPLETE_BUT_EXPORT_FLOOR_UNREACHABLE';
                $coWhy  = sprintf('handover selesai, tetapi %d baris transisi berada di bawah Range Min Export', $expN);
                $coConflict = ['rows' => (array)($fm['final_export_band_details'] ?? []),
                               'rows_count' => $expN];
            } elseif ($stepN > 0) {
                $coMode = 'CHANGE_OVER_HANDOVER_COMPLETE_BUT_EXPORT_STEP_EXCEEDED';
                $coWhy  = sprintf('handover selesai, tetapi %d slot melanggar batas langkah Export %s MW',
                                  $stepN, (string)($fm['export_step_limit_mw'] ?? '35'));
                $coConflict = ['slots' => (array)($fm['export_step_violations'] ?? []), 'slots_count' => $stepN];
            } elseif (empty($fm['premature_start_headroom_sufficient'])
                      && isset($fm['running_fleet_headroom_before_start_mw'])) {
                $coMode = 'CHANGE_OVER_HANDOVER_COMPLETE_BUT_NO_HEADROOM_FOR_PREMATURE_START';
                $coWhy  = sprintf('handover selesai, tetapi armada yang berjalan tidak punya headroom (%.1f MW) '
                                . 'untuk menopang start target lebih awal',
                                  (float)($fm['running_fleet_headroom_before_start_mw'] ?? 0));
                $coConflict = ['running_fleet_headroom_before_start_mw' => $fm['running_fleet_headroom_before_start_mw'] ?? null,
                               'target_gtg_first_positive_row' => $fm['target_gtg_first_positive_row'] ?? null];
            } else {
                $coMode = 'CHANGE_OVER_HANDOVER_COMPLETE_BUT_CANDIDATE_NOT_ELIGIBLE';
                $coWhy  = 'handover selesai, tetapi kandidat tidak lolos syarat kelayakan lain';
            }
        }
        $failOut['info']['Change Over Timeline']=['mode'=>$coMode,
          'requested'=>true,'executed'=>false,'yes_no_equivalent'=>false,
          'handover_complete'=>(bool)($fm['handover_complete'] ?? false),
          'blocker'=>['reason'=>$coMode,'penjelasan'=>$coWhy,'conflict_set'=>$coConflict],
          'source_block'=>$pair['source']['block'],'target_block'=>$pair['target']['block'],
          'selected'=>is_array($fail)?$fail['meta']:null,'candidates_evaluated'=>count($ladder),'ladder'=>$ladder,
          'deferred_reason'=>'Screening completed; no recursive NO fallback was executed'];
        $failOut['ok']=false; $failOut['error_code']=$coMode;
        return $failOut;
    }
    $screenEligible=[];foreach($ranked as $rq){if(empty($rq['eligible']))continue;$rm=(array)$rq['meta'];$screenEligible[]=['start_row'=>(int)($rm['target_start_command_row']??0),'stop_row'=>(int)($rm['source_stop_command_row']??0),'cost_production'=>(float)($rm['total_plant_cost_production']??$rm['cost_production_usd_mwh']??INF),'key'=>$rq['key']];}usort($screenEligible,static fn($a,$b)=>$a['key']<=>$b['key']);$screenWinner=$screenEligible[0]??null;$selectedIdentity=['start_row'=>(int)($bestMeta['target_start_command_row']??0),'stop_row'=>(int)($bestMeta['source_stop_command_row']??0)];$screenIdentityMatch=is_array($screenWinner)&&$screenWinner['start_row']===$selectedIdentity['start_row']&&$screenWinner['stop_row']===$selectedIdentity['stop_row'];
    unset($bestInput['data3']['modeling']['__screen_only']);
    $bestInput['data3']['modeling']['__clean_changeover_winner']=true;
    /* Full-horizon clean completion. Screening stays single-pass for speed, but the winner uses
     * the same 48-slot core path as Change Over NO. */
    $bestInput['data3']['modeling']['__changeover_clean_core']=true;
    $clean=pp_run_simulation_core($bestInput);
    $clean['info']['Change Over Candidate Stop Row']=$bestMeta['source_stop_command_row'];
    $clean['info']['Change Over Clean Completion Policy']='FULL_48_SLOT_RUNNING_HEADROOM_THEN_ONE_PRIORITY_START_THEN_PGN_REPAIR';
    $cleanMeta=pp_changeover_metrics($bestInput,$clean,$pair);
    $cleanMeta['target_start_command_row']=$bestMeta['target_start_command_row'];
    $cleanMeta['source_stop_command_row']=$bestMeta['source_stop_command_row'];
    $cleanFullyValid=pp_changeover_candidate_eligible($cleanMeta)
      && (int)($cleanMeta['hard_violations']??1)===0
      && (int)($cleanMeta['gas_quota_violations']??1)===0;
    if (!$cleanFullyValid) {
        /* BLOCKER WAJIB ADA DI SETIAP CABANG PENOLAKAN. Cabang ini sebelumnya hanya menuliskan
         * mode tanpa `blocker`, sehingga kontrak keputusan operator di run.php tidak mengenalinya
         * dan hasilnya jatuh ke jalur kegagalan umum — operator melihat FAIL tanpa sebab spesifik
         * padahal engine tahu persis apa yang gagal. */
        $cleanConflict = [];
        foreach ((array)($cleanMeta['hard_violation_list'] ?? $cleanMeta['violations'] ?? []) as $cv)
            $cleanConflict[] = is_array($cv) ? implode(': ', array_map('strval', $cv)) : (string)$cv;
        $clean['info']['Change Over Timeline']=['mode'=>'INFEASIBLE_CHANGE_OVER_CLEAN_VALIDATION_FAILED',
          'requested'=>true,'executed'=>false,'yes_no_equivalent'=>false,'selected'=>$cleanMeta,
          'handover_complete'=>(bool)($cleanMeta['handover_complete'] ?? false),
          'blocker'=>['reason'=>'INFEASIBLE_CHANGE_OVER_CLEAN_VALIDATION_FAILED',
            'penjelasan'=>sprintf('kandidat Change Over terbaik lolos penyaringan tetapi gagal pada validasi penuh 48 slot: %d pelanggaran keras, %d di antaranya kuota gas',
                (int)($cleanMeta['hard_violations'] ?? 0), (int)($cleanMeta['gas_quota_violations'] ?? 0)),
            'conflict_set'=>['hard_violations'=>(int)($cleanMeta['hard_violations'] ?? 0),
                             'gas_quota_violations'=>(int)($cleanMeta['gas_quota_violations'] ?? 0),
                             'rows'=>array_slice($cleanConflict, 0, 10)]],
          'candidates_evaluated'=>count($ladder),'ladder'=>$ladder];
        $clean['ok']=false; $clean['error_code']='CHANGE_OVER_CLEAN_VALIDATION_FAILED'; return $clean;
    }
    $best=$clean; $bestMeta=$cleanMeta;
    $cleanIdentityMatch=(int)($cleanMeta['target_start_command_row']??0)===$selectedIdentity['start_row']&&(int)($cleanMeta['source_stop_command_row']??0)===$selectedIdentity['stop_row'];
    $economicProof=['status'=>($screenIdentityMatch&&$cleanIdentityMatch)?'PASS':'FAIL','method'=>'MINIMUM_SCREENING_COST_IDENTITY_THEN_CLEAN_HARD_VALIDATION','eligible_candidates'=>count($screenEligible),'selected_identity'=>$selectedIdentity,'screening_minimum_cost_production'=>is_array($screenWinner)?$screenWinner['cost_production']:null,'clean_final_cost_production'=>(float)($cleanMeta['total_plant_cost_production']??$cleanMeta['cost_production_usd_mwh']??INF),'identity_match_screen'=>$screenIdentityMatch,'identity_match_clean'=>$cleanIdentityMatch];
    if($economicProof['status']!=='PASS'){$best['ok']=false;$best['error_code']='CHANGE_OVER_ECONOMIC_PROOF_FAILED';return $best;}
    if((int)($bestMeta['final_export_band_violations']??1)!==0||(int)($bestMeta['export_step_violation_count']??1)!==0){
        $best['info']['Change Over Timeline']=['mode'=>'REJECTED_FINAL_EXPORT_BAND','requested'=>true,'executed'=>false,
          'yes_no_equivalent'=>false,'selected'=>$bestMeta,'reason'=>'FINAL_CLEAN_VALIDATION_EXPORT_RANGE_FAILED',
          'handover_complete'=>(bool)($bestMeta['handover_complete'] ?? false),
          'blocker'=>['reason'=>'REJECTED_FINAL_EXPORT_BAND',
            'penjelasan'=>'kandidat Change Over terbaik ditolak pada validasi akhir karena Export keluar dari Range',
            'conflict_set'=>['rows'=>array_slice((array)($bestMeta['final_export_band_details'] ?? []), 0, 10),
                             'rows_count'=>(int)($bestMeta['final_export_band_violations'] ?? 0)]]];
        $best['ok']=false;$best['error_code']='CHANGE_OVER_FINAL_EXPORT_BAND_FAILED';return $best;
    }
    $best['info']['Change Over Headroom Audit']=$cleanMeta['headroom_audit']??[];
    $rd=(array)($input['data3']['modeling']['runtime_downtime']??[]);
    $userRunH=max((float)($rd['minimum_runtime_combined_cycle']??6),(float)($rd['minimum_runtime_stg']??6));
    $tgtRows=0;foreach((array)($best['data']??[]) as $rr)if((float)($rr[strtoupper((string)$pair['target']['gtg'])]??0)>0.01)$tgtRows++;
    $best['info']['Change Over Runtime Review']=['user_input_minimum_runtime_hours'=>$userRunH,
      'minimum_runtime_rows'=>(int)ceil($userRunH*2),'observed_destination_runtime_hours'=>round($tgtRows/2,2),
      'observed_destination_runtime_rows'=>$tgtRows,'recommendation_available'=>false,'recommended_runtime_hours'=>null,
      'status'=>'REVIEW_ONLY_NO_UNVALIDATED_OVERRIDE','note'=>'Runtime lebih pendek hanya direkomendasikan setelah alternatif tersebut lolos clean re-simulation seluruh hard constraint; input user tidak diubah otomatis.'];

    /* RESOLVED TIMELINE: publikasikan waktu pemenang agar pp_normalize_change_over() memakai
     * jalur request-time yang sama saat validator/pass hilir menormalisasi ulang input yang sama.
     * Tanpa ini, normalisasi sim/sim lama (cannot_stop sepanjang hari) akan menabrak dispatch
     * pemenang dan memunculkan violation cannot_stop/commitment_continuous palsu. */
    $GLOBALS['__pp_co_resolved'] = [
        'input_sig'   => substr(md5(json_encode($input['data3']['modeling']['change_over'] ?? [])), 0, 16),
        'target_block' => $pair['target']['block'], 'source_block' => $pair['source']['block'],
        'start_other' => $hhmm((int)$bestMeta['target_start_command_row']),
        'stop_other'  => $hhmm((int)$bestMeta['source_stop_command_row'])];
    $best['info']['Change Over Timeline'] = [
        'requested' => true,
        'executed' => true,
        'yes_no_equivalent' => false,
        'source_release_scope'=>'AUTHORIZED_CHANGE_OVER_CANDIDATE_ONLY','mode'=>(string)($pair['mode']??'sim/sim').' (bounded timeline optimisation)',
        'source_block' => $pair['source']['block'], 'target_block' => $pair['target']['block'],
        'selected' => $bestMeta, 'economic_proof'=>$economicProof, 'candidates_evaluated' => count($ladder), 'ladder' => $ladder,
        /* §18 termination certificate — search bounded dan alasan pruning eksplisit */
        'search_certificate' => [
            'candidate_set_bounded' => true,
            'generated_before_prune' => count($ladder) + count($prunedLowOverlap),
            'pruned_before_simulation' => count($prunedLowOverlap),
            'prune_reason'=>'PRUNE_CHANGE_OVER_OVERLAP_BELOW_MINIMUM_OR_MANUAL_BOUNDARY_CONFLICT',
            'pruned_candidates' => $prunedLowOverlap,
            'simulated' => count($ladder),
            'search_budget_seconds' => $__coBudget,
            'elapsed_seconds' => round(microtime(true) - $__coT0, 2),
            'budget_skipped' => (int)($genStats['budget_skipped'] ?? 0),
            'cache_schema' => $__coSchema,
            'termination_reason' => $__coBudgetHit
                ? 'SEARCH_BUDGET_EXCEEDED_PARTIAL_RESULT'
                : 'SEARCH_EXHAUSTED_WITH_CERTIFICATE'],];
    /* V6: Unit Priority polish pada pemenang Change Over dengan evaluator Change Over yang sama (input
     * pemenang sweep, dispatch GTG dibekukan, timeline Change Over tetap) — commitment, jam start/stop,
     * dan handover tidak berubah; hanya pembagian beban antar unit yang berjalan. */
    if (function_exists('pp_v6_priority_polish') && count((array)($best['data'] ?? [])) === 48 && (string)getenv('PP_V6_POLISH_CO') !== '0') {
        $coIn = $bestInput; $coTl = $best['info']['Change Over Timeline'];
        $evalCO = function (array $bd) use ($coIn, $input, $coTl) {
            $x = json_decode(json_encode($coIn), true); $mm = &$x['data3']['modeling'];
            $mm['unit_fix_load'] = (array)($mm['unit_fix_load'] ?? []);
            foreach (pp_tl_gt_units() as $u) { $U = strtoupper($u); $rules = [];
                for ($r = 1; $r <= 48; $r++) { $v = (float)($bd[$r - 1][$U] ?? 0); if ($v > 0.01) $rules[] = ['start' => $r, 'stop' => $r, 'value' => round($v, 4)]; }
                if ($rules) $mm['unit_fix_load'][$u] = $rules; }
            unset($mm);
            try { $o = pp_run_simulation_core($x); } catch (Throwable $e) { return null; }
            $o['info']['Change Over Timeline'] = $coTl;
            $a = pp_tl_assess($input, $o); $a['output'] = $o; return $a;
        };
        $aB = pp_tl_assess($input, $best);
        if (!empty($aB['valid'])) { $aB['output'] = $best; $aB = pp_v6_priority_polish($input, $aB, microtime(true) + 60.0, $evalCO); $best = $aB['output']; }
    }
    return $best;
}

/* =============================================================================================
 *  GLOBAL ECONOMIC COMMITMENT COMPARATOR (root problem:
 *  BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER)
 *
 *  pp_solve_block() memutuskan commitment secara LOKAL per blok per row: ia hanya melihat
 *  $available blok itu, bukan apakah blok lain dapat menyerap beban lebih murah. Akibatnya sebuah
 *  unit dapat di-commit walau alternatif global yang legal lebih baik.
 *
 *  Perbaikan: setelah dispatch selesai, setiap unit yang di-commit TANPA permintaan operator
 *  dievaluasi ulang dengan membangun alternatif global legal tanpa unit tersebut. Pemenang
 *  dipilih comparator leksikografis:
 *    1 operator hard controls (kandidat yang melanggar request dibuang lebih dulu)
 *    2 hard violations   3 Export/Reserve/BusFlow   4 fuel   5 runtime/ramp
 *    6 Unit & Block Priority   7 startup count   8 startup cost
 *    9 Total Cost   10 Cost Production   11 Heat Rate   12 pertahankan unit Running bila setara
 *
 *  Unit yang DIMINTA operator (required_units / required_mode) tidak pernah menjadi kandidat,
 *  sehingga Required G5 tetap dapat start. Mematikan unit dilakukan untuk SELURUH hari sehingga
 *  startup lead-in ikut rollback secara atomik. Dapat dimatikan lewat PP_G5_GLOBAL_CMP=0.
 * =========================================================================================== */
function pp_global_commitment_key(array $in, array $o): array {
    $V  = pp_validate_hard_constraints($in, $o);
    $i  = $o['info'];
    $vt = 0; foreach (($V['violations'] ?? []) as $v) $vt++;
    $m  = $in['data3']['modeling'];
    $rMin = (float)($m['pln_export_priority']['range']['min'] ?? 0);
    $busMin = (float)($m['busflow_min'] ?? 0);
    $need = pp_reserve_min($m);
    $UN = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'];
    $colf = function ($u) { $C = strtoupper($u); return ($C === 'B1' || $C === 'B2') ? 'BB' . substr($C, 1) : $C; };
    $expU = 0; $resU = 0; $busU = 0; $starts = 0; $prev = [];
    foreach (($o['data'] ?? []) as $ri => $r) {
        if ((float)($r['Export_PLN'] ?? 0) < $rMin - 1e-6) $expU++;
        $g = []; foreach ($UN as $u) $g[$u] = (float)($r[$colf($u)] ?? 0);
        if (pp_spinning_reserve($g, $in['data3'], $m, $ri + 1) < $need - 1e-6) $resU++;
        if (calc_busflow($g, (array)($m['bus_unit'] ?? []), (float)($r['IE'] ?? 0)) < $busMin - 1e-6) $busU++;
        foreach ($UN as $u) { if (($prev[$u] ?? 0) <= 0.01 && $g[$u] > 0.01) $starts++; $prev[$u] = $g[$u]; }
    }
    return ['hard'=>$vt,'constraint'=>$expU+$resU+$busU,
            'cp'=>round((float)($i['Total Plant Cost Production (USD/MWh)']??$i['Cost Production (USD/MWh)']??INF),6),
            'jbbk_cp'=>round((float)($i['JBBK MM Cost Production (USD/MWh)']??INF),6),
            'hr'=>round((float)($i['JBBK MM Heat Rate (BTU/kWh)']??INF),4),
            'cost'=>round((float)($i['Total Cost (USD)']??INF),4),'starts'=>$starts,
            'fuel'=>round((float)($i['Gas Fuel Total (BBTUD)']??INF),6)];
}
function pp_global_commitment_better(array $a,array $b): bool {
    foreach(['hard','constraint','cp','jbbk_cp','hr','cost','starts','fuel'] as $k){
        $av=(float)($a[$k]??INF);$bv=(float)($b[$k]??INF);if(abs($av-$bv)>1e-9)return $av<$bv;
    }
    return false;
}

/* =============================================================================================
 *  TARGET SELESAI — PENCARIAN ANYTIME KANDIDAT CONSTRAINT-VALID.
 *
 *  Dipakai hanya bila operator memilih batas waktu (15-60 detik) atau Maximum Review. Pencarian
 *  ini TIDAK menyentuh job exact: ia berjalan di request terpisah dan hanya MENAWARKAN kandidat.
 *  Setiap kandidat adalah dispatch lengkap 48 baris hasil core run engine dengan commitment
 *  tertentu (unit dimatikan sepanjang hari) dan pendaratan gas memakai lever engine sendiri
 *  (__shaper_quota_adjust / __gas_trim_target_bbtud). Kandidat hanya diterima bila lulus SELURUH
 *  pemeriksaan terhadap INPUT ASLI operator: validator hard constraint PASS, Export/Reserve/Bus Flow
 *  tanpa pelanggaran, PLN Export 48/48, window gas total dan supplier PGN, residual shortage 0, dan
 *  tidak terpotong batas waktu. Kandidat invalid tidak pernah disimpan.
 * =========================================================================================== */
function pp_tl_gt_units(): array { return ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10']; }
/* V3: commitment sebuah dispatch 48 baris sebagai jadwal stop per unit GTG (row off berurutan). */
function pp_v3_stops(array $data): array {
    $st = [];
    foreach (pp_tl_gt_units() as $u) {
        $U = strtoupper($u); $r0 = null;
        for ($r = 1; $r <= 49; $r++) {
            $off = $r <= 48 ? ((float)($data[$r - 1][$U] ?? 0) <= 0.01) : false;
            if ($off && $r0 === null) $r0 = $r;
            if (!$off && $r0 !== null) { $st[] = ['unit' => $u, 'start' => $r0, 'stop' => $r - 1]; $r0 = null; }
        }
    }
    return $st;
}
function pp_tl_clean_globals(array $keep = []): void {
    foreach (array_keys($GLOBALS) as $g)
        if (is_string($g) && strpos($g, '__pp_') === 0 && $g !== '__pp_fuel_decision_mode' && !in_array($g, $keep, true)
            && !in_array($g, ['__pp_prelim_rid', '__pp_prelim_t0', '__pp_prelim_steps'], true)) unset($GLOBALS[$g]);   // jejak progres UI (observasi saja)
}
/* Penilaian satu dispatch terhadap input ASLI. valid=true hanya bila semua pemeriksaan lulus. */
function pp_tl_assess(array $orig, array $o): array {
    $rows = count((array)($o['data'] ?? []));
    if ($rows !== 48) return ['valid' => false, 'rows' => $rows, 'dev' => 0.0, 'gas_only' => false, 'key' => null, 'checks' => ['rows_48' => false]];
    $i = (array)($o['info'] ?? []);
    $V = pp_validate_hard_constraints($orig, $o);
    $k = pp_global_commitment_key($orig, $o);
    $q = (float)($i['Total Gas Quota (BBTUD)'] ?? 0);
    $g = (float)($i['Total Gas Used (BBTUD)'] ?? ($i['Gas Fuel Total (BBTUD)'] ?? 0));
    /* Sama dengan validator: bila Actual gas terisi, yang dinilai adalah total EFEKTIF. */
    if ((int)($i['Actual Hours Provided'] ?? 0) > 0 && isset($i['Effective Total Gas (BBTUD)'])) $g = (float)$i['Effective Total Gas (BBTUD)'];
    [$lo, $hi] = pp_gas_window($q);
    $pq = (float)($i['PGN Pipe Quota (BBTUD)'] ?? 0); $pu = (float)($i['PGN Pipe Used (BBTUD)'] ?? 0);
    $types = [];
    foreach ((array)($V['violations'] ?? []) as $v) $types[] = is_array($v) ? (string)($v[0] ?? $v['type'] ?? '?') : '?';
    $checks = [
        'rows_48' => true,
        'hard_constraints' => strtoupper((string)($V['status'] ?? '')) === 'PASS' && (int)$k['hard'] === 0,
        'export_reserve_busflow' => (int)$k['constraint'] === 0,
        'pln_export_48_48' => (string)($i['PLN Export Compliance'] ?? '') === 'OK',
        'gas_window' => $q <= 1.0 || ($g >= $lo - 1e-9 && $g <= $hi + 1e-9),
        'pgn_supplier_window' => $pq <= 1e-9 || ($pu >= $pq - 0.04 - 1e-9 && $pu <= $pq + 1e-9),
        'residual_shortage_zero' => (float)($i['Residual Gas Shortage (BBTUD)'] ?? 0) <= 1e-9,
        'not_truncated' => empty($GLOBALS['__pp_budget_aborts']),
    ];
    /* Pemeriksaan acceptance yang sama dengan release gate (bila tersedia di request ini). */
    if (function_exists('pp_universal_headroom_review')) { try { $checks['universal_headroom'] = strtoupper((string)(pp_universal_headroom_review($orig, $o)['status'] ?? 'FAIL')) === 'PASS'; } catch (Throwable $e) { $checks['universal_headroom'] = false; } }
    if (function_exists('pp_full_horizon_review')) { try { $checks['full_horizon'] = strtoupper((string)(pp_full_horizon_review($orig, $o)['status'] ?? 'FAIL')) === 'PASS'; } catch (Throwable $e) { $checks['full_horizon'] = false; } }
    $valid = !in_array(false, $checks, true);
    $dev = ($q <= 1.0) ? 0.0 : (($g > $hi) ? ($g - $hi) : (($g < $lo) ? -($lo - $g) : 0.0));
    /* Window total sudah terpenuhi tetapi window supplier PGN belum: pendaratan memakai deviasi
     * supplier (lever yang sama menggeser pemakaian pipe searah dengan total). */
    if (abs($dev) <= 1e-9 && $pq > 1e-9 && !$checks['pgn_supplier_window'])
        $dev = ($pu > $pq) ? ($pu - $pq) : -(($pq - 0.04) - $pu);
    /* Dapat didaratkan: selain window gas (total/supplier) tidak ada pemeriksaan lain yang gagal. */
    $gasOnly = (!$types || (count(array_unique($types)) === 1 && $types[0] === 'gas_quota')) && (int)$k['constraint'] === 0
               && $checks['pln_export_48_48'] && $checks['residual_shortage_zero'] && $checks['not_truncated']
               && (!$checks['gas_window'] || !$checks['pgn_supplier_window'] || $types);
    $run = [];
    foreach (pp_tl_gt_units() as $u) { $n = 0; foreach ($o['data'] as $r) if ((float)($r[strtoupper($u)] ?? 0) > 0.01) $n++; if ($n) $run[$u] = $n; }
    return ['valid' => $valid, 'rows' => $rows, 'dev' => $dev, 'gas_only' => $gasOnly, 'key' => $k,
            'checks' => $checks, 'violations' => array_values(array_unique($types)), 'running' => $run];
}
/* Satu kandidat: commitment ($off = unit GTG mati sepanjang hari, $seedStops = jadwal stop
 * tambahan) dengan penyesuaian gas $adj (>0 menaikkan target shaper, <0 target trim). */
/* Target internal supplier PGN yang dipakai kandidat (titik awal bagi kandidat tetangga). */
function pp_tl_supplier_target(array $o): ?float {
    $rv = (array)($o['info']['PGN Supplier Repair Review'] ?? []);
    $obs = (array)($rv['bracket_observations'] ?? []);
    for ($i = count($obs) - 1; $i >= 0; $i--) if (($obs[$i]['side'] ?? '') === 'WINDOW') return (float)$obs[$i]['internal_target'];
    if (isset($rv['under_internal_target'], $rv['over_internal_target']))
        return 0.5 * ((float)$rv['under_internal_target'] + (float)$rv['over_internal_target']);
    return null;
}
function pp_tl_eval(array $orig, array $off, float $adj, float $deadlineTs, array $seedStops = [], ?float $hint = null): ?array {
    if (microtime(true) >= $deadlineTs - 0.2) return null;
    $in = json_decode(json_encode($orig), true);
    $m = &$in['data3']['modeling'];
    $m['unit_stop_time'] = (array)($m['unit_stop_time'] ?? []);
    foreach ($seedStops as $st) $m['unit_stop_time'][] = $st;
    foreach ($off as $u) $m['unit_stop_time'][] = ['unit' => $u, 'start' => 1, 'stop' => 48];
    $m['__tl_no_auto_start'] = true;
    unset($m['__shaper_quota_adjust'], $m['__gas_trim_target_bbtud']);
    if ($adj > 1e-9) $m['__shaper_quota_adjust'] = $adj; elseif ($adj < -1e-9) $m['__gas_trim_target_bbtud'] = -$adj;
    if ($hint !== null && $hint > 0) $m['__tl_supplier_hint'] = $hint;
    $m['time_budget_seconds'] = 60.0; $m['time_budget_max_seconds'] = 60.0;
    unset($m);
    /* V4: KOLAM KANDIDAT BERSAMA. Candidate-state yang sama (input numerik identik) dihitung satu
     * kali oleh proses mana pun; pemanggil lain memakai hasilnya atau menunggu pemiliknya. */
    $ck = function_exists('pp_cs_key') ? pp_cs_key($in) : null; $lk = null;
    $sk = ($ck !== null && function_exists('pp_v5_stopless_key')) ? pp_v5_stopless_key($in) : null; $coverX = null; $aliasV = null;
    if ($ck !== null) {
        $hit = pp_cs_get($ck);
        /* V5: state fisik identik (stop tambahan tidak mengikat) -> hasil kandidat yang sama dipakai. */
        if ($hit === null && $sk !== null) { $coverX = pp_v5_stop_cover((array)$in['data3']['modeling']); $al = pp_v5_alias_find($sk, $coverX);
            if ($al !== null) { if ((string)getenv('PP_V5_ALIAS_VERIFY') === '1') $aliasV = $al; else { $al['off'] = $off; $al['adj'] = $adj; return $al; } } }
        if ($hit === null) { $cl = pp_cs_claim($ck, $deadlineTs); if (is_array($cl['hit'] ?? null)) $hit = $cl['hit']; else $lk = $cl['lock'] ?? null; }
        if ($hit !== null) { $hit['off'] = $off; $hit['adj'] = $adj; return $hit; }
    }
    pp_tl_clean_globals();
    pp_budget_start(60.0, true, true);
    $GLOBALS['__pp_budget_deadline'] = $deadlineTs;          // batas operator, bukan plafon 10 detik
    $__ev0 = microtime(true); $__oc0 = (int)($GLOBALS['__ppx_once_calls'] ?? 0);
    try { $o = pp_run_simulation_core($in); } catch (Throwable $e) { pp_tl_clean_globals(); if ($ck !== null) pp_cs_release($lk); return null; }
    $__evW = microtime(true) - $__ev0; $__oc = (int)($GLOBALS['__ppx_once_calls'] ?? 0) - $__oc0;
    $a = pp_tl_assess($orig, $o);
    if (($__lf = getenv('PP_V5_EVLOG')) !== false && $__lf !== '') { $__rv = (array)($o['info']['PGN Supplier Repair Review'] ?? []);
        @file_put_contents($__lf, json_encode(['pid' => getmypid(), 't' => round(microtime(true), 3), 'wall' => round($__evW, 3), 'assess' => round(microtime(true) - $__ev0 - $__evW, 3), 'once' => $__oc,
            'adj' => $adj, 'off' => $off, 'seed' => count($seedStops), 'hint' => $hint, 'sup_att' => $__rv['attempts'] ?? null, 'sup_mode' => $__rv['search_mode'] ?? null, 'obs' => array_map(function ($b) { return [$b['internal_target'], $b['physical_pipe_used'], substr($b['side'], 0, 1)]; }, (array)($__rv['bracket_observations'] ?? [])),
            'valid' => $a['valid'], 'dev' => round((float)$a['dev'], 4), 'cp' => $a['key']['cp'] ?? null, 'viol' => $a['violations'] ?? null, 'mm' => $o['info']['MM2100 Precision']['used_plus_startup_bbtud'] ?? null]) . "\n", FILE_APPEND); }
    pp_tl_clean_globals();
    $a['output'] = $o; $a['off'] = $off; $a['adj'] = $adj; $a['supplier_target'] = pp_tl_supplier_target($o);
    if ($sk !== null) $a['onmask'] = pp_v5_onmask($o);
    if (!empty($a['valid']) && function_exists('pp_v6_priority_polish') && empty($orig['data3']['modeling']['__v9_nopolish'])) $a = pp_v6_priority_polish($orig, $a, $deadlineTs);   // V6: counterfactual Unit Priority (V9: kandidat local search dinilai mentah, polish pada pemenang)
    if ($aliasV !== null && ($__al = getenv('PP_V5_ALIAS_LOG'))) @file_put_contents($__al, json_encode(['ck' => $ck, 'alias_of' => $aliasV['alias_of'] ?? null,
        'same_cp' => ($aliasV['key']['cp'] ?? null) === ($a['key']['cp'] ?? null), 'same_dev' => ($aliasV['dev'] ?? null) === ($a['dev'] ?? null),
        'same_valid' => ($aliasV['valid'] ?? null) === ($a['valid'] ?? null), 'same_onmask' => ($aliasV['onmask'] ?? null) == $a['onmask'],
        'cp' => [$aliasV['key']['cp'] ?? null, $a['key']['cp'] ?? null]]) . "\n", FILE_APPEND);
    if ($ck !== null) { if (!empty($a['checks']['not_truncated'])) { pp_cs_put($ck, $a); if ($sk !== null) pp_v5_alias_add($sk, $ck, $coverX ?? pp_v5_stop_cover((array)$in['data3']['modeling'])); } pp_cs_release($lk); $a['cs_key'] = $ck; }
    return $a;
}
/* Satu commitment tetap: redispatch lalu pendaratan window gas dengan lever engine sendiri.
 * Dipakai tahap VALID PROVISIONAL (commitment hasil final terakhir). Mengembalikan penilaian
 * terakhir (valid atau tidak) beserta jumlah evaluasi. */
function pp_tl_land_commitment(array $orig, array $off, array $seedStops, float $deadlineTs, int $maxEvals = 5, bool $dual = false): array {
    $R = pp_tl_land_commitment_one($orig, $off, $seedStops, $deadlineTs, $maxEvals);
    /* V5 DOMINANSI TRIM: node keluarga commitment didefinisikan sebagai pendaratan TERMURAH dari dua mode trim
     * sisa window supplier (trim pada state penghasil sisa = V5, trim pada input kontrak = V4). Bila pendaratan
     * V5 valid lewat trim, pendaratan V4 atas commitment yang sama ikut dihitung dan yang lebih murah (urutan
     * comparator engine) dipakai — node keluarga V5 tidak pernah lebih mahal dari node yang sama di V4. */
    $a = $R['a'];
    if ($dual && is_array($a) && !empty($a['valid']) && (string)getenv('PP_V5_TRIM_DUAL') !== '0' && (string)getenv('PP_V5_TRIM_FIX') !== '0'
        && !empty($a['output']['info']['PGN Supplier Direct Trim']['applied']) && microtime(true) < $deadlineTs - 1.0) {
        $origL = $orig; $origL['data3']['modeling']['__v5_trim_legacy'] = true;
        $L = pp_tl_land_commitment_one($origL, $off, $seedStops, $deadlineTs, $maxEvals);
        $R['evals'] += (int)$L['evals']; $b = $L['a'];
        if (is_array($b) && !empty($b['valid']) && is_array($b['key'] ?? null) && pp_global_commitment_better($b['key'], (array)$a['key'])) { $b['trim_mode'] = 'V4'; $R['a'] = $b; }
        else $R['a']['trim_mode'] = 'V5';
        $R['dual'] = true;
    }
    return $R;
}
function pp_tl_land_commitment_one(array $orig, array $off, array $seedStops, float $deadlineTs, int $maxEvals = 5): array {
    $a = pp_tl_eval($orig, $off, 0.0, $deadlineTs, $seedStops, null);
    $n = 1;
    if ($a === null) return ['a' => null, 'evals' => 0];
    $best = $a; $adj = 0.0; $under = null; $over = null; $step = null;
    while (!$a['valid'] && $a['gas_only'] && abs($a['dev']) > 1e-9 && $n < $maxEvals) {
        if ($a['dev'] > 0) $over = $adj; else $under = $adj;
        if ($under !== null && $over !== null) $adj = 0.5 * ($under + $over);
        else { $step = ($step === null) ? abs($a['dev']) + 0.004 : $step * 2.0; $adj += ($a['dev'] > 0 ? -$step : $step); }
        $x = pp_tl_eval($orig, $off, $adj, $deadlineTs, $seedStops, $a['supplier_target'] ?? null);
        if ($x === null) break;
        $n++; $a = $x;
        if ($a['valid'] || abs($a['dev']) < abs($best['dev'])) $best = $a;
    }
    return ['a' => $a['valid'] ? $a : $best, 'evals' => $n];
}
/* =============================================================================================
 *  RUANG KANDIDAT EXACT — KELUARGA COMMITMENT LENGKAP (dipakai Maximum Review DAN Target Selesai).
 *
 *  Definisi (generik, tanpa skenario khusus):
 *    node akar      = input operator apa adanya;
 *    anak sebuah node = node itu + satu unit GTG eligible yang BERJALAN pada dispatch node tersebut,
 *                     dimatikan sepanjang hari (mematikan unit yang memang tidak berjalan tidak
 *                     mengubah apa pun, sehingga bukan kandidat baru);
 *    unit eligible  = hadir, tidak Cannot Stop / Required / required_mode, tidak ber-Fix Load, dan
 *                     bukan Last Data Running (aturan batas row 1 yang sama dengan decommit);
 *    seed           = commitment hasil FINAL terakhir untuk perubahan kuota sederhana (bila ada).
 *  Setiap node dievaluasi SEKALI: redispatch 48 row, lalu window gas didaratkan dengan lever engine
 *  sendiri (maks. 5 evaluasi), kemudian dinilai terhadap input asli dengan pemeriksaan penuh.
 *  Hasilnya disimpan di registri bersama (per state input) sehingga Target Selesai, Maximum Review,
 *  job exact, dan run berikutnya tidak pernah menghitung node yang sama dua kali. Keluarga dinyatakan
 *  LENGKAP hanya bila seluruh node selesai dievaluasi tanpa terpotong waktu.
 * =========================================================================================== */
class PpTlRegistry {
    public $nodes = []; public $outs = [];
    public function get(string $nk) { return $this->nodes[$nk] ?? null; }
    public function output(string $nk) { return $this->outs[$nk] ?? null; }
    public function claim(string $nk): bool { return true; }
    public function claimedByOther(string $nk): bool { return false; }
    public function put(string $nk, array $sum, ?array $out): void { $this->nodes[$nk] = $sum; if ($out !== null) $this->outs[$nk] = $out; }
    public function release(string $nk): void {}
    public function refresh(): void {}
    public function markComplete(array $meta): void {}
    public function allValid(): array { $r = []; foreach ($this->nodes as $nk => $sm) if (!empty($sm['valid'])) $r[$nk] = $sm; return $r; }
    public function allNodes(): array { return $this->nodes; }
}
function pp_tl_node_key(array $off, array $seed = []): string {
    sort($off);
    return $seed ? 'seed:' . substr(md5(json_encode($seed)), 0, 16) . ($off ? '+' . implode(',', $off) : '') : 'off:' . implode(',', $off);
}
function pp_tl_eligible_units(array $orig): array {
    $d3 = (array)($orig['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $fixed = [];
    foreach ((array)($m['unit_cannot_stop'] ?? []) as $u) $fixed[strtolower((string)$u)] = true;
    foreach ((array)($m['required_units'] ?? []) as $u) if (is_string($u)) $fixed[strtolower($u)] = true;
    foreach (array_keys((array)($m['required_mode'] ?? [])) as $u) $fixed[strtolower((string)$u)] = true;
    $lds = (array)($m['unit_last_data_status'] ?? []);
    $el = [];
    foreach (pp_tl_gt_units() as $u) {
        if (!empty($fixed[$u]) || !isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
        if (strtolower((string)($lds[strtoupper($u)] ?? $lds[$u] ?? '')) === 'running') continue;
        $hasFix = false; for ($r = 1; $r <= 48; $r++) if (pp_get_fixed_load($m, $u, $r) >= 0) { $hasFix = true; break; }
        if ($hasFix) continue;
        $el[$u] = true;
    }
    return $el;
}
/* V11 PREFETCH SPEKULATIF KELUARGA COMMITMENT. Keluarga sering berupa rantai (off:[] -> off:g5 -> off:g2,g5 -> ...): setiap
 * tingkat menunggu hasil induknya sehingga pekerja pembantu menganggur. Selagi menunggu, proses ini mendaratkan (tanpa
 * menyentuh registri) anak yang paling mungkin dari node pending: P + u untuk u = unit eligible yang running pada induk P
 * (urutan perluasan yang sama), lalu unit eligible lain menurut prioritas. Hasilnya hanya masuk cache kandidat-state
 * berkunci input identik (pp_tl_eval), sehingga saat node itu benar-benar dicapai, pendaratannya cache hit dengan hasil
 * bit-identik. Pengamat Target Selesai/penghitung dimatikan selama prefetch (kandidat spekulatif bukan kandidat ruang
 * exact). Satu pendaratan per panggilan; penanda eksklusif lintas proses mencegah kerja ganda. PP_V11_FAMILY_SPEC=0 mematikan. */
function pp_v11_family_spec(array $orig, array $pending, $reg, array $el, float $dl, ?array $refRows): bool {
    if ((string)getenv('PP_V11_FAMILY_SPEC') === '0' || !function_exists('pp_cs_file') || microtime(true) > $dl - 8.0) return false;
    if (empty($GLOBALS['ppTlHook']['helper'])) return false;     // hanya pekerja pembantu: pemilik tidak boleh tertahan oleh prefetch
    $m = (array)($orig['data3']['modeling'] ?? []); $rank = function_exists('pp_priority_rank') ? pp_priority_rank($m) : [];
    $lds = (array)($m['unit_last_data_status'] ?? []); $st = substr(md5(json_encode(pp_tl_key($orig))), 0, 12);
    foreach ($pending as $p) {
        [$off, $seed] = $p; if ($seed) continue; sort($off);
        /* unit running pada induk yang sudah selesai (P tanpa satu unit) -> urutan perluasan engine */
        $guess = [];
        foreach ($off as $x) { $par = array_values(array_diff($off, [$x])); $ps = $reg->get(pp_tl_node_key($par));
            if (!is_array($ps)) continue; $ord = [];
            foreach ((array)($ps['running'] ?? []) as $u => $n) { if (empty($el[$u]) || in_array($u, $off, true)) continue;
                $wasOn = strtolower((string)($lds[strtoupper($u)] ?? $lds[$u] ?? '')) === 'running'; $ord[$u] = [$wasOn ? 1 : 0, $wasOn ? $n : -$n, $u]; }
            uasort($ord, function ($a, $b) { return $a <=> $b; }); foreach (array_keys($ord) as $u) $guess[$u] = true; break; }
        $rest = array_values(array_diff(array_keys($el), $off, array_keys($guess)));
        usort($rest, function ($a, $b) use ($rank) { return [(int)($rank[$a] ?? 99), $a] <=> [(int)($rank[$b] ?? 99), $b]; });
        foreach (array_slice(array_merge(array_keys($guess), $rest), 0, 2) as $u) {   // dua tebakan teratas per node pending
            $n2 = array_merge($off, [$u]); sort($n2); $k2 = pp_tl_node_key($n2);
            if ($reg->get($k2) !== null || $reg->claimedByOther($k2)) continue;
            if ($refRows !== null && function_exists('pp_v10_export_capacity_proof') && function_exists('pp_v3_commitment_stops')
                && pp_v10_export_capacity_proof($orig, $refRows, pp_v3_commitment_stops(['off' => $n2, 'seed' => []])) !== null) continue;
            $mk = pp_cs_file('spec_' . $st . '_' . md5($k2), '.mark'); $h = @fopen($mk, 'x'); if (!$h) continue; @fclose($h);
            $hasHook = is_array($GLOBALS['ppTlHook'] ?? null); if ($hasHook) $GLOBALS['ppTlHook']['spec'] = true;   // abort Target Selesai tetap dipantau
            $saved = []; foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
            try { pp_tl_land_commitment($orig, $n2, [], $dl, 5, true); } catch (PpJobAborted $e) { throw $e; } catch (Throwable $e) {}
            finally { pp_tl_clean_globals(); foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv; if ($hasHook && is_array($GLOBALS['ppTlHook'] ?? null)) unset($GLOBALS['ppTlHook']['spec']); }
            if (function_exists('pp_v10_scr')) pp_v10_scr('family', 'v11_spec_prefetch');
            return true;
        }
    }
    return false;
}
/* Ringkasan node yang disimpan di registri (tanpa dispatch). */
function pp_tl_node_summary(array $a, int $evals, float $wall): array {
    return ['valid' => (bool)$a['valid'], 'key' => $a['key'], 'dev' => $a['dev'], 'gas_only' => $a['gas_only'],
            'running' => $a['running'], 'violations' => $a['violations'], 'checks' => $a['checks'],
            'off' => $a['off'] ?? [], 'adj' => $a['adj'] ?? 0.0, 'evals' => $evals, 'wall_s' => round($wall, 3),
            'cost' => $a['output']['info']['Total Cost (USD)'] ?? null,
            'cost_production' => $a['output']['info']['Cost Production (USD/MWh)'] ?? null];
}
/* Pencarian keluarga (anytime dan lengkap). Urutan evaluasi deterministik; setiap node independen
 * dari jalur penemuannya, sehingga hasil tidak bergantung pada siapa yang menghitung lebih dulu. */
function pp_tl_family_search(array $orig, float $deadlineTs, $reg, array $opt = []): array {
    $m = (array)($orig['data3']['modeling'] ?? []);
    $t0 = microtime(true);
    if (!empty($m['change_over']['enabled']))
        return ['applicable' => false, 'complete' => true, 'reason' => 'CHANGE_OVER_PUNYA_RUANG_KANDIDAT_SENDIRI', 'nodes' => 0, 'evaluated' => 0, 'valid' => 0, 'best' => null, 'valid_nodes' => []];
    $maxNodes = (int)($opt['max_nodes'] ?? 64);
    $stop = $opt['stop'] ?? null; $offer = $opt['offer'] ?? null; $tick = $opt['tick'] ?? null;
    $el = pp_tl_eligible_units($orig);
    $lds = (array)($m['unit_last_data_status'] ?? []);
    $blkReq = [];
    foreach ((array)($m['block_priority'] ?? []) as $blk) {
        $isReq = in_array('required', array_map('strtolower', array_map('strval', (array)$blk)), true);
        foreach ((array)$blk as $u) if (is_string($u)) $blkReq[strtolower($u)] = $isReq;
    }
    $queue = [];
    foreach ((array)($opt['seeds'] ?? []) as $sd) if (is_array($sd) && $sd) $queue[] = [[], $sd];
    $queue[] = [[], []];
    $seen = []; $pending = []; $ev = 0; $va = 0; $nodes = 0; $computed = 0; $reused = 0; $refRows = null;
    $best = null; $bestNk = null; $validNodes = []; $complete = true; $why = null;
    $useNode = function (string $nk, array $sum) use (&$best, &$bestNk, &$validNodes, &$va, $offer, $reg) {
        if (!empty($sum['valid']) && is_array($sum['key'] ?? null)) {
            $va++; $validNodes[$nk] = $sum;
            if ($best === null || pp_global_commitment_better($sum['key'], $best['key'])) { $best = $sum; $bestNk = $nk; }
            if ($offer !== null) { $o = $reg->output($nk); if (is_array($o)) $offer($sum + ['output' => $o]); }
        }
    };
    $expand = function (array $off, array $seed, array $sum) use (&$queue, &$seen, $el, $lds, $blkReq) {
        if ($seed) return;                                   // seed tidak diperluas (commitment parsial tetap)
        $ord = [];
        foreach ((array)($sum['running'] ?? []) as $u => $n) {
            if (empty($el[$u]) || in_array($u, $off, true)) continue;
            $wasOn = strtolower((string)($lds[strtoupper($u)] ?? $lds[$u] ?? '')) === 'running';
            $ord[$u] = [!empty($blkReq[$u]) ? 1 : 0, $wasOn ? 1 : 0, $wasOn ? $n : -$n, $u];
        }
        uasort($ord, function ($x, $y) { return $x <=> $y; });
        foreach (array_keys($ord) as $u) { $n2 = array_merge($off, [$u]); sort($n2); $k2 = pp_tl_node_key($n2); if (!isset($seen[$k2])) { $seen[$k2] = true; $queue[] = [$n2, []]; } }
    };
    foreach ($queue as $q) $seen[pp_tl_node_key($q[0], $q[1])] = true;
    while ($queue || $pending) {
        if ($stop !== null && $stop()) { $complete = false; $why = 'DIHENTIKAN'; break; }
        if (microtime(true) >= $deadlineTs - 0.3) { $complete = false; $why = 'BATAS_WAKTU'; break; }
        if ($nodes >= $maxNodes) { $complete = false; $why = 'BATAS_JUMLAH_NODE'; break; }
        if (!$queue) {                                        // tunggu node yang sedang dihitung proses lain
            $reg->refresh(); $moved = false;
            foreach ($pending as $i => $p) {
                $nk = pp_tl_node_key($p[0], $p[1]); $s = $reg->get($nk);
                if ($s !== null || !$reg->claimedByOther($nk)) { $queue[] = $p; unset($pending[$i]); $moved = true; }
            }
            $pending = array_values($pending);
            /* V11: selagi menunggu node yang dihitung proses lain, proses ini memanaskan cache kandidat-state (kunci identik)
             * untuk anak node yang paling mungkin (prefetch spekulatif). Registri, himpunan node, urutan, dan pemenang tidak
             * berubah — hanya pendaratan node berikutnya yang menjadi cache hit. */
            if (!$moved && !(function_exists('pp_v11_family_spec') && pp_v11_family_spec($orig, $pending, $reg, $el, $deadlineTs, $refRows))) usleep(200000);
            continue;
        }
        [$off, $seed] = array_shift($queue); $nk = pp_tl_node_key($off, $seed);
        $sum = $reg->get($nk);
        if ($sum === null) {
            if ($reg->claimedByOther($nk)) { $pending[] = [$off, $seed]; continue; }
            if (!$reg->claim($nk)) { $pending[] = [$off, $seed]; continue; }
            $sum = $reg->get($nk);                            // V4: selesai dihitung proses lain sebelum klaim
            if ($sum !== null) { $reg->release($nk); $reused++; $nodes++; if ($tick !== null) $tick($nodes, $va, $ev); $useNode($nk, $sum); $expand($off, $seed, $sum); continue; }
            $tn = microtime(true);
            /* V10 TIER-1: node yang terbukti infeasible oleh batas kapasitas Export tidak disimulasikan; supersetnya
             * (anak node) juga terbukti infeasible (monoton), sehingga node ini tidak diperluas. */
            if ($refRows !== null && function_exists('pp_v10_export_capacity_proof') && function_exists('pp_v3_commitment_stops')) {
                $cpf = pp_v10_export_capacity_proof($orig, $refRows, pp_v3_commitment_stops(['off' => $off, 'seed' => $seed]));
                if ($cpf !== null) {
                    $sum = ['valid' => false, 'key' => null, 'dev' => 0.0, 'gas_only' => false, 'running' => [], 'violations' => ['export_range'], 'checks' => [],
                            'off' => $off, 'adj' => 0.0, 'evals' => 0, 'wall_s' => 0.0, 'prescreen' => $cpf, 'screened_v10' => 'INFEASIBLE_KAPASITAS_EXPORT'];
                    if ($seed) $sum['seed'] = $seed;
                    $reg->put($nk, $sum, null); $reg->release($nk); $computed++; $nodes++;
                    if (function_exists('pp_v10_scr')) pp_v10_scr('family', 'pruned_capacity');
                    if (function_exists('pp_v11_cnt_screen')) pp_v11_cnt_screen(pp_v3_commitment_stops(['off' => $off, 'seed' => $seed]));
                    if ($tick !== null) $tick($nodes, $va, $ev);
                    continue;
                }
            }
            $L = pp_tl_land_commitment($orig, $off, $seed, $deadlineTs, 5, true);
            $a = $L['a'];
            if ($refRows === null && is_array($a) && count((array)($a['output']['data'] ?? [])) === 48) $refRows = array_values((array)$a['output']['data']);
            if (function_exists('pp_v10_scr')) pp_v10_scr('family', 'full_landing');
            if ($a === null || empty($a['checks']['not_truncated'])) { $reg->release($nk); $complete = false; $why = 'EVALUASI_TERPOTONG_WAKTU'; break; }
            $a['off'] = $off;
            $sum = pp_tl_node_summary($a, (int)$L['evals'], microtime(true) - $tn);
            if ($seed) $sum['seed'] = $seed;                  // V3: commitment node dapat didaratkan ulang
            $reg->put($nk, $sum, !empty($a['valid']) ? $a['output'] : null);
            $reg->release($nk);
            $ev += (int)$L['evals']; $computed++;
        } else $reused++;
        $nodes++;
        if ($tick !== null) $tick($nodes, $va, $ev);
        $useNode($nk, $sum);
        $expand($off, $seed, $sum);
    }
    if ($complete) $reg->markComplete(['nodes' => $nodes, 'best' => $bestNk]);
    return ['applicable' => true, 'complete' => $complete, 'reason' => $why, 'nodes' => $nodes, 'computed' => $computed,
            'reused' => $reused, 'evaluated' => $ev, 'valid' => $va, 'best' => $best, 'best_node' => $bestNk,
            'valid_nodes' => $validNodes, 'wall_s' => round(microtime(true) - $t0, 2), 'eligible_units' => array_keys($el)];
}
/* Tahap akhir exact: pemenang = Cost Production terendah (urutan comparator engine) dari seluruh
 * kandidat eligible yang constraint-valid: hasil pipeline beserta alternatif comparator globalnya,
 * dan seluruh node keluarga commitment. Bila keluarga belum lengkap, review ekonomi dinyatakan
 * belum selesai (tidak ada klaim global optimum). */
function pp_exact_family_stage(array $input, array $out): array {
    if ((string)getenv('PP_EXACT_FAMILY') === '0') return $out;
    $m = (array)($input['data3']['modeling'] ?? []);
    if (!empty($m['change_over']['enabled']) || !empty($m['__tl_no_auto_start']) || !empty($m['__no_exact_family'])) return $out;
    /* V10: kandidat comparator yang dicakup keluarga hanya lengkap bila tahap keluarga benar-benar berjalan. */
    $covFail = function (array $o): array { if (!empty($o['info']['Global Commitment Review']['v10_family_coverage_pending'])) $o['info']['Global Commitment Review']['all_candidates_evaluated'] = false; return $o; };
    if (count((array)($out['data'] ?? [])) !== 48) return $covFail($out);
    if (!empty($GLOBALS['ppFamilyBusy'])) return $covFail($out);
    /* Pipeline sudah dinyatakan belum lengkap (dipotong/ditunda anggaran): hasil ini tidak final dan
     * job exact akan menjalankan tahap ini dengan anggaran penuh — jangan dihitung dua kali. */
    if (($GLOBALS['__pp_econ_review_skipped'] ?? null) !== null || !empty($GLOBALS['__pp_budget_aborts'])) return $covFail($out);
    $GLOBALS['ppFamilyBusy'] = true;
    $saved = [];
    foreach ($GLOBALS as $gk => $gv) if (is_string($gk) && strpos($gk, '__pp_') === 0) $saved[$gk] = $gv;
    $orig = $input;
    foreach (array_keys((array)$orig['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($orig['data3']['modeling'][$mk]);
    $reg = function_exists('pp_tl_registry_open') ? pp_tl_registry_open($orig) : new PpTlRegistry();
    $seeds = function_exists('pp_tl_seed_stops') ? pp_tl_seed_stops($orig) : [];
    $dl = isset($saved['__pp_budget_deadline']) ? (float)$saved['__pp_budget_deadline'] - 4.0 : microtime(true) + 1500.0;
    $t0 = microtime(true);
    $trk = $GLOBALS['ppExactTrack'] ?? null; unset($GLOBALS['ppExactTrack']);   // node keluarga dinilai sendiri
    $seedList = ($seeds && isset($seeds[0]) && is_array($seeds[0]) && !isset($seeds[0]['unit'])) ? $seeds : ($seeds ? [$seeds] : []);
    $hkF = $GLOBALS['ppTlHook'] ?? null;
    $offerF = (is_array($hkF) && function_exists('pp_tl_pool_offer')) ? function (array $a) use ($hkF) { pp_tl_pool_offer($hkF['key'] . '_x', $a, 'exact_family', (string)$hkF['job']); } : null;
    try { $F = pp_tl_family_search($orig, $dl, $reg, ['seeds' => $seedList, 'max_nodes' => 64, 'offer' => $offerF]); }
    catch (Throwable $e) { $F = ['applicable' => true, 'complete' => false, 'reason' => 'GALAT: ' . $e->getMessage(), 'nodes' => 0, 'valid_nodes' => [], 'best' => null]; }
    pp_tl_clean_globals();
    foreach ($saved as $gk => $gv) $GLOBALS[$gk] = $gv;
    unset($GLOBALS['ppFamilyBusy']);
    /* V6: pemenang pipeline dan incumbent terevaluasi dipoles Unit Priority dengan evaluator yang sama
     * seperti node keluarga, sehingga perbandingan ruang kandidat adil. */
    if (function_exists('pp_v6_priority_polish')) {
        $aP = pp_tl_assess($orig, $out);
        if (!empty($aP['valid'])) { $aP['output'] = $out; $aP = pp_v6_priority_polish($orig, $aP, $dl); $out = $aP['output']; }
        if (is_array($trk) && is_array($trk['best'] ?? null) && is_array($trk['best']['output'] ?? null))
            $trk['best'] = pp_v6_priority_polish($orig, $trk['best'] + ['valid' => true], $dl);
    }
    $incKey = pp_global_commitment_key($orig, $out);
    $incValid = ((int)$incKey['hard'] === 0 && (int)$incKey['constraint'] === 0);
    $gcr = (array)($out['info']['Global Commitment Review'] ?? []);
    $ladder = [];
    foreach ((array)($gcr['ladder'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $k = (array)($c['key'] ?? []);
        if (empty($c['excluded']) && ((int)($k['hard'] ?? 1) > 0 || (int)($k['constraint'] ?? 1) > 0)) {
            $c['excluded'] = true; $c['exclusion_reason'] = 'TIDAK_CONSTRAINT_VALID';
        }
        $ladder[] = $c;
    }
    $winnerNk = null; $winnerSum = null;
    /* Seluruh node valid di registri state ini (termasuk yang dihitung pencarian Target Selesai
     * atau run lain) ikut dibandingkan, sehingga tidak ada kandidat valid yang pernah ditemukan
     * untuk state ini yang lebih murah daripada hasil exact. */
    $candNodes = (array)($F['valid_nodes'] ?? []);
    if (!(function_exists('pp_v9_canon') && pp_v9_canon())) { try { foreach ($reg->allValid() as $nk => $s) if (!isset($candNodes[$nk]) && is_array($s['key'] ?? null)) $candNodes[$nk] = $s; } catch (Throwable $e) {} }
    $incT = is_array($trk) ? ($trk['best'] ?? null) : null;
    if (is_array($incT) && is_array($incT['key'] ?? null)) {
        $candNodes['incumbent:evaluated'] = ['valid' => true, 'key' => $incT['key'], 'off' => [], 'checks' => $incT['checks'], 'running' => $incT['running']];
        $reg->outs['incumbent:evaluated'] = $incT['output'];
    }
    foreach ($candNodes as $nk => $s) {
        $better = $winnerSum === null ? pp_global_commitment_better($s['key'], $incKey) : pp_global_commitment_better($s['key'], $winnerSum['key']);
        $ladder[] = ['unit' => $nk, 'variant' => $nk === 'incumbent:evaluated' ? 'EXACT_EVALUATED_INCUMBENT' : 'EXACT_COMMITMENT_FAMILY', 'key' => $s['key'], 'incumbent_key' => $incKey, 'better' => $better];
        if ($better) { $winnerNk = $nk; $winnerSum = $s; }
    }
    $space = ['schema' => 'co12-exact-candidate-space-v1',
        'definition' => 'pipeline exact + alternatif comparator global + keluarga commitment (unit GTG eligible yang berjalan dimatikan sepanjang hari, bertingkat, dengan pendaratan window gas) + seed commitment final terakhir',
        'family_complete' => !empty($F['complete']), 'family_nodes' => (int)($F['nodes'] ?? 0),
        'family_nodes_computed' => (int)($F['computed'] ?? 0), 'family_nodes_reused' => (int)($F['reused'] ?? 0),
        'family_core_evaluations' => (int)($F['evaluated'] ?? 0), 'family_valid' => count((array)($F['valid_nodes'] ?? [])),
        'registry_valid_candidates' => count($candNodes), 'registry_valid_nodes' => count($candNodes) - (isset($candNodes['incumbent:evaluated']) ? 1 : 0), 'evaluated_core_dispatches' => is_array($trk) ? (int)$trk['n'] : null,
        'evaluated_core_dispatches_valid' => is_array($trk) ? (int)$trk['v'] : null,
        'family_stop_reason' => $F['reason'] ?? null, 'family_wall_s' => round(microtime(true) - $t0, 2),
        'eligible_units' => $F['eligible_units'] ?? [],
        'pipeline_winner_stops' => pp_v3_stops((array)$out['data']), 'pipeline_winner_key' => $incKey,
        'evaluated_incumbent_stops' => (is_array($incT) && is_array($incT['output']['data'] ?? null)) ? pp_v3_stops((array)$incT['output']['data']) : null,
        'evaluated_incumbent_key' => is_array($incT) ? ($incT['key'] ?? null) : null,
        'pipeline_winner_valid' => $incValid, 'pipeline_winner_cost_production' => $incKey['cp'] ?? null,
        'winner_source' => $winnerNk === null ? 'PIPELINE' : ($winnerNk === 'incumbent:evaluated' ? 'EVALUATED_INCUMBENT' : 'COMMITMENT_FAMILY'), 'winner_node' => $winnerNk];
    if ($winnerNk !== null) {
        $new = $reg->output($winnerNk);
        if (is_array($new) && count((array)($new['data'] ?? [])) === 48) {
            $keepInfo = ['Export Minimization Two Phase', 'Commitment Audit', 'Pipeline Call Counts'];
            foreach ($keepInfo as $kk) if (isset($out['info'][$kk]) && !isset($new['info'][$kk])) $new['info'][$kk . ' (pipeline)'] = $out['info'][$kk];
            $out = $new;
            $space['winner_cost_production'] = $winnerSum['key']['cp'] ?? null;
        } else { $space['winner_source'] = 'PIPELINE'; $space['winner_node_output_missing'] = $winnerNk; $winnerNk = null; }
    }
    $gcr['ladder'] = $ladder;
    $gcr['candidates_evaluated'] = (int)($gcr['candidates_evaluated'] ?? 0) + count($candNodes);
    $gcr['candidates_total'] = (int)($gcr['candidates_total'] ?? 0) + count($candNodes);
    $gcr['all_candidates_evaluated'] = (($gcr['all_candidates_evaluated'] ?? true) !== false) && !empty($F['complete']);
    if (!empty($gcr['v10_family_coverage_pending'])) { $gcr['v10_family_coverage_pending'] = false; $gcr['v10_family_coverage'] = !empty($F['complete']) ? 'LENGKAP' : 'KELUARGA_BELUM_LENGKAP'; }
    if ($winnerNk !== null) $gcr['units_dropped'] = array_map('strtoupper', (array)($winnerSum['off'] ?? []));
    $gcr['exact_candidate_space'] = $space;
    $out['info']['Global Commitment Review'] = $gcr;
    $out['info']['Exact Candidate Space'] = $space;
    if (empty($F['complete']) && !empty($F['applicable'])) {
        if (($GLOBALS['__pp_econ_review_skipped'] ?? null) === null)
            $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'KELUARGA_COMMITMENT_BELUM_LENGKAP:' . (string)($F['reason'] ?? '?'),
                'async_completion_required' => true, 'family_nodes' => (int)($F['nodes'] ?? 0)];
    }
    return $out;
}
/* Jumlah kandidat comparator global pada sebuah hasil — dibaca dari provenance yang sama persis
 * dengan yang dipakai pp_global_commitment_review(), sehingga gerbang anggaran waktu di bawah
 * memperkirakan biaya dari JUMLAH KANDIDAT NYATA, bukan dari konstanta. Nol kandidat berarti
 * comparator selesai tanpa pipeline tambahan sama sekali. */
function pp_global_commitment_candidate_count(array $out): int {
    if ((string)getenv('PP_G5_GLOBAL_CMP') === '0') return 0;
    $n = 0;
    foreach ((array)($out['info']['Startup Provenance'] ?? []) as $p) {
        if (!is_array($p)) continue;
        if (($p['root_problem'] ?? null) !== 'BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER') continue;
        if (!empty($p['requested'])) continue;
        $n++;
    }
    return $n;
}
/* Perkiraan biaya comparator global, dalam detik, dari pengukuran nyata.
 *   biaya = jumlah_kandidat x waktu_pipeline_yang_baru_selesai x FAKTOR + penutupan
 * FAKTOR diturunkan dari pengukuran, bukan tebakan: satu kandidat menjalankan tahap yang sama
 * dengan pipeline luar (baseline core, mandatory stop, decommit, koreksi window gas, offset
 * search) TETAPI pada rencana yang kehilangan satu unit, sehingga pass perbaikannya beriterasi
 * lebih banyak. Terukur pada input produksi PGN 30: pipeline luar sampai gerbang 15,40 detik
 * (9 core run), comparator 1 kandidat 30,14 detik (30 core run) = 1,96x. Dipakai 2,0 sebagai
 * batas atas terkalibrasi. Perkiraan lama (0,75x TANPA jumlah kandidat) meleset 2,6x ke arah
 * yang berbahaya: comparator dimulai lalu dipotong plafon. */
function pp_global_commitment_time_estimate(float $elapsedPipeline, int $candidates): float {
    if ($candidates <= 0) return 0.5;                 // tanpa kandidat: hanya penulisan evidence
    /* HANYA UNTUK MEMULAI KANDIDAT PERTAMA. Biaya kandidat berikutnya TIDAK diramalkan lagi —
     * ia DIUKUR di dalam pp_global_commitment_review(). Faktor 1,25 adalah biaya satu pipeline
     * kandidat relatif pipeline luar yang baru selesai (tahapnya sama), ditambah margin kecil;
     * perkiraan lama 2,0 per kandidat TANPA pengukuran menolak comparator yang sebenarnya muat
     * (terukur: PGN 30 ditolak pada sisa 40,1 detik untuk pekerjaan ~39 detik; KP72 aktif ditolak
     * pada sisa 47 detik untuk pekerjaan ~38 detik). */
    return max(0.5, $elapsedPipeline) * 1.25 + PP_GCR_CLOSING_RESERVE_S;
}
/* Cadangan waktu untuk menutup respons (validasi akhir + serialisasi JSON) setelah comparator. */
const PP_GCR_CLOSING_RESERVE_S = 4.0;

function pp_global_commitment_review(array $input, array $out): array {
    /* DEFAULT NONAKTIF — comparator ini BELUM aman diaktifkan.
     * Cacat yang terbukti pada pengujian: alternatif global yang dipilih dapat MELANGGAR
     * presence unit lain (T-UNIT-PRESENT-08: S1 dengan unit_present=0 ikut berjalan 17 row),
     * dan hasilnya menggeser angka MM2100 yang sudah dibekukan (JBBK MM Prod 8401,4 -> 8387,5).
     * Kode dipertahankan lengkap beserta evidence; aktifkan hanya untuk investigasi dengan
     * PP_G5_GLOBAL_CMP=1 setelah cacat presence diperbaiki. */
    /* DEFAULT PRODUKSI = OPSI C (keputusan domain final):
     *   PP_G5_GLOBAL_CMP = ON · PP_G5_CMP_ORDER = fuel_last · PP_G5_FUEL_TOL = 0
     * Gas quota tetap HARD CAP tanpa toleransi: kandidat yang melanggar dikeluarkan sebagai
     * INFEASIBLE sebelum comparator dijalankan. Dapat dimatikan dengan PP_G5_GLOBAL_CMP=0. */
    if ((string)getenv('PP_G5_GLOBAL_CMP') === '0') return $out;
    if (!empty($GLOBALS['__pp_gcmp_busy'])) return $out;
    $prov = (array)($out['info']['Startup Provenance'] ?? []);
    $cands = [];
    foreach ($prov as $U => $p) {
        if (($p['root_problem'] ?? null) !== 'BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER') continue;
        if (!empty($p['requested'])) continue;
        $cands[] = strtolower((string)$p['unit']);
    }
    /* NOL KANDIDAT = REVIEW SELESAI, BUKAN REVIEW TIDAK BERJALAN.
     * Sebelumnya blok evidence tidak ditulis sama sekali bila tidak ada kandidat, sehingga
     * konsumen (Run Status, release gate, validasi kandidat LNG/distillate) tidak dapat
     * membedakan "comparator sudah dijalankan dan tidak ada alternatif untuk dibandingkan" dari
     * "comparator tidak pernah dijalankan". Keduanya WAJIB dibedakan. */
    if (!$cands) {
        $out['info']['Global Commitment Review'] = [
            'root_problem' => 'BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER',
            'candidates_evaluated' => 0,
            'candidates_total' => 0,
            'all_candidates_evaluated' => true,
            'units_dropped' => [],
            'comparator_order' => ['operator_hard_controls','hard_violations','export_reserve_busflow',
                                   'cost_production','total_cost','heat_rate','startup_count','fuel',
                                   'keep_running_if_equal'],
            'ladder' => [],
            'note' => 'tidak ada unit dengan provenance BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER; comparator dijalankan dan tidak menemukan alternatif global untuk dibandingkan'];
        return $out;
    }
    /* V10 TIER-1 SCREENING — KELAS COMMITMENT YANG SAMA DICAKUP RUANG KANDIDAT EXACT.
     * Kandidat comparator ini adalah "unit u dimatikan sepanjang hari" dan dievaluasi dengan SATU
     * PIPELINE PENUH BERSARANG per kandidat (terukur 5-28 detik per kandidat). Pada simulasi terluar
     * yang menjalankan tahap keluarga commitment exact (ppExactTrack aktif), kelas commitment yang sama
     * persis — node keluarga off:{u} beserta turunannya — dievaluasi di ruang kandidat exact yang sama
     * (dengan pendaratan window gas dan review generik V9 sesudahnya). Kandidat u yang eligible keluarga
     * karena itu TIDAK di-full-run di sini: ia dicatat sebagai DICAKUP_NODE_KELUARGA dan bukti kelasnya
     * adalah node off:{u} pada ruang kandidat exact. Kelengkapan comparator lalu bergantung pada
     * kelengkapan keluarga (dinilai pp_exact_family_stage). Unit yang tidak eligible keluarga tetap
     * dievaluasi dengan pipeline bersarang seperti sebelumnya. PP_V10_GCR_SCREEN=0 mematikan. */
    $screened = [];
    if (!empty($GLOBALS['ppExactTrack']['orig']) && (string)getenv('PP_V10_GCR_SCREEN') !== '0' && function_exists('pp_tl_eligible_units')) {
        try { $elG = pp_tl_eligible_units((array)$GLOBALS['ppExactTrack']['orig']); } catch (Throwable $e) { $elG = []; }
        $keepC = [];
        foreach ($cands as $u) { if (!empty($elG[$u])) $screened[] = $u; else $keepC[] = $u; }
        /* V11: unit ini DICAKUP node keluarga off:{u} (alias) — tidak dihitung ulang sebagai kandidat. */
        $cands = $keepC;
    }
    $GLOBALS['__pp_gcmp_busy'] = true;
    $bestOut = $out; $bestKey = pp_global_commitment_key($input, $out); $ladder = []; $dropped = [];
    foreach ($screened as $u) $ladder[] = ['unit' => strtoupper($u), 'variant' => 'GLOBAL_ALTERNATIVE_WITHOUT_UNIT', 'key' => null, 'incumbent_key' => $bestKey,
        'better' => false, 'excluded' => true, 'screened' => true, 'covered_by' => 'off:' . $u,
        'exclusion_reason' => 'V10_DICAKUP_NODE_KELUARGA_OFF_' . strtoupper($u) . '_RUANG_KANDIDAT_EXACT'];
    /* ===== ADMISSION WAKTU BERBASIS PENGUKURAN, SEMUA-ATAU-DIBUANG =========================
     * Comparator ini WAJIB mengevaluasi SELURUH kandidat atau tidak dipakai sama sekali —
     * memilih di antara sebagian kandidat bukan bukti biaya terendah. Karena itu: biaya kandidat
     * pertama diukur, lalu sebelum tiap kandidat berikutnya sisa waktu diperiksa terhadap biaya
     * NYATA per kandidat. Bila sisa waktu tidak cukup menampung kandidat yang belum diuji,
     * SELURUH hasil comparator dibuang dan incumbent dipertahankan apa adanya — tidak ada
     * kandidat yang "dilewatkan" dari sebuah perbandingan yang tetap dipakai. Penyelesaiannya
     * diserahkan ke worker asinkron, yang mengevaluasi semuanya tanpa plafon request. */
    $gcrSpent = 0.0; $gcrDone = 0; $gcrTotal = count($cands);
    foreach ($cands as $u) {
        if ($gcrDone > 0) {
            $perCand = $gcrSpent / $gcrDone;                       // biaya TERUKUR, bukan ramalan
            $needRest = ($gcrTotal - $gcrDone) * $perCand * 1.15 + PP_GCR_CLOSING_RESERVE_S;
            if (pp_two_phase_time_left() < $needRest) {
                $GLOBALS['__pp_gcmp_busy'] = false;
                $GLOBALS['__pp_econ_review_skipped'] = [
                    'reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_SELURUH_KANDIDAT_COMPARATOR',
                    'async_completion_required' => true,
                    'candidates_total' => $gcrTotal, 'candidates_measured' => $gcrDone,
                    'measured_per_candidate_s' => round($perCand, 2),
                    'estimated_need_for_rest_s' => round($needRest, 2),
                    'time_left_s' => is_finite(pp_two_phase_time_left()) ? round(pp_two_phase_time_left(), 2) : null,
                    'catatan' => 'hasil comparator DIBUANG seluruhnya; incumbent dipertahankan dan tidak ada perbandingan sebagian yang dipakai'];
                $out['info']['Global Commitment Review Skipped'] = $GLOBALS['__pp_econ_review_skipped'];
                return $out;                                       // incumbent ASLI, bukan $bestOut
            }
        }
        $tCand = microtime(true);
        /* Progres nyata untuk operator: kandidat i/N yang sedang dievaluasi (hanya pelaporan). */
        if (!empty($GLOBALS['__pp_job_id']) && function_exists('pp_job_progress'))
            pp_job_progress((string)$GLOBALS['__pp_job_id'], sprintf('KOMPARATOR_KANDIDAT_%d_DARI_%d_(%s)', $gcrDone + 1, $gcrTotal, strtoupper($u)));
        $x = json_decode(json_encode($input), true);
        $x['data3']['modeling']['unit_stop_time'][] = ['unit' => $u, 'start' => 1, 'stop' => 48];
        $alt = pp_run_simulation($x);
        $gcrSpent += microtime(true) - $tCand; $gcrDone++;
        $k   = pp_global_commitment_key($x, $alt);
        /* GATE FEASIBILITY (keputusan domain, Opsi C): kandidat yang melanggar hard constraint
         * — termasuk gas quota — dinyatakan INFEASIBLE dan DIKELUARKAN sebelum comparator
         * dijalankan. Gas quota tetap hard cap: TOTAL_GAS_USED <= GAS_QUOTA, tanpa toleransi.
         * Alternatif hanya boleh menang bila (a) ia feasible, atau (b) ia secara TEGAS mengurangi
         * jumlah hard violation incumbent. Comparator ekonomi TIDAK PERNAH dipakai untuk memilih
         * di antara dua kandidat yang sama-sama infeasible. */
        $altFeasible = ((int)$k['hard'] === 0);
        $incFeasible = ((int)$bestKey['hard'] === 0);
        if (!$altFeasible && (int)$k['hard'] >= (int)$bestKey['hard']) {
            $ladder[] = ['unit' => strtoupper($u), 'variant' => 'GLOBAL_ALTERNATIVE_WITHOUT_UNIT',
                         'key' => $k, 'incumbent_key' => $bestKey, 'better' => false,
                         'excluded' => true,
                         'exclusion_reason' => 'INFEASIBLE_CANDIDATE_HARD_VIOLATION'
                             . ((int)$bestKey['hard'] > 0 ? '_INCUMBENT_ALSO_INFEASIBLE_KEEP_INCUMBENT' : '')];
            continue;
        }
        $better = pp_global_commitment_better($k, $bestKey);
        $ladder[] = ['unit' => strtoupper($u), 'variant' => 'GLOBAL_ALTERNATIVE_WITHOUT_UNIT',
                     'key' => $k, 'incumbent_key' => $bestKey, 'better' => $better];
        if ($better) { $bestOut = $alt; $bestKey = $k; $dropped[] = strtoupper($u); }
    }
    $GLOBALS['__pp_gcmp_busy'] = false;
    $bestOut['info']['Global Commitment Review'] = [
        'root_problem' => 'BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER',
        'candidates_evaluated' => $gcrDone,
        'candidates_total' => $gcrTotal + count($screened),
        'candidates_screened_v10' => array_map('strtoupper', $screened),
        'v10_family_coverage_pending' => (bool)$screened,
        /* Bukti kelengkapan: comparator hanya boleh dipakai bila SELURUH kandidat dievaluasi.
         * Nilai ini yang dibaca release gate dan laporan bukti optimalitas ekonomi. */
        'all_candidates_evaluated' => ($gcrDone === $gcrTotal),
        'measured_total_s' => round($gcrSpent, 2),
        'measured_per_candidate_s' => $gcrDone > 0 ? round($gcrSpent / $gcrDone, 2) : null,
        'units_dropped' => $dropped,
        'comparator_order' => ['operator_hard_controls','hard_violations','export_reserve_busflow',
                               'cost_production','total_cost','heat_rate','startup_count','fuel',
                               'keep_running_if_equal'],
        'ladder' => $ladder];
    return $bestOut;
}
/* =============================================================================================
 *  PP_JBBK_QUOTA_OFFSET_SEARCH — mendaratkan pemakaian gas ke dalam window kuota.
 *
 *  Root cause: shaper mengejar kuota KONTRAK sedangkan validator menilai realisasi FIXED FLOW,
 *  sehingga pemakaian selalu mendarat di bawah window. Offset dicari di level TERLUAR dan
 *  dievaluasi dengan METRIK VALIDATOR SENDIRI (Total Gas Used vs Base Gas Quota), bukan ditebak
 *  dari komponen internal.
 *
 *  Aturan pemilihan: (1) hard violation paling sedikit, (2) deviasi gas terhadap window,
 *  (3) Cost Production terendah. Best-of guard: bila tidak ada kandidat yang lebih baik dari
 *  hasil awal, hasil awal dipertahankan apa adanya.
 * =========================================================================================== */
function pp_jbbk_quota_offset_search(array $input, array $out): array {
    if ((string)getenv('PP_GAS_OFFSET_SEARCH') === '0') return $out;
    if (!empty($GLOBALS['__pp_gas_offset_busy'])) return $out;
    $score = function (array $in2, array $o2): array {
        $i2 = $o2['info'] ?? [];
        $g  = (float)($i2['Total Gas Used (BBTUD)'] ?? 0);        // neto: window dinilai atas neto
        $q  = (float)($i2['Base Gas Quota (BBTUD)'] ?? 0);
        [$lo, $hi] = ($q > 0) ? pp_gas_window($q) : [0.0, 0.0];
        $dev = ($q <= 0) ? 0.0 : (($g > $hi) ? ($g - $hi) : (($g < $lo) ? ($lo - $g) : 0.0));
        $V = pp_validate_hard_constraints($in2, $o2);
        $hard = count((array)($V['violations'] ?? []));
        $cp = (float)($i2['Total Plant Cost Production (USD/MWh)']
                      ?? $i2['Cost Production (USD/MWh)'] ?? 0);
        return ['dev' => $dev, 'hard' => $hard, 'cp' => $cp, 'gas' => $g, 'quota' => $q];
    };
    $base = $score($input, $out);
    if ($base['dev'] <= 1e-9) return $out;                 // sudah di dalam window
    /* ===== DOMINANCE EKSAK BERDASARKAN ARAH, BUKAN BESARAN (akar penyebab sistemik) ===========
     * Pencarian ini HANYA membangkitkan offset NON-NEGATIF dan MENAMBAHKANNYA ke target kuota
     * shaper (__jbbk_quota_offset += $off, dengan $lowOff = 0 dan probe > 0). Menaikkan target
     * kuota shaper hanya dapat meminta gas LEBIH BANYAK. Fungsi ini memang dibuat untuk
     * MENDARATKAN pemakaian gas yang jatuh DI BAWAH window — bukan untuk menurunkan pemakaian
     * yang sudah DI ATAS window. Karena itu, ketika gas berada di atas batas atas window, tidak
     * ada satu pun kandidat yang dapat dibangkitkannya yang mampu memperkecil deviasi.
     *
     * Gerbang lama `dev > 4.0` adalah ambang BESARAN, dan justru itulah yang membuat perilaku
     * bergantung pada nilai PGN: pada PGN 25 deviasi 4,6985 (> 4) sehingga pencarian dilewati,
     * pada PGN 30 deviasi 0 sehingga dilewati, tetapi pada PGN 27 deviasi 2,7376 jatuh di dalam
     * pita 0 < dev <= 4 sehingga pencarian DIJALANKAN. Terukur pada input produksi PGN 27:
     * satu kandidat offset 3,0 = 27 core run = 25,09 detik, dan hasilnya IDENTIK
     * (gas 64,3376 -> 64,3376, deviasi 2,7376 -> 2,7376, hard 2 -> 2, "tidak ada kandidat lebih
     * baik"). Dua puluh lima detik itulah yang membuat comparator komitmen global kehabisan
     * anggaran dan run dilaporkan ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET.
     *
     * Gerbang di bawah karena itu diubah menjadi ARAH: di atas window = tidak terjangkau oleh
     * lever ini, pada deviasi berapa pun. Ini pemangkasan EKSAK (dominance), bukan heuristik:
     * yang dibuang hanyalah kandidat yang secara konstruksi tidak dapat mengubah hasil akhir.
     * Perilaku untuk gas DI BAWAH window — satu-satunya keadaan yang menjadi alasan fungsi ini
     * ada — tidak berubah sama sekali. */
    if ($base['quota'] > 0) {
        [$bLo, $bHi] = pp_gas_window($base['quota']);
        /* SAKLAR ORACLE/ROLLBACK: PP_GAS_OFFSET_DIRECTIONAL=0 mengembalikan gerbang besaran lama
         * persis seperti V7. Dipakai sebagai oracle ekuivalensi (hasil kedua mode wajib identik)
         * dan sebagai rollback cepat di staging tanpa mengganti paket. */
        if ((string)getenv('PP_GAS_OFFSET_DIRECTIONAL') !== '0' && $base['gas'] > $bHi + 1e-9) {
            $out['info']['Gas Quota Offset Search Skipped'] = [
                'sebab' => 'PEMAKAIAN_GAS_DI_ATAS_WINDOW_LEVER_OFFSET_HANYA_MENAIKKAN_TARGET',
                'gas' => round($base['gas'], 4), 'window_atas' => round($bHi, 4),
                'deviasi' => round($base['dev'], 4),
                'bukti' => 'offset yang dibangkitkan selalu >= 0 dan ditambahkan ke target kuota shaper, '
                         . 'sehingga tidak dapat memperkecil deviasi di atas window pada nilai berapa pun',
                'jenis' => 'EXACT_DOMINANCE_BUKAN_HEURISTIC_PRUNING'];
            return $out;
        }
        if ($base['dev'] > 4.0) {
            $out['info']['Gas Quota Window Unreachable'] = [
                'deviasi' => round($base['dev'], 4), 'lebar_window' => round($bHi - $bLo, 4),
                'sebab' => 'deviasi jauh melampaui lebar window; respons dispatch diskret tidak dapat mendaratkannya',
                'catatan' => 'pencarian offset dilewati agar simulasi tetap cepat'];
            return $out;
        }
    }
    $GLOBALS['__pp_gas_offset_busy'] = true;
    $bestOut = $out; $best = $base; $ladder = []; $bestOff = 0.0; $unreachable = null;
    /* BISECTION TERARAH + BATAS RUN (permintaan operator: simulasi harus cepat).
     * Probe pertama 0.2 (respons terukur hampir seluruhnya muncul di offset kecil).
     * Bila MELEWATI batas atas window, cari di antara 0 dan probe itu; bila masih kurang,
     * naikkan probe. Maksimum 4 simulasi tambahan dan tetap tunduk pada budget waktu. */
    /* Probe awal diperkirakan dari deviasi lalu diperhalus bisection: deviasi besar terjangkau
     * dalam 1-3 simulasi, tanpa menaikkan jumlah run secara buta. */
    $lowOff = 0.0; $highOff = null;
    $probe = max(0.2, min(3.0, $base['dev'] * 1.2));
    $runs = 0; $maxRuns = 3;
    /* BATAS WAKTU PENCARIAN (permintaan operator: simulasi harus cepat).
     * Total waktu yang boleh dipakai pencarian offset dibatasi; bila satu simulasi kandidat
     * ternyata mahal, pencarian berhenti setelah kandidat berjalan, bukan menumpuk 4 run. */
    $searchT0 = microtime(true);
    $searchCap = (float)($input['data3']['modeling']['__gas_offset_search_seconds'] ?? 10.0);
    /* Perkiraan biaya satu kandidat = waktu pipeline luar yang sudah berjalan sampai titik ini
     * (kandidat menjalankan tahap yang sama sebagai pipeline bersarang). Kandidat yang tidak dapat
     * ditampung utuh TIDAK dimulai: memulainya lalu dipotong plafon adalah hasil terburuk. */
    $offOuterElapsed = isset($GLOBALS['__pp_outer_t0'])
                     ? max(0.5, microtime(true) - (float)$GLOBALS['__pp_outer_t0']) : 5.0;
    $offCandEst = $offOuterElapsed * 0.6;
    while ($runs < $maxRuns) {
        if (pp_budget_exceeded('jbbk_quota_offset_search')) break;
        if ((microtime(true) - $searchT0) > $searchCap) break;
        if (!pp_stage_admits($runs > 0 ? $offCandEst : $offCandEst)) {
            pp_stage_defer('jbbk_quota_offset_search', $offCandEst, ['candidates_done' => $runs]);
            break;
        }
        $tOffCand = microtime(true);
        $off = $probe; $runs++;
        $x = json_decode(json_encode($input), true);
        $x['data3']['modeling']['__jbbk_quota_offset'] =
            (float)($x['data3']['modeling']['__jbbk_quota_offset'] ?? 0) + $off;
        $cand = pp_run_simulation($x);
        $sc = $score($x, $cand);
        /* arah berikutnya ditentukan dari posisi gas terhadap window */
        $gasNow = $sc['gas']; $qNow = $sc['quota'];
        [$wLo, $wHi] = ($qNow > 0) ? pp_gas_window($qNow) : [0.0, 0.0];
        if ($gasNow > $wHi + 1e-9) { $highOff = $off; $probe = 0.5 * ($lowOff + $off); }
        elseif ($gasNow < $wLo - 1e-9) { $lowOff = $off;
            $probe = ($highOff === null) ? min(3.0, $off * 2.5) : 0.5 * ($off + $highOff); }
        else { $probe = $off; }
        $offCandEst = max($offCandEst * 0.5, microtime(true) - $tOffCand);   // biaya nyata kandidat
        $ladder[] = ['offset' => $off, 'gas' => round($sc['gas'], 4), 'quota' => round($sc['quota'], 4),
                     'deviasi' => round($sc['dev'], 4), 'hard_violations' => $sc['hard'],
                     'cost_production' => round($sc['cp'], 4)];
        /* urutan pemilihan: hard violation -> deviasi -> cost production */
        $better = ($sc['hard'] < $best['hard'])
               || ($sc['hard'] === $best['hard'] && $sc['dev'] < $best['dev'] - 1e-9)
               || ($sc['hard'] === $best['hard'] && abs($sc['dev'] - $best['dev']) <= 1e-9
                   && $sc['cp'] > 0 && $sc['cp'] < $best['cp'] - 1e-9);
        if ($better) { $bestOut = $cand; $best = $sc; $bestOff = $off; }
        if ($best['dev'] <= 1e-9 && $best['hard'] === 0) break;   // sudah masuk window dan valid
        if ((microtime(true) - $searchT0) > $searchCap) break;    // anggaran pencarian habis
        if ($highOff !== null && abs($highOff - $lowOff) < 0.02) break;   // bracket sudah rapat
        /* DETEKSI DINI WINDOW TAK TERJANGKAU: bila satu langkah offset membuat gas MELOMPATI
         * seluruh window (respons dispatch diskret lebih lebar dari window 0.04), pencarian
         * lebih lanjut hanya membuang waktu. Hentikan dan simpan bukti. */
        if ($highOff !== null && $lowOff >= 0.0 && ($sc['gas'] - $base['gas']) > ($wHi - $wLo) + 1e-9
            && $sc['dev'] > 1e-9 && $base['dev'] > 1e-9) {
            $unreachable = ['lompatan_gas' => round($sc['gas'] - $base['gas'], 4),
                            'lebar_window' => round($wHi - $wLo, 4)];
            break;
        }
    }
    $GLOBALS['__pp_gas_offset_busy'] = false;
    if (!empty($unreachable)) $bestOut['info']['Gas Quota Window Unreachable'] = $unreachable
        + ['sebab' => 'respons dispatch bersifat diskret dan lompatannya lebih lebar daripada window kuota'];
    $bestOut['info']['Gas Quota Offset Search'] = [
        'alasan' => 'basis kuota shaper (kontrak) berbeda dari basis validator (realisasi fixed flow)',
        'deviasi_awal' => round($base['dev'], 4), 'hard_awal' => $base['hard'],
        'offset_terpilih' => $bestOff, 'deviasi_akhir' => round($best['dev'], 4),
        'hard_akhir' => $best['hard'], 'cost_production_akhir' => round($best['cp'], 4),
        'kandidat' => $ladder,
        'catatan' => $bestOff > 0 ? 'offset diterapkan' : 'tidak ada kandidat lebih baik; hasil awal dipertahankan'];
    return $bestOut;
}
/* Outermost-simulation wrapper. Arms the absolute request deadline exactly once per top-level
 * simulation and releases it on every exit path, so nested reviews share one ceiling instead of
 * each being granted a fresh 60 s. The pipeline body below is unchanged. */
/* ROBUSTNESS (bug pre-existing, ada juga di build asli): beberapa field modeling dideklarasikan
 * sebagai array di seluruh engine, tetapi payload yang cacat bisa mengirimkannya sebagai string.
 * `?? []` hanya menangkap null/unset, bukan tipe yang salah, sehingga PHP melempar TypeError dan
 * simulasi MATI TOTAL (mis. unit_stop:"g3" -> in_array(): Argument #2 must be of type array).
 * Daftar di bawah diturunkan dari crash yang benar-benar terjadi, bukan dari tebakan. Nilai yang
 * salah tipe diperlakukan sama seperti tidak dikirim, dan field yang dikoreksi DILAPORKAN sebagai
 * warning domain agar tidak hilang diam-diam. Input yang valid tidak tersentuh sama sekali. */
/* ==============================================================================================
 * KOSAKATA KEPUTUSAN BAHAN BAKAR — SATU TEMPAT, NILAI STABIL, LABEL TAMPILAN TIDAK DIPAKAI LOGIC.
 *
 * CACAT YANG DIPERBAIKI. Menu `Gas Shortage Decision` hanya mengenal tiga nilai backend: `none`,
 * `add_lng`, `use_distillate`. Opsi pertama berlabel "Flag shortage only" dan mengirim `none`,
 * sehingga TIDAK ADA nilai yang berarti "hitung rekomendasi tervalidasi lalu tanya saya" —
 * padahal itulah alur yang diminta kontrak. Akibatnya engine menandai run sebagai
 * FLAG_SHORTAGE_ONLY_SELECTED dan operator tidak pernah dibawa ke keputusan bahan bakar.
 *
 * `recommendation` kini menjadi nilai kelas satu. Untuk DISPATCH ia berperilaku persis seperti
 * `none` — tidak ada LNG yang ditambahkan, tidak ada distillate yang diterapkan, dan itu memang
 * inti kontraknya: jangan auto-apply sebelum operator menyetujui. Yang berbeda hanya NIAT-nya,
 * dan niat itu disimpan terpisah pada `__fuel_decision_mode` supaya seluruh pembaca lama
 * (`... ?? 'none'`) tetap bekerja tanpa satu pun diubah.
 *
 * `distillate` diterima sebagai alias `use_distillate` karena kontrak menyebut nama itu.
 * ============================================================================================== */
function pp_normalize_fuel_action(array &$input): void {
    if (!isset($input['data3']['modeling']) || !is_array($input['data3']['modeling'])) return;
    $m = &$input['data3']['modeling'];
    /* SANITASI PLAFON LITER — dijalankan sebelum penjagaan idempoten supaya nilai rusak tidak
     * pernah lolos ke engine, berapa kali pun normalisasi dipanggil.
     *
     * Dibedakan TEGAS tiga keadaan yang dahulu tercampur:
     *   - field TIDAK ADA / string kosong  -> tanpa plafon (otorisasi tak terbatas);
     *   - field ADA dan bernilai 0         -> otorisasi NOL (distillate tidak boleh dipakai);
     *   - field ADA dan negatif/nonfinite  -> input rusak, diperlakukan sebagai otorisasi NOL.
     * Dahulu ketiganya sama-sama dianggap "tidak ada plafon", sehingga operator yang mengetik 0
     * justru memperoleh distillate TANPA BATAS — kebalikan dari maksudnya. */
    if (array_key_exists('distillate_user_limit_litres', $m)) {
        $rawLim = $m['distillate_user_limit_litres'];
        if ($rawLim === null || $rawLim === '') {
            unset($m['distillate_user_limit_litres']);              // benar-benar tanpa plafon
        } elseif (!is_numeric($rawLim) || !is_finite((float)$rawLim) || (float)$rawLim < 0) {
            $m['distillate_user_limit_litres'] = 0.0;               // rusak -> otorisasi nol
            $m['__distillate_limit_sanitized'] = 'NILAI_TIDAK_SAH_DIPERLAKUKAN_SEBAGAI_NOL';
        } else {
            $m['distillate_user_limit_litres'] = (float)$rawLim;
        }
    }
    /* IDEMPOTEN. Normalisasi berjalan DUA KALI: sekali di pintu masuk run.php (sebelum gating) dan
     * sekali lagi di dalam engine lewat pp_normalize_model_arrays(). Tanpa penjagaan ini, pass
     * kedua membaca aksi yang SUDAH dinormalkan menjadi 'none' lalu menimpa niat aslinya, sehingga
     * `Fuel Action Source` melaporkan 'none' untuk run yang sebenarnya 'recommendation'. */
    if (isset($m['__fuel_decision_mode']) && $m['__fuel_decision_mode'] !== '') {
        $GLOBALS['__pp_fuel_decision_mode'] = (string)$m['__fuel_decision_mode'];
        return;
    }
    $raw = strtolower(trim((string)($m['gas_shortage_action'] ?? 'none')));
    if ($raw === '') $raw = 'none';
    $mode = $raw;
    if ($raw === 'recommendation' || $raw === 'gas_shortage_recommendation') {
        /* Perilaku dispatch = none (tidak auto-apply). Niat disimpan terpisah. */
        $m['gas_shortage_action'] = 'none';
        $mode = 'recommendation';
    } elseif ($raw === 'distillate') {
        $m['gas_shortage_action'] = 'use_distillate';
        $mode = 'use_distillate';
    } elseif ($raw === 'mixed' || $raw === 'mixed_lng_distillate' || $raw === 'lng_plus_distillate') {
        /* AKSI CAMPURAN RESMI. Dipakai ketika operator mengotorisasi LNG dalam jumlah yang TIDAK
         * mencukupi: LNG dipakai sebesar otorisasi, sisanya ditutup distillate tervalidasi. */
        $m['gas_shortage_action'] = 'mixed_lng_distillate';
        $mode = 'mixed_lng_distillate';
    } elseif (!in_array($raw, ['none', 'add_lng', 'use_distillate', 'mixed_lng_distillate', 'force_same_as_quota'], true)) {
        /* Nilai tak dikenal TIDAK diam-diam dianggap none: dicatat supaya terlihat di laporan. */
        $m['gas_shortage_action'] = 'none';
        $mode = 'unknown:' . substr(preg_replace('~[^a-z0-9_]~', '', $raw), 0, 24);
    }
    $m['__fuel_decision_mode'] = $mode;
    $GLOBALS['__pp_fuel_decision_mode'] = $mode;
}

function pp_normalize_model_arrays(array &$input): array {
    pp_normalize_fuel_action($input);
    static $ARRAY_FIELDS = ['unit_stop','unit_stop_time','unit_fix_load','unit_skip_load','hrsg_stop',
        'hrsg_stop_time','bus_unit','ie_adjustment','ie_adjustments','required_units','unit_cannot_stop',
        'max_load_rules','gas_quota','required_mode','stop_mode','manual_fixed_flows','unit_start_time',
        'block_priority','unit_priority','unit_priority_dist','price','emission','stg_startup_mode'];
    if (!isset($input['data3']['modeling']) || !is_array($input['data3']['modeling'])) return [];
    $fixed = [];
    foreach ($ARRAY_FIELDS as $f) {
        if (array_key_exists($f, $input['data3']['modeling']) && $input['data3']['modeling'][$f] !== null
            && !is_array($input['data3']['modeling'][$f])) {
            $fixed[] = $f;
            $input['data3']['modeling'][$f] = [];
        }
    }
    return $fixed;
}

function pp_run_simulation(array $input): array {
    $__ppFixed = pp_normalize_model_arrays($input);
    $GLOBALS['__pp_sim_nest'] = (int)($GLOBALS['__pp_sim_nest'] ?? 0) + 1;
    $__ppOuter = empty($GLOBALS['__pp_sim_outermost']);
    if ($__ppOuter) {
        $GLOBALS['__pp_sim_outermost'] = true;
        /* OPT: batas request untuk memo data input (lihat pp_req_gen()). Dinaikkan hanya di
         * simulasi TERLUAR, sehingga memo hidup sepanjang satu request penuh namun tidak pernah
         * menyeberang ke request berikutnya pada proses worker CLI yang sama. */
        $GLOBALS['__pp_req_gen'] = (int)($GLOBALS['__pp_req_gen'] ?? 0) + 1;
        $GLOBALS['__pp_budget_aborts'] = [];
        $GLOBALS['__pp_core_runs_total'] = 0;
        $GLOBALS['__pp_outer_t0'] = microtime(true);
        $GLOBALS['__pp_phase_timeline'] = [];
        $GLOBALS['__pp_econ_review_skipped'] = null;
        $GLOBALS['__pp_stages_deferred'] = [];
        $GLOBALS['__pp_core_cost_avg'] = null; $GLOBALS['__pp_core_cost_max'] = 0.0;
        $GLOBALS['__pp_core_cost_n'] = 0;
        $GLOBALS['__ppx_once_calls0'] = (int)($GLOBALS['__ppx_once_calls'] ?? 0); $GLOBALS['__ppx_sup_hits0'] = (int)($GLOBALS['__ppx_sup_memo_hits'] ?? 0);
        $GLOBALS['__ppx_once_memo0'] = (int)($GLOBALS['__ppx_once_memo_hits'] ?? 0);
        $mT = (array)($input['data3']['modeling'] ?? []);
        if (empty($mT['__tl_no_auto_start']) && empty($mT['__no_exact_family']) && empty($mT['change_over']['enabled']) && (string)getenv('PP_EXACT_FAMILY') !== '0') {
            $oT = $input; foreach (array_keys($mT) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($oT['data3']['modeling'][$mk]);
            $GLOBALS['ppExactTrack'] = ['orig' => $oT, 'best' => null, 'n' => 0, 'v' => 0];
        } else unset($GLOBALS['ppExactTrack']);
        pp_budget_arm_deadline((float)($input['data3']['modeling']['time_budget_max_seconds'] ?? 60.0));
        pp_phase_mark('entry');
    }
    try {
        $out = pp_run_simulation_pipeline($input);
        /* RUN STATUS (diagnostik, aditif — field lama tidak berubah).
         * Sebuah hasil yang dipotong deadline TIDAK BOLEH dilaporkan sebagai konvergen. Status ini
         * diturunkan dari fakta: tahap mana saja yang benar-benar dihentikan oleh budget/deadline
         * (dicatat pp_budget_exceeded), bukan dari asumsi. */
        if ($__ppFixed) {
            $msg = 'Malformed input: modeling field(s) ' . implode(', ', $__ppFixed)
                 . ' arrived with a non-array type and were treated as empty.';
            if (!isset($out['info']['Warnings']) || !is_array($out['info']['Warnings'])) $out['info']['Warnings'] = [];
            if (!in_array($msg, $out['info']['Warnings'], true)) $out['info']['Warnings'][] = $msg;
            $out['info']['Malformed Input Fields'] = $__ppFixed;
        }
        if ($__ppOuter && function_exists('pp_exact_family_stage')) $out = pp_exact_family_stage($input, $out);   // ruang kandidat exact lengkap
        if ($__ppOuter && function_exists('pp_v6_priority_polish') && empty($input['data3']['modeling']['change_over']['enabled']) && empty($input['data3']['modeling']['__tl_no_auto_start'])
            && ($GLOBALS['__pp_econ_review_skipped'] ?? null) === null && empty($GLOBALS['__pp_budget_aborts'])) {
            $oP = $input; foreach (array_keys((array)$oP['data3']['modeling']) as $mk) if (is_string($mk) && strpos($mk, '__') === 0 && $mk !== '__fuel_decision_mode') unset($oP['data3']['modeling'][$mk]);
            $aP = pp_tl_assess($oP, $out);
            if (!empty($aP['valid'])) { $aP['output'] = $out; $dlP = isset($GLOBALS['__pp_budget_deadline']) ? (float)$GLOBALS['__pp_budget_deadline'] - 2.0 : microtime(true) + 60.0; $aP = pp_v6_priority_polish($oP, $aP, $dlP); $out = $aP['output']; }
        }
        /* V8: review Unit Priority / headroom berbasis kandidat pembanding (row-local, validasi 48 row). */
        if ($__ppOuter && function_exists('pp_v8_priority_review') && empty($input['data3']['modeling']['__tl_no_auto_start'])
            && ($GLOBALS['__pp_econ_review_skipped'] ?? null) === null && empty($GLOBALS['__pp_budget_aborts'])) $out = pp_v8_priority_review($input, $out);
        if ($__ppOuter) $out['info']['Run Status'] = pp_run_status_block($out);
        return $out;
    } finally {
        $GLOBALS['__pp_sim_nest'] = max(0, (int)($GLOBALS['__pp_sim_nest'] ?? 1) - 1);
        if ($__ppOuter) {
            unset($GLOBALS['__pp_sim_outermost'], $GLOBALS['ppExactTrack']);
            pp_budget_disarm_deadline();
            /* Depth counter dikembalikan ke 0 di sini juga: bila pipeline melempar exception,
             * depth tidak pernah diturunkan dan simulasi BERIKUTNYA akan salah menganggap dirinya
             * bersarang. try/finally menjamin cleanup pada semua jalur keluar. */
            $GLOBALS['__pp_sim_depth'] = 0;
        }
    }
}

/* Status eksplisit untuk konsumen: completed / deadline_reached / converged, tahap terakhir yang
 * dipotong, elapsed, dan cakupan kandidat yang benar-benar dievaluasi. */
function pp_run_status_block(array $out): array {
    $aborts   = (array)($GLOBALS['__pp_budget_aborts'] ?? []);
    $deadline = false;
    foreach ($aborts as $k => $c) if (strpos((string)$k, 'absolute_deadline:') === 0) { $deadline = true; break; }
    $budgetCut = count($aborts) > 0;
    $elapsed   = isset($GLOBALS['__pp_outer_t0']) ? microtime(true) - (float)$GLOBALS['__pp_outer_t0'] : null;
    $gcr       = (array)($out['info']['Global Commitment Review'] ?? []);
    $stages    = array_keys($aborts);
    /* Comparator global yang DILEWATI karena anggaran waktu: pencarian ekonomi belum lengkap,
     * walaupun tidak ada tahap yang dipotong di tengah jalan. Dilaporkan sebagai status
     * tersendiri supaya tidak tertukar dengan CONVERGED (terbukti) maupun DEADLINE_REACHED
     * (dipotong plafon). */
    /* Request sinkron: pencarian yang melewatkan kandidat karena anggaran waktu (penanda yang sama
     * dengan syarat memo, pp_memo_eligible_output) BUKAN hasil konvergen, walaupun tidak ada tahap
     * yang dipotong di tengah jalan — job exact menyelesaikannya dengan anggaran penuh. */
    if (($GLOBALS['__pp_econ_review_skipped'] ?? null) === null && !defined('PP_INPROC_JOB') && PHP_SAPI !== 'cli') {
        $jInfo = json_encode($out['info'] ?? []);
        if (is_string($jInfo)) foreach (['"search_truncated_by_budget":true', '"per_candidate_core_run_skipped":true',
                 '"verification_truncated_by_budget":true', '"budget_exhausted":true'] as $mk)
            if (strpos($jInfo, $mk) !== false) { $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'PENCARIAN_DIBATASI_ANGGARAN_SINKRON',
                'marker' => trim($mk, '"'), 'async_completion_required' => true]; break; }
    }
    $econSkip  = $GLOBALS['__pp_econ_review_skipped'] ?? null;
    return [
        'completed'        => true,                       // pipeline mencapai akhir tanpa exception
        'deadline_reached' => $deadline,                  // plafon absolut per-request tercapai
        'budget_truncated' => $budgetCut,                 // ada tahap pencarian yang dihentikan jam
        /* KONVERGEN hanya bila TIDAK ada satu pun tahap yang dipotong waktu DAN comparator
         * global tidak dilewati karena anggaran waktu. */
        'converged'        => !$budgetCut && ($econSkip === null),
        'status'           => $budgetCut ? ($deadline ? 'DEADLINE_REACHED' : 'SEARCH_BUDGET_TRUNCATED')
                              : ($econSkip !== null ? 'ECONOMIC_REVIEW_SKIPPED_TIME_BUDGET' : 'CONVERGED'),
        'economic_review_skipped' => $econSkip,
        'last_stage'       => $stages ? (string)end($stages) : null,
        'stages_truncated' => $aborts,
        'elapsed_s'        => $elapsed === null ? null : round($elapsed, 3),
        'deadline_s'       => isset($GLOBALS['__pp_budget_deadline']) && $elapsed !== null
                              ? round((float)$GLOBALS['__pp_budget_deadline'] - (float)$GLOBALS['__pp_outer_t0'], 3) : null,
        'core_runs'        => (int)($GLOBALS['__pp_core_runs_total'] ?? 0),
        'core_simulations' => (int)($GLOBALS['__ppx_once_calls'] ?? 0) - (int)($GLOBALS['__ppx_once_calls0'] ?? 0),
        /* V4: core state identik yang dipakai ulang (memo eksak) dan kolam candidate-state. */
        'core_state_reuse' => (int)($GLOBALS['__ppx_once_memo_hits'] ?? 0) - (int)($GLOBALS['__ppx_once_memo0'] ?? 0),
        'candidate_pool' => function_exists('pp_v4_stats') ? pp_v4_stats() : null,
        'supplier_state_reuse' => (int)($GLOBALS['__ppx_sup_memo_hits'] ?? 0) - (int)($GLOBALS['__ppx_sup_hits0'] ?? 0),
        /* SELESAI berarti: tidak ada tahap terpotong, comparator tidak dilewati, blok bukti ada,
         * DAN seluruh kandidat comparator benar-benar dievaluasi. */
        'economic_review_completed' => ($econSkip === null)
                              && isset($out['info']['Global Commitment Review'])
                              && (($out['info']['Global Commitment Review']['all_candidates_evaluated'] ?? false) === true),
        'phase_timeline'   => array_values((array)($GLOBALS['__pp_phase_timeline'] ?? [])),
        /* Tahap yang DITUNDA karena anggaran waktu (bukan dipotong). Berbeda dari
         * stages_truncated: tidak ada pekerjaan yang terbuang, dan penyelesaiannya diserahkan ke
         * worker asinkron. */
        'stages_deferred'  => (array)($GLOBALS['__pp_stages_deferred'] ?? []),
        'candidates' => [
            'global_commitment_evaluated' => (int)($gcr['candidates_evaluated'] ?? 0),
            'fully_evaluated'             => !$budgetCut,
            'note' => $budgetCut
                ? 'Sebagian kandidat TIDAK dievaluasi karena batas waktu; hasil ini bukan optimum yang terbukti.'
                : 'Seluruh tahap pencarian selesai tanpa dipotong batas waktu.',
        ],
    ];
}

/* ==============================================================================================
 *  D-1 — CANDIDATE GENERATION KOTAK LEGAL (instruksi §3.2 dan §3.3)
 *
 *  PERAN. Menentukan, SEBELUM satu pun level dispatch dipilih, di interval legal MANA setiap unit
 *  ber-Skip-Load akan beroperasi. Keputusan itu dipasang sebagai kotak [lo,hi] pada dua sumber
 *  kebenaran batas yang dibaca SELURUH pass (max_load_rules / min_load_rules), sehingga nilai di
 *  dalam forbidden band tidak dapat diwakili oleh domain yang dilihat pass mana pun — tidak ada
 *  tambalan di ujung pipeline.
 *
 *  LANGKAH (§3.2). 1 applicable min/max aktual; 2 dipotong Unit Stop, Fix Load, availability, ramp,
 *  user lock; 3 forbidden interval dikeluarkan; 4 kandidat dibentuk HANYA dari interval legal;
 *  5 kandidat mencakup boundary legal, interval yang memuat titik ekonomis, dan kombinasi asimetris;
 *  6 G8/G9 dievaluasi ATOMIK sebagai level bersama lewat irisan himpunan legal; 7 unit lain
 *  menyesuaikan diri lewat pass redispatch engine yang sudah ada; 8 full recompute per kandidat;
 *  9 hard validation per kandidat; 10 economic comparison HANYA pada kandidat feasible.
 *
 *  PERINGKATAN MURAH. Peringkat kandidat dihitung dengan CORE RUN (satu simulasi inti), bukan
 *  pipeline penuh: core run terukur satu orde lebih murah, sehingga membandingkan 2-4 kandidat
 *  tetap jauh di bawah plafon request sinkron. Pipeline penuh dijalankan SEKALI pada kandidat
 *  pemenang — oleh pemanggil, karena fungsi ini hanya mengembalikan input yang sudah dikotakkan.
 * ============================================================================================ */
/* Waktu yang disisakan untuk pipeline penuh kandidat pemenang setelah peringkatan core run. */
const PP_BRANCH_CLOSING_RESERVE_S = 10.0;
/* Perkiraan biaya SATU pipeline penuh kandidat yang sudah dikotakkan. Bukan tebakan: terukur pada
 * fixture produksi — 40,96 s (cabang atas) dan 43,93 s (cabang bawah) untuk band G8 80-90. Dipakai
 * hanya untuk memutuskan apakah verifikasi eksak muat pada waktu yang tersisa. */
const PP_BRANCH_PIPELINE_EST_S = 45.0;

function pp_legal_branch_select(array $input): ?array {
    $d3    = (array)($input['data3'] ?? []);
    $model = (array)($d3['modeling'] ?? []);
    $sk    = (array)($model['unit_skip_load'] ?? []);
    if (!$sk) return null;
    $nRows = max(1, count((array)($input['data2'] ?? [])));
    if ($nRows < 2) $nRows = 48;

    /* ---- unit yang benar-benar terkena band dan ada di model ---------------------------------- */
    $units = [];
    foreach ($sk as $u => $rules) {
        $ul = strtolower((string)$u);
        if (!is_array($rules) || !$rules || !isset($d3[$ul])) continue;
        if (!pp_unit_present($d3, $ul)) continue;
        $units[] = $ul;
    }
    $units = array_values(array_unique($units));
    if (!$units) return null;

    /* ---- himpunan legal per unit, diambil pada row yang benar-benar ber-band ------------------ */
    $ivOf = [];  $bandRows = [];
    foreach ($units as $ul) {
        $rows = [];
        for ($r1 = 1; $r1 <= $nRows; $r1++) if (pp_skip_load_bands($model, $ul, $r1)) $rows[] = $r1;
        if (!$rows) continue;
        $bandRows[$ul] = $rows;
        /* HIMPUNAN LEGAL = IRISAN himpunan legal atas SELURUH row ber-band, bukan row pertama saja.
         *
         * TERUKUR, INILAH SEBABNYA. Sebuah unit dapat memiliki beberapa window band dengan rentang
         * MW berbeda (mis. G9: rows 5-12 band 80-88, rows 30-40 band 92-98). Mengambil row pertama
         * sebagai wakil menghasilkan kandidat [88,108] yang legal untuk rows 5-12 tetapi berada DI
         * DALAM band rows 30-40 — dan pelanggaran itu benar-benar muncul (G9 row 32 = 97,78).
         * Dengan irisan, kandidat menjadi [65,80] u [88,92] u [98,108]: legal untuk SEMUA row
         * ber-band unit itu. */
        $ivU = null;
        foreach ($rows as $rb) {
            $ivR = pp_legal_load_intervals($d3, $model, $ul, $rb);
            $ivU = ($ivU === null) ? $ivR : pp_intervals_intersect($ivU, $ivR);
            if (!$ivU) break;                                   // irisan kosong: konflik domain
        }
        $ivOf[$ul] = (array)$ivU;
    }
    if (!$ivOf) return null;

    /* ---- LEVEL BERSAMA G8=G9 (§3.3): irisan, lalu asimetris bila irisan kosong ----------------
     *  G8 dan G9 SELALU coupled pada plant ini: shaper menggerakkan keduanya sebagai satu level
     *  bersama $v. Karena itu kotak legal WAJIB dipasang pada KEDUA unit begitu SALAH SATU terkena
     *  band — bukan hanya pada unit yang ber-band.
     *
     *  TERUKUR, INILAH SEBABNYA. Dengan band hanya pada G8 dan kotak dipasang hanya pada G8
     *  (G8 >= 90), pass trim hilir menurunkan G9 sendirian sampai min-nya (65) karena G9 tidak
     *  ber-band. Hasilnya G8 = 90 sementara G9 = 65 sepanjang 15 row, lalu keduanya melonjak ke
     *  puncak bersama-sama: G9 berubah 32 MW dalam satu slot dan melanggar ramp 30 MW/30min.
     *  Itu berarti pelanggaran Skip Load ditukar dengan pelanggaran ramp — dilarang §3.4. Dengan
     *  kotak pada kedua unit, G9 ikut ter-floor, ramp smoothing level bersama tetap berlaku, dan
     *  tidak ada lonjakan. */
    $g8g9Banded = (isset($ivOf['g8']) || isset($ivOf['g9']));
    if ($g8g9Banded) {
        /* Unit pasangan yang TIDAK ber-band tetap ikut dikotakkan: himpunan legalnya adalah seluruh
         * rentang operasinya (tanpa band), sehingga irisan hanya dipersempit oleh unit yang ber-band. */
        foreach (['g8', 'g9'] as $pu) {
            if (isset($ivOf[$pu]) || !isset($d3[$pu]) || !pp_unit_present($d3, $pu)) continue;
            $rRef = $bandRows['g8'][0] ?? $bandRows['g9'][0] ?? 1;
            $ivOf[$pu] = pp_legal_load_intervals($d3, $model, $pu, $rRef);
            $bandRows[$pu] = $bandRows['g8'] ?? $bandRows['g9'] ?? range(1, $nRows);
        }
    }
    $coupled = (isset($ivOf['g8']) && isset($ivOf['g9']));
    $candidates = [];                      // [label, branch-spec]
    $evidence   = ['banded_units' => array_keys($ivOf), 'coupled_g8_g9' => $coupled,
                   'legal_intervals' => array_map(
                       fn($iv) => array_map(fn($b) => [round((float)$b[0], 2), round((float)$b[1], 2)], $iv), $ivOf)];

    if ($coupled) {
        /* Row yang dikenai kotak = GABUNGAN row ber-band kedua unit. Dengan begitu band satu row
         * hanya mengotakkan row itu, dan unit pasangan yang tidak ber-band ikut dikotakkan tepat
         * pada row yang sama — tidak lebih luas. */
        $coRows = array_values(array_unique(array_merge($bandRows['g8'] ?? [], $bandRows['g9'] ?? [])));
        sort($coRows);
        $evidence['coupled_box_rows_count'] = count($coRows);
        $inter = pp_intervals_intersect($ivOf['g8'], $ivOf['g9']);
        $evidence['shared_level_intersection'] =
            array_map(fn($b) => [round((float)$b[0], 2), round((float)$b[1], 2)], $inter);
        foreach ($inter as $k => $box) {
            $candidates[] = [sprintf('SHARED[%.2f,%.2f]', $box[0], $box[1]),
                             ['g8' => ['box' => [$box[0], $box[1]], 'rows' => $coRows],
                              'g9' => ['box' => [$box[0], $box[1]], 'rows' => $coRows]]];
        }
        if (!$inter) {
            /* Irisan kosong: level bersama tidak mungkin legal bagi keduanya. Plant model
             * MENGIZINKAN dispatch asimetris (setiap unit punya cap/floor sendiri dan $mkG12
             * meng-clamp per unit), jadi kandidat asimetris dibentuk sebagai kombinasi interval
             * masing-masing unit. Bila tidak ada satu pun kombinasi, conflict set dilaporkan. */
            $evidence['shared_level_feasible'] = false;
            foreach ($ivOf['g8'] as $b8) foreach ($ivOf['g9'] as $b9) {
                $candidates[] = [sprintf('ASYM g8[%.2f,%.2f] g9[%.2f,%.2f]', $b8[0], $b8[1], $b9[0], $b9[1]),
                                 ['g8' => ['box' => [$b8[0], $b8[1]], 'rows' => $coRows],
                                  'g9' => ['box' => [$b9[0], $b9[1]], 'rows' => $coRows]]];
            }
        }
        /* Unit ber-band lain (mis. G3) tetap dienumerasi bersama kandidat di atas. */
        foreach ($ivOf as $ul => $iv) {
            if ($ul === 'g8' || $ul === 'g9') continue;
            $grown = [];
            foreach ($candidates as $c) foreach ($iv as $bx) {
                $spec = $c[1];
                $spec[$ul] = ['box' => [$bx[0], $bx[1]], 'rows' => ($bandRows[$ul] ?? [])];
                $grown[] = [$c[0] . sprintf(' %s[%.2f,%.2f]', strtoupper($ul), $bx[0], $bx[1]), $spec];
            }
            if ($grown) $candidates = $grown;
        }
    } else {
        $candidates = [['', []]];
        foreach ($ivOf as $ul => $iv) {
            $grown = [];
            foreach ($candidates as $c) foreach ($iv as $bx) {
                $spec = $c[1];
                $spec[$ul] = ['box' => [$bx[0], $bx[1]], 'rows' => ($bandRows[$ul] ?? [])];
                $grown[] = [trim($c[0] . sprintf(' %s[%.2f,%.2f]', strtoupper($ul), $bx[0], $bx[1])), $spec];
            }
            $candidates = $grown ?: $candidates;
        }
        if (count($candidates) === 1 && !$candidates[0][1]) $candidates = [];
    }

    /* ---- HIMPUNAN LEGAL KOSONG: konflik domain, dilaporkan spesifik (tanpa simulasi panjang) --- */
    if (!$candidates) {
        $conf = [];
        foreach ($ivOf as $ul => $iv) {
            if ($iv) continue;
            $r1 = $bandRows[$ul][0] ?? 1;
            $conf[] = ['unit' => strtoupper($ul),
                'forbidden_bands' => array_map(fn($b) => [round((float)$b[0], 2), round((float)$b[1], 2)],
                                               pp_skip_load_bands($model, $ul, $r1)),
                'applicable_min' => round(pp_effective_min_load($d3, $model, $ul, $r1), 2),
                'applicable_max' => round(pp_effective_max_load($d3, $model, $ul, $r1), 2),
                'rows' => array_slice($bandRows[$ul] ?? [], 0, 12),
                'rows_count' => count($bandRows[$ul] ?? []),
                'reason' => 'LEGAL_INTERVAL_KOSONG: band Skip Load menutup seluruh rentang operasi yang berlaku'];
        }
        $GLOBALS['__pp_skip_load_branch'] = $evidence + [
            'candidates_evaluated' => 0, 'selected' => null,
            'status' => 'DOMAIN_INFEASIBLE', 'conflict_set' => $conf];
        return null;                      // pipeline lanjut; final invariant menerbitkan pelanggaran
    }

    /* ---- SATU KANDIDAT: tidak ada yang perlu dibandingkan ------------------------------------- */
    $mkInput = function (array $in, array $spec) use ($nRows): array {
        $d3c = (array)($in['data3'] ?? []); $mc = (array)($d3c['modeling'] ?? []);
        $applied = pp_apply_legal_branch($d3c, $mc, $spec, $nRows);
        $mc['__legal_branch_rules'] = (array)($GLOBALS['__pp_legal_branch_rules'] ?? []);
        $mc['__legal_branch'] = ['spec' => array_map(function ($b) {
            if (is_array($b) && isset($b['box'])) return ['box' => [round((float)$b['box'][0], 2),
                round((float)$b['box'][1], 2)], 'rows_count' => count((array)($b['rows'] ?? []))];
            if (is_array($b) && isset($b[0], $b[1])) return [round((float)$b[0], 2), round((float)$b[1], 2)];
            return $b;
        }, $spec), 'boxes' => $applied];
        $d3c['modeling'] = $mc; $in['data3'] = $d3c;
        return $in;
    };
    if (count($candidates) === 1) {
        $in1 = $mkInput($input, $candidates[0][1]);
        $GLOBALS['__pp_skip_load_branch'] = $evidence + [
            'candidates_evaluated' => 1, 'candidates' => [['label' => $candidates[0][0], 'selected' => true,
                'reason' => 'hanya satu interval legal tersedia — tidak ada pilihan ekonomis']],
            'selected' => $candidates[0][0], 'status' => 'SINGLE_LEGAL_BRANCH',
            'boxes' => $in1['data3']['modeling']['__legal_branch']['boxes'] ?? [],
            'applied_rules' => (array)($in1['data3']['modeling']['__legal_branch_rules'] ?? [])];
        return $in1;
    }

    /* ---- PERINGKATAN: core run per kandidat, hard validation, lalu economic comparison -------- */
    /* SNAPSHOT PENUH STATE ENGINE. Pencarian ini berjalan DI DALAM pipeline, bukan sebagai probe
     * top-level, jadi ia tidak boleh membuang state yang sudah disiapkan pemanggil (budget, deadline,
     * timeline fase, konteks worker asinkron, penghitung core run). Pendekatan "hapus semua __pp_*
     * lalu pulihkan daftar-putih" terbukti salah di sini: satu global yang tidak masuk daftar hilang,
     * budget menjadi tidak ter-arm, dan run berikutnya menabrak deadline. Karena itu SELURUH state
     * __pp_* di-snapshot dan dipulihkan persis, sehingga pencarian benar-benar tidak berefek samping. */
    $__snap = [];
    foreach (array_keys($GLOBALS) as $g) if (str_starts_with($g, '__pp_')) $__snap[$g] = $GLOBALS[$g];
    $__restore = function () use ($__snap) {
        foreach (array_keys($GLOBALS) as $g) if (str_starts_with($g, '__pp_')) unset($GLOBALS[$g]);
        foreach ($__snap as $k => $v) $GLOBALS[$k] = $v;
    };

    /* ---- REFERENSI TANPA KOTAK: titik operasi ekonomis yang dikejar solver bila band tidak ada.
     * Dipakai sebagai ukuran PERTURBASI: cabang legal yang paling dekat dengan titik operasi
     * tanpa-band adalah cabang yang paling sedikit mengguncang rencana, dan karena itu paling kecil
     * kemungkinannya memicu pelanggaran baru di pass hilir. Ini HEURISTIK PROVISIONAL yang dipakai
     * hanya untuk memilih kandidat yang dijalankan pada request sinkron; pemenang EKSAK ditentukan
     * job `legal_branch_exact` yang menjalankan pipeline penuh untuk SETIAP kandidat. */
    $refLevel = [];
    {
        $t0r = microtime(true);
        $outR = pp_run_simulation_core($input);
        foreach ((array)($outR['data'] ?? []) as $ri => $rw)
            $refLevel[$ri + 1] = ['g8' => (float)($rw['G8'] ?? 0), 'g9' => (float)($rw['G9'] ?? 0)];
        $evidence['reference_core_run_seconds'] = round(microtime(true) - $t0r, 3);
        $__restore();
    }

    /* ---- KANDIDAT PER ROW (§3.2 butir 5: "compensation-required point") ----------------------
     *  Satu kotak seragam untuk seluruh row ber-band adalah pembatasan yang lebih kuat daripada
     *  yang diminta kontrak: band hanya melarang nilai DI DALAMNYA, bukan memaksa unit tinggal di
     *  satu sisi sepanjang hari. Row yang titik ekonomisnya di bawah band boleh memakai interval
     *  bawah sementara row yang titik ekonomisnya di atas band memakai interval atas.
     *
     *  TERUKUR, INILAH SEBABNYA. Band G9 66-70 (dekat applicable minimum): kotak seragam [70,108]
     *  menaikkan row yang aslinya beroperasi di 67-68 sebanyak 3 MW pada DUA unit sekaligus, dan
     *  satu baris tidak dapat dikompensasi lagi — tersisa satu pelanggaran Export sebesar 4,91 MW.
     *  Kotak seragam [65,66] memindahkan masalahnya, bukan menghapusnya. Kandidat per row memilih
     *  sisi yang paling dekat dengan titik operasi referensi untuk SETIAP row, sehingga guncangan
     *  terhadap rencana minimum dan kompensasi yang dibutuhkan pass hilir paling kecil.
     *
     *  KOHERENSI RAMP. Berpindah sisi berarti melompati seluruh lebar band dalam satu slot. Bila
     *  lebar band melampaui batas ramp unit, perpindahan itu tidak layak dan seluruh window dipaksa
     *  satu sisi (pp_branch_ramp_coherent) — supaya pelanggaran Skip Load tidak ditukar dengan
     *  pelanggaran ramp. */
    if ($coupled && $refLevel && count($inter ?? []) > 1) {
        $rowBox = []; $rowSide = [];
        $bandW = 0.0;
        foreach ($coRows as $r1) {
            $ref = $refLevel[$r1] ?? null;
            $v = $ref ? max((float)$ref['g8'], (float)$ref['g9']) : 0.0;
            $ivR = pp_intervals_intersect(
                pp_legal_load_intervals($d3, $model, 'g8', $r1),
                pp_legal_load_intervals($d3, $model, 'g9', $r1));
            if (!$ivR) continue;
            $pick = null;
            foreach ($ivR as $bx) if ($v >= $bx[0] - PP_LOAD_EPS && $v <= $bx[1] + PP_LOAD_EPS) { $pick = $bx; break; }
            if ($pick === null) {                              // referensi di dalam band: pilih tepi terdekat
                $best = null; $bd = INF;
                foreach ($ivR as $bx) {
                    $c = min(max($v, (float)$bx[0]), (float)$bx[1]);
                    if (abs($c - $v) < $bd - PP_LOAD_EPS) { $bd = abs($c - $v); $best = $bx; }
                }
                $pick = $best;
            }
            if ($pick === null) continue;
            $rowBox[$r1] = ['box' => [(float)$pick[0], (float)$pick[1]], 'rows' => [$r1]];
            $rowSide[$r1] = sprintf('%.2f-%.2f', $pick[0], $pick[1]);
            foreach (pp_skip_load_bands($model, 'g8', $r1) as $bb) $bandW = max($bandW, $bb[1] - $bb[0]);
            foreach (pp_skip_load_bands($model, 'g9', $r1) as $bb) $bandW = max($bandW, $bb[1] - $bb[0]);
        }
        /* Koherensi ramp: bila lebar band melampaui batas ramp G8/G9 (30 MW/30min), sisi tidak boleh
         * berganti-ganti antar row — seluruh window dipaksa sisi mayoritas. */
        $distinct = array_values(array_unique($rowSide));
        if ($rowBox && count($distinct) > 1 && $bandW > 30.0 + PP_LOAD_EPS) {
            $cnt = array_count_values($rowSide);
            arsort($cnt); $win = (string)array_key_first($cnt);
            foreach ($rowSide as $r1 => $sd) if ($sd !== $win) {
                [$a, $b2] = array_map('floatval', explode('-', $win));
                $rowBox[$r1] = ['box' => [$a, $b2], 'rows' => [$r1]];
            }
            $distinct = [$win];
        }
        /* BATAS KEAMANAN KANDIDAT PER ROW — hanya kotak BERJENIS CAP yang boleh dicampur antar row.
         *
         * TERUKUR, INILAH SEBABNYA. Batas ATAS per row ditegakkan lewat `max_load_rules`, yang memang
         * sudah dibaca seluruh pass lewat pp_effective_maxload(). Batas BAWAH per row ditegakkan
         * lewat `min_load_rules` yang hanya dibaca pp_effective_min_load(); sekitar 89 titik pada
         * jalur startup dan commitment masih membaca `$d3[$u]['min_ccload']` LANGSUNG. Untuk kotak
         * yang berlaku SEPANJANG HARI hal itu aman karena skalar `min_ccload` ikut dinaikkan, tetapi
         * untuk kotak PER ROW skalar tidak dapat dipakai — menaikkannya akan ikut mengunci row yang
         * justru harus berada di bawah band. Akibatnya floor per row tidak tertegakkan pada jalur
         * itu, dan beban kembali masuk ke dalam band (terukur: G9 row 34 = 89,23 dan row 41 = 86,04
         * pada band 80-90). Menukar satu pelanggaran dengan pelanggaran lain dilarang, jadi kandidat
         * per row dengan campuran floor+cap TIDAK dibentuk sampai ~89 pembaca langsung itu dirutekan
         * ke pp_effective_min_load(). Kandidat kotak seragam tetap tersedia, dan pemenang eksaknya
         * ditentukan job `legal_branch_exact`. */
        $needsFloor = false;
        foreach ($rowBox as $r1 => $rb)
            if ((float)$rb['box'][0] > pp_effective_min_load($d3, $model, 'g9', (int)$r1) + PP_LOAD_EPS
             || (float)$rb['box'][0] > pp_effective_min_load($d3, $model, 'g8', (int)$r1) + PP_LOAD_EPS)
                { $needsFloor = true; break; }
        if ($needsFloor) {
            $evidence['per_row_candidate_skipped'] =
                'Kandidat per row membutuhkan batas bawah per row; penegakannya belum lengkap pada '
                . 'jalur yang membaca min_ccload langsung, sehingga kandidat ini tidak dibentuk agar '
                . 'pelanggaran Skip Load tidak ditukar dengan pelanggaran lain.';
            $distinct = [];
        }
        if ($rowBox && count($distinct) > 1) {
            /* Gabungkan menjadi satu spec per unit: daftar row dikelompokkan per kotak. */
            $byBox = [];
            foreach ($rowBox as $r1 => $rb) $byBox[sprintf('%.4f|%.4f', $rb['box'][0], $rb['box'][1])][] = $r1;
            $spec8 = []; $spec9 = [];
            foreach ($byBox as $key => $rws) {
                [$a, $b2] = array_map('floatval', explode('|', $key));
                foreach ($rws as $r1) { $spec8[$r1] = [$a, $b2]; $spec9[$r1] = [$a, $b2]; }
            }
            $candidates[] = ['PER_ROW(' . count($byBox) . ' interval)',
                             ['g8' => $spec8, 'g9' => $spec9]];
            $evidence['per_row_candidate'] = ['intervals_used' => array_keys($byBox),
                'rows_per_interval' => array_map('count', $byBox)];
        }
    }

    /* PERTURBASI ANALITIK. Nilai perturbasi sebuah kandidat dapat dihitung LANGSUNG dari kotaknya
     * terhadap trajektori referensi — proyeksi nilai referensi ke dalam kotak — tanpa menjalankan
     * core run per kandidat. Itu memangkas N core run menjadi SATU. Terukur penting: pada band
     * multi-window kandidatnya tiga, dan empat core run ditambah satu pipeline penuh menabrak
     * deadline request (60,2 s, DEADLINE_REACHED). Core run per kandidat tetap dijalankan sebagai
     * bukti tambahan HANYA bila waktu memang tersisa. */
    $perturbOf = function (array $spec) use ($refLevel): float {
        $sum = 0.0; $n2 = 0;
        foreach ($refLevel as $r1 => $ref) {
            foreach (['g8', 'g9'] as $u) {
                $sel = $spec[$u] ?? null; if ($sel === null) continue;
                $box = (is_array($sel) && isset($sel['box'])) ? $sel['box']
                     : ((is_array($sel) && isset($sel[0], $sel[1])) ? [$sel[0], $sel[1]] : null);
                if ($box === null) continue;
                $rows = (is_array($sel) && isset($sel['rows'])) ? (array)$sel['rows'] : null;
                if ($rows !== null && !in_array((int)$r1, array_map('intval', $rows), true)) continue;
                $v = (float)$ref[$u];
                if ($v <= PP_UNIT_ON_MW) continue;               // unit OFF tidak digeser kotak
                $sum += abs(min(max($v, (float)$box[0]), (float)$box[1]) - $v);
                $n2++;
            }
        }
        return $n2 ? $sum / $n2 : 0.0;
    };

    $rank = []; $evalN = 0;
    foreach ($candidates as [$label, $spec]) {
        /* ANGGARAN: yang relevan adalah DEADLINE REQUEST, bukan tangga adaptif pp_budget_left().
         * Memakai pp_budget_left() salah dan terukur merusak: nilainya sudah kecil di awal request
         * (limit awal 10 detik), sehingga kandidat kedua selalu dipotong dan solver memilih cabang
         * yang bukan termurah. Peringkatan memakai CORE RUN yang terukur 0,5-0,8 detik, jadi margin
         * yang dibutuhkan kecil; sisakan waktu untuk satu pipeline penuh kandidat pemenang. */
        $__dl = pp_budget_deadline_left();
        $__need = max(2.0, ($rank ? max(array_column($rank, 'core_run_seconds')) : 1.0) * 2.0);
        if ($evalN > 0 && is_finite($__dl) && $__dl < $__need + PP_BRANCH_CLOSING_RESERVE_S) {
            $evidence['search_truncated_by_budget'] = true;
            $evidence['deadline_left_at_truncation_s'] = round($__dl, 2);
            /* Kandidat cabang Skip Load yang tidak dievaluasi = pencarian belum lengkap: hasil ini
             * tidak boleh dinyatakan konvergen (job exact menyelesaikannya dengan anggaran penuh). */
            if (function_exists('pp_stage_defer')) pp_stage_defer('unit_skip_load_branch', $__need, ['candidates_done' => $evalN]);
            break;
        }
        $inC = $mkInput($input, $spec);
        /* Core run per kandidat hanya dijalankan bila waktu tersisa memang cukup untuk SELURUH
         * kandidat DITAMBAH satu pipeline penuh; bila tidak, peringkatan memakai perturbasi
         * analitik saja dan fakta itu dicatat. */
        $__dlC = pp_budget_deadline_left();
        $__needC = PP_BRANCH_PIPELINE_EST_S + PP_BRANCH_CLOSING_RESERVE_S
                 + 2.0 * (count($candidates) - $evalN);
        if (is_finite($__dlC) && $__dlC < $__needC) {
            $evidence['per_candidate_core_run_skipped'] = true;
            if (function_exists('pp_stage_defer') && empty($GLOBALS['__pp_stages_deferred']['unit_skip_load_branch']))
                pp_stage_defer('unit_skip_load_branch', $__needC, ['candidates_done' => $evalN]);
            $rank[] = ['perturbation_mw' => round($perturbOf($spec), 4),
                'label' => $label, 'spec' => $spec, 'feasible' => false,
                'violations' => -1, 'skip_load_violations' => -1, 'hard_violations' => -1,
                'violation_types' => [], 'cost_plant' => 0.0, 'cost_jbbk' => 0.0,
                'heat_rate' => 0.0, 'gas_used' => 0.0, 'gas_quota' => 0.0,
                'core_run_seconds' => 0.0];
            continue;
        }
        $t0 = microtime(true);
        $outC = pp_run_simulation_core($inC);
        $wall = microtime(true) - $t0;
        $evalN++;
        $VC = pp_validate_hard_constraints($inC, $outC);
        $viol = array_values((array)($VC['violations'] ?? []));
        /* Pelanggaran Skip Load dinilai ulang dari 48 baris dengan sumber kebenaran tunggal. */
        $skV = 0;
        foreach ((array)($outC['data'] ?? []) as $ri => $rw) {
            foreach (array_keys($spec) as $ul)
                if (pp_load_in_forbidden_band($model, $ul, $ri + 1,
                                              (float)($rw[strtoupper($ul)] ?? 0), true)) $skV++;
        }
        $iC = (array)($outC['info'] ?? []);
        $types = [];
        foreach ($viol as $v) { $t2 = (string)($v[0] ?? 'unknown'); $types[$t2] = ($types[$t2] ?? 0) + 1; }
        /* KLASIFIKASI PELANGGARAN UNTUK PERINGKATAN. Kekurangan gas BUKAN cacat rekayasa: ia adalah
         * keadaan terminal sah yang diselesaikan operator lewat keputusan bahan bakar (§3.4
         * mengizinkan gas_constraint_status = USER_FUEL_DECISION_REQUIRED). Sebaliknya export_range,
         * export_ramp, unit_ramp, unit_limit, reserve, dan bus_flow adalah pelanggaran keras yang
         * TIDAK boleh ditukar dengan pelanggaran Skip Load. Karena itu peringkatan memakai jumlah
         * pelanggaran KERAS lebih dulu, baru biaya — bukan biaya lebih dulu.
         *
         * TERUKUR, INILAH SEBABNYA. Untuk band G8 80-90, cabang ATAS lebih murah (61,26 USD/MWh)
         * daripada cabang BAWAH (64,60) tetapi menghasilkan export_ramp 36,7 > 35 MW. Mengurutkan
         * dengan biaya lebih dulu memilih cabang yang menukar satu pelanggaran dengan pelanggaran
         * lain; mengurutkan dengan pelanggaran keras lebih dulu memilih cabang yang sah. */
        $fuelClasses = ['gas_quota' => 1, 'runtime_downtime' => 1, 'mm2100_quota' => 1,
                        'gas_window' => 1, 'distillate_quota' => 1, 'lng_quota' => 1];
        $hardViol = 0;
        foreach ($viol as $v) if (!isset($fuelClasses[(string)($v[0] ?? '')])) $hardViol++;
        /* PERTURBASI terhadap referensi tanpa kotak, rata-rata MW per row atas G8 dan G9. */
        $pSum = 0.0; $pN = 0;
        foreach ((array)($outC['data'] ?? []) as $ri => $rw) {
            $ref = $refLevel[$ri + 1] ?? null; if ($ref === null) continue;
            $pSum += abs((float)($rw['G8'] ?? 0) - $ref['g8']) + abs((float)($rw['G9'] ?? 0) - $ref['g9']);
            $pN++;
        }
        $rank[] = [
            'perturbation_mw' => $pN ? round($pSum / $pN, 4) : 0.0,
            'label' => $label, 'spec' => $spec,
            'feasible' => (count($viol) === 0 && $skV === 0),
            'violations' => count($viol), 'skip_load_violations' => $skV,
            'hard_violations' => $hardViol,
            'violation_types' => $types,
            'cost_plant' => round((float)($iC['Total Plant Cost Production (USD/MWh)'] ?? 0), 6),
            'cost_jbbk'  => round((float)($iC['JBBK MM Cost Production (USD/MWh)'] ?? 0), 6),
            'heat_rate'  => round((float)($iC['JBBK MM Heat Rate (BTU/kWh)'] ?? 0), 4),
            'gas_used'   => round((float)($iC['Total Gas Used (BBTUD)'] ?? 0), 4),
            'gas_quota'  => round((float)($iC['Total Gas Quota (BBTUD)'] ?? 0), 4),
            'core_run_seconds' => round($wall, 3),
        ];
        $__restore();
    }

    /* ECONOMIC COMPARISON HANYA PADA KANDIDAT FEASIBLE (§3.2 butir 10). Bila tidak ada yang
     * feasible, dipilih yang pelanggarannya paling sedikit — bukan untuk menyamarkan kegagalan,
     * melainkan agar operator melihat rencana terbaik yang ada beserta conflict set dari final
     * invariant, yang tetap menahan Publish. */
    $feas = array_values(array_filter($rank, fn($x) => $x['feasible']));
    $pool = $feas ?: $rank;
    /* SATU SUMBER KEBENARAN URUTAN KANDIDAT — lihat pp_branch_candidate_cmp(). Urutan tidak lagi
     * ditulis ulang di sini, di loop verifikasi, maupun di job asinkron. */
    usort($pool, fn(array $a, array $b): int => pp_branch_candidate_cmp([
        'label' => $a['label'], 'skip_load_violations' => $a['skip_load_violations'],
        'violations' => $a['violations'], 'violation_types' => $a['violation_types'],
        'cost_plant_usd_mwh' => $a['cost_plant'], 'cost_jbbk_usd_mwh' => $a['cost_jbbk'],
        'heat_rate' => $a['heat_rate'], 'evaluated' => ($a['skip_load_violations'] >= 0),
    ], [
        'label' => $b['label'], 'skip_load_violations' => $b['skip_load_violations'],
        'violations' => $b['violations'], 'violation_types' => $b['violation_types'],
        'cost_plant_usd_mwh' => $b['cost_plant'], 'cost_jbbk_usd_mwh' => $b['cost_jbbk'],
        'heat_rate' => $b['heat_rate'], 'evaluated' => ($b['skip_load_violations'] >= 0),
    ]));
    $best = $pool[0] ?? null;
    if ($best === null) return null;

    $inBest = $mkInput($input, $best['spec']);
    /* Daftar kandidat TERURUT beserta input yang sudah dikotakkan, untuk tahap verifikasi pipeline
     * penuh di pemanggil (verify-and-fall-through). Core run tidak dapat meramalkan pelanggaran yang
     * baru muncul di pass hilir (terukur: export_ramp pada cabang ATAS band G8 80-90 hanya muncul
     * setelah minimisasi Export), jadi kandidat teratas WAJIB diverifikasi dengan pipeline penuh dan
     * ditinggalkan bila ternyata menukar pelanggaran. */
    $GLOBALS['__pp_legal_branch_queue'] = array_map(
        fn($x) => ['label' => $x['label'], 'input' => $mkInput($input, $x['spec'])], $pool);
    $GLOBALS['__pp_skip_load_branch'] = $evidence + [
        'candidates_evaluated' => $evalN,
        'candidates_total' => count($candidates),
        'all_candidates_evaluated' => ($evalN === count($candidates)),
        'candidates' => array_map(fn($x) => [
            'label' => $x['label'], 'feasible' => $x['feasible'],
            'skip_load_violations' => $x['skip_load_violations'],
            'hard_violations' => $x['hard_violations'],
            'perturbation_mw' => $x['perturbation_mw'],
            'violations' => $x['violations'], 'violation_types' => $x['violation_types'],
            'cost_plant_usd_mwh' => $x['cost_plant'], 'cost_jbbk_usd_mwh' => $x['cost_jbbk'],
            'gas_used_bbtud' => $x['gas_used'], 'core_run_seconds' => $x['core_run_seconds'],
            'selected' => ($x['label'] === $best['label']),
        ], $rank),
        'selected' => $best['label'],
        'selected_feasible' => $best['feasible'],
        'status' => $feas ? 'SELECTED_CHEAPEST_FEASIBLE' : 'NO_FEASIBLE_BRANCH_BEST_EFFORT',
        'boxes' => $inBest['data3']['modeling']['__legal_branch']['boxes'] ?? [],
        'applied_rules' => (array)($inBest['data3']['modeling']['__legal_branch_rules'] ?? []),
        /* KEJUJURAN METODE. Peringkatan di sini memakai CORE RUN: satu simulasi inti penuh dengan
         * recompute dan hard validation, tetapi BELUM melewati pass rekonsiliasi pipeline (decommit,
         * koreksi window gas, minimisasi Export). Karena itu urutan biaya yang dihasilkannya adalah
         * urutan TERUKUR, bukan pembuktian optimalitas. Perbandingan EKSAK antar cabang — pipeline
         * penuh untuk setiap kandidat — diserahkan ke worker asinkron, karena satu pipeline penuh
         * per kandidat terukur 18-45 detik dan dua kandidat tidak muat di plafon request sinkron. */
        'ranking_method' => 'CORE_RUN_MEASURED',
        'exact_economic_comparison' => false,
        'exact_comparison_candidates' => array_values(array_map(
            fn($x) => ['label' => $x['label'], 'spec' => $x['spec']],
            array_filter($rank, fn($x) => $x['label'] !== $best['label']))),
    ];
    return $inBest;
}

function pp_run_simulation_pipeline(array $input): array {
    /* ===== D-1 CANDIDATE GENERATION: PEMILIHAN KOTAK LEGAL SEBELUM DISPATCH DIPILIH ==========
     * Dijalankan SEBELUM core run pertama, karena keputusan "unit berada di interval legal yang
     * MANA" adalah keputusan domain dan harus ditetapkan sebelum satu pun level dispatch dipilih.
     * Solver karena itu tidak pernah memilih nilai di dalam forbidden band lalu menambalnya.
     * Idempotent: kandidat yang dijalankan ulang sudah membawa penanda __legal_branch dan tidak
     * masuk lagi ke pencarian ini. */
    /* DITEMPATKAN DI PALING ATAS PIPELINE, SEBELUM sweep Change Over.
     * Sweep Change Over (pp_changeover_sim_sweep) mengembalikan hasil dan KELUAR dari pipeline
     * sebelum titik mana pun di bawahnya. Terukur: dengan Change Over aktif, pemilihan cabang tidak
     * pernah dijalankan dan 9 baris G9 mendarat di dalam forbidden band. Karena kotak legal adalah
     * pembatasan DOMAIN, ia harus sudah terpasang pada input sebelum sweep menjalankan simulasi
     * bersarangnya — dengan begitu seluruh kandidat Change Over pun ikut terkotak. */
    if ((int)($GLOBALS['__pp_sim_depth'] ?? 0) === 0
        && empty($GLOBALS['__pp_co_sweeping'])
        && !empty($input['data3']['modeling']['unit_skip_load'])
        && empty($input['data3']['modeling']['__legal_branch'])) {
        unset($GLOBALS['__pp_legal_branch_queue']);
        $__brIn = pp_legal_branch_select($input);
        $__queue = (array)($GLOBALS['__pp_legal_branch_queue'] ?? []);
        unset($GLOBALS['__pp_legal_branch_queue']);
        /* KAPAN VERIFIKASI PIPELINE PENUH DIJALANKAN DI SINI.
         * Satu pipeline penuh kandidat terukur 40-44 detik pada fixture ini, sehingga memverifikasi
         * dua kandidat butuh ~87 detik — melewati plafon request sinkron 60 detik. Mencoba lalu
         * terpotong justru menghasilkan DEADLINE_REACHED (terukur). Karena itu loop verifikasi
         * hanya berjalan bila waktu tersisa memang cukup untuk SELURUH kandidat; pada request
         * sinkron ia tidak berjalan, dan perbandingan eksak diserahkan ke job `legal_branch_exact`
         * berplafon 1800 detik. Di dalam worker, loop yang SAMA inilah yang berjalan penuh. */
        $__dl0 = pp_budget_deadline_left();
        $__fits = (!is_finite($__dl0))
            || ($__dl0 >= PP_BRANCH_PIPELINE_EST_S * count($__queue) + PP_BRANCH_CLOSING_RESERVE_S);
        if ($__brIn !== null && count($__queue) > 1 && $__fits) {
            /* VERIFIKASI PIPELINE PENUH, urut peringkat, sampai ketemu kandidat yang tidak menukar
             * pelanggaran. Dibatasi deadline request: kandidat yang tidak sempat diverifikasi
             * dicatat apa adanya dan diserahkan ke perbandingan eksak worker asinkron. */
            $__brEv = (array)($GLOBALS['__pp_skip_load_branch'] ?? []);
            $__verified = []; $__accepted = null; $__acceptedOut = null;
            $__fuelCls = ['gas_quota' => 1, 'runtime_downtime' => 1, 'mm2100_quota' => 1,
                          'gas_window' => 1, 'distillate_quota' => 1, 'lng_quota' => 1];
            foreach ($__queue as $__qi => $__q) {
                if ($__qi > 0) {
                    $__dl = pp_budget_deadline_left();
                    $__cost = $__verified ? max(array_column($__verified, 'pipeline_seconds')) : 25.0;
                    if (is_finite($__dl) && $__dl < $__cost * 1.15 + PP_BRANCH_CLOSING_RESERVE_S) {
                        $__brEv['verification_truncated_by_budget'] = true;
                        $__brEv['verification_deadline_left_s'] = round($__dl, 2);
                        break;
                    }
                }
                $__t = microtime(true);
                $__o = pp_run_simulation_pipeline($__q['input']);
                $__w = microtime(true) - $__t;
                $__V = pp_validate_hard_constraints($__q['input'], $__o);
                $__vs = array_values((array)($__V['violations'] ?? []));
                $__hard = 0; $__types = [];
                foreach ($__vs as $__v) {
                    $__k = (string)($__v[0] ?? 'unknown');
                    $__types[$__k] = ($__types[$__k] ?? 0) + 1;
                    if (!isset($__fuelCls[$__k])) $__hard++;
                }
                $__mdl = (array)($__q['input']['data3']['modeling'] ?? []);
                $__sk = 0;
                foreach ((array)($__o['data'] ?? []) as $__ri => $__rw)
                    foreach (array_keys((array)($__mdl['unit_skip_load'] ?? [])) as $__uu)
                        if (pp_load_in_forbidden_band($__mdl, strtolower((string)$__uu), $__ri + 1,
                            (float)($__rw[strtoupper((string)$__uu)] ?? 0), true)) $__sk++;
                $__iC = (array)($__o['info'] ?? []);
                $__verified[] = ['label' => $__q['label'], 'pipeline_seconds' => round($__w, 2),
                    'skip_load_violations' => $__sk, 'hard_violations' => $__hard,
                    'violations' => count($__vs), 'violation_types' => $__types,
                    'hard_validation' => (string)($__V['status'] ?? ''),
                    'cost_plant_usd_mwh' => round((float)($__iC['Total Plant Cost Production (USD/MWh)'] ?? 0), 6),
                    'accepted' => false];
                if ($__sk === 0 && $__hard === 0) {
                    $__accepted = $__q['label']; $__acceptedOut = $__o;
                    $__verified[count($__verified) - 1]['accepted'] = true;
                    break;                           // kandidat sah pertama menurut urutan biaya
                }
            }
            $__brEv['pipeline_verification'] = $__verified;
            $__brEv['verified_all'] = (count($__verified) === count($__queue));
            if ($__accepted !== null) {
                $__brEv['selected'] = $__accepted;
                $__brEv['applied_rules'] = (array)($__q['input']['data3']['modeling']['__legal_branch_rules'] ?? []);
                $__brEv['selected_feasible'] = true;
                $__brEv['status'] = 'SELECTED_VERIFIED_CHEAPEST_WITHOUT_TRADE';
                $__brEv['exact_economic_comparison'] = $__brEv['verified_all'] ?? false;
                $GLOBALS['__pp_skip_load_branch'] = $__brEv;
                /* Output kandidat dibangun di dalam pipeline bersarang, jadi evidence yang menempel
                 * padanya masih evidence peringkatan core run. Ditimpa dengan hasil verifikasi agar
                 * yang dilaporkan ke operator adalah cabang yang BENAR-BENAR dipakai. */
                $__acceptedOut['info']['Unit Skip Load Branch'] = $__brEv;
                return $__acceptedOut;   // depth belum dinaikkan di titik ini — tidak ada yang perlu dipulihkan
            }
            /* Tidak ada kandidat tanpa trade: pakai yang paling sedikit pelanggaran kerasnya di
             * antara yang terverifikasi, agar operator melihat rencana terbaik yang ada. */
            if ($__verified) {
                usort($__verified, fn(array $a, array $b): int => pp_branch_candidate_cmp($a, $b));
                $__pick = $__verified[0]['label'];
                foreach ($__queue as $__q) if ($__q['label'] === $__pick) { $__brIn = $__q['input'];
                    $__brEv['applied_rules'] = (array)($__q['input']['data3']['modeling']['__legal_branch_rules'] ?? []); break; }
                $__brEv['selected'] = $__pick;
                $__brEv['selected_feasible'] = false;
                $__brEv['status'] = 'NO_BRANCH_WITHOUT_TRADE_BEST_VERIFIED';
            }
            $__brEv['exact_economic_comparison'] = $__brEv['verified_all'] ?? false;
            $GLOBALS['__pp_skip_load_branch'] = $__brEv;
        }
        if ($__brIn !== null && count($__queue) > 1 && !$__fits) {
            $__ev0 = (array)($GLOBALS['__pp_skip_load_branch'] ?? []);
            $__ev0['verification_deferred_to_worker'] = true;
            $__ev0['verification_deferred_reason'] = sprintf(
                'Sisa deadline request %.1f s < %d kandidat x %.0f s perkiraan pipeline penuh; '
                . 'perbandingan eksak dijalankan job legal_branch_exact (plafon 1800 s).',
                is_finite($__dl0) ? $__dl0 : -1.0, count($__queue), PP_BRANCH_PIPELINE_EST_S);
            $__ev0['exact_economic_comparison'] = false;
            $GLOBALS['__pp_skip_load_branch'] = $__ev0;
        }
        if ($__brIn !== null) $input = $__brIn;
    }
    if (empty($GLOBALS['__pp_co_sweeping'])) {
        $GLOBALS['__pp_co_infeasible_evidence'] = null;
        $sw = pp_changeover_sim_sweep($input);
        if ($sw !== null) return $sw;
        /* PROVENANCE PADA MEMO HIT: bila timeline untuk konfigurasi change_over yang sama sudah
         * pernah diputuskan, sweep di-skip agar tidak menjalankan puluhan simulasi ulang. Tanpa
         * penanganan ini, output kedua (mis. tab Monitoring pada proses yang sama) kehilangan
         * evidence Change Over Timeline meski dispatch-nya identik. Provenance dilampirkan
         * kembali dari hasil resolusi yang tersimpan. */
        $GLOBALS['__pp_co_attach_resolved'] = null;
        $rv = $GLOBALS['__pp_co_resolved'] ?? null;
        if (is_array($rv) && pp_changeover_sim_pair($input) !== null) {
            $sig = substr(md5(json_encode($input['data3']['modeling']['change_over'] ?? [])), 0, 16);
            if (($rv['input_sig'] ?? null) === $sig) $GLOBALS['__pp_co_attach_resolved'] = $rv;
        }
    }
    /* §5/§7: decommitment dijalankan sampai KONVERGEN — satu stop per iterasi, tiap stop diikuti
     * full recompute & recheck seluruh constraint. Unit yang sudah OFF dikunci di input berikutnya
     * (unit_stop) sehingga pass hilir TIDAK dapat menyalakannya kembali (§8/T-SUNFIX-13). */
    /* ITEM E: budget waktu global (idempotent — run.php/UI boleh menetapkan lebih kecil lewat
     * modeling.time_budget_seconds). Semua loop berat memeriksa pp_budget_exceeded(). */
    /* Depth guard: hanya simulasi TERLUAR yang mereset budget (pp_run_simulation dipanggil ulang
     * secara nested oleh pass decommit -> reset di dalam akan menghapus jejak & membuat batch
     * non-deterministik). Budget pinned dari run.php tidak pernah direset. */
    $GLOBALS['__pp_sim_depth'] = (int)($GLOBALS['__pp_sim_depth'] ?? 0) + 1;
    if((int)$GLOBALS['__pp_sim_depth']===1)pp_budget_reset_for_sim((float)($input['data3']['modeling']['time_budget_seconds']??0)?:null);
    if((int)$GLOBALS['__pp_sim_depth']===1){
        $GLOBALS['__pp_core_runs']=0;$GLOBALS['__pp_core_budget_hit']=false;
        $GLOBALS['__pp_core_budget']=(int)($input['data3']['modeling']['__core_run_budget']??100);
        $GLOBALS['__pp_best_feasible_output']=null;
    }
    $__tBase0 = microtime(true);
    $inCur = $input; $out = pp_run_simulation_core($inCur); $stopped = [];
    /* Biaya SATU core run pada konfigurasi ini, terukur. Dipakai sebagai perkiraan awal biaya satu
     * iterasi decommit (yang menjalankan beberapa pipeline bersarang) sebelum ada pengukuran nyata. */
    $__baseCoreCost = max(0.05, microtime(true) - $__tBase0);
    pp_phase_mark('baseline_core_run');
    /* OUTER GAS-WINDOW CORRECTION (WEEKLY §2/§4): accounted gas adalah OTORITAS (dipakai validator).
     * Bila hasil di luar strict window, ulangi core run dgn instruksi koreksi yang DISIMPAN ke input
     * aktif (agar pass hilir seperti mandatory-stop/decommit mewarisinya), lalu ulangi koreksi lagi
     * di AKHIR pipeline sebagai jaring terakhir. Deterministik, maks 3 percobaan per pemanggilan. */
    /* V5 — ukuran window yang SAMA dengan validator: dengan Actual Gas terisi, yang dinilai adalah
     * Effective Total Gas (actual jam lalu + rencana jam future), bukan Total Gas Used estimasi.
     * Sebelumnya korektor menganggap window tercapai (Total 64,5605 di [64,56 ; 64,60]) padahal
     * total efektif 64,5269 — validator lalu menolak hasil yang korektor anggap selesai. */
    $gwUsed = function (array $o): float {
        $i = (array)($o['info'] ?? []);
        if ((string)getenv('PP_V5_GW_EFFECTIVE') === '1' && (int)($i['Actual Hours Provided'] ?? 0) > 0 && isset($i['Effective Total Gas (BBTUD)']))
            return (float)$i['Effective Total Gas (BBTUD)'];
        return (float)($i['Total Gas Used (BBTUD)'] ?? 0);
    };
    $fixGasWindow = function (array $in, array $out) use ($gwUsed): array {
        $qW = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
        if ($qW <= 1.0) return [$in, $out];
        if (!empty($in['data3']['modeling']['__screen_only'])) return [$in, $out];   // penyaringan: tanpa retry
        /* EVIDENCE CARRY-FORWARD (regresi STRESS-01): koreksi ini dapat MENGGANTI $out dgn core run
         * baru. Bukti yang dihasilkan pass hulu (Commitment Audit, Decommitted Units, Mandatory Stop
         * Evidence, Start Exhaustion Proof) tidak boleh hilang karena penggantian itu — operator
         * kehilangan jejak keputusan komitmen. Kunci-kunci ini dibawa serta bila run baru tidak
         * menghasilkannya sendiri. */
        $carryKeys = ['Commitment Audit', 'Decommitted Units', 'Mandatory Stop Evidence'];
        $carry = [];
        foreach ($carryKeys as $ck) if (isset($out['info'][$ck])) $carry[$ck] = $out['info'][$ck];
        $applyCarry = function (array $o) use ($carry): array {
            foreach ($carry as $ck => $cv) if (!isset($o['info'][$ck])) $o['info'][$ck] = $cv;
            return $o;
        };
        $adjTrim = (float)($in['data3']['modeling']['__gas_trim_target_bbtud'] ?? 0);
        $adjUp   = (float)($in['data3']['modeling']['__shaper_quota_adjust'] ?? 0);
        /* ===== SUBSTITUSI DISTILLATE DIPAKU SELAMA KOREKSI WINDOW ==============================
         * AKAR PENYEBAB "lever inert". Pada mode use_distillate, korektor ini menaikkan target
         * shaper agar gas naik — tetapi setiap tambahan gas SEKETIKA disubstitusi lagi oleh alokasi
         * distillate, karena anggaran alokasi dihitung ulang dari shortage yang ikut membesar.
         * Gas NETO karena itu tidak bergerak sama sekali, korektor menyimpulkan levernya mati, dan
         * hasil berhenti di luar window.
         *
         * Persoalan sesungguhnya bersifat diskret: langkah distillate terkecil yang sah terukur
         * 0,1405 BBTUD, sedangkan window hanya selebar 0,04 BBTUD. Artinya pada satu tingkat gas
         * kotor tertentu TIDAK ADA kombinasi level distillate yang dapat mendarat di dalam window —
         * yang harus bergerak adalah DISPATCH-nya, bukan level distillate-nya.
         *
         * Karena itu selama koreksi, substitusi distillate DIPAKU pada nilai yang sudah dicapai.
         * Dengan anggaran terpaku, setiap BBTUD gas yang ditambah/dikurangi dispatch berpindah
         * satu-untuk-satu ke gas neto, sehingga lever korektor hidup kembali dan gas neto dapat
         * didaratkan di dalam window tanpa menggeser window, tanpa memperbesar toleransi, dan
         * tanpa melampaui kuota. */
        if (pp_action_uses_distillate((string)($in['data3']['modeling']['gas_shortage_action'] ?? 'none'))
            && !isset($in['data3']['modeling']['__dist_schedule_pin'])) {
            $schedPin = $GLOBALS['__pp_dist_schedule'] ?? null;
            if (is_array($schedPin) && $schedPin) $in['data3']['modeling']['__dist_schedule_pin'] = $schedPin;
        }
        $best = $out; $bestDev = INF; $bestIn = $in;
        /* ESKALASI (root cause "no-movement"): dispatch bergerak dalam langkah DISKRET, sehingga
         * penyesuaian kecil (mis. 0.0129 atau 0.1578 BBTUD) sering tidak menggerakkan apa pun.
         * Sebelumnya engine berhenti pada percobaan PERTAMA dan melaporkan gas di luar window
         * padahal headroom unit Running masih ada. Kini target dinaikkan bertahap sampai dispatch
         * benar-benar bergerak, bounded oleh tangga eskalasi dan budget waktu. */
        $escalate = [0.05, 0.10, 0.20, 0.40, 0.80];
        $escIdx = -1; $maxAtt = 16;
        /* V5 — HENTI SIKLUS STATE FISIK BERULANG. Terukur (PEP +1, Actual 11 jam): 16 percobaan koreksi
         * menghasilkan gas yang BERGANTIAN persis 66,1810 / 66,1891 BBTUD sementara target trim naik
         * 0,5 -> 8,1 BBTUD — pemeriksaan "tidak bergerak" (dibanding percobaan sebelumnya) tidak pernah
         * terpicu karena nilainya berganti. Setelah siklus terulang dua kali, tidak ada percobaan
         * berikutnya yang dapat menghasilkan deviasi lebih kecil dari yang sudah tercatat; hasil terbaik
         * pertama dikembalikan — sama dengan hasil loop penuh. PP_V5_GWC_CYCLE=0 mematikan. */
        $gwSeen = []; $gwCycle = (string)getenv('PP_V5_GWC_CYCLE') !== '0'; $gwCycleStop = false;
        for ($att = 0; $att < $maxAtt; $att++) {
            if ($att > 0 && pp_budget_exceeded('gas_window_correction')) break;
            $uW = $gwUsed($out);
            [$loW, $hiW] = pp_gas_window($qW);
            $dev = ($uW > $hiW) ? ($uW - $hiW) : (($uW < $loW) ? ($loW - $uW) : 0.0);
            if ($dev < $bestDev) { $bestDev = $dev; $best = $out; $bestIn = $in; }
            if ($dev <= 1e-9) break;
            if ($uW > $hiW) {
                $adjTrim += $dev + 0.004;
                $in['data3']['modeling']['__gas_trim_target_bbtud']=$adjTrim;
                unset($in['data3']['modeling']['__shaper_quota_adjust']);
                $adjUp=0.0;
            } else {
                $adjUp += $dev + 0.004;
                $in['data3']['modeling']['__shaper_quota_adjust']=$adjUp;
                unset($in['data3']['modeling']['__gas_trim_target_bbtud']);
                $adjTrim=0.0;
            }
            $out = $applyCarry(pp_run_simulation_core($in));
            $out['info']['Gas Window Correction'] = ['attempt' => $att + 1,
                'trim_target' => round($adjTrim, 4), 'quota_adjust' => round($adjUp, 4),
                'gas_before' => round($uW, 4), 'gas_after' => round($gwUsed($out), 4)];
            /* JEJAK PERCOBAAN yang bertahan walau probe ditolak — tanpa ini, koreksi yang gagal
             * tidak meninggalkan bukti apa pun dan penyebabnya tidak dapat diaudit. */
            $GLOBALS['__pp_gwc_log'][] = ['att' => $att + 1, 'dev' => round($dev, 4),
                'trim' => round($adjTrim, 4), 'up' => round($adjUp, 4),
                'neto_sebelum' => round($uW, 4),
                'neto_sesudah' => round($gwUsed($out), 4),
                'kotor_sesudah' => round((float)($out['info']['Total Gas Used Gross (BBTUD)'] ?? 0), 4),
                'offset_sesudah' => round((float)($out['info']['Distillate Gas Offset (BBTUD)'] ?? 0), 4)];
            if ($gwCycle) { $gwSeen[] = sprintf('%.6f', $gwUsed($out)); $nS = count($gwSeen);
                if ($nS >= 4 && $gwSeen[$nS - 1] === $gwSeen[$nS - 3] && $gwSeen[$nS - 2] === $gwSeen[$nS - 4] && $gwSeen[$nS - 1] !== $gwSeen[$nS - 2]) {
                    $out['info']['Gas Window Correction']['stopped'] = 'repeated physical state cycle (' . $gwSeen[$nS - 2] . ' / ' . $gwSeen[$nS - 1] . ') — deviasi tidak dapat membaik';
                    $gwCycleStop = true; break; } }
            /* KONVERGENSI (root cause runtime): bila percobaan TIDAK menggerakkan gas sama sekali,
             * retry berikutnya pasti sia-sia (profil: 47 core run, 88.3098 -> 88.3098). Hentikan. */
            if (abs($gwUsed($out) - $uW) < 1e-6) {
                $escIdx++;
                if ($escIdx >= count($escalate)) {
                    $out['info']['Gas Window Correction']['stopped'] = 'no-movement after escalation exhausted';
                    break;
                }
                $bump = $escalate[$escIdx];
                if ($uW > $hiW) {
                    $adjTrim += $bump;$in['data3']['modeling']['__gas_trim_target_bbtud']=$adjTrim;
                    unset($in['data3']['modeling']['__shaper_quota_adjust']);$adjUp=0.0;
                } else {
                    $adjUp += $bump;$in['data3']['modeling']['__shaper_quota_adjust']=$adjUp;
                    unset($in['data3']['modeling']['__gas_trim_target_bbtud']);$adjTrim=0.0;
                }
                $out['info']['Gas Window Correction']['escalated_to'] = round($bump, 4);
                $gasBeforeEsc = $gwUsed($out);
                $out = $applyCarry(pp_run_simulation_core($in));
                /* PROVEN WASTE (measured on input_data.json): the escalated run's result was never
                 * tested, so the ladder was always walked to the end -- 13 of the 14 core runs spent
                 * here returned a byte-identical 64.336700 BBTUD while the trim target was pushed from
                 * 4.74 to 34.74 BBTUD, i.e. ~45% of the whole request produced no change at all.
                 * Two strictly increasing correction targets that both reproduce the same gas figure
                 * prove the lever is saturated (dispatch is clamped by hard constraints), so every
                 * later attempt has the identical deviation and can never improve $best/$bestDev.
                 * Stopping here selects the same result and only skips runs that cannot change it.
                 * If the escalation DOES move gas, the full ladder still runs exactly as before. */
                if (abs($gwUsed($out) - $gasBeforeEsc) < 1e-6) {
                    $out['info']['Gas Window Correction']['stopped'] =
                        'lever inert: gas unchanged across two strictly increasing correction targets';
                    $out['info']['Gas Window Correction']['escalation_ladder_remaining'] =
                        count($escalate) - 1 - $escIdx;
                    break;
                }
                continue;
            }
        }
        $uF = $gwUsed($out);
        [$loF, $hiF] = pp_gas_window($qW);
        $devF = ($uF > $hiF) ? ($uF - $hiF) : (($uF < $loF) ? ($loF - $uF) : 0.0);
        /* Jejak ditempelkan DI SINI, bukan di dalam core run: info core run disusun sebelum
         * korektor sempat mencatat apa pun, sehingga jejak yang ditulis di sana selalu kosong. */
        $trace = $GLOBALS['__pp_gwc_log'] ?? null;
        if ($devF > $bestDev + 1e-12 || ($gwCycleStop && $devF >= $bestDev - 1e-12)) {
            $bestOut = $applyCarry($best);
            if ($trace) $bestOut['info']['Gas Window Correction Trace'] = $trace;
            if ($gwCycleStop) $bestOut['info']['Gas Window Correction Cycle Stop'] = ['stopped' => $out['info']['Gas Window Correction']['stopped'] ?? null, 'best_deviation_bbtud' => round($bestDev, 6), 'attempts' => count($gwSeen)];
            return [$bestIn, $bestOut];
        }
        $outF = $applyCarry($out);
        if ($trace) $outF['info']['Gas Window Correction Trace'] = $trace;
        return [$in, $outF];
    };
    /* CATATAN URUTAN (regresi T-SYS): koreksi window gas TIDAK dijalankan di awal. Menjalankannya
     * lebih dulu menitipkan __gas_trim_target_bbtud ke input aktif, sehingga SELURUH kandidat
     * mandatory-stop/decommit ikut menjalankan coordinated solver — mengubah basis perbandingan
     * kandidat dan mematikan decommit yang seharusnya terjadi. Koreksi kini HANYA di akhir pipeline,
     * setelah commitment final ditetapkan. */
    /* MANDATORY STOP (STOP STATUS = Stop Based On Simulation) dijalankan SEBELUM decommit supaya
     * kandidat decommit bekerja di atas rencana yang sudah memenuhi kewajiban stop. (Pemanggilan ini
     * sempat terhapus saat refactor koreksi gas — dikembalikan.) */
    $msPass = pp_mandatory_stop_pass($inCur, $out);
    if (is_array($msPass)) { $inCur = $msPass['input']; $out = $msPass['output']; }
    pp_phase_mark('mandatory_stop_pass');
    /* ADMISSION WAKTU LOOP DECOMMIT.
     * Satu iterasi menjalankan beberapa pipeline bersarang. Sebelumnya iterasi dimulai selama
     * budget belum habis, lalu dipotong plafon absolut di tengah jalan — itulah yang menghasilkan
     * DEADLINE_REACHED dengan enam tahap terpotong pada skenario KP72. Sekarang biaya iterasi
     * DIUKUR, dan iterasi berikutnya hanya dimulai bila sisa waktu masih menampungnya utuh.
     * Perkiraan awal diturunkan dari biaya core run baseline yang baru saja diukur (satu iterasi
     * decommit terukur berbiaya beberapa kali satu core run), sehingga penyesuaiannya otomatis
     * mengikuti kecepatan mesin dan berat konfigurasi. */
    $__decSpent = 0.0; $__decDone = 0;
    for ($iter = 0; $iter < 4; $iter++) {
        if (pp_budget_exceeded('decommit_iteration')) break;                 // candidate budget habis
        $__decEst = ($__decDone > 0) ? ($__decSpent / $__decDone) : ($__baseCoreCost * 4.0);
        if (!pp_stage_admits($__decEst)) {
            pp_stage_defer('decommit_iteration', $__decEst,
                ['iterations_done' => $__decDone,
                 'measured_per_iteration_s' => $__decDone > 0 ? round($__decSpent / $__decDone, 2) : null,
                 'basis' => $__decDone > 0 ? 'biaya iterasi TERUKUR' : 'biaya core run baseline x 4']);
            break;
        }
        $GLOBALS['__pp_decommit_audit'] = null;
        $__tDec = microtime(true);
        $step = pp_decommit_pass($inCur, $out);
        $__decSpent += microtime(true) - $__tDec; $__decDone++;
        if ($step === null) {                                        // konvergen: tidak ada kandidat lagi
            if (is_array($GLOBALS['__pp_decommit_audit'] ?? null))
                $out['info']['Commitment Audit'] = $GLOBALS['__pp_decommit_audit'];
            break;
        }
        $inCur = $step['input']; $out = $step['output']; $stopped[] = $step['unit'];
    }
    if ($stopped) $out['info']['Decommitted Units'] = array_map('strtoupper', $stopped);
    pp_phase_mark('decommit_screening');
    /* GAS CORRECTION BUDGET RESERVATION.
     * The corrector below is bounded and time-guarded, but pp_run_simulation_once() also has a core-run
     * budget. With the historical default of 2, the initial simulation/decommit could consume both runs,
     * so every correction probe returned the previous incumbent unchanged. Reserve enough local core
     * runs for the bounded correction, then restore the caller budget. */
    $__gasCoreBudgetSave=(int)($GLOBALS['__pp_core_budget']??100);
    $GLOBALS['__pp_core_budget']=max($__gasCoreBudgetSave,(int)($GLOBALS['__pp_core_runs']??0)+16);
    [$inCur, $out] = $fixGasWindow($inCur, $out);          // jaring terakhir: window gas wajib in-window
    $GLOBALS['__pp_core_budget']=$__gasCoreBudgetSave;
    pp_phase_mark('gas_window_correction');
    $GLOBALS['__pp_sim_depth'] = max(0, (int)($GLOBALS['__pp_sim_depth'] ?? 1) - 1);
    if ((int)($GLOBALS['__pp_sim_depth'] ?? 0) === 0 && empty($GLOBALS['__pp_gas_offset_busy'])
        && empty($GLOBALS['__pp_co_sweeping'])) {
        $out = pp_jbbk_quota_offset_search($input, $out);
    }
    pp_phase_mark('gas_quota_offset_search');
    /* PENUNDAAN GLOBAL COMMITMENT REVIEW KE FASE B (diizinkan §5: "menghindari Global Commitment
     * Review berulang pada state intermediate").
     * Bila blok minimisasi Export dua fase akan berjalan, rencana saat ini adalah state ANTARA:
     * Fase A masih akan mengubah dispatch. Menjalankan comparator global di sini berarti
     * menjalankannya DUA KALI (sekali pada rencana pra-minimisasi, sekali pada state Fase A) dan
     * yang menentukan hasil akhir hanyalah yang kedua. Comparator TIDAK dihilangkan dan TIDAK ada
     * kandidat yang dibuang — ia hanya dipindahkan ke Fase B, tempat ia dijalankan atas state
     * final. Ekuivalensi hasil diverifikasi dengan oracle (checksum, gas, Export per slot). */
    $__tpWillRun = pp_export_two_phase_will_run($inCur, $out);
    /* ===== GERBANG ANGGARAN WAKTU COMPARATOR GLOBAL (BUG-16) ==================================
     * pp_global_commitment_review() menjalankan SATU PIPELINE PENUH bersarang per kandidat.
     * Bila dimulai tanpa sisa waktu yang memadai, ia dipotong plafon absolut di tengah jalan dan
     * SELURUH request dilaporkan DEADLINE_REACHED / stages_truncated — persis kegagalan
     * "dihentikan plafon waktu absolut setelah 60,311 detik" yang dilaporkan operator pada mesin
     * yang lebih lambat daripada mesin benchmark.
     *
     * Memotong di tengah adalah hasil TERBURUK: pekerjaannya terbuang DAN hasilnya tidak dapat
     * dipublikasikan, tanpa alasan yang spesifik. Karena itu comparator hanya dimulai bila sisa
     * waktu masih cukup menampung satu pipeline penuh — biayanya diperkirakan dari waktu pipeline
     * yang BARU SAJA selesai. Bila tidak cukup, comparator TIDAK dijalankan sebagian: ia dilewati
     * seluruhnya, alasannya dicatat spesifik, dan run tetap konvergen tanpa tahap terpotong.
     * Publish tetap ditolak oleh release gate karena bukti "biaya terendah" memang belum ada —
     * tetapi operator kini mendapat sebab yang jelas, bukan sekadar "pencarian dihentikan". */
    /* Gerbang ini HANYA berlaku bagi comparator pada simulasi TERLUAR. Pipeline kandidat milik
     * comparator itu sendiri juga melewati titik ini (dengan __pp_gcmp_busy aktif); mengevaluasi
     * gerbang di sana akan menandai "comparator dilewati" padahal comparator terluar berjalan
     * dengan sempurna — dan tanda itu lalu merusak Run Status milik run terluar. */
    $__gcrOutermost = ((int)($GLOBALS['__pp_sim_nest'] ?? 0) <= 1)
                   && ((int)($GLOBALS['__pp_sim_depth'] ?? 0) === 0)
                   && empty($GLOBALS['__pp_gcmp_busy']) && empty($GLOBALS['__pp_co_sweeping'])
                   && !$__tpWillRun;
    $__gcrElapsed = isset($GLOBALS['__pp_outer_t0'])
                  ? (microtime(true) - (float)$GLOBALS['__pp_outer_t0']) : 0.0;
    $__gcrLeft    = pp_two_phase_time_left();
    /* Perkiraan biaya SATU pipeline kandidat comparator, dikalibrasi dari pengukuran: pipeline
     * bersarang milik kandidat selalu LEBIH MURAH daripada pipeline luar karena sebagian tahap
     * tidak berlaku baginya. Rasio terukur pada dua input berbeda: 10/17 = 0,59 dan 11/28 = 0,39
     * core run. Dipakai 0,75 sebagai batas atas yang aman, ditambah 3 detik untuk penutupan
     * respons. Terlalu optimis membuat comparator dipotong plafon; terlalu pesimis membuatnya
     * dilewati padahal muat — 0,75 dipilih agar salah di sisi yang aman tanpa melewatkan
     * comparator pada beban normal. */
    $__gcrCands   = pp_global_commitment_candidate_count($out);
    $__gcrNeed    = pp_global_commitment_time_estimate($__gcrElapsed, $__gcrCands);
    $__gcrAfford  = (!$__gcrOutermost) || ($__gcrLeft >= $__gcrNeed);
    if ($__gcrOutermost && !$__gcrAfford) {
        /* Pencatatan JUJUR: comparator yang dilewati karena anggaran waktu TETAP berarti
         * pencarian ekonomi belum lengkap. Run tidak dipotong di tengah jalan (tidak ada tahap
         * truncated, tidak menabrak plafon), tetapi ia juga TIDAK boleh mengaku sudah terbukti
         * paling ekonomis. Status khusus di bawah membedakan keduanya, dan release gate memakai
         * flag ini sebagai alasan pemblokir tersendiri. */
        $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_PIPELINE_KANDIDAT',
            'elapsed_s' => round($__gcrElapsed, 2),
            'candidates' => $__gcrCands,
            'estimated_need_s' => round($__gcrNeed, 2),
            'async_completion_required' => true,
            'time_left_s' => is_finite($__gcrLeft) ? round($__gcrLeft, 2) : null];
        $out['info']['Global Commitment Review Skipped'] = [
            'reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_PIPELINE_KANDIDAT',
            'elapsed_s' => round($__gcrElapsed, 2),
            'time_left_s' => is_finite($__gcrLeft) ? round($__gcrLeft, 2) : null,
            'candidates' => $__gcrCands,
            'estimated_need_s' => round($__gcrNeed, 2),
            'basis' => 'satu kandidat comparator = satu pipeline penuh bersarang; biayanya diperkirakan dari waktu pipeline yang baru selesai',
            'akibat' => 'hasil TETAP konvergen dan seluruh hard constraint tetap divalidasi; yang belum ada hanyalah bukti biaya terendah, sehingga publish tetap ditolak dengan alasan spesifik ini',
            'tindakan' => 'naikkan kapasitas CPU server, atau jalankan ulang saat beban lebih rendah; JANGAN menaikkan plafon di atas 60 detik'];
    }
    /* GLOBAL COMPARATOR: hanya pada level terluar, setelah dispatch dan provenance final. */
    if ($__gcrAfford && !$__tpWillRun && (int)($GLOBALS['__pp_sim_depth'] ?? 0) === 0 && empty($GLOBALS['__pp_gcmp_busy'])
        && empty($GLOBALS['__pp_co_sweeping'])) {
        /* FIX REGRESI: pp_global_commitment_review() menjalankan simulasi BERSARANG, dan simulasi
         * itu mengonsumsi lalu menihilkan $GLOBALS['__pp_co_attach_resolved']. Akibatnya blok
         * attachment Change Over Timeline di bawah kehilangan state memo-hit, sehingga pemanggilan
         * kedua (mis. tab Monitoring) tidak lagi melaporkan timeline. State disimpan dan
         * dikembalikan di sekitar comparator. */
        $__coAttachSave = $GLOBALS['__pp_co_attach_resolved'] ?? null;
        $__coCandSave   = $GLOBALS['__pp_co_candidates'] ?? null;
        $out = pp_global_commitment_review($input, $out);
        if ($__coAttachSave !== null) $GLOBALS['__pp_co_attach_resolved'] = $__coAttachSave;
        if ($__coCandSave !== null)   $GLOBALS['__pp_co_candidates']     = $__coCandSave;
        pp_phase_mark('global_commitment_review_normal_path');
    }
    /* lampirkan kembali provenance Change Over pada memo hit (lihat hook di awal fungsi) */
    if (!empty($GLOBALS['__pp_co_attach_resolved']) && empty($out['info']['Change Over Timeline'])) {
        $rv = (array)$GLOBALS['__pp_co_attach_resolved'];
        $pr = pp_changeover_sim_pair($input);
        if ($pr !== null) {
            $mt = pp_changeover_metrics($input, $out, $pr);
            $toRow = function ($hhmm) { if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)$hhmm, $x)) return null;
                $r = (int)(((int)$x[1] * 60 + (int)$x[2]) / 30); return $r < 1 ? 48 : $r; };
            $mt['target_start_command_row'] = $toRow($rv['start_other'] ?? null);
            $mt['source_stop_command_row']  = $toRow($rv['stop_other'] ?? null);
            $out['info']['Change Over Timeline'] = [
                'mode' => 'sim/sim (timeline sweep)',
                'source_block' => $rv['source_block'] ?? ($pr['source']['block'] ?? null),
                'target_block' => $rv['target_block'] ?? ($pr['target']['block'] ?? null),
                'selected' => $mt,
                'candidates_evaluated' => (int)($GLOBALS['__pp_co_candidates'] ?? 0),
                'ladder' => [],
                'provenance' => 'RESOLVED_TIMELINE_REUSED (timeline sudah diputuskan untuk konfigurasi change_over yang identik)'];
        }
        $GLOBALS['__pp_co_attach_resolved'] = null;
    }
    /* ===== MINIMISASI EXPORT DUA FASE =========================================================
     * Dijalankan HANYA pada simulasi TERLUAR (nest === 1) dan HANYA bila pemakaian gas masih di
     * ATAS batas atas window setelah seluruh pipeline normal selesai. Untuk input yang tidak
     * berada dalam keadaan itu, blok ini tidak dieksekusi sama sekali sehingga hasil V3 tidak
     * berubah sedikit pun. */
    if ((int)($GLOBALS['__pp_sim_nest'] ?? 0) <= 1 && empty($GLOBALS['__pp_co_sweeping'])) {
        $out = pp_export_minimization_two_phase($inCur, $out);
    }
    /* Bukti Change Over yang dititipkan sweep ketika ia menolak menangani request (tidak ada
     * kandidat dengan output). Rencana yang dikembalikan adalah hasil pipeline normal — 48 baris
     * yang sah — dan buktinya tetap terbaca operator. */
    if (!empty($GLOBALS['__pp_co_infeasible_evidence']) && empty($out['info']['Change Over Timeline'])) {
        $out['info']['Change Over Timeline'] = (array)$GLOBALS['__pp_co_infeasible_evidence'];
        $GLOBALS['__pp_co_infeasible_evidence'] = null;
    }
    pp_phase_mark('pipeline_end');
    return $out;
}

/* =============================================================================================
 *  PP_EXPORT_MINIMIZATION_TWO_PHASE — proses dua fase yang EXACT untuk menuntaskan minimisasi
 *  Export sebelum Gas Shortage boleh ditetapkan.
 *
 *  LATAR BELAKANG TERUKUR (profil build pengukuran, input produksi):
 *    - Menyisipkan minimisasi ke dalam loop koreksi window gas membuat SETIAP pipeline (termasuk
 *      pipeline bersarang milik kandidat Global Commitment Review dan milik pencarian offset
 *      kuota) menjalankan ulang minimisasi. Jumlah pipeline penuh naik 2 -> 4 dan core run
 *      27 -> 58.
 *    - Penyebab terbesarnya BUKAN pass minimisasi itu sendiri (hanya 4 core run / ~3,9 s),
 *      melainkan pergeseran deviasi gas dari 4,7367 menjadi 3,1789 BBTUD yang membuat gerbang
 *      `dev > 4.0` pada pp_jbbk_quota_offset_search() tidak lagi memotong pencarian. Pencarian
 *      itu lalu menjalankan satu pipeline penuh bersarang per kandidat dan berakhir dengan
 *      "tidak ada kandidat lebih baik" — hasil akhirnya IDENTIK.
 *
 *  DESAIN:
 *    FASE A  Feasibility repair. Satu core run dengan lever minimisasi aktif: turunkan setiap
 *            Export yang masih di atas applicable Range Min, redispatch legal, seluruh hard
 *            constraint tetap berlaku. TIDAK ada eksplorasi economic decommit di dalam fase ini.
 *    FASE B  Final optimisation. Pada state hasil Fase A: commitment/decommit review SATU KALI,
 *            Global Commitment Review SATU KALI, lalu verifikasi bahwa Export tetap minimum.
 *            Bila Fase B menaikkan Export kembali di atas Range Min, alasannya dicatat dan
 *            hasil Fase A yang dipertahankan (terminasi tegas, tanpa loop).
 *
 *  Blok ini tidak pernah dijalankan pada simulasi bersarang, sehingga jumlah pipeline penuh
 *  tetap seperti baseline.
 * =========================================================================================== */
/* Sisa waktu sampai deadline absolut request, tanpa MENCATAT abort.
 * pp_budget_exceeded() menandai tahap sebagai terpotong sehingga run dilaporkan TIDAK konvergen;
 * di sini kita hanya ingin MELIHAT sisa waktu untuk memutuskan apakah sebuah tahap mahal masih
 * layak dimulai. Mengembalikan INF bila tidak ada deadline yang terpasang. */
function pp_two_phase_time_left(): float {
    $dl = $GLOBALS['__pp_budget_deadline'] ?? null;
    if (!is_float($dl) && !is_int($dl)) return INF;
    return (float)$dl - microtime(true);
}

/* Gerbang tunggal blok dua fase — dipakai juga untuk menunda comparator global. */
function pp_export_two_phase_will_run(array $inCur, array $out): bool {
    /* SAKLAR OPERASIONAL: PP_EXPORT_MIN_TWO_PHASE=0 mematikan seluruh blok dua fase sehingga
     * engine kembali persis ke perilaku V3. Dipakai sebagai rollback cepat di staging DAN sebagai
     * oracle ekuivalensi: dengan saklar ini seluruh skenario corpus wajib menghasilkan checksum
     * yang identik dengan V3, membuktikan tidak ada perubahan lain yang menyelinap masuk. */
    if ((string)getenv('PP_EXPORT_MIN_TWO_PHASE') === '0') return false;
    if ((int)($GLOBALS['__pp_sim_nest'] ?? 0) > 1) return false;
    if (!empty($GLOBALS['__pp_co_sweeping'])) return false;
    /* SAKLAR DIAGNOSTIK/ROLLBACK, setara PP_GAS_OFFSET_SEARCH. Dipakai untuk mengisolasi kontribusi
     * minimisasi Export dua fase terhadap hasil akhir; default tetap menyala. */
    if ((string)getenv('PP_EXPORT_TWO_PHASE') === '0') return false;
    $q = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
    if ($q <= 1.0) return false;
    $model = $inCur['data3']['modeling'] ?? [];
    if (!is_array($model) || !$model) return false;
    if (!empty($model['__screen_only']) || !empty($model['__gas_shortage_minimize'])) return false;
    /* GERBANG memakai gas NETO. Pertanyaannya "apakah rencana FINAL masih di atas kuota setelah
     * substitusi" — itu besaran neto. Memakai gas kotor di sini membuat gerbang selalu benar pada
     * mode distillate, sehingga fase dua selalu berjalan dan membatalkan hasil koreksi window. */
    $u0 = (float)($out['info']['Total Gas Used (BBTUD)'] ?? 0);
    [, $hi] = pp_gas_window($q);
    return ($u0 > $hi + 1e-9);
}

function pp_export_minimization_two_phase(array $inCur, array $out): array {
    if (!pp_export_two_phase_will_run($inCur, $out)) return $out;
    $q = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
    $model = $inCur['data3']['modeling'] ?? [];
    $u0 = (float)($out['info']['Total Gas Used (BBTUD)'] ?? 0);   // neto: konsisten dgn gerbang
    $rec = ['schema' => 'co12-export-minimization-two-phase-v1',
            'gas_required_before_export_minimization' => round($u0, 4),
            'effective_gas_quota' => round($q, 4)];
    $leftA = pp_two_phase_time_left();
    if (pp_budget_exceeded('export_minimization_phase_a') || $leftA < 5.0) {
        $rec['phase_a'] = ['executed' => false, 'reason' => 'BUDGET_WAKTU_HABIS_SEBELUM_FASE_A',
                           'time_left_s' => is_finite($leftA) ? round($leftA, 2) : null];
        /* Comparator global sudah DITUNDA dari pipeline normal ke Fase B, dan Fase B tidak jadi
         * berjalan. Artinya comparator TIDAK PERNAH dijalankan pada run ini — dan itu WAJIB
         * dilaporkan, bukan dibiarkan lolos sebagai CONVERGED. */
        $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'FASE_B_TIDAK_BERJALAN_ANGGARAN_HABIS',
            'async_completion_required' => true,
            'time_left_s' => is_finite($leftA) ? round($leftA, 2) : null, 'estimated_need_s' => null];
        $out['info']['Export Minimization Two Phase'] = $rec;
        return $out;
    }
    /* Waktu yang sudah terpakai pipeline sampai titik ini dipakai sebagai PERKIRAAN biaya satu
     * pipeline penuh bersarang — itulah yang dijalankan comparator global per kandidat. */
    $elapsedPipe = isset($GLOBALS['__pp_outer_t0'])
                 ? (microtime(true) - (float)$GLOBALS['__pp_outer_t0']) : 0.0;
    /* ---------- FASE A: feasibility repair (satu core run, tanpa economic exploration) ------- */
    $tA = microtime(true);
    $inA = $inCur;
    $inA['data3']['modeling']['__gas_shortage_minimize'] = 1;
    $GLOBALS['__pp_shortage_min_ev'] = null;
    $outA = pp_run_simulation_core($inA);
    pp_phase_mark('export_min_phase_a');
    $uA = (float)($outA['info']['Total Gas Used (BBTUD)'] ?? 0);   // neto: konsisten dgn gerbang
    $rec['phase_a'] = ['executed' => true, 'core_runs' => 1,
        'gas_before' => round($u0, 4), 'gas_after' => round($uA, 4),
        'freed_bbtud' => round(max(0.0, $u0 - $uA), 4),
        'elapsed_s' => round(microtime(true) - $tA, 3),
        'evidence' => $GLOBALS['__pp_shortage_min_ev'] ?? null];
    if ($uA >= $u0 - 1e-9) {                                   // tidak ada penurunan legal tersisa
        $rec['phase_a']['applied'] = false;
        $rec['phase_a']['note'] = 'tidak ada penurunan Export/gas yang masih legal; rencana tidak diubah';
        $rec['phase_b'] = ['executed' => false, 'reason' => 'FASE_A_TIDAK_MENGUBAH_RENCANA'];
        /* COMPARATOR YANG DITUNDA WAJIB DIBAYAR DI SINI (perbaikan §8).
         * Comparator global dipindahkan dari pipeline normal ke Fase B semata-mata untuk
         * menghindari menjalankannya dua kali pada state antara. Bila Fase A TIDAK mengubah
         * rencana, rencana final IDENTIK dengan rencana yang tadi dilewati comparator-nya —
         * jadi comparator dapat dijalankan sekarang juga, atas state final yang sama.
         * Melewatkannya berarti menghukum operator untuk optimasi internal kita sendiri. */
        $candsA = pp_global_commitment_candidate_count($out);
        $needA  = pp_global_commitment_time_estimate(
                      isset($GLOBALS['__pp_outer_t0']) ? microtime(true) - (float)$GLOBALS['__pp_outer_t0'] : 0.0,
                      $candsA);
        $leftA2 = pp_two_phase_time_left();
        if ($leftA2 >= $needA && empty($GLOBALS['__pp_gcmp_busy'])) {
            $out = pp_global_commitment_review($inCur, $out);
            $rec['phase_b']['global_commitment_review_runs'] = 1;
            $rec['phase_b']['global_commitment_note'] =
                'comparator yang ditunda dijalankan atas rencana final (identik dengan state pra-Fase A)';
            pp_phase_mark('global_commitment_review_deferred_paid');
        } else {
            $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_COMPARATOR_GLOBAL_SETELAH_FASE_A_NIHIL',
                'async_completion_required' => true, 'candidates' => $candsA,
                'time_left_s' => is_finite($leftA2) ? round($leftA2, 2) : null,
                'estimated_need_s' => round($needA, 2)];
            $rec['phase_b']['global_commitment_skipped'] = [
                'reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_COMPARATOR_GLOBAL',
                'time_left_s' => is_finite($leftA2) ? round($leftA2, 2) : null,
                'estimated_need_s' => round($needA, 2)];
        }
        $rec['gas_required_after_export_minimization'] = round($u0, 4);
        $out['info']['Export Minimization Two Phase'] = $rec;
        return $out;
    }
    $rec['phase_a']['applied'] = true;
    /* ---------- FASE B: final optimisation SATU KALI pada state Fase A ----------------------- */
    $tB = microtime(true);
    $coreB0 = (int)($GLOBALS['__pp_core_runs_total'] ?? 0);
    /* PENUNDAAN ECONOMIC REVIEW YANG TERBUKTI AMAN (diizinkan §5: "menghindari economic review
     * penuh sebelum feasibility repair selesai").
     * pp_jbbk_quota_offset_search() ada untuk MENDARATKAN pemakaian gas yang jatuh DI BAWAH
     * window: ia hanya pernah membangkitkan offset NON-NEGATIF yang DITAMBAHKAN ke target kuota
     * shaper, sehingga tidak pernah meminta gas lebih sedikit. Pada state Fase A pemakaian gas
     * berada DI ATAS window, jadi pencarian itu tidak dapat memperkecil deviasi. Pengukuran
     * membuktikannya: pada input produksi ia mengevaluasi offset 3,0 lewat satu pipeline penuh
     * bersarang dan berakhir "tidak ada kandidat lebih baik" dengan gas yang IDENTIK
     * (62,7789 BBTUD). Knob __gas_offset_search_seconds sudah ada di engine dan hanya dipakai
     * di sini, pada state antara Fase B — jalur pipeline normal tidak tersentuh. Ekuivalensi
     * hasil diuji dengan oracle: checksum, gas, dan Export per slot harus identik. */
    $inB = $inA; $outB = $outA; $droppedB = []; $iterB = 0;
    if (!isset($inB['data3']['modeling']['__gas_offset_search_seconds']))
        $inB['data3']['modeling']['__gas_offset_search_seconds'] = 0.0;
    for ($i = 0; $i < 4; $i++) {
        if (pp_budget_exceeded('export_minimization_phase_b_decommit')) break;
        $step = pp_decommit_pass($inB, $outB);
        if ($step === null) break;                             // konvergen: tidak ada kandidat lagi
        $inB = $step['input']; $outB = $step['output']; $droppedB[] = strtoupper((string)$step['unit']); $iterB++;
    }
    pp_phase_mark('export_min_phase_b_decommit');
    /* GERBANG WAKTU COMPARATOR GLOBAL.
     * pp_global_commitment_review() menjalankan SATU PIPELINE PENUH bersarang per kandidat.
     * Biayanya sebanding dengan biaya pipeline yang baru saja selesai. Memulainya tanpa sisa
     * waktu yang memadai membuat request menabrak deadline dan dilaporkan TIDAK konvergen —
     * pada skenario change-over hal itu terukur mengubah run 27 detik CONVERGED menjadi 60,1
     * detik DEADLINE_REACHED. Karena itu comparator hanya dimulai bila sisa waktu masih cukup
     * menampungnya; bila tidak, hasil Fase A dipertahankan dan alasannya DICATAT, bukan
     * disembunyikan. Ini bukan pemangkasan kandidat: comparator tidak pernah memilih di antara
     * kandidat lalu membuang sebagian — ia dijalankan seluruhnya atau tidak sama sekali. */
    $gcrB = null; $gcrSkip = null;
    $needGcr = pp_global_commitment_time_estimate($elapsedPipe, pp_global_commitment_candidate_count($outB));
    $leftB   = pp_two_phase_time_left();
    if ($leftB < $needGcr) {
        /* KONSISTENSI PELAPORAN: comparator yang dilewati di Fase B sama artinya dengan comparator
         * yang dilewati di pipeline normal — pencarian ekonomi belum lengkap. Keduanya WAJIB
         * memakai penanda yang sama sehingga Run Status dan release gate melaporkannya identik. */
        $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_COMPARATOR_GLOBAL_FASE_B',
            'elapsed_s' => round($elapsedPipe, 2),
            'async_completion_required' => true,
            'time_left_s' => is_finite($leftB) ? round($leftB, 2) : null,
            'estimated_need_s' => round($needGcr, 2)];
        $gcrSkip = ['reason' => 'SISA_WAKTU_TIDAK_CUKUP_UNTUK_COMPARATOR_GLOBAL',
                    'time_left_s' => is_finite($leftB) ? round($leftB, 2) : null,
                    'estimated_need_s' => round($needGcr, 2),
                    'basis' => 'satu kandidat comparator = satu pipeline penuh bersarang; biayanya diperkirakan dari waktu pipeline yang baru selesai'];
    } elseif (!pp_budget_exceeded('export_minimization_phase_b_gcr') && empty($GLOBALS['__pp_gcmp_busy'])) {
        $outB = pp_global_commitment_review($inB, $outB);
        $gcrB = $outB['info']['Global Commitment Review'] ?? null;
    }
    pp_phase_mark('export_min_phase_b_gcr');
    $uB = (float)($outB['info']['Total Gas Used (BBTUD)'] ?? 0);   // neto: konsisten dgn gerbang
    $rec['phase_b'] = ['executed' => true,
        'decommit_iterations' => $iterB, 'units_decommitted' => $droppedB,
        'global_commitment_review_runs' => $gcrB === null ? 0 : 1,
        'global_commitment_skipped' => $gcrSkip,
        'pipeline_elapsed_before_phase_b_s' => round($elapsedPipe, 2),
        'global_commitment_candidates' => (int)($gcrB['candidates_evaluated'] ?? 0),
        'core_runs' => (int)($GLOBALS['__pp_core_runs_total'] ?? 0) - $coreB0,
        'gas_after' => round($uB, 4),
        'elapsed_s' => round(microtime(true) - $tB, 3)];
    /* ---------- verifikasi Export tetap minimum (GS-10) -------------------------------------- */
    $audA = pp_export_minimization_audit($inA, $outA);
    $audB = pp_export_minimization_audit($inB, $outB);
    $rec['phase_b']['export_slots_still_reducible_after_phase_a'] = (int)($audA['rows_still_reducible'] ?? -1);
    $rec['phase_b']['export_slots_still_reducible_after_phase_b'] = (int)($audB['rows_still_reducible'] ?? -1);
    $keep = 'PHASE_B';
    if ($uB > $uA + 1e-9 || (int)($audB['rows_still_reducible'] ?? 0) > (int)($audA['rows_still_reducible'] ?? 0)) {
        /* Fase B menaikkan kembali pemakaian gas / membuka slot Export yang dapat diturunkan lagi.
         * Terminasi TEGAS: pertahankan hasil Fase A dan catat alasannya. Tidak ada loop ulang. */
        $keep = 'PHASE_A';
        $rec['phase_b']['rejected'] = true;
        $rec['phase_b']['rejection_reason'] = 'FASE_B_MENAIKKAN_EXPORT_ATAU_GAS_DI_ATAS_HASIL_FASE_A';
        /* Comparator global dijalankan pada state Fase B, dan state itu DITOLAK. Rencana yang
         * dipublikasikan adalah Fase A, yang belum pernah melewati comparator. Melaporkannya
         * sebagai "economic review selesai" akan menjadi klaim yang tidak dimiliki hasil ini. */
        if ($gcrB !== null) {
            /* COMPARATOR DIJALANKAN ULANG PADA STATE YANG BENAR-BENAR DIPUBLIKASIKAN.
             * Sebelumnya, ketika Fase B ditolak, engine hanya MENCATAT bahwa comparator berjalan
             * atas state yang salah lalu menandai economic review belum lengkap — jujur, tetapi
             * meninggalkan hasil yang selamanya tidak dapat dinyatakan optimal. Terukur pada
             * konfigurasi PGN 20 + PEP 40: `economic_review_completed = false` bertahan bahkan
             * setelah 175 detik di worker asinkron, padahal waktu masih tersedia.
             *
             * Yang benar adalah menjalankan comparator SEKALI LAGI pada state Fase A — state yang
             * menjadi rencana final — sehingga verdict ekonomi benar-benar milik rencana yang
             * dipublikasikan. Ini bukan pelonggaran: comparator tetap dijalankan seluruhnya, atas
             * seluruh kandidat, dan hanya ketika sisa waktu memang cukup. Bila tidak cukup,
             * penanda lama dipertahankan apa adanya dengan `async_completion_required` sehingga
             * worker asinkron yang menyelesaikannya. */
            $needA = pp_global_commitment_time_estimate(
                microtime(true) - (float)($GLOBALS['__pp_outer_t0'] ?? microtime(true)),
                pp_global_commitment_candidate_count($outA));
            $leftA = pp_two_phase_time_left();
            $reGcr = null;
            if (($leftA >= $needA || !is_finite($leftA))
                && !pp_budget_exceeded('export_minimization_phase_a_gcr_rerun')
                && empty($GLOBALS['__pp_gcmp_busy'])) {
                $tA2 = microtime(true);
                $outA = pp_global_commitment_review($inA, $outA);
                $reGcr = $outA['info']['Global Commitment Review'] ?? null;
                $audA = pp_export_minimization_audit($inA, $outA);   // audit mengikuti state terbaru
                $rec['phase_b']['phase_a_comparator_rerun'] = [
                    'executed' => true,
                    'candidates_evaluated' => (int)($reGcr['candidates_evaluated'] ?? 0),
                    'elapsed_s' => round(microtime(true) - $tA2, 3),
                    'catatan' => 'comparator global dijalankan ulang pada state Fase A yang menjadi '
                               . 'rencana final, karena state Fase B ditolak'];
            }
            if ($reGcr !== null) {
                /* Verdict ekonomi kini milik rencana yang dipublikasikan: penanda "belum lengkap"
                 * dihapus HANYA bila comparator benar-benar selesai atas state final. */
                unset($GLOBALS['__pp_econ_review_skipped']);
            } else {
                $GLOBALS['__pp_econ_review_skipped'] = [
                    'reason' => 'COMPARATOR_DIJALANKAN_PADA_STATE_FASE_B_YANG_DITOLAK',
                    'async_completion_required' => true,
                    'time_left_s' => is_finite($leftA) ? round($leftA, 2) : null,
                    'estimated_need_s' => round($needA, 2),
                    'catatan' => 'rencana final adalah hasil Fase A; comparator global dijalankan atas state Fase B yang kemudian ditolak karena menaikkan Export/gas, dan sisa waktu tidak cukup untuk menjalankannya ulang pada state Fase A'];
            }
        }
    }
    $final = ($keep === 'PHASE_B') ? $outB : $outA;
    $finIn = ($keep === 'PHASE_B') ? $inB : $inA;
    $aud   = ($keep === 'PHASE_B') ? $audB : $audA;
    $uF = (float)($final['info']['Total Gas Used (BBTUD)'] ?? 0);  // neto: konsisten dgn gerbang
    $rec['kept'] = $keep;
    $rec['gas_required_after_export_minimization'] = round($uF, 4);
    $rec['final_gas_shortage'] = round(max(0.0, $uF - $q), 4);
    $rec['export_slots_at_range_min'] = (int)($aud['rows_at_range_min'] ?? -1);
    $rec['export_slots_still_reducible'] = (int)($aud['rows_still_reducible'] ?? -1);
    $rec['export_slots_blocked'] = (int)($aud['rows_total'] ?? 0) - (int)($aud['rows_at_range_min'] ?? 0)
                                   - (int)($aud['rows_still_reducible'] ?? 0);
    $rec['minimization_completed'] = ((int)($aud['rows_still_reducible'] ?? 1) === 0);
    $rec['export_minimization_audit'] = $aud;
    $final['info']['Export Minimization Two Phase'] = $rec;
    $GLOBALS['__pp_two_phase_input'] = $finIn;
    return $final;
}
/* ===== MANDATORY STOP OPTIMIZER (STOP STATUS = "Stop Based On Simulation") ====================
 * Unit WAJIB berhenti minimal sekali; waktunya dipilih optimizer secara full-day horizon dgn
 * seluruh hard constraint tetap berlaku (minimum runtime/downtime, ramp-down, STG/HRSG coupling,
 * Export floor & ramp, Bus Flow, reserve, gas window, Required, Fixed/Skip/Stop Schedule,
 * Change Over, TIME PASSED). Kandidat dibangun sebagai stop-schedule nyata (unit_stop_time),
 * dijalankan penuh, divalidasi, lalu dipilih yang VALID dgn Total Cost terendah.
 * Mengembalikan ['input'=>..,'output'=>..] bila ada perubahan, atau null. */
function pp_mandatory_stop_pass(array $input, array $out): ?array {
    $d3 = $input['data3'] ?? []; $model = $d3['modeling'] ?? [];
    if (!$d3 || !$model) return null;
    $units = [];
    foreach (($model['stop_mode'] ?? []) as $u => $cfg)
        if (is_array($cfg) && strtolower((string)($cfg['mode'] ?? '')) === 'based_on_sim_must') $units[] = strtolower((string)$u);
    if (!$units) return null;

    $lim = pp_runtime_limits($model);
    $n = count($out['data'] ?? []);
    if ($n < 2) return null;
    $offRunOf = function (array $rows, string $U): array {          // [maxRun, firstRowOfMaxRun, tailOff]
        $best = 0; $cur = 0; $first = -1; $bestFirst = -1;
        foreach ($rows as $i => $r) {
            if ((float)($r[$U] ?? 0) <= 0.51) { if ($cur === 0) $first = $i; $cur++; if ($cur > $best) { $best = $cur; $bestFirst = $first; } }
            else $cur = 0;
        }
        return [$best, $bestFirst, ($best > 0 && $bestFirst >= 0 && ($bestFirst + $best) >= count($rows))];
    };
    $inCur = $input; $outCur = $out; $changed = false; $evidence = [];
    foreach ($units as $u) {
        $U = strtoupper($u);
        $cls = pp_runtime_class($u, $d3);
        $downRows = (int)($lim[$cls]['down_rows'] ?? 4);
        $runRows  = (int)($lim[$cls]['run_rows'] ?? 8);
        [$best, $bestFirst, $tail] = $offRunOf($outCur['data'], $U);
        if ($best >= $downRows || ($tail && $best > 0)) {           // sudah memenuhi mandatory stop
            $evidence[] = ['unit' => $U, 'status' => 'ALREADY_SATISFIED', 'off_rows' => $best,
                           'first_off_row' => $bestFirst + 1, 'min_downtime_rows' => $downRows];
            continue;
        }
        $costKeep = (float)($outCur['info']['Total Cost (USD)'] ?? INF);
        $bestCand = null; $tried = [];
        /* Kandidat: stop mulai row s sampai akhir hari (paling aman terhadap min downtime), lalu
         * jendela mid-day berdurasi tepat minimum downtime. s dibatasi >= minimum runtime. */
        $starts = [];
        for ($s = max(2, $runRows + 1); $s <= $n; $s += 4) $starts[] = $s;
        $cands = [];
        foreach ($starts as $s) $cands[] = ['start' => $s, 'stop' => $n, 'kind' => 'stop_to_end'];
        foreach ($starts as $s) if ($s + $downRows - 1 <= $n) $cands[] = ['start' => $s, 'stop' => $s + $downRows - 1, 'kind' => 'window'];
        foreach ($cands as $c) {
            if (pp_budget_exceeded('mandatory_stop')) break;
            if (count($tried) >= 12) break;                          // candidate budget
            $in2 = json_decode(json_encode($inCur), true);
            $m2 = &$in2['data3']['modeling'];
            /* FORMAT LIST (start/stop row) — terbukti dihormati engine untuk jendela partial;
             * format keyed ['HH:MM','HH:MM'] hanya bekerja untuk stop full-day. */
            $m2['unit_stop_time'] = (array)($m2['unit_stop_time'] ?? []);
            $m2['unit_stop_time'][] = ['unit' => $u, 'start' => (int)$c['start'], 'stop' => (int)$c['stop']];
            $m2['unit_cannot_stop'] = array_values(array_filter(array_map('strtolower', (array)($m2['unit_cannot_stop'] ?? [])), fn($x) => $x !== $u));
            unset($m2);
            $inScreen = $in2; $inScreen['data3']['modeling']['__screen_only'] = true;
            $o2 = pp_run_simulation_core($inScreen);                  // SCREENING: cepat & deterministik
            $V2 = pp_validate_hard_constraints($inScreen, $o2);
            $okV = (strtoupper((string)($V2['status'] ?? '')) === 'PASS');
            [$b2, $f2, $t2] = $offRunOf($o2['data'], $U);
            $satisfied = ($b2 >= $downRows) || ($t2 && $b2 > 0);
            $c2 = (float)($o2['info']['Total Cost (USD)'] ?? INF);
            $tried[] = ['start_row' => $c['start'], 'stop_row' => $c['stop'], 'kind' => $c['kind'],
                        'validator' => strtoupper((string)($V2['status'] ?? '?')), 'off_rows' => $b2,
                        'satisfied' => $satisfied, 'total_cost' => round($c2, 2),
                        'violations' => array_slice(array_map(fn($v) => is_array($v) ? ($v[1] ?? '') : (string)$v, $V2['violations'] ?? []), 0, 2)];
            if ($okV && $satisfied && $c2 < ($bestCand['cost'] ?? INF)) {
                $lastOn = -1; foreach ($o2['data'] as $i => $r) if ((float)($r[$U] ?? 0) > 0.51) $lastOn = $i;
                $bestCand = ['cost' => $c2, 'input' => $in2, 'output' => $o2, 'cand' => $c,
                             'off_rows' => $b2, 'first_off' => $f2 + 1, 'last_on' => $lastOn + 1];
            }
        }
        if ($bestCand === null) {
            $evidence[] = ['unit' => $U, 'status' => 'NO_FEASIBLE_STOP', 'min_downtime_rows' => $downRows,
                           'candidates_tried' => $tried,
                           'note' => 'Tidak ada waktu stop yang lolos seluruh hard constraint — hasil TIDAK boleh PASS (validator akan menandai mandatory_stop).'];
            continue;
        }
        /* PEMENANG dijalankan ULANG secara PENUH (dgn seluruh koreksi) lalu divalidasi lagi. */
        $oFull = pp_run_simulation_core($bestCand['input']);
        $vFull = pp_validate_hard_constraints($bestCand['input'], $oFull);
        if (strtoupper((string)($vFull['status'] ?? '')) === 'PASS') {
            [$b2f, $f2f, $t2f] = $offRunOf($oFull['data'], $U);
            if ($b2f >= $downRows || ($t2f && $b2f > 0)) {
                $bestCand['output'] = $oFull; $bestCand['off_rows'] = $b2f; $bestCand['first_off'] = $f2f + 1;
                $bestCand['cost'] = (float)($oFull['info']['Total Cost (USD)'] ?? $bestCand['cost']);
            }
        }
        $hh = str_pad((string)intdiv(((int)$bestCand['cand']['start']) * 30, 60), 2, '0', STR_PAD_LEFT)
            . ':' . str_pad((string)((((int)$bestCand['cand']['start']) * 30) % 60), 2, '0', STR_PAD_LEFT);
        $evidence[] = ['unit' => $U, 'status' => 'STOPPED', 'chosen_stop_time' => $hh,
                       'chosen_stop_row' => (int)$bestCand['cand']['start'], 'kind' => $bestCand['cand']['kind'],
                       'last_on_row' => $bestCand['last_on'], 'first_off_row' => $bestCand['first_off'],
                       'off_rows' => $bestCand['off_rows'], 'min_downtime_rows' => $downRows,
                       'constraint_checks' => 'validator PASS (export floor/ramp, bus flow, reserve, gas window, runtime/downtime, startup sequence)',
                       'cost_keep_running' => round($costKeep, 2), 'cost_with_stop' => round($bestCand['cost'], 2),
                       'cost_impact' => round($bestCand['cost'] - $costKeep, 2),
                       'candidates_tried' => $tried];
        $inCur = $bestCand['input']; $outCur = $bestCand['output']; $changed = true;
    }
    $outCur['info']['Mandatory Stop Evidence'] = $evidence;
    return $changed ? ['input' => $inCur, 'output' => $outCur] : ['input' => $inCur, 'output' => $outCur, 'noop' => true];
}

/* Satu langkah decommitment: kembalikan null bila tidak ada kandidat valid & lebih murah.
 * Mode $auditOnly = hanya menyusun Commitment Audit (tanpa mengubah dispatch). */
function pp_decommit_pass(array $input, array $out, bool $auditOnly = false) {
    $d3 = $input['data3'] ?? []; $model = $d3['modeling'] ?? [];
    if (!$d3 || !$model) return $auditOnly ? ['audit' => []] : null;

    $COST_TOL = 10.0;   // USD/day: saving harus nyata di atas rounding noise (§5 tolerance)
    $LF_LOW = 0.20;     // audit threshold low-load (evidence §6, bukan hard constraint)

    /* ---- profil unit ON + load factor + headroom (evidence §13/§14) ---- */
    $gtgs = [];
    foreach (pp_priority_flat($model, '/^g\d+$/') as $uu) $gtgs[] = $uu;   // urutan priority (tinggi -> rendah)
    /* unit yang dikelola Change Over (blok pengganti) TIDAK boleh didecommit. */
    $coUnits = [];
    if (!empty($model['change_over']['enabled'])) {
        $coFB = ['1' => ['gtg' => 'g3', 'stg' => 's1'], '2' => ['gtg' => 'g1', 'stg' => 's2']];
        foreach (['1','2'] as $bn) {
            $blkCO = (array)(($model['change_over']['blocks'] ?? [])[$bn] ?? []);
            $coUnits[] = strtolower((string)($blkCO['gtg'] ?? $coFB[$bn]['gtg']));
            $coUnits[] = strtolower((string)($blkCO['stg'] ?? $coFB[$bn]['stg']));
        }
    }
    $audit = []; $lowCands = [];
    $costA = (float)($out['info']['Total Cost (USD)'] ?? INF);
    foreach ($gtgs as $uu) {
        $U = strtoupper($uu);
        $on = 0; $sum = 0.0; $mx = 0.0;
        foreach ($out['data'] as $rw) { $v = (float)($rw[$U] ?? 0); if ($v > 0.01) { $on++; $sum += $v; $mx = max($mx, $v); } }
        if (!$on) continue;
        $em = (float)($d3[$uu]['min_ccload'] ?? ($d3[$uu]['min_scload'] ?? 0));
        $eM = (float)($d3[$uu]['max_load'] ?? 0);
        $avg = $sum / $on;
        $lf = ($eM > $em + 1e-9) ? max(0.0, ($avg - $em) / ($eM - $em)) : 1.0;
        $head = max(0.0, $eM - $avg);
        $protL = [];
        if (isset($model['required_mode'][$uu])) $protL[] = 'required/commitment';
        $hasFixed = false; for ($r = 1; $r <= 48; $r++) if (pp_get_fixed_load($model, $uu, $r) >= 0) { $hasFixed = true; break; }
        if ($hasFixed) $protL[] = 'fixed load';
        $hasStopSched = false; for ($r = 1; $r <= 48; $r++) if (pp_is_unit_stopped($d3, $model, $uu, $r)) { $hasStopSched = true; break; }
        if ($hasStopSched) $protL[] = 'stop schedule (operator command)';
        /* BUG FIX (ITEM-1): unit pada required_units (mis. blok pengganti Change Over atau unit yang
         * WAJIB start minimal sekali) tidak boleh menjadi kandidat decommit — sebelumnya decommit
         * mematikannya sehingga validator melaporkan "required but never runs". */
        if (in_array($uu, array_map('strtolower', (array)($model['required_units'] ?? [])), true))
            $protL[] = 'required_units (wajib start minimal sekali)';
        /* EF-01 — SHARED LAST-DATA BOUNDARY LOCK (generik, tanpa hardcode unit/tanggal).
         * Unit dengan Last Data Status = Running sudah online SEBELUM horizon. Decommit full-day
         * me-nol-kan row 1 sehingga unit "tidak pernah menyala" — melanggar boundary hari sebelumnya
         * dan merusak Weekly chaining. Unit seperti ini hanya boleh berhenti melalui proses yang sah
         * (stop schedule operator / mandatory stop), bukan lewat decommit merit-order.
         * Reason code: REJECT_LAST_DATA_RUNNING_BOUNDARY */
        $ldsU = strtolower(trim((string)(($model['unit_last_data_status'][strtoupper($uu)]
                                        ?? $model['unit_last_data_status'][$uu] ?? ''))));
        if ($ldsU==='running'&&pp_effective_unit_available($d3,$model,$uu,1))
            $protL[] = 'REJECT_LAST_DATA_RUNNING_BOUNDARY (Last Data Status = Running, row 1 tidak boleh di-nol-kan)';
        /* Kewajiban minimum runtime yang diwariskan dari hari sebelumnya (Weekly): unit WAJIB online
         * pada N row pertama, jadi tidak boleh didecommit full-day. */
        if ((int)(($model['carry_min_runtime_rows'] ?? [])[$uu] ?? 0) > 0)
            $protL[] = 'carry minimum runtime lintas hari';
        if (!empty($model['change_over']['enabled']) && in_array($uu, $coUnits, true))
            $protL[] = 'change over hold (blok pengganti wajib jalan)';
        $audit[$uu] = ['unit' => $U, 'rows_on' => $on, 'avg_load' => round($avg, 2), 'max_load' => round($mx, 2),
            'eff_min' => $em, 'eff_max' => $eM, 'load_factor' => round($lf, 3), 'headroom_avg' => round($head, 2),
            'low_load' => $lf < $LF_LOW, 'protected' => $protL];
        /* PROMPT FORCE G5/G9 §5 (DECOMMITMENT GATE KERAS): kandidat TIDAK dibatasi low-load saja.
         * Unit non-protected yang ON wajib diuji ON-vs-OFF — termasuk unit yang dinyalakan pass
         * gas-driven (top-up/repair) meski load-nya tidak rendah. Urutan uji: load factor menaik. */
        if (!$protL) $lowCands[$uu] = $lf;
    }

    /* ---- decommitment candidate: SATU stop per iterasi, priority terendah dulu (§4) ---- */
    if ($lowCands) {
        $order = array_reverse($gtgs);                                     // priority terendah dulu
        uasort($lowCands, fn($x, $y) => $x <=> $y);                        // LF menaik = paling redundant dulu
        $order = array_values(array_unique(array_merge(array_keys($lowCands), $order)));
        /* §11 (PROMPT SISTEMIK): baseline bisa OUT-OF-WINDOW (VALID-INFEASIBLE). Dalam kondisi itu
         * kandidat TIDAK boleh ditolak hanya karena belum in-window — kandidat sah bila DEVIASI
         * gas terhadap window MENGECIL dan jumlah violation tidak bertambah. Baseline PASS memakai
         * gate ketat lama (PASS + in-window + lebih murah). */
        $VB = pp_validate_hard_constraints($input, $out);
        $qB = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
        /* KEPUTUSAN memakai gas KOTOR (pp_decision_gas), bukan gas neto setelah distillate.
         * Lihat catatan akar penyebab pada pp_decision_gas(): memakai neto di sini membuat plafon
         * distillate operator berperan sebagai tujuan dispatch, sehingga plafon yang lebih besar
         * menekan screening decommit dan justru menghasilkan rencana yang lebih buruk. */
        $uB = pp_decision_gas($out);
        $devOf = function (float $u, float $q): float {
            if ($q <= 1.0) return 0.0; [$lo, $hi] = pp_gas_window($q);
            return $u > $hi ? $u - $hi : ($u < $lo ? $lo - $u : 0.0);
        };
        $devB = $devOf($uB, $qB); $violB = count($VB['violations'] ?? []);
        /* HIMPUNAN JENIS pelanggaran baseline. Jumlah saja tidak cukup: kandidat bisa MENUKAR satu
         * pelanggaran dengan pelanggaran jenis lain pada jumlah yang sama. Terukur — pelonggaran
         * yang hanya melihat jumlah meloloskan kandidat yang memasukkan pelanggaran pita Export
         * baru pada row 23 (PLN Export Compliance OK -> CHECK) sementara jumlahnya tetap. */
        $kindsOf = function (array $V): array {
            $k = [];
            foreach ((array)($V['violations'] ?? []) as $v)
                $k[(string)(is_array($v) ? ($v['constraint'] ?? ($v[0] ?? 'lain')) : $v)] = true;
            return $k;
        };
        $kindsB = $kindsOf($VB);
        /* KEPATUHAN PITA EXPORT tidak muncul sebagai hard violation — ia dilaporkan lewat kolom
         * `in_band` per baris dan diringkas menjadi `PLN Export Compliance`. Karena itu penjagaan
         * berbasis jenis pelanggaran TIDAK dapat melihatnya, dan pelonggaran cabang infeasible
         * meloloskan kandidat yang membuat satu baris keluar dari pita (row 23: Compliance OK ->
         * CHECK) tanpa satu pun hard violation bertambah. Kepatuhan pita karena itu dihitung
         * eksplisit dan tidak boleh memburuk. */
        $outBandOf = function (array $o): int {
            $n = 0;
            foreach ((array)($o['data'] ?? []) as $rw) if (empty($rw['in_band'])) $n++;
            return $n;
        };
        $obB = $outBandOf($out);
        $baseOK = (strtoupper((string)($VB['status'] ?? '')) === 'PASS') && $devB <= 1e-9;
        $tested = 0;
        foreach ($order as $uu) {
            if (!isset($lowCands[$uu]) || $tested >= 3) continue;          // budget uji: 3 kandidat (baseline PASS = 1 core run/kandidat)
            $tested++;
            /* §9 (TRIM DURASI, generik): kandidat 1 = stop full-day. Bila gagal, kandidat berikut
             * memangkas durasi — unit tetap ON pada window awal (mis. menutup Export Min saat
             * startup unit lain) lalu STOP dari slot-k sampai akhir hari. k dicoba menaik supaya
             * durasi minimum yang valid ditemukan (04:00, 06:00, 08:00, 10:00, 12:00). */
            /* budget komputasi: kandidat trim (multi-window) HANYA relevan saat baseline INFEASIBLE
             * (§9/§11). Baseline PASS memakai satu kandidat full-day (perilaku & kecepatan lama). */
            $stopWins = $baseOK
                ? [['00:00', '24:00']]
                : [['00:00', '24:00'], ['04:00', '24:00'], ['06:00', '24:00'], ['08:00', '24:00'],
                   ['10:00', '24:00'], ['12:00', '24:00']];
            $chosen = null;
            /* Safe baseline defaults when the nested core-run budget is exhausted before candidate 1. */
            $o2F=$out; $V2F=pp_validate_hard_constraints($input,$out); $qBase=(float)($out['info']['Total Gas Quota (BBTUD)']??0); $uBase=pp_decision_gas($out); $inWinF=($qBase<=1.0)||pp_gas_in_window($uBase,$qBase); $c2F=$costA;
            foreach ($stopWins as $swI => $sw) {
                if (pp_budget_exceeded('decommit_stopwindow') && $swI > 0) break;   // sisakan kandidat pertama
                /* ADMISSION WAKTU PER KANDIDAT (akar penyebab DEADLINE_REACHED pada skenario KP72).
                 * Ketika baseline INFEASIBLE, satu unit memperoleh enam kandidat stop-window dan
                 * tiap kandidat adalah satu core run penuh — 3 unit x 6 kandidat = sampai 18 core
                 * run di dalam SATU pass decommit. Pada rencana berat satu core run terukur ~4
                 * detik, sehingga pass ini sendiri memakan 48 detik dan request menabrak plafon 60
                 * detik. Kandidat berikutnya kini hanya dimulai bila sisa waktu masih menampung
                 * satu core run berdasarkan biaya TERUKUR, bukan konstanta. Kandidat yang tidak
                 * dimulai diselesaikan worker asinkron; publish tetap diblokir. */
                $__coreEst = (float)($GLOBALS['__pp_core_cost_max'] ?? ($GLOBALS['__pp_core_cost_avg'] ?? 1.0));
                if (!pp_stage_admits($__coreEst)) {
                    pp_stage_defer('decommit_stopwindow_candidate', $__coreEst,
                        ['unit' => strtoupper((string)$uu), 'candidate_index' => $swI,
                         'candidates_total' => count($stopWins),
                         'measured_core_run_s' => round($__coreEst, 2)]);
                    break;
                }
                $in2 = json_decode(json_encode($input), true);
                $in2['data3']['modeling']['unit_stop'] = array_values(array_unique(array_merge((array)($in2['data3']['modeling']['unit_stop'] ?? []), [$uu])));
                $in2['data3']['modeling']['unit_stop_time'][$uu] = $sw;
                /* BUDGET CORE-RUN DETERMINISTIK: satu request tidak boleh meledak menjadi puluhan
                 * pipeline penuh (profil: 47 core run). Setelah batas tercapai, eksplorasi kandidat
                 * dihentikan dan hasil terbaik yang sudah ada dipakai. */
                if ((int)($GLOBALS['__pp_core_runs'] ?? 0) >= (int)($GLOBALS['__pp_core_budget'] ?? 100)) {
                    $GLOBALS['__pp_core_budget_hit'] = true; break;
                }
                $o2 = pp_run_simulation_core($in2);
                $V2 = pp_validate_hard_constraints($in2, $o2);
                $okV = (strtoupper((string)($V2['status'] ?? '')) === 'PASS');
                $q2 = (float)($o2['info']['Total Gas Quota (BBTUD)'] ?? 0);
                $u2 = pp_decision_gas($o2);          // kotor: lihat pp_decision_gas()
                $inWin = ($q2 <= 1.0) || pp_gas_in_window($u2, $q2);
                $c2 = (float)($o2['info']['Total Cost (USD)'] ?? INF);
                $dev2 = $devOf($u2, $q2); $viol2 = count($V2['violations'] ?? []);
                /* §11 — CONSTRAINT FIRST, COST SECOND: pada baseline INFEASIBLE, kandidat yang
                 * memperkecil deviasi window DAN mengurangi jumlah violation WAJIB diterima —
                 * biaya bukan syarat (kandidat feasible/lebih-dekat-feasible selalu menang atas
                 * kandidat murah yang melanggar hard constraint). Baseline PASS memakai gate
                 * ketat lama (PASS + in-window + saving nyata). */
                /* CABANG BASELINE INFEASIBLE — SYARAT YANG DIKOREKSI.
                 * Dahulu kandidat hanya diterima bila deviasi gas mengecil DAN jumlah violation
                 * BERKURANG. Syarat kedua terlalu keras: langkah yang menurunkan pemakaian gas
                 * tanpa menimbulkan pelanggaran baru adalah perbaikan murni, tetapi ditolak hanya
                 * karena tidak sekaligus menghapus pelanggaran yang sudah ada. Akibatnya engine
                 * berhenti memperbaiki dan menetap pada rencana yang lebih boros (terukur: gas
                 * kotor 70,0855 padahal rencana 64,3199 tersedia dan sah).
                 * Sekarang: deviasi WAJIB mengecil tegas, dan violation TIDAK BOLEH bertambah. */
                /* Cabang infeasible: deviasi gas WAJIB mengecil tegas, jumlah pelanggaran tidak
                 * boleh bertambah, DAN tidak boleh ada JENIS pelanggaran baru. Syarat ketiga itulah
                 * yang mencegah pertukaran pelanggaran yang tampak netral pada hitungan. */
                $kinds2 = $kindsOf($V2);
                $jenisBaru = array_diff_key($kinds2, $kindsB);
                $ob2 = $outBandOf($o2);
                $accept = $baseOK
                    ? ($okV && $inWin && $c2 < $costA - $COST_TOL)
                    : (($dev2 < $devB - 1e-6) && ($viol2 < $violB) && !$jenisBaru && $ob2 <= $obB);
                if ($accept) { $chosen = [$in2, $o2, $V2, $okV, $inWin, $c2, $sw, $u2, $q2]; break; }
                if ($swI === 0) { $o2F = $o2; $V2F = $V2; $inWinF = $inWin; $c2F = $c2; }  // audit dr full-stop
            }
            if ($chosen !== null) { [$in2, $o2, $V2, $okV, $inWin, $c2, $swSel, $u2, $q2] = $chosen; }
            else { $o2 = $o2F; $V2 = $V2F; $inWin = $inWinF; $c2 = $c2F; $swSel = null;
                   $okV = (strtoupper((string)($V2['status'] ?? '')) === 'PASS'); }
            $audit[$uu]['decommit_candidate'] = ['validator' => strtoupper((string)($V2['status'] ?? '?')),
                'gas_in_window' => $inWin, 'cost_keep_on' => round($costA, 2), 'cost_decommit' => round($c2, 2),
                'saving' => round($costA - $c2, 2)];
            if ($chosen !== null) {
                /* candidate stop valid & lebih murah -> pilih (SATU stop; §4.5-4.7). */
                $o2['info']['Warnings'] = $o2['info']['Warnings'] ?? [];
                $o2['info']['Warnings'][] = sprintf(
                    'DECOMMITMENT/TRIM (§4/§5/§9/§11): unit %s (LF %.2f) distop pada window %s; '
                    . 'validator %s, gas %.4f (window [%.4f,%.4f]), Total Cost %.2f -> %.2f. Satu stop per iterasi.',
                    strtoupper($uu), $lowCands[$uu], $swSel ? implode('-', $swSel) : 'full-day',
                    strtoupper((string)($V2['status'] ?? '?')), $u2, $q2 - 0.04, $q2, $costA, $c2);
                $audit[$uu]['decommitted'] = true;
                $o2['info']['Commitment Audit'] = array_values($audit);
                if ($auditOnly) { $audit[$uu]['decommitted'] = false; continue; }
                return ['input' => $in2, 'output' => $o2, 'unit' => $uu];
            }
            $audit[$uu]['reason_kept_on'] = !$okV ? 'decommit candidate INVALID (hard constraint: ' . strtoupper((string)($V2['status'] ?? '?')) . ')'
                : (!$inWin ? sprintf('STRICT GAS WINDOW: gas keluar [%.4f,%.4f] bila unit distop', $q2 - 0.04, $q2)
                : sprintf('decommit TIDAK lebih murah (keep %.2f vs stop %.2f, saving %.2f < tolerance %.2f)', $costA, $c2, $costA - $c2, $COST_TOL));
        }
    }
    foreach ($audit as $uu => &$aRow) {
        if (($aRow['low_load'] ?? false) && !isset($aRow['reason_kept_on']) && !isset($aRow['decommitted'])) {
            $aRow['reason_kept_on'] = $aRow['protected'] ? ('not eligible for decommitment: ' . implode(', ', $aRow['protected']))
                                                        : 'not tested this pass (satu stop per iterasi)';
        }
    } unset($aRow);
    /* §B02 LOCAL-MINIMUM ESCAPE (generik, bounded): bila TIDAK ada kandidat tunggal yang diterima
     * tetapi ADA >=2 kandidat tunggal yang masing-masing PASS + in-window (hanya biayanya menanjak),
     * uji SATU kandidat PASANGAN: dua unit non-protected ber-LF terendah distop BERSAMA. Greedy
     * satu-stop bisa terjebak local minimum (tiap langkah menanjak, kombinasi menurun). 1 core run. */
    if (!$auditOnly && $lowCands && count($lowCands) >= 2) {
        $passSingles = [];
        foreach ($audit as $uu2 => $ad)
            if (isset($ad['decommit_candidate']) && $ad['decommit_candidate']['validator'] === 'PASS'
                && $ad['decommit_candidate']['gas_in_window'] === true) $passSingles[] = $uu2;
        if (count($passSingles) >= 2) {
            $pairU = array_slice($passSingles, 0, 2);
            $inP = json_decode(json_encode($input), true);
            foreach ($pairU as $pu) {
                $inP['data3']['modeling']['unit_stop'] = array_values(array_unique(array_merge((array)($inP['data3']['modeling']['unit_stop'] ?? []), [$pu])));
                $inP['data3']['modeling']['unit_stop_time'][$pu] = ['00:00', '24:00'];
            }
            if (pp_budget_exceeded('decommit_paired')) return $auditOnly ? ['audit' => array_values($audit)] : null;
            $oP = pp_run_simulation_core($inP);
            $VP = pp_validate_hard_constraints($inP, $oP);
            $qP = (float)($oP['info']['Total Gas Quota (BBTUD)'] ?? 0);
            $uP = (float)($oP['info']['Total Gas Used (BBTUD)'] ?? 0);
            $cP = (float)($oP['info']['Total Cost (USD)'] ?? INF);
            if (strtoupper((string)($VP['status'] ?? '')) === 'PASS'
                && (($qP <= 1.0) || pp_gas_in_window($uP, $qP)) && $cP < $costA - $COST_TOL) {
                $oP['info']['Warnings'] = $oP['info']['Warnings'] ?? [];
                $oP['info']['Warnings'][] = sprintf(
                    'DECOMMITMENT PAIRED (§5/§B02): unit %s distop BERSAMA (kandidat tunggal masing-masing '
                    . 'menanjak — local minimum); validator PASS, gas in-window, Total Cost %.2f -> %.2f (saving %.2f USD).',
                    strtoupper(implode('+', $pairU)), $costA, $cP, $costA - $cP);
                foreach ($pairU as $pu) $audit[$pu]['decommitted'] = true;
                $oP['info']['Commitment Audit'] = array_values($audit);
                return ['input' => $inP, 'output' => $oP, 'unit' => implode('+', $pairU)];
            }
        }
    }
    if ($auditOnly) return ['audit' => array_values($audit)];
    $out['info']['Commitment Audit'] = array_values($audit);
    $GLOBALS['__pp_decommit_audit'] = array_values($audit);
    return null;
}

/* ============================================================================================
 * pp_actual_gas_compensation() — BAGIAN C: AUTO-RERUN setelah ACTUAL GAS diinput.
 *
 * ROOT CAUSE yang ditutup: akuntansi actual-over-estimation per-slot (worker02 ~2455) sudah benar —
 * Effective[t] = Actual[t] bila terisi (0 = valid), selain itu Estimation[t] — TETAPI optimizer tetap
 * men-dispatch future rows ke target estimasi lama, sehingga Effective PGN Pipe mendarat di luar window
 * (repro live: est 27.4788 -> actual 1.1 x 2 jam -> Effective 27.7318 > quota 27.5), dan validator
 * menurunkan pelanggaran menjadi "HISTORICAL" -> PASS palsu. Bagian C mewajibkan: actual di-lock,
 * future rows di-redispatch (turun bila over / naik bila under) sampai Effective kembali masuk
 * [quota-0.04, quota]; bila mustahil -> VALID-INFEASIBLE ber-evidence, bukan PASS diam-diam.
 *
 * Mekanisme: geser kuota pipe INTERNAL sebesar deviasi efektif (delta = Effective - tepi window yang
 * dilanggar) lalu rerun; seluruh pass hilir (shaper, top-up, ramp, prioritas, Babelan) bekerja normal
 * menuju target baru -> redispatch memakai keseluruhan remaining horizon, bukan repair satu row (C2.6).
 * Nilai ACTUAL tidak pernah disentuh (engine hanya MEMBACA actual_pgn_total/actual_energy_*); dispatch
 * row ber-actual-data / TIME PASSED tetap dilindungi mekanisme $actualRows yang ada. Pelaporan kuota
 * pada output DIPULIHKAN ke kontrak ASLI sehingga UI/validator menilai terhadap quota sebenarnya.
 * Maks 2 iterasi (blend menggeser sedikit share estimasi slot actual antar-run; 1-2 iterasi konvergen).
 * ============================================================================================ */
/* =============================================================================================
 *  REGISTRI STATE FISIK PERBAIKAN WINDOW SUPPLIER (V2).
 *
 *  Banyak kandidat (mandatory stop, export minimization, comparator) berbeda HANYA pada jadwal stop
 *  unit di row tempat unit itu memang tidak berbeban, sehingga dispatch pertama mereka identik bit
 *  per bit, dan pencarian target internal supplier sesudahnya mengulang lintasan yang sama (terukur
 *  PGN 29 + PEP 32: 390 rerun, hanya 97 state fisik berbeda). Hasil perbaikan dipakai ulang bila:
 *    (1) dispatch pertama identik (data + info, kecuali stempel waktu),
 *    (2) seluruh input lain identik (hanya unit_stop/unit_stop_time yang boleh berbeda), dan
 *    (3) setiap sel (unit,row) yang status stop-nya berbeda TIDAK PERNAH berbeban pada satu pun
 *        rerun lintasan yang disimpan (jadwal stop yang berbeda tidak pernah mengikat).
 *  Tidak ada kondisi lain yang dipakai. Dapat dimatikan dengan PP_SUP_MEMO=0.
 * =========================================================================================== */
function pp_sup_units(): array { return ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4']; }
function pp_sup_col(string $u): string { $C = strtoupper($u); return ($C === 'B1' || $C === 'B2') ? 'BB' . substr($C, 1) : $C; }
function pp_sup_load_mask(array $o, array &$mask): void {
    foreach ((array)($o['data'] ?? []) as $ri => $r)
        foreach (pp_sup_units() as $u) if ((float)($r[pp_sup_col($u)] ?? 0) > 1e-9) $mask[$u . ':' . ($ri + 1)] = true;
}
function pp_sup_stop_mask(array $input): array {
    $d3 = (array)($input['data3'] ?? []); $m = (array)($d3['modeling'] ?? []);
    $e = []; if (function_exists('pp_normalize_unit_stop_time')) pp_normalize_unit_stop_time($m, $e);
    $mk = [];
    foreach (pp_sup_units() as $u) { if (!isset($d3[$u])) continue; for ($r = 1; $r <= 48; $r++) if (pp_is_unit_stopped($d3, $m, $u, $r)) $mk[$u . ':' . $r] = true; }
    return $mk;
}
/* LINTASAN DATAR PENCARIAN TARGET INTERNAL (V2). Pada fase adaptif satu sisi, bila dua percobaan
 * berurutan menghasilkan dispatch IDENTIK, rumus langkah berikutnya menerima masukan yang identik
 * sehingga target-target berikutnya dapat dihitung persis (operasi floating-point yang sama) tanpa
 * menjalankan simulasi. Respons fisik terhadap target internal monoton, sehingga bagian datar
 * lintasan kontigu: titik perubahan pertama dicari dengan probe eksponensial + bisection, dan hanya
 * titik-titik probe yang disimulasikan. Titik datar lainnya diisi dengan dispatch datar yang sama.
 * Lintasan utama kemudian berjalan persis seperti semula (pencatatan evidence per iterasi tetap
 * lengkap); yang dihilangkan hanyalah simulasi yang pasti mengulang state fisik yang sama.
 * Dipakai sesudah 8 dispatch identik berturut-turut, atau 3 bila Actual Gas terisi (lintasan datar
 * panjang terukur pada Actual Gas: 27 dari 32 percobaan identik). Dapat dimatikan dengan
 * PP_SUP_FLATJUMP=0. */
function pp_sup_flat_lookahead(array &$cache, array $adj, array $flatOut, string $flatSig, int $it, int $maxAttempts,
                               float $pq, float $lo, float $gainEst, float $pu): void {
    $cur = (float)$adj['data3']['modeling']['gas_quota']['pgn_pipe'];
    $targetMid = $pq - 0.02; $need = $pu - $targetMid;
    $effectiveGain = $pu < $lo ? max(0.45, min(0.90, $gainEst)) : max(0.60, min(1.20, $gainEst));
    $shift = max(-4.0, min(4.0, $need / $effectiveGain));
    /* target iterasi j (j = it+1 .. maxAttempts), dihitung dengan operasi yang sama dengan loop utama */
    $T = []; $t = $cur;
    for ($j = $it + 1; $j <= $maxAttempts; $j++) { $n = max(0.0, $t - $shift); $n = max(0.0, $n); $T[$j] = $n; $t = $n; }
    if (!$T) return;
    $key = function (float $x): string { return sprintf('%.17g', $x); };
    $flat = function (int $j) use (&$cache, $T, $adj, $flatSig, $key): bool {
        $k = $key($T[$j]);
        if (!isset($cache[$k])) { $a = $adj; $a['data3']['modeling']['gas_quota']['pgn_pipe'] = $T[$j];
            $GLOBALS['__ppx_stg_memo_freeze'] = true;
            try { $cache[$k] = pp_run_simulation_once($a); } finally { unset($GLOBALS['__ppx_stg_memo_freeze']); } }
        return md5(json_encode($cache[$k]['data'] ?? [])) === $flatSig;
    };
    $lastFlat = $it; $firstDiff = null; $step = 2;
    while (true) {
        $j = min($maxAttempts, $it + $step);
        if ($j <= $lastFlat) break;
        if ($flat($j)) { $lastFlat = $j; if ($j >= $maxAttempts) break; $step *= 2; }
        else { $firstDiff = $j; break; }
    }
    if ($firstDiff !== null) {
        $a = $lastFlat; $b = $firstDiff;
        while ($b - $a > 1) { $m = intdiv($a + $b, 2); if ($flat($m)) $a = $m; else $b = $m; }
        $lastFlat = $a;
    }
    /* Hanya titik datar yang dipakai ulang; probe non-datar dibuang dan dihitung ulang berurutan oleh
     * loop utama, sehingga lintasan sesudah titik perubahan identik dengan evaluasi berurutan. */
    for ($j = $it + 1; $j <= $maxAttempts; $j++) { $k = $key($T[$j]); if ($j <= $lastFlat) $cache[$k] = $flatOut; else unset($cache[$k]); }
}
function pp_actual_gas_compensation(array $input, array $out): array {
    if ((string)getenv('PP_SUP_MEMO') === '0') return pp_actual_gas_compensation_raw($input, $out);
    $x = $input; $mm = (array)($x['data3']['modeling'] ?? []);
    foreach (['unit_stop', 'unit_stop_time', '__gas_offset_search_seconds', 'time_budget_seconds', 'time_budget_max_seconds'] as $k) unset($mm[$k]);
    $x['data3']['modeling'] = $mm;
    /* Kunci = dispatch + seluruh info yang dibaca keputusan/validator. Blok evidence proses (jejak
     * percobaan internal) tidak ikut kunci: dua dispatch identik dengan jejak percobaan berbeda
     * adalah state fisik yang sama. */
    $oc = $out;
    foreach (['Convergence Budget', 'Pipeline Call Counts', 'MM2100 Gas Trim Evidence', 'Start Exhaustion Proof', 'Recommended Distillate (l/day)',
              'Gas Shortage Candidates', 'Export Floor Repair', 'Final Export Floor Reassertion', 'Gas Topup Evidence',
              'Export Minimization Measurement', 'Export Floor Blockers'] as $ek) unset($oc['info'][$ek]);
    $key = md5(json_encode($x, JSON_PRESERVE_ZERO_FRACTION)) . md5(json_encode($oc, JSON_PRESERVE_ZERO_FRACTION));
    $reg = &$GLOBALS['__ppx_sup_memo'];
    if (!is_array($reg)) $reg = [];
    if (isset($reg[$key])) {
        $E = $reg[$key]; $sm = pp_sup_stop_mask($input); $ok = true;
        foreach ([[$sm, $E['stop']], [$E['stop'], $sm]] as [$a, $b])
            foreach ($a as $c => $_) if (!isset($b[$c]) && isset($E['load'][$c])) { $ok = false; break 2; }
        if ($ok) {
            $GLOBALS['__ppx_sup_memo_hits'] = (int)($GLOBALS['__ppx_sup_memo_hits'] ?? 0) + 1;
            $r = $E['res'];
            if (isset($r['info']['Pipeline Call Counts']) && function_exists('pp_instr_report')) $r['info']['Pipeline Call Counts'] = pp_instr_report();
            return $r;
        }
    }
    $prev = $GLOBALS['__ppx_sup_collect'] ?? null;
    $GLOBALS['__ppx_sup_collect'] = [];
    $b0 = count((array)($GLOBALS['__pp_budget_aborts'] ?? []));
    $res = pp_actual_gas_compensation_raw($input, $out);
    $load = $GLOBALS['__ppx_sup_collect']; pp_sup_load_mask($out, $load); pp_sup_load_mask($res, $load);
    $GLOBALS['__ppx_sup_collect'] = $prev;
    if (is_array($prev)) $GLOBALS['__ppx_sup_collect'] = $prev + $load;
    /* hanya lintasan yang tidak dipotong budget yang boleh dipakai ulang */
    if (count((array)($GLOBALS['__pp_budget_aborts'] ?? [])) === $b0 && empty($GLOBALS['__pp_core_budget_hit'])) {
        if (count($reg) > 120) $reg = array_slice($reg, -60, null, true);
        $reg[$key] = ['res' => $res, 'stop' => pp_sup_stop_mask($input), 'load' => $load];
    }
    return $res;
}
function pp_actual_gas_compensation_raw(array $input, array $out): array {
    $origPipe = (float)($input['data3']['modeling']['gas_quota']['pgn_pipe'] ?? 0);
    if ($origPipe <= 1e-9) return $out;
    $adj = null; $ev = []; $gainEst = 0.85; $puPrev = null; $shiftPrev = 0.0; $supCache = [];
    $bestOut = $out; $bestGap = INF; $attempts = 0; $acceptedAttempts = 0; $rejectedAttempts = 0; $budgetStopped = false;
    /* V5: input yang menghasilkan $out / $bestOut (target internal ikut), dipakai trim langsung. */
    $outIn = $input; $bestIn = $input;
    $underTarget = null; $overTarget = null; $searchMode = 'ADAPTIVE_THEN_BISECTION'; $bracketObservations=[];
    /* V4 OPT-F1 — PROBE KESIA-SIAAN SISI OVER. Terukur (PEP +1 dengan Actual 11 jam): 19 pencarian
     * window supplier, 401,6 dari 445 detik pipeline, tidak ada satu pun yang mendarat — PGN
     * fisik tetap 30,94 BBTUD (kuota 30) sementara target internal diturunkan dari 30 sampai 0
     * dalam 32 percobaan (27-31 simulasi per pencarian). Bila PGN fisik sudah tidak bereaksi
     * (perubahan < 0,02 BBTUD) terhadap penurunan target internal >= 1 BBTUD dan masih > 0,2 BBTUD
     * di atas kuota, target internal TERENDAH (0) langsung diuji; bila di sana PGN fisik masih
     * > kuota + 0,2 BBTUD, tidak ada target internal yang dapat menurunkannya ke window, dan
     * pencarian dihentikan dengan sebab yang dinyatakan. Dapat dimatikan: PP_V4_SUP_FUTILITY=0. */
    $futOn = (string)getenv('PP_V4_SUP_FUTILITY') !== '0'; $futPu = null; $futT = null; $futProbe = false;
    $v7Short = (string)getenv('PP_V7_SUP_SHORT') !== '0'; $v7ShortStop = (string)getenv('PP_V7_SUP_SHORT') !== 'probe'; $v7Env = (string)getenv('PP_V7_SUP_ENV') !== '0'; $v7K = []; $v7EnvStop = null;
    /* PLAFON ITERASI BUKAN ANGKA KERAMAT — TERUKUR, 12 TERLALU DINI.
     * Pada PGN 20 + PEP 40 pencarian berakhir pada attempt 12 dengan bracket internal
     * [21,390629 (UNDER -> fisik 19,9139) ; 21,394500 (OVER -> fisik 20,0051)] dan sisa waktu
     * 498 detik. Bracket itu MASIH menjepit window [19,96 ; 20,00]: satu atau dua bisection lagi
     * mendarat di dalamnya. Yang menghentikan pencarian bukan fisika dan bukan waktu, melainkan
     * angka 12 yang dipilih sebelumnya. Plafon dinaikkan menjadi batas pengaman, dan penghentian
     * yang sesungguhnya diserahkan kepada dua hal yang memang berarti: budget waktu (sudah ada)
     * dan RESOLUSI bracket — bila lebar bracket internal sudah di bawah resolusi yang dapat
     * menggerakkan dispatch, window itu memang tidak dapat dicapai dan pencarian berhenti dengan
     * sebab yang dinyatakan, bukan diam-diam. */
    $maxAttempts = PP_PGN_SUPPLIER_MAX_ATTEMPTS;
    for ($it = 1; $it <= $maxAttempts; $it++) {
        if ($it > 1 && pp_budget_exceeded('pgn_supplier_window_repair')) { $budgetStopped = true; break; }
        if ($it > 1 && $underTarget !== null && $overTarget !== null
            && abs($underTarget - $overTarget) < PP_PGN_SUPPLIER_BRACKET_EPS) {
            $searchMode = 'BRACKET_RESOLUTION_EXHAUSTED';
            break;                                   // window tidak dapat dicapai oleh target internal mana pun
        }
        $attempts = $it;
        $pq = (float)($out['info']['PGN Pipe Quota (BBTUD)'] ?? 0);
        $pu = (float)($out['info']['PGN Pipe Used (BBTUD)']  ?? 0);
        $hA = (int)($out['info']['Actual Hours Provided'] ?? 0);
        if ($pq <= 1e-9) return $out;
        /* Supplier-window repair is universal. Previously this pass exited when no Actual Gas rows
         * existed, so PEP changes could leave PGN Pipe below quota-0.04 and be rejected immediately.
         * The internal target adjustment now runs for both planned and actual-gas cases. */
        $lo = $pq - 0.04;
        $gapNow = $pu < $lo ? $lo - $pu : ($pu > $pq ? $pu - $pq : 0.0);
        if ($gapNow < $bestGap) { $bestGap = $gapNow; $bestOut = $out; $bestIn = $outIn; }
        if ($gapNow <= 1e-9) break;               // Effective sudah dalam window
        /* target TENGAH window (pq-0.02), bukan tepi: relasi shift->efektif tidak persis linear
         * (share estimasi slot actual ikut bergeser antar-run), menembak tengah memberi margin
         * +-0.02 sehingga 1-2 iterasi cukup dan tidak osilasi di tepi.
         * KOREKSI GAIN: respons efektif thd geser kuota terukur ~0.8-0.9 (share est slot actual ikut
         * mengecil saat target turun). Membagi shift dgn gain membuat iterasi PERTAMA biasanya sudah
         * mendarat di window (1 rerun, bukan 2) — kritis utk release gate 100x. Iterasi berikutnya
         * memakai gain TERUKUR dari respons iterasi sebelumnya. */
        $adj = $adj ?? json_decode(json_encode($input), true);
        $cur = (float)$adj['data3']['modeling']['gas_quota']['pgn_pipe'];
        if ($pu < $lo) $underTarget = $cur; else if ($pu > $pq) $overTarget = $cur;
        $targetMid = $pq - 0.02;
        $need = $pu - $targetMid;
        if ($it === 1) $supObs[sprintf('%.9f', $cur)] = $pu;                // V10: pengamatan (target internal -> PGN fisik)
        if ($underTarget !== null && $overTarget !== null && abs($underTarget - $overTarget) > 1e-6) {
            /* Discrete commitment/load response crossed the window. Do not keep pushing in one
             * direction. Bisect the INTERNAL target bracket until physical PGN enters the window. */
            $nextInternal = 0.5 * ($underTarget + $overTarget);
            $shift = $cur - $nextInternal;
            $searchMode = 'BRACKET_BISECTION';
            /* V10 — REGULA FALSI BERPENGAMAN (hanya evaluasi rute cepat V10, penanda __v10_sup_secant):
             * titik berikut = interpolasi linear respons fisik pada braket [under, over] menuju tengah window,
             * dijepit 10 % dari tepi braket; sesudah dua langkah berturut-turut pada sisi yang sama dipakai
             * bisection (pengaman Illinois). Braket tetap menyusut setiap langkah; tanpa penanda perilaku lama. */
            if (!empty($input['data3']['modeling']['__v10_sup_secant'])) {
                $pU0 = $supObs[sprintf('%.9f', $underTarget)] ?? null; $pO0 = $supObs[sprintf('%.9f', $overTarget)] ?? null;
                if ($pU0 !== null && $pO0 !== null && $pO0 - $pU0 > 1e-6 && ($supSideRun ?? 0) < 1) {
                    $wB = $overTarget - $underTarget;
                    $xS = $underTarget + ($targetMid - $pU0) / ($pO0 - $pU0) * $wB;
                    $aB = min($underTarget, $overTarget) + 0.1 * abs($wB); $bB = max($underTarget, $overTarget) - 0.1 * abs($wB);
                    $nextInternal = max($aB, min($bB, $xS)); $shift = $cur - $nextInternal; $searchMode = 'V10_BRACKET_SECANT';
                }
            }
        } elseif ($underTarget !== null && $overTarget !== null) {
            /* ===== FIX BRACKET KOLAPS =====
             * under dan over terisi nilai internal target yang SAMA (respons diskret tidak bergerak),
             * sehingga midpoint = nilai itu sendiri dan 12 iterasi berjalan tanpa progres sama sekali
             * (bukti: 12 attempt, internal_target identik 35.202706, physical identik 34.8077).
             * Bracket DIPERLUAS ke arah yang benar dengan langkah membesar, bukan dibisect. */
            $expandStep = 0.05 * (1 << min(5, max(0, $attempts - 1)));   // 0.05, 0.10, 0.20, ...
            if ($pu > $pq) { $nextInternal = max(0.0, $cur - $expandStep); $overTarget = null; }
            else           { $nextInternal = $cur + $expandStep;          $underTarget = null; }
            $shift = $cur - $nextInternal;
            $searchMode = 'BRACKET_COLLAPSE_EXPANSION';
        } else {
            $effectiveGain = $pu < $lo ? max(0.45, min(0.90, $gainEst)) : max(0.60, min(1.20, $gainEst));
            $shift = max(-4.0, min(4.0, $need / $effectiveGain));
            if ($puPrev !== null && abs($shiftPrev) > 1e-6) {
                $g = ($puPrev - $pu) / $shiftPrev;
                if ($g > 0.3 && $g < 1.5) { $gainEst = $g; $shift = max(-4.0, min(4.0, $need / $gainEst)); }
            }
            $nextInternal = max(0.0, $cur - $shift);
            /* V7 — KEKURANGAN GAS NYATA: pipe DAN total efektif sama-sama > 0,2 BBTUD di atas kuota pada
             * evaluasi pertama. Target internal hanya menurunkan gas GTG 1-9 (pipe dan total bersamaan);
             * target terendah (0) langsung diuji — bila di sana pipe masih > kuota + 0,2 pencarian berhenti
             * (sama dengan akhir probe kesia-siaan V4, tanpa langkah gain di antaranya); bila tidak,
             * bracket [0 ; target sekarang] dibisect seperti biasa. PP_V7_SUP_SHORT=0 mematikan. */
            if ($futOn && $v7Short && !$futProbe && $it === 1 && $pu > $pq + 0.2 && $underTarget === null && $cur > 1e-9) {
                $iiS = (array)($out['info'] ?? []); $qS = (float)($iiS['Total Gas Quota (BBTUD)'] ?? 0);
                $eS = ((int)($iiS['Actual Hours Provided'] ?? 0) > 0 && isset($iiS['Effective Total Gas (BBTUD)'])) ? (float)$iiS['Effective Total Gas (BBTUD)'] : (float)($iiS['Total Gas Used (BBTUD)'] ?? 0);
                if ($qS > 1.0 && $eS > $qS + 0.2) {
                    /* Terukur (QA PGN-1, 23 pencarian): target 0 memberi pipe identik dengan evaluasi pertama
                     * (selisih <= 0,004 BBTUD) — shaper sudah berada di gas minimum commitment ini. Evaluasi
                     * pertama dipakai langsung; kekurangan diselesaikan alur keputusan bahan bakar. */
                    if ($v7ShortStop) { $searchMode = 'V7_KEKURANGAN_GAS_NYATA'; break; }
                    $nextInternal = 0.0; $shift = $cur; $futProbe = true; $searchMode = 'V7_SHORTAGE_PROBE_ZERO';
                }
            }
            if ($futOn && !$futProbe && $pu > $pq + 0.2 && $underTarget === null && $cur > 1e-9) {
                if ($futPu === null || abs($pu - $futPu) >= 0.02) { $futPu = $pu; $futT = $cur; }
                elseif (($futT - $cur) >= 1.0) { $nextInternal = 0.0; $shift = $cur; $futProbe = true; $searchMode = 'V4_FUTILITY_PROBE_ZERO'; }
            }
        }
        /* TARGET SELESAI: titik awal pencarian target internal dari kandidat tetangga (hanya bila
         * penanda pencarian anytime ada; run biasa dan exact tidak pernah membawanya). Hanya titik
         * awal yang berubah — pelaporan kuota tetap memakai kontrak asli dan hasil tetap divalidasi. */
        if ($it === 1 && !empty($input['data3']['modeling']['__tl_no_auto_start'])
            && isset($input['data3']['modeling']['__tl_supplier_hint'])
            && (float)$input['data3']['modeling']['__tl_supplier_hint'] > 0) {
            $nextInternal = (float)$input['data3']['modeling']['__tl_supplier_hint'];
            $shift = $cur - $nextInternal;
            $searchMode = 'TL_HINT_THEN_BISECTION';
            /* V10 (rute cepat): pengamatan pada target kontrak jauh dari titik awal hint dan responsnya sangat tidak
             * linear (datar lalu curam dekat window); braket lokal dibentuk dari langkah gain di sekitar hint. */
            if (!empty($input['data3']['modeling']['__v10_sup_secant'])) { $underTarget = null; $overTarget = null; $v10Hint = true; }
        }
        $puPrev = $pu; $shiftPrev = $shift;
        if (!empty($v10Hint) && $it === 1) $puPrev = null;
        $adj['data3']['modeling']['gas_quota']['pgn_pipe'] = max(0.0, $nextInternal);
        $ev[] = sprintf('iterasi %d: Effective PGN Pipe %.4f di luar [%.4f, %.4f] (deviasi %+.4f dari target supplier) -> target pipe internal %.4f -> %.4f, rerun redispatch future rows',
            $it, $pu, $lo, $pq, $shift, $cur, (float)$adj['data3']['modeling']['gas_quota']['pgn_pipe']);
        $supK = sprintf('%.17g', (float)$adj['data3']['modeling']['gas_quota']['pgn_pipe']);
        if (isset($supCache[$supK])) { $cand = $supCache[$supK]; $GLOBALS['__ppx_sup_flat_hits'] = (int)($GLOBALS['__ppx_sup_flat_hits'] ?? 0) + 1; }
        else $cand = pp_run_simulation_once($adj);
        if (isset($GLOBALS['__ppx_sup_collect']) && is_array($GLOBALS['__ppx_sup_collect'])) pp_sup_load_mask($cand, $GLOBALS['__ppx_sup_collect']);
        /* pulihkan PELAPORAN kuota ke kontrak ASLI (target internal hanyalah alat kompensasi) */
        /* ===== FIX KOREKSI GANDA PELAPORAN KUOTA =====
         * Sebelumnya delta dihitung dari TARGET INTERNAL: $dq = origPipe - internalTarget.
         * Bila internal target berada DI ATAS kontrak (mis. 35.2027 vs 35), delta menjadi negatif
         * dan dikurangkan dari nilai yang SUDAH benar, sehingga kuota dilaporkan 34.7973 padahal
         * operator mengisi 35 -> memicu "gas quota exceeded" palsu sebesar 0.0104.
         * Delta kini dihitung dari nilai yang BENAR-BENAR dilaporkan kandidat, sehingga
         * PGN Pipe Quota mendarat TEPAT pada kontrak asli. */
        $candPq0 = (float)($cand['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe);
        $dq = $origPipe - $candPq0;
        /* `Effective Gas Quota` WAJIB ikut dikoreksi bersama kunci kuota lain. Tanpa itu ia menahan
         * nilai target INTERNAL hasil kompensasi window gas, sementara `Total Gas Quota` sudah
         * dipulihkan ke kontrak operator — dan ringkasan menampilkan dua kuota efektif berbeda
         * (terukur 69,3605 versus 69,2078). */
        foreach (['PGN Pipe Quota (BBTUD)','Total Gas Quota (BBTUD)','Gas Available (BBTUD)','Base Gas Quota (BBTUD)','Effective Gas Quota (BBTUD)'] as $k)
            if (isset($cand['info'][$k])) $cand['info'][$k] = round((float)$cand['info'][$k] + $dq, 4);
        $candPu = (float)($cand['info']['PGN Pipe Used (BBTUD)'] ?? 0);
        $candGap = $candPu < $lo ? $lo - $candPu : ($candPu > $pq ? $candPu - $pq : 0.0);
        $candInternal = (float)$adj['data3']['modeling']['gas_quota']['pgn_pipe'];
        /* Search geometry and publication eligibility are different concerns. Even an intermediate
         * candidate rejected by a non-gas guard is valid evidence that this internal target produces
         * physical PGN below or above the supplier window. Record that bracket before the guard. */
        if ($candPu < $lo) $underTarget = $candInternal;
        else if ($candPu > $pq) $overTarget = $candInternal;
        else { /* exact gas window; retain existing bracket until non-gas guard accepts candidate */ }
        $supObs[sprintf('%.9f', $candInternal)] = $candPu;                  // V10: pengamatan untuk regula falsi
        $sideNow = $candPu < $lo ? 'U' : ($candPu > $pq ? 'O' : 'W');
        $supSideRun = (isset($supSidePrev) && $supSidePrev === $sideNow) ? ($supSideRun ?? 0) + 1 : 0; $supSidePrev = $sideNow;
        $bracketObservations[]=['attempt'=>$it,'internal_target'=>round($candInternal,6),'physical_pipe_used'=>round($candPu,6),'side'=>$candPu<$lo?'UNDER':($candPu>$pq?'OVER':'WINDOW')];
        $futStop = $futProbe && $candInternal <= 1e-9 && $candPu > $pq + 0.2;   // V4 OPT-F1: target terendah pun tidak mendarat
        /* LINTASAN DATAR (V2): lihat pp_sup_flat_lookahead(). */
        $candSig = md5(json_encode($cand['data'] ?? []));
        $supRun = (isset($supSigPrev) && $candSig === $supSigPrev && abs($candPu - (float)$supPuPrev) < 1e-12) ? ($supRun ?? 0) + 1 : 0;
        /* Hanya lintasan datar PANJANG (8 dispatch identik berturut-turut) yang dipercepat. */
        $supFlat = $supRun >= ($hA > 0 ? 2 : 7) && empty($supFlatDone) && ($underTarget === null || $overTarget === null)
            && (string)getenv('PP_SUP_FLATJUMP') !== '0';
        if ($supFlat) $supFlatDone = true;
        /* GUARD PRIORITAS (C3.7/C4.7 — Constraint First): kandidat redispatch WAJIB DITOLAK bila
         * menambah violation BARU pada constraint prioritas lebih tinggi (Export, Bus Flow, Spinning,
         * ramp, startup, runtime, dst) yang tidak ada pada output sebelumnya. Gas window tidak boleh
         * dibeli dgn melanggar Export — hasil terbaik-feasible dipertahankan + evidence VALID-INFEASIBLE. */
        $catCnt = function (array $o) use ($input): array {
            $v = pp_validate_hard_constraints($input, $o);
            $c = [];
            foreach ((array)($v['violations'] ?? []) as $vi)
                if (($vi[0] ?? '') !== 'gas_quota') $c[$vi[0]] = ($c[$vi[0]] ?? 0) + 1;
            return $c;
        };
        $before = $catCnt($out); $after = $catCnt($cand);
        $worse = null;
        foreach ($after as $cat => $n2) if ($n2 > ($before[$cat] ?? 0)) { $worse = sprintf('%s %d -> %d', $cat, $before[$cat] ?? 0, $n2); break; }
        if ($worse !== null) {
            $out['info']['Warnings'] = $out['info']['Warnings'] ?? [];
            $rejectedAttempts++;
            $ev[] = sprintf('iterasi %d candidate sementara ditolak karena violation non-gas memburuk (%s); bracket fisik tetap disimpan dan pencarian dilanjutkan', $it, $worse);
            /* The candidate is not publishable, but its under/over response must steer the next trial. */
            if ($underTarget !== null && $overTarget !== null && abs($underTarget-$overTarget)>1e-9) {
                $adj['data3']['modeling']['gas_quota']['pgn_pipe'] = 0.5*($underTarget+$overTarget);
                $searchMode='BRACKET_BISECTION_AFTER_REJECT';
            }
            unset($supSigPrev); $supRun = 0;                      // lintasan datar hanya atas kandidat yang diterima
            if (!empty($futStop)) { $searchMode = 'V4_FUTILE_OVER_AT_ZERO'; break; }
            continue;
        }
        $acceptedAttempts++;
        if ($candGap < $bestGap) { $bestGap = $candGap; $bestOut = $cand; $bestIn = $adj; }
        $out = $cand; $outIn = $adj;
        if (!empty($futStop)) { $searchMode = 'V4_FUTILE_OVER_AT_ZERO'; break; }
        /* V7 — ENVELOPE RESPONS GAS. Lever target internal menggeser pipe dan total efektif BERSAMAAN:
         * K = Effective - Pipe (= LNG + Jababeka + MM2100) tetap. Bila dua evaluasi berturut-turut memberi K yang sama
         * (< 0,001 BBTUD) dan dengan K itu window total menuntut pipe di luar window supplier
         * (> 0,005 BBTUD; K terukur tetap < 0,001), tidak ada target internal yang dapat mendaratkannya: pencarian berhenti
         * (lever lain — commitment, MM2100, Export — tetap dikerjakan pipeline). PP_V7_SUP_ENV=0 mematikan. */
        if ($v7Env && $candGap > 1e-9) {
            $iiK = (array)($cand['info'] ?? []); $qK = (float)($iiK['Total Gas Quota (BBTUD)'] ?? 0);
            $eK = ((int)($iiK['Actual Hours Provided'] ?? 0) > 0 && isset($iiK['Effective Total Gas (BBTUD)'])) ? (float)$iiK['Effective Total Gas (BBTUD)'] : (float)($iiK['Total Gas Used (BBTUD)'] ?? 0);
            $kK = $eK - $candPu;                                          // K = LNG + Jababeka + MM2100 (termasuk LNG)
            $v7K[] = $kK;
            $nK = count($v7K);
            if ($qK > 1.0 && $nK >= 2 && abs($v7K[$nK - 1] - $v7K[$nK - 2]) < 1e-3) {
                $dK = $qK - $pq - $kK;                                  // window total => pipe dalam [pq + D - 0,04 ; pq + D]
                if ($dK > 0.04 + 0.005 || $dK < -0.04 - 0.005) {
                    $v7EnvStop = ['K_bbtud' => round($kK, 5), 'D_bbtud' => round($dK, 5), 'pipe_dituntut_window_total' => [round($pq + $dK - 0.04, 4), round($pq + $dK, 4)],
                                  'window_supplier' => [round($pq - 0.04, 4), round($pq, 4)], 'evaluasi' => $nK];
                    $searchMode = 'V7_ENVELOPE_CONFLICT'; break;
                }
            }
        }
        if ($supFlat) pp_sup_flat_lookahead($supCache, $adj, $cand, $candSig, $it, $maxAttempts, $pq, $lo, $gainEst, $candPu);
        $supSigPrev = $candSig; $supPuPrev = $candPu;
    }
    $outPu = (float)($out['info']['PGN Pipe Used (BBTUD)'] ?? 0);
    $outQ = (float)($out['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe);
    $outGap = $outPu < $outQ - 0.04 ? ($outQ - 0.04 - $outPu) : ($outPu > $outQ ? $outPu - $outQ : 0.0);
    if ($bestGap < $outGap - 1e-9) { $out = $bestOut; $outIn = $bestIn; }
    $finalPuT=(float)($out['info']['PGN Pipe Used (BBTUD)']??0);$finalQT=(float)($out['info']['PGN Pipe Quota (BBTUD)']??$origPipe);
    $finalGapT=$finalPuT<$finalQT-0.04?($finalQT-0.04-$finalPuT):($finalPuT>$finalQT?($finalPuT-$finalQT):0.0);
    /* ===== TRIM LANGSUNG SISA WINDOW SUPPLIER ================================================
     * Pencarian di atas menggerakkan TARGET INTERNAL kuota pipe — sebuah alat AKUNTANSI. Responsnya
     * diskret karena ia mengubah keputusan komitmen unit; terukur pada PGN 20 + PEP 40, bracket
     * target internal menyempit sampai 6e-5 dan respons fisik tetap melompati window
     * [19,9600 ; 20,0000] dari 19,95xx langsung ke 20,0042. Alat itu memang tidak dapat memotong
     * 0,0042 BBTUD terakhir.
     *
     * Alat yang TEPAT untuk sisa sekecil itu sudah ada dan dipakai di tempat lain:
     * `__gas_trim_target_bbtud`, yaitu permintaan penurunan gas kepada shaper yang dikerjakan oleh
     * koreksi gas terkoordinasi dan export-floor repair — dengan SELURUH guard dispatch berlaku.
     * Satu langkah dispatch terkecil (0,5 MW) pada GTG bernilai sekitar 0,004 BBTUD per slot,
     * sehingga sisa sebesar ini memang berada dalam jangkauan satu langkah legal.
     *
     * Batasnya tegas: penurunan tidak boleh menembus LANTAI window gas total, kandidat wajib tidak
     * menambah violation non-gas, dan percobaannya dibatasi tiga target serta budget waktu. Bila
     * tidak berhasil, rencana TIDAK diubah dan sebabnya dicatat. */
    /* V7: pada KEKURANGAN GAS NYATA (dispatch sudah di gas minimum commitment-nya) trim langsung tidak
     * pernah dapat menutup total ke window (terukur 0 dari 8 pencarian, 24 simulasi) — kekurangan
     * diputuskan lewat alur bahan bakar, bukan dinyatakan sebagai konflik window. */
    if ($finalGapT > 1e-9 && $finalPuT > $finalQT && $searchMode !== 'V7_KEKURANGAN_GAS_NYATA') {
        $tuT = (float)($out['info']['Total Gas Used (BBTUD)'] ?? 0);
        $tqT = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
        $roomToTotalFloor = ($tqT > 1e-9) ? ($tuT - ($tqT - 0.04)) : INF;
        $catCntT = function (array $o) use ($input): array {
            $v = pp_validate_hard_constraints($input, $o); $c = [];
            foreach ((array)($v['violations'] ?? []) as $vi)
                if (($vi[0] ?? '') !== 'gas_quota') $c[$vi[0]] = ($c[$vi[0]] ?? 0) + 1;
            return $c;
        };
        $beforeT = $catCntT($out);
        $trimEv = []; $trimApplied = false;
        foreach ([$finalGapT, $finalGapT + 0.002, $finalGapT + 0.005] as $tgtT) {
            if (pp_budget_exceeded('pgn_supplier_direct_trim')) {
                $trimEv[] = ['target_bbtud' => round($tgtT, 6), 'hasil' => 'budget waktu habis'];
                break;
            }
            if ($tgtT > $roomToTotalFloor + 1e-9) {
                $trimEv[] = ['target_bbtud' => round($tgtT, 6), 'hasil' => 'ditolak: menembus lantai window gas total',
                             'room_to_total_floor_bbtud' => round($roomToTotalFloor, 6)];
                break;
            }
            /* V5 PERBAIKAN: trim dikerjakan pada state yang MENGHASILKAN sisa kelebihan ini (target
             * internal pipe yang sama), bukan pada input kontrak asli. Sebelumnya trim menjalankan
             * ulang dari target internal kontrak (mis. 30 alih-alih 32,278) sehingga dispatch-nya lain
             * sama sekali (pipe 29,777 alih-alih 30,0059 - 0,0059) dan selalu ditolak. Trim yang sudah
             * ada pada input (lever kandidat) dipertahankan dan ditambah. PP_V5_TRIM_FIX=0 = perilaku lama. */
            $trimFix = (string)getenv('PP_V5_TRIM_FIX') !== '0' && empty($input['data3']['modeling']['__v5_trim_legacy']);
            $tIn = json_decode(json_encode($trimFix ? $outIn : $input), true);
            $tIn['data3']['modeling']['__gas_trim_target_bbtud'] = ($trimFix ? (float)($tIn['data3']['modeling']['__gas_trim_target_bbtud'] ?? 0) : 0.0) + $tgtT;
            $candT = pp_run_simulation_once($tIn);
            if ($trimFix) { $cq0 = (float)($candT['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe); $dqT = $origPipe - $cq0;
                foreach (['PGN Pipe Quota (BBTUD)','Total Gas Quota (BBTUD)','Gas Available (BBTUD)','Base Gas Quota (BBTUD)','Effective Gas Quota (BBTUD)'] as $kq)
                    if (isset($candT['info'][$kq])) $candT['info'][$kq] = round((float)$candT['info'][$kq] + $dqT, 4); }
            if (isset($GLOBALS['__ppx_sup_collect']) && is_array($GLOBALS['__ppx_sup_collect'])) pp_sup_load_mask($candT, $GLOBALS['__ppx_sup_collect']);
            $cPuT = (float)($candT['info']['PGN Pipe Used (BBTUD)'] ?? 0);
            $cPqT = (float)($candT['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe);
            $cGapT = $cPuT < $cPqT - 0.04 ? ($cPqT - 0.04 - $cPuT) : ($cPuT > $cPqT ? ($cPuT - $cPqT) : 0.0);
            $cTuT = (float)($candT['info']['Total Gas Used (BBTUD)'] ?? 0);
            $cTqT = (float)($candT['info']['Total Gas Quota (BBTUD)'] ?? 0);
            $totalOK = ($cTqT <= 1e-9) || ($cTuT >= $cTqT - 0.04 - 1e-9 && $cTuT <= $cTqT + 1e-9);
            $afterT = $catCntT($candT); $worseT = null;
            foreach ($afterT as $cat => $n3) if ($n3 > ($beforeT[$cat] ?? 0)) { $worseT = sprintf('%s %d -> %d', $cat, $beforeT[$cat] ?? 0, $n3); break; }
            $trimEv[] = ['target_bbtud' => round($tgtT, 6), 'pipe_used' => round($cPuT, 6),
                         'pipe_gap' => round($cGapT, 6), 'total_gas' => round($cTuT, 6),
                         'total_window_ok' => $totalOK, 'non_gas_worse' => $worseT,
                         'hasil' => ($worseT === null && $totalOK && $cGapT < $finalGapT - 1e-9) ? 'diterima' : 'ditolak'];
            if ($worseT === null && $totalOK && $cGapT < $finalGapT - 1e-9) {
                $out = $candT; $finalPuT = $cPuT; $finalQT = $cPqT; $finalGapT = $cGapT; $trimApplied = true;
                if ($cGapT <= 1e-9) break;
            }
        }
        /* ===== KONFLIK ANTAR-WINDOW YANG TERBUKTI ===========================================
         * Bila pencarian target internal DAN trim langsung sama-sama gagal, itu bukan "engine
         * menyerah": ia adalah bukti terukur bahwa dua window kontrak tidak dapat dipenuhi
         * bersamaan oleh langkah dispatch yang tersedia. Buktinya dilampirkan apa adanya —
         * resolusi bracket, besar langkah legal terkecil yang benar-benar terjadi, dan jarak ke
         * lantai window total — supaya operator melihat ANGKA, bukan label. Kegagalan tetap
         * kegagalan: hasil ini TIDAK pernah dinyatakan lulus. */
        /* V5: konflik tetap terbukti bila trim hanya MENGURANGI sisa (sisa > 0 dan target berikut
         * menembus lantai window total) — kontrak keputusan kuota operator dipertahankan seperti V4. */
        if ($finalGapT > 1e-9 && (!$trimApplied || ((string)getenv('PP_V5_TRIM_FIX') !== '0' && empty($input['data3']['modeling']['__v5_trim_legacy'])))) {
            $smallest = null;
            foreach ($trimEv as $te)
                if (isset($te['total_gas']) && $te['total_gas'] > 0)
                    $smallest = max(0.0, $tuT - (float)$te['total_gas']);
            if ($trimApplied) {   /* sisa setelah trim parsial: angka window diambil dari rencana yang dikembalikan */
                $tuT = (float)($out['info']['Total Gas Used (BBTUD)'] ?? $tuT);
                $roomToTotalFloor = ($tqT > 1e-9) ? ($tuT - ($tqT - 0.04)) : INF;
                $smallest = null;
            }
            $out['info']['Gas Window Conflict'] = [
                'terbukti'   => true,
                'jenis'      => 'PGN_PIPE_CEILING_VS_TOTAL_GAS_FLOOR',
                'window_supplier_pgn_pipe' => ['min' => round($finalQT - 0.04, 4), 'max' => round($finalQT, 4),
                                               'tercapai' => round($finalPuT, 4), 'kelebihan' => round($finalGapT, 4)],
                'window_gas_total'         => ['min' => round($tqT - 0.04, 4), 'max' => round($tqT, 4),
                                               'tercapai' => round($tuT, 4),
                                               'sisa_ke_lantai' => round($roomToTotalFloor, 4)],
                'langkah_legal_terkecil_bbtud' => $smallest !== null ? round($smallest, 4) : null,
                'bukti' => [
                    sprintf('%d rerun target internal; bracket menyempit sampai %.6f tanpa satu pun nilai fisik mendarat di window supplier',
                            $attempts, ($underTarget !== null && $overTarget !== null) ? abs((float)$underTarget - (float)$overTarget) : 0.0),
                    $trimApplied
                        ? sprintf('lever penurunan gas nyata sudah memotong sebagian (sisa %.4f BBTUD); target berikutnya menembus lantai window gas total (sisa ruang %.4f BBTUD)', $finalGapT, $roomToTotalFloor)
                        : (($smallest !== null)
                        ? sprintf('lever penurunan gas nyata diminta memotong %.4f BBTUD; penurunan legal terkecil yang benar-benar terjadi %.4f BBTUD — %.0f kali lebih besar dari sisa %.4f BBTUD ke lantai window gas total',
                                  $finalGapT, $smallest, $roomToTotalFloor > 1e-9 ? $smallest / $roomToTotalFloor : 0.0, $roomToTotalFloor)
                        : 'lever penurunan gas nyata tidak dapat dijalankan dalam budget waktu'),
                ],
                'pilihan_operator' => [
                    sprintf('menaikkan kuota PGN Pipe minimal %.4f BBTUD', $finalGapT),
                    'menurunkan kuota PEP sehingga kebutuhan pipe turun',
                    'menerima rencana dengan kelebihan pipe yang dilaporkan (keputusan operator, bukan keputusan engine)',
                ],
            ];
        }
        $out['info']['PGN Supplier Direct Trim'] = ['triggered' => true, 'applied' => $trimApplied,
            'residual_before_bbtud' => round($tuT > 0 ? ($finalGapT) : 0.0, 6),
            'room_to_total_floor_bbtud' => is_finite($roomToTotalFloor) ? round($roomToTotalFloor, 6) : null,
            'attempts' => $trimEv,
            'catatan' => 'sisa window supplier dipotong dengan lever penurunan gas nyata (__gas_trim_target_bbtud), bukan dengan menggeser target akuntansi'];
    }
    /* ===== V5: NAIK LANGSUNG SISA WINDOW SUPPLIER (sisi UNDER) ===============================
     * Padanan trim langsung di atas untuk sisi bawah: bila pencarian target internal berakhir dengan
     * PGN fisik sedikit DI BAWAH lantai window (respons diskret melompati window), gas dinaikkan
     * langsung pada state yang menghasilkan sisa itu dengan lever kenaikan gas nyata (target shaper),
     * dengan guard yang sama: tidak menembus plafon window gas total, tidak menambah violation
     * non-gas, maksimal tiga target, budget waktu dihormati. Default MATI (terukur tanpa manfaat CP pada
     * skenario uji, menambah 2-7% simulasi); PP_V5_RAISE=1 menyalakan. */
    if ($finalGapT > 1e-9 && $finalPuT < $finalQT - 0.04 && (string)getenv('PP_V5_RAISE') === '1' && $finalGapT <= 0.05) {
        $tuR = (float)($out['info']['Total Gas Used (BBTUD)'] ?? 0);
        $tqR = (float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0);
        $roomToCeil = ($tqR > 1e-9) ? ($tqR - $tuR) : INF;
        $catCntR = function (array $o) use ($input): array {
            $v = pp_validate_hard_constraints($input, $o); $c = [];
            foreach ((array)($v['violations'] ?? []) as $vi) if (($vi[0] ?? '') !== 'gas_quota') $c[$vi[0]] = ($c[$vi[0]] ?? 0) + 1;
            return $c;
        };
        $beforeR = $catCntR($out); $raiseEv = []; $raiseApplied = false;
        foreach ([$finalGapT + 0.002, $finalGapT + 0.006, $finalGapT + 0.012] as $tgtR) {
            if (pp_budget_exceeded('pgn_supplier_direct_raise')) { $raiseEv[] = ['target_bbtud' => round($tgtR, 6), 'hasil' => 'budget waktu habis']; break; }
            if ($tgtR > $roomToCeil + 1e-9) { $raiseEv[] = ['target_bbtud' => round($tgtR, 6), 'hasil' => 'ditolak: menembus plafon window gas total', 'room_to_total_ceiling_bbtud' => round($roomToCeil, 6)]; break; }
            $rIn = json_decode(json_encode($outIn), true); $mr = &$rIn['data3']['modeling'];
            $t0 = (float)($mr['__gas_trim_target_bbtud'] ?? 0); $u0 = (float)($mr['__shaper_quota_adjust'] ?? 0);
            if ($t0 > 1e-9) { $nt = $t0 - $tgtR; unset($mr['__gas_trim_target_bbtud'], $mr['__shaper_quota_adjust']); if ($nt > 1e-9) $mr['__gas_trim_target_bbtud'] = $nt; elseif ($nt < -1e-9) $mr['__shaper_quota_adjust'] = -$nt; }
            else { unset($mr['__gas_trim_target_bbtud']); $mr['__shaper_quota_adjust'] = $u0 + $tgtR; }
            unset($mr);
            $candR = pp_run_simulation_once($rIn);
            if (isset($GLOBALS['__ppx_sup_collect']) && is_array($GLOBALS['__ppx_sup_collect'])) pp_sup_load_mask($candR, $GLOBALS['__ppx_sup_collect']);
            $cq0 = (float)($candR['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe); $dqR = $origPipe - $cq0;
            foreach (['PGN Pipe Quota (BBTUD)','Total Gas Quota (BBTUD)','Gas Available (BBTUD)','Base Gas Quota (BBTUD)','Effective Gas Quota (BBTUD)'] as $kq)
                if (isset($candR['info'][$kq])) $candR['info'][$kq] = round((float)$candR['info'][$kq] + $dqR, 4);
            $cPuR = (float)($candR['info']['PGN Pipe Used (BBTUD)'] ?? 0); $cPqR = (float)($candR['info']['PGN Pipe Quota (BBTUD)'] ?? $origPipe);
            $cGapR = $cPuR < $cPqR - 0.04 ? ($cPqR - 0.04 - $cPuR) : ($cPuR > $cPqR ? ($cPuR - $cPqR) : 0.0);
            $cTuR = (float)($candR['info']['Total Gas Used (BBTUD)'] ?? 0); $cTqR = (float)($candR['info']['Total Gas Quota (BBTUD)'] ?? 0);
            $totalOKR = ($cTqR <= 1e-9) || ($cTuR >= $cTqR - 0.04 - 1e-9 && $cTuR <= $cTqR + 1e-9);
            $afterR = $catCntR($candR); $worseR = null;
            foreach ($afterR as $cat => $n3) if ($n3 > ($beforeR[$cat] ?? 0)) { $worseR = sprintf('%s %d -> %d', $cat, $beforeR[$cat] ?? 0, $n3); break; }
            $okR = $worseR === null && $totalOKR && $cGapR < $finalGapT - 1e-9;
            $raiseEv[] = ['target_bbtud' => round($tgtR, 6), 'pipe_used' => round($cPuR, 6), 'pipe_gap' => round($cGapR, 6), 'total_gas' => round($cTuR, 6),
                          'total_window_ok' => $totalOKR, 'non_gas_worse' => $worseR, 'hasil' => $okR ? 'diterima' : 'ditolak'];
            if ($okR) { $out = $candR; $outIn = $rIn; $finalPuT = $cPuR; $finalQT = $cPqR; $finalGapT = $cGapR; $raiseApplied = true; if ($cGapR <= 1e-9) break; }
        }
        $out['info']['PGN Supplier Direct Raise'] = ['triggered' => true, 'applied' => $raiseApplied, 'room_to_total_ceiling_bbtud' => is_finite($roomToCeil) ? round($roomToCeil, 6) : null,
            'attempts' => $raiseEv, 'catatan' => 'sisa di bawah lantai window supplier dinaikkan dengan lever kenaikan gas nyata pada state yang sama'];
    }
    $out['info']['PGN Supplier Repair Review']=['attempts'=>$attempts,'accepted_attempts'=>$acceptedAttempts,'rejected_attempts'=>$rejectedAttempts,'max_attempts'=>$maxAttempts,'search_mode'=>$searchMode,'under_internal_target'=>$underTarget,'over_internal_target'=>$overTarget,'bracket_observations'=>$bracketObservations,'budget_stopped'=>$budgetStopped,
      'budget_elapsed_seconds'=>round(pp_budget_elapsed(),4),'budget_left_seconds'=>round(max(0.0,pp_budget_left()),4),
      'final_pipe_used_bbtud'=>round($finalPuT,4),'pipe_quota_bbtud'=>round($finalQT,4),'residual_gap_bbtud'=>round($finalGapT,4),
      'status'=>$finalGapT<=1e-9?'PASS':'INCOMPLETE',
      /* Sebuah hasil INCOMPLETE wajib menyebutkan APA yang menghentikan pencarian, sehingga
       * kegagalan window supplier tidak pernah tampil tanpa keterangan. */
      'stop_reason'=>$finalGapT<=1e-9 ? 'window tercapai'
        : ($budgetStopped ? 'budget waktu habis'
          : ($searchMode==='BRACKET_RESOLUTION_EXHAUSTED'
             ? sprintf('respons fisik diskret melompati window: bracket target internal menyempit sampai %.6f tanpa satu pun nilai mendarat di [%.4f, %.4f]', abs((float)$underTarget-(float)$overTarget), $finalQT-0.04, $finalQT)
             : ($searchMode==='V7_ENVELOPE_CONFLICT'
                ? sprintf('envelope respons gas: K = Effective - Pipe tetap %.4f BBTUD pada target internal berbeda; window total menuntut pipe [%.4f ; %.4f] di luar window supplier', (float)($v7EnvStop['K_bbtud'] ?? 0), (float)($v7EnvStop['pipe_dituntut_window_total'][0] ?? 0), (float)($v7EnvStop['pipe_dituntut_window_total'][1] ?? 0))
             : ($searchMode==='V7_KEKURANGAN_GAS_NYATA'
                ? sprintf('kekurangan gas nyata: pipe %.4f dan total efektif sama-sama > 0,2 BBTUD di atas kuota pada gas minimum commitment ini; diselesaikan alur keputusan bahan bakar, bukan target internal', $finalPuT)
             : ($searchMode==='V4_FUTILE_OVER_AT_ZERO' || $searchMode==='V7_SHORTAGE_PROBE_ZERO'
                ? sprintf('PGN fisik tidak bereaksi terhadap target internal: pada target internal terendah (0) masih %.4f > kuota %.4f; tidak ada target internal yang dapat mendaratkannya', $finalPuT, $finalQT)
                : sprintf('plafon %d rerun tercapai', $maxAttempts))))))];
    if ($v7EnvStop !== null) $out['info']['PGN Supplier Repair Review']['v7_envelope'] = $v7EnvStop;
    if ($ev) {
        $pu = (float)($out['info']['PGN Pipe Used (BBTUD)'] ?? 0); $pq = (float)($out['info']['PGN Pipe Quota (BBTUD)'] ?? 0);
        $out['info']['Warnings'] = $out['info']['Warnings'] ?? [];
        $out['info']['Warnings'][] = 'AUTO-RERUN PGN SUPPLIER WINDOW: ' . implode(' | ', $ev)
            . sprintf(' | hasil akhir: Effective PGN Pipe %.4f vs window [%.4f, %.4f] quota ASLI; kuota/input operator tidak diubah.', $pu, $pq - 0.04, $pq);
    }
    return $out;
}

/* =============================================================================================
 *  V4 OPT-M1 — MEMO CORE STATE EKSAK.
 *  Terukur pada pipeline exact Actual 11 jam: 256 simulasi inti, 38 di antaranya mengulang core
 *  state yang SAMA PERSIS — input identik DAN seluruh state global engine yang dibaca simulasi
 *  identik (jejak osilasi budget, konteks startup, evidence) — dan 38/38 menghasilkan dispatch
 *  identik. Memo ini hanya memakai ulang hasil untuk kunci lengkap itu, lalu memulihkan state
 *  global sesudah-simulasi persis seperti yang ditinggalkan simulasi aslinya. Simulasi yang
 *  terpotong budget tidak pernah disimpan. Dapat dimatikan dengan PP_V4_ONCE_MEMO=0.
 * =========================================================================================== */
function pp_once_memo_skip(): array {
    static $x = ['__pp_budget' => 1, '__pp_budget_deadline' => 1, '__pp_core_runs' => 1, '__pp_core_budget' => 1, '__pp_fuel_epoch' => 1,
        '__pp_core_cost_n' => 1, '__pp_core_cost_avg' => 1, '__pp_core_cost_max' => 1, '__pp_core_runs_total' => 1, '__pp_instr' => 1,
        '__pp_last_core_output' => 1, '__pp_best_feasible_output' => 1, '__pp_phase_timeline' => 1, '__pp_prelim_rid' => 1,
        '__pp_prelim_t0' => 1, '__pp_prelim_steps' => 1, '__pp_outer_t0' => 1, '__pp_job_last_pct' => 1];
    return $x;
}
function pp_once_memo_globals(): array {
    $skip = pp_once_memo_skip(); $g = [];
    foreach ($GLOBALS as $k => $v) if (is_string($k) && strncmp($k, '__pp_', 5) === 0 && !isset($skip[$k])) $g[$k] = $v;
    ksort($g); return $g;
}
function pp_run_simulation_once(array $input): array {
    if ((string)getenv('PP_V4_ONCE_MEMO') === '0' || !empty($GLOBALS['__ppx_once_depth'])) return pp_run_simulation_once_raw($input);
    if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_abort_poll')) pp_tl_abort_poll();
    if (pp_budget_deadline_left() <= 0.0 || !empty($GLOBALS['__pp_budget']['latched']) || (isset($GLOBALS['__pp_budget']['lim']) && pp_budget_left() <= 0)
        || (int)($GLOBALS['__pp_core_runs'] ?? 0) >= (int)($GLOBALS['__pp_core_budget'] ?? 100)) return pp_run_simulation_once_raw($input);
    $gIn = pp_once_memo_globals();
    $key = md5(json_encode($input, JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR)) . md5(serialize($GLOBALS['__pp_budget']['states'] ?? null)) . md5(serialize($gIn));
    $M = &$GLOBALS['__ppx_once_memo']; if (!is_array($M)) $M = [];
    if (isset($M[$key])) {
        $E = $M[$key];
        foreach ($E['unset'] as $k) unset($GLOBALS[$k]);
        foreach ($E['set'] as $k => $v) $GLOBALS[$k] = $v;
        if (isset($GLOBALS['__pp_budget'])) $GLOBALS['__pp_budget']['states'] = $E['states'];
        $GLOBALS['__pp_core_runs'] = (int)($GLOBALS['__pp_core_runs'] ?? 0) + 1;
        $GLOBALS['__pp_fuel_epoch'] = (int)($GLOBALS['__pp_fuel_epoch'] ?? 0) + 1;
        $GLOBALS['__ppx_once_memo_hits'] = (int)($GLOBALS['__ppx_once_memo_hits'] ?? 0) + 1;
        unset($M[$key]); $M[$key] = $E;                    // LRU
        return $E['out'];
    }
    $bud0 = $GLOBALS['__pp_budget'] ?? null; if (is_array($bud0)) unset($bud0['states']);
    $ab0 = count((array)($GLOBALS['__pp_budget_aborts'] ?? []));
    $GLOBALS['__ppx_once_depth'] = 1;
    try { $out = pp_run_simulation_once_raw($input); } finally { unset($GLOBALS['__ppx_once_depth']); }
    $bud1 = $GLOBALS['__pp_budget'] ?? null; if (is_array($bud1)) unset($bud1['states']);
    if ($bud0 === $bud1 && count((array)($GLOBALS['__pp_budget_aborts'] ?? [])) === $ab0 && empty($GLOBALS['__pp_core_budget_hit'])) {
        $gOut = pp_once_memo_globals(); $set = []; $uns = [];
        foreach ($gOut as $k => $v) if (!array_key_exists($k, $gIn) || $gIn[$k] !== $v) $set[$k] = $v;
        foreach ($gIn as $k => $v) if (!array_key_exists($k, $gOut)) $uns[] = $k;
        if (count($M) >= 24) array_shift($M);
        $M[$key] = ['out' => $out, 'set' => $set, 'unset' => $uns, 'states' => $GLOBALS['__pp_budget']['states'] ?? []];
    }
    return $out;
}
function pp_run_simulation_once_raw(array $input): array {
    $GLOBALS['__ppx_once_calls'] = (int)($GLOBALS['__ppx_once_calls'] ?? 0) + 1;
    if (isset($GLOBALS['ppTlHook']) && function_exists('pp_tl_abort_poll')) pp_tl_abort_poll();   // penghentian kooperatif TARGET SELESAI
    if (pp_budget_exceeded('simulation_once_entry')) return is_array($GLOBALS['__pp_best_feasible_output']??null)?$GLOBALS['__pp_best_feasible_output']:($GLOBALS['__pp_last_core_output']??['result'=>'error','error_code'=>'GLOBAL_BUDGET_LATCHED','data'=>[],'info'=>[]]);
    if ((int)($GLOBALS['__pp_core_runs']??0)>=(int)($GLOBALS['__pp_core_budget']??100)) { $GLOBALS['__pp_core_budget_hit']=true; return is_array($GLOBALS['__pp_best_feasible_output']??null)?$GLOBALS['__pp_best_feasible_output']:($GLOBALS['__pp_last_core_output']??['result'=>'error','error_code'=>'CORE_RUN_BUDGET_EXHAUSTED','data'=>[],'info'=>[]]); }
    $GLOBALS['__pp_core_runs'] = (int)($GLOBALS['__pp_core_runs'] ?? 0) + 1;   // telemetri & budget core run
    /* OPT-F1: pengaman struktural memo kurva bahan bakar. Field kurva ($d3 x*_f*, xcf, max_load)
     * tidak pernah ditulis di mana pun dalam paket ini, sehingga memo sebenarnya sah selama proses.
     * Epoch tetap dinaikkan di tiap pintu masuk simulasi agar memo TIDAK PERNAH menyeberang antar
     * $d3 yang berbeda seandainya suatu pass di masa depan mengganti kurva unit. */
    $GLOBALS['__pp_fuel_epoch'] = (int)($GLOBALS['__pp_fuel_epoch'] ?? 0) + 1;
    $GLOBALS['__pp_seq_ctx'] = [];
    $GLOBALS['__pp_reserve_commit'] = [];        // reset shared startup context tiap simulasi
    /* MIGRASI FORMAT di pintu masuk engine: seluruh pass hilir hanya melihat canonical list
     * [{unit,start,stop}] sehingga tidak ada dua interpretasi aktif. */
    if (isset($input['data3']['modeling'])) {
        $__stErr = [];
        pp_normalize_unit_stop_time($input['data3']['modeling'], $__stErr);
        if ($__stErr) $GLOBALS['__pp_stoptime_errors'] = $__stErr;
    }
    $d3    = $input['data3'];
    $model = $d3['modeling'];

    /* ---- Commitment Mode normalization (Revisi UI Commitment Start/Stop) --------------------------------
     * The UI's Commitment Mode is the single source of truth; the engine keeps its existing internal
     * structures and derives them here so older inputs stay compatible:
     *   1. Commitment Mode = Unit Continuous Running (required_mode[u].mode = 'continuous')
     *      -> merged into unit_cannot_stop (UI also writes it, but the backend must not depend on that)
     *      -> unit_last_data_status forced to Running (a continuous unit was, by definition, on yesterday).
     *   2. STOP STATUS = Stop Based on Request (stop_mode[u] = {mode:'stop_at', at:'HH:MM'})
     *      -> converted to a unit_stop_time window [stopRow .. 48], so the FIRST 0-MW row is exactly the
     *         Stop At time and the unit stays off for the rest of the day. This reuses the engine's
     *         existing pp_is_unit_stopped() hard enforcement (dispatch, escalation and repair passes all
     *         honour it already) instead of adding a parallel mechanism.
     *      STOP STATUS = Stop Based On Simulation or Continuous Running needs no synthesis: the optimizer
     *      is free to stop the unit at the best time or keep it running to the end of the day. */
    foreach (($model['required_mode'] ?? []) as $u => $cfg) {
        if (!is_array($cfg)) continue;
        if (strtolower((string)($cfg['mode'] ?? '')) === 'continuous') {
            $lu = strtolower((string)$u);
            $cs = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
            if (!in_array($lu, $cs, true)) { $cs[] = $lu; $model['unit_cannot_stop'] = $cs; }
            /* ADDENDUM FINAL Bagian 6 (root cause S28/S29): Continuous berarti "sekali running jangan
             * berhenti" — BUKAN "anggap sudah running kemarin". Bila operator menyatakan Last Data
             * Status = Stop secara eksplisit, hormati: unit start hari ini dengan startup sequence
             * (Cold/Warm/Hot) lalu continuous setelahnya. Hanya default ke Running bila status kosong. */
            $curLS = strtolower((string)($model['unit_last_data_status'][strtoupper($lu)] ?? ''));
            if ($curLS !== 'stop') $model['unit_last_data_status'][strtoupper($lu)] = 'Running';
        }
        /* Start Based on Request: the unit must stay 0 MW on every row up to and INCLUDING the Start At row
         * (the marker row), and begins loading 30 min later. Synthesize that hold as a unit_stop_time window
         * so it is a UNIVERSAL hard lock — every dispatch, escalation and repair pass already honours
         * pp_is_unit_stopped(), so none of them can turn the unit on early (a blip before Start At was
         * exactly the class of bug this closes). */
        if (strtolower((string)($cfg['mode'] ?? '')) === 'start_at'
            && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($cfg['at'] ?? $cfg['start_at'] ?? ''), $sa)) {
            $startAtRow = (int)(((int)$sa[1] * 60 + (int)$sa[2]) / 30);
            if ($startAtRow >= 1) {
                $model['unit_stop_time'] = (array)($model['unit_stop_time'] ?? []);
                $model['unit_stop_time'][] = ['unit' => strtolower((string)$u), 'start' => 1, 'stop' => $startAtRow];
            }
        }
    }
    foreach (($model['stop_mode'] ?? []) as $u => $cfg) {
        if (!is_array($cfg)) continue;
        if (strtolower((string)($cfg['mode'] ?? '')) !== 'stop_at') continue;
        $at = (string)($cfg['at'] ?? $cfg['stop_at'] ?? '');
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $at, $mm)) continue;
        $lu = strtolower((string)$u);
        $stopRow = ((int)$mm[1] * 60 + (int)$mm[2]) / 30;              // 00:30 -> 1 ... 23:30 -> 47
        if ($stopRow <= 0) $stopRow = 48;                              // 00:00 -> row 48 (end of horizon)
        $stopRow = (int)$stopRow;
        $model['unit_stop_time'] = (array)($model['unit_stop_time'] ?? []);
        $model['unit_stop_time'][] = ['unit' => $lu, 'start' => $stopRow, 'stop' => 48];
        // A unit ordered to stop cannot simultaneously be Cannot-Stop/Continuous.
        $model['unit_cannot_stop'] = array_values(array_filter(
            array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])), fn($x) => $x !== $lu));
    }

    /* ---- Change Over Block 1-2 normalization (Revisi Change Over · hard constraint) --------------------
     * model.change_over = { enabled, blocks:{ '1':{block,last_status,gtg,stg,start_other|stop_other},
     *                                          '2':{...} } }.
     * Block 1 = GTG(G3/G4/G6)+STG S1, Block 2 = GTG(G1/G2/G5)+STG S2. The priority-1 GTG is chosen in the
     * UI (blocks[n].gtg) from Unit Priority — the backend does not hardcode it. We translate change_over
     * into the SAME battle-tested primitives the commitment feature uses, so every dispatch/repair pass
     * already honours it:
     *   - Last Status = Stop  + Start Other = HH:MM  -> the block's GTG+STG are held 0 on rows [1..startRow]
     *                                                   (marker row), loading 30 min later (startup sequence).
     *   - Last Status = Stop  + Start Other = sim    -> the block's GTG is added to required_units so it MUST
     *                                                   start at least once (optimizer picks the time).
     *   - Last Status = Running + Stop Other = HH:MM -> GTG+STG held 0 on rows [stopRow..48] (0 MW from Stop
     *                                                   At; the black stop marker), row before still loaded.
     *   - Last Status = Running + Stop Other = sim   -> left to the optimizer (stop at best feasible time).
     * A normalized copy (co_norm) is recorded for the continuity validator (never both blocks fully 0, and
     * start-before-stop). If neither block is Required (no anchor), a hard warning fails validation. */
    pp_normalize_change_over($model);   // REFAKTOR J-GROUP: satu sumber kebenaran (worker02 + validator)

    $ALL_UNITS = ['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10',
                  's1','s2','s3','ge1','ge2','ge3','ge4'];

    /* ---- validation (Sec.15.8) ---------------------------------------- */
    $warnings = pp_validate_input($model);
    if (!empty($model['__co_warn'])) $warnings[] = (string)$model['__co_warn'];

    /* PROMPT STG 3-SEGMENT: auto-migrate S1/S2 Format 3-3-1 dari 2 segment ke 3 segment
     * SEBELUM pass mana pun memanggil calc_stg — $d3 lokal ini yang dipakai seluruh engine. */
    $warnings = array_merge($warnings, pp_stg_migrate_3segment($d3));

    /* Input-conflict detection (Revisi Commitment Start/Stop): a Unit Continuous Running / Cannot-Stop unit
     * with a Fixed Load = 0 rule on any row is a CONTRADICTORY input — the unit cannot simultaneously be
     * required to carry load and be pinned to 0. Neither constraint is silently overridden: the conflict is
     * surfaced explicitly (fixed load stays authoritative on that row, and the cannot-stop violation that
     * results will fail validation with this warning as its row-level evidence). */
    /* PATCH G01/G06 — kontradiksi STG last=Running tanpa feeder feasible di row 1:
     * bila SEMUA feeder GTG blok tidak mungkin online pada 00:30 (last=Stop, hold Start-At dari row 1,
     * atau stop window mencakup row 1), maka status Running STG tsb stale/kontradiktif dgn komitmen hari
     * ini. Normalisasi: STG diperlakukan start hari ini (last=Stop, mode startup berlaku) + warning
     * eksplisit — konsisten dgn filosofi validator (last_data_status tidak pernah VI). */
    foreach (['s1','s2','s3'] as $sN) {
        if (!isset($d3[$sN])) continue;
        if (strtolower((string)($model['unit_last_data_status'][strtoupper($sN)] ?? '')) !== 'running') continue;
        $feedN = array_map('strtolower', array_values($d3[$sN]['hrsg'] ?? ($d3[$sN]['gtg'] ?? [])));
        if (!$feedN) continue;
        $anyFeasible = false;
        foreach ($feedN as $fN) {
            if (!isset($d3[$fN])) continue;
            $lsF = strtolower((string)($model['unit_last_data_status'][strtoupper($fN)] ?? ''));
            if ($lsF !== 'running') continue;                                 // feeder off kemarin: tak bisa on di 00:30
            $held = false;
            foreach ((array)($model['unit_stop_time'] ?? []) as $stN)
                if (strtolower((string)($stN['unit'] ?? '')) === $fN && (int)($stN['start'] ?? 1) <= 1 && (int)($stN['stop'] ?? 48) >= 1) { $held = true; break; }
            if (!$held) { $anyFeasible = true; break; }
        }
        if (!$anyFeasible) {
            $model['unit_last_data_status'][strtoupper($sN)] = 'Stop';
            $warnings[] = sprintf('Input conflict dinormalisasi: %s Last Data = Running tetapi tidak ada feeder GTG yang bisa online pada 00:30 (semua feeder Stop / hold Start-At / stop window) — %s diperlakukan start hari ini (mode startup Cold/Warm/Hot berlaku).', strtoupper($sN), strtoupper($sN));
        }
    }

    foreach (array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])) as $csU) {
        foreach ((array)($model['unit_fix_load'][$csU] ?? $model['unit_fix_load'][strtoupper($csU)] ?? []) as $fxRule) {
            if ((float)($fxRule['value'] ?? -1) <= 0.0) {
                $warnings[] = sprintf('Input conflict: %s is Unit Continuous Running (cannot stop) but has a Fixed Load = 0 rule on rows %d-%d — resolve by removing one of the two constraints.',
                    strtoupper($csU), (int)($fxRule['start'] ?? 1), (int)($fxRule['stop'] ?? 48));
            }
        }
    }

    /* ---- pre-compute & targets ---------------------------------------- */
    [$minB, $maxB] = calc_min_block_load($d3);
    /* ROBUSTNESS (bug pre-existing, ada juga di build asli): `?? []` hanya menangkap null/unset,
       bukan TIPE yang salah. Payload dengan ie_adjustments berupa string membuat PHP melempar
       TypeError dan simulasi mati total. Guard tipe ini tidak mengubah apa pun untuk input valid. */
    $ieAdj   = $model['ie_adjustments'] ?? $model['ie_adjustment'] ?? [];
    $ieVals  = pp_apply_ie_adjustment($input['data1'], is_array($ieAdj) ? $ieAdj : []);
    $plnInfo = pp_compute_pln_targets($model, $input['data2']);
    $warnings = array_merge($warnings, $plnInfo['warn']);

    $blocks  = $model['block_priority'];
    $busUnit = $model['bus_unit'] ?? [];
    $HLnom   = (float)($model['house_load'] ?? 12);

    /* dispatch every block in priority order toward $Gtarget */
    $run_blocks = function (float $Gtarget, int $row1) use ($blocks, $minB, $d3, $model, $ALL_UNITS): array {
        $gen = array_fill_keys($ALL_UNITS, 0.0);
        $blockLoads = [];
        $nB = count($blocks);
        for ($i = 0; $i < $nB; $i++) {
            $committedAbove = 0.0;
            for ($j = 0; $j < $i; $j++) $committedAbove += $blockLoads[$j] ?? 0.0;
            $reservedBelow = 0.0;
            for ($j = $i + 1; $j < $nB; $j++) {
                if (in_array('required', $blocks[$j], true)) $reservedBelow += $minB[$j][0] ?? 0.0;
            }
            $available = $Gtarget - $committedAbove - $reservedBelow;
            $blockLoads[$i] = pp_solve_block($blocks[$i], $available, $row1, $d3, $model, $gen);
        }
        return $gen;
    };

    $realised_export = function (array $gen, float $ie): array {
        // MM2100 units (GE1-4 + GTG10) feed the MM2100 customer, not the PLN/Jababeka bus, so their generation
        // is excluded from PLN Export and the Jababeka energy — KP72/MM2100 must not change PGN/Jababeka (isolation).
        $babelan  = ($gen['b1'] ?? 0) + ($gen['b2'] ?? 0);
        /* KEPUTUSAN DOMAIN FINAL Q1 — pisahkan PHYSICAL BUS dan BUSINESS PRODUCTION.
         *   JABABEKA_BUS_NET  = sum(G1..G9) + sum(S1..S3) - House Load     (topologi PLN)
         *   MM2100_PRODUCTION = G10 + GE1..GE4                              (pelanggan MM2100)
         *   JBBK_MM_PROD      = JABABEKA_BUS_NET + MM2100_PRODUCTION        (business production)
         *   BABELAN_PROD      = B1 + B2  (sudah net, TIDAK dikurangi House Load)
         * House Load dikurangi TEPAT SATU KALI, hanya pada JABABEKA_BUS_NET.
         * PLN Export tetap memakai topologi fisik: Jababeka Bus Net + Babelan - IE,
         * sehingga produksi MM2100 TIDAK otomatis masuk PLN Export. */
        $mm2100   = ($gen['ge1'] ?? 0) + ($gen['ge2'] ?? 0) + ($gen['ge3'] ?? 0) + ($gen['ge4'] ?? 0) + ($gen['g10'] ?? 0);
        $totalGen = array_sum($gen) - $mm2100;
        $hl       = calc_house_load($gen);
        $jbusNet  = $totalGen - $babelan - $hl;          // JABABEKA_BUS_NET (physical)
        $jbbk     = $jbusNet + $mm2100;                  // JBBK_MM_PROD (business)
        $export   = $jbusNet + $babelan - $ie;           // PLN Export tetap topologi fisik
        return ['gen' => $gen, 'total' => $totalGen, 'hl' => $hl, 'babelan' => $babelan,
                'jbus_net' => $jbusNet, 'mm2100' => $mm2100,
                'jbbk' => $jbbk, 'export' => $export];
    };

    /* cheapest dispatch whose export lands in [effLo,effHi] (Sec.1) */
    /* V6 OPT-W2: dispatch satu row (bisection block-priority ke Export minimum) adalah fungsi murni dari
     * (IE, batas Export, row) dan konfigurasi yang ditangkap closure. Hasilnya dimemo lintas core run dalam
     * satu request dengan kunci signature konfigurasi (model tanpa lever gas + data unit + blok) — tidak ada
     * pass yang berubah; PP_MEMO_VERIFY=1 membandingkan dengan perhitungan penuh. */
    $__mSd = $model; foreach (['gas_quota', '__shaper_quota_adjust', '__gas_trim_target_bbtud', '__tl_supplier_hint', 'time_budget_seconds', 'time_budget_max_seconds', '__core_run_budget', '__gas_offset_search_seconds'] as $__k) unset($__mSd[$__k]);
    $__d3d = $d3; unset($__d3d['modeling']);
    $__dSig = md5(serialize($__mSd)) . md5(serialize($__d3d)) . md5(serialize([$blocks, $minB, $HLnom, $ALL_UNITS]));
    unset($__mSd, $__d3d);
    if (!isset($GLOBALS['ppV6RowMemo']) || !is_array($GLOBALS['ppV6RowMemo']) || count($GLOBALS['ppV6RowMemo']) > 12000) $GLOBALS['ppV6RowMemo'] = [];
    $dispatch_row = function (float $ie, float $effLo, float $effHi, int $row1) use (&$dispatch_row_raw, $__dSig): array {
        $k = $__dSig . $row1 . '|' . pack('d', $ie) . pack('d', $effLo) . pack('d', $effHi);
        $h = (PP_V6_WMEMO && !PP_MEMO_OFF && isset($GLOBALS['ppV6RowMemo'][$k])) ? $GLOBALS['ppV6RowMemo'][$k] : null;
        if ($h !== null && !PP_FUEL_MEMO_VERIFY) return $h;
        $v = $dispatch_row_raw($ie, $effLo, $effHi, $row1);
        if ($h !== null && PP_FUEL_MEMO_VERIFY && $h !== $v) throw new RuntimeException('V6 OPT-W2: memo dispatch_row berbeda pada row ' . $row1);
        return $GLOBALS['ppV6RowMemo'][$k] = $v;
    };
    $dispatch_row_raw = function (float $ie, float $effLo, float $effHi, int $row1)
            use ($run_blocks, $realised_export, $HLnom): array {
        $Glo = $ie + $HLnom + $effLo;
        $Ghi = $ie + $HLnom + $effHi + 50;
        $base = $realised_export($run_blocks($Glo, $row1), $ie);
        if ($base['export'] > $effHi + 0.5)
            return $base + ['flag' => sprintf('export %.1f > max %.1f (must-run floor)', $base['export'], $effHi)];
        if ($base['export'] >= $effLo - 0.5)
            return $base + ['flag' => ''];
        $loG = $Glo; $hiG = $Ghi;
        $hiRes = $realised_export($run_blocks($hiG, $row1), $ie);
        if ($hiRes['export'] < $effLo - 0.5)
            return $hiRes + ['flag' => sprintf('export %.1f < min %.1f (capacity limit)', $hiRes['export'], $effLo)];
        $best = $hiRes;
        for ($it = 0; $it < 40; $it++) {
            $midG = ($loG + $hiG) / 2.0;
            $res = $realised_export($run_blocks($midG, $row1), $ie);
            if ($res['export'] >= $effLo - 0.5) { $best = $res; $hiG = $midG; }
            else { $loG = $midG; }
            if ($hiG - $loG < 0.25) break;
        }
        $flag = ($best['export'] > $effHi + 0.5)
              ? sprintf('export %.1f forced above max %.1f by unit step', $best['export'], $effHi) : '';
        return $best + ['flag' => $flag];
    };

    /* =================================================================
     *  GAS QUOTA TARGET (Revisi Sec.1): aim daily Total Gas at the quota,
     *  not at the minimum. Energy-consistent with how Total_Gas is summed.
     * ================================================================= */
    $qq = $model['gas_quota'];
    /* PROMPT GAS QUOTA MMSCFD/BBG (§1/§2): PEP/AKASIA/BASKARA/BBG adalah input MMSCFD
     * (konsep fixed flow Jababeka) — kontribusi quota = flow x GHV Jababeka / 1000 (BBTUD).
     * Komponen lain (PGN Pipe, LNG, KP72/MM2100) tetap BBTUD. BBG kini IKUT masuk kuota
     * fixed flow Jababeka (sebelumnya tidak pernah dibaca engine sama sekali). */
    $ghvJQ = (float)($model['ghv_jababeka'] ?? 1034.7564);
    $ffJQuotaKeys = ['pep', 'akasia', 'baskara', 'bbg'];
    /* Key MM2100 (KP72) mengikuti mode-nya: Fixed Flow = MMSCFD -> x GHV MM2100 / 1000,
     * Cummulative = sudah BBTUD. Sebelumnya seluruh key KP72 masuk mentah sebagai BBTUD,
     * sehingga volume MMSCFD dijumlahkan langsung dengan energi BBTUD. */
    $ghvMQ    = (float)($model['ghv_mm2100'] ?? 1000);
    $mmQKeys  = ['pep_kp72', 'pertagas_kp72', 'akasia_kp72', 'baskara_kp72'];
    $gmQ      = (array)($model['mm2100_gas_mode'] ?? []);
    $cumQKey  = null;
    foreach ($mmQKeys as $gk) {
        $mv = strtolower((string)($gmQ[$gk] ?? 'fixed'));
        if (($mv === 'cummulative' || $mv === 'cumulative') && $cumQKey === null) $cumQKey = $gk;
    }
    $gasQuotaTotal = 0.0;
    foreach ((array)$qq as $gkQ => $gv) {
        if (!is_numeric($gv)) continue;
        $gkQ = (string)$gkQ; $gv = (float)$gv;
        if (in_array($gkQ, $ffJQuotaKeys, true))      $gasQuotaTotal += $gv * $ghvJQ / 1000.0;  // MMSCFD -> BBTUD
        elseif (in_array($gkQ, $mmQKeys, true))       $gasQuotaTotal += ($gkQ === $cumQKey) ? $gv : ($gv * $ghvMQ / 1000.0);
        else                                          $gasQuotaTotal += $gv;                     // sudah BBTUD
    }
    /* KP72 isolation (Revisi Sec.6): the Jababeka gas-tuning quota EXCLUDES the KP72 / MM2100 components
     * (pep_kp72, pertagas_kp72, akasia_kp72, baskara_kp72). Those drive GE1-4 / GTG10 only (MM2100 side),
     * so a change in KP72 never inflates Jababeka g3/g4/g6/g8/g9 gas, PGN, or ENERGY PGN REAL TIME. */
    $kp72Keys = ['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'];
    $gasQuotaJababeka = 0.0;
    foreach ((array)$qq as $gk => $gv) if (is_numeric($gv) && !in_array($gk, $kp72Keys, true)) $gasQuotaJababeka += (float)$gv;
    /* ===== OFFSET BASIS KUOTA SHAPER (root cause: basis shaper != basis validator) =====
     * Shaper mengejar kuota KONTRAK (mis. PEP 30) sedangkan validator menilai realisasi FIXED FLOW
     * (PEP 32.4), sehingga pemakaian selalu mendarat ~0.19 BBTUD di bawah window apa pun nilai PGN.
     * Offset ini dicari di level TERLUAR memakai metrik validator sendiri (Total Gas Used vs
     * Base Gas Quota), bukan ditebak dari komponen internal. Default 0 = perilaku lama. */
    $gasQuotaJababeka += (float)($model['__jbbk_quota_offset'] ?? 0);

    /* =================================================================
     *  PER-ROW ACCOUNTING (factored so the gas-raising and startup passes
     *  can rebuild any row from a gen array with identical columns).
     * ================================================================= */
    $nRows = count($input['data1']);
    /* PROMPT BABELAN & BIOMASS (B2/B4/B8): Biomass TIDAK lagi mengacu %RE Babelan.
     * Biomass Target (MWh) dibagi rata ke seluruh ACTIVE Babelan unit-row (BBLN1/BBLN2 dgn load > 0):
     *   Biomass Allocation per active unit-row = Biomass Target / jumlah active unit-row
     * Nilai allocation dipakai sbg MW per active 30-min unit-row (definisi operasional user).
     * $bioAlloc diisi SETELAH genRows final (sebelum materialize) — closure memakai by-reference. */
    $bioAlloc = 0.0;

    $account_row = function (array $gen, float $ie, float $disp, $time, float $effLo, float $effHi)
            use ($d3, $realised_export, &$bioAlloc, $busUnit): array {
        $r   = $realised_export($gen, $ie);
        $gas = [];
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
            $gas[$u] = calc_fuel($d3, $u, $gen[$u] ?? 0);
        $gasJ = $gas['g1']+$gas['g2']+$gas['g3']+$gas['g4']+$gas['g5']+$gas['g6']+$gas['g7']+$gas['g8']+$gas['g9'];
        $gasM = $gas['g10']+$gas['ge1']+$gas['ge2']+$gas['ge3']+$gas['ge4'];
        /* B5: Biomass Load = min(Babelan Load, Biomass Allocation); Coal Load = max(0, Load - Biomass)
         * (calc_coal sudah meng-clamp net <= 0 menjadi 0 — coal tidak pernah negatif). */
        $b1L = (float)($gen['b1'] ?? 0);  $b2L = (float)($gen['b2'] ?? 0);
        $re1 = $b1L > 0 ? min($b1L, $bioAlloc) : 0.0;
        $re2 = $b2L > 0 ? min($b2L, $bioAlloc) : 0.0;
        $coal1 = calc_coal($b1L, $re1);
        $coal2 = calc_coal($b2L, $re2);
        return [
            'Time' => $time, 'Dispatch' => $disp,
            'HL' => round($r['hl'], 3), 'IE' => $ie,
            'G3'=>round($gen['g3'],2),'G4'=>round($gen['g4'],2),'G6'=>round($gen['g6'],2),'S1'=>round($gen['s1'],2),
            'G1'=>round($gen['g1'],2),'G2'=>round($gen['g2'],2),'G5'=>round($gen['g5'],2),'S2'=>round($gen['s2'],2),
            'G8'=>round($gen['g8'],2),'G9'=>round($gen['g9'],2),'S3'=>round($gen['s3'],2),
            'G7'=>round($gen['g7'],2),'G10'=>round($gen['g10'],2),
            'GE1'=>round($gen['ge1'],2),'GE2'=>round($gen['ge2'],2),'GE3'=>round($gen['ge3'],2),'GE4'=>round($gen['ge4'],2),
            'Jababeka'=>round($r['jbbk'],2),'JababekaBusNet'=>round($r['jbus_net'],2),'MM2100Prod'=>round($r['mm2100'],2),'BB1'=>round($gen['b1'],2),'BB2'=>round($gen['b2'],2),
            'BB_Total'=>round($r['babelan'],2),
            'Total_GE'=>round(($gen['ge1']??0)+($gen['ge2']??0)+($gen['ge3']??0)+($gen['ge4']??0),2),
            'Total_Net'=>round($r['jbbk']+$r['babelan'],2),
            'Export_PLN'=>round($r['export'],2),'diff'=>round($r['export']-$disp,2),
            'pln_lo'=>round($effLo,2),'pln_hi'=>round($effHi,2),
            'in_band'=>($r['export']>=$effLo-0.5 && $r['export']<=$effHi+0.5)?1:0,
            'Spin_Res'=>round(calc_sr($gen),2),'BusFlow'=>round(calc_busflow($gen,$busUnit,$ie),2),
            'Gas_Jababeka'=>round($gasJ,5),'Gas_MM2100'=>round($gasM,5),'Total_Gas'=>round($gasJ+$gasM,5),
            'RE_BB1'=>round($re1,3),'RE_BB2'=>round($re2,3),
            'Coal1'=>round($coal1,4),'Coal2'=>round($coal2,4),'Total_Coal'=>round($coal1+$coal2,4),
            'Dist_Total'=>0,
        ];
    };

    $gasTotalOf = function (array $genRows) use ($d3): float {
        $sum = 0.0;
        foreach ($genRows as $g)
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
                $sum += calc_fuel($d3, $u, $g[$u] ?? 0);
        return $sum / 2.0;   // BBTUD (48 half-hour rows / 2)
    };

    /* per-row PLN band: effLo = daily-target-lifted minimum, effHi = capped max */
    $effHiArr = []; $baseLoArr = [];
    for ($row = 0; $row < $nRows; $row++) {
        $effHiArr[$row]  = (float)$plnInfo['hi'][$row];
        $baseLoArr[$row] = (float)$plnInfo['target'][$row];
    }

    /* dispatch all rows aiming each export at $aimLo[row] (clamped <= effHi). */
    $simulate = function (array $aimLo) use ($nRows, $ieVals, $dispatch_row, $effHiArr): array {
        $genRows = []; $flags = [];
        for ($row = 0; $row < $nRows; $row++) {
            $ie = (float)$ieVals[$row];
            $lo = min($aimLo[$row], $effHiArr[$row]);
            $r  = $dispatch_row($ie, $lo, $effHiArr[$row], $row + 1);
            $genRows[$row] = $r['gen'];
            if (($r['flag'] ?? '') !== '') $flags[$row] = $r['flag'];
        }
        return ['gen' => $genRows, 'flags' => $flags];
    };

    /* ---- gas-targeting search (Revisi Lanjutan Sec.1) ----------------------
     *  Target window:  quota - TOL <= Total Gas <= quota   (TOL = 0.2 BBTUD).
     *  Gas is measured AFTER the startup + min runtime/downtime enforcement
     *  pass, because that pass shifts unit loads. We first bisect a uniform
     *  export lift L (largest L whose post-enforcement gas stays <= quota),
     *  then close any residual gap below the window with a per-row greedy
     *  top-up that raises individual rows within [effLo, effHi] while keeping
     *  gas <= quota. Anti start-stop / startup constraints always win: if they
     *  push gas below the window the gap is reported, never forced open. ------ */
    $GAS_TOL   = 0.2;
    $quotaWarn = '';

    // apply startup + runtime enforcement to a *copy*; return enforced rows + meta
    $enforce = function (array $gen) use ($d3, $model): array {
        $g  = $gen;
        $su = pp_apply_startup_constraints($g, $d3, $model);
        return ['gen' => $g, 'su' => $su];
    };
    // post-enforcement total gas for an explicit per-row aim (clamped to effHi)
    $finalForAim = function (array $aim) use ($effHiArr, $simulate, $enforce, $gasTotalOf): array {
        $a = [];
        foreach ($aim as $i => $v) $a[$i] = min((float)$v, (float)$effHiArr[$i]);
        $sim = $simulate($a);
        $e   = $enforce($sim['gen']);
        return ['gas' => $gasTotalOf($e['gen']), 'sim' => $sim, 'enf' => $e, 'aim' => $a];
    };
    // post-enforcement total gas for a uniform export lift L
    $finalForLift = function (float $L) use ($baseLoArr, $finalForAim): array {
        $aim = [];
        foreach ($baseLoArr as $i => $b) $aim[$i] = $b + $L;
        return $finalForAim($aim);
    };

    /* ---- Dispatch each row at the cheapest valid export (PLN-band minimum,
     *      priority order). Gas quota is maximised later by a dedicated back-end
     *      gas-up pass (after all min-export passes) that loads the already-running
     *      GAS units toward max load — raising export here would instead inflate
     *      Babelan COAL (block priority loads coal first), which is not what the
     *      gas quota target wants. ---------------------------------------------- */
    $chosen = $finalForLift(0.0);

    /* ---- enforcement already applied inside the search -------------------- */
    $sim      = $chosen['sim'];                            // keep flags for must-run warnings
    $genRows  = $chosen['enf']['gen'];
    $su       = $chosen['enf']['su'];
    $warnings = array_merge($warnings, $su['warnings']);
    if ($gasTotalOf($genRows) > $gasQuotaTotal + $GAS_TOL) {
        $warnings[] = sprintf(
            'Gas quota is insufficient for the selected PLN Export Priority and unit constraints (need %.3f > quota %.3f BBTUD). System did NOT auto-add LNG. Options: reduce PLN export target/range if allowed, add LNG manually (Add LNG to cover), or use distillate manually.',
            $gasTotalOf($genRows), $gasQuotaTotal);
    }

    /* ---- Export Range Min/Max redispatch (Revisi). Pull each row's PLN export
     *      into [Range Min, Range Max] by adjusting controllable GTGs: when export
     *      is over max, lower the high units first (G9/G8, then others) toward their
     *      valid minimum; when under min, raise units (G3/G4 first, per Unit
     *      Priority) toward max load. Respects min/max load, explicit stop, and the
     *      CC minimum; recomputes STG after each change. Residual range excursions
     *      (irreducible must-run baseload / IE) are still reported as warnings. */
    $rngR = $model['pln_export_priority']['range'] ?? [];
    $rMaxR = (float)($rngR['max'] ?? INF);
    $rMinR = (float)($rngR['min'] ?? 0);
    if (is_finite($rMaxR) || $rMinR > 0) {
        $expOf = function (array $gen, float $ie) use ($realised_export): float {
            $r = $realised_export($gen, $ie); return (float)$r['export'];
        };
        $validMin = function (string $u, array $gen) use ($d3): float {
            $stg = pp_gtg_to_stg($d3, $u);
            if ($stg && ($gen[$stg] ?? 0) > 0) return (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 5);
            return (float)($d3[$u]['min_scload'] ?? 5);
        };
        /* PRIORITY AUDIT (sistemik): urutan naik/turun load DIBACA dari Unit Priority input user
         * (fallback block_priority), bukan hardcode. Up = priority tertinggi dulu; Down = priority
         * terendah dulu (Rule A4). Fallback statis hanya bila input priority kosong sama sekali. */
        $upOrder   = pp_priority_flat($model, '/^g\d+$/');
        if (!$upOrder) $upOrder = ['g8','g9','g3','g4','g6','g1','g2','g5','g7','g10'];
        $downOrder = array_reverse($upOrder);   // lower the LOWEST Unit Priority first (Rule A4)

        /* ---- ADDENDUM: maximise already-running priority units before starting a
         *      new one. Build the GTG priority order from block_priority; a GTG that
         *      is freshly STARTED while higher-priority running units still have
         *      headroom to max (and its block is not "required") is an unnecessary
         *      start — it is shed (0 MW) and the load is restored by raising the
         *      priority units in the range pass below. */
        $prioList = []; $protectLead = [];
        foreach (($model['block_priority'] ?? []) as $blk) {
            $isReq = in_array('required', $blk, true); $first = true;
            foreach ($blk as $t) if (preg_match('/^g\d+$/', $t)) {
                $prioList[] = $t;
                if ($first) { if ($isReq) $protectLead[$t] = true; $first = false; }  // keep a required block's lead so the block stays available
            }
        }
        // Tahap 1: Required units and Unit-Cannot-Stop units are highest priority and
        // must never be shed by the optimizer.
        $reqUnits = array_values(array_filter(array_map('strtolower', (array)($model['required_units'] ?? []))));
        $cannotStop = array_values(array_filter(array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []))));
        foreach ($reqUnits as $u)   $protectLead[$u] = true;
        foreach ($cannotStop as $u) $protectLead[$u] = true;
        $rank = array_flip($prioList);
        $shedOrder = array_reverse($prioList);
        foreach ($shedOrder as $u) {
            // A required block only needs its LEAD running to be "available"; secondary
            // units (e.g. G4) and lower-block leads (e.g. G1) must still defer to
            // higher-priority running units that still have headroom. Only a required
            // block's lead is protected from shedding.
            if (!isset($d3[$u]) || isset($protectLead[$u])) continue;
            $myRank = $rank[$u] ?? 99;
            $shed = 0; $firstRow = 0;
            // Shed on every row where higher-priority running units can absorb the load
            // (minimises gas), then drop any brief restart segment left behind so the
            // unit does not start-stop (which would churn and inflate startup penalty).
            for ($k = 0; $k < $nRows; $k++) {
                $need = (float)($genRows[$k][$u] ?? 0);
                if ($need <= 0) continue;
                if (pp_is_unit_stopped($d3, $model, $u, $k + 1)) continue;
                $head = 0.0;
                foreach ($prioList as $hp) {
                    if (($rank[$hp] ?? 99) >= $myRank) continue;
                    if (($genRows[$k][$hp] ?? 0) <= 0) continue;                // only already-running units
                    if (pp_is_unit_stopped($d3, $model, $hp, $k + 1)) continue;
                    /* PATCH G29-G31 (re-apply): headroom dihitung terhadap EFFECTIVE max per row (Max Load Adjustment), bukan raw max_load */
                    $head += max(0.0, pp_effective_maxload($d3, $model, $hp, $k + 1) - (float)($genRows[$k][$hp] ?? 0));
                }
                if ($head >= $need - 1e-6) {
                    $genRows[$k][$u] = 0.0;
                    pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1);
                    $shed++; if (!$firstRow) $firstRow = $k + 1;
                }
            }
            // remove brief ON-segments (< min runtime) left over -> avoids start-stop churn,
            // but only if export stays within Range Min without the unit on those rows.
            $rMinShed = (float)($model['pln_export_priority']['range']['min'] ?? 0);
            $minRun = max(2, (int)round(pp_runtime_class($u, $d3) === 'gtg_large'
                ? (float)($model['runtime_downtime']['minimum_runtime_gtg_large'] ?? 2) * 2
                : (float)($model['runtime_downtime']['minimum_runtime_combined_cycle'] ?? 1.5) * 2));
            foreach (pp_segments($genRows, $u) as $sg) {
                if (!$sg['on']) continue;
                $len = $sg['b'] - $sg['a'];
                if ($len >= $minRun) continue;
                $safe = true;
                for ($k = $sg['a']; $k < $sg['b']; $k++) {
                    if (pp_is_unit_stopped($d3, $model, $u, $k + 1)) { $safe = false; break; }
                    $probe = $genRows[$k]; $probe[$u] = 0.0; pp_recompute_stgs($probe, $d3, $model, $k + 1);
                    $ex = (float)$realised_export($probe, (float)$ieVals[$k])['export'];
                    if ($ex < $rMinShed - 0.5) { $safe = false; break; }       // unit needed for Range Min here
                }
                if ($safe) {
                    for ($k = $sg['a']; $k < $sg['b']; $k++) { if (($genRows[$k][$u] ?? 0) > 0) { $genRows[$k][$u] = 0.0; $shed++; } pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1); }
                }
            }
            if ($shed > 0) {
                $warnings[] = sprintf('Unnecessary unit start deferred: %s held OFF for %d slot%s (from row %d) — higher-priority running units still had headroom; start delayed/avoided.',
                    strtoupper($u), $shed, $shed > 1 ? 's' : '', $firstRow);
            }
        }

        /* ---- ADDENDUM: Gas Available is the simulation LIMIT, not a target.
         *      If the current dispatch needs more gas than the available quota,
         *      lower PLN export toward Range Min first (shed controllable units to
         *      their valid minimum, big/late units first) so total gas fits the
         *      quota — never auto-adding LNG/gas. Only runs when actually short, so
         *      a within-quota plan is left untouched. Any residual over-quota gas is
         *      irreducible (required blocks / min-runtime) and becomes a shortage
         *      recommendation. */
        if ($gasTotalOf($genRows) > $gasQuotaTotal + $GAS_TOL) {
            for ($pass = 0; $pass < 6; $pass++) {
                if ($gasTotalOf($genRows) <= $gasQuotaTotal + $GAS_TOL) break;
                $progress = false;
                for ($row = 0; $row < $nRows; $row++) {
                    $ie = (float)$ieVals[$row];
                    $gen =& $genRows[$row];
                    for ($it = 0; $it < 40 && $expOf($gen, $ie) > $rMinR + 0.5; $it++) {
                        $did = false;
                        foreach ($downOrder as $u) {
                            if (!isset($d3[$u]) || ($gen[$u] ?? 0) <= 0) continue;
                            if (pp_is_unit_stopped($d3, $model, $u, $row + 1)) continue;
                            $floor = $validMin($u, $gen);
                            if (($gen[$u] ?? 0) <= $floor + 1e-6) continue;
                            $over = $expOf($gen, $ie) - $rMinR;
                            $gen[$u] = max($floor, (float)$gen[$u] - max(2.0, min($over, (float)$gen[$u] - $floor)));
                            pp_recompute_stgs($gen, $d3, $model, $row + 1);
                            $did = true; $progress = true;
                            if ($expOf($gen, $ie) <= $rMinR + 0.5) break;
                        }
                        if (!$did) break;
                    }
                    unset($gen);
                    if ($gasTotalOf($genRows) <= $gasQuotaTotal + $GAS_TOL) break;
                }
                if (!$progress) break;
            }
            $resid = $gasTotalOf($genRows) - $gasQuotaTotal;
            if ($resid > $GAS_TOL)
                $warnings[] = sprintf('Gas-limited: PLN export lowered toward Range Min to respect the available gas quota; residual %.3f BBTUD over quota is irreducible (required blocks / min-runtime) — see the Gas Shortage Recommendation, no LNG/gas auto-added.', $resid);
            else
                $warnings[] = 'Gas-limited: PLN export lowered toward Range Min so total gas fits the available quota (no LNG/gas auto-added).';
        }

        for ($row = 0; $row < $nRows; $row++) {
            $ie  = (float)$ieVals[$row];
            $gen =& $genRows[$row];
            // over max -> lower controllable units toward their valid min
            for ($it = 0; $it < 40 && $expOf($gen, $ie) > $rMaxR + 0.5; $it++) {
                $did = false;
                foreach ($downOrder as $u) {
                    if (!isset($d3[$u]) || ($gen[$u] ?? 0) <= 0) continue;
                    if (pp_is_unit_stopped($d3, $model, $u, $row + 1)) continue;
                    $floor = $validMin($u, $gen);
                    if (($gen[$u] ?? 0) <= $floor + 1e-6) continue;          // already at valid min
                    $over = $expOf($gen, $ie) - $rMaxR;
                    $gen[$u] = max($floor, (float)$gen[$u] - max(2.0, min($over, (float)$gen[$u] - $floor)));
                    pp_recompute_stgs($gen, $d3, $model, $row + 1);
                    $did = true;
                    if ($expOf($gen, $ie) <= $rMaxR + 0.5) break;
                }
                if (!$did) break;                                            // nothing left to lower
            }
            // under min -> raise running units toward max load
            for ($it = 0; $it < 40 && $expOf($gen, $ie) < $rMinR - 0.5; $it++) {
                $did = false;
                foreach ($upOrder as $u) {
                    if (!isset($d3[$u]) || ($gen[$u] ?? 0) <= 0) continue;   // only raise already-running units
                    if (pp_is_unit_stopped($d3, $model, $u, $row + 1)) continue;
                    $max = pp_effective_maxload($d3, $model, $u, $row + 1);   // PATCH G29-G31: effective max per row
                    if (($gen[$u] ?? 0) >= $max - 1e-6) continue;            // already at max
                    $need = $rMinR - $expOf($gen, $ie);
                    $gen[$u] = min($max, (float)$gen[$u] + max(2.0, min($need, $max - (float)$gen[$u])));
                    pp_recompute_stgs($gen, $d3, $model, $row + 1);
                    $did = true;
                    if ($expOf($gen, $ie) >= $rMinR - 0.5) break;
                }
                if (!$did) break;                                            // nothing left to raise
            }
            // Still under Range Min after maxing running units. Per the final spec,
            // gas quota is the hard limit: we do NOT start a new unit from 0 (that
            // would break the startup ramp and push gas over quota). The shortfall is
            // reported as infeasible so the dispatch never silently violates the band.
            if ($expOf($gen, $ie) < $rMinR - 0.5) {
                $warnings[] = sprintf('No feasible dispatch found: row %d export %.1f MW below Range Min %.0f MW — cannot raise further without starting a unit (would break startup sequence / exceed gas quota).',
                    $row + 1, $expOf($gen, $ie), $rMinR);
            }
            unset($gen);
        }

        /* ---- Final anti-churn cleanup (Sec.12): the gas/range passes above can
         *      leave brief restart segments. Remove any ON-segment shorter than the
         *      unit's minimum runtime when export stays within Range Min without it,
         *      so units do not start-stop repeatedly (which inflates startup penalty
         *      and violates Runtime & Downtime). Required-block leads are preserved. */
        foreach (array_reverse($prioList) as $u) {
            if (!isset($d3[$u]) || isset($protectLead[$u])) continue;
            $minRun = max(2, (int)round(pp_runtime_class($u, $d3) === 'gtg_large'
                ? (float)($model['runtime_downtime']['minimum_runtime_gtg_large'] ?? 2) * 2
                : (float)($model['runtime_downtime']['minimum_runtime_combined_cycle'] ?? 1.5) * 2));
            foreach (pp_segments($genRows, $u) as $sg) {
                if (!$sg['on'] || ($sg['b'] - $sg['a']) >= $minRun) continue;
                $safe = true;
                for ($k = $sg['a']; $k < $sg['b']; $k++) {
                    if (pp_is_unit_stopped($d3, $model, $u, $k + 1)) { $safe = false; break; }
                    $probe = $genRows[$k]; $probe[$u] = 0.0; pp_recompute_stgs($probe, $d3, $model, $k + 1);
                    if ((float)$realised_export($probe, (float)$ieVals[$k])['export'] < $rMinR - 0.5) { $safe = false; break; }
                }
                if ($safe) for ($k = $sg['a']; $k < $sg['b']; $k++) { $genRows[$k][$u] = 0.0; pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1); }
            }
        }
    }

    /* ---- Tahap 1: Required / Unit-Cannot-Stop commitment enforcement -------
     *   Unit Cannot Stop: the unit must be online on every one of the 48 intervals
     *   (>= its simple-cycle minimum). Required: the unit must run at least once in
     *   the horizon (flexible timing). Both are protected from shedding above. If a
     *   commitment pushes gas over quota, the gas-quota status flags it (Sec.5). */
    $csU = array_values(array_filter(array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []))));
    $reqU = array_values(array_filter(array_map('strtolower', (array)($model['required_units'] ?? []))));
    foreach ($csU as $u) {
        if (!isset($d3[$u])) continue;
        $floor = (float)($d3[$u]['min_scload'] ?? 5);
        $fixed = 0;
        for ($r = 0; $r < $nRows; $r++) {
            if (($genRows[$r][$u] ?? 0) < $floor - 1e-6) {
                $genRows[$r][$u] = $floor;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $fixed++;
            }
        }
        if ($fixed > 0)
            $warnings[] = sprintf('Unit Cannot Stop: %s forced online (>= %.0f MW) for %d slot%s — must remain online across all 48 intervals.',
                strtoupper($u), $floor, $fixed, $fixed > 1 ? 's' : '');
    }
    foreach ($reqU as $u) {
        if (!isset($d3[$u]) || in_array($u, $csU, true)) continue;   // cannot-stop already online
        $runs = 0; for ($r = 0; $r < $nRows; $r++) if (($genRows[$r][$u] ?? 0) >= 1) $runs++;
        if ($runs === 0) {
            $floor = (float)($d3[$u]['min_scload'] ?? 5);
            $genRows[0][$u] = $floor;
            pp_recompute_stgs($genRows[0], $d3, $model, 1);
            $warnings[] = sprintf('Required unit: %s was not committed by the optimizer; forced ON for 1 slot (>= %.0f MW) to meet the run-at-least-once requirement.',
                strtoupper($u), $floor);
        }
    }

    /* ---- FINAL Additional-HRSG ramp re-enforcement (Sec.2/5/6) ------------
     *   The export-range / gas / shed / commitment passes can re-introduce a GTG
     *   jumping 0 -> free load (e.g. G4 0->20 @18:30, G6 0->22 @22:00) bypassing
     *   the startup ramp. For a genuine Additional-HRSG start (block STG already
     *   loaded AND another block GTG already running on the previous row) the ramp
     *   is BACKFILLED earlier — 5 MW two rows before, 15 MW one row before — so the
     *   target row keeps its free load (and stays inside the export range), exactly
     *   like Sec.6: free @18:30 => 5 @17:30, 15 @18:00. If it cannot start earlier
     *   (no room / explicit stop) the free jump is deferred 5->15->free instead.
     *   Runs BEFORE the manual fixed-load / actual-data overrides so those win. */
    $ccGtgs = ['g1','g2','g3','g4','g5','g6'];   // HRSG 1-6 short ramp (5->15->free); G8/G9 (40->50->60->70) handled by the forward-cap re-enforcement below
    foreach ($ccGtgs as $u) {
        if (!isset($d3[$u])) continue;
        $stg = pp_gtg_to_stg($d3, $u); if (!$stg) continue;
        $mcc = (float)($d3[$u]['min_ccload'] ?? 5);
        $blk = []; foreach ($ccGtgs as $og) if ($og !== $u && pp_gtg_to_stg($d3, $og) === $stg) $blk[] = $og;
        for ($r = 1; $r < $nRows; $r++) {
            $prev = $genRows[$r-1][$u] ?? 0; $cur = $genRows[$r][$u] ?? 0;
            if ($prev >= 1 || $cur < $mcc - 1e-6) continue;          // only 0 -> free(>=min CC) jumps
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
            $prevStg = $genRows[$r-1][$stg] ?? 0;
            $otherRun = false; foreach ($blk as $og) if (($genRows[$r-1][$og] ?? 0) >= 1) { $otherRun = true; break; }
            if (!($prevStg > 0 && $otherRun)) continue;              // not an Additional-HRSG start (block off => STG-startup handled below)
            if ($r >= 2 && ($genRows[$r-1][$u] ?? 0) < 1 && ($genRows[$r-2][$u] ?? 0) < 1
                    && !pp_is_unit_stopped($d3, $model, $u, $r) && !pp_is_unit_stopped($d3, $model, $u, $r - 1)) {
                $genRows[$r-2][$u] = 5.0;  pp_recompute_stgs($genRows[$r-2], $d3, $model, $r - 1);
                $genRows[$r-1][$u] = 15.0; pp_recompute_stgs($genRows[$r-1], $d3, $model, $r);
                $warnings[] = sprintf('Additional HRSG ramp inserted for %s: 5 MW then 15 MW before free load (STG held during 5/15 MW).', strtoupper($u));
            } else {
                $genRows[$r][$u] = 5.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($r + 1 < $nRows && ($genRows[$r+1][$u] ?? 0) >= 1) { $genRows[$r+1][$u] = 15.0; pp_recompute_stgs($genRows[$r+1], $d3, $model, $r + 2); }
                $warnings[] = sprintf('Additional HRSG: %s could not start earlier; free load deferred (5->15->free).', strtoupper($u));
            }
        }
    }
    $suFinal = pp_apply_startup_constraints($genRows, $d3, $model);
    if (!empty($suFinal['warnings'])) $warnings = array_merge($warnings, $suFinal['warnings']);

    /* ---- Tahap 2: Manual Fixed Load — final hard re-apply -----------------
     *   unit_fix_load{unit:[{start,stop,value}]} is honoured during dispatch, but
     *   the shed / gas-lowering / range / commitment passes above may have moved
     *   those cells. Re-apply the fixed values LAST so a manual fix always wins.
     *   Infeasible fixes (outside [0,max_load]) are flagged; gas/PLN feasibility is
     *   reported through the existing quota-status and export-compliance checks. */
    foreach (($model['unit_fix_load'] ?? []) as $u => $rules) {
        $uu = strtolower($u); if (!isset($d3[$uu])) continue;
        $UU = strtoupper($uu);
        $maxL = (float)($d3[$uu]['max_load'] ?? 1e9);
        foreach ((array)$rules as $rule) {
            $val = (float)($rule['value'] ?? 0);
            $s = max(1, (int)($rule['start'] ?? 1)); $e = min($nRows, (int)($rule['stop'] ?? $nRows));
            $minSC = (float)($d3[$uu]['min_scload'] ?? 0);
            if ($val < -1e-9 || $val > $maxL + 1e-6)
                $warnings[] = sprintf('Fixed Load INFEASIBLE: %s = %.2f MW is outside [0, %.2f] (min/max load). Applied as-is — review the value.', $UU, $val, $maxL);
            elseif ($val > 1e-6 && $minSC > 0 && $val < $minSC - 1e-6)
                $warnings[] = sprintf('Fixed Load INFEASIBLE: %s = %.2f MW is below its minimum stable load (%.2f MW). The unit cannot run steadily at this point — review the value.', $UU, $val, $minSC);
            for ($r = $s - 1; $r <= $e - 1; $r++) {
                if (!isset($genRows[$r])) continue;
                /* PATCH G29-G31: Fixed Load vs Max Load Adjustment — fix tetap menang (hard constraint), tapi
                 * bila melebihi EFFECTIVE max pada row ber-Max-Load-Adjustment, laporkan evidence row-level. */
                $emFx = pp_effective_maxload($d3, $model, $uu, $r + 1);
                if ($val > $emFx + 1e-6 && $emFx < $maxL - 1e-9)
                    $warnings[] = sprintf('Fixed Load INFEASIBLE: %s = %.2f MW exceeds Effective Max Load %.2f MW (Max Load Adjustment) at row %d. Applied as-is — review the value.', $UU, $val, $emFx, $r + 1);
                $genRows[$r][$uu] = $val;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }

    /* ---- Tahap 3: Actual Data override + run-window -----------------------
     *   actual_data{rows:[{row,hl,ie,export,g1..g10,s1..s3,ge1..ge4,b1,b2}]} replaces
     *   the Simulation Data for the actual period (e.g. 00:30..10:00). The optimizer
     *   does not change those rows; only derived values (gas, bus flow, spinning
     *   reserve, cost) are recomputed from the actual generation. Simulation is the
     *   remaining window (e.g. 10:30..00:00). */
    $actualRows = [];
    $aGenUnits = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'];
    foreach (($model['actual_data']['rows'] ?? []) as $a) {
        $ri = (int)($a['row'] ?? 0) - 1;
        if ($ri < 0 || $ri >= $nRows) continue;
        foreach ($aGenUnits as $u) if (isset($a[$u]) && $a[$u] !== '' && $a[$u] !== null) $genRows[$ri][$u] = (float)$a[$u];
        if (isset($a['ie']) && $a['ie'] !== '' && $a['ie'] !== null) $ieVals[$ri] = (float)$a['ie'];
        $actualRows[$ri] = $a;   // keep for HL/Export override after the build
    }
    $actualLastRow = $actualRows ? (max(array_keys($actualRows)) + 1) : 0;   // 1-based, 0 = none

    /* ========================================================================
     *  Revisi FINAL: GAS QUOTA MUST BE USED — back-end gas-up pass
     *  (Sec.1,2,4,6,9,11). After every min-export / shed / range / commitment
     *  pass, raise PLN export PRORATE/SMOOTH by loading the already-running GAS
     *  units (Unit Priority order, highest first) toward their max load until
     *  Total Gas reaches the window [quota - 0.04, quota].
     *  Hard constraints (never violated):
     *    - export per row stays <= Range Max / effHi,
     *    - the 20 MW/30min ramp is preserved (raise is spread evenly over all
     *      48 rows; each row capped by its neighbours' export + 20 MW),
     *    - NO new unit is started (only running units are raised — Sec.9),
     *    - Total Gas never exceeds the quota,
     *    - Actual-period rows are left untouched,
     *    - STG steam is recomputed after each step (free power, no extra gas).
     *  No LNG / distillate is auto-added. If running-unit headroom or the export
     *  ceiling cannot consume the quota, the residual is reported as
     *  UNDER-UTILIZED by the gas-utilization status below. ===================== */
    $rowGas = function (array $g) use ($d3): float {
        $s = 0.0;
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
            $s += calc_fuel($d3, $u, $g[$u] ?? 0);
        return $s / 2.0;
    };
    $penOf = function (array $rows) use ($model, $nRows): float {
        /* ZERO STARTUP GAS PENALTY (keputusan domain final): default 0.0 — lihat pp_startup_gas_penalty. */
        $sp  = $model['startup_penalty'] ?? [];
        $p16 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
        $p70 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
        $t = 0.0;
        foreach (['g1','g2','g3','g4','g5','g6'] as $u) for ($k = 1; $k < $nRows; $k++) if (($rows[$k-1][$u] ?? 0) < 1 && ($rows[$k][$u] ?? 0) >= 1) $t += $p16;
        foreach (['g7','g8','g9','g10'] as $u)        for ($k = 1; $k < $nRows; $k++) if (($rows[$k-1][$u] ?? 0) < 1 && ($rows[$k][$u] ?? 0) >= 1) $t += $p70;
        return $t;
    };
    $rMaxGas    = (float)($model['pln_export_priority']['range']['max'] ?? INF);
    /* PRIORITY AUDIT (sistemik): Unit Priority dari input user, bukan hardcode. */
    $upOrderGas = pp_priority_flat($model, '/^g\d+$/');
    if (!$upOrderGas) $upOrderGas = ['g8','g9','g3','g4','g6','g1','g2','g5','g7','g10'];
    $rampLim    = 20.0;
    $gasTarget  = $gasQuotaTotal - $penOf($genRows);     // calc_fuel budget (startup penalty reserved)
    $winLoGas   = 0.0;
    $gasNow     = $gasTotalOf($genRows);
    if ($gasNow < $winLoGas) {
        $rounds = 0;
        while ($gasNow < $winLoGas && $rounds++ < 800) {
            $progress = false;
            for ($row = 0; $row < $nRows; $row++) {
                if ($gasNow >= $winLoGas) break;
                if (isset($actualRows[$row])) continue;        // never touch the actual period
                $ie  = (float)$ieVals[$row];
                $gen =& $genRows[$row];
                $expNow = (float)$realised_export($gen, $ie)['export'];
                $expCap = min($effHiArr[$row], $rMaxGas);
                if ($row > 0)            $expCap = min($expCap, (float)$realised_export($genRows[$row-1], (float)$ieVals[$row-1])['export'] + $rampLim);
                if ($row < $nRows - 1)   $expCap = min($expCap, (float)$realised_export($genRows[$row+1], (float)$ieVals[$row+1])['export'] + $rampLim);
                if ($expNow >= $expCap - 1e-6) { unset($gen); continue; }
                foreach ($upOrderGas as $u) {
                    if (!isset($d3[$u]) || ($gen[$u] ?? 0) <= 0) continue;            // running units only (no new start)
                    if (pp_is_unit_stopped($d3, $model, $u, $row + 1)) continue;
                    $max = pp_effective_maxload($d3, $model, $u, $row + 1);   // PATCH G29-G31: effective max per row
                    if (($gen[$u] ?? 0) >= $max - 1e-6) continue;
                    $gOld  = (float)$gen[$u];
                    $oldRG = $rowGas($gen);
                    $gen[$u] = min($max, $gOld + 2.0);
                    pp_recompute_stgs($gen, $d3, $model, $row + 1);
                    $newExp = (float)$realised_export($gen, $ie)['export'];
                    $newRG  = $rowGas($gen);
                    if ($newExp > $expCap + 1e-6 || ($gasNow - $oldRG + $newRG) > $gasTarget + 1e-9) {
                        $gen[$u] = $gOld; pp_recompute_stgs($gen, $d3, $model, $row + 1);   // revert: would break ramp/range or exceed quota
                        continue;
                    }
                    $gasNow = $gasNow - $oldRG + $newRG;
                    $progress = true;
                    break;   // one priority unit step per row per round -> keeps the raise smooth/prorate
                }
                unset($gen);
            }
            if (!$progress) break;
        }
        $suGas = pp_apply_startup_constraints($genRows, $d3, $model, $actualRows);   // PROMPT MONITORING §8.2: row locked tidak disentuh
        if (!empty($suGas['warnings'])) $warnings = array_merge($warnings, $suGas['warnings']);
    }

    /* ---- Babelan CF-target & highest-priority enforcement (Revisi Sec.12) -
     *   Babelan is coal-fired (no gas cost) and the highest-priority unit: it is
     *   the first to be loaded and the last to be shed. If its capacity factor is
     *   below the input target, raise b1/b2 toward the target-equivalent average
     *   so long as the PLN export stays within Range Max and each unit stays <=
     *   its max load. This fixes the case where Export PLN sits ~88 MW (Range Max
     *   150) while Babelan CF is only ~66%. */
    $cfTarget = (float)($model['babelan_cf_target'] ?? 0);
    if ($cfTarget > 0 && isset($model['babelan_cap'])) {
        $bc = $model['babelan_cap'];
        $tf = $cfTarget / 100.0;
        if (abs((float)$bc[0]) < 1e-12) {
            $targetAvg = ((float)$bc[1] != 0.0) ? ($tf - (float)$bc[2]) / (float)$bc[1] : 0.0;
        } else {
            $disc = (float)$bc[1] ** 2 - 4 * (float)$bc[0] * ((float)$bc[2] - $tf);
            $targetAvg = $disc >= 0 ? (-(float)$bc[1] + sqrt($disc)) / (2 * (float)$bc[0]) : 0.0;
        }
        $rMaxBB = (float)($model['pln_export_priority']['range']['max'] ?? INF);
        $raised = 0;
        for ($row = 0; $row < $nRows; $row++) {
            /* PATCH G31 (re-apply): Babelan raise dibatasi EFFECTIVE max per row (Max Load Adjustment), bukan raw max_load */
            $bMax1 = pp_effective_maxload($d3, $model, 'b1', $row + 1); if ($bMax1 <= 0) $bMax1 = (float)($d3['b1']['max_load'] ?? 125);
            $bMax2 = pp_effective_maxload($d3, $model, 'b2', $row + 1); if ($bMax2 <= 0) $bMax2 = (float)($d3['b2']['max_load'] ?? 125);
            /* PROMPT SISTEMIK §7: kenaikan CF top-up WAJIB menghormati ramp Babelan 10 MW/30-menit —
             * cap terhadap kedua tetangga (termasuk startup 0 -> 10 -> 20 ...), tanpa zig-zag. */
            $bbLimCF = pp_babelan_ramp_limit($model);
            foreach ([$row - 1, $row + 1] as $nbB) {
                if ($nbB < 0 || $nbB >= $nRows) continue;
                $bMax1 = min($bMax1, (float)($genRows[$nbB]['b1'] ?? 0) + $bbLimCF);
                $bMax2 = min($bMax2, (float)($genRows[$nbB]['b2'] ?? 0) + $bbLimCF);
            }
            $gen =& $genRows[$row];
            $curBB = ($gen['b1'] ?? 0) + ($gen['b2'] ?? 0);
            if ($curBB >= $targetAvg - 1e-6) { unset($gen); continue; }
            $ie  = (float)$ieVals[$row];
            $exp = (float)$realised_export($gen, $ie)['export'];
            $headExp  = is_finite($rMaxBB) ? max(0.0, $rMaxBB - $exp) : INF;     // export room to Range Max
            $headUnit = ($bMax1 - ($gen['b1'] ?? 0)) + ($bMax2 - ($gen['b2'] ?? 0));
            $want = min($targetAvg - $curBB, $headUnit, $headExp);
            if ($want <= 1e-6) { unset($gen); continue; }
            $add1 = min($want, $bMax1 - ($gen['b1'] ?? 0)); $gen['b1'] = ($gen['b1'] ?? 0) + $add1; $want -= $add1;
            if ($want > 1e-6) { $add2 = min($want, $bMax2 - ($gen['b2'] ?? 0)); $gen['b2'] = ($gen['b2'] ?? 0) + $add2; }
            $raised++;
            unset($gen);
        }
        if ($raised > 0)
            $warnings[] = sprintf('Babelan raised toward CF target %.0f%% (highest-priority unit) on %d slot(s), within Range Max and unit max load.', $cfTarget, $raised);
    }

    /* ---- Revisi Lanjutan: final dispatch shaper. Produces the feasible
     *      combined-cycle dispatch (export band + ramp, ramped base-load Babelan,
     *      G8/G9 CC-min floor, valid Additional-HRSG/STG start-ups, gas -> 61 BBTUD
     *      quota) and supersedes the advisory passes above. ------------------- */
    /* BUGFIX (Master Audit): the shaper previously received the BARE Jababeka quota while the
     * utilization/quota validator uses effTotalQuota = quota + Additional LNG (gas_shortage_action
     * = add_lng). The 2-BBTUD mismatch made the shaper (a) refuse gas-raising steps that the export
     * ramp smoothing needed to pre-ramp into a higher Range-rule window, and (b) leave Total Gas
     * structurally below the utilization target. The shaper now gets the SAME effective quota. */
    /* WEEKLY §2/§4 — KOREKSI KUOTA SHAPER (outer retry, generik): bila run sebelumnya menghasilkan
     * accounted gas di LUAR strict window, pp_run_simulation menyuntik penyesuaian kecil lewat
     * modeling.__shaper_quota_adjust sehingga shaper (engine yang SAMA) melakukan trim/top-up lebih
     * tepat. Tidak ada optimizer kedua: hanya parameter kuota efektif yang digeser. */
    $shaperQuota = (float)($model['__shaper_quota_adjust'] ?? 0.0) + $gasQuotaJababeka
        + (in_array(($model['gas_shortage_action'] ?? 'none'), ['add_lng','mixed_lng_distillate'], true) ? (float)($model['additional_lng'] ?? 0) : 0.0);
    /* WEEKLY §7 — RAMP BOUNDARY LINTAS HARI: bila weekly orchestrator menyuplai beban akhir hari
     * sebelumnya (modeling.unit_last_load_mw), row 1 dibatasi dalam ramp unit terhadap nilai itu.
     * Aditif: tanpa field tsb (Daily Plan biasa) blok ini tidak melakukan apa pun. */
    if (!empty($model['unit_last_load_mw']) && !isset($actualRows[0])) {
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','b1','b2'] as $uPB) {
            $isBB = ($uPB === 'b1' || $uPB === 'b2');
            $limPB = $isBB ? pp_babelan_ramp_limit($model) : 30.0;
            $capPB = pp_prev_day_ramp_cap($model, $uPB, $limPB);
            if ($capPB === null) continue;
            if (pp_get_fixed_load($model, $uPB, 1) >= 0) continue;
            $curPB = (float)($genRows[0][$uPB] ?? 0);
            if ($curPB < 0.01) continue;                                  // unit OFF di row 1: startup diatur pass lain
            $newPB = max($capPB[0], min($capPB[1], $curPB));
            if (abs($newPB - $curPB) > 1e-9) {
                $genRows[0][$uPB] = $newPB;
                pp_recompute_stgs($genRows[0], $d3, $model, 1);
            }
        }
    }
    /* ===== CARRY MINIMUM RUNTIME LINTAS TENGAH MALAM (WEEKLY ITEM-1) =====================
     * Unit yang start di ekor hari sebelumnya membawa sisa kewajiban minimum runtime
     * (modeling.carry_min_runtime_rows[unit] = jumlah row). Unit WAJIB tetap berbeban pada
     * row 1..N saja — SETELAH itu bebas (boleh di-decommit/stop bila lebih ekonomis).
     * Ini menggantikan pemaksaan required_units sepanjang hari yang membuat gas over-quota. */
    foreach ((array)($model['carry_min_runtime_rows'] ?? []) as $uC => $needC) {
        $uC = strtolower((string)$uC); $needC = (int)$needC;
        if ($needC <= 0 || !isset($d3[$uC])) continue;
        $mnC = (float)($d3[$uC]['min_ccload'] ?? ($d3[$uC]['min_scload'] ?? 20));
        $prevC = (float)($model['unit_last_load_mw'][strtoupper($uC)] ?? $mnC);
        $applied = 0;
        for ($r = 0; $r < min($needC, $nRows); $r++) {
            if (isset($actualRows[$r])) continue;
            if (pp_is_unit_stopped($d3, $model, $uC, $r + 1)) break;      // stop schedule operator menang
            $fvC = pp_get_fixed_load($model, $uC, $r + 1);
            $tgt = ($fvC >= 0) ? $fvC : max($mnC, min($prevC, (float)($d3[$uC]['max_load'] ?? $mnC)));
            if ((float)($genRows[$r][$uC] ?? 0) < $tgt - 1e-9) {
                $genRows[$r][$uC] = $tgt;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $applied++;
            }
            $prevC = (float)$genRows[$r][$uC];
        }
        if ($applied > 0)
            $GLOBALS['__pp_carry_notes'][strtoupper($uC)] = sprintf('online %d row pertama (kewajiban minimum runtime hari sebelumnya)', $needC);
    }

    pp_shape_final_dispatch($genRows, $d3, $model, $ieVals, $shaperQuota, $actualRows, $warnings);

    /* CO12 R16 hard construction invariant: no downstream shaper may leave a GTG above the
     * same row-specific effective maximum used by the hard validator. Recompute dependent STGs
     * after every clamp so export/headroom repair sees the physical combined-cycle output. */
    $co12MaxClamps = 0;
    foreach ($genRows as $r16 => &$row16) {
        if (isset($actualRows[$r16])) continue;
        $rowChanged16 = false;
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u16) {
            $cur16 = (float)($row16[$u16] ?? 0); if ($cur16 <= 0.0) continue;
            $mx16 = pp_effective_max_load($d3, $model, $u16, $r16 + 1);
            if ($mx16 <= 0) $mx16 = (float)($d3[$u16]['max_load'] ?? $cur16);
            if ($cur16 > $mx16 + 1e-9) { $row16[$u16] = $mx16; $co12MaxClamps++; $rowChanged16 = true; }
        }
        if ($rowChanged16) pp_recompute_stgs($row16, $d3, $model, $r16 + 1);
    }
    unset($row16);
    if ($co12MaxClamps > 0) $warnings[] = sprintf('CO12 max-load construction guard clamped %d GTG row value(s) to effective maximum; STG recomputed.', $co12MaxClamps);

        /* PATCH H08 — peta window startup (Additional HRSG) per unit/row utk guard pass hilir:
         * langkah sequence (G1-6: 5,15 ; G8/9: 40,40,50,60,60,70) adalah NILAI PASTI — trim/refill/
         * ladder DILARANG mengubahnya. Window = firstOn..firstOn+len-1 utk unit fresh (last=Stop). */
        $suWinL = [];
        {
            $lsW = []; foreach (($model['unit_last_data_status'] ?? []) as $kW => $vW) $lsW[strtolower($kW)] = strtolower((string)$vW);
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uW2) {
                if (!isset($d3[$uW2]) || ($lsW[$uW2] ?? '') !== 'stop') continue;
                $fW = -1; for ($rW = 0; $rW < $nRows; $rW++) if ((float)($genRows[$rW][$uW2] ?? 0) > 0.01) { $fW = $rW; break; }
                if ($fW < 0) continue;
                /* jenis start: BLOCK start (unit ini feeder PERTAMA blok + STG fresh) memakai caps mode
                 * STG (S3 cold 12 langkah!); selain itu Additional HRSG (G8/9: 6, G1-6: 2, SC: 1). */
                $lenW = in_array($uW2, ['g8','g9'], true) ? 6 : (in_array($uW2, ['g7','g10'], true) ? 1 : 2);
                $stgW = pp_gtg_to_stg($d3, $uW2);
                if ($stgW && ($lsW[$stgW] ?? '') === 'stop') {
                    $firstFeeder = true;
                    foreach ((array)($d3[$stgW]['gtg'] ?? []) as $sbW) { $sbW = strtolower((string)$sbW);
                        if ($sbW === $uW2) continue;
                        if ($fW > 0 && (float)($genRows[$fW - 1][$sbW] ?? 0) > 0.01) { $firstFeeder = false; break; } }
                    if ($firstFeeder) {
                        $modeW = strtolower((string)($model['stg_startup_mode'][$stgW] ?? ($model['stg_startup_mode'][$stgW . '_startup'] ?? 'warm')));
                        $lenW = max($lenW, count(pp_startup_caps($stgW, $modeW)));
                    }
                }
                /* PATCH A06b — RE-ASSERT SEQUENCE STARTUP SEBELUM DIBEKUKAN.
                 * suWinL hanya MEMBEKUKAN nilai yang ada; bila pass hilir sempat menaikkan langkah
                 * startup (mis. export-floor raise / gas top-up menaikkan G1 ke 31 saat seharusnya
                 * ditahan 20), pembekuan justru mengunci nilai yang salah. Karena KETENTUAN STARTUP
                 * STG/Additional HRSG bersifat WAJIB, tulis ulang nilai caps yang benar lebih dulu,
                 * baru bekukan. Fixed Load & stop window tetap menang (input operator otoritatif). */
                $capsW = pp_start_sequence($d3, $model, $uW2, $genRows, $fW);
                foreach ($capsW as $kW => $cvW) {
                    $rW2 = $fW + $kW;
                    if ($rW2 >= $nRows || isset($actualRows[$rW2])) continue;
                    if (pp_is_unit_stopped($d3, $model, $uW2, $rW2 + 1)) break;
                    if (pp_get_fixed_load($model, $uW2, $rW2 + 1) >= 0) continue;
                    if ((float)($genRows[$rW2][$uW2] ?? 0) < 0.01) break;      // sequence berakhir (unit off)
                    if (abs((float)$genRows[$rW2][$uW2] - (float)$cvW) > 1e-6) {
                        $genRows[$rW2][$uW2] = (float)$cvW;
                        pp_recompute_stgs($genRows[$rW2], $d3, $model, $rW2 + 1);
                    }
                }
                if ($capsW) $lenW = max($lenW, count($capsW));
                for ($rW = $fW; $rW < min($nRows, $fW + $lenW); $rW++) $suWinL[$uW2][$rW] = true;
            }
        }



    /* PATCH I08b: pp_validate_startup dipindah ke AKHIR pipeline (setelah seluruh pass repair) —
     * memvalidasi state tengah menghasilkan warning basi yang dipromosikan validator §9 menjadi
     * violation palsu (kondisi sudah diperbaiki pass hilir). Lihat pemanggilan pasca FIXED RE-ASSERT. */

    /* ---- Biomass Target allocation (PROMPT B4/B8): dihitung dari genRows FINAL ----
     * Active unit-row = setiap row BBLN1/BBLN2 dengan load > 0. Contoh user:
     * Target 480 MWh, BBLN1+BBLN2 running 24 jam = 96 active row -> allocation 5 (MW per row).
     * Sumber input: modeling.babelan_biomass.biomass_target_mwh (fallback legacy biomass_MWh_target).
     * %RE Babelan TIDAK dipakai lagi (B2/B12). Tanpa active row: allocation = 0, coal = 0. */
    $biomassTarget = (float)($model['babelan_biomass']['biomass_target_mwh']
        ?? $model['biomass_MWh_target'] ?? 0);
    $activeBBRows = 0;
    for ($row = 0; $row < $nRows; $row++) {
        if ((float)($genRows[$row]['b1'] ?? 0) > 0) $activeBBRows++;
        if ((float)($genRows[$row]['b2'] ?? 0) > 0) $activeBBRows++;
    }
    $bioAlloc = ($activeBBRows > 0 && $biomassTarget > 0) ? $biomassTarget / $activeBBRows : 0.0;

    /* ===== MIN-RUNTIME EXTENSION (PROMPT 3JUL/7JUL §6/§10) ==================================
     * Lever start (capacity bridge) + pass turn-off yang tidak sadar runtime bisa menghasilkan
     * segmen ON lebih pendek dari minimum runtime (mis. Block 1 rows 37-45 = 9 < 12). Segmen
     * pendek yang berhenti sebelum akhir hari (bukan karena stop-schedule) di-EXTEND ke depan
     * pada level terakhirnya sampai runtime terpenuhi atau akhir hari. Dijalankan SEBELUM final
     * smoothing + refill agar efek export/gas-nya ikut dirapikan. Dibungkus closure dan dipanggil
     * ULANG setelah gas over-trim (Fase 1 bisa menghentikan unit lever, memendekkan run STG — PATCH G21). */
    $ppExtendMinRuntime = function () use (&$genRows, $d3, $model, $nRows, &$actualRows) {
        $limX = pp_runtime_limits($model);
        $lsX = []; foreach (($model['unit_last_data_status'] ?? []) as $kX => $vX) $lsX[strtolower($kX)] = strtolower((string)$vX);
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $uX) {
            if (!isset($d3[$uX])) continue;
            $clsX = pp_runtime_class($uX, $d3);                       // kelas sama persis dgn validator
            $runX = (int)($limX[$clsX]['run_rows'] ?? 8);
            // segmen ON
            $st = -1;
            for ($i = 0; $i <= $nRows; $i++) {
                $on = $i < $nRows && (float)($genRows[$i][$uX] ?? 0) > 0.01;
                if ($on && $st < 0) $st = $i;
                if ((!$on || $i === $nRows - 1) && $st >= 0) {
                    $en = $on ? $i : $i - 1;
                    $len = $en - $st + 1 + (($st === 0 && ($lsX[$uX] ?? '') === 'running') ? $runX : 0);
                    $endsEarly = $en < $nRows - 1 && !pp_is_unit_stopped($d3, $model, $uX, $en + 2);
                    /* PATCH G21 (per-segmen): bila GTG ini feeder satu-satunya yang hidup utk STG blok
                     * SELAMA segmen ini, run STG = run GTG - lag startup (S1/S2: Cold 7/Warm 2/Hot 1;
                     * S3: 11/5/1) — GTG wajib run stgMin + lag agar run STG legal. */
                    $effRunX = $runX;
                    $stgX = pp_gtg_to_stg($d3, $uX);
                    if ($stgX !== '' && $stgX !== null && isset($d3[$stgX])) {
                        $sibsX = array_map('strtolower', array_values($d3[$stgX]['hrsg'] ?? ($d3[$stgX]['gtg'] ?? [])));
                        $soloX = true;
                        foreach ($sibsX as $sbX) { if ($sbX === $uX) continue;
                            for ($q = $st; $q <= $en; $q++) if ((float)($genRows[$q][$sbX] ?? 0) > 0.01) { $soloX = false; break 2; } }
                        if ($soloX && (float)($genRows[$st][$stgX === 's1' ? 'S1' : ($stgX === 's2' ? 'S2' : 'S3')] ?? 0) >= 0
                            && !($st === 0 && ($lsX[$uX] ?? '') === 'running')) {
                            $modeX = strtolower((string)($model['stg_startup_mode'][$stgX] ?? ($model['stg_startup_mode'][$stgX . '_startup'] ?? 'warm')));
                            $lagT = ($stgX === 's3') ? ['cold' => 11, 'warm' => 5, 'hot' => 1] : ['cold' => 7, 'warm' => 2, 'hot' => 1];
                            $effRunX = max($runX, (int)($limX['stg']['run_rows'] ?? 12) + ($lagT[$modeX] ?? 2));
                        }
                    }
                    if ($endsEarly && $len < $effRunX) {
                        $lvl = max((float)$genRows[$en][$uX], (float)($d3[$uX]['min_ccload'] ?? 0));
                        for ($k = $en + 1; $k < $nRows && ($k - $st + 1) <= $effRunX; $k++) {
                            if (isset($actualRows[$k]) || pp_is_unit_stopped($d3, $model, $uX, $k + 1)) break;
                            if ((float)($genRows[$k][$uX] ?? 0) > 0.01) break;      // menyambung segmen berikut
                            $genRows[$k][$uX] = $lvl;
                            pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1);
                        }
                    }
                    $st = -1;
                }
            }
        }
    };
    $ppExtendMinRuntime();
    /* ===== PATCH G05 — POST-EXTENSION BUS REPAIR ============================================
     * Min-runtime extension (hard constraint) dapat menaikkan unit Bus B (mis. G2/G5) sehingga
     * BusFlow = IE - Sigma BusB jatuh di bawah minimum. Perbaiki dgn menaikkan unit Bus A (G8/G9)
     * per row — guard: uRampOk, effective max, export <= ceiling, hold Start-At/Stop/fixed.
     * Prioritas: BusFlow (hard) di atas gas; overshoot gas ditangani over-trim + evidence di bawah.
     * Bila exhausted (G8/G9 terkunci/max) -> evidence row-level utk VALID-INFEASIBLE. */
    {
        $busMinB = (float)($model['busflow_min'] ?? 0);
        if ($busMinB > 0) {
            for ($r = 0; $r < $nRows; $r++) {
                if (isset($actualRows[$r])) continue;
                for ($ib = 0; $ib < 400; $ib++) {
                    $busC = calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]);
                    if ($busC >= $busMinB - 1e-6) break;
                    $did = false;
                    foreach (['g8','g9'] as $uB) {
                        if (pp_is_unit_stopped($d3, $model, $uB, $r + 1)) continue;
                        if (pp_get_fixed_load($model, $uB, $r + 1) >= 0) continue;
                        $gB = (float)($genRows[$r][$uB] ?? 0);
                        if ($gB < 0.01) continue;                                     // hold/off: jangan auto-run
                        if (isset($suWinL[$uB][$r])) continue;                        // PATCH I07: langkah startup EXACT
                        $emB = pp_effective_maxload($d3, $model, $uB, $r + 1);
                        if ($emB <= 0) $emB = (float)($d3[$uB]['max_load'] ?? 108);
                        $nvB = min($emB, $gB + 0.5);
                        if ($nvB <= $gB + 1e-9) continue;
                        $rampOkB = true;                                              // guard ramp 30 lokal (uRampOk terdefinisi belakangan)
                        foreach ([$r-1, $r+1] as $nbB) { if ($nbB < 0 || $nbB >= $nRows) continue;
                            $xB = (float)($genRows[$nbB][$uB] ?? 0);
                            if ($xB >= 1 && abs($nvB - $xB) > 30.0 + 1e-6) { $rampOkB = false; break; } }
                        if (!$rampOkB) continue;
                        $sv = $genRows[$r]; $genRows[$r][$uB] = $nvB;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $peB = $model['pln_export_priority'] ?? []; $hB = (float)($peB['range']['max'] ?? INF);
                        foreach (($peB['range_rules'] ?? []) as $rrB) { $sB2=(int)($rrB['start']??1); $eB2=(int)($rrB['stop']??$nRows);
                            if ($r+1 >= $sB2 && $r+1 <= $eB2 && isset($rrB['max'])) $hB = min($hB, (float)$rrB['max']); }
                        if ($realised_export($genRows[$r], (float)$ieVals[$r])['export'] > $hB + 1e-6) {
                            $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                        $did = true; break;
                    }
                    if (!$did) break;
                }
                $busF = calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]);
                if ($busF < $busMinB - 0.51) {
                    $warnings[] = sprintf('Bus Flow below minimum row %d: BusFlow %.2f < %.2f MW — repair exhausted: G8/G9 (Bus A) di effective max/ramp atau di-hold komitmen Start-At/Stop/Fixed; kenaikan lebih lanjut menembus Export Range Max.', $r + 1, $busF, $busMinB);
                }
            }
        }
    }
    /* ===== PATCH G11/G21 — CANNOT-STOP STG FEEDER PRE-START BRIDGE ==========================
     * STG Continuous/Cannot-Stop wajib hidup sepanjang hari. Bila stop request/window mematikan
     * SEMUA feeder blok pada suatu interval, jembatani dgn feeder alternatif (urutan Unit
     * Priority): PRE-START dgn lead-in (Additional HRSG 5/15 atau 40..70) SEBELUM interval agar
     * STG tidak pernah tanpa pemasok, lalu min-load sepanjang interval (+min-runtime). Feeder
     * yang punya stop window / fixed-0 pada rentang tsb dilewati. Berjalan SETELAH konsolidasi &
     * min-runtime extension sehingga tidak dibatalkan pass lain; ladder/refill/over-trim di bawah
     * hanya menggeser level (tidak mematikan unit). */
    {
        $csBrX = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
        foreach (($model['required_mode'] ?? []) as $uCx => $cCx)
            if (is_array($cCx) && strtolower((string)($cCx['mode'] ?? '')) === 'continuous') $csBrX[] = strtolower((string)$uCx);
        foreach (['s1','s2','s3'] as $sX) {
            if (!isset($d3[$sX]) || !in_array($sX, $csBrX, true)) continue;
            $feedX = array_map('strtolower', array_values($d3[$sX]['hrsg'] ?? ($d3[$sX]['gtg'] ?? [])));
            if (!$feedX) continue;
            $sLastRun = strtolower((string)($model['unit_last_data_status'][strtoupper($sX)] ?? '')) === 'running';
            /* interval semua-feeder-off */
            $offSeg = []; $a = -1;
            $sSeen = $sLastRun;                                              // STG belum start hari ini? interval awal sah kosong
            for ($r = 0; $r < $nRows; $r++) {
                $any = false; foreach ($feedX as $fX) if ((float)($genRows[$r][$fX] ?? 0) > 0.01) { $any = true; break; }
                if ($any) { $sSeen = true; if ($a >= 0) { $offSeg[] = [$a, $r]; $a = -1; } }
                else { if ($a < 0 && $sSeen) $a = $r; }
            }
            if ($a >= 0) $offSeg[] = [$a, $nRows];
            if (!$offSeg) continue;
            $ordX = [];
            foreach ([pp_priority_flat($model)] as $grpX) foreach ((array)$grpX as $cnd) { $cnd = strtolower((string)$cnd);
                if (in_array($cnd, $feedX, true) && isset($d3[$cnd]) && pp_unit_present($d3, $cnd) && !in_array($cnd, $ordX, true)) $ordX[] = $cnd; }
            foreach ($feedX as $cnd) if (!in_array($cnd, $ordX, true) && isset($d3[$cnd]) && pp_unit_present($d3, $cnd)) $ordX[] = $cnd;
            foreach ($offSeg as [$aX, $bX]) {
                foreach ($ordX as $fX) {
                    $seqX = pp_start_sequence($d3, $model, $fX, $genRows, max(0, $rX ?? 0));   // PATCH A06
                    $prevOnX = $aX > 0 && (float)($genRows[$aX-1][$fX] ?? 0) > 0.01;
                    $lead = $prevOnX ? 0 : count($seqX);
                    $st = max(0, $aX - $lead);
                    $limAll = pp_runtime_limits($model); $clsX = pp_runtime_class($fX, $d3);
                    $minRunX = (int)($limAll[$clsX]['run_rows'] ?? 2);
                    $en = min($nRows - 1, max($bX - 1, $st + $minRunX - 1));
                    $ok = true;
                    for ($r = $st; $r <= $en; $r++) {
                        if (isset($actualRows[$r])) { $ok = false; break; }
                        if (pp_is_unit_stopped($d3, $model, $fX, $r + 1)) { $ok = false; break; }
                        $fvX = pp_get_fixed_load($model, $fX, $r + 1);
                        if ($fvX >= 0 && $fvX <= 0.0) { $ok = false; break; }        // fixed 0 = off
                    }
                    if (!$ok) continue;
                    $mnX = (float)($d3[$fX]['min_ccload'] ?? ($d3[$fX]['min_scload'] ?? 5));
                    $k = 0;
                    for ($r = $st; $r <= $en; $r++) {
                        $cur = (float)($genRows[$r][$fX] ?? 0);
                        $freshHere = ($r === $st) ? !$prevOnX : ((float)($genRows[$r-1][$fX] ?? 0) <= 0.01 ? false : false);
                        $val = (!$prevOnX && $k < count($seqX)) ? $seqX[$k] : max($cur, $mnX);
                        $fvX = pp_get_fixed_load($model, $fX, $r + 1);
                        $genRows[$r][$fX] = ($fvX >= 0) ? $fvX : $val;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $k++;
                    }
                    $warnings[] = sprintf('Cannot-Stop STG bridge: %s di-pre-start row %d (lead-in %d row) dan dijaga s/d row %d agar %s tidak kehilangan pemasok pada interval feeder-off rows %d-%d (stop request/window feeder utama).', strtoupper($fX), $st + 1, $lead, $en + 1, strtoupper($sX), $aX + 1, $bX);
                    break;
                }
            }
        }
    }

    /* ===== PATCH J03/J04 — REQUIRED START (Change Over / required_units) =====================
     * Unit di required_units WAJIB start minimal sekali. Utk Change Over: blok pengganti harus
     * loaded SEBELUM blok lama berhenti (start-before-stop): target start = stop_row blok lain -
     * panjang lead-in; selain itu row feasible pertama. Lead-in per unit; lalu min-load s/d akhir
     * hari (blok pengganti memikul beban) kecuali stop window. */
    {
        $ruJ = array_map('strtolower', (array)($model['required_units'] ?? []));
        $coNB = (array)($model['co_norm']['blocks'] ?? []);
        foreach ($ruJ as $uRq) {
            if (!isset($d3[$uRq])) continue;
            if (!empty($model['co_no_anchor'])) continue;                       // tanpa anchor: infeasible ber-evidence
            /* GTG blok pengganti Change Over (stopped block + start_other): setelah handover blok ini
             * ANCHOR — wajib on terus s/d akhir hari (kecuali stop window-nya sendiri). Isi gap. */
            $isCoG = false;
            foreach ($coNB as $entC2) if (strtolower((string)($entC2['gtg'] ?? '')) === $uRq
                && strtolower((string)($entC2['last_status'] ?? '')) === 'stop') { $isCoG = true; break; }
            $fOn = -1; for ($r = 0; $r < $nRows; $r++) if ((float)($genRows[$r][$uRq] ?? 0) > 0.01) { $fOn = $r; break; }
            if ($fOn >= 0 && $isCoG) {
                $mnC2 = (float)($d3[$uRq]['min_ccload'] ?? ($d3[$uRq]['min_scload'] ?? 5));
                for ($r = $fOn; $r < $nRows; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if (pp_is_unit_stopped($d3, $model, $uRq, $r + 1)) break;
                    if ((float)($genRows[$r][$uRq] ?? 0) > 0.01) continue;
                    $fvC2 = pp_get_fixed_load($model, $uRq, $r + 1);
                    $genRows[$r][$uRq] = ($fvC2 >= 0) ? $fvC2 : $mnC2;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
                continue;
            }
            if ($fOn >= 0) continue;                                            // non-co: cukup start sekali
            /* PATCH A06: Change Over / required start ke blok yang STG-nya masih 0 MW WAJIB memakai
             * STG Startup sequence, bukan Additional HRSG (KETENTUAN STARTUP STG DAN ADDITIONAL HRSG). */
            /* FIX USE-BEFORE-INIT: $tgtQ dipakai di baris berikut TETAPI baru di-set sesudahnya.
             * Karena berada di dalam loop, iterasi ke-2 dan seterusnya memakai nilai SISA dari
             * iterasi sebelumnya (dan iterasi pertama memicu undefined-variable warning).
             * Sequence provisional memang dihitung dari row 0 — dinyatakan eksplisit di sini. */
            $tgtQ = -1;
            $seqQ = pp_start_sequence($d3, $model, $uRq, $genRows, max(0, $tgtQ));
            /* target start-before-stop dari blok lain (co) */
            foreach ($coNB as $bnQ => $entQ) {
                if (strtolower((string)($entQ['gtg'] ?? '')) === $uRq) {
                    $othQ = $coNB[$bnQ === '1' ? '2' : '1'] ?? [];
                    if (!empty($othQ['stop_row'])) $tgtQ = max(0, (int)$othQ['stop_row'] - 1 - count($seqQ));
                }
            }
            if ($tgtQ < 0) { for ($r = 0; $r < $nRows; $r++) if (!pp_is_unit_stopped($d3, $model, $uRq, $r + 1)) { $tgtQ = $r; break; } }
            if ($tgtQ < 0) continue;
            while ($tgtQ < $nRows && pp_is_unit_stopped($d3, $model, $uRq, $tgtQ + 1)) $tgtQ++;   // geser keluar hold
            $mnQ = (float)($d3[$uRq]['min_ccload'] ?? ($d3[$uRq]['min_scload'] ?? 5));
            $kQ2 = 0;
            for ($r = $tgtQ; $r < $nRows; $r++) {
                if (isset($actualRows[$r])) continue;
                if (pp_is_unit_stopped($d3, $model, $uRq, $r + 1)) break;
                $fvQ = pp_get_fixed_load($model, $uRq, $r + 1);
                $valQ = ($kQ2 < count($seqQ)) ? $seqQ[$kQ2] : $mnQ;
                $genRows[$r][$uRq] = ($fvQ >= 0) ? $fvQ : max((float)($genRows[$r][$uRq] ?? 0), $valQ);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $kQ2++;
            }
            $warnings[] = sprintf('Required start enforced: %s dinyalakan row %d (lead-in %d row) — required_units/Change Over start-before-stop.', strtoupper($uRq), $tgtQ + 1, count($seqQ));
        }
    }


    /* ===== PATCH E17 — MM2100 QUOTA REBALANCE (pasca min-runtime extension) =================
     * Min-runtime extension dapat MEMPERPANJANG run GE (menambah gas MM) melebihi quota KP72.
     * Rebalance: turunkan LEVEL GE (reverse priority, row tertinggi dulu, 0.5 MW step) tanpa
     * mematikan unit (runtime terjaga), skip fixed/actual/stop, guard export >= floor ladder &
     * busflow. Residual over -> evidence 'MM2100 quota exceeded' (materialize) => VALID-INFEASIBLE. */
    {
        $qMk = 0.0; foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $kQ) $qMk += (float)($model['gas_quota'][$kQ] ?? 0);
        if ($qMk > 1e-9) {
            $geSetR = ['ge1','ge2','ge3','ge4','g10'];
            /* HARD MAXIMUM QUOTA MM2100 — metrik enforcer HARUS identik dengan metrik validator.
             * Validator memakai MM2100 Used + Startup = jumlah Est_FF_M per row, dan Est_FF_M
             * adalah round(Gas_MM2100 / 2, 5). Sebelumnya enforcer memakai jumlah calc_fuel/2
             * TANPA pembulatan per row, sehingga akumulasi 48 pembulatan membuat nilai yang
             * dilaporkan menembus kuota (mis. enforcer 2,99993 lolos tetapi validator 3,00010
             * melanggar). Pembulatan per row direplikasi di sini agar dispatch benar-benar
             * ditekan sampai metrik yang DIVALIDASI <= kuota — bukan sekadar rapi di display. */
            $mmEst = function () use (&$genRows, $d3, $nRows, $geSetR): float {
                $s = 0.0; for ($r = 0; $r < $nRows; $r++) { $rowGas = 0.0;
                    foreach ($geSetR as $u)
                        if (isset($d3[$u]) && (float)($genRows[$r][$u] ?? 0) > 0.01) $rowGas += calc_fuel($d3, $u, (float)$genRows[$r][$u]);
                    $s += round($rowGas / 2.0, 5); }
                return $s; };
            $g10s = 0; for ($k2 = 1; $k2 < $nRows; $k2++) if (($genRows[$k2-1]['g10'] ?? 0) < 1 && ($genRows[$k2]['g10'] ?? 0) >= 1) $g10s++;
            $mmCapR = $qMk - $g10s * 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
            $revGe = array_reverse(pp_priority_flat($model, '/^(ge[1-4]|g10)$/') ?: ['ge1','ge2','ge3','ge4','g10']);
            for ($itM = 0; $itM < 5000 && $mmEst() > $mmCapR + 1e-9; $itM++) {
                $didM = false;
                foreach ($revGe as $uM) {
                    if (!isset($d3[$uM])) continue;
                    $flM = (float)($d3[$uM]['min_ccload'] ?? ($d3[$uM]['min_scload'] ?? 0.5));
                    /* row ON dgn load tertinggi */
                    $bR = -1; $bL = 0.0;
                    for ($r = 0; $r < $nRows; $r++) {
                        if (isset($actualRows[$r]) || pp_get_fixed_load($model, $uM, $r + 1) >= 0) continue;
                        if (pp_is_unit_stopped($d3, $model, $uM, $r + 1)) continue;
                        $cl = (float)($genRows[$r][$uM] ?? 0);
                        if ($cl > $flM + 0.05 && $cl > $bL) { $bL = $cl; $bR = $r; }
                    }
                    if ($bR < 0) continue;
                    $nvM = max($flM, $bL - 0.5);
                    $skM = pp_get_skip_load($model, $uM, $bR + 1);
                    if ($skM && $nvM >= $skM[0] - 0.55 && $nvM <= $skM[1] + 0.55) $nvM = max($flM, $skM[0] - 0.6);
                    if ($nvM >= $bL - 1e-9) continue;
                    $svM = $genRows[$bR]; $genRows[$bR][$uM] = $nvM;
                    $eM2 = $realised_export($genRows[$bR], (float)$ieVals[$bR])['export'];
                    $busM2 = calc_busflow($genRows[$bR], $busUnit, (float)$ieVals[$bR]);
                    $peM = $model['pln_export_priority'] ?? []; $rMinM = (float)($peM['range']['min'] ?? 0);
                    foreach (($peM['range_rules'] ?? []) as $rrM) { $sM=(int)($rrM['start']??1); $eMx=(int)($rrM['stop']??$nRows);
                        if ($bR+1 >= $sM && $bR+1 <= $eMx) $rMinM = max($rMinM, (float)($rrM['min'] ?? 0)); }
                    if ($eM2 < $rMinM - 1e-6 || $busM2 < ((float)($model['busflow_min'] ?? 0)) - 1e-6) {
                        $genRows[$bR] = $svM; continue; }
                    $didM = true; break;
                }
                if ($didM) continue;
                /* HARD MAXIMUM: reduksi load buntu (seluruh unit di min-load) namun usage MASIH
                 * di atas kuota. Kuota MM2100 adalah batas keras, jadi optimizer WAJIB menurunkan
                 * pemakaian — bukan menyerah. Tahap kedua: matikan penuh satu row pada unit MM2100
                 * ber-priority TERENDAH (urutan $revGe), dimulai dari row dengan load terkecil agar
                 * kehilangan produksi minimal. Hard constraint tetap dijaga: bila mematikan row
                 * melanggar Export Range Min atau Bus Flow, row dikembalikan dan kandidat berikutnya
                 * dicoba. Bila seluruh kandidat ditolak constraint, barulah loop berhenti dan
                 * validator melaporkan over-quota secara jujur. */
                foreach ($revGe as $uM) {
                    if (!isset($d3[$uM])) continue;
                    $bR = -1; $bL = INF;
                    for ($r = 0; $r < $nRows; $r++) {
                        if (isset($actualRows[$r]) || pp_get_fixed_load($model, $uM, $r + 1) >= 0) continue;
                        if (pp_is_unit_stopped($d3, $model, $uM, $r + 1)) continue;
                        $cl = (float)($genRows[$r][$uM] ?? 0);
                        if ($cl > 0.01 && $cl < $bL) { $bL = $cl; $bR = $r; }
                    }
                    if ($bR < 0) continue;
                    $svM = $genRows[$bR]; $genRows[$bR][$uM] = 0.0;
                    $eM2  = $realised_export($genRows[$bR], (float)$ieVals[$bR])['export'];
                    $busM2 = calc_busflow($genRows[$bR], $busUnit, (float)$ieVals[$bR]);
                    $peM = $model['pln_export_priority'] ?? []; $rMinM = (float)($peM['range']['min'] ?? 0);
                    foreach (($peM['range_rules'] ?? []) as $rrM) { $sM = (int)($rrM['start'] ?? 1); $eMx = (int)($rrM['stop'] ?? $nRows);
                        if ($bR + 1 >= $sM && $bR + 1 <= $eMx) $rMinM = max($rMinM, (float)($rrM['min'] ?? 0)); }
                    if ($eM2 < $rMinM - 1e-6 || $busM2 < ((float)($model['busflow_min'] ?? 0)) - 1e-6) {
                        $genRows[$bR] = $svM; continue; }
                    $didM = true; break;
                }
                if (!$didM) break;
            }
        }
    }

    /* ===== FINAL EXPORT-RAMP LADDER SMOOTHING (PROMPT 3JUL/7JUL §3.1/§9) ====================
     * Dijalankan SETELAH seluruh mutasi dispatch (gas targeting, gas top-up, lever start,
     * babelan, dst.) dan SEBELUM materialize — menjamin |ΔExport| <= 30 MW/30min sbg hard
     * constraint horizon penuh. Floor per-row = range + range_rules + PROPAGASI TANGGA
     * (floor[r] >= floor tetangga - 30, clamp <= max row) — dgn properti ini, menurunkan sisi
     * tinggi ke (low + 30) TIDAK PERNAH menembus floor-nya sendiri => konvergen terbukti.
     * Presisi: level G8=G9 dicari via BISECTION kontinu (bukan step 0.5) sehingga tepat
     * mendarat <= low+30 (menghilangkan overshoot marginal 30.1-30.3 dari step diskrit).
     * Row actual/locked & row dgn G8/G9 manual-fixed dilewati (nilai operator otoritatif). */
    {
        $peL   = $model['pln_export_priority'] ?? [];
        $rMinL = (float)(($peL['range']['min'] ?? 0));
        $rMaxL = (float)(($peL['range']['max'] ?? INF));
        $fL = array_fill(0, $nRows, $rMinL); $hL = array_fill(0, $nRows, $rMaxL);
        foreach (($peL['range_rules'] ?? []) as $rrL) {
            if (!is_array($rrL)) continue;
            $sL = max(1, (int)($rrL['start'] ?? 1)); $eL = min($nRows, (int)($rrL['stop'] ?? $nRows));
            for ($r = $sL - 1; $r <= $eL - 1; $r++) { $fL[$r] = max($rMinL, (float)($rrL['min'] ?? $rMinL)); $hL[$r] = min($rMaxL, (float)($rrL['max'] ?? $rMaxL)); }
        }
        /* PROMPT 3JUL/7JUL T10 — seed tangga dari export ACTUAL (locked) di boundary */
        foreach ($actualRows as $riW => $arW) {
            $evW = isset($arW['export']) && is_numeric($arW['export']) ? (float)$arW['export'] : null;
            if ($evW === null) continue;
            foreach ([$riW - 1, $riW + 1] as $nbW) {
                if ($nbW < 0 || $nbW >= $nRows || isset($actualRows[$nbW])) continue;
                $fL[$nbW] = max($fL[$nbW], min($evW - 30.0, $hL[$nbW]));
                $hL[$nbW] = min($hL[$nbW], max($evW + 30.0, $fL[$nbW]));
            }
        }
        for ($it = 0; $it < $nRows; $it++) { $chF = false;
            for ($r = 0; $r < $nRows; $r++) { $f = $fL[$r];
                if ($r > 0)          $f = max($f, $fL[$r - 1] - 30.0);
                if ($r < $nRows - 1) $f = max($f, $fL[$r + 1] - 30.0);
                $f = min($f, $hL[$r]);
                if ($f > $fL[$r] + 1e-9) { $fL[$r] = $f; $chF = true; } }
            if (!$chF) break; }
        $g8fixL = []; foreach ((array)($model['unit_fix_load']['g8'] ?? []) as $ru) for ($r = max(1,(int)($ru['start']??1)); $r <= min($nRows,(int)($ru['stop']??$nRows)); $r++) $g8fixL[$r-1] = true;
        foreach ((array)($model['unit_fix_load']['g9'] ?? []) as $ru) for ($r = max(1,(int)($ru['start']??1)); $r <= min($nRows,(int)($ru['stop']??$nRows)); $r++) $g8fixL[$r-1] = true;
        $expAtL = function (int $r) use (&$genRows, $realised_export, $ieVals): float {
            return (float)$realised_export($genRows[$r], (float)$ieVals[$r])['export']; };
        $gMinL = (float)($d3['g8']['min_ccload'] ?? 65);
        for ($passL = 0; $passL < 120; $passL++) {
            $chL = false;
            for ($r = 1; $r < $nRows; $r++) {
                if (isset($actualRows[$r]) && isset($actualRows[$r - 1])) continue;   // keduanya locked: konstanta murni
                $eA = $expAtL($r - 1); $eB = $expAtL($r);
                $d = $eB - $eA;
                if (abs($d) <= 30.0 + 5e-7) continue;
                $hiR = $d > 0 ? $r : $r - 1; $loE = min($eA, $eB);
                if (isset($actualRows[$hiR])) continue;                    // sisi tinggi LOCKED: sisi future dijamin floor seed (fL >= locked-30)
                if (isset($g8fixL[$hiR])) continue;                        // operator fix otoritatif
                $tgt = max($loE + 30.0, $fL[$hiR]);                        // ladder: tgt >= floor terjamin
                if ($expAtL($hiR) <= $tgt + 5e-7) continue;
                /* lever = unit pasangan yang MASIH HIDUP di row ini (G8 stopped -> G9 saja);
                 * tanpa ini smoothing mati total di rows pasca scheduled-stop (row 45 kasus T10). */
                $lvU = [];
                foreach (['g8', 'g9'] as $puL)
                    if (!pp_is_unit_stopped($d3, $model, $puL, $hiR + 1) && (float)($genRows[$hiR][$puL] ?? 0) > $gMinL + 1e-9) $lvU[] = $puL;
                if (!$lvU) continue;                                       // lever habis
                $suHitL = false; foreach ($lvU as $puL) if (isset($suWinL[$puL][$hiR])) { $suHitL = true; break; }
                if ($suHitL) continue;                                             // PATCH H08: row startup EXACT
                $sn0 = []; foreach ($lvU as $puL) $sn0[$puL] = (float)$genRows[$hiR][$puL];
                /* PATCH B03 (unit-ramp guard): lantai per-lever = max(minCC, tetangga-30) — bisection tidak
                 * boleh menjatuhkan G8/G9 melebihi ramp 30 thd row sebelah. Ladder loop (120 pass) tetap
                 * konvergen: tetangga ikut turun di iterasi berikutnya sehingga lantai melonggar bertahap. */
                $rflL = [];
                foreach ($lvU as $puL) { $rfV = $gMinL;
                    foreach ([$hiR - 1, $hiR + 1] as $nbL) { if ($nbL < 0 || $nbL >= $nRows) continue;
                        $nvL = (float)($genRows[$nbL][$puL] ?? 0); if ($nvL >= 1) $rfV = max($rfV, $nvL - 30.0); }
                    $rflL[$puL] = max($rfV, $gMinL); }   // PATCH I08: lantai min-CC
                $gHi0 = max($sn0);
                $loG = $gMinL; $hiG = $gHi0; $best = null;
                for ($bs = 0; $bs < 40; $bs++) {                           // bisection level lever -> export == tgt
                    $mid = ($loG + $hiG) / 2;
                    foreach ($lvU as $puL) $genRows[$hiR][$puL] = min($sn0[$puL], max($mid, $rflL[$puL]));
                    pp_recompute_stgs($genRows[$hiR], $d3, $model, $hiR + 1);
                    $eM = $expAtL($hiR);
                    if ($eM > $tgt) $hiG = $mid; else { $loG = $mid; $best = $mid; }
                    if (abs($eM - $tgt) < 1e-4) { $best = $mid; break; }
                }
                $fin = $best ?? $hiG;
                foreach ($lvU as $puL) $genRows[$hiR][$puL] = min($sn0[$puL], max($fin, $rflL[$puL]));
                pp_recompute_stgs($genRows[$hiR], $d3, $model, $hiR + 1);
                $busL = calc_busflow($genRows[$hiR], $busUnit, (float)$ieVals[$hiR]);
                if ($busL < ((float)($model['busflow_min'] ?? 0)) - 1e-6
                    || $expAtL($hiR) < $fL[$hiR] - 1e-6) {                 // guard: busflow / floor
                    foreach ($lvU as $puL) $genRows[$hiR][$puL] = $sn0[$puL];
                    pp_recompute_stgs($genRows[$hiR], $d3, $model, $hiR + 1);
                    continue;
                }
                $chL = true;
            }
            if (!$chL) break;
        }

        /* POST-SMOOTHING GAS REFILL (ramp-aware): smoothing di atas menurunkan G8/G9 di tepi
         * window -> gas bisa turun di bawah [quota-0.04, quota]. Isi ulang dgn merit-order
         * priority pada row yang masih punya headroom, dgn guard MUTUAL: export baru wajib
         * <= min(export tetangga kiri & kanan) + 30 dan <= effHi row — sehingga refill TIDAK
         * PERNAH menciptakan pelanggaran ramp/range baru. Konvergen: tetangga ikut naik pada
         * giliran berikutnya (tangga tumbuh bertahap). */
        /* target refill = KUOTA EFEKTIF yang dipakai validator: quota + Additional LNG bila
         * gas_shortage_action = add_lng (mismatch inilah yang membuat refill idle di skenario
         * dgn additional LNG aktif). */
        /* PATCH B03 (unit-ramp guard sistemik): refill/over-trim per-1MW tidak boleh menciptakan
         * pelanggaran ramp unit (G8/G9<=30, G7/G10<=55, BB<=5) thd tetangga current. */
        $uLimW = ['g7'=>55.0,'g10'=>55.0,'g8'=>30.0,'g9'=>30.0,'b1'=>pp_babelan_ramp_limit($model),'b2'=>pp_babelan_ramp_limit($model)];
        $uRampOk = function (string $u, int $r, float $nv) use (&$genRows, $nRows, $uLimW): bool {
            if (!isset($uLimW[$u])) return true;
            foreach ([$r-1, $r+1] as $nb) { if ($nb < 0 || $nb >= $nRows) continue;
                $x = (float)($genRows[$nb][$u] ?? 0);
                if ($x >= 1 && $nv >= 1 && abs($nv - $x) > $uLimW[$u] + 1e-6) return false; }
            return true; };
        $actL=strtolower(trim((string)($model['gas_shortage_action']??'none')));if($actL==='flag shortage only'||$actL==='flag_shortage_only')$actL='none';elseif($actL==='add lng to cover'||$actL==='add_lng_to_cover')$actL='add_lng';elseif($actL==='use distillate to cover'||$actL==='use_distillate_to_cover')$actL='use_distillate';
        $effQL  = $gasQuotaTotal + (in_array($actL, ['add_lng','mixed_lng_distillate'], true) ? (float)($model['additional_lng'] ?? 0) : 0.0);
        $gasNowL = $gasTotalOf($genRows);
        $winLoL  = 0.0;
        $gasCapL = $effQL - $penOf($genRows);
        for ($rf = 0; $rf < 800 && $gasNowL < $winLoL; $rf++) {
            $progL = false;
            for ($r = 0; $r < $nRows && $gasNowL < $winLoL; $r++) {
                if (isset($actualRows[$r]) || isset($g8fixL[$r])) continue;
                $capL = min($effHiArr[$r], $rMaxGas, $hL[$r]);
                if ($r > 0)          $capL = min($capL, $expAtL($r - 1) + 30.0);
                if ($r < $nRows - 1) $capL = min($capL, $expAtL($r + 1) + 30.0);
                if ($expAtL($r) >= $capL - 1e-6) continue;
                foreach ($upOrderGas as $u) {
                    if (!isset($d3[$u]) || ($genRows[$r][$u] ?? 0) <= 0) continue;
                    if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
                    if (($genRows[$r][$u] ?? 0) < (float)($d3[$u]['min_ccload'] ?? 0) - 1e-6) continue;   // lead-in startup: jangan diganggu
                    $maxU = pp_effective_maxload($d3, $model, $u, $r + 1);   // PATCH G29-G31: effective max per row
                    if (($genRows[$r][$u] ?? 0) >= $maxU - 1e-6) continue;
                    $g0 = (float)$genRows[$r][$u]; $rg0 = $rowGas($genRows[$r]);
                    if (isset($suWinL[$u][$r])) continue;                        // PATCH H08
                    if (!$uRampOk($u, $r, min($maxU, $g0 + 1.0))) continue;   // PATCH B03
                    $genRows[$r][$u] = min($maxU, $g0 + 1.0);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $rg1 = $rowGas($genRows[$r]);
                    if ($expAtL($r) > $capL + 1e-6 || ($gasNowL - $rg0 + $rg1) > $gasCapL + 1e-9) {
                        $genRows[$r][$u] = $g0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        continue;
                    }
                    $gasNowL = $gasNowL - $rg0 + $rg1; $progL = true; break;
                }
            }
            if (!$progL) break;
        }
        /* ===== GAS OVER-TRIM (PROMPT 3JUL/7JUL §10 + Monitoring budget) =====================
         * Bila total gas MELEBIHI kuota efektif (mis. Monitoring: rows locked pagi sudah memakan
         * budget besar), turunkan dispatch dgn urutan MERIT TERBALIK:
         *  Fase 1 — matikan ATOMIK segmen unit blok NON-REQUIRED (per segmen: semua row atau
         *           tidak sama sekali, agar tidak lahir short-run), asal setiap row tetap
         *           >= floor tangga, ramp mutual aman, dan busflow valid;
         *  Fase 2 — turunkan level unit ON (reverse priority) per 1 MW dgn guard yang sama.
         * Jika setelah semua lever gas masih over -> genuinely infeasible (dilaporkan validator
         * dgn evidence, tidak disembunyikan). */
        $gasNowL = $gasTotalOf($genRows);
        if ($gasNowL > $gasCapL + 1e-9) {
            $blkReqT = [];
            foreach ((array)($model['block_priority'] ?? []) as $blkT) {
                $isReqT = in_array('required', array_map('strtolower', array_map('strval', (array)$blkT)), true);
                foreach ((array)$blkT as $uu) { $uu = strtolower((string)$uu); if ($uu !== 'required') $blkReqT[$uu] = $isReqT; }
            }
            $contT = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
            /* PATCH RC-7 (CSV 9JULY §3.2/§3.4/§5, SISTEMIK): keanggotaan blok fisik utk cek
             * "blok tetap committed". Marker 'required' pada block priority = blok WAJIB committed
             * (>=1 unit jalan), BUKAN "setiap unit di blok haram dimatikan". Tanpa ini, saat Block 1
             * & Block 2 sama-sama Required, TIDAK ADA GTG yang boleh di-decommit sehingga over-trim
             * terpaksa memangkas level G8/G9 (Priority 2) dan membiarkan G2/G5 (Priority 3) di
             * min-load — merit order terbalik + banyak unit low-load. */
            $blkMembersT = [];
            foreach ((array)($model['block_priority'] ?? []) as $blkT2) {
                $memT = [];
                foreach ((array)$blkT2 as $uu) { $uu = strtolower((string)$uu);
                    if ($uu !== 'required' && preg_match('/^(g|b|ge)\d+$/', $uu) && isset($d3[$uu])) $memT[] = $uu; }
                foreach ($memT as $uu) $blkMembersT[$uu] = $memT;
            }
            $rampOK = function (int $r) use (&$genRows, $expAtL, $nRows): bool {
                $e = $expAtL($r);
                if ($r > 0          && abs($e - $expAtL($r - 1)) > 30.0 + 5e-7) return false;
                if ($r < $nRows - 1 && abs($e - $expAtL($r + 1)) > 30.0 + 5e-7) return false;
                return true;
            };
            $downOrder = array_reverse($upOrderGas);
            /* Fase 1: shutdown segmen unit non-required (reverse priority) */
            foreach ($downOrder as $cu) {
                if ($gasNowL <= $gasCapL + 1e-9) break;
                if (!isset($d3[$cu]) || in_array($cu, $contT, true)) continue;
                $rmT = strtolower((string)($model['required_mode'][$cu]['mode'] ?? ''));
                if (in_array($rmT, ['continuous', 'start_at'], true)) continue;   // permintaan operator otoritatif
                if (in_array($cu, array_map('strtolower', (array)($model['required_units'] ?? [])), true)) continue;   // PATCH J03: required (Change Over) wajib run
                /* WEEKLY ITEM-1: unit yang membawa sisa kewajiban minimum runtime lintas hari boleh
                 * di-shutdown HANYA pada row DI LUAR carry window (row >= carry). Row 1..carry adalah
                 * kewajiban keras (anti start-stop lintas tengah malam); setelah itu unit kembali
                 * decommit-eligible sehingga gas dapat diturunkan secara normal. */
                $carryRowsT = (int)(($model['carry_min_runtime_rows'] ?? [])[$cu] ?? 0);
                $onT = [];
                for ($r = 0; $r < $nRows; $r++)
                    if (!isset($actualRows[$r]) && (float)($genRows[$r][$cu] ?? 0) > 0.01 && $r >= $carryRowsT) $onT[] = $r;
                if (!$onT) continue;
                /* PATCH RC-7: unit di blok Required boleh di-decommit HANYA bila pada SETIAP row yang
                 * dimatikan masih ada saudara se-blok yang berbeban (blok tetap committed). */
                if ($blkReqT[$cu] ?? true) {
                    $sibOkT = true;
                    foreach ($onT as $r) {
                        $anySibT = false;
                        foreach (($blkMembersT[$cu] ?? []) as $sbT) {
                            if ($sbT === $cu) continue;
                            if ((float)($genRows[$r][$sbT] ?? 0) > 0.01) { $anySibT = true; break; }
                        }
                        if (!$anySibT) { $sibOkT = false; break; }
                    }
                    if (!$sibOkT) continue;                       // blok akan kehilangan komitmen -> pertahankan
                }
                $snapT = []; foreach ($onT as $r) $snapT[$r] = $genRows[$r];
                $gasBefore = $gasNowL;
                foreach ($onT as $r) {
                    $rg0 = $rowGas($genRows[$r]);
                    $genRows[$r][$cu] = 0.0;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $gasNowL += $rowGas($genRows[$r]) - $rg0;
                }
                /* KOMPENSASI merit-order (PROMPT A/E): defisit akibat shutdown ditutup dgn
                 * menaikkan unit priority LEBIH TINGGI yang masih hidup — G9/G8 dulu (skip yang
                 * scheduled-stop/fixed), lalu anggota blok required yang ON — iteratif +1 MW
                 * per row per pass dgn guard unit-ramp <= 30 vs tetangga, sehingga tangga G9
                 * tumbuh legal (bug unit_ramp percobaan sebelumnya tidak terulang). */
                $compU = [];
                foreach ($upOrderGas as $puC) {
                    if ($puC === $cu || !isset($d3[$puC])) continue;
                    if (($blkReqT[$puC] ?? false) || in_array($puC, ['g8', 'g9'], true)) $compU[] = $puC;
                }
                /* Babelan sbg lever kompensasi TERAKHIR (PROMPT C): menaikkan PLTU tidak
                 * menambah gas (coal/biomass); ramp Babelan <= 5 MW/30min per unit dijaga. */
                foreach (['b1', 'b2'] as $puC) if (isset($d3[$puC])) $compU[] = $puC;
                /* jangkauan kompensasi diperluas +-2 row di sekitar segmen: guard unit-ramp
                 * membutuhkan tetangga (di luar segmen) ikut membentuk tangga G9. */
                $compRows = $onT;
                $eS = min($onT); $eE = max($onT);
                foreach ([$eS - 4, $eS - 3, $eS - 2, $eS - 1, $eE + 1, $eE + 2, $eE + 3, $eE + 4] as $rX)
                    if ($rX >= 0 && $rX < $nRows && !isset($actualRows[$rX])) $compRows[] = $rX;
                $compRows = array_values(array_unique($compRows)); sort($compRows);
                foreach ($compRows as $rX) if (!isset($snapT[$rX])) $snapT[$rX] = $genRows[$rX];
                for ($cp = 0; $cp < 200; $cp++) {
                    $needC = false; $movedC = false;
                    foreach ($compRows as $r) {
                        if ($expAtL($r) >= $fL[$r] - 1e-3 && $rampOK($r)) continue;
                        $needC = true;
                        foreach ($compU as $puC) {
                            if (pp_is_unit_stopped($d3, $model, $puC, $r + 1)) continue;
                            if (in_array($puC, ['g8','g9'], true) && isset($g8fixL[$r])) continue;
                            $gC = (float)($genRows[$r][$puC] ?? 0);
                            $mxC = pp_effective_maxload($d3, $model, $puC, $r + 1);   // PATCH G29-G31: effective max per row
                            if ($gC <= 0.01 || $gC >= $mxC - 1e-6) continue;
                            if ($gC < (float)($d3[$puC]['min_ccload'] ?? 0) - 1e-6) continue;   // sedang lead-in startup: jangan diganggu
                            $rampU = in_array($puC, ['b1', 'b2'], true) ? pp_babelan_ramp_limit($model) : 30.0;
                            $nbHi = $mxC;                                   // guard unit-ramp vs tetangga current
                            if ($r > 0)          $nbHi = min($nbHi, (float)($genRows[$r-1][$puC] ?? 0) > 0.01 ? (float)$genRows[$r-1][$puC] + $rampU : $nbHi);
                            if ($r < $nRows - 1) $nbHi = min($nbHi, (float)($genRows[$r+1][$puC] ?? 0) > 0.01 ? (float)$genRows[$r+1][$puC] + $rampU : $nbHi);
                            if ($gC >= $nbHi - 1e-6) {
                                /* PRE-LIFT tetangga pembatas: tangga unit hanya bisa tumbuh bila
                                 * tetangga (yang sehat) ikut dinaikkan — guard ramp/hL/bus penuh. */
                                foreach ([$r - 1, $r + 1] as $rrP) {
                                    if ($rrP < 0 || $rrP >= $nRows || isset($actualRows[$rrP])) continue;
                                    if (in_array($puC, ['g8', 'g9'], true) && isset($g8fixL[$rrP])) continue;
                                    if (pp_is_unit_stopped($d3, $model, $puC, $rrP + 1)) continue;
                                    $gN = (float)($genRows[$rrP][$puC] ?? 0);
                                    if ($gN <= 0.01 || $gN < (float)($d3[$puC]['min_ccload'] ?? 0) - 1e-6 || $gN >= $mxC - 1e-6) continue;
                                    if ($gC < $gN + $rampU - 1e-6) continue;        // bukan pembatas
                                    $rgN = $rowGas($genRows[$rrP]);
                                    $genRows[$rrP][$puC] = min($mxC, $gN + 1.0);
                                    pp_recompute_stgs($genRows[$rrP], $d3, $model, $rrP + 1);
                                    if (!$rampOK($rrP) || $expAtL($rrP) > min($hL[$rrP], $rMaxGas) + 1e-6
                                        || calc_busflow($genRows[$rrP], $busUnit, (float)$ieVals[$rrP]) < ((float)($model['busflow_min'] ?? 0)) - 1e-6) {
                                        $genRows[$rrP][$puC] = $gN; pp_recompute_stgs($genRows[$rrP], $d3, $model, $rrP + 1); continue;
                                    }
                                    $gasNowL += $rowGas($genRows[$rrP]) - $rgN; $movedC = true;
                                }
                                continue;
                            }
                            $rg0 = $rowGas($genRows[$r]);
                            $genRows[$r][$puC] = min($mxC, min($nbHi, $gC + 1.0));
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            $gasNowL += $rowGas($genRows[$r]) - $rg0;
                            $movedC = true; break;
                        }
                    }
                    if (!$needC || !$movedC) break;
                }
                $ok = $gasNowL < $gasBefore - 1e-9;                        // net gas harus turun
                if ($ok) foreach (array_unique(array_merge($compRows, array_keys($snapT))) as $r) {   // PATCH G05: rows segmen yg dimatikan ikut dicek (BusFlow!)
                    if ($expAtL($r) < $fL[$r] - 1e-3 || !$rampOK($r)
                        || calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < ((float)($model['busflow_min'] ?? 0)) - 1e-6) { $ok = false; break; }
                }
                if (!$ok) {
                    foreach ($snapT as $r => $gS) { $genRows[$r] = $gS; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }
                    $gasNowL = $gasBefore;
                }
            }
            /* budget disinkronkan ulang: startup penalty BERUBAH setelah segmen dimatikan */
            $gasCapL = $effQL - $penOf($genRows); $winLoL = 0.0;
            /* Fase 2: turunkan level unit ON (reverse priority, 1 MW steps, tidak mematikan) */
            for ($tp = 0; $tp < 800 && $gasNowL > $gasCapL + 1e-9; $tp++) {
                $prog = false;
                foreach ($downOrder as $cu) {
                    if ($gasNowL <= $gasCapL + 1e-9) break;
                    if (!isset($d3[$cu])) continue;
                    /* PATCH E14: floor trim = min_ccload ?? min_scload — G7/G10 SC-only (min_ccload NULL)
                     * sebelumnya digiling ke ~1 MW (pelanggaran min_load). Unit tak boleh di bawah min-nya. */
                    $mccT = ($cu === 'b1' || $cu === 'b2') ? 0.0   // PATCH E14: Babelan sah melintasi nilai rendah (ramp 5/row menuju 0)
                          : (float)($d3[$cu]['min_ccload'] ?? ($d3[$cu]['min_scload'] ?? 0));
                    for ($r = 0; $r < $nRows && $gasNowL > $gasCapL + 1e-9; $r++) {
                        if (isset($actualRows[$r]) || isset($g8fixL[$r])) continue;
                        $g0 = (float)($genRows[$r][$cu] ?? 0);
                        if ($g0 <= $mccT + 1e-6 || $g0 <= 0.01) continue;
                        if (pp_get_fixed_load($model, $cu, $r + 1) >= 0) continue;   // PATCH G33: row fixed operator otoritatif
                    if (isset($suWinL[$cu][$r])) continue;                        // PATCH H08: langkah startup EXACT
                    if (!$uRampOk($cu, $r, max($mccT, $g0 - 1.0))) continue;   // PATCH B03
                        $rg0 = $rowGas($genRows[$r]);
                        $genRows[$r][$cu] = max($mccT, $g0 - 1.0);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if ($expAtL($r) < $fL[$r] - 1e-3 || !$rampOK($r)
                            || calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < ((float)($model['busflow_min'] ?? 0)) - 1e-6) {
                            $genRows[$r][$cu] = $g0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            continue;
                        }
                        $gasNowL += $rowGas($genRows[$r]) - $rg0; $prog = true;
                    }
                }
                if (!$prog) break;
            }
            /* PATCH G05 — EXHAUSTION EVIDENCE gas over: Fase 1 (stop segmen) + Fase 2 (turunkan level)
             * sudah kehabisan kandidat (floors min-CC/required + Export Range Min + ramp menahan) tetapi
             * gas masih di atas cap. Ini kondisi fisik yang dipaksa komitmen operator (mis. unit priority
             * tinggi di-hold sehingga unit ber-heat-rate buruk harus menutup export floor). Emit evidence
             * numerik row-level agar validator mengklasifikasikan VALID-INFEASIBLE, bukan FAIL diam. */
            if ($gasNowL > $gasCapL + 0.04 + 1e-9) {
                $warnings[] = sprintf('gas quota exceeded: Total Gas %.4f BBTUD > quota %.4f BBTUD (over %.4f) — seluruh kandidat trim exhausted: unit tersisa di must-run floor (min-CC/required/fixed) dan penurunan lebih lanjut menembus PLN Export Range Min / ramp. ADDITIONAL LNG REQUIRED %.3f BBTUD (atau longgarkan komitmen start/stop).', $gasNowL, $gasCapL, $gasNowL - $gasCapL, $gasNowL - $gasCapL);
            }
            /* trim bisa overshoot ke bawah window -> refill ramp-aware sekali lagi */
            $gasCapL = $effQL - $penOf($genRows); $winLoL = 0.0;
            for ($rf3 = 0; $rf3 < 400 && $gasNowL < $winLoL; $rf3++) {
                $prog = false;
                for ($r = 0; $r < $nRows && $gasNowL < $winLoL; $r++) {
                    if (isset($actualRows[$r]) || isset($g8fixL[$r])) continue;
                    $capL = min($effHiArr[$r], $rMaxGas, $hL[$r]);
                    if ($r > 0)          $capL = min($capL, $expAtL($r - 1) + 30.0);
                    if ($r < $nRows - 1) $capL = min($capL, $expAtL($r + 1) + 30.0);
                    if ($expAtL($r) >= $capL - 1e-6) continue;
                    foreach ($upOrderGas as $u) {
                        if (!isset($d3[$u]) || ($genRows[$r][$u] ?? 0) <= 0) continue;
                        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
                        if (($genRows[$r][$u] ?? 0) < (float)($d3[$u]['min_ccload'] ?? 0) - 1e-6) continue;   // lead-in startup: jangan diganggu
                        $maxU = pp_effective_maxload($d3, $model, $u, $r + 1);   // PATCH G29-G31: effective max per row
                        if (($genRows[$r][$u] ?? 0) >= $maxU - 1e-6) continue;
                        $g0 = (float)$genRows[$r][$u]; $rg0 = $rowGas($genRows[$r]);
                        if (isset($suWinL[$u][$r])) continue;                        // PATCH H08
                        if (!$uRampOk($u, $r, min($maxU, $g0 + 1.0))) continue;   // PATCH B03
                        $genRows[$r][$u] = min($maxU, $g0 + 1.0);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $rg1 = $rowGas($genRows[$r]);
                        if ($expAtL($r) > $capL + 1e-6 || ($gasNowL - $rg0 + $rg1) > $gasCapL + 1e-9) {
                            $genRows[$r][$u] = $g0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            continue;
                        }
                        $gasNowL = $gasNowL - $rg0 + $rg1; $prog = true; break;
                    }
                }
                if (!$prog) break;
            }
        }
    }

    /* ===== PATCH H03/I-GROUP — REBUILD __stg_hold DARI LINTASAN FINAL ========================
     * Peta __stg_hold dibangun shaper pada lintasan TENGAH; restart transien meninggalkan entri
     * basi (lubang S=0 di tengah run, atau gating hilang utk start yang bergeser). Bangun ulang
     * dari lintasan FINAL: STG last=Stop -> hold 0 pada [anchor .. anchor+delay-1], anchor = row
     * feeder pertama ON; Cold S1/S2 di-cap 20 pada row kemunculan pertama. Lalu recompute kolom S
     * seluruh row non-locked — semua pass sudah selesai menggeser GTG. */
    {
        $holdN = [];
        foreach (['s1','s2','s3'] as $sR2) {
            if (!isset($d3[$sR2])) continue;
            if (strtolower((string)($model['unit_last_data_status'][strtoupper($sR2)] ?? '')) !== 'stop') continue;
            $modeR = strtolower((string)($model['stg_startup_mode'][$sR2] ?? ($model['stg_startup_mode'][$sR2 . '_startup'] ?? 'warm')));
            $lagR = ($sR2 === 's3') ? ['cold' => 11, 'warm' => 5, 'hot' => 1] : ['cold' => 7, 'warm' => 2, 'hot' => 1];
            $dR = $lagR[$modeR] ?? 2;
            $feedR2 = array_map('strtolower', array_values($d3[$sR2]['hrsg'] ?? ($d3[$sR2]['gtg'] ?? [])));
            $anch = -1;
            for ($rS2 = 0; $rS2 < $nRows; $rS2++) { foreach ($feedR2 as $fR2)
                if ((float)($genRows[$rS2][$fR2] ?? 0) > 0.01) { $anch = $rS2; break 2; } }
            if ($anch < 0) continue;
            for ($rS2 = $anch; $rS2 < min($anch + $dR, $nRows); $rS2++) $holdN[$sR2][$rS2 + 1] = 0.0;
            if ($modeR === 'cold' && ($sR2 === 's1' || $sR2 === 's2') && $anch + $dR < $nRows)
                $holdN[$sR2][$anch + $dR + 1] = 20.0;                       // cap kemunculan pertama Cold
        }
        $model['__stg_hold'] = $holdN;                                       // REPLACE map basi
        for ($rS2 = 0; $rS2 < $nRows; $rS2++) {
            if (isset($actualRows[$rS2])) continue;
            pp_recompute_stgs($genRows[$rS2], $d3, $model, $rS2 + 1);
        }
    }

    /* PATCH G21: extension ULANG — over-trim Fase 1 dapat menghentikan unit lever sehingga run
     * STG blok memendek di bawah minimum; sapuan kedua menutup segmen pendek yang baru muncul. */
    $ppExtendMinRuntime();
    /* ===== PATCH G01b — STG STALE-HOLE REPAIR (tertarget) ====================================
     * Enforcement tertentu (mis. hold ON-OFF-ON di pp_apply_startup_constraints) menulis load GTG
     * tanpa recompute STG -> "lubang" S=0 di tengah run padahal load feeder identik dgn row
     * tetangga yang S>0. Perbaiki HANYA row seperti itu (recompute lokal) — bukan reconcile
     * blanket, agar gating startup/urutan sequence yang sudah benar tidak terganggu. */
    foreach ([['s1',['g3','g4','g6']], ['s2',['g1','g2','g5']], ['s3',['g8','g9']]] as [$sH, $fdH]) {
        if (!isset($d3[$sH])) continue;
        for ($rH = 1; $rH < $nRows; $rH++) {
            if (isset($actualRows[$rH])) continue;
            if ((float)($genRows[$rH][$sH] ?? 0) > 0.01) continue;                 // bukan lubang
            /* cari tetangga (kiri prioritas) dgn S>0 dan load feeder identik */
            foreach ([$rH - 1, $rH + 1] as $nbH) {
                if ($nbH < 0 || $nbH >= $nRows) continue;
                if ((float)($genRows[$nbH][$sH] ?? 0) <= 0.01) continue;
                $same = true;
                foreach ($fdH as $fH2) if (abs((float)($genRows[$rH][$fH2] ?? 0) - (float)($genRows[$nbH][$fH2] ?? 0)) > 0.01) { $same = false; break; }
                if (!$same) continue;
                pp_recompute_stgs($genRows[$rH], $d3, $model, $rH + 1);
                break;
            }
        }
    }

    /* ===== PATCH G06d — STG-RUN REPAIR (multi-feeder) ========================================
     * Run STG fresh = [anchor+delay .. row terakhir ada feeder ON]. Bila lebih pendek dari minimum
     * runtime STG (12 row) dan berakhir dini bukan karena stop-schedule, perpanjang feeder yang ON
     * di ujung (urutan priority) ke depan pada max(level, min-CC) sampai run STG terpenuhi.
     * Coupling per-GTG tidak cukup utk blok multi-feeder (kedua feeder berakhir bersamaan). */
    {
        $limS = pp_runtime_limits($model); $stgMinS = (int)($limS['stg']['run_rows'] ?? 12);
        foreach (['s1','s2','s3'] as $sG) {
            if (!isset($d3[$sG])) continue;
            if (strtolower((string)($model['unit_last_data_status'][strtoupper($sG)] ?? '')) !== 'stop') continue;
            $feedG = array_map('strtolower', array_values($d3[$sG]['hrsg'] ?? ($d3[$sG]['gtg'] ?? [])));
            if (!$feedG) continue;
            $modeG = strtolower((string)($model['stg_startup_mode'][$sG] ?? ($model['stg_startup_mode'][$sG . '_startup'] ?? 'warm')));
            $lagG = ($sG === 's3') ? ['cold'=>11,'warm'=>5,'hot'=>1] : ['cold'=>7,'warm'=>2,'hot'=>1];
            $anchG = -1;
            for ($r = 0; $r < $nRows; $r++) { foreach ($feedG as $fG2)
                if ((float)($genRows[$r][$fG2] ?? 0) > 0.01) { $anchG = $r; break 2; } }
            if ($anchG < 0) continue;
            $sFirst = $anchG + ($lagG[$modeG] ?? 2);
            $segEnd = -1;
            for ($r = $nRows - 1; $r >= $sFirst; $r--) { foreach ($feedG as $fG2)
                if ((float)($genRows[$r][$fG2] ?? 0) > 0.01) { $segEnd = $r; break 2; } }
            if ($segEnd < $sFirst) continue;
            $needG = $stgMinS - ($segEnd - $sFirst + 1);
            if ($needG <= 0 || $segEnd >= $nRows - 1) continue;
            /* feeder yang on di ujung, urut priority */
            $ordG = [];
            foreach ([pp_priority_flat($model)] as $gp) foreach ((array)$gp as $c2) { $c2 = strtolower((string)$c2);
                if (in_array($c2, $feedG, true) && (float)($genRows[$segEnd][$c2] ?? 0) > 0.01 && !in_array($c2, $ordG, true)) $ordG[] = $c2; }
            foreach ($feedG as $c2) if ((float)($genRows[$segEnd][$c2] ?? 0) > 0.01 && !in_array($c2, $ordG, true)) $ordG[] = $c2;
            if (!$ordG) continue;
            $fx = $ordG[0];
            $mnG = (float)($d3[$fx]['min_ccload'] ?? ($d3[$fx]['min_scload'] ?? 5));
            $lvlG = max((float)$genRows[$segEnd][$fx], $mnG);
            for ($r = $segEnd + 1; $r < $nRows && $needG > 0; $r++) {
                if (isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $fx, $r + 1)) break;
                if (pp_get_fixed_load($model, $fx, $r + 1) >= 0) break;
                $genRows[$r][$fx] = max((float)($genRows[$r][$fx] ?? 0), $lvlG);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $needG--;
            }
        }
    }

    /* ===== PATCH I07b — ANTI-BLIP MIN-LOAD ===================================================
     * Interaksi antar-pass dapat meninggalkan dip 1-row < min-CC (mis. nilai startup 5 MW yatim di
     * tengah run 20 MW). Blip ilegal (validator: invalid load drop). Angkat dip ke min(tetangga),
     * dibatasi effective max; skip fixed/actual/stop & row dlm window startup. */
    for ($swpAB = 0; $swpAB < 6; $swpAB++) { $movAB = false;
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uAB) {
        if (!isset($d3[$uAB])) continue;
        /* PETA STARTUP WINDOW DIBANGUN ULANG DI SINI (WEEKLY §2/§4): $suWinL dibuat sebelum shaper,
         * sehingga startup yang DITULIS shaper (mis. Additional HRSG [5,15] di ekor hari) tidak
         * terdaftar dan klamp min-CC di bawah menimpa step 5/15 menjadi min load. Rebuild memakai
         * pp_start_sequence() — helper yang sama dengan generator & validator. */
        {
            $prevOnAB = (strtolower((string)($model['unit_last_data_status'][strtoupper($uAB)] ?? '')) === 'running');
            for ($rW = 0; $rW < $nRows; $rW++) {
                $onW = ((float)($genRows[$rW][$uAB] ?? 0) > 0.01);
                if ($onW && !$prevOnAB) {
                    $sqW = pp_start_sequence($d3, $model, $uAB, $genRows, $rW);
                    for ($kW = 0; $kW < count($sqW) && $rW + $kW < $nRows; $kW++) $suWinL[$uAB][$rW + $kW] = true;
                }
                $prevOnAB = $onW;
            }
        }
        $mccAB = (float)($d3[$uAB]['min_ccload'] ?? ($d3[$uAB]['min_scload'] ?? 5));
        for ($r = 1; $r < $nRows - 1; $r++) {
            if (isset($actualRows[$r])) continue;
            $lP0 = (float)($genRows[$r-1][$uAB] ?? 0);
            if (isset($suWinL[$uAB][$r])) continue;                            // DI DALAM startup window: nilai 5/15 sah, jangan diklamp
            if (pp_get_fixed_load($model, $uAB, $r + 1) >= 0) continue;
            if (pp_is_unit_stopped($d3, $model, $uAB, $r + 1)) continue;
            $lC = (float)($genRows[$r][$uAB] ?? 0);
            if ($lC <= 0.01 || $lC >= $mccAB - 0.01) continue;
            $lP = (float)($genRows[$r-1][$uAB] ?? 0); $lN = (float)($genRows[$r+1][$uAB] ?? 0);
            $emAB = pp_effective_maxload($d3, $model, $uAB, $r + 1);
            if ($lP >= $mccAB - 0.01 && $lN >= $mccAB - 0.01) {
                $genRows[$r][$uAB] = min($emAB > 0 ? $emAB : $lP, min($lP, $lN));   // blip: samakan tetangga
            } elseif ($lP >= $mccAB - 0.01 || $lN >= $mccAB - 0.01) {
                /* PATCH I08c (konservatif): row < min-CC BERSEBELAHAN row sehat (sisa langkah 0.5
                 * pass repair, mis. 64.5) — klamp naik ke min-CC. Lead-in/lead-out murni tak disentuh. */
                $genRows[$r][$uAB] = min($emAB > 0 ? $emAB : $mccAB, $mccAB);
            } else { continue; }
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); $movAB = true;
        }
    }
    if (!$movAB) break; }


    /* ===== PATCH G06b — FINAL EXPORT FLOOR RAISE =============================================
     * Gating fresh-STG / klamp min-load di atas dapat menggeser export beberapa row di bawah floor
     * SETELAH evidence shaping dibuat. Repair terakhir: naikkan unit gas merit (running, bukan
     * fixed/stop/startup-window) 0.5 MW steps; ramp30 utk G7-G10. Exhausted -> evidence row-level. */
    {
        $peF = $model['pln_export_priority'] ?? [];
        $fLo = array_fill(0, $nRows, (float)($peF['range']['min'] ?? 0));
        $fHi = array_fill(0, $nRows, (float)($peF['range']['max'] ?? INF));
        foreach (($peF['range_rules'] ?? []) as $rrF) { if (!is_array($rrF)) continue;
            $sF = max(1,(int)($rrF['start'] ?? 1)); $eF = min($nRows,(int)($rrF['stop'] ?? $nRows));
            for ($r = $sF-1; $r <= $eF-1; $r++) { if (isset($rrF['min'])) $fLo[$r] = max($fLo[$r], (float)$rrF['min']);
                if (isset($rrF['max'])) $fHi[$r] = min($fHi[$r], (float)$rrF['max']); } }
        $ordF = ['g9','g8','g1','g2','g5','g3','g4','g6','g10','g7'];
        for ($r = 0; $r < $nRows; $r++) {
            if (isset($actualRows[$r])) continue;
            for ($ie2 = 0; $ie2 < 400; $ie2++) {
                $eC2 = $realised_export($genRows[$r], (float)$ieVals[$r])['export'];
                if ($eC2 >= $fLo[$r] - 1e-6) break;
                $did2 = false;
                foreach ($ordF as $uF4) {
                    if (!isset($d3[$uF4]) || isset($suWinL[$uF4][$r])) continue;
                    if (pp_get_fixed_load($model, $uF4, $r + 1) >= 0) continue;
                    if (pp_is_unit_stopped($d3, $model, $uF4, $r + 1)) continue;
                    $g4v = (float)($genRows[$r][$uF4] ?? 0);
                    if ($g4v < 0.01) continue;                                        // anti auto-run
                    $mcc4 = (float)($d3[$uF4]['min_ccload'] ?? ($d3[$uF4]['min_scload'] ?? 5));
                    if ($g4v < $mcc4 - 0.01) continue;                                // lead-in/blip: jangan diamplifikasi
                    if ($r > 0 && $r < $nRows-1 && (float)($genRows[$r-1][$uF4] ?? 0) < 0.01
                        && (float)($genRows[$r+1][$uF4] ?? 0) < 0.01) continue;       // row terisolasi
                    $em4 = pp_effective_maxload($d3, $model, $uF4, $r + 1);
                    if ($em4 <= 0) $em4 = (float)($d3[$uF4]['max_load'] ?? 108);
                    $nv4 = min($em4, $g4v + 0.5);
                    if ($nv4 <= $g4v + 1e-9) continue;
                    if (in_array($uF4, ['g7','g8','g9','g10'], true)) { $ok4 = true;
                        foreach ([$r-1,$r+1] as $nb4) { if ($nb4<0||$nb4>=$nRows) continue;
                            $x4=(float)($genRows[$nb4][$uF4]??0);
                            if ($x4>=1 && abs($nv4-$x4) > (in_array($uF4,['g8','g9'],true)?30.0:55.0)+1e-6) { $ok4=false; break; } }
                        if (!$ok4) continue; }
                    $sv4 = $genRows[$r]; $genRows[$r][$uF4] = $nv4;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    if ($realised_export($genRows[$r], (float)$ieVals[$r])['export'] > $fHi[$r] + 1e-6) {
                        $genRows[$r] = $sv4; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                    $gasF4 = pp_startup_gas_penalty($genRows, $model, true);
                    foreach ($genRows as $gr4) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $ug4)
                        $gasF4 += calc_fuel($d3, $ug4, (float)($gr4[$ug4] ?? 0)) / 2.0;
                    /* tier-1: tetap <= quota Jababeka efektif bila memungkinkan (export floor > gas,
                     * tapi jangan bocorkan overshoot kecil tanpa perlu) */
                    $qJ4 = (float)($model['gas_quota']['pgn_pipe'] ?? 0) + (float)($model['gas_quota']['lng'] ?? 0)
                         + ((float)($model['gas_quota']['pep'] ?? 0) + (float)($model['gas_quota']['akasia'] ?? 0)
                         + (float)($model['gas_quota']['baskara'] ?? 0) + (float)($model['gas_quota']['bbg'] ?? 0)) * $ghvJQ / 1000.0;
                    if ($qJ4 > 1 && $gasF4 > $qJ4 + 1e-4) {
                        $genRows[$r] = $sv4; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                    $did2 = true; break;
                }
                if (!$did2) break;
            }
            $eF2 = $realised_export($genRows[$r], (float)$ieVals[$r])['export'];
            if ($eF2 < $fLo[$r] - 0.51)
                $warnings[] = sprintf('PLN Export below Range Min row %d: %.2f < %.2f — final raise exhausted: seluruh unit gas running di effective max/ramp/startup-window atau di-hold komitmen operator (gating STG fresh menahan output blok).', $r + 1, $eF2, $fLo[$r]);
        }
    }

    /* PATCH J10 — FINAL GAS NUDGE: overshoot kecil (cap .. cap+0.2] pasca extension/required-start
     * diturunkan reverse-merit 0.5 MW steps (guard: floor export, suWin, fixed, min-CC, ramp30). */
    {
        $qJn = (float)($model['gas_quota']['pgn_pipe'] ?? 0) + (float)($model['gas_quota']['lng'] ?? 0)
             + ((float)($model['gas_quota']['pep'] ?? 0) + (float)($model['gas_quota']['akasia'] ?? 0)
             + (float)($model['gas_quota']['baskara'] ?? 0) + (float)($model['gas_quota']['bbg'] ?? 0)) * $ghvJQ / 1000.0;
        $gasN = pp_startup_gas_penalty($genRows, $model, true);   // PATCH J10: ukuran = validator (fuel + startup penalty)
        foreach ($genRows as $grN) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $ugN)
            $gasN += calc_fuel($d3, $ugN, (float)($grN[$ugN] ?? 0)) / 2.0;
        if ($qJn > 1 && $gasN > $qJn + 1e-9 && $gasN <= $qJn + 0.2) {
            $peN = $model['pln_export_priority'] ?? [];
            $fLoN = array_fill(0, $nRows, (float)($peN['range']['min'] ?? 0));
            foreach (($peN['range_rules'] ?? []) as $rrN) { if (!is_array($rrN) || !isset($rrN['min'])) continue;
                for ($r = max(1,(int)($rrN['start']??1))-1; $r <= min($nRows,(int)($rrN['stop']??$nRows))-1; $r++) $fLoN[$r] = max($fLoN[$r], (float)$rrN['min']); }
            $ordN = ['g7','g10','g6','g4','g3','g5','g2','g1','g8','g9'];
            for ($itN = 0; $itN < 400 && $gasN > $qJn + 1e-9; $itN++) {
                $didN = false;
                foreach ($ordN as $uN) {
                    if (!isset($d3[$uN])) continue;
                    for ($r = 0; $r < $nRows; $r++) {
                        if (isset($actualRows[$r]) || isset($suWinL[$uN][$r])) continue;
                        if (pp_get_fixed_load($model, $uN, $r + 1) >= 0) continue;
                        $gN2 = (float)($genRows[$r][$uN] ?? 0);
                        $mcN = (float)($d3[$uN]['min_ccload'] ?? ($d3[$uN]['min_scload'] ?? 5));
                        if ($gN2 <= $mcN + 1e-6) continue;
                        $nvN = max($mcN, $gN2 - 0.5);
                        if (in_array($uN, ['g7','g8','g9','g10'], true)) { $okN = true;
                            foreach ([$r-1,$r+1] as $nbN) { if ($nbN<0||$nbN>=$nRows) continue;
                                $xN=(float)($genRows[$nbN][$uN]??0);
                                if ($xN>=1 && abs($nvN-$xN) > (in_array($uN,['g8','g9'],true)?30.0:55.0)+1e-6) { $okN=false; break; } }
                            if (!$okN) continue; }
                        $svN = $genRows[$r]; $genRows[$r][$uN] = $nvN;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if ($realised_export($genRows[$r], (float)$ieVals[$r])['export'] < $fLoN[$r] - 1e-6) {
                            $genRows[$r] = $svN; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                        $gasN += (calc_fuel($d3, $uN, $nvN) - calc_fuel($d3, $uN, $gN2)) / 2.0;
                        $didN = true; break 2;
                    }
                }
                if (!$didN) break;
            }
        }
    }

    /* ===== BAGIAN A — BALANCE LOAD G8/G9 (balanced default, gap normal <= 25 MW) ==============
     * Dijalankan SETELAH seluruh repair/ramp/ladder (agar tidak ditimpa pass hilir) dan SEBELUM
     * evidence gas G06c + Fixed Load re-assert (agar perintah manual operator tetap menang).
     * Menjaga JUMLAH G8+G9 per row -> Export/BusFlow/gas/cost terbukti tidak bergeser; lihat catatan
     * kalibrasi di pp_balance_g89(). $suWinL memasok pengecualian window startup (§A3). */
    {
        $evB89 = pp_balance_g89($genRows, $d3, $model, $actualRows, $suWinL);
        if ($evB89) $warnings[] = sprintf('G8/G9 load split diseimbangkan pada %d row (gap normal dibatasi 25 MW, jumlah Block-3 tetap sehingga Export/gas/cost tidak berubah): %s%s',
            count($evB89), implode('; ', array_slice($evB89, 0, 3)), count($evB89) > 3 ? sprintf(' (+%d row lain)', count($evB89) - 3) : '');
    }

    /* PATCH G06c — evidence gas over pasca extension/floor-raise (runtime & export floor = hard,
     * gas menyerah dgn evidence): bila total Jababeka melewati cap efektif, laporkan numerik. */
    {
        $gasFin = pp_startup_gas_penalty($genRows, $model, true);
        foreach ($genRows as $grE) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $ugE)
            $gasFin += calc_fuel($d3, $ugE, (float)($grE[$ugE] ?? 0)) / 2.0;
        /* PATCH T04a — kuota efektif WAJIB memasukkan Additional LNG, sama seperti tiga call-site kanonik
         * lain (worker02:835 $shaperQuota, worker02:1297 $effQL, worker02:2006 $addLng) dan sama seperti
         * yang dilaporkan info engine sendiri (Base Gas Quota 66 + Added LNG 2 = Total Gas Quota 68).
         * Tanpa term ini evidence G06c membandingkan gas final terhadap kuota BASE saja -> pada input
         * 7-Jul-26 (pipe 27 + lng 2 + pep 37 = 66, additional_lng = 2, action = add_lng) rencana yang
         * SAH pada 67.96 <= 68 dilaporkan "gas quota exceeded > 66" dan seluruh run jadi VALID-INFEASIBLE.
         * Ini persis "2-BBTUD mismatch" yang komentar $shaperQuota di atas sudah perbaiki utk shaper —
         * call-site ini tertinggal. */
        /* FIX UNIT_MISMATCH pada quota check Jababeka: pep/akasia/baskara/bbg adalah Fixed Flow
         * dalam MMSCFD dan WAJIB dikonversi (x GHV jababeka / 1000) sebelum dijumlahkan dengan
         * pgn_pipe/lng yang sudah BBTUD. Sebelumnya keduanya dijumlahkan mentah sehingga quota
         * terhitung 66,0000 (30 + 36) bukannya 68,8800 (30 + 38,88) — memunculkan false
         * "gas quota exceeded" dan violation runtime_downtime yang tidak nyata.
         * Konsisten dengan $ffJQuotaKeys pada perhitungan Total Gas Quota. */
        $ghvJE = (float)($model['ghv_jababeka'] ?? 1000);
        $qJE = (float)($model['gas_quota']['pgn_pipe'] ?? 0) + (float)($model['gas_quota']['lng'] ?? 0)
             + ((float)($model['gas_quota']['pep'] ?? 0) + (float)($model['gas_quota']['akasia'] ?? 0)
              + (float)($model['gas_quota']['baskara'] ?? 0) + (float)($model['gas_quota']['bbg'] ?? 0)) * $ghvJE / 1000.0
             + (in_array(($model['gas_shortage_action'] ?? 'none'), ['add_lng','mixed_lng_distillate'], true) ? (float)($model['additional_lng'] ?? 0) : 0.0);
        /* §1 FINAL: bila action=use_distillate, distillate menggantikan energi gas sehingga gas
         * EFEKTIF = gasFin − offset. Kuota efektif utk evidence ini menambahkan kapasitas distillate
         * (energi gas yg digantikan), analog dgn Additional LNG pada add_lng. Distillate backward
         * menutup shortage penuh bila slot eligible cukup -> evidence over-quota tidak berlaku lagi.
         * Offset diambil dari alokasi distillate (dihitung di blok bawah); di titik ini kita hitung
         * kebutuhan-vs-kapasitas ringkas: bila distillate mampu menutup (gasFin−qJE), naikkan qJE. */
        if (pp_action_uses_distillate((string)($model['gas_shortage_action'] ?? 'none')) && $qJE > 1 && $gasFin > $qJE) {
            /* kapasitas distillate = total energi gas future rows unit G1-G10 yg dapat dikonversi
             * (batas atas offset). Bila >= kekurangan, shortage tertutup penuh -> tak ada over-quota. */
            $distCap = 0.0;
            foreach ($genRows as $ri => $grE) {
                if (isset($actualRows[$ri])) continue;
                foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $ugE)
                    $distCap += calc_fuel($d3, $ugE, (float)($grE[$ugE] ?? 0)) / 2.0;
            }
            if ($distCap >= $gasFin - $qJE - 1e-9) $qJE = $gasFin;   // distillate menutup -> tak ada over-quota efektif
        }
        /* CATATAN STALE-EVIDENCE (WEEKLY §7): nilai $gasFin di sini adalah SNAPSHOT sebelum pass
         * perbaikan hilir (coordinated gas correction / export-floor repair). Karena itu peringatan
         * tidak boleh dipancarkan sekarang — ia DITUNDA dan diverifikasi ulang terhadap dispatch
         * FINAL. Tanpa penundaan ini, hasil yang sudah masuk window tetap dilaporkan over-quota. */
        if ($qJE > 1 && $gasFin > $qJE + 0.04 + 1e-9)
            $GLOBALS['__pp_gasfin_pending'] = ['gas_snapshot' => $gasFin, 'quota' => $qJE];
    }

    /* ===== PATCH G33 — FIXED LOAD RE-ASSERT FINAL + CONFLICT EVIDENCE ========================
     * (a) Safety net: pass mana pun setelah re-assert shaping tidak boleh meninggalkan row fixed
     *     dgn nilai lain — Fixed Load operator otoritatif (prinsip 'fix tetap menang').
     * (b) Konflik Fixed>0 di dalam Unit Stop window = input kontradiktif: Fixed menang pada row-nya,
     *     stop tetap berlaku di luar. Emit evidence 'Fixed Load INFEASIBLE ... CONFLICT' agar
     *     validator klasifikasi VALID-INFEASIBLE, bukan FAIL diam. */
    {
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'] as $uF3) {
            if (!isset($d3[$uF3])) continue;
            $confW = [];
            for ($r = 0; $r < $nRows; $r++) {
                if (isset($actualRows[$r])) continue;
                $fv3 = pp_get_fixed_load($model, $uF3, $r + 1);
                if ($fv3 < 0) continue;
                if (abs((float)($genRows[$r][$uF3] ?? 0) - $fv3) > 1e-6) {
                    $genRows[$r][$uF3] = $fv3;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
                if ($fv3 > 0 && pp_is_unit_stopped($d3, $model, $uF3, $r + 1)) $confW[] = $r + 1;
            }
            if ($confW) $warnings[] = sprintf('Fixed Load INFEASIBLE (input CONFLICT): %s Fixed Load > 0 pada row %s berada DI DALAM Unit Stop window — Fixed Load (operator) menang pada row tsb, unit tetap 0 di sisa window; lead-in start-up tidak dapat dipenuhi utk lonjakan on/off ini. Selesaikan dgn menghapus salah satu aturan.', strtoupper($uF3), implode(',', array_slice($confW, 0, 6)));
        }
    }


    /* ===== §8 FINAL G8/G9 POST-PASS RECHECK (sebelum materialization) =====
     * Pass repair/gas/export/ramp di atas dapat menggeser G8/G9. Jalankan recheck balanced-default
     * TERAKHIR agar split final = candidate biaya terendah (balanced, karena kurva linear-identik =
     * cost-neutral) tanpa merusak hard constraint. Idempotent: bila sudah balanced & patuh, no-op.
     * Evidence cost per row dikumpulkan ke info['G8/G9 Cost Recheck']. */
    $evB89Final = pp_balance_g89($genRows, $d3, $model, $actualRows, $suWinL);
    if ($evB89Final) $warnings[] = sprintf('G8/G9 FINAL recheck (post-pass, pre-materialize): %d row diseimbangkan ulang (cost-neutral, jumlah Block-3 tetap): %s%s',
        count($evB89Final), implode('; ', array_slice($evB89Final, 0, 2)), count($evB89Final) > 2 ? sprintf(' (+%d row)', count($evB89Final) - 2) : '');
    /* audit balanced/imbalanced final + evidence cost (§10) untuk SETIAP row G8&G9 running normal. */
    $g89CostRecheck = [];
    for ($rr = 0; $rr < $nRows; $rr++) {
        $a = (float)($genRows[$rr]['g8'] ?? 0); $b = (float)($genRows[$rr]['g9'] ?? 0);
        if ($a < 0.01 || $b < 0.01) continue;
        $gap = abs($b - $a); $sum = $a + $b;
        $fuelSel = calc_fuel($d3, 'g8', $a) + calc_fuel($d3, 'g9', $b);
        $fuelBal = calc_fuel($d3, 'g8', $sum / 2) + calc_fuel($d3, 'g9', $sum / 2);
        $locked = isset($actualRows[$rr]); $suW = isset($suWinL['g8'][$rr]) || isset($suWinL['g9'][$rr]);
        $g89CostRecheck[$rr + 1] = [
            'time' => $input['data1'][$rr]['time'] ?? '',
            'g8' => round($a, 2), 'g9' => round($b, 2), 'gap' => round($gap, 2),
            'balanced' => $gap <= 0.5,
            'fuel_selected' => round($fuelSel, 6), 'fuel_balanced' => round($fuelBal, 6),
            'cost_saving_bbtud' => round($fuelBal - $fuelSel, 6),   // 0 = cost-neutral
            'reason' => $locked ? 'TIME PASSED locked' : ($suW ? 'startup/transition window' : ($gap <= 0.5 ? 'balanced (default; no cheaper imbalanced candidate)' : 'imbalanced held by constraint (ramp/max/min) — cost-neutral, sum preserved')),
        ];
    }


    /* ---- build final $data from the resolved gen rows --------------------- */
    $data = [];
    /* EXPORT-RAMP FINAL REPAIR: dipanggil SETELAH seluruh re-assertion pasca-shaper (Required/
     * fixed/min bump — penulis yang selama ini menciptakan ulang cliff). Gas-netral penuh. */
    {
        $rngFR = $model['pln_export_priority']['range'] ?? [];
        $rMinFR = isset($rngFR['min']) && $rngFR['min'] !== '' ? (float)$rngFR['min'] : -INF;
        $rMaxFR = isset($rngFR['max']) && $rngFR['max'] !== '' ? (float)$rngFR['max'] : INF;
        pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
        /* STRICT EXPORT FLOOR (§6, prioritas 1): dijalankan SETELAH export-ramp repair supaya
         * tidak ada satu row pun di bawah Effective Range Min pada hasil final. Evidence per lever
         * dilampirkan ke info['Export Floor Repair'] (row, lever, unit, before/after, gas delta). */
        /* ===== POST-STARTUP MIN-LOAD ENFORCEMENT (WEEKLY ITEM-A) ==================================
         * Setelah jendela startup sequence selesai, unit WAJIB mencapai minimum load-nya. Nilai 5/15 MW
         * hanya sah DI DALAM sequence; bila tertinggal di 15 MW pada row sesudahnya (mis. karena clamp
         * ramp lintas hari atau export floor menahan kenaikan), validator benar melaporkannya sebagai
         * "Invalid 15 MW startup load". Pass ini menaikkan unit ke min load pada row pasca-sequence
         * (menghormati effective max & ramp); bila tidak mungkin, unit dimatikan agar tidak parkir di
         * nilai startup yang tidak sah. Generator & validator memakai pp_start_sequence() yang sama. */
        foreach (['g1','g2','g3','g4','g5','g6','g8','g9'] as $uPS) {
            if (!isset($d3[$uPS])) continue;
            $mnPS = (float)($d3[$uPS]['min_ccload'] ?? ($d3[$uPS]['min_scload'] ?? 20));
            $seqLeft = 0;
            for ($r = 0; $r < $nRows; $r++) {
                $cur = (float)($genRows[$r][$uPS] ?? 0);
                $prev = $r ? (float)($genRows[$r-1][$uPS] ?? 0) : 0.0;
                if ($cur < 0.51) { $seqLeft = 0; continue; }
                if ($prev < 0.51) $seqLeft = max(1, count(pp_start_sequence($d3, $model, $uPS, $genRows, $r)));
                if ($seqLeft > 0) { $seqLeft--; continue; }                 // masih di dalam sequence: sah
                /* GUARD DOMAIN (Additional HRSG): 5/15 MW adalah nilai STARTUP-ONLY. Bila unit baru
                 * menyala <= 3 row, nilai itu adalah langkah sequence yang sah dan TIDAK boleh diklamp
                 * menjadi min load (kasus 5 -> 20 melompati langkah 15). */
                $onRunPS = 0;
                for ($q = $r; $q >= 0 && (float)($genRows[$q][$uPS] ?? 0) > 0.51; $q--) $onRunPS++;
                if ($onRunPS <= 3 && (abs($cur - 5.0) < 0.05 || abs($cur - 15.0) < 0.05)) continue;
                if ($cur >= $mnPS - 1e-6) continue;                          // sudah >= min load
                if (isset($actualRows[$r]) || pp_get_fixed_load($model, $uPS, $r + 1) >= 0) continue;
                $capPS = pp_effective_maxload($d3, $model, $uPS, $r + 1);
                if ($capPS <= 0) $capPS = (float)($d3[$uPS]['max_load'] ?? $mnPS);
                $tgtPS = min($capPS, max($mnPS, $prev - 30.0 < $mnPS ? $mnPS : $mnPS));
                $genRows[$r][$uPS] = ($tgtPS >= $mnPS - 1e-6) ? $tgtPS : 0.0;   // naikkan ke min, atau matikan
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }

        /* ===== RESERVE REPAIR (hard constraint) dijalankan SEBELUM export-floor repair supaya
         * unit tambahan yang dinyalakan ikut diperhitungkan oleh perbaikan berikutnya. */
        $reserveEv = [];
        $model['__reserve_gas_cap'] = (float)($gasQuotaJababeka ?? 0);   // gate kuota utk reserve repair
        pp_reserve_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR, $reserveEv);
        /* ITEM-R1 BABELAN RESERVE RELIEF (gas-neutral): turunkan BB1/BB2 untuk menciptakan headroom
         * spinning reserve dan menutup Export di atas Range Max. Hanya menurunkan coal -> reserve &
         * bus flow monoton naik, gas tidak berubah, Export dijaga tetap di atas Range Min. */
        /* LEVER TIDAK LANGSUNG: Babelan menyerap energi supaya load GTG/GEG eligible dapat turun;
         * HANYA dari turunnya load GTG/GEG itulah Spinning Reserve bertambah. Headroom Babelan
         * sendiri TIDAK PERNAH dihitung sebagai reserve. */
        [$gLoW1, ] = pp_gas_window(pp_gas_quota_jababeka_bbtud($model));
        $bbReliefN = pp_babelan_energy_redispatch_for_reserve($genRows, $d3, $model, $ieVals, $actualRows,
                        $rMinFR, $rMaxFR, $reserveEv, $gLoW1);
        if ($bbReliefN > 0) {
            pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
            $GLOBALS['__pp_babelan_redispatch'] = $bbReliefN;
        }
        if ($reserveEv) $GLOBALS['__pp_reserve_ev'] = $reserveEv;
        /* EXPORT PEAK SHIFT (gas-neutral) sebelum floor repair: turunkan row di atas Range Max lalu
         * kembalikan gas pada row ber-headroom sehingga LNG must-take tetap terserap. */
        $peakEv = [];
        pp_export_peak_shift($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR, $peakEv);
        if ($peakEv) $GLOBALS['__pp_peak_ev'] = $peakEv;
        $expFloorEv = [];
        /* BUKTI MINIMISASI EXPORT (definisi bisnis Gas Shortage): rekam gas dan Export SEBELUM
         * blok penurunan Export/koreksi gas dijalankan, lalu SESUDAHNYA. Tanpa dua angka ini,
         * pernyataan "Export sudah diturunkan lebih dulu" tidak dapat dibuktikan. */
        $gasSnap = function (array $rows) use ($d3, $model): float {
            $g = pp_startup_gas_penalty($rows, $model, true);
            foreach ($rows as $gr) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $uu)
                $g += calc_fuel($d3, $uu, (float)($gr[$uu] ?? 0)) / 2.0;
            return $g;
        };
        $expSnap = function (array $rows) use ($realised_export, $ieVals): array {
            $o = []; foreach ($rows as $ri => $gr)
                $o[$ri] = round((float)$realised_export($gr, (float)$ieVals[$ri])['export'], 3);
            return $o;
        };
        $GLOBALS['__pp_expmin_gas_before'] = $gasSnap($genRows);
        $GLOBALS['__pp_expmin_export_before'] = $expSnap($genRows);
        $trimTarget0 = (float)($model['__gas_trim_target_bbtud'] ?? 0);
        /* WEEKLY §3/§5 — COORDINATED FULL-HORIZON CORRECTION dijalankan LEBIH DULU bila outer loop
         * meminta penurunan gas: swap export-neutral (GTG+STG sebagai satu paket) tidak mengubah
         * export sama sekali sehingga tidak pernah bertabrakan dengan export-floor repair. Sisa yang
         * belum tertutup diteruskan sebagai anchor ke floor repair. */
        $coordFreed = 0.0;
        if (!empty($model['__screen_only'])) $trimTarget0 = 0.0;      // mode penyaringan kandidat: murah & cepat
        if ($trimTarget0 > 1e-9) {
            $coordFreed = pp_coordinated_gas_correction($genRows, $d3, $model, $ieVals, $actualRows,
                                                        $rMinFR, $rMaxFR, $trimTarget0, $expFloorEv);
        }
        pp_export_floor_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR, $expFloorEv,
                               max(0.0, $trimTarget0 - $coordFreed));
        /* TOP-UP (BUG-09): bila outer loop meminta KENAIKAN gas (arah under-target) dan shaper
         * belum mencapainya, pakai headroom naik yang benar-benar legal. All-or-nothing. */
        $upWant = (float)($model['__shaper_quota_adjust'] ?? 0);
        if ($upWant > 1e-9 && empty($model['__screen_only'])) {
            $gasNowTU = pp_startup_gas_penalty($genRows, $model, true);
            foreach ($genRows as $grT) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $ugT)
                $gasNowTU += calc_fuel($d3, $ugT, (float)($grT[$ugT] ?? 0)) / 2.0;
            $tuEv = [];
            $tuAdded = pp_gas_topup_pass($genRows, $d3, $model, $ieVals, $actualRows,
                          $rMinFR, $rMaxFR, $upWant, $realised_export, $tuEv);
            if ($tuEv) $GLOBALS['__pp_gas_topup_ev'] = $tuEv;
        }
        /* LANTAI KUOTA MM2100 — KENDALA TERSENDIRI, BUKAN BAGIAN DARI WINDOW GAS TOTAL.
         *
         * ROOT CAUSE (terukur pada gas matrix: Pertagas/Akasia/Baskara KP72 = 2,2). Menambah kuota
         * KP72 menaikkan kuota MM2100 dari 2,2000 ke 4,4660 BBTUD sehingga lantainya menjadi
         * 4,4260; pemakaian MM2100 tetap 4,39952 dan validator menolak rencana dengan
         * `MM2100 Used+Startup 4.3995 < quota-0.04 (4.4260)`. Koreksi window gas yang ada hanya
         * mengejar TOTAL (Jababeka + MM2100): ia menutup kekurangan total dengan menaikkan unit
         * Jababeka — lever termurah per MW — sehingga lantai MM2100 tidak pernah tersentuh dan
         * rencana selesai dengan satu pelanggaran keras yang tidak punya pass perbaikan sama sekali.
         *
         * Lever-nya NYATA dan terbukti ada pada rencana yang sama: GE1 sudah di 12,50 MW (maksimum),
         * tetapi GE2 berada di 8,00 dari 12,50 dan GE3 di 3,00 dari 12,50 — headroom naik yang sah.
         * Kekurangannya 0,0265 BBTUD, sementara jarak ke langit-langit kuota 0,0665 BBTUD.
         *
         * Karena itu pass ini dijalankan pada SETIAP shaper, terlepas dari keadaan window total,
         * dibatasi HANYA pada unit MM2100 (G10, GE1..GE4) dan diberi plafon sebesar jarak ke
         * langit-langit kuota MM2100, sehingga menutup lantai tidak pernah melahirkan pelanggaran
         * langit-langit yang baru. All-or-nothing: bila headroom legal tidak menutup kekurangan,
         * rencana dikembalikan utuh dan alasannya dicatat sebagai blocker — bukan diam-diam gagal. */
        if (empty($model['__screen_only'])) {
            $mmQuotaFloorQ = pp_mm2100_quota_energy_bbtud($model);
            /* Bukti lama dibuang lebih dulu: satu core run berikutnya tidak boleh mewarisi catatan
             * dari rencana yang sudah diganti. */
            $GLOBALS['__pp_mm2100_floor_ev'] = null;
            if ($mmQuotaFloorQ > 1e-9) {
                $mmUsedNow  = pp_mm2100_used_bbtud($genRows, $d3);
                $mmFloorNeed = ($mmQuotaFloorQ - 0.04) - $mmUsedNow;
                if ($mmFloorNeed > 1e-9) {
                    $mmCeilRoom = max(0.0, $mmQuotaFloorQ - $mmUsedNow);
                    /* SASARAN = TENGAH JENDELA, BUKAN TEPAT DI LANTAI — dan ini bukan selera.
                     * Jendela kuota MM2100 hanya selebar 0,04 BBTUD; mendarat TEPAT di lantai
                     * adalah titik paling rapuh, dan yang lebih penting: gas MM2100 ikut mengisi
                     * jendela gas TOTAL. Terukur pada Pertagas KP72 = 2,2 — menaikkan MM2100 hanya
                     * sebesar kekurangan lantai (0,0268) memaksa koreksi window total mengambil
                     * sisanya dari unit Jababeka, dan itu justru mendorong supplier PGN Pipe
                     * melewati kuotanya (30,0184 > 30,0000). Dengan sasaran tengah jendela, ruang
                     * kontraktual MM2100 yang memang tersedia dipakai lebih dulu dan tekanan pada
                     * supplier pipe berkurang. Pemicunya tetap LANTAI: rencana yang sudah legal
                     * tidak disentuh sama sekali. */
                    $mmAim = min($mmCeilRoom, max($mmFloorNeed, ($mmQuotaFloorQ - 0.02) - $mmUsedNow));
                    $mmEv = [];
                    $mmGot = pp_gas_topup_pass($genRows, $d3, $model, $ieVals, $actualRows,
                                 $rMinFR, $rMaxFR, $mmAim, $realised_export, $mmEv,
                                 ['g10', 'ge1', 'ge2', 'ge3', 'ge4'], $mmCeilRoom);
                    /* All-or-nothing pada sasaran tengah bisa gagal walau lantai masih terjangkau;
                     * bila itu terjadi, coba lagi dengan kebutuhan lantai yang sesungguhnya. */
                    if ($mmGot <= 1e-9 && $mmAim > $mmFloorNeed + 1e-9)
                        $mmGot = pp_gas_topup_pass($genRows, $d3, $model, $ieVals, $actualRows,
                                     $rMinFR, $rMaxFR, $mmFloorNeed, $realised_export, $mmEv,
                                     ['g10', 'ge1', 'ge2', 'ge3', 'ge4'], $mmCeilRoom);
                    $GLOBALS['__pp_mm2100_floor_ev'] = [
                        'quota_bbtud'        => round($mmQuotaFloorQ, 6),
                        'floor_bbtud'        => round($mmQuotaFloorQ - 0.04, 6),
                        'used_before_bbtud'  => round($mmUsedNow, 6),
                        'needed_bbtud'       => round($mmFloorNeed, 6),
                        'ceiling_room_bbtud' => round($mmCeilRoom, 6),
                        'added_bbtud'        => round($mmGot, 6),
                        'used_after_bbtud'   => round(pp_mm2100_used_bbtud($genRows, $d3), 6),
                        'applied'            => $mmGot > 1e-9,
                        'topup_evidence'     => $mmEv,
                    ];
                    if ($mmGot > 1e-9)
                        pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
                }
            }
        }
        /* FASE A — FEASIBILITY REPAIR MINIMISASI EXPORT (definisi bisnis Gas Shortage).
         * Definisi bisnis mewajibkan Export PLN diturunkan sampai Range Min pada seluruh slot
         * yang relevan SEBELUM residualnya boleh disebut Gas Shortage. Berbeda dengan jalur
         * pendaratan window (all-or-nothing), di sini penurunan SEBAGIAN wajib di-commit:
         * residual SETELAH minimisasi inilah angka shortage yang sah. Seluruh hard constraint
         * tetap berlaku — lever ini hanya memakai headroom yang sudah legal (lihat guard di
         * pp_mm2100_gas_trim: baris actual, fixed load, jadwal stop, ketersediaan, applicable
         * minimum, Export >= Range Min, Export <= Range Max, BusFlow, batas langkah Export). */
        if (!empty($model['__gas_shortage_minimize']) && empty($model['__screen_only'])) {
            $smEv = [];
            $smFreed = pp_mm2100_gas_trim($genRows, $d3, $model, $ieVals, $actualRows,
                          $rMinFR, $rMaxFR, 1.0e9, $realised_export, $smEv, true);
            if ($smEv) $GLOBALS['__pp_shortage_min_ev'] = $smEv;
            if ($smFreed > 0) pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
        }
        /* LEVER MM2100 (perbaikan): bila outer loop masih meminta penurunan gas dan pass Jababeka
         * belum menutupinya, coba unit Gas Engine MM2100 — satu-satunya lever gas yang selama ini
         * tidak pernah ditelusuri. All-or-nothing: tidak menutup kebutuhan = tidak ada perubahan. */
        if ($trimTarget0 > 1e-9 && empty($model['__screen_only'])) {
            $geNeed = max(0.0, $trimTarget0 - $coordFreed);
            if ($geNeed > 1e-9) {
                $geEv = [];
                $geFreed = pp_mm2100_gas_trim($genRows, $d3, $model, $ieVals, $actualRows,
                              $rMinFR, $rMaxFR, $geNeed, $realised_export, $geEv);
                if ($geEv) $GLOBALS['__pp_mm2100_trim_ev'] = $geEv;
                if ($geFreed > 0) $coordFreed += $geFreed;
            }
        }
        if ($expFloorEv) $GLOBALS['__pp_export_floor_ev'] = $expFloorEv;
        pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);   // rapikan ramp pasca-floor
        $GLOBALS['__pp_expmin_gas_after'] = $gasSnap($genRows);
        $GLOBALS['__pp_expmin_export_after'] = $expSnap($genRows);
        $GLOBALS['__pp_expmin_range'] = ['min' => $rMinFR, 'max' => $rMaxFR];
        /* BABELAN RE-MAXIMIZE (Priority-1) SETELAH repair: repair dapat membuka ruang baru pada row
         * yang sebelumnya ramp/export-locked. Naikkan b2 lalu b1 selama seluruh guard valid:
         * effective max, ramp Babelan +-10 ke kedua tetangga, fixed/actual/stop, Export <= Range Max,
         * export-ramp <= 30 vs tetangga, BusFlow >= min. Babelan = coal -> gas tidak berubah. */
        {
            $bbLimRM = pp_babelan_ramp_limit($model);
            $busMinRM = (float)($model['busflow_min'] ?? 0);
            $needRM = pp_reserve_min($model);   // HARD CONSTRAINT: re-maximize adalah optimisasi BIAYA
            for ($sw = 0; $sw < 400; $sw++) {
                if (pp_budget_exceeded('bb_remaximize')) break;
                if (pp_budget_repeat('bbrm', pp_rows_signature($genRows))) break;
                $movedRM = false;
                foreach (['b2', 'b1'] as $uRM) {
                    for ($r = 0; $r < $nRows; $r++) {
                        if (isset($actualRows[$r])) continue;
                        if (pp_get_fixed_load($model, $uRM, $r + 1) >= 0) continue;
                        if (pp_is_unit_stopped($d3, $model, $uRM, $r + 1)) continue;
                        $cRM = (float)($genRows[$r][$uRM] ?? 0);
                        if ($cRM < 0.01) continue;                                  // startup placement: bukan urusan pass ini
                        $capRM = pp_effective_maxload($d3, $model, $uRM, $r + 1);
                        if ($capRM <= 0) $capRM = (float)($d3[$uRM]['max_load'] ?? 120);
                        foreach ([$r - 1, $r + 1] as $nbRM) { if ($nbRM < 0 || $nbRM >= $nRows) continue;
                            $capRM = min($capRM, (float)($genRows[$nbRM][$uRM] ?? 0) + $bbLimRM); }
                        if ($cRM + 0.5 > $capRM + 1e-9) continue;
                        $svRM = $genRows[$r]; $genRows[$r][$uRM] = $cRM + 0.5;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $eRM = (float)$realised_export($genRows[$r], (float)$ieVals[$r])['export'];
                        $okRM = ($eRM <= $rMaxFR + 1e-6);
                        foreach ([$r - 1, $r + 1] as $nbRM) { if ($nbRM < 0 || $nbRM >= $nRows) continue;
                            $eNb = (float)$realised_export($genRows[$nbRM], (float)$ieVals[$nbRM])['export'];
                            if (abs($eRM - $eNb) > 30.0 + 1e-6) $okRM = false; }
                        if ($okRM && $busMinRM > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMinRM - 1e-6) $okRM = false;
                        /* CONSTRAINT FIRST, COST SECOND: menaikkan Babelan MENGURANGI headroom coal,
                         * sehingga tidak boleh membuat (atau membiarkan) Spinning Reserve di bawah
                         * requirement. Tanpa guard ini, pass biaya ini membatalkan hasil
                         * pp_babelan_energy_redispatch_for_reserve() yang berjalan lebih awal. */
                        if ($okRM && $needRM > 0
                            && pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) < $needRM - 1e-6) $okRM = false;
                        if (!$okRM) { $genRows[$r] = $svRM; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                        $movedRM = true;
                    }
                }
                if (!$movedRM) break;
            }
            /* RELIEF PASS KEDUA (final): startup placement & shaper berjalan SETELAH relief pertama,
             * sehingga row yang baru menjadi defisit (mis. unit masih di dalam startup window dan
             * belum reserve-eligible) hanya terlihat di sini. Re-maximize di atas sudah ber-guard
             * reserve sehingga hasil relief ini tidak akan dibatalkan lagi. */
            $reliefEv2 = [];
            [$gLoW, ] = pp_gas_window(pp_gas_quota_jababeka_bbtud($model));
            $bbRelief2 = pp_babelan_energy_redispatch_for_reserve($genRows, $d3, $model, $ieVals, $actualRows,
                            $rMinFR, $rMaxFR, $reliefEv2, $gLoW);
            if ($bbRelief2 > 0) {
                pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
                $GLOBALS['__pp_babelan_redispatch2'] = $bbRelief2;
            }
            /* Bila redispatch belum cukup, nyalakan unit eligible tambahan secara anticipatory pada
             * minimum stable load (bukan beban ekonomis) — CONSTRAINT FIRST, COST SECOND. */
            [, $gHiW] = pp_gas_window(pp_gas_quota_jababeka_bbtud($model));
            $antEv = [];
            $antN = pp_anticipatory_commit_for_reserve($genRows, $d3, $model, $ieVals, $actualRows,
                        $rMinFR, $rMaxFR, $antEv, $gHiW);
            if ($antN > 0) {
                pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
                $GLOBALS['__pp_anticipatory_commit'] = $antN;
                foreach ($antEv as $ae) $reserveEv[] = $ae;
            }
        }
        /* VERIFIKASI ULANG peringatan gas yang ditunda terhadap dispatch FINAL. */
        if (isset($GLOBALS['__pp_gasfin_pending'])) {
            $pend = (array)$GLOBALS['__pp_gasfin_pending']; unset($GLOBALS['__pp_gasfin_pending']);
            $gasFinal2 = 0.0;
            foreach ($genRows as $riF => $grF) {
                if (isset($actualRows[$riF])) continue;
                foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uF)
                    $gasFinal2 += calc_fuel($d3, $uF, (float)($grF[$uF] ?? 0)) / 2.0;
            }
            $qJE2 = (float)($pend['quota'] ?? 0);
            if ($qJE2 > 1 && $gasFinal2 > $qJE2 + 0.04 + 1e-9)
                $warnings[] = sprintf('gas quota exceeded: Total Gas Jababeka %.4f BBTUD > quota %.4f (over %.4f) — min-runtime extension & export floor (hard) memaksa unit tetap online di min-load; ADDITIONAL LNG REQUIRED %.3f BBTUD.',
                    $gasFinal2, $qJE2, $gasFinal2 - $qJE2, $gasFinal2 - $qJE2);
        }
    }
    /* FINAL CONSTRAINT REPAIR AFTER ALL GAS/COMMITMENT SHAPING.
     * A higher PEP target may change commitment, but must not leave a repairable Export deficit.
     * Use already-running legal headroom first, recompute dependent STG, then repair Export ramp.
     * Two bounded passes prevent downstream shaping from re-introducing a RangeMin violation. */
    {
        $finalRepairEvidence = [];
        for ($frp = 0; $frp < 2; $frp++) {
            $changedFloor = pp_export_floor_repair($genRows, $d3, $model, $ieVals, $actualRows,
                $rMinFR, $rMaxFR, $finalRepairEvidence, 0.0, true);   // FINAL PASS: langkah halus aktif
            $changedRamp = pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows,
                $rMinFR, $rMaxFR);
            if (($changedFloor + $changedRamp) <= 0) break;
        }
        if ($finalRepairEvidence) $GLOBALS['__pp_final_constraint_repair_ev'] = $finalRepairEvidence;
        if (pp_action_uses_distillate((string)($model['gas_shortage_action']??'none'))) $GLOBALS['__pp_distillate_export_floor_policy']=['status'=>'ENABLED','rule'=>'EFFECTIVE_PER_ROW_EXPORT_FLOOR_RUNNING_UNITS_THEN_DISTILLATE_SUBSTITUTION'];
    }
    /* WARNING & VALIDASI STARTUP HANYA DARI FINAL DISPATCH (WEEKLY §3): dipindah ke SETELAH
     * seluruh pass yang mengubah load (shaper, re-assertion, export/gas repair, post-startup
     * min-load). Sebelumnya berjalan lebih awal sehingga warning state pra-perbaikan bertahan
     * dan dipromosikan validator menjadi violation palsu. */
    /* PATCH I08b: validasi startup HANYA pada STATE FINAL — buang dulu hasil validasi state tengah
     * (termasuk sisa pass-1 autorerun BBG) agar warning basi tidak dipromosikan jadi violation palsu. */
    $warnings = array_values(array_filter($warnings, function ($wS) {
        foreach (['Invalid 5 MW startup load', 'Invalid 15 MW startup load', 'Invalid load drop', 'Invalid CC load drop'] as $pS)
            if (strpos($wS, $pS) === 0) return false;
        return true;
    }));
    /* ===== ANTI-ZEROING STARTUP WINDOW (T-WEEK-BOUND-08) =====================================
     * pp_build_startup_lock() melindungi nilai di dalam startup window dari KENAIKAN, tetapi tidak
     * dari PENGOSONGAN ke 0. Akibatnya masih mungkin muncul pola invalid 0 -> 5 -> 0 -> 24: row di
     * dalam window dikosongkan oleh pass lain, lalu row berikutnya loncat ke free dispatch.
     * Pass ini menegaskan kembali expected step tepat sebelum validasi final: row di dalam startup
     * window WAJIB sama dengan sequence (tidak boleh naik, turun, maupun menjadi 0). */
    $reassertN = pp_reassert_startup_steps($genRows, $d3, $model, $actualRows);
    if ($reassertN > 0) {
        /* Menaikkan unit ke min load dapat menciptakan lonjakan Export antar row. Rapikan ramp,
         * lalu tegaskan ulang step startup (pass ini idempoten) supaya perbaikan ramp tidak
         * mengembalikan nilai di dalam window. */
        pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
        $reassertN += pp_reassert_startup_steps($genRows, $d3, $model, $actualRows);
        $GLOBALS['__pp_startup_reassert'] = $reassertN;
    }

    /* ===== D-1: FINAL INVARIANT REPAIR — UNIT SKIP LOAD ======================================
     * Dijalankan SETELAH fungsi terakhir yang dapat mengubah dispatch (reassert startup + export
     * ramp repair di atas), tepat SEBELUM validasi final dan penyusunan baris output. Inilah
     * satu-satunya titik di mana invariant band terlarang dapat ditegakkan tanpa pass lain
     * mengembalikannya ke dalam band.
     *
     * Perbaikan memakai redispatch berkompensasi dengan rollback transaksional — bukan snap.
     * Bila sebuah baris tidak dapat diperbaiki secara legal, baris itu DIBIARKAN apa adanya dan
     * validator hard tetap melaporkan pelanggaran `skip_load`: kegagalan tidak pernah lolos diam.
     *
     * Dua putaran: perbaikan dapat menggeser Export sedikit sehingga pp_export_ramp_repair() perlu
     * merapikan ramp, dan perapian itu secara teoretis dapat mengembalikan sebuah unit ke dalam
     * band. Putaran kedua bersifat idempoten — bila putaran pertama sudah bersih, ia tidak
     * mengubah apa pun. */
    /* ===== RE-ASSERTION LANTAI EXPORT DI TITIK PALING AKHIR ==================================
     * TERUKUR, INILAH SEBABNYA. Pada skenario dua stop schedule (G2 rows 5-10, G3 rows 30-40),
     * row 27 tersisa pada Export 24,77 vs Range Min 25,00 — kurang 0,23 MW — dan `Export Floor
     * Blockers` KOSONG, artinya repair tidak pernah menyatakan baris itu kehabisan lever. Ketika
     * pp_export_floor_repair() dipanggil LANGSUNG atas 48 baris final yang sama, ia justru berhasil:
     * G9 dinaikkan, Export 24,76 -> 25,45, gas terhitung 64,5631 masih di dalam window (batas atas
     * 64,6000, sisa ruang 0,0369 BBTUD). Jadi lantai Export itu sebenarnya dapat dicapai; yang
     * terjadi adalah sebuah pass SETELAH repair final mengembalikan baris itu ke bawah Range Min.
     *
     * Karena itu lantai Export ditegakkan ulang di titik paling akhir — pola yang sama dengan final
     * invariant Unit Skip Load di bawah. Blok ini TIDAK AKTIF bila tidak ada baris yang melanggar,
     * sehingga hasil yang sudah benar tidak bergeser sedikit pun (baseline tanpa pelanggaran
     * menghasilkan dispatch dan checksum yang identik). Seluruh guard repair tetap berlaku: batas
     * min/max unit, ramp, BusFlow, reserve, window kuota gas, dan himpunan legal Unit Skip Load. */
    {
        $__efNeed = false;
        for ($__r = 0; $__r < $nRows; $__r++) {
            $__lo = (float)($baseLoArr[$__r] ?? 0);
            $__hi = (float)($effHiArr[$__r] ?? 0);
            if ($__lo <= 0 && $__hi <= 0) continue;
            $__g = $genRows[$__r];
            $__mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $__u) $__mm += (float)($__g[$__u] ?? 0);
            $__tot = 0.0; foreach ($__g as $__k => $__v) if (is_numeric($__v)) $__tot += (float)$__v;
            $__e = $__tot - $__mm - calc_house_load($__g) - (float)$ieVals[$__r];
            if ($__e < $__lo - 1e-9 || ($__hi > 0 && $__e > $__hi + 1e-6)) { $__efNeed = true; break; }
        }
        if ($__efNeed) {
            $__efEv = [];
            for ($__p = 0; $__p < 2; $__p++) {
                $__cf = pp_export_floor_repair($genRows, $d3, $model, $ieVals, $actualRows,
                                               $rMinFR, $rMaxFR, $__efEv, 0.0, true);
                $__cr = pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
                if (($__cf + $__cr) <= 0) break;
            }
            if ($__efEv) {
                $GLOBALS['__pp_final_export_floor_reassertion'] = [
                    'triggered' => true, 'events' => array_slice($__efEv, 0, 40),
                    'catatan' => 'lantai Export ditegakkan ulang setelah pass terakhir yang mengubah dispatch'];
            }
        }
    }

    /* ===== RE-ASSERTION LANTAI KUOTA MM2100 DI TITIK PALING AKHIR ============================
     * Pasangan dari re-assertion lantai Export di atas, dengan sebab yang sama dan terukur.
     * Pass lantai MM2100 di hulu (setelah top-up window gas) memang menutup kekurangannya, tetapi
     * pass-pass SETELAHNYA — minimisasi shortage, trim MM2100, perapian ramp, re-assertion startup,
     * dan re-assertion lantai Export — masih dapat menurunkan unit MM2100 lagi. Terukur pada
     * Pertagas/Akasia/Baskara KP72 = 2,2: rencana final kembali ke `MM2100 Used = 4,39952` padahal
     * pass hulu sudah mencapai 4,43289.
     *
     * Blok ini TIDAK AKTIF bila lantai sudah terpenuhi, sehingga rencana yang sudah benar tidak
     * bergeser sedikit pun. Bila lantai belum terpenuhi dan headroom legal tidak mencukupi,
     * rencana dikembalikan utuh dan alasannya tercatat — validator tetap melaporkan pelanggaran
     * `mm2100_quota`, jadi kegagalan tidak pernah lolos diam-diam. */
    if (empty($model['__screen_only'])) {
        $__mmQ = pp_mm2100_quota_energy_bbtud($model);
        if ($__mmQ > 1e-9) {
            $__mmUsed = pp_mm2100_used_bbtud($genRows, $d3);
            $__mmNeed = ($__mmQ - 0.04) - $__mmUsed;
            if ($__mmNeed > 1e-9) {
                $__mmEv = [];
                $__mmRoom = max(0.0, $__mmQ - $__mmUsed);
                $__mmAim  = min($__mmRoom, max($__mmNeed, ($__mmQ - 0.02) - $__mmUsed));   // tengah jendela
                $__mmGot = pp_gas_topup_pass($genRows, $d3, $model, $ieVals, $actualRows,
                               $rMinFR, $rMaxFR, $__mmAim, $realised_export, $__mmEv,
                               ['g10', 'ge1', 'ge2', 'ge3', 'ge4'], $__mmRoom);
                if ($__mmGot <= 1e-9 && $__mmAim > $__mmNeed + 1e-9)
                    $__mmGot = pp_gas_topup_pass($genRows, $d3, $model, $ieVals, $actualRows,
                                   $rMinFR, $rMaxFR, $__mmNeed, $realised_export, $__mmEv,
                                   ['g10', 'ge1', 'ge2', 'ge3', 'ge4'], $__mmRoom);
                if ($__mmGot > 1e-9)
                    pp_export_ramp_repair($genRows, $d3, $model, $ieVals, $actualRows, $rMinFR, $rMaxFR);
                $GLOBALS['__pp_mm2100_floor_reassertion'] = [
                    'triggered'         => true,
                    'quota_bbtud'       => round($__mmQ, 6),
                    'floor_bbtud'       => round($__mmQ - 0.04, 6),
                    'used_before_bbtud' => round($__mmUsed, 6),
                    'needed_bbtud'      => round($__mmNeed, 6),
                    'added_bbtud'       => round($__mmGot, 6),
                    'used_after_bbtud'  => round(pp_mm2100_used_bbtud($genRows, $d3), 6),
                    'applied'           => $__mmGot > 1e-9,
                    'evidence'          => array_slice($__mmEv, 0, 10),
                    'catatan'           => 'lantai kuota MM2100 ditegakkan ulang setelah pass terakhir yang mengubah dispatch',
                ];
            } else {
                $GLOBALS['__pp_mm2100_floor_reassertion'] = null;
            }
        }
    }
    /* ===== V5 — PLAFON KUOTA MM2100 PADA TOTAL BLENDED ========================================
     * Validator menilai pemakaian MM2100 = actual per jam untuk jam ber-Actual Energy MM2100 +
     * estimasi untuk jam lain, terhadap kuota sebagai plafon keras (toleransi nol). Bila meter actual
     * jam lalu lebih tinggi dari estimasinya, total blended dapat melewati kuota dengan selisih sangat
     * kecil (terukur KP72 naik satu slot: 2,20004 > 2,2000 — seluruh kandidat ditolak karena 0,00004).
     * Pass ini menurunkan beban GE yang sedang berjalan pada row future (bukan row actual, bukan jam
     * ber-actual MM2100) per 0,01 MW sampai total blended <= kuota, di atas beban minimum unit, maks.
     * 40 langkah dan hanya untuk kelebihan <= 0,02 BBTUD. Rencana yang tidak melewati plafon tidak
     * disentuh. PP_V5_MM_CAP=0 mematikan. */
    $GLOBALS['__pp_mm2100_cap_trim'] = null;
    if (empty($model['__screen_only']) && (string)getenv('PP_V5_MM_CAP') !== '0') {
        $__cq = pp_mm2100_quota_energy_bbtud($model);
        if ($__cq > 1e-9) {
            $__bl0 = pp_mm2100_used_bbtud($genRows, $d3, $model); $__ex = $__bl0 - $__cq;
            if ($__ex > 1e-9 && $__ex <= 0.02) {
                $__skip = $actualRows;
                foreach ((array)($model['actual_energy_mm2100'] ?? []) as $__ai => $__av)
                    if ($__av !== null && $__av !== '') { $__h = intdiv((int)$__ai, 2); $__skip[2 * $__h] = true; $__skip[2 * $__h + 1] = true; }
                $__steps = []; $__bl = $__bl0;
                for ($__k = 0; $__k < 40 && $__bl > $__cq + 1e-9; $__k++) {
                    $__pick = null;
                    for ($__r = count($genRows) - 1; $__r >= 0 && $__pick === null; $__r--) {
                        if (isset($__skip[$__r])) continue;
                        foreach (['ge1', 'ge2', 'ge3', 'ge4'] as $__u) {
                            $__v = (float)($genRows[$__r][$__u] ?? 0); if ($__v <= 0.01) continue;
                            $__mn = pp_effective_min_load($d3, $model, $__u, $__r + 1);
                            if (pp_get_fixed_load($model, $__u, $__r + 1) >= 0 || $__v - 0.01 < $__mn - 1e-9) continue;
                            $__pick = [$__r, $__u]; break;
                        }
                    }
                    if ($__pick === null) break;
                    [$__r, $__u] = $__pick; $__from = (float)$genRows[$__r][$__u];
                    /* langkah dari kurva bahan bakar unit: MW yang dibutuhkan untuk memotong kelebihan */
                    $__mnU = pp_effective_min_load($d3, $model, $__u, $__r + 1);
                    $__dE = (calc_fuel($d3, $__u, $__from) - calc_fuel($d3, $__u, max($__mnU, $__from - 1.0))) / 2.0 / max(0.01, $__from - max($__mnU, $__from - 1.0));
                    $__need = ($__bl - $__cq) + 0.00002;
                    $__dMW = $__dE > 1e-9 ? ceil($__need / $__dE * 100.0) / 100.0 : 0.01;
                    $__dMW = max(0.01, min($__dMW, $__from - $__mnU));
                    $genRows[$__r][$__u] = round($__from - $__dMW, 2);
                    $__bl = pp_mm2100_used_bbtud($genRows, $d3, $model);
                    $__steps[] = ['row' => $__r + 1, 'unit' => strtoupper($__u), 'from_mw' => $__from, 'to_mw' => $genRows[$__r][$__u], 'blended_after_bbtud' => round($__bl, 6)];
                }
                $GLOBALS['__pp_mm2100_cap_trim'] = ['applied' => (bool)$__steps, 'quota_bbtud' => round($__cq, 6), 'blended_before_bbtud' => round($__bl0, 6),
                    'excess_before_bbtud' => round($__ex, 6), 'blended_after_bbtud' => round($__bl, 6), 'within_cap' => $__bl <= $__cq + 1e-9, 'steps' => $__steps,
                    'catatan' => 'total blended (actual jam ber-actual + estimasi) dijaga <= kuota MM2100 dengan menurunkan beban GE pada row future'];
            }
        }
    }

    /* ===== FINAL INVARIANT UNIT SKIP LOAD (instruksi §3.4) ===================================
     * Dijalankan SETELAH fungsi terakhir yang dapat mengubah dispatch. Ini BUKAN perbaikan: seluruh
     * penegakan band sudah terjadi di hulu, pada domain yang dilihat setiap pass (kotak legal
     * hasil pemilihan cabang — lihat pp_apply_legal_branch()). Tugas blok ini hanya MEMBUKTIKAN
     * invariant itu benar dan, bila tidak, menerbitkan CONFLICT SET SPESIFIK sehingga kegagalan
     * tidak pernah lolos diam-diam dan tidak pernah ditutup dengan tambalan di ujung pipeline.
     *
     * Penilaian memakai satu-satunya sumber kebenaran (pp_load_in_forbidden_band): band adalah
     * interval TERBUKA, kedua boundary legal, toleransi numerik hanya menyerap noise floating-point.
     * Karena nilai yang dinilai di sini masih nilai internal (belum dibulatkan untuk tampilan),
     * penilaian memakai presisi penuh. */
    if (!empty($model['unit_skip_load'])) {
        $__skViol = []; $__skUnits = [];
        foreach ((array)$model['unit_skip_load'] as $__su => $__sr) {
            if (!is_array($__sr) || !$__sr) continue;
            $__su = strtolower((string)$__su);
            if (!isset($d3[$__su])) continue;
            $__skUnits[] = $__su;
            for ($__r = 0; $__r < $nRows; $__r++) {
                $__v = (float)($genRows[$__r][$__su] ?? 0);
                if (!pp_load_in_forbidden_band($model, $__su, $__r + 1, $__v)) continue;
                $__dom = pp_legal_load_domain($d3, $model, $__su, $__r + 1);
                $__skViol[] = [
                    'row'              => $__r + 1,
                    'unit'             => strtoupper($__su),
                    'value_mw'         => round($__v, 3),
                    'forbidden_bands'  => array_map(fn($b) => [round((float)$b[0], 2), round((float)$b[1], 2)],
                                                    pp_skip_load_bands($model, $__su, $__r + 1)),
                    'legal_intervals'  => array_map(fn($b) => [round((float)$b[0], 2), round((float)$b[1], 2)],
                                                    $__dom['intervals']),
                    'applicable_min'   => round((float)$__dom['amin'], 2),
                    'applicable_max'   => round((float)$__dom['amax'], 2),
                    'fixed_load_lock'  => $__dom['locked'],
                    'reason'           => !$__dom['intervals']
                        ? 'LEGAL_INTERVAL_KOSONG: band menutup seluruh rentang operasi yang berlaku'
                        : ($__dom['locked'] !== null
                            ? 'FIXED_LOAD_MENGUNCI_NILAI_DI_DALAM_BAND'
                            : 'LEVEL_TERPILIH_DI_LUAR_KOTAK_LEGAL'),
                ];
            }
        }
        $__skInv = ['units' => array_values(array_unique($__skUnits)),
                    'skip_load_violations' => count($__skViol),
                    'invariant_holds' => !$__skViol];
        if ($__skViol) {
            /* CONFLICT SET SPESIFIK. Diringkas per (unit, alasan, interval legal) agar operator
             * melihat konflik domainnya, bukan 48 baris berulang. */
            $__grp = [];
            foreach ($__skViol as $vv) {
                $k = $vv['unit'] . '|' . $vv['reason'] . '|' . json_encode($vv['legal_intervals']);
                if (!isset($__grp[$k])) $__grp[$k] = ['unit' => $vv['unit'], 'reason' => $vv['reason'],
                    'forbidden_bands' => $vv['forbidden_bands'], 'legal_intervals' => $vv['legal_intervals'],
                    'applicable_min' => $vv['applicable_min'], 'applicable_max' => $vv['applicable_max'],
                    'rows' => [], 'values_mw' => []];
                $__grp[$k]['rows'][] = $vv['row'];
                $__grp[$k]['values_mw'][] = $vv['value_mw'];
            }
            $__skInv['conflict_set'] = array_values(array_map(function ($g) {
                $g['rows_count'] = count($g['rows']);
                $g['rows'] = array_slice($g['rows'], 0, 12);
                $g['values_mw'] = array_slice($g['values_mw'], 0, 12);
                return $g;
            }, $__grp));
            $__skInv['violations'] = array_slice($__skViol, 0, 24);
            $warnings[] = 'UNIT SKIP LOAD: ' . count($__skViol)
                . ' baris berada di dalam forbidden band; conflict set dilampirkan pada Unit Skip Load Invariant.';
        }
        $GLOBALS['__pp_skip_load_invariant'] = $__skInv;
    }

    $warnings = array_merge($warnings, pp_validate_startup($genRows, $d3, $model));

    for ($row = 0; $row < $nRows; $row++) {
        $ie    = (float)$ieVals[$row];
        $disp  = (float)($input['data2'][$row]['dispatch'] ?? 0);
        $effLo = (float)$baseLoArr[$row];
        $effHi = (float)$effHiArr[$row];
        if (isset($sim['flags'][$row])) $warnings[] = 'Row ' . ($row + 1) . ': ' . $sim['flags'][$row];
        $data[] = $account_row($genRows[$row], $ie, $disp, $input['data1'][$row]['time'], $effLo, $effHi);
    }
    // Tahap 3: stamp actual rows (HL & Export PLN come straight from the actual sheet)
    foreach ($actualRows as $ri => $a) {
        if (!isset($data[$ri])) continue;
        if (isset($a['hl'])     && $a['hl'] !== ''     && $a['hl'] !== null)     $data[$ri]['HL'] = round((float)$a['hl'], 3);
        if (isset($a['export']) && $a['export'] !== '' && $a['export'] !== null) $data[$ri]['Export_PLN'] = round((float)$a['export'], 2);
        $data[$ri]['Actual'] = 1;
    }
    if ($actualLastRow > 0) {
        $simStart = $actualLastRow + 1;                      // first simulated row (1-based)
        $hm = function ($r) { $m = $r * 30; return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60); };
        $warnings[] = sprintf('Actual Data applied for rows 1–%d (up to %s). Simulation window is %s–00:00; the optimizer does not change the actual period.',
            $actualLastRow, $hm($actualLastRow), $simStart <= $nRows ? $hm($simStart) : '—');
    }

    /* ---- Min PGN Flow check moved below (after the per-row ENERGY PGN REAL TIME
     *      is computed against Fixed Flow Jababeka). See the "ENERGY PGN REAL TIME"
     *      block in the DAILY AGGREGATION section. */


    /* ---- PLN export hard-range + ramp validation (Revisi Sec.8/10). Range Min/Max
     *      is a hard band and export should not jump more than 20 MW / 30 min. These
     *      are surfaced as explicit warnings (the controllable dispatch is already
     *      clamped into the band; residual excursions come from required must-run
     *      blocks / IE that cannot be curtailed). */
    $rng    = $model['pln_export_priority']['range'] ?? [];
    $rMaxC  = (float)($rng['max'] ?? INF);
    $rMinC  = (float)($rng['min'] ?? 0);
    $overN = 0; $underN = 0; $overMax = 0.0; $overRow = 0;
    $rampWarnN = 0; $rampWarnMax = 0.0; $rampWarnRow = 0;     // 20 < d <= 30 (emergency band)
    $rampRejN  = 0; $rampRejMax  = 0.0; $rampRejRow  = 0;     // d > 30 (reject)
    $prevExp = null;
    /* ADDENDUM FINAL (root cause hidden FAIL): re-emitter ini sebelumnya membandingkan export terhadap
     * Range GLOBAL ($rMaxC/$rMinC) sehingga pelanggaran terhadap Range-rule PER-ROW (mis. max 40 rows 1-16)
     * lolos TANPA evidence -> release gate FAIL. Kini memakai batas efektif per-row (range + range_rules). */
    $peER = $model['pln_export_priority'] ?? [];
    $rMinRowER = array_fill(0, count($data), (float)($peER['range']['min'] ?? 0));
    $rMaxRowER = array_fill(0, count($data), (float)($peER['range']['max'] ?? 1e9));
    foreach (($peER['range_rules'] ?? []) as $zzE) {
        if (!is_array($zzE)) continue;
        $zs = max(1, (int)($zzE['start'] ?? 1)); $ze = min(count($data), (int)($zzE['stop'] ?? count($data)));
        for ($zr = $zs - 1; $zr <= $ze - 1; $zr++) {
            $rMinRowER[$zr] = max($rMinRowER[$zr], (float)($zzE['min'] ?? 0));
            $rMaxRowER[$zr] = min($rMaxRowER[$zr], (float)($zzE['max'] ?? 1e9));
        }
    }
    $overRowsER = []; $underRowsER = [];
    foreach ($data as $idx => $rw) {
        $e = (float)($rw['Export_PLN'] ?? 0);
        if ($e > $rMaxRowER[$idx] + 0.51) { $overN++; $overRowsER[] = sprintf('row %d: %.1f > max %.1f', $idx + 1, $e, $rMaxRowER[$idx]); if ($e - $rMaxRowER[$idx] > $overMax - $rMaxC) { $overMax = $e; $overRow = $idx + 1; $rMaxC = $rMaxRowER[$idx]; } }
        if ($e < $rMinRowER[$idx] - 0.51) { $underN++; $underRowsER[] = sprintf('row %d: %.1f < min %.1f', $idx + 1, $e, $rMinRowER[$idx]); }
        if ($prevExp !== null) {
            $d = abs($e - $prevExp);
            if ($d > 30.0 + 1e-6)     { $rampRejN++;  if ($d > $rampRejMax)  { $rampRejMax = $d;  $rampRejRow = $idx + 1; } }
            elseif ($d > 20.0 + 1e-6) { $rampWarnN++; if ($d > $rampWarnMax) { $rampWarnMax = $d; $rampWarnRow = $idx + 1; } }
        }
        $prevExp = $e;
    }
    if ($overN > 0)
        $warnings[] = sprintf('Invalid PLN export: Export PLN exceeds Range Max (must-run floor) on %d slot%s — evidence row-level: %s. Driven by required/must-run baseload that cannot be curtailed.',
            $overN, $overN > 1 ? 's' : '', implode('; ', array_slice($overRowsER, 0, 6)) . ($overN > 6 ? sprintf(' (+%d rows lain)', $overN - 6) : ''));
    if ($underN > 0)
        $warnings[] = sprintf('PLN export below Range Min for %d slot%s (IE / must-run limited) — evidence row-level: %s.', $underN, $underN > 1 ? 's' : '', implode('; ', array_slice($underRowsER, 0, 6)) . ($underN > 6 ? sprintf(' (+%d rows lain)', $underN - 6) : ''));
    if ($rampWarnN > 0)
        $warnings[] = sprintf('Export ramp exceeded 20 MW but kept below emergency 30 MW due to technical constraint: max %.0f MW at row %d (%d interval%s).',
            $rampWarnMax, $rampWarnRow, $rampWarnN, $rampWarnN > 1 ? 's' : '');
    if ($rampRejN > 0)
        $warnings[] = sprintf('Invalid export ramp: Export PLN changed by %.0f MW in one interval at row %d, exceeding the 30 MW emergency ramp limit (%d interval%s) — candidate dispatch rejected.',
            $rampRejMax, $rampRejRow, $rampRejN, $rampRejN > 1 ? 's' : '');

    /* =================================================================
     *  DAILY AGGREGATION
     * ================================================================= */
    $col = fn($k) => array_column($data, $k);
    $ie_total_MWh   = array_sum($col('IE'))       / 2;
    $ie_max         = max($col('IE'));
    $jbbk_total_MWh = array_sum($col('Jababeka')) / 2;
    $bbln_total_MWh = array_sum($col('BB_Total')) / 2;
    $disp_total_MWh = array_sum($col('Dispatch')) / 2;
    $exp_total_MWh  = array_sum($col('Export_PLN'))/ 2;
    $gasJ_BBTUD = array_sum($col('Gas_Jababeka')) / 2;
    $gasM_BBTUD = array_sum($col('Gas_MM2100'))   / 2;
    $gas_BBTUD  = array_sum($col('Total_Gas'))    / 2;

    /* =================================================================
     *  ENERGY PGN REAL TIME — per row (Revisi A/B).
     *  PGN supplies GTG 1-9 only; MM2100 fixed flow does not affect PGN residual.
     *  For each 30-min row:
     *    ENERGY PGN REAL TIME[row] = max(0, dailyEquiv(Gas GTG1-9[row])
     *                                       - Fixed Flow Energy Jababeka[row])
     *  dailyEquiv(x) = 24*x  (engine row-sum/2 = daily-total convention, so a
     *  single row held all day = 24*row). Fixed Flow Jababeka per row is the
     *  manual Ctrl+Click override when set, otherwise the daily Jababeka quota.
     *  ESTIMATION PGN TOTAL = sum(ENERGY PGN REAL TIME)/48  -> daily BBTUD.
     * ================================================================= */
    $qGas   = $model['gas_quota'] ?? [];
    $ffVolJ = (float)(($qGas['pep'] ?? 0) + ($qGas['akasia'] ?? 0) + ($qGas['baskara'] ?? 0) + ($qGas['bbg'] ?? 0));   // §2: + BBG (MMSCFD)
    $ffVolM = (float)(($qGas['pep_kp72'] ?? 0) + ($qGas['pertagas_kp72'] ?? 0) + ($qGas['akasia_kp72'] ?? 0) + ($qGas['baskara_kp72'] ?? 0));
    $ghvJ   = (float)($model['ghv_jababeka'] ?? 1034.7564);   // dipakai konversi pgnRT (konsisten kolom display)
    $mffJ = []; $mffM = [];
    foreach (($model['manual_fixed_flows'] ?? []) as $mf) {
        $ri = (int)($mf['row'] ?? 0) - 1; if ($ri < 0) continue;
        $area = strtoupper((string)($mf['area'] ?? ''));
        if ($area === 'JABABEKA') $mffJ[$ri] = (float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0);
        elseif ($area === 'MM2100') $mffM[$ri] = (float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0);
    }
    $pgnRT = [];
    foreach ($data as $pi => $prw) {
        $ffJ = array_key_exists($pi, $mffJ) ? $mffJ[$pi] : $ffVolJ;     // per-row Fixed Flow Jababeka (MMSCFD)
        $gasJrowDaily = 24.0 * (float)($prw['Gas_Jababeka'] ?? 0);      // daily-equivalent gas of GTG 1-9
        /* §1 PROMPT MM2100 REDESIGN (koreksi user): pembanding Min PGN Flow adalah
         * FLOW PGN (MMSCFD) = EnergyPGN_RT (BBTUD) / GHV_Jababeka * 1000 — bukan BBTUD langsung.
         * $pgnRT tetap menyimpan BBTUD (konsisten kolom display); konversi dilakukan saat cek. */
        $pgnRT[$pi] = $gasJrowDaily - ($ffJ * $ghvJ / 1000.0);
    }
    $estPGN_perrow = array_sum($pgnRT) / max(1, $nRows);               // ESTIMATION PGN TOTAL (daily BBTUD)

    /* ---- Min PGN Flow check on ENERGY PGN REAL TIME (Revisi Sec.C). Each row's
     *      daily-equivalent PGN flow must stay >= Min PGN Flow when set. Manual
     *      Fixed Flow Jababeka rows are not auto-adjusted (only flagged). */
    $minPgn = (float)($model['min_pgn_flow'] ?? 0);
    if ($minPgn > 0) {
        $belowPgn = 0; $firstBelow = 0; $belowManual = 0;
        /* §3.2: acuan = FLOW PGN REAL TIME (MMSCFD) = EnergyPGN_RT / GHV PGN * 1000. */
        $ghvPgnW = (float)($model['ghv_pgn'] ?? 0); if ($ghvPgnW <= 1e-9) $ghvPgnW = $ghvJ;
        foreach ($pgnRT as $ri => $val) {
            $flow = $val / max(1e-9, $ghvPgnW) * 1000.0;
            if ($flow < $minPgn - 1e-6) { $belowPgn++; if (!$firstBelow) $firstBelow = $ri + 1; if (array_key_exists($ri, $mffJ)) $belowManual++; }
        }
        if ($belowPgn > 0)
            $warnings[] = sprintf('FLOW PGN REAL TIME below Min PGN Flow (%.2f MMSCFD) on %d slot(s) (from row %d)%s. Reduce Fixed Flow Jababeka or add GTG 1-9 load if feasible.',
                $minPgn, $belowPgn, $firstBelow, $belowManual > 0 ? sprintf('; %d on manual-fixed Jababeka flow (left unchanged)', $belowManual) : '');
    }
    $coal_total = array_sum($col('Total_Coal'));
    $re_total_MWh = (array_sum($col('RE_BB1')) + array_sum($col('RE_BB2'))) / 2;

    /* ---- Startup gas penalty (Sec.16): each OFF->ON start consumes extra gas for
     *      firing/purge/warming. GTG 1-6 = 0.08 BBTUD/start, GTG 7-10 = 0.20
     *      BBTUD/start. Added to Total Gas, the quota demand (so it can create/raise
     *      a shortage), and cost. */
    /* ZERO STARTUP GAS PENALTY (keputusan domain final): default 0.0 — satu semantik dgn helper. */
    $spen = $model['startup_penalty'] ?? [];
    $pen16 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
    $pen710 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
    $startEvents16 = 0; $startEvents710 = 0; $penEventRows = [];   // [row0based, penalty] per start event — utk atribusi actual (Bagian C)
    $penUnits = []; $initRunning = [];   /* FIX: sebelumnya kedua inisialisasi ini tertelan ke dalam
                                          * komentar // di atas sehingga PHP 8 melempar
                                          * "Undefined variable $penUnits" pada setiap run tanpa
                                          * start event, dan warning tsb dapat mengotori body JSON. */
    foreach (['g1','g2','g3','g4','g5','g6'] as $u) {
        if (($genRows[0][$u] ?? 0) >= 1) $initRunning[] = strtoupper($u);
        $n = 0; for ($k = 1; $k < $nRows; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) { $n++; $penEventRows[] = [$k, $pen16]; }
        if ($n > 0) { $startEvents16 += $n; $penUnits[] = strtoupper($u) . ($n > 1 ? '×' . $n : ''); }
    }
    foreach (['g7','g8','g9','g10'] as $u) {
        if (($genRows[0][$u] ?? 0) >= 1) $initRunning[] = strtoupper($u);
        $n = 0; for ($k = 1; $k < $nRows; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) { $n++; $penEventRows[] = [$k, $pen710]; }
        if ($n > 0) { $startEvents710 += $n; $penUnits[] = strtoupper($u) . ($n > 1 ? '×' . $n : ''); }
    }
    $startupGasPenalty = $startEvents16 * $pen16 + $startEvents710 * $pen710;
    $gas_BBTUD += $startupGasPenalty;

    /* ---- gas quota / GHV (Sec.10) ------------------------------------ */
    $q     = $model['gas_quota'];
    $price = $model['price'];
    $ghvJ  = (float)($model['ghv_jababeka'] ?? 1000);
    $ghvM  = (float)($model['ghv_mm2100']   ?? 1000);
    $action=strtolower(trim((string)($model['gas_shortage_action']??'none')));
    if($action==='flag shortage only'||$action==='flag_shortage_only')$action='none';
    elseif($action==='add lng to cover'||$action==='add_lng_to_cover')$action='add_lng';
    elseif($action==='use distillate to cover'||$action==='use_distillate_to_cover')$action='use_distillate';
    $addLng = (float)($model['additional_lng'] ?? 0);
    $e = fn($vol, $ghv) => $vol * $ghv / 1000.0;

    /* PROMPT GAS QUOTA MMSCFD/BBG: kuota fixed flow Jababeka = (PEP+AKASIA+BASKARA+BBG)
     *   MMSCFD x GHV Jababeka / 1000 (BBTUD) — konsisten dgn $ffVolJ dan Est_FF_J. */
    $quotaJ = (float)(($q['pep'] ?? 0) + ($q['akasia'] ?? 0) + ($q['baskara'] ?? 0) + ($q['bbg'] ?? 0)) * $ghvJ / 1000.0;
    $quotaM = (float)(($q['pep_kp72'] ?? 0) + ($q['pertagas_kp72'] ?? 0) + ($q['akasia_kp72'] ?? 0) + ($q['baskara_kp72'] ?? 0));
    $usedJ = min($gasJ_BBTUD, $quotaJ);
    $usedM = min($gasM_BBTUD, $quotaM);
    /* V8: kuota KP72 0 tetapi sumber MM2100 sah (Actual Energy / fixed flow manual MM2100) -> gas yang benar-benar
     * dipakai unit MM2100 tetap DIHARGAI (bucket PEP KP72, ditandai unmapped) — V7 menghargainya 0. */
    if ($quotaM <= 1e-9 && $gasM_BBTUD > 1e-9 && (string)getenv('PP_V8_GE_GAS_GATE') !== '0' && function_exists('pp_v8_mm2100_source') && pp_v8_mm2100_source($model)['legal']) $usedM = $gasM_BBTUD;
    /* PGN supplies GTG 1-9 only; MM2100 fixed flow does not affect PGN residual.
     *   PGN need = daily Jababeka PGN (sum of per-row ENERGY PGN REAL TIME / 48)
     *   plus the startup gas penalty. MM2100 residual is intentionally excluded. */
    $pgnNeed = $estPGN_perrow + $startupGasPenalty;

    $pgnPipeQuota = (float)($q['pgn_pipe'] ?? 0);
    $lngQuota     = (float)($q['lng'] ?? 0) + (in_array($action, ['add_lng','mixed_lng_distillate'], true) ? $addLng : 0);
    $pgnTotalQuota = $pgnPipeQuota + $lngQuota;

    $gas_ok = true; $shortage = 0.0; $dist_total_litres = 0.0; $dist_units = [];
    $gasTol = 0.05;   // Revisi Sec.2.1: small rounding tolerance, not a target inflation

    if ($pgnNeed <= $pgnTotalQuota + $gasTol) {
        /* PROMPT LNG_FIXED (koreksi user): LNG = FIXED CONTRACT must-take sesuai input —
         * TIDAK dikurangi/di-residualkan. LNG Used = LNG quota (incl. Additional LNG);
         * PGN Pipe = kebutuhan sisa, dan ENGINE wajib menaikkan pemakaian gas (final top-up)
         * sampai Pipe masuk window [quota-0.04, quota]. Identitas: PGN TOTAL = PIPE + LNG. */
        $lngUsed  = $lngQuota;
        $pipeUsed = max(0.0, $pgnNeed - $lngUsed);
        if ($pipeUsed > $pgnPipeQuota + 1e-9) $pipeUsed = $pgnPipeQuota;   // safety clamp
    } else {
        $shortage = $pgnNeed - $pgnTotalQuota;
        $pipeUsed = $pgnPipeQuota;
        $lngUsed  = $lngQuota;
        if ($action === 'use_distillate' || $action === 'mixed_lng_distillate') {
            /* ===== PROMPT DISTILLATE BACKWARD CONCENTRATION §4/§5/§6/§10 =====
             * ROOT CAUSE lama (selisih Recommended vs Summary): level tunggal (mis. 30%) diterapkan
             * UNIFORM ke SELURUH energi harian unit (48 slot) -> Consumed = 30% x energi_harian_penuh
             * (≫ shortage), sedangkan Recommended = energi shortage. Dua formula berbeda.
             *
             * PERBAIKAN: alokasi PER-SLOT dari slot eligible PALING AKHIR, bergerak MUNDUR per 30
             * menit (§4). Tiap slot 100% dulu (§5); hanya SATU slot residual terakhir memakai level
             * diskrit terkecil (30/50/75/100) yang menutup sisa. Consumed dihitung row-level dari
             * load aktual tiap slot (§6) -> $dist_total_litres = Σ liter aktual = Summary = Recommended
             * (satu sumber kebenaran, §3/§10). Urutan unit tetap Unit Priority Distillate (§7):
             * G1-G6 dari input, lalu fallback G7,G10,G8,G9 hanya bila G1-G6 exhausted.
             * Konversi: calc_fuel_dist(load) = liter per slot 30-menit pada 100% (faktor project
             * 0.8424x19400x2.2046, sudah per-slot). Energi gas yg digantikan per slot = calc_fuel(load)/2. */
            $STAGES = [0.30, 0.50, 0.75, 1.00];
            $remaining = $shortage;                                       // Required Volume (BBTUD energi)
            /* BATAS MANUAL OPERATOR (opsi popup "masukkan jumlah distillate secara manual"):
             * operator menyetujui volume liter tertentu, bukan seluruh kebutuhan. Liter itu
             * dikonversi ke energi memakai basis konversi yang sama (satu sumber kebenaran), lalu
             * menjadi PLAFON penjadwalan. Sisa yang tidak tertutup tetap muncul sebagai shortage
             * sehingga hasilnya tidak dapat dipublikasikan — persis seperti tanpa distillate. */
            /* KEBUTUHAN dan BUDGET PENJADWALAN dipisah tegas.
             * BUG-08 (regresi paket sebelumnya): plafon manual dulu hanya memotong $remaining,
             * sedangkan kredit gas dihitung sebagai ($shortage - $residual) — sehingga rencana
             * memperoleh kredit sebesar SELURUH shortage walau liter yang dijadwalkan hanya
             * sebagian kecil. Akibatnya plafon 1 liter "menyelesaikan" defisit 4,74 BBTUD.
             * Kredit gas kini dihitung dari energi yang BENAR-BENAR disubstitusi. */
            $distRequirement = $remaining;                    // kebutuhan sebenarnya (BBTUD)
            $capLitres = null;                                // plafon liter operator (null = tanpa plafon)
            /* `>= 0`, bukan `> 0`: plafon 0 adalah otorisasi NOL yang WAJIB ditegakkan, bukan
             * tanda "tidak ada plafon". Lihat sanitasi pada pp_normalize_fuel_action(). */
            $userLitreCap = $model['distillate_user_limit_litres'] ?? null;
            if (is_numeric($userLitreCap) && (float)$userLitreCap >= 0) {
                $cvb = pp_distillate_conversion($model);
                $capBBTU = ((float)$userLitreCap * $cvb['btu_per_litre']) / 1000000000.0;
                $GLOBALS['__pp_gs_dist_user_cap'] = ['litres' => (float)$userLitreCap,
                    'bbtud' => round($capBBTU, 6), 'required_bbtud' => round($distRequirement, 6),
                    'applied' => ($capBBTU < $distRequirement)];
                $remaining = min($remaining, $capBBTU);
                $capLitres = (float)$userLitreCap;          // PLAFON LITER: ditegakkan persis, lihat di bawah
            }
            /* ===== DASAR KEPUTUSAN YANG TIDAK BERGANTUNG PLAFON (monotonicity) =================
             * Substitusi yang BENAR-BENAR dijadwalkan wajib tunduk pada plafon operator — itu yang
             * dilaporkan. Tetapi screening decommit dan gerbang export minimization TIDAK boleh
             * memakai angka yang bergantung plafon, karena plafon adalah batas PASOKAN, bukan
             * tujuan dispatch. Bila mereka memakainya, plafon yang lebih besar membuat rencana yang
             * belum diperbaiki tampak lebih sehat, perbaikan tidak dijalankan, dan hasil akhirnya
             * justru lebih buruk daripada plafon yang lebih kecil (lihat pp_decision_gas()).
             *
             * Yang dipakai di sini adalah energi yang DIOTORISASI operator (plafon dikonversi ke
             * energi, dibatasi kapasitas FISIK slot), BUKAN energi yang akhirnya benar-benar
             * terjadwal. Bedanya menentukan segalanya:
             *
             *   - energi TERJADWAL adalah fungsi non-linear dari rencana yang sedang dicari, maka
             *     memakainya sebagai dasar keputusan membuat keputusan dan rencana saling mengejar;
             *   - energi DIOTORISASI hanya fungsi dari plafon, naik monoton terhadap plafon, dan
             *     tidak bergantung pada rencana.
             *
             * Dengan dasar ini, plafon yang lebih besar SELALU melonggarkan persoalan yang sama —
             * persis seperti Additional LNG menaikkan kuota efektif pada mode add_lng — sehingga
             * himpunan rencana feasible hanya dapat membesar, tidak pernah menyempit. Angka ini
             * dipakai HANYA untuk keputusan, tidak pernah untuk pelaporan pemakaian gas. */
            $physCapBBTU = 0.0;
            foreach ($data as $idxPC => $rwPC) {
                if (isset($actualRows[$idxPC])) continue;
                foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uPC)
                    $physCapBBTU += calc_fuel($d3, $uPC, (float)($rwPC[strtoupper($uPC)] ?? 0)) / 2.0;
            }
            $authBBTU = ($capLitres !== null)
                ? (((float)$capLitres * pp_distillate_conversion($model)['btu_per_litre']) / 1000000000.0)
                : INF;                                   // tanpa plafon = otorisasi tak terbatas
            $GLOBALS['__pp_dist_offset_authorized'] = max(0.0, min($authBBTU, $physCapBBTU));
            /* ANGGARAN TERPAKU (lihat catatan pada korektor window gas): selama koreksi dispatch,
             * substitusi distillate tidak boleh ikut tumbuh mengejar shortage — kalau ikut tumbuh,
             * lever dispatch menjadi mati. Kebutuhan ($distRequirement) TIDAK diubah, sehingga
             * residual tetap dilaporkan jujur dan menyusut sendiri ketika dispatch mendekati
             * window. Plafon operator tetap berlaku di atas pakuan ini. */
            /* ===== PENJADWALAN DISTILLATE DIPAKU PERSIS (bukan sekadar anggarannya) ============
             * Memaku ANGGARAN ternyata tidak cukup. Alokasi greedy bekerja pada level diskret,
             * sehingga ia tidak pernah mendarat persis pada anggaran: setiap probe korektor
             * menghasilkan offset yang berbeda sampai 0,14 BBTUD, gas neto melompat-lompat, dan
             * korektor menyimpulkan tidak ada probe yang memperbaiki keadaan.
             * Yang dipaku karena itu adalah PENUGASAN SEL-nya sendiri — baris, unit, dan level yang
             * sama persis diterapkan ulang. Dengan offset benar-benar konstan, gas neto bergerak
             * satu-untuk-satu mengikuti dispatch, dan window dapat dicapai tanpa digeser. */
            $__schedPin = $model['__dist_schedule_pin'] ?? null;
            if (is_array($__schedPin) && $__schedPin) {
                foreach ($__schedPin as $pc) {
                    $idxP = (int)($pc[0] ?? -1); $uP = (string)($pc[1] ?? ''); $stP = (float)($pc[2] ?? 0);
                    if ($idxP < 0 || $uP === '' || $stP <= 0) continue;
                    if (isset($actualRows[$idxP]) || !isset($data[$idxP])) continue;
                    $UP = strtoupper($uP);
                    $loadP = (float)($data[$idxP][$UP] ?? 0);
                    if ($loadP < 1) continue;                       // sel tidak lagi sah -> lewati
                    $gasP = calc_fuel($d3, $uP, $loadP) / 2.0;
                    if ($gasP <= 1e-12) continue;
                    $litP = calc_fuel_dist($d3, $uP, $loadP) * $stP;
                    if ($capLitres !== null && $dist_total_litres + $litP > $capLitres + 1e-6) continue;
                    $data[$idxP]['Dist_' . $UP]    = round((float)($data[$idxP]['Dist_' . $UP] ?? 0) + $litP, 1);
                    $data[$idxP]['Dist_Total']     = round((float)($data[$idxP]['Dist_Total'] ?? 0) + $litP, 1);
                    $data[$idxP]['DistMix_' . $UP] = $stP;
                    $dist_total_litres += $litP;
                    $distSubstitutedActual = ($distSubstitutedActual ?? 0.0) + $stP * $gasP;
                    $dist_units[$uP] = round(($dist_units[$uP] ?? 0) + $litP, 1);
                    $unitMaxFrac[$uP] = max($unitMaxFrac[$uP] ?? 0.0, $stP);
                    $mixByUnit[$uP]   = max($mixByUnit[$uP] ?? 0.0, $stP);
                }
                $remaining = 0.0;                                   // greedy di bawah berhenti seketika
            }
            $__pinB = $model['__dist_budget_pin_bbtud'] ?? null;
            if (is_numeric($__pinB) && (float)$__pinB > 0.0 && !(is_array($__schedPin) && $__schedPin)) {
                /* DIPAKU, BUKAN DIBATASI. `min(sisa, pakuan)` tidak cukup: ketika korektor
                 * menurunkan gas kotor, shortage ikut mengecil sehingga anggaran ikut mengecil dan
                 * substitusi menyusut seiring — gas NETO tidak bergerak sama sekali dan lever
                 * korektor tetap mati. Anggaran karena itu ditetapkan PERSIS pada nilai pakuan,
                 * sehingga setiap BBTUD yang dipangkas dispatch berpindah satu-untuk-satu ke gas
                 * neto. Plafon operator tetap berlaku di atasnya (ditegakkan per slot di bawah). */
                $remaining = (float)$__pinB;
            }
            $distBudget = $remaining;                         // budget penjadwalan (<= kebutuhan)
            $distSubstitutedActual = 0.0;                     // energi gas yang benar-benar digantikan
            $order16 = [];
            foreach (($model['unit_priority_dist'] ?? []) as $grp)
                foreach ((array)$grp as $u)
                    if (in_array($u, ['g1','g2','g3','g4','g5','g6'], true) && !in_array($u, $order16, true)) $order16[] = $u;
            foreach (['g1','g2','g3','g4','g5','g6'] as $u)
                if (!in_array($u, $order16, true)) $order16[] = $u;
            $fallback710 = ['g7','g10','g8','g9'];                        // §7.6 urutan fallback WAJIB
            /* energi gas harian per unit (future rows saja) — dipakai HANYA utk gate fallback §7.5 */
            $unitGasBBTUD = [];
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u) {
                $g = 0.0; foreach ($data as $ri => $rw) { if (isset($actualRows[$ri])) continue; $g += calc_fuel($d3, $u, $rw[strtoupper($u)] ?? 0); }
                $unitGasBBTUD[$u] = $g / 2;
            }
            $mixByUnit = [];                                              // ringkasan mix dominan per unit (utk warning/info)
            $unitMaxFrac = [];                                            // fraksi tertinggi yg terpakai unit (utk gate fallback)
            foreach (array_merge($order16, $fallback710) as $u) {
                if ($remaining <= 1e-9) break;
                $U = strtoupper($u);
                if (($unitGasBBTUD[$u] ?? 0) <= 1e-9) continue;           // unit tak running -> tak ada slot eligible
                if (in_array($u, $fallback710, true)) {
                    /* §7.5 gate: G7-G10 hanya bila SEMUA G1-G6 ber-energi sudah 100% (exhausted penuh) */
                    foreach ($order16 as $x)
                        if (($unitGasBBTUD[$x] ?? 0) > 1e-9 && ($unitMaxFrac[$x] ?? 0) < 1.0 - 1e-9) continue 2;
                }
                /* slot eligible unit = load>=1 & non-locked (§4.3/§9.19: tak boleh setelah stop / load 0). */
                $eligible = [];
                foreach ($data as $idx => $rw) {
                    if (isset($actualRows[$idx])) continue;                // §4.4/§9.17: locked/TIME PASSED Y tak disentuh
                    if ((float)($rw[$U] ?? 0) >= 1) $eligible[] = $idx;
                }
                if (!$eligible) continue;
                /* BACKWARD: slot paling akhir dulu -> mundur per 30 menit (§4.1/§4.2/§4.3). */
                rsort($eligible);
                $usedFrac = 0.0;
                foreach ($eligible as $idx) {
                    if ($remaining <= 1e-9) break;
                    $load  = (float)($data[$idx][$U] ?? 0);
                    $gasSlot = calc_fuel($d3, $u, $load) / 2.0;            // BBTUD energi gas slot ini (per 30-min)
                    if ($gasSlot <= 1e-12) continue;
                    /* §5: 100% dulu. Slot yang TIDAK muat lagi TIDAK dipaksakan di sini.
                     *
                     * AKAR PENYEBAB LAMA: slot residual terakhir memakai level diskret TERKECIL yang
                     * MENUTUPI sisa. Level itu menggantikan energi jauh LEBIH BANYAK daripada sisa
                     * (overshoot terukur 0,13 BBTUD pada satu slot), sehingga gas NETO jatuh ke
                     * BAWAH lantai window take-or-pay dan hasilnya ditolak FINAL_GAS_QUOTA_FAILED.
                     * Korektor gas-window tidak dapat menolong: setiap tambahan gas yang ia dispatch
                     * langsung disubstitusi kembali oleh aturan yang sama, sehingga levernya inert.
                     *
                     * Sekarang pass greedy ini TIDAK PERNAH melampaui sisa: dipilih level TERBESAR
                     * yang masih muat. Slot yang level terkecilnya pun tidak muat dilewati dan
                     * diserahkan ke PENCARIAN LANGKAH PENUTUP di bawah, yang memilih kombinasi
                     * slot x unit x level dengan overshoot MINIMUM sehingga gas neto mendarat DI
                     * DALAM window. Window dan toleransi tidak diubah sama sekali. */
                    $f = null;
                    foreach (array_reverse($STAGES) as $stFit0)
                        if ($stFit0 * $gasSlot <= $remaining + 1e-9) { $f = $stFit0; break; }
                    if ($f === null) continue;            // tidak muat: serahkan ke langkah penutup
                    /* ===== PLAFON LITER OPERATOR DITEGAKKAN PERSIS ==============================
                     * Guard lama hanya menolak langkah yang melampaui SISA BUDGET lebih dari 50%,
                     * sehingga konsumsi masih boleh melewati volume yang disetujui operator.
                     * Terukur: plafon 65.760 liter menghasilkan konsumsi 66.462,1 liter (+702),
                     * dan plafon 131.520 liter menghasilkan 131.640,7 liter (+120,7) — rencana
                     * membakar bahan bakar yang TIDAK disetujui, lalu rekonsiliasi engine sendiri
                     * menyatakan `FUEL_ACTION_NOT_APPLIED` sehingga hasilnya tidak dapat
                     * dipublikasikan. Dua kerugian sekaligus.
                     *
                     * Sekarang plafon adalah plafon: dipilih level diskrit TERBESAR yang tidak
                     * melampaui kebutuhan slot DAN tidak melampaui plafon liter. Bila level
                     * terkecil pun tidak muat, slot dilewati. Sisa yang tidak tertutup tetap
                     * muncul sebagai residual dan dilaporkan apa adanya. Tanpa plafon, perilaku
                     * lama tidak berubah sama sekali. */
                    if ($capLitres !== null) {
                        $fFit = null;
                        foreach (array_reverse($STAGES) as $stFit) {
                            if ($stFit > $f + 1e-12) continue;                  // jangan melebihi kebutuhan slot
                            $litFit = calc_fuel_dist($d3, $u, $load) * $stFit;
                            if ($dist_total_litres + $litFit <= $capLitres + 1e-6) { $fFit = $stFit; break; }
                        }
                        if ($fFit === null) continue;                            // plafon tidak memuat langkah terkecil
                        $f = $fFit;
                    }
                    $distLitreSlot = calc_fuel_dist($d3, $u, $load) * $f;  // Consumed slot (row-level, load-dependent §6)
                    $data[$idx]['Dist_' . $U]  = round($distLitreSlot, 1);
                    $data[$idx]['Dist_Total']  = round(($data[$idx]['Dist_Total'] ?? 0) + $distLitreSlot, 1);
                    $data[$idx]['DistMix_' . $U] = $f;                     // §11: mix per cell utk warna+tooltip
                    $dist_total_litres += $distLitreSlot;                 // Σ liter aktual = Summary = Recommended
                    $remaining -= $f * $gasSlot;                          // kurangi Required dgn energi yg digantikan
                    $distSubstitutedActual = ($distSubstitutedActual ?? 0.0) + $f * $gasSlot;   // substitusi NYATA
                    $usedFrac = max($usedFrac, $f);
                    $dist_units[$u] = round(($dist_units[$u] ?? 0) + $distLitreSlot, 1);
                }
                $unitMaxFrac[$u] = $usedFrac;
                if ($usedFrac > 0) $mixByUnit[$u] = $usedFrac;
            }
            /* ==========================================================================================
             * LANGKAH PENUTUP DISKRET — PEMILIHAN KOMBINASI slot x unit x level DENGAN OVERSHOOT MINIMUM.
             *
             * Pass greedy di atas sengaja tidak pernah melampaui sisa kebutuhan, sehingga selalu
             * menyisakan residual kecil `r`. Residual itu harus ditutup oleh energi diskret, dan
             * energi diskret jarang persis sama dengan `r`. Yang menentukan lulus/gagal adalah gas
             * NETO, dan gas neto sah hanya bila total substitusi berada pada pita:
             *
             *     offset_total  in  [ kebutuhan , kebutuhan + LEBAR_WINDOW ]
             *
             * Batas bawah pita berasal dari batas ATAS kuota (hard cap: gas neto tidak boleh melewati
             * kuota), batas atas pita berasal dari LANTAI window take-or-pay. Keduanya diambil apa
             * adanya dari kontrak — tidak ada window yang digeser dan tidak ada toleransi yang
             * diperbesar.
             *
             * Pencarian bersifat EKSAK, bukan heuristik:
             *   1. seluruh kenaikan level yang SAH dienumerasi (slot future non-locked x unit yang
             *      berhak x level 30/50/75/100 di ATAS level slot itu saat ini);
             *   2. kandidat yang melampaui plafon liter operator dibuang lebih dulu — plafon tetap
             *      plafon;
             *   3. langkah TUNGGAL dicari lebih dahulu: energi dalam pita, overshoot terkecil;
             *   4. bila tidak ada, PASANGAN langkah dicari lengkap lewat kandidat terurut + binary
             *      search (meet-in-the-middle), tetap memilih total terkecil di dalam pita;
             *   5. tie-break deterministik (energi, lalu baris, unit, level) sehingga hasil dapat
             *      direproduksi checksum-nya.
             *
             * Kandidat dengan energi di atas `r + lebar pita` tidak dapat menjadi bagian solusi mana
             * pun — pemangkasannya EKSAK (dominance), bukan penyederhanaan.
             *
             * Bila tidak ada kombinasi yang mendarat di dalam pita, TIDAK ada angka yang dipaksakan:
             * engine menerbitkan conflict certificate (tetangga terdekat di bawah dan di atas beserta
             * slot/unit/level penghasilnya, dan langkah diskret minimum) supaya kegagalan dapat
             * diaudit, bukan sekadar FINAL_GAS_QUOTA_FAILED generik. */
            $__winBand = 0.04;                                  // lebar window take-or-pay, dibaca apa adanya
            /* Sasaran langkah penutup adalah ANGGARAN yang berlaku (terpaku bila dipaku), bukan
             * kebutuhan penuh — memaksa menutup kebutuhan penuh saat dipaku justru membatalkan
             * pakuan itu sendiri. */
            $__need    = (float)($distBudget ?? ($distRequirement ?? $shortage));
            $__r       = $__need - (float)($distSubstitutedActual ?? 0.0);
            $GLOBALS['__pp_dist_closing'] = null;
            /* Saat anggaran DIPAKU (probe korektor window), langkah penutup TIDAK dijalankan.
             * Kalau ia tetap berjalan, setiap probe menambah satu langkah overshoot baru di atas
             * pakuan, offset ikut bergeser, dan gas neto kembali tidak merespons perubahan
             * dispatch — pakuannya batal dengan sendirinya. Selama dipaku, offset dibiarkan tetap
             * pada hasil isian greedy sehingga lever dispatch bergerak satu-untuk-satu. */
            $__pinned = (is_array($model['__dist_schedule_pin'] ?? null) && $model['__dist_schedule_pin'])
                        || (is_numeric($model['__dist_budget_pin_bbtud'] ?? null)
                            && (float)$model['__dist_budget_pin_bbtud'] > 0.0);
            if ($__r > 1e-9 && !$__pinned) {
                /* ---- unit yang BERHAK menerima tambahan (gate §7.5 dihormati apa adanya) ---- */
                $__allowUnits = [];
                foreach ($order16 as $x) if (($unitGasBBTUD[$x] ?? 0) > 1e-9) $__allowUnits[] = $x;
                $__g16Exhausted = true;
                foreach ($order16 as $x)
                    if (($unitGasBBTUD[$x] ?? 0) > 1e-9 && ($unitMaxFrac[$x] ?? 0) < 1.0 - 1e-9) { $__g16Exhausted = false; break; }
                if ($__g16Exhausted)
                    foreach ($fallback710 as $x) if (($unitGasBBTUD[$x] ?? 0) > 1e-9) $__allowUnits[] = $x;

                /* ---- enumerasi kenaikan level yang sah ---- */
                $__cand = []; $__candAll = [];                  // [energi, liter, idx, unit, level_baru, level_lama]
                $__diagRaw = 0; $__diagPruned = 0; $__diagMinE = null; $__diagMaxE = null;
                foreach ($__allowUnits as $uC) {
                    $UC = strtoupper($uC);
                    foreach ($data as $idxC => $rwC) {
                        if (isset($actualRows[$idxC])) continue;
                        $loadC = (float)($rwC[$UC] ?? 0);
                        if ($loadC < 1) continue;
                        $gasC = calc_fuel($d3, $uC, $loadC) / 2.0;
                        if ($gasC <= 1e-12) continue;
                        $f0 = (float)($data[$idxC]['DistMix_' . $UC] ?? 0.0);
                        $litFull = calc_fuel_dist($d3, $uC, $loadC);
                        /* PENURUNAN level ikut dienumerasi, bukan hanya kenaikan.
                         *
                         * Tanpa penurunan, pencarian hanya dapat MENAMBAH substitusi. Padahal
                         * ketika langkah diskret terkecil (0,1405 BBTUD) lebih lebar daripada
                         * window (0,04), satu-satunya cara mendarat di dalam pita adalah
                         * MENGGABUNGKAN satu kenaikan dengan satu penurunan pada sel lain —
                         * misalnya +0,1405 pada sel bernilai besar dan −0,05 pada sel bernilai
                         * kecil menghasilkan +0,0905 yang jatuh tepat di dalam pita. Dengan hanya
                         * kenaikan, kombinasi itu tidak pernah terbentuk dan engine terpaksa
                         * memilih overshoot yang melanggar lantai window. */
                        $__lvls = $STAGES; $__lvls[] = 0.0;
                        foreach ($__lvls as $stC) {
                            if (abs($stC - $f0) <= 1e-12) continue;
                            $dE = ($stC - $f0) * $gasC;
                            $__diagRaw++;
                            if ($__diagMinE === null || $dE < $__diagMinE) $__diagMinE = $dE;
                            if ($__diagMaxE === null || $dE > $__diagMaxE) $__diagMaxE = $dE;
                            $dL = $litFull * ($stC - $f0);
                            /* daftar LENGKAP dipertahankan untuk jalur (4b) dan certificate */
                            $__candAll[] = [$dE, $dL, $idxC, $uC, $stC, $f0];
                            if ($dE > $__r + $__winBand + 1e-9) { $__diagPruned++; continue; }   // dominance eksak utk (3)/(4)
                            $__cand[] = [$dE, $dL, $idxC, $uC, $stC, $f0];
                        }
                    }
                }
                /* urutan kanonik -> tie-break deterministik dan binary search yang sah */
                $__ord = function ($a, $b) {
                    if (abs($a[0] - $b[0]) > 1e-12) return $a[0] <=> $b[0];
                    if ($a[2] !== $b[2]) return $a[2] <=> $b[2];
                    if ($a[3] !== $b[3]) return strcmp((string)$a[3], (string)$b[3]);
                    return $a[4] <=> $b[4];
                };
                usort($__candAll, $__ord);
                usort($__cand, function ($a, $b) {
                    if (abs($a[0] - $b[0]) > 1e-12) return $a[0] <=> $b[0];
                    if ($a[2] !== $b[2]) return $a[2] <=> $b[2];
                    if ($a[3] !== $b[3]) return strcmp((string)$a[3], (string)$b[3]);
                    return $a[4] <=> $b[4];
                });
                $__lo = $__r - 1e-9; $__hi = $__r + $__winBand + 1e-9;
                $__fits = function (float $litTambahan) use ($capLitres, $dist_total_litres): bool {
                    return ($capLitres === null) || ($dist_total_litres + $litTambahan <= $capLitres + 1e-6);
                };
                $__pilih = null; $__mode = null;
                /* ---- (3) langkah TUNGGAL ---- */
                foreach ($__cand as $c) {
                    if ($c[0] < $__lo || $c[0] > $__hi) continue;
                    if (!$__fits($c[1])) continue;
                    $__pilih = [$c]; $__mode = 'SINGLE'; break;       // terurut menaik -> yang pertama = overshoot terkecil
                }
                /* ---- (4) PASANGAN langkah ---- */
                if ($__pilih === null && count($__candAll) > 1) {
                    /* Pasangan dicari pada daftar LENGKAP: kandidat bernilai negatif (penurunan
                     * level) dan kandidat besar yang sendirian di luar pita tetap sah sebagai
                     * PASANGAN. Memakai daftar yang sudah dipangkas akan membuang justru kombinasi
                     * yang mampu mendarat di dalam pita. */
                    $__cand = $__candAll;
                    $__n = count($__cand);
                    $__best = null;
                    for ($i = 0; $i < $__n; $i++) {
                        $a = $__cand[$i];
                        if ($a[0] > $__hi) break;
                        $needLo = $__lo - $a[0]; $needHi = $__hi - $a[0];
                        /* binary search batas bawah */
                        $loI = $i + 1; $hiI = $__n - 1; $posI = $__n;
                        while ($loI <= $hiI) { $mid = intdiv($loI + $hiI, 2);
                            if ($__cand[$mid][0] >= $needLo) { $posI = $mid; $hiI = $mid - 1; } else { $loI = $mid + 1; } }
                        for ($k = $posI; $k < $__n; $k++) {
                            $b = $__cand[$k];
                            if ($b[0] > $needHi) break;
                            if ($b[2] === $a[2] && $b[3] === $a[3]) continue;   // satu sel hanya satu level final
                            if (!$__fits($a[1] + $b[1])) continue;
                            $tot = $a[0] + $b[0];
                            if ($__best === null || $tot < $__best[0] - 1e-12) $__best = [$tot, $a, $b];
                            break;                                              // pasangan terkecil untuk a ini
                        }
                    }
                    if ($__best !== null) { $__pilih = [$__best[1], $__best[2]]; $__mode = 'PAIR'; }
                }
                /* ---- (4b) TIDAK ADA yang mendarat di pita: ambil langkah TERKECIL yang MENUTUPI --
                 * Granularitas distillate bisa lebih KASAR daripada lebar window (terukur: langkah
                 * sah terkecil 0,1405 BBTUD terhadap window selebar 0,04). Pada keadaan itu tidak
                 * ada kombinasi level yang dapat mendarat di dalam window pada tingkat gas kotor
                 * mana pun — yang harus bergerak adalah DISPATCH.
                 *
                 * Arah pelanggaran karena itu dipilih secara sengaja. Menyisakan kekurangan berarti
                 * gas neto MELAMPAUI kuota — pelanggaran batas KERAS yang tidak boleh diterbitkan.
                 * Menggantikan sedikit berlebih menempatkan gas neto di BAWAH lantai window, dan
                 * lantai itu dapat didaratkan kembali oleh korektor window dengan menaikkan
                 * dispatch — arah yang justru memiliki headroom. Karena anggaran distillate DIPAKU
                 * selama koreksi, setiap BBTUD yang dinaikkan dispatch berpindah satu-untuk-satu ke
                 * gas neto, sehingga window benar-benar tercapai tanpa digeser. */
                if ($__pilih === null) {
                    foreach ($__candAll as $c) {
                        if ($c[0] < $__lo) continue;
                        if (!$__fits($c[1])) continue;
                        $__pilih = [$c]; $__mode = 'OVERSHOOT_MINIMUM_LALU_KOMPENSASI_DISPATCH'; break;
                    }
                }
                /* ---- penerapan pemenang ---- */
                if ($__pilih !== null) {
                    $__ev = [];
                    foreach ($__pilih as $c) {
                        [$dE, $dL, $idxC, $uC, $stC, $f0] = $c;
                        $UC = strtoupper($uC);
                        $data[$idxC]['Dist_' . $UC]   = round((float)($data[$idxC]['Dist_' . $UC] ?? 0) + $dL, 1);
                        $data[$idxC]['Dist_Total']    = round((float)($data[$idxC]['Dist_Total'] ?? 0) + $dL, 1);
                        $data[$idxC]['DistMix_' . $UC] = $stC;
                        $dist_total_litres += $dL;
                        $distSubstitutedActual = ($distSubstitutedActual ?? 0.0) + $dE;
                        $dist_units[$uC] = round(($dist_units[$uC] ?? 0) + $dL, 1);
                        $unitMaxFrac[$uC] = max($unitMaxFrac[$uC] ?? 0.0, $stC);
                        $mixByUnit[$uC]   = max($mixByUnit[$uC] ?? 0.0, $stC);
                        $remaining = max(0.0, $remaining - $dE);
                        $__ev[] = ['row' => $idxC + 1, 'unit' => $UC, 'level_dari' => $f0, 'level_ke' => $stC,
                                   'energi_bbtud' => round($dE, 6), 'liter' => round($dL, 1)];
                    }
                    $GLOBALS['__pp_dist_closing'] = [
                        'schema' => 'co12-distillate-discrete-closing-v1', 'status' => 'RESOLVED',
                        'mode' => $__mode, 'residual_sebelum_bbtud' => round($__r, 6),
                        'pita_bbtud' => [round($__r, 6), round($__r + $__winBand, 6)],
                        'kandidat_dievaluasi' => count($__cand), 'langkah' => $__ev];
                } else {
                    /* ---- CONFLICT CERTIFICATE: tidak ada kombinasi diskret yang mendarat di pita ---- */
                    $__below = null; $__above = null; $__minStep = null;
                    foreach ($__cand as $c) {
                        if (!$__fits($c[1])) continue;
                        if ($__minStep === null || $c[0] < $__minStep) $__minStep = $c[0];
                        if ($c[0] < $__lo)      { if ($__below === null || $c[0] > $__below[0]) $__below = $c; }
                        elseif ($c[0] > $__hi)  { if ($__above === null || $c[0] < $__above[0]) $__above = $c; }
                    }
                    $fmtC = function ($c) use ($__need, $distSubstitutedActual) {
                        if ($c === null) return null;
                        return ['row' => $c[2] + 1, 'unit' => strtoupper((string)$c[3]), 'level' => $c[4],
                                'energi_bbtud' => round($c[0], 6), 'liter' => round($c[1], 1),
                                'offset_total_bbtud' => round(((float)($distSubstitutedActual ?? 0.0)) + $c[0], 6)];
                    };
                    $GLOBALS['__pp_dist_closing'] = [
                        'schema' => 'co12-distillate-discrete-closing-v1', 'status' => 'NO_FEASIBLE_COMBINATION',
                        'sebab' => 'tidak ada kombinasi level diskret yang menempatkan gas neto di dalam window '
                                 . 'tanpa melampaui kuota; plafon operator dan legalitas slot dihormati',
                        'residual_sebelum_bbtud' => round($__r, 6),
                        'pita_bbtud' => [round($__r, 6), round($__r + $__winBand, 6)],
                        'kandidat_dievaluasi' => count($__cand),
                        'diagnostik' => ['kenaikan_sah_total' => $__diagRaw, 'dipangkas_dominance' => $__diagPruned,
                                         'energi_terkecil_bbtud' => $__diagMinE === null ? null : round($__diagMinE, 6),
                                         'energi_terbesar_bbtud' => $__diagMaxE === null ? null : round($__diagMaxE, 6),
                                         'unit_berhak' => array_map('strtoupper', $__allowUnits)],
                        'langkah_diskret_minimum_bbtud' => $__minStep === null ? null : round($__minStep, 6),
                        'terdekat_di_bawah' => $fmtC($__below),
                        'terdekat_di_atas'  => $fmtC($__above),
                        'plafon_liter' => $capLitres,
                        'liter_terpakai' => round($dist_total_litres, 1)];
                }
            }
            /* Energi gas yang BENAR-BENAR digantikan distillate = budget yang terpakai.
             * Residual = kebutuhan dikurangi substitusi NYATA — bukan sisa budget — sehingga
             * plafon manual yang mengikat tetap meninggalkan residual dan rencana tetap gagal. */
            /* Penjadwalan yang BENAR-BENAR terwujud, untuk dipaku oleh korektor window. */
            $__sched = [];
            foreach ($data as $idxS => $rwS)
                foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uS) {
                    $fS = (float)($rwS['DistMix_' . strtoupper($uS)] ?? 0);
                    if ($fS > 0) $__sched[] = [$idxS, $uS, $fS];
                }
            $GLOBALS['__pp_dist_schedule'] = $__sched;
            $distSubstituted = max(0.0, $distSubstitutedActual ?? 0.0);
            $residual = max(0.0, ($distRequirement ?? $shortage) - $distSubstituted);
            $pgnNeed = $pgnTotalQuota + $residual;
            $gas_ok = ($residual <= 1e-6);
            /* §1 FINAL ACCOUNTING: distillate benar-benar MENGGANTIKAN energi gas sebesar
             * (shortage awal − residual). Gas efektif yang divalidasi = gas gross − offset ini.
             * Disimpan utk dipakai saat menyusun 'Total Gas Used (BBTUD)' (validator hilir). */
            $GLOBALS['__pp_dist_gas_offset'] = $distSubstituted;
            /* REKONSILIASI ENERGI<->LITER: kredit gas wajib setara dengan liter yang dijadwalkan.
             * Dilaporkan apa adanya supaya penyimpangan apa pun terlihat, bukan tersembunyi. */
            $cvbChk = pp_distillate_conversion($model);
            $litEnergy = ($dist_total_litres * $cvbChk['btu_per_litre']) / 1000000000.0;
            $GLOBALS['__pp_dist_energy_recon'] = [
                'requirement_bbtud' => round($distRequirement ?? $shortage, 6),
                'scheduling_budget_bbtud' => round($distBudget ?? $remaining, 6),
                'substituted_bbtud' => round($distSubstituted, 6),
                'residual_bbtud' => round($residual, 6),
                'scheduled_litres' => round($dist_total_litres, 1),
                'litres_energy_bbtud' => round($litEnergy, 6),
                'delta_bbtud' => round($litEnergy - $distSubstituted, 6),
                'consistent' => (abs($litEnergy - $distSubstituted) <= max(0.002, 0.01 * max($litEnergy, $distSubstituted))),
                'basis' => $cvbChk['basis'],
            ];
            /* §11/info: ringkas jumlah slot & fraksi terpakai per unit. */
            $distMixInfo = [];
            foreach ($mixByUnit as $u => $f) {
                $U = strtoupper($u); $nSlot = 0; $n100 = 0;
                foreach ($data as $rw) if (isset($rw['DistMix_' . $U]) && $rw['DistMix_' . $U] > 0) { $nSlot++; if ($rw['DistMix_' . $U] >= 1.0 - 1e-9) $n100++; }
                $distMixInfo[$U] = ['max_frac_pct' => round($f * 100), 'slots' => $nSlot, 'slots_100' => $n100];
            }
            if ($distMixInfo) $warnings[] = 'Distillate backward-concentrated (100% first, from last eligible slot): ' .
                implode(', ', array_map(fn($u, $d) => "$u {$d['slots_100']}x100%" . ($d['slots'] > $d['slots_100'] ? "+1x{$d['max_frac_pct']}%res" : '') . " ({$d['slots']} slot)", array_keys($distMixInfo), $distMixInfo)) .
                sprintf(' — total %.1f l/day (= Summary = Recommended).', $dist_total_litres);
            if (!$gas_ok) {
                $startCand = '';
                foreach ($order16 as $x) if (($unitGasBBTUD[$x] ?? 0) <= 1e-9) { $startCand = strtoupper($x); break; }
                $warnings[] = sprintf('Gas still short by %.3f BBTUD after distillate (all eligible slots of energized units at 100%%)%s',
                    $residual, $startCand ? " — next start candidate per Distillate Priority: $startCand (one unit, §8; not auto-started)" : ' — no further G1-G6 start candidate');
            }
            $GLOBALS['__pp_dist_mix'] = $mixByUnit;
        } else {
            $gas_ok = false;
            /* ===== PROMPT §8/§10: kandidat LNG vs DISTILLATE (rekomendasi — TIDAK auto-switch).
             * Distillate disimulasikan dgn algoritma BACKWARD PER-SLOT yang SAMA dgn jalur eksekusi
             * (100% dari slot akhir mundur; residual satu slot) tanpa mengubah $data -> $planLitres =
             * volume ter-SCHEDULE aktual = angka yang akan dikonsumsi bila user memilih distillate.
             * Biaya: LNG = BBTUD x 1000 x USD/MMBTU; Distillate = liter x USD/liter. */
            $STAGES = [0.30, 0.50, 0.75, 1.00];
            $ugb = [];
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u) {
                $g = 0.0; foreach ($data as $ri => $rw) { if (isset($actualRows[$ri])) continue; $g += calc_fuel($d3, $u, $rw[strtoupper($u)] ?? 0); }
                $ugb[$u] = $g / 2;
            }
            $ord16 = [];
            foreach (($model['unit_priority_dist'] ?? []) as $grp)
                foreach ((array)$grp as $u)
                    if (in_array($u, ['g1','g2','g3','g4','g5','g6'], true) && !in_array($u, $ord16, true)) $ord16[] = $u;
            foreach (['g1','g2','g3','g4','g5','g6'] as $u) if (!in_array($u, $ord16, true)) $ord16[] = $u;
            $fb710 = ['g7','g10','g8','g9'];
            $rem = $shortage; $planMix = []; $planLitres = 0.0; $planMaxFrac = [];
            foreach (array_merge($ord16, $fb710) as $u) {
                if ($rem <= 1e-9) break;
                $U = strtoupper($u);
                if (($ugb[$u] ?? 0) <= 1e-9) continue;
                if (in_array($u, $fb710, true)) {
                    foreach ($ord16 as $x) if (($ugb[$x] ?? 0) > 1e-9 && ($planMaxFrac[$x] ?? 0) < 1.0 - 1e-9) continue 2;
                }
                $eligible = [];
                foreach ($data as $idx => $rw) { if (isset($actualRows[$idx])) continue; if ((float)($rw[$U] ?? 0) >= 1) $eligible[] = $idx; }
                if (!$eligible) continue;
                rsort($eligible);                                         // backward: slot akhir dulu
                $usedFrac = 0.0;
                foreach ($eligible as $idx) {
                    if ($rem <= 1e-9) break;
                    $load = (float)($data[$idx][$U] ?? 0);
                    $gasSlot = calc_fuel($d3, $u, $load) / 2.0; if ($gasSlot <= 1e-12) continue;
                    $f = 1.00;
                    if ($rem < $gasSlot - 1e-12) foreach ($STAGES as $st) if ($st * $gasSlot >= $rem - 1e-9) { $f = $st; break; }
                    $planLitres += calc_fuel_dist($d3, $u, $load) * $f;
                    $rem -= $f * $gasSlot; $usedFrac = max($usedFrac, $f);
                }
                $planMaxFrac[$u] = $usedFrac;
                if ($usedFrac > 0) $planMix[$u] = $usedFrac;
            }
            $distResidual = max(0.0, $rem);
            $GLOBALS['__pp_gs_dist_scheduled_litres'] = $planLitres;      // §10: dipakai Recommended Distillate saat mode rekomendasi
            $costLNGCand  = $shortage * 1000.0 * (float)($price['lng'] ?? 0);
            $costDistCand = $planLitres * (float)($price['distillate'] ?? 0);
            $candList = [
                ['action' => 'add_lng', 'detail' => sprintf('Add LNG %.3f BBTUD', $shortage),
                 'cost_usd' => round($costLNGCand, 2), 'residual_bbtud' => 0.0, 'valid' => true],
                ['action' => 'use_distillate',
                 'detail' => 'Distillate backward 100%-first: ' . ($planMix
                     ? implode(', ', array_map(fn($u, $f) => strtoupper($u) . ' up to ' . round($f * 100) . '%', array_keys($planMix), $planMix))
                       . sprintf(' (%.1f l/day scheduled)', $planLitres)
                     : 'no eligible slot available'),
                 'cost_usd' => round($costDistCand, 2), 'residual_bbtud' => round($distResidual, 4),
                 'valid' => ($planMix && $distResidual <= 1e-6)],
            ];
            $validCands = array_filter($candList, fn($c) => $c['valid']);
            usort($validCands, fn($a, $b) => $a['cost_usd'] <=> $b['cost_usd']);
            $preferred = $validCands ? reset($validCands)['action'] : 'add_lng';
            $GLOBALS['__pp_gs_candidates'] = $candList;
            $GLOBALS['__pp_gs_preferred']  = $preferred;
            $warnings[] = sprintf(
                'GAS SHORTAGE %.3f BBTUD over PGN quota. NOT auto-switched. Options: add LNG +%.3f BBTUD (USD %.0f), or use distillate (%.1f litre/day scheduled, USD %.0f). Preferred by Total Cost: %s.',
                $shortage, $shortage, $costLNGCand, $planLitres, $costDistCand, strtoupper($preferred));
        }
    }
    $pgnUsed = $pipeUsed + $lngUsed;

    /* HARD RULE (user): the FINAL accepted dispatch must satisfy
     *   Gas Quota - 0.04 <= Total Gas Used <= Gas Quota.
     * If Total Gas Used exceeds the (effective) total quota, the candidate is NOT
     * within quota regardless of how the PGN/PEP/LNG split worked out — it becomes
     * a SHORTAGE recommendation (no auto-add). */
    $effTotalQuota = $gasQuotaTotal + (in_array($action, ['add_lng','mixed_lng_distillate'], true) ? $addLng : 0.0);
    if ($gas_BBTUD > $effTotalQuota + 0.04) {
        $gas_ok = false;
        $shortage = max($shortage, $gas_BBTUD - $effTotalQuota);
        if ($action !== 'use_distillate')
            $warnings[] = sprintf('Gas quota exceeded: Total Gas Used %.3f BBTUD > Gas Quota %.3f BBTUD (over by %.3f). Candidate not within quota — reduce export or add LNG/distillate (no auto-add).',
                $gas_BBTUD, $effTotalQuota, $gas_BBTUD - $effTotalQuota);
    }

    /* ---- Revisi Sec.13/14: gas utilization status + under-utilization /
     *      PGN-pipe warnings. Window: quota - 0.04 <= Total Gas Used <= quota. */
    $infeasible = false;
    foreach ($warnings as $w) if (strpos($w, 'No feasible') !== false) { $infeasible = true; break; }
    /* PROMPT AUDIT STRICT WINDOW §3.4: status display memakai window yang SAMA dgn validator —
     * OVER bila used > quota (tanpa +0.04), OK bila quota-0.04 <= used <= quota. */
    if ($gas_BBTUD > $effTotalQuota + 1e-9)             $gasUtilStatus = 'OVER-QUOTA';
    elseif ($gas_BBTUD >= $effTotalQuota - 0.04 - 1e-9) $gasUtilStatus = 'OK';
    else                                                 $gasUtilStatus = $infeasible ? 'INFEASIBLE' : 'UNDER-UTILIZED';
    if ($gasUtilStatus === 'UNDER-UTILIZED') {
        $maxedRunning = true; $stoppedAvail = [];
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u) {
            if (!pp_effective_unit_available($d3,$model,$u,1)) continue;
            $running = false; $atMax = true;
            foreach ($data as $ri9 => $rw) { $v = (float)($rw[strtoupper($u)] ?? 0); if ($v > 0) { $running = true; if ($v < pp_effective_maxload($d3, $model, $u, $ri9 + 1) - 1.0) $atMax = false; } }   // PATCH G29-G31: effective max per row
            if ($running && !$atMax) $maxedRunning = false;
            if (!$running && in_array($u, array_map('strtolower', (array)($model['unit_stop'] ?? [])), true)) $stoppedAvail[] = strtoupper($u);
        }
        $hint = ($maxedRunning && $stoppedAvail)
            ? sprintf(' Running gas units are at max load; remaining capacity (%s) is force-stopped (unit_stop). To reach the full quota, free a stopped unit, or add LNG / use distillate manually (no auto-add).', implode(', ', $stoppedAvail))
            : ' Raise Export PLN / running-unit load further if any headroom remains, or add LNG / use distillate manually (no auto-add).';
        $warnings[] = sprintf('Invalid gas utilization: Total Gas Used %.3f BBTUD is below Gas Quota %.3f by more than 0.04 BBTUD.%s',
            $gas_BBTUD, $effTotalQuota, $hint);
    }
    if ($pgnPipeQuota > 1e-9 && $pipeUsed < 1e-6 && $gasUtilStatus !== 'OK')
        $warnings[] = 'PGN Pipe quota available but not utilized. Check fuel allocation and gas quota optimization.';

    /* Revisi: Gas KP72 safety validation (non-fatal backend guard, mirrors the UI gate).
     *   If G10 or any Gas Engine is required (block_priority) or actually running, and ALL
     *   KP72 gas inputs are empty/0, warn explicitly. */
    $kp72Keys   = ['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'];
    $kp72Empty  = true;
    foreach ($kp72Keys as $kk) if ((float)($q[$kk] ?? 0) > 0) { $kp72Empty = false; break; }
    $blkRequired = function (array $units) use ($model): bool {
        foreach (($model['block_priority'] ?? []) as $blk) {
            if (is_array($blk) && in_array('required', $blk, true)) {
                foreach ($units as $u) if (in_array($u, $blk, true)) return true;
            }
        }
        return false;
    };
    $g10Run = false; $geRun = false;
    foreach ($data as $rw) {
        if ((float)($rw['G10'] ?? 0) > 0) $g10Run = true;
        foreach (['GE1','GE2','GE3','GE4'] as $ge) if ((float)($rw[$ge] ?? 0) > 0) { $geRun = true; break; }
        if ($g10Run && $geRun) break;
    }
    $needKp72 = $g10Run || $geRun || $blkRequired(['g10']) || $blkRequired(['ge1','ge2','ge3','ge4']);
    if ($needKp72 && $kp72Empty) {
        $warnings[] = 'GAS KP72 BELUM TERISI: G10/Gas Engine required or running but KP72 gas input is empty.';
    }
    if (abs($lngUsed - $lngQuota) > 1e-6) {           // PROMPT LNG_FIXED: LNG harus = quota (fixed contract)
        $warnings[] = sprintf('LNG must equal LNG quota (fixed contract). LNG quota = %.3f, achieved = %.3f.', $lngQuota, $lngUsed);
    }

    /* ---- Unnecessary-STG detection (Revisi Sec.5/13). Only meaningful when gas is
     *      actually the binding constraint: if an STG runs while gas is at the cap,
     *      PLN export sits above Range Min, and its whole block is only at the CC
     *      floor, the export (and that STG/block) could be reduced within range.
     *      Skipped when gas is NOT limited, the block is required, or a daily export
     *      target is required (then the extra export is legitimately needed). */
    $gasLimited = ($gasTotalOf($genRows) >= $gasQuotaTotal - 0.5);
    $dtReq      = !empty($model['pln_export_priority']['daily_target']['required']);
    /* BUGFIX (Master Audit Bagian E): the check previously compared every row's export against the
     * GLOBAL Range Min, so an STG legitimately holding export at a HIGHER per-row Range-rule minimum
     * (e.g. min=100 on rows 17-30) was falsely flagged "unnecessary". Build the effective per-row
     * minimum (range + range_rules) and compare each row against ITS OWN floor. */
    $peChk   = $model['pln_export_priority'] ?? [];
    $rMinChkRow = array_fill(0, count($data), (float)($peChk['range']['min'] ?? 0));
    foreach (($peChk['range_rules'] ?? []) as $zz) {
        if (!is_array($zz)) continue;
        $zs = max(1, (int)($zz['start'] ?? 1)); $ze = min(count($data), (int)($zz['stop'] ?? count($data)));
        for ($zr = $zs - 1; $zr <= $ze - 1; $zr++) $rMinChkRow[$zr] = max($rMinChkRow[$zr], (float)($zz['min'] ?? 0));
    }
    if ($gasLimited && !$dtReq) {
        foreach (['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']] as $s => $bg) {
            if (!isset($d3[$s])) continue;
            if ($blkRequired(array_merge([$s], $bg))) continue;        // required block: keep
            $S = strtoupper($s); $hits = 0;
            /* Per-RUN evaluation (Master Audit Bagian E): an STG cannot start/stop per 30-min slot —
             * if even ONE row of a contiguous run still NEEDS the STG's MW to hold that row's own
             * effective Range Min (export - STG < floor), the WHOLE run is justified (evidence:
             * CC bottoming of a GTG that itself runs for the evidenced export floor; the STG's
             * steam energy is fuel-free, so keeping it is the lowest-cost valid choice). Only a run
             * where EVERY row is reducible is genuinely unnecessary. */
            $run = []; $flagRun = function(array $run) use (&$hits, $data, $rMinChkRow, $S, $d3, $bg) {
                if (!$run) return;
                foreach ($run as $ri3) {
                    $rw3 = $data[$ri3];
                    if ((float)($rw3['Export_PLN'] ?? 0) - (float)($rw3[$S] ?? 0) < ($rMinChkRow[$ri3] ?? 0) - 0.01) return; // this row NEEDS the STG -> whole run justified
                }
                foreach ($run as $ri3) {                                 // count only rows matching the original at-floor + slack profile
                    $rw3 = $data[$ri3];
                    if ((float)($rw3['Export_PLN'] ?? 0) <= ($rMinChkRow[$ri3] ?? 0) + 5.0) continue;
                    $atFloor = true;
                    foreach ($bg as $g) {
                        $mcc = (float)($d3[$g]['min_ccload'] ?? 18);
                        if ((float)($rw3[strtoupper($g)] ?? 0) > $mcc + 1.0) { $atFloor = false; break; }
                    }
                    if ($atFloor) $hits++;
                }
            };
            foreach ($data as $ri2 => $rw) {
                if ((float)($rw[$S] ?? 0) > 0) { $run[] = $ri2; continue; }
                $flagRun($run); $run = [];
            }
            $flagRun($run);
            if ($hits > 0)
                $warnings[] = sprintf('Unnecessary STG running: %s is running for %d slot%s while gas is at the quota cap and PLN Export stays above Range Min with its block only at the CC floor — export could be reduced within range without this STG.',
                    $S, $hits, $hits > 1 ? 's' : '');
        }
    }

    /* ---- Actual vs Estimation gas (Sec.10.5) — calibrated to
     *      "Perhitungan Simulasi dan Aktual Gas.xlsx" -------------------
     *   Workbook conversion:  Energy = FixedFlowVolume(MMSCFD) * GHV/1000
     *     (already baked into $usedJ/$usedM because gas_quota fixed-flow values
     *      are volumes and $e() applies the GHV).
     *   Workbook D = C - I*24 - K*24  ->  Estimation PGN Total
     *      = Total Gas - Energy_Jababeka - Energy_MM2100
     *      = ($gasJ-$usedJ) + ($gasM-$usedM).
     *   Workbook C55 = G54+I54+K54   ->  Actual Total Gas
     *      = blended PGN + blended Energy_Jababeka + blended Energy_MM2100,
     *   where each "blended" uses the user's per-hour ACTUAL readings where
     *   supplied and the estimation (pro-rated per hour) for the remaining
     *   hours — exactly the =SUM(actual)+SUM(estimation tail) pattern. */
    /* PGN supplies GTG 1-9 only; MM2100 fixed flow does not affect PGN residual (Revisi A/B). */
    $estPGN     = $estPGN_perrow;
    $estEnergyJ = $usedJ;
    $estEnergyM = $usedM;

    /* Revisi Sec.5: per-row manual actual. For each of the 48 rows use the
     *   user-supplied actual when filled, otherwise the per-row estimation share
     *   (estDaily / nRows). Sum over rows:
     *     Total Actual X = sum( actual[row] if filled else estimation_per_row )
     *   so an all-blank column reproduces the estimation exactly. */
    /* PROMPT ACTUAL GAS 1H (§3-§6/§9): actual gas adalah data PER 1 JAM (24 pasangan row 30-menit).
     * Kanonik: nilai jam tersimpan di index GENAP pasangan (row 2h), index ganjil kosong.
     * Backward compat data lama 30-menit (kedua row terisi): nilai jam = jumlah pasangan — tetap 1x hitung.
     * Total Harian = SUM(actual HOURS terisi) + SUM(estimation utk jam yang actual-nya kosong)
     * — actual 1 jam TIDAK PERNAH dihitung dua kali sebagai dua row penuh (§9). */
    $pp_blend = function (float $estDaily, $actualRows) use ($nRows): array {
        $n = $nRows > 0 ? $nRows : 48; $H = intdiv($n, 2);
        $perHour = $estDaily / max(1, $H);
        $arr = is_array($actualRows) ? $actualRows : [];
        $cv = function ($i) use ($arr) { $v = $arr[$i] ?? null; return ($v === null || $v === '') ? null : (float)$v; };
        $total = 0.0; $cnt = 0;
        for ($h = 0; $h < $H; $h++) {
            $a = $cv(2 * $h); $b = $cv(2 * $h + 1);
            $hv = ($a !== null && $b !== null) ? ($a + $b) : ($a !== null ? $a : $b);
            if ($hv !== null) { $total += $hv; $cnt++; }
            else $total += $perHour;
        }
        return [$total, $cnt];                                            // cnt = jumlah ACTUAL HOURS terisi
    };
    [$actPGN,     $nApgn] = $pp_blend($estPGN,     $model['actual_pgn_total']       ?? []);
    [$actEnergyJ, $nAej]  = $pp_blend($estEnergyJ, $model['actual_energy_jababeka'] ?? []);
    [$actEnergyM, $nAem]  = $pp_blend($estEnergyM, $model['actual_energy_mm2100']   ?? []);
    $actualTotalGas = $actPGN + $actEnergyJ + $actEnergyM;
    $actualHoursUsed = max($nApgn, $nAej, $nAem);

    /* Revisi Sec.4 (cols 32-40): attach per-row gas-energy columns to $data.
     *   Estimation columns carry the per-row share (column sums to the daily
     *   total); actual columns echo the manual per-row input (null = blank,
     *   UI shows an editable box and falls back to estimation). */
    $ffVolJ = ($q['pep'] ?? 0) + ($q['akasia'] ?? 0) + ($q['baskara'] ?? 0) + ($q['bbg'] ?? 0);     // MMSCFD — §2: TOTAL FIXED FLOW JABABEKA = PEP+AKASIA+BASKARA+BBG
    /* §8-§9 PROMPT MM2100 REDESIGN: dropdown Fixed Flow / Cummulative per gas MM2100.
     * Hanya SATU gas boleh Cummulative; sisanya Fixed Flow. Default semua Fixed Flow.
     * F (BBTUD) = Σ quota gas mode fixed -> ONLY FIXED FLOW MM2100 (MMSCFD) konstan = F*1000/GHV_M.
     * C (BBTUD) = quota gas mode cumulative -> window kumulatif [C-0.04, C]. */
    $gmMM = (array)($model['mm2100_gas_mode'] ?? []);
    $cumKey = null;
    foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $gk) {
        if (strtolower((string)($gmMM[$gk] ?? 'fixed')) === 'cummulative' || strtolower((string)($gmMM[$gk] ?? 'fixed')) === 'cumulative') {
            if ($cumKey === null) $cumKey = $gk;                          // satu Cummulative saja — pertama menang
        }
    }
    /* KEPUTUSAN DOMAIN — satuan gas MM2100 (sejalan dengan konvensi Jababeka $ffJQuotaKeys):
     *   Cummulative : raw input BBTUD. Total energi kumulatif 48 slot. TIDAK dikali GHV,
     *                 TIDAK dikali 48, dan TIDAK berubah saat GHV berubah.
     *   Fixed Flow  : raw input MMSCFD. Energi BBTUD = MMSCFD x GHV / 1000.
     * MMSCFD dan BBTUD TIDAK PERNAH dijumlahkan dalam satu accumulator: volume disimpan pada
     * $fixMM_MMSCFD, energi pada $fixMM_BBTUD. */
    $fixMM_MMSCFD = 0.0; $fixMM_BBTUD = 0.0; $cumMM_BBTUD = 0.0;
    foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $gk) {
        $qq = (float)($q[$gk] ?? 0);
        if ($gk === $cumKey) {
            $cumMM_BBTUD += $qq;                                          // sudah BBTUD
        } else {
            $fixMM_MMSCFD += $qq;                                         // volume MMSCFD
            $fixMM_BBTUD  += $qq * $ghvM / 1000.0;                        // energi BBTUD
        }
    }
    $onlyFFM_MMSCFD = $fixMM_MMSCFD;                                      // konstan 00:00-24:00 (MMSCFD)
    $ffVolM = ($q['pep_kp72'] ?? 0) + ($q['pertagas_kp72'] ?? 0) + ($q['akasia_kp72'] ?? 0) + ($q['baskara_kp72'] ?? 0);
    $perEstPGN = $estPGN     / max(1, $nRows);
    $perEstJ   = $estEnergyJ / max(1, $nRows);
    $perEstM   = $estEnergyM / max(1, $nRows);
    $aP = $model['actual_pgn_total']       ?? [];
    $aJ = $model['actual_energy_jababeka'] ?? [];
    $aM = $model['actual_energy_mm2100']   ?? [];
    $cell = fn($arr, $i) => (($arr[$i] ?? null) === null || ($arr[$i] ?? '') === '') ? null : (float)$arr[$i];
    /* PROMPT ACTUAL GAS 1H: nilai actual utk jam h (0..23) — index genap kanonik; pasangan
     * legacy 30-menit (dua-duanya terisi) di-merge sbg jumlah 1 jam (tanpa double count). */
    $hourCell = function ($arr, int $h) use ($cell) {
        $a = $cell($arr, 2 * $h); $b = $cell($arr, 2 * $h + 1);
        if ($a !== null && $b !== null) return $a + $b;
        return $a !== null ? $a : $b;
    };
    // Revisi Sec.10: manual fixed-flow overrides (Ctrl+Click). Per-row {area,row,value}
    // replaces the Jababeka/MM2100 fixed-flow volume and is NOT changed by the sim.
    $mffJ = []; $mffM = [];
    foreach (($model['manual_fixed_flows'] ?? []) as $mf) {
        $ri = (int)($mf['row'] ?? 0) - 1; if ($ri < 0) continue;
        $area = strtoupper((string)($mf['area'] ?? ''));
        if ($area === 'JABABEKA') $mffJ[$ri] = (float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0);
        elseif ($area === 'MM2100') $mffM[$ri] = (float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0);
    }
    foreach ($data as $i => &$rw) {
        // Revisi gas accounting: TOTAL GAS DISPLAY (daily-equivalent) = per-30min * 48 = Total_Gas(per-hour) * 24
        $totGasDisp = (float)($rw['Total_Gas'] ?? 0) * 24.0;
        // ENERGY PGN REAL TIME is isolated to Jababeka: uses Gas_Jababeka only (KP72/MM2100 gas excluded)
        $jbbkGasDisp = (float)($rw['Gas_Jababeka'] ?? 0) * 24.0;
        $rw['FixedFlow_J']  = round(array_key_exists($i, $mffJ) ? $mffJ[$i] : $ffVolJ, 4);  // 33 (manual override per-row)
        /* ADDENDUM FINAL 1.3: kolom "Energy Real Time Gas MM2100" (key lama FixedFlow_M dipertahankan)
         * = ESTIMATION ENERGY TOTAL MM2100 (BBTUD/slot) x 48 — tampilan flow-like realtime yang mengikuti
         * load G10/GE1-4 (konsep daily quota), menggantikan volume MMSCFD konstan yang sudah tidak sesuai.
         * Manual override per-row operator ($mffM) tetap authoritative bila diisi. */
        /* §9.1: FixedFlow_M kini diisi di bawah sebagai Cummulative Gas MM2100 (BBTUD). */
        $rw['FF_J_Manual']  = array_key_exists($i, $mffJ) ? 1 : 0;
        $rw['FF_M_Manual']  = array_key_exists($i, $mffM) ? 1 : 0;
        // ENERGY PGN REAL TIME = TOTAL GAS DISPLAY - (Fixed Flow Jababeka * GHV_J / 1000); NOT affected by MM2100
        $ffJDaily = ((float)$rw['FixedFlow_J'] * $ghvJ) / 1000.0;
        $energyPgnRT = $jbbkGasDisp - $ffJDaily;
        $rw['EnergyPGN_RT'] = round($energyPgnRT, 5);                 // 32 ENERGY PGN REAL TIME (daily-equiv)
        /* §3.1 PROMPT PGN_FLOW_REBALANCE: FLOW PGN REAL TIME (MMSCFD) = EnergyPGN_RT / GHV PGN * 1000.
         * GHV PGN kosong/invalid -> fallback GHV From Tegalgede to Jababeka (warning di engine). */
        $ghvPgnC = (float)($model['ghv_pgn'] ?? 0); if ($ghvPgnC <= 1e-9) $ghvPgnC = $ghvJ;
        $rw['Flow_PGN_RT']  = round($energyPgnRT / max(1e-9, $ghvPgnC) * 1000.0, 4);
        $rw['Est_PGN']      = round($energyPgnRT / 48.0, 5);          // 35 ESTIMATION PGN TOTAL = ENERGY PGN RT / 48
        $rw['Act_PGN']      = ($i % 2 === 0) ? $hourCell($aP, intdiv($i, 2)) : null;   // 36 ACTUAL PGN TOTAL — PER 1 JAM (merged 2 row; nilai di row genap pasangan)
        // FF JABABEKA / MM2100 - ESTIMATION ENERGY (per 30-min) = FixedFlow(MMSCFD) * GHV / (1000 * 48)
        $rw['Est_FF_J']     = round(((float)$rw['FixedFlow_J'] * $ghvJ) / (1000.0 * 48.0), 5);   // 37
        $rw['Act_FF_J']     = ($i % 2 === 0) ? $hourCell($aJ, intdiv($i, 2)) : null;   // 38 ACTUAL — PER 1 JAM (merged 2 row)
        /* Bagian F6 (Master Audit): ESTIMATION ENERGY TOTAL MM2100 per row = kebutuhan gas LOAD-DEPENDENT
         * (fuel_calc) unit G10 + GE1-4 per 30 menit — konsep lama MMSCFD*GHV/48 (fixed constant flow)
         * DIHAPUS. Satuan: energi per-slot (konsisten dgn Est_FF_J; jumlah 48 row = daily BBTUD; tampilan
         * BBTUD ternormalisasi-48 utk satu row = nilai ini * 48). */
        $rw['Est_FF_M']     = round(((float)($rw['Gas_MM2100'] ?? 0)) / 2.0, 5);                 // 39
        $rw['Act_FF_M']     = ($i % 2 === 0) ? $hourCell($aM, intdiv($i, 2)) : null;   // 40 ACTUAL — PER 1 JAM (merged 2 row)
        /* §6/§9/§10 PROMPT MM2100 REDESIGN:
         * TOTAL GAS JBBK/MM2100 (BBTUD daily-equiv) = gas per-jam x 24 (== per-30min x 48).
         * Manual Ctrl+Click pada TOTAL FLOW GAS MM2100 (MMSCFD, $mffM) -> TOTAL GAS MM2100 = flow*GHV/1000,
         * seluruh turunan (Cummulative, Est_FF_M, Total_Flow) ikut. */
        $rw['Total_Gas_JBBK'] = round($jbbkGasDisp, 5);
        $totMM_disp = array_key_exists($i, $mffM) ? ($mffM[$i] * $ghvM / 1000.0)
                                                  : (float)($rw['Gas_MM2100'] ?? 0) * 24.0;
        $rw['Total_Gas_MM']   = round($totMM_disp, 5);
        $rw['Only_FF_M']      = round($onlyFFM_MMSCFD, 4);                                        // MMSCFD konstan
        /* Cummulative Gas MM2100 (BBTUD) = TOTAL GAS MM2100 - (ONLY FIXED FLOW * GHV/1000) — §9.4.
         * Key lama FixedFlow_M dipertahankan utk kompatibilitas payload/report/download. */
        $rw['FixedFlow_M']    = round($totMM_disp - $fixMM_BBTUD, 4);
        if ($rw['FixedFlow_M'] < -1e-6 && $totMM_disp > 1e-9)
            $mmNegConflict = ($mmNegConflict ?? 0) + 1;
        if (array_key_exists($i, $mffM)) $rw['Est_FF_M'] = round($totMM_disp / 48.0, 5);          // §11 turunan manual
        $rw['Total_Flow_M']   = round($totMM_disp * 1000.0 / max(1e-9, $ghvM), 4);                // §10 MMSCFD
    }
    unset($rw);
    if (!empty($mmNegConflict))
        $warnings[] = sprintf('Cummulative Gas MM2100 negatif pada %d row(s): TOTAL GAS MM2100 < ONLY FIXED FLOW energy — Fixed Flow MM2100 melebihi gas MM2100 aktual (conflict / VALID-INFEASIBLE input).', $mmNegConflict);

    /* PGN Total (Revisi Sec.5): per row use ACTUAL PGN TOTAL when the user filled it, else ESTIMATION PGN
     * TOTAL — never both for the same row. Three monitoring sums for the Simulation Data header. */
    /* PROMPT ACTUAL GAS 1H §9: akunting PER JAM — actual jam terisi dipakai SEKALI menggantikan
     * DUA row estimation pasangannya; jam kosong memakai jumlah estimation kedua row. */
    $pgnTotal = 0.0; $actTotGasPGN = 0.0; $actEnergyJsum = 0.0; $actEnergyMsum = 0.0; $actHoursPGN = 0;
    $Hn = intdiv(count($data), 2);
    for ($h = 0; $h < $Hn; $h++) {
        $i0 = 2 * $h; $i1 = $i0 + 1;
        $estPairPGN = (float)$data[$i0]['Est_PGN'] + (float)($data[$i1]['Est_PGN'] ?? 0);
        $ap = $hourCell($aP, $h);
        $pgnTotal      += ($ap !== null) ? $ap : $estPairPGN;             // ACTUAL 1 jam if filled, else ESTIMATION pasangan
        $actTotGasPGN  += ($ap !== null) ? $ap : 0.0;                     // sum ACTUAL HOURS only (1x per jam)
        if ($ap !== null) $actHoursPGN++;                                 // KEHADIRAN actual (0 juga dihitung!)
        $aj = $hourCell($aJ, $h); if ($aj !== null) $actEnergyJsum += $aj;
        $am = $hourCell($aM, $h); if ($am !== null) $actEnergyMsum += $am;
    }
    /* PROMPT §6.2: identitas PGN TOTAL = PGN PIPE + LNG wajib tetap berlaku saat ACTUAL PGN
     * mengalahkan estimation. LNG adalah kontrak fixed (TIDAK boleh dikurangi diam-diam) —
     * maka PGN Pipe Used disesuaikan sbg residual dari total blended actual+estimation. */
    /* ATRIBUSI STARTUP PENALTY (Bagian C — definisi EKSPLISIT, bukan tebakan):
     *   - Est_PGN per-slot TIDAK memuat startup penalty; penalty adalah komponen TERPISAH level-hari
     *     yang dijumlahkan sekali di jalur estimasi ($pgnNeed = estPGN + penalty).
     *   - actual_pgn_total didefinisikan sbg METER energi PGN jam tsb -> gas startup yang benar-benar
     *     terjadi pada jam ber-actual SUDAH ada di dalam angka meter.
     *   Maka di jalur blended: penalty utk start event yang jatuh di JAM BER-ACTUAL tidak boleh
     *   ditambahkan lagi (double count), penalty utk start event di jam future tetap ditambahkan
     *   tepat SEKALI. Tanpa actual, $penaltyFuture == $startupGasPenalty (identik jalur lama). */
    $penaltyFuture = 0.0;
    foreach (($penEventRows ?? []) as $pe) {
        $h = intdiv((int)$pe[0], 2);
        if ($hourCell($aP, $h) === null) $penaltyFuture += (float)$pe[1];   // jam future -> penalty tetap
    }
    /* BAGIAN B/F — SATU angka efektif kanonik utk total gas harian:
     *   Effective Total = Total est + (blend - est) tiap sumber; blend PGN memakai profil per-slot
     *   Est_PGN (formula yang sama dgn $pgnTotal), blend J/M memakai pp_blend (fixed-flow flat).
     *   Tanpa actual, seluruh delta = 0 -> identik dgn Total Gas Used (backward compatible). */
    $effTotalGas = $gas_BBTUD
        + (($actHoursPGN > 0) ? ($pgnTotal + $penaltyFuture - ($estPGN_perrow + $startupGasPenalty)) : 0.0)
        + ($actEnergyJ - $estEnergyJ)
        + ($actEnergyM - $estEnergyM);
    /* GERBANG KEHADIRAN (Bagian B3 — akar T-ACTUAL-02): gerbang lama `$actTotGasPGN > 0` memakai
     * JUMLAH actual -> actual 0 (valid!) membuat gerbang mati dan slot kembali ke estimation —
     * persis pola truthy `if (actual)` yang dilarang. Gerbang wajib KEHADIRAN jam actual. */
    if ($actHoursPGN > 0) {
        /* KONSISTENSI PENALTY (audit Bagian B): versi estimasi pipe = estPGN + startupGasPenalty - LNG,
         * tapi override blended lama = pgnTotal - LNG TANPA penalty -> offset 0.08 antara jalur pipe dan
         * jalur total membuat window pipe [q-0.04,q] dan window total mustahil dipuaskan bersamaan
         * (terukur: pipe 27.4893 OK tapi total efektif 64.5693 > 64.50). Penalty startup jam FUTURE
         * tetap konsumsi nyata; penalty jam ber-actual sudah di dalam meter (lihat atribusi di atas). */
        $pipeUsed = max(0.0, $pgnTotal + $penaltyFuture - $lngUsed);
        if ($pipeUsed > $pgnPipeQuota + 0.0005)
            $warnings[] = sprintf('ACTUAL PGN terukur di atas rencana: PGN Pipe blended %.4f BBTUD melebihi quota pipe %.4f (overage %.4f) — deviasi historis dari actual jam terisi; row future tetap quota-consistent.', $pipeUsed, $pgnPipeQuota, $pipeUsed - $pgnPipeQuota);
    }

    /* ---- Bagian F5/F2 (Master Audit): MM2100 DAILY QUOTA compliance ---------------------------------------
     * MM2100 Daily Used = SUM(row_energy), row_energy = ACTUAL ENERGY TOTAL jika terisi, selain itu
     * ESTIMATION ENERGY TOTAL (load-dependent). Start-up gas G10 (berasal dari KP72) dilaporkan terpisah
     * dan dihitung ke pemakaian quota. Window: quotaM - 0.04 <= (Used + Startup G10) <= quotaM. */
    /* Kuota energi MM2100: Fixed Flow dikonversi dari MMSCFD, Cummulative dipakai apa adanya.
     * Sebelumnya seluruh $ffVolM (termasuk komponen Cummulative) dikali GHV sehingga nilai
     * Cummulative ikut berubah saat GHV berubah — melanggar keputusan domain. */
    $quotaM_energy = $fixMM_BBTUD + $cumMM_BBTUD;                                // daily quota MM2100 (BBTUD)
    $mmDailyUsed = 0.0;
    for ($h2 = 0; $h2 < intdiv(count($data), 2); $h2++) {                        // PROMPT ACTUAL GAS 1H: per JAM
        $amv = $hourCell($aM, $h2);
        $mmDailyUsed += ($amv !== null) ? $amv
            : ((float)$data[2 * $h2]['Est_FF_M'] + (float)($data[2 * $h2 + 1]['Est_FF_M'] ?? 0));   // ACTUAL 1 jam override (Bagian F7)
    }
    $g10Starts = 0;
    for ($k2 = 1; $k2 < count($genRows); $k2++) if (($genRows[$k2-1]['g10'] ?? 0) < 1 && ($genRows[$k2]['g10'] ?? 0) >= 1) $g10Starts++;
    $mmStartGas = $g10Starts * 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
    $mmUsedTotal = $mmDailyUsed + $mmStartGas;
    $mmStatus = 'N/A';
    if ($quotaM_energy > 1e-9) {
        if     ($mmUsedTotal > $quotaM_energy + 0.04)  { $mmStatus = 'OVER-QUOTA';
            $warnings[] = sprintf('MM2100 quota exceeded: Daily Used + Startup %.3f BBTUD > MM2100 quota %.3f BBTUD (over by %.3f).', $mmUsedTotal, $quotaM_energy, $mmUsedTotal - $quotaM_energy); }
        elseif ($mmUsedTotal >= $quotaM_energy - 0.04) $mmStatus = 'OK';
        else { $mmStatus = 'UNDER-UTILIZED';
            $warnings[] = sprintf('MM2100 quota under-utilized: Daily Used + Startup %.3f BBTUD di bawah quota %.3f BBTUD lebih dari 0.04 — lihat warning MM2100/KP72 untuk evidence row-level (unit max / Maximum Flow / stop-skip).', $mmUsedTotal, $quotaM_energy); }
    }
    /* Bagian G + ADDENDUM 2.1: Maximum Flow MM2100 (MMSCFD) — validasi per-row pada ESTIMATION.
     * Energi cap per slot = maxFlow(MMSCFD) x GHV_MM2100/1000 / 48. */
    $maxFlowMchk = (float)($model['max_flow_mm2100'] ?? 0);
    if ($maxFlowMchk > 1e-9) {
        $capSlotChk = $maxFlowMchk * $ghvM / 1000.0 / 48.0; $mfViol = [];
        foreach ($data as $i2 => $rw2) if ((float)$rw2['Est_FF_M'] > $capSlotChk + 1e-6) $mfViol[] = $i2 + 1;
        if ($mfViol) $warnings[] = sprintf('Maximum Flow MM2100 dilanggar: row %s memiliki ESTIMATION ENERGY > %.4f BBTUD/slot (Maximum Flow %.2f MMSCFD x GHV %.1f).', implode(',', array_slice($mfViol, 0, 6)), $capSlotChk, $maxFlowMchk, $ghvM);
    }

    /* ---- cost (Sec.12.3) --------------------------------------------- */
    $eFFJ     = fn($v) => (float)$v * $ghvJ / 1000.0;                    // MMSCFD -> BBTUD (§1)
    $akasiaU  = min($eFFJ($q['akasia'] ?? 0), $usedJ);
    $baskaraU = min($eFFJ($q['baskara'] ?? 0), $usedJ - $akasiaU);
    $bbgU     = min($eFFJ($q['bbg'] ?? 0), $usedJ - $akasiaU - $baskaraU);   // §2: BBG share
    $pepU     = max(0.0, $usedJ - $akasiaU - $baskaraU - $bbgU);
    /* ALOKASI KP72 PER FAMILY (keputusan domain final: TIDAK BOLEH fallback lintas family).
     * Sebelumnya $pepKpU adalah RESIDUAL yang mencampur PEP dan PERTAGAS dalam satu bucket,
     * lalu diberi SATU harga family — salah untuk sebagian volume bila keduanya berkuota.
     * Kini pemakaian MM2100 dialokasikan berurutan ke tiap sumber sebesar kuotanya sendiri,
     * dan setiap bucket dihargai family-nya sendiri. Sisa yang tak terpetakan (kuota nol tetapi
     * ada pemakaian) tetap masuk bucket PEP dan ditandai pada info agar dapat diaudit. */
    $akasiaKpU   = min((float)($q['akasia_kp72']   ?? 0), $usedM);
    $baskaraKpU  = min((float)($q['baskara_kp72']  ?? 0), max(0.0, $usedM - $akasiaKpU));
    $pertagasKpU = min((float)($q['pertagas_kp72'] ?? 0), max(0.0, $usedM - $akasiaKpU - $baskaraKpU));
    $pepKpQuota  = (float)($q['pep_kp72'] ?? 0);
    $pepKpAlloc  = min($pepKpQuota, max(0.0, $usedM - $akasiaKpU - $baskaraKpU - $pertagasKpU));
    $kpUnmapped  = max(0.0, $usedM - $akasiaKpU - $baskaraKpU - $pertagasKpU - $pepKpAlloc);
    $pepKpU      = $pepKpAlloc + $kpUnmapped;   /* sisa tak terpetakan -> bucket PEP, ditandai */

    $costPEP        = $pepU      * 1000 * ($price['pep'] ?? 0);
    $costBBG        = $bbgU      * 1000 * ($price['bbg'] ?? 0);
    $costAkasia     = $akasiaU   * 1000 * ($price['akasia'] ?? 0);
    $costBaGS       = $baskaraU  * 1000 * ($price['baskara'] ?? 0);
    /* FIX PEP_KP72_PRICE_MAPPING_ERROR: sebelumnya PEP KP72 diberi harga family PERTAGAS
     * ($price['pertagas_kp72'] = 10,4) padahal ia sumber family PEP. Seluruh akumulator lain
     * konsisten memakai family masing-masing (costAkasiaKp72 -> akasia_kp72,
     * costBaGSkp72 -> baskara_kp72), hanya baris ini yang lintas family.
     * Perbaikan generik: hormati key eksplisit bila UI menambahkannya, jika tidak warisi
     * family PEP. Tidak ada harga yang di-hardcode. */
    /* Harga per family: override KP72 dahulu, lalu fallback DALAM family yang sama. */
    $costPEPkp72      = $pepKpU     * 1000 * ($price['pep_kp72']      ?? $price['pep']      ?? 0);
    $costAkasiaKp72   = $akasiaKpU  * 1000 * ($price['akasia_kp72']   ?? $price['akasia']   ?? 0);
    $costBaGSkp72     = $baskaraKpU * 1000 * ($price['baskara_kp72']  ?? $price['baskara']  ?? 0);
    $costPertagasKp72 = $pertagasKpU* 1000 * ($price['pertagas_kp72'] ?? $price['pertagas'] ?? 0);
    $costPGNpipe    = $pipeUsed  * 1000 * ($price['pgn_pipe'] ?? 0);
    $costLNG        = $lngUsed   * 1000 * ($price['lng'] ?? 0);
    $costCoal       = $coal_total * ($price['coal'] ?? 0);
    $costDistillate = $dist_total_litres * (float)($price['distillate'] ?? 0);   // Revisi: USD/liter
    $pksPct         = (float)($model['pks_pct'] ?? 0) / 100.0;
    $costBiomass    = $re_total_MWh * (($price['pks'] ?? 0) * $pksPct + ($price['woodchip'] ?? 0) * (1 - $pksPct));

    $totalCost = $costPEP + $costBBG + $costAkasia + $costBaGS + $costPEPkp72 + $costPertagasKp72 + $costAkasiaKp72
               + $costBaGSkp72 + $costPGNpipe + $costLNG + $costCoal + $costDistillate + $costBiomass;
    /* PROMPT HOUSELOAD §1-§4 — FIX DOUBLE COUNTING: 'Jababeka' (JBBK MM PROD) SUDAH net houseload
     * di row-level ($jbbk = totalGen - babelan - hl), sehingga Summary TIDAK boleh mengurangi
     * Houseload untuk kedua kalinya. Seluruh angka gross/houseload/net/cost berasal dari SATU
     * helper bersama (pp_production_accounting) yang juga menerapkan faktor 0.5 tepat sekali. */
    $acct         = pp_production_accounting($data);
    $hl_total_MWh = $acct['total_houseload'];
    $prodMWh      = $acct['total_gross'];                               // gross = JBBK gross + Babelan
    $netProd      = $acct['total_net'];                                 // = gross - houseload = JBBK net + Babelan net
    if ($netProd <= 0)
        $warnings[] = sprintf('Net Production is %.2f MWh (<= 0): gross production %.2f MWh minus houseload %.2f MWh. Heat Rate / Cost Production divide-by-zero avoided (reported as 0).', $netProd, $prodMWh, $hl_total_MWh);
    $costProduction = pp_cost_production($totalCost, $netProd);
    /* ====================================================================================
     *  BOUNDARY METRICS — numerator dan denominator WAJIB pada boundary yang sama.
     *  MM2100 (GE1-GE4 + G10) memasok pelanggan MM2100, bukan bus Jababeka, sehingga
     *  produksinya memang di luar JBBK MM Prod (desain, lihat worker02.php:1089).
     *  Sebelumnya Heat Rate memakai TOTAL FUEL (termasuk fuel MM2100) dibagi JBBK MM Prod
     *  (tanpa produksi MM2100) — boundary tidak sama, sehingga menambah Gas Engine menaikkan
     *  Heat Rate secara artifisial. Kini disediakan tiga metrik dengan boundary konsisten.
     *  Arti JBBK MM Prod TIDAK diubah. */
    $mm_total_MWh = 0.0;
    foreach ($data as $rMM) foreach (['GE1','GE2','GE3','GE4','G10'] as $uMM)
        $mm_total_MWh += (float)($rMM[$uMM] ?? 0);
    $mm_total_MWh /= 2.0;
    /* Q1 FINAL: JBBK MM Prod SUDAH memuat MM2100 Production, sehingga 'Combined' tidak boleh
     * menambahkannya lagi (double count). Kontrak baru:
     *   TOTAL_PLANT_PRODUCTION = JBBK_MM_PROD + BABELAN_PROD
     * dan JABABEKA_BUS_NET dipublikasikan terpisah sebagai angka topologi fisik. */
    $jbus_net_MWh   = $jbbk_total_MWh - $mm_total_MWh;          // physical bus net
    $comb_total_MWh = $jbbk_total_MWh + $bbln_total_MWh;        // TOTAL PLANT PRODUCTION
    $fuelMM_BBTUD   = (float)$mmUsedTotal;                       // fuel MM2100 (KP72)
    $fuelJ_BBTUD    = max(0.0, (float)$gas_BBTUD - $fuelMM_BBTUD);  // fuel Jababeka murni
    /* §7 BOUNDARY FINAL — numerator dan denominator SELALU selingkup.
     *   JBBK MM      : (fuel Jababeka + fuel MM2100) / JBBK MM Prod   <- headline, TANPA Babelan
     *   Jababeka Bus : fuel Jababeka                  / Jababeka Bus Net
     *   MM2100       : fuel MM2100                    / MM2100 Production
     *   Total Plant  : seluruh fuel (+ Babelan)       / Total Plant Production */
    $fuelJBBKMM_BBTUD = $fuelJ_BBTUD + $fuelMM_BBTUD;                 // tanpa fuel Babelan
    $hrJBBKMM = $jbbk_total_MWh > 0 ? ($fuelJBBKMM_BBTUD * 1e6) / $jbbk_total_MWh : 0;
    $hrJ    = $jbus_net_MWh   > 0 ? ($fuelJ_BBTUD  * 1e6) / $jbus_net_MWh   : 0;
    $hrMM   = $mm_total_MWh   > 0 ? ($fuelMM_BBTUD * 1e6) / $mm_total_MWh   : 0;
    $hrComb = $comb_total_MWh > 0 ? ((float)$gas_BBTUD * 1e6) / $comb_total_MWh : 0;
    $costMM = (float)$costPEPkp72 + (float)$costPertagasKp72 + (float)$costAkasiaKp72 + (float)$costBaGSkp72;
    $costJ  = max(0.0, (float)$totalCost - $costMM);
    /* Cost Production: $totalCost MEMUAT biaya batubara Babelan, sehingga denominator Jababeka
     * dan Combined WAJIB menyertakan Babelan Prod agar boundary numerator = denominator
     * (konsisten dengan Cost Production existing = totalCost / (JBBK + Babelan)).
     * Heat Rate TIDAK menyertakan Babelan karena $gas_BBTUD hanya bahan bakar gas. */
    /* §8 BOUNDARY BIAYA — biaya Babelan TIDAK masuk JBBK MM.
     *   JBBK MM     : (costJ tanpa Babelan + costMM) / JBBK MM Prod
     *   Babelan     : costBabelan                    / Babelan Prod
     *   Total Plant : totalCost                      / Total Plant Production */
    $costBabelan  = (float)($costCoal ?? 0) + (float)($costWoodchip ?? 0) + (float)($costPKS ?? 0);
    $costJBBKMM   = max(0.0, (float)$totalCost - $costBabelan);
    $cpJBBKMM     = $jbbk_total_MWh   > 0 ? $costJBBKMM / $jbbk_total_MWh   : 0;
    $cpBabelan    = (float)$bbln_total_MWh > 0 ? $costBabelan / (float)$bbln_total_MWh : 0;
    $cpDenJ    = $jbus_net_MWh;
    $cpDenComb = $comb_total_MWh;
    $cpJ    = $cpDenJ       > 0 ? max(0.0, $costJ - $costBabelan) / $cpDenJ : 0;
    $cpMM   = $mm_total_MWh > 0 ? $costMM / $mm_total_MWh : 0;
    $cpComb = $cpDenComb    > 0 ? (float)$totalCost / $cpDenComb : 0;
    $heatrate = $jbbk_total_MWh > 0 ? ($gas_BBTUD * 1e6) / $jbbk_total_MWh : 0;  // legacy (kompatibilitas)

    $bc = $model['babelan_cap'];
    $avgBB = array_sum($col('BB_Total')) / max(1, $nRows);
    $babelanCF = ($bc[0] * $avgBB ** 2 + $bc[1] * $avgBB + $bc[2]) * 100;
    $cfTgt = (float)($model['babelan_cf_target'] ?? 0);
    if ($cfTgt > 0 && $babelanCF < $cfTgt - 0.05)
        $warnings[] = sprintf('Babelan CF below target: actual %.1f%% < target %.0f%%. Raise Babelan further if Export PLN headroom and unit max allow.', $babelanCF, $cfTgt);
    $rowsOut = array_sum(array_map(fn($x) => $x ? 0 : 1, $col('in_band')));

    /* =================================================================
     *  SUMMARY (Sec.14.5)
     * ================================================================= */
        /* PATCH I08d — evidence Flow PGN RT (nilai FINAL, materialize): alokasi pipe negatif/under saat
     * blok konsumen di-off-kan komitmen operator = infeasible fisik; emit row-level agar VI. */
    { $minPF = (float)($model['min_pgn_flow'] ?? 0);
      if ($minPF > 0) foreach ($data as $iF => $rwF) {
          if (isset($actualRows[$iF])) continue;
          $fRT = (float)($rwF['Flow_PGN_RT'] ?? 0);
          if ($fRT < $minPF - 0.01)
              $warnings[] = sprintf('Flow PGN Real Time below Min PGN Flow row %d: %.3f < %.3f MMSCFD — alokasi pipe exhausted: kontrak fixed-take (PEP/LNG) terserap sementara konsumen gas Jababeka pada row ini dibatasi komitmen start/stop operator.', $iF + 1, $fRT, $minPF);
      } }

$info = [
        'Notes' => $rowsOut === 0 ? 'All rows within PLN export band' : "$rowsOut row(s) outside export band",
        'PLN Export Compliance' => $rowsOut === 0 ? 'OK' : 'CHECK',
        'Gas Quota Status' => $gas_ok ? 'WITHIN QUOTA' : 'SHORTAGE',
        'Gas Utilization Status' => $gasUtilStatus,
        'Gas Quota Deviation (BBTUD)' => round($effTotalQuota - $gas_BBTUD, 4),
        'PGN Pipe Quota (BBTUD)' => round($pgnPipeQuota, 4),
        'PEP Jababeka Quota (BBTUD)' => round($eFFJ($q['pep'] ?? 0), 4),
        'BBG Jababeka Quota (BBTUD)' => round($eFFJ($q['bbg'] ?? 0), 4),
        'BBG Jababeka Used (BBTUD)' => round($bbgU, 4),
        'LNG Quota (BBTUD)' => round($lngQuota, 4),
        'PGN Pipe Used (BBTUD)' => round($pipeUsed, 4),
        'PEP Jababeka Used (BBTUD)' => round($pepU, 4),
        'LNG Used (BBTUD)' => round($lngUsed, 4),
        /* §1 FINAL: gas fisik yang benar-benar dibakar setelah distillate menggantikan sebagian.
         * Offset = energi gas yang digantikan (shortage awal − residual). Validator hilir memakai
         * angka EFEKTIF ini, sehingga final candidate (bukan gas-only) dinilai dgn benar. */
        'Total Gas Used (BBTUD)' => round($gas_BBTUD - (pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0), 4),
        'Total Gas Used Gross (BBTUD)' => round($gas_BBTUD, 4),   // sebelum offset distillate (audit)
        /* DASAR KEPUTUSAN KUALITAS RENCANA — TIDAK BERGANTUNG PLAFON OPERATOR.
         * Sama dengan Total Gas Used, kecuali offset distillate dihitung TANPA plafon (dibatasi
         * kapasitas fisik saja). Dipakai screening decommit, gerbang export minimization, dan
         * pencarian offset kuota lewat pp_decision_gas(); tidak pernah dipakai sebagai laporan
         * pemakaian gas. Lihat catatan akar penyebab pada pp_decision_gas(). */
        'Decision Gas Used (BBTUD)' => round($gas_BBTUD, 4),
        'Distillate Gas Offset (BBTUD)' => round(pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0, 4),
        'Effective Total Gas (BBTUD)' => round($effTotalGas, 4),   // BAGIAN B/F: actual-over-estimation kanonik
        'Gas Shortage (BBTUD)' => round($shortage, 4),
        'Gas Shortage Action' => $action,
        // ---- Gas Shortage Recommendation (Revisi): figures for the UI panel ----
        'Gas Available (BBTUD)'        => round($effTotalQuota, 4),
        'Gas Required (BBTUD)'         => round($gas_BBTUD, 4),
        'Startup Gas Penalty (BBTUD)'  => round($startupGasPenalty, 4),
        /* Bagian C: penalty yang BENAR-BENAR diterapkan pada jalur efektif — start event di jam
         * ber-actual dieksklusi (meter sudah memuatnya). Tanpa actual == Startup Gas Penalty. */
        'Startup Gas Penalty Applied (BBTUD)' => round(($actHoursPGN > 0) ? $penaltyFuture : $startupGasPenalty, 4),
        'Startup Events (GTG)'         => $startEvents16 + $startEvents710,
        'Units with Startup Penalty'   => $penUnits ? implode(', ', $penUnits) : '-',
        'Initial Running Units (no penalty)' => $initRunning ? implode(', ', $initRunning) : '-',
        'Recommended LNG (BBTUD)'      => round(max(0.0, $shortage), 4),
        /* §10 SATU SUMBER: Recommended = Consumed aktual (row-level schedule), BUKAN formula energi
         * shortage terpisah. Bila action=use_distillate -> = $dist_total_litres (= Summary). Bila
         * belum use_distillate (mode rekomendasi), tampilkan volume kandidat distillate ter-schedule. */
        'Required Distillate (l/day)'  => round($shortage > 0 ? pp_distillate_litres_from_bbtu($shortage, $model) : 0, 0),
        'Recommended Distillate (l/day)' => round(
            pp_action_uses_distillate($action) ? $dist_total_litres
            : (($GLOBALS['__pp_gs_dist_scheduled_litres'] ?? null) ?? ($shortage > 0 ? pp_distillate_litres_from_bbtu($shortage, $model) : 0)), 1),
        'Distillate Cost (USD)'        => round($costDistillate, 2),
        /* Basis konversi liter dinyatakan eksplisit agar UI tidak menghitung ulang dengan
         * konstanta sendiri (dulu index.php menduplikasi 0.8424x19400x2.2046). */
        'Distillate Conversion Basis'  => pp_distillate_conversion($model),
        'Distillate User Limit'        => $GLOBALS['__pp_gs_dist_user_cap'] ?? null,
        /* Bukti pemilihan kombinasi diskret penutup (atau certificate bila tidak ada kombinasi
         * yang sah). Selalu ikut dilaporkan supaya keputusan alokasi dapat diaudit. */
        'Distillate Discrete Closing'  => $GLOBALS['__pp_dist_closing'] ?? null,
        'Distillate Energy Reconciliation' => $GLOBALS['__pp_dist_energy_recon'] ?? null,
        'Gas Topup Evidence'          => $GLOBALS['__pp_gas_topup_ev'] ?? null,
        'Gas Shortage Minimization Evidence' => $GLOBALS['__pp_shortage_min_ev'] ?? null,
        /* D-1: bukti perbaikan band Unit Skip Load — baris mana yang dipindah, ke mana, dan
         * baris mana yang tidak dapat diperbaiki beserta blocker spesifiknya. */
        'Export Floor Blockers' => $GLOBALS['__pp_export_floor_blockers'] ?? null,
        'Final Export Floor Reassertion' => $GLOBALS['__pp_final_export_floor_reassertion'] ?? null,
        'Unit Skip Load Invariant' => $GLOBALS['__pp_skip_load_invariant'] ?? null,
        'Unit Skip Load Branch' => $GLOBALS['__pp_skip_load_branch'] ?? null,
        'Export Minimization Measurement' => (isset($GLOBALS['__pp_expmin_gas_before']) ? [
            'gas_before_export_reduction_bbtud' => round((float)$GLOBALS['__pp_expmin_gas_before'], 4),
            'gas_after_export_reduction_bbtud'  => round((float)($GLOBALS['__pp_expmin_gas_after'] ?? 0), 4),
            'gas_freed_by_export_reduction_bbtud' => round((float)$GLOBALS['__pp_expmin_gas_before']
                                                     - (float)($GLOBALS['__pp_expmin_gas_after'] ?? 0), 4),
            'export_before' => $GLOBALS['__pp_expmin_export_before'] ?? null,
            'export_after'  => $GLOBALS['__pp_expmin_export_after'] ?? null,
            'note' => 'Diukur pada core run terakhir: gas dan Export sebelum vs sesudah blok koreksi gas + export-floor repair.',
        ] : null),
        'MM2100 Gas Trim Evidence'   => $GLOBALS['__pp_mm2100_trim_ev'] ?? null,
        /* Lantai kuota MM2100: bukti bahwa lantai benar-benar ditegakkan (atau alasan
         * mengapa tidak bisa), bukan sekadar hasil akhirnya. */
        'MM2100 Quota Floor Evidence' => $GLOBALS['__pp_mm2100_floor_ev'] ?? null,
        'MM2100 Blended Cap Trim' => $GLOBALS['__pp_mm2100_cap_trim'] ?? null,
        'MM2100 Quota Floor Reassertion' => $GLOBALS['__pp_mm2100_floor_reassertion'] ?? null,
        /* §10 reconciliation: Required vs Scheduled/Consumed/Summary + tolerance + status. */
        'Distillate Reconciliation'    => (function () use ($shortage, $dist_total_litres, $data, $action, $model) {
            if ($shortage <= 0 && $dist_total_litres <= 0) return null;
            $required = $shortage > 0 ? pp_distillate_litres_from_bbtu($shortage, $model) : 0.0;
            $summary  = 0.0; foreach ($data as $r) $summary += (float)($r['Dist_Total'] ?? 0);
            $consumed = $dist_total_litres;
            /* tolerance konsumsi vs summary = pembulatan row (0.1 l x 48) ; vs required = 1 slot penuh
             * (overshoot diskrit tak terhindar) — didokumentasikan. */
            $tolSummary = 5.0;
            $oneSlotMax = 0.0; foreach ($data as $r) { $mx = 0.0; foreach (['G1','G2','G3','G4','G5','G6','G7','G8','G9','G10'] as $U) if (isset($r['DistMix_' . $U])) $mx = max($mx, (float)($r['Dist_' . $U] ?? 0)); $oneSlotMax = max($oneSlotMax, $mx); }
            $sumOk = abs($summary - $consumed) <= $tolSummary;
            $reqOk = pp_action_uses_distillate($action) ? (($consumed - $required) >= -$tolSummary && ($consumed - $required) <= $oneSlotMax + $tolSummary) : true;
            return [
                'required_l'    => round($required, 1),
                'scheduled_l'   => round($consumed, 1),
                'consumed_l'    => round($consumed, 1),
                'summary_l'     => round($summary, 1),
                'difference_l'  => round($summary - $consumed, 1),
                'overshoot_vs_required_l' => round($consumed - $required, 1),
                'tolerance_summary_l' => $tolSummary,
                'tolerance_required_l' => round($oneSlotMax, 1),
                'status' => ($sumOk && $reqOk) ? 'PASS' : 'FAIL',
            ]; })(),
        /* ===== PROMPT GAS SHORTAGE §10/§11/§18: kandidat + preferred + mix + exhaustion proof ===== */
        'Gas Shortage Candidates'      => $GLOBALS['__pp_gs_candidates'] ?? [],
        'Gas Shortage Preferred'       => $GLOBALS['__pp_gs_preferred'] ?? ($shortage > 0 ? $action : '-'),
        'Distillate Mix per Unit (%)'  => (function () {
            $o = []; foreach (($GLOBALS['__pp_dist_mix'] ?? []) as $u => $f) $o[strtoupper($u)] = round($f * 100);
            return $o; })(),
        'Gas Shortage Exhaustion Proof' => (function () use ($shortage, $data, $model, $actualRows, $effTotalQuota, $gas_BBTUD) {
            if ($shortage <= 0) return null;
            /* §18: bukti export sudah diminimalkan & tak ada lever gas-only tersisa SEBELUM fuel
             * tambahan direkomendasikan. margin = step tolerance export engine (0.5 MW) — nilai
             * yang sama dipakai seluruh pass dispatch; validator menerima >= RangeMin persis. */
            $rMin = (float)($model['pln_export_priority']['range']['min'] ?? 0);
            $marg = 0.5;
            $adjExp = []; $lockedN = 0;
            foreach ($data as $i => $r) {
                if (isset($actualRows[$i])) { $lockedN++; continue; }     // TIME PASSED Y / actual: tak boleh diubah
                $adjExp[] = (float)($r['Export_PLN'] ?? 0);
            }
            $near = count(array_filter($adjExp, fn($e) => $e <= $rMin + $marg + 1e-9));
            $under = count(array_filter($adjExp, fn($e) => $e < $rMin - 1e-9));
            return [
                'range_min'            => $rMin,
                'safe_margin_mw'       => $marg,
                'rows_adjustable'      => count($adjExp),
                'rows_locked'          => $lockedN,
                'rows_near_min'        => $near,
                'rows_under_min'       => $under,                          // wajib 0 (§2.4)
                'export_min_adjustable'=> $adjExp ? round(min($adjExp), 2) : null,
                'export_max_adjustable'=> $adjExp ? round(max($adjExp), 2) : null,
                'gas_required_bbtud'   => round($gas_BBTUD, 4),
                'gas_available_bbtud'  => round($effTotalQuota, 4),
                'residual_bbtud'       => round($shortage, 4),
                'note' => 'Export sudah diturunkan menuju Range Min oleh pass gas-limited (down-order ke valid min, seluruh horizon future); start baru ditunda bila headroom unit prioritas tinggi cukup; segmen ON pendek dihapus (anti churn). Residual di atas = kebutuhan fuel yang tidak dapat dihindari.',
            ]; })(),
        'IE Prediction (MWh)' => round($ie_total_MWh, 2),
        'IE Prediction Max (MW)' => round($ie_max, 2),
        'JBBK MM Prod (MWh)' => round($jbbk_total_MWh, 2),
        'Babelan Prod (MWh)' => round($bbln_total_MWh, 2),
        'Babelan CF (%)' => round($babelanCF, 2),
        'Dispatch (MWh)' => round($disp_total_MWh, 2),
        'Daily PLN Exp (MWh)' => round($exp_total_MWh, 2),
        /* §1/§3 IDENTITAS ENERGI: TOTAL FUEL = GAS FUEL TOTAL + DISTILLATE ENERGY.
         *   TOTAL FUEL        = gross total fuel energy (kebutuhan sebelum pemisahan jenis) = $gas_BBTUD.
         *   DISTILLATE ENERGY = energi gas yang digantikan distillate (row-level: Σ fuel×fraksi) = offset.
         *   GAS FUEL TOTAL    = gas fisik EFEKTIF yang dibakar = gross − distillate energy.
         * Satu sumber: offset dihitung engine dari schedule aktual (__pp_dist_gas_offset). */
        'Total Fuel (BBTUD)' => round($gas_BBTUD, 4),
        'Gas Fuel Total (BBTUD)' => round($gas_BBTUD - (pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0), 4),
        'Distillate Energy (BBTUD)' => round(pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0, 4),
        'Gross Total Fuel Energy (BBTUD)' => round($gas_BBTUD, 4),   // alias eksplisit utk audit identitas
        'Total Gas Quota (BBTUD)' => round($effTotalQuota, 4),
        /* ============================================================================
         *  GAS ACCOUNT SEPARATION — raw flow (MMSCFD) dan energy (BBTUD) DIPISAH.
         *  Tidak ada accumulator yang mencampur satuan atau arti berbeda.
         *  Konvensi: Fixed Flow = MMSCFD -> energy = MMSCFD x GHV / 1000.
         *            Cumulative = sudah BBTUD, tidak dikonversi.
         * ========================================================================== */
        'Gas Accounts' => (function () use ($model, $fixMM_MMSCFD, $fixMM_BBTUD, $cumMM_BBTUD,
                                            $pgnPipeQuota, $lngQuota, $effTotalQuota, $gas_BBTUD,
                                            $dist_total_litres, $ghvM) {
            $qG   = (array)($model['gas_quota'] ?? []);
            $ghvJ = (float)($model['ghv_jababeka'] ?? 1000);
            $ffJKeys = ['pep', 'akasia', 'baskara', 'bbg'];
            $jFlow = 0.0; $jPer = [];
            foreach ($ffJKeys as $k) {
                $v = (float)($qG[$k] ?? 0); $jFlow += $v;
                $jPer[$k] = ['flow_mmscfd' => round($v, 4), 'energy_bbtud' => round($v * $ghvJ / 1000.0, 4)];
            }
            $jEnergy = $jFlow * $ghvJ / 1000.0;
            $totalGasEnergy = (float)$pgnPipeQuota + (float)$lngQuota + $jEnergy + $cumMM_BBTUD + $fixMM_BBTUD;
            return [
                'PGN_BBTUD'                     => round((float)$pgnPipeQuota, 4),
                'LNG_BBTUD'                     => round((float)$lngQuota, 4),
                'JABABEKA_FIXED_FLOW_MMSCFD'    => round($jFlow, 4),
                'JABABEKA_FIXED_ENERGY_BBTUD'   => round($jEnergy, 4),
                'JABABEKA_FIXED_PER_SOURCE'     => $jPer,
                'MM2100_CUMULATIVE_BBTUD'       => round($cumMM_BBTUD, 4),
                'MM2100_FIXED_FLOW_MMSCFD'      => round($fixMM_MMSCFD, 4),
                'MM2100_FIXED_ENERGY_BBTUD'     => round($fixMM_BBTUD, 4),
                'TOTAL_GAS_ENERGY_BBTUD'        => round($totalGasEnergy, 4),
                'DISTILLATE_LITRES'             => round((float)$dist_total_litres, 1),
                'TOTAL_FUEL_ENERGY_BBTUD'       => round((float)$gas_BBTUD, 4),
                'GHV'                           => ['jababeka' => $ghvJ, 'mm2100' => (float)$ghvM,
                                                    'pgn' => (float)($model['ghv_pgn'] ?? 0)],
                'RECONCILIATION' => [
                    'total_gas_energy_vs_effective_quota' => round($totalGasEnergy - (float)$effTotalQuota, 6),
                    'note' => 'JABABEKA_FIXED_ENERGY_BBTUD = JABABEKA_FIXED_FLOW_MMSCFD x GHV jababeka / 1000; '
                            . 'MM2100_FIXED_ENERGY_BBTUD = MM2100_FIXED_FLOW_MMSCFD x GHV mm2100 / 1000; '
                            . 'MM2100_CUMULATIVE_BBTUD dipakai apa adanya (tidak dikonversi, tidak dikali 48). '
                            . 'MMSCFD tidak pernah dijumlahkan dengan BBTUD.',
                ],
            ];
        })(),
        /* KEBIJAKAN PRESISI: UI 2 desimal · perhitungan internal full precision ·
         * audit evidence minimal 5 desimal · validator TIDAK boleh memakai angka display.
         * Nilai display tetap 4 desimal untuk kompatibilitas, tetapi nilai FULL PRECISION
         * dipublikasikan terpisah pada 'MM2100 Precision' agar validator memakainya. */
        'MM2100 Precision' => ['quota_bbtud' => $quotaM_energy,
                               'used_plus_startup_bbtud' => $mmUsedTotal,
                               'daily_used_bbtud' => $mmDailyUsed,
                               'startup_gas_bbtud' => $mmStartGas,
                               'audit_quota_5dp' => round($quotaM_energy, 5),
                               'audit_used_5dp'  => round($mmUsedTotal, 5)],
        'MM2100 Quota (BBTUD)' => round($quotaM_energy, 4),
        'MM2100 Daily Used (BBTUD)' => round($mmDailyUsed, 4),
        'MM2100 Startup Gas G10 (BBTUD)' => round($mmStartGas, 4),
        'MM2100 Used + Startup (BBTUD)' => round($mmUsedTotal, 4),
        'MM2100 Quota Status' => $mmStatus,
        'Maximum Flow MM2100 (MMSCFD)' => round((float)($model['max_flow_mm2100'] ?? 0), 4),   // ADDENDUM 2.1: satuan MMSCFD
        'Base Gas Quota (BBTUD)' => round($gasQuotaTotal, 4),
        'Actual Last Time' => $actualLastRow > 0 ? sprintf('%02d:%02d', intdiv($actualLastRow * 30, 60) % 24, ($actualLastRow * 30) % 60) : '',
        'Simulation Start Time' => $actualLastRow > 0 && ($actualLastRow + 1) <= $nRows ? sprintf('%02d:%02d', intdiv(($actualLastRow + 1) * 30, 60) % 24, (($actualLastRow + 1) * 30) % 60) : '',
        'Actual Rows Count' => $actualLastRow,
        'Added LNG (BBTUD)' => round(in_array($action, ['add_lng','mixed_lng_distillate'], true) ? $addLng : 0.0, 4),
        /* ==========================================================================================
         * REKONSILIASI BAHAN BAKAR YANG TIDAK AMBIGU (kontrak §6).
         *
         * Ringkasan lama memuat `Base Gas Quota`, `Added LNG`, dan `LNG Used`, tetapi TIDAK memuat
         * kuota efektif, sisa shortage, maupun asal tindakan. Akibatnya operator tidak dapat
         * membedakan angka yang BARU DIREKOMENDASIKAN dari angka yang BENAR-BENAR DITERAPKAN —
         * dan itulah inti keluhannya. Enam kunci di bawah menutup celah itu, seluruhnya diturunkan
         * dari nilai yang sudah dihitung engine, bukan dihitung ulang dengan rumus kedua.
         *
         * `Effective Gas Quota` = kuota dasar + LNG yang BENAR-BENAR ditambahkan. LNG bersifat
         * must-take sehingga menaikkan kuota; distillate TIDAK menaikkan kuota melainkan MENGURANGI
         * kebutuhan gas, dan karena itu muncul sebagai `Gas Offset`, bukan sebagai tambahan kuota.
         * Memperlakukan keduanya sama akan menyesatkan pembacaan neraca gas.
         * ========================================================================================== */
        /* MENCERMINKAN ANGKA ENGINE, BUKAN MENGHITUNG ULANG. Menyusun sendiri
         * (kuota dasar + LNG) menghasilkan 69,3605 sementara engine memakai 69,2078 — karena
         * `$gasQuotaTotal` di titik ini belum sama dengan kuota dasar yang akhirnya dilaporkan.
         * Dua angka kuota efektif yang berbeda di satu ringkasan persis ambiguitas yang harus
         * dihapus, jadi yang dipakai adalah kuota efektif milik engine sendiri. */
        'Effective Gas Quota (BBTUD)' => round($effTotalQuota, 4),
        'Gas Offset (BBTUD)' => round(pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0, 4),
        /* BASIS SHORTAGE MENGIKUTI ENGINE, BUKAN RUMUS KEDUA. Menghitung ulang sebagai
         * (gas - kuota) menghasilkan 9,6985 sementara engine melaporkan 9,7385: selisih 0,04 itu
         * adalah lebar window supplier yang memang dipakai engine sebagai acuan. Dua angka shortage
         * yang berbeda di satu ringkasan persis jenis ambiguitas yang harus dihapus, jadi nilai
         * engine dipakai dan hanya dikurangi oleh bahan bakar yang BENAR-BENAR diterapkan. */
        'Residual Gas Shortage (BBTUD)' => round(max(0.0,
            (float)$shortage
            - (in_array($action, ['add_lng','mixed_lng_distillate'], true) ? $addLng : 0.0)
            - (pp_action_uses_distillate($action) ? ($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0)), 4),
        'Distillate User Limit (l)' => pp_action_uses_distillate($action)
            ? (is_numeric($model['distillate_user_limit_litres'] ?? null)
                ? round((float)$model['distillate_user_limit_litres'], 1) : null) : null,
        'Distillate Used (l)' => round(pp_action_uses_distillate($action) ? $dist_total_litres : 0.0, 1),
        /* RINCIAN BAHAN BAKAR CAMPURAN (instruksi §5). Angka-angka ini memisahkan apa yang
         * DIBUTUHKAN, apa yang DIOTORISASI operator, apa yang BENAR-BENAR diterapkan, dan berapa
         * sisa yang harus ditutup distillate — supaya tidak ada satu angka pun yang harus ditebak. */
        'Required LNG Equivalent (BBTUD)' => ($action === 'mixed_lng_distillate')
            ? round((float)$shortage + $addLng, 4) : null,
        'User Authorized LNG (BBTUD)' => ($action === 'mixed_lng_distillate') ? round($addLng, 4) : null,
        'Applied LNG (BBTUD)' => ($action === 'mixed_lng_distillate') ? round($addLng, 4) : null,
        'Remaining Shortage Before Distillate (BBTUD)' => ($action === 'mixed_lng_distillate')
            ? round(max(0.0, (float)$shortage), 4) : null,
        'Auto Distillate Required (l)' => ($action === 'mixed_lng_distillate')
            ? round((float)($GLOBALS['__pp_dist_required_litres'] ?? $dist_total_litres), 1) : null,
        /* Asal tindakan: apa yang DIMINTA operator, bukan sekadar apa yang dijalankan engine.
         * `recommendation` tampak sebagai `none` pada dispatch, sehingga tanpa kunci ini kedua
         * keadaan itu tidak dapat dibedakan di ringkasan. */
        'Fuel Action Source' => (string)($model['__fuel_decision_mode']
                                  ?? ($GLOBALS['__pp_fuel_decision_mode'] ?? $action)),
        'Gas vs Quota (BBTUD)' => round($gas_BBTUD - $gasQuotaTotal, 4),
        'Gas Utilization (%)' => $effTotalQuota > 0 ? round(100 * $gas_BBTUD / $effTotalQuota, 2) : 0,
        'Distillate Fuel Total (l)' => round($dist_total_litres, 1),
        'Distillate per unit (l)' => $dist_units,
        /* PROMPT GAS_ACCOUNTING §5.1: identitas PGN TOTAL = PGN PIPE TOTAL + LNG TOTAL.
         * Tanpa actual override -> satu sumber kebenaran (pipeUsed+lngUsed); dgn actual ->
         * campuran actual+estimation dilabeli via 'Actual PGN Total (BBTUD)' terpisah. */
        /* Identitas PGN TOTAL = PIPE + LNG wajib di KEDUA cabang. Dgn actual, $pipeUsed sudah
         * = pgnTotal(blended) + startupGasPenalty - LNG, jadi PIPE+LNG = blended + penalty —
         * konsisten dgn jalur estimasi (estPGN + penalty). Melaporkan $pgnTotal polos memecah
         * identitas sebesar penalty (repro: 27.4131 vs 27.4931+0, selisih -0.08). */
        'PGN Total (BBTUD)' => round($pipeUsed + $lngUsed, 4),
        'Actual Total Gas PGN (BBTUD)' => round($actTotGasPGN, 4),
        'Actual Energy Total Fixed Flow Jababeka (BBTUD)' => round($actEnergyJsum, 4),
        'Actual Energy Total Fixed Flow MM2100 (BBTUD)' => round($actEnergyMsum, 4),
        'PGN Pipe Total (BBTUD)' => round($pipeUsed, 4),
        'LNG Total (BBTUD)' => round($lngUsed, 4),
        'PEP JBBK Total (BBTUD)' => round($pepU, 4),
        'PEP KP72 Total (BBTUD)' => round($pepKpU, 4),
        'Akasia JBBK Total (BBTUD)' => round($akasiaU, 4),
        'Akasia KP72 Total (BBTUD)' => round($akasiaKpU, 4),
        'BaGS JBBK Total (BBTUD)' => round($baskaraU, 4),
        'BaGS KP72 Total (BBTUD)' => round($baskaraKpU, 4),
        'Fixed Flow JBBK (BBTUD)' => round($usedJ, 4),
        'Fixed Flow MM2100 (BBTUD)' => round($usedM, 4),
        'GHV Jababeka (BTU/SCF)' => round($ghvJ, 4),
        'GHV MM2100 (BTU/SCF)' => round($ghvM, 4),
        'Estimation PGN Total (BBTUD)' => round($estPGN, 4),
        'Estimation Energy Jababeka (BBTUD)' => round($estEnergyJ, 4),
        'Estimation Energy MM2100 (BBTUD)' => round($estEnergyM, 4),
        /* PROMPT ZERO PENALTY §5 — LABEL ACTUAL: bila TIDAK ada baris actual user (count = 0),
         * nilai blend hanyalah salinan estimation dan DILARANG dilabeli "Actual" (wrong alias).
         * Field menjadi null + Source = NONE; dgn actual -> 'user-blend' (campuran actual+estimation). */
        'Actual PGN Total (BBTUD)' => ($nApgn > 0 ? round($actPGN, 4) : null),
        'Actual PGN Source' => ($nApgn > 0 ? 'user-blend' : 'NONE'),
        'Actual Energy Jababeka (BBTUD)' => ($nAej > 0 ? round($actEnergyJ, 4) : null),
        'Actual Energy Jababeka Source' => ($nAej > 0 ? 'user-blend' : 'NONE'),
        'Actual Energy MM2100 (BBTUD)' => ($nAem > 0 ? round($actEnergyM, 4) : null),
        'Actual Energy MM2100 Source' => ($nAem > 0 ? 'user-blend' : 'NONE'),
        /* PROMPT ZERO PENALTY §5 — GAS RECONCILIATION: SATU sumber kebenaran utk Summary/Report/
         * validator/harness. Identitas inti (penalty=0): Effective PGN = Estimation run-rate
         * (+ penalty 0) = Pipe + LNG; Total efektif = angka yang divalidasi window. */
        'Gas Reconciliation' => [
            'quota_total'            => round($gasQuotaTotal, 4),
            'strict_min'             => round($gasQuotaTotal - 0.04, 4),
            'estimation_runrate_pgn' => round($estPGN, 4),
            'startup_gas_penalty'    => round($startupGasPenalty, 4),
            'actual_user_pgn'        => ($nApgn > 0 ? round($actPGN, 4) : null),
            'actual_rows_count'      => (int)$actualLastRow,          // baris ACTUAL DISPATCH (TIME PASSED)
            'actual_gas_rows_pgn'    => (int)$nApgn,                  // baris ACTUAL GAS PGN yang diisi operator
            'actual_gas_rows_energy_jbbk' => (int)$nAej,
            'actual_gas_rows_energy_mm'   => (int)$nAem,
            'actual_source'          => ($nApgn > 0 ? 'user-blend' : 'NONE'),
            'effective_pgn_pipe'     => round($pipeUsed, 4),
            'effective_lng'          => round($lngUsed, 4),
            'effective_pgn_total'    => round($pipeUsed + $lngUsed, 4),
            /* identitas sadar-klip: saat shortage, pipe diklip ke quota — identitas tetap sah
             * bila (pipe+lng) = blend+pen ATAU pipe = quota & kebutuhan >= quota (clipped). */
            'identity_pgn'           => (abs(($pipeUsed + $lngUsed) - (($nApgn > 0 ? $actPGN : $estPGN) + $startupGasPenalty)) < 0.002)
                                        || (abs($pipeUsed - (float)($q['pgn_pipe'] ?? 0)) < 1e-6
                                            && (($nApgn > 0 ? $actPGN : $estPGN) + $startupGasPenalty) >= $pipeUsed + $lngUsed - 0.002),
            'identity_pgn_clipped'   => (abs($pipeUsed - (float)($q['pgn_pipe'] ?? 0)) < 1e-6
                                            && (($nApgn > 0 ? $actPGN : $estPGN) + $startupGasPenalty) > $pipeUsed + $lngUsed + 0.002),
            'pep_jbbk'               => round($pepU, 4),
            'pep_kp72'               => round($pepKpU, 4),
            'akasia_jbbk'            => round($akasiaU, 4),
            'akasia_kp72'            => round($akasiaKpU, 4),
            'bags_jbbk'              => round($baskaraU, 4),
            'bags_kp72'              => round($baskaraKpU, 4),
            'mm2100_fixed'           => round($fixMM_BBTUD, 4),
            'mm2100_cumulative'      => round($cumMM_BBTUD, 4),
            'effective_total_gas'    => round($effTotalGas, 4),
            'window_status_total'    => (pp_gas_in_window($effTotalGas, $gasQuotaTotal) ? 'IN' : ($effTotalGas > $gasQuotaTotal ? 'OVER' : 'UNDER')),
        ],
        'Actual Total Gas (BBTUD)' => round($actualTotalGas, 4),
        'Actual Hours Provided' => $actualHoursUsed,
        'Total Coal (ton)' => round($coal_total, 3),
        'Total RE Babelan (MWh)' => round($re_total_MWh, 3),
        '% RE Babelan (%)' => round($bbln_total_MWh > 1e-9 ? ($re_total_MWh / $bbln_total_MWh) * 100 : 0, 1),
        'Biomass Target (MWh)' => round($biomassTarget, 3),
        'Active Babelan Unit-Rows' => $activeBBRows,
        'Biomass Allocation per Active Row (MW)' => round($bioAlloc, 4),
        /* GHG EMISSION (ton CO2e/MWh) — memakai nilai numerik source of truth yang sama dengan
         * Summary, bukan membaca DOM. Denominator mencakup produksi JBBK dan Babelan.
         * carbon_pct = modeling.carbon_pct (0..100). Bila denominator <= 0 -> null (Summary
         * menampilkan '-') dengan reason ZERO_TOTAL_PRODUCTION. */
        'GHG Emission (ton CO2e/MWh)' => (function () use ($gas_BBTUD, $dist_total_litres, $coal_total,
                                                          $jbbk_total_MWh, $bbln_total_MWh, $model, $action) {
            $gasT  = (float)$gas_BBTUD - (pp_action_uses_distillate($action) ? (float)($GLOBALS['__pp_dist_gas_offset'] ?? 0.0) : 0.0);
            $distT = (float)$dist_total_litres;
            $coalT = (float)$coal_total;
            $ef = is_array($model['emission_factor'] ?? null) ? $model['emission_factor'] : [];
            $gasEf = is_numeric($ef['gas_tco2_per_bbtud'] ?? null) ? max(0.0, (float)$ef['gas_tco2_per_bbtud']) : 53.7;
            $distEf = is_numeric($ef['distillate_tco2_per_liter'] ?? null) ? max(0.0, (float)$ef['distillate_tco2_per_liter']) : 0.00233;
            $cRaw = $ef['coal_carbon_pct'] ?? ($model['carbon_pct'] ?? 43.0);
            $cPct = is_numeric($cRaw) ? (float)$cRaw : 43.0;          /* zero is valid */
            if ($cPct < 0) $cPct = 0.0; if ($cPct > 100) $cPct = 100.0;
            $den = (float)$jbbk_total_MWh + (float)$bbln_total_MWh;
            if ($den <= 0) return null;                                /* ZERO_TOTAL_PRODUCTION */
            $num = ($gasT * $gasEf) + ($distT * $distEf) + ($coalT * ($cPct / 100.0) * (98.0 / 100.0) * (44.0 / 12.0));
            return round($num / $den, 4);
        })(),
        'Distillate Export Floor Policy' => $GLOBALS['__pp_distillate_export_floor_policy'] ?? null,
        'GHG Emission Factors' => [
            'gas_tco2_per_bbtud' => is_numeric($model['emission_factor']['gas_tco2_per_bbtud'] ?? null) ? max(0.0, (float)$model['emission_factor']['gas_tco2_per_bbtud']) : 53.7,
            'distillate_tco2_per_liter' => is_numeric($model['emission_factor']['distillate_tco2_per_liter'] ?? null) ? max(0.0, (float)$model['emission_factor']['distillate_tco2_per_liter']) : 0.00233,
            'coal_carbon_pct' => max(0.0, min(100.0, is_numeric($model['emission_factor']['coal_carbon_pct'] ?? null) ? (float)$model['emission_factor']['coal_carbon_pct'] : (is_numeric($model['carbon_pct'] ?? null) ? (float)$model['carbon_pct'] : 43.0)))
        ],
        'GHG Reason' => (((float)$jbbk_total_MWh + (float)$bbln_total_MWh) <= 0) ? 'ZERO_TOTAL_PRODUCTION' : null,
        /* ============================================================================
         *  STARTUP PROVENANCE (audit generik, tanpa hardcode unit)
         *
         *  Untuk SETIAP unit yang menghasilkan load padahal (a) tidak di required_units,
         *  (b) tidak punya required_mode, dan (c) Last Data Status = Stop, dicatat asal-usul
         *  startnya sehingga operator dapat membedakan:
         *    OPERATOR_REQUESTED        - diminta operator
         *    AUTO_START_<CONSTRAINT>   - automatic commitment karena hard constraint (ada evidence)
         *    STG_DERIVED_FROM_BLOCK_GTG- STG mengikuti GTG blok-nya (konsekuensi, bukan start mandiri)
         *    UNREQUESTED_START_NO_EVIDENCE - start diam-diam TANPA evidence (ilegal)
         * ========================================================================== */
        'Startup Provenance' => (function () use ($data, $model, $d3, $warnings) {
            $rows = (array)$data;
            if (!$rows) return [];
            $req  = array_map('strtolower', (array)($model['required_units'] ?? []));
            $rmode= (array)($model['required_mode'] ?? []);
            $lds  = (array)($model['unit_last_data_status'] ?? []);
            $wtxt = implode(' | ', array_map(fn($w) => is_array($w) ? json_encode($w) : (string)$w, (array)$warnings));
            /* peta blok GTG -> STG dari block_priority (generik, bukan daftar tetap) */
            $blockOf = [];
            foreach ((array)($model['block_priority'] ?? []) as $bi => $grp) {
                foreach ((array)$grp as $g) { $g = strtolower((string)$g); if ($g === 'required') continue; $blockOf[$g] = $bi; }
            }
            $isSTG = fn($u) => (bool)preg_match('/^s\d+$/', $u);
            $prov = [];
            foreach (array_keys($blockOf) as $u) {
                $C = strtoupper($u); if ($C === 'B1' || $C === 'B2') $C = 'BB' . substr($C, 1);
                $n = 0; $first = null; $sum = 0.0;
                foreach ($rows as $i => $r) { $v = (float)($r[$C] ?? 0);
                    if ($v > 0.01) { $n++; $sum += $v; if ($first === null) $first = $i + 1; } }
                if ($n === 0) continue;                                   /* tidak menyala */
                $requested = in_array($u, $req, true) || isset($rmode[$u]);
                $wasRunning = strtolower(trim((string)($lds[strtoupper($u)] ?? ''))) === 'running';
                /* CHANGE OVER: unit target/source blok change-over menjadi "required" lewat resolusi
                 * timeline, bukan lewat permintaan operator. Tanpa klasifikasi ini unit tersebut
                 * hilang dari audit sehingga tampak seolah tidak pernah di-start. */
                $coRole = null;
                foreach ((array)($model['change_over']['blocks'] ?? []) as $blk) {
                    if (empty($model['change_over']['enabled'])) break;
                    $bg = strtolower((string)($blk['gtg'] ?? '')); $bs = strtolower((string)($blk['stg'] ?? ''));
                    if ($u !== $bg && $u !== $bs) continue;
                    $coRole = (strtolower((string)($blk['last_status'] ?? '')) === 'running')
                            ? 'CHANGE_OVER_SOURCE' : 'CHANGE_OVER_TARGET_START';
                }
                if ($coRole !== null && !$wasRunning) {
                    $prov[strtoupper($u)] = ['unit' => strtoupper($u), 'requested' => false,
                        'last_data_status' => (string)($lds[strtoupper($u)] ?? ''),
                        'reason_code' => $coRole, 'first_row' => $first, 'rows_on' => $n,
                        'total_mwh_row' => round($sum, 2),
                        'detail' => 'unit blok change-over; waktu start/stop ditentukan candidate timeline sweep'];
                    continue;
                }
                if ($requested || $wasRunning) continue;                  /* bukan start tak diminta */
                $reason = 'UNREQUESTED_START_NO_EVIDENCE';
                $detail = '';
                if ($isSTG($u)) {
                    /* STG: cari GTG pada blok yang sama yang juga menyala -> konsekuensi cascade */
                    $bi = $blockOf[$u]; $src = [];
                    foreach ($blockOf as $g => $b) {
                        if ($b !== $bi || $isSTG($g)) continue;
                        $G = strtoupper($g); if ($G === 'B1' || $G === 'B2') $G = 'BB' . substr($G, 1);
                        foreach ($rows as $r) if ((float)($r[$G] ?? 0) > 0.01) { $src[] = strtoupper($g); break; }
                    }
                    if ($src) { $reason = 'STG_DERIVED_FROM_BLOCK_GTG';
                        $detail = 'load STG mengikuti GTG blok ' . $bi . ': ' . implode(',', $src)
                                . ' (konsekuensi HRSG, bukan start mandiri)'; }
                } elseif (preg_match('/AUTO_START_[A-Z_]+/', $wtxt, $mm)
                          && stripos($wtxt, strtoupper($u)) !== false) {
                    $reason = $mm[0];
                    $detail = 'evidence tersedia pada Warnings';
                }
                /* KANDIDAT TERTOLAK (terstruktur, generik): pada row start unit ini, laporkan
                 * setiap unit ber-priority LEBIH TINGGI beserta headroom tersisa dan alasan
                 * penolakannya. Urutan priority dibaca dari pp_priority_flat(model). */
                $rejected = [];
                if ($reason !== 'STG_DERIVED_FROM_BLOCK_GTG' && $first !== null) {
                    $order = pp_priority_flat($model);
                    $selfIx = array_search($u, $order, true);
                    $rowIdx = $first - 1;
                    foreach ($order as $ix => $cu) {
                        if ($selfIx !== false && $ix >= $selfIx) break;      /* hanya yang lebih tinggi */
                        if ($isSTG($cu)) continue;                            /* STG bukan lever start */
                        if (!pp_unit_present($d3, $cu)) {
                            $rejected[] = ['unit' => strtoupper($cu), 'reason' => 'NOT_PRESENT', 'headroom_mw' => 0.0];
                            continue;
                        }
                        $CU = strtoupper($cu); if ($CU === 'B1' || $CU === 'B2') $CU = 'BB' . substr($CU, 1);
                        $load = (float)(($rows[$rowIdx][$CU] ?? 0));
                        $emax = pp_effective_max_load($d3, $model, $cu, $first);
                        $head = round(max(0.0, $emax - $load), 2);
                        $why  = ($head <= 0.01)
                              ? (($load > 0.01) ? 'AT_EFFECTIVE_MAX' : 'NO_HEADROOM')
                              : (($load > 0.01) ? 'HEADROOM_INSUFFICIENT_OR_RAMP_LOCKED' : 'OFF_AND_NOT_SELECTED');
                        $rejected[] = ['unit' => $CU, 'reason' => $why, 'load_mw' => round($load, 2),
                                       'effective_max_mw' => round($emax, 2), 'headroom_mw' => $head];
                    }
                }
                /* PRIORITY INVERSION: unit yang di-start otomatis TIDAK boleh berada di bawah
                 * unit lain yang (a) present, (b) tidak dijadwalkan stop, (c) punya headroom,
                 * namun tetap 0 MW sepanjang horizon. Bila terjadi, escalation tidak mengikuti
                 * Unit Priority dan wajib ditandai untuk audit. */
                $inversion = [];
                foreach ($rejected as $rc) {
                    if (($rc['reason'] ?? '') !== 'OFF_AND_NOT_SELECTED') continue;
                    if ((float)($rc['headroom_mw'] ?? 0) <= 0.01) continue;
                    $cu = strtolower((string)$rc['unit']);
                    $CU = strtoupper($cu); if ($CU === 'B1' || $CU === 'B2') $CU = 'BB' . substr($CU, 1);
                    $usedAnywhere = false;
                    foreach ($rows as $rr) if (((float)($rr[$CU] ?? 0)) > 0.01) { $usedAnywhere = true; break; }
                    if (!$usedAnywhere) $inversion[] = ['unit' => $rc['unit'],
                        'headroom_mw' => $rc['headroom_mw'], 'rows_on_whole_horizon' => 0];
                }
                $prov[strtoupper($u)] = ['priority_inversion' => $inversion,
                    'priority_inversion_flag' => $inversion ? 'PRIORITY_INVERSION_ON_AUTO_START' : null,
                    'rejected_candidates' => $rejected, 'unit' => strtoupper($u), 'requested' => false,
                    'last_data_status' => (string)($lds[strtoupper($u)] ?? ''),
                    /* TAKSONOMI (keputusan domain): legacy alias dipertahankan agar audit trail
                     * lama tetap terbaca; event classification dan root problem dipisah. */
                    'reason_code' => $reason,
                    'reason_code_legacy_alias' => $reason,
                    'event_classification' => ($reason === 'UNREQUESTED_START_NO_EVIDENCE')
                        ? 'ECONOMIC_COMMITMENT_WITH_MANDATORY_STARTUP_LEADIN' : $reason,
                    'root_problem' => ($reason === 'UNREQUESTED_START_NO_EVIDENCE')
                        ? 'BLOCK_LOCAL_ECONOMIC_COMMITMENT_GLOBAL_ALTERNATIVE_BETTER' : null,
                    'first_row' => $first, 'rows_on' => $n,
                    'total_mwh_row' => round($sum, 2), 'detail' => $detail];
            }
            return $prov;
        })(),
        /* ============================================================================
         *  EVIDENCE RECONCILIATION (generik, tanpa hardcode unit)
         *
         *  Engine dapat menjalankan beberapa pass kandidat; warning yang tersimpan kadang berasal
         *  dari pass yang BUKAN pemenang, sehingga menggambarkan state yang tidak terjadi pada
         *  output final. Pass ini memverifikasi setiap klaim "di-stop rows A-B" dan
         *  "di-start pada row R" terhadap dispatch FINAL, lalu menandai yang tidak cocok.
         *  Reason: STALE_EVIDENCE_FROM_EARLIER_PASS
         * ========================================================================== */
        'Evidence Reconciliation' => (function () use ($data, $warnings) {
            $rows = (array)$data; if (!$rows) return [];
            $col = function ($u) { $C = strtoupper($u);
                if ($C === 'B1' || $C === 'B2') $C = 'BB' . substr($C, 1); return $C; };
            $onAt = function ($u, $r1) use ($rows, $col) {         /* r1 = 1-based */
                $r = $rows[$r1 - 1] ?? null; if (!$r) return null;
                return ((float)($r[$col($u)] ?? 0)) > 0.01;
            };
            $out = [];
            foreach ((array)$warnings as $w) {
                $t = is_array($w) ? json_encode($w) : (string)$w;
                /* klaim STOP: "<UNIT> ... di-stop rows A-B" */
                if (strpos($t, 'di-stop rows') !== false && preg_match('/\b([A-Z]{1,3}\d{1,2})\b[^|]{0,120}?di-stop rows (\d+)-(\d+)/u', $t, $m)) {   // V7: pra-saring strpos (hasil identik)
                    $u = $m[1]; $a = (int)$m[2]; $b = (int)$m[3];
                    $still = 0; for ($r = $a; $r <= $b; $r++) if ($onAt($u, $r) === true) $still++;
                    if ($still > 0) $out[] = ['claim' => 'STOP', 'unit' => $u, 'rows' => "$a-$b",
                        'verdict' => 'STALE_EVIDENCE_FROM_EARLIER_PASS',
                        'detail' => sprintf('warning menyatakan %s di-stop rows %d-%d, tetapi pada output FINAL %s masih menyala pada %d row di window tsb',
                            $u, $a, $b, $u, $still),
                        'excerpt' => substr($t, 0, 120)];
                }
                /* klaim START: "<UNIT> ... di-start pada row R" */
                if (strpos($t, 'di-start pada row') !== false && preg_match('/\b([A-Z]{1,3}\d{1,2})\b[^|]{0,120}?di-start pada row (\d+)/u', $t, $m)) {   // V7: pra-saring strpos
                    $u = $m[1]; $r0 = (int)$m[2];
                    $first = null;
                    foreach ($rows as $i => $rr) if (((float)($rr[$col($u)] ?? 0)) > 0.01) { $first = $i + 1; break; }
                    if ($first !== null && $first !== $r0) $out[] = ['claim' => 'START', 'unit' => $u,
                        'claimed_row' => $r0, 'actual_first_row' => $first,
                        'verdict' => 'STALE_EVIDENCE_FROM_EARLIER_PASS',
                        'detail' => sprintf('warning menyatakan %s di-start pada row %d, tetapi pada output FINAL row pertama %s menyala adalah row %d',
                            $u, $r0, $u, $first),
                        'excerpt' => substr($t, 0, 120)];
                }
            }
            return $out;
        })(),
        'Heatrate' => round($heatrate, 2),
        'MM2100 Production (MWh)'          => round($mm_total_MWh, 2),
        'Jababeka Bus Net (MWh)'           => round($jbus_net_MWh, 2),
        /* §11 METRIC BUS — dihitung generik dari data3.modeling.bus_unit, tanpa daftar unit
         * hardcoded. Kontribusi MM2100 per bus dipisah agar dapat diaudit. */
        'Bus Metrics' => (function () use ($data, $model) {
            $bu = (array)($model['bus_unit'] ?? []);
            $lim = (float)($model['busflow_min'] ?? 0);
            $UN = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'];
            $mm = ['g10','ge1','ge2','ge3','ge4'];
            $col = function ($u) { $C = strtoupper($u); return ($C === 'B1' || $C === 'B2') ? 'BB' . substr($C, 1) : $C; };
            $aGen = 0.0; $bGen = 0.0; $mmA = 0.0; $mmB = 0.0; $minBF = INF; $rows = [];
            foreach ((array)$data as $ri => $r) {
                $g = []; foreach ($UN as $u) $g[$u] = (float)($r[$col($u)] ?? 0);
                $ra = 0.0; $rb = 0.0;
                foreach ($UN as $u) { $bus = strtoupper((string)($bu[$u . '_bus'] ?? ''));
                    if ($bus === 'A') { $ra += $g[$u]; if (in_array($u, $mm, true)) $mmA += $g[$u] / 2; }
                    if ($bus === 'B') { $rb += $g[$u]; if (in_array($u, $mm, true)) $mmB += $g[$u] / 2; } }
                $aGen += $ra / 2; $bGen += $rb / 2;
                $bf = calc_busflow($g, $bu, (float)($r['IE'] ?? 0));
                $minBF = min($minBF, $bf);
                $rows[] = ['row' => $ri + 1, 'bus_a_gen' => round($ra, 4), 'bus_b_gen' => round($rb, 4),
                           'bus_flow' => round($bf, 4), 'margin' => round($bf - $lim, 4)];
            }
            return ['bus_a_generation_mwh' => round($aGen, 2), 'bus_b_generation_mwh' => round($bGen, 2),
                    'mm2100_bus_a_contribution_mwh' => round($mmA, 2),
                    'mm2100_bus_b_contribution_mwh' => round($mmB, 2),
                    'bus_flow_min_mw' => round($minBF, 4), 'bus_flow_limit_mw' => $lim,
                    'bus_flow_margin_min_mw' => round($minBF - $lim, 4),
                    'bus_assignment_source' => 'data3.modeling.bus_unit',
                    'per_row' => $rows];
        })(),
        /* §4 CANDIDATE BUS FLOW BEFORE/AFTER — dampak Bus Flow dinilai PER KANDIDAT MM2100,
         * bukan hanya setelah commitment. Untuk setiap unit MM2100 dan setiap row, dihitung
         * Bus Flow tanpa unit tersebut (before) dan dengan output aktualnya (after), beserta
         * margin terhadap limit. Assignment bus dibaca dari konfigurasi, bukan hardcoded. */
        'MM2100 Candidate Bus Flow' => (function () use ($data, $model) {
            $bu  = (array)($model['bus_unit'] ?? []);
            $lim = (float)($model['busflow_min'] ?? 0);
            $UN  = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'];
            $mm  = ['ge1','ge2','ge3','ge4','g10'];
            $colf = function ($u) { $C = strtoupper($u); return ($C === 'B1' || $C === 'B2') ? 'BB' . substr($C, 1) : $C; };
            $out = [];
            foreach ($mm as $u) {
                $bus = strtoupper((string)($bu[$u . '_bus'] ?? ''));
                $rows = []; $minMargin = INF; $rejects = 0; $maxMW = 0.0;
                foreach ((array)$data as $ri => $r) {
                    $mw = (float)($r[$colf($u)] ?? 0);
                    if ($mw <= 0.01) continue;
                    $g = []; foreach ($UN as $uu) $g[$uu] = (float)($r[$colf($uu)] ?? 0);
                    $ie = (float)($r['IE'] ?? 0);
                    $after  = calc_busflow($g, $bu, $ie);
                    $gb = $g; $gb[$u] = 0.0;
                    $before = calc_busflow($gb, $bu, $ie);
                    $aA = 0.0; $aB = 0.0; $bA = 0.0; $bB = 0.0;
                    foreach ($UN as $uu) { $bs = strtoupper((string)($bu[$uu . '_bus'] ?? ''));
                        if ($bs === 'A') { $aA += $g[$uu]; $bA += $gb[$uu]; }
                        if ($bs === 'B') { $aB += $g[$uu]; $bB += $gb[$uu]; } }
                    $margin = $after - $lim; $valid = ($margin >= -1e-9);
                    if (!$valid) $rejects++;
                    $minMargin = min($minMargin, $margin); $maxMW = max($maxMW, $mw);
                    if (count($rows) < 6) $rows[] = ['row' => $ri + 1, 'proposed_mw' => round($mw, 3),
                        'bus_a_before' => round($bA, 3), 'bus_a_after' => round($aA, 3),
                        'bus_b_before' => round($bB, 3), 'bus_b_after' => round($aB, 3),
                        'bus_flow_before' => round($before, 4), 'bus_flow_after' => round($after, 4),
                        'bus_flow_limit' => $lim, 'margin' => round($margin, 4),
                        'valid' => $valid];
                }
                $acc = ($rejects === 0);
                $out[strtoupper($u)] = ['unit' => strtoupper($u), 'assigned_bus' => ($bus !== '' ? $bus : null),
                    'rows_evaluated' => count((array)$data), 'rows_on' => count($rows) ? null : 0,
                    'max_proposed_mw' => round($maxMW, 3),
                    'bus_flow_limit' => $lim, 'min_margin' => ($minMargin === INF ? null : round($minMargin, 4)),
                    'invalid_rows' => $rejects,
                    'accepted' => $acc,
                    'rejection_reason' => $acc ? null : 'REJECT_MM2100_CANDIDATE_BUS_FLOW_VIOLATION',
                    'sample_rows' => $rows];
            }
            return $out;
        })(),
        'Total Plant Cost Production (USD/MWh)' => round($comb_total_MWh > 0 ? (float)$totalCost / $comb_total_MWh : 0, 4),
        'Babelan Fuel' => ['coal_ton' => round((float)$coal_total, 3),
            'energy_bbtud' => null,
            'reason' => 'NILAI_KALOR_BATUBARA_TIDAK_ADA_DI_INPUT — Babelan Heat Rate dalam BTU/kWh '
                      . 'tidak dapat dihitung tanpa calorific value; hanya massa (ton) yang tersedia'],
        'Total Plant Production (MWh)'     => round($comb_total_MWh, 2),
        'Combined JBBK+MM2100 Prod (MWh)'  => round($jbbk_total_MWh, 2),   // alias legacy = JBBK MM Prod
        'JBBK MM Fuel (BBTUD)'             => round($fuelJBBKMM_BBTUD, 5),
        'JBBK MM Heat Rate (BTU/kWh)'      => round($hrJBBKMM, 2),
        'JBBK MM Total Cost (USD)'         => round($costJBBKMM, 2),
        'JBBK MM Cost Production (USD/MWh)'=> round($cpJBBKMM, 4),
        'Babelan Cost Production (USD/MWh)'=> round($cpBabelan, 4),
        'Jababeka Heat Rate (BTU/kWh)'     => round($hrJ, 2),
        'MM2100 Heat Rate (BTU/kWh)'       => round($hrMM, 2),
        'Combined Heat Rate (BTU/kWh)'     => round($hrComb, 2),
        'Jababeka Cost Production (USD/MWh)' => round($cpJ, 4),
        'MM2100 Cost Production (USD/MWh)'   => round($cpMM, 4),
        'Combined Cost Production (USD/MWh)' => round($cpComb, 4),
        'KP72 Allocation (BBTUD)' => ['pep_kp72' => round($pepKpU, 5), 'pertagas_kp72' => round($pertagasKpU, 5),
            'akasia_kp72' => round($akasiaKpU, 5), 'baskara_kp72' => round($baskaraKpU, 5),
            'unmapped_to_pep' => round($kpUnmapped, 5)],
        'KP72 Cost by Family (USD)' => ['pep' => round($costPEPkp72, 2), 'pertagas' => round($costPertagasKp72, 2),
            'akasia' => round($costAkasiaKp72, 2), 'baskara' => round($costBaGSkp72, 2)],
        'Cost PGN Pipe (USD)' => round($costPGNpipe, 2),
        'Cost LNG (USD)' => round($costLNG, 2),
        'Cost PEP (USD)' => round($costPEP, 2),
        'Cost PEP KP72 (USD)' => round($costPEPkp72, 2),
        'Cost Akasia (USD)' => round($costAkasia, 2),
        'Cost Akasia KP72 (USD)' => round($costAkasiaKp72, 2),
        'Cost BaGS (USD)' => round($costBaGS, 2),
        'Cost BaGS KP72 (USD)' => round($costBaGSkp72, 2),
        'Cost Coal (USD)' => round($costCoal, 2),
        'Cost Biomass (USD)' => round($costBiomass, 2),
        'Total Cost (USD)' => round($totalCost, 2),
        'Total Houseload (MWh)' => round($hl_total_MWh, 2),
        /* §2 audit gate: kedua identitas wajib identik (lihat 'Production Accounting Audit'). */
        'Total Gross Production (MWh)' => round($acct['total_gross'], 2),
        'JBBK MM Gross (MWh)' => round($acct['jbbk_gross'], 2),
        'JBBK MM Net (MWh)' => round($acct['jbbk_net'], 2),
        'Babelan Net (MWh)' => round($acct['babelan_net'], 2),
        'Convergence Budget' => pp_budget_report(),
        /* Laporan perhitungan ganda — dibaca audit, tidak dipakai keputusan apa pun. */
        'Pipeline Call Counts' => pp_instr_report(),
        'Export Floor Repair' => array_values((array)($GLOBALS['__pp_export_floor_ev'] ?? [])),
        'Carry Minimum Runtime' => (array)($GLOBALS['__pp_carry_notes'] ?? []),
        'Reserve Repair' => array_values((array)($GLOBALS['__pp_reserve_ev'] ?? [])),
        'Reserve Infeasibility Certificate' => pp_reserve_certificate($genRows, $d3, $model, $ieVals,
            (float)($gasQuotaJababeka ?? 0), (float)($trueGasFinal ?? 0)),
        'Production Accounting Audit' => [
            'total_gross_minus_houseload' => round($acct['total_gross'] - $acct['total_houseload'], 6),
            'jbbk_net_plus_babelan_net'   => round($acct['jbbk_net'] + $acct['babelan_net'], 6),
            'identities_match'            => (abs(($acct['total_gross'] - $acct['total_houseload']) - ($acct['jbbk_net'] + $acct['babelan_net'])) < 1e-6),
            'houseload_deducted_times'    => 1,
        ],
        'Net Production (MWh)' => round($netProd, 2),
        'Cost Production (USD/MWh)' => round($costProduction, 4),
        /* KOREKSI EVIDENCE BASI: sebuah pass dapat menerbitkan klaim ("di-stop rows A-B" /
         * "di-start pada row R") lalu pass berikutnya MEMBATALKAN aksinya tanpa menarik klaim
         * itu, sehingga operator membaca bukti yang tidak pernah terjadi. Setiap klaim
         * diverifikasi terhadap dispatch FINAL; yang tidak cocok TIDAK dihapus (jejak audit
         * dipertahankan) melainkan diberi anotasi [SUPERSEDED: ...]. Generik, tanpa daftar unit. */
        'Warnings' => (function (array $rowsF, array $warns) {
            $col = function ($u) { $C = strtoupper($u);
                if ($C === 'B1' || $C === 'B2') $C = 'BB' . substr($C, 1); return $C; };
            $out = [];
            foreach ($warns as $w) {
                $t = is_array($w) ? json_encode($w) : (string)$w; $notes = [];
                if (strpos($t, 'di-stop rows') !== false && preg_match('/\b([A-Z]{1,3}\d{1,2})\b[^|]{0,120}?di-stop rows (\d+)-(\d+)/u', $t, $m)) {   // V7: pra-saring strpos (hasil identik)
                    $u = $m[1]; $a = (int)$m[2]; $b = (int)$m[3]; $still = 0;
                    for ($r = $a; $r <= $b; $r++) { $rr = $rowsF[$r - 1] ?? null;
                        if ($rr && ((float)($rr[$col($u)] ?? 0)) > 0.01) $still++; }
                    if ($still > 0) $notes[] = sprintf('%s masih menyala pada %d row di window %d-%d pada output FINAL', $u, $still, $a, $b);
                }
                if (strpos($t, 'di-start pada row') !== false && preg_match('/\b([A-Z]{1,3}\d{1,2})\b[^|]{0,120}?di-start pada row (\d+)/u', $t, $m)) {   // V7: pra-saring strpos
                    $u = $m[1]; $r0 = (int)$m[2]; $f = null;
                    foreach ($rowsF as $k => $rr) if (((float)($rr[$col($u)] ?? 0)) > 0.01) { $f = $k + 1; break; }
                    if ($f !== null && $f !== $r0)
                        $notes[] = sprintf('row pertama %s menyala pada output FINAL adalah %d, bukan %d', $u, $f, $r0);
                }
                $out[] = $notes ? ($t . ' [SUPERSEDED: ' . implode('; ', $notes) . ']') : $w;
            }
            return $out;
        })((array)$data, (array)$warnings),
        /* §10 evidence: audit balanced/imbalanced G8/G9 tiap row + cost saving (0 = cost-neutral). */
        'G8/G9 Cost Recheck' => $g89CostRecheck ?? [],
        'G8/G9 Cost Recheck Summary' => (function () use ($g89CostRecheck) {
            $bal = 0; $imb = 0; $maxSaving = 0.0;
            foreach (($g89CostRecheck ?? []) as $r) { if ($r['balanced']) $bal++; else $imb++; $maxSaving = max($maxSaving, abs($r['cost_saving_bbtud'])); }
            return ['balanced_rows' => $bal, 'imbalanced_rows' => $imb, 'max_abs_cost_saving_bbtud' => round($maxSaving, 6),
                    'note' => 'G8/G9 fuel curves linear-identical -> all equal-sum splits cost-neutral; balanced chosen as default (§6/§1.7).'];
        })(),
    ];

    return [
        'result'   => 'ok',
        'worker'   => $model['worker'] ?? 'worker02',
        'modeling' => 'Daily Simulation: ' . ($model['note'] ?? ''),
        'data'     => $data,
        'info'     => $info,
        'startup_state' => $su['states'],
    ];
}

/* =========================================================================
 *  STANDALONE RUNNER  (only when this file is the entry script — CLI or a
 *  direct request to worker02.php). When required by run.php/index.php this
 *  block is skipped and only pp_run_simulation() is defined.
 * ========================================================================= */
$pp_self    = $_SERVER['SCRIPT_FILENAME'] ?? ($argv[0] ?? '');
$pp_is_main = ($pp_self !== '' && @realpath($pp_self) === __FILE__);

if ($pp_is_main) {
    set_time_limit(0);
    $inputFile = ($argv[1] ?? null) ?: (__DIR__ . '/input_data.json');
    if (!file_exists($inputFile)) { fwrite(STDERR, "input data file not found\n"); exit(1); }
    $input = json_decode(file_get_contents($inputFile), true);
    if (json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, "input JSON invalid: " . json_last_error_msg() . "\n"); exit(1); }

    $output = pp_run_simulation($input);

    $outFile = ($argv[2] ?? null) ?: (__DIR__ . '/output_data.json');
    file_put_contents($outFile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($output);
    } else {
        $i = $output['info'];
        echo basename($outFile) . " written. rows=" . count($output['data'])
           . " | export(MWh)=" . $i['Daily PLN Exp (MWh)']
           . " | gas(BBTUD)=" . $i['Gas Fuel Total (BBTUD)']
           . " | gas_ok=" . ($i['Gas Quota Status'] === 'WITHIN QUOTA' ? 'yes' : 'NO')
           . " | cost/MWh=" . $i['Cost Production (USD/MWh)'] . "\n";
    }
    exit(0);
}
