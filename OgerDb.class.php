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
  static $encNamBegin = '"';

  /// Enclose char at the end.
  /// Enclose char at the end of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  static $encNamEnd = '"';



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
  public static function getStoreStmt($action, $tableName, $fields, $where = array(), $moreStmt = "") {

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
      $stmt .= "INSERT INTO " . static::$encName($tableName) . " ($fieldStmt) VALUES ($valueStmt)";
      break;
    case "UPDATE":
      foreach ($fields as $field) {
        $stmtSet .= ($stmtSet ? "," : '') . static::$encName($field) . "=:$field";
      }
      $stmt .= "UPDATE " . static::$encName($tableName) . " SET $stmtSet";
      // only update needs where values
      $stmt .= static::whereStmt($where);
      break;
    default:
      throw new Exception("Unknown " . __CLASS__ . "::action: $action.");
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
  * @param $stmt   SQL statement.
  * @param $params Assiziative array with key value pairs.
  */
  public static function checkStmtParams($stmt, $params) {

    // extract keys and remove leading ":" (if any) from params
    $execParams = array();
    foreach ($params as $key => $value) {
      if (substr($key, 0, 1) == ":") {
        $key = substr($key, 1);
      }
      $execParams[$key] = $key;
    }

    // check for required params
    $stmtParams = static::getStmtParamNames($stmt);
    foreach ($stmtParams as $stmtParam) {
      if (!array_key_exists($stmtParam, $execParams)) {
        $msg .= "Required parameter $stmtParam in statement not found in execute parameters. \n";
      }
    }

    // check for surplus params
    $stmtParams = array_flip($stmtParams);
    foreach ($execParams as $execParam) {
      if (!array_key_exists($execParam, $stmtParams)) {
        $msg .= "Execute parameter :$execParam not found in statement. \n";
      }
    }

    // if errormessage than return or throw exception
    if ($msg) {
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

    preg_match_all('/:(\w+)/', $stmt, $matches);
    return $matches[1];
  }  // eo get stmt param names



  /**
  * Creates a simple SQL WHERE clause designed for a prepared statement.
  * For parameters @see wherePart().
  * @return String with WHERE clause with the leading WHERE keyword.
  */
  public static function whereStmt($params, $glueOp = "AND") {
    $where = static::wherePart($params, $glueOp);
    if (trim($where)) {
      $where = " WHERE $where";
    }
    return $where;
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
  * @param string $glueOp  Optional logical operator that glues together the fields.
  *                 Defaults to "AND".
  * @return String with WHERE clause, but without the leading WHERE keyword.
  */
  public static function wherePart($params, $glueOp = "AND") {

    if (!is_array($params)) {
      return $params;
    }

    $stmt = '';

    // if  an associative array use keys as parameter name
    if (Oger::isAssocArray($params)) {
      $params = array_keys($params);
    }

    // create where clause
    foreach ($params as $paramName) {
      $stmt .= ($stmt ? " $glueOp " : "") . static::encName(paramName) . "=:$paramName";
    }

    return $stmt;
  } // end of create where



}  // eo class





?>
