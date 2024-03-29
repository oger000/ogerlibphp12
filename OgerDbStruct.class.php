<?php
/*
#LICENSE BEGIN
**********************************************************************
* OgerArch - Archaeological Database is released under the GNU General Public License (GPL) <http://www.gnu.org/licenses>
* Copyright (C) Gerhard Öttl <gerhard.oettl@ogersoft.at>
**********************************************************************
#LICENSE END
*/

// TODO cleanup public interface
// TODO TEST TEST TEST

/**
* Handle database structure.
* Supported database systems are: Only MySql by now.<br>
* No renaming is provided by design.<br>
* For all option arrays there are additional options possible in the
* driver dependent implementation - so see there too.

*/
abstract class OgerDbStruct {

	const LOG_NONE = 0;
	const LOG_CMD = 1;
	const LOG_LOG = 2;
	const LOG_DEBUG = 5;
	const LOG_NOTICE = 7;
	const LOG_ULTRADEBUG = 99;

	protected $conn;  ///< PDO instance created elsewhere.
	protected $dbName;  ///< Database name.
	protected $driverName;  ///< Driver name.
	protected $log;  ///< Log messages buffer.

	protected $params = array();

	protected $quoteNamBegin = '"';
	protected $quoteNamEnd = '"';

	public $changeCount = 0;

	/**
	 * Construct with a PDO instance and database name.
	 * @param $conn  A PDO instance that represents a valid database connection.
	 * @param $dbName  Database name - because this cannot be detected from the PDO connection.
	 */
	public function __construct($conn, $dbName) {
		$this->conn = $conn;
		$this->dbName = $dbName;
		$this->driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
	}  // eo constructor


	/**
	 * Get driver dependend instance.
	 * For params see @see __construct().
	 * @throw Throws an exception if the driver for given PDO object is not supported.
	 */
	static function getInstance($conn, $dbName) {

		// check for supported driver
		$driverName = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
		switch ($driverName) {
		case "mysql":
			$className = "OgerDbStruct" . ucfirst($driverName);
			$dwc = new $className($conn, $dbName);
			return ($dwc);
			break;
		default:
			throw new Exception("PDO driver {$this->driverName} not supported.");
		}
	}  // eo construct


	/**
	* Get the current database structure.
	* @return Array with database structure.
	*/
	abstract public function getDbStruct();


	/**
	* Get new head info for struct array.
	* @return Header for struct array.
	*/
	public function getNewStructHead() {

		// preapre db struct array
		$startTime = time();

		$struct = array();
		$struct['DBSTRUCT_META'] = array(
			"DRIVER_NAME" => $this->driverName,
			"SERIAL" => $startTime,
			"TIME" => date("c", $startTime),
		);

		$struct['SCHEMA_META'] = array();
		$struct['TABLES'] = array();

		return $struct;
	}  // eo create struct head


	/**
	* Add missing tables, columns, indices or foreign keys to the database.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function addDbStruct($refDbStruct = null);


	/**
	* Refresh existing tables, columns, indices and foreign keys.
	* Do not add missing ones.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function refreshDbStruct($refDbStruct = null);


	/**
	* Update existing tables, columns, indices or foreign keys and add missing one.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function updateDbStruct($refDbStruct = null);


	/**
	* Reorder database structure.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function reorderDbStruct($refDbStruct = null);


	/**
	* Cleanup surpluss tables, columns, indices and foreign keys.
	* Despite the first impression not the given database struct is cleaned up
	* but everything that is above is removed.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function cleanupDbStruct($refDbStruct = null);


	/**
	* Force database structure.
	* Forces the given database structure by adding, refreshing, reordering and deleting divergent structure.
	* @param $refDbStruct Array with the reference database structure.
	*/
	abstract public function forceDbStruct($refDbStruct = null);




	/**
	* Format the database struct array into a string.
	* This should result in a more diff friendly output.
	* @param $dbStruct Array with the database structure.
	*/
	public function formatDbStruct($dbStruct) {
		// dummy implementation
		return var_export($dbStruct, true);
	}  // eo format db struct













