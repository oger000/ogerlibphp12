<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/



/**
* Helperclass for DBO related methods.
*/
class OgerDbo {

  const ACTION_INSERT = 'INSERT';
  const ACTION_UPDATE = 'UPDATE';


  /**
  * create statement string for insert or update
  */
  public static function createStmt($action, $table, $fields, $delim = "") {

    switch ($action) {
    case self::ACTION_INSERT:
      foreach ($fields as $field) {
        $fieldStmt .= ($fieldStmt ? "," : '') . "$delim$field$delim";
        $valueStmt .= ($valueStmt ? "," : '') . ":$field";
      }
      $stmt .= "INSERT INTO $delim$table$delim ($fieldStmt) VALUES ($valueStmt)";
      break;
    case self::ACTION_UPDATE:
      foreach ($fields as $field) {
        $stmtSet .= ($stmtSet ? "," : '') . "$delim$field$delim=:$field";
      }
      $stmt .= "UPDATE $delim$table$delim SET $stmtSet";
      break;
    default:
      throw new Exception("Unknown " . __CLASS__ . "::action: $action.");
    }

    return $stmt;
  } // end of create statement



  /**
  * Check parameters for statement.
  * Only used for debugging, because the error messages of the pdo-drivers are very sparingly.
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
    preg_match_all('/:(\w+)/', $stmt, $matches);
    $stmtParams = $matches[1];
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



?>
