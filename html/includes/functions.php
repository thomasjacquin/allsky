<?php

/**
 *
 * This code is executed outside of a function and is needed by index.php and public.php.
 * It's centralized in this file to make it easier to maintain.
 *
*/

// This file sets all the define() variables.
$defs = 'allskyDefines.inc';
if ((include $defs) == false) {
	echo "<br><div class='errorMsgBig errorMsgBox'>";
	echo "The installation of the WebUI is incomplete.<br>";
	echo "File '$defs' not found.<br>";
	echo "Please run the following to fix:";
	echo "<br><br>";
	echo "<code>   cd ~/allsky; ./install.sh --function create_webui_defines</code>";
	echo "</div>";
	exit(1);
}

// Read and decode a json file, returning the decoded results or null.
// On error, display the specified error message.
// If we're being run by the user it's likely on a tty so don't use html.
function get_decoded_json_file($file, $associative, $errorMsg, &$returnedMsg=null) {
	$retMsg = "";
	$html = (get_current_user() == WEBSERVER_OWNER);
	if ($html) {
		$div = "<div class='errorMsgBig'>";
		$end = "</div>";
		$br = "<br>";
		$sep = "<br>";
	} else {
		$div = "";
		$end = "\n";
		$br = "\n";
		$sep = "\n";
	}

	if ($file == "" || ! file_exists($file)) {
		$retMsg .= $div;
		$retMsg .= $errorMsg;
		if ($file == "")
			$retMsg .= " File not specified!";
		else
			$retMsg .= " File <b>$file</b> not found!";
		$retMsg .= $end;
		if ($returnedMsg === null) echo "$retMsg";
		else $returnedMsg = $retMsg;
		return null;
	}

	$str = file_get_contents($file, true);
	if ($str === "" || $str === false) {
		$retMsg .= $div;
		$retMsg .= $errorMsg;
		if ($str === "")
			$retMsg .= " File <b>$file</b> is empty!";
		else
			$retMsg .= " Error reading <b>$file</b>!";
		$retMsg .= $end;
		if ($returnedMsg === null) echo "$retMsg";
		else $returnedMsg = $retMsg;
		return null;
	}

	$str_array = json_decode($str, $associative);
	if ($str_array == null) {
		$retMsg .= $div;
		$retMsg .= "$errorMsg ";
		$retMsg .= json_last_error_msg();
		$cmd = "json_pp < $file 2>&1";
		exec($cmd, $output);
		$retMsg .= $br;
		$retMsg .= implode($sep, $output);
		$retMsg .= $end;
		if ($returnedMsg === null) echo "$retMsg";
		else $returnedMsg = $retMsg;
		return null;
	}
	return $str_array;
}

// The opposite of toString().  Given a string version of a boolean, return true or false.
function toBool($s) {
	if ($s == "true" || $s == "Yes" || $s == "yes" || $s == "1")
		return true;
	return false;
}

function verifyNumber($num) {
	if ($num == "" || ! is_numeric($num)) {
		return false;
	}
	return true;
}

// Globals
$image_name = null;
$showUpdatedMessage = true; $delay=null; $daydelay=null; $nightdelay=null;
$imagesSortOrder = null;
$darkframe = null;
$useLogin = null;
$temptype = null;
$lastChanged = null;
$remoteWebsiteURL = null;
$settings_array = null;
$useLocalWebsite = null;
$useRemoteWebsite = null;
$hasLocalWebsite = null;
$hasRemoteWebsite = null;
$endSetting = "XX_END_XX";

function readSettingsFile() {
	$settings_file = getSettingsFile();
	$errorMsg = "ERROR: Unable to process settings file '$settings_file'.";
	$contents = get_decoded_json_file($settings_file, true, $errorMsg);
	if ($contents === null) {
		exit(1);
	}
	return($contents);
}

