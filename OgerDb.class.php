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
      $stmt .= static::whereStmt($where);
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
  *                 Or a string which will be prefixed with "WHERE" if not already present -
  *                 otherwise returned unchanged.
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
      $stmt .= ($stmt ? " $glueOp " : "") . static::encName($paramName) . "=:$paramName";
    }

    return $stmt;
  } // end of create where



  /**
  * Get value of first column of first row.
  */
  public static function fetchValue1($sql, $seleVals = array()) {

    $pstmt = static::$conn->prepare($sql);
    $pstmt->execute($seleVals);
    $value1 = $pstmt->fetchColumn();
    $pstmt->closeCursor();

    return $value1;
  }  // eo get value 1


  /**
  * Get first row.
  */
  public static function fetchRow1($sql, $seleVals = array()) {

    $pstmt = static::$conn->prepare($sql);
    $pstmt->execute($seleVals);
    $row1 = $pstmt->fetch(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    return $row1;
  }  // eo get value 1



  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST



  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSql($tpl, &$seleVals = array(), $req = null) {

    if ($seleVals === null) {
      $seleVals = array();
    }
    if ($req === null) {
      $req = $_REQUEST;
    }


    // SELECT
    if (preg_match("/\{\s*SELECT\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo select


    // WHERE
    if (preg_match("/\{\s*WHERE\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = self::extjSqlWhere(substr($ori, 1, -1), $seleVals);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo where


    // ORDER BY
    if (preg_match("/\{\s*ORDER\s+BY\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = self::extjSqlOrderBy(substr($ori, 1, -1));
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo order by


    // LIMIT
    $ori = "__EXT_LIMIT__";
    if (strpos($tpl, $ori) !== false) {
      $prep = "";
      if (array_key_exists("limit", $req) && is_numeric($req['limit'])) {
        $prep .= intval($req['limit']);
      }
      // start only makes sense if limit is in prep
      if ($prep && array_key_exists("start", $req) && is_numeric($req['start'])) {
        $prep = "" . intval($req['start']) . ",$prep";
      }
      if ($prep) {
        $prep = "LIMIT $prep";
      }
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo limit


    return $tpl;
  }  // eo select with ext


  /**
  * Prepare WHERE clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlWhere($tpl, &$whereVals = array(), $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // get extjs filter from request
    $extFilter = array();
    if ($req['filter'] && !is_array($req['filter'])) {
      $extItems = json_decode($req['filter'], true);
      $tmpArr = array();
      foreach ((array)$extItems as $extItem) {
        $tmpArr[$extItem['property']] = $extItem['value'];
      }
      $req['filter'] = $tmpArr;
    }
    foreach ((array)$req['filter'] as $colName => $value) {
      // security checks
      if (!preg_match("/^([a-z_][a-z0-9_]*)$/i", $colName)) {
        throw new Exception("Invalid character in filter key (column name) '$colName' in ExtJS filter.");
      }
      $extFilter[$colName] = $value;
    }  // eo sort item loop


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*WHERE\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = str_replace($kw, "", $tpl);
    }

    // split at and/or boundery
    // TODO detect parenthesis
    $parts = preg_split("/(\s+OR\s+|\s+AND\s+)/i", $tpl, null, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as &$part) {

      // detect and/or glue
      $tmp = strtoupper(trim($part));
      if ($tmp == "OR" || $tmp == "AND") {
        $andOrBuffer = $part;
        continue;
      }

      // check if all parameter names for this part are present
      $usePart = true;
      preg_match_all("/(:[\-\?\+!]?[%]?[a-z_][a-z0-9_]+[%]?)/i", $part, $matches);
      $pnams = $matches[1];
      foreach ($pnams as $key => $pnamOri) {

        // remove leading colon
        $pnam = substr($pnamOri, 1);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;
        $doForceAddParam = false;
        $isZombieParam = false;

        if (substr($pnam, 0, 1) == "-") {
          $doRemoveColon = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "?") {
          $doRemovePnam = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "!") {
          $isRequiredParam = true;
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, 0, 1) == "+") {
          $doForceAddParam = true;
          $pnam = substr($pnam, 1);
        }

        // separate special sql prefix and postfix (e.g. %var%)
        $valPre = "";
        $valPost = "";
        if (substr($pnam, 0, 1) == "%") {
          $valPre = "%";
          $pnam = substr($pnam, 1);
        }
        if (substr($pnam, -1) == "%") {
          $valPost = "%";
          $pnam = substr($pnam, 0, -1);
        }

        // if pnam already in where vals, then this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
        }
        // otherwise if param is forced then add part even if pnam not present
        // the user is responsible to provide the key and the value elsewhere
        elseif ($doForceAddParam) {
          $isZombieParam = true;
        }
        // otherwise check if it is a required param
        // if not present till now throw an exeption
        elseif ($isRequiredParam) {
          throw new Exception("Required parameter '$pnam' not in value array.");
        }
        // as last resort we do not use this part
        else {
          $usePart = false;
          break;
        }  // check if pnames present

        // apply prefix and postfix to value
        if (substr($value, 0, 1) != $valPre) {
          $value = $valPre . $value;
        }
        if (substr($value, -1) != $valPost) {
          $value = $value . $valPost;
        }

        // write polished pnam out back to where part
        if ($doRemoveColon) {
          $pnamOut = $pnam;
        }
        elseif ($doRemovePnam) {
          $pnamOut = "";
        }
        else {
          $pnamOut = ":$pnam";  // reasign colon
        }
        $part = str_replace($pnamOri, $pnamOut, $part);

        // remember pnam and value only if placeholder (:var) remains
        // do not add zombie parameters - they come from elsewhere
        if (substr($pnamOut, 0, 1) == ":" && !$isZombieParam) {
          $whereVals[$pnam] = $value;
        }
      }  // eo pnam loop

      if ($usePart) {
        $sql .= ($sql ? $andOrBuffer : "") . $part;
      }
    }  // eo part loop

    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = $kw . $sql;
    }

    return $sql;
  }  // eo WHERE with ext



  /**
  * Prepare ORDER BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlOrderBy($tpl, $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*ORDER\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = str_replace($kw, "", $tpl);
    }


    // extract extra sort field info from template
    $parts = explode(";", $tpl);
    $tplSorter = array();
    foreach ((array)$parts as $value) {
      $value = trim($value);
      if (strpos($value, "=") !== false) {
        list($key, $value) = explode("=", $value, 2);
        $key = trim($key);
        $value = trim($value);
      }
      else {
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // convert sort info from json to array
    if ($req['sort'] && !is_array($req['sort'])) {
      $extItems = json_decode($req['sort'], true);
      $tmpArr = array();
      foreach ((array)$extItems as $extItem) {
        $tmpArr[$extItem['property']] = $extItem['direction'];
      }
      $req['sort'] = $tmpArr;
    }

    // loop over sort info from ext
    foreach ((array)$req['sort'] as $colName => $direct) {
      // security checks
      if (!preg_match('/^([a-z_][a-z0-9_]*)$/i', $colName)) {
        throw new Exception("Invalid character in sort key (column name) '$colName' in ExtJS sort.");
      }
      if ($direct &&  $direct != "ASC" && $direct != "DESC" && $direct != "") {
        throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
      }

      // column name from ext must be present in template sorter
      // or template sorter wildcard (*) must be set, otherwise stop
      if (!($tplSorter[$colName] || $tplSorter['*'])) {
        continue;
      }

      // encode plain column names
      $colNameOut = static::$encNamBegin . $colName . static::$encNamEnd;

      // if template sorter info is present overwrite plain name
      if (array_key_exists($colName, $tplSorter)) {
        $colNameOut = $tplSorter[$colName];
      }

      if ($colNameOut) {
        $sql .= ($sql ? ", " : "") . $colNameOut .
                ($direct ? " " : "") . $direct;
      }
    }  // eo ext sort item loop

    // if no ext sorters are given but a default template sorter is present
    // and no sql composed, then use the template sorter default
    if (!count($req['sort']) && $tplSorter['@'] && !$sql) {
      $sql .= ($sql ? ", " : "") . $tplSorter['@'];
    }


    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = $kw . $sql;
    }


    return $sql;
  }  // eo ORDER BY with ext





}  // eo class





?>
