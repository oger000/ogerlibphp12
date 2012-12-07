<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Handle db related things
*/
class Db {

  private static $conn;

  private static $dbName;
  private static $dbUser;
  private static $dbPass;
  private static $dbAttrib;

  const ACTION_INSERT = 'INSERT';
  const ACTION_UPDATE = 'UPDATE';


  /**
  * Init db class.
  */
  public static function init($dbName, $dbUser, $dbPass, $dbAttrib) {
    self::$dbName = $dbName;
    self::$dbUser = $dbUser;
    self::$dbPass = $dbPass;
    self::$dbAttrib = $dbAttrib;
    self::$conn = null;
  }  // eo init


  /**
  * Get connection
  */
  public static function getConn() {
    if (self::$conn === null) {
      self::$conn = new PDO(self::$dbName, self::$dbUser, self::$dbPass);
      if (self::$dbAttrib['errorMode']) {
        self::$conn->setAttribute(PDO::ATTR_ERRMODE, self::$dbAttrib['errorMode']);
      }
      // TODO maybe the following settings are mysql specific?
      if (self::$dbAttrib['connectionCharset']) {
        self::$conn->exec('SET CHARACTER SET ' . self::$dbAttrib['connectionCharset']);
      }
      if (self::$dbAttrib['connectionTimeZone']) {
        self::$conn->exec('SET time_zone = ' . self::$dbAttrib['connectionTimeZone']);
      }
   }
    return self::$conn;
  }  // eo get connection



  /**
  * Prepare statement with optional where part and more
  */
  public static function prepare($stmt, $where = null, $moreStmt = null) {
    if (is_string($where)) {
      $stmt .= " WHERE $where";
    }
    elseif (is_array($where)) {
      $stmt .= self::createWhereStmt($where, null, true);
    }

    if ($moreStmt) {
      $stmt .= " $moreStmt";
    }

    self::getConn();
    return self::$conn->prepare($stmt);

  }  // eo prepare



  /**
  * Check parameters for statement.
  * Only used for debugging, because the error messages of the pdo-drivers are very sparingly.
  * @adjust: If params should be adjusted. Defaults to false.
  *   - true: params are adjusted silently and no error message is thrown
  *   - false: An error message is thrown if the params does not fit.
  */
  public static function checkStmtParams($stmt, &$params = array(), $returnMsg = false, $adjust = false) {

    // check for required params
    $requiredParams = self::getStmtPlaceHolder($stmt);
    foreach ($requiredParams as $key) {
      // remove leading ':'
      $key = substr($key, 1);
      // check with and without ':'
      if (!array_key_exists($key, $params) && !array_key_exists(':' . $key, $params)) {
        if ($adjust) {
          $params[$key] = ''; // fill with empty string
        }
        else {
          $msg .= "Required key $key not found in params.\n";
        }
      }
    }  // eo foreach required param

    // check for too much params (work on copy)
    $tmp = $params;
    foreach ($tmp as $key => $value) {
      // param must have ':' prefix for this check to match placeholder in statement
      if (substr($key, 0, 1) != ':') {
        $key = ':' . $key;
      }
      if (!array_key_exists($key, $requiredParams)) {
        if ($adjust) {
          unset($params[$key]); // remove array entry
        }
        else {
          $msg .= "No statement placeholder found for param key $key.\n";
        }
      }
    }  // eo foreach given param

    // check for duplicates (key exists with and without ':')
    // I hope php drops one of this silently! If not we can add this later.

    // if errormessage than return or throw exception
    if ($msg) {
      $msg = "Sql prepare statement check failure: $msg in $stmt with given parameters: " . str_replace("\n", '' ,var_export($params, true));
      if ($returnMsg) {
        return $msg;
      }
      else {
        throw new Exception($msg);
      }
    }

  }  // eo check statement params





  /**
  * get parameters of sql statement
  * currently named parameters only
  */
  // TODO handle positional parameters too ???
  private static function getStmtPlaceholder($stmt) {
    preg_match_all('/(:\w+)/', $stmt, $matches);
    return $matches[1];
  }

  /**
  * process query
  **/
  public static function query($stmt) {
    self::getConn();
    return self::$conn->query($stmt);
  }


  /**
  * execute statement (non-query)
  **/
  public static function exec($stmt) {
    self::getConn();
    return self::$conn->exec($stmt);
  }





