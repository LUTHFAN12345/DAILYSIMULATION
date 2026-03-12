<?php
function calc_min_block_load($d3){
  foreach ($d3['modeling']['unit_priority'] as $i => $block){
    $cc_min=[0,0,0];
    $unit_max=[0,0,0];
    $sc_min=[0,0,0];
    $unit_num=[0,0,0,0,0,0];
    $stg='';
    foreach ($block as $j => $unit){
      $unit_num[$j]=0;
      if($unit[0]=="g" || $unit[0]=="b"){
        $cc_min[$j] = $d3[$unit]['min_ccload'] ?? 0;
        $sc_min[$j] = $d3[$unit]['min_scload'];
        $unit_max[$j] = $d3[$unit]['max_load'];
        $stg=$d3[$unit]['stg'] ?? '';
        $unit_num[$j]++;
      }
    }
    if($stg!=''){
      $cc_count = count(array_filter($cc_min));
      for ($n = 1; $n <= $cc_count; $n++){
        $scenario = array_fill(0, 3, 0);
        $scenario_max = array_fill(0, 3, 0);
        for ($m = 0; $m<$n; $m++)$scenario[$m] = $cc_min[$m];
        for ($m = 0; $m<$n; $m++)$scenario_max[$m] = $unit_max[$m];
        $min_block_loads[$i][] = array_sum($scenario) + calc_stg($stg, ...$scenario);
        $max_block_loads[$i][] = array_sum($scenario_max) + calc_stg($stg, ...$scenario_max);
      }
    } else {
      for ($n = 0; $n <= $unit_num[$i]; $n++){
        $min_block_loads[$i][$n] = array_sum(array_slice($sc_min, 0, $n + 1));
        $max_block_loads[$i][$n] = array_sum(array_slice($unit_max, 0, $n + 1));
      }
    }
  }
  return [$min_block_loads,$max_block_loads];
}

function calc_house_load(){
  global $gen;
  $f6 = 0.75;
  $f9 = 2.75;
  $hl_1 = 2.75;
  $hl_3 = 1;
  if($gen['g3']>0)$hl_1+=$f6;
  if($gen['g4']>0)$hl_1+=$f6;
  if($gen['g6']>0)$hl_1+=$f6;
  if($gen['g1']>0)$hl_1+=$f6;
  if($gen['g2']>0)$hl_1+=$f6;
  if($gen['g5']>0)$hl_1+=$f6;
  if($gen['g8']>0)$hl_3+=$f9;
  if($gen['g9']>0)$hl_3+=$f9;
  $hl = $hl_1 + $hl_3;
  return $hl;
}

function stg_coef($stg) {
    if ($stg == "s3") return 1.58;
    if ($stg == "s1" || $stg == "s2") return 1.48;
    return 1.5; // fallback
}

function getFixedLoad($fix_load, $unit, $row) {
    if (!isset($fix_load[$unit])) return (-1);
    foreach ($fix_load[$unit] as $rule) {
        if ($row >= $rule["start"] && $row <= $rule["stop"])return $rule["value"];
    }
    return (-1);
}

function calc_fuel($gtg, $load) {
  if($load<1)return 0;
  global $d3,$note;
  $s0 = $d3[$gtg]["x0_f1"] ?? null;
  $s1 = $d3[$gtg]["x1_f1"] ?? null;
  $s2 = $d3[$gtg]["x2_f1"] ?? 0;
  if(isset($d3[$gtg]['xsp_f2']) && $load>$d3[$gtg]['xsp_f2']){
    $s0 = $d3[$gtg]["x0_f2"] ?? null;
    $s1 = $d3[$gtg]["x1_f2"] ?? null;
    $s2 = $d3[$gtg]["x2_f2"] ?? 0;
  }
  $fuel=$s2 * $load ** 2 + $s1 * $load + $s0;
  $cf=$d3[$gtg]['xcf'];
  //$note.=" calc $gtg with load=$load and s0=$s0, s1=$s1, s2=$s2, result=$fuel, cf=$cf * ";
  if($gtg=='g8' || $gtg=='g9' || $gtg=='g7' || $gtg=='g10')$load=1000;
  return ($fuel*$cf*$load) / 1000000;
}

