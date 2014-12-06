<?PHP
/*
#LICENSE BEGIN
#LICENSE END
*/


/**
* Prepare sql templates with exjs response data
*/
class OgerExtjSqlTpl {

  /// Debug flag.
  //public static $debug = true;
  public static $debug = false;


  public $request;
  public $tpl;
  public $work;
  public $parsed;
  public $sql;
  public $paramValues;


  /*
   * Constructor.
   */
  public function __construct($req = null) {

    $this->setRequest($req);
  }  // eo constructor


  /*
   * Set request.
   * defaults to supervariable $_REQUEST
   */
  public function setRequest($req = null) {

    if ($req === null) {
      $req = $_REQUEST;
    }
    $this->request = $req;
  }  // eo constructor


  /**
  * Get filter data from extjs request.
  */
  public function getStoreFilter() {

    $prop = $this->request['filter'];

    // not present or empty
    if (!$prop]) {
      return array();
    }

    // is already an array
    if (is_array($prop)) {
      return $prop;
    }

    // extract from json request
    $result = array();
    $items = json_decode($prop, true);
    foreach ((array)$items as $item) {
      $result[$item['property']] = $item['value'];
    }

    return $result;
  }  // eo get ext filter


  /**
  * Get sort data from extjs request.
  */
  public function getStoreSort() {

    $prop = $this->request['sort'];

    // not present or empty
    if (!$prop]) {
      return array();
    }

    // is already an array
    if (is_array($prop)) {
      return $prop;
    }

    // extract from json request
    $result = array();
    $items = json_decode($prop, true);
    foreach ((array)$items as $item) {
      $result[$item['property']] = $item['direction'];
    }

    return $result;
  }  // eo get ext sort


  /**
  * Get sql limit.
  */
  public function getStoreLimit() {

   // no limit or limit is empty or non-numeric
    if (!array_key_exists("limit", $this->request) || !is_numeric($this->request['limit'])) {
      return "";
    }
    $limit = "" . intval($this->request['limit']);

    // start only makes sense, if limit is present
    if (array_key_exists("start", $this->request) && is_numeric($this->request['start'])) {
      $limit = "" . intval($this->request['start']) . ",{$limit}";
    }

    return $limit;
  }  // eo get limit



  // #######################################################
  // PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST



  /**
  * Prepare select statement with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public function prepare($tpl, $req = null) {

    $this->tpl = $tpl;
    $this->work = $tpl;

    if ($req !== null) {
      $this->setRequest($req);
    }

    $this->paramValues = array();

    // LIMIT
    // resolve LIMIT before call to parser - otherwise it is confused
    $ori = "__EXTJS_LIMIT__";
    if (strpos($this->work, $ori) !== false) {
      $prep = $this->getStoreLimit();
      $tpl = str_replace($ori, $prep, $this->work);
    }  // eo limit


    // parse and tee-ify
    $parser = new PHPSQLParser\PHPSQLParser();
    $sqlTree = $parser->parse($this->work);
//Oger::debugFile(var_export($sqlTree, true));exit;

    static::extjSqlPspQuery($sqlTree, $seleVals, $req);

    // create sql from prepared parser tree
    $creator = new PHPSQLParser\PHPSQLCreator();
    $sql = $creator->create($sqlTree);

    return $sql;
  }  // eo prep sql with extjs request



  /**
  * Prepare full parsed query tree
  * WORK IN PROGRESS
  * @params &$tree: The parsed and tokenized sql template tree
  */
  public static function extjSqlPspQuery(&$tree, &$seleVals = array(), $req = null) {) {

    static::extjSqlPspSubtreeRun($tree['SELECT'], $seleVals, $req);

    static::extjSqlPspSubtreeRun($tree['FROM'], $seleVals, $req);

    static::extjSqlWherePsp($tree['WHERE'], $seleVals, $req);
    if (!$tree['WHERE']) {
      unset($tree['WHERE']);
    }

  }  // eo process full tree


  /**
  * Prepare subtrees within tokens.
  * WORK IN PROGRESS
  * @params &$tokens: The tokens array.
  */
  public static function extjSqlPspSubtreeRun(&$tokens, &$seleVals = array(), $req = null) {) {

    foreach ($tokens as $key => &$token) {
      if ($token['sub_tree']) {
        static::extjSqlPspSubTree($token, $seleVals, $req);
        // if token subtree is empty after subtree preparation,
        // then remove the token at all
        if (!$token['sub_tree']) {
          unset($tokens[$key]);
        }
      }
    }
  }  // eo process SELECT segment


  /**
  * Prepare single subtree token
  * WORK IN PROGRESS
  * @params &$token: The subtree token.
  */
  public static function extjSqlPspSubtree(&$token, &$seleVals = array(), $req = null) {) {

    switch ($token['expr_type']) {
    case "subquery":
      static::extjSqlPspProcessTree($token['sub_tree'], $seleVals, $req);
    default:
      // do nothing
    }
  }  // eo process subtree



  /**
  * Prepare WHERE (or HAVING) tree with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  *         Variables are detectec by the colon (:) prefix.
  */
  public static function extjSqlWherePsp(&$tokens, &$whereVals = array(), $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }

    // get extjs filter from request
    $req['filter'] = static::getStoreFilter(null, $req);
    $extFilter = array();
    foreach ((array)$req['filter'] as $colName => $value) {
      $extFilter[$colName] = $value;
    }  // eo filter item loop


    $tokensOut = array();
    $queue = array();

    foreach ($tokens as $key => &$token) {

      $uTok = strtoupper($token);

      if ($uTok == "AND" || $uTok == "OR") {
        $parts[] = $queue;
        $queue = array();
      }
      $queue[] = &$token;
    }
    $parts[] = $queue;


    foreach ($parts as &$partTokens) {

      $usePart = true;

      foreach ($partTokens as &$partToken) {

        // we are only interested in named sql params ":xxx"
        if (substr($partToken, 0, 1) != ":") {
          continue;
        }

        // remove leading colon
        $pnam = substr($pnamOri, 1);

        // detect internal commands in first char
        $doRemoveColon = false;
        $doRemovePnam = false;
        $isRequiredParam = false;
        $doForceAddParam = false;
        $isZombieParam = false;
        $valueRequired = false;
        //$trimmedValueRequired = false;

        // loop over internal command prefix
        $intCmdLoop = true;
        while ($intCmdLoop) {

          // remove colon in final where clause
          if (substr($pnam, 0, 1) == "-") {  // alternate: "="
            $doRemoveColon = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // test if pnam exists and remove pnam afterwards
          if (substr($pnam, 0, 1) == "?") {  // alternate: "-"
            $doRemovePnam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // throw exption if pnam does not exist
          if (substr($pnam, 0, 1) == "!") {  // alternate: ":" ????
            $isRequiredParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // add pnam even if not exits in value arra
          if (substr($pnam, 0, 1) == "+") {
            $doForceAddParam = true;
            $pnam = substr($pnam, 1);
            continue;
          }
          // use only if not empty (untrimmed)
          // support ">" for backward compability
          if (substr($pnam, 0, 1) == ">" ||
              substr($pnam, 0, 1) == "@") {  // alternate: "?"
            $valueRequired = true;
            $pnam = substr($pnam, 1);
            continue;
          }

          $intCmdLoop = false;
        }  // eo internal cmd check
if (static::$debug) { echo "search for $pnam<br>\n"; };


        // check if key exists and get value
        //
        // if pnam already in where vals, then this has peference
        if (array_key_exists($pnam, $whereVals)) {
          $value = $whereVals[$pnam];
          $usePart = true;
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
          $usePart = true;
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $req)) {
          $value = $req[$pnam];
          $usePart = true;
        }
if (static::$debug) { echo "use $pnam<br>\n"; };
        // handle special internal commands and special cases
        //
        // if param is forced then add part even if pnam not present
        // the user is responsible to provide the key and the value elsewhere
        if ($doForceAddParam) {
          $isZombieParam = true;
          $usePart = true;
        }

        // otherwise check if it is a required param
        // if not present till now throw an exeption
        if ($isRequiredParam && !$usePart) {
          throw new Exception("Required parameter '$pnam' not in value array for {$tpl}.");
        }

        // otherwise if value is required but not present
        // then exlude if value is not present
        if ($valueRequired && !$value) {
          $usePart = false;
          break;
        }


        // final test if part is used
        if (!$usePart) {
          break;
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

      // do not use empty parts
      if (!trim($part)) {
        $usePart = false;
      }
if (static::$debug) { echo "use=$usePart, usedPart=$part<br>\n"; };

      // use part
      if ($usePart) {
        $sql .= ($sql ? " {$andOrGlue}" : "") . ($kwNOT ? " {$kwNOT}" : "") . " {$part}";
      }
    }  // eo part loop

    // if sql collected then prefix with keyword (and extrablanks to avoid collisions)
    if ($sql) {
      // replace escaped AND / OR part-delimiter
      $sql = preg_replace("/\b\\\\AND\b/", "AND", $sql);
      $sql = preg_replace("/\b\\\\OR\b/", "OR", $sql);
      $sql = " {$kw} {$sql} ";
    }

    return $sql;
  }  // eo WHERE with ext



  /**
  * Prepare ORDER BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlOrderByPsp($tpl, $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*ORDER\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }


    // extract extra sort field info from template
    $parts = explode(";", $tpl);
//@file_put_contents("debug.localonly", "tpl=$tpl => " . var_export($parts, true) . "\n\n", FILE_APPEND);
    $tplSorter = array();
    foreach ((array)$parts as $value) {
      $value = trim($value);
      if (!$value) {
        continue;
      }
      if (strpos($value, "=") !== false) {
        list($key, $value) = explode("=", $value, 2);
        $key = trim($key);
        $value = trim($value);
      }
      else {  // for stand alone colnames sort expression is same as colname
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // if no sort expression is present then fill with default sort
    // if no default sort exists remove sort key
    $defaultSort = $tplSorter[''];
    foreach($tplSorter as $key => $value) {
      if (!$value) {
        if ($defaultSort) {
          $tplSorter[$key] = $defaultSort;
        }
        else {
          unset($tplSorter[$key]);
        }
      }
    }

    // convert sort info from json to array
    $req['sort'] = static::getStoreSort(null, $req);

    // loop over sort info from ext
    foreach ((array)$req['sort'] as $colName => $direct) {

      if ($direct &&  $direct != "ASC" && $direct != "DESC" && trim($direct) != "") {
        throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
      }

      // apply sort expression from template when exists,
      // otherwise ignore sort request
      $sortExpr = trim($tplSorter[$colName]);
      if (!$sortExpr) {
        continue;
      }

      // compose sql
      // if sort direction placeholder exists replace ALL placeholder
      // otherwise append direction if given
      $tmpSql = $sortExpr;
      if (strpos($tmpSql, "__EXTJS_DIRECTION__") !== false) {
        $tmpSql = str_replace("__EXTJS_DIRECTION__", $direct, $tmpSql);
      }
      elseif($direct) {
        $tmpSql .= " $direct";
      }

      $sql .= ($sql ? "," : "") . $tmpSql;

    }  // eo ext sort item loop

    $sql = trim($sql);

    // if no order-by sql is composed, then use the template default sort
    // but remove direction placeholder fist
    if (!$sql && $defaultSort) {
      $sql = str_replace("__EXTJS_DIRECTION__", "", $defaultSort);
    }


    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = " {$kw} {$sql} ";
    }


    return $sql;
  }  // eo ORDER BY with ext



  /**
  * Prepare GROUP BY clause with data from extjs request.
  * WORK IN PROGRESS
  * @params $tpl: The template containing special sql
  */
  public static function extjSqlGroupByPsp($tpl, $req = null) {

    if ($whereVals === null) {
      $whereVals = array();
    }

    if ($req === null) {
      $req = $_REQUEST;
    }


    // detect, save and remove leading where keyword
    if (preg_match("/^\s*GROUP\s+BY\s+/i", $tpl, $matches)) {
      $kw = $matches[0];
      $tpl = implode("", explode($kw, $tpl, 2));
    }


    // extract extra group field info from template
    $parts = explode(";", $tpl);
    $tplSorter = array();
    foreach ((array)$parts as $value) {
      $value = trim($value);
      if (!$value) {
        continue;
      }
      if (strpos($value, "=") !== false) {
        list($key, $value) = explode("=", $value, 2);
        $key = trim($key);
        $value = trim($value);
      }
      else {  // for stand alone colnames group expression is same as colname
        $key = $value;
      }
      $tplSorter[$key] = $value;
    }

    // if no group expression is present then fill with default group
    // if no default group exists remove group key
    $defaultSort = $tplSorter[''];
    foreach($tplSorter as $key => $value) {
      if (!$value) {
        if ($defaultSort) {
          $tplSorter[$key] = $defaultSort;
        }
        else {
          unset($tplSorter[$key]);
        }
      }
    }

    // convert sort info from json to array
    $req['sort'] = static::getStoreSort(null, $req);

    // loop over sort info from ext
    foreach ((array)$req['sort'] as $colName => $direct) {

      if ($direct &&  $direct != "ASC" && $direct != "DESC" && trim($direct) != "") {
        throw new Exception("Invalid direction '$direct' for column name '$colName' in ExtJS sort.");
      }

      // apply sort expression from template when exists,
      // otherwise ignore sort request
      $sortExpr = trim($tplSorter[$colName]);
      if (!$sortExpr) {
        continue;
      }

      // compose sql
      // if sort direction placeholder exists replace ALL placeholder
      // otherwise append direction if given
      $tmpSql = $sortExpr;
      if (strpos($tmpSql, "__EXTJS_DIRECTION__") !== false) {
        $tmpSql = str_replace("__EXTJS_DIRECTION__", $direct, $tmpSql);
      }
      elseif($direct) {
        $tmpSql .= " $direct";
      }

      $sql .= ($sql ? "," : "") . $tmpSql;

    }  // eo ext sort item loop

    $sql = trim($sql);

    // if no order-by sql is composed, then use the template default sort
    // but remove direction placeholder fist
    if (!$sql && $defaultSort) {
      $sql = str_replace("__EXTJS_DIRECTION__", "", $defaultSort);
    }


    // if sql collected then prefix with keyword
    if ($sql) {
      $sql = " {$kw} {$sql} ";
    }


    return $sql;
  }  // eo GROUP BY with ext


  // END OF PREPARE SQL STATEMENT WITH VALUES FROM EXTJS REQUEST - php-sql-parser mode
  // #######################################################










}  // end of class
?>