  /**
  * get named sql parameters (by default from post data)
  */
  public static function fillStmtParms($stmt, $values = null) {
    // fill values array from post variables by default
    if ($values === null) {
      $values = $_POST;
    }
    // if values are from an object then convert to array
    if (is_object($values)) {
      $values = get_object_vars($values);
    }

    $names = static::getStmtPlaceholder($stmt);
    $result = array();
    // fill parameters where corresponding key exists in values array
    foreach($values as $key => $value) {
      $key = ':' . $key;
      if (array_search($key, $names) !== false) {
        $result[$key] = $value;
      }
    }

    // fill missing parameters with empty strings
    foreach($names as $key) {
      if (!array_key_exists($key, $result)) {
        $result[$key] = '';
      }
    }

    return $result;
  }  // end of fill stmt parameters



  /**
  * remove entries from search values when no corresponding sql parameters exists
  */
  public static function cleanStmtParms($stmt, $values = null) {
    // if values are from an object then convert to array
    if (is_object($values)) {
      $values = get_object_vars($values);
    }

    $names = static::getStmtPlaceholder($stmt);

    // check keys
    foreach($values as $key => $value) {
      $key = ':' . $key;
      if (array_search($key, $names) === false) {
        unset($values[$key]);
      }
    }

    return $values;
  }  // end of cleaning statement parameters



  /**
  * create where clause for prepared statement
  * ---
  * Memo on prepared statements (php 5.3):
  * Looks like repared statements are always filled in as strings, even
  * if they are forced to numbers (for example multiplied with 1).
  * So whe have to explicitly cast them in the were statement if
  * we need numbers.
  */
  public static function createWhereStmt($fields, $andOr = 'AND', $withWhere = false) {

    $stmt = '';

    // allow "parameter skiping"
    if (!$andOr) {
      $andOr = 'AND';
    }


    // try to detect associative arrays and use array_keys instead
    if (OgerFunc::isAssoc($fields)) {
      $fields = array_keys($fields);
    }


    // create where clause
    // fieldname can contain cast-operator, comparation-operator and andOr operator
    foreach ($fields as $fieldName) {

      list($fieldName, $compOp, $valueName, $andOrOp) = explode(",", $fieldName);
      list($fieldName, $cast) = explode("#", $fieldName);

      $fieldName = trim($fieldName);
      if (!$fieldName) {
        continue;
      }
      if (!static::checkFieldName($fieldName, false)) {
        continue;
      }

      $valueName = trim($valueName);
      if (!$valueName) {
        $valueName = $fieldName;
      }
      if (substr($valueName, 0, 1) != ":") {
        $valueName = ":" . $valueName;
      }

      $cast = trim($cast);
      if ($cast) {
        $valueName = "CAST($valueName AS $cast)";
      }

      $compOp = trim($compOp);
      switch ($compOp) {
      case "=":
      case "!=":
      case "<":
      case "<=":
      case ">":
      case ">=":
        break;
      default:
        $compOp = "=";
      }

      $andOrOp = trim($andOrOp);
      if ($andOrOp) {
        $andOr = $andOrOp;  // fieldspecific values overwrite general settings
      }

      $stmt .= ($stmt ? " $andOr " : '') . "`$fieldName` $compOp $valueName";
    }

    // return nothing if no statement created
    if (!$stmt) {
      return '';
    }

    return ($withWhere ? ' WHERE ' : '') . $stmt;
  } // end of create where for prepared statement



  /**
  * Cleanup where vals from additional parameters like comparison operation etc
  * Fieldnames are NOT checked. Should be save here.
  */
  public static function getCleanWhereVals($values) {

    if ($values === null) {
      $values = array();
    }

    $whereVals = array();

    // fieldname can contain cast-operator, comparation-operator and andOr operator
    foreach ($values as $key => $value) {

      list($fieldName, $compOp, $valueName, $$andOrOp) = explode(",", $key);
      list($fieldName, $cast) = explode("#", $fieldName);

      $fieldName = trim($fieldName);
      if (!$fieldName) {
        continue;
      }

      $valueName = trim($valueName);
      if (!$valueName) {
        $valueName = $fieldName;
      }
      $whereVals[$valueName] = $value;
    }

    return $whereVals;
  } // end of clean where vals


