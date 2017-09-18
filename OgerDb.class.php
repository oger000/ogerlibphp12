<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Helperclass for database handling.
* This class contains convenience methods for SQL and PDO.
*/
class OgerDb {

	/// Enclose char at the begin.
	/// Enclose char at the begin of table and column names to encapsulate reserved words.
	/// Defaults to double quotes (") which is the ANSI SQL Standard .
	public static $encNamBegin = '"';

	/// Enclose char at the end.
	/// Enclose char at the end of table and column names to encapsulate reserved words.
	/// Defaults to double quotes (") which is the ANSI SQL Standard .
	public static $encNamEnd = '"';


	/// Connection resource.
	/// Mainly intended for setting and using in inheriting classes.
	public static $conn;

	/// Debug flag.
	//public static $debug = true;
	public static $debug = false;



	/**
	* Enclose a name with database dependend encape chars.
	* @param $name Name to enclose.
	* @return Enclosed name.
	*/
	public static function encName($name) {
		return static::$encNamBegin . $name . static::$encNamEnd;
	}


	/**
	* Create a SQL string for insert or update for pepared statement.
	* @param $action Sql action (INSERT or UPDATE).
	* @param $tableName  Table name.
	* @param $fields Array with field names or associative array with fieldname => value pairs.
	* @param string|array $where  Optional SQL WHERE clause.
	*                String or array without the WHERE keyword. An array is passed to whereStmt().
	* @return String with created sql command.
	*/
	public static function getStoreStmt($action, $tableName, $fields, $where = "", $moreStmt = "") {

		if (Oger::isAssocArray($fields)) {
			$fields = array_keys($fields);
		}

		$action = strtoupper($action);
		switch ($action) {
		case "INSERT":
			foreach ($fields as $field) {
				$fieldStmt .= ($fieldStmt ? "," : '') . static::encName($field);
				$valueStmt .= ($valueStmt ? "," : '') . ":$field";
			}
			$stmt .= "INSERT INTO " . static::encName($tableName) . " ($fieldStmt) VALUES ($valueStmt)";
			break;
		case "UPDATE":
			foreach ($fields as $field) {
				$stmtSet .= ($stmtSet ? "," : '') . static::encName($field) . "=:$field";
			}
			$stmt .= "UPDATE " . static::encName($tableName) . " SET $stmtSet";
			// where values only needed on update
			$stmt .= " " . static::whereStmt($where);
			break;
		default:
			throw new Exception("Unknown database store action: $action.");
		}

		if ($moreStmt) {
			$stmt .= " $moreStmt";
		}

		return $stmt;
	} // end of create statement



	/**
	* Check if parameters fit for given statement.
	* Only used for debugging to get more helpful error messages for PDO::exec errors.
	* Throws an exception if an error occurs and does nothing otherwise.
	* @param $sql   SQL statement.
	* @param $values Assiziative array with key value pairs.
	*/
	public static function checkStmtParams($sql, $values) {

		// extract keys and remove leading ":" (if any) from keys
		$valKeys = array();
		foreach ((array)$values as $key => $value) {
			if (substr($key, 0, 1) == ":") {
				$key = substr($key, 1);
			}
			$valKeys[$key] = $value;
		}

		// check for required values
		$tmpMsg = "";
		$delim = "";
		$params = static::getStmtParamNames($sql);
		foreach ($params as $param) {
			if (!array_key_exists($param, $valKeys)) {
				$tmpMsg .= "$delim$param";
				$delim = ", ";
			}
		}
		if ($tmpMsg) {
			$msg .= "No value for param: $tmpMsg. ";
		}

		// check for required params
		$tmpMsg = "";
		$delim = "";
		$params = array_flip($params);
		foreach ((array)$valKeys as $key => $value) {
			if (!array_key_exists($key, $params)) {
				$tmpMsg .= "$delim$key";
				$delim = ", ";
			}
		}
		if ($tmpMsg) {
			$msg .= "No param for value: $tmpMsg.";
		}

		// if errormessage than return or throw exception
		if ($msg) {
			$msg = "$msg Sql: $sql";
			throw new Exception($msg);
		}

	}  // eo check statement params



	/**
	* Get parameters from SQL statement.
	* Get all named parameters from a SQL statement string that is designed for prepared statement usage.
	* @param $stmt  SQL statment.
	* @return An array with all named parameters (placeholders) for a sql statement.
	*/
	public static function getStmtParamNames($stmt) {
		preg_match_all("/[^a-z0-9_]:([a-z_][a-z0-9_]*)/i", $stmt, $matches);
		return $matches[1];
	}  // eo get stmt param names



