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




  // ##############################################
  // new where style:




  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function prepSqlWithExt($tpl, &$seleVals = array(), $req = null) {

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
      $prep = self::whereWithExt(substr($ori, 1, -1), $seleVals);
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo where


    // ORDER BY
    if (preg_match("/\{\s*ORDER\s+BY\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $prep = substr($ori, 1, -1);

      // prep and insert extjs sort
      if (strstr($prep, "__EXT_SORT__")) {

        $extSort = "";

        if ($req['sort'] && !is_array($req['sort'])) {
          $extItems = json_decode($req['sort'], true);
          $tmpArr = array();
          foreach ((array)$extItems as $extItem) {
            $tmpArr[$extItem['property']] = $extItem['direction'];
          }
          $req['sort'] = $tmpArr;
        }
        foreach ((array)$req['sort'] as $colName => $direct) {
          // security checks
          if (!preg_match('/^([a-z_][a-z0-9_]*)$/i', $colName)) {
            throw new Exception("Invalid column name '$colName' in ExtJS sort.");
          }
          if ($direct &&  $direct != "ASC" && $direct != "DESC") {
            throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
          }
          $extSort .= ($extSort ? ", " : "") . Dbw::$encNamBegin . $colName . Dbw::$encNamEnd .
                      ($direct ? " " : "") . $direct;
        }  // eo sort item loop

        $prep = str_replace("__EXT_SORT__", $extSort, $prep);
      }  // eo __EXT_SORT__

      // no more to do for now - maybe more sort options to include

      // if only the ORDER BY keyword remains then remove the section completely
      if (preg_match("/^\s*ORDER\s+BY\s*$/i", $prep)) {
        $prep = "";
      }

      // write order by part back to template
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


    // remove all other special markers
    // remove count skip marker for countWithExt()
    $tpl = preg_replace("/\{\s*__EXT_COUNT_SKIP[\[\]]__.*?\}/", "", $tpl);


    return $tpl;
  }  // eo select with ext


  /**
  * Prepare where clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function whereWithExt($tpl, &$whereVals = array()) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    $req = $_REQUEST;


    // get extjs filter from request
    $filterVals = array();
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
        throw new Exception("Invalid column name '$colName' in ExtJS filter.");
      }
      $filterVals[$colName] = $value;
    }  // eo sort item loop


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*WHERE\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = str_replace($kw, "", $tpl);
    }

    // split at and/or boundery
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
      preg_match_all("/:([a-z_%][a-z0-9_%]*)/i", $part, $matches);
      $pnams = $matches[1];
      foreach ($pnams as $key => $pnam) {

        $pnamOri = $pnam;
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

        // if pnam already in where vals this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
        }
        // otherwise if pnam in extjs filter vals we take this
        elseif (array_key_exists($pnam, $filterVals)) {
          $value = $filterVals[$pnam];
        }
        // otherwise if pnam in request we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
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
        if ($valPre || $valPost) {
          // add ":" anchor for better matches
          $part = str_replace(":$pnamOri", ":$pnam", $part);
        }
        $whereVals[$pnam] = $value;
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
  }  // eo where with ext



  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function countWithExt($tpl, &$seleVals = null) {

    if ($seleVals === null) {
      $seleVals = array();
    }


    // remove count skip areas first, because this can effect the select marker
    $tpl = preg_replace("/\{\s*__EXT_COUNT_SKIP[__\s*\}.*?\{\s*__EXT_COUNT_SKIP]__\s*\}/", "", $tpl);


    // SELECT
    if (preg_match("/\{\s*SELECT\s.*?\}/i", $tpl, $matches)) {
      $ori = $matches[0];
      $tpl = str_replace($ori, "SELECT COUNT(*) ", $tpl);
    }
    else {
      throw new Exception("Missing required SELECT section in countWithExt template.");
    }  // eo select


    $sql = static::prepSqlWithExt($tpl, $seleVals);

    $pstmt = Dbw::$conn->prepare($sql);
    $pstmt->execute($seleVals);
    $count = $pstmt->fetchColumn();
    $pstmt->closeCursor();

    return $count;
  }  // eo count with ext





}  // end of class

?>