function calc_stg($stg, $g1, $g2 = 0, $g3 = 0) {
    global $d3, $note;
    $coeffs = $d3[$stg];
    $load = 0;
    $units = array_filter([$g1, $g2, $g3]); // non-zero units
    $count = count($units);
    $sum = array_sum($units);
    $unitSuffix = "u{$count}";
    $segmentUsed = false;
    //$note.=" calc $stg unit $g1, $g2, $g3 total $sum * ";
    $sspIndex=['f2', 'f3', 'f4'];
    foreach (['f1', 'f2', 'f3'] as $i => $fIndex) {
        $suffix = "{$fIndex}{$unitSuffix}";
        $sspSuffix = "{$sspIndex[$i]}{$unitSuffix}";
        $s0 = $coeffs["s0_{$suffix}"] ?? null;
        $s1 = $coeffs["s1_{$suffix}"] ?? null;
        $s2 = $coeffs["s2_{$suffix}"] ?? 0;
        $ssp = $coeffs["ssp_{$sspSuffix}"] ?? null;
        if ($s0 === null || $s1 === null)continue;
        //$note.=" calc $fIndex, is sum ($sum)> ssp ($ssp) ssp_{$suffix} s0=$s0 s1=$s1";
        if ($ssp !== null && $sum > $ssp)continue;
        $load = $s2 * $sum ** 2 + $s1 * $sum + $s0;
        $segmentUsed = true;
        break;
    }
    //$note.=" segment used $s0, $s1, $s2, $ssp *";
    if (!$segmentUsed) {
        $suffix = "f1{$unitSuffix}";
        $s0 = $coeffs["s0_{$suffix}"] ?? 0;
        $s1 = $coeffs["s1_{$suffix}"] ?? 0;
        $s2 = $coeffs["s2_{$suffix}"] ?? 0;
        $load = $s2 * $sum ** 2 + $s1 * $sum + $s0;
    }
    return $load;
}

function calc_coal($load){
  return ((1151.4 * ($load/ 140) ** 2 - 2439.8 * ($load / 140) + 4014) / 4200) * $load;
}

function reviseWithConstraint(&$data, $input) {
    global $notes;
    $notes.=" revise with constraint ";
    $modeling = $input['data3']['modeling'];
    $unit_priority = $modeling['unit_priority'];
    $unit_info = $input['data3'];
    $use_gas = $modeling['gas_constrain'] > 0;
    $use_pln = $modeling['pln_constrain'] > 0;
    if ($use_gas) {
        adjustGasToTarget($data, $unit_priority, $unit_info, $modeling);
    }
    if ($use_pln) {
        adjustPlnToTarget($data, $unit_priority, $unit_info, $modeling);
    }
}

function adjustGasToTarget(&$data, $unit_priority, $unit_info, $modeling) {
  global $notes;
  $loop = 0;
  $gas_target = $modeling['gas_constrain'];
  $start_row = isset($modeling['calculation_begin']) ? intval($modeling['calculation_begin']) : 0;
  do {
      $gas_actual = 0;$jababeka = 0;
      foreach (array_slice($data, $start_row) as $row) {$gas_actual += $row['Total_Gas'];$jababeka += $row['Jababeka'];}
      $heatrate=$gas_actual/$jababeka * 1000000;
      $gas_actual /= 2;
      $diff = $gas_target - $gas_actual;
      if (abs($diff / $gas_target) < 0.01) break;
      $direction = $diff > 0 ? 'increase' : 'decrease';
      $mw_adjust = (abs($diff)/$heatrate) * 1000000;
      //$notes.="gas actual $gas_actual adjust to $gas_target = $diff pada heatrate $heatrate setara dengan mw $mw_adjust * ";      
      $blocks = $direction === 'increase' ? $unit_priority : array_reverse($unit_priority);
      //$notes.=json_encode($blocks);
      foreach ($blocks as $block) {
        if (in_array('required', $block)) $block = array_diff($block, ['required']);
        $eligible_units = [];
        //$notes.=json_encode($block);
        foreach ($block as $unit) {
          if($unit=='b1' OR $unit=='b2')continue;
          //$notes.=" check $unit ";  
          if (in_array($unit, $modeling['unit_stop'])) continue;
          $uid = strtolower($unit);
          if (!isset($unit_info[$uid]['max_load'])) continue;
          $cur_mw = $row[strtoupper($unit)];
          $max = $unit_info[$uid]['max_load'];
          if($cur_mw*1.01>$max)continue;
          //$notes.=" cur $cur_mw max $max -> ok ";
          foreach ($data as $i => $row) {
            if ($i < $start_row) continue;
            if (!isset($row[strtoupper($unit)])) continue;
            if (isset($modeling['unit_fix_load'][$uid])) {
                foreach ($modeling['unit_fix_load'][$uid] as $fix) {
                    if ($i >= $fix['start'] && $i <= $fix['stop']) continue 2;
                }
            }
            $cur = $row[strtoupper($unit)];
            $room = $direction === 'increase' ? $max - $cur : $cur;
            if ($room > 0) {
                $eligible_units[] = [
                    'unit' => strtoupper($unit),
                    'gas_key' => 'gas' . substr(strtolower($unit), 1),
                    'row' => $i,
                    'room' => $room,
                    'max' => $max,
                    'stg' => isset($unit_info[$uid]['stg']) ? strtoupper($unit_info[$uid]['stg']) : null
                ];
              }
            }
          }
          $total_room = array_sum(array_column($eligible_units, 'room'));
          if ($total_room <= 0) continue;
          //$notes.=" total room $total_room * ";      
          $remaining = $mw_adjust;
          $slots = count($eligible_units);

          foreach ($eligible_units as $entry) {
              $i = $entry['row'];
              $u = $entry['unit'];
              $gk = $entry['gas_key'];
              $room = $entry['room'];
              $stg = $entry['stg'];
              $share = min($room, $remaining / $slots);

              $add = 0;
              if ($direction === 'increase') {
                  $data[$i][$u] += $share;
                  $add += $share;
                  $data[$i][$gk] += $share / 50;
                  $data[$i]['Total_Gas'] += $share / 50;
              } else {
                  $data[$i][$u] -= $share;
                  $add -= $share;
                  $data[$i][$gk] -= $share / 50;
                  $data[$i]['Total_Gas'] -= $share / 50;
              }

              if ($stg) {
                  $old_stg = $data[$i][$stg];

                  // collect associated GTG loads in same block
                  $block_units = [];
                  foreach ($unit_priority as $bp) {
                      if (in_array(strtolower($u), array_map('strtolower', $bp))) {
                          $block_units = $bp;
                          break;
                      }
                  }

/// DISINI masih meragukan
                  $loads = [];
                  foreach ($block_units as $other_unit) {
                      $other_uid = strtolower($other_unit);
                      if (!isset($unit_info[$other_uid]['fuel']) || $unit_info[$other_uid]['fuel'] !== 'gas') continue;
                      if (!isset($data[$i][strtoupper($other_unit)])) continue;
                      $loads[] = $data[$i][strtoupper($other_unit)];
                  }

                  while (count($loads) < 3) $loads[] = 0;
                  $new_stg = calc_stg(strtolower($stg), $loads[0], $loads[1], $loads[2]);
                  //if($i<2)$notes.="calc $i * $stg = $old_stg * $new_stg **  ".$loads[0]." , ".$loads[1]." , ".$loads[2]." *** ";

                  $data[$i][$stg] = $new_stg;
                  $add += $new_stg - $old_stg;
              }



              $data[$i]['Export_PLN'] += $add;
              $data[$i]['Jababeka'] += $add;

              $remaining -= abs($share);
              if ($remaining <= 0) break;
          }

          if ($remaining <= 0) break;
      }

      $loop++;
  } while ($loop < 10);
}

