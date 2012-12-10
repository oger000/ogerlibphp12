<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Helperclass for database handling.
* This class contains convenience methods for SQL and PDO.
* <BR>DEPENDS ON:
* <UL>
*  <LI>class Oger
* </UL>
*/
class OgerDb {

  const SQL_INSERT = 'INSERT';  ///< INSERT marker for sql statement.
  const SQL_UPDATE = 'UPDATE';  ///< UPDATE marker for sql statement.

  /// Escape char at the begin.
  /// Escape char at the begin of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  static $escNamBegin = '"';
  /// Escape char at the end.
  /// Escape char at the end of table and column names to encapsulate reserved words.
  /// Defaults to double quotes (") which is the ANSI SQL Standard .
  static $escNamEnd = '"';


  /**
  * Create statement string for insert or update.
  * @param $action Sql action (INSERT or UPDATE).
  * @param $table  Table name.
  * @param $fields Array with field names.
  * @param string|array $where  Optional SQL WHERE clause.
  *                String or array without the WHERE keyword. An array is passed to static::createWhereStmt().
  * @return String with created sql command.
  */
  public static function createStmt($action, $table, $fields, $where = "") {

    switch ($action) {
    case self::ACTION_INSERT:
      foreach ($fields as $field) {
        $fieldStmt .= ($fieldStmt ? "," : '') . "{static::$escNamBegin}$field{static::$escNamEnd}";
        $valueStmt .= ($valueStmt ? "," : '') . ":$field";
      }
      $stmt .= "INSERT INTO {static::$escNamBegin}$table{static::$escNamEnd} ($fieldStmt) VALUES ($valueStmt)";
      break;
    case self::ACTION_UPDATE:
      foreach ($fields as $field) {
        $stmtSet .= ($stmtSet ? "," : '') . "{static::$escNamBegin}$field{static::$escNamEnd}=:$field";
      }
      $stmt .= "UPDATE {static::$escNamBegin}$table{static::$escNamEnd} SET $stmtSet";
      break;
    default:
      throw new Exception("Unknown " . __CLASS__ . "::action: $action.");
    }

    if (is_array($where)) {
      $where = static::createWhereStmt($where);
    }
    if ($where) {
      $stmt .= " WHERE $where";
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

    // check for too much params
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
    $stmtParams =

    return $matches[1];
  }  // eo get stmt param names



  /**
  * Creates a SQL WHERE clause designed for a prepared statement.
  * The WHERE clause containing placeholder for named parameters.
  * @param $params  An array with the parameter names for the SQL WHERE clause of a SQL statement.
  *                 If an assoziative array is given then the keys are used as parameter names.
  *                 For every parameter a "parameterName=:parameterName" stanza is created.
  *                 Currently only the "=" comperator is used.
  * @param string $glueOp  Optional logical operator that glues together the fields.
  *                 Defaults to "AND".
  * @return String with WHERE clause without the WHERE keyword.
  */
  /*
  * Memo on prepared statements (php 5.3):
  * Looks like repared statements are always filled in as strings, even
  * if they are forced to numbers (for example multiplied with 1).
  * So whe have to explicitly cast them in the where statement if
  * we need numbers.
  */
  public static function createWhereStmt($params, $glueOp = "AND") {

    $stmt = '';

    // try to detect associative arrays and use array_keys instead
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
        $glueOpTmp = ($value['glueOp'] :? $glueOp);
        $fieldName = ($value['field'] :? $fieldName);
        $fieldCast = ($value['fieldCast'] :? $fieldCast);
        $compOp = ($value['compOp'] :? $compOp);
        $valueCast = ($value['valueCast'] :? $valueCast);
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



}  // eo class

?>
