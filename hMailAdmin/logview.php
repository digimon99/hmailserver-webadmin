<?php
//ini_set('display_errors', 1);
define('IN_WEBADMIN', true);

require_once("./config.php");
require_once("./include/initialization_test.php");
require_once("./initialize.php");

if (hmailGetAdminLevel() != 2)
	hmailHackingAttemp();

function filter_result($str, $findme, $type=true) {
	if ( ($pos = stripos($str, $findme)) !== false && (!$type || $pos < 3)) {
		return $str;
	}
}

function filter_result_type($str, $types) {
	foreach ($types as $v) {
		if (!is_null($result = filter_result($str, $v, true)))
			return $result;
	}
}

$Types = !empty($_POST['LogTypes']) ? $_POST['LogTypes'] : array('SMTPD');
$AllTypes = in_array('ALL', $Types);
$RawType = !empty($_POST['LogType']) ? true : false;
$Filter = !empty($_POST['LogFilter']) ? $_POST['LogFilter'] : null;
$Filename = !empty($_POST['LogFilename']) ? $_POST['LogFilename'] : date("Y-m-d");
$Filename = 'hmailserver_' . $Filename . '.log';
$Path = $obBaseApp->Settings->Directories->LogDirectory;
$Filename = $Path . '\\' . $Filename;

if (file_exists($Filename)) {
	$Filesize = filesize($Filename);
	$File = fopen($Filename, 'r');

	if ($File) {
		$events=array();
		while (($Line = fgets($File)) !== false) {
			if ($RawType){
				if (!isset($events[0])) $events[0][0] = array('RAW');
				$events[0][1][] = htmlentities(cleanNonUTF8($Line));
				continue;
			}

			$Unfiltered = $Line;
			$Filtered = $AllTypes ? $Unfiltered :filter_result_type($Unfiltered, $Types);
			if (!is_null($Filter)) {
				$Filtered = filter_result($Filtered, $Filter, false);
				$Filtered = preg_replace("/\w*?$Filter\w*/i", "{em}$0{/em}", $Filtered);
			}

			if (!is_null($Filtered)) parse($Filtered);
		}
		fclose($File);
		$out = events();
	} else {
		$out = $obLanguage->String("Error opening log file");
	}
} else {
	$out = $obLanguage->String("Log file not found");
}

header('Content-Type: application/json');
$out = json_encode($out);
echo $out;

function parse($line){
	global $events;
	$line = cleanString($line);
	$line = cleanNonUTF8($line);
	$data = explode("\t", $line);
	switch($data[0]){
		case 'SMTPD':
		case 'SMTPC':
			parse_smtp($data);
			break;
		case 'POP3D':
		case 'POP3C':
		case 'IMAPD':
			parse_imap($data);
			break;
		case 'TCPIP':
		case 'DEBUG':
		case 'APPLICATION':
		case 'ERROR':
			parse_error($data);
	}
}

$datastore = array();
function parse_smtp($data){
	global $datastore,$events;

	if (!isset($events[$data[0] . $data[2]])) {
		$events[$data[0] . $data[2]][0] = array($data[0], $data[2], $data[4]);
	}

	// AUTH LOGIN decoder.
	// First we get a SENT: 334 VXNlcm5hbWU6 RECEIVED: AUTH LOGIN
	// The next RECEIVED: line contains login username which is e-mail address, base64 encoded.

	if (isset($datastore[$data[0] . $data[2]]) && strpos($data[5],'RECEIVED: ') !== false) {
		// We got it.
		$base64 = substr($data[5], strrpos($data[5], ' ') + 1, strlen($data[5]));
		$data[5] = 'RECEIVED: <b>' . base64_decode($base64) . '</b>';
		unset($datastore[$data[0] . $data[2]]);
	} else if (strpos($data[5], 'RECEIVED: AUTH LOGIN ') !== false && strlen($data[5]) > 21) {
		// Got singel line AUTH LOGIN?
		$base64 = substr($data[5], strrpos($data[5], ' ') + 1, strlen($data[5]));
		$data[5] = substr($data[5], 0, strrpos($data[5], ' ') + 1) .' <b>' . base64_decode($base64) . '</b>';
	} else if (strpos($data[5], 'SENT: 334 VXNlcm5hbWU6') !== false) {
		// Wait for it.
		$datastore[$data[0] . $data[2]] = true;
	}

	$events[$data[0] . $data[2]][1][] = array($data[3], $data[5]);
}

function parse_imap($data){
	global $events;

	if (!isset($events[$data[0] . $data[2]])) {
		$events[$data[0] . $data[2]][0] = array($data[0], $data[2], $data[4]);
	}

	$events[$data[0] . $data[2]][1][] = array($data[3], $data[5]);
}

function parse_error($data){
	global $events;

	if (!isset($events[$data[0] . $data[2]])) {
		$events[$data[0] . $data[2]][0] = array($data[0]);
	}

	$events[$data[0] . $data[2]][1][] = array($data[2], $data[3]);
}

function events(){
	global $events;
	$out = array();
	foreach ($events as $data) {
		$out[] = $data;
	}
	if (empty($out)) $out = $obLanguage->String("No matched entries in the log file");
	return $out;
}

function cleanString($str) {
	$search = array("\r\n", "'", '"', '<', '>', '[nl]', '{em}', '{/em}','\n');
	$replace = array('', '', '', '&lt;', '&gt;', '<br>', '<em>', '</em>','<br>');
	return str_replace($search, $replace, $str);
}

function cleanNonUTF8($str) {
	$regex = <<<'END'
/
  (
    (?: [\x00-\x7F]                 # single-byte sequences   0xxxxxxx
    |   [\xC0-\xDF][\x80-\xBF]      # double-byte sequences   110xxxxx 10xxxxxx
    |   [\xE0-\xEF][\x80-\xBF]{2}   # triple-byte sequences   1110xxxx 10xxxxxx * 2
    |   [\xF0-\xF7][\x80-\xBF]{3}   # quadruple-byte sequence 11110xxx 10xxxxxx * 3
    ){1,100}                        # ...one or more times
  )
| .                                 # anything else
/x
END;
	return preg_replace($regex, '$1', $str);
}
?>