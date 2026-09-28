<?php
/* Satu sumber toleransi langkah startup (optimizer & validator). */

/* ==============================================================================================
 * OPT — SAKELAR VERIFIKASI MEMO (default MATI di produksi, biaya nol).
 *
 * Setiap memoisasi hasil yang dipasang pada rilis ini menyediakan mode verifikasi: hit memo tetap
 * menghitung ulang badan fungsi secara penuh lalu membandingkan bit-per-bit, dan selisih apa pun
 * menghentikan proses dengan pesan yang menyebut argumen persisnya. Mode ini dinyalakan saat A/B
 * oracle dijalankan, sehingga klaim "result-preserving" berdiri di atas pembuktian pada beban
 * nyata, bukan di atas penalaran. Di produksi konstanta bernilai false dan cabang verifikasi
 * dilipat habis oleh optimizer PHP.
 * ============================================================================================== */
if (!defined('PP_FUEL_MEMO_VERIFY')) define('PP_FUEL_MEMO_VERIFY', (bool)getenv('PP_MEMO_VERIFY'));
/* SAKLAR BISECT: PP_MEMO_OFF=1 mematikan SELURUH memoisasi hasil, sehingga source dapat diadu
 * dengan dirinya sendiri untuk memastikan apakah sebuah perbedaan berasal dari memo atau bukan.
 * Default mati (memo aktif). Ini alat diagnosa, bukan jalur produksi. */
if (!defined('PP_MEMO_OFF')) define('PP_MEMO_OFF', (bool)getenv('PP_MEMO_OFF'));
if (!defined('PP_V6_WMEMO')) define('PP_V6_WMEMO', (string)getenv('PP_V6_WMEMO') !== '0');   // V6: memo lintas core run (OPT-W1..W4)

/* ==============================================================================================
 * GENERASI REQUEST — PENGGUGUR MEMO UNTUK DATA INPUT YANG TIDAK PERNAH DIMUTASI.
 *
 * Beberapa memoisasi di berkas ini berlaku atas data yang terbukti tidak pernah ditulis selama
 * satu request (presence unit, urutan prioritas unit). Data itu TETAP berbeda antar request, dan
 * satu proses PHP dapat melayani lebih dari satu request pada jalur worker CLI. Counter ini
 * dinaikkan tepat sekali di pintu masuk terluar setiap request, sehingga memo semacam itu
 * digugurkan pada batas request dan TIDAK PERNAH menyeberang — tetapi tetap hidup penuh di
 * sepanjang satu request, yang memang di situlah nilainya.
 *
 * Sengaja TIDAK memakai __pp_fuel_epoch: epoch itu dinaikkan tiap core run (pengaman kurva bahan
 * bakar), sedangkan data input di sini tidak berubah antar core run.
 * ============================================================================================== */
function pp_req_gen(): int { return (int)($GLOBALS['__pp_req_gen'] ?? 0); }

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

if (!defined('PP_STARTUP_SEQUENCE_EPSILON')) define('PP_STARTUP_SEQUENCE_EPSILON', 0.05);
/* =========================================================================
 *  worker_functions.php  —  Planning Pembangkit 17 Unit
 *  All pure calculation + dispatch helper functions.
 *
 *  Conventions
 *  -----------
 *  - $d3    = $input['data3']  (unit definitions + ['modeling'])
 *  - $model = $d3['modeling']
 *  - row1   = 1-based row number 1..48  (row1=1 -> 00:30, row1=48 -> 00:00).
 *             The engine loop index $row is 0..47; everywhere a *time rule*
 *             is evaluated we pass $row+1 so it matches the user's 1..48
 *             convention (Sec.15.6 fix).
 *  - Per-row generator loads are in MW. calc_fuel returns gas in BBTU for the
 *     30-min slot; daily BBTUD = sum(48 rows)/2 (existing convention kept).
 * ========================================================================= */

if (!function_exists('pp_clamp')) {
    function pp_clamp($v, $lo, $hi) { return max($lo, min($hi, $v)); }
}

/* -------------------------------------------------------------------------
 *  Overlap validation for time-based rules (Sec.3.1, Sec.4, Sec.5).
 *  $rules = list of ['start'=>int,'stop'=>int, ...]; returns conflict msgs.
 * ------------------------------------------------------------------------- */
function pp_find_overlaps(array $rules, string $label): array {
    $errs = [];
    $n = count($rules);
    for ($a = 0; $a < $n; $a++) {
        $sa = $rules[$a]['start'] ?? null; $ea = $rules[$a]['stop'] ?? null;
        if ($sa === null || $ea === null) continue;
        if ($sa < 1 || $ea > 48 || $sa > $ea) {
            $errs[] = "$label: invalid range row $sa-$ea (must be 1..48, start<=stop)";
        }
        for ($b = $a + 1; $b < $n; $b++) {
            $sb = $rules[$b]['start'] ?? null; $eb = $rules[$b]['stop'] ?? null;
            if ($sb === null || $eb === null) continue;
            if ($sa <= $eb && $sb <= $ea) {
                $errs[] = "$label: overlap between row $sa-$ea and row $sb-$eb";
            }
        }
    }
    return $errs;
}

/* Validate every overlap-sensitive structure in the model. */
function pp_validate_input(array $model): array {
    $errs = [];
    $errs = array_merge($errs, pp_find_overlaps($model['ie_adjustment'] ?? [], 'IE adjustment'));
    foreach (($model['unit_stop_time'] ?? []) as $r) { /* grouped per unit below */ }
    // unit_stop_time grouped by unit
    $byUnit = [];
    foreach (($model['unit_stop_time'] ?? []) as $r) { $byUnit[$r['unit'] ?? '?'][] = $r; }
    foreach ($byUnit as $u => $rs) $errs = array_merge($errs, pp_find_overlaps($rs, "Unit stop time [$u]"));
    foreach (($model['unit_fix_load'] ?? []) as $u => $rs) $errs = array_merge($errs, pp_find_overlaps($rs, "Fix load [$u]"));
    foreach (($model['unit_skip_load'] ?? []) as $u => $rs) $errs = array_merge($errs, pp_find_overlaps($rs, "Skip load [$u]"));
    $byHr = [];
    foreach (($model['hrsg_stop_time'] ?? []) as $r) { $byHr[$r['hrsg'] ?? '?'][] = $r; }
    foreach ($byHr as $h => $rs) $errs = array_merge($errs, pp_find_overlaps($rs, "HRSG stop time [$h]"));
    // max_load_time per unit (optional)
    return $errs;
}

/* -------------------------------------------------------------------------
 *  IE adjustment (Sec.3.1): returns adjusted IE value array (per row, 0-based).
 * ------------------------------------------------------------------------- */
function pp_apply_ie_adjustment(array $data1, array $adjustments): array {
    // Resolve each adjustment to signed value + row span [start,stop] (1..48).
    // Supports the new UI format {operator '+'/'-', value, start_period 'HH:MM',
    // stop_period 'HH:MM'} and the legacy {start,stop,value} row format.
    $toMin = function ($t, bool $isStop) {
        if (is_numeric($t)) return null;                       // not a time string
        if (strpos((string)$t, ':') === false) return null;
        [$h, $m] = array_map('intval', explode(':', (string)$t));
        $min = $h * 60 + $m;
        if ($min === 0 && $isStop) $min = 1440;                // "00:00" as a stop = end of day
        return $min;
    };
    $norm = [];
    foreach ($adjustments as $adj) {
        $val = (float)($adj['value'] ?? 0);
        if (isset($adj['operator']) && $adj['operator'] === '-') $val = -abs($val);
        elseif (isset($adj['operator']) && $adj['operator'] === '+') $val = abs($val);
        $sMin = $toMin($adj['start_period'] ?? null, false);
        $eMin = $toMin($adj['stop_period'] ?? null, true);
        if ($sMin !== null && $eMin !== null) {
            $start = (int)floor($sMin / 30) + 1;               // row whose start >= sMin
            $stop  = (int)ceil($eMin / 30);                    // row whose end <= eMin
        } else {
            $start = (int)($adj['start'] ?? 1);
            $stop  = (int)($adj['stop'] ?? 48);
        }
        if ($val != 0.0) $norm[] = ['start' => max(1, $start), 'stop' => min(48, $stop), 'value' => $val];
    }
    $vals = [];
    foreach ($data1 as $i => $row) {
        $r1 = $i + 1;
        $v  = $row['value'];
        foreach ($norm as $adj) {
            if ($r1 >= $adj['start'] && $r1 <= $adj['stop']) $v += $adj['value'];
        }
        $vals[$i] = $v;
    }
    return $vals;
}

/* -------------------------------------------------------------------------
 *  PLN Export Priority (Sec.6).  Returns per-row feasible [lo,hi] and a
 *  cost-minimising target (lower bound), then lifts targets to satisfy the
 *  optional daily-MWh target.  Warnings collect any infeasibility.
 * ------------------------------------------------------------------------- */
function pp_compute_pln_targets(array $model, array $data2): array {
    $pep = $model['pln_export_priority'] ?? [];
    $range = $pep['range'] ?? ['min' => 0, 'max' => 1e9, 'required' => true];
    $dt    = $pep['daily_target'] ?? ['value' => 0, 'required' => false];

    /* Dispatch deviation: support multiple rules (Revisi Sec.3). Prefer
     * pln_export_priority.dispatch_dev_rules[]; fall back to the legacy single
     * dispatch_dev object. Each rule narrows the band on its [start,stop] rows. */
    $ddRules = $pep['dispatch_dev_rules'] ?? null;
    if (!is_array($ddRules) || !$ddRules) {
        $legacy  = $pep['dispatch_dev'] ?? null;
        $ddRules = (is_array($legacy) && !empty($legacy['required'])) ? [$legacy] : [];
    }
    $rules = [];
    foreach ($ddRules as $rr) {
        if (!is_array($rr)) continue;
        if (array_key_exists('required', $rr) && !$rr['required']) continue;
        $rules[] = ['min' => (float)($rr['min'] ?? 0), 'max' => (float)($rr['max'] ?? 0),
                    'start' => max(1, (int)($rr['start'] ?? 1)), 'stop' => min(48, (int)($rr['stop'] ?? 48))];
    }

    $rMin = $range['min'] ?? 0;
    $rMax = $range['max'] ?? 1e9;

    /* Additional Range PLN Rules (Revisi): up to 4 per-period [min,max] windows that override the main
     * range on their [start,stop] rows. Each must satisfy mainMin <= ruleMin <= ruleMax <= mainMax and
     * must not overlap other range rules. */
    $rangeRules = [];
    foreach (($pep['range_rules'] ?? []) as $rr) {
        if (!is_array($rr)) continue;
        $rl = ['min' => (float)($rr['min'] ?? $rMin), 'max' => (float)($rr['max'] ?? $rMax),
               'start' => max(1, (int)($rr['start'] ?? 1)), 'stop' => min(48, (int)($rr['stop'] ?? 48))];
        if ($rl['start'] > $rl['stop']) continue;
        $rangeRules[] = $rl;
        if (count($rangeRules) >= 4) break;
    }

    $n = count($data2);
    $lo = []; $hi = []; $target = []; $warn = [];

    // validate additional range rules: within main range + no overlap
    foreach ($rangeRules as $rl) {
        if ($rl['min'] < $rMin - 1e-9 || $rl['max'] > $rMax + 1e-9 || $rl['min'] > $rl['max'] + 1e-9)
            $warn[] = sprintf('Range PLN rule [%.0f,%.0f] must stay within main range [%.0f,%.0f] and have min<=max.', $rl['min'], $rl['max'], $rMin, $rMax);
    }
    for ($a = 0; $a < count($rangeRules); $a++)
        for ($b = $a + 1; $b < count($rangeRules); $b++)
            if ($rangeRules[$a]['start'] <= $rangeRules[$b]['stop'] && $rangeRules[$b]['start'] <= $rangeRules[$a]['stop']) {
                $warn[] = 'Additional Range PLN rules overlap. Please correct the periods.'; break 2;
            }

    // overlap detection across rules
    for ($a = 0; $a < count($rules); $a++) {
        for ($b = $a + 1; $b < count($rules); $b++) {
            if ($rules[$a]['start'] <= $rules[$b]['stop'] && $rules[$b]['start'] <= $rules[$a]['stop']) {
                $warn[] = 'Dispatch deviation rules overlap. Please correct period.';
                break 2;
            }
        }
    }

    for ($i = 0; $i < $n; $i++) {
        $r1 = $i + 1;
        $l = $rMin; $h = $rMax;
        foreach ($rangeRules as $rl) {                    // additional Range PLN rule overrides main range for its period
            if ($r1 >= $rl['start'] && $r1 <= $rl['stop']) {
                $l = max($rMin, $rl['min']); $h = min($rMax, $rl['max']); break;
            }
        }
        foreach ($rules as $rule) {                       // first matching dispatch-dev rule narrows further
            if ($r1 >= $rule['start'] && $r1 <= $rule['stop']) {
                $disp = $data2[$i]['dispatch'] ?? 0;
                $l = max($l, $disp - $rule['min']);
                $h = min($h, $disp + $rule['max']);
                break;
            }
        }
        if ($l > $h) {
            $warn[] = "Row $r1: range and dispatch-dev conflict ([$l,$h]); using $h";
            $l = $h;
        }
        $lo[$i] = $l; $hi[$i] = $h;
        $target[$i] = $l;                 // cheapest first: minimum export
    }
    /* PROMPT 3JUL/7JUL T10 — SEED TANGGA DARI ACTUAL (Monitoring): export row locked adalah
     * KONSTANTA; row future bersebelahan wajib berada dlm jangkauan ramp thd nilai locked
     * (floor >= actual-30, ceiling <= actual+30). Propagasi tangga di bawah menyebarkannya
     * ke row-row berikutnya (actual-60, ...), shg boundary locked->future tersambung mulus. */
    $actExpT = [];
    foreach ((array)(($model['actual_data']['rows'] ?? [])) as $arT) {
        $riT = (int)($arT['row'] ?? 0) - 1;
        if ($riT >= 0 && $riT < $n && isset($arT['export']) && is_numeric($arT['export'])) $actExpT[$riT] = (float)$arT['export'];
    }
    foreach ($actExpT as $riT => $evT) {
        foreach ([$riT - 1, $riT + 1] as $nbT) {
            if ($nbT < 0 || $nbT >= $n || isset($actExpT[$nbT])) continue;
            /* hanya FLOOR yang di-seed di target builder — hi[] adalah RangeMax USER yang
             * dilaporkan sbg Eff_Hi & divalidasi; ceiling boundary dijaga internal oleh
             * smoothing final (hL) tanpa mengubah batas user. */
            $lo[$nbT] = max($lo[$nbT], min($evT - pp_export_step_limit($model), $hi[$nbT]));
            $target[$nbT] = max($target[$nbT], $lo[$nbT]);
        }
    }
    /* PROMPT 3JUL/7JUL §3.1/§9 — RAMP LADDER di target awal: floor lo[] dipropagasi dua arah
     * (lo[r] >= max(lo[r-1], lo[r+1]) - 30, clamp <= hi[r]) agar dispatch awal SUDAH membentuk
     * tangga menuju/keluar window Range Min tinggi — horizon penuh, bukan repair mendadak. */
    for ($ldp = 0; $ldp < $n; $ldp++) {
        $chL = false;
        for ($i2 = 0; $i2 < $n; $i2++) {
            $f = $lo[$i2];
            if ($i2 > 0)      $f = max($f, $lo[$i2 - 1] - pp_export_step_limit($model));
            if ($i2 < $n - 1) $f = max($f, $lo[$i2 + 1] - pp_export_step_limit($model));
            $f = min($f, $hi[$i2]);
            if ($f > $lo[$i2] + 1e-9) { $lo[$i2] = $f; $target[$i2] = max($target[$i2], $f); $chL = true; }
        }
        if (!$chL) break;
    }

    // Daily target lift (Sec.6.2): sum(target/2) >= value
    if (!empty($dt['required'])) {
        $needMWh = $dt['value'] ?? 0;
        $curMWh  = array_sum(array_map(fn($x) => $x / 2, $target));
        $deficit = $needMWh - $curMWh;     // MWh
        if ($deficit > 1e-6) {
            // distribute proportionally to remaining headroom, iterate to fill
            for ($pass = 0; $pass < 200 && $deficit > 1e-6; $pass++) {
                $headroom = [];
                $totalHead = 0;
                for ($i = 0; $i < $n; $i++) {
                    $hr = $hi[$i] - $target[$i];
                    if ($hr > 1e-9) { $headroom[$i] = $hr; $totalHead += $hr; }
                }
                if ($totalHead <= 1e-9) {
                    $warn[] = sprintf("Daily target %.1f MWh infeasible within range; short by %.1f MWh", $needMWh, $deficit);
                    break;
                }
                // deficit is MWh; each MW added to a row contributes 0.5 MWh
                $addMW = $deficit * 2;     // total MW-steps still needed
                foreach ($headroom as $i => $hr) {
                    $share = min($hr, $addMW * $hr / $totalHead);
                    $target[$i] += $share;
                }
                $curMWh  = array_sum(array_map(fn($x) => $x / 2, $target));
                $deficit = $needMWh - $curMWh;
            }
        }
    }
    return ['target' => $target, 'lo' => $lo, 'hi' => $hi, 'warn' => $warn];
}

/* -------------------------------------------------------------------------
 *  Unit status helpers (Sec.5).  row1 = 1-based.
 * ------------------------------------------------------------------------- */
/* ============================================================================================
 *  LEGACY PRESENCE OVERRIDE — unit MM2100 (GE1-GE4 dan G10)
 *
 *  Audit kontrak UI membuktikan `unit_present` TIDAK memiliki kontrol di index.php:
 *    grep unit_present index.php -> 0 · run.php -> 0
 *    #tbl-units hanya merender min_scload / min_ccload / max_load
 *    collector index.php:4033 tidak pernah menulis data3.<unit>.unit_present
 *    blok Advanced JSON yang membawa field presence sengaja disembunyikan (Revisi Sec.1)
 *  Karena operator TIDAK dapat mengubah field ini, nilai 0 pada input adalah LEGACY ZERO —
 *  bukan deklarasi absent. Untuk unit MM2100, legacy zero tidak boleh menggugurkan eligibility.
 *
 *  Eligibility MM2100 selanjutnya ditentukan kontrol yang BENAR-BENAR tersedia di UI:
 *  Unit Stop, Unit Skip, Required & Commitment, Start/Stop Based on Simulation, Start At,
 *  Stop At, Unit Priority, Block Priority, Last Data Status, startup eligibility,
 *  kuota gas KP72, dan seluruh hard constraint.
 *
 *  Unit di luar MM2100 TETAP memakai presence sebagaimana sebelumnya.
 *  Dapat dimatikan untuk investigasi lewat PP_MM2100_LEGACY_PRESENCE=1.
 * ========================================================================================== */
function pp_is_mm2100_unit(string $unit): bool {
    $u=strtolower($unit);return (bool)preg_match('/^ge[1-4]$/',$u)||$u==='g10';
}
function pp_kp72_total_quota(array $model): float {
    $q=(array)($model['gas_quota']??[]);return max(0.0,(float)($q['pep_kp72']??0)+(float)($q['pertagas_kp72']??0)+(float)($q['akasia_kp72']??0)+(float)($q['baskara_kp72']??0));
}
function pp_unit_explicitly_requested(array $model,string $unit): bool {
    $u=strtolower($unit);$U=strtoupper($u);
    if(in_array($u,array_map('strtolower',(array)($model['required_units']??[])),true))return true;
    if(in_array($u,array_map('strtolower',(array)($model['unit_cannot_stop']??[])),true))return true;
    foreach((array)($model['required_mode']??[]) as $k=>$v)if(strtolower((string)$k)===$u)return true;
    foreach((array)($model['unit_start_time']??[]) as $e)if(strtolower((string)($e['unit']??$e['name']??''))===$u)return true;
    foreach((array)($model['unit_fix_load'][$u]??$model['unit_fix_load'][$U]??[]) as $e)if((float)($e['value']??0)>0)return true;
    return strtolower((string)($model['unit_last_data_status'][$U]??''))==='running';
}
/* PERF: the anchored pattern /^ge[1-4]$/ matches exactly these four strings, so a hash lookup is
   an exact substitute for preg_match() on the hottest leaf in the engine. */
function pp_ge_unit(string $u): bool { static $GE=['ge1'=>true,'ge2'=>true,'ge3'=>true,'ge4'=>true]; return isset($GE[$u]); }
function pp_unit_present(array $d3,string $unit): bool {
    /* ==========================================================================================
     * OPT-U1 — MEMOISASI PRESENCE. Terukur 1,44 detik waktu SELF (ditambah biaya panggilan
     * pp_ge_unit yang ikut hilang: 1,35 detik) hanya untuk mengulang strtolower() dan satu
     * pembacaan `unit_present` yang nilainya tidak pernah berubah.
     *
     * EKSAK. Fungsi ini membaca tepat dua sumber: `$d3[$u]['unit_present']` dan variabel
     * lingkungan PP_MM2100_LEGACY_PRESENCE. Audit tulis menyeluruh atas seluruh berkas PHP paket
     * ini menemukan NOL penugasan ke `unit_present` — nilainya berasal dari input_data.json dan
     * tidak pernah dimutasi. Variabel lingkungan memang sudah dibaca sekali per proses.
     * Memo digugurkan per request lewat pp_req_gen(), jadi request berikutnya di proses yang sama
     * (jalur worker CLI) tidak pernah memakai nilai request sebelumnya.
     * ========================================================================================== */
    static $memo = []; static $gen = null;
    $g = pp_req_gen();
    if ($g !== $gen) { $memo = []; $gen = $g; }
    if (isset($memo[$unit]) && !PP_FUEL_MEMO_VERIFY && !PP_MEMO_OFF) return $memo[$unit];
    $res = pp_unit_present_compute($d3, $unit);
    if (isset($memo[$unit]) && PP_FUEL_MEMO_VERIFY && $memo[$unit] !== $res)
        throw new RuntimeException('OPT-U1: memo pp_unit_present tidak sama dengan perhitungan penuh');
    return $memo[$unit] = $res;
}
function pp_unit_present_compute(array $d3,string $unit): bool {
    $u=strtolower($unit);if(!isset($d3[$u]))return false;
    /* GE legacy-zero is not an operator readiness control. Effective KP72 availability is resolved
       with the model by pp_effective_unit_available(). G10 still respects physical presence. */
    /* PERF: the environment cannot change inside one request, so getenv() is read once per process. */
    static $legacy=null; if($legacy===null)$legacy=((string)getenv('PP_MM2100_LEGACY_PRESENCE')==='1');
    if(pp_ge_unit($u)&&!$legacy)return true;
    return (int)($d3[$u]['unit_present']??1)===1;
}
function pp_effective_unit_available(array $d3,array $model,string $unit,int $row1=1): bool {
    $u=strtolower($unit);if(!isset($d3[$u]))return false;
    if(pp_ge_unit($u)){
        /* V8 — GE HANYA DENGAN SUMBER GAS MM2100 YANG SAH. V7 menganggap GE tersedia bila Last Data
           Status = Running atau diminta eksplisit, walaupun kuota KP72 = 0 dan tidak ada Actual/fixed
           flow MM2100: reproducer Daily Plan 09-Jul-26 Baru menjalankan GE1 3 MW pada 00:30 dengan
           gas 0,64128 BBTUD-laju yang tidak dimiliki MM2100 (tercatat sebagai MM2100 Daily Used
           0,0134 BBTUD di atas kuota 0). Status Running kemarin bukan sumber gas. Sumber sah:
           kuota KP72 > 0, Actual Energy MM2100 > 0, atau fixed flow manual MM2100 > 0.
           PP_V8_GE_GAS_GATE=0 mengembalikan perilaku V7. */
        if(pp_kp72_total_quota($model)>1e-9)return true;
        static $gate=null; if($gate===null)$gate=((string)getenv('PP_V8_GE_GAS_GATE')!=='0');
        if(!$gate)return pp_unit_explicitly_requested($model,$u);
        return pp_v8_mm2100_source($model)['legal'];
    }
    return pp_unit_present($d3,$u);
}
/* V8 — provenance sumber gas MM2100 (dipisah: kuota KP72, Actual Energy MM2100, fixed flow manual MM2100).
   max_flow_mm2100 adalah plafon, bukan sumber. Estimasi fixed flow MM2100 diturunkan DARI dispatch GE/G10,
   sehingga tidak pernah dapat menjadi sumbernya sendiri. */
/* V9 — akun bahan bakar (seluruh fuel): setiap pemakaian wajib punya akun sumber dan biaya.
 * Dikembalikan: daftar akun (dipakai, biaya, harga) dan pelanggaran keras (engine tidak menghargai fuel
 * yang dipakai padahal harga > 0; Distillate dipakai tanpa otorisasi aksi bahan bakar). */
function pp_v9_fuel_accounts(array $model, array $info): array {
    $price = (array)($model['price'] ?? []);
    $defs = [['PGN_PIPE', 'PGN Pipe Used (BBTUD)', 'Cost PGN Pipe (USD)', 'pgn_pipe', 'BBTUD'], ['LNG', 'LNG Used (BBTUD)', 'Cost LNG (USD)', 'lng', 'BBTUD'],
             ['PEP_JBBK', 'PEP Jababeka Used (BBTUD)', 'Cost PEP (USD)', 'pep', 'BBTUD'], ['AKASIA_JBBK', 'Akasia JBBK Total (BBTUD)', 'Cost Akasia (USD)', 'akasia', 'BBTUD'],
             ['BAGS_JBBK', 'BaGS JBBK Total (BBTUD)', 'Cost BaGS (USD)', 'baskara', 'BBTUD'], ['BBG_JBBK', 'BBG Jababeka Used (BBTUD)', 'Cost BBG (USD)', 'bbg', 'BBTUD'],
             ['PEP_KP72', 'PEP KP72 Total (BBTUD)', 'Cost PEP KP72 (USD)', 'pep_kp72', 'BBTUD'], ['AKASIA_KP72', 'Akasia KP72 Total (BBTUD)', 'Cost Akasia KP72 (USD)', 'akasia_kp72', 'BBTUD'],
             ['BAGS_KP72', 'BaGS KP72 Total (BBTUD)', 'Cost BaGS KP72 (USD)', 'baskara_kp72', 'BBTUD'], ['COAL', 'Total Coal (ton)', 'Cost Coal (USD)', 'coal', 'ton'],
             ['DISTILLATE', 'Distillate Used (l)', 'Distillate Cost (USD)', 'distillate', 'l']];
    $acc = []; $viol = [];
    $act = strtolower(trim((string)($model['gas_shortage_action'] ?? 'none')));
    foreach ($defs as [$id, $uk, $ck, $pk, $unit]) {
        $used = (float)($info[$uk] ?? 0); $cost = array_key_exists($ck, $info) ? (float)$info[$ck] : null;
        $pr = $price[$pk] ?? ($pk === 'pep_kp72' ? ($price['pep'] ?? null) : ($pk === 'akasia_kp72' ? ($price['akasia'] ?? null) : ($pk === 'baskara_kp72' ? ($price['baskara'] ?? null) : null)));
        $st = 'OK';
        if ($used > 1e-6) {
            if ($cost === null) $st = 'TANPA_AKUN_BIAYA';
            elseif ($cost <= 0 && (float)$pr > 0) $st = 'FUEL_TANPA_BIAYA';
            elseif ($cost <= 0) $st = 'HARGA_NOL_DARI_INPUT';
        }
        if ($id === 'DISTILLATE' && $used > 1e-6 && !preg_match('~distillate|mixed~', $act) && empty($model['__fuel_decision_mode'])) $st = 'DISTILLATE_TANPA_OTORISASI';
        if (in_array($st, ['FUEL_TANPA_BIAYA', 'DISTILLATE_TANPA_OTORISASI'], true) || ($st === 'TANPA_AKUN_BIAYA' && (float)$pr > 0))
            $viol[] = ['fuel_account', sprintf('%s dipakai %.4f %s tanpa biaya yang sah (%s)', $id, $used, $unit, $st)];
        $acc[] = ['account' => $id, 'used' => round($used, 5), 'unit' => $unit, 'cost_usd' => $cost === null ? null : round($cost, 2), 'price' => $pr, 'status' => $st];
    }
    /* Gas MM2100 (GE/G10) sebagai satu akun: pemakaian harian vs total biaya keluarga KP72. */
    $mmU = (float)($info['MM2100 Daily Used (BBTUD)'] ?? 0); $mmC = 0.0; foreach (['Cost PEP KP72 (USD)', 'Cost Akasia KP72 (USD)', 'Cost BaGS KP72 (USD)'] as $k) $mmC += (float)($info[$k] ?? 0);
    $pm = (float)($price['pep_kp72'] ?? ($price['pep'] ?? 0));
    $stM = $mmU > 1e-6 ? ($mmC > 0 ? 'OK' : ($pm > 0 ? 'FUEL_TANPA_BIAYA' : 'HARGA_NOL_DARI_INPUT')) : 'OK';
    if ($stM === 'FUEL_TANPA_BIAYA') $viol[] = ['fuel_account', sprintf('MM2100_GAS dipakai %.4f BBTUD tanpa biaya', $mmU)];
    $acc[] = ['account' => 'MM2100_GAS', 'used' => round($mmU, 5), 'unit' => 'BBTUD', 'cost_usd' => round($mmC, 2), 'price' => $pm, 'status' => $stM];
    return ['accounts' => $acc, 'violations' => $viol];
}
function pp_v8_mm2100_source(array $model): array {
    $kp = pp_kp72_total_quota($model);
    $act = 0.0; $actRows = 0;
    foreach ((array)($model['actual_energy_mm2100'] ?? []) as $v) if ($v !== '' && $v !== null && is_numeric($v)) { $act += max(0.0, (float)$v); if ((float)$v > 1e-9) $actRows++; }
    $man = [];
    foreach ((array)($model['manual_fixed_flows'] ?? []) as $e) {
        if (!is_array($e) || strtoupper((string)($e['area'] ?? '')) !== 'MM2100') continue;
        $val = (float)($e['value_mmscfd'] ?? $e['value'] ?? 0); if ($val > 1e-9) $man[(int)($e['row'] ?? 0)] = $val;
    }
    $src = $kp > 1e-9 ? 'KP72_QUOTA' : ($act > 1e-9 ? 'ACTUAL_ENERGY_MM2100' : ($man ? 'MANUAL_FIXED_FLOW_MM2100' : 'NONE'));
    return ['legal' => $src !== 'NONE', 'source' => $src, 'kp72_quota_bbtud' => round($kp, 6), 'actual_mm2100_bbtud' => round($act, 6),
            'actual_mm2100_hours' => $actRows, 'manual_fixed_flow_mm2100_rows' => $man];
}

/* ===== SOURCE OF TRUTH — EFFECTIVE MIN / MAX LOAD ==============================================
 * Satu-satunya jalan membaca batas beban unit. Urutan sumber nilai:
 *   1. Nilai user: Periodic Input -> Unit Characteristic -> Presence & Load Limits (d3[unit])
 *   2. Adjustment / derating yang valid (max_load_rules per row, via pp_effective_maxload)
 *   3. Rule per-row yang berlaku (Fixed Load mengunci beban -> min = max = nilai fixed)
 *   4. Default HANYA bila input benar-benar tidak tersedia (null / tidak terdefinisi)
 * Nilai 0 yang VALID tidak boleh diganti default: pengecekan memakai is_numeric(), bukan ?? / ?:,
 * sehingga 0 dan "0" tetap dihormati. */
function pp_effective_min_load(array $d3, array $model, string $unit, int $row1): float {
    $u = $d3[$unit] ?? [];
    $base = null;
    $cc = $u['min_ccload'] ?? null;
    if (is_numeric($cc))      $base = (float)$cc;    // mode combined cycle (nilai user)
    else { $sc = $u['min_scload'] ?? null;
        if (is_numeric($sc))  $base = (float)$sc;    // mode simple cycle (nilai user)
        else { $ml = $u['min_load'] ?? null;
            if (is_numeric($ml)) $base = (float)$ml;
        }
    }
    if ($base === null) $base = 0.0;                 // input tidak tersedia -> tanpa batas bawah
    /* BATAS BAWAH PER ROW (D-1). Sisi max sudah lama memiliki sumber kebenaran per row
     * (`max_load_rules`, dihormati pp_effective_maxload); sisi min TIDAK punya padanannya, sehingga
     * sebuah cabang legal "unit beroperasi di interval ATAS band" tidak dapat dinyatakan per row.
     * `min_load_rules[unit] = [{start,stop,min}]` menutup asimetri itu. Dipakai oleh pemilihan
     * cabang legal interval; tanpa aturan ini nilainya identik dengan perilaku sebelumnya. */
    $rules = $model['min_load_rules'][$unit] ?? ($model['min_load_rules'][strtoupper($unit)] ?? null);
    if (is_array($rules)) {
        foreach ($rules as $rl) {
            if (!is_array($rl)) continue;
            if ($row1 < (int)($rl['start'] ?? 1) || $row1 > (int)($rl['stop'] ?? 48)) continue;
            $mn = $rl['min'] ?? null;
            if (is_numeric($mn) && (float)$mn > $base) $base = (float)$mn;
        }
    }
    return $base;
}
function pp_effective_max_load(array $d3, array $model, string $unit, int $row1): float {
    $m = pp_effective_maxload($d3, $model, $unit, $row1);   // termasuk max_load_rules per row
    if ($m > 0) return $m;
    $base = $d3[$unit]['max_load'] ?? null;
    return is_numeric($base) ? (float)$base : 0.0;
}
/* Effective Maximum Load for a unit on a given 1-based row (Revisi): the unit's main max_load, overridden
 * by any time-based Maximum Load Adjustment rule (model.max_load_rules[unit] = [{start,stop,max}], up to 3,
 * non-overlapping) whose [start,stop] period covers the row. */
/* ===== SOURCE OF TRUTH — Babelan (BBLN1/BBLN2) max ramp rate =====================================
 * Domain: batas ramp Babelan = 10 MW / 30 menit PER UNIT (sebelumnya 5). Satu-satunya sumber nilai;
 * optimizer, repair pass, validator, dan UI WAJIB memakai helper ini (jangan sebar magic number).
 * Dibaca dari config editable bila tersedia (live-state UI, tanpa wajib Save); default domain 10.0.
 * Hanya berlaku utk b1/b2 — ramp unit lain (G8/G9=30, G7/G10=55) tidak disentuh. */
function pp_babelan_ramp_limit(array $model = []): float {
    $cfg = $model['babelan_ramp_limit_mw_per_30min'] ?? ($model['babelan_ramp_mw'] ?? null);
    if (is_numeric($cfg) && (float)$cfg > 0) return (float)$cfg;   // live-state override bila diisi UI
    return 10.0;                                                    // default domain (was 5.0)
}

function pp_effective_maxload(array $d3, array $model, string $unit, int $row1): float {
    $base = (float)($d3[$unit]['max_load'] ?? 0);
    $rules = $model['max_load_rules'][$unit] ?? ($model['max_load_rules'][strtoupper($unit)] ?? null);
    if (!is_array($rules) || !$rules) return $base;
    /* PATCH G31 ROOT CAUSE 2 (ramp-feasible envelope): unit ber-ramp tidak bisa jatuh mendadak ke cap
     * tepat di awal window Max Load Adjustment — descent WAJIB diantisipasi. Effective max di luar
     * window di-taper: cap(r) = min( rule_max + ramp * jarak(r, [start,stop]) ) untuk semua rule.
     * Ini implied-hard: load di atas envelope tidak mungkin masuk window tanpa melanggar ramp.
     * Ramp per unit: Babelan (helper, default 10), G8/G9 CC 30, G7/G10 SC 55 MW/30min; unit lain tanpa taper. */
    $bbRE = pp_babelan_ramp_limit($model);
    static $rampEnvBase = ['g8' => 30.0, 'g9' => 30.0, 'g7' => 55.0, 'g10' => 55.0];
    $rampEnv = $rampEnvBase + ['b1' => $bbRE, 'b2' => $bbRE];
    $ramp = $rampEnv[strtolower($unit)] ?? null;
    $cap = $base;
    foreach ($rules as $rl) {
        $s = (int)($rl['start'] ?? 1); $e = (int)($rl['stop'] ?? 48);
        $mx = min($base, (float)($rl['max'] ?? $base));
        if ($row1 >= $s && $row1 <= $e) { $cap = min($cap, $mx); continue; }
        if ($ramp !== null) {
            $dist = ($row1 < $s) ? ($s - $row1) : ($row1 - $e);
            $cap = min($cap, $mx + $ramp * $dist);
        }
    }
    return $cap;
}
/* ===== FORMAT CANONICAL unit_stop_time (satu interpretasi, dgn migrasi) ========================
 * Canonical: LIST record  [{unit, start, stop}]  dgn start/stop = nomor row 1..48.
 * Diterima juga (legacy) dan DIMIGRASIKAN ke canonical:
 *      - {unit, start:'HH:MM', stop:'HH:MM'}          (waktu -> row)
 *      - keyed  unit => ['HH:MM','HH:MM']             (bentuk lama UI/decommit)
 *      - keyed  unit => [startRow, stopRow]
 * Entri yang tidak dapat ditafsirkan TIDAK diabaikan diam-diam: dikumpulkan sebagai error
 * terstruktur (kode unit_stop_time_format) sehingga validator dapat melaporkannya.
 * Konvensi waktu mengikuti aplikasi: row r menandai akhir slot 30 menit (row 1 = 00:30),
 * sehingga '18:30' -> row 37 dan '24:00' -> row 48. */
function pp_stoptime_hhmm_to_row(string $t, bool $isStop): ?int {
    $t = trim($t);
    if ($t === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
        $mins = ((int)$m[1]) * 60 + (int)$m[2];
        if ($mins < 0 || $mins > 1440) return null;
        $row = $isStop ? (int)ceil($mins / 30) : (int)floor($mins / 30);
        if ($row < 1) $row = 1;
        if ($row > 48) $row = 48;
        return $row;
    }
    if (preg_match('/^\d{1,2}$/', $t)) {                       // sudah berupa row
        $row = (int)$t; return ($row >= 1 && $row <= 48) ? $row : null;
    }
    return null;
}
function pp_normalize_unit_stop_time(array &$model, array &$errors = []): array {
    $raw = $model['unit_stop_time'] ?? [];
    $out = [];
    if ($raw === null || $raw === '' || $raw === []) { $model['unit_stop_time'] = []; return []; }
    if (!is_array($raw)) {
        $errors[] = ['code' => 'unit_stop_time_format', 'message' => 'unit_stop_time harus berupa list record {unit,start,stop}; ditemukan ' . gettype($raw)];
        $model['unit_stop_time'] = [];
        return $errors;
    }
    foreach ($raw as $k => $v) {
        /* (a) canonical / semi-canonical record */
        if (is_array($v) && isset($v['unit'])) {
            $u = strtolower(trim((string)$v['unit']));
            $st = $v['start'] ?? 1; $sp = $v['stop'] ?? 48;
            $stRow = is_numeric($st) ? (int)$st : pp_stoptime_hhmm_to_row((string)$st, false);
            $spRow = is_numeric($sp) ? (int)$sp : pp_stoptime_hhmm_to_row((string)$sp, true);
            if ($u === '' || $stRow === null || $spRow === null || $stRow < 1 || $spRow > 48 || $stRow > $spRow) {
                $errors[] = ['code' => 'unit_stop_time_format', 'message' => sprintf('record tidak sah: unit=%s start=%s stop=%s', (string)($v['unit'] ?? '?'), (string)$st, (string)$sp)];
                continue;
            }
            $out[] = ['unit' => $u, 'start' => $stRow, 'stop' => $spRow];
            continue;
        }
        /* (b) legacy keyed: unit => [start, stop] */
        if (is_string($k) && is_array($v) && count($v) === 2) {
            $u = strtolower(trim($k));
            $st = $v[0]; $sp = $v[1];
            $stRow = is_numeric($st) ? (int)$st : pp_stoptime_hhmm_to_row((string)$st, false);
            $spRow = is_numeric($sp) ? (int)$sp : pp_stoptime_hhmm_to_row((string)$sp, true);
            if ($u === '' || $stRow === null || $spRow === null || $stRow > $spRow) {
                $errors[] = ['code' => 'unit_stop_time_format', 'message' => sprintf('entri keyed tidak sah: %s => [%s,%s]', $k, (string)$st, (string)$sp)];
                continue;
            }
            $out[] = ['unit' => $u, 'start' => $stRow, 'stop' => $spRow];
            continue;
        }
        $errors[] = ['code' => 'unit_stop_time_format',
                     'message' => 'entri unit_stop_time tidak dikenali (key=' . (string)$k . ')'];
    }
    /* dedup deterministik */
    $seen = []; $canon = [];
    foreach ($out as $r) {
        $sig = $r['unit'] . ':' . $r['start'] . ':' . $r['stop'];
        if (isset($seen[$sig])) continue;
        $seen[$sig] = true; $canon[] = $r;
    }
    usort($canon, fn($a, $b) => [$a['unit'], $a['start']] <=> [$b['unit'], $b['start']]);
    $model['unit_stop_time'] = $canon;
    return $errors;
}

function pp_is_unit_stopped(array $d3, array $model, string $unit, int $row1): bool {
    if(!pp_effective_unit_available($d3,$model,$unit,$row1))return true;
    if (in_array($unit, $model['unit_stop'] ?? [], true)) return true;
    foreach (($model['unit_stop_time'] ?? []) as $r) {
        if (($r['unit'] ?? '') === $unit && $row1 >= ($r['start'] ?? 1) && $row1 <= ($r['stop'] ?? 48)) return true;
    }
    return false;
}
/* ==============================================================================================
 *  RESPONSE ENCODER — SATU SUMBER KEBENARAN, TIDAK PERNAH MENGIRIM BODY KOSONG
 *
 *  CACAT YANG DIPERBAIKI (terukur). json_encode() GAGAL TOTAL bila satu saja nilai float di dalam
 *  payload bernilai INF atau NAN ("Inf and NaN cannot be JSON encoded"). Karena seluruh jalur
 *  response memanggil `echo json_encode($output, ...)` tanpa memeriksa hasilnya, satu nilai
 *  non-finit di sudut mana pun membuat SELURUH body kosong — operator melihat HTTP 422 tanpa isi
 *  dan tanpa keterangan. Terukur pada `info -> PGN Supplier Repair Review -> budget_left_seconds`
 *  yang bernilai INF ketika budget belum ter-arm.
 *
 *  PERBAIKAN. Nilai non-finit diganti null dan dicatat pada `_encoding_notes` (jujur: operator
 *  melihat bahwa ada nilai yang tidak dapat dikirim, bukan diam-diam dibulatkan menjadi 0).
 *  Bila encode masih gagal karena sebab lain, dikirim envelope error minimal yang tetap berisi
 *  status dan pesan — tidak pernah body kosong.
 * ============================================================================================ */
function pp_json_sanitize($v, string $path = '', array &$notes = []) {
    if (is_float($v)) {
        if (!is_finite($v)) { $notes[] = ($path !== '' ? $path : '(root)') . ' = ' . var_export($v, true); return null; }
        return $v;
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) $v[$k] = pp_json_sanitize($x, $path === '' ? (string)$k : $path . '.' . $k, $notes);
        return $v;
    }
    return $v;
}
function pp_json_out(array $payload): string {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $j = json_encode($payload, $flags);
    if ($j !== false) return $j;
    $notes = [];
    $clean = pp_json_sanitize($payload, '', $notes);
    if ($notes) $clean['_encoding_notes'] = ['non_finite_values_replaced_with_null' => array_slice($notes, 0, 20)];
    $j = json_encode($clean, $flags);
    if ($j !== false) return $j;
    $j = json_encode($clean, $flags | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($j !== false) return $j;
    return json_encode([
        'status' => (string)($payload['status'] ?? 'ENCODING_FAILED'),
        'error'  => ['code' => 'RESPONSE_ENCODING_FAILED',
                     'message' => 'Hasil simulasi tidak dapat diserialkan ke JSON: ' . json_last_error_msg()],
        'data'   => [],
    ], $flags);
}

function pp_get_fixed_load(array $model, string $unit, int $row1): float {
    foreach (($model['unit_fix_load'][$unit] ?? []) as $rule) {
        if ($row1 >= ($rule['start'] ?? 1) && $row1 <= ($rule['stop'] ?? 48)) return (float)$rule['value'];
    }
    return -1.0;
}
function pp_get_skip_load(array $model, string $unit, int $row1): ?array {
    foreach (($model['unit_skip_load'][$unit] ?? []) as $rule) {
        if ($row1 >= ($rule['start'] ?? 1) && $row1 <= ($rule['stop'] ?? 48)) {
            return [(float)$rule['value_low'], (float)$rule['value_high']];
        }
    }
    return null;
}


/* ==============================================================================================
 *  D-1 — COMPARATOR KANDIDAT CABANG LEGAL: SATU SUMBER KEBENARAN
 *
 *  MENGAPA DIEKSTRAK. Urutan pemilihan kandidat sebelumnya ditulis ulang di TIGA tempat (pemilih
 *  sinkron, loop verifikasi pipeline, dan job `legal_branch_exact`). Satu di antaranya menempatkan
 *  BIAYA sebelum jumlah total pelanggaran, dan itu benar-benar memilih rencana yang lebih buruk:
 *  pada band G9 66-70 cabang `[70,108]` menyisakan SATU pelanggaran export_range, sementara cabang
 *  `[65,66]` menyisakan export_range DITAMBAH kekurangan gas — tetapi `[65,66]` lebih murah
 *  (62,1306 vs 64,6601 USD/MWh) sehingga terpilih. Menambah kekurangan gas demi biaya yang lebih
 *  rendah bukan perbaikan. Karena itu urutan kini hidup di SATU fungsi yang dipakai ketiga tempat
 *  dan diuji terpisah oleh tools/comparator_test.php.
 *
 *  URUTAN KUNCI (instruksi §2, dari paling menentukan):
 *    1. hard control operator dilanggar   (kandidat yang melanggar kunci operator selalu kalah)
 *    2. status feasibility                (feasible SELALU mengalahkan infeasible)
 *    3. jumlah pelanggaran KERAS non-bahan-bakar
 *    4. TOTAL pelanggaran seluruh kategori
 *    5. bobot severity per kategori       (Skip Load > Export > reserve/Bus Flow > ramp > unit limit > gas)
 *    6. kelengkapan evaluasi kandidat     (kandidat yang benar-benar diukur mengalahkan yang tidak)
 *    7. production cost (Total Plant Cost Production)
 *    8. JBBK MM Cost Production
 *    9. heat rate
 *   10. label (tie-breaker deterministik)
 *
 *  Biaya BARU dibandingkan setelah kualitas feasibility dan pelanggaran setara — tidak sebelumnya.
 * ============================================================================================ */

/** Kelas pelanggaran yang diselesaikan operator lewat keputusan bahan bakar, bukan cacat rekayasa. */
function pp_branch_fuel_classes(): array {
    return ['gas_quota' => 1, 'runtime_downtime' => 1, 'mm2100_quota' => 1,
            'gas_window' => 1, 'distillate_quota' => 1, 'lng_quota' => 1];
}

/** Bobot severity per kategori pelanggaran. Angka lebih besar = lebih berat. */
function pp_branch_severity_weights(): array {
    return ['skip_load' => 100, 'export_range' => 40, 'export_ramp' => 40,
            'spinning_reserve' => 30, 'bus_flow' => 30, 'unit_ramp' => 20, 'ramp' => 20,
            'max_load' => 15, 'min_load' => 15, 'unit_limit' => 15, 'fixed_load' => 15,
            'last_data_status' => 10, 'cannot_stop' => 10, 'commitment_continuous' => 10,
            'gas_quota' => 5, 'runtime_downtime' => 5, 'mm2100_quota' => 5];
}

/**
 * Kunci perbandingan kandidat. Nilai lebih KECIL selalu lebih baik pada setiap elemen.
 *
 * @param array $c kandidat: label, skip_load_violations, violations, violation_types,
 *                 cost_plant_usd_mwh, cost_jbbk_usd_mwh, heat_rate, evaluated, operator_lock_broken
 * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:float,7:float,8:float,9:string}
 */
function pp_branch_candidate_key(array $c): array {
    $types = (array)($c['violation_types'] ?? []);
    $fuel  = pp_branch_fuel_classes();
    $wts   = pp_branch_severity_weights();
    $skip  = (int)($c['skip_load_violations'] ?? 0);
    $total = (int)($c['violations'] ?? 0);
    if ($skip < 0) $skip = 0;                                  // -1 = tidak diukur
    $hard = 0; $sev = 0;
    foreach ($types as $k => $n) {
        $k = (string)$k; $n = (int)$n;
        if (!isset($fuel[$k])) $hard += $n;
        $sev += $n * (int)($wts[$k] ?? 25);                    // kategori tak dikenal: bobot menengah
    }
    if (!$types && $total > 0) $hard = $total;                  // tanpa rincian: anggap seluruhnya keras
    $sev += $skip * $wts['skip_load'];
    $feasible = ($skip === 0 && $total === 0) ? 0 : 1;          // 0 = feasible, menang
    return [
        (int)!empty($c['operator_lock_broken']) ? 1 : 0,        // 1. hard control operator
        $feasible,                                              // 2. feasibility
        $hard + $skip,                                          // 3. pelanggaran keras (Skip Load ikut keras)
        $total + $skip,                                         // 4. total pelanggaran
        $sev,                                                   // 5. severity berbobot
        (int)(($c['evaluated'] ?? true) ? 0 : 1),               // 6. kelengkapan evaluasi
        round((float)($c['cost_plant_usd_mwh'] ?? 0), 6),       // 7. production cost
        round((float)($c['cost_jbbk_usd_mwh'] ?? 0), 6),        // 8. JBBK MM cost
        round((float)($c['heat_rate'] ?? 0), 4),                // 9. heat rate
        (string)($c['label'] ?? ''),                            // 10. tie-breaker deterministik
    ];
}

/** Bandingkan dua kandidat memakai kunci di atas. Negatif berarti $a lebih baik. */
function pp_branch_candidate_cmp(array $a, array $b): int {
    $ka = pp_branch_candidate_key($a); $kb = pp_branch_candidate_key($b);
    for ($i = 0; $i < 6; $i++) { $c = $ka[$i] <=> $kb[$i]; if ($c !== 0) return $c; }
    for ($i = 6; $i <= 8; $i++) { if (abs($ka[$i] - $kb[$i]) > 1e-9) return $ka[$i] <=> $kb[$i]; }
    return strcmp((string)$ka[9], (string)$kb[9]);
}

/** Alasan pemenang, untuk evidence: elemen kunci PERTAMA yang membedakannya dari runner-up. */
function pp_branch_win_reason(array $win, ?array $runnerUp): string {
    if ($runnerUp === null) return 'kandidat tunggal';
    $names = ['hard control operator', 'status feasibility', 'jumlah pelanggaran keras',
              'total pelanggaran', 'severity berbobot', 'kelengkapan evaluasi',
              'production cost', 'JBBK MM cost', 'heat rate', 'tie-breaker label'];
    $kw = pp_branch_candidate_key($win); $kr = pp_branch_candidate_key($runnerUp);
    foreach ($kw as $i => $v) {
        $differs = is_string($v) ? ((string)$v !== (string)$kr[$i]) : (abs((float)$v - (float)$kr[$i]) > 1e-9);
        if ($differs) return sprintf('%s (%s vs %s)', $names[$i], var_export($v, true), var_export($kr[$i], true));
    }
    return 'kunci identik';
}

/* ==============================================================================================
 *  D-1 — SUMBER KEBENARAN TUNGGAL UNTUK LEGAL LOAD INTERVALS (UNIT SKIP LOAD)
 *
 *  KONTRAK DOMAIN (instruksi §3.1). Untuk setiap unit, row, dan aturan Skip Load aktif, himpunan
 *  output legal adalah:
 *
 *      [applicable_min, lower_boundary]  UNION  [upper_boundary, applicable_max]
 *
 *  Kedua boundary IKUT LEGAL (interval tertutup). Yang terlarang adalah interval TERBUKA
 *  (lower_boundary, upper_boundary). Unit yang OFF (0 MW) tidak terkena band sama sekali.
 *
 *  ------------------------------------------------------------------------------------------
 *  AUDIT TOLERANCE ±0,51 MW (instruksi §4) — hasil, bukan dugaan
 *
 *  (1) ASAL ANGKA. 0,51 BUKAN toleransi floating-point dan BUKAN toleransi rounding tampilan.
 *      Ia adalah DISKRIMINATOR ON/OFF yang dipakai di seluruh basis kode: `$v > 0.51` berarti
 *      "unit sudah synchronized/berbeban", `$v <= 0.51` berarti "OFF". Angka itu = setengah
 *      langkah dispatch 0,5 MW + epsilon 0,01. Lihat pp_unit_state_label() dan ~30 call site lain
 *      (`if ($cur <= 0.51) continue;` dst). Konstanta itu DISALIN ke uji band Skip Load pada
 *      pp_validate_hard_constraints() butir 8. Jadi: KONSTANTA LAMA YANG DIPAKAI DI LUAR DOMAIN.
 *
 *  (2) SISI YANG TERKENA. Kedua sisi: `$actual >= $lo - 0.51 && $actual <= $hi + 0.51`.
 *      Efeknya forbidden band MELEBAR 1,02 MW, dan — ini yang fatal — `lower_boundary` serta
 *      `upper_boundary` yang menurut kontrak §3.1 LEGAL justru dinyatakan MELANGGAR. Contoh
 *      terukur: band G9 55-75, nilai 75,00 (tepat pada upper boundary, legal) dilaporkan sebagai
 *      pelanggaran skip_load; dan legal interval [75,108] menyusut menjadi (75,51 , 108].
 *
 *  (3) KONSISTENSI. TIDAK konsisten. Sebelum perbaikan ini terdapat TIGA toleransi berbeda:
 *      validator 0,51; candidate generator ($vInSkip pada shaper) 1e-9; post-pass repair
 *      0,51 + 0,09 = 0,60. Generator karena itu dapat memilih nilai yang ditolak validator.
 *
 *  (4) PERSETUJUAN DOMAIN. Tidak ada. Tidak satu pun instruksi, kontrak bisnis, atau dokumen
 *      domain menyebut pelebaran 0,51 MW untuk Skip Load. Yang dapat dibenarkan secara numerik
 *      hanyalah galat rounding TAMPILAN (baris output memakai round(...,2) -> 0,005 MW) dan noise
 *      floating-point. Keduanya jauh di bawah 0,51 MW.
 *
 *  KEPUTUSAN. Toleransi numerik dikembalikan ke perannya: hanya menyerap noise floating-point.
 *  PP_LOAD_EPS = 1e-6 MENYEMPITKAN forbidden band (bukan memperlebar), sehingga nilai TEPAT pada
 *  boundary selalu lolos. Nilai internal dan nilai tampilan dipisahkan: validator dan solver
 *  memakai nilai internal; PP_LOAD_DISPLAY_EPS hanya dipakai bila yang dibandingkan memang nilai
 *  yang sudah dibulatkan untuk tampilan. Satu helper (pp_load_in_forbidden_band) menjadi satu-
 *  satunya penentu, dipakai validator, candidate generator, dan seluruh pass pengubah dispatch.
 * ============================================================================================ */

/** Toleransi floating-point murni. MENYEMPITKAN forbidden band: boundary selalu legal. */
const PP_LOAD_EPS = 1e-6;
/** Galat rounding tampilan baris output (round(x,2)). Hanya untuk nilai yang SUDAH dibulatkan. */
const PP_LOAD_DISPLAY_EPS = 0.005;
/** Ambang ON/OFF dispatch (setengah langkah 0,5 MW + epsilon). Bukan toleransi band. */
const PP_UNIT_ON_MW = 0.51;
/** Langkah grid dispatch yang dipakai solver saat menyusun kandidat. */
const PP_DISPATCH_STEP_MW = 0.5;

/* PENCARIAN JENDELA SUPPLIER PGN (pp_actual_gas_compensation).
 * Batas pengaman jumlah rerun; penghentian yang sesungguhnya berasal dari budget waktu dan dari
 * resolusi bracket di bawah ini. PP_PGN_SUPPLIER_BRACKET_EPS = lebar bracket target internal yang
 * sudah terlalu sempit untuk menggerakkan dispatch diskret; di bawah itu window memang tidak dapat
 * dicapai dan pencarian berhenti dengan sebab yang dinyatakan. */
const PP_PGN_SUPPLIER_MAX_ATTEMPTS = 32;
const PP_PGN_SUPPLIER_BRACKET_EPS  = 1e-4;

/**
 * SEMUA band Skip Load yang berlaku untuk (unit,row), bukan hanya yang pertama.
 * pp_get_skip_load() mengembalikan band pertama saja; itu benar hanya selama window row tidak
 * pernah bertumpuk (dijamin pp_find_overlaps -> HTTP 400). Helper ini tetap mengumpulkan semuanya
 * agar seluruh jalur solver benar tanpa bergantung pada jaminan itu.
 *
 * @return array<int,array{0:float,1:float}> daftar [low,high] ternormalisasi (low <= high)
 */
function pp_skip_load_bands(array $model, string $unit, int $row1): array {
    $u = strtolower($unit);
    $rules = $model['unit_skip_load'][$u] ?? ($model['unit_skip_load'][strtoupper($unit)] ?? null);
    if (!is_array($rules) || !$rules) return [];
    $out = [];
    foreach ($rules as $rule) {
        if (!is_array($rule)) continue;
        if ($row1 < (int)($rule['start'] ?? 1) || $row1 > (int)($rule['stop'] ?? 48)) continue;
        $lo = (float)($rule['value_low'] ?? 0); $hi = (float)($rule['value_high'] ?? 0);
        if ($hi < $lo) { $t = $lo; $lo = $hi; $hi = $t; }    // input terbalik dinormalisasi
        if ($hi - $lo <= PP_LOAD_EPS) continue;             // band lebar nol tidak melarang apa pun
        $out[] = [$lo, $hi];
    }
    return $out;
}

/**
 * SATU-SATUNYA penentu apakah sebuah beban melanggar Unit Skip Load.
 * Forbidden band adalah interval TERBUKA (low, high): boundary legal. Unit OFF bebas.
 *
 * @param bool $displayRounded true bila $v adalah nilai yang sudah dibulatkan untuk tampilan
 *                             (round(x,2)); toleransi rounding tampilan ikut diserap.
 */
function pp_load_in_forbidden_band(array $model, string $unit, int $row1, float $v,
                                   bool $displayRounded = false): bool {
    if ($v <= PP_UNIT_ON_MW) return false;                  // OFF / belum synchronized: tidak terkena band
    $eps = PP_LOAD_EPS + ($displayRounded ? PP_LOAD_DISPLAY_EPS : 0.0);
    foreach (pp_skip_load_bands($model, $unit, $row1) as $b) {
        if ($v > $b[0] + $eps && $v < $b[1] - $eps) return true;
    }
    return false;
}

/** Normalisasi himpunan interval: buang yang kosong, urutkan, gabungkan yang bersentuhan. */
function pp_intervals_normalize(array $iv): array {
    $out = [];
    foreach ($iv as $x) {
        $a = (float)$x[0]; $b = (float)$x[1];
        if ($b < $a - PP_LOAD_EPS) continue;
        $out[] = [$a, max($a, $b)];
    }
    if (!$out) return [];
    usort($out, fn($p, $q) => $p[0] <=> $q[0]);
    $m = [array_shift($out)];
    foreach ($out as $x) {
        $last = &$m[count($m) - 1];
        if ($x[0] <= $last[1] + PP_LOAD_EPS) { if ($x[1] > $last[1]) $last[1] = $x[1]; }
        else $m[] = $x;
        unset($last);
    }
    return $m;
}

/** Kurangi himpunan interval dengan forbidden band TERBUKA (low,high) — boundary dipertahankan. */
function pp_intervals_subtract_open(array $iv, float $lo, float $hi): array {
    if ($hi - $lo <= PP_LOAD_EPS) return $iv;
    $out = [];
    foreach ($iv as $x) {
        $a = (float)$x[0]; $b = (float)$x[1];
        if ($b <= $lo + PP_LOAD_EPS || $a >= $hi - PP_LOAD_EPS) { $out[] = [$a, $b]; continue; }
        if ($a <= $lo + PP_LOAD_EPS) $out[] = [$a, min($b, $lo)];          // sisa bawah, boundary ikut
        if ($b >= $hi - PP_LOAD_EPS) $out[] = [max($a, $hi), $b];          // sisa atas, boundary ikut
    }
    return pp_intervals_normalize($out);
}

/**
 * HIMPUNAN OUTPUT LEGAL untuk (unit,row) — sumber kebenaran tunggal bagi candidate generation,
 * final gas cap, overmax trim, evidence/reconstruction, gas correction, Export repair,
 * Change Over recomputation, STG recomputation, final shaping, dan final invariant validation.
 *
 * Urutan (instruksi §3.2 butir 1-3):
 *   1. applicable min/max aktual (effective min/max load per row);
 *   2. dipotong Fix Load (mengunci min=max), batas pemanggil ($aMin/$aMax: ramp, gas cap, user lock);
 *   3. seluruh forbidden Skip Load interval dikeluarkan.
 *
 * Unit Stop / tidak available -> himpunan KOSONG untuk state berbeban (hanya 0 MW yang sah);
 * pemanggil menangani 0 lewat $allowOff.
 *
 * @return array{intervals:array<int,array{0:float,1:float}>,off_legal:bool,amin:float,amax:float,
 *               bands:array,locked:?float}
 */
function pp_legal_load_domain(array $d3, array $model, string $unit, int $row1,
                              ?float $aMin = null, ?float $aMax = null): array {
    $u     = strtolower($unit);
    $bands = pp_skip_load_bands($model, $u, $row1);
    $stopped = pp_is_unit_stopped($d3, $model, $u, $row1);
    $avail   = pp_effective_unit_available($d3, $model, $u, $row1);
    $eMin  = pp_effective_min_load($d3, $model, $u, $row1);
    $eMax  = pp_effective_max_load($d3, $model, $u, $row1);
    $fix   = pp_get_fixed_load($model, $u, $row1);              // -1.0 bila tidak dikunci
    $locked = ($fix >= 0) ? (float)$fix : null;

    $lo = max($eMin, $aMin ?? -INF);
    $hi = min($eMax, $aMax ??  INF);
    if ($locked !== null) { $lo = $locked; $hi = $locked; }     // Fix Load: satu titik

    $iv = ($stopped || !$avail || $hi < $lo - PP_LOAD_EPS) ? [] : [[$lo, $hi]];
    foreach ($bands as $b) $iv = pp_intervals_subtract_open($iv, $b[0], $b[1]);

    return ['intervals' => pp_intervals_normalize($iv), 'off_legal' => true,
            'amin' => $eMin, 'amax' => $eMax, 'bands' => $bands, 'locked' => $locked];
}

/** Ringkas: hanya daftar interval legal berbeban. */
function pp_legal_load_intervals(array $d3, array $model, string $unit, int $row1,
                                 ?float $aMin = null, ?float $aMax = null): array {
    return pp_legal_load_domain($d3, $model, $unit, $row1, $aMin, $aMax)['intervals'];
}

/** Irisan dua himpunan interval — dipakai untuk shared control level (G8 = G9). */
function pp_intervals_intersect(array $A, array $B): array {
    $out = [];
    foreach ($A as $a) foreach ($B as $b) {
        $lo = max((float)$a[0], (float)$b[0]);
        $hi = min((float)$a[1], (float)$b[1]);
        if ($hi >= $lo - PP_LOAD_EPS) $out[] = [$lo, max($lo, $hi)];
    }
    return pp_intervals_normalize($out);
}

/** Apakah $v berada di dalam himpunan interval legal. */
function pp_intervals_contain(array $iv, float $v): bool {
    foreach ($iv as $x) if ($v >= (float)$x[0] - PP_LOAD_EPS && $v <= (float)$x[1] + PP_LOAD_EPS) return true;
    return false;
}

/* ==============================================================================================
 *  D-1 — PEMILIHAN CABANG LEGAL (CANDIDATE GENERATION DI SUMBER, BUKAN TAMBALAN DI UJUNG)
 *
 *  MENGAPA BUKAN MENYENTUH 113 TITIK TULIS. Audit menghitung 113 titik yang menulis level dispatch
 *  di 18 fungsi (71 di antaranya di dalam pp_shape_final_dispatch saja). Menyisipkan pemeriksaan
 *  band di setiap titik itu terbukti rapuh: satu titik yang terlewat mengembalikan unit ke dalam
 *  band, dan itulah persis kegagalan pendekatan R2.
 *
 *  YANG DILAKUKAN. Nilai terlarang dibuat TIDAK DAPAT DIWAKILI oleh domain yang dilihat pass mana
 *  pun. Himpunan legal `[min, lower] U [upper, max]` adalah gabungan dua interval; solver memilih
 *  lebih dulu unit akan berada di interval MANA (cabang), lalu seluruh pass kontinu yang sudah ada
 *  — final gas cap, overmax trim, evidence/reconstruction, gas correction, Export repair, ramp
 *  smoothing, reserve repair, Change Over, STG recompute, final shaping — bekerja di dalam kotak
 *  [lo,hi] TANPA band di dalamnya. Kotak itu dipasang pada dua sumber kebenaran batas yang memang
 *  sudah dibaca setiap pass:
 *      cabang BAWAH -> max_load_rules[unit] = {start,stop,max: lower_boundary}
 *      cabang ATAS  -> min_load_rules[unit] = {start,stop,min: upper_boundary}
 *  Karena itu tidak ada pass yang dapat memilih nilai di dalam forbidden band: nilai itu di luar
 *  batas yang pass tersebut hormati. Pass hilir tidak diubah sama sekali.
 *
 *  Untuk window band yang mencakup SELURUH hari, cabang ATAS juga menaikkan skalar min_ccload/
 *  min_scload pada $d3 — itu membuat ~89 pembaca langsung `$d3[$u]['min_ccload']` (jalur startup
 *  dan commitment) ikut patuh tanpa perlu diubah satu per satu.
 * ============================================================================================ */

/**
 * Cabang legal yang mungkin untuk (unit,row): 'low' bila interval di bawah band tidak kosong,
 * 'high' bila interval di atas band tidak kosong. Kosong keduanya = konflik domain.
 *
 * @return array{low:?array{0:float,1:float},high:?array{0:float,1:float},band:?array{0:float,1:float}}
 */
function pp_legal_branches(array $d3, array $model, string $unit, int $row1): array {
    $bands = pp_skip_load_bands($model, $unit, $row1);
    $eMin  = pp_effective_min_load($d3, $model, $unit, $row1);
    $eMax  = pp_effective_max_load($d3, $model, $unit, $row1);
    if (!$bands || $eMax < $eMin - PP_LOAD_EPS) return ['low' => null, 'high' => null, 'band' => null];
    /* Beberapa band pada satu row digabung menjadi selubung terlarang terluar untuk keperluan
     * pemilihan cabang; interval legal di antara dua band tetap terlihat pp_legal_load_domain dan
     * tetap dipakai validator, jadi tidak ada pelanggaran yang lolos karena penggabungan ini. */
    $lo = INF; $hi = -INF;
    foreach ($bands as $b) { $lo = min($lo, $b[0]); $hi = max($hi, $b[1]); }
    $low  = ($lo - PP_LOAD_EPS > $eMin) ? [$eMin, min($lo, $eMax)] : null;
    $high = ($hi + PP_LOAD_EPS < $eMax) ? [max($hi, $eMin), $eMax] : null;
    return ['low' => $low, 'high' => $high, 'band' => [$lo, $hi]];
}

/**
 * Pasang KOTAK LEGAL terpilih ke domain yang dibaca seluruh pass.
 *
 * $branch[$unit] = salah satu dari:
 *    'low' | 'high'                     — sugar: interval di bawah / di atas selubung band
 *    [lo, hi]                           — satu kotak untuk seluruh row ber-band
 *    array<int,'low'|'high'|[lo,hi]>    — kotak per row1
 *
 * Bentuk [lo,hi] adalah bentuk umum: ia diperlukan ketika himpunan legal memiliki LEBIH DARI DUA
 * interval — terjadi pada multi-band satu row, dan pada level bersama G8=G9 dengan band BERBEDA
 * (irisan [65,78] u [84,90] u [96,108] memiliki tiga interval, sehingga 'low'/'high' tidak cukup).
 *
 * $d3 dan $model dimodifikasi di tempat (pemanggil bekerja pada salinan kandidat).
 *
 * @return array<int,string> keterangan kotak yang dipasang (evidence)
 */
function pp_apply_legal_branch(array &$d3, array &$model, array $branch, int $n = 48): array {
    $applied = [];
    $GLOBALS['__pp_legal_branch_rules'] = [];      // aturan yang benar-benar dipasang (untuk audit hilir)
    foreach ($branch as $unit => $sel) {
        $u = strtolower((string)$unit);
        if (!isset($d3[$u])) continue;
        $capRows = []; $floorRows = [];
        /* Bentuk ['box'=>[lo,hi],'rows'=>[r1,...]]: kotak eksplisit DAN daftar row yang dikenainya.
         * Daftar row WAJIB eksplisit untuk pasangan coupled: unit pasangan yang tidak ber-band harus
         * ikut dikotakkan pada row yang SAMA dengan row ber-band pasangannya — tidak lebih. Tanpa
         * itu, band satu row akan mengotakkan seluruh 48 row (terukur: band row 20 mengunci G8/G9
         * >= 90 sepanjang hari dan menimbulkan pelanggaran export_ramp). */
        $box = null; $boxRows = null;
        if (is_array($sel) && isset($sel['box']) && is_array($sel['box'])) {
            $box = [(float)$sel['box'][0], (float)$sel['box'][1]];
            $boxRows = isset($sel['rows']) && is_array($sel['rows']) ? array_map('intval', $sel['rows']) : null;
        } elseif (is_array($sel) && count($sel) === 2 && isset($sel[0], $sel[1])
                  && is_numeric($sel[0]) && is_numeric($sel[1])) {
            $box = [(float)$sel[0], (float)$sel[1]];                   // kotak tanpa daftar row: row ber-band
        }
        for ($r1 = 1; $r1 <= $n; $r1++) {
            if ($box !== null) {
                $inScope = ($boxRows !== null) ? in_array($r1, $boxRows, true)
                                               : (pp_skip_load_bands($model, $u, $r1) !== []);
                if (!$inScope) continue;
                if ($box[0] > pp_effective_min_load($d3, $model, $u, $r1) + PP_LOAD_EPS) $floorRows[$r1] = $box[0];
                if ($box[1] < pp_effective_max_load($d3, $model, $u, $r1) - PP_LOAD_EPS) $capRows[$r1]   = $box[1];
                continue;
            }
            $want = is_array($sel) ? ($sel[$r1] ?? '') : (string)$sel;
            if (is_array($want) && isset($want[0], $want[1])) {
                /* KOTAK PER ROW: setiap row ber-band boleh memakai interval legal yang berbeda. */
                $wLo = (float)$want[0]; $wHi = (float)$want[1];
                if ($wLo > pp_effective_min_load($d3, $model, $u, $r1) + PP_LOAD_EPS) $floorRows[$r1] = $wLo;
                if ($wHi < pp_effective_max_load($d3, $model, $u, $r1) - PP_LOAD_EPS) $capRows[$r1]   = $wHi;
                continue;
            }
            $br = pp_legal_branches($d3, $model, $u, $r1);
            if ($br['band'] === null) continue;                        // band tidak berlaku di row ini
            if ($want === 'low'  && $br['low']  !== null) $capRows[$r1]   = $br['low'][1];
            if ($want === 'high' && $br['high'] !== null) $floorRows[$r1] = $br['high'][0];
        }
        /* Ringkas row berurutan bernilai sama menjadi aturan {start,stop,nilai}. */
        $pack = function (array $rows): array {
            if (!$rows) return [];
            ksort($rows); $out = []; $s = null; $p = null; $val = null;
            foreach ($rows as $r1 => $v) {
                if ($s === null) { $s = $p = $r1; $val = $v; continue; }
                if ($r1 === $p + 1 && abs($v - $val) <= PP_LOAD_EPS) { $p = $r1; continue; }
                $out[] = [$s, $p, $val]; $s = $p = $r1; $val = $v;
            }
            $out[] = [$s, $p, $val];
            return $out;
        };
        foreach ($pack($capRows) as $seg) {
            $model['max_load_rules'][$u][] = ['start' => $seg[0], 'stop' => $seg[1], 'max' => $seg[2]];
            $GLOBALS['__pp_legal_branch_rules']['max_load_rules'][$u][] =
                ['start' => $seg[0], 'stop' => $seg[1], 'max' => $seg[2]];
            $applied[] = sprintf('%s rows %d-%d dibatasi max %.2f MW (cabang BAWAH band)',
                                 strtoupper($u), $seg[0], $seg[1], $seg[2]);
        }
        foreach ($pack($floorRows) as $seg) {
            $model['min_load_rules'][$u][] = ['start' => $seg[0], 'stop' => $seg[1], 'min' => $seg[2]];
            $GLOBALS['__pp_legal_branch_rules']['min_load_rules'][$u][] =
                ['start' => $seg[0], 'stop' => $seg[1], 'min' => $seg[2]];
            $applied[] = sprintf('%s rows %d-%d dibatasi min %.2f MW (cabang ATAS band)',
                                 strtoupper($u), $seg[0], $seg[1], $seg[2]);
            /* Window seluruh hari -> naikkan skalar min pada $d3 agar jalur startup/commitment yang
             * membaca $d3[$u]['min_ccload'] langsung ikut patuh tanpa diubah satu per satu. */
            if ($seg[0] === 1 && $seg[1] >= $n) {
                foreach (['min_ccload', 'min_scload', 'min_load'] as $k)
                    if (isset($d3[$u][$k]) && is_numeric($d3[$u][$k]) && (float)$d3[$u][$k] < $seg[2])
                        $d3[$u][$k] = $seg[2];
            }
        }
    }
    return $applied;
}

/**
 * Cabang yang dipilih bila unit dibiarkan pada nilai referensi $vRef (level hasil probe/historis).
 * 'low' / 'high' / null (band tidak berlaku atau kedua cabang kosong).
 */
function pp_branch_for_value(array $d3, array $model, string $unit, int $row1, float $vRef): ?string {
    $br = pp_legal_branches($d3, $model, $unit, $row1);
    if ($br['band'] === null) return null;
    if ($br['low'] === null && $br['high'] === null) return null;
    if ($br['low'] === null)  return 'high';
    if ($br['high'] === null) return 'low';
    if ($vRef <= $br['band'][0] + PP_LOAD_EPS) return 'low';
    if ($vRef >= $br['band'][1] - PP_LOAD_EPS) return 'high';
    $dLow = abs($vRef - $br['low'][1]); $dHigh = abs($br['high'][0] - $vRef);
    if (abs($dLow - $dHigh) <= PP_LOAD_EPS) return 'low';              // seri -> deterministik
    return ($dLow < $dHigh) ? 'low' : 'high';
}

/**
 * Jadikan cabang per row KOHEREN TERHADAP RAMP. Berpindah cabang berarti melompati seluruh lebar
 * band dalam satu slot; bila lebar band melebihi batas ramp unit, pindah cabang tidak layak dan
 * seluruh window dipaksa satu cabang (mayoritas). Tanpa ini cabang per row dapat meminta lompatan
 * yang kemudian ditolak pass ramp — menukar pelanggaran Skip Load dengan pelanggaran ramp.
 */
function pp_branch_ramp_coherent(array $rowBranch, float $bandWidth, float $rampLimit): array {
    if (!$rowBranch) return $rowBranch;
    ksort($rowBranch);
    if ($bandWidth <= $rampLimit + PP_LOAD_EPS) return $rowBranch;     // lompat cabang layak
    $cnt = ['low' => 0, 'high' => 0];
    foreach ($rowBranch as $b) if (isset($cnt[$b])) $cnt[$b]++;
    $win = ($cnt['high'] > $cnt['low']) ? 'high' : 'low';
    foreach ($rowBranch as $r => $b) if ($b !== '') $rowBranch[$r] = $win;
    return $rowBranch;
}

/** Titik legal TERBESAR yang <= $v; null bila tidak ada. */
function pp_legal_floor(array $iv, float $v): ?float {
    $best = null;
    foreach ($iv as $x) {
        $a = (float)$x[0]; $b = (float)$x[1];
        if ($a > $v + PP_LOAD_EPS) continue;
        $c = min($b, $v);
        if ($best === null || $c > $best) $best = $c;
    }
    return $best;
}

/** Titik legal TERKECIL yang >= $v; null bila tidak ada. */
function pp_legal_ceil(array $iv, float $v): ?float {
    $best = null;
    foreach ($iv as $x) {
        $a = (float)$x[0]; $b = (float)$x[1];
        if ($b < $v - PP_LOAD_EPS) continue;
        $c = max($a, $v);
        if ($best === null || $c < $best) $best = $c;
    }
    return $best;
}

/**
 * Titik legal terdekat dari $v di dalam himpunan interval.
 * Bila $v sudah legal, dikembalikan apa adanya (idempoten — tidak menggeser rencana yang sah).
 * Seri jarak diputus oleh $prefer: 'up' memilih yang lebih tinggi, 'down' yang lebih rendah,
 * 'near' deterministik memilih yang lebih rendah agar hasil tetap reproducible.
 */
function pp_snap_to_legal(array $iv, float $v, string $prefer = 'near'): ?float {
    if (!$iv) return null;
    if (pp_intervals_contain($iv, $v)) return $v;
    $best = null; $bestD = INF;
    foreach ($iv as $x) {
        $c = min(max($v, (float)$x[0]), (float)$x[1]);          // proyeksi ke interval
        $d = abs($c - $v);
        $take = ($d < $bestD - PP_LOAD_EPS);
        if (!$take && abs($d - $bestD) <= PP_LOAD_EPS && $best !== null) {
            $take = ($prefer === 'up') ? ($c > $best) : ($prefer === 'down' ? ($c < $best) : ($c < $best));
        }
        if ($take) { $best = $c; $bestD = $d; }
    }
    return $best;
}
function pp_get_max_load(array $d3, array $model, string $unit, int $row1): float {
    // optional per-period max (Sec.4) under unit_def['max_load_time'] = [{start,stop,value}]
    foreach (($d3[$unit]['max_load_time'] ?? []) as $r) {
        if ($row1 >= ($r['start'] ?? 1) && $row1 <= ($r['stop'] ?? 48)) return (float)$r['value'];
    }
    return (float)($d3[$unit]['max_load'] ?? 0);
}
function pp_is_hrsg_stopped(array $model, string $hrsg, int $row1): bool {
    if (in_array($hrsg, $model['hrsg_stop'] ?? [], true)) return true;
    foreach (($model['hrsg_stop_time'] ?? []) as $r) {
        if (($r['hrsg'] ?? '') === $hrsg && $row1 >= ($r['start'] ?? 1) && $row1 <= ($r['stop'] ?? 48)) return true;
    }
    return false;
}

/* gtg -> hrsg id, derived from the STG 'hrsg' maps in $d3. */
function pp_gtg_hrsg_map(array $d3): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $map = [];
    foreach (['s1', 's2', 's3'] as $s) {
        foreach (($d3[$s]['hrsg'] ?? []) as $h => $g) $map[$g] = $h;
    }
    $cache = $map;
    return $map;
}
function pp_gtg_feeds_stg(array $d3, array $model, string $gtg, int $row1): bool {
    $m = pp_gtg_hrsg_map($d3);
    if (!isset($m[$gtg])) return false;            // no HRSG -> never feeds an STG
    return !pp_is_hrsg_stopped($model, $m[$gtg], $row1);
}

/* Push a load out of its forbidden skip band to the nearest feasible edge. */
function pp_apply_skip(float $load, ?array $skip, float $min, float $max): float {
    if ($skip === null) return $load;
    [$lo, $hi] = $skip;
    if ($load < $lo || $load > $hi) return $load;  // already outside band
    $below = $lo; $above = $hi;
    $belowOk = $below >= $min; $aboveOk = $above <= $max;
    if ($belowOk && (!$aboveOk || ($load - $lo) <= ($hi - $load))) return $below;
    if ($aboveOk) return $above;
    return $min;                                    // band spans whole feasible range
}

/* -------------------------------------------------------------------------
 *  STG coefficient ratio (used as initial estimate in the block solver).
 * ------------------------------------------------------------------------- */
function stg_coef(string $stg): float {
    if ($stg === 's3') return 1.58;
    if ($stg === 's1' || $stg === 's2') return 1.48;
    return 1.5;
}

/* -------------------------------------------------------------------------
 *  STG output (Sec.2.3 / Sec.9).  $loads = list of GTG loads that are ONLINE
 *  AND whose HRSG is running (caller filters HRSG-stopped GTGs out).
 *  Picks the fuel segment by online-unit count (u1/u2/u3) and load sum.
 * ------------------------------------------------------------------------- */
/* -------------------------------------------------------------------------
 *  PROMPT STG 3-SEGMENT (S1/S2 Format 3-3-1): migrasi data lama 2 segment
 *  menjadi 3 segment. Segment 3 default = copy koefisien Segment 2 sampai
 *  user mengisi formula baru. Threshold default: T1 = 55 MW, T2 = 71 MW
 *  (T1 = ssp_f2u3, T2 = ssp_f3u3; wajib T1 < T2 — jika tidak valid, di-reset
 *  ke default dgn warning). Mengembalikan array warning (ringan, non-fatal).
 * ------------------------------------------------------------------------- */
function pp_stg_migrate_3segment(array &$d3): array {
    $warn = [];
    foreach (['s1', 's2'] as $sm) {
        if (!isset($d3[$sm]) || count((array)($d3[$sm]['gtg'] ?? [])) < 3) continue;
        $c = &$d3[$sm]; $mig = false; $badT = false;
        if (!isset($c['s1_f3u3']) || !isset($c['s0_f3u3']) || $c['s1_f3u3'] === '' || $c['s0_f3u3'] === '') {
            $c['s0_f3u3'] = $c['s0_f2u3'] ?? 0; $c['s1_f3u3'] = $c['s1_f2u3'] ?? 0;
            if (isset($c['s2_f2u3'])) $c['s2_f3u3'] = $c['s2_f2u3'];
            $mig = true;
        }
        $t1 = is_numeric($c['ssp_f2u3'] ?? null) ? (float)$c['ssp_f2u3'] : null;
        $t2 = is_numeric($c['ssp_f3u3'] ?? null) ? (float)$c['ssp_f3u3'] : null;
        if ($t1 === null || $t2 === null || !($t1 < $t2)) {
            $badT = ($t1 !== null && $t2 !== null && !($t1 < $t2));
            if ($t1 === null || !($t1 < 71.0)) $c['ssp_f2u3'] = 55.0;
            $c['ssp_f3u3'] = 71.0;
            $mig = true;
        }
        if ($badT) $warn[] = sprintf('%s STG Load Characteristic: Threshold 1 (%.2f) must be < Threshold 2 (%.2f) — reset to defaults 55/71 MW. Model tidak valid sampai threshold diperbaiki.', strtoupper($sm), $t1, $t2);
        elseif ($mig) $warn[] = strtoupper($sm) . ' STG Load Characteristic migrated from 2 segments to 3 segments. Please verify Segment 3 coefficients.';
        unset($c);
    }
    return $warn;
}

function calc_stg(array $d3, string $stg, array $loads): float {
    $coeffs = $d3[$stg] ?? [];
    /* PROMPT ISOLASI FORMULA STG (§3/§4): feed BER-NAMA (assoc 'g1'=>load, dikirim jalur
     * dispatch/materialisasi via pp_recompute_stgs) memakai koefisien PER-KOMBO — suffix
     * _g1 (Format 1-1-1) / _g1g2 (Format 2-2-1), urutan mengikuti daftar gtg STG. Ini
     * membuat "S2 with G1 connected" dan "S2 with G2 connected" formula TERPISAH TOTAL.
     * Backward compat: key per-kombo tidak ada -> fallback ke key shared per-count lama
     * (hasil identik dgn sebelum revisi). Feed anonim (list — jalur envelope perencanaan
     * blok min/max) tetap memakai key shared per-count. Format 3-3-1 = satu kombo per STG,
     * key plain u3 tetap (tidak ada masalah sharing). */
    $named = false; foreach ($loads as $lk => $lv) { $named = is_string($lk); break; }
    $active = [];
    if ($named) {
        $order = array_map('strtolower', array_map('strval', (array)($coeffs['gtg'] ?? [])));
        $units = [];
        foreach ($order as $gN) { $vN = (float)($loads[$gN] ?? 0); if ($vN > 0) { $units[] = $vN; $active[] = $gN; } }
        foreach ($loads as $gN => $vN)                                      // safety: nama di luar daftar gtg
            if (is_string($gN) && !in_array(strtolower($gN), $order, true) && (float)$vN > 0) { $units[] = (float)$vN; $active[] = strtolower($gN); }
    } else {
        $units = array_values(array_filter($loads, fn($x) => $x > 0));
    }
    $count  = count($units);
    if ($count === 0) return 0.0;
    $sum    = array_sum($units);
    $uSuf   = "u{$count}";
    $comboSuf = ($named && $count >= 1 && $count <= 2) ? ('_' . implode('', $active)) : '';
    $cf = function (string $k) use ($coeffs, $comboSuf) {
        if ($comboSuf !== '') { $v = $coeffs[$k . $comboSuf] ?? null; if ($v !== null && $v !== '') return $v; }
        $v = $coeffs[$k] ?? null; return ($v === '') ? null : $v;
    };

    /* PROMPT STG 3-SEGMENT: khusus S1/S2 pada Format 3-3-1 (3 GTG connected) pemilihan formula
     * memakai 3 segment eksplisit berdasarkan load (Requirement Logic 1, boundary T07):
     *   Segment 1 : load <  T1            (default T1 = 55 MW)
     *   Segment 2 : T1 <= load <= T2      (default T2 = 71 MW)
     *   Segment 3 : load >  T2
     * if/elif/else menjamin TIDAK ADA gap dan TIDAK ADA overlap range. Backward compat: data lama
     * 2 segment -> Segment 3 memakai copy koefisien Segment 2 (pp_stg_migrate_3segment); guard di
     * sini tetap ada agar calc_stg aman walau migrasi belum berjalan. S3 tidak berubah. */
    $stgL = strtolower($stg);
    if (($stgL === 's1' || $stgL === 's2') && $count === 3) {
        $t1 = is_numeric($cf('ssp_f2u3')) ? (float)$cf('ssp_f2u3') : 55.0;
        $t2 = is_numeric($cf('ssp_f3u3')) ? (float)$cf('ssp_f3u3') : 71.0;
        if (!($t1 < $t2)) { $t1 = 55.0; $t2 = 71.0; }                      // threshold invalid -> default aman
        $seg = ($sum < $t1) ? 'f1' : (($sum <= $t2) ? 'f2' : 'f3');
        $pick = function (string $f) use ($cf, $uSuf): ?array {
            $s0 = $cf("s0_{$f}{$uSuf}"); $s1 = $cf("s1_{$f}{$uSuf}");
            if (!is_numeric($s0) || !is_numeric($s1)) return null;
            $s2 = $cf("s2_{$f}{$uSuf}") ?? 0;
            return [(float)$s0, (float)$s1, is_numeric($s2) ? (float)$s2 : 0.0];
        };
        $c = $pick($seg);
        if ($c === null && $seg === 'f3') $c = $pick('f2');                // 2-segment lama: Segment 3 = copy Segment 2
        if ($c === null && $seg !== 'f1') $c = $pick('f1');
        if ($c !== null) return $c[2] * $sum ** 2 + $c[1] * $sum + $c[0];
        // tidak ada koefisien terdefinisi sama sekali -> jatuh ke rantai legacy di bawah
    }

    $sspIndex = ['f2', 'f3', 'f4'];
    foreach (['f1', 'f2', 'f3'] as $i => $fIndex) {
        $suffix    = "{$fIndex}{$uSuf}";
        $sspSuffix = "{$sspIndex[$i]}{$uSuf}";
        $s0  = $cf("s0_{$suffix}");
        $s1  = $cf("s1_{$suffix}");
        $s2  = $cf("s2_{$suffix}") ?? 0;
        $ssp = $cf("ssp_{$sspSuffix}");
        if ($s0 === null || $s1 === null) continue;
        if ($ssp !== null && $sum > $ssp) continue;
        return $s2 * $sum ** 2 + $s1 * $sum + $s0;
    }
    // fallback to first segment of this unit-count
    $suffix = "f1{$uSuf}";
    $s0 = $cf("s0_{$suffix}") ?? 0;
    $s1 = $cf("s1_{$suffix}") ?? 0;
    $s2 = $cf("s2_{$suffix}") ?? 0;
    return $s2 * $sum ** 2 + $s1 * $sum + $s0;
}

/* -------------------------------------------------------------------------
 *  Pre-compute min / max output per block, by online-unit count (Sec.7).
 *  Robust rewrite. Uses 'unit_priority' (falls back to block_priority).
 * ------------------------------------------------------------------------- */
function calc_min_block_load(array $d3): array {
    $minB = []; $maxB = [];
    $blocks = $d3['modeling']['unit_priority'] ?? $d3['modeling']['block_priority'] ?? [];

    foreach ($blocks as $i => $block) {
        $cc = []; $sc = []; $mx = []; $stg = '';
        foreach ($block as $unit) {
            if ($unit === 'required') continue;
            $c0 = $unit[0] ?? '';
            if ($c0 === 'g' || $c0 === 'b') {
                $cc[] = $d3[$unit]['min_ccload'] ?? null;     // null => no CC
                $sc[] = $d3[$unit]['min_scload'] ?? 0;
                $mx[] = $d3[$unit]['max_load'] ?? 0;
                if (!empty($d3[$unit]['stg'])) $stg = $d3[$unit]['stg'];
            } elseif ($c0 === 's') {
                $stg = $unit;                                  // STG present in block
            }
        }
        $minB[$i] = []; $maxB[$i] = [];
        $hasCC = $stg !== '' && count(array_filter($cc, fn($v) => $v !== null)) > 0;

        if ($hasCC) {
            // combined-cycle: scenarios with 1..N GTGs online
            $ccv = array_map(fn($v) => $v ?? 0, $cc);
            $cnt = count(array_filter($ccv));
            for ($n = 1; $n <= $cnt; $n++) {
                $minScen = []; $maxScen = [];
                for ($m = 0; $m < $n; $m++) { $minScen[] = $ccv[$m]; $maxScen[] = $mx[$m]; }
                $minB[$i][] = array_sum($minScen) + calc_stg($d3, $stg, $minScen);
                $maxB[$i][] = array_sum($maxScen) + calc_stg($d3, $stg, $maxScen);
            }
            if ($cnt === 0) { $minB[$i][] = 0; $maxB[$i][] = 0; }
        } else {
            // simple-cycle / non-STG: cumulative sums 1..N units
            $cntU = count($sc);
            for ($n = 1; $n <= $cntU; $n++) {
                $minB[$i][] = array_sum(array_slice($sc, 0, $n));
                $maxB[$i][] = array_sum(array_slice($mx, 0, $n));
            }
            if ($cntU === 0) { $minB[$i][] = 0; $maxB[$i][] = 0; }
        }
    }
    return [$minB, $maxB];
}

/* -------------------------------------------------------------------------
 *  House load (Sec. plant aux).  Depends on which GTGs are online.
 * ------------------------------------------------------------------------- */
function calc_house_load(array $gen): float {
    /* ==========================================================================================
     * OPT-H1 — MEMOISASI HOUSE LOAD. Terukur 2,87 detik waktu SELF pada tiga skenario contoh
     * (5,0% dari total tersampel), seluruhnya untuk mengulang delapan pembacaan array yang sama.
     *
     * EKSAK TANPA SYARAT. Nilai balik fungsi ini hanya bergantung pada JAWABAN BINER "apakah
     * unit ini > 0" untuk delapan unit tetap — bukan pada besar bebannya, bukan pada $d3, $model,
     * atau state global mana pun. Karena itu state fungsi ini persis 8 bit, dan memo berkunci
     * 8 bit itu menghasilkan nilai yang identik menurut definisi: dua $gen dengan pola on/off
     * yang sama WAJIB menghasilkan house load yang sama pada rumus di bawah.
     *
     * Tabel memo maksimum 256 entri dan tidak pernah basi, sehingga tidak perlu epoch sama sekali.
     * ========================================================================================== */
    static $memo = [];
    $m = 0;
    if (($gen['g3'] ?? 0) > 0) $m |= 1;
    if (($gen['g4'] ?? 0) > 0) $m |= 2;
    if (($gen['g6'] ?? 0) > 0) $m |= 4;
    if (($gen['g1'] ?? 0) > 0) $m |= 8;
    if (($gen['g2'] ?? 0) > 0) $m |= 16;
    if (($gen['g5'] ?? 0) > 0) $m |= 32;
    if (($gen['g8'] ?? 0) > 0) $m |= 64;
    if (($gen['g9'] ?? 0) > 0) $m |= 128;
    if (isset($memo[$m]) && !PP_FUEL_MEMO_VERIFY && !PP_MEMO_OFF) return $memo[$m];
    $f6 = 0.75; $f9 = 2.75;
    $hl1 = 2.75; $hl3 = 1.0;
    foreach (['g3', 'g4', 'g6', 'g1', 'g2', 'g5'] as $g) if (($gen[$g] ?? 0) > 0) $hl1 += $f6;
    foreach (['g8', 'g9'] as $g) if (($gen[$g] ?? 0) > 0) $hl3 += $f9;
    $res = $hl1 + $hl3;
    if (isset($memo[$m]) && PP_FUEL_MEMO_VERIFY && pack('d', $memo[$m]) !== pack('d', $res))
        throw new RuntimeException('OPT-H1: memo calc_house_load tidak sama dengan perhitungan penuh');
    return $memo[$m] = $res;
}

/* -------------------------------------------------------------------------
 *  GTG / GE gas use (BBTU per 30-min slot).  Numeric behaviour preserved
 *  from the calibrated original (incl. the large-SC-GTG curve convention).
 * ------------------------------------------------------------------------- */
function calc_fuel(array $d3, string $gtg, float $load): float {
    if ($load < 1) return 0.0;
    /* ==========================================================================================
     * OPT-F1 — MEMOISASI HASIL KURVA BAHAN BAKAR (result-preserving, bukan penyederhanaan rumus).
     *
     * ALASAN, DARI PENGUKURAN BUKAN DUGAAN. Instrumentasi pada PHP target mencatat calc_fuel
     * sebagai hot leaf terbesar di seluruh pipeline:
     *     baseline  12.882.274 panggilan
     *     PGN 40    52.106.242 panggilan   (skenario 32,64 s dengan HANYA 3 core run —
     *                                       artinya waktunya habis di daun ini, bukan di jumlah pass)
     *     Skip Load 20.389.162 panggilan
     *
     * MENGAPA MEMO INI EKSAK. calc_fuel adalah fungsi MURNI dari ($gtg, $load) dan field kurva
     * milik unit itu: max_load, x0_f1/x1_f1/x2_f1, xsp_f2, x0_f2/x1_f2/x2_f2, xcf. Audit tulis
     * menyeluruh atas seluruh berkas PHP paket ini menunjukkan TIDAK ADA satu pun penugasan ke
     * field-field tersebut — field kurva berasal dari input_data.json dan tidak pernah dimutasi
     * selama request. Mutasi $d3 yang memang ada hanya menyentuh min_ccload/min_scload/min_load
     * (pp_apply_legal_branch) dan koefisien STG s1/s2/s3 (pp_stg_migrate_3segment); calc_fuel
     * tidak membaca satu pun dari keduanya, dan s1/s2/s3 tidak pernah menjadi argumen $gtg.
     *
     * EPOCH TETAP DIPASANG sebagai pengaman struktural: bila suatu saat ada pass yang mengganti
     * kurva unit, pemanggil cukup menaikkan $GLOBALS['__pp_fuel_epoch'] dan memo gugur seluruhnya.
     * Epoch dinaikkan di titik masuk setiap simulasi, jadi memo tidak pernah menyeberang antar run.
     *
     * KUNCI LOSSLESS. $load dipak dengan pack('d') — 8 byte IEEE-754 apa adanya — BUKAN
     * dirangkai sebagai string desimal. String desimal pada PHP tunduk pada `precision=14`,
     * sehingga dua beban yang berbeda di digit ke-16 (lazim muncul dari pengurangan berulang di
     * pencarian offset kuota) akan MENGHASILKAN KUNCI YANG SAMA dan memo akan mengembalikan nilai
     * unit yang salah. pack('d') menutup lubang itu sepenuhnya.
     *
     * VERIFIKASI. Dengan PP_FUEL_MEMO_VERIFY=1 setiap hit memo tetap menghitung ulang badan
     * fungsi dan membandingkan bit-per-bit; selisih apa pun menghentikan proses. Mode itu dipakai
     * saat menjalankan A/B oracle, sehingga kesetaraan hasil dibuktikan pada beban nyata, bukan
     * diklaim dari penalaran di atas.
     * ========================================================================================== */
    static $memo  = [];
    static $epoch = null;
    $curEpoch = $GLOBALS['__pp_fuel_epoch'] ?? 0;
    if ($curEpoch !== $epoch) { $memo = []; $epoch = $curEpoch; }
    $memoKey = $gtg . pack('d', $load);
    $memoHit = isset($memo[$memoKey]);
    if ($memoHit && !PP_FUEL_MEMO_VERIFY && !PP_MEMO_OFF) return $memo[$memoKey];
    $u  = $d3[$gtg] ?? [];
    // Load-dependency guard (Revisi V6): a generating unit cannot exceed its max_load, and every GTG/GE fuel
    // curve here is monotone-increasing within [min, max]. The calibrated G1-G6 f2 polynomial turns over near
    // ~59 MW (far above their 31 MW max), so clamp the load to max_load before evaluating — this leaves every
    // in-range value byte-identical while guaranteeing fuel(load) is non-decreasing (load up => gas up) and
    // never lands on the unphysical declining tail. Fixed Flow (Jababeka/MM2100/KP72) is separate, not fuel.
    $mlF = (float)($u['max_load'] ?? 0);
    if ($mlF > 0 && $load > $mlF) $load = $mlF;
    /* §4.3 PROMPT STOP_EXPORT_LNG_MM2100: kurva Gas Engine di data3 = DAILY BBTUD
     * (0.170138608336371*Load + 0.130793537177791). Pipeline memakai basis per-jam
     * (kolom x24 = daily), maka GE dikonversi daily/24 di satu titik ini — semua turunan
     * (TOTAL GAS MM2100, per-slot /48, quota MM, dispatch cluster) otomatis konsisten. */
    $s0 = $u['x0_f1'] ?? null;
    $s1 = $u['x1_f1'] ?? null;
    $s2 = $u['x2_f1'] ?? 0;
    if (isset($u['xsp_f2']) && $load > $u['xsp_f2']) {
        $s0 = $u['x0_f2'] ?? null;
        $s1 = $u['x1_f2'] ?? null;
        $s2 = $u['x2_f2'] ?? 0;
    }
    $fuel = $s2 * $load ** 2 + $s1 * $load + $s0;
    $cf   = $u['xcf'] ?? 1;

    if ($gtg === 'ge1' || $gtg === 'ge2' || $gtg === 'ge3' || $gtg === 'ge4') {
        /* §4.3: kurva GE = DAILY BBTUD (0.170138608336371*Load + 0.130793537177791).
         * Basis pipeline = per-jam (x24 = daily), maka /24 di sini. */
        $res = ($fuel * $cf) / 24.0;
    } elseif ($gtg === 'g7' || $gtg === 'g8' || $gtg === 'g9') {
        // GTG 7/8/9: Gas_1jam = (0.008*Load + 0.4026) * CF — LOAD-DEPENDENT (gas rises with load; NOT fixed fuel).
        $res = $fuel * $cf;                       // BBTUD per hour (engine halves per 30-min row downstream)
    } elseif ($gtg === 'g10') {
        // GTG 10: upward parabola (x2>0) — load-dependent, monotone increasing.
        $res = ($fuel * $cf * 1000) / 1000000.0;
    } else {
        // GTG 1..6: Gas_1jam = poly(Load) * Load / 1e6 * CF  (poly switches at 15 MW via xsp_f2) — load-dependent.
        $res = ($fuel * $cf * $load) / 1000000.0;
    }
    /* OPT-F1: verifikasi hit memo terhadap perhitungan penuh — dipakai saat A/B oracle. */
    if ($memoHit) {
        if (PP_FUEL_MEMO_VERIFY && pack('d', $memo[$memoKey]) !== pack('d', $res)) {
            fwrite(STDERR, sprintf("PP_FUEL_MEMO_MISMATCH gtg=%s load=%.17g memo=%.17g nyata=%.17g\n",
                                   $gtg, $load, $memo[$memoKey], $res));
            throw new RuntimeException('OPT-F1: memo calc_fuel tidak sama dengan perhitungan penuh');
        }
        return $memo[$memoKey];
    }
    /* Batas atas jumlah entri: melindungi memori bila suatu input menghasilkan beban kontinu yang
     * nyaris tidak pernah berulang. Melewati batas hanya menghentikan PENYIMPANAN entri baru —
     * hasil yang dikembalikan tetap hasil perhitungan penuh, jadi tidak ada perubahan nilai. */
    if (count($memo) < 2000000) $memo[$memoKey] = $res;
    return $res;
}

/* ============================================================================================
 * KONVERSI DISTILLATE — SATU SUMBER KEBENARAN, PARAMETER EKSPLISIT.
 *
 * Sebelumnya faktor 0.8424 x 19400 x 2.2046 di-hardcode di LIMA tempat (worker_functions.php
 * calc_fuel_dist, worker02.php Required/Recommended Distillate + Distillate Reconciliation, dan
 * index.php refreshGasDecision) tanpa satu pun keterangan satuan. Nilainya tidak ada di
 * input_data.json sehingga operator tidak dapat memeriksa, mengoreksi, atau mengaudit angka
 * liter yang ditampilkan.
 *
 * Faktor-faktor itu kini menjadi KONFIGURASI EKSPLISIT dengan satuan yang dinyatakan. Default
 * sama persis dengan konstanta lama, sehingga hasil numerik TIDAK BERUBAH bila operator tidak
 * mengisi apa pun.
 *
 *   modeling.distillate_density_kg_per_l   kg/l      (default 0.8424)
 *   modeling.distillate_lhv_btu_per_lb     BTU/lb    (default 19400)
 *   modeling.lb_per_kg                     lb/kg     (default 2.2046 — konversi satuan, bukan properti bahan bakar)
 *
 * Basis: BTU per liter = density(kg/l) x lb_per_kg(lb/kg) x LHV(BTU/lb).
 *        liter = energi(BTU) / (BTU per liter).
 *        1 BBTU = 1e9 BTU. Nilai per slot 30 menit memakai pembagi tambahan 2 (BBTUD -> per slot).
 * ============================================================================================ */
function pp_distillate_conversion(array $model): array {
    $dens = $model['distillate_density_kg_per_l'] ?? null;
    $lhv  = $model['distillate_lhv_btu_per_lb']   ?? null;
    $lbkg = $model['lb_per_kg']                   ?? null;
    $src  = [];
    $dens = (is_numeric($dens) && (float)$dens > 0) ? (float)$dens : (($src[] = 'density=DEFAULT') ? 0.8424 : 0.8424);
    $lhv  = (is_numeric($lhv)  && (float)$lhv  > 0) ? (float)$lhv  : (($src[] = 'lhv=DEFAULT')     ? 19400.0 : 19400.0);
    $lbkg = (is_numeric($lbkg) && (float)$lbkg > 0) ? (float)$lbkg : (($src[] = 'lb_per_kg=DEFAULT') ? 2.2046 : 2.2046);
    $btuPerLitre = $dens * $lbkg * $lhv;
    return [
        'density_kg_per_l' => $dens, 'lhv_btu_per_lb' => $lhv, 'lb_per_kg' => $lbkg,
        'btu_per_litre' => $btuPerLitre,
        'source' => $src ? ('SEBAGIAN_DEFAULT_ENGINE: ' . implode(',', $src)) : 'SELURUHNYA_DARI_INPUT',
        'complete_from_input' => empty($src),
        'basis' => 'liter = energi_BTU / (density_kg_per_l x lb_per_kg x lhv_btu_per_lb); 1 BBTU = 1e9 BTU',
    ];
}
/* BBTUD energi -> liter per hari. Satu-satunya tempat konversi ini boleh terjadi. */
function pp_distillate_litres_from_bbtu(float $bbtu, array $model): float {
    if ($bbtu <= 0) return 0.0;
    $c = pp_distillate_conversion($model);
    return ($bbtu * 1000000000.0) / $c['btu_per_litre'];
}

/* Distillate per GTG (Sec.11), litres per 30-min slot. GTG 1..10 only. */
function calc_fuel_dist(array $d3, string $gtg, float $load): float {
    if ($load < 1) return 0.0;
    $gasBBTU = calc_fuel($d3, $gtg, $load);       // BBTU-equivalent thermal need
    /* KONVERSI EKSPLISIT: faktor dibaca dari konfigurasi (default = konstanta lama, hasil identik).
     * Pembagi 2 mengubah nilai harian menjadi per slot 30 menit. */
    $model = $d3['modeling'] ?? [];
    return pp_distillate_litres_from_bbtu($gasBBTU, $model) / 2.0;
}

/* -------------------------------------------------------------------------
 *  SYSTEMIC PRIORITY ORDER (PROMPT PRIORITY AUDIT Bagian A — bukan case-by-case).
 *  Single source of truth for EVERY pass that picks a unit for up-dispatch,
 *  down-dispatch, start, stop, decommit, donor, emergency lever, export/bus/
 *  gas/spinning-reserve repair. Reads the USER INPUT priority, never a
 *  hardcoded unit order.
 *
 *  pp_priority_flat($model, $pattern):
 *    Flattened unit order from modeling.unit_priority (fallback:
 *    modeling.block_priority), lowercased, deduped, 'required' tags skipped,
 *    optionally filtered by regex (e.g. '/^g\d+$/' = GTGs only).
 *    Higher priority = earlier in the list. Physical block MEMBERSHIP may be
 *    hardcoded elsewhere (plant mapping), but dispatch ORDER must come from here.
 *
 *  pp_priority_flat_reverse(): same list reversed — down-dispatch / stop /
 *    decommit order (lowest Unit Priority first, per Rule A4).
 *
 *  pp_priority_sort_required_first($model, $units): stable-sorts a candidate
 *    set so members of a Required block come first, then by Unit Priority rank.
 * ------------------------------------------------------------------------- */
function pp_priority_flat(array $model, ?string $pattern = null): array {
    /* ==========================================================================================
     * OPT-P1 — MEMOISASI URUTAN PRIORITAS. Terukur 1,54 detik waktu SELF (2,7% dari total
     * tersampel, dan 1,50 detik di antaranya pada satu skenario PGN 40 saja). Seluruh biaya itu
     * adalah MEMBANGUN ULANG daftar yang sama: strtolower + trim + preg_match + in_array per unit,
     * berkali-kali, untuk hasil yang tidak pernah berbeda.
     *
     * EKSAK. Fungsi ini hanya membaca `modeling.unit_priority` (fallback `block_priority`).
     * Audit tulis menyeluruh atas seluruh berkas PHP paket ini menemukan NOL penugasan ke kedua
     * kunci itu — urutan prioritas adalah INPUT OPERATOR dan memang tidak boleh berubah di tengah
     * request. Karena itu hasil hanya bergantung pada ($pattern) dalam satu request, dan memo
     * digugurkan per request lewat pp_req_gen().
     *
     * CATATAN ARAH: pp_priority_flat_reverse() dan pp_priority_rank() ikut menjadi murah karena
     * keduanya memanggil fungsi ini — tidak ada satu pun call site yang perlu diubah.
     * ========================================================================================== */
    static $memo = []; static $gen = null;
    $g = pp_req_gen();
    if ($g !== $gen) { $memo = []; $gen = $g; }
    $mk = ($pattern === null) ? "\0null" : $pattern;
    if (isset($memo[$mk]) && !PP_FUEL_MEMO_VERIFY && !PP_MEMO_OFF) return $memo[$mk];
    $src = $model['unit_priority'] ?? $model['block_priority'] ?? [];
    $out = [];
    foreach ((array)$src as $grp) foreach ((array)$grp as $u) {
        $u = strtolower(trim((string)$u));
        if ($u === '' || $u === 'required') continue;
        if ($pattern !== null && !preg_match($pattern, $u)) continue;
        if (!in_array($u, $out, true)) $out[] = $u;
    }
    if (isset($memo[$mk]) && PP_FUEL_MEMO_VERIFY && $memo[$mk] !== $out)
        throw new RuntimeException('OPT-P1: memo pp_priority_flat tidak sama dengan perhitungan penuh');
    return $memo[$mk] = $out;
}

function pp_priority_flat_reverse(array $model, ?string $pattern = null): array {
    return array_reverse(pp_priority_flat($model, $pattern));
}

function pp_priority_rank(array $model): array {
    return array_flip(pp_priority_flat($model));
}

function pp_unit_block_required(array $model, string $u): bool {
    $u = strtolower($u);
    foreach ((array)($model['block_priority'] ?? []) as $blk) {
        if (!is_array($blk)) continue;
        $mem = array_map('strtolower', array_map('strval', $blk));
        if (in_array($u, $mem, true)) return in_array('required', $mem, true);
    }
    return false;
}

function pp_priority_sort_required_first(array $model, array $units): array {
    $rank = pp_priority_rank($model);
    $units = array_values(array_unique(array_map('strtolower', $units)));
    usort($units, function ($a, $b) use ($model, $rank) {
        $ra = pp_unit_block_required($model, $a) ? 0 : 1;
        $rb = pp_unit_block_required($model, $b) ? 0 : 1;
        if ($ra !== $rb) return $ra <=> $rb;                     // Required block first
        return ($rank[$a] ?? PHP_INT_MAX) <=> ($rank[$b] ?? PHP_INT_MAX);  // then Unit Priority
    });
    return $units;
}

/* -------------------------------------------------------------------------
 *  Babelan coal (Sec.12.2), tonnes per 30-min slot, RE-aware.
 *  load and re are MW (30-min averaged); formula already divides by 2.
 * ------------------------------------------------------------------------- */
function calc_coal(float $load, float $re = 0.0): float {
    $net = $load - $re;
    if ($net <= 0) return 0.0;
    $x = $net / 140.0;
    return (((1151.4 * $x ** 2 - 2439.8 * $x + 4014) / 4100.0) * $net) / 2.0;
}

/* -------------------------------------------------------------------------
 *  Spinning reserve (Sec.8.1), MW.
 * ------------------------------------------------------------------------- */
function calc_sr(array $gen): float {
    $sr = 0.0;
    foreach (['g3', 'g4', 'g6', 'g1', 'g2', 'g5'] as $g) {
        $v = $gen[$g] ?? 0; if ($v > 5 && $v < 31) $sr += 31 - $v;
    }
    foreach (['g8', 'g9'] as $g) {
        $v = $gen[$g] ?? 0; if ($v > 5 && $v < 108) $sr += 108 - $v;
    }
    foreach (['ge1', 'ge2', 'ge3', 'ge4'] as $g) {
        $v = $gen[$g] ?? 0; if ($v > 3 && $v < 12.5) $sr += 12.5 - $v;
    }
    return $sr;
}

/* -------------------------------------------------------------------------
 *  Bus flow (Revisi Bugfix Bus Flow) = IE data1 - Total Load Bus B (MW).
 *  (Formula lama "Bus A - Bus B" DIHAPUS — jangan dipakai lagi di mana pun.)
 * ------------------------------------------------------------------------- */
function calc_busflow(array $gen, array $busUnit, float $ie = 0.0): float {
    // Bus Flow = IE data1 - Total Load Bus B (Revisi Bugfix Bus Flow). MM2100 units (Gas Engines GE1-4 and
    // GTG10) sit on the separate MM2100 feeder, not the PLN A/B buses, so they are excluded from the PLN
    // Bus B total (KP72/MM2100 load must not perturb the PLN bus — isolation).
    /* KEPUTUSAN DOMAIN FINAL Q4 — Bus Flow WAJIB generik dari data3.modeling.bus_unit.
     * Daftar exclusion hardcoded untuk MM2100 (ge1-ge4, g10) DIHAPUS: unit apa pun yang
     * memiliki assignment bus pada konfigurasi ikut dihitung sesuai bus-nya. Bila assignment
     * berubah di input, perilaku mengikuti tanpa perubahan source. Tidak ada unit yang
     * di-hardcode ke Bus B, dan tidak ada double-count karena setiap unit dijumlah tepat sekali
     * dari $gen (bukan lewat agregat Total_GE). */
    /* V4 OPT-B1: status "unit di Bus B" disimpan per isi konfigurasi bus_unit (perbandingan ===
     * pada zval yang sama selesai seketika). Urutan penjumlahan tidak berubah -> hasil identik. */
    static $cBU = null, $isB = [];
    if ($cBU === null || $busUnit !== $cBU) { $cBU = $busUnit; $isB = []; }
    $b = 0.0;
    foreach ($gen as $unit => $load) {
        $f = $isB[$unit] ?? null;
        if ($f === null) { $bus = $busUnit[strtolower((string)$unit) . '_bus'] ?? null;   // tanpa assignment -> tidak masuk bus
            $f = $isB[$unit] = ($bus !== null && strtoupper((string)$bus) === 'B'); }
        if ($f) $b += (float)$load;
    }
    return $ie - $b;
}

/* =========================================================================
 *  BLOCK DISPATCHER (Sec.7) — unified for STG and non-STG blocks.
 *
 *  Fills $block to carry ~$available MW, honouring:
 *    - unit run-order inside the block (commit fewest units needed),
 *    - required vs non-required (NON-required blocks are NOT forced to min;
 *      they stop when not needed — Sec.7.3 / Sec.15.3 fix),
 *    - unit stop / fixed load / skip load / time-based max,
 *    - STG bottoming with HRSG status (Sec.2.3),
 *    - min CC vs min SC selection.
 *  Writes per-unit loads into $gen and returns the block's total MW output.
 * ========================================================================= */
function pp_solve_block(array $block, float $available, int $row1, array $d3, array $model, array &$gen): float {
    $isRequired = in_array('required', $block, true);
    $busNoop = null;

    // Pre-scan: does this block contain an STG? (it is usually listed LAST, so
    // we must know before classifying the GTG minimums as CC vs SC.)
    $stg = null;
    foreach ($block as $u) { if (($u[0] ?? '') === 's') { $stg = $u; break; } }
    $hasStg = ($stg !== null);

    $fixedLoads = [];        // unit => load (forced on)
    $fixedFeed  = [];        // fixed GTG loads that feed STG
    $fill = [];              // ordered list: ['u'=>id,'min'=>..,'max'=>..,'feed'=>bool,'skip'=>?]

    foreach ($block as $unit) {
        if ($unit === 'required') continue;
        $c0 = $unit[0] ?? '';
        if ($c0 === 's') { $gen[$unit] = 0; continue; }   // STG handled via calc_stg
        // GTG or Babelan
        $gen[$unit] = 0;
        if (pp_is_unit_stopped($d3, $model, $unit, $row1)) { $gen[$unit] = 0; continue; }

        $umax  = pp_get_max_load($d3, $model, $unit, $row1);
        $fx    = pp_get_fixed_load($model, $unit, $row1);
        $feeds = ($c0 === 'g') ? pp_gtg_feeds_stg($d3, $model, $unit, $row1) : false;

        if ($fx >= 0) {                                  // fixed load unit
            $umin = $d3[$unit]['min_scload'] ?? 0;
            $val  = pp_clamp($fx, $umin, $umax);
            $gen[$unit] = $val;
            $fixedLoads[$unit] = $val;
            continue;
        }
        $useCC = $hasStg && isset($d3[$unit]['min_ccload']);
        $umin  = $useCC ? $d3[$unit]['min_ccload'] : ($d3[$unit]['min_scload'] ?? 0);
        $fill[] = [
            'u' => $unit, 'min' => (float)$umin, 'max' => (float)$umax,
            'feed' => $feeds, 'skip' => pp_get_skip_load($model, $unit, $row1),
        ];
    }

    /* ===== RUNNING-UNITS-FIRST FILL ORDER (PROMPT SAME-BLOCK §2/§3, generik) =================
     * ROOT CAUSE start G3 yang tidak perlu: daftar $fill diisi mengikuti URUTAN ARRAY $block,
     * sehingga saat blok perlu k unit, unit yang dipilih adalah k unit PERTAMA dalam array —
     * tanpa memandang apakah unit tsb sedang RUNNING atau justru sedang OFF (butuh startup).
     * Akibatnya unit OFF (mis. G3) bisa di-commit padahal unit yang sudah running pada blok yang
     * sama masih punya headroom. Perbaikan: urutkan $fill secara STABIL dengan kunci
     *   (1) sudah running / required-continuous  -> lebih dulu,
     *   (2) Unit Priority (pp_priority_flat)     -> lebih dulu,
     * sehingga "running units first" dan "same-block before cross-block" berlaku sejak dispatch
     * awal, bukan ditambal oleh pass decommit di hilir. Tidak ada nama unit yang di-hardcode. */
    if (count($fill) > 1) {
        $prioIdx = array_flip(pp_priority_flat($model, '/^g\d+$/'));
        $lastSt  = (array)($model['unit_last_data_status'] ?? []);
        $reqMode = (array)($model['required_mode'] ?? []);
        $rankOf = function (array $f) use ($prioIdx, $lastSt, $reqMode): array {
            $u = $f['u'];
            $isRun = (strtolower((string)($lastSt[strtoupper($u)] ?? '')) === 'running') ? 0 : 1;
            $isReq = isset($reqMode[$u]) ? 0 : 1;                   // perintah operator = harus jalan
            return [min($isRun, $isReq), (int)($prioIdx[$u] ?? 999)];
        };
        $idx = 0;
        foreach ($fill as &$fRef) { $fRef['__ord'] = $idx++; }
        unset($fRef);
        usort($fill, function ($x, $y) use ($rankOf) {
            [$rx, $px] = $rankOf($x); [$ry, $py] = $rankOf($y);
            if ($rx !== $ry) return $rx <=> $ry;                     // running/required dulu
            if ($px !== $py) return $px <=> $py;                     // lalu Unit Priority
            return $x['__ord'] <=> $y['__ord'];                      // stabil
        });
        foreach ($fill as &$fRef2) unset($fRef2['__ord']);
        unset($fRef2);
    }

    // fixed GTGs that feed this block's STG
    if ($stg !== null) {
        foreach ($fixedLoads as $u => $v) {
            if (($u[0] ?? '') === 'g' && pp_gtg_feeds_stg($d3, $model, $u, $row1)) $fixedFeed[] = $v;
        }
    }

    $fixedSum = array_sum($fixedLoads);
    $nFill = count($fill);

    // Closure: block output when the first $k fill units run at common load $v.
    $blockOut = function (float $v, int $k) use ($fill, $fixedSum, $fixedFeed, $stg, $d3): float {
        $sum = $fixedSum;
        $feed = $fixedFeed;
        for ($j = 0; $j < $k; $j++) { $sum += $v; if ($fill[$j]['feed']) $feed[] = $v; }
        if ($stg !== null) $sum += calc_stg($d3, $stg, $feed);
        return $sum;
    };

    // No variable units: block output is whatever the fixed/STG produce.
    if ($nFill === 0) {
        $tot = $blockOut(0.0, 0);
        return $tot;
    }

    $fmin = $fill[0]['min'];                 // identical units within a block in this dataset
    $fmax = $fill[0]['max'];

    // --- NON-OVERSHOOT selection (Sec.7.3) -----------------------------
    // Produce the LARGEST output <= available so the small remainder spills
    // to lower-priority blocks instead of being force-absorbed here.
    // Required blocks keep a floor of one unit at min.
    $bestK = 0;
    for ($k = 1; $k <= $nFill; $k++) {
        if (blockOutMixed($fill, $fixedSum, $fixedFeed, $stg, $d3, $k, 'min') <= $available + 1e-6) $bestK = $k;
    }

    if ($bestK === 0) {
        // even one unit at min overshoots $available
        if ($isRequired && $fixedSum <= 0) {
            return pp_fill_k_units($fill, 1, 'min', $row1, $stg, $fixedSum, $fixedFeed, $d3, $gen); // floor
        }
        // non-required (or fixed already present): commit nothing variable
        return pp_fill_k_units($fill, 0, 'min', $row1, $stg, $fixedSum, $fixedFeed, $d3, $gen);
    }

    $maxEnvK = blockOutMixed($fill, $fixedSum, $fixedFeed, $stg, $d3, $bestK, 'max');
    if ($available >= $maxEnvK - 1e-6) {
        // in the gap above bestK (or exactly its max): run bestK units at max
        return pp_fill_k_units($fill, $bestK, 'max', $row1, $stg, $fixedSum, $fixedFeed, $d3, $gen);
    }

    // available within [minEnv_bestK, maxEnv_bestK]: solve common load to hit it
    $lo = $fmin; $hi = $fmax; $v = $fmin;
    for ($it = 0; $it < 60; $it++) {
        $v = ($lo + $hi) / 2.0;
        $out = $blockOut($v, $bestK);
        if (abs($out - $available) <= 0.5) break;
        if ($out < $available) $lo = $v; else $hi = $v;
    }
    return pp_fill_k_units($fill, $bestK, $v, $row1, $stg, $fixedSum, $fixedFeed, $d3, $gen);
}

/* Block output for k units at each unit's own 'min' or 'max' (handles unequal units). */
function blockOutMixed(array $fill, float $fixedSum, array $fixedFeed, ?string $stg, array $d3, int $k, string $which): float {
    $sum = $fixedSum; $feed = $fixedFeed;
    for ($j = 0; $j < $k; $j++) {
        $val = $which === 'max' ? $fill[$j]['max'] : $fill[$j]['min'];
        $sum += $val; if ($fill[$j]['feed']) $feed[] = $val;
    }
    if ($stg !== null) $sum += calc_stg($d3, $stg, $feed);
    return $sum;
}

/* Commit k fill units; $v is 'min', 'max', or a numeric common load. Applies
   skip-load per unit, writes into $gen, returns block total output. */
function pp_fill_k_units(array $fill, int $k, $v, int $row1, ?string $stg, float $fixedSum, array $fixedFeed, array $d3, array &$gen): float {
    $sum = $fixedSum; $feed = $fixedFeed;
    for ($j = 0; $j < count($fill); $j++) {
        $u = $fill[$j]['u'];
        if ($j >= $k) { $gen[$u] = 0; continue; }
        if ($v === 'min')      $load = $fill[$j]['min'];
        elseif ($v === 'max')  $load = $fill[$j]['max'];
        else                   $load = pp_clamp((float)$v, $fill[$j]['min'], $fill[$j]['max']);
        $load = pp_apply_skip($load, $fill[$j]['skip'], $fill[$j]['min'], $fill[$j]['max']);
        $gen[$u] = $load;
        $sum += $load;
        if ($fill[$j]['feed']) $feed[] = $load;
    }
    if ($stg !== null) { $stgOut = calc_stg($d3, $stg, $feed); $gen[$stg] = $stgOut; $sum += $stgOut; }
    return $sum;
}

/* =========================================================================
 *  STARTUP STG & ADDITIONAL HRSG CONSTRAINTS  (Revisi Sec.7)
 *  Post-dispatch enforcement so the per-row optimiser cannot produce load
 *  profiles that violate the unit start-up timeline (e.g. 75 -> 0 -> 75).
 * ========================================================================= */

/* Ramp-cap timeline: MW ceiling for each consecutive online 30-min slot a GTG
 * must obey while its STG/HRSG is starting, before it is free Min/Max.
 * Derived from Sec.7.2 (Additional HRSG) and Sec.7.3 / 7.4 (STG cold/warm/hot). */
/* Additional-HRSG ramp caps (per KETENTUAN file): a GTG starting into a block
 * whose STG is ALREADY loaded ramps via the short HRSG sequence, NOT the full
 * STG-startup sequence. HRSG 1-6: 5->15->free. HRSG 8-9: 40->50->60->70->free. */
/* Number of rows AFTER a feeding GTG's OFF->ON start during which the STG may NOT
 * yet be generated (calc_stg gatekeeping, Revisi Sec.4-7). Cold-block start uses
 * the full STG startup window; additional-HRSG uses the shorter HRSG delay. */
function pp_stg_release_rows(string $stg, string $mode): int {
    $mode = strtolower(trim($mode));
    if ($mode === 'none' || $mode === 'no start up sequence' || $mode === 'no startup') return 0;
    if ($stg === 's3') { if ($mode === 'cold') return 11; if ($mode === 'warm') return 5; return 1; } // 5.5h / 2.5h / 30min
    if ($mode === 'cold') return 7;                       // STG1/2 cold: 3.5h
    if ($mode === 'warm') return 2;                       // STG1/2 warm: 60min
    return 1;                                             // STG1/2 hot: 30min
}
function pp_additional_hrsg_release_rows(string $stg): int {
    return ($stg === 's3') ? 5 : 2;                       // HRSG 8-9: 150min ; HRSG 1-6: 60min
}

function pp_additional_hrsg_caps(string $u): array {
    /* PROMPT FINAL §5: G8/G9 Additional HRSG = 40 MW selama 60 mnt (2 slot), 50 MW 30 mnt,
     * 60 MW 60 mnt (2 slot), 70 MW 30 mnt — baru bebas. Sebelumnya [40,50,60,70] (durasi salah). */
    if ($u === 'g8' || $u === 'g9') return [40, 40, 50, 60, 60, 70];
    if (in_array($u, ['g1','g2','g3','g4','g5','g6'], true)) return [5, 15];
    return [5];                                       // g7/g10 (no HRSG): single step
}

/* ADDENDUM ANTI AUTO-RUN: himpunan unit yang "diminta running" (required / continuous / cannot-stop /
 * start request-simulation / anggota Block Required / Last-Status Running / unit MM2100 saat kuota > 0). */
function pp_all_ge_stopped_full_horizon(array $d3,array $model,int $n=48): bool {
    foreach(['ge1','ge2','ge3','ge4'] as $u){
        if(!isset($d3[$u]))continue;
        for($r=1;$r<=$n;$r++)if(!pp_is_unit_stopped($d3,$model,$u,$r))return false;
    }
    return true;
}
function pp_g10_autorun_allowed(array $d3,array $model,float $mmQuota,int $n=48): bool {
    if(pp_unit_explicitly_requested($model,'g10'))return true;
    /* G10 is residual absorber only if KP72 exists and all GE are operator-stopped/unavailable.
       If GE are available, the MM allocator must maximize GE before considering G10. */
    return $mmQuota>1e-9&&pp_all_ge_stopped_full_horizon($d3,$model,$n);
}
function pp_autorun_allowed(array $model, float $mmQuota): array {
    $allow = array_map('strtolower', array_merge(
        (array)($model['required_units'] ?? []),
        (array)($model['unit_cannot_stop'] ?? []),
        array_keys((array)($model['required_mode'] ?? []))));
    foreach ((array)($model['block_priority'] ?? []) as $blk) {
        if (is_array($blk) && in_array('required', array_map('strtolower', array_map('strval', $blk)), true))
            foreach ($blk as $uu) { $uu = strtolower((string)$uu); if ($uu !== 'required') $allow[] = $uu; }
    }
    foreach (($model['unit_last_data_status'] ?? []) as $k => $v)
        if (strtolower((string)$v) === 'running') $allow[] = strtolower((string)$k);
    if($mmQuota>1e-9)foreach(['ge1','ge2','ge3','ge4'] as $uu)$allow[]=$uu; /* GE-first; G10 residual or explicit only */
    return array_values(array_unique($allow));
}

/* REFAKTOR J-GROUP: normalisasi Change Over Block 1-2 diekstrak dari worker02 agar validator
 * memakai aturan yang SAMA (last status, stop windows sintetis, cannot_stop, required, co_norm). */
function pp_normalize_change_over(array &$model): void {
    /* Bila timeline sim/sim sudah DIPUTUSKAN oleh pp_changeover_sim_sweep(), pakai waktu pemenang
     * sehingga normalisasi mengikuti jalur request-time yang sama (bukan cannot_stop sepanjang hari). */
    if (!empty($model['change_over']['enabled']) && !empty($GLOBALS['__pp_co_resolved'])) {
        $rv = (array)$GLOBALS['__pp_co_resolved'];
        $sig = substr(md5(json_encode($model['change_over'] ?? [])), 0, 16);
        if (($rv['input_sig'] ?? null) === $sig) {
            foreach ((array)($model['change_over']['blocks'] ?? []) as $bn => $blk) {
                if ((string)$bn === (string)($rv['target_block'] ?? '')) $model['change_over']['blocks'][$bn]['start_other'] = $rv['start_other'];
                if ((string)$bn === (string)($rv['source_block'] ?? '')) $model['change_over']['blocks'][$bn]['stop_other']  = $rv['stop_other'];
            }
            $model['co_resolved_timeline'] = ['start_other' => $rv['start_other'], 'stop_other' => $rv['stop_other'],
                                              'mode' => 'sim/sim resolved by timeline sweep'];
        }
    }
    if (!empty($model['change_over']['enabled'])) {
    $coBlocks = (array)($model['change_over']['blocks'] ?? []);
    // Block→unit fallback map (used only if the UI didn't send gtg/stg, e.g. older inputs)
    $coFallback = ['1' => ['gtg' => 'g3', 'stg' => 's1'], '2' => ['gtg' => 'g1', 'stg' => 's2']];
    $coNorm = ['enabled' => true, 'blocks' => []];
    $anchorRunning = false;
    /* Coordinated sim/sim candidate: worker02 has already converted both controls to concrete times.
     * Release normal Required/Continuous/Cannot-Stop protection only for the source anchor units in
     * this deep-cloned candidate. The original request model is never modified. */
    if (!empty($model['change_over_candidate_context']) && !empty($model['change_over_authorized_source_release']['units'])) {
        $releaseUnits = array_values(array_unique(array_map('strtolower',
            (array)$model['change_over_authorized_source_release']['units'])));
        foreach ($releaseUnits as $su) unset($model['required_mode'][$su], $model['required_mode'][strtoupper($su)]);
        $model['required_units'] = array_values(array_filter((array)($model['required_units'] ?? []),
            static fn($u) => !in_array(strtolower((string)$u), $releaseUnits, true)));
        $model['unit_cannot_stop'] = array_values(array_filter((array)($model['unit_cannot_stop'] ?? []),
            static fn($u) => !in_array(strtolower((string)$u), $releaseUnits, true)));
    }
    // Collect all 4 change-over-managed units up front and clear any stale baseline commitment for them
    // (required_mode continuous / unit_cannot_stop / required_units), so change_over is authoritative and
    // its synthesized holds never contradict a leftover continuous flag from the base input.
    $coUnits = [];
    foreach (['1','2'] as $bn) {
        $blk = (array)($coBlocks[$bn] ?? []);
        $g = strtolower((string)($blk['gtg'] ?? $coFallback[$bn]['gtg']));
        $s = strtolower((string)($blk['stg'] ?? $coFallback[$bn]['stg']));
        if ($g !== '') $coUnits[] = $g; if ($s !== '') $coUnits[] = $s;
    }
    foreach ($coUnits as $cu) {
        if (isset($model['required_mode'][$cu])) unset($model['required_mode'][$cu]);
        if (isset($model['required_mode'][strtoupper($cu)])) unset($model['required_mode'][strtoupper($cu)]);
    }
    $model['unit_cannot_stop'] = array_values(array_filter(
        array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])),
        fn($x) => !in_array($x, $coUnits, true)));
    foreach (['1','2'] as $bn) {
        $blk  = (array)($coBlocks[$bn] ?? []);
        $gtg  = strtolower((string)($blk['gtg'] ?? $coFallback[$bn]['gtg']));
        $stg  = strtolower((string)($blk['stg'] ?? $coFallback[$bn]['stg']));
        $last = (string)($blk['last_status'] ?? '');
        if ($last === '') $last = 'Stop';   // safety default
        $running = (strtolower($last) === 'running');
        if ($running) $anchorRunning = true;
        $entry = ['gtg' => $gtg, 'stg' => $stg, 'last_status' => $running ? 'Running' : 'Stop',
                  'start_other' => null, 'stop_other' => null, 'start_row' => null, 'stop_row' => null];
        if (!$running) {
            // Stopped block -> its units are 0 at row0; make Last Data Status consistent (Stop),
            // and ensure they aren't pinned Continuous/Cannot-Stop (a stopped block can't be cannot-stop).
            foreach ([$gtg, $stg] as $uu) { if ($uu !== '') $model['unit_last_data_status'][strtoupper($uu)] = 'Stop'; }
            $model['unit_cannot_stop'] = array_values(array_filter(
                array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])),
                fn($x) => $x !== $gtg && $x !== $stg));
            // Stopped block -> Start Other governs how it comes online during the day.
            $so = (string)($blk['start_other'] ?? 'sim');
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $so, $sm)) {
                $startRow = (int)(((int)$sm[1] * 60 + (int)$sm[2]) / 30);   // 00:30->1 .. 23:30->47
                if ($startRow < 1) $startRow = 48;                          // 00:00 -> end (start held all day = infeasible->evidence)
                // hold GTG + STG at 0 up to and including the marker row (loads 30 min later)
                $model['unit_stop_time'] = (array)($model['unit_stop_time'] ?? []);
                foreach ([$gtg, $stg] as $uu) { if ($uu !== '') $model['unit_stop_time'][] = ['unit' => $uu, 'start' => 1, 'stop' => $startRow]; }
                // and require the GTG to actually start (must be loaded after the hold)
                $ru = array_map('strtolower', (array)($model['required_units'] ?? []));
                if ($gtg !== '' && !in_array($gtg, $ru, true)) { $ru[] = $gtg; $model['required_units'] = $ru; }
                $entry['start_other'] = $so; $entry['start_row'] = $startRow;
                /* §3 PRIMARY DESTINATION UNIT PRIORITY — HARD SELECTION ORDER.
                 * GTG destination yang ditunjuk change_over ($gtg) WAJIB dicoba lebih dahulu.
                 * Tanpa aturan ini, block dispatcher bebas memilih sibling GTG pada blok yang sama
                 * (mis. G3 start row 11 mendahului G4 row 30) semata karena pertimbangan ekonomi
                 * lokal — itu CHANGE_OVER_PRIMARY_UNIT_PRIORITY_VIOLATION.
                 * Sibling GTG pada blok destination ditahan sampai primary benar-benar online.
                 * Bila primary terbukti tidak feasible, validator akan melaporkannya dan
                 * certificate PRIMARY_DESTINATION_UNIT_REJECTED diterbitkan pada info. */
                $sibs = [];
                foreach ((array)($model['block_priority'] ?? []) as $grp) {
                    $g = array_map('strtolower', (array)$grp);
                    if (!in_array($gtg, $g, true)) continue;
                    foreach ($g as $u2) {
                        if ($u2 === 'required' || $u2 === $gtg || $u2 === $stg) continue;
                        if (($u2[0] ?? '') === 's') continue;              // STG mengikuti GTG-nya
                        if (in_array($u2, array_map('strtolower', (array)($model['required_units'] ?? [])), true)) continue;
                        $sibs[] = $u2;
                    }
                    break;
                }
                foreach ($sibs as $u2) $model['unit_stop_time'][] = ['unit' => $u2, 'start' => 1, 'stop' => 48];
                $model['co_primary_destination'] = [
                    'primary_gtg' => strtoupper($gtg), 'primary_stg' => strtoupper($stg),
                    'start_command_row' => $startRow,
                    'expected_first_positive_row' => $startRow + 1,
                    'siblings_held' => array_map('strtoupper', $sibs),
                    'rule' => 'CHANGE_OVER_PRIMARY_UNIT_PRIORITY (Unit Priority = hard selection order)',
                    'note' => 'sibling GTG hanya boleh start bila primary ditolak dengan certificate'];
            } else {
                // Based on simulation: block must start at least once -> add GTG to required_units.
                $ru = array_map('strtolower', (array)($model['required_units'] ?? []));
                if ($gtg !== '' && !in_array($gtg, $ru, true)) { $ru[] = $gtg; $model['required_units'] = $ru; }
                $entry['start_other'] = 'sim';
            }
        } else {
            // Running block -> Last Data Status Running (it was on yesterday / stays the anchor).
            foreach ([$gtg, $stg] as $uu) { if ($uu !== '') $model['unit_last_data_status'][strtoupper($uu)] = 'Running'; }
            // Stop Other governs how/when it drops off.
            $to = (string)($blk['stop_other'] ?? 'sim');
            if (preg_match('/^(\d{1,2}):(\d{2})$/', $to, $tm)) {
                $stopRow = (int)(((int)$tm[1] * 60 + (int)$tm[2]) / 30);
                if ($stopRow <= 0) $stopRow = 48;                            // 00:00 -> row 48
                $model['unit_stop_time'] = (array)($model['unit_stop_time'] ?? []);
                /* FIX CHANGE_OVER_SOURCE_STOP_NOT_GUARDED_BY_DESTINATION_READINESS
                 * Perintah stop source hanya sah bila destination BENAR-BENAR dapat start.
                 * Bila GTG destination sendiri dijadwalkan stop pada rentang yang menutup
                 * $stopRow (mis. operator men-stop-nya seharian, atau PM), maka menghentikan
                 * source akan membuat KEDUA block mati bersamaan — melanggar kontrak
                 * "block pengganti start dulu sebelum block lama stop".
                 * Dalam kondisi itu perintah stop DIBATALKAN dan source dipertahankan. */
                $destGtg = ''; $destStg = '';
                foreach ((array)($model['change_over']['blocks'] ?? []) as $bn2 => $blk2) {
                    if ((string)$bn2 === (string)$bn) continue;
                    $destGtg = strtolower((string)($blk2['gtg'] ?? '')); $destStg = strtolower((string)($blk2['stg'] ?? ''));
                }
                /* Cek kesiapan destination HANYA dari $model (fungsi ini tidak menerima $d3):
                 * bila destination GTG dijadwalkan stop menutupi seluruh rentang [stopRow..48],
                 * ia tidak mungkin online dan perintah stop source dibatalkan. */
                $destBlocked = false;
                if ($destGtg !== '') {
                    $free = 0;
                    for ($rr = $stopRow; $rr <= 48; $rr++) {
                        $blocked = false;
                        foreach ((array)($model['unit_stop_time'] ?? []) as $st) {
                            if (strtolower((string)($st['unit'] ?? '')) !== $destGtg) continue;
                            if ($rr >= (int)($st['start'] ?? 1) && $rr <= (int)($st['stop'] ?? 48)) { $blocked = true; break; }
                        }
                        if (!$blocked) { $free++; if ($free >= 2) break; }
                    }
                    if ($free < 2) $destBlocked = true;
                }
                if ($destBlocked) {
                    $model['co_source_stop_cancelled'] = [
                        'source_block' => (string)$bn, 'stop_row' => $stopRow,
                        'destination_gtg' => strtoupper($destGtg),
                        'reason' => 'CHANGE_OVER_SOURCE_STOP_CANCELLED_DESTINATION_NOT_READY',
                        'note' => 'menghentikan source akan membuat kedua block mati bersamaan; source dipertahankan'];
                } else {
                    $family = [$gtg, $stg];
                    $familyMap = ['s1' => ['g3','g4','g6','s1'], 's2' => ['g1','g2','g5','s2'], 's3' => ['g8','g9','s3']];
                    if (isset($familyMap[$stg])) $family = $familyMap[$stg];
                    foreach (array_unique($family) as $uu) if ($uu !== '')
                        $model['unit_stop_time'][] = ['unit' => $uu, 'start' => $stopRow, 'stop' => 48];
                }
                // CONTINUITY: keep the anchor GTG loaded (cannot-stop) on every row before the requested
                // stop. pp_is_unit_stopped() (the stop window above) takes precedence and releases it at
                // stopRow, so it stays on [1..stopRow-1] then goes 0 from stopRow — no gap before handover.
                $cs = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
                if ($gtg !== '' && !in_array($gtg, $cs, true)) $cs[] = $gtg;
                $model['unit_cannot_stop'] = $cs;
                $ru = array_map('strtolower', (array)($model['required_units'] ?? []));
                if ($gtg !== '' && !in_array($gtg, $ru, true)) { $ru[] = $gtg; $model['required_units'] = $ru; }
                $entry['stop_other'] = $to; $entry['stop_row'] = $stopRow;
            } else {
                // Based on simulation: to GUARANTEE continuity (never both blocks off), the anchor block is
                // kept running for the whole day (Continuous Running). "Stop based on simulation" then means
                // the optimizer may keep it to end-of-day; a mid-day handover is only allowed when a request
                // time is given. This is the safe, constraint-first reading of change-over continuity.
                $cs = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
                foreach ([$gtg, $stg] as $uu) { if ($uu !== '' && !in_array($uu, $cs, true)) $cs[] = $uu; }
                $model['unit_cannot_stop'] = $cs;
                $ru = array_map('strtolower', (array)($model['required_units'] ?? []));
                if ($gtg !== '' && !in_array($gtg, $ru, true)) { $ru[] = $gtg; $model['required_units'] = $ru; }
                $entry['stop_other'] = 'sim';
            }
        }
        $coNorm['blocks'][$bn] = $entry;
    }
    $model['co_norm'] = $coNorm;
    if (!$anchorRunning) {
        // No block Required -> no anchor -> Change Over cannot be satisfied. Hard evidence for validation.
        $model['co_no_anchor'] = true;
        $model['__co_warn'] = 'Change Over Block 1-2 INFEASIBLE: no anchor (kedua blok Last Status = Stop) — no feasible replacement; kontinuitas blok tidak dapat dijamin dan unit required blok cannot start selama tidak ada anchor. Set salah satu blok Required/Running.';
    }
}
}

/* KETENTUAN STARTUP STG DAN ADDITIONAL HRSG — PEMILIH SEQUENCE TUNGGAL.
 *   STG blok masih 0 MW (fresh)  -> WAJIB STG Startup (pp_startup_caps: Cold/Warm/Hot)
 *   STG blok sudah berbeban      -> Additional HRSG (G1-6 [5,15] · G8/9 [40,40,50,60,60,70])
 * Sebelumnya tiap pass men-hardcode urutan Additional HRSG tanpa cek status STG, sehingga
 * Change Over ke blok fresh memakai ramp Additional HRSG (salah). */
function pp_start_sequence(array $d3, array $model, string $u, array $genRows, int $startRow0): array {
    $u = strtolower($u);
    /* ===== SHARED STARTUP CONTEXT (KUNCI DOMAIN §1/§2) =========================================
     * Klasifikasi startup DIBEKUKAN saat pertama kali dihitung untuk pasangan (unit, start row)
     * dalam satu run. Tanpa ini, konteks dapat BERUBAH di tengah pipeline (mis. STG sempat berbeban
     * saat sequence ditulis lalu menjadi 0 pada state final), sehingga optimizer menulis [5,15]
     * tetapi validator menilai memakai [5] — kontradiksi yang tidak boleh terjadi. */
    $__k = $u . '|' . $startRow0;
    if (isset($GLOBALS['__pp_seq_ctx'][$__k])) return (array)$GLOBALS['__pp_seq_ctx'][$__k];
    $addl = in_array($u, ['g8','g9'], true) ? [40.0,40.0,50.0,60.0,60.0,70.0]
          : (in_array($u, ['g1','g2','g3','g4','g5','g6'], true) ? [5.0,15.0] : []);
    $stg = '';
    foreach (['s1','s2','s3'] as $sC) {
        if (!isset($d3[$sC])) continue;
        $fdC = array_map('strtolower', array_values($d3[$sC]['hrsg'] ?? ($d3[$sC]['gtg'] ?? [])));
        if (in_array($u, $fdC, true)) { $stg = $sC; break; }
    }
    if ($stg === '') return $GLOBALS['__pp_seq_ctx'][$__k] = $addl;   // G7/G10: simple cycle, tanpa HRSG
    if (strtolower((string)($model['unit_last_data_status'][strtoupper($stg)] ?? '')) === 'running')
        return $GLOBALS['__pp_seq_ctx'][$__k] = $addl;                // ADDITIONAL HRSG -> SELALU [5,15]
    /* PATCH A06c — probe "STG sudah berbeban" WAJIB melihat row SEBELUM unit start ($rC < $startRow0),
     * bukan termasuk row start-nya. Menyertakan row start membuat helper tertipu nilai TRANSIEN: saat
     * unit menyusul masuk ke blok yang window STG-startup-nya masih berjalan (mis. G1 join di row 16
     * sementara G2/G5 meng-anchor blok di row 14), calc_stg sempat menghitung S2 > 0 pada row start
     * karena __stg_hold baru di-apply di akhir pipeline -> blok fresh salah divonis Additional HRSG,
     * caps [5,15] bukan [5,15,20,20,20,20,20], window beku terlalu pendek, sisa row dinaikkan pass hilir.
     * Semantik ini kini identik dengan aturan otoritatif pp_apply_startup_constraints ($prevStg = row r-1).
     * Untuk $startRow0 = 0, "sebelum row 0" = kemarin -> sudah ditangani cek unit_last_data_status di atas. */
    $prevRowC = $startRow0 - 1;
    if ($prevRowC >= 0 && $prevRowC < count($genRows)
        && (float)($genRows[$prevRowC][$stg] ?? 0) > 0.01)
        return $GLOBALS['__pp_seq_ctx'][$__k] = $addl;                // HANYA previous-row STG > 0 -> Additional HRSG
    $mdC = strtolower((string)($model['stg_startup_mode'][$stg] ?? ($model['stg_startup_mode'][$stg . '_startup'] ?? 'warm')));
    $cpC = pp_startup_caps($stg, $mdC);
    return $GLOBALS['__pp_seq_ctx'][$__k] = ($cpC ?: $addl);          // STG fresh -> STG Startup sequence
}

function pp_startup_caps(string $stg, string $mode): array {
    $mode = strtolower(trim($mode));
    // "No Start Up Sequence": no ramp caps at all — STG/unit load is taken directly
    // (STG computed from GTG load via calc_stg). (Revisi STG Start Up Mode Sec.3.2)
    if ($mode === 'none' || $mode === 'no start up sequence' || $mode === 'no startup') return [];
    if ($stg === 's3') {                              // STG3 / HRSG 8-9 (per KETENTUAN file)
        if ($mode === 'cold') return [40, 40, 40, 50, 50, 60, 60, 60, 60, 60, 70, 80];
        if ($mode === 'warm') return [50, 50, 60, 60, 70];
        return [60];                                  // hot
    }
    if ($stg === 's1' || $stg === 's2') {             // STG1 / STG2 / HRSG 1-6 (per KETENTUAN file)
        if ($mode === 'cold') return [5, 15, 20, 20, 20, 20, 20];
        if ($mode === 'warm') return [5, 15];
        return [5];                                   // hot
    }
    return [5];                                       // SC-only GTG (g7/g10): single startup step
}

/* GTG -> the STG it feeds (or '' if it has no HRSG, e.g. g7/g10). */
function pp_gtg_to_stg(array $d3, string $gtg): string {
    foreach (['s1', 's2', 's3'] as $s) {
        foreach (($d3[$s]['hrsg'] ?? []) as $h => $g) if ($g === $gtg) return $s;
    }
    return '';
}

/* Recompute s1/s2/s3 output from their feeding GTG loads + HRSG status,
 * used after a startup correction changes a feeding GTG load. */
/* Signature model yang dipakai memo pp_recompute_stgs (dipisah agar V6 dapat mengenali masa hidup memo itu;
 * logika dan cache per kombinasi count identik dengan versi sebelumnya). */
function pp_stg_model_sig(array $model): string {
    static $sigByCounts = [];
    $c1 = count((array)($model['unit_stop_time'] ?? []));
    $c2 = count((array)($model['hrsg_stop'] ?? []));
    $c3 = count((array)($model['unit_stop'] ?? []));
    $c4 = count((array)($model['__stg_hold'] ?? []));
    $modelSig = $sigByCounts[$c1][$c2][$c3][$c4] ?? null;
    if ($modelSig === null) {
        $modelSig = md5(json_encode([
            $model['unit_stop_time'] ?? null, $model['hrsg_stop'] ?? null,
            $model['unit_stop'] ?? null, $model['__stg_hold'] ?? null,
            $model['change_over'] ?? null, $model['co_resolved_timeline'] ?? null,
        ]));
        $sigByCounts[$c1][$c2][$c3][$c4] = $modelSig;
    }
    return $modelSig;
}
/* V6: id masa hidup memo STG yang SEDANG berlaku untuk $model, atau null bila panggilan STG berikutnya dengan
 * model ini akan mengosongkan memo (nilai yang dimemo di luar masa hidup yang sama tidak boleh dipakai ulang). */
function pp_v6_stg_life(array $model): ?int {
    $L = $GLOBALS['ppV6StgLife'] ?? null; if (!is_array($L)) return null;
    $cur = ($GLOBALS['__stg_memo_sig'] ?? null) . '|' . pp_stg_model_sig($model);
    return ($L['sig'] === $cur && empty($GLOBALS['__ppx_stg_memo_freeze'])) ? (int)$L['epoch'] : null;
}
function pp_v6_stg_rec_start(): void { $g = []; $c = ['cmd' => 'rec_start']; pp_recompute_stgs($g, [], [], 0, $c); }
function pp_v6_stg_rec_stop(): array { $g = []; $c = ['cmd' => 'rec_stop']; pp_recompute_stgs($g, [], [], 0, $c); return [$c['touch'] ?? [], !empty($c['bad'])]; }
function pp_v6_stg_recording(): bool { $g = []; $c = ['cmd' => 'rec_active']; pp_recompute_stgs($g, [], [], 0, $c); return !empty($c['active']); }
function pp_v6_stg_replay(array $d3, array $model, array $touch): bool { $g = []; $c = ['cmd' => 'replay', 'touch' => $touch]; pp_recompute_stgs($g, $d3, $model, 1, $c); return !empty($c['ok']); }
function pp_recompute_stgs(array &$gen, array $d3, array $model, int $row1, ?array &$v6 = null): void {
    /* V6 REKAM/PUTAR-ULANG: perintah tanpa perhitungan STG. 'rec_start'/'rec_stop' mencatat setiap sentuhan memo
     * (hit: kunci+nilai; baru: kunci+nilai yang dimasukkan). 'replay' memverifikasi sentuhan hit masih bernilai
     * sama dan sentuhan baru masih kosong, lalu memasukkan entri baru — setara persis dengan menjalankan ulang
     * perhitungan yang direkam; gagal verifikasi = tidak ada yang berubah (dibatalkan). */
    static $recStack = []; static $recBad = [];
    if ($v6 !== null && isset($v6['cmd'])) {
        if ($v6['cmd'] === 'rec_start') { $recStack[] = []; $recBad[] = false; return; }
        if ($v6['cmd'] === 'rec_active') { $v6['active'] = (bool)$recStack; return; }
        if ($v6['cmd'] === 'rec_stop') { $t = array_pop($recStack); $b = array_pop($recBad); $v6['touch'] = $t; $v6['bad'] = $b;
            if ($recStack) { $top = count($recStack) - 1; foreach ($t as $e) $recStack[$top][] = $e; if ($b) $recBad[$top] = true; }
            return; }
    }
    /* FIX TIMEOUT (worker_functions.php:1327 / calc_stg) — ROOT CAUSE bukan baris itu sendiri.
     * Profiling cold-cache pada exact input Change Over Block 2 -> Block 1 mencatat
     * 8.033.266 pemanggilan pp_recompute_stgs dalam satu run (mayoritas dari closure di dalam
     * pp_shape_final_dispatch), sehingga calc_stg menjadi hot leaf dan run menembus 120 s.
     *
     * pp_recompute_stgs bersifat DETERMINISTIK terhadap (row1, beban feeder GTG, cap __stg_hold),
     * dan pass-pass di atasnya memanggilnya berulang pada state yang SAMA. Karena itu hasilnya
     * dimemoisasi per state. Key dibangun dari nilai yang benar-benar memengaruhi output saja —
     * bukan hash seluruh $gen — agar biaya key jauh di bawah biaya calc_stg.
     * Memo di-reset per proses lewat $GLOBALS['__stg_memo_gen'] bila $d3/$model berganti. */
    static $memo = [];
    static $sig  = null;
    /* FIX STALE MEMO: hasil pp_recompute_stgs juga bergantung pada pp_is_hrsg_stopped($model,...)
     * dan jadwal stop unit — nilai yang TIDAK tertangkap key per-row. Bila $model berubah
     * (mis. antar kandidat sweep yang menulis unit_stop_time berbeda), memo lama menjadi STALE
     * dan run berulang menghasilkan dispatch berbeda. Signature dihitung dari bagian $model yang
     * memengaruhi hasil; biayanya sekali per perubahan model, bukan per panggilan. */
    $curSig = $GLOBALS['__stg_memo_sig'] ?? null;
    /* Fingerprint MURAH: md5(json_encode(...)) terlalu mahal pada 8 juta panggilan (+22 s).
     * Mutasi $model yang relevan selalu berupa PENAMBAHAN entri jadwal stop, sehingga jumlah
     * elemen cukup sebagai deteksi perubahan; md5 penuh hanya dihitung saat fingerprint bergeser. */
    /* E01: fingerprint tetap memakai EMPAT COUNT yang sama persis sebagai detektor perubahan —
     * kontraknya tidak berubah. Yang dihilangkan hanyalah pembangunan STRING pada setiap
     * panggilan (3,6 juta kali): keempat count dipakai langsung sebagai kunci array bersarang,
     * sehingga md5 tetap dihitung sekali per kombinasi count yang sama seperti sebelumnya. */
    $curSig = $curSig . '|' . pp_stg_model_sig($model);
    /* OPT-R1 — per row, input terakhir dan hasilnya: bila $gen identik (===) dengan pemanggilan
     * terakhir untuk row ini pada signature dan hold yang sama, kunci memo pasti sama dan hasilnya
     * sama persis; hasil disalin tanpa membangun kunci. */
    static $lastIn = []; static $lastOut = [];
    if ($curSig !== $sig) { $memo = []; $sig = $curSig; $lastIn = []; $lastOut = [];
        $GLOBALS['ppV6StgLife'] = ['sig' => $curSig, 'epoch' => (int)($GLOBALS['ppV6StgLife']['epoch'] ?? 0) + 1];
        }
    if ($v6 !== null && ($v6['cmd'] ?? null) === 'replay') {
        $ok = !empty($GLOBALS['__ppx_stg_memo_freeze']) ? false : true; $added = [];
        if ($ok) foreach ((array)$v6['touch'] as $e) {
            if ($e[1] === 'h') { if (!isset($memo[$e[0]]) || $memo[$e[0]] !== $e[2]) { $ok = false; break; } }
            else { if (isset($memo[$e[0]]) || count($memo) >= 200000) { $ok = false; break; } $memo[$e[0]] = $e[2]; $added[] = $e[0]; }
        }
        if (!$ok) { foreach ($added as $k0) unset($memo[$k0]); }
        elseif ($recStack) { $top = count($recStack) - 1; foreach ((array)$v6['touch'] as $e) $recStack[$top][] = $e; }
        $v6['ok'] = $ok; return;
    }
    $holdR = $model['__stg_hold'] ?? null;
    $hk = $holdR === null ? null : [$holdR['s1'][$row1] ?? null, $holdR['s2'][$row1] ?? null, $holdR['s3'][$row1] ?? null];
    if (!$recStack && isset($lastIn[$row1]) && $lastIn[$row1][1] === $hk && $lastIn[$row1][0] === $gen) {
        foreach ($lastOut[$row1] as $s => $v) $gen[$s] = $v;
        return;
    }
    $genIn = $gen;
    /* Daftar feeder per STG dibangun SEKALI per signature, bukan tiap panggilan: pembacaan
     * $d3[$s]['hrsg'] berulang adalah bagian besar dari biaya key pada 8 juta pemanggilan. */
    static $feeders = null;
    if ($feeders === null || $curSig !== $sig) {
        $feeders = [];
        foreach (['s1', 's2', 's3'] as $s) {
            if (!isset($d3[$s])) { $feeders[$s] = null; continue; }
            $feeders[$s] = array_values((array)($d3[$s]['hrsg'] ?? []));
        }
    }
    /* E01: string kunci yang DIHASILKAN identik byte-per-byte dengan versi concat berantai
     * ($row1 . '|' . a . '|' . b ...), tetapi dibangun sekali lewat implode() alih-alih ~10
     * konkatenasi berurutan. Diukur 1,49x lebih murah pada micro-benchmark. */
    $hold = $model['__stg_hold'] ?? null;
    $kp = [$row1];
    foreach ($feeders as $s => $fl) {
        if ($fl === null) { $kp[] = 'x'; continue; }
        foreach ($fl as $g) $kp[] = (int)(((float)($gen[$g] ?? 0)) * 100);
        $kp[] = isset($hold[$s][$row1]) ? (int)(((float)$hold[$s][$row1]) * 100) : 'n';
    }
    $key = implode('|', $kp);
    if (isset($memo[$key])) {
        foreach ($memo[$key] as $s => $v) $gen[$s] = $v;
        $lastIn[$row1] = [$genIn, $hk]; $lastOut[$row1] = $memo[$key];
        if ($recStack) $recStack[count($recStack) - 1][] = [$key, 'h', $memo[$key]];
        return;
    }
    foreach (['s1', 's2', 's3'] as $s) {
        if (!isset($d3[$s])) continue;
        /* FIX PRESENCE STG: sebelumnya hanya isset() yang diperiksa, sehingga STG dengan
         * unit_present = 0 tetap memperoleh output ketika GTG blok-nya menyala (mis. S1 berjalan
         * 17 row padahal present = 0 saat G5 di-stop). Presence adalah kontrak operator dan wajib
         * dihormati di jalur STG seperti di jalur GTG. */
        if (!pp_unit_present($d3, $s)) { $gen[$s] = 0.0; continue; }
        $feed = [];
        foreach (($d3[$s]['hrsg'] ?? []) as $h => $g) {
            $load = $gen[$g] ?? 0;
            // A GTG still in its startup ramp (below min combined-cycle load, e.g. 5/15 MW)
            // contributes 0 to the STG — calc_stg must not take it as a valid input (Sec.5.1/12).
            $mcc  = $d3[$g]['min_ccload'] ?? null;
            if ($mcc !== null && $load > 0 && $load < $mcc - 1e-6) continue;
            if ($load > 0 && !pp_is_hrsg_stopped($model, $h, $row1)) $feed[$g] = $load;   // PROMPT ISOLASI: feed BER-NAMA -> koefisien per-kombo
        }
        $gen[$s] = $feed ? calc_stg($d3, $s, $feed) : 0.0;
        /* ADDENDUM FINAL Bagian 6: STG start-up delay window (Cold/Warm/Hot). Selama window start
         * blok fresh, STG DITAHAN 0 walau GTG sudah >= min-CC, dan pada row kemunculan pertama
         * (Cold S1/S2) di-cap ke nilai awal 20 MW. Map dihitung sekali oleh final shaper dan dibaca
         * di sini agar SEMUA pass yang me-recompute STG otomatis menghormatinya. */
        if (isset($model['__stg_hold'][$s][$row1])) $gen[$s] = min($gen[$s], (float)$model['__stg_hold'][$s][$row1]);
    }
    $store = [];
    foreach (['s1', 's2', 's3'] as $s) if (array_key_exists($s, $gen)) $store[$s] = $gen[$s];
    /* Evaluasi probe (lihat pp_sup_flat_lookahead) hanya MEMBACA memo: isi memo yang dilihat
     * evaluasi berikutnya tetap sama seperti tanpa probe. */
    if (!empty($GLOBALS['__ppx_stg_memo_freeze'])) { if ($recStack) $recBad[count($recBad) - 1] = true; return; }
    if (count($memo) < 200000) { $memo[$key] = $store; if ($recStack) $recStack[count($recStack) - 1][] = [$key, 'n', $store]; }
    elseif ($recStack) $recBad[count($recBad) - 1] = true;
    $lastIn[$row1] = [$genIn, $hk]; $lastOut[$row1] = $store;
}

/* Enforce start-up sequence on the resolved 48-row dispatch.
 * Mutates $genRows (0-based row -> gen array). Returns warnings + per-unit state. */
/* ============================================================================================
 * pp_balance_g89() — BAGIAN A: pembagian load G8/G9 (BALANCED default, gap normal <= 25 MW).
 *
 * TEMUAN KALIBRASI (menentukan bentuk implementasi ini):
 *   d3.g8 / d3.g9  = { x2_f1: 0, x1_f1: 0.008, x0_f1: 0.4026, xcf: 1.015 }  -> IDENTIK, dan x2 = 0
 *   => fuel(load) LINEAR, tanpa suku kuadratik dan tanpa segmen kedua (tidak ada xsp_f2), sehingga
 *      fuel_total = xcf * (0.008*(G8+G9) + 2*0.4026)              -> HANYA fungsi JUMLAH
 *   d3.s3 dua-feeder = s1_f1u2_g8g9*(G8+G9) + s0_f1u2_g8g9        -> HANYA fungsi JUMLAH
 *   => Pada JUMLAH Block-3 yang tetap, split G8/G9 TIDAK dapat mengubah fuel, gas, S3, Export,
 *      Bus Flow, maupun Total Cost. Seluruh candidate (§A4) SELALU seri persis.
 *      Konsekuensi §A2.3 ("jika split tak seimbang terbukti lebih murah"): pada kalibrasi ini tidak
 *      pernah terbukti — tidak ada gradien biaya. Maka pemilihan jatuh ke §A2.1 (balanced default)
 *      dan §A2.7 (seri -> G9 sedikit lebih tinggi), dengan §A2.4 (gap <= 25) sebagai hard reject.
 *
 * KARENA ITU pass ini menjaga JUMLAH G8+G9 per row TETAP -> transformasi terbukti NETRAL:
 * Export, Bus Flow, gas, dan cost tidak bergerak sama sekali; hanya pembagian antar unit berubah.
 * Ramp juga aman secara matematis: rata-rata dua deret yang patuh ramp tetap patuh ramp
 * ( |d(sum/2)| = |dG8 + dG9|/2 <= (30+30)/2 = 30 ), dan tiap row tetap di-clamp eksplisit ke +-30.
 *
 * PENGECUALIAN §A3 (row DILEWATI, gap > 25 dibiarkan sah): salah satu unit off / di dalam window
 * startup / Stop Schedule / Fixed Load / row locked TIME PASSED. Max Load Adjustment asimetris tidak
 * dilewati begitu saja melainkan dihormati lewat cap per-unit (hasilnya boleh gap > 25 bila cap memaksa).
 *
 * @return array<int,string> evidence per row yang diseimbangkan
 * ============================================================================================ */
function pp_balance_g89(array &$genRows, array $d3, array $model, array $actualRows = [], array $suWin = []): array {
    $n = count($genRows);
    if ($n === 0 || !pp_unit_present($d3, 'g8') || !pp_unit_present($d3, 'g9')) return [];
    $GAPMAX = 25.0;                                  // §A2.4 batas selisih normal
    $RAMP   = 30.0;                                  // G8/G9 Combined Cycle <= 30 MW/30min (validator 8e)
    $min8 = (float)($d3['g8']['min_ccload'] ?? 65); $min9 = (float)($d3['g9']['min_ccload'] ?? 65);
    $ev = [];
    for ($r = 0; $r < $n; $r++) {
        $a = (float)($genRows[$r]['g8'] ?? 0); $b = (float)($genRows[$r]['g9'] ?? 0);
        if ($a < 0.01 || $b < 0.01) continue;                                   // §A3: salah satu off
        if (isset($actualRows[$r])) continue;                                   // TIME PASSED locked
        if (isset($suWin['g8'][$r]) || isset($suWin['g9'][$r])) continue;       // §A3: window startup
        if (pp_is_unit_stopped($d3, $model, 'g8', $r + 1) || pp_is_unit_stopped($d3, $model, 'g9', $r + 1)) continue;   // §A3
        if (pp_get_fixed_load($model, 'g8', $r + 1) >= 0 || pp_get_fixed_load($model, 'g9', $r + 1) >= 0) continue;     // §A3
        /* §1.1/§1.7 BALANCED DEFAULT: bila G8/G9 cost-neutral (kurva linear-identik), tarik ke
         * gap≈0 — BUKAN sekadar gap<=25. Skip hanya bila SUDAH balanced (gap<=BALTOL) & G9>=G8,
         * atau bila split ke balanced tak feasible (ditangani jendela lo..hi di bawah). Bila kurva
         * G8/G9 BERBEDA (bukan cost-neutral), pertahankan perilaku gap<=25 lama (candidate search
         * cost akan menentukan; di luar cakupan pass netral ini). */
        $BALTOL = 0.5;
        $curveIdentical = (($d3['g8']['x0_f1'] ?? null) === ($d3['g9']['x0_f1'] ?? null)
                        && ($d3['g8']['x1_f1'] ?? null) === ($d3['g9']['x1_f1'] ?? null)
                        && ($d3['g8']['x2_f1'] ?? 0)    === ($d3['g9']['x2_f1'] ?? 0)
                        && ($d3['g8']['xcf'] ?? 1)      === ($d3['g9']['xcf'] ?? 1));
        if ($curveIdentical) {
            if (abs($b - $a) <= $BALTOL + 1e-9) continue;                       // sudah balanced (cost-neutral)
        } else {
            if (abs($b - $a) <= $GAPMAX + 1e-9 && $b >= $a - 1e-9) continue;    // kurva beda: gap<=25 lama
        }

        $sum = $a + $b;
        $em8 = pp_effective_maxload($d3, $model, 'g8', $r + 1); if ($em8 <= 0) $em8 = (float)($d3['g8']['max_load'] ?? 108);
        $em9 = pp_effective_maxload($d3, $model, 'g9', $r + 1); if ($em9 <= 0) $em9 = (float)($d3['g9']['max_load'] ?? 108);
        /* jendela feasible utk G9 (G8 = sum - G9), memakai nilai row sebelumnya yang SUDAH diseimbangkan */
        $lo = max($min9, $sum - $em8);
        $hi = min($em9, $sum - $min8);
        if ($r > 0) {
            $p8 = (float)($genRows[$r-1]['g8'] ?? 0); $p9 = (float)($genRows[$r-1]['g9'] ?? 0);
            if ($p9 > 0.01) { $lo = max($lo, $p9 - $RAMP); $hi = min($hi, $p9 + $RAMP); }
            if ($p8 > 0.01) { $lo = max($lo, $sum - $p8 - $RAMP); $hi = min($hi, $sum - $p8 + $RAMP); }
        }
        if ($lo > $hi + 1e-9) continue;                                          // §A3: hard constraint mengunci
        /* §A2.1 balanced default + §A2.7 seri -> G9 sedikit lebih tinggi: ambil G9 se-dekat mungkin ke
         * sum/2 dari sisi ATAS (G9 >= G8), lalu batasi §A2.4 gap <= 25. Semua candidate seri biaya. */
        $target = max($lo, $sum / 2.0);
        $target = min($target, $hi, ($sum + $GAPMAX) / 2.0);
        if ($target < $lo - 1e-9) $target = $lo;                                 // ramp memaksa G8 > G9 (§A3)
        /* G8 = komplemen EKSAK dari G9 (JANGAN dibulatkan terpisah): membulatkan kedua sisi masing-masing
         * ke 4 desimal menggeser jumlah ~3e-5 dan merusak netralitas yang menjadi dasar pass ini. */
        $g9n = round($target, 4); $g8n = $sum - $g9n;
        if ($g8n < $min8 - 1e-6 || $g8n > $em8 + 1e-6 || $g9n < $min9 - 1e-6 || $g9n > $em9 + 1e-6) continue;
        if (abs(($g8n + $g9n) - $sum) > 1e-9) continue;                          // jumlah WAJIB tetap (netralitas)
        /* §10 EVIDENCE COST: bandingkan Total Fuel candidate BALANCED (sum/2 masing-masing, bila
         * feasible) vs candidate TERPILIH. Karena kurva G8/G9 linear-identik, Σ fuel utk sum tetap
         * bersifat cost-neutral: balanced_fuel == selected_fuel. Saving 0 -> balanced default menang
         * (§A2.1/§1.7). Nilai dilaporkan sebelum pembulatan sebagai bukti. */
        $priceGas = (float)($model['price']['gas'] ?? 0);
        $fuelSel  = calc_fuel($d3, 'g8', $g8n) + calc_fuel($d3, 'g9', $g9n);
        $balHalf  = $sum / 2.0;
        $balFeasible = ($balHalf >= $min8 - 1e-9 && $balHalf <= $em8 + 1e-9 && $balHalf >= $min9 - 1e-9 && $balHalf <= $em9 + 1e-9
                        && $balHalf >= $lo - 1e-9 && $balHalf <= $hi + 1e-9);
        $fuelBal  = $balFeasible ? (calc_fuel($d3, 'g8', $balHalf) + calc_fuel($d3, 'g9', $balHalf)) : $fuelSel;
        $costBal  = $fuelBal * 1e6 * $priceGas;    // USD/day (fuel BBTUD/jam * 24 * 1000 MMBTU * price ... skala relatif)
        $costSel  = $fuelSel * 1e6 * $priceGas;
        $saving   = $costBal - $costSel;           // > 0 berarti selected lebih murah dari balanced
        $genRows[$r]['g8'] = $g8n; $genRows[$r]['g9'] = $g9n;
        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
        $ev[$r] = sprintf('row %d: G9/G8 %.1f/%.1f (gap %.1f) -> %.1f/%.1f (gap %.1f), sum %.1f tetap | fuel_balanced=%.6f fuel_selected=%.6f saving=%.6f BBTUD (cost-neutral: linear-identik) -> balanced default',
            $r + 1, $b, $a, abs($b - $a), $g9n, $g8n, abs($g9n - $g8n), $sum, $fuelBal, $fuelSel, $fuelBal - $fuelSel);
    }
    return $ev;
}

function pp_apply_startup_constraints(array &$genRows, array $d3, array $model, array $actualRows = []): array {
    /* PROMPT MONITORING §8.2: row Actual/locked (TIME PASSED = Y) adalah history — enforcement
     * startup/runtime TIDAK boleh mengubahnya. Snapshot di awal -> enforcement berjalan normal
     * untuk future rows -> row actual di-restore persis sebelum return (nilai locked menang). */
    $ppActSnap = [];
    foreach ($actualRows as $ppRi => $ppUnused) if (isset($genRows[$ppRi])) $ppActSnap[$ppRi] = $genRows[$ppRi];
    $warnings = [];
    $states   = [];
    $nRows    = count($genRows);
    $gtgs     = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $smode    = $model['stg_startup_mode'] ?? [];
    $minRun   = fn($u) => (float)($d3[$u]['min_scload'] ?? 5);

    foreach ($gtgs as $u) {
        if (!isset($d3[$u])) continue;
        $stg  = pp_gtg_to_stg($d3, $u);
        $mode = $stg ? (string)($smode[$stg . '_startup'] ?? 'Cold') : 'Hot';
        $caps = pp_startup_caps($stg ?: $u, $mode);
        $mr   = $minRun($u);

        /* (1) Fix rapid ON -> OFF -> ON (Sec.7.5 / 7.7). The middle 0 is invalid
         *     unless it is an explicit unit stop / trip. Hold the unit online. */
        for ($r = 0; $r + 2 < $nRows; $r++) {
            $a = $genRows[$r][$u]   ?? 0;
            $b = $genRows[$r+1][$u] ?? 0;
            $c = $genRows[$r+2][$u] ?? 0;
            if ($a >= $mr && $b < 1 && $c >= $mr
                && !pp_is_unit_stopped($d3, $model, $u, $r + 2)) {
                $fill = max($mr, min($a, $c));
                $genRows[$r+1][$u] = $fill;
                pp_recompute_stgs($genRows[$r+1], $d3, $model, $r + 2);
                $warnings[] = sprintf(
                    'Invalid startup pattern fixed: %s %.0f MW -> 0 MW -> %.0f MW at rows %d-%d; held at %.0f MW to honour STG/HRSG sequence.',
                    strtoupper($u), $a, $c, $r + 1, $r + 3, $fill);
            }
        }

        /* (2) Apply start-up ramp caps on every genuine OFF -> ON transition
         *     (Sec.7.1 - 7.4): the unit may not jump straight to high load.
         *     Additional HRSG (Sec.8): if the block STG was already loaded on the
         *     previous row and another block GTG was running, the starting GTG
         *     follows the SHORT additional-HRSG ramp instead of the full STG
         *     startup sequence — and the STG must not jump (recomputed via calc_stg). */
        $blockGtgs = [];
        if ($stg) foreach ($gtgs as $og) if ($og !== $u && pp_gtg_to_stg($d3, $og) === $stg) $blockGtgs[] = $og;
        for ($r = 1; $r < $nRows; $r++) {
            $prev = $genRows[$r-1][$u] ?? 0;
            $cur  = $genRows[$r][$u]   ?? 0;
            if ($prev < 1 && $cur >= 1) {
                if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
                $addlHrsg = false;
                if ($stg) {
                    $prevStg = $genRows[$r-1][$stg] ?? 0;
                    $otherRun = false;
                    foreach ($blockGtgs as $og) if (($genRows[$r-1][$og] ?? 0) >= 1) { $otherRun = true; break; }
                    $addlHrsg = ($prevStg > 0 && $otherRun);
                }
                $useCaps = $addlHrsg ? pp_additional_hrsg_caps($u) : $caps;
                $k = 0;
                for ($s = $r; $s < $nRows && $k < count($useCaps); $s++) {
                    if (($genRows[$s][$u] ?? 0) < 1) break;            // sequence ended
                    if (pp_is_unit_stopped($d3, $model, $u, $s + 1)) break;
                    $cap = $useCaps[$k];
                    if (($genRows[$s][$u] ?? 0) > $cap + 1e-6) {
                        $genRows[$s][$u] = $cap;
                        pp_recompute_stgs($genRows[$s], $d3, $model, $s + 1);
                    }
                    $k++;
                }
            }
        }

        /* (2b) Combined-cycle minimum-load floor is applied AFTER runtime
         *      enforcement (step 4b) so nothing re-lowers it. */

        /* (3) per-row state labels are computed after runtime enforcement. */
    }

    /* (4) Minimum runtime / downtime enforcement (Revisi Lanjutan Sec.1) ----- */
    $rt       = pp_apply_runtime_constraints($genRows, $d3, $model);
    $warnings = array_merge($warnings, $rt['warnings']);
    $locks    = $rt['locks'];

    /* (4b) Combined-cycle minimum-load floor (Revisi Sec.5.1-5.3), applied AFTER
     *      runtime enforcement so it is final. A GTG already running in combined
     *      cycle (previous row >= min_ccload and block STG loaded) must not be
     *      parked below min_ccload while still online — it must hold >= min CC load
     *      or be OFF, never at the 5 MW simple-cycle level. Genuine startups
     *      (ramping up from < min_ccload) and explicit stops are untouched. */
    foreach ($gtgs as $u) {
        if (!isset($d3[$u])) continue;
        $stg = pp_gtg_to_stg($d3, $u);
        if (!$stg) continue;                                  // SC-only (g7/g10): no CC floor
        $mcc = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 5));
        $fixCount = 0; $firstRow = 0;
        for ($r = 1; $r < $nRows; $r++) {
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
            $prev    = $genRows[$r-1][$u]   ?? 0;
            $cur     = $genRows[$r][$u]     ?? 0;
            $stgPrev = $genRows[$r-1][$stg] ?? 0;
            if ($prev >= $mcc - 1e-6 && $cur >= 1 && $cur < $mcc - 1e-6 && $stgPrev > 0) {
                $genRows[$r][$u] = $mcc;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $fixCount++; if (!$firstRow) $firstRow = $r + 1;
            }
        }
        if ($fixCount > 0) {
            $warnings[] = sprintf(
                'Invalid CC load drop fixed: %s held at min CC load %.0f MW for %d slot%s (from row %d) — a running combined-cycle unit may not be parked below min CC load.',
                strtoupper($u), $mcc, $fixCount, $fixCount > 1 ? 's' : '', $firstRow);
        }
    }

    /* (4c) HARD CONSTRAINT on 5/15 MW and low-load parking (Revisi). A CC-linked
     *      GTG (G1-G6, G8, G9) may only sit at 5/15 MW (or any value below
     *      min_ccload) while it is genuinely STARTING UP — i.e. inside an ON
     *      segment that actually progresses and REACHES valid combined-cycle
     *      operation (>= min_ccload). A segment whose entire run stays below
     *      min_ccload is fake low-load parking (e.g. G6 = 5 MW flat) and is
     *      rejected: the whole segment is turned OFF. Segments that reach
     *      min_ccload keep their progressing ramp cells (5 -> 15 -> 20 ...).
     *      Mid-run dips below min_ccload are handled by the CC floor (4b). */
    foreach ($gtgs as $u) {
        if (!isset($d3[$u])) continue;
        $stg = pp_gtg_to_stg($d3, $u);
        if (!$stg) continue;                                  // SC-only (g7/g10): no 5/15, skip
        $mode = (string)($smode[$stg . '_startup'] ?? 'Cold');
        $rampLen = max(1, count(pp_startup_caps($stg, $mode)) + 1);   // allowed rows to reach CC
        $mcc = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 5));
        $offSlots = 0; $firstRow = 0;
        foreach (pp_segments($genRows, $u) as $sg) {
            if (!$sg['on']) continue;
            $a = $sg['a']; $b = $sg['b'];
            $explicit = false; $reachRow = -1;
            for ($k = $a; $k < $b; $k++) {
                if (pp_is_unit_stopped($d3, $model, $u, $k + 1)) $explicit = true;
                if ($reachRow < 0 && (float)($genRows[$k][$u] ?? 0) >= $mcc - 1.0) $reachRow = $k;
            }
            if ($explicit) continue;                          // respect explicit user stop/fix windows
            // valid startup = reaches min_ccload within $rampLen rows of the segment start.
            $timely = ($reachRow >= 0 && ($reachRow - $a) <= $rampLen);
            if (!$timely) {                                   // fake low-load parking -> turn segment OFF
                for ($k = $a; $k < $b; $k++) {
                    if (($genRows[$k][$u] ?? 0) > 0) { $genRows[$k][$u] = 0.0; $offSlots++; if (!$firstRow) $firstRow = $k + 1; }
                    pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1);
                }
            }
        }
        if ($offSlots > 0) {
            $warnings[] = sprintf(
                'Invalid low-load parking resolved: %s turned OFF for %d slot%s (from row %d) — it did not reach min CC load %.0f MW promptly, so the 5/15 MW run was not a valid startup.',
                strtoupper($u), $offSlots, $offSlots > 1 ? 's' : '', $firstRow, $mcc);
        }
    }

    /* (4d) STG RELEASE-TIME GATEKEEPING for calc_stg (Revisi Sec.4-7). An STG may
     *      only be generated from a feeding GTG once that GTG has been running long
     *      enough since its start: the full STG startup window for a cold-block
     *      start, or the shorter HRSG delay for an additional-HRSG start. While a
     *      feeder is still inside its release window it does NOT contribute to the
     *      STG, so STG output stays 0 during the 5/15/20 (or 40/50/60) ramp. */
    foreach (['s1','s2','s3'] as $s) {
        if (!isset($d3[$s])) continue;
        $feeders = array_values($d3[$s]['hrsg'] ?? []);
        if (!$feeders) continue;
        $mode   = (string)($smode[$s . '_startup'] ?? 'Cold');
        $rel    = pp_stg_release_rows($s, $mode);
        $addRel = pp_additional_hrsg_release_rows($s);
        $age = []; $cold = [];                            // per-feeder age (rows since start) + start-type
        foreach ($feeders as $g) { $age[$g] = -1; $cold[$g] = false; }
        $gated = 0; $firstGate = 0;
        for ($r = 0; $r < $nRows; $r++) {
            $stgPrev = $r > 0 ? ($genRows[$r-1][$s] ?? 0) : 0;
            foreach ($feeders as $g) {
                $cur  = $genRows[$r][$g] ?? 0;
                $prev = $r > 0 ? ($genRows[$r-1][$g] ?? 0) : 0;
                if ($cur < 1) { $age[$g] = -1; continue; }
                if ($r === 0) { $age[$g] = max($rel, $addRel); $cold[$g] = false; continue; } // running at horizon start = already released
                if ($prev < 1) {                          // OFF -> ON
                    $allPrevOff = true;
                    foreach ($feeders as $gg) if (($genRows[$r-1][$gg] ?? 0) >= 1) { $allPrevOff = false; break; }
                    $cold[$g] = ($allPrevOff && $stgPrev < 1);   // cold-block start vs additional HRSG
                    $age[$g] = 0;
                } else {
                    $age[$g] = $age[$g] >= 0 ? $age[$g] + 1 : 0;
                }
            }
            // contributors = feeders past their release window and not HRSG-stopped
            $contrib = [];
            foreach ($feeders as $g) {
                if (($age[$g] ?? -1) < 0) continue;
                $req = $cold[$g] ? $rel : $addRel;
                if ($age[$g] < $req) continue;            // still in release window -> no STG contribution
                $h = array_search($g, $d3[$s]['hrsg'] ?? [], true);
                if ($h !== false && pp_is_hrsg_stopped($model, (string)$h, $r + 1)) continue;
                $contrib[$g] = $genRows[$r][$g] ?? 0;                     // PROMPT ISOLASI: feed BER-NAMA -> koefisien per-kombo
            }
            $newStg = $contrib ? calc_stg($d3, $s, $contrib) : 0.0;
            $oldStg = $genRows[$r][$s] ?? 0;
            if ($oldStg > 1e-9 && $newStg < $oldStg - 1e-6) { $gated++; if (!$firstGate) $firstGate = $r + 1; }
            $genRows[$r][$s] = $newStg;
        }
        if ($gated > 0) {
            $warnings[] = sprintf('Invalid STG generation prevented: %s held at 0 MW for %d slot%s (from row %d) until the GTG startup reached the STG release time.',
                strtoupper($s), $gated, $gated > 1 ? 's' : '', $firstGate);
        }
    }

    /* (5) Per-unit state labels on the final dispatch (Sec.7.6 / Sec.1.3) ---- */
    foreach (array_merge($gtgs, ['s1','s2','s3']) as $u) {
        if (!isset($d3[$u])) continue;
        $isStg = in_array($u, ['s1','s2','s3'], true);
        $stg   = $isStg ? '' : pp_gtg_to_stg($d3, $u);
        $mode  = (!$isStg && $stg) ? (string)($smode[$stg . '_startup'] ?? 'Cold') : 'Hot';
        $caps  = $isStg ? [] : pp_startup_caps($stg ?: $u, $mode);
        $st = []; $seqLeft = 0;
        for ($r = 0; $r < $nRows; $r++) {
            $cur  = $genRows[$r][$u] ?? 0;
            $prev = $r ? ($genRows[$r-1][$u] ?? 0) : $cur;
            if (!$isStg && pp_is_unit_stopped($d3, $model, $u, $r + 1)) { $st[$r] = 'FORCED_STOP'; $seqLeft = 0; }
            elseif ($cur < 1) { $st[$r] = 'OFF'; $seqLeft = 0; }
            else {
                if ($prev < 1) $seqLeft = count($caps);
                if ($seqLeft > 0) { $st[$r] = $isStg ? 'STARTING' : ($stg ? ('STARTING_STG_' . strtoupper($mode)) : 'STARTING_ADDITIONAL_HRSG'); $seqLeft--; }
                else $st[$r] = 'RUNNING';
            }
            if ($st[$r] === 'RUNNING' && isset($locks[$u][$r])) $st[$r] = $locks[$u][$r];
        }
        $states[$u] = $st;
    }
    /* PROMPT MONITORING §8.2: restore row Actual/locked — history tidak pernah diubah enforcement */
    foreach ($ppActSnap as $ppRi => $ppRow) $genRows[$ppRi] = $ppRow;
    return ['warnings' => $warnings, 'states' => $states];
}

/* Validation-only scan for any remaining rapid on/off/on pattern (Sec.7.7) plus
 * invalid CC load drop (Sec.7) and additional-HRSG-from-0 (Sec.7) detection. */
/* =========================================================================
 *  RELEASE GATE (MASTER FABLE5 — 100x validation integrated into the engine)
 *  -------------------------------------------------------------------------
 *  pp_validate_hard_constraints(): full hard-constraint validator that runs
 *  against ONE simulation output (input + output pair). This is a native PHP
 *  port of the same checks used by validate.py, kept in the engine itself so
 *  the release gate below does not depend on any external test harness.
 *
 *  pp_release_gate(): runs pp_run_simulation() up to $runs times against the
 *  SAME input, validates every run with pp_validate_hard_constraints(), and
 *  returns an aggregate verdict. release_allowed is true only if there is
 *  ZERO FAIL across all runs (VALID-INFEASIBLE runs are allowed through, they
 *  carry their own row-level evidence in the violations list).
 * ========================================================================= */
/* ===== SOURCE OF TRUTH — SEMANTIK KUOTA GAS (PROMPT FORCE G5/G9 §2) =============================
 * Lima konsep DIPISAH tegas:
 *   1. Maximum gas quota      = gas_quota.pgn_pipe / pep / dst  -> BATAS ATAS (available)
 *   2. Minimum contractual take = LNG (fixed contract; equality-nya divalidasi terpisah)
 *   3. Effective gas window   = [quota-0.04, quota] -> HANYA berlaku bila operator memilih
 *                               gas_shortage_action = 'force_same_as_quota' (opsi popup
 *                               "Force gas same as quota"). Bila tidak dipilih, kuota adalah
 *                               MAXIMUM AVAILABLE: used <= quota, TIDAK ada kewajiban menghabiskan.
 *   4. Minimum PGN realtime flow = min_pgn_flow (MMSCFD, per row) -> tetap hard constraint
 *   5. Fuel required for actual generation -> hasil dispatch, bukan target
 * ROOT CAUSE yang ditutup: lower-bound window dulu dipaksa TANPA SYARAT sehingga engine menyalakan
 * unit tambahan semata-mata untuk menghabiskan gas (start-to-burn). Itu salah bila kuota = maksimum. */
function pp_gas_must_take(array $model): bool {
    /* CATATAN: strict window [quota-0.04, quota] SELALU berlaku (hard constraint project).
     * Helper ini HANYA menandai STRATEGI 'force same as quota' yang dipilih operator —
     * dilarang dipakai untuk mematikan batas bawah/atas gas di validator atau pass manapun. */
    return (($model['gas_shortage_action'] ?? 'none') === 'force_same_as_quota');
}

/* Satu sumber kebenaran window gas (dipakai validator, wrapper, repair, decommit, harness). */
/* ===== SOURCE OF TRUTH — PRODUCTION ACCOUNTING (PROMPT HOUSELOAD §1/§2/§4) =====================
 * Definisi NYATA (ditelusuri dari row-level worker02 $realised_export):
 *     $jbbk = totalGen - babelan - houseload      -> kolom 'Jababeka' SUDAH NET
 *     $babelan = b1 + b2                          -> kolom 'BB_Total' (Babelan tanpa houseload:
 *                                                    calc_house_load() hanya bergantung GTG g1..g9)
 *     kolom 'HL' = houseload MW milik JBBK-MM
 * Karena itu Houseload SUDAH dikurangi satu kali di dalam JBBK MM PROD dan TIDAK boleh dikurangi
 * lagi pada level Summary. Energi per slot 30 menit = MW x 0.5 jam (faktor /2 diterapkan SEKALI
 * di sini; pemanggil TIDAK boleh membaginya lagi).
 * Kedua identitas wajib menghasilkan angka sama:
 *     total_net = total_gross - total_houseload
 *     total_net = jbbk_net + babelan_net                                                        */
function pp_production_accounting(array $rows): array {
    $jbbkNet = 0.0; $bbln = 0.0; $hl = 0.0;
    foreach ($rows as $r) {
        $jbbkNet += (float)($r['Jababeka'] ?? 0);
        $bbln    += (float)($r['BB_Total'] ?? 0);
        $hl      += (float)($r['HL'] ?? 0);
    }
    $jbbkNet /= 2.0; $bbln /= 2.0; $hl /= 2.0;          // MW -> MWh (slot 30 menit), SEKALI
    $jbbkGross = $jbbkNet + $hl;                        // houseload milik JBBK-MM
    return [
        'jbbk_gross'        => $jbbkGross,
        'jbbk_houseload'    => $hl,
        'jbbk_net'          => $jbbkNet,
        'babelan_gross'     => $bbln,
        'babelan_houseload' => 0.0,                     // model: Babelan tidak memikul houseload
        'babelan_net'       => $bbln,
        'total_gross'       => $jbbkGross + $bbln,
        'total_houseload'   => $hl,
        'total_net'         => $jbbkNet + $bbln,        // == total_gross - total_houseload
    ];
}

/* Cost Production = Total Production Cost / Final Net Production (presisi penuh; rounding hanya display). */
function pp_cost_production(float $totalCost, float $netMWh): float {
    return ($netMWh > 1e-9 && is_finite($netMWh) && is_finite($totalCost)) ? $totalCost / $netMWh : 0.0;
}

/* ===== EXPORT-RAMP FINAL REPAIR (post re-assertion) ============================================
 * Dipanggil worker02 SETELAH seluruh re-assertion (Required/fixed/min) — penulis pasca-shaper
 * itulah yang selama ini menciptakan ulang cliff (mis. bump G8 40 -> CC-min 65 di satu row).
 * Lever 100% gas-netral: (a) pasangan H/L unit sama (fuel-matched), (b) Babelan lintas-row,
 * (c) donor-row: turunkan unit u di row donor bermargin + naikkan u di sisi rendah.
 * Guard: min/max/ramp unit, ramp+fixed Babelan, suCap? (di sini pasca-startup: pakai floor min),
 * Export Range kedua row, must-improve gap terburuk, budget keras. */
/** Universal PLN Export deviation hard limit per 30-minute slot. */
function pp_export_step_limit($model = null): float { return 35.0; }

/* =============================================================================================
 *  D-1 — PERBAIKAN UNIT SKIP LOAD DENGAN REDISPATCH BERKOMPENSASI DAN ROLLBACK TRANSAKSIONAL
 *
 *  MASALAH (hasil audit per-pass, bukan dugaan). G8 dan G9 digerakkan sebagai satu level bersama.
 *  Jejak nilai G9 pada band terlarang 55-75 MW memperlihatkan pelanggaran diproduksi oleh BEBERAPA
 *  pass, bukan satu:
 *
 *      A_before_window        r1 g9=75,00  r6 g9=75,00  r7 g9=75,00     <- sudah di dalam band
 *      G_after_final_gascap   r1 73,30  r2 74,30  r5 74,50  r6 71,48  r7 70,78   <- kelima row
 *      N_after_overmax_trim   seluruh row g9=75,60                      <- keluar band (di atas)
 *      O_after_evidence       r1 74,60  r6 73,60  r7 73,60              <- masuk band lagi
 *
 *  Karena itu membuat satu pass "skip-aware" tidak pernah cukup: pass mana pun setelahnya dapat
 *  mengembalikan unit ke dalam band. Yang dibutuhkan adalah INVARIANT REPAIR di titik paling akhir,
 *  setelah fungsi terakhir yang dapat mengubah dispatch.
 *
 *  MENGAPA BUKAN SNAP. Menjepit beban keluar band tanpa kompensasi mengubah Export baris itu dan
 *  terbukti memindahkan pelanggaran: percobaan snap sederhana menghasilkan 12 pelanggaran Export
 *  Range. Memindahkan pelanggaran satu hard constraint ke constraint lain dilarang.
 *
 *  YANG DILAKUKAN. Untuk setiap (baris, unit) yang berada di dalam band:
 *    1. bangun kandidat nilai LEGAL terdekat — tepat di atas band, lalu tepat di bawah band —
 *       diurutkan dari yang paling sedikit mengganggu rencana;
 *    2. untuk tiap kandidat jalankan SATU TRANSAKSI:
 *         snapshot baris -> ubah unit -> recompute STG -> hitung selisih Export ->
 *         redispatch kompensasi pada unit lain di baris yang sama -> recompute STG ->
 *         validasi penuh baris itu DAN kedua tetangganya;
 *    3. commit hanya bila SELURUH constraint tetap valid; bila satu saja rusak, ROLLBACK penuh
 *       dan coba kandidat berikutnya;
 *    4. bila tidak ada kandidat yang lolos, baris itu dibiarkan apa adanya dan dicatat sebagai
 *       blocker spesifik — validator hard tetap melaporkannya sebagai pelanggaran `skip_load`,
 *       sehingga kegagalan tidak pernah lolos diam-diam (fail closed).
 *
 *  Unit yang dipakai sebagai kompensasi tidak boleh: terkunci Fixed Load, berada dalam stop window,
 *  masuk ke dalam band terlarangnya sendiri, melanggar min/max efektif, atau melanggar ramp unit
 *  terhadap baris tetangga.
 * =========================================================================================== */
/* Guard band validator Unit Skip Load: pp_validate_hard_constraints() memperlebar band nominal
 * 0,51 MW ke kedua sisi. Perbaikan memakai angka yang SAMA agar hasilnya benar-benar lolos. */
const PP_SKIP_LOAD_GUARD_MW = 0.51;
/* Anggaran total pergeseran gas yang diizinkan seluruh perbaikan band. Jauh di bawah lebar window
 * kuota (0,04 BBTUD) sehingga perbaikan tidak dapat memecahkan window gas. */
const PP_SKIP_LOAD_GAS_BUDGET_BBTUD = 0.012;
/* Pergeseran Export maksimum yang diizinkan satu perbaikan band, dalam MW. Nol berarti perbaikan
 * hanya boleh dilakukan bila Export dapat dipulihkan persis; nilai besar membuat rencana berubah
 * jauh dan runtime tidak stabil. 3,0 MW dipilih dari pengukuran: cukup untuk memindahkan unit
 * keluar band pada kasus nyata, tanpa mengguncang pass rekonsiliasi di hilir.
 *
 * PENGUKURAN. Dicoba 3,0 MW dan hasilnya lebih buruk: rencana berubah cukup jauh sehingga pass
 * rekonsiliasi engine bekerja lebih lama dan satu skenario menabrak plafon 60 detik, sementara
 * jumlah pelanggaran sisa tidak berkurang. Dipakai 0,5 MW: perbaikan hanya di-commit bila
 * kompensasi benar-benar memulihkan Export baris itu, sehingga rencana praktis tidak berubah di
 * luar unit yang dipindahkan keluar band. */
const PP_SKIP_LOAD_MAX_EXPORT_SHIFT_MW = 0.5;

function pp_skip_load_band_repair(array &$genRows, array $d3, array $model, array $ieVals,
                                  array $actualRows, $rMin, $rMax, array &$evidence,
                                  ?float $gasQuotaTotal = null): int {
    /* BATAS EXPORT PER BARIS. Range Export tidak seragam: `range_rules` membuat sebagian baris
     * memiliki Range Min yang berbeda (pada fixture ini baris 17-32 bernilai 25 sementara sisanya
     * 20). Memvalidasi seluruh baris terhadap SATU nilai skalar adalah cacat: perbaikan dapat
     * mendorong sebuah baris di bawah batas minimumnya yang sebenarnya dan menukar pelanggaran
     * Skip Load menjadi pelanggaran Export Range. Karena itu fungsi ini menerima batas per baris
     * (array) maupun skalar, dan selalu memakai batas milik baris yang sedang diperiksa. */
    $loAt = is_array($rMin)
        ? (fn(int $r): float => (float)($rMin[$r] ?? 0.0))
        : (fn(int $r): float => (float)$rMin);
    $hiAt = is_array($rMax)
        ? (fn(int $r): float => (float)($rMax[$r] ?? 0.0))
        : (fn(int $r): float => (float)$rMax);
    $n = count($genRows);
    if ($n === 0) return 0;
    /* Tidak ada Unit Skip Load aktif -> fungsi ini tidak menyentuh apa pun sama sekali. */
    if (empty($model['unit_skip_load'])) return 0;

    $UNITS = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'];
    $MM    = ['ge1'=>1,'ge2'=>1,'ge3'=>1,'ge4'=>1];
    /* PETA BAND, dihitung SEKALI. Fungsi ini dijalankan pada setiap core run, jadi ia tidak boleh
     * memanggil pp_get_skip_load() 48 x 16 kali per pemanggilan. Hanya unit yang benar-benar
     * memiliki aturan Unit Skip Load yang masuk peta. */
    $bandUnits = [];
    foreach ((array)$model['unit_skip_load'] as $bu => $brules) {
        if (!is_array($brules) || !$brules) continue;
        $bl = strtolower((string)$bu);
        if (!in_array($bl, $UNITS, true)) continue;
        $bandUnits[$bl] = [];
        foreach ($brules as $br) {
            if (!is_array($br)) continue;
            $bandUnits[$bl][] = [(int)($br['start'] ?? 1), (int)($br['stop'] ?? $n),
                                 (float)($br['value_low'] ?? 0), (float)($br['value_high'] ?? 0)];
        }
    }
    if (!$bandUnits) return 0;
    $bandAt = function (string $u, int $r1) use ($bandUnits): ?array {
        if (!isset($bandUnits[$u])) return null;
        foreach ($bandUnits[$u] as $b) if ($r1 >= $b[0] && $r1 <= $b[1]) return [$b[2], $b[3]];
        return null;
    };
    /* PRA-PINDAI: bila tidak ada satu pun baris yang melanggar, keluar tanpa membangun apa pun. */
    $anyViol = false;
    foreach ($bandUnits as $bu => $_) {
        for ($rr = 0; $rr < $n; $rr++) {
            $bb = $bandAt($bu, $rr + 1); if ($bb === null) continue;
            $vv = (float)($genRows[$rr][$bu] ?? 0);
            if ($vv > 0.01 && $vv >= $bb[0] - PP_SKIP_LOAD_GUARD_MW - 1e-9
                           && $vv <= $bb[1] + PP_SKIP_LOAD_GUARD_MW + 1e-9) { $anyViol = true; break 2; }
        }
    }
    if (!$anyViol) return 0;
    /* RUMUS EXPORT OTORITATIF — sama persis dengan $realised_export() di worker02.php, yang
     * menghasilkan Export_PLN pada baris output dan yang dinilai validator:
     *     mm2100   = GE1..GE4 + G10            (pelanggan MM2100, bukan bus PLN)
     *     export   = sum(gen) - mm2100 - house_load - IE
     * Catatan: G10 WAJIB ikut dikecualikan. Menyalin rumus dari pp_export_ramp_repair() — yang
     * hanya mengecualikan GE1..GE4 — membuat Export yang dihitung di sini lebih besar sebanyak G10,
     * sehingga sebuah baris dapat dinyatakan "valid" oleh perbaikan ini tetapi tetap dilaporkan di
     * luar Range oleh validator. Itu terjadi dan terukur sebagai satu pelanggaran Export sisa. */
    $expOf = function (array $g, float $ie): float {
        $tot = 0.0; foreach ($g as $u => $v) if ($u[0] !== '_') $tot += (float)$v;
        $mm = (float)($g['ge1'] ?? 0) + (float)($g['ge2'] ?? 0) + (float)($g['ge3'] ?? 0)
            + (float)($g['ge4'] ?? 0) + (float)($g['g10'] ?? 0);
        return $tot - $mm - calc_house_load($g) - $ie;
    };
    $busUnit = (array)($model['bus_unit'] ?? []);
    $busMin  = (float)($model['busflow_min'] ?? 0);
    $busOf = function (array $g, float $ie) use ($busUnit, $MM): float {
        $b = 0.0;
        foreach ($g as $u => $l) {
            if (isset($MM[$u]) || $u === 'g10' || $u[0] === '_') continue;
            if (($busUnit[$u . '_bus'] ?? null) === 'B') $b += (float)$l;
        }
        return $ie - $b;
    };
    /* ===== TABEL STATIS PER (UNIT, BARIS), DIHITUNG SEKALI ====================================
     * pp_effective_min_load(), pp_effective_maxload(), pp_is_unit_stopped(), dan pp_get_fixed_load()
     * memindai aturan input setiap kali dipanggil. Di dalam loop kompensasi (16 unit x 3 sapuan x
     * 2 kandidat x 48 baris) pemanggilan itu mencapai ratusan ribu kali per request dan terukur
     * mendorong satu skenario dari 24 detik menjadi menabrak plafon 60 detik. Nilainya tidak
     * berubah selama satu pemanggilan, jadi dihitung sekali lalu dibaca dari tabel. */
    $TMIN = []; $TMAX = []; $TSTOP = []; $TFIX = []; $TPRESENT = [];
    foreach ($UNITS as $tu) {
        $TPRESENT[$tu] = isset($d3[$tu]) && pp_unit_present($d3, $tu);
        for ($tr = 1; $tr <= $n; $tr++) {
            if (!$TPRESENT[$tu]) { $TMIN[$tu][$tr] = 0.0; $TMAX[$tu][$tr] = 0.0;
                                   $TSTOP[$tu][$tr] = true; $TFIX[$tu][$tr] = -1.0; continue; }
            $mm2 = ($tu === 'b1' || $tu === 'b2') ? 10.0 : pp_effective_min_load($d3, $model, $tu, $tr);
            if ($mm2 <= 0) $mm2 = (float)($d3[$tu]['min_ccload'] ?? ($d3[$tu]['min_scload'] ?? 20));
            $mx2 = pp_effective_maxload($d3, $model, $tu, $tr);
            if ($mx2 <= 0) $mx2 = (float)($d3[$tu]['max_load'] ?? 0);
            $TMIN[$tu][$tr] = $mm2; $TMAX[$tu][$tr] = $mx2;
            $TSTOP[$tu][$tr] = pp_is_unit_stopped($d3, $model, $tu, $tr);
            $TFIX[$tu][$tr] = pp_get_fixed_load($model, $tu, $tr);
        }
    }
    $minOf = fn(string $u, int $r1): float => (float)($TMIN[$u][$r1] ?? 0.0);
    $maxOf = fn(string $u, int $r1): float => (float)($TMAX[$u][$r1] ?? 0.0);
    /* Band efektif = band nominal diperlebar guard 0,51 MW di kedua sisi, sama persis dengan
     * pemeriksaan di pp_validate_hard_constraints(). Memakai band nominal di sini akan membuat
     * perbaikan "berhasil" menurut fungsi ini tetapi tetap dilaporkan melanggar oleh validator. */
    $inBand = function (string $u, int $r1, float $v) use ($bandAt): bool {
        if ($v <= 0.01) return false;
        $sk = $bandAt($u, $r1);
        if ($sk === null) return false;
        $g = PP_SKIP_LOAD_GUARD_MW;
        return $v >= (float)$sk[0] - $g - 1e-9 && $v <= (float)$sk[1] + $g + 1e-9;
    };
    $movable = function (string $u, int $r) use (&$genRows, $TPRESENT, $TSTOP, $TFIX): bool {
        $r1 = $r + 1;
        if (empty($TPRESENT[$u])) return false;
        if (!empty($TSTOP[$u][$r1])) return false;
        if (($TFIX[$u][$r1] ?? -1.0) >= 0) return false;
        return ((float)($genRows[$r][$u] ?? 0)) > 0.01;      // hanya unit yang sudah berbeban
    };
    /* Ramp unit terhadap baris tetangga (batas engine untuk GTG = 30 MW/30 menit; Babelan 5). */
    $rampCap = function (string $u) use ($model): float {
        if ($u === 'b1' || $u === 'b2') return pp_babelan_ramp_limit($model);
        return 30.0;
    };
    $rampOk = function (string $u, int $r, float $val) use (&$genRows, $n, $rampCap, $actualRows): bool {
        $cap = $rampCap($u);
        foreach ([$r - 1, $r + 1] as $nb) {
            if ($nb < 0 || $nb >= $n) continue;
            $nv = (float)($genRows[$nb][$u] ?? 0);
            if ($nv <= 0.01 || $val <= 0.01) continue;        // start/stop diatur pass lain
            if (abs($val - $nv) > $cap + 1e-6) return false;
        }
        return true;
    };
    /* Validasi penuh SATU baris beserta kedua tetangganya. */
    $rowOk = function (int $r) use (&$genRows, $n, $d3, $model, $ieVals, $expOf, $busOf, $busMin,
                                    $loAt, $hiAt, $inBand, $minOf, $maxOf, $rampOk, $UNITS, $actualRows): bool {
        $e = $expOf($genRows[$r], (float)$ieVals[$r]);
        $lo = $loAt($r); $hi = $hiAt($r);
        if ($e < $lo - 1e-6 || ($hi > 0 && $e > $hi + 1e-6)) return false;
        /* tetangga juga wajib tetap berada di dalam batas MILIK BARISNYA SENDIRI */
        foreach ([$r - 1, $r + 1] as $nb2) {
            if ($nb2 < 0 || $nb2 >= $n) continue;
            $en = $expOf($genRows[$nb2], (float)$ieVals[$nb2]);
            $lon = $loAt($nb2); $hin = $hiAt($nb2);
            if ($en < $lon - 1e-6 || ($hin > 0 && $en > $hin + 1e-6)) return false;
        }
        if ($busOf($genRows[$r], (float)$ieVals[$r]) < $busMin - 1e-6) return false;
        $step = pp_export_step_limit($model);
        foreach ([$r - 1, $r + 1] as $nb) {
            if ($nb < 0 || $nb >= $n) continue;
            if (abs($e - $expOf($genRows[$nb], (float)$ieVals[$nb])) > $step + 1e-6) return false;
        }
        foreach ($UNITS as $u) {
            $v = (float)($genRows[$r][$u] ?? 0);
            if ($v <= 0.01) continue;
            if ($inBand($u, $r + 1, $v)) return false;                    // band terlarang
            if ($v < $minOf($u, $r + 1) - 1e-6) return false;             // di bawah min efektif
            if ($v > $maxOf($u, $r + 1) + 1e-6) return false;             // di atas max efektif
            if (!$rampOk($u, $r, $v)) return false;                       // ramp unit
        }
        return true;
    };

    /* Konsumsi gas satu baris (BBTU per slot 30 menit), rumus yang sama dengan akuntansi engine. */
    $gasOfRow = function (array $g) use ($d3): float {
        $t = 0.0;
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
            $t += calc_fuel($d3, $u, (float)($g[$u] ?? 0));
        return $t / 2.0;
    };
    /* ANGGARAN GAS DARI RUANG NYATA DI DALAM WINDOW KUOTA.
     * Perbaikan band memang dapat menggeser konsumsi gas. Yang tidak boleh terjadi adalah total gas
     * keluar dari window kuota — itu berarti memindahkan pelanggaran. Karena itu anggarannya bukan
     * konstanta, melainkan sisa ruang yang benar-benar ada: jarak antara total gas saat ini dan
     * kedua tepi window. Bila kuota tidak diketahui, dipakai batas konservatif. */
    $gasTotalNow = 0.0;
    for ($rr = 0; $rr < $n; $rr++) $gasTotalNow += $gasOfRow($genRows[$rr]);
    if ($gasQuotaTotal !== null && $gasQuotaTotal > 1.0) {
        [$gwLo, $gwHi] = pp_gas_window($gasQuotaTotal);
        $roomUp   = max(0.0, $gwHi - $gasTotalNow);
        $roomDown = max(0.0, $gasTotalNow - $gwLo);
    } else {
        $roomUp = PP_SKIP_LOAD_GAS_BUDGET_BBTUD; $roomDown = PP_SKIP_LOAD_GAS_BUDGET_BBTUD;
    }
    $repaired = 0; $gasBudgetUsed = 0.0;
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;                              // baris actual terkunci
        foreach (array_keys($bandUnits) as $u) {
            $r1 = $r + 1;
            $sk = $bandAt($u, $r1);
            if ($sk === null) continue;
            $cur = (float)($genRows[$r][$u] ?? 0);
            if (!$inBand($u, $r1, $cur)) continue;
            if (($TFIX[$u][$r1] ?? -1.0) >= 0) {
                $evidence[] = ['row' => $r1, 'unit' => strtoupper($u), 'value' => round($cur, 2),
                               'band' => [(float)$sk[0], (float)$sk[1]], 'repaired' => false,
                               'blocker' => 'FIXED_LOAD_MENGUNCI_NILAI_DI_DALAM_BAND'];
                continue;
            }
            $lo = (float)$sk[0]; $hi = (float)$sk[1];
            $eMin = $minOf($u, $r1); $eMax = $maxOf($u, $r1);
            /* KONTRAK BATAS DARI SOURCE AKTUAL. pp_validate_hard_constraints() menyatakan sebuah
             * beban berada di dalam band bila `$actual >= $lo - 0.51 && $actual <= $hi + 0.51`,
             * yaitu band efektifnya diperlebar 0,51 MW di kedua sisi. Kandidat perbaikan WAJIB
             * berada di luar band efektif itu — bukan di luar band nominal. Margin 0,6 MW dipakai
             * agar melewati guard 0,51 dengan jelas. Tolerance validator TIDAK diubah. */
            $guardBand = PP_SKIP_LOAD_GUARD_MW;
            $margin = $guardBand + 0.09;                       // 0,6 MW
            $cands = [];
            $up = $hi + $margin;   if ($up <= $eMax + 1e-9) $cands[] = $up;
            $dn = $lo - $margin;   if ($dn >= $eMin - 1e-9) $cands[] = $dn;
            usort($cands, fn($a, $b) => abs($a - $cur) <=> abs($b - $cur));   // paling sedikit mengganggu
            $before = $genRows[$r];
            $expTarget = $expOf($before, (float)$ieVals[$r]);
            $gasBeforeRow = $gasOfRow($before);
            $done = false; $tried = []; $best = null;
            foreach ($cands as $cand) {
                $genRows[$r] = $before;                                     // mulai transaksi bersih
                $genRows[$r][$u] = $cand;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r1);
                /* ---- redispatch kompensasi agar Export kembali ke nilai semula ---------------- */
                /* KOMPENSASI DALAM SAPUAN. pp_recompute_stgs() adalah operasi termahal di sini;
                 * memanggilnya setelah SETIAP unit membuat fungsi ini berjalan puluhan kali per
                 * baris per core run dan terukur mendorong satu skenario menabrak plafon 60 detik.
                 * Karena itu penyesuaian dikumpulkan dalam satu sapuan, lalu STG dihitung ulang
                 * SEKALI dan selisih Export diukur kembali. Maksimum tiga sapuan. */
                $need = $expOf($genRows[$r], (float)$ieVals[$r]) - $expTarget;   // >0 = harus diturunkan
                for ($sweep = 0; $sweep < 3 && abs($need) > 0.01; $sweep++) {
                    $movedAny = false; $rem = $need;
                    foreach ($UNITS as $cu) {
                        if (abs($rem) <= 0.01) break;
                        if ($cu === $u || isset($MM[$cu]) || $cu === 'g10') continue;
                        if (!$movable($cu, $r)) continue;
                        $cv = (float)($genRows[$r][$cu] ?? 0);
                        $cMin = $minOf($cu, $r1); $cMax = $maxOf($cu, $r1);
                        $room = ($rem > 0) ? ($cv - $cMin) : ($cMax - $cv);      // ruang ke arah yang dibutuhkan
                        if ($room <= 0.01) continue;
                        $stepv = min(abs($rem), $room);
                        $nv = ($rem > 0) ? ($cv - $stepv) : ($cv + $stepv);
                        if ($inBand($cu, $r1, $nv)) continue;                    // jangan buat pelanggaran baru
                        if (!$rampOk($cu, $r, $nv)) continue;
                        $genRows[$r][$cu] = $nv;
                        $rem -= ($rem > 0) ? $stepv : -$stepv;
                        $movedAny = true;
                    }
                    if (!$movedAny) break;                                       // tidak ada lever tersisa
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r1);
                    $need = $expOf($genRows[$r], (float)$ieVals[$r]) - $expTarget;
                }
                /* SYARAT COMMIT. Tidak cukup bahwa Export masih berada di dalam Range: perbaikan
                 * tidak boleh MENGUBAH rencana secara material. Kompensasi wajib benar-benar
                 * memulihkan Export baris itu (toleransi 0,5 MW), dan konsumsi gas baris itu wajib
                 * mendekati netral. Tanpa syarat ini, baris yang kehabisan lever tetap ter-commit
                 * dan terukur menggeser Export 26,84 -> 41,25 MW serta melempar total gas keluar
                 * window (65,1826 terhadap [64,5600; 64,6000]).
                 *
                 * Anggaran gas kumulatif dibatasi jauh di bawah lebar window kuota (0,04 BBTUD)
                 * sehingga seluruh perbaikan bersama-sama tidak dapat memecahkan window itu. */
                /* SYARAT COMMIT.
                 *  - seluruh constraint baris DAN kedua tetangganya wajib valid ($rowOk);
                 *  - pergeseran gas wajib tetap di dalam ruang nyata window kuota.
                 * Export TIDAK diwajibkan kembali ke nilai semula: memindahkannya di DALAM Range
                 * adalah rencana yang sah, dan $rowOk sudah menjamin Range, step ramp Export,
                 * BusFlow, min/max unit, serta ramp unit. Yang dijaga terpisah adalah gas, karena
                 * itulah satu-satunya jalan pelanggaran dapat "berpindah" tanpa terlihat.
                 * Di antara kandidat yang lolos, dipilih yang paling SEDIKIT menggeser Export. */
                $expNow = $expOf($genRows[$r], (float)$ieVals[$r]);
                $gasAfterRow = $gasOfRow($genRows[$r]);
                $gasDelta = $gasAfterRow - $gasBeforeRow;
                $gasCum = $gasBudgetUsed + $gasDelta;
                $gasAffordable = ($gasCum >= 0.0)
                    ? ($gasCum <= $roomUp + 1e-9)
                    : (-$gasCum <= $roomDown + 1e-9);
                /* BATAS GANGGUAN. Membiarkan Export bergeser bebas di dalam Range memang sah,
                 * tetapi terukur membuat rencana berubah cukup jauh sehingga pass rekonsiliasi
                 * engine sesudahnya bekerja jauh lebih lama — satu skenario bergerak dari 24 detik
                 * menjadi menabrak plafon 60 detik. Perbaikan invariant tidak boleh menjadi sumber
                 * ketidakstabilan runtime, jadi pergeseran Export dibatasi. Di antara kandidat yang
                 * lolos tetap dipilih yang pergeserannya paling kecil. */
                $shiftNow = abs($expNow - $expTarget);
                $ok = $rowOk($r) && $gasAffordable && ($shiftNow <= PP_SKIP_LOAD_MAX_EXPORT_SHIFT_MW);
                if ($ok) {
                    $shift = abs($expNow - $expTarget);
                    if ($best === null || $shift < $best['shift'] - 1e-9) {
                        $best = ['shift' => $shift, 'row' => $genRows[$r], 'cand' => $cand,
                                 'exp' => $expNow, 'gas' => $gasDelta];
                    }
                }
                $tried[] = ['nilai' => round($cand, 2),
                            'row_valid' => $rowOk($r),
                            'export_setelah' => round($expNow, 2),
                            'pergeseran_export' => round(abs($expNow - $expTarget), 2),
                            'gas_delta' => round($gasDelta, 6),
                            'gas_terjangkau' => $gasAffordable,
                            'sisa_ruang_gas_naik' => round($roomUp, 6),
                            'sisa_ruang_gas_turun' => round($roomDown, 6)];
                /* setiap kandidat dievaluasi dari state BERSIH; kandidat terbaik di-commit sesudahnya */
                $genRows[$r] = $before;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r1);
            }
            if ($best !== null) {
                $genRows[$r] = $best['row'];                                     // COMMIT kandidat terbaik
                $gasBudgetUsed += $best['gas'];
                $evidence[] = ['row' => $r1, 'unit' => strtoupper($u),
                               'from' => round($cur, 2), 'to' => round($best['cand'], 2),
                               'band' => [$lo, $hi], 'repaired' => true,
                               'export_before' => round($expTarget, 2),
                               'export_after' => round($best['exp'], 2),
                               'pergeseran_export' => round($best['shift'], 2),
                               'gas_delta_bbtud' => round($best['gas'], 6),
                               'metode' => 'REDISPATCH_BERKOMPENSASI_ATOMIK'];
                $repaired++; $done = true;
            }
            if (!$done) {
                $genRows[$r] = $before;                                          // ROLLBACK penuh
                pp_recompute_stgs($genRows[$r], $d3, $model, $r1);
                $evidence[] = ['row' => $r1, 'unit' => strtoupper($u), 'value' => round($cur, 2),
                               'band' => [$lo, $hi], 'repaired' => false,
                               'kandidat_dicoba' => $tried,
                               'blocker' => 'TIDAK_ADA_NILAI_LEGAL_YANG_LOLOS_SELURUH_CONSTRAINT'];
            }
        }
    }
    return $repaired;
}

function pp_export_ramp_repair(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows, float $rMin, float $rMax): int {
    $n = count($genRows); if ($n === 0) return 0;
    $expOf = function (array $g, float $ie) use ($d3, $model): float {
        $tot = 0.0; foreach ($g as $u => $v) if ($u[0] !== '_' ) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4'] as $ge) $mm += (float)($g[$ge] ?? 0);
        $hl = calc_house_load($g);
        return $tot - $mm - $hl - $ie;
    };
    $isBB = fn($u) => ($u === 'b1' || $u === 'b2');
    $minOf = function (string $u) use ($d3) {
        if ($u === 'b1' || $u === 'b2') return 10.0;
        return (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
    };
    $capOf = function (string $u, int $r) use ($d3, $model, &$genRows, $n) {
        if ($u === 'b1' || $u === 'b2') {
            $cap = pp_effective_maxload($d3, $model, $u, $r + 1);
            if ($cap <= 0) $cap = (float)($d3[$u]['max_load'] ?? 120);
            $lim = pp_babelan_ramp_limit($model);
        } else {
            $em = pp_effective_maxload($d3, $model, $u, $r + 1);
            $cap = ($em > 0 ? $em : (float)($d3[$u]['max_load'] ?? 108));
            $lim = 30.0;
        }
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            $cap = min($cap, (float)($genRows[$nb][$u] ?? 0) + $lim); }
        return $cap;
    };
    $floorNb = function (string $u, int $r, float $nv) use (&$genRows, $n, $model): bool {   // penurunan tak memecah ramp tetangga
        $lim = ($u === 'b1' || $u === 'b2') ? pp_babelan_ramp_limit($model) : 30.0;
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            if ($nv < (float)($genRows[$nb][$u] ?? 0) - $lim - 1e-9) return false; }
        return true;
    };
    $units = ['g9', 'g8', 'g1', 'g2', 'g5', 'g3', 'b2', 'b1'];
    /* STARTUP-WINDOW LOCK (root cause A04): row-row di dalam startup sequence sebuah unit TIDAK boleh
     * disentuh repair — urutannya (mis. Warm S3: 50,50,60,60,70 atau Additional HRSG: 5,15) adalah
     * hard constraint. Window dideteksi generik lewat pp_start_sequence pada setiap start event. */
    $suLock = [];
    foreach (array_merge($units, ['g4', 'g6', 'g7', 'g10']) as $uS) {
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($uS)] ?? '')) === 'running');
        for ($r = 0; $r < $n; $r++) {
            $on = ((float)($genRows[$r][$uS] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $seq = pp_start_sequence($d3, $model, $uS, $genRows, $r);
                for ($k = 0; $k < count($seq) && $r + $k < $n; $k++) $suLock[$uS][$r + $k] = true;
            }
            $prevOn = $on;
        }
    }
    $moves = 0;
    for ($it = 0; $it < 700; $it++) {
        if (pp_budget_exceeded('export_ramp_repair')) break;                       // deadline budget
        if (pp_budget_repeat('xrr', pp_rows_signature($genRows))) break;           // oscillation/no-progress
        $wi = -1; $wd = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            $dT = $expOf($genRows[$i + 1], (float)$ieVals[$i + 1]) - $expOf($genRows[$i], (float)$ieVals[$i]);
            if (abs($dT) > 30.0 + 1e-6 && abs($dT) > abs($wd)) { $wd = $dT; $wi = $i; }
        }
        if ($wi < 0) break;
        $L = $wd > 0 ? $wi : $wi + 1; $H = $wd > 0 ? $wi + 1 : $wi;
        if (isset($actualRows[$L]) || isset($actualRows[$H])) break;
        $moved = false;
        /* (a)+(b): pasangan H turun / L naik (unit sama atau beda; BB diperbolehkan) */
        foreach ($units as $uH) { if ($moved) break;
            if (pp_get_fixed_load($model, $uH, $H + 1) >= 0 || pp_is_unit_stopped($d3, $model, $uH, $H + 1)) continue;
            if (isset($suLock[$uH][$H])) continue;                       // startup window: beku
            $cH = (float)($genRows[$H][$uH] ?? 0);
            if ($cH < $minOf($uH) + 0.5 - 1e-9) continue;
            if (!$floorNb($uH, $H, $cH - 0.5)) continue;
            foreach ($units as $uL) {
                if (pp_get_fixed_load($model, $uL, $L + 1) >= 0 || pp_is_unit_stopped($d3, $model, $uL, $L + 1)) continue;
                if (isset($suLock[$uL][$L])) continue;                   // startup window: beku
                $cL = (float)($genRows[$L][$uL] ?? 0); if ($cL < 0.01) continue;
                $cap = $capOf($uL, $L); if ($cL + 0.5 > $cap + 1e-9) continue;
                $svL = $genRows[$L]; $svH = $genRows[$H];
                $genRows[$H][$uH] = $cH - 0.5; $genRows[$L][$uL] = $cL + 0.5;
                pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                pp_recompute_stgs($genRows[$H], $d3, $model, $H + 1);
                $eL = $expOf($genRows[$L], (float)$ieVals[$L]); $eH = $expOf($genRows[$H], (float)$ieVals[$H]);
                $dA = abs($expOf($genRows[$wi + 1], (float)$ieVals[$wi + 1]) - $expOf($genRows[$wi], (float)$ieVals[$wi]));
                if ($eL > $rMax + 1e-6 || $eH < $rMin - 1e-6 || $dA >= abs($wd) - 1e-9) {
                    $genRows[$L] = $svL; $genRows[$H] = $svH;
                    pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                    pp_recompute_stgs($genRows[$H], $d3, $model, $H + 1); continue;
                }
                $moved = true; $moves++; break;
            }
        }
        /* (c): donor-row — turunkan u di row donor bermargin, naikkan u yang sama di L (gas ~netral) */
        if (!$moved) {
            foreach (['g9', 'g8', 'g1', 'g2', 'g5'] as $u) { if ($moved) break;
                if (pp_get_fixed_load($model, $u, $L + 1) >= 0 || pp_is_unit_stopped($d3, $model, $u, $L + 1)) continue;
                if (isset($suLock[$u][$L])) continue;                    // startup window: beku
                $cL = (float)($genRows[$L][$u] ?? 0); if ($cL < 0.01) continue;
                $cap = $capOf($u, $L); if ($cL + 0.5 > $cap + 1e-9) continue;
                for ($D = 0; $D < $n; $D++) {
                    if ($D === $L || $D === $H || isset($actualRows[$D])) continue;
                    if (pp_get_fixed_load($model, $u, $D + 1) >= 0 || isset($suLock[$u][$D])) continue;
                    $cD = (float)($genRows[$D][$u] ?? 0);
                    if ($cD < $minOf($u) + 0.5 - 1e-9) continue;
                    if (!$floorNb($u, $D, $cD - 0.5)) continue;
                    $svL = $genRows[$L]; $svD = $genRows[$D];
                    $genRows[$D][$u] = $cD - 0.5; $genRows[$L][$u] = $cL + 0.5;
                    pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                    pp_recompute_stgs($genRows[$D], $d3, $model, $D + 1);
                    $eL = $expOf($genRows[$L], (float)$ieVals[$L]); $eD = $expOf($genRows[$D], (float)$ieVals[$D]);
                    $okD = ($eL <= $rMax + 1e-6) && ($eD >= $rMin - 1e-6);
                    foreach ([$D - 1, $D + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if (abs($eD - $expOf($genRows[$nb], (float)$ieVals[$nb])) > pp_export_step_limit($model) + 1e-6) $okD = false; }
                    $dA = abs($expOf($genRows[$wi + 1], (float)$ieVals[$wi + 1]) - $expOf($genRows[$wi], (float)$ieVals[$wi]));
                    if (!$okD || $dA >= abs($wd) - 1e-9) {
                        $genRows[$L] = $svL; $genRows[$D] = $svD;
                        pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                        pp_recompute_stgs($genRows[$D], $d3, $model, $D + 1); continue;
                    }
                    $moved = true; $moves++; break;
                }
            }
        }
        if (!$moved) break;
    }
    return $moves;
}

/* ===== CONVERGENCE / TIME BUDGET GUARDS (ITEM E) ==============================================
 * Root cause timeout 120s BUKAN calc_fuel (worker_functions:583 hanyalah fungsi terpanas yang
 * kebetulan tertangkap profiler) melainkan CALLER-LOOP berlapis: decommit iteratif x kandidat x
 * stop-window, masing-masing menjalankan core run penuh, ditambah repair loop mikro-step. Guard di
 * sini menghentikan pekerjaan secara ANGGUN (hasil valid + warning), bukan dengan menaikkan
 * max_execution_time:
 *   - deadline budget global (default 90s; UI/run.php dapat menurunkannya),
 *   - max iteration per loop (sudah ada) + no-progress detection,
 *   - repeated-state/oscillation detection via signature state,
 *   - epsilon numerik seragam (pp_eps) supaya "progress" tidak diklaim oleh noise float. */
function pp_eps(): float { return 1e-9; }

/* Budget default diturunkan dari max_execution_time PHP (mis. 120s di app) dgn margin aman,
 * sehingga engine berhenti anggun SEBELUM PHP mematikan proses (fatal tanpa JSON). */
function pp_budget_default_from_ini(float $margin = 25.0): float {
    $mx = (float)ini_get('max_execution_time');
    if ($mx <= 0) return 90.0;                                  // CLI/unlimited -> default internal
    return max(15.0, $mx - $margin);
}

function pp_budget_start(?float $seconds = null, bool $force = false, bool $pinned = false): void {
    /* PINNED = ditetapkan caller HTTP/UI (run.php) -> tidak boleh direset oleh pipeline.
     * Tanpa pinned, setiap simulasi TOP-LEVEL memulai budget baru (lihat pp_budget_reset_for_sim)
     * sehingga batch (release-validate 100x, harness) TIDAK mewarisi jam yang sudah berjalan —
     * warisan itulah yang membuat run belakangan terpotong dan hasil jadi non-deterministik. */
    if (!$force && isset($GLOBALS['__pp_budget']['t0'])) return;          // idempotent: caller terluar menang
    if (!$force && !empty($GLOBALS['__pp_budget']['pinned'])) return;
    /* CLAMP KE BATAS PHP (root cause "Maximum execution time exceeded"): budget yang diminta caller
     * TIDAK boleh melewati max_execution_time SAPI, karena PHP akan mematikan proses dgn FATAL
     * (tanpa JSON) sebelum guard konvergensi kita sempat berhenti anggun. */
    if ($seconds !== null && $seconds > 0) {
        $iniLim = (float)ini_get('max_execution_time');
        if ($iniLim > 0) $seconds = min((float)$seconds, pp_budget_default_from_ini());
    }
    $initial=($seconds!==null&&$seconds>0)?(float)$seconds:10.0;
    $hardMax=pp_budget_ceiling();
    $iniLim=(float)ini_get('max_execution_time');
    if($iniLim>0)$hardMax=min($hardMax,max(10.0,pp_budget_default_from_ini()));
    $initial=min($hardMax,max(10.0,$initial));
    $GLOBALS['__pp_budget'] = [
        't0'=>microtime(true),'lim'=>$initial,'initial_lim'=>$initial,
        'adaptive_step'=>5.0,'adaptive_max'=>$hardMax,'extensions'=>[],
        'hits'=>0,'latched'=>false,'states'=>[],'notes'=>[],'pinned'=>$pinned,
    ];
}

/* Dipanggil di awal setiap simulasi TOP-LEVEL (depth 0). Budget pinned (dari run.php) dihormati. */
function pp_budget_reset_for_sim(?float $seconds = null): void {
    if (!empty($GLOBALS['__pp_budget']['pinned'])) return;
    pp_budget_start($seconds, true, false);
}
/* ---- ABSOLUTE REQUEST DEADLINE (the real fail-safe) -------------------------------------------
 * pp_budget_reset_for_sim() restarts the clock for every simulation that enters at depth 1. But
 * pp_run_simulation() decrements the depth BEFORE pp_jbbk_quota_offset_search() and
 * pp_global_commitment_review() run their nested simulations, so those re-enter at depth 1 and were
 * treated as brand-new top-level runs -- each one granted a fresh 60 s. Measured on input_data.json:
 * one CLI simulation ran 117.8 s under a budget that reports "limit 60".
 * This deadline is armed ONCE per outermost simulation (so the 100x release gate still gets a full
 * budget per run) and is never cleared by a reset, which makes 60 s an actual ceiling. It can only
 * stop a run that would otherwise overrun; a request that converges earlier is unaffected. */
function pp_budget_arm_deadline(?float $maxSeconds = null): void {
    if (isset($GLOBALS['__pp_budget_deadline'])) return;
    $GLOBALS['__pp_budget_deadline'] = microtime(true) + max(10.0, min(pp_budget_ceiling(), (float)($maxSeconds ?: 60.0)));
}
function pp_budget_disarm_deadline(): void { unset($GLOBALS['__pp_budget_deadline']); }

/* ==============================================================================================
 *  GAS UNTUK KEPUTUSAN KUALITAS RENCANA (akar penyebab pelanggaran monotonicity Distillate).
 *
 *  `Total Gas Used (BBTUD)` dilaporkan NETO: pada mode `use_distillate` ia sudah dikurangi energi
 *  yang digantikan distillate. Angka itu benar sebagai LAPORAN, tetapi salah sebagai DASAR
 *  KEPUTUSAN, dan dahulu dipakai oleh screening decommit, gerbang export minimization, serta
 *  pencarian offset kuota.
 *
 *  Akibatnya plafon distillate operator — yang seharusnya hanya batas PASOKAN — berubah menjadi
 *  TUJUAN DISPATCH: makin besar plafon, makin kecil gas neto yang terlihat, makin "sehat" rencana
 *  yang belum diperbaiki tampak, dan makin sedikit perbaikan yang dijalankan engine. Terukur pada
 *  input reproduksi (PGN pipe 20):
 *
 *      plafon 272.475,8 l -> decommit G3 berjalan -> gas kotor 64,3199 -> residual 0,0046 -> LULUS
 *      plafon 280.000,0 l -> tidak ada decommit   -> gas kotor 70,0855 -> residual 5,4460 -> GAGAL
 *      plafon 300.000,0 l -> tidak ada decommit   -> gas kotor 70,0855 -> residual 4,7170 -> GAGAL
 *
 *  Plafon yang LEBIH BESAR menghasilkan rencana yang LEBIH BURUK — padahal rencana pemenang pada
 *  plafon kecil (memakai 270.210,6 l) tetap berada di dalam domain plafon besar. Itu pelanggaran
 *  monotonicity: plafon adalah batas atas, menaikkannya tidak boleh mempersempit feasible set.
 *
 *  Perbaikan ada di SUMBER keputusan, bukan pada angka liter mana pun: kualitas rencana dinilai
 *  memakai substitusi distillate yang dihitung TANPA plafon — besaran yang tidak bergantung pada
 *  berapa banyak distillate yang kebetulan disetujui operator. Plafon diterapkan SESUDAH rencana
 *  dipilih, pada penjadwalan yang benar-benar dilaporkan. Dengan begitu rencana identik untuk
 *  setiap plafon, dan plafon yang lebih besar hanya dapat menutup residual yang sama atau lebih
 *  banyak — monotonicity berlaku by construction.
 *
 *  Pada mode selain `use_distillate` angka keputusan == gas neto == gas kotor, sehingga perilaku
 *  lama tidak berubah sama sekali dan regresi tidak tersentuh.
 *
 *  CATATAN PENTING TENTANG PILIHAN ANGKA. Memakai gas KOTOR di sini terbukti SALAH arah: rencana
 *  distillate selalu berada jauh di atas window menurut gas kotor, sehingga screening selalu masuk
 *  cabang "baseline infeasible" yang lebih lemah, tidak pernah memakai cabang berbasis biaya, dan
 *  seluruh plafon berakhir pada rencana buruk yang sama (gas kotor 70,0855). Yang diperlukan bukan
 *  angka yang mengabaikan distillate, melainkan angka yang tidak bergantung PLAFON: gas setelah
 *  substitusi yang dihitung TANPA plafon, dibatasi kapasitas fisik slot saja. Dengan itu setiap
 *  plafon menghadapi baseline keputusan yang sama, memperoleh rencana yang sama, dan plafon hanya
 *  menentukan berapa banyak residual yang benar-benar tertutup. */
/* Aksi yang MENJADWALKAN distillate. `mixed_lng_distillate` memakai LNG sebesar otorisasi operator
 * lalu menutup sisanya dengan distillate, sehingga seluruh akuntansi distillate berlaku sama
 * seperti `use_distillate`. Dipusatkan di satu fungsi supaya tidak ada situs yang tertinggal —
 * situs yang tertinggal persis yang membuat angka distillate hilang dari ringkasan. */
function pp_action_uses_distillate(string $action): bool {
    return $action === 'use_distillate' || $action === 'mixed_lng_distillate';
}

function pp_decision_gas(array $out): float {
    $i = (array)($out['info'] ?? []);
    $d = $i['Decision Gas Used (BBTUD)'] ?? null;
    if (is_numeric($d)) return (float)$d;
    return (float)($i['Total Gas Used (BBTUD)'] ?? 0);
}
/* ==============================================================================================
 *  GARIS WAKTU FASE (audit anggaran waktu — §9 instruksi operator).
 *  Mencatat detik-berlalu dan jumlah core run pada setiap batas tahap pipeline TERLUAR, sehingga
 *  pertanyaan "mengapa comparator kehabisan waktu" dijawab dari pengukuran, bukan dugaan.
 *  Biayanya satu microtime() per tahap dan tidak ada keputusan yang bergantung padanya. */
/* ==============================================================================================
 *  JEJAK PROGRES ANALISIS PENDAHULUAN.
 *
 *  Ditulis pada setiap batas fase pipeline TERLUAR dan dibaca endpoint `mode=prelim_progress`.
 *  Isinya nama fase NYATA yang baru selesai — bukan perkiraan waktu, bukan animasi. Bila engine
 *  berhenti, berkas ini berhenti bertambah, dan itu sendiri sudah merupakan informasi.
 *
 *  Biayanya satu penulisan berkas kecil per fase (delapan sampai sepuluh kali per run). */
function pp_prelim_progress_path(string $rid): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . '_prelim';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir . DIRECTORY_SEPARATOR . ($rid !== '' ? $rid : 'unknown') . '.json';
}
/* Menyapu jejak progres lama supaya direktori tidak tumbuh tanpa batas. Dipanggil sekali per run
 * di pintu masuk, bukan per fase dan bukan per polling. */
function pp_prelim_progress_sweep(int $maxAgeSeconds = 3600): void {
    $dir = dirname(pp_prelim_progress_path('x'));
    foreach ((array)@scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $p = $dir . DIRECTORY_SEPARATOR . $e;
        if (is_file($p) && (time() - (int)@filemtime($p)) > $maxAgeSeconds) @unlink($p);
    }
}
function pp_prelim_progress_mark(string $phase): void {
    $rid = (string)($GLOBALS['__pp_prelim_rid'] ?? '');
    if ($rid === '') return;
    $t0 = (float)($GLOBALS['__pp_prelim_t0'] ?? microtime(true));
    $st = (array)($GLOBALS['__pp_prelim_steps'] ?? []);
    $st[] = ['phase' => $phase, 'at_s' => round(microtime(true) - $t0, 2)];
    $GLOBALS['__pp_prelim_steps'] = $st;
    pp_prelim_progress_write($phase);
}
/* Menulis berkas progres. Bila job lanjutan sudah didaftarkan di tengah jalur sinkron, identitas dan
 * token pemicunya ikut disertakan supaya browser dapat memulai perhitungan eksak SEKARANG, bukan
 * menunggu jalur sinkron selesai lalu baru memulainya. */
function pp_prelim_progress_write(?string $phase = null): void {
    $rid = (string)($GLOBALS['__pp_prelim_rid'] ?? '');
    if ($rid === '') return;
    $t0 = (float)($GLOBALS['__pp_prelim_t0'] ?? microtime(true));
    $st = (array)($GLOBALS['__pp_prelim_steps'] ?? []);
    $rec = ['phase' => $phase ?? (($st ? end($st)['phase'] : null)), 'steps' => $st,
            'elapsed_s' => round(microtime(true) - $t0, 2)];
    $adm = $GLOBALS['__pp_async_early_admission'] ?? null;
    if (is_array($adm) && !empty($adm['job_id']) && !empty($adm['exec_token']))
        $rec['async_job'] = ['job_id' => (string)$adm['job_id'], 'exec_token' => (string)$adm['exec_token']];
    @file_put_contents(pp_prelim_progress_path($rid), json_encode($rec));
}

/* AMBANG CHECKPOINT ADMISSION.
 * Terukur pada PGN 30 dengan anggaran request web 15 detik: batas fase sesudah core run dasar
 * (1,3 s) langsung disusul penyaringan decommit yang memakan 14 detik, sehingga ambang 12 detik
 * baru terlewati pada detik 15,2 — saat jalur sinkron sudah habis. Job eksak lalu baru mulai
 * sesudahnya dan seluruh 15 detik itu terbuang. Bila total anggaran pencarian request ini kecil
 * (<= 20 s, yaitu max_execution_time 30 bawaan XAMPP), job didaftarkan pada batas fase pertama
 * sesudah core run dasar sehingga browser dapat memicunya segera. Pada anggaran lebih besar
 * perilaku lama (12 s) tidak berubah. Admission hanya mendaftarkan job; tidak mengubah hasil. */
function pp_admit_checkpoint_threshold(float $t): float {
    $base = defined('PP_ADMIT_CHECKPOINT_S') ? PP_ADMIT_CHECKPOINT_S : 12.0;
    $total = INF;
    $left = pp_budget_deadline_left();
    if (is_finite($left)) $total = $t + $left;
    if (isset($GLOBALS['__pp_budget']['adaptive_max'])) $total = min($total, (float)$GLOBALS['__pp_budget']['adaptive_max']);
    if ($total <= 20.0) return min($base, 0.5);
    return $base;
}
if (!class_exists('PpSyncHandoff')) { class PpSyncHandoff extends Exception {} }
function pp_phase_mark(string $name): void {
    pp_prelim_progress_mark($name);          // progres nyata untuk modal; tidak memengaruhi keputusan
    /* Job eksak untuk state YANG SAMA sudah dipicu browser: jalur sinkron berhenti di batas fase ini
     * dan menyerahkan hasil kepada job itu, alih-alih menghitung state yang sama dua kali. Hanya
     * aktif di jalur sinkron HTTP (penanda dipasang run.php tepat di sekitar pp_run_simulation). */
    if (!empty($GLOBALS['__pp_sync_handoff_armed']) && function_exists('pp_job_read')) {
        $admH = $GLOBALS['__pp_async_early_admission'] ?? null;
        if (is_array($admH) && !empty($admH['job_id'])) {
            $jH = pp_job_read((string)$admH['job_id']);
            if (is_array($jH) && in_array((string)($jH['status'] ?? ''), ['CLAIMED', 'RUNNING', 'DONE'], true))
                throw new PpSyncHandoff((string)$admH['job_id']);
        }
    }
    if (!isset($GLOBALS['__pp_outer_t0'])) return;
    if ((int)($GLOBALS['__pp_sim_nest'] ?? 0) > 1) return;
    if (!empty($GLOBALS['__pp_gcmp_busy']) || !empty($GLOBALS['__pp_co_sweeping'])) return;
    if (!isset($GLOBALS['__pp_phase_timeline']) || !is_array($GLOBALS['__pp_phase_timeline']))
        $GLOBALS['__pp_phase_timeline'] = [];
    $t = microtime(true) - (float)$GLOBALS['__pp_outer_t0'];
    $prev = $GLOBALS['__pp_phase_timeline'] ? end($GLOBALS['__pp_phase_timeline']) : null;
    $GLOBALS['__pp_phase_timeline'][] = [
        'phase'      => $name,
        'at_s'       => round($t, 3),
        'delta_s'    => round($t - (float)($prev['at_s'] ?? 0.0), 3),
        'core_runs'  => (int)($GLOBALS['__pp_core_runs_total'] ?? 0),
        'core_delta' => (int)($GLOBALS['__pp_core_runs_total'] ?? 0) - (int)($prev['core_runs'] ?? 0),
        'left_s'     => is_finite(pp_budget_deadline_left()) ? round(pp_budget_deadline_left(), 3) : null,
    ];
    /* ==========================================================================================
     * CHECKPOINT ADMISSION ASINKRON TENGAH-JALAN (§11).
     *
     * MENGAPA INI ADA, PADAHAL SUDAH ADA PREDIKSI DI PINTU MASUK. Prediksi di pintu masuk hanya
     * melihat BENTUK input. Terukur pada PHP 7.4, itu tidak cukup: PGN 40 dan baseline punya
     * bentuk input yang identik dan hanya berbeda nilai kuota, tetapi memakan 29,5 detik versus
     * 15,1 detik, dan yang pertama tidak menyelesaikan review ekonomi. Tidak ada fitur input yang
     * dapat memisahkan keduanya di awal.
     *
     * Checkpoint ini tidak menebak apa pun. Ia memakai detik yang BENAR-BENAR SUDAH BERLALU pada
     * run yang sedang berjalan. Begitu satu fase pipeline selesai dan jam sudah melewati ambang
     * sementara pipeline masih punya fase tersisa, job latar dibuat SAAT ITU JUGA — pada detik
     * ke-12, bukan setelah operator menunggu 46 detik sampai jalur sinkron menyerah.
     *
     * YANG TIDAK DILAKUKAN. Pipeline sinkron TIDAK dihentikan, TIDAK dipotong, dan tidak satu pun
     * keputusannya berubah. Yang terjadi hanya satu: job dibuat lebih awal. Karena itu checkpoint
     * ini tidak dapat mengubah dispatch, kandidat, constraint, maupun biaya.
     * ========================================================================================== */
    if (!isset($GLOBALS['__pp_async_early_admission'])          // belum pernah diadmisi
        && empty($GLOBALS['__pp_is_job_worker'])                // bukan worker itu sendiri
        && !empty($GLOBALS['__pp_admit_input'])                 // hanya jalur HTTP yang memasang ini
        && $name !== 'pipeline_end'                             // masih ada fase tersisa
        && $name !== 'entry'
        && $t >= pp_admit_checkpoint_threshold($t)
        && function_exists('pp_job_start')) {
        $GLOBALS['__pp_async_early_admission'] = ['pending_checkpoint' => true];   // cegah masuk dua kali
        $admIn = (array)$GLOBALS['__pp_admit_input'];
        $stC = pp_job_start($admIn, 'economic_review', $GLOBALS['__pp_admit_rid'] ?? null, false);
        $jobC = (array)($stC['job'] ?? []);
        $GLOBALS['__pp_async_early_admission'] = [
            'admitted_early' => true,
            'trigger'        => 'CHECKPOINT_FASE',
            'at_phase'       => $name,
            'elapsed_s'      => round($t, 3),
            'threshold_s'    => pp_admit_checkpoint_threshold($t),
            'kind'           => 'economic_review',
            'ok'             => (bool)($stC['ok'] ?? false),
            'job_id'         => $jobC['job_id'] ?? null,
            'exec_token'     => $jobC['exec_token'] ?? null,
            'status'         => $jobC['status'] ?? null,
            'reused'         => (bool)($stC['reused'] ?? false),
            'error'          => !empty($stC['ok']) ? null : (string)($stC['error'] ?? ''),
            'catatan'        => 'Job dibuat di tengah jalur sinkron begitu jam melewati ambang, bukan '
                              . 'setelah jalur sinkron selesai. Pipeline sinkron tetap berjalan utuh.',
        ];
        pp_prelim_progress_write();
        /* Anggaran request kecil: jalur sinkron tidak akan menuntaskan pipeline, dan job eksak yang
         * baru didaftarkan akan menghitung state yang sama dari awal. Jalur sinkron berhenti di sini
         * supaya state itu hanya dihitung SATU kali — oleh job. */
        if (!empty($GLOBALS['__pp_sync_handoff_armed']) && !empty($jobC['job_id'])
            && pp_admit_checkpoint_threshold($t) < 1.0)
            throw new PpSyncHandoff((string)$jobC['job_id']);
    }
    /* PROGRESS JOB ASINKRON DITURUNKAN DARI FASE PIPELINE YANG NYATA.
     * Sebelumnya job hanya melaporkan satu langkah ("MENJALANKAN_SIMULASI_LENGKAP", 5%) lalu diam
     * sampai selesai — terukur: percent bertahan di 5 selama lebih dari 36 detik pengamatan.
     * Instruksi §6 mewajibkan progress BERGERAK. Fase pipeline sudah ditandai di sini, jadi
     * penanda yang sama dipakai untuk memperbarui progress job. Bobot fase mencerminkan biaya
     * TERUKUR dari profil (`export_min_phase_b_gcr` saja 91 detik dari total 177 detik), bukan
     * pembagian rata. Hanya aktif di dalam worker; jalur web tidak menyentuhnya. */
    if (!empty($GLOBALS['__pp_job_id']) && function_exists('pp_job_progress')) {
        static $phaseWeight = [
            'entry'                       => 3.0,
            'baseline_core_run'           => 10.0,
            'mandatory_stop_pass'         => 14.0,
            'decommit_screening'          => 28.0,
            'gas_window_correction'       => 40.0,
            'gas_quota_offset_search'     => 46.0,
            'export_min_phase_a'          => 55.0,
            'export_min_phase_b_decommit' => 70.0,
            'export_min_phase_b_gcr'      => 90.0,
            'pipeline_end'                => 95.0,
        ];
        $pct = $phaseWeight[$name] ?? null;
        if ($pct !== null) {
            $last = (float)($GLOBALS['__pp_job_last_pct'] ?? 0.0);
            if ($pct > $last) {                       // progress hanya maju, tidak pernah mundur
                $GLOBALS['__pp_job_last_pct'] = $pct;
                pp_job_progress((string)$GLOBALS['__pp_job_id'], strtoupper($name), $pct,
                    ['core_runs' => (int)($GLOBALS['__pp_core_runs_total'] ?? 0),
                     'elapsed_pipeline_s' => round($t, 2)]);
            }
        }
    }
}
/* PLAFON WAKTU SATU SIMULASI.
 * 60 detik adalah plafon REQUEST SINKRON (PHP-FPM) dan TIDAK PERNAH dinaikkan — batas itu ada
 * untuk melindungi request web. Worker CLI asinkron berjalan DI LUAR PHP-FPM tanpa request yang
 * menunggu, dan tugasnya justru menyelesaikan economic review sampai tuntas; hanya ia yang
 * memakai plafon job. Jalur web tidak dapat mencapai cabang ini: SAPI wajib cli DAN penanda job
 * wajib aktif. */
/* ==============================================================================================
 *  ADMISSION WAKTU PER TAHAP (non-latching).
 *
 *  MASALAH YANG DITUTUP. Tahap-tahap mahal (loop decommit, comparator global, pencarian offset)
 *  menjalankan PIPELINE PENUH BERSARANG per kandidat. Sebelumnya tahap itu dimulai tanpa memeriksa
 *  apakah sisa anggaran request masih dapat menampungnya, lalu dipotong di tengah oleh plafon
 *  absolut. Hasilnya adalah keluaran TERBURUK yang mungkin: pekerjaannya terbuang, plafon 60 detik
 *  tetap terlampaui, dan SELURUH request dilaporkan DEADLINE_REACHED dengan enam tahap terpotong.
 *  Terukur pada skenario Pertagas KP72 = 2,2: decommit screening 46,66 detik, run berakhir 60,21
 *  detik DEADLINE_REACHED.
 *
 *  PERBEDAAN DENGAN pp_budget_exceeded(). pp_budget_exceeded() MENCATAT abort (stages_truncated)
 *  dan dipakai ketika sebuah tahap benar-benar dihentikan di tengah jalan. Fungsi ini TIDAK
 *  mencatat apa pun: ia hanya menjawab "apakah masih cukup waktu untuk MEMULAI pekerjaan sebesar
 *  ini". Menolak memulai bukan pemangkasan hasil — pekerjaan yang ditolak diselesaikan worker
 *  asinkron, dan publish tetap diblokir sampai selesai.
 * ============================================================================================ */
const PP_STAGE_CLOSING_RESERVE_S = 4.0;
function pp_stage_admits(float $estimatedCost): bool {
    $left = pp_budget_deadline_left();
    if (!is_finite($left)) return true;                 // tanpa deadline: selalu boleh
    return $left >= ($estimatedCost + PP_STAGE_CLOSING_RESERVE_S);
}
/* Mencatat bahwa sebuah tahap DITUNDA karena anggaran waktu — bukan dipotong. Penanda ini yang
 * membuat run dilaporkan belum lengkap DAN memicu penyerahan otomatis ke worker asinkron. */
function pp_stage_defer(string $stage, float $estimatedCost, array $extra = []): void {
    $left = pp_budget_deadline_left();
    $rec = ['stage' => $stage, 'estimated_cost_s' => round($estimatedCost, 2),
            'time_left_s' => is_finite($left) ? round($left, 2) : null,
            'async_completion_required' => true] + $extra;
    if (!isset($GLOBALS['__pp_stages_deferred']) || !is_array($GLOBALS['__pp_stages_deferred']))
        $GLOBALS['__pp_stages_deferred'] = [];
    $GLOBALS['__pp_stages_deferred'][$stage] = $rec;
    if (($GLOBALS['__pp_econ_review_skipped'] ?? null) === null)
        $GLOBALS['__pp_econ_review_skipped'] = ['reason' => 'TAHAP_DITUNDA_ANGGARAN_WAKTU:' . $stage,
            'async_completion_required' => true] + $rec;
}
function pp_budget_ceiling(): float {
    /* Plafon job berlaku untuk worker CLI lama DAN untuk job yang kini dijalankan di dalam request
     * (`mode=job_exec`, konstanta `PP_INPROC_JOB` — konstanta karena global `__pp_*` dibersihkan antar-kandidat). Tanpa pengecualian kedua, job in-request
     * dibatasi plafon request web 60 detik dan berakhir tidak konvergen — terukur: LNG 6,7385 pada
     * PGN 25 berhenti di gas 66,4451/66,3385 (residual 0,1066), sedangkan worker CLI baseline
     * dengan plafon job mencapai 66,3361 (residual 0). Request web biasa tetap memakai 60 detik. */
    if (PHP_SAPI !== 'cli' && !defined('PP_INPROC_JOB')) return 60.0;
    if (empty($GLOBALS['__pp_async_worker'])) return 60.0;
    $c = (float)($GLOBALS['__pp_async_worker_ceiling'] ?? 900.0);
    return max(60.0, min(3600.0, $c));
}
function pp_budget_deadline_left(): float {
    return isset($GLOBALS['__pp_budget_deadline'])
        ? (float)$GLOBALS['__pp_budget_deadline'] - microtime(true) : INF;
}
function pp_budget_elapsed(): float {
    return isset($GLOBALS['__pp_budget']['t0']) ? (microtime(true) - (float)$GLOBALS['__pp_budget']['t0']) : 0.0;
}
function pp_budget_left(): float {
    if (!isset($GLOBALS['__pp_budget']['lim'])) return INF;
    return (float)$GLOBALS['__pp_budget']['lim'] - pp_budget_elapsed();
}
function pp_budget_exceeded(?string $where = null): bool {
    /* Absolute ceiling wins over the adaptive ladder AND over any budget reset below it. */
    if (pp_budget_deadline_left() <= 0.0) {
        if (isset($GLOBALS['__pp_budget'])) {
            $GLOBALS['__pp_budget']['latched'] = true;
            $GLOBALS['__pp_budget']['hits'] = 1;
            $GLOBALS['__pp_budget']['notes'] = ['absolute_deadline' . ($where !== null ? ':' . $where : '')];
        }
        $k = 'absolute_deadline:' . (string)($where ?? '?');
        $GLOBALS['__pp_budget_aborts'][$k] = (int)($GLOBALS['__pp_budget_aborts'][$k] ?? 0) + 1;
        return true;
    }
    if(!isset($GLOBALS['__pp_budget']['lim']))return false;
    if(!empty($GLOBALS['__pp_budget']['latched']))return true;
    if(pp_budget_left()>0)return false;
    $cur=(float)$GLOBALS['__pp_budget']['lim'];
    $max=(float)($GLOBALS['__pp_budget']['adaptive_max']??60.0);
    $step=(float)($GLOBALS['__pp_budget']['adaptive_step']??5.0);
    if($cur<$max-1e-9){
        $next=min($max,$cur+$step);
        $GLOBALS['__pp_budget']['lim']=$next;
        $GLOBALS['__pp_budget']['extensions'][]=['from'=>$cur,'to'=>$next,'where'=>$where,'elapsed'=>pp_budget_elapsed()];
        return false;
    }
    $GLOBALS['__pp_budget']['latched']=true;$GLOBALS['__pp_budget']['hits']=1;
    if($where!==null)$GLOBALS['__pp_budget']['notes']=[$where];
    /* Catat TAHAP mana yang dipotong, supaya status hasil tidak bisa mengklaim konvergensi. */
    $k = 'time_budget:' . (string)($where ?? '?');
    $GLOBALS['__pp_budget_aborts'][$k] = (int)($GLOBALS['__pp_budget_aborts'][$k] ?? 0) + 1;
    return true;
}
/* Oscillation / repeated-state: kembalikan TRUE bila signature $sig sudah pernah muncul pada $key
 * (loop berputar di state yang sama -> hentikan, tak ada progres nyata). */
function pp_budget_repeat(string $key, string $sig, int $keep = 64): bool {
    if (!isset($GLOBALS['__pp_budget'])) return false;
    $bucket = &$GLOBALS['__pp_budget']['states'][$key];
    if (!is_array($bucket)) $bucket = [];
    if (isset($bucket[$sig])) return true;
    if (count($bucket) >= $keep) array_shift($bucket);
    $bucket[$sig] = 1;
    return false;
}
function pp_budget_report(): array {
    if (!isset($GLOBALS['__pp_budget'])) return ['limit_s' => null, 'elapsed_s' => 0.0, 'exceeded' => false, 'hits' => 0, 'where' => []];
    return [
        'limit_s' => (float)$GLOBALS['__pp_budget']['lim'],
        'elapsed_s' => round(pp_budget_elapsed(), 3),
        'exceeded' => (int)($GLOBALS['__pp_budget']['hits'] ?? 0) > 0,
        'hits' => (int)($GLOBALS['__pp_budget']['hits'] ?? 0),
        'where' => array_values(array_unique($GLOBALS['__pp_budget']['notes'] ?? [])),
    ];
}
/* Signature ringan utk deteksi oscillation pada baris dispatch (dibulatkan ke 0.1 MW). */
function pp_rows_signature(array $genRows, array $units = ['g1','g2','g3','g5','g8','g9','b1','b2']): string {
    $acc = '';
    foreach ($genRows as $g) { foreach ($units as $u) $acc .= (string)round((float)($g[$u] ?? 0), 1) . ','; $acc .= ';'; }
    return substr(hash('crc32b', $acc), 0, 8) . ':' . strlen($acc);
}

/* ===== STRICT PLN EXPORT FLOOR REPAIR (PROMPT SAME-BLOCK/EXPORT-FLOOR §6) =====================
 * Export floor adalah hard constraint PRIORITAS 1: setiap row wajib Export >= Effective Range Min.
 * Dipanggil worker02 SETELAH seluruh re-assertion & export-ramp repair. Lever berurutan (§6):
 *   L1  naikkan unit NON-GAS (Babelan/coal) pada row defisit — tidak menambah gas sama sekali,
 *   L2  naikkan unit gas yang masih ber-headroom bila window gas masih menyisakan ruang,
 *   L3  ANTICIPATORY STARTUP SHIFT: majukan startup unit yang sedang/akan start sehingga pada row
 *       defisit unit sudah mencapai step sequence yang lebih tinggi (5->15->20...), lalu biayai
 *       tambahan gas-nya dgn menurunkan unit gas pada row yang punya MARGIN export (gas-neutral).
 * Invarian: proxy gas harian TIDAK boleh naik (rencana sudah berada di window maksimum), Export Range
 * kedua sisi, Export Ramp <= 30, Bus Flow >= min, ramp unit, fixed/actual/stop, startup sequence
 * (dibangun ulang via pp_start_sequence) tetap dihormati. Tidak ada nama unit yang di-hardcode. */
/* Plafon TEGAS kenaikan gas yang boleh dipakai perbaikan Export floor ketika rencana SUDAH
 * berada di atas batas atas window kuota gas (lihat BUG-15). Kecil dan terbatas: ia hanya untuk
 * menutup kekurangan Export beberapa ratus kW, bukan untuk menggeser rencana. */
if (!defined('PP_EXPORT_FLOOR_GAS_ALLOWANCE_BBTUD')) define('PP_EXPORT_FLOOR_GAS_ALLOWANCE_BBTUD', 0.05);

function pp_export_floor_repair(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                                float $rMin, float $rMax, array &$evidence, float $gasReduceTarget = 0.0,
                                bool $finalPass = false): int {
    /* $finalPass = true HANYA pada pemanggilan TERAKHIR (final constraint repair, setelah seluruh
     * tahap yang dapat mengubah dispatch). Dua perilaku baru — langkah halus (BUG-14) dan
     * kelonggaran gas ketika rencana sudah di atas window (BUG-15) — hanya aktif di sana.
     * Pemanggilan di tengah pipeline berjalan PERSIS seperti V3, sehingga dispatch input yang
     * selama ini sudah benar tidak bergeser dan jumlah core run tidak bertambah. */
    $n = count($genRows); if ($n === 0 || !is_finite($rMin)) return 0;
    /* Row-specific effective Export bounds. range_rules override the main range on their rows. */
    $rMinR=array_fill(0,$n,$rMin);$rMaxR=array_fill(0,$n,$rMax);
    foreach((array)($model['pln_export_priority']['range_rules']??[]) as $rr){
        $rs=max(1,(int)($rr['start']??1));$re=min($n,(int)($rr['stop']??$n));
        $lo=max($rMin,(float)($rr['min']??$rMin));$hi=min($rMax,(float)($rr['max']??$rMax));
        for($ix=$rs-1;$ix<=$re-1;$ix++){$rMinR[$ix]=$lo;$rMaxR[$ix]=$hi;}
    }
    $step=pp_export_step_limit($model);
    for($pass=0;$pass<$n;$pass++){$changed=false;for($ix=0;$ix<$n;$ix++){
        $lo=$rMinR[$ix];if($ix>0)$lo=max($lo,$rMinR[$ix-1]-$step);if($ix<$n-1)$lo=max($lo,$rMinR[$ix+1]-$step);
        $lo=min($lo,$rMaxR[$ix]);if($lo>$rMinR[$ix]+1e-9){$rMinR[$ix]=$lo;$changed=true;}
    }if(!$changed)break;}
    $minAt=static fn(int $r):float=>(float)$rMinR[$r];$maxAt=static fn(int $r):float=>(float)$rMaxR[$r];
    $GAS = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $busUnit = $model['bus_unit'] ?? [];
    $busMin = (float)($model['busflow_min'] ?? 0);
    $allowGrossIncreaseForDistillate=(($model['gas_shortage_action']??'none')==='use_distillate');
    /* PERF: Export(row) is a PURE function of that row plus the constant $ieVals[$r], yet it is the
     * single most-called expression in the repair (donor scan, ramp check, rowOK, evidence). The memo
     * is validated by array identity: PHP compares two identical hashtables in O(1), so an unchanged
     * row returns the byte-identical cached value and a changed row recomputes. No invalidation hook
     * is needed anywhere, which is why this cannot drift from the original semantics. */
    $expMemoRow = []; $expMemoVal = [];
    $expOf = function (int $r) use (&$genRows, $ieVals, &$expMemoRow, &$expMemoVal): float {
        $g = $genRows[$r];
        if (isset($expMemoRow[$r]) && $expMemoRow[$r] === $g) return $expMemoVal[$r];
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        $tot = 0.0; foreach ($g as $k => $v) if (is_numeric($v)) $tot += (float)$v;
        $val = $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
        $expMemoRow[$r] = $g; $expMemoVal[$r] = $val;
        return $val;
    };
    /* PERF: 48 rows x 10 units of calc_fuel() per call, and it is re-evaluated several times between
     * two consecutive writes to $genRows. Same identity-validated memo as $expOf. */
    $gpMemoRows = null; $gpMemoVal = 0.0;
    /* V4 OPT-G1: bahan bakar per unit per row disimpan per isi row (===); penjumlahan tetap
     * satu akumulator dengan urutan row x unit yang sama persis -> hasil bit-identik. */
    $gpRowKey = []; $gpRowF = [];
    $gasProxy = function () use (&$genRows, $d3, $GAS, &$gpMemoRows, &$gpMemoVal, &$gpRowKey, &$gpRowF): float {
        if ($gpMemoRows !== null && $gpMemoRows === $genRows) return $gpMemoVal;
        $s = 0.0;
        foreach ($genRows as $ri => $g) {
            if (isset($gpRowKey[$ri]) && $gpRowKey[$ri] === $g) { foreach ($gpRowF[$ri] as $fv) $s += $fv; continue; }
            $fs = []; foreach ($GAS as $u) { $fv = calc_fuel($d3, $u, (float)($g[$u] ?? 0)); $fs[] = $fv; $s += $fv; }
            $gpRowKey[$ri] = $g; $gpRowF[$ri] = $fs;
        }
        $gpMemoRows = $genRows; $gpMemoVal = $s / 2.0;
        return $gpMemoVal;
    };
    /* PERF: pp_export_step_limit() ignores its argument and returns a constant, and $expOf($r) was
     * re-evaluated once per neighbour. Both are hoisted; the comparison is unchanged. */
    $stepLim = pp_export_step_limit($model) + 1e-6;
    $rampOK = function (int $r) use (&$genRows, $expOf, $n, $stepLim): bool {
        $e = $expOf($r);
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            if (abs($e - $expOf($nb)) > $stepLim) return false; }
        return true;
    };
    /* PERF: calc_busflow() is, like Export, a pure function of the row (with $busUnit and $ieVals
     * constant), and $rowOK is evaluated after every trial mutation and every revert. Same
     * identity-validated memo as $expOf -- an unchanged row returns the identical cached value. */
    $busMemoRow = []; $busMemoVal = [];
    $rowOK = function (int $r) use ($expOf, $minAt, $maxAt, $rampOK, &$genRows, $busUnit, $busMin, $ieVals,
                                    &$busMemoRow, &$busMemoVal, $rMinR, $rMaxR): bool {
        $e = $expOf($r);
        if ($e < (float)$rMinR[$r] - 1e-9 || $e > (float)$rMaxR[$r] + 1e-6) return false;
        if ($busMin > 0) {
            $gr = $genRows[$r];
            if (isset($busMemoRow[$r]) && $busMemoRow[$r] === $gr) { $bf = $busMemoVal[$r]; }
            else { $bf = calc_busflow($gr, $busUnit, (float)$ieVals[$r]); $busMemoRow[$r] = $gr; $busMemoVal[$r] = $bf; }
            if ($bf < $busMin - 1e-6) return false;
        }
        return $rampOK($r);
    };
    $capOf = function (string $u, int $r) use ($d3, $model, &$genRows, $n): float {
        $isBB = ($u === 'b1' || $u === 'b2');
        $cap = pp_effective_maxload($d3, $model, $u, $r + 1);
        if ($cap <= 0) $cap = (float)($d3[$u]['max_load'] ?? 0);
        $lim = $isBB ? pp_babelan_ramp_limit($model) : 30.0;
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            $cap = min($cap, (float)($genRows[$nb][$u] ?? 0) + $lim); }
        return $cap;
    };
    /* PERF: pure in $u ($d3 is captured by value); called inside the innermost donor loop. */
    $minOfMemo = [];
    $minOf = function (string $u) use ($d3, &$minOfMemo): float {
        return $minOfMemo[$u] ??= (($u === 'b1' || $u === 'b2')
            ? 10.0 : (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20)));
    };
    /* STARTUP-WINDOW LOCK: nilai di dalam startup sequence (mis. 5,15,20) adalah hard constraint —
     * L1/L2/donor DILARANG menyentuhnya (mengubah 5 -> 5.5 membuat row berikutnya dianggap di luar
     * sequence dan melanggar minimum load). Hanya L3 yang boleh menulis ulang window secara utuh. */
    $suLockF = [];
    foreach (array_merge($GAS, ['b1','b2']) as $uS) {
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($uS)] ?? '')) === 'running');
        for ($rr = 0; $rr < $n; $rr++) {
            $on = ((float)($genRows[$rr][$uS] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $sq = pp_start_sequence($d3, $model, $uS, $genRows, $rr);
                for ($k = 0; $k < count($sq) && $rr + $k < $n; $k++) $suLockF[$uS][$rr + $k] = true;
            }
            $prevOn = $on;
        }
    }
    /* PERF: $model, $d3, $actualRows and $suLockF are captured BY VALUE and are never written to
     * inside this function, so (unit,row) fully determines the answer. The domain is at most
     * ~16 units x 48 rows, while the donor loop asks the same question tens of thousands of times
     * (this is where pp_is_unit_stopped/pp_effective_unit_available dominated the profile). */
    /* V4 OPT-X1: memo dua dimensi [unit][row] (tanpa pembentukan string kunci); loop donor/refill
     * membaca memo langsung sebelum memanggil closure. Nilai identik: fungsi murni per (unit,row). */
    $writableMemo = [];
    $writable = function (string $u, int $r) use ($model, $d3, $actualRows, $suLockF, &$writableMemo): bool {
        if (isset($writableMemo[$u][$r])) return $writableMemo[$u][$r];
        if (isset($actualRows[$r])) return $writableMemo[$u][$r] = false;
        if (isset($suLockF[$u][$r])) return $writableMemo[$u][$r] = false;  // startup window: beku
        if (pp_get_fixed_load($model, $u, $r + 1) >= 0) return $writableMemo[$u][$r] = false;
        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return $writableMemo[$u][$r] = false;
        return $writableMemo[$u][$r] = true;
    };
    /* Donor gas: turunkan unit gas pada row bermargin sampai proxy kembali <= target.
     * DUA TAHAP:
     *   (1) single-row donor  — turunkan 0.5 MW pada satu row bermargin,
     *   (2) BLOCK-RAMP DONOR  — bila single-row selalu ditolak karena rantai Export Ramp (tetangga
     *       jauh di atas), turunkan SEKUMPULAN row BERURUTAN sekaligus sehingga ramp internal blok
     *       tetap sah dan hanya batas luar blok yang perlu lolos. Total gas harian tetap dijaga
     *       (target), Range Min tiap row donor dijaga, unit ramp & startup window dihormati,
     *       Babelan TIDAK dipakai sebagai lever (tetap Priority-1 baseload). */
    /* PERF -- REPLAY CACHE FOR A DETERMINISTIC SEARCH.
     * donate() is deterministic in (grid, target): every other input it reads ($d3, $model, $GAS,
     * $actualRows, ...) is captured BY VALUE, and every trial it rejects is reverted. Measured on
     * input_data.json: 9,155 calls per simulation but only 1,106 distinct (grid,target) states --
     * 88% of the calls re-run a search whose answer is already known -- and the function accounts
     * for 25.6s of a 53s run. The cache stores the ENTRY grid, the EXIT grid and the return value,
     * so a hit replays the full effect of the call, not just its boolean result. Entries are bucketed
     * by the gas proxy (already computed on the line above) and confirmed by array identity, so a
     * collision can never return another state's answer. Calls that stop on the time budget are
     * NOT cached, because that exit is wall-clock dependent rather than deterministic. */
    $donateMemo = []; $donateMemoN = 0;
    /* OPT-D1 — percobaan donor yang GAGAL bergantung hanya pada isi row yang disentuhnya beserta
     * tetangga yang dibaca rowOK/rampOK (±1 row untuk satu row, ±2 row untuk blok), unit, dan besar
     * langkah; konteks lain (writable, batas Export, IE, Bus Flow) tetap selama satu pemanggilan
     * repair. Kegagalan pada isi yang identik (===) pasti berulang, jadi tidak dicoba ulang. Percobaan
     * yang berhasil selalu dijalankan apa adanya. */
    $srFail = []; $blkFail = [];
    $donate = function (float $target) use (&$genRows, $d3, $model, $GAS, $n, $expOf, $minAt, $rowOK, $gasProxy, $writable, $minOf, $actualRows, $allowGrossIncreaseForDistillate, &$donateMemo, &$donateMemoN, &$srFail, &$blkFail, &$writableMemo, $rMinR, &$expMemoRow, &$expMemoVal, &$minOfMemo): bool {
        $units = ['g9','g8','g5','g1','g2','g4','g3','g6'];
        /* EFISIENSI (bukan pelonggaran): kebutuhan gas yang harus dibebaskan dihitung SEKALI, lalu
         * dilacak secara inkremental dari delta calc_fuel setiap penurunan. Sebelumnya gasProxy()
         * (48 row x 8 unit calc_fuel) dipanggil ulang setiap iterasi sehingga repair kehabisan
         * deadline budget sebelum sempat menutup defisit — itulah penyebab EXHAUSTED palsu. */
        $gpEntry  = $gasProxy();
        $needFree = $gpEntry - $target;
        if ($needFree <= 1e-9) return true;
        $bk = (string)(int)round($gpEntry * 1e6) . '|' . (string)(int)round($target * 1e6);
        if (isset($donateMemo[$bk])) foreach ($donateMemo[$bk] as $ent) {
            if ($ent[0] === $genRows) { $genRows = $ent[1]; return $ent[2]; }   // identity-confirmed replay
        }
        $entryRows = $genRows;
        $keep = static function (bool $ret) use (&$donateMemo, &$donateMemoN, &$genRows, &$entryRows, $bk): bool {
            if ($donateMemoN < 4000) { $donateMemo[$bk][] = [$entryRows, $genRows, $ret]; $donateMemoN++; }
            return $ret;
        };
        $freed = 0.0; $dWork = 0;
        for ($guard = 0; $guard < 120; $guard++) {
            if(pp_budget_exceeded('export_floor_donate'))return false;   // wall-clock exit: never cached
            if ($freed >= $needFree - 1e-9) return $keep(true);
            if (++$dWork > 4000) return $keep(false);                 // deterministik, bukan wall-clock
            $done = false;
            /* (1) single-row, DIURUTKAN dari margin export TERBESAR dan memakai STEP BESAR lebih dulu
             * (5 -> 2 -> 0.5 MW). Row dgn margin besar (mis. pagi 108 MW vs Range Min 20) adalah donor
             * paling sah & paling efisien; langkah besar mencegah repair terjebak ribuan langkah 0.5 MW. */
            /* PERF: this list is rebuilt and re-sorted on every guard iteration. usort() with a PHP
             * comparator costs one userland call per comparison; arsort() on a row-keyed map does the
             * same ordering inside the engine. PHP >= 8.0 sorts are stable and the map is built in
             * ascending row order, so ties keep the same relative order the comparator produced. */
            $cands = [];
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) continue;
                $gX = $genRows[$r];
                $mg = ((isset($expMemoRow[$r]) && $expMemoRow[$r] === $gX) ? $expMemoVal[$r] : $expOf($r)) - (float)$rMinR[$r];
                if ($mg > 0.6) $cands[$r] = $mg;
            }
            arsort($cands);
            foreach ($cands as $r => $mg) {
                if ($done) break;
                foreach ($units as $u) {
                    if (!($writableMemo[$u][$r] ?? $writable($u, $r))) continue;
                    $cur = (float)($genRows[$r][$u] ?? 0);
                    $room = $cur - ($minOfMemo[$u] ?? $minOf($u));
                    if ($room < 0.5 - 1e-9) continue;
                    foreach ([5.0, 2.0, 0.5] as $step) {
                        $dec = min($step, $room, max(0.5, $mg));
                        if ($dec < 0.5 - 1e-9) continue;
                        /* ANTI-OVERSHOOT: jangan membebaskan gas lebih dari sisa kebutuhan supaya
                         * total gas tidak jatuh di bawah batas BAWAH strict window. */
                        $remainD = $needFree - $freed;
                        /* PERF: calc_fuel($d3,$u,$cur) does not depend on $kd but was recomputed on
                         * every halving step, and once more when the step was accepted. Hoisted. */
                        $fuelCur = calc_fuel($d3, $u, $cur);
                        for ($kd = 0; $kd < 8; $kd++) {
                            $dFd = ($fuelCur - calc_fuel($d3, $u, $cur - $dec)) / 2.0;
                            if ($dFd <= $remainD + 0.002) break;
                            $dec = round($dec / 2.0, 3);
                            if ($dec < 0.1) break;
                        }
                        if ($dec < 0.1) continue;
                        $srK = $r . '|' . $u . '|' . pack('d', $dec);
                        $srCtx = [$r > 0 ? $genRows[$r - 1] : null, $genRows[$r], $r < $n - 1 ? $genRows[$r + 1] : null];
                        if (isset($srFail[$srK]) && $srFail[$srK] === $srCtx) continue;
                        $sv = $genRows[$r]; $genRows[$r][$u] = $cur - $dec;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if (!$rowOK($r)) { $genRows[$r] = $sv; $srFail[$srK] = $srCtx; /* E10 site 1: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue; }
                        $freed += ($fuelCur - calc_fuel($d3, $u, $cur - $dec)) / 2.0;
                        $done = true; break;
                    }
                    if ($done) break;
                }
            }
            if ($done) continue;
            /* (2) block-ramp donor: cari blok row berurutan bermargin, turunkan bersama */
            /* V4 OPT-X2: kelayakan row blok (margin Export) dihitung sekali per iterasi guard.
             * Selama pemindaian blok, $genRows hanya berubah lewat percobaan yang langsung
             * dipulihkan (zval yang sama) atau percobaan sukses yang mengakhiri pemindaian, sehingga
             * nilainya identik dengan evaluasi per blok. */
            $expLow = []; $badPre = [0];
            for ($q = 0; $q < $n; $q++) { $expLow[$q] = $expOf($q) < (float)$rMinR[$q] + 0.6; $badPre[$q + 1] = $badPre[$q] + ((isset($actualRows[$q]) || $expLow[$q]) ? 1 : 0); }
            for ($start = 0; $start < $n && !$done; $start++) {
                if ($expLow[$start]) continue;
                for ($len = 2; $len <= 12 && $start + $len - 1 < $n; $len++) {
                    $end = $start + $len - 1;
                    $okBlock = ($badPre[$end + 1] - $badPre[$start]) === 0;
                    if (!$okBlock) continue;
                    $bLo = max(0, $start - 2); $bHi = min($n - 1, $end + 2);
                    $bCtx = array_slice($genRows, $bLo, $bHi - $bLo + 1);
                    foreach ($units as $u) {
                        $bK = $start . '|' . $len . '|' . $u;
                        if (isset($blkFail[$bK]) && $blkFail[$bK] === $bCtx) { $freedBlk = 0.0; continue; }
                        $svBlock = [];
                        $applied = 0;
                        for ($rr = $start; $rr <= $end; $rr++) {
                            if (!($writableMemo[$u][$rr] ?? $writable($u, $rr))) { $applied = -1; break; }
                            $cur = (float)($genRows[$rr][$u] ?? 0);
                            if ($cur < ($minOfMemo[$u] ?? $minOf($u)) + 0.5 - 1e-9) { $applied = -1; break; }
                            $svBlock[$rr] = $genRows[$rr];
                            $genRows[$rr][$u] = $cur - 0.5;
                            pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                            $freedBlk = ($freedBlk ?? 0.0) + (calc_fuel($d3, $u, $cur) - calc_fuel($d3, $u, $cur - 0.5)) / 2.0;
                            $applied++;
                        }
                        if ($applied <= 0) {
                            foreach ($svBlock as $rr => $sv) { $genRows[$rr] = $sv; /* E10 site 2: recompute pasca-restore DIHAPUS (terbukti redundan) */ }
                            $freedBlk = 0.0; $blkFail[$bK] = $bCtx;
                            continue;
                        }
                        /* validasi: seluruh row blok + satu row di kedua sisi (batas luar) */
                        $good = true;
                        for ($rr = max(0, $start - 1); $rr <= min($n - 1, $end + 1); $rr++)
                            if (!isset($actualRows[$rr]) && !$rowOK($rr)) { $good = false; break; }
                        if (!$good) {
                            foreach ($svBlock as $rr => $sv) { $genRows[$rr] = $sv; /* E10 site 3: recompute pasca-restore DIHAPUS (terbukti redundan) */ }
                            $freedBlk = 0.0; $blkFail[$bK] = $bCtx;
                            continue;
                        }
                        $freed += (float)($freedBlk ?? 0.0); $freedBlk = 0.0;
                        $done = true; break;
                    }
                    if ($done) break;
                }
            }
            if (!$done) return $keep($gasProxy() <= $target + 1e-9);
        }
        return $keep($gasProxy() <= $target + 1e-9);
    };

    /* REFILL — cermin donate(): bila repair membuat proxy gas TURUN di bawah baseline, kelebihan
     * penurunan WAJIB dikembalikan supaya total gas harian tetap di dalam strict window
     * [quota-0.04, quota]. Tanpa ini, repair bisa membuat gas jatuh di bawah batas bawah window
     * (bug nyata: hari-1 weekly 84.9497 < 84.9600). Guard sama: Range Min/Max, ramp export, ramp
     * unit, BusFlow, fixed/actual/stop, startup window; Babelan tidak dipakai sebagai lever. */
    $refill = function (float $target) use (&$genRows, $d3, $model, $n, $expOf, $minAt, $maxAt, $rowOK, $gasProxy, $writable, $capOf, $actualRows, $allowGrossIncreaseForDistillate, &$writableMemo, $rMaxR): bool {
        $units = ['g9','g8','g5','g2','g4','g1','g3','g6'];
        $need = $target - $gasProxy();
        if ($need <= 1e-9) return true;
        $added = 0.0; $rWork = 0;
        $liftGuard=0;
        while ($added < $need - 1e-9 && $liftGuard++ < 120) {
            if (pp_budget_exceeded('export_floor_lift')) break;
            if (++$rWork > 4000) return false;
            $done = false;
            $cands = [];
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) continue;
                $head = (float)$rMaxR[$r] - $expOf($r);
                if ($head > 0.6) $cands[] = [$r, $head];
            }
            usort($cands, fn($x, $y) => $y[1] <=> $x[1]);
            foreach ($cands as [$r, $head]) {
                if ($done) break;
                foreach ($units as $u) {
                    if (!($writableMemo[$u][$r] ?? $writable($u, $r))) continue;
                    $cur = (float)($genRows[$r][$u] ?? 0);
                    if ($cur < 0.01) continue;                          // jangan start unit baru di sini
                    $cap = $capOf($u, $r); $room = $cap - $cur;
                    if ($room < 0.5 - 1e-9) continue;
                    foreach ([5.0, 2.0, 0.5] as $step) {
                        $inc = min($step, $room, max(0.5, $head));
                        if ($inc < 0.5 - 1e-9) continue;
                        /* ANTI-OVERSHOOT: perkecil increment sampai delta fuel <= sisa kebutuhan,
                         * supaya total gas tidak melewati batas ATAS strict window. */
                        $remain = $need - $added;
                        for ($k = 0; $k < 8; $k++) {
                            $dF = (calc_fuel($d3, $u, $cur + $inc) - calc_fuel($d3, $u, $cur)) / 2.0;
                            if ($dF <= $remain + 1e-9) break;
                            $inc = round($inc / 2.0, 3);
                            if ($inc < 0.1) break;
                        }
                        if ($inc < 0.1) continue;
                        $dF = (calc_fuel($d3, $u, $cur + $inc) - calc_fuel($d3, $u, $cur)) / 2.0;
                        if ($dF > $remain + 1e-9) continue;
                        $sv = $genRows[$r]; $genRows[$r][$u] = $cur + $inc;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if (!$rowOK($r)) { $genRows[$r] = $sv; /* E10 site 4: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue; }
                        $added += $dF;
                        $done = true; break;
                    }
                    if ($done) break;
                }
            }
            if (!$done) return false;
        }
        return true;
    };

    $fixed = 0; $exhaustedRows = [];
    $GLOBALS['__pp_export_floor_blockers'] = [];     // conflict set lantai Export, dibangun ulang tiap pass
    /* BUG-14 — dua TINGKAT presisi langkah, agar perilaku lama tidak bergeser.
     * Tingkat 1 (default) memakai ambang 0,5 MW persis seperti V3, sehingga seluruh input yang
     * selama ini sudah tertangani menghasilkan dispatch yang IDENTIK. Tingkat 2 hanya dipakai
     * pada row yang benar-benar gagal di tingkat 1 DAN kebutuhannya di bawah 0,5 MW: di situ
     * ambang diturunkan ke kebutuhan nyata. Tanpa dua tingkat ini, langkah halus ikut dipakai
     * pada row yang sudah berhasil dan menggeser dispatch input lain tanpa perlu. */
    $fineRows = [];
    /* ANCHOR GAS TUNGGAL (WEEKLY §2/§4): satu pass memperbaiki export floor SEKALIGUS menurunkan
     * total gas sebesar $gasReduceTarget (bila diminta outer loop karena accounted gas over-quota).
     * Memakai dua pass terpisah (trim lalu floor repair) menyebabkan osilasi: floor repair menaikkan
     * gas lagi ke baseline-nya sendiri. Dengan anchor tunggal, seluruh lever & donor bekerja menuju
     * target yang sama. */
    $proxyEntry = $gasProxy();
    $proxy0 = $proxyEntry - max(0.0, $gasReduceTarget);
    /* Apakah rencana SUDAH di ATAS batas atas window kuota gas SEBELUM perbaikan dimulai?
     * Dihitung dengan metrik yang sama dengan validator (startup penalty + seluruh unit gas
     * Jababeka dan MM2100), lalu dibandingkan terhadap window kuota total. Dievaluasi SEKALI. */
    $overWinBase = false; $overWinGas0 = null; $overWinHi = null;
    {
        $qAll = pp_gas_quota_jababeka_bbtud($model) + pp_mm2100_quota_energy_bbtud($model);
        if ($qAll > 1.0) {
            $gNow = pp_startup_gas_penalty($genRows, $model, true);
            foreach ($genRows as $grQ)
                foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $uQ)
                    $gNow += calc_fuel($d3, $uQ, (float)($grQ[$uQ] ?? 0)) / 2.0;
            [, $hiQ] = pp_gas_window($qAll);
            $overWinBase = ($gNow > $hiQ + 1e-9);
            $overWinGas0 = $gNow; $overWinHi = $hiQ;
        }
    }
    /* GAS TERHITUNG DENGAN METRIK VALIDATOR, dihitung ulang saat dibutuhkan. Sengaja memakai metrik
     * yang SAMA dengan $overWinGas0 di atas (startup penalty + seluruh unit gas Jababeka dan MM2100)
     * supaya gerbang di bawah menilai angka yang sama dengan yang dinilai validator, bukan proxy. */
    $gasAccounted = function () use (&$genRows, $d3, $model): float {
        $g = pp_startup_gas_penalty($genRows, $model, true);
        foreach ($genRows as $gr)
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
                $g += calc_fuel($d3, $u, (float)($gr[$u] ?? 0)) / 2.0;
        return $g;
    };
    if ($gasReduceTarget > 1e-9)
        $evidence[] = ['lever' => 'GAS_ANCHOR_LOWERED', 'proxy_entry' => round($proxyEntry, 4),
                       'reduce_target' => round($gasReduceTarget, 4), 'anchor' => round($proxy0, 4)];
    /* DETERMINISME (WEEKLY §6): budget repair ini WAJIB deterministik — dibatasi hitungan kerja,
     * BUKAN wall-clock. Guard berbasis waktu membuat output bergantung kecepatan/muatan mesin
     * (hasil berbeda antar run = checksums != 1). Wall-clock hanya dipakai sebagai jaring terakhir
     * di level pipeline (pp_budget_*), bukan di dalam loop yang menentukan hasil. */
    $workCap = (($model['gas_shortage_action']??'none')==='use_distillate') ? 160 : 128; $work = 0;
    for ($pass = 0; $pass < 400; $pass++) {
        if ($work >= $workCap) break;
        $r = -1;
        for ($i = 0; $i < $n; $i++)
            if (!isset($actualRows[$i]) && $expOf($i) < $minAt($i) - 1e-9 && empty($exhaustedRows[$i])) { $r = $i; break; }
        if ($r < 0) break;
        $need = $minAt($r) - $expOf($r) + 0.05;
        $before = $expOf($r);
        $moved = false;

        /* L1 — unit non-gas (coal) lebih dulu: sama sekali tidak menambah gas. */
        foreach (['b2','b1'] as $u) {
            if ($moved) break;
            if (!$writable($u, $r)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0); if ($cur < 0.01) continue;   // penempatan startup bukan urusan pass ini
            /* BUG-14 — AMBANG LANGKAH 0,5 MW MEMBLOKIR PERBAIKAN YANG HANYA BUTUH 0,15 MW.
             * Ketiga lever di bawah dulu menolak unit yang sisa headroom-nya < 0,5 MW, walaupun
             * kekurangan Export pada row itu hanya 0,15 MW dan headroom yang tersedia 0,44 MW.
             * Akibatnya row 22 (11:00) tetap 24,85 < Range Min 25,00 padahal G1 masih punya ruang
             * legal lebih dari cukup. Ambangnya kini KEBUTUHAN NYATA (dibatasi 0,5 MW di atas dan
             * 0,01 MW di bawah), bukan konstanta. Range Min TIDAK diubah, toleransi TIDAK diperbesar,
             * dan seluruh guard $rowOK (ramp, min/max, BusFlow, reserve, fixed/actual/stop) tetap
             * dijalankan persis seperti sebelumnya. */
            $minRoom = empty($fineRows[$r]) ? 0.5 : min(0.5, max(0.01, $need));
            $cap = $capOf($u, $r); if ($cap - $cur < $minRoom - 1e-9) continue;
            $sv = $genRows[$r]; $genRows[$r][$u] = min($cap, $cur + max($minRoom, min($need, $cap - $cur)));
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            if (!$rowOK($r)) { $genRows[$r] = $sv; /* E10 site 5: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue; }
            $moved = true; $evidence[] = ['row' => $r + 1, 'lever' => 'L1_NON_GAS_UP', 'unit' => strtoupper($u),
                                          'export_before' => round($before, 2), 'export_after' => round($expOf($r), 2)];
        }
        /* L2 — unit gas ber-headroom, hanya bila proxy gas masih di bawah baseline. */
        if (!$moved) {
            foreach (['g9','g8','g5','g1','g2','g4','g3','g6'] as $u) {
                if ($moved) break;
                if (!$writable($u, $r)) continue;
                $cur = (float)($genRows[$r][$u] ?? 0); if ($cur < 0.01) continue;
                $minRoom = empty($fineRows[$r]) ? 0.5 : min(0.5, max(0.01, $need));   // BUG-14
                $cap = $capOf($u, $r); if ($cap - $cur < $minRoom - 1e-9) continue;
                $step2 = max($minRoom, min($need, $cap - $cur));        // langkah = KEBUTUHAN, bukan 0.5 MW
                $sv = $genRows[$r]; $genRows[$r][$u] = min($cap, $cur + $step2);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $work += 2;
                /* BUG-15 — GERBANG NETRALITAS GAS YANG MOOT.
                 * Lever ini menolak kenaikan gas sekecil apa pun agar window kuota tidak tertembus.
                 * Tetapi bila rencana SUDAH berada di ATAS batas atas window, window itu memang
                 * sudah tertembus: menolak perbaikan tidak menyelamatkan apa pun, ia hanya
                 * MENAMBAH pelanggaran kedua (export_range) di atas pelanggaran gas yang sudah ada.
                 * Pada input operator: gas 64,2977 vs window [59,5600–59,6000] — sudah 4,70 BBTUD
                 * di atas — sementara perbaikan Export row 22 hanya menambah orde 0,001 BBTUD.
                 * Karena itu, HANYA ketika gas sudah di atas window, kenaikan diizinkan dengan
                 * plafon tegas dan dicatat sebagai bukti. Bila gas masih DI DALAM window, gerbang
                 * lama berlaku persis seperti semula. */
                $gasNow = $gasProxy();
                $overWin = ($finalPass && $overWinBase);
                /* BUG-16 — GERBANG NETRALITAS GAS MEMBLOKIR PERBAIKAN YANG MASIH DI DALAM WINDOW.
                 * Gerbang lama hanya mengizinkan kenaikan bila gas tetap <= proxy BASELINE. Itu
                 * surrogate yang lebih ketat daripada aturan bisnisnya: constraint yang sebenarnya
                 * adalah WINDOW KUOTA, bukan "tidak boleh lebih dari baseline". Akibatnya sebuah
                 * perbaikan yang sah menjadi mustahil.
                 *
                 * TERUKUR pada band G9 66-70, row 22 (11:00): Export 24,85 < Range Min 25,00,
                 * kekurangan 0,20 MW. Satu-satunya lever adalah G1 (headroom legal 0,44 MW; BB1/BB2
                 * sudah di 120,00 = max). Menaikkan G1 0,20 MW menambah gas 0,000862 BBTUD,
                 * sementara gas berada di 64,5882 dengan batas atas window 64,6000 — ruang 0,0118
                 * BBTUD, jadi perbaikan itu hanya memakai 7,3% ruang yang tersedia dan TIDAK
                 * menembus window. Gerbang lama tetap menolaknya dan row 22 dilaporkan EXHAUSTED,
                 * sehingga satu pelanggaran export_range bertahan tanpa alasan fisik.
                 *
                 * Kenaikan kini diizinkan bila gas TERHITUNG (metrik validator, bukan proxy) tetap
                 * berada DI DALAM window. Window tidak dilebarkan, Range Min tidak diubah, dan
                 * seluruh guard $rowOK (ramp, min/max, BusFlow, startup, fixed/actual/stop) tetap
                 * berlaku persis seperti sebelumnya. Bila gas sudah di ATAS window, gerbang
                 * $overWin yang lama berlaku tanpa perubahan. */
                $inWinOK = false;
                if (!$allowGrossIncreaseForDistillate && $overWinHi !== null && !$overWinBase
                    && $gasNow > $proxy0 + 1e-9) {
                    $gAcc = $gasAccounted();
                    $inWinOK = ($gAcc <= $overWinHi + 1e-9);
                    if ($inWinOK) $evidence[] = ['row' => $r + 1, 'lever' => 'L2_GAS_UP_WITHIN_WINDOW',
                        'unit' => strtoupper($u), 'deficit_mw' => round($need, 3),
                        'gas_accounted_after' => round($gAcc, 6), 'window_hi' => round($overWinHi, 6),
                        'window_room_left' => round($overWinHi - $gAcc, 6),
                        'note' => 'kenaikan gas diizinkan karena gas terhitung tetap di dalam window kuota'];
                }
                $okGas = $allowGrossIncreaseForDistillate
                       || ($gasNow <= $proxy0 + 1e-9 && $gasNow >= $proxy0 - 0.002)
                       || $inWinOK
                       || ($overWin && $gasNow <= $proxy0 + PP_EXPORT_FLOOR_GAS_ALLOWANCE_BBTUD + 1e-9);
                if (!$rowOK($r) || !$okGas) {
                    $genRows[$r] = $sv; /* E10 site 6: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue;
                }
                if ($overWin && $gasNow > $proxy0 + 1e-9)
                    $evidence[] = ['row' => $r + 1, 'lever' => 'L2_GAS_UP_OVER_WINDOW_ALLOWANCE',
                        'unit' => strtoupper($u), 'gas_added_bbtud' => round($gasNow - $proxy0, 6),
                        'allowance_bbtud' => PP_EXPORT_FLOOR_GAS_ALLOWANCE_BBTUD,
                        'gas_total_before_bbtud' => $overWinGas0 === null ? null : round($overWinGas0, 4),
                        'gas_window_high_bbtud' => $overWinHi === null ? null : round($overWinHi, 4),
                        'note' => 'gas sudah di atas window sebelum perbaikan; menolak perbaikan hanya menambah pelanggaran export_range'];
                $moved = true; $evidence[] = ['row' => $r + 1, 'lever' => 'L2_GAS_UP', 'unit' => strtoupper($u),
                                              'export_before' => round($before, 2), 'export_after' => round($expOf($r), 2)];
            }
        }
        /* L2b — GAS-NEUTRAL CROSS-ROW SHIFT: bila window gas sudah penuh, tambahan MW di row defisit
         * dibiayai dgn MENURUNKAN unit gas pada row yang punya MARGIN export (total gas harian tetap).
         * Ini menutup kasus distribusi gas yang menumpuk di row surplus dan menelantarkan row defisit
         * (mis. continuity antar-hari mengubah bentuk dispatch). Guard: sisi donor tetap >= Range Min,
         * ramp export <= 30, BusFlow, min/max & ramp unit, fixed/actual/stop, startup window. */
        if (!$moved) {
            foreach (['g9', 'g8', 'g5', 'g1', 'g2', 'g4', 'g3', 'g6'] as $uUp) {
                if ($moved) break;
                if (!$writable($uUp, $r)) continue;
                $curU = (float)($genRows[$r][$uUp] ?? 0); if ($curU < 0.01) continue;
                $minRoomU = empty($fineRows[$r]) ? 0.5 : min(0.5, max(0.01, $need));  // BUG-14
                $capU = $capOf($uUp, $r); if ($capU - $curU < $minRoomU - 1e-9) continue;
                $stepU = max($minRoomU, min($need, $capU - $curU));      // langkah = KEBUTUHAN
                $svRow = $genRows[$r]; $work += 3;
                $genRows[$r][$uUp] = min($capU, $curU + $stepU);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $added = ($gasProxy() - $proxy0);
                $okRow = $rowOK($r);
                if ($okRow && $added > 1e-9 && !$allowGrossIncreaseForDistillate) $okRow = $donate($proxy0);       // biayai dari row bermargin
                /* NEUTRALITAS GAS KETAT: total gas harian setelah lever WAJIB kembali ke baseline
                 * (|delta| <= 0.002). Baseline sudah berada di dalam strict window, sehingga
                 * netralitas ini menjaga window pada kedua sisi (bawah maupun atas). */
                if (!$okRow || (!$allowGrossIncreaseForDistillate && ($gasProxy() > $proxy0 + 1e-9 || $gasProxy() < $proxy0 - 0.002)) || !$rowOK($r)) {
                    $genRows[$r] = $svRow; /* E10 site 7: recompute pasca-restore DIHAPUS (terbukti redundan) */
                    continue;
                }
                $moved = true;
                $evidence[] = ['row' => $r + 1, 'lever' => 'L2b_GAS_NEUTRAL_CROSS_ROW_SHIFT', 'unit' => strtoupper($uUp),
                               'export_before' => round($before, 2), 'export_after' => round($expOf($r), 2),
                               'gas_proxy_delta' => round($gasProxy() - $proxy0, 4)];
            }
        }
        /* L3 — ANTICIPATORY STARTUP SHIFT (generik): unit yang startup-nya mulai PADA/di sekitar row
         * defisit dimajukan beberapa row supaya di row defisit sudah pada step sequence lebih tinggi. */
        if (!$moved) {
            $cands = [];
            foreach (pp_priority_flat($model, '/^g\d+$/') as $u) {
                if (!isset($d3[$u])) continue;
                $f = -1;
                for ($i = 1; $i < $n; $i++)
                    if ((float)($genRows[$i - 1][$u] ?? 0) < 0.01 && (float)($genRows[$i][$u] ?? 0) >= 0.01) { $f = $i; break; }
                if ($f < 0) continue;
                /* Kandidat startup yang RELEVAN: mulai di sekitar row defisit ATAU SETELAHNYA dalam
                 * horizon dekat — startup yang dimulai setelah row defisit dapat DIMAJUKAN sehingga
                 * unit sudah berbeban pada row defisit (kasus continuity: unit baru start sore hari
                 * padahal defisit terjadi siang). */
                if ($f < max(0, $r - 6) || $f > $r + 20) continue;
                $cands[] = [$u, $f];
            }
            foreach ($cands as [$u, $f]) {
                if ($moved) break;
                $seq = pp_start_sequence($d3, $model, $u, $genRows, $f);
                if (!$seq) continue;
                $maxDelta = max(1, min(20, $f - 1));                            // boleh maju sampai row 2
                for ($delta = 1; $delta <= $maxDelta && !$moved; $delta++) {
                    $f2 = $f - $delta; if ($f2 < 1) break;
                    /* L3 menulis ULANG seluruh startup window -> lock startup tidak berlaku di sini,
                     * tetapi fixed/actual/stop tetap dihormati. */
                    $writableL3 = function (int $rr) use ($u, $model, $d3, $actualRows): bool {
                        if (isset($actualRows[$rr])) return false;
                        if (pp_get_fixed_load($model, $u, $rr + 1) >= 0) return false;
                        if (pp_is_unit_stopped($d3, $model, $u, $rr + 1)) return false;
                        return true;
                    };
                    if (!$writableL3($f2)) continue;
                    $svAll = $genRows;
                    $okShift = true;
                    /* GESER SELURUH PROFIL unit ke kiri sebesar $delta (bukan hanya menempel sequence),
                     * supaya tidak ada nilai sisa startup di luar startup window (mis. 15 MW di row
                     * setelah sequence) yang melanggar minimum load. */
                    $oldProf = [];
                    for ($rr = 0; $rr < $n; $rr++) $oldProf[$rr] = (float)($genRows[$rr][$u] ?? 0);
                    /* Ubah HANYA region startup: [f2 .. f2+len-1] = sequence, lalu [f2+len .. f+len-1]
                     * = beban pertama pasca-sequence. Row SETELAH f+len-1 TIDAK disentuh sehingga
                     * profil hilir (termasuk rencana stop unit) tetap utuh. */
                    $lenS = count($seq);
                    /* holdVal = beban PERTAMA setelah sequence lama (bukan step terakhir sequence),
                     * supaya row sisa di region2 memenuhi minimum load, bukan mewarisi nilai ramp. */
                    $holdVal = $oldProf[min($n - 1, $f + $lenS)];
                    if ($holdVal < $minOf($u) - 1e-9) $holdVal = $minOf($u);
                    for ($rr = $f2; $rr <= min($n - 1, $f + $lenS - 1); $rr++) {
                        $val = ($rr - $f2 < $lenS) ? (float)$seq[$rr - $f2] : $holdVal;
                        if (abs($val - $oldProf[$rr]) < 1e-9) continue;
                        if (!$writableL3($rr)) { $okShift = false; break; }
                        $genRows[$rr][$u] = $val;
                        pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                    }
                    if ($okShift) {
                        if(!$allowGrossIncreaseForDistillate)$donate($proxy0);              // distillate substitutes added thermal energy
                        $allOK = true;
                        for ($rr = max(0, $f2 - 1); $rr < min($n, $f + count($seq) + 1); $rr++)
                            if (!isset($actualRows[$rr]) && !$rowOK($rr)) { $allOK = false; break; }
                        if ($allOK && pp_validate_startup($genRows, $d3, $model)) $allOK = false;   // startup/min-load wajib bersih
                        /* dua sisi: tidak menambah gas DAN tidak menjatuhkan gas di bawah baseline
                         * lebih dari toleransi kecil (sisa dikoreksi refill di akhir). */
                        /* PERF: gasProxy() was evaluated twice in this one condition and a third time
                         * in the evidence below, with no write to $genRows in between. Evaluated once;
                         * the short-circuit order is preserved exactly. */
                        $gpNow = 0.0; $gasOK = false;
                        if ($allOK && $expOf($r) >= $minAt($r) - 1e-9) {
                            $gpNow = $gasProxy();
                            $gasOK = $allowGrossIncreaseForDistillate
                                  || ($gpNow <= $proxy0 + 1e-9 && $gpNow >= $proxy0 - 0.002);
                        }
                        if ($gasOK) {
                            $moved = true;
                            $evidence[] = ['row' => $r + 1, 'lever' => 'L3_ANTICIPATORY_STARTUP_SHIFT',
                                           'unit' => strtoupper($u), 'startup_row_before' => $f + 1,
                                           'startup_row_after' => $f2 + 1, 'sequence' => $seq,
                                           'export_before' => round($before, 2), 'export_after' => round($expOf($r), 2),
                                           'gas_proxy_delta' => round($gpNow - $proxy0, 4)];
                            break;
                        }
                    }
                    $genRows = $svAll;                                           // revert penuh
                }
            }
        }
        /* L4 — EXPORT-FLOOR START (same-block dulu, cross-block hanya bila same-block ditolak):
         * bila SELURUH unit running di row defisit sudah di maksimum (L1/L2/L2b gagal) dan tidak ada
         * startup yang bisa dimajukan (L3 gagal), satu-satunya lever fisik adalah MENYALAKAN SATU unit
         * tambahan yang menutupi seluruh window defisit. Gas tambahannya dibiayai donor (total gas tetap
         * di window). Startup sequence ditulis utuh via pp_start_sequence dan divalidasi. */
        if (!$moved) {
            $winS = $r; while ($winS > 0 && !isset($actualRows[$winS - 1]) && $expOf($winS - 1) < $minAt($winS - 1) - 1e-9) $winS--;
            $winE = $r; while ($winE < $n - 1 && !isset($actualRows[$winE + 1]) && $expOf($winE + 1) < $minAt($winE + 1) - 1e-9) $winE++;
            $stgOfU = function (string $u) use ($d3) { return pp_gtg_to_stg($d3, $u) ?: ''; };
            $stgRunning = function (string $stg) use (&$genRows, $n) {
                if ($stg === '') return false;
                for ($i = 0; $i < $n; $i++) if ((float)($genRows[$i][$stg] ?? 0) > 0.01) return true;
                return false;
            };
            $same = []; $cross = []; $rejL4 = [];
            foreach (pp_priority_flat($model, '/^g\d+$/') as $uC) {
                if (!isset($d3[$uC]) || !pp_unit_present($d3, $uC)) continue;
                $onAny = false;
                for ($i = $winS; $i <= $winE; $i++) if ((float)($genRows[$i][$uC] ?? 0) > 0.01) { $onAny = true; break; }
                if ($onAny) continue;                                        // sudah berbeban di window
                $blocked = false;
                for ($i = max(0, $winS - 8); $i <= $winE; $i++)
                    if (isset($actualRows[$i]) || pp_is_unit_stopped($d3, $model, $uC, $i + 1) || pp_get_fixed_load($model, $uC, $i + 1) >= 0) { $blocked = true; break; }
                if ($blocked) { $rejL4[] = ['candidate' => strtoupper($uC), 'reason_rejected' => 'stop schedule / fixed load / actual row pada window']; continue; }
                if ($stgRunning($stgOfU($uC))) $same[] = $uC; else $cross[] = $uC;
            }
            foreach ([['same-block', $same], ['cross-block', $cross]] as [$grpName, $grp]) {
                if ($moved) break;
                foreach ($grp as $uC) {
                    if ($moved) break;
                    $seqC = pp_start_sequence($d3, $model, $uC, $genRows, max(1, $winS - 2));
                    $lenC = count($seqC);
                    $sRow = max(1, $winS - $lenC);                            // sequence selesai sebelum window
                    $minC = (float)($d3[$uC]['min_ccload'] ?? ($d3[$uC]['min_scload'] ?? 20));
                    $svAllL4 = $genRows;
                    for ($i = $sRow; $i < $n; $i++) {
                        if (isset($actualRows[$i]) || pp_get_fixed_load($model, $uC, $i + 1) >= 0) break;
                        if (pp_is_unit_stopped($d3, $model, $uC, $i + 1)) break;
                        $val = ($i - $sRow < $lenC) ? (float)$seqC[$i - $sRow] : $minC;
                        $genRows[$i][$uC] = $val;
                        pp_recompute_stgs($genRows[$i], $d3, $model, $i + 1);
                    }
                    if(!$allowGrossIncreaseForDistillate)$donate($proxy0);               // distillate substitutes added thermal energy
                    $fixedWin = true;
                    for ($i = $winS; $i <= $winE; $i++) if ($expOf($i) < $minAt($i) - 1e-9) { $fixedWin = false; break; }
                    $allOKL4 = $fixedWin;
                    if ($allOKL4) for ($i = max(0, $sRow - 1); $i < $n; $i++)
                        if (!isset($actualRows[$i]) && !$rowOK($i)) { $allOKL4 = false; break; }
                    if ($allOKL4 && pp_validate_startup($genRows, $d3, $model)) $allOKL4 = false;
                    if (!$allOKL4 || (!$allowGrossIncreaseForDistillate && $gasProxy() > $proxy0 + 1e-6)) {
                        $genRows = $svAllL4;
                        $rejL4[] = ['candidate' => strtoupper($uC), 'group' => $grpName,
                                    'reason_rejected' => !$fixedWin ? 'window defisit tetap di bawah Range Min'
                                        : ($gasProxy() > $proxy0 + 1e-6 ? 'gas tidak dapat didonasikan tanpa keluar window'
                                                                        : 'hard constraint lain (ramp/startup/busflow) gagal')];
                        continue;
                    }
                    $moved = true;
                    $evidence[] = ['row' => $r + 1, 'lever' => 'L4_EXPORT_FLOOR_START', 'unit' => strtoupper($uC),
                                   'group' => $grpName, 'window_rows' => ($winS + 1) . '-' . ($winE + 1),
                                   'start_row' => $sRow + 1, 'sequence' => $seqC, 'hold_load_mw' => $minC,
                                   'export_before' => round($before, 2), 'export_after' => round($expOf($r), 2),
                                   'gas_proxy_delta' => round($gasProxy() - $proxy0, 4),
                                   'same_block_rejections' => array_values(array_filter($rejL4, fn($x) => ($x['group'] ?? '') === 'same-block'))];
                }
            }
            if (!$moved && $rejL4) $evidence[] = ['row' => $r + 1, 'lever' => 'L4_REJECTED', 'candidates' => $rejL4];
        }
        if (!$moved && $finalPass && empty($fineRows[$r]) && $need < 0.5 - 1e-9) {
            /* Tingkat 1 (ambang 0,5 MW) gagal padahal kebutuhannya di bawah 0,5 MW. Coba sekali
             * lagi dengan ambang = kebutuhan nyata sebelum row dinyatakan kehabisan lever. */
            $fineRows[$r] = true;
            $evidence[] = ['row' => $r + 1, 'lever' => 'RETRY_FINE_STEP',
                           'deficit_mw' => round($need, 3),
                           'note' => 'ambang langkah 0,5 MW memblokir seluruh lever; dicoba ulang dengan ambang = kebutuhan nyata'];
            continue;
        }
        if (!$moved) {
            /* Row ini kehabisan lever -> CATAT dan LANJUT ke row defisit lain (jangan break,
             * karena row lain mungkin masih bisa diperbaiki dan bukti per row wajib lengkap). */
            $exhaustedRows[$r] = true;
            /* KLASIFIKASI SEBAB SPESIFIK (D-1 §3.4 / §4 conflict set).
             * "EXHAUSTED" saja tidak cukup: operator perlu tahu APAKAH lantai Export itu mustahil
             * karena tidak ada MW legal sama sekali, atau karena satu-satunya MW legal yang tersisa
             * berasal dari unit GAS sementara gas sudah berada di atas window kuota. Yang kedua
             * bukan cacat rekayasa — ia keputusan bahan bakar, dan wajib dilaporkan sebagai itu.
             * Terukur: band G9 80-90 + Change Over, row 23 butuh +15,92 MW; satu-satunya lever
             * adalah G3/G8/G9 (39,85 MW headroom legal) sementara gas 72,69 BBTUD sudah 8,09 di
             * atas batas atas window 64,60 — menaikkannya menukar pelanggaran Export dengan
             * kekurangan gas yang lebih besar. */
            $legalUp = 0.0; $legalUpGas = 0.0; $atMaxAll = [];
            foreach (['b1','b2','g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $uB) {
                if (!$writable($uB, $r)) continue;
                $curB = (float)($genRows[$r][$uB] ?? 0); if ($curB < 0.01) continue;
                $capB = $capOf($uB, $r);
                $ivB  = pp_legal_load_intervals($d3, $model, $uB, $r + 1, null, $capB);
                $ceilB = $ivB ? pp_legal_floor($ivB, $capB) : null;
                $upB = ($ceilB === null) ? 0.0 : max(0.0, $ceilB - $curB);
                if ($upB <= 0.01) { $atMaxAll[] = strtoupper($uB); continue; }
                $legalUp += $upB;
                if (in_array($uB, $GAS, true)) $legalUpGas += $upB;
            }
            $gAccB  = ($overWinHi !== null) ? $gasAccounted() : null;
            $gasOver = ($gAccB !== null && $overWinHi !== null && $gAccB > $overWinHi + 1e-9);
            $reason = 'NO_LEGAL_LEVER';
            if ($legalUp >= $need - 0.01 && $legalUpGas >= $need - 0.01 && $gasOver)
                $reason = 'FUEL_LIMITED';
            elseif ($legalUp >= $need - 0.01)
                $reason = 'LEVER_EXISTS_BUT_ROW_VALIDATION_FAILED';
            $blk = ['row' => $r + 1, 'lever' => 'EXHAUSTED', 'export' => round($before, 2),
                    'range_min' => $minAt($r), 'deficit_mw' => round($need, 2),
                    'blocker' => $reason,
                    'legal_up_total_mw' => round($legalUp, 2),
                    'legal_up_gas_only_mw' => round($legalUpGas, 2),
                    'units_at_legal_max' => array_slice($atMaxAll, 0, 10),
                    'gas_accounted_bbtud' => $gAccB === null ? null : round($gAccB, 4),
                    'gas_window_hi_bbtud' => $overWinHi === null ? null : round($overWinHi, 4),
                    'gas_over_window_bbtud' => ($gasOver && $gAccB !== null) ? round($gAccB - $overWinHi, 4) : 0.0,
                    'note' => $reason === 'FUEL_LIMITED'
                        ? 'Lantai Export pada baris ini hanya dapat dicapai dengan menaikkan unit GAS, '
                          . 'sementara gas terhitung sudah berada di atas window kuota. Ini keputusan '
                          . 'bahan bakar operator, bukan pelanggaran yang dapat diperbaiki dispatch.'
                        : ($reason === 'NO_LEGAL_LEVER'
                            ? 'Tidak ada MW legal yang tersisa pada baris ini: seluruh unit sudah di batas '
                              . 'legal atas, terkunci Fix Load/Unit Stop, atau berada di dalam startup window.'
                            : 'MW legal tersisa tetapi setiap kandidat gagal validasi baris/tetangga '
                              . '(Export Range, ramp, BusFlow, atau reserve).')];
            $evidence[] = $blk;
            $GLOBALS['__pp_export_floor_blockers'][] = $blk;
            continue;
        }
        unset($exhaustedRows[$r], $fineRows[$r]);
        $fixed++;
    }
    /* INVARIAN GAS DUA SISI: gas harian setelah repair tidak boleh lebih rendah dari baseline
     * (baseline sudah berada di dalam strict window). Kelebihan penurunan dikembalikan. */
    /* Konvergensi DUA SISI: proxy gas dikembalikan ke baseline (yang sudah in-window) — bila di
     * bawah baseline -> refill, bila di atas -> donate. Maks 4 iterasi, deterministik. */
    for ($gk = 0; $gk < 6; $gk++) {
        $now = $gasProxy();
        if($allowGrossIncreaseForDistillate){$evidence[]=['lever'=>'DISTILLATE_GROSS_ENERGY_PRESERVED_FOR_EXPORT_FLOOR','baseline_gas_proxy'=>round($proxy0,4),'gross_gas_proxy_after_export_repair'=>round($now,4),'gross_increment_for_distillate_bbtud'=>round(max(0.0,$now-$proxy0),4)];break;}
        /* Sisi ATAS tanpa toleransi (gas > baseline = risiko melewati quota); sisi BAWAH toleransi
         * 0.002 (window punya lebar 0.04, jadi 0.002 aman). */
        if ($now <= $proxy0 + 1e-9 && $now >= $proxy0 - 0.002) break;
        if ($gk === 3) $evidence[] = ['lever' => 'GAS_NEUTRALITY_RESIDUAL', 'proxy' => round($now, 4),
                                      'baseline' => round($proxy0, 4), 'delta' => round($now - $proxy0, 4)];
        if ($now < $proxy0 - 0.002) { $okRef = $refill($proxy0); $lv = 'GAS_REFILL_AFTER_REPAIR'; }
        else                { $okRef = $donate($proxy0); $lv = 'GAS_TRIM_AFTER_REPAIR'; }
        $evidence[] = ['lever' => $lv, 'target_proxy' => round($proxy0, 4),
                       'proxy_before' => round($now, 4), 'proxy_after' => round($gasProxy(), 4), 'ok' => $okRef];
        if (!$okRef) break;
    }
    return $fixed;
}

/* ===== RAMP BOUNDARY LINTAS TENGAH MALAM (WEEKLY PLAN §7) ======================================
 * ADITIF & AMAN: hanya aktif bila model memuat `unit_last_load_mw` (diisi oleh weekly orchestrator
 * dari row 48 hari sebelumnya). Daily Plan biasa TIDAK memuat field ini sehingga perilakunya
 * persis seperti sebelumnya. Dipakai sebagai batas ramp untuk ROW 1 saja. */
function pp_prev_day_load(array $model, string $unit): ?float {
    $v = $model['unit_last_load_mw'][strtoupper($unit)] ?? ($model['unit_last_load_mw'][strtolower($unit)] ?? null);
    if ($v === null || $v === '') return null;
    return (float)$v;
}
function pp_prev_day_ramp_cap(array $model, string $unit, float $limit): ?array {
    $prev = pp_prev_day_load($model, $unit);
    if ($prev === null) return null;
    return [max(0.0, $prev - $limit), $prev + $limit];
}

/* ===== MARGIN-SORTED GAS TRIM (WEEKLY §1-§4) ===================================================
 * Menurunkan pemakaian gas harian sebesar $targetReduce (BBTUD, ukuran proxy fuel) dengan memilih
 * kandidat berdasarkan SKOR EKSPLISIT — bukan urutan array/row:
 *      skor = margin export row (besar lebih dulu) x prioritas unit terbalik (unit priority rendah
 *             lebih dulu di-turunkan) ; kandidat wajib: bukan startup window, bukan Fixed/Stop/actual,
 *             punya reducible load di atas minimum, dan penurunan tidak melanggar Export Range Min,
 *             Export Ramp, unit ramp, maupun Bus Flow.
 * Step adaptif 5 -> 2 -> 0.5 -> micro (bisection) supaya residual kecil (mis. 0.03 BBTUD) tidak
 * menyebabkan gas jatuh di bawah quota-0.04. Babelan (coal) TIDAK dipakai sebagai lever.
 * Mengembalikan jumlah proxy gas yang benar-benar diturunkan. */
function pp_gas_margin_trim(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                            float $rMin, float $rMax, float $targetReduce, array &$evidence): float {
    $n = count($genRows); if ($n === 0 || $targetReduce <= 1e-9) return 0.0;
    $GAS = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $expOf = function (int $r) use (&$genRows, $ieVals): float {
        $g = $genRows[$r];
        $tot = 0.0; foreach ($g as $k => $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $rowOK = function (int $r) use (&$genRows, $expOf, $rMin, $rMax, $n, $busUnit, $busMin, $ieVals, $model): bool {
        $e = $expOf($r);
        if ($e < $rMin - 1e-9 || $e > $rMax + 1e-6) return false;
        if ($busMin > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) return false;
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            if (abs($e - $expOf($nb)) > pp_export_step_limit($model) + 1e-6) return false; }
        return true;
    };
    /* startup window lock (generik) */
    $suLock = [];
    foreach ($GAS as $uS) {
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($uS)] ?? '')) === 'running');
        for ($r = 0; $r < $n; $r++) {
            $on = ((float)($genRows[$r][$uS] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $sq = pp_start_sequence($d3, $model, $uS, $genRows, $r);
                for ($k = 0; $k < count($sq) && $r + $k < $n; $k++) $suLock[$uS][$r + $k] = true;
            }
            $prevOn = $on;
        }
    }
    $prio = array_flip(pp_priority_flat($model, '/^g\d+$/') ?: []);
    $minOf = function (string $u) use ($d3, $model): float {
        $mn = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
        return $mn > 0 ? $mn : 20.0;
    };
    $writable = function (string $u, int $r) use ($model, $d3, $actualRows, $suLock): bool {
        if (isset($actualRows[$r]) || isset($suLock[$u][$r])) return false;
        if (isset($model['required_mode'][$u]) && ($model['required_mode'][$u]['mode'] ?? '') === 'fixed') return false;
        if (pp_get_fixed_load($model, $u, $r + 1) >= 0) return false;
        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return false;
        return true;
    };
    $done = 0.0; $work = 0; $log = [];
    while ($done < $targetReduce - 1e-9 && $work < 6000) {
        /* RANKING KANDIDAT EKSPLISIT */
        $cands = [];
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            $mg = $expOf($r) - $rMin;
            if ($mg <= 0.5) continue;                                  // hanya row bermargin nyata
            foreach ($GAS as $u) {
                if (!$writable($u, $r)) continue;
                $cur = (float)($genRows[$r][$u] ?? 0);
                $red = $cur - $minOf($u);
                if ($red < 0.5 - 1e-9) continue;
                $cands[] = ['row' => $r, 'unit' => $u, 'margin' => $mg, 'reducible' => $red,
                            'prio' => (int)($prio[$u] ?? 99),
                            'score' => $mg * 100.0 + (int)($prio[$u] ?? 99) + min($red, 30.0)];
            }
        }
        if (!$cands) break;
        usort($cands, fn($a, $b) => $b['score'] <=> $a['score']);
        $moved = false;
        foreach ($cands as $c) {
            $work++;
            $r = $c['row']; $u = $c['unit'];
            $cur = (float)($genRows[$r][$u] ?? 0);
            $remain = $targetReduce - $done;
            foreach ([5.0, 2.0, 0.5, 0.2] as $step) {
                $dec = min($step, $c['reducible'], max(0.2, $c['margin']));
                if ($dec < 0.2 - 1e-9) continue;
                /* anti-overshoot: perkecil sampai delta gas <= sisa kebutuhan (+toleransi kecil) */
                for ($k = 0; $k < 10; $k++) {
                    $dF = (calc_fuel($d3, $u, $cur) - calc_fuel($d3, $u, $cur - $dec)) / 2.0;
                    if ($dF <= $remain + 0.002) break;
                    $dec = round($dec / 2.0, 3);
                    if ($dec < 0.1) break;
                }
                if ($dec < 0.1) continue;
                $dF = (calc_fuel($d3, $u, $cur) - calc_fuel($d3, $u, $cur - $dec)) / 2.0;
                if ($dF > $remain + 0.002) continue;
                $sv = $genRows[$r];
                $genRows[$r][$u] = $cur - $dec;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $ok = $rowOK($r);
                foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n || isset($actualRows[$nb])) continue;
                    if (!$rowOK($nb)) $ok = false; }
                if (!$ok) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                $done += $dF;
                if (count($log) < 12) $log[] = ['row' => $r + 1, 'unit' => strtoupper($u), 'mw_reduced' => $dec,
                                                'gas_freed' => round($dF, 4), 'export_after' => round($expOf($r), 2),
                                                'margin_before' => round($c['margin'], 2)];
                $moved = true; break;
            }
            if ($moved) break;
        }
        if (!$moved) break;
    }
    $evidence[] = ['lever' => 'MARGIN_SORTED_GAS_TRIM', 'target_reduce' => round($targetReduce, 4),
                   'achieved' => round($done, 4), 'steps' => $log];
    return $done;
}

/* ===== COORDINATED FULL-HORIZON GAS CORRECTION (WEEKLY §1-§5) ==================================
 * Solver deterministik ber-batas untuk menurunkan gas harian sebesar $reduceTarget TANPA merusak
 * satu pun hard constraint. Kunci temuan: margin export BUKAN margin generasi karena STG mengikuti
 * GTG (turun 0.5 MW GTG -> STG ikut turun ~0.2 MW -> export jatuh ~0.7 MW). Karena itu lever utama
 * di sini adalah SWAP EXPORT-NEUTRAL:
 *      turunkan unit gas kurang efisien  +  naikkan unit gas lebih efisien pada ROW YANG SAMA
 *      sedemikian hingga  |delta net generation (GTG + STG)| ~ 0  dan  delta gas < 0.
 * Karena generasi row tidak berubah, Export/Bus Flow/Export-Ramp row & tetangga TIDAK berubah —
 * inilah sebabnya lever ini hidup padahal lever satu-arah mati.
 * Sifat solver: deterministik (urutan kandidat ditentukan skor lalu row/unit), batas kandidat &
 * iterasi, deteksi no-progress, deteksi repeated-state, epsilon konsisten, rollback atomik,
 * evidence per kandidat (termasuk yang ditolak). GTG+STG diperlakukan sebagai satu paket. */
function pp_coordinated_gas_correction(array &$genRows, array $d3, array $model, array $ieVals,
                                       array $actualRows, float $rMin, float $rMax,
                                       float $reduceTarget, array &$evidence): float {
    $n = count($genRows);
    if ($n === 0 || $reduceTarget <= 1e-9) return 0.0;
    $GAS  = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $STG  = ['s1','s2','s3'];
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $EPS = 1e-9;

    $expOf = function (array $g, int $r) use ($ieVals): float {
        $tot = 0.0; foreach ($g as $k => $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $gasRow = function (array $g) use ($d3, $GAS): float {
        $s = 0.0; foreach ($GAS as $u) $s += calc_fuel($d3, $u, (float)($g[$u] ?? 0));
        return $s;                                                   // BBTU per slot
    };
    $netRow = function (array $g) use ($GAS, $STG): float {
        $s = 0.0; foreach ($GAS as $u) if ($u !== 'g10') $s += (float)($g[$u] ?? 0);
        foreach ($STG as $u) $s += (float)($g[$u] ?? 0);
        return $s;                                                   // paket GTG + STG
    };
    /* startup window lock — nilai di dalam sequence tidak boleh disentuh */
    $suLock = [];
    foreach ($GAS as $u) {
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($u)] ?? '')) === 'running');
        for ($r = 0; $r < $n; $r++) {
            $on = ((float)($genRows[$r][$u] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $sq = pp_start_sequence($d3, $model, $u, $genRows, $r);
                for ($k = 0; $k < count($sq) && $r + $k < $n; $k++) $suLock[$u][$r + $k] = true;
            }
            $prevOn = $on;
        }
    }
    $writable = function (string $u, int $r) use ($model, $d3, $actualRows, $suLock): bool {
        if (isset($actualRows[$r]) || isset($suLock[$u][$r])) return false;
        if (pp_get_fixed_load($model, $u, $r + 1) >= 0) return false;
        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return false;
        return true;
    };
    $minOf = function (string $u) use ($d3): float {
        $mn = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
        return $mn > 0 ? $mn : 20.0;
    };
    $capOf = function (string $u, int $r) use ($d3, $model, &$genRows, $n): float {
        $em = pp_effective_maxload($d3, $model, $u, $r + 1);
        $cap = ($em > 0 ? $em : (float)($d3[$u]['max_load'] ?? 0));
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            $cap = min($cap, (float)($genRows[$nb][$u] ?? 0) + 30.0); }
        return $cap;
    };
    /* validasi row + tetangga (Export Range, Export Ramp, Bus Flow) */
    /* BUGFIX (no numeric effect): $model was used inside this closure but never imported, emitting a
       PHP warning on every call. pp_export_step_limit() ignores its argument (it returns 35.0), so the
       computed limit is unchanged -- only the warning noise disappears. */
    $rowsOK = function (array $rows) use (&$genRows, $expOf, $rMin, $rMax, $n, $busUnit, $busMin, $ieVals, $model): bool {
        foreach ($rows as $r) {
            if ($r < 0 || $r >= $n) continue;
            $e = $expOf($genRows[$r], $r);
            if ($e < $rMin - 1e-9 || $e > $rMax + 1e-6) return false;
            if ($busMin > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) return false;
            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                if (abs($e - $expOf($genRows[$nb], $nb)) > pp_export_step_limit($model) + 1e-6) return false; }
        }
        return true;
    };

    $freed = 0.0; $iter = 0; $rejected = 0; $applied = [];
    $stateSeen = [];
    $ITER_CAP = 220; $CAND_CAP = 4000;      // batas deterministik (runtime per hari harus <120 s)
    /* OPT-C1 — kandidat swap per row hanya bergantung pada isi row itu, dua row tetangganya (batas
     * ramp pada capOf), dan pilihan daftar langkah (sisa target < 0,01). Setiap iterasi hanya
     * mengubah SATU row, sehingga 47 row lain menghasilkan daftar kandidat yang sama persis. Daftar
     * kandidat valid (urutan evaluasi asli) dan jumlah kandidat per row disimpan, lalu diputar ulang
     * lewat aturan pemilihan yang sama — pemenang, urutan tie-break, dan batas CAND_CAP identik. */
    $rowCandCache = [];
    while ($freed < $reduceTarget - 1e-9 && $iter++ < $ITER_CAP) {
        /* deteksi repeated-state/oscillation */
        $sig = pp_rows_signature($genRows, $GAS);
        if (isset($stateSeen[$sig])) { $evidence[] = ['lever' => 'COORD_STOP_REPEATED_STATE', 'iter' => $iter]; break; }
        $stateSeen[$sig] = true;

        $best = null; $cand = 0;
        for ($r = 0; $r < $n && $cand < $CAND_CAP; $r++) {
            if (isset($actualRows[$r])) continue;
            $row0 = $genRows[$r];
            $smallSt = ($reduceTarget - $freed < 0.01);
            $nbP = $r > 0 ? $genRows[$r - 1] : null; $nbN = $r < $n - 1 ? $genRows[$r + 1] : null;
            $ckR = $rowCandCache[$r] ?? null;
            if ($ckR !== null && $ckR[3] === $smallSt && $ckR[0] === $row0 && $ckR[1] === $nbP && $ckR[2] === $nbN) {
                $cand += $ckR[4];
                foreach ($ckR[5] as $cdR) {
                    $score = $cdR['score'];
                    if ($best === null || $score > $best['score'] + 1e-12
                        || (abs($score - $best['score']) <= 1e-12 && $r < $best['row'])) $best = $cdR;
                }
                continue;
            }
            $candStart = $cand; $rowC = [];
            $gas0 = $gasRow($row0); $net0 = $netRow($row0);
            /* SENSITIVITY MAP per row (dihitung SEKALI per iterasi): marginal gas per net-MW untuk
             * setiap unit ON, memakai paket GTG+STG. Hanya 2 kandidat terburuk (untuk diturunkan)
             * dan 2 terbaik (untuk dinaikkan) yang dievaluasi -> pencarian jauh lebih murah dan
             * tetap deterministik (urutan skor lalu nama unit). */
            $sens = [];
            foreach ($GAS as $uS) {
                if ($uS === 'g10' || !$writable($uS, $r)) continue;
                $lS = (float)($row0[$uS] ?? 0);
                if ($lS < 0.01) continue;
                $probe = $row0; $probe[$uS] = min($capOf($uS, $r), $lS + 0.5);
                if ($probe[$uS] - $lS < 0.05) { $probe = $row0; $probe[$uS] = max($minOf($uS), $lS - 0.5); }
                if (abs($probe[$uS] - $lS) < 0.05) continue;
                pp_recompute_stgs($probe, $d3, $model, $r + 1);
                $dN = $netRow($probe) - $net0; $dG = ($gasRow($probe) - $gas0);
                if (abs($dN) < 1e-6) continue;
                $sens[$uS] = $dG / $dN;                               // BBTU per MW-slot (marginal)
            }
            if (count($sens) < 2) { $rowCandCache[$r] = [$row0, $nbP, $nbN, $smallSt, 0, []]; continue; }
            $byWorst = $sens; arsort($byWorst); $byBest = $sens; asort($byBest);
            $dnList = array_slice(array_keys($byWorst), 0, 3);         // marginal TERBURUK -> turunkan
            $upList = array_slice(array_keys($byBest), 0, 3);          // marginal TERBAIK  -> naikkan
            foreach ($dnList as $uDn) {
                $lDn = (float)($row0[$uDn] ?? 0);
                if ($lDn < $minOf($uDn) + 0.1 - $EPS) continue;
                foreach ($upList as $uUp) {
                    if ($uUp === $uDn) continue;
                    $lUp = (float)($row0[$uUp] ?? 0);
                    if ($lUp < 0.01) continue;                        // jangan start unit baru (Priority 4)
                    $capUp = $capOf($uUp, $r);
                    if ($capUp - $lUp < 0.1 - $EPS) continue;
                    $steps = ($reduceTarget - $freed < 0.01) ? [0.5, 0.2, 0.1] : [2.0, 1.0, 0.5, 0.2];
                    foreach ($steps as $dDn) {
                        if ($lDn - $dDn < $minOf($uDn) - $EPS) continue;
                        /* cari dUp yang membuat net delta ~ 0 (GTG+STG diperhitungkan) */
                        foreach ([10, 9, 11, 8, 12] as $m) {
                            $dUp = round($dDn * $m / 10.0, 2);
                            if ($dUp < 0.05 || $lUp + $dUp > $capUp + $EPS) continue;
                            $cand++;
                            $test = $row0;
                            $test[$uDn] = $lDn - $dDn; $test[$uUp] = $lUp + $dUp;
                            pp_recompute_stgs($test, $d3, $model, $r + 1);
                            $dNet = $netRow($test) - $net0;
                            if (abs($dNet) > 0.05) continue;          // wajib export-neutral
                            $dGas = ($gasRow($test) - $gas0) / 2.0;   // BBTUD
                            if ($dGas >= -1e-6) continue;             // wajib menurunkan gas
                            $score = -$dGas;
                            $cdN = ['score' => $score, 'row' => $r, 'down' => $uDn, 'up' => $uUp,
                                    'd_dn' => $dDn, 'd_up' => $dUp, 'd_gas' => $dGas, 'd_net' => $dNet,
                                    'row_after' => $test];
                            $rowC[] = $cdN;
                            if ($best === null || $score > $best['score'] + 1e-12
                                || (abs($score - $best['score']) <= 1e-12 && $r < $best['row'])) {
                                $best = $cdN;
                            }
                        }
                    }
                }
            }
            $rowCandCache[$r] = [$row0, $nbP, $nbN, $smallSt, $cand - $candStart, $rowC];
        }
        if ($best === null) { $evidence[] = ['lever' => 'COORD_NO_CANDIDATE', 'iter' => $iter, 'freed' => round($freed, 4)]; break; }

        /* terapkan atomik lalu validasi row + tetangga; rollback bila gagal */
        $sv = $genRows[$best['row']];
        $genRows[$best['row']] = $best['row_after'];
        if (!$rowsOK([$best['row'] - 1, $best['row'], $best['row'] + 1])) {
            $genRows[$best['row']] = $sv; $rejected++;
            $evidence[] = ['lever' => 'COORD_SWAP_REJECTED', 'row' => $best['row'] + 1,
                           'down' => strtoupper($best['down']), 'up' => strtoupper($best['up']),
                           'reason' => 'row/tetangga gagal validasi (Export Range/Ramp/Bus Flow)'];
            $stateSeen = [];                                          // izinkan jalur lain
            continue;
        }
        $freed += -$best['d_gas'];
        if (count($applied) < 24) $applied[] = ['row' => $best['row'] + 1, 'down' => strtoupper($best['down']),
            'mw_down' => $best['d_dn'], 'up' => strtoupper($best['up']), 'mw_up' => $best['d_up'],
            'd_net_gen' => round($best['d_net'], 3), 'd_gas' => round($best['d_gas'], 5),
            'gas_freed_cum' => round($freed, 5)];
    }
    $evidence[] = ['lever' => 'COORDINATED_GAS_CORRECTION', 'reduce_target' => round($reduceTarget, 4),
                   'achieved' => round($freed, 4), 'iterations' => $iter, 'rejected' => $rejected,
                   'swaps' => $applied];
    return $freed;
}

/* ===== SPINNING RESERVE (HARD CONSTRAINT) =====================================================
 * Definisi domain: reserve = TOTAL HEADROOM UNIT YANG SEDANG ONLINE pada row tsb
 *   reserve(row) = SUM over unit ONLINE ( effective_max(unit,row) - load(unit,row) )
 * Unit yang OFF tidak menyumbang (bukan spinning). Unit dengan Fixed Load tidak menyumbang karena
 * bebannya terkunci. Helper ini dipakai BERSAMA oleh optimizer, repair, decommit gate, dan validator
 * sehingga tidak ada dua definisi reserve yang berbeda. */
function pp_reserve_units(array $d3, array $model = []): array {
    /* KEPUTUSAN DOMAIN OPERATOR — FINAL (KOREKSI): unit yang boleh berkontribusi pada Spinning
     * Reserve HANYA G1-G10 dan GE1-GE4 (Gas Engine). BABELAN 1 & 2 TIDAK ELIGIBLE.
     *
     * BB1/BB2 tetap dipakai penuh untuk pembangkitan energi, PLN Export, load balance, Bus Flow,
     * ramp Babelan, dan gas-shortage redispatch — tetapi headroom-nya SELALU menyumbang 0 MW
     * Spinning Reserve. STG (S1/S2/S3) juga tidak eligible (bottoming cycle).
     *
     * Daftar TIDAK berhenti di G9. G10 TIDAK dikecualikan karena feeder MM2100. GE1-GE4 TIDAK
     * dikecualikan karena tipe unit — namun tunduk penuh pada Presence & Load Limits.
     *
     * TIDAK ADA DOUBLE COUNTING: G10 dan GE tetap dikecualikan dari Bus Flow, PLN Export, dan
     * House load. Headroom unit dihitung SATU KALI: Effective Max - Current Load. */
    $all = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'];
    $u = [];
    foreach ($all as $x) {
        if (!isset($d3[$x])) continue;
        if (!pp_unit_present($d3, $x)) continue;     // Presence & Load Limits otoritatif
        $u[] = $x;
    }
    return $u;
}
/* Unit yang secara domain TIDAK PERNAH eligible sebagai Spinning Reserve. */
function pp_reserve_non_eligible_units(): array {
    return ['b1','b2','s1','s2','s3'];
}

/* Alasan pengecualian per unit — dipakai tabel evidence & certificate. NULL = eligible. */
function pp_reserve_exclusion_reason(array $gen, array $d3, array $model, string $u, int $row1): ?string {
    if (in_array($u, pp_reserve_non_eligible_units(), true))
        return 'RESERVE_EXCLUDED_NON_ELIGIBLE_UNIT'
             . (in_array($u, ['b1','b2'], true)
                ? ' (Babelan: dipakai untuk energi/Export/Bus Flow, tetapi headroom = 0 MW Reserve)'
                : ' (STG bottoming cycle)');
    if (!isset($d3[$u]))                      return 'unit tidak terdefinisi di Unit Characteristic';
    /* SCOPE OVERRIDE MM2100: deprekasi legacy unit_present berlaku untuk COMMITMENT/eligibility
     * dispatch, TIDAK untuk kontribusi Spinning Reserve. Kontrak domain menyatakan GE1-GE4
     * "tunduk penuh pada Presence & Load Limits" untuk Reserve, sehingga nilai RAW tetap dipakai
     * di sini agar headroom GE tidak menggelembungkan Reserve. */
    if (pp_is_mm2100_unit($u)) {
        if (!pp_effective_unit_available($d3,$model,$u,1)) return 'presence = false (Presence & Load Limits)';
    } elseif (!pp_unit_present($d3, $u))     return 'presence = false (Presence & Load Limits)';
    if (!in_array($u, ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'], true))
                                              return 'RESERVE_EXCLUDED_NON_ELIGIBLE_UNIT';
    if (pp_is_unit_stopped($d3, $model, $u, $row1)) return 'Stop operator aktif pada row ini';
    $v = (float)($gen[$u] ?? 0);
    if ($v <= 0.51)                           return 'OFF / belum synchronized';
    if (pp_get_fixed_load($model, $u, $row1) >= 0) return 'Fixed Load — headroom terkunci';
    $mn = pp_effective_min_load($d3, $model, $u, $row1);
    if ($mn > 0 && $v < $mn - 1e-6)           return sprintf('startup belum selesai (load %.2f < effective min %.2f MW)', $v, $mn);
    if (pp_effective_max_load($d3, $model, $u, $row1) <= 0) return 'Effective Max Load tidak valid';
    return null;
}
function pp_spinning_reserve(array $gen, array $d3, array $model, int $row1): float {
    $res = 0.0;
    foreach (pp_reserve_units($d3, $model) as $u) {
        if (pp_reserve_exclusion_reason($gen, $d3, $model, $u, $row1) !== null) continue;
        $res += max(0.0, pp_effective_max_load($d3, $model, $u, $row1) - (float)($gen[$u] ?? 0));
    }
    return $res;
}
function pp_reserve_min(array $model): float {
    return (float)($model['spinning_reserve_min'] ?? $model['reserve_min'] ?? 0);
}
/* Headroom tambahan yang bisa didapat bila unit OFF dinyalakan (untuk keputusan commitment). */
function pp_reserve_potential_from_start(array $gen, array $d3, array $model, string $u, int $row1): float {
    if ((float)($gen[$u] ?? 0) > 0.51) return 0.0;
    if (pp_is_unit_stopped($d3, $model, $u, $row1)) return 0.0;
    $mx = pp_effective_maxload($d3, $model, $u, $row1);
    if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
    $mn = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
    return max(0.0, $mx - $mn);                                     // online di min load -> headroom
}

/* ===== RESERVE REPAIR (HARD CONSTRAINT, export-neutral) ========================================
 * Bila spinning reserve pada suatu row di bawah requirement, unit OFF dinyalakan LEBIH AWAL supaya
 * ada online headroom. Agar tidak merusak Export/gas, penambahan MW dari unit baru dikompensasi
 * dengan menurunkan unit lain pada row yang sama (export-neutral) — sekaligus MENAMBAH headroom
 * unit tsb. Urutan kandidat: same-block (STG aktif) -> Unit Priority -> potensi headroom terbesar.
 * Seluruh kandidat dicatat (diterima/ditolak + alasan) sebagai Reserve Exhaustion Proof. */
function pp_reserve_repair(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                           float $rMin, float $rMax, array &$evidence): int {
    $n = count($genRows); if ($n === 0) return 0;
    $need = pp_reserve_min($model); if ($need <= 0) return 0;
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $expOf = function (int $r) use (&$genRows, $ieVals): float {
        $g = $genRows[$r]; $tot = 0.0;
        foreach ($g as $k => $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $bbLimR = pp_babelan_ramp_limit($model);
    /* E05 BUGFIX (dampak numerik NOL): $model dipakai di dalam closure ini (pp_export_step_limit)
     * tetapi tidak pernah di-import. Terukur 120.328 PHP warning "Undefined variable $model" pada
     * corpus regresi — seluruhnya dari SATU baris ini, dan hanya muncul ketika pass perbaikan
     * Spinning Reserve benar-benar berjalan. Karena pp_export_step_limit() mengabaikan argumennya
     * (selalu mengembalikan 35.0), nilai batas yang dihitung TIDAK berubah sama sekali; yang hilang
     * hanya noise warning beserta biaya menuliskannya ke error_log. Pola yang sama sudah diperbaiki
     * sebelumnya di pp_coordinated_gas_correction(). */
    $rowOK = function (int $r) use (&$genRows, $expOf, $rMin, $rMax, $n, $busUnit, $busMin, $ieVals, $bbLimR, $model): bool {
        $e = $expOf($r);
        if ($e < $rMin - 1e-9 || $e > $rMax + 1e-6) return false;
        if ($busMin > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) return false;
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            if (abs($e - $expOf($nb)) > pp_export_step_limit($model) + 1e-6) return false; }
        /* RAMP BABELAN (hard): kompensasi reserve tidak boleh melanggar batas ramp coal. */
        foreach (['b1','b2'] as $bu) {
            foreach ([$r - 1, $r + 1] as $nb) {
                if ($nb < 0 || $nb >= $n) continue;
                if (abs((float)($genRows[$r][$bu] ?? 0) - (float)($genRows[$nb][$bu] ?? 0)) > $bbLimR + 1e-6) return false;
            }
        }
        return true;
    };
    $minOf = fn(string $u) => (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
    $maxOf = function (string $u, int $r) use ($d3, $model) {
        $mx = pp_effective_maxload($d3, $model, $u, $r + 1);
        return $mx > 0 ? $mx : (float)($d3[$u]['max_load'] ?? 0);
    };
    $writable = function (string $u, int $r) use ($model, $d3, $actualRows): bool {
        if (isset($actualRows[$r])) return false;
        if (pp_get_fixed_load($model, $u, $r + 1) >= 0) return false;
        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return false;
        return true;
    };
    $prio = array_flip(pp_priority_flat($model, '/^g\d+$/') ?: []);
    $tried = []; $fixed = 0; $exhausted = []; $workUnits = 0;

    for ($pass = 0; $pass < 120; $pass++) {
        if ($workUnits > 20000) { $evidence[] = ['lever' => 'RESERVE_WORK_BUDGET_REACHED', 'work' => $workUnits]; break; }
        $r = -1;
        for ($i = 0; $i < $n; $i++) {
            if (isset($actualRows[$i]) || !empty($exhausted[$i])) continue;
            if (pp_spinning_reserve($genRows[$i], $d3, $model, $i + 1) < $need - 1e-6) { $r = $i; break; }
        }
        if ($r < 0) break;
        $before = pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1);

        /* kandidat unit OFF yang dapat dinyalakan */
        $cands = [];
        foreach (pp_reserve_units($d3) as $u) {
            if ((float)($genRows[$r][$u] ?? 0) > 0.51) continue;
            if (!$writable($u, $r)) { $tried[] = ['row' => $r + 1, 'unit' => strtoupper($u), 'accepted' => false,
                'reason' => 'stop schedule / fixed load / actual row']; continue; }
            $pot = pp_reserve_potential_from_start($genRows[$r], $d3, $model, $u, $r + 1);
            if ($pot <= 0.01) { $tried[] = ['row' => $r + 1, 'unit' => strtoupper($u), 'accepted' => false,
                'reason' => 'tidak ada potensi headroom']; continue; }
            /* Unit yang kemarin STOP tidak boleh langsung online di row 1 (anti start-stop);
             * mulai dari row >= 2 dengan startup sequence. */
            if ($r === 0 && strtolower((string)($model['unit_last_data_status'][strtoupper($u)] ?? '')) !== 'running') {
                $tried[] = ['row' => 1, 'unit' => strtoupper($u), 'accepted' => false,
                            'reason' => 'Last Data Status = Stop: tidak boleh online pada row 1']; continue;
            }
            $stg = pp_gtg_to_stg($d3, $u);
            $sameBlock = ($stg && (float)($genRows[$r][$stg] ?? 0) > 0.01) ? 0 : 1;
            /* efisiensi gas: BBTU per MW headroom (CC + kredit STG jauh lebih efisien dari simple cycle) */
            $mnU = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
            $fuelU = ($u === 'b1' || $u === 'b2') ? 0.0 : calc_fuel($d3, $u, $mnU);
            $eff = $pot > 0 ? ($fuelU / $pot) : INF;
            $cands[] = ['u' => $u, 'pot' => $pot, 'same' => $sameBlock, 'prio' => (int)($prio[$u] ?? 99),
                        'eff' => $eff];
        }
        /* urutan: non-gas dulu (eff 0) -> same-block -> efisiensi gas terbaik -> Unit Priority */
        usort($cands, fn($a, $b) => [round($a['eff'], 5), $a['same'], $a['prio'], -$a['pot']]
                              <=> [round($b['eff'], 5), $b['same'], $b['prio'], -$b['pot']]);
        $moved = false;
        foreach ($cands as $c) {
            $u = $c['u']; $mn = $minOf($u);
            $save = $genRows;
            /* nyalakan dari row r sampai akhir hari (atau sampai stop schedule) pada min load */
            $seqU = pp_start_sequence($d3, $model, $u, $genRows, $r);
            $k = 0;
            for ($rr = $r; $rr < $n; $rr++) {
                if (!$writable($u, $rr)) break;
                if ($k < count($seqU)) {
                    /* DI DALAM startup window: nilai WAJIB TEPAT sama dgn langkah sequence
                     * (bukan max() dgn nilai existing — itulah sumber "G4 slot 1 = 10 MW"). */
                    $genRows[$rr][$u] = (float)$seqU[$k];
                } else {
                    $genRows[$rr][$u] = max($mn, (float)($genRows[$rr][$u] ?? 0));
                }
                pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                $k++;
            }
            $GLOBALS['__pp_reserve_commit'][strtoupper($u)] = true;   // otorisasi: start demi reserve
            /* export-neutral: turunkan unit lain pada row terdampak sebesar tambahan MW */
            $okAll = true;
            for ($rr = $r; $rr < $n && $okAll; $rr++) {
                if ((float)($genRows[$rr][$u] ?? 0) <= 0.51) continue;
                $guard = 0;
                while ($expOf($rr) > $rMax + 1e-6 || (!$rowOK($rr) && $expOf($rr) > $rMin + 0.5)) {
                    if (++$guard > 60 || ++$workUnits > 20000) { $okAll = false; break; }   // budget deterministik
                    /* KOMPENSASI SADAR-EFISIENSI: turunkan unit dengan marginal gas TERBURUK lebih
                     * dulu (mis. simple cycle G7/G10 tanpa kredit STG), sehingga menyalakan unit CC
                     * efisien justru MENURUNKAN total gas — inilah lever gabungan reserve <-> gas. */
                    $lockNow = pp_build_startup_lock($genRows, $d3, $model);   // lock terkini
                    $order = [];
                    foreach (['g7','g10','g9','g8','g5','g1','g2','g4','g3','g6','b1','b2'] as $dn) {
                        if ($dn === $u || !$writable($dn, $rr)) continue;
                        if (isset($lockNow[$dn][$rr])) continue;                   // di dalam startup window
                        $cur = (float)($genRows[$rr][$dn] ?? 0);
                        if ($cur <= 0.51 || $cur < $minOf($dn) + 0.5 - 1e-9) continue;
                        if ($dn === 'b1' || $dn === 'b2') { $order[] = [$dn, INF]; continue; }   // non-gas: terakhir
                        $probe = $genRows[$rr]; $net0 = 0.0; $net1 = 0.0;
                        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','s1','s2','s3'] as $q) $net0 += (float)($probe[$q] ?? 0);
                        $f0 = calc_fuel($d3, $dn, $cur);
                        $probe[$dn] = $cur - 0.5; pp_recompute_stgs($probe, $d3, $model, $rr + 1);
                        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','s1','s2','s3'] as $q) $net1 += (float)($probe[$q] ?? 0);
                        $dNet = max(0.001, $net0 - $net1);
                        $order[] = [$dn, ($f0 - calc_fuel($d3, $dn, $cur - 0.5)) / $dNet];
                    }
                    usort($order, fn($x, $y) => $y[1] <=> $x[1]);        // marginal terburuk lebih dulu
                    $done = false;
                    foreach ($order as [$dn, $eff]) {
                        $cur = (float)($genRows[$rr][$dn] ?? 0);
                        /* langkah adaptif: pakai 2 MW saat selisih besar, 0.5 MW saat mendekati target */
                        $gapNow = max(0.0, $expOf($rr) - $rMax);
                        $step = ($gapNow > 4.0) ? 2.0 : 0.5;
                        $step = min($step, max(0.5, $cur - $minOf($dn)));
                        $genRows[$rr][$dn] = $cur - $step;
                        pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                        $done = true; break;
                    }
                    if (!$done) { $okAll = false; break; }
                }
                if ($okAll && !$rowOK($rr)) $okAll = false;
            }
            /* GATE KETAT: seluruh row yang tersentuh WAJIB lolos Export Range (kedua sisi), Bus Flow,
             * ramp, dan startup — kandidat ditolak SEBELUM dibandingkan manfaatnya. */
            if ($okAll) {
                for ($rr = max(0, $r - 1); $rr < $n; $rr++) {
                    if (isset($actualRows[$rr])) continue;
                    if (!$rowOK($rr)) { $okAll = false; break; }   // seluruh row terdampak divalidasi
                }
            }
            $after = $okAll ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : 0.0;
            $suOK = $okAll ? empty(pp_validate_startup($genRows, $d3, $model)) : false;
            /* GATE GAS: kandidat ditolak bila mendorong proxy gas harian melewati kuota (hard).
             * Reserve tidak boleh dipenuhi dengan melanggar gas window. */
            $gasAfter = 0.0;
            if ($okAll) { foreach ($genRows as $gRow) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $gu)
                              $gasAfter += calc_fuel($d3, $gu, (float)($gRow[$gu] ?? 0));
                          $gasAfter /= 2.0; }
            $gasCap = (float)($model['__reserve_gas_cap'] ?? 0);
            $gasOK = ($gasCap <= 0) || ($gasAfter <= $gasCap + 1e-6);
            if ($okAll && $suOK && $gasOK && $after > $before + 0.5) {
                $tried[] = ['row' => $r + 1, 'unit' => strtoupper($u), 'accepted' => true,
                            'reserve_before' => round($before, 2), 'reserve_after' => round($after, 2),
                            'headroom_potential' => round($c['pot'], 2), 'same_block' => $c['same'] === 0];
                $moved = true; $fixed++; break;
            }
            $genRows = $save;
            $tried[] = ['row' => $r + 1, 'unit' => strtoupper($u), 'accepted' => false,
                        'reason' => !$okAll ? 'kompensasi export-neutral gagal (Export/Bus Flow/ramp)' :
                                    (!$suOK ? 'melanggar startup sequence' :
                                    (!$gasOK ? sprintf('melewati kuota gas (%.4f > %.4f BBTUD)', $gasAfter, $gasCap)
                                             : 'reserve tidak membaik'))];
        }
        if (!$moved) {
            /* Row ini kehabisan kandidat -> CATAT sebagai exhaustion proof lalu LANJUT ke row lain
             * (satu row infeasible tidak boleh menghentikan perbaikan row lainnya). */
            $exhausted[$r] = true;
            $evidence[] = ['lever' => 'RESERVE_EXHAUSTED', 'row' => $r + 1,
                           'reserve' => round($before, 2), 'requirement' => $need,
                           'candidates' => array_values(array_filter($tried, fn($t) => $t['row'] === $r + 1))];
            continue;
        }
    }
    if ($tried) $evidence[] = ['lever' => 'RESERVE_REPAIR', 'requirement' => $need, 'rows_fixed' => $fixed,
                               'candidates_evaluated' => count($tried), 'detail' => array_slice($tried, 0, 20)];
    return $fixed;
}

/* ===== BABELAN ENERGY REDISPATCH FOR RESERVE (lever TIDAK LANGSUNG) ============================
 * KOREKSI DOMAIN: BB1/BB2 BUKAN unit Spinning Reserve. Menurunkan Babelan TIDAK menambah reserve.
 * Babelan hanyalah lever ENERGI: dengan menaikkan Babelan, beban GTG/GEG yang eligible dapat
 * DITURUNKAN pada energy balance yang sama, dan HANYA dari situ headroom reserve bertambah.
 *
 *      reserve naik  <=>  load GTG/GEG eligible turun
 *      Babelan naik   =   kompensasi energi supaya PLN Export & load balance tidak berubah
 *
 * Fungsi ini TIDAK PERNAH menghitung (Effective Max BB - Current Load BB) sebagai reserve.
 *
 * Batas yang dihormati: Effective Min GTG/GEG, Effective Max Babelan, ramp Babelan per row,
 * Bus Flow minimum (menaikkan Babelan di Bus B menurunkan Bus Flow), Export Range, Fixed Load,
 * Stop operator, row Actual/locked, dan window gas (kompensasi menurunkan pemakaian gas).
 * Setiap operasi di-rollback penuh bila salah satu batas dilanggar. */
function pp_babelan_energy_redispatch_for_reserve(array &$genRows, array $d3, array $model, array $ieVals,
                                                  array $actualRows, float $rMin, float $rMax,
                                                  array &$evidence, float $gasLo = 0.0): int {
    $n = count($genRows); if ($n === 0) return 0;
    $need = pp_reserve_min($model);
    if ($need <= 0) return 0;
    $BB = []; foreach (['b1','b2'] as $u) if (isset($d3[$u])) $BB[] = $u;
    $lim = pp_babelan_ramp_limit($model);
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $EPS = 1e-6;

    $expOf = function (int $r) use (&$genRows, $ieVals): float {
        $g = $genRows[$r]; $tot = 0.0;
        foreach ($g as $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $gasTotal = function () use (&$genRows, $d3): float {
        $t = 0.0;
        foreach ($genRows as $g)
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
                $t += calc_fuel($d3, $u, (float)($g[$u] ?? 0));
        return $t;
    };
    $frozen = function (string $u, int $r) use ($model, $d3, $actualRows): bool {
        return isset($actualRows[$r]) || pp_get_fixed_load($model, $u, $r + 1) >= 0
               || pp_is_unit_stopped($d3, $model, $u, $r + 1);
    };
    /* Naikkan Babelan pada row $r sebesar $amt, hormati Effective Max & ramp coal. Return sisa
     * yang TIDAK dapat diserap Babelan. */
    $raiseBB = function (int $r, float $amt) use (&$genRows, $d3, $model, $BB, $lim, $n, $frozen, $EPS): float {
        foreach ($BB as $u) {
            if ($amt <= $EPS) break;
            if ($frozen($u, $r)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0);
            if ($cur <= 0.51) continue;                        /* Babelan OFF: jangan dinyalakan di sini */
            $mx = pp_effective_max_load($d3, $model, $u, $r + 1);
            $cap = $mx;
            if ($r > 0)     $cap = min($cap, (float)$genRows[$r - 1][$u] + $lim);
            if ($r + 1 < $n) $cap = min($cap, (float)$genRows[$r + 1][$u] + $lim);
            $room = max(0.0, $cap - $cur);
            $step = min($amt, $room);
            if ($step <= $EPS) continue;
            $genRows[$r][$u] = $cur + $step;
            $amt -= $step;
        }
        return $amt;
    };

    /* GUARD GAS RELATIF: proxy calc_fuel() tidak sekalibrasi dengan angka gas resmi engine, jadi
     * ambang absolut tidak dapat dipercaya. Yang dijaga adalah TIDAK ADA PENURUNAN pemakaian gas
     * terhadap kondisi awal pass. Konsekuensi domain yang benar: pada skenario gas must-take yang
     * sudah menempel plafon window, lever ini memang TIDAK BOLEH dipakai — membebaskan headroom
     * GTG selalu berarti membakar gas lebih sedikit. */
    $gasStart = $gasTotal();
    $gas0 = $gasLo > 0 ? $gasStart : 0.0;
    $applied = 0;

    /* --- TRIM EXPORT DI ATAS RANGE MAX (lever ENERGI murni, bukan klaim reserve) --------------
     * Menurunkan Babelan menurunkan PLN Export. Ini TIDAK menambah Spinning Reserve sama sekali
     * (headroom Babelan = 0 MW menurut keputusan domain); tujuannya semata menutup pelanggaran
     * Export Range Max tanpa menyentuh gas. */
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $over = $expOf($r) - $rMax;
        if ($over <= $EPS) continue;
        foreach ($BB as $u) {
            if ($over <= $EPS) break;
            if ($frozen($u, $r)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0);
            if ($cur <= 0.51) continue;
            $flo = pp_effective_min_load($d3, $model, $u, $r + 1);
            if ($r > 0)      $flo = max($flo, (float)$genRows[$r - 1][$u] - $lim);
            if ($r + 1 < $n) $flo = max($flo, (float)$genRows[$r + 1][$u] - $lim);
            $step = min($over, max(0.0, $cur - $flo));
            if ($step <= $EPS) continue;
            $snap = $genRows;
            $genRows[$r][$u] = $cur - $step;
            if ($expOf($r) < $rMin - $EPS) { $genRows = $snap; continue; }
            $over -= $step; $applied++;
            $evidence[] = ['babelan_export_trim', sprintf(
                'row %d: %s %.2f -> %.2f MW menutup Export %.2f MW di atas Range Max (Reserve TIDAK berubah)',
                $r + 1, strtoupper($u), $cur, $cur - $step, $step)];
        }
    }

    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $guard = 0;
        while ($guard++ < 30) {
            $res = pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1);
            $want = $need - $res;
            if ($want <= $EPS) break;
            $moved = false;
            /* Turunkan GTG/GEG eligible yang loadnya PALING JAUH di atas Effective Min lebih dulu. */
            $cands = [];
            foreach (pp_reserve_units($d3, $model) as $u) {
                if (pp_reserve_exclusion_reason($genRows[$r], $d3, $model, $u, $r + 1) !== null) continue;
                if ($frozen($u, $r)) continue;
                $slack = (float)$genRows[$r][$u] - pp_effective_min_load($d3, $model, $u, $r + 1);
                if ($slack > $EPS) $cands[$u] = $slack;
            }
            arsort($cands);
            foreach ($cands as $u => $slack) {
                if ($want <= $EPS) break;
                $step = min($want, $slack);
                if ($step <= $EPS) continue;
                $snapshot = $genRows;
                $genRows[$r][$u] = (float)$genRows[$r][$u] - $step;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                /* kompensasi energi dengan Babelan supaya Export tidak jatuh */
                $left = $raiseBB($r, $step);
                $ok = true;
                $e = $expOf($r);
                if ($e < $rMin - $EPS || $e > $rMax + $EPS) $ok = false;
                if ($ok && $busMin > 0
                    && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - $EPS) $ok = false;
                if ($ok && $gasLo > 0 && $gasTotal() < $gasStart - 1e-9) $ok = false;   /* gas tidak boleh turun */
                if ($ok) {
                    $applied++; $moved = true; $want -= $step;
                    $evidence[] = ['babelan_redispatch', sprintf(
                        'row %d: %s turun %.2f MW (Babelan menyerap %.2f MW) -> reserve %.2f -> %.2f',
                        $r + 1, strtoupper($u), $step, $step - $left, $res,
                        pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1))];
                    break;
                }
                $genRows = $snapshot;
            }
            if (!$moved) break;
        }
    }
    return $applied;
}


/* ===== RE-ASSERTION STARTUP STEP (anti-zeroing) ================================================
 * Menegaskan kembali nilai row yang berada DI DALAM startup window agar persis sama dengan expected
 * sequence. Melindungi dari tiga arah sekaligus:
 *   - dinaikkan   (mis. refill menulis 5 -> 10)
 *   - diturunkan  (mis. trim menulis 15 -> 12)
 *   - dikosongkan (mis. decommit menulis 15 -> 0, menghasilkan pola invalid 0 -> 5 -> 0 -> 24)
 *
 * Deteksi window memakai sisi KIRI sequence: sebuah startup dimulai pada row pertama unit menyala
 * setelah OFF (atau setelah boundary Stop). Karena row di dalam window bisa saja SUDAH dikosongkan
 * oleh pass sebelumnya, deteksi tidak boleh bergantung pada "row menyala"; window diperpanjang
 * sepanjang len(sequence) dari titik start yang ditemukan.
 *
 * Row Actual/locked, Fixed Load, dan row di dalam Stop operator TIDAK disentuh — jadwal operator
 * selalu menang atas re-assertion. */
/* ===== ANTICIPATORY COMMITMENT FOR RESERVE ====================================================
 * Bila setelah redispatch masih ada row dengan Spinning Reserve di bawah requirement, unit eligible
 * yang masih OFF dinyalakan lebih awal pada MINIMUM STABLE LOAD (bukan pada beban ekonomis), lalu
 * energinya diserap dengan menurunkan Babelan / GTG lain supaya PLN Export tidak berubah.
 *
 * Unit tanpa startup sequence berskrip (mis. G7/G10) langsung reserve-eligible pada Effective Min.
 * Unit Additional HRSG (G1-G6) melewati step 5/15 MW yang BELUM eligible, sehingga baru menolong
 * beberapa row kemudian — tetap dicoba, tetapi diprioritaskan setelah unit non-skrip.
 * Minimum runtime dihormati: unit dinyalakan untuk sekurang-kurangnya durasi minimum. */
function pp_anticipatory_commit_for_reserve(array &$genRows, array $d3, array $model, array $ieVals,
                                            array $actualRows, float $rMin, float $rMax,
                                            array &$evidence, float $gasHi = 0.0): int {
    $n = count($genRows); if ($n === 0) return 0;
    $need = pp_reserve_min($model); if ($need <= 0) return 0;
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $lim = pp_babelan_ramp_limit($model);
    $rt = $model['runtime_downtime'] ?? [];
    $minRun = (int)($rt['minimum_runtime_gtg_large'] ?? ($rt['minimum_runtime'] ?? 12));
    if ($minRun < 1) $minRun = 1;
    $EPS = 1e-6;

    $expOf = function (int $r) use (&$genRows, $ieVals): float {
        $g = $genRows[$r]; $tot = 0.0;
        foreach ($g as $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $gasTotal = function () use (&$genRows, $d3): float {
        $t = 0.0;
        foreach ($genRows as $g)
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
                $t += calc_fuel($d3, $u, (float)($g[$u] ?? 0));
        return $t;
    };
    $frozen = function (string $u, int $r) use ($model, $d3, $actualRows): bool {
        return isset($actualRows[$r]) || pp_get_fixed_load($model, $u, $r + 1) >= 0
               || pp_is_unit_stopped($d3, $model, $u, $r + 1);
    };
    /* serap $amt MW pada row $r dengan menurunkan Babelan lebih dulu, lalu GTG lain */
    $absorb = function (int $r, float $amt, string $skip) use (&$genRows, $d3, $model, $lim, $n, $frozen, $EPS): float {
        foreach (['b1','b2'] as $u) {
            if ($amt <= $EPS) break;
            if (!isset($d3[$u]) || $frozen($u, $r)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0); if ($cur <= 0.51) continue;
            $mn = pp_effective_min_load($d3, $model, $u, $r + 1);
            $flo = $mn;
            if ($r > 0)      $flo = max($flo, (float)$genRows[$r - 1][$u] - $lim);
            if ($r + 1 < $n) $flo = max($flo, (float)$genRows[$r + 1][$u] - $lim);
            $step = min($amt, max(0.0, $cur - $flo));
            if ($step <= $EPS) continue;
            $genRows[$r][$u] = $cur - $step; $amt -= $step;
        }
        foreach (pp_reserve_units($d3, $model) as $u) {
            if ($amt <= $EPS) break;
            if ($u === $skip || $frozen($u, $r)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0); if ($cur <= 0.51) continue;
            $mn = pp_effective_min_load($d3, $model, $u, $r + 1);
            $step = min($amt, max(0.0, $cur - $mn));
            if ($step <= $EPS) continue;
            $genRows[$r][$u] = $cur - $step;
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            $amt -= $step;
        }
        return $amt;
    };

    $applied = 0; $guard = 0;
    while ($guard++ < 6) {
        $deficit = null;
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            if (pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) < $need - $EPS) { $deficit = $r; break; }
        }
        if ($deficit === null) break;
        /* kandidat: unit eligible yang OFF pada row defisit; non-skrip lebih dulu */
        $cands = [];
        foreach (pp_reserve_units($d3, $model) as $u) {
            if ((float)($genRows[$deficit][$u] ?? 0) > 0.51) continue;
            if ($frozen($u, $deficit)) continue;
            $mn = pp_effective_min_load($d3, $model, $u, $deficit + 1);
            $mx = pp_effective_max_load($d3, $model, $u, $deficit + 1);
            if ($mx <= $mn + $EPS) continue;
            $seq = pp_start_sequence($d3, $model, $u, $genRows, $deficit);
            $cands[] = ['u' => $u, 'mn' => $mn, 'gain' => $mx - $mn, 'scripted' => !empty($seq) ? 1 : 0];
        }
        if (!$cands) break;
        usort($cands, fn($a, $b) => [$a['scripted'], -$a['gain']] <=> [$b['scripted'], -$b['gain']]);

        $done = false;
        foreach ($cands as $c) {
            $snapshot = $genRows;
            $u = $c['u']; $mn = $c['mn'];
            $last = min($n - 1, $deficit + $minRun - 1);
            $ok = true;
            for ($r = $deficit; $r <= $last; $r++) {
                if ($frozen($u, $r)) { $ok = false; break; }
                $genRows[$r][$u] = $mn;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($absorb($r, $mn, $u) > $EPS) { $ok = false; break; }      /* energi tidak terserap */
                $e = $expOf($r);
                if ($e < $rMin - $EPS || $e > $rMax + $EPS) { $ok = false; break; }
                if ($busMin > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - $EPS) { $ok = false; break; }
            }
            if ($ok && $gasHi > 0 && $gasTotal() > $gasHi + $EPS) $ok = false;  /* kuota gas */
            if ($ok) {
                $applied++; $done = true;
                $evidence[] = ['anticipatory_commit', sprintf(
                    'row %d-%d: %s dinyalakan pada minimum stable load %.2f MW -> reserve row %d = %.2f MW',
                    $deficit + 1, $last + 1, strtoupper($u), $mn, $deficit + 1,
                    pp_spinning_reserve($genRows[$deficit], $d3, $model, $deficit + 1))];
                break;
            }
            $genRows = $snapshot;
        }
        if (!$done) break;
    }
    return $applied;
}

function pp_reassert_startup_steps(array &$genRows, array $d3, array $model, array $actualRows = []): int {
    $n = count($genRows); if ($n === 0) return 0;
    $units = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $fixed = 0;

    /* --- FASE 0: TUTUP BLIP OFF -------------------------------------------------------------
     * Pola ON -> OFF -> ON dengan gap 1-2 row bukan shutdown sah: gap sependek itu selalu di bawah
     * minimum downtime unit mana pun, dan ia MEMECAH klasifikasi pp_start_sequence() sehingga satu
     * startup terbaca sebagai dua startup (sumber pola 0 -> 5 -> 0 -> 24). Gap ditutup lebih dulu
     * supaya window startup kembali kontigu dan re-assertion di bawah melihat sequence yang benar.
     * Jadwal operator selalu menang: row Actual/locked, Fixed Load, dan Stop window tidak ditutup. */
    foreach ($units as $u) {
        if (!isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
        for ($r = 1; $r < $n - 1; $r++) {
            if ((float)($genRows[$r][$u] ?? 0) > 0.01) continue;              // tidak sedang OFF
            if ((float)($genRows[$r - 1][$u] ?? 0) <= 0.01) continue;         // gap tidak dimulai di sini
            $g = 0; while ($r + $g < $n && (float)($genRows[$r + $g][$u] ?? 0) <= 0.01) $g++;
            if ($g > 2 || $r + $g >= $n) { $r += max(0, $g - 1); continue; }  // shutdown sah / sampai akhir hari
            $blocked = false;
            for ($k = 0; $k < $g; $k++) {
                $rr = $r + $k;
                if (isset($actualRows[$rr]) || pp_get_fixed_load($model, $u, $rr + 1) >= 0
                    || pp_is_unit_stopped($d3, $model, $u, $rr + 1)) { $blocked = true; break; }
            }
            if ($blocked) { $r += max(0, $g - 1); continue; }
            $fill = min((float)$genRows[$r - 1][$u], (float)$genRows[$r + $g][$u]);
            if ($fill <= 0.01) { $r += max(0, $g - 1); continue; }
            for ($k = 0; $k < $g; $k++) {
                $genRows[$r + $k][$u] = $fill;
                pp_recompute_stgs($genRows[$r + $k], $d3, $model, $r + $k + 1);
                $fixed++;
            }
            $r += max(0, $g - 1);
        }
    }

    /* --- FASE 1: tegaskan expected step di dalam startup window ------------------------------ */
    foreach ($units as $u) {
        if (!isset($d3[$u]) || !pp_unit_present($d3, $u)) continue;
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($u)] ?? '')) === 'running');
        for ($r = 0; $r < $n; $r++) {
            $on = ((float)($genRows[$r][$u] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $seq = pp_start_sequence($d3, $model, $u, $genRows, $r);
                if (!$seq) {
                    /* Unit TIDAK berada dalam startup sequence berskrip (bukan Additional HRSG /
                     * STG-fed). Nilai step seperti 5 atau 15 MW tidak sah di sini — unit yang baru
                     * menyala WAJIB berada pada effective min load. */
                    $mnU = pp_effective_min_load($d3, $model, $u, $r + 1);
                    $rr = $r;
                    while ($rr < $n && (float)($genRows[$rr][$u] ?? 0) > 0.01
                           && (float)$genRows[$rr][$u] < $mnU - 1e-6) {
                        if (isset($actualRows[$rr]) || pp_get_fixed_load($model, $u, $rr + 1) >= 0
                            || pp_is_unit_stopped($d3, $model, $u, $rr + 1)) break;
                        $genRows[$rr][$u] = $mnU;
                        pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                        $fixed++; $rr++;
                    }
                    $prevOn = true;
                    continue;
                }
                for ($k = 0; $k < count($seq) && $r + $k < $n; $k++) {
                    $rr = $r + $k;
                    if (isset($actualRows[$rr])) continue;                       // history, tidak boleh diubah
                    if (pp_get_fixed_load($model, $u, $rr + 1) >= 0) continue;    // Fixed Load menang
                    if (pp_is_unit_stopped($d3, $model, $u, $rr + 1)) continue;   // Stop operator menang
                    $want = (float)$seq[$k];
                    $cur  = (float)($genRows[$rr][$u] ?? 0);
                    if (abs($cur - $want) > 1e-6) {
                        $genRows[$rr][$u] = $want;                                // termasuk memulihkan 0 -> step
                        pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                        $fixed++;
                    }
                }
                /* PASCA-WINDOW: row setelah sequence berakhir tidak boleh tertinggal di bawah
                 * effective min load (mis. sisa nilai 5 MW dari blip yang ditutup). Nilai di bawah
                 * min di LUAR window selalu ditolak validator, jadi dinaikkan ke min load. */
                $mnP = pp_effective_min_load($d3, $model, $u, $r + 1);
                $rp = $r + count($seq);
                while ($rp < $n && (float)($genRows[$rp][$u] ?? 0) > 0.01
                       && (float)$genRows[$rp][$u] < $mnP - 1e-6) {
                    if (isset($actualRows[$rp]) || pp_get_fixed_load($model, $u, $rp + 1) >= 0
                        || pp_is_unit_stopped($d3, $model, $u, $rp + 1)) break;
                    $genRows[$rp][$u] = $mnP;
                    pp_recompute_stgs($genRows[$rp], $d3, $model, $rp + 1);
                    $fixed++; $rp++;
                }
                /* lompati seluruh window supaya row di dalamnya tidak dianggap start baru */
                $r += max(0, count($seq) - 1);
                $prevOn = true;
                continue;
            }
            $prevOn = $on;
        }
    }
    return $fixed;
}

function pp_reserve_certificate(array $genRows, array $d3, array $model, array $ieVals, float $gasQuota, float $gasUsed): array {
    $need = pp_reserve_min($model);
    if ($need <= 0) return [];
    /* gas_used dihitung ULANG dari dispatch final (sumber yang sama dgn pass gas lain) supaya
     * gas_room & binding_constraint tidak salah ketika caller belum memiliki nilai gas final. */
    if ($gasUsed <= 0.0) {
        $gasUsed = 0.0;
        foreach ($genRows as $gRow)
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $gu)
                $gasUsed += calc_fuel($d3, $gu, (float)($gRow[$gu] ?? 0));
        $gasUsed /= 2.0;
    }
    $n = count($genRows);
    $rows = []; $worst = null;
    for ($r = 0; $r < $n; $r++) {
        $res = pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1);
        if ($res >= $need - 1e-6) continue;
        $sumMaxOnline = 0.0; $sumLoad = 0.0; $offPot = []; $gasExtra = 0.0;
        foreach (pp_reserve_units($d3) as $u) {
            $v = (float)($genRows[$r][$u] ?? 0);
            $mx = pp_effective_maxload($d3, $model, $u, $r + 1);
            if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
            if ($v > 0.51) { $sumMaxOnline += $mx; $sumLoad += $v; continue; }
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) { $offPot[] = ['unit' => strtoupper($u), 'headroom' => 0.0,
                'reason' => 'stop schedule operator']; continue; }
            $mn = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 20));
            $f  = ($u === 'b1' || $u === 'b2') ? 0.0 : calc_fuel($d3, $u, $mn);
            $offPot[] = ['unit' => strtoupper($u), 'headroom' => round(max(0.0, $mx - $mn), 2),
                         'min_load' => $mn, 'fuel_per_slot' => round($f, 4),
                         'gas_full_day_bbtud' => round($f * 48 / 2.0, 4)];
        }
        $maxTheoretical = $sumMaxOnline - $sumLoad;
        foreach ($offPot as $o) $maxTheoretical += (float)($o['headroom'] ?? 0);
        $deficit = $need - $res;
        /* gas minimum tambahan: pilih unit OFF dgn gas/MW-headroom terbaik sampai defisit tertutup */
        $sorted = array_values(array_filter($offPot, fn($o) => ($o['headroom'] ?? 0) > 0));
        usort($sorted, fn($a, $b) => (($a['gas_full_day_bbtud'] ?? INF) / max(0.01, $a['headroom']))
                                 <=> (($b['gas_full_day_bbtud'] ?? INF) / max(0.01, $b['headroom'])));
        $acc = 0.0; $picked = [];
        foreach ($sorted as $o) { if ($acc >= $deficit) break; $acc += (float)$o['headroom'];
                                  $gasExtra += (float)($o['gas_full_day_bbtud'] ?? 0); $picked[] = $o['unit']; }
        $row = ['row' => $r + 1, 'reserve' => round($res, 2), 'requirement' => $need,
                'deficit_mw' => round($deficit, 2),
                'sigma_max_online' => round($sumMaxOnline, 2), 'sigma_load_online' => round($sumLoad, 2),
                'reserve_max_theoretical_all_units_online' => round($maxTheoretical, 2),
                'units_needed' => $picked, 'headroom_gained_mw' => round($acc, 2),
                'gas_extra_required_bbtud' => round($gasExtra, 4),
                'gas_used_bbtud' => round($gasUsed, 4), 'gas_quota_bbtud' => round($gasQuota, 4),
                'gas_room_bbtud' => round($gasQuota - $gasUsed, 4),
                'binding_constraint' => ($acc + 1e-9 < $deficit)
                        ? 'CAPACITY (headroom unit eligible tidak cukup menutup defisit)'
                        : (($gasExtra > max(0.0, $gasQuota - $gasUsed) + 1e-9)
                            ? sprintf('GAS QUOTA (butuh %.4f BBTUD tambahan, ruang tersedia %.4f BBTUD)',
                                      $gasExtra, max(0.0, $gasQuota - $gasUsed))
                            : 'OPERATIONAL (lihat kandidat ditolak pada Reserve Repair)'),
                'off_units' => $offPot];
        $rows[] = $row;
        if ($worst === null || $row['deficit_mw'] > $worst['deficit_mw']) $worst = $row;
    }
    if (!$rows) return [];
    return ['status' => 'VALID-INFEASIBLE', 'requirement_mw' => $need, 'rows_under' => count($rows),
            'worst_row' => $worst, 'rows' => array_slice($rows, 0, 8),
            'note' => 'Requirement TIDAK diturunkan dan input TIDAK diubah; bukti per row dilampirkan.'];
}

/* ===== EXPORT PEAK SHIFT (gas-neutral) ========================================================
 * Menurunkan row yang melebihi Effective Range Max lalu MENGEMBALIKAN energi/gas yang sama pada row
 * lain yang masih punya headroom export, sehingga:
 *   - Export <= Range Max pada seluruh row,
 *   - total gas harian TIDAK turun (LNG must-take tetap terserap, window tetap terpenuhi),
 *   - Spinning Reserve tiap row tidak memburuk,
 *   - Export Ramp <= 30, Bus Flow >= minimum, ramp unit & Babelan tetap sah.
 * Fase 1 trim over-rows (unit dgn marginal gas terburuk lebih dulu), Fase 2 refill row ber-headroom
 * (unit dgn marginal terbaik lebih dulu) sampai proxy gas kembali ke level semula. */
function pp_export_peak_shift(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                              float $rMin, float $rMax, array &$evidence): int {
    $n = count($genRows); if ($n === 0) return 0;
    $GAS = ['g1','g2','g3','g4','g5','g6','g7','g8','g9'];
    $busUnit = $model['bus_unit'] ?? []; $busMin = (float)($model['busflow_min'] ?? 0);
    $need = pp_reserve_min($model);
    $bbLim = pp_babelan_ramp_limit($model);
    $expOf = function (int $r) use (&$genRows, $ieVals): float {
        $g = $genRows[$r]; $tot = 0.0;
        foreach ($g as $k => $v) if (is_numeric($v)) $tot += (float)$v;
        $mm = 0.0; foreach (['ge1','ge2','ge3','ge4','g10'] as $u) $mm += (float)($g[$u] ?? 0);
        return $tot - $mm - calc_house_load($g) - (float)$ieVals[$r];
    };
    $gasProxy = function () use (&$genRows, $d3): float {
        $s = 0.0;
        foreach ($genRows as $g) foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'] as $u)
            $s += calc_fuel($d3, $u, (float)($g[$u] ?? 0));
        return $s / 2.0;
    };
    $rowOK = function (int $r) use (&$genRows, $expOf, $rMin, $rMax, $n, $busUnit, $busMin, $ieVals, $bbLim, $d3, $model, $need): bool {
        $e = $expOf($r);
        if ($e < $rMin - 1e-9 || $e > $rMax + 1e-6) return false;
        if ($busMin > 0 && calc_busflow($genRows[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) return false;
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            if (abs($e - $expOf($nb)) > pp_export_step_limit($model) + 1e-6) return false;
            foreach (['b1','b2'] as $bu)
                if (abs((float)($genRows[$r][$bu] ?? 0) - (float)($genRows[$nb][$bu] ?? 0)) > $bbLim + 1e-6) return false; }
        return true;
    };
    /* MIN LOAD ABSOLUT: ambil nilai terbesar dari min_ccload / min_scload / min_load yang tersedia,
     * sehingga unit seperti G7 (min 30) tidak pernah ditulis pada nilai antara (mis. 10 MW). */
    $minOf = function (string $u) use ($d3): float {
        $c = [];
        foreach (['min_ccload','min_scload','min_load'] as $kk)
            if (isset($d3[$u][$kk]) && (float)$d3[$u][$kk] > 0) $c[] = (float)$d3[$u][$kk];
        return $c ? max($c) : 20.0;
    };
    $capOf = function (string $u, int $r) use ($d3, $model, &$genRows, $n) {
        $mx = pp_effective_maxload($d3, $model, $u, $r + 1);
        if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
            $mx = min($mx, (float)($genRows[$nb][$u] ?? 0) + 30.0); }
        return $mx;
    };
    $writable = function (string $u, int $r) use ($model, $d3, $actualRows): bool {
        if (isset($actualRows[$r])) return false;
        if (pp_get_fixed_load($model, $u, $r + 1) >= 0) return false;
        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return false;
        return true;
    };
    /* startup window lock: nilai di dalam sequence tidak boleh disentuh trim/refill */
    $suLockP = [];
    foreach ($GAS as $uS) {
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($uS)] ?? '')) === 'running');
        for ($rW = 0; $rW < $n; $rW++) {
            $onW = ((float)($genRows[$rW][$uS] ?? 0) > 0.01);
            if ($onW && !$prevOn) {
                $sqW = pp_start_sequence($d3, $model, $uS, $genRows, $rW);
                for ($kW = 0; $kW < count($sqW) && $rW + $kW < $n; $kW++) $suLockP[$uS][$rW + $kW] = true;
            }
            $prevOn = $onW;
        }
    }
    $gas0 = $gasProxy(); $trimmed = 0; $work = 0; $log = [];

    /* ---- FASE 1: turunkan row yang melewati Range Max ---- */
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $guard = 0;
        while ($expOf($r) > $rMax + 1e-6 && $guard++ < 120 && $work++ < 20000) {
            $gap = $expOf($r) - $rMax;
            $done = false;
            foreach (['g7','g9','g8','g5','g1','g2','g4','g3','g6'] as $u) {
                if (!$writable($u, $r) || isset($suLockP[$u][$r])) continue;      // hormati startup window
                $cur = (float)($genRows[$r][$u] ?? 0);
                if ($cur <= 0.51 || $cur < $minOf($u) + 0.5 - 1e-9) continue;
                $step = min(max(0.5, min($gap, 5.0)), $cur - $minOf($u));
                if ($cur - $step < $minOf($u) - 1e-9) continue;
                $sv = $genRows[$r]; $genRows[$r][$u] = $cur - $step;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($expOf($r) < $rMin - 1e-9 ||
                    ($need > 0 && pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) < pp_spinning_reserve($sv, $d3, $model, $r + 1) - 1e-6)) {
                    $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue;
                }
                $done = true; break;
            }
            if (!$done) break;
        }
        if ($expOf($r) <= $rMax + 1e-6) $trimmed++;
    }
    /* ---- FASE 2: kembalikan gas pada row ber-headroom (gas-neutral) ---- */
    /* Lock dibangun ULANG setelah trim: nilai di dalam startup window tidak boleh dinaikkan refill
     * (bukti bisect: G4 row2 5,00 -> 10,00 ditulis fase refill). */
    $suLockP = pp_build_startup_lock($genRows, $d3, $model);
    $refillWork = 0;
    while ($gasProxy() < $gas0 - 1e-6 && $refillWork++ < 4000) {
        $cands = [];
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            $head = $rMax - $expOf($r);
            if ($head > 0.6) $cands[] = [$r, $head];
        }
        usort($cands, fn($a, $b) => $b[1] <=> $a[1]);
        $done = false;
        foreach ($cands as [$r, $head]) {
            foreach (['g8','g9','g5','g2','g4','g1','g3','g6','g7'] as $u) {
                if (!$writable($u, $r) || isset($suLockP[$u][$r])) continue;   // hormati startup window
                $cur = (float)($genRows[$r][$u] ?? 0);
                if ($cur < 0.51) continue;
                $cap = $capOf($u, $r); if ($cap - $cur < 0.5) continue;
                $inc = min(5.0, $cap - $cur, max(0.5, $head));
                $resBefore = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                $sv = $genRows[$r]; $genRows[$r][$u] = $cur + $inc;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $resAfter = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                /* KUNCI RESERVE: refill tidak boleh menjatuhkan reserve di bawah requirement, dan
                 * untuk row yang sudah kurang tidak boleh membuatnya makin buruk. */
                $resOK = ($need <= 0) || ($resBefore >= $need - 1e-6 ? $resAfter >= $need - 1e-6
                                                                     : $resAfter >= $resBefore - 1e-6);
                if (!$rowOK($r) || $gasProxy() > $gas0 + 1e-6 || !$resOK) {
                    $genRows[$r] = $sv; /* E10 site 10: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue;
                }
                $done = true; break;
            }
            if ($done) break;
        }
        if (!$done) break;
    }
    /* Fase 1 diulang: refill dapat mendorong row lain melewati Range Max. */
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $guard2 = 0;
        while ($expOf($r) > $rMax + 1e-6 && $guard2++ < 200 && $work++ < 30000) {
            $done2 = false;
            foreach (['g7','g9','g8','g5','g1','g2','g4','g3','g6'] as $u) {
                if (!$writable($u, $r) || isset($suLockP[$u][$r])) continue;      // hormati startup window
                $cur = (float)($genRows[$r][$u] ?? 0);
                if ($cur <= 0.51 || $cur < $minOf($u) + 0.5 - 1e-9) continue;
                $step = min(max(0.5, min($expOf($r) - $rMax, 5.0)), $cur - $minOf($u));
                if ($cur - $step < $minOf($u) - 1e-9) continue;                    // jangan di bawah min load
                $resB = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                $sv = $genRows[$r]; $genRows[$r][$u] = $cur - $step;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $resA = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                $okNb = $rowOK($r);
                foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n || isset($actualRows[$nb])) continue;
                    if (!$rowOK($nb)) $okNb = false; }
                if (!$okNb || $resA < $resB - 1e-6) {
                    $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue;
                }
                $done2 = true; break;
            }
            if (!$done2) break;
        }
    }
    /* INVARIAN MIN-LOAD: setelah seluruh lever, tidak boleh ada unit pada nilai antara
     * (0 < load < min_load) di LUAR startup window. Naikkan ke min bila sah, jika tidak matikan. */
    $lockFin = pp_build_startup_lock($genRows, $d3, $model);
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        foreach ($GAS as $u) {
            if (isset($lockFin[$u][$r]) || !$writable($u, $r)) continue;
            $v = (float)($genRows[$r][$u] ?? 0);
            $mn = $minOf($u);
            if ($v <= 0.51 || $v >= $mn - 1e-6) continue;
            $sv = $genRows[$r];
            $genRows[$r][$u] = $mn;                                  // coba naikkan ke min load
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            if (!$rowOK($r)) {
                $genRows[$r] = $sv; $genRows[$r][$u] = 0.0;          // tidak sah -> matikan
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }
    /* ---- FASE 3: REFILL FINAL (gas-neutral, pasca invarian min-load) ----------------------------
     * Invarian min-load dapat menurunkan gas di bawah baseline (mis. 95,9957 -> 95,7750). Kembalikan
     * selisihnya pada row ber-headroom dengan seluruh guard: startup lock, min/max, ramp unit,
     * Export Range dua sisi + Export Ramp, Bus Flow, ramp Babelan, dan reserve tidak boleh turun. */
    $lockR3 = pp_build_startup_lock($genRows, $d3, $model);
    $w3 = 0;
    while ($gasProxy() < $gas0 - 1e-6 && $w3++ < 6000) {
        $cands3 = [];
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            $head = $rMax - $expOf($r);
            if ($head > 0.4) $cands3[] = [$r, $head];
        }
        usort($cands3, fn($a, $b) => $b[1] <=> $a[1]);
        $done3 = false;
        foreach ($cands3 as [$r, $head]) {
            foreach (['g8','g9','g5','g2','g4','g1','g3','g6','g7'] as $u) {
                if (!$writable($u, $r) || isset($lockR3[$u][$r])) continue;
                $cur = (float)($genRows[$r][$u] ?? 0);
                if ($cur < 0.51) continue;                                  // jangan start unit baru di sini
                $cap = $capOf($u, $r);
                if ($cap - $cur < 0.4) continue;
                $inc = min(3.0, $cap - $cur, max(0.4, $head));
                $resB3 = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                $sv3 = $genRows[$r]; $genRows[$r][$u] = $cur + $inc;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $resA3 = ($need > 0) ? pp_spinning_reserve($genRows[$r], $d3, $model, $r + 1) : INF;
                $okNb3 = $rowOK($r);
                foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n || isset($actualRows[$nb])) continue;
                    if (!$rowOK($nb)) $okNb3 = false; }
                $resOK3 = ($need <= 0) || ($resB3 >= $need - 1e-6 ? $resA3 >= $need - 1e-6 : $resA3 >= $resB3 - 1e-6);
                if (!$okNb3 || !$resOK3 || $gasProxy() > $gas0 + 1e-6) {
                    $genRows[$r] = $sv3; /* E10 site 12: recompute pasca-restore DIHAPUS (terbukti redundan) */ continue;
                }
                $done3 = true; break;
            }
            if ($done3) break;
        }
        if (!$done3) break;
    }
    $evidence[] = ['lever' => 'EXPORT_PEAK_SHIFT', 'rows_trimmed' => $trimmed,
                   'gas_before' => round($gas0, 4), 'gas_after' => round($gasProxy(), 4),
                   'work' => $work + $refillWork];
    return $trimmed;
}

/* Peta startup window bersama: (unit,row) yang berada DI DALAM startup sequence tidak boleh
 * ditimpa pass mana pun. Dibangun ulang dari state saat ini memakai pp_start_sequence(). */
function pp_build_startup_lock(array $genRows, array $d3, array $model, array $units = []): array {
    if (!$units) $units = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    $n = count($genRows); $lock = [];
    foreach ($units as $u) {
        if (!isset($d3[$u])) continue;
        $prevOn = (strtolower((string)($model['unit_last_data_status'][strtoupper($u)] ?? '')) === 'running');
        for ($r = 0; $r < $n; $r++) {
            $on = ((float)($genRows[$r][$u] ?? 0) > 0.01);
            if ($on && !$prevOn) {
                $sq = pp_start_sequence($d3, $model, $u, $genRows, $r);
                for ($k = 0; $k < count($sq) && $r + $k < $n; $k++) $lock[$u][$r + $k] = true;
            }
            $prevOn = $on;
        }
    }
    return $lock;
}

/* ============================================================================================
 * KUOTA GAS JABABEKA dalam BBTUD — SATU SUMBER KEBENARAN.
 *
 * BUG SATUAN yang diperbaiki: tiga pass (relief reserve Babelan dan anticipatory start) memakai
 *     pgn_pipe + lng + pep
 * yang menjumlahkan `pep` — sebuah ALIRAN dalam MMSCFD — langsung ke total BBTUD tanpa konversi
 * GHV. Pada input produksi hasilnya 25 + 0 + 30 = 55,0 BBTUD, padahal kuota Jababeka yang benar
 * adalah 25 + 30 x 1080/1000 = 57,4 BBTUD. Enam lokasi lain di file yang sama sudah melakukan
 * konversi dengan benar; ketiga pass itu bekerja terhadap target 2,4 BBTUD terlalu rendah.
 *
 * Fixed flow Jababeka = (pep + akasia + baskara + bbg) MMSCFD x ghv_jababeka / 1000 -> BBTUD.
 * ============================================================================================ */
/* ============================================================================================
 * TRIM GAS MM2100 (unit Gas Engine) — lever penurunan gas yang sebelumnya TIDAK PERNAH dipakai.
 *
 * ROOT CAUSE: seluruh pass koreksi gas hanya menelusuri unit Jababeka g1..g9. Unit Gas Engine
 * MM2100 (ge1..ge4) membakar gas dan ikut dihitung dalam Total Gas Used, tetapi tidak pernah
 * dipertimbangkan sebagai lever penurunan. Reproduksi: pgn_pipe=29,70 (kuota total 64,3000),
 * gas 64,3367 -> deviasi hanya 0,0367 BBTUD sementara GE1 berada di 12,00 MW dengan applicable
 * minimum 3,00 MW pada 5 baris yang Export-nya masih punya kelonggaran 4,4-24,0 MW. Menurunkan
 * GE1 pada dua baris saja sudah membebaskan ~0,064 BBTUD — lebih dari cukup — namun engine
 * berhenti dengan "lever inert" dan melaporkan gas di luar window.
 *
 * SIFAT ALL-OR-NOTHING: perubahan hanya di-commit bila SELURUH kebutuhan tertutup. Bila tidak
 * cukup, rencana dikembalikan persis seperti semula. Dengan begitu pass ini tidak pernah
 * menghasilkan perubahan parsial: rencana yang memang tidak dapat diselamatkan (mis. input
 * produksi dengan deviasi 4,74 BBTUD) tetap identik dengan sebelumnya.
 *
 * Guard yang dihormati: baris actual/terkunci, fixed load, jadwal stop, ketersediaan unit,
 * applicable minimum, Export >= Range Min, Export <= Range Max, BusFlow minimum, dan batas
 * langkah Export antar-baris.
 * ============================================================================================ */
/* ============================================================================================
 * TOP-UP GAS (arah UNDER-TARGET) — cermin dari pp_mm2100_gas_trim untuk arah sebaliknya.
 *
 * ROOT CAUSE (BUG-09): jalur up-dispatch shaper berhenti sebelum mencapai batas bawah window
 * walaupun headroom naik yang legal masih tersedia. Reproduksi: input produksi dengan
 * gas_quota.pgn_pipe=31,40 (kuota total 66,0000) -> engine berhenti 1,6213 BBTUD di bawah
 * window sementara batas atas penambahan yang dihitung audit 11,9759 BBTUD.
 *
 * Pass ini menaikkan unit gas (Jababeka g1..g9 dan MM2100 g10/ge1..ge4) dengan guard lengkap,
 * bersifat ALL-OR-NOTHING: hanya di-commit bila SELURUH kekurangan tertutup. Bila tidak cukup,
 * rencana dikembalikan utuh sehingga tidak pernah ada perubahan parsial dan tidak ada regresi
 * pada rencana yang memang tidak dapat mencapai window.
 *
 * Guard: baris actual/terkunci, fixed load, jadwal stop, ketersediaan unit, applicable maximum,
 * Export <= Range Max, Export >= Range Min, BusFlow minimum, batas langkah Export antar-baris.
 * Anti-overshoot: langkah dipilih dari tangga terkecil yang menutup sisa kebutuhan, karena
 * window hanya selebar 0,04 BBTUD.
 * ============================================================================================ */
/* ============================================================================================
 * ESTIMATOR NILAI MINIMUM YANG TERBUKTI VALID (bounded, deterministik, tervalidasi rerun).
 *
 * ROOT CAUSE (BUG-07): "Recommended LNG" adalah RAW GAS GAP — selisih pemakaian terhadap kuota.
 * Angka itu tidak pernah diuji terhadap hard validation. Pada input produksi, LNG sebesar gap
 * (4,7377) menyisakan pelanggaran export_range, sedangkan 5,5 justru menjatuhkan gas ke BAWAH
 * window (LNG must-take menaikkan kuota). Jadi fungsinya TIDAK monoton dan bisection polos
 * tidak sah.
 *
 * Pendekatan: tangga kandidat menaik yang eksplisit, setiap kandidat DIJALANKAN PENUH dan
 * divalidasi (hard validation + convergence), lalu penyempitan biner HANYA di antara kandidat
 * gagal terakhir dan kandidat lulus pertama — tanpa mengasumsikan monotonisitas di luar
 * bracket yang benar-benar teramati. Seluruh probe dibatasi jumlahnya dan menghormati budget
 * waktu global; bila habis, hasilnya dinyatakan apa adanya (BUDGET_EXHAUSTED), bukan ditebak.
 *
 * MAHAL: satu probe = satu simulasi penuh (10-60 detik). Karena itu pass ini TIDAK dijalankan
 * pada setiap Run. Ia hanya dipanggil lewat mode eksplisit (run.php?mode=shortage_probe) ketika
 * operator meminta angka yang benar-benar dapat dipakai.
 * ============================================================================================ */
function pp_probe_apply_support(array $in, string $kind, float $amount): array {
    /* Label niat bahan bakar diturunkan ulang dari aksi kandidat (bukan diwarisi 'recommendation'
     * dari input dasar), sehingga state kandidat SAMA PERSIS dengan rerun final operator yang
     * memilih jumlah ini. Label ini hanya dipakai untuk `Fuel Action Source`, bukan untuk dispatch. */
    unset($in['data3']['modeling']['__fuel_decision_mode']);
    if ($kind === 'lng') {
        $in['data3']['modeling']['gas_shortage_action'] = 'add_lng';
        $in['data3']['modeling']['additional_lng'] = $amount;
    } else {
        $in['data3']['modeling']['gas_shortage_action'] = 'use_distillate';
        $in['data3']['modeling']['distillate_user_limit_litres'] = $amount;
    }
    return $in;
}

/* Menjalankan satu kandidat dan mengembalikan verdict lengkap. */
/* =============================================================================================
 * RESUME STATE ESTIMATOR BERTAHAP — hash input dan validasi ketat.
 *
 * Pencarian minimum LNG/distillate tidak boleh berjalan sebagai satu request PHP-FPM yang
 * panjang. Ia dipecah menjadi beberapa request; tiap request mengembalikan resume_state dan
 * request berikutnya mengirimkannya kembali. Dua hal yang WAJIB dijaga:
 *   1. state hanya boleh dipakai bila BENAR-BENAR milik input yang sama  -> input_hash;
 *   2. state yang rusak/berjenis salah TIDAK boleh dipakai diam-diam     -> validasi ketat.
 * Keduanya gagal-tertutup: bila ragu, state ditolak dan pencarian dimulai bersih.
 * =========================================================================================== */
function pp_probe_input_hash(array $input): string {
    $d3 = (array)($input['data3'] ?? []);
    $m  = (array)($d3['modeling'] ?? []);
    /* Field kontrol probe TIDAK ikut di-hash: ia berubah tiap ronde dan bukan bagian dari
     * definisi masalah. Semua yang lain ikut, sehingga perubahan kuota, range Export,
     * ketersediaan unit, atau data beban langsung membatalkan state lama. */
    foreach (['__probe_tested', '__probe_state', 'shortage_probe_max_seconds',
              'shortage_probe_max_candidates', 'gas_shortage_action'] as $k) unset($m[$k]);
    ksort($m);
    $d3['modeling'] = $m;
    ksort($d3);
    return substr(hash('sha256', json_encode([
        'data1' => $input['data1'] ?? null,
        'data2' => $input['data2'] ?? null,
        'data3' => $d3,
    ])), 0, 32);
}

/**
 * @return array{status:string, carry:?array}  carry === null berarti state DITOLAK.
 */
function pp_probe_validate_resume($state, $tested, string $hash, string $kind): array {
    $reject = fn(string $why): array => ['status' => $why, 'carry' => null];
    if ($state !== null && !is_array($state))  return $reject('REJECTED_MALFORMED_STATE_NOT_OBJECT');
    if ($tested !== null && !is_array($tested)) return $reject('REJECTED_MALFORMED_TESTED_NOT_OBJECT');
    $st = is_array($state) ? $state : [];
    if ($tested === null) $tested = $st['tested'] ?? null;
    if ($tested === null) $tested = [];
    if (!is_array($tested)) return $reject('REJECTED_MALFORMED_TESTED_NOT_OBJECT');
    if ($st === [] && $tested === []) return $reject('FRESH_EMPTY_RESUME_STATE');
    if (!isset($st['input_hash']) || !is_string($st['input_hash']))
        return $reject('REJECTED_MISSING_INPUT_HASH');
    if (!hash_equals($hash, (string)$st['input_hash']))
        return $reject('REJECTED_STALE_INPUT_HASH');
    if (isset($st['kind']) && strtolower((string)$st['kind']) !== $kind)
        return $reject('REJECTED_KIND_MISMATCH');
    if (!isset($st['raw_gas_gap']) || !is_numeric($st['raw_gas_gap']) || (float)$st['raw_gas_gap'] <= 0)
        return $reject('REJECTED_MISSING_OR_INVALID_GAP');
    if (!isset($st['engine_estimate']) || !is_numeric($st['engine_estimate']) || (float)$st['engine_estimate'] <= 0)
        return $reject('REJECTED_MISSING_OR_INVALID_ESTIMATE');
    /* Tiap entri kandidat harus bertipe benar. Satu entri rusak membatalkan SELURUH state —
     * memakai sebagiannya berisiko melewatkan kandidat yang sebenarnya belum pernah diuji. */
    $clean = [];
    foreach ($tested as $amt => $row) {
        if (!is_numeric($amt) || !is_array($row))         return $reject('REJECTED_MALFORMED_TESTED_ENTRY');
        foreach (['valid', 'converged'] as $bk)
            if (!array_key_exists($bk, $row) || !is_bool($row[$bk])) return $reject('REJECTED_TESTED_ENTRY_TYPE');
        if (!isset($row['violations']) || !is_int($row['violations']) || $row['violations'] < 0)
            return $reject('REJECTED_TESTED_ENTRY_TYPE');
        $clean[(string)$amt] = [
            'valid'               => (bool)$row['valid'],
            'violations'          => (int)$row['violations'],
            'converged'           => (bool)$row['converged'],
            'gas_used_bbtud'      => isset($row['gas_used_bbtud'])  ? (float)$row['gas_used_bbtud']  : null,
            'gas_quota_bbtud'     => isset($row['gas_quota_bbtud']) ? (float)$row['gas_quota_bbtud'] : null,
            'binding_constraints' => is_array($row['binding_constraints'] ?? null) ? $row['binding_constraints'] : [],
        ];
    }
    return ['status' => 'RESUMED_VALID', 'carry' => [
        'tested'          => $clean,
        'raw_gas_gap'     => (float)$st['raw_gas_gap'],
        'engine_estimate' => (float)$st['engine_estimate'],
        'two_phase'       => is_array($st['two_phase'] ?? null) ? $st['two_phase'] : null,
    ]];
}

function pp_probe_run_candidate(array $in, string $kind, float $amount, float $budgetSeconds = 60.0): array {
    $probe = pp_probe_apply_support($in, $kind, $amount);
    /* Konteks worker asinkron BUKAN state simulasi — ia menentukan plafon waktu job dan wajib
     * bertahan lintas kandidat. Tanpa penyelamatan ini kandidat kedua dan seterusnya jatuh kembali
     * ke plafon 60 detik lalu dilaporkan "tidak konvergen" padahal hanya kehabisan jam. */
    $__keep = [];
    foreach (['__pp_async_worker', '__pp_async_worker_ceiling'] as $kg)
        if (isset($GLOBALS[$kg])) $__keep[$kg] = $GLOBALS[$kg];
    foreach (array_keys($GLOBALS) as $g) if (str_starts_with($g, '__pp_')) unset($GLOBALS[$g]);
    foreach ($__keep as $kg => $kv) $GLOBALS[$kg] = $kv;
    /* Tiap probe adalah simulasi mandiri: budget/deadline di-arm ulang supaya probe kedua dan
     * seterusnya tidak mewarisi budget yang sudah habis dipakai probe sebelumnya.
     *
     * BATAS PER KANDIDAT (perbaikan): sebelumnya tiap kandidat SELALU mendapat plafon 60 detik
     * penuh, sehingga satu request FPM dapat berjalan 45 detik (batas pencarian) DITAMBAH 60
     * detik kandidat terakhir = 105 detik. Pemanggil kini mengoper SISA waktu request, dan
     * plafon kandidat tidak pernah melampauinya. Nilai juga dititipkan ke input agar deadline
     * internal engine memakai angka yang sama. */
    $budgetSeconds = max(5.0, min(pp_budget_ceiling(), $budgetSeconds));
    $probe['data3']['modeling']['time_budget_max_seconds'] = $budgetSeconds;
    /* Probe jumlah bahan bakar hanya menilai kelayakan jumlah; keluarga commitment dilengkapi pada
     * run final atas jumlah yang dipilih (lihat pp_memo_apply_family). */
    $probe['data3']['modeling']['__no_exact_family'] = true;
    pp_budget_start($budgetSeconds, true, true);
    $t = microtime(true);
    /* Kandidat yang statenya sudah pernah dihitung penuh dipakai ulang (lihat pp_sim_memo_run);
     * kandidat yang dihitung di sini juga disimpan, sehingga rerun final atas jumlah yang sama
     * tidak menghitungnya lagi. */
    $out = function_exists('pp_sim_memo_run') ? pp_sim_memo_run($probe) : pp_run_simulation($probe);
    $wall = microtime(true) - $t;
    $V = pp_validate_hard_constraints($probe, $out);
    $viol = array_values((array)($V['violations'] ?? []));
    $rs = (array)($out['info']['Run Status'] ?? []);
    $converged = !empty($rs['converged']) && empty($rs['deadline_reached']) && empty($rs['stages_truncated']);
    /* Sebuah angka baru boleh disebut "kebutuhan LNG/distillate" bila rerun kandidatnya JUGA
     * menyelesaikan economic review. Tanpa syarat ini, angka yang comparator-nya dilewati karena
     * waktu tetap lolos sebagai rekomendasi final. */
    $econDone  = ($rs['economic_review_completed'] ?? null) === true;
    $types = [];
    foreach ($viol as $v) { $t2 = (string)($v[0] ?? 'unknown'); $types[$t2] = ($types[$t2] ?? 0) + 1; }
    return [
        'amount' => $amount,
        'valid' => (count($viol) === 0 && $converged && $econDone),
        'converged_only' => (count($viol) === 0 && $converged),
        'economic_review_completed' => $econDone,
        'economic_review_skipped' => $rs['economic_review_skipped'] ?? null,
        'run_status' => (string)($rs['status'] ?? ''),
        'violations' => count($viol),
        'binding_constraints' => $types,
        'converged' => $converged,
        'gas_used_bbtud' => round((float)($out['info']['Total Gas Used (BBTUD)'] ?? 0), 4),
        'gas_quota_bbtud' => round((float)($out['info']['Total Gas Quota (BBTUD)'] ?? 0), 4),
        'distillate_litres' => round((float)($out['info']['Distillate Fuel Total (l)'] ?? 0), 1),
        'energy_reconciliation' => $out['info']['Distillate Energy Reconciliation'] ?? null,
        'wall_s' => round($wall, 2),
        'violation_samples' => array_map(fn($v) => substr((string)($v[1] ?? ''), 0, 110), array_slice($viol, 0, 3)),
    ];
}

/**
 * @param string $kind 'lng' (BBTUD) atau 'distillate' (liter/hari)
 */
function pp_probe_minimum_feasible_support(array $input, string $kind, float $rawGap,
                                           float $estimate, float $maxPhysical, int $maxProbes = 7): array {
    $unit = ($kind === 'lng') ? 'BBTUD' : 'l/day';
    $result = [
        'schema' => 'co12-minimum-feasible-support-v1',
        'kind' => $kind, 'unit' => $unit,
        'raw_gas_gap' => round($rawGap, 4),
        'engine_estimate' => round($estimate, 4),
        'estimated_minimum_feasible_support' => null,
        'validated_by_rerun' => false,
        'validation_status' => 'NO_FEASIBLE_AMOUNT_FOUND',
        'residual_after_validation' => null,
        'binding_constraints' => [],
        'probes' => [], 'probe_count' => 0, 'max_probes' => $maxProbes,
        'search' => 'LADDER_NAIK_LALU_BISECTION_DI_DALAM_BRACKET_TERAMATI',
        'note' => 'Setiap kandidat dijalankan penuh dan divalidasi; monotonisitas TIDAK diasumsikan di luar bracket yang teramati.',
    ];
    if ($estimate <= 0 || $maxPhysical <= 0) {
        $result['validation_status'] = 'TIDAK_ADA_ESTIMASI_AWAL';
        return $result;
    }
    /* tangga kandidat: kelipatan estimasi, dibatasi batas fisik */
    $ladder = [];
    foreach ([1.0, 1.25, 1.5, 1.75, 2.0] as $m) {
        $v = round($estimate * $m, ($kind === 'lng') ? 4 : 1);
        if ($v > $maxPhysical) break;
        if (!in_array($v, $ladder, true)) $ladder[] = $v;
    }
    $probes = []; $firstOk = null; $lastFail = null; $used = 0;
    $tStart = microtime(true);
    /* BATAS AMAN PER REQUEST: satu request web tidak boleh menjalankan pencarian panjang.
     * Default 45 detik — di bawah batas request 60 detik — sehingga probe SELALU kembali cepat
     * dengan status validation_pending bila belum selesai, dan UI melanjutkannya pada request
     * berikutnya memakai daftar kandidat yang sudah diuji (resume). */
    /* Plafon pencarian per request DI-CLAMP 10..45 detik. Input tidak dapat mendorongnya ke
     * angka yang membuat satu request PHP-FPM melewati 60 detik. */
    /* Batas 42 detik itu MILIK REQUEST SINKRON. Worker CLI asinkron tidak punya request yang
     * menunggu dan tugasnya menyelesaikan pencarian sampai tuntas, jadi plafonnya adalah plafon
     * job. pp_budget_ceiling() mengembalikan 60,0 di luar worker sehingga jalur web tidak dapat
     * menyentuh cabang ini. */
    $capHard = (pp_budget_ceiling() > 60.0) ? max(60.0, pp_budget_ceiling() - 30.0) : 42.0;
    $capSeconds = max(5.0, min($capHard, (float)($input['data3']['modeling']['shortage_probe_max_seconds'] ?? 40.0)));
    /* SISA WAKTU MINIMUM UNTUK MEMULAI SATU KANDIDAT.
     * Kandidat yang dimulai dengan anggaran terlalu tipis akan dipotong deadline dan dilaporkan
     * TIDAK konvergen — yaitu dinilai GAGAL padahal sebenarnya belum diuji. Karena itu kandidat
     * hanya dimulai bila sisa waktu masih cukup untuk menampungnya utuh. Nilai awal 20 detik
     * (satu simulasi penuh input produksi terukur ~19 detik) lalu disesuaikan dengan durasi
     * kandidat yang benar-benar terjadi di dalam request ini. */
    $minSlice = 20.0;
    $lastDur  = 0.0;
    $already = (array)($input['data3']['modeling']['__probe_tested'] ?? []);   // resume antar-request
    foreach ($ladder as $v) {
        if (isset($already[(string)$v])) {                       // hasil dari request sebelumnya
            $p0 = (array)$already[(string)$v]; $p0['amount'] = $v; $p0['resumed'] = true;
            $probes[] = $p0;
            if (!empty($p0['valid'])) { $firstOk = $p0; break; }
            $lastFail = $p0; continue;
        }
        if ($used >= $maxProbes) { $result['validation_status'] = 'PROBE_LIMIT_TERCAPAI'; break; }
        /* Kandidat hanya dimulai bila SISA waktu request masih cukup untuk menampungnya utuh. */
        $remain = $capSeconds - (microtime(true) - $tStart);
        if ($remain < $minSlice) { $result['validation_status'] = 'VALIDATION_PENDING'; break; }
        $tCand = microtime(true);
        $p = pp_probe_run_candidate($input, $kind, $v, $remain); $used++;
        $lastDur = microtime(true) - $tCand;
        $minSlice = max($minSlice, $lastDur * 1.15);
        $probes[] = $p;
        if ($p['valid']) { $firstOk = $p; break; }
        $lastFail = $p;
    }
    /* penyempitan biner HANYA di dalam bracket yang benar-benar teramati (gagal -> lulus) */
    if ($firstOk !== null && $lastFail !== null) {
        $lo = (float)$lastFail['amount']; $hi = (float)$firstOk['amount'];
        for ($k = 0; $k < 2; $k++) {
            $remain = $capSeconds - (microtime(true) - $tStart);
            if ($used >= $maxProbes || $remain < $minSlice) break;
            $mid = round(($lo + $hi) / 2.0, ($kind === 'lng') ? 4 : 1);
            if ($mid <= $lo + 1e-9 || $mid >= $hi - 1e-9) break;
            /* REUSE (perbaikan): kandidat penyempitan biner juga harus menghormati resume state.
             * Tanpa ini, ronde lanjutan menjalankan ULANG nilai tengah yang sudah pernah diuji
             * pada ronde sebelumnya — satu simulasi penuh terbuang dan janji "kandidat yang sudah
             * diuji tidak dijalankan ulang" dilanggar. */
            if (isset($already[(string)$mid])) {
                $p = (array)$already[(string)$mid]; $p['amount'] = $mid; $p['resumed'] = true;
                $probes[] = $p;
                if (!empty($p['valid'])) { $firstOk = $p; $hi = $mid; } else { $lo = $mid; }
                continue;
            }
            $tCand = microtime(true);
            $p = pp_probe_run_candidate($input, $kind, $mid, $remain); $used++;
            $lastDur = microtime(true) - $tCand;
            $minSlice = max($minSlice, $lastDur * 1.15);
            $probes[] = $p;
            if ($p['valid']) { $firstOk = $p; $hi = $mid; } else { $lo = $mid; }
        }
    }
    $result['probes'] = $probes;
    $result['probe_count'] = $used;
    /* State lanjutan: dikirim balik oleh UI pada request berikutnya sebagai __probe_tested,
     * sehingga pencarian berlanjut tanpa mengulang kandidat yang sudah diuji. */
    /* BUG-13 (FATAL, ditemukan lewat uji HTTP end-to-end): blok ini memakai BAREWORD sebagai
     * kunci dan nilai array (`valid`, `violations`, `data3.modeling.__probe_tested`, ...).
     * Pada PHP 8 bareword yang tidak terdefinisi adalah Error fatal, bukan notice seperti PHP 7,
     * sehingga endpoint `?mode=shortage_probe` SELALU berakhir
     * "Uncaught Error: Undefined constant" begitu pencarian benar-benar dijalankan. Seluruh
     * kunci dan nilai kini menjadi string yang benar. */
    $tested = $already;
    foreach ($probes as $pr) $tested[(string)$pr['amount']] = [
        'valid'               => (bool)$pr['valid'],
        'violations'          => (int)$pr['violations'],
        'converged'           => (bool)$pr['converged'],
        'gas_used_bbtud'      => $pr['gas_used_bbtud'],
        'gas_quota_bbtud'     => $pr['gas_quota_bbtud'],
        'binding_constraints' => $pr['binding_constraints']];
    $result['resume_state'] = ['field' => 'data3.modeling.__probe_tested', 'tested' => $tested];
    $result['elapsed_s'] = round(microtime(true) - $tStart, 2);
    $result['per_request_cap_s'] = $capSeconds;
    if ($firstOk !== null) {
        $result['estimated_minimum_feasible_support'] = $firstOk['amount'];
        $result['validated_by_rerun'] = true;
        $result['validation_status'] = 'VALIDATED_MINIMUM_IN_LADDER';
        $result['residual_after_validation'] = 0.0;
        $result['validated_run'] = ['gas_used_bbtud' => $firstOk['gas_used_bbtud'],
            'gas_quota_bbtud' => $firstOk['gas_quota_bbtud'], 'violations' => 0,
            'distillate_litres' => $firstOk['distillate_litres']];
        $result['note'] .= ' Nilai ini adalah kandidat TERKECIL pada tangga yang terbukti lulus, bukan minimum global.';
    } else {
        if ($result['validation_status'] === 'NO_FEASIBLE_AMOUNT_FOUND' && $lastFail !== null) {
            $result['residual_after_validation'] = $lastFail['violations'];
            $result['binding_constraints'] = $lastFail['binding_constraints'];
        }
    }
    return $result;
}

function pp_gas_topup_pass(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                           float $rMin, float $rMax, float $needBBTU, callable $realisedExport,
                           array &$evidence, ?array $onlyUnits = null, ?float $maxAdd = null): float {
    if ($needBBTU <= 1e-9) return 0.0;
    /* $onlyUnits — membatasi lever pada himpunan unit tertentu. Dipakai lantai kuota MM2100, yang
     * merupakan kendala TERSENDIRI: menaikkan unit Jababeka tidak pernah memperbaikinya.
     * $maxAdd    — plafon penambahan. Lantai dan langit-langit kuota MM2100 hanya berjarak 0,04
     *              BBTUD, sehingga langkah yang menutup lantai tidak boleh menembus langit-langit. */
    $UNITS = $onlyUnits ?: ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'];
    $n = count($genRows);
    $busUnit = (array)($model['bus_unit'] ?? []);
    $busMin  = (float)($model['busflow_min'] ?? 0);
    $expOf = fn(array $gen, float $ie): float => (float)$realisedExport($gen, $ie)['export'];

    $cand = [];
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $r1 = $r + 1;
        foreach ($UNITS as $u) {
            if (!pp_unit_present($d3, $u)) continue;
            if (!pp_effective_unit_available($d3, $model, $u, $r1)) continue;
            if (pp_is_unit_stopped($d3, $model, $u, $r1)) continue;
            if (pp_get_fixed_load($model, $u, $r1) >= 0) continue;
            $mw = (float)($genRows[$r][$u] ?? 0);
            if ($mw <= 0.001) continue;                       // unit OFF: penempatan start bukan urusan pass ini
            $mx = pp_effective_max_load($d3, $model, $u, $r1);
            if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
            $up = $mx - $mw;
            if ($up <= 0.05) continue;
            $gain = (calc_fuel($d3, $u, $mw + $up) - calc_fuel($d3, $u, $mw)) / 2.0;
            if ($gain <= 1e-12) continue;
            $cand[] = ['r' => $r, 'u' => $u, 'mw' => $mw, 'max' => $mx, 'max_up' => $up,
                       'rate' => $gain / $up];
        }
    }
    if (!$cand) {
        $evidence[] = ['pass' => 'GAS_TOPUP', 'applied' => false, 'needed_bbtud' => round($needBBTU, 6),
            'available_bbtud' => 0.0, 'note' => 'tidak ada unit gas running dengan headroom naik yang legal'];
        return 0.0;
    }
    usort($cand, fn($a, $b) => $b['rate'] <=> $a['rate']);    // laju gas per MW tertinggi lebih dulu

    $trial = $genRows; $addedTotal = 0.0; $steps = [];
    foreach ($cand as $c) {
        if ($addedTotal >= $needBBTU - 1e-9) break;
        $r = $c['r']; $u = $c['u']; $r1 = $r + 1;
        $hi = $c['max']; $cur = (float)$trial[$r][$u];
        $room = $hi - $cur;
        if ($room <= 0.05) continue;
        $sisa = $needBBTU - $addedTotal;
        /* tangga dari terkecil yang menutup sisa (anti-overshoot), lalu terbesar sebagai cadangan */
        $ladder = [$room * 0.25, $room * 0.5, $room * 0.75, $room];
        $pilih = null;
        foreach ($ladder as $tryUp) {
            $g = (calc_fuel($d3, $u, min($hi, $cur + $tryUp)) - calc_fuel($d3, $u, $cur)) / 2.0;
            if ($g >= $sisa - 1e-12) { $pilih = $tryUp; break; }
        }
        /* PENYEMPITAN ANTI-OVERSHOOT (hanya ketika pemanggil memang peduli pada plafon).
         * Tangga seperempat terlalu kasar untuk jendela selebar 0,04 BBTUD: pada lantai kuota
         * MM2100 ia menaikkan GE3 dari 3,00 langsung ke 12,50 MW dan menambah 0,0337 BBTUD padahal
         * yang dibutuhkan 0,0268 — kelebihan 0,0069 BBTUD yang justru memakan ruang jendela gas
         * TOTAL yang sedang sempit. Di sini langkah dipersempit secara biner ke nilai TERKECIL yang
         * masih menutup sisa kebutuhan, sehingga penambahan mendekati kebutuhan, bukan melampauinya. */
        if ($maxAdd !== null && $pilih !== null) {
            $loStep = 0.0; $hiStep = $pilih;
            for ($bi = 0; $bi < 12 && ($hiStep - $loStep) > 0.01; $bi++) {
                $mid = 0.5 * ($loStep + $hiStep);
                $gMid = (calc_fuel($d3, $u, min($hi, $cur + $mid)) - calc_fuel($d3, $u, $cur)) / 2.0;
                if ($gMid >= $sisa - 1e-12) $hiStep = $mid; else $loStep = $mid;
            }
            if ($hiStep > 0.05 && $hiStep < $pilih) $pilih = $hiStep;
        }
        $order = ($pilih !== null) ? [$pilih, $room] : [$room, $room * 0.75, $room * 0.5, $room * 0.25];
        $best = 0.0;
        foreach ($order as $tryUp) {
            if ($tryUp <= 0.05) continue;
            /* PLAFON: langkah yang menembus batas atas penambahan ditolak SEBELUM dicoba, bukan
             * dibatalkan sesudahnya — menutup lantai kuota tidak boleh menciptakan pelanggaran
             * langit-langit kuota yang baru. */
            if ($maxAdd !== null) {
                $gTry = (calc_fuel($d3, $u, min($hi, $cur + $tryUp)) - calc_fuel($d3, $u, $cur)) / 2.0;
                if ($addedTotal + $gTry > $maxAdd + 1e-9) continue;
            }
            $save = $trial[$r];
            $trial[$r][$u] = min($hi, $cur + $tryUp);
            pp_recompute_stgs($trial[$r], $d3, $model, $r1);
            $ok = true;
            $e = $expOf($trial[$r], (float)$ieVals[$r]);
            if ($e > $rMax + 1e-6 || $e < $rMin - 1e-6) $ok = false;
            if ($ok && $busMin > 0 && calc_busflow($trial[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) $ok = false;
            if ($ok) foreach ([$r - 1, $r + 1] as $nb) {
                if ($nb < 0 || $nb >= $n) continue;
                $eNb = $expOf($trial[$nb], (float)$ieVals[$nb]);
                if (abs($e - $eNb) > pp_export_step_limit($model) + 1e-6) { $ok = false; break; }
            }
            if (!$ok) { $trial[$r] = $save; pp_recompute_stgs($trial[$r], $d3, $model, $r1); continue; }
            $best = (float)$trial[$r][$u] - $cur;
            break;
        }
        if ($best <= 0.0) continue;
        $gain = (calc_fuel($d3, $u, $cur + $best) - calc_fuel($d3, $u, $cur)) / 2.0;
        $addedTotal += $gain;
        $steps[] = ['row' => $r1, 'unit' => strtoupper($u), 'from_mw' => round($cur, 2),
                    'to_mw' => round($cur + $best, 2), 'added_bbtud' => round($gain, 6)];
    }
    if ($addedTotal < $needBBTU - 1e-9) {
        /* USABLE, bukan teoretis: inilah jumlah yang benar-benar dapat diserap setelah SELURUH
         * guard diuji satu per satu. Angka ini yang dilaporkan, bukan batas atas optimistis. */
        $evidence[] = ['pass' => 'GAS_TOPUP', 'applied' => false,
            'needed_bbtud' => round($needBBTU, 6), 'usable_bbtud' => round($addedTotal, 6),
            'candidate_steps' => count($steps),
            'note' => 'all-or-nothing: kebutuhan tidak tertutup oleh headroom yang benar-benar legal, rencana dikembalikan utuh'];
        return 0.0;
    }
    $genRows = $trial;
    $evidence[] = ['pass' => 'GAS_TOPUP', 'applied' => true,
        'needed_bbtud' => round($needBBTU, 6), 'added_bbtud' => round($addedTotal, 6), 'steps' => $steps];
    return $addedTotal;
}

/* $maximal=true: dipakai pada jalur MINIMISASI SHORTAGE. Definisi bisnis mewajibkan Export/gas
 * diturunkan SEJAUH masih legal sebelum residual disebut Gas Shortage, sehingga penurunan
 * SEBAGIAN wajib di-commit. $maximal=false (default) tetap all-or-nothing untuk jalur
 * pendaratan window, agar rencana yang tidak dapat mendarat tidak berubah sebagian. */
/* =============================================================================================
 * KUOTA MM2100 — replikasi PERSIS metrik engine/validator, dipakai sebagai GUARD lantai bawah
 * pada lever penurunan gas MM2100.
 *
 * ROOT CAUSE (BUG-11, ditemukan lewat GS-10 pada build dua fase): mode MAKSIMAL menurunkan GE1
 * dari 12 MW ke applicable minimum 3 MW pada banyak baris, sehingga MM2100 Used+Startup jatuh
 * dari 2,1600 ke 0,6413 BBTUD dan MELANGGAR jendela bawah kuota MM2100 (quota - 0,04). Guard
 * lama hanya menjaga Export, BusFlow, applicable minimum, dan batas langkah — lantai kuota
 * MM2100 tidak pernah diperiksa karena mode all-or-nothing tidak pernah menurunkan sebanyak ini.
 *
 * Formula mengikuti worker02.php:
 *   quota  = Σ kp72 fixed-flow × GHV_MM2100/1000  +  Σ kp72 cummulative (sudah BBTUD)
 *   used   = Σ_baris round( (Σ calc_fuel(g10,ge1..ge4)) / 2 , 5 )        [Est_FF_M per baris]
 * =========================================================================================== */
function pp_mm2100_quota_energy_bbtud(array $model): float {
    $q    = (array)($model['gas_quota'] ?? []);
    $ghvM = (float)($model['ghv_mm2100'] ?? 1000);
    $gmMM = (array)($model['mm2100_gas_mode'] ?? []);
    $cumKey = null;
    foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $gk) {
        $m = strtolower((string)($gmMM[$gk] ?? 'fixed'));
        if (($m === 'cummulative' || $m === 'cumulative') && $cumKey === null) $cumKey = $gk;
    }
    $tot = 0.0;
    foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $gk) {
        $qq = (float)($q[$gk] ?? 0);
        $tot += ($gk === $cumKey) ? $qq : ($qq * $ghvM / 1000.0);
    }
    return $tot;
}

function pp_mm2100_used_bbtud(array $genRows, array $d3, ?array $model = null): float {
    $set = ['g10','ge1','ge2','ge3','ge4'];
    $s = 0.0; $est = [];
    foreach (array_values($genRows) as $i => $row) {
        $rowGas = 0.0;
        foreach ($set as $u)
            if (isset($d3[$u]) && (float)($row[$u] ?? 0) > 0.01) $rowGas += calc_fuel($d3, $u, (float)$row[$u]);
        $est[$i] = round($rowGas / 2.0, 5);
        $s += $est[$i];
    }
    /* V5 — UKURAN YANG SAMA DENGAN VALIDATOR. Dengan Actual Energy MM2100 terisi, pemakaian harian
     * dinilai sebagai actual per jam untuk jam ber-actual dan estimasi untuk jam lain. Sebelumnya
     * pass lantai/trim kuota MM2100 membidik tengah jendela dengan estimasi saja (terukur 2,18006),
     * lalu validator menilai total blended 2,20004 > kuota 2,2000 — rencana ditolak karena 0,00004.
     * Dipakai hanya bila $model diberikan (plafon lantai MM2100); PP_V5_MM_BLEND=0 mematikan. */
    if ($model !== null && (string)getenv('PP_V5_MM_BLEND') !== '0') {
        /* baris dengan Fixed Flow MM2100 manual (Ctrl+Click): energi baris = flow x GHV/1000/48 */
        $ghvM = (float)($model['ghv_mm2100'] ?? 0);
        foreach ((array)($model['manual_fixed_flows'] ?? []) as $mf) {
            if (strtoupper((string)($mf['area'] ?? '')) !== 'MM2100' || $ghvM <= 0) continue;
            $ri = (int)($mf['row'] ?? 0) - 1; if (!isset($est[$ri])) continue;
            $ne = round(((float)($mf['value_mmscfd'] ?? $mf['value'] ?? 0)) * $ghvM / 1000.0 / 48.0, 5);
            $s += $ne - $est[$ri]; $est[$ri] = $ne;
        }
        $aM = (array)($model['actual_energy_mm2100'] ?? []);
        if ($aM) {
            $cell = function ($i) use ($aM) { $v = $aM[$i] ?? null; return ($v === null || $v === '') ? null : (float)$v; };
            for ($h = 0; $h < intdiv(count($est), 2); $h++) {
                $a = $cell(2 * $h); $b = $cell(2 * $h + 1);
                $act = ($a !== null && $b !== null) ? $a + $b : ($a !== null ? $a : $b);
                if ($act !== null) $s += $act - ($est[2 * $h] + ($est[2 * $h + 1] ?? 0.0));
            }
        }
    }
    return $s;
}

function pp_mm2100_gas_trim(array &$genRows, array $d3, array $model, array $ieVals, array $actualRows,
                            float $rMin, float $rMax, float $needBBTU, callable $realisedExport,
                            array &$evidence, bool $maximal = false): float {
    if ($needBBTU <= 1e-9) return 0.0;
    /* GUARD LANTAI KUOTA MM2100 (BUG-11). Penurunan TOTAL dibatasi oleh jarak metrik MM2100
     * saat ini ke batas bawah jendela kuotanya (quota - 0,04). Tanpa batas ini mode MAKSIMAL
     * menukar satu pelanggaran (gas Jababeka) dengan pelanggaran lain (MM2100 under-absorbed). */
    $mmQuotaM = pp_mm2100_quota_energy_bbtud($model);
    if ($mmQuotaM > 1e-9) {
        $mmRoom = pp_mm2100_used_bbtud($genRows, $d3) - ($mmQuotaM - 0.04);
        if ($mmRoom <= 1e-9) {
            $evidence[] = ['pass' => $maximal ? 'MM2100_GAS_TRIM_MAKSIMAL' : 'MM2100_GAS_TRIM',
                'applied' => false, 'mm2100_quota_bbtud' => round($mmQuotaM, 6),
                'mm2100_used_bbtud' => round(pp_mm2100_used_bbtud($genRows, $d3), 6),
                'note' => 'MM2100 sudah berada di batas bawah jendela kuotanya; penurunan lebih lanjut akan melanggar kuota MM2100'];
            return 0.0;
        }
        if ($needBBTU > $mmRoom) {
            /* Jalur PENDARATAN WINDOW tetap all-or-nothing: bila kebutuhan tidak muat di dalam
             * ruang kuota MM2100, menutupnya berarti menukar pelanggaran gas Jababeka dengan
             * pelanggaran kuota MM2100. Rencana dikembalikan utuh. Jalur MINIMISASI SHORTAGE
             * memang menerima penurunan sebagian, jadi kebutuhannya dibatasi ke ruang yang ada. */
            if (!$maximal) {
                $evidence[] = ['pass' => 'MM2100_GAS_TRIM', 'applied' => false,
                    'needed_bbtud' => round($needBBTU, 6), 'mm2100_room_bbtud' => round($mmRoom, 6),
                    'note' => 'kebutuhan melebihi sisa ruang kuota MM2100; rencana dikembalikan utuh'];
                return 0.0;
            }
            $needBBTU = $mmRoom;
        }
    }
    $GE = ['ge1','ge2','ge3','ge4'];
    $n = count($genRows);
    $busUnit = (array)($model['bus_unit'] ?? []);
    $busMin  = (float)($model['busflow_min'] ?? 0);
    /* Export memakai CLOSURE AKUNTANSI ENGINE yang dioper dari pemanggil — tidak ada formula
     * export kedua di dalam file ini. */
    $expOf = fn(array $gen, float $ie): float => (float)$realisedExport($gen, $ie)['export'];
    /* Kandidat: (baris, unit, MW yang boleh diturunkan, gas yang dibebaskan). */
    $cand = [];
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        $r1 = $r + 1;
        foreach ($GE as $u) {
            if (!pp_unit_present($d3, $u)) continue;
            if (!pp_effective_unit_available($d3, $model, $u, $r1)) continue;
            if (pp_is_unit_stopped($d3, $model, $u, $r1)) continue;
            if (pp_get_fixed_load($model, $u, $r1) >= 0) continue;
            $mw = (float)($genRows[$r][$u] ?? 0);
            if ($mw <= 0.001) continue;
            $mn = pp_effective_min_load($d3, $model, $u, $r1);
            $dn = $mw - $mn;
            if ($dn <= 0.05) continue;
            $freed = (calc_fuel($d3, $u, $mw) - calc_fuel($d3, $u, $mw - $dn)) / 2.0;
            if ($freed <= 1e-12) continue;
            $cand[] = ['r' => $r, 'u' => $u, 'mw' => $mw, 'min' => $mn, 'max_dn' => $dn,
                       'rate' => $freed / $dn];
        }
    }
    if (!$cand) return 0.0;
    /* laju gas per MW tertinggi lebih dulu: kebutuhan tertutup dengan perubahan MW paling kecil */
    usort($cand, fn($a, $b) => $b['rate'] <=> $a['rate']);

    $trial = $genRows;                       // salinan kerja — commit hanya bila cukup
    $freedTotal = 0.0; $steps = [];
    foreach ($cand as $c) {
        if ($freedTotal >= $needBBTU - 1e-9) break;
        $r = $c['r']; $u = $c['u']; $r1 = $r + 1;
        $lo = $c['min']; $hi = (float)$trial[$r][$u];
        /* cari penurunan terbesar yang masih legal, lalu potong seperlunya saja */
        $best = 0.0;
        /* ANTI-OVERSHOOT: window hanya selebar 0,04 BBTUD, sehingga langkah terakhir tidak boleh
         * membebaskan jauh lebih banyak daripada sisa kebutuhan — gas bisa jatuh DI BAWAH window.
         * Tangga dicoba dari yang TERKECIL yang masih menutup sisa kebutuhan; bila tidak ada yang
         * menutup, ambil yang terbesar dan lanjut ke kandidat berikutnya. */
        $sisa = $needBBTU - $freedTotal;
        $ladder = $maximal ? [] : [$c['max_dn'] * 0.25, $c['max_dn'] * 0.5, $c['max_dn'] * 0.75, $c['max_dn']];
        $pilih = null;
        foreach ($ladder as $cand2) {
            $gain2 = (calc_fuel($d3, $u, $hi) - calc_fuel($d3, $u, max($lo, $hi - $cand2))) / 2.0;
            if ($gain2 >= $sisa - 1e-12) { $pilih = $cand2; break; }
        }
        /* Mode MAKSIMAL hanya butuh penurunan sebesar mungkin: dua langkah sudah cukup dan
         * memangkas biaya pass ini setengahnya (pass ini berjalan di seluruh 48 baris). */
        /* Mode MAKSIMAL: langkah dihitung EKSAK sebesar sisa kebutuhan yang tersisa, bukan tangga
         * tetap. Tangga tetap (9 / 4,5 / 2,25 MW) membuat sisa ruang kuota MM2100 yang kecil
         * (mis. 0,0071 BBTUD) tidak pernah terpakai, sehingga slot yang sebenarnya sudah mentok
         * tetap dilaporkan "masih dapat diturunkan". Batas dicari dengan bisection pada kurva
         * calc_fuel yang sesungguhnya lalu dibulatkan KE BAWAH ke grid 0,01 MW. */
        if ($maximal) {
            $dnCap = $c['max_dn'];
            $gFull = (calc_fuel($d3, $u, $hi) - calc_fuel($d3, $u, max($lo, $hi - $dnCap))) / 2.0;
            if ($gFull > $sisa + 1e-12) {
                $loD = 0.0; $hiD = $dnCap;
                for ($it = 0; $it < 32; $it++) {
                    $mid = 0.5 * ($loD + $hiD);
                    $gm = (calc_fuel($d3, $u, $hi) - calc_fuel($d3, $u, max($lo, $hi - $mid))) / 2.0;
                    if ($gm > $sisa) $hiD = $mid; else $loD = $mid;
                }
                $dnCap = floor($loD * 100.0) / 100.0;          // grid 0,01 MW, selalu ke bawah
            }
            $order = [$dnCap, $dnCap * 0.5, $dnCap * 0.25];
        } else {
            $order = ($pilih !== null) ? [$pilih, $c['max_dn']]
                   : [$c['max_dn'], $c['max_dn'] * 0.75, $c['max_dn'] * 0.5, $c['max_dn'] * 0.25];
        }
        foreach ($order as $tryDn) {
            if ($tryDn <= 0.05) continue;
            if ($maximal) {                                   // jangan melewati sisa kebutuhan
                $gainTry = (calc_fuel($d3, $u, $hi) - calc_fuel($d3, $u, max($lo, $hi - $tryDn))) / 2.0;
                if ($gainTry > $sisa + 1e-9) continue;
            }

            $save = $trial[$r];
            $trial[$r][$u] = max($lo, $hi - $tryDn);
            pp_recompute_stgs($trial[$r], $d3, $model, $r1);
            $okRow = true;
            $e = $expOf($trial[$r], (float)$ieVals[$r]);
            if ($e < $rMin - 1e-6 || $e > $rMax + 1e-6) $okRow = false;
            if ($okRow && $busMin > 0 && calc_busflow($trial[$r], $busUnit, (float)$ieVals[$r]) < $busMin - 1e-6) $okRow = false;
            if ($okRow) foreach ([$r - 1, $r + 1] as $nb) {
                if ($nb < 0 || $nb >= $n) continue;
                $eNb = $expOf($trial[$nb], (float)$ieVals[$nb]);
                if (abs($e - $eNb) > pp_export_step_limit($model) + 1e-6) { $okRow = false; break; }
            }
            if (!$okRow) { $trial[$r] = $save; pp_recompute_stgs($trial[$r], $d3, $model, $r1); continue; }
            $best = $hi - (float)$trial[$r][$u];
            break;
        }
        if ($best <= 0.0) continue;
        $gain = (calc_fuel($d3, $u, $hi) - calc_fuel($d3, $u, $hi - $best)) / 2.0;
        $freedTotal += $gain;
        $steps[] = ['row' => $r1, 'unit' => strtoupper($u), 'from_mw' => round($hi, 2),
                    'to_mw' => round($hi - $best, 2), 'freed_bbtud' => round($gain, 6),
                    '__r' => $r, '__u' => $u, '__from' => $hi, '__gain' => $gain];
    }
    /* ROLLBACK EKSAK LANTAI KUOTA MM2100 (BUG-11).
     * Akuntansi langkah memakai calc_fuel/2 tanpa pembulatan, sedangkan metrik validator
     * membulatkan per baris (round(rowGas/2, 5)). Selisih pembulatan itu dapat membuat rencana
     * menembus lantai kuota beberapa desimal. Alih-alih memakai margin tebakan, langkah TERAKHIR
     * dibatalkan satu per satu sampai metrik VALIDATOR sendiri kembali di atas lantai. */
    if ($mmQuotaM > 1e-9) {
        $mmFloorM = $mmQuotaM - 0.04;
        $rolled = 0;
        while ($steps && pp_mm2100_used_bbtud($trial, $d3) < $mmFloorM - 1e-9) {
            $last = array_pop($steps);
            $rr = (int)$last['__r']; $uu = (string)$last['__u'];
            $trial[$rr][$uu] = (float)$last['__from'];
            pp_recompute_stgs($trial[$rr], $d3, $model, $rr + 1);
            $freedTotal -= (float)$last['__gain'];
            $rolled++;
        }
        if ($rolled > 0) $evidence[] = ['pass' => 'MM2100_QUOTA_FLOOR_ROLLBACK', 'steps_reverted' => $rolled,
            'mm2100_quota_bbtud' => round($mmQuotaM, 6), 'mm2100_floor_bbtud' => round($mmFloorM, 6),
            'mm2100_used_after_bbtud' => round(pp_mm2100_used_bbtud($trial, $d3), 6),
            'note' => 'langkah terakhir dibatalkan agar metrik validator MM2100 tetap di atas batas bawah jendela kuota'];
        $freedTotal = max(0.0, $freedTotal);
    }
    foreach ($steps as $k => $st) { unset($steps[$k]['__r'], $steps[$k]['__u'], $steps[$k]['__from'], $steps[$k]['__gain']); }
    $steps = array_values($steps);
    if ($freedTotal < $needBBTU - 1e-9 && !$maximal) {
        $evidence[] = ['pass' => 'MM2100_GAS_TRIM', 'applied' => false,
            'needed_bbtud' => round($needBBTU, 6), 'available_bbtud' => round($freedTotal, 6),
            'note' => 'all-or-nothing: kebutuhan tidak tertutup, rencana dikembalikan utuh'];
        return 0.0;                                  // tidak ada perubahan sama sekali
    }
    if ($maximal && $freedTotal <= 1e-9) {
        $evidence[] = ['pass' => 'MM2100_GAS_TRIM_MAKSIMAL', 'applied' => false,
            'freed_bbtud' => 0.0, 'note' => 'tidak ada penurunan legal yang tersisa'];
        return 0.0;
    }
    $genRows = $trial;
    $evidence[] = ['pass' => $maximal ? 'MM2100_GAS_TRIM_MAKSIMAL' : 'MM2100_GAS_TRIM', 'applied' => true,
        'needed_bbtud' => round($needBBTU, 6), 'freed_bbtud' => round($freedTotal, 6),
        'partial' => ($maximal && $freedTotal < $needBBTU - 1e-9), 'steps' => $steps];
    return $freedTotal;
}


function pp_gas_quota_jababeka_bbtud(array $model): float {
    $q    = (array)($model['gas_quota'] ?? []);
    $ghvJ = (float)($model['ghv_jababeka'] ?? 1000);
    $ffVol = (float)($q['pep'] ?? 0) + (float)($q['akasia'] ?? 0)
           + (float)($q['baskara'] ?? 0) + (float)($q['bbg'] ?? 0);      // MMSCFD
    return (float)($q['pgn_pipe'] ?? 0) + (float)($q['lng'] ?? 0) + ($ffVol * $ghvJ / 1000.0);
}

function pp_gas_window(float $quota): array { return [max(0.0, $quota - 0.04), max(0.0, $quota)]; }
function pp_gas_in_window(float $used, float $quota): bool {
    [$lo, $hi] = pp_gas_window($quota);
    return ($used >= $lo - 1e-9) && ($used <= $hi + 1e-9);
}

/* ============================================================================================
 * AUDIT FEASIBILITY GAS — sumber tunggal untuk menjawab "kenapa koreksi otomatis berhenti".
 *
 * ROOT CAUSE yang diperbaiki: sebelumnya pesan kegagalan gas_quota memakai TEMPLATE STATIS
 * ("reduce legal gas dispatch and Export, then redispatch non-gas") yang dipilih hanya dari
 * JENIS pelanggaran — tanpa satu pun pemeriksaan apakah tindakan itu mungkin dilakukan. Pada
 * input produksi 08-Jul-26 tindakan itu terbukti MUSTAHIL: headroom naik non-gas = 0,0 MW di
 * seluruh 48 slot (Babelan terkunci di max_load), dan dua lever (turunkan gas / turunkan
 * Export) tidak pernah tersedia pada slot yang sama.
 *
 * Fungsi ini MURNI ANALISIS atas hasil akhir — tidak menjalankan core run, tidak mengubah
 * dispatch, dan tidak memanggil solver. Biayanya O(baris x unit).
 *
 * Satuan: MW untuk beban/headroom, MW-slot untuk akumulasi 48 slot 30 menit, BBTUD untuk gas.
 * ============================================================================================ */
function pp_gas_audit_row_key(string $unit): string {
    $u = strtolower($unit);
    if ($u === 'b1') return 'BB1';
    if ($u === 'b2') return 'BB2';
    return strtoupper($u);
}

/* Unit yang MEMBAKAR GAS (lever penurunan gas) dan unit NON-GAS (lever pengganti). Daftar ini
 * diturunkan dari akuntansi bahan bakar engine sendiri: calc_fuel() dipanggil untuk g1..g9 pada
 * agregasi gas Jababeka, g10/ge1-4 masuk gas MM2100, sedangkan b1/b2 (Babelan) adalah batubara
 * dan tidak pernah masuk perhitungan gas. STG (s1/s2/s3) bukan lever: keluarannya adalah fungsi
 * dari GTG pengumpan, bukan variabel keputusan sendiri. */
function pp_gas_audit_unit_sets(): array {
    return [
        'gas'     => ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'],
        'non_gas' => ['b1','b2'],
    ];
}

/* ============================================================================================
 * AUDIT MINIMISASI EXPORT PLN — bukti bahwa Export benar-benar sudah diturunkan lebih dulu.
 *
 * DEFINISI BISNIS (ditetapkan operator): Gas Shortage adalah kondisi ketika kebutuhan gas hasil
 * simulasi MASIH lebih besar daripada kuota gas SETELAH engine menurunkan Export PLN sampai
 * Range Min pada seluruh slot yang relevan. Karena itu status GAS_SHORTAGE tidak boleh
 * dinyatakan tanpa bukti per slot bahwa penurunan Export sudah dicoba dan mentok.
 *
 * TOLERANSI RESMI: sebuah slot dinyatakan "sudah di Range Min" hanya bila
 *     abs(export - applicable_range_min) <= PP_EXPORT_MIN_TOLERANCE_MW
 * Di luar itu, slot WAJIB punya blocker legal yang spesifik; bila tidak ada, slot itu dicatat
 * sebagai masih dapat diturunkan dan status "Export sudah minimum semua" TIDAK sah.
 *
 * Blocker ditentukan dengan menguji penurunan yang sesungguhnya: MW hanya dapat dikurangi bila
 * ada unit gas di slot itu yang masih di atas applicable minimum-nya. Bila seluruh unit gas
 * sudah di minimum, penurunan Export lebih lanjut berarti melanggar minimum unit — itulah
 * blocker legalnya, dan dicatat per unit.
 * ============================================================================================ */
if (!defined('PP_EXPORT_MIN_TOLERANCE_MW')) define('PP_EXPORT_MIN_TOLERANCE_MW', 0.05);

function pp_export_minimization_audit(array $input, array $output): array {
    $d3    = (array)($input['data3'] ?? []);
    $model = (array)($d3['modeling'] ?? []);
    $rows  = array_values((array)($output['data'] ?? []));
    $info  = (array)($output['info'] ?? []);
    $n     = count($rows);
    $sets  = pp_gas_audit_unit_sets();
    $meas  = (array)($info['Export Minimization Measurement'] ?? []);
    $before = (array)($meas['export_before'] ?? []);

    /* BLOCKER LEGAL KUOTA MM2100 (BUG-11): unit MM2100 (G10, GE1..GE4) hanya boleh diturunkan
     * selama metrik MM2100 Used+Startup masih di ATAS batas bawah jendela kuotanya. Bila sudah
     * mentok, menurunkannya lagi menukar satu pelanggaran dengan pelanggaran lain — itu blocker
     * legal, bukan kelalaian engine, dan WAJIB dilaporkan sebagai blocker per slot. */
    $mmQuotaAud = pp_mm2100_quota_energy_bbtud($model);
    $mmRoomAud  = null;
    if ($mmQuotaAud > 1e-9) {
        $genLike = [];
        foreach ($rows as $rw) {
            $g = [];
            foreach (['g10','ge1','ge2','ge3','ge4'] as $u) $g[$u] = (float)($rw[strtoupper($u)] ?? 0);
            $genLike[] = $g;
        }
        $mmRoomAud = pp_mm2100_used_bbtud($genLike, $d3) - ($mmQuotaAud - 0.04);
    }
    /* Sebuah unit MM2100 baru boleh disebut "movable" bila sisa ruang kuota MM2100 masih cukup
     * untuk satu langkah dispatch terkecil (0,5 MW) pada unit itu, diukur dengan kurva bahan
     * bakar yang sesungguhnya. Aturan ini per-unit dan per-baris, tanpa ambang tebakan. */
    $mmPinned = ($mmRoomAud !== null && $mmRoomAud <= 1e-9);
    $slots = []; $atMin = 0; $reducible = 0; $remainTotal = 0.0; $reducedTotal = 0.0;
    for ($r = 0; $r < $n; $r++) {
        $row = (array)$rows[$r]; $r1 = $r + 1;
        $e   = (float)($row['Export_PLN'] ?? 0);
        $lo  = (float)($row['pln_lo'] ?? 0);
        $hi  = (float)($row['pln_hi'] ?? 0);
        $eB  = array_key_exists($r, $before) ? (float)$before[$r] : null;
        $isActual = false;                                  // baris actual tidak dapat diubah
        $remain = max(0.0, $e - $lo);
        $isAtMin = ($remain <= PP_EXPORT_MIN_TOLERANCE_MW + 1e-9);

        /* Blocker: apakah masih ada unit gas PEMASOK EXPORT di atas applicable minimum pada slot ini?
         *
         * KOREKSI PENTING (BUG-12). Versi sebelumnya memperlakukan SELURUH unit gas — termasuk
         * unit MM2100 (G10, GE1..GE4) — sebagai lever penurunan Export, sehingga slot yang hanya
         * punya GE1 di atas minimum dilaporkan "masih dapat diturunkan". Itu SALAH: unit MM2100
         * memasok beban MM2100, bukan Export PLN. Bukti terukur dari run ini sendiri — Fase A
         * menurunkan GE1 pada row 1 (12,00 -> 3,00 MW) dan row 2 (12,00 -> 9,99 MW):
         *
         *     row 1: Jababeka 274,95 -> 265,95 (-9,00) ; BusFlow 199,76 -> 208,76 (+9,00)
         *            Export_PLN 	 TIDAK BERUBAH
         *     row 2: Jababeka 277,69 -> 275,68 (-2,01) ; BusFlow 202,54 -> 204,55 (+2,01)
         *            Export_PLN 	 TIDAK BERUBAH
         *
         * Kekurangan produksi MM2100 diserap oleh BusFlow (impor dari bus Jababeka), sehingga
         * Export PLN tidak bergerak sama sekali. Karena itu unit MM2100 dicatat sebagai lever GAS
         * (menurunkan kebutuhan gas) tetapi BUKAN lever EXPORT.
         *
         * Unit non-gas (Babelan B1/B2) sengaja TIDAK dihitung sebagai lever Export di sini:
         * menurunkannya memang menurunkan Export, tetapi membebaskan NOL gas, sehingga tidak
         * dapat mengurangi shortage — dan comparator Cost Production tidak akan pernah memilih
         * memadamkan batubara murah hanya untuk menurunkan Export. */
        $mmUnits = ['g10','ge1','ge2','ge3','ge4'];
        $exportGas = array_values(array_diff($sets['gas'], $mmUnits));
        $movable = []; $pinned = []; $gasOnly = [];
        foreach ($mmUnits as $u) {
            if (!pp_unit_present($d3, $u)) continue;
            $mw = (float)($row[pp_gas_audit_row_key($u)] ?? 0);
            if ($mw <= 0.001) continue;
            $gasOnly[] = sprintf('%s %.2f (lever GAS, BUKAN lever Export — pasokan MM2100 diserap BusFlow)',
                                 strtoupper($u), $mw);
        }
        foreach ($exportGas as $u) {
            if (!pp_unit_present($d3, $u)) continue;
            $mw = (float)($row[pp_gas_audit_row_key($u)] ?? 0);
            if ($mw <= 0.001) continue;
            $U = strtoupper($u);
            if (pp_is_unit_stopped($d3, $model, $u, $r1)) { $pinned[] = "$U=STOP_TERJADWAL"; continue; }
            if (pp_get_fixed_load($model, $u, $r1) >= 0)  { $pinned[] = "$U=FIXED_LOAD"; continue; }
            $mn = pp_effective_min_load($d3, $model, $u, $r1);
            if ($mw - $mn > 0.05) $movable[] = sprintf('%s %.2f>min %.2f', $U, $mw, $mn);
            else                  $pinned[]  = sprintf('%s=APPLICABLE_MINIMUM(%.2f)', $U, $mn);
        }
        $blockers = [];
        if (!$isAtMin) {
            if (!$movable) $blockers[] = 'SELURUH_UNIT_GAS_DI_APPLICABLE_MINIMUM';
            if ($gasOnly) $blockers[] = 'UNIT_YANG_MASIH_BERGERAK_HANYA_MM2100_TIDAK_MEMPENGARUHI_EXPORT_PLN';
            if ($mmPinned) $blockers[] = 'KUOTA_MM2100_SUDAH_DI_BATAS_BAWAH_JENDELA';
            if ($isActual) $blockers[] = 'BARIS_ACTUAL_TERKUNCI';
        }
        /* slot yang masih dapat diturunkan = di atas toleransi DAN masih ada unit yang bisa turun */
        /* LANGKAH MINIMUM NYATA: seluruh pass dispatch bergerak dalam langkah >= 0,5 MW. Bila sisa
 * kelonggaran Export lebih kecil daripada itu, penurunan berikutnya PASTI menembus Range Min —
 * itu blocker legal, bukan kelalaian engine. */
        $stepMin = 0.5;
        if (!$isAtMin && $remain < $stepMin) $blockers[] = 'SISA_KELONGGARAN_DI_BAWAH_LANGKAH_MINIMUM_UNIT_0_5_MW';
        $canReduce = (!$isAtMin && $movable && !$isActual && $remain >= $stepMin);
        if ($isAtMin) $atMin++;
        if ($canReduce) { $reducible++; $remainTotal += $remain; }
        if ($eB !== null) $reducedTotal += max(0.0, $eB - $e);

        $slots[] = [
            'row' => $r1, 'time' => (string)($row['Time'] ?? ''),
            'export_before' => $eB, 'export_after' => round($e, 2),
            'reduction_mw' => ($eB !== null) ? round(max(0.0, $eB - $e), 2) : null,
            'range_min' => round($lo, 2), 'range_max' => round($hi, 2),
            'remaining_legal_reduction_mw' => round($remain, 2),
            'at_range_min' => $isAtMin,
            'can_reduce_further' => $canReduce,
            'blockers' => $blockers,
            'units_movable' => $movable,
            'units_pinned' => $pinned,
            'units_gas_lever_only_not_export' => $gasOnly,
        ];
    }

    $allMinimized = ($reducible === 0);
    $claim = $allMinimized
        ? (($atMin >= $n)
            ? 'SELURUH_SLOT_DI_RANGE_MIN'
            : 'TIDAK_DAPAT_DITURUNKAN_LAGI_KARENA_BLOCKER_LEGAL')
        : 'MASIH_ADA_SLOT_YANG_DAPAT_DITURUNKAN';

    return [
        'schema' => 'co12-export-minimization-audit-v1',
        'tolerance_mw' => PP_EXPORT_MIN_TOLERANCE_MW,
        'rows_total' => $n,
        'rows_at_range_min' => $atMin,
        'rows_still_reducible' => $reducible,
        'remaining_legal_reduction_mw_slot' => round($remainTotal, 2),
        'export_reduction_applied_mw_slot' => round($reducedTotal, 2),
        'export_reduction_attempted' => isset($meas['gas_before_export_reduction_bbtud']),
        'gas_before_export_reduction_bbtud' => $meas['gas_before_export_reduction_bbtud'] ?? null,
        'gas_after_export_reduction_bbtud'  => $meas['gas_after_export_reduction_bbtud'] ?? null,
        'gas_freed_by_export_reduction_bbtud' => $meas['gas_freed_by_export_reduction_bbtud'] ?? null,
        'mm2100_quota_bbtud' => ($mmQuotaAud > 1e-9) ? round($mmQuotaAud, 4) : null,
        'mm2100_room_to_floor_bbtud' => ($mmRoomAud === null) ? null : round($mmRoomAud, 4),
        'mm2100_units_pinned_by_quota_floor' => $mmPinned,
        'minimization_complete' => $allMinimized,
        'claim' => $claim,
        'slots' => $slots,
        'note' => $allMinimized
            ? 'Setiap slot yang belum tepat di Range Min memiliki blocker legal spesifik (unit gas sudah di applicable minimum), sehingga penurunan Export lebih lanjut akan melanggar minimum unit.'
            : 'Masih terdapat slot yang secara legal dapat diturunkan — status GAS_SHORTAGE BELUM boleh dinyatakan.',
    ];
}

function pp_gas_feasibility_audit(array $input, array $output): array {
    $d3    = (array)($input['data3'] ?? []);
    $model = (array)($d3['modeling'] ?? []);
    /* KOTAK LEGAL UNIT SKIP LOAD WAJIB TERLIHAT DI SINI (D-1).
     * Audit ini menghitung "lever" yang masih tersedia untuk menurunkan gas. Bila kotak legal hasil
     * pemilihan cabang tidak terlihat, audit mengira G8/G9 masih dapat diturunkan sampai min_ccload
     * aslinya, lalu menyimpulkan feasible = true — padahal engine sudah kehabisan lever dan
     * validator melaporkan gas_quota. Terukur: band G9 60-80 menghasilkan `feasible = true`
     * bersamaan dengan "ADDITIONAL LNG REQUIRED 2,396 BBTUD" pada output yang sama. Kontradiksi itu
     * membuat jalur keputusan bahan bakar tidak pernah dijalankan, sehingga operator hanya menerima
     * HTTP 422 tanpa opsi. Aturan kotak diambil dari evidence cabang yang menempel pada output,
     * sehingga ikut benar pada jalur worker asinkron (tidak bergantung pada state proses). */
    $__br = (array)($output['info']['Unit Skip Load Branch'] ?? []);
    foreach (['min_load_rules', 'max_load_rules'] as $__rk) {
        foreach ((array)($__br['applied_rules'][$__rk] ?? []) as $__u => $__rules) {
            foreach ((array)$__rules as $__rule) $model[$__rk][strtolower((string)$__u)][] = $__rule;
        }
    }
    $rows  = array_values((array)($output['data'] ?? []));
    $info  = (array)($output['info'] ?? []);
    $n     = count($rows);
    $sets  = pp_gas_audit_unit_sets();

    $quota = (float)($info['Total Gas Quota (BBTUD)'] ?? 0);
    $used  = (float)($info['Total Gas Used (BBTUD)'] ?? 0);
    $hasAnyActual = ((int)($info['Actual Hours Provided'] ?? 0)) > 0;
    if ($hasAnyActual && isset($info['Effective Total Gas (BBTUD)']))
        $used = (float)$info['Effective Total Gas (BBTUD)'];   // basis yang sama dengan validator
    [$lo, $hi] = pp_gas_window($quota);
    $dir = 'IN_WINDOW'; $dev = 0.0;
    if ($used > $hi + 1e-9)      { $dir = 'OVER_QUOTA';   $dev = $used - $hi; }
    elseif ($used < $lo - 1e-9)  { $dir = 'UNDER_TARGET'; $dev = $lo - $used; }

    /* ---- audit per unit per slot ------------------------------------------------------- */
    $unitStat = []; $slotStat = [];
    $gasDown = 0.0; $gasUp = 0.0; $nonGasUp = 0.0; $nonGasDown = 0.0; $ubTotal = 0.0; $ubUpTotal = 0.0;
    $bbRamp = pp_babelan_ramp_limit($model);
    $cannotStop = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));

    foreach (array_merge($sets['gas'], $sets['non_gas']) as $u) {
        $unitStat[$u] = ['unit' => strtoupper($u), 'is_gas' => in_array($u, $sets['gas'], true),
            'present' => pp_unit_present($d3, $u), 'rows_running' => 0, 'rows_off' => 0,
            'rows_at_min' => 0, 'rows_at_max' => 0, 'rows_fixed' => 0, 'rows_stopped' => 0,
            'down_mw_slot' => 0.0, 'up_mw_slot' => 0.0,
            'cannot_stop' => in_array($u, $cannotStop, true), 'reasons' => []];
    }

    for ($r = 0; $r < $n; $r++) {
        $row = (array)$rows[$r]; $r1 = $r + 1;
        $slot = ['row' => $r1, 'time' => (string)($row['Time'] ?? ''),
                 'export' => round((float)($row['Export_PLN'] ?? 0), 2),
                 'export_min' => round((float)($row['pln_lo'] ?? 0), 2),
                 'export_max' => round((float)($row['pln_hi'] ?? 0), 2),
                 'gas_down_mw' => 0.0, 'non_gas_up_mw' => 0.0, 'units_at_min' => [], 'units_at_max' => []];
        $slot['export_reduction_remaining'] = round(max(0.0, $slot['export'] - $slot['export_min']), 2);
        $slot['export_at_minimum'] = $slot['export_reduction_remaining'] <= 0.05;

        foreach (array_merge($sets['gas'], $sets['non_gas']) as $u) {
            if (!$unitStat[$u]['present']) continue;
            $mw  = (float)($row[pp_gas_audit_row_key($u)] ?? 0);
            $fx  = pp_get_fixed_load($model, $u, $r1);
            $stp = pp_is_unit_stopped($d3, $model, $u, $r1);
            $mn  = pp_effective_min_load($d3, $model, $u, $r1);
            $mx  = pp_effective_max_load($d3, $model, $u, $r1);
            if ($mx <= 0) $mx = (float)($d3[$u]['max_load'] ?? 0);
            /* batas ramp Babelan terhadap kedua tetangga: cap naik yang benar-benar legal */
            if (in_array($u, $sets['non_gas'], true)) {
                foreach ([$r - 1, $r + 1] as $nb) {
                    if ($nb < 0 || $nb >= $n) continue;
                    $mx = min($mx, (float)($rows[$nb][pp_gas_audit_row_key($u)] ?? 0) + $bbRamp);
                }
            }
            if ($stp)      { $unitStat[$u]['rows_stopped']++; continue; }
            if ($fx >= 0)  { $unitStat[$u]['rows_fixed']++;   continue; }   // fixed load: bukan lever
            if ($mw <= 0.001) { $unitStat[$u]['rows_off']++;  continue; }
            $unitStat[$u]['rows_running']++;
            $down = max(0.0, $mw - $mn);
            $up   = max(0.0, $mx - $mw);
            if ($down <= 0.001) { $unitStat[$u]['rows_at_min']++; $slot['units_at_min'][] = strtoupper($u); }
            if ($up   <= 0.001) { $unitStat[$u]['rows_at_max']++; $slot['units_at_max'][] = strtoupper($u); }
            $unitStat[$u]['down_mw_slot'] += $down;
            $unitStat[$u]['up_mw_slot']   += $up;
            if ($unitStat[$u]['is_gas']) { $gasDown += $down; $gasUp += $up; $slot['gas_down_mw'] += $down; }
            else                         { $nonGasUp += $up; $nonGasDown += $down; $slot['non_gas_up_mw'] += $up; }
        }
        $slot['gas_down_mw']   = round($slot['gas_down_mw'], 2);
        $slot['non_gas_up_mw'] = round($slot['non_gas_up_mw'], 2);
        /* Lever penurunan gas hanya BENAR-BENAR tersedia bila penurunan itu tidak menembus
         * Range Min Export, atau bila ada non-gas yang dapat menggantikan MW-nya. */
        $slot['gas_reduction_usable_mw'] = round(
            min($slot['gas_down_mw'], $slot['export_reduction_remaining'] + $slot['non_gas_up_mw']), 2);
        /* KONVERSI KE BBTUD — feasibility WAJIB dinilai dalam satuan gas, bukan MW. MW yang boleh
         * diturunkan dialokasikan ke unit gas dengan laju gas marginal TERTINGGI lebih dulu
         * (memaksimalkan gas yang dibebaskan), memakai calc_fuel() yang sama dengan akuntansi
         * engine: fuel per slot 30 menit = calc_fuel(load)/2.
         * Ini BATAS ATAS: kopling GTG->STG membuat penurunan 1 MW GTG menurunkan Export lebih dari
         * 1 MW, sehingga kemampuan nyata lebih kecil daripada angka ini. Bila batas atas saja sudah
         * di bawah deviasi, infeasibility terbukti. */
        $budgetMW = (float)$slot['gas_reduction_usable_mw']; $freed = 0.0;
        if ($budgetMW > 1e-6) {
            $cand = [];
            foreach ($sets['gas'] as $u) {
                if (!($unitStat[$u]['present'] ?? false)) continue;
                $mw = (float)($row[pp_gas_audit_row_key($u)] ?? 0);
                if ($mw <= 0.001) continue;
                if (pp_is_unit_stopped($d3, $model, $u, $r1)) continue;
                if (pp_get_fixed_load($model, $u, $r1) >= 0) continue;
                $mn = pp_effective_min_load($d3, $model, $u, $r1);
                $dn = max(0.0, $mw - $mn);
                if ($dn <= 1e-6) continue;
                $rate = (calc_fuel($d3, $u, $mw) - calc_fuel($d3, $u, max($mn, $mw - 1.0))) / 2.0;  // BBTU per MW-slot
                $cand[] = ['u' => $u, 'dn' => $dn, 'rate' => max(0.0, $rate), 'mw' => $mw, 'mn' => $mn];
            }
            usort($cand, fn($a, $b) => $b['rate'] <=> $a['rate']);
            foreach ($cand as $c) {
                if ($budgetMW <= 1e-6) break;
                $take = min($c['dn'], $budgetMW);
                $freed += (calc_fuel($d3, $c['u'], $c['mw']) - calc_fuel($d3, $c['u'], $c['mw'] - $take)) / 2.0;
                $budgetMW -= $take;
            }
        }
        $slot['gas_reduction_upper_bound_bbtud'] = round($freed, 5);
        $ubTotal += $freed;
        /* Arah UNDER_TARGET: batas atas gas yang dapat DITAMBAH. MW kenaikan dibatasi kelonggaran
         * Export terhadap Range Max (menaikkan gas menaikkan Export). Dinilai dalam BBTUD, sama
         * seperti arah over-quota — feasibility tidak boleh dinilai dalam satuan MW. */
        $budgetUp = max(0.0, (float)$slot['export_max'] - (float)$slot['export']);
        $added = 0.0;
        if ($budgetUp > 1e-6) {
            $candU = [];
            foreach ($sets['gas'] as $u) {
                if (!($unitStat[$u]['present'] ?? false)) continue;
                $mw = (float)($row[pp_gas_audit_row_key($u)] ?? 0);
                if ($mw <= 0.001) continue;
                if (pp_is_unit_stopped($d3, $model, $u, $r1)) continue;
                if (pp_get_fixed_load($model, $u, $r1) >= 0) continue;
                $mxU = pp_effective_max_load($d3, $model, $u, $r1);
                if ($mxU <= 0) $mxU = (float)($d3[$u]['max_load'] ?? 0);
                $upU = max(0.0, $mxU - $mw);
                if ($upU <= 1e-6) continue;
                $rateU = (calc_fuel($d3, $u, min($mxU, $mw + 1.0)) - calc_fuel($d3, $u, $mw)) / 2.0;
                $candU[] = ['u' => $u, 'up' => $upU, 'rate' => max(0.0, $rateU), 'mw' => $mw];
            }
            usort($candU, fn($a, $b) => $b['rate'] <=> $a['rate']);
            foreach ($candU as $cu) {
                if ($budgetUp <= 1e-6) break;
                $take = min($cu['up'], $budgetUp);
                $added += (calc_fuel($d3, $cu['u'], $cu['mw'] + $take) - calc_fuel($d3, $cu['u'], $cu['mw'])) / 2.0;
                $budgetUp -= $take;
            }
        }
        $slot['gas_increase_upper_bound_bbtud'] = round($added, 5);
        $ubUpTotal += $added;
        $slotStat[] = $slot;
    }

    $rowsExpAtMin = 0; $expRedTotal = 0.0; $usableTotal = 0.0;
    foreach ($slotStat as $s) {
        if ($s['export_at_minimum']) $rowsExpAtMin++;
        $expRedTotal += $s['export_reduction_remaining'];
        $usableTotal += $s['gas_reduction_usable_mw'];
    }
    foreach ($unitStat as $u => &$st) {
        $st['down_mw_slot'] = round($st['down_mw_slot'], 1);
        $st['up_mw_slot']   = round($st['up_mw_slot'], 1);
        if (!$st['present'])                 $st['reasons'][] = 'UNIT_TIDAK_TERSEDIA';
        if ($st['rows_stopped'] > 0)         $st['reasons'][] = 'STOP_TERJADWAL_' . $st['rows_stopped'] . '_BARIS';
        if ($st['rows_fixed'] > 0)           $st['reasons'][] = 'FIXED_LOAD_' . $st['rows_fixed'] . '_BARIS';
        if ($st['cannot_stop'])              $st['reasons'][] = 'UNIT_CANNOT_STOP';
        if ($st['rows_running'] > 0 && $st['rows_at_min'] === $st['rows_running'])
            $st['reasons'][] = 'DI_APPLICABLE_MINIMUM_SELURUH_BARIS_RUNNING';
        if ($st['rows_running'] > 0 && $st['rows_at_max'] === $st['rows_running'])
            $st['reasons'][] = 'DI_APPLICABLE_MAXIMUM_SELURUH_BARIS_RUNNING';
    }
    unset($st);

    /* ---- feasibility: apakah tindakan yang direkomendasikan benar-benar mungkin? --------- */
    $blockers = []; $feasible = false; $need = 0.0;
    if ($dir === 'OVER_QUOTA') {
        /* Penurunan gas hanya berguna bila MW-nya dapat digantikan (Export punya kelonggaran
         * ATAU non-gas punya headroom naik). Bila keduanya nol, tidak ada lever tersisa. */
        $feasible = $ubTotal >= $dev - 1e-9;   // batas atas gas yang dapat dibebaskan vs deviasi
        $need = $dev;
        if ($rowsExpAtMin >= $n)
            $blockers[] = sprintf('PLN Export sudah berada di Range Min pada %d/%d slot (Export terendah %.2f MW).',
                $rowsExpAtMin, $n, min(array_column($slotStat, 'export')));
        elseif ($rowsExpAtMin > 0)
            $blockers[] = sprintf('PLN Export sudah di Range Min pada %d/%d slot; sisa penurunan legal hanya %.2f MW-slot pada %d slot lainnya.',
                $rowsExpAtMin, $n, $expRedTotal, $n - $rowsExpAtMin);
        $atMinUnits = [];
        foreach ($unitStat as $u => $st)
            if ($st['is_gas'] && $st['rows_running'] > 0 && $st['rows_at_min'] > 0)
                $atMinUnits[] = sprintf('%s di applicable minimum %d/%d baris running',
                    $st['unit'], $st['rows_at_min'], $st['rows_running']);
        if ($atMinUnits) $blockers[] = 'Unit gas yang tidak dapat diturunkan lagi: ' . implode('; ', $atMinUnits) . '.';
        $blockers[] = sprintf('Headroom NAIK unit non-gas yang tersedia = %.1f MW-slot%s.',
            $nonGasUp, $nonGasUp <= 0.05 ? ' (Babelan berada di applicable maximum pada seluruh slot, sehingga redispatch ke non-gas tidak mungkin)' : '');
        $blockers[] = sprintf('Penurunan gas yang benar-benar dapat dipakai (dibatasi Export Range Min dan headroom non-gas) = %.1f MW-slot dari %.1f MW-slot headroom turun mentah.',
            $usableTotal, $gasDown);
        $blockers[] = sprintf('BATAS ATAS gas yang dapat dibebaskan seluruh lever dispatch = %.4f BBTUD, sedangkan yang dibutuhkan %.4f BBTUD (kurang %.4f BBTUD). Batas atas ini belum memperhitungkan kopling GTG->STG yang membuat kemampuan nyata lebih kecil lagi.',
            $ubTotal, $dev, max(0.0, $dev - $ubTotal));
    } elseif ($dir === 'UNDER_TARGET') {
        $feasible = $ubUpTotal >= $dev - 1e-9;   // dinilai dalam BBTUD, bukan MW
        $need = $dev;
 $blockers[] = sprintf('Headroom NAIK unit gas yang tersedia = %.1f MW-slot.', $gasUp);
 $blockers[] = sprintf('BATAS ATAS gas yang dapat ditambah seluruh lever dispatch = %.4f BBTUD, sedangkan yang dibutuhkan %.4f BBTUD (kurang %.4f BBTUD); kenaikan dibatasi kelonggaran Export terhadap Range Max.', $ubUpTotal, $dev, max(0.0, $dev - $ubUpTotal));
        $atMaxUnits = [];
        foreach ($unitStat as $u => $st)
            if ($st['is_gas'] && $st['rows_running'] > 0 && $st['rows_at_max'] > 0)
                $atMaxUnits[] = sprintf('%s di applicable maximum %d/%d baris running',
                    $st['unit'], $st['rows_at_max'], $st['rows_running']);
        if ($atMaxUnits) $blockers[] = 'Unit gas yang tidak dapat dinaikkan lagi: ' . implode('; ', $atMaxUnits) . '.';
    }

    /* slot paling menentukan: yang lever-nya paling mampet tetapi kontribusi gasnya besar */
    usort($slotStat, function ($a, $b) {
        $c = $a['gas_reduction_usable_mw'] <=> $b['gas_reduction_usable_mw'];
        return $c !== 0 ? $c : ($b['export'] <=> $a['export']);
    });
    $worst = array_slice($slotStat, 0, 8);
    usort($slotStat, fn($a, $b) => $a['row'] <=> $b['row']);

    return [
        'schema' => 'co12-gas-feasibility-audit-v1',
        'direction' => $dir,
        'feasible' => $feasible,
        'gas' => ['used_bbtud' => round($used, 4), 'quota_bbtud' => round($quota, 4),
                  'window_min_bbtud' => round($lo, 4), 'window_max_bbtud' => round($hi, 4),
                  'deviation_bbtud' => round($dev, 4), 'unit' => 'BBTUD',
                  'basis' => $hasAnyActual ? 'EFFECTIVE_TOTAL_GAS_ACTUAL_PLUS_ESTIMASI' : 'ESTIMASI_SIMULASI_PENUH'],
        'export' => ['rows_total' => $n, 'rows_at_minimum' => $rowsExpAtMin,
                     'min_actual_mw' => $n ? round(min(array_column($slotStat, 'export')), 2) : null,
                     'reduction_remaining_mw_slot' => round($expRedTotal, 2), 'unit' => 'MW'],
        'headroom' => ['gas_down_mw_slot' => round($gasDown, 1), 'gas_up_mw_slot' => round($gasUp, 1),
                       'non_gas_up_mw_slot' => round($nonGasUp, 1), 'non_gas_down_mw_slot' => round($nonGasDown, 1),
                       'gas_reduction_usable_mw_slot' => round($usableTotal, 1), 'unit' => 'MW-slot',
                       'gas_reduction_upper_bound_bbtud' => round($ubTotal, 4),
                       'gas_reduction_shortfall_bbtud' => round(max(0.0, $dev - $ubTotal), 4),
                       'gas_increase_upper_bound_bbtud' => round($ubUpTotal, 4)],
        'units' => array_values($unitStat),
        'blockers' => $blockers,
        'worst_slots' => $worst,
        'required_bbtud' => round($need, 4),
        'note' => 'Analisis murni atas rencana final; tidak menjalankan core run dan tidak mengubah dispatch.',
    ];
}

/* Pesan kegagalan gas yang DIHITUNG, bukan template. Dipakai run.php menggantikan kalimat
 * rekomendasi generik. Rekomendasi tindakan hanya disertakan bila audit membuktikannya feasible. */
function pp_gas_failure_narrative(array $audit): array {
    $g = (array)($audit['gas'] ?? []);
    $head = sprintf('Total Gas Used=%.4f di luar window [%.4f,%.4f] (deviasi %.4f BBTUD).',
        (float)($g['used_bbtud'] ?? 0), (float)($g['window_min_bbtud'] ?? 0),
        (float)($g['window_max_bbtud'] ?? 0), (float)($g['deviation_bbtud'] ?? 0));
    if (!empty($audit['feasible'])) {
        $act = ($audit['direction'] === 'OVER_QUOTA')
            ? sprintf('Masih tersedia penurunan gas yang dapat dipakai sebesar %.1f MW-slot — koreksi otomatis seharusnya dapat dilanjutkan.',
                (float)($audit['headroom']['gas_reduction_usable_mw_slot'] ?? 0))
            : sprintf('Masih tersedia headroom naik unit gas sebesar %.1f MW-slot — koreksi otomatis seharusnya dapat dilanjutkan.',
                (float)($audit['headroom']['gas_up_mw_slot'] ?? 0));
        return ['message' => $head . ' ' . $act, 'action' => $act, 'feasible' => true];
    }
    $lines = (array)($audit['blockers'] ?? []);
    $act = 'Koreksi otomatis berhenti karena seluruh lever legal habis: ' . implode(' ', $lines)
         . ' Tidak ada tindakan dispatch yang tersisa; penyelesaian memerlukan keputusan bahan bakar (LNG atau distillate) atau perubahan input oleh operator.';
    return ['message' => $head . ' ' . $act, 'action' => $act, 'feasible' => false];
}

/* ============================================================================================
 * KONTRAK KEPUTUSAN SHORTAGE BAHAN BAKAR (backend -> UI).
 *
 * Dipakai ketika: gas berada di luar window, audit feasibility membuktikan tidak ada lever
 * dispatch legal yang tersisa, dan operator memilih "Flag shortage only"
 * (modeling.gas_shortage_action = none). Backend TIDAK pernah memilih bahan bakar sendiri;
 * ia mengembalikan status mesin-terbaca agar UI meminta keputusan operator.
 *
 * ADITIF: seluruh field lama (ok/result/error/http_status_recommended/release_gate) tetap ada
 * dan tidak berubah artinya. publish_allowed tetap false sampai rerun konvergen dan lulus gate.
 * ============================================================================================ */
function pp_shortage_decision_block(array $input, array $output, array $audit, ?array $expAudit = null): array {
    if ($expAudit === null) $expAudit = pp_export_minimization_audit($input, $output);
    /* Rekaman dua fase (Fase A feasibility repair + Fase B final optimisation) bila blok
     * minimisasi Export benar-benar dijalankan pada run ini. */
    $tp   = (array)($output['info']['Export Minimization Two Phase'] ?? []);
    $rsB  = (array)($output['info']['Run Status'] ?? []);
    $convRun = array_key_exists('converged', $rsB) ? (bool)$rsB['converged'] : null;
    $model = (array)($input['data3']['modeling'] ?? []);
    $info  = (array)($output['info'] ?? []);
    $act   = strtolower(trim((string)($model['gas_shortage_action'] ?? 'none')));
    if ($act === 'flag shortage only' || $act === 'flag_shortage_only') $act = 'none';

    $gap  = (float)($audit['gas']['deviation_bbtud'] ?? 0);
    $dir  = (string)($audit['direction'] ?? 'IN_WINDOW');
    $conv = pp_distillate_conversion($model);

    /* Estimasi LNG: engine sudah menghitung "Recommended LNG (BBTUD)" dari selisih gas terhadap
     * kuota. ANGKA ITU HANYA MENUTUP SELISIH GAS — ia belum diuji terhadap hard validation.
     * Pengujian nyata pada input produksi menunjukkan LNG sebesar selisih gas MASIH menyisakan
     * pelanggaran export_range, sementara LNG yang lebih besar dapat membuat gas jatuh DI BAWAH
     * window (LNG bersifat must-take sehingga kuota ikut naik). Karena itu status validasinya
     * dinyatakan apa adanya dan hanya rerun yang membuktikannya. */
    /* RESIDUAL FINAL (perbaikan): estimasi LNG/distillate WAJIB memakai residual SETELAH
     * minimisasi Export selesai, bukan residual awal. info['Recommended LNG (BBTUD)'] dihitung di
     * dalam core SEBELUM lever minimisasi bekerja, sehingga pada input produksi ia melaporkan
     * 4,7377 BBTUD — angka pra-minimisasi. Angka yang sah adalah deviasi gas rencana FINAL
     * terhadap window (audit dijalankan atas output final), yaitu 3,1789 BBTUD. Angka engine tetap
     * dilaporkan sebagai bukti pelacakan, tetapi bukan sebagai nilai yang ditawarkan ke operator. */
    $lngEngine = array_key_exists('Recommended LNG (BBTUD)', $info)
        ? (float)$info['Recommended LNG (BBTUD)'] : null;
    /* ===== ANGKA YANG DITAWARKAN HARUS MENDARAT DI DALAM WINDOW, BUKAN MENEMPEL DI PLAFON =====
     * `deviation_bbtud` diukur terhadap BATAS ATAS window (used - quota). Jumlah sebesar itu, bila
     * benar-benar dipakai, membawa rencana tepat ke plafon dengan margin nol — dan karena redispatch
     * setelah penambahan bahan bakar menggeser pemakaian beberapa perseratus BBTUD, hasilnya jatuh
     * di sisi yang salah. Terukur pada fixture ini: yang ditawarkan 4,6985 BBTUD sedangkan angka
     * yang BENAR-BENAR lolos adalah 4,7385 BBTUD (rerun penuh: gas 64,3296 pada window
     * [64,2985 ; 64,3385], residual 0,0000). Selisihnya persis selebar window, 0,04 BBTUD.
     * Karena itu angka yang ditawarkan diukur terhadap BATAS BAWAH window. Ia tidak pernah lebih
     * kecil dari residual dua-fase, sehingga tidak ada kasus yang justru ditawari lebih sedikit. */
    $gasUsedAud = (float)($audit['gas']['used_bbtud'] ?? 0);
    $winLoAud   = (float)($audit['gas']['window_min_bbtud'] ?? 0);
    $lngEstRaw  = isset($tp['final_gas_shortage']) ? (float)$tp['final_gas_shortage'] : max(0.0, $gap);
    $lngEst = ($dir === 'OVER_QUOTA' && $winLoAud > 0)
            ? max($lngEstRaw, max(0.0, $gasUsedAud - $winLoAud))
            : $lngEstRaw;
    /* Distillate dijadwalkan per slot dengan level diskrit 30/50/75/100%, sehingga volume yang
     * BENAR-BENAR terpakai tidak sama dengan hasil konversi analitik. Terukur: konversi analitik
     * 131.520 liter, sedangkan penjadwalan nyata memerlukan 132.510 liter; operator yang mengetik
     * angka analitik memperoleh rencana yang TIDAK tertutup. Yang ditawarkan karena itu adalah
     * volume terjadwal milik engine sendiri bila tersedia. */
    $distAnalytic = pp_distillate_litres_from_bbtu(max(0.0, $lngEst), $model);
    $distSched    = (float)($info['Recommended Distillate (l/day)'] ?? 0);
    $distEst = max($distAnalytic, $distSched);

    /* SYSTEMIC SHORTAGE PROOF (quota-independent): requiring rows_still_reducible=0 was wrong.
     * Some Export rows may remain numerically above Range Min while their reduction cannot be used
     * because non-gas upward headroom is zero, or the absolute gas-reduction upper bound is smaller
     * than the remaining gas gap. In that state dispatch cannot resolve the violation, regardless
     * of whether PGN is 25, 26, 27, or another value. */
    $gasReductionUpper = (float)($audit['headroom']['gas_reduction_upper_bound_bbtud'] ?? 0.0);
    $gasReductionShortfall = (float)($audit['headroom']['gas_reduction_shortfall_bbtud']
        ?? max(0.0, max(0.0, $gap) - $gasReductionUpper));
    $nonGasUp = (float)($audit['headroom']['non_gas_up_mw_slot'] ?? 0.0);
    $dispatchCannotCloseGap = ($dir === 'OVER_QUOTA')
        && empty($audit['feasible'])
        && ($gap > 1e-6)
        && (($gasReductionShortfall > 1e-6) || ($nonGasUp <= 0.05 && $gasReductionUpper + 1e-6 < $gap));
    $exportWorkAttempted = !empty($expAudit['export_reduction_attempted'])
        || ((float)($expAudit['gas_before_export_reduction_bbtud'] ?? 0) > 0)
        || ((int)($audit['export']['rows_at_minimum'] ?? 0) > 0);

    $cands = [];
    foreach ((array)($info['Gas Shortage Candidates'] ?? []) as $c)
        if (is_array($c)) $cands[(string)($c['action'] ?? '')] = $c;

    /* Slot yang membutuhkan bantuan: slot dengan lever paling mampet. */
    $slots = [];
    foreach ((array)($audit['worst_slots'] ?? []) as $s)
        $slots[] = ['row' => $s['row'], 'time' => $s['time'], 'export' => $s['export'],
                    'export_min' => $s['export_min'], 'gas_reduction_usable_mw' => $s['gas_reduction_usable_mw']];

    $reasons = [];
    if ($dir === 'OVER_QUOTA')   $reasons[] = 'GAS_DEMAND_EXCEEDS_QUOTA_AFTER_EXPORT_MINIMIZATION';
    if ($dir === 'OVER_QUOTA')   $reasons[] = 'GAS_OVER_QUOTA';            // kompatibilitas nama lama
    if ($dir === 'UNDER_TARGET') $reasons[] = 'GAS_CONSUMPTION_UNDER_TARGET';
    if ($dir === 'UNDER_TARGET') $reasons[] = 'GAS_UNDER_TARGET';          // kompatibilitas nama lama
    if (!empty($expAudit['minimization_complete'])) $reasons[] = 'EXPORT_FLOOR_BINDING';
    if ((int)($audit['export']['rows_at_minimum'] ?? 0) > 0) $reasons[] = 'EXPORT_AT_RANGE_MIN';
    if ((float)($audit['headroom']['non_gas_up_mw_slot'] ?? 0) <= 0.05) $reasons[] = 'NO_NON_GAS_UPWARD_HEADROOM';
    if (empty($audit['feasible'])) $reasons[] = 'DISPATCH_LEVERS_EXHAUSTED';
    if ($act === 'none') $reasons[] = 'FLAG_SHORTAGE_ONLY_SELECTED';

    return [
        'schema' => 'co12-shortage-fuel-selection-v1',
        'action_required' => 'USER_FUEL_DECISION',
        'action_required_legacy' => 'SHORTAGE_FUEL_SELECTION',   // kompatibilitas klien lama
        'reason_codes' => $reasons,
        'gas_target' => [
            'min' => (float)($audit['gas']['window_min_bbtud'] ?? 0),
            'max' => (float)($audit['gas']['window_max_bbtud'] ?? 0),
            'quota' => (float)($audit['gas']['quota_bbtud'] ?? 0),
            'simulated' => (float)($audit['gas']['used_bbtud'] ?? 0),
            'actual' => isset($info['Actual PGN Total (BBTUD)']) ? $info['Actual PGN Total (BBTUD)'] : null,
            'residual' => round($gap, 4),
            'direction' => $dir,
            'unit' => 'BBTUD',
            'basis' => (string)($audit['gas']['basis'] ?? ''),
        ],
        'export' => [
            'current_min_mw' => $audit['export']['min_actual_mw'] ?? null,
            'minimum_mw' => $audit['export']['min_actual_mw'] ?? null,
            'rows_at_minimum' => (int)($audit['export']['rows_at_minimum'] ?? 0),
            'rows_total' => (int)($audit['export']['rows_total'] ?? 0),
            'reduction_remaining_mw_slot' => (float)($audit['export']['reduction_remaining_mw_slot'] ?? 0),
            'unit' => 'MW',
        ],
        'headroom' => $audit['headroom'] ?? [],
        'stop_reason' => (array)($audit['blockers'] ?? []),
        'correction_stopped_because' => $info['Gas Window Correction'] ?? null,
        /* SEMANTIK RESMI (ditetapkan operator):
         *   GAS_SHORTAGE = kebutuhan gas hasil simulasi MASIH melebihi kuota SETELAH Export PLN
         *                  diturunkan sampai Range Min pada seluruh slot yang relevan.
         *   GAS_CONSUMPTION_UNDER_TARGET = konsumsi gas di bawah batas bawah window kontrak.
         * Keduanya TIDAK boleh memakai kata "shortage" yang sama. */
        /* Definisi bisnis: GAS_SHORTAGE hanya sah bila minimisasi Export sudah TUNTAS. Bila masih
         * ada slot yang secara legal dapat diturunkan, status yang jujur adalah PENDING — bukan
         * GAS_SHORTAGE — karena prasyaratnya belum terpenuhi. */
        'problem_kind' => ($dir === 'UNDER_TARGET') ? 'GAS_CONSUMPTION_UNDER_TARGET'
            : (!empty($expAudit['minimization_complete']) ? 'GAS_SHORTAGE' : 'GAS_SHORTAGE_PENDING_EXPORT_MINIMIZATION'),
        'reason_code' => ($dir === 'UNDER_TARGET')
            ? 'GAS_CONSUMPTION_BELOW_CONTRACT_MINIMUM'
            : (!empty($expAudit['minimization_complete'])
                ? 'GAS_DEMAND_EXCEEDS_QUOTA_AFTER_EXPORT_MINIMIZATION'
                : 'EXPORT_MINIMIZATION_INCOMPLETE_SHORTAGE_NOT_YET_DECLARABLE'),
        'problem_label' => ($dir === 'UNDER_TARGET')
            ? 'Konsumsi gas DI BAWAH batas bawah window kontrak (bukan gas shortage)'
            : 'GAS SHORTAGE — kebutuhan gas masih melebihi kuota setelah Export PLN diminimalkan',
        /* Bukti wajib definisi bisnis: gas sebelum/sesudah minimisasi Export, kuota efektif,
         * residual, dan status minimisasi Export per slot. */
        'gas_required_before_export_reduction' => $expAudit['gas_before_export_reduction_bbtud'] ?? null,
        'gas_required_after_export_reduction'  => $expAudit['gas_after_export_reduction_bbtud'] ?? round((float)($audit['gas']['used_bbtud'] ?? 0), 4),
        'effective_gas_quota' => round((float)($audit['gas']['quota_bbtud'] ?? 0), 4),
        'gas_shortage' => ($dir === 'OVER_QUOTA') ? round($gap, 4) : null,
        'gas_shortage_basis' => ($dir !== 'OVER_QUOTA') ? null
            : (!empty($expAudit['minimization_complete']) ? 'SETELAH_MINIMISASI_EXPORT_TUNTAS'
               : 'SEBELUM_MINIMISASI_EXPORT_TUNTAS_ANGKA_BELUM_FINAL'),
        'gas_unit' => 'BBTUD',
        'export_reduction_attempted' => (bool)($expAudit['export_reduction_attempted'] ?? false),
        'export_reduction_applied_mw_slot' => $expAudit['export_reduction_applied_mw_slot'] ?? null,
        'export_slots_at_range_min' => $expAudit['rows_at_range_min'] ?? null,
        'export_slots_total_relevant' => $expAudit['rows_total'] ?? null,
        'export_slots_still_reducible' => $expAudit['rows_still_reducible'] ?? null,
        'export_reduction_remaining_mw_slot' => $expAudit['remaining_legal_reduction_mw_slot'] ?? null,
        'export_minimization_complete' => $expAudit['minimization_complete'] ?? null,
        'export_minimization_claim' => $expAudit['claim'] ?? null,
        'export_minimization_audit' => $expAudit,
        /* ===== BUKTI RESIDUAL FINAL (§7 instruksi operator) =====================================
         * Gas Shortage hanya boleh DITETAPKAN bila keempat syarat di bawah terpenuhi sekaligus.
         * Bila salah satu tidak terpenuhi, angka LNG/distillate TIDAK boleh disajikan sebagai
         * nilai final — lihat 'final_values_publishable'. */
        'gas_required_before_export_minimization' => $tp['gas_required_before_export_minimization']
            ?? ($expAudit['gas_before_export_reduction_bbtud'] ?? null),
        'gas_required_after_export_minimization'  => $tp['gas_required_after_export_minimization']
            ?? round((float)($audit['gas']['used_bbtud'] ?? 0), 4),
        'final_gas_shortage' => ($dir === 'OVER_QUOTA') ? ($tp['final_gas_shortage'] ?? round($gap, 4)) : null,
        'export_slots_blocked' => $tp['export_slots_blocked']
            ?? (($expAudit['rows_total'] ?? null) === null ? null
                : (int)$expAudit['rows_total'] - (int)($expAudit['rows_at_range_min'] ?? 0)
                  - (int)($expAudit['rows_still_reducible'] ?? 0)),
        'minimization_completed' => array_key_exists('minimization_completed', $tp)
            ? (bool)$tp['minimization_completed'] : (bool)($expAudit['minimization_complete'] ?? false),
        'converged' => $convRun,
        'two_phase_executed' => !empty($tp),
        'two_phase_record' => $tp ?: null,
        /* Two separate gates. fuel_estimate_ready permits the operator decision step after
         * dispatch/export exhaustion. It deliberately does not claim final release or completed
         * economic optimality. final_values_publishable remains the stricter final-data gate. */
        'fuel_estimate_ready' => $dispatchCannotCloseGap
            && $exportWorkAttempted
            && ((float)($tp['final_gas_shortage'] ?? $gap) > 1e-6)
            && ($lngEst > 1e-6) && ($distEst > 0),
        'dispatch_cannot_close_gap' => $dispatchCannotCloseGap,
        'dispatch_resolution_proof' => [
            'gas_gap_bbtud' => round(max(0.0, $gap), 4),
            'gas_reduction_upper_bound_bbtud' => round($gasReductionUpper, 4),
            'gas_reduction_shortfall_bbtud' => round($gasReductionShortfall, 4),
            'non_gas_up_mw_slot' => round($nonGasUp, 2),
            'rows_still_reducible' => (int)($expAudit['rows_still_reducible'] ?? 0),
            'proof_rule' => 'FUEL_REQUIRED_WHEN_DISPATCH_UPPER_BOUND_CANNOT_CLOSE_GAS_GAP',
        ],
        /* Strict final-data gate retained for release/publication semantics. */
        'shortage_declarable' => $dispatchCannotCloseGap
            && $exportWorkAttempted
            && ((float)($audit['gas']['used_bbtud'] ?? 0) > (float)($audit['gas']['quota_bbtud'] ?? 0)),
        'final_values_publishable' => ($dir === 'OVER_QUOTA')
            && ($convRun === true)
            && ((int)($expAudit['rows_still_reducible'] ?? 1) === 0)
            && (array_key_exists('minimization_completed', $tp)
                ? (bool)$tp['minimization_completed'] : (bool)($expAudit['minimization_complete'] ?? false)),
        'relevant_actions' => ($dir === 'UNDER_TARGET')
            ? ['NAIKKAN_DISPATCH_GAS_LEGAL', 'TINJAU_KOMITMEN_UNIT', 'TURUNKAN_KUOTA_JIKA_KONTRAK_MENGIZINKAN']   // BUKAN gas shortage: LNG/distillate tidak relevan
            : ['TURUNKAN_DISPATCH_GAS', 'TURUNKAN_EXPORT_SAMPAI_RANGE_MIN', 'NAIKKAN_NON_GAS', 'SUBSTITUSI_DISTILLATE', 'TAMBAH_LNG'],
        /* Arah UNDER_TARGET: LNG dan distillate TIDAK ditawarkan. LNG menaikkan kuota efektif
         * sehingga justru memperlebar kekurangan; distillate menggantikan gas sehingga menurunkan
         * pemakaian gas — keduanya bergerak ke arah yang salah untuk masalah ini. */
        'options' => [
            'lng' => [
                'available' => $gap > 0 && $dir === 'OVER_QUOTA',
                'not_offered_reason' => ($dir === 'UNDER_TARGET') ? 'LNG_MENAIKKAN_KUOTA_MEMPERBURUK_UNDER_TARGET' : null,
                'estimated_required' => round(max(0.0, $lngEst), 4),
                'unit' => 'BBTUD',
                'field' => 'data3.modeling.additional_lng',
                'action_value' => 'add_lng',
                'basis' => 'Residual gas FINAL setelah minimisasi Export selesai (kebutuhan gas final - kuota efektif). LNG bersifat must-take: kuota efektif ikut naik sebesar jumlah ini.',
                'engine_recommended_lng_bbtud' => $lngEngine,
                'engine_recommended_lng_note' => 'angka engine dihitung sebelum lever minimisasi Export bekerja; disimpan sebagai bukti pelacakan, bukan nilai yang ditawarkan',
                'based_on_final_residual' => isset($tp['final_gas_shortage']),
                'validation_status' => 'GAS_GAP_ONLY_NOT_VALIDATED',
                'validation_note' => 'Angka ini menutup selisih gas, belum tentu membuat seluruh hard validation lulus. Hasil sebenarnya hanya diketahui setelah rerun. Jumlah yang terlalu besar dapat membuat gas jatuh DI BAWAH window karena LNG must-take.',
                'estimated_cost_usd' => isset($cands['add_lng']['cost_usd']) ? (float)$cands['add_lng']['cost_usd'] : null,
                'max_physical' => 100.0,
            ],
            'distillate' => [
                'available' => $gap > 0 && $dir === 'OVER_QUOTA',
                'not_offered_reason' => ($dir === 'UNDER_TARGET') ? 'DISTILLATE_MENURUNKAN_PEMAKAIAN_GAS_MEMPERBURUK_UNDER_TARGET' : null,
                'estimated_required_liters' => round(max(0.0, $distEst), 1),
                'unit' => 'l/day',
                'field' => 'data3.modeling.gas_shortage_action=use_distillate',
                'action_value' => 'use_distillate',
                'basis' => $conv['basis'],
                'conversion' => $conv,
                'validation_status' => $conv['complete_from_input'] ? 'PARAMETER_LENGKAP_DARI_INPUT' : 'PARAMETER_MEMAKAI_DEFAULT_ENGINE',
                'validation_note' => $conv['complete_from_input'] ? null
                    : 'Sebagian faktor konversi belum ada di input dan memakai default engine — isi distillate_density_kg_per_l, distillate_lhv_btu_per_lb, dan lb_per_kg agar angka liter dapat diaudit.',
                'estimated_cost_usd' => isset($cands['use_distillate']['cost_usd']) ? (float)$cands['use_distillate']['cost_usd'] : null,
                'max_physical_liters' => 5000000.0,
            ],
            'combination_allowed' => false,
            'combination_note' => 'gas_shortage_action hanya menerima satu nilai (none|add_lng|use_distillate|force_same_as_quota); kombinasi LNG+distillate tidak didukung backend sehingga tidak ditawarkan.',
        ],
        'slots_needing_support' => $slots,
        'current_action' => $act,
        'minimum_feasible_probe' => [
            'endpoint' => 'run.php?mode=shortage_probe&kind=lng|distillate',
            'purpose' => 'Mencari jumlah TERKECIL pada tangga kandidat yang TERBUKTI menghasilkan rencana valid (hard validation lulus DAN konvergen).',
            'cost' => 'Satu kandidat = satu simulasi penuh (10-60 detik); karena itu TIDAK dijalankan otomatis pada setiap Run.',
            'available' => ($dir === 'OVER_QUOTA'),
        ],
        'rerun_contract' => [
            'lng'        => ['data3.modeling.gas_shortage_action' => 'add_lng',        'data3.modeling.additional_lng' => '<BBTUD>'],
            'distillate' => ['data3.modeling.gas_shortage_action' => 'use_distillate'],
            'note' => 'Rerun WAJIB mengirim input bersih yang sama ditambah field di atas; jangan mengirim state parsial hasil sebelumnya. Sertakan _request_id agar respons lama tidak menimpa rerun terbaru.',
        ],
        'publish_allowed' => false,
    ];
}

/* ==============================================================================================
 *  INSTRUMENTASI PERHITUNGAN GANDA (instruksi §10).
 *
 *  Pertanyaannya bukan "berapa lama", melainkan "berapa kali state yang SAMA dihitung ulang".
 *  Karena itu setiap pemanggilan fase berat dicatat bersama SIDIK JARI STATE-nya. Dua pemanggilan
 *  dengan sidik jari sama adalah pekerjaan yang terbuang; dua pemanggilan dengan sidik jari berbeda
 *  adalah pekerjaan yang memang diperlukan setelah state berubah. Tanpa sidik jari, jumlah panggilan
 *  saja tidak dapat membedakan keduanya — dan itulah sebabnya audit sebelumnya tidak menemukan apa pun.
 *
 *  Biayanya satu hash per panggilan fase berat, dan tidak ada satu pun keputusan yang bergantung
 *  padanya: instrumentasi hanya membaca, tidak pernah mengubah hasil. */
function pp_state_fingerprint(array $input, array $output): string {
    /* SIDIK JARI LENGKAP, BUKAN RINGKASAN.
     *
     * Versi ringkas (hanya baris + beberapa field aksi) TERBUKTI SALAH lewat oracle A/B: hasil
     * dengan cache berbeda dari hasil tanpa cache. Sebabnya validator juga membaca blok `info`
     * — angka gas, kuota, offset — sehingga dua state dengan baris yang sama tetapi `info` berbeda
     * memperoleh sidik jari yang sama dan saling menimpa. Itu persis definisi cache basi.
     *
     * Karena itu sidik jari memuat SELURUH input modeling dan SELURUH output yang dibaca validator.
     * Hashing struktur besar memang lebih mahal daripada hashing ringkasan, tetapi tetap jauh lebih
     * murah daripada menjalankan ulang validasi — dan yang terpenting, ia benar. */
    return substr(hash('sha256', json_encode([
        'modeling' => $input['data3']['modeling'] ?? [],
        'rows'     => $output['data'] ?? null,
        'info'     => $output['info'] ?? null,
    ])), 0, 24);
}
function pp_instr(string $phase, string $fingerprint, float $elapsedMs = 0.0): void {
    if (!isset($GLOBALS['__pp_instr']) || !is_array($GLOBALS['__pp_instr'])) $GLOBALS['__pp_instr'] = [];
    if (!isset($GLOBALS['__pp_instr'][$phase]))
        $GLOBALS['__pp_instr'][$phase] = ['calls' => 0, 'unique' => [], 'duplicate' => 0, 'ms' => 0.0];
    $e = &$GLOBALS['__pp_instr'][$phase];
    $e['calls']++;
    $e['ms'] += $elapsedMs;
    if (isset($e['unique'][$fingerprint])) $e['duplicate']++;
    else $e['unique'][$fingerprint] = true;
}
function pp_instr_report(): array {
    $out = [];
    foreach ((array)($GLOBALS['__pp_instr'] ?? []) as $phase => $e) {
        $out[$phase] = [
            'call_count'             => (int)$e['calls'],
            'unique_state_count'     => count((array)$e['unique']),
            'duplicate_same_state'   => (int)$e['duplicate'],
            'elapsed_ms'             => round((float)$e['ms'], 1),
        ];
    }
    ksort($out);
    return $out;
}

function pp_validate_hard_constraints(array $input, array $output): array {
    /* ==========================================================================================
     * CACHE REUSE DIHAPUS — KEPUTUSAN BERDASARKAN PENGUKURAN, BUKAN DUGAAN.
     *
     * Reuse hasil validasi untuk state identik sempat dipasang di sini. Oracle A/B (PP_HV_CACHE=0
     * versus menyala) membuktikannya TIDAK SETARA: hasil berbeda. Penyebabnya, validator tidak
     * murni — ia juga menulis bukti/global yang dibaca tahap berikutnya, sehingga melewatinya
     * mengubah keadaan hilir. Sidik jari selengkap apa pun tidak dapat memperbaiki itu.
     *
     * Pengukuran juga menunjukkan reuse-nya memang tidak layak dikejar: dengan sidik jari LENGKAP
     * hanya 3 dari 36 pemanggilan yang benar-benar berulang, dan SELURUH 33 validasi nyata hanya
     * memakan 36,6 ms pada run 24 detik — 0,15% waktu. Angka "20 duplikat" yang sempat terukur
     * berasal dari sidik jari ringkas yang keliru menyamakan state berbeda.
     *
     * Waktu run ada di core run dispatch, dan di sana duplikat terukur NOL. Instrumentasi
     * dipertahankan supaya klaim ini dapat diperiksa ulang kapan saja. */

    $model = $input['data3']['modeling'] ?? [];
    /* V5 — VALIDASI CHANGE OVER TANPA GLOBAL PROSES. Timeline sim yang diputuskan sweep
     * sebelumnya hanya tersedia di global proses (__pp_co_resolved), sehingga validasi di proses
     * lain (pekerja pembantu, penjaga kolam, request berikutnya, release gate job) memakai aturan
     * sim lama (source Cannot Stop sepanjang hari) dan melaporkan cannot_stop/commitment_continuous
     * palsu pada dispatch pemenang. Output yang SUDAH mengeksekusi Change Over membawa baris
     * perintah yang dipilih; bila global untuk konfigurasi yang sama tidak ada, timeline itu
     * dipakai untuk normalisasi validasi ini saja (global dipulihkan sesudahnya). */
    $__coTmp = false; $__coPrev = $GLOBALS['__pp_co_resolved'] ?? null;
    if (!empty($model['change_over']['enabled'])) {
        $__coT = (array)($output['info']['Change Over Timeline'] ?? []); $__coSel = (array)($__coT['selected'] ?? []);
        $__coSig = substr(md5(json_encode($model['change_over'] ?? [])), 0, 16);
        if (!empty($__coT['executed']) && isset($__coSel['target_start_command_row'], $__coSel['source_stop_command_row'])
            && !(is_array($__coPrev) && ($__coPrev['input_sig'] ?? null) === $__coSig)
            && function_exists('pp_changeover_sim_pair') && pp_changeover_sim_pair($input) !== null) {
            $__hm = function (int $r) { $mm = $r * 30; return sprintf('%02d:%02d', intdiv($mm, 60) % 24, $mm % 60); };
            $GLOBALS['__pp_co_resolved'] = ['input_sig' => $__coSig, 'target_block' => (string)($__coT['target_block'] ?? ''),
                'source_block' => (string)($__coT['source_block'] ?? ''),
                'start_other' => $__hm((int)$__coSel['target_start_command_row']), 'stop_other' => $__hm((int)$__coSel['source_stop_command_row'])];
            $__coTmp = true;
        }
        pp_normalize_change_over($model);
        if ($__coTmp) { if ($__coPrev === null) unset($GLOBALS['__pp_co_resolved']); else $GLOBALS['__pp_co_resolved'] = $__coPrev; }
    }
    /* FORMAT CANONICAL unit_stop_time: migrasi + laporan error terstruktur (tanpa silent fallback). */
    $stErr = [];
    pp_normalize_unit_stop_time($model, $stErr);
    foreach ($stErr as $e) $V[] = ['unit_stop_time_format', (string)($e['message'] ?? 'format tidak sah')];   // PARITY J-GROUP: aturan co identik dgn engine
    $rows  = $output['data'] ?? [];
    $info  = $output['info'] ?? [];
    $n = count($rows);
    $V = [];

    /* PROMPT MONITORING DAILY PLAN §8.4: row Actual/locked (TIME PASSED = Y atau Actual Data)
     * adalah history — pelanggaran constraint pada row tsb TIDAK boleh mengubah data dan TIDAK
     * dihitung sbg optimizer violation (FAIL). Dilaporkan terpisah sbg historical deviation.
     * Tanpa row Actual, perilaku validator identik dgn sebelumnya (zero regression). */
    $histV = [];
    $isActRow = function (int $i) use ($rows): bool { return !empty($rows[$i]['Actual'] ?? null); };
    $addV = function (string $cat, string $msg, ?int $rowIdx = null) use (&$V, &$histV, $isActRow, $n) {
        if ($rowIdx !== null && $rowIdx >= 0 && $rowIdx < $n && $isActRow($rowIdx)) {
            $histV[] = [$cat, 'HISTORICAL VIOLATION / ACTUAL DEVIATION (locked/time-passed row): ' . $msg];
            return;
        }
        $V[] = [$cat, $msg];
    };
    $splitAct = function (array $rowIdxs) use ($isActRow): array {   // [futureRows, actualRows]
        $f = []; $a = [];
        foreach ($rowIdxs as $ri) { if ($isActRow((int)$ri)) $a[] = $ri; else $f[] = $ri; }
        return [$f, $a];
    };

    /* 1b. PROMPT GAS_ACCOUNTING §2.1: PGN PIPE window per-supplier + identitas PGN TOTAL. */
    {
        $pq = (float)($info['PGN Pipe Quota (BBTUD)'] ?? 0);
        $pu = (float)($info['PGN Pipe Used (BBTUD)'] ?? 0);
        $lu = (float)($info['LNG Used (BBTUD)'] ?? 0);
        $pt = (float)($info['PGN Total (BBTUD)'] ?? 0);
        if ($pq > 0) {
            /* PROMPT ACTUAL GAS (§6.2 + prinsip historis §8.4): bila ACTUAL PGN terisi, PGN Pipe Used
             * = residual dari total blended (actual sudah TERJADI — bukan keputusan optimizer).
             * Pipe keluar window karena pengukuran actual => HISTORICAL/ACTUAL DEVIATION (dilaporkan
             * transparan), bukan optimizer violation; future rows tetap quota-consistent via targeting. */
            /* BAGIAN C/G (menggantikan downgrade lama): Effective PGN Pipe di luar window TIDAK LAGI
             * otomatis "HISTORICAL" — optimizer WAJIB mengompensasi lewat redispatch future rows
             * (pp_actual_gas_compensation). PASS palsu 27.7318 > 27.5 dgn actual = akar yang ditutup.
             * Kalau kompensasi mustahil, ini violation keras dgn evidence (bisa VALID-INFEASIBLE). */
            if ($pu > $pq + 1e-9)
                $V[] = ['gas_quota', sprintf('gas quota exceeded: Effective PGN PIPE TOTAL %.4f > quota %.4f (over %.4f) — actual jam terukur menaikkan pemakaian dan redispatch future rows belum/tidak dapat menyerapnya', $pu, $pq, $pu - $pq)];
            if ($pu < $pq - 0.04 - 1e-9)
                $V[] = ['gas_quota', sprintf('Effective PGN PIPE TOTAL %.4f < quota-0.04 (%.4f) — pemakaian gas harus dinaikkan (up-dispatch) sampai Pipe masuk window; LNG fixed tidak boleh dikurangi', $pu, $pq - 0.04)];
        }
        /* PROMPT LNG_FIXED §: LNG Used wajib = LNG quota (fixed contract, incl Additional LNG) */
        $lq = (float)($info['LNG Quota (BBTUD)'] ?? 0);
        if ($lq > 0 && abs($lu - $lq) > 0.0005)
            $V[] = ['gas_quota', sprintf('LNG TOTAL %.4f != LNG quota %.4f (fixed contract — Additional LNG menambah, bukan mengganti)', $lu, $lq)];
        if ($pt > 0 && abs($pt - ($pu + $lu)) > 0.002)
            $V[] = ['gas_quota', sprintf('PGN TOTAL %.4f != PGN PIPE %.4f + LNG %.4f (selisih %.4f)', $pt, $pu, $lu, $pt - $pu - $lu)];
    }
    // 1. Gas Quota target window: quota-0.04 <= Total Gas Used <= quota.
    $quota = (float)($info['Total Gas Quota (BBTUD)'] ?? 0);
    $used  = (float)($info['Total Gas Used (BBTUD)'] ?? ($info['Gas Fuel Total (BBTUD)'] ?? 0));
    /* BAGIAN B/C/G — bila ACTUAL gas terisi, angka yang dinilai adalah EFFECTIVE (actual-over-estimation),
     * bukan estimasi murni: kompensasi auto-rerun menggeser dispatch future rows sehingga estimasi turun/
     * naik, dan justru TOTAL EFEKTIF-lah yang wajib masuk window kontrak. */
    $hasAnyActual = ((int)($info['Actual Hours Provided'] ?? 0)) > 0;
    if ($hasAnyActual && isset($info['Effective Total Gas (BBTUD)']))
        $used = (float)$info['Effective Total Gas (BBTUD)'];
    /* PROMPT LNG_FIXED: window total ketat kembali — LNG fixed contract, penghematan tidak boleh
     * menurunkan total di bawah window; engine wajib top-up. */
    /* PROMPT AUDIT STRICT WINDOW §2: window eksak quota-0.04 <= used <= quota (epsilon 1e-9
     * murni utk float, bukan pelonggaran). 64.459 -> FAIL, 64.460 -> PASS, 64.500 -> PASS,
     * 64.501 -> FAIL. Tidak ada lagi -0.041 / +0.0005. */
    /* KEPUTUSAN DOMAIN FINAL (STRICT GAS WINDOW): batas bawah DAN atas SELALU aktif, tidak boleh
     * dimatikan oleh gas_shortage_action / force_same_as_quota / popup / konteks Plan-Monitoring.
     * gas_shortage_action hanya mengatur STRATEGI mencapai window, bukan keberadaannya.
     *   quota_min = quota - 0.04 ; quota_max = quota ; epsilon 1e-9 murni float-noise. */
    $quotaMin = max(0.0, $quota - 0.04); $quotaMax = $quota;
    if (!(($quotaMin - 1e-9) <= $used && $used <= ($quotaMax + 1e-9))) {
        $V[] = ['gas_quota', sprintf('Total Gas Used=%.4f outside [%.4f,%.4f]', $used, $quotaMin, $quotaMax)];
        /* PROMPT_RESET: bila UNDER quota karena Maximum Flow MM2100 / MM2100 quota membatasi gas MM2100
         * (unit G10/GE tak boleh naik lagi), itu VALID-INFEASIBLE — emit evidence eksplisit. */
        $maxFlowMM = (float)($model['max_flow_mm2100'] ?? 0);
        if ($used < 0.0 && $maxFlowMM > 0) {
            $info['Warnings'][] = sprintf('Gas quota under-utilized (%.4f < %.4f) because Maximum Flow MM2100=%.1f MMSCFD caps MM2100 generation (G10/GE1-4) — no feasible gas lever remains without breaching the MM2100 flow limit. VALID-INFEASIBLE.', $used, $quota, $maxFlowMM);
        }
    }

    // 2. Export PLN Effective Range, per row
    $expUnder = 0; $expOver = 0;
    foreach ($rows as $i => $r) {
        $e = (float)($r['Export_PLN'] ?? 0); $lo = (float)($r['pln_lo'] ?? 0); $hi = (float)($r['pln_hi'] ?? 1e9);
        if ($e < $lo - 1e-9) { if (!$isActRow($i)) $expUnder++; $addV('export_range', 'row ' . ($i + 1) . ": Export $e < RangeMin $lo", $i); }   /* §3: strict — 14.99 < 15 gagal */
        if ($e > $hi + 0.51) { if (!$isActRow($i)) $expOver++;  $addV('export_range', 'row ' . ($i + 1) . ": Export $e > RangeMax $hi", $i); }
    }

    // A unit with an explicit STOP schedule (full-day unit_stop or a unit_stop_time window) legitimately sits
    // at 0 MW — the stop overrides last-status-Running and cannot-stop for the covered rows.
    $stoppedFull = array_map('strtolower', (array)($model['unit_stop'] ?? []));
    $stopWindows = [];
    foreach (($model['unit_stop_time'] ?? []) as $w) {
        if (is_array($w) && !empty($w['unit'])) $stopWindows[strtolower((string)$w['unit'])][] = [(int)($w['start'] ?? 1), (int)($w['stop'] ?? $n)];
    }
    $gtgStopped = function (string $g, int $row1) use ($stoppedFull, $stopWindows): bool {
        $g = strtolower($g);
        if (in_array($g, $stoppedFull, true)) return true;
        foreach ($stopWindows[$g] ?? [] as [$s, $e]) if ($row1 >= $s && $row1 <= $e) return true;
        return false;
    };
    $stoppedAt = function (string $u, int $row1) use ($stoppedFull, $stopWindows, $rows, $gtgStopped): bool {
        $u = strtolower($u);
        if (in_array($u, $stoppedFull, true)) return true;
        foreach ($stopWindows[$u] ?? [] as [$s, $e]) if ($row1 >= $s && $row1 <= $e) return true;
        // an STG is implicitly stopped when every GTG feeding its block is stopped (no steam source)
        $feeders = ['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']];
        if (isset($feeders[$u])) {
            $present = array_filter($feeders[$u], fn($g) => isset($rows[0][strtoupper($g)]));
            if ($present) { $allStopped = true; foreach ($present as $g) if (!$gtgStopped($g, $row1)) { $allStopped = false; break; } if ($allStopped) return true; }
        }
        return false;
    };

    /* PATCH G01 (paritas dgn normalisasi engine worker02): STG last=Running tanpa feeder yang mungkin
     * online di 00:30 (semua feeder last!=Running atau stop window mencakup row 1) = input kontradiktif.
     * Aturan deterministik yang SAMA dgn engine: STG diperlakukan start hari ini (last=Stop). Berlaku utk
     * SEMUA cek di bawah (last_data_status, cannot_stop, commitment_continuous) via $model lokal. */
    foreach (['s1','s2','s3'] as $sNv) {
        $curLsS = strtolower((string)($model['unit_last_data_status'][strtoupper($sNv)] ?? ''));
        if ($curLsS !== 'running') continue;
        $d3nv = $input['data3'] ?? [];
        if (!isset($d3nv[$sNv])) continue;
        $feedNv = array_map('strtolower', array_values($d3nv[$sNv]['hrsg'] ?? ($d3nv[$sNv]['gtg'] ?? [])));
        $anyFv = false;
        foreach ($feedNv as $fNv) {
            if (strtolower((string)($model['unit_last_data_status'][strtoupper($fNv)] ?? '')) !== 'running') continue;
            if ($stoppedAt($fNv, 1)) continue;
            $anyFv = true; break;
        }
        if (!$anyFv) $model['unit_last_data_status'][strtoupper($sNv)] = 'Stop';
    }

    // 3. Unit Last Data Status (row 0)
    foreach (($model['unit_last_data_status'] ?? []) as $u => $st) {
        $st = strtolower((string)$st);
        $key = null;
        foreach ([strtoupper($u), ucfirst(strtolower($u)), $u] as $cand) { if (isset($rows[0][$cand])) { $key = $cand; break; } }
        if ($key === null) continue;
        $val = (float)$rows[0][$key];
        $rmU = strtolower((string)(($model['required_mode'][strtolower((string)$u)]['mode'] ?? '')));
        if ($st === 'stop' && $val > 0.51 && $rmU !== 'continuous') $addV('last_data_status', strtoupper($u) . " Stop but row0=$val", 0);   // continuous commitment menang: start row0 sah (dgn startup seq)
        /* V8: GE tanpa sumber gas MM2100 sah WAJIB 0 MW — Last Data Running tidak mewajibkannya berjalan. */
        if ($st === 'running' && $val <= 0.0 && !$stoppedAt((string)$u, 1)
            && !(pp_ge_unit(strtolower((string)$u)) && !pp_effective_unit_available($input['data3'] ?? [], $model, strtolower((string)$u), 1)))
            $addV('last_data_status', strtoupper($u) . " Running but row0=$val", 0);
    }

    /* V9 — AKUN BAHAN BAKAR: tidak ada fuel yang dipakai tanpa akun biaya (seluruh fuel). */
    if ((string)getenv('PP_V9_FUEL_ACCOUNT') !== '0' && function_exists('pp_v9_fuel_accounts')) foreach (pp_v9_fuel_accounts($model, $info)['violations'] as $fv) $addV($fv[0], $fv[1], null);

    /* V8 — SUMBER GAS MM2100. GE1-GE4 dan G10 memasok pelanggan MM2100 dan hanya boleh berbeban bila
     * sumber gas MM2100 sah tersedia (kuota KP72, Actual Energy MM2100, atau fixed flow manual MM2100).
     * Tanpa sumber sah, setiap MW pada unit tersebut adalah pemakaian gas tanpa pasokan. */
    if ((string)getenv('PP_V8_GE_GAS_GATE') !== '0') {
        $mmSrcV = pp_v8_mm2100_source($model);
        if (!$mmSrcV['legal']) {
            foreach (['GE1','GE2','GE3','GE4','G10'] as $uMMv) {
                $rowsMMv = [];
                foreach ($rows as $riMMv => $rwMMv) if ((float)($rwMMv[$uMMv] ?? 0) > 0.01) $rowsMMv[] = $riMMv;
                [$fMMv, $aMMv] = $splitAct($rowsMMv);
                if ($fMMv) $addV('mm2100_gas_source', sprintf('%s berbeban pada %d row (row %s) tanpa sumber gas MM2100 sah (kuota KP72 0, Actual Energy MM2100 0, fixed flow manual MM2100 0)',
                    $uMMv, count($fMMv), implode(',', array_map(function ($x) { return $x + 1; }, array_slice($fMMv, 0, 12)))), null);
                if ($aMMv) $histV[] = ['mm2100_gas_source', sprintf('HISTORICAL: %s berbeban pada %d row actual tanpa sumber gas MM2100 sah', $uMMv, count($aMMv))];
            }
        }
    }

    // 4. Required / Cannot Stop / Commitment Mode
    $cannotStop = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
    $required   = array_map('strtolower', (array)($model['required_units'] ?? []));
    foreach ($cannotStop as $u) {
        $key = strtoupper($u); if (!isset($rows[0][$key])) continue;
        /* PROMPT FINAL §9: Last Data Status = Stop + Cannot-Stop/Continuous berarti unit START hari ini
         * dgn startup sequence lalu tidak berhenti — rows 0 SEBELUM start pertama adalah sah. */
        $lsU = strtolower((string)($model['unit_last_data_status'][$key] ?? ($model['unit_last_data_status'][$u] ?? '')));
        $firstOn = -1; foreach ($rows as $i => $r) if ((float)($r[$key] ?? 0) > 0.001) { $firstOn = $i; break; }
        $zeroRows = []; foreach ($rows as $i => $r) {
            if ((float)($r[$key] ?? 0) > 0.001 || $stoppedAt($u, $i + 1)) continue;
            if ($lsU === 'stop' && ($firstOn < 0 || $i < $firstOn)) continue;   // belum start (fresh) — sah
            $zeroRows[] = $i;
        }
        [$zeroRows, $zeroHist] = $splitAct($zeroRows);
        if ($zeroRows) $V[] = ['cannot_stop', "$key zero at rows " . implode(',', array_slice($zeroRows, 0, 5)) . (count($zeroRows) > 5 ? '...' : '')];
        if ($zeroHist) $histV[] = ['cannot_stop', "HISTORICAL VIOLATION / ACTUAL DEVIATION (locked/time-passed row): $key zero at rows " . implode(',', array_slice($zeroHist, 0, 5))];
    }
    foreach ($required as $u) {
        // an explicit STOP schedule overrides Required: skip a unit stopped on every row.
        $allStopped = true; for ($ri = 0; $ri < $n; $ri++) if (!$stoppedAt($u, $ri + 1)) { $allStopped = false; break; }
        if ($allStopped) continue;
        $key = strtoupper($u); if (!isset($rows[0][$key])) continue;
        $runs = 0; $nz = []; foreach ($rows as $i => $r) if ((float)($r[$key] ?? 0) > 0.001) { $runs++; $nz[] = $i; }
        if ($runs === 0) $V[] = ['required_units', "$key required but never runs"];
        elseif ($runs < 2) $V[] = ['required_units_efficiency', "$key required but runs only $runs row (" . implode(',', $nz) . ')'];
    }

    // 5. Ramp rates: Export <=30MW/30min hard; Babelan <=5MW/30min PER UNIT (BB1, BB2)
    $rampViol = 0; $prev = null;
    foreach ($rows as $i => $r) {
        $e = (float)($r['Export_PLN'] ?? 0);
        if ($prev !== null && abs($e - $prev) > pp_export_step_limit($model) + 1e-6) { if (!$isActRow($i)) $rampViol++; $addV('export_ramp', 'row ' . ($i + 1) . ': |dExport|=' . round(abs($e - $prev), 1) . ' > 35', $i); }
        $prev = $e;
    }
    $bbRampViol = 0; $pb1 = $pb2 = null;
    $bbRampLim = pp_babelan_ramp_limit($input['data3']['modeling'] ?? []);   // §4 single source of truth
    foreach ($rows as $i => $r) {
        $b1 = (float)($r['BB1'] ?? 0); $b2 = (float)($r['BB2'] ?? 0);
        if ($pb1 !== null) {
            if (abs($b1 - $pb1) > $bbRampLim + 1e-6) { if (!$isActRow($i)) $bbRampViol++; $addV('babelan_ramp', 'row ' . ($i + 1) . ': |dBB1|=' . round(abs($b1 - $pb1), 2) . ' > ' . $bbRampLim, $i); }
            if (abs($b2 - $pb2) > $bbRampLim + 1e-6) { if (!$isActRow($i)) $bbRampViol++; $addV('babelan_ramp', 'row ' . ($i + 1) . ': |dBB2|=' . round(abs($b2 - $pb2), 2) . ' > ' . $bbRampLim, $i); }
        }
        $pb1 = $b1; $pb2 = $b2;
    }

    // 5b. MM2100 daily quota window + Maximum Flow MM2100 (Master Audit Bagian F/G/H)
    /* KEBIJAKAN PRESISI: validator WAJIB memakai nilai full precision, bukan angka display.
     * 'MM2100 Quota (BBTUD)' dan 'MM2100 Used + Startup (BBTUD)' adalah nilai TAMPILAN
     * (round 4 desimal). Nilai internal penuh dipublikasikan pada info['MM2100 Precision'].
     * Fallback ke nilai display hanya bila channel presisi tidak tersedia (build lama). */
    $mmPrec  = (array)($info['MM2100 Precision'] ?? []);
    $mmQuota = (float)($mmPrec['quota_bbtud'] ?? $info['MM2100 Quota (BBTUD)'] ?? 0);
    if ($mmQuota > 1e-9) {
        $mmTot = (float)($mmPrec['used_plus_startup_bbtud'] ?? $info['MM2100 Used + Startup (BBTUD)'] ?? 0);
        if ($mmTot > $mmQuota + 1e-9)              $V[] = ['mm2100_quota', sprintf('MM2100 Used+Startup %.4f > quota %.4f', $mmTot, $mmQuota)];              // AUDIT §4: used > quota = breach
        elseif ($mmTot < $mmQuota - 0.04 - 1e-9)   $V[] = ['mm2100_quota', sprintf('MM2100 Used+Startup %.4f < quota-0.04 (%.4f)', $mmTot, $mmQuota - 0.04)];
    }
    /* ADDENDUM Bagian 2.1: Maximum Flow MM2100 dalam MMSCFD -> cap energi = x GHV_MM2100/1000/48 */
    $mmMaxFlow = (float)($info['Maximum Flow MM2100 (MMSCFD)'] ?? ($info['Maximum Flow MM2100 (BBTUD)'] ?? 0));
    if ($mmMaxFlow > 1e-9) {
        $ghvMv = (float)($model['ghv_mm2100'] ?? 1000);
        $capSlotV = $mmMaxFlow * $ghvMv / 1000.0 / 48.0; $mfBad = [];
        foreach ($rows as $i2 => $r2) if ((float)($r2['Est_FF_M'] ?? 0) > $capSlotV + 1e-6) $mfBad[] = $i2 + 1;
        if ($mfBad) $V[] = ['mm2100_max_flow', 'rows over Maximum Flow MM2100: ' . implode(',', array_slice($mfBad, 0, 5))];
    }

    // 6. Bus Flow
    $busMin = (float)($model['busflow_min'] ?? 0);
    $busViol = []; foreach ($rows as $i => $r) if ((float)($r['BusFlow'] ?? 0) < $busMin - 0.51) $busViol[] = $i;
    [$busViol, $busHist] = $splitAct($busViol);
    if ($busViol) $V[] = ['bus_flow', 'rows under busflow_min=' . $busMin . ': ' . implode(',', array_slice($busViol, 0, 5))];
    if ($busHist) $histV[] = ['bus_flow', 'HISTORICAL VIOLATION / ACTUAL DEVIATION (locked/time-passed row): rows under busflow_min=' . $busMin . ': ' . implode(',', array_slice($busHist, 0, 5))];

    // 7. Fixed Load
    foreach (($model['unit_fix_load'] ?? []) as $u => $rules) {
        $key = strtoupper((string)$u);
        if ($key === 'B1') $key = 'BB1'; elseif ($key === 'B2') $key = 'BB2';   /* STRESS #70: kolom Babelan = BB1/BB2 */
        foreach ((array)$rules as $rule) {
            for ($ridx = (int)($rule['start'] ?? 1) - 1; $ridx <= (int)($rule['stop'] ?? $n) - 1; $ridx++) {
                if ($ridx < 0 || $ridx >= $n) continue;
                $actual = (float)($rows[$ridx][$key] ?? -999); $expected = (float)($rule['value'] ?? 0);
                if (abs($actual - $expected) > 0.51) $addV('fixed_load', $key . ' row ' . ($ridx + 1) . ": expected fixed $expected, got $actual", $ridx);
            }
        }
    }

    // 8. Skip Load — model structure is {unit: [{start,stop,value_low,value_high}]}; a unit's load must NOT
    //    sit inside its forbidden band [low,high] across the window (0 or outside the band is fine).
    foreach (($model['unit_skip_load'] ?? []) as $u => $rules) {
        if (!is_array($rules)) continue;
        $key = strtoupper((string)$u);
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $lo = (float)($rule['value_low'] ?? 0); $hi = (float)($rule['value_high'] ?? 0);
            for ($ridx = (int)($rule['start'] ?? 1) - 1; $ridx <= (int)($rule['stop'] ?? $n) - 1; $ridx++) {
                if ($ridx < 0 || $ridx >= $n) continue;
                $actual = (float)($rows[$ridx][$key] ?? 0);
                /* SUMBER KEBENARAN TUNGGAL (D-1 §3.1/§4). Sebelumnya baris ini memakai
                 * `$actual >= $lo - 0.51 && $actual <= $hi + 0.51`, yaitu konstanta ON/OFF 0,51 MW
                 * yang disalin dari tempat lain. Akibatnya forbidden band melebar 1,02 MW dan
                 * `lower_boundary`/`upper_boundary` — yang menurut kontrak domain LEGAL — justru
                 * dilaporkan melanggar. Sekarang validator, candidate generator, dan seluruh pass
                 * pengubah dispatch memakai satu helper yang sama. Nilai baris output sudah
                 * dibulatkan 2 desimal, jadi toleransi rounding tampilan diserap eksplisit. */
                if (pp_load_in_forbidden_band($model, strtolower((string)$u), $ridx + 1, $actual, true)) {
                    $addV('skip_load', $key . ' row ' . ($ridx + 1) . ": load $actual inside forbidden band ($lo,$hi)", $ridx);
                }
            }
        }
    }

    /* 8a-bis. STOP STATUS = "Stop Based On Simulation" (MANDATORY STOP, enum based_on_sim_must):
     * unit WAJIB benar-benar berhenti minimal satu kali; waktunya bebas dipilih optimizer, tetapi
     * durasi OFF wajib >= minimum downtime kelas unit (OFF satu row lalu ON kembali TIDAK sah).
     * Continuous running BUKAN hasil yang diperbolehkan untuk mode ini. */
    foreach (($model['stop_mode'] ?? []) as $u => $cfg) {
        if (!is_array($cfg) || strtolower((string)($cfg['mode'] ?? '')) !== 'based_on_sim_must') continue;
        $key = strtoupper((string)$u); if (!isset($rows[0][$key])) continue;
        $limM = pp_runtime_limits($model);
        $clsM = pp_runtime_class(strtolower((string)$u), $input['data3'] ?? []);
        $downRows = (int)($limM[$clsM]['down_rows'] ?? $limM['gtg_small']['down_rows'] ?? 4);
        $best = 0; $cur = 0; $firstOff = -1; $bestFirst = -1;
        for ($ridx = 0; $ridx < $n; $ridx++) {
            if ((float)($rows[$ridx][$key] ?? 0) <= 0.51) {
                if ($cur === 0) $firstOff = $ridx;
                $cur++;
                if ($cur > $best) { $best = $cur; $bestFirst = $firstOff; }
            } else $cur = 0;
        }
        $tailOff = ($best > 0 && $bestFirst >= 0 && ($bestFirst + $best) >= $n);   // OFF sampai akhir hari
        if ($best === 0)
            $V[] = ['mandatory_stop', "$key STOP STATUS = Stop Based On Simulation (mandatory) but the unit never stops (continuous running is not allowed for this mode)"];
        elseif ($best < $downRows && !$tailOff)
            $V[] = ['mandatory_stop', sprintf('%s mandatory stop too short: OFF %d row(s) from row %d, minimum downtime is %d row(s)', $key, $best, $bestFirst + 1, $downRows)];
    }

    // 8b. STOP STATUS = Stop Based on Request (stop_mode.stop_at): the unit must be 0 MW at the Stop At row
    //     and stay off for the rest of the day; for a committed (required) unit the row BEFORE Stop At must
    //     still carry load (the stop is a transition, not a whole-day off).
    foreach (($model['stop_mode'] ?? []) as $u => $cfg) {
        if (!is_array($cfg) || strtolower((string)($cfg['mode'] ?? '')) !== 'stop_at') continue;
        $at = (string)($cfg['at'] ?? $cfg['stop_at'] ?? '');
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $at, $mm)) { $V[] = ['stop_at', strtoupper((string)$u) . ' Stop Based on Request active but Stop At is empty/invalid']; continue; }
        $stopRow = (int)(((int)$mm[1] * 60 + (int)$mm[2]) / 30); if ($stopRow <= 0) $stopRow = 48;
        $key = strtoupper((string)$u); if (!isset($rows[0][$key])) continue;
        for ($ridx = $stopRow - 1; $ridx < $n; $ridx++) {
            if ((float)($rows[$ridx][$key] ?? 0) > 0.51) { $V[] = ['stop_at', "$key must be 0 MW from Stop At $at (row $stopRow) but row " . ($ridx + 1) . " = " . $rows[$ridx][$key]]; break; }
        }
        $isCommitted = in_array(strtolower((string)$u), array_map('strtolower', (array)($model['required_units'] ?? [])), true);
        if ($isCommitted && $stopRow - 2 >= 0 && (float)($rows[$stopRow - 2][$key] ?? 0) <= 0.51) {
            $V[] = ['stop_at_prior', "$key should still be loaded on the row before Stop At $at but row " . ($stopRow - 1) . " = 0"];
        }
    }

    // 8c. Change Over Block 1-2 (Revisi Change Over · hard constraint). Enforced only when enabled.
    //     (a) no-anchor: Change Over YES but neither Block 1 nor Block 2 Required -> impossible, fail.
    //     (b) continuity: never a row where BOTH blocks are fully 0 MW (GTG+STG of each block = 0).
    //     (c) start-before-stop: the replacement (starting) block must be loaded no later than the row the
    //         old (stopping) block goes to 0, so there is no dead gap.
    if (!empty($model['change_over']['enabled'])) {
        if (!empty($model['co_no_anchor'])) {
            $V[] = ['change_over', 'Change Over Block 1-2 needs at least one of Block 1 or Block 2 Required (no anchor block running)'];
        }
        $co = $model['co_norm']['blocks'] ?? [];
        /* BUGFIX (Master Audit A3.15): co_norm hanya hidup di salinan model INTERNAL pp_run_simulation,
         * sedangkan validator/release-gate menerima model input mentah — sehingga check kontinuitas
         * Change Over tidak pernah aktif di gate. Bila co_norm absen, normalisasi minimal dibangun di
         * sini dari change_over.blocks (fallback unit map sama dengan worker02). */
        if (!$co) {
            $coB = (array)($model['change_over']['blocks'] ?? []);
            $fb  = ['1' => ['gtg' => 'g3', 'stg' => 's1'], '2' => ['gtg' => 'g1', 'stg' => 's2']];
            foreach (['1','2'] as $bn) {
                $bk = (array)($coB[$bn] ?? []);
                $ent = ['gtg' => strtolower((string)($bk['gtg'] ?? $fb[$bn]['gtg'])),
                        'stg' => strtolower((string)($bk['stg'] ?? $fb[$bn]['stg'])),
                        'start_row' => null, 'stop_row' => null];
                foreach (['start_other' => 'start_row', 'stop_other' => 'stop_row'] as $kSrc => $kDst) {
                    $tv = (string)($bk[$kSrc] ?? '');
                    if (preg_match('/^(\d{1,2}):(\d{2})$/', $tv, $tm)) {
                        $rr0 = (int)(((int)$tm[1] * 60 + (int)$tm[2]) / 30); if ($rr0 <= 0) $rr0 = 48;
                        $ent[$kDst] = $rr0;
                    }
                }
                $co[$bn] = $ent;
            }
        }
        $blockLoad = function(array $row, array $b) {
            // a block is "on" if its GTG or its STG carries load
            $g = strtoupper((string)($b['gtg'] ?? '')); $s = strtoupper((string)($b['stg'] ?? ''));
            $gv = $g !== '' ? (float)($row[$g] ?? 0) : 0.0;
            $sv = $s !== '' ? (float)($row[$s] ?? 0) : 0.0;
            return max($gv, $sv);
        };
        if (isset($co['1'], $co['2'])) {
            $bothOff = 0; $firstBothOff = null;
            foreach ($rows as $i => $r) {
                $l1 = $blockLoad($r, $co['1']); $l2 = $blockLoad($r, $co['2']);
                if ($l1 <= 0.51 && $l2 <= 0.51) { $bothOff++; if ($firstBothOff === null) $firstBothOff = $i + 1; }
            }
            if ($bothOff > 0) {
                $V[] = ['change_over_continuity', "both Block 1 and Block 2 are 0 MW on $bothOff row(s) during change over (first at row $firstBothOff) — replacement block must start before the old block stops"];
            }
            // start-before-stop: if one block stops at a requested row and the other starts at a requested
            // row, the starting block must be loaded by the stop row (else a gap). Evidence row-level.
            foreach ([['1','2'],['2','1']] as $pair) {
                [$a,$bb] = $pair;
                $stopRow  = $co[$a]['stop_row']  ?? null;   // block A stops here
                $startRow = $co[$bb]['start_row'] ?? null;  // block B starts here (loads startRow+1)
                if ($stopRow !== null && $startRow !== null) {
                    // block B first carries load at startRow+1; block A is 0 from stopRow. Gap if B not on by stopRow.
                    $bLoadedByStop = false;
                    for ($ri = 0; $ri < min($stopRow, $n); $ri++) { if ($blockLoad($rows[$ri], $co[$bb]) > 0.51) { $bLoadedByStop = true; break; } }
                    if (!$bLoadedByStop) {
                        $V[] = ['change_over_continuity', "Block $bb must be running before Block $a stops at row $stopRow (start-before-stop) — replacement not ready in time"];
                    }
                }
            }
        }
    }

    /* 8b. (PROMPT FINAL §4/§7/§8/§9) Independent hard checks — validator TIDAK bergantung pada
     * warning engine: min-load valid pasca-release, minimum runtime/downtime, Unit Last Data Status
     * row 1, dan Commitment Continuous. Kategori last_data_status & min_load TIDAK pernah boleh
     * VALID-INFEASIBLE (selalu bug engine bila terjadi). */
    {
        $d3v   = $input['data3'] ?? [];
        $lsV   = []; foreach (($model['unit_last_data_status'] ?? []) as $k => $v) $lsV[strtolower($k)] = strtolower((string)$v);
        $limV  = pp_runtime_limits($model);
        $smV   = $model['stg_startup_mode'] ?? [];
        $csV   = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
        $contV = $csV;
        foreach (($model['required_mode'] ?? []) as $u => $cfg)
            if (is_array($cfg) && strtolower((string)($cfg['mode'] ?? '')) === 'continuous') $contV[] = strtolower((string)$u);
        $colOf = fn($u) => strtoupper($u);
        $units = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3'];
        foreach ($units as $u) {
            if (!isset($d3v[$u])) continue;
            $C = $colOf($u);
            $loads = []; foreach ($rows as $r) $loads[] = (float)($r[$C] ?? 0);
            $mcc = (float)($d3v[$u]['min_ccload'] ?? ($d3v[$u]['min_scload'] ?? 5));
            $isStg = in_array($u, ['s1','s2','s3'], true);
            $stgU  = $isStg ? $u : pp_gtg_to_stg($d3v, $u);
            $modeU = $stgU ? (string)($smV[$stgU . '_startup'] ?? 'Cold') : 'Hot';
            $capsStart = $isStg ? [] : pp_startup_caps($stgU !== '' ? $stgU : $u, $modeU);
            $capsAddl  = $isStg ? [] : pp_additional_hrsg_caps($u);
            // --- last data status row 1 ---
            if (($lsV[$u] ?? '') === 'stop' && $loads[0] > 0.01 && !$stoppedAt($u, 1))
                if (strtolower((string)(($model['required_mode'][strtolower($C)]['mode'] ?? ''))) !== 'continuous')
                    $V[] = ['last_data_status', "row 1: $C=" . round($loads[0], 2) . ' > 0 but Last Data Status = Stop'];   // continuous commitment: start row0 sah
            if (($lsV[$u] ?? '') === 'running' && $n > 0 && $loads[0] < 0.01 && !$stoppedAt($u, 1) && pp_effective_unit_available($d3v,$model,$u,1)
                && empty($model['co_norm']['enabled']))
                $V[] = ['last_data_status', "row 1: $C=0 but Last Data Status = Running"];
            // --- continuous commitment ---
            if (in_array($u, $contV, true) && pp_effective_unit_available($d3v,$model,$u,1)) {
                for ($i = 0; $i < $n; $i++) {
                    if ($loads[$i] < 0.01 && !$stoppedAt($u, $i + 1)
                        && !($i === 0 && ($lsV[$u] ?? '') === 'stop')                       // fresh-start hari ini: row 1 = 0 sah
                        && !(($lsV[$u] ?? '') === 'stop' && max(array_slice($loads, 0, $i + 1)) < 0.01)) { // belum start
                        $addV('commitment_continuous', "row " . ($i + 1) . ": $C=0 but Continuous Running", $i); break;
                    }
                }
            }
            // --- run segments: min-load pasca-release + runtime/downtime ---
            $cls  = pp_runtime_class($u, $d3v);
            $runR = (int)($limV[$cls]['run_rows'] ?? 8); $downR = (int)($limV[$cls]['down_rows'] ?? 4);
            $segs = []; $st = -1;
            for ($i = 0; $i < $n; $i++) {
                $on = $loads[$i] > 0.01;
                if ($on && $st < 0) $st = $i;
                if ((!$on || $i === $n - 1) && $st >= 0) { $en = $on ? $i : $i - 1; $segs[] = [$st, $en]; $st = -1; }
            }
            foreach ($segs as $si => [$a, $b]) {
                $fresh = !($a === 0 && ($lsV[$u] ?? '') === 'running');
                // startup window (informasi untuk min-load): fresh start pakai caps mode; jika STG blok
                // sudah hidup di row a-1 -> Additional HRSG caps.
                $seqW = [];
                if (!$isStg && $fresh) {
                    $addl = false;
                    if ($stgU && $a > 0) {
                        $addl = ((float)($rows[$a - 1][strtoupper($stgU)] ?? 0) > 0.01);
                        /* PATCH I07 (staggered block start): blok sudah DINYALAKAN feeder lain (sibling on
                         * di a-1, STG masih dlm delay startup) => GTG ini Additional HRSG, bukan block start. */
                        if (!$addl) foreach ((array)($d3v[$stgU]['gtg'] ?? []) as $sbV) { $sbV = strtolower((string)$sbV);
                            if ($sbV !== $u && (float)($rows[$a - 1][strtoupper($sbV)] ?? 0) > 0.01) { $addl = true; break; } }
                    }
                    $seqW = $addl ? $capsAddl : $capsStart;
                }
                for ($i = $a; $i <= $b; $i++) {
                    $l = $loads[$i];
                    if ($l >= $mcc - 0.01) continue;
                    $k = $i - $a;
                    if ($fresh && $k < count($seqW) && $l <= (float)$seqW[$k] + 0.01) continue;   // langkah startup sah
                    if ($isStg) {                                                                  // STG mengikuti calc dari GTG
                        if ($l >= 0.01) continue;                                                   // >0: hasil calc_stg (sah)
                        continue;
                    }
                    $addV('min_load', 'row ' . ($i + 1) . ": $C=" . round($l, 2) . " below valid minimum $mcc outside startup sequence", $i);
                    break;
                }
                // minimum runtime: segmen yang STOP sebelum akhir hari & bukan karena stop-schedule/skip
                if ($b < $n - 1 && !$stoppedAt($u, $b + 2)) {
                    $len = $b - $a + 1 + (($a === 0 && ($lsV[$u] ?? '') === 'running') ? $runR : 0);   // lanjutan kemarin: runtime terpenuhi
                    if ($len < $runR) $V[] = ['runtime_downtime', "unit $C run rows " . ($a + 1) . '-' . ($b + 1) . " = " . ($b - $a + 1) . " rows < minimum runtime $runR rows"];
                }
                // minimum downtime: jarak ke segmen berikutnya
                if (isset($segs[$si + 1])) {
                    $gap = $segs[$si + 1][0] - $b - 1; $forced = false;
                    for ($q = $b + 2; $q <= $segs[$si + 1][0]; $q++) if ($stoppedAt($u, $q)) { $forced = true; break; }
                    if (!$forced && $gap < $downR) $V[] = ['runtime_downtime', "unit $C down gap rows " . ($b + 2) . '-' . $segs[$si + 1][0] . " = $gap rows < minimum downtime $downR rows"];
                }
            }
        }
    }

    /* 8c. (ADDENDUM ANTI AUTO-RUN §A-§C) Berlaku utk SEMUA unit — G1..G10, S1-S3, GE1-4, BBLN1/2:
     * unit yang TIDAK required / continuous / cannot-stop / start-request / start-simulation / bagian
     * block Required / Last-Status Running / unit MM2100 saat kuota MM2100 > 0, tidak boleh running
     * TANPA evidence row-level di warnings. Dengan evidence => diizinkan (bukan violation); tanpa
     * evidence => kategori auto_run (tidak punya pattern evidence => selalu FAIL/REJECT). */
    {
        $lsA = []; foreach (($model['unit_last_data_status'] ?? []) as $k => $vv) $lsA[strtolower($k)] = strtolower((string)$vv);
        $allowA = pp_autorun_allowed($model, (float)($info['MM2100 Quota (BBTUD)'] ?? 0));
        $blkFeedA = ['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']];
        $wBlobA = implode(' ', $warningsAll = ($info['Warnings'] ?? []));
        $colA = fn($u) => strtoupper($u) === 'B1' ? 'BB1' : (strtoupper($u) === 'B2' ? 'BB2' : strtoupper($u));
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'] as $uA) {
            if (!isset($input['data3'][$uA])) continue;
            if (in_array($uA, $allowA, true)) continue;
            if (($lsA[$uA] ?? '') === 'running') continue;                          // lanjutan kemarin (kondisi 2.1.7)
            $CA = $colA($uA); $firstOnA = -1; $onlyActualOn = true;
            foreach ($rows as $i => $r) if ((float)($r[$CA] ?? 0) > 0.01) { if ($firstOnA < 0) $firstOnA = $i; if (!$isActRow($i)) { $onlyActualOn = false; break; } }
            if ($firstOnA < 0) continue;                                             // unit diam — patuh
            if ($onlyActualOn) {                                                      // §8.4: ON hanya di periode actual/locked -> history, bukan auto-run optimizer
                $histV[] = ['auto_run', sprintf('HISTORICAL / ACTUAL: %s running only inside the locked/actual period (from row %d) — recorded as history, not an optimizer auto-run violation', $CA, $firstOnA + 1)];
                continue;
            }
            // evidence: warning yang menyebut unit ini ATAU (STG) GTG feed bloknya yang diizinkan/ber-evidence
            $hasEvA = stripos($wBlobA, strtoupper($uA)) !== false;
            if (!$hasEvA && isset($blkFeedA[$uA])) {
                foreach ($blkFeedA[$uA] as $fA)
                    if (in_array($fA, $allowA, true) || stripos($wBlobA, strtoupper($fA)) !== false) { $hasEvA = true; break; }
            }
            if (!$hasEvA)
                if (!empty($GLOBALS['__pp_reserve_commit'][$CA])) {
                    /* dinyalakan oleh reserve repair (hard constraint) -> bukan auto-run liar */
                } else
                $V[] = ['auto_run', sprintf('%s running from row %d but unit is NOT required/continuous/requested and NO row-level evidence found', $CA, $firstOnA + 1)];
        }
    }

    /* 8d. §3.2 PROMPT PGN_FLOW_REBALANCE: FLOW PGN REAL TIME (MMSCFD) >= Min PGN Flow per row.
     * FLOW = EnergyPGN_RT / GHV PGN * 1000 (fallback GHV Tegalgede->Jababeka bila GHV PGN kosong). */
    {
        $minPgnV = (float)($model['min_pgn_flow'] ?? 0);
        $ghvPv = (float)($model['ghv_pgn'] ?? 0);
        if ($ghvPv <= 1e-9) { $ghvPv = (float)($model['ghv_jababeka'] ?? 1034.7564); if ($ghvPv <= 1e-9) $ghvPv = 1034.7564; }
        if ($minPgnV > 0) {
            $pvV = 0; $fpV = 0; $wv = 0.0;
            foreach ($rows as $i => $r) {
                $flow = isset($r['Flow_PGN_RT']) ? (float)$r['Flow_PGN_RT'] : ((float)($r['EnergyPGN_RT'] ?? 0) / $ghvPv * 1000.0);
                if ($flow < $minPgnV - 0.001) { $pvV++; if (!$fpV) { $fpV = $i + 1; $wv = $flow; } }
            }
            if ($pvV > 0) $V[] = ['pgn_rt_min', sprintf('FLOW PGN REAL TIME below Min PGN Flow %.2f MMSCFD on %d row(s), first row %d (%.3f MMSCFD)', $minPgnV, $pvV, $fpV, $wv)];
        }
    }

    /* 8e. (PROMPT MM2100 REDESIGN §4) Ramp unit: G7/G10 Simple Cycle <= 55 MW/30min,
     * G8/G9 Combined Cycle <= 30 MW/30min. Transisi start/stop (salah satu sisi = 0) ditangani
     * validator startup/min-load; ramp dihitung hanya bila kedua row on. */
    {
        $rampLim = ['g7' => 55.0, 'g10' => 55.0, 'g8' => 30.0, 'g9' => 30.0];
        foreach ($rampLim as $uR => $limR) {
            $C = strtoupper($uR); $bad = []; $badH = [];
            for ($i = 1; $i < $n; $i++) {
                $a = (float)($rows[$i-1][$C] ?? 0); $b = (float)($rows[$i][$C] ?? 0);
                if ($a > 0.01 && $b > 0.01 && abs($b - $a) > $limR + 0.01) { if ($isActRow($i)) $badH[] = ($i + 1) . sprintf(' (|d|=%.1f)', abs($b - $a)); else $bad[] = ($i + 1) . sprintf(' (|d|=%.1f)', abs($b - $a)); }
            }
            if ($bad) $V[] = ['unit_ramp', "$C ramp > {$limR} MW/30min at rows " . implode(', ', array_slice($bad, 0, 4))];
            if ($badH) $histV[] = ['unit_ramp', "HISTORICAL VIOLATION / ACTUAL DEVIATION (locked/time-passed row): $C ramp > {$limR} MW/30min at rows " . implode(', ', array_slice($badH, 0, 4))];
        }
    }

    // 9. Startup / runtime warnings the engine itself surfaced
    /* ===== SPINNING RESERVE & BUS FLOW = HARD CONSTRAINT (per row) ==========================
     * Keduanya divalidasi dari dispatch MATERIALIZED memakai helper bersama, sehingga hasil
     * validator identik untuk Daily / Monitoring / Weekly / acceptance / release. */
    {
        $__mR = $input['data3']['modeling'] ?? [];
        $__d3R = $input['data3'] ?? [];
        $__resMin = pp_reserve_min($__mR);
        $__busMin = (float)($__mR['busflow_min'] ?? 0);
        $__busUnit = $__mR['bus_unit'] ?? [];
        foreach (($output['data'] ?? []) as $__i => $__r) {
            $__gen = [];
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'] as $__u) {
                $__c = strtoupper($__u); if ($__c === 'B1') $__c = 'BB1'; if ($__c === 'B2') $__c = 'BB2';
                $__gen[$__u] = (float)($__r[$__c] ?? 0);
            }
            if ($__resMin > 0) {
                $__res = pp_spinning_reserve($__gen, $__d3R, $__mR, $__i + 1);
                if ($__res < $__resMin - 1e-6)
                    $V[] = ['spinning_reserve', sprintf('row %d: Spinning Reserve %.2f MW < requirement %.2f MW (headroom unit online tidak cukup)', $__i + 1, $__res, $__resMin)];
            }
            if ($__busMin > 0) {
                $__bf = calc_busflow($__gen, $__busUnit, (float)($__r['IE'] ?? 0));
                if ($__bf < $__busMin - 1e-6)
                    $V[] = ['bus_flow', sprintf('row %d: Bus Flow %.2f MW < minimum %.2f MW', $__i + 1, $__bf, $__busMin)];
            }
        }
    }

    /* ITEM-I — FINAL VALIDATION ENTRYPOINT SELF-CONTAINED: validator TIDAK boleh bergantung pada
     * warning yang dititipkan engine. Startup divalidasi ULANG langsung dari dispatch MATERIALIZED
     * (kolom output, termasuk pemetaan BB1/BB2) sehingga Daily/Monitoring/Weekly/acceptance/release
     * menghasilkan violation set yang identik untuk dispatch yang sama. */
    {
        $__gr = [];
        foreach (($output['data'] ?? []) as $__r) {
            $__row = [];
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2'] as $__u) {
                $__c = strtoupper($__u); if ($__c === 'B1') $__c = 'BB1'; if ($__c === 'B2') $__c = 'BB2';
                $__row[$__u] = (float)($__r[$__c] ?? 0);
            }
            $__gr[] = $__row;
        }
        if ($__gr) {
            foreach (pp_validate_startup($__gr, $input['data3'] ?? [], $input['data3']['modeling'] ?? []) as $__w) {
                $__dup = false;
                foreach ($V as $__v) if (is_array($__v) && ($__v[1] ?? '') === $__w) { $__dup = true; break; }
                if (!$__dup) $V[] = ['startup_sequence', $__w];
            }
        }
    }

    $warnings = $info['Warnings'] ?? [];
    foreach ($warnings as $w) {
        /* ITEM-I: SATU titik promosi hard-warning -> violation, dipakai Daily/Monitoring/Weekly/
         * acceptance/release. Kategori Additional HRSG & step sequence WAJIB ikut dipromosikan. */
        /* Kategori startup TIDAK diambil dari warning titipan engine (dapat berasal dari state
         * pra-perbaikan / kandidat yang ditolak). Sumber otoritatifnya adalah revalidasi startup
         * dari dispatch MATERIALIZED di atas — satu entrypoint, satu hasil. */
        if (stripos($w, 'startup sequence') !== false || stripos($w, 'physical start-up') !== false
            || stripos($w, 'Additional HRSG') !== false || stripos($w, 'startup load') !== false
            || stripos($w, 'startup step') !== false) continue;
        elseif (stripos($w, 'minimum runtime') !== false || stripos($w, 'minimum downtime') !== false || stripos($w, 'runtime extension') !== false) $V[] = ['runtime_downtime', $w];
    }

    /* PATCH HANDOFF §3.6 (re-apply): validator final — load <= EFFECTIVE max load per unit per row.
     * Melebihi effective max = hard violation (candidate REJECT). Fix Load yang menang di atas effective
     * max di-cover pola evidence 'exceeds Effective Max Load' (engine sudah menulis warning row-level),
     * sehingga kasus fix-vs-adjustment terklasifikasi VALID-INFEASIBLE, bukan silent PASS. */
    {
        $d3v = $input['data3'] ?? [];
        $unitsEM = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4'];
        $colEM = array_combine($unitsEM, array_map('strtoupper', $unitsEM));
        $colEM['b1'] = 'BB1'; $colEM['b2'] = 'BB2'; $unitsEM[] = 'b1'; $unitsEM[] = 'b2';
        foreach ($unitsEM as $uE) {
            if (!isset($d3v[$uE])) continue;
            $CE = $colEM[$uE];
            for ($i = 0; $i < $n; $i++) {
                $lv = (float)($rows[$i][$CE] ?? 0);
                if ($lv <= 0) continue;
                $emV = pp_effective_maxload($d3v, $model, $uE, $i + 1);
                if ($emV > 0 && $lv > $emV + 0.51)
                    $addV('max_load', sprintf('row %d: %s=%.2f exceeds Effective Max Load %.2f (Max Load Adjustment)', $i + 1, $CE, $lv, $emV), $i);
            }
        }
    }

    $status = empty($V) ? 'PASS' : 'FAIL';
    // VALID-INFEASIBLE: every violation category present has explicit, row-level evidence from the engine
    // itself, not silently produced. last_data_status is NEVER valid-infeasible-able — a violation there is
    // always a genuine bug (a unit cannot honestly claim its own commitment-status constraint is impossible).
    if ($V) {
        $evidence = [
            'gas_quota' => ['GAS SHORTAGE', 'gas quota exceeded', 'insufficient', 'Invalid gas utilization', 'quota under-utilized', 'Maximum Flow MM2100', 'under-absorbed', 'ADDITIONAL LNG REQUIRED'],
            'export_ramp' => ['could not be smoothed'],
            'export_range' => ['PLN export below Range Min', 'IE / must-run limited', 'must-run floor', 'exceeds Range Max', 'PLN Export below Range Min', 'LNG recommendation', 'Export under Effective Range Min', 'ADDITIONAL LNG REQUIRED'],
            'skip_load' => ['Fixed Load vs Skip Load CONFLICT'],
            'fixed_load' => ['Fixed Load vs Skip Load CONFLICT', 'Fixed Load INFEASIBLE'],
            'bus_flow' => ['Bus Flow below minimum'],
            'pgn_rt_min' => ['below Min PGN Flow', 'FLOW PGN REAL TIME'],
            'babelan_ramp' => ['Babelan ramp could not be smoothed'],
            'mm2100_quota' => ['MM2100/KP72 DAILY QUOTA under-absorbed', 'MM2100 quota exceeded', 'MM2100 quota under-utilized'],
            'mm2100_max_flow' => ['Maximum Flow MM2100 dilanggar'],
            'runtime_downtime' => ['cannot maintain minimum runtime', 'minimum downtime violated', 'runtime extension'],
            'commitment_continuous' => ['CONFLICT', 'Stop/Skip schedule'],
            'startup_sequence' => ['physical start-up limitation', 'startup'],
            'max_load' => ['exceeds Effective Max Load'],
            'unit_ramp' => ['could not be smoothed due to technical constraint', 'kept below emergency'],   // re-apply sesi handoff: unit-ramp residual ber-evidence
            'pgn_rt_min' => ['flow pgn real time below min pgn flow'],   // evidence exhaustion alokasi pipe
            'change_over' => ['no anchor', 'needs at least one'],
            'change_over_continuity' => ['no anchor', 'replacement not ready', 'start-before-stop', 'no feasible replacement'],
            'required_units' => ['no anchor', 'cannot start', 'no feasible replacement'],
        ];
        $warningsBlob = strtolower(implode(' ', $warnings));
        $cats = array_unique(array_map(fn($v) => $v[0], $V));
        $allEvidenced = true;
        foreach ($cats as $c) {
            $pats = $evidence[$c] ?? null;
            if (!$pats) { $allEvidenced = false; break; }
            $hit = false; foreach ($pats as $p) if (strpos($warningsBlob, strtolower($p)) !== false) { $hit = true; break; }
            if (!$hit) { $allEvidenced = false; break; }
        }
        if ($allEvidenced) $status = 'VALID-INFEASIBLE';
    }
    $checksum = substr(md5(json_encode($rows)), 0, 12);
    return [
        'status' => $status,
        'violations' => $V,
        'metrics' => [
            'gas_used' => $used, 'gas_quota' => $quota, 'gas_status' => $info['Gas Quota Status'] ?? null,
            'export_under' => $expUnder, 'export_over' => $expOver,
            'export_step_limit_mw' => 35.0, 'ramp_violations' => $rampViol, 'babelan_ramp_violations' => $bbRampViol,
            'busflow_violations' => count($busViol),
            'cost' => (float)($info['Cost Production (USD/MWh)'] ?? 0),
            'warnings_count' => count($warnings),
            'historical_deviations' => $histV,
            'checksum' => $checksum,
        ],
    ];
}

/* pp_release_gate(): the 100x validation RELEASE GATE, integrated into the engine itself (not an external
 * harness). Runs pp_run_simulation() $runs times against the SAME input, validates each run, and only allows
 * release when there is zero FAIL across all runs. Picks the lowest-cost run among the PASS/VALID-INFEASIBLE
 * runs as the representative final output (Constraint First, Cost Second). */
/* ===== PROOF INFEASIBILITY SPINNING RESERVE ====================================================
 * Menentukan apakah defisit reserve bersifat FISIK (tidak dapat ditutup lever apa pun) atau
 * merupakan kegagalan engine. Ini BUKAN relabel: setiap row defisit wajib punya bukti tersendiri.
 *
 * Untuk tiap row yang di bawah requirement dihitung batas ATAS reserve yang masih mungkin:
 *   max_possible = reserve_sekarang
 *                + SUM(eff_max - eff_min) unit eligible yang masih OFF        (dinyalakan di min load)
 *                + SUM(load - eff_min) unit coal online, dibatasi ruang Export (penurunan Babelan)
 * Bukti A : max_possible < requirement            -> tidak mungkin secara fisik
 * Bukti B : menyalakan unit yang diperlukan menuntut gas melebihi sisa kuota
 * Row tanpa salah satu bukti -> BUKAN infeasible; outcome tetap FAIL (kegagalan engine). */
function pp_reserve_infeasibility_proof(array $genRows, array $d3, array $model, array $ieVals,
                                        float $gasQuota, float $gasUsed): array {
    $need = pp_reserve_min($model);
    if ($need <= 0) return ['infeasible' => false, 'reason' => 'requirement 0'];
    $rMinE = (float)($model['pln_export_priority']['range']['min'] ?? 0);
    $gasRoom = max(0.0, $gasQuota - $gasUsed);
    $proofs = []; $unproven = [];
    foreach ($genRows as $i => $g) {
        $row = $i + 1;
        $res = pp_spinning_reserve($g, $d3, $model, $row);
        if ($res >= $need - 1e-6) continue;
        /* KOREKSI DOMAIN: Babelan BUKAN unit reserve, jadi penurunan coal TIDAK menambah reserve.
         * Batas atas reserve pada row ini hanya dari dua sumber:
         *   downPot  = menurunkan load unit eligible yang SUDAH online sampai Effective Min
         *   startPot = menyalakan unit eligible yang masih OFF pada Effective Min (butuh gas) */
        $startPot = 0.0; $gasNeed = 0.0; $offU = [];
        $downPot = 0.0; $onU = [];
        foreach (pp_reserve_units($d3, $model) as $u) {
            if (pp_is_unit_stopped($d3, $model, $u, $row)) continue;
            if (pp_get_fixed_load($model, $u, $row) >= 0) continue;
            $mx = pp_effective_max_load($d3, $model, $u, $row);
            $mn = pp_effective_min_load($d3, $model, $u, $row);
            if ($mx <= 0) continue;
            $load = (float)($g[$u] ?? 0);
            if ($load > 0.51) {
                $slack = max(0.0, $load - $mn);
                if ($slack > 0) { $downPot += $slack; $onU[] = strtoupper($u); }
            } else {
                if ($mx <= $mn) continue;
                $startPot += ($mx - $mn); $offU[] = strtoupper($u);
                $gasNeed += calc_fuel($d3, $u, $mn) / 2.0 * count($genRows);
            }
        }
        $coalPot = 0.0;   /* Babelan tidak pernah menyumbang reserve */
        $maxPossible = $res + $downPot + $startPot;
        if ($maxPossible < $need - 1e-6) {
            $proofs[] = ['row' => $row, 'proof' => 'A', 'reserve_mw' => round($res, 2),
                         'max_possible_mw' => round($maxPossible, 2), 'requirement_mw' => $need,
                         'down_potential_mw' => round($downPot, 2), 'start_potential_mw' => round($startPot, 2),
                         'reason' => 'seluruh lever habis: menurunkan unit online ke Effective Min + menyalakan semua unit eligible tetap tidak mencapai requirement (Babelan tidak menyumbang reserve)'];
        } elseif ($gasNeed > $gasRoom + 1e-9 && $res + $downPot < $need - 1e-6) {
            $proofs[] = ['row' => $row, 'proof' => 'B', 'reserve_mw' => round($res, 2),
                         'gas_extra_required_bbtud' => round($gasNeed, 4), 'gas_room_bbtud' => round($gasRoom, 4),
                         'units_needed' => $offU,
                         'down_potential_mw' => round($downPot, 2), 'units_online_backdown' => $onU,
                         'reason' => 'menurunkan seluruh unit eligible yang online sampai Effective Min tetap tidak mencapai requirement, dan menyalakan unit tambahan menuntut gas melebihi sisa kuota (Babelan tidak menyumbang reserve)'];
        } else {
            $unproven[] = ['row' => $row, 'reserve_mw' => round($res, 2),
                           'max_possible_mw' => round($maxPossible, 2), 'gas_room_bbtud' => round($gasRoom, 4)];
        }
    }
    if (!$proofs && !$unproven) return ['infeasible' => false, 'reason' => 'tidak ada row defisit'];
    return ['infeasible' => empty($unproven), 'rows_under' => count($proofs) + count($unproven),
            'proofs' => array_slice($proofs, 0, 8), 'unproven' => array_slice($unproven, 0, 8),
            'binding_constraint' => $proofs && ($proofs[0]['proof'] === 'B') ? 'GAS QUOTA' : 'CAPACITY',
            'reason' => $unproven ? 'terdapat row defisit TANPA bukti exhaustion -> kegagalan engine, bukan infeasible fisik'
                                  : 'seluruh row defisit memiliki bukti exhaustion'];
}

/* Klasifikasi outcome satu run: PASS / VALID-INFEASIBLE / SEARCH_EXHAUSTED / ERROR / FAIL. */
function pp_classify_outcome(array $input, array $output, array $val): array {
    if (empty($output['data'])) return ['ERROR', ['reason' => 'output kosong / engine tidak menghasilkan dispatch']];
    if (($val['status'] ?? '') === 'PASS' && empty($val['violations'])) return ['PASS', null];
    $types = [];
    foreach (($val['violations'] ?? []) as $v) $types[is_array($v) ? ($v[0] ?? '?') : '?'] = true;
    $types = array_keys($types);
    /* Hanya defisit reserve yang dapat berstatus infeasible fisik. Pelanggaran gas / export /
     * bus flow / startup / min-load adalah kegagalan engine dan TETAP FAIL — tidak ada bypass. */
    if ($types !== ['spinning_reserve']) return ['FAIL', ['violation_types' => $types]];
    $d3 = $input['data3']; $model = $d3['modeling'];
    $UN = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','b1','b2','ge1','ge2','ge3','ge4'];
    $rows = []; $ie = [];
    foreach ($output['data'] as $r) {
        $g = [];
        foreach ($UN as $u) { $c = strtoupper($u); if ($c === 'B1') $c = 'BB1'; if ($c === 'B2') $c = 'BB2';
            $g[$u] = (float)($r[$c] ?? 0); }
        $rows[] = $g; $ie[] = (float)($r['IE'] ?? 0);
    }
    $gasUsed = (float)($output['info']['Total Gas Used (BBTUD)'] ?? 0);
    $gasQuota = (float)($output['info']['Total Gas Quota (BBTUD)'] ?? 0);
    $p = pp_reserve_infeasibility_proof($rows, $d3, $model, $ie, $gasQuota, $gasUsed);
    if (!empty($p['infeasible'])) return ['VALID-INFEASIBLE', $p];
    if (!empty($p['unproven'])) return ['SEARCH_EXHAUSTED', $p];
    return ['FAIL', $p];
}

function pp_release_gate(array $input, int $runs = 100): array {
    $inputChecksum = md5(json_encode($input));
    $results = [];
    $best = null; $bestCost = INF;
    for ($i = 1; $i <= $runs; $i++) {
        $output = pp_run_simulation($input);
        $val = pp_validate_hard_constraints($input, $output);
        /* OUTCOME LIMA KATEGORI: PASS / VALID-INFEASIBLE / SEARCH_EXHAUSTED / ERROR / FAIL.
         * VALID-INFEASIBLE hanya diberikan bila SETIAP row defisit punya bukti exhaustion formal
         * (pp_reserve_infeasibility_proof) — bukan sekadar relabel status FAIL. */
        [$outcome, $proof] = pp_classify_outcome($input, $output, $val);
        $results[] = ['run' => $i, 'status' => $val['status'], 'outcome' => $outcome, 'proof' => $proof,
                      'metrics' => $val['metrics'], 'violations' => $val['violations']];
        if ($val['status'] !== 'FAIL') {
            $c = $val['metrics']['cost'];
            if ($best === null || $c < $bestCost) { $best = $output; $bestCost = $c; }
        }
    }
    $pass = 0; $fail = 0; $vi = 0; $firstFail = null;
    $se = 0; $er = 0; $firstProof = null;
    foreach ($results as $r) {
        switch ($r['outcome'] ?? $r['status']) {
            case 'PASS':             $pass++; break;
            case 'VALID-INFEASIBLE': $vi++; if ($firstProof === null) $firstProof = $r['proof']; break;
            case 'SEARCH_EXHAUSTED': $se++;  if ($firstFail === null) $firstFail = $r; break;
            case 'ERROR':            $er++;  if ($firstFail === null) $firstFail = $r; break;
            default:                 $fail++; if ($firstFail === null) $firstFail = $r;
        }
    }
    $gasVals = array_map(fn($r) => $r['metrics']['gas_used'], $results);
    $costVals = array_map(fn($r) => $r['metrics']['cost'], $results);
    $checksums = array_values(array_unique(array_map(fn($r) => $r['metrics']['checksum'], $results)));
    /* VALID-INFEASIBLE yang formal, deterministic, dan bersertifikat BUKAN kegagalan engine:
     * outcome itu tidak memblokir release. SEARCH_EXHAUSTED dan ERROR tetap memblokir. */
    $releaseAllowed = ($fail === 0) && ($se === 0) && ($er === 0) && ($runs > 0)
                      && (count($checksums) === 1);
    $summary = [
        '100x_validation_required' => true,
        '100x_validation_pass' => $releaseAllowed,
        'validation_run_count' => $runs,
        'release_allowed' => $releaseAllowed,
        'input_checksum' => $inputChecksum,
        'pass_count' => $pass, 'fail_count' => $fail, 'valid_infeasible_count' => $vi,
        'search_exhausted_count' => $se, 'error_count' => $er, 'infeasibility_proof' => $firstProof,
        'gas_used_min' => $gasVals ? min($gasVals) : null, 'gas_used_max' => $gasVals ? max($gasVals) : null,
        'gas_used_avg' => $gasVals ? round(array_sum($gasVals) / count($gasVals), 6) : null,
        'cost_min' => $costVals ? min($costVals) : null, 'cost_max' => $costVals ? max($costVals) : null,
        'cost_avg' => $costVals ? round(array_sum($costVals) / count($costVals), 6) : null,
        'unique_output_checksums' => $checksums, 'unique_checksum_count' => count($checksums),
        'first_fail' => $firstFail,
        'status' => $releaseAllowed ? 'RELEASED' : 'NOT_RELEASED_VALIDATION_FAILED',
    ];
    return ['summary' => $summary, 'final_output' => $best ?? ($results ? $output : null), 'all_results' => $results];
}

/* Startup gas penalty (Sec.16), computed exactly as worker02.php's authoritative total does: each OFF->ON
 * start consumes extra gas for firing/purge/warming — GTG 1-6 = 0.08 BBTUD/start, GTG 7-10 = 0.20 BBTUD/start.
 * Late gas-trim passes inside pp_shape_final_dispatch need this to target the TRUE final total (which is what
 * gets reported and quota-checked), not just the calc_fuel sum. */
function pp_startup_gas_penalty(array $genRows, array $model, bool $jababekaOnly = false): float {
    /* Master Audit Bagian F (isolasi KP72): $jababekaOnly=true mengecualikan G10 — start-up gas G10
     * berasal dari gas KP72/MM2100, jadi TIDAK boleh memakan budget/quota Jababeka di dalam shaper. */
    /* KEPUTUSAN DOMAIN FINAL (PROMPT ZERO PENALTY §2): STARTUP GAS PENALTY = 0 BBTUD.
     * Startup unit (termasuk BBLN1/BBLN2, yang memang tidak pernah dihitung di sini) TIDAK
     * menambah PGN/PEP/LNG/KP72/MM2100/Akasia. Normal fuel gas berbasis load aktual tetap
     * dihitung di calc_fuel. Default fallback lama 0.08/0.20 DIHAPUS -> 0.0; override input
     * tetap dibaca via ?? (nilai 0 eksplisit tidak tertimpa karena null-coalescing, bukan falsy). */
    /* OPT-P1 — kedua penalti bernilai 0,0 mutlak (keputusan domain di atas), sehingga hasilnya
     * selalu 0,0 berapa pun jumlah event start-up. Penghitungan event 48 row x 9 unit yang dahulu
     * dijalankan di setiap evaluasi gas (puluhan ribu kali per run) tidak mengubah apa pun. */
    return 0.0;
    $spen = $model['startup_penalty'] ?? [];
    $pen16 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
    $pen710 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
    $n = count($genRows); $ev16 = 0; $ev710 = 0;
    foreach (['g1','g2','g3','g4','g5','g6'] as $u) {
        for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $ev16++;
    }
    $u710 = $jababekaOnly ? ['g7','g8','g9'] : ['g7','g8','g9','g10'];
    foreach ($u710 as $u) {
        for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $ev710++;
    }
    return $ev16 * $pen16 + $ev710 * $pen710;
}

function pp_validate_startup(array $genRows, array $d3, array $model): array {
    $w = []; $n = count($genRows);
    $gtgs = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10'];
    foreach ($gtgs as $u) {
        if (!isset($d3[$u])) continue;
        $mr = (float)($d3[$u]['min_scload'] ?? 5);

        /* 5 MW / 15 MW are startup-only values (Revisi Sec.5-7): they may appear
         * ONLY while the unit is inside a genuine OFF->ON startup ramp (Additional
         * HRSG for HRSG 1-6, or STG 1/2 Cold/Warm/Hot). Anywhere else -> warning. */
        /* SATU SUMBER KEBENARAN (WEEKLY §5): panjang ramp yang sah dihitung dari pp_start_sequence()
         * PADA ROW START unit tsb — helper yang SAMA dipakai engine untuk MENULIS sequence. Sebelumnya
         * validator memakai pp_startup_caps(STG, mode) saja, sehingga konteks "STG sudah running ->
         * Additional HRSG [5,15]" salah divonis (mode Hot -> window 0/1) dan step 15 MW yang SAH
         * dilaporkan sebagai violation. Formula sequence TIDAK diubah; hanya sumber panjangnya
         * disatukan agar generator & validator tidak pernah berbeda. */
        $seq = 0;
        for ($r = 0; $r < $n; $r++) {
            $cur  = $genRows[$r][$u] ?? 0;
            $prev = $r ? ($genRows[$r-1][$u] ?? 0) : 0;
            if ($cur < 1) { $seq = 0; continue; }
            if ($prev < 1) {
                /* Window startup hanya terbuka pada transisi OFF->ON yang NYATA di dalam hari ini.
                 * Unit yang sudah Running sejak kemarin (row 0 tanpa row sebelumnya) BUKAN startup. */
                $__contYesterday = ($r === 0 &&
                    strtolower((string)($model['unit_last_data_status'][strtoupper($u)] ?? '')) === 'running');
                $__seqArr = $__contYesterday ? [] : pp_start_sequence($d3, $model, $u, $genRows, $r);
                $__seqIdx = 0;
                $seq = $__contYesterday ? 0 : max(1, count($__seqArr));
            }
            /* VALIDASI STEP EKSAK (KUNCI DOMAIN Additional HRSG §2): setiap slot DI DALAM startup
             * window wajib SAMA PERSIS dengan langkah sequence-nya (5 MW lalu 15 MW untuk GTG1-6).
             * Sebelumnya validator hanya menolak nilai 5/15 di LUAR window, sehingga 12 MW pada slot
             * kedua lolos. EPS kecil hanya untuk floating point. */
            if ($seq > 0 && !empty($__seqArr) && isset($__seqArr[$__seqIdx])) {
                $expStep = (float)$__seqArr[$__seqIdx];
                if (abs((float)$cur - $expStep) > PP_STARTUP_SEQUENCE_EPSILON
                    && !pp_is_unit_stopped($d3, $model, $u, $r + 1)
                    && pp_get_fixed_load($model, $u, $r + 1) < 0)
                    $w[] = sprintf('Invalid startup sequence step: %s slot %d = %.2f MW, expected %.2f MW (startup sequence %s).',
                        strtoupper($u), $__seqIdx + 1, (float)$cur, $expStep, json_encode($__seqArr));
                $__seqIdx++;
            }
            $inRamp = ($seq > 0);
            if ($seq > 0) $seq--;
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
            $is5 = abs($cur - 5) < 0.6; $is15 = abs($cur - 15) < 0.6;
            if (($is5 || $is15) && !$inRamp)
                $w[] = sprintf('Invalid %d MW startup load: %s has %.0f MW at row %d, but unit is not in Additional HRSG or STG 1/2 startup sequence.',
                    $is5 ? 5 : 15, strtoupper($u), $cur, $r + 1);
            if ($is5 && $prev > 15.6 && !$inRamp)
                $w[] = sprintf('Invalid load drop: %s dropped from %.0f MW to 5 MW without valid startup sequence.',
                    strtoupper($u), $prev);
        }

        for ($r = 0; $r + 2 < $n; $r++) {
            $a = $genRows[$r][$u] ?? 0; $b = $genRows[$r+1][$u] ?? 0; $c = $genRows[$r+2][$u] ?? 0;
            if ($a >= $mr && $b < 1 && $c >= $mr && !pp_is_unit_stopped($d3, $model, $u, $r + 2))
                $w[] = sprintf('Invalid startup pattern: %s %.0f MW -> 0 MW -> %.0f MW at rows %d-%d.',
                    strtoupper($u), $a, $c, $r + 1, $r + 3);
        }
        $stg = pp_gtg_to_stg($d3, $u);
        if (!$stg) continue;
        $mcc = (float)($d3[$u]['min_ccload'] ?? $mr);
        $blockGtgs = [];
        foreach ($gtgs as $og) if ($og !== $u && pp_gtg_to_stg($d3, $og) === $stg) $blockGtgs[] = $og;
        for ($r = 1; $r < $n; $r++) {
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
            $prev = $genRows[$r-1][$u] ?? 0; $cur = $genRows[$r][$u] ?? 0;
            $stgPrev = $genRows[$r-1][$stg] ?? 0;
            // invalid CC load drop
            if ($prev >= $mcc - 1e-6 && $cur >= 1 && $cur < $mcc - 1e-6 && ($genRows[$r][$stg] ?? 0) > 0)
                $w[] = sprintf('Invalid CC load drop: %s was running in combined cycle and dropped to %.0f MW without valid constraint.',
                    strtoupper($u), $cur);
            // additional-HRSG start from 0 while block STG already loaded & another GTG running
            if ($prev < 1 && $cur >= 1 && $stgPrev > 0) {
                $other = false;
                foreach ($blockGtgs as $og) if (($genRows[$r-1][$og] ?? 0) >= 1) { $other = true; break; }
                if ($other && $cur > pp_additional_hrsg_caps($u)[0] + 1e-6)
                    $w[] = sprintf('Invalid Additional HRSG startup: %s started from 0 MW while %s was already loaded. Unit must follow Additional HRSG sequence.',
                        strtoupper($u), strtoupper($stg));
            }
        }
    }
    return $w;
}

/* =====================================================================
 * Minimum runtime / downtime enforcement (Revisi Lanjutan Sec.1)
 * Prevents close start-stop cycling on GTG and STG units. Operates on the
 * already startup-constrained dispatch and is invoked from inside
 * pp_apply_startup_constraints() so the gas-targeting search sees the final
 * (post-enforcement) dispatch. 1 row = 0.5 h, so rows = hours * 2.
 * ===================================================================== */
function pp_runtime_limits(array $model): array {
    // Revisi: granular minimum runtime/downtime from UI (runtime_downtime), with
    // fallback to the older min_run_down block, then to conservative defaults.
    $rd  = $model['runtime_downtime'] ?? [];
    $h   = $model['min_run_down'] ?? [];
    $pick = function (string $rkey, string $dkey, string $cls, float $dr, float $dd) use ($rd, $h): array {
        $run  = (float)($rd[$rkey] ?? $h[$cls]['run']  ?? $dr);
        $down = (float)($rd[$dkey] ?? $h[$cls]['down'] ?? $dd);
        return ['run_rows' => (int)round($run * 2), 'down_rows' => (int)round($down * 2),
                'run_h' => $run, 'down_h' => $down];
    };
    return [
        'gtg_small'       => $pick('minimum_runtime_gtg_small',       'minimum_downtime_gtg_small',       'gtg',             4, 2),
        'gtg_large'       => $pick('minimum_runtime_gtg_large',       'minimum_downtime_gtg_large',       'gtg_large',       6, 4),
        'combined_cycle'  => $pick('minimum_runtime_combined_cycle',  'minimum_downtime_combined_cycle',  'combined_cycle',  6, 3),
        'stg'             => $pick('minimum_runtime_stg',             'minimum_downtime_stg',             'stg',             6, 3),
        // legacy alias used by older call sites
        'gtg'             => $pick('minimum_runtime_gtg_small',       'minimum_downtime_gtg_small',       'gtg',             4, 2),
        'additional_hrsg' => $pick('minimum_runtime_gtg_small',       'minimum_downtime_gtg_small',       'additional_hrsg', 4, 2),
    ];
}

/* Map a unit id to its runtime/downtime class (Revisi). Large units (G7-G10)
 * get the stricter large limits so they don't stop-start within the day; the
 * CC-capable small GTGs (G1-G6, which feed an STG) use the combined-cycle
 * limits; STGs use the STG limits. */
function pp_runtime_class(string $u, array $d3): string {
    if (in_array($u, ['s1','s2','s3'], true)) return 'stg';
    if (in_array($u, ['g7','g8','g9','g10'], true)) return 'gtg_large';
    if (pp_gtg_to_stg($d3, $u)) return 'combined_cycle';      // G1-G6 are CC-capable
    return 'gtg_small';
}

/* Split a unit's 48-row dispatch into ON / OFF runs. */
function pp_segments(array $genRows, string $u): array {
    $n = count($genRows); $segs = []; $i = 0;
    while ($i < $n) {
        $on = (($genRows[$i][$u] ?? 0) >= 1); $j = $i + 1;
        while ($j < $n && ((($genRows[$j][$u] ?? 0) >= 1) === $on)) $j++;
        $segs[] = ['on' => $on, 'a' => $i, 'b' => $j];
        $i = $j;
    }
    return $segs;
}

function pp_apply_runtime_constraints(array &$genRows, array $d3, array $model): array {
    $warnSet = []; $locks = [];
    $n   = count($genRows);
    $lim = pp_runtime_limits($model);
    $units = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3'];
    $touched = 0;

    /* Hold a unit online across rows [a,b). For an STG this holds its feeding
     * GTGs online (the STG load is derived from them by recompute). */
    $hold = function (string $u, int $a, int $b, bool $isStg, string $label)
            use (&$genRows, &$locks, &$touched, $d3, $model, $n): void {
        $targets = $isStg ? ($d3[$u]['gtg'] ?? []) : [$u];
        if ($isStg && $a > 0) {
            $pre = [];
            foreach ($targets as $gg) if ((float)($genRows[$a-1][$gg] ?? 0) >= 1) $pre[] = $gg;
            if ($pre) $targets = $pre;                       // prefer GTGs already feeding
        }
        foreach ($targets as $g) {
            $gMin   = (float)($d3[$g]['min_scload'] ?? 5);
            $before = $a > 0 ? (float)($genRows[$a-1][$g] ?? 0) : 0;
            $after  = $b < $n ? (float)($genRows[$b][$g] ?? 0) : 0;
            $ref = [];
            if ($before >= 1) $ref[] = $before;
            if ($after  >= 1) $ref[] = $after;
            $fill = $ref ? max($gMin, min($ref)) : $gMin;
            for ($k = $a; $k < $b; $k++) {
                if (pp_is_unit_stopped($d3, $model, $g, $k + 1)) continue;   // respect explicit stop
                if (($genRows[$k][$g] ?? 0) < 1) { $genRows[$k][$g] = $fill; $locks[$g][$k] = $label; $touched++; }
            }
        }
        for ($k = $a; $k < $b; $k++) {
            pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1);
            if ($isStg) $locks[$u][$k] = $label;
        }
    };

    // run the per-unit pass repeatedly until stable (holds on GTGs can shorten
    // an STG gap into a newly-mergeable one, and vice-versa)
    for ($iter = 0; $iter < 6; $iter++) {
        $before = $touched;
        foreach ($units as $u) {
            if (!isset($d3[$u])) continue;
            $isStg   = in_array($u, ['s1','s2','s3'], true);
            $cls     = pp_runtime_class($u, $d3);            // Revisi: granular class
            $minRun  = $lim[$cls]['run_rows'];
            $minDown = $lim[$cls]['down_rows'];

            $stoppedAt = function (int $row1) use ($d3, $model, $u, $isStg): bool {
                if ($isStg) {
                    $g = $d3[$u]['gtg'] ?? [];
                    if (!$g) return false;
                    foreach ($g as $gg) if (!pp_is_unit_stopped($d3, $model, $gg, $row1)) return false;
                    return true;                              // STG stopped only if all feeders stopped
                }
                return pp_is_unit_stopped($d3, $model, $u, $row1);
            };

            /* (A) minimum downtime: merge short interior OFF gaps. */
            foreach (pp_segments($genRows, $u) as $sg) {
                if ($sg['on'] || $sg['a'] === 0 || $sg['b'] >= $n) continue;
                $gap = $sg['b'] - $sg['a'];
                if ($gap >= $minDown) continue;
                $explicit = false;
                for ($k = $sg['a']; $k < $sg['b']; $k++) if ($stoppedAt($k + 1)) { $explicit = true; break; }
                if ($explicit) {
                    $warnSet[sprintf('%s stopped at row %d by explicit user rule, restarted at row %d (gap %.1f h). Allowed by user stop/trip.',
                        strtoupper($u), $sg['a'] + 1, $sg['b'] + 1, $gap / 2)] = true;
                    continue;
                }
                $hold($u, $sg['a'], $sg['b'], $isStg, 'MIN_DOWNTIME_LOCKED');
                $warnSet[sprintf('Invalid start-stop pattern: %s stopped at row %d after running, then restarted at row %d (gap %.1f h < min downtime %.1f h). Held online to honour minimum downtime.',
                    strtoupper($u), $sg['a'] + 1, $sg['b'] + 1, $gap / 2, $minDown / 2)] = true;
            }

            /* (B) minimum runtime: extend short ON segments forward. */
            foreach (pp_segments($genRows, $u) as $sg) {
                if (!$sg['on']) continue;
                $runLen = $sg['b'] - $sg['a'];
                if ($runLen >= $minRun || $sg['b'] >= $n) continue;
                if ($stoppedAt($sg['b'])) {
                    $warnSet[sprintf('Unit %s stopped at row %d by user rule before minimum runtime %.1f h reached (ran %.1f h).',
                        strtoupper($u), $sg['b'] + 1, $minRun / 2, $runLen / 2)] = true;
                    continue;
                }
                $k = $sg['b']; $len = $runLen;
                while ($k < $n && $len < $minRun && (($genRows[$k][$u] ?? 0) < 1) && !$stoppedAt($k + 1)) { $len++; $k++; }
                if ($k > $sg['b']) $hold($u, $sg['b'], $k, $isStg, 'MIN_RUNTIME_LOCKED');
                if ($len < $minRun)
                    $warnSet[sprintf('Unit %s cannot maintain minimum runtime %.1f h (ran only %.1f h from row %d) due to horizon / explicit stop limit.',
                        strtoupper($u), $minRun / 2, $len / 2, $sg['a'] + 1)] = true;
            }
        }
        if ($touched === $before) break;                    // stable
    }
    return ['warnings' => array_keys($warnSet), 'locks' => $locks];
}

/* =========================================================================
 *  pp_shape_final_dispatch (Revisi Lanjutan) — final dispatch shaper.
 *
 *  Replaces the resolved gen rows with a feasible combined-cycle dispatch that
 *  simultaneously honours every hard constraint:
 *    - Export PLN in [Range Min, Range Max] every row (0 under / 0 over);
 *    - Export ramp <= 20 MW/30min (default) and never > 35 MW;
 *    - Babelan = ramped base-load PLTU (demand-following, <= 5 MW/30min per unit,
 *      NOT strict-flat — strict-flat is infeasible vs the 61 BBTUD gas quota);
 *    - G8 never stops; G8/G9 never parked below the 75 MW CC-min while in CC mode
 *      (G9 may be OFF overnight, restarted with the 40/50/60/70 Additional-HRSG
 *      ramp so total gas can reach — not exceed — the 61 BBTUD quota);
 *    - Additional-HRSG / STG start-up sequences valid (release-time gated).
 *
 *  Gas levers (Revisi V6: calc_fuel makes EVERY GTG G1-G10 + GE1-4 load-dependent —
 *  raising G8/G9/G10 MW raises their gas too; the G1-G6 loads also move
 *  gas). The shaper fixes the gas-driving loads (G3 load, G9 on-rows) to hit the
 *  quota, then shapes export with the G8/G9 MW + Babelan levers (Babelan is gas-free; G8/G9 fuel is load-dependent and reconciled by the final gas-cap/up-dispatch).
 * ========================================================================= */
/* OPT-S1 — jumlah bahan bakar harian G1-G9 dengan cache per (row, unit). Urutan penjumlahan identik
 * dengan loop asli (row naik, unit g1..g9), dan calc_fuel hanya dipanggil ulang bila beban sel itu
 * berubah (perbandingan === atas nilai yang sama persis), sehingga hasil identik bit-per-bit. */
function pp_fuel_sum_g19(array $genRows, array $d3, int $n, array &$fc): float {
    static $U = ['g1','g2','g3','g4','g5','g6','g7','g8','g9'];
    /* Awalan identik: selama row 0..k-1 sama persis (===) dengan saat terakhir dijumlah, total
     * berjalan sebelum row k juga sama persis — urutan penambahan floating-point tidak berubah. */
    $k = 0;
    if (isset($fc['n']) && $fc['n'] === $n) {
        $rowsC = $fc['rows'];
        while ($k < $n && isset($rowsC[$k]) && $rowsC[$k] === $genRows[$k]) $k++;
        if ($k === $n) return $fc['P'][$n];
    } else { $fc = ['n' => $n, 'rows' => [], 'f' => [], 'P' => [0 => 0.0], 'cell' => []]; }
    $g = $fc['P'][$k];
    for ($r = $k; $r < $n; $r++) {
        $fc['P'][$r] = $g;
        $row = $genRows[$r];
        if (isset($fc['rows'][$r]) && $fc['rows'][$r] === $row) {
            foreach ($fc['f'][$r] as $f) $g += $f;
            continue;
        }
        $fr = [];
        foreach ($U as $u) {
            $l = $row[$u] ?? 0;
            $c = $fc['cell'][$r][$u] ?? null;
            if ($c !== null && $c[0] === $l) $f = $c[1];
            else { $f = calc_fuel($d3, $u, $l); $fc['cell'][$r][$u] = [$l, $f]; }
            $fr[] = $f;
            $g += $f;
        }
        $fc['rows'][$r] = $row; $fc['f'][$r] = $fr;
    }
    $fc['P'][$n] = $g;
    return $g;
}
function pp_shape_final_dispatch(array &$genRows, array $d3, array $model, array $ieVals, float $quota, array $actualRows, array &$warnings): void {
    $n = count($genRows);
    $__FC = [];                                  // OPT-S1: cache bahan bakar per sel untuk pass ini
    if ($n === 0) return;
    $bbRamp = pp_babelan_ramp_limit($model);   // §4 single source of truth (Babelan 10 MW/30min per unit)
    $pe   = $model['pln_export_priority'] ?? [];
    $rng  = $pe['range'] ?? [];
    $rMin = (float)($rng['min'] ?? 0);
    $rMax = (float)($rng['max'] ?? INF);
    if (!is_finite($rMax)) return;
    $busUnit = $model['bus_unit'] ?? [];
    $busMin  = (float)($model['busflow_min'] ?? 0);
    // per-row effective Range Min/Max: main range, overridden by additional Range PLN rules on their periods
    $rMinR = array_fill(0, $n, $rMin); $rMaxR = array_fill(0, $n, $rMax);
    foreach (($pe['range_rules'] ?? []) as $rr) {
        if (!is_array($rr)) continue;
        $s = max(1, (int)($rr['start'] ?? 1)); $e2 = min($n, (int)($rr['stop'] ?? $n));
        if ($s > $e2) continue;
        $lo = max($rMin, (float)($rr['min'] ?? $rMin)); $hi = min($rMax, (float)($rr['max'] ?? $rMax));
        for ($r = $s - 1; $r <= $e2 - 1; $r++) { $rMinR[$r] = $lo; $rMaxR[$r] = $hi; }
    }
    /* PROMPT 3JUL/7JUL §3.1/§9 — LONG-HORIZON RAMP LADDER: export ramp <= 30 MW/30min adalah
     * hard constraint, maka utk mencapai window Range Min tinggi (mis. 100 MW mulai 08:30),
     * row-row SEBELUM dan SESUDAH window wajib membentuk tangga (>= 70, >= 40, ...). Floor
     * per-row dipropagasi dua arah sampai fixpoint: F[r] >= max(F[r-1], F[r+1]) - 30. Dengan
     * menaikkan $rMinR sendiri, SEMUA pass existing (floor repair, lever start, smoothing,
     * validator guard) otomatis menghormati tangga — perencanaan horizon penuh, bukan repair
     * row-per-row. Floor tangga di-clamp ke Range Max row ybs (window spacing infeasible akan
     * muncul sbg evidence floor>max di validator, bukan disembunyikan). */
    /* PROMPT 3JUL/7JUL T10 — SEED TANGGA DARI ACTUAL (Monitoring): export row locked adalah
     * KONSTANTA; row future bersebelahan wajib berada dlm jangkauan ramp thd nilai locked
     * (floor >= actual-30, ceiling <= actual+30). Propagasi tangga di bawah menyebarkannya
     * ke row-row berikutnya (actual-60, ...), shg boundary locked->future tersambung mulus. */
    $actExpS = [];
    foreach ((array)(($model['actual_data']['rows'] ?? [])) as $arS) {
        $riS = (int)($arS['row'] ?? 0) - 1;
        if ($riS >= 0 && $riS < $n && isset($arS['export']) && is_numeric($arS['export'])) $actExpS[$riS] = (float)$arS['export'];
    }
    foreach ($actExpS as $riS => $evS) {
        foreach ([$riS - 1, $riS + 1] as $nbS) {
            if ($nbS < 0 || $nbS >= $n || isset($actExpS[$nbS])) continue;
            $rMinR[$nbS] = max($rMinR[$nbS], min($evS - 30.0, $rMaxR[$nbS]));
            $rMaxR[$nbS] = min($rMaxR[$nbS], max($evS + 30.0, $rMinR[$nbS]));
        }
    }
    for ($lad = 0; $lad < $n; $lad++) {
        $chLad = false;
        for ($r = 0; $r < $n; $r++) {
            $f = $rMinR[$r];
            if ($r > 0)      $f = max($f, $rMinR[$r - 1] - 30.0);
            if ($r < $n - 1) $f = max($f, $rMinR[$r + 1] - 30.0);
            $f = min($f, $rMaxR[$r]);                              // jangan melebihi Range Max row ini
            if ($f > $rMinR[$r] + 1e-9) { $rMinR[$r] = $f; $chLad = true; }
        }
        if (!$chLad) break;
    }
    $UNITS = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'];
    $INDEP = ['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'];
    $lastStatus = [];
    foreach (($model['unit_last_data_status'] ?? []) as $k => $v) $lastStatus[strtolower($k)] = strtolower((string)$v);
    if (!pp_unit_present($d3, 'g8') || !pp_unit_present($d3, 'g9') || !pp_unit_present($d3, 'g3')) return;
    /* ===== JENDELA LEVEL BERSAMA G8=G9 — PER ROW DAN COUPLED (D-1) ==============================
     *  Sebelumnya jendela level bersama dibaca SEBAGAI SKALAR DAN HANYA DARI G8:
     *      $GMIN = $d3['g8']['min_ccload'];  $GMAX = $d3['g8']['max_load'];
     *  Dua cacat. (a) G9 tidak pernah dilihat: batas G9 yang lebih ketat — termasuk kotak legal
     *  hasil pemilihan cabang Skip Load — tidak membatasi level bersama, sehingga level yang sah
     *  bagi G8 dapat menaruh G9 di dalam forbidden band-nya. (b) Skalar: aturan per row
     *  (max_load_rules / min_load_rules, Unit Stop, Fix Load) tidak terlihat sama sekali.
     *  Sekarang jendela dihitung PER ROW sebagai IRISAN batas kedua unit (instruksi §3.3): batas
     *  bawah = max(floor G8, floor G9), batas atas = min(cap G8, cap G9). Seluruh ~25 titik yang
     *  memilih atau mengubah level bersama memakai jendela ini, jadi tidak ada satu pun pass yang
     *  dapat memilih level di luar kotak legal. $GMIN/$GMAX skalar dipertahankan sebagai selubung
     *  hari (dipakai hanya di titik yang memang tidak punya konteks row, mis. bisection global). */
    $__gwLo = []; $__gwHi = [];
    for ($rw = 1; $rw <= $n; $rw++) {
        $lo8 = pp_effective_min_load($d3, $model, 'g8', $rw); $lo9 = pp_effective_min_load($d3, $model, 'g9', $rw);
        $hi8 = pp_effective_max_load($d3, $model, 'g8', $rw); $hi9 = pp_effective_max_load($d3, $model, 'g9', $rw);
        if ($hi8 <= 0) $hi8 = (float)($d3['g8']['max_load'] ?? 110);
        if ($hi9 <= 0) $hi9 = (float)($d3['g9']['max_load'] ?? 110);
        /* Unit yang berhenti di row ini tidak boleh mempersempit jendela unit yang masih jalan. */
        $st8 = pp_is_unit_stopped($d3, $model, 'g8', $rw); $st9 = pp_is_unit_stopped($d3, $model, 'g9', $rw);
        $lo = max($st8 ? -INF : $lo8, $st9 ? -INF : $lo9);
        $hi = min($st8 ?  INF : $hi8, $st9 ?  INF : $hi9);
        if (!is_finite($lo)) $lo = 0.0;
        if (!is_finite($hi)) $hi = max($hi8, $hi9);
        if ($hi < $lo) $hi = $lo;                                  // konflik dilaporkan validator, bukan disamarkan
        $__gwLo[$rw] = $lo; $__gwHi[$rw] = $hi;
    }
    /** Batas bawah level bersama G8=G9 pada row 0-based. */
    $GLO = function (int $r0) use ($__gwLo, $n): float {
        $k = max(1, min($n, $r0 + 1)); return (float)($__gwLo[$k] ?? 0.0);
    };
    /** Batas atas level bersama G8=G9 pada row 0-based. */
    $GHI = function (int $r0) use ($__gwHi, $n): float {
        $k = max(1, min($n, $r0 + 1)); return (float)($__gwHi[$k] ?? 0.0);
    };
    $GMIN = $__gwLo ? min($__gwLo) : (float)($d3['g8']['min_ccload'] ?? 65);
    $GMAX = $__gwHi ? max($__gwHi) : (float)($d3['g8']['max_load'] ?? 110);
    $g3mcc = (float)($d3['g3']['min_ccload'] ?? 20);
    $g3max = (float)($d3['g3']['max_load'] ?? 31);
    $b1max = (float)($d3['b1']['max_load'] ?? 120);
    $b2max = (float)($d3['b2']['max_load'] ?? 120);
    $bbMaxTotal = $b1max + $b2max;                                    // Babelan full-load target (e.g. 240)

    /* ================================================================================================
     * PIPELINE STEP — Fixed Load Lock (MASTER FABLE5 Sec.5.4 item 2: "Fixed Load diterapkan setelah
     * bisection sehingga quota meleset"). Applied EARLY, before ANY gas-quota tuning/escalation, so every
     * later stage (Babelan cap sizing, vLo/vHi windows, gas bisection, top-up, down-dispatch, ramp
     * smoothing, BusFlow repair) treats a fixed row as a CONSTANT to work around and account for, not a
     * free variable it can silently drift away from. Every escalation/repair loop below checks
     * $isFixed($u,$r) and skips that (unit,row) — the fixed operator value is never overwritten. ------- */
    $fixedVal = [];   // $fixedVal[$u][$r] = value, only for (unit,row) with an active unit_fix_load rule
    /* STRESS #70: Fixed Load berlaku utk SEMUA unit — termasuk Babelan, G7, G10, GE. */
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'] as $fu) {
        if (!pp_unit_present($d3, $fu)) continue;
        for ($r = 0; $r < $n; $r++) {
            $fx = pp_get_fixed_load($model, $fu, $r + 1);
            if ($fx >= 0) $fixedVal[$fu][$r] = $fx;
        }
    }
    $isFixed = function (string $u, int $r) use (&$fixedVal): bool { return isset($fixedVal[$u][$r]); };

    /* PROMPT STOP_EXPORT_LNG §1.2 — penolan stop DI AWAL shaper: unit dalam Unit Stop Schedule
     * dinolkan sebelum seluruh pass berjalan, sehingga advisory/eskalasi melihat kapasitas nyata
     * dan menyalakan pengganti sesuai Block/Unit Priority. (Re-assert akhir tetap sbg jaring pengaman.) */
    /* BABELAN STOP-WINDOW RAMP TAPER (STRESS #57): BB ramp <= 5 MW/30min per unit — menuju window
     * stop, beban harus DITURUNKAN BERJENJANG hingga 0 tepat di awal window, dan naik berjenjang
     * setelah window. Cap per row = jarak_ke_window x bbRamp (helper). */
    foreach (['b1', 'b2'] as $uB) {
        if (!isset($d3[$uB])) continue;
        $stopRow = [];
        for ($r = 0; $r < $n; $r++) $stopRow[$r] = pp_is_unit_stopped($d3, $model, $uB, $r + 1);
        if (!in_array(true, $stopRow, true) || !in_array(false, $stopRow, true)) continue;   // tanpa window parsial
        for ($r = 0; $r < $n; $r++) {
            if ($stopRow[$r]) continue;
            $dNear = PHP_INT_MAX;
            for ($k = 0; $k < $n; $k++) if ($stopRow[$k]) $dNear = min($dNear, abs($k - $r));
            $cap = $bbRamp * $dNear;
            if ((float)($genRows[$r][$uB] ?? 0) > $cap + 1e-9) {
                $genRows[$r][$uB] = $cap;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }

    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'] as $uZ0) {
        if (!isset($d3[$uZ0])) continue;
        for ($r = 0; $r < $n; $r++) {
            if ((float)($genRows[$r][$uZ0] ?? 0) > 0.0 && pp_is_unit_stopped($d3, $model, $uZ0, $r + 1)) {
                $genRows[$r][$uZ0] = 0.0;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }

    /* Block/Unit Priority-aware lever selection (MASTER FABLE5 Sec.4.2/4.4/4.6): this shaping pass previously
     * always treated G3 (feeding S1) as a default-on baseline lever for export/gas-quota purposes, regardless
     * of whether the G3/G4/G6/S1 block was actually Required. G1/G2/G5 (feeding S2) — even when that block IS
     * Required — were never used as a lever here at all (only floored afterwards). Fix: detect which block is
     * Required from block_priority and let G1 (already running, Cannot-Stop) then G2 (Required, mode
     * based_on_sim) absorb export/gas-quota headroom BEFORE a non-required G3/G4/G6/S1 is ever started. */
    $g1mcc = (float)($d3['g1']['min_ccload'] ?? $d3['g1']['min_scload'] ?? 20);
    $g1max = (float)($d3['g1']['max_load'] ?? 31);
    $g2mcc = (float)($d3['g2']['min_ccload'] ?? $d3['g2']['min_scload'] ?? 20);
    $g2max = (float)($d3['g2']['max_load'] ?? 31);
    $csL0  = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
    $g1cs  = in_array('g1', $csL0, true);
    $blockReqOfUnit = function (string $unit) use ($model): bool {
        foreach (($model['block_priority'] ?? []) as $blk) {
            if (in_array($unit, $blk, true)) return in_array('required', $blk, true);
        }
        return false;
    };
    // Block 2 (g1/g2/g5/s2) Required AND takes priority over Block 1 (g3/g4/g6/s1) precisely when Block 1 is
    // NOT itself Required (block_priority order already tells us which one leads when both are Required).
    /* PROMPT REUPLOAD §2: lever Block-2 aktif ketika BLOKNYA required — tidak peduli blok lain juga
     * required (input dgn SEMUA blok required sebelumnya mematikan seluruh lever ini). Prioritas antar
     * blok tetap ditangani urutan rantai lever (G5/G2 sebelum G3, lalu G4/G6). */
    $b2Req = pp_unit_present($d3, 'g1') && $blockReqOfUnit('g1');
    $g1Base = $g1cs ? $g1mcc : 0.0;   // G1's own Cannot-Stop floor (independent of the lever escalation below)
    /* ZERO TOLERANCE §5: Last Data Status = Running berarti unit BERBEBAN dari row 1 dan lanjutan dari
     * kemarin sampai perintah stop pertamanya (Stop At / stop schedule) — bukan 0 dari awal. Base per-row:
     * cannot-stop/continuous -> min-CC pada semua row di luar window stop; Running (tanpa continuous) ->
     * min-CC dari row 1 sampai tepat sebelum window stop pertama, 0 sesudahnya (tidak auto-restart). */
    $g1BaseRow = array_fill(0, $n, 0.0);
    {
        $g1Run = (($lastStatus['g1'] ?? '') === 'running');
        $firstStopG1 = $n;
        for ($r = 0; $r < $n; $r++) if (pp_is_unit_stopped($d3, $model, 'g1', $r + 1)) { $firstStopG1 = $r; break; }
        for ($r = 0; $r < $n; $r++) {
            if (pp_is_unit_stopped($d3, $model, 'g1', $r + 1)) { $g1BaseRow[$r] = 0.0; continue; }
            if ($g1cs) $g1BaseRow[$r] = $g1mcc;
            elseif ($g1Run && $r < $firstStopG1) $g1BaseRow[$r] = $g1mcc;
        }
    }

    /* PATCH B01a — BASE PER-ROW G3 (simetris dgn $g1BaseRow di atas).
     * ROOT CAUSE B01: probe deficit export-floor (mkFull) memanggil g3 = 0.0 secara literal, karena rantai
     * lever memang mengeskalasi G3 paling belakang (Block 1, priority lebih rendah). Itu benar untuk G3 yang
     * BEBAS. Tapi bila G3 ber-Commitment (Unit Continuous Running / Cannot-Stop) atau Last Data Status =
     * Running, G3 BUKAN lever bebas — ia DIJAMIN berbeban >= min-CC dan menyumbang steam S1. Menganggapnya 0
     * membuat probe melihat DEFICIT FANTOM, lalu menyalakan G2 DAN G5 sepanjang window kontigu padahal
     * headroom unit prioritas lebih tinggi belum habis (pelanggaran §3.1/§3.3/§3.4).
     * Base ini HANYA lantai commitment yang sudah dijamin — bukan mengeskalasi G3, bukan mengangkat guard
     * anti auto-run: G3 yang tidak required/continuous/running tetap 0 di sini dan tetap perlu evidence. */
    $g3BaseRow = array_fill(0, $n, 0.0);
    {
        $g3cs  = in_array('g3', $csL0, true)
                 || (strtolower((string)(($model['required_mode']['g3']['mode'] ?? ''))) === 'continuous');
        $g3Run = (($lastStatus['g3'] ?? '') === 'running');
        $firstStopG3 = $n;
        for ($r = 0; $r < $n; $r++) if (pp_is_unit_stopped($d3, $model, 'g3', $r + 1)) { $firstStopG3 = $r; break; }
        if (pp_unit_present($d3, 'g3')) {
            for ($r = 0; $r < $n; $r++) {
                if (pp_is_unit_stopped($d3, $model, 'g3', $r + 1)) { $g3BaseRow[$r] = 0.0; continue; }
                if ($g3cs) $g3BaseRow[$r] = $g3mcc;
                elseif ($g3Run && $r < $firstStopG3) $g3BaseRow[$r] = $g3mcc;
            }
        }
    }

    /* ---- Anti auto-run guard (Revisi Bugfix): a NON-required Block-1 unit (g3/g4/g6/s1) must never be
     * silently started by a repair/shaper pass. It may only carry load when (a) its block is Required, or
     * (b) g3 is itself a Required unit, or (c) it is already loaded on that row (a legitimate earlier
     * decision), or (d) its Last Data Status is Running. Otherwise starting it needs row-level PROOF that no
     * higher-priority allowed lever (g8/g9 on Bus A, Babelan, and any Required Block-2 GTG) can close the
     * deficit — in which case we start it AND emit a row-level warning as evidence. */
    $g3Required = in_array('g3', array_map('strtolower', (array)($model['required_units'] ?? [])), true) || $blockReqOfUnit('g3');
    $g3LastRunning = (($lastStatus['g3'] ?? '') === 'running');
    // may g3 carry load on row $r WITHOUT needing last-resort proof?
    $g3FreeToRun = function (array $g, int $r) use ($g3Required, $g3LastRunning): bool {
        if ($g3Required || $g3LastRunning) return true;                 // required / was on yesterday -> allowed
        if ((float)($g['g3'] ?? 0) > 0.0) return true;                  // already loaded on this row -> keep
        return false;                                                   // non-required & currently 0 -> gated
    };
    $g3AutoStartWarned = [];   // row -> true, so we warn once per row when a non-required g3 is force-started

    /* Dispatch Dev (PLN Export Priority). When required, export on the target period must sit inside the
     * dispatch +/- deviation band; modes: 'same_as_quota' (gas hard-capped at quota) / 'recommend' (may
     * exceed quota, then recommend LNG/Distillate). Export ramp > 30 becomes a warning (not a reject). */
    $dd = $pe['dispatch_dev'] ?? [];
    $ddReq = !empty($dd['required']);
    $ddMode = strtolower((string)($pe['dispatch_dev_mode'] ?? $dd['mode'] ?? 'same_as_quota'));
    $ddTarget = (float)($pe['daily_target']['value'] ?? $dd['value'] ?? $dd['dispatch'] ?? 0);
    if (isset($dd['dispatch'])) $ddTarget = (float)$dd['dispatch'];
    $ddLo = $ddTarget + (float)($dd['min'] ?? 0);
    $ddHi = $ddTarget + (float)($dd['max'] ?? 0);
    $ddStart = max(0, (int)($dd['start'] ?? 1) - 1);
    $ddStop  = min($n - 1, (int)($dd['stop'] ?? 0) - 1);
    $ddActive = $ddReq && $ddTarget > 0 && $ddStop >= $ddStart;
    // FORCE GAS SAME AS QUOTA (Revisi popup option): drive Total Gas into [quota-0.04, quota] by holding Export
    // at the Range Min floor (skip the gas-up that would inflate export/gas) and letting the gas-cap trim down.
    $forceSAQ = (($model['gas_shortage_action'] ?? 'none') === 'force_same_as_quota');

    /* PERF: the shaper evaluates the same row repeatedly (snapshot -> trial -> test -> revert).
     * A single-entry identity cache turns those repeats into a pointer comparison. */
    $eLastG = null; $eLastIe = null; $eLastV = 0.0;
    /* OPT-S3 — daftar unit non-MM2100 dihitung sekali (urutan penjumlahan tetap sama), dan hasil
     * disimpan per nilai IE untuk beberapa row terakhir: pemindaian argmax atas 48 row yang tidak
     * berubah tidak lagi menghitung ulang. Kunci pembanding tetap isi row itu sendiri (===). */
    $UNITS_X = array_values(array_diff($UNITS, ['ge1', 'ge2', 'ge3', 'ge4', 'g10']));
    $eMap = [];
    $expOf = function (array $g, float $ie) use ($UNITS_X, &$eLastG, &$eLastIe, &$eLastV, &$eMap): float {
        if ($eLastG !== null && $eLastIe === $ie && $eLastG === $g) return $eLastV;
        // Isolasi KP72/MM2100 (Master Audit Bagian F): GE1-4 + G10 memberi daya ke pelanggan MM2100,
        // BUKAN ke bus PLN/Jababeka — sama seperti realised_export di worker02, unit MM2100 dikecualikan
        // dari Export PLN sehingga pass Cluster-4 tidak pernah mengganggu pandangan export shaper.
        $k = pack('d', $ie);
        if (isset($eMap[$k])) foreach ($eMap[$k] as $e) if ($e[0] === $g) { $eLastG = $g; $eLastIe = $ie; return $eLastV = $e[1]; }
        $t = 0.0; foreach ($UNITS_X as $u) $t += (float)($g[$u] ?? 0);
        $v = $t - calc_house_load($g) - $ie;
        $eLastG = $g; $eLastIe = $ie; $eLastV = $v;
        $bk = $eMap[$k] ?? []; if (count($bk) >= 4) array_shift($bk); $bk[] = [$g, $v]; $eMap[$k] = $bk;
        return $v;
    };
    /* OPT-S3 — peta unit Bus B (tanpa MM2100) dibangun sekali; iterasi tetap mengikuti urutan $g. */
    $busB = [];
    foreach ((array)$busUnit as $bk0 => $bv0)
        if (is_string($bk0) && substr($bk0, -4) === '_bus' && $bv0 === 'B') $busB[substr($bk0, 0, -4)] = true;
    foreach (['ge1', 'ge2', 'ge3', 'ge4', 'g10'] as $mmU) unset($busB[$mmU]);
    $busOf = function (array $g, ?float $ie = null) use ($busB): float {
        // Bus Flow = IE data1 - Total Load Bus B (Revisi Bugfix Bus Flow). MM2100 units (GE1-4, G10) sit on
        // the separate MM2100 feeder, not the PLN A/B buses, so they are excluded from the PLN Bus B total.
        $b = 0.0;
        foreach ($g as $u => $l) if (isset($busB[$u])) $b += (float)$l;
        return (float)($ie ?? 0.0) - $b;
    };
    $blank = function () use ($UNITS): array { return array_fill_keys($UNITS, 0.0); };
    /* PROMPT STOP_EXPORT_LNG §1.2: alokasi Babelan stop-aware — unit dlm stop window tidak menerima beban. */
    $bbSplit = function (float $bbTot, int $rr) use ($d3, $model): array {
        /* PATCH G31: split cap = EFFECTIVE max per row (envelope Max Load Adjustment), bukan raw */
        $c1 = pp_is_unit_stopped($d3, $model, 'b1', $rr) ? 0.0 : max(0.0, pp_effective_maxload($d3, $model, 'b1', $rr));
        $c2 = pp_is_unit_stopped($d3, $model, 'b2', $rr) ? 0.0 : max(0.0, pp_effective_maxload($d3, $model, 'b2', $rr));
        if ($c1 <= 0) $c1 = pp_is_unit_stopped($d3, $model, 'b1', $rr) ? 0.0 : (float)($d3['b1']['max_load'] ?? 120);
        if ($c2 <= 0) $c2 = pp_is_unit_stopped($d3, $model, 'b2', $rr) ? 0.0 : (float)($d3['b2']['max_load'] ?? 120);
        if ($c1 + $c2 <= 1e-9) return [0.0, 0.0];
        $b1 = min($c1, $bbTot * $c1 / ($c1 + $c2)); $b2 = min($c2, $bbTot - $b1);
        return [$b1, $b2];
    };
    $mk = function (float $v, float $g3, float $bb, int $rr = 1) use ($bbSplit, $blank, $d3, $model, $g1Base) {
        [$bb1s, $bb2s] = $bbSplit($bb, $rr);
        $g = $blank(); $g['g8'] = $v; $g['g9'] = $v; $g['g3'] = $g3; $g['b1'] = $bb1s; $g['b2'] = $bb2s; $g['g1'] = $g1Base;
        pp_recompute_stgs($g, $d3, $model, 1); return $g;
    };

    /* Babelan priority #1: maximise toward full load. Per-row cap = largest BB for which some g8=g9 in
     * [GMIN,GMAX] keeps export in [rMin,rMax] AND BusFlow>=min (G8/G9 are ~gas-free here, pure levers). */
    /* ===== MEMOISASI EKSAK PENCARIAN CAP BABELAN (hot spot #1 seluruh engine) =================
     *  TEMUAN PROFIL. pp_shape_final_dispatch() memakan 97,8% waktu satu core run pada rencana
     *  berat (terukur PEP = 40: 21,84 detik dari 22,34 detik), dan hampir seluruhnya habis di
     *  pencarian tiga-lapis di bawah: 48 row x (langkah BB) x (langkah v). Setiap kombinasi
     *  membangun ulang satu vektor dispatch lengkap lewat $mk() -> pp_recompute_stgs(), sehingga
     *  satu core run dapat memanggilnya lebih dari 150.000 kali. Di situlah letak sebab
     *  DEADLINE_REACHED pada skenario PEP/KP72 tinggi: bukan jumlah pencarian yang terlalu banyak,
     *  melainkan satu core run yang 20x lebih mahal daripada seharusnya.
     *
     *  KENAPA MEMOISASI DI SINI EKSAK. Predikat kelayakan hanya bergantung pada dua besaran yang
     *  merupakan fungsi MURNI dari vektor dispatch:
     *      A(g) = Sigma beban unit non-MM2100 - calc_house_load(g)      -> export = A(g) - IE[r]
     *      B(g) = Sigma beban unit Bus B non-MM2100                     -> busflow = IE[r] - B(g)
     *  Vektor g sendiri hanya bergantung pada (v, b1, b2): $mk() memakai $rr SEMATA-MATA lewat
     *  $bbSplit(), dan pp_recompute_stgs() selalu dipanggil dengan row 1. Karena itu A dan B dapat
     *  dihitung SEKALI per (v, b1, b2) lalu dipakai ulang untuk seluruh row — nilainya identik,
     *  urutan iterasi identik, titik break identik. Yang dihilangkan hanyalah perhitungan ulang
     *  yang hasilnya sudah diketahui; tidak ada kandidat yang dipangkas dan tidak ada ambang baru.
     *  Ekuivalensinya dibuktikan dengan oracle: seluruh skenario wajib menghasilkan checksum
     *  dispatch yang identik dengan build sebelum perubahan ini.
     *
     *  Kunci memo memakai pack('ddd') — identitas biner float, bukan pembulatan desimal, sehingga
     *  dua nilai yang berbeda tidak mungkin tertukar. */
    static $PP_MM_EXCL = ['ge1' => 1, 'ge2' => 1, 'ge3' => 1, 'ge4' => 1, 'g10' => 1];
    $abMemo = [];
    $ppMemoOnAb = ((string)getenv('PP_SHAPER_MEMO') !== '0');
    $abOf = function (float $v, float $bb1, float $bb2) use (&$abMemo, $blank, $d3, $model, $g1Base, $UNITS, $busUnit, $PP_MM_EXCL, $ppMemoOnAb): array {
        $k = pack('ddd', $v, $bb1, $bb2);
        if ($ppMemoOnAb && isset($abMemo[$k])) return $abMemo[$k];
        $g = $blank(); $g['g8'] = $v; $g['g9'] = $v; $g['g3'] = 0.0;
        $g['b1'] = $bb1; $g['b2'] = $bb2; $g['g1'] = $g1Base;
        pp_recompute_stgs($g, $d3, $model, 1);
        $t = 0.0;
        foreach ($UNITS as $u) { if (isset($PP_MM_EXCL[$u])) continue; $t += (float)($g[$u] ?? 0); }
        $A = $t - calc_house_load($g);
        $b = 0.0;
        foreach ($g as $u => $l) {
            if (isset($PP_MM_EXCL[$u])) continue;
            if (($busUnit[$u . '_bus'] ?? null) === 'B') $b += (float)$l;
        }
        return $abMemo[$k] = [$A, $b];
    };
    $maxBBrow = [];
    for ($r = 0; $r < $n; $r++) {
        $cap = 0.0;
        $bbAvailR = (pp_is_unit_stopped($d3, $model, 'b1', $r + 1) ? 0.0 : pp_effective_maxload($d3, $model, 'b1', $r + 1))
                  + (pp_is_unit_stopped($d3, $model, 'b2', $r + 1) ? 0.0 : pp_effective_maxload($d3, $model, 'b2', $r + 1));   // PATCH G31: envelope per row
        $ieR = $ieVals[$r]; $rMaxRr = $rMaxR[$r];
        for ($BB = min($bbMaxTotal, $bbAvailR); $BB >= 0; $BB -= 4) {
            $ok = false;
            /* $bbSplit hanya bergantung pada (BB, row) — dihitung sekali per BB, bukan per v. */
            [$bb1s, $bb2s] = $bbSplit($BB, $r + 1);
            // BB cap is bounded only by over-max and bus-flow (export>=min wants MORE gen, raised later)
            for ($v = $GMAX; $v >= $GMIN - 1e-6; $v -= 1.0) {
                [$A, $B] = $abOf($v, $bb1s, $bb2s);
                if (($A - $ieR) <= $rMaxRr && ($ieR - $B) >= $busMin - 1e-6) { $ok = true; break; }
            }
            if ($ok) { $cap = $BB; break; }
        }
        $maxBBrow[$r] = $cap;
    }
    /* ramped base-load Babelan: hug the per-row cap (maximised, stable), ramp <=10/row (5/unit) */
    $bb = $maxBBrow;
    for ($it = 0; $it < 120; $it++) { $ch = false;
        for ($r = 1; $r < $n; $r++)    if ($bb[$r] > $bb[$r-1] + 10) { $bb[$r] = $bb[$r-1] + 10; $ch = true; }
        for ($r = $n-2; $r >= 0; $r--) if ($bb[$r] > $bb[$r+1] + 10) { $bb[$r] = $bb[$r+1] + 10; $ch = true; }
        for ($r = 0; $r < $n; $r++)    if ($bb[$r] > $maxBBrow[$r] + 1e-9) { $bb[$r] = $maxBBrow[$r]; $ch = true; }
        if (!$ch) break;
    }

    /* per-row g8=g9 window [vLo,vHi] given bb[r]. G3/G4/G6 (Block "1", non-required in this dataset) default
     * OFF — they are only raised below if Babelan+G8/G9 truly cannot reach Range Min. When Block "2" (G1/G2/G5)
     * IS Required and Block "1" is not, G1 (already running, Cannot-Stop) then G2 (Required, based_on_sim) are
     * escalated FIRST, so a non-required G3/S1 never starts while the required block still has headroom. */
    /* PATCH B01a (LINGKUP SEMPIT): $g3row TETAP mulai 0 — ia adalah state ESKALASI lever, dan dipakai juga
     * oleh akuntansi gas top-up ($gasNow) serta estimasi export ($exRow) di bawah. Menyeednya dgn base
     * commitment mengubah kedua hal itu dan membuat top-up berhenti dini -> gas jatuh di bawah window
     * must-take. Base commitment G3 dipakai HANYA di probe deficit export-floor (lihat $g3BaseRow). */
    $g3row = array_fill(0, $n, 0.0); $g1row = $g1BaseRow; $g2row = array_fill(0, $n, 0.0);
    /* ZERO TOLERANCE §4: Block Required (kini g5,g1,g2) — G5 continuous ikut jadi lever eskalasi. */
    $g5cs   = in_array('g5', array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])), true)
              || (strtolower((string)(($model['required_mode']['g5']['mode'] ?? ''))) === 'continuous');
    $g5max  = (float)($d3['g5']['max_load'] ?? 31); $g5mcc = (float)($d3['g5']['min_ccload'] ?? 20);
    $g5row  = array_fill(0, $n, 0.0);
    for ($r = 0; $r < $n; $r++) {
        if (pp_is_unit_stopped($d3, $model, 'g5', $r + 1)) continue;
        if ($g5cs && pp_unit_present($d3, 'g5')) $g5row[$r] = $g5mcc;
        elseif ((($lastStatus['g5'] ?? '') === 'running') && pp_unit_present($d3, 'g5')) $g5row[$r] = $g5mcc;
    }
    foreach (($fixedVal['g5'] ?? []) as $r => $v) $g5row[$r] = $v;
    foreach (['g1' => &$g1row, 'g2' => &$g2row, 'g3' => &$g3row] as $fu => &$farr) {
        foreach (($fixedVal[$fu] ?? []) as $r => $v) $farr[$r] = $v;
    }
    unset($farr);
    $mkG12 = function (float $v, float $g1, float $g2, float $g3, float $bb, int $rr = 1) use ($blank, $d3, $model, $bbSplit) {
        [$bb1s, $bb2s] = $bbSplit($bb, $rr);
        /* PROMPT 3JUL §3.1 (root cause): probe HARUS menghormati scheduled stop per-row — G8/G9
         * yang Stop-At/unit_stop di row ini di-set 0, BUKAN v. Tanpa ini, deficit export pasca
         * stop (mis. G8 stop 18:30) tak terlihat oleh need[]/lever -> tidak ada capacity bridge,
         * export crash minus, dan Block 1 dipakai sbg shortcut terlambat. */
        $cap8 = pp_effective_max_load($d3, $model, 'g8', $rr); if ($cap8 <= 0) $cap8 = (float)($d3['g8']['max_load'] ?? 108);
        $cap9 = pp_effective_max_load($d3, $model, 'g9', $rr); if ($cap9 <= 0) $cap9 = (float)($d3['g9']['max_load'] ?? 108);
        $cap1 = pp_effective_max_load($d3, $model, 'g1', $rr); if ($cap1 <= 0) $cap1 = (float)($d3['g1']['max_load'] ?? 31);
        $g8v = pp_is_unit_stopped($d3, $model, 'g8', $rr) ? 0.0 : min($v, $cap8);
        $g9v = pp_is_unit_stopped($d3, $model, 'g9', $rr) ? 0.0 : min($v, $cap9);
        $g = $blank(); $g['g8'] = $g8v; $g['g9'] = $g9v; $g['g1'] = min($g1, $cap1); $g['g2'] = $g2; $g['g3'] = $g3; $g['b1'] = $bb1s; $g['b2'] = $bb2s;
        pp_recompute_stgs($g, $d3, $model, $rr); return $g;
    };
    /* ===== HIMPUNAN LEGAL LEVEL BERSAMA G8=G9 (D-1, SUMBER KEBENARAN TUNGGAL) ==================
     *  Shaper menggerakkan G8 dan G9 sebagai SATU level bersama $v. Level bersama karena itu wajib
     *  legal bagi KEDUA unit sekaligus: himpunan legalnya adalah IRISAN himpunan legal G8 dan G9
     *  (instruksi §3.3). Irisan dihitung SEKALI per row dari helper yang sama yang dipakai
     *  validator, candidate generator, dan seluruh pass — bukan dari salinan logika band.
     *
     *  Irisan dapat memiliki LEBIH DARI DUA interval. Contoh terukur: G8 band 78-84, G9 band 90-96,
     *  rentang 65-108 -> ([65,78] u [84,108]) irisan ([65,90] u [96,108]) = [65,78] u [84,90] u
     *  [96,108]. Karena itu himpunan disimpan sebagai daftar interval, bukan sepasang batas.
     *
     *  Unit yang berhenti di row ini tidak dihitung: bandnya tidak boleh membatasi level unit yang
     *  masih berjalan (nilainya sudah dipaksa 0 oleh $mkG12/$mkFull).
     *
     *  INERT tanpa Unit Skip Load pada G8/G9: himpunan legal menjadi satu interval [GLO,GHI] dan
     *  seluruh pemeriksaan lolos apa adanya, sehingga hasil yang sudah terverifikasi tidak berubah. */
    $__vLegal = [];
    for ($rw = 1; $rw <= $n; $rw++) {
        $sets = [];
        foreach (['g8', 'g9'] as $uSk) {
            if (pp_is_unit_stopped($d3, $model, $uSk, $rw)) continue;
            $sets[] = pp_legal_load_intervals($d3, $model, $uSk, $rw, $GLO($rw - 1), $GHI($rw - 1));
        }
        if (!$sets)            $iv = [[$GLO($rw - 1), $GHI($rw - 1)]];
        elseif (count($sets) === 1) $iv = $sets[0];
        else                   $iv = pp_intervals_intersect($sets[0], $sets[1]);
        $__vLegal[$rw] = $iv;
    }
    /** Himpunan interval legal level bersama pada row 0-based. */
    $vSet = function (int $r) use ($__vLegal, $n): array {
        return (array)($__vLegal[max(1, min($n, $r + 1))] ?? []);
    };
    /** Apakah level bersama $v TIDAK legal pada row $r (0-based). */
    $vInSkip = function (float $v, int $r) use ($vSet): bool {
        if ($v <= PP_UNIT_ON_MW) return false;                 // OFF tidak terkena band
        $iv = $vSet($r);
        return $iv ? !pp_intervals_contain($iv, $v) : false;
    };
    /** Titik legal terdekat bagi level bersama; null bila himpunan legal kosong (konflik). */
    $vSnap = function (float $v, int $r) use ($vSet): float {
        if ($v <= PP_UNIT_ON_MW) return $v;
        $iv = $vSet($r);
        if (!$iv) return $v;                                   // konflik: dilaporkan validator, tidak disamarkan
        $s = pp_snap_to_legal($iv, $v);
        return $s === null ? $v : $s;
    };
    /* V6 OPT-W1: signature konfigurasi untuk memo jendela per row — seluruh model KECUALI lever gas yang berubah
     * antar-rerun supplier (kuota/target internal, penyesuaian shaper, trim, hint, budget) dan data unit. */
    static $__v6W = []; static $__v6D3 = null; static $__v6D3h = '';
    $__mS = $model; foreach (['gas_quota', '__shaper_quota_adjust', '__gas_trim_target_bbtud', '__tl_supplier_hint', 'time_budget_seconds', 'time_budget_max_seconds', '__core_run_budget', '__gas_offset_search_seconds'] as $__k) unset($__mS[$__k]);
    $__d3x = $d3; unset($__d3x['modeling']);
    if ($__v6D3 !== $__d3x) { $__v6D3 = $__d3x; $__v6D3h = md5(serialize($__d3x)); }
    $__wSig = md5(serialize($__mS)) . $__v6D3h . md5(serialize([$GLOBALS['__stg_memo_sig'] ?? null, $UNITS_X, $busB, $b2Req, $g1max, $n]));
    unset($__mS, $__d3x);
    $vLo = []; $vHi = [];
    for ($r = 0; $r < $n; $r++) {
        if ($isFixed('g8', $r) || $isFixed('g9', $r)) {
            // G8/G9 fixed by the operator: lock the g8=g9 bisection variable to that value for this row so
            // every later stage (gas bisection, top-up, down-dispatch, ramp) treats it as a constant.
            $fv = $fixedVal['g8'][$r] ?? $fixedVal['g9'][$r];
            $vLo[$r] = $vHi[$r] = $fv;
            continue;
        }
        $g1ForProbe = $isFixed('g1', $r) ? $fixedVal['g1'][$r] : $g1BaseRow[$r];
        /* JENDELA PER ROW (D-1): batas scan diambil dari irisan batas G8 dan G9 pada row ini,
         * termasuk kotak legal hasil pemilihan cabang Skip Load. Sebelumnya scan memakai selubung
         * hari $GMIN/$GMAX milik G8 saja, sehingga level yang terpilih bisa melanggar batas G9. */
        $gLoR = $GLO($r); $gHiR = $GHI($r);
        /* V6 OPT-W1: jendela level bersama G8=G9 per row adalah fungsi murni dari isi row (batas, IE, Babelan,
         * G1 probe, himpunan legal) dan konfigurasi model/unit yang dibaca \$mkG12/\$expOf/\$busOf. Hasil
         * pemindaian 0,5 MW dimemo per kunci eksak itu (lintas rerun supplier dalam satu request) — pemindaian
         * sama persis dijalankan bila kunci belum ada; PP_MEMO_VERIFY=1 membandingkan keduanya. */
        $__wK = $__wSig . '|' . $r . '|' . implode(',', [pack('d', $gLoR), pack('d', $gHiR), pack('d', (float)$rMaxR[$r]), pack('d', (float)$rMinR[$r]), pack('d', (float)$busMin), pack('d', (float)$ieVals[$r]), pack('d', (float)$bb[$r]), pack('d', (float)$g1ForProbe), pack('d', (float)$g1BaseRow[$r]), ($isFixed('g1', $r) ? 'F' . pack('d', (float)$fixedVal['g1'][$r]) : 'n')]) . '|' . json_encode($vSet($r));
        $__wHit = (PP_V6_WMEMO && !PP_MEMO_OFF && isset($__v6W[$__wK])) ? $__v6W[$__wK] : null;
        if ($__wHit !== null && !PP_FUEL_MEMO_VERIFY && pp_v6_stg_replay($d3, $model, $__wHit[3])) { [$vl, $vh, $__g1s] = $__wHit; if ($__g1s !== null) $g1row[$r] = $__g1s; }
        else {
        $__g1pre = $g1row[$r]; pp_v6_stg_rec_start();
            $vh = $gLoR;
            for ($v = $gHiR; $v >= $gLoR - 1e-6; $v -= 0.5) { if ($vInSkip($v, $r)) { $vh = $v; continue; }
                if ($expOf($mkG12($v, $g1ForProbe, 0.0, 0.0, $bb[$r], $r + 1), $ieVals[$r]) <= $rMaxR[$r]) { $vh = $v; break; } $vh = $v; }
            $vl = $gHiR; $found = false;
            for ($v = $gLoR; $v <= $gHiR + 1e-6; $v += 0.5) { if ($vInSkip($v, $r)) continue;
                $g = $mkG12($v, $g1ForProbe, 0.0, 0.0, $bb[$r], $r + 1); if ($expOf($g, $ieVals[$r]) >= $rMinR[$r] && $busOf($g, $ieVals[$r]) >= $busMin - 1e-6) { $vl = $v; $found = true; break; } }
            if (!$found && $b2Req && !$isFixed('g1', $r)
                && !pp_is_unit_stopped($d3, $model, 'g1', $r + 1) && $g1BaseRow[$r] > 0.0) {
                /* ZERO TOLERANCE §6: lever G1 hanya pada rows di mana G1 memang boleh berbeban (di luar window
                 * Stop At / stop schedule dan hanya bila base > 0). Tanpa guard ini lever menyalakan G1 di
                 * dalam window stop, lalu ditolkan enforcement — hasilnya G1 = 0 sepanjang hari. */
                for ($l1 = $g1BaseRow[$r]; $l1 <= $g1max + 1e-6; $l1 += 0.5) { $g = $mkG12($gHiR, $l1, 0.0, 0.0, $bb[$r], $r + 1); $g1row[$r] = $l1; if ($expOf($g, $ieVals[$r]) >= $rMinR[$r] && $busOf($g, $ieVals[$r]) >= $busMin - 1e-6) { $found = true; break; } }
                if ($found) $vl = $gHiR;
            }
            if (!$found) {
                /* ZERO TOLERANCE §13.5-6: bila BusFlow minimum tak terjangkau utk SEMUA level (unit Bus-B
                 * ikut naik bersama v sehingga bus justru turun), JANGAN paksa GMAX (itu meledakkan gas tanpa
                 * menaikkan bus) — pakai level export-feasible terendah; deficit bus = evidence. */
                for ($v = $gLoR; $v <= $gHiR + 1e-6; $v += 0.5) { if ($vInSkip($v, $r)) continue;
                    $g = $mkG12($v, $g1ForProbe, 0.0, 0.0, $bb[$r], $r + 1); if ($expOf($g, $ieVals[$r]) >= $rMinR[$r]) { $vl = $v; $found = true; break; } }
                if (!$found) $vl = $gHiR;   // residual export deficit resolved by the contiguous window pass below
            }
        [$__wT, $__wBad] = pp_v6_stg_rec_stop();
        $__wVal = [$vl, $vh, ($g1row[$r] !== $__g1pre) ? $g1row[$r] : null, $__wT];
        if ($__wHit !== null && PP_FUEL_MEMO_VERIFY && !($__wHit[0] === $__wVal[0] && $__wHit[1] === $__wVal[1] && ($__wHit[2] ?? $__g1pre) === ($__wVal[2] ?? $__g1pre))) throw new RuntimeException('V6 OPT-W1: memo jendela level G8/G9 berbeda pada row ' . $r);
        if (!$__wBad && count($__v6W) < 6000) $__v6W[$__wK] = $__wVal;
        }
        if ($vl > $vh) $vh = $vl;
        /* JENDELA HASIL SCAN WAJIB LEGAL. Jalur fallback di atas ($vl = $gHiR bila tidak ada level
         * export-feasible) dapat mendarat di dalam forbidden band; tanpa snap ini jendela yang
         * dipakai SELURUH tahap hilir sudah tidak legal sejak awal. Snap memakai helper yang sama
         * dengan validator, jadi tidak mungkin berbeda pendapat. */
        $vl = $vSnap($vl, $r); $vh = $vSnap($vh, $r);
        if ($vl > $vh) { $t = $vl; $vl = $vh; $vh = $t; }
        $vLo[$r] = $vl; $vHi[$r] = $vh;
    }
    // full working row including Block-Required additional-HRSG units g4/g6 (Bus B, feed S1 steam) and the
    // Block2 levers g1/g2 (Bus A/B, feed S2 steam) — g1/g2 default to their already-escalated state above.
    /* PHP 8.4: an implicit nullable parameter (`float $x = null`) is deprecated. The explicit `?float`
       is the same type the engine already accepted, so call sites and behaviour are unchanged. */
    $mkFull = function (float $v, float $g3, float $bb, float $g4, float $g6, ?float $g1 = null, ?float $g2 = null, float $g5v = 0.0, int $rr = 1) use ($blank, $d3, $model, $g1Base) {
        /* PROMPT 3JUL §3.1: probe menghormati scheduled stop per-row (lihat mkG12). */
        $cap8 = pp_effective_max_load($d3, $model, 'g8', $rr); if ($cap8 <= 0) $cap8 = (float)($d3['g8']['max_load'] ?? 108);
        $cap9 = pp_effective_max_load($d3, $model, 'g9', $rr); if ($cap9 <= 0) $cap9 = (float)($d3['g9']['max_load'] ?? 108);
        $g8v = pp_is_unit_stopped($d3, $model, 'g8', $rr) ? 0.0 : min($v, $cap8);
        $g9v = pp_is_unit_stopped($d3, $model, 'g9', $rr) ? 0.0 : min($v, $cap9);
        $g = $blank(); $g['g8'] = $g8v; $g['g9'] = $g9v; $g['g3'] = $g3; $g['b1'] = $bb/2; $g['b2'] = $bb/2; $g['g4'] = $g4; $g['g6'] = $g6;
        foreach (['g3','g4','g6'] as $cu) { $cm = pp_effective_max_load($d3, $model, $cu, $rr); if ($cm > 0) $g[$cu] = min((float)$g[$cu], $cm); }
        $g1v = $g1 ?? $g1Base; $g2v = $g2 ?? 0.0;
        foreach (['g1'=>&$g1v,'g2'=>&$g2v,'g5'=>&$g5v] as $cu=>&$cv) { $cm = pp_effective_max_load($d3, $model, $cu, $rr); if ($cm > 0) $cv = min((float)$cv, $cm); } unset($cv);
        $g['g1'] = $g1v; $g['g2'] = $g2v; $g['g5'] = $g5v;   // ZERO TOLERANCE §4: G5 anggota Block Required
        pp_recompute_stgs($g, $d3, $model, $rr); return $g;
    };
    $g4row = array_fill(0, $n, 0.0); $g6row = array_fill(0, $n, 0.0);
    foreach (['g4' => &$g4row, 'g6' => &$g6row] as $fu => &$farr) {
        foreach (($fixedVal[$fu] ?? []) as $r => $v) $farr[$r] = $v;
    }
    unset($farr);
    $g4max = (float)($d3['g4']['max_load'] ?? 31); $g4mcc = (float)($d3['g4']['min_ccload'] ?? 20);
    $g6max = (float)($d3['g6']['max_load'] ?? 31); $g6mcc = (float)($d3['g6']['min_ccload'] ?? 20);
    /* Export-floor support: when G8/G9(max)+Babelan+G1(max) still cannot reach Range Min on some rows, bring in
     * a fresh unit over a CONTIGUOUS window (never a single isolated row — a GTG cannot validly start, feed its
     * STG, and stop within one 30-min slot; pp_apply_startup_constraints would reject that pattern anyway).
     * Priority (Master Fable5 Sec.4.2/4.4): G2 first (Required Block2, Additional-HRSG ramp since S2 is already
     * hot from G1) — only if Block2 is the Required one; otherwise/then G3 (Block1 CC-min ramp); then the
     * Block-Required additional-HRSG units G4, then G6. Each lever starts one contiguous block with a 2-row
     * lead-in (5,15 MW) so the Additional-HRSG start-up ramp is absorbed before the deficit rows, and a 2-row
     * taper after so the turn-off export ramp stays <= 30 MW. */
    $need = [];
    for ($r = 0; $r < $n; $r++) {
        $g = $mkFull($vHi[$r], max($g3row[$r], $g3BaseRow[$r]), $bb[$r], 0, 0, $g1row[$r], 0.0, $g5row[$r], $r + 1);   // per-row: hormati scheduled stop G8/G9 · PATCH B01a: hitung lantai commitment G3, bukan 0
        if ($expOf($g, $ieVals[$r]) < $rMinR[$r] - 0.3) $need[$r] = true;
    }
    if ($need) {
        $rn = array_keys($need); $first = min($rn); $last = max($rn);
        /* PRIORITY AUDIT (sistemik — GANTI Lever-0/Lever-1 hardcode "G5 dulu, lalu G2"):
         * lever window Block-2 (anggota selain lead G1, yaitu g2/g5) DIURUTKAN dari Unit Priority
         * input user. Dgn priority [g1,g2,g5] -> G2 dinaikkan lebih dulu (T01); dgn [g1,g5,g2] ->
         * G5 dulu (T02). Setiap lever memakai pola identik: window kontigu + lead-in Additional
         * HRSG (5,15) + taper 2 row (PROMPT FORCE_START §5 — agar startup rebuild MENERIMA start).
         * Row-0 Stop hanya skip row 0 (PROMPT EXPORT_FORCE_START §5), bukan mematikan lever. */
        if ($b2Req) {
            $b2Levers = [];
            foreach (pp_priority_flat($model, '/^g[1-6]$/') ?: ['g2','g5'] as $lu)
                if (in_array($lu, ['g2','g5'], true) && pp_unit_present($d3, $lu)) $b2Levers[] = $lu;
            $b2Lim = ['g2' => [$g2mcc, $g2max], 'g5' => [$g5mcc, $g5max]];
            foreach ($b2Levers as $lu) {
                if (!$need) break;
                $rnL = array_keys($need); $fL = min($rnL); $lL = max($rnL);
                if ($lu === 'g2') $arrL = &$g2row; else $arrL = &$g5row;
                [$mccL, $maxL] = $b2Lim[$lu];
                for ($r = $fL; $r <= $lL; $r++) {
                    if ($isFixed($lu, $r) || pp_is_unit_stopped($d3, $model, $lu, $r + 1)) continue;
                    if ($r === 0 && ($lastStatus[$lu] ?? '') === 'stop') continue;
                    $st = max($arrL[$r], $mccL);
                    for ($x = $st; $x <= $maxL + 1e-6; $x += 1.0) {
                        $arrL[$r] = $x;
                        if ($expOf($mkFull($vHi[$r], max($g3row[$r], $g3BaseRow[$r]), $bb[$r], 0, 0, $g1row[$r], $g2row[$r], $g5row[$r], $r + 1), $ieVals[$r]) >= $rMinR[$r]) break;   // PATCH B01a
                    }
                }
                if ($fL - 1 >= 0 && !$isFixed($lu, $fL - 1) && !pp_is_unit_stopped($d3, $model, $lu, $fL)) $arrL[$fL - 1] = max($arrL[$fL - 1], 15.0);
                if ($fL - 2 >= 0 && !$isFixed($lu, $fL - 2) && !pp_is_unit_stopped($d3, $model, $lu, $fL - 1)) $arrL[$fL - 2] = max($arrL[$fL - 2], 5.0);
                for ($t = 1; $t <= 2; $t++) {
                    $r = $lL + $t; if ($r >= $n || $isFixed($lu, $r) || pp_is_unit_stopped($d3, $model, $lu, $r + 1)) continue;
                    $tv = max(0.0, $arrL[$lL] * (1 - $t / 3.0));
                    if ($tv > 0 && $tv < $mccL) $tv = $mccL;
                    $arrL[$r] = max($arrL[$r], $tv);
                }
                unset($arrL);
                // rebuild need setelah lever ini — lever priority berikutnya hanya dipakai bila deficit tersisa
                $need = [];
                for ($r = 0; $r < $n; $r++) {
                    $g = $mkFull($vHi[$r], max($g3row[$r], $g3BaseRow[$r]), $bb[$r], 0, 0, $g1row[$r], $g2row[$r], $g5row[$r], $r + 1);   // PATCH B01a
                    if ($expOf($g, $ieVals[$r]) < $rMinR[$r] - 0.3) $need[$r] = true;
                }
            }
            if (!$need) goto after_floor_levers;
            $rn = array_keys($need); $first = min($rn); $last = max($rn);
        }
        // Re-check residual deficit after G2 (still using g1row/g2row as already set); only escalate G3 (Block1,
        // non-required) where G2 truly could not close the gap.
        $need2 = [];
        for ($r = 0; $r < $n; $r++) {
            $g = $mkFull($vHi[$r], max($g3row[$r], $g3BaseRow[$r]), $bb[$r], 0, 0, $g1row[$r], $g2row[$r], $g5row[$r], $r + 1);   // PATCH B01a
            if ($expOf($g, $ieVals[$r]) < $rMinR[$r] - 0.3) $need2[$r] = true;
        }
        if ($need2) {
            $rn2 = array_keys($need2); $first2 = min($rn2); $last2 = max($rn2);
            for ($r = $first2; $r <= $last2; $r++) {
                if ($isFixed('g3', $r)) continue;                                 // operator-fixed row -> never overwritten
                if ($r === 0 && ($lastStatus['g3'] ?? '') === 'stop') continue;   // Stop -> row0 must stay 0 MW
                for ($x = $g3mcc; $x <= $g3max + 1e-6; $x += 1.0) { $g3row[$r] = $x; if ($expOf($mkFull($vHi[$r], $x, $bb[$r], 0, 0, $g1row[$r], $g2row[$r], $g5row[$r]), $ieVals[$r]) >= $rMinR[$r]) break; }
            }
            /* ADDENDUM FINAL Bagian 6: start G3 dimajukan SEPANJANG window start-up STG mode aktif
             * (Cold = 7 slot: 5,15,20,20,20,20,20) sehingga S1 sudah boleh muncul TEPAT pada row pertama
             * yang membutuhkan Block 1 — tanpa ini, window menahan S1 = 0 di dalam periode Range-Min
             * tinggi dan export jatuh di bawah floor (deficit fisik). Lead lama 2 slot (15/5) diganti. */
            $modeS1e = (string)(($model['stg_startup_mode']['s1_startup'] ?? 'Cold'));
            $capsS1e = pp_startup_caps('s1', $modeS1e);
            $leadE   = max(2, count($capsS1e));
            $startE  = max((($lastStatus['g3'] ?? '') === 'stop') ? 1 : 0, $first2 - $leadE);
            foreach ($capsS1e ?: [5.0, 15.0] as $kE => $capE) {
                $rE = $startE + $kE; if ($rE >= $first2) break;
                if ($rE < 0 || $isFixed('g3', $rE)) continue;
                $g3row[$rE] = max($g3row[$rE], (float)$capE);
            }
            for ($t = 1; $t <= 2; $t++) {
                $r = $last2 + $t; if ($r >= $n || $isFixed('g3', $r)) continue;
                $tv = max(0.0, $g3row[$last2] * (1 - $t / 3.0));
                if ($tv > 0 && $tv < $g3mcc) $tv = $g3mcc;
                $g3row[$r] = max($g3row[$r], $tv);
            }
            // Evidence: a non-required Block-1 G3 was started to meet the export floor after the Required
            // levers (G1 Cannot-Stop + G2 Required) were exhausted. Row-level proof per the anti auto-run rule.
            if (!$g3Required && !$g3LastRunning) {
                /* ADDENDUM FINAL 3.2: evidence ROW-LEVEL numerik — deficit MW per row DIHITUNG pada state
                 * "semua lever prioritas lebih tinggi sudah maksimal" (G8/G9 max via vHi, G1 max, G2 Required
                 * dinaikkan sampai max), sebelum G3 disentuh. Tanpa angka ini kandidat wajib REJECT. */
                $evRows = [];
                foreach (array_slice($rn2, 0, 6) as $rEv) {
                    $gEv = $mkFull($vHi[$rEv], 0.0, $bb[$rEv], 0, 0, $g1row[$rEv], $g2row[$rEv], $g5row[$rEv]);
                    $evRows[] = sprintf('row %d: export %.1f < min %.1f (deficit %.1f MW)',
                        $rEv + 1, $expOf($gEv, $ieVals[$rEv]), $rMinR[$rEv], $rMinR[$rEv] - $expOf($gEv, $ieVals[$rEv]));
                }
                /* BUGFIX AUDITABILITY: (a) nama unit TIDAK boleh di-hardcode pada pesan;
                 * (b) window yang dilaporkan sebelumnya adalah window DEFISIT, bukan row start
                 * aktual — start sesungguhnya lebih awal sebesar startup lead time sehingga
                 * operator tidak dapat merekonsiliasi kemunculan unit. Keduanya dilaporkan. */
                $uAuto   = 'g3';
                $rampFrom = $startE + 1;                 /* row start aktual (1-based) */
                $rampTo   = $first2;                     /* ramp berakhir tepat sebelum window defisit */
                $warnings[] = sprintf(
                    'AUTO_START_EXPORT_RANGE_MIN: %s (non-required, Last Data Stop) di-start pada row %d '
                  . '(ramp row %d-%d, startup lead %d row) agar tersedia pada window defisit row %d-%d. '
                  . 'Lever prioritas lebih tinggi sudah maksimal sebelum %s disentuh. '
                  . 'Evidence row-level: %s%s.',
                    strtoupper($uAuto), $rampFrom, $rampFrom, max($rampFrom, $rampTo), $leadE,
                    $first2 + 1, $last2 + 1, strtoupper($uAuto),
                    implode('; ', $evRows), count($rn2) > 6 ? sprintf(' (+%d rows lain)', count($rn2) - 6) : '');
            }
        }
    }
    /* Export-floor support (Revisi Sec.4) continued: if G2/G3 (with G1 already maxed) still cannot reach Range
     * Min at peak, START Block-Required additional-HRSG units g4 (then g6) by Unit Priority. Only when both g4
     * and g6 are present. */
    if (pp_unit_present($d3, 'g4') || pp_unit_present($d3, 'g6')) {
        $need = [];
        for ($r = 0; $r < $n; $r++) {
            $g = $mkFull($vHi[$r], $g3row[$r], $bb[$r], 0, 0, $g1row[$r], $g2row[$r], $g5row[$r]);
            if ($expOf($g, $ieVals[$r]) < $rMinR[$r] - 0.3) $need[$r] = true;
        }
        if ($need) {
            $rn = array_keys($need); $first = min($rn); $last = max($rn);
            /* PRIORITY AUDIT (sistemik): urutan pasangan additional-HRSG Block-1 (g4/g6) DIBACA dari
             * Unit Priority input user — priority [.. g4, g6 ..] -> g4 dulu; [.. g6, g4 ..] -> g6 dulu. */
            $b1Pair = [];
            foreach (pp_priority_flat($model, '/^g[46]$/') ?: ['g4','g6'] as $pu)
                if (pp_unit_present($d3, $pu)) $b1Pair[] = $pu;
            $uA = $b1Pair[0] ?? 'g4'; $uB = $b1Pair[1] ?? ($uA === 'g4' ? 'g6' : 'g4');
            $limAB = ['g4' => [$g4mcc, $g4max], 'g6' => [$g6mcc, $g6max]];
            [$mccA, $maxA] = $limAB[$uA]; [$mccB, $maxB] = $limAB[$uB];
            $mkAB = function (int $r, float $vA, float $vB) use ($mkFull, $vHi, $g3row, $bb, $g1row, $g2row, $g5row, $uA) {
                $g4v = $uA === 'g4' ? $vA : $vB; $g6v = $uA === 'g6' ? $vA : $vB;
                return $mkFull($vHi[$r], $g3row[$r], $bb[$r], $g4v, $g6v, $g1row[$r], $g2row[$r], $g5row[$r]);
            };
            $rowsAB = ['g4' => &$g4row, 'g6' => &$g6row];
            for ($r = $first; $r <= $last; $r++) {
                if ($isFixed($uA, $r) && $isFixed($uB, $r)) continue;             // both operator-fixed -> never overwritten
                if ($r === 0 && ((($lastStatus[$uA] ?? '') === 'stop') || (($lastStatus[$uB] ?? '') === 'stop'))) continue;  // Stop -> row0 must stay 0 MW
                $vA = $isFixed($uA, $r) ? $rowsAB[$uA][$r] : $mccA; $vB = $isFixed($uB, $r) ? $rowsAB[$uB][$r] : 0.0; $ok = false;
                if (!$isFixed($uA, $r)) for ($x = $mccA; $x <= $maxA + 1e-6; $x += 1.0) { $vA = $x; if ($expOf($mkAB($r, $x, $isFixed($uB, $r) ? $vB : 0.0), $ieVals[$r]) >= $rMinR[$r]) { $ok = true; break; } }
                if (!$ok && !$isFixed($uB, $r) && isset($b1Pair[1])) { $vA = !$isFixed($uA, $r) ? $maxA : $vA; for ($x = $mccB; $x <= $maxB + 1e-6; $x += 1.0) { $vB = $x; if ($expOf($mkAB($r, $vA, $x), $ieVals[$r]) >= $rMinR[$r]) break; } }
                if (!$isFixed($uA, $r)) $rowsAB[$uA][$r] = $vA;
                if (!$isFixed($uB, $r)) $rowsAB[$uB][$r] = $vB;
            }
            // Additional-HRSG start-up ramp (5,15 MW) on the 2 rows before the deficit, so the unit is at
            // full output on the first deficit row and the export profile has no start-up discontinuity.
            if ($first - 1 >= 0 && pp_unit_present($d3, $uA)) $rowsAB[$uA][$first - 1] = 15.0;
            if ($first - 2 >= 0 && pp_unit_present($d3, $uA)) $rowsAB[$uA][$first - 2] = 5.0;
            // taper down for 2 rows after the deficit block so the turn-off export ramp stays <= 30 MW
            for ($t = 1; $t <= 2; $t++) {
                $r = $last + $t; if ($r >= $n) break;
                $g4row[$r] = max(0.0, $g4row[$last] * (1 - $t / 3.0));
                $g6row[$r] = max(0.0, $g6row[$last] * (1 - $t / 3.0));
                if ($g4row[$r] > 0 && $g4row[$r] < $g4mcc) $g4row[$r] = $g4mcc;
                if ($g6row[$r] > 0 && $g6row[$r] < $g6mcc) $g6row[$r] = $g6mcc;
            }
        }
        after_floor_levers: ;   // ZERO TOLERANCE §4: G5 saja sudah menutup deficit — lever lain dilewati
    }
    /* ===== MEMOISASI EKSAK $exAt (hot spot #1 yang sebenarnya) ================================
     *  TEMUAN PROFIL (PEP = 40, satu core run 26,6 detik): 71,8% waktu habis di blok penyetelan
     *  level g8=g9, dan biayanya seluruhnya berasal dari $exAt(). $planForLevel() dipanggil 43 kali
     *  (2 batas + 40 bisection + penutup) dan tiap panggilan menjalankan sampai 40 pass smoothing
     *  ramp x 47 row x 2 pemanggilan $exAt = hampir 4.000 panggilan per level, yaitu sekitar
     *  160.000 panggilan per core run. Setiap panggilan membangun ulang satu vektor dispatch penuh
     *  lewat $mkFull() -> pp_recompute_stgs().
     *
     *  Mayoritas panggilan itu adalah PENGULANGAN IDENTIK: pada pass smoothing kedua dan
     *  seterusnya sebagian besar row sudah stabil, sehingga $exAt($r, $v[$r]) dipanggil dengan
     *  argumen yang sama persis puluhan kali.
     *
     *  KENAPA EKSAK. $exAt menangkap $mkFull, $g3row, $g4row, $g6row, $g1row, $g2row, $g5row, $bb,
     *  $expOf, dan $ieVals dengan `use` BY VALUE — seluruhnya dibekukan pada saat closure dibuat.
     *  Karena itu $exAt(r, v) adalah fungsi MURNI dari (r, v) sepanjang hidup closure: perubahan
     *  pada array baris setelah titik ini pun tidak mengubah nilainya (perilaku yang sudah berlaku
     *  sebelum memo ini ada). Memo hanya menghapus perhitungan ulang yang hasilnya dijamin sama.
     *  Tidak ada kandidat yang dipangkas, tidak ada ambang, tidak ada urutan yang berubah.
     *  Kunci memakai pack('d') sehingga identitas float bersifat biner, bukan pembulatan desimal.
     *  Ekuivalensi dibuktikan oracle: checksum dispatch seluruh skenario wajib identik. */
    /* SAKLAR ORACLE: PP_SHAPER_MEMO=0 mematikan memo sehingga setiap nilai dihitung ulang penuh.
     * Kedua mode WAJIB menghasilkan checksum dispatch yang identik — itulah bukti bahwa memo ini
     * murni penghapusan perhitungan berulang, bukan perubahan perilaku. Juga berfungsi sebagai
     * rollback cepat di staging. */
    $ppMemoOn = ((string)getenv('PP_SHAPER_MEMO') !== '0');
    $exAtMemo = [];
    /* V6 OPT-W3: memo lintas core run (satu request) — nilai $exAt hanya bergantung pada angka yang masuk ke
     * $mkFull (dipanggil dengan row 1 bawaan) dan IE row, ditambah konfigurasi yang ditangkap ($__wSig, G1 base). */
    static $__v6X = [];
    if (count($__v6X) > 60000) $__v6X = [];
    $__xSig = $__wSig . pack('d', (float)$g1Base);
    $exAt = function (int $r, float $v) use ($mkFull, $g3row, $g4row, $g6row, $g1row, $g2row, $g5row, $bb, $expOf, $ieVals, &$exAtMemo, $ppMemoOn, &$__v6X, $__xSig, $model, $d3) {
        if (!$ppMemoOn)
            return $expOf($mkFull($v, $g3row[$r], $bb[$r], $g4row[$r], $g6row[$r], $g1row[$r], $g2row[$r], $g5row[$r]), $ieVals[$r]);
        $k = $r . '|' . pack('d', $v);
        if (isset($exAtMemo[$k])) return $exAtMemo[$k];
        $xLife = pp_v6_stg_life($model);
        $kx = $__xSig . '|' . $xLife . '|' . pack('d', $v) . pack('d', (float)$g3row[$r]) . pack('d', (float)$bb[$r]) . pack('d', (float)$g4row[$r]) . pack('d', (float)$g6row[$r]) . pack('d', (float)$g1row[$r]) . pack('d', (float)$g2row[$r]) . pack('d', (float)$g5row[$r]) . pack('d', (float)$ieVals[$r]);
        if (PP_V6_WMEMO && $xLife !== null && isset($__v6X[$kx]) && !PP_MEMO_OFF && !PP_FUEL_MEMO_VERIFY) return $exAtMemo[$k] = $__v6X[$kx];
        $val = $expOf($mkFull($v, $g3row[$r], $bb[$r], $g4row[$r], $g6row[$r], $g1row[$r], $g2row[$r], $g5row[$r]), $ieVals[$r]);
        if ($xLife !== null && isset($__v6X[$kx]) && PP_FUEL_MEMO_VERIFY && $__v6X[$kx] !== $val) throw new RuntimeException('V6 OPT-W3: memo exAt berbeda');
        $xLife2 = pp_v6_stg_life($model);
        if ($xLife2 !== null) $__v6X[$__xSig . '|' . $xLife2 . '|' . substr($kx, strlen($__xSig . '|' . $xLife . '|'))] = $val;
        return $exAtMemo[$k] = $val;
    };

    /* Babelan is already maximised (gas-free, priority #1). G8/G9 now burn real gas, so they are NOT
     * auto-maximised: instead their common level is tuned so Total Gas lands in [quota-0.04, quota] on the
     * normal / Same-as-Quota mode, while every row stays in [vLo,vHi] (Export Range + Bus Flow + export
     * floor). Higher level -> more gas AND more export, so gas is monotonic in the level. */
    $RAMP = 18.0;
    $eHi = []; $eLo = [];
    for ($r = 0; $r < $n; $r++) { $eHi[$r] = min($rMaxR[$r], $exAt($r, $vHi[$r])); $eLo[$r] = max($rMinR[$r], $exAt($r, $vLo[$r])); }

    // per-row gas at a given common g8=g9 level (clamped to the row's feasible window), with ramp smoothing
    /* Snap yang SELALU memilih tepi legal di bawah nilai, tetapi tidak pernah di bawah $vLo. */
    $vSnapDown = function (float $v, int $r) use ($vSet): float {
        $iv = $vSet($r);
        if (!$iv || pp_intervals_contain($iv, $v)) return $v;
        $s = pp_legal_floor($iv, $v);                     // tepi legal TERBESAR yang <= $v
        return $s === null ? $v : $s;                     // tidak ada: biarkan (pass lain yang menolak)
    };
    /* V6 OPT-W4: rencana per level bersama adalah fungsi murni dari level L dan array yang ditangkap BY VALUE di
     * bawah; dimemo lintas core run dengan signature isi array itu + konfigurasi ($__xSig). */
    static $__v6P = [];
    if (count($__v6P) > 3000) $__v6P = [];
    $__pSig = $__xSig . md5(serialize([$n, $vLo, $vHi, $g3row, $g4row, $g6row, $g1row, $g2row, $g5row, $bb, $ieVals, $__vLegal, $RAMP]));
    $planForLevel = function (float $L) use (&$planForLevelRaw, &$__v6P, $__pSig, $model, $d3): array {
        $pl = pp_v6_stg_life($model);
        $k = $__pSig . '|' . $pl . '|' . pack('d', $L);
        if (PP_V6_WMEMO && $pl !== null && isset($__v6P[$k]) && !PP_MEMO_OFF && !PP_FUEL_MEMO_VERIFY) return $__v6P[$k];
        $val = $planForLevelRaw($L);
        if ($pl !== null && isset($__v6P[$k]) && PP_FUEL_MEMO_VERIFY && $__v6P[$k] !== $val) throw new RuntimeException('V6 OPT-W4: memo planForLevel berbeda');
        $pl2 = pp_v6_stg_life($model);
        if ($pl2 !== null) $__v6P[$__pSig . '|' . $pl2 . '|' . pack('d', $L)] = $val;
        return $val;
    };
    $planForLevelRaw = function (float $L) use ($n, $vLo, $vHi, $exAt, $g3row, $g4row, $g6row, $g1row, $g2row, $bb, $d3, $RAMP, $ieVals, $vSnap, $vSnapDown) {
        $v = [];
        /* $vSnap inert bila tidak ada Unit Skip Load pada G8/G9 (lihat catatan di atas). */
        for ($r = 0; $r < $n; $r++) $v[$r] = $vSnap(min($vHi[$r], max($vLo[$r], $L)), $r);
        // export ramp smoothing (<=RAMP) by nudging the higher side down within its window
        for ($pass = 0; $pass < 40; $pass++) {
            $ch = false;
            for ($r = 1; $r < $n; $r++) {
                $d = $exAt($r, $v[$r]) - $exAt($r-1, $v[$r-1]);
                /* ARAH NUDGE DIPERTAHANKAN. Snap dengan preferensi 'near' dapat mengembalikan nilai
                 * yang LEBIH TINGGI dari nilai sebelum nudge (bila tepi band bawah lebih jauh),
                 * sehingga smoothing tidak pernah konvergen dan 40 pass terbuang. Preferensi
                 * 'down' memaksa snap ke tepi legal di bawah, searah dengan nudge. */
                if ($d > $RAMP && $v[$r] > $vLo[$r]) {
                    $nv = $vSnapDown(max($vLo[$r], $v[$r] - 0.5), $r);
                    if ($nv < $v[$r] - PP_LOAD_EPS) { $v[$r] = $nv; $ch = true; }
                }
                elseif ($d < -$RAMP && $v[$r-1] > $vLo[$r-1]) {
                    $nv = $vSnapDown(max($vLo[$r-1], $v[$r-1] - 0.5), $r-1);
                    if ($nv < $v[$r-1] - PP_LOAD_EPS) { $v[$r-1] = $nv; $ch = true; }
                }
            }
            if (!$ch) break;
        }
        $gas = 0.0;
        for ($r = 0; $r < $n; $r++) $gas += (calc_fuel($d3, 'g8', $v[$r]) + calc_fuel($d3, 'g9', $v[$r]) + calc_fuel($d3, 'g3', $g3row[$r]) + calc_fuel($d3, 'g4', $g4row[$r]) + calc_fuel($d3, 'g6', $g6row[$r]) + calc_fuel($d3, 'g1', $g1row[$r]) + calc_fuel($d3, 'g2', $g2row[$r])) / 2.0;
        return [$gas, $v];
    };
    [$gLo, $vAtLo] = $planForLevel($GMIN);
    [$gHi, $vAtHi] = $planForLevel($GMAX);
    $chosenV = null;
    if ($gLo >= $quota) { $chosenV = $vAtLo; }                 // even the minimum level is at/over quota
    elseif ($gHi <= 0.0) { $chosenV = $vAtHi; }      // even the maximum level under-utilises
    else {                                                     // tune level so gas -> quota (use it fully, not over)
        $loL = $GMIN; $hiL = $GMAX; $target = $quota - 0.02;
        for ($k = 0; $k < 40; $k++) { $mid = ($loL + $hiL)/2; [$gm, $vm] = $planForLevel($mid); if ($gm > $target) $hiL = $mid; else $loL = $mid; $chosenV = $vm; }
        [$gf, $chosenV] = $planForLevel($loL);
        if ($gf > $quota) { [$gf, $chosenV] = $planForLevel(max($GMIN, $loL - 0.5)); }
    }

    /* Gas top-up to quota (Revisi Sec.4/under-quota): the FINAL daily up-dispatch (end of this function) raises
     * the gas-tuned level still leaves Total Gas < quota-0.04, raise the real gas levers G3, then G4, then G6
     * (Block-Required block-4 Unit Priority) on rows that still have Export headroom below Range Max, until
     * Total Gas reaches [quota-0.04, quota] without exceeding Range Max or the quota. Raised uniformly in
     * small steps from the largest-headroom rows so the export ramp profile is preserved. */
    // gasNow must reflect the LOCKED actual-period gas (genRows already holds the actual generation for those
    // rows) plus the rebuild gas on the free rows — otherwise an actual period that ran lower/higher than the
    // simulation would not trigger the free-row up-/down-dispatch below (Addendum: no silent under/over).
    $gasNow = 0.0;
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) {
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $u) $gasNow += calc_fuel($d3, $u, $genRows[$r][$u] ?? 0);
        } else {
            $gasNow += calc_fuel($d3,'g8',$chosenV[$r]) + calc_fuel($d3,'g9',$chosenV[$r]) + calc_fuel($d3,'g3',$g3row[$r]) + calc_fuel($d3,'g4',$g4row[$r]) + calc_fuel($d3,'g6',$g6row[$r]) + calc_fuel($d3,'g1',$g1row[$r]) + calc_fuel($d3,'g2',$g2row[$r]);
        }
    }
    $gasNow /= 2.0;
    if ($gasNow < 0.0 && !$ddActive && !$forceSAQ) {
        // Lever order (MASTER FABLE5 Sec.4.2/4.4): exhaust the Required block (G1 already running, then G2,
        // Required mode based_on_sim) BEFORE ever raising the non-required Block1 (G3/G4/G6). If Block1 is the
        // one actually marked Required in this dataset instead, fall back to the original G3/G4/G6-first order.
        /* PRIORITY AUDIT (sistemik): urutan lever DIBACA dari input user — anggota blok Required
         * lebih dulu, lalu Unit Priority (pp_priority_sort_required_first). Bukan hardcode
         * g1/g2-vs-g3/g4/g6: kalau user menukar prioritas, urutan lever ikut berubah. */
        $leverArrs = ['g1' => [&$g1row, $g1max], 'g2' => [&$g2row, $g2max],
                      'g3' => [&$g3row, $g3max], 'g4' => [&$g4row, $g4max], 'g6' => [&$g6row, $g6max]];
        $levers = [];
        foreach (pp_priority_sort_required_first($model, array_keys($leverArrs)) as $lu) {
            if (!pp_unit_present($d3, $lu)) continue;
            $levers[] = [$lu, &$leverArrs[$lu][0], $leverArrs[$lu][1]];
        }
        $exRow = function (int $r) use ($mkFull, $chosenV, &$g3row, &$g4row, &$g6row, &$g1row, &$g2row, $g5row, $bb, $expOf, $ieVals) {
            return $expOf($mkFull($chosenV[$r], $g3row[$r], $bb[$r], $g4row[$r], $g6row[$r], $g1row[$r], $g2row[$r], $g5row[$r]), $ieVals[$r]);
        };
        $guard = 0;
        foreach ($levers as $lev) {
            $u = $lev[0]; $arr = &$lev[1]; $umax = $lev[2];
            /* PATCH B02a — daftar row yang step-nya DITOLAK (lihat rollback non-linearitas di bawah).
             * Tanpa ini row yang sama dipilih ulang tanpa henti: rollback mengembalikan nilai lama sehingga
             * $head row itu TIDAK berubah, ia kembali jadi $bestR, ditolak lagi -> $guard habis terbakar
             * (terukur: 19291 dari 20000 iterasi tersia-sia) dan lever berikutnya (G2/G3) TIDAK PERNAH
             * dicoba -> gas berhenti jauh di bawah window must-take. Blacklist di-reset per lever. */
            $blockedR = [];
            /* PATCH B02b — §6: "Seluruh startup window harus dilindungi dari export repair, GAS TOP-UP,
             * busflow repair, ramp repair, final shaper, dan consolidation." Top-up ini sebelumnya hanya
             * dibatasi pp_effective_maxload (max fisik + Max Load Adjustment) sehingga bebas menaikkan unit
             * DI DALAM ramp startup-nya (mis. G1 Cold 5,15,20x5 -> dipaksa 5,15,31x5). Selama guard kehabisan
             * jatah hal ini tak terlihat; setelah PATCH B02a ia muncul. Cap per-row memakai helper yang SAMA
             * dgn enforcement (pp_start_sequence) sehingga tidak ada aturan startup kedua yang bisa menyimpang. */
            $suCapU = [];
            {
                $fU = -1; for ($rU = 0; $rU < $n; $rU++) if (($arr[$rU] ?? 0) > 0.01) { $fU = $rU; break; }
                if ($fU >= 0) {
                    $capsU = pp_start_sequence($d3, $model, $u, $genRows, $fU);
                    foreach ($capsU as $kU => $cU) if ($fU + $kU < $n) $suCapU[$fU + $kU] = (float)$cU;
                }
            }
            /* PATCH B02c — target loop DISELARASKAN dgn entry-check pass ini (0.0) dan dgn
             * window yang divalidasi validator ([quota-0.04, quota]). Target lama ($quota - 0.07) berhenti
             * 0.03 BBTUD SEBELUM kriteria masuknya sendiri terpenuhi -> pass ini bisa "selesai" sambil
             * meninggalkan gas di luar window must-take. Proteksi overshoot tetap: rollback + break 2
             * bila gasNow > quota. */
            while ($gasNow < 0.0 && $guard < 20000) {
                // pick the row with the most Export headroom where this lever still has room and won't exceed Range Max
                $bestR = -1; $bestHead = 0.0;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r]) || $isFixed($u, $r) || isset($blockedR[$r])) continue;   // PATCH B02a
                    $em = pp_effective_maxload($d3, $model, $u, $r + 1); $cap = min($umax, $em > 0 ? $em : $umax);
                    if (isset($suCapU[$r])) $cap = min($cap, $suCapU[$r]);          // PATCH B02b: hormati window startup
                    if ($arr[$r] >= $cap - 1e-6) continue;
                    $head = $rMaxR[$r] - $exRow($r);
                    if ($head <= 0.6) continue;                       // keep Export under Range Max
                    if ($head > $bestHead) { $bestHead = $head; $bestR = $r; }
                }
                if ($bestR < 0) break;                                // this lever exhausted across all rows
                $before = (calc_fuel($d3, $u, $arr[$bestR])) / 2.0;
                $prevVal = $arr[$bestR];
                $arr[$bestR] = min($arr[$bestR] + 0.5, $rMaxR[$bestR]);
                $em = pp_effective_maxload($d3, $model, $u, $bestR + 1); $cap = min($umax, $em > 0 ? $em : $umax);
                if (isset($suCapU[$bestR])) $cap = min($cap, $suCapU[$bestR]);      // PATCH B02b
                if ($arr[$bestR] > $cap) $arr[$bestR] = $cap;
                // Re-check Range Max AFTER the step: crossing a GTG's min_ccload threshold makes it suddenly
                // count as a valid CC feeder, which can jump the STG's output by far more than a smooth 0.5 MW
                // step would suggest — the pre-step headroom estimate cannot anticipate that non-linearity.
                if ($exRow($bestR) > $rMaxR[$bestR] + 1e-6) { $arr[$bestR] = $prevVal; $blockedR[$bestR] = true; $guard++; continue; }   // PATCH B02a
                $after = (calc_fuel($d3, $u, $arr[$bestR])) / 2.0;
                $gasNow += ($after - $before);
                $guard++;
                if ($gasNow > $quota) { $arr[$bestR] = $prevVal; $gasNow -= ($after - $before); break 2; }
            }
        }
        unset($arr);
    }

    /* Over-quota down-dispatch (Revisi Optimizer Dinamis Sec.5/10): when the projected Total Gas exceeds the
     * quota — e.g. additional unit G4/G6 was started for Range Min at a high quota but the quota is now lower —
     * reduce the gas-driving levers so Total Gas <= quota WITHOUT dropping Export below Range Min. Order:
     * additional units g4, g6 first (turned fully OFF on rows where Export stays >= Range Min without them),
     * then g3 (down to CC-min), then the g8/g9 export lever (down to vLo). This makes the dispatch fully
     * dynamic: G4/G6 are not locked from a previous higher-quota run. */
    $exRowD = function (int $r) use ($mkFull, &$chosenV, &$g3row, &$g4row, &$g6row, &$g1row, &$g2row, $g5row, $bb, $expOf, $ieVals) {
        return $expOf($mkFull($chosenV[$r], $g3row[$r], $bb[$r], $g4row[$r], $g6row[$r], $g1row[$r], $g2row[$r], $g5row[$r]), $ieVals[$r]);
    };
    if ($gasNow > $quota + 0.001 && !$ddActive) {
        // (a) g3 down to CC-min on the highest-Export-margin rows first (keeps Export >= Range Min). G4/G6 are
        //     NOT cycled here: once started they must run continuously to honour the Additional-HRSG start-up
        //     and min-runtime sequence, so trimming them mid-run would create under-min / startup violations.
        if (pp_unit_present($d3, 'g3')) {
            $guard = 0;
            while ($gasNow > $quota && $guard < 40000) {
                $bestR = -1; $bestMargin = 0.6;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r]) || $isFixed('g3', $r)) continue;
                    if ($g3row[$r] <= $g3mcc + 1e-6) continue;
                    $margin = $exRowD($r) - $rMinR[$r];
                    if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
                }
                if ($bestR < 0) break;
                $before = calc_fuel($d3, 'g3', $g3row[$bestR]) / 2.0;
                $g3row[$bestR] = max($g3mcc, $g3row[$bestR] - 0.5);
                $gasNow -= ($before - calc_fuel($d3, 'g3', $g3row[$bestR]) / 2.0);
                $guard++;
            }
        }
        // (a2) g1 down to its own floor (Cannot-Stop min — never below it; no startup/runtime risk since G1
        //      never stops, only its load level changes) — trims back any quota top-up before touching G8/G9.
        if (pp_unit_present($d3, 'g1')) {
            $guard = 0;
            while ($gasNow > $quota && $guard < 40000) {
                $bestR = -1; $bestMargin = 0.6;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r]) || $isFixed('g1', $r)) continue;
                    if ($g1row[$r] <= $g1Base + 1e-6) continue;
                    $margin = $exRowD($r) - $rMinR[$r];
                    if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
                }
                if ($bestR < 0) break;
                $before = calc_fuel($d3, 'g1', $g1row[$bestR]) / 2.0;
                $g1row[$bestR] = max($g1Base, $g1row[$bestR] - 0.5);
                $gasNow -= ($before - calc_fuel($d3, 'g1', $g1row[$bestR]) / 2.0);
                $guard++;
            }
        }
        // (b) g8/g9 export lever down to vLo on max-margin rows (lowers Export toward the Range Min floor)
        if ($gasNow > $quota) {
            $guard = 0;
            while ($gasNow > $quota && $guard < 40000) {
                $bestR = -1; $bestMargin = 0.6;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if ($chosenV[$r] <= $vLo[$r] + 1e-6) continue;
                    $margin = $exRowD($r) - $rMinR[$r];
                    if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
                }
                if ($bestR < 0) break;
                $before = (calc_fuel($d3, 'g8', $chosenV[$bestR]) + calc_fuel($d3, 'g9', $chosenV[$bestR])) / 2.0;
                $chosenV[$bestR] = max($vLo[$bestR], $chosenV[$bestR] - 0.5);
                $gasNow -= ($before - (calc_fuel($d3, 'g8', $chosenV[$bestR]) + calc_fuel($d3, 'g9', $chosenV[$bestR])) / 2.0);
                $guard++;
            }
        }
    }

    /* Dispatch Dev: on the requested period force export into the dispatch +/- deviation band (still inside
     * Range). 'recommend' mode may exceed quota (LNG/Distillate recommended later); 'same_as_quota' keeps
     * the gas-tuned level above. Export ramp > 30 becomes a warning (handled downstream) when active. */
    if ($ddActive) {
        for ($r = $ddStart; $r <= $ddStop; $r++) {
            $lo = max($rMinR[$r], min($rMaxR[$r], $ddLo)); $hi = max($rMinR[$r], min($rMaxR[$r], $ddHi));
            $tt = max($lo, min($hi, $exAt($r, $chosenV[$r])));
            $loV = $vLo[$r]; $hiV = $vHi[$r];
            if ($exAt($r, $loV) >= $tt) $vv = $loV;
            elseif ($exAt($r, $hiV) <= $tt) $vv = $hiV;
            else { $a = $loV; $b = $hiV; for ($k = 0; $k < 28; $k++) { $mid = ($a+$b)/2; if ($exAt($r, $mid) >= $tt) $b = $mid; else $a = $mid; } $vv = $b; }
            $chosenV[$r] = $vv;
        }
    }

    /* materialise the chosen g8=g9 levels */
    $rows = [];
    for ($r = 0; $r < $n; $r++) $rows[$r] = $mkFull($chosenV[$r], $g3row[$r], $bb[$r], $g4row[$r], $g6row[$r], $g1row[$r], $g2row[$r], $g5row[$r]);

    /* safety repairs: export floor / busflow (raise g8/g9 Bus A, then g3) */
    for ($r = 0; $r < $n; $r++) {
        $g = $rows[$r];
        for ($v = $g['g8']; $v <= $GMAX + 1e-6 && ($expOf($g, $ieVals[$r]) < $rMinR[$r] || $busOf($g, $ieVals[$r]) < $busMin - 1e-6); $v += 0.5) { $g['g8'] = $v; $g['g9'] = $v; pp_recompute_stgs($g, $d3, $model, $r + 1); }
        // Raise g3 ONLY if it is free to run, or — as a proven last resort — g8/g9 are already maxed and the
        // export floor still can't be met (non-required g3 then starts WITH a row-level evidence warning).
        if ($expOf($g, $ieVals[$r]) < $rMinR[$r] && !$isFixed('g3', $r) && !pp_is_unit_stopped($d3, $model, 'g3', $r + 1)) {
            $g8Maxed = ($g['g8'] >= $GMAX - 1e-6);
            $allowed = $g3FreeToRun($g, $r);
            if ($allowed || $g8Maxed) {
                $wasZero = ((float)($g['g3'] ?? 0) <= 0.0);
                for ($l3 = $g['g3']; $l3 <= $g3max + 1e-6; $l3 += 0.5) { $g['g3'] = $l3; pp_recompute_stgs($g, $d3, $model, $r + 1); if ($expOf($g, $ieVals[$r]) >= $rMinR[$r]) break; }
                if ($wasZero && !$allowed && ($g['g3'] ?? 0) > 0 && empty($g3AutoStartWarned[$r])) {
                    $g3AutoStartWarned[$r] = true;
                    $warnings[] = sprintf('Non-required G3 started at row %d as last resort: g8/g9 maxed (%.1f MW) but PLN export still below Range Min %.1f — G3 required for export feasibility.', $r + 1, $g['g8'], $rMinR[$r]);
                }
            }
        }
        // over-max guard: lower g8/g9 (Babelan is priority, lowered last)
        for ($v = $g['g8']; $v >= $GMIN - 1e-6 && $expOf($g, $ieVals[$r]) > $rMaxR[$r]; $v -= 0.5) { $g['g8'] = $v; $g['g9'] = $v; pp_recompute_stgs($g, $d3, $model, $r + 1); }
        $rows[$r] = $g;
    }

    /* Export-ramp smoothing via the G8/G9 lever (Revisi): keep |Δexport| <= 30 MW/30min. NOTE (V6): G8/G9 fuel
     * is load-dependent, so the final gas-cap/up-dispatch reconcile Total Gas afterwards. Stays within [GMIN,GMAX], keeps Export in
     * [Range Min, Range Max] and BusFlow >= min. Runs after the G3/G4/G6 gas top-up so it never alters gas. */
    for ($pass = 0; $pass < 300; $pass++) {
        $ch = false;
        for ($r = 1; $r < $n; $r++) {
            $e0 = $expOf($rows[$r - 1], $ieVals[$r - 1]); $e1 = $expOf($rows[$r], $ieVals[$r]);
            $d = $e1 - $e0;
            if (abs($d) <= 30 + 1e-6) continue;
            $hi = ($d > 0) ? $r : $r - 1; $loIdx = ($d > 0) ? $r - 1 : $r;
            $g = $rows[$hi];
            if (($g['g8'] ?? 0) > $GMIN + 1e-6) { $nv = max($GMIN, $g['g8'] - 0.5); $t = $g; $t['g8'] = $nv; $t['g9'] = $nv; pp_recompute_stgs($t, $d3, $model, $hi + 1);
                if ($expOf($t, $ieVals[$hi]) >= $rMinR[$hi] - 1e-6 && $busOf($t, $ieVals[$hi]) >= $busMin - 1e-6) { $rows[$hi] = $t; $ch = true; continue; } }
            $g = $rows[$loIdx];
            if (($g['g8'] ?? 0) < $GMAX - 1e-6) { $nv = min($GMAX, $g['g8'] + 0.5); $t = $g; $t['g8'] = $nv; $t['g9'] = $nv; pp_recompute_stgs($t, $d3, $model, $loIdx + 1);
                if ($expOf($t, $ieVals[$loIdx]) <= $rMaxR[$loIdx] + 1e-6) { $rows[$loIdx] = $t; $ch = true; } }
        }
        if (!$ch) break;
    }

    /* KP72 / MM2100 fixed-flow absorption (Revisi Sec.5): if there is MM2100/KP72 fixed-flow energy, run the
     * Gas Engines GE1-4 by Unit Priority to absorb it; if all present GEs are maxed and energy remains, bring
     * in GTG10 (Simple Cycle). The target per row is the MM2100 fixed-flow energy (actual manual row if given,
     * else the KP72 quota spread evenly). These units feed MM2100 only, so they do NOT touch Jababeka/PGN. */
    $gq = $model['gas_quota'] ?? [];
    $kp72Vol = (float)($gq['pep_kp72'] ?? 0) + (float)($gq['pertagas_kp72'] ?? 0) + (float)($gq['akasia_kp72'] ?? 0) + (float)($gq['baskara_kp72'] ?? 0);
    $ghvM = (float)($model['ghv_mm2100'] ?? 1000);
    $kp72DailyBBTUD = $kp72Vol * $ghvM / 1000.0;                      // KP72 quota expressed as daily energy
    /* KP72 / MM2100 fixed-flow absorption is performed as the FINAL step (see end of this function) so that the
     * MM2100 Gas Engines / GTG10 never perturb the Jababeka-side g8/g9/g3 dispatch. Here we only pre-compute the
     * daily MM2100 energy to deliver; the units themselves stay at zero through every Jababeka pass. */
    if ($kp72DailyBBTUD > 1e-6) {
        // (kept intentionally empty — absorption deferred to the isolated final pass)
    }

    /* Effective Maximum Load enforcement (Revisi): clamp each dispatched unit to its time-based effective
     * max for the row; a candidate load above the effective max is not allowed. */
    $mlClamped = 0;
    for ($r = 0; $r < $n; $r++) {
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u) {
            if (!isset($rows[$r][$u])) continue;
            $em = pp_effective_maxload($d3, $model, $u, $r + 1);
            if ($em > 0 && (float)$rows[$r][$u] > $em + 1e-6) { $rows[$r][$u] = $em; $mlClamped++; }
        }
        pp_recompute_stgs($rows[$r], $d3, $model, $r + 1);
    }
    if ($mlClamped > 0) $warnings[] = sprintf('Time-based Maximum Load Adjustment applied: %d unit-row load(s) capped to their effective max.', $mlClamped);

    /* Unit Last Data Status: Running unit must carry load at row 0 (continuation, not a new start) */
    foreach ($INDEP as $u) {
        if (($lastStatus[$u] ?? '') === 'running' && ($rows[0][$u] ?? 0) <= 0) {
            if (in_array($u, ['g8','g9'], true)) $rows[0][$u] = max($GMIN, (float)$rows[0][$u]);
            elseif ($u === 'g3') $rows[0][$u] = max($g3mcc, (float)$rows[0][$u]);
            elseif (in_array($u, ['b1','b2'], true) && !pp_is_unit_stopped($d3, $model, $u, 1)) $rows[0][$u] = max(30.0, (float)$rows[0][$u]);
        }
    }

    // Cluster 3: build the set of units that must NOT be auto-zeroed by the shaper — Required units,
    // Unit-Cannot-Stop units, and units that were Running in the last actual data (zeroing them would force
    // an unwanted stop/downtime). These are protected from the zeroList and actively re-committed below.
    $lastStat = (array)($model['unit_last_data_status'] ?? []);
    /* PROMPT FORCE_START §5-6: protect memakai pp_autorun_allowed — mencakup Required, Cannot-Stop,
     * LS Running, DAN seluruh anggota Block Required (mis. G5/G2 blok-2) + GE/G10 saat quota MM ada.
     * Root cause G5 hilang: protect lama tidak memuat anggota blok-required ber-LS-Stop, sehingga
     * start G5 yang sah dari lever export-floor di-zero oleh zeroList shaper. */
    $protect = pp_autorun_allowed($model, (float)($kp72DailyBBTUD ?? 0));
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        foreach ($UNITS as $u) if (isset($d3[$u])) $genRows[$r][$u] = (float)($rows[$r][$u] ?? 0);
        // MM2100 units (GE1-4, GTG10) are kept at zero through every Jababeka-side pass so they cannot perturb
        // the g8/g9/g3 dispatch (isolation). Required GE (Cluster 3) is re-committed just below, and the KP72
        // absorption re-adds GE/GTG10 as the very last step. g1/g2 are NOT in this list: the shaper manages
        // them through its own levers (g1row/g2row) — zeroing them here wiped a non-required G2 window the
        // shaper itself committed to reach Range Min (a unit may run without being Required when needed).
        $zeroList = ['ge1','ge2','ge3','ge4','g5','g7','g10'];
        $zeroList = array_values(array_diff($zeroList, $protect));      // never zero Required / Cannot-Stop / last-running
        foreach ($zeroList as $off) if (isset($genRows[$r][$off])) $genRows[$r][$off] = 0.0;
    }
    $su = pp_apply_startup_constraints($genRows, $d3, $model, $actualRows);   // PROMPT MONITORING §8.2: row locked tidak disentuh
    /* Cluster 3: re-commit Required / Unit-Cannot-Stop units that the shaper's rebuild dropped (the genRows are
     * rebuilt from the export-shaped $rows, which only carries g3/g4/g6/g8/g9/b1/b2 — so a Required GE/GTG would
     * otherwise come back as zero). Mirrors worker02 Tahap-1: Cannot-Stop online on every interval at >= its
     * simple-cycle minimum; Required online for at least one slot. Done BEFORE the gas-cap so the down-dispatch
     * re-balances g8/g9 to keep the total within quota. */
    {
        $csU2  = array_values(array_filter(array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []))));
        $reqU2 = array_values(array_filter(array_map('strtolower', (array)($model['required_units'] ?? []))));
        foreach ($csU2 as $u) {
            if (!isset($d3[$u])) continue;                              // required/cannot-stop override the default-absent flag (match worker02 Tahap-1)
            // A CC-feeding GTG (has an STG link) cannot validly sit below its combined-cycle minimum: 5 MW
            // rows would be flagged as invalid startup-sequence states. Simple-cycle units keep min_scload.
            $floor = (pp_gtg_to_stg($d3, $u) !== '')
                ? (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 20)
                : (float)($d3[$u]['min_scload'] ?? 5);
            for ($r = 0; $r < $n; $r++) { if (isset($actualRows[$r])) continue; if (($genRows[$r][$u] ?? 0) < $floor - 1e-6) { $genRows[$r][$u] = $floor; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); } }
        }
        // NOTE: the single-row "run at least once" commit for Required units used to live here, but committing
        // just one row at min_scload is an invalid start-stop blip (fails runtime/startup checks). That is now
        // handled once, correctly, by the "FINAL Required re-assertion" pass near the end of this function,
        // which commits a full runtime-safe window with a proper start-up ramp instead.
        /* STG Required / Cannot-Stop realization (Revisi Gabungan Sec.7/9): an STG (S1/S2/S3) only generates when
         * its feeding GTG runs in combined cycle (>= min_ccload). A Required/Cannot-Stop STG is therefore realized
         * by committing its priority-1 feeding GTG at >= min_ccload — Cannot-Stop: every row (STG online all day);
         * Required: a contiguous window long enough for the STG start-up sequence to complete (>= 1 valid config).
         * The gas-cap down-dispatch below re-balances g8/g9 so the total stays within quota. */
        $pri1Feeder = function (string $s) use ($d3, $model) {
            $feeders = array_values($d3[$s]['hrsg'] ?? []);
            $csL = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
            $lsL = []; foreach (($model['unit_last_data_status'] ?? []) as $k => $v) $lsL[strtolower($k)] = strtolower((string)$v);
            $rm = $model['required_mode'] ?? [];
            $isStartAt = function ($u) use ($rm) { $c = $rm[$u] ?? $rm[strtoupper($u)] ?? null; return is_array($c) && strtolower((string)($c['mode'] ?? '')) === 'start_at'; };
            // 1) prefer a Cannot-Stop feeder (it is already online all day and keeps the STG up);
            // 2) then a Running feeder; never pick a Start-At feeder (it must wait for its start row).
            foreach ($feeders as $cand) { $cand = strtolower((string)$cand); if (isset($d3[$cand]) && pp_unit_present($d3, $cand) && in_array($cand, $csL, true)) return $cand; }
            foreach ([pp_priority_flat($model)] as $grp) foreach ((array)$grp as $cand) { $cand = strtolower((string)$cand);
                if (in_array($cand, $feeders, true) && isset($d3[$cand]) && pp_unit_present($d3, $cand) && !$isStartAt($cand) && ($lsL[$cand] ?? '') === 'running') return $cand; }
            foreach ([pp_priority_flat($model)] as $grp) foreach ((array)$grp as $cand) { $cand = strtolower((string)$cand);
                if (in_array($cand, $feeders, true) && isset($d3[$cand]) && pp_unit_present($d3, $cand) && !$isStartAt($cand)) return $cand; }
            foreach ($feeders as $cand) { $cand = strtolower((string)$cand); if (isset($d3[$cand]) && pp_unit_present($d3, $cand) && !$isStartAt($cand)) return $cand; }
            return '';
        };
        foreach (['s1','s2','s3'] as $s) {
            if (!isset($d3[$s])) continue;
            $isCS = in_array($s, $csU2, true); $isReq = in_array($s, $reqU2, true);
            if (!$isCS && !$isReq) continue;
            $g = $pri1Feeder($s); if ($g === '') continue;
            $mcc = (float)($d3[$g]['min_ccload'] ?? $d3[$g]['min_scload'] ?? 20);
            if ($isCS) {                                                  // STG online every (non-actual) row
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $g, $r + 1)) continue;
                    if (($genRows[$r][$g] ?? 0) < $mcc - 1e-6) { $genRows[$r][$g] = $mcc; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }
                }
            } else {                                                     // Required: sustained window so the STG starts once
                $sruns = 0; for ($r = 0; $r < $n; $r++) if (($genRows[$r][$s] ?? 0) >= 1) $sruns++;
                if ($sruns === 0) {
                    $win = 8; $st = -1; $from = (($lastStatus[$g] ?? '') === 'stop') ? 1 : 0;
                    for ($r = $from; $r + $win <= $n; $r++) {
                        $ok = true; for ($k = 0; $k < $win; $k++) if (isset($actualRows[$r + $k]) || pp_is_unit_stopped($d3, $model, $g, $r + $k + 1)) { $ok = false; break; }
                        if ($ok) { $st = $r; break; }
                    }
                    if ($st >= 0) for ($k = 0; $k < $win; $k++) { if (($genRows[$st + $k][$g] ?? 0) < $mcc - 1e-6) $genRows[$st + $k][$g] = $mcc; pp_recompute_stgs($genRows[$st + $k], $d3, $model, $st + $k + 1); }
                }
            }
        }
        /* Required mode (Addendum Sec.4): per-unit mode in $model['required_mode'] = {unit:{mode, at}}.
         *   - "based_on_sim": run at least once at the optimiser's feasible/low-cost time -> already satisfied by
         *      the run-at-least-once commit above (and by the natural dispatch for units like G4).
         *   - "start_at HH:MM": the unit must FOLLOW its start-up sequence from that time. We force it OFF before
         *      the start row, then impose the start-up ramp (HRSG 1-6: 5,15; GTG 8/9: 40,50,60,70; GE: min) from
         *      the start row, after which it is free (>= min). A unit already running before Start-at (Cannot-Stop
         *      or Running in the last actual data) is treated as CONTINUOUS — no restart (Sec.4.2 rule 6). */
        foreach ((array)($model['required_mode'] ?? []) as $um => $cfg) {
            $u = strtolower((string)$um);
            if (!isset($d3[$u]) || !is_array($cfg)) continue;
            if (strtolower((string)($cfg['mode'] ?? 'based_on_sim')) !== 'start_at') continue;
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)($cfg['at'] ?? $cfg['start_at'] ?? ''), $mm)) continue;
            $sr = (int)floor(((int)$mm[1] * 60 + (int)$mm[2]) / 30);     // 30-min rows; 10:00 -> first load at row index 20
            if ($sr < 0 || $sr >= $n) continue;
            $continuous = in_array($u, $csU2, true) || strcasecmp((string)($lastStat[strtoupper($u)] ?? ($lastStat[$u] ?? '')), 'Running') === 0;
            if ($continuous) continue;                                   // already online -> no restart
            for ($r = 0; $r < $sr; $r++) { if (isset($actualRows[$r])) continue; if (($genRows[$r][$u] ?? 0) != 0.0) { $genRows[$r][$u] = 0.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); } }
            /* PATCH A06: pilih sequence via helper tunggal — STG blok fresh -> STG Startup, bukan Add-HRSG. */
            $caps = pp_start_sequence($d3, $model, $u, $genRows, $sr);
            if (!$caps) $caps = [(float)($d3[$u]['min_scload'] ?? 3)];
            $minRun = (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 5);
            foreach ($caps as $k => $cap) { $r = $sr + $k; if ($r >= $n || isset($actualRows[$r])) continue; $genRows[$r][$u] = $cap; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }
            $rA = $sr + count($caps);
            if ($rA < $n && !isset($actualRows[$rA]) && ($genRows[$rA][$u] ?? 0) < $minRun - 1e-6) { $genRows[$rA][$u] = $minRun; pp_recompute_stgs($genRows[$rA], $d3, $model, $rA + 1); }
        }
        // Forcing a Bus-B unit (e.g. a Required GE) on can push BusFlow below minimum on that row. Restore it by
        // trimming Babelan (also Bus B; gas-free, so no gas cost) first, then raising g8/g9 (Bus A) as a fallback.
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            $bg = 0;
            while ($busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6 && $bg++ < 2000) {
                $b1 = (float)($genRows[$r]['b1'] ?? 0); $b2 = (float)($genRows[$r]['b2'] ?? 0);
                if ($b1 + $b2 > 1e-6) {
                    if ($b1 >= $b2 && $b1 > 0) $genRows[$r]['b1'] = max(0.0, $b1 - 0.5); elseif ($b2 > 0) $genRows[$r]['b2'] = max(0.0, $b2 - 0.5);
                } else {
                    /* ZERO TOLERANCE §13.5-6: BusFlow = IE(data1) - ΣBusB — menaikkan G8/G9 (Bus A) TIDAK
                     * menaikkan BusFlow (fallback lama itu hanya membakar gas). Babelan sudah 0 dan unit
                     * Bus B lain dilindungi cannot-stop/runtime -> deficit fisik = evidence, bukan diobati. */
                    $warnings[] = sprintf('Bus Flow below minimum %.1f at row %d (%.1f): Babelan (Bus B) already 0 and remaining Bus-B units are protected by hard commitment rules — IE-limited, no valid lever remains.', $busMin, $r + 1, $busOf($genRows[$r], $ieVals[$r]));
                    break;
                }
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }

    /* Final authoritative gas-cap (Revisi Komprehensif): true Total Gas = sum(calc_fuel)/2 + startup penalty
     * (each fresh OFF->ON start burns extra gas). The gas-up pass budgets calc_fuel only, so a unit that was
     * started for Range Min can tip the real total just over quota. Trim the gas-driving G3 (then the G8/G9
     * export lever) on the highest Export-margin rows, keeping Export >= Range Min and BusFlow ok, until the
     * true total <= quota. Units stay ON (no new/removed starts => penalty unchanged, startup/runtime intact). */
    if (!$ddActive || $ddMode === 'same_as_quota') {
        $sp = $model['startup_penalty'] ?? [];
        $p16 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
        $p70 = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
        $penNow = 0.0;
        foreach (['g1','g2','g3','g4','g5','g6'] as $u) for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $penNow += $p16;
        foreach (['g7','g8','g9','g10'] as $u)        for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $penNow += $p70;
        // The gas-cap targets the JABABEKA quota, and MM2100 gas (GE1-4 / GTG10) is accounted separately against
        // the KP72 quota, so both gas measures below sum ONLY the Jababeka units (g1-g9). This keeps a Required
        // GE / KP72 absorption from pulling the g8/g9 dispatch down (PGN/Jababeka isolation).
        $gasTrue = function () use (&$genRows, $d3, $n, &$penNow, &$__FC) {
            $g = pp_fuel_sum_g19($genRows, $d3, $n, $__FC);
            return $g / 2.0 + $penNow;
        };
        // calc-fuel-only daily gas (NO startup penalty) — this is the figure reported as
        // "Gas Fuel Total / Total Gas Used" and the one compared against the quota, so the
        // exhaustive down-dispatch targets THIS to keep the reported total <= quota.
        $gasCalc = function () use (&$genRows, $d3, $n, &$__FC) {
            $g = pp_fuel_sum_g19($genRows, $d3, $n, $__FC);
            return $g / 2.0;
        };
        $recalcPen = function () use (&$genRows, $n, $p16, $p70, &$penNow) {
            $penNow = 0.0;
            if ($p16 === 0.0 && $p70 === 0.0) return;          // OPT-P1: penalti nol mutlak -> selalu 0,0
            foreach (['g1','g2','g3','g4','g5','g6'] as $u) for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $penNow += $p16;
            foreach (['g7','g8','g9'] as $u)              for ($k = 1; $k < $n; $k++) if (($genRows[$k-1][$u] ?? 0) < 1 && ($genRows[$k][$u] ?? 0) >= 1) $penNow += $p70;   // G10 dikecualikan: start gas G10 = sisi KP72/MM2100 (Bagian F)
        };
        /* Dynamic stop of additional units (Revisi Sec.5/7): if Total Gas is over quota and a started
         * additional unit (g6 then g4, lowest Unit Priority first) is NOT actually required to hold Range Min
         * — i.e. with the unit OFF, G8/G9 raised up to max can still keep Export >= Range Min on every one of
         * its rows — then remove that unit entirely. Removing a whole run is start-up-safe (the unit simply
         * never starts), so no min-runtime/HRSG sequence is violated and its start-up penalty disappears. */
        $g3maxL = (float)($d3['g3']['max_load'] ?? 31);
        $tryRemove = function (string $u) use (&$genRows, $d3, $model, $n, $expOf, $busOf, $ieVals, $rMinR, $rMaxR, $busMin, $GMAX, $GMIN, $g3maxL) {
            $snap = $genRows; $any = false;
            for ($r = 0; $r < $n; $r++) {
                if (($genRows[$r][$u] ?? 0) <= 1e-6) continue;
                $any = true;
                $genRows[$r][$u] = 0.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                // hold Range Min with the cheaper export lever G8/G9 (up to max) first ...
                $v = (float)($genRows[$r]['g8'] ?? $GMIN);
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $v < $GMAX - 1e-6) {
                    $v = min($GMAX, $v + 0.5); $genRows[$r]['g8'] = $v; $genRows[$r]['g9'] = $v;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
                // ... then raise the already-running CC unit G3 toward its max if still short
                $l3 = (float)($genRows[$r]['g3'] ?? 0);
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $l3 < $g3maxL - 1e-6) {
                    $l3 = min($g3maxL, $l3 + 0.5); $genRows[$r]['g3'] = $l3;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
                if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                    $genRows = $snap; return false;                   // unit is genuinely required here -> keep it
                }
            }
            return $any;
        };
        if ($gasTrue() > $quota + 1e-9) {
            $protectRem = array_merge(array_map('strtolower', (array)($model['required_units'] ?? [])), array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])));
            /* PRIORITY AUDIT (sistemik / Rule A4): kandidat removal = additional-HRSG Block-1 (g4/g6)
             * urut Unit Priority TERENDAH dulu dari input user, bukan hardcode [g6,g4]. */
            foreach (pp_priority_flat_reverse($model, '/^g[46]$/') ?: ['g6','g4'] as $u) {
                if ($gasTrue() <= $quota) break;
                if (!pp_unit_present($d3, $u)) continue;
                if (in_array($u, $protectRem, true)) continue;          // never remove a Required / Cannot-Stop unit
                if ($tryRemove($u)) $recalcPen();
            }
        }
        /* ---- EXHAUSTIVE DOWN-DISPATCH (Revisi Cluster 1): drive true Total Gas to
         *      <= quota (landing in [quota-0.04, quota]) WITHOUT ever finalising over
         *      quota, trying EVERY feasible lever before any shortage is declared.
         *      Lever order per row = lowest Unit Priority first:
         *        g6 -> g4 (additional CC units, toward CC-min/off) -> g3 (toward CC-min)
         *        -> g8/g9 export lever (toward GMIN).
         *      g8/g9 sit on Bus A and are pinned UP by the BusFlow floor while Babelan
         *      (gas-free, Bus B) is maximised, so to lower them we also trim Babelan to
         *      keep BusFlow >= min. Every step keeps Export within [Range Min, Range Max]
         *      and BusFlow >= min; a step that cannot is reverted and the NEXT candidate
         *      is tried — the pass never aborts on a single guard hit (the old code broke
         *      out on the first hit, leaving gas stuck above quota and falsely "infeasible"). */
        $lowerOneRaw = function (int $r) use (&$genRows, $d3, $model, $expOf, $busOf, $ieVals, $rMinR, $busMin, $GMIN, $g3mcc, $g1Base, &$capStep) {
            if ($expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r] <= 1e-4) return false;   // at Range Min floor
            $trim = function (string $lever, float $floor) use (&$genRows, $d3, $model, $expOf, $busOf, $ieVals, $rMinR, $busMin, $r, &$capStep): bool {
                // For the g8/g9 export lever, the two units may sit at slightly different loads (e.g. 105 vs 108
                // after an earlier repair). Decrement BOTH by the same step from their own current value so the
                // export drops gradually (~2*d); forcing g9 down to g8's level would overshoot Range Min in one
                // step and make the trim revert, leaving the row stuck above the floor.
                $cur = ($lever === 'g8') ? min((float)($genRows[$r]['g8'] ?? 0), (float)($genRows[$r]['g9'] ?? 0)) : (float)($genRows[$r][$lever] ?? 0);
                if ($cur <= $floor + 1e-6) return false;
                foreach ([4.0, 2.0, 1.0, 0.5, 0.25, 0.1, 0.05, 0.02] as $d) {
                    if ($d > $cur - $floor + 1e-9) continue;
                    if ($d > $capStep + 1e-9) continue;                 // never trim more than the remaining gas headroom
                    $snap = $genRows[$r];
                    if ($lever === 'g8') { $genRows[$r]['g8'] = (float)$genRows[$r]['g8'] - $d; $genRows[$r]['g9'] = (float)$genRows[$r]['g9'] - $d; }
                    else                 { $genRows[$r][$lever] = $cur - $d; }
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $bg = 0;
                    while ($busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6 && $bg++ < 2000) {
                        $b1 = (float)($genRows[$r]['b1'] ?? 0); $b2 = (float)($genRows[$r]['b2'] ?? 0);
                        if ($b1 + $b2 <= 1e-6) break;                   // Babelan exhausted -> cannot compensate
                        if ($b1 >= $b2 && $b1 > 0) $genRows[$r]['b1'] = max(0.0, $b1 - 0.5); elseif ($b2 > 0) $genRows[$r]['b2'] = max(0.0, $b2 - 0.5); else break;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    }
                    if ($expOf($genRows[$r], $ieVals[$r]) >= $rMinR[$r] - 1e-6 && $busOf($genRows[$r], $ieVals[$r]) >= $busMin - 1e-6) return true;
                    $genRows[$r] = $snap; /* E10 site 13: recompute pasca-restore DIHAPUS (terbukti redundan) */
                }
                return false;
            };
            // (a) g3 toward CC-min, (a2) g1 toward its own Cannot-Stop floor (undo any quota top-up first),
            // then (b) g8/g9 export lever toward GMIN (Unit Cannot Stop -> floor = GMIN).
            if ($trim('g3', $g3mcc)) return true;
            if ($trim('g1', $g1Base)) return true;
            if ($trim('g8', $GMIN))  return true;
            return false;
        };
        /* OPT-S2 — kegagalan lowerOne() pada state row yang SAMA dan batas langkah yang tidak lebih
         * besar pasti berulang: percobaan trim hanya bergantung pada isi row itu (dipulihkan utuh saat
         * gagal) dan pada $capStep (batas atas langkah; $capStep lebih kecil = subset percobaan yang
         * sama). Kegagalan itu dicatat dan tidak dihitung ulang. Hasil identik; hanya kerja ulang hilang. */
        $lowFail = [];
        $lowerOne = function (int $r) use (&$lowerOneRaw, &$lowFail, &$genRows, &$capStep) {
            if (isset($lowFail[$r]) && $capStep <= $lowFail[$r][1] && $lowFail[$r][0] === $genRows[$r]) return false;
            $cs = $capStep;
            if ($lowerOneRaw($r)) { unset($lowFail[$r]); return true; }
            $lowFail[$r] = [$genRows[$r], $cs];
            return false;
        };
        $guard = 0;
        $recalcPen();                                                  // refresh start-up penalty before measuring
        $capTarget = $quota;                                    // land mid-window [quota-0.04, quota]
        $capStep  = 99.0;                                              // per-step MW cap (set from gas headroom below)
        // Dispatch-Dev "same_as_quota": the forced rows [ddStart..ddStop] must keep their required export, so
        // they are pinned (only the free rows are down-dispatched to cap the total at quota).
        $isPinned = function (int $r) use ($actualRows, $ddActive, $ddStart, $ddStop): bool {
            return isset($actualRows[$r]) || ($ddActive && $r >= $ddStart && $r <= $ddStop);
        };
        // NB: the figure compared against quota is calc-fuel + start-up penalty (see worker02 $gas_BBTUD),
        //     so the loop drives $gasTrue() (which includes the penalty), not calc-fuel alone.
        while ($gasTrue() > $capTarget && $guard < 300000) {
            // cap the next decrement to the remaining headroom (~0.00812 BBTUD per MW of g8&g9) so the trim
            // lands inside [quota-0.04, quota] and never overshoots below the window.
            $capStep = max(0.02, ($gasTrue() - $capTarget) / 0.00812);
            $bestR = -1; $bestM = 1e-6;
            for ($r = 0; $r < $n; $r++) {
                if ($isPinned($r)) continue;
                $m = $expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r];
                if ($m > $bestM) { $bestM = $m; $bestR = $r; }
            }
            if ($bestR < 0) break;                                    // every row at Range Min floor -> irreducible
            if (!$lowerOne($bestR)) {
                // best row momentarily stuck (e.g. ramp-pinned) -> try any other reducible row
                $any = false;
                for ($r = 0; $r < $n; $r++) {
                    if ($isPinned($r) || $r === $bestR) continue;
                    if ($expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r] <= 1e-6) continue;
                    if ($lowerOne($r)) { $any = true; break; }
                }
                if (!$any) break;                                     // genuinely exhausted -> residual is a real shortage
            }
            $guard++;
            if ($guard % 256 === 0) $recalcPen();
        }
        $recalcPen();
    }

    /* Final export-floor repair (hard constraint): after all gas trimming, guarantee Export >= Range Min on
     * every row by raising the G8/G9 export lever (then G3 toward max) where a row fell under — e.g. on an
     * additional-unit start-up ramp row. Runs last so the floor is never left violated even when the gas plan
     * is over quota (an over-quota plan still must not breach Range Min; the excess becomes an LNG shortage). */
    for ($r = 0; $r < $n; $r++) {
        if (isset($actualRows[$r])) continue;
        // Per-unit hold check on EVERY row (not just row 0): a unit inside its Stop window or Start-At hold
        // (pp_is_unit_stopped) must stay 0 — only the un-held unit of the g8/g9 pair may be raised.
        $g8Held = pp_is_unit_stopped($d3, $model, 'g8', $r + 1) || ($r === 0 && (($lastStatus['g8'] ?? '') === 'stop'));
        $g9Held = pp_is_unit_stopped($d3, $model, 'g9', $r + 1) || ($r === 0 && (($lastStatus['g9'] ?? '') === 'stop'));
        $v = (float)max($genRows[$r]['g8'] ?? $GMIN, $genRows[$r]['g9'] ?? $GMIN);
        while (!($g8Held && $g9Held) && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $v < $GMAX - 1e-6) {
            $v = min($GMAX, $v + 0.5);
            if (!$g8Held) $genRows[$r]['g8'] = $v;
            if (!$g9Held) $genRows[$r]['g9'] = $v;
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
        }
        $l3 = (float)($genRows[$r]['g3'] ?? 0); $g3maxF = (float)($d3['g3']['max_load'] ?? 31);
        $g3Locked0 = pp_is_unit_stopped($d3, $model, 'g3', $r + 1) || ($r === 0 && ($lastStatus['g3'] ?? '') === 'stop');
        while (!$g3Locked0 && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $l3 < $g3maxF - 1e-6) {
            $l3 = min($g3maxF, $l3 + 0.5); $genRows[$r]['g3'] = $l3;
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
        }
    }

    /* Final export ramp smoothing (hard constraint, monotonic-down): keep |ΔExport| <= 30 MW/30min by
     * lowering the G8/G9 export lever on the HIGHER side of any >30 step (e.g. the row before an additional
     * unit's unavoidable OFF at its CC-min). Only lowers, never below Range Min, so it never creates an
     * under-min row; it simply spreads a steep descent over neighbouring rows. Skipped when Dispatch Dev is
     * active (intentional steps) or on manual actual rows. */
    if (!$ddActive) {
        $eAt = function (int $r) use (&$genRows, $expOf, $ieVals) { return $expOf($genRows[$r], $ieVals[$r]); };
        for ($pass = 0; $pass < 200; $pass++) {
            $ch = false;
            for ($r = 1; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($actualRows[$r-1])) continue;
                $d = $eAt($r) - $eAt($r - 1);
                if (abs($d) <= 30 + 1e-6) continue;
                $hi = $d > 0 ? $r : $r - 1;                            // lower the higher-export row toward the other
                $target = min($eAt($r), $eAt($r - 1)) + 30.0;          // bring the high side down to within 30
                $g = (float)($genRows[$hi]['g8'] ?? 0);
                $guard = 0;
                while ($eAt($hi) > $target + 1e-6 && $g > $GMIN + 1e-6 && $eAt($hi) > $rMinR[$hi] + 1e-6 && $guard < 400) {
                    $g = max($GMIN, $g - 0.5); $genRows[$hi]['g8'] = $g; $genRows[$hi]['g9'] = $g;
                    pp_recompute_stgs($genRows[$hi], $d3, $model, $hi + 1);
                    if ($busOf($genRows[$hi], $ieVals[$hi]) < $busMin - 1e-6) { $g += 0.5; $genRows[$hi]['g8'] = $g; $genRows[$hi]['g9'] = $g; pp_recompute_stgs($genRows[$hi], $d3, $model, $hi + 1); break; }
                    $ch = true; $guard++;
                }
            }
            if (!$ch) break;
        }
    }

    /* Manual Fixed Load override (Ctrl+Click in Simulation Data): unit_fix_load = {unit:[{start,stop,value}]}.
     * The operator's chosen load is authoritative — applied LAST so no gas/ramp/floor pass overrides it. Load,
     * Export, Total Gas, summary and output_data.json then all reflect the manual value. */
    foreach (($model['unit_fix_load'] ?? []) as $u => $rules) {
        if (!isset($d3[$u]) || !is_array($rules)) continue;
        foreach ($rules as $rule) {
            $s = max(1, (int)($rule['start'] ?? 1)); $e = min($n, (int)($rule['stop'] ?? $n));
            $val = (float)($rule['value'] ?? 0);
            for ($r = $s - 1; $r <= $e - 1; $r++) {
                $genRows[$r][$u] = $val;
                // Cluster 2: fixed load is per-unit independent. Do NOT mirror the value onto the paired GTG —
                // if only G8 is fixed, G9 keeps its optimiser value (equal-sharing applies only when neither is
                // overridden). Mirroring here was the bug that forced G9 to follow a manual G8 (and vice-versa).
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }
    /* Cluster 2 follow-up: a manual fix can pull Export below Range Min on a row (e.g. fixing G8 lower than the
     * value the high-IE row needs). Honour the fix, but recover Range Min using only the NON-fixed continuous
     * levers — raise the partner GTG (g9 when g8 is fixed, or g8 when g9 is fixed) then g3, up to their max.
     * Any row still under Range Min after every non-fixed lever is maxed is a genuine fixed-load infeasibility
     * and is reported (not silently violated, not overriding the operator's fix). */
    if (!empty($model['unit_fix_load'])) {
        $fixedAt = function (int $r, string $u) use ($model, $n): bool {
            foreach (($model['unit_fix_load'][$u] ?? []) as $rule) {
                $s = max(1, (int)($rule['start'] ?? 1)); $e = min($n, (int)($rule['stop'] ?? $n));
                if ($r + 1 >= $s && $r + 1 <= $e) return true;
            }
            return false;
        };
        $g3maxF2 = (float)($d3['g3']['max_load'] ?? 31);
        $stillUnder = [];
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            if ($expOf($genRows[$r], $ieVals[$r]) >= $rMinR[$r] - 1e-6) continue;
            // raise the non-fixed partner GTG toward GMAX
            $partner = !$fixedAt($r, 'g9') ? 'g9' : (!$fixedAt($r, 'g8') ? 'g8' : null);
            if ($partner !== null) {
                $g = (float)($genRows[$r][$partner] ?? $GMIN);
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $g < $GMAX - 1e-6) {
                    $g = min($GMAX, $g + 0.5); $genRows[$r][$partner] = $g;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
            // then g3 toward its max (unless g3 itself is the fixed unit)
            if (!$fixedAt($r, 'g3')) {
                $l3 = (float)($genRows[$r]['g3'] ?? 0);
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && $l3 < $g3maxF2 - 1e-6) {
                    $l3 = min($g3maxF2, $l3 + 0.5); $genRows[$r]['g3'] = $l3;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
            if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6) $stillUnder[] = $r + 1;
        }
        if ($stillUnder)
            $warnings[] = sprintf('Fixed Load INFEASIBLE: Export below Range Min on row(s) %s even after raising every non-fixed unit to max — the manual fix is too low for the IE on these rows. Raise the fixed value or lower Range Min.', implode(',', $stillUnder));
    }

    /* ---- Cluster 4 (Master Audit Bagian F/G — MM2100 DAILY QUOTA, isolated final pass) ---------------------
     * KONSEP BARU: gas KP72/MM2100 (PEP/AKASIA/BaGS/Pertagas KP72) is a DAILY quota like PGN — NOT a
     * constant per-row fixed flow. Target window: quotaM - 0.04 <= MM2100 Daily Used <= quotaM.
     *   - Only G10 + GE1-4 consume this quota (never a Jababeka unit); MM2100 units stay excluded from
     *     PLN Export / Jababeka gas / BusFlow, so this pass never perturbs the g8/g9/g3 dispatch.
     *   - ACTUAL override (Bagian F7): a row whose ACTUAL ENERGY TOTAL is filled consumes quota at its
     *     ACTUAL value; the remaining (estimation) rows absorb quotaM - sum(ACTUAL).
     *   - Priority (Bagian F4): Gas Engines FIRST by Unit Priority, level-loaded across the estimation
     *     rows; G10 only for the residual the GEs cannot take (or when GEs are stopped / skip-banded /
     *     unit_present=0 / fixed-conflicted — row-level evidence emitted), or when G10 is Required.
     *   - Maximum Flow MM2100 (Bagian G): planned estimation energy per row is hard-capped at
     *     max_flow_mm2100 (BBTUD, normalized-48) => per-slot cap = max_flow_mm2100 / 48.
     *   - G10 runs as ONE contiguous window honouring Unit Last Data Status (Stop => row 0 stays 0) and
     *     its start-up cap sequence, then flat; a final fine-tune (+/-0.5 MW on the flat rows) lands the
     *     daily total inside [quotaM-0.04, quotaM] without ever exceeding the quota. */
    if ($kp72DailyBBTUD > 1e-6) {
        $gePri = [];
        foreach ([pp_priority_flat($model)] as $blk) foreach ($blk as $u) if (preg_match('/^ge[1-4]$/', $u) && isset($d3[$u])) $gePri[] = $u;
        if (!$gePri) foreach (['ge1','ge2','ge3','ge4'] as $u) if (isset($d3[$u])) $gePri[] = $u;
        /* ADDENDUM Bagian 2.1: satuan Maximum Flow MM2100 = MMSCFD (volume). Konversi ke energi:
         * daily cap = maxFlow(MMSCFD) x GHV_MM2100 / 1000 (BBTUD) -> per-slot cap = /48. */
        $maxFlowM = (float)($model['max_flow_mm2100'] ?? 0);                // MMSCFD; 0/blank = unlimited
        $maxFlowM_BBTUD = $maxFlowM * $ghvM / 1000.0;                       // konversi volume -> energi harian
        $slotCap  = $maxFlowM > 1e-9 ? $maxFlowM_BBTUD / 48.0 : INF;        // per-slot energy cap (Bagian G)
        $aMrows   = (array)($model['actual_energy_mm2100'] ?? []);
        $isActM   = function ($r) use ($aMrows) { return isset($aMrows[$r]) && $aMrows[$r] !== '' && $aMrows[$r] !== null; };
        $actSumM  = 0.0; for ($r = 0; $r < $n; $r++) if ($isActM($r)) $actSumM += (float)$aMrows[$r];
        $remaining = max(0.0, $kp72DailyBBTUD - $actSumM);                  // energy the ESTIMATION rows must absorb
        if ($actSumM > $kp72DailyBBTUD + 0.04)
            $warnings[] = sprintf('MM2100/KP72: sum of ACTUAL ENERGY TOTAL rows (%.3f BBTUD) already exceeds the MM2100 daily quota %.3f BBTUD — actuals are authoritative, estimation rows are planned at 0.', $actSumM, $kp72DailyBBTUD);
        $estRows = [];
        for ($r = 0; $r < $n; $r++) if (!$isActM($r)) $estRows[] = $r;
        $rowEnergyM = function (int $r) use (&$genRows, $d3): float {
            $e = 0.0; foreach (['g10','ge1','ge2','ge3','ge4'] as $u) $e += calc_fuel($d3, $u, (float)($genRows[$r][$u] ?? 0)) / 2.0;
            return $e;
        };
        /* PATCH E17 (Fixed Load Lock utk MM2100): materialisasikan unit_fix_load GE1-4/G10 ke genRows
         * SEBELUM budget dihitung — fixed operator = KONSTANTA yang harus diperhitungkan (bukan ditambah
         * belakangan oleh re-assert di atas level-loading GE lain, yang membuat MM over-quota). */
        foreach (['ge1','ge2','ge3','ge4','g10'] as $uFm) {
            if (!isset($d3[$uFm])) continue;
            for ($rFm = 0; $rFm < $n; $rFm++) {
                if ($isActM($rFm)) continue;
                $fvM = pp_get_fixed_load($model, $uFm, $rFm + 1);
                if ($fvM >= 0 && abs((float)($genRows[$rFm][$uFm] ?? 0) - $fvM) > 1e-9) $genRows[$rFm][$uFm] = $fvM;
            }
        }
        $prePlaced = 0.0; foreach ($estRows as $r) $prePlaced += $rowEnergyM($r);   // fixed loads / required GE already on
        $remaining = max(0.0, $remaining - $prePlaced);
        /* availability + evidence (Bagian F4.3/F4.5) */
        /* §5.1 PROMPT PGN_FLOW_REBALANCE: unit_present TIDAK lagi menghalangi Gas Engine —
         * feasibility GE ditentukan hanya oleh Stop Schedule (full day), Skip/Fixed/MaxLoad,
         * runtime/downtime/ramp, dan Block/Unit Priority. */
        $geAvail = []; $geBlockedWhy = [];
        foreach ($gePri as $u) {
            if (!isset($d3[$u])) { $geBlockedWhy[] = strtoupper($u) . ': tidak terdefinisi di data3'; continue; }
            $stopAllDay = true;
            for ($rC = 1; $rC <= $n; $rC++) { if (!pp_is_unit_stopped($d3, $model, $u, $rC)) { $stopAllDay = false; break; } }
            if ($stopAllDay) { $geBlockedWhy[] = strtoupper($u) . ': Unit Stop Schedule full day 00:00-00:00'; continue; }
            $geAvail[] = $u;
        }
        $g10ok=isset($d3['g10'])&&pp_g10_autorun_allowed($d3,$model,$kp72DailyBBTUD,$n);
        /* setLoad: put unit u on row r at load L IF every hard constraint allows it; returns the fuel delta
         * actually added (0 if rejected). Never modifies an operator-fixed row, never enters a stop window
         * or a skip band, never exceeds the effective max, never lifts row 0 of a Last-Status-Stop unit. */
        $lastStat = $lastStatus;   // lowercased map from the shaper prologue
        $setLoadM = function (string $u, int $r, float $L) use (&$genRows, $d3, $model, $lastStat, $fixedVal): float {
            if (isset($fixedVal[$u][$r])) return 0.0;
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return 0.0;
            if ($r === 0 && ($lastStat[$u] ?? '') === 'stop' && $L > 0.01) return 0.0;
            $emax = pp_effective_maxload($d3, $model, $u, $r + 1);
            if ($L > $emax + 1e-9) $L = $emax;
            $skip = pp_get_skip_load($model, $u, $r + 1);
            if ($skip !== null && $L >= $skip[0] - 1e-9 && $L <= $skip[1] + 1e-9) $L = ($skip[0] - 0.5 >= 0.5) ? $skip[0] - 0.5 : 0.0;
            $cur  = (float)($genRows[$r][$u] ?? 0);
            if (abs($L - $cur) < 1e-9) return 0.0;
            $dF = (calc_fuel($d3, $u, $L) - calc_fuel($d3, $u, $cur)) / 2.0;
            $genRows[$r][$u] = $L;
            return $dF;
        };
        /* ---- PHASE 1 — Gas Engines FIRST, level-loaded across estimation rows ---------------------------- */
        if ($remaining > 0.039 && $geAvail && $estRows) {
            for($round=0;$round<96&&$remaining>0.039;$round++){ if(pp_budget_exceeded('mm_ge_fill'))break;
                $progress = false;
                foreach ($estRows as $r) {
                    if ($remaining <= 0.039) break;
                    if ($rowEnergyM($r) >= $slotCap - 1e-9) continue;        // Maximum Flow MM2100 (Bagian G)
                    foreach ($geAvail as $u) {
                        $cur = (float)($genRows[$r][$u] ?? 0);
                        $nxt = $cur > 0.01 ? $cur + 0.5 : (float)($d3[$u]['min_scload'] ?? 3);
                        if ($nxt > pp_effective_maxload($d3, $model, $u, $r + 1) + 1e-9) continue;
                        $dF = (calc_fuel($d3, $u, $nxt) - calc_fuel($d3, $u, $cur)) / 2.0;
                        if ($dF > $remaining + 1e-12) continue;              // would overshoot the daily quota
                        if ($rowEnergyM($r) + $dF > $slotCap + 1e-12) continue;
                        $got = $setLoadM($u, $r, $nxt);
                        if ($got > 0) { $remaining -= $got; $progress = true; }
                        break;                                               // one step per row per round => level loading
                    }
                }
                if (!$progress) break;
            }
        }
        /* ---- PHASE 2 — G10 residual: ONE contiguous window with the start-up cap sequence ----------------
         * Reservasi start-up gas (Bagian F/H): gas start G10 secara fisik berasal dari KP72/MM2100, jadi
         * SATU penalty start (default 0.20 BBTUD) direservasi dari budget harian SEBELUM run dibangun —
         * total MM2100 (energy + start gas) tidak pernah melewati quota, dan Total Gas plant tetap <= quota. */
        $g10PenReserve = 0.0;
        if ($remaining > 0.039 && $g10ok && $estRows) {
            $g10AlreadyOn = false; for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r]['g10'] ?? 0) >= 0.5) { $g10AlreadyOn = true; break; }
            if (!$g10AlreadyOn && (($lastStat['g10'] ?? '') !== 'running')) {
                $g10PenReserve = 0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */;
                if ($remaining - $g10PenReserve <= 0.039) $g10PenReserve = 0.0;   // reservasi membuat run mustahil -> jangan start G10
                else $remaining -= $g10PenReserve;
            }
        }
        if ($remaining > 0.039 && $g10ok && $estRows && !($g10PenReserve === 0.0 && (($lastStat['g10'] ?? '') !== 'running') && !$geAvail && $kp72DailyBBTUD - $actSumM - $prePlaced <= (0.0 /* ZERO STARTUP GAS PENALTY: mutlak, override input diabaikan */ + 0.039))) {
            if ($geBlockedWhy)
                $warnings[] = 'MM2100/KP72 priority evidence (Bagian F4): Gas Engine dicoba lebih dulu tetapi tidak feasible — ' . implode('; ', $geBlockedWhy) . ' — residual quota dialihkan ke G10.';
            elseif ($geAvail)
                $warnings[] = 'MM2100/KP72 priority evidence (Bagian F4): seluruh Gas Engine feasible sudah level-loaded penuh; residual quota MM2100 dialihkan ke G10.';
            if ($remaining > 0.041)
                $warnings[] = sprintf('MM2100/KP72 DAILY QUOTA under-absorbed: %.3f BBTUD tersisa — GE feasible sudah maksimum dan langkah gas minimum G10 (min-load) melebihi sisa quota; window [quota-0.04, quota] tak terjangkau tanpa melanggar min/max/ramp. VALID-INFEASIBLE.', $remaining);
            $stgOfG10 = pp_gtg_to_stg($d3, 'g10');
            $capsG10  = pp_startup_caps($stgOfG10 !== '' && $stgOfG10 !== null ? $stgOfG10 : 'g10',
                          (string)(($model['stg_startup_mode'][$stgOfG10 . '_startup'] ?? 'Hot')));
            $g10Fresh = (($lastStat['g10'] ?? '') !== 'running');
            $g10minSC = (float)($d3['g10']['min_scload'] ?? 30);
            /* PROMPT STOP_EXPORT_LNG: guard min-runtime — jangan start G10 bila sisa quota tidak cukup
             * membiayai min-load selama minimum runtime (mencegah short-run = runtime violation). */
            if ($g10Fresh) {
                $runRowsG10 = max(1, (int)($d3['g10']['min_run_down']['run'] ?? 12));
                $gasMinRun  = calc_fuel($d3, 'g10', $g10minSC) / 2.0 * $runRowsG10;
                if ($remaining < $gasMinRun - 1e-9) {
                    $warnings[] = sprintf('MM2100/KP72 DAILY QUOTA under-absorbed: sisa %.3f BBTUD < kebutuhan gas G10 min-load x minimum-runtime (%.3f BBTUD) — G10 tidak di-start (mencegah short-run); GE sudah maksimum. VALID-INFEASIBLE.', $remaining, $gasMinRun);
                    $remaining = 0.0;
                }
            }
            $k = 0;                                                          // consecutive on-row index (for caps)
            foreach ($estRows as $r) {
                if ($remaining <= 0.039) break;
                if ($rowEnergyM($r) >= $slotCap - 1e-9) continue;
                if (pp_is_unit_stopped($d3, $model, 'g10', $r + 1)) { $k = 0; continue; }
                if ($r === 0 && $g10Fresh) continue;                         // Last Status Stop: row 0 stays 0
                $emax = pp_effective_maxload($d3, $model, 'g10', $r + 1);
                $cap  = ($g10Fresh && $k < count($capsG10)) ? min($emax, (float)$capsG10[$k]) : $emax;
                // largest 0.5-step load whose fuel fits BOTH the remaining budget and the slot cap
                $budget = min($remaining, $slotCap - $rowEnergyM($r));
                $best = 0.0; $lo = min($cap, max(0.5, ($g10Fresh && $k < count($capsG10)) ? 0.5 : $g10minSC));
                for ($L = $lo; $L <= $cap + 1e-6; $L += 0.5) {
                    if (calc_fuel($d3, 'g10', $L) / 2.0 > $budget + 1e-12) break;
                    $best = $L;
                }
                if ($best < 0.5 - 1e-9) { if ($k > 0) break; else continue; }  // run ended: budget can no longer fund a row
                $dF = $setLoadM('g10', $r, $best);
                if ($dF > 0) { $remaining -= $dF; $k++; }
            }
        }
        /* ---- PHASE 2.5 — kuantisasi min-load G10 (Bagian F2): jika residual < fuel(min_SC)/2, run G10
         * DIPERPANJANG satu row min-SC dengan MENURUNKAN row flat yang ada (redistribusi, total tetap
         * <= quota) sehingga daily used bisa mendarat di [quotaM-0.04, quotaM] tanpa pernah overshoot. */
        while ($remaining > 0.039 && $g10ok) {
            // next allowed row right after the current last G10 on-row (keep the run contiguous)
            $lastOn = -1; for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r]['g10'] ?? 0) >= 0.5) $lastOn = $r;
            $rNew = -1;
            for ($r = max(1, $lastOn + 1); $r < $n; $r++) {
                if ($isActM($r)) continue;
                if (pp_is_unit_stopped($d3, $model, 'g10', $r + 1)) continue;
                if (isset($fixedVal['g10'][$r])) continue;
                if ($rowEnergyM($r) >= $slotCap - 1e-9) continue;
                $rNew = $r; break;
            }
            if ($rNew < 0 || $lastOn < 0 || $rNew !== $lastOn + 1) break;      // no contiguous extension possible
            $g10minSC2 = (float)($d3['g10']['min_scload'] ?? 30);
            $fNew = calc_fuel($d3, 'g10', $g10minSC2) / 2.0;
            if ($fNew > $slotCap + 1e-12) break;                               // Maximum Flow blocks even a min-SC row
            $need = $fNew - $remaining;
            if ($need <= 1e-12) { $got = $setLoadM('g10', $rNew, $g10minSC2); if ($got <= 0) break; $remaining -= $got; continue; }
            // free `need` by lowering existing flat rows (never below min-SC, never below a start-up cap row's value)
            $snapC = $genRows; $freedC = 0.0; $guardC = 0;
            while ($freedC < $need - 1e-12 && $guardC++ < 4000) {
                $bestRC = -1; $bestLC = 0.0;
                for ($r = 0; $r < $n; $r++) {
                    $cl = (float)($genRows[$r]['g10'] ?? 0);
                    if ($cl <= $g10minSC2 + 0.49) continue;                    // already at/near min-SC
                    if ($isActM($r) || isset($fixedVal['g10'][$r])) continue;
                    if ($cl > $bestLC) { $bestLC = $cl; $bestRC = $r; }
                }
                if ($bestRC < 0) break;
                $freedC += (calc_fuel($d3, 'g10', $bestLC) - calc_fuel($d3, 'g10', $bestLC - 0.5)) / 2.0;
                $genRows[$bestRC]['g10'] = $bestLC - 0.5;
            }
            if ($freedC < $need - 1e-12) { $genRows = $snapC; break; }         // cannot fund the new row -> evidence below
            $remaining += $freedC;
            $got = $setLoadM('g10', $rNew, $g10minSC2);
            if ($got <= 0) { $genRows = $snapC; $remaining -= $freedC; break; }
            $remaining -= $got;
        }
        /* ---- PHASE 3 — fine-tune into [quotaM-0.04, quotaM]: +/-0.5 MW on already-running rows ------------
         * G10 rows inside a fresh run's start-up cap window keep their cap (never pumped past the ramp). */
        $g10CapRow = array_fill(0, $n, PHP_FLOAT_MAX);
        if ($g10ok) {
            $stgOfG10b = pp_gtg_to_stg($d3, 'g10');
            $capsB = pp_startup_caps($stgOfG10b !== '' && $stgOfG10b !== null ? $stgOfG10b : 'g10',
                       (string)($model['stg_startup_mode'][$stgOfG10b . '_startup'] ?? 'Hot'));
            $prevOn = (($lastStat['g10'] ?? '') === 'running'); $kk = $prevOn ? count($capsB) : 0;
            for ($r = 0; $r < $n; $r++) {
                $on = ((float)($genRows[$r]['g10'] ?? 0) >= 0.5);
                if ($on) { if ($kk < count($capsB)) $g10CapRow[$r] = (float)$capsB[$kk]; $kk++; }
                else $kk = 0;
            }
        }
        for ($round = 0; $round < 4000 && $remaining > 0.039; $round++) {
            $progress = false;
            foreach ($estRows as $r) {
                if ($remaining <= 0.039) break;
                if ($rowEnergyM($r) >= $slotCap - 1e-9) continue;
                foreach (array_merge($g10ok ? ['g10'] : [], $geAvail) as $u) {   // finest step first (G10 slope kecil)
                    $cur = (float)($genRows[$r][$u] ?? 0);
                    if ($cur < 0.01) continue;                                   // fine-tune only rows already running
                    $nxt = $cur + 0.5;
                    if ($u === 'g10' && $nxt > $g10CapRow[$r] + 1e-9) continue;  // start-up cap window (Bagian E/H)
                    if ($nxt > pp_effective_maxload($d3, $model, $u, $r + 1) + 1e-9) continue;
                    $dF = (calc_fuel($d3, $u, $nxt) - calc_fuel($d3, $u, $cur)) / 2.0;
                    if ($dF > $remaining + 1e-12 || $rowEnergyM($r) + $dF > $slotCap + 1e-12) continue;
                    $got = $setLoadM($u, $r, $nxt);
                    if ($got > 0) { $remaining -= $got; $progress = true; break; }
                }
            }
            if (!$progress) break;
        }
        foreach ($estRows as $r) pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
        if ($remaining > 0.04) {
            $why = [];
            if ($geBlockedWhy) $why[] = implode('; ', $geBlockedWhy);
            if (!$g10ok) $why[] = 'G10 unavailable';
            if (!is_infinite($slotCap)) $why[] = sprintf('Maximum Flow MM2100 = %.2f MMSCFD (= %.3f BBTUD) membatasi tiap row di %.4f BBTUD/slot', $maxFlowM, $maxFlowM_BBTUD, $slotCap);
            if (!$why) $why[] = 'seluruh unit MM2100 yang feasible sudah di max load / kuantisasi min-load';
            $warnings[] = sprintf('MM2100/KP72 DAILY QUOTA under-absorbed: %.3f BBTUD dari quota %.3f BBTUD tidak terserap G10/GE1-4 (%s). MM2100 Daily Used akan berada di bawah quota-0.04 — evidence row-level tersedia.', $remaining, $kp72DailyBBTUD, implode('; ', $why));
        }
    }

    $reqCsStg = false;
    foreach (['s1','s2','s3'] as $s) if (in_array($s, array_map('strtolower', (array)($model['unit_cannot_stop'] ?? [])), true) || in_array($s, array_map('strtolower', (array)($model['required_units'] ?? [])), true)) { $reqCsStg = true; break; }
    $lockG = function (string $u) use ($lastStatus, $model, $n): array {   // rows where Stop/Start-At hold g8/g9 at 0 or on its ramp (no raise)
        if (($lastStatus[$u] ?? '') !== 'stop') return [];
        $rmU = ($model['required_mode'][$u] ?? $model['required_mode'][strtoupper($u)] ?? null);
        $sa = -1;
        if (is_array($rmU) && strtolower((string)($rmU['mode'] ?? '')) === 'start_at'
            && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($rmU['at'] ?? $rmU['start_at'] ?? ''), $mm)) $sa = (int)floor(((int)$mm[1]*60+(int)$mm[2])/30);
        $off = [];
        if ($sa >= 0) { for ($r = 0; $r < $sa + 6 && $r < $n; $r++) $off[$r] = true; }  // OFF before start row + the 6-step ramp window
        else $off[0] = true;                                                            // plain Stop: just hold row 0 at 0
        return $off;
    };

    /* ---- Unit Last Data Status — FINAL row-0 enforcement + start-up sequence (Revisi UI Sec.6) ------------
     * Running  -> the unit MUST carry load > 0 on row 0 (continuation from the previous day; no new start,
     *             no start-up sequence/penalty — handled by the no-penalty initial-running list in worker02).
     * Stop     -> the unit MUST be 0 on row 0, and when it DOES start later it must follow its start-up ramp
     *             (G8/G9: 40·60min,50·30min,60·60min,70 ; G1-G6: 5·30min,15·30min ; then free). The STG stays
     *             at 0 through the restricted steps because pp_recompute_stgs ignores a GTG below min_ccload.
     * Unit-Cannot-Stop overrides Stop on row 0 (a unit that cannot stop is physically online); the UI
     * auto-corrects / warns on that conflict. Actual-data row 0 is never touched. */
    /* FINAL Required re-assertion (Revisi Sec.B): the over-quota down-dispatch may have removed a Required unit
     * that it judged "unnecessary"; a Required unit must still run at least once. Re-commit any Required
     * (non Cannot-Stop) unit that ended at 0 over a RUNTIME-SAFE contiguous window (min-runtime rows, with the
     * Additional-HRSG/STG start-up ramp built in) — never a single row, which would be an invalid start-stop
     * blip that pp_apply_startup_constraints/runtime enforcement would have to reject anyway. */
    {
        $reqF = array_values(array_filter(array_map('strtolower', (array)($model['required_units'] ?? []))));
        $csF  = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
        $rtLim = pp_runtime_limits($model);
        // STOP STATUS = Stop Based on Request per unit: the stop row (1-based; unit must be 0 there)
        $stopAtRow = [];
        foreach (($model['stop_mode'] ?? []) as $suK => $sCfg) {
            if (!is_array($sCfg) || strtolower((string)($sCfg['mode'] ?? '')) !== 'stop_at') continue;
            if (preg_match('/^(\d{1,2}):(\d{2})$/', (string)($sCfg['at'] ?? $sCfg['stop_at'] ?? ''), $smm)) {
                $sr0 = (int)(((int)$smm[1] * 60 + (int)$smm[2]) / 30); if ($sr0 <= 0) $sr0 = 48;
                $stopAtRow[strtolower((string)$suK)] = $sr0;
            }
        }
        foreach ($reqF as $u) {
            if (!isset($d3[$u]) || in_array($u, $csF, true) || in_array($u, ['s1','s2','s3'], true)) continue;
            $runs = 0; $lastLoaded = -1;
            for ($r = 0; $r < $n; $r++) if (($genRows[$r][$u] ?? 0) >= 1) { $runs++; $lastLoaded = $r; }
            $feedsStg = pp_gtg_to_stg($d3, $u) !== '';
            $floor = $feedsStg ? (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 20) : (float)($d3[$u]['min_scload'] ?? 5);
            $runRows = $feedsStg ? $rtLim['combined_cycle']['run_rows'] : $rtLim['gtg_small']['run_rows'];
            // Stop Based on Request + already running: the unit's LAST loaded row must be exactly stopRow-1
            // ("the row before Stop At must still carry load") — extend its tail at floor if it ends early.
            if ($runs > 0 && isset($stopAtRow[$u])) {
                $anchorLast = $stopAtRow[$u] - 2;                      // 0-based index of the row before Stop At
                for ($r = $lastLoaded + 1; $r <= $anchorLast && $r < $n; $r++) {
                    if (isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $u, $r + 1)) break;
                    $genRows[$r][$u] = max((float)($genRows[$r][$u] ?? 0), $floor);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
            if ($runs > 0) continue;
            $rmU = ($model['required_mode'][$u] ?? $model['required_mode'][strtoupper($u)] ?? null);
            $startR = (($lastStatus[$u] ?? '') === 'stop') ? 1 : 0;
            if (is_array($rmU) && strtolower((string)($rmU['mode'] ?? '')) === 'start_at'
                && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($rmU['at'] ?? $rmU['start_at'] ?? ''), $mmR)) {
                $startR = (int)floor(((int)$mmR[1] * 60 + (int)$mmR[2]) / 30);
            }
            // Stop Based on Request + not yet running: anchor the whole window to END at stopRow-1, so the
            // first 0-MW row is exactly the Stop At time (never an early stop, never a run past Stop At).
            if (isset($stopAtRow[$u])) {
                $anchorStart = max($startR, $stopAtRow[$u] - 1 - $runRows);
                if ($anchorStart < $stopAtRow[$u] - 1) $startR = $anchorStart;
            }
            // find the first feasible non-actual, non-stopped row to start the run
            $sr = -1;
            for ($r = $startR; $r < $n; $r++) { if (!isset($actualRows[$r]) && !pp_is_unit_stopped($d3, $model, $u, $r + 1)) { $sr = $r; break; } }
            if ($sr < 0) continue;   // no feasible start row at all -> leave as-is (surfaces as required-unit violation, valid-infeasible)
            $last = min($n - 1, $sr + $runRows - 1);
            if (isset($stopAtRow[$u])) $last = min($last, $stopAtRow[$u] - 2);   // never load at/after the Stop At row
            // Additional-HRSG ramp for a genuine cold start (Stop -> row0 already forced 0 by the pass below);
            // g8/g9 use their own 6-step sequence, g1-g6 use 5/15, g7/g10 (no HRSG) jump straight to floor.
            $ramp = pp_start_sequence($d3, $model, $u, $genRows, $sr);   // PATCH A06 (STG fresh -> STG Startup)
            for ($r = $sr; $r <= $last; $r++) {
                if (isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $u, $r + 1)) break;
                $k = $r - $sr;
                $val = ($k < count($ramp)) ? $ramp[$k] : $floor;
                $genRows[$r][$u] = max((float)($genRows[$r][$u] ?? 0), $val);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
            // Re-committing this unit here (after the gas-cap pass already ran) can push Total Gas back over
            // quota; trim the g8/g9 export lever back down on the rows with the most Export headroom (outside
            // this unit's own window) until Total Gas <= quota, without breaking Range Min.
            $trueGas = function () use (&$genRows, $d3, $model, $n, &$__FC) {
                $g = pp_fuel_sum_g19($genRows, $d3, $n, $__FC);
                return $g / 2.0 + pp_startup_gas_penalty($genRows, $model, true);   // Jababeka-only: G10 start gas = KP72 side
            };
            $guardG = 0;
            while ($trueGas() > $quota + 0.001 && $guardG++ < 20000) {
                $bestR = -1; $bestMargin = 0.6;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r]) || ($r >= $sr && $r <= $last)) continue;
                    if ((float)($genRows[$r]['g8'] ?? 0) <= $GMIN + 1e-6) continue;
                    $margin = $expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r];
                    if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
                }
                if ($bestR < 0) break;   // no more headroom -> residual overage is a genuine gas-quota shortage
                $g8 = (float)$genRows[$bestR]['g8'];
                $genRows[$bestR]['g8'] = ($g8 <= 0.0) ? 0.0 : max($GMIN, $g8 - 0.5);
                $genRows[$bestR]['g9'] = ((float)$genRows[$bestR]['g9'] <= 0.0) ? 0.0 : max($GMIN, (float)$genRows[$bestR]['g9'] - 0.5);
                pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            }
        }
    }
    if (!isset($actualRows[0])) {
        $csSet = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
        // The GTG that keeps a Cannot-Stop STG online is its priority-1 feeder — only THAT one must stay online
        // on row 0. Other feeders of the same block follow their own Last Data Status / Commitment Mode (e.g. a
        // Stop G4/G6 must still be 0 at 00:30, and a Start-At G9 must wait for its start row). (Revisi V2 Sec.C)
        $csStgFeeders = [];
        $rmA = $model['required_mode'] ?? [];
        $isSA = function ($u) use ($rmA) { $c = $rmA[$u] ?? $rmA[strtoupper($u)] ?? null; return is_array($c) && strtolower((string)($c['mode'] ?? '')) === 'start_at'; };
        foreach (['s1','s2','s3'] as $s) {
            if (!isset($d3[$s]) || !in_array($s, $csSet, true)) continue;
            $feeders = array_map('strtolower', array_values($d3[$s]['hrsg'] ?? []));
            $pick = '';
            foreach ($feeders as $cand) if (isset($d3[$cand]) && pp_unit_present($d3, $cand) && in_array($cand, $csSet, true)) { $pick = $cand; break; }  // cannot-stop feeder first
            if ($pick === '') foreach ([pp_priority_flat($model)] as $grp) { foreach ((array)$grp as $cand) { $cand = strtolower((string)$cand);
                if (in_array($cand, $feeders, true) && isset($d3[$cand]) && pp_unit_present($d3, $cand) && !$isSA($cand)) { $pick = $cand; break 2; } } }
            if ($pick === '') foreach ($feeders as $cand) if (isset($d3[$cand]) && pp_unit_present($d3, $cand) && !$isSA($cand)) { $pick = $cand; break; }
            if ($pick !== '') $csStgFeeders[$pick] = true;
        }
        // duration-expanded start-up ramp (one entry per 30-min row)
        /* PATCH A06: sequence dipilih helper tunggal — STG blok fresh -> STG Startup (Cold/Warm/Hot),
         * STG blok sudah berbeban -> Additional HRSG. g7/g10 (tanpa HRSG) -> []. */
        $seqOf = function (string $u) use ($d3, $model, &$genRows): array {
            return pp_start_sequence($d3, $model, $u, $genRows, 0);
        };
        foreach ($lastStatus as $u => $st) {
            if (!isset($d3[$u])) continue;                          // STG (s1-s3) follow their feeding GTG, not forced directly
            if ($st === 'stop' && !in_array($u, $csSet, true)) {
                if (isset($csStgFeeders[$u])) {                          // feeds a Cannot-Stop STG -> keep online at row 0
                    if ((float)($genRows[0][$u] ?? 0) <= 0.0) { $genRows[0][$u] = (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 5); pp_recompute_stgs($genRows[0], $d3, $model, 1); }
                    continue;
                }
                if ((float)($genRows[0][$u] ?? 0) > 0.0) { $genRows[0][$u] = 0.0; pp_recompute_stgs($genRows[0], $d3, $model, 1); }
                // Start Based on Request (Commitment Mode): the unit must stay OFF until its Start-At row, then
                // follow the start-up ramp from there. Force OFF before the start row so downstream export/gas
                // levers cannot re-run it earlier, making the ramp land at the requested time (Revisi Sec.B2).
                $saRow = -1;
                $rmU = ($model['required_mode'][$u] ?? $model['required_mode'][strtoupper($u)] ?? null);
                if (is_array($rmU) && strtolower((string)($rmU['mode'] ?? '')) === 'start_at'
                    && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($rmU['at'] ?? $rmU['start_at'] ?? ''), $mmA)) {
                    $saRow = (int)floor(((int)$mmA[1] * 60 + (int)$mmA[2]) / 30);
                    for ($r = 0; $r < $saRow && $r < $n; $r++) { if (!isset($actualRows[$r]) && (float)($genRows[$r][$u] ?? 0) != 0.0) { $genRows[$r][$u] = 0.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); } }
                }
                // impose the start-up ramp on the FIRST row the unit comes online (a Stop unit was OFF the
                // previous day, so its first load is a genuine cold/additional start, not a continuation).
                $seq = $seqOf($u);
                if ($seq) {
                    $sr = -1;
                    $from = ($saRow >= 0) ? $saRow : 1;
                    for ($r = $from; $r < $n; $r++) { if ((float)($genRows[$r][$u] ?? 0) >= 1.0) { $sr = $r; break; } }
                    if ($sr >= 0) {
                        foreach ($seq as $k => $cap) {
                            $rr = $sr + $k; if ($rr >= $n) break;
                            if ($isFixed($u, $rr)) continue;                          // operator-fixed row -> never capped
                            if ((float)($genRows[$rr][$u] ?? 0) < 1.0) break;       // unit went back off -> ramp ends
                            if (pp_is_unit_stopped($d3, $model, $u, $rr + 1)) break;
                            if ((float)$genRows[$rr][$u] > $cap + 1e-6) { $genRows[$rr][$u] = $cap; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1); }
                        }
                    }
                }
            } elseif ($st === 'running') {
                if ((float)($genRows[0][$u] ?? 0) <= 0.0) {
                    $fl = in_array($u, ['g8','g9'], true) ? $GMIN : ($u === 'g3' ? $g3mcc : (in_array($u, ['b1','b2'], true) ? 30.0 : (float)($d3[$u]['min_scload'] ?? $d3[$u]['min_ccload'] ?? 5)));
                    $genRows[0][$u] = $fl; pp_recompute_stgs($genRows[0], $d3, $model, 1);
                }
            }
        }
    }

    /* ---- PATCH G01 — MUST-START enforcement utk Start Based on Request -------------------------------
     * required_mode = start_at adalah PERINTAH operator: unit WAJIB start tepat 30 menit setelah marker.
     * Normalisasi hanya menahan (hold) sebelum marker; bila tidak ada pass yang menyalakannya (mis. unit
     * bukan lever ekspor/gas), paksa on: startup sequence (Additional HRSG / SC step) lalu min-load sampai
     * dihentikan aturan stop/akhir hari. Pass repair selanjutnya bebas menaikkan level. */
    foreach (($model['required_mode'] ?? []) as $uMS => $cfgMS) {
        if (!is_array($cfgMS) || strtolower((string)($cfgMS['mode'] ?? '')) !== 'start_at') continue;
        $uMS = strtolower((string)$uMS);
        if (!isset($d3[$uMS]) || in_array($uMS, ['s1','s2','s3'], true)) continue;
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string)($cfgMS['at'] ?? $cfgMS['start_at'] ?? ''), $mMS)) continue;
        $saR = (int)(((int)$mMS[1] * 60 + (int)$mMS[2]) / 30);            // marker row (1-based); start = marker+1
        $onAfter = false;
        for ($r = $saR; $r < $n; $r++) if ((float)($genRows[$r][$uMS] ?? 0) > 0.01) { $onAfter = true; break; }
        if ($onAfter) continue;
        $seqMS = pp_start_sequence($d3, $model, $uMS, $genRows, $saR);   // PATCH A06 (STG fresh -> STG Startup)
        $mnMS = (float)($d3[$uMS]['min_ccload'] ?? ($d3[$uMS]['min_scload'] ?? 5));
        $kSeq = 0;
        for ($r = $saR; $r < $n; $r++) {
            if (isset($actualRows[$r])) { continue; }
            if (pp_is_unit_stopped($d3, $model, $uMS, $r + 1)) break;      // aturan stop user menang
            $fvMS = pp_get_fixed_load($model, $uMS, $r + 1);
            $val = ($kSeq < count($seqMS)) ? $seqMS[$kSeq] : $mnMS;
            $genRows[$r][$uMS] = ($fvMS >= 0) ? $fvMS : $val;
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            $kSeq++;
        }
        $warnings[] = sprintf('Start Based on Request enforced: %s dinyalakan row %d (marker %s) dgn lead-in Additional-HRSG lalu min-load — tidak ada pass lain yang membutuhkannya, komitmen operator tetap dihormati.', strtoupper($uMS), $saR + 1, (string)($cfgMS['at'] ?? ''));
    }

    /* ---- FINAL BusFlow + Range-Min repair (Revisi Gabungan) ----------------------------------------------
     * The Last-Status start-up ramp (g1 at 5/15, etc.) can leave BusFlow below minimum or export below Range
     * Min on a row. Restore both by raising g8/g9 (Bus A, also lifts export), then g3; fall back to trimming
     * Babelan only for a pure BusFlow deficit when g8/g9 are maxed. A g8/g9 held OFF by its own Stop/Start-At
     * commitment ($lockG) is never re-raised, so a Start-At G9 stays 0 before its start row. Skips actual rows. */
    if ($reqCsStg) {
        $offG8 = $lockG('g8'); $offG9 = $lockG('g9');
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r])) continue;
            $bg = 0;
            $fixG8 = pp_get_fixed_load($model, 'g8', $r + 1) >= 0 || isset($offG8[$r]); $fixG9 = pp_get_fixed_load($model, 'g9', $r + 1) >= 0 || isset($offG9[$r]);
            $fixG3 = pp_get_fixed_load($model, 'g3', $r + 1) >= 0;
            $fixB1 = pp_get_fixed_load($model, 'b1', $r + 1) >= 0; $fixB2 = pp_get_fixed_load($model, 'b2', $r + 1) >= 0;
            $need = function () use (&$genRows, $r, $busOf, $busMin, $expOf, $ieVals, $rMinR) {
                return ($busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) || ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6);
            };
            while ($need() && $bg++ < 4000) {
                $g8 = (float)($genRows[$r]['g8'] ?? 0); $g9 = (float)($genRows[$r]['g9'] ?? 0);
                $canG8 = !$fixG8 && $g8 < $GMAX - 1e-6; $canG9 = !$fixG9 && $g9 < $GMAX - 1e-6;
                if ($canG8 || $canG9) {                                  // raise g8/g9 INDEPENDENTLY (a Stop/Start-At-locked unit is left as-is)
                    if ($canG8) $genRows[$r]['g8'] = min($GMAX, max($g8, $GMIN) + 0.5);
                    if ($canG9) $genRows[$r]['g9'] = min($GMAX, max($g9, $GMIN) + 0.5);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                } elseif (!$fixG3 && !($r === 0 && ($lastStatus['g3'] ?? '') === 'stop') && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6 && (float)($genRows[$r]['g3'] ?? 0) < $g3max - 1e-6) {
                    $genRows[$r]['g3'] = min($g3max, (float)($genRows[$r]['g3'] ?? $g3mcc) + 0.5);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                } else {
                    if ($busOf($genRows[$r], $ieVals[$r]) >= $busMin - 1e-6) break;          // export-only deficit, no lever left
                    $b1 = (float)($genRows[$r]['b1'] ?? 0); $b2 = (float)($genRows[$r]['b2'] ?? 0);
                    if ((($fixB1 || $b1 <= 1e-6) && ($fixB2 || $b2 <= 1e-6))) break;
                    $t = $genRows[$r];
                    if (!$fixB1 && $b1 >= $b2 && $b1 > 0) $t['b1'] = max(0.0, $b1 - 0.5); elseif (!$fixB2 && $b2 > 0) $t['b2'] = max(0.0, $b2 - 0.5); else break;
                    if ($expOf($t, $ieVals[$r]) < $rMinR[$r] - 1e-6) break;      // would tank export -> leave row as-is
                    $genRows[$r] = $t; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
            /* PATCH G05 — evidence exhaustion BusFlow: seluruh lever habis (G8 max/ramp, G9 terkunci
             * Start-At/Stop, G3 max, trim Babelan menembus export floor) tetapi BusFlow masih di bawah
             * minimum. Emit row-level agar validator klasifikasi VALID-INFEASIBLE, bukan FAIL diam. */
            if ($busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                $warnings[] = sprintf('Bus Flow below minimum row %d: BusFlow %.2f < %.2f MW — repair exhausted (G8 di max/ramp, G9 di-hold komitmen Start-At/Stop, G3 di max, penurunan Babelan menembus PLN Export Range Min).', $r + 1, $busOf($genRows[$r], $ieVals[$r]), $busMin);
            }
        }
    }

    /* ---- Unit ramp-rate hard constraint (Revisi V3/V6) --------------------------------------------------
     * G8/G9 in Combined Cycle: |load[t]-load[t-1]| <= 30 MW/30min. G7/G10 Simple Cycle: <= 55 MW/30min.
     * Start-up steps are exempt (the start-up sequence governs that window); after release the normal ramp
     * applies. Smooth a too-fast DROP by lifting the later row to within the limit (kept <= Range Max); a Stop/
     * Start-At unit's start-up ramp window is left untouched. Runs BEFORE the daily up-dispatch so the up-
     * dispatch's <= quota guard stays the final word on Total Gas (the smoothing lift also adds a little gas). */
    $startWin = function (string $u) use ($lastStatus, $model, $genRows, $n): array {
        if (($lastStatus[$u] ?? '') !== 'stop') return [];
        $rmU = ($model['required_mode'][$u] ?? $model['required_mode'][strtoupper($u)] ?? null);
        $sr = -1;
        if (is_array($rmU) && strtolower((string)($rmU['mode'] ?? '')) === 'start_at'
            && preg_match('/^(\d{1,2}):(\d{2})$/', (string)($rmU['at'] ?? $rmU['start_at'] ?? ''), $mm)) $sr = (int)floor(((int)$mm[1]*60+(int)$mm[2])/30);
        else for ($r = 1; $r < $n; $r++) { if (($genRows[$r][$u] ?? 0) >= 1) { $sr = $r; break; } }
        $w = []; if ($sr >= 0) for ($r = max(0,$sr-1); $r <= $sr + 6 && $r < $n; $r++) $w[$r] = true;
        return $w;
    };
    foreach (['g8' => 30.0, 'g9' => 30.0, 'g7' => 55.0, 'g10' => 55.0] as $u => $lim) {
        if (!pp_unit_present($d3, $u)) continue;
        $win = $startWin($u);
        for ($r = 1; $r < $n; $r++) {
            if (isset($actualRows[$r]) || isset($actualRows[$r-1]) || isset($win[$r]) || isset($win[$r-1])) continue;
            $prev = (float)($genRows[$r-1][$u] ?? 0); $cur = (float)($genRows[$r][$u] ?? 0);
            if ($prev < 1.0 || $cur < 1.0) continue;                          // a genuine OFF<->ON is a stop/start, not a ramp
            if ($cur < $prev - $lim - 1e-6) {                                 // too-fast DROP -> lift later row to within limit
                $tgt = $prev - $lim; $sv = $genRows[$r];
                $genRows[$r][$u] = $tgt; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }  // would break Range Max -> leave
            } elseif ($cur > $prev + $lim + 1e-6) {                           // too-fast RISE (non-start-up) -> trim to within limit if Export still >= Min
                $tgt = $prev + $lim; $sv = $genRows[$r];
                $genRows[$r][$u] = $tgt; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }
            }
        }
    }

    /* ---- Babelan ramp-rate hard constraint (<=5 MW/30min PER UNIT: BB1, BB2) -----------------------------
     * The FINAL BusFlow + Range-Min repair above can trim b1/b2 (to rebalance Bus A vs Bus B) without
     * checking the Babelan ramp limit. Smooth any resulting >5MW/30min step by lifting the lower side back
     * toward the previous row's value, within the limit — kept inside Range Min/Max and BusFlow valid; if
     * that is not achievable the row is left as-is (a genuine physical conflict, not silently hidden). */
    foreach (['b1', 'b2'] as $u) {
        if (!pp_unit_present($d3, $u)) continue;
        for ($pass = 0; $pass < 3; $pass++) {
            $changed = false;
            for ($r = 1; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($actualRows[$r - 1])) continue;
                $prev = (float)($genRows[$r - 1][$u] ?? 0); $cur = (float)($genRows[$r][$u] ?? 0);
                if (abs($cur - $prev) <= $bbRamp + 1e-6) continue;
                $tgt = $cur > $prev ? $prev + $bbRamp : $prev - $bbRamp;
                $sv = $genRows[$r];
                $genRows[$r][$u] = max(0.0, $tgt);
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $e = $expOf($genRows[$r], $ieVals[$r]);
                $ok = !($e < $rMinR[$r] - 1e-6 || $e > $rMaxR[$r] + 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6);
                if (!$ok) {
                    // Compensate with G8/G9 (30 MW/30min budget, usually has headroom) so Export/BusFlow stay
                    // put while Babelan's own jump shrinks to within its 5 MW limit — avoids trading one hard
                    // constraint for another.
                    $g8v = (float)($genRows[$r]['g8'] ?? 0); $g9v = (float)($genRows[$r]['g9'] ?? 0);
                    $prevG8 = (float)($genRows[$r - 1]['g8'] ?? $g8v); $bump = 0;
                    while (!$ok && !$isFixed('g8', $r) && !$isFixed('g9', $r) && $bump < 60) {
                        $e2 = $expOf($genRows[$r], $ieVals[$r]);
                        if ($e2 < $rMinR[$r] - 1e-6 && $genRows[$r]['g8'] < $GMAX - 1e-6 && $genRows[$r]['g8'] < $prevG8 + 30.0 - 1e-6) {
                            $genRows[$r]['g8'] = min($GMAX, $genRows[$r]['g8'] + 0.5); $genRows[$r]['g9'] = min($GMAX, $genRows[$r]['g9'] + 0.5);
                        } elseif ($e2 > $rMaxR[$r] + 1e-6 && $genRows[$r]['g8'] > $GMIN + 1e-6) {
                            $genRows[$r]['g8'] = max($GMIN, $genRows[$r]['g8'] - 0.5); $genRows[$r]['g9'] = max($GMIN, $genRows[$r]['g9'] - 0.5);
                        } else { break; }
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $e3 = $expOf($genRows[$r], $ieVals[$r]);
                        $ok = !($e3 < $rMinR[$r] - 1e-6 || $e3 > $rMaxR[$r] + 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6);
                        $bump++;
                    }
                    if (!$ok) { $genRows[$r]['g8'] = $g8v; $genRows[$r]['g9'] = $g9v; $genRows[$r][$u] = $sv[$u]; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); }
                }
                if ($ok) { $changed = true; continue; }
                $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);   // later-row trim (with G8/G9 compensation) infeasible -> try raising the earlier row instead
                $tgt2 = $cur > $prev ? $cur - $bbRamp : $cur + $bbRamp;
                $sv2 = $genRows[$r - 1];
                $genRows[$r - 1][$u] = max(0.0, $tgt2);
                pp_recompute_stgs($genRows[$r - 1], $d3, $model, $r);
                $e2 = $expOf($genRows[$r - 1], $ieVals[$r - 1]);
                $prev2 = (float)($genRows[$r - 2][$u] ?? $genRows[$r - 1][$u]);   // avoid creating a NEW ramp break vs row r-2
                $ok2 = !($e2 < $rMinR[$r - 1] - 1e-6 || $e2 > $rMaxR[$r - 1] + 1e-6 || $busOf($genRows[$r - 1], $ieVals[$r - 1]) < $busMin - 1e-6
                    || ($r - 2 >= 0 && !isset($actualRows[$r - 2]) && abs($genRows[$r - 1][$u] - $prev2) > $bbRamp + 1e-6));
                if (!$ok2) {
                    // same G8/G9 compensation, applied to row r-1
                    $g8v = (float)($genRows[$r - 1]['g8'] ?? 0); $g9v = (float)($genRows[$r - 1]['g9'] ?? 0);
                    $prevG8b = (float)($genRows[$r - 2]['g8'] ?? $g8v); $bump = 0;
                    while (!$ok2 && !$isFixed('g8', $r - 1) && !$isFixed('g9', $r - 1) && $bump < 60) {
                        $e3 = $expOf($genRows[$r - 1], $ieVals[$r - 1]);
                        if ($e3 < $rMinR[$r - 1] - 1e-6 && $genRows[$r - 1]['g8'] < $GMAX - 1e-6 && $genRows[$r - 1]['g8'] < $prevG8b + 30.0 - 1e-6) {
                            $genRows[$r - 1]['g8'] = min($GMAX, $genRows[$r - 1]['g8'] + 0.5); $genRows[$r - 1]['g9'] = min($GMAX, $genRows[$r - 1]['g9'] + 0.5);
                        } elseif ($e3 > $rMaxR[$r - 1] + 1e-6 && $genRows[$r - 1]['g8'] > $GMIN + 1e-6) {
                            $genRows[$r - 1]['g8'] = max($GMIN, $genRows[$r - 1]['g8'] - 0.5); $genRows[$r - 1]['g9'] = max($GMIN, $genRows[$r - 1]['g9'] - 0.5);
                        } else { break; }
                        pp_recompute_stgs($genRows[$r - 1], $d3, $model, $r);
                        $e4 = $expOf($genRows[$r - 1], $ieVals[$r - 1]);
                        $ok2 = !($e4 < $rMinR[$r - 1] - 1e-6 || $e4 > $rMaxR[$r - 1] + 1e-6 || $busOf($genRows[$r - 1], $ieVals[$r - 1]) < $busMin - 1e-6);
                        $bump++;
                    }
                    if (!$ok2) { $genRows[$r - 1]['g8'] = $g8v; $genRows[$r - 1]['g9'] = $g9v; }
                }
                if (!$ok2) {
                    $genRows[$r - 1] = $sv2; pp_recompute_stgs($genRows[$r - 1], $d3, $model, $r);   // neither direction feasible even with G8/G9 -> leave (genuine conflict)
                }
                else { $changed = true; }
            }
            if (!$changed) break;
        }
    }

    /* ---- Babelan priority-1 baseload (Revisi V7) -------------------------------------------------------
     * Babelan (b1/b2) is gas-free PLTU and Unit-Priority #1, so it is run as high as possible: maximise it
     * toward effective max on every row where Export stays <= Range Max and BusFlow >= min, then build a
     * ramp-feasible trajectory with |Δ| <= 5 MW/30min PER UNIT (<= 10/row total). Gas-free, so this does not
     * touch the gas quota; it only shifts cheap MWh onto the highest-priority unit. Skips actual + fixed rows. */
    {
        $b1mxRaw = (float)($d3['b1']['max_load'] ?? 120); $b2mxRaw = (float)($d3['b2']['max_load'] ?? 120);
        /* PATCH G31 (re-apply): Babelan baseload fill dibatasi EFFECTIVE max PER ROW (Max Load Adjustment),
         * bukan raw max_load sekali hitung. Cap per row disimpan agar seluruh tahap (fill, ramp smoothing,
         * trajectory) tidak pernah menembus effective max pada row mana pun. */
        $b1mxR = []; $b2mxR = [];
        for ($r = 0; $r < $n; $r++) {
            $e1 = pp_effective_maxload($d3, $model, 'b1', $r + 1); $b1mxR[$r] = $e1 > 0 ? min($b1mxRaw, $e1) : $b1mxRaw;
            $e2 = pp_effective_maxload($d3, $model, 'b2', $r + 1); $b2mxR[$r] = $e2 > 0 ? min($b2mxRaw, $e2) : $b2mxRaw;
        }
        $bbCap = []; $bbFix = []; $bbOrig = [];
        for ($r = 0; $r < $n; $r++) {
            $b1mx = $b1mxR[$r]; $b2mx = $b2mxR[$r]; $bbMx = $b1mx + $b2mx;
            $cur = (float)($genRows[$r]['b1'] ?? 0) + (float)($genRows[$r]['b2'] ?? 0); $bbOrig[$r] = $cur;
            $fixB1 = pp_get_fixed_load($model, 'b1', $r + 1) >= 0; $fixB2 = pp_get_fixed_load($model, 'b2', $r + 1) >= 0;
            $bbFix[$r] = ($fixB1 && $fixB2);
            if (isset($actualRows[$r]) || $bbFix[$r]) { $bbCap[$r] = $cur; continue; }
            $cap = $cur; $t = $genRows[$r];
            for ($bb = $cur; $bb <= $bbMx + 1e-6; $bb += 1.0) {
                $t['b1'] = $fixB1 ? (float)$genRows[$r]['b1'] : min($b1mx, $bb / 2);
                $t['b2'] = $fixB2 ? (float)$genRows[$r]['b2'] : min($b2mx, $bb / 2);
                if ($expOf($t, $ieVals[$r]) > $rMaxR[$r] + 1e-6) break;       // would push Export over Range Max
                if ($busOf($t, $ieVals[$r]) < $busMin - 1e-6) break;                       // BusFlow floor
                $cap = $t['b1'] + $t['b2'];
            }
            $bbCap[$r] = $cap;
        }
        // Build a ramp-feasible (<=10/row total = 5/unit) baseload trajectory: start from the per-row cap
        // (maximised), then enforce |Δ| <= 10 by LOWERING the higher side of any violating pair — but never so
        // far that Export drops below Range Min. Residual >10 steps are therefore export-Min-forced (the only
        // way to honour the ramp would break the export floor) and are reported row-level as valid-infeasible.
        $bb = $bbCap;
        $bbExp = function (int $r, float $bbv) use ($genRows, $b1mxR, $b2mxR, $expOf, $ieVals) {
            $t = $genRows[$r]; $t['b1'] = min($b1mxR[$r], $bbv / 2); $t['b2'] = min($b2mxR[$r], $bbv / 2); return $expOf($t, $ieVals[$r]);   // PATCH G31: effective max per row
        };
        $lowerTo = function (int $r, float $target) use (&$bb, $bbExp, $rMinR) {       // lower bb[r] toward target while Export >= Min
            while ($bb[$r] > $target + 1e-9) { $try = max($target, $bb[$r] - 2.0); if ($bbExp($r, $try) < $rMinR[$r] - 1e-6) break; $bb[$r] = $try; }
        };
        for ($it = 0; $it < 3 * $n; $it++) { $ch = false;
            for ($r = 1; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($actualRows[$r-1]) || $bbFix[$r] || $bbFix[$r-1]) continue;
                if (abs($bb[$r] - $bb[$r-1]) <= 10.0 + 1e-9) continue;
                if ($bb[$r] > $bb[$r-1]) { $b0 = $bb[$r]; $lowerTo($r, $bb[$r-1] + 10.0); if ($bb[$r] < $b0 - 1e-9) $ch = true; }
                else { $b0 = $bb[$r-1]; $lowerTo($r-1, $bb[$r] + 10.0); if ($bb[$r-1] < $b0 - 1e-9) $ch = true; }
            }
            if (!$ch) break;
        }
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r]) || $bbFix[$r]) continue;
            $fixB1 = pp_get_fixed_load($model, 'b1', $r + 1) >= 0; $fixB2 = pp_get_fixed_load($model, 'b2', $r + 1) >= 0;
            if (!$fixB1) $genRows[$r]['b1'] = min($b1mxR[$r], $bb[$r] / 2);   // PATCH G31: effective max per row
            if (!$fixB2) $genRows[$r]['b2'] = min($b2mxR[$r], $bb[$r] / 2);
            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
        }
    }

    /* ---- FINAL daily up-dispatch to gas quota (Revisi V3) ------------------------------------------------
     * The shaper-stage gas top-up estimates Export with g8/g9 still high; once Start-At/Stop zeroes them the
     * real Export is lower, leaving running gas levers with un-used headroom. Re-run the up-dispatch on the
     * FINAL dispatch: raise the load-dependent gas levers (G3 — running, cannot-stop, cheapest PGN — then any
     * already-running G4/G6 additional-HRSG) on the rows that still have Export room below Range Max, until true
     * Total Gas reaches [quota-0.04, quota]. G8/G9/G10 are load-dependent (V6) and ARE used as gas levers here. */
    $trueGasFinal = function () use (&$genRows, $d3, $model, $n, &$__FC) {
        /* OPT-S1/P1: jumlah bahan bakar lewat cache sel (urutan identik); penalti start-up bernilai
         * nol mutlak sehingga penambahannya tidak mengubah nilai sama sekali. */
        $g = pp_fuel_sum_g19($genRows, $d3, $n, $__FC); $g /= 2.0;
        return $g;   // matches worker02 reported Total Gas Used (calc_fuel/2 + startup penalty)
    };
    if (!$ddActive && !$forceSAQ) {
        $gNow = $trueGasFinal();
        if ($gNow < 0.0) {
            // Merit-order daily up-dispatch (Revisi V7): raise the gas levers in Unit-Priority order — highest
            // priority / lowest marginal cost first. In this plant Unit Priority is [b1,b2] > [g9,g8,s3] > ... >
            // [g3,g4,g6,s1] > [g1,g2,g5], and marginal cost confirms G8/G9 (~70.6 USD/MWh) < G3 (~75.5) < G1-G6
            // (~88.8), so G8/G9 are raised BEFORE G3/G1-G6. Babelan (priority 1, gas-free) is maximised separately
            // by the baseload pass above. ramp = per-step limit (G8/G9 CC 30, G10 SC 55, others none).
            $rampOf = function (string $u): float { return ($u === 'g8' || $u === 'g9') ? 30.0 : (($u === 'g7' || $u === 'g10') ? 55.0 : 0.0); };
            $maxOf  = function (string $u) use ($d3, $g3max, $g4max, $g6max): float {
                if ($u === 'g3') return $g3max; if ($u === 'g4') return $g4max; if ($u === 'g6') return $g6max;
                return (float)($d3[$u]['max_load'] ?? 108);
            };
            $upLevers = []; $seen = [];
            foreach ([pp_priority_flat($model)] as $grp) {                       // follow Unit Priority order
                foreach ((array)$grp as $u) { $u = strtolower((string)$u);
                    if (!in_array($u, ['g1','g2','g3','g4','g5','g6','g8','g9','g10'], true)) continue;   // gas levers only (b/ge/stg handled elsewhere)
                    if (isset($seen[$u]) || !pp_unit_present($d3, $u)) continue;
                    $seen[$u] = true; $upLevers[] = [$u, $maxOf($u), $rampOf($u)];
                }
            }
            if (!$upLevers && pp_unit_present($d3,'g3')) $upLevers[] = ['g3', $g3max, 0.0];   // fallback
            $upWin = ['g8' => $startWin('g8'), 'g9' => $startWin('g9')];   // never raise g8/g9 inside their start-up ramp window
            foreach ($upLevers as $lev) {
                [$u, $umax, $ramp] = $lev; $guard = 0;
                while ($gNow < 0.0 && $guard++ < 30000) {
                    $bestR = -1; $bestHead = 0.6;
                    for ($r = 0; $r < $n; $r++) {
                        if (isset($actualRows[$r])) continue;
                        if (isset($upWin[$u][$r])) continue;                         // start-up sequence governs this row
                        if (($genRows[$r][$u] ?? 0) < 1.0) continue;                 // only raise where the unit is already online
                        if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
                        $em = pp_effective_maxload($d3, $model, $u, $r + 1); $cap = min($umax, $em > 0 ? $em : $umax);
                        if ($ramp > 0) {                                              // ramp cap: stay within +limit of the lower neighbour
                            $nb = $cap;
                            if ($r > 0 && ($genRows[$r-1][$u] ?? 0) >= 1) $nb = min($nb, (float)$genRows[$r-1][$u] + $ramp);
                            if ($r < $n-1 && ($genRows[$r+1][$u] ?? 0) >= 1) $nb = min($nb, (float)$genRows[$r+1][$u] + $ramp);
                            $cap = min($cap, $nb);
                        }
                        if (($genRows[$r][$u] ?? 0) >= $cap - 1e-6) continue;
                        $head = $rMaxR[$r] - $expOf($genRows[$r], $ieVals[$r]);
                        if ($head > $bestHead) { $bestHead = $head; $bestR = $r; }
                    }
                    if ($bestR < 0) break;
                    $before = calc_fuel($d3, $u, (float)$genRows[$bestR][$u]) / 2.0;
                    $em = pp_effective_maxload($d3, $model, $u, $bestR + 1); $cap = min($umax, $em > 0 ? $em : $umax);
                    if ($ramp > 0) { $nb = $cap; if ($bestR > 0 && ($genRows[$bestR-1][$u] ?? 0) >= 1) $nb = min($nb, (float)$genRows[$bestR-1][$u] + $ramp); if ($bestR < $n-1 && ($genRows[$bestR+1][$u] ?? 0) >= 1) $nb = min($nb, (float)$genRows[$bestR+1][$u] + $ramp); $cap = min($cap, $nb); }
                    $genRows[$bestR][$u] = min($cap, (float)$genRows[$bestR][$u] + 0.5);
                    pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
                    $after = calc_fuel($d3, $u, (float)$genRows[$bestR][$u]) / 2.0;
                    $gNow += ($after - $before);
                    if ($gNow > $quota + 1e-9) { $genRows[$bestR][$u] = max(0.0, (float)$genRows[$bestR][$u] - 0.5); pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1); $gNow -= ($after - $before); break 2; }
                }
            }
        }
    }

    /* ---- FINAL Babelan ramp cleanup (Revisi V7) --------------------------------------------------------
     * The gas up-dispatch above raises G8/G9/G3 (lifting Export), which frees room that may not have existed
     * when the Babelan baseload pass ran. Re-enforce |Δ Babelan| <= 10/row (5/unit) against the FINAL export by
     * lowering the higher side of any residual violating pair, as long as Export stays >= Range Min. Gas-free. */
    {
        $b1mxF = function (int $r) use ($d3, $model) { $e = pp_effective_maxload($d3, $model, 'b1', $r + 1); return $e > 0 ? $e : (float)($d3['b1']['max_load'] ?? 120); };   // PATCH G31
        $b2mxF = function (int $r) use ($d3, $model) { $e = pp_effective_maxload($d3, $model, 'b2', $r + 1); return $e > 0 ? $e : (float)($d3['b2']['max_load'] ?? 120); };
        $bbFixR = function (int $r) use ($model): bool { return pp_get_fixed_load($model, 'b1', $r + 1) >= 0 && pp_get_fixed_load($model, 'b2', $r + 1) >= 0; };
        $bbExpF = function (int $r, float $bbv) use ($genRows, $b1mxF, $b2mxF, $expOf, $ieVals) {
            $t = $genRows[$r]; $t['b1'] = min($b1mxF($r), $bbv / 2); $t['b2'] = min($b2mxF($r), $bbv / 2); return $expOf($t, $ieVals[$r]); };
        $bbSet = function (int $r, float $bbv) use (&$genRows, $b1mxF, $b2mxF, $d3, $model) {
            $genRows[$r]['b1'] = min($b1mxF($r), $bbv / 2); $genRows[$r]['b2'] = min($b2mxF($r), $bbv / 2); pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); };
        for ($it = 0; $it < 3 * $n; $it++) { $ch = false;
            for ($r = 1; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($actualRows[$r-1]) || $bbFixR($r) || $bbFixR($r-1)) continue;
                $bbA = (float)$genRows[$r-1]['b1'] + (float)$genRows[$r-1]['b2']; $bbB = (float)$genRows[$r]['b1'] + (float)$genRows[$r]['b2'];
                if (abs($bbB - $bbA) <= 10.0 + 1e-9) continue;
                $hi = $bbB > $bbA ? $r : $r - 1; $target = ($bbB > $bbA ? $bbA : $bbB) + 10.0; $cur = max($bbA, $bbB);
                while ($cur > $target + 1e-9) { $try = max($target, $cur - 2.0); if ($bbExpF($hi, $try) < $rMinR[$hi] - 1e-6) break; $bbSet($hi, $try); $cur = $try; $ch = true; }
            }
            if (!$ch) break;
        }
    }

    /* Final micro gas-trim (runs BEFORE ramp smoothing, AFTER every other repair pass): earlier passes above
     * (Babelan ramp G8/G9 compensation, required-unit re-assertion, fixed-load override) can each add a small
     * amount of gas that was not accounted for by the main quota bisection. Absorb any residual overage here —
     * trimming g8/g9 on the highest-Export-margin rows — so ramp smoothing below starts from an
     * already-in-quota baseline and only needs to stay gas-neutral-or-better, never needing to add gas back. */
    $trueGasNow = function () use (&$genRows, $d3, $model, $n, &$__FC) {
        $g = pp_fuel_sum_g19($genRows, $d3, $n, $__FC);
        return $g / 2.0 + pp_startup_gas_penalty($genRows, $model, true);   // Jababeka-only: G10 start gas = KP72 side
    };
    {
        $guardFinal = 0; $exhausted = [];
        while ($trueGasNow() > $quota - 0.038 && $guardFinal++ < 400) {
            $bestR = -1; $bestMargin = 0.1;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($exhausted[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) continue;
                if ((float)($genRows[$r]['g8'] ?? 0) <= $GMIN + 1e-6) continue;
                $margin = $expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r];
                if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
            }
            if ($bestR < 0) {
                /* PROMPT SISTEMIK §8/§9 — LEVER PAIRED (generik): bila TIDAK ada row dgn margin export,
                 * turunkan unit gas (G8/G9) DAN naikkan Babelan pada row yang SAMA sehingga export tetap
                 * di dalam Range — gas turun menuju window TANPA menekan Babelan (Priority 1 baseload).
                 * Guard: Babelan effective max, ramp Babelan (helper), Bus Flow min, Export Range, fixed/
                 * actual/stop rows. Berhenti bila tidak ada pasangan yang valid (exhaustion nyata). */
                $pairedDone = false;
                $bbRampOQ = pp_babelan_ramp_limit($model);
                for ($r = 0; $r < $n && !$pairedDone; $r++) {
                    if (isset($actualRows[$r]) || isset($exhausted['p' . $r])) continue;
                    foreach ([['g9', 'b2'], ['g9', 'b1'], ['g8', 'b2'], ['g8', 'b1']] as $pr) {
                        [$gU, $bU] = $pr;
                        if ($isFixed($gU, $r) || $isFixed($bU, $r)) continue;
                        if (pp_is_unit_stopped($d3, $model, $bU, $r + 1)) continue;
                        $gv = (float)($genRows[$r][$gU] ?? 0); $bv = (float)($genRows[$r][$bU] ?? 0);
                        if ($gv <= $GMIN + 1e-6 || $bv < 0.01) continue;              // gas unit hrs > min; Babelan hrs ON
                        $bMax = pp_effective_maxload($d3, $model, $bU, $r + 1);
                        if ($bMax <= 0) $bMax = (float)($d3[$bU]['max_load'] ?? 120);
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            $bMax = min($bMax, (float)($genRows[$nb][$bU] ?? 0) + $bbRampOQ); }
                        if ($bv + 0.5 > $bMax + 1e-9) continue;
                        $sv2 = $genRows[$r];
                        $genRows[$r][$gU] = max($GMIN, $gv - 0.5);
                        $genRows[$r][$bU] = $bv + 0.5;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $e2 = $expOf($genRows[$r], $ieVals[$r]);
                        if ($e2 < $rMinR[$r] - 1e-6 || $e2 > $rMaxR[$r] + 1e-6
                            || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                            $genRows[$r] = $sv2; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            continue;
                        }
                        $pairedDone = true; break;
                    }
                    if (!$pairedDone) $exhausted['p' . $r] = true;
                }
                if (!$pairedDone) break;   // seluruh lever (margin & paired) habis -> exhaustion nyata
                continue;
            }
            $sv = $genRows[$bestR];
            $genRows[$bestR]['g8'] = ((float)$genRows[$bestR]['g8'] <= 0.0) ? 0.0 : max($GMIN, (float)$genRows[$bestR]['g8'] - 0.5);
            $genRows[$bestR]['g9'] = ((float)$genRows[$bestR]['g9'] <= 0.0) ? 0.0 : max($GMIN, (float)$genRows[$bestR]['g9'] - 0.5);
            pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            if ($busOf($genRows[$bestR], $ieVals[$bestR]) < $busMin - 1e-6 || $expOf($genRows[$bestR], $ieVals[$bestR]) < $rMinR[$bestR] - 1e-6) {
                $genRows[$bestR] = $sv; pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
                $exhausted[$bestR] = true;   // this row can't absorb any more -> try the next-best row instead
            }
        }
    }

    /* ---- FINAL startup-ramp-cap re-enforcement (runs after every other repair pass that could have moved a
     * unit) -------------------------------------------------------------------------------------------------
     * The gas top-up levers, the Manual Fixed Load override, and the Required-unit re-assertion can each
     * independently commit a Stop-status unit to a value above its Additional-HRSG/STG start-up cap on its
     * first on-row, undoing the earlier "Unit Last Data Status" ramp enforcement. Re-apply the SAME cap
     * sequence one final time, for every g1-g9 unit whose Last Data Status is Stop, so no later pass can
     * leave an un-ramped start in the output. Operator-fixed rows are exempt (fixed load is authoritative). */
    {
        /* ADDENDUM FINAL Bagian 6 (root cause): urutan final sebelumnya HARDCODE [5,15] (g1-6) /
         * [40,40,50,60,60,70] (g8/9), sehingga langkah Cold yang lebih panjang (mis. S1 Cold =
         * [5,15,20,20,20,20,20]) tidak ditegakkan dan GTG bisa lompat ke 23.5/31 MW di dalam window.
         * Sekarang memakai pp_startup_caps() aktual sesuai STG Start Up Mode yang dipilih operator. */
        $smodeF = $model['stg_startup_mode'] ?? [];
        $seqOfFinal = function (string $u) use ($d3, $smodeF): array {
            $stgU = pp_gtg_to_stg($d3, $u);
            $mode = $stgU !== '' ? (string)($smodeF[$stgU . '_startup'] ?? 'Cold') : 'Hot';
            return array_map('floatval', pp_startup_caps($stgU !== '' ? $stgU : $u, $mode));
        };
        $suCap = [];   /* $suCap[$u][$row] = cap MW — startup-sequence windows.
         * ZERO TOLERANCE: loop pengisi LAMA di sini DIHAPUS — ia mengevaluasi klasifikasi Additional-vs-STG
         * pada state SEBELUM penolan row-1 (STG masih tampak hidup dari GTG yang akan dinolkan), lalu
         * bertabrakan dgn applyStgHold fase (a); min() antar keduanya menghasilkan caps campuran dan lever
         * kompensasi ter-clamp turun. applyStgHold (dipanggil dua kali) kini penegak TUNGGAL. */
        // g8/g9 caps as a per-row clamp for the smoothing passes (which move g8 and g9 together):
        //   - inside a startup-sequence window -> clamp to the sequence cap;
        //   - on a stopped/held row (Stop window, Start-At hold) -> clamp to 0 (universal hard lock).
        /* PROMPT FINAL (root cause S07): $suCap WAJIB by-reference — panggilan kedua applyStgHold
         * menambah entri caps utk restart intra-day; capture by-value membuat lever kompensasi buta
         * terhadap caps baru dan menaikkan G8/G9 melewati window Additional HRSG. */
        $g89Cap = function (int $r, string $u, float $v) use (&$suCap, $d3, $model, $GMAX): float {
            if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) return 0.0;   // ZERO TOLERANCE §6
            return isset($suCap[$u][$r]) ? min($v, $suCap[$u][$r]) : $v;
        };
    }

    /* ---- Export ramp smoothing via G8/G9 (<=30 MW/30min hard) --------------------------------------------
     * A fixed-load boundary (operator jumps a unit straight to/from its manual value), a large natural swing
     * in the IE input series, or another late repair can leave a >30MW/30min step in Export that no earlier
     * pass targets directly. Smooth it by nudging G8/G9 at the row with less "pull" — G8/G9 share the same
     * 30MW/30min budget, so a same-direction opposite adjustment can absorb most or all of the jump. GAS-AWARE:
     * a step that RAISES G8/G9 (adds gas) is rejected if it would push Total Gas over quota, so this pass
     * never undoes the gas-trim above; a step that LOWERS G8/G9 (removes gas) is always safe on that front.
     * Never touches an operator-fixed G8/G9 row. Any row this cannot resolve — G8/G9 already at a bound, at
     * its own 30MW budget vs the neighbour, or blocked by Range Min/Max/BusFlow/gas-quota — is left as a
     * genuine, evidenced physical conflict (row-level detail below) rather than silently overridden. */
    $rampUnresolved = [];
    /* Gas DONOR (Master Audit Bagian E — ramp feasibility at a Range-rule step): when the smoothing
     * below must RAISE G8/G9 on a pre-ramp row but Total Gas is already at the quota cap, the gas is
     * REALLOCATED instead of added: trim G8/G9 on the row with the LARGEST export slack above its own
     * per-row Range Min (never a fixed/actual row, never the two rows being smoothed, never below GMIN,
     * never breaking the donor's own <=30MW ramp vs its neighbours). G8/G9 sit on Bus A, so lowering
     * them can never violate BusFlow (= IE - Bus B). Returns the BBTUD actually freed. */
    $donateGas = function (float $needBBTUD, array $exclude) use (&$genRows, $d3, $model, $n, $expOf, $ieVals, $rMinR, $GMIN, $isFixed, $actualRows): float {
        $freed = 0.0; $guard = 0;
        while ($freed < $needBBTUD - 1e-9 && $guard++ < 4000) {
            $bestR = -1; $bestSlack = 1.5;                       // require >1.5 MW slack so we never scrape Range Min
            for ($r = 0; $r < $n; $r++) {
                if (isset($exclude[$r]) || isset($actualRows[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) continue;
                $g8 = (float)($genRows[$r]['g8'] ?? 0);
                if ($g8 <= $GMIN + 1e-6) continue;
                // donor's own ramp budget vs neighbours after a -0.5 step (export moves ~-1.0 MW)
                $eCur = $expOf($genRows[$r], $ieVals[$r]);
                $okRamp = true;
                foreach ([$r - 1, $r + 1] as $nb) {
                    if ($nb < 0 || $nb >= $n) continue;
                    $eNb = $expOf($genRows[$nb], $ieVals[$nb]);
                    if (($eCur - 1.2) - $eNb < -30.0 + 1e-6) { $okRamp = false; break; }
                }
                if (!$okRamp) continue;
                $slack = $eCur - $rMinR[$r];
                if ($slack > $bestSlack) { $bestSlack = $slack; $bestR = $r; }
            }
            if ($bestR < 0) break;                               // no donor left with usable slack
            $g8 = (float)$genRows[$bestR]['g8']; $g9 = (float)$genRows[$bestR]['g9'];
            $before = calc_fuel($d3, 'g8', $g8) + calc_fuel($d3, 'g9', $g9);
            $n8 = max($GMIN, $g8 - 0.5); $n9 = $g9 > 0 ? max($GMIN, $g9 - 0.5) : 0.0;
            $genRows[$bestR]['g8'] = $n8; $genRows[$bestR]['g9'] = $n9;
            pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            if ($expOf($genRows[$bestR], $ieVals[$bestR]) < $rMinR[$bestR] - 1e-6) {   // safety: revert
                $genRows[$bestR]['g8'] = $g8; $genRows[$bestR]['g9'] = $g9;
                pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
                break;
            }
            $after = calc_fuel($d3, 'g8', $n8) + calc_fuel($d3, 'g9', $n9);
            $freed += ($before - $after) / 2.0;
        }
        return $freed;
    };
    /* Range-step LADDER pre-ramp (Master Audit Bagian E): when a Range-rule window makes the per-row
     * Range Min jump by more than 30 MW at a boundary row (e.g. rows 1-16 min 30 -> rows 17-30 min 100),
     * no single-pair adjustment can make the transition feasible: the export must CLIMB the step across
     * the preceding rows (a ladder: rMin[boundary]-30, -60, ...). The pairwise smoothing below cannot
     * discover this (it ping-pongs raising/lowering one row), so the ladder is built here explicitly,
     * BACKWARD from every upward boundary and FORWARD from every downward boundary, raising G8/G9 with
     * donor-funded gas (reallocation, never addition) and never exceeding each row's own Range Max. */
    for ($rB = 1; $rB < $n; $rB++) {
        $stepUp = $rMinR[$rB] - $rMinR[$rB - 1];
        if ($stepUp > 30.0 + 1e-6) {
            for ($k = 1; $rB - $k >= 0; $k++) {
                $rr2 = $rB - $k;
                $need = $rMinR[$rB] - 30.0 * $k;
                if ($need <= $rMinR[$rr2] + 1e-6) break;                    // ladder reached the local floor
                $tgt = min($need, $rMaxR[$rr2]);
                if (isset($actualRows[$rr2]) || $isFixed('g8', $rr2) || $isFixed('g9', $rr2)) break;
                $guardL = 0;
                while ($expOf($genRows[$rr2], $ieVals[$rr2]) < $tgt - 1e-6 && $guardL++ < 400) {
                    $cur8 = (float)($genRows[$rr2]['g8'] ?? 0);
                    if ($cur8 >= $GMAX - 1e-6) break;
                    $t8 = $g89Cap($rr2, 'g8', min($GMAX, $cur8 + 0.5));
                    $t9 = $g89Cap($rr2, 'g9', min($GMAX, (float)($genRows[$rr2]['g9'] ?? 0) + 0.5));
                    if (abs($t8 - $cur8) < 1e-9) break;                     // startup cap: no-op
                    $before = calc_fuel($d3, 'g8', $cur8) + calc_fuel($d3, 'g9', (float)($genRows[$rr2]['g9'] ?? 0));
                    $after  = calc_fuel($d3, 'g8', $t8)   + calc_fuel($d3, 'g9', $t9);
                    $needGas = $trueGasNow() + ($after - $before) / 2.0 - ($quota + 0.0005);
                    if ($needGas > 0) {
                        $donateGas($needGas + 0.002, [$rr2 => 1, $rB => 1]);
                        if ($trueGasNow() + ($after - $before) / 2.0 > $quota + 0.001) break;   // no donor left
                    }
                    $sv2 = $genRows[$rr2];
                    $genRows[$rr2]['g8'] = $t8; $genRows[$rr2]['g9'] = $t9;
                    pp_recompute_stgs($genRows[$rr2], $d3, $model, $rr2 + 1);
                    $eL = $expOf($genRows[$rr2], $ieVals[$rr2]);
                    if ($eL > $rMaxR[$rr2] + 1e-6 || $busOf($genRows[$rr2], $ieVals[$rr2]) < $busMin - 1e-6) {
                        $genRows[$rr2] = $sv2; pp_recompute_stgs($genRows[$rr2], $d3, $model, $rr2 + 1); break;
                    }
                }
            }
        }
    }
        /* ---- ADDENDUM FINAL Bagian 6: STG start-up DELAY window (S1/S2/S3) -----------------------------
         * Spec: Cold S1/S2 -> STG muncul 210 mnt (7 slot) setelah GTG pertama 5 MW, awal 20 MW, satu slot
         * kemudian bebas; Warm -> 60 mnt (2 slot); Hot -> 30 mnt (1 slot). S3 mengikuti window caps-nya.
         * DIBUNGKUS CLOSURE dan dipanggil DUA KALI: di sini dan lagi menjelang akhir shaper — karena pass
         * required/export lever bisa MENYALAKAN unit SETELAH titik ini (root cause S2/S3: saat call pertama
         * G1/G8 belum on sehingga fresh-start tidak terdeteksi). Juga menegakkan Unit Last Data Status =
         * Stop (row 1 wajib 0 MW) + caps GTG penuh per mode untuk start yang muncul belakangan. */
        $applyStgHold = function () use (&$genRows, &$model, &$warnings, &$suCap, $d3, $n, $isFixed,
                                         $lastStatus, $smodeF, $seqOfFinal, $expOf, $ieVals, $rMinR,
                                         $GMAX, $g89Cap, $trueGasNow, $donateGas, $quota, $actualRows) {
            /* (a) PROMPT FINAL §4/§5/§7: berlaku utk SETIAP transisi OFF->ON intra-day (bukan hanya
             * fresh-start dari kemarin). Untuk tiap start: bila STG blok sudah hidup pada row sebelumnya
             * -> caps Additional HRSG (G1-6: 5,15 ; G8/9: 40,40,50,60,60,70); bila belum -> caps STG
             * mode (Cold/Warm/Hot). Setelah window selesai, unit yang masih ON di bawah min-CC diangkat
             * ke min-CC (release-to-min) — 5/15 dsb hanya sah DI DALAM sequence. Row 1 dgn Last Data
             * Status Stop dinolkan. */
            /* g10 SENGAJA dikecualikan: G10/GE1-4 dikelola Cluster 4 (MM2100 daily quota) — pass
             * generik tidak boleh menaikkan/memperpanjang unit MM2100 (regresi kuota). */
            /* PRA-LOOP: nolkan row 1 utk SEMUA unit ber-Last-Status Stop SEBELUM deteksi transisi —
             * bila dilakukan per-unit di dalam loop, STG blok masih tampak hidup dari GTG saudaranya
             * yang belum dinolkan, sehingga start salah terklasifikasi Additional HRSG (caps salah). */
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $u0) {
                if (!isset($d3[$u0]) || ($lastStatus[$u0] ?? '') !== 'stop') continue;
                if ((float)($genRows[0][$u0] ?? 0) >= 0.01 && !$isFixed($u0, 0) && !isset($actualRows[0]))
                    $genRows[0][$u0] = 0.0;
            }
            pp_recompute_stgs($genRows[0], $d3, $model, 1);
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $u) {
                if (!isset($d3[$u])) continue;
                $stgU  = pp_gtg_to_stg($d3, $u);
                $mccU  = (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 5));
                for ($r = 0; $r < $n; $r++) {
                    $prevOn = $r > 0 ? ((float)($genRows[$r-1][$u] ?? 0) >= 0.01
                                        && !pp_is_unit_stopped($d3, $model, $u, $r))     // row ber-stop-schedule = OFF
                                     : (($lastStatus[$u] ?? '') === 'running');
                    if ($prevOn || (float)($genRows[$r][$u] ?? 0) < 0.01) continue;
                    if (pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
                    if (isset($actualRows[$r]) || $isFixed($u, $r)) continue;
                    $addl = false;
                    if ($stgU !== '') {
                        /* ZERO TOLERANCE (sinkron dgn validator): STG blok dianggap HIDUP hanya bila ada
                         * feed GTG lain yang on >= min-CC di row sebelumnya DAN tidak dalam window stop
                         * pada row itu — nilai pra-enforcement (mis. G9 yang akan dinolkan stop schedule)
                         * tidak boleh membuat start salah terklasifikasi Additional HRSG. */
                        $feedMapA = ['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']];
                        foreach (($feedMapA[strtolower($stgU)] ?? []) as $fA) {
                            if ($fA === $u || !isset($d3[$fA])) continue;
                            if ($r > 0 && !pp_is_unit_stopped($d3, $model, $fA, $r)
                                && (float)($genRows[$r-1][$fA] ?? 0) >= (float)($d3[$fA]['min_ccload'] ?? 20) - 0.01) { $addl = true; break; }
                        }
                    }
                    $seq = $addl ? array_map('floatval', pp_additional_hrsg_caps($u)) : $seqOfFinal($u);
                    $k = 0; $rr = $r;
                    foreach ($seq as $cap) {
                        if ($rr >= $n) break;
                        if ((float)($genRows[$rr][$u] ?? 0) < 0.01) break;
                        if (pp_is_unit_stopped($d3, $model, $u, $rr + 1)) break;
                        if (!$isFixed($u, $rr)) {
                            $suCap[$u][$rr] = isset($suCap[$u][$rr]) ? min($suCap[$u][$rr], (float)$cap) : (float)$cap;
                            /* ZERO TOLERANCE §9: langkah start-up adalah kurva TETAP — nilai dalam window
                             * wajib PERSIS langkah sequence (5,15,...), bukan angka acak di bawah cap. */
                            if (abs((float)$genRows[$rr][$u] - (float)$cap) > 0.01) {
                                $genRows[$rr][$u] = (float)$cap; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                            }
                        }
                        $rr++; $k++;
                    }
                    /* release-to-min: pasca window unit ON wajib >= min-CC */
                    for ($q = $rr; $q < $n; $q++) {
                        $lq = (float)($genRows[$q][$u] ?? 0);
                        if ($lq < 0.01) break;
                        if ($lq >= $mccU - 0.01) { break; }
                        if ($isFixed($u, $q) || isset($actualRows[$q]) || isset($suCap[$u][$q])) break;
                        $genRows[$q][$u] = $mccU; pp_recompute_stgs($genRows[$q], $d3, $model, $q + 1);
                    }
                    $r = $rr;                                                    // lompati window yang sudah ditangani
                }
            }
            /* (b) Hold map STG per blok fresh-start. */
            $model['__stg_hold'] = [];
            foreach (['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']] as $s => $feedG) {
                if (!isset($d3[$s])) continue;
                $modeS = (string)($smodeF[$s . '_startup'] ?? 'Cold');
                $capsS = pp_startup_caps($s, $modeS); $win = count($capsS);
                if ($win === 0) continue;                                        // No Start Up Sequence: bebas
                $sr = -1;
                for ($r = 0; $r < $n; $r++) { foreach ($feedG as $fg) { if ((float)($genRows[$r][$fg] ?? 0) >= 1.0) { $sr = $r; break 2; } } }
                if ($sr < 0) continue;                                            // blok tidak start hari ini
                if ($sr === 0 && (($lastStatus[$s] ?? '') === 'running')) continue;   // lanjutan kemarin, bukan fresh
                if ($sr > 0 && (float)($genRows[$sr - 1][$s] ?? 0) > 0.01) continue;  // Additional HRSG: STG sudah hidup
                for ($k = 0; $k < $win; $k++) {
                    $rr = $sr + $k; if ($rr >= $n) break;
                    $anyOn = false; foreach ($feedG as $fg) if ((float)($genRows[$rr][$fg] ?? 0) >= 1.0) { $anyOn = true; break; }
                    if (!$anyOn) break;                                           // start batal di tengah window
                    if ($isFixed($s, $rr)) continue;                              // operator-fixed STG row menang
                    $model['__stg_hold'][$s][$rr + 1] = 0.0;
                }
                $rrF = $sr + $win;                                                // row kemunculan pertama
                if ($rrF < $n && !$isFixed($s, $rrF) && ($s === 's1' || $s === 's2') && strtolower($modeS) === 'cold')
                    $model['__stg_hold'][$s][$rrF + 1] = 20.0;                    // spec 6.1: STG awal 20 MW (Cold)
            }
            if (!$model['__stg_hold']) return;
            for ($r = 0; $r < $n; $r++) pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            /* (c) Kompensasi (Constraint first): energi STG tertahan bisa menjatuhkan export < Range Min. */
            for ($r = 0; $r < $n; $r++) {
                $gcomp = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 0.3 && $gcomp++ < 200) {
                    $c8 = (float)($genRows[$r]['g8'] ?? 0);
                    if ($c8 >= $GMAX - 1e-6 || isset($actualRows[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) break;
                    if ($r === 0 && ((($lastStatus['g8'] ?? '') === 'stop') || (($lastStatus['g9'] ?? '') === 'stop'))) break;   // Last Status Stop: row 1 wajib 0
                    $t8 = $g89Cap($r, 'g8', min($GMAX, $c8 + 0.5));
                    $t9 = $g89Cap($r, 'g9', min($GMAX, (float)($genRows[$r]['g9'] ?? 0) + 0.5));
                    if ($t8 < $c8 - 1e-9) break;                                   // clamp di bawah nilai kini: jangan turunkan lewat jalur naik
                    $t9 = max($t9, (float)($genRows[$r]['g9'] ?? 0));
                    if (abs($t8 - $c8) < 1e-9) break;
                    $before = calc_fuel($d3, 'g8', $c8) + calc_fuel($d3, 'g9', (float)($genRows[$r]['g9'] ?? 0));
                    $after  = calc_fuel($d3, 'g8', $t8) + calc_fuel($d3, 'g9', $t9);
                    $needG = $trueGasNow() + ($after - $before) / 2.0 - ($quota + 0.0005);
                    if ($needG > 0) { $donateGas($needG + 0.002, [$r => 1]);
                        if ($trueGasNow() + ($after - $before) / 2.0 > $quota - 0.0005) break; }
                    $genRows[$r]['g8'] = $t8; $genRows[$r]['g9'] = $t9;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
                foreach (['g5', 'g1', 'g2', 'g3'] as $uU) {
                    $gcompU = 0;
                    while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 0.3 && $gcompU++ < 200) {
                        $cU = (float)($genRows[$r][$uU] ?? 0);
                        if ($cU < 1.0 || $isFixed($uU, $r) || isset($actualRows[$r])) break;
                        if (pp_is_unit_stopped($d3, $model, $uU, $r + 1)) break;        // ZERO TOLERANCE §6: window Stop At
                        if ($r === 0 && (($lastStatus[$uU] ?? '') === 'stop')) break;   // Last Status Stop: row 1 wajib 0
                        if (isset($suCap[$uU][$r]) && $cU >= (float)$suCap[$uU][$r] - 1e-6) break;
                        $mxU = pp_effective_maxload($d3, $model, $uU, $r + 1);
                        if ($cU >= $mxU - 1e-6) break;
                        $tU = min($mxU, $cU + 0.5);
                        if (isset($suCap[$uU][$r])) $tU = min($tU, (float)$suCap[$uU][$r]);
                        $befU = calc_fuel($d3, $uU, $cU); $aftU = calc_fuel($d3, $uU, $tU);
                        $needU = $trueGasNow() + ($aftU - $befU) / 2.0 - ($quota + 0.0005);
                        if ($needU > 0) { $donateGas($needU + 0.002, [$r => 1]);
                            if ($trueGasNow() + ($aftU - $befU) / 2.0 > $quota - 0.0005) break; }
                        $genRows[$r][$uU] = $tU;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    }
                }
                if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 0.5 && isset($model['__stg_hold']['s1'][$r + 1]) + isset($model['__stg_hold']['s2'][$r + 1]) + isset($model['__stg_hold']['s3'][$r + 1]) > 0)
                    $warnings[] = sprintf('PLN export below Range Min %.1f MW at row %d (%.1f MW): STG start-up sequence (mode window) holds the STG at 0 and no other unit had headroom — physical start-up limitation, evidenced.', $rMinR[$r], $r + 1, $expOf($genRows[$r], $ieVals[$r]));
            }
        };
        $applyStgHold();
    /* Cliff redistribution pre-pass: a multi-row steep decline/rise (3+ consecutive transitions all >30MW in
     * the same direction) cannot be fixed by adjusting one pair of rows at a time — fixing transition (i,i+1)
     * by moving row i+1 immediately re-breaks transition (i+1,i+2). Detect the contiguous span and retarget
     * every interior row to a linearly-interpolated Export value between the two stable endpoints, so each
     * step lands within the 30MW limit; only the endpoints (already stable relative to THEIR outer neighbour)
     * are left untouched. */
    {
        $exArr = []; for ($r = 0; $r < $n; $r++) $exArr[$r] = $expOf($genRows[$r], $ieVals[$r]);
        $r = 1;
        while ($r < $n) {
            if (isset($actualRows[$r]) || isset($actualRows[$r - 1]) || abs($exArr[$r] - $exArr[$r - 1]) <= 30.0 + 1e-6) { $r++; continue; }
            $dir = $exArr[$r] > $exArr[$r - 1] ? 1 : -1;
            $start = $r - 1; $end = $r;
            while ($end + 1 < $n && !isset($actualRows[$end + 1])
                   && (($exArr[$end + 1] - $exArr[$end]) * $dir) > 1e-6
                   && abs($exArr[$end + 1] - $exArr[$end]) > 30.0 + 1e-6) { $end++; }
            $span = $end - $start;
            // if even a perfect redistribution over the initially-detected span would still exceed 30MW/step,
            // extend further (through already-compliant transitions) until it becomes feasible, up to +4 rows.
            $extra = 0;
            while ($span > 0 && abs(($exArr[$end] - $exArr[$start]) / $span) > 30.0 - 1e-6 && $extra < 4) {
                if ($end + 1 < $n && !isset($actualRows[$end + 1])) { $end++; }
                elseif ($start - 1 >= 0 && !isset($actualRows[$start - 1])) { $start--; }
                else { break; }
                $span = $end - $start; $extra++;
            }
            if ($span >= 2) {
                $target0 = $exArr[$start]; $target1 = $exArr[$end]; $perStep = ($target1 - $target0) / $span;
                if (abs($perStep) <= 30.0 - 1e-6) {
                    for ($k = 1; $k < $span; $k++) {
                        $rr = $start + $k; if ($isFixed('g8', $rr) || $isFixed('g9', $rr)) continue;
                        $want = $target0 + $perStep * $k;
                        // linear search for the G8=G9 level that lands closest to $want, honouring quota/Range/BusFlow
                        $bestV = (float)$genRows[$rr]['g8']; $bestDiff = abs($exArr[$rr] - $want);
                        for ($v = $GMIN; $v <= $GMAX + 1e-6; $v += 0.5) {
                            $sv = $genRows[$rr];
                            $genRows[$rr]['g8'] = $g89Cap($rr, 'g8', $v); $genRows[$rr]['g9'] = $g89Cap($rr, 'g9', $v);
                            pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                            $eTry = $expOf($genRows[$rr], $ieVals[$rr]);
                            $gasOk = true;
                            if ($v > (float)$sv['g8']) {   // raising -> must not exceed quota
                                $before = calc_fuel($d3, 'g8', (float)$sv['g8']) + calc_fuel($d3, 'g9', (float)$sv['g9']);
                                $after = calc_fuel($d3, 'g8', $v) + calc_fuel($d3, 'g9', $v);
                                $gasOk = ($trueGasNow() + ($after - $before) / 2.0) <= $quota + 0.0001;
                            }
                            $valid = $gasOk && $eTry >= $rMinR[$rr] - 1e-6 && $eTry <= $rMaxR[$rr] + 1e-6 && $busOf($genRows[$rr], $ieVals[$rr]) >= $busMin - 1e-6;
                            $genRows[$rr] = $sv; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                            if ($valid && abs($eTry - $want) < $bestDiff) { $bestDiff = abs($eTry - $want); $bestV = $v; }
                        }
                        $genRows[$rr]['g8'] = $g89Cap($rr, 'g8', $bestV); $genRows[$rr]['g9'] = $g89Cap($rr, 'g9', $bestV);
                        pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                        $exArr[$rr] = $expOf($genRows[$rr], $ieVals[$rr]);
                    }
                }
            }
            $r = $end + 1;
        }
    }
    for ($pass = 0; $pass < 10; $pass++) {
        $changed = false;
        for ($r = 1; $r < $n; $r++) {
            if (isset($actualRows[$r]) || isset($actualRows[$r - 1])) continue;
            $e0 = $expOf($genRows[$r - 1], $ieVals[$r - 1]); $e1 = $expOf($genRows[$r], $ieVals[$r]);
            $d = $e1 - $e0; if (abs($d) <= pp_export_step_limit($model) + 1e-6) continue;
            // Try smoothing from whichever side is NOT g8/g9-fixed, using G8/G9's own 30MW/30min budget.
            $resolvedHere = false; $reasons = [];
            foreach ([$r, $r - 1] as $rr) {
                if ($isFixed('g8', $rr) || $isFixed('g9', $rr)) { $reasons[] = "row $rr: G8/G9 operator-fixed"; continue; }
                $nb = ($rr === $r) ? $r - 1 : $r + 1;
                if ($nb < 0 || $nb >= $n) continue;
                $g8 = (float)($genRows[$rr]['g8'] ?? 0); $g9 = (float)($genRows[$rr]['g9'] ?? 0);
                $prevG8 = (float)($genRows[$nb]['g8'] ?? $g8);
                $bump = 0; $ok = false; $moved = false; $stopReason = '';
                while ($bump < 60) {
                    $e0b = $expOf($genRows[$r - 1], $ieVals[$r - 1]); $e1b = $expOf($genRows[$r], $ieVals[$r]);
                    $db = $e1b - $e0b;
                    if (abs($db) <= 30.0 + 1e-6) { $ok = true; break; }
                    $needLowerAtR = ($db > 30.0) === ($rr === $r);
                    $curG8 = (float)$genRows[$rr]['g8'];
                    if ($needLowerAtR) {
                        if ($curG8 <= $GMIN + 1e-6) { $stopReason = 'G8/G9 at GMIN'; break; }
                        if ($curG8 <= $prevG8 - 30.0 + 1e-6) { $stopReason = 'G8/G9 own 30MW ramp budget vs neighbour exhausted'; break; }
                        $trial8 = $g89Cap($rr, 'g8', max($GMIN, $curG8 - 0.5)); $trial9 = $g89Cap($rr, 'g9', max($GMIN, (float)$genRows[$rr]['g9'] - 0.5));
                        if ((float)$genRows[$rr]['g9'] <= 0.0) $trial9 = 0.0;   // never lift an off/held unit via the GMIN floor
                        if ((float)$genRows[$rr]['g8'] <= 0.0) $trial8 = 0.0;
                    } else {
                        if ($curG8 >= $GMAX - 1e-6) { $stopReason = 'G8/G9 at GMAX'; break; }
                        if ($curG8 >= $prevG8 + 30.0 - 1e-6) { $stopReason = 'G8/G9 own 30MW ramp budget vs neighbour exhausted'; break; }
                        // raising adds gas -> must not push Total Gas over quota
                        $before = calc_fuel($d3, 'g8', $curG8) + calc_fuel($d3, 'g9', (float)$genRows[$rr]['g9']);
                        $trial8 = $g89Cap($rr, 'g8', min($GMAX, $curG8 + 0.5)); $trial9 = $g89Cap($rr, 'g9', min($GMAX, (float)$genRows[$rr]['g9'] + 0.5));
                        if (abs($trial8 - $curG8) < 1e-9 && abs($trial9 - (float)$genRows[$rr]['g9']) < 1e-9) { $stopReason = 'G8/G9 held at start-up sequence cap'; break; }
                        $after = calc_fuel($d3, 'g8', $trial8) + calc_fuel($d3, 'g9', $trial9);
                        $needGas = $trueGasNow() + ($after - $before) / 2.0 - ($quota + 0.0005);
                        if ($needGas > 0) {
                            // Master Audit Bagian E: reallocate (never add) gas — trim the highest-slack
                            // donor row so the pre-ramp raise stays inside the quota.
                            $donateGas($needGas + 0.002, [$r => 1, $r - 1 => 1]);
                            if ($trueGasNow() + ($after - $before) / 2.0 > $quota + 0.001) { $stopReason = 'raising G8/G9 would exceed Gas Quota (no donor row with export slack left)'; break; }
                        }
                    }
                    $genRows[$rr]['g8'] = $trial8; $genRows[$rr]['g9'] = $trial9;
                    pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1);
                    $eNow = $expOf($genRows[$rr], $ieVals[$rr]);
                    if ($eNow < $rMinR[$rr] - 1e-6) { $stopReason = 'would break Export Range Min'; $genRows[$rr]['g8'] = $g8; $genRows[$rr]['g9'] = $g9; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1); break; }
                    if ($eNow > $rMaxR[$rr] + 1e-6) { $stopReason = 'would break Export Range Max'; $genRows[$rr]['g8'] = $g8; $genRows[$rr]['g9'] = $g9; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1); break; }
                    if ($busOf($genRows[$rr], $ieVals[$rr]) < $busMin - 1e-6) { $stopReason = 'would break BusFlow minimum'; $genRows[$rr]['g8'] = $g8; $genRows[$rr]['g9'] = $g9; pp_recompute_stgs($genRows[$rr], $d3, $model, $rr + 1); break; }
                    $moved = true; $bump++;
                }
                if ($moved) $changed = true;
                if ($ok) { $resolvedHere = true; break; }
                if ($stopReason !== '') $reasons[] = "row $rr: $stopReason";
            }
            if (!$resolvedHere) $rampUnresolved[$r] = ['delta' => round($d, 2), 'reasons' => $reasons];
            else unset($rampUnresolved[$r]);
        }
        if (!$changed) break;
    }
    // Rebuild from the TRUE final state: a row's fix during a pass can be undone by a later row's adjustment
    // within that SAME pass (adjacent conflicting cliffs) — the per-row snapshot above does not catch that.
    $rampUnresolved = [];
    for ($r = 1; $r < $n; $r++) {
        if (isset($actualRows[$r]) || isset($actualRows[$r - 1])) continue;
        $e0 = $expOf($genRows[$r - 1], $ieVals[$r - 1]); $e1 = $expOf($genRows[$r], $ieVals[$r]);
        $d = $e1 - $e0; if (abs($d) <= pp_export_step_limit($model) + 1e-6) continue;
        $rampUnresolved[$r] = ['delta' => round($d, 2), 'reasons' => ['no further G8/G9 headroom on either side after full multi-pass smoothing']];
    }
    // Babelan ramp: same true-final-state check (its own pass above can leave a stale warning if a LATER
    // pass — gas-trim, cliff redistribution, export-ramp smoothing — incidentally resolves the tension).
    foreach (['b1', 'b2'] as $u) {
        if (!pp_unit_present($d3, $u)) continue;
        for ($r = 1; $r < $n; $r++) {
            if (isset($actualRows[$r]) || isset($actualRows[$r - 1])) continue;
            $prev = (float)($genRows[$r - 1][$u] ?? 0); $cur = (float)($genRows[$r][$u] ?? 0);
            if (abs($cur - $prev) <= $bbRamp + 1e-6) continue;
            $warnings[] = sprintf('Babelan ramp could not be smoothed: %s row %d changed by %.2f MW (limit %g MW/30min) and G8/G9 compensation is exhausted (Range Min/Max or BusFlow).',
                strtoupper($u), $r + 1, $cur - $prev, $bbRamp);
        }
    }
    // Belt-and-suspenders: ramp smoothing's own gas-aware check should already prevent any overage, but
    // re-verify once more since this is the LAST point repairs are allowed before final validation. Also
    // raises back toward quota if the earlier buffer (left for ramp smoothing's raise steps) went unused,
    // so a scenario needing no ramp smoothing does not end up needlessly under-utilised.
    {
        $guardFinal2 = 0; $exhausted2 = [];
        while ($trueGasNow() > $quota + 0.0001 && $guardFinal2++ < 400) {
            $bestR = -1; $bestMargin = 0.1;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($exhausted2[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) continue;
                if ((float)($genRows[$r]['g8'] ?? 0) <= $GMIN + 1e-6) continue;
                $margin = $expOf($genRows[$r], $ieVals[$r]) - $rMinR[$r];
                if ($margin > $bestMargin) { $bestMargin = $margin; $bestR = $r; }
            }
            if ($bestR < 0) break;
            $sv = $genRows[$bestR];
            $genRows[$bestR]['g8'] = ((float)$genRows[$bestR]['g8'] <= 0.0) ? 0.0 : max($GMIN, (float)$genRows[$bestR]['g8'] - 0.5);
            $genRows[$bestR]['g9'] = ((float)$genRows[$bestR]['g9'] <= 0.0) ? 0.0 : max($GMIN, (float)$genRows[$bestR]['g9'] - 0.5);
            pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            /* Master Audit Bagian E: the trim must never (re)create an export ramp > 35 MW vs a neighbour. */
            $rampBroke2 = false; $eB2 = $expOf($genRows[$bestR], $ieVals[$bestR]);
            foreach ([$bestR - 1, $bestR + 1] as $nb2) { if ($nb2 < 0 || $nb2 >= $n) continue;
                $dNew = abs($eB2 - $expOf($genRows[$nb2], $ieVals[$nb2]));
                $dOld = abs($expOf($sv, $ieVals[$bestR]) - $expOf($genRows[$nb2], $ieVals[$nb2]));
                if ($dNew > pp_export_step_limit($model) + 1e-6 && $dNew > $dOld + 1e-9) { $rampBroke2 = true; break; } }
            if ($rampBroke2 || $busOf($genRows[$bestR], $ieVals[$bestR]) < $busMin - 1e-6 || $expOf($genRows[$bestR], $ieVals[$bestR]) < $rMinR[$bestR] - 1e-6) {
                $genRows[$bestR] = $sv; pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
                $exhausted2[$bestR] = true;
            }
        }
        $guardFinal3 = 0; $exhausted3 = [];
        while ($trueGasNow() < $quota - 0.02 && $guardFinal3++ < 400) {
            $bestR = -1; $bestHead = 0.6;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r]) || isset($exhausted3[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) continue;
                if ((float)($genRows[$r]['g8'] ?? 0) >= $GMAX - 1e-6) continue;
                $head = $rMaxR[$r] - $expOf($genRows[$r], $ieVals[$r]);
                if ($head > $bestHead) { $bestHead = $head; $bestR = $r; }
            }
            if ($bestR < 0) break;
            $sv = $genRows[$bestR];
            $before = calc_fuel($d3, 'g8', (float)$genRows[$bestR]['g8']) + calc_fuel($d3, 'g9', (float)$genRows[$bestR]['g9']);
            $genRows[$bestR]['g8'] = $g89Cap($bestR, 'g8', min($GMAX, (float)$genRows[$bestR]['g8'] + 0.5));
            $genRows[$bestR]['g9'] = $g89Cap($bestR, 'g9', min($GMAX, (float)$genRows[$bestR]['g9'] + 0.5));
            $after = calc_fuel($d3, 'g8', (float)$genRows[$bestR]['g8']) + calc_fuel($d3, 'g9', (float)$genRows[$bestR]['g9']);
            if (abs($after - $before) < 1e-9) { $exhausted3[$bestR] = true; continue; }   // startup-cap made this a no-op -> try another row
            pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            $eNow = $expOf($genRows[$bestR], $ieVals[$bestR]);
            /* Master Audit Bagian E: the raise-back toward quota must never (re)create an export ramp
             * > 30 MW vs a neighbour — this pass previously re-broke a step the smoothing had resolved. */
            $rampBroke3 = false;
            foreach ([$bestR - 1, $bestR + 1] as $nb3) { if ($nb3 < 0 || $nb3 >= $n) continue;
                $dNew3 = abs($eNow - $expOf($genRows[$nb3], $ieVals[$nb3]));
                $dOld3 = abs($expOf($sv, $ieVals[$bestR]) - $expOf($genRows[$nb3], $ieVals[$nb3]));
                if ($dNew3 > pp_export_step_limit($model) + 1e-6 && $dNew3 > $dOld3 + 1e-9) { $rampBroke3 = true; break; } }
            if ($rampBroke3 || $eNow > $rMaxR[$bestR] + 1e-6 || $busOf($genRows[$bestR], $ieVals[$bestR]) < $busMin - 1e-6 || $trueGasNow() > $quota + 0.0001) {
                $genRows[$bestR] = $sv; pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
                $exhausted3[$bestR] = true;
            }
        }
    }
    /* PROMPT FINAL: panggilan KEDUA applyStgHold — menangkap unit yang dinyalakan pass-pass setelah
     * panggilan pertama (required lever, export repair, dsb) SEBELUM trim/rebuild agar caps + hold
     * ditegakkan lalu dinormalkan oleh trim & ter-evidence oleh rebuild ramp final. */
    if (isset($applyStgHold)) $applyStgHold();

    /* PROMPT FINAL §8: MINIMUM RUNTIME extension — segmen run pendek (< minimum runtime) yang berhenti
     * sebelum akhir hari & bukan karena Stop/Skip schedule diperpanjang pada min-CC (STG mengikuti via
     * recompute). Ramp turun min-CC->0 kecil; over-range yang tercipta dinormalkan trim di bawah. */
    {
        $rtLimF = pp_runtime_limits($model);
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $uRT) {   // g10: Cluster 4 (MM2100)
            if (!isset($d3[$uRT])) continue;
            $clsRT = pp_runtime_class($uRT, $d3);
            $runR = (int)($rtLimF[$clsRT]['run_rows'] ?? 8);
            $mccRT = (float)($d3[$uRT]['min_ccload'] ?? ($d3[$uRT]['min_scload'] ?? 5));
            $st = -1;
            for ($r = 0; $r <= $n; $r++) {
                $on = $r < $n && (float)($genRows[$r][$uRT] ?? 0) >= 0.01;
                if ($on && $st < 0) $st = $r;
                if (!$on && $st >= 0) {
                    $en = $r - 1;
                    $carry = ($st === 0 && (($lastStatus[$uRT] ?? '') === 'running'));
                    $len = $en - $st + 1;
                    if (!$carry && $en < $n - 1 && $len < $runR && !pp_is_unit_stopped($d3, $model, $uRT, $en + 2)) {
                        for ($q = $en + 1; $q < min($n, $st + $runR); $q++) {
                            if ($isFixed($uRT, $q) || isset($actualRows[$q]) || pp_is_unit_stopped($d3, $model, $uRT, $q + 1)) break;
                            $skq = pp_get_skip_load($model, $uRT, $q + 1);
                            $vq = $mccRT;
                            if ($skq !== null && $vq >= $skq[0] - 0.51 && $vq <= $skq[1] + 0.51) $vq = $skq[1] + 0.6;
                            $genRows[$q][$uRT] = $vq;
                            pp_recompute_stgs($genRows[$q], $d3, $model, $q + 1);
                        }
                    }
                    $st = -1;
                }
            }
        }
    }

    /* PROMPT FINAL §8 (STG): minimum runtime STG — bila segmen S1/S2/S3 berakhir terlalu cepat karena
     * GTG feed dimatikan, perpanjang GTG feed pada min-CC hingga runtime STG terpenuhi (STG recompute). */
    {
        $rtLimS = pp_runtime_limits($model);
        $runS = (int)($rtLimS['stg']['run_rows'] ?? 12);
        foreach (['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']] as $sX => $feedX) {
            if (!isset($d3[$sX])) continue;
            $st = -1;
            for ($r = 0; $r <= $n; $r++) {
                $on = $r < $n && (float)($genRows[$r][strtolower($sX)] ?? ($genRows[$r][$sX] ?? 0)) >= 0.01;
                if ($on && $st < 0) $st = $r;
                if (!$on && $st >= 0) {
                    $en = $r - 1;
                    $carry = ($st === 0 && (($lastStatus[$sX] ?? '') === 'running'));
                    if (!$carry && $en < $n - 1 && ($en - $st + 1) < $runS && !pp_is_unit_stopped($d3, $model, $sX, $en + 2)) {
                        // GTG feed yang on pada row terakhir segmen
                        $keep = []; foreach ($feedX as $fX) if ((float)($genRows[$en][$fX] ?? 0) >= 0.01) $keep[] = $fX;
                        if ($keep) {
                            for ($q = $en + 1; $q < min($n, $st + $runS); $q++) {
                                $any = false;
                                foreach ($keep as $fX) {
                                    if ($isFixed($fX, $q) || isset($actualRows[$q]) || pp_is_unit_stopped($d3, $model, $fX, $q + 1)) continue;
                                    $mccF = (float)($d3[$fX]['min_ccload'] ?? 20);
                                    if ((float)($genRows[$q][$fX] ?? 0) < $mccF - 0.01) $genRows[$q][$fX] = $mccF;
                                    $any = true;
                                }
                                if (!$any) break;
                                pp_recompute_stgs($genRows[$q], $d3, $model, $q + 1);
                                if ((float)($genRows[$q][$sX] ?? 0) < 0.01) break;   // STG tetap mati (mode hold dsb)
                            }
                        }
                    }
                    $st = -1;
                }
            }
        }
    }

    /* ---- OVER-MAX final trim (Master Audit A3.11): setelah semua pass, tidak boleh ada row dengan
     * Export > Range Max efektifnya. Turunkan G8/G9 (ramp-aware, tetap >= Range Min & BusFlow) pada
     * row yang over; residual yang tak terselesaikan = must-run floor, diberi warning evidence. */
    {
        // ADDENDUM FINAL: multi-sweep — taper Babelan (±5 MW/unit/step) butuh tetangga turun dulu
        for ($sweepOM = 0; $sweepOM < 5; $sweepOM++) {
        $anyOverOM = false;
        for ($r = 0; $r < $n; $r++) if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.51) { $anyOverOM = true; break; }
        if (!$anyOverOM && $sweepOM > 0) break;
        for ($r = 0; $r < $n; $r++) {
            if (isset($actualRows[$r]) || $isFixed('g8', $r) || $isFixed('g9', $r)) continue;
            // taper helper: apakah row ini / tetangga langsung sedang over? (BB boleh ikut turun demi taper)
            $nearOverOM = false;
            foreach ([$r - 1, $r, $r + 1] as $q) { if ($q < 0 || $q >= $n) continue;
                if ($expOf($genRows[$q], $ieVals[$q]) > $rMaxR[$q] + 0.51) { $nearOverOM = true; break; } }
            /* ZERO TOLERANCE (root cause over-max rows saat pasangannya dalam startup window): lever
             * G8/G9 kini PER-UNIT independen — sebelumnya berbasis c8 bersama sehingga G8=40 (window
             * Additional HRSG) mem-break loop dan G9=91 tidak pernah diturunkan. Unit di dalam window
             * caps (suCap) tidak disentuh: kurva start-up adalah fisika tetap. Floor = GMIN. */
            foreach (['g9', 'g8'] as $uOM) {
                $gOM = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5 - 1e-6 && $gOM++ < 400) {
                    $cOM = (float)($genRows[$r][$uOM] ?? 0);
                    if ($cOM <= $GMIN + 1e-6) break;
                    if (isset($suCap[$uOM][$r])) break;                            // startup window: jangan diubah
                    $svOM = $genRows[$r];
                    $genRows[$r][$uOM] = max($GMIN, $cOM - 0.5);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $eOM = $expOf($genRows[$r], $ieVals[$r]);
                    $rampBad = false;
                    foreach ([$r - 1, $r + 1] as $nbo) { if ($nbo < 0 || $nbo >= $n) continue;
                        $dU = abs((float)$genRows[$r][$uOM] - (float)($genRows[$nbo][$uOM] ?? 0));   // ramp unit CC <=30
                        $dN = abs($eOM - $expOf($genRows[$nbo], $ieVals[$nbo]));
                        $dO = abs($expOf($svOM, $ieVals[$r]) - $expOf($genRows[$nbo], $ieVals[$nbo]));
                        if (($dN > pp_export_step_limit($model) + 1e-6 && $dN > $dO + 1e-9) || $dU > pp_export_step_limit($model) + 1e-6) { $rampBad = true; break; } }
                    if ($rampBad || $eOM < $rMinR[$r] - 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                        $genRows[$r] = $svOM; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break;
                    }
                }
            }
            /* ADDENDUM FINAL: lever Babelan — bila lever gas exhausted, turunkan B1/B2 bergantian
             * (coal, tidak menyentuh gas quota) dengan taper otomatis <=5 MW/30min PER UNIT vs kedua
             * tetangga, tidak di bawah min_scload, tidak pada row fixed/actual, tetap >= Range Min & BusFlow. */
            foreach (['b1', 'b2'] as $uB) {
                $gOMB = 0;
                while (($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5 - 1e-6
                        || ($nearOverOM && $gOMB < 12)) && $gOMB++ < 200) {
                    if ($isFixed($uB, $r) || isset($actualRows[$r])) break;
                    $cB = (float)($genRows[$r][$uB] ?? 0);
                    $minB = (float)($d3[$uB]['min_scload'] ?? 30);
                    if ($cB <= $minB + 1e-6) break;
                    $tB = max($minB, $cB - 0.5);
                    $okB = true;                                                   // Babelan ramp per unit (helper)
                    foreach ([$r - 1, $r + 1] as $nbB) { if ($nbB < 0 || $nbB >= $n) continue;
                        if (abs($tB - (float)($genRows[$nbB][$uB] ?? 0)) > $bbRamp + 1e-6) { $okB = false; break; } }
                    if (!$okB) break;
                    $svB = $genRows[$r];
                    $genRows[$r][$uB] = $tB;
                    $eB2 = $expOf($genRows[$r], $ieVals[$r]);
                    if ($eB2 < $rMinR[$r] - 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                        $genRows[$r] = $svB; break;
                    }
                }
            }
            /* ADDENDUM FINAL Bagian 6: bila G8/G9 sudah di GMIN, turunkan G2 lalu G1 (required-continuous
             * boleh turun LEVEL sampai min-CC, tidak pernah ke 0) — lead start Cold G3 di window ber-Range-Max
             * rendah butuh unit lain memberi ruang. Ramp-aware & BusFlow-aware seperti lever lain. */
            foreach (['g2', 'g1'] as $uD) {
                $gOM2 = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5 - 1e-6 && $gOM2++ < 200) {
                    if ($isFixed($uD, $r) || isset($actualRows[$r])) break;
                    $cD = (float)($genRows[$r][$uD] ?? 0);
                    $mccD = (float)($d3[$uD]['min_ccload'] ?? 15);
                    if ($cD <= $mccD + 1e-6) break;                              // required-running: jangan di bawah min-CC
                    $svD = $genRows[$r];
                    $genRows[$r][$uD] = max($mccD, $cD - 0.5);
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $eD = $expOf($genRows[$r], $ieVals[$r]);
                    $rampBadD = false;
                    foreach ([$r - 1, $r + 1] as $nbD) { if ($nbD < 0 || $nbD >= $n) continue;
                        $dN = abs($eD - $expOf($genRows[$nbD], $ieVals[$nbD]));
                        $dO = abs($expOf($svD, $ieVals[$r]) - $expOf($genRows[$nbD], $ieVals[$nbD]));
                        if ($dN > 30.0 + 1e-6 && $dN > $dO + 1e-9) { $rampBadD = true; break; } }
                    if ($rampBadD || $eD < $rMinR[$r] - 1e-6 || $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                        $genRows[$r] = $svD; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break;
                    }
                }
            }
            $eF = $expOf($genRows[$r], $ieVals[$r]);
            if ($sweepOM === 4 && $eF > $rMaxR[$r] + 0.51) {
                $warnings[] = sprintf('Invalid PLN export: Export PLN %.1f MW exceeds Range Max %.1f MW at row %d — driven by required/must-run baseload that cannot be curtailed (must-run floor).', $eF, $rMaxR[$r], $r + 1);
            }
        }
        }
    }
    /* Stop Schedule hard-constraint enforcement (Revisi Bugfix): the g8/g9 export lever (and some repair
     * passes) set g9=g8 without consulting each unit's stop window, so a unit ordered to stop can reappear.
     * After all passes, force every unit to 0 MW on any row where pp_is_unit_stopped() holds. Fixed-load rows
     * are left to the conflict reporter. Change-Over-managed units are skipped (change_over enforces its own
     * start-before-stop continuity). Row-level evidence is emitted. */
    $coManaged = [];
    if (!empty($model['change_over']['enabled'])) {
        $coBlk = ($model['co_norm']['blocks'] ?? $model['change_over']['blocks'] ?? []);
        foreach ($coBlk as $cb) { if (is_array($cb)) { if (!empty($cb['gtg'])) $coManaged[strtolower($cb['gtg'])] = 1; if (!empty($cb['stg'])) $coManaged[strtolower($cb['stg'])] = 1; } }
    }
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3','ge1','ge2','ge3','ge4','b1','b2'] as $u) {
        if (isset($coManaged[$u])) continue;                              // change_over owns these units' schedule
        for ($r = 0; $r < $n; $r++) {
            if (isset($fixedVal[$u][$r])) continue;
            if (!pp_is_unit_stopped($d3, $model, $u, $r + 1)) continue;
            $cur = (float)($genRows[$r][$u] ?? 0);
            if ($cur > 0.01) {
                $genRows[$r][$u] = 0.0;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $warnings[] = sprintf('Stop Schedule enforced: %s row %d forced to 0 MW (was %.1f) — unit is in its stop window.', strtoupper($u), $r + 1, $cur);
            }
        }
    }

    /* Skip Load hard-constraint enforcement (Revisi Bugfix): after all dispatch/repair passes, no unit may
     * sit inside its forbidden skip band on any row. Push each offending load to the nearest feasible edge
     * outside the band; if the band spans the unit's whole feasible range (covers its CC-min) the unit goes
     * OFF. Fixed-load rows are authoritative. Row-level evidence emitted. */
    foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','s1','s2','s3'] as $u) {
        if (empty($model['unit_skip_load'][$u]) && empty($model['unit_skip_load'][strtoupper($u)])) continue;
        $umin = (float)($d3[$u]['min_ccload'] ?? $d3[$u]['min_scload'] ?? 0);
        $umax = (float)($d3[$u]['max_load'] ?? 999);
        for ($r = 0; $r < $n; $r++) {
            if (isset($fixedVal[$u][$r])) {                               // operator fixed-load wins ...
                $skipC = pp_get_skip_load($model, $u, $r + 1);            // ... tapi konflik dgn skip band = evidence
                $fvC = (float)$fixedVal[$u][$r];
                if ($skipC !== null && $fvC >= $skipC[0] - 1e-9 && $fvC <= $skipC[1] + 1e-9) {
                    static $confWarned = [];
                    $ck = $u . ':' . $r;
                    if (!isset($confWarned[$u])) {
                        $confWarned[$u] = true;
                        $warnings[] = sprintf('Fixed Load vs Skip Load CONFLICT: %s Fixed %.1f MW berada DI DALAM skip band [%.1f,%.1f] (mulai row %d) — dua hard constraint operator saling bertentangan; Fixed Load dipertahankan (authoritative), kandidat ditandai valid-infeasible dengan evidence ini.', strtoupper($u), $fvC, $skipC[0], $skipC[1], $r + 1);
                    }
                }
                continue;
            }
            $skip = pp_get_skip_load($model, $u, $r + 1);
            if ($skip === null) continue;
            $cur = (float)($genRows[$r][$u] ?? 0);
            if ($cur <= 0.0) continue;                                    // already off -> compliant
            [$lo, $hi] = $skip;
            if ($cur < $lo - 1e-9 || $cur > $hi + 1e-9) continue;         // already outside band
            $adj = pp_apply_skip($cur, $skip, $umin, $umax);
            /* PROMPT FINAL (root cause S30): nilai TEPAT di tepi band (mis. 99 pada [95,99]) sebelumnya
             * jatuh ke 0 MW satu row (start-stop ilegal). Pilih tepi LUAR terdekat yang feasible dulu;
             * 0 hanya bila band benar-benar menutup seluruh range feasible unit. */
            if ($adj >= $lo - 1e-9 && $adj <= $hi + 1e-9) {
                $candS = [];
                if ($lo - 0.6 >= $umin - 1e-9) $candS[] = $lo - 0.6;   // margin > toleransi validator ±0.51
                if ($hi + 0.6 <= $umax + 1e-9) $candS[] = $hi + 0.6;
                if ($candS) { usort($candS, fn($a, $b) => abs($a - $cur) <=> abs($b - $cur)); $adj = $candS[0]; }
                else $adj = 0.0;
            }
            if (abs($adj - $cur) > 1e-9) {
                $genRows[$r][$u] = $adj;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                $warnings[] = sprintf('Skip Load enforced: %s row %d moved out of forbidden band [%.1f,%.1f] (%.1f -> %.1f MW).',
                    strtoupper($u), $r + 1, $lo, $hi, $cur, $adj);
            }
        }
    }

    /* ZERO TOLERANCE §13.2: FINAL GAS RE-TRIM — kompensasi/runtime-extension/sanitasi dapat menambah
     * gas melewati kuota; turunkan kembali lewat donor (rows ber-slack export, ramp/bus-aware, tidak
     * menyentuh window startup / stopped / fixed) hingga Total Gas <= Quota. Residual = warning evidence. */
    {
        $excF = $trueGasNow() - $quota;
        if ($excF > 1e-4) {
            $donateGas($excF + 0.001, []);
            $excF = $trueGasNow() - $quota;
            if ($excF > 0.0006)
                $warnings[] = sprintf('Gas quota exceeded by %.4f BBTUD after final re-trim: remaining rows are pinned by Range-Min/startup/fixed constraints (no further donor slack).', $excF);
        }
    }

    /* PROMPT FINAL §7 SANITASI MIN-LOAD FINAL: setelah Stop/Skip enforcement (yang dapat mendorong
     * nilai keluar band ke bawah min-CC), tidak boleh ada load ON di bawah min valid di luar startup
     * window. Naikkan ke min-CC bila tidak menabrak Range Max & skip band; bila menabrak, nol-kan bila
     * ramp & Range Min aman; sisanya dibiarkan utk validator (bug nyata harus terlihat, bukan disembunyikan). */
    {
        /* ZERO TOLERANCE §2/§9 CARRYOVER: unit Cannot-Stop/Continuous (semua row di luar window stop)
         * dan unit Last-Status Running (dari row 1 sampai window stop PERTAMA-nya) tidak boleh dinolkan
         * atau ditaruh di bawah min-CC oleh pass mana pun (donor gas, trim, hold) — start-stop berulang
         * yang tercipta dari pelanggaran ini adalah CRITICAL BUG per spec. */
        {
            $csSan = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $uC) {
                if (!isset($d3[$uC]) || !pp_unit_present($d3, $uC)) continue;
                $contC = in_array($uC, $csSan, true)
                    || strtolower((string)($model['required_mode'][$uC]['mode'] ?? '')) === 'continuous';
                $runC = (($lastStatus[$uC] ?? '') === 'running');
                if (!$contC && !$runC) continue;
                $mccC = (float)($d3[$uC]['min_ccload'] ?? ($d3[$uC]['min_scload'] ?? 5));
                $firstStopC = $n;
                for ($r = 0; $r < $n; $r++) if (pp_is_unit_stopped($d3, $model, $uC, $r + 1)) { $firstStopC = $r; break; }
                $limC = $contC ? $n : $firstStopC;
                /* Continuous + Last-Status STOP = unit START hari ini dgn startup sequence lalu tidak
                 * berhenti — penegakan dimulai dari start PERTAMA unit, bukan dari row 1. */
                $fromC = 0; $okWinC = [];
                if (($lastStatus[$uC] ?? '') === 'stop') {
                    $fromC = -1;
                    for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r][$uC] ?? 0) > 0.01) { $fromC = $r; break; }
                    if ($fromC < 0) continue;                                   // belum pernah start — bukan urusan carryover
                }
                /* window start SAH = deret suCap kontigu yang diawali (a) start pertama unit LS-Stop, atau
                 * (b) restart tepat setelah window Stop Schedule/Stop-At. Jejak suCap dari start-stop palsu
                 * di tengah hari TIDAK melindungi dip ilegal. */
                for ($r0 = 0; $r0 < $n; $r0++) {
                    if (!isset($suCap[$uC][$r0]) || isset($suCap[$uC][$r0 - 1])) continue;
                    $sahC = ((($lastStatus[$uC] ?? '') === 'stop') && $r0 === $fromC)
                         || ($r0 > 0 && pp_is_unit_stopped($d3, $model, $uC, $r0));   // row $r0 (1-based) = index $r0-1
                    if ($sahC) for ($q = $r0; isset($suCap[$uC][$q]); $q++) $okWinC[$q] = 1;
                }
                for ($r = $fromC; $r < $limC; $r++) {
                    if (pp_is_unit_stopped($d3, $model, $uC, $r + 1) || $isFixed($uC, $r) || isset($actualRows[$r])) continue;
                    $lC = (float)($genRows[$r][$uC] ?? 0);
                    if ($lC >= $mccC - 0.01) continue;
                    if (isset($okWinC[$r]) && $lC > 0.01 && $lC <= (float)$suCap[$uC][$r] + 0.01) continue;   // window start sah
                    $tC = $mccC;
                    $skC = pp_get_skip_load($model, $uC, $r + 1);
                    if ($skC !== null && $tC >= $skC[0] - 0.51 && $tC <= $skC[1] + 0.51) $tC = $skC[1] + 0.6;
                    $genRows[$r][$uC] = $tC; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
        }

        /* ZERO TOLERANCE §9 (Unit Last Data Status): unit Running WAJIB berbeban di row 1 — pass gas/trim
         * ekstrem apa pun tidak boleh menolkannya; konsekuensi kuota menjadi evidence, bukan pelanggaran LS. */
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','s1','s2','s3','b1','b2'] as $uL) {
            if (!isset($d3[$uL]) || (($lastStatus[$uL] ?? '') !== 'running')) continue;
            if (!pp_unit_present($d3, $uL)) continue;
            if (pp_is_unit_stopped($d3, $model, $uL, 1) || $isFixed($uL, 0) || isset($actualRows[0])) continue;
            if (in_array($uL, ['s1','s2','s3'], true)) continue;                    // STG = konsekuensi feed (recompute)
            $mccL = (float)($d3[$uL]['min_ccload'] ?? ($d3[$uL]['min_scload'] ?? 5));
            if ((float)($genRows[0][$uL] ?? 0) < $mccL - 0.01) {
                $genRows[0][$uL] = $mccL; pp_recompute_stgs($genRows[0], $d3, $model, 1);
            }
        }
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $uS) {   // g10: Cluster 4 (MM2100)
            if (!isset($d3[$uS])) continue;
            $mccS = (float)($d3[$uS]['min_ccload'] ?? ($d3[$uS]['min_scload'] ?? 5));
            for ($r = 0; $r < $n; $r++) {
                $lS = (float)($genRows[$r][$uS] ?? 0);
                if ($lS > 0.01) {                                             // ZERO TOLERANCE §11: Max Load efektif
                    $mxS = pp_effective_maxload($d3, $model, $uS, $r + 1);
                    if ($lS > $mxS + 0.01 && !$isFixed($uS, $r) && !isset($actualRows[$r])) {
                        $genRows[$r][$uS] = $mxS; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); $lS = $mxS;
                    }
                }
                if ($lS < 0.01 || $lS >= $mccS - 0.01) continue;
                if ($isFixed($uS, $r) || isset($actualRows[$r])) continue;
                if (isset($suCap[$uS][$r]) && $lS <= (float)$suCap[$uS][$r] + 0.01) continue;   // startup window sah
                $svS = $genRows[$r]; $tS = $mccS;
                $skS = pp_get_skip_load($model, $uS, $r + 1);
                if ($skS !== null && $tS >= $skS[0] - 0.51 && $tS <= $skS[1] + 0.51) $tS = $skS[1] + 0.6;
                $genRows[$r][$uS] = $tS; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5) {
                    $genRows[$r] = $svS; $genRows[$r][$uS] = 0.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] + 0.35) {
                        /* ZERO TOLERANCE §11: load ilegal (mis. lead 5/15 yatim dari repair) TIDAK boleh
                         * tersisa — pilih 0 MW; deficit Range Min menjadi evidence range (kategori ber-
                         * evidence), sedangkan min_load tidak pernah boleh valid-infeasible. */
                        $warnings[] = sprintf('Invalid low load sanitized to 0: %s row %d was %.1f MW (< min %.1f); raising breached Range Max, so the unit is zeroed and the residual Range-Min deficit is evidenced.', strtoupper($uS), $r + 1, $lS, $mccS);
                    }
                }
            }
        }
    }

    /* ANTI AUTO-RUN EVIDENCE GENERIK (PROMPT STOP_EXPORT_LNG §6): setiap unit non-required yang
     * running dari jalur eskalasi mana pun wajib ber-evidence row-level. Emit di sini utk unit yang
     * belum tercakup emitter spesifik (mis. G4/G2 yang dinaikkan lever advisory last-resort). */
    {
        $allowEv = pp_autorun_allowed($model, 0.0);
        foreach (['g1','g2','g3','g4','g6','g7'] as $uE) {
            if (!isset($d3[$uE]) || in_array($uE, $allowEv, true)) continue;
            if ((($lastStatus[$uE] ?? '') === 'running')) continue;
            $a = -1; $b = -1;
            for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r][$uE] ?? 0) > 0.01) { if ($a < 0) $a = $r; $b = $r; }
            if ($a < 0) continue;
            $has = false; $C = strtoupper($uE);
            foreach ($warnings as $w) if (stripos($w, $C) !== false && (stripos($w, 'evidence') !== false || stripos($w, 'started rows') !== false)) { $has = true; break; }
            if ($has) continue;
            $mid = intdiv($a + $b, 2);
            $head = [];
            foreach (['g9','g8','g5','b1','b2'] as $uH) {
                $cH = (float)($genRows[$mid][$uH] ?? 0);
                $mH = in_array($uH,['g9','g8'],true) ? $GMAX : (float)($d3[$uH]['max_load'] ?? 120);
                $head[] = sprintf('%s=%.0f/%.0f', strtoupper($uH), $cH, $mH);
            }
            $warnings[] = sprintf('Non-required %s started rows %d-%d as LAST RESORT. Evidence row-level: row %d export=%.1f in [%.1f,%.1f]; higher-priority/allowed units at that row: %s (headroom exhausted or ramp/startup/quota locked).',
                $C, $a + 1, $b + 1, $mid + 1, $expOf($genRows[$mid], $ieVals[$mid]), $rMinR[$mid], $rMaxR[$mid], implode(', ', $head));
        }
    }
    /* BABELAN STOP-WINDOW TAPER RE-ASSERT (STRESS #57): tegakkan ulang cap jarak x5 setelah
     * seluruh pass baseload/advisory, SEBELUM repair akhir (repair BB ramp-aware terhadap tetangga). */
    foreach (['b1', 'b2'] as $uB2) {
        if (!isset($d3[$uB2])) continue;
        $stopRow2 = [];
        for ($r = 0; $r < $n; $r++) $stopRow2[$r] = pp_is_unit_stopped($d3, $model, $uB2, $r + 1);
        if (!in_array(true, $stopRow2, true) || !in_array(false, $stopRow2, true)) continue;
        for ($r = 0; $r < $n; $r++) {
            if ($stopRow2[$r]) continue;
            $dN = PHP_INT_MAX;
            for ($k = 0; $k < $n; $k++) if ($stopRow2[$k]) $dN = min($dN, abs($k - $r));
            $cap2 = $bbRamp * $dN;
            if ((float)($genRows[$r][$uB2] ?? 0) > $cap2 + 1e-9 && !$isFixed($uB2, $r) && !isset($actualRows[$r])) {
                $genRows[$r][$uB2] = $cap2;
                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
            }
        }
    }

    /* ================= UNIT STOP RE-ASSERT (PROMPT STOP_EXPORT_LNG §1.1-1.2) =================
     * Unit Stop Schedule = hard constraint mutlak utk SEMUA unit (G1-G10, GE1-4, S1-3, BBLN1-2).
     * Pass mana pun yang menyalakan unit dalam stop window dinolkan di sini SEBELUM repair akhir,
     * sehingga repair menutup deficit dengan unit lain yang sah — bukan dgn unit stop. */
    {
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4','b1','b2'] as $uZ) {
            if (!isset($d3[$uZ])) continue;
            for ($r = 0; $r < $n; $r++) {
                if ((float)($genRows[$r][$uZ] ?? 0) > 0.0 && pp_is_unit_stopped($d3, $model, $uZ, $r + 1)) {
                    $genRows[$r][$uZ] = 0.0;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
        }
    }

    /* ================= BABELAN PRIORITY FULL LOAD (PROMPT MM2100 REDESIGN §5) =================
     * Babelan = priority 1: full load selama Export dlm Range & BusFlow aman; unit paling terakhir
     * turun dan bukan lever default. Pass ini menaikkan BBLN1/BBLN2 menuju max via EXPORT SLACK
     * (bukan substitusi gas — quota window Jababeka tetap terjaga), ramp <=5 MW/30min per unit,
     * BusFlow >= min (BB di Bus B: naik BB menurunkan BusFlow), export <= Range Max. */
    {
        $busMinBB = $busMin;
        $bbMxRaw = ['b1' => (float)($d3['b1']['max_load'] ?? 120), 'b2' => (float)($d3['b2']['max_load'] ?? 120)];
        $bbMxAt = function (string $ub, int $r) use ($d3, $model, $bbMxRaw) { $e = pp_effective_maxload($d3, $model, $ub, $r + 1); return $e > 0 ? min($bbMxRaw[$ub], $e) : $bbMxRaw[$ub]; };   // PATCH G31: effective max per row
        for ($swBB = 0; $swBB < 10; $swBB++) {
            $movedBB = false;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) continue;
                foreach (['b1', 'b2'] as $ub) {
                    if ($isFixed($ub, $r) || pp_is_unit_stopped($d3, $model, $ub, $r + 1)) continue;
                    $gBB = 0;
                    while ($gBB++ < 60) {
                        $cb = (float)($genRows[$r][$ub] ?? 0);
                        $mxUb = $bbMxAt($ub, $r);   // PATCH G31: effective max per row
                        if ($cb >= $mxUb - 1e-6) break;
                        $lim = $mxUb;
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            $lim = min($lim, (float)($genRows[$nb][$ub] ?? 0) + $bbRamp); }
                        if ($cb >= $lim - 1e-6) break;
                        $sv = $genRows[$r];
                        $genRows[$r][$ub] = min($lim, $cb + 0.5);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $eB = $expOf($genRows[$r], $ieVals[$r]); $bad = false;
                        if ($eB > $rMaxR[$r] + 1e-9 - 0.5) $bad = true;                 // jaga margin Range Max
                        if (!$bad && $busOf($genRows[$r], $ieVals[$r]) < $busMinBB - 1e-6) $bad = true;
                        if (!$bad) foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            if (abs($eB - $expOf($genRows[$nb], $ieVals[$nb])) > pp_export_step_limit($model) + 1e-6) { $bad = true; break; } }
                        if ($bad) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                        $movedBB = true;
                    }
                }
            }
            if (!$movedBB) break;
        }
        /* evidence §5: streak BB < max harus punya alasan row-level */
        $stB = -1;
        for ($r = 0; $r <= $n; $r++) {
            $low = $r < $n && ((float)($genRows[$r]['b1'] ?? 0) < $bbMxAt('b1', $r) - 0.5 || (float)($genRows[$r]['b2'] ?? 0) < $bbMxAt('b2', $r) - 0.5);   // PATCH G31
            if ($low && $stB < 0) $stB = $r;
            if (!$low && $stB >= 0) {
                $en = $r - 1; $mid = intdiv($stB + $en, 2);
                $bbT = (float)($genRows[$mid]['b1'] ?? 0) + (float)($genRows[$mid]['b2'] ?? 0);
                $eM = $expOf($genRows[$mid], $ieVals[$mid]);
                $reason = ($rMaxR[$mid] - $eM) < 1.5 ? sprintf('raising Babelan breaches Export Range Max %.1f (export %.1f)', $rMaxR[$mid], $eM)
                        : (($busOf($genRows[$mid], $ieVals[$mid]) - $busMinBB) < 1.5 ? sprintf('BusFlow at minimum %.1f (Babelan is Bus B: raising it lowers BusFlow)', $busMinBB)
                        : 'Babelan 5 MW/30min ramp path from neighbouring rows');
                $warnings[] = sprintf('Babelan below full load rows %d-%d (e.g. row %d: BB=%.0f/%.0f MW): %s. Export=%.1f in [%.1f,%.1f], BusFlow=%.1f (min %.1f).',
                    $stB + 1, $en + 1, $mid + 1, $bbT, $bbMxAt('b1', $mid) + $bbMxAt('b2', $mid), $reason, $eM, $rMinR[$mid], $rMaxR[$mid], $busOf($genRows[$mid], $ieVals[$mid]), $busMinBB);
                $stB = -1;
            }
        }
    }

    // Master Audit Bagian E: rebuild the unresolved-ramp list one FINAL time, AFTER guardFinal2/3 —
    // those passes are now ramp-aware, so any step still >30 MW here is a genuine, evidenced conflict.
    $rampUnresolved = [];
    for ($r = 1; $r < $n; $r++) {
        if (isset($actualRows[$r]) || isset($actualRows[$r - 1])) continue;
        $e0 = $expOf($genRows[$r - 1], $ieVals[$r - 1]); $e1 = $expOf($genRows[$r], $ieVals[$r]);
        $d = $e1 - $e0; if (abs($d) <= pp_export_step_limit($model) + 1e-6) continue;
        $rampUnresolved[$r] = ['delta' => round($d, 2), 'reasons' => ['no further G8/G9 headroom on either side after full multi-pass smoothing']];
    }
    // Row-level evidence for any Export ramp step that could not be resolved (fixed-load lock, gas quota,
    // Range Min/Max, or BusFlow all exhausted) — surfaced, never silently overridden or hidden.
    foreach ($rampUnresolved as $r => $info) {
        $warnings[] = sprintf('Export ramp could not be smoothed: |dExport|=%.1f MW at row %d exceeds the 35 MW limit (%s).',
            abs($info['delta']), $r + 1, implode('; ', $info['reasons']) ?: 'no further G8/G9 headroom on either side');
    }


    /* ADDENDUM ANTI AUTO-RUN §C: EMITTER EVIDENCE TERPUSAT — pass mana pun yang menyalakan unit
     * non-required (gas top-up merit order, export repair, dsb) wajib meninggalkan evidence row-level.
     * Untuk unit non-allowed yang running dan belum disebut warning mana pun, emit evidence berbasis
     * keadaan nyata: row start, alasan dominan (serapan Gas Quota harian / export floor / merit order),
     * dan headroom unit allowed pada row tersebut. */
    {
        $mmQe = 0.0;   // kuota MM2100 dihitung dari input quota keys KP72 side bila tersedia
        foreach (['pep_kp72','pertagas_kp72','akasia_kp72','baskara_kp72'] as $qk)
            $mmQe += (float)($model['gas_quota'][$qk] ?? 0);
        $allowE = pp_autorun_allowed($model, $mmQe);
        $blkFeedE = ['s1' => ['g3','g4','g6'], 's2' => ['g1','g2','g5'], 's3' => ['g8','g9']];
        $wBlobE = implode(' ', $warnings);
        $gasNowE = $trueGasNow();
        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','s1','s2','s3','b1','b2'] as $uE) {
            if (!isset($d3[$uE]) || in_array($uE, $allowE, true)) continue;
            $fOn = -1; for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r][$uE] ?? 0) > 0.01) { $fOn = $r; break; }
            if ($fOn < 0) continue;
            if (stripos($wBlobE, strtoupper($uE)) !== false) continue;               // sudah ber-evidence
            $isStgE = isset($blkFeedE[$uE]);
            if ($isStgE) {                                                            // STG: konsekuensi GTG feed blok
                $cov = false; foreach ($blkFeedE[$uE] as $fE)
                    if (in_array($fE, $allowE, true) || stripos($wBlobE, strtoupper($fE)) !== false) { $cov = true; break; }
                if ($cov) continue;
            }
            $lOn = $fOn; for ($r = $n - 1; $r >= 0; $r--) if ((float)($genRows[$r][$uE] ?? 0) > 0.01) { $lOn = $r; break; }
            $head = [];                                                               // headroom unit allowed pada fOn
            foreach ($allowE as $aU) {
                if (!isset($d3[$aU]) || in_array($aU, ['s1','s2','s3'], true)) continue;
                $mxA = pp_effective_maxload($d3, $model, $aU, $fOn + 1);
                $cA = (float)($genRows[$fOn][strtolower($aU)] ?? 0);
                if ($mxA - $cA > 0.5) $head[] = sprintf('%s %.0f/%.0f', strtoupper($aU), $cA, $mxA);
            }
            $expE = $expOf($genRows[$fOn], $ieVals[$fOn]);
            $reason = (abs($gasNowE - $quota) <= 0.05)
                ? sprintf('absorb daily Gas Quota window (used %.3f / quota %.3f BBTUD)', $gasNowE, $quota)
                : (($expE <= $rMinR[$fOn] + 1.0)
                    ? sprintf('meet PLN export Range Min %.1f MW (export %.1f MW at start row)', $rMinR[$fOn], $expE)
                    : 'merit-order dispatch after higher-priority units');
            $warnings[] = sprintf('Non-required %s started rows %d-%d to %s. Evidence row-level: row %d export=%.1f MW range=[%.1f,%.1f]; remaining allowed-unit headroom at start: %s.',
                strtoupper($uE), $fOn + 1, $lOn + 1, $reason, $fOn + 1, $expE, $rMinR[$fOn], $rMaxR[$fOn],
                $head ? implode(', ', array_slice($head, 0, 5)) : 'none (all at max/limited)');
        }
    }

    /* ================= BUSFLOW MIN & PGN-RT MIN FLOOR REPAIR (PROMPT_RESET §1/§6) =================
     * BusFlow = IE(data1) - ΣBusB  >= busflow_min   (hard, absolut — di bawah minimum bisa trip unit)
     * EnergyPGN_RT = 24 x Σ calc_fuel(G1..G9) - FixedFlow_J x GHV_J/1000  >=  min_pgn_flow  (hard)
     * Repair sinergis: NAIKKAN unit gas Bus A (G9 -> G8 -> G5, lalu G3 last-resort) dan/atau TURUNKAN
     * Bus B non-gas (Babelan, ramp <=5 MW/30min/unit) — export dijaga dalam Range, gas <= quota,
     * startup window (suCap) & stop schedule & fixed & ramp unit dihormati. */
    {
        /* §3.2 PROMPT PGN_FLOW_REBALANCE: acuan = FLOW PGN REAL TIME (MMSCFD)
         * = EnergyPGN_RT / GHV PGN * 1000  >=  Min PGN Flow (MMSCFD).
         * Threshold internal BBTUD = min * GHV_PGN / 1000. GHV PGN kosong/invalid ->
         * fallback GHV From Tegalgede to Jababeka (dicatat sbg warning input). */
        $ghvPgnF = (float)($model['ghv_pgn'] ?? 0);
        if ($ghvPgnF <= 1e-9) {
            $ghvPgnF = (float)($model['ghv_jababeka'] ?? 1034.7564); if ($ghvPgnF <= 1e-9) $ghvPgnF = 1034.7564;
            if ((float)($model['min_pgn_flow'] ?? 0) > 0)
                $warnings[] = sprintf('GHV PGN kosong/invalid — memakai fallback GHV From Tegalgede to Jababeka (%.4f BTU/SCF) utk FLOW PGN REAL TIME. Isi GHV PGN di Gas Quota utk hasil akurat.', $ghvPgnF);
        }
        $pgnMinF = (float)($model['min_pgn_flow'] ?? 0) * $ghvPgnF / 1000.0;
        $qG = $model['gas_quota'] ?? [];
        $ffJDf = ((float)(($qG['pep'] ?? 0) + ($qG['akasia'] ?? 0) + ($qG['baskara'] ?? 0) + ($qG['bbg'] ?? 0)))
                 * ((float)($model['ghv_jababeka'] ?? 1034.7564)) / 1000.0;   // §2: + BBG (MMSCFD)
        $gasJRowF = function (int $r) use (&$genRows, $d3): float {
            $s = 0.0;
            foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9'] as $u)
                $s += calc_fuel($d3, $u, (float)($genRows[$r][$u] ?? 0));
            return $s;
        };
        $pgnRTof = fn(int $r): float => 24.0 * $gasJRowF($r) - $ffJDf;
        /* §4.2 closure donor: turunkan 0.5 MW unit gas di row donor (FLOW surplus, export > Range Min,
         * ramp/bus aman) — dipakai jalur lift utama & kaskade tetangga. */
        $shiftDonor = function (int $exclude) use (&$genRows, $d3, $model, $n, $expOf, $busOf, $ieVals, $rMinR, &$suCap, $isFixed, $actualRows, $GMIN, &$pgnRTof, &$pgnMinF, &$busMin, &$needBus): bool {
            for ($rd = 0; $rd < $n; $rd++) {
                if ($rd === $exclude || isset($actualRows[$rd])) continue;
                if ($pgnRTof($rd) < $pgnMinF + 0.6) continue;
                if ($expOf($genRows[$rd], $ieVals[$rd]) - 0.7 < $rMinR[$rd]) continue;
                foreach (['g9', 'g8', 'g5', 'g2', 'g1'] as $ud) {
                    if (!isset($d3[$ud]) || $isFixed($ud, $rd) || isset($suCap[$ud][$rd]) || pp_is_unit_stopped($d3, $model, $ud, $rd + 1)) continue;
                    $cd = (float)($genRows[$rd][$ud] ?? 0);
                    $flD = ($ud === 'g8' || $ud === 'g9') ? $GMIN : (float)($d3[$ud]['min_ccload'] ?? 20);
                    if ($cd <= $flD + 1e-6 || $cd < 0.01) continue;
                    $svD = $genRows[$rd];
                    $genRows[$rd][$ud] = max($flD, $cd - 0.5);
                    pp_recompute_stgs($genRows[$rd], $d3, $model, $rd + 1);
                    $badD = $pgnRTof($rd) < $pgnMinF - 1e-6
                         || $expOf($genRows[$rd], $ieVals[$rd]) < $rMinR[$rd] - 0.4
                         || ($needBus && $busOf($genRows[$rd], $ieVals[$rd]) < $busMin - 1e-6);
                    if (!$badD) foreach ([$rd - 1, $rd + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if (abs($expOf($genRows[$rd], $ieVals[$rd]) - $expOf($genRows[$nb], $ieVals[$nb])) > pp_export_step_limit($model) + 1e-6) { $badD = true; break; } }
                    if ($badD) { $genRows[$rd] = $svD; pp_recompute_stgs($genRows[$rd], $d3, $model, $rd + 1); continue; }
                    return true;
                }
            }
            return false;
        };
        $busUnitF = $model['bus_unit'] ?? [];
        $isBusA = fn(string $u): bool => strtoupper((string)($busUnitF[$u . '_bus'] ?? 'A')) === 'A';
        $needBus = $busMin > -1e8;
        if ($pgnMinF > 0 || $needBus) {
            for ($swF = 0; $swF < 3; $swF++) {
                $movedF = false;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r])) continue;
                    /* (a) BusFlow floor: turunkan Babelan (Bus B non-gas); bila export akan jatuh di bawah
                     * Range Min, naikkan unit gas Bus A bersamaan (bus naik + PGN naik + export netral). */
                    $gF = 0;
                    while ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6 && $gF++ < 900) {
                        $svF = $genRows[$r]; $did = false;
                        foreach (['b1', 'b2'] as $ub) {
                            if ($isBusA($ub) || $isFixed($ub, $r)) continue;
                            $cb = (float)($genRows[$r][$ub] ?? 0); if ($cb < 0.01) continue;
                            $lo = 0.0;
                            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                $lo = max($lo, (float)($genRows[$nb][$ub] ?? 0) - $bbRamp); }   // ramp Babelan
                            if ($cb <= $lo + 1e-9) continue;
                            $genRows[$r][$ub] = max($lo, $cb - 0.5); $did = true; break;
                        }
                        if (!$did) {
                            /* kaskade: BB row ini terkunci ramp oleh tetangga ber-slack -> turunkan BB
                             * tetangga (bus & export & ramp-nya aman) lalu coba lagi. */
                            $relaxed = false;
                            foreach ([$r + 1, $r - 1] as $nb2) {
                                if ($nb2 < 0 || $nb2 >= $n || isset($actualRows[$nb2])) continue;
                                if ($busOf($genRows[$nb2], $ieVals[$nb2]) < $busMin + 0.55) continue;
                                foreach (['b1', 'b2'] as $ub2) {
                                    if ($isFixed($ub2, $nb2)) continue;
                                    $cb2 = (float)($genRows[$nb2][$ub2] ?? 0); if ($cb2 < 0.01) continue;
                                    $lo2 = 0.0;
                                    foreach ([$nb2 - 1, $nb2 + 1] as $nb3) { if ($nb3 < 0 || $nb3 >= $n) continue;
                                        $lo2 = max($lo2, (float)($genRows[$nb3][$ub2] ?? 0) - $bbRamp); }
                                    if ($cb2 <= $lo2 + 1e-9) continue;
                                    $sv2 = $genRows[$nb2];
                                    $genRows[$nb2][$ub2] = max($lo2, $cb2 - 0.5);
                                    pp_recompute_stgs($genRows[$nb2], $d3, $model, $nb2 + 1);
                                    if ($expOf($genRows[$nb2], $ieVals[$nb2]) < $rMinR[$nb2] - 0.5) {
                                        $genRows[$nb2] = $sv2; pp_recompute_stgs($genRows[$nb2], $d3, $model, $nb2 + 1); continue;
                                    }
                                    $relaxed = true; break;
                                }
                                if ($relaxed) break;
                            }
                            if ($relaxed) { $movedF = true; continue; }
                            break;                                                  // benar-benar terkunci -> evidence
                        }
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $eNow = $expOf($genRows[$r], $ieVals[$r]); $rampBadF = false;
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            $dN = abs($eNow - $expOf($genRows[$nb], $ieVals[$nb]));
                            $dO = abs($expOf($svF, $ieVals[$r]) - $expOf($genRows[$nb], $ieVals[$nb]));
                            if ($dN > 30.0 + 1e-6 && $dN > $dO + 1e-9) { $rampBadF = true; break; } }
                        if ($rampBadF) { $genRows[$r] = $svF; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                        if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 0.3) {
                            $up = false;                                            // export drop -> angkat gas Bus A
                            foreach (['g9', 'g8', 'g5'] as $ug) {
                                if (!isset($d3[$ug]) || !$isBusA($ug) || $isFixed($ug, $r) || isset($suCap[$ug][$r])) continue;
                                if (pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                                $cg = (float)($genRows[$r][$ug] ?? 0); if ($cg < 0.01) continue;
                                $em = pp_effective_maxload($d3, $model, $ug, $r + 1);
                                $cap = min($em > 0 ? $em : $GMAX, ($ug === 'g8' || $ug === 'g9') ? $GMAX : (float)($d3[$ug]['max_load'] ?? 31));
                                foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                    if ((float)($genRows[$nb][$ug] ?? 0) >= 1) $cap = min($cap, (float)$genRows[$nb][$ug] + 30.0); }
                                if ($cg >= $cap - 1e-6) continue;
                                /* export-first §1: quota tidak menahan kompensasi export */
                                $genRows[$r][$ug] = min($cap, $cg + 0.5);
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                $up = true; break;
                            }
                            if (!$up && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] + 0.35) {
                                $genRows[$r] = $svF; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break;
                            }
                        }
                        $movedF = true;
                    }
                    /* (b) PGN-RT floor: naikkan unit gas (Bus A dulu: G9 -> G8 -> G5; lalu G1/G2 Bus B bila
                     * BusFlow masih slack). Export > Range Max -> turunkan Babelan bersamaan. */
                    $gF = 0;
                    while ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 1e-6 && $gF++ < 900) {
                        /* PROMPT FORCE G5/G9 §2/§4 — LEVER GENERATION-NEUTRAL (PILIHAN PERTAMA):
                         * geser beban dari unit gas Bus A yang lebih efisien (G9/G8) ke unit gas running
                         * yang kurang efisien pada low load (G5). Total MW row TETAP -> Export & Bus Flow
                         * netral, Babelan TIDAK disentuh, TIDAK ada start unit; konsumsi gas naik sehingga
                         * FLOW PGN RT naik. Min/max/ramp/fixed/stop tiap unit tetap dijaga. */
                        $flowB4 = $pgnRTof($r); $swapOK = false;
                        foreach ([['g9', 'g5'], ['g8', 'g5']] as $pairSw) {
                            [$uHi, $uLo] = $pairSw;
                            if (!isset($d3[$uHi]) || !isset($d3[$uLo])) continue;
                            if ($isFixed($uHi, $r) || $isFixed($uLo, $r) || isset($suCap[$uHi][$r]) || isset($suCap[$uLo][$r])) continue;
                            if (pp_is_unit_stopped($d3, $model, $uHi, $r + 1) || pp_is_unit_stopped($d3, $model, $uLo, $r + 1)) continue;
                            $cHi = (float)($genRows[$r][$uHi] ?? 0); $cLo = (float)($genRows[$r][$uLo] ?? 0);
                            if ($cHi < 0.01 || $cLo < 0.01) continue;                    // keduanya harus SUDAH running
                            $minHi = ($uHi === 'g8' || $uHi === 'g9') ? $GMIN : (float)($d3[$uHi]['min_ccload'] ?? 20);
                            $emLo  = pp_effective_maxload($d3, $model, $uLo, $r + 1);
                            $capLo = min($emLo > 0 ? $emLo : $GMAX, (float)($d3[$uLo]['max_load'] ?? 31));
                            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                if ((float)($genRows[$nb][$uLo] ?? 0) >= 1) $capLo = min($capLo, (float)$genRows[$nb][$uLo] + 30.0);
                                if ((float)($genRows[$nb][$uHi] ?? 0) >= 1) $minHi = max($minHi, (float)$genRows[$nb][$uHi] - 30.0); }
                            if ($cLo + 0.5 > $capLo + 1e-9 || $cHi - 0.5 < $minHi - 1e-9) continue;
                            $svSw = $genRows[$r];
                            $genRows[$r][$uLo] = $cLo + 0.5; $genRows[$r][$uHi] = $cHi - 0.5;
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            $eSw = $expOf($genRows[$r], $ieVals[$r]);
                            $badSw = ($eSw > $rMaxR[$r] + 1e-6) || ($eSw < $rMinR[$r] - 1e-6)
                                  || ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)
                                  || ($pgnRTof($r) <= $flowB4 + 1e-9);                   // wajib benar-benar menaikkan flow
                            if ($badSw) { $genRows[$r] = $svSw; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                            $swapOK = true; break;
                        }
                        if ($swapOK) { $movedF = true; continue; }                        // re-evaluasi floor
                        if ($trueGasNow() + 0.02 > $quota - 0.0005) {
                            /* §4.2 DAILY-HORIZON REBALANCING: quota penuh — alihkan gas dari periode donor
                             * (FLOW PGN masih surplus & export bisa turun tanpa keluar Range Min) ke periode
                             * gagal. Total harian tetap dlm [quota-0.04, quota]; export/ramp/bus dijaga. */
                            if (!$shiftDonor($r)) break;                              // donor habis -> VI evidence (§4.3)
                        }
                        if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] - 0.6) {   // mepet Range Max: BB turun dulu
                            $dn0 = false;
                            foreach (['b1', 'b2'] as $ub) {
                                if ($isFixed($ub, $r)) continue;
                                $cb = (float)($genRows[$r][$ub] ?? 0); if ($cb < 0.01) continue;
                                $lo = 0.0;
                                foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                    $lo = max($lo, (float)($genRows[$nb][$ub] ?? 0) - $bbRamp); }
                                if ($cb <= $lo + 1e-9) continue;
                                $genRows[$r][$ub] = max($lo, $cb - 0.5);
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                $dn0 = true; break;
                            }
                            if (!$dn0 && $expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] - 0.1) break;   // benar-benar mentok
                        }
                        $lift = false;
                        foreach (['g9', 'g8', 'g5', 'g1', 'g2', 'g4', 'g3', 'g6'] as $ug) {   /* §3.1: G4 (running/headroom) + g3/g6 (auto-skip bila Stop) */
                            if (!isset($d3[$ug]) || $isFixed($ug, $r) || isset($suCap[$ug][$r])) continue;
                            if (pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                            $cg = (float)($genRows[$r][$ug] ?? 0); if ($cg < 0.01) continue;
                            if (!$isBusA($ug) && $needBus
                                && $busOf($genRows[$r], $ieVals[$r]) - 0.5 < $busMin - 1e-6) continue;  // Bus B: jaga bus
                            $em = pp_effective_maxload($d3, $model, $ug, $r + 1);
                            $cap = min($em > 0 ? $em : $GMAX, ($ug === 'g8' || $ug === 'g9') ? $GMAX : (float)($d3[$ug]['max_load'] ?? 31));
                            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                if ((float)($genRows[$nb][$ug] ?? 0) >= 1 && ($ug === 'g8' || $ug === 'g9')) $cap = min($cap, (float)$genRows[$nb][$ug] + 30.0); }
                            if ($cg >= $cap - 1e-6) continue;
                            $svF = $genRows[$r];
                            $genRows[$r][$ug] = min($cap, $cg + 0.5);
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.3) {
                                $dn = false;                                        // over range -> Babelan turun
                                foreach (['b1', 'b2'] as $ub) {
                                    if ($isFixed($ub, $r)) continue;
                                    $cb = (float)($genRows[$r][$ub] ?? 0); if ($cb < 0.01) continue;
                                    $lo = 0.0;
                                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                        $lo = max($lo, (float)($genRows[$nb][$ub] ?? 0) - $bbRamp); }
                                    if ($cb <= $lo + 1e-9) continue;
                                    $genRows[$r][$ub] = max($lo, $cb - 0.5); $dn = true; break;
                                }
                                if (!$dn || $expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5) {
                                    $genRows[$r] = $svF; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break;
                                }
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            }
                            $lift = true; $movedF = true; break;
                        }
                        if (!$lift) {
                            /* kaskade: ramp G8/G9 terkunci tetangga -> naikkan G9/G8 tetangga ber-slack
                             * export (quota-aware) utk membuka jalur, lalu coba lagi. */
                            $rel2 = false;
                            foreach ([$r + 1, $r - 1] as $nb2) {
                                if ($nb2 < 0 || $nb2 >= $n || isset($actualRows[$nb2])) continue;
                                foreach (['g9', 'g8'] as $ug2) {
                                    if ($isFixed($ug2, $nb2) || isset($suCap[$ug2][$nb2])) continue;
                                    if (pp_is_unit_stopped($d3, $model, $ug2, $nb2 + 1)) continue;
                                    $cg2 = (float)($genRows[$nb2][$ug2] ?? 0); if ($cg2 < 0.01) continue;
                                    $em2 = pp_effective_maxload($d3, $model, $ug2, $nb2 + 1);
                                    $cap2 = min($GMAX, $em2 > 0 ? $em2 : $GMAX);
                                    foreach ([$nb2 - 1, $nb2 + 1] as $nb3) { if ($nb3 < 0 || $nb3 >= $n) continue;
                                        if ((float)($genRows[$nb3][$ug2] ?? 0) >= 1) $cap2 = min($cap2, (float)$genRows[$nb3][$ug2] + 30.0); }
                                    if ($cg2 >= $cap2 - 1e-6) continue;
                                    if ($expOf($genRows[$nb2], $ieVals[$nb2]) > $rMaxR[$nb2] - 0.6) continue;
                                    if ($trueGasNow() + 0.02 > $quota - 0.0005) { if (!$shiftDonor($r)) break 2; }
                                    $genRows[$nb2][$ug2] = min($cap2, $cg2 + 0.5);
                                    pp_recompute_stgs($genRows[$nb2], $d3, $model, $nb2 + 1);
                                    $rel2 = true; break;
                                }
                                if ($rel2) break;
                            }
                            if ($rel2) { $movedF = true; continue; }
                            break;                                                   // lever habis -> evidence
                        }
                    }
                }
                if (!$movedF) break;
            }
            /* (c) normalisasi export pasca-repair: repair bus/pgn dapat meninggalkan sisa over/under
             * di rows startup-window; rapikan dgn lever yang sama sambil MENJAGA bus & pgn & quota.
             * Multi-sweep: penurunan Babelan berpropagasi antar-row (ramp 5 MW berantai). */
            for ($swC = 0; $swC < 20; $swC++) { $movedC = false;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) continue;
                $gC = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5 - 1e-6 && $gC++ < 600) {
                    $svC = $genRows[$r]; $didC = false;
                    foreach (['g9', 'g8'] as $ug) {
                        if ($isFixed($ug, $r) || isset($suCap[$ug][$r]) || pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                        $cg = (float)($genRows[$r][$ug] ?? 0); if ($cg <= $GMIN + 1e-6) continue;
                        $genRows[$r][$ug] = max($GMIN, $cg - 0.5);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 1e-6) {          // jangan rusak pgn
                            $genRows[$r] = $svC; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue;
                        }
                        $didC = true; break;
                    }
                    if (!$didC) foreach (['b1', 'b2'] as $ub) {
                        if ($isFixed($ub, $r)) continue;
                        $cb = (float)($genRows[$r][$ub] ?? 0); if ($cb < 0.01) continue;
                        $lo = 0.0;
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            $lo = max($lo, (float)($genRows[$nb][$ub] ?? 0) - $bbRamp); }
                        if ($cb <= $lo + 1e-9) {
                            foreach ([$r - 1, $r + 1] as $nb2) {                     // kaskade: buka ramp tetangga
                                if ($nb2 < 0 || $nb2 >= $n || isset($actualRows[$nb2]) || $isFixed($ub, $nb2)) continue;
                                $cb2 = (float)($genRows[$nb2][$ub] ?? 0); if ($cb2 < 0.01) continue;
                                $lo2 = 0.0;
                                foreach ([$nb2 - 1, $nb2 + 1] as $nb3) { if ($nb3 < 0 || $nb3 >= $n) continue;
                                    $lo2 = max($lo2, (float)($genRows[$nb3][$ub] ?? 0) - $bbRamp); }
                                if ($cb2 <= $lo2 + 1e-9) continue;
                                $sv2 = $genRows[$nb2];
                                $genRows[$nb2][$ub] = max($lo2, $cb2 - 0.5);
                                pp_recompute_stgs($genRows[$nb2], $d3, $model, $nb2 + 1);
                                if ($expOf($genRows[$nb2], $ieVals[$nb2]) < $rMinR[$nb2] - 0.5
                                    || ($needBus && $busOf($genRows[$nb2], $ieVals[$nb2]) < $busMin - 1e-6)) {
                                    $genRows[$nb2] = $sv2; pp_recompute_stgs($genRows[$nb2], $d3, $model, $nb2 + 1); continue;
                                }
                                $didC = true; break;
                            }
                            if ($didC) break;
                            continue;
                        }
                        $genRows[$r][$ub] = max($lo, $cb - 0.5);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        if ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {   // jangan rusak bus
                            $genRows[$r] = $svC; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break;
                        }
                        $didC = true; break;
                    }
                    if (!$didC) break;
                    $movedC = true;
                }
                $gC = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] + 0.35 && $gC++ < 600) {   /* §3: target = Range Min + margin */
                    $didC = false;
                    foreach (['g9', 'g8', 'g5', 'g1', 'g2', 'g4', 'g3', 'g6'] as $ug) {   /* §3.1: G4 (running/headroom) + g3/g6 (auto-skip bila Stop) */
                        if (!isset($d3[$ug]) || $isFixed($ug, $r) || isset($suCap[$ug][$r]) || pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                        $cg = (float)($genRows[$r][$ug] ?? 0); if ($cg < 0.01) continue;
                        if (!$isBusA($ug) && $needBus && $busOf($genRows[$r], $ieVals[$r]) - 0.5 < $busMin - 1e-6) continue;
                        /* PROMPT LNG_EXPORT_RANGE §1/§13: PLN Export Range Min = target dispatch —
                         * quota TIDAK boleh menahan repair export; selisih gas menjadi Additional LNG. */
                        $em = pp_effective_maxload($d3, $model, $ug, $r + 1);
                        $cap = min($em > 0 ? $em : $GMAX, ($ug === 'g8' || $ug === 'g9') ? $GMAX : (float)($d3[$ug]['max_load'] ?? 31));
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            if ((float)($genRows[$nb][$ug] ?? 0) >= 1 && ($ug === 'g8' || $ug === 'g9')) $cap = min($cap, (float)$genRows[$nb][$ug] + 30.0); }
                        if ($cg >= $cap - 1e-6) continue;
                        $nv = min($cap, $cg + 0.5);
                        $skR = pp_get_skip_load($model, $ug, $r + 1);
                        if ($skR) {                                                    // Skip Load = hard: lompati band
                            [$lo2, $hi2] = $skR;                                       // return = [lo, hi] numerik
                            if ($nv >= $lo2 - 0.55 && $nv <= $hi2 + 0.55) $nv = ($hi2 + 0.6 <= $cap) ? $hi2 + 0.6 : max($cg, $lo2 - 0.6);
                            if ($nv <= $cg + 1e-9) continue;
                        }
                        $genRows[$r][$ug] = $nv;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $didC = true; break;
                    }
                    if (!$didC) foreach (['b1', 'b2'] as $ub) {                       // BB naik (ramp & bus-aware)
                        if ($isFixed($ub, $r) || pp_is_unit_stopped($d3, $model, $ub, $r + 1)) continue;
                        $cb = (float)($genRows[$r][$ub] ?? 0); $mb = (float)($d3[$ub]['max_load'] ?? 120);
                        $lim = $mb;
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            $lim = min($lim, (float)($genRows[$nb][$ub] ?? 0) + $bbRamp); }
                        if ($cb >= $lim - 1e-6) continue;
                        if ($needBus && $busOf($genRows[$r], $ieVals[$r]) - 0.5 < $busMin - 1e-6) continue;
                        $genRows[$r][$ub] = min($lim, $cb + 0.5);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $didC = true; break;
                    }
                    if (!$didC) break;
                    $movedC = true;
                }
            }
            if (!$movedC) break; }

            /* (c1b) EXPORT START-BLOCK (PROMPT §4/§5): jika export MASIH under setelah unit running
             * dinaikkan, START unit Block-Required non-stop yang masih OFF (G5, lalu G1) pada rentang
             * row-under yang kontigu — di min-CC. Startup sequence (5/15 Additional HRSG atau STG mode)
             * ditegakkan ulang oleh $applyStgHold() sesudah blok ini. Unit Stop All Day tak pernah dipilih. */
            {
                $under = [];
                for ($r = 0; $r < $n; $r++)
                    if (!isset($actualRows[$r]) && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] + 0.3)
                        $under[$r] = true;
                if ($under) {
                    $startCands = [];
                    /* PRIORITY AUDIT (sistemik): kandidat start = GTG Jababeka HRSG (g1–g6) dari input
                     * user — blok Required lebih dulu, lalu Unit Priority. Bukan hardcode [g5,g1,g2,g4].
                     * Dengan priority Block2 = g1,g2,g5: bila G1 running, kandidat berikut = G2 (T01). */
                    foreach (pp_priority_sort_required_first($model, pp_priority_flat($model, '/^g[1-6]$/') ?: ['g1','g2','g3','g4','g5','g6']) as $us) {
                        if (!isset($d3[$us])) continue;
                        if (($lastStatus[$us] ?? '') === 'running') continue;     // sudah on -> ditangani lever lift
                        $anyOn = false; for ($r = 0; $r < $n; $r++) if ((float)($genRows[$r][$us] ?? 0) > 0.01) { $anyOn = true; break; }
                        if ($anyOn) continue;
                        $blocked = true;                                          // hanya kandidat bila TIDAK stop full day
                        for ($r = 0; $r < $n; $r++) if (!pp_is_unit_stopped($d3, $model, $us, $r + 1)) { $blocked = false; break; }
                        if ($blocked) continue;
                        $startCands[] = $us;
                    }
                    foreach ($startCands as $us) {
                        $mcc = (float)($d3[$us]['min_ccload'] ?? ($d3[$us]['min_scload'] ?? 20));
                        $started = false;
                        foreach (array_keys($under) as $r) {
                            if ($isFixed($us, $r) || pp_is_unit_stopped($d3, $model, $us, $r + 1) || isset($actualRows[$r])) continue;
                            if ((float)($genRows[$r][$us] ?? 0) > 0.01) continue;
                            $sv = $genRows[$r];
                            $genRows[$r][$us] = $mcc;
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            if ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                            if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                            $started = true;
                        }
                        if ($started) {
                            /* STRESS #44: jaga MINIMUM RUNTIME — segmen ON hasil start diperpanjang hingga
                             * >= 12 rows (gtg CC 6h) dgn min-CC, selama export <= Range Max; segmen yang tak
                             * bisa mencapai min-run dibatalkan (unit kembali 0). */
                            $minRunN = 12;
                            $segs2 = []; $rr0 = -1;
                            for ($r = 0; $r < $n; $r++) {
                                $on2 = (float)($genRows[$r][$us] ?? 0) > 0.01;
                                if ($on2 && $rr0 < 0) $rr0 = $r;
                                if ((!$on2 || $r === $n - 1) && $rr0 >= 0) { $segs2[] = [$rr0, $on2 ? $r : $r - 1]; $rr0 = -1; }
                            }
                            foreach ($segs2 as [$sa, $sb]) {
                                $len = $sb - $sa + 1; if ($len >= $minRunN) continue;
                                for ($r = $sb + 1; $r < $n && $len < $minRunN; $r++) {
                                    if ($isFixed($us, $r) || isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $us, $r + 1)) break;
                                    if ((float)($genRows[$r][$us] ?? 0) > 0.01) { $len++; continue; }
                                    $sv2 = $genRows[$r]; $genRows[$r][$us] = $mcc;
                                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                    if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6) { $genRows[$r] = $sv2; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                                    $len++;
                                }
                                for ($r = $sa - 1; $r >= 0 && $len < $minRunN; $r--) {
                                    if ($isFixed($us, $r) || isset($actualRows[$r]) || pp_is_unit_stopped($d3, $model, $us, $r + 1)) break;
                                    if ((float)($genRows[$r][$us] ?? 0) > 0.01) { $len++; continue; }
                                    $sv2 = $genRows[$r]; $genRows[$r][$us] = $mcc;
                                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                    if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6) { $genRows[$r] = $sv2; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                                    $len++;
                                }
                                if ($len < $minRunN) {                                  // tak tercapai -> batalkan segmen
                                    for ($r = 0; $r < $n; $r++)
                                        if ((float)($genRows[$r][$us] ?? 0) > 0.01 && $r >= $sa - ($minRunN) && $r <= $sb + $minRunN && !$isFixed($us, $r) && !isset($actualRows[$r])) {
                                            $genRows[$r][$us] = 0.0; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                        }
                                }
                            }
                            if (isset($applyStgHold)) $applyStgHold();
                            for ($r = 0; $r < $n; $r++)
                                $under[$r] = !isset($actualRows[$r]) && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] + 0.3 ? true : false;
                            $under = array_filter($under);
                            $warnings[] = sprintf('Non-required %s dinyalakan sbg LAST RESORT (export floor, bukan stop-schedule) — evidence row-level tersedia.', strtoupper($us));
                        }
                        if (!$under) break;
                    }
                    if (isset($applyStgHold)) $applyStgHold();                     // 5/15 atau STG mode utk unit yang baru start
                    /* naikkan unit yang baru start dari min-CC menuju headroom pada row under tersisa */
                    for ($sw = 0; $sw < 8; $sw++) { $mv = false;
                        for ($r = 0; $r < $n; $r++) {
                            if (isset($actualRows[$r]) || $expOf($genRows[$r], $ieVals[$r]) >= $rMinR[$r] + 0.5) continue;
                            foreach ($startCands as $us) {
                                if ($isFixed($us, $r) || isset($suCap[$us][$r]) || pp_is_unit_stopped($d3, $model, $us, $r + 1)) continue;
                                $cg = (float)($genRows[$r][$us] ?? 0); if ($cg < 0.01) continue;
                                $em = pp_effective_maxload($d3, $model, $us, $r + 1); $cap = $em > 0 ? $em : (float)($d3[$us]['max_load'] ?? 31);
                                if ($cg >= $cap - 1e-6) continue;
                                $sv = $genRows[$r]; $genRows[$r][$us] = min($cap, $cg + 0.5);
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                if (($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) || $expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                                $mv = true;
                            }
                        }
                        if (!$mv) break;
                    }
                }
            }
            /* (c2) VALLEY DIG: row over yang terkunci rantai ramp Babelan berlapis — turunkan BB
             * berantai dari radius 6 menuju row target (luar -> dalam), tiap row dijaga export >=
             * Range Min & bus >= min. Membentuk lembah ramp-valid 5 MW/30min per unit. */
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) continue;
                $guard = 0;
                while ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 0.5 - 1e-6 && $guard++ < 400) {
                    $any = false;
                    for ($d = 6; $d >= 0; $d--) {
                        foreach ($d === 0 ? [$r] : [$r - $d, $r + $d] as $q) {
                            if ($q < 0 || $q >= $n || isset($actualRows[$q])) continue;
                            foreach (['b1', 'b2'] as $ub) {
                                if ($isFixed($ub, $q)) continue;
                                $cb = (float)($genRows[$q][$ub] ?? 0); if ($cb < 0.01) continue;
                                $lo = 0.0;
                                foreach ([$q - 1, $q + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                    $lo = max($lo, (float)($genRows[$nb][$ub] ?? 0) - $bbRamp); }
                                if ($cb <= $lo + 1e-9) continue;
                                if ($q !== $r && $expOf($genRows[$q], $ieVals[$q]) - 0.5 < $rMinR[$q] - 0.4) continue;
                                $sv = $genRows[$q];
                                $genRows[$q][$ub] = max($lo, $cb - 0.5);
                                pp_recompute_stgs($genRows[$q], $d3, $model, $q + 1);
                                if ($needBus && $busOf($genRows[$q], $ieVals[$q]) < $busMin - 1e-6) {
                                    $genRows[$q] = $sv; pp_recompute_stgs($genRows[$q], $d3, $model, $q + 1); continue;
                                }
                                $any = true;
                            }
                        }
                    }
                    if (!$any) break;
                }
            }
            /* (d) QUOTA TOP-UP pasca-repair: repair dapat menurunkan gas di bawah window
             * [quota-0.04, quota]; kembalikan dgn menaikkan unit gas pada rows ber-slack. */
            $gT = 0;
            while ($trueGasNow() < $quota - 0.0395 && $gT++ < 160) {
                $bestR = -1; $bestU = '';
                for ($r = 0; $r < $n && $bestR < 0; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] - 0.6) continue;
                    /* ramp guard: menaikkan row ini tak boleh memperburuk |dExport| tetangga melewati 30 */
                    $eProbe = $expOf($genRows[$r], $ieVals[$r]) + 0.7; $rampBad = false;
                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if ($eProbe - $expOf($genRows[$nb], $ieVals[$nb]) > 30.0 - 0.5) { $rampBad = true; break; } }
                    if ($rampBad) continue;
                    foreach (['g9','g8','g5','g1','g2'] as $ug) {
                        if (!isset($d3[$ug]) || $isFixed($ug, $r) || isset($suCap[$ug][$r]) || pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                        $cg = (float)($genRows[$r][$ug] ?? 0); if ($cg < 0.01) continue;
                        if (!$isBusA($ug) && $needBus && $busOf($genRows[$r], $ieVals[$r]) - 0.5 < $busMin - 1e-6) continue;
                        $em = pp_effective_maxload($d3, $model, $ug, $r + 1);
                        $cap = min($em > 0 ? $em : $GMAX, ($ug === 'g8' || $ug === 'g9') ? $GMAX : (float)($d3[$ug]['max_load'] ?? 31));
                        foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            if ((float)($genRows[$nb][$ug] ?? 0) >= 1 && ($ug === 'g8' || $ug === 'g9')) $cap = min($cap, (float)$genRows[$nb][$ug] + 30.0); }
                        if ($cg >= $cap - 1e-6) continue;
                        $bestR = $r; $bestU = $ug; break;
                    }
                }
                if ($bestR < 0) break;
                $gasGapNow = max(0.0, $quota - $trueGasNow());
                $stepNow = min(2.0, max(0.5, $gasGapNow * 8.0));
                $genRows[$bestR][$bestU] = min($GMAX * 2, (float)$genRows[$bestR][$bestU] + $stepNow);
                pp_recompute_stgs($genRows[$bestR], $d3, $model, $bestR + 1);
            }
            if ($trueGasNow() < 0.005)
                $warnings[] = sprintf('Gas quota under-utilized after repair (%.4f < %.4f): remaining rows are locked by Export Range Max / export-ramp / BusFlow — no feasible gas lever remains. VALID-INFEASIBLE.', $trueGasNow(), $quota);
            /* (d2) FINAL QUOTA CLAMP: rebalancing/lift tidak boleh meninggalkan total > quota. */
            $gT2 = 0;
            while ($trueGasNow() > $quota + 0.0004 && $gT2++ < 160) {
                $done = false;
                for ($r2 = 0; $r2 < $n && !$done; $r2++) {
                    if (isset($actualRows[$r2])) continue;
                    if ($pgnMinF > 0 && $pgnRTof($r2) < $pgnMinF + 0.55) continue;
                    if ($expOf($genRows[$r2], $ieVals[$r2]) - 0.7 < $rMinR[$r2]) continue;
                    foreach (['g9', 'g8'] as $ud2) {
                        if ($isFixed($ud2, $r2) || isset($suCap[$ud2][$r2]) || pp_is_unit_stopped($d3, $model, $ud2, $r2 + 1)) continue;
                        $cd2 = (float)($genRows[$r2][$ud2] ?? 0); if ($cd2 <= $GMIN + 1e-6) continue;
                        $genRows[$r2][$ud2] = max($GMIN, $cd2 - 0.5);
                        pp_recompute_stgs($genRows[$r2], $d3, $model, $r2 + 1);
                        $done = true; break;
                    }
                }
                if (!$done) break;
            }
            /* (e) UNIT-RAMP CLAMP: G8/G9 <=30, G7/G10 <=55 MW/30min (di luar transisi 0). */
            foreach ([['g8',30.0],['g9',30.0],['g7',55.0],['g10',55.0]] as [$uRm,$limRm]) {
                if (!isset($d3[$uRm])) continue;
                for ($r = 1; $r < $n; $r++) {
                    $a = (float)($genRows[$r-1][$uRm] ?? 0); $b = (float)($genRows[$r][$uRm] ?? 0);
                    if ($a <= 0.01 || $b <= 0.01 || abs($b - $a) <= $limRm + 1e-6) continue;
                    if ($isFixed($uRm, $r) || isset($suCap[$uRm][$r]) || isset($actualRows[$r])) continue;
                    $tgt = $b > $a ? $a + $limRm : $a - $limRm;
                    $minU = (float)($d3[$uRm]['min_scload'] ?? ($d3[$uRm]['min_ccload'] ?? 20));
                    if ($tgt < $minU - 0.01) continue;                    // clamp tidak boleh menembus min-load
                    $genRows[$r][$uRm] = $tgt;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                }
            }
            /* (e2) sanitasi min-load MM2100: dispatch cluster tak boleh meninggalkan 0<load<min. */
            foreach (['g10','ge1','ge2','ge3','ge4'] as $uMm) {
                if (!isset($d3[$uMm])) continue;
                $minMm = (float)($d3[$uMm]['min_scload'] ?? ($d3[$uMm]['min_ccload'] ?? 5));
                for ($r = 0; $r < $n; $r++) {
                    $vMm = (float)($genRows[$r][$uMm] ?? 0);
                    if ($vMm > 0.01 && $vMm < $minMm - 0.01 && !$isFixed($uMm, $r) && !isset($suCap[$uMm][$r]) && !isset($actualRows[$r])) {
                        $genRows[$r][$uMm] = $minMm;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    }
                }
            }
            /* (f) residual export-ramp evidence */
            for ($r = 1; $r < $n; $r++) {
                $d = abs($expOf($genRows[$r], $ieVals[$r]) - $expOf($genRows[$r-1], $ieVals[$r-1]));
                if ($d > 30.0 + 0.01)
                    $warnings[] = sprintf('Export ramp could not be smoothed after repair: |dExport|=%.1f MW at row %d exceeds the 35 MW limit — remaining levers are locked by BusFlow/PGN/quota/start-sequence hard constraints.', $d, $r + 1);
            }
            /* ============ UNIT CONSOLIDATION / DECOMMITMENT CHECK (PROMPT MERIT ORDER) ============
             * "Lebih baik sedikit unit beban tinggi daripada banyak unit minimum load."
             * Untuk unit non-required/non-continuous yang running di area minimum: coba candidate
             * TAIL-DECOMMIT (stop dari row k s/d akhir segmen) dgn beban dipindah 1:1 ke unit
             * priority lebih tinggi (G9 -> G8 -> G4) yang masih punya headroom & ramp valid.
             * Export tak berubah per-row (kompensasi 1:1) -> Range/ramp export aman; bus & PGN
             * dicek; runtime min-run segmen tersisa dijaga; candidate diterima hanya bila total
             * gas TURUN (proxy heatrate/cost) — cost comparison dicatat sebagai warning. */
            {
                $reqM = array_keys((array)($model['required_mode'] ?? []));
                $ccs  = array_map('strtolower', (array)($model['unit_cannot_stop'] ?? []));
                $minRunRows = 8;                                                   // gtg 4h default
                /* PRIORITY AUDIT (sistemik / Rule A4): kandidat decommit = GTG Jababeka non-required
                 * urut Unit Priority TERENDAH dulu (reverse dari input user), bukan hardcode [g5,g2]. */
                foreach (pp_priority_flat_reverse($model, '/^g[1-6]$/') ?: ['g6','g4','g3','g5','g2','g1'] as $uD) {
                    if (!isset($d3[$uD]) || in_array($uD, $reqM, true) || in_array($uD, $ccs, true)) continue;
                    /* segmen ON terakhir */
                    $a = -1; $b = -1;
                    for ($r = $n - 1; $r >= 0; $r--) if ((float)($genRows[$r][$uD] ?? 0) > 0.01) { $b = $r; break; }
                    if ($b < 0) continue;
                    for ($r = $b; $r >= 0 && (float)($genRows[$r][$uD] ?? 0) > 0.01; $r--) $a = $r;
                    $mccD = (float)($d3[$uD]['min_ccload'] ?? 20);
                    /* coba k dari (a + minRun) .. b: decommit [k..b]; pilih k terkecil yang seluruhnya valid */
                    $bestK = -1; $bestRows = null; $gas0 = $trueGasNow();
                    for ($k = max($a + $minRunRows, $a); $k <= $b; $k++) {
                        if ($k === $a && $b - $a + 1 < $minRunRows) break;         // segmen terlalu pendek utk sisa
                        $save = $genRows; $okAll = true;
                        for ($r = $k; $r <= $b && $okAll; $r++) {
                            if ($isFixed($uD, $r) || isset($actualRows[$r])) { $okAll = false; break; }
                            $need2 = (float)($genRows[$r][$uD] ?? 0); if ($need2 < 0.01) continue;
                            $genRows[$r][$uD] = 0.0;
                            $left = $need2;
                            /* PRIORITY AUDIT (sistemik): donor = GTG Jababeka running urut Unit
                             * Priority tertinggi dari input user, bukan hardcode [g9,g8,g4]. */
                            foreach (pp_priority_flat($model, '/^g([1-6]|8|9)$/') ?: ['g9','g8','g4'] as $uH) {
                                if ($uH === $uD) continue;                        // never donate to the unit being stopped
                                if ($left <= 1e-9) break;
                                if ($isFixed($uH, $r) || isset($suCap[$uH][$r]) || pp_is_unit_stopped($d3, $model, $uH, $r + 1)) continue;
                                $cH = (float)($genRows[$r][$uH] ?? 0); if ($cH < 0.01) continue;   // hanya unit on
                                $em = pp_effective_maxload($d3, $model, $uH, $r + 1);
                                $cap = min($em > 0 ? $em : $GMAX, in_array($uH, ['g8','g9'], true) ? $GMAX : (float)($d3[$uH]['max_load'] ?? 31));
                                $add = min($left, max(0.0, $cap - $cH));
                                if ($add <= 1e-9) continue;
                                $genRows[$r][$uH] = $cH + $add; $left -= $add;
                            }
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                            if ($left > 0.05
                                || $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-9
                                || ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)
                                || ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 1e-6)) $okAll = false;
                        }
                        if ($okAll) {
                            /* ramp G8/G9 antar-row (termasuk batas k-1|k) */
                            for ($r = max(1, $k - 1); $r <= min($n - 1, $b + 1) && $okAll; $r++)
                                foreach (['g9','g8'] as $uH)
                                    if ((float)$genRows[$r-1][$uH] >= 1 && (float)$genRows[$r][$uH] >= 1
                                        && abs((float)$genRows[$r][$uH] - (float)$genRows[$r-1][$uH]) > 30.0 + 1e-6) { $okAll = false; break; }
                        }
                        if ($okAll && $trueGasNow() < $gas0 - 1e-6) { $bestK = $k; $bestRows = $genRows; $genRows = $save; break; }
                        $genRows = $save;
                    }
                    if ($bestK >= 0 && $bestRows !== null) {
                        $gasBefore = $trueGasNow();
                        $genRows = $bestRows;
                        $gasAfter = $trueGasNow();
                        $warnings[] = sprintf('CONSOLIDATION (merit order): %s (non-required, min-load) di-stop rows %d-%d; beban dipindah ke G9/G8/G4 (priority lebih tinggi, headroom tersedia). Cost comparison: gas %s %.3f -> %.3f BBTUD (hemat %.3f) — candidate lebih sedikit unit @ load tinggi MENANG. Export/bus/PGN/ramp tervalidasi per-row.', strtoupper($uD), $bestK + 1, $b + 1, 'Jababeka', $gasBefore, $gasAfter, $gasBefore - $gasAfter);
                    }
                }
            }

            /* ============ PATCH T09 — MERIT LOAD SHIFT (per-row, priority-driven) ============
             * §4.7.3: selama unit priority TINGGI masih punya headroom (effective max per row), unit
             * priority RENDAH tidak boleh berada di atas floor min-load-nya. Pass ini menggeser MW per
             * row dari unit rendah (di atas floor) ke unit tinggi (di bawah cap) — MW-neutral, dgn guard:
             * fixed/actual/stop/startup-cap, skip-load band, ramp unit (G7/G10 55, G8/G9 30), export tetap
             * dlm [RangeMin,RangeMax] + export-ramp <=30 vs tetangga, BusFlow, Min PGN Flow, gas <= quota.
             * Gas yang terbebas (heat-rate lebih baik) diisi ulang oleh FINAL GAS TOP-UP di bawah. */
            {
                $tiersMS = pp_priority_flat($model, '/^g(10|[1-9])$/') ?: ['g9','g8','g1','g2','g5','g3','g4','g6','g10','g7'];
                $rankMS  = array_flip($tiersMS);
                $rampMS  = ['g7' => 55.0, 'g10' => 55.0, 'g8' => 30.0, 'g9' => 30.0];
                $flrMS = function (string $u) use ($d3) { return (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 5)); };
                $capMS = function (string $u, int $r) use ($d3, $model, $GMAX, &$genRows, $rampMS, $n) {
                    $em = pp_effective_maxload($d3, $model, $u, $r + 1);
                    $cap = min($em > 0 ? $em : $GMAX, in_array($u, ['g8','g9'], true) ? $GMAX : (float)($d3[$u]['max_load'] ?? 31));
                    if (isset($rampMS[$u])) foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if ((float)($genRows[$nb][$u] ?? 0) >= 1) $cap = min($cap, (float)$genRows[$nb][$u] + $rampMS[$u]); }
                    return $cap;
                };
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r])) continue;
                    for ($msIt = 0; $msIt < 400; $msIt++) {
                        $hi = null; $hiCap = 0.0;
                        foreach ($tiersMS as $uH) {
                            if (!isset($d3[$uH]) || $isFixed($uH, $r) || isset($suCap[$uH][$r]) || pp_is_unit_stopped($d3, $model, $uH, $r + 1)) continue;
                            $cH = (float)($genRows[$r][$uH] ?? 0);
                            if ($cH < $flrMS($uH) - 1e-6 || $cH < 0.01) continue;              // off / lead-in startup: jangan diganggu
                            $cp = $capMS($uH, $r);
                            if ($cH < $cp - 0.05) { $hi = $uH; $hiCap = $cp; break; }
                        }
                        if ($hi === null) break;
                        $lo = null;
                        foreach (array_reverse($tiersMS) as $uL) {
                            if (($rankMS[$uL] ?? 0) <= ($rankMS[$hi] ?? 0)) continue;          // hanya priority LEBIH RENDAH dari $hi
                            if (!isset($d3[$uL]) || $isFixed($uL, $r) || isset($suCap[$uL][$r]) || pp_is_unit_stopped($d3, $model, $uL, $r + 1)) continue;
                            $cL = (float)($genRows[$r][$uL] ?? 0);
                            if ($cL > $flrMS($uL) + 0.05) { $lo = $uL; break; }
                        }
                        if ($lo === null) break;
                        $cH0 = (float)$genRows[$r][$hi]; $cL0 = (float)$genRows[$r][$lo];
                        $step = min(0.5, $hiCap - $cH0, $cL0 - $flrMS($lo));
                        if ($step <= 1e-6) break;
                        $nvH = $cH0 + $step; $nvL = $cL0 - $step;
                        $skH = pp_get_skip_load($model, $hi, $r + 1); $skL = pp_get_skip_load($model, $lo, $r + 1);
                        if ($skH && $nvH >= $skH[0] - 0.55 && $nvH <= $skH[1] + 0.55) break;
                        if ($skL && $nvL >= $skL[0] - 0.55 && $nvL <= $skL[1] + 0.55) break;
                        if (isset($rampMS[$lo])) { $okL = true;
                            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                if ((float)($genRows[$nb][$lo] ?? 0) >= 1 && abs($nvL - (float)$genRows[$nb][$lo]) > $rampMS[$lo] + 1e-6) { $okL = false; break; } }
                            if (!$okL) break;
                        }
                        $save = $genRows[$r];
                        $genRows[$r][$hi] = $nvH; $genRows[$r][$lo] = $nvL;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        /* Kompensasi kopling STG: swap GTG<->GTG tidak export-neutral (S naik/turun beda blok).
                         * Bila export jatuh di bawah floor, naikkan $hi (masih ada headroom) sampai floor pulih. */
                        for ($cmp = 0; $cmp < 20 && $expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-6; $cmp++) {
                            $cHc = (float)$genRows[$r][$hi];
                            if ($cHc >= $hiCap - 1e-6) break;
                            $genRows[$r][$hi] = min($hiCap, $cHc + 0.1);
                            pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        }
                        $eN = $expOf($genRows[$r], $ieVals[$r]); $bad = false;
                        if ($eN < $rMinR[$r] - 1e-6 || $eN > $rMaxR[$r] + 1e-6) $bad = true;
                        if (!$bad) foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                            if (abs($eN - $expOf($genRows[$nb], $ieVals[$nb])) > pp_export_step_limit($model) + 1e-6) { $bad = true; break; } }
                        if (!$bad && $needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) $bad = true;
                        if (!$bad && $pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 1e-6) $bad = true;
                        if (!$bad && $trueGasNow() > $quota + 0.0004) $bad = true;
                        if ($bad) { $genRows[$r] = $save; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                    }
                }
            }

            /* ============ FINAL GAS TOP-UP (PROMPT LNG_FIXED) ============
             * LNG fixed contract: total gas wajib kembali ke [quota-0.04, quota] setelah
             * konsolidasi/penghematan — naikkan unit priority tertinggi (G9 -> G8 -> G4) pada rows
             * dgn headroom, export <= Range Max - 0.6, ramp/pgn/bus valid. */
            $gTU = 0;
            while ($trueGasNow() < $quota - 0.038 && $gTU++ < 400) {
                $doneU = false;
                for ($r = 0; $r < $n && !$doneU; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if ($expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] - 0.6) continue;
                    /* PRIORITY AUDIT (sistemik): top-up gas mengikuti Unit Priority input user. */
                    foreach (pp_priority_flat($model, '/^g([1-6]|8|9)$/') ?: ['g9','g8','g4'] as $uT) {
                        if (!isset($d3[$uT]) || $isFixed($uT, $r) || isset($suCap[$uT][$r]) || pp_is_unit_stopped($d3, $model, $uT, $r + 1)) continue;
                        $cT = (float)($genRows[$r][$uT] ?? 0); if ($cT < 0.01) continue;
                        $em = pp_effective_maxload($d3, $model, $uT, $r + 1);
                        $cap = min($em > 0 ? $em : $GMAX, in_array($uT, ['g8','g9'], true) ? $GMAX : (float)($d3[$uT]['max_load'] ?? 31));
                        if (in_array($uT, ['g8','g9'], true))
                            foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                if ((float)($genRows[$nb][$uT] ?? 0) >= 1) $cap = min($cap, (float)$genRows[$nb][$uT] + 30.0); }
                        if ($cT >= $cap - 1e-6) continue;
                        $skT = pp_get_skip_load($model, $uT, $r + 1);
                        $nvT = min($cap, $cT + 0.4);
                        if ($skT) { [$loT, $hiT] = $skT; if ($nvT >= $loT - 0.55 && $nvT <= $hiT + 0.55) { $nvT = ($hiT + 0.6 <= $cap) ? $hiT + 0.6 : $cT; } if ($nvT <= $cT + 1e-9) continue; }
                        if ($trueGasNow() + 0.03 > $quota + 0.0004) { $doneU = false; break; }   // jangan overshoot
                        $genRows[$r][$uT] = $nvT;
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $doneU = true; break;
                    }
                }
                if (!$doneU) break;
            }

            /* ============ PATCH B-SERIES — FINAL EXPORT REPAIR + EXHAUSTION EVIDENCE ============
             * Re-implementasi pass sesi handoff yang hilang: (2) Export-max shedding termasuk Babelan
             * coordinated ladder/plateau descent (ramp 5/row), (1) Export floor raise merit-order ramp-aware,
             * (3) unit-ramp smoothing G7-G10 (termasuk transfer GAS-NEUTRAL intra-row). Residual yang
             * benar-benar exhausted diberi evidence NUMERIK row-level (pola cocok validator) — bukan
             * warning generik (handoff §4). */
            {
                $rampU = ['g7' => 55.0, 'g10' => 55.0, 'g8' => 30.0, 'g9' => 30.0, 'b1' => $bbRamp, 'b2' => $bbRamp];
                $flrOf = function (string $u) use ($d3) {
                    if ($u === 'b1' || $u === 'b2') return 0.0;
                    return (float)($d3[$u]['min_ccload'] ?? ($d3[$u]['min_scload'] ?? 5)); };
                $capOf = function (string $u, int $r) use ($d3, $model, $GMAX, &$genRows, $rampU, $n) {
                    $em = pp_effective_maxload($d3, $model, $u, $r + 1);
                    $cap = $em > 0 ? $em : (in_array($u, ['g8','g9'], true) ? $GMAX : (float)($d3[$u]['max_load'] ?? 120));
                    if (isset($rampU[$u])) foreach ([$r-1, $r+1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if ((float)($genRows[$nb][$u] ?? 0) >= 1) $cap = min($cap, (float)$genRows[$nb][$u] + $rampU[$u]); }
                    return $cap; };
                $tunable = function (string $u, int $r) use ($d3, $model, &$actualRows, $isFixed, &$suCap) {
                    if (!isset($d3[$u]) || isset($actualRows[$r])) return false;
                    if ($isFixed($u, $r) || isset($suCap[$u][$r]) || pp_is_unit_stopped($d3, $model, $u, $r + 1)) return false;
                    return true; };
                $skOk = function (string $u, int $r, float $nv) use ($model) {
                    $sk = pp_get_skip_load($model, $u, $r + 1);
                    return !($sk && $nv >= $sk[0] - 0.55 && $nv <= $sk[1] + 0.55); };
                /* Babelan plateau/ladder descent (tryLowerChain re-apply) */
                $tryLowerChain = function (int $r, float $need) use (&$genRows, $d3, $model, $n, $expOf, $ieVals, $rMinR, $tunable, $rampU) {
                    $units = ['b1','b2']; $save = $genRows; $left = $need;
                    for ($span = 0; $span < 12 && $left > 0.05; $span++) {
                        $movedAny = false;
                        foreach ($units as $ub) {
                            for ($k = max(0, $r - $span); $k <= min($n - 1, $r + $span); $k++) {
                                if (!$tunable($ub, $k)) continue;
                                $cv = (float)($genRows[$k][$ub] ?? 0); if ($cv <= 0.05) continue;
                                $lim = $cv;
                                foreach ([$k-1, $k+1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                    $nv2 = (float)($genRows[$nb][$ub] ?? 0);
                                    if ($nv2 >= 0.05) $lim = min($lim, $nv2 + $rampU[$ub]); }
                                $step = min(1.0, $cv, $k === $r ? $left : 1.0);
                                $nv = max(0.0, min($lim, $cv) - $step);
                                if ($nv >= $cv - 1e-9) continue;
                                $genRows[$k][$ub] = $nv; pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1);
                                if ($expOf($genRows[$k], $ieVals[$k]) < $rMinR[$k] - 1e-6) {
                                    $genRows[$k][$ub] = $cv; pp_recompute_stgs($genRows[$k], $d3, $model, $k + 1); continue; }
                                if ($k === $r) $left -= ($cv - $nv);
                                $movedAny = true;
                            }
                        }
                        if (!$movedAny && $span >= 3) break;
                        for ($k = max(1, $r - $span - 1); $k <= min($n - 1, $r + $span + 1); $k++)
                            foreach ($units as $ub) { $a = (float)($genRows[$k-1][$ub] ?? 0); $c = (float)($genRows[$k][$ub] ?? 0);
                                if ($a >= 0.05 && $c >= 0.05 && abs($c - $a) > $rampU[$ub] + 1e-6) { $genRows = $save; return false; } }
                    }
                    if ($left > 0.05) { $genRows = $save; return false; }
                    return true; };

                for ($sweep = 0; $sweep < 2; $sweep++) {
                    /* ---- Stage 2: EXPORT > RangeMax -> shed reverse-merit + BB chain ---- */
                    for ($r = 0; $r < $n; $r++) {
                        if (isset($actualRows[$r])) continue;
                        for ($it = 0; $it < 600; $it++) {
                            $over = $expOf($genRows[$r], $ieVals[$r]) - $rMaxR[$r];
                            if ($over <= 1e-6) break;
                            $did = false;
                            foreach ((pp_priority_flat_reverse($model, '/^g(10|[1-9])$/') ?: []) as $uS) {
                                if (!$tunable($uS, $r)) continue;
                                $cv = (float)($genRows[$r][$uS] ?? 0); $fl = $flrOf($uS);
                                if ($cv <= $fl + 0.05) continue;
                                $lim = $cv - $fl;
                                if (isset($rampU[$uS])) foreach ([$r-1, $r+1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                    $nv2 = (float)($genRows[$nb][$uS] ?? 0);
                                    if ($nv2 >= 1) $lim = min($lim, $cv - max($fl, $nv2 - $rampU[$uS])); }
                                if ($lim <= 1e-6) continue;
                                $nv = $cv - min(0.5, $lim, $over);
                                if (!$skOk($uS, $r, $nv)) continue;
                                $sv = $genRows[$r]; $genRows[$r][$uS] = $nv;
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                if ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) {
                                    $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                                $did = true; break;
                            }
                            if (!$did) {
                                $over = $expOf($genRows[$r], $ieVals[$r]) - $rMaxR[$r];
                                if ($over > 1e-6 && !$tryLowerChain($r, $over)) break;
                                if ($expOf($genRows[$r], $ieVals[$r]) - $rMaxR[$r] > 1e-6) break;
                            }
                        }
                    }
                    /* ---- Stage 1: EXPORT < RangeMin -> raise merit-order, ramp/em/gas aware ---- */
                    for ($r = 0; $r < $n; $r++) {
                        if (isset($actualRows[$r])) continue;
                        for ($it = 0; $it < 600; $it++) {
                            $under = $rMinR[$r] - $expOf($genRows[$r], $ieVals[$r]);
                            if ($under <= 1e-6) break;
                            $did = false;
                            foreach ((pp_priority_flat($model, '/^(b[12]|g(10|[1-9]))$/') ?: []) as $uR2) {
                                if (!$tunable($uR2, $r)) continue;
                                $cv = (float)($genRows[$r][$uR2] ?? 0);
                                if ($cv < 0.01 && !in_array($uR2, ['b1','b2'], true)) continue;   // anti auto-run
                                $cap = $capOf($uR2, $r);
                                if ($cv >= $cap - 1e-6) continue;
                                $nv = min($cap, $cv + min(0.5, $under + 0.05));
                                if (!$skOk($uR2, $r, $nv)) continue;
                                if ($trueGasNow() > $quota - 0.001 && !in_array($uR2, ['b1','b2'], true)) continue;   // gas cap: hanya BB
                                $sv = $genRows[$r]; $genRows[$r][$uR2] = $nv;
                                pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                                if (($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)
                                    || $expOf($genRows[$r], $ieVals[$r]) > $rMaxR[$r] + 1e-6
                                    || $trueGasNow() > $quota + 0.0004) {
                                    $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); continue; }
                                $did = true; break;
                            }
                            if (!$did) break;
                        }
                    }
                    /* ---- Stage 3: unit-ramp smoothing G7/G8/G9/G10 ---- */
                    foreach (['g8','g9','g7','g10'] as $uRm) {
                        $limR = $rampU[$uRm];
                        for ($pass3 = 0; $pass3 < 3; $pass3++) { $fix3 = false;
                            for ($r = 1; $r < $n; $r++) {
                                $a = (float)($genRows[$r-1][$uRm] ?? 0); $c = (float)($genRows[$r][$uRm] ?? 0);
                                if ($a < 1 || $c < 1 || abs($c - $a) <= $limR + 1e-6) continue;
                                [$loR, $hiR] = $a < $c ? [$r-1, $r] : [$r, $r-1];
                                $done3 = false;
                                if ($tunable($uRm, $loR)) {
                                    $cvL = (float)$genRows[$loR][$uRm]; $capL = $capOf($uRm, $loR);
                                    $needR = abs($c - $a) - $limR;
                                    $nv = min($capL, $cvL + $needR);
                                    if ($nv > $cvL + 1e-6 && $skOk($uRm, $loR, $nv) && $trueGasNow() <= $quota - 0.001) {
                                        $sv = $genRows[$loR]; $genRows[$loR][$uRm] = $nv;
                                        pp_recompute_stgs($genRows[$loR], $d3, $model, $loR + 1);
                                        if ($expOf($genRows[$loR], $ieVals[$loR]) > $rMaxR[$loR] + 1e-6 || $trueGasNow() > $quota + 0.0004
                                            || ($needBus && $busOf($genRows[$loR], $ieVals[$loR]) < $busMin - 1e-6)) {
                                            $genRows[$loR] = $sv; pp_recompute_stgs($genRows[$loR], $d3, $model, $loR + 1); }
                                        else { $fix3 = true; $done3 = true; }
                                    }
                                }
                                /* transfer GAS-NEUTRAL intra-row: raise unit ramping di sisi rendah, turunkan
                                 * donor lain di ROW YANG SAMA (prefer sibling CC g8<->g9) — export & gas ~netral,
                                 * lolos saat raise polos diblok gas-cap dan lower-high diblok export floor. */
                                if (!$done3) {
                                    $needR = abs($c - $a) - $limR;
                                    $sib = ['g8'=>'g9','g9'=>'g8','g7'=>'g10','g10'=>'g7'][$uRm] ?? null;
                                    $donors = array_values(array_unique(array_filter(array_merge(
                                        $sib ? [$sib] : [], pp_priority_flat_reverse($model, '/^g(10|[1-9])$/') ?: []),
                                        fn($du) => $du !== $uRm)));
                                    if ($tunable($uRm, $loR)) {
                                        $cvL = (float)$genRows[$loR][$uRm]; $capL = $capOf($uRm, $loR);
                                        $amt = min($needR, max(0.0, $capL - $cvL));
                                        if ($amt > 1e-6 && $skOk($uRm, $loR, $cvL + $amt)) {
                                            foreach ($donors as $uD) {
                                                if (!$tunable($uD, $loR)) continue;
                                                $cvD = (float)($genRows[$loR][$uD] ?? 0); $flD = $flrOf($uD);
                                                if ($cvD <= $flD + 0.05) continue;
                                                $limD = $cvD - $flD;
                                                if (isset($rampU[$uD])) foreach ([$loR-1, $loR+1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                                    $nv2 = (float)($genRows[$nb][$uD] ?? 0);
                                                    if ($nv2 >= 1) $limD = min($limD, $cvD - max($flD, $nv2 - $rampU[$uD])); }
                                                $mv = min($amt, $limD);
                                                if ($mv <= 1e-6 || !$skOk($uD, $loR, $cvD - $mv)) continue;
                                                $sv = $genRows[$loR];
                                                $genRows[$loR][$uRm] = $cvL + $mv; $genRows[$loR][$uD] = $cvD - $mv;
                                                pp_recompute_stgs($genRows[$loR], $d3, $model, $loR + 1);
                                                $eL = $expOf($genRows[$loR], $ieVals[$loR]);
                                                if ($eL < $rMinR[$loR] - 1e-6 || $eL > $rMaxR[$loR] + 1e-6
                                                    || ($needBus && $busOf($genRows[$loR], $ieVals[$loR]) < $busMin - 1e-6)
                                                    || $trueGasNow() > $quota + 0.0004) {
                                                    $genRows[$loR] = $sv; pp_recompute_stgs($genRows[$loR], $d3, $model, $loR + 1); continue; }
                                                $fix3 = true; $done3 = true; break;
                                            }
                                        }
                                    }
                                }
                                if (!$done3 && $tunable($uRm, $hiR)) {
                                    $cvH = (float)$genRows[$hiR][$uRm]; $fl = $flrOf($uRm);
                                    $needR = abs((float)$genRows[$hiR][$uRm] - (float)$genRows[$loR][$uRm]) - $limR;
                                    $nv = max($fl, $cvH - $needR);
                                    if ($nv < $cvH - 1e-6 && $skOk($uRm, $hiR, $nv)) {
                                        $sv = $genRows[$hiR]; $genRows[$hiR][$uRm] = $nv;
                                        pp_recompute_stgs($genRows[$hiR], $d3, $model, $hiR + 1);
                                        if ($expOf($genRows[$hiR], $ieVals[$hiR]) < $rMinR[$hiR] - 1e-6
                                            || ($needBus && $busOf($genRows[$hiR], $ieVals[$hiR]) < $busMin - 1e-6)) {
                                            $genRows[$hiR] = $sv; pp_recompute_stgs($genRows[$hiR], $d3, $model, $hiR + 1); }
                                        else $fix3 = true;
                                    }
                                }
                            }
                            if (!$fix3) break;
                        }
                    }
                }
                /* ---- EVIDENCE residual (numerik, row-level; pola cocok validator) ---- */
                $gasAtCap = $trueGasNow() >= $quota - 0.005;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r])) continue;
                    $e = $expOf($genRows[$r], $ieVals[$r]);
                    if ($e < $rMinR[$r] - 0.51) {
                        $short = $rMinR[$r] - $e;
                        if ($gasAtCap)
                            $warnings[] = sprintf('Export under Effective Range Min row %d: Export %.2f < Min %.2f (kurang %.2f MW). Gas di cap quota %.4f BBTUD, seluruh unit running & Babelan di batas ramp/effective-max — ADDITIONAL LNG REQUIRED utk menutup, atau turunkan Range Min.', $r + 1, $e, $rMinR[$r], $short, $quota);
                        else
                            $warnings[] = sprintf('PLN Export below Range Min row %d: %.2f < %.2f — IE / must-run limited: seluruh kandidat raise merit dicoba dan terkunci ramp/startup/fixed/skip pada row ini (exhaustion).', $r + 1, $e, $rMinR[$r]);
                    } elseif ($e > $rMaxR[$r] + 0.51) {
                        $warnings[] = sprintf('Invalid PLN export: Export PLN %.2f MW exceeds Range Max %.2f MW at row %d — driven by required/must-run baseload that cannot be curtailed (must-run floor; Babelan ladder & reverse-merit shed exhausted).', $e, $rMaxR[$r], $r + 1);
                    }
                    if ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 0.01) {
                        $warnings[] = sprintf('Flow PGN Real Time below Min PGN Flow row %d: %.3f < %.3f MMSCFD — alokasi pipe exhausted: PEP/kontrak fixed-take terserap penuh sementara unit gas Jababeka pada row ini terbatas komitmen start/stop (blok off).', $r + 1, $pgnRTof($r), $pgnMinF);
                    }
                    if ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 0.51) {
                        $warnings[] = sprintf('Bus Flow below minimum row %d: BusFlow %.2f < %.2f MW — repair exhausted: unit Bus A (G8/G9) di max/ramp atau di-hold komitmen Start-At/Stop; penurunan Bus B (Babelan) lebih lanjut menembus PLN Export Range Min.', $r + 1, $busOf($genRows[$r], $ieVals[$r]), $busMin);
                    }
                }
                for ($r = 1; $r < $n; $r++) {
                    $eA = $expOf($genRows[$r-1], $ieVals[$r-1]); $eC = $expOf($genRows[$r], $ieVals[$r]);
                    if (abs($eC - $eA) > 30.0 + 0.51)
                        $warnings[] = sprintf('Export ramp %.1f MW rows %d-%d could not be smoothed: IE step melebihi kapasitas ramp gabungan (G8/G9<=30, BB<=5, sisi rendah gas-cap=%s).', abs($eC - $eA), $r, $r + 1, $gasAtCap ? 'YES' : 'no');
                    foreach (['g7','g8','g9','g10'] as $uW) { $a = (float)($genRows[$r-1][$uW] ?? 0); $c = (float)($genRows[$r][$uW] ?? 0);
                        if ($a >= 1 && $c >= 1 && abs($c - $a) > $rampU[$uW] + 0.51)
                            $warnings[] = sprintf('%s ramp %.1f > %.0f rows %d-%d could not be smoothed due to technical constraint (export band/gas cap menahan kedua sisi).', strtoupper($uW), abs($c - $a), $rampU[$uW], $r, $r + 1); }
                }
            }

            /* SKIP LOAD RE-ASSERT: dukungan engine utk Skip Load (hard) dari pass mana pun —
             * no-op bila input tidak memiliki unit_skip_load (input terbaru: EMPTY). */
            if (!empty($model['unit_skip_load'])) {
                foreach (array_keys((array)$model['unit_skip_load']) as $uK) {
                    if (!isset($d3[$uK])) continue;
                    for ($rK = 0; $rK < $n; $rK++) {
                        $skK = pp_get_skip_load($model, $uK, $rK + 1);
                        if (!$skK) continue;
                        $lv = (float)($genRows[$rK][$uK] ?? 0); if ($lv <= 0.01) continue;
                        [$loK, $hiK] = $skK;
                        if ($lv >= $loK - 0.5 && $lv <= $hiK + 0.5) {
                            $mxK = pp_get_max_load($d3, $model2 ?? $model, $uK, $rK + 1);
                            if ($mxK <= 0) $mxK = (float)($d3[$uK]['max_load'] ?? 999);
                            $genRows[$rK][$uK] = ($hiK + 0.6 <= $mxK + 1e-9) ? $hiK + 0.6 : max(0.0, $loK - 0.6);
                            pp_recompute_stgs($genRows[$rK], $d3, $model, $rK + 1);
                        }
                    }
                }
            }
            /* FIXED LOAD RE-ASSERT (ADDENDUM #24/28: Fixed Load wajib dihormati mutlak) — nilai user
             * ditulis ulang di titik paling akhir agar tidak ada pass yang menimpanya. */
            foreach ($fixedVal as $uF => $rowsF) {
                foreach ($rowsF as $rF => $vF) {
                    if (abs((float)($genRows[$rF][$uF] ?? 0) - $vF) > 1e-6) {
                        $genRows[$rF][$uF] = $vF;
                        pp_recompute_stgs($genRows[$rF], $d3, $model, $rF + 1);
                    }
                }
            }
            /* STRESS #70 pasca FIXED RE-ASSERT: (i) BB ramp reconcile — rows non-fixed di sekitar
             * window fixed Babelan diselaraskan bertingkat <= 5 MW/step; (ii) mini quota clamp. */
            foreach (['b1', 'b2'] as $uR) {
                $KEYR = $uR;
                for ($sw2 = 0; $sw2 < 60; $sw2++) {
                    $mv2 = false;
                    for ($r = 1; $r < $n; $r++) {
                        $a2 = (float)($genRows[$r-1][$uR] ?? 0); $b2v = (float)($genRows[$r][$uR] ?? 0);
                        if (abs($b2v - $a2) <= $bbRamp + 1e-9) continue;
                        if ($isFixed($uR, $r) && $isFixed($uR, $r-1)) continue;
                        $tR = $isFixed($uR, $r) ? $r - 1 : $r;                       // ubah sisi non-fixed
                        if ($isFixed($uR, $tR) || isset($actualRows[$tR]) || pp_is_unit_stopped($d3, $model, $uR, $tR + 1)) continue;
                        $ref = (float)($genRows[$tR === $r ? $r - 1 : $r][$uR] ?? 0);
                        $cur = (float)($genRows[$tR][$uR] ?? 0);
                        $genRows[$tR][$uR] = $cur > $ref ? $ref + 5.0 : max(0.0, $ref - 5.0);
                        pp_recompute_stgs($genRows[$tR], $d3, $model, $tR + 1);
                        $mv2 = true;
                    }
                    if (!$mv2) break;
                }
            }
            $gQ2 = 0;
            while ($trueGasNow() > $quota + 0.0004 && $gQ2++ < 160) {
                $dn2 = false;
                for ($r = 0; $r < $n && !$dn2; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF + 0.55) continue;
                    if ($expOf($genRows[$r], $ieVals[$r]) - 0.6 < $rMinR[$r]) continue;
                    foreach (['g9', 'g8'] as $uQ) {
                        if ($isFixed($uQ, $r) || isset($suCap[$uQ][$r]) || pp_is_unit_stopped($d3, $model, $uQ, $r + 1)) continue;
                        $cQ = (float)($genRows[$r][$uQ] ?? 0); if ($cQ <= $GMIN + 1e-6) continue;
                        $genRows[$r][$uQ] = max($GMIN, $cQ - 0.4);
                        pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                        $dn2 = true; break;
                    }
                }
                if (!$dn2) break;
            }
            /* evidence residual */
            $bv = 0; $pv = 0; $fb = 0; $fp = 0;
            for ($r = 0; $r < $n; $r++) {
                if ($needBus && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) { $bv++; if (!$fb) $fb = $r + 1; }
                if ($pgnMinF > 0 && $pgnRTof($r) < $pgnMinF - 1e-6) { $pv++; if (!$fp) $fp = $r + 1; }
            }
            /* §2.1-2.2 PROMPT STOP_EXPORT_LNG: residual export-under + rekomendasi LNG target-based.
             * LNG minimal dihitung dari TARGET (export >= Range Min semua 48 row), BUKAN dari posisi
             * dispatch yang masih under: deficit MW tiap row dikonversi gas via marginal fuel G9. */
            /* PROMPT GAS_SHORTAGE_LNG_REBALANCE: rekomendasi LNG dihitung dari DISPATCH TARGET —
             * salinan dispatch diangkat hingga SELURUH 48 row export >= Effective Range Min + margin
             * (0.6 MW), menghormati max/ramp/stop/startup TAPI mengabaikan quota gas. Kebutuhan LNG =
             * gas(dispatch target) - quota. TIDAK dihitung dari dispatch yang masih under. Row yang
             * bahkan tanpa quota tetap under = CAPACITY SHORTFALL (bukan solvable dgn LNG). */
            $euRows = 0; $euFirst = 0;
            for ($r = 0; $r < $n; $r++)
                if ($expOf($genRows[$r], $ieVals[$r]) < $rMinR[$r] - 1e-9) { $euRows++; if (!$euFirst) $euFirst = $r + 1; }   /* §3: 14.99 < 15 = gagal */
            if ($euRows > 0) {
                $tg = $genRows;                                                     // dispatch target (salinan)
                $gasTG = function () use (&$tg, $d3, $n): float {
                    $s = 0.0;
                    for ($r = 0; $r < $n; $r++)
                        foreach (['g1','g2','g3','g4','g5','g6','g7','g8','g9','g10','ge1','ge2','ge3','ge4'] as $u)
                            $s += calc_fuel($d3, $u, (float)($tg[$r][$u] ?? 0)) / 2.0;
                    return $s;
                };
                for ($swT = 0; $swT < 25; $swT++) {
                    $movedT = false;
                    for ($r = 0; $r < $n; $r++) {
                        if (isset($actualRows[$r])) continue;
                        $gT = 0;
                        while ($expOf($tg[$r], $ieVals[$r]) < $rMinR[$r] + 0.6 - 1e-9 && $gT++ < 900) {
                            $didT = false;
                            foreach (['g9','g8','g5','g1','g2','g4','g3','g6','ge1','ge2','ge3','ge4','g10'] as $ug) {
                                if (!isset($d3[$ug]) || $isFixed($ug, $r) || isset($suCap[$ug][$r])) continue;
                                if (pp_is_unit_stopped($d3, $model, $ug, $r + 1)) continue;
                                $cg = (float)($tg[$r][$ug] ?? 0); if ($cg < 0.01) continue;   // hanya unit on (start baru = wewenang engine)
                                $em = pp_effective_maxload($d3, $model, $ug, $r + 1);
                                $cap = min($em > 0 ? $em : (float)($d3[$ug]['max_load'] ?? 31),
                                           in_array($ug, ['g8','g9'], true) ? $GMAX : (float)($d3[$ug]['max_load'] ?? 31));
                                if (in_array($ug, ['g8','g9'], true))
                                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                        if ((float)($tg[$nb][$ug] ?? 0) >= 1) $cap = min($cap, (float)$tg[$nb][$ug] + 30.0); }
                                if ($cg >= $cap - 1e-6) continue;
                                $tg[$r][$ug] = min($cap, $cg + 0.5);
                                pp_recompute_stgs($tg[$r], $d3, $model, $r + 1);
                                $didT = true; $movedT = true; break;
                            }
                            if (!$didT) {                                            // Babelan naik (ramp 5, non-stop)
                                foreach (['b1','b2'] as $ub) {
                                    if ($isFixed($ub, $r) || pp_is_unit_stopped($d3, $model, $ub, $r + 1)) continue;
                                    $cb = (float)($tg[$r][$ub] ?? 0); $lim = (float)($d3[$ub]['max_load'] ?? 120);
                                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                                        $lim = min($lim, (float)($tg[$nb][$ub] ?? 0) + $bbRamp); }
                                    if ($cb < $lim - 1e-6) { $tg[$r][$ub] = min($lim, $cb + 0.5); pp_recompute_stgs($tg[$r], $d3, $model, $r + 1); $didT = true; $movedT = true; break; }
                                }
                            }
                            if (!$didT) break;
                        }
                    }
                    if (!$movedT) break;
                }
                $gasTarget = $gasTG();
                $lngNeed = max(0.0, $gasTarget - $quota);
                $capRows = 0; $capShortMW = 0.0;
                for ($r = 0; $r < $n; $r++) {
                    $eT = $expOf($tg[$r], $ieVals[$r]);
                    if ($eT < $rMinR[$r] - 0.51) { $capRows++; $capShortMW = max($capShortMW, $rMinR[$r] - $eT); }
                }
                $warnings[] = sprintf('Export under Effective Range Min on %d row(s) (from row %d) AFTER repair & daily rebalancing: remaining rows are capacity/ramp-locked (see capacity evidence). Evidence row-level.', $euRows, $euFirst);
                if ($lngNeed > 0.005)
                    $warnings[] = sprintf('LNG recommendation (target-dispatch): add >= %.3f BBTUD LNG — computed from a hypothetical dispatch where ALL 48 rows sit slightly ABOVE Effective Range Min (target gas %.3f vs quota %.3f), NOT from the current under-export position.', $lngNeed * 1.02, $gasTarget, $quota);
                if ($capRows > 0) {
                    $warnings[] = sprintf('CAPACITY SHORTFALL (bukan gas): %d row(s) tetap di bawah Range Min hingga %.1f MW bahkan TANPA batas quota — LNG tidak menutup ini. Opsi: turunkan Range Min/IE, atau izinkan unit Stop/start unit tambahan.', $capRows, $capShortMW);
                    /* §7: evidence row-level ketat (contoh 2 row terparah) */
                    $exList = [];
                    for ($r = 0; $r < $n; $r++) { $eT = $expOf($tg[$r], $ieVals[$r]); if ($eT < $rMinR[$r] - 0.51) $exList[$r] = $rMinR[$r] - $eT; }
                    arsort($exList);
                    foreach (array_slice(array_keys($exList), 0, 2) as $rX) {
                        $det = [];
                        foreach (['g9','g8','g5','g2','g1','g4','ge1','g10','b1','b2'] as $uX) {
                            if (!isset($d3[$uX])) continue;
                            $cX = (float)($tg[$rX][$uX] ?? 0);
                            $emX = pp_effective_maxload($d3, $model, $uX, $rX + 1);
                            $mxX = $emX > 0 ? $emX : (float)($d3[$uX]['max_load'] ?? 0);
                            $why = pp_is_unit_stopped($d3, $model, $uX, $rX + 1) ? 'STOP' : (isset($suCap[$uX][$rX]) ? 'startup-seq' : ($cX >= $mxX - 0.51 ? 'AT MAX' : 'ramp/off'));
                            $det[] = sprintf('%s=%.0f/%.0f(%s)', strtoupper($uX), $cX, $mxX, $why);
                        }
                        $warnings[] = sprintf('Capacity shortfall evidence row %d: IE=%.1f RangeMin=%.1f Export(target)=%.1f — %s.',
                            $rX + 1, $ieVals[$rX], $rMinR[$rX], $expOf($tg[$rX], $ieVals[$rX]), implode(', ', $det));
                    }
                }
                /* §1: gas melampaui quota demi Export Range Min = GAS SHORTAGE eksplisit (bukan alasan under) */
                if ($trueGasNow() > $quota + 0.0004)
                    $warnings[] = sprintf('ADDITIONAL LNG REQUIRED (export-first §1): dispatch mengejar PLN Export >= Range Min; gas Jababeka %.3f BBTUD > quota %.3f — Additional LNG minimum = %.3f BBTUD. Gas quota bukan alasan membiarkan export under Range Min.', $trueGasNow(), $quota, $trueGasNow() - $quota);
            }
            /* PROMPT §7: export SUDAH tercapai penuh (0 under) tetapi gas > quota — surplus itulah
             * Additional LNG. Emit rekomendasi meski euRows=0 agar operator tahu kebutuhan LNG. */
            if ($euRows === 0 && $trueGasNow() > $quota + 0.0004)
                $warnings[] = sprintf('ADDITIONAL LNG REQUIRED (export-first §7): PLN Export >= Range Min tercapai di SEMUA 48 row dengan menaikkan/men-start unit feasible (G4 up, G5/G1 started); gas Jababeka %.3f BBTUD > quota %.3f — Additional LNG minimum = %.3f BBTUD. Close-loop export under count = 0.', $trueGasNow(), $quota, $trueGasNow() - $quota);
            if ($bv > 0) $warnings[] = sprintf('Bus Flow below minimum %.1f on %d row(s) (from row %d) AFTER repair: Babelan ramp/floor and gas Bus-A headroom/quota exhausted — evidence row-level.', $busMin, $bv, $fb);
            if ($pv > 0) $warnings[] = sprintf('FLOW PGN REAL TIME below Min PGN Flow (%.2f MMSCFD) on %d row(s) (from row %d) AFTER repair & daily rebalancing: donor rows (export > Range Min) and gas-unit headroom/ramp exhausted — evidence row-level.', $pgnMinF * 1000.0 / $ghvPgnF, $pv, $fp);
        }
    }
    $stale = ['No feasible dispatch found','PLN export below Range Min','Gas-limited','Unnecessary unit start deferred',
        'Babelan raised toward CF target','Invalid gas utilization','PGN Pipe quota available but not utilized',
        'Gas quota is insufficient','Gas quota exceeded','Additional HRSG ramp inserted','Additional HRSG: ',
        'Unnecessary STG running','Babelan CF below target','Invalid PLN export','Invalid export ramp',
        'Export ramp exceeded','GAS SHORTAGE','cannot maintain minimum runtime','Invalid start-stop pattern',
        'Invalid CC load drop fixed','Invalid low-load parking resolved','Invalid STG generation prevented',
        'Invalid startup pattern fixed','held online to honour','stopped at row','Required unit:','Unit Cannot Stop:',
        'Net Production is'];
    $warnings = array_values(array_filter($warnings, function ($w) use ($stale) {
        foreach ($stale as $s) if (strpos($w, $s) !== false) return false; return true;
    }));
    foreach (pp_validate_startup($genRows, $d3, $model) as $w) $warnings[] = $w;
    if (!empty($su['warnings'])) foreach ($su['warnings'] as $w) { $skip = false; foreach ($stale as $s) if (strpos($w, $s) !== false) { $skip = true; break; } if (!$skip) $warnings[] = $w; }

    /* Post-enforcement feasibility evidence (Revisi Bugfix): the Stop/Skip enforcement above can legitimately
     * remove a major export lever (e.g. a full-day G8 stop), after which PLN export may fall below Range Min on
     * some rows. That is a genuine physical infeasibility caused by the operator's own hard constraint — emit
     * row-level evidence (recognized VALID-INFEASIBLE pattern) instead of leaving a bare, unexplained deficit. */
    for ($r = 0; $r < $n; $r++) {
        $e = $expOf($genRows[$r], $ieVals[$r]);
        if ($e < $rMinR[$r] - 0.51) {
            $warnings[] = sprintf('PLN export below Range Min %.1f MW at row %d (%.1f MW): a hard constraint (Stop/Skip schedule) removed the generation needed to reach the floor — IE / must-run limited.', $rMinR[$r], $r + 1, $e);
        }
    }
    // If a Stop/Skip schedule removed the G8/G9 ramp lever, any residual >30 MW/30min export step can no longer
    // be smoothed — surface it as recognized ramp evidence (row-level) so it classifies as VALID-INFEASIBLE.
    if (!empty($model['unit_stop']) || !empty($model['unit_stop_time']) || !empty($model['unit_skip_load'])) {
        $prevE = null;
        for ($r = 0; $r < $n; $r++) {
            $e = $expOf($genRows[$r], $ieVals[$r]);
            if ($prevE !== null) { $dr = abs($e - $prevE); if ($dr > pp_export_step_limit($model) + 1e-6) {
                $warnings[] = sprintf('Export ramp could not be smoothed: |dExport|=%.1f MW at row %d exceeds the 35 MW limit (G8/G9 ramp lever removed by a Stop/Skip schedule).', $dr, $r + 1);
            } }
            $prevE = $e;
        }
    }

    /* export-ramp check: >30 hard-reject only when Dispatch Dev is NOT active (else warning) */
    $maxR = 0.0; $prev = null;
    for ($r = 0; $r < $n; $r++) { $e = $expOf($genRows[$r], $ieVals[$r]); if ($prev !== null) { $dr = abs($e - $prev); $maxR = max($maxR, $dr); if ($dr > pp_export_step_limit($model) + 1e-6) { $warnings[] = sprintf('Export ramp %.1f MW/30min at row %d %s', $dr, $r, $ddActive ? '(allowed: Dispatch Dev Required)' : '(exceeds 35 MW limit)'); } } $prev = $e; }

    $bf = 0; for ($r = 0; $r < $n; $r++) if ($busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6) $bf++;
    if ($bf > 0) $warnings[] = sprintf('Bus Flow below minimum (%.0f MW) on %d row(s).', $busMin, $bf);

    /* gas verdict + Dispatch-Dev mode handling (LNG/Distillate recommendation). Jababeka units only, against the
     * Jababeka quota — MM2100 (GE/GTG10) gas is reported separately by worker02's total-quota check. */
    $gasAll = pp_fuel_sum_g19($genRows, $d3, $n, $__FC);
    $gasDaily = $gasAll / 2.0;
    if ($gasDaily > $quota + 0.04) {
        if ($ddActive && $ddMode === 'recommend') {
            $warnings[] = sprintf('Dispatch Dev (Recommend if Exceed Quota): Total Gas %.3f BBTUD exceeds quota %.3f to meet the dispatch target — recommend additional %.3f BBTUD of LNG/Distillate.', $gasDaily, $quota, $gasDaily - $quota);
        } else {
            $warnings[] = sprintf('Gas over quota: Total Gas %.3f BBTUD > quota %.3f BBTUD (mode Same as Quota: candidate capped to quota).', $gasDaily, $quota);
        }
    }
    /* Dispatch Dev band feasibility (Addendum Sec.5): if a required dispatch band cannot be reached on its
     * target rows even with the units maxed (e.g. IE is too high to export the requested MW), report the
     * specific rows rather than failing silently — the engine has already loaded those rows to their best effort. */
    if ($ddActive) {
        $missed = [];
        for ($r = $ddStart; $r <= $ddStop; $r++) { if (isset($actualRows[$r])) continue; if ($expOf($genRows[$r], $ieVals[$r]) < $ddLo - 0.5) $missed[] = $r + 1; }
        if ($missed)
            $warnings[] = sprintf('Dispatch Dev Required NOT met on row(s) %s: Export cannot reach %.0f-%.0f MW even with the export units at maximum — IE on these intervals is too high. Lower the dispatch target or reduce IE.', implode(',', $missed), $ddLo, $ddHi);
    }
    // Under-utilisation is reported authoritatively by worker02's gas-utilization status (which uses the
    // final accounted Gas Fuel Total). The shaper no longer emits a duplicate under-utilised warning here,
    // to avoid a false positive from its slightly lower per-unit running total.

    /* PROMPT SISTEMIK §7/§8 — KONFORMANSI RAMP BABELAN (pass akhir, generik): ramp 10 MW/30-menit
     * bersifat MUTLAK termasuk saat startup (0 -> 10 -> 20 -> ...), tanpa zig-zag. Clamp maju:
     * |BB[i] - BB[i-1]| <= limit. Guard: actual rows dilewati, fixed load dihormati, dan hasil
     * clamp direvert per-row bila Export keluar Range (gas tidak berubah — Babelan non-gas). */
    {
        $bbLim = pp_babelan_ramp_limit($model);
        foreach (['b1', 'b2'] as $bu) {
            /* ANCHOR FIXED-LOAD (generik): bila operator memfixkan nilai V di row F, ramp 10 menuntut
             * row-row SEBELUM/SESUDAH berada dalam selubung |b[r] - V| <= lim*|r-F|. Backward/forward
             * pre-ramp MENAIKKAN row tetangga menuju anchor (start lebih awal diperbolehkan utk unit
             * required/ON) — memenuhi Fixed Load DAN ramp sekaligus, tanpa melonggarkan limit. */
            for ($F = 0; $F < $n; $F++) {
                $fv = pp_get_fixed_load($model, $bu, $F + 1);
                if ($fv < 0) continue;
                for ($r = $F - 1; $r >= 0; $r--) {                       // backward pre-ramp
                    if (isset($actualRows[$r]) || pp_get_fixed_load($model, $bu, $r + 1) >= 0) break;
                    $need = $fv - $bbLim * ($F - $r);
                    if ($need <= 0) break;
                    $cur = (float)($genRows[$r][$bu] ?? 0);
                    if ($cur >= $need - 1e-9) break;
                    $sv = $genRows[$r]; $genRows[$r][$bu] = $need;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $eA = $expOf($genRows[$r], $ieVals[$r]);
                    if ($eA > $rMaxR[$r] + 1e-6 || ($busMin > 0 && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                }
                for ($r = $F + 1; $r < $n; $r++) {                       // forward post-ramp (descent halus)
                    if (isset($actualRows[$r]) || pp_get_fixed_load($model, $bu, $r + 1) >= 0) break;
                    $need = $fv - $bbLim * ($r - $F);
                    if ($need <= 0) break;
                    $cur = (float)($genRows[$r][$bu] ?? 0);
                    if ($cur >= $need - 1e-9) break;
                    $sv = $genRows[$r]; $genRows[$r][$bu] = $need;
                    pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1);
                    $eA = $expOf($genRows[$r], $ieVals[$r]);
                    if ($eA > $rMaxR[$r] + 1e-6 || ($busMin > 0 && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)) { $genRows[$r] = $sv; pp_recompute_stgs($genRows[$r], $d3, $model, $r + 1); break; }
                }
            }
            $prevB = null;
            for ($r = 0; $r < $n; $r++) {
                if (isset($actualRows[$r])) { $prevB = (float)($genRows[$r][$bu] ?? 0); continue; }
                $cur = (float)($genRows[$r][$bu] ?? 0);
                if ($prevB !== null && pp_get_fixed_load($model, $bu, $r + 1) < 0) {
                    $lo = $prevB - $bbLim; $hi = $prevB + $bbLim;
                    $clamped = max($lo, min($hi, $cur));
                    if (abs($clamped - $cur) > 1e-9) {
                        $sv = $genRows[$r];
                        $genRows[$r][$bu] = max(0.0, $clamped);
                        $eC = $expOf($genRows[$r], $ieVals[$r]);
                        if ($eC < $rMinR[$r] - 1e-6 || $eC > $rMaxR[$r] + 1e-6) $genRows[$r] = $sv;   // revert
                    }
                }
                $prevB = (float)($genRows[$r][$bu] ?? 0);
            }
        }
        /* §7.3 — BB UP-FILL (generik): setelah konformansi, naikkan Babelan menuju Effective Max
         * pada row yang masih punya ruang ramp DAN export tetap dalam Range. Iterasi kecil (0.5)
         * sampai tidak ada kenaikan valid; menutup celah "BB di bawah max tanpa limiter". */
        foreach (['b2', 'b1'] as $bu) {
            for ($sweep = 0; $sweep < 200; $sweep++) {
                if(pp_budget_exceeded('bb_upfill'))break;
                if (pp_budget_repeat('bbuf' . $bu, pp_rows_signature($genRows))) break;
                $movedUF = false;
                for ($r = 0; $r < $n; $r++) {
                    if (isset($actualRows[$r])) continue;
                    if (pp_get_fixed_load($model, $bu, $r + 1) >= 0) continue;
                    if (pp_is_unit_stopped($d3, $model, $bu, $r + 1)) continue;
                    $cur = (float)($genRows[$r][$bu] ?? 0);
                    if ($cur < 0.01) continue;                               // startup placement bukan urusan pass ini
                    $bMaxU = pp_effective_maxload($d3, $model, $bu, $r + 1);
                    if ($bMaxU <= 0) $bMaxU = (float)($d3[$bu]['max_load'] ?? 120);
                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        $bMaxU = min($bMaxU, (float)($genRows[$nb][$bu] ?? 0) + $bbLim); }
                    if ($cur + 0.5 > $bMaxU + 1e-9) continue;
                    $sv = $genRows[$r]; $genRows[$r][$bu] = $cur + 0.5;
                    $eU = $expOf($genRows[$r], $ieVals[$r]);
                    $rampBad = false;
                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if (abs($eU - $expOf($genRows[$nb], $ieVals[$nb])) > pp_export_step_limit($model) + 1e-6) $rampBad = true; }
                    if ($eU > $rMaxR[$r] + 1e-6 || $eU < $rMinR[$r] - 1e-6 || $rampBad
                        || ($busMin > 0 && $busOf($genRows[$r], $ieVals[$r]) < $busMin - 1e-6)) {
                        $genRows[$r] = $sv; continue;
                    }
                    $movedUF = true;
                }
                if (!$movedUF) break;
            }
        }
            /* ============ EXPORT-RAMP CROSS-ROW REPAIR (ITEM A) ============
             * Transisi |dExport| > 30 (mis. step demand IE) diperbaiki dgn MEMINDAHKAN MW unit gas
             * yang SAMA antar-row: sisi RENDAH +0.5, sisi TINGGI -0.5 (fuel hampir identik -> gas
             * harian ~netral; drift dikoreksi donateGas, sisa diisi FINAL GAS TOP-UP). Guard: min/max
             * unit, ramp unit, suCap startup, fixed/actual/stop/last-status, Export Range kedua row,
             * BusFlow, must-improve pada gap terburuk (anti-osilasi) + budget iterasi keras. */
            {
                $rampXR = ['g8' => 30.0, 'g9' => 30.0, 'g1' => 30.0, 'g2' => 30.0, 'g5' => 30.0, 'g3' => 30.0];
                $minXR = function (string $u) use ($d3, $GMIN) {
                    return in_array($u, ['g8', 'g9'], true) ? $GMIN : (float)($d3[$u]['min_ccload'] ?? 20);
                };
                $capXR = function (string $u, int $r) use ($d3, $model, $GMAX, &$genRows, $rampXR, $n) {
                    $em = pp_effective_maxload($d3, $model, $u, $r + 1);
                    $cap = min($em > 0 ? $em : $GMAX, in_array($u, ['g8','g9'], true) ? $GMAX : (float)($d3[$u]['max_load'] ?? 31));
                    foreach ([$r - 1, $r + 1] as $nb) { if ($nb < 0 || $nb >= $n) continue;
                        if ((float)($genRows[$nb][$u] ?? 0) >= 1) $cap = min($cap, (float)$genRows[$nb][$u] + ($rampXR[$u] ?? 30.0)); }
                    return $cap;
                };
                $gasB4X = $trueGasNow();
                for ($xIt = 0; $xIt < 600; $xIt++) {
                    if(pp_budget_exceeded('shaper_xr'))break;
                    if (pp_budget_repeat('xr', pp_rows_signature($genRows))) break;                       // budget: 600 micro-moves (~0.5-1 MW) utk cliff besar
                    $wi = -1; $wd = 0.0;
                    for ($i = 0; $i < $n - 1; $i++) {
                        $dT = $expOf($genRows[$i + 1], $ieVals[$i + 1]) - $expOf($genRows[$i], $ieVals[$i]);
                        if (abs($dT) > 30.0 + 1e-6 && abs($dT) > abs($wd)) { $wd = $dT; $wi = $i; }
                    }
                    if ($wi < 0) break;                                        // seluruh transisi <= 30
                    $L = $wd > 0 ? $wi : $wi + 1;                              // sisi export rendah
                    $H = $wd > 0 ? $wi + 1 : $wi;                              // sisi export tinggi
                    $movedX = false;
                    if (!isset($actualRows[$L]) && !isset($actualRows[$H])) {
                        /* CROSS-UNIT: unit penurun (uH) dan penaik (uL) boleh BERBEDA — pada startup pagi
                         * pasangan same-unit sering terkunci (min sisi tinggi, suCap sisi rendah). Drift
                         * gas antar-unit dikoreksi di akhir pass (donateGas / FINAL TOP-UP). */
                        /* Unit gas + BABELAN: pada step demand (IE cliff), sisi tinggi kerap memiliki
                         * SELURUH unit gas di minimum — satu-satunya lever fisik adalah menggeser
                         * baseload Babelan lintas-row (coal; tanpa drift gas; ramp +-10 dijaga). */
                        $unitsXR = ['g9', 'g8', 'g1', 'g2', 'g5', 'g3', 'b2', 'b1'];
                        $isBBXR = fn($u) => ($u === 'b1' || $u === 'b2');
                        $pairsXR = [];
                        foreach ($unitsXR as $uSame) $pairsXR[] = [$uSame, $uSame];        // prefer same-unit
                        foreach ($unitsXR as $uH) foreach ($unitsXR as $uL)
                            if ($uH !== $uL) $pairsXR[] = [$uH, $uL];
                        foreach ($pairsXR as [$uH, $uL]) {
                            if ($isFixed($uH, $H) || $isFixed($uL, $L)) continue;
                            if (pp_is_unit_stopped($d3, $model, $uH, $H + 1) || pp_is_unit_stopped($d3, $model, $uL, $L + 1)) continue;
                            $cL = (float)($genRows[$L][$uL] ?? 0); $cH = (float)($genRows[$H][$uH] ?? 0);
                            $minH = $isBBXR($uH) ? 10.0 : $minXR($uH);        // BB required-continuous: floor 10
                            if ($cL < 0.01 || $cH < $minH + 0.5 - 1e-9) continue;  // keduanya ON; sisi tinggi tetap >= floor
                            if ($isBBXR($uL)) {
                                $capL = pp_effective_maxload($d3, $model, $uL, $L + 1);
                                if ($capL <= 0) $capL = (float)($d3[$uL]['max_load'] ?? 120);
                                $bbLimX = pp_babelan_ramp_limit($model);
                                foreach ([$L - 1, $L + 1] as $nbX) { if ($nbX < 0 || $nbX >= $n) continue;
                                    $capL = min($capL, (float)($genRows[$nbX][$uL] ?? 0) + $bbLimX); }
                                if (pp_get_fixed_load($model, $uL, $L + 1) >= 0) continue;
                            } else {
                                $capL = $capXR($uL, $L);
                                if (isset($suCap[$uL][$L])) $capL = min($capL, (float)$suCap[$uL][$L]);
                            }
                            if ($isBBXR($uH)) {
                                if (pp_get_fixed_load($model, $uH, $H + 1) >= 0) continue;
                                $bbLimX2 = pp_babelan_ramp_limit($model);
                                foreach ([$H - 1, $H + 1] as $nbX) { if ($nbX < 0 || $nbX >= $n) continue;
                                    if ($cH - 0.5 < (float)($genRows[$nbX][$uH] ?? 0) - $bbLimX2 - 1e-9) { $capL = -1; break; } }
                                if ($capL < 0) continue;                       // penurunan memecah ramp BB tetangga
                            }
                            if ($cL + 0.5 > $capL + 1e-9) continue;
                            /* FUEL-MATCHED: delta sisi rendah dipilih agar kenaikan fuel uL ==
                             * penurunan fuel uH (gas harian netral per move; residual mikro). */
                            $fd = $isBBXR($uH) ? 0.0 : (calc_fuel($d3, $uH, $cH) - calc_fuel($d3, $uH, $cH - 0.5));
                            $g1s = $isBBXR($uL) ? 0.0 : (calc_fuel($d3, $uL, $cL + 0.5) - calc_fuel($d3, $uL, $cL));
                            if ($isBBXR($uH) && $isBBXR($uL)) $dL = 0.5;                       // coal<->coal: netral
                            elseif ($isBBXR($uH) || $isBBXR($uL)) $dL = 0.5;                    // campuran: drift dikoreksi akhir
                            else $dL = ($g1s > 1e-9) ? max(0.1, min(2.0, 0.5 * $fd / $g1s)) : 0.5;
                            if ($cL + $dL > $capL + 1e-9) $dL = $capL - $cL;
                            if ($dL < 0.05) continue;
                            $svL = $genRows[$L]; $svH = $genRows[$H];
                            $genRows[$H][$uH] = $cH - 0.5; $genRows[$L][$uL] = $cL + $dL;
                            pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                            pp_recompute_stgs($genRows[$H], $d3, $model, $H + 1);
                            $eL = $expOf($genRows[$L], $ieVals[$L]); $eH = $expOf($genRows[$H], $ieVals[$H]);
                            $dAfter = abs($expOf($genRows[$wi + 1], $ieVals[$wi + 1]) - $expOf($genRows[$wi], $ieVals[$wi]));
                            $badX = ($eL > $rMaxR[$L] + 1e-6) || ($eH < $rMinR[$H] - 1e-6)
                                 || ($busMin > 0 && ($busOf($genRows[$L], $ieVals[$L]) < $busMin - 1e-6
                                                     || $busOf($genRows[$H], $ieVals[$H]) < $busMin - 1e-6))
                                 || ($dAfter >= abs($wd) - 1e-9);              // wajib memperkecil gap terburuk
                            if ($badX) {
                                $genRows[$L] = $svL; $genRows[$H] = $svH;
                                pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                                pp_recompute_stgs($genRows[$H], $d3, $model, $H + 1);
                                continue;
                            }
                            $movedX = true; break;
                        }
                    }
                    if (!$movedX && !isset($actualRows[$L])) {
                        /* LEVER 2 — SINGLE-SIDED RAISE: bila seluruh pasangan terkunci (sisi tinggi ALL-MIN,
                         * BB chain-locked), naikkan unit di sisi RENDAH saja; tambahan fuel dikembalikan via
                         * donateGas dari row donor bermargin (guard RangeMin internal donateGas). */
                        foreach (['g9', 'g8', 'g1', 'g2', 'g5'] as $uS) {
                            if ($isFixed($uS, $L) || pp_is_unit_stopped($d3, $model, $uS, $L + 1)) continue;
                            $cS = (float)($genRows[$L][$uS] ?? 0); if ($cS < 0.01) continue;
                            $capS = $capXR($uS, $L);
                            if (isset($suCap[$uS][$L])) $capS = min($capS, (float)$suCap[$uS][$L]);
                            if ($cS + 1.0 > $capS + 1e-9) continue;
                            $svS = $genRows[$L];
                            $genRows[$L][$uS] = $cS + 1.0;
                            pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1);
                            $eS = $expOf($genRows[$L], $ieVals[$L]);
                            $dAf2 = abs($expOf($genRows[$wi + 1], $ieVals[$wi + 1]) - $expOf($genRows[$wi], $ieVals[$wi]));
                            if ($eS > $rMaxR[$L] + 1e-6 || $dAf2 >= abs($wd) - 1e-9
                                || ($busMin > 0 && $busOf($genRows[$L], $ieVals[$L]) < $busMin - 1e-6)) {
                                $genRows[$L] = $svS; pp_recompute_stgs($genRows[$L], $d3, $model, $L + 1); continue;
                            }
                            $fAdd = (calc_fuel($d3, $uS, $cS + 1.0) - calc_fuel($d3, $uS, $cS)) / 2.0;
                            if ($fAdd > 1e-6) $donateGas($fAdd, [$L => 1]);
                            $movedX = true; break;
                        }
                    }
                    if (!$movedX) break;                                       // lever habis pada gap terburuk
                }
                $driftX = $trueGasNow() - $gasB4X;
                if ($driftX > 0.0015) $donateGas($driftX + 0.0005, []);        // over-drift: buang di row bermargin
                elseif ($driftX < -0.0015) {                                    // under-drift: serap kecil dgn guard export-ramp
                    $needA = -$driftX + 0.0005;
                    for ($rA = 0; $rA < $n && $needA > 1e-6; $rA++) {
                        if (isset($actualRows[$rA])) continue;
                        foreach (['g9', 'g8', 'g1'] as $uA) {
                            if ($isFixed($uA, $rA) || pp_is_unit_stopped($d3, $model, $uA, $rA + 1)) continue;
                            $cA = (float)($genRows[$rA][$uA] ?? 0); if ($cA < 0.01) continue;
                            $capA = $capXR($uA, $rA); if (isset($suCap[$uA][$rA])) $capA = min($capA, (float)$suCap[$uA][$rA]);
                            if ($cA + 0.5 > $capA + 1e-9) continue;
                            $svA = $genRows[$rA]; $genRows[$rA][$uA] = $cA + 0.5;
                            pp_recompute_stgs($genRows[$rA], $d3, $model, $rA + 1);
                            $eA2 = $expOf($genRows[$rA], $ieVals[$rA]); $okA = ($eA2 <= $rMaxR[$rA] + 1e-6);
                            foreach ([$rA - 1, $rA + 1] as $nb2) { if ($nb2 < 0 || $nb2 >= $n) continue;
                                if (abs($eA2 - $expOf($genRows[$nb2], $ieVals[$nb2])) > pp_export_step_limit($model) + 1e-6) $okA = false; }
                            if (!$okA) { $genRows[$rA] = $svA; pp_recompute_stgs($genRows[$rA], $d3, $model, $rA + 1); continue; }
                            $needA -= (calc_fuel($d3, $uA, $cA + 0.5) - calc_fuel($d3, $uA, $cA)) / 2.0;
                            break;
                        }
                    }
                }
            }

    }


}