	/**
	* Creates a simple SQL WHERE clause designed for a prepared statement.
	* For parameters @see wherePart().
	* @return String with WHERE clause with the leading WHERE keyword.
	*/
	public static function whereStmt($params, $glueOp = "AND") {
		$where = static::wherePart($params, $glueOp);
		$chkWhere = trim($where);
		if ($chkWhere && strtoupper(substr($chkWhere, 0, 5) != "WHERE")) {
			$where = "WHERE $where";
		}
		return " " . $where;
	}

	/**
	* Creates a part for the SQL WHERE clause designed for a prepared statement.
	* The WHERE clause containing placeholder for named parameters.<BR>
	* Remark on prepared statements (PHP 5.3):
	* Looks like repared statements are always filled in as strings, even
	* if they are forced to PHP numbers (for example multiplied with 1).
	* So whe have to explicitly cast them in the WHERE statement if
	* we need numbers.
	* @param $params  An array with the parameter names for the SQL WHERE clause of a SQL statement.
	*                 If an assoziative array is given then the keys are used as parameter names.
	*                 For every parameter a "parameterName=:parameterName" stanza is created.
	*                 Currently only the "=" comperator is used.
	*                 If params is a string it will be returned unchanged.
	* @param string $glueOp  Optional logical operator that glues together the fields.
	*                 Defaults to "AND".
	* @return String with WHERE clause, but without the leading WHERE keyword.
	*/
	public static function wherePart($params, $glueOp = "AND") {

		if (!is_array($params)) {
			return $params;
		}

		$stmt = '';

		// if not an associative array, then make it
		if (!Oger::isAssocArray($params)) {
			$tmp = array();
			foreach ($params as $paramName) {
				$tmp[$paramName] = $paramName;
			}
			$params = $tmp;
		}

		// create where clause
		foreach ($params as $colName => $val) {
			$stmt .= ($stmt ? " $glueOp " : "") . static::encName($colName) . "=:";
			// if the value is another array, then the key contains the real parameter name
			if (is_array($val)) {
				reset($val);
				$stmt .= key($val);
			}
			else {
				$stmt .= "$colName";
			}
		}

		return $stmt;
	} // end of create where



	/**
	* Check if values match statement placeholders and prepare sql.
	*/
	public static function checkedPrepare($sql, $values = array()) {
		// if sql is already a prepared statemend, then we return it directly
		if (is_a($sql, "PDOStatement")) {
			return $sql;
		}
		static::checkStmtParams($sql, $values);
		return static::$conn->prepare($sql);
	}  // eo checked prepare


	/**
	* Check if values match statement placeholders. Prepare and execute sql.
	*/
	public static function checkedExecute($sql, $values = array()) {
		$pstmt = static::checkedPrepare($sql, $values);
		$pstmt->execute($values);
		return $pstmt;
	}  // eo checked execute




	/**
	* Get value of first column of first row.
	*/
	public static function fetchValue1($sql, $seleVals = array()) {

		$pstmt = static::checkedExecute($sql, $seleVals);
		$value1 = $pstmt->fetchColumn();
		$pstmt->closeCursor();

		return $value1;
	}  // eo get value 1


	/**
	* Get first row.
	*/
	public static function fetchRow1($sql, $seleVals = array()) {

		$pstmt = static::checkedExecute($sql, $seleVals);
		$row1 = $pstmt->fetch(PDO::FETCH_ASSOC);
		$pstmt->closeCursor();

		return $row1;
	}  // eo get value 1



	/**
	* Check for valid characters in column names (driver specific).
	*/
	public static function columnCharsValid($colName) {
		if (preg_match("/^([a-z_][a-z0-9_]*)$/i", $colName)) {
			return true;
		}
		return false;
	}  // eo valid column chars



	/**
	* Get mysql error message from exception
	*/
	public static function getMyErrorMsg($ex) {

		$oriMsg = $ex->getMessage();
		$errMsg = $oriMsg;

		switch ($ex->errorInfo[1]) {
		case 1062:
			$errMsg = sprintf(Oger::_("Unerlaubtes Duplikat: %s"), $oriMsg);
			break;
		case 1451:
			$errMsg = sprintf(Oger::_("Fehler durch verknüpfte Datensätze: %s"), $oriMsg);
			break;
		}  // eo switch

		return $errMsg;
	}  // eo mysql error msg




}  // eo class





?>