function readOptionsFile() {
	$options_file = getOptionsFile();
	$errorMsg = "ERROR: Unable to process options file '$options_file'.";
	$contents = get_decoded_json_file($options_file, true, $errorMsg);
	if ($contents === null) {
		exit(1);
	}
	return($contents);
}

function initialize_variables($website_only=false) {
	global $status;
	global $image_name;
	global $showUpdatedMessage, $delay, $daydelay, $nightdelay;
	global $imagesSortOrder;
	global $darkframe, $useLogin, $temptype, $lastChanged, $lastChangedName;
	global $remoteWebsiteURL;
	global $settings_array;
	global $useLocalWebsite, $useRemoteWebsite;
	global $hasLocalWebsite, $hasRemoteWebsite;

	$settings_array = readSettingsFile();

	// See if there are any website configuration files.
	// Assume if there are, that website is in use.
	if (file_exists(getLocalWebsiteConfigFile())) {
		$hasLocalWebsite = true;
	} else {
		$hasLocalWebsite = false;
	}
	if (file_exists(getRemoteWebsiteConfigFile())) {
		$hasRemoteWebsite = true;
	} else {
		$hasRemoteWebsite = false;
	}
	$useLocalWebsite = toBool(getVariableOrDefault($settings_array, 'uselocalwebsite', "false"));
	$useRemoteWebsite = toBool(getVariableOrDefault($settings_array, 'useremotewebsite', "false"));

	if ($website_only) return;

	// $img_dir is an alias in the web server's config that points to where the current image is.
	// It's the same as ${ALLSKY_TMP} which is the physical path name on the server.
	$img_dir = get_variable(ALLSKY_HOME . '/variables.sh', 'IMG_DIR=', 'current/tmp');
	$f = getVariableOrDefault($settings_array, 'filename', "image.jpg");
	$image_name = "$img_dir/$f";
	$darkframe = toBool(getVariableOrDefault($settings_array, 'takedarkframes', "false"));
	$imagesSortOrder = getVariableOrDefault($settings_array, 'imagessortorder', "ascending");
	$useLogin = toBool(getVariableOrDefault($settings_array, 'uselogin', "true"));
	$temptype = getVariableOrDefault($settings_array, 'temptype', "C");
	$lastChanged = getVariableOrDefault($settings_array, $lastChangedName, "");
	$remoteWebsiteURL = getVariableOrDefault($settings_array, 'remotewebsiteurl', "");

	$ms_per_sec = 1000;		// to make the code easier to read

	////////////////// Determine delay between refreshes of the image.
	$daydelay = getVariableOrDefault($settings_array, 'daydelay', 30 * $ms_per_sec);
	$nightdelay = getVariableOrDefault($settings_array, 'nightdelay', 30 * $ms_per_sec);
	$showUpdatedMessage = toBool(getVariableOrDefault($settings_array, 'showupdatedmessage', "true"));

	$dayexposure = getVariableOrDefault($settings_array, 'dayexposure', 500);
	$daymaxautoexposure = getVariableOrDefault($settings_array, 'daymaxautoexposure', 100);
	$nightexposure = getVariableOrDefault($settings_array, 'nightexposure', 10 * $ms_per_sec);
	$nightmaxautoexposure = getVariableOrDefault($settings_array, 'nightmaxautoexposure', 10 * $ms_per_sec);

	$ok = true;
	// These are all required settings so if they are blank don't display a
	// message since the WebUI will.
	$delay = 0;
	if (! verifyNumber($daydelay)) $ok = false; else $delay += $daydelay;
	if (! verifyNumber($daymaxautoexposure)) $ok = false;
	if (! verifyNumber($dayexposure)) $ok = false;
	if (! verifyNumber($nightdelay)) $ok = false; else $delay += $nightdelay;
	if (! verifyNumber($nightmaxautoexposure)) $ok = false;
	if (! verifyNumber($nightexposure)) $ok = false;

	if (! $ok) {
		$showUpdatedMessage = false;
		if ($delay === 0) $delay = 20 * $ms_per_sec;	// a reasonable default
		return;
	}

	$dayautoexposure = toBool(getVariableOrDefault($settings_array, 'dayautoexposure', "true"));
	$nightautoexposure = toBool(getVariableOrDefault($settings_array, 'nightautoexposure', "true"));
	$maxDayExposure = ($dayautoexposure ? $dayexposure : $daymaxautoexposure);
	$maxNightExposure = ($nightautoexposure ?  $nightexposure : $nightmaxautoexposure);
	$consistentDelays = toBool(getVariableOrDefault($settings_array, 'consistentdelays', "true"));

	$daydelay += ($consistentDelays ? $maxDayExposure : $dayexposure);
	$nightdelay += ($consistentDelays ? $maxNightExposure : $nightexposure);

	// Determine if it's day or night so we know which delay to use.
	$angle = getVariableOrDefault($settings_array, 'angle', -6);
	$lat = getVariableOrDefault($settings_array, 'latitude', "");
	$lon = getVariableOrDefault($settings_array, 'longitude', "");
	if ($lat != "" && $lon != "") {
		exec("sunwait poll exit set angle $angle $lat $lon", $return, $retval);
		if ($retval == 2) {
			$delay = $daydelay;
		} else if ($retval == 3) {
			$delay = $nightdelay;
		} else {
			$msg = "<code>sunwait</code> returned $retval; don't know if it's day or night.";
			$status->addMessage($msg, 'danger');
			$delay = ($daydelay + $nightdelay) / 2;		// Use the average delay
		}

		// Convert to seconds for display on the LiveView page.
		// These variables are now only used for the display.
		$daydelay /= $ms_per_sec;
		$nightdelay /= $ms_per_sec;
	} else {
		// Error message will be displayed by WebUI.
		$showUpdatedMessage = false;
		// Not showing delay so just use average
		$delay = ($daydelay + $nightdelay) / 2;		// Use the average delay
	}

	// Lessen the delay between a new picture and when we check.
	$delay /= 5;
	$delay = max($delay, 2 * $ms_per_sec);
}