  /**
  * create order by statement
  */
  public static function createOrderByStmt($fields, $withOrderBy = false) {

    $stmt = '';
    foreach ($fields as $key => $direction) {
      $direction = ($direction ? strtoupper($direction) : 'ASC');
      $stmt .= ($stmt ? ',' : '') . "$key $direction";
    }

    return ($withOrderBy ? ' ORDER BY ' : '') . $stmt;
  } // eo create order by



  /**
  * Reverse order by statement
  * orderBy: array with key=fieldname, value=direction pairs
  *          or string with order by statement
  */
  public static function reverseOrderByStmt($orderBy) {

    if (is_string($orderBy)) {
      $orderStmt = trim($orderBy);
      if (strtoupper(substr($orderStmt, 0, 8)) == "ORDER BY") {
        $orderStmt = trim(substr($orderStmt, 8));
        $withOrderBy = true;
      }
      $orderStmt = explode(',', $orderStmt);

      $orderBy = array();
      foreach ($orderStmt as $fieldStmt) {
        $fieldStmt = trim($fieldStmt);
        list($field, $direction) = preg_split('/\s+/', $fieldStmt, 2);
        $orderBy[$field] = $direction;
      }
    }

    foreach ($orderBy as $field => $direction) {
      $direction = ($direction ? strtoupper($direction) : 'ASC');
      // reverse
      $direction = ($direction == 'ASC' ? 'DESC' : 'ASC');
      $stmt .= ($stmt ? ',' : '') . "$field $direction";
    }

    return ($withOrderBy ? ' ORDER BY ' : '') . $stmt;
  } // eo reverse order


  /**
  * create statement string for insert or update
  */
  public static function createStmt($action, $table, $fields, $where = null) {

    $stmt = '';

    if (is_array($where)) {
      $where = self::createWhereStmt($where);
    }

    switch ($action) {
    case self::ACTION_INSERT:
      foreach ($fields as $field) {
        if (!$field) {
          continue;
        }
        $stmtField .= ($stmtField ? "," : '') . "`$field`";
        $stmtValue .= ($stmtValue ? "," : '') . ":$field";
      }
      $stmt .= "INSERT INTO `$table` ($stmtField) VALUES ($stmtValue)";
      break;
    case self::ACTION_UPDATE:
      foreach ($fields as $field) {
        if (!$field) {
          continue;
        }
        $stmtSet .= ($stmtSet ? "," : '') . "`$field`=:$field";
      }
      $stmt .= "UPDATE `$table` SET $stmtSet";
      break;
    default:
      throw new Exception('Unknown Db::action: ' . $action . '.');
    }

    if ($where) $stmt .= ' WHERE ' . $where;

    return $stmt;

  } // end of create statement