function adjustPlnToTarget(&$data, $unit_priority, $unit_info, $modeling) {
    $loop = 0;
    $pln_target = $modeling['pln_constrain'];
    do {
        $pln_actual = array_sum(array_column($data, 'Export_PLN')) / 2;
        $diff = $pln_target - $pln_actual;
        if (abs($diff / $pln_target) < 0.01) break;

        $direction = $diff > 0 ? 'increase' : 'decrease';
        $mw_adjust = abs($diff);
        $blocks = $direction === 'increase' ? $unit_priority : array_reverse($unit_priority);

        foreach ($blocks as $block) {
            if (in_array('required', $block)) $block = array_diff($block, ['required']);

            $eligible_units = [];
            foreach ($block as $unit) {
                if (in_array($unit, $modeling['unit_stop'])) continue;
                $uid = strtolower($unit);
                if (!isset($unit_info[$uid]['max_load'])) continue;
                $max = $unit_info[$uid]['max_load'];

                foreach ($data as $i => $row) {
                    if (!isset($row[strtoupper($unit)])) continue;

                    if (isset($modeling['unit_fix_load'][$uid])) {
                        foreach ($modeling['unit_fix_load'][$uid] as $fix) {
                            if ($i >= $fix['start'] && $i <= $fix['stop']) continue 2;
                        }
                    }

                    $cur = $row[strtoupper($unit)];
                    $room = $direction === 'increase' ? $max - $cur : $cur;
                    if ($room > 0) {
                        $eligible_units[] = [
                            'unit' => strtoupper($unit),
                            'row' => $i,
                            'room' => $room,
                            'max' => $max
                        ];
                    }
                }
            }

            $total_room = array_sum(array_column($eligible_units, 'room'));
            if ($total_room <= 0) continue;

            $remaining = $mw_adjust;
            $slots = count($eligible_units);

            foreach ($eligible_units as $entry) {
                $i = $entry['row'];
                $u = $entry['unit'];
                $room = $entry['room'];

                $share = min($room, $remaining / $slots);

                if ($direction === 'increase') {
                    $data[$i][$u] += $share;
                    $data[$i]['Export_PLN'] += $share;
                } else {
                    $data[$i][$u] -= $share;
                    $data[$i]['Export_PLN'] -= $share;
                }

                $remaining -= $share;
                if ($remaining <= 0) break;
            }

            if ($remaining <= 0) break;
        }

        $loop++;
    } while ($loop < 10);
}
?>