// Check if the settings have been configured.
function check_if_configured($page, $calledFrom) {
	global $lastChanged, $status;
	static $will_display_configured_message = false;

	if ($will_display_configured_message)
		return(true);

	if ($lastChanged === "") {
		// The settings aren't configured - probably right after an installation.
		$msg = "Allsky must be configured before using it.<br>";
		if ($page === "configuration")
			$msg .= "If it's already configured, just click on the 'Save changes' button.";
		else
			$msg .= "Go to the 'Allsky Settings' page to do so.";
		$status->addMessage("<div id='mustConfigure' class='important'>$msg</div>", 'danger');
		$will_display_configured_message = true;
		return(false);
	}

	return(true);
}
/**
*
* We need to make sure we're only trying to display and delete image directories, not ones like /etc.
* In this case "valid" means it's an image directory.
* We allow "test*" in case the user created a directory for testing.
* Example directory: 20210710.  They should start with "2" for the 2000's.
*
*/
function is_valid_directory($directory_name) {
	return preg_match('/^(2\d{7}|test\w*)$/', basename($directory_name));
}

/**
*
* Add CSRF Token to form
*
*/
function CSRFToken() {
	global $useLogin;
	if (! $useLogin) return;
?>
<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
<?php
}

/**
*
* Validate CSRF Token
*
*/
function CSRFValidate() {
  global $useLogin;
  if (! $useLogin) return true;
  if (isset($_POST['csrf_token']) && hash_equals($_POST['csrf_token'], $_SESSION['csrf_token']) ) {
    return true;
  } else {
    error_log('CSRF violation');
    return false;
  }
}