  /**
  * Create statement for select
  * Opts parameter consists of keywords array pairs where the array contains the data for the given keyword.
  * If a string is given istead of an array than this string is used directly.
  */
  /* from mysql 5 docu
    SELECT
      [ALL | DISTINCT | DISTINCTROW ]
        [HIGH_PRIORITY]
        [STRAIGHT_JOIN]
        [SQL_SMALL_RESULT] [SQL_BIG_RESULT] [SQL_BUFFER_RESULT]
        [SQL_CACHE | SQL_NO_CACHE] [SQL_CALC_FOUND_ROWS]
      select_expr [, select_expr ...]
      [FROM table_references
      [WHERE where_condition]
      [GROUP BY {col_name | expr | position}
        [ASC | DESC], ... [WITH ROLLUP]]
      [HAVING where_condition]
      [ORDER BY {col_name | expr | position}
        [ASC | DESC], ...]
      [LIMIT {[offset,] row_count | row_count OFFSET offset}]
      [PROCEDURE procedure_name(argument_list)]
      [INTO OUTFILE 'file_name' export_options
        | INTO DUMPFILE 'file_name'
        | INTO var_name [, var_name]]
      [FOR UPDATE | LOCK IN SHARE MODE]]
  */
  public static function createSelectStmt($opts) {

    $stmt = '';

    if (!array_key_exists('checkFieldNames', $opts)) {
      $opts['checkFieldNames'] = true;
    }

    // SELECT statment
    if ($opts['select'] === false) {
      // suppress 'SELECT' statement
    }
    else {
      $stmt .= ($opts['select'] ?: 'SELECT');
    }


    // ALL, DISTINCT, PRIORITY, etc
    if ($opts['extra']) {
      if (is_string($opts['extra'])) {
        $stmt .= ' ' . $opts['extra'];
      }
      else {
        $stmt .= ' ' . implode(' ', $opts['extra']);
      }
    }


    // result fields (if associative arrays use keys insted of values)
    if ($opts['fields']) {
      $fieldNames = $opts['fields'];
      if (is_string($fieldNames)) {
        $fieldNames = explode(',' , $opts['fields']);
      }
      if (OgerFunc::isAssoc($fieldNames)) {
        $fieldNames = array_keys($fieldNames);
      }
      if ($opts['checkFieldNames']) {
        foreach($fieldNames as $fieldName) {
          static::checkFieldName($fieldName, false);
        }
      }
      $stmt .= ' ' . implode(' ', $fieldNames);
    }


    // FROM table reference(s)
    if ($opts['table']) {
      $stmt .= ' FROM ' . $opts['table'];
    }


    // WHERE
    if ($opts['where']) {
      if (is_string($opts['where'])) {
        $str = trim($opts['where']);
        if (strtoupper(substr($str, 0, 5)) != "WHERE") {
          $str = " WHERE $str";
        }
        $stmt .= $str;
      }
      else {
        $stmt .= self::createWhereStmt($opts['where'], null, true);
      }
    }


    // GROUP BY
    if ($opts['groupBy']) {
      if (is_string($opts['groupBy'])) {
        $str = trim($opts['groupBy']);
        if (strtoupper(substr($str, 0, 8)) != "GROUP BY") {
          $str = " GROUP BY $str";
        }
        $stmt .= $str;
      }
      else {
        if (OgerFunc::isAssoc($opts['groupBy'])) {
          $opts['groupBy'] = array_keys($opts['groupBy']);
        }
        $stmt .= ' ' . implode(' ', $opts['groupBy']);
      }
    }


    // HAVING
    if ($opts['having']) {
      if (is_string($opts['having'])) {
        $str = trim($opts['having']);
        if (strtoupper(substr($str, 0, 6)) != "HAVING") {
          $str = " HAVING $str";
        }
        $stmt .= $str;
      }
      else {
        $stmt .= ' HAVING ' . self::createWhereStmt($opts['having'], null, false);
      }
    }


    // ORDER BY
    if ($opts['orderBy']) {
      if (is_string($opts['orderBy'])) {
        $str = trim($opts['orderBy']);
        if (strtoupper(substr($str, 0, 8)) != "ORDER BY") {
          $str = " ORDER BY $str";
        }
        $stmt .= $str;
      }
      else {
        $stmt .= self::createOrderByStmt($opts['orderBy'], true);
      }
    }


    // LIMIT
    if ($opts['limit']) {
      if (is_string($opts['limit'])) {
        $str = trim($opts['limit']);
        if (strtoupper(substr($str, 0, 5)) != "LIMIT") {
          $str = " LIMIT $str";
        }
        $stmt .= $str;
      }
      else {
        if (count($opts['limit']) == 2) {
          if (OgerFunc::isAssoc($opts['limit'])) {
            $limit = $opts['limit']['start'] . ',' . $opts['limit']['limit'];
          }
          else {
            $limit = implode(',', $opts['limit']);
          }
        }
        else {
          if (is_array($opts['limit'])) {
            $limit = '' . reset($opts['limit']);
          }
          else {
            $limit = '' . $opts['limit'];
          }
        }
        $stmt .= " LIMIT $limit";
      }
    }


    // PROCEDURE

    // lock options
    //if ($opts['lock']) {


    // return full statement
    return $stmt;

  } // eo create select statement



  /**
  * Check one fieldname. Throw excaption if fieldname invalid.
  */
  public static function checkFieldName($fieldName, $silent) {
    if (!preg_match('/\w+|\*/', trim($fieldName))) {
      if ($silent) {
        return false;
      }
      else {
        $ex = new Exception("Db::checkFieldName: Invalid fieldname $fieldName.");
        $ex->invalidFieldName = $fieldName;
        throw $ex;
      }
    }
    return true;
  }  // eo check fieldname with exception

  /**
  * Check one or more fieldnames. Throw excaption if fieldname invalid.
  */
  public static function checkFieldNamesEx($fieldNames) {
    if (is_string($fieldNames)) {
      $fieldNames = array($fieldNames);
    }
    foreach ($fieldNames as $fieldName) {
      static::checkFieldName($fieldName, false);
    }
  }  // eo check fieldname with exception



}   // end of class

?>
