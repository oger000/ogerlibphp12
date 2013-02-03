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

  /// Escape char at the begin.
  /// Escape char at the begin of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  static $escNamBegin = '"';

  /// Escape char at the end.
  /// Escape char at the end of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  static $escNamEnd = '"';

  /// The PDO connection.
  /// It is the responsibility of the class user to provide a valid PDO connection
  /// before using it.
  static $conn;


  /**
  * Create a SQL string for insert or update for pepared statement.
  * @param $action Sql action (INSERT or UPDATE).
  * @param $tableName  Table name.
  * @param $fields Array with field names or associative array with fieldname => value pairs.
  * @param string|array $where  Optional SQL WHERE clause.
  *                String or array without the WHERE keyword. An array is passed to whereStmt().
  * @return String with created sql command.
  */
  public static function makeStmt($action, $tableName, $fields, $where = array(), $moreStmt = "") {

    if (Oger::isAssocArray($fields)) {
      $fields = array_keys($fields);
    }

    switch ($action) {
    case "INSERT":
      foreach ($fields as $field) {
        $fieldStmt .= ($fieldStmt ? "," : '') . "{static::$escNamBegin}$field{static::$escNamEnd}";
        $valueStmt .= ($valueStmt ? "," : '') . ":$field";
      }
      $stmt .= "INSERT INTO {static::$escNamBegin}$tableName{static::$escNamEnd} ($fieldStmt) VALUES ($valueStmt)";
      break;
    case "UPDATE":
      foreach ($fields as $field) {
        $stmtSet .= ($stmtSet ? "," : '') . "{static::$escNamBegin}$field{static::$escNamEnd}=:$field";
      }
      $stmt .= "UPDATE {static::$escNamBegin}$tableName{static::$escNamEnd} SET $stmtSet";
      break;
    default:
      throw new Exception("Unknown " . __CLASS__ . "::action: $action.");
    }

    $stmt .= static::whereStmt($where);

    if ($moreStmt) {
      $stmt .= " $moreStmt";
    }

    return $stmt;
  } // end of create statement


  /**
  * Create a prepared statement from given parameters.
  * For parameters @see makeStmt().
  * @deprecated Do not use.
  * @return The prepared statement.
  */
  public static function prepXStmt($action, $tableName, $fields, $where = array(), $moreStmt = "") {
    $sql = static::makeStmt($action, $tableName, $fields, $where, $moreStmt);
    $pstmt = static::$conn->prepare($sql);
    return $pstmt;
  }  // eo mke and prepare statement


  /**
  * Execute a prepared statement from given parameters.
  * For parameters @see makeStmt().
  * @deprecated Do not use.
  * @param $fields  Has to be an associative array of field name => value pairs and
  *                 must also include the keys and values for the WHERE clause.
  * @return The prepared statement.
  */
  public static function execXStmt($action, $tableName, $fields, $where = array(), $moreStmt = "") {
    $pstmt = static::prepXStmt($action, $tableName, $fields, $where, $moreStmt);
    $pstmt->execute($fields);
    return $pstmt;
  }  // oe make, prepare and execute statement


  /**
  * Execute a prepared statement from given parameters and fetch first entry.
  * Close the prepared statement after fetching.
  * For parameters @see execXStmt().
  * @deprecated Do not use.
  * @return The result.
  */
  public static function fetchXStmt($action, $tableName, $fields, $where = array(), $moreStmt = "") {
    $pstmt = static::execXStmt($action, $tableName, $fields, $where, $moreStmt);
    $pstmt->execute($fields);
    $row = $pstmt->fetch(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    return $row;
  }  // oe make, prepare, execute, fetch


  /**
  * Prepared statement with optional where parameter.
  * @return The prepared statement.
  */
  public static function prepSelect($stmt, $where = array(), $moreStmt = "") {
    $stmt .= static::whereStmt($where);
    if ($moreStmt) {
      $stmt .= " $moreStmt";
    }
    $pstmt = static::$conn->prepare($sql);
    return $pstmt;
  }  // eo mke and prepare statement


  /**
  * Execute a statement with optional parameters.
  * For parameters @see prepSelect().
  * @return The prepared statement.
  */
  public static function execSelect($stmt, $where = array(), $moreStmt = "") {
    $pstmt = static::prepSelect($stmt, $where, $moreStmt);
    $pstmt->execute($where);
    return $pstmt;
  }  // oe make, prepare and execute statement


  /**
  * Execute a statement with optional parameters and fetch first entry.
  * Close the prepared statement after fetching.
  * For parameters @see prepSelect().
  * @return The first result entry.
  */
  public static function fetchSelect($stmt, $where = array(), $moreStmt = "") {
    $pstmt = static::execSelect($stmt, $where, $moreStmt);
    $pstmt->execute($where);
    $row = $pstmt->fetch(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();
    return $row;
  }  // oe make, prepare, execute, fetch


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
  * Creates a SQL WHERE clause designed for a prepared statement.
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

    // if not an associative array use values as keys (parameter name)
    if (!Oger::isAssocArray($params)) {
      $params = array_flip($params);
    }

    // create where clause
    foreach ($params as $paramName => $value) {

      $glueOpTmp = $glueOp;
      $fieldName = $value;
      $fieldCast = "";
      $compOp = "=";
      $valueCast = "";

      // evaluate extended syntax (NOT DOCUMENTED !!!)
      if (is_array($value)) {
        $glueOpTmp = ($value['glueOp'] ?: $glueOp);
        $fieldName = ($value['field'] ?: $fieldName);
        $fieldCast = ($value['fieldCast'] ?: $fieldCast);
        $compOp = ($value['compOp'] ?: $compOp);
        $valueCast = ($value['valueCast'] ?: $valueCast);
      }

      $fieldName = "{static::$escNamBegin}$fieldName{static::$escNamEnd}";
      if ($fieldCast) {
        $fieldName = "CAST($fieldName AS $fieldCast)";
      }

      $paramName = ":$paramName";
      if ($valueCast) {
        $paramName = "CAST($paramName AS $valueCast)";
      }

      $stmt .= ($stmt ? " $glueOpTmp " : "") . " $compOp $paramName";
    }

    return $stmt;
  } // end of create where


  /**
  * Clean up an array with WHERE parameters for usage in execute().
  * An array with WHERE parameters can contain additional information
  * beside the value. So we have to cleanup before being used in execute().
  * @param $params  An assoziative array with parameter names as key
  *        and WHERE information as value.
  * @return Array with WHERE information reduced to the plain value.
  */
  public static function getCleanWhereVals($values) {

    $whereVals = array();

    // the value can contain additional information that is cleared now
    foreach ((array)$values as $key => $value) {
      if (is_array($value)) {
        $value = $value['value'];
      }
      $whereVals[$key] = $value;
    }

    return $whereVals;
  } // end of clean where vals


}  // eo class

?>