/**
* Test whether array is associative
*/
function isAssoc($arr) {
  return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
*
* Display a selector field for a form. Arguments are:
*   $name:     Field name
*   $options:  Array of options
*   $selected: Selected option (optional)
*       If $options is an associative array this should be the key
*
*/
function SelectorOptions($name, $options, $selected = null) {
  echo "<select class=\"form-control\" name=\"$name\">";
  foreach ( $options as $opt => $label) {
    $select = '';
    $key = isAssoc($options) ? $opt : $label;
    if( $key == $selected ) {
      $select = " selected";
    }
    echo "<option value=\"$key\"$select>$label</options>";
  }
  echo "</select>";
}

/**
*
* @param string $input
* @param string $string
* @param int $offset
* @param string $separator
* @return $string
*/
function GetDistString( $input,$string,$offset,$separator ) {
	$string = substr( $input,strpos( $input,$string )+$offset,strpos( substr( $input,strpos( $input,$string )+$offset ), $separator ) );
	return $string;
}

/**
*
* @param array $arrConfig
* @return $config
*/
function ParseConfig( $arrConfig ) {
	$config = array();
	foreach( $arrConfig as $line ) {
		$line = trim($line);
		if( $line != "" && $line[0] != "#" ) {
			$arrLine = explode( "=", $line );
			$config[$arrLine[0]] = ( count($arrLine) > 1 ? $arrLine[1] : true );
		}
	}
	return $config;
}

/**
*
* @param string $freq
* @return $channel
*/
function ConvertToChannel( $freq ) {
  $channel = ($freq - 2407)/5;	// check for 2.4 GHz
  if ($channel > 0 && $channel <= 14) {
    return $channel . " / 2.4GHz";
  } else {	// check for 5 GHz
    $channel = ($freq - 5030)/5;
    if ($channel >= 7 && $channel <= 165) {
      // There are also some channels in the 4915 - 4980 range...
      return $channel . " / 5GHz";
    } else {
      return 'Invalid&nbsp;Channel, Hz=' . $freq;
    }
  }
}

/**
* Converts WPA security string to readable format
* @param string $security
* @return string
*/
function ConvertToSecurity( $security ) {
  $options = array();
  preg_match_all('/\[([^\]]+)\]/s', $security, $matches);
  foreach($matches[1] as $match) {
    if (preg_match('/^(WPA\d?)/', $match, $protocol_match)) {
      $protocol = $protocol_match[1];
      $matchArr = explode('-', $match);
      if (count($matchArr) > 2) {
        $options[] = $protocol . ' ('. $matchArr[2] .')';
      } else {
        $options[] = $protocol;
      }
    }
  }

  if (count($options) === 0) {
    // This could also be WEP but wpa_supplicant doesn't have a way to determine
    // this.
    // And you shouldn't be using WEP these days anyway.
    return 'Open';
  } else {
    return implode('<br />', $options);
  }
}

/**
*
* Functions to get the status output of an interface determine if it's up or down and
* to parse "ifconfig" output and return results.
*/
function get_interface_status($cmd) {
	exec($cmd, $return );
	return(preg_replace('/\s\s+/', ' ', implode(" ", $return)));
}

function is_interface_up($interface_status) {
	return(strpos($interface_status, "UP") !== false ? true : false);
}

function is_interface_running($interface_status) {
	return(strpos($interface_status, "RUNNING") !== false ? true : false);
}

function parse_ifconfig($input, &$strHWAddress, &$strIPAddress, &$strNetMask, &$strRxPackets, &$strTxPackets, &$strRxBytes, &$strTxBytes) {
	preg_match( '/ether ([0-9a-f:]+)/i', $input, $result );
	$strHWAddress = getVariableOrDefault($result, 1, "[not set]");
	preg_match( '/inet ([0-9.]+)/i', $input, $result );
	$strIPAddress = getVariableOrDefault($result, 1, "[not set]");

	preg_match( '/netmask ([0-9.]+)/i', $input, $result );
	$strNetMask = getVariableOrDefault($result, 1, "[not set]");

	preg_match( '/RX packets (\d+)/', $input, $result );
	$strRxPackets = getVariableOrDefault($result, 1, "[not set]");

	preg_match( '/TX packets (\d+)/', $input, $result );
	$strTxPackets = getVariableOrDefault($result, 1, "[not set]");

	preg_match_all( '/bytes (\d+ \(\d+.\d+ [K|M|G]iB\))/i', $input, $result );
	if (isset($result[1][0])) {
		$strRxBytes = $result[1][0];
		$strTxBytes = $result[1][1];
	} else {
		$strRxBytes = 0;
		$strTxBytes = 0;
	}
}

function handle_interface_POST_and_status($interface, $input, &$myStatus) {
	$interface_up = false;
	if( isset($_POST['turn_down']) ) {
		// We should only get here if the interface is up,
		// but just in case, check if it's already down.
		// If the interface is down it's also not running.
		$s = get_interface_status("ifconfig $interface");
		if (! is_interface_up($s)) {
			$myStatus->addMessage("Interface $interface was already down", 'warning', false);
		} else {
			exec( "sudo ifconfig $interface down 2>&1", $output );	// stop
			// Check that it actually stopped
			$s = get_interface_status("ifconfig $interface");
			if (! is_interface_up($s)) {
				$myStatus->addMessage("Interface $interface stopped", 'success', false);
			} else {
				if ($output == "")
					$output = "Unknown reason";
				else
					$output = implode(" ", $output);
				$myStatus->addMessage("Unable to stop interface $interface<br>$output" , 'danger', false);
				$interface_up = true;
			}
		}

	} elseif( isset($_POST['turn_up']) ) {
		// We should only get here if the interface is down,
		// but just in case, check if it's already up.
		if (is_interface_up(get_interface_status("ifconfig $interface"))) {
			$myStatus->addMessage("Interface $interface was already up", 'warning', false);
			$interface_up = true;
		} else {
			exec( "sudo ifconfig $interface up 2>&1", $output );	// start
			// Check that it actually started
			$s = get_interface_status("ifconfig $interface");
			if (! is_interface_up($s)) {
				$myStatus->addMessage("Unable to start interface $interface", 'danger', false);
			} else {
				if (is_interface_running($s))
					$myStatus->addMessage("Interface $interface started", 'success', false);
				else
					$myStatus->addMessage("Interface $interface started but nothing connected to it", 'warning', false);
				$interface_up = true;
			}
		}

	} elseif (is_interface_up($input)) {
		// The interface can be up but nothing connected to it (i.e., not RUNNING).
		if (is_interface_running($input))
			$myStatus->addMessage("Interface $interface is up", 'success', false);
		else
			$myStatus->addMessage("Interface $interface is up but nothing connected to it", 'warning', false);
		$interface_up = true;

	} else {
		$myStatus->addMessage("Interface $interface is down", 'danger', false);
	}

	return($interface_up);
}

/**
*
* Get the last occurence of a variable from a file and return its value; if not there,
* return the default.
* NOTE: The variable's value is anything after the equal sign,
* so there shouldn't be a comment on the line,
* however, there can be optional spaces or tabs before the string.
*
*/
function get_variable($file, $searchfor, $default)
{
	// get the file contents
	if (! file_exists($file)) {
		$msg  = "<div class='error-msg'>";
		$msg .= "<br>File '$file' not found!";
		$msg .= "</div>";
		echo $msg;
		return($default);
	}
	$contents = file_get_contents($file);
	if ($contents == "") return($default);	// file not found or not readable

	// escape special characters in the query
	$pattern = preg_quote($searchfor, '/');
	// finalise the regular expression, matching the whole line
	$pattern = "/^[ 	]*$pattern.*\$/m";

	// search, and store all matching occurences in $matches
	$num_matches = preg_match_all($pattern, $contents, $matches);
	if ($num_matches) {
		$double_quote = '"';

		// Format: [stuff]$searchfor=$value   or   [stuff]$searchfor="$value"
		// Need to delete  [stuff]$searchfor=  and optional double quotes
		// If more than 1 match, get the last match that matches $searchfor EXACTLY.
		if ($num_matches === 1) {
			$match = $matches[0][$num_matches - 1];	// get the last one
		} else {
			for ($i=$num_matches-1; $i>=0; $i--) {
				$match = $matches[0][$i];
				if ($match === $searchfor) {
					break;
				}
			}
		}
		$match = explode( '=', $match)[1];	// get everything after equal sign
		$match = str_replace($double_quote, "", $match);
		return($match);
	} else {
   		return($default);
	}
}

/**
* 
* List a type of file - either "All" (case sensitive) for all days, or only for the specified day.
* If $dir is not null, it ends in "/".
*/
function ListFileType($dir, $imageFileName, $formalImageTypeName, $type) {
	$num = 0;	// Let the user know when there are no images for the specified day
	// "/images" is an alias in the web server for ALLSKY_IMAGES
	$images_dir = "/images";
	$chosen_day = $_GET['day'];
	echo "<h2>$formalImageTypeName - $chosen_day</h2>\n";
	echo "<div class='row'>\n";
	if ($chosen_day === 'All'){
		if ($handle = opendir(ALLSKY_IMAGES)) {
		    while (false !== ($day = readdir($handle))) {
				if (is_valid_directory($day)) {
					$days[] = $day;
					$num += 1;
				}
		    }
		    closedir($handle);
		}

		if ($num == 0) {
			// This could indicate an error, or the user just installed allsky
			echo "<span class='alert-warning'>There are no image directories.</span>";
		} else {
			rsort($days);
			$num = 0;
			foreach ($days as $day) {
				$imageTypes = array();
				foreach (glob(ALLSKY_IMAGES . "/$day/$dir$imageFileName-$day.*") as $imageType) {
					$imageTypes[] = $imageType;
					$num += 1;
					echo "<br>&nbsp;"; // to separate images
					foreach ($imageTypes as $imageType) {
						$imageType_name = basename($imageType);
						$fullFilename = "$images_dir/$day/$dir$imageType_name";
						if ($type == "picture") {
							echo "<a href='$fullFilename'>";
							echo "<div class='functionsListFileType'>";
							echo "<label>$day</label>";
							echo "<img src='$fullFilename' class='functionsListTypeImg' />";
							echo "</div></a>\n";
						} else {	// is video
							// TODO: Show a thumbnail since loading videos is bandwidth intensive.
							echo "<a href='$fullFilename'>";
							echo "<div class='functionsListFileType'>";
							echo "<label class='middleVerticalAlign'>$day &nbsp; &nbsp;</label>";
							echo "<video width='85%' height='85%' controls class='middleVerticalAlign'>";
							echo "<source src='$fullFilename' type='video/mp4'>";
							echo "Your browser does not support the video tag.";
							echo "</video>";
							echo "</div></a>\n";
						}
			  		}
				}
			}
			if ($num == 0) {
				echo "<span class='alert-warning'>There are no $formalImageTypeName.</span>";
			}
		}
	} else {
		foreach (glob(ALLSKY_IMAGES . "/$chosen_day/$dir$imageFileName-$chosen_day.*") as $imageType) {
			$imageTypes[] = $imageType;
			$num += 1;
		}
		if ($num == 0) {
			echo "<span class='alert-warning'>There are no $formalImageTypeName for this day.</span>";
		} else {
			foreach ($imageTypes as $imageType) {
				$imageType_name = basename($imageType);
				$fullFilename = "$images_dir/$chosen_day/$dir$imageType_name";
				if ($type == "picture") {
				    echo "<a href='$fullFilename'>
					<div class='left'>
					<img src='$fullFilename' style='max-width: 100%; max-height: 400px'/>
					</div></a>\n";
				} else {	//video
				    echo "<a href='$fullFilename'>";
				    echo "<div class='left' style='width: 100%'>
					<video width='85%' height='85%' controls>
						<source src='$fullFilename' type='video/mp4'>
						Your browser does not support the video tag.
					</video>
					</div></a>\n";
				}
			}
		}
	}
        echo "</div>";
}

// Run a command and display the appropriate status message.
// If $addMsg is false, then don't add our own message.
function runCommand($cmd, $onSuccessMessage, $messageColor, $addMsg=true, $onFailureMessage="")
{
	global $status;

	exec("$cmd 2>&1", $result, $return_val);
	$dq = '"';
	echo "<script>console.log(${dq}[$cmd] returned $return_val, result=" . implode(" ", $result) . "${dq});</script>";
	if ($return_val === 255) {
		// This is only a warning so only display the caller's message, if any.
		if ($result != null) $msg = implode("<br>", $result);
		else $msg = "";
		$status->addMessage($msg, "warning", false);
		return false;
	} elseif ($return_val > 0) {
		// Display a failure message, plus the caller's message, if any.
		if ($addMsg) {
			$msg = "'$cmd' failed";
			if ($result != null) $msg .= ":<br>" . implode("<br>", $result);
		} else {
			if ($result != null) $msg = implode("<br>", $result);
			else $msg = "";
		}
		// Display the caller's "on success" onSuccessMessage, if any.
		if ($onFailureMessage !== "")
			$status->addMessage($onFailureMessage, "danger", false);
		$status->addMessage($msg, "danger", false);
		return false;
	}

	// Display the caller's "on success" onSuccessMessage, if any.
	if ($onSuccessMessage !== "")
		$status->addMessage($onSuccessMessage, $messageColor, false);

	// Display any output from the command.
	if ($result != null) $status->addMessage(implode("<br>", $result), "message", false);

	return true;
}

// Update a file.
// Files should be writable by the web server, but if they aren't, use a temporary file.
// Return any error message.
function updateFile($file, $contents, $fileName, $toConsole) {
	if (@file_put_contents($file, $contents) == false) {
		$e = error_get_last()['message'];

		// $toConsole tells us whether or not to use console.log() or just echo.
		if ($toConsole) {
			$cl1 = '<script>console.log("';
			$cl2 = '");</script>';
		} else {
			$cl1 = "<br>";
			$cl2 = "";
		}
		echo "${cl1}Note: Unable to update $file 1st time: ${e}${cl2}\n";

		// Assumed it failed due to lack of permissions,
		// usually because the file isn't grouped to the web server group.
		// Set the permissions and try again.

		$cmd = "sudo touch '$file' && sudo chgrp " . WEBSERVER_GROUP . " '$file' &&";
		$cmd .= " sudo chmod g+w '$file'";
		$return = null;
		$ret = exec("( $cmd ) 2>&1", $return, $retval);
		if (gettype($return) === "array")
			$c = count($return);
		else
			$c = 0;
		if ($ret === false || $c > 0 || $retval !== 0) {
			$err = implode("\n", $return);
			return "Unable to update settings: $err";
		}

		if (@file_put_contents($file, $contents) == false) {
			$e = error_get_last()['message'];
			$err = "Failed to save settings: $e";
			echo "${cl1}Unable to update file for 2nd time: ${e}${cl2}";
			$x = str_replace("\n", "", shell_exec("ls -l '$file'"));
			echo "${cl1}ls -l returned: ${x}${cl2}";

			// Save a temporary copy of the file in a place the webserver can write to,
			// then use sudo to "cp" the file to the final place.
			// Use "cp" instead of "mv" because the destination file may be a hard link
			// and we need to keep the link.
			$tempFile = "/tmp/$fileName-temp.txt";

			if (@file_put_contents($tempFile, $contents) == false) {
				$err = "Failed to create temporary file: " . error_get_last()['message'];
				return $err;
			}

			$cmd = "x=\$(sudo cp '$tempFile' '$file' 2>&1) || echo 'Unable to copy [$tempFile] to [$file]': \${x}";
			$err = str_replace("\n", "", shell_exec($cmd));
			if ($err !== "") echo "${cl1}cp returned: [$err]${cl2}";
			return $err;
		}
	}
	return "";
}

// Return the settings file for the current camera.
function getSettingsFile() {
	return ALLSKY_CONFIG . "/settings.json";
}

// Return the options file for the current camera.
function getOptionsFile() {
	return ALLSKY_CONFIG . "/options.json";
}

// Return the full path name of the local Website configuration file.
function getLocalWebsiteConfigFile() {
	return ALLSKY_WEBSITE_LOCAL_CONFIG;
}

// Return the full path name of the remote Website configuration file.
function getRemoteWebsiteConfigFile() {
	return ALLSKY_WEBSITE_REMOTE_CONFIG;
}

// Return the file name after accounting for any ${} variables.
// Since there will often only be one file used by multiple settings,
// as an optimization save the last name.
$lastFileName = null;
function getFileName($file) {
	global $lastFileName;

	if ($lastFileName === $file) return $lastFileName;

	if (strpos('${HOME}', $file) !== false) {
		$lastFileName = str_replace('${HOME}', HOME, $file);
	} else if (strpos('${ALLSKY_ENV}', $file) !== false) {
		$lastFileName = str_replace('${ALLSKY_ENV}', ALLSKY_ENV, $file);
	} else if (strpos('${ALLSKY_HOME}', $file) !== false) {
		$lastFileName = str_replace('${ALLSKY_HOME}', ALLSKY_HOME, $lastFileName);
	}
	return $lastFileName;
}

// Check if the specified variable is in the specified array.
// If so, return it; if not, return default value;
// This is used to make the code easier to read.
function getVariableOrDefault($a, $v, $d) {
	if (isset($a[$v])) {
		$value = $a[$v];
		if (gettype($value) === "boolean") {
			if ($value || $value == "true") {
				return "true";
			} else {
				return "false";
			}
		}
		return $value;
	} else if (gettype($d) === "boolean" && $d == "") {
		return "false";
	} else if (gettype($d) === "null") {
		return null;
	}

	return($d);
}

function getTOD() {
	global $settings_array;

	//$settings_array = readSettingsFile();

	$angle = getVariableOrDefault($settings_array, 'angle', -6);
	$lat = getVariableOrDefault($settings_array, 'latitude', "");
	$lon = getVariableOrDefault($settings_array, 'longitude', "");
	$tod = 'Unknown';

	if ($lat != "" && $lon != "") {
		exec("sunwait poll exit set angle $angle $lat $lon", $return, $retval);
		if ($retval == 2) {
			$tod = 'day';
		} else if ($retval == 3) {
			$tod = 'night';
		}
	}
	
	return $tod;
}

function getDatefromDay($date) {
	$year = substr($date, 0, 4);
	$month = substr($date, 4, 2);
	$day = substr($date, 6, 2);

	$date = DateTime::createFromFormat('Y-m-d', "$year-$month-$day");
	return $date->format('D M d Y');
}

function getImageTime($filename) {
	$filename = basename($filename);
	$timeStamp = substr($filename, strrpos($filename, '-') + 1);
	
	// YYYY MM DD HH MM SS
	// 0123 45 67 89 01 23
	$year = substr($timeStamp, 0, 4);
	$month = substr($timeStamp, 4, 2);
	$day = substr($timeStamp, 6, 2);
	$hour = substr($timeStamp, 8, 2);
	$minute = substr($timeStamp, 10, 2);
	$second = substr($timeStamp, 12, 2);

	$date = DateTime::createFromFormat('Y-m-d H:i:s', "$year-$month-$day $hour:$minute:$second");
	return $date->format('D M d Y') . " @ " . $hour . ":" . $minute . ":" . $second;
}
?>
