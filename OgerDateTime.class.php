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
		return $interval->days;
	}  // eo day diff



}  // eo class

?>
