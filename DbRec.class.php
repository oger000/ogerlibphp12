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
* Collection of static methods.
*/
class DbRec {

  public static $tableName;


  /**
  * Constructor. Set values from associative array.
  */
  public function __construct($values = array()) {
    $this->setValues($values);
  }  // eo constructor



  /**
  * Set all values from an array to the object.
  * @deprecated: Deprecated.
  * @values: associative array with fieldname (key) value pairs.
  */
  public function setValues($values = array()) {
    //trigger_error("Function " . __CLASS__ . "::" . __FUNCTION__ . "() is deprecated.", E_USER_DEPRECATED);
    foreach ((array)$values as $key => $value) {
      $this->$key = $value;
    }
  }  // eo set values from array



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
  * Get row data from db.
  * Old style WHERE.
  * @return False if nothing found.
  */
  public static function fromDb($whereVals = array()) {

    $stmt = "SELECT * FROM " . static::$tableName . Dbw::whereStmt($whereVals) . " LIMIT 1";
    $pstmt = Dbw::$conn->prepare($stmt);
    $pstmt->execute($whereVals);
    $row = $pstmt->fetch(PDO::FETCH_ASSOC);
    $pstmt->closeCursor();

    return $row;
  }  // eo from db



  /**
  * Get data for input form.
  * Old style WHERE.
  * @return False if nothing found.
  */
  public static function getForForm($whereVals = array()) {

    return static::fromDb($whereVals);
  }  // eo get form data



  /**
  * Write record values to db.
  * Old style WHERE.
  */
  public static function toDb($saveAction, $values, $whereVals = array()) {

    $values = static::filterColValues($values);

    $stmt = Dbw::getStoreStmt($saveAction, static::$tableName, $values, $whereVals);
    $pstmt = Dbw::$conn->prepare($stmt);
    $result = $pstmt->execute(array_merge((array)$whereVals, $values));
    $pstmt->closeCursor();

  }  // eo write to db



  /**
  * Get max value.
  * Old style WHERE.
  */
  public static function getMaxValue($fieldName, $whereVals = array()) {

    $stmt = "SELECT MAX($fieldName) FROM " . static::$tableName . Dbw::whereStmt($whereVals);
    $pstmt = Dbw::$conn->prepare($stmt);
    $pstmt->execute($whereVals);
    $maxVal = $pstmt->fetchColumn();
    $pstmt->closeCursor();

    return $maxVal;
  }  // eo max value


  /**
  * Exists record with this where values. Static version.
  */
  public static function exists($whereVals = array()) {
    return static::getCount($whereVals) > 0;
  }  // eo record exists


  /**
  * Get count for this where values.
  * Old style WHERE.
  */
  public static function getCount($whereVals = array()) {

    $stmt = "SELECT COUNT(*) FROM " . static::$tableName . Dbw::whereStmt($whereVals);
    $pstmt = Dbw::$conn->prepare($stmt);
    $pstmt->execute($whereVals);
    $count = $pstmt->fetchColumn();
    $pstmt->closeCursor();

    return $count;
  }  // eo get count




}  // end of class

?>