	// ############################################
	// some helper methods and setter/getter
	// ############################################


	/**
	* Set parameter.
	* @param $name Parameter name.
	* Valid parameter names are: dry-run, log-level.
	* @param $value New parameter value.
	* @return Old parameter value.
	*/
	public function setParam($name, $value) {
		$ret = $this->getParam($name);
		$this->params[$name] = $value;
		return $ret;
	}  // eo set param

	/**
	* Set multiple parameters at once.
	* @params Associative array with key value pairs.
	* @return Old parameter values.
	*/
	public function setParams($values) {
		$ret = array();
		foreach ($values as $key => $value) {
			$ret[$key] = $this->setParam($key, $value);
		}
		return $ret;
	}  // eo set param

	/**
	* Get parameter.
	* @param $name Parameter name.
	* @return Old parameter value.
	*/
	public function getParam($name) {
		return $this->params[$name];
	}  // eo get param

	/**
	* Get all parameters.
	* @return All parameter names and values.
	*/
	public function getParams() {
		return $this->params;
	}  // eo get params


	/**
	* Add text to the log buffer if log level fits.
	* @param $msgLogLevel Log level for this log message.
	* @param $text Text added to the log buffer.
	*/
	public function log($msgLogLevel, $text) {
		if ($msgLogLevel <= $this->getParam("log-level")) {
			// if text starts with sql comment then populate this to every line
			// of this log text
			if (substr($text, 0, 3) == "-- ") {
				$finalNl = false;
				if (substr($text, -1) == "\n") {
					$finalNl = true;
					$text = substr($text, 0, -1);
				}
				$lines = explode("\n", $text);
				$text = "";
				foreach ($lines as &$line) {
					if (trim($line) && substr($line, 0, 3) != "-- ") {
						$line = "-- $line";
					}
				}
				$text = implode("\n", $lines);
				if ($finalNl) {
					$text .= "\n";
				}
			}
			if ($this->getParam("dry-run")) {
				$text = preg_replace("/^/ms", "-- dry-run: ", $text);
			}
			if ($this->getParam("echo-log")) {
				echo $text;
			}
			$this->addLog($text);
		}
	}  // eo add text for log level

	/**
	* Add text to log buffer.
	* @param $text Text added to the log buffer.
	*/
	public function addLog($text) {
		$this->log .= $text;
	}  // eo add log

	/**
	* Get log text.
	* @return Log content.
	*/
	public function getLog() {
		return $this->log;
	}  // eo get log

	/**
	* Flush log buffer.
	* @return Log content.
	*/
	public function flushLog() {
		$ret = $this->log;
		$this->log = "";
		return $ret;
	}  // eo flush log




	/**
	* Quote a table or column name.
	* @param $name Name to be quoted.
	*/
	public function quoteName($name) {
		return "{$this->quoteNamBegin}$name{$this->quoteNamEnd}";
	}  // eo quote name


	/**
	* Prepares and executes an sql statement.
	* Respects dry-run and logging parameters.
	* @param $stmt SQL statement.
	* @param $values Associative array with parameter => value pairs.
	*/
	public function execChange($stmt, $values = array()) {
		$stmts = explode(";", $stmt);
		foreach ($stmts as $stmt) {
			if ($stmt) {
				if (!$this->getParam("dry-run")) {
					$this->log(static::LOG_CMD, "-- " . date("c") . " Begin change:\n");
				}
				$this->log(static::LOG_CMD, "$stmt;\n");
				$this->changeCount++;
				if (!$this->getParam("dry-run")) {
					try {
						$pstmt = $this->conn->prepare($stmt);
						$pstmt->execute();
						$this->log(static::LOG_CMD, "-- " . date("c") . " End change.\n");
					}
					catch (Exception $ex) {
						throw new Exception($ex->getMessage() . ": $stmt;");
					}
				}
			}
		}
	}  // eo execute stmt


	// eo helper methods
	// ###############################

}  // eo class

?>
