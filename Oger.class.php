<?PHP
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/




/**
* Collection of utility methods.
*/
class Oger {


	/**
	* L10N.
	* Has to be implemented. For now only marks text for L10N.
	* @param $text  Text to be localized.
	* @return Localized string.
	*/
	public static function _($text) {
		return $text;
	}  // eo l10n



	/**
	* Check if array is associative.
	* An array is assiciative if it has non numeric keys.<BR>
	* <em>ATTENTION:</em> Associative arrays with only numeric keys are
	* treated as NOT associative!!!! This is a general problem
	* also in PHP internal-functions like array_merge, etc.
	* @param $array  Array to be checked.
	* @return True if it is an associative array. False otherwise.
	*/
	public static function isAssocArray($array) {
		if (!is_array($array)) {
			return false;
		}
		foreach ($array as $key => $value) {
			if (!is_numeric($key)) {
				return true;
			}
		}
		return false;
	}  // eo assoc check


/**
 * Merge two or more arrays and preserve numeric keys
 * DEPRECATED: only for backward compatibility with ogerArch
 * Use uni operator + for arrays to preserve values with the same key
 * of the first array and ignore later ones.
 * Use array_replace to overwrite values with the same key with last occurence
 */
public static function arrayMergeAssoc() {
	$result = array();
	$arrays = func_get_args();
	foreach($arrays as $array) {
		if ($array == null) {
			continue;
		}
		foreach($array as $key => $value) {
			$result[$key] = $value;
		}
	}
	return $result;
}  // eo array merge assoc


/**
* Evaluate an arithmetic expressoin from string.
* Only base arithmetic works because of security reasons.
* DEPRECATED: only for backward compatibility with ogerArch
*/
public static function evalMath($str) {
	$str = preg_replace('/[^0-9\. \+\-\*\/\(\)]+/', '', $str);
	return eval('return ' . $str . ';');
}





	/**
	* Restart session without warnings
	* Cookie based sessions give a warning if reopened after output.
	* Long running scripts need session_write_close() + session_start()
	* because in file based session storage the session file is locked and
	* every other requests within this session that opens the session has
	* to wait till the first script is finished.
  * see <https://stackoverflow.com/questions/12315225/reopening-a-session-in-php#12315542>
  * see also inital session start in init.inc.php
	*/
	public static function sessionRestart() {
    // the version for php7 should work for php5 too, but we let the working code untouched - maybe cleanup later
    if (PHP_MAJOR_VERSION < 7) {
      ini_set('session.use_only_cookies', false);
      ini_set('session.use_cookies', false);
  		ini_set('session.use_trans_sid', false);
  		ini_set('session.cache_limiter', null);
  		session_start();
    }
    else {  // php >= 7
      @session_start();
  		if (session_status() != PHP_SESSION_ACTIVE) {  // == PHP_SESSION_NONE ???
  			session_start();
  		}
    }  // eo php7
	}  // eo reopen session



	/**
	* Pad string (multibyte variant).
	* @param $str Debug message.
	* see: <http://php.net/manual/en/ref.mbstring.php>
	*/
	public static function mbStrPad($str, $len, $padStr = " ", $padStyle = STR_PAD_RIGHT, $encoding = "UTF-8") {
		return str_pad($str, strlen($str) - mb_strlen($str, $encoding) + $len, $padStr, $padStyle);
	}  // eo str pad




	/**
	* Report a debug message to the php error system.
	* @param $msg Debug message.
	*/
	public static function debug($msg) {
		trigger_error($msg, E_USER_WARNING);
	}  // eo debug to php error system


	/**
	* Report a debug message to a file.
	* @param $msg Debug message.
	* @param $fileName File to write to. Must be writable for calling user.
	*/
	public static function debugFile($msg, $fileName = "debug.localonly") {
		$type = gettype($msg);
		if (is_array($msg) || is_object($msg)) {
			$msg = var_export($msg, true);
		}
		$msg = "\n" . date("c") . " ({$type}):\n{$msg}";
		@file_put_contents($fileName, "{$msg}\n", FILE_APPEND);
	}  // eo debug to file



	/**
	* Create natural sort entry for an id
	* Expand every numeric part by prefixing with zeros to a fixed length.
	* Longer numeric parts are clipped.
	* Negative and positive sign and decimal chars are detected as NON-number chars
	* this is object of later improvement via opts
	*/
	public static function getNatSortId($id, $numlength = 10, $opts = array()) {

		// preprocess alpha parts
		$id = strtoupper($id);  // all uppercase
		// remove all spaces
		// maybe fold multiple spaces into one and
		// remove spaces in front of numbers would be enough?
		$id = str_replace(" ", "", $id);

		// handle numeric parts
		preg_match_all("/(\d+)/", $id, $matches);
		$parts = preg_split("/(\d+)/", $id);

		$natId = "";
		foreach ($matches[1] as $num) {
			$part = array_shift($parts);
			$natId .= $part . str_pad(substr($num, 0, $numlength), $numlength, "0", STR_PAD_LEFT);
		}
		$natId .= array_shift($parts);

		if ($opts['maxlength'] > 0) {
			$natId = substr($natId, 0, $opts['maxlength']);
		}

		return $natId;
	}  // eo natural sort


