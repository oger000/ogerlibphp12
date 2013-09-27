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
  * Get prepared sql string and fill sele vals
  */
  public static function getSql($target, &$seleVals = array()) {
    $tpl = static::getSqlTpl($target);
    $sql = OgerExtjs::extjSql($tpl, $seleVals);
    return $sql;
  }  // eo get sql





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

    Dbw::checkStmtParams($stmt, $values);
    $result = $pstmt->execute($values);
    $pstmt->closeCursor();
  }  // eo store to db



  /**
  * Prepare date fields for output.
  */
  public static function prepDateOut($values, $dateFieldNames) {

    foreach ($dateFieldNames as $fieldName) {
      if (substr($values[$fieldName], 0, 10) == '0000-00-00') {
        $values[$fieldName] = '';
      }
    }

    return $values;
  }  // eo order by template




}  // end of class

?>
