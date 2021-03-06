<?php

// $_POST['roundid'] for "no change"
// $_POST['roundid']: set but empty for pack standings
//
// $_POST['expose'] unset for "show none", i.e., same as 0
// $_POST['expose'] = n or +n or 'all'

// standings-message value is <roundid>-<exposed>
//
// No standings-message value means no standings are showing/have been shown.
//
// <roundid> == '' means whole-pack standings
//
// <exposed> == '' means all shown

start_response();

if (have_permission(PRESENT_AWARDS_PERMISSION)) {
  $prev = explode('-', read_raceinfo('standings-message', '-'));
  $roundid = $prev[0];
  if (isset($_POST['roundid'])) {
    $roundid = $_POST['roundid'];  // Possibly empty
  }

  if (!isset($_POST['expose']) or $_POST['expose'] == '') {
    $expose = '0';
  } else if ($_POST['expose'] == 'all') {
    $expose = '';
  } else if (substr($_POST['expose'], 0, 1) == '+') {
    $expose = intval(substr($_POST['expose'], 1)) + intval($prev[1]);
  } else {
    $expose = intval($_POST['expose']);
  }

  write_raceinfo('standings-message', $roundid.'-'.$expose);
  echo "<success/>\n";
  echo "<standings>".$roundid.'-'.$expose."</standings>\n";
} else {
  not_authorized_body();
}

end_response();
?>