	/*
	 * Number format with german defaults
	 */
	public static function numberFormatDe($num, $dec = 0) {
		return number_format($num, $dec, ",", ".");
	}  // eo number format de



	/**
	 * Indents a flat JSON string to make it more human-readable.
	 * from: <http://recursive-design.com/blog/2008/03/11/format-json-with-php/>
	 * @param string $json The original JSON string to process.
	 * @return string Indented version of the original JSON string.
	 */
	public static function formatJson($json) {

		$result      = '';
		$pos         = 0;
		$strLen      = strlen($json);
		$indentStr   = '  ';
		$newLine     = "\n";
		$prevChar    = '';
		$outOfQuotes = true;

		for ($i=0; $i<=$strLen; $i++) {

			// Grab the next character in the string.
			$char = substr($json, $i, 1);

			// Are we inside a quoted string?
			if ($char == '"' && $prevChar != '\\') {
				$outOfQuotes = !$outOfQuotes;

			// If this character is the end of an element,
			// output a new line and indent the next line.
			} else if(($char == '}' || $char == ']') && $outOfQuotes) {
				$result .= $newLine;
				$pos --;
				for ($j=0; $j<$pos; $j++) {
					$result .= $indentStr;
				}
			}

			// Add the character to the result string.
			$result .= $char;

			// If the last character was the beginning of an element,
			// output a new line and indent the next line.
			if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
				$result .= $newLine;
				if ($char == '{' || $char == '[') {
					$pos ++;
				}

				for ($j = 0; $j < $pos; $j++) {
					$result .= $indentStr;
				}
			}

			$prevChar = $char;
		}

		return $result;
	}  // eo beautify json



	/*
	 * Format an array of rows into csv text file
	 */
	public static function rowArrayToCsv($arr, $delim = ";", $enc = '"', $nl = "\n", $escMode = "EXCEL") {

		$out = "";

		foreach ((array)$arr as $row) {
			$out .= static::arrayToCsvRow($row,  $delim = ";", $enc = '"', $nl = "\n", $escMode = "EXCEL");
		}

		return $out;
	}  // eo array of rows to csv


	/*
	 * Format an array into csv delimited row
	 */
	public static function arrayToCsvRow($arr, $delim = ";", $enc = '"', $nl = "\n", $escMode = "EXCEL") {

		$out = "";
		$tmpDelim = "";

		foreach ((array)$arr as $field) {

			switch ($escMode) {
			case "EXCEL":
				$field = str_replace($enc, "{$enc}{$enc}", $field);
				break;
			case "UNIX":
				$field = str_replace("\\", "\\\\", $field);   // escape the escape char too
				$field = str_replace($enc, "\{$enc}", $field);
				break;
			}

			$out .= "{$tmpDelim}{$enc}{$field}{$enc}";
			$tmpDelim = $delim;
		}  // eo fields

		return "{$out}{$nl}";
	}  // eo array to csv



	/*
	 * Check if the current connection has a valid ssl client certificate
	 */
	public static function connectionHasValidSslClientCert() {

		if ($_SERVER['SSL_CLIENT_VERIFY'] == 'SUCCESS'  // NONE, SUCCESS, GENEROUS or FAILED:reason
			|| isset($_SERVER['SSL_CLIENT_M_SERIAL'])  // The serial of the server certificate
			|| isset($_SERVER['SSL_CLIENT_V_END'])  // Validity of client's certificate (end time)
																							 // e.g: 'Mar 14 16:02:00 2016 GMT')
			|| isset($_SERVER['SSL_CLIENT_I_DN'])  // Issuer DN of client's certificate
				 // e.g: 'emailAddress=office@asinoe.at, CN=asinoe.at, O=asinoe.at, L=Krems, C=AT'
			|| isset($_SERVER['SSL_CLIENT_S_DN'])  // Issuer DN of client's certificate
				 // e.g: 'emailAddress=gerhard.oettl@asinoe.at, CN=gerhard.oettl@asinoe.at, OU=Leitung, O=asinoe.at, L=Krems, C=AT'
			|| $_SERVER['SSL_CLIENT_V_REMAIN'] > 0  // Number of days until client's certificate expires
		)	{
			return true;
		}

		return false;
	}  // eo check for valid ssl connection



}  // eo class

?>
