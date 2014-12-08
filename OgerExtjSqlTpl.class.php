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
  public $template;
  public $parsed;
  public $prepared
  public $sql;
  public $paramValues = array();;

  private $tpl;

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

    $this->template = $tpl;

    if ($req !== null) {
      $this->setRequest($req);
    }
    // sanity check / set default
    if ($this->request === null) {
      $this->setRequest();
    }

    $this->paramValues = array();

    // LIMIT
    // resolve LIMIT before call to parser - otherwise it is confused
    $ori = "__EXTJS_LIMIT__";
    if (strpos($tpl, $ori) !== false) {
      $prep = $this->getStoreLimit();
      $tpl = str_replace($ori, $prep, $tpl);
    }  // eo limit


    // parse and tee-ify
    $parser = new PHPSQLParser\PHPSQLParser();
    $this->parsed = $parser->parse($tpl);
//Oger::debugFile(var_export($sqlTree, true));exit;

    $this->repared = $this->prepQuery($this->parsed);

    // create sql from prepared parser tree
    $creator = new PHPSQLParser\PHPSQLCreator();
    $this->sql = $creator->create($this->prepared

    return $this->sql;
  }  // eo prep sql with extjs request



  /**
  * Prepare full parsed query tree
  * @params $tree: Parsed and tokenized sql template tree
  */
  public function prepQuery($tree) {

    $tree = $this->prepSequence($tree['SELECT']);

    $tree = $this->prepSequence($tree['FROM']);

    $tree = $this->prepWhere($tree['WHERE']);
    if (!$tree['WHERE']) {
      unset($tree['WHERE']);
    }

    $tree = $this->prepSequence($tree['GROUP BY']);

    $tree = $this->prepWhere($tree['HAVING']);
    if (!$tree['HAVING']) {
      unset($tree['HAVING']);
    }

    $tree = $this->prepSequence($tree['ORDER BY']);

    return $tree;
  }  // eo process full tree


  /**
  * Prepare token sequence.
  * @params $sequences: A token sequence.
  */
  public function prepSequence($sequence) {

    $sequenceOut = array();

    foreach ($sequence as $key => $token) {
      if ($token['sub_tree']) {
        $token = $this->prepSubtree($token);
        // if token subtree is empty, then we ignore
        if (!$token['sub_tree']) {
          continue;
        }
      }
      $sequenceOut[] = $token;
    }

    return $sequenceOut;
  }  // eo process SELECT segment


  /**
  * Prepare single subtree token
  * @params $token: The subtree token.
  */
  public function prepSubtree($token) {

    switch ($token['expr_type']) {
    case "subquery":
      $token['sub_tree'] = $this->prepQuery($token['sub_tree']);
      break;
    default:
      // do nothing
    }

    return $token;
  }  // eo prep subtree



  /**
  * Prepare WHERE (or HAVING) tree with data from extjs request.
  * @params $sequcence:
  *         Variables are detectec by the colon (:) prefix.
  */
  public function prepWhere($sequence) {

    // get extjs filter from request
    $extFilter = $this->getStoreFilter();

    $parts = array();
    $queue = array();

    // split into AND/OR parts
    foreach ($sequence as $token) {

      if ($token['expr_type'] == "operator") {
        $uTok = strtoupper($token['base_expr']);
        if ($uTok == "AND" || $uTok == "OR") {
          $parts[] = $queue;
          $queue = array();
        }
      }
      $queue[] = $token;
    }
    if ($queue) {
      $parts[] = $queue;
    }


    $sequenceOut = array();
    foreach ($parts as $partTokens) {

      $usePart = true;
      $tmpParamValues = array();

      foreach ($partTokens as $token) {

        // we are only interested in named sql params ":xxx"
        if (!($token['expr_type'] == "colref" && substr($token['base_expr'], 0, 1) == ":")) {
          continue;
        }

        $usePart = false;
        $pnamOri = $token['base_expr'];

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
          if (substr($pnam, 0, 1) == "=") {  // alternate: "=", was: "-"
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
              substr($pnam, 0, 1) == "@") {  // alternate: ".~"
            $valueRequired = true;
            $pnam = substr($pnam, 1);
            continue;
          }

          $intCmdLoop = false;
        }  // eo internal cmd check


        // check if key exists and get value
        //
        // if pnam already in where vals, then we do nothing
        if (array_key_exists($pnam, $this->paramValues)) {
          $value = $this->paramValues[$pnam];
          // already done
          $usePart = true;
        }
        // otherwise if pnam exists in extjs filter vals then we take this
        elseif (array_key_exists($pnam, $extFilter)) {
          $value = $extFilter[$pnam];
          $usePart = true;
        }
        // otherwise if pnam elsewhere in values (request) then we take this
        elseif (array_key_exists($pnam, $this->request)) {
          $value = $this->request[$pnam];
          $usePart = true;
        }
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
          throw new Exception("Required parameter '$pnam' not in value array for {$this->template}.");
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


        // polish pnam
        if ($doRemoveColon) {
          $pnamOut = $pnam;
        }
        elseif ($doRemovePnam) {
          $pnamOut = "";
        }
        else {
          $pnamOut = ":{$pnam}";  // reasign colon
        }

        $token['base_expr'] = $pnamOut;
        $token['no_quotes'] = $pnamOut;

        // remember value only if placeholder (:var) remains
        // do not add zombie parameters - they come from elsewhere
        if (substr($pnamOut, 0, 1) == ":" && !$isZombieParam) {
          $tmpParamValues[$pnam] = $value;
        }
      }  // eo loop over all tokens of one part

      if (!$usePart) {
        continue;
      }

      // remove and/or glue if first part of sequence
      // otherwise preserve
      $token = array_shift($partTokens);
      if ($token['expr_type'] == "operator") {
        $uTok = strtoupper($token['base_expr']);
        // if not a and/or operator on first position of sequence out
        // then reassign to part-tokens
        if (!(count($sequenceOut) == 0 && ($uTok == "AND" || $uTok == "OR"))) {
          array_unshift($partTokens, $token);
        }
      }

      // do not use empty parts
// TODO
      if (!trim($part)) {
        // $usePart = false;
      }

      // all tests passed - use part
      $sequenceOut[] = array_merge($sequenceOut, $partTokens);
      $this->paramValues = array_merge($this->paramValues, $tmpParamValues);

    }  // eo loop over all parts

    return $sequenceOut;
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
