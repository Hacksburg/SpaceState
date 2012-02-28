<?php
// /////////////////////////////////////////////////////////////////////////////
//
// Frack space indicator as used on hackerspaces.nl
// This script is polled by them every 5 minutes
//
// There is an API version available by adding '?api' to the request.
// This will return a 1 or 0 indicating whether the space is open or not.
//
// There is also a JSON API interface, which is accessed through '?json'
// This returns a reply conforming to version 0.11 of the Hackerspaces API:
// https://hackerspaces.nl/spaceapi/
//
// /////////////////////////////////////////////////////////////////////////////
include 'json.class.php';
include 'twitter.class.php';
$config = parse_ini_file('spacestate.ini', true);


function OpenDatabase() {
  global $config;
  return new PDO(sprintf('sqlite:%s', $config['status']['database']));
}


function RecentCheckins() {
  $db = OpenDatabase();
  $query = 'SELECT * FROM `event` ORDER BY `ID` DESC LIMIT 10';
  $checkins = array();
  foreach ($db->query($query) as $checkin) {
    $checkins[] = array('name' => 'Frack',
                        'type' => $checkin['action'],
                        't' => $checkin['timestamp']);
  }
  return $checkins;
}


function RecordCheckin($newstate) {
  $db = OpenDatabase();
  $query = $db->prepare('INSERT INTO `event` (`action`, `timestamp`)
                         VALUES (:action, :timestamp)');
  $query->execute(array(':action' => ($newstate) ? 'check-in' : 'check-out',
                        ':timestamp' => time()));
}


function SpaceStatus() {
  global $config;
  return (bool) file_get_contents($config['status']['statusfile']);
}


function SetSpaceStatus($status, $tweet=true) {
  global $config;
  if ($status != SpaceStatus()) {
    $fp = fopen($config['status']['statusfile'], 'w');
    fwrite($fp, $status ? '1' : '0');
    fclose($fp);
    RecordCheckin($status);
    if ($tweet) {
      TweetSpaceState($status);
    }
    return (bool) $status;
  }
}


function TweetSpaceState($status) {
  try {
    global $config;
	  $twitter = new Twitter(
	      $config['oauth']['consumerKey'], $config['oauth']['consumerSecret'],
	      $config['oauth']['accessToken'], $config['oauth']['accessTokenSecret']);
	  $twitter->send(sprintf('The space is now %s (changed on %s)',
	                         $status ? 'open!' :'closed.',
	                         date('Y-n-j H:i')));
	} catch (Exception $e) {
		echo 'Caught exception: ',  $e->getMessage(), "\n", '<br><br>';
		echo 'Twitter API barked at us, likely because of rate limiting;';
		echo 'Please try again after a minute.<br>';
		echo 'If the problem persists, please contact info[at]frack.nl';
		exit();
  }
}


if (isset($_GET['api'])) {
  header('Access-Control-Allow-Origin: *');
  header('Cache-Control: no-cache, must-revalidate');
  echo PrettyJson(array(
      'api' => '0.11',
      'space' => 'Frack',
      'logo' => 'http://frack.nl/wiki/Frack-logo.png',
      'icon' => array(
          'closed' => 'http://frack.nl/spacestate/icon_closed.png',
          'open' => 'http://frack.nl/spacestate/icon_open.png'),
      'url' => 'http://frack.nl',
      'address' => 'Zuiderplein 33, 8911 AN, Leeuwarden, The Netherlands',
      'contact' => array(
          'phone' => '+31681563934',
          'keymaster' => 'key-holders@frack.nl',
          'irc' => 'irc://irc.eth-0.nl/#frack',
          'twitter' => '@fracknl',
          'email' => 'info@frack.nl',
          'ml' => 'general@frack.nl'),
      'lat' => 53.197916,
      'lon' => 5.796962,
      'open' => SpaceStatus(),
      'events' => RecentCheckins()));
} elseif (isset($_GET['banner'])) {
  echo sprintf('<html>
   <head>
     <title>Frack space indicator</title>
     <style type="text/css">* {margin:0;padding:0}</style>
   </head><body><a href="http://frack.nl"><img src="banner_%s.png" /></a></body></html>',
   SpaceStatus() ? 'open' : 'closed');
} else {
  // regular door script here
  $update_error = '';
  $status = SpaceStatus();
  if (isset($_POST['pass'])) {
    if ($_POST['pass'] != $config['status']['secret']) {
      $update_error = '<p class="error">Sorry, the password you entered is not correct.</p>';
    } elseif (!isset($_POST['newstate'])) {
      $update_error = '<p class="error">The new space state was not specified :(</p>';
    } else {
      $status = SetSpaceStatus((int) $_POST['newstate']);
    }
  }
  $closed_replacements = array(
      'The Frack hackerspace is now closed :(',                // page title
      'closed',                                                // body class
      $update_error,                                           // state update errors
      'Sorry, but we\'re closed right now, try again later.',  // body content
      'Are you a member? Open the space',                      // form leader
      '1', 'Open the space');                                  // newstate and button values
  $open_replacements = array(  // sames values as above
      'The Frack hackerspace is now open!',
      'open',
      $update_error,
      'The space is now open.<br>You are welcome to come over!',
      'Please close the space if you are the last person to leave',
      '0', 'Close the space');

  vprintf('<!DOCTYPE HTML>
  <html>
    <head>
      <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
      <meta name="viewport" content="initial-scale=1.0">
      <link rel="stylesheet" type="text/css" href="style.css">
      <title>%s</title>
    </head>
    <body class="%s">
      <div class="dashes slant_right"></div>
      <div class="box slant_left">%s<p>%s</p></div>
      <div class="dashes slant_left"></div>
      <div class="box slant_right">
        <form method="post">
          <p>%s:</p>
          <p>
            <input type="password" name="pass" />
            <input type="hidden" name="newstate" value="%s" />
            <input type="submit" name="submit" value="%s" />
          </p>
        </form>
      </div>
    </body>
  </html>',
  $status ? $open_replacements : $closed_replacements);
}
?>
