<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/*
 * This class is not intended as part of the ogerlibphp12 and
 * should go into the application specific class directory later.
 * For now it is guest here to share basic development between
 * apps in early stage and at the same time being tested in
 * mature apps for later inclusion.
 */

/**
 * WORK IN PROGRESS
 * This class has core function on one side and at the same time
 * is the playground for other database related functions.
 * Including ones that may go into other classes when final.
 * So be careful when using across multiple projects.
 */



/**
* Base class for handling one record of a database table.
* Mainly a collection of static methods.
*/
class DbRec {

  public static $tableName;
  public static $primaryWhere;

  /**
  * Filter out column values from an array.
  * @values: associative array with fieldname (key) value pairs.
  */
  public static function filterColValues($values = array()) {

    $newVals = array();

    foreach ((array)$values as $key => $value) {
      if (Dbw::isColumn(static::$tableName, $key)) {
        $newVals[$key] = $value;
      }
    }

    return $newVals;
  }  // eo filter



  /**
  * Write record values to db.
  * Accepts old style WHERE values (as array) too.
  */
  public static function store($storeAction, $values, $where = null) {

    $values = static::filterColValues($values);

    // sanity check - do not update without WHERE clause
    if ($storeAction == "UPDATE") {
      if (!$where) {
        $where = static::$primaryWhere;
      }
      if (!$where) {
        throw new Exception("Update without WHERE clause refused.");
      }
    }  // eo update WHERE check

    $stmt = Dbw::getStoreStmt($storeAction, static::$tableName, $values, $where);
    $pstmt = Dbw::$conn->prepare($stmt);
    if (is_array($where)) {
      $values = array_merge($where, $values);
    }
    $result = $pstmt->execute($where);
    $pstmt->closeCursor();
  }  // eo store to db




  /**
  * Get full sql template.
  */
  public static function getSqlTpl($selectTarget, $whereTarget = null, $orderTarget = null) {

    $tpl = static::getSelectTpl($selectTarget);

    if ($whereTarget) {
      $whereTarget = ltrim($whereTarget);
      // a prefix of "=" returns the where target unchanged (prefix stripped)
      if (substr($whereTarget, 0, 1) == "=") {
        $tpl .= " " . substr($whereTarget, 1);
      }
      else {
        $tpl .= " " . static::getWhereTpl($whereTarget, $whereVals);
      }
    }  // where

    if ($orderTarget) {
      $tpl .= " " . static::getOrderTpl($orderTarget);
    }

    return $tpl;
  }  // eo get sql tpl


  /**
  * Get select template.
  */
  public static function getSelectTpl($target) {

    $listDelim = ExcavHelper::$xidDelimiterOut;

    if ($target == "DEFAULT") {
      return "SELECT * FROM archFind ";
    }

    if ($target == "GRID" || $target == "FORM") {
      return
        "SELECT *," .
        "  (SELECT group_concat(stratumId ORDER BY stratumid SEPARATOR '$listDelim') " .
        "   FROM stratumToArchFind AS stToAf " .
        "   WHERE stToAf.excavId=archFind.excavId AND stToAf.archFindId=archFind.archFindId " .
        "  ) AS stratumIdList " .
        "FROM archFind ";
    }

  }  // get select


  /**
  * Get where sql and prepare sele vals.
  */
  /*
  public static function getExtjSqlWhere($target, &$seleVals = array()) {
    $where = static::getWhereTpl($target);
    $where = Dbw::extjSqlWhere($where, $seleVals);
    return $where;
  }  // eo get extjs sql where
  */

  /**
  * Get where template.
  */
  public static function getWhereTpl($target) {
  }  // eo where tpl


  /**
  * Get order-by template.
  */
  public static function getOrderTpl($target) {
  }  // eo order by tpl






}  // end of class

?>
