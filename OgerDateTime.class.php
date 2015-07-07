<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/




/**
* Date time extension
*/
class OgerDateTime extends DateTime {

	public static $defaultDateFormat = "d.m.Y";
	public static $defaultTimeFormat = "H:i";
	public static $defaultDateTimeFormat = "d.m.Y H:i";


	/**
	* Constructor.
	* Additional formats:
	* - numeric-only $time parameter is handled as unix timestamp.
	*/
	public function __construct ($time = "now", $timezone = null) {

		if (is_numeric($time)) {
			$time = "@{$time}";
		}

		parent::__construct($time, $timezone);
	}  // eo constructor


	/**
	 * Format date part with default setting.
	*/
	public function formatDate($format = "") {
		$format = ($format ?: static::$defaultDateFormat);
		return parent::format($format);
	}  // eo format date


	/**
	 * Format time part with default setting.
	*/
	public function formatTime($format = "") {
		$format = ($format ?: static::$defaultTimeFormat);
		return parent::format($format);
	}  // eo format time


	/**
	 * Format date and time with default setting.
	*/
	public function format($format = "") {
		$format = ($format ?: static::$defaultDateTimeFormat);
		return parent::format($format);
	}  // eo format date and time



	/**
	 * Format date part into ansi format.
	*/
	public function formatAnsiDate() {
		return parent::format("Y-m-d");
	}  // eo format ansi date



	/**
	 * Check if time is empty
	*/
	public static function _isEmpty($timeStr, $opts = array()) {

		$timeStr = trim($timeStr);

		if (!$timeStr) {
			return true;
		}

		// check sql date (maybe can be improved ???)
		if (substr($timeStr, 0, 10) == "0000-00-00" && !$opts['allowZeroYear']) {
			return true;
		}

		// invalid date is reported empty
		// numeric values are handled as already parsed with strtotime
		if (!is_numeric($timeStr) && strtotime($timeStr) === false) {
			return true;
		}

		return false;
	}  // eo is empty



	/**
	 * Date difference in full days (no fractals).
	*/
	public function diffDays($dateTime, $absolute = false) {
		$interval = $this->diff($dateTime, $absolute);
		$days = $interval->days;
		if (!$absolute && $interval->invert) {
			$days *= -1;
		}
		return $days;
	}  // eo day diff


	/*
	 * Get diff with extended parameter handling
	 * Date1 and date2 can be string or DateTime objects.
	 * Date1 before date2 - otherwise the interval is negative.
	 * Empty dates are replaced by NOW.
	 * Invalid dates result in null.
	 * Return DateInterval object.
	 */
	public static function _xDiff($date1 = null, $date2 = null) {

		if (static::_isEmpty($date1)) {
			$date1 = "NOW";
		}

		if (is_string($date1)) {
			$date1 = new DateTime($date1);
			if (!$date1) {  // invalid date
				return null;
			}
		}

		if (static::_isEmpty($date2)) {
			$date2 = "NOW";
		}

		if (is_string($date2)) {
			$date2 = new DateTime($date2);
			if (!$date2) {  // invalid date
				return null;
			}
		}

		return $date1->diff($date2);
	}  // eo get x-diff



	/*
	 * Static function for formated date output
	 * Date can be string, a unix timestamp or DateTime object.
	 */
	public static function _formatDate($dateIn, $format = null) {

		if (!$dateIn) {
			return "";
		}

		$format = ($format ?: static::$defaultDateFormat);

		if (is_string($dateIn)) {
			if (OgerDateTime::_isEmpty($dateIn)) {
				return "";
			}
			$date = new DateTime($dateIn);
			if (!$date) {  // invalid date
				return "";
			}
		}
		elseif (is_numeric($dateIn)) {
			$date = new DateTime();
			$date.setTimestamp($dateIn);
		}
		else {
			$date = $dateIn;
		}

		return $date->format($format);
	}  // eo static date format




}  // eo class

?>
