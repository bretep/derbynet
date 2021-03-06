<?php @session_start();
    require_once('inc/data.inc');
    require_once('inc/schema_version.inc');
    require_once('inc/running_round_header.inc');

    $use_master_sched = use_master_sched();
    $high_water_rounds = high_water_rounds();
?><!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<script type="text/javascript" src="js/jquery.js"></script>
<?php if (isset($as_kiosk)) require_once('inc/kiosk-poller.inc'); ?>
<script type="text/javascript">
var g_update_status = {
      last_update_time: "", // First refresh is for everything
      high_water_resultid: <?php echo high_water_resultid(); ?>,
      high_water_tbodyid: <?php echo $high_water_rounds['roundid']; ?>,
      use_master_sched: <?php echo $use_master_sched ? 1 : 0; ?>,
      // Even if using master scheduling, display the round results separately.
      merge_rounds: false,
};
</script>
<?php require_once('inc/ajax-failure.inc'); ?>
<script type="text/javascript" src="js/update.js"></script>
<?php if (isset($as_kiosk))
    echo '<script type="text/javascript" src="js/results-by-racer-scrolling.js"></script>'."\n";
?>
<title>Results By Racer <?php if (isset($_GET['racerid'])) echo ' for '.$_GET['racerid']; ?></title>
<?php require('inc/stylesheet.inc'); ?>
<style>
.scroll-bounding-rect {
      overflow: hidden;
}
</style>
</head>
<body>
<?php
$banner_title = 'Results By Racer';
require('inc/banner.inc');

$nlanes = get_lane_count_from_results();

$now_running = get_running_round();
running_round_header($now_running, /* Use RoundID */ TRUE);

require_once('inc/rounds.inc');
$rounds = all_rounds();

$sql = 'SELECT RegistrationInfo.racerid,'
    .' Classes.class, round, heat, lane, finishtime, resultid,'
    .' carnumber, RegistrationInfo.firstname, RegistrationInfo.lastname,'
    .' Classes.classid, Rounds.roundid'
    .' FROM '.inner_join('RaceChart', 'RegistrationInfo',
                         'RegistrationInfo.racerid = RaceChart.racerid',
                         'Roster', 'Roster.racerid = RegistrationInfo.racerid',
                         'Rounds', 'Rounds.roundid = Roster.roundid',
                         'Classes', 'Rounds.classid = Classes.classid')
    .' WHERE Rounds.roundid = RaceChart.roundid'
    .(isset($_GET['racerid'])
          ? ' AND RaceChart.racerid = '.$_GET['racerid'] : '')
    .' ORDER BY '
    .(schema_version() >= 2 ? 'Classes.sortorder, ' : '')
    .'class, round, lastname, firstname, carnumber, resultid, lane';

$stmt = $db->query($sql);
if ($stmt === FALSE) {
  $info = $db->errorInfo();
  echo '<h2>Error: '.$info[2].'</h2>'."\n";
 }
?>
<div class="scroll-bounding-rect">
<table class="main_table">
<?php

function byes($n) {
  $result = '';
  while ($n > 0) {
    $result .= '<td>--</td>';
    --$n;
  }
  return $result;
}

function write_rr($racer_label, $racer_cells, $nrows) {
  global $nlanes;
  global $row;  // read and written

  $rrow = '<tr class="d'.($row & 1).'">'
         .'<th rowspan="'.$nrows.'">'.$racer_label.'</th>';
  ++$row;
  for ($r = 0; $r < $nrows; ++$r) {
    if ($r > 0) {
      $rrow .= '</tr><tr class="d'.($row & 1).'">';
      ++$row;
    }
    foreach ($racer_cells as $cols) {
      if (count($cols) <= $r) {
	// This racer didn't race in this lane (in this round)
	$rrow .= '<td/>'; // '<td>row '.$r.' count '.(count($cols)).'</td>'; // TODO: Bye
      } else {
	$rrow .= $cols[$r];
      }
    }
  }
  $rrow .= '</tr>';
  echo $rrow;
}

$rs = $stmt->fetch(PDO::FETCH_ASSOC);
foreach ($rounds as $round) {
  // Generate header and tbody
  $roundid = $round['roundid'];
  $classid = $round['classid'];
  $groupid = $round['groupid'];

  $is_current = 0;
  if ($now_running['roundid'] == $roundid and
      $now_running['classid'] == $classid)
    $is_current = 1;

  echo '<tbody id="tbody_'.$groupid.'">'."\n";
  echo '<tr><th/><th class="group_spacer wide" colspan="'.$nlanes.'"/></tr>'."\n";
  echo '<tr><th class="pre_group_title"/>'
      .'<th class="group_title wide" colspan="'.$nlanes.'">'
          .htmlspecialchars($round['class'], ENT_QUOTES, 'UTF-8').', Round '.$round['round'].'</th>'
      .'</tr>'."\n";

  echo '<tr>';
  echo '<th>Racer</th>';
  for ($l = 1; $l <= $nlanes; ++$l)
    echo '<th>Lane '.$l.'</th>';
  echo '</tr>'."\n";

  $row = 1;
  $racerid = 0;
  $racer_label = '';
  while ($rs and $rs['roundid'] == $roundid) {
    if ($racerid <> $rs['racerid']) {
      if ($racer_label) {
		write_rr($racer_label, $racer_cells, $nrows);
      }
      $racerid = $rs['racerid'];
      $racer_label = '<span class="racer">'
        .htmlspecialchars($rs['lastname'].', '.$rs['firstname'], ENT_QUOTES, 'UTF-8').'</span>'
		.' (<span class="car">'.$rs['carnumber'].'</span>)';
      $racer_cells = array();
      for ($i = 1; $i <= $nlanes; ++$i) {
		$racer_cells[] = array();
      }
      //++$row;
      $lane = 1;
      $nrows = 1;
    }

    $lane = $rs['lane'];

    $ft = $rs['finishtime'];
    $racer_cells[$lane - 1][] = '<td class="resultid_'.$rs['resultid'].'">'
                 .'<a class="heat_link" href="ondeck.php#heat_'.$roundid.'_'.$rs['heat'].'">'
                 .'<span class="time">'.($ft ? number_format($ft, 3) : '--').'</span>'
                 .'</a>'
                 .'</td>'."\n";
    if (count($racer_cells[$lane - 1]) > $nrows) {
      $nrows = count($racer_cells[$lane - 1]);
    }
    ++$lane;
    $rs = $stmt->fetch(PDO::FETCH_ASSOC);
  }

  if ($racer_label) {
    write_rr($racer_label, $racer_cells, $nrows);
  }
  echo '</tbody>'."\n";
}

$stmt->closeCursor();
?>
</table>
</div>
<div class="block_buttons">
<?php
  if (isset($_GET['racerid'])) {
    echo '<form method="link" action="racer-results.php">'."\n";
    echo '<input type="submit" value="View All Racers"/>'."\n";
    echo '</form>'."\n";
  }
?>
</div>
</body>
</html>